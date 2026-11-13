<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * PHASE 03 M3-C — WHICH VERSION IS LIVE FOR A (STORE, THEME) PAIR. AT MOST ONE, ENFORCED BY THE DB.
 * ================================================================================================
 *
 * `UNIQUE (store_id, theme_id)` makes "exactly one published version" an engine guarantee rather
 * than an application convention, and the foreign key is COMPOSITE --
 * `(store_id, theme_id, version_id)` references `theme_versions (store_id, theme_id, id)` -- so the
 * engine refuses a pointer whose store or theme does not match the version's. Measured: store B
 * publishing store A's version is refused with 1452, and so is the wrong theme of the right store.
 *
 * NOT `Cachable` and NOT in `scoped_models`, for the reasons in `ThemeVersion`.
 *
 * `ON DELETE RESTRICT` on the version means a live version cannot be deleted while it is live, so
 * "the storefront renders a version that no longer exists" is unreachable rather than unlikely.
 */
class ThemePublishedVersion extends Model
{
    protected $fillable = ['store_id', 'theme_id', 'version_id', 'published_at', 'published_by'];

    protected $casts = [
        'store_id'     => 'integer',
        'version_id'   => 'integer',
        'published_by' => 'integer',
        'published_at' => 'datetime',
    ];

    /** The live pointer for one store's one theme, or null. Scoped, never by bare id. */
    public static function liveFor(int $storeId, string $themeId): ?self
    {
        if ($storeId <= 0) {
            return null;
        }

        return static::query()
            ->where('store_id', $storeId)
            ->where('theme_id', $themeId)
            ->first();
    }

    public function version()
    {
        return $this->belongsTo(ThemeVersion::class, 'version_id');
    }
}
