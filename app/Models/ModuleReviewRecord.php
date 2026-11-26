<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * MARKET-004. The review decision that stands between an uploaded package and its own `register()`.
 *
 * **DELIBERATELY NOT `Cachable`**, and this is the one design note that must not be lost. 79 of the
 * 95 models in `app/Models/` carry the trait and CLAUDE.md's standing advice is to assume a model
 * is cached. This one must not be, for the same reason `App\Models\MarketingNudgeSend` is not: a
 * cached MISS on "has this been reviewed" is not a stale read, it is the execution of unreviewed
 * third-party code. Under `CACHE_STORE=array` a stale miss lives for the rest of a request; under
 * production's non-taggable `database` store laravel-model-caching's flush degrades to a whole-store
 * flush, so the inverse -- a row that exists being invisible -- is equally reachable.
 *
 * `App\Support\Modules\ModuleTrust` does not use this model at all; it reads the table through the
 * query builder, because the predicate runs inside `PackageServiceProvider` while the container is
 * still assembling and must not depend on Eloquent's boot order or on any cache driver. This model
 * exists for the operator's review screen and for tests, which are the places a row is WRITTEN.
 */
class ModuleReviewRecord extends Model
{
    use HasFactory;

    public const TYPE_MODULE = 'module';
    public const TYPE_THEME = 'theme';

    public const PROVENANCE_UPLOADED = 'uploaded';
    public const PROVENANCE_SHIPPED = 'shipped';

    public const STATE_PENDING = 'pending';
    public const STATE_APPROVED = 'approved';
    public const STATE_REJECTED = 'rejected';

    protected $table = 'module_review_records';

    protected $fillable = [
        'subject_type', 'subject', 'provenance', 'review_state',
        'declared_version', 'declared_dependencies', 'declared_permissions',
        'uploaded_by', 'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    /** The states a decision may be recorded as. Not `pending`: that is the absence of a decision. */
    public static function decidableStates(): array
    {
        return [self::STATE_APPROVED, self::STATE_REJECTED];
    }
}
