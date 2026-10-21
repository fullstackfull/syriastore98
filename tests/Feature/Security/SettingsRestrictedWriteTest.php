<?php

namespace Tests\Feature\Security;

use App\Models\Store;
use App\Models\User;
use App\Services\StoreSettingsAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P1-SEC-27 -- a user who may not open the payment or email tab could still write its settings BY NAME.
 * =============================================================================================
 *
 * P1-SEC-18 gated the handlers that READ and WRITE the SMTP and gateway credentials. Nine other web handlers take EVERY posted
 * name/value into `settings` for the caller's store, with no allow-list and no permission middleware on the route:
 *
 *   Settings screen (permission `Manage Setting`):    /business-settings /cookie-setting /customize-settings /system-settings
 *                                                     /currency-settings /meta-seo-settings  (+ /loyality-program-settings)
 *   Store Setting screen (`Manage Store Setting`):    /theme-settings /seo-setting /shipping-label-setting
 *
 * So any logged-in user of the store -- staff holding neither permission included -- could post `MAIL_PASSWORD` (storefront mail,
 * shoppers' reset links, then goes through their server), `stripe_secret_key` (card payments settle to them), `bank_transfer` (the
 * account shoppers are told to pay), `CURRENCY_NAME` (what a card is charged in), `shopify_store_url` (where the stored token is
 * sent), `domains` + `enable_domain` (ThemeSettings turns those into a hosting-panel delete). They never needed to read the old
 * value. The design, and why it is not a per-writer allow-list:
 *
 *   - the six Settings-screen handlers (and the web currency and loyalty twins) require `Manage Setting` OUTRIGHT, exactly as
 *     settingForm() -- the tab renderer -- already does;
 *   - the three Store Setting handlers require `Manage Store Setting` (as AppSettingController::index() does) and refuse a CHANGE to a
 *     restricted name unless the user also holds `Manage Setting`. The restricted names are the payment / mail / storage / currency /
 *     integration / routing ones; the script-bearing names the screen exists to edit (storejs, storecss, pixels) stay open to
 *     `Manage Store Setting` holders and are the owner's decision (P1-OWN-12);
 *   - the admin API's Cod, Bank, Loyalty and three currency writers ask the same App\Services\StoreSettingsAccess as the SMTP/Stripe
 *     handlers.
 *
 * What stays open on purpose: the owner, the operator and staff holding `Manage Setting` write exactly as before.
 *
 * SQLite specifics: the admin-API writers use MySQL-only `insert ... on duplicate key update`, so an ALLOWED api write is asserted as
 * "not 401/403" and a refusal as an exact 403 with the row untouched; MySQL's collation, which the name rules exist for, is proven by
 * tests/Support/Security/bin/verify-settings-name-collation-on-mysql.php.
 */
class SettingsRestrictedWriteTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const PERMISSION = 'Manage Setting';
    private const STORE_PERMISSION = 'Manage Store Setting';

    private User $merchant;
    private Store $store;
    /** a member of staff who holds NEITHER permission */
    private User $staff;
    /** ... only `Manage Store Setting` */
    private User $storeStaff;
    /** ... `Manage Setting` */
    private User $settingsStaff;
    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

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
            'name' => 'srw-plan-' . uniqid(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'off', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->merchant->save();
        $this->staff = $this->makeStaff($this->merchant);
        $this->storeStaff = $this->makeStaff($this->merchant);
        $this->grantPermission($this->storeStaff, self::STORE_PERMISSION);
        $this->settingsStaff = $this->makeStaff($this->merchant);
        $this->grantPermission($this->settingsStaff, self::PERMISSION);
        $this->operator = $this->makePlatformAdmin();

        // currencySettings() refuses a currency the store has no active rate row for; USD is what it posts when none is chosen.
        DB::table('currency_rates')->insert(['store_id' => $this->store->id, 'currency_code' => 'USD', 'currency_name' => 'US Dollar', 'symbol' => '$', 'rate_per_usd' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

        foreach (['MAIL_PASSWORD' => 'ORIGINAL-SMTP', 'stripe_secret_key' => 'ORIGINAL-STRIPE', 'bank_transfer' => 'ORIGINAL-BANK-ACCOUNT', 'domains' => 'shop.example.test'] as $n => $v) {
            DB::table('settings')->insert(['name' => $n, 'value' => $v, 'created_by' => $this->merchant->id, 'store_id' => $this->store->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function stored(string $name): ?string
    {
        return DB::table('settings')->where('store_id', $this->store->id)->where('name', $name)->orderBy('id')->value('value');
    }

    /** Some writers key their rows by the writer too (a staff member's save creates a second row), so "was it written" asks every row. */
    private function everStored(string $name, string $value): bool
    {
        return DB::table('settings')->where('store_id', $this->store->id)->where('name', $name)->pluck('value')->contains($value);
    }

    private function rows(string $name): int
    {
        return DB::table('settings')->where('store_id', $this->store->id)->where('name', $name)->count();
    }

    // ---------------------------------------------------------------------------------------------
    // the classifier
    // ---------------------------------------------------------------------------------------------

    public static function restrictedNames(): array
    {
        return array_map(fn ($n) => [$n], [
            'MAIL_PASSWORD', 'MAIL_HOST', 'mail_username', 'stripe_secret_key', 'stripe_publishable_key', 'stripe_secret', 'publishable_key',
            'bank_transfer', 'is_bank_transfer_enabled', 'cod_info', 'is_cod_enabled', 'domains', 'enable_domain',
            'paystack_secret', 'razorpay_secret', 'is_paystack_enabled', 'PAYMERA_USERNAME', 'google_recaptcha_secret',
            // the names the host lookup routes by, where uploads go, and the gateway images
            'subdomain', 'enable_subdomain', 'enable_storelink', 'storage_setting', 's3_endpoint', 's3_key', 'wasabi_secret', 'wasabi_url',
            'local_storage_validation', 'local_storage_max_upload_size',
            'paypal_image', 'paystack_image', 'cinet_pay_image', 'senang_pay_image', 'cybersource_pay_image', 'ozow_pay_image', 'benefit_mode',
            // what a shopper is charged in and what a loyalty point is worth
            'CURRENCY', 'CURRENCY_NAME', 'defult_currancy', 'defult_currancy_symbol', 'site_currency_symbol_position', 'loyality_program_enabled', 'reward_point',
            // where an integration's stored token is sent
            'shopify_store_url', 'shopify_setting_enabled', 'woocommerce_store_url', 'woocommerce_consumer_key', 'twilio_sid', 'twilio_from',
            'twilio_notification_number', 'whatsapp_phone_number_id', 'whatsapp_contact_number', 'fcm_Key', 'firebase_enabled',
            // whose ID token the storefront accepts
            'google_api_audiences', 'Google_client_id', 'apple_client_ids', 'facebook_client_id',
        ]);
    }

    public static function ordinaryNames(): array
    {
        return array_map(fn ($n) => [$n], [
            'cust_darklayout', 'cust_theme_bg', 'logo_dark', 'theme_logo', 'favicon', 'title_text', 'footer_text', 'SITE_RTL', 'color',
            'site_date_format', 'enable_cookie', 'cookie_title', 'contactus_url', 'metakeyword', 'metadesc', 'store_address', 'store_city',
            // every browser form posts these; "token" is in the secret classifier's pattern, so they were refused (review round 1)
            '_token', '_method', 'app_setting_tab', 'theme_name', 'store_slug', 'default_language', 'additional_notes', 'invoice_logo',
            // the script-bearing names the Store Setting screen exists to edit -- the owner's decision, P1-OWN-12
            'storejs', 'storecss', 'google_analytic', 'fbpixel_code',
        ]);
    }

    /** @dataProvider restrictedNames */
    public function test_payment_mail_currency_integration_and_domain_names_are_restricted(string $name): void
    {
        $this->assertTrue(StoreSettingsAccess::isRestrictedName($name));
    }

    /** @dataProvider ordinaryNames */
    public function test_appearance_seo_and_locale_names_are_not(string $name): void
    {
        $this->assertFalse(StoreSettingsAccess::isRestrictedName($name), "{$name} was classified restricted; a screen that posts it would break for staff.");
    }

    /**
     * 14 of the gateway ids have a tile image whose name is NOT one of the patterns (`xendit_image`, `cinetpay_image`, `ozow_image`, ...):
     * only the rule derived from PaymentSettingController::getPaymentGateways() covers them, and it is what covers a gateway added tomorrow.
     */
    public function test_every_gateways_tile_image_is_restricted_whatever_its_name(): void
    {
        $ids = array_column((new \App\Http\Controllers\PaymentSettingController())->getPaymentGateways(), 'id');
        $this->assertGreaterThan(30, count($ids));

        foreach ($ids as $id) {
            $this->assertTrue(StoreSettingsAccess::isRestrictedName($id . '_image'), "{$id}_image is writable by name.");
        }
    }

    // ---------------------------------------------------------------------------------------------
    // the web's generic writers
    // ---------------------------------------------------------------------------------------------

    private static function cookieFields(): array
    {
        return ['cookie_title' => 't', 'cookie_description' => 'd', 'strictly_cookie_title' => 's', 'strictly_cookie_description' => 'sd', 'more_information_title' => 'm', 'contactus_url' => '#'];
    }

    private static function seoFields(): array
    {
        return ['google_analytic' => 'g', 'fbpixel_code' => 'f', 'metakeyword' => 'k', 'metadesc' => 'd'];
    }

    /** The Settings screen: uri => the fields that reach the write (some handlers validate first) */
    public static function settingsScreenWriters(): array
    {
        return [
            'customize' => ['/customize-settings', []],
            'system' => ['/system-settings', ['currency_format' => '1']],
            'currency' => ['/currency-settings', []],
            'cookie' => ['/cookie-setting', self::cookieFields()],
            'meta seo' => ['/meta-seo-settings', []],
            'business' => ['/business-settings', []],
        ];
    }

    /** The Store Setting screen */
    public static function storeScreenWriters(): array
    {
        return [
            'app seo' => ['/seo-setting', self::seoFields()],
            'shipping label' => ['/shipping-label-setting', ['store_address' => 'a', 'store_city' => 'c', 'store_state' => 's', 'store_zipcode' => 'z', 'store_country' => 'k']],
            'theme' => ['/theme-settings', []],
        ];
    }

    public static function genericWriters(): array
    {
        return self::settingsScreenWriters() + self::storeScreenWriters();
    }

    /** what a user who must not be able to write them tries to */
    private function attack(): array
    {
        return [
            'MAIL_PASSWORD' => 'ATTACKER', 'stripe_secret_key' => 'ATTACKER', 'bank_transfer' => 'ATTACKER-ACCOUNT', 'domains' => 'victim.example.test',
            'enable_domain' => 'on', 'CURRENCY_NAME' => 'JPY', 'defult_currancy' => 'JPY', 'reward_point' => '99999', 'shopify_store_url' => 'attacker.example',
            'woocommerce_store_url' => 'https://attacker.example', 's3_endpoint' => 'https://attacker.example', 'zz_ordinary' => 'ATTACKER-ORDINARY',
        ];
    }

    private function assertNothingOfTheAttackWasWritten(string $uri, bool $ordinaryToo): void
    {
        foreach (['MAIL_PASSWORD', 'stripe_secret_key', 'domains', 'CURRENCY_NAME', 'defult_currancy', 'reward_point', 'shopify_store_url', 'woocommerce_store_url', 's3_endpoint'] as $name) {
            $this->assertFalse($this->everStored($name, $this->attack()[$name]), "{$uri} let a user write {$name} by name.");
        }
        $this->assertFalse($this->everStored('bank_transfer', 'ATTACKER-ACCOUNT'), "{$uri} let a user overwrite the bank-transfer destination.");
        $this->assertNull($this->stored('enable_domain'));
        if ($ordinaryToo) {
            $this->assertFalse($this->everStored('zz_ordinary', 'ATTACKER-ORDINARY'), "{$uri}: a refused request writes nothing, not even its ordinary fields.");
        }
    }

    /** @dataProvider genericWriters */
    public function test_premise_the_owner_can_write_a_restricted_name_through_the_generic_writer(string $uri, array $fields): void
    {
        $this->actingAs($this->merchant)->post($uri, $fields + ['MAIL_PASSWORD' => 'OWNER-CHOSEN']);

        $this->assertTrue($this->everStored('MAIL_PASSWORD', 'OWNER-CHOSEN'), "Premise: {$uri} did not reach its write for the owner, so 'refused' below proves nothing.");
    }

    // ----- the Settings screen: Manage Setting, outright

    /** @dataProvider settingsScreenWriters */
    public function test_a_settings_screen_writer_refuses_a_user_without_manage_setting_outright(string $uri, array $fields): void
    {
        foreach ([$this->staff, $this->storeStaff] as $who) {
            $this->actingAs($who)->post($uri, $fields + $this->attack() + ['storejs' => '<script>steal()</script>'])->assertSessionHas('error');
        }

        $this->assertNothingOfTheAttackWasWritten($uri, true);
        $this->assertFalse($this->everStored('storejs', '<script>steal()</script>'), "{$uri} let a user put script on the storefront.");
    }

    /** @dataProvider settingsScreenWriters */
    public function test_a_settings_screen_writer_admits_staff_holding_manage_setting(string $uri, array $fields): void
    {
        $this->actingAs($this->settingsStaff)->post($uri, $fields + ['MAIL_PASSWORD' => 'DELEGATED', 'zz_ordinary' => 'v2'])->assertSessionMissing('error');

        $this->assertTrue($this->everStored('MAIL_PASSWORD', 'DELEGATED'), "{$uri} refused a staff member who holds Manage Setting.");
        $this->assertTrue($this->everStored('zz_ordinary', 'v2'));
    }

    // ----- the Store Setting screen: Manage Store Setting, and restricted names need Manage Setting

    /** @dataProvider storeScreenWriters */
    public function test_a_store_screen_writer_refuses_a_user_holding_neither_permission_outright(string $uri, array $fields): void
    {
        $this->actingAs($this->staff)->post($uri, $fields + $this->attack() + ['storejs' => '<script>steal()</script>', 'zz_ordinary2' => 'x'])->assertSessionHas('error');

        $this->assertNothingOfTheAttackWasWritten($uri, true);
        $this->assertFalse($this->everStored('storejs', '<script>steal()</script>'));
        $this->assertFalse($this->everStored('zz_ordinary2', 'x'));
    }

    /** The gate alone, with nothing restricted in the post: the restricted-name rule would refuse an attack, this must refuse an honest-looking post. */
    public function test_a_store_screen_writer_refuses_a_user_holding_neither_permission_even_for_an_ordinary_post(): void
    {
        foreach (self::storeScreenWriters() as $label => [$uri, $fields]) {
            $this->actingAs($this->staff)->post($uri, $fields + ['zz_ordinary' => "only-{$label}"])->assertSessionHas('error');
            $this->assertFalse($this->everStored('zz_ordinary', "only-{$label}"), "{$uri} saved for a user who holds neither permission.");
        }
    }

    /** @dataProvider storeScreenWriters */
    public function test_staff_holding_only_manage_store_setting_cannot_write_a_restricted_name_by_name(string $uri, array $fields): void
    {
        $this->actingAs($this->storeStaff)->post($uri, $fields + $this->attack())->assertSessionHas('error');

        $this->assertNothingOfTheAttackWasWritten($uri, true);
    }

    /**
     * Review round 1, finding 2. Every browser form posts `_token`; "token" is in GatewaySecretRedactor's pattern; the baseline for a
     * missing name is empty, so the CSRF token was "a change to a secret" and the WHOLE request was refused. CSRF is off under the
     * tests, so no earlier test posted it.
     *
     * @dataProvider storeScreenWriters
     */
    public function test_an_honest_browser_post_with_a_csrf_token_still_saves_for_staff_holding_manage_store_setting(string $uri, array $fields): void
    {
        $this->actingAs($this->storeStaff)
            ->post($uri, $fields + ['_token' => 'csrf-token-from-the-form', '_method' => 'POST', 'zz_ordinary' => 'v1'])
            ->assertSessionMissing('error');

        $this->assertTrue($this->everStored('zz_ordinary', 'v1'), "{$uri} refused an ordinary post because it carried a _token.");
    }

    /** @dataProvider storeScreenWriters */
    public function test_staff_holding_manage_setting_still_write_restricted_names_through_the_store_screen(string $uri, array $fields): void
    {
        $this->actingAs($this->settingsStaff)->post($uri, $fields + ['MAIL_PASSWORD' => 'DELEGATED'])->assertSessionMissing('error');

        $this->assertTrue($this->everStored('MAIL_PASSWORD', 'DELEGATED'), "{$uri} refused a staff member who holds Manage Setting.");
    }

    /**
     * DOCUMENTED, NOT ENDORSED -- P1-OWN-12. The script-bearing names are what the Store Setting screen exists to edit, so a
     * `Manage Store Setting` holder can put script on every page of the storefront, checkout included. Whether that needs
     * `Manage Setting` is the owner's decision; this test fails the day someone changes it, which is when the decision should be
     * recorded.
     */
    public function test_script_bearing_names_remain_open_to_manage_store_setting_holders_pending_the_owner_decision(): void
    {
        $this->actingAs($this->storeStaff)->post('/seo-setting', self::seoFields() + ['storejs' => '<script>x()</script>', 'storecss' => 'a{}'])->assertSessionMissing('error');

        $this->assertTrue($this->everStored('storejs', '<script>x()</script>'));
        $this->assertTrue($this->everStored('storecss', 'a{}'));
    }

    // ----- the restricted-name rule, driven through one Store Setting writer

    /**
     * A form that always posts its fields resubmits the values already stored when the user changed something else; refusing THAT
     * would make the whole tab unusable. And the resubmitted value is not WRITTEN either: the writers key their rows by the writing
     * user too, so a write would insert a second row owned by the staff member that every newest-row reader prefers.
     */
    public function test_resubmitting_the_stored_value_is_not_a_change_and_writes_nothing(): void
    {
        $before = $this->rows('MAIL_PASSWORD') + $this->rows('domains') + $this->rows('stripe_secret_key');

        $this->actingAs($this->storeStaff)->post('/seo-setting', self::seoFields() + [
            'MAIL_PASSWORD' => 'ORIGINAL-SMTP', 'domains' => 'shop.example.test', 'stripe_secret_key' => 'ORIGINAL-STRIPE', 'cust_darklayout' => 'on',
        ])->assertSessionMissing('error');

        $this->assertTrue($this->everStored('cust_darklayout', 'on'), 'The ordinary field posted next to unchanged restricted ones must save.');
        $this->assertSame($before, $this->rows('MAIL_PASSWORD') + $this->rows('domains') + $this->rows('stripe_secret_key'), 'An unchanged restricted value was written again (a second row, owned by the staff member).');
    }

    /**
     * "Unchanged" must be a true no-op. /seo-setting keys its rows by name and store only and RE-STAMPS created_by on the row it
     * updates, which hides the owner's row from every created_by-filtered reader (getAdminAllSetting, the authenticated
     * Utility::Setting()); /theme-settings keys by created_by too and INSERTS a second row. Neither may happen for a value that was
     * merely resubmitted -- from the body or from the query string, which Request::all() merges in.
     */
    public function test_a_resubmitted_restricted_value_is_not_restamped_or_duplicated(): void
    {
        $owner = (string) $this->merchant->id;
        $restricted = ['MAIL_PASSWORD' => 'ORIGINAL-SMTP', 'stripe_secret_key' => 'ORIGINAL-STRIPE', 'bank_transfer' => 'ORIGINAL-BANK-ACCOUNT', 'domains' => 'shop.example.test'];
        $count = fn () => DB::table('settings')->where('store_id', $this->store->id)->whereIn('name', array_keys($restricted))->count();
        $before = $count();

        $this->actingAs($this->storeStaff)->post('/seo-setting', self::seoFields() + $restricted)->assertSessionMissing('error');
        $this->actingAs($this->storeStaff)->post('/theme-settings', $restricted + ['store_city' => 'Hama'])->assertSessionMissing('error');
        $this->actingAs($this->storeStaff)->post('/theme-settings?' . http_build_query($restricted), ['store_city' => 'Hama2'])->assertSessionMissing('error');

        $this->assertSame($before, $count(), 'A resubmitted restricted value was written again as a second row.');
        foreach (array_keys($restricted) as $name) {
            $this->assertSame([$owner], DB::table('settings')->where('store_id', $this->store->id)->where('name', $name)->pluck('created_by')->map(fn ($v) => (string) $v)->all(), "{$name} was re-stamped to the staff member.");
        }
    }

    public function test_changing_one_restricted_value_among_unchanged_ones_is_refused_and_nothing_is_written(): void
    {
        $this->actingAs($this->storeStaff)->post('/seo-setting', self::seoFields() + [
            'MAIL_PASSWORD' => 'ORIGINAL-SMTP', 'bank_transfer' => 'ATTACKER-ACCOUNT', 'cust_darklayout' => 'on',
        ])->assertSessionHas('error');

        $this->assertFalse($this->everStored('bank_transfer', 'ATTACKER-ACCOUNT'));
        $this->assertFalse($this->everStored('cust_darklayout', 'on'), 'A refused request writes nothing, not even its ordinary fields.');
    }

    public function test_a_restricted_name_with_no_stored_value_may_be_posted_empty_but_not_filled(): void
    {
        $this->actingAs($this->storeStaff)->post('/seo-setting', self::seoFields() + ['MAIL_HOST' => ''])->assertSessionMissing('error');
        $this->actingAs($this->storeStaff)->post('/seo-setting', self::seoFields() + ['MAIL_HOST' => 'smtp.attacker.example'])->assertSessionHas('error');
        $this->assertFalse($this->everStored('MAIL_HOST', 'smtp.attacker.example'));
    }

    public function test_an_array_or_a_file_under_a_restricted_name_counts_as_a_change(): void
    {
        $this->actingAs($this->storeStaff)->post('/seo-setting', self::seoFields() + ['MAIL_PASSWORD' => ['x']])->assertSessionHas('error');
        $this->actingAs($this->storeStaff)->post('/seo-setting', self::seoFields() + ['bank_transfer_image' => UploadedFile::fake()->image('a.png')])->assertSessionHas('error');
    }

    /** An integer key is a name too: the writers' `where name = 0` matches EVERY non-numeric name on MySQL. */
    public function test_a_post_with_an_integer_key_is_refused_for_a_user_without_manage_setting(): void
    {
        $this->actingAs($this->storeStaff)->post('/seo-setting', self::seoFields() + ['0' => 'clobber'])->assertSessionHas('error');
        $this->assertFalse($this->everStored('0', 'clobber'));

        $this->assertTrue(StoreSettingsAccess::deniesRestrictedWrite([0 => 'x'], $this->storeStaff, $this->store->id));
        $this->assertFalse(StoreSettingsAccess::deniesRestrictedWrite([0 => 'x'], $this->merchant, $this->store->id), 'The owner is not affected.');
    }

    /** Finding 3 (classification). Case, trailing space: MySQL's collation says these ARE the restricted names. */
    public static function spellingsOfRestrictedNames(): array
    {
        return array_map(fn ($n) => [$n], [
            'Domains', 'DOMAINS', 'domains ', ' domains', "domains\t", 'Bank_Transfer', 'Is_Cod_Enabled', 'ENABLE_DOMAIN', 'Enable_Domain',
            'Mail_Password', 'mail_password', 'STRIPE_SECRET_KEY', 'Subdomain', 'ENABLE_SUBDOMAIN', 'Enable_StoreLink', 'S3_ENDPOINT',
            'WASABI_SECRET', 'Storage_Setting', 'PayPal_Image', 'Benefit_Mode', 'Is_Stripe_Enabled', 'Cod_Info', 'Currency_Name', 'REWARD_POINT',
            'Shopify_Store_Url', 'LOCAL_STORAGE_VALIDATION',
        ]);
    }

    /** @dataProvider spellingsOfRestrictedNames */
    public function test_every_spelling_of_a_restricted_name_is_restricted(string $name): void
    {
        $this->assertTrue(StoreSettingsAccess::isRestrictedName($name), json_encode($name) . ' escaped the classifier; on MySQL it is the same row as the canonical name.');
    }

    /** @dataProvider spellingsOfRestrictedNames */
    public function test_staff_cannot_reach_a_restricted_row_through_another_spelling(string $name): void
    {
        $this->actingAs($this->storeStaff)->post('/seo-setting', self::seoFields() + [$name => 'ATTACKER'])->assertSessionHas('error');

        $this->assertSame(0, DB::table('settings')->where('store_id', $this->store->id)->where('value', 'ATTACKER')->count());
    }

    /**
     * Finding 3 (blanking). The posted spelling differs from the stored one and the value is EMPTY: the stored row is keyed
     * `MAIL_HOST`, a lookup by `mail_host` finds nothing (SQLite) or the same row (MySQL), so "empty equals missing" read as
     * "unchanged" while the writer nulled the row and renamed it.
     */
    public function test_posting_a_stored_restricted_row_under_another_spelling_is_refused_even_with_the_same_value(): void
    {
        foreach (['mail_password' => 'ORIGINAL-SMTP', 'Mail_Password' => '', 'MAIL_PASSWORD ' => 'ORIGINAL-SMTP', 'Stripe_Secret_Key' => ''] as $name => $value) {
            $this->actingAs($this->storeStaff)->post('/seo-setting', self::seoFields() + [$name => $value])->assertSessionHas('error');
        }

        $this->assertSame('ORIGINAL-SMTP', $this->stored('MAIL_PASSWORD'));
        $this->assertSame('ORIGINAL-STRIPE', $this->stored('stripe_secret_key'));
    }

    public static function opaqueNames(): array
    {
        return [['d' . "\u{00F3}" . 'mains'], ['bank_transf' . "\u{00E9}" . 'r'], ["domains\x01"], ["dom\u{00AD}ains"], ['zz_ordinary' . "\u{00E9}"], ["mail_host\0"]];
    }

    /** MySQL may fold a non-ASCII (or control) byte onto an ASCII letter; no real form posts one. */
    public function test_a_name_with_a_non_ascii_or_control_byte_is_refused_outright(): void
    {
        foreach (self::opaqueNames() as [$name]) {
            $this->assertTrue(StoreSettingsAccess::deniesRestrictedWrite([$name => ''], $this->storeStaff, $this->store->id), json_encode($name) . ' was admitted.');
            $this->assertFalse(StoreSettingsAccess::deniesRestrictedWrite([$name => ''], $this->merchant, $this->store->id), 'The owner is not affected.');
        }

        $this->assertFalse(StoreSettingsAccess::deniesRestrictedWrite(["d\u{00F3}mains" => ''], $this->settingsStaff, $this->store->id), 'Nor is staff holding the permission.');
    }

    /** Finding 7. A boolean is what a JSON client sends; the database stores '1' / '0'; a missing is_cod_enabled READS as 'on'. */
    public function test_a_boolean_and_a_default_are_compared_as_the_reader_sees_them(): void
    {
        $deny = fn (array $post): bool => StoreSettingsAccess::deniesRestrictedWrite($post, $this->storeStaff, $this->store->id);

        // no row: a reader gets Utility::Setting()'s 'on', so '' / false / 'off' TURN THE METHOD OFF
        $this->assertTrue($deny(['is_cod_enabled' => false]));
        $this->assertTrue($deny(['is_cod_enabled' => '']));
        $this->assertTrue($deny(['is_cod_enabled' => 'off']));
        $this->assertFalse($deny(['is_cod_enabled' => 'on']), 'Resubmitting what the reader already sees is not a change.');
        $this->assertTrue($deny(['is_bank_transfer_enabled' => false]));

        // a name with no default: a missing row equals empty, true is '1', false is '0'
        $this->assertFalse($deny(['cod_info' => '']));
        $this->assertFalse($deny(['cod_info' => null]));
        $this->assertTrue($deny(['cod_info' => false]));
        $this->assertTrue($deny(['cod_info' => true]));

        // a row that exists wins over the default, even when empty
        DB::table('settings')->insert(['name' => 'is_cod_enabled', 'value' => '', 'created_by' => $this->merchant->id, 'store_id' => $this->store->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertFalse($deny(['is_cod_enabled' => '']));
        $this->assertTrue($deny(['is_cod_enabled' => 'on']));
    }

    /** Finding 7, staleness. The baseline is read from the table: `Setting` is Cachable and the admin API writes with a raw insert. */
    public function test_the_baseline_is_the_table_not_a_cached_model_read(): void
    {
        \App\Models\Setting::where('store_id', $this->store->id)->where('name', 'bank_transfer')->get();   // primes the model cache
        DB::table('settings')->where('store_id', $this->store->id)->where('name', 'bank_transfer')->update(['value' => 'RAW-WRITE']);

        $this->assertTrue(StoreSettingsAccess::deniesRestrictedWrite(['bank_transfer' => 'ORIGINAL-BANK-ACCOUNT'], $this->storeStaff, $this->store->id), 'The stale value was treated as the baseline.');
        $this->assertFalse(StoreSettingsAccess::deniesRestrictedWrite(['bank_transfer' => 'RAW-WRITE'], $this->storeStaff, $this->store->id));
    }

    /** `settings` has no unique key: a reader sees the NEWEST row (`pluck`, GetValueByName), so that is the value a post is compared with. */
    public function test_with_two_rows_for_a_name_the_newest_is_the_baseline(): void
    {
        DB::table('settings')->insert(['name' => 'bank_transfer', 'value' => 'NEWER-ACCOUNT', 'created_by' => $this->merchant->id, 'store_id' => $this->store->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->assertFalse(StoreSettingsAccess::deniesRestrictedWrite(['bank_transfer' => 'NEWER-ACCOUNT'], $this->storeStaff, $this->store->id), 'Resubmitting what a reader sees is not a change.');
        $this->assertTrue(StoreSettingsAccess::deniesRestrictedWrite(['bank_transfer' => 'ORIGINAL-BANK-ACCOUNT'], $this->storeStaff, $this->store->id), 'The older row is not what a reader sees.');
    }

    /** The operator limb, which no web test drives (the operator has no store of their own here). */
    public function test_the_owner_the_operator_and_permitted_staff_are_never_denied_by_the_service(): void
    {
        $post = ['MAIL_PASSWORD' => 'X', 'domains' => 'y', "d\u{00F3}mains" => 'z', 'Bank_Transfer' => 'w', 0 => 'v'];

        $this->assertFalse(StoreSettingsAccess::deniesRestrictedWrite($post, $this->merchant, $this->store->id));
        $this->assertFalse(StoreSettingsAccess::deniesRestrictedWrite($post, $this->operator, $this->store->id));
        $this->assertFalse(StoreSettingsAccess::deniesRestrictedWrite($post, $this->settingsStaff, $this->store->id));
        $this->assertTrue(StoreSettingsAccess::deniesRestrictedWrite($post, $this->storeStaff, $this->store->id));
        $this->assertTrue(StoreSettingsAccess::deniesRestrictedWrite($post, $this->staff, $this->store->id));
        $this->assertTrue(StoreSettingsAccess::deniesRestrictedWrite($post, null, $this->store->id), 'No user fails closed.');

        foreach ([$this->merchant, $this->operator, $this->settingsStaff] as $who) {
            $this->assertTrue(StoreSettingsAccess::permitsStoreScreen($who));
        }
        $this->assertTrue(StoreSettingsAccess::permitsStoreScreen($this->storeStaff));
        $this->assertFalse(StoreSettingsAccess::permitsStoreScreen($this->staff));
        $this->assertFalse(StoreSettingsAccess::permitsStoreScreen(null));
    }

    // ---------------------------------------------------------------------------------------------
    // ThemeSettings turns a TOKEN into on/off flags and into hosting-panel calls
    // ---------------------------------------------------------------------------------------------

    private function recordingWebuzo(): object
    {
        $fake = new class extends \App\Services\WebuzoDomainService {
            public array $calls = [];

            public function __construct()
            {
            }

            public function addDomain(string $domain, bool $issueSsl = false): array
            {
                $this->calls[] = ['addDomain', $domain];

                return ['success' => true, 'message' => ''];
            }

            public function deleteDomain(string $domain): array
            {
                $this->calls[] = ['deleteDomain', $domain];

                return ['success' => true, 'message' => ''];
            }

            public function addSubdomain(string $domain, string $subdomain, bool $issueSsl = false): array
            {
                $this->calls[] = ['addSubdomain', $domain, $subdomain];

                return ['success' => true, 'error' => []];
            }

            public function domainExists(string $domain): bool
            {
                $this->calls[] = ['domainExists', $domain];

                return true;
            }
        };
        $this->app->instance(\App\Services\WebuzoDomainService::class, $fake);

        return $fake;
    }

    /** @param  'domain'|'subdomain'|'storelink'  $mode */
    private function routingMode(string $mode): void
    {
        DB::table('settings')->where('store_id', $this->store->id)->whereIn('name', StoreSettingsAccess::DOMAIN_ROUTING)->delete();

        $rows = [
            'domain' => ['domains' => 'shop.example.test', 'enable_domain' => 'on', 'enable_subdomain' => 'off', 'enable_storelink' => 'off'],
            'subdomain' => ['subdomain' => 'myshop.platform.test', 'enable_domain' => 'off', 'enable_subdomain' => 'on', 'enable_storelink' => 'off'],
            'storelink' => ['enable_domain' => 'off', 'enable_subdomain' => 'off', 'enable_storelink' => 'on'],
        ][$mode];

        foreach ($rows as $n => $v) {
            DB::table('settings')->insert(['name' => $n, 'value' => $v, 'created_by' => $this->merchant->id, 'store_id' => $this->store->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** every routing row, as [id => name=value], to prove a post wrote none */
    private function routingRows(): array
    {
        return DB::table('settings')->where('store_id', $this->store->id)->whereIn('name', StoreSettingsAccess::DOMAIN_ROUTING)->orderBy('id')
            ->get(['id', 'name', 'value'])->mapWithKeys(fn ($r) => [$r->id => "{$r->name}={$r->value}"])->all();
    }

    private function panelCalls(object $fake): array
    {
        return array_values(array_filter($fake->calls, fn ($c) => in_array($c[0], ['deleteDomain', 'addDomain', 'addSubdomain'], true)));
    }

    public static function requestsThatTryToChangeTheRouting(): array
    {
        return [
            'the stored vocabulary, enable_domain=on' => [['enable_domain' => 'on']],
            'switch to the store link' => [['enable_domain' => 'enable_storelink']],
            'switch to a sub-domain' => [['enable_domain' => 'enable_subdomain', 'subdomain' => 'x']],
            'clear the domain' => [['enable_domain' => 'enable_domain', 'domains' => '']],
            'omit the domain with the custom-domain token' => [['enable_domain' => 'enable_domain']],
            'another domain' => [['enable_domain' => 'enable_domain', 'domains' => 'attacker.example.test']],
            'another domain without a token' => [['domains' => 'attacker.example.test']],
            'the token as an array' => [['enable_domain' => ['enable_domain']]],
            'a different case of the field names' => [['enable_domain' => 'enable_domain', 'domains' => 'shop.example.test', 'Domains' => 'attacker.example.test']],
            'a derived flag posted raw' => [['enable_domain' => 'enable_domain', 'domains' => 'shop.example.test', 'enable_subdomain' => 'on']],
            'a file under a routing name' => [['enable_domain' => 'enable_domain', 'domains' => 'shop.example.test', 'subdomain' => 'FILE']],
        ];
    }

    /** Premise: for the OWNER these requests do reach the hosting panel, so "no call for staff" proves a control, not a dead fixture. */
    public function test_premise_the_owner_can_make_theme_settings_delete_the_custom_domain(): void
    {
        $this->routingMode('domain');
        $fake = $this->recordingWebuzo();

        $this->actingAs($this->merchant)->post('/theme-settings', ['enable_domain' => 'on']);

        $this->assertContains(['deleteDomain', 'shop.example.test'], $fake->calls, 'Premise: the delete branch did not run for the owner.');
        $this->assertSame('', $this->stored('domains'));
    }

    /** @dataProvider requestsThatTryToChangeTheRouting */
    public function test_staff_without_manage_setting_cannot_make_theme_settings_change_the_routing(array $post): void
    {
        $this->routingMode('domain');
        $fake = $this->recordingWebuzo();
        $before = $this->routingRows();
        if (($post['subdomain'] ?? null) === 'FILE') {
            $post['subdomain'] = UploadedFile::fake()->create('s.txt', 1);
        }

        $this->actingAs($this->storeStaff)->post('/theme-settings', $post + ['store_city' => 'Nowhere'])->assertSessionHas('error');

        $this->assertSame([], $fake->calls, 'The hosting panel was called for a user who may not change routing.');
        $this->assertSame($before, $this->routingRows(), 'A routing row was written or changed.');
        $this->assertFalse($this->everStored('store_city', 'Nowhere'), 'A refused request writes nothing.');
    }

    /**
     * Review round 2, finding 1. The Home and Checkout tabs post NO routing field at all, so absence is not an attempt: refusing it
     * made two tabs unsaveable for staff on every store that uses a custom domain or a sub-domain -- with a misleading message --
     * and the first version's test pinned that refusal as correct.
     */
    public static function modesAndHonestShapes(): array
    {
        $out = [];
        foreach (['domain', 'subdomain', 'storelink'] as $mode) {
            $out["home tab, {$mode}"] = [$mode, ['app_setting_tab' => 'pills-home-tab', 'theme_name' => 'Renamed', 'email' => 'new@example.test', 'default_language' => 'en']];
            $out["checkout tab, {$mode}"] = [$mode, ['app_setting_tab' => 'pills-checkout-tab', 'additional_notes' => 'on', 'is_checkout_login_required' => 'off']];
        }
        // the Custom tab with the radios and inputs a plan that allows the feature renders (the readonly sub-domain input posts the CURRENT slug)
        // with the token (the handler would call domainExists / addSubdomain for the owner): for a user without Manage Setting, nothing
        $out['custom tab on the stored custom domain'] = ['domain', ['app_setting_tab' => 'pills-custom-tab', 'enable_domain' => 'enable_domain', 'domains' => 'shop.example.test', 'subdomain' => 'slug', 'storecss' => 'a{}']];
        $out['custom tab on the stored sub-domain'] = ['subdomain', ['app_setting_tab' => 'pills-custom-tab', 'enable_domain' => 'enable_subdomain', 'subdomain' => 'myshop', 'storecss' => 'a{}']];
        $out['custom tab on the stored sub-domain after a slug change'] = ['subdomain', ['app_setting_tab' => 'pills-custom-tab', 'enable_domain' => 'enable_subdomain', 'subdomain' => 'renamed-slug', 'storecss' => 'a{}']];
        $out['custom tab on the store link'] = ['storelink', ['app_setting_tab' => 'pills-custom-tab', 'enable_domain' => 'enable_storelink', 'domains' => '', 'subdomain' => 'slug', 'storecss' => 'a{}']];
        // a plan with no domain feature renders no radio, but the domain input of a stored custom domain may still be posted
        $out['custom domain posted with no token'] = ['domain', ['app_setting_tab' => 'pills-custom-tab', 'domains' => 'shop.example.test', 'storecss' => 'a{}']];

        return $out;
    }

    /** @dataProvider modesAndHonestShapes */
    public function test_an_honest_post_still_saves_for_staff_without_manage_setting_and_touches_neither_the_panel_nor_the_routing(string $mode, array $post): void
    {
        $this->routingMode($mode);
        $fake = $this->recordingWebuzo();
        $before = $this->routingRows();

        $this->actingAs($this->storeStaff)->post('/theme-settings', $post + ['_token' => 'csrf', 'store_city' => "Aleppo-{$mode}"])->assertSessionMissing('error');

        $this->assertTrue($this->everStored('store_city', "Aleppo-{$mode}"), 'The ordinary field of the honest post did not save.');
        $this->assertSame([], $fake->calls, 'A user who may not change routing reached the hosting panel (a read or a write).');
        $this->assertSame($before, $this->routingRows(), 'An honest post wrote a routing row (a shadow row owned by the staff member), or changed one.');
    }

    /**
     * Review round 3. A stored domain that is not in the form the handler would write ('WWW.Shop.Example.test': hand-edited, or written
     * by an older build) makes the handler's `$oldDomain !== $newDomain` true on an HONEST post, so it would add the normalised
     * domain at the hosting panel and delete the stored one -- the same host. A user who may not change routing must not reach the
     * panel at all, whatever is stored.
     */
    public function test_a_non_canonical_stored_domain_does_not_send_a_user_without_manage_setting_to_the_panel(): void
    {
        $this->routingMode('domain');
        DB::table('settings')->where('store_id', $this->store->id)->where('name', 'domains')->update(['value' => 'WWW.Shop.Example.test']);
        $fake = $this->recordingWebuzo();

        foreach ([
            ['app_setting_tab' => 'pills-home-tab', 'theme_name' => 'X1'],
            ['app_setting_tab' => 'pills-custom-tab', 'enable_domain' => 'enable_domain', 'domains' => 'WWW.Shop.Example.test'],
        ] as $post) {
            $this->actingAs($this->storeStaff)->post('/theme-settings', $post)->assertSessionMissing('error');
        }

        $this->assertSame([], $fake->calls, 'The hosting panel was reached for a user who may not change routing.');
        $this->assertSame('WWW.Shop.Example.test', $this->stored('domains'));
    }

    /** The Store Setting form posts a store name too: the store's own columns are not settings and still save. */
    public function test_the_honest_home_post_still_renames_the_store(): void
    {
        $this->routingMode('domain');
        $this->recordingWebuzo();

        $this->actingAs($this->storeStaff)->post('/theme-settings', ['app_setting_tab' => 'pills-home-tab', 'theme_name' => 'Renamed Store'])->assertSessionMissing('error');

        $this->assertSame('Renamed Store', Store::find($this->store->id)->name);
    }

    /** Premise for the honest-post tests above: the same Home post by the OWNER on a custom-domain store really does call the panel. */
    public function test_premise_the_unrelated_tab_post_reaches_the_domain_branch_for_the_owner(): void
    {
        $this->routingMode('domain');
        $fake = $this->recordingWebuzo();

        $this->actingAs($this->merchant)->post('/theme-settings', ['app_setting_tab' => 'pills-home-tab', 'theme_name' => 'Renamed']);

        $this->assertNotSame([], $this->panelCalls($fake), 'Premise: if the unrelated-tab post never reaches the domain branch, the honest-post tests prove nothing.');
    }

    public function test_staff_holding_manage_setting_can_still_change_the_routing(): void
    {
        $this->routingMode('domain');
        $fake = $this->recordingWebuzo();

        $this->actingAs($this->settingsStaff)->post('/theme-settings', ['enable_domain' => 'enable_storelink'])->assertSessionMissing('error');

        $this->assertContains(['deleteDomain', 'shop.example.test'], $fake->calls);
        $this->assertTrue($this->everStored('enable_storelink', 'on'), 'The permitted staff member\'s change was not written.');
    }

    public function test_a_user_holding_neither_permission_cannot_reach_theme_settings_at_all(): void
    {
        $this->routingMode('domain');
        $fake = $this->recordingWebuzo();

        $this->actingAs($this->staff)->post('/theme-settings', ['enable_domain' => 'enable_domain', 'domains' => 'shop.example.test', 'store_city' => 'Nowhere'])->assertSessionHas('error');

        $this->assertSame([], $fake->calls);
        $this->assertFalse($this->everStored('store_city', 'Nowhere'));
    }

    public function test_the_held_routing_describes_each_mode(): void
    {
        $this->routingMode('domain');
        $this->assertSame(['enable_domain' => 'enable_domain', 'domains' => 'shop.example.test', 'subdomain' => ''], StoreSettingsAccess::domainRoutingHeld($this->store->id));

        $this->routingMode('subdomain');
        $this->assertSame(['enable_domain' => 'enable_subdomain', 'domains' => '', 'subdomain' => 'myshop'], StoreSettingsAccess::domainRoutingHeld($this->store->id));

        $this->routingMode('storelink');
        $this->assertSame(['enable_domain' => 'enable_storelink', 'domains' => '', 'subdomain' => ''], StoreSettingsAccess::domainRoutingHeld($this->store->id));

        DB::table('settings')->where('store_id', $this->store->id)->whereIn('name', StoreSettingsAccess::DOMAIN_ROUTING)->delete();
        $this->assertSame('enable_storelink', StoreSettingsAccess::domainRoutingHeld($this->store->id)['enable_domain'], 'A store that never saved the tab is on the store link.');
    }

    /** What the handler is fed when the post carries no token: never a "switch to ..." request. */
    public function test_a_post_with_no_token_is_fed_the_token_that_reaches_no_panel_call(): void
    {
        $this->routingMode('domain');
        $this->assertSame('enable_domain', StoreSettingsAccess::domainRoutingNeutralised(['theme_name' => 'x'], $this->store->id)['enable_domain']);

        foreach (['subdomain', 'storelink'] as $mode) {
            $this->routingMode($mode);
            $this->assertSame('enable_storelink', StoreSettingsAccess::domainRoutingNeutralised(['theme_name' => 'x'], $this->store->id)['enable_domain'], "mode {$mode}");
        }

        $this->assertNull(StoreSettingsAccess::domainRoutingNeutralised(['enable_domain' => 'enable_subdomain'], $this->store->id));
    }

    // ---------------------------------------------------------------------------------------------
    // the web twins of the gated API writers
    // ---------------------------------------------------------------------------------------------

    public function test_staff_without_the_permission_cannot_change_the_currency_or_the_loyalty_reward_on_the_web(): void
    {
        foreach ([$this->staff, $this->storeStaff] as $who) {
            $this->actingAs($who)->post('/currency-settings', ['defult_currancy' => 'USD'])->assertSessionHas('error');
            $this->actingAs($who)->post('/loyality-program-settings', ['loyality_program_enabled' => 'on', 'reward_point' => 99])->assertSessionHas('error');
        }
        $this->assertNull($this->stored('defult_currancy'));
        $this->assertNull($this->stored('reward_point'));
    }

    public function test_the_owner_and_permitted_staff_still_change_the_loyalty_reward_on_the_web(): void
    {
        $this->actingAs($this->merchant)->post('/loyality-program-settings', ['loyality_program_enabled' => 'on', 'reward_point' => 7])->assertSessionMissing('error');
        $this->assertSame('7', $this->stored('reward_point'));

        $this->actingAs($this->settingsStaff)->post('/loyality-program-settings', ['loyality_program_enabled' => 'on', 'reward_point' => 8])->assertSessionMissing('error');
        $this->assertTrue($this->everStored('reward_point', '8'));
    }

    // ---------------------------------------------------------------------------------------------
    // the sibling doors the third review found: same rows, other routes
    // ---------------------------------------------------------------------------------------------

    /** The web twin of updateCurrencyRateApi / toggleCurrencyStatusApi (route `currency-rates.update`, `auth` only). */
    public function test_a_currency_rate_is_changed_only_by_a_user_holding_manage_setting(): void
    {
        $eur = DB::table('currency_rates')->insertGetId(['store_id' => $this->store->id, 'currency_code' => 'EUR', 'currency_name' => 'Euro', 'symbol' => 'E', 'rate_per_usd' => 0.9, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $rate = fn () => (float) DB::table('currency_rates')->where('id', $eur)->value('rate_per_usd');

        foreach ([$this->staff, $this->storeStaff] as $who) {
            $this->actingAs($who)->post("/currency-rates/{$eur}", ['rate_per_usd' => 0.00000001, 'is_active' => 0])->assertSessionHas('error');
        }
        $this->assertEquals(0.9, $rate(), 'A user without Manage Setting changed a currency rate.');
        $this->assertSame(1, (int) DB::table('currency_rates')->where('id', $eur)->value('is_active'));

        $this->actingAs($this->settingsStaff)->post("/currency-rates/{$eur}", ['rate_per_usd' => 0.8, 'is_active' => 1])->assertSessionMissing('error');
        $this->assertEquals(0.8, $rate());
        $this->actingAs($this->merchant)->post("/currency-rates/{$eur}", ['rate_per_usd' => 0.7, 'is_active' => 1])->assertSessionMissing('error');
        $this->assertEquals(0.7, $rate());
    }

    /** Tabs of the Settings screen whose POST targets had no check although settingForm() refuses the tab itself. */
    public function test_the_stock_and_refund_tabs_need_manage_setting(): void
    {
        $stock = ['low_stock_threshold' => '3', 'out_of_stock_threshold' => '1', 'notification' => ['mail'], 'stock_management' => 'on'];

        foreach ([$this->staff, $this->storeStaff] as $who) {
            $this->actingAs($who)->post('/stock-settings', $stock)->assertSessionHas('error');
            $this->actingAs($who)->post('/refund-settings', ['refund_by_zz' => '1'])->assertSessionHas('error');
        }
        $this->assertNull($this->stored('low_stock_threshold'));
        $this->assertSame(0, DB::table('order_refund_settings')->where('store_id', $this->store->id)->count());

        $this->actingAs($this->settingsStaff)->post('/stock-settings', $stock)->assertSessionMissing('error');
        $this->assertTrue($this->everStored('low_stock_threshold', '3'), 'Premise: the permitted user must still save the stock tab.');
        $this->actingAs($this->merchant)->post('/refund-settings', ['refund_by_zz' => '1'])->assertSessionMissing('error');
        $this->assertSame(1, DB::table('order_refund_settings')->where('store_id', $this->store->id)->where('name', 'refund_by_zz')->count(), 'Premise: the owner must still save the refund tab.');
    }

    /** `currency_name` decides what a card is charged in (on MySQL it addresses the CURRENCY_NAME row). */
    public function test_staff_cannot_change_the_charged_currency_through_the_store_owner_info_api(): void
    {
        DB::table('settings')->insert(['name' => 'CURRENCY_NAME', 'value' => 'USD', 'created_by' => $this->merchant->id, 'store_id' => $this->store->id, 'created_at' => now(), 'updated_at' => now()]);
        $url = '/api/admin/updateStoreOwnerInfo?store_id=' . $this->store->id;
        $written = fn () => DB::table('settings')->where('store_id', $this->store->id)->whereRaw('LOWER(name) = ?', ['currency_name'])->pluck('value')->contains('JPY');

        foreach ([$this->staff, $this->storeStaff] as $who) {
            $this->postJson($url, ['currency_name' => 'JPY', 'store_name' => 'Renamed By Staff'], $this->headers($who))->assertStatus(403);
        }
        $this->assertFalse($written(), 'A user without Manage Setting changed the charged currency.');

        // a prefilled form resubmits the stored value next to a store-level change: not a change
        $response = $this->postJson($url, ['currency_name' => 'USD', 'store_name' => 'Prefilled Save'], $this->headers($this->storeStaff));
        $this->assertNotContains($response->getStatusCode(), [401, 403], $response->getContent());
        $this->assertSame('Prefilled Save', Store::find($this->store->id)->name);

        foreach ([$this->settingsStaff, $this->merchant] as $who) {
            $this->assertNotContains($this->postJson($url, ['currency_name' => 'JPY'], $this->headers($who))->getStatusCode(), [401, 403], 'A principal who may change the currency was refused.');
        }
    }

    /**
     * `custom.auth` logs a Sanctum token's owner into the guard that matches its CLASS, so a Customer's or a DeliveryBoy's token
     * passes it while `Auth::user()` stays null. A customer-token probe alone proves nothing (it would pass for the wrong reason),
     * so all three classes are driven at every gated API writer: the two non-merchant classes must write nothing.
     *
     * @dataProvider apiWriters
     */
    public function test_a_customer_or_driver_token_writes_nothing_through_the_api_writers(string $verb, string $uri, array $data): void
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
        $before = DB::table('settings')->where('store_id', $this->store->id)->count();

        foreach ([$customer, $driver] as $principal) {
            $response = $this->callApi($principal, $verb, $uri, $data);

            // ApiResponser::error() answers HTTP 200 with {status: 0} by default (CLAUDE.md), so a refusal is "HTTP >= 400 OR status 0".
            $this->assertTrue($response->getStatusCode() >= 400 || (int) $response->json('status') === 0,
                "{$uri}: a " . class_basename($principal) . ' token was served: ' . substr($response->getContent(), 0, 160));
        }
        $this->assertSame($before, DB::table('settings')->where('store_id', $this->store->id)->count(), "{$uri}: a non-merchant token wrote a setting.");
        $this->assertSame('ORIGINAL-BANK-ACCOUNT', $this->stored('bank_transfer'));
    }

    // ---------------------------------------------------------------------------------------------
    // the admin API's ungated writers
    // ---------------------------------------------------------------------------------------------

    private function headers($u): array
    {
        return ['Authorization' => 'Bearer ' . $u->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }

    /** @return array<string, array{0: string, 1: string, 2: array}> */
    public static function apiWriters(): array
    {
        return [
            'loyalty' => ['post', '/api/admin/loyalitysetting', ['loyality_program_enabled' => 'on', 'reward_point' => 5]],
            'cod' => ['post', '/api/admin/cod-payment', ['CURRENCY_NAME' => 'USD', 'CURRENCY' => '$', 'is_cod_enabled' => 'off']],
            'bank' => ['post', '/api/admin/bank-payment', ['CURRENCY_NAME' => 'USD', 'CURRENCY' => '$', 'is_bank_transfer_enabled' => 'on', 'bank_transfer' => 'ATTACKER-ACCOUNT']],
            'currency rate' => ['put', '/api/admin/update-currency-rate', ['currency_code' => 'EUR', 'rate_per_usd' => 0.5]],
            'currency toggle' => ['put', '/api/admin/toggle-currency-status', ['currency_code' => 'EUR', 'is_active' => 0]],
            'default currency' => ['post', '/api/admin/updateCurrencyApi', ['defult_currancy' => 'EUR', 'defult_currancy_symbol' => '€']],
        ];
    }

    private function callApi($as, string $verb, string $uri, array $data)
    {
        $data += ['store_id' => $this->store->id];

        return $verb === 'put'
            ? $this->putJson($uri, $data, $this->headers($as))
            : $this->postJson($uri, $data, $this->headers($as));
    }

    /** @dataProvider apiWriters */
    public function test_staff_without_the_permission_are_refused_by_the_api_writers(string $verb, string $uri, array $data): void
    {
        foreach ([$this->staff, $this->storeStaff] as $who) {
            $response = $this->callApi($who, $verb, $uri, $data);

            $this->assertSame(403, $response->getStatusCode(), "{$uri}: " . substr($response->getContent(), 0, 200));
        }
        $this->assertSame('ORIGINAL-BANK-ACCOUNT', $this->stored('bank_transfer'));
    }

    /** @dataProvider apiWriters */
    public function test_the_owner_and_the_operator_pass_the_api_gate(string $verb, string $uri, array $data): void
    {
        foreach ([$this->merchant, $this->operator] as $principal) {
            $status = $this->callApi($principal, $verb, $uri, $data)->getStatusCode();

            $this->assertNotContains($status, [401, 403], "{$uri} refused a principal who must be admitted.");
        }
    }

    /** @dataProvider apiWriters */
    public function test_staff_holding_the_permission_pass_the_api_gate(string $verb, string $uri, array $data): void
    {
        $this->assertNotContains($this->callApi($this->settingsStaff, $verb, $uri, $data)->getStatusCode(), [401, 403], "{$uri} refused a staff member who holds Manage Setting.");
    }
}
