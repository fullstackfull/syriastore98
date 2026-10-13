<?php

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * THE FAIL-CLOSED POSTURE IS NOT A SILENT KILL SWITCH.
 * =============================================================================================
 *
 * config/payments.php disables plan activation for twelve payment providers whose return
 * handlers cannot be verified from this environment. A switch like that is dangerous in exactly
 * two directions, and this file nails both:
 *
 *   DRIFT TOWARDS OPEN -- someone flips a default to true, or deletes an entry, and a free
 *   activation quietly comes back. Each provider's shipped default is asserted here, read from
 *   the FILE rather than from the loaded config, so setting the env var in a CI job cannot hide
 *   a changed default.
 *
 *   DRIFT TOWARDS CLOSED AND FORGOTTEN -- a provider is switched off and nobody records why or
 *   what would turn it back on, so it stays off forever and nobody can tell whether that is
 *   deliberate. Every entry is required to carry its env key, a REASON and RE-ENABLE
 *   conditions, and this file fails if one does not.
 *
 * It also asserts that a provider which DOES verify with the gateway is NOT disabled, and that
 * the two lists together account for every plan-purchase writer -- so a provider cannot end up
 * in neither list and escape classification altogether.
 *
 * WHAT THIS FILE IS NOT. It is not the proof that the refusal works; that is
 * PlanPaymentFailClosedTest, which drives each route and asserts the 403, the absent
 * plan_orders row and the unchanged entitlement. This file is the proof that the POSTURE is
 * what it claims to be and stays that way.
 */
class PlanPaymentPostureTest extends TestCase
{
    /**
     * The twelve forgeable providers, each with the env key its entry must read.
     *
     * FAIL-CLOSED -- DISABLED PENDING VERIFICATION, every one. None of these is verified.
     */
    public static function failClosedProviders(): array
    {
        return [
            'Nepalste'    => ['nepalste', 'PLAN_ACTIVATION_NEPALSTE'],
            'PayHere'     => ['payhere', 'PLAN_ACTIVATION_PAYHERE'],
            'PhonePe'     => ['phonepe', 'PLAN_ACTIVATION_PHONEPE'],
            'Paddle'      => ['paddle', 'PLAN_ACTIVATION_PADDLE'],
            'FedPay'      => ['fedpay', 'PLAN_ACTIVATION_FEDPAY'],
            'Easebuzz'    => ['easebuzz', 'PLAN_ACTIVATION_EASEBUZZ'],
            'DPOPay'      => ['dpopay', 'PLAN_ACTIVATION_DPOPAY'],
            'Yookassa'    => ['yookassa', 'PLAN_ACTIVATION_YOOKASSA'],
            'SenangPay'   => ['senangpay', 'PLAN_ACTIVATION_SENANGPAY'],
            'PayTR'       => ['paytr', 'PLAN_ACTIVATION_PAYTR'],
            'PaiementPro' => ['paiementpro', 'PLAN_ACTIVATION_PAIEMENTPRO'],
            'Ozow'        => ['ozow', 'PLAN_ACTIVATION_OZOW'],

            // Found by closing a hole in the audit's own census rather than by the census; see
            // PlanPaymentVerificationInventoryTest for the four price-write shapes it missed.
            'CoinGate'    => ['coingate', 'PLAN_ACTIVATION_COINGATE'],
            'PayTabs'     => ['paytabs', 'PLAN_ACTIVATION_PAYTABS'],
            'SSLCommerz'  => ['sslcommerz', 'PLAN_ACTIVATION_SSLCOMMERZ'],
        ];
    }

    /** Which controller consults which map key, and the method that does it. */
    public static function providerCallSites(): array
    {
        return [
            'Nepalste'    => ['nepalste', 'NepalstePaymnetController'],
            'PayHere'     => ['payhere', 'PayHerePaymnetController'],
            'PhonePe'     => ['phonepe', 'PhonePePaymentController'],
            'Paddle'      => ['paddle', 'PaddlePaymentController'],
            'FedPay'      => ['fedpay', 'FedPayPaymentController'],
            'Easebuzz'    => ['easebuzz', 'EasebuzzController'],
            'DPOPay'      => ['dpopay', 'DPOPayController'],
            'Yookassa'    => ['yookassa', 'YookassaController'],
            'SenangPay'   => ['senangpay', 'SenangPayController'],
            'PayTR'       => ['paytr', 'PaytrController'],
            'PaiementPro' => ['paiementpro', 'PaiementProPaymentController'],
            'Ozow'        => ['ozow', 'OzowController'],
            'CoinGate'    => ['coingate', 'CoingateController'],
            'PayTabs'     => ['paytabs', 'PaytabsController'],
            'SSLCommerz'  => ['sslcommerz', 'SSLCommerzPaymentController'],
        ];
    }

    private function configSource(): string
    {
        $path = config_path('payments.php');
        $this->assertFileExists($path, 'config/payments.php has been deleted. Every plan-payment '
            . 'guard reads it, and a missing key fails closed -- so deleting it refuses all '
            . 'twelve providers rather than opening them, but the posture documentation is gone.');

        return (string) file_get_contents($path);
    }

    #[DataProvider('failClosedProviders')]
    public function test_a_fail_closed_provider_is_disabled_in_the_loaded_config(string $key): void
    {
        $value = config('payments.plan_activation.' . $key);

        $this->assertNotNull(
            $value,
            $key . ' is no longer present in config(payments.plan_activation). A missing key is '
            . 'treated as DISABLED by GuardsPlanActivation, so this is not an open door -- but '
            . 'the entry carried the REASON and the RE-ENABLE CONDITIONS, and those are now lost.'
        );

        $this->assertFalse(
            filter_var($value, FILTER_VALIDATE_BOOLEAN),
            $key . ' now ALLOWS plan activation. That provider\'s return handler cannot verify '
            . 'with the gateway, so enabling it restores a free-activation path: one '
            . 'authenticated request activates any paid plan. If the gateway verification has '
            . 'genuinely been implemented, move this provider out of failClosedProviders() and '
            . 'into PlanPaymentVerificationInventoryTest::verifiedHandlers() with its marker.'
        );
    }

    #[DataProvider('failClosedProviders')]
    public function test_the_shipped_default_in_the_file_is_disabled_and_documented(
        string $key,
        string $envKey
    ): void {
        $src = $this->configSource();

        // Read from the FILE, not from config(): if the env var happened to be set in whatever
        // environment this runs in, the loaded value would be true and a changed default would
        // slip past the test above.
        $this->assertStringContainsString(
            "'" . $key . "' => env('" . $envKey . "', false)",
            $src,
            $key . "'s shipped default is no longer `env('" . $envKey . "', false)`. The default "
            . 'must be DISABLED so that a deployment which has never heard of this setting is '
            . 'safe, and it must stay env-driven so the owner can re-enable one provider '
            . 'without a code change.'
        );

        // The entry must say why it is off and what would turn it back on. A switch with no
        // recorded reason is how a provider stays disabled for a year with nobody able to say
        // whether that was deliberate.
        $entry = $this->commentBlockFor($src, $key);

        $this->assertStringContainsString('REASON:', $entry,
            $key . ' has no REASON recorded in config/payments.php.');
        $this->assertStringContainsString('RE-ENABLE', $entry,
            $key . ' has no RE-ENABLE conditions recorded in config/payments.php.');
        $this->assertStringContainsString($envKey, $entry,
            $key . "'s comment block does not name its env key " . $envKey . '.');
    }

    /**
     * The comment block immediately preceding a provider's entry.
     */
    private function commentBlockFor(string $src, string $key): string
    {
        $needle = "'" . $key . "' => env(";
        $at     = strpos($src, $needle);

        $this->assertNotFalse($at, 'No entry for ' . $key . ' in config/payments.php.');

        // Back up to the start of the preceding /* ... */ block.
        $start = strrpos(substr($src, 0, $at), '/*');
        $this->assertNotFalse($start, $key . ' has no comment block in config/payments.php.');

        return substr($src, $start, $at - $start);
    }

    #[DataProvider('providerCallSites')]
    public function test_the_handler_actually_consults_the_map_for_its_own_provider_key(
        string $key,
        string $controller
    ): void {
        $path = app_path('Http/Controllers/' . $controller . '.php');
        $this->assertFileExists($path);

        $src = (string) file_get_contents($path);

        // A typo'd key would fail closed rather than open (assertPlanActivationEnabled treats a
        // missing config key as disabled and logs it), so the risk this catches is the opposite
        // one: the guard being removed, or renamed to a key nobody disables.
        $this->assertStringContainsString(
            "assertPlanActivationEnabled('" . $key . "'",
            $src,
            $controller . ' no longer calls assertPlanActivationEnabled(\'' . $key . '\'). '
            . 'Without it this handler activates paid plans on caller-supplied data again. '
            . 'PlanPaymentFailClosedTest proves the refusal behaviourally; this assertion '
            . 'catches the guard being dropped from a handler that has no behavioural test yet.'
        );
    }

    /**
     * A PROVIDER THAT VERIFIES WITH THE GATEWAY MUST NOT BE DISABLED.
     *
     * Fail-closed is the right answer for an unverifiable provider and the WRONG answer for a
     * working one: disabling CinetPay or Powertranz would break live subscription sales for no
     * security gain. The map must therefore contain the twelve and only the twelve.
     */
    public function test_no_verified_provider_has_been_swept_onto_the_disabled_map(): void
    {
        $map = array_keys((array) config('payments.plan_activation'));
        sort($map);

        $expected = array_map(fn ($row) => $row[0], array_values(self::failClosedProviders()));
        sort($expected);

        $this->assertSame(
            $expected,
            $map,
            "config('payments.plan_activation') no longer holds exactly the twelve forgeable "
            . "providers.\n  Added entries disable a provider that may well verify with its "
            . "gateway, which breaks real subscription sales.\n  Removed entries lose that "
            . "provider's recorded reason and re-enable conditions.\n  Either way, update "
            . 'failClosedProviders() here and say why in the commit.'
        );
    }

    /**
     * The eight verified providers are documented, and documented as verified -- not disabled.
     */
    public function test_every_verified_provider_is_recorded_with_its_verification_marker(): void
    {
        $verified = (array) config('payments.plan_activation_provider_verified');

        $expected = [
            'TapPaymnetController',
            'CashfreeController',
            'BenefitPaymentController',
            'PaynowController',
            'PaytmPaymentController',
            'XenditPaymentController',
            'CinetPayController',
            'PowertranzPaymentController',
            'CyberSourceController',
            'MolliePaymentController',
            'StripePaymentController',
        ];

        foreach ($expected as $controller) {
            $this->assertArrayHasKey(
                $controller,
                $verified,
                $controller . ' is no longer recorded in '
                . "config('payments.plan_activation_provider_verified'). That list is what keeps "
                . 'a provider from falling between the two stools: neither disabled nor '
                . 'documented as verifying.'
            );

            $this->assertNotEmpty(
                $verified[$controller],
                $controller . ' has an empty verification marker, so the file no longer records '
                . 'WHAT it verifies.'
            );
        }

        $this->assertSame(
            [],
            array_values(array_diff(array_keys($verified), $expected)),
            'An unexpected controller is listed as provider-verified. Verification is a claim '
            . 'about money moving; it has to be hand-read from the controller, not added to a '
            . 'list.'
        );
    }
}
