<?php

namespace Tests\Feature\Security;

use App\Models\DeliveryBoy;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * GAP-131 — `cart-check-guest` READ ANOTHER TENANT'S STOCK, FOR ANY SANCTUM TOKEN HOLDER.
 * =============================================================================================
 *
 * `POST /api/{storeA-slug}/cart-check-guest` with a store-B product id answered **HTTP 200** with
 * that product's stock figure. Two statements, both unscoped:
 *
 *     Product::find($value['product_id'])                                  the plain arm
 *     ProductVariant::where('product_id', …)->where('id', $value['varient_id'])->first()
 *                                                                          the variant arm
 *
 * `$store = getStore($slug)` is read at the top of the handler and used for **nothing but** the
 * "Store not found." early return.
 *
 * NOTHING UNDERNEATH CONTAINS IT, and the REASON differs between the two models even though the
 * conclusion does not. `Product` carries `StoreScope` through `BelongsToStore` but is not in
 * `config/tenancy.php`'s `scoped_models`, so `StoreScope::isActiveFor()` short-circuits and **no
 * predicate is added for any principal** — not a customer, not a merchant, not the operator.
 * `ProductVariant` has no scope at all: `use HasFactory, Cachable` and nothing else. An earlier
 * version of this docblock said both carried the scope, which the independent review refuted —
 * and CLAUDE.md records that this exact distinction has been mis-stated in both directions before.
 * The `null_tenant => 'open'` rule does not even come into it.
 *
 * ---------------------------------------------------------------------------------------------
 * BOTH ARMS NEED CLOSING, AND NOT BY THE SAME MEANS.
 *
 * The variant branch **overwrites `$product`** before the stock is read, so leaving it alone
 * discloses the per-variant figure however well the plain arm is scoped. Each arm has its own case
 * here and each failed against the unchanged code.
 *
 * But the variant arm is closed by requiring the **already store-verified product**, not by a
 * second `store_id` predicate — the independent review's finding, and it is about availability
 * rather than disclosure. `product_variants.store_id` is nullable, both writers go through
 * `getCurrentStore()` (null for a storeless user), and this project has MEASURED the column
 * drifting: `ProductController:2873-2895` records an operator's ordinary save stamping
 * `store_id = 1` on a store-7 product's variants and names the store-scoped readers that then
 * "stop seeing them". A fourth such reader on a pre-checkout stock gate would tell a shopper
 * "Product no found." about a product their own merchant sells. Gating on `$product` is exactly as
 * safe — a foreign product does not resolve on the plain arm — and agrees with
 * `productOfCartLineStore()`, which deliberately accepts a NULL `store_id`.
 *
 * ---------------------------------------------------------------------------------------------
 * THE SUFFIX IS NOT A LICENCE, AND THE TOKEN IS WHY THIS NEEDS THREE PRINCIPALS.
 *
 * `-guest` does not mean public: the route carries `custom.auth`, which refuses a missing bearer
 * token — measured, 401 for no token and 401 for a garbage one. What the suffix means is that the
 * handler has no customer binding at all; it reads `$request->all()` and trusts it.
 *
 * So this drives a **customer, a merchant and a driver** token. CLAUDE.md records why a
 * customer-only probe proves nothing on this family: `CustomAuth` logs a token's owner into the
 * guard matching the TOKENABLE CLASS, so a merchant's or driver's token passes the middleware
 * while `auth('customers')` stays null, and a handler with an `auth('customers')` fallback takes
 * the attacker-controlled branch for exactly those two. This handler has no fallback, so all three
 * reach the same statement — which is itself the finding, and is asserted rather than assumed.
 *
 * ---------------------------------------------------------------------------------------------
 * THE REQUEST KEY IS MISSPELLED `varient_id` IN THE HANDLER.
 *
 * It appears nowhere else in `app/` or `tests/`, so a census grepping `variant_id` misses this
 * surface entirely. The spelling is preserved here because the handler's contract is what a client
 * sends, not what it should have been called.
 */
class StorefrontGuestCartStockTenancyTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** Distinctive, so an assertion cannot pass on a coincidence. */
    private const FOREIGN_PLAIN_STOCK = 4242;

    private const FOREIGN_VARIANT_STOCK = 777;

    private const OWN_PLAIN_STOCK = 55;

    private const OWN_VARIANT_STOCK = 33;

    private function productIn($store, int $stock, bool $variantProduct = false): Product
    {
        return Product::forceCreate([
            'name' => 'GAP131-PRODUCT-' . uniqid(),
            'slug' => 'gap131-' . uniqid(),
            'description' => 'x',
            'product_stock' => $stock,
            'track_stock' => 1,
            'variant_product' => $variantProduct ? 1 : 0,
            'stock_status' => 'in_stock',
            'trending' => 0,
            'status' => 1,
            'price' => 10,
            'sale_price' => 10,
            'store_id' => $store->id,
            'created_by' => $store->created_by,
        ]);
    }

    private function variantFor(Product $product, $store, int $stock): ProductVariant
    {
        /*
         * `price` and `stock`, which are the columns `product_variants` actually has. Read from the
         * migration after an insert failed on `original_price` -- and worth recording that
         * `ApiController::check_variant_stock` READS `original_price`, `discount_price` and
         * `final_price` from this table, none of which the migration creates. Pre-existing, outside
         * this slice, and NOT "fixed" here: CLAUDE.md records that the migrations are not the
         * schema, so those three may well exist on the production table.
         */
        return ProductVariant::forceCreate([
            'product_id' => $product->id,
            'variant' => 'red/large',
            'sku' => 'SKU-' . uniqid(),
            'price' => 10,
            'stock' => $stock,
            'stock_status' => 'in_stock',
            'low_stock_threshold' => 0,
            'store_id' => $store->id,
        ]);
    }

    /** @return array{0:string,1:string,2:string} customer, merchant and driver tokens for one store */
    private function threeTokensFor($merchant, $store): array
    {
        $customer = \App\Models\Customer::forceCreate([
            'first_name' => 'Shopper',
            'last_name' => 'A',
            'email' => 'gap131-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id,
            'status' => 1,
            'register_type' => 'email',
        ]);

        $driver = DeliveryBoy::forceCreate([
            'name' => 'Driver',
            'email' => 'gap131-drv-' . uniqid() . '@example.test',
            'password' => bcrypt('x'),
            'created_by' => $merchant->id,
            'store_id' => $store->id,
        ]);

        return [
            $customer->createToken('t')->plainTextToken,
            $merchant->createToken('t')->plainTextToken,
            $driver->createToken('t')->plainTextToken,
        ];
    }

    private function cartCheck($store, array $items, string $bearer)
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $bearer])
            ->postJson('/api/' . $store->slug . '/cart-check-guest', $items);
    }

    /** The per-item figure the handler answers with, read out of the response. */
    private static function reportedQty($response, int $index = 0)
    {
        return data_get($response->json(), 'data.cart.' . $index . '.product_qty');
    }

    private static function reportedMessage($response, int $index = 0)
    {
        return data_get($response->json(), 'data.cart.' . $index . '.message');
    }

    // ══════════════════════════════════════════════════════════════════════════ THE PLAIN ARM

    public function test_a_foreign_products_stock_is_not_reported_to_any_token_holder(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $foreign = $this->productIn($storeB, self::FOREIGN_PLAIN_STOCK);

        $foreignRow = Product::withoutGlobalScopes()->find($foreign->id);

        $this->assertSame(
            self::FOREIGN_PLAIN_STOCK,
            (int) $foreignRow->product_stock,
            'premise: the foreign product does not hold the stock this test looks for'
        );
        /* AND IT REALLY BELONGS TO STORE B. Without this, a fixture that silently dropped
           `store_id` would make this case pass as "orphans are refused" while proving nothing about
           cross-tenant reads. Raised by the independent review as hardening. */
        $this->assertSame((int) $storeB->id, (int) $foreignRow->store_id,
            'premise: the foreign product is not actually store B\'s, so this measures the wrong thing');

        foreach ($this->threeTokensFor($merchantA, $storeA) as $principal => $bearer) {
            $response = $this->cartCheck($storeA, [
                ['product_id' => $foreign->id, 'varient_id' => 0, 'qty' => 1],
            ], $bearer);

            $this->assertSame(200, $response->status(), 'the request was not accepted at all, so the assertion below would be vacuous');

            $this->assertNotEquals(
                self::FOREIGN_PLAIN_STOCK,
                self::reportedQty($response),
                sprintf(
                    'principal %d was told store B\'s stock figure through store A\'s cart-check-guest. '
                    . 'Product is NOT in config/tenancy.php scoped_models, so no global scope contains '
                    . 'this: the handler\'s own predicate is the only control.',
                    $principal
                )
            );

            $this->assertSame('Product no found.', self::reportedMessage($response),
                'the foreign product resolved, so the refusal is not the one the handler means');
        }
    }

    /** CONTROL: the store's OWN product still answers with its own figure, for a customer token. */
    public function test_a_stores_own_product_is_still_reported(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();

        $own = $this->productIn($storeA, self::OWN_PLAIN_STOCK);

        [$customerToken] = $this->threeTokensFor($merchantA, $storeA);

        $response = $this->cartCheck($storeA, [
            ['product_id' => $own->id, 'varient_id' => 0, 'qty' => 1],
        ], $customerToken);

        $response->assertStatus(200);

        $this->assertSame(self::OWN_PLAIN_STOCK, (int) self::reportedQty($response),
            'the fix refused a legitimate same-store basket, which is a regression and not a fix');
        $this->assertSame('Product have stock.', self::reportedMessage($response));
    }

    // ════════════════════════════════════════════════════════════════════════ THE VARIANT ARM

    /**
     * THE ARM THAT A PARTIAL FIX LEAVES OPEN. The variant branch overwrites `$product`, so the
     * plain lookup's predicate does not reach it — this case fails against a handler where only
     * `Product::find()` was constrained.
     */
    public function test_a_foreign_variants_stock_is_not_reported_either(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $foreign = $this->productIn($storeB, 1, true);
        $foreignVariant = $this->variantFor($foreign, $storeB, self::FOREIGN_VARIANT_STOCK);

        $foreignVariantRow = ProductVariant::withoutGlobalScopes()->find($foreignVariant->id);

        $this->assertSame(
            self::FOREIGN_VARIANT_STOCK,
            (int) $foreignVariantRow->stock,
            'premise: the foreign variant does not hold the stock this test looks for'
        );
        $this->assertSame((int) $storeB->id, (int) $foreignVariantRow->store_id,
            'premise: the foreign variant is not actually store B\'s');

        foreach ($this->threeTokensFor($merchantA, $storeA) as $principal => $bearer) {
            $response = $this->cartCheck($storeA, [
                ['product_id' => $foreign->id, 'varient_id' => $foreignVariant->id, 'qty' => 1],
            ], $bearer);

            $this->assertSame(200, $response->status());

            $this->assertNotEquals(
                self::FOREIGN_VARIANT_STOCK,
                self::reportedQty($response),
                sprintf('principal %d was told store B\'s VARIANT stock through store A', $principal)
            );

            $this->assertSame('Product no found.', self::reportedMessage($response));
        }
    }

    /** CONTROL: the store's own variant still answers. */
    public function test_a_stores_own_variant_is_still_reported(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();

        $own = $this->productIn($storeA, 1, true);
        $ownVariant = $this->variantFor($own, $storeA, self::OWN_VARIANT_STOCK);

        [$customerToken] = $this->threeTokensFor($merchantA, $storeA);

        $response = $this->cartCheck($storeA, [
            ['product_id' => $own->id, 'varient_id' => $ownVariant->id, 'qty' => 1],
        ], $customerToken);

        $response->assertStatus(200);

        $this->assertSame(self::OWN_VARIANT_STOCK, (int) self::reportedQty($response),
            'the fix refused a legitimate same-store variant basket');
        $this->assertSame('Product have stock.', self::reportedMessage($response));
    }

    /**
     * A VARIANT OF ONE STORE UNDER A PRODUCT OF ANOTHER, BOTH WAYS ROUND.
     *
     * THIS CASE PASSED AGAINST THE UNCHANGED HANDLER, and saying so is the point of keeping it.
     * The variant lookup keys on `product_id` AND `id`, so a mismatched pair matches no row and was
     * already contained — by the compound key, not by any tenant reasoning. A first version of this
     * docblock claimed it was "the cross-join a one-predicate fix would not refuse", which the
     * measurement refuted.
     *
     * It stays as a REGRESSION GUARD rather than as evidence of the defect: a future rewrite that
     * looked the variant up by `id` alone — the obvious simplification, since `id` is the primary
     * key — would reopen exactly this pairing, and nothing else in the file would notice.
     */
    public function test_a_variant_cannot_be_read_under_another_stores_product(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $foreign = $this->productIn($storeB, 1, true);
        $foreignVariant = $this->variantFor($foreign, $storeB, self::FOREIGN_VARIANT_STOCK);

        [$customerToken] = $this->threeTokensFor($merchantA, $storeA);

        /* Store A's own variant id, paired with store B's product id. */
        $own = $this->productIn($storeA, 1, true);
        $ownVariant = $this->variantFor($own, $storeA, self::OWN_VARIANT_STOCK);

        $response = $this->cartCheck($storeA, [
            ['product_id' => $foreign->id, 'varient_id' => $ownVariant->id, 'qty' => 1],
        ], $customerToken);

        $response->assertStatus(200);
        $this->assertSame('Product no found.', self::reportedMessage($response),
            'a variant resolved under another store\'s product id');
        $this->assertSame(0, (int) self::reportedQty($response));

        /* And the reverse pairing, so neither id can be the one that carries the lookup. */
        $reverse = $this->cartCheck($storeA, [
            ['product_id' => $own->id, 'varient_id' => $foreignVariant->id, 'qty' => 1],
        ], $customerToken);

        $reverse->assertStatus(200);
        $this->assertNotEquals(self::FOREIGN_VARIANT_STOCK, self::reportedQty($reverse),
            'store B\'s variant was read under store A\'s own product id');
    }

    /**
     * A LEGITIMATE VARIANT WHOSE OWN `store_id` IS WRONG MUST STILL BE SERVED — the regression the
     * independent review caught in the first version of this fix.
     *
     * Both states are real in this database, not hypothetical:
     *
     *   NULL — `product_variants.store_id` is nullable and both writers stamp it from
     *   `getCurrentStore()`, which answers null for a user with no `current_store` who owns no
     *   store. `ProductController:2941-2942` records that the `choice_no` path creates such rows.
     *
     *   DRIFTED — `ProductController:2873-2895` records an operator's ordinary no-op save stamping
     *   `store_id = 1` on a **store-7** product's variants while `products.store_id` stayed 7, and
     *   names the store-scoped readers that then stop seeing them. It self-heals only on the next
     *   save of that combination.
     *
     * The first fix put `->where('store_id', $store->id)` on the variant lookup and would have
     * become a fourth such reader — on a PRE-CHECKOUT STOCK GATE, so the symptom is a shopper told
     * "Product no found." about a product their own merchant sells. Gating on the already
     * store-verified product instead is as safe and immune to the column.
     */
    public function test_a_variant_whose_own_store_id_is_null_or_drifted_is_still_served(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $own = $this->productIn($storeA, 1, true);
        $ownVariant = $this->variantFor($own, $storeA, self::OWN_VARIANT_STOCK);

        [$customerToken] = $this->threeTokensFor($merchantA, $storeA);

        foreach ([null, (int) $storeB->id] as $wrongStoreId) {
            /* A raw UPDATE, then the model cache flushed: `ProductVariant` is `Cachable`, so an
               Eloquent read -- the handler's own -- would otherwise serve the pre-write row for the
               rest of this test, which CLAUDE.md records as having produced three false readings. */
            DB::table('product_variants')->where('id', $ownVariant->id)->update(['store_id' => $wrongStoreId]);
            (new ProductVariant())->flushCache();

            $this->assertSame(
                $wrongStoreId,
                DB::table('product_variants')->where('id', $ownVariant->id)->value('store_id') === null
                    ? null
                    : (int) DB::table('product_variants')->where('id', $ownVariant->id)->value('store_id'),
                'premise: the variant\'s store_id was not actually changed, so this case is vacuous'
            );

            $response = $this->cartCheck($storeA, [
                ['product_id' => $own->id, 'varient_id' => $ownVariant->id, 'qty' => 1],
            ], $customerToken);

            $response->assertStatus(200);

            $this->assertSame(
                self::OWN_VARIANT_STOCK,
                (int) self::reportedQty($response),
                sprintf(
                    'a variant of store A\'s OWN product was refused because its own store_id is %s. '
                    . 'The product resolved in store A, so the variant under it is store A\'s '
                    . 'whatever that column says -- and this is the pre-checkout stock gate, so the '
                    . 'shopper is told their merchant does not sell their own product.',
                    $wrongStoreId === null ? 'NULL' : 'a different store'
                )
            );
        }
    }

    // ═══════════════════════════════════════════════════════════════════ THE DOOR ITSELF

    /**
     * `-guest` IS NOT PUBLIC, and this is asserted so the suffix is never read as a licence.
     * `custom.auth` refuses a missing bearer token before the handler runs.
     *
     * EACH CASE IN ITS OWN REQUEST, deliberately: `withHeaders()` merges into `$defaultHeaders` and
     * PERSISTS across requests inside one test method, so a probe that sent a token earlier and
     * then "sent none" inherits the earlier bearer. That exact mistake made a first measurement of
     * this route read as anonymous.
     */
    public function test_the_guest_route_still_refuses_a_caller_with_no_token(): void
    {
        [, $storeA] = $this->makeTenant();

        $response = $this->postJson('/api/' . $storeA->slug . '/cart-check-guest', [
            ['product_id' => 1, 'varient_id' => 0, 'qty' => 1],
        ]);

        $this->assertSame(401, $response->status(), 'the -guest suffix made this route anonymous');
    }

    public function test_a_garbage_token_is_refused(): void
    {
        [, $storeA] = $this->makeTenant();

        $response = $this->cartCheck($storeA, [
            ['product_id' => 1, 'varient_id' => 0, 'qty' => 1],
        ], 'not-a-real-token');

        $this->assertSame(401, $response->status());
    }

    /**
     * A MALFORMED ITEM MUST NOT BE A 500. The handler reads `$value['product_id']` and
     * `$value['varient_id']` with no `isset`, and PHP 8 promotes the undefined-key warning to an
     * `ErrorException` — so one item without those keys took the whole basket down. Not a
     * disclosure, but a 500 is the shape that lets a regression test pass for the wrong reason,
     * which this project has measured more than once on this very family.
     */
    public function test_a_malformed_item_does_not_crash_the_basket(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        $own = $this->productIn($storeA, self::OWN_PLAIN_STOCK);

        [$customerToken] = $this->threeTokensFor($merchantA, $storeA);

        $response = $this->cartCheck($storeA, [
            ['qty' => 1],
            ['product_id' => $own->id, 'varient_id' => 0, 'qty' => 1],
        ], $customerToken);

        $this->assertNotSame(500, $response->status(), 'an item with no product_id crashed the handler');

        /* And the well-formed item beside it is still answered. */
        $this->assertSame(self::OWN_PLAIN_STOCK, (int) self::reportedQty($response, 1));
    }

    /** Nothing here writes: a read-only door must stay one. */
    public function test_the_handler_writes_nothing(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $foreign = $this->productIn($storeB, self::FOREIGN_PLAIN_STOCK);

        $cartsBefore = (int) DB::table('carts')->count();
        $stockBefore = (int) Product::withoutGlobalScopes()->find($foreign->id)->product_stock;

        [$customerToken] = $this->threeTokensFor($merchantA, $storeA);

        $this->cartCheck($storeA, [
            ['product_id' => $foreign->id, 'varient_id' => 0, 'qty' => 1],
        ], $customerToken);

        $this->assertSame($cartsBefore, (int) DB::table('carts')->count());
        $this->assertSame($stockBefore, (int) Product::withoutGlobalScopes()->find($foreign->id)->product_stock);
    }
}
