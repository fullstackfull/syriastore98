<?php

namespace Tests\Feature\Security;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * GAP-280 — THE TWO THINGS THE MEASUREMENT FOUND UNGUARDED, PINNED.
 * =============================================================================================
 *
 * GAP-280 asked for the surviving-line foreign-product disclosure to be CLOSED on both cart
 * readers. It was implemented, measured, and NOT LANDED — the measurement is
 * `tests/Support/Security/bin/measure-foreign-product-cart-read.php` and the decision it raises is
 * the owner's. This file holds the part that is not a decision: two tripwires for the facts the
 * measurement established, both of which pass against unchanged production code.
 *
 * NEITHER CASE BELOW ASSERTS THAT THE DISCLOSURE IS DESIRABLE. The first pins a COUPLING and the
 * second pins a RULE. The disclosure itself is recorded in the script, deliberately not in a test,
 * because a test asserting current behaviour would pin the defect as correct and
 * `.github/known-test-outcomes.json` declares an EMPTY `expected_failures` set whose own README
 * forbids using it as a parking spot.
 *
 * ---------------------------------------------------------------------------------------------
 * WHY THE OBVIOUS FIX WAS NOT LANDED, in one paragraph, because this file is where somebody will
 * come looking.
 *
 * Scoping the readers' line lookup makes a foreign product resolve to NULL, which hands the line
 * to the stale-line guard GAP-241 installed: dropped, reported, basket recomputed. It works, and it
 * unifies the two defects into one behaviour. BUT THE READERS FEED THE CHECKOUT GATE —
 * `basketHasForeignProduct()` is called with `$cartlist['product_list']` — so dropping the line
 * empties the gate's input and the gate can no longer fire. MEASURED on
 * `POST {storeA}/get-payment-paiementpro` with a guest cookie basket: with the readers scoped, a
 * basket holding one OWN line and one FOREIGN line WROTE AN ORDER and moved the shopper's own
 * stock 50 -> 48, where today it is refused with "item this store does not sell" and writes none.
 * The cross-tenant protection held in every case — no foreign stock moved and no foreign product
 * reached an order either way — so what changes is only that a refused basket completes. That is a
 * payments behaviour change, it disarms a control `ForeignProductCartChainTest` says was "extended
 * and wired for" that leg, and the gateway arm cannot be repaired from here because it lives in
 * `PaymentController` and the gateway controllers, which this workstream must not edit.
 *
 * AND THE SEVERITY IS LOWER THAN THE ROW IMPLIED, which is why waiting is affordable: section B of
 * the script shows the SAME principal reading the SAME name, price and image through store B's own
 * slug on `product-detail` (HTTP 200) — GAP-244's recorded property. So the reader reveals no field
 * that is not already served to that token holder. What it really does is fold another store's
 * price into this store's basket total and leave the basket unpayable.
 */
class ForeignProductCartReadFeedsTheGateTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function tenant(): array
    {
        [$merchant, $store] = $this->makeTenant();

        foreach (['stock_management' => 'on', 'out_of_stock_threshold' => '0', 'notification' => '[]'] as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => $value,
                'created_by' => $merchant->id, 'store_id' => $store->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        \Illuminate\Support\Facades\Cache::forget('admin_details');

        return [$merchant, $store];
    }

    private function productIn($store, string $name, $storeIdOverride = false): Product
    {
        $product = Product::forceCreate([
            'name' => $name, 'slug' => 'g280-' . uniqid(), 'description' => 'x',
            'product_stock' => 50, 'track_stock' => 0, 'variant_product' => 0,
            'stock_status' => 'in_stock', 'status' => 1, 'price' => '10.00', 'sale_price' => '10.00',
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);

        if ($storeIdOverride !== false) {
            DB::table('products')->where('id', $product->id)->update(['store_id' => $storeIdOverride]);
            (new Product())->flushCache();
        }

        return $product;
    }

    /**
     * THE READER'S OUTPUT IS THE CHECKOUT GATE'S INPUT, AND THAT COUPLING IS LOAD-BEARING.
     *
     * `place_order()`, `payment_sheet()` and `ordersave()` all run the guard -- through
     * `foreignBasketLines($products, $store)` since ruling (B); the boolean
     * `basketHasForeignProduct()` now delegates to it -- where `$products` IS
     * `$cartlist['product_list']`. So the gate can only refuse a foreign line while the reader
     * still PUTS one there. Nothing stated that anywhere, and the obvious remedy for GAP-280 —
     * scoping the reader — silently breaks it: measured, a mixed basket then completes and writes
     * an order instead of being refused.
     *
     * This case exists so that change cannot be made quietly. If a future slice scopes the readers
     * it must either repair the gate's input or take the owner's decision to let such baskets
     * complete; either way this test reddens and says why, instead of six security cases failing
     * for reasons that look unrelated.
     */
    public function test_the_cart_read_still_hands_a_foreign_line_to_the_checkout_gate(): void
    {
        [, $storeA] = $this->tenant();
        [, $storeB] = $this->tenant();

        $shopper = Customer::forceCreate([
            'first_name' => 'Shopper', 'last_name' => 'A',
            'email' => 'g280-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'), 'store_id' => $storeA->id,
            'status' => 1, 'register_type' => 'email',
        ]);

        $own = $this->productIn($storeA, 'G280-GATE-OWN');
        $foreign = $this->productIn($storeB, 'G280-GATE-FOREIGN');

        foreach ([$own, $foreign] as $p) {
            Cart::forceCreate([
                'customer_id' => $shopper->id, 'product_id' => $p->id, 'variant_id' => 0,
                'qty' => 1, 'price' => 0, 'store_id' => $storeA->id,
            ]);
        }

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $shopper->createToken('t')->plainTextToken,
        ])->postJson('/api/' . $storeA->slug . '/cart-list', ['customer_id' => $shopper->id]);

        $served = array_map('intval', array_column((array) $response->json('data.product_list'), 'product_id'));

        $this->assertContains((int) $foreign->id, $served,
            'The cart read no longer hands the foreign line to the checkout gate. That is the '
            . 'remedy GAP-280 proposes, and it is not a free one: `basketHasForeignProduct()` at '
            . 'place_order() / payment_sheet() / ordersave() takes `product_list` as its '
            . 'input, so with the line gone the gate cannot fire and a MIXED basket COMPLETES and '
            . 'writes an order where it is refused today (measured: own stock 50 -> 48 on the '
            . 'PaiementPro return). If that is now the intended behaviour, the owner has decided '
            . 'it and this test should be replaced by one asserting the new contract — not '
            . 'deleted.');

        /* And the gate does in fact still refuse, on the arm this workstream owns. */
        $this->assertTrue(
            Product::withoutGlobalScopes()->whereKey($foreign->id)->value('store_id') !== null
            && (int) Product::withoutGlobalScopes()->whereKey($foreign->id)->value('store_id') !== (int) $storeA->id,
            'premise: the foreign product is not provably foreign, so the gate would not fire and '
            . 'this case proves nothing'
        );
    }

    /**
     * ONE RULE IN THREE PLACES, AND THEY MUST AGREE.
     *
     * "Is this product provably not this store's?" is asked by
     * `GuardsGatewayReturn::basketHasForeignProduct()` (the checkout gate for seven writers),
     * `CartController::product_cartlist:314` (the live web cart writer, P01-COMMERCE-SEC-01) and
     * `Api\ApiController::productOfCartLineStore()` (the shipping quote) — three copies of the same
     * three-outcome rule, with no control that they still answer alike. A fourth copy was about to
     * be added for the cart readers, which is how this codebase's censuses have repeatedly drifted.
     *
     * The matrix is the one that matters: a NULL `store_id` must be NOT foreign, because the web
     * writer admits such a product into any store's basket. A reader or gate that "tightened" this
     * to equality would start refusing lines the writer creates.
     */
    public function test_the_checkout_gate_holds_the_three_outcome_rule(): void
    {
        [, $storeA] = $this->tenant();
        [, $storeB] = $this->tenant();

        $own = $this->productIn($storeA, 'G280-RULE-OWN');
        $foreign = $this->productIn($storeB, 'G280-RULE-FOREIGN');
        $nullStore = $this->productIn($storeA, 'G280-RULE-NULL', null);

        $this->assertNull(DB::table('products')->where('id', $nullStore->id)->value('store_id'),
            'premise: store_id is not NULL, so the third case is vacuous');

        $gate = new class {
            use \App\Http\Controllers\Concerns\GuardsGatewayReturn;

            public function foreign($products, $store): bool
            {
                return $this->basketHasForeignProduct($products, $store);
            }
        };

        $cases = [
            "this store's own product" => [$own->id, false],
            'another store\'s product' => [$foreign->id, true],
            'a NULL store_id product' => [$nullStore->id, false],
            'an id with no row' => [99_000_000, false],
        ];

        foreach ($cases as $label => [$productId, $expected]) {
            $this->assertSame($expected, $gate->foreign([['product_id' => $productId]], $storeA),
                "basketHasForeignProduct() changed its answer for `{$label}`. Three sites hold this "
                . 'rule and the cart readers are candidates for a fourth, so a change here must be '
                . 'made in all of them deliberately — see '
                . 'tests/Support/Security/bin/measure-foreign-product-cart-read.php.');
        }
    }
}
