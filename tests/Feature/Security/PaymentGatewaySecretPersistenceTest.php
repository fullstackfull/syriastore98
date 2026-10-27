<?php

namespace Tests\Feature\Security;

use App\Models\Setting;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * F117, the payment screen — 58 gateway secrets survive a save that does not retype them.
 * =================================================================================================
 *
 * THE PAYMENT SCREEN IS THE ONE WHERE MASKING WITHOUT THIS WOULD HAVE BEEN CATASTROPHIC. Every
 * other panel posts a handful of fields for one integration; this one posts all 52 gateways at
 * once, and `PaymentSettingController::processGateway()` wrote
 *
 *     $this->post[$field] = !empty($request->$field) ? $request->$field : '';
 *
 * for each. That line was safe only while every field rendered its value back into the form, so
 * "nothing posted" could not happen for a configured gateway. The moment the 58 secret fields
 * became masked, a merchant changing a gateway DESCRIPTION would have had every stored key on
 * the screen overwritten with the empty string — silently, with a success message, and with no
 * way to tell which integration had stopped working until a customer failed to check out.
 *
 * So this is the bite test for the persistence half. It drives the real route with the real
 * payload shape and reads the rows back through `withoutGlobalScopes()`, because a plain
 * `Setting::` query answers against whichever tenant happens to be resolved rather than against
 * the table — the false-pass this suite has already met once.
 *
 * AND IT CARRIES A POSITIVE CONTROL, which is the part that makes the main assertion mean
 * anything. "The secret survived" is also what you observe when the handler did nothing at all:
 * wrong permission, unresolved store, validation refusal. A public field on the same gateway is
 * changed in the same request and asserted to have CHANGED, so a save that no-opped fails here
 * instead of reporting that credentials were preserved.
 *
 * NO REAL CREDENTIAL APPEARS. Every fixture value is an obvious invention and no assertion
 * message interpolates a stored value, so a failure cannot print one into CI output.
 */
class PaymentGatewaySecretPersistenceTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const INVENTED_STRIPE_SECRET = 'sk_INVENTED_not_a_real_key';
    private const INVENTED_PAYPAL_SECRET = 'INVENTED-PAYPAL-SECRET-not-real';
    private const STORED_PUBLISHABLE = 'pk_INVENTED_publishable';

    private int $storeId = 0;

    private function merchantWithGatewaySecrets(): \App\Models\User
    {
        $merchant = $this->makeMerchant();
        $this->grantPermission($merchant, 'Manage Store Setting');

        $store = Store::create([
            'name' => 'Gateway Store', 'email' => 'gateways@example.test',
            'slug' => 'gateway-store-' . uniqid(), 'theme_id' => 'stylique',
            'created_by' => $merchant->id, 'is_active' => 1, 'default_language' => 'en',
        ]);

        $merchant->current_store = $store->id;
        $merchant->save();
        $this->storeId = $store->id;

        foreach ([
            'is_stripe_enabled' => 'on',
            'stripe_publishable_key' => self::STORED_PUBLISHABLE,
            'stripe_secret_key' => self::INVENTED_STRIPE_SECRET,
            'stripe_unfo' => 'Pay by card',
            'is_paypal_enabled' => 'on',
            'paypal_client_id' => 'INVENTED-CLIENT-ID',
            'paypal_secret_key' => self::INVENTED_PAYPAL_SECRET,
            'paypal_mode' => 'sandbox',
        ] as $name => $value) {
            Setting::forceCreate([
                'name' => $name, 'value' => $value,
                'store_id' => $store->id, 'created_by' => $merchant->id,
            ]);
        }

        return $merchant;
    }

    private function stored(string $name): ?string
    {
        $row = Setting::withoutGlobalScopes()
            ->where('store_id', $this->storeId)
            ->where('name', $name)
            ->first();

        return $row ? (string) $row->value : null;
    }

    /** The payload a masked screen really posts: every secret box empty, the public ones filled. */
    private function payloadWithEmptySecrets(array $overrides = []): array
    {
        return array_merge([
            'is_stripe_enabled' => 'on',
            'stripe_publishable_key' => self::STORED_PUBLISHABLE,
            'stripe_secret_key' => '',
            'stripe_unfo' => 'Pay by card',
            'is_paypal_enabled' => 'on',
            'paypal_client_id' => 'INVENTED-CLIENT-ID',
            'paypal_secret_key' => '',
            'paypal_mode' => 'sandbox',
        ], $overrides);
    }

    public function test_a_save_with_empty_secret_boxes_keeps_every_stored_gateway_credential(): void
    {
        $merchant = $this->merchantWithGatewaySecrets();

        /* The positive control rides in the same request: the Stripe description changes, so a
           handler that refused or no-opped cannot pass this test by leaving everything alone. */
        $this->actingAs($merchant)->post('/payment-setting',
            $this->payloadWithEmptySecrets(['stripe_unfo' => 'Pay by card or wallet']));

        $this->assertSame('Pay by card or wallet', $this->stored('stripe_unfo'),
            'positive control: the save did not run, so "the secret survived" below proves nothing');

        $this->assertSame(self::INVENTED_STRIPE_SECRET, $this->stored('stripe_secret_key'),
            'the Stripe secret key was overwritten by a save that left its masked box empty');
        $this->assertSame(self::INVENTED_PAYPAL_SECRET, $this->stored('paypal_secret_key'),
            'the PayPal secret key was overwritten by a save that left its masked box empty');
    }

    /** Typing a new value must still replace the old one, or "blank keeps it" becomes "nothing changes". */
    public function test_a_typed_secret_replaces_the_stored_one(): void
    {
        $merchant = $this->merchantWithGatewaySecrets();

        $this->actingAs($merchant)->post('/payment-setting',
            $this->payloadWithEmptySecrets(['stripe_secret_key' => 'sk_INVENTED_rotated']));

        $this->assertSame('sk_INVENTED_rotated', $this->stored('stripe_secret_key'),
            'a retyped secret did not replace the stored one, so the field cannot be rotated');
        $this->assertSame(self::INVENTED_PAYPAL_SECRET, $this->stored('paypal_secret_key'),
            'rotating one gateway\'s secret disturbed another\'s');
    }

    /**
     * A PUBLIC field is NOT protected, and that is deliberate rather than an oversight: it
     * renders with its value, so an empty post there really does mean the merchant cleared it.
     * Asserting it keeps the protection from quietly spreading to every field, which would make
     * the screen unable to clear anything.
     */
    public function test_a_public_field_is_still_cleared_by_an_empty_post(): void
    {
        $merchant = $this->merchantWithGatewaySecrets();

        $this->actingAs($merchant)->post('/payment-setting',
            $this->payloadWithEmptySecrets(['stripe_publishable_key' => '']));

        $this->assertSame('', $this->stored('stripe_publishable_key'),
            'the publishable key is public and visible in its box, so clearing it must clear it');
        $this->assertSame(self::INVENTED_STRIPE_SECRET, $this->stored('stripe_secret_key'),
            'clearing a public field disturbed the secret beside it');
    }

    /**
     * Stripe's two keys are in `required_fields`. With the secret masked, a flat `required` rule
     * would refuse every save of an already-configured gateway — the merchant could not change
     * the description without retyping their secret key, so the rule was changed to `required`
     * only while nothing is stored.
     *
     * THIS TEST IS CURRENTLY VACUOUS AND IS KEPT ANYWAY, labelled. It passes against the
     * unchanged code too, because `validateGatewayFields()` has no effect at all:
     * `processGateway()` discards its return value and the method's only failure path is a
     * `redirect()` nobody sends. That is recorded and pinned by
     * `PaymentGatewayValidationIsDeadTest`, which is where the finding lives.
     *
     * It stays because the rule it describes is the one that must hold on the day the validator
     * is made live, and on that day this becomes real evidence with no edit. Deleting it would
     * mean rediscovering the trap then. What it must NOT be read as today is proof that the
     * `nullable` branch works — nothing here exercises it.
     */
    public function test_a_required_secret_that_is_already_stored_does_not_block_the_save(): void
    {
        $merchant = $this->merchantWithGatewaySecrets();

        $this->actingAs($merchant)->post('/payment-setting',
            $this->payloadWithEmptySecrets(['stripe_unfo' => 'Cards accepted']));

        $this->assertSame('Cards accepted', $this->stored('stripe_unfo'),
            'the save was refused because a required secret posted empty, even though one is stored');
    }

    public function test_the_route_refuses_a_principal_without_the_setting_permission(): void
    {
        $merchant = $this->merchantWithGatewaySecrets();
        $outsider = $this->makeStaff($merchant);

        $response = $this->actingAs($outsider)->post('/payment-setting',
            $this->payloadWithEmptySecrets(['stripe_unfo' => 'changed by staff']));

        /* "Nothing changed" is also what a 500 looks like. The handler's refusal is
           `redirect()->back()->with('error', …)`, so assert the refusal itself and not only its
           absence of effect. */
        $response->assertSessionHas('error');

        $this->assertSame('Pay by card', $this->stored('stripe_unfo'),
            'a staff member without `Manage Setting` changed the payment settings');
        $this->assertSame(self::INVENTED_STRIPE_SECRET, $this->stored('stripe_secret_key'),
            'a staff member without `Manage Setting` reached the gateway credentials');
    }
}
