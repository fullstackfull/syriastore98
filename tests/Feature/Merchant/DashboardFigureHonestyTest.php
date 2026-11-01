<?php

namespace Tests\Feature\Merchant;

use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\Feature\Security\SecurityTestCase;

/**
 * WS C — what the merchant dashboard says, and whether it is true.
 * =================================================================================================
 *
 * `/dashboard` renders TWO files: `resources/views/dashboard.blade.php`, the vendored WorkDo page,
 * and `resources/views/dashboard/overview.blade.php`, the Phase 02 region it `@include`s at the
 * top. Phase 02 built the overview and left everything below it alone, so the page ended up
 * answering several questions twice — and the two answers did not agree. Each test below pins one
 * of those disagreements.
 *
 * 1. THE ORDER LIST WAS ON THE PAGE TWICE, AND ONE COPY HAD NO GATE. The overview's "Latest
 *    orders" and the WorkDo page's "Recent Orders" iterated the SAME `$new_orders` collection. The
 *    overview wraps its copy in `@if ($ssMayOrders)`; the WorkDo copy had no check at all, so a
 *    staff member whose role grants only, say, Manage Blog saw the overview decide they must not
 *    see orders and then read the order list anyway, a few hundred pixels lower. The duplicate is
 *    deleted; nothing is lost from the page.
 *
 * 2. AND THE `/100` IN THAT PARTIAL IS NOT A CURRENCY SCALE — WS C REMOVED IT AND HAD TO PUT IT
 *    BACK. `$sale->total_sale` is `SUM(orders.final_price)`, and `orders.final_price` really is a
 *    major-unit decimal everywhere else it appears, so the division looks like a minor-unit
 *    conversion applied to money that is already major units. A real MariaDB says otherwise: the
 *    twelve queries CROSS JOIN a 1..100 series with nothing bounding it to the number of elements
 *    in `orders.product_id`, so a single-product order joins ONE HUNDRED TIMES and its price is
 *    summed a hundred times. Removing the division multiplies every figure on both cards by 100.
 *    It never shipped, because `verify-dashboard-top-sales-scope-on-mysql.php` was written before
 *    the commit — the Feature suite cannot execute the query at all, and the browser harness
 *    renders the cards empty for the same reason. `test_the_sales_figure_keeps_its_compensation`
 *    below is what stops it being "corrected" a second time.
 *
 * 3. AND THAT MONEY WAS UNGATED TOO, on the page and on the endpoint behind it. The route
 *    `top.brand.category.chart` carries `['auth','xss','setlocate','verified']` and no permission
 *    middleware, so any authenticated member of the tenant could POST it and read the store's
 *    order revenue broken down by category and by brand.
 *
 * 4. THREE TABS PER LIST SHARED ONE id AND NAMED A PANEL THAT DOES NOT EXIST. Week, Month and Year
 *    all carried `id="pills-seo-tab"` and `aria-controls="pills-seo"` — six duplicate ids on the
 *    page, and an `aria-controls` pointing at nothing — while all five tabs declared
 *    `aria-selected="true"`.
 *
 * 5. THE STORAGE CARD STATED A QUOTA IT DID NOT HAVE. `($plan->storage_limit ?? 0) . 'MB'`
 *    rendered "12MB / 0MB" for a user with no plan, which reads as a quota of zero already
 *    exceeded; the donut beside it simultaneously said 0% used, because `HomeController` leaves
 *    the percentage at 0 unless `$plan->storage_limit > 0`.
 *
 * WHY THESE ARE PAGE RENDERS AND NOT SOURCE GREPS. A `assertStringNotContainsString('</h4>')`-style
 * check on this repository has twice matched the author's own docblock, and a source assertion
 * cannot see a gate that is present but false. `/dashboard` renders in full under the Feature
 * suite — 200 and ~100 KB — so every claim here is measured from the markup a merchant receives.
 * The one exception is the endpoint's own SQL, which uses `SUBSTRING_INDEX` and cannot run on
 * SQLite at all; what that costs, and where the evidence for it lives instead, is stated on
 * `test_the_sales_endpoint_admits_a_principal_who_holds_manage_order`.
 */
class DashboardFigureHonestyTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();

        $this->grantPermission(User::find($this->merchant->id), 'Manage Order');
        $this->grantPermission(User::find($this->merchant->id), 'Manage Products');

        $this->merchant = User::find($this->merchant->id);

        /* THE TABLE ONLY RENDERS WHEN THERE IS SOMETHING IN IT. With no orders the overview
           shows its empty state instead, `data-ss-list="latest-orders"` never appears, and a
           census for "exactly one" would pass against a page that had neither copy. */
        $this->makeOrder($this->store, ['delivered_status' => 0]);
    }

    private function dashboardAs(User $user): string
    {
        $response = $this->actingAs($user)->get('/dashboard');
        $response->assertStatus(200);

        return (string) $response->getContent();
    }

    /**
     * A staff member inside this tenant holding a permission that has nothing to do with orders or
     * products — the principal the page used to leak to.
     */
    private function unprivilegedStaff(): User
    {
        $staff = $this->makeStaff($this->merchant, ['current_store' => $this->store->id]);
        $this->grantPermission(User::find($staff->id), 'Manage Blog');

        return User::find($staff->id);
    }

    // ---------------------------------------------------------------------------------------
    //  1 — THE ORDER LIST, ONCE
    // ---------------------------------------------------------------------------------------

    public function test_the_order_list_appears_exactly_once_on_the_dashboard(): void
    {
        $body = $this->dashboardAs($this->merchant);

        $this->assertSame(1, substr_count($body, 'data-ss-list="latest-orders"'),
            "the overview's order table did not render exactly once, so this test could not "
            . 'measure whether a second copy exists');
        $this->assertSame(0, substr_count($body, 'Recent Orders'),
            'the WorkDo "Recent Orders" block is still on the page, so the dashboard shows the '
            . 'same four orders twice');
    }

    public function test_a_principal_without_manage_order_sees_no_order_list_at_all(): void
    {
        $body = $this->dashboardAs($this->unprivilegedStaff());

        $this->assertSame(0, substr_count($body, 'data-ss-list="latest-orders"'));
        $this->assertSame(0, substr_count($body, 'Recent Orders'),
            'a staff member without Manage Order was shown the order list by the ungated '
            . 'WorkDo block, defeating the gate the overview applies to the same data');
    }

    // ---------------------------------------------------------------------------------------
    //  2 + 3 — THE SALES MONEY
    // ---------------------------------------------------------------------------------------

    /**
     * [2026-11-01] THE DIVISION IS GONE, AND THIS RATCHET IS INVERTED. F242, owner-approved.
     *
     * WHAT THIS TEST USED TO ASSERT, and why it was right at the time: the twelve queries behind
     * this partial cross-joined a 1..100 generated series, so a single-product order's price arrived
     * summed a hundred times and the view's `/100` was the compensation. WS C removed the divisor
     * once on the reasoning that `final_price` is already major units; that reasoning was right
     * about the column and wrong about the query, and this ratchet existed to stop a second attempt.
     *
     * WHY IT IS NOW THE OPPOSITE: the fan-out itself is gone. `App\Support\TopSellersByRevenue`
     * sums each order LINE's revenue from `orders.product_json`, so `total_sale` arrives as real
     * money and a `/100` would render every figure on both cards as a hundredth of the truth.
     *
     * The divisor could never have been correct anyway, which is what F242 recorded and what
     * `verify-dashboard-top-sales-scope-on-mysql.php` now measures on a real MariaDB: the fan-out
     * was NON-UNIFORM, so on one three-product order the old card showed Rugs as **2.15** against a
     * truth of 100.00 and Tiles as **210.70** against a truth of 15.00. No single divisor corrects
     * both directions at once.
     */
    public function test_the_sales_figure_is_printed_as_real_money_with_no_divisor(): void
    {
        $this->actingAs($this->merchant);

        $html = View::make('order.brand_category_chart', [
            'tab_name' => '#all-category-order',
            /* What the computation really returns now: the money itself. */
            'top_sales' => collect([
                (object) ['sale_name' => 'Lamps', 'sale_image_path' => null, 'total_sale' => 4500],
            ]),
        ])->render();

        $expected = currency_format_with_sym(4500, getCurrentStore());
        $hundredth = currency_format_with_sym(45, getCurrentStore());

        $this->assertNotSame($expected, $hundredth,
            'the store currency formats 4500 and 45 identically, so this test cannot tell the two '
            . 'apart and proves nothing');
        $this->assertStringContainsString($expected, $html,
            'the figure is not printed as given, so the card is rescaling real money');
        $this->assertStringNotContainsString($hundredth, $html,
            'the `/100` is back while the cross join that justified it is gone, so every "By Sales" '
            . 'figure now reads a hundredth of the truth');
    }

    /**
     * The injected body must not claim to be a tab panel: it is replaced INTO
     * `#category-tab-data`, which is the panel the five tabs name.
     */
    public function test_the_injected_sales_body_declares_no_tab_panel_of_its_own(): void
    {
        $this->actingAs($this->merchant);

        $html = View::make('order.brand_category_chart', [
            'tab_name' => '#week-category-order',
            'top_sales' => collect(),
        ])->render();

        $this->assertStringNotContainsString('role="tabpanel"', $html);
        $this->assertStringNotContainsString('aria-labelledby', $html);
        $this->assertStringNotContainsString('id="#', $html,
            'the wrapper is still building an id out of the request\'s tabId, which begins with #');
    }

    public function test_the_sales_cards_are_hidden_from_a_principal_without_manage_order(): void
    {
        $body = $this->dashboardAs($this->unprivilegedStaff());

        $this->assertSame(0, substr_count($body, 'Top Category'),
            'a staff member without Manage Order was shown the store\'s order revenue broken '
            . 'down by category');
        $this->assertSame(0, substr_count($body, 'Top Brand'));
        $this->assertSame(0, substr_count($body, 'category-tab-data'),
            'the AJAX container is still on the page, so the sales request fires for a principal '
            . 'who may not read the figures');
    }

    public function test_the_sales_cards_are_shown_to_a_principal_who_holds_manage_order(): void
    {
        $body = $this->dashboardAs($this->merchant);

        $this->assertSame(1, substr_count($body, 'Top Category'));
        $this->assertSame(1, substr_count($body, 'Top Brand'));
    }

    public function test_the_sales_endpoint_refuses_a_principal_without_manage_order(): void
    {
        $response = $this->actingAs($this->unprivilegedStaff())
            ->post(route('top.brand.category.chart'), ['tabId' => '#all-category-order', 'type' => 'category']);

        $response->assertStatus(200);
        $response->assertExactJson(['html' => '', 'tab_name' => '', 'type' => '']);
    }

    /**
     * THE ALLOW PATH — AND IT CAN NOW PROVE WHAT IT ALWAYS WANTED TO.
     *
     * THIS TEST USED TO MEASURE A CRASH. The twelve queries used `SUBSTRING_INDEX`, which SQLite
     * does not have, so the statement could not execute in this suite at all — and that failure was
     * pressed into service as the DISTINGUISHER: reaching it proved the principal got past the
     * permission gate, which the refusal case above cannot show on its own.
     *
     * It worked, and it was a symptom. An endpoint whose only in-suite evidence is the exception it
     * raises has no coverage of what it returns. F242's fix removed the MySQL-only SQL
     * (`App\Support\TopSellersByRevenue` is portable PHP over `product_json`), so this case now
     * asserts the real payload: a 200 carrying the partial's own markup for the requested tab.
     *
     * The figures themselves are measured by `TopSellersRevenueAccuracyTest` in this suite and by
     * `verify-dashboard-top-sales-scope-on-mysql.php` on a real MariaDB.
     */
    public function test_the_sales_endpoint_admits_a_principal_who_holds_manage_order(): void
    {
        $response = $this->actingAs($this->merchant)
            ->post(route('top.brand.category.chart'), ['tabId' => '#all-category-order', 'type' => 'category']);

        $response->assertStatus(200);

        $this->assertSame('#all-category-order', $response->json('tab_name'),
            'the endpoint did not echo the requested tab, so the dashboard cannot place the result');
        $this->assertSame('category', $response->json('type'));

        /* The DISTINGUISHER, replacing the crash: a refused principal gets three empty strings
           (asserted in the case above), so a non-empty html proves this one got past the gate.
           The dev fixture has no orders in this test's database, so the partial renders its own
           empty state rather than rows -- which is still markup, and still distinguishable. */
        $this->assertNotSame('', (string) $response->json('html'),
            'the endpoint returned the refusal payload for a principal who holds Manage Order');
    }

    // ---------------------------------------------------------------------------------------
    //  4 — THE PERIOD TABS
    // ---------------------------------------------------------------------------------------

    public function test_every_element_id_on_the_dashboard_is_unique(): void
    {
        $body = $this->dashboardAs($this->merchant);

        preg_match_all('/\sid="([^"$#{][^"]*)"/', $body, $matches);
        $ids = $matches[1];

        $this->assertGreaterThan(20, count($ids),
            'almost no ids were found, so this census proved nothing');

        $duplicates = array_keys(array_filter(array_count_values($ids), fn ($n) => $n > 1));

        $this->assertSame([], $duplicates,
            'duplicate element ids on the dashboard: ' . implode(', ', $duplicates) . '. An id '
            . 'that appears twice makes every aria-labelledby / aria-controls naming it resolve '
            . 'to whichever copy comes first.');
    }

    public function test_every_tab_names_a_panel_that_exists_and_only_one_tab_is_selected(): void
    {
        $body = $this->dashboardAs($this->merchant);

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($body);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);

        $tabs = $xpath->query('//*[@role="tab"]');
        $this->assertGreaterThanOrEqual(10, $tabs->length,
            'the two period tab lists did not render, so this test proved nothing');

        foreach ($tabs as $tab) {
            $controls = $tab->getAttribute('aria-controls');
            $this->assertNotSame('', $controls, 'a tab declares no aria-controls');
            $this->assertSame(1, $xpath->query('//*[@id="' . $controls . '"]')->length,
                "a tab's aria-controls names \"{$controls}\", which is not an element on the page");
        }

        foreach (['pills-cat-tab', 'pills-brand-tab'] as $listId) {
            $selected = $xpath->query('//*[@id="' . $listId . '"]//*[@role="tab"][@aria-selected="true"]');
            $this->assertSame(1, $selected->length,
                "the \"{$listId}\" tab list reports {$selected->length} selected tabs; a screen "
                . 'reader announces every one of them as the current tab');
        }
    }

    public function test_the_dead_pills_seo_target_is_gone(): void
    {
        $this->assertSame(0, substr_count($this->dashboardAs($this->merchant), 'pills-seo'),
            'Week, Month and Year still share one id and point at a panel that does not exist');
    }

    // ---------------------------------------------------------------------------------------
    //  5 — THE STORAGE CARD
    // ---------------------------------------------------------------------------------------

    public function test_the_storage_card_does_not_claim_a_quota_of_zero(): void
    {
        $this->merchant->plan_id = null;
        $this->merchant->storage_limit = 12;
        $this->merchant->save();
        (new User)->flushCache();

        $body = $this->dashboardAs(User::find($this->merchant->id));

        $this->assertStringContainsString('Storage Status', $body);
        $this->assertStringNotContainsString('/ 0MB', $body,
            'the card still states a quota of 0MB, which reads as a limit already exceeded');
        $this->assertStringContainsString('12 MB used', $body);
        $this->assertStringNotContainsString('unlimited', $body,
            'a plan with NO recorded limit is being described as unlimited, which is a different '
            . 'statement');
        $this->assertStringNotContainsString('id="device-chart"', $body,
            'the percentage donut still renders with no quota to be a percentage of');
    }

    /**
     * `-1` IS WORKDO'S "UNLIMITED" SENTINEL — `Utility::updateStorageLimit()` refuses an upload
     * when `$plan->storage_limit <= $total_storage && $plan->storage_limit != -1`, so -1 is the
     * value that never refuses. The dev fixture's plan carries it, which is how the browser run
     * caught the card rendering "Storage Status (MB / -1MB)" above a 0% donut. "No limit recorded"
     * would be an improvement and still the wrong sentence: this plan HAS no limit, rather than
     * failing to state one.
     */
    public function test_the_storage_card_says_unlimited_rather_than_minus_one(): void
    {
        $plan = Plan::create([
            'name' => 'WS C unlimited plan',
            'price' => 0,
            'duration' => 'Unlimited',
            'storage_limit' => -1,
            'themes' => '',
        ]);

        $this->merchant->plan_id = $plan->id;
        $this->merchant->storage_limit = 12;
        $this->merchant->save();
        (new User)->flushCache();

        $body = $this->dashboardAs(User::find($this->merchant->id));

        $this->assertStringNotContainsString('-1MB', $body,
            'the card still prints the unlimited sentinel as a negative quota');
        $this->assertStringContainsString('12 MB used of unlimited storage', $body);
        $this->assertStringContainsString('unlimited storage, so there is no percentage', $body);
        $this->assertStringNotContainsString('id="device-chart"', $body,
            'a percentage donut renders against an unlimited quota, which is not a percentage');
    }

    public function test_the_storage_card_states_the_quota_when_the_plan_records_one(): void
    {
        $plan = Plan::create([
            'name' => 'WS C storage plan',
            'price' => 0,
            'duration' => 'Unlimited',
            'storage_limit' => 500,
            /* `plans.themes` is NOT NULL with no default. */
            'themes' => '',
        ]);

        $this->merchant->plan_id = $plan->id;
        $this->merchant->storage_limit = 12;
        $this->merchant->save();
        (new User)->flushCache();

        $body = $this->dashboardAs(User::find($this->merchant->id));

        $this->assertStringContainsString('12MB / 500MB', $body);
        $this->assertStringContainsString('id="device-chart"', $body,
            'the donut is missing although the plan records a limit to measure against');
        $this->assertStringNotContainsString('12 MB used', $body,
            'the no-quota wording is being shown alongside a real quota');
    }

    // ---------------------------------------------------------------------------------------
    //  THE PRODUCT LIST
    // ---------------------------------------------------------------------------------------

    public function test_the_latest_products_list_is_hidden_from_a_principal_without_manage_products(): void
    {
        $body = $this->dashboardAs($this->unprivilegedStaff());

        $this->assertSame(0, substr_count($body, 'Latest Products'),
            'a staff member without Manage Products was shown the newest products with prices, '
            . 'which the overview region withholds from the same principal');
    }

    public function test_the_latest_products_list_is_shown_to_a_principal_who_holds_manage_products(): void
    {
        $this->assertSame(1, substr_count($this->dashboardAs($this->merchant), 'Latest Products'));
    }

    // ---------------------------------------------------------------------------------------
    //  THE PRINCIPAL THAT IS NOT A MERCHANT AT ALL
    // ---------------------------------------------------------------------------------------

    /**
     * `isAbleTo()` IS A FATAL ON A CUSTOMER, NOT A FALSE. It comes from Laratrust's
     * `HasRolesAndPermissions`, which only `App\Models\User` uses. The reasoning that a Customer
     * never reaches the `web` guard is true of `CustomAuth` and false of `actingAs()`, which puts
     * whatever model it is given on the default guard because Laravel never type-checks the model
     * against the provider — and when `MerchantPageDirectory` made this same call without a type
     * guard, the resulting fatal landed on the storefront and turned 112 security tests into
     * 500s. This drives the condition directly rather than trusting the reasoning again.
     */
    public function test_a_customer_on_the_web_guard_is_refused_rather_than_fatally_erroring(): void
    {
        $customer = \App\Models\Customer::create([
            'name' => 'WS C probe',
            'email' => 'wsc-probe-' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            'store_id' => $this->store->id,
            'is_enable_login' => 1,
        ]);

        $this->actingAs($customer);

        $this->assertFalse(\App\Support\MerchantDashboardAbilities::mayManageOrders(),
            'a Customer is being granted a merchant permission');
        $this->assertFalse(\App\Support\MerchantDashboardAbilities::mayManageProducts());
        $this->assertFalse(\App\Support\MerchantDashboardAbilities::mayManageCustomers());
        $this->assertFalse(\App\Support\MerchantDashboardAbilities::mayManageStoreSettings());
        $this->assertFalse(\App\Support\MerchantDashboardAbilities::isOperator());
    }

    public function test_a_guest_is_refused_rather_than_fatally_erroring(): void
    {
        $this->assertFalse(\App\Support\MerchantDashboardAbilities::mayManageOrders());
        $this->assertFalse(\App\Support\MerchantDashboardAbilities::isOperator());
    }
}
