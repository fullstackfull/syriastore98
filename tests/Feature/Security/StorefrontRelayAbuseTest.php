<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/**
 * P1-SEC-22 (a) -- `POST {storeSlug}/get-massage` WAS AN UNTHROTTLED, UNGATED ANONYMOUS TELEGRAM RELAY.
 * =====================================================================================================
 *
 * For `data[type]=telegram` the handler makes the SERVER post one message to the merchant's Telegram chat per call. The route is anonymous,
 * carried no throttle, and did not look at whether the store had switched Telegram (or WhatsApp) on at all - a store that merely HAD a bot token
 * saved was a relay for anyone, repeatable without limit because the guest arm does not consume the cart (READ in the P1-SEC-17 review; the
 * premise - one anonymous call, one message - is StorefrontCredentialDisclosureTest's).
 *
 * Now: (1) the method must be switched on for THAT store (`is_telegram_enabled` / `is_whatsapp_enabled` = 'on' - the same flag that makes the
 * checkout offer the method at all, so a real shopper never reaches a refusal); (2) the route is throttled per IP + store slug (12/min);
 * (3) an unknown slug is a 404, not a 500.
 */
class StorefrontRelayAbuseTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function store(array $settings): object
    {
        [$merchant, $store] = $this->makeTenant();

        foreach (array_merge(['telegram_access_token' => '111:FIXTURE-relay-token', 'telegram_chat_id' => '-100123', 'whatsapp_number' => '963900000000'], $settings) as $name => $value) {
            DB::table('settings')->insert(['name' => $name, 'value' => $value, 'created_by' => $merchant->id, 'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $store;
    }

    private function relay($store, string $type = 'telegram')
    {
        return $this->post('/' . $store->slug . '/get-massage', ['data' => ['type' => $type, 'order_id' => 'ORD-1']]);
    }

    public function test_a_store_that_has_not_switched_telegram_on_is_not_a_relay(): void
    {
        Http::fake();
        $store = $this->store(['is_telegram_enabled' => 'off']);

        $this->relay($store, 'telegram')->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_a_store_with_no_flag_at_all_is_not_a_relay(): void
    {
        Http::fake();
        $store = $this->store([]);

        $this->relay($store, 'telegram')->assertForbidden();
        $this->relay($store, 'whatsapp')->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_telegram_being_on_does_not_switch_whatsapp_on(): void
    {
        Http::fake();
        $store = $this->store(['is_telegram_enabled' => 'on']);

        $this->relay($store, 'whatsapp')->assertForbidden();
        $this->assertNotSame(403, $this->relay($store, 'telegram')->status(), 'Premise: the enabled method is not refused by this gate.');
    }

    public function test_an_unknown_slug_is_a_404_not_a_server_error(): void
    {
        $this->post('/no-such-store-FIXTURE/get-massage', ['data' => ['type' => 'telegram']])->assertNotFound();
    }

    public function test_the_route_is_throttled_per_address_and_store(): void
    {
        Http::fake();
        $busy = $this->store(['is_telegram_enabled' => 'on']);
        $quiet = $this->store(['is_telegram_enabled' => 'on']);
        RateLimiter::clear('storefront-relay');

        $statuses = [];

        for ($i = 0; $i < 13; $i++) {
            $statuses[] = $this->relay($busy)->status();
        }

        $this->assertNotContains(429, array_slice($statuses, 0, 12), 'The limiter fired before its limit.');
        $this->assertSame(429, $statuses[12], 'The 13th call in a minute was not throttled.');
        $this->assertNotSame(429, $this->relay($quiet)->status(), "One store's traffic throttled another store's shoppers.");
    }

    // ---- (b) an exception object is never flashed to a shopper -------------------------------

    public function test_a_failed_payment_start_flashes_a_fixed_sentence_and_logs_the_cause(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        $secret = 'FIXTURE-gateway-key-0123456789';

        $response = \App\Services\PaymentFailure::redirectBack(new \RuntimeException('Gateway refused key ' . $secret), 'midtrans');
        $flashed = $response->getSession()?->get('error') ?? session('error');

        $this->assertIsString($flashed, 'The flashed error must be a plain string, never an exception object.');
        $this->assertStringNotContainsString($secret, $flashed);
        $this->assertStringNotContainsString('RuntimeException', $flashed);
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => $context['gateway'] === 'midtrans' && $context['exception'] === \RuntimeException::class);
    }

    /** The ratchet: no handler under app/ may hand a caught exception itself to the session. */
    public function test_no_handler_flashes_a_caught_exception_object(): void
    {
        $offenders = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            foreach (file($file->getPathname()) as $n => $line) {
                if (preg_match('/->with\(\s*[\'"]error[\'"]\s*,\s*\$e\s*\)/', $line)) {
                    $offenders[] = str_replace(base_path() . '/', '', $file->getPathname()) . ':' . ($n + 1);
                }
            }
        }

        $this->assertSame([], $offenders, "These hand the exception object to the session (printed unescaped by the layouts, stack trace included):\n" . implode("\n", $offenders));
    }
}
