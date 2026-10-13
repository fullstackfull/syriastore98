<?php

namespace Tests\Feature\Tenancy;

use App\Http\Middleware\ActiveTheme;
use App\Scopes\StoreScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use RuntimeException;
use Tests\Feature\Security\SecurityTestCase;

/**
 * PER-CONTEXT TENANT RESOLUTION -- the repair for the batch-2 revert.
 *
 * Batch 2 enabled Cart and Customer and had to be reverted. The measured cause was NOT that
 * those models are unscopeable; it was that ONE predicate was serving FIVE different contexts.
 * routes/customer.php is `require`d from routes/web.php:1222, so storefront routes sit inside
 * the `web` group; config/session.php:146 sets the cookie path to '/'; storefronts are
 * path-prefixed on the same host. A merchant with a live admin session is therefore a POPULATED
 * web-guard principal on every storefront request, and `getCurrentStore()` answered with their
 * ADMIN store while the storefront's own reads filtered on the SLUG-resolved store -- so the two
 * predicates ANDed to zero rows, silently.
 *
 * This suite drives EACH CONTEXT EXPLICITLY, which the previous suite could not: it proved only
 * the anonymous storefront case, and that case fails open and so passes trivially.
 *
 * It asserts the resolver, not a model. The model matrix is
 * tests/Feature/Tenancy/StorefrontContextModelIsolationTest.
 */
class StorefrontContextTenantResolutionTest extends SecurityTestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        StoreScope::resetExplicitContext();
        StoreScope::resetSuppression();
        StoreScope::forgetSlugStoreIds();
        StoreScope::forgetOwnedStoreIds();

        parent::tearDown();
    }

    /**
     * Run a callback with the container's `request` replaced by one that has really been matched
     * to a route, so `request()->route()->parameter('storeSlug')` answers exactly as it does in
     * production. `Route::bind()` is what the router itself calls to populate parameters from the
     * URI, so the parameter is parsed rather than hand-stuffed.
     *
     * @template TReturn
     * @param  callable():TReturn  $callback
     * @return TReturn
     */
    private function onRoute(string $uriPattern, string $path, callable $callback, bool $storefrontGroup = true)
    {
        $route = new Route(['GET'], $uriPattern, ['uses' => fn () => null]);

        if ($storefrontGroup) {
            // The one declaration every storefront route inherits: routes/customer.php:49
            // `Route::middleware([ActiveTheme::class, 'themelanguage'])->group(...)`.
            $route->middleware([ActiveTheme::class, 'themelanguage']);
        }

        $request = Request::create($path, 'GET');
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);

        $original = app('request');
        app()->instance('request', $request);

        try {
            return $callback();
        } finally {
            app()->instance('request', $original);
            StoreScope::forgetSlugStoreIds();
        }
    }

    /**
     * @template TReturn
     * @param  callable():TReturn  $callback
     * @return TReturn
     */
    private function onStorefront(string $slug, callable $callback)
    {
        return $this->onRoute('{storeSlug?}/cart', '/' . $slug . '/cart', $callback);
    }

    // =============================================================================
    //  CONTEXT 3 -- STOREFRONT, WEB-GUARD PRINCIPAL. THE CASE THAT BROKE BATCH 2.
    // =============================================================================

    public function test_a_storefront_request_admits_the_slug_store_for_a_foreign_admin_session(): void
    {
        [$merchant, $ownStore] = $this->makeTenant();
        [, $foreignStore] = $this->makeTenant();

        $this->actingAs($merchant->fresh());

        $tenant = $this->onStorefront($foreignStore->slug, fn () => StoreScope::resolveTenant());

        $this->assertSame(StoreScope::CONTEXT_STOREFRONT_WEB, $tenant['context'],
            'A request carrying a storeSlug that resolves to a real store is a STOREFRONT '
            . 'request, whoever is authenticated on the web guard.');
        $this->assertSame(StoreScope::MODE_TENANT, $tenant['mode']);
        $this->assertContains($foreignStore->id, $tenant['store_ids'],
            'THE DEFECT THAT CAUSED THE REVERT. The slug-resolved store must be admissible, or '
            . 'every storefront read of the form where(store_id, $slugStore->id) ANDs to zero '
            . 'rows -- silently, because an empty get() is indistinguishable from "no such row".');
        $this->assertContains($ownStore->id, $tenant['store_ids'],
            'additive mode must keep the owned stores admissible too, which is what makes the '
            . 'set a strict superset and so unable to blank a page that works today.');
    }

    public function test_an_admin_request_does_not_admit_a_foreign_store(): void
    {
        [$merchant, $ownStore] = $this->makeTenant();
        [, $foreignStore] = $this->makeTenant();

        $this->actingAs($merchant->fresh());

        // An admin route: no storeSlug parameter and no ActiveTheme middleware.
        $tenant = $this->onRoute('admin/carts', '/admin/carts', fn () => StoreScope::resolveTenant(), false);

        $this->assertSame(StoreScope::CONTEXT_MERCHANT_ADMIN, $tenant['context']);
        $this->assertSame([$ownStore->id], $tenant['store_ids']);
        $this->assertNotContains($foreignStore->id, $tenant['store_ids'],
            'The admin context must stay narrow. If the storefront repair widened THIS, it would '
            . 'have given every merchant read access to every store and the enablement would be '
            . 'worse than no scope at all.');
    }

    /**
     * THE SAFETY PROPERTY THE WHOLE DESIGN RESTS ON: additive mode can never return fewer rows
     * than the admin predicate, so it cannot blank a page that works today.
     */
    public function test_the_storefront_set_is_a_strict_superset_of_the_admin_set(): void
    {
        [$merchant, $ownStore] = $this->makeTenant();
        [, $foreignStore] = $this->makeTenant();
        $second = $this->makeStore($merchant);

        $this->actingAs($merchant->fresh());
        StoreScope::forgetOwnedStoreIds();

        $admin = $this->onRoute('admin/x', '/admin/x', fn () => StoreScope::resolveTenant(), false);
        $store = $this->onStorefront($foreignStore->slug, fn () => StoreScope::resolveTenant());

        foreach ($admin['store_ids'] as $id) {
            $this->assertContains($id, $store['store_ids'],
                'store id ' . $id . ' is admissible in the admin context but not on the '
                . 'storefront. additive mode would then be a NARROWING, and the "cannot blank a '
                . 'working page" argument in config/tenancy.php would be false.');
        }

        $this->assertContains($ownStore->id, $admin['store_ids']);
        $this->assertContains($second->id, $admin['store_ids']);
        $this->assertGreaterThan(count($admin['store_ids']), count($store['store_ids']));
    }

    public function test_slug_only_mode_replaces_the_owned_set_instead_of_adding_to_it(): void
    {
        [$merchant, $ownStore] = $this->makeTenant();
        [, $foreignStore] = $this->makeTenant();

        config(['tenancy.storefront_tenant' => 'slug_only']);
        $this->actingAs($merchant->fresh());

        $tenant = $this->onStorefront($foreignStore->slug, fn () => StoreScope::resolveTenant());

        $this->assertSame('slug_only', StoreScope::storefrontTenantMode());
        $this->assertSame([$foreignStore->id], $tenant['store_ids']);
        $this->assertNotContains($ownStore->id, $tenant['store_ids'],
            'slug_only is the strict per-context predicate: on a storefront request the tenant '
            . 'is the store the URL names and nothing else.');
    }

    public function test_an_unrecognised_storefront_mode_falls_back_to_additive(): void
    {
        config(['tenancy.storefront_tenant' => 'slug-only']);  // a plausible typo

        $this->assertSame('additive', StoreScope::storefrontTenantMode(),
            'A config typo must not be able to narrow the predicate. Same reasoning as '
            . 'nullTenantPolicy(): a mistake must not take the shop offline.');
    }

    // =============================================================================
    //  CONTEXT 2 -- PUBLIC / CUSTOMER STOREFRONT (no web-guard principal)
    // =============================================================================

    public function test_an_anonymous_storefront_request_stays_fail_open_in_additive_mode(): void
    {
        [, $store] = $this->makeTenant();

        $tenant = $this->onStorefront($store->slug, fn () => StoreScope::resolveTenant());

        $this->assertSame(StoreScope::CONTEXT_STOREFRONT_PUBLIC, $tenant['context']);
        $this->assertSame(StoreScope::MODE_UNRESOLVED, $tenant['mode'],
            'additive must not narrow the anonymous storefront: that is a query shape that works '
            . 'today, and narrowing it is how a storefront gets blanked.');
        $this->assertStringContainsString('slug-resolved store ' . $store->id, $tenant['reason'],
            'The reason must still NAME the resolved store, so the fail-open is measurable '
            . 'rather than invisible (tenancy.log_null_tenant logs this reason).');
    }

    public function test_slug_only_mode_scopes_an_anonymous_storefront_request_to_the_slug_store(): void
    {
        [, $store] = $this->makeTenant();

        config(['tenancy.storefront_tenant' => 'slug_only']);

        $tenant = $this->onStorefront($store->slug, fn () => StoreScope::resolveTenant());

        $this->assertSame(StoreScope::CONTEXT_STOREFRONT_PUBLIC, $tenant['context']);
        $this->assertSame(StoreScope::MODE_TENANT, $tenant['mode'],
            'slug_only is what closes the storefront fail-open hole that tenancy.null_tenant '
            . 'documents. It is implemented and proven, and it is deliberately not the default.');
        $this->assertSame([$store->id], $tenant['store_ids']);
    }

    // =============================================================================
    //  WHICH REQUESTS ARE STOREFRONT REQUESTS -- the resolver must not guess
    // =============================================================================

    public function test_an_unknown_slug_resolves_no_storefront_tenant(): void
    {
        [$merchant, $ownStore] = $this->makeTenant();

        $this->actingAs($merchant->fresh());

        $tenant = $this->onStorefront('no-such-store-' . uniqid(), fn () => StoreScope::resolveTenant());

        $this->assertSame(StoreScope::CONTEXT_MERCHANT_ADMIN, $tenant['context'],
            'An unresolvable slug must NOT be guessed at and must NOT become a storefront '
            . 'context. The request falls back to the merchant-admin predicate.');
        $this->assertSame([$ownStore->id], $tenant['store_ids']);
    }

    public function test_the_first_segment_of_a_non_storefront_route_is_never_read_as_a_slug(): void
    {
        [$merchant, $ownStore] = $this->makeTenant();
        [, $foreignStore] = $this->makeTenant();

        $this->actingAs($merchant->fresh());

        // A route with NO storeSlug parameter and NO ActiveTheme middleware, whose first path
        // segment is nonetheless a real store slug. This is the shape that would turn any
        // /<slug>/... admin-ish URL into a tenant widening if the resolver read segment(1)
        // unconditionally.
        $tenant = $this->onRoute(
            $foreignStore->slug . '/dashboard',
            '/' . $foreignStore->slug . '/dashboard',
            fn () => StoreScope::resolveTenant(),
            false
        );

        $this->assertSame(StoreScope::CONTEXT_MERCHANT_ADMIN, $tenant['context']);
        $this->assertNotContains($foreignStore->id, $tenant['store_ids'],
            'segment(1) must only be consulted for routes carrying the ActiveTheme middleware. '
            . 'Reading it unconditionally would let any URL whose first segment matches a store '
            . 'slug widen the admissible set.');
        $this->assertSame([$ownStore->id], $tenant['store_ids']);
    }

    public function test_the_slug_route_parameter_is_never_mistaken_for_a_store_slug(): void
    {
        [$merchant, $ownStore] = $this->makeTenant();
        [, $foreignStore] = $this->makeTenant();

        $this->actingAs($merchant->fresh());

        // routes/customer.php declares `{storeSlug?}/article/{slug}` and
        // `{storeSlug?}/product/{product_slug}`, so a parameter literally named `slug` is an
        // ARTICLE slug, not a store slug. EnsureCustomerAuthenticated.php:63 does fall back to
        // it; this resolver must not, or it would resolve a confidently WRONG tenant.
        $tenant = $this->onRoute(
            'article/{slug}',
            '/article/' . $foreignStore->slug,
            fn () => StoreScope::resolveTenant()
        );

        $this->assertNotContains($foreignStore->id, $tenant['store_ids'],
            'The `slug` parameter held a real store slug and was correctly ignored. Consulting '
            . 'it would make an article URL silently re-tenant the request.');
        $this->assertSame(StoreScope::CONTEXT_MERCHANT_ADMIN, $tenant['context']);
    }

    public function test_a_storefront_route_matched_without_the_optional_slug_is_not_a_storefront_context(): void
    {
        [$merchant, $ownStore] = $this->makeTenant();

        $this->actingAs($merchant->fresh());

        // `{storeSlug?}` is OPTIONAL, so `/cart` matches with the parameter absent and
        // segment(1) == 'cart', which is no store. The correct answer is "no storefront tenant",
        // NOT a guess.
        $tenant = $this->onRoute('{storeSlug?}/cart', '/cart', fn () => StoreScope::resolveTenant());

        $this->assertSame(StoreScope::CONTEXT_MERCHANT_ADMIN, $tenant['context']);
        $this->assertSame([$ownStore->id], $tenant['store_ids']);
    }

    public function test_the_first_segment_is_read_for_a_storefront_route_with_no_slug_parameter(): void
    {
        [, $store] = $this->makeTenant();
        [$merchant] = $this->makeTenant();

        $this->actingAs($merchant->fresh());

        // ActiveTheme.php:21 resolves `Store::where('slug', request()->segment(1))`, so a
        // storefront route that does NOT declare {storeSlug} still has a resolvable tenant. The
        // package storefront routes are in this shape.
        $tenant = $this->onRoute(
            $store->slug . '/on-page-checkout',
            '/' . $store->slug . '/on-page-checkout',
            fn () => StoreScope::resolveTenant()
        );

        $this->assertSame(StoreScope::CONTEXT_STOREFRONT_WEB, $tenant['context']);
        $this->assertContains($store->id, $tenant['store_ids']);
    }

    // =============================================================================
    //  CONTEXT 1 / 5 -- EXPLICIT CONTEXT, AND THE STALE-CONTEXT TRAP
    // =============================================================================

    public function test_a_console_or_queue_context_has_no_route_and_so_no_storefront(): void
    {
        [, $store] = $this->makeTenant();

        // No route resolver is set on the test's base request, which is exactly the state of a
        // queue worker and an artisan command.
        $this->assertNull(request()->route(), 'fixture precondition');

        $tenant = StoreScope::resolveTenant();

        $this->assertSame(StoreScope::CONTEXT_CONSOLE, $tenant['context']);
        $this->assertSame(StoreScope::MODE_UNRESOLVED, $tenant['mode']);
        $this->assertSame([], $tenant['store_ids']);
    }

    public function test_an_explicit_context_overrides_everything_including_a_storefront_slug(): void
    {
        [$merchant] = $this->makeTenant();
        [, $foreignStore] = $this->makeTenant();
        [, $jobStore] = $this->makeTenant();

        $this->actingAs($merchant->fresh());

        $tenant = $this->onStorefront($foreignStore->slug, fn () => StoreScope::forStoreContext(
            $jobStore->id,
            fn () => StoreScope::resolveTenant()
        ));

        $this->assertSame(StoreScope::CONTEXT_EXPLICIT, $tenant['context']);
        $this->assertSame([$jobStore->id], $tenant['store_ids'],
            'forStoreContext() is how a job SAYS which tenant it acts for. It must win over every '
            . 'ambient signal, or a job dispatched from a web request would inherit that '
            . 'request\'s tenant.');
    }

    public function test_an_explicit_context_does_not_leak_past_its_block(): void
    {
        [, $jobStore] = $this->makeTenant();

        $this->assertFalse(StoreScope::hasExplicitContext(), 'must start clean');

        StoreScope::forStoreContext($jobStore->id, function () use ($jobStore) {
            $this->assertTrue(StoreScope::hasExplicitContext());
            $this->assertSame([$jobStore->id], StoreScope::resolveTenant()['store_ids']);
        });

        $this->assertFalse(StoreScope::hasExplicitContext(),
            'THE STALE-CONTEXT TRAP. A queue worker runs many jobs in one process, so a context '
            . 'that outlived its block would serve job N+1 with job N\'s tenant. There is no '
            . 'ambient context to go stale: it is a stack restored in a finally.');
        $this->assertSame(StoreScope::CONTEXT_CONSOLE, StoreScope::resolveTenant()['context']);
    }

    public function test_an_explicit_context_is_restored_even_when_the_callback_throws(): void
    {
        [, $jobStore] = $this->makeTenant();

        try {
            StoreScope::forStoreContext($jobStore->id, function () {
                throw new RuntimeException('job blew up');
            });
            $this->fail('the exception must propagate');
        } catch (RuntimeException $e) {
            $this->assertSame('job blew up', $e->getMessage());
        }

        $this->assertFalse(StoreScope::hasExplicitContext(),
            'A failed job must not leave its tenant behind for the next one.');
    }

    public function test_nested_explicit_contexts_restore_the_outer_tenant_not_the_absence_of_one(): void
    {
        [, $outer] = $this->makeTenant();
        [, $inner] = $this->makeTenant();

        StoreScope::forStoreContext($outer->id, function () use ($outer, $inner) {
            $this->assertSame([$outer->id], StoreScope::resolveTenant()['store_ids']);

            StoreScope::forStoreContext($inner->id, function () use ($inner) {
                $this->assertSame([$inner->id], StoreScope::resolveTenant()['store_ids']);
            });

            $this->assertSame([$outer->id], StoreScope::resolveTenant()['store_ids'],
                'A stack, not a boolean: the inner block\'s exit must not clear the outer one.');
        });

        $this->assertFalse(StoreScope::hasExplicitContext());
    }

    public function test_an_explicit_context_accepts_several_stores(): void
    {
        [, $a] = $this->makeTenant();
        [, $b] = $this->makeTenant();

        $ids = StoreScope::forStoreContext([$b->id, $a->id, $a->id], fn () => StoreScope::resolveTenant()['store_ids']);

        $expected = [$a->id, $b->id];
        sort($expected);

        $this->assertSame($expected, $ids, 'de-duplicated and sorted, so the compiled bindings -- '
            . 'and the model-caching key derived from them -- are stable.');
    }

    public function test_an_empty_explicit_context_is_rejected_rather_than_compiled(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StoreScope::forStoreContext([], fn () => null);
    }

    // =============================================================================
    //  CONTEXT 6 -- SUPER ADMIN
    // =============================================================================

    public function test_a_super_admin_still_bypasses_the_scope_on_a_storefront(): void
    {
        [, $store] = $this->makeTenant();
        $platform = $this->makeMerchant();
        $platform->type = 'super admin';
        $platform->save();

        $this->actingAs($platform->fresh());

        $tenant = $this->onStorefront($store->slug, fn () => StoreScope::resolveTenant());

        $this->assertSame(StoreScope::CONTEXT_SUPER_ADMIN, $tenant['context'],
            'The platform-operator bypass stays AHEAD of the storefront branch: narrowing a super '
            . 'admin to the storefront they happen to be viewing would hide the platform behind '
            . 'what looks like missing data.');
        $this->assertSame(StoreScope::MODE_SUPER_ADMIN, $tenant['mode']);
    }

    // =============================================================================
    //  THE INVARIANTS THAT HAVE BROKEN THIS PROJECT BEFORE
    // =============================================================================

    public function test_the_all_models_flip_is_still_off(): void
    {
        $this->assertFalse(StoreScope::$enabled,
            'StoreScope::$enabled constrains EVERY trait-carrying model at once. 785 of the 1,282 '
            . 'existing where(store_id) filters pass a store id other than the current one, so a '
            . 'blanket rollout compiles store_id = A AND store_id = B at those sites.');
    }

    public function test_store_scope_enabled_is_still_not_defined_in_config(): void
    {
        $this->assertNull(config('tenancy.store_scope_enabled'),
            'isActive() reads this key and gets null, so the static false governs. DEFINING IT '
            . 'TRUE WOULD SCOPE EVERY TRAIT-CARRYING MODEL AT ONCE, including Product and '
            . 'Customer. This increment added a key to config/tenancy.php; it must not have '
            . 'added this one.');
    }

    /**
     * THE ENFORCEMENT INVENTORY, counted at runtime.
     *
     * app/Models/Concerns/BelongsToStore.php's docblock states "17 carriers minus 10 allowlisted
     * = 7 unenforced". That sentence had been stale across two increments (it read "the 11" while
     * the sentence above it read "TEN models are now really scoped", which cannot both be true),
     * so the numbers are now ASSERTED rather than described. A stale comment on a security
     * control is how a reader is misled about what is enforced.
     */
    public function test_the_enforcement_inventory_is_seventeen_carriers_ten_enforced_seven_not(): void
    {
        $carriers = [];

        foreach (glob(app_path('Models/*.php')) as $file) {
            $contents = file_get_contents($file) ?: '';

            // The `use ... BelongsToStore` composition inside the class body, not the import.
            if (preg_match('/^\s*use\s+[^;]*\bBelongsToStore\b[^;]*;/m', $contents)) {
                $carriers[] = 'App\\Models\\' . basename($file, '.php');
            }
        }

        sort($carriers);

        $allowlisted = StoreScope::allowlistedModels();
        sort($allowlisted);

        $this->assertCount(17, $carriers,
            'The BelongsToStore carrier count changed. Every carrier is one config line away '
            . 'from enforcement, so the set must be reviewed, not grown by accident.');

        $this->assertSame([
            \App\Models\Cart::class,               // batch 3 (re-enabled)
            \App\Models\Customer::class,           // batch 3 (re-enabled)
            \App\Models\FlashSaleCondition::class, // batch 1
            \App\Models\Menu::class,               // batch 1
            \App\Models\NotifyUser::class,         // batch 1
            \App\Models\OrderNote::class,          // batch 2
            \App\Models\OrderRefund::class,        // batch 2
            \App\Models\SupportConversion::class,  // batch 1
            \App\Models\SupportTicket::class,      // batch 1
            \App\Models\ThemeCustomizeOrder::class, // batch 1
        ], $allowlisted, 'The enforced set changed. Adding a model here needs its own '
            . 'negative/positive isolation proof; removing one needs the evidence that it broke '
            . 'something real.');

        $unenforced = array_values(array_diff($carriers, $allowlisted));
        sort($unenforced);

        $this->assertSame([
            \App\Models\Category::class,
            \App\Models\DeliveryBoy::class,
            \App\Models\Product::class,
            \App\Models\Shipping::class,
            \App\Models\StoreWallet::class,
            \App\Models\StoreWalletTransaction::class,
            \App\Models\StoreWithdrawal::class,
        ], $unenforced, 'The seven carriers that are NOT enforced. Attaching the trait without '
            . 'allowlisting is behaviour-neutral, so these are honestly reported as unenforced '
            . 'rather than counted as protected. The three StoreWallet* models are GATE F.');
    }

    public function test_the_storefront_repair_did_not_widen_an_authorization_helper(): void
    {
        [$merchant] = $this->makeTenant();
        [, $foreignStore] = $this->makeTenant();

        $this->actingAs($merchant->fresh());

        // `resolveStoreId()` feeds isOwnedByCurrentStore(), which controllers use as
        // abort_unless($model->isOwnedByCurrentStore(), 403). If the storefront branch had
        // widened it, that would be a PERMISSION change wearing a tenancy change's clothes.
        $resolved = $this->onStorefront($foreignStore->slug, fn () => StoreScope::resolveStoreId());

        $this->assertSame($merchant->current_store, $resolved,
            'On a storefront request resolveStoreId() must still answer the ADMIN store. The '
            . 'storefront repair widens the scope\'s admissible SET, never the single-store '
            . 'identity the authorization helpers compare against.');
        $this->assertNotSame($foreignStore->id, $resolved);
    }
}
