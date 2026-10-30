<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * F216 / OWNER DECISION D-29.3 + D-29.4 — ONE CAPTURE, ONE ROW, AND NO PAID STATE WITHOUT PROOF.
 * =================================================================================================
 *
 * `order_gateway_references` binds a gateway capture to an order, or records that a capture
 * produced none. This service is the only thing that writes it, and the two owner decisions it
 * serves pull in opposite directions on purpose:
 *
 *   **D-29.3** asks for uniqueness, tenant isolation and idempotency across the gateway return,
 *   the webhook and the retry — so a replay must not create a second row or a second order.
 *
 *   **D-29.4** refuses the obvious shortcut: *"a merchant's statement alone is not payment
 *   proof."* So nothing here sets `payment_status`, releases stock, or declares a capture
 *   successful. It records what a gateway said and whom to ask.
 *
 * IDEMPOTENCY IS THE DATABASE'S JOB, NOT THIS CLASS'S
 * -------------------------------------------------------------------------------------------------
 * `claim()` attempts an INSERT and lets `UNIQUE (store_id, gateway, reference)` decide. A
 * read-then-write — "is this reference known? no? then insert" — races: two concurrent returns
 * both read absent and both insert. That is exactly the shape this repository has measured on the
 * variant-removal path, where SQLite's `lockForUpdate()` compiles to nothing and only MariaDB with
 * two real connections showed the truth. Here the engine refuses the second insert outright, so
 * there is no window to lose.
 *
 * A DUPLICATE IS NOT AN ERROR. It is the answer: *this capture has been seen.* `claim()` returns
 * `false` and the caller refuses the replay — the same contract `claimGatewayReturn()` already
 * uses, and the reason this returns a bool rather than throwing.
 *
 * THE KEY INCLUDES THE REFERENCE, which is the owner's sentence *"another order in the same store
 * is not evidence that this operation succeeded"* turned into a constraint. A store with a hundred
 * paid orders tells you nothing about this capture; only the same (gateway, reference) does.
 *
 * WHAT IS DELIBERATELY ABSENT
 * -------------------------------------------------------------------------------------------------
 *   - **No `markPaid()`.** D-29.3's *"no automatic Paid state without proof of capture"* and
 *     D-29.4's refusal of merchant attestation both land here. An order's payment status is not
 *     this service's to move.
 *   - **No claim release.** D-29.4: *"do not release the Replay Claim unsafely."* The 48-hour
 *     `gateway-return-claim:` keys are `GuardsGatewayReturn`'s and are untouched.
 *   - **No stock movement.** D-29.4: *"release or restore no stock on a guess."*
 *   - **No refund.** `RefundIntegrityService:119-122` refuses every collected payment because no
 *     gateway refund driver exists, which is also E13's blocker. A recovery-by-refund path needs
 *     that driver and a separate owner decision.
 */
final class GatewayReference
{
    public const TABLE = 'order_gateway_references';

    /** The three honest capture states. A boolean would have to lie about one of them. */
    public const CONFIRMED = 'confirmed';
    public const UNKNOWN = 'unknown';
    public const REJECTED = 'rejected';

    /**
     * Record this capture, or report that it is already recorded.
     *
     * @return bool true when THIS call created the row; false when the reference was already known
     */
    public static function claim(
        $storeId,
        string $gateway,
        string $reference,
        ?string $amount = null,
        string $captureState = self::UNKNOWN
    ): bool {
        if (! self::available()) {
            /*
             * THE TABLE IS NOT THERE. That is the state of every environment where the migration
             * has not been run, which by the owner's decision includes production. Returning TRUE
             * means "proceed", so an installation without the table behaves exactly as it did
             * before this service existed -- the feature is additive and cannot refuse a
             * legitimate payment because a migration is outstanding.
             *
             * It is logged at `warning` rather than silently, because an installation that MEANT
             * to have the table and does not has lost its idempotency guarantee and nobody would
             * otherwise know.
             */
            Log::warning('gateway reference not recorded: the table is absent', [
                'gateway' => $gateway,
                'store_id' => $storeId,
            ]);

            return true;
        }

        if (! self::usable($storeId, $reference)) {
            return true;
        }

        try {
            DB::table(self::TABLE)->insert([
                'store_id' => (int) $storeId,
                'gateway' => $gateway,
                'reference' => $reference,
                'order_id' => null,
                'amount' => $amount,
                'capture_state' => self::state($captureState),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return true;
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            /* ALREADY SEEN. The engine refused it, which is the answer rather than a failure. */
            Log::warning('gateway capture replayed: the reference is already recorded', [
                'gateway' => $gateway,
                'store_id' => $storeId,
            ]);

            return false;
        } catch (\Illuminate\Database\QueryException $e) {
            /*
             * Older engines and drivers report a duplicate key as a plain QueryException rather
             * than the typed one, so the SQLSTATE is checked rather than the class alone. 23000 is
             * the integrity-constraint class; any other code is a real fault and must not be read
             * as "already seen", because that would turn a broken database into a silent refusal
             * of every payment.
             */
            if ((string) ($e->errorInfo[0] ?? '') === '23000') {
                Log::warning('gateway capture replayed (23000): the reference is already recorded', [
                    'gateway' => $gateway,
                    'store_id' => $storeId,
                ]);

                return false;
            }

            Log::error('gateway reference could not be recorded', [
                'gateway' => $gateway,
                'store_id' => $storeId,
                'error' => $e->getMessage(),
            ]);

            return true;
        }
    }

    /**
     * Bind a recorded capture to the order it produced.
     *
     * Called AFTER the order row exists. It never creates the reference row: a bind with no prior
     * `claim()` would mean an order was written for a capture nobody recorded, which is a defect to
     * see rather than to paper over.
     *
     * @return bool true when a row was bound
     */
    public static function bind($storeId, string $gateway, string $reference, $orderId): bool
    {
        if (! self::available() || ! self::usable($storeId, $reference) || empty($orderId)) {
            return false;
        }

        /*
         * `whereNull('order_id')` is the idempotency of the BIND, distinct from the claim's. A
         * re-delivered webhook must not move an existing binding to a different order, so a row
         * that is already bound is left exactly as it is and the caller learns nothing was done.
         */
        $bound = DB::table(self::TABLE)
            ->where('store_id', (int) $storeId)
            ->where('gateway', $gateway)
            ->where('reference', $reference)
            ->whereNull('order_id')
            ->update([
                'order_id' => (int) $orderId,
                'bound_at' => now(),
                'updated_at' => now(),
            ]);

        return $bound > 0;
    }

    /** The order this capture produced, or null — which is the F216 review case, not an error. */
    public static function orderFor($storeId, string $gateway, string $reference)
    {
        if (! self::available() || ! self::usable($storeId, $reference)) {
            return null;
        }

        return DB::table(self::TABLE)
            ->where('store_id', (int) $storeId)
            ->where('gateway', $gateway)
            ->where('reference', $reference)
            ->value('order_id');
    }

    /**
     * Captures in this store that produced no order — the review queue D-29.4 requires.
     *
     * `rejected` is excluded: the gateway was reachable and said no, so there is nothing to settle
     * and a queue that lists failed payments is a queue nobody reads. The same reasoning
     * `StoreNotificationService::captureWithoutOrder()` records for its own notification.
     */
    public static function unreconciled($storeId)
    {
        if (! self::available() || ! is_numeric($storeId)) {
            return collect();
        }

        return DB::table(self::TABLE)
            ->where('store_id', (int) $storeId)
            ->whereNull('order_id')
            ->where('capture_state', '!=', self::REJECTED)
            ->orderByDesc('created_at')
            ->get();
    }

    private static function state(string $captureState): string
    {
        return in_array($captureState, [self::CONFIRMED, self::UNKNOWN, self::REJECTED], true)
            ? $captureState
            : self::UNKNOWN;
    }

    /**
     * A store and a reference this service can key on.
     *
     * An empty reference would make every unreferenced capture in a store collide on the unique
     * key and refuse the second one as a replay, which would turn a missing gateway field into a
     * refused payment. Proceeding unrecorded is the lesser harm and is logged by the caller.
     */
    private static function usable($storeId, string $reference): bool
    {
        return is_numeric($storeId) && (int) $storeId > 0 && trim($reference) !== '';
    }

    /**
     * PUBLIC because the review surface needs it, and it is a read-only question.
     *
     * It was `private static` and `StoreFinanceController` called it anyway, which made the HTML
     * branch of the review page an UNCONDITIONAL HTTP 500 for every merchant who opened it —
     * `Error: Call to private method ... from scope App\Http\Controllers\StoreFinanceController`.
     * Found by an independent review of my own change, and MISSED BY ALL ELEVEN OF MY OWN TESTS
     * because every one of them drove `getJson()`, which takes the `expectsJson()` branch and
     * returns before this line is reached. A handler with two branches needs a test on each.
     */
    public static function available(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable(self::TABLE);
    }
}
