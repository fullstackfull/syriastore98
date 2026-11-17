<?php

namespace App\Models;

use App\Models\Concerns\AppendOnlyEloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * ONE FACT ABOUT CONSENT, WRITTEN ONCE AND NEVER CHANGED. D8 / MKT-008.
 *
 * This is the EVIDENCE. `marketing_contact_states` is a projection that can be rebuilt from it; if
 * the two ever disagree, this table is right. So it is append-only at the model layer, in three ways:
 * an existing instance cannot be saved or deleted (the model events throw), and the query builder
 * it returns refuses `update`, `delete`, `truncate`, `upsert` and friends, which is the path a
 * model event cannot see.
 *
 * WHAT THAT DOES NOT COVER, said plainly: a raw `DB::table('marketing_consent_events')` write goes
 * around Eloquent entirely, and so does anyone with database access. `ConsentLedgerCensusTest`
 * asserts that nothing in `app/` writes this table that way, and a MySQL trigger refusing UPDATE and
 * DELETE would close the rest -- it is a production schema decision and belongs with the migration
 * authorisation, not in this change.
 *
 * NOT `Cachable` AND NOT tenant-scoped, both deliberately. A cached read of consent is a stale
 * answer to "may I send this", and the global store scope cannot tell a merchant browsing another
 * storefront from a visitor -- the reasons `config/tenancy.php` documents at length. Every query
 * here carries an explicit `store_id`; the service that reads it takes one as a required argument.
 */
class MarketingConsentEvent extends Model
{
    protected $table = 'marketing_consent_events';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'evidence' => 'array',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('marketing_consent_events is append-only: a recorded consent event is evidence and is never edited.');
        });

        static::deleting(function () {
            throw new LogicException('marketing_consent_events is append-only: a recorded consent event is evidence and is never deleted.');
        });
    }

    public function newEloquentBuilder($query)
    {
        return new AppendOnlyEloquentBuilder($query);
    }
}
