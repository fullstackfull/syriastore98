<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * D-21 — THE FLUTTER ADMIN APP'S ORDER WRITER KEEPS ITS REFUSAL CONTRACT.
 * =======================================================================
 *
 * `POST api/admin/createOrderViaApi` is the shipped Flutter admin app's own order writer. D-21
 * routed its stock decrement through `ProductStockService` so the availability test and the
 * decrement become one atomic statement — it was a check-then-act, where two concurrent orders
 * could both read the same figure, both pass the test and both subtract.
 *
 * THE OWNER'S CONDITION ON THIS PATH IS THE SHARPEST ONE IN D-21:
 *
 * > لا تغير رموز HTTP أو استجابات API بصورة تكسر Flutter، وخصوصاً لا تستخدم 401 لرفض تجاري عادي.
 *
 * A 401 is not merely a different number here: `lib/network/network.dart` in the shipped app maps
 * ANY 401 to a forced logout that clears stored preferences. So a business refusal answered with
 * 401 would sign the merchant out of their own admin app. This file pins the refusal's shape so
 * the atomicity change cannot have altered it, and so a later change cannot either.
 *
 * WHAT IT DOES NOT CLAIM. It does not test concurrency — `CACHE_STORE=array` makes `Cache::lock`
 * process-local and SQLite `:memory:` is per-process, so no Feature test here can stage two
 * overlapping requests. The atomicity is asserted structurally in
 * `tests/Feature/Commerce/GatewayStockDecrementRoutingTest.php`; what is asserted HERE is that
 * buying it cost the client nothing.
 */
class AdminApiStockRefusalContractTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** @return array{0:\App\Models\Product,1:int} */
    private function productIn($store, int $productStock, int $variantStock): array
    {
        $product = Product::forceCreate([
            'name' => 'arc-' . uniqid(), 'slug' => 'arc-' . uniqid(),
            'product_stock' => $productStock, 'track_stock' => 1, 'variant_product' => 1,
            'price' => 10, 'sale_price' => 10, 'stock_status' => 'in_stock', 'status' => 1,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);

        $variantId = DB::table('product_variants')->insertGetId([
            'product_id' => $product->id, 'variant' => 'red', 'sku' => 'arc-' . uniqid(),
            'price' => 10, 'stock' => $variantStock, 'store_id' => $store->id,
            'variation_option' => 'manage_stock', 'stock_status' => 'in_stock',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$product, (int) $variantId];
    }

    private function customerIn($store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Arc', 'last_name' => 'Shopper',
            'email' => 'arc-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    private function order($merchant, $store, Customer $customer, int $productId, $variantId, int $qty)
    {
        // P2-04-10 CONSEQUENCE, recorded rather than silently absorbed.
        //
        // `createOrderViaApi` gained a permission gate on 2026-10-28: it previously had NO
        // permission check at all (328 lines, two order-creating calls, zero checks), so an
        // employee with no order permission created real orders in their employer's store. This
        // suite's subject is the STOCK contract, not permissions, and a fixture merchant holds no
        // Laratrust role -- so without this grant it would start failing for an orthogonal reason
        // and stop isolating what it was written for. A REAL merchant is unaffected: the shared
        // global `admin` role carries `Manage Order` and every merchant is given it for every
        // store they create.
        $this->grantPermission(\App\Models\User::find($merchant->id), 'Manage Order');
        $merchant = \App\Models\User::find($merchant->id);

        return $this->withHeader('Authorization', 'Bearer ' . $merchant->createToken('auth_token')->plainTextToken)
            ->postJson('/api/admin/createOrderViaApi?store_id=' . $store->id, [
                'customer_id' => $customer->id,
                'products' => [array_filter([
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'quantity' => $qty,
                    'price' => 10.00,
                ], fn ($v) => $v !== null)],
                'payment_type' => 'cod',
                'payment_status' => 'Unpaid',
                'first_name' => 'Arc', 'last_name' => 'Buyer',
                'email' => 'buyer@example.test', 'telephone' => '555000111',
                'address' => 'Street 1',
            ]);
    }

    /**
     * A SHORTFALL ON A VARIANT: 400, `status`/`message`, and NEVER 401.
     */
    public function test_a_variant_shortfall_still_answers_400_with_the_same_envelope(): void
    {
        [$merchant, $store] = $this->makeTenant();
        [$product, $variantId] = $this->productIn($store, 100, 2);
        $customer = $this->customerIn($store);

        $response = $this->order($merchant, $store, $customer, $product->id, $variantId, 5);

        $this->assertSame(400, $response->getStatusCode(),
            'the stock refusal changed status code. The shipped Flutter admin app branches on '
            . 'this; 400 is what it has always received for a business refusal.');

        $this->assertNotSame(401, $response->getStatusCode(),
            'A BUSINESS REFUSAL ANSWERED 401. lib/network/network.dart maps any 401 to a forced '
            . 'logout that clears stored preferences, so this would sign the merchant out of '
            . 'their own admin app for ordering too many of something.');

        $body = $response->json();

        $this->assertSame('error', $body['status'] ?? null,
            'the envelope key `status` must still be `error`');
        $this->assertIsString($body['message'] ?? null,
            'the envelope key `message` must still be a string the app can show');
        $this->assertStringContainsString('Insufficient stock', (string) $body['message'],
            'the refusal wording changed; the app may be matching on it');

        /* And the rollback still held: no order, no stock movement. */
        $this->assertSame(2, (int) DB::table('product_variants')->where('id', $variantId)->value('stock'),
            'a refused order moved the variant stock anyway');
    }

    /**
     * THE SAME FOR A NON-VARIANT LINE, because the two legs are separate code and only one of
     * them would be caught by a single test.
     */
    public function test_a_product_shortfall_still_answers_400_with_the_same_envelope(): void
    {
        [$merchant, $store] = $this->makeTenant();
        [$product] = $this->productIn($store, 2, 100);
        $customer = $this->customerIn($store);

        $response = $this->order($merchant, $store, $customer, $product->id, null, 5);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertNotSame(401, $response->getStatusCode(),
            'a business refusal answered 401 — see the variant case for why that logs the '
            . 'merchant out');
        $this->assertSame('error', $response->json('status'));
        $this->assertStringContainsString('Insufficient stock', (string) $response->json('message'));

        $this->assertSame(2, (int) Product::withoutGlobalScopes()->find($product->id)->product_stock,
            'a refused order moved the product stock anyway');
    }

    /**
     * THE POSITIVE CONTROL. Without it, both refusals above would be satisfied by a handler that
     * refuses everything — which would be a complete outage of the admin app's order writer
     * rather than a hardening.
     */
    public function test_an_order_that_fits_still_succeeds_and_takes_the_stock(): void
    {
        [$merchant, $store] = $this->makeTenant();
        [$product, $variantId] = $this->productIn($store, 100, 10);
        $customer = $this->customerIn($store);

        $response = $this->order($merchant, $store, $customer, $product->id, $variantId, 3);

        $this->assertContains($response->getStatusCode(), [200, 201],
            'a legitimate order was refused: ' . $response->getContent());

        $this->assertSame(7, (int) DB::table('product_variants')->where('id', $variantId)->value('stock'),
            'the order succeeded but the stock was not taken, so the decrement is now inert');
    }

    /**
     * THE EXACT BOUNDARY, because `>=` and `>` are one character apart and the difference is a
     * merchant unable to sell their last unit.
     */
    public function test_ordering_exactly_the_remaining_stock_is_allowed(): void
    {
        [$merchant, $store] = $this->makeTenant();
        [$product, $variantId] = $this->productIn($store, 100, 4);
        $customer = $this->customerIn($store);

        $response = $this->order($merchant, $store, $customer, $product->id, $variantId, 4);

        $this->assertContains($response->getStatusCode(), [200, 201],
            'ordering exactly the stock on hand was refused, so the predicate is `>` where it '
            . 'should be `>=` and the last unit of everything is unsellable: '
            . $response->getContent());

        $this->assertSame(0, (int) DB::table('product_variants')->where('id', $variantId)->value('stock'));
    }

    /**
     * THE CONVERSION ITSELF, pinned in source — because the four tests above cannot see it.
     *
     * That is not a hedge, it was measured. Revert `createOrderViaApi` to its original
     * check-then-act (`if ($variant->stock < $qty) { rollBack; 400 }` then
     * `$variant->stock -= $qty; save()`) and ALL FOUR still pass: the refusal is 400 with the
     * same envelope either way, the boundary case still succeeds, and the happy path still
     * takes the stock. The behaviour is deliberately byte-identical — that is the whole point
     * of the D-21 Flutter condition, that the shipped admin app sees no change — so behaviour
     * is precisely the wrong place to look for evidence that the conversion happened.
     *
     * What the conversion buys is invisible here and cannot be staged here: the service issues
     * one conditional `UPDATE ... SET col = col - ? WHERE col >= ?`, so two concurrent orders
     * cannot both pass a check and both write. A read-modify-write loses that update. Under
     * SQLite `:memory:` with `CACHE_STORE=array` no Feature test can run two overlapping
     * requests, so the lost-update property is proven against the service itself by
     * `OrderStockConcurrencyTest`; what is proven HERE is the thing that would silently undo
     * it — that this writer delegates rather than doing its own arithmetic.
     *
     * Precedent and shape:
     * `OrderStoreOwnershipTest::test_store_delegates_stock_to_the_service_and_keeps_no_read_modify_write`.
     * Comments are stripped first, so a comment quoting the old expression can neither satisfy
     * nor defeat any assertion below — this codebase has had a census satisfied by a comment.
     */
    public function test_create_order_via_api_delegates_stock_to_the_service_and_keeps_no_read_modify_write(): void
    {
        $m = new \ReflectionMethod(\App\Http\Controllers\Api\DashboardController::class, 'createOrderViaApi');

        $source = implode('', array_slice(
            file($m->getFileName()),
            $m->getStartLine() - 1,
            $m->getEndLine() - $m->getStartLine() + 1
        ));

        $code = '';

        foreach (token_get_all('<?php ' . $source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        $this->assertStringContainsString('decrementVariantStock(', $code,
            'the variant branch of createOrderViaApi must decrement through ProductStockService. '
            . 'This is the Flutter admin app\'s own order writer — ledger F118 — and it was missed '
            . 'by every earlier census because it spells its subtraction `-=` rather than the '
            . '`$remain_stock` form those censuses keyed on.');

        $this->assertStringContainsString('decrementProductStock(', $code,
            'the product branch of createOrderViaApi must decrement through ProductStockService.');

        /*
         * AND BOTH MUST REQUIRE AVAILABILITY. The fourth argument is what preserves the 400:
         * with $requireAvailability = false the service would force the decrement through on a
         * shortfall instead of reporting INSUFFICIENT, the `applied` test below it would be
         * true, and the handler would write an order against stock it does not have — silently,
         * since the four behavioural tests above would still see a 200 on the happy path and
         * nothing at all on the shortfall path they would no longer reach.
         */
        $normalised = preg_replace('/\s+/', '', $code);

        $this->assertSame(2, substr_count($normalised, ',$qty,false,true)'),
            'both decrements in createOrderViaApi must pass $requireAvailability = true. Without '
            . 'it the service oversells instead of refusing, and the 400 this file pins becomes '
            . 'a 200 with an order written against stock that is not there.');

        foreach ([
            '$variant->stock-=' => 'the variant read-modify-write',
            '$product->product_stock-=' => 'the product read-modify-write',
            '$variant->stock=$variant->stock-' => 'the expanded variant form',
            '$product->product_stock=$product->product_stock-' => 'the expanded product form',
        ] as $needle => $what) {
            $this->assertStringNotContainsString(preg_replace('/\s+/', '', $needle), $normalised,
                $what . ' must not come back: two concurrent Flutter orders would both read the '
                . 'same figure and the second write would erase the first.');
        }
    }
}
