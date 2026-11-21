<?php

namespace Tests\Feature\Reports;

use App\Http\Middleware\ReportPermission;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * ANA-001 -- FINANCE IS IN THE REPORTS CENTRE, AND NO WIDER AUDIENCE CAN SEE IT THAN BEFORE.
 * =================================================================================================
 *
 * The traceability row measured the gap as discoverability: "FINANCE is not in the centre. The
 * Reports group has no finance screen and no finance permission." The figures themselves already
 * existed at `/finance` behind `View Finance Dashboard`.
 *
 * SO THE AUTHORIZATION IS THE RISK, NOT THE FIGURES. `ReportPermission::ACTIONS` falls back to
 * `Manage Reports` for an unmapped action, so the lazy version of this change -- a route in the
 * reports group and nothing else -- would have shown a tenant's money to every principal holding a
 * reports permission. That is a strictly WIDER audience than the screen the figures come from, which
 * is why the new action is mapped to `View Finance Dashboard` and why the negative test below is the
 * one that matters: a merchant with `Manage Reports` and `Manage Order Reports` but NOT the finance
 * permission must be refused.
 *
 * NOTHING IS RECOMPUTED. The assertions read the same assemblers `/finance` uses, so a drift between
 * the two screens is impossible by construction rather than by review.
 *
 * TWO OF THIS SLICE'S GUARDS ARE REDUNDANT ON PURPOSE, and two mutations survive because of it --
 * recorded here rather than dressed up as coverage. Mapping the action to `Manage Reports` survives
 * the refusal test because `finance.permission` on the route refuses that principal anyway; dropping
 * the `OperatorStoreSelection::unchosen()` guard survives because `reportStoreId()` returns
 * `NO_STORE` for that principal and the `$storeId <= 0` check below refuses it. Each layer can
 * regress without changing the OUTCOME, which is what defence in depth means and is the reason both
 * are there. The mapping is pinned by a direct assertion instead, and dropping BOTH gates is what a
 * mutation has to do to change behaviour.
 */
class FinanceReportInTheCentreTest extends SecurityTestCase
{
    use RefreshDatabase;
    use \Tests\Support\StripsSourceComments;

    private const URI = '/finance-reports';

    private function merchantWith(array $permissions): array
    {
        [, $decoy] = $this->makeTenant();          // store 1 is a decoy, never the tenant under test
        [$merchant, $store] = $this->makeTenant();

        $this->assertGreaterThan(1, (int) $store->id,
            'premise: the tenant under test is not store 1, whose id a wrong-tenant read would hard-code');

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission], ['display_name' => $permission]);
            $this->grantPermission(User::find($merchant->id), $permission);
        }

        Cache::forget('admin_details');

        return [User::find($merchant->id), $store, $decoy];
    }

    /** Refusal as the report tests define it: a 403, or a redirect carrying session('error'). */
    private function refused($response): bool
    {
        return $response->status() === 403
            || ($response->isRedirect() && session()->has('error'));
    }

    // ---------------------------------------------------------------------------------------------
    // The authorization boundary -- the point of the slice
    // ---------------------------------------------------------------------------------------------

    public function test_the_finance_report_action_is_mapped_to_the_finance_permission_not_a_reports_one(): void
    {
        $this->assertArrayHasKey('financeReport', ReportPermission::ACTIONS,
            'an unmapped report action falls back to `Manage Reports`, which would widen who sees money');

        $this->assertSame('View Finance Dashboard', ReportPermission::ACTIONS['financeReport'],
            'the centre entry must require the SAME permission as /finance; the screen moves, the audience does not');

        $this->assertNotSame('Manage Reports', ReportPermission::ACTIONS['financeReport']);
        $this->assertNotSame('Manage Order Reports', ReportPermission::ACTIONS['financeReport']);
    }

    public function test_a_merchant_with_every_reports_permission_but_not_the_finance_one_is_refused(): void
    {
        [$merchant] = $this->merchantWith(['Manage Reports', 'Manage Order Reports', 'Manage Stock Reports']);

        $response = $this->actingAs($merchant)->get(self::URI);

        $this->assertTrue($this->refused($response),
            'holding reports permissions must NOT grant a tenant\'s financial figures -- that is the widening this slice exists to avoid');
    }

    public function test_a_merchant_with_the_finance_permission_is_admitted(): void
    {
        [$merchant] = $this->merchantWith(['View Finance Dashboard']);

        $response = $this->actingAs($merchant)->get(self::URI);

        $this->assertFalse($this->refused($response), 'the finance permission must admit this screen');
        $this->assertSame(200, $response->status());
    }

    public function test_an_anonymous_caller_is_sent_to_sign_in_and_not_given_the_figures(): void
    {
        $response = $this->get(self::URI);

        $this->assertTrue($response->isRedirect(), 'an unauthenticated request must not render the screen');
        $this->assertStringNotContainsString('Payments received', (string) $response->getContent());
    }

    // ---------------------------------------------------------------------------------------------
    // The figures, and that they are the SAME ones /finance shows
    // ---------------------------------------------------------------------------------------------

    public function test_the_screen_shows_three_of_decision_d2s_four_labels(): void
    {
        [$merchant] = $this->merchantWith(['View Finance Dashboard']);

        $body = html_entity_decode(
            (string) $this->actingAs($merchant)->get(self::URI)->getContent(),
            ENT_QUOTES | ENT_HTML5
        );

        foreach (['Payments received', 'Estimated gross profit', 'Available to withdraw'] as $label) {
            $this->assertStringContainsString($label, $body, "D2's label `{$label}` is missing from the centre");
        }

        /*
         * AND NOT THE FOURTH. `Order value` belongs beside the demand figures on the sales report;
         * putting it here would invite exactly the comparison D2 exists to prevent -- order value
         * against payments received read as missing money, when the difference is unpaid COD. The
         * ANA-005 row records that no single screen yet shows all four, and this change does not
         * quietly resolve that.
         */
        $this->assertStringNotContainsString('Order value', $body,
            'the fourth D2 figure is deliberately not on this card -- see the view comment');
    }

    public function test_the_figures_come_from_the_same_assembler_the_finance_screen_uses(): void
    {
        [$merchant, $store] = $this->merchantWith(['View Finance Dashboard']);

        DB::table('settings')->insert([
            'name' => 'defult_currancy', 'value' => 'SYP', 'created_by' => $merchant->id,
            'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        (new \App\Models\Setting)->flushCache();

        $expected = \App\Support\Metrics\FinanceLedgerFigures::forStore((int) $store->id, 'SYP');
        $wallet = \App\Support\Metrics\WalletEntitlementFigure::forStore((int) $store->id, 'SYP');

        $body = html_entity_decode(
            (string) $this->actingAs($merchant)->get(self::URI)->getContent(),
            ENT_QUOTES | ENT_HTML5
        );

        /*
         * Compared against the ASSEMBLER rather than a literal, which is the only form that can
         * prove the two screens agree: a hard-coded expectation would pass while the centre drifted.
         */
        foreach ([$expected['payments'], $expected['profit'], $wallet] as $tile) {
            if ($tile['value'] === null) {
                continue;   // an unavailable figure renders as a state, not a number
            }
            $this->assertStringContainsString(
                str_replace(["\u{2066}", "\u{2069}"], '', (string) $tile['value']),
                str_replace(["\u{2066}", "\u{2069}"], '', $body),
                'the centre shows a different figure from the assembler /finance reads'
            );
        }
    }

    public function test_another_tenants_figures_never_reach_this_screen(): void
    {
        [$merchant, , $decoy] = $this->merchantWith(['View Finance Dashboard']);

        // The decoy is store 1, with a recognisable amount nothing else should print.
        $order = $this->makeOrder($decoy, ['order_date' => now()->subDays(2)->toDateTimeString()]);
        DB::table('store_order_financials')->where('order_id', $order->id)->update([
            'payment_status' => 'paid', 'paid_amount' => '77777.77', 'paid_at' => now()->subDay(),
            'currency_code' => 'USD', 'updated_at' => now(),
        ]);

        $body = (string) $this->actingAs($merchant)->get(self::URI)->getContent();

        $this->assertStringNotContainsString('77,777.77', $body, "store 1's payments are on this merchant's screen");
        $this->assertStringNotContainsString('77777.77', $body);
    }

    public function test_the_currency_is_this_stores_own_and_not_whichever_setting_row_comes_first(): void
    {
        [$merchant, $store, $decoy] = $this->merchantWith(['View Finance Dashboard']);

        /*
         * CLOSING A REAL COVERAGE GAP, found by mutation: dropping the `store_id` predicate from the
         * currency read SURVIVED, because only one store carried a `defult_currancy` row and both
         * spellings therefore returned the same value. `Setting` is NOT in `config/tenancy.php`'s
         * `scoped_models`, so no global scope narrows that read -- the call-site predicate is the
         * only one there is, and a test with a single row cannot tell whether it is present.
         *
         * The decoy's row is written FIRST and with a DIFFERENT currency, so an unscoped read returns
         * the decoy's and this screen prints the wrong money's name.
         */
        foreach ([[$decoy, 'EUR'], [$store, 'SYP']] as [$target, $code]) {
            DB::table('settings')->insert([
                'name' => 'defult_currancy', 'value' => $code, 'created_by' => $target->created_by,
                'store_id' => $target->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        (new \App\Models\Setting)->flushCache();

        $this->assertSame('EUR', DB::table('settings')->where('name', 'defult_currancy')
            ->orderBy('id')->value('value'), 'premise: an unscoped read would find the decoy row first');

        $body = str_replace(["\u{2066}", "\u{2069}"], '', html_entity_decode(
            (string) $this->actingAs($merchant)->get(self::URI)->getContent(),
            ENT_QUOTES | ENT_HTML5
        ));

        $this->assertStringNotContainsString('EUR', $body,
            "the screen names the decoy store's currency, so the currency read is not scoped to this store");
    }

    public function test_an_operator_who_has_chosen_no_store_is_refused_rather_than_shown_store_one(): void
    {
        [, $decoy] = $this->makeTenant();
        $operator = $this->makePlatformAdmin(['current_store' => null]);
        Permission::firstOrCreate(['name' => 'View Finance Dashboard'], ['display_name' => 'View Finance Dashboard']);
        Cache::forget('admin_details');

        $this->assertSame(1, (int) $decoy->id, 'premise: the decoy is the store the literal 1 would resolve to');

        $response = $this->actingAs(User::find($operator->id))->get(self::URI);

        // GAP-223(b): money, so the refusal is explicit here rather than an empty scope.
        $this->assertTrue($this->refused($response),
            'a storeless operator must be refused this screen, not served store 1 figures');
    }

    public function test_the_centre_links_to_it_under_the_same_permission_the_route_enforces(): void
    {
        $menu = $this->sourceWithoutComments('app/Listeners/CompanyMenuListener.php');

        $i = strpos($menu, "'route' => 'reports.finance'");
        $this->assertNotFalse($i, 'the Reports centre has no finance entry, which is the ANA-001 gap itself');

        $entry = substr($menu, max(0, $i - 400), 600);

        $this->assertStringContainsString("'parent' => 'reports'", $entry, 'the entry must sit under the Reports group');
        $this->assertStringContainsString("'permission' => 'View Finance Dashboard'", $entry,
            'the menu must name the permission the route enforces: a different one either hides the screen '
            . 'from people who may open it, or advertises it to people who may not');
    }
}
