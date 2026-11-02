<?php

namespace Tests\Feature\DesignSystem;

use App\Http\Controllers\HomeController;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P2-05-6 — the Order Status Report counted seven of the nine statuses.
 * =============================================================================================
 *
 * `delivered_status` carries nine values; `HomeController` names all nine as constants, and
 * `getOrderCounts()` already learned this lesson for the dashboard — it used to count six of
 * nine and gained an `other` catch-all so the parts ALWAYS sum to the total. This report had the
 * same defect, independently: a fresh literal map `0 => 'Pending' … 6 => 'Shipped'`, with
 * `ORDER_STATUS_PARTIALLY_PAID` (7) and `ORDER_STATUS_PRE_ORDER` (8) simply absent.
 *
 * For a store that uses either, all three charts showed a breakdown whose parts did not sum to
 * the store's order count, and **nothing on the screen said so**. There was no total to compare
 * against, which is exactly what made it invisible — so the total is now on the card, and this
 * test asserts the identity rather than the individual numbers.
 *
 * SUM == TOTAL IS THE ASSERTION THAT CANNOT BE SATISFIED BY ACCIDENT. A test that checked "the
 * Pre Order row exists" would pass against a map that lists it and counts it wrong. A test that
 * checked nine rows would pass against nine zeroes.
 */
class OrderStatusReportHonestyTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = User::factory()->create([
            'type' => 'admin', 'created_by' => 1, 'is_active' => 1, 'email_verified_at' => now(),
        ]);
        $this->store = Store::create([
            'name' => 'Status Store', 'email' => 'status@example.test',
            'slug' => 'status-store-' . uniqid(), 'theme_id' => 'stylique',
            'created_by' => $this->merchant->id, 'is_active' => 1, 'default_language' => 'en',
        ]);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();
        $this->merchant = $this->merchant->fresh();
    }

    private function order(int $status, ?int $storeId = null): void
    {
        Order::create([
            'order_id' => 'S' . uniqid(),
            'store_id' => $storeId ?? $this->store->id,
            'delivered_status' => $status,
            'order_date' => now(),
            'price' => 10,
            'customer_id' => 0,
            'is_guest' => 0,
        ]);
    }

    private function page(): string
    {
        return $this->actingAs($this->merchant)->get('/Status-reports')->assertOk()->getContent();
    }

    /**
     * The numbers in the chart's table body, keyed by their row header.
     *
     * SCOPED TO `<tbody>` ON PURPOSE. The component's `<tfoot>` total is also a
     * `<th scope="row">` followed by a numeric `<td>`, so a whole-table scan matches it too and
     * every sum comes out at exactly double. That cost three failing assertions here, and it is
     * the third time in this phase that the `<tfoot>` has been counted as data — the first two
     * were in `BarChartComponentTest`.
     */
    private function rows(string $html): array
    {
        if (preg_match('/<tbody>(.*?)<\/tbody>/s', $html, $body) !== 1) {
            return [];
        }

        preg_match_all(
            '/<th scope="row">([^<]*)<\/th>\s*<td class="ss-table__num ss-num">([\d,]+)<\/td>/s',
            $body[1], $m, PREG_SET_ORDER
        );

        $out = [];
        foreach ($m as $row) {
            $out[trim($row[1])] = (int) str_replace(',', '', $row[2]);
        }

        return $out;
    }

    /**
     * THE DEFECT, stated as an identity. Two orders in statuses the old map did not know about,
     * among seven it did.
     */
    public function test_the_parts_sum_to_the_total(): void
    {
        foreach ([0, 0, 1, 2, 3, 4, 5, 6] as $status) {
            $this->order($status);
        }
        $this->order(HomeController::ORDER_STATUS_PARTIALLY_PAID);
        $this->order(HomeController::ORDER_STATUS_PRE_ORDER);

        $html = $this->page();
        $rows = $this->rows($html);

        $this->assertNotSame([], $rows, 'Premise: the report rendered no rows at all.');

        /* The table's own <tfoot> total, which the component sums from the rows it drew. */
        $this->assertStringContainsString('>10</td>', $html,
            'the chart table totals ' . array_sum($rows) . ', not the 10 orders that exist — '
            . 'statuses the map does not know about are being dropped. Rows: ' . json_encode($rows));

        $this->assertSame(10, array_sum($rows),
            'the status breakdown sums to ' . array_sum($rows) . ' over 10 orders. Rows: '
            . json_encode($rows));

        /* And the card states the total independently, so a reader can check the same identity
           without opening the table. */
        $this->assertStringContainsString('Total orders', $html);
    }

    public function test_the_two_statuses_that_were_missing_are_counted(): void
    {
        $this->order(HomeController::ORDER_STATUS_PARTIALLY_PAID);
        $this->order(HomeController::ORDER_STATUS_PARTIALLY_PAID);
        $this->order(HomeController::ORDER_STATUS_PRE_ORDER);

        $rows = $this->rows($this->page());

        $this->assertArrayHasKey('Partially Paid', $rows);
        $this->assertSame(2, $rows['Partially Paid']);
        $this->assertArrayHasKey('Pre Order', $rows);
        $this->assertSame(1, $rows['Pre Order']);
    }

    /**
     * A VALUE OUTSIDE 0-8 STAYS VISIBLE. The same catch-all `getOrderCounts()` has: if a tenth
     * value is ever written, it must land in `Other` rather than disappear, because a silently
     * short breakdown is the failure this whole file is about.
     */
    public function test_an_unknown_status_lands_in_other_rather_than_vanishing(): void
    {
        $this->order(1);
        $this->order(42);

        $rows = $this->rows($this->page());

        $this->assertArrayHasKey('Other', $rows, 'an unrecognised status vanished from the report');
        $this->assertSame(1, $rows['Other']);
        $this->assertSame(2, array_sum($rows));
    }

    /** A healthy store does not carry an empty catch-all row. */
    public function test_other_is_absent_when_every_status_is_known(): void
    {
        $this->order(1);

        $this->assertArrayNotHasKey('Other', $this->rows($this->page()));
    }

    /**
     * PRD-001. The query has NO date filter — it is every order the store has ever taken — and
     * the screen used to read as though it were current.
     */
    public function test_the_report_says_it_covers_all_time(): void
    {
        $this->order(1);

        $html = $this->page();

        $this->assertStringContainsString('All time', $html,
            'the report filters by no date at all, so it must say so');
    }

    /** The counts are this store's, not the platform's. */
    public function test_another_stores_orders_are_not_counted(): void
    {
        $other = User::factory()->create(['type' => 'admin', 'created_by' => 1, 'is_active' => 1, 'email_verified_at' => now()]);
        $otherStore = Store::create([
            'name' => 'Other', 'email' => 'other@example.test', 'slug' => 'other-' . uniqid(),
            'theme_id' => 'stylique', 'created_by' => $other->id, 'is_active' => 1, 'default_language' => 'en',
        ]);

        $this->order(1);
        $this->order(1, $otherStore->id);
        $this->order(2, $otherStore->id);

        $this->assertSame(1, array_sum($this->rows($this->page())),
            "another store's orders are in this store's report");
    }

    /**
     * The three ApexCharts had no accessible representation of any kind, and the AREA chart drew
     * categories as a continuous quantity — a line sloping from "Cancel" to "Return" claims
     * there is something between them.
     */
    public function test_the_numbers_are_reachable_and_the_picture_is_not_announced(): void
    {
        $this->order(1);

        $html = $this->page();

        $this->assertStringContainsString('<div class="ss-chart" aria-hidden="true">', $html);
        $this->assertStringContainsString('<caption', $html);
        $this->assertStringContainsString('<th scope="col">Status</th>', $html,
            'the column header must name what the rows are, not default to "Day"');
        $this->assertStringNotContainsString("type: 'area'", $html,
            'the area chart drew categorical data as a continuous quantity');
        $this->assertSame(1, substr_count($html, 'ss-card__title'),
            'three cards carried the identical heading "Order Status Report"; one dataset is one card');
    }
}
