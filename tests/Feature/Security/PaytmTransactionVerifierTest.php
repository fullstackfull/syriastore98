<?php

namespace Tests\Feature\Security;

use App\Services\PaytmTransactionVerifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * F229 / D-31 REQUIREMENT 2 — the Paytm status check itself, driven directly.
 *
 * The controller tests (`PaytmIntentBoundActivationTest`, `PlanActivationReplayGuardTest`) bind a
 * double in this class's place, which is right for them: they are about what the *controller* does
 * with each of the three answers. Nothing drove the class that produces those answers, and
 * independent review of 2f011efb found two defects inside it that no controller test could see:
 *
 *   1. it reached the network through the SDK's `callAPI()`, which sets `CURLOPT_SSL_VERIFYPEER`
 *      and `CURLOPT_SSL_VERIFYHOST` to **0** — so the authoritative answer was writable by anyone
 *      who could answer for the host, and a "server-to-server verification" was a verification of
 *      nothing;
 *   2. it never looked at the status response's own `CHECKSUMHASH`.
 *
 * Both are now fixed, and both are pinned here. The first is pinned two ways — behaviourally
 * (`Http::fake()` can only intercept this because the transport is no longer `callAPI()`) and from
 * the source (`'verify' => true` is passed explicitly, and the class must not reach for the SDK's
 * status provider again).
 *
 * `Http::preventStrayRequests()` runs in every case, so a path that reaches a real network fails
 * rather than silently taking twenty seconds and returning UNVERIFIABLE — which is exactly how a
 * control that never runs looks like a control that always passes.
 */
class PaytmTransactionVerifierTest extends TestCase
{
    private const KEY = 'a-merchant-key-only-the-server-holds';
    private const MID = 'StoreS12345678901234';
    private const REFERENCE = 'a1b2c3d4e5f60718';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config([
            'services.paytm-wallet.merchant_key' => self::KEY,
            'services.paytm-wallet.merchant_id' => self::MID,
            'services.paytm-wallet.env' => 'staging',
        ]);
    }

    /** The SDK's globals live behind a plain `require` in a class file; touching it loads them. */
    private function mintChecksumOver(array $body): string
    {
        class_exists(\Anand\LaravelPaytmWallet\Providers\PaytmWalletProvider::class);

        return getChecksumFromArray($body, self::KEY);
    }

    private function signed(array $body): array
    {
        return $body + ['CHECKSUMHASH' => $this->mintChecksumOver($body)];
    }

    private function answering(array $body, int $status = 200): void
    {
        Http::fake(['*securegw*paytm.in/merchant-status/getTxnStatus' => Http::response($body, $status)]);
    }

    private function verifier(): PaytmTransactionVerifier
    {
        return new PaytmTransactionVerifier();
    }

    public function test_premise_the_sdk_checksum_helpers_are_reachable_from_this_suite(): void
    {
        /* If this fails, every "the checksum did not verify" result below is true for the wrong
           reason and the whole file proves nothing. */
        class_exists(\Anand\LaravelPaytmWallet\Providers\PaytmWalletProvider::class);

        $this->assertTrue(function_exists('getChecksumFromArray'));
        $this->assertTrue(function_exists('verifychecksum_e'));
        $this->assertSame('TRUE', verifychecksum_e(
            ['MID' => 'x', 'ORDERID' => 'y', 'CHECKSUMHASH' => $this->mintChecksumOver(['MID' => 'x', 'ORDERID' => 'y'])],
            self::KEY,
            $this->mintChecksumOver(['MID' => 'x', 'ORDERID' => 'y'])
        ));
    }

    public function test_a_correctly_checksummed_success_is_verified_on_the_responses_own_checksum(): void
    {
        $this->answering($this->signed([
            'STATUS' => 'TXN_SUCCESS',
            'TXNAMOUNT' => '499.00',
            'CURRENCY' => 'INR',
            'TXNID' => '20261031111212800110168659801234567',
            'ORDERID' => self::REFERENCE,
        ]));

        $answer = $this->verifier()->verify(self::REFERENCE);

        $this->assertSame(PaytmTransactionVerifier::VERIFIED, $answer['state']);
        $this->assertSame('499.00', $answer['amount'], 'the identity normaliser must not rescale Paytm major units');
        $this->assertSame('INR', $answer['currency']);
        $this->assertSame('20261031111212800110168659801234567', $answer['txn_id']);
        $this->assertSame('authenticated by the response checksum', $answer['reason']);
    }

    public function test_a_success_with_no_checksum_is_verified_on_the_validated_certificate_instead(): void
    {
        /* Paytm's getTxnStatus contract is not established by sandbox evidence in this project, so
           an absent checksum must not be treated as a failure. It is not treated as nothing
           either: `'verify' => true` means the chain for this host was validated before these
           bytes were read, and the return value names that as the basis. */
        $this->answering(['STATUS' => 'TXN_SUCCESS', 'TXNAMOUNT' => '499.00', 'CURRENCY' => 'INR']);

        $answer = $this->verifier()->verify(self::REFERENCE);

        $this->assertSame(PaytmTransactionVerifier::VERIFIED, $answer['state']);
        $this->assertSame('authenticated by the validated TLS certificate of securegw-stage.paytm.in', $answer['reason']);
    }

    public function test_a_success_whose_checksum_does_not_verify_is_unverifiable_and_not_a_refusal(): void
    {
        $this->answering([
            'STATUS' => 'TXN_SUCCESS',
            'TXNAMOUNT' => '499.00',
            'CHECKSUMHASH' => $this->mintChecksumOver(['STATUS' => 'TXN_SUCCESS', 'TXNAMOUNT' => '1.00']),
        ]);

        $answer = $this->verifier()->verify(self::REFERENCE);

        /* UNVERIFIABLE, not REFUSED, and the distinction is load-bearing: `recordRefusal()` is
           terminal, so labelling an ambiguous mismatch a refusal would destroy a real payment
           that a human could otherwise reconcile. Both outcomes block the activation. */
        $this->assertSame(PaytmTransactionVerifier::UNVERIFIABLE, $answer['state']);
        $this->assertSame('the status response carried a checksum that did not verify', $answer['reason']);
        $this->assertNull($answer['amount'], 'nothing from an unauthenticated body may be carried forward');
    }

    public function test_a_garbage_checksum_does_not_crash_the_check(): void
    {
        /* `decrypt_e_openssl()` on a non-base64 string warns and returns false. A control that
           throws where it should refuse is a 500, and a 500 on a payment return is its own defect. */
        $this->answering(['STATUS' => 'TXN_SUCCESS', 'TXNAMOUNT' => '499.00', 'CHECKSUMHASH' => 'not-a-checksum-at-all']);

        $answer = $this->verifier()->verify(self::REFERENCE);

        $this->assertSame(PaytmTransactionVerifier::UNVERIFIABLE, $answer['state']);
    }

    public function test_a_provider_failure_is_a_refusal_which_is_final(): void
    {
        $this->answering($this->signed(['STATUS' => 'TXN_FAILURE', 'TXNAMOUNT' => '499.00', 'RESPMSG' => 'Txn Failed']));

        $answer = $this->verifier()->verify(self::REFERENCE);

        $this->assertSame(PaytmTransactionVerifier::REFUSED, $answer['state']);
        $this->assertSame('Paytm reports STATUS TXN_FAILURE', $answer['reason']);
    }

    public function test_a_pending_transaction_is_a_refusal_rather_than_a_pass(): void
    {
        $this->answering($this->signed(['STATUS' => 'PENDING', 'TXNAMOUNT' => '499.00']));

        $this->assertSame(PaytmTransactionVerifier::REFUSED, $this->verifier()->verify(self::REFERENCE)['state']);
    }

    public function test_success_without_an_amount_cannot_be_matched_so_it_is_not_verified(): void
    {
        $this->answering($this->signed(['STATUS' => 'TXN_SUCCESS', 'CURRENCY' => 'INR']));

        $answer = $this->verifier()->verify(self::REFERENCE);

        $this->assertSame(PaytmTransactionVerifier::UNVERIFIABLE, $answer['state']);
        $this->assertSame('Paytm reported success without a TXNAMOUNT', $answer['reason']);
    }

    public function test_an_unreachable_provider_is_unverifiable(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 60: SSL certificate problem'));

        $answer = $this->verifier()->verify(self::REFERENCE);

        $this->assertSame(PaytmTransactionVerifier::UNVERIFIABLE, $answer['state']);
        $this->assertStringContainsString('the status call failed', $answer['reason']);
    }

    public function test_an_http_error_is_unverifiable(): void
    {
        $this->answering(['error' => 'nope'], 503);

        $answer = $this->verifier()->verify(self::REFERENCE);

        $this->assertSame(PaytmTransactionVerifier::UNVERIFIABLE, $answer['state']);
        $this->assertSame('Paytm answered HTTP 503', $answer['reason']);
    }

    public function test_a_response_that_is_not_an_object_is_unverifiable(): void
    {
        Http::fake(['*' => Http::response('TXN_SUCCESS', 200, ['Content-Type' => 'text/plain'])]);

        $this->assertSame(PaytmTransactionVerifier::UNVERIFIABLE, $this->verifier()->verify(self::REFERENCE)['state']);
    }

    /** @dataProvider missingCredential */
    public function test_a_missing_credential_refuses_without_asking_anyone(string $key, string $reason): void
    {
        config(['services.paytm-wallet.' . $key => '']);
        Http::fake();

        $answer = $this->verifier()->verify(self::REFERENCE);

        $this->assertSame(PaytmTransactionVerifier::UNVERIFIABLE, $answer['state']);
        $this->assertSame($reason, $answer['reason']);
        Http::assertNothingSent();
    }

    public static function missingCredential(): array
    {
        return [
            'no merchant key' => ['merchant_key', 'no merchant key is configured'],
            'no merchant id' => ['merchant_id', 'no merchant id is configured'],
        ];
    }

    public function test_an_empty_reference_asks_nothing(): void
    {
        Http::fake();

        $this->assertSame(PaytmTransactionVerifier::UNVERIFIABLE, $this->verifier()->verify('   ')['state']);
        Http::assertNothingSent();
    }

    public function test_the_question_carries_the_mid_the_order_and_a_checksum_over_both(): void
    {
        $this->answering($this->signed(['STATUS' => 'TXN_SUCCESS', 'TXNAMOUNT' => '499.00']));

        $this->verifier()->verify(self::REFERENCE);

        Http::assertSent(function ($request) {
            /* `callAPI()`'s wire format, copied rather than reimplemented: a urlencoded
               `JsonData=` body under a `Content-Type: application/json` header. */
            parse_str($request->body(), $form);

            $this->assertArrayHasKey('JsonData', $form);
            $sent = json_decode((string) $form['JsonData'], true);

            $this->assertSame(self::MID, $sent['MID']);
            $this->assertSame(self::REFERENCE, $sent['ORDERID']);
            $this->assertNotEmpty($sent['CHECKSUMHASH']);
            $this->assertSame('TRUE', verifychecksum_e($sent, self::KEY, $sent['CHECKSUMHASH']), 'the question must be signed with the server-held key');

            return true;
        });
    }

    /** @dataProvider mode */
    public function test_the_mode_decides_the_host_the_sdks_own_way(string $env, string $host): void
    {
        config(['services.paytm-wallet.env' => $env]);
        $this->answering($this->signed(['STATUS' => 'TXN_SUCCESS', 'TXNAMOUNT' => '499.00']));

        $this->verifier()->verify(self::REFERENCE);

        Http::assertSent(fn ($request) => $request->url() === 'https://' . $host . '/merchant-status/getTxnStatus');
    }

    public static function mode(): array
    {
        return [
            'production' => ['production', 'securegw.paytm.in'],
            'sandbox' => ['staging', 'securegw-stage.paytm.in'],
            /* The SDK's comparison is `== 'production'`, so anything else is the sandbox. An
               unconfigured mode must not silently address the live host. */
            'unset' => ['', 'securegw-stage.paytm.in'],
            'nonsense' => ['PRODUCTION', 'securegw-stage.paytm.in'],
        ];
    }

    public function test_the_transport_validates_tls_and_does_not_go_through_the_sdk(): void
    {
        /*
         * A SOURCE ASSERTION, because the behavioural one is not available: `Http::fake()` never
         * opens a socket, so no test in this file can observe whether `'verify'` was honoured.
         * What a test CAN do is pin that the option is passed and that the SDK's status provider
         * -- whose `callAPI()` sets both TLS checks to 0 -- is not reached for again.
         */
        $source = file_get_contents(base_path('app/Services/PaytmTransactionVerifier.php'));

        /*
         * Asked of the CODE and not of the file. The first version of this assertion searched the
         * raw text and failed on the header's own prose, which names `callAPI()` and the SDK
         * status provider in order to explain why neither is used -- a census that cannot tell an
         * explanation from an instance is the mistake this project keeps making, so the comments
         * come out before the question is asked.
         */
        $code = implode('', array_map(
            fn ($token) => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : (is_array($token) ? $token[1] : $token),
            token_get_all($source)
        ));

        $this->assertStringContainsString("'verify' => true", $code, 'the TLS check must be stated at the call site');
        $this->assertStringNotContainsString('PaytmWallet::', $code, 'the SDK transport disables certificate validation; this class must own its own');
        $this->assertStringNotContainsString('callAPI(', $code);
        $this->assertStringContainsString('merchant-status/getTxnStatus', $code);
    }
}
