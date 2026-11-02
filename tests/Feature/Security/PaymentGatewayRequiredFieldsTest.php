<?php

namespace Tests\Feature\Security;

use App\Models\Setting;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * F142 — the payment screen's required-field validation, repaired on the transition only.
 * =================================================================================================
 *
 * THIS FILE USED TO PIN THE OPPOSITE, and the change of direction is the record worth keeping.
 * `processGateway()` called `validateGatewayFields()` as a statement and discarded its return,
 * while that method's only failure path was `return redirect()->back()` — a response object
 * nobody sends. So every `required_fields` entry across all 52 gateways was decorative, and a
 * gateway could be switched on with its credentials empty. That was MEASURED, pinned here rather
 * than repaired, and escalated: making the rule live would start refusing saves that succeed
 * today.
 *
 * THE OWNER'S DECISION WAS TO REPAIR IT GRADUALLY, and the shape follows from one fact about
 * this screen: it is ONE form and ONE save covering all 52 gateways. A blanket rule would mean
 * that a single gateway enabled today with a field it never filled makes the merchant unable to
 * save ANYTHING on the payment screen, with nothing on the page saying which gateway is at
 * fault. So:
 *
 *   - the rule applies only when a gateway is being TURNED ON — `$wasEnabled` reads the STORED
 *     row, never the request, so a gateway already on keeps saving exactly as before;
 *   - a secret that is ALREADY STORED counts as supplied, because its box is masked and posts
 *     empty when the merchant leaves it alone;
 *   - a failure does not abort the save. The gateway is left OFF and named, everything typed is
 *     still written, and the other 51 gateways still save.
 *
 * IMPACT MEASURED BEFORE THE RULE WAS WRITTEN: all 118 `required_fields` entries across the 52
 * gateways have a rendered control — counting the mode radios, which come from `mode_options`
 * rather than from `fields`, and which a first pass of that audit wrongly reported as missing
 * for 18 gateways. The rule can never demand a value the merchant has no way to supply.
 */
class PaymentGatewayRequiredFieldsTest extends SecurityTestCase
{
    use RefreshDatabase;

    private int $storeId = 0;

    private function merchant(): \App\Models\User
    {
        $merchant = $this->makeMerchant();
        $this->grantPermission($merchant, 'Manage Store Setting');

        $store = Store::create([
            'name' => 'Validation Store', 'email' => 'validation@example.test',
            'slug' => 'validation-store-' . uniqid(), 'theme_id' => 'stylique',
            'created_by' => $merchant->id, 'is_active' => 1, 'default_language' => 'en',
        ]);

        $merchant->current_store = $store->id;
        $merchant->save();
        $this->storeId = $store->id;

        return \App\Models\User::find($merchant->id);
    }

    private function stored(string $name): ?string
    {
        $row = Setting::withoutGlobalScopes()
            ->where('store_id', $this->storeId)->where('name', $name)->first();

        return $row ? (string) $row->value : null;
    }

    /** THE REPAIR: an incomplete gateway is not accepted as a valid one. */
    public function test_a_gateway_cannot_be_enabled_with_its_required_credentials_empty(): void
    {
        $m = $this->merchant();

        $this->actingAs($m)->post('/payment-setting', [
            'is_stripe_enabled' => 'on',
            'stripe_publishable_key' => '',
            'stripe_secret_key' => '',
            'stripe_unfo' => 'Pay by card',
        ])->assertSessionHas('error');

        $this->assertSame('off', $this->stored('is_stripe_enabled'),
            'a gateway was switched on with both of its required credentials empty');
    }

    /**
     * AND THE SAVE IS NOT LOST. Everything typed is still written and the other gateways still
     * save, because aborting one form would punish 51 gateways for one.
     */
    public function test_an_incomplete_gateway_does_not_abort_the_whole_save(): void
    {
        $m = $this->merchant();

        $this->actingAs($m)->post('/payment-setting', [
            'is_stripe_enabled' => 'on',
            'stripe_publishable_key' => '',
            'stripe_secret_key' => '',
            'stripe_unfo' => 'Pay by card',
            'is_bank_transfer_enabled' => 'on',
            'bank_transfer' => 'Our IBAN is on the invoice',
        ]);

        $this->assertSame('on', $this->stored('is_bank_transfer_enabled'),
            'an unrelated gateway failed to save because another one was incomplete');
        $this->assertSame('Our IBAN is on the invoice', $this->stored('bank_transfer'));
        $this->assertSame('Pay by card', $this->stored('stripe_unfo'),
            'the incomplete gateway lost the values the merchant did type');
    }

    /** The message names the gateway and the fields, or it cannot be acted on. */
    public function test_the_refusal_names_the_gateway_and_the_missing_fields(): void
    {
        $m = $this->merchant();

        $this->actingAs($m)->post('/payment-setting', [
            'is_stripe_enabled' => 'on',
            'stripe_publishable_key' => '',
            'stripe_secret_key' => '',
        ]);

        $error = session('error');

        $this->assertIsString($error);
        $this->assertStringContainsString('Stripe Publishable Key', $error);
        $this->assertStringContainsString('Stripe Secret Key', $error);
    }

    /**
     * A GATEWAY ALREADY ON KEEPS SAVING, which is the half that makes this gradual rather than a
     * blanket switch. A merchant whose gateway was enabled before the rule existed — possibly
     * with a field they were never asked for — must not be locked out of the payment screen.
     */
    public function test_a_gateway_already_on_is_not_retrospectively_refused(): void
    {
        $m = $this->merchant();

        foreach (['is_stripe_enabled' => 'on', 'stripe_unfo' => 'Pay by card'] as $name => $value) {
            Setting::forceCreate([
                'name' => $name, 'value' => $value,
                'store_id' => $this->storeId, 'created_by' => $m->id,
            ]);
        }

        $this->actingAs($m)->post('/payment-setting', [
            'is_stripe_enabled' => 'on',
            'stripe_publishable_key' => '',
            'stripe_secret_key' => '',
            'stripe_unfo' => 'Cards accepted',
        ]);

        $this->assertSame('on', $this->stored('is_stripe_enabled'),
            'a gateway that was already on was switched off by the new rule');
        $this->assertSame('Cards accepted', $this->stored('stripe_unfo'),
            'a merchant with a grandfathered gateway could not change its description');
    }

    /** A stored secret counts as supplied, or enabling would mean retyping a key to flip a switch. */
    public function test_a_stored_secret_satisfies_the_requirement(): void
    {
        $m = $this->merchant();

        foreach ([
            'stripe_publishable_key' => 'pk_INVENTED',
            'stripe_secret_key' => 'sk_INVENTED_not_real',
        ] as $name => $value) {
            Setting::forceCreate([
                'name' => $name, 'value' => $value,
                'store_id' => $this->storeId, 'created_by' => $m->id,
            ]);
        }

        $this->actingAs($m)->post('/payment-setting', [
            'is_stripe_enabled' => 'on',
            'stripe_publishable_key' => 'pk_INVENTED',
            'stripe_secret_key' => '',
            'stripe_unfo' => 'Pay by card',
        ]);

        $this->assertSame('on', $this->stored('is_stripe_enabled'),
            'enabling a gateway whose secret is already stored was refused, so the merchant '
            . 'would have to retype a credential to flip a switch');
        $this->assertSame('sk_INVENTED_not_real', $this->stored('stripe_secret_key'),
            'and the stored secret must still be there');
    }

    /** A complete configuration still enables, or the rule refuses everything. */
    public function test_a_complete_configuration_still_enables(): void
    {
        $m = $this->merchant();

        $this->actingAs($m)->post('/payment-setting', [
            'is_stripe_enabled' => 'on',
            'stripe_publishable_key' => 'pk_INVENTED',
            'stripe_secret_key' => 'sk_INVENTED_not_real',
            'stripe_unfo' => 'Pay by card',
        ])->assertSessionHas('success');

        $this->assertSame('on', $this->stored('is_stripe_enabled'));
    }
}
