<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * P1-SEC-13 -- THE STOREFRONT VISIT LOG WROTE EVERY REQUEST'S BODY AND HEADERS TO THE DATABASE.
 * =============================================================================================
 *
 * `ActiveTheme::handle` calls `visitor()->visit($store)` on every request to an active, themed
 * store. `Shetabit\Visitor\Visitor::prepareLog()` persists `$request->all()` and every request
 * header, and its only filter -- `config/visitor.php` `except => ['login','register']`, matched
 * with `Request::is()` -- matches the BARE path `login`, never `{storeSlug}/login`.
 *
 * MEASURED while chasing an unexplained 500 in a P1-ORD-07 test: `POST /{slug}/login` left a
 * `shetabit_visits` row whose `request` column held the plaintext password and whose `headers`
 * column held the Cookie header. Gateway handlers behind `{storeSlug?}/get-payment-NMI` and
 * `{storeSlug?}/authorizenet-status` read `nmi_card_number` / `nmi_cvv_code` / `cardNumber` /
 * `cvv` from the same group, so the card number and CVV were persisted too.
 *
 * ORACLE: every column of every visit row, serialised. The leak IS what is stored, so the
 * assertion is "this sentinel appears nowhere in the table", with a positive control that the
 * analytics columns are still written (otherwise "nothing stored" would pass by disabling logging).
 *
 * ONE REQUEST PER TEST where the visitor singleton matters: `shetabit-visitor` is bound once with
 * the Request that existed when it was first resolved, so a second request in the same test is
 * logged against the FIRST request's data. `visit()` below forgets the instance between calls.
 */
class StorefrontVisitLogTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'PW-FIXTURE-SENTINEL-4471';
    private const COOKIE = 'COOKIE-SENTINEL-9902';
    private const CARD = '4111111111119876';
    private const CVV = '737';
    private const TOKEN = 'RESETTOKEN-SENTINEL-5538';

    private function themedStore(): Store
    {
        [, $store] = $this->makeTenant();

        // Premise: the logging only runs for an ACTIVE store WITH a theme.
        $this->assertNotEmpty($store->theme_id, 'Fixture premise: the store must have a theme or ActiveTheme never logs a visit.');
        $this->assertSame(1, (int) $store->is_active);

        return $store;
    }

    private function allVisitText(): string
    {
        return serialize(DB::table('shetabit_visits')->get()->map(fn ($row) => (array) $row)->all());
    }

    private function visit(string $method, string $uri, array $data = [], array $headers = [])
    {
        $this->app->forgetInstance('shetabit-visitor');

        // call(), not get()/post(): those apply withHeaders(), call() does not -- the first version of this
        // helper sent no headers at all, and the credential-header test below passed vacuously.
        return $this->call($method, $uri, $data, [], [], $this->transformHeadersToServerVars($headers));
    }

    private function assertNothingSecretStored(array $secrets, string $context): void
    {
        $stored = $this->allVisitText();

        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $stored,
                "{$context}: '{$secret}' was written to shetabit_visits. The visit log persists the request body "
                . 'and headers of every themed storefront request.');
        }
    }

    // =========================================================================================
    //  PREMISE AND POSITIVE CONTROL
    // =========================================================================================

    public function test_premise_a_themed_storefront_request_writes_a_visit_row(): void
    {
        $store = $this->themedStore();

        $this->visit('GET', '/' . $store->slug . '/login', [], ['User-Agent' => 'ProbeAgent/1.0']);

        $row = DB::table('shetabit_visits')->first();
        $this->assertNotNull($row, 'No visit row: every other assertion in this file would be vacuous.');
        $this->assertStringContainsString('/' . $store->slug . '/login', (string) $row->url);
        $this->assertSame('GET', $row->method);
        $this->assertSame('ProbeAgent/1.0', $row->useragent);
        $this->assertNotEmpty($row->ip);
        $this->assertNotNull($row->browser);
        $this->assertNotNull($row->platform);
        $this->assertNotNull($row->device);
    }

    // =========================================================================================
    //  THE LEAK
    // =========================================================================================

    /** THE REPRODUCED DEFECT, exactly as measured. */
    public function test_a_login_post_stores_neither_the_password_nor_the_cookie(): void
    {
        $store = $this->themedStore();
        Customer::forceCreate([
            'first_name' => 'Shop', 'last_name' => 'Per', 'email' => 'victim@example.test',
            'password' => bcrypt(self::PASSWORD), 'store_id' => $store->id, 'status' => 1,
            'billable_id' => $store->created_by, 'billable_type' => \App\Models\User::class,
        ]);

        $this->visit('POST', '/' . $store->slug . '/login',
            ['email' => 'victim@example.test', 'password' => self::PASSWORD],
            ['Cookie' => 'laravel_session=' . self::COOKIE]);

        $this->assertSame(1, DB::table('shetabit_visits')->count(), 'Fixture premise: the login POST must have been logged.');
        $this->assertNothingSecretStored([self::PASSWORD, self::COOKIE], 'login');
    }

    public function test_the_authorization_and_csrf_headers_are_not_stored(): void
    {
        $store = $this->themedStore();

        $this->visit('GET', '/' . $store->slug . '/login', [], [
            'User-Agent' => 'HdrProbe/1.0',
            'Authorization' => 'Bearer BEARER-SENTINEL-1',
            'X-XSRF-TOKEN' => 'XSRF-SENTINEL-2',
            'X-CSRF-TOKEN' => 'CSRF-SENTINEL-3',
        ]);

        $this->assertSame(1, DB::table('shetabit_visits')->count());
        // Premise: the headers really reached the request -- the User-Agent sent alongside them is the
        // one the row recorded, so the sentinels above were on the wire and their absence means something.
        $this->assertSame('HdrProbe/1.0', DB::table('shetabit_visits')->value('useragent'));
        $this->assertNothingSecretStored(['BEARER-SENTINEL-1', 'XSRF-SENTINEL-2', 'CSRF-SENTINEL-3'], 'credential headers');
    }

    public function test_every_credential_bearing_storefront_post_stores_no_field_value(): void
    {
        $store = $this->themedStore();

        foreach (['login', 'register-data', 'forgot-password', 'reset-password', 'get-payment-NMI', 'authorizenet-status'] as $path) {
            $this->visit('POST', '/' . $store->slug . '/' . $path, [
                'email' => 'sentinel@example.test',
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
                'nmi_card_number' => self::CARD,
                'nmi_cvv_code' => self::CVV,
                'cardNumber' => self::CARD,
                'cvv' => self::CVV,
            ]);
        }

        $this->assertGreaterThanOrEqual(6, DB::table('shetabit_visits')->count(),
            'Fixture premise: each of the six POSTs must have been logged (a handler that redirects still passes ActiveTheme first).');
        $this->assertNothingSecretStored([self::PASSWORD, self::CARD, self::CVV, 'sentinel@example.test'], 'credential-bearing POSTs');
    }

    /** A password-reset link carries its token and the address in the query string, which `url` and `referer` store. */
    public function test_a_reset_token_in_the_query_string_is_redacted_from_url_and_referer(): void
    {
        $store = $this->themedStore();

        // The package reads the referer from the $_SERVER SUPERGLOBAL, not from the Request, so the test
        // client's server vars never reach it; set it as php-fpm would.
        $_SERVER['HTTP_REFERER'] = 'https://mail.example.test/inbox?token=' . self::TOKEN . '&otp=123456&folder=inbox';

        try {
            $this->visit('GET', '/' . $store->slug . '/reset-password?token=' . self::TOKEN . '&email=victim%40example.test&page=2',
                [], ['Referer' => $_SERVER['HTTP_REFERER']]);
        } finally {
            unset($_SERVER['HTTP_REFERER']);
        }

        $row = DB::table('shetabit_visits')->first();
        $this->assertNotNull($row);
        $this->assertStringContainsString('folder=inbox', (string) $row->referer, 'Premise: the referer column must have been written.');
        $this->assertStringContainsString('/reset-password', (string) $row->url, 'The path must survive: analytics group by it.');
        $this->assertStringContainsString('page=2', (string) $row->url, 'A harmless query parameter must survive.');
        $this->assertNothingSecretStored([self::TOKEN, 'victim@example.test', 'victim%40example.test', '123456'], 'reset link');
    }

    // =========================================================================================
    //  THE LOG MUST NEVER BREAK THE PAGE
    // =========================================================================================

    /**
     * `Visit` JSON-encodes the request, so ANY storefront request with invalid UTF-8 answered a 500
     * before its handler ran. The body is no longer stored; and a failure to log is reported, not thrown.
     */
    public function test_invalid_utf8_in_a_storefront_request_is_not_a_server_error(): void
    {
        $store = $this->themedStore();

        $response = $this->visit('POST', '/' . $store->slug . '/forgot-password', ['email' => "\xFF\xFE@example.test"]);

        $this->assertNotSame(500, $response->getStatusCode(),
            'A storefront request carrying invalid UTF-8 answered 500 from the visit log.');
    }

    public function test_a_failing_visit_write_does_not_fail_the_storefront_page(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName(),
            'This test creates a SQLite trigger. A different engine needs its own failure injection; '
            . 'do not turn this into a skip, the gate declares its skips by name.');

        $store = $this->themedStore();

        DB::unprepared("CREATE TRIGGER reject_visit_insert BEFORE INSERT ON shetabit_visits "
            . "BEGIN SELECT RAISE(ABORT, 'simulated visit-log failure'); END");

        try {
            $response = $this->visit('GET', '/' . $store->slug . '/login');
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS reject_visit_insert');
        }

        $this->assertNotSame(500, $response->getStatusCode(),
            'A visit-log write failure took the whole storefront page down with it.');
        $this->assertSame(0, DB::table('shetabit_visits')->count());
    }

    /** The anonymous order-details link is a CAPABILITY in the path (P1-ORD-09), so it must not be written down. */
    public function test_an_order_details_link_is_not_stored_in_url_or_referer(): void
    {
        $store = $this->themedStore();
        $link = \Illuminate\Support\Facades\Crypt::encrypt(7);

        $_SERVER['HTTP_REFERER'] = 'https://shop.test/' . $store->slug . '/order/' . $link . '?utm=mail';

        try {
            $this->visit('GET', '/' . $store->slug . '/order/' . $link);
        } finally {
            unset($_SERVER['HTTP_REFERER']);
        }

        $row = DB::table('shetabit_visits')->first();
        $this->assertNotNull($row, 'Fixture premise: the order-details visit must have been logged.');
        $this->assertStringContainsString('/' . $store->slug . '/order/', (string) $row->url, 'The route must survive for analytics.');
        $this->assertStringContainsString('utm=mail', (string) $row->referer, 'Premise: the referer column must have been written.');
        $this->assertNothingSecretStored([$link, substr($link, 0, 40)], 'order-details link');
    }

    /**
     * An invalid-UTF-8 User-Agent used to make the `headers` JSON cast throw, the catch swallowed it, and
     * -- with `report($e)` -- every such request was reported to the error tracker WITH the live cookies.
     * The strings are scrubbed instead: the visit is recorded and nothing is thrown.
     */
    public function test_an_invalid_utf8_user_agent_is_recorded_scrubbed_not_thrown_and_reported(): void
    {
        $store = $this->themedStore();

        \Illuminate\Support\Facades\Exceptions::fake();

        $response = $this->visit('GET', '/' . $store->slug . '/login', [], ['User-Agent' => "Bad\xFFAgent/1.0", 'Accept-Language' => "en\xFE-US"]);

        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertSame(1, DB::table('shetabit_visits')->count(),
            'The visit was lost: the headers JSON cast threw on invalid UTF-8.');
        \Illuminate\Support\Facades\Exceptions::assertNothingReported();
    }

    /** A visit that cannot be written is logged by CLASS only: the exception object would take the request to the error tracker. */
    public function test_a_failing_visit_write_is_not_reported_with_its_exception_object(): void
    {
        $store = $this->themedStore();

        \Illuminate\Support\Facades\Exceptions::fake();

        DB::unprepared("CREATE TRIGGER reject_visit_insert2 BEFORE INSERT ON shetabit_visits "
            . "BEGIN SELECT RAISE(ABORT, 'simulated visit-log failure'); END");

        try {
            $response = $this->visit('GET', '/' . $store->slug . '/login');
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS reject_visit_insert2');
        }

        $this->assertNotSame(500, $response->getStatusCode());
        \Illuminate\Support\Facades\Exceptions::assertNothingReported();
    }

    /**
     * Review round 3: the first version of the test above only asked that report() was not called, which also
     * passes for an EMPTY catch and for `Log::warning(..., ['exception' => $e])` -- the exact variant the comment in
     * ActiveTheme warns about, because the error tracker's handler acts on `context['exception']`. The log line is
     * now pinned: the class and a message, and NO exception object.
     */
    public function test_the_failure_is_logged_by_class_and_message_and_never_with_the_exception_object(): void
    {
        $store = $this->themedStore();

        \Illuminate\Support\Facades\Log::spy();

        DB::unprepared("CREATE TRIGGER reject_visit_insert3 BEFORE INSERT ON shetabit_visits "
            . "BEGIN SELECT RAISE(ABORT, 'simulated visit-log failure'); END");

        try {
            $this->visit('GET', '/' . $store->slug . '/login');
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS reject_visit_insert3');
        }

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->withArgs(function ($message, $context = []) {
                return $message === 'Storefront visit log failed'
                    && is_string($context['class'] ?? null)
                    && is_string($context['message'] ?? null)
                    && ! array_key_exists('exception', $context);
            })
            ->once();
    }
}
