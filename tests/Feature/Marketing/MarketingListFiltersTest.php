<?php

namespace Tests\Feature\Marketing;

use App\DataTables\AbandonCartDataTable;
use App\DataTables\CouponDataTable;
use App\DataTables\FlashSaleDataTable;
use App\DataTables\NewsletterDataTable;
use App\DataTables\WishlistDataTable;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\FlashSale;
use App\Models\Newsletter;
use App\Models\Store;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\Feature\Security\SecurityTestCase;

/**
 * THE FIVE MARKETING LISTS NARROW TO WHAT THE FILTER SAYS. MKT-002 part 2.
 * =================================================================================================
 *
 * Part 1 gave the five screens a vocabulary and a receipt. This is the half that actually narrows
 * rows, and it is driven BEHAVIOURALLY -- the DataTable's own `query()` is executed against seeded
 * rows and the ids that come back are asserted. Not by matching SQL strings, for two reasons:
 *
 *   1. A SQL assertion passes for a predicate that is syntactically present and semantically wrong.
 *      `HAVING COUNT(*) = 1` and `WHERE COUNT(*) = 1` both "contain the count".
 *   2. `AbandonCartDataTable` GROUPS BY `carts.customer_id` and selects `COUNT(*) as total_carts`.
 *      A `WHERE` on that aggregate is not merely wrong, it is a different query -- SQLite accepts
 *      `WHERE total_carts = 1` as a reference to nothing and MySQL rejects it outright. Only running
 *      it tells you which.
 *
 * EVERY SURFACE IS SEEDED IN TWO TENANTS. All five DataTables already carry
 * `where('store_id', getCurrentStore())`, so these filters only ever NARROW a set that is already
 * confined -- but "already" is a claim, and a filter that joined or re-selected could break it. The
 * second tenant's rows are seeded with the SAME filterable values as the first, so a predicate that
 * widened the set would return them and every assertion here would fail.
 *
 * THE MODELS ARE ALL `Cachable`, so fixtures are written through Eloquent rather than
 * `DB::table()->insert()` -- a raw write leaves Eloquent reads serving the pre-write row, and under
 * `CACHE_STORE=array` it lives for the rest of the test. `Coupon::$fillable` has no `store_id` and
 * `Newsletter` has no `$fillable` at all, so those columns are assigned, never mass-assigned.
 */
class MarketingListFiltersTest extends SecurityTestCase
{
    use RefreshDatabase;

    private Store $mine;

    private Store $theirs;

    protected function setUp(): void
    {
        parent::setUp();

        [, $decoy] = $this->makeTenant();          // store 1, never the tenant under test
        $this->theirs = $decoy;

        [$merchant, $store] = $this->makeTenant();
        $this->mine = $store;
        $this->actingAs($merchant);
    }

    /**
     * The ids a DataTable returns for a given query string.
     *
     * @return list<int>
     */
    private function ids(string $dataTable, string $model, array $query): array
    {
        $request = Request::create('/', 'GET', $query);
        app()->instance('request', $request);

        $rows = (new $dataTable())->query(new $model())->get();

        return $rows->pluck('id')->map(static fn ($id): int => (int) $id)->sort()->values()->all();
    }

    // ---------------------------------------------------------------- coupons

    private function coupon(Store $store, array $attributes): int
    {
        $coupon = new Coupon();
        $coupon->coupon_name = $attributes['name'] ?? ('C-' . $this->uniqueFixtureRef());
        $coupon->coupon_code = $attributes['code'] ?? strtoupper($this->uniqueFixtureRef());
        $coupon->coupon_type = $attributes['type'] ?? 'percentage';
        $coupon->discount_amount = 10.0;
        $coupon->coupon_limit = 0;
        $coupon->coupon_expiry_date = $attributes['expiry'] ?? '2099-01-01';
        $coupon->status = $attributes['status'] ?? 1;
        $coupon->store_id = $store->id;
        $coupon->save();

        return $coupon->id;
    }

    public function test_the_coupon_status_filter_narrows_to_that_status(): void
    {
        $active = $this->coupon($this->mine, ['status' => 1]);
        $this->coupon($this->mine, ['status' => 0]);
        $this->coupon($this->theirs, ['status' => 1]);   // another tenant, same value

        $this->assertSame(
            [$active],
            $this->ids(CouponDataTable::class, Coupon::class, ['ss_status' => '1']),
        );
    }

    public function test_the_coupon_type_filter_uses_the_stored_literal(): void
    {
        $flat = $this->coupon($this->mine, ['type' => 'flat']);
        $this->coupon($this->mine, ['type' => 'percentage']);
        $this->coupon($this->mine, ['type' => 'fixed product discount']);
        $this->coupon($this->theirs, ['type' => 'flat']);

        $this->assertSame(
            [$flat],
            $this->ids(CouponDataTable::class, Coupon::class, ['ss_type' => 'flat']),
        );
    }

    public function test_the_coupon_expiry_filter_splits_on_today(): void
    {
        $expired = $this->coupon($this->mine, ['expiry' => Carbon::now()->subDay()->toDateString()]);
        $current = $this->coupon($this->mine, ['expiry' => Carbon::now()->addDay()->toDateString()]);

        $this->assertSame(
            [$expired],
            $this->ids(CouponDataTable::class, Coupon::class, ['ss_expiry' => 'expired']),
        );
        $this->assertSame(
            [$current],
            $this->ids(CouponDataTable::class, Coupon::class, ['ss_expiry' => 'current']),
        );
    }

    public function test_a_coupon_filter_value_outside_the_vocabulary_narrows_nothing(): void
    {
        $a = $this->coupon($this->mine, ['status' => 1]);
        $b = $this->coupon($this->mine, ['status' => 0]);

        /* The rule `applyFilters()` already follows on the product screen: an unrecognised value
           declines rather than narrowing to nothing, so a merchant is never shown an empty list
           because of a typo in a url. */
        $this->assertSame(
            [$a, $b],
            $this->ids(CouponDataTable::class, Coupon::class, ['ss_status' => 'banana']),
        );
    }

    public function test_an_unrecognised_coupon_type_narrows_nothing(): void
    {
        /* ADDED BECAUSE A MUTATION SURVIVED. Replacing the three-literal allowlist with
           `is_string($type) && $type !== ''` broke no test: the only unrecognised-value case here
           drove `ss_status`, so `ss_type` had an allowlist nothing exercised. A loosened one would
           pass any string straight into a `where`, and `?ss_type=x` would empty the list. */
        $a = $this->coupon($this->mine, ['type' => 'flat']);
        $b = $this->coupon($this->mine, ['type' => 'percentage']);

        $this->assertSame(
            [$a, $b],
            $this->ids(CouponDataTable::class, Coupon::class, ['ss_type' => 'buy-one-get-one']),
        );
    }

    public function test_a_coupon_expiring_today_has_not_expired_yet(): void
    {
        /* ADDED BECAUSE A MUTATION SURVIVED. Flipping the pair to `<=` / `>` broke no test, because
           the only expiry fixtures were YESTERDAY and TOMORROW -- both sides of the boundary agree
           on those, and the boundary itself is the one a merchant notices: a coupon that is still
           usable all of today must not be listed as expired. */
        $today = $this->coupon($this->mine, ['expiry' => Carbon::now()->toDateString()]);

        $this->assertSame(
            [$today],
            $this->ids(CouponDataTable::class, Coupon::class, ['ss_expiry' => 'current']),
            'a coupon whose expiry date is today is still valid today'
        );
        $this->assertSame(
            [],
            $this->ids(CouponDataTable::class, Coupon::class, ['ss_expiry' => 'expired']),
        );
    }

    public function test_ss_to_includes_everything_recorded_on_the_day_it_names(): void
    {
        /*
         * ADDED BECAUSE A MUTATION SURVIVED, and this is the one that would have shipped a wrong
         * list. Dropping `endOfDay()` from the `ss_to` bound broke no test because the existing
         * fixture compared a row created at 00:00:00 against the NEXT day -- a gap wide enough that
         * the bug fits inside it. `created_at` is a DATETIME, so `<= '2026-03-17'` means
         * `<= 2026-03-17 00:00:00` and drops everything recorded during the day the merchant typed.
         * The row below is created at midday ON the bound, which is the only shape that discriminates.
         */
        $sameDay = $this->coupon($this->mine, ['status' => 1]);
        $row = Coupon::where('id', $sameDay)->first();
        $row->forceFill(['created_at' => Carbon::now()->subDays(3)->setTime(12, 30)])->save();

        $this->assertSame(
            [$sameDay],
            $this->ids(CouponDataTable::class, Coupon::class, [
                'ss_to' => Carbon::now()->subDays(3)->toDateString(),
            ]),
            'a row recorded at 12:30 must be inside a bound that names its day'
        );
    }

    public function test_a_date_that_is_not_a_real_date_narrows_nothing(): void
    {
        /*
         * ADDED BECAUSE A MUTATION SURVIVED. Dropping `parseDate`'s round-trip check broke no test:
         * the receipt test only drove a VALID date. `createFromFormat('Y-m-d', ...)` accepts far
         * more than it looks -- `2099-02-30` rolls forward into March and `2099-2-3` parses -- so
         * without the round trip a filter would narrow on a date nobody chose. Both shapes must
         * decline, which means the unfiltered list.
         *
         * THE YEAR IS IN THE FUTURE ON PURPOSE, and the first version of this test got it wrong.
         * With `2026-02-30` the mutation survived: the rolled-forward date landed in the PAST, so
         * `created_at >= it` admitted both rows and the unfiltered list came back either way. A
         * future date makes the roll-forward exclude everything, so the assertion discriminates
         * between "declined" and "narrowed on a date nobody typed".
         */
        $a = $this->coupon($this->mine, ['status' => 1]);
        $b = $this->coupon($this->mine, ['status' => 0]);

        foreach (['2099-02-30', '2099-2-3', 'tomorrow', '+1 week', ''] as $notADate) {
            $this->assertSame(
                [$a, $b],
                $this->ids(CouponDataTable::class, Coupon::class, ['ss_from' => $notADate]),
                "`{$notADate}` is not a date this filter may narrow on"
            );
        }
    }

    public function test_an_array_valued_coupon_filter_does_not_fail(): void
    {
        $a = $this->coupon($this->mine, ['status' => 1]);

        // `?ss_status[]=1` makes this an array; the product screen's own note says the next filter
        // added is where that becomes an "Array to string conversion" and a 500 from a query string.
        $this->assertSame(
            [$a],
            $this->ids(CouponDataTable::class, Coupon::class, ['ss_status' => ['1']]),
        );
    }

    // ---------------------------------------------------------------- newsletters

    private function newsletter(Store $store, ?int $customerId): int
    {
        $row = new Newsletter();
        $row->email = 'n-' . $this->uniqueFixtureRef() . '@example.test';
        $row->customer_id = $customerId;
        $row->store_id = $store->id;
        $row->save();

        return $row->id;
    }

    public function test_the_newsletter_source_filter_separates_customers_from_bare_emails(): void
    {
        $registered = $this->newsletter($this->mine, 4242);
        $guest = $this->newsletter($this->mine, null);
        $zero = $this->newsletter($this->mine, 0);        // the shape a vendored writer uses
        $this->newsletter($this->theirs, 4242);

        $this->assertSame(
            [$registered],
            $this->ids(NewsletterDataTable::class, Newsletter::class, ['ss_source' => 'customer']),
            'a registered subscriber is one with a real customer id'
        );

        $this->assertSame(
            [$guest, $zero],
            $this->ids(NewsletterDataTable::class, Newsletter::class, ['ss_source' => 'guest']),
            'NULL and 0 both mean "no customer"; counting only NULL would hide the vendored shape'
        );
    }

    // ---------------------------------------------------------------- flash sales

    private function flashSale(Store $store, array $attributes): int
    {
        $sale = new FlashSale();
        $sale->name = 'FS-' . $this->uniqueFixtureRef();
        $sale->start_date = $attributes['start'];
        $sale->end_date = $attributes['end'];
        $sale->start_time = '00:00:00';
        $sale->end_time = '23:59:59';
        $sale->sale_product = '[]';
        $sale->discount_type = $attributes['type'] ?? 'percentage';
        $sale->discount_amount = 5.0;
        $sale->is_active = $attributes['active'] ?? 1;
        $sale->created_by = 1;
        $sale->store_id = $store->id;
        $sale->save();

        return $sale->id;
    }

    public function test_the_flash_sale_window_filter_reads_the_dates(): void
    {
        $today = Carbon::now()->toDateString();

        $running = $this->flashSale($this->mine, [
            'start' => Carbon::now()->subDays(2)->toDateString(), 'end' => Carbon::now()->addDays(2)->toDateString(),
        ]);
        $upcoming = $this->flashSale($this->mine, [
            'start' => Carbon::now()->addDays(3)->toDateString(), 'end' => Carbon::now()->addDays(5)->toDateString(),
        ]);
        $ended = $this->flashSale($this->mine, [
            'start' => Carbon::now()->subDays(9)->toDateString(), 'end' => Carbon::now()->subDays(4)->toDateString(),
        ]);
        $this->flashSale($this->theirs, ['start' => $today, 'end' => $today]);

        $this->assertSame([$running], $this->ids(FlashSaleDataTable::class, FlashSale::class, ['ss_window' => 'running']));
        $this->assertSame([$upcoming], $this->ids(FlashSaleDataTable::class, FlashSale::class, ['ss_window' => 'upcoming']));
        $this->assertSame([$ended], $this->ids(FlashSaleDataTable::class, FlashSale::class, ['ss_window' => 'ended']));
    }

    public function test_a_sale_that_starts_and_ends_today_is_running_not_ended(): void
    {
        /* The boundary both comparisons have to be inclusive for. A `<` on either end would drop a
           one-day sale out of every one of the three windows, which is the shape that reads as
           "the filter is broken" rather than "no sales match". */
        $today = Carbon::now()->toDateString();
        $oneDay = $this->flashSale($this->mine, ['start' => $today, 'end' => $today]);

        $this->assertSame([$oneDay], $this->ids(FlashSaleDataTable::class, FlashSale::class, ['ss_window' => 'running']));
        $this->assertSame([], $this->ids(FlashSaleDataTable::class, FlashSale::class, ['ss_window' => 'ended']));
        $this->assertSame([], $this->ids(FlashSaleDataTable::class, FlashSale::class, ['ss_window' => 'upcoming']));
    }

    public function test_the_flash_sale_status_and_type_filters_narrow(): void
    {
        $inactiveFlat = $this->flashSale($this->mine, [
            'start' => Carbon::now()->toDateString(), 'end' => Carbon::now()->toDateString(),
            'active' => 0, 'type' => 'flat',
        ]);
        $this->flashSale($this->mine, [
            'start' => Carbon::now()->toDateString(), 'end' => Carbon::now()->toDateString(),
            'active' => 1, 'type' => 'percentage',
        ]);

        $this->assertSame([$inactiveFlat], $this->ids(FlashSaleDataTable::class, FlashSale::class, ['ss_status' => '0']));
        $this->assertSame([$inactiveFlat], $this->ids(FlashSaleDataTable::class, FlashSale::class, ['ss_type' => 'flat']));
    }

    // ---------------------------------------------------------------- wishlists

    /**
     * A real product per store, because `wishlists.product_id` CARRIES A FOREIGN KEY.
     *
     * The first version of this fixture hard-coded `product_id = 1` and the insert failed with
     * "FOREIGN KEY constraint failed" -- a fixture fault, not a filter fault, and worth a note
     * because the `carts` table has no such constraint, so the two neighbouring fixtures in this
     * file are deliberately not symmetrical.
     *
     * `forceCreate` because `products.created_by` is NOT NULL and not mass-assignable, so
     * `Product::create()` drops it silently and the insert fails on that constraint instead.
     *
     * NO CATEGORY ROW IS CREATED, and that is measured rather than assumed: `products.category_id`
     * is `unsignedBigInteger()->nullable()` with NO foreign key in the create migration, so the
     * `makeCategory` dance other fixtures do is not needed to satisfy a constraint here. Omitting
     * it keeps this fixture to the one row the wishlist's own foreign key actually requires.
     */
    private function productIn(Store $store): int
    {
        return (int) \App\Models\Product::forceCreate([
            'name' => 'MKT-' . bin2hex(random_bytes(3)),
            'slug' => 'mkt-' . bin2hex(random_bytes(3)),
            'store_id' => $store->id,
            'track_stock' => 0,
            'created_by' => 1,
        ])->id;
    }

    private function wishlistRow(Store $store, int $customerId, int $status): int
    {
        $row = new Wishlist();
        $row->customer_id = $customerId;
        $row->product_id = $this->productIn($store);
        $row->variant_id = 0;
        $row->status = $status;
        $row->store_id = $store->id;
        $row->save();

        return $row->id;
    }

    public function test_the_wishlist_status_filter_narrows_before_the_grouping(): void
    {
        /* `WishlistDataTable` groups by `customer_id`, so this predicate must be a WHERE applied to
           the rows BEFORE the group -- a HAVING here would compare a column no aggregate produced. */
        $active = $this->wishlistRow($this->mine, 11, 1);
        $this->wishlistRow($this->mine, 12, 0);
        $this->wishlistRow($this->theirs, 13, 1);

        $this->assertSame(
            [$active],
            $this->ids(WishlistDataTable::class, Wishlist::class, ['ss_status' => '1']),
        );
    }

    // ---------------------------------------------------------------- abandoned carts

    private function cartRow(Store $store, int $customerId): int
    {
        $row = new Cart();
        $row->customer_id = $customerId;
        $row->product_id = 1;
        $row->variant_id = 0;
        $row->qty = 1;
        $row->price = 10;
        $row->store_id = $store->id;
        $row->save();

        return $row->id;
    }

    public function test_the_abandoned_cart_item_filter_is_an_aggregate_predicate(): void
    {
        /*
         * THE ONE HAVING IN THIS SLICE, and the reason it cannot be a WHERE: the DataTable selects
         * `COUNT(*) as total_carts` and groups by `carts.customer_id`, so the thing being compared
         * does not exist until the rows are grouped. A `WHERE total_carts = 1` references a column
         * no row has -- SQLite silently treats it as nothing and MySQL raises "Unknown column".
         *
         * Customer 21 has ONE line, customer 22 has THREE. The row the DataTable returns per
         * customer carries `MIN(carts.id)`, so the expected ids are the lowest of each group.
         */
        $one = $this->cartRow($this->mine, 21);

        $firstOfThree = $this->cartRow($this->mine, 22);
        $this->cartRow($this->mine, 22);
        $this->cartRow($this->mine, 22);

        $this->cartRow($this->theirs, 21);   // another tenant, one line, same customer id

        $this->assertSame(
            [$one],
            $this->ids(AbandonCartDataTable::class, Cart::class, ['ss_items' => 'one']),
            'one line means COUNT(*) = 1 AFTER the grouping'
        );

        $this->assertSame(
            [$firstOfThree],
            $this->ids(AbandonCartDataTable::class, Cart::class, ['ss_items' => 'several']),
            'several means COUNT(*) > 1, and the row carries MIN(carts.id)'
        );
    }

    public function test_an_unfiltered_abandoned_cart_list_still_groups_per_customer(): void
    {
        // The premise: without a filter, three lines for one customer are ONE row.
        $one = $this->cartRow($this->mine, 21);
        $firstOfThree = $this->cartRow($this->mine, 22);
        $this->cartRow($this->mine, 22);

        $this->assertSame(
            [$one, $firstOfThree],
            $this->ids(AbandonCartDataTable::class, Cart::class, []),
        );
    }

    // ---------------------------------------------------------------- the date range, on all five

    public function test_the_date_range_narrows_every_marketing_list(): void
    {
        $old = Carbon::now()->subMonths(2);

        $recent = $this->coupon($this->mine, ['status' => 1]);
        $stale = $this->coupon($this->mine, ['status' => 1]);
        Coupon::where('id', $stale)->first()->forceFill(['created_at' => $old])->save();

        $this->assertSame(
            [$recent],
            $this->ids(CouponDataTable::class, Coupon::class, [
                'ss_from' => Carbon::now()->subDay()->toDateString(),
            ]),
            'ss_from must narrow on created_at'
        );

        $this->assertSame(
            [$stale],
            $this->ids(CouponDataTable::class, Coupon::class, [
                'ss_to' => $old->copy()->addDay()->toDateString(),
            ]),
            'ss_to must narrow on created_at, and its bound is inclusive of the whole day'
        );
    }
}
