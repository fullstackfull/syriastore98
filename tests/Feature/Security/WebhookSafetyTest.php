<?php

namespace Tests\Feature\Security;

use App\Models\Utility;
use App\Models\Webhook;
use App\Services\SafeRemoteFetch;
use App\Services\SafeWebhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * P1-SEC-33 -- THE WEBHOOK SENDER WAS A WRITE-CAPABLE SERVER-SIDE REQUEST FORGERY, AND THE WEBHOOK SETTINGS WERE EDITABLE ACROSS TENANTS.
 * =========================================================================================================================================
 *
 * Found by the read-only review of the P1-SEC-06 fix, which asked "what other sink takes a URL a merchant typed?" and EXECUTED the answer.
 *
 * `Utility::WebhookCall($url, $parameter, $method)` -- called when an order is placed, a product is created, a user is added ... -- did
 *     $h = curl_init($url);  CURLOPT_POSTFIELDS => $parameter;  CURLOPT_CUSTOMREQUEST => $method;  curl_exec($h);
 * with the URL and the method straight from the `webhooks` row. cURL speaks gopher, dict, ftp, file, smtp, telnet ... so
 * `gopher://127.0.0.1:6379/_<RESP commands>` is a raw TCP write to a Redis on localhost; a method containing CRLF injects request lines; there
 * was no timeout. `WebhookController::store` validates `webbbook_url` as `required` and nothing else, and checks no permission.
 *
 * AND `update($id)` / `destroy($id)` addressed the row by a bare id: `Webhook::where('id',$id)->update([... 'store_id' => <caller's>])`
 * MOVED another tenant's webhook into the caller's store, and `Webhook::find($id)->delete()` deleted any tenant's for any `admin`-type user.
 * Neither model nor query is store-scoped (Webhook is not in config/tenancy.php `scoped_models`).
 *
 * ORACLE for the SSRF tests: a real TCP listener on 127.0.0.1. The leak IS "the server connected", so the assertion is that the listener
 * accepts nothing; a positive control proves the listener would have seen a connection.
 */
class WebhookSafetyTest extends SecurityTestCase
{
    use RefreshDatabase;

    private $listener;
    private int $port;

    protected function setUp(): void
    {
        parent::setUp();

        $this->listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->port = (int) substr(strrchr((string) stream_socket_get_name($this->listener, false), ':'), 1);
        stream_set_blocking($this->listener, false);

        SafeRemoteFetch::resolveUsing(fn (string $host) => match ($host) {
            'hooks.example.test' => ['93.184.216.34'],
            'private.example.test' => ['10.0.0.5'],
            default => [],
        });
    }

    protected function tearDown(): void
    {
        SafeRemoteFetch::resolveUsing(null);
        SafeWebhook::transportUsing(null);

        if (is_resource($this->listener)) {
            fclose($this->listener);
        }

        parent::tearDown();
    }

    /** Did anything connect to the listener within $seconds? */
    private function connections(float $seconds = 0.4): int
    {
        $count = 0;

        while (($client = @stream_socket_accept($this->listener, $seconds)) !== false) {
            $count++;
            fclose($client);
            $seconds = 0.05;
        }

        return $count;
    }

    // =========================================================================================
    //  PREMISE: the listener would see a connection
    // =========================================================================================

    public function test_the_listener_sees_a_connection_made_to_it(): void
    {
        $socket = stream_socket_client("tcp://127.0.0.1:{$this->port}", $errno, $errstr, 2);
        $this->assertNotFalse($socket, 'Premise: the loopback listener must be reachable, or "nothing connected" proves nothing.');

        $this->assertSame(1, $this->connections());
        fclose($socket);
    }

    // =========================================================================================
    //  THE SENDER
    // =========================================================================================

    /** @return array<string, array{0: string}> */
    public static function hostileUrls(): array
    {
        return array_map(fn ($u) => [$u], [
            'gopher' => 'gopher://127.0.0.1:{PORT}/_%2A1%0D%0A%244%0D%0AFLUSHALL%0D%0A',
            'dict' => 'dict://127.0.0.1:{PORT}/info',
            'loopback http' => 'http://127.0.0.1:{PORT}/hook',
            'localhost' => 'http://localhost:{PORT}/hook',
            'loopback v6' => 'http://[::1]:{PORT}/hook',
            'decimal loopback' => 'http://2130706433:{PORT}/hook',
            'metadata' => 'http://169.254.169.254/latest/meta-data/',
            'private name' => 'http://private.example.test/hook',
            'file' => 'file:///etc/hostname',
            'ftp' => 'ftp://127.0.0.1:{PORT}/x',
            'user info' => 'http://user:pw@127.0.0.1:{PORT}/x',
            'not resolving' => 'http://nowhere.example.test/hook',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostileUrls')]
    public function test_a_webhook_aimed_at_an_inward_or_non_http_address_connects_to_nothing(string $url): void
    {
        $url = str_replace('{PORT}', (string) $this->port, $url);

        $result = Utility::WebhookCall($url, '{"order":1}', 'POST');

        $this->assertFalse($result, "{$url}: a refused webhook is reported as failed");
        $this->assertSame(0, $this->connections(), "{$url}: the server connected to the listener.");
    }

    public static function hostileMethods(): array
    {
        return array_map(fn ($m) => [$m], ["POST /x HTTP/1.1\r\nX-Injected: 1\r\n\r\nGET", "POST\r\nX: y", 'PROPFIND', 'TRACE', 'PUT', 'PATCH', 'DELETE', '', 'post; rm', "\x00"]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostileMethods')]
    public function test_a_method_that_is_not_get_or_post_sends_nothing(string $method): void
    {
        $calls = 0;
        SafeWebhook::transportUsing(function () use (&$calls) {
            $calls++;

            return '';
        });

        $this->assertFalse(Utility::WebhookCall('https://hooks.example.test/x', '{"a":1}', $method));
        $this->assertSame(0, $calls, 'A request was made with method ' . json_encode($method));
    }

    public function test_an_ordinary_public_webhook_is_sent_pinned_and_limited_to_http(): void
    {
        $seen = [];
        SafeWebhook::transportUsing(function (string $url, array $options) use (&$seen) {
            $seen[] = [$url, $options];

            return '';
        });

        $result = Utility::WebhookCall('https://hooks.example.test:5678/in/abc?k=v', '{"order":1}', 'post');

        $this->assertTrue($result, 'an empty response is the module\'s "delivered" (unchanged semantics)');
        $this->assertCount(1, $seen);
        [$url, $options] = $seen[0];

        $this->assertSame('https://hooks.example.test:5678/in/abc?k=v', $url);
        $this->assertSame(['hooks.example.test:5678:93.184.216.34'], $options[CURLOPT_RESOLVE], 'the connection is pinned to the address that was judged');
        $this->assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $options[CURLOPT_PROTOCOLS], 'only http(s), whatever the URL says');
        $this->assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $options[CURLOPT_REDIR_PROTOCOLS]);
        $this->assertFalse($options[CURLOPT_FOLLOWLOCATION], 'a redirect is not followed to an address nobody judged');
        $this->assertSame('POST', $options[CURLOPT_CUSTOMREQUEST]);
        $this->assertSame('{"order":1}', $options[CURLOPT_POSTFIELDS]);
        $this->assertTrue($options[CURLOPT_RETURNTRANSFER]);
        $this->assertGreaterThan(0, $options[CURLOPT_TIMEOUT], 'a tarpit cannot hold a worker forever');
        $this->assertLessThanOrEqual(30, $options[CURLOPT_TIMEOUT]);
        $this->assertGreaterThan(0, $options[CURLOPT_CONNECTTIMEOUT]);
    }

    public function test_a_trailing_dot_or_capitals_do_not_escape_the_pin(): void
    {
        $seen = [];
        SafeWebhook::transportUsing(function (string $url, array $options) use (&$seen) {
            $seen[] = [$url, $options[CURLOPT_RESOLVE]];

            return '';
        });

        Utility::WebhookCall('https://HOOKS.example.test./x', '{"a":1}', 'GET');

        $this->assertSame([['https://hooks.example.test/x', ['hooks.example.test:443:93.184.216.34']]], $seen);
    }

    public function test_the_modules_return_value_semantics_are_unchanged(): void
    {
        SafeWebhook::transportUsing(fn () => 'a body came back');
        $this->assertFalse(Utility::WebhookCall('https://hooks.example.test/x', '{"a":1}', 'POST'), 'a non-empty answer is "Webhook call failed."');

        SafeWebhook::transportUsing(fn () => false);
        $this->assertTrue(Utility::WebhookCall('https://hooks.example.test/x', '{"a":1}', 'POST'), 'curl_exec() false is empty(), as it always was');

        $this->assertFalse(Utility::WebhookCall(null, '{"a":1}', 'POST'));
        $this->assertFalse(Utility::WebhookCall('https://hooks.example.test/x', null, 'POST'));
    }

    // =========================================================================================
    //  THE SETTINGS
    // =========================================================================================

    private function webhookFor($store, string $module = 'New Order', string $url = 'https://hooks.example.test/mine'): Webhook
    {
        $webhook = new Webhook();
        $webhook->module = $module;
        $webhook->url = $url;
        $webhook->method = 'POST';
        $webhook->store_id = $store->id;
        $webhook->save();

        return $webhook;
    }

    public static function refusedUrlsAtSave(): array
    {
        return array_map(fn ($u) => [$u], ['gopher://127.0.0.1:6379/_x', 'http://127.0.0.1/hook', 'http://169.254.169.254/', 'file:///etc/passwd', 'not a url',
            'http://private.example.test/hook', 'ftp://hooks.example.test/x']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedUrlsAtSave')]
    public function test_the_settings_form_refuses_an_address_the_sender_would_refuse(string $url): void
    {
        [$merchant, $store] = $this->makeTenant();

        $response = $this->actingAs($merchant)->post('/webhook', ['module' => 'New Order', 'method' => 'POST', 'webbbook_url' => $url]);

        $response->assertSessionHas('error');
        $this->assertSame(0, Webhook::where('store_id', $store->id)->count(), "{$url} was saved as a webhook.");
    }

    public function test_the_settings_form_refuses_a_method_other_than_get_or_post(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $response = $this->actingAs($merchant)->post('/webhook', ['module' => 'New Order', 'method' => "POST\r\nX: y", 'webbbook_url' => 'https://hooks.example.test/x']);

        $response->assertSessionHas('error');
        $this->assertSame(0, Webhook::where('store_id', $store->id)->count());
    }

    public function test_the_settings_form_still_saves_a_public_webhook(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $response = $this->actingAs($merchant)->post('/webhook', ['module' => 'New Order', 'method' => 'POST', 'webbbook_url' => 'https://hooks.example.test:5678/in/abc']);

        $response->assertSessionHas('success');
        $this->assertSame('https://hooks.example.test:5678/in/abc', Webhook::where('store_id', $store->id)->value('url'));
    }

    public function test_an_update_cannot_reach_another_tenants_webhook_or_move_it(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $victim = $this->webhookFor($storeB, 'New Order', 'https://hooks.example.test/victim');

        $response = $this->actingAs($merchantA)->put('/webhook/' . $victim->id, ['module' => 'Attacker Module', 'method' => 'GET', 'webbbook_url' => 'https://hooks.example.test/attacker']);

        $response->assertSessionHas('error');
        $row = DB::table('webhooks')->where('id', $victim->id)->first();
        $this->assertSame((int) $storeB->id, (int) $row->store_id, 'the row was moved into the caller\'s store');
        $this->assertSame('https://hooks.example.test/victim', $row->url, 'the row was overwritten');
        $this->assertSame('New Order', $row->module);
        $this->assertSame(0, Webhook::where('store_id', $storeA->id)->count());
    }

    public function test_an_update_changes_the_callers_own_webhook(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $own = $this->webhookFor($store);

        $response = $this->actingAs($merchant)->put('/webhook/' . $own->id, ['module' => 'New Order', 'method' => 'GET', 'webbbook_url' => 'https://hooks.example.test/new']);

        $response->assertSessionHas('success');
        $row = DB::table('webhooks')->where('id', $own->id)->first();
        $this->assertSame('https://hooks.example.test/new', $row->url);
        $this->assertSame('GET', $row->method);
        $this->assertSame((int) $store->id, (int) $row->store_id);
    }

    /** On MySQL update() reports CHANGED rows, so a handler that treated 0 as "not found" would refuse an unchanged re-save. */
    public function test_saving_a_webhook_unchanged_is_not_a_miss(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $own = $this->webhookFor($store);

        $response = $this->actingAs($merchant)->put('/webhook/' . $own->id, ['module' => 'New Order', 'method' => 'POST', 'webbbook_url' => 'https://hooks.example.test/mine']);

        $response->assertSessionHas('success');
    }

    public function test_an_update_with_an_inward_address_changes_nothing(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $own = $this->webhookFor($store);

        $response = $this->actingAs($merchant)->put('/webhook/' . $own->id, ['module' => 'New Order', 'method' => 'POST', 'webbbook_url' => 'gopher://127.0.0.1:6379/_x']);

        $response->assertSessionHas('error');
        $this->assertSame('https://hooks.example.test/mine', DB::table('webhooks')->where('id', $own->id)->value('url'));
    }

    public function test_a_delete_cannot_reach_another_tenants_webhook(): void
    {
        [$merchantA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $victim = $this->webhookFor($storeB);

        $this->assertSame('admin', $merchantA->type, 'Premise: a self-registered merchant is of type admin, which is all destroy() asked.');

        $response = $this->actingAs($merchantA)->delete('/webhook/' . $victim->id);

        $response->assertSessionHas('error');
        $this->assertSame(1, DB::table('webhooks')->where('id', $victim->id)->count(), 'another tenant\'s webhook was deleted');
    }

    public function test_a_delete_removes_the_callers_own_webhook(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $own = $this->webhookFor($store);

        $response = $this->actingAs($merchant)->delete('/webhook/' . $own->id);

        $response->assertSessionHas('success');
        $this->assertSame(0, DB::table('webhooks')->where('id', $own->id)->count());
    }

    // =========================================================================================
    //  CENSUS
    // =========================================================================================

    /** No code reaches cURL with a webhook's URL except through SafeWebhook. */
    public function test_the_only_webhook_sender_is_the_guarded_one(): void
    {
        $utility = (string) file_get_contents(app_path('Models/Utility.php'));
        $start = strpos($utility, 'public static function WebhookCall');
        $this->assertNotFalse($start);
        $body = substr($utility, $start, strpos($utility, 'public static function paymentWebhook') - $start);

        $this->assertStringContainsString('SafeWebhook::send(', $body);
        $this->assertStringNotContainsString('curl_init', $body, 'WebhookCall opens cURL itself again.');
        $this->assertStringNotContainsString('curl_exec', $body);
    }
}
