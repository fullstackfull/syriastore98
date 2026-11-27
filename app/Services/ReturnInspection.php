<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RET-002 — "فحص حالة المنتج قبل إعادته للبيع": INSPECT THE PRODUCT'S CONDITION BEFORE RETURNING
 * IT TO SALE.
 *
 * `docs/development/master-plan/DECISION-ID-REGISTER.md` carries this as `RET-002`, one of the
 * seven `RET-*` decisions of the plan's §04-F. **None of the seven appears in
 * `MASTER-PLAN-REQUIREMENT-TRACEABILITY.md` or in `MASTER-PLAN-GAP-REGISTER.md`** (measured
 * 2026-11-26 by grep over both), so this decision has never been tracked, built or tested.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * WHAT WAS THERE BEFORE: NOTHING, AND THREE DOORS THAT DID NOT ASK
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * A census over `app/` and `database/migrations/` for `inspect`, `resellable`, `quarantine`,
 * `damaged` and a quoted `condition` finds, outside unrelated prose, only `FlashSale`'s rule
 * vocabulary and `Customer::$fields_status`. Neither is about a returned item. The single string
 * in the platform that names an item's condition is `OrderRefund::RefundReason()`'s *"The Product
 * Was Damaged Upon Arrival."* — a SHOPPER-STATED reason, written by
 * `AccountProfileController::order_refund_request()` and read by three Blade files to print it.
 * **No code reads it to decide anything**, so a shopper saying the goods arrived broken changed
 * nothing about whether those goods went back on sale.
 *
 * And three doors put returned goods back into SELLABLE stock:
 *
 *   1. `OrderRefundController::RefundStock()`     — the refund screen's Manage Stock action. Gated
 *      by `RefundPolicy::MANAGE_STOCK`, clamped to the units the lines lost, idempotent.
 *   2. `Order::performProductReturn()`            — the merchant marks ONE line returned. Restocks
 *      in the SAME call, with no per-store toggle at all.
 *   3. `OrderController::restoreReturnedStock()`  — the whole-order return approval (`status == 2`).
 *      Also restocks in the same call, also with no toggle.
 *
 * Every control on those three doors is about **how many** units and **whether twice**. Not one of
 * them is about **what state the goods came back in**. That is the whole of RET-002, and it was
 * absent.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * WHAT THIS DOES, AND THE ONE THING IT DELIBERATELY DOES NOT
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * It records a verdict per returned line and it BLOCKS a restock of a line whose verdict says the
 * goods are not resellable. That is the enforceable half of RET-002 and it is enforced at all
 * three doors.
 *
 * **IT DOES NOT REQUIRE AN INSPECTION BEFORE ANY RESTOCK.** With no verdict recorded,
 * {@see blocksRestock()} answers `false` and every door behaves byte-for-byte as it did before
 * this class existed. That is a deliberate boundary, for a measured reason rather than caution:
 * every store on this platform has recorded zero verdicts, so a "no restock without an inspection"
 * rule would stop **every** restock at **every** store on the day it shipped — withholding goods a
 * merchant physically holds from sale, which is a stock-policy change and an owner escalation
 * under the standing rules. Making it mandatory is one per-store boolean away (the
 * `order_refund_settings` shape `RefundPolicy` already reads), and the decision is the owner's.
 * See the report that accompanies this change.
 *
 * So the direction of this class is: it can only ever credit LESS stock than before, never more,
 * and only where a human has explicitly said the goods are damaged.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * NO ELOQUENT MODEL, AND THAT IS NOT LAZINESS
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Three reasons, each one a trap this codebase has already paid for:
 *
 *   1. **Most models in `app/Models/` are `Cachable`** (CLAUDE.md). A gate on stock that
 *      can serve a verdict from before the merchant's own save is worse than no gate: it would
 *      restock damaged goods *and* show the merchant their verdict on the screen. `RefundPolicy`
 *      reads `order_refund_settings` raw for exactly this reason and says so.
 *   2. A model would need `BelongsToStore` to look tenant-aware, and `StoreScope::isActiveFor()`
 *      short-circuits for any model absent from `config/tenancy.php`'s `scoped_models` — so the
 *      trait would register a scope that **adds no predicate**, while `getGlobalScopes()` reported
 *      one. That is the `Product`/`Category` trap verbatim, and a reader checking the wrong
 *      question would conclude this table was scoped when it is not.
 *   3. Adding to `scoped_models` is a five-condition audit (`config/tenancy.php`) and a config
 *      decision outside this slice.
 *
 * **So the `store_id` predicate is written at every call site here, explicitly**, which is the
 * position `RefundIntegrityService::awaitingSettlement()` takes in code. (Its docblock's *reason*
 * — that `OrderRefund` "carries no StoreScope and is not in `scoped_models`" — is measurably
 * wrong: `OrderRefund::class` IS on that list. The code is right and the explanation is not; the
 * accompanying report records it.)
 *
 * All three statements below name `store_id`, which is why
 * `Feature\Tenancy\ServiceLayerRawQueryInventoryTest` counts them in `$scoped` and not in
 * `$storeLess`.
 */
class ReturnInspection
{
    public const TABLE = 'order_return_inspections';

    /** Inspected, in saleable condition. The only verdict that returns goods to sale. */
    public const VERDICT_RESELLABLE = 'resellable';

    /** Inspected, not saleable. */
    public const VERDICT_DAMAGED = 'damaged';

    /**
     * Held. Received but not yet judged, or judged and set aside.
     *
     * NOT resellable, because "I have not decided" is not a licence to sell. This is the same
     * fail-closed reading `OrderRefundController::illegalTransition()` applies to an unrecognised
     * refund state: *"I do not know what state this is in" is not a licence to move money*.
     */
    public const VERDICT_QUARANTINED = 'quarantined';

    /**
     * The vocabulary, and the derived answer for each term.
     *
     * ONE map, so `resellable` cannot drift from `verdict`. `record()` is the only writer and takes
     * the boolean from here rather than from its caller, which is what makes the stored column a
     * cache of this map instead of a second opinion.
     *
     * @var array<string, bool>
     */
    public const VERDICTS = [
        self::VERDICT_RESELLABLE => true,
        self::VERDICT_DAMAGED => false,
        self::VERDICT_QUARANTINED => false,
    ];

    /**
     * Record (or replace) the condition verdict for one returned line.
     *
     * @param  mixed  $storeId    The owning store. Refused unless numeric and positive.
     * @param  mixed  $orderId    The order the line belongs to.
     * @param  mixed  $productId  The line's product.
     * @param  mixed  $variantId  The line's variant, or 0 for a simple product.
     * @param  string $verdict    One of {@see VERDICTS}. Anything else is refused.
     * @param  mixed  $actorId    `users.id` of whoever inspected, or null.
     * @param  mixed  $units      Units this verdict covers, or null.
     * @param  mixed  $notes      Free text, or null.
     * @return bool               Whether a row was written.
     */
    public function record(
        $storeId,
        $orderId,
        $productId,
        $variantId,
        string $verdict,
        $actorId = null,
        $units = null,
        $notes = null
    ): bool {
        if (! array_key_exists($verdict, self::VERDICTS)) {
            /*
             * An unrecognised verdict is REFUSED rather than stored. A row whose `verdict` is not
             * in the map would read `resellable = 0` by the column default and so would block the
             * line -- a stock decision taken by a typo. The caller is told nothing was written.
             */
            Log::warning('A return inspection was refused: the condition verdict is not one this platform knows', [
                'store_id' => $storeId,
                'order_id' => $orderId,
                'product_id' => $productId,
                'verdict' => $verdict,
                'known' => array_keys(self::VERDICTS),
            ]);

            return false;
        }

        $key = $this->lineKey($storeId, $orderId, $productId, $variantId);

        if ($key === null) {
            return false;
        }

        try {
            /*
             * THE FOUR KEY COLUMNS ARE SPELLED OUT RATHER THAN PASSED AS AN `$key` VARIABLE, and
             * that is not style. A first version wrote `->updateOrInsert($key, [...])` and
             * `Feature\Tenancy\ServiceLayerRawQueryInventoryTest` refused it: the statement named
             * `store_id` NOWHERE, so the census could not tell a scoped write from a platform-wide
             * one and demanded a `STORE_LESS_BY_DESIGN` declaration this write does not deserve.
             * The census was right, and about more than itself — a reader of the statement could
             * not see the tenancy predicate either. It is written here where both can see it.
             *
             * `upsert()` RATHER THAN `updateOrInsert()`, so `created_at` SURVIVES A RE-INSPECTION.
             * `updateOrInsert($attributes, $values)` applies one `$values` array to both the insert
             * and the update, so a second verdict on the same line rewrote `created_at` to `now()`
             * and the time of the FIRST inspection was gone — `created_at` would have equalled
             * `updated_at` on every row, which is a column that says nothing while looking as
             * though it says when the goods were first examined. `upsert()`'s third argument names
             * the columns an existing row may change, and `created_at` is deliberately absent from
             * it. (This needs the unique index the migration declares; without it MySQL would
             * insert duplicates and SQLite would raise on the `ON CONFLICT` target, so
             * `ReturnConditionInspectionTest::test_a_re_inspection_replaces_the_verdict_rather_than_adding_a_second_one`
             * is the premise test for the index as much as for this call.)
             */
            DB::table(self::TABLE)->upsert([[
                'store_id' => $key['store_id'],
                'order_id' => $key['order_id'],
                'product_id' => $key['product_id'],
                'variant_id' => $key['variant_id'],
                'verdict' => $verdict,
                'resellable' => self::VERDICTS[$verdict] ? 1 : 0,
                'units' => is_numeric($units) && (int) $units > 0 ? (int) $units : null,
                'notes' => is_string($notes) && $notes !== '' ? mb_substr($notes, 0, 2000) : null,
                'inspected_by' => is_numeric($actorId) && (int) $actorId > 0 ? (int) $actorId : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]], [
                'store_id', 'order_id', 'product_id', 'variant_id',
            ], [
                'verdict', 'resellable', 'units', 'notes', 'inspected_by', 'updated_at',
            ]);
        } catch (\Throwable $theWriteFailed) {
            /*
             * `\Throwable` and not `QueryException`, for the reason `RefundPolicy::allows()` gives:
             * a narrow catch defends against one CAUSE of the outcome rather than the outcome. An
             * absent table, a renamed column, a lost connection and an `\Error` from the logger all
             * end the same way, and the caller needs to be told the verdict was not stored --
             * telling a merchant their inspection was recorded when it was not is how damaged goods
             * reach a shopper.
             */
            Log::error('A return inspection could not be recorded', [
                'store_id' => $storeId,
                'order_id' => $orderId,
                'product_id' => $productId,
                'verdict' => $verdict,
                'error' => $theWriteFailed->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * MUST this line be kept out of sellable stock?
     *
     * True **only** when a verdict exists for this exact line and that verdict is not resellable.
     *
     * ──────────────────────────────────────────────────────────────────────────────────────────
     * ABSENT MEANS "NOT BLOCKED", AND A READ FAILURE MEANS THE SAME — the opposite of
     * `RefundPolicy`, deliberately, because the two gates fail in opposite directions
     * ──────────────────────────────────────────────────────────────────────────────────────────
     *
     * `RefundPolicy::allows()` fails CLOSED: it gates an action, and refusing the action changes
     * nothing in the world. This gate BLOCKS an action, so failing closed here means refusing to
     * credit stock a merchant physically holds — on no evidence at all, because a read that threw
     * read no verdicts.
     *
     * The measured case is not hypothetical. `RefundStockIdempotencyTest` used to build a schema by
     * hand with no `order_refund_settings` table and `RefundPolicy`'s read raised *"no such table"*
     * out of a gate; a fresh environment that has not run this slice's migration is the same shape
     * for this table. Failing closed there would break all three restock doors everywhere,
     * **including every environment where no inspection has ever been recorded** — which is the
     * entire platform. So: no table, no verdicts, nothing blocked, and a `warning` so the reason is
     * readable rather than silent.
     *
     * The direction is stated here so it is falsifiable, and
     * `ReturnConditionInspectionTest::test_an_unreadable_inspection_table_blocks_nothing` pins it.
     */
    public function blocksRestock($storeId, $orderId, $productId, $variantId): bool
    {
        $key = $this->lineKey($storeId, $orderId, $productId, $variantId);

        if ($key === null) {
            return false;
        }

        try {
            $resellable = DB::table(self::TABLE)
                ->where('store_id', $key['store_id'])
                ->where('order_id', $key['order_id'])
                ->where('product_id', $key['product_id'])
                ->where('variant_id', $key['variant_id'])
                ->orderBy('id')
                ->pluck('resellable')
                ->last();
        } catch (\Throwable $theReadFailed) {
            /* NO APOSTROPHE IN THIS MESSAGE, deliberately. `SecurityLogInventoryTest`'s source
               census captures `[^'"]{10,200}` after the quote, so an escaped apostrophe truncates
               the captured text at the backslash and the inventory row reads as a fragment. */
            Log::warning('The condition of a returned line could not be read; the restock was not blocked', [
                'store_id' => $storeId,
                'order_id' => $orderId,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'error' => $theReadFailed->getMessage(),
            ]);

            return false;
        }

        if ($resellable === null) {
            // No verdict for this line. Behaviour before this class existed.
            return false;
        }

        return ! (is_numeric($resellable) && (int) $resellable === 1);
    }

    /**
     * The verdicts recorded against one order, keyed by `productId:variantId`.
     *
     * For the merchant screen and for a caller that wants every line at once rather than one
     * question per line. Store-scoped by argument, like every read here.
     *
     * @return array<string, array<string, mixed>>
     */
    public function forOrder($storeId, $orderId): array
    {
        if (! is_numeric($storeId) || (int) $storeId <= 0 || ! is_numeric($orderId) || (int) $orderId <= 0) {
            return [];
        }

        try {
            $rows = DB::table(self::TABLE)
                ->where('store_id', (int) $storeId)
                ->where('order_id', (int) $orderId)
                ->orderBy('id')
                ->get();
        } catch (\Throwable $theReadFailed) {
            Log::warning('The return inspections for an order could not be listed', [
                'store_id' => $storeId,
                'order_id' => $orderId,
                'error' => $theReadFailed->getMessage(),
            ]);

            return [];
        }

        $byLine = [];

        foreach ($rows as $row) {
            $byLine[(int) $row->product_id . ':' . (int) $row->variant_id] = [
                'verdict' => (string) $row->verdict,
                'resellable' => (int) $row->resellable === 1,
                'units' => $row->units === null ? null : (int) $row->units,
                'notes' => $row->notes,
                'inspected_by' => $row->inspected_by === null ? null : (int) $row->inspected_by,
                'recorded_at' => $row->updated_at,
            ];
        }

        return $byLine;
    }

    /**
     * The four-column identity of a returned line, or null when it is not usable.
     *
     * `variant_id` collapses to 0 for a simple product, which is the spelling every return door
     * already uses (`$variant_id == 0 || empty($variant_id)` in `Order::performProductReturn()`
     * and `OrderController::restoreReturnedStock()`) and the one the unique index needs — see the
     * migration's header on why the column is NOT NULL.
     *
     * @return array<string, int>|null
     */
    private function lineKey($storeId, $orderId, $productId, $variantId): ?array
    {
        if (! is_numeric($storeId) || (int) $storeId <= 0) {
            return null;
        }

        if (! is_numeric($orderId) || (int) $orderId <= 0) {
            return null;
        }

        if (! is_numeric($productId) || (int) $productId <= 0) {
            return null;
        }

        return [
            'store_id' => (int) $storeId,
            'order_id' => (int) $orderId,
            'product_id' => (int) $productId,
            'variant_id' => is_numeric($variantId) && (int) $variantId > 0 ? (int) $variantId : 0,
        ];
    }
}
