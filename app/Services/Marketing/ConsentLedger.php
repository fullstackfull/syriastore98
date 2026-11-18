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
 * ONE EVENT + ONE PROJECTION UPDATE, IN ONE TRANSACTION, **LOCK FIRST**. The projection is
 * `marketing_contact_states`. The event row is evidence and is never edited; the projection is a convenience
 * that {@see self::rebuildState()} recomputes from the events and {@see self::divergences()} compares.
 *
 * THE ORDER OF THE TWO WRITES IS THE WHOLE CORRECTNESS ARGUMENT, and the first version had it backwards. An event
 * gets its id when it is INSERTed, and a replay folds the log in id order. If the projection row were locked AFTER
 * the INSERT, two overlapping requests for one contact would be folded into the projection in LOCK order, which is
 * not id order: a withdrawal with the higher id could be folded first and then overwritten by an earlier grant,
 * leaving the log saying `withdrawn` and the row the send gate reads saying `granted`. Measured on MariaDB 10.11 with
 * two real processes (`verify-consent-ledger-concurrency-on-mysql.php`): log `1:granted 2:granted 3:withdrawn`,
 * projection `granted`, replay `withdrawn`. So the row is locked FIRST, the event is inserted while the lock is held,
 * and the fold happens before the lock is released: id order, lock order and fold order are then the same thing.
 *
 * A FIRST-EVER EVENT FOR A CONTACT HAS NO ROW TO LOCK. Two such writers both read the absent key under `FOR UPDATE`,
 * which at REPEATABLE READ (the server default; nothing in config/database.php changes it) takes a gap lock, so both
 * then INSERT the row and each waits for the other's gap lock: InnoDB kills one (error 1213) and its whole transaction,
 * event included, is rolled back. Measured the same way. The transaction is therefore RETRIED; the second attempt finds
 * the row the winner created and waits on it like any other writer. Under READ COMMITTED the loser instead meets the
 * unique key, and is handled below. A retry needs the ledger to own the outermost transaction: inside a caller's
 * transaction Laravel raises a `DeadlockException` instead of retrying, so a seam must call this OUTSIDE any transaction
 * that can fail afterwards (a withdrawal recorded inside a checkout that then rolls back is a withdrawal not recorded).
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

        // Keyset-paged, so a contact with a long history is folded without being held in memory all at once.
        MarketingConsentEvent::query()
            ->where('store_id', $storeId)
            ->where('channel', $channel->value)
            ->where('purpose', $purpose)
            ->where('address_hash', ContactAddress::hash($storeId, $channel, $normalised))
            ->lazyById(500)
            ->each(function (MarketingConsentEvent $event) use (&$state) {
                $kind = ConsentEvent::tryFrom((string) $event->event);

                if ($kind !== null) {
                    $state = $state->after($kind);
                }
            });

        return $state;
    }

    /**
     * EVERY CONTACT OF ONE STORE WHOSE PROJECTION DISAGREES WITH ITS EVIDENCE. Read-only.
     *
     * "If they disagree the events are right" is only a safety net if something can tell that they disagree, and the first
     * version had no way to: {@see self::rebuildState()} needs the RAW address, which is deliberately not stored, so it can
     * never be asked about a contact it was not already told about. This works from the hashes the tables do hold. It folds
     * the whole store's log in id order, compares each contact's result with its projection row, and reports:
     *
     *   * a projection that says something else than the log implies (the `1:granted 2:granted 3:withdrawn` -> `granted`
     *     case that the lock-first ordering exists to prevent), and
     *   * a contact with a log and NO projection row, which the gate reads as `none`.
     *
     * It is an audit tool, not a request-path call: it reads the store's entire evidence log. It does NOT repair anything --
     * a repair is a decision about evidence and belongs to a person.
     *
     * @return list<array{channel: string, purpose: string, contact: string, projected: string, replayed: string}>
     *         `contact` is a 12-character hash prefix, never an address.
     */
    public function divergences(int $storeId): array
    {
        if ($storeId <= 0) {
            return [];
        }

        $replayed = [];

        MarketingConsentEvent::query()
            ->where('store_id', $storeId)
            ->lazyById(1000)
            ->each(function (MarketingConsentEvent $event) use (&$replayed) {
                $kind = ConsentEvent::tryFrom((string) $event->event);

                if ($kind === null) {
                    return;
                }

                $contact = $event->channel."\0".$event->purpose."\0".$event->address_hash;
                $replayed[$contact] = ($replayed[$contact] ?? ConsentState::None)->after($kind);
            });

        $projected = [];

        MarketingContactState::query()
            ->where('store_id', $storeId)
            ->lazyById(1000)
            ->each(function (MarketingContactState $row) use (&$projected) {
                $projected[$row->channel."\0".$row->purpose."\0".$row->address_hash] = ConsentState::tryFrom((string) $row->status) ?? ConsentState::None;
            });

        $out = [];

        foreach (array_unique(array_merge(array_keys($replayed), array_keys($projected))) as $contact) {
            $log = $replayed[$contact] ?? ConsentState::None;
            $row = $projected[$contact] ?? ConsentState::None;

            if ($log !== $row) {
                [$channel, $purpose, $hash] = explode("\0", $contact);
                $out[] = [
                    'channel' => $channel,
                    'purpose' => $purpose,
                    'contact' => substr($hash, 0, 12),
                    'projected' => $row->value,
                    'replayed' => $log->value,
                ];
            }
        }

        return $out;
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

    /**
     * A purpose is part of the contact's KEY, so it must be exactly what the column will hold: at 32 characters or fewer, and
     * in a form that cannot alias another purpose under the connection's case-insensitive collation.
     *
     * @throws InvalidArgumentException
     */
    public static function assertPurpose(string $purpose): void
    {
        if (! preg_match('/^[a-z][a-z0-9_]{0,31}$/', $purpose)) {
            throw new InvalidArgumentException('A consent purpose is 1-32 lowercase letters, digits or underscores, starting with a letter.');
        }
    }

    /** Longest `source` / `actor_type` / hint the columns hold. MySQL in the production `strict => false` mode TRUNCATES instead of refusing. */
    private const MAX_SOURCE = 48;

    private const MAX_ACTOR_TYPE = 16;

    private const MAX_HINT = 191;

    /** Bytes of evidence kept. A User-Agent alone can be 8 KB; the point of evidence is to prove the act, not to archive the request. */
    private const MAX_EVIDENCE_BYTES = 4096;

    /** Evidence is flat request metadata. Anything nested deeper than this is a mistake, not proof. */
    private const MAX_EVIDENCE_DEPTH = 6;

    /** The deadlock retries described on the class. */
    private const ATTEMPTS = 3;

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

        // These three go into string(32) / string(48) / string(16) columns. SQLite enforces no length and MySQL in the
        // production non-strict mode silently TRUNCATES, which for `purpose` changes the key (the row written is no longer
        // the row looked up) and would make every later event for the contact fail. Refuse instead of truncating.
        self::assertPurpose($purpose);

        if (trim($source) === '' || mb_strlen($source) > self::MAX_SOURCE) {
            throw new InvalidArgumentException('A consent event must say where the decision was made, in at most '.self::MAX_SOURCE.' characters.');
        }

        if ($actorType !== null && mb_strlen($actorType) > self::MAX_ACTOR_TYPE) {
            throw new InvalidArgumentException('An actor type is at most '.self::MAX_ACTOR_TYPE.' characters.');
        }

        $addressHint = $addressHint === null ? null : mb_substr($addressHint, 0, self::MAX_HINT);
        $evidence = self::evidenceForStorage($evidence);

        $key = [
            'store_id' => $storeId,
            'channel' => $channel->value,
            'purpose' => $purpose,
            'address_hash' => $addressHash,
        ];

        return DB::transaction(function () use ($event, $key, $addressHint, $source, $evidence, $customerId, $actorType, $actorId) {
            // 1. Serialise on the contact BEFORE the event has an id. See the class docblock: this ordering is what makes
            //    the projection equal the replay of the log.
            $state = $this->lockProjection($key);

            // 2. The id is assigned, and the timestamps are taken, while the lock is held.
            $row = MarketingConsentEvent::create($key + [
                'address_hint' => $addressHint,
                'customer_id' => $customerId,
                'event' => $event->value,
                'source' => $source,
                'evidence' => $evidence,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'occurred_at' => now(),
                'created_at' => now(),
            ]);

            // 3. Fold, still under the lock.
            $before = ConsentState::tryFrom((string) $state->status) ?? ConsentState::None;

            $state->forceFill([
                'status' => $before->after($event)->value,
                'last_event_id' => $row->id,
            ])->save();

            return $row;
        }, self::ATTEMPTS);
    }

    /**
     * The projection row for this contact, locked FOR UPDATE and created as `none` if the contact has never been seen.
     *
     * `none` is what the absence of a row already means, so creating it changes no answer; it exists so there is a row
     * to lock. A writer that loses the race to create it meets the unique key (READ COMMITTED) or a deadlock (REPEATABLE
     * READ, which the transaction retry above absorbs) and takes the winner's row under the lock.
     *
     * @param  array{store_id: int, channel: string, purpose: string, address_hash: string}  $key
     */
    private function lockProjection(array $key): MarketingContactState
    {
        $locked = fn (): ?MarketingContactState => MarketingContactState::query()->where($key)->lockForUpdate()->first();

        $current = $locked();

        if ($current !== null) {
            return $current;
        }

        try {
            return MarketingContactState::create($key + [
                'status' => ConsentState::None->value,
                'last_event_id' => 0,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent first writer created it between our read and our insert. Take theirs, under the lock.
            return MarketingContactState::query()->where($key)->lockForUpdate()->firstOrFail();
        }
    }

    /**
     * EVIDENCE MUST NEVER BE THE REASON A WITHDRAWAL IS NOT RECORDED. The column is JSON and the model casts an array
     * with a plain `json_encode`, which THROWS on a byte sequence that is not valid UTF-8 -- and the evidence for an
     * unsubscribe is, by design, the redeeming request's own IP and User-Agent, the least trustworthy bytes in the
     * system. A scanner or a hostile client that sends one bad byte would otherwise turn an unsubscribe into a 500 and
     * leave the person `granted`.
     *
     * So the value is made encodable BEFORE it is encoded, by construction rather than by catching a failure: only strings,
     * integers, booleans, finite floats, null and arrays of those survive. Invalid UTF-8 is substituted (U+FFFD); anything
     * else (an object, a resource, NAN, a structure deeper than {@see self::MAX_EVIDENCE_DEPTH}) becomes null and the record
     * carries a `_note` saying so; an oversized payload is replaced by a marker with its size. Nothing is dropped without a
     * note and nothing here can throw.
     *
     * @param  array<array-key, mixed>  $evidence
     * @return array<array-key, mixed>|null
     */
    private static function evidenceForStorage(array $evidence): ?array
    {
        if ($evidence === []) {
            return null;
        }

        $altered = false;
        $clean = self::encodable($evidence, self::MAX_EVIDENCE_DEPTH, $altered);
        $bytes = strlen((string) json_encode($clean));

        if ($bytes > self::MAX_EVIDENCE_BYTES) {
            return ['_note' => 'evidence exceeded '.self::MAX_EVIDENCE_BYTES.' bytes and was not kept', '_bytes' => $bytes];
        }

        if ($altered) {
            $clean['_note'] = 'part of the evidence could not be kept and was replaced by null';
        }

        return $clean;
    }

    /** @param  bool  $altered  set when something had to be replaced by null (a substituted byte is not an alteration worth a note) */
    private static function encodable(mixed $value, int $depth, bool &$altered): mixed
    {
        return match (true) {
            $value === null, is_bool($value), is_int($value) => $value,
            is_float($value) => is_finite($value) ? $value : self::replaced($altered),
            is_string($value) => self::utf8($value),
            is_array($value) && $depth > 0 => self::encodableArray($value, $depth, $altered),
            default => self::replaced($altered),
        };
    }

    /** @param  array<array-key, mixed>  $value */
    private static function encodableArray(array $value, int $depth, bool &$altered): array
    {
        $out = [];

        foreach ($value as $key => $item) {
            $out[is_int($key) ? $key : self::utf8((string) $key)] = self::encodable($item, $depth - 1, $altered);
        }

        return $out;
    }

    private static function replaced(bool &$altered): mixed
    {
        $altered = true;

        return null;
    }

    private static function utf8(string $text): string
    {
        // `json_encode` with SUBSTITUTE cannot fail on a string; decoding it gives the same text with U+FFFD for each bad byte.
        return (string) json_decode((string) json_encode($text, JSON_INVALID_UTF8_SUBSTITUTE), true);
    }
}
