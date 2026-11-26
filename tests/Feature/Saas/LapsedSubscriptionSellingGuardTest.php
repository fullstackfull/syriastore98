<?php

namespace Tests\Feature\Saas;

use App\Http\Middleware\LapsedSubscriptionSellingGuard;
use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use App\Support\Saas\SubscriptionLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;

/**
 * SAAS-004 — "تقييد البيع الجديد بعد انتهاء فترة السماح": RESTRICT NEW SELLING AFTER THE GRACE
 * PERIOD, WHILE PRESERVING EXISTING ORDERS, TRACKING AND SUPPORT.
 * =============================================================================================
 *
 * NOTHING RESTRICTED ANYTHING, MEASURED. Before this slice the whole platform's response to a
 * lapsed subscription was `assignPlan(1)` at the next login — a DOWNGRADE, which switches off
 * stores past plan 1's `max_stores` but does not stop selling on the ones that remain. And
 * `ActiveTheme::handle()`'s own `abort('403', 'The store is not active.')` is COMMENTED OUT, so
 * even a store with `is_active = 0` still serves its storefront and still takes orders. The second
 * half of SAAS-004 was satisfied by accident and the first half did not exist.
 *
 * THE DOORS, ENUMERATED FROM THE ROUTE TABLE RATHER THAN GUESSED. There are exactly three places a
 * NEW order can be opened from outside the panel:
 *
 *   POST {storeSlug?}/process-order    PaymentController::processOrder   name `payment.process`
 *   POST api/{slug}/place-order        Api\ApiController::place_order
 *   POST api/{slug}/place-order-guest  Api\ApiController::place_order
 *
 * The first is what the shipped checkout form posts to
 * (`front_end/common/checkout/checkout_form.blade.php` builds `['payment.process', $store->slug]`);
 * `GAP-267` records that the old `{storeSlug?}/place-order` web route pointed at a method that does
 * not exist and was withdrawn. The other two are the storefront API, which no client in either
 * repository calls — gated anyway, because they create orders.
 *
 * THE ~20 GATEWAY CALLBACK CONTROLLERS ARE DELIBERATELY NOT GATED, and that is a measured decision
 * rather than an omission: `Order::create`/`new Order()` appears in twenty-odd payment controllers,
 * and each of those is the CONTINUATION of a checkout that already passed the door above. Gating a
 * callback would refuse a sale AFTER the shopper's money moved, which is the opposite of what
 * SAAS-004 asks for and the worst failure mode available.
 *
 * AND THE PRESERVED SURFACES ARE ASSERTED POSITIVELY. `{storeSlug?}/track-order`,
 * `{storeSlug?}/support-ticket`, the cart pages and the storefront itself are NOT gated, because
 * SAAS-004 names orders, tracking and support as things to preserve. This project has shipped two
 * "fixes" that broke the storefront with a green board, which is why
 * `EnsureCustomerAuthenticated`'s own docblock insists on positive assertions for the public
 * surface; this file does the same.
 *
 * INERT UNTIL THE OWNER SETS THE GRACE PERIOD. `config('saas.grace_period_days')` ships NULL and
 * `SubscriptionLifecycle::restrictsNewSelling()` is then false for every subscription however long
 * ago it lapsed. So attaching this guard changes NOTHING for any live merchant today; it becomes
 * the approved behaviour the moment one value is set. `test_with_no_grace_period_configured_a_
 * lapsed_store_still_sells` is the case that holds that, and it is the one that must not be
 * "fixed" by giving the config a default.
 */
final class LapsedSubscriptionSellingGuardTest extends SecurityTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
        Cache::forget('super_admin_details');
        Cache::forget('admin_details');
    }

    private function makePlan(array $overrides = []): Plan
    {
        return Plan::create(array_merge([
            'name' => 'Plan ' . $this->uniqueFixtureRef(),
            'price' => 100,
            'duration' => 'Month',
            'max_stores' => 3,
            'max_products' => 50,
            'max_users' => 3,
            'storage_limit' => 100,
            'themes' => 'stylique',
            'trial' => 0,
            'trial_days' => 0,
        ], $overrides));
    }

    /** A store whose owner's subscription expired `$daysAgo` days ago. */
    private function storeWhoseOwnerLapsed(int $daysAgo): Store
    {
        $plan = $this->makePlan();
        [$merchant, $store] = $this->makeTenant();

        $merchant->plan_id = $plan->id;
        $merchant->plan_expire_date = date('Y-m-d', strtotime("-{$daysAgo} days"));
        $merchant->save();

        return $store;
    }

    private function storeWhoseOwnerIsPaidUp(): Store
    {
        $plan = $this->makePlan();
        [$merchant, $store] = $this->makeTenant();

        $merchant->plan_id = $plan->id;
        $merchant->plan_expire_date = date('Y-m-d', strtotime('+20 days'));
        $merchant->save();

        return $store;
    }

    // ------------------------------------------------- the store-keyed predicate

    #[Test]
    public function test_the_predicate_reads_the_stores_own_owner(): void
    {
        config(['saas.grace_period_days' => 7]);

        $lapsed = $this->storeWhoseOwnerLapsed(30);
        $current = $this->storeWhoseOwnerIsPaidUp();

        $this->assertTrue(SubscriptionLifecycle::restrictsNewSellingForStore($lapsed));
        $this->assertFalse(SubscriptionLifecycle::restrictsNewSellingForStore($current));
    }

    /**
     * A STORE WHOSE OWNER CANNOT BE READ AS A MERCHANT IS NOT RESTRICTED.
     *
     * `stores.created_by` is written as the merchant's own id at both creation sites
     * (`StoreController::store()` writes `$user->id` on the self-service path and again on the
     * operator path, where it is the NEW merchant and not the operator). But a selling door must
     * never refuse a sale because an ownership read was ambiguous, so a missing owner, a non-admin
     * owner and a null store all answer "do not restrict" — fail-open on the RESTRICTION, which is
     * fail-safe for the merchant's revenue. CLAUDE.md records `created_by = 1` being read as a
     * tenant costing an account takeover, so the type check is not optional.
     */
    #[Test]
    public function test_a_store_with_no_readable_merchant_owner_is_not_restricted(): void
    {
        config(['saas.grace_period_days' => 0]);

        $this->assertFalse(
            SubscriptionLifecycle::restrictsNewSellingForStore(null),
            'a null store restricts nothing'
        );

        /*
         * A STORE WITH A DANGLING `created_by` CANNOT BE BUILT, AND THAT IS A SCHEMA FACT WORTH
         * RECORDING RATHER THAN WORKING AROUND: `stores.created_by` carries a FOREIGN KEY, so
         * setting it to a missing id fails with "FOREIGN KEY constraint failed". The predicate's
         * missing-owner branch is therefore unreachable through the schema and is kept as defence
         * for a partially-selected model or a future schema change, not as a live case. The
         * null-store branch above and the non-admin branch below are the reachable ones.
         */
        $staffOwned = $this->storeWhoseOwnerLapsed(30);
        $owner = User::withoutGlobalScopes()->find($staffOwned->created_by);
        $owner->type = 'staff';
        $owner->save();

        $this->assertFalse(
            SubscriptionLifecycle::restrictsNewSellingForStore($staffOwned->fresh()),
            'a store whose owner is not a type-admin row restricts nothing'
        );

        /*
         * AND THIS ASSERTION IS REDUNDANT DEFENCE RATHER THAN THE CONTROL, stated because a mutant
         * proved it: replacing `restrictsNewSellingForStore()`'s `isMerchant($owner)` with a plain
         * `$owner === null` left every case here green. `restrictsNewSelling()` -> `stateFor()`
         * applies its OWN `isMerchant()` and answers `NOT_A_MERCHANT`, so the type test is enforced
         * twice and either copy alone is sufficient. The outer one is kept -- two spellings of an
         * ownership test on a selling door is the cheap direction -- but it is not what makes the
         * assertion above pass, and a reader should not believe it is.
         */
        $this->assertSame(
            SubscriptionLifecycle::NOT_A_MERCHANT,
            SubscriptionLifecycle::stateFor(
                User::withoutGlobalScopes()->find($staffOwned->created_by),
                null
            ),
            'the inner predicate is where the type test actually bites'
        );
    }

    /**
     * ADDED BECAUSE A MUTANT SURVIVED: the predicate must work on a PARTIALLY-SELECTED `Store`.
     *
     * Replacing `StorePlan::ownerOf($store)` with a bare `User::find($store->created_by)` left
     * every other case green, because a fully-hydrated `Store` always carries `created_by` — so
     * the reuse looked cosmetic. It is not. `StorePlan::ownerOf()` re-reads `created_by` from the
     * table when the model it was handed does not have it, which is exactly what a caller doing
     * `Store::select('id', 'slug')->find(...)` hands over; the bare read gets null, `User::find(null)`
     * is null, and the guard fails open on a store whose subscription is past grace.
     *
     * That is the kind of difference this codebase has paid for before — `StorePlan` exists because
     * a global `admin_details` cache made every store answer with ONE merchant's plan — so the
     * seam is now load-bearing here rather than a tidy-up.
     */
    #[Test]
    public function test_the_predicate_works_on_a_store_selected_without_its_owner_column(): void
    {
        config(['saas.grace_period_days' => 0]);

        $lapsed = $this->storeWhoseOwnerLapsed(30);

        $partial = Store::withoutGlobalScopes()->select(['id', 'slug'])->find($lapsed->id);

        $this->assertNull(
            $partial->getAttribute('created_by'),
            'premise: the column really is absent from this model, which is what makes the case'
        );

        $this->assertTrue(
            SubscriptionLifecycle::restrictsNewSellingForStore($partial),
            'a partially-selected store must not fail open'
        );
    }

    // ------------------------------------------------- the guard, called directly

    /**
     * CALLED DIRECTLY, NOT THROUGH A ROUTE, AND THAT IS DELIBERATE. CLAUDE.md: "A HANDLER-LEVEL
     * CHECK BEHIND AN `abort()`ING MIDDLEWARE IS INVISIBLE TO AN HTTP TEST" — and the converse
     * applies here. `payment.process` sits behind `ActiveTheme` and `themelanguage` and renders a
     * theme view, so an HTTP probe's status says as much about theme resolution as about this
     * guard. Driving `handle()` with a `$next` that records whether it ran is the only assertion
     * that distinguishes "refused" from "failed for another reason".
     */
    private function drive(Store $store, bool $json = false): array
    {
        $ran = false;
        $request = \Illuminate\Http\Request::create(
            ($json ? '/api/' : '/') . $store->slug . ($json ? '/place-order' : '/process-order'),
            'POST'
        );

        if ($json) {
            $request->headers->set('Accept', 'application/json');
        }

        $response = (new LapsedSubscriptionSellingGuard())->handle(
            $request,
            function () use (&$ran) {
                $ran = true;

                return response('reached the handler');
            },
            $store->slug
        );

        return ['passed' => $ran, 'response' => $response];
    }

    #[Test]
    public function test_a_store_past_the_grace_period_cannot_open_a_new_order(): void
    {
        config(['saas.grace_period_days' => 7]);

        $result = $this->drive($this->storeWhoseOwnerLapsed(30));

        $this->assertFalse($result['passed'], 'the checkout handler must not be reached');
        $this->assertSame(302, $result['response']->getStatusCode());
    }

    #[Test]
    public function test_a_paid_up_store_sells_exactly_as_before(): void
    {
        config(['saas.grace_period_days' => 7]);

        $result = $this->drive($this->storeWhoseOwnerIsPaidUp());

        $this->assertTrue($result['passed'], 'a paying merchant must be untouched');
    }

    #[Test]
    public function test_a_store_inside_the_grace_period_still_sells(): void
    {
        config(['saas.grace_period_days' => 7]);

        $result = $this->drive($this->storeWhoseOwnerLapsed(3));

        $this->assertTrue($result['passed'], 'the grace period is time to pay, not a soft block');
    }

    /**
     * THE CASE THAT KEEPS THIS SLICE INERT. With no grace period configured, a subscription that
     * lapsed a year ago still sells — because the owner has not said when the restriction starts.
     * Do not "fix" this by giving `config/saas.php` a default.
     */
    #[Test]
    public function test_with_no_grace_period_configured_a_lapsed_store_still_sells(): void
    {
        config(['saas.grace_period_days' => null]);

        $result = $this->drive($this->storeWhoseOwnerLapsed(365));

        $this->assertTrue(
            $result['passed'],
            'the grace period is an owner decision; until it is set, nothing is restricted'
        );

        // AND THE REASON IS NAMED, so "not restricted" cannot be read as "subscription fine".
        $store = $this->storeWhoseOwnerLapsed(365);
        $owner = User::withoutGlobalScopes()->find($store->created_by);

        $this->assertSame(
            SubscriptionLifecycle::REASON_GRACE_UNDEFINED,
            SubscriptionLifecycle::whyNotRestricted($owner, Plan::find($owner->plan_id))
        );
    }

    /**
     * NEVER 401, ON EITHER SHAPE. The shipped Flutter client force-clears its session on any 401
     * (`lib/network/network.dart`'s `_handleUnauthorized`), so a subscription refusal must not be
     * one — the same rule `EnsureCustomerAuthenticated` records at length.
     */
    #[Test]
    public function test_the_refusal_is_never_a_401(): void
    {
        config(['saas.grace_period_days' => 0]);

        foreach ([false, true] as $json) {
            $result = $this->drive($this->storeWhoseOwnerLapsed(30), $json);

            $this->assertFalse($result['passed']);
            $this->assertNotSame(
                401,
                $result['response']->getStatusCode(),
                ($json ? 'json' : 'web') . ': a 401 logs the shipped client out'
            );
        }
    }

    /** A JSON caller gets JSON, not a redirect to a theme page. */
    #[Test]
    public function test_a_json_caller_is_refused_in_json(): void
    {
        config(['saas.grace_period_days' => 0]);

        $result = $this->drive($this->storeWhoseOwnerLapsed(30), true);

        $this->assertFalse($result['passed']);
        $this->assertSame(403, $result['response']->getStatusCode());
        $this->assertJson((string) $result['response']->getContent());
    }

    // ------------------------------------------------- the guard THROUGH the real route

    /**
     * ADDED BECAUSE A MUTANT SURVIVED, AND IT IS THE MOST IMPORTANT CASE IN THE FILE.
     *
     * Deleting `?? $request->route('slug')` from `resolveStore()` — so the guard reads only
     * `{storeSlug}` and the two API doors resolve NULL — left all eleven earlier cases green. The
     * reason is that `drive()` hands the slug in as the third middleware parameter, so it never
     * exercises route resolution at all, and the wiring cases only assert that the class is
     * ATTACHED. A guard that is attached and resolves nothing is exactly the failure this file's
     * own docblock warns about, and nothing here could see it.
     *
     * So this drives `POST api/{slug}/place-order` over HTTP, through `custom.auth` with a real
     * customer token, and requires the 403 to come from THIS guard — both directions, so a 403 for
     * some other reason cannot stand in for the measurement.
     */
    #[Test]
    public function test_the_api_door_resolves_its_own_slug_parameter(): void
    {
        config(['saas.grace_period_days' => 0]);

        $lapsed = $this->storeWhoseOwnerLapsed(30);
        $shopper = \App\Models\Customer::forceCreate([
            'first_name' => 'Shopper',
            'last_name' => $this->uniqueFixtureRef(),
            'email' => 'shop' . $this->uniqueFixtureRef() . '@example.test',
            'password' => bcrypt('shopper-password-1'),
            'store_id' => $lapsed->id,
        ]);
        $token = $shopper->createToken('auth_token')->plainTextToken;

        $refused = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/' . $lapsed->slug . '/place-order', []);

        $this->assertSame(
            403,
            $refused->getStatusCode(),
            'the guard must resolve the API route\'s {slug} and refuse. Body: '
            . substr((string) $refused->getContent(), 0, 300)
        );
        $this->assertSame(
            __('This store is not accepting new orders at the moment.'),
            $refused->json('message'),
            'and the 403 must be THIS guard, not some other refusal on the same path'
        );

        // THE OTHER DIRECTION: the same call on a paid-up store is NOT refused by this guard. It
        // may well fail for its own reasons -- `place_order` with an empty body -- so the control
        // is the guard's own message, never the status.
        $current = $this->storeWhoseOwnerIsPaidUp();
        $shopper2 = \App\Models\Customer::forceCreate([
            'first_name' => 'Shopper',
            'last_name' => $this->uniqueFixtureRef(),
            'email' => 'shop' . $this->uniqueFixtureRef() . '@example.test',
            'password' => bcrypt('shopper-password-1'),
            'store_id' => $current->id,
        ]);
        $token2 = $shopper2->createToken('auth_token')->plainTextToken;

        $allowed = $this->withHeader('Authorization', 'Bearer ' . $token2)
            ->postJson('/api/' . $current->slug . '/place-order', []);

        $this->assertNotSame(
            __('This store is not accepting new orders at the moment.'),
            $allowed->json('message'),
            'a paying merchant must not be refused by this guard'
        );
    }

    // ------------------------------------------------- the route wiring, both directions

    /**
     * Middleware per `<VERB> <uri>`, with EVERY verb a route answers listed separately.
     *
     * `payment.process` is declared with `Route::any`, so its method list is
     * `GET|HEAD|POST|PUT|PATCH|DELETE|OPTIONS` and keying on `implode('|', methods())` produced
     * `GET|HEAD|POST|PUT|... {storeSlug?}/process-order` — which no `POST {storeSlug?}/...` lookup
     * could ever find. The premise assertion in each case reported that as a missing route rather
     * than as a missing guard, which is the only reason it was not read as a wiring failure.
     *
     * @return array<string, list<string>> "<VERB> <uri>" => middleware
     */
    private function middlewareByUri(): array
    {
        $out = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                $key = $method . ' ' . $route->uri();
                /*
                 * STRINGS ONLY. `gatherMiddleware()` can return a CLOSURE -- some routes in this
                 * application register middleware inline -- and `array_unique()` stringifies its
                 * input, so "Object of class Closure could not be converted to string" came out of
                 * this line rather than any assertion. A closure is not a class name and this map
                 * only ever answers "is this class present".
                 */
                $out[$key] = array_values(array_unique(array_merge(
                    $out[$key] ?? [],
                    array_filter($route->gatherMiddleware(), 'is_string')
                )));
            }
        }

        return $out;
    }

    #[Test]
    public function test_every_new_order_door_carries_the_guard(): void
    {
        $doors = [
            'POST {storeSlug?}/process-order',
            'POST api/{slug}/place-order',
            'POST api/{slug}/place-order-guest',
        ];

        $all = $this->middlewareByUri();

        foreach ($doors as $door) {
            $this->assertArrayHasKey($door, $all, "premise: the route {$door} exists");
            $this->assertContains(
                LapsedSubscriptionSellingGuard::class,
                $all[$door],
                $door . ' opens a new order and must carry the SAAS-004 guard'
            );
        }
    }

    /**
     * AND THE PRESERVED SURFACES CARRY IT ON NONE OF THEM. SAAS-004 names existing orders, tracking
     * and support as things to PRESERVE, so this is the half that must be asserted positively —
     * a guard that crept onto the tracking route would break the requirement it was added for.
     */
    #[Test]
    public function test_no_preserved_surface_carries_the_guard(): void
    {
        $preserved = [
            'GET {storeSlug?}/track-order',
            'POST {storeSlug?}/order-track',
            'GET {storeSlug?}/support-ticket',
            'GET {storeSlug?}/add-ticket',
            'POST {storeSlug?}/store-ticket',
            'GET {storeSlug?}/cart',
        ];

        $all = $this->middlewareByUri();

        foreach ($preserved as $uri) {
            $this->assertArrayHasKey($uri, $all, "premise: the route {$uri} exists");
            $this->assertNotContains(
                LapsedSubscriptionSellingGuard::class,
                $all[$uri],
                $uri . ' is a surface SAAS-004 preserves and must NOT be gated'
            );
        }
    }

    /**
     * THE PANEL IS NOT GATED EITHER. SAAS-004 restricts new SELLING, not the merchant's access to
     * their own data — and `SetLocate`'s commented-out `return redirect()` is the shape that would
     * have done the opposite.
     */
    #[Test]
    public function test_the_merchant_panel_is_not_gated(): void
    {
        $all = $this->middlewareByUri();

        $panel = array_filter(
            $all,
            fn (string $uri) => str_starts_with($uri, 'GET dashboard')
                || str_starts_with($uri, 'GET order')
                || str_starts_with($uri, 'GET support_ticket'),
            ARRAY_FILTER_USE_KEY
        );

        $this->assertNotEmpty($panel, 'premise: panel routes were found');

        foreach ($panel as $uri => $middleware) {
            $this->assertNotContains(
                LapsedSubscriptionSellingGuard::class,
                $middleware,
                $uri . ' is the merchant reaching their own data and must not be gated'
            );
        }
    }
}
