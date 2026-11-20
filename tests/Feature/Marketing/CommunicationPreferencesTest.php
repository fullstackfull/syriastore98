<?php

namespace Tests\Feature\Marketing;

use App\Models\Customer;
use App\Models\MarketingConsentEvent;
use App\Models\Store;
use App\Services\Marketing\ConsentLedger;
use App\Support\Marketing\Channel;
use App\Support\Marketing\ConsentState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\Feature\Security\SecurityTestCase;

/**
 * D8-3b — THE OPT-IN. The first thing in this product that may record a GRANT. D8 / GAP-200 / MKT-008.
 *
 * `ConsentLedgerCensusTest::GRANT_CALLERS` was EMPTY on purpose until now: nothing was allowed to write
 * a `granted` event, because the owner's direction is that consent is never inferred from an order, a
 * registration or an address book. This page is the first exception and it is deliberate -- a person
 * ticking a box about themselves, while signed in as themselves.
 *
 * WHY IT MUST BE AUTHENTICATED, and why the unsubscribe token cannot be reused here: that token is a
 * capability to WITHDRAW one contact, and `UnsubscribeTokens` says so -- "it cannot grant, cannot name
 * another contact". A grant therefore needs the person to prove who they are, which is the customer
 * session.
 *
 * THE TWO VALUES THAT DECIDE WHAT IS WRITTEN COME FROM THE SIGNED-IN CUSTOMER'S OWN ROW -- the store
 * from `$customer->store_id` and the address from `$customer->email` / `$customer->mobile`. Neither is
 * read from the request. CLAUDE.md records that a tenant predicate built from caller-supplied input is
 * not a tenant check, and has produced four separate defects here; `test_forged_request_input_is_ignored`
 * posts another store's id and another shopper's address and asserts both are discarded.
 *
 * A SUPPRESSION OUTRANKS A TICK. A complaint, a legal request or a bounce is a hard block, and the state
 * machine keeps it: ticking the box cannot undo one, and the page says so rather than silently failing.
 */
final class CommunicationPreferencesTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const URL = '/communication-preferences';

    /** @return array{0: Store, 1: Customer} */
    private function storeWithShopper(?string $mobile = '963900000001'): array
    {
        [, $store] = $this->makeTenant();

        $customer = Customer::forceCreate([
            'first_name' => 'Nour',
            'last_name' => 'Saleh',
            'email' => 'shopper-'.$this->uniqueFixtureRef().'@example.test',
            'password' => bcrypt('x'),
            'store_id' => $store->id,
            'mobile' => $mobile,
        ]);

        return [$store, $customer->fresh()];
    }

    private function url(Store $store): string
    {
        return '/'.$store->slug.self::URL;
    }

    private function stateOf(Customer $customer, Channel $channel, string $address): ConsentState
    {
        return app(ConsentLedger::class)->stateOf((int) $customer->store_id, $channel, $address);
    }

    /*
     * -------------------------------------------------------------------------------------------
     * The door
     * -------------------------------------------------------------------------------------------
     */

    public function test_a_guest_cannot_open_the_preferences(): void
    {
        [$store] = $this->storeWithShopper();

        $response = $this->get($this->url($store));

        $this->assertTrue($response->isRedirect(), 'a signed-out visitor was shown the preferences');
        $this->assertNotSame(401, $response->status(),
            'a 401 forces a logout in the shipped Flutter client; this codebase uses 403 or a redirect');
    }

    public function test_a_guest_cannot_write_a_grant(): void
    {
        [$store, $customer] = $this->storeWithShopper();

        $this->post($this->url($store), ['email' => 'on']);

        $this->assertSame(ConsentState::None, $this->stateOf($customer, Channel::Email, (string) $customer->email));
        $this->assertSame(0, MarketingConsentEvent::query()->count(), 'a signed-out POST wrote to the ledger');
    }

    public function test_the_routes_sit_behind_the_customer_session_gate(): void
    {
        foreach (['marketing.preferences.show', 'marketing.preferences.update'] as $name) {
            $route = RouteFacade::getRoutes()->getByName($name);

            $this->assertNotNull($route, $name.' is not registered');
            $this->assertContains(
                \App\Http\Middleware\EnsureCustomerAuthenticated::class,
                $route->gatherMiddleware(),
                $name.' is not gated by the storefront customer-session middleware'
            );
        }
    }

    /*
     * -------------------------------------------------------------------------------------------
     * Reading and writing the person's own choice
     * -------------------------------------------------------------------------------------------
     */

    public function test_the_page_offers_a_channel_the_shopper_has_an_address_for(): void
    {
        [$store, $customer] = $this->storeWithShopper();

        $response = $this->actingAs($customer, 'customers')->get($this->url($store));

        $response->assertStatus(200);
        $response->assertSee('name="email"', false);
        $response->assertSee('name="whatsapp"', false);
    }

    public function test_a_shopper_with_no_mobile_is_offered_no_phone_channel(): void
    {
        [$store, $customer] = $this->storeWithShopper(null);

        $response = $this->actingAs($customer, 'customers')->get($this->url($store));

        $response->assertStatus(200);
        $response->assertSee('name="email"', false);
        $response->assertDontSee('name="whatsapp"', false);
    }

    public function test_ticking_email_records_an_explicit_grant(): void
    {
        [$store, $customer] = $this->storeWithShopper();

        $this->actingAs($customer, 'customers')
            ->post($this->url($store), ['email' => 'on'])
            ->assertStatus(200);

        $this->assertSame(ConsentState::Granted,
            $this->stateOf($customer, Channel::Email, (string) $customer->email));
    }

    public function test_unticking_withdraws_what_was_granted(): void
    {
        [$store, $customer] = $this->storeWithShopper();
        app(ConsentLedger::class)->grant((int) $store->id, Channel::Email, (string) $customer->email, 'test-fixture');

        $this->actingAs($customer, 'customers')
            ->post($this->url($store), [])
            ->assertStatus(200);

        $this->assertSame(ConsentState::Withdrawn,
            $this->stateOf($customer, Channel::Email, (string) $customer->email));
    }

    public function test_a_second_identical_post_records_nothing_new(): void
    {
        [$store, $customer] = $this->storeWithShopper();

        $this->actingAs($customer, 'customers')->post($this->url($store), ['email' => 'on']);
        $after = MarketingConsentEvent::query()->count();
        $this->actingAs($customer, 'customers')->post($this->url($store), ['email' => 'on']);

        $this->assertSame($after, MarketingConsentEvent::query()->count(),
            'an unchanged choice appended another event, so the ledger grows on every page save');
    }

    /*
     * -------------------------------------------------------------------------------------------
     * THE SECURITY CASE: the request body decides nothing
     * -------------------------------------------------------------------------------------------
     */

    public function test_forged_request_input_is_ignored(): void
    {
        [$storeA, $shopperA] = $this->storeWithShopper();
        [$storeB, $shopperB] = $this->storeWithShopper();

        $this->actingAs($shopperA, 'customers')->post($this->url($storeA), [
            'email' => 'on',
            // All of this is attacker-supplied and must decide nothing.
            'store_id' => $storeB->id,
            'customer_id' => $shopperB->id,
            'address' => $shopperB->email,
            'channel' => 'email',
        ])->assertStatus(200);

        $this->assertSame(ConsentState::Granted,
            $this->stateOf($shopperA, Channel::Email, (string) $shopperA->email),
            "the grant did not land on the signed-in shopper's own address");
        $this->assertSame(ConsentState::None,
            $this->stateOf($shopperB, Channel::Email, (string) $shopperB->email),
            'a request body named another shopper and consent was recorded for them');
    }

    /** Opening another store's slug must not move the person's consent to that store. */
    public function test_the_store_comes_from_the_shopper_not_the_slug(): void
    {
        [$storeA, $shopperA] = $this->storeWithShopper();
        [$storeB] = $this->storeWithShopper();

        $this->actingAs($shopperA, 'customers')
            ->post($this->url($storeB), ['email' => 'on'])
            ->assertStatus(200);

        $this->assertSame(ConsentState::Granted,
            app(ConsentLedger::class)->stateOf((int) $storeA->id, Channel::Email, (string) $shopperA->email));
        $this->assertSame(ConsentState::None,
            app(ConsentLedger::class)->stateOf((int) $storeB->id, Channel::Email, (string) $shopperA->email),
            "consent was recorded against the slug's store instead of the shopper's own");
    }

    /*
     * -------------------------------------------------------------------------------------------
     * A hard block is not undone by a tick
     * -------------------------------------------------------------------------------------------
     */

    public function test_a_tick_cannot_undo_a_suppression(): void
    {
        [$store, $customer] = $this->storeWithShopper();
        app(ConsentLedger::class)->suppress((int) $store->id, Channel::Email, (string) $customer->email, 'complaint');

        $this->actingAs($customer, 'customers')
            ->post($this->url($store), ['email' => 'on'])
            ->assertStatus(200);

        $this->assertSame(ConsentState::Suppressed,
            $this->stateOf($customer, Channel::Email, (string) $customer->email),
            'a complaint was softened by a checkbox');
    }
}
