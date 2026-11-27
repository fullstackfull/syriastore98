<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * `MKT-005` — one movement of one customer's loyalty points. APPEND-ONLY, ENFORCED.
 * =================================================================================================
 *
 * DELIBERATELY NOT `Cachable`, and this is the one decision in the file.
 *
 * CLAUDE.md: most models in `app/Models/` carry the trait, and the paragraph that lists the
 * exceptions tells you to *assume cached*. `AuditLog` is the precedent for not caching, and the
 * reason applies here with more force: a ledger exists to be read back immediately after it is
 * written — by the reconciliation, by the merchant screen one request later, and by a test. CLAUDE.md
 * records **three measured false readings** where a write left an Eloquent read serving the pre-write
 * row, and under `CACHE_STORE=array` that stale answer lives for the rest of the process. A cached
 * audit log is a log that can disagree with itself, which is worse than no log.
 *
 * NOT IN `config/tenancy.php`'s `scoped_models` either, so no `StoreScope` predicate is added for
 * any principal. Every reader states the store itself; see {@see \App\Support\Loyalty\LoyaltyLedger}.
 *
 * -------------------------------------------------------------------------------------------------
 * WHY THE APPEND-ONLY RULE IS CODE AND NOT A COMMENT
 * -------------------------------------------------------------------------------------------------
 *
 * `PHASE-05-OWNER-DECISION-PACKAGE.md` §2.4 D4b: *"A reversal writes a compensating row; it never
 * edits history… the audit trail must show the award and the reversal, not an award that quietly
 * disappeared."* A docblock saying so constrains nobody. `updating` and `deleting` therefore throw,
 * which also means a future `$transaction->update()` written in good faith fails loudly in
 * development instead of quietly erasing a dispute's evidence in production.
 *
 * The guards hang off model events rather than off overridden methods, because `Builder::update()`
 * and `Builder::delete()` on a query do not call the model's own methods — but a mass update does
 * not fire these events either, so this is a guard against the ordinary mistake and NOT a claim that
 * the rows are immutable at the database. Nothing short of a database grant can make that claim, and
 * this model does not pretend otherwise.
 */
class LoyaltyPointTransaction extends Model
{
    protected $table = 'loyalty_point_transactions';

    /**
     * `created_at` with no `updated_at`. Setting `UPDATED_AT` to null is what stops Eloquent
     * writing a column the migration deliberately does not declare.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'store_id',
        'customer_id',
        'kind',
        'points_delta',
        'balance_before',
        'balance_after',
        'order_id',
        'redemption_claimed',
        'source',
        'reason',
    ];

    protected $casts = [
        'store_id' => 'integer',
        'customer_id' => 'integer',
        'order_id' => 'integer',
        'redemption_claimed' => 'boolean',
        /*
         * `float` and not `decimal:2`. A `decimal` cast returns a STRING, and every consumer here
         * does arithmetic on it — the reconciliation sums, the screen formats a sign. A string that
         * silently works in `+` and silently does not in `===` is the shape of a defect nobody
         * sees. The COLUMN is `decimal(15,2)`, which is where the storage guarantee lives; the cast
         * is about what PHP gets handed.
         */
        'points_delta' => 'float',
        'balance_before' => 'float',
        'balance_after' => 'float',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new RuntimeException(
                'loyalty_point_transactions is append-only: a correction is a new compensating '
                . 'movement, never an edit of a recorded one (D4b).'
            );
        });

        static::deleting(static function (): void {
            throw new RuntimeException(
                'loyalty_point_transactions is append-only: a recorded movement is evidence in a '
                . 'dispute and is never removed (D4b).'
            );
        });
    }
}
