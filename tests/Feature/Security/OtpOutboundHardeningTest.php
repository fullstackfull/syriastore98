<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\OtpIntegration;
use App\Services\OtpTransport;
use App\Services\SafeRemoteFetch;
use App\Services\SafeWebhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

/**
 * P1-SEC-55 -- EVERY OUTBOUND OTP SEND IS JUDGED AND PINNED, AND ONLY AN AUTHORISED PRINCIPAL CAN
 * AIM ONE.
 * ==============================================================================================
 *
 * Owner direction of 2026-11-02, Decisions 1 and 2, written after a retrospective review of
 * PR #124 measured that the P1-SEC-45 fix closed one of four sends and closed it with the wrong
 * half of a two-part control. See docs/security/RETROSPECTIVE-REVIEW-PR124.md.
 *
 * THE TWO DEFECTS THIS FILE EXISTS TO KEEP CLOSED:
 *
 *   1. `SafeWebhook::allows()` is VERDICT-ONLY -- `plan() !== null`, a bool. It judged the host
 *      and returned, pinning nothing; the controller then made its own
 *      `Http::asMultipart()->post()` with no `CURLOPT_RESOLVE` and no `allow_redirects => false`.
 *      Guzzle follows up to five redirects by default and A 307 PRESERVES METHOD AND BODY, so a
 *      public host that passed the check could redirect the store's `api_secret` to an internal
 *      address the check itself refuses. Measured in the booted app: the body, secret field
 *      included, was delivered to the second hop.
 *   2. THE GUARDED SEND WAS NOT THE ONLY SEND. Two more, in `CustomerLoginController`, had no
 *      guard at all, on a route with NO AUTH -- and they reflected the remote body and the cURL
 *      error to an anonymous caller, naming internal ports.
 *
 * WHY `Http::fake()` IS NOT USED HERE, and why two older tests had to change with this one.
 * Both controllers now send through `SafeWebhook::send()`, which is cURL, not the `Http` facade.
 * `Http::fake()` therefore intercepts NOTHING, and an `Http::assertNothingSent()` written against
 * the old code would hold whatever the controller did -- a test passing for the wrong reason,
 * which is the exact failure class this repository has spent the week on. The seam used instead
 * is `SafeWebhook::transportUsing()`, which hands the test the PINNED URL and the FULL cURL
 * OPTION ARRAY. That is a stronger assertion than a faked HTTP response: the redirect refusal and
 * the DNS pin are properties of those options, so they can be asserted directly rather than
 * inferred.
 */
class OtpOutboundHardeningTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** @var array<int, array{0: string, 1: array<int, mixed>}> every request the transport was asked to make */
    private array $sent = [];

    /** How many times DNS was consulted, per host. A second consult would be a rebinding window. */
    private array $resolved = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->sent = [];
        $this->resolved = [];

        SafeRemoteFetch::resolveUsing(function (string $host): array {
            $this->resolved[$host] = ($this->resolved[$host] ?? 0) + 1;

            return match ($host) {
                'sms.example.test', 'other-sms.example.test' => ['93.184.216.34'],
                // A name that resolves into RFC1918 space. This is the shape that a numeric
                // literal cannot test, because check() refuses numeric host spellings outright.
                'internal.example.test' => ['10.0.0.5'],
                default => [],
            };
        });

        SafeWebhook::transportUsing(function (string $url, array $options) {
            $this->sent[] = [$url, $options];

            // `['body' => ..., 'status' => ...]` is SafeWebhook's opt-in metadata seam shape.
            // `OtpTransport::succeeded()` needs HTTP 2xx *and* provider 200, so a seam that
            // reports no HTTP status makes a positive case FAIL rather than pass leniently.
            return ['body' => json_encode(['status' => 200, 'message' => 'queued']), 'status' => 200];
        });
    }

    protected function tearDown(): void
    {
        SafeRemoteFetch::resolveUsing(null);
        SafeWebhook::transportUsing(null);

        parent::tearDown();
    }

    /** The stored integration every case starts from. Every field is load-bearing -- see below. */
    private function integration(int $storeId, array $overrides = []): OtpIntegration
    {
        return OtpIntegration::create(array_merge([
            'store_id' => $storeId,
            'base_url' => 'https://sms.example.test',
            'api_secret' => 'the-stores-own-otp-secret',
            'otp_channel' => 'sms',
            // `sms_mode` AND `sms_gateway` ARE LOAD-BEARING, and omitting either makes every
            // negative case below pass for the wrong reason: the handler returns "Invalid SMS
            // mode." or "Gateway ID is missing." BEFORE it reaches any send, so an assertion that
            // nothing was sent would hold with or without the guard. With both set, a request
            // WOULD go out but for the control under test, which is what makes the assertion
            // attributable. This trap was documented by the previous round and is repeated here
            // because it is the reason that round's positive control existed.
            'sms_mode' => 'credits',
            'sms_gateway' => 'fixture-gateway',
            'is_active' => true,
        ], $overrides));
    }

    // ============================================================================================
    //  POSITIVE -- the feature still works, and the secret still reaches a permitted provider
    // ============================================================================================

    /** Replaces the default seam with one that answers a given HTTP status and body. */
    private function respondWith(?int $httpStatus, $body): void
    {
        SafeWebhook::transportUsing(function (string $url, array $options) use ($httpStatus, $body) {
            $this->sent[] = [$url, $options];

            if ($httpStatus === null) {
                // The LEGACY seam shape: a bare body, reporting no HTTP status at all.
                return $body;
            }

            return ['body' => $body, 'status' => $httpStatus];
        });
    }

    /** Drives the merchant test endpoint as the store owner and returns the decoded JSON. */
    private function driveMerchantTest(): array
    {
        [$merchant, $store] = $this->makeTenant();
        $this->integration($store->id);

        $response = $this->actingAs($merchant->fresh())
            ->post('/otp-integration/test', ['phone' => '0100000000']);

        $response->assertOk();

        return (array) $response->json();
    }

    /**
     * Drives the merchant test endpoint for the WHATSAPP channel and returns the decoded JSON.
     *
     * `whatsapp_account` IS LOAD-BEARING, exactly as `sms_mode`/`sms_gateway` are for the SMS
     * branch: without it the handler returns "WhatsApp Account ID is missing." BEFORE reaching any
     * send, so a negative assertion would hold with or without the control under test. The same
     * trap the file documents for SMS, one branch over.
     */
    private function driveMerchantWhatsappTest(): array
    {
        [$merchant, $store] = $this->makeTenant();
        $this->integration($store->id, [
            'otp_channel' => 'whatsapp',
            'whatsapp_enabled' => true,
            'whatsapp_account' => 'fixture-wa-account',
        ]);

        $response = $this->actingAs($merchant->fresh())
            ->post('/otp-integration/test', ['phone' => '0100000000']);

        $response->assertOk();

        return (array) $response->json();
    }

    // ============================================================================================
    //  THE WHATSAPP MERCHANT PATH -- the second of the owner's "both merchant OTP test paths",
    //  which had NO coverage at all until this round
    // ============================================================================================

    /**
     * MEASURED BEFORE WRITING THIS: no test in the repository drove the WhatsApp branch.
     * `grep -rn "otp_channel.*whatsapp" tests/Feature/Security/` found nothing in either OTP test
     * file, so this branch had its own `OtpTransport::post()` call, its own endpoint path, its own
     * field array, its own refusal log and its own copy of the success condition -- all unasserted.
     * The owner's direction to retest *both* merchant paths is what surfaced it.
     */
    public function test_the_whatsapp_branch_sends_pinned_to_its_own_endpoint(): void
    {
        $json = $this->driveMerchantWhatsappTest();

        $this->assertTrue($json['success'] ?? null, 'the WhatsApp test send was refused');
        $this->assertCount(1, $this->sent, 'exactly one request');

        [$url, $options] = $this->sent[0];

        $this->assertSame(
            'https://sms.example.test/api/send/whatsapp',
            $url,
            'the WhatsApp branch must request its OWN endpoint, and the COMPLETE url must be what '
            . 'was judged -- the pre-P1-SEC-55 code judged the base and requested base+path.'
        );

        $this->assertFalse(
            $options[CURLOPT_FOLLOWLOCATION],
            'the WhatsApp branch must refuse redirects too -- a 307 preserves method and body, so '
            . 'this is the limb that stops the secret being forwarded onward.'
        );

        $this->assertSame(
            ['sms.example.test:443:93.184.216.34'],
            $options[CURLOPT_RESOLVE],
            'the connection must be pinned to the address that was judged'
        );

        $this->assertSame(
            'the-stores-own-otp-secret',
            $options[CURLOPT_POSTFIELDS]['secret'] ?? null,
            "premise: the store's own secret must really be in the body, or the negative WhatsApp "
            . 'cases below would prove nothing about protecting it'
        );

        $this->assertSame(
            'fixture-wa-account',
            $options[CURLOPT_POSTFIELDS]['account'] ?? null,
            'the account field is part of the provider wire format and must survive the move off '
            . 'Guzzle multipart'
        );
    }

    /** The regression on the SECOND branch: its success condition is the same helper, so prove it. */
    public function test_the_whatsapp_branch_also_refuses_an_http_error_claiming_status_200(): void
    {
        $this->respondWith(500, json_encode(['status' => 200, 'message' => 'queued']));

        $this->assertFalse(
            $this->driveMerchantWhatsappTest()['success'] ?? null,
            'HTTP 500 with a body claiming status:200 was reported as a successful WhatsApp send. '
            . 'Both branches call OtpTransport::succeeded(), and this is what stops one of them '
            . 'being updated alone.'
        );
    }

    /** And its destination guard: an internal address must send nothing from this branch either. */
    public function test_the_whatsapp_branch_refuses_an_internal_destination(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->integration($store->id, [
            'otp_channel' => 'whatsapp',
            'whatsapp_enabled' => true,
            'whatsapp_account' => 'fixture-wa-account',
            'base_url' => 'https://internal.example.test',
        ]);

        $response = $this->actingAs($merchant->fresh())
            ->post('/otp-integration/test', ['phone' => '0100000000']);

        $response->assertOk();

        $this->assertFalse($response->json('success'));
        $this->assertCount(
            0,
            $this->sent,
            "THE WHATSAPP BRANCH SENT THE STORE'S api_secret TO A NAME THAT RESOLVES INTO RFC1918 "
            . 'SPACE. The SMS branch was guarded first and this one had no test at all.'
        );
    }

    // ============================================================================================
    //  THE HTTP-STATUS REGRESSION the owner caught, and the dual condition restored
    // ============================================================================================

    /**
     * THE REGRESSION ITSELF: HTTP 500 carrying a body that claims `status: 200`.
     *
     * The original condition was `$response->successful() && ($result['status'] ?? null) == 200`.
     * The first move onto `SafeWebhook::send()` kept the provider's field and lost the HTTP status,
     * so THIS case flipped from failure to success -- a merchant would have been told the test OTP
     * was sent when the provider had in fact returned a server error, and the customer-facing flow
     * would have told a shopper to check for a code that never left.
     *
     * A provider that genuinely returns 500 while claiming success in its body is not a hypothesis
     * about a hostile provider; it is what a broken or half-deployed gateway looks like.
     */
    public function test_an_http_error_with_a_body_claiming_status_200_is_not_a_successful_send(): void
    {
        $this->respondWith(500, json_encode(['status' => 200, 'message' => 'queued']));

        $json = $this->driveMerchantTest();

        $this->assertFalse(
            $json['success'] ?? null,
            'HTTP 500 WITH A BODY CLAIMING status:200 WAS REPORTED AS A SUCCESSFUL SEND. That is '
            . 'the exact regression this round exists to correct: the original condition required '
            . 'HTTP 2xx AND provider status 200, and dropping the first half made a server error '
            . 'look like a delivered OTP.'
        );

        $this->assertCount(1, $this->sent,
            'premise: the request must actually have been attempted, or this proves nothing about '
            . 'the response handling.');
    }

    /**
     * @dataProvider httpErrorStatuses
     */
    public function test_no_non_2xx_status_is_a_successful_send(int $status): void
    {
        $this->respondWith($status, json_encode(['status' => 200, 'message' => 'queued']));

        $this->assertFalse(
            $this->driveMerchantTest()['success'] ?? null,
            "HTTP {$status} with provider status 200 was treated as a successful send."
        );
    }

    public static function httpErrorStatuses(): array
    {
        return [
            'bad request'       => [400],
            'unauthorized -- a wrong api_secret looks like this' => [401],
            'forbidden'         => [403],
            'not found -- a wrong base_url path looks like this' => [404],
            'rate limited'      => [429],
            'server error'      => [500],
            'bad gateway'       => [502],
            'gateway timeout'   => [504],
            // 3xx matters specifically: redirects are REFUSED (CURLOPT_FOLLOWLOCATION => false),
            // so a provider answering 302 yields the redirect response itself. It is not 2xx and
            // must not read as a send.
            'redirect, which is never followed' => [302],
        ];
    }

    /**
     * THE POSITIVE HALF, across the 2xx range -- because "successful()" was never "== 200".
     *
     * Laravel's `$response->successful()` is `status >= 200 && status < 300`, so restoring the
     * condition must not narrow it to 200 exactly. A provider answering 201 or 202 with
     * `status: 200` in its body succeeded before P1-SEC-55 and must still succeed.
     *
     * @dataProvider successfulHttpStatuses
     */
    public function test_a_legitimate_2xx_response_is_still_a_successful_send(int $status): void
    {
        $this->respondWith($status, json_encode(['status' => 200, 'message' => 'queued']));

        $this->assertTrue(
            $this->driveMerchantTest()['success'] ?? null,
            "HTTP {$status} with provider status 200 was REFUSED. Laravel's successful() is the "
            . 'whole 2xx range, so restoring the HTTP half must not narrow it to exactly 200 -- '
            . 'that would break every provider that answers 201 or 202.'
        );
    }

    public static function successfulHttpStatuses(): array
    {
        return [
            'ok'       => [200],
            'created'  => [201],
            'accepted' => [202],
            'no content, which some gateways answer' => [204],
            'the top of the range' => [299],
        ];
    }

    /**
     * THE OTHER HALF, unchanged: HTTP 2xx is not enough on its own.
     *
     * The provider's own `status` field is its documented contract, and a gateway that accepts the
     * request and then reports a failure in the body answers HTTP 200. Dropping THIS half would be
     * the mirror-image regression.
     */
    public function test_a_2xx_response_with_a_provider_failure_is_not_a_successful_send(): void
    {
        $this->respondWith(200, json_encode(['status' => 400, 'message' => 'insufficient credits']));

        $json = $this->driveMerchantTest();

        $this->assertFalse($json['success'] ?? null,
            'HTTP 200 with provider status 400 was treated as a successful send.');

        $this->assertSame(
            'insufficient credits',
            $json['message'] ?? null,
            "the provider's own message is still surfaced to the authenticated, permission-checked "
            . 'merchant -- that half of the behaviour is deliberate and must not be lost to the fix'
        );
    }

    /**
     * FAIL CLOSED when the HTTP status is unknown.
     *
     * The real transport always has a status (`CURLINFO_RESPONSE_CODE`), and `0` -- no HTTP
     * response at all -- is reported as null rather than as a code, so a caller cannot read it as
     * a 2xx. The only way a status is genuinely absent is a seam that does not supply one, and
     * that must fail rather than pass: a lenient fallback here would make every positive test in
     * this file pass without exercising the restored condition at all.
     */
    public function test_an_unknown_http_status_is_not_a_successful_send(): void
    {
        $this->respondWith(null, json_encode(['status' => 200, 'message' => 'queued']));

        $this->assertFalse(
            $this->driveMerchantTest()['success'] ?? null,
            'A response with NO HTTP status was treated as successful. succeeded() must fail '
            . 'closed, or a transport that cannot report a status silently bypasses the HTTP half.'
        );
    }

    /**
     * `send()`'s CONTRACT IS UNCHANGED, asserted directly rather than inferred.
     *
     * The owner's instruction was explicit: *"Do not silently change the semantics of other
     * SafeWebhook callers."* The only other caller in the application is
     * `Utility::WebhookCall` (Utility.php:2119), which expects the BODY, `false` on transport
     * failure, and `null` on refusal. `send()` is now a projection of `sendWithStatus()`, so this
     * pins that the projection did not change what it returns.
     */
    public function test_send_still_returns_only_the_body_and_sendWithStatus_adds_the_status(): void
    {
        $this->respondWith(201, 'a body came back');

        $this->assertSame(
            'a body came back',
            SafeWebhook::send('https://sms.example.test/x', [], 'POST'),
            'send() must still answer the BODY alone -- Utility::WebhookCall depends on it.'
        );

        $this->assertSame(
            ['body' => 'a body came back', 'status' => 201],
            SafeWebhook::sendWithStatus('https://sms.example.test/x', [], 'POST'),
            'sendWithStatus() must answer the body AND the status.'
        );

        // Refusal: null from both, and nothing sent.
        $before = count($this->sent);

        $this->assertNull(
            SafeWebhook::send('https://internal.example.test/x', [], 'POST'),
            'send() must still answer null for a REFUSED destination.'
        );
        $this->assertNull(
            SafeWebhook::sendWithStatus('https://internal.example.test/x', [], 'POST'),
            'sendWithStatus() must answer null for a REFUSED destination.'
        );
        $this->assertCount($before, $this->sent, 'a refused destination must send nothing');

        // Transport failure: false body, no status, through both.
        $this->respondWith(null, false);

        $this->assertFalse(
            SafeWebhook::send('https://sms.example.test/x', [], 'POST'),
            'send() must still answer false when the transport failed.'
        );
        $this->assertSame(
            ['body' => false, 'status' => null],
            SafeWebhook::sendWithStatus('https://sms.example.test/x', [], 'POST'),
            'sendWithStatus() must report a transport failure as a false body with no status.'
        );
    }

    /**
     * `succeeded()` DIRECTLY, over the whole decision table -- including the cases the suite
     * cannot reach through the real transport.
     *
     * WHY THIS IS HERE AND NOT INFERRED. `SafeWebhook::sendWithStatus()` maps cURL's
     * `CURLINFO_RESPONSE_CODE` of `0` -- no HTTP response at all -- to `null` rather than to a
     * status. That mapping lives on the real cURL path, which the suite never executes (every
     * test goes through the transport seam), so **it is not covered by any test and saying so is
     * better than implying it is**: I mutated it to pass `0` through unchanged and the whole suite
     * stayed green.
     *
     * What IS asserted here is the property that makes the mapping safe either way: `succeeded()`
     * refuses `0` and refuses `null`, so neither can become a success path. The mapping is
     * defence-in-depth for the LOG and for any future caller comparing ranges, not the thing the
     * success decision rests on.
     *
     * @dataProvider succeededDecisionTable
     */
    public function test_the_success_condition_is_both_halves_and_fails_closed(
        bool $expected,
        string $outcome,
        ?int $providerStatus,
        ?int $httpStatus,
        string $why
    ): void {
        $this->assertSame(
            $expected,
            OtpTransport::succeeded([
                'outcome' => $outcome,
                'status' => $providerStatus,
                'http_status' => $httpStatus,
                'provider_message' => null,
            ]),
            $why
        );
    }

    public static function succeededDecisionTable(): array
    {
        $ok = OtpTransport::ANSWERED;

        return [
            // both halves true
            'HTTP 200 + provider 200' => [true,  $ok, 200, 200, 'the ordinary success'],
            'HTTP 201 + provider 200' => [true,  $ok, 200, 201, '201 is within successful()'],
            'HTTP 299 + provider 200' => [true,  $ok, 200, 299, 'the top of the 2xx range'],

            // the HTTP half false -- the regression the owner caught
            'HTTP 500 + provider 200' => [false, $ok, 200, 500, 'THE REGRESSION: a server error with a body claiming success'],
            'HTTP 404 + provider 200' => [false, $ok, 200, 404, 'a wrong base_url path'],
            'HTTP 302 + provider 200' => [false, $ok, 200, 302, 'a redirect, which is never followed'],
            'HTTP 199 + provider 200' => [false, $ok, 200, 199, 'below the range'],
            'HTTP 300 + provider 200' => [false, $ok, 200, 300, 'the boundary above the range'],

            // the provider half false
            'HTTP 200 + provider 400' => [false, $ok, 400, 200, 'the gateway accepted and then reported a failure'],
            'HTTP 200 + provider null (non-JSON body)' => [false, $ok, null, 200, 'a service banner arrives this way'],

            // fail closed where the status is unknown or there was no response
            'HTTP null + provider 200' => [false, $ok, 200, null, 'no HTTP response is not a 2xx'],
            'HTTP 0 + provider 200'    => [false, $ok, 200, 0,    'cURL reports 0 for no response; it must never read as success'],

            // not an answer at all
            'refused'  => [false, OtpTransport::REFUSED, 200, 200, 'nothing was sent, so nothing succeeded'],
            'failed'   => [false, OtpTransport::FAILED,  200, 200, 'the transport failed'],
        ];
    }

    /**
     * A SEAM RETURNING AN ARRAY BODY IS NOT A STATUS REPORT.
     *
     * The metadata shape is recognised by the presence of a `body` KEY, not by being an array, so
     * the additive seam contract cannot misread an existing seam's return value. This pins the
     * disambiguation rule rather than leaving it as a comment.
     */
    public function test_an_array_without_a_body_key_is_treated_as_a_body_not_as_metadata(): void
    {
        SafeWebhook::transportUsing(fn () => ['status' => 500, 'not_a_body' => 'x']);

        $this->assertSame(
            ['body' => ['status' => 500, 'not_a_body' => 'x'], 'status' => null],
            SafeWebhook::sendWithStatus('https://sms.example.test/x', [], 'POST'),
            'an array WITHOUT a `body` key must be passed through as the body, so a seam that '
            . 'happens to return an array is not reinterpreted as an HTTP status report.'
        );
    }

    public function test_a_permitted_provider_receives_the_send_pinned_and_unredirectable(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->integration($store->id);

        $response = $this->actingAs($merchant->fresh())->post('/otp-integration/test', [
            'phone' => '0100000000',
        ]);

        $body = json_decode((string) $response->getContent(), true) ?: [];
        $this->assertTrue(
            (bool) ($body['success'] ?? false),
            'A PERMITTED PROVIDER WAS REFUSED. The hardening has broken legitimate OTP sending, '
            . 'which the owner direction explicitly requires be preserved. Response: '
            . substr((string) $response->getContent(), 0, 300)
        );

        $this->assertCount(1, $this->sent, 'exactly one outbound request');
        [$url, $options] = $this->sent[0];

        // THE JUDGED URL IS THE REQUESTED URL, path included. The previous code judged $baseUrl
        // and then requested $baseUrl . '/api/send/sms', so what was judged was not what was
        // requested. pinnedUrl() keeps the path, so the two are now the same string.
        $this->assertSame('https://sms.example.test/api/send/sms', $url);

        // The provider's wire format is unchanged: the same multipart field names and values.
        $fields = $options[CURLOPT_POSTFIELDS];
        $this->assertIsArray($fields, 'cURL builds multipart/form-data from an array');
        $this->assertSame('the-stores-own-otp-secret', $fields['secret'] ?? null);
        $this->assertSame('credits', $fields['mode'] ?? null);
        $this->assertArrayHasKey('message', $fields);
    }

    // ============================================================================================
    //  REDIRECT -- the 307 hole that `allows()` could not close
    // ============================================================================================

    public function test_the_request_cannot_be_redirected_at_all(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->integration($store->id);

        $this->actingAs($merchant->fresh())->post('/otp-integration/test', ['phone' => '0100000000']);

        $this->assertCount(1, $this->sent);
        [, $options] = $this->sent[0];

        $this->assertArrayHasKey(CURLOPT_FOLLOWLOCATION, $options);
        $this->assertFalse(
            $options[CURLOPT_FOLLOWLOCATION],
            'REDIRECTS ARE BEING FOLLOWED. This is the measured P1-SEC-45 residual: a public first '
            . 'hop that passes the address check can answer 307, which preserves method and body, '
            . 'and the store api_secret is delivered to wherever it points -- including an '
            . 'internal address the check itself refuses.'
        );

        // Belt and braces: even were a redirect followed, only http(s) could be spoken.
        $this->assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $options[CURLOPT_REDIR_PROTOCOLS]);
        $this->assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $options[CURLOPT_PROTOCOLS]);
    }

    // ============================================================================================
    //  DNS REBINDING -- judged once, pinned, never resolved again
    // ============================================================================================

    public function test_the_connection_is_pinned_to_the_address_that_was_judged(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->integration($store->id);

        $this->actingAs($merchant->fresh())->post('/otp-integration/test', ['phone' => '0100000000']);

        $this->assertCount(1, $this->sent);
        [, $options] = $this->sent[0];

        $this->assertSame(
            ['sms.example.test:443:93.184.216.34'],
            $options[CURLOPT_RESOLVE],
            'THE CONNECTION IS NOT PINNED. Without CURLOPT_RESOLVE the name is resolved a SECOND '
            . 'time by the HTTP client, so a record that answered a public address when it was '
            . 'judged can answer a private one when it is dialled. That is DNS rebinding, and it '
            . 'is the second route to the same leak as the redirect above.'
        );

        // The name was consulted ONCE. A second consult is the rebinding window; the pin is what
        // removes it, and this asserts there is no unpinned second lookup hiding anywhere.
        $this->assertSame(
            1,
            $this->resolved['sms.example.test'] ?? 0,
            'the host was resolved more than once, so there is a second, unpinned resolution'
        );
    }

    // ============================================================================================
    //  NEGATIVE -- an internal destination is refused BEFORE anything is sent
    // ============================================================================================

    /** @dataProvider refusedDestinations */
    public function test_an_internal_destination_is_refused_and_nothing_is_sent(string $label, string $baseUrl): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->integration($store->id);

        $response = $this->actingAs($merchant->fresh())->post('/otp-integration/test', [
            'phone' => '0100000000',
            'base_url' => $baseUrl,
        ]);

        $this->assertSame(
            [],
            $this->sent,
            "{$label} WAS SENT TO. The store's api_secret is in the body of that request. "
            . 'This is P1-SEC-45/P1-SEC-55.'
        );

        $body = json_decode((string) $response->getContent(), true) ?: [];
        $this->assertFalse((bool) ($body['success'] ?? false), $label . ' was reported as a success');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function refusedDestinations(): array
    {
        return [
            'the cloud metadata address' => ['the cloud metadata address', 'http://169.254.169.254'],
            'loopback' => ['loopback', 'http://127.0.0.1:6379'],
            'a name resolving into RFC1918' => ['a name resolving into RFC1918', 'https://internal.example.test'],
            'a name with no records at all' => ['a name with no records at all', 'https://nowhere.example.test'],
            'a non-http scheme' => ['a non-http scheme', 'gopher://127.0.0.1:6379/_FLUSHALL'],
            'credentials in the URL' => ['credentials in the URL', 'https://user:pass@sms.example.test'],
        ];
    }

    // ============================================================================================
    //  AUTHORISATION -- owner policy, Decision 2
    // ============================================================================================

    public function test_a_staff_user_without_the_permission_cannot_cause_a_send(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->integration($store->id);

        $staff = $this->makeStaff($merchant, ['current_store' => $store->id]);

        $response = $this->actingAs($staff->fresh())->post('/otp-integration/test', [
            'phone' => '0100000000',
            'base_url' => 'https://other-sms.example.test',
        ]);

        $this->assertSame(
            [],
            $this->sent,
            'A STAFF USER HOLDING NO PERMISSION CAUSED THE STORED api_secret TO BE TRANSMITTED. '
            . 'The settings view renders that field as type="password" with no value precisely so '
            . 'it cannot be read back; this endpoint hands it to a host of the caller\'s choosing.'
        );

        $body = json_decode((string) $response->getContent(), true) ?: [];
        $this->assertFalse((bool) ($body['success'] ?? false));
    }

    public function test_a_staff_user_without_the_permission_cannot_change_the_destination(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $integration = $this->integration($store->id);

        $staff = $this->makeStaff($merchant, ['current_store' => $store->id]);

        $this->actingAs($staff->fresh())->post('/otp-integration', [
            'base_url' => 'https://attacker.example.test',
            'otp_channel' => 'sms',
            'sms_mode' => 'credits',
        ]);

        $this->assertSame(
            'https://sms.example.test',
            $integration->fresh()->base_url,
            'A STAFF USER HOLDING NO PERMISSION REWROTE THE OTP DESTINATION. Every subsequent '
            . 'customer password-reset would then post the store secret there.'
        );
    }

    /**
     * THE NULL-PRINCIPAL LIMB, ASSERTED ON THE GATE ITSELF -- and the first version of this test
     * did not do that, which my own mutation run caught.
     *
     * The reviewer measured that `test_an_unauthenticated_caller_cannot_reach_either_endpoint`
     * passes with the gate entirely removed, because `auth` on `routes/web.php:343` stops the
     * request first. So I wrote a behavioural test driving `save1` through a route without `auth`
     * -- AND IT HAD THE SAME DEFECT ONE LAYER DOWN. With the gate stubbed to `return true` it
     * still passed, because `resolvedStoreId()` then refuses: `getCurrentStore()` returns null
     * for a null user, so the write never happens and the assertion holds for the wrong reason.
     *
     * Two assertions now, each isolating one limb:
     *
     *   1. THE GATE'S OWN DECISION, read by reflection with nobody authenticated. This is the
     *      only way to assert `mayManageOtpIntegration()` in isolation, because every route to it
     *      is defended twice over.
     *   2. The behavioural outcome, kept for what it proves -- that no unauthenticated write
     *      reaches the row by ANY limb -- with its docblock no longer claiming it proves the gate.
     */
    public function test_the_gate_itself_refuses_a_null_principal(): void
    {
        [, $store] = $this->makeTenant();
        $integration = $this->integration($store->id);

        $this->assertNull(auth()->user(), 'premise: nobody is authenticated');

        $gate = new \ReflectionMethod(
            \App\Http\Controllers\OtpIntegrationController::class,
            'mayManageOtpIntegration'
        );
        $gate->setAccessible(true);

        $this->assertFalse(
            $gate->invoke(new \App\Http\Controllers\OtpIntegrationController()),
            'THE GATE ADMITS A NULL PRINCIPAL. StoreSettingsAccess::permits(null) is false, so '
            . 'this can only break by the gate being replaced with something that assumes a user.'
        );

        \Illuminate\Support\Facades\Route::middleware('web')->post(
            '/__test__/otp-integration/no-auth',
            [\App\Http\Controllers\OtpIntegrationController::class, 'save1']
        );

        $this->post('/__test__/otp-integration/no-auth', [
            'base_url' => 'https://attacker.example.test',
            'otp_channel' => 'sms',
            'sms_mode' => 'credits',
        ]);

        $this->assertSame(
            'https://sms.example.test',
            $integration->fresh()->base_url,
            'AN UNAUTHENTICATED CALLER REWROTE THE OTP DESTINATION through a route without the '
            . '`auth` middleware. Note this holds even with the gate stubbed open, because the '
            . 'store guard also refuses -- which is why the reflection assertion above exists.'
        );
    }

    /**
     * F4: THE REFUSAL LOG MUST NOT CARRY A CREDENTIAL.
     *
     * `$baseUrl` is caller-supplied, and one of the shapes `SafeRemoteFetch::check` REFUSES is
     * userinfo in the URL. So the exact input that reaches the refusal log is the one that would
     * write `https://user:pass@host` into `storage/logs/` -- and incident #120 is still open.
     *
     * THIS TEST EXISTS BECAUSE THE FIX HAD NONE. Reverting `loggableHost()` to `$baseUrl` left
     * all 25 other tests green, which is the definition of an unasserted fix.
     */
    public function test_the_refusal_log_does_not_carry_credentials_from_the_url(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->integration($store->id);

        $records = [];

        \Illuminate\Support\Facades\Log::listen(function ($message) use (&$records) {
            $records[] = $message;
        });

        $this->actingAs($merchant->fresh())->post('/otp-integration/test', [
            'phone' => '0100000000',
            'base_url' => 'https://leaked-user:leaked-pass@127.0.0.1',
        ]);

        $this->assertNotEmpty($records, 'premise: the refusal must have logged something');

        $dump = json_encode(array_map(
            fn ($r) => [$r->message ?? '', $r->context ?? []],
            $records
        ));

        foreach (['leaked-pass', 'leaked-user'] as $secret) {
            $this->assertStringNotContainsString(
                $secret,
                (string) $dump,
                "THE REFUSAL LOG CONTAINS `{$secret}` FROM THE SUBMITTED URL. The refused shape "
                . 'is credentials-in-URL, so logging the address whole writes the credential to '
                . 'disk on exactly the input that triggers the refusal.'
            );
        }

        $this->assertStringContainsString(
            '127.0.0.1',
            (string) $dump,
            'premise: the host must still be logged, or the redaction has removed the diagnostic '
            . 'as well as the credential and this test would pass vacuously.'
        );
    }

    /**
     * THE OPERATOR MUST NOT INHERIT STORE 1 (F2).
     *
     * `app/Helper/helper.php:774-775` returns the LITERAL `1` for `users.type == 'super admin'`
     * with an empty `current_store`. So before `resolvedStoreId()`, an operator in that state
     * would read and overwrite whichever tenant holds store id 1 -- and `test()` would transmit
     * THAT tenant's `api_secret` to a caller-named host.
     *
     * Not an escalation (the operator is entitled to everything) but wrong-tenant exposure and an
     * unintended credential write, which is why `CouponController:171-177`,
     * `StoreFinanceController:332-335`, `TaxMethodController:107-108` and
     * `FlashSaleController:66-69` all refuse the same default.
     *
     * The fixture gives store id 1 a DIFFERENT tenant's row on purpose: if the guard regresses,
     * this test shows a cross-tenant write rather than a harmless one.
     */
    public function test_an_operator_with_no_current_store_does_not_fall_through_to_store_one(): void
    {
        $victim = OtpIntegration::withoutGlobalScopes()->updateOrCreate(
            ['store_id' => 1],
            [
                'base_url' => 'https://victim.example.test',
                'api_secret' => 'victim-secret-must-not-leave',
                'otp_channel' => 'sms',
                'sms_mode' => 'credits',
                'is_active' => 1,
            ]
        );

        $operator = $this->makeMerchant(['type' => 'super admin', 'current_store' => null]);

        $this->assertSame(
            1,
            (int) getCurrentStore($operator->id),
            'premise: getCurrentStore() must still answer the hardcoded 1 for this principal, or '
            . 'this test proves nothing about the guard.'
        );

        $this->actingAs($operator->fresh())->post('/otp-integration/test', [
            'phone' => '0100000000',
            'base_url' => 'https://attacker.example.test',
        ]);

        $this->assertCount(
            0,
            $this->sent,
            'AN OPERATOR WITH NO current_store CAUSED STORE 1\'s api_secret TO BE TRANSMITTED.'
        );

        $this->actingAs($operator->fresh())->post('/otp-integration', [
            'base_url' => 'https://attacker.example.test',
            'otp_channel' => 'sms',
            'sms_mode' => 'credits',
        ]);

        $this->assertSame(
            'https://victim.example.test',
            OtpIntegration::withoutGlobalScopes()->find($victim->id)->base_url,
            'the same principal overwrote store 1\'s OTP destination'
        );
    }

    /**
     * EVERY WRITER OF THE OTP ROW IS GATED, not just every routed one.
     *
     * Decision 2 says "Apply authorization to the relevant controller ACTIONS and routes." The
     * first pass gated the two ROUTED actions and stopped there. Finishing the census found a
     * third: `OtpIntegrationController::save1()`, a near-duplicate of `save()` that writes
     * `base_url` and `api_secret` to the same row and is registered by no route at all.
     *
     * This test drives it THROUGH A ROUTE IT REGISTERS ITSELF, because the property under test is
     * a property of the method, not of `routes/web.php`. `save` and `save1` differ by one
     * character; a later registration is the easiest mistake available here, and CLAUDE.md records
     * that a later `Route::` declaration for the same method+URI silently replaces the earlier one
     * and drops its middleware. If someone wires `save1` up, this test is what keeps the gate.
     */
    public function test_every_writer_of_the_integration_is_gated_including_the_unrouted_one(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $integration = $this->integration($store->id);

        \Illuminate\Support\Facades\Route::middleware('web')->post(
            '/__test__/otp-integration/save1',
            [\App\Http\Controllers\OtpIntegrationController::class, 'save1']
        );

        $staff = $this->makeStaff($merchant, ['current_store' => $store->id]);

        $this->actingAs($staff->fresh())->post('/__test__/otp-integration/save1', [
            'base_url' => 'https://attacker.example.test',
            'otp_channel' => 'sms',
            'sms_mode' => 'credits',
        ]);

        $this->assertSame(
            'https://sms.example.test',
            $integration->fresh()->base_url,
            'save1() REWROTE THE OTP DESTINATION for a staff user holding no permission. It is '
            . 'unrouted today, so this is not a live defect -- but the gate belongs to the action, '
            . 'and one route registration is all that separates the two.'
        );
    }

    /**
     * THE CENSUS ITSELF, so a FOURTH writer cannot be added without this failing.
     *
     * The question is not "are the three I know about gated" -- that is the test above. It is
     * "are there exactly three, and does each one carry the gate". A new `public function` on
     * this controller that touches the row, or a write to `OtpIntegration` from anywhere else in
     * `app/`, fails here.
     *
     * Measured when written: `OtpIntegrationController` has `index` (renders only),
     * `save`, `save1`, `test`, plus two private helpers; `SettingController`'s `otp_setting`
     * branch READS the row to render the settings tab and writes nothing; and no other file in
     * `app/` or `packages/` constructs or updates an `OtpIntegration`.
     */
    public function test_no_fourth_writer_of_the_otp_integration_exists(): void
    {
        $controller = base_path('app/Http/Controllers/OtpIntegrationController.php');
        $source = (string) file_get_contents($controller);

        // Every public action on the controller, in declaration order.
        preg_match_all('/public function (\w+)\s*\(/', $source, $m);
        $actions = $m[1];

        $this->assertSame(
            ['index', 'save', 'save1', 'test'],
            $actions,
            'The public surface of OtpIntegrationController changed. If an action was ADDED, '
            . 'decide whether it writes or transmits the integration and gate it with '
            . 'mayManageOtpIntegration() before updating this list.'
        );

        /*
         * Each action that touches the row must call the gate. `index` is excluded by name and
         * with a reason: it only renders, and it is measured broken anyway (see below).
         */
        foreach (['save', 'save1', 'test'] as $action) {
            /*
             * COMMENTS ARE STRIPPED FIRST, and that is the point rather than tidiness. The
             * reviewer noted that `assertStringContainsString('mayManageOtpIntegration()')` is
             * satisfied by the identifier appearing in a COMMENT -- and that this file's docblocks
             * discuss the gate at length, so the assertion sat one paste away from vacuous.
             * `php_strip_whitespace` removes comments using the real tokenizer, so what is matched
             * is code.
             */
            $body = $this->methodBody($this->codeOnly($source), $action);

            $this->assertStringContainsString(
                'mayManageOtpIntegration()',
                $body,
                "OtpIntegrationController::{$action}() does not call the authorization gate. It "
                . 'writes or transmits the store OTP credential, so an unauthorised staff member '
                . 'can change the destination or cause a send.'
            );
        }

        /*
         * And no other first-party file may write the row. A READ is fine -- SettingController
         * renders the settings tab from it -- so the pattern is write verbs only.
         */
        $writers = [];

        foreach (['app', 'packages'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $relative = ltrim(str_replace(base_path(), '', $file->getPathname()), '/');

                if ($relative === 'app/Http/Controllers/OtpIntegrationController.php') {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());

                /*
                 * WIDER THAN THE STATIC CONSTRUCTORS. The reviewer pointed out that matching only
                 * `OtpIntegration::create|updateOrCreate|...` would let a future
                 * `DB::table('otp_integrations')->update()` or
                 * `OtpIntegration::where(...)->update()` past this gate. Neither exists today;
                 * both are matched now so neither can be added quietly.
                 */
                $writePatterns = [
                    '/OtpIntegration::(create|updateOrCreate|firstOrNew|firstOrCreate|insert|upsert)\s*\(/',
                    '/OtpIntegration::where\s*\([^;]*->\s*(update|delete|upsert|increment|decrement)\s*\(/s',
                    '/DB::table\(\s*[\'"]otp_integrations[\'"]\s*\)[^;]*->\s*(insert|update|updateOrInsert|upsert|delete)\s*\(/s',
                ];

                foreach ($writePatterns as $pattern) {
                    if (preg_match($pattern, $contents)) {
                        $writers[] = $relative;
                        break;
                    }
                }
            }
        }

        $this->assertSame([], $writers,
            'A file other than OtpIntegrationController writes the OTP integration row. It is '
            . 'outside the gate added for owner Decision 2: ' . implode(', ', $writers));
    }

    /**
     * NOT A SECURITY ASSERTION -- a correctness one, pinned because it was measured and would
     * otherwise be a sentence in a document that nothing checks.
     *
     * `OtpIntegrationController::index()` returns `view('otp.integration')`, and that view does
     * not exist: `view()->exists('otp.integration')` is FALSE and
     * `find . -name integration.blade.php` finds only a LynomiaChat superadmin view. So the
     * registered `GET /otp-integration` route (routes/web.php:349) throws on arrival. The live
     * settings UI is `SettingController`'s `otp_setting` tab rendering
     * `resources/views/setting/otp_setting.blade.php`, which is also where `save()` redirects back
     * to (`'setting_tab' => 'otp_setting'`).
     *
     * It is asserted in the FALSE direction on purpose. Changing it to EXISTS is the fix -- either
     * point `index()` at the real tab or drop the route and the method -- and whoever does that
     * will be told by this test to come and delete it, which is how a known-broken route stops
     * being folklore.
     */
    public function test_the_index_route_is_still_the_known_broken_one(): void
    {
        $this->assertFalse(
            view()->exists('otp.integration'),
            'view("otp.integration") now EXISTS. OtpIntegrationController::index() was measured '
            . 'broken on 2026-11-02 (the registered GET /otp-integration route threw), and this '
            . 'test pins that so the finding cannot rot. If the view was added or index() was '
            . 'repointed, that finding is CLOSED -- delete this test and the note in '
            . 'docs/security/P1-SEC-55-OUTBOUND-SENDER-CENSUS.md.'
        );
    }

    /** The same source with every comment and docblock removed, via PHP's own tokenizer. */
    private function codeOnly(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $out .= $token[1];

                continue;
            }

            $out .= $token;
        }

        return $out;
    }

    /** The source of one method, from its signature to the start of the next `function`. */
    private function methodBody(string $source, string $name): string
    {
        $start = strpos($source, "function {$name}(");

        $this->assertNotFalse($start, "method {$name}() not found");

        $next = preg_match(
            '/\n\s*(?:public|private|protected)\s+function\s/',
            $source,
            $m,
            PREG_OFFSET_CAPTURE,
            $start + 1
        ) === 1 ? $m[0][1] : strlen($source);

        return substr($source, $start, $next - $start);
    }

    public function test_the_store_administrator_may_still_manage_the_integration(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->integration($store->id);

        $this->actingAs($merchant->fresh())->post('/otp-integration/test', ['phone' => '0100000000']);

        $this->assertCount(
            1,
            $this->sent,
            'THE STORE ADMINISTRATOR WAS LOCKED OUT. users.type === admin is the root of its own '
            . 'tenant and the owner policy admits store administrators by name.'
        );
    }

    public function test_a_staff_user_granted_the_permission_may_manage_the_integration(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->integration($store->id);

        $staff = $this->makeStaff($merchant, ['current_store' => $store->id]);
        $this->grantPermission($staff, 'Manage Setting');

        $this->actingAs($staff->fresh())->post('/otp-integration/test', ['phone' => '0100000000']);

        $this->assertCount(
            1,
            $this->sent,
            'A STAFF MEMBER THE OWNER EXPLICITLY GRANTED THE PERMISSION WAS STILL REFUSED. The '
            . 'policy is "staff with an explicitly granted permission MAY", so this is the half of '
            . 'the gate that must not over-refuse.'
        );
    }

    /**
     * AND THE OTHER SCREEN'S PERMISSION IS NOT ENOUGH. This is the finding the reviewer caught in
     * the first draft of the gate, pinned so it cannot come back.
     *
     * `Manage Store Setting` is the "Store Setting" entry's permission (route `app-setting.index`,
     * CompanyMenuListener:88). The OTP tab lives on the Settings screen (`setting.index`,
     * CompanyMenuListener:964), whose permission is `Manage Setting`. `StoreSettingsAccess` says
     * so in its own docblock and refuses `Manage Store Setting` alone for any restricted name --
     * and `api_secret` IS a restricted name under `GatewaySecretRedactor`'s `/secret|.../i`.
     *
     * So a role holding only the Store-Setting permission must not be able to read back the OTP
     * secret by pointing the test endpoint at a host it controls.
     */
    public function test_the_other_screens_permission_is_not_enough(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $integration = $this->integration($store->id);

        $staff = $this->makeStaff($merchant, ['current_store' => $store->id]);
        $this->grantPermission($staff, 'Manage Store Setting');

        $this->actingAs($staff->fresh())->post('/otp-integration/test', [
            'phone' => '0100000000',
            'base_url' => 'https://attacker.example.test',
        ]);

        $this->assertCount(
            0,
            $this->sent,
            'A STAFF MEMBER HOLDING ONLY `Manage Store Setting` CAUSED THE STORED api_secret TO '
            . 'BE SENT. That is the Store Setting screen\'s permission, not this screen\'s, and '
            . 'StoreSettingsAccess refuses it for restricted names precisely so a role holding it '
            . 'cannot be handed another screen\'s credential.'
        );

        $this->actingAs($staff->fresh())->post('/otp-integration', [
            'base_url' => 'https://attacker.example.test',
            'otp_channel' => 'sms',
            'sms_mode' => 'credits',
        ]);

        $this->assertSame(
            'https://sms.example.test',
            $integration->fresh()->base_url,
            'the same principal rewrote the OTP destination'
        );
    }

    /**
     * THE OPERATOR-FIRST CLAUSE, which exists because this codebase has NO super-admin bypass:
     * `permissions_as_gates => false`, so `isAbleTo()` ALONE REFUSES the platform operator.
     * Without the `UserPolicy::isOperator()` clause this case fails, and that is the whole reason
     * CLAUDE.md §1 states the ordering rule.
     */
    public function test_the_platform_operator_is_not_refused_by_laratrust(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->integration($store->id);

        $operator = $this->makeMerchant(['type' => 'super admin', 'current_store' => $store->id]);

        $this->assertFalse(
            $operator->isAbleTo('Manage Setting'),
            'premise: isAbleTo() must refuse the operator, or this test proves nothing about the '
            . 'operator-first clause. Named for the permission the gate ACTUALLY asks for -- an '
            . 'earlier draft asserted this about `Manage Store Setting`, which the gate no longer '
            . 'consults, so the premise would have been about nothing.'
        );

        $this->actingAs($operator->fresh())->post('/otp-integration/test', ['phone' => '0100000000']);

        $this->assertCount(1, $this->sent, 'the platform operator was refused by the permission gate');
    }

    public function test_an_unauthenticated_caller_cannot_reach_either_endpoint(): void
    {
        [, $store] = $this->makeTenant();
        $this->integration($store->id);

        foreach (['/otp-integration/test', '/otp-integration'] as $path) {
            $this->post($path, ['phone' => '0100000000', 'base_url' => 'https://other-sms.example.test']);
        }

        $this->assertSame([], $this->sent, 'an unauthenticated caller caused an outbound send');
    }

    // ============================================================================================
    //  THE ANONYMOUS ORACLE -- nothing from the remote side reaches an unauthenticated caller
    // ============================================================================================

    /**
     * `POST {storeSlug?}/forgot-password/otp/send` carries NO AUTH -- measured middleware is
     * `web, ActiveTheme, ThemeLanguage, ThrottleRequests:auth`, and `throttle:auth` keys on
     * ip + sha256(`email`) while this route posts `mobile`, so it is a per-IP cap and not a
     * control. It used to flash the remote body and the cURL error to that caller, which made it
     * a port and service oracle over the platform's own network.
     */
    public function test_the_anonymous_customer_flow_leaks_nothing_from_the_remote_side(): void
    {
        [, $store] = $this->makeTenant();

        // The destination a staff member could previously have stored. It resolves into RFC1918,
        // so the transport refuses it -- and the question this test asks is what the ANONYMOUS
        // caller is told about that.
        $this->integration($store->id, ['base_url' => 'https://internal.example.test']);

        $customer = Customer::create([
            'name' => 'Fixture Customer',
            'email' => 'fixture-customer@example.test',
            'password' => Hash::make('secret-password'),
            'mobile' => '0936807754',
            'store_id' => $store->id,
            'is_active' => 1,
        ]);

        $response = $this->post('/' . $store->slug . '/forgot-password/otp/send', [
            'mobile' => $customer->mobile,
        ]);

        $this->assertSame([], $this->sent, 'the private destination was dialled');

        $flashed = (string) (session('error') ?? '');

        foreach (['cURL', 'curl', '6379', '10.0.0.5', 'Connection refused', 'internal.example.test'] as $leak) {
            $this->assertStringNotContainsString(
                $leak,
                $flashed,
                'THE ANONYMOUS CALLER WAS TOLD "' . $leak . '". That is the oracle: an '
                . 'unauthenticated visitor learns about the platform\'s internal network from a '
                . 'password-reset form. Flashed: ' . $flashed
            );
        }

        $this->assertNotSame(404, $response->getStatusCode(), 'the route must exist for this to mean anything');
    }

    // ============================================================================================
    //  CENSUS -- no other sender bypasses the pinned transport
    // ============================================================================================

    /**
     * Owner direction, Decision 1: "Verify that no other outbound sender bypasses the same
     * protection." A census rather than a reading, so the answer survives the next edit.
     *
     * The rule: any first-party file that handles the OTP `api_secret` AND performs an outbound
     * request must do it through `OtpTransport` or `SafeWebhook`. A new sender added with the
     * `Http` facade or raw cURL fails this.
     */
    public function test_no_first_party_sender_of_the_otp_secret_bypasses_the_pinned_transport(): void
    {
        $root = base_path();
        $offenders = [];
        $checked = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/app', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (! str_contains($source, 'api_secret')) {
                continue;
            }

            $checked++;

            $sends = preg_match('/Http::\s*(asMultipart|withHeaders|timeout|post|get)\b/', $source) === 1
                || preg_match('/\bcurl_(init|exec)\s*\(/', $source) === 1;

            if (! $sends) {
                continue;
            }

            if (! str_contains($source, 'OtpTransport') && ! str_contains($source, 'SafeWebhook')) {
                $offenders[] = str_replace($root . '/', '', $file->getPathname());
            }
        }

        $this->assertGreaterThan(
            0,
            $checked,
            'the census matched no file at all, so it is vacuous -- api_secret should appear in '
            . 'at least the OTP controllers and the model'
        );

        $this->assertSame(
            [],
            $offenders,
            "AN OUTBOUND SENDER HANDLES THE OTP SECRET WITHOUT THE PINNED TRANSPORT:\n  "
            . implode("\n  ", $offenders)
            . "\nRoute it through App\\Services\\OtpTransport. P1-SEC-55."
        );
    }

    /**
     * NON-VACUITY FOR THE CENSUS ABOVE. If the detection regex stops matching, the census passes
     * while the rule it enforces is gone -- the precise failure mode this repository has been
     * closing all week. This asserts the detector fires on the shape it is looking for.
     */
    public function test_the_census_detector_actually_matches_an_unguarded_send(): void
    {
        $unguarded = '<?php $x = $integration->api_secret; Http::asMultipart()->post($u, []);';

        $this->assertSame(
            1,
            preg_match('/Http::\s*(asMultipart|withHeaders|timeout|post|get)\b/', $unguarded),
            'the census detector no longer recognises an Http facade send, so the census above is '
            . 'vacuous'
        );

        $this->assertSame(
            1,
            preg_match('/\bcurl_(init|exec)\s*\(/', '<?php curl_init($u);'),
            'the census detector no longer recognises raw cURL'
        );
    }

    /** The transport itself: a refusal must be distinguishable from a failure, and carry no detail. */
    public function test_the_transport_reports_a_refusal_distinctly_and_without_detail(): void
    {
        $refused = OtpTransport::post('https://internal.example.test', '/api/send/sms', [
            ['name' => 'secret', 'contents' => 'x'],
        ]);

        $this->assertSame(OtpTransport::REFUSED, $refused['outcome']);
        $this->assertNull($refused['provider_message'], 'a refusal must carry no message to repeat');
        $this->assertSame([], $this->sent, 'a refusal must send nothing');

        SafeWebhook::transportUsing(fn () => false);

        $failed = OtpTransport::post('https://sms.example.test', '/api/send/sms', [
            ['name' => 'secret', 'contents' => 'x'],
        ]);

        $this->assertSame(OtpTransport::FAILED, $failed['outcome']);
        $this->assertNull(
            $failed['provider_message'],
            'A TRANSPORT FAILURE CARRIES A MESSAGE. That is how "cURL error 7: ... port 6379 ... '
            . 'Connection refused" reached an anonymous caller.'
        );

        // A remote service banner is not JSON, and must not become a message either.
        SafeWebhook::transportUsing(fn () => 'INTERNAL-SERVICE-BANNER: redis 7.0.11 on 127.0.0.1');

        $banner = OtpTransport::post('https://sms.example.test', '/api/send/sms', [
            ['name' => 'secret', 'contents' => 'x'],
        ]);

        $this->assertNull(
            $banner['provider_message'],
            'a non-JSON body became a repeatable message -- a service banner arrives exactly so'
        );
    }
}
