<?php

namespace Tests\Feature\Security;

use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Services\VariantRemovalPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * P1-SEC-66 / P1-SEC-67 -- THE FIRST-PARTY STORE-OWNERSHIP SINKS THAT TREATED "A ROOT OF 1" AS A TENANT.
 * =====================================================================================================
 *
 * The second independent review of P1-SEC-64b (a review of a FIX, which is where this project's reviews keep finding things) found that the
 * predicate P0-TENANT-ROOT removed from `UserPolicy::sameTenant()` -- "`creatorId()` names a tenant" -- still decided WRITES in five more first-party
 * places that neither that fix nor `ThemeEditorGate`'s covered, each as a copy of
 *
 *     Store::where('created_by', $user->creatorId())        // "the stores this user owns"
 *
 * `User::creatorId()` is the user's own id for type admin/super admin and `created_by` for everything else. A merchant who demotes themselves
 * (`PUT /users/{self}` with a role of their own named "Manager": `docs/security/P0-USER-DELETION-CASCADE.md` §7 calls it a supported action) has
 * `creatorId() = 1`, which is the platform operator -- and the operator's own store carries `created_by = 1` (the installer:
 * `PermissionTableSeeder:41-47`). So the "stores this user owns" included the OPERATOR'S.
 *
 * THE SINKS, each driven here through its real route or entry point with the demotion done THROUGH THE PRODUCT:
 *
 *   StoreController::changeStore       POST /change-store/{id}       the discriminator `users.current_store` becomes the operator's store
 *   StoreController::resolveOwnedStore POST /store/change-status     takes the operator's storefront offline
 *   Api\DashboardController::resolveOwnedStore  GET /api/admin/categorylist?store_id=   102 call sites, the admin API the Flutter app calls
 *   Api\DashboardController::ownedStoreIds      the owned-store set seven list/detail handlers filter by
 *   VariantRemovalPolicy::authorize    the variant delete/update ownership limb
 *
 * THE FIX is one definition, `UserPolicy::merchantTenantRoot()`: the tenant root, or NULL when the principal belongs to no merchant tenant (a demoted
 * merchant, or staff the operator created). Every sink fails CLOSED on null. The operator is admitted before any of them asks.
 *
 * THE GLOBAL SCOPE, FIXED SEPARATELY AS P1-SEC-67b (the tests under "StoreScope" below): `StoreScope::ownedStoreIds()` -- the owned-store set the scope and
 * `BulkDeleteTenantGuard` both read -- built the same set the same way. An earlier version of this note said it could not be patched because
 * `config/tenancy.php` `null_tenant => 'open'` means "no tenant" adds NO predicate. That is true of changing `resolveTenant()`'s MODE and not of narrowing
 * the owned SET: an empty set degrades to `[current_store]` ("never to zero rows and never to no predicate at all"), which fails CLOSED; a test below pins
 * that the mode stays TENANT. `Api\DashboardController::resolveManageableUser`, the last unpatched copy of the P0 predicate, uses the same helper.
 * What is STILL open is named in `docs/security/P01-SECURITY-WORK-QUEUE.md`, subsection g).
 */
class TenantRootSinksTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * Put the attacker into the collapsed state THROUGH THE PRODUCT, and prove it happened (the P0 test's helper, with its premise): if the demotion
     * stops working every case below would be measuring a refusal it would get anyway, and must be re-pointed rather than left to pass.
     */
    private function demoteThroughTheRoute(User $attacker): User
    {
        $role = Role::forceCreate([
            'name' => 'Manager',
            'display_name' => 'Manager',
            'guard_name' => 'web',
            'created_by' => $attacker->creatorId(),
            'store_id' => $attacker->current_store,
        ]);

        $this->actingAs($attacker)->put("/users/{$attacker->id}", ['name' => $attacker->name, 'email' => $attacker->email, 'role' => $role->id]);

        $fresh = $attacker->fresh();

        $this->assertSame('Manager', $fresh->type, 'the self-demotion no longer happens, so these cases cannot reach the state they exist to test');
        $this->assertSame(1, (int) $fresh->creatorId(), "the attacker's tenant root is no longer the platform, so the collapse is not being reproduced");

        return $fresh;
    }

    /**
     * The operator FIRST, so that it is user 1 -- the id every merchant has as `created_by` -- and its store, as the installer creates it.
     *
     * @return array{0: User, 1: Store}
     */
    private function operatorWithStore(): array
    {
        $operator = $this->makePlatformAdmin();
        $this->assertSame(1, (int) $operator->id, 'premise: the operator is user 1, the id every merchant has as created_by');

        return [$operator, $this->makeStore($operator)];
    }

    /** @return array{0: User, 1: Store} a merchant who has demoted themselves, and the store they own */
    private function collapsedMerchant(): array
    {
        [$merchant, $store] = $this->makeTenant();
        // `PUT /users/{self}` needs `Edit User`, which a real merchant's seeded `admin` role holds (the P0 test grants it the same way)
        $this->grantPermission($merchant, 'Edit User');

        return [$this->demoteThroughTheRoute($merchant), $store];
    }

    // ---- the one definition ---------------------------------------------------------------------------------------------------------------

    public function test_the_merchant_tenant_root_is_the_merchant_for_a_merchant_and_their_staff_and_nobody_else(): void
    {
        [$operator] = $this->operatorWithStore();
        [$merchant] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);
        [$collapsed] = $this->collapsedMerchant();
        $operatorStaff = User::factory()->create(['type' => 'staff', 'created_by' => $operator->id, 'is_active' => 1, 'email_verified_at' => now()])->fresh();

        $this->assertSame((int) $merchant->id, UserPolicy::merchantTenantRoot($merchant->fresh()), 'a merchant roots at themselves');
        $this->assertSame((int) $merchant->id, UserPolicy::merchantTenantRoot($staff->fresh()), "a merchant's staff root at the merchant");
        $this->assertNull(UserPolicy::merchantTenantRoot($operator->fresh()), 'the operator is the platform, not a tenant');
        $this->assertNull(UserPolicy::merchantTenantRoot($collapsed), 'a demoted merchant is rooted at the platform');
        $this->assertNull(UserPolicy::merchantTenantRoot($operatorStaff), 'staff the operator created are rooted at the platform');

        // A root that is NOT user 1 and NOT the operator, but is not a merchant either: staff created by a demoted merchant. A check that only asks "is the
        // root user 1" -- the narrower question UserPolicy's own first version asked -- passes this principal, so it must be here.
        $staffOfTheDemoted = User::factory()->create(['type' => 'staff', 'created_by' => $collapsed->id, 'is_active' => 1, 'email_verified_at' => now()])->fresh();
        $this->assertNotSame(1, (int) $staffOfTheDemoted->creatorId(), 'premise: this principal is not rooted at user 1');
        $this->assertNull(UserPolicy::merchantTenantRoot($staffOfTheDemoted), 'staff of a demoted merchant are rooted at a non-merchant');
        $this->assertNull(UserPolicy::merchantTenantRoot(null), 'no principal, no root');
    }

    // ---- StoreController::changeStore -----------------------------------------------------------------------------------------------------

    public function test_a_self_demoted_merchant_cannot_make_the_operators_store_their_current_store(): void
    {
        [$operator, $operatorStore] = $this->operatorWithStore();
        [$collapsed, $ownStore] = $this->collapsedMerchant();
        $before = $collapsed->fresh()->current_store;

        $this->actingAs($collapsed)->post("/change-store/{$operatorStore->id}")->assertForbidden();

        $this->assertSame($before, $collapsed->fresh()->current_store, "the discriminator was pointed at the operator's store");
        $this->assertNotSame((int) $operatorStore->id, (int) $collapsed->fresh()->current_store);

        // POSITIVE CONTROL: the operator keeps the escape hatch, so "unchanged" cannot pass on a route that refuses everyone
        $this->actingAs($operator->fresh())->post("/change-store/{$operatorStore->id}");
        $this->assertSame((int) $operatorStore->id, (int) $operator->fresh()->current_store, 'Premise: the operator can switch into their own store.');
    }

    // ---- StoreController::resolveOwnedStore -----------------------------------------------------------------------------------------------

    public function test_a_self_demoted_merchant_cannot_take_the_operators_storefront_offline(): void
    {
        [$operator, $operatorStore] = $this->operatorWithStore();
        // A SECOND operator store: the handler refuses to deactivate a tenant's LAST active store, which would stop the attack for a reason that has
        // nothing to do with ownership and leave `is_active` untouched before the fix too (the assertion below would pass for the wrong reason).
        $this->makeStore($operator);
        [$collapsed] = $this->collapsedMerchant();
        [$merchant, $ownStore] = $this->makeTenant();
        $this->makeStore($merchant);                                   // and a second store of their own, for the positive control

        $this->actingAs($collapsed)->post('/store/change-status', ['id' => $operatorStore->id, 'status' => 0])->assertForbidden();
        $this->assertSame(1, (int) DB::table('stores')->where('id', $operatorStore->id)->value('is_active'), "A self-demoted merchant took the operator's storefront offline.");

        // POSITIVE CONTROLS: a merchant switches their own storefront off, and the operator the platform's
        $this->actingAs($merchant->fresh())->post('/store/change-status', ['id' => $ownStore->id, 'status' => 0]);
        $this->assertSame(0, (int) DB::table('stores')->where('id', $ownStore->id)->value('is_active'), 'Premise: a merchant can still change their own store.');

        $this->actingAs($operator->fresh())->post('/store/change-status', ['id' => $operatorStore->id, 'status' => 0]);
        $this->assertSame(0, (int) DB::table('stores')->where('id', $operatorStore->id)->value('is_active'), 'Premise: the operator can still change their own store.');
    }

    // ---- Api\DashboardController::resolveOwnedStore (102 call sites) ----------------------------------------------------------------------

    public function test_a_self_demoted_merchant_does_not_resolve_the_operators_store_in_the_admin_api(): void
    {
        [$operator, $operatorStore] = $this->operatorWithStore();
        [$collapsed] = $this->collapsedMerchant();
        [$merchant, $ownStore] = $this->makeTenant();

        $get = fn (User $user, Store $store) => $this->withHeader('Authorization', 'Bearer '.$user->createToken('auth_token')->plainTextToken)
            ->getJson('/api/admin/categorylist?store_id='.$store->id);

        // the denial is a 403 and never a 401: the shipped app forces a logout on any 401
        $denied = $get($collapsed, $operatorStore);
        $this->assertSame(403, $denied->getStatusCode(), "The admin API resolved the operator's store for a self-demoted merchant.");

        // POSITIVE CONTROLS
        $this->assertSame(200, $get($merchant->fresh(), $ownStore)->getStatusCode(), 'Premise: a merchant reads their own store through the admin API.');
        $this->assertNotSame(403, $get($operator->fresh(), $ownStore)->getStatusCode(), 'Premise: the operator keeps the escape hatch.');
    }

    public function test_the_admin_apis_owned_store_set_is_empty_for_a_self_demoted_merchant(): void
    {
        [$operator, $operatorStore] = $this->operatorWithStore();
        [$collapsed] = $this->collapsedMerchant();
        [$merchant, $ownStore] = $this->makeTenant();

        $ownedStoreIds = function (User $user): array {
            $this->actingAs($user);
            $controller = app(\App\Http\Controllers\Api\DashboardController::class);
            $method = new \ReflectionMethod($controller, 'ownedStoreIds');
            $method->setAccessible(true);

            return array_map('intval', $method->invoke($controller));
        };

        $this->assertSame([], $ownedStoreIds($collapsed), "The owned-store set of a self-demoted merchant includes the operator's store.");

        // POSITIVE CONTROLS
        $this->assertSame([(int) $ownStore->id], $ownedStoreIds($merchant->fresh()), 'Premise: a merchant owns exactly their own store.');
        $this->assertContains((int) $operatorStore->id, $ownedStoreIds($operator->fresh()), 'Premise: the operator is not narrowed.');
    }

    // ---- VariantRemovalPolicy -------------------------------------------------------------------------------------------------------------

    public function test_the_variant_policy_refuses_a_self_demoted_merchant_the_operators_product(): void
    {
        [$operator, $operatorStore] = $this->operatorWithStore();
        [$collapsed] = $this->collapsedMerchant();
        [$merchant, $ownStore] = $this->makeTenant();
        // The permission limb is answered per STORE, so a role bound to the attacker's own store would be refused there and the ownership limb under test
        // would never be reached (this case passed before the fix for that reason). Bind the role to the OPERATOR'S store -- the state a self-demoted
        // merchant is in after `changeStore` -- so that OWNERSHIP is the only thing left to refuse.
        $role = Role::forceCreate(['name' => 'p66-products-'.$collapsed->id, 'display_name' => 'p66', 'guard_name' => 'web', 'created_by' => $collapsed->creatorId(), 'store_id' => $operatorStore->id]);
        $role->givePermission(\App\Models\Permission::firstOrCreate(['name' => 'Edit Products'], ['display_name' => 'Edit Products']));
        $collapsed->addRole($role);
        $collapsed->flushCache();
        $this->grantPermission($merchant, 'Edit Products');

        $product = fn (Store $store, int $createdBy) => Product::forceCreate([
            'name' => 'P1-SEC-66 scarf', 'slug' => 'p66-'.uniqid(), 'description' => 'x', 'price' => 10, 'track_stock' => 1, 'trending' => 0,
            'stock_status' => 'in_stock', 'product_stock' => 7, 'status' => 1, 'variant_product' => 1, 'store_id' => $store->id, 'created_by' => $createdBy,
        ]);

        $operatorsProduct = $product($operatorStore, (int) $operator->id);
        $ownProduct = $product($ownStore, (int) $merchant->id);

        $refusal = VariantRemovalPolicy::authorize($collapsed->fresh(), $operatorsProduct);
        $this->assertNotNull($refusal, "The variant policy authorised a self-demoted merchant against the operator's product.");
        $this->assertSame(403, $refusal['status']);

        // POSITIVE CONTROL: a merchant is still authorised against their own product
        $this->assertNull(VariantRemovalPolicy::authorize($merchant->fresh(), $ownProduct), 'Premise: a merchant is authorised against their own product.');
    }

    // ---- Api\ApiController::resolveViewableOrder (order detail / status change / return) --------------------------------------------------

    public function test_a_self_demoted_merchant_cannot_read_an_order_of_the_operators_store_through_the_storefront_api(): void
    {
        [$operator, $operatorStore] = $this->operatorWithStore();
        [$collapsed] = $this->collapsedMerchant();
        [$merchant, $ownStore] = $this->makeTenant();
        $operatorsOrder = $this->makeOrder($operatorStore, ['product_order_id' => 'ORD-P1SEC66-OPERATOR']);
        $ownOrder = $this->makeOrder($ownStore, ['product_order_id' => 'ORD-P1SEC66-OWN']);

        $detail = fn (User $user, Store $store, $order) => $this->withHeader('Authorization', 'Bearer '.$user->createToken('auth_token')->plainTextToken)
            ->postJson('/api/'.$store->slug.'/order-detail', ['order_id' => $order->id]);

        $this->assertStringNotContainsString('ORD-P1SEC66-OPERATOR', (string) $detail($collapsed, $operatorStore, $operatorsOrder)->getContent(), "A self-demoted merchant read an order of the operator's store.");

        // POSITIVE CONTROLS: a merchant reads their own order, and the operator any order
        $this->assertStringContainsString('ORD-P1SEC66-OWN', (string) $detail($merchant->fresh(), $ownStore, $ownOrder)->getContent(), 'Premise: a merchant reads their own order through this endpoint.');
        $this->assertStringContainsString('ORD-P1SEC66-OPERATOR', (string) $detail($operator->fresh(), $operatorStore, $operatorsOrder)->getContent(), 'Premise: the operator reads any order.');
    }

    // ---- UserController::UserUnable, the `store` branch ------------------------------------------------------------------------------------

    public function test_a_self_demoted_merchant_cannot_deactivate_the_operators_store_through_user_unable(): void
    {
        [$operator, $operatorStore] = $this->operatorWithStore();
        $this->makeStore($operator);                      // a second operator store: the branch refuses to deactivate a tenant's LAST active store
        [$collapsed] = $this->collapsedMerchant();
        [$merchant, $ownStore] = $this->makeTenant();
        $this->makeStore($merchant);

        // `owner_id` must be non-empty or the handler answers "Something went wrong" and does nothing (so the refusal assertion below would pass for the wrong
        // reason); the store branch derives the real owner from the STORE, never from this field.
        $this->actingAs($collapsed)->post('/user-unable', ['name' => 'store', 'id' => $operatorStore->id, 'owner_id' => 1, 'is_active' => 0]);
        $this->assertSame(1, (int) DB::table('stores')->where('id', $operatorStore->id)->value('is_active'), "A self-demoted merchant deactivated the operator's store through user-unable.");

        // POSITIVE CONTROLS: a merchant deactivates one of their own stores; the operator one of theirs
        $this->actingAs($merchant->fresh())->post('/user-unable', ['name' => 'store', 'id' => $ownStore->id, 'owner_id' => 1, 'is_active' => 0]);
        $this->assertSame(0, (int) DB::table('stores')->where('id', $ownStore->id)->value('is_active'), 'Premise: a merchant can still deactivate their own store.');

        $this->actingAs($operator->fresh())->post('/user-unable', ['name' => 'store', 'id' => $operatorStore->id, 'owner_id' => 1, 'is_active' => 0]);
        $this->assertSame(0, (int) DB::table('stores')->where('id', $operatorStore->id)->value('is_active'), 'Premise: the operator can still deactivate their own store.');
    }

    // ---- PaymentController::handlePaymeraTrigger -- a payment-completion endpoint's ownership gate ------------------------------------------

    public function test_a_self_demoted_merchant_cannot_trigger_a_payment_of_the_operators_store(): void
    {
        [$operator, $operatorStore] = $this->operatorWithStore();
        [$collapsed] = $this->collapsedMerchant();

        $reference = 'ss-p66-'.uniqid();
        \Illuminate\Support\Facades\Cache::put('paymera_pending_'.$reference, [
            'status' => 'P', 'processed' => false, 'payment_id' => 'pay-'.uniqid(), 'order_id' => 'ORD-OPERATOR',
            'request_data' => ['store_id' => $operatorStore->id],
        ], 600);

        $denied = $this->actingAs($collapsed)->postJson('/store/paymera/trigger/'.$reference);

        $this->assertSame(404, $denied->getStatusCode(), "A self-demoted merchant reached the payment trigger of the operator's store.");
        $this->assertSame(['error' => 'Payment context not found'], $denied->json(), 'the refusal must be the same 404 an unknown reference gets');

        // POSITIVE CONTROL: the operator passes the ownership gate (named positively: a null json() would satisfy a negated assertion)
        $passed = $this->actingAs($operator->fresh())->postJson('/store/paymera/trigger/'.$reference);
        $passed->assertOk();
        $passed->assertJson(['ok' => true]);
    }

    // ---- Api\DeliveryBoyApiController::refuseUnlessOperatorOwns ----------------------------------------------------------------------------

    public function test_a_self_demoted_merchant_cannot_list_the_operators_delivery_boys_through_the_admin_api(): void
    {
        [$operator, $operatorStore] = $this->operatorWithStore();
        [$collapsed] = $this->collapsedMerchant();
        [$merchant, $ownStore] = $this->makeTenant();

        $index = fn (User $user, Store $store) => $this->withHeader('Authorization', 'Bearer '.$user->createToken('auth_token')->plainTextToken)
            ->getJson('/api/admin/deliveryboysIndex?store_id='.$store->id);

        $this->assertSame(404, $index($collapsed, $operatorStore)->getStatusCode(), "The delivery-boy API admitted a self-demoted merchant to the operator's store.");

        // POSITIVE CONTROLS: a merchant reaches their own store, the operator any
        $this->assertNotSame(404, $index($merchant->fresh(), $ownStore)->getStatusCode(), 'Premise: a merchant reaches their own store through the delivery-boy API.');
        $this->assertNotSame(404, $index($operator->fresh(), $ownStore)->getStatusCode(), 'Premise: the operator keeps the escape hatch.');
    }

    // ---- AITemplateController::resolveApiKeyForActor -- the platform's own AI key ------------------------------------------------------------

    /**
     * The owner decided (2026-10-22) that merchants must not consume the platform's OpenAI key. `resolveApiKeyForActor()` looks for the caller's OWN
     * key first by `created_by = creatorId()`; for a user rooted at the operator that is the OPERATOR'S key -- the one thing the decision forbids.
     */
    public function test_a_self_demoted_merchant_does_not_resolve_the_platforms_ai_key(): void
    {
        [$operator] = $this->operatorWithStore();
        [$collapsed] = $this->collapsedMerchant();
        [$merchant] = $this->makeTenant();
        \App\Models\ApikeySetiings::create(['key' => 'OPERATOR-PLATFORM-KEY', 'created_by' => $operator->id]);
        \App\Models\ApikeySetiings::create(['key' => 'MERCHANT-OWN-KEY', 'created_by' => $merchant->id]);

        $keyFor = function (User $user): ?string {
            $this->actingAs($user);
            $controller = app(\App\Http\Controllers\AITemplateController::class);
            $method = new \ReflectionMethod($controller, 'resolveApiKeyForActor');
            $method->setAccessible(true);

            return $method->invoke($controller);
        };

        $this->assertNull($keyFor($collapsed), "A self-demoted merchant resolved the platform's AI key.");

        // POSITIVE CONTROLS
        $this->assertSame('MERCHANT-OWN-KEY', $keyFor($merchant->fresh()), 'Premise: a merchant resolves their own key.');
        $this->assertSame('OPERATOR-PLATFORM-KEY', $keyFor($operator->fresh()), 'Premise: the operator resolves the platform key.');

        // A SECOND operator account with its own key resolves THAT key, not the first operator's: "the same caller always gets the same key, which is
        // what makes the charge attributable" (the resolver's own docblock). Without this case the operator's own-key limb is indistinguishable from the
        // platform fallback, which hands every operator the lowest-id operator's key.
        $secondOperator = $this->makePlatformAdmin();
        \App\Models\ApikeySetiings::create(['key' => 'SECOND-OPERATOR-KEY', 'created_by' => $secondOperator->id]);
        $this->assertSame('SECOND-OPERATOR-KEY', $keyFor($secondOperator->fresh()), 'an operator is charged on their own key, not on the first operator\'s');
    }

    // ---- StoreScope -- the global scope's owned-store set (P1-SEC-67b) ----------------------------------------------------------------------

    /**
     * The scope narrows a scoped model to `store_id IN {current_store} UNION {stores whose created_by = creatorId()}`. For a user rooted at the platform
     * that union held the OPERATOR'S stores, so every handler whose ONLY tenant control is the scope -- Menu and SupportTicket destroy, the Customer,
     * Cart, OrderNote and DeliveryBoy reads -- reached the operator's rows. Narrowing the owned set fails CLOSED: an empty set degrades to
     * `[current_store]` ("never to zero rows and never to no predicate at all", StoreScope::ownedStoreIds()). Changing `resolveTenant()`'s MODE is the
     * change that would fail open under `null_tenant => 'open'`; this is not that.
     */
    public function test_the_scopes_owned_store_set_excludes_the_operators_stores_for_a_user_rooted_at_the_platform(): void
    {
        [$operator, $operatorStore] = $this->operatorWithStore();
        [$collapsed, $ownStore] = $this->collapsedMerchant();
        [$merchant, $merchantStore] = $this->makeTenant();
        $secondStore = $this->makeStore($merchant);
        $staff = $this->makeStaff($merchant);
        $operatorsStaff = User::factory()->create(['type' => 'staff', 'created_by' => $operator->id, 'current_store' => $ownStore->id, 'is_active' => 1, 'email_verified_at' => now()])->fresh();
        $dangling = User::factory()->create(['type' => 'staff', 'created_by' => 987654, 'current_store' => $ownStore->id, 'is_active' => 1, 'email_verified_at' => now()])->fresh();

        \App\Scopes\StoreScope::forgetOwnedStoreIds();    // the set is memoised per application instance; every store above exists now

        $ids = fn (User $user): array => \App\Scopes\StoreScope::admissibleStoreIds($user, (int) $user->current_store);

        // THE PRINCIPALS ROOTED AT A NON-MERCHANT get only the store they are IN: a demoted merchant, staff the operator created, and a dangling root
        $this->assertSame([(int) $ownStore->id], $ids($collapsed->fresh()), "A self-demoted merchant's scope includes the operator's stores.");
        $this->assertNotContains((int) $operatorStore->id, $ids($operatorsStaff));
        // (This one does not discriminate: a missing root owns no store -- `stores.created_by` is a foreign key -- so the answer is [current_store] with or
        // without the fix. What pins "a root that cannot be read is not a merchant" is the helper test below.)
        $this->assertSame([(int) $ownStore->id], $ids($dangling), 'a root that does not exist must fail closed to the current store');

        // POSITIVE CONTROLS: a merchant sees all of their stores, and their staff the merchant's
        $expected = [(int) $merchantStore->id, (int) $secondStore->id];
        $this->assertEqualsCanonicalizing($expected, $ids($merchant->fresh()), 'Premise: a merchant is scoped to all of their own stores.');
        $this->assertEqualsCanonicalizing($expected, $ids($staff->fresh()), "Premise: a merchant's staff are scoped to the merchant's stores.");
    }

    /**
     * `StoreScope::rootIsAMerchant()` in isolation, because the one answer that cannot be reached through the scope is a root that does not exist AND
     * owns stores: `stores.created_by` is a foreign key to `users.id`, so that state cannot be built here (it was tried: the insert is refused). The
     * contract still has to hold where the constraint is absent, and "a root that cannot be read is NOT a merchant" is what the helper's docblock says
     * and `UserPolicy::tenantRootIsAMerchant()` does. Called by reflection: it is `protected` on purpose.
     */
    public function test_the_scopes_merchant_root_check_answers_for_each_kind_of_root(): void
    {
        [$operator] = $this->operatorWithStore();
        [$merchant] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);
        [$collapsed] = $this->collapsedMerchant();

        $isMerchant = fn (int $rootId): bool => (new \ReflectionMethod(\App\Scopes\StoreScope::class, 'rootIsAMerchant'))->invoke(null, $rootId);

        $this->assertTrue($isMerchant((int) $merchant->id), 'Premise: a type-admin user is a merchant root.');
        $this->assertFalse($isMerchant((int) $operator->id), 'The operator is the platform, not a merchant.');
        $this->assertFalse($isMerchant((int) $staff->id), 'Staff are not a tenant root.');
        $this->assertFalse($isMerchant((int) $collapsed->id), 'A demoted merchant is no longer a merchant root.');
        $this->assertFalse($isMerchant(987654), 'A root that cannot be read is NOT a merchant -- the policy says so, and a store it owns (where nothing forbids the row) must not become reachable.');
        $this->assertFalse($isMerchant(0), 'No root is not a merchant.');
    }

    /**
     * ONE RULE FOR EVERY PRINCIPAL KIND. The owned set is memoised per application instance KEYED BY `creatorId()` ALONE, so its value has to be a function of
     * that id alone. A first draft asked only principals that define `creatorId()` and left the rest on the old query: a principal WITHOUT one, whose
     * `created_by` is the operator, filled the memo with the operator's stores, and the next self-demoted merchant in the same application instance was served
     * them. The only production caller passes the `web` guard's `User`, so nothing real took the other branch; the rule is uniform because a branch nothing
     * exercises is a branch nobody checks. The plain principal goes FIRST, so that whatever it memoises is what the demoted merchant would be served.
     */
    public function test_the_scope_applies_one_rule_to_every_principal_kind_so_the_memo_cannot_mix_them(): void
    {
        [$operator] = $this->operatorWithStore();
        [$collapsed, $ownStore] = $this->collapsedMerchant();
        [$merchant, $merchantStore] = $this->makeTenant();
        $secondStore = $this->makeStore($merchant);
        \App\Scopes\StoreScope::forgetOwnedStoreIds();

        $plainRootedAtOperator = (object) ['id' => 987001, 'created_by' => $operator->id, 'current_store' => $ownStore->id];
        $plainRootedAtMerchant = (object) ['id' => 987002, 'created_by' => $merchant->id, 'current_store' => $merchantStore->id];
        $ids = fn ($principal): array => \App\Scopes\StoreScope::admissibleStoreIds($principal, (int) $principal->current_store);

        $this->assertSame([(int) $ownStore->id], $ids($plainRootedAtOperator), "A principal with no creatorId() and the operator as its creator was given the operator's stores.");
        $this->assertSame([(int) $ownStore->id], $ids($collapsed->fresh()), 'A self-demoted merchant was served a set memoised for a principal of another kind.');

        // POSITIVE CONTROL: the same rule gives a principal created by a merchant that merchant's stores
        $this->assertEqualsCanonicalizing([(int) $merchantStore->id, (int) $secondStore->id], $ids($plainRootedAtMerchant), 'Premise: the rule is the same for a principal that is not a User.');
    }

    /**
     * A tenant root whose `type` is `$type` FROM THE START -- created that way through the model, never raw-written afterwards, so `Cachable` cannot serve
     * `UserPolicy` a stale row -- with TWO stores it owns and a staff member working in the first. The second store is what makes the owned set tell
     * itself apart from the `[current_store]` fallback: with one store they are the same id, and an assertion on it cannot fail.
     *
     * @return array{0: User, 1: Store, 2: Store, 3: User}
     */
    private function rootTypedAs(string $type): array
    {
        $root = User::factory()->create(['type' => $type, 'created_by' => 1, 'is_active' => 1, 'email_verified_at' => now()]);
        $first = $this->makeStore($root);
        $second = $this->makeStore($root);
        $root->current_store = $first->id;
        $root->save();

        return [$root->fresh(), $first, $second, $this->makeStaff($root->fresh())];
    }

    /**
     * The scope reads the root's `type` the way `UserPolicy::tenantRootIsAMerchant()` does -- lower-cased, trailing SPACES ignored (MySQL's collation ignores
     * those and the case, but not a leading space, a tab or a newline) -- and this test CALLS THE POLICY on every spelling, so the two copies of the rule cannot
     * drift apart without a test failing. A merchant whose `type` is stored `ADMIN` or `admin ` (which the database treats as `admin`) must not have their staff
     * refused the merchant's own stores -- fail-closed, but a regression; and the spellings the policy refuses stay refused.
     */
    public function test_the_scopes_merchant_root_check_agrees_with_the_policy_on_every_spelling_of_type(): void
    {
        $this->operatorWithStore();
        $spellings = [
            'admin' => true, 'Admin' => true, 'ADMIN' => true, 'admin  ' => true,
            ' admin' => false, "admin\t" => false, "admin\n" => false, 'adminx' => false, 'super admin' => false, 'staff' => false,
        ];

        $fixtures = [];
        foreach ($spellings as $type => $expected) {
            $fixtures[$type] = $this->rootTypedAs((string) $type);
        }
        \App\Scopes\StoreScope::forgetOwnedStoreIds();

        $isMerchant = fn (int $rootId): bool => (new \ReflectionMethod(\App\Scopes\StoreScope::class, 'rootIsAMerchant'))->invoke(null, $rootId);
        $ids = fn (User $user): array => \App\Scopes\StoreScope::admissibleStoreIds($user, (int) $user->current_store);

        foreach ($spellings as $type => $expected) {
            [$root, $first, $second, $staff] = $fixtures[$type];
            $label = json_encode((string) $type);

            $this->assertSame($expected, UserPolicy::tenantRootIsAMerchant((int) $root->id), "Premise: the POLICY's answer for a root typed {$label}.");
            $this->assertSame($expected, $isMerchant((int) $root->id), "The scope's merchant-root check disagrees with the policy for a root typed {$label}.");

            $expectedIds = $expected ? [(int) $first->id, (int) $second->id] : [(int) $first->id];
            $this->assertEqualsCanonicalizing($expectedIds, $ids($staff->fresh()), "The scope's owned set for the staff of a root typed {$label} (the second store is "
                .($expected ? 'reachable' : 'NOT reachable').').');
        }
    }

    /** What BulkDeleteTenantGuard reads: `StoreScope::resolveTenant()['store_ids']`. */
    public function test_the_resolved_tenant_of_a_self_demoted_merchant_is_their_current_store_only(): void
    {
        [$operator, $operatorStore] = $this->operatorWithStore();
        [$collapsed, $ownStore] = $this->collapsedMerchant();
        [$merchant, $merchantStore] = $this->makeTenant();
        $secondStore = $this->makeStore($merchant);
        \App\Scopes\StoreScope::forgetOwnedStoreIds();

        $this->actingAs($collapsed->fresh());
        $tenant = \App\Scopes\StoreScope::resolveTenant();
        $this->assertSame(\App\Scopes\StoreScope::MODE_TENANT, $tenant['mode'], 'the mode must stay TENANT: a narrower SET is the change, not an unresolved tenant (which is open)');
        $this->assertSame([(int) $ownStore->id], array_map('intval', $tenant['store_ids']), "The tenant of a self-demoted merchant includes the operator's stores.");

        // POSITIVE CONTROLS
        $this->actingAs($merchant->fresh());
        $this->assertEqualsCanonicalizing([(int) $merchantStore->id, (int) $secondStore->id], array_map('intval', \App\Scopes\StoreScope::resolveTenant()['store_ids']), 'Premise: a merchant resolves to all of their stores.');
        $this->actingAs($operator->fresh());
        $this->assertSame(\App\Scopes\StoreScope::MODE_SUPER_ADMIN, \App\Scopes\StoreScope::resolveTenant()['mode'], 'Premise: the operator keeps the platform bypass.');
    }

    /** A handler whose ONLY tenant control is the scope: `Menu::findOrFail($id)->delete()` behind a permission check. */
    public function test_a_self_demoted_merchant_cannot_delete_a_menu_of_the_operators_store(): void
    {
        [$operator, $operatorStore] = $this->operatorWithStore();
        [$collapsed, $ownStore] = $this->collapsedMerchant();
        [$merchant, $merchantStore] = $this->makeTenant();
        $this->grantPermission($collapsed, 'Delete Menu');
        $this->grantPermission($merchant, 'Delete Menu');
        $operatorsMenu = \App\Models\Menu::forceCreate(['name' => 'operator menu', 'store_id' => $operatorStore->id]);
        $ownMenu = \App\Models\Menu::forceCreate(['name' => 'merchant menu', 'store_id' => $merchantStore->id]);
        $demotedsOwnMenu = \App\Models\Menu::forceCreate(['name' => 'demoted merchant menu', 'store_id' => $ownStore->id]);
        \App\Scopes\StoreScope::forgetOwnedStoreIds();

        // THE PRINCIPAL REACHES THE HANDLER AND HOLDS THE GRANT: they delete a menu of their OWN store. Without this, "the operator's menu still exists" cannot tell the
        // scope from a permission refusal (which answers a redirect) or a route that never reached the controller.
        $this->actingAs($collapsed->fresh())->delete('/menus/'.$demotedsOwnMenu->id);
        $this->assertFalse(DB::table('menus')->where('id', $demotedsOwnMenu->id)->exists(), 'Premise: the self-demoted merchant holds Delete Menu and reaches the handler, so a refusal below is the scope.');

        // and the operator's menu: a 404 is what `Menu::findOrFail()` answers under the narrowed scope -- a permission refusal and a success are both redirects
        $this->actingAs($collapsed->fresh())->delete('/menus/'.$operatorsMenu->id)->assertNotFound();
        $this->assertTrue(DB::table('menus')->where('id', $operatorsMenu->id)->exists(), "A self-demoted merchant deleted a menu of the operator's store through the global scope.");

        // POSITIVE CONTROL: a merchant deletes their own menu, so "still there" cannot pass on a route that refuses everyone
        $this->actingAs($merchant->fresh())->delete('/menus/'.$ownMenu->id);
        $this->assertFalse(DB::table('menus')->where('id', $ownMenu->id)->exists(), 'Premise: a merchant can delete their own menu.');
    }

    // ---- Api\DashboardController::resolveManageableUser -- the P0 predicate, shielded only by call order -------------------------------------

    /**
     * The resolver that decides which user an admin-API caller may rewrite (`EditProfile` writes email and name, `UpdatePassword` the password) was
     * `target.created_by === actor.creatorId()` -- the exact predicate P0-TENANT-ROOT removed from `UserPolicy`: for a user rooted at the platform it
     * matches EVERY merchant, since every merchant has `created_by = 1`. Both callers ran `resolveOwnedStore()` first, which now refuses that principal,
     * so it was not reachable; this makes the resolver safe on its own, so a reordered or a new caller cannot reopen the account takeover.
     */
    public function test_the_admin_apis_manageable_user_resolver_refuses_a_self_demoted_merchant_every_other_merchant(): void
    {
        [$operator] = $this->operatorWithStore();
        [$collapsed] = $this->collapsedMerchant();
        [$victim] = $this->makeTenant();
        [$merchant] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);

        $resolve = function (User $actor, $targetId): ?User {
            $this->actingAs($actor);
            $controller = app(\App\Http\Controllers\Api\DashboardController::class);
            $method = new \ReflectionMethod($controller, 'resolveManageableUser');
            $method->setAccessible(true);

            return $method->invoke($controller, $targetId);
        };

        $this->assertNull($resolve($collapsed->fresh(), $victim->id), 'A self-demoted merchant resolved ANOTHER MERCHANT as a user they may manage (the P0 account takeover).');

        // POSITIVE CONTROLS: yourself, a merchant's own staff, and the operator anyone -- and a merchant still cannot reach another merchant
        $this->assertSame((int) $collapsed->id, (int) $resolve($collapsed->fresh(), $collapsed->id)?->id, 'Premise: a user always resolves themselves.');
        $this->assertSame((int) $staff->id, (int) $resolve($merchant->fresh(), $staff->id)?->id, "Premise: a merchant resolves their own staff.");
        $this->assertNull($resolve($merchant->fresh(), $victim->id), 'Premise: a merchant cannot resolve another merchant.');
        $this->assertSame((int) $victim->id, (int) $resolve($operator->fresh(), $victim->id)?->id, 'Premise: the operator resolves anyone.');
    }
}
