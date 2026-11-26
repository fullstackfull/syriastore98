<?php

namespace App\Models;

use App\Services\Kyc\KycAuthority;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * KYC-001 + KYC-003 — ONE STORE'S VERIFICATION **BY ONE AUTHORITY**.
 * =================================================================================================
 *
 * A row answers "has <authority> verified <store>, as an <individual|company>, and until when".
 * There is deliberately no row, column or method anywhere that answers "is this store verified"
 * without naming an authority: that conflation is exactly what KYC-003 forbids.
 *
 * NOT `Cachable`, DELIBERATELY, and that is a departure from the 79-of-95 majority in
 * `app/Models/`. Three reasons, each one measured by this project rather than assumed:
 *
 *   - CLAUDE.md records THREE false test readings caused by a raw write leaving an Eloquent read
 *     (route-model binding included) serving the pre-write row, and under `CACHE_STORE=array` that
 *     stale answer lives for the rest of the process. For an authorization-bearing record the
 *     stale answer is "still approved".
 *   - `AuditLog` — the access trail this record is read beside — is one of the sixteen models that
 *     is already not `Cachable`, so a cached verification beside an uncached trail could disagree.
 *   - A reviewer's decision is read rarely and must be right; there is no hot path to protect.
 *
 * NOT IN `config/tenancy.php`'s `scoped_models` EITHER, and that is also deliberate rather than an
 * omission. The model satisfies all five of the conditions that file lists for an entry (the table
 * really has `store_id`; every insert path populates it; no call site filters it by a foreign store
 * id; it is not reachable through the admin API's request-supplied `store_id`; and no storefront
 * read site filters it by a slug-resolved store). It is left out because `null_tenant => 'open'`
 * means the scope adds NO predicate whenever no tenant resolves, so a handler that leaned on it
 * would be unprotected for exactly the principals that matter here — the platform operator, a
 * console command, a queue job. Every read in `App\Services\Kyc` therefore carries its own
 * `store_id` predicate derived from the authenticated principal, never from request input.
 * Registering a `StoreScope` that does not filter would make `getGlobalScopes()` answer "scoped"
 * for a model that is not, which CLAUDE.md names as the wrong question to ask.
 *
 * WHAT THIS MODEL DOES NOT DECIDE: which documents a subject type must supply, how long an approval
 * lasts, who may approve, and what happens to a store whose verification lapses. All four are owner
 * decisions (`D-P6-46`). `isCurrent()` reports what the stored dates say and takes no action.
 */
class KycVerification extends Model
{
    protected $table = 'kyc_verifications';

    /**
     * `store_id`, `authority` and `subject_type` are NOT fillable. They identify the record, the
     * unique key is built from the first two, and a mass assignment that could change them would
     * move a reviewed decision from one store or one authority to another — the single thing
     * KYC-003 exists to make impossible. `KycVerificationFactoryGuardTest` asserts the absence.
     */
    protected $fillable = [
        'status',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'expires_on',
        'created_by',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'expires_on' => 'date',
    ];

    /** Nothing has been uploaded yet. The state a record is created in. */
    public const UNSUBMITTED = 'unsubmitted';

    /** The merchant has said they are done; nobody has looked. */
    public const SUBMITTED = 'submitted';

    /**
     * A reviewer has picked it up.
     *
     * DECLARED AND NOT YET WRITTEN BY ANY DOOR, stated here rather than left to be discovered. The
     * operator's queue admits it (`whereIn(['submitted', 'in_review'])`) so that a future "claim
     * this for review" step needs no migration and no change to the queue; nothing claims a record
     * today, because whether reviews are assigned to a named reviewer — and whether a merchant
     * should be told who is looking at their papers — is a workflow question nobody has asked for.
     * `KycStatusVocabularyTest` writes it directly, so the column really accepts it.
     */
    public const IN_REVIEW = 'in_review';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /** Was approved; `expires_on` has passed. `KycRenewal` is the only writer of this state. */
    public const EXPIRED = 'expired';

    /** @var list<string> pinned against the migration's comment by KycStatusVocabularyTest */
    public const STATUSES = [
        self::UNSUBMITTED,
        self::SUBMITTED,
        self::IN_REVIEW,
        self::APPROVED,
        self::REJECTED,
        self::EXPIRED,
    ];

    /** KYC-001's two subjects. */
    public const INDIVIDUAL = 'individual';

    public const COMPANY = 'company';

    /** @var list<string> */
    public const SUBJECT_TYPES = [self::INDIVIDUAL, self::COMPANY];

    /**
     * REFUSE AN UNKNOWN AUTHORITY, SUBJECT TYPE OR STATUS AT THE MODEL, not at the handler.
     *
     * A string column with a vocabulary is only a vocabulary if something enforces it. The engine
     * cannot (a MySQL `enum` would need an `ALTER` to extend, which is an owner escalation on a
     * live table), and a check in one handler is a check the next handler does not have. So it
     * lives here, where every writer passes.
     *
     * `saving` AND NOT `creating`: a status transition is an update, and an update that wrote
     * `approvd` would otherwise pass. Measured shape, not hypothetical — this codebase's own
     * `Setting` guard was added at the writer for the same reason.
     */
    protected static function booted(): void
    {
        static::saving(function (self $record): void {
            if (! KycAuthority::isKnown((string) $record->authority)) {
                throw new InvalidArgumentException(
                    'a KYC verification must name a known authority, not ' . json_encode($record->authority)
                );
            }

            if (! in_array((string) $record->subject_type, self::SUBJECT_TYPES, true)) {
                throw new InvalidArgumentException(
                    'a KYC verification must name a known subject type, not ' . json_encode($record->subject_type)
                );
            }

            if (! in_array((string) $record->status, self::STATUSES, true)) {
                throw new InvalidArgumentException(
                    'a KYC verification must carry a known status, not ' . json_encode($record->status)
                );
            }

            if ((int) $record->store_id <= 0) {
                throw new InvalidArgumentException('a KYC verification must belong to a store');
            }
        });
    }

    /** @return HasMany<KycDocument> */
    public function documents(): HasMany
    {
        return $this->hasMany(KycDocument::class, 'kyc_verification_id');
    }

    /**
     * Is this verification an APPROVAL THAT IS STILL GOOD TODAY?
     *
     * Two conditions, and the second is KYC-002's renewal clause: `approved` AND either no expiry
     * or an expiry that has not passed (plus `config('kyc.expiry_grace_days')`, which is 0 by
     * default — a grace period is a policy the owner has not set).
     *
     * IT READS THE STORED DATE AND DOES NOT WRITE. A record whose expiry has passed answers false
     * here whether or not `KycRenewal` has got round to writing `expired` into its status, so a
     * gate is never waiting on a scheduled command to become correct. That is the opposite of the
     * usual projection pattern in this codebase and is the right way round for an authorization
     * answer: the derived state is the pessimistic one.
     */
    public function isCurrent(?\DateTimeInterface $asOf = null): bool
    {
        if ((string) $this->status !== self::APPROVED) {
            return false;
        }

        if ($this->expires_on === null) {
            return true;
        }

        $grace = max(0, (int) config('kyc.expiry_grace_days', 0));
        $deadline = $this->expires_on->copy()->endOfDay()->addDays($grace);

        return $deadline->greaterThanOrEqualTo($asOf ?? now());
    }
}
