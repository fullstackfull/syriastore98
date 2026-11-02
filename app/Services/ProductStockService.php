<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Log;

/**
 * Atomic stock decrement for the order path.
 *
 * WHY THIS EXISTS
 * ---------------
 * Every order writer decremented stock by reading the row, subtracting in PHP
 * and saving it back:
 *
 *     $remain_stock = $Product->product_stock - $qtyy;
 *     $Product->product_stock = $remain_stock;
 *     $Product->save();
 *
 * That is a lost update. Two checkouts that read the same stock both write
 * `stock - qty` and the second overwrites the first, so the shop oversells by
 * exactly the quantity of the orders it processed concurrently. And because
 * `products.product_stock` is a SIGNED integer and the cart quantity is taken
 * raw, the subtraction also goes NEGATIVE — for which no concurrency is even
 * required.
 *
 * The pattern was written out five times across PaymentController and
 * PaymentService. It now lives here once and those five sites call it; this
 * REPLACES the duplication rather than sitting beside it.
 *
 * WHY NO LOCK
 * -----------
 * A single conditional UPDATE is atomic in the database by itself:
 *
 *     UPDATE products SET product_stock = product_stock - ?
 *      WHERE id = ? AND product_stock >= ?
 *
 * The row lock is taken and released by that statement, and "zero rows
 * affected" is the authoritative answer that stock was insufficient. A
 * SELECT ... FOR UPDATE plus a second statement would be strictly more code
 * holding a lock for strictly longer, to no benefit.
 *
 * BACKORDERS ARE PRESERVED
 * ------------------------
 * The availability guard is only applied when the product forbids ordering
 * below stock — `stock_order_status === 'not_allow'`, the same test
 * App\Models\Cart and ApiController already use. A product configured for
 * backorder ('notify_customer', or no value at all) still decrements, and may
 * still go negative, exactly as before: tightening that would silently remove
 * the backorder feature, which is a merchant-facing behaviour and not this
 * fix's business.
 */
class ProductStockService
{
    /** The decrement was applied. */
    public const APPLIED = 'applied';

    /** Stock was insufficient and the product forbids ordering below it. */
    public const INSUFFICIENT = 'insufficient_stock';

    /** There was nothing to decrement (no quantity, or no row). */
    public const SKIPPED = 'skipped';

    /**
     * The decrement was applied even though stock was short, because the caller
     * had passed the point where refusing is possible -- see $allowOversell.
     */
    public const OVERSOLD = 'applied_oversold';

    /**
     * Decrement a product's own stock.
     *
     * @param  \App\Models\Product $product A loaded Product. Its in-memory
     *                                      `product_stock` is refreshed to the
     *                                      post-decrement value so the caller's
     *                                      low-stock and out-of-stock checks
     *                                      read the real figure.
     * @param  mixed $qty
     * @param  bool  $requireAvailability See {@see decrementVariantStock()}; the
     *                                    manual-admin writer needs it.
     * @return array{applied: bool, reason: string, quantity: int, remaining: ?int}
     */
    public function decrementProductStock(
        $product,
        $qty,
        bool $allowOversell = false,
        bool $requireAvailability = false,
        array $evidence = []
    ): array {
        $quantity = $this->normaliseQuantity($qty);

        if ($product === null || empty($product->id) || $quantity === null) {
            return $this->result(false, self::SKIPPED, 0, null);
        }

        $query = Product::query()->whereKey($product->id);

        // $allowOversell is set ONLY by a caller that has already passed the point
        // where refusing is possible -- a post-capture order writer. Dropping the
        // guard there restores the exact pre-existing unguarded decrement rather
        // than abandoning an order whose money is already taken. The atomic
        // conditional UPDATE is still the only write; only its predicate changes.
        if (($this->mustNotOversell($product) || $requireAvailability) && !$allowOversell) {
            $query->where('product_stock', '>=', $quantity);
        }

        $affected = $query->decrement('product_stock', $quantity);

        if ($affected === 0) {
            Log::warning('Stock decrement rejected: insufficient stock', [
                'product_id' => $product->id,
                'requested_quantity' => $quantity,
                'stock_at_read_time' => $product->product_stock,
            ]);

            /* E13: A REFUSAL IS EVIDENCE TOO, and the most important kind. "The order tried and
               failed" is a different fact from "the order took stock", and a replacement built on
               the second while the first is true deducts stock twice. `applied => false`. */
            $this->recordMovement($evidence, [
                'product_id' => $product->id,
                'direction' => \App\Services\StockMovement::OUT,
                'quantity' => $quantity,
                'applied' => false,
                'outcome' => self::INSUFFICIENT,
            ]);

            return $this->result(false, self::INSUFFICIENT, $quantity, null);
        }

        $remaining = $this->refreshStock($product, Product::class, 'product_stock');

        // A negative remainder on a product that forbids overselling means the
        // guard was deliberately bypassed and the shop is now oversold. That is a
        // real operational exception for the merchant to resolve, so it is reported
        // at critical -- but it is NOT an error for the shopper, whose payment
        // succeeded.
        if ($allowOversell && $this->mustNotOversell($product) && $remaining !== null && $remaining < 0) {
            Log::critical('Stock decremented below zero on an order path that cannot refuse', [
                'model' => Product::class,
                'id' => $product->id,
                'requested_quantity' => $quantity,
                'remaining_stock' => $remaining,
            ]);

            $this->recordMovement($evidence, [
                'product_id' => $product->id,
                'direction' => \App\Services\StockMovement::OUT,
                'quantity' => $quantity,
                'applied' => true,
                'outcome' => self::OVERSOLD,
            ]);

            return $this->result(true, self::OVERSOLD, $quantity, $remaining);
        }

        $this->recordMovement($evidence, [
            'product_id' => $product->id,
            'direction' => \App\Services\StockMovement::OUT,
            'quantity' => $quantity,
            'applied' => true,
            'outcome' => self::APPLIED,
        ]);

        return $this->result(true, self::APPLIED, $quantity, $remaining);
    }

    /**
     * Decrement a product variant's stock.
     *
     * @param  \App\Models\ProductVariant $variant A loaded variant. Its
     *                                             in-memory `stock` is refreshed.
     * @param  mixed $qty
     * @param  bool  $requireAvailability Apply the `>=` predicate even when the
     *        row permits a backorder.
     *
     *        WHY THIS EXISTS, and why it is not the default. The two storefront
     *        writers must honour `stock_order_status` because a backorder is a
     *        merchant-facing feature there. OrderController::store() is
     *        different: it is the MANUAL merchant-admin writer, and its original
     *        code refused the line whenever `stock < qty` with no reference to
     *        `stock_order_status` at all (`if ($variant->stock < $qty) throw`).
     *        Making the service the only guard there would therefore have
     *        LOOSENED that writer -- a manual order for a backorder-enabled
     *        product would newly be accepted -- which is a behaviour change, not
     *        a fix. This flag lets that writer keep its own stricter rule while
     *        still getting the atomic conditional UPDATE.
     *
     *        It defaults to false, so every pre-existing call site
     *        (PaymentController:118-119/155-156, PaymentService:126-127/163-164)
     *        is unchanged.
     * @return array{applied: bool, reason: string, quantity: int, remaining: ?int}
     */
    public function decrementVariantStock(
        $variant,
        $qty,
        bool $allowOversell = false,
        bool $requireAvailability = false,
        array $evidence = []
    ): array {
        $quantity = $this->normaliseQuantity($qty);

        if ($variant === null || empty($variant->id) || $quantity === null) {
            return $this->result(false, self::SKIPPED, 0, null);
        }

        $query = ProductVariant::query()->whereKey($variant->id);

        // $allowOversell is set ONLY by a caller that has already passed the point
        // where refusing is possible -- a post-capture order writer. Dropping the
        // guard there restores the exact pre-existing unguarded decrement rather
        // than abandoning an order whose money is already taken. The atomic
        // conditional UPDATE is still the only write; only its predicate changes.
        if (($this->mustNotOversell($variant) || $requireAvailability) && !$allowOversell) {
            $query->where('stock', '>=', $quantity);
        }

        $affected = $query->decrement('stock', $quantity);

        if ($affected === 0) {
            Log::warning('Variant stock decrement rejected: insufficient stock', [
                'variant_id' => $variant->id,
                'product_id' => $variant->product_id ?? null,
                'requested_quantity' => $quantity,
                'stock_at_read_time' => $variant->stock,
            ]);

            /* E13: A REFUSAL IS EVIDENCE TOO, and the most important kind. "The order tried and
               failed" is a different fact from "the order took stock", and a replacement built on
               the second while the first is true deducts stock twice. `applied => false`. */
            $this->recordMovement($evidence, [
                'variant_id' => $variant->id,
                'direction' => \App\Services\StockMovement::OUT,
                'quantity' => $quantity,
                'applied' => false,
                'outcome' => self::INSUFFICIENT,
            ]);

            return $this->result(false, self::INSUFFICIENT, $quantity, null);
        }

        $remaining = $this->refreshStock($variant, ProductVariant::class, 'stock');

        // A negative remainder on a product that forbids overselling means the
        // guard was deliberately bypassed and the shop is now oversold. That is a
        // real operational exception for the merchant to resolve, so it is reported
        // at critical -- but it is NOT an error for the shopper, whose payment
        // succeeded.
        if ($allowOversell && $this->mustNotOversell($variant) && $remaining !== null && $remaining < 0) {
            Log::critical('Stock decremented below zero on an order path that cannot refuse', [
                'model' => ProductVariant::class,
                'id' => $variant->id,
                'requested_quantity' => $quantity,
                'remaining_stock' => $remaining,
            ]);

            $this->recordMovement($evidence, [
                'variant_id' => $variant->id,
                'direction' => \App\Services\StockMovement::OUT,
                'quantity' => $quantity,
                'applied' => true,
                'outcome' => self::OVERSOLD,
            ]);

            return $this->result(true, self::OVERSOLD, $quantity, $remaining);
        }

        $this->recordMovement($evidence, [
            'variant_id' => $variant->id,
            'direction' => \App\Services\StockMovement::OUT,
            'quantity' => $quantity,
            'applied' => true,
            'outcome' => self::APPLIED,
        ]);

        return $this->result(true, self::APPLIED, $quantity, $remaining);
    }

    /**
     * Give stock back.
     *
     * Used to compensate an applied decrement when the order it was taken for
     * could not be written, so a failed checkout does not silently consume
     * inventory. This is a correction of this service's own effect, not a
     * general stock-adjustment API.
     */
    public function restoreProductStock($productId, int $quantity, array $evidence = []): void
    {
        if (empty($productId) || $quantity <= 0) {
            return;
        }

        $affected = Product::query()->whereKey($productId)->increment('product_stock', $quantity);
        $this->flushModelCache(Product::class);

        /* `applied` is the AFFECTED ROW COUNT, not the fact that we called increment(). A restore
           for a product that no longer exists changes nothing, and recording it as applied would
           make `movedOutFor()` believe stock came back when it did not. */
        $this->recordMovement($evidence, [
            'product_id' => $productId,
            'direction' => \App\Services\StockMovement::IN,
            'quantity' => $quantity,
            'applied' => $affected > 0,
            'outcome' => $affected > 0 ? self::APPLIED : self::SKIPPED,
        ]);
    }

    /** Give variant stock back. See {@see restoreProductStock()}. */
    public function restoreVariantStock($variantId, int $quantity, array $evidence = []): void
    {
        if (empty($variantId) || $quantity <= 0) {
            return;
        }

        $affected = ProductVariant::query()->whereKey($variantId)->increment('stock', $quantity);
        $this->flushModelCache(ProductVariant::class);

        /* See restoreProductStock(): `applied` is the affected row count. */
        $this->recordMovement($evidence, [
            'variant_id' => $variantId,
            'direction' => \App\Services\StockMovement::IN,
            'quantity' => $quantity,
            'applied' => $affected > 0,
            'outcome' => $affected > 0 ? self::APPLIED : self::SKIPPED,
        ]);
    }

    /**
     * Whether this row forbids being ordered below its stock.
     *
     * `'not_allow'` is the existing platform-wide marker for "do not sell when
     * out of stock" (App\Models\Cart:50-124, ApiController:245-403). Any other
     * value, including none, permits a backorder.
     */
    private function mustNotOversell($model): bool
    {
        return ($model->stock_order_status ?? null) === 'not_allow';
    }

    /**
     * Coerce a cart quantity to a positive integer, or null when there is
     * nothing to decrement.
     *
     * A zero or negative quantity is NOT passed to `decrement()`: a negative one
     * would INCREASE stock, which is how a tampered quantity could mint
     * inventory.
     */
    private function normaliseQuantity($qty): ?int
    {
        if (!is_numeric($qty)) {
            return null;
        }

        $quantity = (int) $qty;

        return $quantity > 0 ? $quantity : null;
    }

    /**
     * Re-read the post-decrement value into the caller's model instance.
     *
     * `decrement()` is a direct UPDATE, so the in-memory attribute is stale
     * afterwards and the caller's low-stock / out-of-stock thresholds would be
     * evaluated against the pre-order figure. The original is synced too, so a
     * later `save()` on the same instance cannot write the stale value back.
     *
     * The model cache is flushed explicitly because an UPDATE issued through the
     * query builder does not fire the Eloquent events the Cachable trait hooks,
     * whereas the `save()` this replaces did.
     */
    private function refreshStock($model, string $modelClass, string $column): ?int
    {
        $this->flushModelCache($modelClass);

        $fresh = $modelClass::query()->whereKey($model->id)->value($column);

        if ($fresh === null) {
            return null;
        }

        $model->{$column} = $fresh;

        if (method_exists($model, 'syncOriginalAttribute')) {
            $model->syncOriginalAttribute($column);
        }

        return (int) $fresh;
    }

    /**
     * Drop the model cache for a class, tolerating any package difference.
     *
     * A cache flush failing must never fail a checkout, so this is advisory:
     * worst case a cart shows a stale stock figure for one cache lifetime,
     * which is the behaviour that already exists everywhere else.
     */
    /**
     * E13 / D-30 — write one line of stock evidence, if the caller supplied a context.
     *
     * **NO CONTEXT, NO ROW, AND THAT IS DELIBERATE.** `$evidence` carries `store_id`, `order_id` and
     * `reason`, and only a caller that knows which order it is acting for can supply them. A
     * movement recorded without an order is not evidence for E13 — it cannot answer "did THIS order
     * move stock?" — and recording one anyway would put rows in the table that look like answers and
     * are not. So an un-threaded call site records nothing, and `StockMovement::movedOutFor()` then
     * answers UNKNOWN for that order, which is the honest result and the one D-15 requires a caller
     * to refuse on.
     *
     * `StockMovementEvidenceTest::test_the_shared_trait_threads_a_token_at_every_stock_call` is
     * what stops that silence becoming permanent for the checkout path: it pins the six call
     * sites in `AppliesCartStockChanges`. **IT DOES NOT COVER THE RESTORE SITES** --
     * OrderController's return loop, Order::product_return, OrderRefundController and
     * PaymentService thread no context, so their movements are not recorded. That is stated
     * here rather than implied, because the previous version of this paragraph named a
     * `StockMovementCoverageTest` THAT DOES NOT EXIST -- a claim about the filesystem, which
     * CLAUDE.md's own section 6 records as having been wrong in both directions before. Found
     * by independent review.
     */
    private function recordMovement(array $evidence, array $movement): void
    {
        /*
         * A STORE IS REQUIRED. An order id OR a correlation token is required -- the token is how a
         * PLACEMENT path records a movement that happens before `new Order()` exists, which
         * `AppliesCartStockChanges::decrementStockOrFail()`'s own comment confirms is the real
         * ordering. Without either, the row could not be attributed to anything and would look like
         * an answer without being one.
         */
        if (empty($evidence['store_id'])) {
            return;
        }

        if (empty($evidence['order_id']) && empty($evidence['correlation_token'])) {
            return;
        }

        \App\Services\StockMovement::record(array_merge($movement, [
            'store_id' => $evidence['store_id'],
            'order_id' => $evidence['order_id'] ?? null,
            'reason' => $evidence['reason'] ?? null,
            'correlation_token' => $evidence['correlation_token'] ?? null,
        ]));
    }

    private function flushModelCache(string $modelClass): void
    {
        try {
            $instance = new $modelClass();

            if (method_exists($instance, 'flushCache')) {
                $instance->flushCache();
            }
        } catch (\Throwable $e) {
            Log::warning('Could not flush the model cache after a stock change', [
                'model' => $modelClass,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function result(bool $applied, string $reason, int $quantity, ?int $remaining): array
    {
        return [
            'applied' => $applied,
            'reason' => $reason,
            'quantity' => $quantity,
            'remaining' => $remaining,
        ];
    }
}
