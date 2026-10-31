<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * E13 / D-30 — the writer and reader for the durable stock-evidence record.
 *
 * ONE RULE GOVERNS EVERY METHOD HERE: **absence of evidence is reported as absence of evidence, and
 * never as absence of movement.** D-15 forbids inferring stock movement, and the most tempting place
 * to break that rule is a reader that answers "no rows" with "no decrement". So `movedOutFor()`
 * returns a three-state answer, and `available()` exists so a caller can tell "the record says no"
 * from "there is no record".
 *
 * WHY A SERVICE AND NOT AN ELOQUENT MODEL. Deliberately raw `DB::table()`:
 *
 *   - 79 of 95 models in `app/Models` are `Cachable`, and this table is written on the checkout
 *     path and read immediately afterwards by a cancellation or replacement. A `Cachable` model
 *     would serve a pre-write row from cache — the trap CLAUDE.md records three measured times —
 *     and here that would mean reading "no movement" for a movement that just happened.
 *   - F218 measured the other half of the same trap: a `Cachable` model's write FLUSHES the whole
 *     default store on a non-taggable driver, which erases the payment replay claims. A model here
 *     would add a third flush to the checkout path.
 *
 * Every statement names `store_id` explicitly. `Product` is not in `config/tenancy.php`'s
 * `scoped_models`, so nothing supplies a tenant predicate for free, and `ServiceLayerRawQueryInventoryTest`
 * ratchets that this file's raw queries carry one.
 */
class StockMovement
{
    public const OUT = 'out';
    public const IN = 'in';

    public const REASON_ORDER_PLACE = 'order_place';
    public const REASON_ORDER_CANCEL = 'order_cancel';
    public const REASON_ORDER_RETURN = 'order_return';
    public const REASON_REFUND = 'refund';
    public const REASON_MANUAL = 'manual';

    /** The three states a reader may be in about one order line. */
    public const MOVED = 'moved';
    public const NOT_MOVED = 'not_moved';
    public const UNKNOWN = 'unknown';

    private const TABLE = 'stock_movements';

    /**
     * Is the record installed at all?
     *
     * **This is the method that keeps D-15 honest.** The migration is development-and-test only, so
     * in production the table does not exist — and a caller that treats a missing table as "no
     * movement" would be making exactly the inference D-15 forbids, on every order. Any caller
     * deciding something about stock MUST consult this first and refuse when it is false.
     */
    public static function available(): bool
    {
        try {
            return Schema::hasTable(self::TABLE);
        } catch (\Throwable $theSchemaIsUnreadable) {
            return false;
        }
    }

    /**
     * Record one movement. Never throws, and never blocks the stock operation it describes.
     *
     * **A FAILURE TO RECORD MUST NOT FAIL THE CHECKOUT.** The stock write has already happened by
     * the time this is called, so raising here would turn a successful order into an error after
     * money may have been taken — the F121/F123 shape this project has already been bitten by twice.
     * It logs instead, and the gap then shows up as `UNKNOWN` to any later reader, which is the
     * correct answer rather than a convenient one.
     */
    public static function record(array $movement): void
    {
        if (! self::available()) {
            return;
        }

        $storeId = $movement['store_id'] ?? null;

        if (! is_numeric($storeId) || (int) $storeId <= 0) {
            /* No tenant, no row. A movement we cannot attribute to a store is not evidence, and
               writing it would put one tenant's row where another's query might read it. */
            Log::warning('stock movement not recorded: no store', ['movement' => $movement]);

            return;
        }

        try {
            DB::table(self::TABLE)->insert([
                'store_id' => (int) $storeId,
                'order_id' => isset($movement['order_id']) && is_numeric($movement['order_id'])
                    ? (int) $movement['order_id'] : null,
                'product_id' => isset($movement['product_id']) && is_numeric($movement['product_id'])
                    ? (int) $movement['product_id'] : null,
                'variant_id' => isset($movement['variant_id']) && is_numeric($movement['variant_id'])
                    ? (int) $movement['variant_id'] : null,
                'direction' => ($movement['direction'] ?? self::OUT) === self::IN ? self::IN : self::OUT,
                'quantity' => max(0, (int) ($movement['quantity'] ?? 0)),
                'applied' => (bool) ($movement['applied'] ?? false),
                'outcome' => isset($movement['outcome']) ? substr((string) $movement['outcome'], 0, 24) : null,
                'reason' => isset($movement['reason']) ? substr((string) $movement['reason'], 0, 32) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $theRecordCouldNotBeWritten) {
            Log::warning('stock movement not recorded', [
                'reason' => $theRecordCouldNotBeWritten->getMessage(),
                'movement' => $movement,
            ]);
        }
    }

    /**
     * Did this order line take stock OUT, and is it still out?
     *
     * Returns one of MOVED / NOT_MOVED / UNKNOWN. **UNKNOWN is not a failure mode, it is the whole
     * point**: it is what a caller gets when the record cannot answer, and D-15 requires that caller
     * to refuse rather than guess.
     *
     * "Still out" is `applied` outs minus `applied` ins for the same (store, order, line). A line
     * taken and then given back is NOT_MOVED on balance, which is what a replacement needs to know —
     * and this is the one place a count is legitimate, because it is scoped to a single line of a
     * single order rather than being summed into a stock balance.
     */
    public static function movedOutFor($storeId, $orderId, $productId = null, $variantId = null): string
    {
        if (! self::available()) {
            return self::UNKNOWN;
        }

        if (! is_numeric($storeId) || ! is_numeric($orderId)) {
            return self::UNKNOWN;
        }

        if (empty($productId) && empty($variantId)) {
            return self::UNKNOWN;
        }

        try {
            $query = DB::table(self::TABLE)
                ->where('store_id', (int) $storeId)
                ->where('order_id', (int) $orderId)
                ->where('applied', true);

            if (! empty($variantId)) {
                $query->where('variant_id', (int) $variantId);
            } else {
                $query->where('product_id', (int) $productId)->whereNull('variant_id');
            }

            $rows = $query->get(['direction', 'quantity']);

            if ($rows->isEmpty()) {
                /*
                 * NO ROWS IS NOT "NO MOVEMENT" UNLESS THE ORDER WAS OBSERVED AT ALL. An order placed
                 * before this record existed, or on an environment where it is absent, has no rows
                 * and did move stock. So the question becomes: did we observe ANYTHING for this
                 * order? If not, we cannot speak for it.
                 */
                $observed = DB::table(self::TABLE)
                    ->where('store_id', (int) $storeId)
                    ->where('order_id', (int) $orderId)
                    ->exists();

                return $observed ? self::NOT_MOVED : self::UNKNOWN;
            }

            $out = 0;

            foreach ($rows as $row) {
                $out += $row->direction === self::IN ? -(int) $row->quantity : (int) $row->quantity;
            }

            return $out > 0 ? self::MOVED : self::NOT_MOVED;
        } catch (\Throwable $theRecordCouldNotBeRead) {
            Log::warning('stock movement not readable', ['reason' => $theRecordCouldNotBeRead->getMessage()]);

            return self::UNKNOWN;
        }
    }

    /** Every movement recorded for one order, for a reviewer. Store-scoped. */
    public static function forOrder($storeId, $orderId)
    {
        if (! self::available() || ! is_numeric($storeId) || ! is_numeric($orderId)) {
            return collect();
        }

        return DB::table(self::TABLE)
            ->where('store_id', (int) $storeId)
            ->where('order_id', (int) $orderId)
            ->orderBy('id')
            ->get();
    }
}
