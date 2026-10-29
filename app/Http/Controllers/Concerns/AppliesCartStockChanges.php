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
        $result = $kind === 'variant'
            ? $this->stockService()->decrementVariantStock($model, $qty)
            : $this->stockService()->decrementProductStock($model, $qty);

        if ($result['applied']) {
            $applied[] = [
                'kind' => $kind,
                'id' => $model->id,
                // Recorded so a failed compensation can name its tenant. The model is the row
                // that was just decremented, so this is its own store and not a caller's claim.
                'store_id' => $model->store_id ?? null,
                'quantity' => $result['quantity'],
            ];

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
            $forced = $kind === 'variant'
                ? $this->stockService()->decrementVariantStock($model, $qty, true)
                : $this->stockService()->decrementProductStock($model, $qty, true);

            if ($forced['applied']) {
                $applied[] = [
                    'kind' => $kind,
                    'id' => $model->id,
                    'store_id' => $model->store_id ?? null,
                    'quantity' => $forced['quantity'],
                ];
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
                if (($change['kind'] ?? null) === 'variant') {
                    $this->stockService()->restoreVariantStock($change['id'], $change['quantity']);
                } else {
                    $this->stockService()->restoreProductStock($change['id'], $change['quantity']);
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
