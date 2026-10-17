<?php

namespace Workdo\LynomiaChat\app\Services;

use App\Models\Store;
use App\Models\User;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Support\LinkState;
use Workdo\LynomiaChat\app\Support\MerchantContact;

/**
 * WHO STORE SYRIA MAY SEND AN OPERATIONAL NOTICE TO, and at what number.
 *
 * Two questions, and they have very different answers on this installation.
 *
 * ELIGIBILITY IS ALREADY WELL ESTABLISHED. A merchant is a recipient when their link is
 * provisioned, in a state that still represents a customer, and -- for a claimed workspace --
 * when Lynomia confirmed they control it. That whole test is already written as
 * `LynomiaStoreLink::allowsSso()`, so it is called rather than re-derived, and the store's
 * current owner is checked against the owner recorded on the link: a store that changed
 * hands since the link was made is not somebody this integration has any business messaging.
 *
 * ADDRESSING IS NOT, AND THIS CLASS REFUSES RATHER THAN GUESSES.
 *
 * `users.mobile` was nullable, unindexed and unvalidated, and is still written with values
 * that were never anybody's number: self-registration writes an empty string, and Super Admin
 * store creation wrote the literal 7878787878 for every merchant it created until B4 was
 * fixed. Sending a WhatsApp message to a value like that does not fail safely -- it reaches
 * WHOEVER HOLDS THAT NUMBER, who is a stranger, about a merchant's account.
 *
 * SO WELL-FORMED IS NOT ENOUGH, AND THAT IS THE CHANGE B4 MADE HERE. A number can be
 * perfectly dialable and belong to somebody else -- a typo in a merchant's own profile form
 * is a valid number for a stranger, and no amount of parsing will tell the two apart. The
 * only thing that distinguishes them is the holder having answered a code sent to it, which
 * is `users.mobile_verified_at` and is set by nothing but
 * MerchantContactVerification::confirm. A merchant who has not done that is reported
 * unreachable with `phone_not_verified`, which is a fact an operator can act on rather than a
 * message a stranger receives.
 *
 * THE CLASSIFICATION ITSELF LIVES IN MerchantContact, because the Super Admin report and the
 * verification flow ask the same question and three answers to it would be three policies.
 *
 * THE OPERATOR NEVER TYPES A NUMBER. Every recipient is resolved from the merchant's own
 * record, which is the owner's requirement that recipients be verified merchants rather than
 * an address somebody entered.
 */
class PlatformRecipients
{
    /**
     * States that still represent a customer worth telling about their account.
     *
     * SUSPENDED IS INCLUDED DELIBERATELY. A suspended installation is the case where the
     * merchant most needs to hear from the platform -- that is usually why it is suspended --
     * and refusing to tell them would make the hold silent. DECOMMISSIONED and REVOKED are
     * not: the arrangement is over, and continuing to message somebody about an account they
     * no longer have is not an operational notice.
     */
    private const REACHABLE_STATES = [LinkState::ACTIVE, LinkState::SUSPENDED];

    /**
     * @return array{0: ?array{store_id:int,user_id:int,phone:string,name:string}, 1: ?string}
     *         the recipient, or null and a refusal reason
     */
    public function resolve(LynomiaStoreLink $link): array
    {
        if (! in_array($link->state, self::REACHABLE_STATES, true)) {
            return [null, 'installation_not_reachable'];
        }

        if (! $link->isProvisioned()) {
            return [null, 'installation_not_provisioned'];
        }

        if ($link->link_mode === LynomiaStoreLink::MODE_LINK_EXISTING && $link->ownership_proven_at === null) {
            return [null, 'ownership_not_proven'];
        }

        $store = Store::find($link->store_id);

        if ($store === null) {
            return [null, 'store_missing'];
        }

        // THE STORE CHANGED HANDS. The link recorded an owner; if the store's owner is now
        // somebody else, this integration has no business messaging either of them about it
        // until an operator looks.
        if ((int) $store->created_by !== (int) $link->owner_user_id) {
            return [null, 'owner_changed'];
        }

        $owner = User::find($store->created_by);

        if ($owner === null) {
            return [null, 'owner_missing'];
        }

        $contact = MerchantContact::of($owner);

        if (! $contact->reachable()) {
            return [null, $contact->refusal()];
        }

        return [[
            'store_id' => (int) $link->store_id,
            'user_id' => (int) $owner->id,
            'phone' => $contact->digits,
            'name' => (string) ($owner->name ?? 'Merchant'),
        ], null];
    }
}
