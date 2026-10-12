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
     * Decrement a product's own stock.
     *
     * @param  \App\Models\Product $product A loaded Product. Its in-memory
     *                                      `product_stock` is refreshed to the
     *                                      post-decrement value so the caller's
     *                                      low-stock and out-of-stock checks
     *                                      read the real figure.
     * @param  mixed $qty
     * @return array{applied: bool, reason: string, quantity: int, remaining: ?int}
     */
    public function decrementProductStock($product, $qty): array
    {
        $quantity = $this->normaliseQuantity($qty);

        if ($product === null || empty($product->id) || $quantity === null) {
            return $this->result(false, self::SKIPPED, 0, null);
        }

        $query = Product::query()->whereKey($product->id);

        if ($this->mustNotOversell($product)) {
            $query->where('product_stock', '>=', $quantity);
        }

        $affected = $query->decrement('product_stock', $quantity);

        if ($affected === 0) {
            Log::warning('Stock decrement rejected: insufficient stock', [
                'product_id' => $product->id,
                'requested_quantity' => $quantity,
                'stock_at_read_time' => $product->product_stock,
            ]);

            return $this->result(false, self::INSUFFICIENT, $quantity, null);
        }

        $remaining = $this->refreshStock($product, Product::class, 'product_stock');

        return $this->result(true, self::APPLIED, $quantity, $remaining);
    }

    /**
     * Decrement a product variant's stock.
     *
     * @param  \App\Models\ProductVariant $variant A loaded variant. Its
     *                                             in-memory `stock` is refreshed.
     * @param  mixed $qty
     * @return array{applied: bool, reason: string, quantity: int, remaining: ?int}
     */
    public function decrementVariantStock($variant, $qty): array
    {
        $quantity = $this->normaliseQuantity($qty);

        if ($variant === null || empty($variant->id) || $quantity === null) {
            return $this->result(false, self::SKIPPED, 0, null);
        }

        $query = ProductVariant::query()->whereKey($variant->id);

        if ($this->mustNotOversell($variant)) {
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

            return $this->result(false, self::INSUFFICIENT, $quantity, null);
        }

        $remaining = $this->refreshStock($variant, ProductVariant::class, 'stock');

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
    public function restoreProductStock($productId, int $quantity): void
    {
        if (empty($productId) || $quantity <= 0) {
            return;
        }

        Product::query()->whereKey($productId)->increment('product_stock', $quantity);
        $this->flushModelCache(Product::class);
    }

    /** Give variant stock back. See {@see restoreProductStock()}. */
    public function restoreVariantStock($variantId, int $quantity): void
    {
        if (empty($variantId) || $quantity <= 0) {
            return;
        }

        ProductVariant::query()->whereKey($variantId)->increment('stock', $quantity);
        $this->flushModelCache(ProductVariant::class);
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
