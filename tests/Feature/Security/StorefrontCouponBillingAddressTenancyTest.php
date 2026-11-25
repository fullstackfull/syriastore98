<?php

namespace Tests\Feature\Security;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\DeliveryAddress;
use App\Models\DeliveryBoy;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * `apply-coupon` PRICES A QUOTE FROM ANY DELIVERY ADDRESS ROW IN THE DATABASE.
 * =============================================================================================
 *
 *     $address = DeliveryAddress::find($request->billing_address_id);   // ApiController
 *     if ($address) {
 *         $parms['billing_info']['delivery_country'] = $address->country_id;
 *         $parms['billing_info']['delivery_state']   = $address->state_id;
 *         $parms['billing_info']['delivery_city']    = $address->city_id;
 *         $request->merge($parms);
 *     }
 *
 * `DeliveryAddress` is NOT in `config/tenancy.php`'s `scoped_models` and carries no scope, so
 * nothing contains that lookup: a shopper of store A names any address id and the row answers --
 * another customer's in the same store, or a customer's of another tenant entirely.
 *
 * ---------------------------------------------------------------------------------------------
 * WHAT IT DISCLOSES, AND WHAT IT DOES NOT. Both halves are asserted, because overstating this
 * would be as wrong as missing it.
 *
 *   IT IS A READ of another customer's jurisdiction. The three merged values feed
 *   `cart_list()`, which selects the merchant's tax methods BY JURISDICTION
 *   (`Cart::getProductTaxAmount($taxId, $sub, $store, $city, $state, $country, true)` matches
 *   country/state/city), so the returned quote's `tax_price`, `tax_rate` and `tax_name` change
 *   according to the foreign row. The figures are therefore a read-back channel for the
 *   jurisdiction fields of an arbitrary address id. That is what this test measures.
 *
 *   IT IS NOT the row's street address, name, phone or postcode -- the handler reads only
 *   `country_id`, `state_id` and `city_id`.
 *
 *   AND IT IS NOT A NEW ROUTE TO MOVING MONEY, which is the part worth saying plainly. The same
 *   three values are ALREADY settable by the caller directly: `cart_list()` reads
 *   `$request->billing_info['delivery_country'|'delivery_state'|'delivery_city']` with no address
 *   involved at all. So a caller who wants to price a quote in a tax-free jurisdiction can
 *   already do it without an address id, and closing this lookup does not close that. (It also
 *   writes nothing: `apply_coupon` has no `save()`, `create()`, `update()` or `delete()` anywhere
 *   in its body -- checked, so "a foreign address moves money" is not claimed here.) The fix is
 *   therefore about the READ, and the posted-jurisdiction question is a separate finding that this
 *   slice does not address and does not pretend to.
 *
 * ---------------------------------------------------------------------------------------------
 * ONLY A CUSTOMER TOKEN REACHES THE STATEMENT, and that is measured rather than assumed. The
 * branch is the `else` of `auth('customers')->guest()`, and `custom.auth` logs a merchant's or a
 * driver's token into `web`/`deliveryboy`, so for those two `guest()` is TRUE and the cookie-cart
 * branch runs instead. All three are still driven, so the claim is on the record and a future
 * change that routed a merchant token into this branch would fail the case.
 */
class StorefrontCouponBillingAddressTenancyTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const COUPON_CODE = 'GAP-COUPON-FREESHIP';

    /** @var array<string,int> the one jurisdiction the merchant's tax method is restricted to */
    private array $jurisdiction = [];

    private function jurisdiction(): array
    {
        if ($this->jurisdiction !== []) {
            return $this->jurisdiction;
        }

        $countryId = (int) DB::table('countries')->insertGetId(['name' => 'GAPLAND', 'created_at' => now(), 'updated_at' => now()]);
        $stateId = (int) DB::table('states')->insertGetId(['name' => 'GAPSTATE', 'country_id' => $countryId, 'created_at' => now(), 'updated_at' => now()]);
        $cityId = (int) DB::table('cities')->insertGetId(['name' => 'GAPCITY', 'country_id' => $countryId, 'state_id' => $stateId, 'created_at' => now(), 'updated_at' => now()]);

        return $this->jurisdiction = ['country_id' => $countryId, 'state_id' => $stateId, 'city_id' => $cityId];
    }

    /** A tax the merchant has restricted to one country, so the jurisdiction decides the figure. */
    private function taxFor($store): int
    {
        $taxId = (int) DB::table('taxes')->insertGetId([
            'name' => 'GAP-TAX', 'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('tax_methods')->insert([
            'name' => 'GAP-TAX-METHOD',
            'tax_rate' => '25',
            'tax_id' => $taxId,
            'country_id' => $this->jurisdiction()['country_id'],
            'state_id' => null,
            'city_id' => null,
            'priority' => '0',
            'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $taxId;
    }

    private function productIn($store, string $name, ?int $taxId = null): Product
    {
        return Product::forceCreate([
            'name' => $name,
            'slug' => 'gapaddr-' . uniqid(),
            'description' => 'x',
            'tax_id' => $taxId,
            'product_stock' => 9,
            'track_stock' => 1,
            'variant_product' => 0,
            'stock_status' => 'in_stock',
            'trending' => 0,
            'status' => 1,
            'price' => 100,
            'sale_price' => 100,
            'store_id' => $store->id,
            'created_by' => $store->created_by,
        ]);
    }

    private function shopperOf($store, string $tag): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Shopper', 'last_name' => $tag,
            'email' => 'gapaddr-' . $tag . '-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('x'), 'store_id' => $store->id,
            'status' => 1, 'register_type' => 'email',
        ]);
    }

    private function addressFor(Customer $customer, $store): DeliveryAddress
    {
        return DeliveryAddress::forceCreate(array_merge([
            'customer_id' => $customer->id,
            'full_name' => 'GAPADDR-FULL-NAME',
            'phone' => '555000111',
            'title' => 'home',
            'address' => 'GAPADDR-STREET',
            'postcode' => 12345,
            'default_address' => 0,
            'type' => 0,
            'store_id' => $store->id,
        ], $this->jurisdiction()));
    }

    private function couponFor($store): int
    {
        return (int) DB::table('coupons')->insertGetId([
            'coupon_name' => 'GAP Free Shipping',
            'coupon_code' => self::COUPON_CODE,
            'coupon_type' => 'percentage',
            'coupon_limit' => 0,
            'coupon_expiry_date' => now()->addYear()->toDateString(),
            'discount_amount' => 0,
            'sale_items' => 0,
            /* free_shipping_coupon = 1 AND no `shipping_method` in the session is what reaches the
               branch that contains the address lookup -- the `else` of `if ($shipping_Methods !=
               null)` inside the `else` of `if ($coupon->free_shipping_coupon == 0)`. Any other
               coupon shape never executes the statement under test, which would make every
               assertion here vacuous. */
            'free_shipping_coupon' => 1,
            'status' => 1,
            'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function cartLine(Customer $customer, $store, Product $product): Cart
    {
        return Cart::forceCreate([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'variant_id' => 0,
            'qty' => 1,
            'price' => 100,
            'store_id' => $store->id,
        ]);
    }

    private function applyCoupon($store, $addressId, string $bearer)
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $bearer])
            ->postJson('/api/' . $store->slug . '/apply-coupon', [
                'coupon_code' => self::COUPON_CODE,
                'billing_address_id' => $addressId,
            ]);
    }

    /**
     * THE QUOTE OF A SHOPPER OF STORE A MUST NOT BE PRICED FROM ANOTHER TENANT'S ADDRESS ROW.
     *
     * The observable is the tax block of the quote, and it is observable in BOTH directions:
     * when no jurisdiction is known `cart_list` takes the `Tax::TaxCount` path and reports the
     * method's `tax_rate`/`tax_name`; when one IS known it takes `getProductTaxAmount` and reports
     * a `tax_price` instead. So "the address resolved" and "it did not" cannot be confused.
     */
    public function test_a_foreign_customers_address_does_not_price_the_quote(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $taxId = $this->taxFor($storeA);
        $product = $this->productIn($storeA, 'GAPADDR-PRODUCT', $taxId);

        $shopperA = $this->shopperOf($storeA, 'a');
        $this->cartLine($shopperA, $storeA, $product);
        $this->couponFor($storeA);

        /* The foreign row: another TENANT's customer's address. */
        $shopperB = $this->shopperOf($storeB, 'b');
        $foreignAddress = $this->addressFor($shopperB, $storeB);

        /* PREMISES. */
        $row = DB::table('delivery_addresses')->where('id', $foreignAddress->id)->first();
        $this->assertSame((int) $storeB->id, (int) $row->store_id, 'premise: the address is not store B\'s');
        $this->assertSame((int) $shopperB->id, (int) $row->customer_id, 'premise: the address is not shopper B\'s');
        $this->assertSame($this->jurisdiction()['country_id'], (int) $row->country_id,
            'premise: the foreign address carries no jurisdiction, so it cannot steer the tax');
        $this->assertNotSame((int) $shopperA->id, (int) $shopperB->id);

        $absentId = ((int) DB::table('delivery_addresses')->max('id')) + 5000;

        $token = $shopperA->createToken('t')->plainTextToken;

        $withForeign = $this->applyCoupon($storeA, $foreignAddress->id, $token);
        $withAbsent = $this->applyCoupon($storeA, $absentId, $token);

        $this->assertSame(200, $withForeign->status(), 'apply-coupon did not answer at all');
        $this->assertSame(1, (int) $withAbsent->json('status'),
            'premise: the coupon was not accepted, so the quote under test was never built '
            . '(message: ' . (string) $withAbsent->json('message') . ')');

        /* Premise that the observable CAN move: with no jurisdiction the quote reports the
           method's rate by name. If this is 0 the whole comparison below is blind. */
        $this->assertSame('25', (string) data_get($withAbsent->json(), 'data.tax_rate'),
            'premise: the tax fixture does not reach the quote, so the comparison below cannot '
            . 'tell a resolved address from an unresolved one');

        $this->assertSame(
            [
                (string) data_get($withAbsent->json(), 'data.tax_rate'),
                (string) data_get($withAbsent->json(), 'data.tax_name'),
                (string) data_get($withAbsent->json(), 'data.tax_price'),
            ],
            [
                (string) data_get($withForeign->json(), 'data.tax_rate'),
                (string) data_get($withForeign->json(), 'data.tax_name'),
                (string) data_get($withForeign->json(), 'data.tax_price'),
            ],
            'apply-coupon priced store A\'s shopper\'s quote from a DeliveryAddress row belonging '
            . 'to another tenant\'s customer: the tax block differs from the one computed when no '
            . 'address resolves, so the foreign row\'s country/state/city reached the arithmetic. '
            . 'DeliveryAddress is NOT in config/tenancy.php scoped_models, so the handler\'s own '
            . 'predicate is the only control.'
        );
    }

    /** AND NOT FROM ANOTHER SHOPPER'S ADDRESS IN THE SAME STORE -- the store predicate alone would
     *  not catch this one, which is why the fix keys on the CUSTOMER. */
    public function test_another_shoppers_address_in_the_same_store_does_not_price_the_quote(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();

        $taxId = $this->taxFor($storeA);
        $product = $this->productIn($storeA, 'GAPADDR-PRODUCT', $taxId);

        $shopperA = $this->shopperOf($storeA, 'a');
        $this->cartLine($shopperA, $storeA, $product);
        $this->couponFor($storeA);

        $neighbour = $this->shopperOf($storeA, 'neighbour');
        $neighbourAddress = $this->addressFor($neighbour, $storeA);

        $this->assertSame((int) $neighbour->id, (int) DB::table('delivery_addresses')->where('id', $neighbourAddress->id)->value('customer_id'));
        $this->assertSame((int) $storeA->id, (int) DB::table('delivery_addresses')->where('id', $neighbourAddress->id)->value('store_id'),
            'premise: the neighbour\'s address is not in the SAME store, so this case duplicates the first');

        $absentId = ((int) DB::table('delivery_addresses')->max('id')) + 5000;
        $token = $shopperA->createToken('t')->plainTextToken;

        $withNeighbour = $this->applyCoupon($storeA, $neighbourAddress->id, $token);
        $withAbsent = $this->applyCoupon($storeA, $absentId, $token);

        $this->assertSame('25', (string) data_get($withAbsent->json(), 'data.tax_rate'),
            'premise: the tax fixture does not reach the quote');

        $this->assertSame(
            (string) data_get($withAbsent->json(), 'data.tax_rate'),
            (string) data_get($withNeighbour->json(), 'data.tax_rate'),
            'apply-coupon priced one shopper\'s quote from another shopper\'s address row in the '
            . 'same store. A store_id predicate alone does not refuse this: the row IS this '
            . 'store\'s. The predicate has to be the authenticated customer.'
        );
    }

    /** CONTROL: the shopper's OWN address still prices their own quote. */
    public function test_a_shoppers_own_address_still_prices_their_own_quote(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();

        $taxId = $this->taxFor($storeA);
        $product = $this->productIn($storeA, 'GAPADDR-PRODUCT', $taxId);

        $shopperA = $this->shopperOf($storeA, 'a');
        $this->cartLine($shopperA, $storeA, $product);
        $this->couponFor($storeA);

        $ownAddress = $this->addressFor($shopperA, $storeA);
        $absentId = ((int) DB::table('delivery_addresses')->max('id')) + 5000;

        $token = $shopperA->createToken('t')->plainTextToken;

        $withOwn = $this->applyCoupon($storeA, $ownAddress->id, $token);
        $withAbsent = $this->applyCoupon($storeA, $absentId, $token);

        $this->assertSame(200, $withOwn->status());
        $this->assertSame(1, (int) $withOwn->json('status'),
            'the fix refused a legitimate coupon request carrying the shopper\'s own address');

        $this->assertNotSame(
            (string) data_get($withAbsent->json(), 'data.tax_rate'),
            (string) data_get($withOwn->json(), 'data.tax_rate'),
            'the shopper\'s OWN address stopped reaching the quote, so the fix is a regression: '
            . 'their jurisdiction no longer decides their tax and the quote is the no-address one.'
        );
    }

    /**
     * A MERCHANT'S AND A DRIVER'S TOKEN DO NOT REACH THE STATEMENT AT ALL — AND THIS CASE MEASURES
     * THAT, after the independent review showed the first version could not fail.
     *
     * `custom.auth` logs a token's owner into the guard matching the TOKENABLE CLASS, so for a
     * `User` and a `DeliveryBoy` `auth('customers')->guest()` is TRUE and the cookie-cart branch
     * runs -- the address statement is unreachable for them. That is the claim.
     *
     * WHAT THE FIRST VERSION DID WRONG, recorded because it is this repository's most productive
     * failure mode and the review caught it in two independent ways at once:
     *
     *   (1) Its only needle was `GAPADDR-STREET`, and this handler reads ONLY `country_id`,
     *       `state_id` and `city_id` -- the street is in no response, before or after the fix. The
     *       assertion was true of the unfixed code too.
     *   (2) It supplied no basket, so `Cart::cart_list_cookie()` returned `Utility::error(['message'
     *       => 'Cart is empty.'])` and the handler returned at :4648-4650 -- BEFORE the branch the
     *       case is named after. It never reached the code it claimed to guard.
     *
     * WHAT IT ASSERTS NOW. A real cookie basket (`carts.cookie_session_id` matching the `cart`
     * cookie, which is what `Cart::forGuestCookie()` keys on, `app/Models/Cart.php:37-44`) so the
     * handler proceeds to produce a quote; a PREMISE assertion that it really did (`status === 1`),
     * so the comparison below cannot be between two identical early returns; then the SAME
     * differential the customer cases use, in the opposite direction: the quote must be
     * **IDENTICAL** whether the token names a foreign address or an id belonging to nobody. For a
     * customer those two differ -- that is the defect this file exists for. For a merchant or a
     * driver they must not.
     *
     * AND IT IS MUTATION-MEASURED, in both directions, because "it passes now" was exactly the
     * first version's problem:
     *
     *   CAUGHT -- routing non-customers into the address arm (`if (auth('customers')->guest())`
     *   forced false) with the pre-fix `DeliveryAddress::find()` restored. **It catches it as an
     *   HTTP 500, not as a jurisdiction difference**, and that distinction is recorded rather than
     *   glossed: that arm dereferences the customer in several places, so a non-customer routed
     *   into it crashes before the tax block is computed. The guard that fires is the
     *   `assertNotSame(500, ...)` one. Measured, not assumed -- the first draft of this comment
     *   claimed the tax block moved, and it does not.
     *
     *   NOT CAUGHT -- a `resolveStoreCustomer()` that falls back to an arbitrary customer of the
     *   store for a non-customer principal. Stated plainly because it bounds what this case is
     *   worth: a merchant or driver never reaches that helper on this path (the cookie arm runs),
     *   so the mutant changes code they do not execute. The customer-facing cases above are what
     *   guard that helper, and `test_a_foreign_customers_address_does_not_price_the_quote` catches
     *   it there.
     */
    public function test_a_merchant_and_a_driver_token_are_not_served_the_foreign_address_branch(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $taxId = $this->taxFor($storeA);
        $product = $this->productIn($storeA, 'GAPADDR-PRODUCT', $taxId);
        $this->couponFor($storeA);

        $shopperB = $this->shopperOf($storeB, 'b');
        $foreignAddress = $this->addressFor($shopperB, $storeB);
        $absentId = ((int) DB::table('delivery_addresses')->max('id')) + 5000;

        /* A GUEST COOKIE BASKET, so the handler gets past the empty-cart return and actually
         * produces a quote. Without this the case cannot fail, which is what the review found. */
        $cookie = 'GAPADDR-COOKIE-' . uniqid();
        Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100, 'store_id' => $storeA->id,
            'cookie_session_id' => $cookie,
        ]);

        $driver = DeliveryBoy::forceCreate([
            'name' => 'Driver', 'email' => 'gapaddr-drv-' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'created_by' => $merchantA->id, 'store_id' => $storeA->id,
        ]);

        foreach ([
            'merchant' => $merchantA->createToken('t')->plainTextToken,
            'driver' => $driver->createToken('t')->plainTextToken,
        ] as $label => $bearer) {
            $withForeign = $this->applyCouponWithCookieCart($storeA, $foreignAddress->id, $bearer, $cookie);
            $withAbsent = $this->applyCouponWithCookieCart($storeA, $absentId, $bearer, $cookie);

            $this->assertNotSame(500, $withForeign->status(),
                'apply-coupon crashed for a ' . $label . ' token, so nothing below is measured');

            /* THE PREMISE: the basket reached the handler. If this fails the differential below is
             * comparing two identical early returns and proves nothing -- exactly the trap the
             * first version of this case fell into. */
            $this->assertSame(1, (int) $withForeign->json('status'), sprintf(
                'the %s token got an error envelope (%s), so the cookie basket did not reach the '
                . 'handler and the comparison below would be between two early returns',
                $label,
                (string) $withForeign->json('message')
            ));

            $this->assertSame(
                (string) data_get($withAbsent->json(), 'data.tax_rate'),
                (string) data_get($withForeign->json(), 'data.tax_rate'),
                'a ' . $label . ' token naming ANOTHER TENANT\'S address got a different tax block '
                . 'from one naming an id belonging to nobody, so a non-customer principal is now '
                . 'reaching the address statement. It must not: `auth(\'customers\')->guest()` is '
                . 'true for this principal, and the branch that reads the address is the other arm.'
            );

            $this->assertSame(
                (string) data_get($withAbsent->json(), 'data.tax_price'),
                (string) data_get($withForeign->json(), 'data.tax_price'),
                'same claim on the tax_price limb: cart_list reports tax_price when a jurisdiction '
                . 'IS known and tax_rate when it is not, so both must be checked'
            );
        }
    }

    /** `applyCoupon()` plus the `cart` cookie `Cart::forGuestCookie()` keys on. */
    private function applyCouponWithCookieCart($store, $addressId, string $bearer, string $cookie)
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $bearer])
            ->withUnencryptedCookie('cart', $cookie)
            ->postJson('/api/' . $store->slug . '/apply-coupon', [
                'coupon_code' => self::COUPON_CODE,
                'billing_address_id' => $addressId,
            ]);
    }

    public function test_the_route_still_refuses_a_caller_with_no_token(): void
    {
        [, $storeA] = $this->makeTenant();

        $response = $this->postJson('/api/' . $storeA->slug . '/apply-coupon', [
            'coupon_code' => self::COUPON_CODE,
            'billing_address_id' => 1,
        ]);

        $this->assertSame(401, $response->status(), 'apply-coupon is anonymous');
    }
}
