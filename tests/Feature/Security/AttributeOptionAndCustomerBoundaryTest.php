<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * THE TWO SECURITY FINDINGS CLAUDE B REPORTED, verified and closed.
 *
 * P01B-SEC-1  ProductAttributeOptionController -- B reported show() and option(). Verified, and
 *             edit(), update() and destroy() turned out to carry the same defect; two of those
 *             MUTATE and one DELETES, so the reported pair was the smaller half.
 * P01B-SEC-2  CustomerController::store()/create() -- B reported the permission check as removed
 *             for testing and still absent. Verified: there was none.
 *
 * Neither ProductAttributeOption nor ProductAttribute is on config/tenancy.php's `scoped_models`
 * allowlist and neither carries the tenancy trait, so there was no global scope to fall back on.
 */
class AttributeOptionAndCustomerBoundaryTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** @return array{0: \App\Models\User, 1: \App\Models\Store, 2: ProductAttribute, 3: ProductAttributeOption} */
    private function tenantWithAttribute(string $label): array
    {
        [$owner, $store] = $this->makeTenant();

        $name = 'attr-' . $label . '-' . uniqid();

        $attribute = ProductAttribute::forceCreate([
            'name' => $name,
            // product_attributes.slug is declared string() with neither nullable() nor a default
            // (database/migrations/2023_12_27_054045_create_product_attributes_table.php:17), so
            // omitting it is a NOT NULL violation, not an empty value.
            'slug' => $name,
            'store_id' => $store->id,
        ]);

        $option = ProductAttributeOption::forceCreate([
            'attribute_id' => $attribute->id,
            'terms' => 'term-' . $label,
            'order' => 0,
            'store_id' => $store->id,
        ]);

        return [$owner, $store, $attribute, $option];
    }

    private function grantAttributePermissions(\App\Models\User $user): void
    {
        foreach ([
            'Manage Attributes Option',
            'Create Attributes Option',
            'Edit Attributes Option',
            'Delete Attributes Option',
        ] as $permission) {
            $this->grantPermission($user, $permission);
        }
    }

    // ===================================================================================
    // THE PREMISE -- the permissions this fix gates on must really be seeded, or the gate
    // locks out every legitimate user instead of only attackers.
    // ===================================================================================

    public function test_the_premise_every_permission_these_gates_use_is_seeded(): void
    {
        \App\Models\Utility::addNewData();

        foreach ([
            'Manage Attributes Option',
            'Create Attributes Option',
            'Edit Attributes Option',
            'Delete Attributes Option',
            'Manage Customer',
        ] as $permission) {
            $this->assertNotNull(
                \App\Models\Permission::where('name', $permission)->first(),
                "Utility::addNewData() does not create '{$permission}', so gating on it would 403 "
                . 'every legitimate user on a database built from source.'
            );
        }

        $this->assertNull(
            \App\Models\Permission::where('name', 'Create Customer')->first(),
            'A seeded "Create Customer" now exists. The customer gate deliberately uses '
            . '"Manage Customer" BECAUSE that name did not exist; re-read the choice.'
        );
    }

    // ===================================================================================
    // P01B-SEC-1 -- CROSS-TENANT READS
    // ===================================================================================

    public function test_a_merchant_cannot_read_another_tenants_attribute_options(): void
    {
        [$ownerA] = $this->tenantWithAttribute('a');
        [$ownerB, $storeB, $attributeB, $optionB] = $this->tenantWithAttribute('b');

        $this->grantAttributePermissions($ownerA);

        $response = $this->actingAs($ownerA)->get('/attribute-option/' . $attributeB->id);

        $this->assertNotSame(200, $response->getStatusCode(),
            "Merchant A received a 200 for merchant B's attribute options.");
        $this->assertStringNotContainsString('term-b', $response->getContent());
    }

    public function test_a_merchant_cannot_open_another_tenants_attribute_option_for_edit(): void
    {
        [$ownerA] = $this->tenantWithAttribute('a');
        [$ownerB, $storeB, $attributeB, $optionB] = $this->tenantWithAttribute('b');

        $this->grantAttributePermissions($ownerA);

        $response = $this->actingAs($ownerA)->get('/edit-attribute-option/' . $optionB->id);

        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('term-b', $response->getContent());
    }

    public function test_a_user_without_the_permission_cannot_read_attribute_options_at_all(): void
    {
        [$owner, $store, $attribute] = $this->tenantWithAttribute('own');

        // The owner's OWN attribute, but no permission granted.
        $response = $this->actingAs($owner)->get('/attribute-option/' . $attribute->id);

        $this->assertNotSame(200, $response->getStatusCode(),
            'show() admitted a caller holding no attribute-option permission.');
    }

    // ===================================================================================
    // P01B-SEC-1 -- CROSS-TENANT WRITES. These are the half B did not report.
    // ===================================================================================

    public function test_a_merchant_cannot_reorder_another_tenants_attribute_options(): void
    {
        [$ownerA] = $this->tenantWithAttribute('a');
        [$ownerB, $storeB, $attributeB, $optionB] = $this->tenantWithAttribute('b');

        $this->grantAttributePermissions($ownerA);

        $before = (int) $optionB->fresh()->order;

        $this->actingAs($ownerA)
            ->post('/product-attribute/option', ['terms' => [5 => $optionB->id]])
            ->assertForbidden();

        $this->assertSame($before, (int) $optionB->fresh()->order,
            "Merchant A changed the ordering of merchant B's attribute option.");
    }

    /**
     * ALL-OR-NOTHING. A payload mixing the caller's own id with a foreign one must reorder
     * NEITHER -- otherwise the foreign id is rejected while the caller's own rows have already
     * been rewritten, and a partial write is still a write the caller did not earn.
     */
    public function test_a_mixed_reorder_payload_changes_nothing_at_all(): void
    {
        [$ownerA, $storeA, $attributeA, $optionA] = $this->tenantWithAttribute('a');
        [$ownerB, $storeB, $attributeB, $optionB] = $this->tenantWithAttribute('b');

        $this->grantAttributePermissions($ownerA);

        $beforeA = (int) $optionA->fresh()->order;
        $beforeB = (int) $optionB->fresh()->order;

        $this->actingAs($ownerA)
            ->post('/product-attribute/option', ['terms' => [7 => $optionA->id, 8 => $optionB->id]])
            ->assertForbidden();

        $this->assertSame($beforeA, (int) $optionA->fresh()->order,
            'The caller\'s own option was reordered even though the request was refused.');
        $this->assertSame($beforeB, (int) $optionB->fresh()->order);
    }

    public function test_a_merchant_cannot_update_another_tenants_attribute_option(): void
    {
        [$ownerA] = $this->tenantWithAttribute('a');
        [$ownerB, $storeB, $attributeB, $optionB] = $this->tenantWithAttribute('b');

        $this->grantAttributePermissions($ownerA);

        $this->actingAs($ownerA)
            ->post('/update-attribute-option/' . $optionB->id, ['terms' => 'hijacked']);

        $this->assertSame('term-b', $optionB->fresh()->terms,
            "Merchant A rewrote merchant B's attribute option.");
    }

    public function test_a_merchant_cannot_delete_another_tenants_attribute_option(): void
    {
        [$ownerA] = $this->tenantWithAttribute('a');
        [$ownerB, $storeB, $attributeB, $optionB] = $this->tenantWithAttribute('b');

        $this->grantAttributePermissions($ownerA);

        $this->actingAs($ownerA)->delete('/delete-attribute-option/' . $optionB->id);

        $this->assertNotNull(ProductAttributeOption::find($optionB->id),
            "Merchant A deleted merchant B's attribute option.");
    }

    public function test_a_merchant_cannot_attach_an_option_to_another_tenants_attribute(): void
    {
        [$ownerA] = $this->tenantWithAttribute('a');
        [$ownerB, $storeB, $attributeB] = $this->tenantWithAttribute('b');

        $this->grantAttributePermissions($ownerA);

        $this->actingAs($ownerA)
            ->post('/store-attribute-option/' . $attributeB->id, ['terms' => 'smuggled']);

        $this->assertSame(
            0,
            ProductAttributeOption::where('terms', 'smuggled')->count(),
            "An option was attached to merchant B's attribute."
        );
    }

    // ===================================================================================
    // P01B-SEC-1 -- THE LEGITIMATE FLOWS MUST SURVIVE
    // ===================================================================================

    public function test_a_merchant_can_still_read_and_reorder_their_own_attribute_options(): void
    {
        [$owner, $store, $attribute, $option] = $this->tenantWithAttribute('own');
        $this->grantAttributePermissions($owner);

        $this->actingAs($owner)
            ->get('/attribute-option/' . $attribute->id)
            ->assertStatus(200);

        $this->actingAs($owner)
            ->post('/product-attribute/option', ['terms' => [3 => $option->id]])
            ->assertStatus(200);

        $this->assertSame(3, (int) $option->fresh()->order,
            'The ordinary reorder flow no longer works for the owner.');
    }

    public function test_a_merchant_can_still_update_their_own_attribute_option(): void
    {
        [$owner, $store, $attribute, $option] = $this->tenantWithAttribute('own');
        $this->grantAttributePermissions($owner);

        $this->actingAs($owner)
            ->post('/update-attribute-option/' . $option->id, ['terms' => 'renamed']);

        $this->assertSame('renamed', $option->fresh()->terms,
            'The owner can no longer rename their own attribute option.');
    }

    // ===================================================================================
    // S14 -- /order/export
    //
    // Reported to me as "dumps every order in the store to anyone authenticated, with no order
    // permission at all". The second half was true; the first was overstated.
    // App\Exports\OrderExport::collection() already does
    // `Order::where('store_id', $store->id)` with $store from getCurrentStore(), so the rows were
    // never cross-tenant. The real defect was that the order book had a file-shaped hole in its
    // permission boundary: the order LIST needs 'Manage Order' and the spreadsheet of the same
    // rows needed nothing.
    // ===================================================================================

    public function test_a_user_without_manage_order_cannot_export_the_order_book(): void
    {
        [$owner, $store] = $this->makeTenant();
        $staff = $this->makeStaff($owner, ['current_store' => $store->id]);

        $response = $this->actingAs($staff)->get('/order/export');

        $this->assertNotSame(200, $response->getStatusCode(),
            'A staff member holding no order permission downloaded the order book.');
    }

    public function test_a_merchant_with_manage_order_can_still_export(): void
    {
        [$owner, $store] = $this->makeTenant();
        $this->makeOrder($store);
        $this->grantPermission($owner, 'Manage Order');

        $this->actingAs($owner)->get('/order/export')->assertStatus(200);
    }

    /**
     * A caller with the permission but NO store context used to reach
     * OrderExport::collection(), where `getStoreById(null)` returns null and `$store->id` raised
     * a 500. It is a refusal now, not a stack trace.
     */
    public function test_a_merchant_with_no_store_context_does_not_500_on_export(): void
    {
        $stray = $this->makeMerchant();
        $stray->current_store = null;
        $stray->save();
        $this->grantPermission($stray->fresh(), 'Manage Order');

        $response = $this->actingAs($stray->fresh())->get('/order/export');

        $this->assertNotSame(500, $response->getStatusCode(),
            'The export still raises a server error for a caller with no store context.');
    }

    // ===================================================================================
    // P01B-SEC-2 -- ADD CUSTOMER
    // ===================================================================================

    public function test_a_user_without_manage_customer_cannot_create_a_customer(): void
    {
        [$owner, $store] = $this->makeTenant();
        $staff = $this->makeStaff($owner, ['current_store' => $store->id]);

        $this->actingAs($staff)->post('/customer', [
            'first_name' => 'Unauthorized',
            'last_name' => 'Creation',
            'email' => 'unauthorized@example.test',
            'password' => 'not-a-real-password',
            'status' => 1,
        ]);

        $this->assertNull(
            Customer::where('email', 'unauthorized@example.test')->first(),
            'A user holding no customer permission created a customer. A hidden button is not a fix.'
        );
    }

    public function test_the_add_customer_form_is_also_gated_server_side(): void
    {
        [$owner, $store] = $this->makeTenant();
        $staff = $this->makeStaff($owner, ['current_store' => $store->id]);

        $response = $this->actingAs($staff)->get('/customer/create');

        $this->assertNotSame(200, $response->getStatusCode(),
            'The create form rendered for a caller who cannot create a customer.');
    }

    public function test_a_merchant_with_manage_customer_can_still_create_one_in_their_own_store(): void
    {
        [$owner, $store] = $this->makeTenant();
        $this->grantPermission($owner, 'Manage Customer');

        $this->actingAs($owner)->post('/customer', [
            'first_name' => 'Legit',
            'last_name' => 'Customer',
            'email' => 'legitcustomer@example.test',
            'password' => 'not-a-real-password',
            'status' => 1,
        ]);

        $created = Customer::where('email', 'legitcustomer@example.test')->first();

        $this->assertNotNull($created, 'The ordinary add-customer flow was broken by the fix.');
        $this->assertSame((int) $store->id, (int) $created->store_id,
            'The customer was not created in the acting merchant\'s own store.');
    }

    /**
     * THE `?? 1` FALLBACK. store() created the row with
     * `'store_id' => auth()->user()->current_store ?? 1`, so a merchant with no current_store
     * silently created a customer in STORE 1 -- a store belonging to someone else.
     */
    public function test_a_merchant_with_no_store_context_cannot_create_a_customer_in_store_one(): void
    {
        [$other, $storeOne] = $this->makeTenant();

        $stray = $this->makeMerchant();
        $stray->current_store = null;
        $stray->save();
        $stray = $stray->fresh();
        $this->grantPermission($stray, 'Manage Customer');

        $this->actingAs($stray)->post('/customer', [
            'first_name' => 'Stray',
            'last_name' => 'Customer',
            'email' => 'straycustomer@example.test',
            'password' => 'not-a-real-password',
            'status' => 1,
        ]);

        $created = Customer::where('email', 'straycustomer@example.test')->first();

        if ($created !== null) {
            $this->assertNotSame(1, (int) $created->store_id,
                'A storeless merchant created a customer in store 1, which is another tenant.');
        } else {
            $this->assertNull($created, 'Refused outright, which is the preferred outcome.');
        }
    }
}
