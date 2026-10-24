<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * A VERIFIED CASH COLLECTION EVENT -- the only thing that settles a COD order.
 * ===========================================================================
 *
 * OWNER DECISION, 2026-10-24, FINAL:
 *
 *     "Withdraw the Delivered-based COD payment/settlement trigger now. Delivered must never, by
 *      itself, mark COD as Paid or credit a merchant wallet. Require a separate verified cash
 *      collection event, recording the collector (merchant, courier or delivery company),
 *      collection amount, time and supporting evidence. Keep order delivery reporting separate
 *      from cash collection and financial settlement reporting. Preserve historical records and
 *      avoid destructive migration. Implement the safe state transitions and required tests now;
 *      any schema-dependent accounting functionality remains gated on GATE F."
 *
 * This class is the "separate verified cash collection event". It is the seam, and the seam is
 * what the decision asks for NOW; the persistence behind it is schema-dependent and therefore
 * waits for GATE F, which is the other half of the same sentence.
 *
 * ===========================================================================================
 *  WHY A SERVICE THAT CANNOT YET WRITE ANYTHING IS THE RIGHT THING TO BUILD
 * ===========================================================================================
 *
 * The alternative was to withdraw the Delivered trigger and stop -- leaving COD with no route to
 * Paid except a merchant flipping a dropdown, and no definition anywhere of what a collection IS.
 * That would make the next person to implement this invent the four fields again, probably
 * differently, and probably inside whatever controller happened to need them. The decision names
 * those four fields precisely; they belong in one place, with the rules that make an event
 * VERIFIED rather than merely recorded.
 *
 * So: the shape is defined and enforced here, `record()` refuses loudly while the table is
 * absent, and `verifiedCollectionFor()` answers null. Nothing silently half-works.
 *
 * ===========================================================================================
 *  WHAT MAKES AN EVENT *VERIFIED* RATHER THAN MERELY WRITTEN
 * ===========================================================================================
 *
 * All four of the owner's fields, each refused when missing rather than defaulted:
 *
 *   collector_type   one of COLLECTOR_TYPES -- merchant, courier, delivery_company. Not free text:
 *                    "who collected" is a question with three answers in this product, and an
 *                    unrecognised fourth is a mistake, not a new kind of collector.
 *   collector_id     WHICH merchant, courier or company. A type without an id names nobody and
 *                    cannot support the collector-liability distinction the decision asks to keep.
 *   amount           strictly positive. Zero is not a collection.
 *   collected_at     when the cash changed hands -- NOT when the row was written. They differ, and
 *                    reconciliation keys on the former.
 *   evidence         the supporting evidence, non-empty. What counts as sufficient is the owner's
 *                    to define per store; that it EXISTS is enforced here.
 *
 * `recorded_by` is captured separately from `collector_id` on purpose: the person who enters the
 * event into the system is often not the person who took the money, and collapsing them destroys
 * exactly the collector-liability trail the decision asks to preserve.
 *
 * ===========================================================================================
 *  WHAT THIS CLASS DELIBERATELY DOES NOT DO
 * ===========================================================================================
 *
 * IT DOES NOT CREDIT A WALLET. It records that cash was collected; `OrderFinancialService` decides
 * what that means for a merchant's balance, and that service's tables are themselves behind
 * GATE F. The decision asks for delivery, collection and settlement to stay SEPARATE, and three
 * separate things are three separate classes.
 *
 * IT DOES NOT TOUCH HISTORICAL ROWS. "Preserve historical records and avoid destructive
 * migration": existing COD orders already marked Paid under the withdrawn trigger keep that
 * status. They are not re-opened, not reversed and not back-filled with invented events. The
 * draft migration creates a table and alters nothing.
 */
class CodCollectionService
{
    /**
     * The table this writes. Declared by NO migration -- see the draft under _gate_f_pending/.
     *
     * AND THE TWO `DB::table()` CALLS BELOW SPELL IT OUT AS A LITERAL RATHER THAN USING THIS
     * CONSTANT, WHICH IS DELIBERATE DUPLICATION. `RawQueryTenancyInventoryTest`'s per-table
     * exposure ratchet -- the one its own docblock calls "the ratchet that matters", because it
     * fails when a table's unscoped-query count goes UP -- matches
     * `/DB::table\s*\(\s*['"]([a-zA-Z0-9_]+)['"]/`. It can only see a QUOTED LITERAL. Written
     * as `DB::table(self::TABLE)` these two statements were invisible to it: the per-directory
     * COUNT moved from 143 to 145 and the per-table exposure map did not gain a row at all, so a
     * later change dropping the `store_id` predicate would have raised no alarm anywhere.
     *
     * Being legible to the tenancy ratchet is worth more than not repeating a string. The
     * constant stays for `Schema::hasTable()` and as the public name other code and the tests
     * refer to, and `test_the_table_constant_and_the_literals_agree` pins the two together so
     * they cannot drift.
     */
    public const TABLE = 'cod_collection_events';

    /** Who may have taken the cash. The decision names exactly these three. */
    public const COLLECTOR_TYPES = ['merchant', 'courier', 'delivery_company'];

    /** Money comparison tolerance, matching PaymentVerificationService::codMayBeSettled(). */
    private const AMOUNT_TOLERANCE = 0.005;

    /** Is the schema-dependent half available? Everything else keys on this. */
    public function isAvailable(): bool
    {
        try {
            return Schema::hasTable(self::TABLE);
        } catch (\Throwable $e) {
            // A connection that cannot answer is not an invitation to assume yes.
            return false;
        }
    }

    /**
     * Validate an event WITHOUT persisting it. Returns the reason it is not verifiable, or null.
     *
     * Separated from record() so the rules can be asserted, and asserted NOW, without a table --
     * which is what lets the required tests exist before GATE F rather than after.
     */
    public function rejectionReason(array $event): ?string
    {
        $type = strtolower(trim((string) ($event['collector_type'] ?? '')));

        if (!in_array($type, self::COLLECTOR_TYPES, true)) {
            return 'collector_type must be one of: ' . implode(', ', self::COLLECTOR_TYPES);
        }

        if (filter_var($event['collector_id'] ?? null, FILTER_VALIDATE_INT) === false
            || (int) $event['collector_id'] <= 0) {
            return 'collector_id must identify WHICH ' . $type . ' collected the cash';
        }

        if (!is_numeric($event['amount'] ?? null) || (float) $event['amount'] <= 0.0) {
            return 'amount must be a positive number; zero is not a collection';
        }

        if (trim((string) ($event['collected_at'] ?? '')) === '') {
            return 'collected_at must record WHEN the cash changed hands, not when this row was written';
        }

        if (trim((string) ($event['evidence'] ?? '')) === '') {
            return 'evidence must be present; what counts as sufficient is the store owner\'s to define';
        }

        return null;
    }

    /**
     * Record a verified collection against an order.
     *
     * Returns false and logs when it cannot -- never throws, because the caller is a merchant
     * workflow and a missing table is an operator problem rather than that merchant's.
     *
     * @param \App\Models\Order $order
     */
    public function record($order, array $event): bool
    {
        $reason = $this->rejectionReason($event);

        if ($reason !== null) {
            Log::warning('COD collection refused: the event is not verifiable', [
                'order_id' => $order->id ?? null,
                'store_id' => $order->store_id ?? null,
                'reason' => $reason,
            ]);

            return false;
        }

        // The amount must settle what is owed. A short collection is a PARTIAL collection, which is
        // a different record this product does not yet have, and must not be written as if it were
        // a full one.
        $owed = (float) ($order->final_price ?? 0);

        if (abs((float) $event['amount'] - $owed) > self::AMOUNT_TOLERANCE) {
            Log::warning('COD collection refused: the amount collected does not settle the order', [
                'order_id' => $order->id ?? null,
                'store_id' => $order->store_id ?? null,
                'collected' => (float) $event['amount'],
                'owed' => $owed,
            ]);

            return false;
        }

        if (!$this->isAvailable()) {
            Log::critical('COD collection cannot be recorded: cod_collection_events has no table', [
                'order_id' => $order->id ?? null,
                'store_id' => $order->store_id ?? null,
                'gate' => 'GATE F -- promote the draft under database/migrations/_gate_f_pending/',
            ]);

            return false;
        }

        DB::table('cod_collection_events')->insert([
            'order_id' => (int) $order->id,
            'store_id' => $order->store_id,
            'collector_type' => strtolower(trim((string) $event['collector_type'])),
            'collector_id' => (int) $event['collector_id'],
            'amount' => (float) $event['amount'],
            'collected_at' => $event['collected_at'],
            'evidence' => (string) $event['evidence'],
            'recorded_by' => $event['recorded_by'] ?? (auth('web')->id() ?: null),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }

    /**
     * The verified collection for an order, or null.
     *
     * Null while the table is absent, which is what keeps
     * `PaymentVerificationService::codMayBeSettled()` refusing every COD order until GATE F.
     *
     * @param \App\Models\Order $order
     */
    public function verifiedCollectionFor($order): ?object
    {
        if ($order === null || !$this->isAvailable()) {
            return null;
        }

        try {
            /*
             * THE store_id PREDICATE IS WRITTEN AT THE CALL SITE, and it has to be: a raw
             * `DB::table()` query is invisible to every Eloquent global scope, so there is no
             * other remedy. `RawQueryTenancyInventoryTest` exists to make exactly this omission
             * impossible to ship quietly, and it caught the first version of this method, which
             * keyed on `order_id` alone.
             *
             * `order_id` IS UNIQUE IN THE DRAFT SCHEMA, so the predicate looks redundant today.
             * It is not, for two reasons. The unique index is the first thing that has to change
             * when partial collection is designed -- the draft says so in terms -- and at that
             * moment a read keyed on order_id alone becomes ambiguous rather than wrong, which is
             * the harder kind of bug to notice. And an order whose store_id disagrees with the
             * event's is a data fault that should return nothing rather than settle something.
             */
            return DB::table('cod_collection_events')
                ->where('order_id', (int) $order->id)
                ->where('store_id', $order->store_id)
                ->orderBy('id')
                ->first();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
