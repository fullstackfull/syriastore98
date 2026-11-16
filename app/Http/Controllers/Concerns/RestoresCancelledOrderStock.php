<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ONE RESTORE LOOP, SHARED BY EVERY DOOR THAT CANCELS AN ORDER.  P4-F034.
 * ======================================================================
 *
 * MEASURED, before this trait existed: a merchant cancelling a pending, undispatched order through
 * the order screen (`POST order-status-change/{id}`) left a 3-unit line's stock at 4 instead of 7.
 * The same through the shipped Flutter admin app (`POST api/admin/orders/update-status`,
 * `delivered_status: 2`): 4. Through the Delivery Syria courier webhook: 4. Variant lines, multi-line
 * orders, the same. The ONLY door that credited anything was the storefront customer's
 * (`OrderController::status_cancel()`), which carried this loop inline -- so whether a merchant's
 * inventory came back depended on which of six buttons cancelled the order, and nothing on the
 * `orders` row distinguished them: `cancel_date` and `delivered_status = 2` are identical across all
 * of them.
 *
 * The census that produced "eight doors" is corrected here, because the number matters to whoever
 * maintains this: there are SEVEN handlers writing `delivered_status = 2` and EIGHT routes (one is
 * registered twice). SIX of the handlers are defects; the seventh,
 * `App\Services\OrderReplacement::cancelAndReplace()`, deliberately does NOT restore -- a cancel
 * plus a reissue must net -N -- and is pinned by
 * `tests/Feature/Commerce/OrderReplacementTest::test_the_pair_moves_the_stock_once_and_the_lines_say_why`.
 * It calls `Order::order_status_change()` directly, which is exactly why this loop must stay in the
 * CALLERS and must never be moved into that method: a restore at that "single choke point" would
 * drag the replacement path in with it.
 *
 * WHAT MOVING THE LOOP REQUIRED, and it is the part worth reading. With one caller the loop needed no
 * idempotency of its own: a replayed storefront cancel is refused by `Order::order_status_change()`
 * before the loop is reached. With six callers that is no longer true -- the Flutter door re-enters
 * its own transaction for a second POST carrying a `note`, and the courier webhook has no transition
 * guard at all -- and `movedOutFor()` cannot substitute, because `stock_movements` is
 * development-and-test-only and every line on a live installation therefore reads UNKNOWN and
 * restores unconditionally. So the loop now consults the two line markers itself, and every caller
 * ALSO guards on the order's pre-write state. Both, deliberately: the marker bounds the goods and the
 * caller's guard bounds the transition.
 */
trait RestoresCancelledOrderStock
{
    private ?\App\Services\ProductStockService $cancelStockServiceInstance = null;

    private function cancelStockService(): \App\Services\ProductStockService
    {
        return $this->cancelStockServiceInstance ??= new \App\Services\ProductStockService();
    }

    /**
     * Credit back every line of a cancelled order whose goods are not already accounted for.
     *
     * @param  array<int|string, mixed>  $lines  the order's lines; `Order::order_detail()`'s derived
     *                                           copy or a `json_decode($order->product_json)` -- the
     *                                           marker check reads the stored lines either way
     */
    protected function restoreCancelledOrderStock(Order $order, array $lines): void
    {
        if ($lines === []) {
            return;
        }

        $alreadyAccounted = $this->linesAlreadyAccountedFor($order);

        DB::transaction(function () use ($lines, $order, $alreadyAccounted) {
            /*
             * THE SAME "ALREADY BACK IN" MARKER THE RETURN PATH WRITES.
             *
             * `Order::product_return()` stamps `Order::LINE_STOCK_RESTORED` on every line it
             * credits (app/Models/Order.php:1063, :1094) and the merchant return loop reads it
             * before crediting (:1353-1360). This loop did neither, so a cancellation credited
             * the goods and left no mark — and a merchant who then approved a return on the
             * same order credited them a SECOND time. `movedOutFor()` does not catch that one:
             * the return path threads no evidence, so its restore is invisible to the record,
             * and on production the record does not exist at all. The marker is the control
             * that already spans both doors. Raised by independent review.
             *
             * Collected as (product_id, variant_id) pairs and stamped after the loop, because
             * `$order_detail['product']` is a derived copy rather than the stored lines.
             */
            $restoredLines = [];

            foreach ($lines as $item) {
                $productId = $item['product_id'] ?? null;
                $qty = $item['qty'] ?? null;

                if ($productId === null || !is_numeric($qty) || (int) $qty <= 0) {
                    Log::warning('Order cancellation skipped a line with an unusable product id or quantity', [
                        'order_id' => $order->id,
                        'product_id' => $productId,
                    ]);

                    continue;
                }

                $qty = (int) $qty;

                /*
                 * THE TWO MARKERS, CONSULTED BEFORE CREDITING ANYTHING. P4-F034.
                 *
                 * This loop never read them while it had exactly one caller: a replay of the
                 * storefront door is refused structurally, because `Order::order_status_change()`
                 * answers `error` for a cancel from any non-pending state, so the loop could not
                 * run twice on one order. Shared across SIX doors that is no longer true, and
                 * `movedOutFor()` cannot stand in for it: `stock_movements` is
                 * development-and-test-only, so on every live installation every line reads
                 * UNKNOWN and this loop restores unconditionally. The guard has to be the marker.
                 *
                 *   LINE_STOCK_RESTORED  these goods are already back in -- stamped by this loop
                 *                        and by the return path (app/Models/Order.php:1063, :1094).
                 *   LINE_STOCK_CARRIED   these goods never left and never will: the line belongs
                 *                        to a REPLACEMENT order, written by
                 *                        `OrderReplacement::cancelAndReplace()` so that the
                 *                        cancel-and-reissue pair nets -N. Crediting it invents
                 *                        stock. Until this line existed that marker had ZERO
                 *                        production readers -- its only reference in the whole
                 *                        repository was an assertion in OrderReplacementTest --
                 *                        so cancelling a replacement order through any restoring
                 *                        door credited goods that had never been on the shelf.
                 *                        Found by independent review of the P4-4 tests.
                 *
                 * Keyed on (product_id, variant_id) against the STORED lines rather than read
                 * off `$item`, for the same reason `markCancelledLinesRestored()` is:
                 * `Order::order_detail()` hands callers a DERIVED copy whose keys and extra
                 * fields are not promised to match `product_json`.
                 */
                $variantKey = empty($item['variant_id']) || $item['variant_id'] == 0
                    ? ''
                    : (string) (int) $item['variant_id'];

                if (isset($alreadyAccounted[(int) $productId . ':' . $variantKey])) {
                    Log::info('Order cancellation skipped a line whose goods are already accounted for', [
                        'order_id' => $order->id,
                        'store_id' => $order->store_id,
                        'product_id' => $productId,
                        'variant_id' => $variantKey === '' ? null : (int) $variantKey,
                    ]);

                    continue;
                }

                $product = Product::where('id', $productId)
                    ->where('store_id', $order->store_id)
                    ->first();

                if (! $product) {
                    Log::warning("Order cancellation skipped a line whose product is not in the order's store", [
                        'order_id' => $order->id,
                        'product_id' => $productId,
                        'store_id' => $order->store_id,
                    ]);

                    continue;
                }

                /*
                 * E13 SLICE 3 — ASK THE RECORD, DO NOT INFER FROM THE STATUS.
                 *
                 * D-15, restated by D-30: *"Do not infer stock movement from order status,
                 * payment status, a guessed flag or the existence of an order."* Until this
                 * line existed, the loop below inferred precisely that — it credited every
                 * line of every successfully cancelled order, on the assumption that placing
                 * the order had taken that stock out.
                 *
                 * THE ASSUMPTION IS WRONG WHENEVER THE ORIGINAL NEVER REACHED A DECREMENT: a
                 * refused shortfall, an untracked product, a transaction that rolled back
                 * after the order row was written. In those cases the cancellation does not
                 * restore inventory, it INVENTS it — and the arithmetic looks perfect while
                 * doing so, which is why nothing caught it.
                 *
                 * `movedOutFor()` answers in three states and each gets its own behaviour:
                 *
                 *   MOVED      give it back, and record the restore (below) so the same line
                 *              reads NOT_MOVED afterwards — that is what makes a second
                 *              restore impossible rather than merely unlikely.
                 *   NOT_MOVED  SKIP. The record saw this order and this line never took
                 *              anything out.
                 *   UNKNOWN    give it back, and say so in the log.
                 *
                 * UNKNOWN RESTORES ON PURPOSE. `stock_movements` is development-and-test-only
                 * (its migration says so), so on every live installation `available()` is
                 * false, every line is UNKNOWN, and this path behaves exactly as it did before
                 * — one log line heavier. The protection switches on when, and only when, the
                 * record exists. Refusing on UNKNOWN would stop restoring stock for every real
                 * order on the platform, which is a far larger defect than the one being
                 * fixed.
                 */
                /* `empty()` as well as `== 0`, so an EMPTY-STRING variant_id is a product
                   line rather than a lookup for variant 0 that silently skips the line. The
                   sibling return loop at :1404 already reads it this way and the two must not
                   disagree about the same stored value; raised by independent review. */
                $variantId = empty($item['variant_id']) || $item['variant_id'] == 0
                    ? null
                    : (int) $item['variant_id'];

                $movement = \App\Services\StockMovement::movedOutFor(
                    $order->store_id,
                    $order->id,
                    $product->id,
                    $variantId
                );

                if ($movement === \App\Services\StockMovement::NOT_MOVED) {
                    Log::warning('Order cancellation skipped a line the stock record says never took stock out', [
                        'order_id' => $order->id,
                        'store_id' => $order->store_id,
                        'product_id' => $product->id,
                        'variant_id' => $variantId,
                        'qty' => $qty,
                    ]);

                    continue;
                }

                if ($movement === \App\Services\StockMovement::UNKNOWN) {
                    Log::info('Order cancellation restored a line with no stock evidence', [
                        'order_id' => $order->id,
                        'store_id' => $order->store_id,
                        'product_id' => $product->id,
                        'variant_id' => $variantId,
                        'qty' => $qty,
                    ]);
                }

                /* The restore is RECORDED, not just performed: `movedOutFor()` nets applied
                   ins against applied outs, so feeding it here is what turns the consult above
                   into a restore-twice guard. */
                $evidence = [
                    'store_id' => $order->store_id,
                    'order_id' => $order->id,
                    'reason' => \App\Services\StockMovement::REASON_ORDER_CANCEL,
                ];

                if ($variantId === null) {
                    $this->cancelStockService()->restoreProductStock($product->id, $qty, $evidence);
                    $restoredLines[] = [(int) $product->id, null];

                    continue;
                }

                // Both predicates, so a variant_id belonging to another
                // product cannot be credited against this line.
                $variant = ProductVariant::where('id', $variantId)
                    ->where('product_id', $product->id)
                    ->first();

                if (! $variant) {
                    Log::warning('Order cancellation skipped a line whose variant does not belong to its product', [
                        'order_id' => $order->id,
                        'product_id' => $product->id,
                        'variant_id' => $variantId,
                    ]);

                    continue;
                }

                $this->cancelStockService()->restoreVariantStock($variant->id, $qty, $evidence);
                $restoredLines[] = [(int) $product->id, (int) $variant->id];
            }

            /* Stamped inside the same transaction as the credits it describes: a marker that
               survived a rolled-back restore would stop a legitimate return credit. */
            if ($restoredLines !== []) {
                $this->markCancelledLinesRestored($order, $restoredLines);
            }
        });
    }

    /**
     * The (product_id, variant_id) keys of the stored lines whose goods are already accounted for --
     * either credited back already, or carried from a replaced original and never taken out at all.
     *
     * @return array<string, true>
     */
    private function linesAlreadyAccountedFor(Order $order): array
    {
        $stored = json_decode((string) $order->product_json, true);

        if (! is_array($stored)) {
            return [];
        }

        $keys = [];

        foreach ($stored as $line) {
            if (! is_array($line)) {
                continue;
            }

            if (! Order::lineStockAlreadyRestored($line) && ! Order::lineStockCarriedFromOriginal($line)) {
                continue;
            }

            $productId = isset($line['product_id']) && is_numeric($line['product_id'])
                ? (int) $line['product_id'] : null;

            if ($productId === null) {
                continue;
            }

            $variantKey = empty($line['variant_id']) || $line['variant_id'] == 0
                ? ''
                : (string) (int) $line['variant_id'];

            $keys[$productId . ':' . $variantKey] = true;
        }

        return $keys;
    }
    /**
     * Stamp `Order::LINE_STOCK_RESTORED` on the stored lines a cancellation really did credit.
     *
     * Keyed on (product_id, variant_id) rather than on the array index, because
     * `Order::order_detail()` returns a DERIVED copy of the lines and its keys are not promised to
     * match the stored `product_json`. A line that was skipped — the record says it never took
     * stock out, or its product or variant did not resolve — is deliberately NOT stamped: the
     * marker means "these goods are already back in", and claiming it for goods that never came
     * back would block a legitimate return credit later.
     *
     * @param list<array{0:int,1:int|null}> $restoredLines
     */
    protected function markCancelledLinesRestored(Order $order, array $restoredLines): void
    {
        $lines = json_decode((string) $order->product_json, true);

        if (! is_array($lines) || $lines === []) {
            return;
        }

        $wanted = [];

        foreach ($restoredLines as [$productId, $variantId]) {
            $wanted[$productId . ':' . ($variantId ?? '')] = true;
        }

        $changed = false;

        foreach ($lines as $key => $line) {
            if (! is_array($line)) {
                continue;
            }

            $productId = isset($line['product_id']) && is_numeric($line['product_id'])
                ? (int) $line['product_id'] : null;

            if ($productId === null) {
                continue;
            }

            $variantId = empty($line['variant_id']) || $line['variant_id'] == 0
                ? null
                : (int) $line['variant_id'];

            if (! isset($wanted[$productId . ':' . ($variantId ?? '')])) {
                continue;
            }

            if (Order::lineStockAlreadyRestored($line)) {
                continue;
            }

            $lines[$key][Order::LINE_STOCK_RESTORED] = 1;
            $changed = true;
        }

        if (! $changed) {
            return;
        }

        /* Written through Eloquent so the model cache is flushed: `Order` is `Cachable`, and a raw
           update would leave route-model binding serving the pre-write row. */
        $order->product_json = json_encode($lines);
        $order->save();
    }
}
