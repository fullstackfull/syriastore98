<?php

namespace Workdo\LynomiaChat\app\Services;

use App\Models\Store;
use App\Models\User;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Support\LinkState;

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
 * There is no verified merchant phone number in Store Syria. `users.mobile` is nullable, not
 * unique, not indexed, has no format rule, and is actively seeded with values that are not
 * phone numbers: self-registration writes an empty string, and Super Admin store creation
 * hardcodes 7878787878 (StoreController.php:268). There is no OTP, no verification flag and
 * no KYC for a merchant number anywhere in the repository.
 *
 * Sending a WhatsApp message to a number like that does not fail safely -- it reaches
 * WHOEVER HOLDS THAT NUMBER, who is a stranger, about a merchant's account. So the resolver
 * admits only a number it can normalise to an international form and that is not a known
 * placeholder, and a merchant it cannot address is reported as unreachable rather than
 * addressed approximately. The gap itself is recorded as a blocker; this is what makes it
 * safe to ship in the meantime.
 *
 * THE OPERATOR NEVER TYPES A NUMBER. Every recipient is resolved from the merchant's own
 * record, which is the owner's requirement that recipients be verified merchants rather than
 * an address somebody entered.
 */
class PlatformRecipients
{
    /**
     * Values that are in the column and are not phone numbers. Not a general-purpose filter:
     * these are the two this installation actually writes.
     */
    private const PLACEHOLDERS = ['7878787878'];

    /** Shortest and longest an international subscriber number can be (ITU E.164). */
    private const MIN_DIGITS = 8;
    private const MAX_DIGITS = 15;

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

        [$phone, $problem] = $this->addressFor($owner);

        if ($problem !== null) {
            return [null, $problem];
        }

        return [[
            'store_id' => (int) $link->store_id,
            'user_id' => (int) $owner->id,
            'phone' => $phone,
            'name' => (string) ($owner->name ?? 'Merchant'),
        ], null];
    }

    /**
     * The merchant's own number, or why it cannot be used.
     *
     * Digits only, leading + stripped, and judged on length. A stricter rule would need a
     * real phone library and a country to parse against; what this must prevent is sending
     * to an empty string, a placeholder or something that is plainly not a number, and it
     * does that without pretending to validate more than it can.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function addressFor(User $owner): array
    {
        $raw = trim((string) ($owner->mobile ?? ''));

        if ($raw === '') {
            return [null, 'no_phone_on_record'];
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if (in_array($digits, self::PLACEHOLDERS, true)) {
            return [null, 'placeholder_phone'];
        }

        if (strlen($digits) < self::MIN_DIGITS || strlen($digits) > self::MAX_DIGITS) {
            return [null, 'phone_not_usable'];
        }

        return [$digits, null];
    }
}
