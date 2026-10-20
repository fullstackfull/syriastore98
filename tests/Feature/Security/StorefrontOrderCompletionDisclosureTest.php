<?php

namespace Tests\Feature\Security;

use App\Models\Order;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * P1-ORD-08 -- THE ANONYMOUS "THANK YOU" AND "TRACK MY ORDER" PAGES DISCLOSED ANY ORDER.
 * =============================================================================================
 *
 * Both routes are anonymous BY DESIGN: `order-complete` is where a guest lands after paying, and
 * `order-track` is where a guest looks an order up, so neither can require a login. The defect is
 * that neither asked the visitor to PROVE they placed the order.
 *
 * MEASURED, P1-SEC-03 round 2, with no session of any kind:
 *
 *   GET  /{storeA}/order-complete?data[order_id]=<storeB order>   -> 200, the victim's order number
 *        in the body, and a minted `/order/<ciphertext>` link that returned the FULL order (email,
 *        phone, address, line items, total) at store B's public slug.
 *   POST /{store}/order-track {order_number, email: anything}      -> 200 with name, email, phone.
 *        The submitted email was compared to nothing: the gate was `order_number || email`.
 *
 * `product_order_id` is a bare timestamp ('0' . date('YmdHis') for a guest), so one store-day is
 * 86,400 guesses; the order PRIMARY KEY is a sequential integer.
 *
 * WHY THE OBVIOUS FIX IS NOT THE FIX
 * ---------------------------------------------------------------------------------------------
 * Round 1 recommended scoping the four `Order::find()` reads to the slug's store, and a critic
 * recommended deleting the request-input branches. BOTH ARE WRONG, and finding out why took reading
 * the callers rather than the handler:
 *
 *  - Store-scoping alone is nearly cosmetic. An attacker simply addresses the VICTIM's own (public)
 *    slug, where the order's store matches. It closes a mismatched slug, not an enumerator.
 *  - `data[order_id]` has exactly one legitimate producer: the WhatsApp/Telegram checkout, whose
 *    success payload is `data => ['order_id' => $order->id]` and whose blade forwards it to this
 *    page as a query string. Deleting the branch breaks those checkouts. (`responce[data][order_id]`
 *    has NO producer anywhere in resources/, themes/ or public/ -- the grep hits are server-side
 *    `$responce` variables -- so that branch is dead for legitimate use and is removed.)
 *
 * So the request-supplied id is accepted ONLY if it is one this very SESSION placed:
 * `PaymentController::whatsapp()` records the new order's id under `order_complete_ids`, and
 * `orderComplete()` honours `data[order_id]` only for ids in that list. A session id is random and
 * server-held, so unlike a `Crypt::encrypt()` token it is NOT forgeable with the leaked APP_KEY.
 * The gateway handlers already put the id in the session (`->with('data', $poid)`), so they need no
 * change; they gain only the store predicate.
 *
 * AND `order-track` requires the order number AND the email the order was placed with -- the form
 * already marks both `required` -- compared case-insensitively against `order_billing_details`.
 *
 * WHAT THIS DOES NOT CLOSE, STATED PLAINLY (P1-ORD-09, BLOCKED on an owner decision)
 * ---------------------------------------------------------------------------------------------
 * The `/{slug}/order/<Crypt::encrypt(id)>` details link is still forgeable by anyone holding the
 * leaked APP_KEY, because that ciphertext is the only credential the emailed link has and the link
 * must keep working from another device -- so it cannot be session-bound. Closing it needs a
 * different credential (a signed, expiring, order-bound token) AND the APP_KEY rotation (#43). That
 * is a change to what every customer's confirmation email contains, so it is not made here.
 *
 * ORACLES ARE RAW `DB::table()` ROWS AND RENDERED BODIES. The leak IS what is rendered, so the
 * assertions are made on output, with a positive marker found first wherever absence is claimed.
 *
 * ONE REQUEST PER TEST, AND THAT IS NOT STYLE. The test client reuses a single in-memory session
 * Store for every request in a test, and `Store::loadSession()` does `array_replace($this->attributes,
 * <handler data>)` -- it never clears. A second request therefore silently inherits the first
 * request's session, so a "stranger" is not session-less unless `flushSession()` is called. That
 * produced a false failure while writing the producer test in UnguardedOrderWriterTrustBoundaryTest.
 */
class StorefrontOrderCompletionDisclosureTest extends SecurityTestCase
{
    use RefreshDatabase;

    // =========================================================================================
    //  FIXTURES
    // =========================================================================================

    private function placedOrder(Store $store, string $marker, array $overrides = [], ?string $email = null): Order
    {
        $order = $this->makeOrder($store, array_merge([
            'product_order_id' => $marker,
            'customer_id' => 0,
            'is_guest' => 1,
            'product_json' => json_encode([
                ['product_id' => 5, 'name' => 'Line' . $marker, 'variant_id' => 0, 'qty' => 1, 'final_price' => 100],
            ]),
        ], $overrides));

        DB::table('order_billing_details')->insert([
            'order_id' => $order->id,
            'product_order_id' => $order->product_order_id,
            'first_name' => 'First' . $marker,
            'last_name' => 'Last',
            'email' => $email ?? strtolower($marker) . '@buyer.test',
            'telephone' => '555' . substr(preg_replace('/\D/', '', $marker) . '0000000', 0, 7),
            'address' => 'Street ' . $marker,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $order;
    }

    private function complete(Store $store, array $query = [], array $session = [])
    {
        $request = $session === [] ? $this : $this->withSession($session);

        return $request->get('/' . $store->slug . '/order-complete' . ($query ? '?' . http_build_query($query) : ''));
    }

    private function track(Store $store, array $body)
    {
        return $this->post('/' . $store->slug . '/order-track', $body);
    }

    // =========================================================================================
    //  PREMISES
    // =========================================================================================

    public function test_premise_both_routes_are_anonymous_by_design(): void
    {
        foreach (['order.complete', 'order.track'] as $name) {
            $this->assertTrue(Route::has($name), "Route {$name} is missing.");

            $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();

            foreach (['auth', 'auth:customers', 'custom.auth', \App\Http\Middleware\EnsureCustomerAuthenticated::class] as $gate) {
                $this->assertNotContains($gate, $middleware,
                    "{$name} now carries {$gate}. These are the post-payment landing page and the guest order "
                    . 'lookup: gating them on a login breaks every guest checkout. The proof of ownership has to '
                    . 'be something a guest can hold -- the session that placed the order, or the email on it.');
            }
        }
    }

    // =========================================================================================
    //  order-complete
    // =========================================================================================

    /** POSITIVE CONTROL for the whole gateway family: the server-set session flash. */
    public function test_a_gateway_return_renders_the_order_the_session_names(): void
    {
        [, $store] = $this->makeTenant();
        $order = $this->placedOrder($store, 'GATEWAY7001');

        $response = $this->complete($store, [], ['data' => $order->product_order_id]);

        $response->assertOk();
        $this->assertStringContainsString('GATEWAY7001', (string) $response->getContent(),
            'The legitimate gateway return no longer renders the order. Every payment redirects here.');
    }

    public function test_the_khalti_session_value_renders_the_order_it_names(): void
    {
        [, $store] = $this->makeTenant();
        $order = $this->placedOrder($store, 'KHALTI7002');

        $response = $this->complete($store, [], ['khalti_order_id' => $order->product_order_id]);

        $response->assertOk();
        $this->assertStringContainsString('KHALTI7002', (string) $response->getContent());
    }

    /**
     * `product_order_id` is a bare timestamp, so two stores can hold the SAME value in the same
     * second. The session paths looked it up with no store predicate and returned whichever row the
     * database produced first.
     */
    public function test_a_session_order_number_belonging_to_another_store_is_not_rendered(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->placedOrder($storeA, 'LOCALORDER7003');
        $foreign = $this->placedOrder($storeB, 'FOREIGNORDER7004');

        $response = $this->complete($storeA, [], ['data' => $foreign->product_order_id]);

        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertStringNotContainsString('FOREIGNORDER7004', (string) $response->getContent(),
            'A session order number resolved to another store\'s order.');
        $response->assertRedirect();
    }

    /** The Khalti branch had the same unscoped `product_order_id` lookup as the flashed one. */
    public function test_a_khalti_session_order_number_belonging_to_another_store_is_not_rendered(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->placedOrder($storeA, 'LOCALKHALTI7017');
        $foreign = $this->placedOrder($storeB, 'FOREIGNKHALTI7018');

        $response = $this->complete($storeA, [], ['khalti_order_id' => $foreign->product_order_id]);

        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertStringNotContainsString('FOREIGNKHALTI7018', (string) $response->getContent(),
            'A Khalti session order number resolved to another store\'s order.');
        $response->assertRedirect();
    }

    /** THE REPRODUCED DEFECT: no session, another tenant's primary key in `data[order_id]`. */
    public function test_an_anonymous_caller_cannot_read_another_tenants_order_through_the_request_id(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $victim = $this->placedOrder($storeB, 'VICTIMORDER7005');

        $response = $this->complete($storeA, ['data' => ['order_id' => $victim->id]]);

        $this->assertNotSame(500, $response->getStatusCode(), 'The page crashed, which is not a refusal.');
        $this->assertStringNotContainsString('VICTIMORDER7005', (string) $response->getContent(),
            'An anonymous caller read another tenant\'s order number.');
        $this->assertStringNotContainsString('/order/eyJ', (string) $response->getContent(),
            'The page minted an order-details link for an order the caller never placed.');
        $response->assertRedirect();
    }

    /**
     * THE HALF A STORE PREDICATE CANNOT CLOSE: the victim's OWN slug. This is what an attacker
     * actually does, because the slug is public and the order's store then matches it.
     */
    public function test_an_anonymous_caller_cannot_enumerate_a_stores_orders_through_the_request_id(): void
    {
        [, $store] = $this->makeTenant();
        $victim = $this->placedOrder($store, 'SAMESTORE7006');

        $response = $this->complete($store, ['data' => ['order_id' => $victim->id]]);

        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertStringNotContainsString('SAMESTORE7006', (string) $response->getContent(),
            'A sequential primary key typed into the URL disclosed an order to a visitor who never placed '
            . 'it. A store predicate cannot stop this -- the slug is public and the order\'s store matches it.');
        $this->assertStringNotContainsString('/order/eyJ', (string) $response->getContent());
        $response->assertRedirect();
    }

    /** The branch with no legitimate producer anywhere in resources/, themes/ or public/. */
    public function test_the_responce_branch_discloses_nothing_to_anyone(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $own = $this->placedOrder($storeA, 'RESPOWN7007');
        $foreign = $this->placedOrder($storeB, 'RESPFOREIGN7008');

        foreach ([$own, $foreign] as $order) {
            $response = $this->complete($storeA, ['responce' => ['data' => ['order_id' => $order->id]]]);

            $this->assertNotSame(500, $response->getStatusCode());
            $this->assertStringNotContainsString($order->product_order_id, (string) $response->getContent(),
                '`responce[data][order_id]` disclosed order ' . $order->product_order_id . '.');
            $response->assertRedirect();
        }
    }

    // -- THE SESSION BINDING ------------------------------------------------------------------

    /** POSITIVE CONTROL: the WhatsApp/Telegram redirect, once the session carries the id. */
    public function test_the_whatsapp_redirect_renders_the_order_this_session_placed(): void
    {
        [, $store] = $this->makeTenant();
        $order = $this->placedOrder($store, 'WHATSAPP7009');

        $response = $this->complete($store, ['data' => ['order_id' => $order->id]], ['order_complete_ids' => [$order->id]]);

        $response->assertOk();
        $this->assertStringContainsString('WHATSAPP7009', (string) $response->getContent(),
            'The legitimate WhatsApp/Telegram thank-you page no longer renders the buyer\'s order.');
    }

    /** The binding is per ID, not a blanket pass: a session that placed ONE order may read only that one. */
    public function test_a_session_bound_to_one_order_cannot_read_another_in_the_same_store(): void
    {
        [, $store] = $this->makeTenant();
        $mine = $this->placedOrder($store, 'MINE7010');
        $other = $this->placedOrder($store, 'OTHERBUYER7011');

        $response = $this->complete($store, ['data' => ['order_id' => $other->id]], ['order_complete_ids' => [$mine->id]]);

        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertStringNotContainsString('OTHERBUYER7011', (string) $response->getContent(),
            'Holding a binding for one order unlocked every order in the store.');
        $response->assertRedirect();
    }

    public function test_a_session_binding_does_not_unlock_another_stores_order_either(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $mine = $this->placedOrder($storeA, 'MINE7012');
        $foreign = $this->placedOrder($storeB, 'FOREIGNBOUND7013');

        // The id list is attacker-influenced only via THEIR OWN session; but the store predicate must
        // still hold, because a stale binding survives a change of storefront in the same browser.
        $response = $this->complete($storeA, ['data' => ['order_id' => $foreign->id]],
            ['order_complete_ids' => [$mine->id, $foreign->id]]);

        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertStringNotContainsString('FOREIGNBOUND7013', (string) $response->getContent());
    }

    /**
     * PRECEDENCE: a request id outranks Khalti, as it did before. A buyer with a stale
     * `khalti_order_id` who then checks out through WhatsApp must be shown the order they just placed.
     */
    public function test_a_stale_khalti_session_does_not_hide_the_whatsapp_order_just_placed(): void
    {
        [, $store] = $this->makeTenant();
        $stale = $this->placedOrder($store, 'STALEKHALTI7015');
        $fresh = $this->placedOrder($store, 'FRESHWHATSAPP7016');

        $response = $this->complete($store, ['data' => ['order_id' => $fresh->id]], [
            'khalti_order_id' => $stale->product_order_id,
            'order_complete_ids' => [$fresh->id],
        ]);

        $response->assertOk();
        $this->assertStringContainsString('FRESHWHATSAPP7016', (string) $response->getContent(),
            'The order the buyer just placed was replaced by a stale Khalti one.');
        $this->assertStringNotContainsString('STALEKHALTI7015', (string) $response->getContent());
    }

    /** A non-list value in the session must not become a crash or a pass. */
    public function test_a_malformed_session_binding_is_ignored_rather_than_trusted(): void
    {
        [, $store] = $this->makeTenant();
        $order = $this->placedOrder($store, 'MALFORMED7014');

        // `[true]` is the one that matters: filter_var(true, FILTER_VALIDATE_INT) is 1, and the first
        // order in a fresh database IS id 1, so a sanitiser that lets booleans through would unlock it.
        foreach (['', 'all', true, 0, ['*'], [null], [true], [[$order->id]]] as $junk) {
            $response = $this->complete($store, ['data' => ['order_id' => $order->id]], ['order_complete_ids' => $junk]);

            $this->assertNotSame(500, $response->getStatusCode(), 'A malformed binding crashed the page: ' . json_encode($junk));
            $this->assertStringNotContainsString('MALFORMED7014', (string) $response->getContent(),
                'A malformed session binding was treated as authorisation: ' . json_encode($junk));
        }
    }

    // =========================================================================================
    //  order-track
    // =========================================================================================

    /** POSITIVE CONTROL: the shipped journey -- the number AND the email the order was placed with. */
    public function test_tracking_with_the_number_and_the_matching_email_still_works(): void
    {
        [, $store] = $this->makeTenant();
        $order = $this->placedOrder($store, 'TRACKME8001', [], 'Buyer.Mixed@Example.TEST');

        // Case and surrounding whitespace differ from what is stored: a shopper retyping their own
        // address must not be locked out by a capital letter.
        $response = $this->track($store, ['order_number' => $order->product_order_id, 'email' => '  buyer.mixed@example.test ']);

        $response->assertOk();
        $this->assertStringContainsString('TRACKME8001', (string) $response->getContent(),
            'A shopper who supplied the right number and the right email can no longer track their order.');
    }

    /** THE REPRODUCED DEFECT: the email was compared to nothing. */
    public function test_a_wrong_email_does_not_disclose_the_order(): void
    {
        [, $store] = $this->makeTenant();
        $order = $this->placedOrder($store, 'TRACKVICTIM8002');

        $response = $this->track($store, ['order_number' => $order->product_order_id, 'email' => 'attacker@evil.test']);

        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertStringNotContainsString('TRACKVICTIM8002', (string) $response->getContent(),
            'The order number alone disclosed the order -- the submitted email is compared to nothing.');
        $this->assertStringNotContainsString('trackvictim8002@buyer.test', (string) $response->getContent());
        $this->assertStringNotContainsString('FirstTRACKVICTIM8002', (string) $response->getContent());
        $this->assertStringNotContainsString('/order/eyJ', (string) $response->getContent(),
            'A minted order-details link was handed to a visitor who did not know the email.');
    }

    public function test_the_order_number_alone_does_not_disclose_the_order(): void
    {
        [, $store] = $this->makeTenant();
        $order = $this->placedOrder($store, 'NOEMAIL8003');

        $response = $this->track($store, ['order_number' => $order->product_order_id]);

        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertStringNotContainsString('NOEMAIL8003', (string) $response->getContent());
    }

    /**
     * `where('product_order_id', $email_only_request->order_number)` compiled to `IS NULL` when the
     * number was absent, so an email-only POST disclosed the first order whose number was NULL.
     */
    public function test_an_email_alone_does_not_disclose_an_order_with_no_number(): void
    {
        [, $store] = $this->makeTenant();
        $order = $this->placedOrder($store, 'NULLNUMBER8004', ['product_order_id' => null], 'null@buyer.test');

        $this->assertNull(DB::table('orders')->where('id', $order->id)->value('product_order_id'),
            'Fixture premise: the order\'s number must really be NULL.');

        $response = $this->track($store, ['email' => 'null@buyer.test']);

        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertStringNotContainsString('null@buyer.test', (string) $response->getContent(),
            'An email-only lookup disclosed an order whose product_order_id is NULL.');
        $this->assertStringNotContainsString('FirstNULLNUMBER8004', (string) $response->getContent());
    }

    /** An order with no recorded email must not become trackable by posting an empty one: '' === ''. */
    public function test_an_order_with_no_recorded_email_cannot_be_tracked_with_an_empty_one(): void
    {
        [, $store] = $this->makeTenant();
        $order = $this->placedOrder($store, 'BLANKMAIL8005');
        DB::table('order_billing_details')->where('order_id', $order->id)->update(['email' => null]);

        foreach (['', '   ', null] as $blank) {
            $response = $this->track($store, ['order_number' => $order->product_order_id, 'email' => $blank]);

            $this->assertNotSame(500, $response->getStatusCode());
            $this->assertStringNotContainsString('BLANKMAIL8005', (string) $response->getContent(),
                'Two empty emails compared equal and opened the order. Posted: ' . json_encode($blank));
        }
    }

    /**
     * The RECORDED email's trim is not redundant -- nothing trims a database value. Billing emails
     * written before TrimStrings, or by an API client, can carry stray whitespace, and a buyer who
     * retypes their address correctly must still find their order.
     */
    public function test_a_recorded_email_with_stray_whitespace_still_matches(): void
    {
        [, $store] = $this->makeTenant();
        $order = $this->placedOrder($store, 'PADDED8008', [], "  Padded.Buyer@Example.TEST \t");

        $response = $this->track($store, ['order_number' => $order->product_order_id, 'email' => 'padded.buyer@example.test']);

        $response->assertOk();
        $this->assertStringContainsString('PADDED8008', (string) $response->getContent(),
            'A buyer was locked out of their own order because the stored email had stray whitespace.');
    }

    public function test_tracking_does_not_cross_tenants_even_with_the_victims_real_email(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $foreign = $this->placedOrder($storeB, 'CROSSTRACK8006');

        $response = $this->track($storeA, ['order_number' => $foreign->product_order_id, 'email' => 'crosstrack8006@buyer.test']);

        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertStringNotContainsString('CROSSTRACK8006', (string) $response->getContent());
    }

    /** A wrong email must not be a distinguishable answer from an unknown number -- no oracle for either. */
    public function test_a_wrong_email_is_indistinguishable_from_an_unknown_order_number(): void
    {
        [, $store] = $this->makeTenant();
        $order = $this->placedOrder($store, 'ORACLE8007');

        $wrongEmail = $this->track($store, ['order_number' => $order->product_order_id, 'email' => 'x@y.test']);
        $unknownNumber = $this->track($store, ['order_number' => 'DOESNOTEXIST', 'email' => 'x@y.test']);

        $this->assertSame($unknownNumber->getStatusCode(), $wrongEmail->getStatusCode());
        $this->assertStringNotContainsString('ORACLE8007', (string) $wrongEmail->getContent());

        // Same view, same length once the per-request CSRF token is removed.
        $strip = fn ($r) => preg_replace('/name="_token" value="[^"]+"/', '', (string) $r->getContent());
        $this->assertSame(strlen($strip($unknownNumber)), strlen($strip($wrongEmail)),
            'A wrong email renders a different page from an unknown order number: the number\'s existence leaks.');
    }
}
