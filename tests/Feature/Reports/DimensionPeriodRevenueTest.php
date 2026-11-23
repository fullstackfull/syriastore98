<?php

namespace Tests\Feature\Reports;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\Store;
use App\Support\Reports\DimensionPeriodRevenue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Security\SecurityTestCase;

/**
 * ANA-TOP — the shared accumulator the category and brand reports' eight period branches use.
 *
 * Its own docblock states the three defects it replaces. This file drives it directly, because
 * the eight call sites exercise it through four different bucket-index calculations and a bug in
 * the shared piece would surface as eight separate, differently-shaped report failures.
 */
class DimensionPeriodRevenueTest extends SecurityTestCase
{
    use RefreshDatabase;

    private Store $store;

    private int $categoryId;

    private int $brandId;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        [, $this->store] = $this->makeTenant();
        [$this->categoryId, $this->brandId, $this->product] = $this->catalogue($this->store, 'Lamps');
    }

    // ------------------------------------------------------------------- the currency rule

    public function test_two_currencies_are_never_added_together(): void
    {
        $this->storeCurrency($this->store, 'EUR');

        $revenue = $this->accumulate([
            [0, 'EUR', 25.0],
            [0, 'USD', 90.0],
        ]);

        $this->assertSame('EUR', $revenue->primaryCurrency());
        $this->assertTrue($revenue->primaryCurrencyIsConfigured());
        $this->assertSame(25.0, $revenue->total($this->categoryId),
            '115.00 means the two were added; 90.00 means the wrong one was chosen');
        $this->assertSame(['USD' => 90.0], $revenue->others($this->categoryId));
    }

    public function test_an_order_with_no_recorded_currency_is_counted_and_disclosed(): void
    {
        $this->storeCurrency($this->store, 'EUR');

        $revenue = $this->accumulate([
            [0, 'EUR', 25.0],
            [0, '', 10.0],
        ]);

        $this->assertSame(35.0, $revenue->total($this->categoryId),
            'an order with no currency was dropped; those rows are real money');
        $this->assertSame(10.0, $revenue->unrecorded($this->categoryId));
        $this->assertStringContainsString('10.00', $revenue->caveat($this->categoryId));
    }

    public function test_a_dimension_earning_only_a_foreign_currency_has_no_comparable_total(): void
    {
        $this->storeCurrency($this->store, 'EUR');

        $revenue = $this->accumulate([[0, 'GBP', 40.0]]);

        $this->assertNull($revenue->total($this->categoryId),
            '0.00 would read as "no sales", which is a different and false statement');
        $this->assertSame(['GBP' => 40.0], $revenue->others($this->categoryId));
        $this->assertStringContainsString('GBP', $revenue->caveat($this->categoryId));
    }

    public function test_an_unconfigured_store_is_denominated_in_its_busiest_currency(): void
    {
        /* No currency setting at all. Classing every bucket as foreign would leave the whole
           report with no comparable total, which is a refusal where an answer exists. */
        $revenue = $this->accumulate([
            [0, 'EUR', 30.0],
            [1, 'EUR', 70.0],
            [0, 'GBP', 10.0],
        ]);

        $this->assertSame('EUR', $revenue->primaryCurrency());
        $this->assertFalse($revenue->primaryCurrencyIsConfigured(),
            'a figure drawn with the store symbol while denominated in an INFERRED currency says '
            . 'two different things at once');
        $this->assertSame(100.0, $revenue->total($this->categoryId));
    }

    public function test_the_store_configured_currency_wins_over_the_busiest_one(): void
    {
        $this->storeCurrency($this->store, 'EUR');

        $revenue = $this->accumulate([
            [0, 'EUR', 5.0],
            [0, 'USD', 500.0],
        ]);

        $this->assertSame('EUR', $revenue->primaryCurrency());
        $this->assertSame(5.0, $revenue->total($this->categoryId));
    }

    // ------------------------------------------------------------------- tenancy

    public function test_another_tenants_product_in_our_order_is_dropped_not_attributed(): void
    {
        $this->storeCurrency($this->store, 'EUR');

        [, , $foreign] = $this->catalogue($this->makeTenant()[1], 'Lamps');

        /* Force the collision the old `Product::find()` needed: the foreign product is filed
           under OUR category id. Raw, then flushed, because `Product` is `Cachable`. */
        DB::table('products')->where('id', $foreign->id)->update(['category_id' => $this->categoryId]);
        (new Product)->flushCache();

        $this->assertSame(
            $this->categoryId,
            (int) DB::table('products')->where('id', $foreign->id)->value('category_id'),
            'premise failed: the collision this case needs was not created'
        );

        $revenue = DimensionPeriodRevenue::forOrders(
            (int) $this->store->id,
            DimensionPeriodRevenue::CATEGORY,
            [$this->orderRow('EUR', [[$foreign->id, 777.0]])],
            3,
            fn () => 0
        );

        $this->assertNull($revenue->total($this->categoryId),
            "another tenant's product was counted into this store's report, attributed by that "
            . "foreign product's own category");
        $this->assertSame([], $revenue->dimensionIds());
    }

    // ------------------------------------------------------------------- the series shape

    public function test_the_series_is_always_the_full_bucket_width_and_numeric(): void
    {
        $this->storeCurrency($this->store, 'EUR');

        $revenue = $this->accumulate([[2, 'EUR', 12.0]], 5);
        $series = $revenue->series($this->categoryId);

        /* A short or sparse array is what made `order_category_chart` print `1` per row: the view
           cast a non-numeric shape with `intval()`. The width is part of the contract. */
        $this->assertCount(5, $series);
        $this->assertSame([0.0, 0.0, 12.0, 0.0, 0.0], $series);

        foreach ($series as $value) {
            $this->assertIsFloat($value);
        }
    }

    public function test_a_dimension_with_no_money_still_answers_a_zero_series(): void
    {
        $this->storeCurrency($this->store, 'EUR');

        $revenue = $this->accumulate([[0, 'EUR', 5.0]], 4);

        $this->assertSame([0.0, 0.0, 0.0, 0.0], $revenue->series(999_999));
        $this->assertNull($revenue->total(999_999));
        $this->assertSame([], $revenue->others(999_999));
    }

    public function test_an_order_outside_the_bucket_range_is_dropped_not_folded_into_slot_zero(): void
    {
        $this->storeCurrency($this->store, 'EUR');

        $revenue = DimensionPeriodRevenue::forOrders(
            (int) $this->store->id,
            DimensionPeriodRevenue::CATEGORY,
            [
                $this->orderRow('EUR', [[$this->product->id, 10.0]]),
                $this->orderRow('EUR', [[$this->product->id, 999.0]]),
            ],
            3,
            /* The second order answers out of range -- a real possibility in the `year` branch,
               where the index comes from a date the window does not actually contain. Folding it
               into slot 0 would silently misdate money. */
            fn ($order) => ((float) json_decode($order->product_json, true)[0]['final_price']) > 100 ? 7 : 0
        );

        $this->assertSame(10.0, $revenue->total($this->categoryId));
        $this->assertSame([10.0, 0.0, 0.0], $revenue->series($this->categoryId));
    }

    public function test_an_unreadable_product_json_is_skipped_not_fatal(): void
    {
        $this->storeCurrency($this->store, 'EUR');

        $broken = (object) ['product_json' => '{not json', 'currency_code' => 'EUR'];

        $revenue = DimensionPeriodRevenue::forOrders(
            (int) $this->store->id,
            DimensionPeriodRevenue::CATEGORY,
            [$broken, $this->orderRow('EUR', [[$this->product->id, 8.0]])],
            2,
            fn () => 0
        );

        $this->assertSame(8.0, $revenue->total($this->categoryId),
            'one unreadable order took the whole report down, or dropped the readable one with it');
    }

    // ------------------------------------------------------------------- brand parity

    public function test_the_brand_dimension_obeys_the_same_rules(): void
    {
        $this->storeCurrency($this->store, 'EUR');

        $revenue = DimensionPeriodRevenue::forOrders(
            (int) $this->store->id,
            DimensionPeriodRevenue::BRAND,
            [
                $this->orderRow('EUR', [[$this->product->id, 25.0]]),
                $this->orderRow('USD', [[$this->product->id, 90.0]]),
            ],
            2,
            fn () => 0
        );

        $this->assertSame(25.0, $revenue->total($this->brandId));
        $this->assertSame(['USD' => 90.0], $revenue->others($this->brandId));
    }

    // ------------------------------------------------------------------- the aggregate row

    public function test_the_aggregate_row_adds_the_series_and_not_the_raw_buckets(): void
    {
        /*
         * "All Categories" / "All Brands" IS THE ROW A MERCHANT READS AS "my whole month", so it
         * is the worst place for a cross-currency sum to survive. Adding the per-dimension SERIES
         * bucket by bucket is arithmetic on like quantities, because each series is already
         * denominated in one currency. Re-reading the raw buckets would fold the foreign ones
         * straight back in.
         *
         * Here: 20 EUR in bucket 0 of Lamps, 5 EUR in bucket 1, and 90 USD that must stay out.
         */
        $this->storeCurrency($this->store, 'EUR');

        [$otherCategory, , $otherProduct] = $this->catalogue($this->store, 'Chairs');

        $orders = [
            $this->orderRow('EUR', [[$this->product->id, 20.0]]),
            $this->orderRow('EUR', [[$otherProduct->id, 5.0]]),
            $this->orderRow('USD', [[$this->product->id, 90.0]]),
        ];
        $orders[0]->b = 0;
        $orders[1]->b = 1;
        $orders[2]->b = 0;

        $revenue = DimensionPeriodRevenue::forOrders(
            (int) $this->store->id,
            DimensionPeriodRevenue::CATEGORY,
            $orders,
            3,
            fn ($order) => (int) $order->b
        );

        $ids = [$this->categoryId, $otherCategory];

        $this->assertSame([20.0, 5.0, 0.0], $revenue->aggregateSeries($ids),
            '110.00 in bucket 0 means the 90 USD was added into the aggregate');
        $this->assertSame(['USD' => 90.0], $revenue->aggregateOthers($ids));
        $this->assertStringContainsString('90.00', $revenue->aggregateCaveat($ids),
            'the aggregate row hides the foreign money its own figure excludes');
    }

    public function test_the_aggregate_covers_the_selection_and_not_the_whole_catalogue(): void
    {
        /*
         * The report's aggregate row is built from the SELECTED categories, because the screen is
         * filtered. An aggregate that silently covered everything would exceed the sum of the
         * rows above it -- a figure that contradicts the table it sits under.
         */
        $this->storeCurrency($this->store, 'EUR');

        [$excluded, , $excludedProduct] = $this->catalogue($this->store, 'Chairs');

        $orders = [
            $this->orderRow('EUR', [[$this->product->id, 11.0]]),
            $this->orderRow('EUR', [[$excludedProduct->id, 44.0]]),
        ];
        foreach ($orders as $order) {
            $order->b = 0;
        }

        $revenue = DimensionPeriodRevenue::forOrders(
            (int) $this->store->id,
            DimensionPeriodRevenue::CATEGORY,
            $orders,
            2,
            fn ($order) => (int) $order->b
        );

        /* Only Lamps is selected. */
        $this->assertSame([11.0, 0.0], $revenue->aggregateSeries([$this->categoryId]));

        /* Both, to show the method is reading the argument and not a fixed set. */
        $this->assertSame([55.0, 0.0], $revenue->aggregateSeries([$this->categoryId, $excluded]));
        $this->assertNotSame($excluded, $this->categoryId, 'premise: the two categories are distinct');
    }

    public function test_the_aggregate_withholds_a_total_it_cannot_state(): void
    {
        $this->storeCurrency($this->store, 'EUR');

        $orders = [$this->orderRow('GBP', [[$this->product->id, 40.0]])];
        $orders[0]->b = 0;

        $revenue = DimensionPeriodRevenue::forOrders(
            (int) $this->store->id,
            DimensionPeriodRevenue::CATEGORY,
            $orders,
            2,
            fn ($order) => (int) $order->b
        );

        $this->assertSame([0.0, 0.0], $revenue->aggregateSeries([$this->categoryId]));
        $this->assertStringContainsString(
            __('Nothing was earned in your store currency here, so this row has no comparable total.'),
            $revenue->aggregateCaveat([$this->categoryId]),
            'a zero series with no disclosure reads as "no sales", which is false -- 40 GBP was taken'
        );
    }

    // ------------------------------------------------------------------- the disclosure

    public function test_every_caveat_opens_with_the_shared_order_value_sentence(): void
    {
        $this->storeCurrency($this->store, 'EUR');

        $revenue = $this->accumulate([[0, 'EUR', 25.0]]);

        /* Asserted against the method, not a literal, so this cannot pass while the single source
           §11 defines changes underneath it. */
        /* The HINT, not just the definition: `orderValueHint()` is the definition plus
           `MetricCaveat::FLOAT_PRECISION`, and these screens carried both before this change.
           Asserting the weaker one let a dropped caveat through -- four pre-existing tests
           caught it, and this is the assertion that should have. */
        $this->assertStringStartsWith(
            \App\Support\Reports\ReportFigureNote::orderValueHint(),
            $revenue->caveat($this->categoryId),
            'the report would claim money received for a figure that counts unpaid, cancelled and '
            . 'refunded order lines'
        );
    }

    // ------------------------------------------------------------------- fixtures

    /** @param list<array{0: int, 1: string, 2: float}> $rows bucket, currency, amount */
    private function accumulate(array $rows, int $buckets = 3): DimensionPeriodRevenue
    {
        $orders = [];

        foreach ($rows as [$bucket, $currency, $amount]) {
            $order = $this->orderRow($currency, [[$this->product->id, $amount]]);
            $order->ss_bucket = $bucket;
            $orders[] = $order;
        }

        return DimensionPeriodRevenue::forOrders(
            (int) $this->store->id,
            DimensionPeriodRevenue::CATEGORY,
            $orders,
            $buckets,
            fn ($order) => (int) $order->ss_bucket
        );
    }

    /** @param list<array{0: int, 1: float}> $lines product id, amount */
    private function orderRow(string $currency, array $lines): object
    {
        $json = [];

        foreach ($lines as [$productId, $amount]) {
            $json[] = [
                'product_id' => $productId,
                'qty' => 1,
                'quantity' => 1,
                'price' => $amount,
                'final_price' => $amount,
            ];
        }

        /* A PLAIN OBJECT, which is what the handlers hand over: they pass an Eloquent collection
           of `Order` rows, and this accumulator reads only `product_json` and `currency_code`.
           Taking the narrow shape keeps it drivable without the order fixture, and keeps it
           honest -- it cannot quietly start depending on a model it was not given. */
        return (object) [
            'product_json' => json_encode($json),
            'currency_code' => $currency,
        ];
    }

    private function storeCurrency(Store $store, string $code): void
    {
        DB::table('settings')->insert([
            'store_id' => $store->id, 'name' => 'defult_currancy', 'value' => $code,
            'created_by' => $store->created_by,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{0: int, 1: int, 2: Product} */
    private function catalogue(Store $store, string $name): array
    {
        $suffix = $name . '-' . $store->id . '-' . bin2hex(random_bytes(3));

        $categoryId = (int) Category::create([
            'name' => $suffix, 'slug' => Str::slug($suffix),
            'parent_id' => 0, 'trending' => 0, 'status' => 1, 'store_id' => $store->id,
        ])->id;

        $brandId = (int) ProductBrand::create([
            'name' => $suffix . '-brand', 'slug' => Str::slug($suffix) . '-brand',
            'status' => 1, 'store_id' => $store->id, 'created_by' => $store->created_by,
        ])->id;

        /* `forceCreate` and every NOT NULL column: `track_stock` is required, `brand_id` is not
           fillable (so `create()` would drop it and the brand cases would test nothing), and
           `products` HAS NO `sub_category_id` column at all. */
        $product = Product::forceCreate([
            'name' => $suffix, 'slug' => Str::slug($suffix) . '-p',
            'category_id' => $categoryId, 'brand_id' => $brandId,
            'product_stock' => 100, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 10, 'sale_price' => 10, 'stock_status' => 'in_stock',
            'status' => 1, 'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);

        return [$categoryId, $brandId, $product];
    }
}
