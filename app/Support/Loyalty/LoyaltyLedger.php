<?php

namespace App\Support\Loyalty;

use App\Models\LoyaltyPointTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * `MKT-005` — «سجل حركات». THE ONLY WRITER OF THE LOYALTY POINT LEDGER.
 * =================================================================================================
 *
 * «نقاط ولاء وVIP وإحالات مع سجل حركات» — *loyalty points, VIP and referrals, with a transaction
 * log*. This is the log. The VIP half shipped as a `min_loyalty_points` segment rule; referrals are
 * the owner decision `J37` over vendored `ProductAffiliate`.
 *
 * -------------------------------------------------------------------------------------------------
 * ONE WRITER, AND WHAT THAT BUYS
 * -------------------------------------------------------------------------------------------------
 *
 * Every row in `loyalty_point_transactions` comes through {@see self::record()}. That is what makes
 * the vocabulary enforceable, the sign reliable and the refusals testable in one place. A second
 * writer would be free to insert an unsigned redemption, and every sum over the table would be
 * wrong with nothing to show it.
 *
 * -------------------------------------------------------------------------------------------------
 * AN OBSERVER OF RECORD, NOT AN AUTHORITY — THE DESIGN DECISION OF THE SLICE
 * -------------------------------------------------------------------------------------------------
 *
 * The balance lives in `customers.total_club_point`, a **vendored** column that is ABSENT from this
 * application's schema (`GAP-191`; measured again by this slice's own premise test). Three options
 * were on the table:
 *
 *   (a) ledger authoritative, the vendored column a cache;
 *   (b) ledger records movements against whatever balance exists, and refuses cleanly when the
 *       substrate is absent;
 *   (c) something else.
 *
 * **(b), sharpened.** (a) requires something to read this table to decide what a shopper may spend.
 * That changes the input of all eleven first-party spend paths, and therefore changes
 * customer-visible balances and the money discount derived from them. Points are money-adjacent, so
 * that is the owner's decision; it is reported as one rather than taken. (a) also has to keep a
 * vendored column in step from first-party code, which is a second writer of a column whose only
 * current writer is a vendored listener this project may not edit — two authorities that drift.
 *
 * The sharpening is that a log of bare deltas is not reconcilable: each row therefore carries the
 * balance this writer **observed**, before and after. One row then answers a dispute, and the first
 * row's `balance_before` anchors the chain to a balance that existed before this table did — which
 * is the only reason a store that installed the vendored module years ago can be reconciled at all.
 *
 * **So the only write this slice performs anywhere is an INSERT into this table.** No balance is
 * read to price anything, no balance is written, and a store without the vendored module behaves
 * exactly as it does today.
 *
 * -------------------------------------------------------------------------------------------------
 * `Schema::hasTable()` AND NEVER `module_is_active()`
 * -------------------------------------------------------------------------------------------------
 *
 * CLAUDE.md: `module_is_active()` resolves its principal from `Auth::check()` first, so from a
 * console command, a queue worker or a test body with nobody signed in it answers FALSE while the
 * `add_on_managers` row, `has`, `isEnabled` and `getByStatus` all read TRUE. A writer gated on it
 * would stop logging in exactly the contexts where a log matters most. The thing this writer needs
 * is its TABLE, and `hasTable()` asks about that.
 *
 * -------------------------------------------------------------------------------------------------
 * IT NEVER FAILS A CHECKOUT
 * -------------------------------------------------------------------------------------------------
 *
 * {@see self::record()} returns null when the table is absent, and {@see self::observe()} swallows
 * anything the write throws. This is called from inside payment confirmation; CLAUDE.md records a
 * post-commit insert throwing there and leaving a half-written order at HTTP 500. A log that can
 * take a payment down is a worse defect than a missing log line, so the refusals that DO throw are
 * programmer errors reached only from first-party call sites with constant arguments, and the
 * observer path cannot throw at all.
 *
 * @see \App\Observers\CustomerLoyaltyPointObserver  the movement chokepoint
 * @see \App\Support\Loyalty\LoyaltyReconciliation   ledger against balance
 * @see \App\Support\Loyalty\LoyaltyBalance          the capability-gated balance reader
 */
final class LoyaltyLedger
{
    public const EARN = 'earn';
    public const REDEEM = 'redeem';
    public const EXPIRE = 'expire';
    public const ADJUST = 'adjust';

    /**
     * The vocabulary `MKT-005`'s log names.
     *
     * `EXPIRE` HAS NO PRODUCER IN THIS CODEBASE, deliberately. Nothing expires points here, and
     * inventing an expiry policy would invent a business rule that costs customers their balance.
     * The kind exists so the log's vocabulary matches the requirement's words rather than the subset
     * that happens to be wired, and so an owner-approved expiry has somewhere honest to write.
     */
    public const KINDS = [self::EARN, self::REDEEM, self::EXPIRE, self::ADJUST];

    /**
     * Movements smaller than this are not movements.
     *
     * The authority this ledger observes is a `double` and the points figure is itself computed by a
     * division (`product_price * order_points / order_point_price`), so a `save()` that touched some
     * other column can leave a difference in the last bits. Half a cent is below anything a
     * `decimal(15,2)` column can represent, so no real movement is lost to it.
     */
    public const EPSILON = 0.005;

    /** Is the ledger's own table there? See the class docblock on why this and not a module check. */
    public static function available(): bool
    {
        return Schema::hasTable('loyalty_point_transactions');
    }

    /**
     * Record one movement. The ONLY way a row enters this table.
     *
     * Named arguments throughout, at every call site: the signature is long because a movement is
     * genuinely eight facts, and two adjacent nullable floats that mean "before" and "after" are
     * exactly the pair a positional call swaps silently.
     *
     * @param  int          $storeId            the store whose customer this is; never 0
     * @param  int          $customerId         `customers.id`; never 0
     * @param  string       $kind               one of {@see self::KINDS}
     * @param  float        $delta              SIGNED: negative for a redemption
     * @param  float|null   $balanceBefore      as OBSERVED; null means unobservable, never 0
     * @param  float|null   $balanceAfter       as OBSERVED; null means unobservable, never 0
     * @param  int|null     $orderId            what caused it, where known
     * @param  bool         $redemptionClaimed  what the REQUEST claimed; not the same as the kind
     * @param  string       $source             which writer observed it
     * @param  string|null  $reason             free text for a human reading the row
     *
     * @throws RuntimeException on a programmer error — an unknown kind, a zero movement, a missing
     *                          store or customer. Never on a condition a request can cause.
     */
    public static function record(
        int $storeId,
        int $customerId,
        string $kind,
        float $delta,
        ?float $balanceBefore,
        ?float $balanceAfter,
        string $source,
        ?int $orderId = null,
        bool $redemptionClaimed = false,
        ?string $reason = null,
    ): ?LoyaltyPointTransaction {
        if (! in_array($kind, self::KINDS, true)) {
            throw new RuntimeException(
                'Unknown loyalty movement kind [' . $kind . ']. The vocabulary is '
                . implode(', ', self::KINDS) . '.'
            );
        }

        if (abs($delta) < self::EPSILON) {
            throw new RuntimeException(
                'A zero loyalty movement is not a movement. Recording one puts a row in the log '
                . 'that no auditor can act on and no reconciliation can use.'
            );
        }

        if ($storeId <= 0) {
            throw new RuntimeException(
                'A loyalty movement must name its store. This table is not in `scoped_models` and '
                . '`null_tenant => open`, so every reader states the store explicitly — a storeless '
                . 'row would be invisible to all of them and present in the table.'
            );
        }

        if ($customerId <= 0) {
            throw new RuntimeException('A loyalty movement must name its customer.');
        }

        if (! self::available()) {
            return null;
        }

        return LoyaltyPointTransaction::create([
            'store_id' => $storeId,
            'customer_id' => $customerId,
            'kind' => $kind,
            'points_delta' => round($delta, 2),
            'balance_before' => $balanceBefore === null ? null : round($balanceBefore, 2),
            'balance_after' => $balanceAfter === null ? null : round($balanceAfter, 2),
            'order_id' => ($orderId !== null && $orderId > 0) ? $orderId : null,
            'redemption_claimed' => $redemptionClaimed,
            'source' => $source,
            'reason' => $reason,
        ]);
    }

    /**
     * {@see self::record()} for a caller that must never throw and never fail its own request.
     *
     * The observer uses this. A balance movement has already happened and been committed by the time
     * it is called; the log cannot undo that, so the only question is whether a failure to log takes
     * the request down with it. It must not — CLAUDE.md records a post-commit insert throwing inside
     * payment confirmation and leaving a half-written order at HTTP 500.
     *
     * The failure is reported to the log rather than swallowed in silence, so a ledger that has
     * stopped writing is discoverable instead of merely empty.
     */
    public static function observe(
        int $storeId,
        int $customerId,
        string $kind,
        float $delta,
        ?float $balanceBefore,
        ?float $balanceAfter,
        string $source,
        ?int $orderId = null,
        bool $redemptionClaimed = false,
        ?string $reason = null,
    ): ?LoyaltyPointTransaction {
        try {
            return self::record(
                storeId: $storeId,
                customerId: $customerId,
                kind: $kind,
                delta: $delta,
                balanceBefore: $balanceBefore,
                balanceAfter: $balanceAfter,
                source: $source,
                orderId: $orderId,
                redemptionClaimed: $redemptionClaimed,
                reason: $reason,
            );
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * One customer's history in one store, newest first.
     *
     * THE STORE PREDICATE IS NOT OPTIONAL and is not supplied by a global scope: this model is
     * absent from `config/tenancy.php`'s `scoped_models`, so `StoreScope::isActiveFor()`
     * short-circuits and adds nothing for ANY principal. `customers.id` values are reused across
     * tenants in a shared database, so a `customer_id`-only read is a cross-tenant read.
     *
     * Ordered by `id` and not by `created_at`: two movements in one request share a timestamp to the
     * second, and the screen's order must be total.
     *
     * @return Collection<int, LoyaltyPointTransaction>
     */
    public static function historyFor(int $storeId, int $customerId, int $limit = 100): Collection
    {
        if (! self::available() || $storeId <= 0 || $customerId <= 0) {
            return new Collection();
        }

        return LoyaltyPointTransaction::query()
            ->where('store_id', $storeId)
            ->where('customer_id', $customerId)
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get();
    }
}
