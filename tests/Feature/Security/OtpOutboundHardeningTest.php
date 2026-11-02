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

            return json_encode(['status' => 200, 'message' => 'queued']);
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
        $this->grantPermission($staff, 'Manage Store Setting');

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
            $operator->isAbleTo('Manage Store Setting'),
            'premise: isAbleTo() must refuse the operator, or this test proves nothing about the '
            . 'operator-first clause'
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
