<?php

namespace Tests\Support\Kyc;

use App\Models\KycVerification;
use App\Models\Store;
use App\Models\User;
use App\Services\Kyc\KycAuthority;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Fixtures for the KYC family, written so a test cannot pass for the wrong reason.
 *
 * THREE DELIBERATE CHOICES, each one a failure this project has already had:
 *
 *  1. A MERCHANT IS A TYPE-`admin` ROW AND OWNS THEIR STORE. `UserPolicy::merchantTenantRoot()`
 *     returns null for anything else, so a fixture merchant typed `'user'` would make every
 *     ownership limb refuse and a test asserting a refusal would pass without exercising it. The
 *     whole family of "tenant root is a merchant" defects comes from that row's `type`.
 *  2. `Storage::fake('kyc')` IS CALLED BY THE TEST, NOT HERE. Faking a disk writes to
 *     `storage/framework/testing/disks/`, which is SHARED by every PHPUnit process in one worktree
 *     (CLAUDE.md records a measured cross-process collision reading exactly like a regression), so
 *     which tests fake which disk stays visible in the test rather than hidden in a trait.
 *  3. AN UPLOADED FILE IS A REAL PNG (`UploadedFile::fake()->image()`), because
 *     `PlatformUploadPolicy` checks the file's SIGNATURE with `mimes:`. A zero-byte
 *     `fake()->create('x.png')` is refused by the real rule, so a test built on one would prove
 *     only that the refusal works.
 */
trait MakesKycFixtures
{
    /** @return array{0: User, 1: Store} a type-`admin` merchant and a store they own */
    protected function kycMerchantWithStore(): array
    {
        $merchant = User::create([
            'name' => 'KYC Merchant',
            'email' => 'kyc-merchant-' . bin2hex(random_bytes(5)) . '@example.test',
            'password' => bcrypt('secret-' . bin2hex(random_bytes(4))),
            'type' => 'admin',
            'language' => 'en',
            'created_by' => 1,
        ]);

        $store = Store::create([
            'name' => 'KYC Store ' . bin2hex(random_bytes(3)),
            'email' => 'kyc-store-' . bin2hex(random_bytes(5)) . '@example.test',
            'theme_id' => 'stylique',
            'slug' => 'kyc-store-' . bin2hex(random_bytes(5)),
            'created_by' => $merchant->id,
            'is_active' => 1,
        ]);

        $merchant->current_store = $store->id;
        $merchant->save();

        return [$merchant, $store];
    }

    protected function kycOperator(): User
    {
        return User::create([
            'name' => 'Platform Operator',
            'email' => 'kyc-operator-' . bin2hex(random_bytes(5)) . '@example.test',
            'password' => bcrypt('secret-' . bin2hex(random_bytes(4))),
            'type' => 'super admin',
            'language' => 'en',
        ]);
    }

    /** Staff of a merchant: `created_by` the merchant, and NOT type `admin`. */
    protected function kycStaff(User $merchant, Store $store): User
    {
        return User::create([
            'name' => 'KYC Staff',
            'email' => 'kyc-staff-' . bin2hex(random_bytes(5)) . '@example.test',
            'password' => bcrypt('secret-' . bin2hex(random_bytes(4))),
            'type' => 'user',
            'language' => 'en',
            'created_by' => $merchant->id,
            'current_store' => $store->id,
        ]);
    }

    protected function kycRecord(
        Store $store,
        string $authority = KycAuthority::STORE_SYRIA,
        string $subjectType = KycVerification::COMPANY,
        ?User $actor = null
    ): KycVerification {
        $record = new KycVerification();
        $record->store_id = $store->id;
        $record->authority = $authority;
        $record->subject_type = $subjectType;
        $record->status = KycVerification::UNSUBMITTED;
        $record->created_by = $actor?->id;
        $record->save();

        return $record;
    }

    /**
     * Configure the owner's document kinds for one subject type and authority.
     *
     * Needed by almost every test, because the shipped default is EMPTY and an empty list refuses
     * every upload — the data-minimisation default `config/kyc.php` explains.
     *
     * @param  list<string>  $kinds
     */
    protected function configureKycKinds(string $subjectType, string $authority, array $kinds): void
    {
        config(['kyc.document_kinds.' . $subjectType . '.' . $authority => $kinds]);
    }

    /**
     * Give a user a real Laratrust role carrying a permission, in ONE store.
     *
     * `forceCreate`, NOT `create`, and `SecurityTestCase::makeRole()` records why: `App\Models\Role`
     * sets `$guarded = []` but extends Laratrust's Role, whose NON-EMPTY `$fillable` takes
     * precedence in `Model::isFillable()` — so `store_id` would be silently dropped and the role
     * would become the shared global one, which `MerchantPermissions` treats as the merchant's own
     * in every store. A test built on that would prove the opposite of what it claims.
     */
    protected function grantKycPermission(User $user, string $permission, int $storeId): \App\Models\Role
    {
        $p = \App\Models\Permission::firstOrCreate(
            ['name' => $permission],
            ['display_name' => $permission]
        );

        $role = \App\Models\Role::forceCreate([
            'name' => 'kyc-role-' . $user->id . '-' . bin2hex(random_bytes(3)),
            'display_name' => 'KYC Role',
            'guard_name' => 'web',
            'created_by' => $user->creatorId(),
            'store_id' => $storeId,
        ]);

        $role->givePermission($p);
        $user->addRole($role);
        $user->flushCache();

        return $role;
    }

    /** A real PNG the platform uploader rule accepts. See choice 3 above. */
    protected function kycPngUpload(string $name = 'passport.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 40, 40);
    }

    /** The files really on the private disk, for an "it was stored" / "it was not" assertion. */
    protected function kycStoredFiles(): array
    {
        $files = Storage::disk(config('kyc.disk', 'kyc'))->allFiles();
        sort($files);

        return $files;
    }
}
