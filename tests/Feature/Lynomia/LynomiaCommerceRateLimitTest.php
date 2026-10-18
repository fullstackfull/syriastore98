<?php

namespace Tests\Feature\Lynomia;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Services\AccountProvisioner;
use Workdo\LynomiaChat\app\Services\CommerceCredential;

/**
 * ONE MERCHANT MUST NOT BE ABLE TO SPEND ANOTHER MERCHANT'S COMMERCE ALLOWANCE.
 *
 * THE DEFECT THESE PIN. The commerce group carried only two of the three middleware the events
 * ingress has always carried, and in the wrong order: `throttle:lynomia-commerce` ran BEFORE
 * AuthenticateCommerceApi. Laravel resolves a named limiter when the throttle middleware runs,
 * and the limiter read the store id from a request attribute the middleware does not stamp until
 * the END of its handle() -- so the attribute was always unset, the per-store branch was
 * unreachable dead code, and every request fell to one bucket keyed on the caller IP.
 *
 * Lynomia reads every merchant's orders from one egress address. That made the IP bucket a
 * single 120/minute allowance for the WHOLE INSTALLATION: one busy merchant's agents returned
 * 429 to every other merchant's commerce panel, and Lynomia does not retry a 429 (its
 * Commerce::HttpClient retries 502/503/504 only), so a tenant who had done nothing watched their
 * panel degrade or go blank. Two connected stores were enough to cause it and not enough to
 * notice it, which is why it survived a single-store production verification.
 *
 * WHAT IS ASSERTED, AND WHY IT IS NOT JUST THE ORDERING. A structural test on the middleware
 * order would pass again the moment someone reintroduced a shared fallback bucket, so the
 * behavioural tests below drive real requests and assert the property itself: store B is
 * unaffected by store A spending its budget, an unverified caller can reach no store budget at
 * all, and the store a request is counted against comes from the verified credential rather than
 * from anything the caller can choose.
 */
class LynomiaCommerceRateLimitTest extends TestCase
{
    use InteractsWithLynomia;
    use RefreshDatabase;

    /** The floor `registerIngressLimiter` enforces on the per-store budget, so the cheapest honest run. */
    private const STORE_BUDGET = 60;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLynomiaHarness();
        // The per-store budget cannot be configured below 60; the IP ceiling can, and a couple of
        // requests is enough to prove a ceiling exists.
        config()->set('lynomia.commerce_rate_per_minute', self::STORE_BUDGET);
    }

    /** @return array{0: LynomiaStoreLink, 1: string} the link and a live credential for it */
    private function connectedStore(int $accountId): array
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia(['account_id' => $accountId]);
        $link = app(AccountProvisioner::class)->provision($store, $owner->fresh())->fresh();
        $link->forceFill(['commerce_credential_version' => 1])->save();

        return [$link->fresh(), CommerceCredential::issue($link->fresh())];
    }

    /** Every merchant's traffic arrives from Lynomia's one egress address, so the tests use one too. */
    private function callStore(?string $token, string $ip = '203.0.113.7'): \Illuminate\Testing\TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->getJson('/api/lynomia/commerce/v1/store',
                $token === null ? [] : ['Authorization' => 'Bearer ' . $token]);
    }

    // ------------------------------------------------- the stack that makes the id trustworthy

    /**
     * The ordering, pinned structurally as well as behaviourally: the IP ceiling must come first
     * so an unverified caller pays something, the credential check must come next so the store id
     * exists, and the per-store budget must come last so the id it keys on is a verified one.
     */
    public function test_the_per_store_throttle_runs_after_the_credential_is_verified(): void
    {
        $route = Route::getRoutes()->getByName('lynomia.commerce.v1.store');
        $this->assertNotNull($route);

        $stack = array_values(array_map(
            static fn ($m) => is_string($m) ? $m : (is_object($m) ? get_class($m) : (string) $m),
            app('router')->gatherRouteMiddleware($route)
        ));

        $ipCeiling = $credential = $storeBudget = null;

        foreach ($stack as $i => $entry) {
            if (str_contains($entry, 'ThrottleRequests:lynomia-commerce-ip')) {
                $ipCeiling = $i;
            } elseif (str_contains($entry, 'ThrottleRequests:lynomia-commerce')) {
                $storeBudget = $i;
            }
            if (str_contains($entry, 'AuthenticateCommerceApi')) {
                $credential = $i;
            }
        }

        $this->assertNotNull($ipCeiling, 'the commerce API has no per-IP ceiling');
        $this->assertNotNull($credential, 'the commerce API does not verify the credential');
        $this->assertNotNull($storeBudget, 'the commerce API has no per-store budget');

        $this->assertLessThan($credential, $ipCeiling,
            'the IP ceiling runs after verification, so an anonymous flood costs an HMAC and a query for free');
        $this->assertGreaterThan($credential, $storeBudget,
            'the per-store throttle runs before verification, so it cannot know which store to count against');
    }

    // ------------------------------------------------- the property: no cross-merchant spending

    /**
     * THE DEFECT, DIRECTLY. Two merchants, one egress IP. Store A spends its whole budget; store
     * B has never sent a request. Before the fix store B's FIRST call answered 429.
     */
    public function test_one_store_spending_its_budget_does_not_touch_another_store(): void
    {
        [, $tokenA] = $this->connectedStore(7001);
        [, $tokenB] = $this->connectedStore(7002);

        for ($i = 0; $i < self::STORE_BUDGET; $i++) {
            $this->callStore($tokenA)->assertOk();
        }

        $this->callStore($tokenA)->assertStatus(429);
        $this->callStore($tokenB)->assertOk();
    }

    /** And the refusal is a 429 the caller can read, not a 500. */
    public function test_exceeding_the_per_store_budget_answers_429(): void
    {
        [, $token] = $this->connectedStore(7001);

        for ($i = 0; $i < self::STORE_BUDGET; $i++) {
            $this->callStore($token)->assertOk();
        }

        $response = $this->callStore($token);
        $response->assertStatus(429);
        $this->assertNotNull($response->headers->get('Retry-After'), 'a 429 with no Retry-After is not actionable');
    }

    // ------------------------------------------------- what an unverified caller can reach

    /**
     * An unverified caller pays the IP ceiling and can reach NO store budget. Proven by the
     * contrapositive: after the anonymous flood has exhausted its own address, a verified request
     * from a different address is unaffected, because nothing the flood spent belonged to a store.
     */
    public function test_an_unverified_flood_cannot_exhaust_any_merchants_allowance(): void
    {
        config()->set('lynomia.commerce_unverified_rate_per_minute', 2);
        [, $token] = $this->connectedStore(7001);

        $this->callStore(null, '198.51.100.9')->assertStatus(401);
        $this->callStore(null, '198.51.100.9')->assertStatus(401);
        // The third is refused by the ceiling rather than by the credential check.
        $this->callStore(null, '198.51.100.9')->assertStatus(429);

        $this->callStore($token, '203.0.113.7')->assertOk();
    }

    /** A request with no credential is refused, and never counted against a store. */
    public function test_an_unauthenticated_request_is_refused(): void
    {
        [, $token] = $this->connectedStore(7001);

        $this->callStore(null)->assertStatus(401);

        // The store's budget is untouched: its full allowance is still there afterwards.
        for ($i = 0; $i < self::STORE_BUDGET; $i++) {
            $this->callStore($token)->assertOk();
        }
    }

    /**
     * A malformed or forged credential is refused, and -- the part that matters for partitioning
     * -- the store id it NAMES is never used to pick a bucket. `lsc_7001_1_<64 hex>` claims store
     * 7001; if the limiter trusted that claim, a forged token could spend a real merchant's
     * budget without ever passing verification.
     */
    public function test_an_invalid_credential_cannot_spend_the_store_it_claims(): void
    {
        config()->set('lynomia.commerce_unverified_rate_per_minute', 1000);
        [$link, $token] = $this->connectedStore(7001);
        $claimed = 'lsc_' . $link->store_id . '_1_' . str_repeat('a', 64);

        for ($i = 0; $i < self::STORE_BUDGET; $i++) {
            $this->callStore($claimed)->assertStatus(401);
        }

        // The real credential for that same store still has its whole allowance.
        for ($i = 0; $i < self::STORE_BUDGET; $i++) {
            $this->callStore($token)->assertOk();
        }
    }

    // ------------------------------------------------- the configuration is real

    /** Both limits are configurable, which they were not: one read a key that did not exist. */
    public function test_both_commerce_limits_are_configured_keys(): void
    {
        $this->assertIsInt(config('lynomia.commerce_rate_per_minute'));
        $this->assertIsInt(config('lynomia.commerce_unverified_rate_per_minute'));

        $file = require base_path('packages/workdo/LynomiaChat/src/config/lynomia.php');
        $this->assertArrayHasKey('commerce_rate_per_minute', $file);
        $this->assertArrayHasKey('commerce_unverified_rate_per_minute', $file);
    }
}
