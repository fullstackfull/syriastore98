<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * R4-04 cross-tenant IDOR, the two exemplars.
 *
 * CustomerController::destroy had NO checks at all -- no permission, no tenant
 * scope -- while index() and show() in the same controller both check. Its
 * parameter is route-model bound, so Laravel resolved any customer id in the
 * table and deleted it.
 *
 * ProductController::destroy checked 'Delete Products' but not ownership, which
 * is worse than a database-only IDOR: before any row is deleted it unlinks files
 * from disk (each ProductImage image_path, the cover image, the downloadable
 * product, a video preview), so a cross-tenant call destroyed another
 * merchant's uploaded media irreversibly and mis-accounted the victim's storage
 * against the attacker via changeproductStorageLimit(creatorId()).
 *
 * These tests run for real: customers, products and product_images are all
 * declared by migrations and all carry store_id (verified).
 *
 * PERMISSION NAMES USED HERE ARE REAL. 'Manage Customer' and 'Delete Products'
 * are both created by Utility::addNewData(). There is deliberately no
 * 'Delete Customer' anywhere in these tests, because that row does not exist --
 * the only customer permissions seeded are Manage/Show/Status Customer. Gating
 * on an invented name is the defect that locked out the finance surface.
 */
class CrossTenantDeletionTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * Guards the premise of every assertion below.
     */
    public function test_the_permissions_these_fixes_gate_on_actually_exist(): void
    {
        $utility = file_get_contents(base_path('app/Models/Utility.php'));

        foreach (['Manage Customer', 'Delete Products'] as $permission) {
            $this->assertGreaterThan(
                0,
                substr_count($utility, '"' . $permission . '"')
                + substr_count($utility, "'" . $permission . "'"),
                "{$permission} must be created by Utility::addNewData()."
            );
        }

        // The name NOT used, and why.
        $this->assertSame(
            0,
            substr_count($utility, '"Delete Customer"')
            + substr_count($utility, "'Delete Customer'"),
            'If Delete Customer is now seeded, CustomerController::destroy may be '
            . 'narrowed to it. Until then, gating on it would 403 everybody.'
        );
    }

    /**
     * NOTE ON billable_id / billable_type -- these are set here but production
     * does NOT set them, and that is a real finding rather than a test detail.
     *
     * laravel/cashier-paddle ships
     * database/migrations/2019_05_03_000001_create_customers_table.php, which
     * creates a `customers` table with billable_id and billable_type NOT NULL
     * and no default. It sorts before the application's own
     * 2023_12_15_112855_create_customers_table.php, whose body is wrapped in
     * `if (!Schema::hasTable('customers'))` -- so on a database built from
     * source the application's create is SKIPPED entirely and the table is
     * Cashier's, with the app's own columns bolted on by later ALTERs.
     *
     * The consequence: CustomerController::store() does not set billable_id, so
     * creating a customer through the application fails with "NOT NULL
     * constraint failed: customers.billable_id" on any from-source database.
     * That is pre-existing, is nothing to do with this change, and is reported
     * as an owner/dependency item -- it cannot be fixed here because the remedy
     * touches composer.json or a migration, both outside this agent's files.
     *
     * Setting the two columns here is therefore a FIXTURE accommodation of a
     * broken schema, not an invented precondition: on the live MySQL database
     * customer rows demonstrably exist. The assertions below are about whether
     * destroy() authorizes correctly GIVEN a customer row, which does not depend
     * on how the row came to be.
     */
    private function makeCustomerIn($store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Cust',
            'last_name' => 'Omer',
            'email' => 'cust' . uniqid() . '@example.test',
            'password' => bcrypt('not-a-real-credential'),
            'store_id' => $store->id,
            'status' => 1,
            'billable_id' => $store->created_by,
            'billable_type' => \App\Models\User::class,
        ]);
    }

    private function makeProductIn($store, $owner): Product
    {
        return Product::forceCreate([
            'name' => 'prod-' . uniqid(),
            'slug' => 'prod-' . uniqid(),
            'product_stock' => 3,
            // track_stock is NOT NULL with no default in the real schema.
            'track_stock' => 0,
            'variant_product' => 0,
            'store_id' => $store->id,
            'created_by' => $owner->id,
        ]);
    }

    // ---------------------------------------------------------------- customers

    /**
     * CustomerController::destroy cannot be reached over HTTP on a database
     * built from source, so its authorization is exercised by invoking the
     * method directly with a Customer instance.
     *
     * WHY. App\Models\Customer uses SoftDeletes, but `customers.deleted_at` is
     * declared by NO migration. The SoftDeletes global scope therefore fires
     * during ROUTE-MODEL BINDING and the request dies before any controller
     * code runs:
     *
     *   SQLSTATE[HY000]: no such column: customers.deleted_at
     *   (select * from "customers" where "id" = ? and "customers"."deleted_at" is null)
     *
     * Verified by execution with withoutExceptionHandling(). That breaks
     * customer/{customer} show, update and destroy alike, is pre-existing, and
     * is nothing to do with this change -- it is the same undeclared-column
     * pattern as the finance tables and users.deliverysyria_secret.
     *
     * Calling the method directly is NOT a way of dodging a red test: it runs
     * the real authorization branch on a real persisted Customer. What it does
     * not cover is the routing layer, which is noted as BLOCKED in the report.
     */
    private function invokeDestroy(Customer $customer)
    {
        return (new \App\Http\Controllers\CustomerController())->destroy($customer);
    }

    public function test_a_merchant_cannot_delete_another_stores_customer(): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker] = $this->makeTenant();

        // The attacker genuinely holds the permission inside their OWN tenant.
        // This isolates the ownership defect from the permission defect.
        $this->grantPermission($attacker, 'Manage Customer');

        $customer = $this->makeCustomerIn($victimStore);

        $this->actingAs($attacker->fresh());

        $response = $this->invokeDestroy($customer);

        $this->assertStringContainsString(
            'Permission denied',
            (string) session('error'),
            "A merchant must be refused another store's customer."
        );

        // The row is untouched. Checked with a raw query, because the Eloquent
        // model's SoftDeletes scope cannot query this table at all.
        $this->assertTrue(
            \Illuminate\Support\Facades\DB::table('customers')
                ->where('id', $customer->id)->exists(),
            "The victim's customer row was removed."
        );
    }

    public function test_a_user_without_the_permission_cannot_delete_a_customer(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);

        $customer = $this->makeCustomerIn($store);

        $this->actingAs($staff);

        $this->invokeDestroy($customer);

        $this->assertStringContainsString(
            'Permission denied',
            (string) session('error'),
            'A user without Manage Customer must be refused.'
        );

        $this->assertTrue(
            \Illuminate\Support\Facades\DB::table('customers')
                ->where('id', $customer->id)->exists()
        );
    }

    /**
     * The positive case: a permitted merchant deleting their OWN customer must
     * still work, so the fix is not simply "deny everything".
     *
     * BLOCKED. The refusal branches above return before $customer->delete(),
     * which is why they run; this one reaches delete(), and SoftDeletes cannot
     * write customers.deleted_at because the column does not exist. Skipped
     * rather than asserted, because adding the column from a test would
     * manufacture a schema production does not have.
     *
     * CROSS-AGENT DEPENDENCY: customers.deleted_at (schema agent).
     */
    public function test_a_permitted_merchant_can_delete_their_own_customer(): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasColumn('customers', 'deleted_at')) {
            $this->markTestSkipped(
                'BLOCKED: App\Models\Customer uses SoftDeletes but '
                . 'customers.deleted_at is declared by no migration, so no delete '
                . 'can complete (cross-agent dependency -- schema agent).'
            );
        }

        [$merchant, $store] = $this->makeTenant();

        $this->grantPermission($merchant, 'Manage Customer');

        $customer = $this->makeCustomerIn($store);

        $this->actingAs($merchant->fresh());

        $this->invokeDestroy($customer);

        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
    }

    // ----------------------------------------------------------------- products

    public function test_a_merchant_cannot_delete_another_stores_product(): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker] = $this->makeTenant();

        $this->grantPermission($attacker, 'Delete Products');

        $product = $this->makeProductIn($victimStore, $victim);

        $this->actingAs($attacker->fresh())->delete('/product/' . $product->id);

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    /**
     * The file-unlink half, which is why this one matters more than a row.
     *
     * ProductImage rows are deleted and their files unlinked BEFORE the product
     * row goes, so a cross-tenant call had already destroyed the victim's media
     * by the time anything else happened. Asserting the image row survives
     * proves the ownership check runs before that loop, not after it.
     */
    public function test_a_cross_tenant_delete_does_not_remove_the_victims_product_images(): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker] = $this->makeTenant();

        $this->grantPermission($attacker, 'Delete Products');

        $product = $this->makeProductIn($victimStore, $victim);

        $image = ProductImage::forceCreate([
            'product_id' => $product->id,
            'image_path' => 'storage/uploads/does-not-exist-' . uniqid() . '.png',
        ]);

        $this->actingAs($attacker->fresh())->delete('/product/' . $product->id);

        $this->assertDatabaseHas('product_images', ['id' => $image->id]);
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_a_user_without_the_permission_cannot_delete_a_product(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);

        $product = $this->makeProductIn($store, $merchant);

        $this->actingAs($staff)->delete('/product/' . $product->id);

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }
}
