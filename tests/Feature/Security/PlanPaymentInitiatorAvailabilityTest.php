<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * A CHECKOUT THAT CANNOT PROCEED MUST SAY SO, NOT RAISE A 500.
 * =============================================================================================
 *
 * PayHerePaymnetController::planPayWithPayHere -- the INITIATOR, not the return handler -- ends
 * in `return PayHere::checkOut()`. Lahirulhr\PayHere\PayHere is absent from vendor/, measured
 * rather than assumed:
 *
 *     php -r '... class_exists("Lahirulhr\PayHere\PayHere")'   =>  false
 *     ls vendor/lahirulhr                                      =>  No such file or directory
 *
 * A missing class raises \Error, which is a \Throwable but NOT an \Exception, so the method's
 * `catch (\Exception $e)` never saw it and a merchant who chose PayHere got an uncaught 500 --
 * after the coupon lookup, the config() write and the hash computation had already run.
 *
 * lahirulhr/laravel-payhere is EXTERNALLY BLOCKED rather than merely uninstalled: its pinned
 * commit no longer exists upstream, and all eight published versions resolve to two GitHub
 * repositories that answer neither a zipball fetch nor an unauthenticated git ls-remote. So
 * this is the permanent state of this deployment, not a transient vendor gap, and the initiator
 * has to behave itself with the package absent.
 *
 * WHAT THIS IS NOT. It is not a security fix and it does not replace one. The free-activation
 * gate for PayHere is assertPlanActivationEnabled('payhere') on the RETURN handler
 * (planGetPayHereStatus), where it belongs, and payhere remains shipped-disabled in
 * config/payments.php -- PlanPaymentPostureTest and PlanPaymentFailClosedTest own that. An
 * initiator that 500s is a broken checkout, not an open door. What is asserted here is that it
 * is now a refusal with no side effects rather than a stack trace, and -- because this is a
 * payment path -- that the refusal writes NOTHING.
 */
class PlanPaymentInitiatorAvailabilityTest extends PlanPaymentTestCase
{
    use RefreshDatabase;

    private const PAYHERE_CLASS = 'Lahirulhr\PayHere\PayHere';

    public function test_the_payhere_sdk_really_is_absent_so_this_file_is_testing_something(): void
    {
        $this->assertFalse(
            class_exists(self::PAYHERE_CLASS),
            self::PAYHERE_CLASS . ' now exists. Every assertion in this file rests on its '
            . 'absence, so if lahirulhr/laravel-payhere has become installable the guard in '
            . 'planPayWithPayHere() should be re-read rather than this test adjusted -- and the '
            . 'return handler becomes verifiable, which is what config/payments.php names as '
            . "payhere's re-enable condition."
        );
    }

    public function test_a_payhere_plan_checkout_is_refused_instead_of_raising_a_500(): void
    {
        $this->seedSuperAdminCurrency('LKR', [
            'payhere_mode'             => 'sandbox',
            'payhere_merchant_id'      => 'M1',
            'payhere_merchant_secret'  => 'S1',
            'payhere_app_id'           => 'A1',
            'payhere_app_secret'       => 'AS1',
        ]);

        $merchant = $this->makeMerchant();
        $plan     = $this->makePlan('499');

        $response = $this->actingAs($merchant)->post(route('pay.payhere.payment'), [
            'plan_id' => Crypt::encrypt($plan->id),
        ]);

        $this->assertNotSame(
            500,
            $response->status(),
            'Starting a PayHere plan purchase still raises a 500. The missing SDK raises \\Error, '
            . 'which `catch (\\Exception)` does not catch -- hence the class_exists() guard as the '
            . 'first statement and the widened catch.'
        );

        $response->assertStatus(302);
        $response->assertRedirect(route('plan.index'));
    }

    /**
     * THE REFUSAL LEAVES NOTHING BEHIND.
     *
     * The point of putting the guard FIRST rather than wrapping the call site: everything the
     * method used to do before reaching PayHere::checkOut() -- the coupon usage lookup, the
     * config() write, the hash -- is skipped. A payment path that half-runs is worse than one
     * that refuses, because the leftovers look like a real purchase in progress.
     */
    public function test_a_refused_payhere_checkout_writes_no_order_and_grants_no_plan(): void
    {
        $this->seedSuperAdminCurrency('LKR', [
            'payhere_mode'            => 'sandbox',
            'payhere_merchant_id'     => 'M1',
            'payhere_merchant_secret' => 'S1',
        ]);

        $merchant = $this->makeMerchant();
        $plan     = $this->makePlan('499');

        $planBefore = $merchant->plan_id;

        $this->actingAs($merchant)->post(route('pay.payhere.payment'), [
            'plan_id' => Crypt::encrypt($plan->id),
        ]);

        $this->assertSame(
            0,
            DB::table('plan_orders')->count(),
            'A PayHere checkout that cannot proceed recorded a plan order.'
        );

        $this->assertSame(
            $planBefore,
            $merchant->fresh()->plan_id,
            'A PayHere checkout that cannot proceed changed the merchant\'s entitlement.'
        );

        $this->assertSame(
            0,
            DB::table('plan_user_coupons')->count(),
            'A PayHere checkout that cannot proceed recorded a coupon usage. The guard must run '
            . 'before the coupon lookup, not after it.'
        );
    }

    /**
     * The widened catch, asserted on the code with COMMENTS STRIPPED.
     *
     * This is the trap this exact assertion has already sprung on this project: a scan for
     * `catch (\Exception` matches the phrase inside the explanatory comment about that very
     * defect, and reports the opposite of the truth. token_get_all() first, always -- PHP's own
     * lexer is the only thing that reliably knows where a comment ends.
     */
    public function test_the_payhere_initiator_catches_throwable_not_only_exception(): void
    {
        $src = $this->codeWithoutComments(
            (string) file_get_contents(app_path('Http/Controllers/PayHerePaymnetController.php'))
        );

        $this->assertStringContainsString(
            'catch (\Throwable $e)',
            $src,
            'PayHerePaymnetController no longer catches \\Throwable. A missing class or a bad '
            . 'method call raises \\Error, which does not extend \\Exception, so narrowing this '
            . 'back lets the next one out as a 500.'
        );

        $this->assertStringContainsString(
            'class_exists(PayHere::class)',
            $src,
            'The class_exists() guard has gone from planPayWithPayHere(). Without it the '
            . 'initiator runs its coupon lookup and hash computation before failing at '
            . 'PayHere::checkOut().'
        );
    }

    /**
     * AND THE ACTIVATION GATE IS STILL ON THE RETURN HANDLER, WHERE IT BELONGS.
     *
     * Asserted here because this file changes the same controller: a plausible-looking "tidy up"
     * would be to move the fail-closed guard up into the initiator, which would leave the
     * RETURN path -- the one that calls assignPlan() -- completely open while still looking
     * guarded to a grep of the file.
     */
    public function test_the_fail_closed_gate_is_still_on_the_payhere_return_handler(): void
    {
        $src = $this->codeWithoutComments(
            (string) file_get_contents(app_path('Http/Controllers/PayHerePaymnetController.php'))
        );

        $returnHandler = strpos($src, 'function planGetPayHereStatus');

        $this->assertNotFalse($returnHandler, 'planGetPayHereStatus has been renamed or removed.');

        $this->assertStringContainsString(
            "assertPlanActivationEnabled('payhere'",
            substr($src, $returnHandler),
            'assertPlanActivationEnabled(\'payhere\') is no longer inside or after '
            . 'planGetPayHereStatus. That guard is the only thing stopping a forged PayHere '
            . 'return from activating a paid plan -- the md5 hash this controller computes is '
            . 'outbound-only and the same URL is registered as both successUrl and failUrl.'
        );
    }

    private function codeWithoutComments(string $src): string
    {
        $out = '';

        foreach (token_get_all($src) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $out .= str_repeat("\n", substr_count($token[1], "\n"));

                    continue;
                }

                $out .= $token[1];

                continue;
            }

            $out .= $token;
        }

        return $out;
    }
}
