<?php

namespace Tests\Feature\Lynomia;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Services\AccountProvisioner;
use Workdo\LynomiaChat\app\Services\CommerceCredential;

/**
 * P3A. The commerce credential and the store boundary it draws.
 *
 * The property under test is narrow and load-bearing: a commerce request's tenant comes from the
 * credential and from nothing else. Store Syria's existing admin API cannot provide that -- it
 * tenants through getCurrentStore() -> users.current_store, a mutable column written by the
 * store switcher and shared across a person's sessions and devices -- so these tests exist to
 * pin the replacement rather than to restate it.
 */
class LynomiaCommerceApiTest extends TestCase
{
    use InteractsWithLynomia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLynomiaHarness();
    }

    /** @return array{0: \App\Models\Store, 1: LynomiaStoreLink, 2: string} */
    private function connectedStore(int $accountId = 7001): array
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia(['account_id' => $accountId]);
        $link = app(AccountProvisioner::class)->provision($store, $owner)->fresh();
        $link->forceFill(['commerce_credential_version' => 1])->save();

        return [$store, $link->fresh(), CommerceCredential::issue($link->fresh())];
    }

    private function callStore(?string $token): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/lynomia/commerce/v1/store',
            $token === null ? [] : ['Authorization' => 'Bearer ' . $token]);
    }

    // ---------------------------------------------------------------- the credential

    public function test_a_valid_credential_identifies_its_own_store(): void
    {
        [$store, , $token] = $this->connectedStore();

        $response = $this->callStore($token);

        $response->assertOk();
        $response->assertJsonPath('name', $store->name);
        $response->assertJsonPath('contract_version', 'v1');
        $this->assertStringEndsWith(':' . $store->id, $response->json('external_store_id'));
    }

    public function test_an_anonymous_request_is_refused(): void
    {
        $this->connectedStore();

        $this->callStore(null)->assertStatus(401);
    }

    public function test_a_garbage_or_truncated_credential_is_refused(): void
    {
        [, , $token] = $this->connectedStore();

        foreach (['', 'nonsense', 'lsc_', 'lsc_1_1_', substr($token, 0, -4), $token . 'x'] as $bad) {
            $this->callStore($bad)->assertStatus(401);
        }
    }

    // THE FORGERY THE SHAPE EXISTS TO STOP. The store id travels in the clear, so the obvious
    // attack is to edit it. It is inside the signed material, so the signature stops verifying.
    public function test_a_credential_cannot_be_edited_to_name_another_store(): void
    {
        [$storeA, , $tokenA] = $this->connectedStore(7001);
        [$storeB, , ] = $this->connectedStore(7002);

        $forged = str_replace(
            CommerceCredential::PREFIX . $storeA->id . '_',
            CommerceCredential::PREFIX . $storeB->id . '_',
            $tokenA
        );

        $this->assertNotSame($tokenA, $forged, 'the test did not actually alter the store id');
        $this->callStore($forged)->assertStatus(401);
    }

    public function test_one_stores_credential_never_answers_for_another(): void
    {
        [$storeA, , $tokenA] = $this->connectedStore(7001);
        [$storeB, , $tokenB] = $this->connectedStore(7002);

        $this->assertStringEndsWith(':' . $storeA->id, $this->callStore($tokenA)->json('external_store_id'));
        $this->assertStringEndsWith(':' . $storeB->id, $this->callStore($tokenB)->json('external_store_id'));
        $this->assertNotSame($tokenA, $tokenB);
    }

    // ---------------------------------------------------------------- lifecycle

    public function test_issuing_a_new_credential_revokes_the_previous_one(): void
    {
        [, $link, $first] = $this->connectedStore();
        $this->callStore($first)->assertOk();

        $link->forceFill(['commerce_credential_version' => $link->commerce_credential_version + 1])->save();
        $second = CommerceCredential::issue($link->fresh());

        $this->callStore($first)->assertStatus(401);
        $this->callStore($second)->assertOk();
    }

    public function test_a_credential_for_a_link_that_is_not_active_is_refused(): void
    {
        [, $link, $token] = $this->connectedStore();
        $this->callStore($token)->assertOk();

        $link->forceFill(['state' => 'revoked'])->save();

        // The credential still verifies -- it proves WHICH store. The link state decides WHETHER.
        $this->callStore($token)->assertStatus(403);
    }

    public function test_nothing_answers_while_the_integration_is_disabled(): void
    {
        [, , $token] = $this->connectedStore();
        config()->set('lynomia.enabled', false);

        $this->callStore($token)->assertStatus(503);
    }

    // ---------------------------------------------------------------- purpose separation

    // A webhook secret and a commerce credential are both derived from the same master key, so
    // the only thing keeping them apart is the purpose label inside the signed material.
    public function test_a_webhook_secret_cannot_be_used_as_a_commerce_credential(): void
    {
        [$store, , ] = $this->connectedStore();
        $webhookSecret = \Workdo\LynomiaChat\app\Services\EventSignature::secretFor((int) $store->id);

        $this->callStore($webhookSecret)->assertStatus(401);
        $this->callStore(CommerceCredential::PREFIX . $store->id . '_1_' . $webhookSecret)->assertStatus(401);
    }

    // It is not a Sanctum token, so the middleware that guards the admin API cannot resolve it.
    public function test_a_commerce_credential_cannot_reach_the_admin_api(): void
    {
        [, , $token] = $this->connectedStore();

        $this->getJson('/api/admin/orderlist', ['Authorization' => 'Bearer ' . $token])
            ->assertStatus(401);
    }

    public function test_the_commerce_route_is_not_in_the_shared_api_throttle_group(): void
    {
        $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName('lynomia.commerce.v1.store');
        $this->assertNotNull($route);

        $stack = array_map(
            static fn ($m) => is_string($m) ? $m : (is_object($m) ? get_class($m) : (string) $m),
            app('router')->gatherRouteMiddleware($route)
        );

        $this->assertNotContains('Illuminate\\Routing\\Middleware\\ThrottleRequests:120,1', $stack,
            'the commerce API is sharing the platform-wide IP-keyed bucket');
        $this->assertContains(\Workdo\LynomiaChat\app\Http\Middleware\AuthenticateCommerceApi::class, $stack);
    }
}
