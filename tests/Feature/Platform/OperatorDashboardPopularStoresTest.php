<?php

namespace Tests\Feature\Platform;

use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\StripsSourceComments;

/**
 * P05-19 (GAP-167) — THE OPERATOR DASHBOARD'S "MOST POPULAR STORE" PANEL.
 * =============================================================================================
 *
 * The sibling of the "Top Selling Store" panel, found while repairing that one. Its whole
 * implementation was three lines in `HomeController::handleSuperAdmin()`:
 *
 *     $visitors = DB::table('shetabit_visits')->whereNotNull('store_id')->pluck('store_id')->toArray();
 *     $visitors = array_count_values($visitors);
 *     arsort($visitors);  // then array_slice(..., 0, 5, true)
 *
 * (1) EVERY ROW OF THE VISIT LOG WAS LOADED INTO PHP TO COUNT FIVE NUMBERS. `shetabit_visits`
 *     gains a row for every themed storefront request of every tenant, crawlers included, and is
 *     never pruned. This read has no window and no limit, and it ran on every load of the
 *     operator's landing page — so that page's memory use grows with the whole platform's traffic
 *     until it stops rendering. `AnalyticsPeriod` caps a MERCHANT's window to bound the cost of
 *     reading this same table; the operator's read of all of it had no bound at all.
 *
 * (2) THE BREADTH WAS DECLARED NOWHERE. `shetabit_visits` is not in `config/tenancy.php`'s
 *     `scoped_models`, so no global scope narrows it for anybody. This read is cross-tenant ON
 *     PURPOSE, and said so only by the absence of a store predicate (P05-19).
 *
 * (3) A VISIT WHOSE STORE NO LONGER EXISTS TOOK ONE OF THE FIVE PLACES. The top five were chosen
 *     from raw `store_id` values and the view then skipped any it could not find, so the panel
 *     silently showed four, or three.
 *
 * (4) NOTHING SAID WHAT THE NUMBER IS. It sits beside a heart icon under "Most Popular Store".
 *     It is a count of page views — requests, not people — which is exactly the distinction the
 *     merchant's own screen is careful to state.
 *
 * WHAT IS UNCHANGED: the figure itself. Still every recorded page view, still all of them, still
 * the top five. No window is introduced, because choosing one would change what the operator
 * sees rather than how it is computed.
 */
class OperatorDashboardPopularStoresTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    private function pageViews(int $storeId, int $count): void
    {
        $rows = [];

        for ($n = 0; $n < $count; $n++) {
            $rows[] = ['store_id' => $storeId, 'created_at' => now(), 'updated_at' => now()];
        }

        DB::table('shetabit_visits')->insert($rows);
    }

    private function named(Store $store, string $name): Store
    {
        $store->name = $name;
        $store->save();

        return $store;
    }

    private function dashboard($user)
    {
        Cache::forget('admin_details');

        return $this->actingAs($user->fresh())->get('/dashboard');
    }

    public function test_the_panel_ranks_stores_by_recorded_page_views(): void
    {
        $operator = $this->makePlatformAdmin();

        [, $quiet] = $this->makeTenant();
        [, $busy] = $this->makeTenant();
        [, $middling] = $this->makeTenant();

        $this->named($quiet, 'Quiet Lane');
        $this->named($busy, 'Busy Square');
        $this->named($middling, 'Middling Road');

        // Inserted in an order that is neither the ranking nor its reverse.
        $this->pageViews($quiet->id, 1);
        $this->pageViews($busy->id, 5);
        $this->pageViews($middling->id, 3);

        $response = $this->dashboard($operator);
        $response->assertOk();

        $this->assertSame(
            [['Busy Square', 5], ['Middling Road', 3], ['Quiet Lane', 1]],
            array_map(static fn (array $row): array => [$row['name'], $row['views']], $response->viewData('popularStores')),
            'The panel does not list the stores by their recorded page views, highest first.'
        );

        // And on the page, in that order — the data reaching the view is not the feature.
        $body = (string) $response->getContent();

        $this->assertMatchesRegularExpression(
            '/Busy Square.*?Middling Road.*?Quiet Lane/su',
            $body,
            'The ranked stores are not printed on the dashboard in ranking order.'
        );
    }

    /**
     * THE COUNT IS DONE BY THE DATABASE. Asserted on the queries the request actually issues: any
     * read of the visit log must be an aggregate. The old read was
     * `select "store_id" from "shetabit_visits" where "store_id" is not null` — one PHP array
     * element per page view ever logged, on every load of this page.
     */
    public function test_page_views_are_counted_by_the_database_and_not_loaded_row_by_row(): void
    {
        $operator = $this->makePlatformAdmin();
        [, $store] = $this->makeTenant();
        $this->pageViews($store->id, 3);

        Cache::forget('admin_details');
        $this->actingAs($operator->fresh());

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->get('/dashboard');
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $response->assertOk();

        $reads = array_values(array_filter(
            $queries,
            static fn (string $sql): bool => stripos($sql, 'shetabit_visits') !== false && stripos(ltrim($sql), 'select') === 0
        ));

        $this->assertNotSame([], $reads,
            'PREMISE: the dashboard no longer reads the visit log at all, so this test is measuring nothing.');

        foreach ($reads as $sql) {
            $this->assertMatchesRegularExpression('/count\s*\(/i', $sql,
                "The operator dashboard reads the visit log without counting in the database: {$sql}");
            $this->assertMatchesRegularExpression('/group\s+by/i', $sql,
                "The operator dashboard reads the visit log without grouping in the database: {$sql}");
            $this->assertMatchesRegularExpression('/limit\s+\d+/i', $sql,
                "The operator dashboard reads the visit log with no bound on the rows returned: {$sql}");
        }
    }

    /**
     * A PAGE VIEW WHOSE STORE IS GONE TAKES NO PLACE. Five real stores and one identifier that
     * belongs to no store, which has more page views than any of them. The old panel chose its
     * five from the raw identifiers and then skipped the one it could not find — so it showed four.
     */
    public function test_a_page_view_logged_against_a_store_that_no_longer_exists_takes_no_place(): void
    {
        $operator = $this->makePlatformAdmin();

        $names = [];

        foreach (range(1, 5) as $n) {
            [, $store] = $this->makeTenant();
            $this->named($store, $names[] = 'Real Store ' . $n);
            $this->pageViews($store->id, $n);
        }

        $this->assertSame(0, DB::table('stores')->where('id', 987654)->count(),
            'PREMISE: store 987654 exists, so its page views are not orphaned.');
        $this->pageViews(987654, 50);

        $response = $this->dashboard($operator);
        $response->assertOk();

        $listed = array_column($response->viewData('popularStores'), 'name');
        sort($listed);

        $this->assertSame($names, $listed,
            'A page view logged against a store that no longer exists took one of the five places, '
            . 'so the panel lists fewer stores than there are.');
    }

    public function test_the_panel_stops_at_five(): void
    {
        $operator = $this->makePlatformAdmin();

        foreach (range(1, 6) as $n) {
            [, $store] = $this->makeTenant();
            $this->named($store, 'Ranked ' . $n);
            // Distinct counts, so the store left out is decided by the count and not by a tie.
            $this->pageViews($store->id, $n);
        }

        $rows = $this->dashboard($operator)->viewData('popularStores');

        $this->assertCount(5, $rows);
        $this->assertNotContains('Ranked 1', array_column($rows, 'name'),
            'The store with the fewest page views is listed, so the five are not the top five.');
    }

    /**
     * THE NUMBER IS NAMED. A count beside a heart, under "Most Popular Store", reads as people or
     * as likes. It is requests. The merchant's screen says so about its own visits; the operator's
     * said nothing. The control proves the sentence is this panel's and not the layout's.
     */
    public function test_the_panel_says_what_the_number_counts(): void
    {
        $operator = $this->makePlatformAdmin();
        [$merchant, $store] = $this->makeTenant();
        $this->pageViews($store->id, 2);

        $sentence = __('Storefront page views recorded for each store since recording began. A page view is one request, not one person, and automated traffic is included.');

        $forTheOperator = $this->dashboard($operator);
        $forTheOperator->assertOk();

        $this->assertStringContainsString($sentence, html_entity_decode((string) $forTheOperator->getContent(), ENT_QUOTES | ENT_HTML5),
            'The panel shows a number without saying that it counts page views rather than people.');

        // The control has to RENDER to be a control. Without the status assertion a redirect or an
        // error page would satisfy "the sentence is absent" without ever drawing the layout this
        // exists to rule out -- found by independent review.
        $forTheMerchant = $this->dashboard($merchant);
        $forTheMerchant->assertOk();

        $this->assertStringNotContainsString($sentence, html_entity_decode((string) $forTheMerchant->getContent(), ENT_QUOTES | ENT_HTML5),
            'CONTROL: the sentence is on the merchant dashboard too, so finding it above proves nothing about the panel.');
    }

    /**
     * A STORE'S NAME IS MERCHANT-WRITTEN, AND THIS IS THE OPERATOR'S PAGE. The view escapes it;
     * nothing held it to that. Review found that swapping the escaped echo for a raw one survived
     * every test in this file, because no fixture name carried markup.
     */
    public function test_a_store_name_carrying_markup_is_printed_as_text(): void
    {
        $operator = $this->makePlatformAdmin();
        [, $store] = $this->makeTenant();
        $this->named($store, 'Viewed <b>Shop</b> & Sons');
        $this->pageViews($store->id, 2);

        $response = $this->dashboard($operator);
        $response->assertOk();

        $body = (string) $response->getContent();

        $this->assertStringContainsString('Viewed &lt;b&gt;Shop&lt;/b&gt; &amp; Sons', $body,
            'PREMISE: the store is not listed on the panel at all, so its escaping is not being measured.');
        $this->assertStringNotContainsString('Viewed <b>Shop</b>', $body,
            'A merchant-written store name reached the operator\'s page as markup.');
    }

    /** The sentence is new, so it needs both languages — a key with no Arabic renders English on an Arabic page. */
    public function test_the_sentence_has_an_arabic_translation(): void
    {
        $key = 'Storefront page views recorded for each store since recording began. A page view is one request, not one person, and automated traffic is included.';

        $arabic = json_decode((string) file_get_contents(base_path('resources/lang/ar.json')), true);

        $this->assertArrayHasKey($key, $arabic, 'The panel\'s sentence has no Arabic translation.');
        $this->assertNotSame($key, $arabic[$key]);
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', (string) $arabic[$key]);
    }

    /**
     * THE BREADTH OF THE READ IS VISIBLE AT THE CALL SITE (P05-19), as it now is for the money
     * panel beside it. Comments are stripped first: the handler's own explanation names the table.
     */
    public function test_the_cross_tenant_read_is_declared_through_a_metric_scope(): void
    {
        $source = $this->sourceWithoutComments('app/Http/Controllers/HomeController.php');

        $start = strpos($source, 'function handleSuperAdmin');
        $end = strpos($source, 'function handleRegularUser');

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        $handler = substr($source, $start, $end - $start);

        $this->assertStringNotContainsString('shetabit_visits', $handler,
            'The operator handler still reads the visit log with its own query, with no store '
            . 'predicate and nothing saying that the omission is deliberate.');

        $this->assertMatchesRegularExpression(
            '/topStoresByPageViews\s*\(\s*\\\\?[A-Za-z\\\\]*MetricScope::stores\s*\(/',
            $handler,
            'The page-view ranking is not handed the set of stores it may read as an argument.'
        );
    }

    /** The panel is the operator's alone; a merchant's dashboard is given none of it. */
    public function test_a_merchant_never_receives_the_panel(): void
    {
        $this->makePlatformAdmin();

        [, $other] = $this->makeTenant();
        $this->named($other, 'Somebody Elses Shop');
        $this->pageViews($other->id, 4);

        [$merchant] = $this->makeTenant();

        $response = $this->dashboard($merchant);
        $response->assertOk();

        $this->assertStringNotContainsString('Somebody Elses Shop', (string) $response->getContent(),
            "Another tenant's store name reached a merchant.");
        $this->assertArrayNotHasKey('popularStores', $response->original->getData(),
            'The merchant dashboard was handed the cross-tenant page-view ranking.');
    }

    /**
     * THE TWO PRINCIPALS WHOSE TENANT ROOT IS THE OPERATOR. The dispatch in
     * `HomeController::index()` is the ONLY gate in front of both cross-tenant panels, and every
     * other refusal test here drives an ordinary type-`admin` merchant -- for whom a dispatch keyed
     * on almost anything is correct by accident. These two are the ones `creatorId()` resolves to
     * the operator's own id:
     *
     *   - staff the operator created (`created_by` = the operator);
     *   - a merchant who changed their own `type` to a role name, which the product allows, and
     *     whose `created_by` has been the operator's id since the day they registered.
     *
     * CLAUDE.md records that reading `created_by = 1` as a tenant was once an account takeover.
     * Nothing here asserts what these principals DO see -- other guards decide that, and some of
     * them refuse. It asserts what they must never be handed.
     *
     * @dataProvider principalsRootedAtTheOperator
     */
    public function test_a_principal_rooted_at_the_operator_is_not_the_operator(string $kind): void
    {
        $operator = $this->makePlatformAdmin();

        [, $other] = $this->makeTenant();
        $this->named($other, 'Somebody Elses Shop');
        $this->pageViews($other->id, 4);

        [$merchant, $ownStore] = $this->makeTenant();

        if ($kind === 'staff the operator created') {
            $principal = $this->makeStaff($operator, ['current_store' => $ownStore->id]);
        } else {
            // Through Eloquent: `User` is Cachable, and a raw write leaves the old `type` being served.
            $merchant->type = 'manager';
            $merchant->save();
            $principal = $merchant->fresh();
        }

        $this->assertSame((int) $operator->id, (int) $principal->creatorId(),
            "PREMISE: this principal's tenant root is not the operator, so the case is an ordinary "
            . 'merchant under another name and measures nothing the other tests do not.');
        $this->assertNotSame('super admin', $principal->type);

        $response = $this->dashboard($principal);

        $view = $response->original;
        $handed = $view instanceof \Illuminate\View\View ? $view->getData() : [];

        $this->assertFalse($view instanceof \Illuminate\View\View && $view->name() === 'superadmin.dashboard',
            "A {$kind} was given the platform operator's dashboard.");
        $this->assertArrayNotHasKey('popularStores', $handed, "A {$kind} was handed the cross-tenant page-view ranking.");
        $this->assertArrayNotHasKey('topStoresByCurrency', $handed, "A {$kind} was handed the cross-tenant order-value ranking.");
        $this->assertStringNotContainsString('Somebody Elses Shop', (string) $response->getContent(),
            "Another tenant's store name reached a {$kind}.");
    }

    /** @return array<string, array{0: string}> */
    public static function principalsRootedAtTheOperator(): array
    {
        return [
            'staff the operator created' => ['staff the operator created'],
            'a merchant who demoted themselves' => ['a merchant who demoted themselves'],
        ];
    }
}
