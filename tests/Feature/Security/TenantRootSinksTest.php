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
 * NOT FIXED HERE, AND WHY: `StoreScope::resolveTenant()` (the global tenancy scope, and through it `BulkDeleteTenantGuard`'s owned-store set) builds
 * the same set the same way, but `config/tenancy.php` `null_tenant => 'open'` means "no tenant" adds NO predicate -- patching it to return nothing
 * for these principals would make them see EVERYONE. That needs a closed mode, not a one-line change: recorded as P1-SEC-67, open.
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
}
