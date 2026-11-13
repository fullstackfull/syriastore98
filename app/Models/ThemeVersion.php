<?php

namespace App\Models;

use App\Support\Theme\ThemePayload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PHASE 03 M3-C — ONE SAVED THEME CONFIGURATION: A DRAFT, A PUBLISHED SNAPSHOT, OR AN ARCHIVED ONE.
 * ================================================================================================
 *
 * NOT `Cachable`, AND THAT IS A DECISION. 79 of the 95 models in this directory use the trait and
 * the 16 exceptions are deliberate. This is the 17th. A raw write leaves Eloquent reads -- route
 * model binding included -- serving the pre-write row, and under `CACHE_STORE=array` that survives
 * a whole test run; it has produced three measured false readings in this project. Version rows are
 * read to decide what to publish and whether a draft differs from what is live, so a stale read here
 * means publishing the wrong bytes. `AuditLog` is not `Cachable` for the same reason.
 *
 * NOT IN `scoped_models` EITHER, and that is also a decision rather than an omission. See
 * `TenantModelClassificationTest::doNotScopeDespiteHavingTheColumn()` for the recorded reason: with
 * `null_tenant => 'open'` the global scope adds NO predicate when no tenant resolves, so a second
 * implicit predicate would mask a missing explicit one. Isolation here is the explicit triple
 * `(store_id, theme_id, id)`, which `tv_identity_unique` indexes exactly -- so the safe lookup is
 * also the fast one.
 *
 * NEVER FETCH ONE BY BARE ID. `ThemeVersion::find($request->version_id)` returns another tenant's
 * 31-35 KB payload. Use `ownedBy()` below.
 */
class ThemeVersion extends Model
{
    public const STATUS_DRAFT     = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED  = 'archived';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_ARCHIVED];

    protected $fillable = [
        'store_id', 'theme_id', 'version_no', 'status', 'label',
        'payload', 'payload_hash', 'created_by', 'published_by', 'published_at',
    ];

    protected $casts = [
        'store_id'     => 'integer',
        'version_no'   => 'integer',
        'created_by'   => 'integer',
        'published_by' => 'integer',
        'published_at' => 'datetime',
    ];

    /**
     * THE COLUMN CANNOT GUARD THE STATUS SET, SO THE MODEL DOES.
     *
     * `status` is `varchar(16)` and production runs `'strict' => false`, so an over-length value is
     * SILENTLY TRUNCATED rather than refused -- measured on MariaDB 10.11: a 17-character status
     * came back as 16 characters. An `enum` would be worse, not better: under the same non-strict
     * mode an unknown enum value coerces to the EMPTY STRING, which would give the appearance of a
     * constraint while quietly accepting garbage.
     *
     * So the set is enforced here, on the way in, where it can refuse rather than mangle.
     */
    protected static function booted(): void
    {
        static::saving(static function (self $version): void {
            if (! in_array($version->status, self::STATUSES, true)) {
                throw new \InvalidArgumentException(
                    "'{$version->status}' is not a theme version status. Expected one of: "
                    . implode(', ', self::STATUSES) . '. The varchar(16) column would have '
                    . 'truncated it silently under production\'s non-strict sql_mode.');
            }
        });
    }

    /**
     * THE ONLY SAFE LOOKUP. Scoped by store AND theme AND id, because the id alone is a global
     * surrogate key and nothing in the schema stops one tenant naming another's.
     */
    public static function ownedBy(int $storeId, string $themeId, int $versionId): ?self
    {
        if ($storeId <= 0 || $versionId <= 0) {
            return null;        // fail closed; `where('store_id', null)` is `store_id IS NULL`
        }

        return static::query()
            ->where('store_id', $storeId)
            ->where('theme_id', $themeId)
            ->where('id', $versionId)
            ->first();
    }

    /** Every version of one store's one theme, newest first. */
    public static function historyFor(int $storeId, string $themeId)
    {
        return static::query()
            ->where('store_id', $storeId)
            ->where('theme_id', $themeId)
            ->orderByDesc('version_no');
    }

    /** The decoded `name => value` override set. Never re-encoded; see ThemePayload. */
    public function settings(): array
    {
        return ThemePayload::decode((string) $this->payload);
    }

    /** Does the stored hash still match the stored payload? Immutability evidence. */
    public function hashIsIntact(): bool
    {
        return hash('sha256', (string) $this->payload) === (string) $this->payload_hash;
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
