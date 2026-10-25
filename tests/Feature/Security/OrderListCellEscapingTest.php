<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The order list's raw columns escape the values a SHOPPER chooses.
 * =================================================================================================
 *
 * `OrderDataTable::dataTable()` ends with `->rawColumns([... 'customer_id' ...])`, which tells
 * yajra not to escape that cell -- correctly, because the cell contains a `<br>` this file writes.
 * The values around that `<br>` are not this file's: `CustomerData->name` is
 * `first_name . ' ' . last_name` on a row a shopper created, and registration validates those as
 * `'required','string','max:255'` and nothing more. So the shopper chose a string that printed
 * into the MERCHANT'S admin origin, where it executes with the merchant's `web` session.
 *
 * THE ONE LAYER THAT LOOKS LIKE IT WOULD STOP THIS DOES NOT, and that is the part worth knowing.
 * `app/Http/Middleware/XSS.php` runs `htmlspecialchars_decode($value)` and then strips four
 * strings: `&lt;`, `&gt;`, `javascript`, `alert`. The decode converts an encoded `&lt;` into a raw
 * `<` BEFORE the strip looks for `&lt;`, and a raw `<` was never in the list anyway. So the
 * middleware removes two words and two entity spellings, and passes `<img src=x onerror=...>`
 * through intact. A denylist of four strings is not an escaping layer, and the test below drives
 * a payload that contains none of the four.
 *
 * TENANCY IS NOT THE CONTROL HERE AND IS NOT CLAIMED AS ONE. `Customer` IS in
 * `config/tenancy.php`'s `scoped_models`, so the relation does not cross stores -- the attacker
 * has to be a shopper of the victim merchant's own store, which costs one registration. This is a
 * same-tenant privilege escalation (shopper -> merchant session), not a cross-tenant read.
 *
 * Found by the tenant/authz review of P2-04-4, on the same `rawColumns()` line that change edits.
 */
class OrderListCellEscapingTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * A payload that survives `XSS.php` verbatim: no `&lt;`, no `&gt;`, no `javascript`, no
     * `alert`. If the middleware is ever made real, this string going missing from the REQUEST
     * is not what this test measures -- the customer row is written directly.
     */
    private const PAYLOAD = '<img src=x onerror=fetch(0)>';

    private function makeShopper(int $storeId, string $firstName, string $mobile = '0'): Customer
    {
        return Customer::forceCreate([
            'first_name' => $firstName,
            'last_name' => '',
            'email' => 'shopper' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            'mobile' => $mobile,
            'store_id' => $storeId,
            'status' => 1,
            'register_type' => 'email',
        ]);
    }

    /**
     * The order list's AJAX leg -- and it must be the FIRST request this test makes.
     *
     * yajra's `Services\DataTable::render()` branches on
     * `request()->ajax() && request()->wantsJson()`, so only the AJAX leg builds any rows at
     * all; the page leg returns the empty table shell. Both headers are needed, because
     * `getJson()` supplies `Accept` and nothing supplies `X-Requested-With`.
     *
     * AND NOTHING MAY DRIVE A ROUTE BEFORE IT. `Yajra\DataTables\Utilities\Request` captures
     * `app('request')` when the container first resolves it and keeps it for the rest of the
     * test, so a liveness check like `$this->get('/order')->assertOk()` placed above this call
     * fixes `ajax()` at FALSE for every later request in the same test method -- and the ajax
     * leg then quietly returns the page. Measured: with the page request first, all three cases
     * here asserted against a 1,300-line HTML shell containing no customer cell, which is a
     * state in which "the payload is absent" is true and means nothing. The premise assertion
     * below is what caught it; `assertOk()` here covers the liveness the page request was for.
     */
    private function orderListHtml(\App\Models\User $merchant): string
    {
        $response = $this->actingAs($merchant)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
            ->get('/order?draw=1&start=0&length=10');

        $this->assertNotSame(404, $response->getStatusCode(), 'the order list must exist');
        $this->assertNotSame(500, $response->getStatusCode(), 'the order list must not 500');
        $response->assertOk();

        $body = (string) $response->getContent();

        $this->assertStringStartsWith('{', ltrim($body),
            'premise: the ajax leg answered with JSON. The page shell has no rows in it, so '
            . 'every assertion below would be about markup that was never rendered.');

        return $body;
    }

    public function test_a_shoppers_name_cannot_inject_markup_into_the_merchants_order_list(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order');

        $shopper = $this->makeShopper($store->id, self::PAYLOAD);

        $this->makeOrder($store, [
            'product_order_id' => 'ORD-XSS',
            'customer_id' => $shopper->id,
            'is_guest' => 0,
        ]);

        $html = $this->orderListHtml($merchant);

        // PREMISE: the row is in the response at all. Without this, "the payload is absent"
        // is satisfied by an empty table, a 403, or a filter that dropped the order.
        $this->assertStringContainsString('ORD-XSS', $html,
            'premise: the order is in the list, so the cell below was actually rendered');

        /*
         * PREMISE 2: the shopper's name reached the cell in SOME form. If the relation resolved
         * to null the assertion below would hold for the wrong reason -- and `Customer` is a
         * scoped model, which is exactly the kind of thing that silently returns null in a test.
         */
        $this->assertStringContainsString('img src=x', $html,
            'premise: the customer name reached the cell; if not, this test proves nothing');

        // THE POINT: it is there as TEXT, not as a tag.
        $this->assertStringNotContainsString('<img src=x', $html,
            'a shopper-chosen name reached the merchant order list as live markup -- the '
            . 'customer_id column is in rawColumns(), so it must be escaped in the handler');
    }

    public function test_a_shoppers_mobile_number_is_escaped_too(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order');

        // The same cell, the other half of it. A sweep that escaped only the name would pass
        // the case above and leave this one open.
        $shopper = $this->makeShopper($store->id, 'Ordinary Name', self::PAYLOAD);

        $this->makeOrder($store, [
            'product_order_id' => 'ORD-XSS2',
            'customer_id' => $shopper->id,
            'is_guest' => 0,
        ]);

        $html = $this->orderListHtml($merchant);

        $this->assertStringContainsString('ORD-XSS2', $html, 'premise: the order is in the list');
        $this->assertStringContainsString('img src=x', $html, 'premise: the mobile reached the cell');

        $this->assertStringNotContainsString('<img src=x', $html,
            'the mobile number half of the customer cell is unescaped');
    }

    /**
     * THE ORDER REFERENCE CELL, which gets there by a longer route than the customer name.
     *
     * `orders.product_order_id` is a STRING column and `PaymentService.php:2689` builds it as
     * `$request->customer_id . date('YmdHis')` for an authenticated shopper -- raw caller input.
     * The same request value also goes to `orders.customer_id`, an `unsignedBigInteger`, and the
     * obvious objection is that the insert would fail on it -- but `config/database.php` sets
     * `'strict' => false` on the `mysql` connection, so MySQL coerces that one to 0 and the
     * string column stores the payload whole.
     *
     * This drives the RENDERING, by writing the column directly. That the shopper can reach the
     * column is established by reading those three facts, not by driving checkout -- and the
     * authorization half of it is ledger F46, deliberately not fixed in this slice because it
     * sits on a payment path.
     */
    public function test_an_order_reference_cannot_inject_markup(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order');

        $this->makeOrder($store, ['product_order_id' => self::PAYLOAD . '20261025120000']);

        $html = $this->orderListHtml($merchant);

        $this->assertStringContainsString('img src=x', $html,
            'premise: the reference reached the cell');

        $this->assertStringNotContainsString('<img src=x', $html,
            'the order reference cell is unescaped, and that column is written from '
            . 'request input on the checkout path');
    }

    /** The cell still shows the real value, so escaping did not turn it into entities on screen. */
    public function test_an_ordinary_customer_name_still_renders(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order');

        $shopper = $this->makeShopper($store->id, 'Amina Haddad', '0912345678');

        $this->makeOrder($store, [
            'product_order_id' => 'ORD-OK',
            'customer_id' => $shopper->id,
            'is_guest' => 0,
        ]);

        $html = $this->orderListHtml($merchant);

        $this->assertStringContainsString('Amina Haddad', $html);
        $this->assertStringContainsString('0912345678', $html);
    }
}
