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
     * IT WAS VACUOUS WHEN WRITTEN AND IS NOT ANY MORE, which is worth recording because the
     * reason it was vacuous is the finding. `validateGatewayFields()` had no effect at all —
     * `processGateway()` discarded its return and its only failure path was a `redirect()`
     * nobody sends — so this test passed against the UNFIXED code too. A test that passes
     * before and after the change it is meant to prove is not evidence.
     *
     * The rule is live now (F142), so this is real: `PaymentGatewayRequiredFieldsTest` proves
     * the same property from the other side, by showing that a gateway WITHOUT a stored secret
     * is refused.
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

    // -------------------------------------------------------------------------------------
    // F143 — a field that is not in the request is left alone
    // -------------------------------------------------------------------------------------

    /**
     * A STORED VALUE SURVIVES A SAVE THAT DOES NOT MENTION IT. The old write could not tell
     * "the merchant cleared this box" from "this control was never on the page", and wrote ''
     * for both. `paiementpro_mode` is the measured case: it is in the gateway's save list, the
     * display array gives PaiementPro no `mode_options`, so no radio is ever rendered and
     * nothing posts it — yet every save that enabled that gateway cleared it.
     */
    public function test_a_field_absent_from_the_request_keeps_its_stored_value(): void
    {
        $merchant = $this->merchantWithGatewaySecrets();

        Setting::forceCreate([
            'name' => 'paiementpro_mode', 'value' => 'live',
            'store_id' => $this->storeId, 'created_by' => $merchant->id,
        ]);
        Setting::forceCreate([
            'name' => 'paiementpro_merchant_id', 'value' => 'INVENTED-PP-MERCHANT',
            'store_id' => $this->storeId, 'created_by' => $merchant->id,
        ]);

        /* The payload the real screen sends for PaiementPro: an enable flag and a merchant id,
           and NO mode, because no control for it is rendered. */
        $this->actingAs($merchant)->post('/payment-setting', $this->payloadWithEmptySecrets([
            'is_paiementpro_enabled' => 'on',
            'paiementpro_merchant_id' => 'INVENTED-PP-MERCHANT',
            'paiementpro_unfo' => 'Pay with PaiementPro',
        ]));

        $this->assertSame('Pay with PaiementPro', $this->stored('paiementpro_unfo'),
            'positive control: the save did not run, so the assertion below proves nothing');

        $this->assertSame('live', $this->stored('paiementpro_mode'),
            'a save that never mentioned the mode wrote over it');
    }

    /**
     * AND PRESENT-BUT-EMPTY STILL CLEARS, which is the other half and the one a careless fix
     * breaks. A GET form submits every control, so an emptied text field arrives as present and
     * empty; if that stopped clearing, nothing on this screen could ever be emptied again.
     */
    public function test_a_field_present_and_empty_is_still_cleared(): void
    {
        $merchant = $this->merchantWithGatewaySecrets();

        $this->actingAs($merchant)->post('/payment-setting',
            $this->payloadWithEmptySecrets(['stripe_publishable_key' => '']));

        $this->assertSame('', $this->stored('stripe_publishable_key'),
            'a public field that was present and empty was not cleared');
    }
}
