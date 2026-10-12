<?php

namespace Tests\Feature\Commerce;

use App\Http\Controllers\PaymentController;
use App\Services\PaymentService;
use App\Services\PaymentVerificationService;
use Tests\TestCase;

/**
 * THE PAYMERA CONFIG BLOCK: declaring the keys the code already reads, WITHOUT
 * changing any behaviour and WITHOUT introducing any credential.
 *
 * THE ESTABLISHED FACT THIS RESTS ON. `config/services.php` had no `paymera`
 * block at all -- `git grep -in paymera -- config/` returned nothing -- so every
 * `config('services.paymera.*')` lookup in the codebase resolved to null. Two of
 * those lookups matter:
 *
 *   - PaymentController::paymeraStatusCredential() reads
 *     config('services.paymera.username'). That key has never existed, and it
 *     dates to the initial commit (3c39c160), so the status poll could never
 *     authenticate in any deployment. The real per-store credential is a
 *     SETTING -- Utility::GetValueByName('PAYMERA_USERNAME', $store->id), used
 *     by PaymentService.php:383 -- not an env var.
 *
 *   - PaymentVerificationService::paymeraSignatureIsRequired() reads
 *     config('services.paymera.require_callback_signature') and DEFAULTS TO
 *     TRUE, so an unconfigured signature scheme REJECTS every Paymera callback.
 *     That is the intended fail-closed posture, and the owner's documented
 *     escape hatch is to set that one flag to false -- which was unreachable
 *     without editing code, because the key was not declared anywhere.
 *
 * WHAT THIS SUITE PROVES. That adding the block changed NOTHING: every
 * behaviour-bearing default is identical to the null/true/empty it resolved to
 * before, the fail-closed paths still fail closed, and no credential value is
 * present in the block. It is a regression test against the block being
 * "helpfully" filled in later.
 *
 * WHAT IS NOT PROVEN, and must not be. Nothing here asserts that Paymera
 * checkout works. It does not: the signature scheme is GATE G(4) and the status
 * credential is pointed at a key that is not the real source. Both remain open
 * and are reported, not papered over.
 */
class PaymeraConfigSourceTest extends TestCase
{
    // -----------------------------------------------------------------
    // No credential is introduced.
    // -----------------------------------------------------------------

    /**
     * Every credential-bearing key resolves to EMPTY. This is the assertion that
     * fails loudly if a real value is ever pasted into the block.
     *
     * No value is printed in any failure message: the assertions are on
     * emptiness, so a leaked secret could not be echoed by a failure.
     */
    public function test_no_paymera_credential_value_is_present_in_configuration(): void
    {
        foreach ([
            'services.paymera.username',
            'services.paymera.callback_signature_secret',
            'services.paymera.callback_signature_field',
            'services.paymera.callback_signature_algorithm',
            'services.paymera.callback_amount_field',
        ] as $key) {
            $this->assertEmpty(
                config($key),
                $key . ' must carry no value: credentials belong in the environment, '
                . 'never in a tracked config file'
            );
        }
    }

    /** The block exists, so the keys are declared rather than silently absent. */
    public function test_the_paymera_block_is_declared(): void
    {
        $this->assertIsArray(config('services.paymera'),
            'the keys the Paymera code reads must be declared somewhere');

        $this->assertArrayHasKey('require_callback_signature', config('services.paymera'));
        $this->assertArrayHasKey('username', config('services.paymera'));
    }

    // -----------------------------------------------------------------
    // Behaviour is byte-for-byte preserved.
    // -----------------------------------------------------------------

    /**
     * THE FAIL-CLOSED DEFAULT IS UNCHANGED. Declaring the key must not have
     * relaxed it: with nothing configured, a Paymera callback is still required
     * to carry a signature and is therefore still rejected.
     */
    public function test_the_callback_signature_is_still_required_by_default(): void
    {
        $this->assertTrue(
            app(PaymentVerificationService::class)->paymeraSignatureIsRequired(),
            'the default must remain fail-closed; declaring the key must not loosen it'
        );
    }

    /** And the hatch is a real one: setting the flag false is honoured. */
    public function test_the_documented_escape_hatch_is_reachable(): void
    {
        config(['services.paymera.require_callback_signature' => false]);

        $this->assertFalse(
            app(PaymentVerificationService::class)->paymeraSignatureIsRequired(),
            'the owner must be able to restore the previous behaviour by configuration '
            . 'rather than by editing verification code'
        );
    }

    /**
     * The signature scheme is still UNCONFIGURED, so verification still refuses.
     * `callback_signature_payload` must be an empty ARRAY -- the verifier
     * requires a non-empty array, and an empty one is what keeps it closed.
     */
    public function test_an_unconfigured_signature_scheme_still_fails_closed(): void
    {
        $this->assertSame([], config('services.paymera.callback_signature_payload'),
            'no signed-field list may be invented; GATE G(4) supplies it');

        $result = app(PaymentVerificationService::class)
            ->verifyPaymeraSignature(['anything' => 'at all', 'signature' => 'deadbeef']);

        $this->assertFalse($result->isVerified(),
            'an unconfigured scheme must never report a valid signature');
        $this->assertSame('paymera_signature_scheme_not_configured', $result->reason);
    }

    /**
     * THE STATUS POLL STILL FAILS CLOSED. With no credential it makes no call at
     * all and returns the sentinel, so a missing credential can never be
     * mistaken for a successful capture.
     *
     * This also makes no network request, which is why it is safe to run here.
     */
    public function test_the_status_poll_still_refuses_to_run_unauthenticated(): void
    {
        $controller = new PaymentController(app(PaymentService::class));

        $result = $controller->pollPaymentStatus('any-payment-id');

        $this->assertSame(
            PaymentController::PAYMERA_STATUS_UNAUTHENTICATED,
            $result['status'] ?? null,
            'with no credential the poll must return the sentinel, not a status'
        );
    }

    /**
     * The captured-amount field probe is UNCHANGED. With no configured field
     * name the existing candidate list still runs, so capture verification
     * behaves exactly as before the block was added.
     */
    public function test_the_captured_amount_field_probe_is_unchanged(): void
    {
        $verifier = app(PaymentVerificationService::class);

        // A payload using one of the existing candidate names still verifies
        // against a matching total.
        $verified = $verifier->verifyPaymeraCapture(
            ['Data' => ['capturedAmount' => '115.00']],
            '115.00'
        );

        $this->assertTrue($verified->isVerified(),
            'the candidate-name probe must still work with no configured field');

        // And a mismatch is still rejected rather than waved through.
        $rejected = $verifier->verifyPaymeraCapture(
            ['Data' => ['capturedAmount' => '1.00']],
            '115.00'
        );

        $this->assertFalse($rejected->isVerified());
        $this->assertSame('paymera_capture_amount_mismatch', $rejected->reason);
    }

    // -----------------------------------------------------------------
    // The credential-source finding itself, pinned so it cannot be
    // silently "fixed" without the owner.
    // -----------------------------------------------------------------

    /**
     * THE TWO SOURCES STILL DISAGREE, and that is recorded here deliberately.
     *
     * The checkout call authenticates with the per-store SETTING; the status
     * poll reads a global CONFIG key. The correct minimal change is to point the
     * poll at the same per-store setting, because the credential is per store
     * and a single global env var cannot express that on a shared-database
     * multi-tenant platform.
     *
     * It is NOT made here because pollPaymentStatus() is the sole arbiter of
     * whether a Paymera order is created, so repointing it changes live payment
     * behaviour for a real merchant's money -- an owner decision. This test
     * asserts the CURRENT state, so that whoever changes it has to change this
     * assertion too and thereby state what they did.
     */
    public function test_the_checkout_and_poll_credential_sources_are_still_different(): void
    {
        $checkout = $this->executableSourceOf(PaymentService::class, 'processPaymera');
        $poll = $this->executableSourceOf(PaymentController::class, 'paymeraStatusCredential');

        $this->assertStringContainsString("GetValueByName('PAYMERA_USERNAME'", $checkout,
            'the checkout call authenticates with the per-store setting');

        $this->assertStringContainsString("config('services.paymera.username')", $poll,
            'the status poll still reads the global config key; repointing it to the '
            . 'per-store setting is the documented owner decision, not a silent fix');
    }

    /**
     * The test gateway host is still hardcoded, and still reported.
     *
     * `egate-t.paymera.cc` is Paymera's TEST gateway. There is no way to point
     * production at the live one without editing that line, which is a
     * deployment decision for the owner.
     */
    public function test_the_hardcoded_test_gateway_host_is_still_present_and_reported(): void
    {
        $source = $this->executableSourceOf(PaymentController::class, 'pollPaymentStatus');

        $this->assertStringContainsString('egate-t.paymera.cc', $source,
            'if this host ever changes, the deployment decision was taken and the '
            . 'open finding must be updated rather than quietly dropped');
    }

    /** A method's executable source, comments stripped. */
    private function executableSourceOf(string $class, string $method): string
    {
        $m = new \ReflectionMethod($class, $method);

        $source = implode('', array_slice(
            file($m->getFileName()),
            $m->getStartLine() - 1,
            $m->getEndLine() - $m->getStartLine() + 1
        ));

        $code = '';

        foreach (token_get_all('<?php ' . $source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
    }
}
