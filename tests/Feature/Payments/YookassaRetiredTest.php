<?php

namespace Tests\Feature\Payments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\PlanPaymentTestCase;

/**
 * YOOKASSA IS RETIRED: A CHECKOUT CANNOT EVEN BE STARTED.
 * =============================================================================================
 *
 * The owner has decided YooKassa is not needed. Refusing only the RETURN handler -- which is
 * where a verification defect belongs, and where config/payments.php already refused -- left the
 * INITIATOR, YookassaController::paywithyookassa, completely ungated. Two things followed from
 * that, and this file is about both:
 *
 *   A REAL CHECKOUT WITH A RETIRED GATEWAY. The initiator reached
 *   `$client->createPayment()` and redirected the merchant to YooKassa's confirmation URL.
 *
 *   A FREE PLAN WITH NO GATEWAY AT ALL. In the coupon-covers-the-full-price branch the initiator
 *   never touches YooKassa: it sets the user's plan, writes a PlanOrder with
 *   `payment_status = 'success'` and calls assignPlan() twice. No provider, no payment, no
 *   return -- just a coupon and a POST.
 *
 * THE FIXTURE IS SEEDED SO THE UNGATED PATH WOULD REALLY PROCEED. seedSuperAdminCurrency() plus
 * the two yookassa merchant keys, because getSuperAdminAllSetting() feeds
 * `$payment_setting['CURRENCY_NAME']` and `$payment_setting['yookassa_shop_id_key']`, and a
 * missing key would make the method bail with "Undefined array key" into its own
 * `catch (\Exception)`. A 403 asserted against a path that could not have proceeded anyway
 * proves nothing, so every test here -- including the negative ones -- seeds it.
 *
 * ASSERTION ORDER IS DELIBERATE: rows and entitlement BEFORE the status code. A status assertion
 * can be vacuous (four tests on this project once passed with their defect reintroduced), and if
 * assertForbidden() ran first it would shadow the question of whether a paid plan had been sold
 * for nothing.
 *
 * WHAT THIS FILE DOES NOT DO. It does not assert class_exists('YooKassa\Client') in either
 * direction. The SDK is pinned in composer.lock and installs on a correct install; an earlier
 * test asserted its ABSENCE, which was true only inside one development container, and CI failed
 * on it. The guard's whole point is that it runs before the SDK is touched, so these assertions
 * hold whether or not vendor/yoomoney is present -- which is exactly why they are written this
 * way.
 */
class YookassaRetiredTest extends PlanPaymentTestCase
{
    use RefreshDatabase;

    /**
     * Everything getSuperAdminAllSetting() must hand back for the ungated initiator to run all
     * the way to the gateway call instead of bailing out early.
     */
    private function seedYookassaMerchantSettings(): void
    {
        $this->seedSuperAdminCurrency('USD', [
            'yookassa_shop_id_key' => '123456',
            'yookassa_secret_key'  => 'test_secret_key',
        ]);
    }

    public function test_a_yookassa_plan_checkout_cannot_be_started_at_all(): void
    {
        $this->seedYookassaMerchantSettings();

        $merchant = $this->makeMerchant();
        $plan     = $this->makePlan('499');

        $planBefore = $merchant->plan_id;

        $response = $this->actingAs($merchant)->post(route('pay.yookassa.payment'), [
            'plan_id' => Crypt::encrypt($plan->id),
            'coupon'  => '',
        ]);

        $this->assertSame(
            0,
            DB::table('plan_orders')->count(),
            'A refused YooKassa checkout still recorded a plan_orders row. The guard must run '
            . 'before anything is written.'
        );

        $this->assertSame(
            $planBefore,
            $merchant->fresh()->plan_id,
            'A refused YooKassa checkout still changed the merchant\'s entitlement.'
        );

        // 403, never 401: repo B's lib/network/network.dart turns any 401 into a forced logout
        // that clears the merchant's stored preferences.
        $response->assertForbidden();
    }

    /**
     * The branch that needs no gateway at all, and so is the one whose refusal is worth most.
     *
     * With a 100% coupon the initiator's own code path sets `$authuser->plan`, writes a PlanOrder
     * with `payment_status = 'success'`, records the coupon usage and calls assignPlan() -- all
     * before YooKassa is contacted, and therefore reachable on any install.
     */
    public function test_a_full_price_coupon_cannot_activate_a_plan_through_the_retired_initiator(): void
    {
        $this->seedYookassaMerchantSettings();

        $merchant = $this->makeMerchant();
        $plan     = $this->makePlan('499');
        $coupon   = $this->makeCoupon('FREEPLAN100', 100);

        $planBefore = $merchant->plan_id;

        $response = $this->actingAs($merchant)->post(route('pay.yookassa.payment'), [
            'plan_id' => Crypt::encrypt($plan->id),
            'coupon'  => $coupon->code,
        ]);

        $this->assertSame(
            0,
            DB::table('plan_orders')->count(),
            'A 100% coupon through the retired YooKassa initiator still recorded a PlanOrder with '
            . 'payment_status = success. The guard must run before that write.'
        );

        $this->assertSame(
            0,
            DB::table('plan_user_coupons')->count(),
            'A refused checkout still consumed the coupon.'
        );

        $this->assertSame(
            $planBefore,
            $merchant->fresh()->plan_id,
            'A 100% coupon through the retired YooKassa initiator still ACTIVATED the paid plan. '
            . 'The guard must run before both assignPlan() calls.'
        );

        $response->assertForbidden();
    }

    /**
     * An unreadable plan_id must be REFUSED, not fatal and not softer than a readable one.
     *
     * The initiator's `Crypt::decrypt($request->plan_id)` sat outside both of its try blocks, so
     * a tampered token raised DecryptException straight out of the controller as a 500. The
     * decrypt now only supplies the refusal log with a plan id, and a failure must not become a
     * way around the gate.
     */
    public function test_an_unreadable_plan_id_is_refused_and_does_not_raise(): void
    {
        $this->seedYookassaMerchantSettings();

        $merchant = $this->makeMerchant();
        $this->makePlan('499');

        $response = $this->actingAs($merchant)->post(route('pay.yookassa.payment'), [
            'plan_id' => 'not-a-crypt-payload',
            'coupon'  => '',
        ]);

        $this->assertSame(0, DB::table('plan_orders')->count());

        $response->assertForbidden();
    }

    /**
     * The gate precedes the plan lookup, so an unknown plan is refused rather than redirected.
     *
     * If the guard were placed after `Plan::find()` this would answer 302 "Plan is deleted."
     * instead -- a softer answer that would tell a caller the gate is not the first thing in the
     * way, and that would leave the guard's position dependent on the plan existing.
     */
    public function test_an_unknown_plan_is_refused_by_the_gate_before_the_lookup(): void
    {
        $this->seedYookassaMerchantSettings();

        $merchant = $this->makeMerchant();

        $response = $this->actingAs($merchant)->post(route('pay.yookassa.payment'), [
            'plan_id' => Crypt::encrypt(999999),
            'coupon'  => '',
        ]);

        $this->assertSame(0, DB::table('plan_orders')->count());

        $response->assertForbidden();
    }
}
