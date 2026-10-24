<?php

namespace Tests\Feature\Security;

use App\Models\AddOnManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/../../Support/Midtrans/MT_Tests.php';

/**
 * P1-SEC-17 -- the three vendored copies of what StorefrontCredentialDisclosureTest and
 * MidtransServerKeyDisclosureTest fix in the host.
 * =============================================================================================
 *
 * `packages/workdo/` cannot be edited (a CI guard refuses it), so these are closed from the host:
 *
 *  - TELEGRAM. Donation and LotteryPrizes each carry a `getWhatsappUrl()` that RETURNS
 *    `https://api.telegram.org/bot<TOKEN>/sendMessage` to an anonymous caller (they never even post
 *    the message -- the URL is built and handed out). Closed by a response guard on the `web` group
 *    (App\Http\Middleware\WithholdTelegramBotUrl), the same vehicle as BulkDeleteTenantGuard,
 *    because the module routes are registered from `booted()` and a host re-declaration would lose
 *    the method+uri slot.
 *  - MIDTRANS. Campaigns, Donation and LotteryPrizes each render `$data['midtrans_secret']` -- the
 *    merchant's SERVER key -- as `data-client-key` and, through `route($fallback_url, $data)`, into
 *    the form action. Closed by Laravel's package-view override
 *    (`resources/views/vendor/<namespace>/payment/midtrans.blade.php`), which `loadViewsFrom()`
 *    consults before the module's own directory.
 *
 * EVERY MODULE IS ENABLED THROUGH THE PRODUCTION LOADER (PackageServiceProvider::loadAddons), and
 * the premise tests assert it really is active: no WorkDo module loads in a test run unless a row
 * is inserted, so a test that "passes" against a module surface may never have reached it.
 *
 * Sentinels carry `FIXTURE` so the CI secret scan's generic-assigned-secret rule exempts them.
 */
class ModuleCredentialDisclosureTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const BOT_TOKEN = '888000222:FIXTURE-module-telegram-token-AAHsentinel0002';

    private const SERVER_KEY = 'SB-Mid-server-FIXTURE-module-0003';

    /** module => [package_name, view namespace] */
    private const MODULES = [
        'LotteryPrizes' => ['lottery-prizes', 'lottery-prizes'],
        'Donation' => ['donation', 'donation'],
        'Campaigns' => ['campaigns', 'campaigns'],
    ];

    protected function tearDown(): void
    {
        \Midtrans\MT_Tests::reset();

        parent::tearDown();
    }

    private function enable(string $module): void
    {
        [$package] = self::MODULES[$module];

        AddOnManager::create([
            'module' => $module, 'name' => $module,
            'monthly_price' => 0, 'yearly_price' => 0,
            'is_enable' => 1, 'package_name' => $package,
        ]);

        Cache::flush();

        $provider = new \App\Providers\PackageServiceProvider($this->app);
        $loadAddons = new \ReflectionMethod($provider, 'loadAddons');
        $loadAddons->setAccessible(true);
        $loadAddons->invoke($provider, [$module]);
    }

    /** @return \App\Models\Store a store whose merchant has configured a Telegram bot */
    private function storeWithTelegram()
    {
        [$merchant, $store] = $this->makeTenant();

        foreach (['telegram_access_token' => self::BOT_TOKEN, 'telegram_chat_id' => '-100987654321'] as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => $value,
                'created_by' => $merchant->id, 'store_id' => $store->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $store;
    }

    // ---------------------------------------------------------------------------------------------
    // Telegram
    // ---------------------------------------------------------------------------------------------

    public function test_premise_the_module_routes_exist_once_the_modules_are_enabled(): void
    {
        $this->assertFalse(Route::has('ticket.get.whatsappurl'), 'Premise: the module must not be loaded before it is enabled.');

        $this->enable('LotteryPrizes');
        $this->enable('Donation');

        $this->assertTrue(Route::has('ticket.get.whatsappurl'), 'LotteryPrizes did not load through the production loader.');
        $this->assertTrue(Route::has('donation.get.whatsappurl'), 'Donation did not load through the production loader.');
    }

    public function test_lottery_prizes_does_not_hand_the_bot_token_to_an_anonymous_caller(): void
    {
        $this->enable('LotteryPrizes');
        $store = $this->storeWithTelegram();

        $response = $this->withSession(['lottery_request_data' => [
            'payment_methods' => 'telegram',
            'lottery_info' => ['lottery_user_telephone' => '123'],
        ]])->post('/' . $store->slug . '/ticket-get-massage', ['data' => ['type' => 'telegram']]);

        $response->assertOk();
        $this->assertSame('', (string) $response->getContent(),
            'The body must be emptied, not merely free of the token (a redirect or a 404 would pass a weaker check).');
        $this->assertStringNotContainsString(self::BOT_TOKEN, json_encode($response->headers->all()));
    }

    public function test_donation_does_not_hand_the_bot_token_to_an_anonymous_caller(): void
    {
        $this->enable('Donation');
        $store = $this->storeWithTelegram();

        $response = $this->withSession(['donation_request_data' => [
            'donationpayment_method' => 'Telegram',
            'number' => '123',
        ]])->post('/' . $store->slug . '/donation-get-massage', ['data' => ['type' => 'telegram']]);

        $response->assertOk();
        $this->assertSame('', (string) $response->getContent(),
            'The body must be emptied, not merely free of the token (a redirect or a 404 would pass a weaker check).');
        $this->assertStringNotContainsString(self::BOT_TOKEN, json_encode($response->headers->all()));
    }

    /** The WhatsApp branch of the same handlers returns a link the page opens; it must be untouched. */
    public function test_the_modules_whatsapp_link_is_still_returned(): void
    {
        $this->enable('LotteryPrizes');
        $store = $this->storeWithTelegram();

        $response = $this->withSession(['lottery_request_data' => [
            'payment_methods' => 'whatsapp',
            'lottery_info' => ['lottery_user_telephone' => '963911111111'],
        ]])->post('/' . $store->slug . '/ticket-get-massage', ['data' => ['type' => 'whatsapp']]);

        $response->assertOk();
        $this->assertStringStartsWith('https://api.whatsapp.com/send?phone=963911111111', (string) $response->getContent());
    }

    // ---------------------------------------------------------------------------------------------
    // Midtrans -- the module views
    // ---------------------------------------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function moduleViews(): array
    {
        return [
            'LotteryPrizes' => ['LotteryPrizes'],
            'Donation' => ['Donation'],
            'Campaigns' => ['Campaigns'],
        ];
    }

    private function midtransData(string $fallbackRoute): array
    {
        return [
            'snap_token' => 'FIXTURE-snap-token-0003',
            'midtrans_secret' => self::SERVER_KEY,
            'order_id' => 'ORD-9',
            'slug' => 'some-store',
            'amount' => 100,
            'fallback_url' => $fallbackRoute,
        ];
    }

    #[DataProvider('moduleViews')]
    public function test_the_modules_midtrans_view_does_not_render_the_server_key(string $module): void
    {
        $this->enable($module);
        [, $namespace] = self::MODULES[$module];

        $view = $namespace . '::payment.midtrans';

        $this->assertTrue(view()->exists($view), "Premise: {$view} is not resolvable, so the module is not active.");

        // Any named GET/POST route will do for `route($data['fallback_url'], $data)`; use one that exists in every module.
        $fallback = 'login';
        $html = view($view, ['data' => $this->midtransData($fallback)])->render();

        $this->assertStringContainsString("window.snap.pay('FIXTURE-snap-token-0003'", $html, 'Premise: the Snap page was not rendered.');
        $this->assertStringNotContainsString(self::SERVER_KEY, $html, "{$module}: the Midtrans server key was rendered.");
        $this->assertStringNotContainsString(rawurlencode(self::SERVER_KEY), $html, "{$module}: the server key was rendered URL-encoded.");
        $this->assertStringNotContainsString('data-client-key', $html);
    }

    /**
     * THE OVERRIDE IS THE MODULE'S VIEW WITH EXACTLY TWO CHANGES. A host override that silently
     * freezes a vendored page is a maintenance trap: when the module's view changes upstream this
     * fails, and the failure is the instruction to re-derive the override.
     */
    #[DataProvider('moduleViews')]
    public function test_the_override_is_the_modules_view_with_only_those_two_changes(string $module): void
    {
        [, $namespace] = self::MODULES[$module];

        $original = file_get_contents(base_path("packages/workdo/{$module}/src/resources/views/payment/midtrans.blade.php"));
        $override = file_get_contents(base_path("resources/views/vendor/{$namespace}/payment/midtrans.blade.php"));

        // Strip the override's leading explanation.
        $override = preg_replace('/\A\{\{--.*?--\}\}\n/s', '', $override, 1);

        $expected = str_replace(
            [
                "    <!-- @TODO: replace SET_YOUR_CLIENT_KEY_HERE with your client key -->\n    <script type=\"text/javascript\"\n      src=\"https://app.sandbox.midtrans.com/snap/snap.js\"\n      data-client-key=\"{{ \$data['midtrans_secret'] }}\"></script>\n",
                "route(\$data['fallback_url'],\$data)",
            ],
            [
                "    <script type=\"text/javascript\"\n      src=\"https://app.sandbox.midtrans.com/snap/snap.js\"></script>\n",
                "route(\$data['fallback_url'], \\Illuminate\\Support\\Arr::except(\$data, ['midtrans_secret']))",
            ],
            $original
        );

        $this->assertNotSame($original, $expected, 'Premise: the module view no longer contains the lines this override replaces.');
        $this->assertSame($expected, $override,
            "The {$module} Midtrans view changed upstream (or the override drifted). Re-derive resources/views/vendor/{$namespace}/payment/midtrans.blade.php.");
    }

    // ---------------------------------------------------------------------------------------------
    // The response guard itself
    // ---------------------------------------------------------------------------------------------

    public function test_the_guard_empties_a_bare_telegram_bot_url_and_nothing_else(): void
    {
        $guard = new \App\Http\Middleware\WithholdTelegramBotUrl();
        $request = \Illuminate\Http\Request::create('/x', 'POST');

        $leaky = $guard->handle($request, fn () => new \Illuminate\Http\Response('https://api.telegram.org/bot1:FIXTURE-guard-token/sendMessage'));
        $this->assertSame('', $leaky->getContent(), 'A bare bot URL was not emptied.');

        $padded = $guard->handle($request, fn () => new \Illuminate\Http\Response("  \nHTTPS://API.TELEGRAM.ORG/bot1:FIXTURE-guard-token/sendMessage"));
        $this->assertSame('', $padded->getContent(), 'Whitespace/case must not slip a bot URL past the guard.');

        foreach ([
            'a WhatsApp link' => 'https://api.whatsapp.com/send?phone=1&text=hi',
            'an HTML page that mentions Telegram' => '<html><body>Contact us at https://api.telegram.org/bot is not a link</body></html>',
            'an empty body' => '',
        ] as $label => $body) {
            $kept = $guard->handle($request, fn () => new \Illuminate\Http\Response($body));
            $this->assertSame($body, $kept->getContent(), "The guard changed {$label}.");
        }

        $json = $guard->handle($request, fn () => response()->json(['url' => 'https://api.telegram.org/bot1:x/sendMessage']));
        $this->assertStringContainsString('api.telegram.org', $json->getContent(), 'The guard must leave JSON responses alone (it is a rule about bare URL bodies).');
    }

    public function test_the_guard_is_on_the_web_group_so_module_routes_reach_it(): void
    {
        $this->enable('LotteryPrizes');

        $route = Route::getRoutes()->getByName('ticket.get.whatsappurl');
        $this->assertNotNull($route, 'Premise: the module route is registered.');

        $stack = $this->app->make(\Illuminate\Routing\Router::class)->gatherRouteMiddleware($route);

        $this->assertContains(\App\Http\Middleware\WithholdTelegramBotUrl::class, $stack,
            'The module route does not pass through the guard.');
    }
}
