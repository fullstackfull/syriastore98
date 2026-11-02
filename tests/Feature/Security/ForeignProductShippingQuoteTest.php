<?php

namespace Tests\Feature\Security;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Shipping;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * F283 — THE SHIPPING-QUOTE LEG OF THE FOREIGN-PRODUCT CHAIN.
 * =================================================================================================
 *
 * `ForeignProductCartChainTest` drives the cart → ORDER chain and closes it at every writer,
 * including `test_a_stored_foreign_cart_row_cannot_move_another_merchants_stock` and
 * `test_place_order_also_refuses_a_stored_foreign_cart_row`. **The QUOTE leg was never driven.**
 *
 * `Api\ApiController::calculateFlaterate()` is reached from `delivery_charge` and does this:
 *
 *     $productId    = $Product->product_id;                      // $Product is a CART ROW
 *     $product_data = Product::find($productId);                 // no store predicate
 *     $shippingClass = Shipping::find($product_data->shipping_id);   // no store predicate
 *
 * `Product` carries `StoreScope` but is ABSENT from `config/tenancy.php`'s `scoped_models`, so
 * `StoreScope::isActiveFor()` short-circuits and **no predicate is added for any principal**.
 * `Shipping` is excluded from `scoped_models` outright. So neither read is scoped by anything, and
 * the only thing standing between them and another tenant's rows is whether a cart row in store A
 * can name a product in store B.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * WHAT THE QUOTE ACTUALLY LEAKS AND DECIDES, traced rather than asserted
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * The foreign product's `shipping_id` selects WHICH bucket of store A's own `product_cost` JSON is
 * charged:
 *
 *     $shippingClass = Shipping::find($product_data->shipping_id);
 *     if ($shippingClass == null)  $price += $product_cost['product_no_cost'];
 *     else foreach ($product_cost['product_cost'] as $key => $value)
 *              if ($key == $shippingClass->id) $price += $value;
 *
 * So the consequence is not "another store's price is charged" — the cost table is store A's own,
 * and `delivery_charge`'s `ShippingMethod` lookup is already store-scoped (F263). It is (a) a
 * cross-tenant READ of store B's `products` row and `shippings` row, and (b) the ability to select
 * a cheaper bucket of store A's table by naming a foreign product whose class maps to it.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * THE ORDER OF THESE CASES IS THE OWNER'S FIVE STEPS, AND IT MATTERS
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * 1. how the product enters the cart         → the entry cases
 * 2. whether a foreign id can reach the sum  → the stored-row case
 * 3. ownership at the trust boundary         → the entry cases, per writer
 * 4. whether existing scopes prevent it      → the premise cases (they do NOT; the writers do)
 * 5. the real request path, two tenants      → every case drives HTTP, never a helper
 *
 * **NOTHING HERE ASSERTS AGAINST A COPY OF A PREDICATE.** Each case posts to a real route with a
 * real customer token and reads the response or the database.
 */
class ForeignProductShippingQuoteTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchantA;

    private Store $storeA;

    private User $merchantB;

    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->merchantA, $this->storeA] = $this->makeTenant();
        [$this->merchantB, $this->storeB] = $this->makeTenant();
    }

    // ---------------------------------------------------------------------------------------
    //  FIXTURES
    // ---------------------------------------------------------------------------------------

    private function shippingClassIn(Store $store, string $name): Shipping
    {
        $row = Shipping::create([
            'name' => $name,
            'slug' => str_replace(' ', '-', strtolower($name)) . '-' . uniqid(),
            'description' => $name,
            'store_id' => $store->id,
        ]);
        (new Shipping)->flushCache();

        return $row;
    }

    /**
     * A Flat Rate method whose `product_cost` JSON prices each shipping class differently.
     *
     * The buckets are what makes the leak observable: `product_no_cost` is charged when the
     * product has no class, and `product_cost[<class id>]` when it has one. Two very different
     * numbers, so the response says which branch ran.
     */
    private function flatRateIn(Store $store, array $costByClassId, string $noCost): ShippingMethod
    {
        $countryId = (int) \DB::table('countries')->insertGetId(['name' => 'Testland ' . uniqid()]);
        $stateId = (int) \DB::table('states')->insertGetId([
            'name' => 'Teststate ' . uniqid(),
            'country_id' => $countryId,
        ]);

        $zone = ShippingZone::create([
            'zone_name' => 'Zone ' . uniqid(),
            'country_id' => $countryId,
            'state_id' => $stateId,
            'shipping_method' => '1',
            'store_id' => $store->id,
        ]);

        $method = ShippingMethod::create([
            'method_name' => 'Flat Rate',
            'zone_id' => $zone->id,
            'cost' => '7.00',
            'calculation_type' => 'fixed',
            'shipping_requires' => '1',
            'store_id' => $store->id,
        ]);

        /* `product_cost` is a JSON string on the method; the handler json_decodes it. */
        ShippingMethod::withoutGlobalScopes()->whereKey($method->id)->update([
            'product_cost' => json_encode([
                'product_cost' => $costByClassId,
                'product_no_cost' => $noCost,
            ]),
        ]);
        (new ShippingMethod)->flushCache();

        return ShippingMethod::withoutGlobalScopes()->findOrFail($method->id);
    }

    private function productIn(Store $store, string $name, ?int $shippingId, string $price): Product
    {
        return Product::forceCreate([
            'name' => $name . '-' . uniqid(),
            'slug' => $name . '-' . uniqid(),
            'product_stock' => 50,
            'track_stock' => 0,
            'variant_product' => 0,
            'price' => $price,
            'sale_price' => $price,
            'shipping_id' => $shippingId,
            'store_id' => $store->id,
            'created_by' => $store->created_by,
        ]);
    }

    private function customerIn(Store $store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Shopper',
            'last_name' => 'F283',
            'email' => 'f283-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id,
            'status' => 1,
            'register_type' => 'email',
        ]);
    }

    /** A cart row written RAW, which is the only way to construct the pre-guard state now. */
    private function rawCartRow(Store $store, Customer $customer, int $productId): int
    {
        return (int) \DB::table('carts')->insertGetId([
            'customer_id' => $customer->id,
            'product_id' => $productId,
            'variant_id' => 0,
            'qty' => 1,
            'price' => 10,
            'store_id' => $store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function quote(Store $store, Customer $customer, ShippingMethod $method)
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $customer->createToken('f283')->plainTextToken,
        ])->postJson('/api/' . $store->slug . '/delivery-charge', [
            'method_id' => $method->id,
            'country_id' => 1,
            'state_id' => 1,
        ]);
    }

    // =======================================================================================
    //  STEP 4 — THE PREMISE: NO GLOBAL SCOPE PREVENTS EITHER READ
    // =======================================================================================

    /**
     * Neither model is scoped, so nothing downstream is protected by the ORM.
     *
     * This is the premise the whole finding rests on, and it is asserted rather than quoted from
     * `config/tenancy.php` so that adding either model to `scoped_models` would surface here.
     */
    public function test_premise_neither_product_nor_shipping_is_scoped_by_the_orm(): void
    {
        $scoped = (array) config('tenancy.scoped_models');

        $this->assertNotContains(Product::class, $scoped,
            'Product is now in scoped_models, so this finding\'s premise has changed and the '
            . 'reasoning below needs re-deriving');
        $this->assertNotContains(Shipping::class, $scoped,
            'Shipping is now in scoped_models, so this finding\'s premise has changed');

        /* And the consequence, driven: a bare find reaches the other tenant's rows. */
        $foreignClass = $this->shippingClassIn($this->storeB, 'B class');
        $foreign = $this->productIn($this->storeB, 'b-product', $foreignClass->id, '99.00');

        $this->assertNotNull(Product::find($foreign->id),
            'premise: Product::find no longer reaches another store, so the downstream read is '
            . 'already safe by the ORM and this file is measuring the wrong thing');
        $this->assertNotNull(Shipping::find($foreignClass->id),
            'premise: Shipping::find no longer reaches another store');
    }

    // =======================================================================================
    //  STEPS 1 AND 3 — THE TRUST BOUNDARY: CAN A FOREIGN PRODUCT ENTER THE CART AT ALL?
    // =======================================================================================

    /**
     * THE API WRITER REFUSES, AND WRITES NOTHING.
     *
     * `addtocart` resolves `Product::where('id', …)->where('store_id', $store->id)` — a hard match
     * added by P01-COMMERCE-SEC-03. Driven through the real route rather than read, because the
     * refusal is the thing that makes the downstream read unreachable.
     */
    public function test_the_api_cart_writer_refuses_another_stores_product(): void
    {
        $foreign = $this->productIn($this->storeB, 'b-product', null, '99.00');
        $customer = $this->customerIn($this->storeA);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $customer->createToken('f283')->plainTextToken,
        ])->postJson('/api/' . $this->storeA->slug . '/add-cart', [
            'product_id' => $foreign->id,
            'qty' => 1,
        ]);

        $this->assertNotSame(500, $response->getStatusCode(),
            'addtocart raised a 500 on a foreign product instead of refusing it');

        $this->assertSame(0,
            Cart::withoutGlobalScopes()->where('product_id', $foreign->id)->count(),
            "a store-A customer carted store B's product through the API. That row is what makes "
            . 'every downstream unscoped read reachable.');
    }

    /** AND THE LEGITIMATE PATH STILL WORKS, or the refusal above proves nothing. */
    public function test_the_api_cart_writer_still_accepts_this_stores_own_product(): void
    {
        $own = $this->productIn($this->storeA, 'a-product', null, '10.00');
        $customer = $this->customerIn($this->storeA);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $customer->createToken('f283')->plainTextToken,
        ])->postJson('/api/' . $this->storeA->slug . '/add-cart', [
            'product_id' => $own->id,
            'qty' => 1,
        ]);

        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertSame(1,
            Cart::withoutGlobalScopes()->where('product_id', $own->id)->count(),
            'a customer can no longer cart their OWN store\'s product, so the guard refuses too '
            . 'much and the refusal case above is vacuous');
    }

    // =======================================================================================
    //  STEP 2 — THE DOWNSTREAM READ, GIVEN A ROW THE WRITERS WOULD NOT HAVE WRITTEN
    // =======================================================================================

    /**
     * THE QUOTE MUST NOT PRICE A FOREIGN PRODUCT, EVEN IF A FOREIGN CART ROW EXISTS.
     *
     * ══════════════════════════════════════════════════════════════════════════════════════════
     * WHY A RAW ROW IS THE RIGHT PREMISE AND NOT A CONTRIVANCE
     * ══════════════════════════════════════════════════════════════════════════════════════════
     *
     * Every cart writer refuses a foreign product TODAY — the two cases above prove it for the API
     * writer, `ForeignProductCartChainTest` proves it for the web and module writers. So the only
     * ways such a row exists are: it predates those guards (this is a live production database), a
     * future writer is added without one, or a WorkDo module route is enabled that the middleware
     * does not cover. All three are exactly what defence in depth is for, and the owner's
     * instruction is explicit — *"do not dismiss it merely because a different cart-entry defect
     * is required."*
     *
     * The row is written with `DB::table()` precisely because the guards refuse it through every
     * real door; that is the finding, not a weakness in the test.
     *
     * ══════════════════════════════════════════════════════════════════════════════════════════
     * WHAT IS OBSERVED
     * ══════════════════════════════════════════════════════════════════════════════════════════
     *
     * Store A's Flat Rate method prices its own class at **3.00** and anything class-less at
     * **95.00**. Store B's product carries store B's class. So:
     *
     *   - if the quote reads the foreign product and its foreign class, the class matches NO key
     *     in store A's table and the sum is **0.00** — a free shipment, chosen by naming a foreign
     *     product;
     *   - if the quote refuses the foreign line, neither number appears for it.
     *
     * The assertion is therefore on the ABSENCE of a figure derived from store B's row, and on
     * store B's data being untouched.
     */
    public function test_the_quote_does_not_price_a_stored_foreign_cart_row(): void
    {
        $classA = $this->shippingClassIn($this->storeA, 'A class');
        $methodA = $this->flatRateIn($this->storeA, [(string) $classA->id => '3.00'], '95.00');

        $classB = $this->shippingClassIn($this->storeB, 'B class');
        $foreign = $this->productIn($this->storeB, 'b-product', $classB->id, '99.00');

        $customer = $this->customerIn($this->storeA);
        $this->rawCartRow($this->storeA, $customer, (int) $foreign->id);

        $bNameBefore = Shipping::withoutGlobalScopes()->findOrFail($classB->id)->name;
        $bPriceBefore = (string) Product::withoutGlobalScopes()->findOrFail($foreign->id)->sale_price;

        $response = $this->quote($this->storeA, $customer, $methodA);

        $this->assertNotSame(500, $response->getStatusCode(),
            'the quote raised a 500 on a foreign cart row — ' . substr((string) $response->getContent(), 0, 300));

        $body = (string) $response->getContent();

        /*
         * THE FOREIGN PRODUCT'S OWN PRICE MUST NOT APPEAR. `calculateFlaterate` reads
         * `$product_data->sale_price` off whatever `Product::find()` returns, so 99.00 in the
         * response is store B's catalogue value having crossed the boundary.
         */
        $this->assertStringNotContainsString('99.00', $body,
            "the shipping quote carried store B's product price. `Product::find()` in "
            . 'calculateFlaterate has no store predicate and Product is absent from scoped_models.');

        /*
         * AND THE DISCOUNT THE FOREIGN LINE BUYS MUST NOT BE GRANTED EITHER. Measured before the
         * fix: store B's class matches no key in store A's `product_cost` table, so `$price += 0`
         * and the quote came back **7.00** where this store's own classified product costs
         * **10.00**. Naming a foreign product bought a 3.00 discount on store A's own shipping.
         *
         * So "refused" has to mean the line contributes nothing AND takes no cheaper bucket.
         */
        /*
         * AND THE FIGURE IS THE METHOD'S OWN COST — 7.00 — WHICH MEANS SOMETHING DIFFERENT NOW
         * THAN IT DID BEFORE THE FOLLOW-UP, so the assertion is re-stated rather than kept.
         *
         * BEFORE: 7.00 was the DEFECT. The foreign line was priced, its class matched no key in
         * this store's table, the per-class sum stayed 0, and the shopper was quoted 7.00 where
         * this store's own classified product costs 10.00 — a 3.00 discount bought by naming a
         * foreign product. The first version of this case therefore asserted 7.00 must NOT appear.
         *
         * AFTER: the line is REFUSED, and a cart whose every line is refused is deliberately
         * charged the method's own cost. 7.00 is now the conservative answer, not the cheap one —
         * the alternative was the `?? 0` fallthrough, which quoted FREE shipping and which the
         * mandated review caught.
         *
         * So what must be asserted is the policy, not the number's absence: not zero (the cheapest
         * answer, derived from rows this store does not own) and not the no-cost bucket (the
         * over-charge the three-way helper exists to prevent).
         */
        $this->assertStringContainsString('"charge_price":"7.00"', $body,
            'a cart whose every line is refused is no longer charged the method\'s own cost');
        $this->assertStringNotContainsString('"charge_price":"0.00"', $body,
            'an all-refused cart was quoted FREE shipping. The `continue` fires before '
            . '$total_shipping_price is assigned, so without the explicit fallback the response '
            . 'falls through to `?? 0` — the cheapest possible answer, and indistinguishable from '
            . 'an empty cart.');
        $this->assertStringNotContainsString('"charge_price":"102.00"', $body,
            'the refused line took the product_no_cost bucket (7.00 + 95.00)');

        /* AND THE RESPONSE MUST NOT PRICE THE FOREIGN PRODUCT AT ALL. */
        $this->assertStringContainsString('"original_price":"0.00"', $body,
            "the refused line still reported a product price; it must report none, because the "
            . 'only product in this cart belongs to another store');

        /* NOTHING OF STORE B'S IS MODIFIED, which is the harder half of the guarantee. */
        (new Shipping)->flushCache();
        $this->assertSame($bNameBefore,
            Shipping::withoutGlobalScopes()->findOrFail($classB->id)->name,
            "store B's shipping class was modified by a quote request");
        $this->assertSame($bPriceBefore,
            (string) Product::withoutGlobalScopes()->findOrFail($foreign->id)->sale_price,
            "store B's product was modified by a quote request");
    }

    /**
     * AND THE LEGITIMATE QUOTE STILL PRICES THE STORE'S OWN PRODUCT BY ITS OWN CLASS.
     *
     * Without this, a fix that refused every line would leave the case above green while the
     * storefront quoted nothing — the shape this project has been caught by repeatedly.
     */
    public function test_the_quote_still_prices_this_stores_own_product_by_its_own_class(): void
    {
        $classA = $this->shippingClassIn($this->storeA, 'A class');
        $methodA = $this->flatRateIn($this->storeA, [(string) $classA->id => '3.00'], '95.00');

        $own = $this->productIn($this->storeA, 'a-product', $classA->id, '10.00');

        $customer = $this->customerIn($this->storeA);
        $this->rawCartRow($this->storeA, $customer, (int) $own->id);

        $response = $this->quote($this->storeA, $customer, $methodA);

        $this->assertNotSame(500, $response->getStatusCode(),
            'the quote raised a 500 on the store\'s OWN product — '
            . substr((string) $response->getContent(), 0, 300));

        /*
         * EXACT ARITHMETIC, not a substring. `calculateFlaterate` returns `$cost_totle + $price`,
         * where `$cost_totle` starts at the method's own cost (7.00) and `$price` accumulates the
         * bucket matching the product's class (3.00). 7 + 3 = 10.00. A loose `contains('3')` was
         * the first draft and it matched nothing, because the body carries 10.00 and 20.00 — a
         * substring assertion on a number is how a figure check passes or fails for the wrong
         * reason.
         */
        $this->assertStringContainsString('"charge_price":"10.00"', (string) $response->getContent(),
            "the quote no longer charges the store's own per-class cost (method 7.00 + class 3.00 "
            . '= 10.00), so the F283 predicate refuses legitimate lines as well as foreign ones');
    }

    /**
     * A PRODUCT OF THIS STORE WHOSE `shipping_id` NAMES ANOTHER STORE'S CLASS MUST BE CHARGED
     * EXACTLY AS BEFORE — this is a regression the mandated review found in my own F283 fix.
     *
     * Nothing validates product ownership of a shipping class on the write path:
     * `ProductController:816` assigns `$request->shipping_id` directly, and the admin API
     * validates it only as `nullable`. So a merchant can point their own product at another
     * store's class id, and legacy rows can already be in that state. **No cart-write defect is
     * needed** — this is a merchant's own product in their own cart.
     *
     * BEFORE F283: `Shipping::find()` returned the foreign row, `$shippingClass` was non-null, the
     * `else` branch ran, and `$key == $shippingClass->id` matched no key in this store's table —
     * because `shippings.id` is a global auto-increment, so a foreign id can never equal one of
     * this store's. The contribution was therefore **always +0**.
     *
     * AFTER my first fix: the helper returned null, which the caller reads as "this product has no
     * class", and charged the **`product_no_cost` bucket** — 95.00 in this fixture. A deterministic
     * OVER-charge, and the mirror image of the under-charge F283 was about.
     *
     * So the correct behaviour is the pre-change one: contribute nothing. "The class belongs to
     * someone else" and "this product has no class" are different facts and must not collapse into
     * one null.
     */
    public function test_an_own_product_pointing_at_a_foreign_class_is_charged_as_before(): void
    {
        $classA = $this->shippingClassIn($this->storeA, 'A class');
        $methodA = $this->flatRateIn($this->storeA, [(string) $classA->id => '3.00'], '95.00');

        $classB = $this->shippingClassIn($this->storeB, 'B class');

        /* Store A's OWN product, pointing at store B's class. */
        $own = $this->productIn($this->storeA, 'a-foreign-class', (int) $classB->id, '10.00');

        $customer = $this->customerIn($this->storeA);
        $this->rawCartRow($this->storeA, $customer, (int) $own->id);

        $response = $this->quote($this->storeA, $customer, $methodA);

        $this->assertNotSame(500, $response->getStatusCode(),
            'the quote 500ed on a product pointing at a foreign class — '
            . substr((string) $response->getContent(), 0, 300));

        $body = (string) $response->getContent();

        /* The method's own cost and nothing else: 7.00 + 0. */
        $this->assertStringContainsString('"charge_price":"7.00"', $body,
            'a product of this store whose shipping_id names another store\'s class is no longer '
            . 'charged the way it was before F283 (method cost + 0)');

        /* AND SPECIFICALLY NOT THE NO-COST BUCKET, which is the regression. */
        $this->assertStringNotContainsString('"charge_price":"102.00"', $body,
            'the foreign class collapsed into "this product has no class" and bought the '
            . 'product_no_cost bucket (7.00 + 95.00). That is a deterministic OVER-charge '
            . 'introduced by conflating two different facts into one null.');
    }

    /**
     * The end of the method starting at `$start`: the next METHOD declaration, not the next
     * `function` keyword.
     *
     * A plain `strpos($code, 'function ', $start + 10)` finds the first CLOSURE inside the method
     * — `delivery_charge` has `function ($q) use ($store)` in its ShippingMethod predicate — and
     * cut the window after a few hundred characters. The first bounded draft did exactly that and
     * the ratchet went red, which is the good failure: a window that ends early makes
     * `assertStringNotContainsString` pass silently, so it has to end in the right place.
     *
     * A method declaration is `function <name>(`; a closure is `function (` or `function(`.
     */
    private function methodEnd(string $code, int $start): int
    {
        if (preg_match('/\bfunction\s+[A-Za-z_]\w*\s*\(/', $code, $m, PREG_OFFSET_CAPTURE, $start + 10)) {
            return (int) $m[0][1];
        }

        return strlen($code);
    }

    // =======================================================================================
    //  THE PAYABLE TWIN — Cart::calculateFlatRateShippingAmount()
    // =======================================================================================

    /**
     * THE TWIN THAT CHARGES MONEY REFUSES A FOREIGN LINE TOO.
     *
     * F283's first fix scoped `Api\ApiController::calculateFlaterate()` — the QUOTE path. **The
     * order writers do not call it.** They call `Cart::calculateFlatRateShippingAmount()` through
     * `CartController::get_shipping_method()`, and its result becomes
     * `$order_array['delivery_charge']` and from there `orders.delivery_price` and
     * `orders.final_price`. Found by the mandated review, which said it plainly: the first fix's
     * reachability argument read as though `delivery_charge` were the surface, and it is not the
     * surface that charges money.
     *
     * Driven directly on the method rather than through a full checkout, because what is being
     * established is the arithmetic it returns for a foreign line — and the three figures are
     * unambiguous: method 7.00 + class 3.00 = 10.00 for an owned classified product, and the
     * method cost alone for a refused one.
     */
    public function test_the_payable_flat_rate_twin_refuses_a_foreign_line(): void
    {
        $classA = $this->shippingClassIn($this->storeA, 'A class');
        $methodA = $this->flatRateIn($this->storeA, [(string) $classA->id => '3.00'], '95.00');

        $classB = $this->shippingClassIn($this->storeB, 'B class');
        $foreign = $this->productIn($this->storeB, 'b-product', (int) $classB->id, '99.00');
        $own = $this->productIn($this->storeA, 'a-product', (int) $classA->id, '10.00');

        $line = fn ($id) => (object) ['product_id' => $id, 'variant_id' => 0, 'qty' => 1];

        /* The store's own product: method cost + its class bucket. */
        $ownTotal = Cart::calculateFlatRateShippingAmount(
            $methodA, $methodA->cost, [$line($own->id)], $this->storeA->id
        );
        $this->assertSame('10.00', number_format((float) $ownTotal, 2, '.', ''),
            'the payable twin no longer charges this store\'s own classified product correctly '
            . '(method 7.00 + class 3.00)');

        /* Store B's product: refused, so the method cost alone. */
        $foreignTotal = Cart::calculateFlatRateShippingAmount(
            $methodA, $methodA->cost, [$line($foreign->id)], $this->storeA->id
        );
        $this->assertSame('7.00', number_format((float) $foreignTotal, 2, '.', ''),
            'the payable twin priced a foreign line. Its result becomes orders.delivery_price and '
            . 'orders.final_price, so a wrong figure here is PERSISTED, not merely displayed.');

        /* AND NOTHING OF STORE B'S IS TOUCHED. */
        (new \App\Models\Shipping)->flushCache();
        $this->assertSame('B class',
            \App\Models\Shipping::withoutGlobalScopes()->findOrFail($classB->id)->name,
            "store B's shipping class was modified by a shipping calculation");
    }

    /**
     * AN OWN PRODUCT POINTING AT A FOREIGN CLASS IS CHARGED AS BEFORE HERE TOO.
     *
     * The same regression the review found in the quote twin's first fix would be worse on this
     * path, because the over-charge would be persisted on the order rather than shown in a quote.
     */
    public function test_the_payable_twin_does_not_overcharge_an_own_product_with_a_foreign_class(): void
    {
        $classA = $this->shippingClassIn($this->storeA, 'A class');
        $methodA = $this->flatRateIn($this->storeA, [(string) $classA->id => '3.00'], '95.00');

        $classB = $this->shippingClassIn($this->storeB, 'B class');
        $own = $this->productIn($this->storeA, 'a-foreign-class', (int) $classB->id, '10.00');

        $total = Cart::calculateFlatRateShippingAmount(
            $methodA,
            $methodA->cost,
            [(object) ['product_id' => $own->id, 'variant_id' => 0, 'qty' => 1]],
            $this->storeA->id
        );

        $this->assertSame('7.00', number_format((float) $total, 2, '.', ''),
            'a product of this store whose shipping_id names another store\'s class was charged '
            . 'the product_no_cost bucket (7.00 + 95.00 = 102.00) instead of the method cost. On '
            . 'this path that over-charge is PERSISTED on the order.');
    }

    /**
     * AND BOTH CALL SITES REALLY PASS THE STORE — a ratchet, because the parameter is OPTIONAL.
     *
     * It is optional so that no caller breaks and an un-updated one keeps its legacy behaviour
     * rather than refusing every line on a payable path. That choice is only safe if the real
     * callers pass it, which is a source fact and is pinned here.
     */
    public function test_both_shipping_callers_pass_the_resolved_store(): void
    {
        $code = $this->withoutComments(
            (string) file_get_contents(app_path('Http/Controllers/CartController.php'))
        );

        $this->assertSame(0, substr_count($code, 'calculateFlatRateShippingAmount($shippingMethods, $shippingMethods->cost, $response->data->product_list)'),
            'a call site of calculateFlatRateShippingAmount() omits the store argument, so that '
            . 'path silently keeps the unscoped legacy behaviour on a payable calculation');

        $this->assertSame(2, substr_count($code, 'calculateFlatRateShippingAmount('),
            'the number of call sites changed; re-derive whether each one passes a '
            . 'slug-resolved store');
        $this->assertSame(2, substr_count($code, '$response->data->product_list, $store->id ?? null'),
            'both call sites no longer pass the slug-resolved store id');
    }

    /**
     * THE DOWNSTREAM READS ARE SCOPED TOO, AND THIS IS A SOURCE RATCHET BECAUSE NOTHING
     * BEHAVIOURAL CAN ISOLATE THEM. Measured, not assumed.
     *
     * The mutation sweep for this change:
     *
     *   boundary in `delivery_charge` removed                  -> the 99.00 leak returns   (BIT)
     *   `calculateFlaterate`'s product read unscoped ALONE     -> nothing changes      (NO BITE)
     *   the shipping-class read unscoped ALONE                 -> nothing changes      (NO BITE)
     *   BOTH product reads unscoped together                   -> the leak returns         (BIT)
     *
     * The reason is structural: the boundary `continue`s past a foreign line, so
     * `calculateFlaterate()` is never called for it and its own predicates are unreachable while
     * the boundary stands. They are mutually redundant — the same shape as F263's `store_id` write
     * versus its row predicate, and the honest conclusion is the same: with the boundary in place
     * the downstream scopes change no observable behaviour today.
     *
     * They are kept anyway, and pinned here, for the reason defence in depth exists:
     * `calculateFlaterate()` is a PUBLIC method on a 7,000-line controller, and a future caller
     * that reaches it without passing through `delivery_charge`'s loop would arrive with no
     * protection at all. A ratchet costs one assertion; rediscovering this through a leak costs a
     * tenant's catalogue.
     *
     * Read from the COMMENT-STRIPPED body, because this change's own explanatory comments quote
     * the unscoped calls they replaced.
     */
    public function test_the_downstream_quote_reads_are_scoped_in_source(): void
    {
        $code = $this->withoutComments(
            (string) file_get_contents(app_path('Http/Controllers/Api/ApiController.php'))
        );

        $start = strpos($code, 'function calculateFlaterate(');
        $this->assertNotFalse($start, 'premise: calculateFlaterate no longer exists');

        /*
         * BOUNDED AT THE METHOD'S REAL END, NOT A MAGIC LENGTH — the mandated review flagged this
         * and asked me to measure rather than trust its count, which was the right instruction.
         *
         * MEASURED: the comment-stripped body of `calculateFlaterate` is **4,231 characters**, and
         * the first draft of this ratchet read a **4,000**-character window. It already ended 231
         * characters short of the method. All four call sites happen to fall inside today, so the
         * ratchet was not broken — but its load-bearing assertions are
         * `assertStringNotContainsString`, which a window that falls short satisfies **silently**.
         * A few added lines and it would have been guarding less than the method while still
         * printing PASS.
         *
         * So the window ends where the method does: at the next `function ` declaration.
         */
        $next = $this->methodEnd($code, (int) $start);
        $body = substr($code, (int) $start, $next - (int) $start);

        $this->assertGreaterThan(4000, strlen($body),
            'premise: the stripped method is shorter than the 4,000-character window the first '
            . 'draft used, so the hazard this bound exists for is no longer present and this '
            . 'assertion should be re-derived rather than relaxed');

        $this->assertStringNotContainsString('Product::find(', $body,
            'calculateFlaterate resolves a product with a bare find() again. Product is absent '
            . 'from scoped_models, so that read is unscoped for every principal.');
        $this->assertStringNotContainsString('Shipping::find(', $body,
            'calculateFlaterate resolves a shipping class with a bare find() again. Shipping is '
            . 'excluded from scoped_models outright.');

        $this->assertStringContainsString('productOfCartLineStore(', $body,
            'calculateFlaterate no longer resolves its product through the shared store-scoped '
            . 'resolver, so it and delivery_charge can drift apart');
        $this->assertStringContainsString('shippingClassOfStore(', $body,
            'calculateFlaterate no longer resolves its shipping class within the cart line store');

        /* AND THE BOUNDARY ITSELF, which is the control that actually bites. */
        $dc = strpos($code, 'function delivery_charge(');
        $this->assertNotFalse($dc, 'premise: delivery_charge no longer exists');

        /* Same bound, same reason. */
        $dcBody = substr($code, (int) $dc, $this->methodEnd($code, (int) $dc) - (int) $dc);

        $this->assertStringContainsString('productOfCartLineStore(', $dcBody,
            "delivery_charge resolves its cart line's product without the store-scoped resolver. "
            . 'This is the control the mutation sweep proved load-bearing: removing it brings back '
            . "store B's 99.00 in the response.");
    }

    /** Blank out comments while preserving the line count. */
    private function withoutComments(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $out .= str_repeat("\n", substr_count($token[1], "\n"));

                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    /**
     * A LEGACY PRODUCT WITH NO `store_id` IS STILL PRICED — the writers' own NULL rule, pinned.
     *
     * Both cart writers refuse only a PROVABLE mismatch (`!empty($product->store_id) && … !==`),
     * because `products.store_id` is nullable and legacy rows may not carry it. The F283 resolver
     * uses the same rule deliberately, and without this case that choice is untested: a stricter
     * NULL policy here would silently stop pricing a merchant's own legacy catalogue, which is a
     * worse defect than the one being fixed.
     */
    public function test_a_legacy_product_with_no_store_is_still_priced(): void
    {
        $classA = $this->shippingClassIn($this->storeA, 'A class');
        $methodA = $this->flatRateIn($this->storeA, [(string) $classA->id => '3.00'], '95.00');

        $legacy = $this->productIn($this->storeA, 'a-legacy', $classA->id, '10.00');
        Product::withoutGlobalScopes()->whereKey($legacy->id)->update(['store_id' => null]);
        (new Product)->flushCache();

        $this->assertNull(Product::withoutGlobalScopes()->findOrFail($legacy->id)->store_id,
            'premise: the legacy product still carries a store, so the NULL rule is not driven');

        $customer = $this->customerIn($this->storeA);
        $this->rawCartRow($this->storeA, $customer, (int) $legacy->id);

        $response = $this->quote($this->storeA, $customer, $methodA);

        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertStringContainsString('"charge_price":"10.00"', (string) $response->getContent(),
            'a legacy product whose store_id is NULL is no longer priced, so the F283 resolver '
            . 'applies a stricter NULL policy than the cart writers do and a merchant with legacy '
            . 'rows has silently lost shipping quotes');
    }

    /**
     * A PRODUCT WITH NO SHIPPING CLASS STILL TAKES THE `product_no_cost` BUCKET.
     *
     * `calculateFlaterate`'s `$shippingClass == null` branch is a legitimate, supported case — a
     * merchant need not classify every product. A fix that scoped the `Shipping::find()` must not
     * turn "this store's product has no class" into "refused".
     */
    public function test_an_unclassified_own_product_still_takes_the_no_cost_bucket(): void
    {
        $classA = $this->shippingClassIn($this->storeA, 'A class');
        $methodA = $this->flatRateIn($this->storeA, [(string) $classA->id => '3.00'], '95.00');

        $own = $this->productIn($this->storeA, 'a-unclassed', null, '10.00');

        $customer = $this->customerIn($this->storeA);
        $this->rawCartRow($this->storeA, $customer, (int) $own->id);

        $response = $this->quote($this->storeA, $customer, $methodA);

        $this->assertNotSame(500, $response->getStatusCode());
        /* 7.00 method cost + 95.00 `product_no_cost` bucket = 102.00. */
        $this->assertStringContainsString('"charge_price":"102.00"', (string) $response->getContent(),
            'an unclassified product of this store no longer takes the product_no_cost bucket '
            . '(method 7.00 + no-cost 95.00 = 102.00), so the F283 fix broke a supported '
            . 'configuration — a merchant need not classify every product');
    }
}
