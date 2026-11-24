<?php

namespace Tests\Feature\Analytics;

use App\Models\Order;
use App\Support\Metrics\MerchantAnalyticsFigures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * ANA-004 / GAP-227(a) — ORDERS BY SALES CHANNEL.
 * =============================================================================================
 *
 * `orders.order_source` has been a per-row label and a filter since P2-04-11 and was **never
 * aggregated anywhere in the product** — measured 2026-11-22 across every analytics surface, the
 * exports, the Flutter admin API and the older `/themean-alytic` screen. This is that aggregation.
 *
 * WHAT EACH TEST IS FOR, because three of them look like the same test:
 *
 *  - the counts are the counts, and they are THIS store's;
 *  - an order with NO source keeps its own row, named as an absence rather than as a category —
 *    `Order`'s own docblock forbids naming it ("NULL is the ABSENCE of a record, not a fourth
 *    category"), and `orderSourceLabel()` returns null for it on purpose;
 *  - a value the vocabulary does not know is SHOWN rather than dropped, so a breakdown cannot
 *    quietly total less than the Orders tile beside it.
 *
 * THE WINDOW IS `order_date`, the same column and bounds `countOrders()` uses, so a test that
 * places an order outside the window is also a test that the two cannot disagree.
 */
class OrdersByChannelTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function orderIn($store, ?string $source, string $when = 'now'): void
    {
        DB::table('orders')->insert([
            'product_order_id' => 'OBC-' . $this->uniqueFixtureRef(),
            'store_id' => $store->id,
            'customer_id' => 0,
            'is_guest' => 1,
            'order_source' => $source,
            'final_price' => 10.00,
            'order_date' => now()->parse($when),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, string> label => count, with the LTR isolates stripped */
    private function breakdown($store): array
    {
        $figures = MerchantAnalyticsFigures::forStore($store->id, $store->currency ?? 'USD');

        $this->assertArrayHasKey('sources', $figures,
            'The figures contract carries no `sources` breakdown, so the screen and the CSV have nothing to render.');

        $out = [];

        foreach ($figures['sources']['rows'] as $row) {
            $out[(string) $row['label']] = trim(preg_replace('/[\x{2066}\x{2069}]/u', '', (string) $row['count']));
        }

        return $out;
    }

    public function test_orders_are_counted_by_the_channel_that_created_them(): void
    {
        [, $store] = $this->makeTenant();

        $this->orderIn($store, Order::SOURCE_STOREFRONT);
        $this->orderIn($store, Order::SOURCE_STOREFRONT);
        $this->orderIn($store, Order::SOURCE_STOREFRONT);
        $this->orderIn($store, Order::SOURCE_POS);

        $rows = $this->breakdown($store);

        $this->assertSame('3', $rows[Order::orderSourceLabel(Order::SOURCE_STOREFRONT)] ?? null);
        $this->assertSame('1', $rows[Order::orderSourceLabel(Order::SOURCE_POS)] ?? null);
        $this->assertArrayNotHasKey(Order::orderSourceLabel(Order::SOURCE_MANUAL), $rows,
            'A channel with no orders was given a zero row rather than left out.');
    }

    /** The largest channel first, so the biggest share is not arbitrary to find. */
    public function test_the_breakdown_is_ordered_by_count_and_not_by_name(): void
    {
        [, $store] = $this->makeTenant();

        // `manual` sorts before `pos` and `storefront` alphabetically, and has the FEWEST orders.
        $this->orderIn($store, Order::SOURCE_MANUAL);
        $this->orderIn($store, Order::SOURCE_POS);
        $this->orderIn($store, Order::SOURCE_POS);

        $labels = array_keys($this->breakdown($store));

        $this->assertSame(Order::orderSourceLabel(Order::SOURCE_POS), $labels[0] ?? null,
            'The breakdown is ordered by label rather than by count, which is the defect the older /themean-alytic screen has.');
    }

    public function test_an_order_with_no_source_keeps_its_own_row_named_as_an_absence(): void
    {
        [, $store] = $this->makeTenant();

        $this->orderIn($store, Order::SOURCE_POS);
        $this->orderIn($store, null);
        $this->orderIn($store, '');

        $rows = $this->breakdown($store);
        $absence = __('Source not recorded (placed before this was tracked)');

        $this->assertSame('2', $rows[$absence] ?? null,
            'NULL and the empty string were counted as two separate absences, so one absence reads as two channels.');

        foreach (array_keys($rows) as $label) {
            $this->assertNotSame(Order::orderSourceLabel(Order::SOURCE_STOREFRONT), $label,
                'An order with no source was presented as a storefront order, which Order::orderSourceLabel() exists to prevent.');
        }
    }

    public function test_a_source_the_vocabulary_does_not_know_is_shown_rather_than_dropped(): void
    {
        [, $store] = $this->makeTenant();

        $this->orderIn($store, Order::SOURCE_POS);
        $this->orderIn($store, 'marketplace');

        $rows = $this->breakdown($store);

        $this->assertSame(2, array_sum(array_map('intval', $rows)),
            'The breakdown totals fewer orders than the store has, so a stored value was silently dropped.');

        $shown = implode(' | ', array_keys($rows));
        $this->assertStringContainsString('marketplace', $shown,
            'An order_source this version has no name for vanished from the breakdown instead of being shown verbatim.');
    }

    public function test_the_breakdown_counts_only_this_store(): void
    {
        [, $mine] = $this->makeTenant();
        [, $theirs] = $this->makeTenant();

        $this->orderIn($mine, Order::SOURCE_POS);
        $this->orderIn($theirs, Order::SOURCE_POS);
        $this->orderIn($theirs, Order::SOURCE_POS);

        $this->assertSame('1', $this->breakdown($mine)[Order::orderSourceLabel(Order::SOURCE_POS)] ?? null,
            'Another store\'s orders were counted into this store\'s channel breakdown. Order carries no StoreScope and is not in config/tenancy.php, so the predicate has to be explicit.');
    }

    public function test_an_order_outside_the_window_is_not_counted(): void
    {
        [, $store] = $this->makeTenant();

        $this->orderIn($store, Order::SOURCE_POS);
        $this->orderIn($store, Order::SOURCE_POS, '-400 days');

        $this->assertSame('1', $this->breakdown($store)[Order::orderSourceLabel(Order::SOURCE_POS)] ?? null,
            'The breakdown is not windowed on order_date, so it disagrees with the Orders tile above it.');
    }

    /**
     * THE HINT MUST REFUSE THE DIVISION THE LAYOUT INVITES. This table sits beside two PAGE-VIEW
     * breakdowns, and the 2026-11-22 measurement established there is no link of any kind between
     * a visit and an order — so a per-channel conversion rate is not derivable, and a merchant who
     * divides one table by the other gets a number that means nothing.
     */
    public function test_the_hint_says_these_are_orders_and_cannot_be_divided_by_page_views(): void
    {
        [, $store] = $this->makeTenant();
        $this->orderIn($store, Order::SOURCE_POS);

        $hint = MerchantAnalyticsFigures::forStore($store->id, $store->currency ?? 'USD')['sources']['hint'];

        $this->assertStringContainsString('ORDERS, not visits', $hint);
        $this->assertStringContainsString('cannot be divided', $hint);
    }

    public function test_a_store_with_no_orders_is_told_so_rather_than_shown_an_empty_table(): void
    {
        [, $store] = $this->makeTenant();

        $figures = MerchantAnalyticsFigures::forStore($store->id, $store->currency ?? 'USD');

        $this->assertSame([], $figures['sources']['rows']);
        $this->assertStringContainsString('Nothing is estimated', $figures['sources']['hint']);
    }
}
