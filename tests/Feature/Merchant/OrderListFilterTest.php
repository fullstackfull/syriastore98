<?php

namespace Tests\Feature\Merchant;

use App\Http\Controllers\HomeController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2-04-8 -- the order list can be filtered, and a filter can only NARROW it.
 * =============================================================================================
 *
 * THE SAME SHAPE AS `ProductDataTable::applyFilters()`, deliberately. Two lists with two idioms
 * for "read a filter from request input into a query" is how they drift, and this one carries a
 * date range, which is the half with the sharp edges.
 *
 * THE STATUS OPTIONS COME FROM `HomeController`'s CONSTANTS, not from a literal list here.
 * `orders.delivered_status` holds NINE values and **7 and 8 are not fulfilment states** -- 7 is
 * Partially Paid, a payment state, and 8 is Pre Order, an order type, both sharing the column
 * (ledger F0). The dashboard was given an honesty pass that names them and the Flutter app now
 * does too; a filter that silently offered only 0..6 would quietly hide those orders from the
 * one screen a merchant uses to find them.
 *
 * THE DATE RANGE IS THE PART WORTH TESTING HARDEST:
 *   - an end date must be INCLUSIVE, or an order placed on the day the merchant typed is missing
 *     and nothing says so;
 *   - `order_date` is a datetime, so a naive `<= '2026-03-17'` compares against midnight and
 *     drops that whole day -- the classic off-by-one-day, and it is silent;
 *   - a reversed range (from after to) must return nothing rather than everything;
 *   - garbage must be ignored, not 500 and not widen.
 */
class OrderListFilterTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];

    /** The order references the list served, for the given filter query. */
    private function references($user, array $filters = []): array
    {
        $query = array_merge($filters, ['draw' => 1, 'start' => 0, 'length' => 100]);

        foreach (['order_id', 'price'] as $i => $column) {
            $query["columns[{$i}][data]"] = $column;
            $query["columns[{$i}][name]"] = $column;
            $query["columns[{$i}][searchable]"] = 'true';
            $query["columns[{$i}][orderable]"] = 'true';
            $query["columns[{$i}][search][value]"] = '';
            $query["columns[{$i}][search][regex]"] = 'false';
        }

        $query['order[0][column]'] = 0;
        $query['order[0][dir]'] = 'asc';
        $query['search[value]'] = '';
        $query['search[regex]'] = 'false';

        $response = $this->actingAs($user)
            ->withHeaders(self::AJAX)
            ->getJson('/order?' . http_build_query($query));

        $this->assertNotSame(404, $response->getStatusCode(), 'the order route must exist');
        $this->assertNotSame(500, $response->getStatusCode(), 'a filter must not 500 the list');

        $payload = $response->json();

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('draw', $payload, 'premise: yajra served the AJAX leg');

        $refs = [];

        foreach ($payload['data'] ?? [] as $row) {
            $refs[] = (string) ($row['product_order_id'] ?? '');
        }

        return $refs;
    }

    /** True when the list served an order whose reference contains `$needle`. */
    private function served(array $refs, string $needle): bool
    {
        foreach ($refs as $ref) {
            if (str_contains($ref, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function makeCatalogue(): array
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order');

        $this->makeOrder($store, [
            'product_order_id' => 'ORD-PENDING',
            'delivered_status' => HomeController::ORDER_STATUS_PENDING,
            'payment_status' => 'Unpaid',
            'order_date' => '2026-03-01 10:00:00',
        ]);
        $this->makeOrder($store, [
            'product_order_id' => 'ORD-DELIVERED',
            'delivered_status' => HomeController::ORDER_STATUS_DELIVERED,
            'payment_status' => 'Paid',
            'order_date' => '2026-03-17 23:45:00',
        ]);
        $this->makeOrder($store, [
            'product_order_id' => 'ORD-PREORDER',
            'delivered_status' => HomeController::ORDER_STATUS_PRE_ORDER,
            'payment_status' => 'Partially Paid',
            'order_date' => '2026-04-02 08:00:00',
        ]);

        return [$merchant, $store];
    }

    // =========================================================================================
    // The premise
    // =========================================================================================

    public function test_with_no_filter_the_list_serves_every_order(): void
    {
        [$merchant] = $this->makeCatalogue();

        $this->assertCount(3, $this->references($merchant),
            'premise: the unfiltered list serves all three, so every exclusion below is the '
            . 'filter doing it');
    }

    // =========================================================================================
    // Status and payment
    // =========================================================================================

    public function test_the_status_filter_narrows_to_one_fulfilment_state(): void
    {
        [$merchant] = $this->makeCatalogue();

        $refs = $this->references($merchant,
            ['ss_status' => (string) HomeController::ORDER_STATUS_DELIVERED]);

        $this->assertTrue($this->served($refs, 'ORD-DELIVERED'));
        $this->assertFalse($this->served($refs, 'ORD-PENDING'));
    }

    /**
     * AND 7 AND 8 ARE FILTERABLE, which is the case that matters.
     *
     * They are not fulfilment states -- 7 is a payment state, 8 an order type -- but they ARE
     * values the column holds, and a filter offering only 0..6 would hide those orders from the
     * screen a merchant uses to find them. The dashboard names them; so does this.
     */
    public function test_the_two_non_fulfilment_values_are_filterable(): void
    {
        [$merchant] = $this->makeCatalogue();

        $refs = $this->references($merchant,
            ['ss_status' => (string) HomeController::ORDER_STATUS_PRE_ORDER]);

        $this->assertTrue($this->served($refs, 'ORD-PREORDER'),
            'a Pre Order cannot be filtered for, so those orders are unreachable from the list');
        $this->assertFalse($this->served($refs, 'ORD-DELIVERED'));
    }

    public function test_the_payment_filter_narrows_to_one_payment_state(): void
    {
        [$merchant] = $this->makeCatalogue();

        $refs = $this->references($merchant, ['ss_payment' => 'Paid']);

        $this->assertTrue($this->served($refs, 'ORD-DELIVERED'));
        $this->assertFalse($this->served($refs, 'ORD-PENDING'));

        // "Partially Paid" must not be caught by a loose match on "Paid" -- a `LIKE` here would
        // quietly merge two different financial states.
        $this->assertFalse($this->served($refs, 'ORD-PREORDER'),
            'the payment filter matched "Partially Paid" when asked for "Paid"');
    }

    // =========================================================================================
    // The date range -- where the sharp edges are
    // =========================================================================================

    /**
     * THE END DATE IS INCLUSIVE, AND IT INCLUDES THE WHOLE DAY.
     *
     * `order_date` is a datetime. A naive `<= '2026-03-17'` compares against midnight at the
     * START of that day, so an order placed at 23:45 on the date the merchant typed is missing
     * from their own range -- silently, with nothing on screen to suggest it.
     */
    public function test_the_end_date_includes_the_whole_of_that_day(): void
    {
        [$merchant] = $this->makeCatalogue();

        $refs = $this->references($merchant,
            ['ss_from' => '2026-03-01', 'ss_to' => '2026-03-17']);

        $this->assertTrue($this->served($refs, 'ORD-DELIVERED'),
            'an order placed at 23:45 on the END date was excluded -- the range compares '
            . 'against midnight instead of the end of the day');
        $this->assertTrue($this->served($refs, 'ORD-PENDING'));
        $this->assertFalse($this->served($refs, 'ORD-PREORDER'));
    }

    public function test_the_start_date_is_inclusive_too(): void
    {
        [$merchant] = $this->makeCatalogue();

        $refs = $this->references($merchant, ['ss_from' => '2026-03-01']);

        $this->assertTrue($this->served($refs, 'ORD-PENDING'),
            'an order placed on the START date was excluded');
    }

    public function test_either_bound_works_on_its_own(): void
    {
        [$merchant] = $this->makeCatalogue();

        $fromOnly = $this->references($merchant, ['ss_from' => '2026-04-01']);
        $this->assertTrue($this->served($fromOnly, 'ORD-PREORDER'));
        $this->assertFalse($this->served($fromOnly, 'ORD-PENDING'));

        $toOnly = $this->references($merchant, ['ss_to' => '2026-03-02']);
        $this->assertTrue($this->served($toOnly, 'ORD-PENDING'));
        $this->assertFalse($this->served($toOnly, 'ORD-PREORDER'));
    }

    /** A reversed range returns nothing, rather than being quietly ignored. */
    public function test_a_reversed_range_returns_nothing(): void
    {
        [$merchant] = $this->makeCatalogue();

        $this->assertSame([], $this->references($merchant,
            ['ss_from' => '2026-05-01', 'ss_to' => '2026-01-01']),
            'a from-after-to range returned rows, so one of the two bounds was dropped');
    }

    /**
     * GARBAGE IN A DATE FIELD IS IGNORED, not an error.
     *
     * `Carbon::parse` throws on nonsense, and an unhandled throw here is a 500 on the order
     * list from a query string. It is also lenient in surprising ways -- `'0'` and `'now'` parse
     * -- so the parse is anchored to an explicit format rather than left open.
     */
    public function test_an_unparseable_date_is_ignored(): void
    {
        [$merchant] = $this->makeCatalogue();

        foreach (['not-a-date', '2026-13-45', '', '0', "' OR 1=1 --", '99999999999999'] as $value) {
            $refs = $this->references($merchant, ['ss_from' => $value]);

            $this->assertCount(3, $refs,
                "the date value [{$value}] narrowed or widened the list instead of being ignored");
        }
    }

    // =========================================================================================
    // A filter may ONLY narrow
    // =========================================================================================

    public function test_no_filter_value_can_reach_another_tenants_orders(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $this->grantPermission($merchantA, 'Manage Order');

        $this->makeOrder($storeA, ['product_order_id' => 'ORD-ALPHA-OWN']);
        $this->makeOrder($storeB, [
            'product_order_id' => 'ORD-BETA-SECRET',
            'delivered_status' => HomeController::ORDER_STATUS_DELIVERED,
            'payment_status' => 'Paid',
            'order_date' => '2026-03-17 12:00:00',
        ]);

        $this->assertSame(2, \DB::table('orders')->count(), 'premise: both orders exist');

        foreach ([
            [],
            ['ss_status' => '1'],
            ['ss_status' => '8'],
            ['ss_payment' => 'Paid'],
            ['ss_from' => '2026-01-01'],
            ['ss_to' => '2030-01-01'],
            ['ss_from' => '2026-01-01', 'ss_to' => '2030-01-01'],
            ['ss_status' => 'nonsense'],
            ['ss_payment' => "' OR 1=1 --"],
            ['ss_from' => "' OR 1=1 --"],
            ['ss_status' => '-1'],
        ] as $filters) {
            $this->assertFalse(
                $this->served($this->references($merchantA, $filters), 'ORD-BETA-SECRET'),
                'the filter ' . json_encode($filters) . " reached another tenant's order"
            );
        }
    }

    public function test_no_filter_value_returns_more_rows_than_no_filter_at_all(): void
    {
        [$merchant] = $this->makeCatalogue();

        $unfiltered = count($this->references($merchant));
        $this->assertSame(3, $unfiltered, 'premise: the baseline is the whole list');

        foreach ([
            ['ss_status' => '0'], ['ss_status' => '7'], ['ss_status' => '8'],
            ['ss_payment' => 'Paid'], ['ss_payment' => 'Unpaid'],
            ['ss_from' => '2026-03-01'], ['ss_to' => '2026-04-02'],
            ['ss_status' => 'nonsense'], ['ss_status' => '99'], ['ss_status' => ''],
            ['ss_payment' => 'nonsense'], ['ss_from' => 'nonsense'],
        ] as $filters) {
            $this->assertLessThanOrEqual($unfiltered,
                count($this->references($merchant, $filters)),
                'the filter ' . json_encode($filters) . ' returned MORE rows than no filter');
        }
    }

    /** An array where a scalar is expected must not crash the list. */
    public function test_an_array_filter_value_does_not_crash_the_list(): void
    {
        [$merchant] = $this->makeCatalogue();

        $response = $this->actingAs($merchant)
            ->withHeaders(self::AJAX)
            ->getJson('/order?' . http_build_query([
                'ss_status' => ['1'],
                'ss_payment' => ['Paid'],
                'ss_from' => ['2026-01-01'],
                'draw' => 1, 'start' => 0, 'length' => 10,
            ]));

        $this->assertNotSame(500, $response->getStatusCode(),
            'an array where a scalar filter was expected crashed the order list');
    }

    // =========================================================================================
    // The bar is on the page
    // =========================================================================================

    public function test_the_filter_bar_renders_with_the_parameter_names_the_query_reads(): void
    {
        [$merchant] = $this->makeCatalogue();

        $response = $this->actingAs($merchant)->get('/order');

        $response->assertOk();

        $body = (string) $response->getContent();

        foreach (['ss_status', 'ss_payment', 'ss_from', 'ss_to'] as $name) {
            $this->assertStringContainsString('name="' . $name . '"', $body,
                "the filter bar does not offer {$name}, which the query reads");
        }

        $this->assertStringContainsString('data-ss-order-filters', $body,
            'the filter bar is missing from the order list');
    }

    /**
     * EVERY VALUE THE COLUMN HOLDS IS OFFERED, including 7 and 8.
     *
     * Read from `HomeController`'s constants rather than counting `<option>` tags, so adding a
     * tenth status to the column fails this test instead of quietly producing a filter that
     * cannot find those orders.
     */
    public function test_the_status_select_offers_every_value_the_column_holds(): void
    {
        [$merchant] = $this->makeCatalogue();

        $body = (string) $this->actingAs($merchant)->get('/order')->getContent();

        $constants = (new \ReflectionClass(HomeController::class))->getConstants();
        $statuses = [];

        foreach ($constants as $name => $value) {
            if (str_starts_with($name, 'ORDER_STATUS_')) {
                $statuses[] = $value;
            }
        }

        // PREMISE: the scan found the constants. A rename would otherwise make this pass
        // against an empty expectation.
        $this->assertCount(9, $statuses,
            'the ORDER_STATUS_* constants no longer number nine, so this test is measuring the '
            . 'wrong set -- read HomeController before changing the number');

        foreach ($statuses as $value) {
            $this->assertStringContainsString('value="' . $value . '"', $body,
                "the status filter does not offer delivered_status = {$value}, so those orders "
                . 'cannot be found from the list');
        }
    }
}
