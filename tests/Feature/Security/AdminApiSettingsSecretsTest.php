<?php

namespace Tests\Feature\Security;

use App\Mail\TestMail;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * P1-SEC-18 F5 + F6 -- the admin API handed a merchant's SMTP and Stripe secrets to the merchant's STAFF, and its payment-setting
 * uploads deleted another tenant's image file.
 * =============================================================================================
 *
 * F5. `GetEmailSetting` returns MAIL_PASSWORD, `GetStripePayment` returns stripe_secret, `EmailSetting` / `StripePayment`
 * overwrite them and `TestMail` sends through them. The routes carry `custom.auth` only, and `resolveOwnedStore()` admits
 * anyone whose `creatorId()` owns the store -- which for a STAFF member is their employer. There is no `isAbleTo()` call in the
 * whole controller, so a staff member whose role the merchant never meant to include Settings could read and overwrite the
 * gateway and SMTP credentials. (A different merchant was always refused; that is not what this is about.)
 *
 * THE FIX is the web UI's own gate: `Manage Setting`, the permission behind the "Settings" menu entry (CompanyMenuListener
 * :954-965, route setting.index -- where the email and payment tabs live; `Manage Store Setting` belongs to the different
 * "Store Setting" entry, route app-setting.index). The decision is App\Services\StoreSettingsAccess: the platform operator
 * first (`isAbleTo()` alone refuses the operator -- there is no super-admin bypass in this codebase), then the tenant OWNER
 * (`creatorId() === id`, so a merchant whose role rows were never seeded is not locked out of their own settings), then
 * `isAbleTo()`. A refusal is HTTP 403, never 401 -- the shipped Flutter app forces a logout on any 401.
 *
 * F6. `CodPayment`, `BankPayment` and `StripePayment` ran `Setting::where(['name' => 'cod_image'])->first()` with no store
 * predicate and then `File::delete(base_path($Setting->value))`. `Setting` is not in config/tenancy.php's `scoped_models`, so
 * that is the FIRST tenant's row -- the lowest id on the platform -- and a merchant updating their own COD image deleted
 * somebody else's file.
 *
 * SCOPING THE LOOKUP ALONE WOULD HAVE MADE IT WORSE (found by the review of the first version). The row is then the CALLER's own,
 * and the web's generic writers (`POST /customize-settings`, `/system-settings`, ... take every posted name/value into `settings`
 * for the caller's store, no allow-list) let any tenant set `cod_image=.env`; the delete would then aim at the application's own
 * files. So the delete also goes through App\Services\StoredUpload::delete(), which unlinks only a file that resolves INSIDE
 * `base_path('uploads')` -- the precedent is ProductController::isInsideUploadsRoot().
 *
 * C. (found by the same review, outside the ticket) `updateStoreOwnerInfo` admits a STAFF member through resolveOwnedStore() and
 * then writes `owner_name`, `owner_email` and `owner_mobile` onto the OWNER's user row -- the owner's login email, i.e. the
 * address a password reset goes to. Staff to owner takeover in two requests.
 *
 * SQLITE NOTE. The write handlers persist with `insert ... ON DUPLICATE KEY UPDATE`, which is MySQL syntax; on this suite's
 * SQLite the persistence step throws AFTER the permission gate and AFTER the image deletion. So: a write refused by the gate is
 * an exact 403 with the row untouched; a write that is allowed is asserted by "not 403" plus, for F6, by the file the handler
 * reached and deleted. The persistence step itself is not exercised here.
 */
class AdminApiSettingsSecretsTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const PERMISSION = 'Manage Setting';
    private const SMTP_SECRET = 'FIXTURE-SMTP-PASSWORD-18a';
    private const STRIPE_SECRET = 'FIXTURE-STRIPE-SECRET-18b';

    private User $merchant;
    private Store $store;
    private User $staff;
    private User $foreignMerchant;
    private Store $foreignStore;
    private User $operator;
    private bool $createdUploadsRoot = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createdUploadsRoot = ! is_dir(base_path('uploads'));

        [$this->merchant, $this->store] = $this->makeTenant();
        [$this->foreignMerchant, $this->foreignStore] = $this->makeTenant();
        $this->staff = $this->makeStaff($this->merchant);
        $this->operator = $this->makePlatformAdmin();

        $this->setting($this->store, [
            'MAIL_DRIVER' => 'smtp', 'MAIL_HOST' => 'smtp.example.test', 'MAIL_PORT' => '587', 'MAIL_USERNAME' => 'mailer',
            'MAIL_PASSWORD' => self::SMTP_SECRET, 'MAIL_ENCRYPTION' => 'tls', 'MAIL_FROM_NAME' => 'Shop',
            'MAIL_FROM_ADDRESS' => 'shop@example.test', 'stripe_secret' => self::STRIPE_SECRET, 'publishable_key' => 'pk_fixture',
            'CURRENCY' => '$', 'CURRENCY_NAME' => 'USD', 'is_stripe_enabled' => 'on',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('storage/framework/testing/sec18'));
        File::deleteDirectory(base_path('uploads/zz-sec18'));
        if ($this->createdUploadsRoot && is_dir(base_path('uploads')) && count(scandir(base_path('uploads'))) === 2) {
            rmdir(base_path('uploads'));
        }

        parent::tearDown();
    }

    private function setting(Store $store, array $rows): void
    {
        foreach ($rows as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => $value, 'created_by' => $store->created_by, 'store_id' => $store->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $user->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }

    private function read(User $as, string $uri, ?Store $store = null)
    {
        return $this->getJson($uri . '?store_id=' . ($store ?? $this->store)->id, $this->headers($as));
    }

    private function write(User $as, string $uri, array $data)
    {
        return $this->postJson($uri, $data + ['store_id' => $this->store->id], $this->headers($as));
    }

    private function emailPayload(): array
    {
        return [
            'mail_driver' => 'smtp', 'mail_host' => 'evil.example.test', 'mail_port' => '25', 'mail_username' => 'attacker',
            'mail_password' => 'ATTACKER-CHOSEN', 'mail_encryption' => 'tls', 'mail_from_address' => 'x@evil.example.test',
            'mail_from_name' => 'X',
        ];
    }

    private function stripePayload(): array
    {
        return ['CURRENCY' => '$', 'CURRENCY_NAME' => 'USD', 'is_stripe_enabled' => 'on', 'stripe_secret' => 'ATTACKER-CHOSEN', 'publishable_key' => 'pk_x'];
    }

    private function storedValue(string $name): ?string
    {
        return DB::table('settings')->where('store_id', $this->store->id)->where('name', $name)->value('value');
    }

    // ---------------------------------------------------------------------------------------------
    // F5 -- premise: who CAN read, so that "refused" below cannot pass because the route never answered
    // ---------------------------------------------------------------------------------------------

    public function test_premise_a_permitted_principal_reads_the_secrets_through_the_real_routes(): void
    {
        $this->grantPermission($this->merchant, self::PERMISSION);

        $this->read($this->merchant, '/api/admin/getemailsetting')->assertOk()->assertJsonPath('data.MAIL_PASSWORD', self::SMTP_SECRET);
        $this->read($this->merchant, '/api/admin/get-stripe-payment')->assertOk()->assertJsonPath('data.stripe_secret', self::STRIPE_SECRET);
    }

    public function test_the_platform_operator_is_admitted_without_holding_the_permission(): void
    {
        // isAbleTo() alone would refuse the operator: there is no super-admin bypass.
        $this->assertFalse($this->operator->isAbleTo(self::PERMISSION), 'Premise: the operator holds no role here.');

        $this->read($this->operator, '/api/admin/getemailsetting')->assertOk()->assertJsonPath('data.MAIL_PASSWORD', self::SMTP_SECRET);
        $this->read($this->operator, '/api/admin/get-stripe-payment')->assertOk()->assertJsonPath('data.stripe_secret', self::STRIPE_SECRET);
    }

    /**
     * The operator test everywhere else in the codebase is `strtolower(trim($type)) === 'super admin'` (PlatformOperatorOnly,
     * RolePolicy, resolveOwnedStore). creatorId() compares exactly, so a non-canonical spelling passes ONLY that first limb; this
     * pins that the gate agrees with the rest of the codebase about who the operator is (such a type cannot be set by a tenant:
     * the reserved-name rules normalise the same way).
     */
    public function test_the_operator_is_recognised_the_way_every_other_operator_check_recognises_them(): void
    {
        $variant = $this->makePlatformAdmin(['type' => ' Super Admin ']);

        $this->read($variant, '/api/admin/getemailsetting')->assertOk()->assertJsonPath('data.MAIL_PASSWORD', self::SMTP_SECRET);
    }

    public function test_the_tenant_owner_is_admitted_even_with_no_role_rows(): void
    {
        // A merchant whose `admin` role was never seeded with the permission must not be locked out of their own settings
        // in the shipped app: the gate is about STAFF.
        $this->assertFalse($this->merchant->isAbleTo(self::PERMISSION), 'Premise: this merchant holds no role.');

        $this->read($this->merchant, '/api/admin/getemailsetting')->assertOk()->assertJsonPath('data.MAIL_PASSWORD', self::SMTP_SECRET);
        $this->read($this->merchant, '/api/admin/get-stripe-payment')->assertOk()->assertJsonPath('data.stripe_secret', self::STRIPE_SECRET);
        $this->assertNotContains($this->write($this->merchant, '/api/admin/emailsetting', $this->emailPayload())->getStatusCode(), [401, 403]);
    }

    public function test_staff_whom_the_merchant_has_given_the_permission_still_read_and_pass_the_write_gate(): void
    {
        $this->grantPermission($this->staff, self::PERMISSION);

        $this->read($this->staff, '/api/admin/getemailsetting')->assertOk()->assertJsonPath('data.MAIL_PASSWORD', self::SMTP_SECRET);
        $this->read($this->staff, '/api/admin/get-stripe-payment')->assertOk()->assertJsonPath('data.stripe_secret', self::STRIPE_SECRET);

        foreach ([['emailsetting', $this->emailPayload()], ['stripe-payment', $this->stripePayload()]] as [$uri, $payload]) {
            $this->assertNotContains($this->write($this->staff, "/api/admin/$uri", $payload)->getStatusCode(), [401, 403], "A permitted staff member was refused on $uri.");
        }
    }

    // ---------------------------------------------------------------------------------------------
    // F5 -- the defect
    // ---------------------------------------------------------------------------------------------

    public function test_staff_without_the_permission_cannot_read_the_smtp_password(): void
    {
        $response = $this->read($this->staff, '/api/admin/getemailsetting');

        $response->assertStatus(403);
        $this->assertStringNotContainsString(self::SMTP_SECRET, $response->getContent());
    }

    public function test_staff_without_the_permission_cannot_read_the_stripe_secret(): void
    {
        $response = $this->read($this->staff, '/api/admin/get-stripe-payment');

        $response->assertStatus(403);
        $this->assertStringNotContainsString(self::STRIPE_SECRET, $response->getContent());
    }

    public function test_staff_without_the_permission_cannot_overwrite_the_smtp_credentials(): void
    {
        $this->write($this->staff, '/api/admin/emailsetting', $this->emailPayload())->assertStatus(403);

        $this->assertSame(self::SMTP_SECRET, $this->storedValue('MAIL_PASSWORD'));
        $this->assertSame('smtp.example.test', $this->storedValue('MAIL_HOST'));
    }

    public function test_staff_without_the_permission_cannot_overwrite_the_stripe_credentials(): void
    {
        $this->write($this->staff, '/api/admin/stripe-payment', $this->stripePayload())->assertStatus(403);

        $this->assertSame(self::STRIPE_SECRET, $this->storedValue('stripe_secret'));
    }

    public function test_staff_without_the_permission_cannot_send_mail_through_the_merchants_smtp(): void
    {
        Mail::fake();

        $this->write($this->staff, '/api/admin/testmail', ['email' => 'anyone@example.test'])->assertStatus(403);

        Mail::assertNothingSent();
    }

    public function test_the_permitted_owner_can_still_send_the_test_mail(): void
    {
        Mail::fake();
        $this->grantPermission($this->merchant, self::PERMISSION);

        $this->write($this->merchant, '/api/admin/testmail', ['email' => 'anyone@example.test'])->assertOk()->assertJsonPath('status', 1);

        Mail::assertSent(TestMail::class);
    }

    /** The refusal must not log a merchant out of the shipped app: 403, never 401. */
    public function test_the_refusal_is_a_403_and_never_a_401(): void
    {
        foreach (['/api/admin/getemailsetting', '/api/admin/get-stripe-payment'] as $uri) {
            $this->assertSame(403, $this->read($this->staff, $uri)->getStatusCode());
        }
    }

    /** Cross-tenant was always refused; the new gate must not change what a foreign merchant is told. */
    public function test_a_foreign_merchant_is_still_refused_for_the_ownership_reason(): void
    {
        $this->grantPermission($this->foreignMerchant, self::PERMISSION);

        $response = $this->read($this->foreignMerchant, '/api/admin/getemailsetting');

        $this->assertStringNotContainsString(self::SMTP_SECRET, $response->getContent());
        $this->assertNotSame(401, $response->getStatusCode());
    }

    // ---------------------------------------------------------------------------------------------
    // F6 -- another tenant's image file
    // ---------------------------------------------------------------------------------------------

    /** @return array{0: string, 1: string} the victim's and the caller's relative file paths */
    private function twoImageFiles(string $settingName): array
    {
        $victim = 'uploads/zz-sec18/victim-' . $settingName . '.png';
        $own = 'uploads/zz-sec18/own-' . $settingName . '.png';
        File::ensureDirectoryExists(base_path('uploads/zz-sec18'));
        File::put(base_path($victim), 'victim');
        File::put(base_path($own), 'own');

        // The victim's row has the LOWEST id on the platform: that is the row an unscoped ->first() returns.
        $this->setting($this->foreignStore, [$settingName => $victim]);
        $this->setting($this->store, [$settingName => $own]);

        return [$victim, $own];
    }

    public static function imageHandlers(): array
    {
        return [
            'cod' => ['cod-payment', 'cod_image', 'is_cod_enabled', 'cod_image'],
            'bank' => ['bank-payment', 'bank_transfer_image', 'is_bank_transfer_enabled', 'bank_transfer_image'],
            'stripe' => ['stripe-payment', 'stripe_image', 'is_stripe_enabled', 'stripe_image'],
        ];
    }

    /**
     * @dataProvider imageHandlers
     */
    public function test_replacing_my_payment_image_never_deletes_another_tenants_file(string $uri, string $settingName, string $enabledField, string $fileField): void
    {
        Storage::fake('theme');
        $this->grantPermission($this->merchant, self::PERMISSION);
        [$victim, $own] = $this->twoImageFiles($settingName);

        $this->post('/api/admin/' . $uri, [
            'store_id' => $this->store->id, 'CURRENCY' => '$', 'CURRENCY_NAME' => 'USD',
            $enabledField => 'on', $fileField => UploadedFile::fake()->image('new.png'),
        ], $this->headers($this->merchant));

        // Premise: the handler reached its delete step -- it deleted SOMETHING. (Before the fix it deleted the wrong tenant's.)
        $this->assertTrue(! file_exists(base_path($victim)) || ! file_exists(base_path($own)),
            "Premise: {$uri} never reached the old-image delete, so this test proves nothing.");
        // The other tenant's file is untouched...
        $this->assertFileExists(base_path($victim), "{$uri} deleted another tenant's image.");
        // ...and the caller's own previous image is what was replaced.
        $this->assertFileDoesNotExist(base_path($own), "{$uri} left the caller's own previous image behind.");
    }

    public function test_no_image_lookup_in_the_controller_is_unscoped(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/Api/DashboardController.php'));

        $this->assertSame(0, substr_count($source, 'Setting::where($where)->first()'),
            'An image lookup is not scoped to a store: ->first() is the lowest-id row on the platform, another tenant\'s.');
        $this->assertSame(3, substr_count($source, "Setting::where(\$where)->where('store_id', \$store->id)->first()"));
    }

    // ---------------------------------------------------------------------------------------------
    // F5 -- what the review of the first version found
    // ---------------------------------------------------------------------------------------------

    /** The "Store Setting" menu entry's permission is a DIFFERENT one; holding it must not open the Settings secrets. */
    public function test_the_permission_of_the_store_setting_screen_does_not_open_the_smtp_password(): void
    {
        $this->grantPermission($this->staff, 'Manage Store Setting');

        $response = $this->read($this->staff, '/api/admin/getemailsetting');

        $response->assertStatus(403);
        $this->assertStringNotContainsString(self::SMTP_SECRET, $response->getContent());
    }

    /**
     * Ownership uses creatorId()'s EXACT comparison, so a staff row whose `type` merely normalises to 'admin' ('Admin',
     * ' admin') is a staff member for resolveOwnedStore() -- and must not be an "owner" for this gate either.
     *
     * @dataProvider lookalikeTypes
     */
    public function test_a_staff_row_whose_type_only_looks_like_admin_is_not_an_owner(string $type): void
    {
        $lookalike = $this->makeStaff($this->merchant, ['type' => $type]);

        $this->read($lookalike, '/api/admin/getemailsetting')->assertStatus(403);
    }

    public static function lookalikeTypes(): array
    {
        return ['capitalised' => ['Admin'], 'padded' => [' admin'], 'upper' => ['ADMIN']];
    }

    /** custom.auth admits a shopper's or a driver's token; they log into other guards, so Auth::user() is null here. */
    public function test_a_customer_token_and_a_driver_token_get_neither_the_secret_nor_a_401(): void
    {
        $customer = \App\Models\Customer::forceCreate([
            'first_name' => 'Tok', 'last_name' => 'Cust', 'email' => 'tok-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999), 'password' => bcrypt('secret123'),
            'store_id' => $this->store->id, 'status' => 1, 'register_type' => 'email',
        ]);
        $driver = \App\Models\DeliveryBoy::forceCreate([
            'name' => 'Driver ' . uniqid(), 'email' => 'driver' . uniqid() . '@example.test', 'password' => bcrypt('x'),
            'contact' => '0000000', 'created_by' => $this->store->created_by, 'store_id' => $this->store->id,
        ]);

        foreach ([$customer, $driver] as $principal) {
            $headers = ['Authorization' => 'Bearer ' . $principal->createToken('t')->plainTextToken, 'Accept' => 'application/json'];

            foreach (['/api/admin/getemailsetting', '/api/admin/get-stripe-payment'] as $uri) {
                $response = $this->getJson($uri . '?store_id=' . $this->store->id, $headers);

                $this->assertStringNotContainsString(self::SMTP_SECRET, $response->getContent());
                $this->assertStringNotContainsString(self::STRIPE_SECRET, $response->getContent());
                $this->assertNotSame(401, $response->getStatusCode(), 'A token-class refusal must not log the app out.');
            }
        }
    }

    // ---------------------------------------------------------------------------------------------
    // C -- staff rewriting the OWNER's identity
    // ---------------------------------------------------------------------------------------------

    private function ownerInfoPayload(): array
    {
        return ['owner_name' => 'Taken Over', 'owner_email' => 'attacker@evil.example.test', 'owner_mobile' => '0999999999'];
    }

    public function test_staff_cannot_rewrite_the_owners_login_email_name_or_mobile(): void
    {
        $before = $this->merchant->only(['name', 'email', 'mobile']);

        $response = $this->postJson('/api/admin/updateStoreOwnerInfo?store_id=' . $this->store->id, $this->ownerInfoPayload(), $this->headers($this->staff));

        $response->assertStatus(403);
        $this->assertSame($before, User::find($this->merchant->id)->only(['name', 'email', 'mobile']), 'A staff member changed the owner\'s identity.');
    }

    public static function ownerFields(): array
    {
        return ['name' => ['owner_name', 'Taken Over'], 'email' => ['owner_email', 'attacker@evil.example.test'], 'mobile' => ['owner_mobile', '0999999999']];
    }

    /**
     * @dataProvider ownerFields
     */
    public function test_staff_cannot_change_any_single_owner_field(string $field, string $value): void
    {
        $column = ['owner_name' => 'name', 'owner_email' => 'email', 'owner_mobile' => 'mobile'][$field];
        $before = User::find($this->merchant->id)->{$column};

        $this->postJson('/api/admin/updateStoreOwnerInfo?store_id=' . $this->store->id, [$field => $value], $this->headers($this->staff))->assertStatus(403);

        $this->assertSame($before, User::find($this->merchant->id)->{$column});
    }

    /** A prefilled form resubmits the owner's CURRENT values next to a changed store name: that is not a change to the owner. */
    public function test_staff_may_resubmit_the_owners_current_values_with_a_store_level_change(): void
    {
        $owner = User::find($this->merchant->id);

        $response = $this->postJson('/api/admin/updateStoreOwnerInfo?store_id=' . $this->store->id, [
            'owner_name' => $owner->name, 'owner_email' => $owner->email, 'owner_mobile' => (string) $owner->mobile, 'store_name' => 'Prefilled Form Save',
        ], $this->headers($this->staff));

        $this->assertNotContains($response->getStatusCode(), [401, 403], $response->getContent());
        $this->assertSame('Prefilled Form Save', Store::find($this->store->id)->name);
    }

    public function test_the_owner_can_still_update_their_own_info(): void
    {
        $response = $this->postJson('/api/admin/updateStoreOwnerInfo?store_id=' . $this->store->id,
            ['owner_name' => 'Renamed Owner', 'store_name' => 'Renamed Store'], $this->headers($this->merchant));

        $this->assertNotContains($response->getStatusCode(), [401, 403], $response->getContent());
        $this->assertSame('Renamed Owner', User::find($this->merchant->id)->name, 'Premise: the owner\'s own update must still work. ' . substr($response->getContent(), 0, 200));
    }

    public function test_staff_can_still_update_the_store_fields_that_are_not_the_owners_identity(): void
    {
        $response = $this->postJson('/api/admin/updateStoreOwnerInfo?store_id=' . $this->store->id,
            ['store_name' => 'Renamed By Staff'], $this->headers($this->staff));

        $this->assertNotContains($response->getStatusCode(), [401, 403], $response->getContent());
        $this->assertSame('Renamed By Staff', Store::find($this->store->id)->name, 'Premise: staff keep the store-level fields. ' . substr($response->getContent(), 0, 200));
    }

    public function test_the_operator_can_update_an_owners_info(): void
    {
        $this->postJson('/api/admin/updateStoreOwnerInfo?store_id=' . $this->store->id, ['owner_name' => 'By Operator'], $this->headers($this->operator));

        $this->assertSame('By Operator', User::find($this->merchant->id)->name);
    }

    /**
     * `$uploadPath = public_path('uploads/' . $storeId)` used the RAW request string. On MySQL `Store::where('id', '5/../../x')`
     * matches store 5 (the string is coerced to its numeric prefix), so the destination could be walked out of public/uploads;
     * SQLite compares the string as text and never matches, so this suite cannot show the coercion -- tests/Support/Security/bin/
     * verify-store-id-coercion-on-mysql.php does. Here: the legitimate upload lands in public/uploads/<store id>/, the row
     * stores the matching relative path, and the handler no longer builds a path from `$storeId` before replacing it.
     */
    public function test_the_store_image_upload_lands_in_the_stores_own_folder(): void
    {
        $dir = public_path('uploads/' . $this->store->id);
        $existed = is_dir($dir);
        $before = $existed ? scandir($dir) : [];

        try {
            $response = $this->post('/api/admin/updateStoreOwnerInfo?store_id=' . $this->store->id, [
                'invoice_logo' => UploadedFile::fake()->image('logo.png'),
            ], $this->headers($this->merchant));

            $this->assertNotContains($response->getStatusCode(), [401, 403, 422], $response->getContent());
            $new = array_values(array_diff(is_dir($dir) ? scandir($dir) : [], $before));
            $this->assertCount(1, array_values(array_filter($new, fn ($f) => ! in_array($f, ['.', '..']))), 'Premise: one file was written.');
            $this->assertStringStartsWith('uploads/' . $this->store->id . '/', DB::table('settings')->where('store_id', $this->store->id)->where('name', 'invoice_logo')->value('value'));
        } finally {
            foreach (array_diff(is_dir($dir) ? scandir($dir) : [], $before, ['.', '..']) as $f) {
                File::delete($dir . '/' . $f);
            }
            if (! $existed && is_dir($dir)) {
                @rmdir($dir);
            }
        }
    }

    public function test_the_owner_info_handler_builds_no_path_from_the_raw_store_id(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/Api/DashboardController.php'));
        $handler = substr($source, strpos($source, 'public function updateStoreOwnerInfo'));
        $handler = substr($handler, 0, strpos($handler, 'public function ', 20) ?: null);

        $assign = strpos($handler, '$storeId = $store->id;');
        $path = strpos($handler, "public_path('uploads/'");
        $this->assertNotFalse($assign, 'The handler must replace the request string with the validated store\'s id.');
        $this->assertNotFalse($path);
        $this->assertLessThan($path, $assign, 'public_path(...) is built before $storeId is replaced by the validated id.');
    }

    // ---------------------------------------------------------------------------------------------
    // F6 -- the stored path is untrusted input to the filesystem
    // ---------------------------------------------------------------------------------------------

    public static function poisonedPaths(): array
    {
        $cases = [];
        foreach (self::imageHandlers() as $handler => [$uri, $settingName, $enabledField, $fileField]) {
            foreach ([
                'a file outside uploads' => 'storage/framework/testing/sec18/outside.txt',
                'a traversal out of uploads' => 'uploads/../storage/framework/testing/sec18/outside.txt',
            ] as $label => $path) {
                $cases["$handler: $label"] = [$uri, $settingName, $enabledField, $fileField, $path];
            }
        }

        return $cases;
    }

    /**
     * Any tenant can write ANY setting name for their own store through the web's generic writers (`/customize-settings`,
     * `/system-settings`, ...), so `cod_image` can be set to a path of the caller's choosing. Scoping the lookup to the caller's
     * store makes that row the one the delete follows -- so the delete must refuse a path outside the uploads directory.
     *
     * @dataProvider poisonedPaths
     */
    public function test_a_poisoned_stored_path_is_never_unlinked(string $uri, string $settingName, string $enabledField, string $fileField, string $poisoned): void
    {
        Storage::fake('theme');
        $this->grantPermission($this->merchant, self::PERMISSION);
        File::ensureDirectoryExists(base_path('storage/framework/testing/sec18'));
        File::put(base_path('storage/framework/testing/sec18/outside.txt'), 'application file');
        $this->setting($this->store, [$settingName => $poisoned]);

        $this->post('/api/admin/' . $uri, [
            'store_id' => $this->store->id, 'CURRENCY' => '$', 'CURRENCY_NAME' => 'USD',
            $enabledField => 'on', $fileField => UploadedFile::fake()->image('new.png'),
        ], $this->headers($this->merchant));

        $this->assertFileExists(base_path('storage/framework/testing/sec18/outside.txt'), "{$uri} unlinked a stored {$settingName} path outside uploads/.");
    }
}
