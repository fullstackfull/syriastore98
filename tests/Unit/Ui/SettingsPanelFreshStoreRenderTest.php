<?php

namespace Tests\Unit\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * EVERY SETTINGS PANEL MUST RENDER ON A STORE THAT HAS NEVER SAVED IT.
 * =================================================================================================
 *
 * `$setting` on these panels is `getAdminAllSetting()` — a key/value map of the rows the store has
 * actually saved. A brand-new store has saved nothing, so a panel's first-ever render sees an
 * EMPTY array, and every `$setting['x']` read on it is absent. That is the normal first-run state,
 * not an edge case: it is what a merchant sees the first time they open the tab.
 *
 * WHAT THIS CAUGHT. `storage_setting` was a hard fatal in exactly that state:
 *
 *     explode(',', isset($setting['local_storage_validation'])
 *         ? $setting['local_storage_validation'] : [])
 *
 * The else branch hands `explode()` an ARRAY, which under PHP 8 is
 * `TypeError: explode(): Argument #2 ($string) must be of type string, array given` — fatal, not a
 * notice. The AJAX tab handler catches it and answers "something went wrong", so the whole Storage
 * tab was unreachable for any store that had never saved it. The two sibling blocks pass `null`
 * instead, which PHP 8.3 only deprecates, so only the local block was fatal and only on a fresh
 * store. That combination is why nobody hit it on a seeded install.
 *
 * WHY A RENDER TEST AND NOT A LOOK AT THE SOURCE. `BladeViewsCompileTest` passes on this file —
 * the compiled PHP parses fine. The type error happens at RUN time, inside a `@foreach` over
 * `config('files_types')`, so it needs the view actually rendered with real config. Nothing short
 * of rendering sees it.
 *
 * TWO PREMISES THIS TEST HAS TO SUPPLY ITSELF, and getting them wrong is how it would report a
 * defect that is only its own omission:
 *
 *   - `$errors`. Shared into every view by `ShareErrorsFromSession`, which runs in the `web`
 *     middleware group and therefore not here. `brand_setting` and `style_setting` read it through
 *     `@error`, so without it they fail with `Undefined variable $errors` — a test artefact, not a
 *     product defect. An empty `ViewErrorBag` is shared below, which is exactly what a GET request
 *     with no validation failure carries.
 *   - AN AUTHENTICATED MERCHANT. `brand_setting`, `stock_setting` and `system_setting` branch on
 *     `auth()->user()->type`, so with no one logged in they fail with
 *     `Attempt to read property "type" on null`. Again a test artefact: the route is behind
 *     `StoreSettingsAccess::permits()`, so a real render always has a user. `type => 'admin'`
 *     is the merchant tenant owner, which is the principal this phase is about.
 *   - A MIGRATED DATABASE. `refund_setting` and `system_setting` query from inside the view
 *     (`order_refund_settings`, and `Utility::getValByName()` on `settings`). Without migrations
 *     they fail with `no such table`, which again says nothing about the panel. `RefreshDatabase`
 *     gives them empty tables — the fresh-store state this test is about.
 *
 * SCOPE, STATED HONESTLY. The panels below are the ones whose view data the controller passes and
 * this test can reconstruct: `$setting` plus a config array, an empty collection, or null for an
 * absent record. `payment_setting` and `pwa_setting` need a gateway array and filesystem state and
 * are NOT covered here; they are exercised by the live browser harness instead. A panel missing
 * from this list is untested by it, not proven safe.
 */
class SettingsPanelFreshStoreRenderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Panel view => the extra view data `SettingController::settingForm()` passes with it, with
     * `$setting` itself supplied by the test as the empty fresh-store map.
     *
     * The extras are deliberately EMPTY rather than populated: an empty collection is what a fresh
     * store really has, and a panel that only renders when its collection is non-empty is the same
     * class of defect as the one this test was written for.
     */
    public static function panels(): array
    {
        return [
            'brand'            => ['setting.brand_setting', ['plan' => null, 'timezones' => []]],
            'cache'            => ['setting.cache_setting', ['plan' => null, 'timezones' => []]],
            'chatgpt'          => ['setting.chatgpt_setting', ['ai_key_settings' => []]],
            'cookie'           => ['setting.cookie_setting', ['plan' => null, 'timezones' => []]],
            'email'            => ['setting.email_setting', ['get_setting' => 'smtp', 'email_setting' => []]],
            'email_notify'     => ['setting.email_notify_setting', ['emailTemplates' => []]],
            'loyality_pro'     => ['setting.loyality_pro_setting', ['plan' => null, 'timezones' => []]],
            'otp'              => ['setting.otp_setting', ['otpIntegration' => null]],
            'pixel_field'      => ['setting.pixel_field_setting', ['PixelFields' => []]],
            'recaptcha'        => ['setting.recaptcha_setting', ['plan' => null, 'timezones' => []]],
            'refund'           => ['setting.refund_setting', ['plan' => null, 'timezones' => []]],
            'seo'              => ['setting.seo_setting', ['plan' => null, 'timezones' => []]],
            'shopify'          => ['setting.shopify_setting', ['plan' => null, 'timezones' => []]],
            'stock'            => ['setting.stock_setting', ['plan' => null, 'timezones' => []]],
            'storage'          => ['setting.storage_setting', ['file_type' => null]], // null => config('files_types')
            'style'            => ['setting.style_setting', ['plan' => null, 'timezones' => []]],
            'system'           => ['setting.system_setting', ['plan' => null, 'timezones' => []]],
            'twilio'           => ['setting.twilio_setting', ['plan' => null, 'timezones' => []]],
            'webhook'          => ['setting.webhook_setting', ['webhooks' => []]],
            'whatsapp'         => ['setting.whatsapp_setting', ['plan' => null, 'timezones' => []]],
            'whatsapp_msg'     => ['setting.whatsapp_msg_setting', ['plan' => null, 'timezones' => []]],
            'whatsapp_notify'  => ['setting.whatsapp_notify_setting', ['WhatsappNotification' => []]],
            'woocom'           => ['setting.woocom_setting', ['plan' => null, 'timezones' => []]],
        ];
    }

    /**
     * @dataProvider panels
     */
    public function test_the_panel_renders_with_no_saved_settings(string $view, array $extra): void
    {
        if (array_key_exists('file_type', $extra) && $extra['file_type'] === null) {
            // The real value the controller passes. 92 entries, so the `@foreach` really iterates
            // and really reaches the `explode()` this test was written for.
            $extra['file_type'] = config('files_types');
            $this->assertNotEmpty($extra['file_type'], 'config(files_types) must be non-empty or the loop never runs');
        }

        $this->actingAs(User::factory()->create([
            'type' => 'admin',
            'created_by' => 1,
            'is_active' => 1,
        ]));

        view()->share('errors', new ViewErrorBag());

        $html = view($view, array_merge(['setting' => []], $extra))->render();

        $this->assertNotSame('', trim($html), $view . ' rendered nothing');
    }
}
