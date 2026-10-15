<?php

namespace Tests\Feature\Lynomia;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use Workdo\LynomiaChat\app\Models\LynomiaMarketingAddon;
use Workdo\LynomiaChat\app\Models\LynomiaStoreAddon;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Services\MarketingAllowanceSync;

/**
 * COMMERCIAL ENTITLEMENTS FOR MARKETING SENDS (P5G).
 *
 * TWO AUTHORITIES, AND THE TESTS KEEP THEM APART. Store Syria decides what a package includes
 * and what a merchant has been granted -- commercial decisions about its own product, summed
 * here because they are one authority's numbers. Lynomia decides whether a particular message
 * goes out, composing that single grant with whatever else the account has. A test that
 * computed the merchant-facing number on this side would be asserting what Store Syria
 * BELIEVES rather than what will actually refuse them.
 */
class LynomiaMarketingAllowanceTest extends TestCase
{
    use InteractsWithLynomia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootLynomiaHarness();
    }

    /**
     * A tenant ON A PLAN. `entitledTenant` grants the module but leaves `plan_id` null, and a
     * merchant with no plan is exactly the case where the allowance must be null -- so these
     * examples have to put them on one explicitly, the way the seat allowance tests do.
     *
     * @return array{0: \App\Models\User, 1: \App\Models\Store, 2: LynomiaStoreLink}
     */
    private function connected(?int $includedSends = null): array
    {
        [$owner, $store] = $this->entitledTenant();
        $owner->forceFill(['plan_id' => $this->planWithSends($includedSends)->id])->save();
        $this->fakeLynomia(['account_id' => 7001]);
        $link = app(\Workdo\LynomiaChat\app\Services\AccountProvisioner::class)->provision($store, $owner->fresh())->fresh();

        return [$owner->fresh(), $store, $link];
    }

    private function planWithSends(?int $sends): Plan
    {
        return Plan::create([
            'name' => 'Package ' . uniqid(),
            'price' => '0',
            'duration' => 'Month',
            'themes' => '1',
            'max_stores' => '1',
            'max_products' => '10',
            'max_users' => '10',
            'storage_limit' => '10',
            'lynomia_campaign_sends' => $sends,
        ]);
    }

    private function grant(LynomiaStoreLink $link, int $sends, int $quantity = 1, $expiresAt = null): LynomiaStoreAddon
    {
        $addon = LynomiaMarketingAddon::create([
            'name' => 'Bundle', 'campaign_sends' => $sends, 'price_cents' => 5000, 'currency' => 'USD',
        ]);

        return LynomiaStoreAddon::create([
            'store_id' => $link->store_id, 'addon_id' => $addon->id,
            'quantity' => $quantity, 'expires_at' => $expiresAt,
        ]);
    }

    private function sync(): MarketingAllowanceSync
    {
        return app(MarketingAllowanceSync::class);
    }

    // ================================================================ the allowance

    /**
     * NULL IS NOT ZERO. Every plan that exists today says nothing about marketing sends, and
     * granting zero would stop those merchants' campaigns the moment this shipped.
     */
    public function test_a_plan_that_says_nothing_and_no_addons_grant_nothing(): void
    {
        [, , $link] = $this->connected();

        $this->assertNull($this->sync()->allowanceFor($link));
    }

    public function test_a_plan_allowance_alone_is_the_allowance(): void
    {
        [, , $link] = $this->connected(includedSends: 2000);

        $this->assertSame(2000, $this->sync()->allowanceFor($link));
    }

    /** Both halves are this side's own commercial decision about one merchant, so they add. */
    public function test_addons_add_to_what_the_package_includes(): void
    {
        [, , $link] = $this->connected(includedSends: 2000);
        $this->grant($link, 10_000);

        $this->assertSame(12_000, $this->sync()->allowanceFor($link));
    }

    public function test_quantity_multiplies_a_bundle(): void
    {
        [, , $link] = $this->connected(includedSends: 0);
        $this->grant($link, 1000, quantity: 3);

        $this->assertSame(3000, $this->sync()->allowanceFor($link));
    }

    /** An add-on alone produces a grant, which is what makes a trial work on a bare plan. */
    public function test_an_addon_alone_is_enough_to_produce_an_allowance(): void
    {
        [, , $link] = $this->connected();
        $this->grant($link, 500);

        $this->assertSame(500, $this->sync()->allowanceFor($link));
    }

    /** A TRIAL IS AN ADD-ON THAT EXPIRES, which is why there is no separate trial mechanism. */
    public function test_an_expired_addon_stops_counting_without_being_deleted(): void
    {
        [, , $link] = $this->connected(includedSends: 1000);
        $grant = $this->grant($link, 5000, expiresAt: now()->addDay());

        $this->assertSame(6000, $this->sync()->allowanceFor($link));

        $grant->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->assertSame(1000, $this->sync()->allowanceFor($link));
        $this->assertSame(1, LynomiaStoreAddon::count(), 'the ledger keeps what was given, even once it has lapsed');
    }

    /** STRICT STORE ISOLATION: another store's bundle is not this store's allowance. */
    public function test_another_stores_addon_does_not_raise_this_stores_allowance(): void
    {
        [, , $link] = $this->connected(includedSends: 1000);
        $addon = LynomiaMarketingAddon::create(['name' => 'Theirs', 'campaign_sends' => 9999]);
        LynomiaStoreAddon::create(['store_id' => (int) $link->store_id + 1000, 'addon_id' => $addon->id, 'quantity' => 1]);

        $this->assertSame(1000, $this->sync()->allowanceFor($link));
    }

    // ================================================================ the grant to Lynomia

    public function test_it_pushes_one_number_to_lynomia(): void
    {
        [$owner, , $link] = $this->connected(includedSends: 2000);
        $this->grant($link, 10_000);

        $this->sync()->sync($link, $owner);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'entitlement_grants') || strtoupper($request->method()) !== 'PUT') {
                return false;
            }

            return ($request['resource'] ?? null) === 'campaign_sends' && (int) ($request['limit_value'] ?? 0) === 12_000;
        });
    }

    public function test_it_withdraws_the_grant_when_nothing_says_anything(): void
    {
        [$owner, , $link] = $this->connected();

        $this->sync()->sync($link, $owner);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'entitlement_grants')
            && strtoupper($request->method()) === 'DELETE');
    }

    /**
     * An operator granting an add-on must not get an error because Lynomia is briefly
     * unreachable; the next sync corrects it, and the failure is logged with the store.
     */
    public function test_an_unreachable_lynomia_does_not_fail_the_grant(): void
    {
        [$owner, , $link] = $this->connected(includedSends: 1000);
        $this->lynomiaScript['fail'] = [
            'PUT /platform/api/v1/accounts/7001/entitlement_grants' => Http::failedConnection(),
        ];

        $this->assertNull($this->sync()->sync($link, $owner));
    }

    // ================================================================ the operator surface

    public function test_the_marketing_routes_are_operator_only(): void
    {
        foreach (['lynomia.admin.marketing.allowance', 'lynomia.admin.marketing.addons.store',
            'lynomia.admin.marketing.addons.grant'] as $name) {
            $this->assertTrue(Route::has($name), "Route {$name} is missing.");

            $stack = array_filter(app('router')->gatherRouteMiddleware(Route::getRoutes()->getByName($name)), 'is_string');

            $this->assertContains(\App\Http\Middleware\PlatformOperatorOnly::class, $stack, $name);
            $this->assertContains(\Illuminate\Auth\Middleware\Authenticate::class, $stack, $name);
        }
    }

    public function test_a_merchant_cannot_grant_themselves_an_addon(): void
    {
        [$owner, , $link] = $this->connected();
        $addon = LynomiaMarketingAddon::create(['name' => 'Bundle', 'campaign_sends' => 10_000]);

        $this->actingAs($owner)->post(route('lynomia.admin.marketing.addons.grant'), [
            'link_id' => $link->id, 'addon_id' => $addon->id, 'quantity' => 1,
        ])->assertForbidden();

        $this->assertSame(0, LynomiaStoreAddon::count());
    }

    // THE OPERATOR CASES GO THROUGH THE CONTROLLER, NOT THE HTTP STACK, for the reason
    // LynomiaSeatAllowanceTest documents: this installation has over a hundred pending
    // migrations from other Workdo modules, and every super-admin request 302s to /update
    // before reaching any controller. Asserting through the stack would produce tests that
    // pass because nothing happened, which is worse than no test. The route's own operator
    // guard is asserted separately, from the booted router.

    private function controller(): \Workdo\LynomiaChat\app\Http\Controllers\SuperAdmin\IntegrationController
    {
        return app(\Workdo\LynomiaChat\app\Http\Controllers\SuperAdmin\IntegrationController::class);
    }

    private function request(string $path, array $params): \Illuminate\Http\Request
    {
        return \Illuminate\Http\Request::create("/super-admin/lynomia-integration/{$path}", 'POST', $params);
    }

    public function test_an_operator_can_grant_an_addon_and_it_reaches_lynomia(): void
    {
        [, , $link] = $this->connected(includedSends: 1000);
        $addon = LynomiaMarketingAddon::create(['name' => 'Bundle', 'campaign_sends' => 10_000]);
        $this->actingAs($this->operator());

        $this->controller()->grantAddon($this->request('marketing-addons/grant', [
            'link_id' => $link->id, 'addon_id' => $addon->id, 'quantity' => 1, 'note' => 'pilot',
        ]));

        $this->assertSame(1, LynomiaStoreAddon::count());
        Http::assertSent(fn ($request) => str_contains($request->url(), 'entitlement_grants')
            && (int) ($request['limit_value'] ?? 0) === 11_000);
    }

    /** The ledger says who granted it and why, so "where did these sends come from" is answerable. */
    public function test_a_grant_records_who_made_it(): void
    {
        [, , $link] = $this->connected();
        $addon = LynomiaMarketingAddon::create(['name' => 'Trial', 'campaign_sends' => 500]);
        $operator = $this->operator();
        $this->actingAs($operator);

        $this->controller()->grantAddon($this->request('marketing-addons/grant', [
            'link_id' => $link->id, 'addon_id' => $addon->id, 'quantity' => 1,
            'expires_at' => now()->addMonth()->toDateTimeString(), 'note' => 'pilot',
        ]));

        $grant = LynomiaStoreAddon::sole();
        $this->assertSame($operator->id, (int) $grant->granted_by);
        $this->assertSame('pilot', $grant->note);
        $this->assertNotNull($grant->expires_at, 'a trial is a grant with an expiry');
    }

    /** `nullable` IS the product feature: an empty field says "grant nothing", not "zero". */
    public function test_an_operator_can_clear_a_plans_allowance_without_setting_it_to_zero(): void
    {
        [$owner] = $this->connected(includedSends: 2000);
        $this->actingAs($this->operator());

        $this->controller()->updateMarketingAllowance($this->request('marketing-allowance', [
            'plan_id' => $owner->plan_id, 'campaign_sends' => null,
        ]));

        $this->assertNull(Plan::find($owner->plan_id)->lynomia_campaign_sends);
    }

    public function test_a_negative_allowance_is_refused(): void
    {
        [$owner] = $this->connected(includedSends: 2000);
        $this->actingAs($this->operator());

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->controller()->updateMarketingAllowance($this->request('marketing-allowance', [
            'plan_id' => $owner->plan_id, 'campaign_sends' => -5,
        ]));
    }

    // ================================================================ what the merchant sees

    /**
     * READ FROM THE SIDE THAT ENFORCES IT. A number computed here would be what Store Syria
     * believes, and the two differ the moment an operator sets an override upstream -- the
     * merchant needs the number that will actually refuse them.
     */
    public function test_the_merchant_is_shown_the_enforced_ceiling_not_this_sides_arithmetic(): void
    {
        [$owner, , $link] = $this->connected(includedSends: 2000);
        $this->grant($link, 10_000);
        // Lynomia says something different -- an operator override upstream.
        $this->lynomiaScript['limits'] = ['campaign_sends' => ['used' => 300, 'limit' => 50_000]];

        $body = $this->actingAs($owner)->getJson('/lynomia/campaigns/api/allowance')->assertOk()->json();

        $this->assertSame(50_000, $body['limit'], 'the enforced ceiling wins over this side\'s sum');
        $this->assertSame(300, $body['used']);
        // And the ledger beside it explains where this side's contribution came from.
        $this->assertSame(2000, $body['included']);
        $this->assertSame(10_000, $body['addons'][0]['sends']);
    }

    public function test_an_unlimited_allowance_is_reported_as_no_limit_rather_than_zero(): void
    {
        [$owner] = $this->connected();
        $this->lynomiaScript['limits'] = ['campaign_sends' => ['used' => 12, 'limit' => null]];

        $body = $this->actingAs($owner)->getJson('/lynomia/campaigns/api/allowance')->assertOk()->json();

        $this->assertNull($body['limit']);
        $this->assertSame(12, $body['used']);
    }

    public function test_the_allowance_route_carries_the_campaign_permission(): void
    {
        $stack = array_filter(
            app('router')->gatherRouteMiddleware(Route::getRoutes()->getByName('lynomia.campaigns.api.allowance')),
            'is_string'
        );

        $this->assertTrue((bool) preg_grep('/Permission:Manage Lynomia Campaigns/', $stack));
        $this->assertTrue((bool) preg_grep('/EnsureStoreOwnsLink:existing/', $stack));
    }
}
