<?php

namespace Tests\Feature\Security;

use App\Models\AddOnManager;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * P1-INV-06 / #32 -- the two vendored "frequently bought together" cart writers.
 *
 * THE DEFECT, REPRODUCED BY EXECUTION with only the `bootstrap/app.php` registration line removed
 * (the removal checks at the bottom of this file re-run each of these):
 *
 *   POST /{A-slug}/boost-sales-cart         product_ids[]=<B's product> enable_product[]=<same>
 *   POST /{A-slug}/frequently_product_cart  product_ids[]=<B's product> enable_product[]=<same>
 *     -> 200 {"status":1,"message":"successfull","data":{"message":"Products added successfully."}}
 *        carts  store_id = 2 (A)   product_id = 1 (B's)   price = 999 (B's catalogue price)
 *        -- the same result from BOTH routes, which is why one guard covers both.
 *
 *   POST /{A-slug}/<either>  product_ids[]=999999 enable_product[]=999999   -> 500, no row
 *   POST /{A-slug}/<either>  enable_product[]=<own product>, no product_ids -> 500, no row
 *
 * THE TWO 500s ARE WHY THE LIVENESS CONTROL BELOW IS NOT OPTIONAL. A test asserting only "no cart
 * row was written" would pass against the UNFIXED handler on both of those shapes. The assertions
 * here are on the REFUSAL -- a 403 the guard alone produces -- not on the absence of a row.
 *
 * `Product` carries no `StoreScope` AND is absent from `config/tenancy.php`'s `scoped_models`, so
 * `Product::find()` adds no predicate for any principal. Both routes sit in a `['web']`-only group
 * -- no `Authenticate`, no `EnsureCustomerAuthenticated`, no `PlanModuleCheck` -- so the whole
 * thing is anonymous. The fix is `App\Http\Middleware\ModuleCartWriteGuard`, appended to the `web`
 * group and keyed on the lowercased controller action.
 *
 * WHAT THIS SUITE PINS THAT IS NOT THE HEADLINE:
 *
 *   THE ROUTES DO NOT EXIST UNTIL THE MODULES ARE ENABLED. No WorkDo module loads in any test run
 *   unless an `add_on_managers` row exists, so a suite that merely posted to the uri would 404 and
 *   every "no cart row was written" assertion would pass vacuously. `test_the_premise_*` asserts
 *   the absence first and the suite enables the modules through the production loader.
 *
 *   THE GUARD'S KEYS ARE LIVE. `test_the_premise_the_two_routes_resolve_to_the_guarded_actions`
 *   reads the resolved route table and asserts the lowercased action names are exactly the two
 *   `TARGETS` keys. Without it an upstream rename -- or this project's own typo -- makes the guard
 *   inert and every negative test below passes for the wrong reason.
 *
 *   A 403 IS NOT A LIVENESS CHECK ON ITS OWN. The positive tests assert that a legitimate request
 *   still writes its row, because a guard that refused everything would satisfy every negative
 *   assertion here. That is the first of the four vacuous-pass traps the project skill names, and
 *   it bit for real on this endpoint: with `product_ids` absent the UNGUARDED handler 500s
 *   (`in_array($x, null)` is a TypeError on PHP 8), so "no row was written" held either way.
 *
 *   ROWS ARE READ THROUGH `DB::table()`. `Product` is `Cachable`; an Eloquent read can serve a
 *   mutated in-memory instance, which produced a false reproduction once already in this project.
 */
class ModuleCartWriteGuardTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The two lowercased action names the guard keys on. */
    private const ACTIONS = [
        'boost-sales-cart' => 'workdo\boostsales\app\http\controllers\boostsalescontroller@product_cartlist',
        'frequently_product_cart' => 'workdo\frequentlyproduct\app\http\controllers\frequentlyproductcontroller@product_cartlist',
    ];

    private function enableModules(): void
    {
        foreach ([['BoostSales', 'Boost Sales', 'boost-sales'], ['FrequentlyProduct', 'Frequently Product', 'frequently-product']] as [$module, $name, $package]) {
            AddOnManager::create([
                'module' => $module,
                'name' => $name,
                'monthly_price' => 0,
                'yearly_price' => 0,
                'is_enable' => 1,
                'package_name' => $package,
            ]);
        }

        Cache::flush();

        $provider = new \App\Providers\PackageServiceProvider($this->app);
        $loadAddons = new \ReflectionMethod($provider, 'loadAddons');
        $loadAddons->invoke($provider, ['BoostSales', 'FrequentlyProduct']);
    }

    /** @return array{0: User, 1: Store, 2: int} merchant, store, their own product id */
    private function storeWithProduct(string $label, float $price = 25.0): array
    {
        [$owner, $store] = $this->makeTenant();

        $productId = (int) DB::table('products')->insertGetId([
            'name' => $label,
            'slug' => 'product-' . $store->id . '-' . uniqid(),
            'store_id' => $store->id,
            'created_by' => $owner->id,
            'price' => $price,
            'track_stock' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$owner, $store, $productId];
    }

    /** The two uris, so every negative case is driven through BOTH handlers rather than one. */
    private function uris(Store $store): array
    {
        return [
            'boost-sales' => '/' . $store->slug . '/boost-sales-cart',
            'frequently-product' => '/' . $store->slug . '/frequently_product_cart',
        ];
    }

    private function ajax(string $uri, array $body)
    {
        return $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
            ->post($uri, $body);
    }

    // =====================================================================================
    //  PREMISE. Without these the whole file is vacuous.
    // =====================================================================================

    public function test_the_premise_neither_route_exists_until_its_module_is_enabled(): void
    {
        $this->assertFalse(Route::has('boost.sales.cart.product.data'),
            'The BoostSales cart route exists before the addon was enabled, so this suite would not be testing the real surface.');
        $this->assertFalse(Route::has('cart.product-data'),
            'The FrequentlyProduct cart route exists before the addon was enabled.');

        $this->enableModules();

        $this->assertTrue(Route::has('boost.sales.cart.product.data'));
        $this->assertTrue(Route::has('cart.product-data'));
    }

    /**
     * The guard keys on `strtolower($route->getActionName())`. If either name drifts the guard
     * goes inert and every negative test below passes because nothing is reachable rather than
     * because something is refused.
     */
    public function test_the_premise_the_two_routes_resolve_to_the_guarded_actions(): void
    {
        $this->enableModules();

        $guarded = (new \ReflectionClassConstant(\App\Http\Middleware\ModuleCartWriteGuard::class, 'TARGETS'))->getValue();

        foreach (self::ACTIONS as $uriFragment => $expected) {
            $route = collect(Route::getRoutes()->getRoutes())
                ->first(fn ($r) => str_contains($r->uri(), $uriFragment) && in_array('POST', $r->methods(), true));

            $this->assertNotNull($route, "No POST route matching '{$uriFragment}' resolved.");
            $this->assertSame($expected, strtolower($route->getActionName()),
                "The resolved action for '{$uriFragment}' is not the name the guard keys on.");
            $this->assertArrayHasKey($expected, $guarded,
                "'{$expected}' resolves but is not in ModuleCartWriteGuard::TARGETS.");
            // gatherMiddleware() returns DECLARED names unexpanded -- `['web']` here -- which is
            // the same property that makes the registration survive `route:cache`. So the two
            // halves are asserted separately: the route is in the group, and the group holds the
            // guard. Asserting the class directly would fail for a route that really is guarded.
            $this->assertContains('web', $route->gatherMiddleware(),
                "'{$uriFragment}' is not in the `web` group, so the appended guard never runs on it.");
            $this->assertContains(\App\Http\Middleware\ModuleCartWriteGuard::class,
                app(\Illuminate\Routing\Router::class)->getMiddlewareGroups()['web'] ?? [],
                'The guard is not appended to the `web` group.');
        }

        $this->assertCount(count(self::ACTIONS), $guarded,
            'TARGETS holds an entry this premise test does not cover.');
    }

    /**
     * THE LIVENESS CONTROL. A guard that refused every request would satisfy every negative test
     * in this file, and the unguarded handler 500s on one of the shapes below, so "no row" is not
     * evidence on its own.
     */
    public function test_a_legitimate_add_to_cart_still_writes_its_row(): void
    {
        $this->enableModules();
        [, $store, $productId] = $this->storeWithProduct('Own product', 25.0);

        foreach ($this->uris($store) as $label => $uri) {
            DB::table('carts')->delete();

            // `variant_id` IS POSTED, and it has to be: `carts.variant_id` is NOT NULL while the
            // guest branch passes `$request->variant_id` straight through, so a request that
            // omits it is a QueryException -- measured, with the guard registration removed. That
            // is a pre-existing handler defect outside this package's scope (the shipped form
            // always sends the field); it is recorded here because a positive fixture that
            // omitted it would look like the guard had broken the endpoint. `0` is the form's own
            // "no variant" value and the column's default.
            $response = $this->ajax($uri, [
                'product_ids' => [$productId],
                'enable_product' => [$productId],
                'variant_id' => 0,
            ]);

            $this->assertNotSame(403, $response->getStatusCode(), "{$label}: the guard refused a legitimate request.");
            $this->assertNotSame(404, $response->getStatusCode(), "{$label}: the route did not resolve.");
            $this->assertNotSame(500, $response->getStatusCode(), "{$label}: the handler failed.");

            $row = DB::table('carts')->first();
            $this->assertNotNull($row, "{$label}: a legitimate add-to-cart wrote no row, so every negative assertion here is vacuous.");
            $this->assertSame($productId, (int) $row->product_id, "{$label}: the wrong product was carted.");
            $this->assertSame((int) $store->id, (int) $row->store_id);
        }
    }

    // =====================================================================================
    //  THE DEFECT
    // =====================================================================================

    /** 1. CROSS-TENANT: another store's product, carted under this store at that store's price. */
    public function test_another_stores_product_cannot_be_carted(): void
    {
        $this->enableModules();
        [, $victimStore, $victimProduct] = $this->storeWithProduct('Victim product', 999.0);
        [, $attackerStore] = $this->storeWithProduct('Attacker product', 1.0);

        $this->assertNotSame($victimStore->id, $attackerStore->id, 'Fixture premise: two distinct stores.');

        foreach ($this->uris($attackerStore) as $label => $uri) {
            DB::table('carts')->delete();

            $response = $this->ajax($uri, [
                'product_ids' => [$victimProduct],
                'enable_product' => [$victimProduct],
            ]);

            $this->assertSame(403, $response->getStatusCode(), "{$label}: a foreign product was accepted.");
            $this->assertSame(0, DB::table('carts')->count(), "{$label}: a cart row referencing another store's product was written.");
        }
    }

    /** The same, hidden among the caller's own products: ALL, not ANY, and the whole call refused. */
    public function test_a_foreign_product_mixed_with_an_owned_one_refuses_the_whole_request(): void
    {
        $this->enableModules();
        [, $victimStore, $victimProduct] = $this->storeWithProduct('Victim product', 999.0);
        [, $attackerStore, $ownProduct] = $this->storeWithProduct('Attacker product', 1.0);
        $this->assertNotSame($victimStore->id, $attackerStore->id);

        foreach ($this->uris($attackerStore) as $label => $uri) {
            DB::table('carts')->delete();

            $response = $this->ajax($uri, [
                'product_ids' => [$ownProduct, $victimProduct],
                'enable_product' => [$ownProduct],
            ]);

            $this->assertSame(403, $response->getStatusCode(), "{$label}: a mixed list was accepted.");
            $this->assertSame(0, DB::table('carts')->count(),
                "{$label}: the request was filtered rather than refused -- the handler re-reads the raw input, so a narrowed list authorises a subset while the handler acts on the whole of it.");
        }
    }

    /**
     * 2. An id matching no product. MEASURED as a 500 from both handlers (the guest branch has no
     * `if (!$product)`, so `$product->final_price` reads a property on null), which is exactly
     * why this asserts the 403 and not merely the absence of a row.
     */
    public function test_a_product_that_does_not_exist_is_refused_rather_than_a_server_error(): void
    {
        $this->enableModules();
        [, $store] = $this->storeWithProduct('Own product', 25.0);

        foreach ($this->uris($store) as $label => $uri) {
            DB::table('carts')->delete();

            $response = $this->ajax($uri, [
                'product_ids' => [999999],
                'enable_product' => [999999],
            ]);

            $this->assertSame(403, $response->getStatusCode(),
                "{$label}: a nonexistent product was not refused. Without the guard this is a 500, so the "
                . 'refusal -- not the empty table -- is the evidence.');
            $this->assertSame(0, DB::table('carts')->count(), "{$label}: a cart row was written for a product that does not exist.");
        }
    }

    /** 3. A NEGATIVE QUANTITY writes a negative line price -- a credit against the rest of the basket. */
    public function test_a_non_positive_or_fractional_quantity_is_refused(): void
    {
        $this->enableModules();
        [, $store, $productId] = $this->storeWithProduct('Own product', 25.0);

        foreach ($this->uris($store) as $label => $uri) {
            foreach (['-3', '0', '1.5', 'abc'] as $qty) {
                DB::table('carts')->delete();

                $response = $this->ajax($uri, [
                    'product_ids' => [$productId],
                    'enable_product' => [$productId],
                    'qty' => $qty,
                ]);

                $this->assertSame(403, $response->getStatusCode(), "{$label}: qty '{$qty}' was accepted.");
                $this->assertSame(0, DB::table('carts')->count(), "{$label}: qty '{$qty}' wrote a cart row.");
            }
        }
    }

    /** 4. `in_array($x, null)` is a TypeError on PHP 8, so this was a 500 and not a refusal. */
    public function test_a_missing_product_ids_list_is_refused_rather_than_a_server_error(): void
    {
        $this->enableModules();
        [, $store, $productId] = $this->storeWithProduct('Own product', 25.0);

        foreach ($this->uris($store) as $label => $uri) {
            DB::table('carts')->delete();

            $response = $this->ajax($uri, ['enable_product' => [$productId]]);

            $this->assertSame(403, $response->getStatusCode(), "{$label}: expected a refusal, got " . $response->getStatusCode());
            $this->assertSame(0, DB::table('carts')->count());
        }
    }

    /** The variant is a SECOND unscoped read, and the customer branch prices the line from it. */
    public function test_another_stores_product_variant_cannot_be_used(): void
    {
        $this->enableModules();
        [, $victimStore, $victimProduct] = $this->storeWithProduct('Victim product', 999.0);
        [, $attackerStore, $ownProduct] = $this->storeWithProduct('Attacker product', 1.0);

        $foreignVariant = (int) DB::table('product_variants')->insertGetId([
            'product_id' => $victimProduct,
            'store_id' => $victimStore->id,
            'variant' => 'Large',
            'sku' => 'SKU-' . uniqid(),
            'price' => 999.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($this->uris($attackerStore) as $label => $uri) {
            foreach (['variant_id', 'attribute_id'] as $key) {
                DB::table('carts')->delete();

                $response = $this->ajax($uri, [
                    'product_ids' => [$ownProduct],
                    'enable_product' => [$ownProduct],
                    $key => $foreignVariant,
                ]);

                $this->assertSame(403, $response->getStatusCode(), "{$label}/{$key}: a foreign variant was accepted.");
                $this->assertSame(0, DB::table('carts')->count(), "{$label}/{$key}: a cart row was written against another store's variant.");
            }
        }
    }

    /** An unknown slug: the handlers do `getStore($slug)->id` with no null guard, writing store_id NULL. */
    public function test_an_unknown_store_slug_is_refused(): void
    {
        $this->enableModules();
        [, $store, $productId] = $this->storeWithProduct('Own product', 25.0);

        foreach (['boost-sales-cart', 'frequently_product_cart'] as $path) {
            DB::table('carts')->delete();

            $response = $this->ajax('/no-such-store-' . uniqid() . '/' . $path, [
                'product_ids' => [$productId],
                'enable_product' => [$productId],
            ]);

            $this->assertSame(403, $response->getStatusCode(), "{$path}: an unknown slug was accepted.");
            $this->assertSame(0, DB::table('carts')->count(), "{$path}: a cart row with a NULL store_id was written.");
        }
    }

    /**
     * A SIGNED-IN SHOPPER TAKES THE OTHER BRANCH, and it is the branch with the `qty` multiplier
     * and the variant pricing -- so driving only the anonymous one would leave half the handler
     * unexercised. The project skill records this as the pattern that produced the most defects:
     * a test that drives one principal proves nothing about the other.
     */
    public function test_a_signed_in_shopper_cannot_cart_another_stores_product_either(): void
    {
        $this->enableModules();
        [, $victimStore, $victimProduct] = $this->storeWithProduct('Victim product', 999.0);
        [, $attackerStore] = $this->storeWithProduct('Attacker product', 1.0);
        $this->assertNotSame($victimStore->id, $attackerStore->id);

        $shopper = Customer::forceCreate([
            'first_name' => 'Shop', 'last_name' => 'Per', 'email' => 'shopper' . uniqid() . '@example.test',
            'password' => bcrypt('secret-not-a-real-password'), 'store_id' => $attackerStore->id, 'status' => 1,
        ]);

        foreach ($this->uris($attackerStore) as $label => $uri) {
            DB::table('carts')->delete();

            // The customer branch reads `substr($str, strpos($str, '_') + 1)`, so the shipped form
            // posts a prefixed value. Driving the raw form here as well would exercise the
            // first-character-drop reading, which the guard deliberately does not validate -- see
            // the guard's docblock.
            $response = $this->actingAs($shopper, 'customers')->withHeaders([
                'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json',
            ])->post($uri, [
                'product_ids' => [$victimProduct],
                'enable_product' => ['product_' . $victimProduct],
            ]);

            $this->assertSame(403, $response->getStatusCode(), "{$label}: a signed-in shopper carted a foreign product.");
            $this->assertSame(0, DB::table('carts')->count(), "{$label}: a cart row referencing another store's product was written.");
        }
    }

    /** Nothing chosen is the handler's own validation message, not an authorization failure. */
    public function test_an_empty_selection_is_left_to_the_handlers_own_message(): void
    {
        $this->enableModules();
        [, $store, $productId] = $this->storeWithProduct('Own product', 25.0);

        foreach ($this->uris($store) as $label => $uri) {
            $response = $this->ajax($uri, ['product_ids' => [$productId]]);

            $this->assertNotSame(403, $response->getStatusCode(),
                "{$label}: an empty selection was answered as an authorization failure. Telling a shopper "
                . "'permission denied' for a mis-click is the live UI defect LotterySettingProductOwnershipGuard was corrected for.");
        }
    }

    /** The guard must be inert everywhere else: it is appended to the whole `web` group. */
    public function test_the_guard_is_inert_on_an_unrelated_storefront_route(): void
    {
        $this->enableModules();
        [, $store] = $this->storeWithProduct('Own product', 25.0);

        $response = $this->get('/' . $store->slug . '/login');

        $this->assertNotSame(403, $response->getStatusCode(),
            'The guard refused an unrelated storefront route, so it is not keyed on the action after all.');
    }

    // =====================================================================================
    //  TWO BYPASSES OF THIS GUARD, found by the independent read-only review AFTER it was
    //  green, and both REPRODUCED before being fixed. They are in this file rather than in a
    //  follow-up because a guard's own suite is where its bypasses belong.
    // =====================================================================================

    /**
     * F1 -- A JSON BOOLEAN IN `product_ids` DEFEATED THE WHOLE PRODUCT CHECK. LIVE, measured:
     *
     *   POST /{A-slug}/frequently_product_cart   (JSON)
     *   {"product_ids":[true],"enable_product":["<B's product id>"],"variant_id":0}
     *     -> 200, carts store_id=1 (A)  product_id=2 (B's)  price=999 (B's catalogue price)
     *
     * from BOTH routes. The two sides disagreed about what `true` means: the shared predicate
     * stringifies each member, `(string) true` is `'1'`, so the guard asked about product 1 --
     * which the slug's own store owns -- and allowed it; the handler's gate is
     * `in_array($product_id, $product_ids)`, loose, and on PHP 8 a bool on either side forces a
     * bool comparison, so `"42" == true` is TRUE and every truthy id passed.
     *
     * THE FIXTURE DEPENDS ON STORE A OWNING `products.id = 1`, which is why the attacker's store
     * is created FIRST here and the order is asserted rather than assumed. That is also the
     * bypass's own precondition: the attacker picks the slug, so they pick the store that owns
     * product 1.
     *
     * ONLY A JSON CALLER CAN DO THIS -- form-encoded input is always strings -- which is why the
     * first round of measurements on this guard, which posted forms, showed it working.
     */
    public function test_a_json_boolean_in_product_ids_cannot_cart_another_stores_product(): void
    {
        $this->enableModules();
        [, $attackerStore, $ownProduct] = $this->storeWithProduct('Attacker product', 1.0);
        [, $victimStore, $victimProduct] = $this->storeWithProduct('Victim product', 999.0);

        $this->assertSame(1, $ownProduct,
            'Fixture premise: the attacker must own products.id = 1, because (string) true is \'1\' '
            . 'and that is the row the guard was tricked into asking about.');
        $this->assertNotSame($victimStore->id, $attackerStore->id);

        foreach ($this->uris($attackerStore) as $label => $uri) {
            DB::table('carts')->delete();

            $response = $this->postJson($uri, [
                'product_ids' => [true],
                'enable_product' => [(string) $victimProduct],
                'variant_id' => 0,
            ]);

            $this->assertSame(403, $response->getStatusCode(), "{$label}: a JSON boolean member was accepted.");
            $this->assertSame(0, DB::table('carts')->count(),
                "{$label}: a cart row referencing another store's product was written through the boolean bypass.");
        }
    }

    /** The same hole in its quieter form: a member the shared predicate could not read was DROPPED, not refused. */
    public function test_a_non_scalar_member_of_product_ids_is_refused_rather_than_skipped(): void
    {
        $this->enableModules();
        [, $store, $ownProduct] = $this->storeWithProduct('Own product', 25.0);

        foreach ($this->uris($store) as $label => $uri) {
            foreach ([[999999], null, false, 1.5] as $i => $bad) {
                DB::table('carts')->delete();

                $response = $this->postJson($uri, [
                    'product_ids' => [$ownProduct, $bad],
                    'enable_product' => [(string) $ownProduct],
                    'variant_id' => 0,
                ]);

                $this->assertSame(403, $response->getStatusCode(),
                    "{$label}: member #{$i} was dropped rather than refused. Dropping a value you cannot read "
                    . 'authorises a subset while the handler acts on the whole list.');
            }
        }
    }

    /**
     * F2a -- THE GUARD READ `input()`; THE HANDLERS READ `all()`. A FILE SKIPPED IT ENTIRELY.
     *
     * `all()` is `array_replace_recursive($this->input(), $this->allFiles())`, so a file is in
     * `all()` and NOT in `input()`. A multipart POST with a file field named `enable_product[0]`
     * and no text field of that name gave the guard null, took the empty-selection early return,
     * and ran NO check at all while the handler saw a truthy array.
     *
     * MEASURED BEFORE THE FIX: 200, and NO cart row -- the handler then uses the UploadedFile as
     * a product id and finds nothing. So this was a bypassed control rather than a live
     * cross-tenant write, and this test asserts the CONTROL (a 403), not the empty table, because
     * the empty table held either way. That is the first of the four vacuous-pass traps.
     */
    public function test_a_file_named_enable_product_does_not_skip_the_guard(): void
    {
        $this->enableModules();
        [, $victimStore, $victimProduct] = $this->storeWithProduct('Victim product', 999.0);
        [, $attackerStore] = $this->storeWithProduct('Attacker product', 1.0);
        $this->assertNotSame($victimStore->id, $attackerStore->id);

        foreach ($this->uris($attackerStore) as $label => $uri) {
            DB::table('carts')->delete();

            $response = $this->call('POST', $uri,
                ['product_ids' => [(string) $victimProduct]],
                [],
                ['enable_product' => [\Illuminate\Http\UploadedFile::fake()->create('x.txt', 1)]],
                $this->transformHeadersToServerVars(['Accept' => 'application/json'])
            );

            $this->assertSame(403, $response->getStatusCode(),
                "{$label}: a file-valued enable_product skipped the guard entirely -- no product check, "
                . 'no store check, no quantity check.');
            $this->assertSame(0, DB::table('carts')->count());
        }
    }

    /**
     * F3 -- WHAT THE TWO HANDLERS ACTUALLY DO, pinned because the guard's first docblock said
     * they "differ only in their success strings" and called that a measurement. It was not.
     *
     * MEASURED: BoostSales' braces mis-nest -- `:63`'s `if (! auth('customers')->user()) {` has no
     * `else`, and `:108`'s `} else {` closes the extra `if ($enable_product) {` wrapper at `:66`
     * that FrequentlyProduct does not have. So a signed-in customer on `boost-sales-cart` gets an
     * EMPTY 200 and no row, and the same request to `frequently_product_cart` reaches the
     * customer branch.
     *
     * This test exists so the correction cannot quietly rot back: it is the evidence for two
     * statements in the guard's docblock, and for the fact that BoostSales' cross-tenant write is
     * live on the GUEST branch only.
     */
    public function test_boost_sales_has_no_reachable_customer_branch_and_frequently_product_does(): void
    {
        $this->enableModules();
        [, $store, $productId] = $this->storeWithProduct('Own product', 25.0);

        $shopper = Customer::forceCreate([
            'first_name' => 'Shop', 'last_name' => 'Per', 'email' => 'shopper' . uniqid() . '@example.test',
            'password' => bcrypt('secret-not-a-real-password'), 'store_id' => $store->id, 'status' => 1,
        ]);

        $uris = $this->uris($store);

        $boost = $this->actingAs($shopper, 'customers')
            ->postJson($uris['boost-sales'], ['product_ids' => [$productId], 'enable_product' => ['x_' . $productId], 'variant_id' => 0, 'qty' => 1]);

        $this->assertSame('', trim($boost->getContent()),
            'BoostSales answered a signed-in customer with a body, so its customer branch IS reachable and the '
            . "guard's docblock -- which says it is not -- must be re-measured.");

        $frequently = $this->actingAs($shopper, 'customers')
            ->postJson($uris['frequently-product'], ['product_ids' => [$productId], 'enable_product' => ['x_' . $productId], 'variant_id' => 0, 'qty' => 1]);

        $this->assertNotSame('', trim($frequently->getContent()),
            'FrequentlyProduct answered nothing, so the asymmetry this test records is gone.');
    }
}
