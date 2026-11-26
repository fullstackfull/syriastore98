<?php

namespace App\Services\Kyc;

use App\Models\AuditLog;
use App\Models\KycDocument;
use App\Models\KycVerification;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * KYC-002's FOURTH CLAUSE — سجلات الوصول, THE ACCESS LOG. WHO LOOKED AT WHOSE IDENTITY PAPERS.
 * =================================================================================================
 *
 * WHY THIS IS `audit_logs` AND NOT A NEW TABLE. Four measured reasons, in the order they matter:
 *
 *  1. THE HARD PART IS ALREADY SOLVED THERE, AND IT IS NOT THE PART THAT LOOKS HARD.
 *     `App\Support\Audit\AuditAttribution` exists because `AuditLog::record()` used to take its
 *     `store_id` from `getCurrentStore()`, which FABRICATES store 1 for the platform operator —
 *     so every operator action was filed under store 1 whoever it was taken against, and
 *     *"auditing merchant Y's history MISSES every operator action taken against Y"*. An access log
 *     for identity documents is almost entirely operator actions against other people's stores.
 *     A fresh table would have reproduced that defect from scratch.
 *  2. IT IS ONE OF THE SIXTEEN MODELS THAT IS NOT `Cachable`. CLAUDE.md records three measured
 *     false test readings from cached models; a trail that can be read stale is not a trail.
 *  3. IT IS A GATE F TABLE — `2026_10_12_000105_create_audit_logs_table` with
 *     `GateFSchemaGuard::assertCompatible()` — so it exists in production with a measured
 *     structure, and the access log needs no migration at all.
 *  4. A SECOND AUDIT SYSTEM BESIDE THE FIRST IS HOW A TRAIL ENDS UP WITH TWO HALF-ANSWERS. This
 *     project's own SUPPORT-004 finding is that the impersonation door writes NO audit row; the
 *     remedy named there is this table, not another one.
 *
 * THE ACTION VOCABULARY IS CLOSED AND `record()` REFUSES ANYTHING OUTSIDE IT. A trail queried by
 * `action` is only queryable if the writers agree on the spelling, and `audit_logs.action` is a
 * free `varchar(100)` that would happily take `kyc.docuemnt.read`. A typo there is not a visible
 * failure — it is a row the access-log screen never shows, which is the worst kind.
 *
 * WHAT IS **NOT** PUT IN A ROW: the document's bytes, its `storage_name` (the name IS the secret —
 * see `KycDocument`'s docblock — and an audit row is read by more people than the file is), and the
 * reviewer's note. What goes in is the document id, its kind, its authority, and for a refusal the
 * reason. `KycAccessLogContentTest` asserts the `storage_name` is absent, because a log line that
 * leaks the secret it is logging access to would be a worse defect than no log at all.
 */
final class KycAccessLog
{
    /** A merchant (or the operator on their behalf) stored a new private document. */
    public const UPLOADED = 'kyc.document.uploaded';

    /** THE ONE THAT MATTERS: somebody read the document's bytes. */
    public const READ = 'kyc.document.read';

    /** Somebody tried to read, or act on, a document and was refused. */
    public const REFUSED = 'kyc.document.refused';

    public const APPROVED = 'kyc.document.approved';

    public const REJECTED = 'kyc.document.rejected';

    /** `KycRenewal` marked it past its `expires_on`. */
    public const EXPIRED = 'kyc.document.expired';

    /** A document was replaced by a newer one of the same kind. */
    public const SUPERSEDED = 'kyc.document.superseded';

    public const VERIFICATION_SUBMITTED = 'kyc.verification.submitted';

    public const VERIFICATION_APPROVED = 'kyc.verification.approved';

    public const VERIFICATION_REJECTED = 'kyc.verification.rejected';

    public const VERIFICATION_EXPIRED = 'kyc.verification.expired';

    /** @var list<string> */
    public const ACTIONS = [
        self::UPLOADED,
        self::READ,
        self::REFUSED,
        self::APPROVED,
        self::REJECTED,
        self::EXPIRED,
        self::SUPERSEDED,
        self::VERIFICATION_SUBMITTED,
        self::VERIFICATION_APPROVED,
        self::VERIFICATION_REJECTED,
        self::VERIFICATION_EXPIRED,
    ];

    private function __construct()
    {
    }

    /**
     * Write one access-log row.
     *
     * `$actor` IS PASSED RATHER THAN READ FROM THE GUARD wherever the caller holds it. `AuditLog::record()`
     * falls back to `auth('web')->user()`, which is right for a panel request and wrong for a console
     * command, a queue job and — per CLAUDE.md — any request where `CustomAuth` has left the DEFAULT
     * guard pointing at `customers` or `deliveryboy`. A non-`User` principal is recorded as `null`,
     * which is the honest answer; the row still carries the store, because attribution reads the
     * SUBJECT's `store_id` first.
     *
     * @param  KycDocument|KycVerification  $subject
     * @param  array<string, mixed>  $details  merged into `new_values`; never the storage name
     *
     * @throws InvalidArgumentException on an action outside ACTIONS
     */
    public static function record(string $action, Model $subject, array $details = [], mixed $actor = null): AuditLog
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException('unknown KYC access-log action ' . json_encode($action));
        }

        if (! $subject instanceof KycDocument && ! $subject instanceof KycVerification) {
            throw new InvalidArgumentException('a KYC access-log row must name a KYC document or verification record');
        }

        if (array_key_exists('storage_name', $details)) {
            throw new InvalidArgumentException('the storage name is the secret and must not be written to the trail');
        }

        $describing = $subject instanceof KycDocument
            ? [
                'authority' => (string) $subject->authority,
                'document_kind' => (string) $subject->document_kind,
                'document_status' => (string) $subject->status,
            ]
            : [
                'authority' => (string) $subject->authority,
                'subject_type' => (string) $subject->subject_type,
                'verification_status' => (string) $subject->status,
            ];

        return AuditLog::record(
            $action,
            $subject,
            [],
            array_merge($describing, $details),
            $actor instanceof User ? $actor : null
        );
    }

    /**
     * THE TRAIL FOR ONE DOCUMENT — what a merchant is shown about who looked at their papers.
     *
     * THE `store_id` PREDICATE IS DEFENCE IN DEPTH AND IS NOT THE AUTHORIZATION. Every row for this
     * document already carries this document's store (attribution prefers the subject's own
     * `store_id`), so the predicate changes no result today; it is here so that a row written with
     * a different attribution — by a future caller, or by a bug — cannot be shown on a merchant's
     * screen. WHO may call this is `KycAccess`'s decision, made before the query is built: a query
     * is not a gate, and `AuditAttribution`'s docblock warns in those words that the historical
     * store-1 rows become a cross-tenant disclosure *"the day a reader is added"*.
     *
     * @return Builder<AuditLog>
     */
    public static function forDocument(KycDocument $document): Builder
    {
        return AuditLog::query()
            ->where('subject_type', KycDocument::class)
            ->where('subject_id', $document->getKey())
            ->where('store_id', (int) $document->store_id)
            ->orderByDesc('id');
    }

    /**
     * WHO TO SAY DID IT, ON A MERCHANT'S OWN ACCESS-LOG SCREEN — AND WHEN NOT TO SAY A NAME.
     *
     * A DEFECT IN THE FIRST VERSION OF THIS WORKSTREAM'S OWN SCREEN, found by reviewing the change
     * against `.claude/agents/tenant-authz-reviewer.md` rather than by a failing test. The view
     * printed `$actor->name` for any non-operator actor. Attribution files a row against the
     * SUBJECT's store, so a REFUSED access by a stranger — a merchant of another tenant who guessed
     * a document id — is filed against the victim's store with the STRANGER'S `user_id`. The screen
     * would therefore have told merchant A the full name of a user in tenant B.
     *
     * That is a cross-tenant disclosure arriving through a compliance feature, and the fix is not to
     * hide the event: the merchant must still see that somebody tried. What they do not need is the
     * stranger's identity, which is the platform's to act on.
     *
     *   the platform operator      "Store Syria". Never an account name: a merchant does not need
     *                              the name of the staff member who opened their passport scan, and
     *                              printing it would turn a compliance screen into a staff
     *                              directory.
     *   this store's own tenant     their name. The merchant, and their own staff, are people the
     *                              merchant already knows and manages.
     *   anybody else                a neutral label. Somebody asked; who they are is not this
     *                              merchant's to read.
     *   nobody                      "the system" — a console sweep (`kyc:expire`) or a queue job.
     *
     * THE TENANT TEST IS `UserPolicy::merchantTenantRoot()` AND NOT `created_by`, for the reason
     * that helper exists: for a user rooted at the platform — a merchant who demoted their own
     * `type`, or staff the operator created — `creatorId()` is the OPERATOR'S id, and a bare
     * comparison would call them a member of whichever tenant the operator's id matched.
     */
    public static function actorLabel(mixed $userId, int $storeId): string
    {
        $id = (int) $userId;

        if ($id <= 0) {
            return (string) __('The system');
        }

        $actor = User::withoutGlobalScopes()->whereKey($id)->first();

        if ($actor === null) {
            return (string) __('The system');
        }

        if (\App\Policies\UserPolicy::isOperator($actor)) {
            return (string) __('Store Syria');
        }

        $root = \App\Policies\UserPolicy::merchantTenantRoot($actor);

        if ($root !== null && \App\Models\Store::query()
            ->where('id', $storeId)
            ->where('created_by', $root)
            ->exists()) {
            return (string) $actor->name;
        }

        return (string) __('Another account');
    }

    /**
     * The trail for a whole verification record: its own rows and its documents'.
     *
     * @return Builder<AuditLog>
     */
    public static function forVerification(KycVerification $verification): Builder
    {
        $documentIds = KycDocument::query()
            ->where('store_id', (int) $verification->store_id)
            ->where('kyc_verification_id', $verification->getKey())
            ->pluck('id')
            ->all();

        return AuditLog::query()
            ->where('store_id', (int) $verification->store_id)
            ->where(function ($query) use ($verification, $documentIds): void {
                $query
                    ->where(function ($own) use ($verification): void {
                        $own->where('subject_type', KycVerification::class)
                            ->where('subject_id', $verification->getKey());
                    })
                    ->orWhere(function ($docs) use ($documentIds): void {
                        $docs->where('subject_type', KycDocument::class)
                            ->whereIn('subject_id', $documentIds ?: [0]);
                    });
            })
            ->orderByDesc('id');
    }
}
