<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingConsentEvent;
use App\Models\Store;
use App\Services\Marketing\ConsentLedger;
use App\Services\Marketing\UnsubscribeTokens;
use App\Support\Marketing\Channel;
use App\Support\Marketing\ConsentState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\Feature\Security\SecurityTestCase;

/**
 * D8-3a — THE UNSUBSCRIBE LINK, HONOURED SAFELY. D8 / GAP-200 / MKT-008.
 *
 * `UnsubscribeTokens` has existed since D8-1 and was UNWIRED: a token could be issued and redeemed in
 * PHP, but nothing a recipient could click reached it. Its own docblock states the three rules the route
 * must obey, and each one is a case here rather than a comment:
 *
 *   1. **A GET MUST NEVER REDEEM.** Mail scanners and link prefetchers follow GETs. A token is reusable
 *      BY DESIGN (an unsubscribe link that stops working is a failure to honour an unsubscribe), so a
 *      prefetched GET on an old link would withdraw an opt-in the person has since given again. The GET
 *      therefore only CONFIRMS; the POST withdraws.
 *   2. **THE ROUTE MUST BE THROTTLED.** Every recipient holds a working link and every redemption appends
 *      a row to a table nothing prunes.
 *   3. **THE STORE IS THE ONE THE PAGE IS SERVED UNDER**, passed as the required `$expectedStoreId`, so a
 *      token issued for store A cannot be honoured because it was opened at store B. The slug is REQUIRED
 *      on the route for exactly this reason: `getStore(null)` would resolve some other store, and
 *      CLAUDE.md records that a storefront handler must take its store from the route and never from
 *      `getCurrentStore()`, which answers for a merchant's panel session.
 *
 * AND AN UNRECOGNISED TOKEN MUST BE ANSWERED IDENTICALLY TO A WRONG-STORE ONE -- no hint that it exists
 * somewhere else. That equality is asserted directly, because it is the kind of thing a later "friendlier
 * error" refactor quietly breaks.
 *
 * NOTHING HERE DEPENDS ON ENFORCEMENT BEING ON. Honouring an unsubscribe is not gated by
 * `MARKETING_CONSENT_ENFORCEMENT`: a withdrawal must be recorded whatever the send gate is set to.
 */
final class UnsubscribePageTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function tokens(): UnsubscribeTokens
    {
        return app(UnsubscribeTokens::class);
    }

    private function addressFor(): string
    {
        return 'shopper-'.$this->uniqueFixtureRef().'@example.test';
    }

    private function stateOf(Store $store, string $address): ConsentState
    {
        return app(ConsentLedger::class)->stateOf((int) $store->id, Channel::Email, $address);
    }

    private function showUrl(Store $store, string $token): string
    {
        return '/'.$store->slug.'/unsubscribe/'.$token;
    }

    /*
     * -------------------------------------------------------------------------------------------
     * PREMISES
     * -------------------------------------------------------------------------------------------
     */

    public function test_premise_a_contact_starts_at_none_and_a_token_can_be_issued(): void
    {
        [, $store] = $this->makeTenant();
        $address = $this->addressFor();

        $this->assertSame(ConsentState::None, $this->stateOf($store, $address),
            'consent is never inferred: an address nobody has recorded must read as none');

        $token = $this->tokens()->issue((int) $store->id, Channel::Email, $address);

        $this->assertSame(43, strlen($token), 'the token is 32 random bytes in url-safe base64');
    }

    /*
     * -------------------------------------------------------------------------------------------
     * RULE 1 — a GET confirms, it does not withdraw
     * -------------------------------------------------------------------------------------------
     */

    public function test_a_get_shows_the_page_and_does_not_withdraw(): void
    {
        [, $store] = $this->makeTenant();
        $address = $this->addressFor();
        $token = $this->tokens()->issue((int) $store->id, Channel::Email, $address);

        $response = $this->get($this->showUrl($store, $token));

        $response->assertStatus(200);

        $this->assertSame(ConsentState::None, $this->stateOf($store, $address),
            'the GET redeemed the token. A link prefetcher or mail scanner would then withdraw an '
            .'opt-in the person has since given again, which is why the service requires POST.');
        $this->assertSame(0, MarketingConsentEvent::query()->count(),
            'the GET appended a consent event, so it wrote to the ledger');
    }

    public function test_the_confirmation_page_shows_the_masked_hint_and_never_the_address(): void
    {
        [, $store] = $this->makeTenant();
        $address = $this->addressFor();
        $token = $this->tokens()->issue((int) $store->id, Channel::Email, $address);

        $response = $this->get($this->showUrl($store, $token));

        [$local] = explode('@', $address, 2);

        $response->assertStatus(200);
        $response->assertDontSee($address, false);
        $response->assertSee(mb_substr($local, 0, 1).'***@example.test', false);
    }

    /*
     * -------------------------------------------------------------------------------------------
     * RULE 1 — the POST is what withdraws
     * -------------------------------------------------------------------------------------------
     */

    public function test_a_post_withdraws_the_contact_the_token_names(): void
    {
        [, $store] = $this->makeTenant();
        $address = $this->addressFor();
        $token = $this->tokens()->issue((int) $store->id, Channel::Email, $address);

        $response = $this->post($this->showUrl($store, $token));

        $response->assertStatus(200);
        $this->assertSame(ConsentState::Withdrawn, $this->stateOf($store, $address));
        $this->assertSame(1, MarketingConsentEvent::query()->count(), 'exactly one withdrawal was recorded');
    }

    /** Reusable BY DESIGN: a second click must not fail, and must leave the person withdrawn. */
    public function test_a_second_post_is_honoured_and_not_an_error(): void
    {
        [, $store] = $this->makeTenant();
        $address = $this->addressFor();
        $token = $this->tokens()->issue((int) $store->id, Channel::Email, $address);

        $this->post($this->showUrl($store, $token))->assertStatus(200);
        $this->post($this->showUrl($store, $token))->assertStatus(200);

        $this->assertSame(ConsentState::Withdrawn, $this->stateOf($store, $address),
            'a recipient who clicks an old link must not find it dead');
    }

    /*
     * -------------------------------------------------------------------------------------------
     * RULE 3 — the store is the one the page is served under
     * -------------------------------------------------------------------------------------------
     */

    public function test_a_token_issued_for_another_store_is_refused_and_withdraws_nothing(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $address = $this->addressFor();
        $tokenForA = $this->tokens()->issue((int) $storeA->id, Channel::Email, $address);

        $response = $this->post($this->showUrl($storeB, $tokenForA));

        $response->assertStatus(404);
        $this->assertSame(ConsentState::None, $this->stateOf($storeA, $address),
            "store A's contact was withdrawn by a request served under store B");
        $this->assertSame(0, MarketingConsentEvent::query()->count());
    }

    /** An unknown token and a wrong-store token must be INDISTINGUISHABLE. */
    public function test_an_unknown_token_and_a_wrong_store_token_answer_identically(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $tokenForA = $this->tokens()->issue((int) $storeA->id, Channel::Email, $this->addressFor());
        $unknown = str_repeat('a', 43);

        $wrongStore = $this->post($this->showUrl($storeB, $tokenForA));
        $neverIssued = $this->post($this->showUrl($storeB, $unknown));

        $this->assertSame($neverIssued->status(), $wrongStore->status());
        $this->assertSame($neverIssued->getContent(), $wrongStore->getContent(),
            'a wrong-store token is answered differently from an unknown one, which tells the holder '
            .'that it is valid at some other store');
    }

    public function test_a_malformed_token_is_refused_without_a_server_error(): void
    {
        [, $store] = $this->makeTenant();

        foreach (['short', str_repeat('z', 44), 'has spaces here and is 43 characters long!!'] as $bad) {
            $this->post($this->showUrl($store, rawurlencode($bad)))->assertStatus(404);
        }

        $this->assertSame(0, MarketingConsentEvent::query()->count());
    }

    public function test_an_unknown_store_slug_fails_closed(): void
    {
        [, $store] = $this->makeTenant();
        $token = $this->tokens()->issue((int) $store->id, Channel::Email, $this->addressFor());

        $this->post('/no-such-store-'.$this->uniqueFixtureRef().'/unsubscribe/'.$token)->assertStatus(404);

        $this->assertSame(0, MarketingConsentEvent::query()->count());
    }

    /*
     * -------------------------------------------------------------------------------------------
     * RULE 2 — throttling, asserted structurally because a rate limit cannot be proved by one request
     * -------------------------------------------------------------------------------------------
     */

    public function test_both_unsubscribe_routes_are_throttled(): void
    {
        foreach (['marketing.unsubscribe.show', 'marketing.unsubscribe.redeem'] as $name) {
            $route = RouteFacade::getRoutes()->getByName($name);

            $this->assertNotNull($route, $name.' is not registered');

            $throttles = array_filter(
                $route->gatherMiddleware(),
                fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')
            );

            $this->assertNotEmpty($throttles, sprintf(
                '%s has no throttle middleware. Every recipient holds a working link and every '
                .'redemption appends a row to a table nothing prunes.',
                $name
            ));
        }
    }

    /**
     * F5c, from the D8-3 tenant-authz review -- THE REDEMPTION MUST NOT BE BOUNDED PER CALLER.
     *
     * A bare `throttle:30,1` is keyed on the IP. Recipients of one campaign routinely share an egress
     * address (a corporate gateway, a carrier NAT, a mail provider's link scanner), so one recipient's
     * traffic -- or a scanner's prefetches -- could exhaust the budget and the next person's POST would
     * come back 429. A 429 here is not a delay: THE WITHDRAWAL IS SIMPLY NOT RECORDED, which is the one
     * outcome this surface exists to prevent.
     *
     * Asserted structurally because the shape of the limiter is the thing that has to hold: any
     * `throttle:<number>,<number>` is per-caller by construction, whatever the numbers are.
     */
    public function test_the_redemption_is_not_bounded_by_a_bare_per_caller_throttle(): void
    {
        $redeem = array_values(array_filter(
            RouteFacade::getRoutes()->getByName('marketing.unsubscribe.redeem')->gatherMiddleware(),
            fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')
        ));

        $named = array_filter($redeem, fn ($m) => preg_match('/^throttle:\d+,\d+$/', $m) !== 1);

        $this->assertNotEmpty($named,
            'the redemption POST is bounded by a bare numeric throttle, which Laravel keys on the IP: '
            .'one shared egress address can then spend every recipient\'s budget, and a refused POST '
            .'means an unsubscribe that was never recorded.');
    }

    /** The same property, measured: one recipient spending their whole budget must not refuse another's. */
    public function test_one_recipients_redemptions_cannot_exhaust_anothers(): void
    {
        [, $store] = $this->makeTenant();

        $mine = $this->addressFor();
        $theirs = $this->addressFor();
        $myToken = $this->tokens()->issue((int) $store->id, Channel::Email, $mine);
        $theirToken = $this->tokens()->issue((int) $store->id, Channel::Email, $theirs);

        /*
         * Spend one recipient's budget until it is refused, all from this test's single IP. The loop is
         * ADAPTIVE rather than a fixed count on purpose: it must trip whatever the limit happens to be,
         * so that re-merging the two halves onto one per-IP bucket is caught by the SHARING assertion
         * below instead of by a premise that merely stops being measurable.
         */
        $attempts = 0;
        $last = null;

        do {
            $last = $this->post($this->showUrl($store, $myToken));
            $attempts++;
        } while ($last->getStatusCode() !== 429 && $attempts < 60);

        // Premise: the redemption IS bounded, or the assertion below proves nothing about sharing.
        $this->assertSame(429, $last->getStatusCode(),
            'Fixture premise: a replay flood against one link must eventually be refused, since every '
            .'redemption appends a row to a table nothing may prune.');

        $response = $this->post($this->showUrl($store, $theirToken));

        $this->assertNotSame(429, $response->getStatusCode(),
            'a second recipient was refused because the first had spent a budget they SHARE -- that '
            .'refusal is an unsubscribe that was never recorded');
        $this->assertSame(ConsentState::Withdrawn, $this->stateOf($store, $theirs),
            'the second recipient\'s withdrawal was not recorded');
    }

    /*
     * -------------------------------------------------------------------------------------------
     * A suppression outranks the link: it must not be downgraded to "withdrawn"
     * -------------------------------------------------------------------------------------------
     */

    public function test_a_suppressed_contact_stays_suppressed(): void
    {
        [, $store] = $this->makeTenant();
        $address = $this->addressFor();
        app(ConsentLedger::class)->suppress((int) $store->id, Channel::Email, $address, 'complaint');
        $token = $this->tokens()->issue((int) $store->id, Channel::Email, $address);

        $this->post($this->showUrl($store, $token))->assertStatus(200);

        $this->assertSame(ConsentState::Suppressed, $this->stateOf($store, $address),
            'a hard block was softened to withdrawn by an unsubscribe click');
    }
}
