<?php

namespace App\Services\Marketing;

use App\Support\Marketing\Channel;
use App\Support\Marketing\ConsentState;
use App\Support\Marketing\ContactAddress;
use App\Support\Marketing\MessageClass;
use Illuminate\Support\Facades\Log;
use Throwable;

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
 *   4. marketing, and the consent store CANNOT BE READ -> HOLD in `enforce` (consent cannot be verified, and a marketing
 *      message needs it) and ALLOW in `report` (report never stops a send), with a log line that names the exception CLASS
 *      and nothing else: a database exception's message carries the failing statement's values, including the address hash.
 *
 * THE MODE IS PER CHANNEL FOR ONE REASON: `enforce` is not honoured for SMS and WhatsApp while phone identity is not canonical
 * (config/marketing_consent.php). It is read as `report` there, and says so in the log.
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

    /** The reason when the consent store could not be read at all. */
    public const STORE_UNAVAILABLE = 'consent_store_unavailable';

    public function __construct(private readonly ConsentLedger $ledger) {}

    /** Spellings that can only mean "not on". An empty value is what Laravel's env() makes of the literal `false`. */
    private const OFF_SPELLINGS = ['', 'off', 'false', '0', 'no', 'disabled', 'none', 'null'];

    /**
     * The mode in force, for a channel. An unrecognised value is `report`: visible, and neither disabling the control nor stopping
     * a merchant's mail by accident. A value that can only mean "off" is `off` -- see config/marketing_consent.php -- and `enforce` is
     * read as `report` for a channel whose address identity is not canonical yet.
     */
    public function mode(?Channel $channel = null): string
    {
        $configured = strtolower(trim((string) config('marketing_consent.enforcement', self::MODE_OFF)));

        $mode = match (true) {
            in_array($configured, self::OFF_SPELLINGS, true) => self::MODE_OFF,
            $configured === self::MODE_ENFORCE => self::MODE_ENFORCE,
            default => self::MODE_REPORT,
        };

        if ($mode === self::MODE_ENFORCE && $channel?->isPhoneNumber() && config('marketing_consent.phone_identity_canonical') !== true) {
            return self::MODE_REPORT;
        }

        return $mode;
    }

    public function decide(int $storeId, Channel $channel, MessageClass $class, ?string $address): SendDecision
    {
        $mode = $this->mode($channel);

        // 1. Inert unless switched on. No query is issued on this path.
        if ($mode === self::MODE_OFF) {
            return SendDecision::allow('enforcement_off', $mode);
        }

        // 2. Only marketing needs a permission.
        if (! $class->needsConsent()) {
            return SendDecision::allow('not_marketing', $mode);
        }

        // 3. Marketing. Work out why it would be held, if it would -- and if the store cannot be read, that is the reason.
        try {
            $reason = $this->holdReason($storeId, $channel, $address);
        } catch (Throwable $e) {
            $reason = self::STORE_UNAVAILABLE;

            // The CLASS only. A QueryException's message is the failing statement with its values substituted, including the hash.
            Log::warning('Marketing consent store could not be read', [
                'store_id' => $storeId,
                'channel' => $channel->value,
                'mode' => $mode,
                'exception' => get_class($e),
            ]);
        }

        if ($reason === null) {
            return SendDecision::allow('consent_granted', $mode);
        }

        if ($mode === self::MODE_ENFORCE) {
            Log::warning('Marketing message held by consent enforcement', $this->logContext($storeId, $channel, $address, $reason, $mode));

            return SendDecision::hold($reason, $mode);
        }

        // report: the send goes, and the owner is told what enforcement would have done.
        Log::warning('Marketing message would be held by consent enforcement (report mode; it was sent)', $this->logContext($storeId, $channel, $address, $reason, $mode));

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

        // ONE SOURCE OF TRUTH for "may this person be marketed to": the state decides, not a second restatement of it here. The `match`
        // only names the reason, and a state it does not know yet is held as "no consent recorded" rather than thrown out of the send path.
        if ($state->permitsMarketing()) {
            return null;
        }

        return match ($state) {
            ConsentState::Withdrawn => 'withdrawn',
            ConsentState::Suppressed => 'suppressed',
            default => 'no_consent_recorded',
        };
    }

    /**
     * What a gate log line may say: the store, the channel, why, the mode, and a 12-character PREFIX of the contact's hash -- enough to
     * correlate lines about one contact, never an address.
     *
     * @return array<string, mixed>
     */
    private function logContext(int $storeId, Channel $channel, ?string $address, string $reason, string $mode): array
    {
        $normalised = ContactAddress::normalise($channel, $address);

        return [
            'store_id' => $storeId,
            'channel' => $channel->value,
            'reason' => $reason,
            'mode' => $mode,
            'contact' => $normalised === null ? null : substr(ContactAddress::hash($storeId, $channel, $normalised), 0, 12),
        ];
    }
}
