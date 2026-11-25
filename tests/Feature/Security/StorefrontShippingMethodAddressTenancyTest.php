<?php

namespace Tests\Feature\Security;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\DeliveryAddress;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * GAP-243 / P3-F116 — THE WEB SIBLING OF THE COUPON ADDRESS DEFECT, FOUND BY THE REVIEW OF ITS FIX.
 * =============================================================================================
 *
 * The API fix (GAP-235, `ApiController::apply_coupon`) closed a `DeliveryAddress::find(
 * $request->billing_address_id)` that read an id the caller chose. The independent review of that
 * fix went looking for the same statement elsewhere and found it **byte-for-byte** on the web
 * storefront, in `CartController::get_shipping_method()` at :668 — same lookup, same three merged
 * jurisdiction fields, same `cart_list()` call that prices them back:
 *
 *     $address = DeliveryAddress::find($request->billing_address_id);
 *     if ($address) {
 *         $parms['billing_info']['delivery_country'] = $address->country_id;
 *         $parms['billing_info']['delivery_state']   = $address->state_id;
 *         $parms['billing_info']['delivery_city']    = $address->city_id;
 *         $request->merge($parms);
 *     }
 *
 * `POST {storeSlug}/shipping-method` (`routes/customer.php:195`). `DeliveryAddress` is NOT in
 * `config/tenancy.php`'s `scoped_models` and carries no scope, so **every address row in the
 * database answered** — another shopper's in this store, or another tenant's customer's.
 *
 * ---------------------------------------------------------------------------------------------
 * WHAT THE PREDICATE IS, AND WHY IT IS THE CUSTOMER RATHER THAN THE STORE.
 *
 * The statement sits in the `else` arm of `if (auth('customers')->guest())`, so a `Customer` is on
 * the guard by construction — the very next line already dereferences
 * `auth('customers')->user()->id` to key `cart_list()`. So the address must simply belong to **that
 * same customer**, which is the identity the quote is already computed for.
 *
 * A `store_id` predicate would be the weaker choice and would miss the case this test drives
 * second: `delivery_addresses.store_id` is nullable, and a NEIGHBOUR IN THE SAME STORE is this
 * store's row — so a store predicate admits it. The customer predicate refuses it. Same reasoning
 * as the API fix, and the same shape as `update_address()`/`delete_address()`.
 *
 * ---------------------------------------------------------------------------------------------
 * WHAT IS AND IS NOT CLOSED, stated so this is not overread.
 *
 * Only `country_id`, `state_id` and `city_id` are read — not the street, name, phone or postcode —
 * and the handler writes nothing. The same three values remain postable directly as
 * `billing_info[delivery_*]`, which `cart_list()` reads with no address involved. So this closes
 * the READ of someone else's row through the tax block, not a shopper's ability to price their own
 * quote in a jurisdiction of their choosing. That second question is carried separately (GAP-235's
 * residual), exactly as it is on the API side.
 *
 * TWO MORE SITES IN THIS FILE ARE NOT DEFECTS, checked by the review and re-checked here so a later
 * sweep does not "fix" them twice: `:1063` reads a foreign address into `$country/$state_id/
 * $city_id` but all three are unconditionally overwritten at `:1076-1079` before use, and `:850`
 * assigns `$Address`, which is never read again.
 */
class StorefrontShippingMethodAddressTenancyTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function makeShopper(Store $store, string $tag): Customer
    {
        return Customer::forceCreate([
            'first_name' => $tag, 'last_name' => 'F116',
            'email' => 'f116-' . $tag . '-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('x'), 'store_id' => $store->id,
            'status' => 1, 'register_type' => 'email',
        ]);
    }

    /**
     * A DISTINCT REAL JURISDICTION PER ADDRESS. `delivery_addresses.country_id`/`state_id`/`city_id`
     * carry FOREIGN KEYS (the create migration, :31-33), so arbitrary integers are rejected -- and
     * the three cases below can only tell "whose address was merged" apart if each row names
     * different ids. So each call mints its own country/state/city and returns them with the row.
     *
     * @return array{0:DeliveryAddress,1:array{country_id:int,state_id:int,city_id:int}}
     */
    private function makeAddress(Customer $customer, Store $store, string $tag): array
    {
        $countryId = (int) DB::table('countries')->insertGetId(
            ['name' => 'F116-' . $tag, 'created_at' => now(), 'updated_at' => now()]);
        $stateId = (int) DB::table('states')->insertGetId(
            ['name' => 'F116-ST-' . $tag, 'country_id' => $countryId, 'created_at' => now(), 'updated_at' => now()]);
        $cityId = (int) DB::table('cities')->insertGetId(
            ['name' => 'F116-CT-' . $tag, 'country_id' => $countryId, 'state_id' => $stateId, 'created_at' => now(), 'updated_at' => now()]);

        $jurisdiction = ['country_id' => $countryId, 'state_id' => $stateId, 'city_id' => $cityId];

        $address = DeliveryAddress::forceCreate(array_merge([
            'customer_id' => $customer->id, 'store_id' => $store->id,
            'full_name' => 'F116-' . $tag, 'phone' => '0100000000',
            'title' => 'home', 'address' => 'F116-STREET',
            'postcode' => 12345, 'default_address' => 0, 'type' => 0,
        ], $jurisdiction));

        return [$address, $jurisdiction];
    }

    /**
     * THE READ ITSELF, measured on the handler's own merge rather than on a response field.
     *
     * The response is a rendered view whose tax block depends on the merchant having a matching tax
     * method, so asserting on the HTML would make this test depend on tax fixtures it does not own
     * and could pass for the wrong reason. What the defect IS, exactly, is that the handler merges a
     * foreign row's jurisdiction into the request — so that is what is asserted, by reading the
     * request back out of the container after the handler has run.
     */
    public function test_a_shopper_cannot_merge_another_tenants_customers_address(): void
    {
        $victimMerchant = $this->makeMerchant();
        $victimStore = $this->makeStore($victimMerchant);
        $victim = $this->makeShopper($victimStore, 'victim');
        [$foreignAddress, $foreign] = $this->makeAddress($victim, $victimStore, 'victim');

        $attackerMerchant = $this->makeMerchant();
        $attackerStore = $this->makeStore($attackerMerchant);
        $attacker = $this->makeShopper($attackerStore, 'attacker');

        $merged = $this->driveShippingMethod($attacker, $attackerStore, $foreignAddress->id);

        $this->assertNotSame($foreign['country_id'], $merged['delivery_country'] ?? null,
            'the handler merged ANOTHER TENANT\'S customer\'s address jurisdiction into the quote '
            . 'request. DeliveryAddress has no tenant scope and is not in scoped_models, so '
            . '`DeliveryAddress::find($request->billing_address_id)` was a liveness check on an id '
            . 'the caller chose, not an identity check -- and cart_list() then selects the '
            . 'merchant\'s tax methods BY JURISDICTION, which prices the foreign row back out.');

        $this->assertNull($merged['delivery_country'] ?? null,
            'a foreign address id still produced a jurisdiction merge');
    }

    /**
     * THE SAME-STORE NEIGHBOUR — the case a `store_id` predicate would MISS, which is why this test
     * exists separately rather than being folded into the cross-tenant one above.
     */
    public function test_a_shopper_cannot_merge_a_same_store_neighbours_address(): void
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);

        $neighbour = $this->makeShopper($store, 'neighbour');
        [$neighbourAddress, $neighbourJurisdiction] = $this->makeAddress($neighbour, $store, 'neighbour');

        $attacker = $this->makeShopper($store, 'attacker');

        $merged = $this->driveShippingMethod($attacker, $store, $neighbourAddress->id);

        $this->assertNotSame($neighbourJurisdiction['country_id'], $merged['delivery_country'] ?? null,
            'the handler merged a SAME-STORE neighbour\'s address. This row belongs to this store, '
            . 'so a `store_id` predicate would admit it -- the predicate has to be the CUSTOMER.');
    }

    /** THE CONTROL: the shopper's OWN address still prices their quote. The fix must not break the
     *  ordinary checkout, which is the whole purpose of the statement. */
    public function test_a_shopper_still_merges_their_own_address(): void
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);

        $shopper = $this->makeShopper($store, 'owner');
        [$own, $ownJurisdiction] = $this->makeAddress($shopper, $store, 'owner');

        $merged = $this->driveShippingMethod($shopper, $store, $own->id);

        $this->assertSame($ownJurisdiction['country_id'], (int) ($merged['delivery_country'] ?? 0),
            'the shopper\'s OWN address was refused, so the fix breaks every real checkout at the '
            . 'shipping-method step rather than closing a cross-tenant read');
        $this->assertSame($ownJurisdiction['state_id'], (int) ($merged['delivery_state'] ?? 0));
        $this->assertSame($ownJurisdiction['city_id'], (int) ($merged['delivery_city'] ?? 0));
    }

    /**
     * Drives `POST {slug}/shipping-method` as the given shopper and returns whatever the handler
     * merged into `billing_info`, read back out of the request the container actually dispatched.
     *
     * A NOTE ON THE GUARD, CORRECTED: this comment used to say the shopper is signed in
     * "specifically, not the default one, because `actingAs($c, 'customers')` would make
     * `customers` the default guard and hide a handler that reads the wrong one" — while the line
     * below is literally `actingAs($shopper, 'customers')`. A comment that contradicts its own code
     * is worse than no comment, and a future census for that shape would hit this file. Flagged by
     * the independent review.
     *
     * WHY IT IS NEVERTHELESS SOUND HERE, which is the part that matters: CLAUDE.md's warning is
     * about a handler whose behaviour depends on WHICH guard is default. This one cannot —
     * `CartController:636`, `:669` and `:677` all name `auth('customers')` explicitly — and the
     * assertion does not read a rendered response but the `billing_info` the handler MERGED, taken
     * back off the dispatched request. A changed default guard moves neither. Where the default
     * guard would matter, the helper to use is `asAShopper()`
     * (`tests/Feature/Security/PlatformUploaderDoorsTest.php:670`).
     *
     * @return array<string,mixed>
     */
    private function driveShippingMethod(Customer $shopper, Store $store, int $addressId): array
    {
        /* A basket, because the handler's else arm forwards to cart_list() and an empty cart short-
         * circuits before the merge -- which would make every case above pass vacuously. */
        $product = Product::forceCreate([
            'name' => 'F116-PRODUCT-' . uniqid(), 'slug' => 'f116-' . uniqid(),
            'description' => 'x', 'product_stock' => 10, 'track_stock' => 1,
            'variant_product' => 0, 'stock_status' => 'in_stock', 'trending' => 0,
            'status' => 1, 'price' => 100, 'sale_price' => 100,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);

        Cart::forceCreate([
            'customer_id' => $shopper->id, 'product_id' => $product->id,
            'qty' => 1, 'price' => 100, 'store_id' => $store->id,
        ]);

        /* The merge happens inside the handler and is then consumed by cart_list(); reading it back
         * from the dispatched request is the direct measurement of the defect. */
        $response = $this->withSession([])
            ->actingAs($shopper, 'customers')
            ->post('/' . $store->slug . '/shipping-method', [
                'billing_address_id' => $addressId,
                'method_id' => 0,
            ]);

        $this->assertNotSame(500, $response->status(),
            'the shipping-method handler crashed, so this case measures a crash rather than the '
            . 'address predicate');

        $request = $this->app['request'];
        $captured = (array) ($request->input('billing_info') ?? []);

        return $captured;
    }
}
