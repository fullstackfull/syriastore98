<?php

namespace Tests\Feature\Security;

use App\Models\Setting;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The payment screen's required-field validation does nothing. This pins that, it does not fix it.
 * =================================================================================================
 *
 * `PaymentSettingController::processGateway()` does
 *
 *     if (!empty($gateway['required_fields'])) {
 *         $this->validateGatewayFields($request, $gateway);
 *     }
 *
 * — a statement, with the return value discarded. And the only thing `validateGatewayFields()`
 * does when the validator fails is `return redirect()->back()->with('error', …)`, which from a
 * private method called that way constructs a response object that nobody sends. So every
 * `required_fields` entry across all 52 gateways is decorative: a gateway can be switched on with
 * its credentials empty, and the save reports success.
 *
 * FOUND WHILE MASKING THE CREDENTIALS, and worth recording because of how it was found. The F117
 * change made a required secret `nullable` when one is already stored — otherwise a masked box
 * would refuse every save of a configured gateway. A test was written for that, and it PASSED
 * AGAINST THE UNFIXED CODE TOO, because the validator never bites either way. A test that passes
 * before and after the change it is meant to prove is not evidence, and the right response is to
 * find out why rather than to keep the green tick.
 *
 * WHY IT IS NOT REPAIRED HERE. Making the validation live would start REFUSING saves that
 * currently succeed. Every merchant who has enabled a gateway without filling a field this array
 * calls required would find the payment screen unable to save at all — including the fields they
 * were never asked for, because the rule has never run. That is a change to what the screen
 * accepts, with a blast radius across 52 gateways, and it is not part of "stop rendering the
 * credentials". It belongs to the owner, and the ledger carries it.
 *
 * WHAT THIS TEST BUYS MEANWHILE: the behaviour cannot change by accident in either direction. If
 * somebody makes the validator live, this fails and names the decision. If somebody relies on the
 * deadness, this is where that reliance is written down.
 */
class PaymentGatewayValidationIsDeadTest extends SecurityTestCase
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

    /**
     * Stripe's `required_fields` are `stripe_publishable_key` and `stripe_secret_key`. Nothing is
     * stored for this merchant, so neither can be "kept" — there is nothing to keep — and the
     * rules are genuinely `required`. The save still goes through.
     */
    public function test_a_gateway_can_be_enabled_with_its_required_credentials_empty(): void
    {
        $merchant = $this->merchant();

        $this->actingAs($merchant)->post('/payment-setting', [
            'is_stripe_enabled' => 'on',
            'stripe_publishable_key' => '',
            'stripe_secret_key' => '',
            'stripe_unfo' => 'Pay by card',
        ]);

        $this->assertSame('on', $this->stored('is_stripe_enabled'),
            'the gateway was NOT enabled, so `validateGatewayFields()` now bites. That is '
            . 'probably the right behaviour, but it changes what the payment screen accepts for '
            . 'all 52 gateways and is an owner decision — read this class\'s docblock.');

        $this->assertSame('Pay by card', $this->stored('stripe_unfo'),
            'the save was refused, so the validator now bites — see the assertion above');

        /* And the empty required fields really were written as empty, which is the state the
           validation was supposed to prevent. */
        $this->assertSame('', $this->stored('stripe_secret_key'),
            'an empty required secret was not written as empty, so something else now guards it');
    }
}
