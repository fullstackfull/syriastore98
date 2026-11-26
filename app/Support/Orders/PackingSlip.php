<?php

namespace App\Support\Orders;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MASTER PLAN `ORD-008` (GAP-185) — THE PACKING DOCUMENT. WHAT GOES IN THE PARCEL, AND NOTHING ELSE.
 * ==================================================================================================
 *
 * Plan text read: `docs/development/master-plan/MASTER-PRODUCT-PLAN-v1.0-WITH-AMENDMENTS.md:287`,
 * section 04-C (الطلبات والتجهيز) — «Picking وPacking والباركود والملصقات والفواتير.» /
 * *Picking, Packing, barcodes, labels and invoices*. One clause, five nouns.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT WAS ALREADY THERE, MEASURED, BECAUSE `GAP-185`'s ROW IS WRONG ABOUT ONE OF THEM.
 *
 * The row reads *"Picking, packing, packing slips and labels are not located."* Labels ARE located,
 * and so are invoices, which the row does not mention at all:
 *
 *   barcodes  `packages/workdo/ProductBarCode` — adds `products.product_code`, prints barcodes and
 *             QR codes, and has its own POS barcode view.
 *   labels    `OrderController::shippinglabel()` → `resources/views/order/shippinglabel.blade.php`,
 *             a FIRST-PARTY host document (P1-SEC-11 fixed its store resolution and its `dir`), plus
 *             `AutomaticOrderPrinting`'s `shippinglabel`/`printinglabel` with ten templates.
 *   invoices  `OrderController::order_receipt()` → `resources/views/order/receipt.blade.php`, plus
 *             `AutomaticOrderPrinting::invoice_preview()`. All of these are already registered in
 *             `OrderDocumentAccessGuard::TARGETS`.
 *
 * Picking and packing really are absent, and that half of the row holds. Measured rather than
 * asserted: a whole-word search for `picking|packing|picklist|pick_list|packing_slip|packingslip`
 * across `app/`, `resources/views/`, `packages/workdo/` and `database/` returns **13** hits, of
 * which **11** are English prose in comments about unrelated subjects ("picking one would be
 * inventing an approved sending frequency") and **2** are the `AutomaticOrderPrinting` marketplace
 * blurb's "ready for packing and dispatch". **Zero** are a picking or packing surface.
 * `orders.picked_date` and `FulfilmentStateMachine::STATE_PICKED_UP` are the COURIER collecting the
 * parcel — the value sits between Confirmed and Shipped — not warehouse picking.
 *
 * -------------------------------------------------------------------------------------------------
 * NO MONEY ON A PACKING SLIP, AND THAT IS WHY THIS CLASS CAN EXIST WITHOUT AN OWNER DECISION.
 *
 * A packing slip travels inside the parcel and lists what is in it; the priced document is the
 * receipt, which already exists. So this builder carries **no price, no tax, no discount and no
 * total** — which is not a simplification but the document's definition, and it is the reason this
 * is engineering rather than a question: there is no figure to invent and no pricing policy to
 * choose. Everything on the slip is already in the order record.
 *
 * -------------------------------------------------------------------------------------------------
 * THE QUANTITY KEY IS NOT ONE KEY, AND READING ONLY ONE WOULD SILENTLY SHIP EMPTY PARCELS.
 *
 * `Order::order_detail()` normalises each line differently depending on the order's `payment_type`:
 * a `POS` order's line carries **`quantity`** and every other order's carries **`qty`** (the two
 * branches are otherwise identical). A reader that took `qty` alone would print `0` for every
 * till order, so both are read, `quantity` first because it is the explicit one.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT THIS CLASS DELIBERATELY DOES NOT DO.
 *
 *   * **It adds no fulfilment STAGE.** "Picked" and "packed" as order states would mean new values
 *     in `orders.delivered_status` — a column that already carries a fulfilment state, a payment
 *     state and an order type at once (`FulfilmentStateMachine`'s own docblock calls that a schema
 *     problem it must not pretend to have solved) — and in `App\Support\Api\OrderStatusContract`,
 *     which the shipped Flutter admin app reads. That is a public-API compatibility change and a
 *     change to `ORD-002`'s fixed general stages, both reserved to the owner.
 *   * **It does not scope by tenant, and must not be given a tenant predicate of its own.** It is a
 *     pure projection of lines its CALLER has already authorised: the only caller is the order
 *     detail screen, behind `OrderDocumentAccessGuard`, which decides the row. A second predicate
 *     here would be a check that can never disagree with the one before it — depth that cannot fail
 *     a test. The product and variant lookups below are keyed on the order's OWN line ids, and the
 *     store id is passed in so the `product_code` read cannot reach another tenant's catalogue
 *     (`Product` is in no `scoped_models` list, so nothing else would stop it).
 */
final class PackingSlip
{
    /**
     * Build the slip's lines and counts from `Order::order_detail()`'s `product` array.
     *
     * @param  iterable<array-key, array<string, mixed>>  $lines  `$order['product']`
     * @param  int  $storeId  the ORDER's store, for the catalogue lookups
     * @return array{
     *     lines: list<array{product_id: ?int, variant_id: ?int, name: string, variant_name: ?string, code: ?string, quantity: int, returned: bool}>,
     *     units: int,
     *     distinct: int,
     *     returned: int
     * }
     */
    public static function forLines(iterable $lines, int $storeId): array
    {
        $lines = is_array($lines) ? $lines : iterator_to_array($lines);

        $codes = self::productCodes($lines, $storeId);
        $skus = self::variantSkus($lines);

        $built = [];
        $units = 0;
        $returned = 0;

        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $productId = self::id($line['product_id'] ?? null);
            $variantId = self::id($line['variant_id'] ?? null);
            $quantity = self::quantity($line);
            $isReturned = ! empty($line['return']);

            $built[] = [
                'product_id' => $productId,
                'variant_id' => $variantId,
                /* The order's OWN recorded name, not the catalogue's: a product renamed after the
                   order must not change what the parcel says was in it. */
                'name' => trim((string) ($line['name'] ?? '')),
                'variant_name' => self::text($line['variant_name'] ?? null),
                'code' => ($variantId !== null ? ($skus[$variantId] ?? null) : null)
                    ?? ($productId !== null ? ($codes[$productId] ?? null) : null),
                'quantity' => $quantity,
                'returned' => $isReturned,
            ];

            $units += $quantity;

            if ($isReturned) {
                $returned++;
            }
        }

        return [
            'lines' => $built,
            'units' => $units,
            'distinct' => count($built),
            'returned' => $returned,
        ];
    }

    /**
     * The line's quantity, from whichever key this order's `payment_type` branch wrote.
     *
     * `quantity` first: it is the POS branch's explicit key, and a line carrying both is a POS line.
     * A non-numeric or negative value is 0 — a packing slip that prints `-3` is worse than one that
     * prints `0`, and `filter_var` is used rather than `is_numeric` for the reason the skill records
     * (`is_numeric` accepts `'5.9'` and rejects `'5abc'`, and MySQL reads `'5abc'` as 5).
     *
     * @param  array<string, mixed>  $line
     */
    private static function quantity(array $line): int
    {
        foreach (['quantity', 'qty'] as $key) {
            if (! array_key_exists($key, $line)) {
                continue;
            }

            $value = $line[$key];

            if (is_int($value) || is_float($value) || (is_string($value) && trim($value) !== '')) {
                $parsed = filter_var(trim((string) $value), FILTER_VALIDATE_INT);

                if ($parsed !== false && $parsed > 0) {
                    return $parsed;
                }

                return 0;
            }
        }

        return 0;
    }

    /**
     * `products.product_code` for the order's own product ids, scoped to the order's store.
     *
     * The column is added by `packages/workdo/ProductBarCode`'s own migration, so it does not exist
     * on an installation that has never enabled the module — hence `Schema::hasColumn()` rather than
     * a try/catch. That is the ORD-008 "barcode" noun arriving on the packing document, which is how
     * the plan's clause groups them.
     *
     * @param  list<mixed>  $lines
     * @return array<int, string>
     */
    private static function productCodes(array $lines, int $storeId): array
    {
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'product_code')) {
            return [];
        }

        $ids = self::idsOf($lines, 'product_id');

        if ($ids === []) {
            return [];
        }

        return DB::table('products')
            ->whereIn('id', $ids)
            ->where('store_id', $storeId)
            ->whereNotNull('product_code')
            ->pluck('product_code', 'id')
            ->map(fn ($code) => (string) $code)
            ->filter(fn (string $code) => trim($code) !== '')
            ->all();
    }

    /**
     * `product_variants.sku` for the order's own variant ids.
     *
     * No store predicate, and that is deliberate rather than an omission: `product_variants.store_id`
     * is nullable and the variant is reached through the order's own recorded `variant_id`, so a
     * store filter here would blank the SKU on legacy rows that carry no store. A variant id the
     * order recorded is the order's own; the caller has already been authorised for the order.
     *
     * @param  list<mixed>  $lines
     * @return array<int, string>
     */
    private static function variantSkus(array $lines): array
    {
        $ids = self::idsOf($lines, 'variant_id');

        if ($ids === [] || ! Schema::hasTable('product_variants')) {
            return [];
        }

        return DB::table('product_variants')
            ->whereIn('id', $ids)
            ->whereNotNull('sku')
            ->pluck('sku', 'id')
            ->map(fn ($sku) => (string) $sku)
            ->filter(fn (string $sku) => trim($sku) !== '')
            ->all();
    }

    /**
     * @param  list<mixed>  $lines
     * @return list<int>
     */
    private static function idsOf(array $lines, string $key): array
    {
        $ids = [];

        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $id = self::id($line[$key] ?? null);

            if ($id !== null) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    private static function id(mixed $value): ?int
    {
        if (is_array($value) || is_object($value) || $value === null || $value === '') {
            return null;
        }

        $parsed = filter_var(trim((string) $value), FILTER_VALIDATE_INT);

        return ($parsed === false || $parsed <= 0) ? null : $parsed;
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
