<?php

namespace Tests\Feature\Security;

use App\Models\Cart;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * P2.5-PKG-C -- THE TWO "NUDGE THE SHOPPER" ACTIONS READ ANY STORE'S CART FOR A STORELESS CALLER.
 * ================================================================================================
 *
 * FOUND BY THE TENANT-AUTHZ REVIEW OF A NEIGHBOURING CHANGE. The P2.5-PKG-C identifier repair
 * touched `abandon_carts_show()` and added a test proving that a STORELESS principal exists and
 * that `Cart`'s global scope does nothing for them. The review's point was that the two handlers
 * one click away on the same screen depend on that same scope and have no predicate of their own
 * -- so the new test was evidence against the untouched code sitting beside it.
 *
 * THE EXISTING DOCBLOCK IN THAT HANDLER SAYS THE OPPOSITE, and that is the part worth recording:
 * `CartController::abandon_carts_messsend()` carries a P1-SEC comment stating "The tenant scope
 * contained the READ (Cart is allowlisted), so this was not a cross-tenant disclosure". That is
 * true for a caller who resolves a tenant, and false for one who does not. A containment claim
 * that is only true for the principal you happened to test reads, later, as a claim about all of
 * them.
 *
 * MEASURED, before the predicates below existed, for a storeless `type=admin` user holding
 * `Abandon Cart` and posting ANOTHER tenant's `cart_id`:
 *
 *     Cart::find(<victim's cart id>)   -> FOUND          (the scope is inert: getCurrentStore() is NULL)
 *     POST /abandon-carts-emailsend    -> 500            (ErrorException: $store is null downstream)
 *     POST /abandon-carts-messagesend  -> 200, is_success false,
 *                                         "Invalid Auth access token - Cannot parse access token"
 *
 * THAT LAST LINE IS THE SERIOUS ONE. The 500 means the mail path died before sending. The SMS path
 * did NOT die -- it reached `Utility::SendMsgs()` and the gateway refused it, and the only reason
 * the gateway refused it is that no Twilio credentials exist in the test environment. On a
 * configured store that message goes OUT: to another tenant's customer, naming another tenant's
 * products, from a merchant who has no relationship with either. A read is bad; an outbound
 * message to someone else's customer is worse, and it is not recoverable once sent.
 *
 * WHO THE PRINCIPAL IS. `getCurrentStore()` falls back to `Store::where('created_by', $user->id)`
 * -- the caller's OWN id -- so it is NULL not only for a merchant who has created no store but for
 * any STAFF member whose `current_store` is unset, because staff never created the store they work
 * in. That is not an exotic account.
 *
 * WHAT THE REPAIR IS. The same shape already applied to `abandon_carts_show()`: refuse when no
 * store resolves, and name `store_id` on the lookup and on the follow-up `customer_id` query. The
 * follow-up matters separately from the lookup -- it is keyed on `customer_id`, and customer ids
 * are reused across tenants in a shared database, so a lookup that resolves correctly can still
 * collect a different store's lines for the same id.
 *
 * WHAT MUTATION SHOWS, because "both guards present" is not the same as "both guards tested":
 *
 *   - Remove only the lookup predicates: green -- the early refusal fires first.
 *   - Remove only the two `empty($storeId)` refusals: RED, but ONLY because of
 *     `test_a_storeless_caller_cannot_reach_a_null_store_cart_row`. Without that case this mutant
 *     survived, and the first version of this file recorded it as surviving: the predicate alone
 *     refuses a storeless caller, so the guards were credited for the predicate's work. A row
 *     with a NULL `store_id` is what separates them, and `carts.store_id` is nullable with no
 *     default, so that row is a real possibility rather than a contrivance.
 *   - Remove BOTH: RED.
 *
 * So the two guards are independently sufficient, and that is deliberate rather than redundant.
 * The predicate closes the hole given what the table CONTAINS; the explicit refusal closes it
 * regardless of contents, which matters because `carts.store_id` is nullable with no default
 * (2024_01_18_103138) and one legacy or imported NULL-store row would otherwise be readable by
 * any storeless caller holding `Abandon Cart`.
 *
 * THE POSITIVE CONTROLS ARE NOT DECORATION HERE. Both endpoints answer 200 with
 * `is_success: false` in this environment whatever happens, because neither mail nor SMS is
 * configured -- so "it failed" is the resting state and a predicate that refused EVERYTHING would
 * look identical to a working one. The controls assert the owner's own cart still RESOLVES, by
 * requiring the response not to be the 404 that the refusal produces.
 */
class AbandonedCartSendStoreScopeTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** @return array{0: \App\Models\User, 1: Cart} the victim merchant and a cart row in their store */
    private function victimWithCart(): array
    {
        [$victim, $store] = $this->makeTenant();

        $customer = Customer::forceCreate([
            'first_name' => 'V', 'last_name' => 'Shopper',
            'email' => 'victim' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'store_id' => $store->id,
        ]);

        $cart = Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => 4321, 'variant_id' => 0,
            'qty' => 1, 'price' => 5, 'store_id' => $store->id,
        ]);

        return [$victim, $cart];
    }

    public static function sendEndpoints(): array
    {
        return [
            'email' => ['/abandon-carts-emailsend'],
            'sms' => ['/abandon-carts-messagesend'],
        ];
    }

    /**
     * @dataProvider sendEndpoints
     */
    public function test_a_storeless_caller_cannot_nudge_another_stores_shopper(string $endpoint): void
    {
        [, $foreignCart] = $this->victimWithCart();

        $storeless = $this->makeMerchant(['current_store' => null]);
        $this->grantPermission($storeless, 'Abandon Cart');

        // The premise, asserted rather than assumed: for THIS caller the global scope is inert, so
        // the explicit predicate is the only thing under test.
        $this->actingAs($storeless);
        $this->assertNotNull(
            Cart::find($foreignCart->id),
            'premise: with no tenant resolved the global scope must NOT hide the foreign row -- '
            . 'if it does, this test would pass without any predicate in the handler.'
        );

        $response = $this->actingAs($storeless)->postJson($endpoint, ['cart_id' => $foreignCart->id]);

        $this->assertSame(
            404,
            $response->getStatusCode(),
            'a caller with no store reached another store\'s abandoned cart through ' . $endpoint
            . '. Status ' . $response->getStatusCode() . '. A 500 means the handler read the row '
            . 'and died downstream; a 200 means it read the row and handed it to the mail or SMS '
            . 'gateway.'
        );
    }

    /**
     * THE ROW THAT MAKES THE `empty($storeId)` GUARDS LOAD-BEARING: a cart line with a NULL store.
     *
     * ADDED AFTER MUTATION, and after the same gap was found and closed on the wishlist side. The
     * mutation table above records that removing the two guards alone left these tests green --
     * because without them the lookup compiles to `store_id IS NULL` and no fixture row carries a
     * null store, so the query misses and the handler 404s for the wrong reason. The guards were
     * being credited for a refusal the predicate produced.
     *
     * `carts.store_id` IS nullable with no default (2024_01_18_103138). For a caller whose
     * `getCurrentStore()` is NULL, `store_id IS NULL` MATCHES such a row, so without the explicit
     * refusal a storeless caller reads and acts on a line belonging to no tenant -- and on the SMS
     * path, hands it to the gateway. With the refusal, no query runs at all.
     *
     * @dataProvider sendEndpoints
     */
    public function test_a_storeless_caller_cannot_reach_a_null_store_cart_row(string $endpoint): void
    {
        [, $orphanCart] = $this->victimWithCart();
        \DB::table('carts')->where('id', $orphanCart->id)->update(['store_id' => null]);

        $storeless = $this->makeMerchant(['current_store' => null]);
        $this->grantPermission($storeless, 'Abandon Cart');

        $this->assertNull(
            \DB::table('carts')->where('id', $orphanCart->id)->value('store_id'),
            'premise: the row under test must carry a NULL store_id, or `store_id IS NULL` would '
            . 'not match it and this test would pass without the guard'
        );

        $response = $this->actingAs($storeless)->postJson($endpoint, ['cart_id' => $orphanCart->id]);

        $this->assertSame(
            404,
            $response->getStatusCode(),
            'a storeless caller reached a NULL-store cart row through ' . $endpoint
            . '. Status ' . $response->getStatusCode()
        );
    }

    /**
     * @dataProvider sendEndpoints
     */
    public function test_the_owner_can_still_nudge_their_own_shopper(string $endpoint): void
    {
        [$owner, $ownCart] = $this->victimWithCart();
        $this->grantPermission($owner, 'Abandon Cart');

        $response = $this->actingAs($owner)->postJson($endpoint, ['cart_id' => $ownCart->id]);

        // Not "succeeded" -- neither gateway is configured here, so both endpoints answer 200 with
        // is_success false. What this asserts is that the cart still RESOLVED: 404 is the refusal.
        $this->assertNotSame(
            404,
            $response->getStatusCode(),
            'the new store predicate refused the owner their OWN cart through ' . $endpoint
        );
    }
}
