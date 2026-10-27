<?php

namespace Tests\Feature\Security;

use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

/**
 * F117's own blast radius — the handlers that now SUBSTITUTE a stored credential.
 * =============================================================================================
 *
 * Masking a field is not only a display change. Every handler that falls back to the stored
 * value is, from that moment, a handler that WILL USE A SECRET THE CALLER DOES NOT KNOW. On a
 * save path that is harmless: the value goes back where it came from. On a SEND path it is not,
 * because the caller chooses where it goes.
 *
 * `testSendMail` takes the whole request into `SetConfigEmail()`, which sets `mail.host`,
 * `mail.username` and `mail.password` from it. Its route carries `auth, xss, setlocate,
 * verified` and NO permission check. Before F117 that was survivable: a staff member without
 * `Manage Setting` could not learn the SMTP password (the settings tab 403s them, P1-SEC-18 F5)
 * and the handler made them supply one. After F117 the server supplies it — so an ungated
 * test-send would let that staff member point `mail_host` at a server they control and have
 * the application authenticate to it with the merchant's saved password.
 *
 * `testSendwhatsappmassage` is the same shape with a smaller loss: the Bearer token goes to the
 * literal `graph.facebook.com` and cannot be redirected, so what leaks is use of the merchant's
 * WhatsApp account, not the token.
 *
 * Both now go through `StoreSettingsAccess::permits()` — the same decision point that guards the
 * tab the credential came from, so the two doors cannot drift apart again.
 *
 * THE ASSERTION IS ON `config('mail.password')`, not on a delivered email. What the SMTP
 * handshake then does is the attacker's business; the defect is that the application loaded the
 * merchant's secret into a transport aimed at a caller-chosen host. Reading the resolved config
 * proves that regardless of transport.
 *
 * NO REAL CREDENTIAL APPEARS HERE.
 */
class SecretSubstitutionGatesTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const SMTP_SECRET = 'FIXTURE-SMTP-PASSWORD-f117';
    private const WA_TOKEN = 'FIXTURE-WHATSAPP-TOKEN-f117';

    private User $merchant;
    private User $staff;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();
        $this->merchant = $this->merchant->fresh();

        $this->staff = $this->makeStaff($this->merchant);

        foreach ([
            'MAIL_DRIVER' => 'smtp', 'MAIL_HOST' => 'smtp.example.test', 'MAIL_PORT' => '587',
            'MAIL_USERNAME' => 'mailer', 'MAIL_PASSWORD' => self::SMTP_SECRET, 'MAIL_ENCRYPTION' => 'tls',
            'MAIL_FROM_NAME' => 'Shop', 'MAIL_FROM_ADDRESS' => 'shop@example.test',
            'whatsapp_access_token' => self::WA_TOKEN, 'whatsapp_phone_number_id' => '1234567890',
        ] as $name => $value) {
            Setting::forceCreate([
                'name' => $name, 'value' => $value,
                'store_id' => $this->store->id, 'created_by' => $this->merchant->id,
            ]);
        }
    }

    /** The attacker's payload: everything the validator wants, and the password box left empty. */
    private function sendMailForm(string $host = 'smtp.attacker.example'): array
    {
        return [
            'mail_driver' => 'smtp', 'mail_host' => $host, 'mail_port' => '587',
            'mail_username' => 'anything', 'mail_encryption' => 'tls',
            'mail_from_address' => 'x@example.test', 'mail_from_name' => 'X',
            'email' => 'x@example.test',
            // mail_password deliberately absent
        ];
    }

    /* ------------------------------------------------------------------------ test-send mail */

    public function test_staff_without_the_permission_cannot_make_the_server_use_the_stored_smtp_password(): void
    {
        config(['mail.password' => 'UNTOUCHED']);

        $response = $this->actingAs($this->staff)->post(route('test.send.mail'), $this->sendMailForm());

        $this->assertFalse((bool) $response->json('is_success'),
            'the test-send was accepted from a staff member without `Manage Setting`');

        $this->assertNotSame(self::SMTP_SECRET, config('mail.password'),
            "the server loaded the merchant's stored SMTP password into a transport aimed at a "
            . 'host the caller chose');
        $this->assertNotSame('smtp.attacker.example', config('mail.host'),
            'the caller-chosen host reached the mail config');
    }

    /**
     * THE OTHER DIRECTION, and the reason the gate is `permits()` rather than a blanket refusal:
     * the owner must still be able to press "send a test mail" without retyping the password.
     * Without this, the fix above would be indistinguishable from breaking the button.
     */
    public function test_the_owner_can_still_test_send_with_the_box_left_blank(): void
    {
        config(['mail.password' => 'UNTOUCHED']);

        $this->actingAs($this->merchant)->post(route('test.send.mail'), $this->sendMailForm('smtp.example.test'));

        $this->assertSame(self::SMTP_SECRET, config('mail.password'),
            'the owner\'s blank box did not resolve to the stored password, so the test button '
            . 'can no longer test the saved credential');
    }

    /* -------------------------------------------------------------------- test-send whatsapp */

    public function test_staff_without_the_permission_cannot_send_on_the_stores_whatsapp_account(): void
    {
        Http::fake();

        $response = $this->actingAs($this->staff)->post(route('whatsapp.send.massage'), [
            'mobile' => '15550000000', 'whatsapp_phone_number_id' => '1234567890',
        ]);

        $this->assertFalse((bool) $response->json('is_success'),
            'a staff member without `Manage Setting` was allowed to send on the store account');

        Http::assertNothingSent();
    }

    /* ------------------------------------------------------------- the two modal entry points */

    public function test_staff_without_the_permission_are_refused_both_test_modals(): void
    {
        $this->assertSame(403, $this->actingAs($this->staff)->post(route('email.test'), [])->getStatusCode(),
            'the test-mail modal is the door to the send handler and hands over the settings array');

        $this->assertSame(403, $this->actingAs($this->staff)->post(route('whatsappmassage.test'), [])->getStatusCode());
    }

    public function test_the_owner_still_gets_both_test_modals(): void
    {
        $this->actingAs($this->merchant)->post(route('email.test'), [])->assertOk();
        $this->actingAs($this->merchant)->post(route('whatsappmassage.test'), [])->assertOk();
    }
}
