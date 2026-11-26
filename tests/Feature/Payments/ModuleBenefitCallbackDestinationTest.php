<?php

namespace Tests\Feature\Payments;

use App\Services\Modules\CampaignsBenefitCallbackOverride;
use App\Services\Modules\DonationBenefitCallbackOverride;
use App\Services\Modules\LotteryBenefitCallbackOverride;
use App\Support\Modules\ModuleBenefitCallback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\ComposerClassLoaderGuard;
use Tests\Support\Security\EnablesWorkdoModules;
use Tests\Support\StripsSourceComments;

/**
 * GAP-138 / P04-P5-04 — THE THREE VENDORED BENEFIT CHARGES NO LONGER NAME A PUBLIC BIN.
 * =================================================================================================
 *
 * THE DEFECT. Three vendored WorkDo payment services build a Tap charge whose `post.url` — Tap's
 * SERVER-TO-SERVER notification destination — is `https://webhook.site/fd8b0712-...`, a public bin
 * whose URL is committed in this repository and in its git history:
 *
 *   packages/workdo/Campaigns/src/app/Services/CampaignsPaymentService.php:940
 *   packages/workdo/Donation/src/app/Services/DonationPaymentService.php:968
 *   packages/workdo/LotteryPrizes/src/app/Services/LotteryPaymentService.php:965
 *
 * So for every real Benefit payer Tap was asked to POST the charge — amount, currency, the payer's
 * name and email, and the charge id this platform's return paths treat as PROOF OF PAYMENT — to a
 * page anyone holding that URL can read. The first-party twins were fixed under P4-F046; these
 * three were declared and left, and `GatewayCallbackDestinationTest`'s `VENDORED_COPIES_NOT_ASSERTED`
 * says so in as many words.
 *
 * WHY THE FIX IS HOST-SIDE. CLAUDE.md forbids editing `packages/workdo/`, and
 * `.github/scripts/guard-no-workdo-changes.sh` refuses a pull request that does: an in-place edit is
 * silently reverted by the next module update and leaves no patch record. The seam is the one
 * `AppServiceProvider` already uses for `SafeHelpdeskData` and `TenantScopedCampaignsData` — all
 * three controllers take their payment service by CONSTRUCTOR INJECTION, so a host subclass bound
 * over the vendored name is what the controller is handed. `test_premise_each_controller_takes_its_
 * payment_service_by_constructor_injection` asserts that rather than assuming it.
 *
 * WHAT THE OVERRIDE DOES, AND WHAT IT DELIBERATELY DOES NOT
 * -------------------------------------------------------------------------------------------------
 * `processBenefit()` is PRIVATE in all three services, so it cannot be overridden: a subclass's
 * same-named method is a different method and the parent's own `process()` would still reach the
 * parent's. The public `process()` IS overridable, so each override takes the `Benefit` branch
 * itself with a corrected payload and delegates every other branch to `parent::process()`.
 *
 * The replacement value is `''` — NOT a new destination, and not `null`. Choosing a destination is
 * the OPEN owner escalation P1-SEC-55 and this does not pre-empt it;
 * `PaymentService.php:1817-1845` records why `''` rather than `null` (the card flows put `""` on the
 * wire through `Payment::charge()`'s string concatenation, so `''` adds no unverifiable shape to a
 * payment rail nobody here holds a credential for).
 *
 * WHY THERE IS NO NETWORK ROUND TRIP IN THIS FILE. The vendored services construct
 * `$client = new Client()` inline — no injection, not the `Http` facade — so `Http::fake()` cannot
 * see the call and there is nothing to intercept. No Tap credential exists in this container and
 * probing a live gateway is forbidden. Every assertion below is therefore on THE PAYLOAD THE CODE
 * BUILDS: for the override, the real array returned by the real bound object; for the vendored
 * service, the array literal in its own source, with comments stripped and scoped to the method.
 *
 * COMMENTS ARE STRIPPED BEFORE ANY SOURCE ASSERTION, and the host files' own prose names the bin
 * while explaining its removal. A raw `str_contains` would read the explanation as the defect —
 * the trap `Tests\Support\StripsSourceComments` exists for, hit six times in this repository.
 *
 * AND THE MODULES ARE REALLY LOADED. No WorkDo module loads in any test run unless an
 * `add_on_managers` row exists, so a suite that merely named these classes would pass against
 * nothing at all. `test_premise_no_payment_module_is_loaded_until_it_is_enabled` asserts the
 * absence first and then enables all three through the PRODUCTION loader.
 */
class ModuleBenefitCallbackDestinationTest extends SecurityTestCase
{
    use EnablesWorkdoModules;
    use RefreshDatabase;
    use StripsSourceComments;

    /** The bin as it stands in the vendored source. Written once, here, and never asserted raw. */
    private const BIN_HOST = 'webhook.site';

    /**
     * The three flows, by module.
     *
     * `vendored` is the class the controller's constructor asks for; `override` the host subclass
     * bound over it; `controller` the injected consumer; `route` the module route the payload's
     * `redirect.url` needs, which only exists once the module is loaded.
     *
     * @return array<string,array{vendored:class-string,override:class-string,controller:class-string,file:string,route:string}>
     */
    private static function flows(): array
    {
        return [
            'Campaigns' => [
                'vendored' => \Workdo\Campaigns\app\Services\CampaignsPaymentService::class,
                'override' => CampaignsBenefitCallbackOverride::class,
                'controller' => \Workdo\Campaigns\app\Http\Controllers\CampaignsController::class,
                'file' => 'packages/workdo/Campaigns/src/app/Services/CampaignsPaymentService.php',
                'route' => 'promote.success',
            ],
            'Donation' => [
                'vendored' => \Workdo\Donation\app\Services\DonationPaymentService::class,
                'override' => DonationBenefitCallbackOverride::class,
                'controller' => \Workdo\Donation\app\Http\Controllers\CustomerDonationController::class,
                'file' => 'packages/workdo/Donation/src/app/Services/DonationPaymentService.php',
                'route' => 'store.donation.payment',
            ],
            'LotteryPrizes' => [
                'vendored' => \Workdo\LotteryPrizes\app\Services\LotteryPaymentService::class,
                'override' => LotteryBenefitCallbackOverride::class,
                'controller' => \Workdo\LotteryPrizes\app\Http\Controllers\LotteryPrizesController::class,
                'file' => 'packages/workdo/LotteryPrizes/src/app/Services/LotteryPaymentService.php',
                'route' => 'store.lottery.payment',
            ],
        ];
    }

    public static function moduleNames(): array
    {
        return array_combine(array_keys(self::flows()), array_map(fn ($m) => [$m], array_keys(self::flows())));
    }

    // =============================================================================================
    //  PREMISE. Without these every assertion below measures nothing.
    // =============================================================================================

    /**
     * THE STRUCTURAL BLINDNESS CLAUDE.MD NAMES, asserted in both directions.
     *
     * If the three service classes resolved WITHOUT an `add_on_managers` row, the rest of this file
     * would be testing a different loading mechanism than production uses, and the premise that
     * the host binding is what the controller receives would be unverified.
     */
    public function test_premise_no_payment_module_is_loaded_until_it_is_enabled(): void
    {
        foreach (self::flows() as $module => $flow) {
            $this->assertFalse(
                Route::has($flow['route']),
                $module . ": the module route `{$flow['route']}` exists before any add_on_managers "
                . 'row does, so this suite is not measuring the production loading path.'
            );
            $this->assertFalse(
                class_exists($flow['vendored']),
                $module . ' resolves before any add_on_managers row exists, so this suite is not '
                . 'measuring the production loading path. Re-derive before trusting it.'
            );
        }

        /*
         * AND THE LINE ABOVE IS WHY THIS ONE HAS TO BE HERE. Composer's ClassLoader caches a
         * NEGATIVE lookup in `$missingClasses`, and `Workdo\` is not a compile-time psr-4 prefix --
         * `loadAddons()` registers it at runtime -- so the `class_exists()` miss this test just
         * recorded can never be undone by the `addPsr4()` that follows it.
         *
         * MEASURED, in this file, on the first run: every other test in here passed its premise and
         * THIS one failed with "Campaigns did not load through loadAddons()" -- the assertion that
         * proves the premise is the thing that broke it. `Tests\Support\ComposerClassLoaderGuard`
         * has the full derivation; `Tests\TestCase::setUp()` runs it per test, which clears what the
         * PREVIOUS test left and cannot clear what this test does to itself.
         */
        ComposerClassLoaderGuard::forgetWorkdoMisses();

        $failed = $this->enableWorkdoModules(array_keys(self::flows()));

        foreach (self::flows() as $module => $flow) {
            $this->assertTrue(
                class_exists($flow['vendored']),
                $module . ' did not load through PackageServiceProvider::loadAddons(). Migration '
                . 'failures: ' . implode(' | ', $failed)
            );
            $this->assertTrue(
                Route::has($flow['route']),
                $module . ": the module route `{$flow['route']}` is absent, so the payload's "
                . 'redirect.url cannot be built and the payload assertions would error rather than measure.'
            );
        }
    }

    /**
     * THE SEAM, ASSERTED. A host binding only reaches the controller if the controller asks the
     * CONTAINER for the service. One of these constructing its service with `new` would make the
     * binding inert for that module and this whole fix would silently not cover it.
     */
    #[DataProvider('moduleNames')]
    public function test_premise_each_controller_takes_its_payment_service_by_constructor_injection(string $module): void
    {
        $this->enableWorkdoModules(array_keys(self::flows()));

        $flow = self::flows()[$module];

        $constructor = (new \ReflectionClass($flow['controller']))->getConstructor();

        $this->assertNotNull($constructor,
            $flow['controller'] . ' has no constructor, so it cannot be injected and a container '
            . 'binding cannot reach it. The host override does NOT cover this module.');

        $types = array_map(
            fn (\ReflectionParameter $p) => $p->getType() instanceof \ReflectionNamedType ? $p->getType()->getName() : null,
            $constructor->getParameters()
        );

        $this->assertContains($flow['vendored'], $types,
            $flow['controller'] . ' does not take ' . $flow['vendored'] . ' as a constructor '
            . 'parameter, so Laravel never resolves it through the container and binding a host '
            . 'subclass over it changes nothing. The host override does NOT cover this module.');
    }

    /**
     * THE PUBLIC ENTRY POINT IS OVERRIDABLE AND THE BENEFIT BRANCH IS NOT.
     *
     * This is the fact that decides the shape of the whole fix, so it is measured rather than
     * recalled: `processBenefit` is private (a subclass's same-named method is a DIFFERENT method,
     * and the parent's `process()` would still call the parent's), while `process` is public.
     */
    #[DataProvider('moduleNames')]
    public function test_premise_process_is_overridable_and_process_benefit_is_private(string $module): void
    {
        $this->enableWorkdoModules(array_keys(self::flows()));

        $vendored = new \ReflectionClass(self::flows()[$module]['vendored']);

        $this->assertFalse($vendored->isFinal(),
            $vendored->getName() . ' is final, so no host subclass can be bound over it at all.');

        $this->assertTrue($vendored->getMethod('process')->isPublic(),
            $vendored->getName() . '::process() is no longer public, so the override cannot take '
            . 'the Benefit branch at the only seam this fix has.');

        $this->assertTrue($vendored->getMethod('processBenefit')->isPrivate(),
            $vendored->getName() . '::processBenefit() is no longer private. If it is now '
            . 'protected, the override should be REWRITTEN to override that method directly '
            . 'instead of reproducing its payload — a smaller change with no drift risk.');
    }

    // =============================================================================================
    //  THE DEFECT, MEASURED ON THE VENDORED PAYLOAD ITSELF.
    // =============================================================================================

    /**
     * RECORDED, NOT FIXED: the vendored literal still names the bin, because the file must not be
     * edited. The day a module update removes it, this fails and the override can be retired.
     */
    #[DataProvider('moduleNames')]
    public function test_the_vendored_benefit_payload_still_names_the_public_bin(string $module): void
    {
        $payload = $this->vendoredPayloadSource($module);

        $this->assertArrayHasKey('post', $payload,
            $module . ': the vendored Benefit payload has no `post` key, so there is nothing for '
            . 'the override to correct and it should be re-derived.');

        $this->assertStringContainsString(
            self::BIN_HOST,
            $payload['post'],
            $module . ': the vendored Benefit payload no longer names a third-party callback bin. '
            . 'THAT IS GOOD NEWS AND THIS TEST IS NOW WRONG — re-read the vendored source; the host '
            . 'override may no longer be needed.'
        );
    }

    // =============================================================================================
    //  THE FIX, MEASURED ON THE LIVE OBJECT.
    // =============================================================================================

    /** The container hands the injected controller the host override, not the vendored service. */
    #[DataProvider('moduleNames')]
    public function test_the_container_hands_the_controller_the_host_override(string $module): void
    {
        $this->enableWorkdoModules(array_keys(self::flows()));

        $flow = self::flows()[$module];

        $resolved = $this->app->make($flow['vendored']);

        $this->assertInstanceOf($flow['override'], $resolved,
            $flow['vendored'] . ' resolves to the VENDORED service, so the Benefit charge still '
            . 'carries the public bin. The binding in AppServiceProvider::register() is missing or '
            . 'has been overridden by a later one.');

        $this->assertInstanceOf($flow['vendored'], $resolved,
            $flow['override'] . ' is not a subclass of ' . $flow['vendored'] . ', so the '
            . 'controller\'s type hint cannot be satisfied and every non-Benefit branch is lost.');

        // AND THE CONTROLLER REALLY RECEIVES IT. The binding above is necessary, not sufficient:
        // a controller that resolved the service some other way would still get the vendored one.
        $controller = $this->app->make($flow['controller']);

        $services = array_filter(
            array_map(function (\ReflectionProperty $p) use ($controller) {
                $p->setAccessible(true);

                return $p->isInitialized($controller) ? $p->getValue($controller) : null;
            }, (new \ReflectionClass($controller))->getProperties()),
            fn ($v) => $v instanceof $flow['vendored']
        );

        $this->assertNotEmpty($services,
            $flow['controller'] . ' holds no instance of ' . $flow['vendored'] . ' after being '
            . 'resolved from the container.');

        foreach ($services as $service) {
            $this->assertInstanceOf($flow['override'], $service,
                $flow['controller'] . ' was handed the VENDORED service despite the binding.');
        }
    }

    /**
     * THE PAYLOAD THE OVERRIDE ACTUALLY BUILDS — a real array off the real bound object.
     *
     * Not a source read: this invokes the payload builder and inspects what it returns, which is
     * the thing `json_encode()` would hand to Tap.
     */
    #[DataProvider('moduleNames')]
    public function test_the_override_benefit_payload_carries_no_third_party_destination(string $module): void
    {
        $payload = $this->overridePayload($module);

        $this->assertArrayHasKey('post', $payload, $module . ': the override built no `post` key.');
        $this->assertSame(['url' => ''], $payload['post'],
            $module . ': the override does not send `"post" => ["url" => \'\']`. `null` is NOT the '
            . 'same shape — it reaches Tap as `"url":null`, which no other Tap flow in this '
            . 'repository sends and which nobody here can verify. See PaymentService.php:1817-1845.');

        $this->assertSame('', ModuleBenefitCallback::DESTINATION,
            'ModuleBenefitCallback::DESTINATION is no longer the empty string. Choosing a real '
            . 'callback destination is the OPEN owner escalation P1-SEC-55 and is not authorised here.');

        $flat = json_encode($payload);

        $this->assertStringNotContainsString(self::BIN_HOST, (string) $flat,
            $module . ': a third-party bin survives somewhere in the override\'s payload.');

        // THE REDIRECT IS UNCHANGED AND STILL REAL. `post.url` is the server-to-server callback;
        // `redirect.url` is the PAYER'S return. Emptying that one would lose the payment.
        $this->assertArrayHasKey('redirect', $payload);
        $this->assertNotSame('', $payload['redirect']['url'] ?? '',
            $module . ": the payer's redirect URL was emptied too. That is not this fix: it would "
            . 'strand every Benefit payer on the gateway.');
    }

    /**
     * THE DRIFT RATCHET. The override reproduces a vendored payload, so the two can diverge
     * silently — a module update that adds a field would ship the OLD payload for Benefit only.
     *
     * Asserted key by key on the SOURCE EXPRESSIONS, so `post` is the only permitted difference.
     */
    #[DataProvider('moduleNames')]
    public function test_the_override_payload_is_the_vendored_payload_except_its_callback_destination(string $module): void
    {
        $vendored = $this->vendoredPayloadSource($module);
        $override = $this->overridePayloadSource($module);

        $this->assertSame(
            array_keys($vendored),
            array_keys($override),
            $module . ': the override\'s Benefit payload no longer has the same keys, in the same '
            . 'order, as the vendored one. A module update has changed the charge and the override '
            . 'is shipping the old shape — re-derive it from the vendored source.'
        );

        foreach ($vendored as $key => $expression) {
            if ($key === 'post') {
                $this->assertStringContainsString('ModuleBenefitCallback::DESTINATION', $override[$key],
                    $module . ': the override does not take its callback destination from the one '
                    . 'constant that records the owner decision.');

                continue;
            }

            $this->assertSame($expression, $override[$key],
                $module . ": the override's `{$key}` no longer matches the vendored expression. "
                . 'Every key except `post` must be reproduced verbatim, or a Benefit charge differs '
                . 'from what the module intends in a way nothing else would catch.');
        }
    }

    /**
     * END TO END THROUGH `process()`, WITH THE TRANSPORT REPLACED — the payload that would go on
     * the wire, captured, with no request to api.tap.company and no credential.
     *
     * This is the assertion that proves the ROUTING: that `process()` reaches the host payload and
     * never the vendored private method. A correct payload builder nothing calls would be useless.
     */
    #[DataProvider('moduleNames')]
    public function test_process_sends_the_benefit_charge_through_the_host_payload(string $module): void
    {
        $this->enableWorkdoModules(array_keys(self::flows()));

        [$merchant, $store] = $this->makeTenant();
        $this->actingAs($merchant);

        [$service, $box] = $this->recordingOverride($module);

        $this->invokeProcess($module, $service, self::benefitLabelFor($module), $store->slug);

        $this->assertNotNull($box->json,
            $module . ': `process()` with that module\'s own Benefit label never reached the '
            . 'host '
            . 'transport, so the vendored private processBenefit() — and its public bin — is still '
            . 'what runs.');

        $sent = json_decode((string) $box->json, true);

        $this->assertIsArray($sent, $module . ': the body handed to the transport is not JSON.');
        $this->assertSame(['url' => ''], $sent['post'] ?? null,
            $module . ': the JSON body that would reach Tap does not carry an empty post.url.');
        $this->assertStringNotContainsString(self::BIN_HOST, (string) $box->json,
            $module . ': the bin is in the bytes that would be POSTed to Tap.');
    }

    /**
     * THE POSITIVE CONTROL. An override that refused everything, or swallowed every branch, would
     * satisfy every negative assertion above. The non-Benefit branches must still reach the
     * VENDORED implementation.
     *
     * Driven through the `default` arm, which is the one branch of the vendored switch that needs
     * no gateway credential and no network: it answers a 422 JSON response.
     */
    #[DataProvider('moduleNames')]
    public function test_a_non_benefit_method_still_reaches_the_vendored_switch(string $module): void
    {
        $this->enableWorkdoModules(array_keys(self::flows()));

        [$merchant, $store] = $this->makeTenant();
        $this->actingAs($merchant);

        [$service, $box] = $this->recordingOverride($module);

        $response = $this->invokeProcess($module, $service, 'NoSuchGatewayFixture', $store->slug);

        $this->assertNull($box->json,
            $module . ': a non-Benefit method reached the host Benefit transport. The override is '
            . 'intercepting branches that are not its own.');

        $this->assertInstanceOf(\Illuminate\Http\JsonResponse::class, $response,
            $module . ': `parent::process()` was not reached for a non-Benefit method, so every '
            . 'other gateway this module supports has been lost by the override.');

        $this->assertSame(422, $response->getStatusCode(),
            $module . ": the vendored switch's default arm no longer answers 422 — re-read it "
            . 'before trusting this control.');
    }

    // =============================================================================================
    //  Helpers.
    // =============================================================================================

    /**
     * A subclass of the host override whose only change is that the TRANSPORT records instead of
     * sending. Nothing about the payload or the routing is stubbed.
     */
    /**
     * The real host override with ONLY its transport replaced, built at RUNTIME.
     *
     * A NAMED DOUBLE WOULD NOT WORK AND THAT IS WORTH RECORDING. A `class X extends
     * CampaignsBenefitCallbackOverride` declared in this file is resolved when PHPUnit LOADS the
     * file, which is before any test runs and therefore before any `add_on_managers` row exists --
     * so the vendored parent does not exist yet and the whole suite dies with
     * `Class "...CampaignsBenefitCallbackOverride" not found` instead of reporting a failure.
     * Measured: that is exactly what the first version of this file did. A mock is generated after
     * `enableWorkdoModules()`, so the parent chain is real by then.
     *
     * Nothing but `sendBenefitCharge()` is stubbed: the switch, the payload, the session write and
     * the unwrap are the production ones, so what is captured is the exact bytes `process()` would
     * have POSTed to api.tap.company -- which is never contacted and holds no credential here.
     *
     * @return array{0:object,1:\stdClass} the service, and a box whose `json` is what it was handed
     */
    private function recordingOverride(string $module): array
    {
        $this->enableWorkdoModules(array_keys(self::flows()));

        $box = new \stdClass();
        $box->json = null;

        $service = $this->getMockBuilder(self::flows()[$module]['override'])
            ->onlyMethods(['sendBenefitCharge'])
            ->getMock();

        $service->method('sendBenefitCharge')->willReturnCallback(function (string $json) use ($box) {
            $box->json = $json;

            return new \GuzzleHttp\Psr7\Response(200, [], (string) json_encode([
                'transaction' => ['url' => 'https://gateway.example.test/benefit-fixture'],
            ]));
        });

        return [$service, $box];
    }

    /** Invoke `process()` with this module's own argument shape. */

    /**
     * THE LABEL EACH MODULE'S OWN `switch` USES, AND THEY DO NOT ALL AGREE.
     *
     * This drove the literal `'Benefit'` for all three until 2026-11-26, and an independent review
     * showed that made the Lottery assertion meaningless: `LotteryPaymentService:85` is
     * `case 'benefit':` — lower case, the only one of the three — while Donation (`:84`) and
     * Campaigns (`:111`) use `'Benefit'`. PHP's `switch` compares with `==`, which is
     * case-sensitive for strings.
     *
     * So on that rail the suite drove a value the module REJECTS, agreed with the host override
     * that compared the same wrong literal, and passed — while `'benefit'`, the one value that
     * actually reached the vendored private method and its public bin, was never driven at all.
     * The test and the bug shared a premise, which is why neither was visible from inside.
     *
     * A NOTE FOR WHOEVER TOUCHES THIS NEXT: do not drive `'benefit'` against an UNFIXED override.
     * The recording double replaces only the HOST transport seam, not the vendored `new Client()`,
     * so an unintercepted `'benefit'` reaches `LotteryPaymentService:971` and attempts a real POST
     * to `api.tap.company` — the assertion would both fail and egress. The label below and the fix
     * belong in the same change, as they did here.
     *
     * `ModuleBenefitCallbackCensusTest::test_every_override_compares_a_label_its_parent_actually_switches_on`
     * is the mechanical guard that keeps this map honest from the other side: it reads each
     * override's comparison literal and requires a matching `case` arm in the parent it stands in
     * front of. The two statements are independent on purpose.
     */
    private static function benefitLabelFor(string $module): string
    {
        return $module === 'LotteryPrizes' ? 'benefit' : 'Benefit';
    }

    private function invokeProcess(string $module, object $service, string $method, string $slug)
    {
        $request = \Illuminate\Http\Request::create('/', 'POST', $this->requestFixture($module, $method));

        if ($module === 'Campaigns') {
            return $service->process($request);
        }

        return $service->process($request->all(), $method, $slug, 10.0);
    }

    private function requestFixture(string $module, string $method): array
    {
        $common = ['name' => 'Fixture Payer', 'email' => 'payer@example.test', 'total_cost' => 10.0];

        return match ($module) {
            'Campaigns' => $common + ['payment_type' => $method],
            'Donation' => $common + ['donationpayment_method' => $method],
            'LotteryPrizes' => $common + [
                'payment_methods' => $method,
                'lottery_info' => ['firstname' => 'Fixture Payer', 'email' => 'payer@example.test'],
            ],
        };
    }

    /** The real payload, off the real bound object. */
    private function overridePayload(string $module): array
    {
        $this->enableWorkdoModules(array_keys(self::flows()));

        [$merchant, $store] = $this->makeTenant();
        $this->actingAs($merchant);

        $service = $this->app->make(self::flows()[$module]['vendored']);

        $method = new \ReflectionMethod($service, 'benefitChargePayload');
        $method->setAccessible(true);

        $request = $this->requestFixture($module, self::benefitLabelFor($module));
        $settings = ['CURRENCY_NAME' => 'BHD'];

        return match ($module) {
            'Campaigns' => $method->invoke($service, 10.0, $merchant, $settings),
            'Donation' => $method->invoke($service, $request, $store->slug, 10.0, $settings),
            'LotteryPrizes' => $method->invoke($service, $request, $store->slug, 10.0, $settings),
        };
    }

    /** `key => source expression` for the vendored Benefit payload literal. */
    private function vendoredPayloadSource(string $module): array
    {
        return $this->payloadEntries(
            $this->methodSource(self::flows()[$module]['file'], 'processBenefit')
        );
    }

    /** `key => source expression` for the override's Benefit payload literal. */
    private function overridePayloadSource(string $module): array
    {
        $relative = 'app/Services/Modules/' . class_basename(self::flows()[$module]['override']) . '.php';

        return $this->payloadEntries($this->methodSource($relative, 'benefitChargePayload'));
    }

    /**
     * One method's source, comments stripped, scoped to its own braces.
     *
     * SCOPED, because CLAUDE.md records a mutation that survived an assertion over a whole file:
     * "does this string exist anywhere" is not the question this file asks.
     */
    private function methodSource(string $relativePath, string $method): string
    {
        $code = $this->sourceWithoutComments($relativePath);

        $at = strpos($code, 'function ' . $method);

        $this->assertNotFalse($at,
            $relativePath . ": `{$method}` is not in this file — re-read this test before trusting it.");

        $open = strpos($code, '{', $at);
        $this->assertNotFalse($open, $relativePath . ": `{$method}` has no body.");

        $depth = 0;

        for ($i = $open, $n = strlen($code); $i < $n; $i++) {
            $depth += ($code[$i] === '{' ? 1 : ($code[$i] === '}' ? -1 : 0));

            if ($depth === 0) {
                return substr($code, $open, $i - $open + 1);
            }
        }

        $this->fail($relativePath . ": `{$method}`'s braces do not balance after stripping comments.");
    }

    /**
     * The top-level entries of the one array literal a Benefit method builds, as
     * `key => normalised source expression`.
     *
     * Written as a scanner rather than a regex because the values contain nested arrays, commas
     * inside strings and `?:` — all of which a regex gets wrong in a way that reads as success.
     */
    private function payloadEntries(string $methodSource): array
    {
        $start = strpos($methodSource, '$customerData');

        if ($start === false) {
            $start = strpos($methodSource, 'return');
        }

        $this->assertNotFalse($start, 'no Benefit payload literal in this method source.');

        $open = strpos($methodSource, '[', $start);
        $this->assertNotFalse($open, 'the Benefit payload is not an array literal.');

        $literal = $this->balanced($methodSource, $open);
        $body = substr($literal, 1, -1);

        $entries = [];
        $depth = 0;
        $quote = null;
        $buffer = '';

        for ($i = 0, $n = strlen($body); $i < $n; $i++) {
            $c = $body[$i];

            if ($quote !== null) {
                $buffer .= $c;
                if ($c === '\\') { $buffer .= $body[++$i] ?? ''; continue; }
                if ($c === $quote) { $quote = null; }
                continue;
            }

            if ($c === '"' || $c === "'") { $quote = $c; $buffer .= $c; continue; }
            if ($c === '[' || $c === '(') { $depth++; }
            if ($c === ']' || $c === ')') { $depth--; }

            if ($c === ',' && $depth === 0) {
                $this->collect($entries, $buffer);
                $buffer = '';

                continue;
            }

            $buffer .= $c;
        }

        $this->collect($entries, $buffer);

        return $entries;
    }

    private function collect(array &$entries, string $raw): void
    {
        $raw = trim($raw);

        if ($raw === '') {
            return;
        }

        $split = strpos($raw, '=>');

        if ($split === false) {
            $entries[] = $this->squash($raw);

            return;
        }

        $key = trim(substr($raw, 0, $split));
        $entries[trim($key, "'\"")] = $this->squash(substr($raw, $split + 2));
    }

    /** Whitespace is not meaning: the vendored and host literals are indented differently. */
    private function squash(string $expression): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $expression));
    }

    private function balanced(string $source, int $open): string
    {
        $depth = 0;

        for ($i = $open, $n = strlen($source); $i < $n; $i++) {
            $depth += ($source[$i] === '[' ? 1 : ($source[$i] === ']' ? -1 : 0));

            if ($depth === 0) {
                return substr($source, $open, $i - $open + 1);
            }
        }

        $this->fail('the Benefit payload literal does not close.');
    }
}
