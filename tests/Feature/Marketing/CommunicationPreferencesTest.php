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

    /**
     * F3, from the D8-3 tenant-authz review -- THE SLUG MUST BE REQUIRED, NOT OPTIONAL.
     *
     * It was declared `{storeSlug?}/communication-preferences`, so `/communication-preferences` resolved
     * too. `EnsureCustomerAuthenticated` builds its target from `$request->route('storeSlug')`, and with
     * no slug that is `route('customer.login')` -- the bare `/login`, which `routes/auth.php` claims
     * first as the MERCHANT login. A shopper following a link in a marketing e-mail would have been
     * shown the merchant sign-in page and had no way back to their own store's.
     */
    public function test_the_route_requires_the_store_slug(): void
    {
        foreach (['marketing.preferences.show', 'marketing.preferences.update'] as $name) {
            $uri = RouteFacade::getRoutes()->getByName($name)->uri();

            $this->assertStringNotContainsString('{storeSlug?}', $uri,
                $name.' makes the slug optional, so the signed-out redirect resolves to the merchant login');
            $this->assertStringStartsWith('{storeSlug}/', $uri, $name.' does not carry the store slug');
        }
    }

    public function test_a_signed_out_shopper_is_sent_to_their_own_stores_login(): void
    {
        [$store] = $this->storeWithShopper();

        $response = $this->get($this->url($store));

        $response->assertRedirect(route('customer.login', ['storeSlug' => $store->slug]));

        // The point of the finding: that destination is NOT the merchant login.
        $this->assertNotSame(route('login'), $response->headers->get('Location'),
            'a signed-out shopper was redirected to the merchant sign-in page');
    }

    /**
     * F5b, from the same review -- the POST really is behind the `web` stack, so a cross-site form
     * cannot withdraw or grant consent on a signed-in shopper's behalf. Asserted structurally because
     * the test harness disables CSRF verification, so no request in this file can demonstrate it.
     */
    public function test_the_write_route_is_csrf_protected(): void
    {
        $middleware = RouteFacade::getRoutes()->getByName('marketing.preferences.update')->gatherMiddleware();

        // `gatherMiddleware()` reports the GROUP by name; the group is where CSRF and the session live.
        $this->assertContains('web', $middleware,
            'the preferences POST is outside the web group, so it has neither a session nor CSRF protection');

        $group = app('router')->getMiddlewareGroups()['web'] ?? [];

        // Matched by suffix, not by one fixed class: Laravel 11 renamed VerifyCsrfToken to
        // ValidateCsrfToken, and an application may substitute its own subclass through
        // `$middleware->replace()`. What must hold is that SOME CSRF middleware is in the group.
        $csrf = array_filter($group, static fn ($m) => is_string($m) && str_ends_with($m, 'CsrfToken'));

        $this->assertNotEmpty($csrf,
            'the web group verifies no CSRF token, so another site could change a shopper\'s consent');
        $this->assertContains(\Illuminate\Session\Middleware\StartSession::class, $group,
            'without the session middleware there is no customer session to authenticate against');
    }

    /**
     * F4, from the same review -- A SHOPPER WHOSE ROW NAMES NO STORE.
     *
     * `customers.store_id` is nullable (2023_12_15_112855_create_customers_table) and this deployment
     * may hold such rows. `(int) null` is 0, `ConsentLedger::stateOf()` tolerates 0, so the page
     * rendered ordinary unticked boxes -- and the first tick reached `grant(0, ...)`, which throws
     * InvalidArgumentException. That shopper got an HTTP 500 on every save and nothing was ever written.
     */
    public function test_a_shopper_whose_account_names_no_store_is_offered_nothing_and_can_still_save(): void
    {
        [$store] = $this->storeWithShopper();

        $orphan = Customer::forceCreate([
            'first_name' => 'No',
            'last_name' => 'Store',
            'email' => 'orphan-'.$this->uniqueFixtureRef().'@example.test',
            'password' => bcrypt('x'),
            'store_id' => null,
            'mobile' => '963900000009',
        ])->fresh();

        // Premise: the fixture really has no store, or this test proves nothing.
        $this->assertNull($orphan->store_id, 'Fixture premise: store_id must be null.');

        $page = $this->actingAs($orphan, 'customers')->get($this->url($store));

        $page->assertStatus(200);
        $page->assertDontSee('name="email"', false);
        $page->assertDontSee('name="whatsapp"', false);

        $saved = $this->actingAs($orphan, 'customers')
            ->post($this->url($store), ['email' => 'on', 'whatsapp' => 'on']);

        $saved->assertStatus(200);
        $this->assertSame(0, MarketingConsentEvent::query()->count(),
            'a shopper with no store had consent written -- against which store\'s list?');
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
