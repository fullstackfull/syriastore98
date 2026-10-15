<?php

namespace Workdo\LynomiaChat\app\Services;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Workdo\LynomiaChat\app\Models\LynomiaConsentDecision;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Support\LinkState;

/**
 * Turning a ticked box on a checkout page into a consent decision this store can defend.
 *
 * THE FIELD NAME IS THE WHOLE CONTRACT WITH THE FORM. Nothing else about the checkout is
 * read: not the email, not the phone, not the address. Lynomia resolves the subject through
 * the customer link it already holds, so sending contact details would put them in a request
 * log and an outbox for nobody to read.
 *
 * AFFIRMATIVE, AND NEVER PRE-TICKED. The box is rendered unchecked
 * (resources/views/storefront/marketing-optin.blade.php) and absence of the parameter means
 * no. There is deliberately no "unticking it withdraws" branch: a shopper who does not tick
 * a box has not asked to be removed from anything, and reading silence as a withdrawal would
 * destroy a grant they gave somewhere else -- on WhatsApp, or in the merchant's own console.
 * Withdrawal is an explicit act with its own paths.
 *
 * A GUEST IS NOT CAPTURED, because there is nobody to attach the grant to. `customer_id` is 0
 * for a guest order and the subject this store sends is the customer id, so a guest's tick
 * has no stable identity to be recorded against. Capturing it under the order id would create
 * a grant that can never be matched to the person on their next visit and can never be
 * honoured when they withdraw.
 *
 * A TICK IS NOT A RE-CONSENT. `re_consent` is false, always, and that is the most important
 * line here. Lynomia refuses a plain grant that follows a withdrawal
 * (`refused_withdrawal_dominant`), and a checkout checkbox is exactly the kind of grant that
 * must be refused: a shopper who said STOP on WhatsApp has not changed their mind because
 * they did not notice a box three screens into a purchase. Re-consent after a withdrawal is a
 * separate, explicitly verified workflow (docs/partner-consent/AUTHORITY.md §4); this is not
 * it, and the refusal that follows is the correct outcome, not a bug to route around.
 *
 * RECORDED ONCE PER DECISION, NOT ONCE PER ORDER. A shopper who buys weekly is restating a
 * grant, not making a new one, so a tick is skipped while the newest local decision for the
 * scope is already `granted`. What that leaves, after a withdrawal, is exactly one refused
 * row -- which is the record the merchant needs to understand why their opt-in did not take
 * -- and not one per order for ever.
 */
class CheckoutConsentCapture
{
    /** The checkout form field. Absence means no. */
    public const FIELD = 'lynomia_marketing_optin';

    public const SOURCE = 'checkout_optin';

    /**
     * Marketing only. A transactional message about an order the shopper placed is not
     * something a marketing box grants or withholds, and conflating the two would let an
     * unticked box look like a refusal to be told their parcel shipped.
     */
    public const PURPOSE = 'promotional';

    /**
     * Every channel the store might reach them on. The shopper agreed to hear from the store,
     * not to hear from it over SMS specifically, and a checkout page that asked them to pick
     * transports would be asking a question they have no basis to answer.
     */
    public const CHANNEL = 'any';

    public function capture(Order $order, Request $request): ?LynomiaConsentDecision
    {
        if (! config('lynomia.enabled') || ! $request->boolean(self::FIELD)) {
            return null;
        }

        $customerId = (int) ($order->customer_id ?? 0);
        $storeId = (int) ($order->store_id ?? 0);

        if ($customerId <= 0 || $storeId <= 0) {
            return null;
        }

        $link = LynomiaStoreLink::forStore($storeId)->first();

        if ($link === null || $link->state !== LinkState::ACTIVE || ! $link->checkout_optin_enabled) {
            return null;
        }

        $subject = (string) $customerId;

        if ($this->alreadyGranted($storeId, $subject)) {
            return null;
        }

        return LynomiaConsentDecision::create([
            'store_id' => $storeId,
            'customer_id' => $customerId,
            'external_subject_id' => $subject,
            'purpose' => self::PURPOSE,
            'channel' => self::CHANNEL,
            'state' => 'granted',
            'source' => self::SOURCE,
            'policy_version' => $link->consent_policy_version,
            're_consent' => false,
            // No name, email, phone or address: this is the evidence that a decision was
            // made and where, not a copy of the customer record.
            'evidence' => [
                'form' => 'checkout',
                'order_ref' => (string) ($order->product_order_id ?? ''),
            ],
            'captured_at' => now(),
            // Minted ONCE, here. A retry, a redelivery after a crash and a reconciliation
            // replay all carry this same ref, and Lynomia's UNIQUE (commerce_store_id,
            // decision_ref) turns every repeat into a no-op -- which is what makes
            // at-least-once delivery safe for something that must not be applied twice.
            'decision_ref' => (string) Str::uuid(),
            'attempts' => 0,
        ]);
    }

    private function alreadyGranted(int $storeId, string $subject): bool
    {
        $latest = LynomiaConsentDecision::forStore($storeId)
            ->where('external_subject_id', $subject)
            ->where('purpose', self::PURPOSE)
            ->where('channel', self::CHANNEL)
            ->orderByDesc('id')
            ->first();

        return $latest !== null && $latest->state === 'granted';
    }
}
