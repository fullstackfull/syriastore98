<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * PHASE 06 `CTRL-003` — one entry in the platform's change log.
 *
 * APPEND-ONLY, AND ENFORCED AT THIS BOUNDARY. `created_at` and no `updated_at`, the shape
 * `audit_logs` and `theme_publication_events` already use — and, one step further than that
 * precedent, an Eloquent `update` or `delete` THROWS. `CTRL-003` asks for a change log, and a log
 * whose rows can be rewritten is not one.
 *
 * WHAT THE ENFORCEMENT DOES NOT DO, said plainly rather than left to be discovered: nothing in the
 * database refuses an `UPDATE` or a `DELETE`. `DB::table('platform_control_changes')->delete()`
 * works, and so does a database client. `ThemePublicationEvent`'s docblock is explicit that
 * append-only is "a convention here, not a constraint"; this model narrows the convention to the
 * door every first-party writer passes through, which is a real improvement and not immutability.
 *
 * `RefreshDatabase` IS NOT AFFECTED, and that was checked rather than assumed: the trait truncates
 * or rebuilds through the schema builder, not through this model, so the guard cannot wedge the
 * suite.
 */
class PlatformControlChange extends Model
{
    /** `created_at` only. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'subject_type', 'subject_key', 'action',
        'before', 'after',
        'actor_id', 'actor_name', 'note',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'actor_id' => 'integer',
    ];

    protected static function booted(): void
    {
        /*
         * `updating`, NOT `saving`. `saving` fires for an INSERT too, so guarding there would make
         * the table unwritable -- a mistake worth naming, because it is the obvious first draft and
         * it fails in the direction that looks like the feature working (nothing is ever logged).
         */
        static::updating(function (self $row) {
            throw new RuntimeException(
                'platform_control_changes is append-only: entry ' . ($row->getKey() ?? '?')
                . ' cannot be updated. CTRL-003 asks for a change log, and a log whose rows can be '
                . 'rewritten is not one. Record a new entry describing the correction instead.'
            );
        });

        static::deleting(function (self $row) {
            throw new RuntimeException(
                'platform_control_changes is append-only: entry ' . ($row->getKey() ?? '?')
                . ' cannot be deleted. If retention is genuinely needed it is an owner decision '
                . 'about how long the platform keeps its own change history, not a delete call.'
            );
        });
    }
}
