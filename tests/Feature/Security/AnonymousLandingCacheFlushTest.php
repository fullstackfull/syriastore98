<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F215 — AN ANONYMOUS `GET /` RAN `cache:clear`, SO A STRANGER COULD ERASE EVERY GATEWAY-RETURN
 * REPLAY CLAIM ON THE PLATFORM.
 * =================================================================================================
 *
 * F188 removed three unauthenticated `cache:clear` primitives from the language-switch handlers and
 * its ledger row then said, twice, that *"the unauthenticated primitive is gone; the operator-triggered
 * flush remains"*. **A fourth one survived, on the root route, and it is the worst of them** — found
 * by a verification pass over F188's own claim rather than by the suites.
 *
 * THE CHAIN, EVERY LINK MEASURED
 * -------------------------------------------------------------------------------------------------
 *   1. `routes/web.php:393` — `Route::any('/', [HomeController::class, 'Landing'])` with
 *      `->middleware('setlocate')` and nothing else. **No `auth`. No `throttle`.** `Route::any`, so
 *      a GET is enough and there is no CSRF token to obtain.
 *   2. `HomeController::Landing()` — when `display_landing == 'on'` it runs
 *      `Artisan::call('package:migrate LandingPage')` and then
 *      `Artisan::call('package:seed LandingPage')`, **above** `return view(...)`, so the calls land
 *      even on a request that then renders nothing.
 *   3. `database/seeders/DefaultSetting.php:28` — `"display_landing" => "on"` is the **seeded
 *      default**, so this is the shipped configuration rather than an unusual one.
 *   4. `LandingPageDatabaseSeeder:19` — `$this->call(PermissionTableSeeder::class)`.
 *   5. `PermissionTableSeeder:22` — `Artisan::call('cache:clear')`, its **first** statement.
 *   6. `app/Providers/PackageServiceProvider.php:31` — `LandingPage` is in `$staticAddons`, so it
 *      loads on **every** request with no `add_on_managers` row. The usual reason a WorkDo module is
 *      unreachable does not apply to this one.
 *
 * WHY IT IS A PAYMENT DEFECT AND NOT A PERFORMANCE ONE
 * -------------------------------------------------------------------------------------------------
 * `cache:clear` is `Cache::flush()` over the default store. `GuardsGatewayReturn::claimGatewayReturn()`
 * takes its replay claim there with **`Cache::add()`**, and `Cache::add()` on a missing key returns
 * **true** — so an erased claim is not a missing record the control notices, it is an **un-taken**
 * claim, indistinguishable from a first arrival (this is F211's measurement, on MariaDB). The claim
 * carries a 48-hour TTL precisely so a replay cannot slip through later.
 *
 * `PaymentController::getProductStatus()` — reached by `store.payment.status`, which
 * `app/Services/PaymentService.php` names **48 times** as a `return_url`, `cancel_url` or
 * `callback_url` across the gateway catalogue — takes that claim at `PaymentController.php:2923` and
 * makes **no signature or verification call of its own**. The claim is that handler's only replay
 * control. And `releaseGatewayReturnClaim()` has exactly **one** call site in all of `app/`
 * (`NMIPayController.php:1526`), so nothing else touches those keys either.
 *
 * So: a stranger hitting the home page clears the replay claims for every gateway return in flight,
 * platform-wide, for free, as often as they like.
 *
 * THIS ONE IS NOT HIDDEN BY THE SUITE'S CACHE DRIVER, AND THAT IS WHY IT CAN BE TESTED HERE.
 * F194's whole-store flush is invisible under `phpunit.xml`'s `CACHE_STORE=array`, because
 * `ArrayStore` extends `TaggableStore` and laravel-model-caching's flush is therefore tag-scoped.
 * `cache:clear` is different: it is an explicit `Cache::flush()` on the store itself, which the array
 * store honours. The defect is engine-independent and a Feature test can see it.
 */
final class AnonymousLandingCacheFlushTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The real key shape, from `GuardsGatewayReturn::gatewayReturnClaimKeys()`. */
    private const CLAIM = 'gateway-return-claim:payment:stripe:1:0000000000000000000000000000000000000000';

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * THE FIXTURE HAD TO BE CORRECTED, AND THE PREMISE CASE BELOW IS WHAT CAUGHT IT. A first
         * version inserted `display_landing` with `created_by => 1, store_id => null` and no platform
         * admin at all. `getSuperAdminAllSetting()` (`helper.php:514`) reads the `super admin` row,
         * memoised as `super_admin_details`, and then only settings whose `created_by` is THAT user's
         * id and whose `store_id` is THAT user's `current_store` — so with no such user the settings
         * array was empty, `Landing()` took its `redirect('login')` branch, and the defect case
         * "passed" because the seeder never ran. The key survived for the wrong reason entirely.
         */
        $admin = $this->makePlatformAdmin();

        DB::table('settings')->insert([
            'name' => 'display_landing', 'value' => 'on',
            'created_by' => $admin->id, 'store_id' => $admin->current_store,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        /* Both memos, not just the one: `getSuperAdminAllSetting()` reads `super_admin_details` and
           several other handlers read `admin_details`. */
        Cache::forget('super_admin_details');
        Cache::forget('admin_details');
    }

    /**
     * THE DEFECT. An anonymous GET of the root route must not erase a payment claim.
     *
     * The claim is seeded, the landing module is ALREADY INSTALLED (so there is nothing legitimate
     * left to migrate or seed), and one unauthenticated request is made.
     */
    public function test_an_anonymous_request_to_the_root_route_does_not_erase_a_payment_claim(): void
    {
        $this->markSkippedIfLandingUnavailable();

        /*
         * AN ALREADY-INSTALLED STORE, stated explicitly rather than inherited from whatever
         * `RefreshDatabase` happens to leave behind. `RefreshDatabase` runs the module's migrations
         * but seeds no rows, so without this line the very first request is a legitimate fresh
         * install, the seed runs once by design, and this case would be measuring the install path
         * while claiming to measure the defect. The whole point of F215 is the SECOND and every
         * later request.
         */
        DB::table('landing_page_settings')->insert([
            'name' => 'ss-f215-already-installed', 'value' => 'yes',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Cache::put(self::CLAIM, 'stripe', 60 * 60 * 24 * 2);
        $this->assertSame('stripe', Cache::get(self::CLAIM), 'premise: the claim is readable before the request');

        $this->get('/');

        $this->assertSame('stripe', Cache::get(self::CLAIM),
            'an ANONYMOUS GET of `/` erased a gateway-return replay claim. `HomeController::Landing` '
            . 'runs `package:seed LandingPage` above the view, whose `PermissionTableSeeder:22` calls '
            . '`Artisan::call(\'cache:clear\')` — a flush of the whole default store. Because '
            . '`claimGatewayReturn()` takes its claim with `Cache::add()`, and `add()` on a missing key '
            . 'returns true, an erased claim is an UN-TAKEN claim: the replay it exists to refuse is '
            . 'welcomed. A stranger can do this as often as they like, with no token and no session '
            . '(F215).');
    }

    /**
     * THE PREMISE OF THE CASE ABOVE, and without it that case passes for a store that never ran the
     * seeder at all — which is the single most likely way for this test to be vacuous.
     *
     * It asserts the request really did reach the landing branch, by asserting the branch's own
     * observable effect: the module's settings table is populated afterwards.
     */
    public function test_the_request_really_reaches_the_landing_branch(): void
    {
        $this->markSkippedIfLandingUnavailable();

        DB::table('landing_page_settings')->delete();

        $this->get('/');

        $this->assertTrue(DB::table('landing_page_settings')->exists(),
            'the root route did not reach the landing branch at all, so the case above is asserting '
            . 'nothing about it. Check `display_landing`, `getSuperAdminAllSetting()` and whether the '
            . 'LandingPage static addon loaded in this run.');
    }

    /**
     * AND THE FRESH-INSTALL PATH STILL WORKS, which is the thing a careless fix would break.
     *
     * The guard must not stop a first-ever visit from installing the module — that is what these
     * two Artisan calls are legitimately for, and removing them outright would leave a new
     * deployment with an empty landing page.
     */
    public function test_a_fresh_install_is_still_seeded_on_the_first_visit(): void
    {
        $this->markSkippedIfLandingUnavailable();

        DB::table('landing_page_settings')->delete();
        $this->assertFalse(DB::table('landing_page_settings')->exists(), 'premise: nothing seeded');

        $this->get('/');

        $this->assertTrue(DB::table('landing_page_settings')->exists(),
            'the fix stopped a fresh install from seeding its landing page on the first visit, which '
            . 'is a regression rather than a tightening');
    }

    /**
     * The module has to be present for any of this to mean anything. Skipped rather than failed, and
     * the skip states its own precondition — a conditionally-skipped case would otherwise break the
     * known-outcome gate's declared skip set, so this file is only ever run where it can measure.
     */
    private function markSkippedIfLandingUnavailable(): void
    {
        if (! Schema::hasTable('landing_page_settings')) {
            $this->markTestSkipped(
                'the LandingPage module\'s `landing_page_settings` table is absent in this run, so '
                . 'there is no landing branch to drive and nothing this file asserts would be '
                . 'measuring the defect.'
            );
        }
    }
}
