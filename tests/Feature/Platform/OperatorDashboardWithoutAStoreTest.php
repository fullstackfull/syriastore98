<?php

namespace Tests\Feature\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\StripsSourceComments;

/**
 * AN OPERATOR WHOSE `current_store` DOES NOT RESOLVE COULD NOT OPEN THE DASHBOARD AT ALL: HTTP 500.
 * =============================================================================================
 *
 * NOT A DISCOVERY -- A RECORDED OPEN FINDING, REPAIRED. I first wrote this up as something found
 * on 2026-11-22 while building the top-stores fixture. It had been measured and recorded before:
 * `OperatorImplicitStoreFinancialGuardTest::test_the_refusal_does_not_strand_the_operator`
 * asserted this 500 as "a recorded open finding, not a requirement", with the same diagnosis, and
 * asked whoever repaired it to change that case rather than delete it. Independent review of this
 * change pointed at it; that case is updated in the same commit. The finance guard redirects an
 * unchosen operator to `/dashboard`, so until this repair the refusal landed on an error page and
 * its message was never seen.
 *
 *   SQLSTATE[HY000]: General error: 1 no such column: total_user
 *
 * TWO THINGS HAD TO BE TRUE AT ONCE, and each looks harmless alone.
 *
 * (1) `handleSuperAdmin()` DECORATED THE LIVE AUTHENTICATED MODEL. `$user['total_user'] = …`,
 *     `$user['total_orders'] = …`, `$user['total_plan'] = …` set three attributes that are not
 *     columns on the very `User` instance the session guard holds. Nothing goes wrong until
 *     something saves that user in the same request.
 *
 * (2) `getOrderChart()` LOGGED OUT ANY USER WITHOUT A RESOLVABLE STORE — the operator included,
 *     although the operator's branch of that method reads `PlanOrder` and never touches the store
 *     it had just demanded. `logout()` cycles the remember token, which SAVES the user, which now
 *     tried to `UPDATE users SET total_user = …`.
 *
 * So the most privileged account on the platform, on its landing page, got a 500 the moment its
 * selected store was deleted or was never set. And `ReportController::financeReport()` answers an
 * unchosen operator with `redirect()->route('dashboard')->with('error', 'No store is selected.')`
 * — it sends them to the one page that could not render for them.
 *
 * Had the decoration not been there, (2) alone would have signed the operator out of the platform
 * for lacking something their dashboard does not use, and then rendered the view anyway with a
 * RedirectResponse where the chart data should be.
 *
 * The merchant path is untouched: a merchant or staff member with no resolvable store is still
 * signed out by `getOrderChart()`, as before. Only the principal whose branch never reads the
 * store is excused from having one.
 */
class OperatorDashboardWithoutAStoreTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    public function test_an_operator_with_no_store_can_open_the_dashboard(): void
    {
        $operator = $this->makePlatformAdmin(['current_store' => null]);
        Cache::forget('admin_details');

        $response = $this->actingAs($operator->fresh())->get('/dashboard');

        $response->assertOk();
        $this->assertAuthenticatedAs($operator->fresh(), 'web');
    }

    public function test_an_operator_whose_selected_store_was_deleted_can_open_the_dashboard(): void
    {
        $operator = $this->makePlatformAdmin(['current_store' => 987654]);

        /*
         * NO MERCHANT IS CREATED, AND AN EARLIER NOTE HERE SAID ONE WAS NEEDED. It claimed a
         * platform with an operator and no merchant answers 500 from `getAdminAllSetting()`, whose
         * last branch reads `User::where('type', 'admin')->first()->id` unchecked. That branch is
         * real, but it is reached only by a request with NO authenticated user -- which is what
         * this request BECAME halfway through, when the layout helper signed the operator out.
         * With that sign-out gone the branch is not reached. Re-measured after review questioned
         * the note: this case passes on an empty platform, so the note was a misdiagnosis of the
         * defect being fixed, not a second defect.
         */
        Cache::forget('admin_details');

        $response = $this->actingAs($operator->fresh())->get('/dashboard');

        $response->assertOk();
        $this->assertAuthenticatedAs($operator->fresh(), 'web');
    }

    /**
     * ALL THREE TOTALS, AND THE CARD EACH IS WIRED TO. The first version value-checked `stores`
     * alone, against the handler's own expression, and never looked at the page -- so swapping two
     * cards' bindings, or dropping one, would have passed. The counts are made DISTINCT so that a
     * number under the wrong label cannot match by coincidence.
     */
    public function test_the_platform_totals_reach_the_view_and_each_sits_under_its_own_label(): void
    {
        $operator = $this->makePlatformAdmin(['current_store' => null]);
        $this->makeTenant();
        $this->makeTenant();

        foreach (range(1, 7) as $n) {
            \Illuminate\Support\Facades\DB::table('plan_orders')->insert([
                'order_id' => 'PO-' . $this->uniqueFixtureRef(), 'name' => 'x', 'email' => 'x@example.test',
                'plan_name' => 'p', 'plan_id' => 1, 'price' => 1, 'price_currency' => 'USD', 'txn_id' => 't' . $n,
                'payment_status' => 'succeeded', 'payment_type' => 'manual', 'receipt' => '', 'user_id' => 1,
                'store_id' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        (new \App\Models\PlanOrder)->flushCache();
        Cache::forget('admin_details');

        $expected = [
            'stores' => (int) $operator->fresh()->countCompany(),
            'orders' => (int) \App\Models\PlanOrder::count(),
            'plans' => (int) \App\Models\Plan::count(),
        ];

        $this->assertSame(7, $expected['orders'], 'PREMISE: the seven plan orders were not inserted.');
        $this->assertCount(3, array_unique($expected),
            'PREMISE: two of the three totals are equal (' . json_encode($expected) . '), so a '
            . 'number under the wrong label would still match and this test could not see it.');

        $response = $this->actingAs($operator->fresh())->get('/dashboard');
        $response->assertOk();

        $totals = $response->viewData('platformTotals');

        $this->assertSame(['stores', 'orders', 'plans'], array_keys($totals));
        $this->assertSame($expected, array_map('intval', $totals));

        $body = (string) $response->getContent();

        foreach ([__('Total Store') => $expected['stores'], __('Total Plan Orders') => $expected['orders'], __('Total Plans') => $expected['plans']] as $label => $count) {
            $this->assertMatchesRegularExpression(
                '/' . preg_quote($label, '/') . '<\/h2>\s*<h3[^>]*>\s*' . $count . '\s*<\/h3>/u',
                $body,
                "The card labelled '{$label}' does not show {$count}."
            );
        }
    }

    /**
     * THE DECORATION IS THE HAZARD, WHATEVER HAPPENS TO SAVE THE USER NEXT. Asserted on the model
     * the view receives: no attribute on it may be something `users` has no column for, because
     * the next `save()` anywhere in the request would fail exactly as this one did.
     */
    public function test_the_authenticated_user_is_not_decorated_with_attributes_that_are_not_columns(): void
    {
        $operator = $this->makePlatformAdmin(['current_store' => null]);
        Cache::forget('admin_details');

        $response = $this->actingAs($operator->fresh())->get('/dashboard');
        $response->assertOk();

        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('users');
        $carried = array_keys($response->viewData('user')->getAttributes());

        $this->assertSame([], array_values(array_diff($carried, $columns)),
            'The dashboard handler set attributes on the authenticated User that are not columns. '
            . 'Any save of that user later in the request fails with an unknown-column error.');
    }

    /*
     * A MERCHANT WITH NO RESOLVABLE STORE IS STILL SIGNED OUT -- AND THERE ARE TWO MECHANISMS, SO
     * THERE ARE TWO TESTS.
     *
     * The first version of this file had one case: a merchant with a dangling selection, asserted
     * signed out after `/dashboard`. Independent review traced why it could not fail for either
     * mechanism alone. `handleRegularUser()` calls `APP_THEME()` first, which signs the merchant
     * out; `getOrderChart()` then finds no user and returns before reaching its own check. So
     *
     *   - excusing EVERYBODY in `getOrderChart()` passed, because the helper had already signed out;
     *   - excusing EVERYBODY in `APP_THEME()` passed, because `getOrderChart()` then signed out.
     *
     * Both are mutations in the direction that matters -- a principal kept signed in who should
     * not be -- and both survived. The class docblock credited the wrong mechanism, too. Each case
     * below is built so that exactly ONE of the two can produce the sign-out.
     */

    /**
     * ONLY `getOrderChart()` CAN SIGN THIS ONE OUT. With `current_store` null, `APP_THEME()` takes
     * its empty-selection branch and signs nobody out, so the chart helper's check is the sole
     * mechanism. The response is the recorded 500 (see `OperatorImplicitStoreFinancialGuardTest`);
     * what is asserted here is the session.
     */
    public function test_the_chart_helper_signs_out_a_merchant_who_has_no_store_at_all(): void
    {
        $merchant = \App\Models\User::find($this->makeMerchant(['current_store' => null])->id);

        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('stores')->where('created_by', $merchant->id)->count(),
            'PREMISE: this merchant owns a store, so the chart helper has something to resolve.');

        $this->actingAs($merchant);
        $this->assertNotSame('', (string) APP_THEME(),
            'PREMISE: the layout helper took its sign-out branch for an EMPTY selection, so this '
            . 'case no longer isolates the chart helper.');
        $this->assertAuthenticatedAs($merchant, 'web');

        Cache::forget('admin_details');
        $this->actingAs($merchant->fresh())->get('/dashboard');

        $this->assertGuest('web');
    }

    /**
     * ONLY `APP_THEME()` IS CALLED HERE. A merchant whose selected store no longer exists is
     * signed out by the layout helper itself -- the designed answer to losing their store, and
     * the branch the operator is now excused from.
     */
    public function test_the_layout_helper_signs_out_a_merchant_whose_selected_store_was_deleted(): void
    {
        [$merchant] = $this->makeTenant();

        $merchant->current_store = 987654;
        $merchant->save();

        $this->actingAs($merchant->fresh());
        $this->assertAuthenticated('web');

        APP_THEME();

        $this->assertGuest('web');
    }

    /** And the same call leaves the OPERATOR signed in, which is the whole of the change to that helper. */
    public function test_the_layout_helper_does_not_sign_out_an_operator_whose_selected_store_was_deleted(): void
    {
        $operator = $this->makePlatformAdmin(['current_store' => 987654]);

        $this->actingAs($operator->fresh());

        $this->assertSame('stylique', APP_THEME());
        $this->assertAuthenticated('web');
    }

    /**
     * P5-R7 ITEM 2 -- THE CARD COUNTS SAAS SUBSCRIPTION ORDERS, NOT CUSTOMER ORDERS.
     *
     * `PlanOrder::total_orders()` is `PlanOrder::count()` over `plan_orders`, and the card links to
     * `plan.index`. On an operator dashboard, beside "Total Store", a card reading "Total Orders"
     * is read as the customer orders placed across the platform. It is not that figure and cannot
     * be made into one: `store_order_financials.order_id` is a foreign key to the CUSTOMER `orders`
     * table, so a `plan_orders` row can never have a ledger row and no money counterpart can be
     * built for this card at all.
     *
     * The bare label is asserted ABSENT as well, because relabelling only works if the old wording
     * is gone -- the sibling cards are "Total Store", "Total Plans", "Total Plan Request", and a
     * stray "Total Orders" anywhere on this screen re-creates the confusion.
     */
    public function test_the_operator_plan_order_card_does_not_claim_to_count_customer_orders(): void
    {
        $operator = $this->makePlatformAdmin(['current_store' => null]);

        $body = (string) $this->actingAs($operator->fresh())->get('/dashboard')->getContent();

        $this->assertStringContainsString(__('Total Plan Orders'), $body,
            'the operator dashboard does not name the plan-order card, so a count of SaaS '
            . 'subscription rows is presented under some other meaning');

        $this->assertDoesNotMatchRegularExpression(
            '/>\s*' . preg_quote(__('Total Orders'), '/') . '\s*</u',
            $body,
            'a card on the operator dashboard still reads "Total Orders", which an operator reads '
            . 'as customer orders across the platform -- a figure this screen does not show'
        );
    }
}
