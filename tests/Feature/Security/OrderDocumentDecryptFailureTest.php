<?php

namespace Tests\Feature\Security;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * P2.5-PKG-C (F-C-05) — four `catch (DecryptException)` blocks that had never run.
 * ================================================================================================
 *
 * `OrderController.php` carried `catch (DecryptException $e)` at four sites, all UNQUALIFIED, with
 * no `use` statement for the class anywhere in the file. An unqualified name in a catch resolves
 * against the current namespace, so each named `App\Http\Controllers\DecryptException` — measured
 * absent, while `Illuminate\Contracts\Encryption\DecryptException` is what `Crypt::decrypt()`
 * throws.
 *
 * WHY IT SURVIVED: two of the four have a SECOND, fully-qualified `catch (\Exception $e)` right
 * after, returning the same message, so `order_view` and `order_receipt` behaved correctly BY
 * ACCIDENT. `shippinglabel` has only the dead catch and `show()` had no `try` at all, so those two
 * answered HTTP 500 with the exception's stack page.
 *
 * WHY THE ID BELOW IS A PLAIN INTEGER OF A REAL, OWNED ORDER, and not random text. These routes
 * sit behind `OrderDocumentAccessGuard`, whose `resolveOrderId()` tries `Crypt::decrypt()` and
 * FALLS BACK to the raw value when that fails. So:
 *   - random text   -> resolveOrderId() returns null -> the guard denies with 403, and the
 *                      controller is never reached. That tests the guard, not this fix.
 *   - "1"           -> the guard resolves order 1, finds it in the caller's store, authorises,
 *                      and hands over. THEN the controller's own `Crypt::decrypt('1')` throws.
 * Only the second shape reaches the code this test is about. Measured both ways before choosing.
 *
 * Severity, stated rather than inflated: a merchant always arrives from a list link carrying a
 * token this application produced, so this is the quality of the error page on a stale or
 * hand-edited URL — not a broken screen. Four handlers that have never once run is still worth
 * one import.
 */
class OrderDocumentDecryptFailureTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** @return array{0: \App\Models\User, 1: int} */
    private function merchantWithOwnOrder(): array
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Show Order');

        $order = $this->makeOrder($store);

        return [$merchant, (int) $order->id];
    }

    /**
     * `OrderController::show()` had NO try at all.
     *
     * ONE URI REACHES IT, NOT TWO. An earlier version of this docblock said `show()` was "routed
     * twice", via `order/{order}` and `orders/order_view/{id}`. Raised by the tenant-authz review
     * and confirmed against my own measurement: `GET /order/{order}` answers **403** and never
     * reaches the method. `show(Order $order, $id)` names its first parameter `order`, which
     * matches that route's segment, so `SubstituteBindings` — which runs BEFORE the appended
     * `OrderDocumentAccessGuard` — replaces the value with an `Order` instance;
     * `resolveOrderId()` then rejects it at `OrderDocumentAccessGuard.php:405`
     * (`! is_string($raw)`) and the guard aborts. Fail-closed in every direction, and a separate
     * finding (F-C-02) rather than something this test covers.
     *
     * So `orders/order_view/{id}` — the eye/Show button — is the only URI that exercises the fix.
     */
    public function test_the_order_show_modal_reports_a_bad_id_instead_of_answering_500(): void
    {
        [$merchant, $id] = $this->merchantWithOwnOrder();

        $response = $this->actingAs($merchant)->get('/orders/order_view/' . $id);

        $this->assertNotSame(
            500,
            $response->status(),
            'An undecryptable id still produces a 500 stack page on the order Show modal.'
        );

        // TIED TO THE CATCH ARM, not merely to "a redirect happened". A bare assertRedirect()
        // would also pass if some other middleware -- `verified`, say -- bounced the request,
        // which would make the 500 disappear for the wrong reason. Raised by the tenant-authz
        // review. The string is the one all four sibling handlers flash.
        $response->assertRedirect();
        $response->assertSessionHas('error', __('Something was wrong.'));
    }

    /**
     * `shippinglabel()` has a `catch (DecryptException $e)` and NO `\Exception` fallback, so it
     * was the clearest of the four: the intended handler existed and could never fire.
     */
    public function test_the_shipping_label_reports_a_bad_id_instead_of_answering_500(): void
    {
        [$merchant, $id] = $this->merchantWithOwnOrder();

        $response = $this->actingAs($merchant)->get('/shippinglabel/pdf/' . $id);

        $this->assertNotSame(
            500,
            $response->status(),
            'An undecryptable id still produces a 500 stack page on the shipping label.'
        );

        $response->assertRedirect();
        $response->assertSessionHas('error', __('Something was wrong.'));
    }

    /**
     * THE POSITIVE CONTROL, and it is load-bearing: every assertion above is satisfied by a
     * handler that redirects unconditionally. This proves a VALID token still renders.
     */
    public function test_a_valid_encrypted_id_still_renders_the_order_detail(): void
    {
        [$merchant, $id] = $this->merchantWithOwnOrder();

        $this->actingAs($merchant)
            ->get('/order-view/' . \Crypt::encrypt($id))
            ->assertStatus(200);
    }

    /**
     * THE POSITIVE CONTROL FOR `show()` ITSELF. The one above drives `order_view()` -- a different
     * method -- so it could not have caught a `show()` whose new `try` broke the success path.
     * Raised by the tenant-authz review. Low risk, since the `try` wraps a single statement, but a
     * control that does not touch the changed method is not a control for it.
     */
    public function test_a_valid_encrypted_id_still_renders_the_order_show_modal(): void
    {
        [$merchant, $id] = $this->merchantWithOwnOrder();

        $this->actingAs($merchant)
            ->get('/orders/order_view/' . \Crypt::encrypt($id))
            ->assertStatus(200);
    }

    /**
     * F-C-03. `CustomFilterData` read `$requestData['customer_field']` SIX times unguarded; one of
     * the six already carried `?? null` with a comment about a previous round of this same defect.
     * A bare request answered 500, `Undefined array key "customer_field"`.
     *
     * Guarding only the first read is not enough and that is not hypothetical — it is what the
     * first attempt at this fix did, and the probe answered 500 again naming the second site. The
     * second attempt then rewrote the declaration itself into `$customerField = $customerField;`
     * and the probe named that. Both were caught by re-driving, neither by reading the diff.
     *
     * ASSERTS 200, NOT MERELY "NOT 500". The tenant-authz review pointed out that
     * `assertNotSame(500, …)` is satisfied by a 404, a 403 or a redirect — which is this project's
     * own "the route does not exist and the 404 satisfies the assertion" failure mode. It reddens
     * on today's revert either way, but it would not notice a future rename or a new gate.
     */
    public function test_the_customer_filter_fragment_survives_a_request_with_no_filter(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Customer');

        $customer = \App\Models\Customer::forceCreate([
            'first_name' => 'Filterable', 'last_name' => 'Shopper',
            'email' => 'filterable-' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'store_id' => $store->id,
        ]);

        $response = $this->actingAs($merchant)->get('/customer-filter-data');

        $response->assertStatus(200);

        // AND it really is the fragment, not an empty 200: the fixture customer's own address is
        // in the body. Without this, a handler that returned an empty string would pass.
        $response->assertSee($customer->email, false);
    }

    /**
     * F-C-06, raised by the tenant-authz review of the fix above and NOT by the fix itself.
     *
     * `CustomFilterData()` checked NOTHING — no permission, no policy, no operator limb — while
     * returning every customer in the caller's store with name, EMAIL, registration date,
     * last-active date, order count and total spend. Its route is in the
     * `['auth','xss','setlocate','verified']` group, so any authenticated `web` principal reached
     * it, a staff member holding zero customer permissions included.
     *
     * The review was right that this is not a regression from F-C-03: the gap was already
     * reachable with ANY query parameter, since any value misses all five filter branches and
     * falls through to `$query->get()`. Guarding the absent-key case conferred no new capability.
     * It is still a live gap, so it is closed.
     *
     * `Manage Customer` locks nobody out: `index()` and `customer_grid_view()` already require
     * exactly that, and this fragment is fetched over ajax from a page the refused principal
     * cannot open.
     */
    public function test_a_staff_member_with_no_permissions_cannot_read_the_customer_filter_data(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);

        $customer = \App\Models\Customer::forceCreate([
            'first_name' => 'Private', 'last_name' => 'Shopper',
            'email' => 'private-' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'store_id' => $store->id,
        ]);

        $response = $this->actingAs($staff)->get('/customer-filter-data');

        $response->assertRedirect();
        $response->assertDontSee($customer->email, false);
    }

    /**
     * And the OPERATOR is not refused by the new gate. Laratrust has no super-admin bypass and the
     * operator holds no merchant role, so a bare `isAbleTo()` would have refused the one principal
     * who legitimately reads across stores — the ordering defect CLAUDE.md records as repeated.
     * The two sibling handlers omit this limb; this one does not.
     */
    public function test_the_platform_operator_is_not_refused_by_the_new_gate(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $operator = $this->makePlatformAdmin(['current_store' => $store->id]);

        $this->actingAs($operator)->get('/customer-filter-data')->assertStatus(200);
    }

    /**
     * F-C-04. `Route::resource('customer', …)` registered `edit` and `update` while
     * `CustomerController` has NEITHER METHOD, so both answered HTTP 500 with a
     * `BadMethodCallException`. 404 is the correct answer for a screen that does not exist.
     *
     * This asserts the ROUTE IS GONE rather than that the response is 404 for some other reason:
     * a 404 would also be produced by a missing customer row, which is a different fact.
     */
    public function test_the_unimplemented_customer_edit_and_update_routes_are_not_registered(): void
    {
        $uris = collect(\Route::getRoutes()->getRoutes())
            ->map(fn ($r) => implode('|', $r->methods()) . ' ' . $r->uri())
            ->all();

        $this->assertNotContains('GET|HEAD customer/{customer}/edit', $uris);
        $this->assertNotContains('PUT|PATCH customer/{customer}', $uris);

        // And the ones that DO have handlers are untouched — otherwise `except()` could have been
        // given the wrong list and this test would still pass.
        $this->assertContains('GET|HEAD customer/{customer}', $uris);
        $this->assertContains('DELETE customer/{customer}', $uris);
        $this->assertContains('GET|HEAD customer/create', $uris);
        $this->assertContains('POST customer', $uris);
    }
}
