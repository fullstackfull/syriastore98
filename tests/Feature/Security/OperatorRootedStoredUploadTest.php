<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\Utility;
use App\Services\StoredUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * P1-SEC-67d -- THE OPERATOR'S CROSS-TENANT FOLDER EXEMPTION BELONGS TO THE OPERATOR ACTING, NOT TO THE OPERATOR'S ID.
 * ============================================================================================
 *
 * `StoredUpload` rule 3 keeps a stored path inside the caller's tenant: `uploads/<store id>/...` resolves only for a store the given OWNER
 * owns -- unless that owner is the platform operator, who may act on any store's folder. The owner is whatever the caller passes, and about
 * thirty handlers pass `Auth::user()->creatorId()`. For a principal rooted at the platform -- a merchant who demoted their own `type` through
 * `PUT /users/{self}`, or staff the operator created -- `creatorId()` IS the operator's id, so they presented the operator's id and were
 * given the operator's exemption: any file in any tenant's upload folder, the operator's own included.
 *
 * The stored path is tenant-written (the generic settings writers store any name and value), so two ordinary requests were enough: store a
 * path, then replace the image it names. The two route cases at the foot of this file drive exactly that, and are the failing-before evidence.
 *
 * THE RULE NOW: when the owner presented is an operator and a panel user who is NOT the operator is acting, the exemption does not apply and
 * they get what they would get as themselves -- a store they own, and nothing else. Nothing changes for the operator, for a merchant, for a
 * merchant's staff, for staff of a self-demoted merchant (their `creatorId()` is their employer, never the operator), or where no panel user
 * is acting at all (the console, a queue job, a storefront customer).
 *
 * WHAT IT COSTS: staff the operator created can no longer unlink an old file in the operator's own store folder (the file is orphaned; the
 * save itself succeeds). They are indistinguishable from a demoted merchant by `creatorId()`; that is the owner's fork in the work queue.
 *
 * `uploads/` IS A TRACKED DIRECTORY (see StoredUploadTest): every store here is re-keyed to an id no real folder uses, the ids differ from
 * StoredUploadTest's so that the two classes cannot collide, and teardown removes only the folders named here.
 */
class OperatorRootedStoredUploadTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const OPERATOR_STORE_ID = 986001;
    private const VICTIM_STORE_ID = 986002;
    private const OWN_STORE_ID = 986003;
    private const FIXTURE_STORE_IDS = [self::OPERATOR_STORE_ID, self::VICTIM_STORE_ID, self::OWN_STORE_ID];

    private User $operator;
    private Store $operatorStore;
    private User $victim;
    private Store $victimStore;
    private User $merchant;
    private Store $ownStore;
    private bool $createdUploadsRoot = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createdUploadsRoot = ! is_dir(base_path('uploads'));
        foreach (self::FIXTURE_STORE_IDS as $id) {
            $this->assertDirectoryDoesNotExist(base_path('uploads/' . $id));
            $this->assertDirectoryDoesNotExist(base_path('uploads/uploads/' . $id));
        }

        // The operator FIRST, so that it is user 1 -- the id every merchant has as `created_by`.
        $this->operator = $this->makePlatformAdmin();
        $this->assertSame(1, (int) $this->operator->id, 'premise: the operator is user 1');
        [$this->operator, $this->operatorStore] = $this->rekey($this->operator, $this->makeStore($this->operator), self::OPERATOR_STORE_ID);

        [$victim, $victimStore] = $this->makeTenant();
        [$this->victim, $this->victimStore] = $this->rekey($victim, $victimStore, self::VICTIM_STORE_ID);

        [$merchant, $ownStore] = $this->makeTenant();
        [$this->merchant, $this->ownStore] = $this->rekey($merchant, $ownStore, self::OWN_STORE_ID);
    }

    /**
     * A store on an id no real `uploads/<id>` folder uses. `stores.id` is changed underneath Eloquent, so the model caches are flushed
     * (`Store` and `User` are `Cachable`; a raw write leaves the pre-write row being served for the rest of the test).
     *
     * @return array{0: User, 1: Store}
     */
    private function rekey(User $owner, Store $store, int $newId): array
    {
        DB::table('stores')->where('id', $store->id)->update(['id' => $newId]);
        (new Store)->flushCache();

        $owner->current_store = $newId;
        $owner->save();

        return [$owner->fresh(), Store::findOrFail($newId)];
    }

    protected function tearDown(): void
    {
        foreach (self::FIXTURE_STORE_IDS as $id) {
            File::deleteDirectory(base_path('uploads/' . $id));
            File::deleteDirectory(base_path('uploads/uploads/' . $id));   // the product gallery's doubled prefix
        }
        if ($this->createdUploadsRoot && is_dir(base_path('uploads')) && count(scandir(base_path('uploads'))) === 2) {
            rmdir(base_path('uploads'));
        }

        parent::tearDown();
    }

    private function file(string $relative, string $body = 'x'): string
    {
        File::ensureDirectoryExists(dirname(base_path($relative)));
        File::put(base_path($relative), $body);

        return $relative;
    }

    /**
     * The P0 test's demotion, THROUGH THE PRODUCT, with its premise asserted. A real merchant's seeded `admin` role holds `Edit User` and
     * `Manage Setting` and `UserController::update` only ADDS a role, so the demoted merchant keeps both; here the grants are made explicit.
     */
    private function selfDemotedMerchant(): User
    {
        $merchant = $this->merchant;
        $this->grantPermission($merchant, 'Edit User');
        $role = Role::forceCreate([
            'name' => 'Manager', 'display_name' => 'Manager', 'guard_name' => 'web',
            'created_by' => $merchant->creatorId(), 'store_id' => $merchant->current_store,
        ]);

        $this->actingAs($merchant)->put("/users/{$merchant->id}", ['name' => $merchant->name, 'email' => $merchant->email, 'role' => $role->id]);

        $fresh = $merchant->fresh();
        $this->assertSame('Manager', $fresh->type, 'the self-demotion no longer happens, so these cases cannot reach the state they exist to test');
        $this->assertSame(1, (int) $fresh->creatorId(), "the principal's tenant root is no longer the platform, so the collapse is not being reproduced");
        $this->assertSame(self::OWN_STORE_ID, (int) $fresh->current_store, 'premise: the demoted merchant is still in their own store');
        $this->grantPermission($fresh, 'Manage Setting');
        auth()->logout();

        return $fresh->fresh();
    }

    private function operatorsStaff(): User
    {
        return User::factory()->create([
            'type' => 'staff', 'created_by' => $this->operator->id, 'current_store' => self::OPERATOR_STORE_ID, 'is_active' => 1, 'email_verified_at' => now(),
        ])->fresh();
    }

    // ---------------------------------------------------------------------------------------------
    // the rule, asked exactly as the handlers ask it: StoredUpload::*( $path, Auth::user()->creatorId() )
    // ---------------------------------------------------------------------------------------------

    public function test_a_self_demoted_merchant_does_not_inherit_the_operators_reach_into_another_tenants_folder(): void
    {
        $demoted = $this->selfDemotedMerchant();
        $logo = $this->file('uploads/' . self::VICTIM_STORE_ID . '/victim-logo.png');
        $gallery = $this->file('uploads/uploads/' . self::VICTIM_STORE_ID . '/victim-gallery.jpg');

        $this->actingAs($demoted);
        $owner = $demoted->creatorId();

        $this->assertNull(StoredUpload::resolve($logo, $owner), "A self-demoted merchant resolved a file in ANOTHER TENANT'S upload folder through the operator's exemption.");
        $this->assertNull(StoredUpload::resolve($gallery, $owner), "A self-demoted merchant resolved another tenant's product gallery image.");
        $this->assertFalse(StoredUpload::delete($logo, $owner));
        Utility::changeStorageLimit($owner, $logo);
        Utility::changeproductStorageLimit($owner, [$gallery]);

        $this->assertFileExists(base_path($logo), "A self-demoted merchant unlinked another tenant's file.");
        $this->assertFileExists(base_path($gallery), "A self-demoted merchant unlinked another tenant's gallery image.");
    }

    /** The second limb gave this away even without the exemption: the operator's stores ARE `created_by = 1`. */
    public function test_nor_into_the_operators_own_store_folder(): void
    {
        $demoted = $this->selfDemotedMerchant();
        $platform = $this->file('uploads/' . self::OPERATOR_STORE_ID . '/platform-logo.png');

        $this->actingAs($demoted);

        $this->assertNull(StoredUpload::resolve($platform, $demoted->creatorId()), "A self-demoted merchant resolved a file in the OPERATOR'S own store folder.");
        Utility::changeStorageLimit($demoted->creatorId(), $platform);
        $this->assertFileExists(base_path($platform), "A self-demoted merchant unlinked a file of the operator's own store.");
    }

    /** THE COST NOT IMPOSED: they still own their store, and its old files are still theirs to replace. */
    public function test_a_self_demoted_merchant_still_reaches_the_folder_of_a_store_they_own(): void
    {
        $demoted = $this->selfDemotedMerchant();
        $own = $this->file('uploads/' . self::OWN_STORE_ID . '/own-old-logo.png');

        $this->actingAs($demoted);

        $this->assertSame(realpath(base_path($own)), StoredUpload::resolve($own, $demoted->creatorId()), "A self-demoted merchant lost the folder of the store they own.");
        $this->assertTrue(StoredUpload::delete($own, $demoted->creatorId()));
        $this->assertFileDoesNotExist(base_path($own));
    }

    /**
     * Staff the operator created present the operator's id too. They are refused the same way -- and, owning no store, they are refused the
     * operator's own store folder as well. THAT IS THE STATED COST of not being able to tell them from a demoted merchant.
     */
    public function test_staff_the_operator_created_do_not_inherit_the_exemption_either(): void
    {
        $staff = $this->operatorsStaff();
        $this->assertSame(1, (int) $staff->creatorId(), 'premise: staff the operator created are rooted at the operator');
        $victim = $this->file('uploads/' . self::VICTIM_STORE_ID . '/victim-logo.png');
        $platform = $this->file('uploads/' . self::OPERATOR_STORE_ID . '/platform-logo.png');

        $this->actingAs($staff);

        $this->assertNull(StoredUpload::resolve($victim, $staff->creatorId()), "Staff the operator created resolved a file in another tenant's folder through the operator's exemption.");
        $this->assertNull(StoredUpload::resolve($platform, $staff->creatorId()), 'The stated cost: staff the operator created no longer resolve a file in the operator\'s own store folder.');
        $this->assertFileExists(base_path($victim));
    }

    /**
     * THE RULE IS ABOUT AN OPERATOR ROW, NOT ABOUT USER 1. A second operator account has another id, and staff it created present THAT id;
     * a check that looked only for the literal 1 would go on handing them the exemption.
     */
    public function test_the_rule_is_about_an_operator_row_and_not_about_user_1(): void
    {
        $secondOperator = $this->makePlatformAdmin();
        $this->assertNotSame(1, (int) $secondOperator->id, 'premise: a second operator account, with an id other than 1');
        $staff = User::factory()->create([
            'type' => 'staff', 'created_by' => $secondOperator->id, 'current_store' => self::OPERATOR_STORE_ID, 'is_active' => 1, 'email_verified_at' => now(),
        ])->fresh();
        $this->assertSame((int) $secondOperator->id, (int) $staff->creatorId(), 'premise: they present the second operator\'s id');
        $victim = $this->file('uploads/' . self::VICTIM_STORE_ID . '/victim-logo.png');

        $this->actingAs($staff);

        $this->assertNull(StoredUpload::resolve($victim, $staff->creatorId()), "Staff of a second operator account resolved another tenant's folder through that operator's exemption.");
        $this->assertFileExists(base_path($victim));
    }

    /**
     * THE RULE, NOT "EVERY PRINCIPAL WHOSE ROOT IS NOT A MERCHANT": staff of a self-demoted merchant present their EMPLOYER'S id, never the
     * operator's, so the exemption was never theirs and the ordinary ownership limb already bounds them to their employer's stores. A
     * uniform "the root must be a merchant" refusal here would cost them their own store's housekeeping and close nothing.
     */
    public function test_staff_of_a_self_demoted_merchant_are_not_touched_by_this_rule(): void
    {
        $demoted = $this->selfDemotedMerchant();
        $staff = $this->makeStaff($demoted)->fresh();
        $this->assertSame((int) $demoted->id, (int) $staff->creatorId(), 'premise: they present their employer, not the operator');
        $employers = $this->file('uploads/' . self::OWN_STORE_ID . '/employers-old-logo.png');
        $victim = $this->file('uploads/' . self::VICTIM_STORE_ID . '/victim-logo.png');

        $this->actingAs($staff);

        $this->assertSame(realpath(base_path($employers)), StoredUpload::resolve($employers, $staff->creatorId()), "Staff of a self-demoted merchant lost their employer's store folder.");
        $this->assertNull(StoredUpload::resolve($victim, $staff->creatorId()), 'Premise: another tenant\'s folder was never theirs.');
    }

    public function test_the_operator_keeps_the_exemption_when_the_operator_is_the_one_acting(): void
    {
        $victim = $this->file('uploads/' . self::VICTIM_STORE_ID . '/victim-logo.png');
        $gallery = $this->file('uploads/uploads/' . self::VICTIM_STORE_ID . '/victim-gallery.jpg');

        // nobody signed in: the console, a queue job
        $this->assertSame(realpath(base_path($victim)), StoredUpload::resolve($victim, $this->operator->id), 'Premise: with no panel user acting, the operator as owner still resolves any store folder.');

        $this->actingAs($this->operator);
        $this->assertSame(realpath(base_path($victim)), StoredUpload::resolve($victim, $this->operator->creatorId()), "The operator lost their reach into a merchant's folder.");
        $this->assertSame(realpath(base_path($gallery)), StoredUpload::resolve($gallery, $this->operator->creatorId()));
    }

    public function test_an_ordinary_merchant_and_their_staff_are_unchanged(): void
    {
        $theirs = $this->file('uploads/' . self::VICTIM_STORE_ID . '/their-own.png');
        $foreign = $this->file('uploads/' . self::OWN_STORE_ID . '/someone-elses.png');
        $platform = $this->file('uploads/' . self::OPERATOR_STORE_ID . '/platform-logo.png');
        $staff = $this->makeStaff($this->victim)->fresh();

        foreach ([$this->victim, $staff] as $principal) {
            $this->actingAs($principal);
            $this->assertSame(realpath(base_path($theirs)), StoredUpload::resolve($theirs, $principal->creatorId()), 'Premise: a merchant and their staff still resolve their own store folder.');
            $this->assertNull(StoredUpload::resolve($foreign, $principal->creatorId()), 'Premise: another tenant\'s folder does not resolve for them.');
            $this->assertNull(StoredUpload::resolve($platform, $principal->creatorId()), 'Premise: nor does the operator\'s.');
        }
    }

    /**
     * UNCHANGED, AND RECORDED RATHER THAN ENDORSED: a storefront customer is not a panel user. `Api\ApiController` replaces a customer's
     * profile image with `StoredUpload::delete($customer->profile_image, $store->created_by)`; in the operator's own store that owner is the
     * operator and the exemption applies, exactly as before this change. This case pins that the new rule reads the WEB guard and a `User`,
     * so a `Customer` on the default guard is neither refused by it nor handed to a method that takes a `User`.
     */
    public function test_a_storefront_customer_is_not_a_panel_actor_and_nothing_changes_for_one(): void
    {
        $customer = Customer::forceCreate([
            'first_name' => 'Buyer', 'last_name' => 'Fixture', 'email' => 'buyer-' . uniqid() . '@example.test', 'password' => bcrypt('secret'),
            'store_id' => self::OPERATOR_STORE_ID, 'type' => 'customer', 'created_by' => $this->operator->id,
        ]);
        $victim = $this->file('uploads/' . self::VICTIM_STORE_ID . '/victim-logo.png');

        $this->actingAs($customer, 'customers');

        $this->assertNull(auth('web')->user(), 'premise: no panel user is acting');
        $this->assertSame(realpath(base_path($victim)), StoredUpload::resolve($victim, $this->operatorStore->created_by), 'A storefront customer changed what the operator-as-owner resolves; this rule is about panel users.');
    }

    // ---------------------------------------------------------------------------------------------
    // the real chain: store a path through one settings writer, then replace the image it names
    // ---------------------------------------------------------------------------------------------

    /** `Utility::upload_file()` reads the platform's storage settings from `created_by = 1`; without them no upload happens and no delete is reached. */
    private function platformStorageRows(): void
    {
        foreach (['storage_setting' => 'local', 'local_storage_validation' => 'jpg,jpeg,png', 'local_storage_max_upload_size' => '2048000'] as $name => $value) {
            DB::table('settings')->insert(['name' => $name, 'value' => $value, 'created_by' => $this->operator->id, 'store_id' => self::OPERATOR_STORE_ID, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** Stores `$path` as this principal's `theme_logo` through the Store Setting screen's writer, and proves it is stored. */
    private function storeAsThemeLogo(User $principal, string $path): void
    {
        $this->actingAs($principal)->post('/shipping-label-setting', [
            'theme_logo' => $path, 'store_address' => 'a', 'store_city' => 'c', 'store_state' => 's', 'store_zipcode' => '1', 'store_country' => 'x',
        ]);

        $this->assertSame($path, DB::table('settings')->where('name', 'theme_logo')->where('store_id', $principal->current_store)->value('value'),
            'Premise: the writer stored the path as this store\'s theme_logo, so the replacement below reads it back as the OLD image.');
    }

    private function replaceThemeLogo(User $principal): void
    {
        $this->actingAs($principal->fresh())->post('/theme-settings', ['theme_logo' => UploadedFile::fake()->image('new-logo.png')]);

        $this->assertNotEmpty(Storage::disk('theme')->allFiles(),
            'Premise: /theme-settings stored the new image. The old-image delete is the statement after that upload, so a request that never uploaded proves nothing.');
    }

    public static function foreignFolders(): array
    {
        return [
            "another merchant's store folder" => [self::VICTIM_STORE_ID],
            "the operator's own store folder" => [self::OPERATOR_STORE_ID],
        ];
    }

    /**
     * @dataProvider foreignFolders
     */
    public function test_storing_a_path_and_then_replacing_the_logo_never_unlinks_a_file_outside_the_callers_own_store(int $folder): void
    {
        Storage::fake('theme');
        $this->platformStorageRows();
        $demoted = $this->selfDemotedMerchant();
        $victim = $this->file('uploads/' . $folder . '/storefront-logo.png');

        $this->storeAsThemeLogo($demoted, $victim);
        $this->replaceThemeLogo($demoted);

        $this->assertFileExists(base_path($victim), "A self-demoted merchant unlinked a file in uploads/{$folder}/ with two ordinary requests.");
    }

    /** The same two requests, same principal, their OWN old file: the handler does reach the delete, and the rule still lets it happen. */
    public function test_the_same_two_requests_still_remove_the_callers_own_previous_logo(): void
    {
        Storage::fake('theme');
        $this->platformStorageRows();
        $demoted = $this->selfDemotedMerchant();
        $own = $this->file('uploads/' . self::OWN_STORE_ID . '/own-old-logo.png');

        $this->storeAsThemeLogo($demoted, $own);
        $this->replaceThemeLogo($demoted);

        $this->assertFileDoesNotExist(base_path($own), "Premise: /theme-settings reaches the old-image delete for this principal, and their own store's previous logo is still removed.");
    }
}
