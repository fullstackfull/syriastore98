<?php

namespace Tests\Feature\Reports;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * ANA-007(d) — THE PRODUCT'S ONE DEDICATED DATA-QUALITY SCREEN WAS REACHABLE ONLY BY TYPING ITS URL.
 * =============================================================================================
 *
 * `ANA-007` is the requirement to SHOW data quality, estimates and omissions.
 * `/finance/unreconciled-captures` is the only screen in the product whose entire purpose is to
 * show a data-quality problem: payments a gateway reports as captured that no order can be matched
 * to. It renders a `role=status` warning and lists the rows.
 *
 * MEASURED 2026-11-22: the route exists (`routes/web.php:240`, gated
 * `finance.permission:View Finance Dashboard`) and **nothing anywhere links to it** — not the
 * finance screen, not the navigation config, not any other view. A screen a merchant cannot find
 * does not show them anything, so for this requirement it was ABSENT.
 *
 * WHY THIS NEEDED NO OWNER DECISION. The screen, its permission and its content all already exist
 * and are unchanged. This adds one link, behind the permission the route already enforces. No
 * threshold, cadence or monetary definition is involved, and no figure changes.
 *
 * THE GATE IS THE ROUTE'S OWN, AND IT IS A REAL LARAVEL GATE RATHER THAN A LARATRUST PERMISSION.
 * `finance.permission` resolves `Gate::allows('finance', $permission)`, defined at
 * `AuthServiceProvider.php:109-123`: it returns true for a platform operator (`type ===
 * PLATFORM_ADMIN`) BEFORE consulting Laratrust, and otherwise falls through to
 * `$user->isAbleTo($permission)`.
 *
 * So `@can('finance', 'View Finance Dashboard')` in the view asks EXACTLY the question the route
 * will answer. `@permission('View Finance Dashboard')` would not: it is `isAbleTo()` alone and
 * would hide the link from the operator, who the route admits.
 *
 * CLAUDE.md's rule that `@can` is always false is about LARATRUST PERMISSIONS used as gates
 * (`permissions_as_gates => false`). It does not apply to a Gate ability that is explicitly
 * defined, which this one is — checked before relying on it, because getting that backwards would
 * render the link for nobody.
 *
 * NOTE, corrected after reading the definition: this gate does NOT specially admit a tenant owner.
 * An owner passes only if their role carries the permission. A first draft of this file claimed
 * otherwise.
 *
 * MUTATION EVIDENCE (scratchpad `mutate_unrec.py`, four mutations, 2026-11-22).
 *   U1 link removed            -> CAUGHT
 *   U2 label dropped           -> CAUGHT
 *   U3 `@can` -> `@permission` -> CAUGHT **only after this file gained the operator test**.
 *      It survived the first run because every other test here drives a merchant who holds the
 *      permission and therefore passes BOTH directives. The mutation is invisible to any
 *      merchant-driven assertion; it is visible only to the principal the two directives
 *      disagree about.
 *   U4 gate removed entirely   -> SURVIVES, and is recorded rather than filtered away.
 *      It is an EQUIVALENT MUTANT BY DESIGN: `routes/web.php:212` applies
 *      `finance.permission:View Finance Dashboard`, and that middleware resolves the same
 *      `finance` gate, so a principal who fails it never renders this view at all. The `@can`
 *      here is redundant defence-in-depth against a future route change, which no test can
 *      exercise while the route gate stands.
 *      `test_the_finance_screen_itself_refuses_a_principal_without_the_permission` pins that
 *      route gate: if it is ever removed, U4 becomes catchable and this note becomes wrong.
 */
class UnreconciledCapturesIsReachableTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function merchantWithFinance(): array
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'View Finance Dashboard');

        $merchant->plan_id = DB::table('plans')->insertGetId([
            'name' => 'fin-plan-' . $this->uniqueFixtureRef(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'off',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->save();
        \Illuminate\Support\Facades\Cache::forget('admin_details');

        return [$merchant->fresh(), $store];
    }

    public function test_the_finance_screen_links_to_the_unreconciled_captures_review(): void
    {
        [$merchant] = $this->merchantWithFinance();

        $response = $this->actingAs($merchant)->get('/finance');

        $response->assertStatus(200);
        $response->assertSee(route('finance.unreconciled.captures'), false);
    }

    /** The link must name what it is, in words a merchant can act on — not a bare URL. */
    public function test_the_link_is_labelled_and_translated(): void
    {
        [$merchant] = $this->merchantWithFinance();

        $response = $this->actingAs($merchant)->get('/finance');

        $response->assertSee(__('Review unmatched payments'));

        $ar = json_decode((string) file_get_contents(base_path('resources/lang/ar.json')), true);
        $this->assertArrayHasKey('Review unmatched payments', $ar,
            'The link has no Arabic, so an Arabic merchant reads an English control on an RTL page.');
    }

    /**
     * THE LINK MUST RESOLVE, not merely appear. A link to a route the merchant is then refused is
     * worse than no link: it reads as a broken product rather than as a permission boundary.
     */
    public function test_the_linked_screen_opens_for_the_merchant_who_is_shown_the_link(): void
    {
        [$merchant] = $this->merchantWithFinance();

        $shown = $this->actingAs($merchant)->get('/finance');
        $shown->assertSee(route('finance.unreconciled.captures'), false);

        $opened = $this->actingAs($merchant)->get(route('finance.unreconciled.captures'));

        $this->assertSame(200, $opened->status(),
            'The merchant is shown a link to a screen that then refuses them (HTTP ' . $opened->status() . ').');
    }

    /**
     * THE REAL CONTROL IS THE PAGE'S OWN GATE, AND THE LINK'S `@can` IS REDUNDANT TODAY.
     *
     * A first version of this test asserted "a merchant without the permission is not shown the
     * link", with an `if (status === 200) ... else assertTrue(true)` escape hatch. **That test
     * could not fail**, which is the failure mode this project keeps paying for, and a mutation
     * run proved it: removing the `@can` wrapper entirely left it green.
     *
     * The reason is structural, not a test defect to patch around: `/finance` itself carries
     * `finance.permission:View Finance Dashboard` (`routes/web.php:212`) — the SAME gate. A
     * principal who fails it never reaches the page, so there is no reachable state in which the
     * link could leak to someone the route would refuse. The `@can` is defence in depth against a
     * future in which the two gates differ, and it is kept for that reason and labelled as such
     * rather than credited with a protection it does not currently provide.
     *
     * So this test asserts the control that IS load-bearing.
     */
    public function test_the_finance_screen_itself_refuses_a_principal_without_the_permission(): void
    {
        [$merchant] = $this->makeTenant();

        $merchant->plan_id = DB::table('plans')->insertGetId([
            'name' => 'nofin-plan-' . $this->uniqueFixtureRef(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'off',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->save();
        \Illuminate\Support\Facades\Cache::forget('admin_details');

        $response = $this->actingAs($merchant->fresh())->get('/finance');

        $this->assertNotSame(200, $response->status(),
            'A principal with no View Finance Dashboard permission opened the finance dashboard. '
            . 'That is the control the link relies on; if it ever stops holding, the link\'s own @can becomes load-bearing.');
    }

    /**
     * THE OPERATOR MUST SEE THE LINK, and this is what makes `@can('finance', ...)` the right gate
     * rather than `@permission('View Finance Dashboard')`.
     *
     * `Gate::define('finance', ...)` (`AuthServiceProvider:109-123`) returns true for a platform
     * operator BEFORE consulting Laratrust. `@permission` is `isAbleTo()` alone, and Laratrust has
     * no super-admin bypass — so swapping one for the other would hide this link from the one
     * principal the route most reliably admits.
     *
     * A mutation proved this test was missing: swapping `@can` for `@permission` left every other
     * test in this file green, because they all drive a merchant who holds the permission and
     * therefore passes both.
     */
    public function test_the_platform_operator_is_shown_the_link(): void
    {
        [$merchant, $store] = $this->merchantWithFinance();

        $operator = $this->makePlatformAdmin();

        /*
         * THE OPERATOR MUST HAVE SELECTED A STORE. With `current_store` unset, `/finance` refuses
         * them -- and that refusal is CORRECT and deliberate: GAP-223(b) is the rule that an
         * operator with no explicitly selected store must never default to viewing store 1's
         * tenant data. So the fixture selects one, which is the state a real operator reviewing a
         * store is in. (A first version of this test omitted that and SKIPPED, which is not
         * evidence and would also have broken the known-outcome gate's declared skip set.)
         */
        $operator->current_store = $store->id;
        $operator->save();
        \Illuminate\Support\Facades\Cache::forget('admin_details');

        $response = $this->actingAs($operator->fresh())->get('/finance');

        $this->assertSame(200, $response->status(),
            'Premise: the operator cannot open /finance even with a store selected, so the link below is not what is being measured.');

        $response->assertSee(route('finance.unreconciled.captures'), false);
    }
}
