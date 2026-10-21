<?php

namespace Tests\Feature\Security;

use App\Models\Cart;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * P1-SEC-17 -- credentials handed to the browser.
 * =============================================================================================
 *
 * F1 (HIGH). `POST {storeSlug?}/get-massage` (PaymentController::getWhatsappUrl) is anonymous.
 * For `data[type]=telegram` it posted the order text to Telegram ITSELF and then returned the
 * request URL it had used -- `https://api.telegram.org/bot<TOKEN>/sendMessage` -- as the response
 * body. The shipped page (resources/views/payment/telegram.blade.php) stores that body in a hidden
 * input nothing reads, so the only thing the return value ever did was give the bot token to
 * whoever asked. A guest reaches the handler with two ordinary calls: `process-order` with
 * `payment_type=telegram` (the allowlist checks no enabled flag) and then `get-massage`.
 *
 * WHAT THIS FILE CLAIMS, AND HOW IT IS KEPT FROM PASSING FOR THE WRONG REASON
 *
 *  - The Telegram call is faked at BOTH transports it could use (a stream wrapper standing in for
 *    `https://`, and Laravel's Http client), and what each saw is recorded in one list. So the
 *    delivery assertions do not depend on which transport the handler uses, and a handler that
 *    never reached Telegram fails the premise test instead of satisfying every "no token" test.
 *  - Every "no token" assertion is paired with the premise that the token WAS used server-side.
 *  - The token is a distinctive sentinel, searched for in the body, the headers, and every record
 *    written to the log, including the exception message of a failed delivery.
 *
 * The sentinel carries `FIXTURE` so the CI secret scan's generic-assigned-secret rule exempts it.
 */
class StorefrontCredentialDisclosureTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const BOT_TOKEN = '777000111:FIXTURE-telegram-bot-token-AAHsentinel0001';

    private const CHAT_ID = '-100123456789';

    /** @var list<array{url: string, form: array}> every outbound Telegram call, whichever transport made it */
    public static array $telegramCalls = [];

    /** @var list<string> every record the application wrote to its log during the request */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        self::$telegramCalls = [];
        $this->logged = [];

        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logged[] = $e->message . ' ' . json_encode($e->context, JSON_PARTIAL_OUTPUT_ON_ERROR);
        });
    }

    protected function tearDown(): void
    {
        @stream_wrapper_restore('https');

        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------------------------------

    /** @return array{0: \App\Models\User, 1: \App\Models\Store} */
    private function tenantWithTelegram(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'cred-plan-' . uniqid(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'off',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->save();
        Cache::forget('admin_details');

        foreach ([
            'stock_management' => 'on',
            'out_of_stock_threshold' => '0',
            'notification' => '[]',
            'telegram_access_token' => self::BOT_TOKEN,
            'telegram_chat_id' => self::CHAT_ID,
            'whatsapp_number' => '963900000000',
            'whatsapp_item_variable' => '{quantity} x {product_name}',
            'whatsapp_content' => 'New order {order_no} from {customer_name}: {item_variable}',
        ] as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => $value,
                'created_by' => $merchant->id, 'store_id' => $store->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return [$merchant, $store];
    }

    private function guestCartIn($store): string
    {
        $product = Product::forceCreate([
            'name' => 'cred-' . uniqid(), 'slug' => 'cred-' . uniqid(),
            'product_stock' => 50, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 100.00, 'sale_price' => 100.00,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);

        $sid = 'cred-cookie-' . uniqid();

        Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
            'cookie_session_id' => $sid,
        ]);

        return $sid;
    }

    /** What processOrder() leaves in the session for a Telegram checkout. */
    private function checkoutState($store): array
    {
        return [
            'slug' => $store->slug,
            'store_id' => $store->id,
            'customer_id' => '',
            'payment_type' => 'telegram',
            'shipping_final_price' => 0,
            'tax_price' => 0,
            'cartlist_final_price' => 100.00,
            'billing_info' => [
                'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.test',
                'billing_user_telephone' => '123', 'billing_address' => 'x',
                'billing_postecode' => '1', 'billing_country' => '101',
                'billing_state' => '1', 'billing_city' => '1',
                'delivery_address' => 'x', 'delivery_postcode' => '1',
                'delivery_country' => '101', 'delivery_state' => '1', 'delivery_city' => '1',
            ],
        ];
    }

    /**
     * Stand in for Telegram on every transport the handler could use.
     *
     * @param  bool  $answer  false makes Telegram refuse (HTTP 401), the shape of a revoked token
     */
    private function fakeTelegram(bool $answer = true): void
    {
        RecordingTelegramStream::$answer = $answer;
        RecordingTelegramStream::$sink = &self::$telegramCalls;

        @stream_wrapper_unregister('https');
        stream_wrapper_register('https', RecordingTelegramStream::class);

        Http::fake(function ($request) use ($answer) {
            self::$telegramCalls[] = ['url' => $request->url(), 'form' => $request->data()];

            return Http::response($answer ? ['ok' => true] : ['ok' => false, 'description' => 'Unauthorized'], $answer ? 200 : 401);
        });
    }

    private function getMassage($store, string $type, ?string $sid = null)
    {
        // The response guard (WithholdTelegramBotUrl) would blank a leaked body on its own, which would
        // let these tests pass with the controller fix removed. It is switched off HERE so that what is
        // measured is the controller; the guard has its own tests in ModuleCredentialDisclosureTest.
        return $this->withoutMiddleware(\App\Http\Middleware\WithholdTelegramBotUrl::class)
            ->withCookie('cart', $sid ?? $this->guestCartIn($store))
            ->withSession(['request_data' => $this->checkoutState($store)])
            ->post('/' . $store->slug . '/get-massage', [
                'data' => ['type' => $type, 'order_id' => 'ORD-1'],
            ]);
    }

    private function everythingTheClientCouldSee($response): string
    {
        return $response->getContent() . "\n" . json_encode($response->headers->all());
    }

    // ---------------------------------------------------------------------------------------------
    // F1 -- the Telegram bot token
    // ---------------------------------------------------------------------------------------------

    /**
     * THE PREMISE. Without this the tests below can pass against a handler that never reached
     * Telegram (it returns early on a fixture gap) -- which is exactly how a disclosure test
     * passes for the wrong reason.
     */
    public function test_the_telegram_order_message_is_still_delivered_server_side(): void
    {
        [, $store] = $this->tenantWithTelegram();
        $this->fakeTelegram();

        $response = $this->getMassage($store, 'telegram');

        $this->assertCount(1, self::$telegramCalls,
            'The handler never made its Telegram call (HTTP ' . $response->getStatusCode() . '), so every '
            . 'non-disclosure assertion in this file would be vacuous. Body: ' . substr((string) $response->getContent(), 0, 300));

        $call = self::$telegramCalls[0];

        $this->assertSame('https://api.telegram.org/bot' . self::BOT_TOKEN . '/sendMessage', $call['url'],
            'The message must still go to the merchant\'s bot, with the merchant\'s token, from the server.');
        $this->assertSame(self::CHAT_ID, (string) ($call['form']['chat_id'] ?? ''));
        $this->assertStringContainsString('ORD-1', (string) ($call['form']['text'] ?? ''),
            'The order text was not what reached Telegram.');
    }

    public function test_the_response_to_an_anonymous_telegram_request_carries_no_bot_token(): void
    {
        [, $store] = $this->tenantWithTelegram();
        $this->fakeTelegram();

        $response = $this->getMassage($store, 'telegram');

        $this->assertCount(1, self::$telegramCalls, 'Premise: the token was used server-side.');
        $this->assertLessThan(500, $response->getStatusCode());

        $seen = $this->everythingTheClientCouldSee($response);

        $this->assertStringNotContainsString(self::BOT_TOKEN, $seen, 'The bot token was returned to the caller.');
        $this->assertStringNotContainsString('api.telegram.org/bot', $seen, 'A Telegram bot URL was returned to the caller.');
        $this->assertStringNotContainsString('777000111', $seen, 'The bot id half of the token was returned.');
    }

    /**
     * The shipped telegram page ignores the body, so withholding it must not change what the
     * page does: the request still succeeds. (The WhatsApp page DOES read its body -- next test.)
     */
    public function test_the_telegram_request_still_succeeds_for_the_page_that_sent_it(): void
    {
        [, $store] = $this->tenantWithTelegram();
        $this->fakeTelegram();

        $this->getMassage($store, 'telegram')->assertOk();
    }

    /**
     * The WhatsApp branch returns a wa.me-style link the page DOES use. Nothing in it is secret and
     * it must not change.
     */
    public function test_the_whatsapp_branch_still_returns_its_link(): void
    {
        [, $store] = $this->tenantWithTelegram();
        $this->fakeTelegram();

        $response = $this->getMassage($store, 'whatsapp');

        $response->assertOk();
        $this->assertStringStartsWith('https://api.whatsapp.com/send?phone=963900000000&text=', (string) $response->getContent());
        $this->assertCount(0, self::$telegramCalls, 'The WhatsApp branch must not call Telegram.');
    }

    /**
     * A revoked or mistyped token makes Telegram answer 401. The old transport raised a PHP warning
     * whose MESSAGE is the full request URL -- `file_get_contents(https://api.telegram.org/bot<TOKEN>/
     * sendMessage): Failed to open stream` -- which became the exception text, and exception text is
     * written to the log and shipped to the error tracker. The token must not survive a failure.
     */
    public function test_a_failed_delivery_does_not_write_the_bot_token_anywhere(): void
    {
        [, $store] = $this->tenantWithTelegram();
        $this->fakeTelegram(answer: false);

        $response = $this->getMassage($store, 'telegram');

        $this->assertNotEmpty(self::$telegramCalls, 'Premise: the failing call was attempted.');

        $this->assertStringNotContainsString(self::BOT_TOKEN, $this->everythingTheClientCouldSee($response));

        foreach ($this->logged as $record) {
            $this->assertStringNotContainsString(self::BOT_TOKEN, $record, 'The bot token was written to the log: ' . substr($record, 0, 200));
            $this->assertStringNotContainsString('api.telegram.org/bot', $record, 'A Telegram bot URL was written to the log.');
        }
    }

    /**
     * The branch that matters most: a CONNECTION failure. Guzzle's ConnectionException message
     * carries the request URL -- `cURL error 28: ... for https://api.telegram.org/bot<TOKEN>/sendMessage`
     * -- so logging `$e->getMessage()` (or the exception) would write the token. Faked as the thrown
     * exception it really is, with the URL in its message, so a handler that logs the message fails.
     */
    public function test_a_connection_failure_does_not_write_the_bot_token_anywhere(): void
    {
        [, $store] = $this->tenantWithTelegram();

        Http::fake(function ($request) {
            self::$telegramCalls[] = ['url' => $request->url(), 'form' => $request->data()];

            throw new \Illuminate\Http\Client\ConnectionException(
                'cURL error 28: Operation timed out after 10001 milliseconds for ' . $request->url()
            );
        });

        $response = $this->getMassage($store, 'telegram');

        $this->assertCount(1, self::$telegramCalls, 'Premise: the connection failure was attempted and thrown.');
        $this->assertLessThan(500, $response->getStatusCode(), 'A failed notification must not fail the request.');
        $this->assertStringNotContainsString(self::BOT_TOKEN, $this->everythingTheClientCouldSee($response));

        $this->assertNotEmpty($this->logged, 'Premise: the failure was logged at all, so the loop below examines something.');

        foreach ($this->logged as $record) {
            $this->assertStringNotContainsString(self::BOT_TOKEN, $record, 'The bot token was written to the log: ' . substr($record, 0, 200));
            $this->assertStringNotContainsString('api.telegram.org/bot', $record, 'A Telegram bot URL was written to the log.');
        }
    }

    /** A store with no bot configured must not make a request to `https://api.telegram.org/bot/sendMessage`. */
    public function test_a_store_without_a_bot_token_makes_no_telegram_call(): void
    {
        [$merchant, $store] = $this->tenantWithTelegram();
        DB::table('settings')->where('store_id', $store->id)->where('name', 'telegram_access_token')->delete();
        $this->fakeTelegram();

        $response = $this->getMassage($store, 'telegram');

        $response->assertOk();
        $this->assertCount(0, self::$telegramCalls, 'A request was made for a store with no bot token.');
        $this->assertNotEmpty($this->logged, 'Premise: the skipped delivery was logged.');
    }

    // ---------------------------------------------------------------------------------------------
    // F3 -- the platform reCAPTCHA secret, rendered into a script on verify-email
    // ---------------------------------------------------------------------------------------------

    private const CAPTCHA_SECRET = 'FIXTURE-recaptcha-secret-6LcSentinelSecret0001';

    private const CAPTCHA_SITEKEY = 'FIXTURE-recaptcha-sitekey-6LcSentinelSite0001';

    /** A platform operator whose reCAPTCHA settings are the ones getSuperAdminAllSetting() reads. */
    private function platformWithCaptcha(string $version = 'v3'): void
    {
        $operator = \App\Models\User::factory()->create([
            'type' => 'super admin', 'created_by' => 0, 'is_active' => 1, 'email_verified_at' => now(),
        ]);
        $store = $this->makeStore($operator);
        $operator->current_store = $store->id;
        $operator->save();

        foreach ([
            'RECAPTCHA_MODULE' => 'yes',
            'NOCAPTCHA_VERSON' => $version,
            'NOCAPTCHA_SECRET' => self::CAPTCHA_SECRET,
            'NOCAPTCHA_SITEKEY' => self::CAPTCHA_SITEKEY,
        ] as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => $value,
                'created_by' => $operator->id, 'store_id' => $store->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Cache::forget('super_admin_details');
    }

    private function unverifiedMerchantPage()
    {
        $merchant = $this->makeMerchant(['email_verified_at' => null]);

        return $this->actingAs($merchant)->get('/verify-email');
    }

    /** The premise: the page is the verify-email prompt, and the v3 script block IS rendered. */
    public function test_the_verify_email_page_renders_the_recaptcha_script_with_the_site_key(): void
    {
        $this->platformWithCaptcha();

        $response = $this->unverifiedMerchantPage();

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('google.com/recaptcha/api.js?render=', $html, 'Premise: the v3 script block was not rendered.');
        $this->assertStringContainsString(self::CAPTCHA_SITEKEY, $html, 'The page must use the public site key.');
    }

    public function test_the_verify_email_page_does_not_contain_the_recaptcha_secret(): void
    {
        $this->platformWithCaptcha();

        $response = $this->unverifiedMerchantPage();

        $response->assertOk();
        $this->assertStringContainsString('google.com/recaptcha/api.js?render=', $response->getContent(), 'Premise: the script block was rendered.');
        $this->assertStringNotContainsString(self::CAPTCHA_SECRET, $response->getContent(),
            'The platform reCAPTCHA SECRET was rendered into the page.');
    }
}

/**
 * Stands in for the `https://` stream wrapper so `file_get_contents('https://api.telegram.org/...')`
 * is observable without a network. Records the URL and the POSTed form, answers 200 (or refuses).
 *
 * @internal
 */
final class RecordingTelegramStream
{
    public static bool $answer = true;

    /** @var array */
    public static $sink = [];

    /** @var resource|null set by PHP when a context was passed */
    public $context;

    private int $position = 0;

    private string $body = '{"ok":true,"result":{}}';

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $http = stream_context_get_options($this->context ?? stream_context_create())['http'] ?? [];
        parse_str((string) ($http['content'] ?? ''), $form);

        self::$sink[] = ['url' => $path, 'form' => $form];

        if (! self::$answer) {
            trigger_error("file_get_contents({$path}): Failed to open stream: HTTP request failed! HTTP/1.1 401 Unauthorized", E_USER_WARNING);

            return false;
        }

        return true;
    }

    public function stream_read(int $count): string|false
    {
        $chunk = substr($this->body, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->body);
    }

    public function stream_stat(): array|false
    {
        return [];
    }

    public function stream_close(): void
    {
    }
}
