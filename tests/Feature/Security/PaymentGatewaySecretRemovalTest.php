<?php

namespace Tests\Feature\Security;

use App\Models\Setting;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

/**
 * F141 — removing ONE stored gateway credential, deliberately, and nothing else.
 * =================================================================================================
 *
 * THE GAP THIS CLOSES was created by F117's own fix and found by review rather than by a test.
 * Masking the 58 gateway secrets made "blank means keep" the save semantics, and unlike every
 * other converted panel the payment screen has no second path out: `processGateway()` leaves
 * stored rows alone when a gateway is switched off, deliberately, so that a merchant can toggle
 * a gateway and keep its keys. The result was that a credential could be ROTATED but never
 * REMOVED — including one the merchant knows has leaked.
 *
 * THE TWO BEHAVIOURS THAT MUST NOT CHANGE, and they are asserted here rather than assumed,
 * because the obvious way to add removal is to break one of them:
 *
 *   - switching a gateway OFF must still keep its credentials;
 *   - an EMPTY BOX on a normal save must still mean "keep".
 *
 * Removal is a separate, explicit act or it is a trap.
 *
 * NO REAL CREDENTIAL APPEARS. Every fixture value is an obvious invention, and one test asserts
 * that no handler writes one into the log — the one place a security fix most often leaks the
 * thing it was protecting.
 */
class PaymentGatewaySecretRemovalTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const STRIPE_SECRET = 'sk_INVENTED_stripe_not_real';
    private const PAYPAL_SECRET = 'INVENTED-PAYPAL-SECRET-not-real';
    private const PUBLISHABLE = 'pk_INVENTED_publishable';

    private int $storeId = 0;

    private function merchant(bool $stripeEnabled = false): \App\Models\User
    {
        $merchant = $this->makeMerchant();
        $this->grantPermission($merchant, 'Manage Store Setting');

        $store = Store::create([
            'name' => 'Removal Store', 'email' => 'removal@example.test',
            'slug' => 'removal-store-' . uniqid(), 'theme_id' => 'stylique',
            'created_by' => $merchant->id, 'is_active' => 1, 'default_language' => 'en',
        ]);

        $merchant->current_store = $store->id;
        $merchant->save();
        $this->storeId = $store->id;

        foreach ([
            'is_stripe_enabled' => $stripeEnabled ? 'on' : 'off',
            'stripe_publishable_key' => self::PUBLISHABLE,
            'stripe_secret_key' => self::STRIPE_SECRET,
            'stripe_unfo' => 'Pay by card',
            'is_paypal_enabled' => 'off',
            'paypal_client_id' => 'INVENTED-CLIENT-ID',
            'paypal_secret_key' => self::PAYPAL_SECRET,
        ] as $name => $value) {
            Setting::forceCreate([
                'name' => $name, 'value' => $value,
                'store_id' => $store->id, 'created_by' => $merchant->id,
            ]);
        }

        return \App\Models\User::find($merchant->id);
    }

    private function stored(string $name): ?string
    {
        $row = Setting::withoutGlobalScopes()
            ->where('store_id', $this->storeId)->where('name', $name)->first();

        return $row ? (string) $row->value : null;
    }

    // -------------------------------------------------------------------------------------
    // The removal itself
    // -------------------------------------------------------------------------------------

    public function test_a_confirmed_removal_deletes_exactly_one_credential(): void
    {
        $m = $this->merchant();

        $this->actingAs($m)->post('/payment-setting/secret/remove', [
            'field' => 'stripe_secret_key', 'confirm' => 'remove',
        ])->assertRedirect();

        $this->assertNull($this->stored('stripe_secret_key'),
            'the credential that was asked for is still stored');

        /* FIELD INDEPENDENCE, within the same gateway. */
        $this->assertSame(self::PUBLISHABLE, $this->stored('stripe_publishable_key'),
            'removing the secret key also removed the publishable key beside it');
        $this->assertSame('Pay by card', $this->stored('stripe_unfo'),
            'removing a credential disturbed the gateway description');

        /* GATEWAY INDEPENDENCE. */
        $this->assertSame(self::PAYPAL_SECRET, $this->stored('paypal_secret_key'),
            "removing Stripe's secret also removed PayPal's");
    }

    /**
     * THE DELETE IS CONFINED TO ONE STORE, and proving that needs a SECOND store holding a row
     * of the same name — which the first version of this file did not have, so breaking the
     * `where('store_id', …)` on the delete left every test green. `Setting` is in no
     * `scoped_models` entry, so it adds no predicate of its own: that one `where` is the only
     * thing standing between this action and every tenant's copy of the same credential.
     */
    public function test_the_delete_cannot_reach_another_stores_copy(): void
    {
        $m = $this->merchant();

        $neighbour = $this->makeMerchant();
        $neighbourStore = Store::create([
            'name' => 'Neighbour', 'email' => 'neighbour@example.test',
            'slug' => 'neighbour-' . uniqid(), 'theme_id' => 'stylique',
            'created_by' => $neighbour->id, 'is_active' => 1, 'default_language' => 'en',
        ]);

        Setting::forceCreate([
            'name' => 'stripe_secret_key', 'value' => 'sk_INVENTED_neighbour',
            'store_id' => $neighbourStore->id, 'created_by' => $neighbour->id,
        ]);

        $this->actingAs($m)->post('/payment-setting/secret/remove', [
            'field' => 'stripe_secret_key', 'confirm' => 'remove',
        ]);

        $this->assertNull($this->stored('stripe_secret_key'),
            'premise: the removal did not run in the caller\'s own store');

        $theirs = Setting::withoutGlobalScopes()
            ->where('store_id', $neighbourStore->id)
            ->where('name', 'stripe_secret_key')
            ->first();

        $this->assertNotNull($theirs,
            "one merchant's removal deleted another merchant's credential of the same name");
        $this->assertSame('sk_INVENTED_neighbour', (string) $theirs->value);
    }

    /** Without the confirmation value the write refuses, even though the field is valid. */
    public function test_an_unconfirmed_request_removes_nothing(): void
    {
        $m = $this->merchant();

        $this->actingAs($m)->post('/payment-setting/secret/remove', [
            'field' => 'stripe_secret_key',
        ]);

        $this->assertSame(self::STRIPE_SECRET, $this->stored('stripe_secret_key'),
            'an unconfirmed request removed the credential, so the confirmation is decorative');
    }

    /**
     * ONLY A SECRET FIELD OF A REAL GATEWAY. The parameter is an allowlist lookup against
     * `paymentSettingArray()`, so it cannot be pointed at a public key, a site setting, or an
     * arbitrary `settings` row name.
     *
     * @dataProvider notRemovable
     */
    public function test_a_field_that_is_not_a_gateway_secret_is_refused(string $field): void
    {
        $m = $this->merchant();

        Setting::forceCreate([
            'name' => 'site_currency', 'value' => 'USD',
            'store_id' => $this->storeId, 'created_by' => $m->id,
        ]);

        /* The row this field names, BEFORE. The empty and traversal cases name no row at all,
           which is the point — there the assertion is that nothing else moved. */
        $before = $field === '' ? null : $this->stored($field);

        $this->actingAs($m)->post('/payment-setting/secret/remove', [
            'field' => $field, 'confirm' => 'remove',
        ]);

        /* ASSERTED ON THE NAMED ROW, not on a fallback. The first version of this was
           `assertNotNull($this->stored($field) ?? $this->stored('stripe_secret_key'))`, and the
           `??` is what made it useless: when the handler DID delete the named row, `stored()`
           returned null, the expression fell through to the untouched Stripe secret, and the
           test passed. Measured — breaking the allowlist to accept any field left this green.
           A fallback inside an assertion is a way for the assertion to find something else to
           be true about. */
        if ($before !== null) {
            $this->assertSame($before, $this->stored($field),
                "`{$field}` is not a gateway secret, and the removal handler deleted it anyway");
        }

        $this->assertSame(self::STRIPE_SECRET, $this->stored('stripe_secret_key'),
            "a request naming `{$field}` disturbed a real gateway credential");
        $this->assertSame(self::PAYPAL_SECRET, $this->stored('paypal_secret_key'));
    }

    public static function notRemovable(): array
    {
        return [
            'a public key' => ['stripe_publishable_key'],
            'an unrelated setting' => ['site_currency'],
            'a gateway enable flag' => ['is_stripe_enabled'],
            'nothing at all' => [''],
            'an invented name' => ['../../etc/passwd'],
        ];
    }

    // -------------------------------------------------------------------------------------
    // The enabled-gateway guard
    // -------------------------------------------------------------------------------------

    /**
     * A REQUIRED SECRET CANNOT LEAVE AN ENABLED GATEWAY. Allowing it would leave the gateway
     * switched on and unable to authenticate, and the merchant would learn about it from a
     * shopper failing at checkout.
     */
    public function test_a_required_secret_cannot_be_removed_while_the_gateway_is_on(): void
    {
        $m = $this->merchant(stripeEnabled: true);

        $this->actingAs($m)->post('/payment-setting/secret/remove', [
            'field' => 'stripe_secret_key', 'confirm' => 'remove',
        ])->assertSessionHas('error');

        $this->assertSame(self::STRIPE_SECRET, $this->stored('stripe_secret_key'),
            'a required credential was removed from a gateway that is switched on');
    }

    /** And the same secret CAN be removed once the gateway is off — the guard is the state, not the field. */
    public function test_the_same_secret_can_be_removed_once_the_gateway_is_off(): void
    {
        $m = $this->merchant(stripeEnabled: false);

        $this->actingAs($m)->post('/payment-setting/secret/remove', [
            'field' => 'stripe_secret_key', 'confirm' => 'remove',
        ]);

        $this->assertNull($this->stored('stripe_secret_key'),
            'the guard refuses even when the gateway is off, so removal is impossible');
    }

    // -------------------------------------------------------------------------------------
    // What must NOT have changed
    // -------------------------------------------------------------------------------------

    /** Switching a gateway off still keeps its credentials. The owner's decision says so. */
    public function test_switching_a_gateway_off_still_keeps_its_credentials(): void
    {
        $m = $this->merchant(stripeEnabled: true);

        $this->actingAs($m)->post('/payment-setting', [
            'is_paypal_enabled' => 'on',
            'paypal_client_id' => 'INVENTED-CLIENT-ID',
            'paypal_secret_key' => '',
        ]);

        $this->assertSame('off', $this->stored('is_stripe_enabled'));
        $this->assertSame(self::STRIPE_SECRET, $this->stored('stripe_secret_key'),
            'switching a gateway off erased its credentials');
    }

    /** An empty box on a normal save still means keep. */
    public function test_an_empty_box_on_a_normal_save_still_keeps_the_value(): void
    {
        $m = $this->merchant(stripeEnabled: true);

        $this->actingAs($m)->post('/payment-setting', [
            'is_stripe_enabled' => 'on',
            'stripe_publishable_key' => self::PUBLISHABLE,
            'stripe_secret_key' => '',
            'stripe_unfo' => 'Cards accepted',
        ]);

        $this->assertSame('Cards accepted', $this->stored('stripe_unfo'),
            'positive control: the save did not run');
        $this->assertSame(self::STRIPE_SECRET, $this->stored('stripe_secret_key'),
            'an empty box on a normal save removed the credential');
    }

    // -------------------------------------------------------------------------------------
    // Permission, and exposure
    // -------------------------------------------------------------------------------------

    public function test_a_principal_without_the_setting_permission_cannot_remove(): void
    {
        $m = $this->merchant();
        $outsider = $this->makeStaff($m);

        $this->actingAs($outsider)->post('/payment-setting/secret/remove', [
            'field' => 'stripe_secret_key', 'confirm' => 'remove',
        ])->assertSessionHas('error');

        $this->assertSame(self::STRIPE_SECRET, $this->stored('stripe_secret_key'),
            'a staff member without `Manage Setting` removed a gateway credential');
    }

    public function test_the_confirmation_page_is_also_refused_without_the_permission(): void
    {
        $m = $this->merchant();
        $outsider = $this->makeStaff($m);

        $this->actingAs($outsider)
            ->post('/payment-setting/secret/confirm', ['field' => 'stripe_secret_key'])
            ->assertSessionHas('error');
    }

    /**
     * THE CONFIRMATION PAGE MUST NOT RENDER THE THING IT IS ABOUT. It names the field and the
     * gateway so the merchant knows what they are removing; naming the VALUE would put a live
     * credential on screen in the one flow a merchant reaches because it may have leaked.
     */
    public function test_the_confirmation_page_never_renders_the_credential(): void
    {
        $m = $this->merchant();

        $html = $this->actingAs($m)
            ->post('/payment-setting/secret/confirm', ['field' => 'stripe_secret_key'])
            ->assertOk()->getContent();

        $this->assertStringNotContainsString(self::STRIPE_SECRET, $html,
            'the confirmation page renders the credential it is offering to remove');
        $this->assertStringContainsString('Stripe Secret Key', $html,
            'the page does not say WHICH credential, so the confirmation is not informed');
        $this->assertStringContainsString('does not revoke', $html,
            'the page does not say that removing locally is not revoking at the provider');
    }

    /**
     * AND NOTHING WRITES THE CREDENTIAL TO THE LOG. A security fix that reports what it
     * protected is the oldest way to undo it, and the field name travelling in a POST body
     * rather than a url is only half of keeping it out of the record.
     */
    public function test_no_credential_reaches_the_log(): void
    {
        $m = $this->merchant();

        $written = [];
        Log::listen(static function ($message) use (&$written): void {
            $written[] = json_encode([$message->message, $message->context]);
        });

        /* A PREMISE, because the first version of this test asserted nothing when nothing was
           logged — and PHPUnit called it risky, correctly. "No log line carried the secret" and
           "no log line existed" are indistinguishable without it, and the second is also what
           you see when the requests never ran. One deliberate record proves the listener is
           attached and capturing. */
        Log::info('premise: the listener is attached');

        $this->actingAs($m)->post('/payment-setting/secret/confirm', ['field' => 'stripe_secret_key']);
        $this->actingAs($m)->post('/payment-setting/secret/remove', [
            'field' => 'stripe_secret_key', 'confirm' => 'remove',
        ]);

        $this->assertNotEmpty($written, 'premise: the log listener captured nothing at all');
        $this->assertNull($this->stored('stripe_secret_key'),
            'premise: the removal did not run, so "nothing was logged" proves nothing');

        foreach ($written as $line) {
            $this->assertStringNotContainsString(self::STRIPE_SECRET, $line,
                'a log record carries the credential');
            $this->assertStringNotContainsString(self::PAYPAL_SECRET, $line,
                'a log record carries another gateway\'s credential');
        }
    }
}
