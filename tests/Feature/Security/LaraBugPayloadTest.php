<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Services\ScrubbedLaraBug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use LaraBug\Http\Client;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * P1-SEC-14 -- THE ERROR TRACKER POSTED THE REQUEST'S SECRETS TO A THIRD PARTY.
 * =============================================================================
 *
 * vendor/larabug/larabug builds, for every exception it is asked to report, a payload that carries the full URL with its query string,
 * the DECRYPTED cookies (the session id, `remember_web_*`), the raw `cookie` header, `Session::all()`, `Request::all()`, old input and the
 * signed-in user's `toArray()`; Client::report POSTs it to config('larabug.server') whether or not LB_KEY is set. Its only protection is a
 * key-NAME glob (`config/larabug.php` `blacklist`).
 *
 * ORACLE: the array the real LaraBug classes hand to Client::report -- captured by a Client subclass, so the leak IS what is asserted on.
 * Not a mock of getExceptionData: the real `handle()` runs, with a real exception, a real request and a real signed-in User. Each secret is
 * a sentinel (a string that appears nowhere else in the payload), and the assertion is "this sentinel is not in the serialised payload".
 *
 * POSITIVE CONTROLS: the payload still carries the exception class, file, line, route and a harmless parameter, so "nothing leaked" cannot
 * pass by the tracker having been switched off.
 */
class LaraBugPayloadTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const SESSION_ID = 'SESSIONID-SENTINEL-77310';
    private const REMEMBER = 'REMEMBER-SENTINEL-55821';
    private const CARD = '4111111111116024';
    private const SECRET = 'SECRETKEY-SENTINEL-90417';
    private const EXPIRY = 'EXPIRY-SENTINEL-1229';
    private const QUERY_TOKEN = 'QUERYTOKEN-SENTINEL-30192';
    private const BOUND_CARD = '5555444433332201';
    private const USER_SECRET = 'GOOGLE2FA-SENTINEL-66205';
    // A constant, never a literal beside the throw: the payload carries the source lines around the exception, this file's included.
    private const USER_EMAIL = 'sentinel-user-email@example.test';

    /** @var array|null the argument of the last Client::report call */
    public ?array $reported = null;

    private function tracker(): ScrubbedLaraBug
    {
        $test = $this;

        $client = new class ('login', 'project', $test) extends Client {
            public function __construct(string $login, string $project, private $test)
            {
                parent::__construct($login, $project);
            }

            public function report($exception)
            {
                $this->test->reported = $exception;

                return null;
            }
        };

        // The class under test is built exactly as AppServiceProvider builds it; only the transport differs.
        return new ScrubbedLaraBug($client);
    }

    private function enableTracker(): void
    {
        config([
            'larabug.environments' => ['testing'],
            'larabug.sleep' => 0,
            'larabug.except' => [],
        ]);
    }

    /** Make $request the request the facades read, with a session, as the framework does during a live request. */
    private function bindRequest(HttpRequest $request, array $session = []): void
    {
        $request->setLaravelSession($this->app['session.store']);

        foreach ($session as $key => $value) {
            $this->app['session.store']->put($key, $value);
        }

        $this->app->instance('request', $request);
        \Illuminate\Support\Facades\Request::swap($request);
    }

    private function payload(): string
    {
        $this->assertNotNull($this->reported, 'The tracker reported nothing: the test did not reach Client::report.');

        return serialize($this->reported);
    }

    private function assertAbsent(array $secrets, string $payload, string $context): void
    {
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $payload,
                "{$context}: '{$secret}' was in the payload LaraBug posts to the tracker.");
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableTracker();
        $this->reported = null;
    }

    // =========================================================================================
    //  PREMISE
    // =========================================================================================

    /** The binding the application actually resolves is the scrubbing subclass, not the package's. */
    public function test_the_container_resolves_the_scrubbing_tracker(): void
    {
        $this->assertInstanceOf(ScrubbedLaraBug::class, $this->app->make('larabug'));
        $this->assertSame($this->app->make('larabug'), $this->app->make('larabug'), 'singleton, as the package binds it');
    }

    /** The log channel the package registers resolves the same binding, so a reported log record is scrubbed too. */
    public function test_the_log_channel_handler_uses_the_container_binding(): void
    {
        $handler = new \LaraBug\Logger\LaraBugHandler($this->app['larabug']);
        $property = (new \ReflectionClass($handler))->getProperty('laraBug');
        $property->setAccessible(true);

        $this->assertInstanceOf(ScrubbedLaraBug::class, $property->getValue($handler));
    }

    // =========================================================================================
    //  THE PAYLOAD: SECRETS CARRIED BY THE REQUEST
    // =========================================================================================

    public function test_cookies_session_card_and_gateway_secrets_are_not_posted(): void
    {
        $request = HttpRequest::create(
            '/store-a/checkout?harmless=1',
            'POST',
            [
                'nmi_card_number' => self::CARD,
                'cardNumber' => self::CARD,
                'cc_number' => self::CARD,
                'exp_month' => self::EXPIRY,
                'expiry' => self::EXPIRY,
                'stripe_secret_key' => self::SECRET,
                'harmless_param' => 'visible-value',
            ],
            [
                'store_syria_session' => self::SESSION_ID,
                'remember_web_59ba36addc2b2f9401580f014c7f58ea4e30989d' => self::REMEMBER,
            ],
            [],
            ['HTTP_COOKIE' => 'store_syria_session=' . self::SESSION_ID . '; remember_web_x=' . self::REMEMBER]
        );
        $this->bindRequest($request, ['_token' => 'CSRFTOKEN-SENTINEL-1', 'login_web_x' => 4242]);

        $this->tracker()->handle(new \RuntimeException('checkout failed'));

        $payload = $this->payload();

        $this->assertAbsent(
            [self::SESSION_ID, self::REMEMBER, self::CARD, self::SECRET, self::EXPIRY],
            $payload,
            'request carrying cookies, a card and a gateway secret'
        );

        // Positive controls: the report is still useful. Asserted on the FIELDS -- the payload also carries the source lines around the
        // throw (this file's own text), so a substring check on the whole payload would pass whatever the scrubber did.
        $this->assertSame('checkout failed', $this->reported['exception']['exception']);
        $this->assertSame(\RuntimeException::class, $this->reported['exception']['class']);
        $this->assertSame('visible-value', $this->reported['exception']['storage']['PARAMETERS']['harmless_param'], 'a harmless parameter must survive');
        $this->assertStringContainsString('/store-a/checkout', $this->reported['exception']['fullUrl']);
    }

    public function test_a_secret_in_the_query_string_is_not_posted_in_the_url(): void
    {
        $request = HttpRequest::create('/store-a/pay?api_token=' . self::QUERY_TOKEN . '&secret=' . self::SECRET . '&page=3', 'GET');
        $this->bindRequest($request);

        $this->tracker()->handle(new \RuntimeException('boom'));

        $payload = $this->payload();

        $this->assertAbsent([self::QUERY_TOKEN, self::SECRET], $payload, 'secrets in the query string');
        $this->assertStringContainsString('page=3', $this->reported['exception']['fullUrl'], 'a harmless query value must survive');
        $this->assertStringContainsString('/store-a/pay', $this->reported['exception']['fullUrl']);
    }

    /**
     * A value too short to be scrubbed as a VALUE (see MIN_SECRET_LENGTH) is still removed from the URL by its KEY.
     */
    public function test_a_short_secret_in_the_query_string_is_filtered_by_its_key(): void
    {
        $this->bindRequest(HttpRequest::create('/store-a/verify?pin=4821&page=3', 'GET'));

        $this->tracker()->handle(new \RuntimeException('boom'));

        $url = $this->reported['exception']['fullUrl'];

        $this->assertStringNotContainsString('4821', $url);
        $this->assertStringContainsString('page=3', $url);
    }

    /**
     * The same secret travelling under an innocent key in the URL (`ref`) while the body sent it under a blacklisted one: the key filter
     * cannot see it, the value scrub does.
     */
    public function test_a_secret_value_under_an_innocent_query_key_is_scrubbed_from_the_url(): void
    {
        $this->bindRequest(HttpRequest::create('/store-a/cb?ref=' . self::SECRET, 'POST', ['stripe_secret_key' => self::SECRET]));

        $this->tracker()->handle(new \RuntimeException('boom'));

        $this->assertStringNotContainsString(self::SECRET, $this->reported['exception']['fullUrl']);
        $this->assertStringContainsString('/store-a/cb?ref=', $this->reported['exception']['fullUrl']);
    }

    /**
     * A QueryException embeds its BOUND VALUES in the message, so the key glob -- which looks at keys -- cannot see a card number that
     * a failing INSERT carried. The value the request sent under a blacklisted key is removed from the message and trace as well.
     */
    public function test_a_value_the_request_sent_as_a_secret_is_removed_from_the_exception_message_and_trace(): void
    {
        $request = HttpRequest::create('/store-a/checkout', 'POST', ['nmi_card_number' => self::BOUND_CARD, 'note' => 'ok']);
        $this->bindRequest($request);

        $exception = new \RuntimeException(
            'SQLSTATE[22001]: Data too long (Connection: mysql, SQL: insert into `orders` (`card`) values (' . self::BOUND_CARD . '))'
        );

        $this->tracker()->handle($exception);

        $payload = $this->payload();

        $this->assertAbsent([self::BOUND_CARD], $payload, 'exception message built from bound values');
        $this->assertStringContainsString('SQLSTATE[22001]', $this->reported['exception']['exception'], 'positive control: the message is still reported');
        $this->assertStringContainsString('values (***)', $this->reported['exception']['exception']);
    }

    /**
     * The trace string embeds the arguments of each frame when `zend.exception_ignore_args` is off (the development default; each string is
     * cut at 15 characters, so only a short secret appears whole -- an OTP, a PIN).
     */
    public function test_a_short_secret_in_a_trace_argument_is_removed_from_the_trace(): void
    {
        // php.ini-production sets both of these the other way (ignore_args On, string_param_max_len 0), which is why a trace
        // there carries no argument at all; the development default is what the tracker sees on a developer's machine.
        $ignore = ini_set('zend.exception_ignore_args', '0');
        $length = ini_set('zend.exception_string_param_max_len', '15');

        try {
            $this->bindRequest(HttpRequest::create('/store-a/verify', 'POST', ['otp' => '482913']));

            $this->tracker()->handle($this->exceptionRaisedWith('482913'));
        } finally {
            ini_set('zend.exception_ignore_args', $ignore === false ? '1' : $ignore);
            ini_set('zend.exception_string_param_max_len', $length === false ? '0' : $length);
        }

        $trace = $this->reported['exception']['error'];

        $this->assertStringContainsString('exceptionRaisedWith(', $trace, 'premise: the trace is the one the helper produced');
        $this->assertStringNotContainsString("exceptionRaisedWith('...')", $trace, 'premise: the arguments were recorded, so the scrub has something to do');
        $this->assertStringNotContainsString('482913', $trace);
    }

    private function exceptionRaisedWith(string $argument): \RuntimeException
    {
        return new \RuntimeException('verification failed');
    }

    /** Several secrets, one containing another: the longer is replaced first, so no tail of it survives. */
    public function test_overlapping_secrets_leave_no_remnant(): void
    {
        $this->bindRequest(HttpRequest::create('/x', 'POST', ['otp' => 'ABC123', 'client_secret' => 'ABC123-TAIL-77001']));

        $this->tracker()->handle(new \RuntimeException('failed for ABC123-TAIL-77001'));

        $message = $this->reported['exception']['exception'];

        $this->assertStringNotContainsString('TAIL-77001', $message);
        $this->assertStringNotContainsString('ABC123', $message);
    }

    /** A JSON body carries numbers as numbers. */
    public function test_numeric_secrets_are_removed_from_the_message(): void
    {
        $this->bindRequest(HttpRequest::create('/x', 'POST', ['otp' => 482913, 'secret_amount' => 1234567.25]));

        $this->tracker()->handle(new \RuntimeException('bad otp 482913 and 1234567.25'));

        $message = $this->reported['exception']['exception'];

        $this->assertStringNotContainsString('482913', $message);
        $this->assertStringNotContainsString('1234567.25', $message);
        $this->assertStringStartsWith('bad otp', $message, 'positive control');
    }

    public function test_a_key_is_matched_whatever_its_case(): void
    {
        $this->bindRequest(HttpRequest::create('/x', 'POST', ['CARD_NUMBER' => self::CARD]));

        $this->tracker()->handle(new \RuntimeException('failed ' . self::CARD));

        $this->assertStringNotContainsString(self::CARD, $this->reported['exception']['exception']);
    }

    // -- every place the request can carry a value ------------------------------------------

    public function test_a_value_carried_only_by_a_cookie_is_removed_from_the_message(): void
    {
        $this->bindRequest(HttpRequest::create('/x', 'GET', [], ['store_syria_session' => self::SESSION_ID]));

        $this->tracker()->handle(new \RuntimeException('invalid session ' . self::SESSION_ID));

        $this->assertStringNotContainsString(self::SESSION_ID, $this->reported['exception']['exception']);
    }

    public function test_a_value_carried_only_by_a_header_is_removed_from_the_message(): void
    {
        $this->bindRequest(HttpRequest::create('/x', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . self::SECRET]));

        $this->tracker()->handle(new \RuntimeException('rejected: Bearer ' . self::SECRET));

        $this->assertStringNotContainsString(self::SECRET, $this->reported['exception']['exception']);
    }

    public function test_a_value_carried_only_by_the_session_is_removed_from_the_message(): void
    {
        $this->bindRequest(HttpRequest::create('/x', 'GET'), ['payment_secret' => self::SECRET]);

        $this->tracker()->handle(new \RuntimeException('could not charge with ' . self::SECRET));

        $this->assertStringNotContainsString(self::SECRET, $this->reported['exception']['exception']);
    }

    /** Old input is flashed into the session, so the session source covers it. */
    public function test_a_value_carried_only_by_flashed_old_input_is_removed_from_the_message(): void
    {
        $this->bindRequest(HttpRequest::create('/x', 'GET'), ['_old_input' => ['nmi_card_number' => self::CARD]]);

        $this->tracker()->handle(new \RuntimeException('failed ' . self::CARD));

        $this->assertStringNotContainsString(self::CARD, $this->reported['exception']['exception']);
    }

    public function test_a_secret_sent_in_a_nested_array_is_removed_from_the_message(): void
    {
        $request = HttpRequest::create('/store-a/checkout', 'POST', ['payment' => ['stripe_secret_key' => self::SECRET, 'amount' => '12.50']]);
        $this->bindRequest($request);

        $this->tracker()->handle(new \RuntimeException('gateway said: bad key ' . self::SECRET));

        $payload = $this->payload();

        $this->assertAbsent([self::SECRET], $payload, 'nested secret echoed in a message');
        $this->assertSame('12.50', $this->reported['exception']['storage']['PARAMETERS']['payment']['amount'], 'a harmless nested value must survive');
    }

    /** The CHILD key is innocent; the PARENT is a secret, so everything under it is. */
    public function test_a_value_under_a_secret_parent_is_removed_whatever_its_own_key(): void
    {
        $this->bindRequest(HttpRequest::create('/x', 'POST', ['card' => ['holder_ref' => self::CARD, 'brand' => 'visa']]));

        $this->tracker()->handle(new \RuntimeException('declined ' . self::CARD));

        $this->assertStringNotContainsString(self::CARD, $this->reported['exception']['exception']);
    }

    /** A value shorter than the minimum would scrub every occurrence of a short common string; it is deliberately left alone. */
    public function test_a_short_value_under_a_secret_key_does_not_scrub_ordinary_words(): void
    {
        $request = HttpRequest::create('/store-a/checkout', 'POST', ['pin' => 'a']);
        $this->bindRequest($request);

        $this->tracker()->handle(new \RuntimeException('a banana'));

        $this->assertSame('a banana', $this->reported['exception']['exception']);
    }

    // =========================================================================================
    //  THE USER
    // =========================================================================================

    public function test_the_signed_in_user_is_reported_as_an_id_and_a_type_not_as_the_model(): void
    {
        // The GoogleAuthentication module's migration adds this column in production (no WorkDo module loads under test), so the
        // fixture adds it the same way; toArray() would carry it, hidden only if the model said so.
        if (! Schema::hasColumn('users', 'google2fa_secret')) {
            Schema::table('users', fn ($table) => $table->text('google2fa_secret')->nullable());
        }

        $user = User::factory()->create([
            'type' => 'admin',
            'email' => self::USER_EMAIL,
            'google2fa_secret' => self::USER_SECRET,
        ]);
        Auth::guard('web')->setUser($user);
        $this->actingAs($user, 'web');
        $this->bindRequest(HttpRequest::create('/dashboard', 'GET'));

        $this->tracker()->handle(new \RuntimeException('as a user'));

        $payload = $this->payload();

        $this->assertAbsent([self::USER_SECRET, self::USER_EMAIL, (string) $user->password], $payload, 'signed-in user');
        $this->assertSame((int) $user->id, (int) $this->reported['user']['id'], 'positive control: the user is still identified');
        $this->assertSame('admin', $this->reported['user']['type']);
        $this->assertSame(['id', 'type', 'created_by'], array_keys($this->reported['user']));
    }

    public function test_the_user_model_is_larabugable(): void
    {
        $this->assertInstanceOf(\LaraBug\Concerns\Larabugable::class, new User());
    }

    // =========================================================================================
    //  THE BLACKLIST: every entry earns its place
    // =========================================================================================

    /** @return array<string, array{0: string}> */
    public static function secretKeys(): array
    {
        $keys = [
            // pre-existing entries
            'authorization', 'password', 'password_confirmation', 'api_token', 'verification_code', 'credit_card_number', 'cardToken', 'cvv',
            'cvv_code', 'iban', 'email',
            // added by P1-SEC-14
            'cookie', 'Cookie', 'laravel_session', 'session_id', 'remember_web_59ba36addc2b2f9401580f014c7f58ea4e30989d', 'remember_token',
            'stripe_secret_key', 'google2fa_secret', 'client_secret', 'razorpay_api_key', 'public_key', 'private_key',
            'nmi_card_number', 'cardNumber', 'PowerTranz_card_number', 'cc_number', 'cc_cvv', 'card_holder', 'exp_month', 'exp_year',
            'expiry', 'expiration', 'expiry_date', 'otp', 'login_otp', 'pin', 'user_pin', 'signature', 'x_signature', '_csrf', 'XSRF-TOKEN',
            'bearer', 'login_web_59ba36addc2b2f9401580f014c7f58ea4e30989d', 'cc_holder', 'account_number',
        ];

        return array_combine($keys, array_map(fn ($key) => [$key], $keys));
    }

    #[DataProvider('secretKeys')]
    public function test_the_key_is_filtered_by_the_real_filter(string $key): void
    {
        $filtered = $this->tracker()->filterVariables([$key => 'VALUE-SENTINEL-' . md5($key), 'harmless_field' => 'kept']);

        $this->assertSame('***', $filtered[$key], "'{$key}' reached the tracker unfiltered.");
        $this->assertSame('kept', $filtered['harmless_field']);
    }

    /** Keys the application's own forms use that are NOT secrets must not vanish from a report. */
    public function test_ordinary_diagnostic_keys_survive_the_filter(): void
    {
        $filtered = $this->tracker()->filterVariables(['page' => '2', 'sort' => 'asc', 'store_id' => '7', 'product_id' => '9', 'qty' => '1']);

        $this->assertSame(['page' => '2', 'sort' => 'asc', 'store_id' => '7', 'product_id' => '9', 'qty' => '1'], $filtered);
    }

    // =========================================================================================
    //  THE JAVASCRIPT-REPORT ROUTE
    // =========================================================================================

    /**
     * Premise for the next two tests: the package's unauthenticated route is live in this application.
     */
    public function test_the_package_javascript_route_is_registered_and_unauthenticated(): void
    {
        $route = $this->app['router']->getRoutes()->match(HttpRequest::create('/larabug-api/javascript-report', 'POST'));

        $this->assertSame('larabug-api/javascript-report', $route->uri());
        $this->assertSame([], array_values(array_diff($route->gatherMiddleware(), ['web', 'api'])), 'no auth middleware is on the route');
    }

    /**
     * `LaraBug::handle(..., 'javascript', ...)` runs `file($data['file'])` on a path taken from the request body, and posts the lines around
     * `line` to the tracker. Anyone can POST it. The application has no JavaScript client that calls it.
     */
    public function test_an_anonymous_javascript_report_cannot_make_the_server_read_a_file_and_post_it(): void
    {
        $secretFile = tempnam(sys_get_temp_dir(), 'larabug');
        file_put_contents($secretFile, "line one\nDB_PASSWORD=FILE-SENTINEL-88231\nline three\n");

        try {
            $tracker = $this->tracker();
            $this->app->instance('larabug', $tracker);

            $response = $this->postJson('/larabug-api/javascript-report', [
                'message' => 'js error',
                'file' => $secretFile,
                'line' => 2,
                'stack' => 'at x',
                'url' => 'https://example.test/',
            ]);

            $sent = $this->reported === null ? '' : serialize($this->reported);

            $this->assertStringNotContainsString('FILE-SENTINEL-88231', $sent,
                'An anonymous POST made the server read a local file and hand its lines to the third-party tracker.');
            $this->assertContains($response->getStatusCode(), [200, 404], 'the route must not 500 for the caller');
        } finally {
            @unlink($secretFile);
        }
    }

    public function test_a_javascript_report_with_a_url_as_the_file_makes_no_outbound_read(): void
    {
        $tracker = $this->tracker();

        $result = $tracker->handle(new \ErrorException('x'), 'javascript', [
            'file' => 'http://127.0.0.1:9/never',
            'line' => 1,
            'message' => 'x',
            'stack' => 'y',
            'url' => 'https://example.test/',
        ]);

        $this->assertFalse($result, 'a javascript report is refused outright');
        $this->assertNull($this->reported, 'and nothing is posted');
    }

    /** Positive control for the two above: an ordinary PHP exception report still goes out through the same instance. */
    public function test_a_php_exception_report_still_goes_out(): void
    {
        $this->bindRequest(HttpRequest::create('/x', 'GET'));

        $this->tracker()->handle(new \LogicException('still works'));

        $this->assertSame('still works', $this->reported['exception']['exception']);
        $this->assertSame(\LogicException::class, $this->reported['exception']['class']);
    }
}
