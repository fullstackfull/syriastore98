<?php

namespace Tests\Feature\Merchant;

use App\Exports\OrderExport;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2-04-12 / WS E10 — the merchant-facing order export (`Order.xlsx`).
 * =================================================================================================
 *
 * THE DEFECT THIS FILE EXISTS FOR IS A PAIRING, NOT A VALUE. `OrderExport` used to return whole
 * `Order` models and pair them against a separate hardcoded list of 19 headings, so the data
 * columns were whatever the table's physical column order happened to be and the headings were a
 * literal that no migration ever updated. The first measurement taken here found **19 headings
 * against 28 data columns** -- nine shipping with no header at all.
 *
 * AND POSITIONAL PAIRING DIVERGES BETWEEN THE TEST DATABASE AND PRODUCTION, which is the reason it
 * survived this long. Three shipped migrations insert order columns MID-TABLE
 * (`product_order_status` and `lottery_code` `after('return_price')`, `payment_receipt`
 * `after('payment_status')`, `deliveryboy_id` `after('additional_note')`). `after()` is a no-op on
 * sqlite -- it always appends -- so on this suite's database every added column lands past the 19th
 * and the old headings lined up, while on MySQL two of them sit between "Return Price" and
 * `payment_type`. No test could have caught that by construction, and no MySQL instance was
 * available to measure it (F154: the production claim is NOT asserted, only the fix).
 *
 * SO THE ASSERTIONS HERE ARE ABOUT NAMES, NOT INDEXES. `test_the_pairing_survives_a_scrambled
 * _attribute_order` is the one that matters: it hands `map()` a row whose attributes are in a
 * deliberately wrong order and requires each value to still arrive under its own heading. Reverting
 * `map()` to anything positional fails that case while the happy-path cases still pass.
 */
class OrderExportColumnAlignmentTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchant;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();
        $this->grantPermission(User::find($this->merchant->id), 'Manage Order');
        $this->merchant = User::find($this->merchant->id);
        $this->actingAs($this->merchant);
    }

    /** Pair every heading with the cell beneath it, by position in the emitted row. */
    private function exportedRow(Order $order): array
    {
        $export = new OrderExport();

        return array_combine($export->headings(), $export->map($order));
    }

    public function test_every_heading_has_a_column_and_every_column_has_a_heading(): void
    {
        $order = $this->makeOrder($this->store);
        $export = new OrderExport();

        $this->assertCount(
            count($export->headings()),
            $export->map($order),
            'the heading row and the data row are different widths, so at least one column in '
            . 'Order.xlsx is either unlabelled or labelled with another column\'s name'
        );
    }

    public function test_the_nine_columns_that_used_to_ship_unlabelled_now_carry_a_heading(): void
    {
        $headings = (new OrderExport())->headings();

        // Measured before the fix: present in the file, with no header above them.
        foreach ([
            'Additional Note', 'Delivery Boy Id', 'Payment Receipt', 'Product Order Status',
            'Lottery Code', 'Currency Code', 'Currency Symbol', 'Created By', 'Order Source',
        ] as $heading) {
            $this->assertContains($heading, $headings,
                "{$heading} is exported but has no heading, which is the state this row fixed");
        }
    }

    public function test_the_first_nineteen_headings_are_unchanged_and_in_order(): void
    {
        // A merchant's downstream spreadsheet may match on these strings, including the
        // inconsistent casing of 'product Name' and 'Payment status'. They ship as they were.
        $this->assertSame([
            'Order Id', 'Order Date', 'User Name', 'product Name', 'Product Price', 'Coupon Price',
            'Delivery Price', 'Tax Price', 'Final Price', 'Return Price', 'Payment Type',
            'Payment status', 'Delivered status', 'Delivered Date', 'Confirmed Date', 'Picked Date',
            'Return status', 'Cancel Date', 'Reward Points',
        ], array_slice((new OrderExport())->headings(), 0, 19));
    }

    public function test_each_money_column_holds_its_own_figure(): void
    {
        // Distinct values, so a one-column shift is visible rather than plausible.
        $order = $this->makeOrder($this->store, [
            'product_price' => 11, 'coupon_price' => 22, 'delivery_price' => 33,
            'tax_price' => 44, 'final_price' => 55, 'return_price' => 66,
        ]);

        $row = $this->exportedRow($order);

        $this->assertEquals(11, $row['Product Price']);
        $this->assertEquals(22, $row['Coupon Price']);
        $this->assertEquals(33, $row['Delivery Price']);
        $this->assertEquals(44, $row['Tax Price']);
        $this->assertEquals(55, $row['Final Price']);
        $this->assertEquals(66, $row['Return Price']);
    }

    public function test_the_payment_and_fulfilment_columns_hold_their_own_values(): void
    {
        // These are the columns the mid-table inserts sit above: under positional pairing on
        // MySQL, `payment_type` would print beneath 'Delivered status'.
        $order = $this->makeOrder($this->store, [
            'payment_type' => 'bank_transfer',
            'payment_status' => 'Paid',
            'delivered_status' => 4,
            'return_status' => 2,
        ]);

        $row = $this->exportedRow($order);

        $this->assertSame('bank_transfer', $row['Payment Type']);
        $this->assertSame('Paid', $row['Payment status']);
        $this->assertEquals(4, $row['Delivered status']);
        $this->assertEquals(2, $row['Return status']);
    }

    public function test_the_pairing_survives_a_scrambled_attribute_order(): void
    {
        /*
         * THE POINT OF THE WHOLE ROW. A migration may insert a column anywhere, and on MySQL three
         * of them already do. This hands `map()` a row whose attributes are in an order no schema
         * would produce and requires every value to still arrive under its own heading, which is
         * only true if the mapping reads attributes BY NAME.
         */
        $order = $this->makeOrder($this->store, [
            'payment_type' => 'cod', 'final_price' => 999, 'delivered_status' => 1,
        ]);

        $scrambled = new Order();
        $scrambled->setRawAttributes(array_reverse($order->fresh()->getAttributes(), true), true);

        $row = array_combine((new OrderExport())->headings(), (new OrderExport())->map($scrambled));

        $this->assertEquals(999, $row['Final Price'],
            'the export pairs columns by position, so reordering the row moved a money value '
            . 'under another heading -- the MySQL mid-table-insert defect');
        $this->assertSame('cod', $row['Payment Type']);
        $this->assertEquals(1, $row['Delivered status']);
    }

    public function test_order_source_is_exported_under_its_own_heading(): void
    {
        // P2-04-11 added the column; before this row it reached the file with no header.
        $order = $this->makeOrder($this->store, ['order_source' => Order::SOURCE_POS]);

        $this->assertSame(Order::SOURCE_POS, $this->exportedRow($order)['Order Source']);
    }

    public function test_an_order_with_no_recorded_source_exports_an_empty_cell(): void
    {
        // Not the string 'storefront', and not a claim about the record's age -- F148.
        $order = $this->makeOrder($this->store);
        $order->order_source = null;
        $order->save();

        $this->assertNull($this->exportedRow($order->fresh())['Order Source']);
    }

    public function test_reward_points_exports_the_shipped_literal_pending_an_owner_decision(): void
    {
        /*
         * F153. The export has always overwritten this column with the literal 1, discarding the
         * stored value: an order carrying 7 points exports as 1. It is a figure a merchant may
         * sum, so it is recorded for an owner decision rather than corrected inside a UI row. This
         * case pins the CURRENT behaviour so the change, once authorised, cannot land unnoticed.
         */
        $order = $this->makeOrder($this->store);
        $order->reward_points = 7;
        $order->save();

        $this->assertEquals(1, $this->exportedRow($order->fresh())['Reward Points'],
            'reward_points no longer exports the shipped literal -- if that is intended, F153 has '
            . 'been decided and this test states the new contract instead');
    }

    public function test_the_two_name_columns_hold_names_rather_than_ids(): void
    {
        $order = $this->makeOrder($this->store, ['customer_id' => 0, 'product_id' => 0]);

        $row = $this->exportedRow($order);

        // No customer and no product resolve, so both are empty strings rather than `0`.
        $this->assertSame('', $row['User Name']);
        $this->assertSame('', $row['product Name']);
    }

    public function test_the_export_holds_only_the_callers_own_store(): void
    {
        $this->makeOrder($this->store, ['product_order_id' => 'MINE-1']);

        $other = $this->makeMerchant();
        $otherStore = $this->makeStore($other);
        $this->makeOrder($otherStore, ['product_order_id' => 'THEIRS-1']);

        $ids = (new OrderExport())->collection()->pluck('product_order_id')->all();

        $this->assertContains('MINE-1', $ids);
        $this->assertNotContains('THEIRS-1', $ids,
            'the export carries another store\'s orders');
    }

    public function test_the_export_is_empty_when_no_store_resolves(): void
    {
        // P1-SEC-11 criterion 2: the second line of defence, because the class is constructible
        // from anywhere. An unresolvable store exports nothing rather than guessing one.
        $this->makeOrder($this->store);

        $staff = $this->makeMerchant(['type' => 'staff', 'created_by' => $this->merchant->id]);
        $staff->current_store = null;
        $staff->save();
        $this->actingAs(User::find($staff->id));

        $this->assertCount(0, (new OrderExport())->collection());
    }

    // =============================================================================================
    //  THE TWO NAME LOOKUPS, ACROSS A STORE BOUNDARY  (gap closed after independent review)
    // =============================================================================================

    /*
     * The independent tenancy review of this change noted that no case drove a CROSS-STORE id
     * through `computed()` — `test_the_two_name_columns_hold_names_rather_than_ids` uses id 0,
     * which resolves to nothing for a reason that has nothing to do with tenancy. These two cases
     * close that, and they deliberately record OPPOSITE answers, because the two models differ:
     * `Customer` is allowlisted in `config/tenancy.php` and really filters, `Product` is not in
     * `scoped_models` at all and so adds no predicate for any principal.
     */

    public function test_a_customer_from_another_tenant_does_not_resolve_a_name(): void
    {
        $outsider = \App\Models\Customer::forceCreate([
            'first_name' => 'Zebra', 'last_name' => 'Outsider',
            'email' => 'outsider-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $this->makeStore($this->makeMerchant())->id,
            'status' => 1, 'register_type' => 'email',
        ]);

        // An order in MY store that names a customer belonging to a tenant I am not inside.
        $order = $this->makeOrder($this->store, ['customer_id' => $outsider->id]);

        $this->assertSame('', $this->exportedRow($order)['User Name'],
            'the export resolved a customer name from a tenant the caller is not inside');
    }

    public function test_a_product_from_another_store_still_resolves_because_product_is_unscoped(): void
    {
        /*
         * RECORDING SHIPPED BEHAVIOUR, NOT ENDORSING IT. `Product` is absent from
         * `config/tenancy.php`'s `scoped_models`, so `StoreScope::isActiveFor()` short-circuits and
         * `Product::find()` reads across every tenant for EVERY principal — this is the same
         * absence that produced the S19 cross-tenant stock drain. Here the leaked value is a
         * product NAME, which the shop page already serves to anonymous visitors, so the export is
         * left reproducing what it always did rather than widened in a UI row.
         *
         * The case exists so the behaviour is visible and pinned: if `Product` is ever added to
         * `scoped_models`, this test fails and tells whoever did it that the export's output
         * changed with it.
         */
        $foreignStore = $this->makeStore($this->makeMerchant());
        $foreign = \App\Models\Product::forceCreate([
            'name' => 'QUUX-FOREIGN', 'slug' => 'quux-foreign-' . uniqid(),
            'price' => 10, 'sale_price' => 10, 'cost_price' => 4,
            'product_stock' => 1, 'track_stock' => 1, 'variant_product' => 0,
            'stock_status' => 'in_stock', 'status' => 1,
            'store_id' => $foreignStore->id, 'created_by' => $foreignStore->created_by,
        ]);

        $order = $this->makeOrder($this->store, ['product_id' => $foreign->id]);

        $this->assertSame('QUUX-FOREIGN', $this->exportedRow($order)['product Name'],
            'Product has become scoped, or the export now filters it — either way the export no '
            . 'longer reproduces the shipped behaviour this case records');
    }
}
