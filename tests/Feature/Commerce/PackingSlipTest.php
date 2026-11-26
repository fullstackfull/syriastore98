<?php

namespace Tests\Feature\Commerce;

use App\Support\Orders\PackingSlip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MASTER PLAN `ORD-008` (GAP-185) — THE PACKING DOCUMENT'S CONTENT.
 * ==================================================================
 *
 * Plan text: `docs/development/master-plan/MASTER-PRODUCT-PLAN-v1.0-WITH-AMENDMENTS.md:287`,
 * section 04-C — «Picking وPacking والباركود والملصقات والفواتير.» The barcode, label and invoice
 * nouns are already built (see `App\Support\Orders\PackingSlip`'s docblock for where); picking and
 * packing are not, and this is the packing half.
 *
 * THE TWO CASES THAT COST SOMETHING, named here so they are not read as padding:
 *
 *   1. **The quantity key is not one key.** `Order::order_detail()` writes `quantity` on a `POS`
 *      order's lines and `qty` on every other order's. A reader that took `qty` alone would print
 *      `0` for every till order's parcel, and nothing in the codebase says so.
 *   2. **`products.product_code` belongs to a module that may not be installed**, so the barcode
 *      read has to survive the column's absence AND must not reach another tenant's catalogue
 *      (`Product` is in no `scoped_models` list, so nothing else would stop it).
 */
class PackingSlipTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ADD `products.product_code` IF IT IS NOT THERE, so the barcode cases MEASURE something.
     *
     * The column belongs to `packages/workdo/ProductBarCode`'s own migration, and no WorkDo module
     * migration runs in this suite — so the first form of these cases took their "the module is not
     * installed" limb and asserted `assertTrue(true)`, which is trap 3: the precondition was never
     * met, so the case passed without reaching the code it names. `RefreshDatabase` rolls the column
     * back after each test.
     */
    private function withProductCodeColumn(): void
    {
        if (\Illuminate\Support\Facades\Schema::hasColumn('products', 'product_code')) {
            return;
        }

        \Illuminate\Support\Facades\Schema::table('products', function ($table) {
            $table->string('product_code')->nullable();
        });

        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('products', 'product_code'),
            'premise: the product_code column could not be added, so the barcode read is not being '
            . 'measured at all');
    }

    private function product(int $storeId, string $name, ?string $code = null): int
    {
        $row = [
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid(),
            'store_id' => $storeId,
            'track_stock' => 0,
            'created_by' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if ($code !== null && \Illuminate\Support\Facades\Schema::hasColumn('products', 'product_code')) {
            $row['product_code'] = $code;
        }

        return (int) DB::table('products')->insertGetId($row);
    }

    private function variant(int $productId, int $storeId, string $sku): int
    {
        return (int) DB::table('product_variants')->insertGetId([
            'product_id' => $productId,
            'variant' => 'S-Red',
            'sku' => $sku,
            'price' => 10,
            'stock' => 5,
            'store_id' => $storeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * A POS ORDER'S LINES USE `quantity`, AND EVERY OTHER ORDER'S USE `qty`.
     *
     * Both in one call, because a slip built from a mixed source must not silently drop one of them,
     * and because a test driving only one key cannot tell "reads both" from "reads the one I sent".
     */
    public function test_both_quantity_keys_are_read(): void
    {
        $slip = PackingSlip::forLines([
            ['product_id' => 1, 'name' => 'Till line', 'quantity' => 3],
            ['product_id' => 2, 'name' => 'Web line', 'qty' => 4],
        ], 1);

        $this->assertSame(3, $slip['lines'][0]['quantity'],
            'the POS branch\'s `quantity` key was not read, so every till order\'s parcel would '
            . 'print 0 — Order::order_detail() writes `quantity` when payment_type is POS');
        $this->assertSame(4, $slip['lines'][1]['quantity'],
            'the ordinary branch\'s `qty` key was not read');
        $this->assertSame(7, $slip['units'], 'the unit total does not add both keys up');
        $this->assertSame(2, $slip['distinct']);
    }

    /** A line with neither key, or a nonsense one, counts as 0 rather than printing rubbish. */
    public function test_an_unusable_quantity_is_zero_and_never_negative(): void
    {
        $slip = PackingSlip::forLines([
            ['product_id' => 1, 'name' => 'No key'],
            ['product_id' => 2, 'name' => 'Empty', 'qty' => ''],
            ['product_id' => 3, 'name' => 'Words', 'qty' => '5abc'],
            ['product_id' => 4, 'name' => 'Negative', 'qty' => -3],
            ['product_id' => 5, 'name' => 'Fractional', 'qty' => '5.9'],
        ], 1);

        foreach ($slip['lines'] as $line) {
            $this->assertSame(0, $line['quantity'],
                'the line "' . $line['name'] . '" produced ' . $line['quantity']
                . '. `5abc` and `5.9` are the two values the skill names: `is_numeric` accepts the '
                . 'second and rejects the first, while MySQL reads `5abc` as 5.');
        }

        $this->assertSame(0, $slip['units']);
    }

    /** The barcode: a variant's SKU wins, and a simple product falls back to `product_code`. */
    public function test_the_code_column_comes_from_the_variant_then_the_product(): void
    {
        $this->withProductCodeColumn();

        $simple = $this->product(7, 'Simple scarf', 'CODE-SIMPLE');
        $parent = $this->product(7, 'Variant scarf', 'CODE-PARENT');
        $variantId = $this->variant($parent, 7, 'SKU-VARIANT');

        $slip = PackingSlip::forLines([
            ['product_id' => $simple, 'name' => 'Simple scarf', 'qty' => 1],
            ['product_id' => $parent, 'variant_id' => $variantId, 'variant_name' => 'S / Red', 'qty' => 2],
        ], 7);

        $this->assertSame('SKU-VARIANT', $slip['lines'][1]['code'],
            'the variant\'s own SKU must win over its parent\'s product code — the parcel holds the '
            . 'variant, not the parent');
        $this->assertSame('S / Red', $slip['lines'][1]['variant_name']);

        $this->assertSame('CODE-SIMPLE', $slip['lines'][0]['code'],
            'a simple product\'s `products.product_code` was not read');
    }

    /**
     * THE PRODUCT-CODE READ IS SCOPED TO THE ORDER'S STORE.
     *
     * `Product` is absent from `config/tenancy.php`'s `scoped_models`, so `StoreScope::isActiveFor()`
     * short-circuits and a `whereIn('id', ...)` with no store predicate reaches every tenant's
     * catalogue. A packing slip printing another tenant's product code is a small leak, but it is
     * the same unguarded read that produced the S19 stock drain, so it is asserted rather than
     * assumed.
     */
    public function test_the_product_code_read_does_not_cross_tenants(): void
    {
        $this->withProductCodeColumn();

        $foreign = $this->product(99, 'Another tenant product', 'CODE-FOREIGN');
        $mine = $this->product(7, 'My product', 'CODE-MINE');

        $slip = PackingSlip::forLines([
            ['product_id' => $foreign, 'name' => 'Another tenant product', 'qty' => 1],
            /* The control, in the SAME call: without it the case passes against a reader that
               returns no code for anybody. */
            ['product_id' => $mine, 'name' => 'My product', 'qty' => 1],
        ], 7);

        $this->assertSame('CODE-MINE', $slip['lines'][1]['code'],
            'premise: the store\'s OWN product code did not come back either, so "the foreign one '
            . 'is absent" says nothing about the predicate');

        $this->assertNull($slip['lines'][0]['code'],
            'ANOTHER TENANT\'S PRODUCT CODE REACHED THIS SLIP. The order belongs to store 7 and the '
            . 'product to store 99; `Product` is in no scoped_models list, so only the explicit '
            . 'store predicate stands between them.');
    }

    /**
     * AND THE COLUMN'S ABSENCE IS NOT A FATAL — the state of every installation that has never
     * enabled `ProductBarCode`, which is the default and the suite's own.
     */
    public function test_the_slip_builds_when_the_barcode_column_does_not_exist(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('products', 'product_code'),
            'premise: the suite now migrates ProductBarCode\'s column, so this case no longer '
            . 'measures the module-absent state');

        $id = $this->product(7, 'Scarf');

        $slip = PackingSlip::forLines([
            ['product_id' => $id, 'name' => 'Scarf', 'qty' => 2],
        ], 7);

        $this->assertSame(2, $slip['lines'][0]['quantity']);
        $this->assertNull($slip['lines'][0]['code']);
    }

    /** The order's own recorded name, not the catalogue's current one. */
    public function test_the_recorded_name_is_used_not_the_catalogues(): void
    {
        $id = $this->product(7, 'Renamed since');

        $slip = PackingSlip::forLines([
            ['product_id' => $id, 'name' => 'As sold', 'qty' => 1],
        ], 7);

        $this->assertSame('As sold', $slip['lines'][0]['name'],
            'the slip printed the catalogue\'s current name. A product renamed after the order must '
            . 'not change what the parcel says was in it.');
    }

    /** A returned line is MARKED, not dropped: the packer still has to account for it. */
    public function test_a_returned_line_is_marked_and_counted(): void
    {
        $slip = PackingSlip::forLines([
            ['product_id' => 1, 'name' => 'Kept', 'qty' => 1, 'return' => false],
            ['product_id' => 2, 'name' => 'Sent back', 'qty' => 2, 'return' => true],
        ], 1);

        $this->assertFalse($slip['lines'][0]['returned']);
        $this->assertTrue($slip['lines'][1]['returned']);
        $this->assertSame(1, $slip['returned']);
        $this->assertSame(3, $slip['units'],
            'a returned line must still count towards the units on the slip — dropping it would '
            . 'make the slip disagree with the order');
    }

    /** No money of any kind reaches the slip. */
    public function test_the_slip_carries_no_money(): void
    {
        $slip = PackingSlip::forLines([
            [
                'product_id' => 1, 'name' => 'Scarf', 'qty' => 1,
                'orignal_price' => 100, 'final_price' => 90, 'sale_price' => 80,
                'total_orignal_price' => 100,
            ],
        ], 1);

        foreach (array_keys($slip['lines'][0]) as $key) {
            $this->assertStringNotContainsString('price', $key,
                'a price key reached the packing slip. The slip travels in the parcel; the priced '
                . 'document is the receipt. Carrying money here would mean choosing which figure to '
                . 'print, which is a pricing decision this class must not make.');
        }

        $this->assertSame(
            ['product_id', 'variant_id', 'name', 'variant_name', 'code', 'quantity', 'returned'],
            array_keys($slip['lines'][0]),
            'the line shape changed; re-read the no-money argument before adding a key'
        );
    }

    /** A malformed line is skipped rather than fatal. */
    public function test_a_malformed_line_is_skipped(): void
    {
        $slip = PackingSlip::forLines([
            'not an array',
            ['product_id' => 'not an id', 'variant_id' => ['array'], 'name' => 'Odd', 'qty' => 1],
        ], 1);

        $this->assertSame(1, $slip['distinct'], 'the non-array line was not skipped');
        $this->assertNull($slip['lines'][0]['product_id']);
        $this->assertNull($slip['lines'][0]['variant_id']);
        $this->assertSame(1, $slip['lines'][0]['quantity']);
    }

    /** An order with no lines produces an empty slip, not a fatal. */
    public function test_an_empty_order_produces_an_empty_slip(): void
    {
        $slip = PackingSlip::forLines([], 1);

        $this->assertSame([], $slip['lines']);
        $this->assertSame(0, $slip['units']);
        $this->assertSame(0, $slip['distinct']);
        $this->assertSame(0, $slip['returned']);
    }
}
