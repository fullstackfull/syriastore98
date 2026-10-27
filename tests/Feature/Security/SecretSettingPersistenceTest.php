<?php

namespace Tests\Feature\Security;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * F117 — a credential survives a save that does not touch its box, and never reaches the page.
 *
 * THIS IS THE TEST THAT HAD TO EXIST BEFORE THE DISPLAY WAS MASKED. These panels write EVERY
 * field on EVERY save: `SettingController::TwilioSettings` did
 * `$post['twilio_token'] = $request->twilio_token;` unconditionally. Empty the input and the next
 * save of an unrelated field on the same panel writes an empty string over a live auth token —
 * the integration stops working and nothing on screen explains why. Masking without this is a
 * data-loss bug wearing a security fix's clothes.
 *
 * NO REAL CREDENTIAL APPEARS HERE. The fixtures are obvious inventions, and nothing interpolates
 * a stored value into an assertion message, so a failure cannot print one into CI output.
 */
class SecretSettingPersistenceTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const INVENTED_SID = 'AC-INVENTED-SID-not-real';
    private const INVENTED_TOKEN = 'INVENTED-TOKEN-not-real';

    /**
     * A REAL STORE, so `getCurrentStore()` resolves the way it does in production.
     *
     * The first version of this fixture just set `current_store => 1` without creating the row,
     * and `getCurrentStore()` returned NULL — so the handler wrote its settings under a NULL
     * store while the assertions read store 1. The secrets "survived" because nothing had been
     * written at all. The positive control on `twilio_from` is what exposed that; without it the
     * test would have reported a pass over a handler that did nothing.
     */
    private function merchantWithTwilioSecrets(): \App\Models\User
    {
        $merchant = $this->makeMerchant();
        $this->grantPermission($merchant, 'Manage Store Setting');

        $store = \App\Models\Store::create([
            'name' => 'Secrets Store', 'email' => 'secrets@example.test',
            'slug' => 'secrets-store-' . uniqid(), 'theme_id' => 'stylique',
            'created_by' => $merchant->id, 'is_active' => 1, 'default_language' => 'en',
        ]);

        $merchant->current_store = $store->id;
        $merchant->save();

        $this->storeId = $store->id;

        foreach ([
            'twilio_sid' => self::INVENTED_SID,
            'twilio_token' => self::INVENTED_TOKEN,
            'twilio_from' => '15550000000',
            'twilio_notification_number' => '15551111111',
        ] as $name => $value) {
            Setting::forceCreate([
                'name' => $name,
                'value' => $value,
                'store_id' => $store->id,
                'created_by' => $merchant->id,
            ]);
        }

        return $merchant;
    }

    private int $storeId = 0;

    /** Read through `withoutGlobalScopes()`: a plain query answers against whichever tenant
        happens to be resolved, not against the table. */
    private function stored(string $name): ?string
    {
        $row = Setting::withoutGlobalScopes()
            ->where('store_id', $this->storeId)
            ->where('name', $name)
            ->first();

        return $row ? (string) $row->value : null;
    }

    /**
     * THE CENTRAL CASE: change the sender number, leave both secret boxes empty — which is what
     * the masked inputs now post — and both credentials must survive.
     */
    public function test_a_save_with_empty_secret_boxes_keeps_the_stored_credentials(): void
    {
        $merchant = $this->merchantWithTwilioSecrets();

        $this->actingAs($merchant)->post('/twilio-settings', [
            'twilio_setting_enabled' => 'on',
            'twilio_sid' => '',
            'twilio_token' => '',
            'twilio_from' => '15559999999',
            'twilio_notification_number' => '15551111111',
        ]);

        $this->assertSame(self::INVENTED_SID, $this->stored('twilio_sid'),
            'the SID was erased by a save that did not touch its box');
        $this->assertSame(self::INVENTED_TOKEN, $this->stored('twilio_token'),
            'the auth token was erased by a save that did not touch its box');

        /* The positive control: the field the merchant DID change must have changed, or this
           test would pass against a handler that saves nothing at all. */
        $this->assertSame('15559999999', $this->stored('twilio_from'));
    }

    /** Typing a new value must still replace the old one. */
    public function test_a_typed_secret_replaces_the_stored_one(): void
    {
        $merchant = $this->merchantWithTwilioSecrets();

        $this->actingAs($merchant)->post('/twilio-settings', [
            'twilio_setting_enabled' => 'on',
            'twilio_sid' => 'AC-INVENTED-REPLACEMENT',
            'twilio_token' => '',
            'twilio_from' => '15550000000',
            'twilio_notification_number' => '15551111111',
        ]);

        $this->assertSame('AC-INVENTED-REPLACEMENT', $this->stored('twilio_sid'));
        $this->assertSame(self::INVENTED_TOKEN, $this->stored('twilio_token'),
            'the untouched token must not have been replaced alongside the one that was typed');
    }

    /**
     * CLEARING IS STILL POSSIBLE, and still deliberate. "Blank keeps" removes the obvious way to
     * empty a credential, so the panel's own disable branch must still blank them — otherwise
     * this fix would trap a merchant with a credential they cannot remove.
     */
    public function test_disabling_the_integration_still_clears_the_credentials(): void
    {
        $merchant = $this->merchantWithTwilioSecrets();

        $this->actingAs($merchant)->post('/twilio-settings', [
            'twilio_setting_enabled' => 'off',
            'twilio_sid' => '',
            'twilio_token' => '',
            'twilio_from' => '',
            'twilio_notification_number' => '',
        ]);

        $this->assertSame('', $this->stored('twilio_sid'));
        $this->assertSame('', $this->stored('twilio_token'));
    }

    /**
     * THE DISPLAY HALF, driven: the stored credential must not appear in what the page emits.
     *
     * Rendered from the partial rather than the whole settings page, because the partial is
     * exactly what changed and the page's tab machinery would add failure modes unrelated to the
     * property under test.
     */
    public function test_the_panel_never_renders_the_stored_credentials(): void
    {
        $merchant = $this->merchantWithTwilioSecrets();
        $this->actingAs($merchant);

        $html = (string) view('setting.twilio_setting', [
            'setting' => [
                'twilio_sid' => self::INVENTED_SID,
                'twilio_token' => self::INVENTED_TOKEN,
                'twilio_from' => '15550000000',
                'twilio_notification_number' => '15551111111',
                'twilio_setting_enabled' => 'on',
            ],
        ])->render();

        $this->assertStringNotContainsString(self::INVENTED_SID, $html,
            'the Twilio SID is still being written into the page');
        $this->assertStringNotContainsString(self::INVENTED_TOKEN, $html,
            'the Twilio auth token is still being written into the page');

        /* PREMISE: the panel really rendered the fields. Without this, a view that threw or
           returned nothing would satisfy both assertions above. */
        $this->assertStringContainsString('name="twilio_sid"', $html);
        $this->assertStringContainsString('name="twilio_token"', $html);

        /* The boxes must be password inputs, and must say a value is saved — otherwise a
           merchant sees two empty boxes and reasonably concludes nothing is configured. */
        $this->assertStringContainsString('type="password"', $html);
        $this->assertStringContainsString('A value is saved', $html);

        /* And a non-secret field on the same panel must still show its value, or this test
           would also pass against a panel that renders nothing useful at all. */
        $this->assertStringContainsString('15550000000', $html);
    }

    /**
     * PERMISSIONS: the writer refuses a principal the settings rule does not admit, and the
     * credentials are untouched by the attempt.
     */
    public function test_a_user_without_settings_access_cannot_save_or_erase(): void
    {
        $owner = $this->merchantWithTwilioSecrets();
        $outsider = $this->makeStaff($owner);

        $this->actingAs($outsider)->post('/twilio-settings', [
            'twilio_setting_enabled' => 'on',
            'twilio_sid' => 'AC-INVENTED-ATTACKER',
            'twilio_token' => 'INVENTED-ATTACKER',
            'twilio_from' => '15558888888',
            'twilio_notification_number' => '15551111111',
        ]);

        $this->assertSame(self::INVENTED_SID, $this->stored('twilio_sid'));
        $this->assertSame(self::INVENTED_TOKEN, $this->stored('twilio_token'));
        $this->assertSame('15550000000', $this->stored('twilio_from'),
            'the refusal must leave the whole panel alone, not just the secrets');
    }

    /**
     * THE OTHER CONVERTED PANELS, driven the same way: a save that does not touch the secret box
     * must keep the credential, and must still write the non-secret field beside it.
     *
     * Each is a separate route with its own validator, and the validator is the half that bites:
     * these panels mark their secret `required` while the integration is enabled, so masking the
     * input made them IMPOSSIBLE TO SAVE until the rule became "required only when nothing is
     * stored". The `assertSame` on the non-secret field is what proves the save actually
     * happened — without it, a refused request would look exactly like a preserved credential.
     *
     * @dataProvider convertedPanels
     */
    public function test_each_converted_panel_keeps_its_credential_on_a_blank_save(
        string $route,
        string $secretName,
        string $storageName,
        array $payload,
        string $witnessField,
        string $witnessValue
    ): void {
        $merchant = $this->makeMerchant();
        $this->grantPermission($merchant, 'Manage Store Setting');

        $store = \App\Models\Store::create([
            'name' => 'Panel Store', 'email' => 'panel@example.test',
            'slug' => 'panel-store-' . uniqid(), 'theme_id' => 'stylique',
            'created_by' => $merchant->id, 'is_active' => 1, 'default_language' => 'en',
        ]);
        $merchant->current_store = $store->id;
        $merchant->save();
        $this->storeId = $store->id;

        Setting::forceCreate([
            'name' => $storageName, 'value' => 'INVENTED-' . $storageName,
            'store_id' => $store->id, 'created_by' => $merchant->id,
        ]);

        $this->actingAs($merchant)->post($route, array_merge($payload, [$secretName => '']));

        $this->assertSame('INVENTED-' . $storageName, $this->stored($storageName),
            $storageName . ' was erased by a save that did not touch its box');

        $this->assertSame($witnessValue, $this->stored($witnessField),
            'the save did not happen at all, so the preserved credential proves nothing — the '
            . 'panel is probably refusing on its own validator');
    }

    /** @return array<string, array{0:string,1:string,2:string,3:array,4:string,5:string}> */
    public static function convertedPanels(): array
    {
        return [
            /* `shopify_store_url` is a DNS LABEL, not a URL — P1-SEC-06 restricts it to the shop
               name only, because a value like `169.254.169.254#` would end the host and comment
               out the `.myshopify.com` suffix in the thirteen URLs built from it. The first
               version of this case posted a full URL under the wrong field name and the panel
               refused; the witness assertion is what reported that rather than a false pass. */
            'shopify' => [
                '/shopify-settings', 'shopify_access_token', 'shopify_access_token',
                ['shopify_setting_enabled' => 'on', 'shopify_store_url' => 'invented-shop'],
                'shopify_store_url', 'invented-shop',
            ],
            'woocommerce' => [
                '/woocommerce-settings', 'woocommerce_consumer_secret', 'woocommerce_consumer_secret',
                ['woocommerce_setting_enabled' => 'on', 'woocommerce_store_url' => 'https://invented2.example.test', 'woocommerce_consumer_key' => 'INVENTED-KEY'],
                'woocommerce_store_url', 'https://invented2.example.test',
            ],

            /* ADDED [2026-10-27] AFTER AN INDEPENDENT REVIEW FOUND THESE THREE PANELS UNSAVEABLE.
               Their fields were masked and their saves were converted, but their validators were
               left at a flat `required`, so an ordinary save of the panel failed with "The mail
               password field is required" and `keepStoredSecret()` below was never reached. It
               fails CLOSED — nothing is erased — but the panel cannot be saved without retyping
               the secret, and nothing in the suite drove either route, so the whole thing shipped
               past a green run. The provider covering only two of the five converted panels is
               the actual defect here; these three close it.

               NOTE the field/storage split for mail: the input posts `mail_password`, the row is
               `MAIL_PASSWORD`. */
            'email (SMTP)' => [
                '/email-settings', 'mail_password', 'MAIL_PASSWORD',
                [
                    'email_setting' => 'SMTP', 'mail_driver' => 'smtp', 'mail_host' => 'smtp.invented.test',
                    'mail_port' => '587', 'mail_username' => 'mailer', 'mail_encryption' => 'tls',
                    'mail_from_address' => 'shop@invented.test', 'mail_from_name' => 'Invented',
                ],
                'MAIL_HOST', 'smtp.invented.test',
            ],
            'storage (S3)' => [
                '/storage-settings', 's3_secret', 's3_secret',
                [
                    'storage_setting' => 's3', 's3_key' => 'INVENTED-KEY', 's3_region' => 'eu-west-1',
                    's3_bucket' => 'invented-bucket', 's3_url' => 'https://s3.invented.test',
                    's3_endpoint' => 'https://s3.invented.test', 's3_max_upload_size' => '2048',
                    's3_storage_validation' => ['jpg', 'png'],
                ],
                's3_bucket', 'invented-bucket',
            ],
            'storage (Wasabi)' => [
                '/storage-settings', 'wasabi_secret', 'wasabi_secret',
                [
                    'storage_setting' => 'wasabi', 'wasabi_key' => 'INVENTED-KEY', 'wasabi_region' => 'eu-central-1',
                    'wasabi_bucket' => 'invented-bucket-w', 'wasabi_url' => 'https://wasabi.invented.test',
                    'wasabi_root' => 'root', 'wasabi_max_upload_size' => '2048',
                    'wasabi_storage_validation' => ['jpg', 'png'],
                ],
                'wasabi_bucket', 'invented-bucket-w',
            ],
        ];
    }
}
