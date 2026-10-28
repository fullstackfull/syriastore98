<?php

namespace App\Exports;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * The merchant-facing order export (`Order.xlsx`), reached from `OrderController::fileExport`.
 *
 * P2-04-12 — WHY THIS CLASS MAPS BY KEY RATHER THAN BY POSITION.
 * =================================================================================================
 * It used to return whole `Order` models from `collection()` and pair them against a hardcoded
 * list of 19 headings. Nothing tied the two together: the data columns were *whatever the table's
 * physical column order happened to be*, minus an `unset()` list, and the headings were a separate
 * literal. The pairing was therefore an accident of the schema, re-rolled by every migration.
 *
 * MEASURED BEFORE THE CHANGE (sqlite, `OrderExportColumnAlignmentTest`): **19 headings against 28
 * data columns.** Nine columns shipped with no header at all -- `additional_note`,
 * `deliveryboy_id`, `payment_receipt`, `product_order_status`, `lottery_code`, `currency_code`,
 * `currency_symbol`, `created_by` and `order_source`.
 *
 * AND THE POSITIONAL PAIRING IS NOT SAFE ON MYSQL, WHICH IS WHERE PRODUCTION RUNS. Three shipped
 * migrations add order columns *mid-table*:
 *
 *   - `product_order_status` -> `after('return_price')`, and `lottery_code` after that
 *     (2024_11_08_111424), i.e. inserted directly beneath heading index 9, "Return Price";
 *   - `payment_receipt`      -> `after('payment_status')` (2024_10_22_103923);
 *   - `deliveryboy_id`       -> `after('additional_note')` (2024_03_28_034154).
 *
 * `after()` is a no-op on sqlite, which always appends -- so on the test database every added
 * column lands past the 19th and the headings line up, while on MySQL two columns sit between
 * "Return Price" and `payment_type`. Under the old code that shifts every heading from "Payment
 * Type" onward: a merchant reading "Delivered status" would be looking at `payment_type`. That
 * divergence CANNOT be reproduced on the test database by construction, which is why it survived.
 * It is NOT asserted here as a live production fact -- no MySQL instance was available to measure
 * it (see `docs/development/phase-2/PHASE-02-TEST-EVIDENCE.md`, F154) -- but the fix makes the
 * physical column order irrelevant either way, which is cheaper than being right about it.
 *
 * So headings and data now come from ONE list, `COLUMNS`, read by attribute name. A migration may
 * insert a column anywhere, `$appends` may grow, and the export is unaffected.
 *
 * THE FIRST 19 HEADINGS ARE UNCHANGED, verbatim and in order -- including the inconsistent casing
 * of "product Name" and "Payment status", which are left exactly as shipped because a merchant's
 * downstream spreadsheet may match on them. The nine columns that were already present but
 * unlabelled now carry a heading; no column was added, removed or moved, so no cell in an existing
 * export changes position.
 *
 * TWO PRE-EXISTING ODDITIES ARE PRESERVED DELIBERATELY, not inherited by accident:
 *
 *  1. `reward_points` was overwritten with the literal `1` for every row, discarding the stored
 *     value -- an order carrying 7 points exported as 1. That is a value a merchant may sum, so it
 *     is recorded as **F153** for an owner decision rather than silently corrected here; the
 *     literal is kept until that decision. `OrderExportColumnAlignmentTest` pins the current
 *     behaviour so the change, when authorised, is visible.
 *  2. "User Name" and "product Name" carry the customer's and product's NAME, not the id columns
 *     they are read from. That substitution is the point of those two headings and is retained.
 */
class OrderExport implements FromCollection, WithHeadings, WithMapping
{
    /**
     * heading => the Order attribute it is read from.
     *
     * The order of this array IS the column order of the file. The first nineteen entries are the
     * historical heading list, unchanged. `null` marks a column computed in `map()`.
     */
    private const COLUMNS = [
        'Order Id'         => 'product_order_id',
        'Order Date'       => 'order_date',
        'User Name'        => null,                 // customer name, resolved in map()
        'product Name'     => null,                 // product name, resolved in map()
        'Product Price'    => 'product_price',
        'Coupon Price'     => 'coupon_price',
        'Delivery Price'   => 'delivery_price',
        'Tax Price'        => 'tax_price',
        'Final Price'      => 'final_price',
        'Return Price'     => 'return_price',
        'Payment Type'     => 'payment_type',
        'Payment status'   => 'payment_status',
        'Delivered status' => 'delivered_status',
        'Delivered Date'   => 'delivery_date',
        'Confirmed Date'   => 'confirmed_date',
        'Picked Date'      => 'picked_date',
        'Return status'    => 'return_status',
        'Cancel Date'      => 'cancel_date',
        'Reward Points'    => null,                 // F153: the shipped literal, see the docblock
        // --- previously present in the file with no heading at all ---
        'Additional Note'  => 'additional_note',
        'Delivery Boy Id'  => 'deliveryboy_id',
        'Payment Receipt'  => 'payment_receipt',
        'Product Order Status' => 'product_order_status',
        'Lottery Code'     => 'lottery_code',
        'Currency Code'    => 'currency_code',
        'Currency Symbol'  => 'currency_symbol',
        'Created By'       => 'created_by',
        'Order Source'     => 'order_source',
    ];

    public function headings(): array
    {
        return array_keys(self::COLUMNS);
    }

    public function collection()
    {
        /*
         * P1-SEC-11 criterion 2. `getCurrentStore()` returns a hardcoded 1 for an operator whose
         * `current_store` is empty (helper.php:407-409), and `getStoreById(1)` is NULL on any
         * deployment whose stores do not start at 1 -- a first store that was deleted is enough.
         * This line then read `$store->id` and the export 500ed. `fileExport`'s own
         * `if (empty(getCurrentStore()))` guard cannot catch it, because the helper returned 1
         * rather than nothing.
         *
         * An unresolvable store exports NOTHING rather than guessing one: this class is reached
         * from a download response, so the alternative to an empty file is a stack trace. The
         * caller refuses with a message before getting here; this is the second line of defence,
         * because the class is constructible from anywhere.
         *
         * The helper is deliberately NOT changed -- config/tenancy.php:60-63 records two storefront
         * outages shipped by changing a helper this central.
         */
        $store = getStoreById(getCurrentStore());

        if ($store === null) {
            return collect([]);
        }

        return Order::where('store_id', $store->id)->get();
    }

    /**
     * @param  Order  $order
     */
    public function map($order): array
    {
        $row = [];

        foreach (self::COLUMNS as $heading => $attribute) {
            $row[] = $attribute === null
                ? $this->computed($heading, $order)
                : $order->getAttribute($attribute);
        }

        return $row;
    }

    private function computed(string $heading, Order $order)
    {
        switch ($heading) {
            case 'User Name':
                // `customer_id` is 0 for a guest order, and Customer is a scoped model.
                return Customer::find($order->customer_id)?->name ?? '';

            case 'product Name':
                // `orders.product_id` is a COMMA LIST for a multi-product order, so `find()`
                // resolves a name only for a single-product one. Shipped behaviour, retained.
                return Product::find($order->product_id)?->name ?? '';

            case 'Reward Points':
                // F153 -- the shipped literal, not `$order->reward_points`. See the docblock.
                return 1;
        }

        return '';
    }
}
