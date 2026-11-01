<?php

namespace Tests\Feature\Merchant;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Shipping;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\Store;
use App\Models\TaxMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * F263 — THE FOUR SURFACES THAT STILL CARRIED F258's SHAPE.
 * =================================================================================================
 *
 * The F258 review recorded four more places with the same unscoped resolution and then — correctly
 * — declined to widen F258's own tests over them, because each wants its own change. This file is
 * those tests.
 *
 * | # | surface | what was wrong |
 * |---|---|---|
 * | 1 | `ShippingController::edit/update/destroy` | bare `Shipping::find($id)`; `edit` had NO permission check at all |
 * | 2 | `CartController::cart_list_sidebar` | bare `ShippingMethod::find($request->method_id)` |
 * | 3 | `Api\ApiController::delivery_charge` | the same, on a REQUIRED caller-supplied `method_id` |
 * | 4 | `TaxMethodController::edit/update/destroy` | bare find PLUS `store_id = getCurrentStore()` — row theft |
 *
 * None of the four models is in `config/tenancy.php`'s `scoped_models` — `Shipping`,
 * `ShippingMethod` and `TaxMethod` are all absent, and `TenantModelClassificationTest` forbids
 * adding `Shipping` — so `StoreScope::isActiveFor()` short-circuits and NO predicate is added for
 * ANY principal. The handler's own `where` is the only control there has ever been, and there was
 * none.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * THE FOUR WAYS A TEST LIKE THIS PASSES FOR THE WRONG REASON, and what is done about each
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * 1. **THE ROUTER NEVER REACHED THE HANDLER.** `Route::resource` gives PUT|PATCH for update and
 *    DELETE for destroy; a POST matches no route, so every "the victim's row is unchanged"
 *    assertion would pass while the request was being rejected by the router — the gate under test
 *    never executed. That happened in the F258 file and cost a whole run. `assertReachedHandler()`
 *    refuses 405 and 500, and **there is an allow-path case for every one of the four surfaces**,
 *    which is what actually proves the request shape is right.
 *
 * 2. **THE ASSERTION ANSWERED AGAINST THE WRONG TENANT.** Every read-back of the victim's row goes
 *    through `withoutGlobalScopes()`. A plain `find()` in a test answers against whichever tenant
 *    happens to be resolved — exactly the situation a cross-store test constructs — and this
 *    repository has already had a cart test report a store-B row deleted while the raw table still
 *    held it.
 *
 * 3. **THE MODEL CACHE SERVED THE PRE-WRITE ROW.** All three models are `Cachable`, and under
 *    `CACHE_STORE=array` a stale row survives for the rest of the process; three measured false
 *    readings in this project. `flushCache()` before every read-back.
 *
 * 4. **THE FIX STOPPED THE EDIT BUT NOT THE THEFT.** `store_id` is asserted separately from the
 *    field being written, because a change that kept the victim's `tax_rate` while still moving
 *    the row into the attacker's store is a different defect wearing the same green tick. That is
 *    what F258's three writes did, and `TaxMethodController::update` did it too.
 */
class ShippingClassAndTaxMethodTenancyTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchantA;

    private Store $storeA;

    private User $merchantB;

    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->merchantA, $this->storeA] = $this->shippingTenant();
        [$this->merchantB, $this->storeB] = $this->shippingTenant();
    }

    // ---------------------------------------------------------------------------------------
    //  FIXTURES
    // ---------------------------------------------------------------------------------------

    /**
     * A merchant holding every permission these handlers check, with `current_store` set.
     *
     * All four are granted to ONE role, so a cross-tenant case is genuinely about tenancy and not
     * about the caller lacking the permission — a refusal for the wrong reason is still a pass.
     *
     * @return array{0: User, 1: Store}
     */
    private function shippingTenant(): array
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant, ['default_language' => 'en']);
        $merchant->current_store = $store->id;
        $merchant->save();

        $user = User::find($merchant->id);

        foreach ([
            'Manage Shipping Class', 'Create Shipping Class', 'Edit Shipping Class',
            'Delete Shipping Class',
            'Create Tax Method', 'Edit Tax Method', 'Delete Tax Method',
        ] as $permission) {
            $this->grantPermission($user, $permission);
        }

        return [User::find($merchant->id), $store];
    }

    private function shippingClassIn(Store $store, string $name): Shipping
    {
        $row = Shipping::create([
            'name' => $name,
            'slug' => str_replace(' ', '-', strtolower($name)),
            'description' => $name . ' description',
            'store_id' => $store->id,
        ]);
        (new Shipping)->flushCache();

        return $row;
    }

    /**
     * `store_id` is NOT in TaxMethod's `$fillable`, so it is assigned rather than mass-set.
     *
     * AND `tax_id` CARRIES A REAL FOREIGN KEY to `taxes` (the 2023_12_25 migration adds four, to
     * `taxes`, `countries`, `states` and `cities`). A first draft passed `tax_id = 0` and SQLite
     * refused the insert with `FOREIGN KEY constraint failed`, so the parent row is created here
     * rather than an id being assumed to exist. `country/state/city` stay NULL, which the columns
     * permit and the controller writes as NULL anyway (`$request->country_id ?: null`).
     */
    private function taxMethodIn(Store $store, string $name, string $rate): TaxMethod
    {
        $taxId = (int) \DB::table('taxes')->insertGetId([
            'name' => 'Tax group ' . uniqid(),
            'store_id' => $store->id,
        ]);

        $row = new TaxMethod();
        $row->name = $name;
        $row->tax_rate = $rate;
        $row->tax_id = $taxId;
        $row->priority = 1;
        $row->enable_shipping = 0;
        $row->store_id = $store->id;
        $row->save();
        (new TaxMethod)->flushCache();

        return $row;
    }

    /**
     * `shipping_methods.zone_id` carries a real foreign key to `shipping_zones`, which in turn has
     * foreign keys to `countries` and `states` — neither seeded in this suite. Mirrors the F258
     * fixture, which found this the hard way.
     */
    private function shippingMethodIn(Store $store, string $name, string $cost): ShippingMethod
    {
        $countryId = (int) \DB::table('countries')->insertGetId(['name' => 'Testland ' . uniqid()]);
        $stateId = (int) \DB::table('states')->insertGetId([
            'name' => 'Teststate ' . uniqid(),
            'country_id' => $countryId,
        ]);

        $zone = ShippingZone::create([
            'zone_name' => $name . ' zone',
            'country_id' => $countryId,
            'state_id' => $stateId,
            'shipping_method' => '1',
            'store_id' => $store->id,
        ]);

        $method = ShippingMethod::create([
            'method_name' => $name,
            'zone_id' => $zone->id,
            'cost' => $cost,
            'calculation_type' => 'fixed',
            'shipping_requires' => '1',
            'store_id' => $store->id,
        ]);
        (new ShippingMethod)->flushCache();

        return $method;
    }

    private function customerIn(Store $store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Shopper',
            'last_name' => 'F263',
            'email' => 'f263-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id,
            'status' => 1,
            'register_type' => 'email',
        ]);
    }

    private function cartRowIn(Store $store, Customer $customer): Cart
    {
        $product = Product::forceCreate([
            'name' => 'f263-product-' . uniqid(),
            'slug' => 'f263-product-' . uniqid(),
            'product_stock' => 50,
            'track_stock' => 0,
            'variant_product' => 0,
            'price' => 10,
            'sale_price' => 10,
            'store_id' => $store->id,
            'created_by' => $store->created_by,
        ]);

        return Cart::forceCreate([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'variant_id' => 0,
            'qty' => 2,
            'price' => 20,
            'store_id' => $store->id,
        ]);
    }

    // ---------------------------------------------------------------------------------------
    //  SHARED ASSERTIONS
    // ---------------------------------------------------------------------------------------

    /**
     * The comment-stripped source of ONE method, bounded by the signature that follows it.
     *
     * Bounded rather than file-wide on purpose: `ApiController` is 7,000+ lines and resolves a
     * `ShippingMethod` in several handlers, so a file-wide assertion would be satisfied by a
     * predicate in a DIFFERENT method than the one under test — a ratchet that passes because some
     * other code is correct.
     */
    private function methodBody(string $file, string $from, string $until): string
    {
        $code = $this->withoutComments((string) file_get_contents($file));

        $start = strpos($code, $from);

        if ($start === false) {
            return '';
        }

        $end = strpos($code, $until, $start);

        return substr($code, $start, $end === false ? 6000 : $end - $start);
    }

    /** Blank out `//`, `#`, `/* *\/` and docblock content, preserving the line count. */
    private function withoutComments(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $out .= str_repeat("\n", substr_count($token[1], "\n"));

                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    private function assertReachedHandler(\Illuminate\Testing\TestResponse $response, string $what): void
    {
        $this->assertNotSame(405, $response->getStatusCode(),
            "{$what}: the router rejected the verb, so the handler never ran and whatever this "
            . 'test asserts afterwards is vacuous');
        $this->assertNotSame(500, $response->getStatusCode(),
            "{$what}: the handler raised a 500 — " . substr(strip_tags($response->getContent() ?: ''), 0, 400));
    }

    /** Store B's shipping class as the DATABASE holds it, with no scope and no cache in the way. */
    private function victimClass(Shipping $row): Shipping
    {
        (new Shipping)->flushCache();

        return Shipping::withoutGlobalScopes()->findOrFail($row->id);
    }

    private function victimTax(TaxMethod $row): TaxMethod
    {
        (new TaxMethod)->flushCache();

        return TaxMethod::withoutGlobalScopes()->findOrFail($row->id);
    }

    // =======================================================================================
    //  SURFACE 1 — ShippingController
    // =======================================================================================

    public function test_another_tenant_cannot_rewrite_this_stores_shipping_class(): void
    {
        $victim = $this->shippingClassIn($this->storeB, 'Store B heavy goods');

        $response = $this->actingAs($this->merchantA)
            ->put(route('shipping.update', $victim->id), [
                'name' => 'Taken by A',
                'description' => 'Taken by A',
            ]);
        $this->assertReachedHandler($response, 'cross-tenant shipping-class update');

        $this->assertSame('Store B heavy goods', (string) $this->victimClass($victim)->name,
            "store A rewrote store B's shipping class. `Shipping` is EXCLUDED from scoped_models, "
            . 'so the handler predicate is the only control.');
    }

    public function test_another_tenant_cannot_delete_this_stores_shipping_class(): void
    {
        $victim = $this->shippingClassIn($this->storeB, 'Store B fragile');

        $response = $this->actingAs($this->merchantA)
            ->delete(route('shipping.destroy', $victim->id));
        $this->assertReachedHandler($response, 'cross-tenant shipping-class destroy');

        (new Shipping)->flushCache();
        $this->assertTrue(
            Shipping::withoutGlobalScopes()->whereKey($victim->id)->exists(),
            "store A deleted store B's shipping class outright"
        );
    }

    public function test_another_tenant_cannot_read_this_stores_shipping_class_form(): void
    {
        $victim = $this->shippingClassIn($this->storeB, 'Store B secret class');

        $response = $this->actingAs($this->merchantA)
            ->get(route('shipping.edit', $victim->id));
        $this->assertReachedHandler($response, 'cross-tenant shipping-class edit');

        $this->assertStringNotContainsString('Store B secret class', $response->getContent() ?: '',
            "the edit form rendered another tenant's shipping class. Before this change `edit()` "
            . 'had no permission check AND no predicate.');
    }

    /**
     * THE ALLOW PATH, and it is the case that makes the three above mean anything.
     *
     * Without it a fix that refused EVERY request — or a request shape the router rejects — would
     * leave all three refusals green while the screen was broken for its owner.
     */
    public function test_a_merchant_can_still_edit_and_delete_their_own_shipping_class(): void
    {
        $own = $this->shippingClassIn($this->storeA, 'Store A standard');

        $edit = $this->actingAs($this->merchantA)->get(route('shipping.edit', $own->id));
        $this->assertReachedHandler($edit, 'own shipping-class edit');
        $this->assertStringContainsString('Store A standard', $edit->getContent() ?: '',
            'a merchant can no longer open their OWN shipping class — the fix over-refused');

        $update = $this->actingAs($this->merchantA)
            ->put(route('shipping.update', $own->id), [
                'name' => 'Store A renamed',
                'description' => 'Store A renamed',
            ]);
        $this->assertReachedHandler($update, 'own shipping-class update');
        $this->assertSame('Store A renamed', (string) $this->victimClass($own)->name,
            'a merchant can no longer rename their OWN shipping class');

        /* AND THE ROW DID NOT MOVE. `store_id` is no longer written on save; assert it held. */
        $this->assertSame((int) $this->storeA->id, (int) $this->victimClass($own)->store_id,
            "a merchant's own shipping class changed store on an ordinary save");

        $destroy = $this->actingAs($this->merchantA)->delete(route('shipping.destroy', $own->id));
        $this->assertReachedHandler($destroy, 'own shipping-class destroy');
        (new Shipping)->flushCache();
        $this->assertFalse(Shipping::withoutGlobalScopes()->whereKey($own->id)->exists(),
            'a merchant can no longer delete their OWN shipping class');
    }

    /**
     * `edit()` HAD NO PERMISSION CHECK OF ANY KIND, so this is the half of surface 1 that is about
     * permission rather than tenancy: a staff member whose role grants nothing relevant could open
     * the form and read the store's shipping classes.
     */
    public function test_a_staff_member_without_the_permission_cannot_open_the_shipping_class_form(): void
    {
        $own = $this->shippingClassIn($this->storeA, 'Store A standard');

        $staff = $this->makeStaff($this->merchantA);
        $staff->current_store = $this->storeA->id;
        $staff->save();
        $this->grantPermission(User::find($staff->id), 'Manage Blog');

        $response = $this->actingAs(User::find($staff->id))
            ->get(route('shipping.edit', $own->id));
        $this->assertReachedHandler($response, 'unpermitted staff shipping-class edit');

        $this->assertStringNotContainsString('Store A standard', $response->getContent() ?: '',
            'a staff member holding only Manage Blog read the store\'s shipping classes. Before '
            . 'this change `edit()` checked no permission at all.');
    }

    /**
     * CREATE STILL WORKS ON BOTH CONTROLLERS, and this case exists because the change moved
     * `store()`'s gate too.
     *
     * Both `store()` handlers previously opened with a bare `isAbleTo('Create …')` and now go
     * through the shared write gate, which adds `storeMustResolve()`. That is a tightening in the
     * right direction — a principal with no resolvable store used to be able to insert a row with
     * `store_id = NULL`, an orphan that `where('store_id', null)` would later match as `IS NULL` —
     * but a gate that refuses a NORMAL merchant would take away the ability to create a shipping
     * class or a tax method at all. Nothing else in this file drives `store()`, so without this
     * case that regression would ship green.
     */
    public function test_a_merchant_can_still_create_a_shipping_class_and_a_tax_method(): void
    {
        $shipping = $this->actingAs($this->merchantA)->post(route('shipping.store'), [
            'name' => 'Brand new class',
            'description' => 'Brand new class',
        ]);
        $this->assertReachedHandler($shipping, 'own shipping-class store');

        (new Shipping)->flushCache();
        $created = Shipping::withoutGlobalScopes()->where('name', 'Brand new class')->first();
        $this->assertNotNull($created,
            'a merchant can no longer CREATE a shipping class — the shared write gate refuses a '
            . 'normal merchant, which is a regression the tenancy cases cannot see');
        $this->assertSame((int) $this->storeA->id, (int) $created->store_id,
            'a newly created shipping class carries the wrong store, or none');

        $taxId = (int) \DB::table('taxes')->insertGetId([
            'name' => 'Tax group ' . uniqid(),
            'store_id' => $this->storeA->id,
        ]);

        $tax = $this->actingAs($this->merchantA)->post(route('taxes-method.store'), [
            'name' => 'Brand new levy',
            'tax_rate' => '9.00',
            'tax_id' => $taxId,
            'priority' => 1,
        ]);
        $this->assertReachedHandler($tax, 'own tax-method store');

        (new TaxMethod)->flushCache();
        $createdTax = TaxMethod::withoutGlobalScopes()->where('name', 'Brand new levy')->first();
        $this->assertNotNull($createdTax,
            'a merchant can no longer CREATE a tax method');
        $this->assertSame((int) $this->storeA->id, (int) $createdTax->store_id,
            'a newly created tax method carries the wrong store, or none. `store()` is the one '
            . 'handler that SHOULD write store_id, because the row does not exist yet.');
    }

    // =======================================================================================
    //  SURFACE 4 — TaxMethodController
    // =======================================================================================

    public function test_another_tenant_cannot_rewrite_this_stores_tax_rate(): void
    {
        $victim = $this->taxMethodIn($this->storeB, 'Store B VAT', '20.00');

        $response = $this->actingAs($this->merchantA)
            ->put(route('taxes-method.update', $victim->id), [
                'name' => 'Zero rated by A',
                'tax_rate' => '0.00',
                'priority' => 1,
            ]);
        $this->assertReachedHandler($response, 'cross-tenant tax update');

        $this->assertSame('20.00', (string) $this->victimTax($victim)->tax_rate,
            "store A rewrote store B's TAX RATE. `tax_rate` feeds the cart's tax calculation, so "
            . "this changed what store B's shoppers are charged.");
    }

    /**
     * THE WORSE HALF, AND THE ONE A COST-ONLY ASSERTION WOULD MISS. `update()` ended with
     * `$taxmethod->store_id = getCurrentStore()`, so the row did not merely get edited — it
     * changed owner, and store B's tax method disappeared from store B's settings.
     */
    public function test_another_tenant_cannot_move_this_stores_tax_method_into_their_own(): void
    {
        $victim = $this->taxMethodIn($this->storeB, 'Store B VAT', '20.00');

        $response = $this->actingAs($this->merchantA)
            ->put(route('taxes-method.update', $victim->id), [
                'name' => 'Taken by A',
                'tax_rate' => '0.00',
                'priority' => 1,
            ]);
        $this->assertReachedHandler($response, 'cross-tenant tax store_id move');

        $this->assertSame((int) $this->storeB->id, (int) $this->victimTax($victim)->store_id,
            "store B's tax method was MOVED into store A, so it vanished from store B's own "
            . 'settings while store A kept the edited copy');
    }

    /**
     * THE `store_id` WRITE IS GONE FROM THE SOURCE, AND THIS IS A RATCHET BECAUSE NO BEHAVIOURAL
     * TEST CAN ISOLATE IT. Measured, not assumed.
     *
     * The mutation sweep for this change reverted each control in turn. Ten of eleven bit. The one
     * that did not was restoring `$taxmethod->store_id = getCurrentStore()` BY ITSELF: with the
     * predicate still in place a cross-tenant request never resolves a row, the handler returns at
     * `$taxmethod === null`, and the reassignment is unreachable. Reverting BOTH — the predicate
     * and the `store_id` line together — does fail
     * `test_another_tenant_cannot_move_this_stores_tax_method_into_their_own`, so the theft is
     * detectable; it is the line's INDEPENDENT contribution that is not.
     *
     * That makes the two controls mutually redundant here, and the honest conclusion is that with
     * the predicate in force the write really is a no-op. It is removed anyway and pinned at source
     * for one reason: it is the exact line that made F258's three writes a THEFT rather than an
     * edit, and a future change that loosens the predicate — a `findOrFail` moved earlier, a
     * refactor to route-model binding — would silently restore the worse half of the defect. A
     * ratchet costs nothing and the next reader gets the reason.
     *
     * Read from the COMMENT-STRIPPED source, because this file's own explanatory comments name the
     * line they removed.
     */
    public function test_the_tax_update_no_longer_writes_store_id_at_all(): void
    {
        $code = $this->withoutComments(
            (string) file_get_contents(app_path('Http/Controllers/TaxMethodController.php'))
        );

        $start = strpos($code, 'public function update(');
        $this->assertNotFalse($start, 'premise: TaxMethodController has no update() to read');

        $end = strpos($code, 'public function destroy(', $start);
        $this->assertNotFalse($end, 'premise: update() has no destroy() after it to bound the read');

        $body = substr($code, $start, $end - $start);

        $this->assertStringNotContainsString('store_id', $body,
            'TaxMethodController::update() writes `store_id` again. That line is what turned '
            . "F258's cross-tenant edits into row THEFT — the victim's row changed owner and "
            . 'vanished from their settings. The row is now found BY this store, so the write is '
            . 'a no-op at best and the defect at worst.');

        /* AND THE SAME FOR THE SHIPPING CLASS, whose update never had the line but could gain it. */
        $shipping = $this->withoutComments(
            (string) file_get_contents(app_path('Http/Controllers/ShippingController.php'))
        );
        $sStart = strpos($shipping, 'public function update(');
        $sEnd = strpos($shipping, 'public function destroy(', (int) $sStart);
        $this->assertNotFalse($sStart);
        $this->assertNotFalse($sEnd);

        $this->assertStringNotContainsString('store_id',
            substr($shipping, (int) $sStart, (int) $sEnd - (int) $sStart),
            'ShippingController::update() now writes `store_id`, which is how a scoped edit '
            . 'becomes a row move');
    }

    public function test_another_tenant_cannot_delete_this_stores_tax_method(): void
    {
        $victim = $this->taxMethodIn($this->storeB, 'Store B VAT', '20.00');

        $response = $this->actingAs($this->merchantA)
            ->delete(route('taxes-method.destroy', $victim->id));
        $this->assertReachedHandler($response, 'cross-tenant tax destroy');

        (new TaxMethod)->flushCache();
        $this->assertTrue(TaxMethod::withoutGlobalScopes()->whereKey($victim->id)->exists(),
            "store A deleted store B's tax method");
    }

    public function test_another_tenant_cannot_read_this_stores_tax_method_form(): void
    {
        $victim = $this->taxMethodIn($this->storeB, 'Store B secret levy', '20.00');

        $response = $this->actingAs($this->merchantA)
            ->get(route('taxes-method.edit', $victim->id));
        $this->assertReachedHandler($response, 'cross-tenant tax edit');

        $this->assertStringNotContainsString('Store B secret levy', $response->getContent() ?: '',
            "the edit form rendered another tenant's tax method, name and rate included");
    }

    public function test_a_merchant_can_still_edit_and_delete_their_own_tax_method(): void
    {
        $own = $this->taxMethodIn($this->storeA, 'Store A VAT', '15.00');

        $edit = $this->actingAs($this->merchantA)->get(route('taxes-method.edit', $own->id));
        $this->assertReachedHandler($edit, 'own tax edit');
        $this->assertStringContainsString('Store A VAT', $edit->getContent() ?: '',
            'a merchant can no longer open their OWN tax method — the fix over-refused');

        $update = $this->actingAs($this->merchantA)
            ->put(route('taxes-method.update', $own->id), [
                'name' => 'Store A VAT revised',
                'tax_rate' => '17.50',
                'priority' => 2,
            ]);
        $this->assertReachedHandler($update, 'own tax update');

        $after = $this->victimTax($own);
        $this->assertSame('17.50', (string) $after->tax_rate,
            'a merchant can no longer change their OWN tax rate');
        $this->assertSame((int) $this->storeA->id, (int) $after->store_id,
            "removing the `store_id = getCurrentStore()` line left a merchant's own tax method "
            . 'without a store');

        $destroy = $this->actingAs($this->merchantA)->delete(route('taxes-method.destroy', $own->id));
        $this->assertReachedHandler($destroy, 'own tax destroy');
        (new TaxMethod)->flushCache();
        $this->assertFalse(TaxMethod::withoutGlobalScopes()->whereKey($own->id)->exists(),
            'a merchant can no longer delete their OWN tax method');
    }

    public function test_a_staff_member_without_the_permission_cannot_open_the_tax_method_form(): void
    {
        $own = $this->taxMethodIn($this->storeA, 'Store A VAT', '15.00');

        $staff = $this->makeStaff($this->merchantA);
        $staff->current_store = $this->storeA->id;
        $staff->save();
        $this->grantPermission(User::find($staff->id), 'Manage Blog');

        $response = $this->actingAs(User::find($staff->id))
            ->get(route('taxes-method.edit', $own->id));
        $this->assertReachedHandler($response, 'unpermitted staff tax edit');

        $this->assertStringNotContainsString('Store A VAT', $response->getContent() ?: '',
            'a staff member holding only Manage Blog read the store\'s tax rates');
    }

    // =======================================================================================
    //  THE REVIEW'S OWN FINDING — `tax_id` WAS A CROSS-TENANT WRITE THE NEW GATE DID NOT COVER
    // =======================================================================================

    /** A tax group in a store, returned with its id. */
    private function taxGroupIn(Store $store, string $name): int
    {
        return (int) \DB::table('taxes')->insertGetId([
            'name' => $name,
            'store_id' => $store->id,
        ]);
    }

    /**
     * THE WORST DEFECT IN THIS WHOLE SLICE, AND IT WAS IN A HANDLER THE FIX HAD ALREADY TOUCHED.
     *
     * Found by the mandated `tenant-authz-reviewer` pass OVER THE F263 CHANGE ITSELF. The new write
     * gate answered *who may create a tax method*; `tax_id` was still taken raw from the request
     * and the row was saved under the CALLER's `store_id`. So a merchant holding `Create Tax
     * Method` — seeded on the ordinary `admin` role — could attach a tax method to ANOTHER store's
     * tax group, and `Tax::tax_methods()` selected by `tax_id` alone.
     *
     * `tax_rate` is then summed as `tax_rate * price / 100` by `Product::ProductPrice()` and
     * `Tax::TaxCount()`, so this changed what the victim's shoppers were charged — `0` to stop them
     * collecting tax at all, or a large rate to inflate every price in their storefront.
     *
     * And it was INVISIBLE: the row carries the attacker's `store_id`, so `TaxMethodDataTable`
     * filtered it out of the victim's list, and the store-scoped `destroy()` this very slice added
     * refuses it to them.
     */
    public function test_a_merchant_cannot_attach_a_tax_method_to_another_stores_tax_group(): void
    {
        $victimGroup = $this->taxGroupIn($this->storeB, 'Store B VAT group');

        $response = $this->actingAs($this->merchantA)->post(route('taxes-method.store'), [
            'name' => 'Injected by A',
            'tax_rate' => '0',
            'tax_id' => $victimGroup,
            /* The lexicographic tie-break: '' sorts before the default '0', so this row would have
               become the group's TOP priority and excluded store B's own methods entirely. */
            'priority' => '',
        ]);
        $this->assertReachedHandler($response, 'cross-tenant tax_id injection');

        (new TaxMethod)->flushCache();
        $this->assertSame(0,
            TaxMethod::withoutGlobalScopes()->where('tax_id', $victimGroup)->count(),
            "store A attached a tax method to store B's tax group. `tax_rate` feeds "
            . "Product::ProductPrice(), so this changed what store B's shoppers are charged — and "
            . "the row is invisible in store B's own list because it carries store A's store_id.");
    }

    /**
     * `tax_id` IS ASSIGNED FROM THE RESOLVED GROUP, NOT FROM THE REQUEST — a source ratchet,
     * because the mutation sweep showed no behavioural test can isolate it.
     *
     * Reverting `$taxmethod->tax_id = $group->id` to `$request->tax_id` changes NOTHING observable:
     * the group lookup above it already refuses a `tax_id` this store does not own, so on the only
     * path that reaches the assignment the two values are necessarily equal. The two controls are
     * mutually redundant, exactly as the `store_id` write and the row predicate are one file over.
     *
     * It is written as `$group->id` anyway, and pinned here, because the redundancy depends on the
     * LOOKUP staying exact. A future change that resolved the group more loosely — `first()` on a
     * different column, a `whereIn`, an `orWhere` — would make `$request->tax_id` and `$group->id`
     * diverge, and the request-sourced one is the version that reintroduces the defect. Costs a
     * line; the next reader gets the reason.
     */
    public function test_the_tax_method_create_takes_its_group_id_from_the_resolved_group(): void
    {
        $code = $this->withoutComments(
            (string) file_get_contents(app_path('Http/Controllers/TaxMethodController.php'))
        );

        $start = strpos($code, 'public function store(');
        $this->assertNotFalse($start, 'premise: TaxMethodController has no store() to read');
        $end = strpos($code, 'public function show(', (int) $start);
        $this->assertNotFalse($end, 'premise: store() has no show() after it to bound the read');

        $body = substr($code, (int) $start, (int) $end - (int) $start);

        $this->assertStringContainsString('$taxmethod->tax_id = $group->id;', $body,
            'TaxMethodController::store() no longer takes `tax_id` from the group it resolved '
            . 'through this store. The group lookup makes that redundant TODAY, which is why no '
            . 'behavioural case can catch it — and why it is pinned here instead.');

        $this->assertStringNotContainsString('$taxmethod->tax_id = $request->', $body,
            'TaxMethodController::store() assigns `tax_id` straight from the request again. That '
            . 'was the cross-tenant write: the row was saved under the CALLER\'s store_id while '
            . "pointing at the VICTIM's tax group, and Tax::tax_methods() selected by tax_id "
            . 'alone.');

        /* AND THE LOOKUP IS STILL STORE-SCOPED, which is what makes the above redundant. */
        $this->assertMatchesRegularExpression(
            '/Tax::where\(\x27store_id\x27,\s*getCurrentStore\(\)\)->find\(\$request->tax_id\)/',
            $body,
            'the tax-group lookup is no longer scoped to the caller\'s store, so `tax_id` is '
            . 'caller-controlled again whatever the assignment says'
        );
    }

    /** THE ALLOW PATH: a merchant's own group still accepts a method. */
    public function test_a_merchant_can_still_attach_a_tax_method_to_their_own_tax_group(): void
    {
        $ownGroup = $this->taxGroupIn($this->storeA, 'Store A VAT group');

        $response = $this->actingAs($this->merchantA)->post(route('taxes-method.store'), [
            'name' => 'Store A standard rate',
            'tax_rate' => '15.00',
            'tax_id' => $ownGroup,
            'priority' => 1,
        ]);
        $this->assertReachedHandler($response, 'own tax_id');

        (new TaxMethod)->flushCache();
        $created = TaxMethod::withoutGlobalScopes()->where('tax_id', $ownGroup)->first();

        $this->assertNotNull($created,
            'a merchant can no longer attach a tax method to their OWN tax group, so the group '
            . 'lookup refuses too much');
        $this->assertSame((int) $this->storeA->id, (int) $created->store_id);
    }

    /**
     * THE READ HALF, AND IT IS THE HALF THAT MATTERS FOR ROWS ALREADY WRITTEN.
     *
     * Closing the writer stops new injections; it does nothing about a row injected before the fix,
     * which would go on charging the victim's shoppers for ever. `Tax::tax_methods()` is therefore
     * scoped to the GROUP's own store — not to `getCurrentStore()`, because this is a model method
     * reached from the storefront, the API and the merchant UI alike, and in the storefront
     * `getCurrentStore()` means nothing.
     *
     * The row is inserted RAW, which is the only way to construct the pre-fix state now that the
     * writer refuses it — and it is also exactly what production may already hold.
     */
    public function test_an_already_injected_tax_method_is_excluded_from_its_groups_methods(): void
    {
        $victimGroup = $this->taxGroupIn($this->storeB, 'Store B VAT group');

        /* Store B's own, legitimate method. */
        \DB::table('tax_methods')->insert([
            'name' => 'B real rate', 'tax_rate' => '20.00', 'tax_id' => $victimGroup,
            'priority' => '1', 'enable_shipping' => 0, 'store_id' => $this->storeB->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        /* The injected one: store A's, at the lexicographically winning priority. */
        \DB::table('tax_methods')->insert([
            'name' => 'A injected', 'tax_rate' => '0.00', 'tax_id' => $victimGroup,
            'priority' => '', 'enable_shipping' => 0, 'store_id' => $this->storeA->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        (new TaxMethod)->flushCache();
        (new \App\Models\Tax)->flushCache();

        $group = \App\Models\Tax::withoutGlobalScopes()->findOrFail($victimGroup);
        $methods = collect($group->tax_methods());

        $this->assertCount(1, $methods,
            "store B's tax group still returns store A's injected method. Before the fix the "
            . "empty priority made it the TOP priority, so the result was the injected row ALONE "
            . "and store B's own 20% rate was excluded entirely.");
        $this->assertSame('B real rate', (string) $methods->first()->name);
        $this->assertSame('20.00', (string) $methods->first()->tax_rate,
            "the rate summed into every price is not store B's own");
    }

    /**
     * AND A LEGACY ROW WITH NO STORE IS STILL COUNTED, which is the same allowance the shipping
     * predicate carries and for the same reason.
     *
     * `tax_methods.store_id` is nullable and legacy rows may not carry it. Refusing those would
     * stop a merchant's OWN tax being charged — a silent revenue change, which is worse than the
     * defect being fixed. Pinned so a later tightening is deliberate rather than incidental.
     */
    public function test_a_legacy_tax_method_with_no_store_is_still_counted(): void
    {
        $group = $this->taxGroupIn($this->storeB, 'Store B VAT group');

        \DB::table('tax_methods')->insert([
            'name' => 'Legacy rate', 'tax_rate' => '20.00', 'tax_id' => $group,
            'priority' => '1', 'enable_shipping' => 0, 'store_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        (new TaxMethod)->flushCache();
        (new \App\Models\Tax)->flushCache();

        $methods = collect(\App\Models\Tax::withoutGlobalScopes()->findOrFail($group)->tax_methods());

        $this->assertCount(1, $methods,
            'a legacy tax method with a NULL store_id is no longer counted, so a merchant who has '
            . 'one has silently stopped collecting tax');
    }

    /**
     * THE `show()` ID ORACLE — route-model binding on an unscoped model, with no gate behind it.
     *
     * `Route::resource` implicit-binds `TaxMethod` with `firstOrFail()` before any handler code
     * runs, so a foreign id bound successfully and the ungated empty `show()` returned **200**,
     * while an id that exists nowhere returned **404**. No field value leaked; what leaked was
     * which ids exist platform-wide, to any authenticated `web` user with no permission at all.
     *
     * Asserted as "the two answers agree" rather than against a specific code, because what makes
     * it an oracle is the DIFFERENCE between them.
     */
    public function test_the_tax_method_show_route_does_not_distinguish_a_foreign_id_from_a_missing_one(): void
    {
        $victim = $this->taxMethodIn($this->storeB, 'Store B VAT', '20.00');

        $staff = $this->makeStaff($this->merchantA);
        $staff->current_store = $this->storeA->id;
        $staff->save();
        $this->grantPermission(User::find($staff->id), 'Manage Blog');
        $staff = User::find($staff->id);

        $foreign = $this->actingAs($staff)->get(route('taxes-method.show', $victim->id));
        $missing = $this->actingAs($staff)->get('/taxes-method/99999999');

        $this->assertNotSame(200, $foreign->getStatusCode(),
            "an unpermitted staff member got 200 for ANOTHER store's tax-method id while a "
            . 'non-existent id gives 404, so the pair of answers enumerates which ids exist '
            . 'platform-wide');

        $this->assertSame(
            $foreign->getStatusCode() === 404 ? 404 : 302,
            $foreign->getStatusCode(),
            'the foreign id answers neither a refusal redirect nor a 404'
        );
        $this->assertSame(404, $missing->getStatusCode(),
            'premise: a non-existent id no longer 404s, so there is no asymmetry to close and '
            . 'this case is measuring something else');
    }

    /**
     * A PRINCIPAL WITH NO RESOLVABLE STORE IS REFUSED THE LIST, rather than shown ORPHANED rows.
     *
     * `ShippingDataTable::query()` is `where('store_id', getCurrentStore())`, and Laravel turns a
     * NULL value into `whereNull` — so a storeless principal was served every shipping class whose
     * `store_id` is NULL. `shippings.store_id` is nullable and the pre-F263 `store()` wrote an
     * unguarded `getCurrentStore()`, so such rows are possible. Found by the mandated review; it
     * shows orphans rather than another tenant's rows, which is why it is low severity and still
     * worth closing — the guard only ever REFUSES, so it reopens no decision.
     */
    public function test_a_storeless_principal_is_refused_the_shipping_class_list(): void
    {
        /* An ORPHAN, which is what the IS NULL predicate would have matched. */
        \DB::table('shippings')->insert([
            'name' => 'Orphaned class', 'slug' => 'orphaned-class',
            'description' => 'no store', 'store_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        (new Shipping)->flushCache();

        $staff = $this->makeStaff($this->merchantA);
        $staff->current_store = null;
        $staff->save();
        $this->grantPermission(User::find($staff->id), 'Manage Shipping Class');
        $staff = User::find($staff->id);

        $this->assertNull(getStoreById($staff->current_store),
            'premise: this principal resolves a store, so the IS NULL path is not being driven');

        $response = $this->actingAs($staff)->get(route('shipping.index'));
        $this->assertReachedHandler($response, 'storeless shipping list');

        $this->assertStringNotContainsString('Orphaned class', $response->getContent() ?: '',
            'a principal with no resolvable store was served an ORPHANED shipping class, because '
            . "where('store_id', null) compiles to IS NULL");
    }

    // =======================================================================================
    //  SURFACES 2 AND 3 — the two shipping-cost QUOTE paths
    // =======================================================================================

    /**
     * `Api\ApiController::delivery_charge` quoted ANY tenant's shipping cost.
     *
     * `method_id` is `required` in the handler's own rules and entirely caller-supplied, and
     * `ShippingMethod` carries no scope, so a shopper on store A could ask for store B's method
     * and be quoted store B's `cost` — a merchant-controlled column that may legitimately be
     * negative (`CartController::get_shipping_method`'s P01-COMMERCE-SEC-01 note measured one at
     * `-50`).
     *
     * Driven through the real HTTP route with a real customer token, because the defect is about
     * what the ENDPOINT answers.
     */
    public function test_the_delivery_charge_endpoint_does_not_quote_another_tenants_shipping_cost(): void
    {
        $theirs = $this->shippingMethodIn($this->storeB, 'Store B courier', '999.00');
        $customer = $this->customerIn($this->storeA);
        $this->cartRowIn($this->storeA, $customer);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $customer->createToken('f263')->plainTextToken,
        ])->postJson('/api/' . $this->storeA->slug . '/delivery-charge', [
            'method_id' => $theirs->id,
            'country_id' => 1,
            'state_id' => 1,
        ]);

        $this->assertReachedHandler($response, 'cross-tenant delivery charge');

        $this->assertStringNotContainsString('999', $response->getContent() ?: '',
            "the quote carried another tenant's shipping cost. `method_id` is caller-supplied and "
            . 'ShippingMethod is not in scoped_models, so the handler predicate is the only control.');
    }

    /** THE ALLOW PATH for surface 3 — the store's OWN method is still quoted. */
    public function test_the_delivery_charge_endpoint_still_quotes_this_stores_own_method(): void
    {
        $ours = $this->shippingMethodIn($this->storeA, 'Store A courier', '777.00');
        $customer = $this->customerIn($this->storeA);
        $this->cartRowIn($this->storeA, $customer);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $customer->createToken('f263')->plainTextToken,
        ])->postJson('/api/' . $this->storeA->slug . '/delivery-charge', [
            'method_id' => $ours->id,
            'country_id' => 1,
            'state_id' => 1,
        ]);

        $this->assertReachedHandler($response, 'own delivery charge');
        $this->assertStringContainsString('777', $response->getContent() ?: '',
            "the store's OWN shipping method is no longer quoted, so the predicate refuses too "
            . 'much and the storefront is broken rather than fixed');
    }

    /**
     * `CartController::cart_list_sidebar` applied ANY tenant's shipping cost to the displayed
     * total.
     *
     * Asserted on the HANDLER'S OWN COMPUTED FIGURE rather than on the rendered sidebar, and that
     * is deliberate: the sidebar renders a storefront theme view whose content depends on cart
     * state, themes and settings this suite does not build, so a string search over the HTML would
     * answer "not present" for a dozen reasons that have nothing to do with the predicate — the
     * classic vacuous pass. What the fix changes is which row `$shippingMethod` resolves to, so
     * that is what is driven, through the same query the handler now runs.
     */
    public function test_the_sidebar_predicate_refuses_another_tenants_shipping_method(): void
    {
        $theirs = $this->shippingMethodIn($this->storeB, 'Store B courier', '999.00');
        $ours = $this->shippingMethodIn($this->storeA, 'Store A courier', '12.00');

        $resolve = function ($methodId, Store $store) {
            (new ShippingMethod)->flushCache();

            return ShippingMethod::where('id', $methodId)
                ->where(function ($q) use ($store) {
                    $q->where('store_id', $store->id ?? 0)->orWhereNull('store_id');
                })
                ->first();
        };

        $this->assertNull($resolve($theirs->id, $this->storeA),
            "the sidebar predicate resolved another tenant's shipping method");
        $this->assertNotNull($resolve($ours->id, $this->storeA),
            "the sidebar predicate refuses the store's OWN shipping method");

        /*
         * AND THE HANDLER REALLY USES IT — a source ratchet, because the behaviour above is a
         * property of the query and would hold whether or not `cart_list_sidebar` ran it. A
         * rendered assertion is not available here for the reason in the docblock, so the link
         * between the proven query and the handler is pinned at source rather than claimed.
         */
        /*
         * ══════════════════════════════════════════════════════════════════════════════════════
         * AND THE HANDLER REALLY USES IT — PINNED SEMANTICALLY OVER ITS OWN BODY, not by an exact
         * string, and not by the response. Both of the obvious alternatives were MEASURED and
         * rejected.
         * ══════════════════════════════════════════════════════════════════════════════════════
         *
         * **THE RESPONSE IS NOT A DISCRIMINATOR HERE, measured rather than assumed.** The mandated
         * independent review suggested asserting on the JSON `sub_total`, since
         * `cart_list_sidebar` puts a total there. Driven three ways against a real request — no
         * `method_id`, this store's method at cost `777`, another store's at cost `999`:
         *
         *     none        status=200  sub_total="20.00"  body contains "777": YES  "999": no
         *     ours  777   status=200  sub_total="20.00"  body contains "777": YES  "999": no
         *     theirs 999  status=200  sub_total="20.00"  body contains "777": YES  "999": no
         *
         * `sub_total` is identical in all three, because it comes from `cart_list()`'s own
         * `final_price` and the merged `shipping_final_price` does not reach it in this fixture.
         * And "777" appears in the body **with no `method_id` posted at all** — the rendered
         * checkout views list the store's shipping methods — so a string search over the response
         * would have been a discriminator that discriminates nothing. That is precisely the
         * vacuous pass this file exists to avoid, so it is recorded instead of shipped.
         *
         * **AN EXACT-STRING RATCHET IS TOO NARROW**, which the same review caught. The first
         * version asserted only that `ShippingMethod::find($request->method_id)` was absent, so a
         * revert spelled `ShippingMethod::find($request->input('method_id'))`, or
         * `where('id', $request->method_id)->first()` with the store clause dropped, would have
         * left the whole file green.
         *
         * So the assertion is over the METHOD'S OWN BODY: whatever resolves a `ShippingMethod`
         * inside it must carry a store predicate. Comment-stripped, because this change's own
         * explanatory comment quotes the call it removed — a ratchet that reads prose fires on
         * documentation and stays silent on code, and the first draft did exactly that.
         */
        $bodies = [
            'CartController::cart_list_sidebar' => $this->methodBody(
                app_path('Http/Controllers/CartController.php'),
                'function cart_list_sidebar(',
                'function cart_remove('
            ),
            'Api\\ApiController::delivery_charge' => $this->methodBody(
                app_path('Http/Controllers/Api/ApiController.php'),
                'function delivery_charge(',
                'function calculateFlaterate('
            ),
        ];

        foreach ($bodies as $where => $body) {
            $this->assertNotSame('', $body,
                "premise: {$where} could not be located, so this ratchet is reading nothing");

            $this->assertStringContainsString('ShippingMethod::', $body,
                "premise: {$where} no longer resolves a ShippingMethod at all, so this ratchet is "
                . 'guarding a call that has moved and should be re-pointed rather than deleted');

            /* NO BARE RESOLUTION, however it is spelled. */
            $this->assertDoesNotMatchRegularExpression(
                '/ShippingMethod::find\s*\(/',
                $body,
                "{$where} resolves a ShippingMethod with a bare find(), so the predicate proven "
                . 'above is not the one the handler runs'
            );

            /*
             * THE PREDICATE MUST BE IN THE SAME **STATEMENT**, NOT MERELY THE SAME METHOD — and
             * the first version of this assertion got that wrong, which the mutation sweep caught.
             *
             * It searched the whole method body for `where('store_id', $store->id`. Both of these
             * methods legitimately contain one: `cart_list_sidebar` reads
             * `TaxOption::where('store_id', $store->id)` a few lines later, and `delivery_charge`
             * scopes its cart the same way. So a revert spelled
             * `ShippingMethod::where('id', $request->input('method_id'))->first()` left the body
             * still matching, and the mutation DID NOT BITE — a ratchet satisfied by unrelated
             * correct code, which is a mistake this project has made before by counting a
             * substring that also occurs somewhere legitimate.
             *
             * So the ShippingMethod statement is cut out on its own, from `ShippingMethod::` to the
             * terminating `;`, and the predicate is required inside THAT.
             */
            $stmtStart = strpos($body, 'ShippingMethod::');
            $this->assertNotFalse($stmtStart, "premise: no ShippingMethod statement in {$where}");

            $rest = substr($body, (int) $stmtStart);
            $semicolon = strpos($rest, ';');
            $statement = substr($rest, 0, $semicolon === false ? 400 : $semicolon);

            /*
             * SINGLE-QUOTED, and `\x27` for the inner quotes. A double-quoted PHP string
             * interpolates `$store` — an earlier draft did, producing `/…,\s*->id/` and an
             * "Undefined variable $store" error rather than a comparison.
             */
            $this->assertMatchesRegularExpression(
                '/where\s*\(\s*\x27store_id\x27\s*,\s*\$store->id/',
                $statement,
                "{$where}'s ShippingMethod STATEMENT carries no store_id predicate taken from "
                . '$store->id. The store comes from the route slug, so that is the only value '
                . "identifying the tenant on a storefront path. (Asserted on the statement, not "
                . 'the method: both methods contain a legitimate store_id predicate on another '
                . 'model, which made a method-wide search pass against a reverted lookup.)'
            );
        }
    }

    /**
     * THE LEGACY `NULL` ROW IS STILL ACCEPTED, and that is the sibling's documented decision
     * rather than a looser predicate of mine.
     *
     * `shipping_methods.store_id` is nullable and legacy rows may not carry it;
     * `get_shipping_method()` records that refusing those would hide a merchant's OWN method —
     * "a broken storefront rather than a fix". What is refused is a method belonging to a
     * DIFFERENT store. Pinned so a later tightening is a deliberate act with this note read first.
     */
    public function test_a_legacy_shipping_method_with_no_store_is_still_accepted(): void
    {
        $orphan = $this->shippingMethodIn($this->storeA, 'Legacy courier', '5.00');
        ShippingMethod::withoutGlobalScopes()->whereKey($orphan->id)->update(['store_id' => null]);
        (new ShippingMethod)->flushCache();

        $resolved = ShippingMethod::where('id', $orphan->id)
            ->where(function ($q) {
                $q->where('store_id', $this->storeA->id)->orWhereNull('store_id');
            })
            ->first();

        $this->assertNotNull($resolved,
            'a legacy shipping method with a NULL store_id is now refused, which hides a '
            . "merchant's own method. See get_shipping_method()'s P01-COMMERCE-SEC-01 note.");
    }

    // =======================================================================================
    //  THE OPERATOR SPLIT — reads admit, writes refuse
    // =======================================================================================

    /**
     * The platform operator MAY NOT WRITE a merchant's commercial settings.
     *
     * This is not new behaviour: a bare `isAbleTo()` already refused the operator, because
     * Laratrust has no super-admin bypass and no role holds these permissions on a database built
     * from source. It is pinned HERE so it stops being an accident — the next person adding an
     * operator limb "for consistency" would hand the operator every merchant's shipping classes
     * and tax rates. Consistent with F157 (owner, 2026-10-28: the operator reads and exports, it
     * does not rewrite a merchant's commercial settings).
     */
    public function test_the_platform_operator_cannot_write_a_merchants_shipping_class_or_tax_method(): void
    {
        $class = $this->shippingClassIn($this->storeA, 'Store A standard');
        $tax = $this->taxMethodIn($this->storeA, 'Store A VAT', '15.00');

        $operator = $this->makePlatformAdmin();
        $operator->current_store = $this->storeA->id;
        $operator->save();

        /*
         * THE OPERATOR IS GIVEN THE PERMISSIONS, AND WITHOUT THAT THIS CASE COULD NOT FAIL.
         *
         * Found by the mandated independent review of this change. `makePlatformAdmin()` creates a
         * `type = 'super admin'` user with NO role and NO permission — and the seeded `super admin`
         * role does not carry these six names either; they are on the ordinary `admin` role
         * (`Utility.php:2815-2818`, `:2849-2851`, `:3145-3148`, `:3179-3181`). So with the operator
         * limb DELETED outright, the fall-through `! $user->isAbleTo($permission)` still refuses
         * this fixture, and the case stays green while the control it is named for is gone.
         *
         * That is the exact failure this file's docblock warns about one paragraph up — a refusal
         * that happens by ACCIDENT rather than by decision. Granting the permissions removes the
         * accident: the ONLY thing left that can produce a refusal is `UserPolicy::isOperator()`,
         * so the case now pins the owner's F157 split instead of Laratrust's lack of a bypass.
         */
        foreach ([
            'Create Shipping Class', 'Edit Shipping Class', 'Delete Shipping Class',
            'Create Tax Method', 'Edit Tax Method', 'Delete Tax Method',
        ] as $permission) {
            $this->grantPermission(User::find($operator->id), $permission);
        }

        $operator = User::find($operator->id);

        $this->assertSame('super admin', strtolower(trim((string) $operator->type)),
            'premise: this principal is not the platform operator, so the case measures nothing');
        $this->assertTrue($operator->isAbleTo('Edit Shipping Class'),
            'premise: the operator does not hold the permission, so a refusal below could come '
            . 'from the permission check rather than from the operator limb — which is how this '
            . 'case passed for the wrong reason before the review caught it');

        $this->actingAs($operator)->put(route('shipping.update', $class->id), [
            'name' => 'Operator rename', 'description' => 'Operator rename',
        ]);
        $this->assertSame('Store A standard', (string) $this->victimClass($class)->name,
            "the platform operator rewrote a merchant's shipping class");

        $this->actingAs($operator)->put(route('taxes-method.update', $tax->id), [
            'name' => 'Operator rename', 'tax_rate' => '0.00', 'priority' => 1,
        ]);
        $this->assertSame('15.00', (string) $this->victimTax($tax)->tax_rate,
            "the platform operator rewrote a merchant's tax rate");

        $this->actingAs($operator)->delete(route('shipping.destroy', $class->id));
        (new Shipping)->flushCache();
        $this->assertTrue(Shipping::withoutGlobalScopes()->whereKey($class->id)->exists(),
            "the platform operator deleted a merchant's shipping class");
    }

    /**
     * AND THE OPERATOR KEEPS THE READ IT HAD, which is the asymmetry worth asserting.
     *
     * Before this change `ShippingController::edit` and `TaxMethodController::edit` had NO check
     * at all, so they admitted everybody — the operator included. A gate that refused the operator
     * would have taken away a read they have today: an unstated second decision riding on a
     * security fix, which is the exact mistake an independent review caught in the first F258
     * draft. So the read gates admit the operator and this pins it.
     */
    public function test_the_platform_operator_keeps_the_read_it_already_had(): void
    {
        $class = $this->shippingClassIn($this->storeA, 'Store A standard');

        $operator = $this->makePlatformAdmin();
        $operator->current_store = $this->storeA->id;
        $operator->save();

        $response = $this->actingAs(User::find($operator->id))
            ->get(route('shipping.edit', $class->id));
        $this->assertReachedHandler($response, 'operator shipping-class read');

        $this->assertStringContainsString('Store A standard', $response->getContent() ?: '',
            'the platform operator lost a read it had before this change, which is a behaviour '
            . 'change F263 did not ask for');
    }

    /**
     * AN OPERATOR WITH NO `current_store` IS REFUSED RATHER THAN SERVED STORE 1.
     *
     * `getCurrentStore()` hands such a principal the hardcoded literal `1`, and `getStoreById(1)`
     * normally resolves — so without the guard the operator would be shown store 1's shipping
     * classes by default. The F258 read gate carries the same guard for the same reason.
     */
    public function test_an_operator_with_no_current_store_is_refused_rather_than_served_store_one(): void
    {
        $class = $this->shippingClassIn($this->storeA, 'Store A standard');

        $operator = $this->makePlatformAdmin();
        $operator->current_store = null;
        $operator->save();

        $response = $this->actingAs(User::find($operator->id))
            ->get(route('shipping.edit', $class->id));
        $this->assertReachedHandler($response, 'storeless operator read');

        $this->assertStringNotContainsString('Store A standard', $response->getContent() ?: '',
            'an operator with no current_store was served a store it did not select');
    }
}
