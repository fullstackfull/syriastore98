<?php

namespace Tests\Feature\Merchant;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Support\TopSellersByRevenue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * F242 — THE "BY SALES" CARDS REPORT THE REAL FIGURES, FOR ANY NUMBER OF PRODUCTS.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * WHY THESE CASES COULD NOT EXIST BEFORE
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * The old implementation was twelve queries built on `SUBSTRING_INDEX` and `CURDATE` — **neither of
 * which SQLite has** — so the Feature suite could not execute this card at all, and its only
 * coverage was a MariaDB script plus a browser harness that rendered the cards empty. The figures
 * on the two most prominent dashboard cards were therefore untested in the ordinary suite for the
 * whole of Phase 02.
 *
 * `TopSellersByRevenue` is portable PHP over `orders.product_json`, so these figures are testable
 * here now. The MariaDB half still exists and still matters — it proves the same numbers on the
 * engine production runs — but the arithmetic is no longer only provable there.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * EVERY EXPECTED TOTAL BELOW IS COMPUTED INDEPENDENTLY, which is the owner's condition
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * The directive says *"compare rendered figures with independent expected totals"*. So no case here
 * asks the implementation what it thinks and then asserts that: each expected figure is written as
 * an arithmetic literal derived from the fixture by hand. A test that recomputed the total the same
 * way the code does would pass against the fan-out bug too.
 */
class TopSellersRevenueAccuracyTest extends SecurityTestCase
{
    use RefreshDatabase;

    private $store;

    private $otherStore;

    protected function setUp(): void
    {
        parent::setUp();

        [, $this->store] = $this->makeTenant();
        [, $this->otherStore] = $this->makeTenant();
    }

    private function category(string $name, $store = null): Category
    {
        return Category::forceCreate([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid(),
            'image_path' => 'uploads/' . $name . '.png',
            'status' => 1,
            'store_id' => ($store ?? $this->store)->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function product(Category $category, $store = null): Product
    {
        $store = $store ?? $this->store;

        return Product::forceCreate([
            'name' => 'p-' . uniqid(), 'slug' => 'p-' . uniqid(),
            'category_id' => $category->id,
            'product_stock' => 100, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 10, 'sale_price' => 10, 'stock_status' => 'in_stock',
            'status' => 1, 'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    /**
     * @param  array<int, array{0:Product, 1:int, 2:float}>  $lines  [product, qty, unit price]
     */
    private function order(array $lines, $store = null, ?string $createdAt = null): Order
    {
        $store = $store ?? $this->store;
        $json = [];
        $ids = [];
        $total = 0.0;

        foreach ($lines as [$product, $qty, $unit]) {
            $json[] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'qty' => $qty,
                'quantity' => $qty,
                'price' => $unit,
                'final_price' => $qty * $unit,
            ];
            $ids[] = $product->id;
            $total += $qty * $unit;
        }

        $order = Order::create([
            'product_order_id' => 'ORD-' . strtoupper(uniqid()),
            'order_date' => now(),
            'customer_id' => 0,
            'is_guest' => 1,
            'product_json' => json_encode($json),
            /* The comma-separated list the OLD query parsed. Written faithfully so a reader can see
               that the new computation does not consult it — and so a revert would have data. */
            'product_id' => implode(',', $ids),
            'product_price' => $total,
            'coupon_price' => 0,
            /* Order-level money that belongs to NO category. Deliberately non-zero, because an
               implementation that summed `final_price` per category would include it. */
            'delivery_price' => 25,
            'tax_price' => 15,
            'final_price' => $total + 40,
            'payment_type' => 'cod',
            'payment_status' => 'Unpaid',
            'delivered_status' => 0,
            'store_id' => $store->id,
            'created_by' => $store->created_by,
        ]);

        if ($createdAt !== null) {
            /* `timestamps = false` so an explicit past `created_at` is not overwritten, and through
               Eloquent so the Cachable read-back is flushed. */
            $order->timestamps = false;
            $order->created_at = $createdAt;
            $order->save();
        }

        return $order->fresh();
    }

    private function totalFor(array $rows, string $name): float
    {
        foreach ($rows as $row) {
            if ($row->sale_name === $name) {
                return (float) $row->total_sale;
            }
        }

        return -1.0;
    }

    /**
     * THE CASE THE OLD CODE GOT RIGHT BY ACCIDENT. One product, so the old fan-out was exactly 100
     * and the `/100` divisor cancelled it. Kept so the fix is shown not to break what worked.
     */
    public function test_a_single_product_order_is_counted_once(): void
    {
        $c = $this->category('Lamps');
        $p = $this->product($c);
        $this->order([[$p, 3, 20.0]]);                       // 3 x 20 = 60

        $rows = TopSellersByRevenue::compute(TopSellersByRevenue::CATEGORY, $this->store->id);

        $this->assertCount(1, $rows);
        $this->assertSame(60.0, $this->totalFor($rows, 'Lamps'));
    }

    /**
     * THE CASE THE OLD CODE GOT WRONG, and the one F242 is about.
     *
     * Two products in two categories. Under the old shape the first category joined ONCE and was
     * then divided by 100, so a 60 read as 1.3 (the order total 140 / 100 rounded) while the second
     * joined 99 times and read near the full order total. Both were wrong, and in opposite
     * directions.
     *
     * Expected by hand: Lamps 3 x 20 = 60, Rugs 2 x 50 = 100. Neither includes the 25 delivery or
     * the 15 tax, which belong to no category.
     */
    public function test_a_two_product_order_credits_each_category_its_own_lines(): void
    {
        $lamps = $this->category('Lamps');
        $rugs = $this->category('Rugs');
        $this->order([
            [$this->product($lamps), 3, 20.0],
            [$this->product($rugs), 2, 50.0],
        ]);

        $rows = TopSellersByRevenue::compute(TopSellersByRevenue::CATEGORY, $this->store->id);

        $this->assertCount(2, $rows);
        $this->assertSame(60.0, $this->totalFor($rows, 'Lamps'));
        $this->assertSame(100.0, $this->totalFor($rows, 'Rugs'));

        /* AND THE SUM IS NOT THE ORDER TOTAL, which is the second half of the defect. An
           implementation crediting each category the whole order would read 140 and 140. */
        $this->assertSame(160.0, array_sum(array_map(fn ($r) => $r->total_sale, $rows)),
            'the per-category figures add up to the ORDER total, so order-level money '
            . '(delivery 25 + tax 15) is being attributed to categories that did not earn it');
    }

    /**
     * THREE AND FOUR PRODUCTS, because the old fan-out error grew with the line count and the
     * directive names those counts explicitly.
     *
     * Expected by hand:
     *   Lamps 1x10 + 5x4   = 10 + 20 = 30
     *   Rugs  2x30         = 60
     *   Tiles 3x7          = 21
     */
    public function test_three_and_four_line_orders_are_exact(): void
    {
        $lamps = $this->category('Lamps');
        $rugs = $this->category('Rugs');
        $tiles = $this->category('Tiles');

        $lampA = $this->product($lamps);
        $lampB = $this->product($lamps);         // same category, two products

        $this->order([
            [$lampA, 1, 10.0],
            [$this->product($rugs), 2, 30.0],
            [$this->product($tiles), 3, 7.0],
        ]);
        $this->order([[$lampB, 5, 4.0]]);

        $rows = TopSellersByRevenue::compute(TopSellersByRevenue::CATEGORY, $this->store->id);

        $this->assertSame(30.0, $this->totalFor($rows, 'Lamps'),
            'two products of one category across two orders did not add up');
        $this->assertSame(60.0, $this->totalFor($rows, 'Rugs'));
        $this->assertSame(21.0, $this->totalFor($rows, 'Tiles'));

        /* Ordering is part of what a "top sellers" card is. */
        $this->assertSame(['Rugs', 'Lamps', 'Tiles'], array_map(fn ($r) => $r->sale_name, $rows),
            'the card is not sorted by revenue descending');
    }

    /**
     * STORE ISOLATION, and it is not inherited: `Product` registers no `StoreScope` and is absent
     * from `config/tenancy.php`'s `scoped_models`, so an unpredicated `whereIn('id', …)` reads every
     * tenant's catalogue. Both stores use the SAME category name so a leak shows up as a wrong
     * figure rather than as an extra row.
     */
    public function test_another_stores_revenue_is_not_counted(): void
    {
        $mine = $this->category('Lamps');
        $theirs = $this->category('Lamps', $this->otherStore);

        $this->order([[$this->product($mine), 1, 10.0]]);
        $this->order([[$this->product($theirs, $this->otherStore), 1, 999.0]], $this->otherStore);

        $rows = TopSellersByRevenue::compute(TopSellersByRevenue::CATEGORY, $this->store->id);

        $this->assertCount(1, $rows);
        $this->assertSame(10.0, $this->totalFor($rows, 'Lamps'),
            "another store's order reached this store's figures");

        $theirRows = TopSellersByRevenue::compute(TopSellersByRevenue::CATEGORY, $this->otherStore->id);
        $this->assertSame(999.0, $this->totalFor($theirRows, 'Lamps'), 'and the other store sees its own');
    }

    /**
     * THE CROSS-TENANT ORDER LINE, which is what the `products.store_id` predicate is actually for.
     *
     * **ADDED BECAUSE A MUTATION EXPOSED THE GAP.** Dropping that predicate failed NO test: the
     * product ids fed to `whereIn` already come from this store's own orders, so the predicate was
     * unexercised and the isolation case above passed either way. A guard no test reaches is the
     * F237 shape, and it was in my own fix.
     *
     * The case that reaches it: store A has an order whose LINE names store B's product. `Product`
     * registers no `StoreScope` and is absent from `scoped_models` (S19), so an unpredicated lookup
     * resolves it happily and would credit store B's category — inside store A's dashboard.
     *
     * Correct behaviour is that the line contributes to NEITHER store: not to A, because A does not
     * own the product; not to B, because B did not take the order. It is dropped and the figures stay
     * honest, which is better than guessing which tenant it belongs to.
     */
    public function test_an_order_line_naming_another_stores_product_is_dropped(): void
    {
        $mine = $this->category('Lamps');
        $theirCategory = $this->category('Rugs', $this->otherStore);
        $theirProduct = $this->product($theirCategory, $this->otherStore);

        /* Store A's order, one honest line plus one naming store B's product. */
        $this->order([
            [$this->product($mine), 1, 10.0],
            [$theirProduct, 1, 500.0],
        ]);

        $rows = TopSellersByRevenue::compute(TopSellersByRevenue::CATEGORY, $this->store->id);

        $this->assertCount(1, $rows,
            "another store's product was resolved AND its category rendered inside this store's "
            . 'dashboard, so BOTH store predicates are missing — see the ratchet below for why '
            . 'this case cannot name which one');
        $this->assertSame(10.0, $this->totalFor($rows, 'Lamps'));
        $this->assertSame(-1.0, $this->totalFor($rows, 'Rugs'),
            "store B's category appeared in store A's figures");

        /* And store B does not gain revenue from an order it never took. */
        $theirRows = TopSellersByRevenue::compute(TopSellersByRevenue::CATEGORY, $this->otherStore->id);
        $this->assertSame([], $theirRows,
            "store B was credited 500 from store A's order");
    }

    /**
     * THE TWO STORE PREDICATES ARE REDUNDANT WITH EACH OTHER, AND THAT IS WHY THIS RATCHET EXISTS
     * RATHER THAN A BEHAVIOURAL TEST.
     *
     * **MEASURED, and it corrected a claim I had just made.** The case above was written to prove
     * that `products.store_id` keeps another tenant's product out. It does not prove that: removing
     * that predicate alone failed NO test, and removing the `categories`/`product_brands` predicate
     * alone failed no test either. Each one covers the other —
     *
     *   - without the PRODUCTS predicate, store B's product resolves and maps to store B's
     *     category id, but the LABELS query then refuses that id and the row is dropped unnamed;
     *   - without the LABELS predicate, the products query never let store B's id into the map in
     *     the first place.
     *
     * A category or brand id belongs to exactly one store, so no input shape makes the pair
     * non-redundant: only removing BOTH leaks, and no single-mutation test can isolate either. That
     * is defence in depth working as intended, and it is also exactly the condition under which a
     * guard quietly stops being exercised — the F237 shape.
     *
     * So the pair is ratcheted at the source, with the reason attached. A reader who removes one
     * "because nothing fails" is told here that nothing failing is expected and is not permission.
     */
    public function test_both_store_predicates_are_present_because_neither_alone_is_detectable(): void
    {
        $source = file_get_contents(app_path('Support/TopSellersByRevenue.php'));

        /* THREE, not two: the `orders` query carries one on the same line as its `DB::table()`,
           so it matches this needle too. Counted after measuring rather than predicted — the first
           version of this assertion said 2 and failed against a correct file. */
        $this->assertSame(3, substr_count($source, "->where('store_id', \$storeId)"),
            'TopSellersByRevenue no longer carries all THREE store predicates — one on `orders`, '
            . 'one on the `products` '
            . 'lookup and one on the `categories`/`product_brands` lookup. Removing either alone '
            . 'breaks no test, because each covers the other; removing both leaks another tenant\'s '
            . 'revenue into this dashboard. Product registers no StoreScope and is absent from '
            . "config/tenancy.php's scoped_models, so neither predicate is inherited.");

        /* And the orders query carries its own, which IS individually load-bearing — the isolation
           case above fails without it. */
        $this->assertStringContainsString("DB::table('orders')->where('store_id', \$storeId)", $source,
            'the orders query lost its store predicate, so every tenant\'s orders are summed');
    }

    /** No tenant resolves — refuse rather than return every store's revenue. */
    public function test_an_unresolved_store_gets_no_figures(): void
    {
        $this->order([[$this->product($this->category('Lamps')), 1, 10.0]]);

        foreach ([null, 0, '', 'abc', -1] as $storeId) {
            $this->assertSame([], TopSellersByRevenue::compute(TopSellersByRevenue::CATEGORY, $storeId),
                'an unresolved store id produced figures');
        }
    }

    /**
     * THE WINDOW IS HALF-OPEN `[from, to)`, so an order at exactly the boundary belongs to one
     * period and not two. A closed upper bound double-counts midnight.
     */
    public function test_the_date_window_is_half_open(): void
    {
        $c = $this->category('Lamps');
        $p = $this->product($c);

        $start = now()->startOfDay();
        $end = $start->copy()->addDay();

        $this->order([[$p, 1, 7.0]], null, $start->toDateTimeString());               // in
        $this->order([[$p, 1, 11.0]], null, $start->copy()->addHours(5)->toDateTimeString()); // in
        $this->order([[$p, 1, 13.0]], null, $end->toDateTimeString());                // OUT, exactly
        $this->order([[$p, 1, 17.0]], null, $start->copy()->subSecond()->toDateTimeString()); // OUT

        $rows = TopSellersByRevenue::compute(
            TopSellersByRevenue::CATEGORY, $this->store->id,
            $start->toDateTimeString(), $end->toDateTimeString()
        );

        $this->assertSame(18.0, $this->totalFor($rows, 'Lamps'),
            'the window is not half-open: 7 + 11 = 18 is the only correct answer, and 31 means the '
            . 'upper bound is inclusive while 35 means the lower bound leaks');

        /* All time sees everything. */
        $all = TopSellersByRevenue::compute(TopSellersByRevenue::CATEGORY, $this->store->id);
        $this->assertSame(48.0, $this->totalFor($all, 'Lamps'));
    }

    /**
     * THE KEY SPELLINGS, driven directly, because the platform's writers do not agree: measured
     * across `app/` and the vendored modules, `quantity` appears at 33 sites and `qty` at 23, with
     * `price` at 56 and `final_price` at 37. A reader that knows one spelling silently reports zero
     * for a third of the platform's orders — which is this card's existing failure mode.
     */
    public function test_a_line_is_read_whichever_spelling_the_writer_used(): void
    {
        $cases = [
            'stated final_price wins' => [['final_price' => 90, 'qty' => 3, 'price' => 20], 90.0],
            'total_orignal_price next' => [['total_orignal_price' => 80, 'qty' => 2, 'price' => 20], 80.0],
            'qty x price' => [['qty' => 3, 'price' => 20], 60.0],
            'quantity x price' => [['quantity' => 4, 'price' => 5], 20.0],
            'qty wins over quantity' => [['qty' => 2, 'quantity' => 9, 'price' => 10], 20.0],
            'orignal_price as unit' => [['qty' => 2, 'orignal_price' => 15], 30.0],
            'nothing readable' => [['name' => 'x'], 0.0],
            'negative quantity is floored' => [['qty' => -5, 'price' => 10], 0.0],
            'zero stated total falls through' => [['final_price' => 0, 'qty' => 2, 'price' => 10], 20.0],
        ];

        foreach ($cases as $label => [$line, $expected]) {
            $this->assertSame($expected, TopSellersByRevenue::lineRevenue($line), $label);
        }
    }

    /**
     * THE NEW COMPUTATION DOES NOT READ `orders.product_id` AT ALL, and this proves it rather than
     * asserting it in a comment.
     *
     * The fixture writes a faithful comma-separated list; here it is corrupted into something the
     * old `SUBSTRING_INDEX` parse would have mangled, and the figures must be unchanged. If a future
     * change reintroduces a dependency on that column, this bites.
     */
    public function test_the_figures_do_not_depend_on_the_comma_separated_product_id(): void
    {
        $c = $this->category('Lamps');
        $order = $this->order([[$this->product($c), 2, 25.0]]);

        $before = $this->totalFor(
            TopSellersByRevenue::compute(TopSellersByRevenue::CATEGORY, $this->store->id), 'Lamps'
        );

        DB::table('orders')->where('id', $order->id)->update(['product_id' => '9999,8888,,,7777']);
        (new Order)->flushCache();

        $after = $this->totalFor(
            TopSellersByRevenue::compute(TopSellersByRevenue::CATEGORY, $this->store->id), 'Lamps'
        );

        $this->assertSame(50.0, $before);
        $this->assertSame($before, $after,
            'the figures moved when `orders.product_id` was corrupted, so the computation is still '
            . 'reading the comma-separated list rather than `product_json`');
    }

    /** An order whose `product_json` cannot be read contributes nothing rather than raising. */
    public function test_an_unreadable_order_is_skipped_not_fatal(): void
    {
        $c = $this->category('Lamps');
        $p = $this->product($c);
        $this->order([[$p, 1, 10.0]]);

        $broken = $this->order([[$p, 1, 10.0]]);
        DB::table('orders')->where('id', $broken->id)->update(['product_json' => 'not json']);
        (new Order)->flushCache();

        $rows = TopSellersByRevenue::compute(TopSellersByRevenue::CATEGORY, $this->store->id);

        $this->assertSame(10.0, $this->totalFor($rows, 'Lamps'));
    }

    /**
     * THE CARD ITSELF NO LONGER DIVIDES BY 100. Asserted on the view source rather than on a
     * rendered page, because the divisor is the thing that was defended twice and re-added once,
     * and a source assertion is what stops a third round.
     */
    public function test_the_card_does_not_rescale_the_figure(): void
    {
        $view = file_get_contents(base_path('resources/views/order/brand_category_chart.blade.php'));

        $this->assertStringNotContainsString('/ 100', $view,
            'the `/100` fan-out compensation is back, but the cross join it compensated for is gone, '
            . 'so every figure on both cards now reads a hundredth of the truth');
        $this->assertStringContainsString('currency_format_with_sym(($sale->total_sale ?? 0)', $view,
            'the figure is no longer printed as-is');
    }

    /**
     * AND THE OLD QUERY SHAPE IS GONE FROM THE CONTROLLER, so a merge cannot quietly restore the
     * twelve cross joins while the view's divisor stays removed — which would be the worst of both.
     */
    public function test_the_cross_join_query_shape_is_gone(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/HomeController.php'));

        $this->assertStringNotContainsString('SUBSTRING_INDEX', $controller,
            'the fan-out query is back in HomeController');
        $this->assertStringContainsString('TopSellersByRevenue', $controller,
            'the controller no longer uses the corrected computation');
    }
}
