<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * P1-SEC-30 -- SEVEN HANDLERS THAT WRITE FIXED-NAME CREDENTIALS ASKED NOBODY'S PERMISSION.
 * =========================================================================================
 *
 * P1-SEC-27 closed the by-name settings writers with a name classifier. These handlers write FIXED names (so the classifier never applies)
 * and none of them opened with the permission of the screen it belongs to: any logged-in user of the store -- a cashier, a delivery
 * clerk -- reaches them by URL whatever role the merchant gave them (the menu hides the tab; the route does not):
 *
 *   POST storage-settings        s3_ and wasabi_ keys AND ENDPOINT -- uploads go to whatever bucket is named
 *   POST woocommerce-settings    the store URL + API keys the importers call
 *   POST shopify-settings        likewise
 *   POST twilio-settings         SID, auth token, sender and notification number
 *   POST whatsapp-settings       the number shoppers' messages are sent from
 *   POST whatsapp-notification   the Cloud API phone id + access token
 *   POST firebase-settings       the FCM server key
 *
 * ORACLE: the `settings` rows, raw. A refusal must leave NO row of the names the handler writes; the owner and a user holding the screen's
 * permission must still save (positive control), and the platform operator is admitted (operator first: Laratrust has no super-admin bypass).
 */
class FixedNameCredentialWritersTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: array<string, mixed>, 2: list<string>}> uri, a VALID body, the setting names it writes */
    public static function writers(): array
    {
        return [
            'storage' => ['/storage-settings', ['storage_setting' => 'local', 'local_storage_validation' => ['jpg'], 'local_storage_max_upload_size' => 2048],
                ['storage_setting', 'local_storage_validation', 'local_storage_max_upload_size']],
            'woocommerce' => ['/woocommerce-settings', ['woocommerce_setting_enabled' => 'on', 'woocommerce_store_url' => 'https://shop.example.test',
                'woocommerce_consumer_key' => 'ck_FIXTURE', 'woocommerce_consumer_secret' => 'cs_FIXTURE'],
                ['woocommerce_setting_enabled', 'woocommerce_store_url', 'woocommerce_consumer_key', 'woocommerce_consumer_secret']],
            'shopify' => ['/shopify-settings', ['shopify_setting_enabled' => 'on', 'shopify_store_url' => 'myshop', 'shopify_access_token' => 'shpat_FIXTURE'],
                ['shopify_setting_enabled', 'shopify_store_url', 'shopify_access_token']],
            'twilio' => ['/twilio-settings', ['twilio_setting_enabled' => 'on', 'twilio_sid' => 'AC_FIXTURE', 'twilio_token' => 'FIXTURE-TOKEN', 'twilio_from' => '15550001111',
                'twilio_notification_number' => '15550002222'], ['twilio_setting_enabled', 'twilio_sid', 'twilio_token', 'twilio_from', 'twilio_notification_number']],
            'whatsapp' => ['/whatsapp-settings', ['whatsapp_setting_enabled' => 'on', 'whatsapp_contact_number' => '+15550003333'],
                ['whatsapp_setting_enabled', 'whatsapp_contact_number']],
            'whatsapp notification' => ['/whatsapp-notification', ['whatsapp_phone_number_id' => '12345', 'whatsapp_access_token' => 'FIXTURE-WA-TOKEN'],
                ['whatsapp_phone_number_id', 'whatsapp_access_token']],
            'firebase' => ['/firebase-settings', ['firebase_enabled' => 'on', 'fcm_Key' => 'FIXTURE-FCM-KEY', 'app_setting_tab' => 'firebase'],
                ['firebase_enabled', 'fcm_Key']],
        ];
    }

    private function written(int $storeId, array $names): int
    {
        return DB::table('settings')->where('store_id', $storeId)->whereIn('name', $names)->count();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('writers')]
    public function test_a_staff_member_without_a_settings_permission_cannot_write_the_credentials(string $uri, array $body, array $names): void
    {
        [$merchant, $store] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);

        $response = $this->actingAs($staff->fresh())->post($uri, $body);

        $this->assertSame(0, $this->written($store->id, $names), "{$uri}: a user with no settings permission wrote " . json_encode($names));
        $response->assertSessionHas('error');
        $this->assertNotSame(401, $response->status(), 'a refusal is a redirect/403, never a 401');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('writers')]
    public function test_the_store_owner_still_saves(string $uri, array $body, array $names): void
    {
        [$merchant, $store] = $this->makeTenant();

        $this->actingAs($merchant->fresh())->post($uri, $body);

        $this->assertGreaterThan(0, $this->written($store->id, $names), "{$uri}: the owner could not save (Premise for the refusals above).");
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('writers')]
    public function test_a_staff_member_holding_the_settings_permission_still_saves(string $uri, array $body, array $names): void
    {
        [$merchant, $store] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);
        $this->grantPermission($staff, 'Manage Setting');

        $this->actingAs($staff->fresh())->post($uri, $body);

        $this->assertGreaterThan(0, $this->written($store->id, $names), "{$uri}: a user holding Manage Setting could not save.");
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('writers')]
    public function test_the_platform_operator_is_admitted(string $uri, array $body, array $names): void
    {
        [, $store] = $this->makeTenant();
        $operator = $this->makePlatformAdmin(['current_store' => $store->id]);
        $this->assertFalse($operator->isAbleTo('Manage Setting'), 'Premise: the operator holds no merchant permission.');

        $this->actingAs($operator)->post($uri, $body);

        $this->assertGreaterThan(0, $this->written($store->id, $names), "{$uri}: the operator was refused.");
    }
}
