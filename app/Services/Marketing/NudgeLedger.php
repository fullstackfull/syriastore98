<?php

namespace App\Services\Marketing;

use App\Models\MarketingNudgeSend;
use App\Support\Marketing\NudgeDecision;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GAP-219 — THE NUDGE LEDGER: a record of every shopper nudge, and the duplicate guard over it.
 * =================================================================================================
 *
 * Four endpoints send a shopper a nudge about an abandoned cart or wishlist. None of them records
 * that it did, and none carries a throttle, so a merchant who double-clicks sends twice and pays
 * twice. This class is the record, and the guard is a consequence of keeping it honestly.
 *
 * WHAT MAKES THIS "IDEMPOTENT" WITHOUT INVENTING A FREQUENCY, which is the constraint the whole
 * design turns on. The key is derived from the LOGICAL ACTION -- store, channel, template, contact
 * and subject -- and contains no clock. Two clicks on the same cart row produce the same key, so
 * the second is a duplicate by identity rather than by recency. Nothing here decides how often a
 * shopper may be messaged; that is a marketing policy and the owner's to set. `NudgeThrottle` holds
 * that limb and leaves it switched off.
 *
 * THE UNIQUE KEY IS THE GUARD, NOT THE READ. A read-then-write would lose a genuine race: two
 * requests both see no row, both send. So the insert is attempted FIRST and a unique-key violation
 * is what tells us somebody else got there -- the same shape `ConsentLedger` uses, and the same
 * reason. `recordSend()` therefore returns a decision rather than a boolean: "I reserved this, go
 * ahead" or "somebody already has, here is when".
 *
 * IT FAILS OPEN ON A MISSING TABLE, AND THAT IS A DELIBERATE, NARROW CHOICE. The migration is
 * approved for development and isolated testing only, so in production the table may not exist
 * yet. If it does not, `available()` is false and the guard abstains: a nudge still goes out
 * exactly as it does today. Failing CLOSED would mean this unreleased table silently disables four
 * working merchant features the moment the code ships without the migration -- turning a cost
 * control into an outage. The posture is recorded here rather than left to be inferred, and
 * `NudgeLedgerTest` drives it.
 *
 * THE STORE IS ALWAYS EXPLICIT. `marketing_nudge_sends` is not in `config/tenancy.php`'s
 * `scoped_models`, and `null_tenant => 'open'` means a global scope would add NO predicate when no
 * tenant resolves. Every query below carries `$storeId`, passed from the caller's own
 * `getCurrentStore()`; none reads it from request input.
 *
 * THE CONTACT IS HASHED, never stored. The hash answers "is this the same person" without making
 * this table a second copy of the customer contact list. Normalisation strips everything but digits
 * for a phone and lowercases an e-mail, so `+963 11 222` and `963-11-222` are one contact -- which
 * matters, because otherwise a merchant could send twice by typing the number differently.
 */
final class NudgeLedger
{
    /** Is the ledger's table present? See the class docblock on failing open. */
    public function available(): bool
    {
        return Schema::hasTable('marketing_nudge_sends');
    }

    /**
     * THE IDEMPOTENCY KEY, derived from the action and nothing else.
     *
     * No clock, no random, no request id: the same logical nudge must produce the same key on two
     * separate requests or the guard does nothing. The subject is included so nudging a DIFFERENT
     * basket of the same shopper is a different action, which it is.
     */
    public function keyFor(
        string $channel,
        string $template,
        string $contactHash,
        ?string $subjectType = null,
        ?int $subjectId = null
    ): string {
        return hash('sha256', implode('|', [
            'nudge.v1',
            strtolower(trim($channel)),
            strtolower(trim($template)),
            $contactHash,
            strtolower(trim((string) $subjectType)),
            (string) ($subjectId ?? 0),
        ]));
    }

    /**
     * SHA-256 of the normalised address.
     *
     * Normalisation is why this is a method rather than a one-liner at each call site: a phone keeps
     * only its digits and an e-mail is lowercased and trimmed, so two spellings of one contact
     * cannot each get their own nudge. An empty address returns an empty hash, which callers treat
     * as "no contact" rather than as a contact whose hash happens to be that.
     */
    public function hashContact(string $channel, ?string $address): string
    {
        $address = trim((string) $address);

        if ($address === '') {
            return '';
        }

        $normalised = strtolower(trim($channel)) === 'email'
            ? strtolower($address)
            : (string) preg_replace('/\D+/', '', $address);

        return $normalised === '' ? '' : hash('sha256', $normalised);
    }

    /**
     * Reserve this nudge, or report that it is already taken.
     *
     * THE INSERT IS THE LOCK. On success the caller may send. On a unique-key violation somebody
     * else reserved the same action -- possibly microseconds ago in a parallel request -- so the
     * winner's row is read and returned as a duplicate. `sent_at` is stamped at reservation rather
     * than after the send because the row's job is to stop a SECOND send; a row written only after
     * a successful send would leave the race window open for exactly as long as the send takes,
     * which for an HTTP call to a WhatsApp gateway is the whole problem.
     *
     * @return NudgeDecision allowed() true means "reserved, go ahead"
     */
    public function reserve(
        int $storeId,
        string $channel,
        string $template,
        string $contactHash,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?int $createdBy = null,
        string $messageClass = 'marketing'
    ): NudgeDecision {
        if (! $this->available() || $storeId <= 0 || $contactHash === '') {
            return NudgeDecision::allow();
        }

        $key = $this->keyFor($channel, $template, $contactHash, $subjectType, $subjectId);

        try {
            $row = new MarketingNudgeSend();
            $row->channel = $channel;
            $row->message_class = $messageClass;
            $row->template = $template;
            $row->contact_hash = $contactHash;
            $row->subject_type = $subjectType;
            $row->subject_id = $subjectId;
            $row->sent_at = CarbonImmutable::now();

            // Ownership and integrity, set as properties: none of these four is fillable.
            $row->store_id = $storeId;
            $row->created_by = $createdBy;
            $row->idempotency_key = $key;
            $row->outcome = MarketingNudgeSend::OUTCOME_SENT;

            $row->save();

            return NudgeDecision::allow();
        } catch (QueryException $e) {
            /*
             * A unique-key violation is the EXPECTED path for a double-click, not an error. Any
             * other query failure is rethrown: swallowing it would turn a broken ledger into a
             * silent "allow", which is the failure mode this class exists to prevent.
             */
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            $existing = MarketingNudgeSend::query()
                ->where('store_id', $storeId)
                ->where('idempotency_key', $key)
                ->first();

            return NudgeDecision::duplicate($existing?->sent_at);
        }
    }

    /**
     * Record a refusal, so the ledger holds why nothing arrived.
     *
     * A ledger of successes only cannot answer a merchant asking why a shopper never got a message,
     * and "we declined" is the answer in four of the five cases `blockedReason()` covers. The write
     * is best-effort: a refusal that cannot be recorded must not become an exception on a path the
     * merchant is already being refused on.
     */
    public function recordRefusal(
        int $storeId,
        string $channel,
        string $template,
        string $contactHash,
        string $outcome,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?int $createdBy = null
    ): void {
        if (! $this->available() || $storeId <= 0) {
            return;
        }

        try {
            DB::table('marketing_nudge_sends')->insert([
                'store_id' => $storeId,
                'channel' => $channel,
                'message_class' => 'marketing',
                'template' => $template,
                'contact_hash' => $contactHash,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                /*
                 * A REFUSAL MUST NOT COLLIDE WITH THE RESERVATION IT IS ABOUT, and must not block a
                 * later legitimate send. So its key is salted with the outcome and a timestamp:
                 * refusals are a log, not a reservation, and two refusals of the same action are
                 * two facts worth keeping.
                 */
                'idempotency_key' => hash('sha256', implode('|', [
                    'refusal',
                    $outcome,
                    $this->keyFor($channel, $template, $contactHash, $subjectType, $subjectId),
                    (string) CarbonImmutable::now()->getPreciseTimestamp(),
                ])),
                'outcome' => $outcome,
                'sent_at' => null,
                'created_by' => $createdBy,
                'created_at' => CarbonImmutable::now(),
                'updated_at' => CarbonImmutable::now(),
            ]);
        } catch (QueryException $e) {
            // Best effort by design; see the method docblock.
        }
    }

    /**
     * When this contact was last really sent a nudge on this channel, or null.
     *
     * Only `sent` rows count: a `duplicate_suppressed` or `throttled` row means nothing arrived, and
     * treating one as a send would make the first refusal extend the quiet period — a guard that
     * punishes the merchant for having been refused.
     */
    public function lastSentAt(int $storeId, string $channel, string $contactHash): ?CarbonImmutable
    {
        if (! $this->available() || $storeId <= 0 || $contactHash === '') {
            return null;
        }

        $sentAt = MarketingNudgeSend::query()
            ->where('store_id', $storeId)
            ->where('channel', $channel)
            ->where('contact_hash', $contactHash)
            ->whereIn('outcome', MarketingNudgeSend::deliveredOutcomes())
            ->whereNotNull('sent_at')
            ->max('sent_at');

        return $sentAt === null ? null : CarbonImmutable::parse($sentAt);
    }

    /**
     * MySQL 1062 / SQLite 23000 / Postgres 23505 — the same detection `ConsentLedger` uses.
     *
     * Matching on the driver code rather than on the message text, because the text is localised by
     * the server and carries the index name, neither of which is stable.
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $driverCode = (string) ($e->errorInfo[1] ?? '');

        return $sqlState === '23000'
            || $sqlState === '23505'
            || $driverCode === '1062'
            || $driverCode === '19';
    }
}
