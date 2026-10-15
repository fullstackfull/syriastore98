<?php

namespace Tests\Feature\Lynomia;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;
use Workdo\LynomiaChat\app\Models\LynomiaConsentDecision;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Services\CheckoutConsentCapture;
use Workdo\LynomiaChat\app\Support\LinkState;

/**
 * THE MARKETING OPT-IN ON A STORE'S CHECKOUT.
 *
 * The cases that matter here are the refusals, not the happy path. A consent capture that is
 * too eager produces grants the merchant cannot defend, and the four ways to be too eager are
 * all tested as refusals: a box nobody ticked, a shopper with no identity to attach it to, a
 * store that never asked for the field, and a tick treated as re-consent after a withdrawal.
 */
class LynomiaCheckoutOptInTest extends TestCase
{
    use InteractsWithLynomia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootLynomiaHarness();
    }

    /** @return array{0: \App\Models\Store, 1: LynomiaStoreLink, 2: Customer} */
    private function optInStore(bool $enabled = true): array
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia(['account_id' => 7001]);
        $link = app(\Workdo\LynomiaChat\app\Services\AccountProvisioner::class)->provision($store, $owner)->fresh();
        $link->forceFill(['checkout_optin_enabled' => $enabled, 'consent_policy_version' => 'v3'])->save();

        $customer = Customer::create([
            'first_name' => 'Shopper', 'last_name' => 'One',
            'email' => 'shopper' . uniqid() . '@example.test',
            'store_id' => $store->id, 'created_by' => $owner->id,
        ]);

        return [$store, $link->fresh(), $customer];
    }

    private function order(int $storeId, int $customerId): Order
    {
        $order = new Order();
        $order->forceFill([
            'product_order_id' => 'o' . uniqid(),
            'customer_id' => $customerId,
            'store_id' => $storeId,
            'is_guest' => $customerId > 0 ? 0 : 1,
            'product_json' => '[]',
            'product_price' => 10,
            'final_price' => 10,
            'payment_type' => 'cod',
            'payment_status' => 'Unpaid',
            'order_date' => now(),
        ]);

        return $order;
    }

    private function capture(Order $order, array $input): ?LynomiaConsentDecision
    {
        return app(CheckoutConsentCapture::class)->capture($order, new Request($input));
    }

    public function test_a_ticked_box_is_recorded_as_a_promotional_grant(): void
    {
        [$store, , $customer] = $this->optInStore();

        $decision = $this->capture($this->order($store->id, $customer->id), [CheckoutConsentCapture::FIELD => '1']);

        $this->assertNotNull($decision);
        $this->assertSame('granted', $decision->state);
        $this->assertSame('promotional', $decision->purpose);
        $this->assertSame('any', $decision->channel);
        $this->assertSame('checkout_optin', $decision->source);
        $this->assertSame((string) $customer->id, $decision->external_subject_id);
        $this->assertSame('v3', $decision->policy_version, 'the policy the shopper was shown travels with the grant');
        $this->assertNotEmpty($decision->decision_ref);
        $this->assertNull($decision->delivered_at, 'it is queued for delivery, not delivered inline');
    }

    /**
     * THE MOST IMPORTANT LINE IN THE WHOLE CAPTURE. Lynomia refuses a plain grant that follows
     * a withdrawal, and a checkout checkbox is exactly the kind of grant that must be refused:
     * a shopper who said STOP on WhatsApp has not changed their mind because they did not
     * notice a box three screens into a purchase.
     */
    public function test_a_tick_is_never_submitted_as_a_re_consent(): void
    {
        [$store, , $customer] = $this->optInStore();

        $decision = $this->capture($this->order($store->id, $customer->id), [CheckoutConsentCapture::FIELD => '1']);

        $this->assertFalse((bool) $decision->re_consent);
    }

    public function test_an_unticked_box_records_nothing(): void
    {
        [$store, , $customer] = $this->optInStore();

        $this->assertNull($this->capture($this->order($store->id, $customer->id), []));
        $this->assertSame(0, LynomiaConsentDecision::count());
    }

    /**
     * Silence is not a withdrawal. A shopper who does not tick a box has not asked to be
     * removed from anything, and reading it that way would destroy a grant they gave on
     * another channel.
     */
    public function test_an_unticked_box_does_not_withdraw_an_existing_grant(): void
    {
        [$store, , $customer] = $this->optInStore();
        $this->capture($this->order($store->id, $customer->id), [CheckoutConsentCapture::FIELD => '1']);

        $this->capture($this->order($store->id, $customer->id), []);

        $this->assertSame(1, LynomiaConsentDecision::count());
        $this->assertSame('granted', LynomiaConsentDecision::first()->state);
    }

    /**
     * A guest has no stable identity for the grant to be attached to -- `customer_id` is 0 --
     * so a tick recorded under the order id could never be matched to them again, nor
     * honoured when they withdraw.
     */
    public function test_a_guest_checkout_records_nothing(): void
    {
        [$store] = $this->optInStore();

        $this->assertNull($this->capture($this->order($store->id, 0), [CheckoutConsentCapture::FIELD => '1']));
        $this->assertSame(0, LynomiaConsentDecision::count());
    }

    /** The merchant did not ask for the field, so a posted parameter is not consent to anything. */
    public function test_a_store_that_has_not_enabled_the_opt_in_records_nothing(): void
    {
        [$store, , $customer] = $this->optInStore(enabled: false);

        $this->assertNull($this->capture($this->order($store->id, $customer->id), [CheckoutConsentCapture::FIELD => '1']));
        $this->assertSame(0, LynomiaConsentDecision::count());
    }

    public function test_a_store_whose_link_is_not_active_records_nothing(): void
    {
        [$store, $link, $customer] = $this->optInStore();
        $link->forceFill(['state' => LinkState::REVOKED])->save();

        $this->assertNull($this->capture($this->order($store->id, $customer->id), [CheckoutConsentCapture::FIELD => '1']));
        $this->assertSame(0, LynomiaConsentDecision::count());
    }

    /**
     * A weekly shopper is restating a grant, not making a new one. Without this, a decision
     * log that is supposed to answer "when did they agree" becomes one row per order.
     */
    public function test_a_repeat_tick_while_already_granted_records_nothing_further(): void
    {
        [$store, , $customer] = $this->optInStore();
        $this->capture($this->order($store->id, $customer->id), [CheckoutConsentCapture::FIELD => '1']);

        $this->capture($this->order($store->id, $customer->id), [CheckoutConsentCapture::FIELD => '1']);

        $this->assertSame(1, LynomiaConsentDecision::count());
    }

    /**
     * After a withdrawal the tick IS a change the shopper expressed, so it is recorded and
     * sent -- and Lynomia refuses it, which is the outcome the merchant needs to see. Exactly
     * one such row per withdrawal cycle, not one per order for ever.
     */
    public function test_a_tick_after_a_withdrawal_is_recorded_once_so_the_refusal_is_visible(): void
    {
        [$store, , $customer] = $this->optInStore();
        LynomiaConsentDecision::create([
            'store_id' => $store->id, 'external_subject_id' => (string) $customer->id,
            'purpose' => 'promotional', 'channel' => 'any', 'state' => 'withdrawn',
            'source' => 'merchant', 'captured_at' => now(),
            'decision_ref' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $this->assertNotNull($this->capture($this->order($store->id, $customer->id), [CheckoutConsentCapture::FIELD => '1']));
        $this->assertNull($this->capture($this->order($store->id, $customer->id), [CheckoutConsentCapture::FIELD => '1']));
        $this->assertSame(2, LynomiaConsentDecision::count());
    }

    /** No name, no email, no phone, no address: the evidence says a decision was made, not who made it. */
    public function test_the_evidence_carries_no_customer_contact_details(): void
    {
        [$store, , $customer] = $this->optInStore();

        $decision = $this->capture($this->order($store->id, $customer->id), [
            CheckoutConsentCapture::FIELD => '1',
            'email' => 'shopper@example.test',
            'telephone' => '+963900000000',
            'first_name' => 'Shopper',
        ]);

        $flat = json_encode($decision->evidence);
        $this->assertStringNotContainsString('shopper@example.test', $flat);
        $this->assertStringNotContainsString('963900000000', $flat);
        $this->assertStringNotContainsString('Shopper', $flat);
    }

    /** THE OBSERVER, not just the service: a capture nothing calls is a capture that does not exist. */
    public function test_creating_an_order_through_the_model_captures_the_tick(): void
    {
        [$store, , $customer] = $this->optInStore();

        $this->app['request'] = Request::create('/checkout', 'POST', [CheckoutConsentCapture::FIELD => '1']);
        $this->order($store->id, $customer->id)->save();

        $this->assertSame(1, LynomiaConsentDecision::count());
        $this->assertSame('granted', LynomiaConsentDecision::first()->state);
    }

    /**
     * A consent row is worth less than the order it rode in on. If capturing raises, the
     * shopper has still bought something and must still get their confirmation.
     */
    public function test_a_failing_capture_does_not_fail_the_order(): void
    {
        [$store, , $customer] = $this->optInStore();
        $this->app->bind(CheckoutConsentCapture::class, fn () => new class extends CheckoutConsentCapture {
            public function capture(Order $order, Request $request): ?LynomiaConsentDecision
            {
                throw new \RuntimeException('consent store unavailable');
            }
        });

        $this->app['request'] = Request::create('/checkout', 'POST', [CheckoutConsentCapture::FIELD => '1']);
        $order = $this->order($store->id, $customer->id);
        $order->save();

        $this->assertNotNull($order->fresh(), 'the order must survive a consent failure');
        $this->assertSame(0, LynomiaConsentDecision::count());
    }
}
