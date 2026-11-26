<?php

namespace Tests\Feature\Saas;

use App\Models\Plan;
use App\Models\User;
use App\Support\Saas\SubscriptionLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\StripsSourceComments;

/**
 * SAAS-002 — A MERCHANT WHO LOGS IN ON THEIR EXPIRY DATE WAS DOWNGRADED ON A DAY THEY PAID FOR.
 * ============================================================================================
 *
 * THE DEFECT, DRIVEN OVER HTTP BEFORE THE FIX. A merchant on a `Month` plan with
 * `plan_expire_date = date('Y-m-d')` posted to `/login` and came back with:
 *
 *     plan_id            2  ->  1
 *     plan_expire_date   '2026-11-26'  ->  NULL
 *     session('error')   'Your Plan is expired.'
 *
 * `$datetime2->diff($datetime1)->format('%r%a')` is `0` on the expiry date and the three auth
 * sites branched on `$days <= 0`, so the last day of the paid period was treated as the first day
 * after it. `assignPlan(1)` then replaced the plan, nulled the expiry, reduced `active_module` to
 * whatever plan 1 grants, and deactivated every store and staff seat past plan 1's `max_stores` /
 * `max_users`.
 *
 * THE SAME ROW WAS SIMULTANEOUSLY `live` ON THE OPERATOR'S REVENUE SCREEN.
 * `SaasRevenueFigures::subscriptions()` classifies with `plan_expire_date >= :today` and states the
 * rule in its own docblock; `PlatformRevenueFiguresTest::test_a_subscription_expiring_today_still_
 * counts` pins it. The merchant's own plan screen (`plans/index.blade.php`) uses
 * `plan_expire_date < date('Y-m-d')` and also did not say `Expired`. So two surfaces said live and
 * three said expired, on the same row, on the same day.
 *
 * THE CORRECTION IS THE NARROWEST ONE AVAILABLE: all three sites now ask
 * `SubscriptionLifecycle::recurringSubscriptionNeedsDowngrade()`, which differs from the old
 * expression by exactly one day and in nothing else. In particular an EMPTY `plan_expire_date` on a
 * recurring plan still downgrades — `new \DateTime('')` is now, so the old `<= 0` was true for it —
 * because repairing a row whose expiry was never written is a different question from this
 * off-by-one, and silently changing it would be the kind of drive-by this file exists to prevent.
 * `test_a_recurring_plan_with_no_expiry_recorded_still_downgrades_at_login` pins that half.
 *
 * WHY THE ASSERTIONS ARE ON THE STORED ROW AND NOT ON THE STATUS CODE. Both outcomes are a 302 to
 * the same URL: `redirect()->intended(RouteServiceProvider::HOME)` on the downgrade branch and
 * `redirect()->intended(RouteServiceProvider::HOME)` at the end of the method. CLAUDE.md records
 * that a 302 from a gate and a 302 from an ordinary path are the same status and opposite facts, so
 * the control here is `plan_id`, `plan_expire_date` and the flash — never the code.
 */
final class LoginExpiryBoundaryTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    private const PASSWORD = 'boundary-password-1';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        // SetLocate and several handlers memoise the platform admin for an hour. Under
        // CACHE_STORE=array that lives for the rest of the test.
        Cache::forget('super_admin_details');
        Cache::forget('admin_details');
    }

    /** Plan 1 is the downgrade target every auth site hardcodes as `assignPlan(1)`. */
    private function freePlan(): Plan
    {
        return Plan::create([
            'id' => 1,
            'name' => 'Free ' . $this->uniqueFixtureRef(),
            'price' => 0,
            'duration' => 'Unlimited',
            'max_stores' => 1,
            'max_products' => 5,
            'max_users' => 1,
            'storage_limit' => 10,
            'themes' => 'stylique',
            'trial' => 0,
            'trial_days' => 0,
        ]);
    }

    private function paidMonthlyPlan(): Plan
    {
        return Plan::create([
            'name' => 'Paid ' . $this->uniqueFixtureRef(),
            'price' => 1200,
            'duration' => 'Month',
            'max_stores' => 3,
            'max_products' => 100,
            'max_users' => 5,
            'storage_limit' => 500,
            'themes' => 'stylique',
            'trial' => 0,
            'trial_days' => 0,
        ]);
    }

    /** A merchant signed up to `$plan` whose subscription expires on `$expiry`. */
    private function subscriber(Plan $plan, ?string $expiry): User
    {
        [$merchant] = $this->makeTenant();

        $merchant->plan_id = $plan->id;
        $merchant->plan_expire_date = $expiry;
        $merchant->password = bcrypt(self::PASSWORD);
        $merchant->save();

        return $merchant->fresh();
    }

    /** @return array{0: \Illuminate\Testing\TestResponse, 1: User} */
    private function logIn(User $merchant): array
    {
        $response = $this->post('/login', [
            'email' => $merchant->email,
            'password' => self::PASSWORD,
        ]);

        // withoutGlobalScopes: `User` carries no StoreScope today, but an assertion about what the
        // table holds must not depend on that staying true.
        return [$response, User::withoutGlobalScopes()->find($merchant->id)];
    }

    // ------------------------------------------------------------------ the defect

    #[Test]
    public function test_a_merchant_logging_in_on_their_expiry_date_keeps_their_plan(): void
    {
        $this->freePlan();
        $paid = $this->paidMonthlyPlan();
        $today = date('Y-m-d');

        $merchant = $this->subscriber($paid, $today);

        [$response, $after] = $this->logIn($merchant);

        $this->assertSame(
            $paid->id,
            (int) $after->plan_id,
            'the expiry date is the last day of the paid period, not the first day after it'
        );
        $this->assertSame($today, $after->plan_expire_date);
        $this->assertNotSame('Your Plan is expired.', session('error'));
        $response->assertRedirect();
    }

    #[Test]
    public function test_a_merchant_logging_in_the_day_after_expiry_is_still_downgraded(): void
    {
        $free = $this->freePlan();
        $paid = $this->paidMonthlyPlan();

        $merchant = $this->subscriber($paid, date('Y-m-d', strtotime('-1 day')));

        [, $after] = $this->logIn($merchant);

        $this->assertSame($free->id, (int) $after->plan_id, 'the lapse must still be enforced');
        $this->assertSame('Your Plan is expired.', session('error'));
    }

    #[Test]
    public function test_a_merchant_well_inside_their_period_is_untouched(): void
    {
        $this->freePlan();
        $paid = $this->paidMonthlyPlan();
        $expiry = date('Y-m-d', strtotime('+20 days'));

        $merchant = $this->subscriber($paid, $expiry);

        [, $after] = $this->logIn($merchant);

        $this->assertSame($paid->id, (int) $after->plan_id);
        $this->assertSame($expiry, $after->plan_expire_date);
    }

    /**
     * THE PRESERVED EDGE CASE. A recurring plan with no expiry recorded was downgraded by the old
     * expression and still is. Changing it is a separate decision, not a side effect of this one.
     */
    #[Test]
    public function test_a_recurring_plan_with_no_expiry_recorded_still_downgrades_at_login(): void
    {
        $free = $this->freePlan();
        $paid = $this->paidMonthlyPlan();

        $merchant = $this->subscriber($paid, null);

        [, $after] = $this->logIn($merchant);

        $this->assertSame($free->id, (int) $after->plan_id);
        $this->assertSame('Your Plan is expired.', session('error'));
    }

    // ------------------------------------------------- the entitlement the day is worth

    /**
     * WHAT THE OFF-BY-ONE ACTUALLY COST, rather than just which integer changed: the downgrade runs
     * `assignPlan(1)`'s cascades, so on the expiry date a merchant lost two of their three
     * storefronts and their extra staff seat for a day they had paid for.
     */
    #[Test]
    public function test_on_the_expiry_date_the_merchants_extra_stores_stay_active(): void
    {
        $this->freePlan();
        $paid = $this->paidMonthlyPlan();

        $merchant = $this->subscriber($paid, date('Y-m-d'));
        $second = $this->makeStore($merchant);
        $third = $this->makeStore($merchant);

        $this->logIn($merchant);

        foreach ([$second, $third] as $store) {
            $this->assertSame(
                1,
                (int) \App\Models\Store::withoutGlobalScopes()->find($store->id)->is_active,
                'store ' . $store->id . ' was paid for through today'
            );
        }
    }

    // ------------------------------------------------- the three sites really call the predicate

    /**
     * THE CENSUS THAT STOPS THE EXPRESSION COMING BACK. Three sites shared it, and a fix applied to
     * one of them is the shape of this project's recurring near-miss. Comments are stripped because
     * the docblocks above those very lines quote the old expression.
     */
    #[Test]
    public function test_no_auth_site_still_computes_the_expiry_by_hand(): void
    {
        $files = [
            'app/Http/Controllers/Auth/AuthenticatedSessionController.php',
            'app/Http/Controllers/Auth/RegisteredUserController.php',
            'app/Http/Middleware/SetLocate.php',
        ];

        foreach ($files as $file) {
            $source = $this->sourceWithoutComments(base_path($file));

            $this->assertStringNotContainsString(
                "format('%r%a')",
                $source,
                $file . ' must ask SubscriptionLifecycle rather than diffing the dates itself'
            );
            $this->assertStringContainsString(
                'SubscriptionLifecycle::',
                $source,
                $file . ' must ask the one predicate'
            );
        }
    }

    // ------------------------------------------------- the shipped Flutter contract, unchanged

    /**
     * THE SIXTH SURFACE, AND IT WAS ALREADY RIGHT — so this pins the agreement rather than a fix.
     *
     * `GET api/admin/indexAllPlan` answers the admin app's plan screen with `is_expired`,
     * `days_remaining`, `status` and `expire_message`; `StoreSyria_app98`'s
     * `PlanStatus.fromJson` (lib/core/model/my_plan_model.dart) reads `is_expired` straight to the
     * merchant's screen. Its comparison is `$today->greaterThan($expireDate)` — strictly after, so
     * the expiry date is live, which is the boundary this slice adopted. The merchant's phone and
     * the merchant's login now say the same thing on the same day, and NO API FIELD CHANGED:
     * nothing in `Api\DashboardController` was touched. This case is what would fail if a later
     * change moved either one.
     */
    #[Test]
    public function test_the_flutter_plan_endpoint_and_the_web_login_agree_on_the_expiry_day(): void
    {
        $this->freePlan();
        $paid = $this->paidMonthlyPlan();
        $today = date('Y-m-d');

        [$merchant, $store] = $this->makeTenant();
        $merchant->plan_id = $paid->id;
        $merchant->plan_expire_date = $today;
        $merchant->save();
        $merchant = $merchant->fresh();

        $token = $merchant->createToken('auth_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/indexAllPlan?store_id=' . $store->id);

        $this->assertSame(200, $response->getStatusCode(), 'premise: the endpoint answers');

        // `data.current_plan.plan_status`, NOT `data.plan_status`: the block is NESTED inside
        // `current_plan`. The premise assertion below is what reported that, instead of the case
        // passing on a null `is_expired` that casts to false -- which is the answer it asserts.
        $status = $response->json('data.current_plan.plan_status');

        $this->assertIsArray(
            $status,
            'premise: the response still carries the plan_status block the Flutter app reads. '
            . 'Body: ' . substr((string) $response->getContent(), 0, 400)
        );
        $this->assertFalse(
            (bool) $status['is_expired'],
            'the Flutter contract must keep saying live on the expiry day'
        );
        $this->assertFalse(
            SubscriptionLifecycle::recurringSubscriptionNeedsDowngrade($merchant),
            'and the web login must now say the same'
        );
    }

    /**
     * A PREMISE, NOT A RESTATEMENT: that the predicate those sites now call really is the one this
     * file's boundary cases describe. Without it the census above could be satisfied by a call to
     * something that answers differently.
     */
    #[Test]
    public function test_the_predicate_the_auth_sites_call_has_the_boundary_this_file_asserts(): void
    {
        $merchant = new User();
        $merchant->forceFill(['type' => 'admin', 'plan_id' => 7, 'plan_expire_date' => date('Y-m-d')]);

        $this->assertFalse(SubscriptionLifecycle::recurringSubscriptionNeedsDowngrade($merchant));

        $merchant->plan_expire_date = date('Y-m-d', strtotime('-1 day'));

        $this->assertTrue(SubscriptionLifecycle::recurringSubscriptionNeedsDowngrade($merchant));
    }
}
