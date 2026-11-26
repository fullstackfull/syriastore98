<?php

namespace App\Services\Kyc;

use App\Models\KycDocument;
use App\Models\KycVerification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * KYC-002's SECOND CLAUSE — المراجعات, THE REVIEWS. ONE DECISION, RECORDED, BY SOMEBODY ENTITLED.
 * =================================================================================================
 *
 * EVERY METHOD ASKS `KycAccess` FIRST AND REFUSES BY THROWING. A refusal is also an access-log row:
 * an attempt to approve somebody's identity papers by a principal who may not is precisely the event
 * KYC-002's access log exists to show, and a refusal that only answered 403 would leave no trace of
 * it.
 *
 * THE GATE IS IN THE SERVICE AND NOT ONLY IN THE ROUTE, on purpose. CLAUDE.md: *"A HANDLER-LEVEL
 * CHECK BEHIND AN `abort()`ING MIDDLEWARE IS INVISIBLE TO AN HTTP TEST"* — the middleware answers
 * 403 and the handler never runs, so the route returns 403 whether or not the handler checks
 * anything, and `PlanController::planDisable` admitted a `Customer` typed `super admin` behind a
 * gate whose HTTP tests were green. The tests for this class therefore call these methods DIRECTLY
 * with each principal, and the route's own middleware is a second layer rather than the only one.
 *
 * APPROVING A VERIFICATION REQUIRES EVERY CONFIGURED KIND TO HAVE AN APPROVED DOCUMENT, and with no
 * kinds configured that condition is vacuously satisfied — which is the honest answer rather than a
 * hidden policy. The owner's document list (`D-P6-46`) is what makes the requirement real; the
 * mechanism enforces whatever is configured and invents nothing. `missingKinds()` is the same answer
 * as a report, so a reviewer sees what is outstanding instead of being refused without a reason.
 *
 * ONE AUTHORITY AT A TIME, WHICH IS KYC-003 AT THE VERB. There is no method that approves "the
 * store"; every method names a record, and a record names an authority. Approving Store Syria's
 * verification writes nothing to the payment-provider or government-licence records, and
 * `KycAuthoritySeparationTest` asserts that in all six ordered pairs.
 */
final class KycReview
{
    public const APPROVE = 'approve';

    public const REJECT = 'reject';

    /** @var list<string> */
    public const DECISIONS = [self::APPROVE, self::REJECT];

    private function __construct()
    {
    }

    /**
     * Decide one document.
     *
     * @param  string|null  $expiresOn  the date on the paper itself, as `Y-m-d`, or null
     *
     * @throws KycRefusal
     */
    public static function decideDocument(
        KycDocument $document,
        mixed $actor,
        string $decision,
        ?string $note = null,
        ?string $expiresOn = null
    ): KycDocument {
        if (! in_array($decision, self::DECISIONS, true)) {
            throw new KycRefusal('decision', (string) __('That is not a decision.'));
        }

        if (! KycAccess::permitsReview($actor)) {
            // The refusal is logged against the document, so an attempt leaves a trace.
            KycDocumentStore::refuse($document, 'not_a_reviewer', $actor instanceof User ? $actor : null);

            throw new KycRefusal('not_a_reviewer', (string) __('You are not allowed to review this document.'));
        }

        if (! in_array((string) $document->status, [KycDocument::PENDING, KycDocument::APPROVED, KycDocument::REJECTED], true)) {
            throw new KycRefusal('terminal', (string) __('This document can no longer be reviewed.'));
        }

        /** @var User $reviewer */
        $reviewer = $actor;

        $document->status = $decision === self::APPROVE ? KycDocument::APPROVED : KycDocument::REJECTED;
        $document->reviewed_by = $reviewer->id;
        $document->reviewed_at = now();
        $document->review_note = $note === null ? null : mb_substr($note, 0, 1000);

        if ($expiresOn !== null) {
            $document->expires_on = $expiresOn;
        }

        $document->save();

        KycAccessLog::record(
            $decision === self::APPROVE ? KycAccessLog::APPROVED : KycAccessLog::REJECTED,
            $document,
            ['expires_on' => (string) $document->expires_on?->toDateString()],
            $reviewer
        );

        return $document;
    }

    /**
     * The configured document kinds that have no APPROVED document on this record.
     *
     * `isCurrent()` AND NOT `status === approved`: a document that is approved but past its expiry
     * does not satisfy a requirement, and reading the status alone would let an expired licence
     * hold a verification open. The pessimistic read, as everywhere else in this family.
     *
     * @return list<string>
     */
    public static function missingKinds(KycVerification $record): array
    {
        $required = KycAuthority::documentKinds((string) $record->subject_type, (string) $record->authority);

        if ($required === []) {
            return [];
        }

        $satisfied = KycDocument::query()
            ->where('store_id', (int) $record->store_id)
            ->where('kyc_verification_id', $record->getKey())
            ->where('status', KycDocument::APPROVED)
            ->get()
            ->filter(static fn (KycDocument $document): bool => $document->isCurrent())
            ->pluck('document_kind')
            ->all();

        return array_values(array_diff($required, $satisfied));
    }

    /**
     * Decide a whole verification record.
     *
     * @throws KycRefusal
     */
    public static function decideVerification(
        KycVerification $record,
        mixed $actor,
        string $decision,
        ?string $note = null,
        ?string $expiresOn = null
    ): KycVerification {
        if (! in_array($decision, self::DECISIONS, true)) {
            throw new KycRefusal('decision', (string) __('That is not a decision.'));
        }

        if (! KycAccess::permitsReview($actor)) {
            KycAccessLog::record(
                KycAccessLog::REFUSED,
                $record,
                ['reason' => 'not_a_reviewer'],
                $actor instanceof User ? $actor : null
            );

            throw new KycRefusal('not_a_reviewer', (string) __('You are not allowed to review this record.'));
        }

        /** @var User $reviewer */
        $reviewer = $actor;

        if ($decision === self::APPROVE) {
            $missing = self::missingKinds($record);

            if ($missing !== []) {
                throw new KycRefusal(
                    'missing_documents',
                    (string) __('This record cannot be approved while documents are outstanding.')
                );
            }
        }

        DB::transaction(function () use ($record, $decision, $note, $expiresOn, $reviewer): void {
            $record->status = $decision === self::APPROVE ? KycVerification::APPROVED : KycVerification::REJECTED;
            $record->reviewed_by = $reviewer->id;
            $record->reviewed_at = now();
            $record->review_note = $note === null ? null : mb_substr($note, 0, 1000);

            if ($expiresOn !== null) {
                $record->expires_on = $expiresOn;
            }

            $record->save();
        });

        KycAccessLog::record(
            $decision === self::APPROVE ? KycAccessLog::VERIFICATION_APPROVED : KycAccessLog::VERIFICATION_REJECTED,
            $record,
            ['expires_on' => (string) $record->expires_on?->toDateString()],
            $reviewer
        );

        return $record;
    }

    /**
     * The merchant says they are done. Moves `unsubmitted`/`rejected`/`expired` to `submitted`.
     *
     * A SUBMISSION IS NOT A REVIEW and takes the OWN-DOCUMENTS gate, not the reviewer one — the
     * merchant may say "look at this" and may not say "this is approved". The two gates being
     * different methods on `KycAccess` is what keeps that from being one mistake away.
     *
     * @throws KycRefusal
     */
    public static function submit(KycVerification $record, mixed $actor): KycVerification
    {
        if (! KycAccess::permitsOwnDocuments($actor, (int) $record->store_id)) {
            KycAccessLog::record(
                KycAccessLog::REFUSED,
                $record,
                ['reason' => 'not_this_store'],
                $actor instanceof User ? $actor : null
            );

            throw new KycRefusal('not_this_store', (string) __('You are not allowed to submit this record.'));
        }

        if ((string) $record->status === KycVerification::APPROVED) {
            throw new KycRefusal('already_approved', (string) __('This record is already approved.'));
        }

        $record->status = KycVerification::SUBMITTED;
        $record->submitted_at = now();
        $record->save();

        KycAccessLog::record(
            KycAccessLog::VERIFICATION_SUBMITTED,
            $record,
            [],
            $actor instanceof User ? $actor : null
        );

        return $record;
    }

    /**
     * The record for this store and authority, created `unsubmitted` if it does not exist.
     *
     * `firstOrCreate` IS NOT USED, deliberately: the identifying columns are not fillable (see
     * `KycVerification::$fillable` — a mass assignment that could move a reviewed decision between
     * stores is the shape KYC-003 forbids), so the row is built explicitly. The unique key
     * `(store_id, authority)` is what makes the race safe; a loser re-reads.
     */
    public static function recordFor(int $storeId, string $authority, string $subjectType, ?User $actor = null): KycVerification
    {
        $authority = KycAuthority::normalise($authority);

        $existing = KycVerification::query()
            ->where('store_id', $storeId)
            ->where('authority', $authority)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        if (! in_array($subjectType, KycVerification::SUBJECT_TYPES, true)) {
            throw new KycRefusal('subject_type', (string) __('That is not a subject type.'));
        }

        try {
            $record = new KycVerification();
            $record->store_id = $storeId;
            $record->authority = $authority;
            $record->subject_type = $subjectType;
            $record->status = KycVerification::UNSUBMITTED;
            $record->created_by = $actor?->id;
            $record->save();

            return $record;
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // The loser of a race re-reads the winner's row rather than creating a second answer.
            return KycVerification::query()
                ->where('store_id', $storeId)
                ->where('authority', $authority)
                ->firstOrFail();
        }
    }
}
