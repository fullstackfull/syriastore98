<?php

namespace App\Services\Marketing;

use App\Models\MarketingConsentEvent;
use App\Models\MarketingContactState;
use App\Support\Marketing\Channel;
use App\Support\Marketing\ConsentEvent;
use App\Support\Marketing\ConsentState;
use App\Support\Marketing\ContactAddress;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * THE ONLY WRITER OF MARKETING CONSENT. D8 / GAP-200 / MKT-008.
 *
 * Every method takes an explicit `$storeId`. The tenant scope is deliberately NOT relied on, for the
 * reason `config/tenancy.php` gives at length and the CLAUDE.md "tenancy" notes repeat: with
 * `null_tenant => open` the scope adds no predicate for a principal that resolves no tenant, and a
 * predicate built from a caller-supplied value only compares the caller's own two values. A consent
 * decision for store B must never be answerable from a request that belongs to store A, so the store
 * is an argument, never an ambient.
 *
 * WHAT THIS NEVER DOES: infer. Nothing here is called from an order, a registration, a customer
 * import or an address book, and `ConsentLedgerCensusTest` fails if a call to a granting method
 * appears outside the explicit opt-in surfaces it lists. Having bought something, or having been
 * added by a merchant, is not permission to be marketed to.
 *
 * ONE EVENT + ONE PROJECTION UPDATE, IN ONE TRANSACTION. The projection is `marketing_contact_states`.
 * The event row is evidence and is never edited; the projection is a convenience that
 * {@see self::rebuildState()} can recompute from the events so the two can be compared.
 */
final class ConsentLedger
{
    public const PURPOSE_MARKETING = 'marketing';

    /** An explicit opt-in. `$source` says where the person said yes; `$evidence` is the proof. */
    public function grant(
        int $storeId,
        Channel $channel,
        string $address,
        string $source,
        array $evidence = [],
        ?int $customerId = null,
        ?string $actorType = null,
        ?int $actorId = null,
        string $purpose = self::PURPOSE_MARKETING,
    ): MarketingConsentEvent {
        return $this->recordForAddress(ConsentEvent::Granted, $storeId, $channel, $address, $source, $evidence, $customerId, $actorType, $actorId, $purpose);
    }

    /** The person (or a merchant on their behalf, with `$actorType = 'merchant'`) withdrew. */
    public function withdraw(
        int $storeId,
        Channel $channel,
        string $address,
        string $source,
        array $evidence = [],
        ?int $customerId = null,
        ?string $actorType = null,
        ?int $actorId = null,
        string $purpose = self::PURPOSE_MARKETING,
    ): MarketingConsentEvent {
        return $this->recordForAddress(ConsentEvent::Withdrawn, $storeId, $channel, $address, $source, $evidence, $customerId, $actorType, $actorId, $purpose);
    }

    /** A HARD block (complaint, legal request, bounce). Outranks a later opt-in until explicitly lifted. */
    public function suppress(
        int $storeId,
        Channel $channel,
        string $address,
        string $source,
        array $evidence = [],
        ?int $customerId = null,
        ?string $actorType = null,
        ?int $actorId = null,
        string $purpose = self::PURPOSE_MARKETING,
    ): MarketingConsentEvent {
        return $this->recordForAddress(ConsentEvent::Suppressed, $storeId, $channel, $address, $source, $evidence, $customerId, $actorType, $actorId, $purpose);
    }

    /** Lifts a suppression. It does NOT grant: the person returns to `withdrawn` and must opt in afresh. */
    public function liftSuppression(
        int $storeId,
        Channel $channel,
        string $address,
        string $source,
        array $evidence = [],
        ?int $customerId = null,
        ?string $actorType = null,
        ?int $actorId = null,
        string $purpose = self::PURPOSE_MARKETING,
    ): MarketingConsentEvent {
        return $this->recordForAddress(ConsentEvent::SuppressionLifted, $storeId, $channel, $address, $source, $evidence, $customerId, $actorType, $actorId, $purpose);
    }

    /**
     * Withdrawal from a HASH -- the unsubscribe path, where the token names the contact but the address
     * is deliberately not stored. Withdrawal only: nothing that starts from a hash may grant.
     */
    public function withdrawByHash(
        int $storeId,
        Channel $channel,
        string $addressHash,
        ?string $addressHint,
        string $source,
        array $evidence = [],
        ?int $customerId = null,
        string $purpose = self::PURPOSE_MARKETING,
    ): MarketingConsentEvent {
        return $this->record(ConsentEvent::Withdrawn, $storeId, $channel, $addressHash, $addressHint, $source, $evidence, $customerId, 'customer', null, $purpose);
    }

    /** The current state of one contact. A missing row, or an address that cannot be one, is `None` -- which is NOT consent. */
    public function stateOf(int $storeId, Channel $channel, ?string $address, string $purpose = self::PURPOSE_MARKETING): ConsentState
    {
        if ($storeId <= 0) {
            return ConsentState::None;
        }

        $normalised = ContactAddress::normalise($channel, $address);

        if ($normalised === null) {
            return ConsentState::None;
        }

        $row = MarketingContactState::query()
            ->where('store_id', $storeId)
            ->where('channel', $channel->value)
            ->where('purpose', $purpose)
            ->where('address_hash', ContactAddress::hash($storeId, $channel, $normalised))
            ->first();

        return $row === null ? ConsentState::None : (ConsentState::tryFrom((string) $row->status) ?? ConsentState::None);
    }

    /**
     * The state recomputed from the EVIDENCE alone, ignoring the projection. For audits and tests: if
     * this and {@see self::stateOf()} ever differ, the events are right and the projection is wrong.
     */
    public function rebuildState(int $storeId, Channel $channel, string $address, string $purpose = self::PURPOSE_MARKETING): ConsentState
    {
        $normalised = ContactAddress::normalise($channel, $address);

        if ($storeId <= 0 || $normalised === null) {
            return ConsentState::None;
        }

        $state = ConsentState::None;

        MarketingConsentEvent::query()
            ->where('store_id', $storeId)
            ->where('channel', $channel->value)
            ->where('purpose', $purpose)
            ->where('address_hash', ContactAddress::hash($storeId, $channel, $normalised))
            ->orderBy('id')
            ->get()
            ->each(function (MarketingConsentEvent $event) use (&$state) {
                $kind = ConsentEvent::tryFrom((string) $event->event);

                if ($kind !== null) {
                    $state = $state->after($kind);
                }
            });

        return $state;
    }

    private function recordForAddress(
        ConsentEvent $event,
        int $storeId,
        Channel $channel,
        string $address,
        string $source,
        array $evidence,
        ?int $customerId,
        ?string $actorType,
        ?int $actorId,
        string $purpose,
    ): MarketingConsentEvent {
        $normalised = ContactAddress::normalise($channel, $address);

        if ($normalised === null) {
            throw new InvalidArgumentException('That is not a valid '.$channel->value.' address, so no consent can be recorded against it.');
        }

        if ($storeId <= 0) {
            throw new InvalidArgumentException('A consent decision belongs to a store.');
        }

        return $this->record(
            $event,
            $storeId,
            $channel,
            ContactAddress::hash($storeId, $channel, $normalised),
            ContactAddress::mask($channel, $normalised),
            $source,
            $evidence,
            $customerId,
            $actorType,
            $actorId,
            $purpose,
        );
    }

    private function record(
        ConsentEvent $event,
        int $storeId,
        Channel $channel,
        string $addressHash,
        ?string $addressHint,
        string $source,
        array $evidence,
        ?int $customerId,
        ?string $actorType,
        ?int $actorId,
        string $purpose,
    ): MarketingConsentEvent {
        if ($storeId <= 0) {
            throw new InvalidArgumentException('A consent decision belongs to a store.');
        }

        if (! preg_match('/^[0-9a-f]{64}$/', $addressHash)) {
            throw new InvalidArgumentException('An address hash is 64 lowercase hex characters.');
        }

        if (trim($source) === '') {
            throw new InvalidArgumentException('A consent event must say where the decision was made.');
        }

        return DB::transaction(function () use ($event, $storeId, $channel, $addressHash, $addressHint, $source, $evidence, $customerId, $actorType, $actorId, $purpose) {
            $row = MarketingConsentEvent::create([
                'store_id' => $storeId,
                'channel' => $channel->value,
                'purpose' => $purpose,
                'address_hash' => $addressHash,
                'address_hint' => $addressHint,
                'customer_id' => $customerId,
                'event' => $event->value,
                'source' => $source,
                'evidence' => $evidence === [] ? null : $evidence,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'occurred_at' => now(),
                'created_at' => now(),
            ]);

            $this->project($event, $row, $storeId, $channel, $addressHash, $purpose);

            return $row;
        });
    }

    private function project(ConsentEvent $event, MarketingConsentEvent $row, int $storeId, Channel $channel, string $addressHash, string $purpose): void
    {
        $key = [
            'store_id' => $storeId,
            'channel' => $channel->value,
            'purpose' => $purpose,
            'address_hash' => $addressHash,
        ];

        $current = MarketingContactState::query()->where($key)->lockForUpdate()->first();

        if ($current === null) {
            try {
                MarketingContactState::create($key + [
                    'status' => ConsentState::None->after($event)->value,
                    'last_event_id' => $row->id,
                ]);

                return;
            } catch (UniqueConstraintViolationException) {
                // A concurrent writer created the row between our read and our insert. Take theirs, under a lock.
                $current = MarketingContactState::query()->where($key)->lockForUpdate()->firstOrFail();
            }
        }

        $before = ConsentState::tryFrom((string) $current->status) ?? ConsentState::None;

        $current->forceFill([
            'status' => $before->after($event)->value,
            'last_event_id' => $row->id,
        ])->save();
    }
}
