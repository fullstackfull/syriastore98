<?php

namespace Tests\Feature\Platform;

use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\StripsSourceComments;

/**
 * P05-14 / P05-19 (GAP-162, GAP-167) — THE OPERATOR DASHBOARD'S "TOP SELLING STORE" PANEL.
 * =============================================================================================
 *
 * The one PRE-EXISTING cross-tenant money figure in the product, older than the Phase 05 metric
 * layer and never brought onto it. The register recorded one thing wrong with it: an unfiltered
 * order-value sum with no caveat. Reading the handler and its view found two more, and they are
 * worse than the one that was known.
 *
 * (1) EVERY STORE'S TOTAL WAS PRINTED IN THE OPERATOR'S OWN STORE'S CURRENCY. The view called
 *     `currency_format_with_sym($store->total_amount, getCurrentStore())`. `getCurrentStore()` is
 *     the SIGNED-IN OPERATOR's store, not the row's. A store that took 5,000,000 Syrian pounds was
 *     shown as "$5,000,000". This is the same defect GAP-222 removed from five wallet figures —
 *     an amount printed under a currency it is not denominated in — on the one screen where the
 *     reader is comparing tenants.
 *
 * (2) STORES IN DIFFERENT CURRENCIES WERE RANKED AGAINST EACH OTHER BY THE RAW NUMBER.
 *     `SUM(final_price) … ORDER BY total_amount DESC` across every store. A store selling in a
 *     currency with large nominal amounts outranks every store selling in dollars, always, by
 *     arithmetic alone. `PlatformAnalyticsFigures` already records the rule this breaks: a
 *     cross-tenant money figure is "a SET of per-currency amounts, not a total", because nothing
 *     in this schema stamps an exchange rate onto an order. Comparing two currencies by their
 *     digits is the same error as adding them.
 *
 * (3) NOTHING SAID WHAT THE FIGURE COUNTS. It sums every order ever placed, cancelled and unpaid
 *     included, under the title "Top Selling Store".
 *
 * WHAT THIS DOES NOT DO, deliberately. It does not decide that cancelled orders should be
 * excluded. The merchant dashboard's own order-value figure excludes them by owner decision F257,
 * and there is a case for the operator's ranking to follow — but F257 names the merchant's
 * figure, and extending it here would be inventing the next decision rather than implementing
 * that one. So the basis is kept exactly as shipped and STATED, which is what
 * `GrossMerchandiseValue`'s own definition requires: "The report must label which it used. Never
 * silently pick one." `test_a_cancelled_order_is_still_counted…` pins that, so changing it is a
 * deliberate act with an owner decision behind it, not a side effect.
 *
 * Within ONE currency the ranking is unchanged: same basis, same order, same top five.
 */
class OperatorDashboardTopStoresTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    private function order(Store $store, float $amount, ?string $currency, int $status = 0): void
    {
        DB::table('orders')->insert([
            'product_order_id' => 'TS-' . $this->uniqueFixtureRef(),
            'store_id' => $store->id,
            'customer_id' => 0,
            'is_guest' => 1,
            'delivered_status' => $status,
            'final_price' => $amount,
            'currency_code' => $currency,
            'order_date' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function nameStore(Store $store, string $name): Store
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

    /** Money is wrapped in bidi isolates for mixed-script rendering; compare without them. */
    private function plain(string $text): string
    {
        return str_replace(["\u{2066}", "\u{2069}"], '', html_entity_decode($text, ENT_QUOTES | ENT_HTML5));
    }

    /** @return array{0: \App\Models\User, 1: Store, 2: Store} operator, a pounds store, a dollars store */
    private function twoStoresInTwoCurrencies(): array
    {
        $operator = $this->makePlatformAdmin();

        [, $pounds] = $this->makeTenant();
        [, $dollars] = $this->makeTenant();

        $this->nameStore($pounds, 'Damascus Pounds Shop');
        $this->nameStore($dollars, 'Dollar Corner');

        // One large-nominal order against three small ones worth far more in any real terms.
        $this->order($pounds, 5000000.00, 'SYP');
        $this->order($dollars, 300.00, 'USD');
        $this->order($dollars, 300.00, 'USD');
        $this->order($dollars, 300.00, 'USD');

        return [$operator, $pounds, $dollars];
    }

    public function test_premise_the_operator_dashboard_renders_under_the_suite(): void
    {
        [$operator] = $this->twoStoresInTwoCurrencies();

        $this->dashboard($operator)->assertOk()->assertSee('Damascus Pounds Shop');
    }

    public function test_each_stores_figure_is_printed_in_that_stores_own_currency(): void
    {
        [$operator] = $this->twoStoresInTwoCurrencies();

        $body = $this->plain((string) $this->dashboard($operator)->getContent());

        $this->assertStringContainsString('5,000,000.00 SYP', $body,
            'The pounds store\'s order value is not printed in pounds. The panel formatted every '
            . 'store\'s total with the SIGNED-IN OPERATOR\'s own store settings, so an amount is '
            . 'shown under a currency it is not denominated in.');

        $this->assertStringContainsString('900.00 USD', $body,
            'The dollars store\'s order value is not printed in dollars.');

        $this->assertStringNotContainsString('$5,000,000', $body,
            'Five million Syrian pounds is printed as five million dollars.');
    }

    /**
     * NEVER RANKED ACROSS CURRENCIES. The view receives one ranked list PER CURRENCY, and a store
     * appears only in the list of the currency its orders are in.
     */
    public function test_stores_in_different_currencies_are_never_ranked_against_each_other(): void
    {
        [$operator, $pounds, $dollars] = $this->twoStoresInTwoCurrencies();

        $response = $this->dashboard($operator);
        $response->assertOk();

        $ranked = $response->viewData('topStoresByCurrency');

        $this->assertIsArray($ranked,
            'The panel still hands the view one flat list ordered by the raw number, so a store '
            . 'selling in a large-nominal currency outranks every dollar store by arithmetic.');

        $this->assertSame(['SYP', 'USD'], array_keys($ranked),
            'The stores are not grouped by the currency their orders are in.');

        $this->assertSame([(int) $pounds->id], array_map(fn ($row) => (int) $row['store_id'], $ranked['SYP']));
        $this->assertSame([(int) $dollars->id], array_map(fn ($row) => (int) $row['store_id'], $ranked['USD']));
    }

    /** Within one currency nothing about the ranking changes: highest order value first, top five. */
    public function test_within_one_currency_the_ranking_is_by_order_value_and_stops_at_five(): void
    {
        $operator = $this->makePlatformAdmin();

        $expected = [];
        foreach ([10.00, 70.00, 30.00, 50.00, 20.00, 60.00, 40.00] as $amount) {
            [, $store] = $this->makeTenant();
            $this->order($store, $amount, 'USD');
            $expected[(int) $store->id] = $amount;
        }

        arsort($expected);
        $topFive = array_slice(array_keys($expected), 0, 5);

        $ranked = $this->dashboard($operator)->viewData('topStoresByCurrency');

        $this->assertSame($topFive, array_map(fn ($row) => (int) $row['store_id'], $ranked['USD'] ?? []),
            'Within a single currency the panel no longer lists the five stores with the highest '
            . 'order value, highest first.');
    }

    /**
     * THE BASIS IS UNCHANGED AND IS NOW STATED. Cancelled orders are counted, as they always were
     * here; whether the operator's ranking should follow the merchant figure's F257 exclusion is
     * an owner decision this change does not take.
     */
    public function test_a_cancelled_order_is_still_counted_and_the_panel_says_what_it_counts(): void
    {
        $operator = $this->makePlatformAdmin();
        [, $store] = $this->makeTenant();
        $this->nameStore($store, 'Cancelled Mostly');

        $this->order($store, 100.00, 'USD');
        $this->order($store, 400.00, 'USD', \App\Http\Controllers\HomeController::ORDER_STATUS_CANCEL);

        $response = $this->dashboard($operator);
        $body = $this->plain((string) $response->getContent());

        $this->assertStringContainsString('500.00 USD', $body,
            'The cancelled order is no longer counted. That changes what an operator-facing figure '
            . 'means, and it needs an owner decision: F257 covers the MERCHANT dashboard\'s figure.');

        $this->assertStringContainsString(
            $this->plain(__('Order value: every order placed, including unpaid and cancelled orders. Stores are ranked only against stores in the same currency.')),
            $body,
            'The panel does not say what its figure counts, so a ranking that includes cancelled '
            . 'and unpaid orders reads as a ranking of sales.'
        );
    }

    /**
     * An order with no recorded currency takes ITS OWN STORE's currency — not the operator's.
     *
     * THE FIRST VERSION OF THIS FIXTURE COULD NOT TELL THE TWO APART, and review found it. Its only
     * store was id 1, and a store-less operator's `getCurrentStore()` answers the literal 1. So a
     * fallback that read the OPERATOR's store's currency — the original defect class of this very
     * panel — would also have printed `250.00 SYP`, and the test would have passed.
     *
     * Now a decoy tenant is created first, it holds a DIFFERENT currency, and the operator has it
     * selected. The legacy rows belong to a second store. Only a fallback that follows the row can
     * print pounds, and one that follows the signed-in principal prints euros.
     */
    public function test_an_order_with_no_recorded_currency_takes_its_stores_own_currency(): void
    {
        [$decoyOwner, $decoy] = $this->makeTenant();
        $operator = $this->makePlatformAdmin(['current_store' => $decoy->id]);

        [$merchant, $store] = $this->makeTenant();
        $this->nameStore($store, 'Legacy Rows');

        $this->assertNotSame((int) $decoy->id, (int) $store->id);
        $this->assertSame((int) $decoy->id, (int) getCurrentStore($operator->id),
            'PREMISE: the operator does not resolve the decoy store, so this fixture cannot tell '
            . 'the row\'s store from the signed-in principal\'s.');

        foreach ([[$decoyOwner, $decoy, 'EUR'], [$merchant, $store, 'SYP']] as [$owner, $ofStore, $code]) {
            DB::table('settings')->insert([
                'name' => 'defult_currancy', 'value' => $code, 'created_by' => $owner->id,
                'store_id' => $ofStore->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        (new \App\Models\Setting)->flushCache();

        $this->order($store, 250.00, null);

        $body = $this->plain((string) $this->dashboard($operator)->getContent());

        $this->assertStringContainsString('250.00 SYP', $body,
            'An order whose currency was never recorded is missing from the panel, or is printed '
            . 'under a currency other than its own store\'s.');

        $this->assertStringNotContainsString('250.00 EUR', $body,
            'The legacy order is printed in the SIGNED-IN OPERATOR\'s selected store\'s currency. '
            . 'That is the defect this panel was fixed for, arriving through the fallback.');
    }

    /**
     * A STORE WITH DUPLICATE CURRENCY ROWS IS GIVEN THE SAME ONE AS THE FINANCE REPORT GIVES IT.
     * `ReportController::financeReport()` reads the setting with `->value('value')`, the oldest
     * row; this reads many stores at once with `pluck()`, which keeps the LAST value per key. So
     * the read is ordered newest-first to end on the same row, rather than on whichever the
     * engine happened to return last.
     */
    public function test_duplicate_currency_rows_resolve_to_the_oldest_as_the_finance_report_does(): void
    {
        $operator = $this->makePlatformAdmin();
        [$merchant, $store] = $this->makeTenant();
        $this->nameStore($store, 'Two Rows');

        foreach (['SYP', 'EUR'] as $code) {
            DB::table('settings')->insert([
                'name' => 'defult_currancy', 'value' => $code, 'created_by' => $merchant->id,
                'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        (new \App\Models\Setting)->flushCache();

        $this->assertSame('SYP', \App\Models\Setting::where('store_id', $store->id)
            ->where('name', 'defult_currancy')->value('value'),
            'PREMISE: the finance report\'s own read no longer returns the oldest row.');

        $this->order($store, 40.00, null);

        $body = $this->plain((string) $this->dashboard($operator)->getContent());

        $this->assertStringContainsString('40.00 SYP', $body,
            'A store with two currency rows is labelled differently here than on its own finance report.');
    }

    /**
     * THE CODE IS PRINTED THROUGH THE PRODUCT'S OWN CLEANER. A currency code is a merchant-written
     * setting snapshotted onto every order, and `CurrencySymbol::clean()` is the control this
     * product applies wherever one is printed (P1-SEC-80). The view escapes it too — asserted on
     * the DATA here, so this pins the cleaner and not merely the escaping.
     */
    public function test_a_currency_code_carrying_markup_is_cleaned_before_it_is_grouped_or_printed(): void
    {
        $operator = $this->makePlatformAdmin();
        [, $store] = $this->makeTenant();

        $this->order($store, 10.00, 'US<b>D');

        $response = $this->dashboard($operator);
        $ranked = $response->viewData('topStoresByCurrency');

        $this->assertSame(['USBD'], array_keys($ranked),
            'A currency code reached the panel without passing through CurrencySymbol::clean().');

        $this->assertStringNotContainsString('<b>D', (string) $response->getContent());
    }

    /**
     * AND THE SECOND SOURCE. The case above carries the markup on the ORDER; the fallback reads a
     * different column of a different table -- the store's own `defult_currancy` setting -- and
     * the code cleans that as well. Nothing tested it: a mutation removing the cleaner from the
     * fallback alone survived the case above, because its fixture never takes the fallback.
     */
    public function test_a_stores_own_currency_setting_carrying_markup_is_cleaned_too(): void
    {
        $operator = $this->makePlatformAdmin();
        [$merchant, $store] = $this->makeTenant();

        DB::table('settings')->insert([
            'name' => 'defult_currancy', 'value' => 'SY<i>P', 'created_by' => $merchant->id,
            'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        (new \App\Models\Setting)->flushCache();

        // No recorded currency, so the store's own setting is what names the list.
        $this->order($store, 10.00, null);

        $response = $this->dashboard($operator);

        $this->assertSame(['SYIP'], array_keys($response->viewData('topStoresByCurrency')),
            'A store\'s own currency setting reached the panel without passing through '
            . 'CurrencySymbol::clean(). The recorded code is cleaned and the fallback is not.');

        $this->assertStringNotContainsString('<i>P', (string) $response->getContent());
    }

    /**
     * THE PANEL IS THE OPERATOR'S ALONE. A merchant asking for the same URL gets the merchant
     * dashboard, and none of another tenant's names or amounts.
     */
    public function test_a_merchant_never_receives_the_cross_tenant_panel(): void
    {
        [, $pounds, $dollars] = $this->twoStoresInTwoCurrencies();
        [$merchant] = $this->makeTenant();

        $response = $this->dashboard($merchant);
        $response->assertOk();

        $body = $this->plain((string) $response->getContent());

        $this->assertStringNotContainsString('Damascus Pounds Shop', $body, "Another tenant's store name reached a merchant.");
        $this->assertStringNotContainsString('Dollar Corner', $body, "Another tenant's store name reached a merchant.");
        $this->assertStringNotContainsString('5,000,000.00', $body, "Another tenant's order value reached a merchant.");
        // `viewData()` throws on a key the view was never given, which is the answer wanted here.
        $this->assertArrayNotHasKey('topStoresByCurrency', $response->original->getData(),
            'The merchant dashboard was handed the cross-tenant ranking.');
    }

    /**
     * THE BREADTH OF THE READ IS VISIBLE AT THE CALL SITE (P05-19). Every Phase 05 figure names
     * the stores it reads through a `MetricScope`; this panel was a bare `Order::select()` with no
     * predicate, deliberately cross-tenant and saying so nowhere. Comments are stripped first — the
     * handler's own explanation mentions the old query.
     */
    public function test_the_cross_tenant_read_is_declared_through_a_metric_scope(): void
    {
        $source = $this->sourceWithoutComments('app/Http/Controllers/HomeController.php');

        $start = strpos($source, 'function handleSuperAdmin');
        $end = strpos($source, 'function handleRegularUser');

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        $handler = substr($source, $start, $end - $start);

        $this->assertStringContainsString('MetricScope::stores(', $handler,
            'The operator panel reads every tenant without naming that breadth at the call site.');

        // Case and spacing ignored: `sum( final_price )` is the same query.
        $this->assertDoesNotMatchRegularExpression('/sum\s*\(\s*[`"\']?(orders\.)?final_price/i', $handler,
            'The operator handler still sums order value with its own SQL instead of the metric '
            . 'layer, which is how this one figure came to disagree with every other about currency.');
    }
}
