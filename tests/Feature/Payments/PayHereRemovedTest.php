<?php

namespace Tests\Feature\Payments;

use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\Feature\Security\PlanPaymentTestCase;

/**
 * PAYHERE IS RETIRED: THE DEPENDENCY IS GONE AND BOTH PATHS REFUSE ON PURPOSE.
 * =============================================================================================
 *
 * The owner's closure decision is that PayHere is not needed. Two things had to follow from it,
 * and this file asserts both, because each would be invisible to the other's test:
 *
 *   1. THE BUILD BLOCKER IS GONE. `lahirulhr/laravel-payhere` pinned a dist zipball at a commit
 *      that no longer exists upstream, so `composer install` from the committed lock exited 100
 *      on a clean runner and the REQUIRED `tests` job could never run a single Laravel test.
 *      The package is removed from composer.json AND composer.lock -- asserted in both, because
 *      a require line removed without the lock entry still installs it, and a lock entry
 *      removed without the require line is restored by the next `composer update`.
 *
 *   2. THE PATHS REFUSE BECAUSE THE INTEGRATION IS RETIRED, NOT BECAUSE A CLASS IS MISSING.
 *      Removing the package makes `Lahirulhr\PayHere\PayHere` permanently absent, so the
 *      pre-existing class_exists() guards would fail these paths closed on their own. That is
 *      INCIDENTAL. The refusal is now first and unconditional, so a tree that somehow vendored
 *      the SDK back in still refuses rather than quietly resuming charges through a gateway the
 *      owner withdrew. The class_exists() guards are kept as a second line of defence and are
 *      still asserted by PlanPaymentInitiatorAvailabilityTest.
 *
 * WHAT THIS IS NOT. Not a security control and not a substitute for one. Free activation is
 * refused by assertPlanActivationEnabled('payhere') on the RETURN handler, driven by
 * config/payments.php, and PlanPaymentFailClosedTest / PlanPaymentPostureTest own that. A
 * refusing initiator is a closed checkout, not an authorisation boundary.
 *
 * @see docs/BUILD-REPRODUCIBILITY.md for the dead-upstream investigation
 */
class PayHereRemovedTest extends PlanPaymentTestCase
{
    use RefreshDatabase;

    private const DEAD = 'lahirulhr/laravel-payhere';

    /**
     * The transitive dependency the removal legitimately took with it. It was required by
     * PayHere alone (`"spatie/laravel-package-tools": "^1.9.2"`) and by nothing else in the
     * lock, nothing in composer.json and no first-party or packaged source file.
     */
    private const CASCADED = 'spatie/laravel-package-tools';

    /**
     * Gateways that must have survived the removal untouched, as package => controller class.
     *
     * The owner's instruction was to preserve every unrelated gateway, and "composer remove"
     * is exactly the operation that can quietly take a shared transitive dependency with it.
     * Asserting the controller class alone would not catch that -- the class is first-party and
     * exists either way -- so each one is paired with the locked package it actually needs.
     *
     * yoomoney/yookassa-sdk-php is deliberately in this list. YooKassa is being DISABLED by the
     * same closure decision, but disabling is not uninstalling: its SDK installs cleanly and
     * decision 2 says to leave unrelated dependencies alone. If a later "tidy up" removes the
     * yoomoney packages too, this fails and says why.
     */
    private const SURVIVORS = [
        'stripe/stripe-php'            => \App\Http\Controllers\StripePaymentController::class,
        'srmklive/paypal'              => \App\Http\Controllers\PaypalPaymentController::class,
        'mollie/laravel-mollie'        => \App\Http\Controllers\MolliePaymentController::class,
        'iyzico/iyzipay-php'           => \App\Http\Controllers\IyziPayController::class,
        'mercadopago/dx-php'           => \App\Http\Controllers\MercadoPaymentController::class,
        'xendit/xendit-php-clients'    => \App\Http\Controllers\XenditPaymentController::class,
        'fedapay/fedapay-php'          => \App\Http\Controllers\FedPayPaymentController::class,
        'laravel/cashier-paddle'       => \App\Http\Controllers\PaddlePaymentController::class,
        'myfatoorah/laravel-package'   => \App\Http\Controllers\PaymentController::class,
        'yoomoney/yookassa-sdk-php'    => \App\Http\Controllers\YookassaController::class,
    ];

    // -----------------------------------------------------------------------------------------
    // 1. THE DEPENDENCY IS GONE FROM BOTH MANIFESTS.
    // -----------------------------------------------------------------------------------------

    public function test_the_dead_package_is_absent_from_composer_json_and_composer_lock(): void
    {
        $json = $this->composerJson();
        $lock = $this->composerLock();

        $this->assertArrayNotHasKey(
            self::DEAD,
            $json['require'] ?? [],
            self::DEAD . ' is still required by composer.json. Its dist zipball 404s on a clean '
            . 'runner, so while this require line exists `composer install` from the lock cannot '
            . 'succeed and the required `tests` job cannot run.'
        );

        $this->assertArrayNotHasKey(self::DEAD, $json['require-dev'] ?? [], 'Moved to require-dev, not removed.');

        $this->assertNotContains(
            self::DEAD,
            $this->lockedPackageNames($lock),
            self::DEAD . ' is still an entry in composer.lock. The lock is what `composer '
            . 'install` reads, so a require line removed without the lock entry changes nothing '
            . 'about the build blocker.'
        );
    }

    /**
     * THE REMOVAL MUST NOT HAVE LEFT AN UNSATISFIABLE REQUIREMENT BEHIND.
     *
     * `composer remove` also dropped spatie/laravel-package-tools, because PayHere was the only
     * thing that required it. That is correct, but the failure mode worth asserting is the one
     * where something ELSE still needs a package that has left the lock -- which would make the
     * lock internally inconsistent while `composer validate` (which only compares composer.json
     * to composer.lock, never the dependency graph) still passed.
     */
    public function test_no_surviving_locked_package_still_requires_what_was_removed(): void
    {
        $lock    = $this->composerLock();
        $removed = [self::DEAD, self::CASCADED];

        $this->assertNotContains(
            self::CASCADED,
            $this->lockedPackageNames($lock),
            self::CASCADED . ' is back in the lock. It was removed as PayHere\'s only dependent; '
            . 'if something now needs it, it must be required explicitly and on purpose.'
        );

        $offenders = [];

        foreach (['packages', 'packages-dev'] as $section) {
            foreach ($lock[$section] ?? [] as $package) {
                foreach (['require', 'require-dev'] as $key) {
                    foreach (array_keys($package[$key] ?? []) as $dependency) {
                        if (in_array($dependency, $removed, true)) {
                            $offenders[] = $package['name'] . ' (' . $section . ') ' . $key . ' => ' . $dependency;
                        }
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A locked package still requires a package the removal took out of the lock, so the "
            . "locked set can no longer install:\n  " . implode("\n  ", $offenders)
        );
    }

    // -----------------------------------------------------------------------------------------
    // 2. EVERY OTHER GATEWAY SURVIVED.
    // -----------------------------------------------------------------------------------------

    public function test_every_unrelated_payment_gateway_still_resolves(): void
    {
        $locked = $this->lockedPackageNames($this->composerLock());

        foreach (self::SURVIVORS as $package => $controller) {
            $this->assertContains(
                $package,
                $locked,
                $package . ' has left composer.lock. Only PayHere was to be removed; this '
                . 'gateway is unrelated and the owner\'s instruction was to preserve it.'
            );

            $this->assertTrue(
                class_exists($controller),
                $controller . ' no longer resolves, so an unrelated gateway was broken while '
                . 'retiring PayHere.'
            );
        }
    }

    // -----------------------------------------------------------------------------------------
    // 3. THE STOREFRONT CHECKOUT REFUSES AND WRITES NOTHING.
    // -----------------------------------------------------------------------------------------

    /**
     * PaymentService::processPayHere()'s own side effects, in the order it used to perform
     * them, are Session::put('request_data', ...) and a CheckoutAttachment row. Both are
     * asserted absent BEFORE the status code, because a 302 is exactly what this method
     * returns on success too -- the redirect proves nothing on its own.
     */
    public function test_a_storefront_payhere_checkout_is_refused_and_records_nothing(): void
    {
        $this->session([]);

        $ordersBefore = Schema::hasTable('orders') ? DB::table('orders')->count() : null;

        $method = new ReflectionMethod(PaymentService::class, 'processPayHere');
        $method->setAccessible(true);

        $response = $method->invoke(
            new PaymentService(),
            Request::create('/checkout', 'POST', ['slug' => 'any-store-slug']),
            'any-store-slug',
            null
        );

        // THE ABSENCE OF THE WRITE FIRST. These are the assertions a reintroduced defect
        // actually moves; the status code below does not change either way.
        $this->assertFalse(
            session()->has('request_data'),
            'The refusal must run BEFORE Session::put(\'request_data\'), so a refused checkout '
            . 'leaves no half-finished purchase in the session.'
        );

        if (Schema::hasTable('checkout_attachments')) {
            $this->assertSame(
                0,
                DB::table('checkout_attachments')->count(),
                'A refused PayHere checkout stored a CheckoutAttachment row.'
            );
        }

        if ($ordersBefore !== null) {
            $this->assertSame(
                $ordersBefore,
                DB::table('orders')->count(),
                'A refused PayHere checkout created an order row.'
            );
        }

        // Then the customer-visible outcome: the same redirect-with-error the method produces
        // for its other failures, never an HTTP 500 from a missing class.
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('PayHere', (string) session()->get('error'));
        $this->assertSame(302, $response->getStatusCode());
    }

    // -----------------------------------------------------------------------------------------
    // 4. THE PLAN PURCHASE REFUSES AND ACTIVATES NOTHING.
    // -----------------------------------------------------------------------------------------

    /**
     * The currency IS seeded, deliberately. Without it the plan-purchase path dies at
     * $admin_payment_setting['CURRENCY_NAME'] and a 302-to-plan.index would be indistinguishable
     * from a refusal -- the test would pass against a completely unguarded initiator. Seeded,
     * the only thing standing between this request and an activated plan is the refusal.
     */
    public function test_a_payhere_plan_purchase_is_refused_and_activates_no_plan(): void
    {
        $this->seedSuperAdminCurrency('LKR', [
            'payhere_mode'            => 'sandbox',
            'payhere_merchant_id'     => 'M1',
            'payhere_merchant_secret' => 'S1',
            'payhere_app_id'          => 'A1',
            'payhere_app_secret'      => 'AS1',
        ]);

        // The provider is switched ON at the settings level on purpose: the refusal must not
        // depend on a tenant leaving it off.
        $this->enableProvider('payhere');

        $merchant   = $this->makeMerchant();
        $plan       = $this->makePlan('499');
        $planBefore = $merchant->plan_id;

        $response = $this->actingAs($merchant)->post(route('pay.payhere.payment'), [
            'plan_id' => Crypt::encrypt($plan->id),
        ]);

        // THE ENTITLEMENT FIRST. This is the assertion that means something: a 302 to
        // plan.index is also what a SUCCESSFUL activation returns.
        $this->assertSame(
            $planBefore,
            $merchant->fresh()->plan_id,
            'A refused PayHere plan purchase changed the merchant\'s entitlement. This is the '
            . 'assertion that distinguishes a refusal from a successful activation -- both '
            . 'redirect to plan.index with a 302.'
        );

        $this->assertSame(
            0,
            DB::table('plan_orders')->count(),
            'A refused PayHere plan purchase recorded a plan order.'
        );

        $this->assertSame(
            0,
            DB::table('plan_user_coupons')->count(),
            'A refused PayHere plan purchase recorded a coupon usage, so the refusal is running '
            . 'after the coupon lookup instead of before it.'
        );

        $this->assertNotSame(500, $response->status(), 'The initiator raised a 500 instead of refusing.');
        $response->assertRedirect(route('plan.index'));
    }

    // -----------------------------------------------------------------------------------------
    // 5. THE REFUSAL IS UNCONDITIONAL, NOT A SIDE EFFECT OF THE MISSING CLASS.
    // -----------------------------------------------------------------------------------------

    /**
     * WHY THIS IS A SOURCE ASSERTION AND NOT A RUNTIME ONE.
     *
     * While the package is absent, the class_exists() guards refuse these paths all by
     * themselves -- so deleting the deliberate refusal changes NO observable behaviour and
     * every runtime test above keeps passing. That makes the behavioural tests blind to
     * exactly the property the owner asked for: refuse because the integration is retired,
     * not because a class happens to be missing.
     *
     * The only way to detect it while the class is unloadable is to assert the ORDER of the two
     * guards in the source, which is what this does. It is the same technique
     * PlanPaymentInitiatorAvailabilityTest uses for guard placement on this controller.
     *
     * COMMENTS ARE STRIPPED FIRST. Both methods' own comments discuss class_exists() by name, so
     * a naive substring search over the raw source matches the prose and reports the opposite of
     * the truth. That trap has already caught this project once.
     */
    public function test_both_payhere_paths_refuse_before_they_check_for_the_class(): void
    {
        $targets = [
            'PaymentService::processPayHere' => [
                app_path('Services/PaymentService.php'),
                'function processPayHere',
            ],
            'PayHerePaymnetController::planPayWithPayHere' => [
                app_path('Http/Controllers/PayHerePaymnetController.php'),
                'function planPayWithPayHere',
            ],
        ];

        foreach ($targets as $label => [$path, $needle]) {
            $src = $this->codeWithoutComments((string) file_get_contents($path));

            $start = strpos($src, $needle);
            $this->assertNotFalse($start, $label . ' has been renamed or removed.');

            $body = substr($src, $start);

            $refusal = strpos($body, 'the integration is retired');
            $guard   = strpos($body, 'class_exists');

            $this->assertNotFalse(
                $refusal,
                $label . ' no longer contains the deliberate retired-integration refusal. The '
                . 'class_exists() guard alone is NOT the owner\'s disable: it refuses because a '
                . 'package is missing, so a tree that vendored the SDK back in would resume '
                . 'charging through a retired gateway.'
            );

            $this->assertNotFalse($guard, $label . ' lost its class_exists() second line of defence.');

            $this->assertLessThan(
                $guard,
                $refusal,
                $label . ' checks for the class BEFORE it refuses, which makes the refusal '
                . 'conditional on the SDK being absent. The deliberate refusal must come first '
                . 'and be unconditional; class_exists() is only the fallback behind it.'
            );
        }
    }

    // -----------------------------------------------------------------------------------------
    // Helpers.
    // -----------------------------------------------------------------------------------------

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

    private function composerJson(): array
    {
        return $this->readJson(base_path('composer.json'));
    }

    private function composerLock(): array
    {
        return $this->readJson(base_path('composer.lock'));
    }

    private function readJson(string $path): array
    {
        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded, $path . ' is not valid JSON.');

        return $decoded;
    }

    /**
     * @return list<string>
     */
    private function lockedPackageNames(array $lock): array
    {
        $names = [];

        foreach (['packages', 'packages-dev'] as $section) {
            foreach ($lock[$section] ?? [] as $package) {
                $names[] = $package['name'];
            }
        }

        return $names;
    }
}
