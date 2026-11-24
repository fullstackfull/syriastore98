<?php

namespace App\Console\Commands;

use App\Models\MarketingRecoveryCandidate;
use App\Models\Setting;
use App\Models\Store;
use App\Services\Marketing\MarketingSendGate;
use App\Services\Marketing\NudgeThrottle;
use App\Support\Marketing\RecoveryCandidates;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

/**
 * MKT-007 — the scheduled sweep: find what is worth recovering, queue it, and SEND NOTHING.
 *
 * WHAT THIS COMMAND IS FOR. MKT-007's measurement was that recovery is one merchant click per cart
 * row: nobody looks for an abandoned basket or an unpaid order unless a person remembers to open a
 * screen. This looks, hourly, and writes what it finds to a queue.
 *
 * **IT DOES NOT SEND, AND THAT IS THE OWNER'S INSTRUCTION RATHER THAN AN UNFINISHED EDGE.**
 * Automatic promotional sending stays disabled until the required consent enforcement is approved
 * and active. So the send step is written, it asks both gates, and both of them say no today:
 * `MarketingSendGate::mode()` is `off` (`config/marketing_consent.php`) and
 * `NudgeThrottle::minIntervalSeconds()` is null because no cadence has been approved (GAP-219). Every
 * row is therefore written `held`, WITH THE REASON IN THE ROW, so that "nothing was sent" is a fact
 * a reader can check rather than an inference from an empty log.
 *
 * WHY THE QUEUE EXISTS AT ALL IF NOTHING IS SENT -- the question worth answering in advance. Because
 * the day sending is approved, a pipeline with no memory would discover every abandoned basket in
 * the store's history and message all of them at once. A queue whose rows carry `detected_at` lets
 * that backlog be released deliberately, aged out, or ignored. Building the memory now is what makes
 * switching sending on a decision rather than an incident.
 *
 * DEDUPLICATION HERE IS NOT THE SEND-STEP DEDUPLICATION. `NudgeLedger` answers "have I already SENT
 * this message", keyed on the logical action with no clock in it, and it is already on. This answers
 * "have I already QUEUED this subject", through the unique key on (store, kind, subject). Twenty-four
 * hourly ticks over one abandoned basket produce ONE row.
 *
 * IT VISITS ONLY STORES THAT HAVE CONFIGURED AN AGE, which is a correctness property and not an
 * optimisation: without an age there is nothing to be older than, so a store that has configured
 * nothing has no candidates. There is no default age anywhere in this feature.
 */
class DetectRecoveryCandidates extends Command
{
    protected $signature = 'marketing:detect-recovery-candidates';

    protected $description = 'Queue the baskets and unpaid orders worth recovering. Sends nothing (MKT-007).';

    public function handle(MarketingSendGate $gate, NudgeThrottle $throttle): int
    {
        $storeIds = Setting::whereIn('name', array_column(RecoveryCandidates::KINDS, 'setting'))
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->distinct()
            ->pluck('store_id')
            ->filter(static fn ($id): bool => (int) $id > 0)
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($storeIds === []) {
            $this->info('No store has configured a recovery age, so there is nothing to look for.');

            return self::SUCCESS;
        }

        /*
         * THE SEND DECISION IS TAKEN ONCE, BEFORE THE LOOP, and it is the same for every store
         * because both gates are platform-wide configuration. Asking per candidate would imply the
         * answer could differ per basket, which it cannot today and which would be a misleading
         * shape to leave behind.
         */
        $heldReason = $this->heldReason($gate, $throttle);

        $queued = 0;
        $checked = 0;

        foreach ($storeIds as $storeId) {
            if (! Store::where('id', $storeId)->exists()) {
                // Settings can outlive their store; a candidate for a deleted store has nobody to
                // recover anything for.
                continue;
            }

            $checked++;

            foreach (RecoveryCandidates::forStore($storeId) as $candidate) {
                if ($this->queue($storeId, $candidate, $heldReason)) {
                    $queued++;
                }
            }
        }

        $this->info(sprintf(
            'Checked %d store(s); queued %d new candidate(s). Nothing was sent: %s.',
            $checked,
            $queued,
            $heldReason ?? 'both gates would allow a send, but this command does not send'
        ));

        return self::SUCCESS;
    }

    /**
     * Which gate holds a send today, or null if neither does.
     *
     * THE ORDER IS CONSENT FIRST, deliberately: consent is a legal and ethical constraint and the
     * cadence is a marketing preference, so a reader of the queue should see the stronger reason when
     * both apply. `MarketingSendGate::mode()` is read rather than `decide()` because there is no
     * address to decide about here -- this command establishes that sending is OFF, not whether one
     * particular person may be written to.
     */
    private function heldReason(MarketingSendGate $gate, NudgeThrottle $throttle): ?string
    {
        if ($gate->mode() !== MarketingSendGate::MODE_ENFORCE) {
            return MarketingRecoveryCandidate::HELD_CONSENT_OFF;
        }

        if ($throttle->minIntervalSeconds() === null) {
            return MarketingRecoveryCandidate::HELD_NO_CADENCE;
        }

        return null;
    }

    /**
     * Write one candidate, or report that it was already queued.
     *
     * THE INSERT IS THE DEDUPLICATION, not a preceding read. A read-then-write leaves a window two
     * overlapping sweeps can both pass through -- `withoutOverlapping()` on the schedule makes that
     * unlikely rather than impossible, since a lock can expire while a slow sweep is still running.
     * The unique key refuses the second row and the `QueryException` is the answer, which is the
     * same reasoning `NudgeLedger::reserve()` records for the same reason.
     *
     * AN ALREADY-QUEUED CANDIDATE IS NOT UPDATED. Its `detected_at` is when it was FIRST seen, and
     * refreshing it on every tick would make the queue's age always "now" -- destroying the one
     * piece of information a backlog release would need.
     */
    private function queue(int $storeId, array $candidate, ?string $heldReason): bool
    {
        $row = new MarketingRecoveryCandidate();
        $row->store_id = $storeId;
        $row->kind = $candidate['kind'];
        $row->subject_type = $candidate['subject_type'];
        $row->subject_id = $candidate['subject_id'];
        $row->customer_id = $candidate['customer_id'];
        $row->detected_at = CarbonImmutable::now();
        $row->send_state = MarketingRecoveryCandidate::STATE_HELD;
        $row->held_reason = $heldReason;

        try {
            $row->save();
        } catch (QueryException $e) {
            // Already queued. Anything other than the unique key is a real fault and must not be
            // swallowed: a sweep that silently loses rows is worse than one that stops.
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            return false;
        }

        return true;
    }

    /**
     * SQLSTATE 23000/23505 across the engines this project runs on.
     *
     * Matched on the SQLSTATE rather than the driver message, because the message differs between
     * MySQL ("Duplicate entry"), MariaDB and SQLite ("UNIQUE constraint failed") and a message match
     * would pass the suite and fail in production -- the shape of defect this project keeps finding.
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        return in_array((string) $e->getCode(), ['23000', '23505'], true);
    }
}
