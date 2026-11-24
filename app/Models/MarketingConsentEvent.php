<?php

namespace App\Models;

use App\Models\Concerns\AppendOnlyEloquentBuilder;
use App\Models\Concerns\AppendOnlyQueryBuilder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * ONE FACT ABOUT CONSENT, WRITTEN ONCE AND NEVER CHANGED. D8 / MKT-008.
 *
 * This is the EVIDENCE. `marketing_contact_states` is a projection that can be rebuilt from it; if
 * the two ever disagree, this table is right. So it is append-only at the model layer, in three layers:
 * an existing instance cannot be saved or deleted (the model events throw); the Eloquent builder it
 * returns refuses the mass statements by name (`update`, `delete`, `truncate`, `upsert`, `increment`...)
 * with a message that says so; and the QUERY builder underneath it refuses `update`, `updateFrom`,
 * `upsert`, `delete` and `truncate`, which is where `touch()`, `updateOrInsert()`, `incrementEach()`,
 * `decrementEach()` and `toBase()->update()` all end. Inserts are untouched: that is how it is written.
 *
 * WHAT THAT DOES NOT COVER, said plainly: a raw `DB::table('marketing_consent_events')` write goes
 * around Eloquent entirely, and so does anyone with database access. `ConsentLedgerCensusTest`
 * asserts that nothing in `app/` writes this table that way, and a MySQL trigger refusing UPDATE and
 * DELETE (or grants that withhold them from the application's database user) would close the rest --
 * it is a production schema decision and belongs with the migration authorisation, not in this change.
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

    /** Everything the ledger writes, and nothing else: a request array passed to `create()` cannot set a column that is not listed. */
    protected $fillable = [
        'store_id', 'channel', 'purpose', 'address_hash', 'address_hint', 'customer_id',
        'event', 'source', 'evidence', 'actor_type', 'actor_id', 'occurred_at', 'created_at',
    ];

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

    protected function newBaseQueryBuilder()
    {
        $connection = $this->getConnection();

        return new AppendOnlyQueryBuilder($connection, $connection->getQueryGrammar(), $connection->getPostProcessor());
    }
}
