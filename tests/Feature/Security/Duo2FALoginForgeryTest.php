<?php

namespace Tests\Feature\Security;

use App\Models\AddOnManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * P1-SEC-35 -- `/duologin` LOGGED AN ANONYMOUS CALLER IN AS ANY USER, THE PLATFORM OPERATOR INCLUDED.
 * ============================================================================================
 *
 * Found by the P1-SEC-04 route census (READ) and EXECUTED here. The Duo2FA module's `Route::any('duologin')` sits in a `['web']`-only group and
 * is CSRF-exempt. Its handler verifies a "Duo response" and then calls `Auth::loginUsingId()` for the e-mail INSIDE that response:
 *
 *   - the response is two HMAC-SHA1 halves. The AUTH half is keyed with the secret key of the store of the user named in the REQUEST's
 *     `user_email` -- and `stores.duo_secret_key` is NULL for every store that never configured Duo, which PHP coerces to '' for hash_hmac;
 *   - the APP half is keyed with a CONSTANT committed to the repository (LaravelDuo::get_akey ignores its argument);
 *   - the two halves are only compared with EACH OTHER. Nothing compares the user they name with the user_email the request carried, with the
 *     session, or with anything the caller does not choose.
 *
 * So anyone can mint a "response" naming the victim, send `user_email` of any account whose store has no Duo secret (their own), and be logged
 * in as the victim. packages/workdo/ is vendored and cannot be edited: the control is host-side (App\Http\Middleware\Duo2FALoginGuard).
 *
 * The module is enabled through the production loader and the premise is asserted: no WorkDo module loads in a test run unless a row is
 * inserted, so a test against a module surface may never have reached it.
 */
class Duo2FALoginForgeryTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The constant LaravelDuo::get_akey() returns for every store; it is in the repository. */
    private const AKEY = 'DISYRIADY4EWCZS6HZ6VL51dadnnADBSAFMKONAOIHKONSIONOKMSOIJHIFSNKVNNIONIONIOSFNION';

    private function enableDuo(): void
    {
        AddOnManager::create([
            'module' => 'Duo2FA', 'name' => 'Duo2FA',
            'monthly_price' => 0, 'yearly_price' => 0,
            'is_enable' => 1, 'package_name' => 'duo-2fa',
        ]);

        Cache::flush();

        $provider = new \App\Providers\PackageServiceProvider($this->app);
        $loadAddons = new \ReflectionMethod($provider, 'loadAddons');
        $loadAddons->setAccessible(true);
        $loadAddons->invoke($provider, ['Duo2FA']);

        foreach (glob(base_path('packages/workdo/Duo2FA/src/database/migrations/*.php')) ?: [] as $file) {
            (require $file)->up();
        }

        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->app['router']->getRoutes()->refreshActionLookups();
    }

    /** What an attacker builds with nothing but the committed constant and an empty key. */
    private function forgedResponse(string $victimEmail, string $authKey = '', int $ttl = 3000, string $noise = ''): string
    {
        $exp = time() + $ttl;
        $vals = base64_encode($victimEmail . '|' . str_repeat('A', 20) . '|' . $exp) . $noise;

        $auth = 'AUTH|' . $vals;
        $authSig = $auth . '|' . hash_hmac('sha1', $auth, $authKey);

        $app = 'APP|' . $vals;
        $appSig = $app . '|' . hash_hmac('sha1', $app, self::AKEY);

        return $authSig . ':' . $appSig;
    }

    private function victimAndAttacker(): array
    {
        $operator = $this->makePlatformAdmin(['email' => 'operator-FIXTURE@example.test']);
        [$attacker, $attackerStore] = $this->makeTenant();

        return [$operator, $attacker, $attackerStore];
    }

    public function test_premise_the_duo_module_is_loaded_and_the_route_is_reachable_without_a_session(): void
    {
        $this->enableDuo();

        $this->assertTrue(Route::has('duologin'), 'Premise: the module is not loaded, so this file would test nothing.');
        $this->assertStringEndsWith('Duo2FAController@duologin', Route::getRoutes()->getByName('duologin')->getActionName(), 'The guard matches this ACTION; if the module renames it the guard silently stops covering the login.');
        $this->assertTrue(Schema::hasColumn('stores', 'duo_secret_key'));
        $this->assertTrue(Schema::hasColumn('users', 'is_duo_loggedin'));
    }

    public function test_an_anonymous_forged_response_does_not_log_the_caller_in_as_the_victim(): void
    {
        $this->enableDuo();
        [$operator, $attacker] = $this->victimAndAttacker();

        $response = $this->post('/duologin', ['user_email' => $attacker->email, 'sig_response' => $this->forgedResponse($operator->email)]);

        $response->assertRedirect(route('login'));   // a refusal, not a 500 that happens to leave the caller a guest
        $this->assertGuest('web');
        $this->assertNull(session('is_login'), 'The forged response left a login marker in the session.');
        $this->assertSame(0, (int) DB::table('users')->where('id', $operator->id)->value('is_duo_loggedin'), 'The victim was marked as having passed Duo.');
    }

    public function test_an_anonymous_forged_response_naming_an_ordinary_merchant_is_refused_too(): void
    {
        $this->enableDuo();
        [$victim] = $this->makeTenant();
        [$attacker] = $this->makeTenant();

        $this->post('/duologin', ['user_email' => $attacker->email, 'sig_response' => $this->forgedResponse($victim->email)])->assertRedirect(route('login'));

        $this->assertGuest('web');
    }

    public function test_a_signed_in_user_cannot_use_a_forged_response_to_become_someone_else(): void
    {
        $this->enableDuo();
        [$operator, $attacker] = $this->victimAndAttacker();

        $this->actingAs($attacker->fresh())->post('/duologin', ['user_email' => $attacker->email, 'sig_response' => $this->forgedResponse($operator->email)]);

        $this->assertAuthenticatedAs($attacker->fresh(), 'web');
    }

    public function test_the_response_must_name_the_user_the_request_names(): void
    {
        $this->enableDuo();
        [$operator, $attacker] = $this->victimAndAttacker();

        // signed in as the attacker, with a "response" for the operator and user_email = operator: the pair agree with each other but not with the session
        $this->actingAs($attacker->fresh())->post('/duologin', ['user_email' => $operator->email, 'sig_response' => $this->forgedResponse($operator->email)]);

        $this->assertAuthenticatedAs($attacker->fresh(), 'web');
    }

    public function test_a_malformed_response_is_a_refusal_not_a_server_error(): void
    {
        $this->enableDuo();
        [, $attacker] = $this->victimAndAttacker();

        foreach (['', 'x', 'a:b', 'AUTH|x:APP|y', str_repeat('A', 5000)] as $garbage) {
            $response = $this->actingAs($attacker->fresh())->post('/duologin', ['user_email' => $attacker->email, 'sig_response' => $garbage]);

            $this->assertLessThan(500, $response->status(), 'A malformed sig_response answered a server error: ' . substr($garbage, 0, 20));
        }
    }

    public function test_an_unknown_email_is_a_refusal_not_a_server_error(): void
    {
        $this->enableDuo();

        $response = $this->post('/duologin', ['user_email' => 'nobody-FIXTURE@example.test', 'sig_response' => $this->forgedResponse('nobody-FIXTURE@example.test')]);

        $response->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    public function test_the_legitimate_flow_still_logs_the_signed_in_user_in(): void
    {
        $this->enableDuo();
        [$merchant, $store] = $this->makeTenant();
        $secret = str_repeat('s', 40);
        // the store has Duo configured: the AUTH half is genuinely signed with its secret key, as Duo's own service signs it
        DB::table('stores')->where('id', $store->id)->update(['duo_secret_key' => $secret, 'duo_integration_key' => str_repeat('I', 20)]);
        (new \App\Models\Store())->flushCache();
        (new \App\Models\User())->flushCache();

        $response = $this->actingAs($merchant->fresh())->post('/duologin', [
            'user_email' => $merchant->email,
            'sig_response' => $this->forgedResponse($merchant->email, $secret),
        ]);

        $this->assertLessThan(400, $response->status(), 'Premise: the legitimate (signed-in, self-naming) flow is not refused.');
        $this->assertAuthenticatedAs($merchant->fresh(), 'web');
        $this->assertSame(1, (int) DB::table('users')->where('id', $merchant->id)->value('is_duo_loggedin'), 'the handler still records the Duo pass for the user it logs in');
    }

    /** A response is genuinely signed only if the store HAS a secret key; with none, the AUTH half is a signature anyone can make -- even for themselves. */
    public function test_a_store_without_duo_keys_cannot_produce_a_valid_response_even_for_its_own_user(): void
    {
        $this->enableDuo();
        [$merchant] = $this->makeTenant();

        $response = $this->actingAs($merchant->fresh())->post('/duologin', ['user_email' => $merchant->email, 'sig_response' => $this->forgedResponse($merchant->email)]);

        $response->assertRedirect(route('duo2fa.form'));
        $this->assertNull(session('is_login'), 'A response signed with the empty key was accepted.');
        $this->assertSame(0, (int) DB::table('users')->where('id', $merchant->id)->value('is_duo_loggedin'));
    }

    public function test_a_store_with_only_one_of_the_two_keys_is_refused_too(): void
    {
        $this->enableDuo();

        foreach ([['duo_integration_key' => str_repeat('I', 20), 'duo_secret_key' => ''], ['duo_integration_key' => '', 'duo_secret_key' => str_repeat('s', 40)]] as $keys) {
            [$merchant, $store] = $this->makeTenant();
            DB::table('stores')->where('id', $store->id)->update($keys);
            (new \App\Models\Store())->flushCache();

            $response = $this->actingAs($merchant->fresh())->post('/duologin', ['user_email' => $merchant->email, 'sig_response' => $this->forgedResponse($merchant->email, (string) $keys['duo_secret_key'])]);

            $response->assertRedirect(route('duo2fa.form'));
            $this->assertSame(0, (int) DB::table('users')->where('id', $merchant->id)->value('is_duo_loggedin'));
        }
    }

    public function test_the_request_must_carry_the_signed_in_users_own_email(): void
    {
        $this->enableDuo();
        [$merchant, $store] = $this->makeTenant();
        [$other] = $this->makeTenant();
        $secret = str_repeat('s', 40);
        DB::table('stores')->where('id', $store->id)->update(['duo_secret_key' => $secret, 'duo_integration_key' => str_repeat('I', 20)]);
        (new \App\Models\Store())->flushCache();

        $response = $this->actingAs($merchant->fresh())->post('/duologin', ['user_email' => $other->email, 'sig_response' => $this->forgedResponse($merchant->email, $secret)]);

        $response->assertRedirect(route('duo2fa.form'));
        $this->assertSame(0, (int) DB::table('users')->where('id', $merchant->id)->value('is_duo_loggedin'));
    }

    /** Each half is checked on its own: a response whose AUTH half names the user but whose APP half names somebody else (or the reverse) is refused. */
    public function test_each_half_of_the_response_must_name_the_signed_in_user(): void
    {
        $this->enableDuo();
        [$merchant, $store] = $this->makeTenant();
        [$other] = $this->makeTenant();
        $secret = str_repeat('s', 40);
        DB::table('stores')->where('id', $store->id)->update(['duo_secret_key' => $secret, 'duo_integration_key' => str_repeat('I', 20)]);
        (new \App\Models\Store())->flushCache();

        [$ownAuth, $ownApp] = explode(':', $this->forgedResponse($merchant->email, $secret));
        [$otherAuth, $otherApp] = explode(':', $this->forgedResponse($other->email, $secret));

        foreach (['AUTH half foreign' => $otherAuth . ':' . $ownApp, 'APP half foreign' => $ownAuth . ':' . $otherApp] as $label => $mixed) {
            $response = $this->actingAs($merchant->fresh())->post('/duologin', ['user_email' => $merchant->email, 'sig_response' => $mixed]);

            $response->assertRedirect(route('duo2fa.form'));
            $this->assertSame(0, (int) DB::table('users')->where('id', $merchant->id)->value('is_duo_loggedin'), $label);
        }
    }

    public function test_the_guard_leaves_every_other_route_alone(): void
    {
        $this->enableDuo();
        [$merchant] = $this->makeTenant();

        // POST /login is not the Duo route: the guard must not touch it (an anonymous, empty post is the normal validation answer, not a Duo refusal)
        $response = $this->post('/login', []);

        $this->assertNotSame(route('duo2fa.form'), $response->headers->get('Location'));
        $this->assertNull(session('error'));
    }

    /** The guard decodes what the module will decode; a payload the module would accept only because its base64_decode() is lenient is refused here. */
    public function test_a_payload_with_characters_outside_the_base64_alphabet_is_refused(): void
    {
        $this->enableDuo();
        [$merchant, $store] = $this->makeTenant();
        $secret = str_repeat('s', 40);
        DB::table('stores')->where('id', $store->id)->update(['duo_secret_key' => $secret, 'duo_integration_key' => str_repeat('I', 20)]);
        (new \App\Models\Store())->flushCache();

        $response = $this->actingAs($merchant->fresh())->post('/duologin', ['user_email' => $merchant->email, 'sig_response' => $this->forgedResponse($merchant->email, $secret, 3000, '!')]);

        $response->assertRedirect(route('duo2fa.form'));
        $this->assertSame(0, (int) DB::table('users')->where('id', $merchant->id)->value('is_duo_loggedin'));
    }

    /** No case folding: the response must name the user spelled exactly as stored. */
    public function test_a_differently_cased_name_is_not_the_signed_in_user(): void
    {
        $this->enableDuo();
        [$merchant, $store] = $this->makeTenant();
        $secret = str_repeat('s', 40);
        DB::table('stores')->where('id', $store->id)->update(['duo_secret_key' => $secret, 'duo_integration_key' => str_repeat('I', 20)]);
        (new \App\Models\Store())->flushCache();

        $response = $this->actingAs($merchant->fresh())->post('/duologin', ['user_email' => $merchant->email, 'sig_response' => $this->forgedResponse(strtoupper($merchant->email), $secret)]);

        $response->assertRedirect(route('duo2fa.form'));
        $this->assertSame(0, (int) DB::table('users')->where('id', $merchant->id)->value('is_duo_loggedin'));
    }

    private function withDuoKeys($store, string $secret): void
    {
        DB::table('stores')->where('id', $store->id)->update(['duo_secret_key' => $secret, 'duo_integration_key' => str_repeat('I', 20)]);
        (new \App\Models\Store())->flushCache();
    }

    /**
     * THE POST-FIX ATTACK: a signed-in attacker whose OWN store has Duo keys signs a response naming the OPERATOR with their own secret, and
     * sends user_email = themselves. Only the 'both halves name the signed-in user' condition refuses it (the store-keys condition is
     * satisfied), so this is the case that isolates it.
     */
    public function test_a_signed_in_attacker_with_their_own_duo_keys_cannot_name_somebody_else(): void
    {
        $this->enableDuo();
        [$operator, $attacker, $attackerStore] = $this->victimAndAttacker();
        $secret = str_repeat('s', 40);
        $this->withDuoKeys($attackerStore, $secret);

        $response = $this->actingAs($attacker->fresh())->post('/duologin', ['user_email' => $attacker->email, 'sig_response' => $this->forgedResponse($operator->email, $secret)]);

        $response->assertRedirect(route('duo2fa.form'));
        $this->assertAuthenticatedAs($attacker->fresh(), 'web');
        $this->assertSame(0, (int) DB::table('users')->where('id', $operator->id)->value('is_duo_loggedin'));
        $this->assertNull(session('is_login'));
    }

    /** The guard validates what the handler reads: arrays, a different transport, JSON, and extra halves/parts are all refusals. */
    public function test_unusual_shapes_of_the_same_request_are_refused(): void
    {
        $this->enableDuo();
        [$merchant, $store] = $this->makeTenant();
        $secret = str_repeat('s', 40);
        $this->withDuoKeys($store, $secret);
        $good = $this->forgedResponse($merchant->email, $secret);
        $this->actingAs($merchant->fresh());

        $cases = [
            'sig_response as array' => fn () => $this->post('/duologin', ['user_email' => $merchant->email, 'sig_response' => [$good]]),
            'user_email as array' => fn () => $this->post('/duologin', ['user_email' => [$merchant->email], 'sig_response' => $good]),
            'three halves' => fn () => $this->post('/duologin', ['user_email' => $merchant->email, 'sig_response' => $good . ':' . $good]),
            'four parts in a half' => fn () => $this->post('/duologin', ['user_email' => $merchant->email, 'sig_response' => $good . '|extra']),
            'trailing space in the named user' => fn () => $this->post('/duologin', ['user_email' => $merchant->email, 'sig_response' => $this->forgedResponse($merchant->email . ' ', $secret)]),
        ];

        foreach ($cases as $label => $send) {
            $response = $send();
            $this->assertSame(route('duo2fa.form'), $response->headers->get('Location'), $label);
            $this->assertSame(0, (int) DB::table('users')->where('id', $merchant->id)->value('is_duo_loggedin'), $label);
        }

        // JSON caller: a 403, never a 401 and never a login
        $this->postJson('/duologin', ['user_email' => $merchant->email, 'sig_response' => 'x'])->assertStatus(403);
    }

    public function test_a_get_request_to_the_route_is_judged_the_same_way(): void
    {
        $this->enableDuo();
        [$operator, $attacker] = $this->victimAndAttacker();

        $this->get('/duologin?' . http_build_query(['user_email' => $attacker->email, 'sig_response' => $this->forgedResponse($operator->email)]))->assertRedirect(route('login'));

        $this->assertGuest('web');
    }

    /** Request::__get reads input AND uploaded files (files win); the guard validates the value the handler reads, not a text field it would ignore. */
    public function test_a_file_part_that_shadows_a_text_field_is_judged_as_the_handler_reads_it(): void
    {
        $this->enableDuo();
        [$merchant, $store] = $this->makeTenant();
        $secret = str_repeat('s', 40);
        $this->withDuoKeys($store, $secret);
        $good = $this->forgedResponse($merchant->email, $secret);
        $this->actingAs($merchant->fresh());

        foreach (['sig_response', 'user_email'] as $shadowed) {
            $response = $this->call('POST', '/duologin', ['user_email' => $merchant->email, 'sig_response' => $good], [], [$shadowed => \Illuminate\Http\UploadedFile::fake()->create('x.txt', 1)]);

            $this->assertSame(route('duo2fa.form'), $response->headers->get('Location'), "a file named {$shadowed} reached the handler");
            $this->assertSame(0, (int) DB::table('users')->where('id', $merchant->id)->value('is_duo_loggedin'));
        }
    }
}
