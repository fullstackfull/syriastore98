<?php

namespace Tests\Feature\Security;

use App\Models\Role;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * P1-SEC-67c -- A PRINCIPAL ROOTED AT THE OPERATOR COULD WRITE THE PLATFORM'S STORAGE CONFIGURATION.
 * ================================================================================================
 *
 * Found by the independent review of the first 67c slice (lens "completeness"), and read against the code by its author before this test was written.
 *
 * `Utility::upload_file()` and `jsonUpload_file()` decide where EVERY upload on the platform goes by reading
 *
 *     Setting::where('created_by', 1)->pluck('value', 'name')
 *
 * -- every `settings` row stamped with the operator's id, in ANY store, no store predicate. For the operator that is the platform's storage configuration.
 *
 * The Settings writers (`StorageSettings`, `CustomizeSetting`, ...) save with `updateOrCreate(..., ['created_by' => auth()->user()->id])`, and
 * `SettingOwnership::keep()` -- the `Setting::saving` hook of P1-PLT-07 -- re-stamps a NEW row from the writer's own id to `$user->creatorId()`, "the
 * tenant root", so that a merchant's staff write rows their merchant can see. For a merchant or their staff that is the merchant. For a principal whose
 * `created_by` is the platform operator -- a merchant who demoted their own `type` through `PUT /users/{self}`, or staff the operator created -- the "tenant
 * root" is the OPERATOR, so a row they create is stamped `created_by = 1` and joins the pool above. The shared `admin` role keeps `Manage Setting` after a
 * demotion (`UserController::update` only ADDS the new role), so any registered merchant could point every tenant's uploads at a bucket of their own, or make
 * every upload fail.
 *
 * Merchant stores are not seeded with `storage_setting` or `s3_*`, so those names take the CREATE path and `keep()` is reached -- the premise the existing
 * `SettingsCreatedByOwnershipTest` pins for its own names.
 *
 * THE FIX is the same shared definition the sinks use: `keep()` re-stamps only to a MERCHANT root (`UserPolicy::merchantTenantRoot()`), and leaves the writer's
 * own id otherwise. The operator is unaffected (their `creatorId()` is their own id and they are not a merchant: no re-stamp is needed), a merchant's staff
 * still write rows their merchant sees, and the demoted merchant's rows keep THEIR id, outside the pool.
 *
 * The demotion is done THROUGH THE PRODUCT with its premise asserted. The pool is read with the exact query `Utility::upload_file()` runs, so the test
 * measures the consequence rather than the stamp.
 */
class OperatorRootedSettingsWritesTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const ATTACKER_BUCKET = 'attacker-bucket-fixture';

    private User $operator;
    private Store $operatorStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = $this->makePlatformAdmin();
        $this->assertSame(1, (int) $this->operator->id, 'premise: the operator is user 1, the id every merchant has as created_by');
        $this->operatorStore = $this->makeStore($this->operator);
        $this->operator->current_store = $this->operatorStore->id;
        $this->operator->save();
        $this->operator = $this->operator->fresh();

        // THE PLATFORM'S storage configuration, as the installer leaves it: rows stamped with the operator's id.
        foreach (['storage_setting' => 'local', 'local_storage_validation' => 'jpg,jpeg,png', 'local_storage_max_upload_size' => '2048'] as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => $value, 'created_by' => 1, 'store_id' => $this->operatorStore->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** The P0 test's demotion, with its premise asserted; they keep the permission the shared `admin` role holds. */
    private function selfDemotedMerchant(): User
    {
        [$merchant] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit User');
        $role = Role::forceCreate([
            'name' => 'Manager', 'display_name' => 'Manager', 'guard_name' => 'web',
            'created_by' => $merchant->creatorId(), 'store_id' => $merchant->current_store,
        ]);

        $this->actingAs($merchant)->put("/users/{$merchant->id}", ['name' => $merchant->name, 'email' => $merchant->email, 'role' => $role->id]);

        $fresh = $merchant->fresh();
        $this->assertSame('Manager', $fresh->type, 'the self-demotion no longer happens, so these cases cannot reach the state they exist to test');
        $this->assertSame(1, (int) $fresh->creatorId(), "the principal's tenant root is no longer the platform, so the collapse is not being reproduced");
        $this->grantPermission($fresh, 'Manage Setting');

        return $fresh->fresh();
    }

    /** THE POOL, read with the query `Utility::upload_file()` and `jsonUpload_file()` run. */
    private function platformPool(): array
    {
        (new Setting())->flushCache();

        return Setting::where('created_by', 1)->pluck('value', 'name')->toArray();
    }

    private function s3Form(string $bucket): array
    {
        return [
            'storage_setting' => 's3', 's3_key' => 'AKIAFIXTURE', 's3_secret' => 'fixture-secret', 's3_region' => 'us-east-1', 's3_bucket' => $bucket,
            's3_url' => 'https://s3.example.test', 's3_endpoint' => 'https://s3.example.test', 's3_max_upload_size' => '2048', 's3_storage_validation' => ['jpg', 'png'],
        ];
    }

    private function row(Store $store, string $name): ?object
    {
        return DB::table('settings')->where('store_id', $store->id)->where('name', $name)->first();
    }

    public function test_a_self_demoted_merchant_cannot_write_the_platforms_storage_configuration(): void
    {
        $demoted = $this->selfDemotedMerchant();
        $ownStore = Store::find($demoted->current_store);

        $this->actingAs($demoted)->post('/storage-settings', $this->s3Form(self::ATTACKER_BUCKET));

        // PREMISE: the handler accepted the save, into THEIR OWN store -- otherwise "the pool is unchanged" would be a refusal measured, not a stamp.
        $this->assertSame(self::ATTACKER_BUCKET, $this->row($ownStore, 's3_bucket')?->value, 'Premise: the self-demoted merchant\'s save reached the writer.');

        $pool = $this->platformPool();
        $this->assertSame('local', $pool['storage_setting'] ?? null, "A self-demoted merchant's save replaced the PLATFORM's storage_setting: every upload on the platform now goes where they chose.");
        $this->assertArrayNotHasKey('s3_bucket', $pool, "A self-demoted merchant's s3_bucket is in the pool Utility::upload_file() reads for every tenant.");
        $this->assertSame(1, (int) $this->row(Store::find($this->operatorStore->id), 'storage_setting')->created_by, 'Premise: the operator\'s own row is untouched.');
    }

    public function test_the_generic_writer_cannot_be_used_the_same_way(): void
    {
        $demoted = $this->selfDemotedMerchant();
        $ownStore = Store::find($demoted->current_store);

        // `/customize-settings` writes EVERY posted name, so the smallest request that matters is one name
        $this->actingAs($demoted)->post('/customize-settings', ['storage_setting' => 's3']);

        $this->assertSame('s3', $this->row($ownStore, 'storage_setting')?->value, 'Premise: the generic writer accepted the save into their own store.');
        $this->assertSame('local', $this->platformPool()['storage_setting'] ?? null, "A single posted name from a self-demoted merchant overwrote the platform's storage_setting.");
    }

    /**
     * CREATION ONLY, and the name says so: this staff member's `current_store` is a FRESH operator store that holds no rows, so every name takes the create path.
     * Pointed at the store that holds the platform's own rows, the same POST would UPDATE them in place (an update keeps the stored owner) -- a delegate of the
     * operator acting on the operator's store, which the owner's fork f)(2) leaves open and which this change does NOT close. The ownership control the Settings
     * handlers lack (`getCurrentStore()` is never checked against the caller) is the real fix and is open.
     */
    public function test_staff_the_operator_created_do_not_create_rows_in_the_platforms_pool_either(): void
    {
        $operatorsStaff = User::factory()->create([
            'type' => 'staff', 'created_by' => $this->operator->id, 'current_store' => $this->makeStore($this->operator)->id, 'is_active' => 1, 'email_verified_at' => now(),
        ]);
        $this->grantPermission($operatorsStaff, 'Manage Setting');

        $this->actingAs($operatorsStaff->fresh())->post('/customize-settings', ['storage_setting' => 's3']);

        $this->assertSame('local', $this->platformPool()['storage_setting'] ?? null, "Staff the operator created overwrote the platform's storage_setting.");
    }

    /**
     * THE RULE, NOT THE POOL: staff of a self-demoted merchant are rooted at a user who is no longer a merchant (their `created_by` is a `Manager`, id other than
     * 1). A re-stamp to that root would not touch the platform's pool -- it stamps the demoted merchant's id -- but `UserPolicy::merchantTenantRoot()` calls the
     * root nobody's tenant everywhere else, so the row keeps the WRITER'S id here too. This is the case that separates "the root is a merchant" from the
     * narrower "the root is not the operator".
     */
    public function test_staff_of_a_self_demoted_merchant_keep_their_own_id_on_what_they_write(): void
    {
        $demoted = $this->selfDemotedMerchant();
        $staff = $this->makeStaff($demoted);
        $this->grantPermission($staff, 'Manage Setting');

        $this->actingAs($staff->fresh())->post('/customize-settings', ['metakeyword' => 'written-by-staff-of-the-demoted']);

        $row = $this->row(Store::find($demoted->current_store), 'metakeyword');
        $this->assertNotNull($row, 'Premise: the staff member\'s save reached the writer.');
        $this->assertSame((int) $staff->id, (int) $row->created_by, 'A non-merchant root was treated as a tenant root: the row was re-stamped away from its writer.');
    }

    /** The controls: a route that refuses everyone, or a hook that no longer re-stamps, would make every refusal above pass. */
    public function test_the_writers_still_stamp_what_they_always_did(): void
    {
        // a merchant saves their own storage settings: their rows, stamped with their id, outside the pool
        [$merchant, $store] = $this->makeTenant();
        $this->actingAs($merchant)->post('/storage-settings', $this->s3Form('merchant-own-bucket-fixture'));
        $this->assertSame('merchant-own-bucket-fixture', $this->row($store, 's3_bucket')?->value, 'Premise: a merchant saves.');
        $this->assertSame((int) $merchant->id, (int) $this->row($store, 's3_bucket')->created_by, 'A merchant\'s row is stamped with their id.');
        $this->assertArrayNotHasKey('s3_bucket', $this->platformPool(), 'A merchant\'s row is not in the platform pool.');

        // a merchant's STAFF are re-stamped to the MERCHANT (the normalisation the hook exists for): the merchant sees the row they wrote
        $staff = $this->makeStaff($merchant);
        $this->grantPermission($staff, 'Manage Setting');
        $this->actingAs($staff->fresh())->post('/customize-settings', ['metakeyword' => 'staff-wrote-this']);
        $this->assertSame((int) $merchant->id, (int) $this->row($store, 'metakeyword')?->created_by, "A merchant's staff must still write rows their MERCHANT owns -- the P1-PLT-07 normalisation.");

        // and the operator writes the pool through the same route: this is what the pool is FOR
        $this->actingAs($this->operator)->post('/customize-settings', ['storage_setting' => 'local']);
        $this->assertSame(1, (int) $this->row($this->operatorStore, 'storage_setting')->created_by, 'The operator\'s own save stays stamped with their id.');
    }
}
