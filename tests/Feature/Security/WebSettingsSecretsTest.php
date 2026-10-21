<?php

namespace Tests\Feature\Security;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * P1-SEC-18 F5 + F6, the WEB doors.
 * =============================================================================================
 *
 * AdminApiSettingsSecretsTest closes the admin API. The same secrets are also served and written by the web Settings screens,
 * and neither SettingController nor PaymentSettingController checked any permission -- the only gate was the MENU: the Settings
 * entry is hidden unless the user holds `Manage Setting`, but the routes behind it (group: auth, xss, setlocate, verified)
 * answered anyone logged in. Found by the review of the API fix, which read `POST /setting-form` render MAIL_PASSWORD and every
 * gateway key for a staff session.
 *
 * Gated here, with the same decision (App\Services\StoreSettingsAccess): settingForm (renders every tab), getEmailSettingFields,
 * saveEmailSettings, PaymentSettingController::update -- the direct readers and writers of the SMTP and gateway rows.
 *
 * NOT closed here, and recorded as P1-SEC-27: the web's GENERIC writers (`/customize-settings`, `/cookie-setting`,
 * `/system-settings`, `/currency-settings`, `/meta-seo-settings`, `/seo-setting`, `/shipping-label-setting`, `/theme-settings`)
 * take every posted name/value pair into `settings` for the caller's store with no allow-list and no permission, so staff can
 * still overwrite the same rows by NAME. That needs an allow-list per writer, not a one-line gate.
 *
 * AppSettingController repeats the F6 defect (five sites): `Setting::where(['name' => 'theme_logo'])->first()` with no store
 * predicate, then File::delete -- so ANY merchant saving their own logo deleted the lowest-id tenant's logo file.
 *
 * `currency_rates` has no migration (GATE F), so settingForm's currency tab needs it created, as ChatgptKeyDisclosureTest does.
 */
class WebSettingsSecretsTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const SMTP_SECRET = 'FIXTURE-SMTP-PASSWORD-web18';
    private const STRIPE_SECRET = 'FIXTURE-STRIPE-SECRET-web18';
    private const PERMISSION = 'Manage Setting';

    private User $merchant;
    private Store $store;
    private User $staff;
    private User $operator;
    private bool $createdUploadsRoot = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createdUploadsRoot = ! is_dir(base_path('uploads'));

        if (! Schema::hasTable('currency_rates')) {
            Schema::create('currency_rates', function ($t) {
                $t->id();
                $t->unsignedBigInteger('store_id')->nullable();
                $t->string('currency_code');
                $t->string('currency_name')->nullable();
                $t->string('symbol')->nullable();
                $t->decimal('rate_per_usd', 20, 8)->default(1);
                $t->boolean('is_active')->default(1);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }

        [$this->merchant, $this->store] = $this->makeTenant();
        $this->merchant->plan_id = DB::table('plans')->insertGetId([
            'name' => 'web-plan-' . uniqid(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'off', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->merchant->save();
        $this->staff = $this->makeStaff($this->merchant);
        $this->operator = $this->makePlatformAdmin();

        $this->setting($this->store, [
            'email_setting' => 'SMTP', 'MAIL_DRIVER' => 'smtp', 'MAIL_HOST' => 'smtp.example.test', 'MAIL_PORT' => '587',
            'MAIL_USERNAME' => 'mailer', 'MAIL_PASSWORD' => self::SMTP_SECRET, 'MAIL_ENCRYPTION' => 'tls',
            'MAIL_FROM_NAME' => 'Shop', 'MAIL_FROM_ADDRESS' => 'shop@example.test',
            'stripe_secret_key' => self::STRIPE_SECRET, 'stripe_publishable_key' => 'pk_fixture', 'is_stripe_enabled' => 'on',
            'CURRENCY' => '$', 'CURRENCY_NAME' => 'USD',
        ]);

        // Utility::upload_file() reads the PLATFORM's storage settings from `created_by = 1`; without them every upload answers
        // 'not set configration' and the old-image delete is never reached.
        foreach (['storage_setting' => 'local', 'local_storage_validation' => 'jpg,jpeg,png', 'local_storage_max_upload_size' => '2048000'] as $name => $value) {
            DB::table('settings')->insert(['name' => $name, 'value' => $value, 'created_by' => 1, 'store_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    protected function tearDown(): void
    {
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

    private function stored(string $name): ?string
    {
        return DB::table('settings')->where('store_id', $this->store->id)->where('name', $name)->value('value');
    }

    private function tabHtml($response): string
    {
        return (string) (json_decode($response->getContent(), true)['data']['content'] ?? '') . $response->getContent();
    }

    // ---------------------------------------------------------------------------------------------
    // reads
    // ---------------------------------------------------------------------------------------------

    public function test_premise_the_owner_gets_the_payment_tab_with_the_gateway_secret_in_it(): void
    {
        $response = $this->actingAs($this->merchant)->post('/setting-form', ['tab_type' => 'payment_setting']);

        $response->assertOk();
        $this->assertTrue((bool) json_decode($response->getContent(), true)['is_success'], 'Premise: the tab did not render. ' . substr($response->getContent(), 0, 200));
        $this->assertStringContainsString(self::STRIPE_SECRET, $this->tabHtml($response), "Premise: the payment tab does not carry the gateway secret, so 'refused' below proves nothing.");
    }

    public function test_staff_without_the_permission_are_not_handed_the_settings_tabs(): void
    {
        // The payment tab carries every gateway key; the email tab's password comes from get-email-fields (below). Every tab is
        // refused: the Settings screen is one permission.
        foreach (['payment_setting', 'email_setting', 'system_setting'] as $tab) {
            $response = $this->actingAs($this->staff)->post('/setting-form', ['tab_type' => $tab]);

            $this->assertSame(403, $response->getStatusCode(), "{$tab} was served to a staff member without the permission.");
            $this->assertStringNotContainsString(self::STRIPE_SECRET, $response->getContent());
        }
    }

    public function test_staff_with_the_permission_still_get_the_tabs(): void
    {
        $this->grantPermission($this->staff, self::PERMISSION);

        $response = $this->actingAs($this->staff)->post('/setting-form', ['tab_type' => 'payment_setting']);

        $response->assertOk();
        $this->assertStringContainsString(self::STRIPE_SECRET, $this->tabHtml($response));
    }

    public function test_the_operator_still_gets_the_tabs(): void
    {
        $response = $this->actingAs($this->operator)->post('/setting-form', ['tab_type' => 'email_setting']);

        $response->assertOk();
        $this->assertTrue((bool) json_decode($response->getContent(), true)['is_success'], substr($response->getContent(), 0, 200));
    }

    public function test_staff_without_the_permission_do_not_get_the_email_fields(): void
    {
        $response = $this->actingAs($this->staff)->post('/get-email-fields', ['emailsetting' => 'SMTP']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringNotContainsString(self::SMTP_SECRET, $response->getContent());
    }

    public function test_the_owner_gets_the_email_fields_with_the_password(): void
    {
        $response = $this->actingAs($this->merchant)->post('/get-email-fields', ['emailsetting' => 'SMTP']);

        $response->assertOk();
        $this->assertStringContainsString(self::SMTP_SECRET, $response->getContent(), 'Premise: the fields carry the password.');
    }

    // ---------------------------------------------------------------------------------------------
    // writes
    // ---------------------------------------------------------------------------------------------

    private function emailForm(): array
    {
        return [
            'mail_driver' => 'smtp', 'mail_host' => 'evil.example.test', 'mail_port' => '25', 'mail_username' => 'attacker',
            'mail_password' => 'ATTACKER-CHOSEN', 'mail_encryption' => 'tls', 'mail_from_address' => 'x@evil.example.test',
            'mail_from_name' => 'X',
        ];
    }

    public function test_staff_without_the_permission_cannot_overwrite_the_smtp_settings(): void
    {
        $response = $this->actingAs($this->staff)->post('/email-settings', $this->emailForm());

        $response->assertSessionHas('error');
        $this->assertSame(self::SMTP_SECRET, $this->stored('MAIL_PASSWORD'));
        $this->assertSame('smtp.example.test', $this->stored('MAIL_HOST'));
    }

    public function test_the_owner_can_still_save_the_smtp_settings(): void
    {
        $this->actingAs($this->merchant)->post('/email-settings', $this->emailForm())->assertSessionHas('success');

        $this->assertSame('ATTACKER-CHOSEN', $this->stored('MAIL_PASSWORD'), 'Premise: the owner\'s own save must persist.');
    }

    public function test_staff_without_the_permission_cannot_overwrite_the_payment_settings(): void
    {
        $response = $this->actingAs($this->staff)->post('/payment-setting', [
            'CURRENCY' => '$', 'CURRENCY_NAME' => 'USD', 'is_stripe_enabled' => 'on', 'stripe_secret_key' => 'ATTACKER-CHOSEN', 'stripe_publishable_key' => 'pk_x',
        ]);

        $response->assertSessionHas('error');
        $this->assertSame(self::STRIPE_SECRET, $this->stored('stripe_secret_key'));
    }

    public function test_the_owner_can_still_save_the_payment_settings(): void
    {
        $this->actingAs($this->merchant)->post('/payment-setting', [
            'CURRENCY' => '$', 'CURRENCY_NAME' => 'USD', 'is_stripe_enabled' => 'on', 'stripe_secret_key' => 'NEW-OWNER-SECRET', 'stripe_publishable_key' => 'pk_new',
        ])->assertSessionHas('success');

        $this->assertSame('NEW-OWNER-SECRET', $this->stored('stripe_secret_key'), 'Premise: the owner\'s own save must persist.');
    }

    // ---------------------------------------------------------------------------------------------
    // F6, web: AppSettingController deletes the lowest-id tenant's file
    // ---------------------------------------------------------------------------------------------

    public static function uploadHandlers(): array
    {
        return [
            'seo metaimage' => ['/seo-setting', 'metaimage', ['google_analytic' => 'g', 'fbpixel_code' => 'f', 'metakeyword' => 'k', 'metadesc' => 'd']],
            'theme logo' => ['/theme-settings', 'theme_logo', []],
            'invoice logo' => ['/theme-settings', 'invoice_logo', []],
            'favicon' => ['/theme-settings', 'theme_favicon', []],
            'theme image' => ['/theme-settings', 'theme_image', []],
        ];
    }

    /**
     * @dataProvider uploadHandlers
     */
    public function test_saving_my_own_image_never_deletes_another_tenants_file(string $uri, string $settingName, array $extra): void
    {
        Storage::fake('theme');
        [$other, $otherStore] = $this->makeTenant();

        File::ensureDirectoryExists(base_path('uploads/zz-sec18'));
        $victim = 'uploads/zz-sec18/victim-' . $settingName . '.png';
        $own = 'uploads/zz-sec18/own-' . $settingName . '.png';
        File::put(base_path($victim), 'victim');
        File::put(base_path($own), 'own');

        // The OTHER tenant's row has the lowest id on the platform: that is what an unscoped ->first() returns.
        DB::table('settings')->insert(['name' => $settingName, 'value' => $victim, 'created_by' => $other->id, 'store_id' => $otherStore->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->setting($this->store, [$settingName => $own]);

        $this->actingAs($this->merchant)->post($uri, $extra + [$settingName => UploadedFile::fake()->image('new.png')]);

        $this->assertTrue(! file_exists(base_path($victim)) || ! file_exists(base_path($own)),
            "Premise: {$uri} never reached the old-image delete, so this test proves nothing.");
        $this->assertFileExists(base_path($victim), "{$uri} deleted another tenant's {$settingName}.");
        $this->assertFileDoesNotExist(base_path($own), "{$uri} left the caller's own previous {$settingName} behind.");
    }

    /**
     * @dataProvider uploadHandlers
     */
    public function test_a_poisoned_stored_path_is_never_unlinked_by_the_web_handlers(string $uri, string $settingName, array $extra): void
    {
        Storage::fake('theme');
        File::ensureDirectoryExists(base_path('storage/framework/testing/sec18'));
        File::put(base_path('storage/framework/testing/sec18/outside.txt'), 'application file');
        $this->setting($this->store, [$settingName => 'uploads/../storage/framework/testing/sec18/outside.txt']);

        $this->actingAs($this->merchant)->post($uri, $extra + [$settingName => UploadedFile::fake()->image('new.png')]);

        $this->assertFileExists(base_path('storage/framework/testing/sec18/outside.txt'), "{$uri} unlinked a stored {$settingName} path outside uploads/.");
        File::deleteDirectory(base_path('storage/framework/testing/sec18'));
    }

    /**
     * `Utility::changeStorageLimit($createdId, $path)` -- called with a database path by ~35 handlers, three of them in
     * SettingController::BusinessSettings on the caller's OWN store-scoped row -- does `File::glob(base_path($path))` and then
     * `File::delete()` of every match. Settings rows are written by NAME through the generic writers, so a staff member can
     * store `logo_dark = config/*` and then save a logo: every file directly inside config/ is unlinked. Found by the review of
     * the F6 fix, which asked what the delete does with a path of the caller's choosing.
     *
     * This test points the pattern at a sandbox directory; the real target is any directory of the application.
     *
     * P1-SEC-27 closed the first half of this for a member of staff with no settings permission: the generic writers now require
     * `Manage Setting` (see SettingsRestrictedWriteTest), so the poisoned row can no longer be stored by them. The delete primitive
     * is still reachable by whoever MAY write the row -- the owner, and staff the owner gave `Manage Setting` -- and by any writer
     * the name rules do not reach (the vendored module writers, P1-SEC-31), so the central fix in StoredUpload stays the control
     * under test, with the actor moved to a principal that can still reach it.
     */
    public function test_a_poisoned_logo_row_cannot_make_business_settings_delete_files_by_glob(): void
    {
        Storage::fake('theme');
        $this->grantPermission($this->staff, 'Manage Setting');
        $dir = 'storage/framework/testing/sec18/glob';
        File::ensureDirectoryExists(base_path($dir));
        foreach (['a', 'b', 'c'] as $n) {
            File::put(base_path("$dir/$n.txt"), 'application file');
        }

        $this->actingAs($this->staff)->post('/customize-settings', ['logo_dark' => "$dir/*"]);
        $this->assertSame("$dir/*", $this->stored('logo_dark'), 'Premise: the generic writer stored the poisoned row for the staff member\'s store.');

        $this->actingAs($this->staff)->post('/business-settings', ['logo_dark' => UploadedFile::fake()->image('l.png')]);

        foreach (['a', 'b', 'c'] as $n) {
            $this->assertFileExists(base_path("$dir/$n.txt"), "A glob in a stored path unlinked $n.txt.");
        }
        File::deleteDirectory(base_path('storage/framework/testing/sec18'));
    }
}
