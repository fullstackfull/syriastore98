<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Utility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * `Utility::CustomerAuthCheck()` MEMOISED ITS ANSWER IN PROCESS-GLOBAL STATICS, KEYED ON NOTHING.
 * ==============================================================================================
 * P4-F123. Found by independent review, sweeping for the shape of a defect this phase had just
 * introduced and removed elsewhere (`App\Support\Commerce\StorePlan`'s store-id memo).
 *
 * `app/Models/Utility.php:1119-1120` declared
 *
 *     private static $store_id = null;
 *     private static $customer = null;
 *
 * and filled them on the first call: the store id resolved from `$slug`, and a COUNT of customers in
 * that store carrying the authenticated customer's email. Every later call in the same PHP process
 * returned those two values **whatever slug it was given and whoever was signed in** — the memo was
 * not keyed on the slug, on the customer, or on anything at all.
 *
 * WHAT IT DECIDES, which is why this is not a tidiness fix. `CustomerAuthCheck()` is the switch that
 * chooses WHICH CART READER prices a basket:
 *
 *     ApiController::couponSpendBaseline:118   Cart::cart_list_cookie()  vs  cart_list()
 *     ApiController::apply_coupon:4619         the same choice again
 *
 * So a stale `true` makes a coupon path price the signed-in `carts` rows where the guest cookie
 * basket was intended, or the reverse. That sits directly underneath the coupon arithmetic this
 * phase spent three rounds of review on, and it is exactly the "a green that cannot be attributed"
 * class.
 *
 * PRODUCTION IS HARDENING, NOT A HOLE, and the distinction is measured rather than assumed: all 21
 * call sites are controllers, middleware or views (no job, listener, command or observer — grepped),
 * so under PHP-FPM the statics die with the request and within one request the slug is the route's
 * store and the authenticated customer does not change. What is unsound is the memo itself, for any
 * caller that ever evaluates two slugs or two principals.
 *
 * IN THE TEST SUITE IT IS LIVE, and that is what these two tests drive. PHPUnit runs a file's methods
 * in declaration order in ONE process, while `RefreshDatabase` restarts the id space for each — so
 * the first test's memo answers the second test's different store. The two methods below must stay in
 * this order and in this file: split them, reorder them, or run either alone, and the defect is
 * invisible. That is the same property that made the `StorePlan` memo show up only in the full suite.
 */
class CustomerAuthCheckIsNotMemoisedTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function customerIn($store, string $tag): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'A', 'last_name' => 'B',
            'email' => $tag . '-' . uniqid() . '@e.test',
            'mobile' => '555' . random_int(100000, 999999), 'password' => bcrypt('x'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    /**
     * FIRST, AND IT FILLS THE MEMO. This must pass, or the second test proves nothing: a `false`
     * here would leave `self::$customer` holding 0 and the stale answer would be the right one by
     * accident.
     */
    public function test_a_customer_of_this_store_passes(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'own');

        $this->actingAs($customer, 'customers');

        $this->assertTrue(Utility::CustomerAuthCheck($store->slug),
            'Premise: a real customer of this store does not pass CustomerAuthCheck, so the '
            . 'staleness test below cannot distinguish a stale true from a correct one.');
    }

    /**
     * SECOND, IN THE SAME PROCESS: a different store, and a signed-in customer who belongs to
     * ANOTHER store. The honest answer is false.
     */
    public function test_a_customer_of_another_store_does_not_pass(): void
    {
        [, $storeB] = $this->makeTenant();
        [, $storeC] = $this->makeTenant();

        // Signed in, but a customer of store C -- not of the store being asked about.
        $foreign = $this->customerIn($storeC, 'foreign');
        $this->actingAs($foreign, 'customers');

        $this->assertFalse(Utility::CustomerAuthCheck($storeB->slug),
            "CustomerAuthCheck said a customer of another store is a customer of this one. The "
            . 'answer came from `Utility::$store_id` / `Utility::$customer`, filled by the previous '
            . 'test in this process and keyed on nothing, so neither the slug nor the signed-in '
            . 'principal was consulted.');
    }

    /** And the no-principal case, which must not consult or fill the memo either. */
    public function test_a_signed_out_visitor_does_not_pass(): void
    {
        [, $store] = $this->makeTenant();

        $this->assertFalse(Utility::CustomerAuthCheck($store->slug),
            'A visitor with no customer session passed CustomerAuthCheck.');
    }
}
