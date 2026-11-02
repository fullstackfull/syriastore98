<?php

namespace App\Http\Controllers\Concerns;

/**
 * APPLYING A CART'S STOCK CHANGES, AND GIVING THEM BACK.
 * =============================================================================================
 *
 * WHY THIS IS A CONCERN AND NOT A COPY. These two methods were written for
 * PaymentController's three order writers in P01-SEC-14 and are unchanged here -- the move is
 * mechanical, the bodies are identical, and PaymentController still uses them through this
 * trait. What changes is that a FOURTH order writer can now reach them:
 * NMIPayController::getNMIProductStatus carried three raw read-modify-write decrements
 * (`$remain_stock = $Product->product_stock - $qtyy; ... ->save()`) and no give-back at all.
 *
 * That matters more there than anywhere else, for a reason specific to that handler: it is a
 * CARD CHARGE. By the time its stock loop runs, NMI has captured the money. A line that fails
 * mid-loop -- and one now can, because the product lookup there became store-scoped -- abandons
 * the checkout with the earlier lines already decremented and no order row to show for them.
 *
 * The alternative was a second copy of the same 70 lines. The project's own record of what
 * copies cost is in docs/security/P01-SECURITY-WORK-QUEUE.md S32: five gateway handlers carry
 * three read-modify-write decrements each and ZERO uses of ProductStockService, because nothing
 * shared existed to wire them to. One place is the fix for that, not an extra abstraction.
 *
 * WHAT IT DELIBERATELY DOES NOT DECIDE. Whether a shortfall should refuse the order. That is the
 * caller's `$rejectOnShortfall`, and the comment inside decrementStockOrFail() states why every
 * writer that has already taken money must pass false.
 */
trait AppliesCartStockChanges
{
    /** Atomic stock decrement, replacing the read-modify-write pattern. */
    private ?\App\Services\ProductStockService $stockService = null;

    /**
     * E13 / D-30 — the correlation token for THIS checkout's stock movements.
     *
     * `decrementStockOrFail()` runs BEFORE `new Order()` is reached (this file's own comment says
     * so), so a movement recorded there has no order id to carry. It carries this token instead,
     * and the caller attributes it once the order exists -- see
     * `StockMovement::attributeToOrder()`. Until then the row is honestly UNATTRIBUTED rather than
     * mis-attributed, and if the order write never succeeds it stays that way, which is the correct
     * record of stock that moved with no order owning it.
     *
     * Minted lazily and once per request, so every line of one basket shares a token.
     */
    private function stockEvidenceToken(): string
    {
        if (empty($this->ssStockEvidenceToken)) {
            $this->ssStockEvidenceToken = \App\Services\StockMovement::newToken();
        }

        return $this->ssStockEvidenceToken;
    }

    /** @var string|null Set by {@see stockEvidenceToken()}; one token per request. */
    private $ssStockEvidenceToken = null;

    /**
     * How many APPLIED decrements on this request threaded an evidence context — F237.
     *
     * Counted so the attribution can tell a PARTIAL recording failure from success. Found by
     * independent review: `attributeToOrder()` answers "how many rows moved", and the first
     * version tested that with `> 0`, i.e. "did ANY row move". On a three-line basket whose
     * middle line was never recorded that returns 2, `> 0` is true, and the method reported
     * success while one line of evidence was missing.
     *
     * A row can be missing even though the stock really moved:
     * `ProductStockService::recordMovement()` returns without writing when the model carries no
     * `store_id` (a live open owner decision for NULL-`store_id` variant rows), and
     * `StockMovement::record()` logs a warning and writes nothing if the insert throws.
     *
     * And the consequence is the one D-15 conditions 4 and 5 exist to prevent. `movedOutFor()`'s
     * `$observed` fallback asks whether THE ORDER took anything out, not whether THIS LINE did —
     * so once any line of the order is attributed, an unrecorded line reads **NOT_MOVED** rather
     * than UNKNOWN, and `OrderController`'s cancellation skips its restore. Stock then stays
     * consumed on a cancelled order, with a log line saying the record told it so. F237 did not
     * introduce that fallback; it made it reachable, which is exactly what the change was for.
     */
    private $ssStockEvidenceExpected = 0;

    /**
     * PHASE TWO of the two-phase attribution: point this request's movements at the order — F237.
     *
     * `stockEvidenceToken()`'s docblock above promised that "the caller attributes it once the
     * order exists". **For eleven methods across nine controllers, no caller ever did.** Phase one
     * was wired and phase two was not, so every placement row kept `order_id = NULL` for good;
     * `movedOutFor()` predicates on `order_id` in both its main query and its `$observed` fallback,
     * so it answered `UNKNOWN` for every real order; and because `UNKNOWN` means *fall through and
     * restore*, the E13-C cancellation guard never refused anything on a real code path. This is
     * the missing half, in one place rather than eleven.
     *
     * ## CALL IT IMMEDIATELY AFTER `$order->save()`, INSIDE THE ORDER'S TRANSACTION
     *
     * Both halves of that matter:
     *
     *   - **After the save**, because an order with no id cannot own anything.
     *   - **Inside the transaction**, because the movement rows were written by
     *     `decrementStockOrFail()` *before* the transaction opened and are already committed. So a
     *     rollback cannot un-record the movement — nor should it, the stock really did move — but
     *     it DOES undo the attribution, leaving the row unattributed. That is the correct record of
     *     stock that moved with no order owning it, and it is exactly what `unattributed()` is for.
     *
     * ## IT ONLY ACTS WHEN A TOKEN WAS REALLY MINTED
     *
     * Reading `$this->ssStockEvidenceToken` directly rather than calling `stockEvidenceToken()` is
     * deliberate: the accessor MINTS on first use, so calling it here would manufacture a token on
     * a request that moved no stock and then attribute nothing under a name that means nothing.
     * The null check is the whole difference between a no-op and a lie.
     *
     * ## A FAILURE IS LOGGED, NEVER SWALLOWED — AND NEVER FAILS THE CHECKOUT
     *
     * Two rules that pull against each other, and both are kept.
     *
     * It must not throw. It runs after the order is persisted, on paths that have already taken
     * money; an exception here would roll the order back and lose a paid order, which would be a
     * change to approved payment behaviour introduced by a bookkeeping call. So nothing propagates.
     *
     * THE LEVEL IS `warning`, NOT `error`, AND THE INVENTORY SETTLES WHICH.
     * `docs/security/P01-OBSERVABILITY-INVENTORY.md:32-35` states the taxonomy this repository
     * uses: `critical` and `warning` are the levels for "a control fired" or "something happened
     * that a human should look at", and `error` is "almost entirely pre-existing
     * third-party-integration failure reporting … none of them is a security signal". Missing
     * stock evidence for a real order is the former, not the latter. It also matters practically:
     * only `critical` and `warning` are enumerated in that inventory, so an `error` line would
     * have had no stated destination -- which is the very thing the inventory exists to prevent.
     * It is also the level `StockMovement`'s own recording failures already use.
     *
     * But a zero row count must not be read as success either. `attributeToOrder()` returns a
     * count, and zero is ambiguous four ways — already attributed, nothing recorded, rows that
     * exist but did not move, or an update that threw. So when it moves nothing, the record is
     * asked what state it is actually in (`attributionStateFor()`), and only `ATTRIBUTED` — the
     * retried-return case — is treated as done. Everything else is logged at **error** with the
     * token and the order, and the row is left unattributed. That keeps `movedOutFor()` answering
     * `UNKNOWN`, which falls through and restores: the safe direction, and the pre-existing
     * behaviour.
     *
     * @param \App\Models\Order|mixed $order The order as just persisted; must carry id and store_id.
     */
    private function attributeStockEvidenceTo($order): void
    {
        if (empty($this->ssStockEvidenceToken)) {
            return;
        }

        /*
         * NO RECORD INSTALLED IS NOT A FAILURE TO ATTRIBUTE, AND THIS GUARD IS THE WHOLE
         * DIFFERENCE BETWEEN HONOURING THAT RULE AND BREAKING IT.
         *
         * Found by independent review, and it was a live production defect in the first version of
         * this method. The migration is development-and-test only (D-15 condition 9), so on every
         * live installation `available()` is false. But `stockEvidence()` mints the token
         * unconditionally -- it does not consult `available()` -- so the early return above does
         * NOT fire in production: a token exists, the attribution finds no table,
         * `attributionStateFor()` answers UNKNOWN, and the first version then logged UNKNOWN at
         * ERROR. That is one error-level line per order, on all eleven paths, on the money path,
         * forever, for a condition that is intended.
         *
         * It also contradicted the governing rule of the service it calls, stated at
         * `StockMovement.php:137-139`: **"UNKNOWN is not a failure mode, it is the whole point."**
         * `OrderController`'s cancellation consult reasons the same way at `:1872-1877` and accepts
         * being "one log line heavier" at INFO on a path that runs once per cancellation -- not at
         * ERROR once per order.
         *
         * No test could have caught it: `RefreshDatabase` runs the migration, so the table always
         * exists in the suite and `available()` is never false where the tests look.
         */
        if (! \App\Services\StockMovement::available()) {
            return;
        }

        $token = $this->ssStockEvidenceToken;

        try {
            $orderId = is_object($order) ? ($order->id ?? null) : null;
            $storeId = is_object($order) ? ($order->store_id ?? null) : null;

            if (! is_numeric($orderId) || ! is_numeric($storeId)) {
                \Illuminate\Support\Facades\Log::warning('stock evidence not attributed: the order carries no usable id or store', [
                        'token' => $token,
                        'order_id' => is_scalar($orderId) ? $orderId : null,
                        'store_id' => is_scalar($storeId) ? $storeId : null,
                        'controller' => static::class,
                    ]
                );

                return;
            }

            $moved = \App\Services\StockMovement::attributeToOrder($token, $storeId, $orderId);

            if ($moved > 0) {
                /*
                 * PARTIAL IS NOT SUCCESS, and `> 0` alone said it was.
                 *
                 * Found by independent review. See `$ssStockEvidenceExpected` for why a row can
                 * be missing while the stock really moved, and for why the consequence is a
                 * D-15 conditions 4/5 violation rather than a cosmetic gap: an unrecorded line
                 * reads NOT_MOVED once any sibling line is attributed, and the cancellation then
                 * skips its restore.
                 *
                 * Reported, not repaired: the missing row cannot be reconstructed here, and
                 * inventing one would be manufacturing evidence — precisely what D-15 forbids.
                 * What this does is make the gap loud, so the order can be reviewed, instead of
                 * leaving a silent half-record that reads as proof.
                 */
                if ($this->ssStockEvidenceExpected > $moved) {
                    \Illuminate\Support\Facades\Log::warning('stock evidence incomplete: fewer movements recorded than stock decrements applied', [
                            'applied_decrements' => $this->ssStockEvidenceExpected,
                            'attributed_rows' => $moved,
                            'token' => $token,
                            'order_id' => $orderId,
                            'store_id' => $storeId,
                            'controller' => static::class,
                        ]
                    );
                }

                return;
            }

            $state = \App\Services\StockMovement::attributionStateFor($token, $storeId, $orderId);

            if ($state === \App\Services\StockMovement::ATTRIBUTED) {
                /* A retried gateway return. The rows already belong to this order, which is the
                   idempotency `attributeToOrder()`'s `whereNull('order_id')` is there to give. */
                return;
            }

            \Illuminate\Support\Facades\Log::warning('stock evidence not attributed: stock moved for this request and no row now owns the order', [
                    'state' => $state,
                    'token' => $token,
                    'order_id' => $orderId,
                    'store_id' => $storeId,
                    'controller' => static::class,
                ]
            );
        } catch (\Throwable $theAttributionFailed) {
            /* Never fail a persisted order over bookkeeping — but never go quiet either. */
            \Illuminate\Support\Facades\Log::warning('stock evidence not attributed: the attempt threw', [
                'reason' => $theAttributionFailed->getMessage(),
                'token' => $token,
                'controller' => static::class,
            ]);
        }
    }

    /** The evidence context for a stock call on the placement path. */
    private function stockEvidence($model, string $reason): array
    {
        return [
            'store_id' => $model->store_id ?? null,
            'correlation_token' => $this->stockEvidenceToken(),
            'reason' => $reason,
        ];
    }

    private function stockService(): \App\Services\ProductStockService
    {
        return $this->stockService ??= new \App\Services\ProductStockService();
    }

    /**
     * Decrement stock atomically, remembering it so it can be given back.
     *
     * One copy for all three order paths in this controller
     * (getProductStatus, getWhatsappUrl, whatsapp), which each carried an
     * identical read-modify-write decrement.
     *
     * @param  array  $applied  Accumulator of applied decrements, by reference.
     * @param  string $kind     'variant' or 'product'.
     * @param  mixed  $model    The loaded ProductVariant or Product.
     * @param  mixed  $qty      The cart quantity.
     * @return array|null  null on success; a `['message' => ...]` payload when
     *                     the caller must abandon the order. Any decrements
     *                     already applied in this cart are given back first.
     */
    private function decrementStockOrFail(
        array &$applied,
        string $kind,
        $model,
        $qty,
        bool $rejectOnShortfall = true
    ): ?array {
        $evidence = $this->stockEvidence($model, \App\Services\StockMovement::REASON_ORDER_PLACE);

        $result = $kind === 'variant'
            ? $this->stockService()->decrementVariantStock($model, $qty, false, false, $evidence)
            : $this->stockService()->decrementProductStock($model, $qty, false, false, $evidence);

        if ($result['applied']) {
            $applied[] = [
                'kind' => $kind,
                'id' => $model->id,
                // Recorded so a failed compensation can name its tenant. The model is the row
                // that was just decremented, so this is its own store and not a caller's claim.
                'store_id' => $model->store_id ?? null,
                'quantity' => $result['quantity'],
            ];

            $this->ssStockEvidenceExpected++;

            return null;
        }

        if ($result['reason'] !== \App\Services\ProductStockService::INSUFFICIENT) {
            // Nothing to decrement (no quantity, or no row). Previously this
            // subtracted zero and saved, so carrying on matches the old
            // behaviour exactly.
            return null;
        }

        // PAST THE POINT OF NO RETURN.
        //
        // $rejectOnShortfall is false in every writer that has already done
        // irreversible work by the time this line runs. In those methods the cart
        // rows of the lines processed BEFORE this one have already been deleted,
        // `new Order()` has not been reached yet, and no transaction wraps any of
        // it -- and on the payment-return paths the gateway has already captured
        // the money. Returning an error there abandoned the checkout with no order
        // row, a partly destroyed cart and nothing refunded.
        //
        // The pre-existing code had no availability test at all on these paths: it
        // simply subtracted and saved, so stock could go negative. Overselling and
        // reporting it is therefore the SAME outcome the platform always had, and
        // strictly better than losing the order. The shortfall is a merchant
        // operations problem, not a reason to discard a paid basket.
        if (!$rejectOnShortfall) {
            $forcedEvidence = $this->stockEvidence($model, \App\Services\StockMovement::REASON_ORDER_PLACE);

            $forced = $kind === 'variant'
                ? $this->stockService()->decrementVariantStock($model, $qty, true, false, $forcedEvidence)
                : $this->stockService()->decrementProductStock($model, $qty, true, false, $forcedEvidence);

            if ($forced['applied']) {
                $applied[] = [
                    'kind' => $kind,
                    'id' => $model->id,
                    'store_id' => $model->store_id ?? null,
                    'quantity' => $forced['quantity'],
                ];

                $this->ssStockEvidenceExpected++;
            }

            \Illuminate\Support\Facades\Log::critical('Insufficient stock on an order path that cannot refuse; order kept, stock oversold', [
                'kind' => $kind,
                'model_id' => $model->id ?? null,
                'requested_quantity' => $qty,
                'outcome' => $forced['reason'] ?? null,
            ]);

            return null;
        }

        $this->restoreStockDecrements($applied);

        /*
         * CLEARED, BECAUSE THE ACCUMULATOR IS BY REFERENCE AND THESE LINES HAVE NOW BEEN GIVEN
         * BACK. Without this they stay recorded, so a caller that gives the basket back here and
         * then fails again later restores the SAME entries a second time -- which ADDS stock that
         * was never taken, the opposite error and a harder one to notice.
         *
         * IT IS NOT REACHABLE TODAY and the line is hardening, not a fix: every one of the 39
         * `decrementStockOrFail()` call sites in the repository passes `$rejectOnShortfall = false`
         * explicitly, so this branch is dead. It became worth closing when F192 added a second
         * give-back to each order writer's pre-transaction catch: a future decrement added inside
         * one of those guarded regions that simply OMITS the fifth argument would take the default
         * `true`, land here, and arm exactly that double restore. Found by the review of F192,
         * which is the change that made it worth a line.
         */
        $applied = [];

        return ['message' => __('Insufficient stock. Please review your cart and try again.')];
    }

    /** Give back every decrement recorded by {@see decrementStockOrFail()}. */
    private function restoreStockDecrements(array $applied): void
    {
        foreach ($applied as $change) {
            /*
             * D-13, 2026-10-29. EACH RESTORE IS INDEPENDENT, and that became load-bearing when the
             * four gateway return handlers began calling this from a `catch`.
             *
             * From a catch, a throw in here REPLACES the original exception: the handler's real
             * failure -- the one the merchant needs in the log -- is lost and replaced by whatever
             * went wrong giving one line's stock back. And a basket's later lines would keep their
             * decrement because the loop died on an earlier one.
             *
             * So a failed restore is logged and the loop continues. It is the correct direction
             * for a compensation: the alternatives are losing the original error, or abandoning
             * the rest of the inventory to protect a log line.
             */
            try {
                /* The compensation is evidence too: a line taken and given back must read as
                   NOT_MOVED on balance, or a later replacement would restore it a second time.
                   Same token, so it joins this checkout's other movements. */
                $restoreEvidence = [
                    'store_id' => $change['store_id'] ?? null,
                    'correlation_token' => $this->stockEvidenceToken(),
                    'reason' => \App\Services\StockMovement::REASON_ORDER_CANCEL,
                ];

                if (($change['kind'] ?? null) === 'variant') {
                    $this->stockService()->restoreVariantStock($change['id'], $change['quantity'], $restoreEvidence);
                } else {
                    $this->stockService()->restoreProductStock($change['id'], $change['quantity'], $restoreEvidence);
                }
            } catch (\Throwable $restoreFailure) {
                /*
                 * THE MESSAGE IS ON THE SAME LINE AS THE CALL, deliberately.
                 * `SecurityLogInventoryTest`'s census matches `Log::critical(` and its first
                 * string literal on ONE line, so a call split across two lines is invisible to the
                 * inventory -- the line would exist with no stated destination and no reader, and
                 * the ratchet would pass. Found by writing it the other way first and watching
                 * `source lines : 137` stay at 137 after adding a line.
                 */
                /* The STORE too, which the review asked for: without it this line names a product
                   id and a quantity and leaves whoever reconciles it to find the tenant. */
                \Illuminate\Support\Facades\Log::critical('Stock compensation failed: inventory was consumed and could not be returned.', [
                    'kind' => $change['kind'] ?? null,
                    'id' => $change['id'] ?? null,
                    'store_id' => $change['store_id'] ?? null,
                    'quantity' => $change['quantity'] ?? null,
                    'message' => $restoreFailure->getMessage(),
                ]);
            }
        }
    }
}
