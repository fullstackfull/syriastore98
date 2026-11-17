<?php

namespace App\Services\Marketing;

use App\Support\Marketing\Channel;
use App\Support\Marketing\ConsentState;
use App\Support\Marketing\ContactAddress;
use App\Support\Marketing\MessageClass;
use Illuminate\Support\Facades\Log;

/**
 * MAY THIS MESSAGE BE SENT? D8 / GAP-200 / MKT-008.
 *
 * One decision function, called at the SEND BOUNDARY of each channel -- not in a form, not in a
 * template, not in the UI. The owner's direction is that eligibility be enforced where the message
 * actually leaves, because a rule enforced only upstream of the sender is bypassed by every sender that
 * does not go through the upstream code, and this repository has dozens of those.
 *
 * THE RULES, in the order they are applied:
 *
 *   1. mode `off` (the shipped default)  -> ALLOW, and the database is not touched. Production has no
 *      consent tables today; an `off` gate must be inert there, not an outage.
 *   2. a message that is not marketing   -> ALLOW. Transactional and operational messages are never held.
 *      A receipt, a one-time password or a password reset is part of delivering what was asked for,
 *      and the owner's direction is that these must not be blocked indiscriminately.
 *   3. marketing, no store / no usable address / no current permission -> HOLD (or, in `report`,
 *      allow-and-say-so). The only state that permits marketing is an explicit, current `granted`.
 *
 * NOTHING HERE INFERS CONSENT. "This person has ordered from us" and "this contact exists" are not
 * inputs. The only thing that can make rule 3 pass is a `granted` event in the ledger.
 *
 * REPORT MODE LOGS A HASH PREFIX, NEVER AN ADDRESS. The point of report mode is to let an owner see
 * what would be held before holding it; that must not turn the log into a list of customer emails.
 */
final class MarketingSendGate
{
    public const MODE_OFF = 'off';
    public const MODE_REPORT = 'report';
    public const MODE_ENFORCE = 'enforce';

    public function __construct(private readonly ConsentLedger $ledger) {}

    /** The configured mode. An unrecognised value is `report`: visible, and neither disabling nor enforcing by accident. */
    public function mode(): string
    {
        $configured = strtolower(trim((string) config('marketing_consent.enforcement', self::MODE_OFF)));

        return match ($configured) {
            self::MODE_OFF, self::MODE_REPORT, self::MODE_ENFORCE => $configured,
            default => self::MODE_REPORT,
        };
    }

    public function decide(int $storeId, Channel $channel, MessageClass $class, ?string $address): SendDecision
    {
        $mode = $this->mode();

        // 1. Inert unless switched on. No query is issued on this path.
        if ($mode === self::MODE_OFF) {
            return SendDecision::allow('enforcement_off', $mode);
        }

        // 2. Only marketing needs a permission.
        if (! $class->needsConsent()) {
            return SendDecision::allow('not_marketing', $mode);
        }

        // 3. Marketing. Work out why it would be held, if it would.
        $reason = $this->holdReason($storeId, $channel, $address);

        if ($reason === null) {
            return SendDecision::allow('consent_granted', $mode);
        }

        if ($mode === self::MODE_ENFORCE) {
            return SendDecision::hold($reason, $mode);
        }

        // report: the send goes, and the owner is told what enforcement would have done.
        $this->reportWouldHold($storeId, $channel, $address, $reason);

        return SendDecision::allowButWouldHold($reason, $mode);
    }

    private function holdReason(int $storeId, Channel $channel, ?string $address): ?string
    {
        if ($storeId <= 0) {
            return 'no_store';
        }

        if (ContactAddress::normalise($channel, $address) === null) {
            return 'address_unusable';
        }

        $state = $this->ledger->stateOf($storeId, $channel, $address);

        return match ($state) {
            ConsentState::Granted => null,
            ConsentState::Withdrawn => 'withdrawn',
            ConsentState::Suppressed => 'suppressed',
            ConsentState::None => 'no_consent_recorded',
        };
    }

    private function reportWouldHold(int $storeId, Channel $channel, ?string $address, string $reason): void
    {
        $normalised = ContactAddress::normalise($channel, $address);

        Log::warning('Marketing message would be held by consent enforcement (report mode; it was sent)', [
            'store_id' => $storeId,
            'channel' => $channel->value,
            'reason' => $reason,
            'contact' => $normalised === null ? null : substr(ContactAddress::hash($storeId, $channel, $normalised), 0, 12),
        ]);
    }
}
