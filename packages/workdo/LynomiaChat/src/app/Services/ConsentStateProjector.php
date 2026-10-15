<?php

namespace Workdo\LynomiaChat\app\Services;

use Illuminate\Support\Carbon;
use Workdo\LynomiaChat\app\Models\LynomiaConsentState;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;

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
 * THE SCOPE IS THE STORE, THE SUBJECT, THE PURPOSE AND THE CHANNEL -- the same four columns
 * Lynomia's unique key uses. A decision at one store never touches another, and a marketing
 * withdrawal never silently rewrites the transactional row beside it.
 *
 * A REVISION-LESS EVENT IS STILL APPLIED, because a native decision -- a shopper replying STOP
 * on WhatsApp -- carries the native row's id rather than a partner revision, and refusing it
 * for want of a field would mean the opt-out this whole feed exists for never lands. It is
 * ordered on the same column; the ids come from different tables but each is monotonic within
 * its own, and `origin` records which produced it.
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
            'origin' => mb_substr((string) ($payload['origin'] ?? ''), 0, 20) ?: null,
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
     * The four columns that identify a scope, or null when the event does not name one. A
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

        if ($claimedStore !== '' && $claimedStore !== (string) $link->store_id) {
            return null;
        }

        return [
            'store_id' => (int) $link->store_id,
            'external_subject_id' => mb_substr($subject, 0, 128),
            'purpose' => mb_substr($purpose, 0, 20),
            'channel' => mb_substr($channel, 0, 20),
        ];
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
