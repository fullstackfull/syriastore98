<?php

namespace App\Services\Commerce;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tax;
use App\Models\TaxOption;

/**
 * `PRICE-006` — PREVIEW THE FINAL PRICE AND EXPLAIN HOW IT WAS CALCULATED.
 * =================================================================================================
 *
 * THE RECOVERED REQUIREMENT, verbatim from `DECISION-ID-REGISTER.md:103`:
 *
 *   `PRICE-006`  معاينة السعر النهائي وشرح طريقة حسابه.
 *                — *Preview the final price and explain how it was calculated*
 *
 * `DECISION-ID-REGISTER.md:532` lists it among the recovered requirements the 350-row traceability
 * matrix never carried.
 *
 * -------------------------------------------------------------------------------------------------
 * THE ONE PROPERTY THAT MATTERS: AN EXPLANATION THAT DISAGREES WITH THE CHARGE IS WORSE THAN NONE
 * -------------------------------------------------------------------------------------------------
 * This class does not compute a price. It asks the shipped pricing engine for the price, derives a
 * component breakdown from the same rows that engine reads, and then **checks that the components
 * sum to the engine's own answer**. When they do not, it reports `reconciled => false` and a named
 * `unexplained` residual instead of presenting a breakdown that would be a lie.
 *
 * That check is the whole design, and it is why the duplication below is safe. The deltas are
 * derived from the same three primitives the engine uses — the `products` row, the store's active
 * `FlashSale` rows, and `Tax::tax_methods()` — so if the engine's expression ever changes (and
 * `J17`/`J18` are two open owner decisions that WILL change it) this class does not quietly drift
 * into charging one number and explaining another. It stops explaining. `PriceExplanationTest`
 * asserts exactly that by mutating the engine and watching `reconciled` go false.
 *
 * -------------------------------------------------------------------------------------------------
 * WHICH ENGINE. THERE ARE TWO, AND THAT IS A MEASURED FINDING, NOT A DESIGN CHOICE
 * -------------------------------------------------------------------------------------------------
 * `Price002WholesaleTierLayerMeasurementTest` measures that with `WholesaleProduct` active the cart
 * readers do not call `Product::ProductPrice()` at all — `WholesaleProduct::Wholesale_Product_price()`
 * answers instead, from a different base, and the two differ by up to the full price of the
 * product on identical data. So "explain the final price" has to begin by saying which engine is
 * going to charge it. `engineFor()` resolves that the same way the cart readers do, and when the
 * price-list engine governs, this class explains THAT engine and reconciles against ITS number.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT THIS CLASS DELIBERATELY DOES NOT DO
 * -------------------------------------------------------------------------------------------------
 *   - It changes no amount, writes nothing, and has no side effects.
 *   - It does not rank discounts. `PRICE-004` ("explicit discount-priority rules") does not exist
 *     in this codebase and the rule is owner decision `J18`. This class reports the order the code
 *     actually applies, as an observation, and says in `notes` that no rule governs it.
 *   - It does not apply coupons. A coupon is allocated per BASKET, not per product
 *     (`CouponBasketAllocator`), so it is not a property of a product price preview. The
 *     `coupon_not_included` note says so rather than leaving the omission to be discovered.
 *   - It does not reach the shipper, the second tax pass at checkout (`GAP-143`) or the
 *     order-level totals. Its subject is one product line.
 */
final class PriceExplanation
{
    /** `Product::ProductPrice()` — the host's pricing engine. */
    public const ENGINE_PRODUCT_PRICE = 'product_price';

    /** `WholesaleProduct::Wholesale_Product_price()` — `PRICE-002`'s price-list engine. */
    public const ENGINE_WHOLESALE = 'wholesale_price_list';

    /**
     * Which engine will charge this line, resolved the way the cart readers resolve it.
     *
     * `ApiController::cart_list()` and `Cart::cart_data()` both branch on
     * `module_is_active('WholesaleProduct')` first, and only the `SizeGuideline` arm — which has no
     * module in this repository — falls back to `Product::ProductPrice()`. `class_exists` is
     * checked as well because the `add_on_managers` row controls reachability while `loadAddons()`
     * controls whether the class is loaded, and a row without a load is a module
     * `module_is_active()` admits and whose class is missing.
     */
    public static function engineFor(): string
    {
        $wholesale = \Workdo\WholesaleProduct\app\Models\WholesaleProduct::class;

        if (module_is_active('WholesaleProduct') && class_exists($wholesale)) {
            return self::ENGINE_WHOLESALE;
        }

        return self::ENGINE_PRODUCT_PRICE;
    }

    /**
     * The breakdown for one product line.
     *
     * @param  string  $slug  the store slug, as the engine's own signature takes it
     * @param  int  $qty  the line quantity — it selects the price-list tier, so it is an INPUT
     *                    to the price and not merely a multiplier
     * @return array{
     *     engine: string,
     *     product_id: int,
     *     variant_id: int,
     *     qty: int,
     *     unit_charged: float,
     *     line_charged: float,
     *     components: list<array{code: string, label: string, amount: float, detail: string}>,
     *     components_total: float,
     *     reconciled: bool,
     *     residual: float,
     *     notes: list<string>
     * }
     */
    public static function forProduct(string $slug, int $productId, int $variantId = 0, int $qty = 1): array
    {
        $qty = max(1, $qty);
        $engine = self::engineFor();

        $product = Product::find($productId);

        if ($product === null) {
            return self::unexplainable($engine, $productId, $variantId, $qty, 'product_not_found');
        }

        return $engine === self::ENGINE_WHOLESALE
            ? self::explainWholesale($slug, $product, $variantId, $qty)
            : self::explainProductPrice($slug, $product, $variantId, $qty);
    }

    /*
    |--------------------------------------------------------------------------
    | engine 1 — Product::ProductPrice()
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    private static function explainProductPrice(string $slug, Product $product, int $variantId, int $qty): array
    {
        // THE CHARGE. Asked of the engine, never computed here. Everything below is an attempt to
        // ACCOUNT for this number, checked against it at the end.
        $unitCharged = round((float) Product::ProductPrice($slug, $product->id, $variantId), 2);

        $components = [];
        $notes = [];

        // ---- layer 1: the base. `sale_price` OVERRIDES `price` when non-empty; it is not a
        // comparison, so a sale_price ABOVE list is honoured (measured, GAP-311).
        $list = round((float) ($variantId > 0
            ? (self::variantPrice($product, $variantId) ?? $product->price)
            : $product->price), 2);

        $salePrice = round((float) $product->sale_price, 2);
        $usesSalePrice = $variantId === 0 && ! empty($product->sale_price);

        $components[] = self::component('base_list_price', __('List price'), $list,
            $variantId > 0 ? __('The variant\'s own price.') : __('The product\'s `price` column.'));

        if ($usesSalePrice) {
            $components[] = self::component('sale_price_override', __('Sale price'), $salePrice - $list, sprintf(
                /* translators: %s is the sale price */
                __('The `sale_price` column (%s) REPLACES the list price whenever it is non-empty. It is an override, not a comparison: a sale price above the list price is charged as-is.'),
                number_format($salePrice, 2)
            ));
        }

        $base = $usesSalePrice ? $salePrice : $list;

        if ($variantId === 0 && (float) $product->sale_price === 0.0) {
            $notes[] = 'blank_sale_price_is_zero';
        }

        // ---- layer 2: the flash sale, on `$product->sale_price` — NOT on the base layer 1 chose.
        $slugStore = getStore($slug);
        $flash = self::matchedFlashSale($product, empty($slugStore) ? null : (int) $slugStore->id);
        $preTax = $base;

        if ($flash !== null) {
            $flashBase = $variantId > 0
                ? (self::variantPrice($product, $variantId) ?? 0.0)
                : (float) $product->sale_price;

            $discounted = $flash['discount_type'] === 'flat'
                ? $flashBase - (float) $flash['discount_amount']
                : $flashBase - ($flashBase * (float) $flash['discount_amount'] / 100);

            $components[] = self::component('flash_sale', __('Flash sale'), round($discounted - $base, 2), sprintf(
                /* translators: 1: flat/percentage, 2: the amount, 3: the base it is taken off */
                __('A %1$s flash sale of %2$s, taken off %3$s. Note the base: the flash sale is applied to the product\'s own sale price, not to the price layer 1 resolved.'),
                $flash['discount_type'] === 'flat' ? __('fixed') : __('percentage'),
                number_format((float) $flash['discount_amount'], 2),
                number_format($flashBase, 2)
            ));

            $preTax = round($discounted, 2);
            $notes[] = 'no_discount_priority_rule';
        }

        // ---- the clamp. `ProductPrice()` ends the discount section with `max($price, 0)`.
        if ($preTax < 0) {
            $components[] = self::component('negative_price_clamp', __('Clamped at zero'), -$preTax,
                __('The discounts took the price below zero and the engine clamps it to 0.00.'));
            $preTax = 0.0;
        }

        // ---- layer 3: tax bands, added per method, on the post-discount price.
        $components = array_merge($components, self::taxComponents($product, $preTax, $slug));

        $notes[] = 'coupon_not_included';

        return self::reconcile(self::ENGINE_PRODUCT_PRICE, $product->id, $variantId, $qty,
            $unitCharged, $components, $notes);
    }

    /*
    |--------------------------------------------------------------------------
    | engine 2 — WholesaleProduct::Wholesale_Product_price()  (PRICE-002)
    |--------------------------------------------------------------------------
    */

    /**
     * The price-list engine. Its own return value is a LINE total — `Wholesale_Product_price()`
     * multiplies by `qty` before taxing — so the charge is read as a line and divided back out for
     * `unit_charged`, rather than the reverse.
     *
     * @return array<string, mixed>
     */
    private static function explainWholesale(string $slug, Product $product, int $variantId, int $qty): array
    {
        $store = getStore($slug);

        if (empty($store)) {
            return self::unexplainable(self::ENGINE_WHOLESALE, $product->id, $variantId, $qty, 'store_not_found');
        }

        $variant = $variantId > 0 ? ProductVariant::find($variantId) : null;

        $answer = \Workdo\WholesaleProduct\app\Models\WholesaleProduct::Wholesale_Product_price(
            $product,
            ['qty' => $qty, 'varint' => $variant->variant ?? '', 'product_id' => $product->id],
            $store
        );

        if (! is_array($answer) || ! isset($answer['final_price'])) {
            // The engine's error paths return a `status`/`message` array, or nothing at all, and
            // both cart readers turn that into `?? 0` — a charge of zero. Reported as
            // unexplainable rather than explained as free.
            return self::unexplainable(self::ENGINE_WHOLESALE, $product->id, $variantId, $qty,
                'price_list_engine_returned_no_price');
        }

        $lineCharged = round((float) $answer['final_price'], 2);

        $components = [];
        $notes = [];
        $notes[] = 'price_list_engine_replaces_product_price';
        $notes[] = 'coupon_not_included';

        $tier = self::matchedTier($product, $qty);

        if ($tier !== null) {
            $components[] = self::component('price_list_tier', __('Quantity tier'),
                round((float) $tier->per_product_price * $qty, 2), sprintf(
                    /* translators: 1: min qty, 2: max qty, 3: unit price, 4: qty */
                    __('A price-list tier for %1$d-%2$d units at %3$s each, matched at quantity %4$d. The tier REPLACES the base price and both product-level discounts — the sale price and any flash sale do not apply.'),
                    (int) $tier->min_qty, (int) $tier->max_qty,
                    number_format((float) $tier->per_product_price, 2), $qty
                ));
            $preTax = round((float) $tier->per_product_price * $qty, 2);
        } else {
            $components[] = self::component('price_list_base', __('Sale price'),
                round((float) $product->sale_price * $qty, 2), sprintf(
                    /* translators: %d is the quantity */
                    __('No price-list tier matches quantity %d, so the price-list engine falls back to the product\'s sale price. It does NOT fall back to the list price, and it does not apply a flash sale.'),
                    $qty
                ));
            $preTax = round((float) $product->sale_price * $qty, 2);
            $notes[] = 'price_list_fallback_ignores_list_price';

            if ((float) $product->sale_price === 0.0) {
                $notes[] = 'blank_sale_price_is_zero';
            }
        }

        $components = array_merge($components, self::taxComponents($product, $preTax, $slug));

        $result = self::reconcile(self::ENGINE_WHOLESALE, $product->id, $variantId, $qty,
            $lineCharged, $components, $notes);

        // The wholesale engine answers a line, so `unit_charged` is derived and `line_charged` is
        // the measured figure -- the opposite way round from engine 1, and stated rather than
        // left for a reader to infer from a quantity of 1.
        $result['line_charged'] = $lineCharged;
        $result['unit_charged'] = $qty > 0 ? round($lineCharged / $qty, 2) : $lineCharged;

        return $result;
    }

    /*
    |--------------------------------------------------------------------------
    | shared readings of the engines' own primitives
    |--------------------------------------------------------------------------
    */

    /**
     * The tax bands, read exactly as `ProductPrice()` and `Tax::TaxCount()` read them.
     *
     * Both engines consult the same three things: `Tax::find($product->tax_id)`, that tax's
     * `tax_methods()` (which is already store-scoped inside the model) and the store's
     * `TaxOption` rows. Inclusive pricing adds nothing, which is why it produces a component of
     * 0.00 with an explanation rather than no component at all -- a merchant asking "why is there
     * no tax line" deserves the answer.
     *
     * @return list<array{code: string, label: string, amount: float, detail: string}>
     */
    private static function taxComponents(Product $product, float $preTax, string $slug): array
    {
        $tax = Tax::find($product->tax_id);

        if (! $tax || count($tax->tax_methods()) === 0) {
            return [];
        }

        $store = getStore($slug);
        $storeId = ! empty($store) ? $store->id : null;

        $options = $storeId === null ? [] : TaxOption::where('store_id', $storeId)
            ->pluck('value', 'name')->toArray();

        $inclusive = ($options['price_type'] ?? null) === 'inclusive'
            && ($options['shop_price'] ?? null) === 'including';

        if ($inclusive) {
            return [self::component('tax_inclusive', __('Tax (already included)'), 0.00,
                __('This store prices tax-inclusive, so the tax is already inside the figure above and nothing is added.'))];
        }

        $components = [];
        $taxTotal = 0.0;

        foreach ($tax->tax_methods() as $method) {
            $amount = (float) $method->tax_rate * $preTax / 100;
            $taxTotal += $amount;

            $components[] = self::component('tax_' . $method->id, (string) $method->name, round($amount, 2), sprintf(
                /* translators: 1: tax rate, 2: the amount it is charged on */
                __('%1$s%% of %2$s, added on top of the post-discount price.'),
                (string) $method->tax_rate, number_format($preTax, 2)
            ));
        }

        // `ProductPrice()` rounds the tax-INCLUSIVE total to a whole unit when `round_tax` is on,
        // so the rounding is its own line rather than an invisible adjustment inside the tax.
        if (($options['round_tax'] ?? null) == 1) {
            $rounded = round($preTax + $taxTotal);
            $delta = round($rounded - ($preTax + $taxTotal), 2);

            if (abs($delta) >= 0.005) {
                $components[] = self::component('tax_rounding', __('Rounding'), $delta,
                    __('This store rounds the tax-inclusive price to a whole unit.'));
            }
        }

        return $components;
    }

    /**
     * The flash sale that applies, matched with the same predicates `ProductPrice()` uses:
     * `store_id`, `is_active = 1`, the product's id inside the `sale_product` JSON array, and a
     * start/end window compared in the same timezone the engine forces.
     *
     * `ProductPrice()` keeps the LAST matching row (its loop overwrites `$latestSales[$productId]`
     * and does not break), so this does too. A store with two overlapping flash sales on one
     * product therefore has a winner decided by row order, which is `PRICE-004`'s absence again
     * and is surfaced in `notes`.
     *
     * THE STORE IS THE SLUG'S, NOT THE PRODUCT'S, and the difference is deliberate. `Product` is
     * not in `config/tenancy.php`'s `scoped_models` and carries no effective global scope, so
     * `ProductPrice($slug, $id)` can be reached with a product belonging to another store — and
     * when it is, the engine resolves flash sales for the SLUG's store. Keying this on
     * `$product->store_id` instead would make the explanation disagree with the charge in exactly
     * that case, which is the one thing this class must never do.
     *
     * @return array{discount_type: string, discount_amount: float}|null
     */
    private static function matchedFlashSale(Product $product, ?int $storeId): ?array
    {
        if ($storeId === null) {
            return null;
        }

        $store = $storeId;
        $matched = null;

        $previous = date_default_timezone_get();
        date_default_timezone_set('Asia/Kolkata');

        try {
            $now = \Carbon\Carbon::now()->toDateTimeString();

            foreach (\App\Models\FlashSale::where('store_id', $store)->where('is_active', 1)->get() as $sale) {
                $products = json_decode((string) $sale->sale_product, true);

                if (! is_array($products) || ! in_array($product->id, $products)) {
                    continue;
                }

                $start = \Carbon\Carbon::parse($sale->start_date . ' ' . $sale->start_time);
                $end = \Carbon\Carbon::parse($sale->end_date . ' ' . $sale->end_time);

                if ($end < $start) {
                    $end->addDay();
                }

                if ($now >= $start && $now <= $end) {
                    $matched = [
                        'discount_type' => (string) $sale->discount_type,
                        'discount_amount' => (float) $sale->discount_amount,
                    ];
                }
            }
        } finally {
            date_default_timezone_set($previous);
        }

        return $matched;
    }

    /**
     * The price-list tier that matches, with the engine's own `break` — the FIRST matching row
     * wins, which is the opposite of the flash sale's last-row-wins and is worth a reader knowing.
     */
    private static function matchedTier(Product $product, int $qty): ?object
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('wholesale_products')) {
            return null;
        }

        foreach (\Illuminate\Support\Facades\DB::table('wholesale_products')
            ->where('product_id', $product->id)->get() as $row) {
            if ($qty >= (int) $row->min_qty && $qty <= (int) $row->max_qty) {
                return $row;
            }
        }

        return null;
    }

    private static function variantPrice(Product $product, int $variantId): ?float
    {
        $variant = ProductVariant::where('product_id', $product->id)->where('id', $variantId)->first();

        return $variant === null ? null : (float) $variant->price;
    }

    /*
    |--------------------------------------------------------------------------
    | the reconciliation — the only reason this class is safe to show a merchant
    |--------------------------------------------------------------------------
    */

    /**
     * @param  list<array{code: string, label: string, amount: float, detail: string}>  $components
     * @param  list<string>  $notes
     * @return array<string, mixed>
     */
    private static function reconcile(string $engine, int $productId, int $variantId, int $qty,
        float $charged, array $components, array $notes): array
    {
        $total = round(array_sum(array_column($components, 'amount')), 2);
        $residual = round($charged - $total, 2);
        $reconciled = abs($residual) < 0.005;

        if (! $reconciled) {
            // THE REFUSAL. The components do not account for the charge, so the breakdown is not
            // presented as complete: the gap is named, signed and labelled, and `reconciled` is
            // false so a caller can decline to show a breakdown at all. This is the branch an
            // owner decision landing on `J17` or `J18` will take until this class is updated with
            // it, and taking it is correct behaviour, not a bug.
            $components[] = self::component('unexplained', __('Unexplained difference'), $residual,
                __('The components above do not account for the price the pricing engine returned. Something in the pricing path is not represented here, so this breakdown is incomplete and must not be presented as the reason for the price.'));
            $notes[] = 'breakdown_does_not_reconcile';
        }

        return [
            'engine' => $engine,
            'product_id' => $productId,
            'variant_id' => $variantId,
            'qty' => $qty,
            'unit_charged' => $charged,
            'line_charged' => round($charged * $qty, 2),
            'components' => $components,
            'components_total' => $reconciled ? $total : round($total + $residual, 2),
            'reconciled' => $reconciled,
            'residual' => $residual,
            'notes' => array_values(array_unique($notes)),
        ];
    }

    /** @return array<string, mixed> */
    private static function unexplainable(string $engine, int $productId, int $variantId, int $qty, string $reason): array
    {
        $notes = [];
        $notes[] = $reason;
        $notes[] = 'breakdown_does_not_reconcile';

        return [
            'engine' => $engine,
            'product_id' => $productId,
            'variant_id' => $variantId,
            'qty' => $qty,
            'unit_charged' => null,
            'line_charged' => null,
            'components' => [],
            'components_total' => 0.00,
            'reconciled' => false,
            'residual' => 0.00,
            'notes' => $notes,
        ];
    }

    /** @return array{code: string, label: string, amount: float, detail: string} */
    private static function component(string $code, string $label, float $amount, string $detail): array
    {
        return ['code' => $code, 'label' => $label, 'amount' => round($amount, 2), 'detail' => $detail];
    }

    /**
     * The sentences behind the `notes` codes, so a surface renders prose and the codes stay stable
     * for a test to assert on.
     *
     * @return array<string, string>
     */
    public static function noteText(): array
    {
        return [
            'no_discount_priority_rule' => __('More than one discount applies to this product. There is no discount-priority rule in this system, so the order below is the order the code happens to apply and the discounts compound.'),
            'coupon_not_included' => __('Coupons are not included. A coupon is applied to the whole basket at checkout, not to a single product, so the final amount a customer pays may be lower than this.'),
            'price_list_engine_replaces_product_price' => __('A price-list module is active for this store, and it replaces the ordinary pricing engine for this product. The sale price and any flash sale are NOT applied while it governs.'),
            'price_list_fallback_ignores_list_price' => __('No price-list tier matches this quantity. The price-list engine then uses the sale price and does not fall back to the list price.'),
            'blank_sale_price_is_zero' => __('This product\'s sale price is 0.00. The system treats a blank sale price as zero, which can make the product free on some paths. Set a sale price, or set it equal to the list price, until this is resolved.'),
            'breakdown_does_not_reconcile' => __('This breakdown does not add up to the price the system will actually charge, so it is incomplete. Do not rely on it.'),
            'product_not_found' => __('No such product.'),
            'store_not_found' => __('No such store.'),
            'price_list_engine_returned_no_price' => __('The price-list module did not return a price for this product and quantity. On the shopper-facing paths that becomes a charge of 0.00.'),
        ];
    }
}
