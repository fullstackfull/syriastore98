<?php

namespace Workdo\LynomiaChat\app\Services;

use Illuminate\Support\Carbon;
use Workdo\LynomiaChat\app\Models\LynomiaConsentState;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Support\StoreIdentity;

/**
 * Records what Lynomia says it now holds, so the merchant screen can show the truth.
 *
 * WHY THIS IS NOT JUST "WRITE THE STATE". An event can arrive late, twice, or out of order --
 * the delivery pipe is at-least-once and the network does not promise sequence. So the
 * projection applies LYNOMIA'S OWN ORDERING KEY: `revision` is the id Lynomia assigned the
 * decision when it accepted it, and an event whose revision is not greater than the one
 * already stored is dropped. That is the same rule Lynomia applies to its own writes, which
 * is what stops the two sides disagreeing about which decision is newest.
 *
 * THE SCOPE IS THE STORE, THE SUBJECT, THE PURPOSE, THE CHANNEL AND THE ORIGIN. A decision at
 * one store never touches another, and a marketing withdrawal never silently rewrites the
 * transactional row beside it.
 *
 * THE ORIGIN IS IN THE SCOPE BECAUSE THE TWO ORIGINS COUNT IN DIFFERENT SEQUENCES, and leaving
 * it out was a defect. Lynomia publishes from two tables: a native decision carries
 * `origin: lynomia_native` and a `commerce_messaging_consents` id as its revision, a partner
 * decision carries `origin: partner` and a `partner_consent_events` id. Each is monotonic
 * within its own table and the two are unrelated, so comparing them decides nothing -- and on
 * one shared row a partner grant at revision 10001 overwrote a native withdrawal at revision
 * 5, which is exactly what the ordering rule exists to prevent. Two sequences cannot be merged
 * into one order, so they are not: each origin keeps its own row and is ordered against itself.
 *
 * WHICH MEANS NO SINGLE ROW IS THE ANSWER. The effective answer is the union of the negatives
 * across origins -- LynomiaConsentState::effectiveFor -- which is the same rule Lynomia
 * applies at its own send boundary.
 *
 * AN ORIGIN-LESS EVENT GETS ITS OWN KEY rather than being guessed into one of the two:
 * guessing reopens the cross-sequence comparison, refusing would drop an opt-out, and an extra
 * row costs nothing because its negative still counts in the union.
 *
 * A REVISION-LESS EVENT IS STILL APPLIED, because refusing it for want of a field would mean
 * the opt-out this whole feed exists for never lands.
 */
class ConsentStateProjector
{
    /** @return bool true when the event changed what this side believes. */
    public function apply(LynomiaStoreLink $link, array $payload): bool
    {
        $scope = $this->scopeOf($link, $payload);

        if ($scope === null) {
            return false;
        }

        $revision = isset($payload['revision']) && is_numeric($payload['revision'])
            ? (int) $payload['revision']
            : null;

        $existing = LynomiaConsentState::where($scope)->first();

        // Already superseded. The newer event won and this one is late; dropping it is what
        // stops a delayed delivery reinstating something a later decision replaced.
        if ($existing !== null && $revision !== null && $existing->revision !== null
            && $revision <= $existing->revision) {
            return false;
        }

        $attributes = [
            'state' => mb_substr((string) ($payload['state'] ?? ''), 0, 20),
            'source' => mb_substr((string) ($payload['source'] ?? ''), 0, 40) ?: null,
            'revision' => $revision,
            'occurred_at' => $this->timestamp($payload['occurred_at'] ?? null),
            'observed_at' => now(),
        ];

        if ($attributes['state'] === '') {
            return false;
        }

        LynomiaConsentState::updateOrCreate($scope, $attributes);

        return true;
    }

    /**
     * The five columns that identify a scope, or null when the event does not name one. A
     * delivery whose store does not match the link it arrived on is dropped: the signature
     * proves who sent it, this proves which installation it may speak for.
     */
    private function scopeOf(LynomiaStoreLink $link, array $payload): ?array
    {
        $subject = (string) ($payload['subject']['external_customer_id'] ?? '');
        $purpose = (string) ($payload['purpose'] ?? '');
        $channel = (string) ($payload['channel'] ?? '');
        $claimedStore = (string) ($payload['store']['external_store_id'] ?? '');

        if ($subject === '' || $purpose === '' || $channel === '') {
            return null;
        }

        // Compared against the QUALIFIED identifier, which is what Lynomia sends. Against
        // the bare store id this never matched, so every inbound consent event was read as
        // speaking for another installation and dropped.
        if ($claimedStore !== '' && $claimedStore !== StoreIdentity::of((int) $link->store_id)) {
            return null;
        }

        return [
            'store_id' => (int) $link->store_id,
            'external_subject_id' => mb_substr($subject, 0, 128),
            'purpose' => mb_substr($purpose, 0, 20),
            'channel' => mb_substr($channel, 0, 20),
            'origin' => $this->originOf($payload),
        ];
    }

    /** Never empty: an unnamed origin is its own key, not a guess at one of the two real ones. */
    private function originOf(array $payload): string
    {
        return mb_substr((string) ($payload['origin'] ?? ''), 0, 20) ?: LynomiaConsentState::ORIGIN_UNKNOWN;
    }

    private function timestamp($value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
