<?php

namespace Tests\Feature\Lynomia;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;
use Workdo\LynomiaChat\app\Models\LynomiaConsentDecision;
use Workdo\LynomiaChat\app\Models\LynomiaConsentState;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;

/**
 * THE STORE'S OWN CONSENT LEDGER, AND WHY IT IS A SECOND SURFACE RATHER THAN A DUPLICATE.
 *
 * The existing consent tab proxies Lynomia's per-contact API: "what does this workspace hold
 * about this person". This one answers what that surface cannot -- "what did MY checkout
 * capture, has Lynomia been told, and did it agree". The three differ exactly when something
 * is wrong, which is when a merchant comes looking.
 */
class LynomiaStoreConsentVisibilityTest extends TestCase
{
    use InteractsWithLynomia;
    use RefreshDatabase;

    private const LEDGER = '/lynomia/automation/api/store-consents';

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootLynomiaHarness();
    }

    /** @return array{0: \App\Models\User, 1: \App\Models\Store, 2: LynomiaStoreLink} */
    private function connectedTenant(int $accountId = 7001): array
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia(['account_id' => $accountId]);
        $link = app(\Workdo\LynomiaChat\app\Services\AccountProvisioner::class)->provision($store, $owner);

        return [$owner->fresh(), $store, $link->fresh()];
    }

    private function decision(int $storeId, array $attributes = []): LynomiaConsentDecision
    {
        return LynomiaConsentDecision::create(array_merge([
            'store_id' => $storeId,
            'external_subject_id' => 'cust-77',
            'purpose' => 'promotional',
            'channel' => 'any',
            'state' => 'granted',
            'source' => 'checkout_optin',
            'captured_at' => now(),
            'decision_ref' => (string) Str::uuid(),
        ], $attributes));
    }

    private function remoteState(int $storeId, array $attributes = []): LynomiaConsentState
    {
        return LynomiaConsentState::create(array_merge([
            'store_id' => $storeId,
            'external_subject_id' => 'cust-77',
            'purpose' => 'promotional',
            'channel' => 'any',
            'origin' => 'lynomia_native',
            'state' => 'withdrawn',
            'revision' => 5,
            'observed_at' => now(),
        ], $attributes));
    }

    // ================================================================ the walls

    public function test_every_store_consent_route_carries_the_consent_stack(): void
    {
        foreach (['lynomia.automation.api.store_consents',
            'lynomia.automation.api.store_consents.settings',
            'lynomia.automation.api.store_consents.reconcile'] as $name) {
            $this->assertTrue(Route::has($name), "Route {$name} is missing.");

            $stack = array_filter(app('router')->gatherRouteMiddleware(Route::getRoutes()->getByName($name)), 'is_string');

            $this->assertContains(\Illuminate\Auth\Middleware\Authenticate::class, $stack, $name);
            $this->assertTrue((bool) preg_grep('/PlanModuleCheck:LynomiaChat/', $stack), $name);
            $this->assertTrue((bool) preg_grep('/EnsureStoreOwnsLink:existing/', $stack), $name);
            $this->assertTrue((bool) preg_grep('/Permission:Manage Lynomia Consent/', $stack), $name);
        }
    }

    public function test_an_anonymous_caller_is_refused(): void
    {
        $this->getJson(self::LEDGER)->assertUnauthorized();
    }

    public function test_a_staff_member_without_the_consent_permission_is_refused(): void
    {
        [$owner] = $this->connectedTenant();
        $staff = $this->staffOf($owner);
        $this->grantLynomiaPermission($staff, ['Manage Lynomia Chat', 'Manage Lynomia Automation']);

        $this->actingAs($staff->fresh())->getJson(self::LEDGER)->assertForbidden();
    }

    /**
     * STRICT TENANT ISOLATION. No route here takes a store id -- the store comes from the
     * session and EnsureStoreOwnsLink has already authorized it -- so the test that matters
     * is that another tenant's rows never appear, not that an id is rejected.
     */
    public function test_it_never_shows_another_tenants_decisions(): void
    {
        [$owner, $store] = $this->connectedTenant();
        [, $otherStore] = $this->connectedTenant(7002);
        $this->decision($store->id, ['external_subject_id' => 'mine']);
        $this->decision($otherStore->id, ['external_subject_id' => 'theirs']);

        $body = $this->actingAs($owner)->getJson(self::LEDGER)->assertOk()->json();

        $this->assertSame(['mine'], array_column($body['decisions'], 'subject'));
    }

    // ================================================================ what it shows

    public function test_it_shows_a_decision_waiting_in_the_outbox_as_pending(): void
    {
        [$owner, $store] = $this->connectedTenant();
        $this->decision($store->id);

        $body = $this->actingAs($owner)->getJson(self::LEDGER)->assertOk()->json();

        $this->assertSame('pending', $body['decisions'][0]['sync']['status']);
        $this->assertSame(1, $body['pending']);
        $this->assertSame('unknown', $body['decisions'][0]['remote']['state'],
            'nothing is known remotely until Lynomia says so');
    }

    public function test_it_shows_a_grant_lynomia_refused_as_refused_rather_than_sent(): void
    {
        [$owner, $store] = $this->connectedTenant();
        $this->decision($store->id)->forceFill([
            'delivered_at' => now(), 'remote_outcome' => 'refused_withdrawal_dominant',
        ])->save();

        $body = $this->actingAs($owner)->getJson(self::LEDGER)->assertOk()->json();

        $this->assertSame('refused', $body['decisions'][0]['sync']['status']);
        $this->assertSame(0, $body['pending'], 'a settled refusal is not a backlog');
    }

    /**
     * THE WHOLE REASON THIS SURFACE EXISTS. The store holds a grant; the shopper opted out on
     * WhatsApp; Lynomia will not send. A screen showing only the local decision would promise
     * a send that cannot happen.
     */
    public function test_it_shows_that_lynomia_will_not_send_to_someone_who_opted_out_elsewhere(): void
    {
        [$owner, $store] = $this->connectedTenant();
        $this->decision($store->id)->forceFill(['delivered_at' => now(), 'remote_outcome' => 'applied'])->save();
        $this->remoteState($store->id, ['state' => 'withdrawn', 'origin' => 'lynomia_native']);

        $row = $this->actingAs($owner)->getJson(self::LEDGER)->assertOk()->json('decisions.0');

        $this->assertSame('accepted', $row['sync']['status']);
        $this->assertSame('withdrawn', $row['remote']['state']);
        $this->assertSame('lynomia_native', $row['remote']['origin'],
            'the merchant has to be told WHERE the opt-out came from, or they cannot act on it');
    }

    /** A partner grant beside a native withdrawal is not agreement. */
    public function test_a_partner_grant_does_not_hide_a_native_withdrawal_on_the_screen(): void
    {
        [$owner, $store] = $this->connectedTenant();
        $this->decision($store->id)->forceFill(['delivered_at' => now(), 'remote_outcome' => 'applied'])->save();
        $this->remoteState($store->id, ['state' => 'withdrawn', 'origin' => 'lynomia_native', 'revision' => 5]);
        $this->remoteState($store->id, ['state' => 'granted', 'origin' => 'partner', 'revision' => 10001]);

        $row = $this->actingAs($owner)->getJson(self::LEDGER)->assertOk()->json('decisions.0');

        $this->assertSame('withdrawn', $row['remote']['state']);
    }

    public function test_it_names_the_customer_rather_than_an_id_where_it_can(): void
    {
        [$owner, $store] = $this->connectedTenant();
        $customer = Customer::create([
            'first_name' => 'Lina', 'last_name' => 'Haddad',
            'email' => 'lina' . uniqid() . '@example.test',
            'store_id' => $store->id, 'created_by' => $owner->id,
        ]);
        $this->decision($store->id, ['external_subject_id' => (string) $customer->id]);

        $row = $this->actingAs($owner)->getJson(self::LEDGER)->assertOk()->json('decisions.0');

        $this->assertSame('Lina Haddad', $row['customer']);
    }

    /** A customer id belonging to another store must not be resolved into a name here. */
    public function test_it_does_not_name_a_customer_from_another_store(): void
    {
        [$owner, $store] = $this->connectedTenant();
        [$otherOwner, $otherStore] = $this->connectedTenant(7002);
        $stranger = Customer::create([
            'first_name' => 'Not', 'last_name' => 'Yours',
            'email' => 'stranger' . uniqid() . '@example.test',
            'store_id' => $otherStore->id, 'created_by' => $otherOwner->id,
        ]);
        $this->decision($store->id, ['external_subject_id' => (string) $stranger->id]);

        $row = $this->actingAs($owner)->getJson(self::LEDGER)->assertOk()->json('decisions.0');

        $this->assertNull($row['customer']);
    }

    // ================================================================ the settings

    public function test_the_merchant_can_switch_the_checkout_opt_in_on_and_record_a_policy_version(): void
    {
        [$owner, , $link] = $this->connectedTenant();

        $this->actingAs($owner)->postJson(self::LEDGER . '/settings', [
            'checkout_optin_enabled' => true,
            'policy_version' => 'v3',
        ])->assertOk()->assertJson(['checkout_optin_enabled' => true, 'policy_version' => 'v3']);

        $this->assertTrue((bool) $link->fresh()->checkout_optin_enabled);
        $this->assertSame('v3', $link->fresh()->consent_policy_version);
    }

    /** Switching it off must not unsay anything a customer already agreed to. */
    public function test_switching_the_opt_in_off_does_not_touch_decisions_already_captured(): void
    {
        [$owner, $store, $link] = $this->connectedTenant();
        $link->forceFill(['checkout_optin_enabled' => true, 'consent_policy_version' => 'v3'])->save();
        $this->decision($store->id, ['policy_version' => 'v3']);

        $this->actingAs($owner)->postJson(self::LEDGER . '/settings', ['checkout_optin_enabled' => false])->assertOk();

        $this->assertSame(1, LynomiaConsentDecision::count());
        $this->assertSame('granted', LynomiaConsentDecision::first()->state);
        $this->assertSame('v3', LynomiaConsentDecision::first()->policy_version);
    }

    public function test_a_malformed_setting_is_refused_at_the_boundary(): void
    {
        [$owner] = $this->connectedTenant();

        $this->actingAs($owner)->postJson(self::LEDGER . '/settings', ['checkout_optin_enabled' => 'maybe'])
            ->assertStatus(422);
    }

    // ================================================================ reconcile on demand

    public function test_the_merchant_can_ask_lynomia_now_and_the_answer_lands(): void
    {
        [$owner, $store] = $this->connectedTenant();
        $this->lynomiaScript['native_consents'] = [[
            'external_subject_id' => 'cust-77', 'purpose' => 'promotional', 'channel' => 'any',
            'state' => 'withdrawn', 'source' => 'shopper_keyword', 'revision' => 5,
            'decided_at' => now()->toIso8601String(),
        ]];

        $this->actingAs($owner)->postJson(self::LEDGER . '/reconcile')
            ->assertOk()->assertJson(['applied' => 1]);

        $this->assertSame('withdrawn', LynomiaConsentState::forStore($store->id)->sole()->state);
    }

    public function test_an_unreachable_lynomia_says_so_rather_than_failing_the_page(): void
    {
        [$owner, , $link] = $this->connectedTenant();
        $this->lynomiaScript['fail'] = [
            "GET /platform/api/v1/accounts/{$link->lynomia_account_id}/partner_consents" => Http::failedConnection(),
        ];

        $this->actingAs($owner)->postJson(self::LEDGER . '/reconcile')
            ->assertStatus(503)
            ->assertJson(['is_success' => false]);
    }
}
