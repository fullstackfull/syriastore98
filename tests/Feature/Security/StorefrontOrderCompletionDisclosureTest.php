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

    /**
     * M-8 (review): the SAME number in two stores must resolve to the store the request names.
     * The other store tests prove a foreign number is not rendered; none drove the actual
     * collision, where the right answer is the OTHER order that shares the number.
     */
    public function test_a_number_held_by_two_stores_resolves_to_the_store_the_request_names(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $a = $this->placedOrder($storeA, 'SHAREDNUM9101');
        $b = $this->placedOrder($storeB, 'SHAREDNUM9101');
        DB::table('order_billing_details')->where('order_id', $a->id)->update(['first_name' => 'ALPHABUYER']);
        DB::table('order_billing_details')->where('order_id', $b->id)->update(['first_name' => 'BETABUYER']);

        $this->assertSame($a->product_order_id, $b->product_order_id, 'Fixture premise: the two numbers must collide.');

        $response = $this->complete($storeB, [], ['data' => $b->product_order_id]);

        $response->assertOk();
        $this->assertStringContainsString('SHAREDNUM9101', (string) $response->getContent());
        $this->assertSame((int) $b->id, (int) $response->viewData('order')->id,
            'The thank-you page for store B rendered a different order sharing its number.');
    }

    /**
     * F1 (review lens B). `product_order_id` is not unique -- a guest order is `'0' . date('YmdHis')`
     * and the column has no index -- so two orders placed in one store in one second share it. The
     * flashed and Khalti lookups took `first()`, i.e. the LOWEST id: a visitor who placed their own
     * order a second after the victim's was shown the victim's name, phone, address and a minted
     * link to the full order, with no proof of ownership. An ambiguous number is refused, for the
     * buyer too -- a false denial in a rare collision is the price of never guessing between two
     * people's orders; P1-ORD-13 records the real fix (carry the primary key in the flash).
     */
    public function test_an_ambiguous_session_order_number_in_one_store_renders_neither_order(): void
    {
        [, $store] = $this->makeTenant();
        $first = $this->placedOrder($store, 'COLLIDE9102', [], 'victim@buyer.test');
        $second = $this->placedOrder($store, 'COLLIDE9102', [], 'attacker@buyer.test');
        DB::table('order_billing_details')->where('order_id', $first->id)->update(['first_name' => 'VICTIMNAME']);

        $this->assertSame($first->product_order_id, $second->product_order_id, 'Fixture premise: the two numbers must collide.');

        $response = $this->complete($store, [], ['data' => $second->product_order_id]);

        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertStringNotContainsString('VICTIMNAME', (string) $response->getContent());
        $this->assertStringNotContainsString('/order/eyJ', (string) $response->getContent(),
            'A minted details link for the LOWEST-id order sharing the number was handed to a visitor who placed the other.');
        $response->assertRedirect();
    }

    public function test_an_ambiguous_khalti_session_order_number_in_one_store_renders_neither_order(): void
    {
        [, $store] = $this->makeTenant();
        $first = $this->placedOrder($store, 'COLLIDEK9103', [], 'victim@buyer.test');
        $second = $this->placedOrder($store, 'COLLIDEK9103', [], 'attacker@buyer.test');

        $response = $this->complete($store, [], ['khalti_order_id' => $second->product_order_id]);

        $this->assertNotSame(500, $response->getStatusCode());
        $response->assertRedirect();
        $this->assertStringNotContainsString('/order/eyJ', (string) $response->getContent());
    }

    /**
     * M-9 (review): shapes of the request id. Only the plain integer of an id this session placed
     * is honoured; everything else is refused rather than coerced into a match.
     */
    public function test_odd_shapes_of_the_request_id_are_not_coerced_into_a_match(): void
    {
        [, $store] = $this->makeTenant();
        $order = $this->placedOrder($store, 'SHAPES9104');

        foreach (['abc', $order->id . 'abc', '0' . $order->id, $order->id . '.0', $order->id . 'e0', [$order->id], ['x' => $order->id], ''] as $shape) {
            $response = $this->complete($store, ['data' => ['order_id' => $shape]], ['order_complete_ids' => [$order->id]]);

            $this->assertNotSame(500, $response->getStatusCode(), 'order_id ' . json_encode($shape) . ' crashed the page.');
            $this->assertStringNotContainsString('SHAPES9104', (string) $response->getContent(),
                'order_id ' . json_encode($shape) . ' was coerced into a match for order ' . $order->id . '.');
        }
    }

    /**
     * WHICH ORDER DID THE THANK-YOU PAGE ACTUALLY RENDER?
     *
     * The page prints the order NUMBER and the minted `/order/<encrypted id>` link, and nothing else
     * that tells two orders apart -- not the billing name, not the line names (the first two drafts
     * of the collision test asserted on each in turn and failed for that reason, not for the
     * defect). In a same-second collision the NUMBER is identical by construction, so the link is
     * the only observable identity, and its id is what this reads.
     *
     * Decrypting it in a test is sound: the id is the assertion's subject, not its secret.
     * APP_KEY's exposure is precisely why the id in that link cannot be the ownership proof --
     * which is what OrderCompleteBinding exists to replace.
     */
    private function renderedOrderId(string $body): ?int
    {
        if (preg_match('#/order/([A-Za-z0-9+/=]+)#', $body, $m) !== 1) {
            return null;
        }

        try {
            return (int) \Illuminate\Support\Facades\Crypt::decrypt($m[1]);
        } catch (\Throwable $e) {
            return null;
        }
    }

    // =========================================================================================
    //  P1-ORD-13: the same-second collision, resolved for BOTH buyers rather than refused
    // =========================================================================================

    /**
     * THE CASE P1-ORD-08 COULD ONLY FAIL CLOSED ON, and the reason this task exists.
     *
     * `product_order_id` is `'0' . date('YmdHis')` for a guest, with no index and no uniqueness, so
     * two orders placed in one store in one second carry the SAME number. Resolving the flashed
     * NUMBER had two possible answers and both were wrong: `first()` -- the lowest id -- showed one
     * buyer the OTHER'S order and a minted link to it (measured, review lens E, P8), and
     * P1-ORD-08's refusal sent BOTH buyers back to the landing page having just paid.
     *
     * Every gateway return now records the order's PRIMARY KEY against the session that placed it,
     * so each buyer's own session resolves the collision to their own order.
     */
    public function test_each_buyer_in_a_same_second_collision_sees_their_own_order(): void
    {
        [, $store] = $this->makeTenant();

        /*
         * The two orders share the NUMBER and differ in a LINE NAME, because the line name is what
         * the thank-you page actually prints. The first draft of this test asserted on the billing
         * first_name and failed for that reason: the view never renders it, so the positive
         * assertion could not be satisfied by any correct behaviour. (The negative assertions in
         * the P1-ORD-08 tests above are on that same field, which is why they pass either way --
         * worth knowing before trusting one of them on its own.)
         */
        $first = $this->placedOrder($store, 'COLLIDE13A', [
            'product_json' => json_encode([['product_id' => 5, 'name' => 'FIRSTBUYERLINE', 'variant_id' => 0, 'qty' => 1, 'final_price' => 100]]),
        ], 'first@buyer.test');
        $second = $this->placedOrder($store, 'COLLIDE13A', [
            'product_json' => json_encode([['product_id' => 5, 'name' => 'SECONDBUYERLINE', 'variant_id' => 0, 'qty' => 1, 'final_price' => 100]]),
        ], 'second@buyer.test');

        $this->assertSame($first->product_order_id, $second->product_order_id, 'Fixture premise: the numbers must collide.');

        // The FIRST buyer's session: the flashed number plus the id their gateway return bound.
        $response = $this->complete($store, [], [
            'data' => $first->product_order_id,
            \App\Services\OrderCompleteBinding::SESSION_KEY => [$first->id],
        ]);

        $response->assertOk();
        $this->assertSame((int) $first->id, $this->renderedOrderId((string) $response->getContent()),
            'The first buyer was not shown their OWN order: the collision resolved to the wrong one.');

        // And the SECOND buyer -- the one the old lowest-id lookup could never serve.
        $response = $this->complete($store, [], [
            'data' => $second->product_order_id,
            \App\Services\OrderCompleteBinding::SESSION_KEY => [$second->id],
        ]);

        $response->assertOk();
        $this->assertSame((int) $second->id, $this->renderedOrderId((string) $response->getContent()),
            'The second buyer -- the one the old lowest-id lookup could never serve -- was shown the '
            . 'wrong order or refused their own.');
    }

    /**
     * KHALTI, the one provider the first cut left on the double refusal.
     *
     * Khalti does not redirect: its handler returns JSON that the blade navigates with, and it puts
     * the order NUMBER in `khalti_order_id` (KhaltiPaymnetController). So the thank-you page's khalti
     * limb resolved that number through soleStoreOrderByNumber() -- which, in a same-second
     * collision, correctly refuses, and therefore sent BOTH Khalti buyers back to the landing page
     * having just paid. Found by the tenant-authz review of the first cut: every other producer had
     * been given a binding and this one had not. It now records the primary key as well, and the
     * khalti limb prefers it exactly as the flashed limb does.
     */
    public function test_each_khalti_buyer_in_a_same_second_collision_sees_their_own_order(): void
    {
        [, $store] = $this->makeTenant();

        $first = $this->placedOrder($store, 'KHALTI13A', [], 'khalti-first@buyer.test');
        $second = $this->placedOrder($store, 'KHALTI13A', [], 'khalti-second@buyer.test');

        $this->assertSame($first->product_order_id, $second->product_order_id, 'Fixture premise: the numbers must collide.');

        foreach ([$first, $second] as $buyer) {
            $response = $this->complete($store, [], [
                'khalti_order_id' => $buyer->product_order_id,
                \App\Services\OrderCompleteBinding::SESSION_KEY => [$buyer->id],
            ]);

            $response->assertOk();
            $this->assertSame((int) $buyer->id, $this->renderedOrderId((string) $response->getContent()),
                'A Khalti buyer in a same-second collision was refused their own order, or shown the other buyer\'s.');
        }
    }

    /**
     * And the number lookup is still the fallback, so a Khalti session placed before the binding
     * existed is served -- an unambiguous number resolves with no binding at all.
     */
    public function test_an_unambiguous_khalti_number_still_resolves_without_a_binding(): void
    {
        [, $store] = $this->makeTenant();
        $order = $this->placedOrder($store, 'KHALTI13B', [], 'khalti-legacy@buyer.test');

        $response = $this->complete($store, [], ['khalti_order_id' => $order->product_order_id]);

        $response->assertOk();
        $this->assertSame((int) $order->id, $this->renderedOrderId((string) $response->getContent()));
    }

    /** A Khalti binding for ANOTHER store's order does not unlock this store's colliding number. */
    public function test_a_khalti_binding_from_another_store_does_not_resolve_a_collision_here(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $mine = $this->placedOrder($storeA, 'KHALTI13C', [], 'ka@buyer.test');
        $theirs = $this->placedOrder($storeA, 'KHALTI13C', [], 'kb@buyer.test');
        $elsewhere = $this->placedOrder($storeB, 'KHALTI13C', [], 'kc@buyer.test');

        $response = $this->complete($storeA, [], [
            'khalti_order_id' => $mine->product_order_id,
            \App\Services\OrderCompleteBinding::SESSION_KEY => [$elsewhere->id],
        ]);

        $response->assertRedirect();
        $this->assertNotSame((int) $mine->id, $this->renderedOrderId((string) $response->getContent()));
        $this->assertNotSame((int) $theirs->id, $this->renderedOrderId((string) $response->getContent()));
    }

    /** A binding for ANOTHER store's order does not unlock this store's colliding number. */
    public function test_a_binding_from_another_store_does_not_resolve_a_collision_here(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $mine = $this->placedOrder($storeA, 'COLLIDE13B', [
            'product_json' => json_encode([['product_id' => 5, 'name' => 'STOREAONELINE', 'variant_id' => 0, 'qty' => 1, 'final_price' => 100]]),
        ], 'a@buyer.test');
        $theirs = $this->placedOrder($storeA, 'COLLIDE13B', [
            'product_json' => json_encode([['product_id' => 5, 'name' => 'STOREATWOLINE', 'variant_id' => 0, 'qty' => 1, 'final_price' => 100]]),
        ], 'b@buyer.test');

        $elsewhere = $this->placedOrder($storeB, 'ELSEWHERE13B', [], 'c@buyer.test');

        $response = $this->complete($storeA, [], [
            'data' => $mine->product_order_id,
            \App\Services\OrderCompleteBinding::SESSION_KEY => [$elsewhere->id],
        ]);

        // The binding names another store's order, so it cannot pick between store A's two; the
        // ambiguous-number fallback then refuses, exactly as P1-ORD-08 made it.
        $this->assertNull($this->renderedOrderId((string) $response->getContent()),
            "A binding naming another store's order resolved store A's ambiguous number.");
        $response->assertRedirect();
    }

    /**
     * THE STORE PREDICATE ON THE BOUND-ID LOOKUP, and the only input that isolates it.
     *
     * A mutant removing it SURVIVED the cross-store test above, because there the bound order's
     * NUMBER differed, so the match failed anyway. The separating input is a binding whose order is
     * in ANOTHER store and carries the SAME number -- which is the normal case, since
     * `'0' . date('YmdHis')` is generated per store and two stores collide in any shared second.
     * Without the predicate, store B's order renders under store A's slug.
     */
    public function test_a_bound_order_in_another_store_sharing_the_number_is_not_rendered_here(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $hereOnly = $this->placedOrder($storeA, 'SHARED13E', [], 'a@buyer.test');
        $theirs = $this->placedOrder($storeB, 'SHARED13E', [], 'b@buyer.test');

        $this->assertSame($hereOnly->product_order_id, $theirs->product_order_id, 'Fixture premise: two stores, one number.');

        // The session placed store B's order; the request addresses store A with the shared number.
        $response = $this->complete($storeA, [], [
            'data' => $theirs->product_order_id,
            \App\Services\OrderCompleteBinding::SESSION_KEY => [$theirs->id],
        ]);

        $rendered = $this->renderedOrderId((string) $response->getContent());

        $this->assertNotSame((int) $theirs->id, $rendered,
            "Store B's order was rendered under store A's slug: the bound-id lookup is not scoped to "
            . 'the store the request names.');
        // Store A's own order is unambiguous here, so it is the correct answer for this slug.
        $this->assertSame((int) $hereOnly->id, $rendered);
    }

    /** With no binding at all, an ambiguous number still refuses -- the fallback is unchanged. */
    public function test_without_a_binding_an_ambiguous_number_still_refuses(): void
    {
        [, $store] = $this->makeTenant();
        $first = $this->placedOrder($store, 'COLLIDE13C', [
            'product_json' => json_encode([['product_id' => 5, 'name' => 'NOBINDINGONELINE', 'variant_id' => 0, 'qty' => 1, 'final_price' => 100]]),
        ], 'x@buyer.test');
        $this->placedOrder($store, 'COLLIDE13C', [], 'y@buyer.test');

        $response = $this->complete($store, [], ['data' => $first->product_order_id]);

        $this->assertNull($this->renderedOrderId((string) $response->getContent()),
            'With no binding, an ambiguous number must still refuse rather than guess.');
        $response->assertRedirect();
    }

    /** And a session binding alone, with nothing flashed and nothing asked, serves the buyer. */
    public function test_a_bound_session_with_nothing_flashed_sees_its_most_recent_order(): void
    {
        [, $store] = $this->makeTenant();
        $order = $this->placedOrder($store, 'BOUNDONLY13D');

        $response = $this->complete($store, [], [
            \App\Services\OrderCompleteBinding::SESSION_KEY => [$order->id],
        ]);

        $response->assertOk();
        $this->assertSame((int) $order->id, $this->renderedOrderId((string) $response->getContent()));
    }

    /** The sanitiser accepts an id stored as a numeric STRING, as a session driver round-trip may produce. */
    public function test_a_session_binding_stored_as_numeric_strings_still_works(): void
    {
        [, $store] = $this->makeTenant();
        $order = $this->placedOrder($store, 'STRINGID9105');

        $response = $this->complete($store, ['data' => ['order_id' => $order->id]], ['order_complete_ids' => [(string) $order->id]]);

        $response->assertOk();
        $this->assertStringContainsString('STRINGID9105', (string) $response->getContent());
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

    /**
     * D-5 (review): the case a real buyer hits. The record is lowercase and they type capitals.
     * Both earlier positive controls posted a LOWERCASE address against a MIXED-case record, so
     * removing the lowercase of the POSTED side left them green.
     */
    public function test_a_posted_email_in_capitals_matches_a_lowercase_record(): void
    {
        [, $store] = $this->makeTenant();
        $order = $this->placedOrder($store, 'UPPERPOST9201', [], 'buyer@example.test');

        $response = $this->track($store, ['order_number' => $order->product_order_id, 'email' => 'BUYER@EXAMPLE.TEST']);

        $response->assertOk();
        $this->assertStringContainsString('UPPERPOST9201', (string) $response->getContent(),
            'A buyer who types their address in capitals was locked out of their own order.');
    }

    /**
     * F1 on the tracking page. Two orders in one store share a number; the email is what tells them
     * apart. `first()` took the lowest id and then compared the email to THAT order only, so the
     * second buyer was told their order does not exist. The scan matches the email across every
     * order sharing the number.
     */
    public function test_tracking_picks_the_order_whose_email_matches_among_orders_sharing_a_number(): void
    {
        [, $store] = $this->makeTenant();
        $a = $this->placedOrder($store, 'SAMETRACK9202', [], 'alpha@buyer.test');
        $b = $this->placedOrder($store, 'SAMETRACK9202', [], 'beta@buyer.test');
        DB::table('order_billing_details')->where('order_id', $a->id)->update(['first_name' => 'ALPHABUYER']);
        DB::table('order_billing_details')->where('order_id', $b->id)->update(['first_name' => 'BETABUYER']);

        $this->assertSame($a->product_order_id, $b->product_order_id, 'Fixture premise: the two numbers must collide.');

        $asBeta = $this->track($store, ['order_number' => $b->product_order_id, 'email' => 'beta@buyer.test']);
        $asBeta->assertOk();
        $this->assertStringContainsString('BETABUYER', (string) $asBeta->getContent(),
            'The second buyer was told their order does not exist because the first row sharing the number was compared.');
        $this->assertStringNotContainsString('ALPHABUYER', (string) $asBeta->getContent());

        $this->flushSession();

        $asAlpha = $this->track($store, ['order_number' => $a->product_order_id, 'email' => 'alpha@buyer.test']);
        $this->assertStringContainsString('ALPHABUYER', (string) $asAlpha->getContent());
        $this->assertStringNotContainsString('BETABUYER', (string) $asAlpha->getContent());
    }

    public function test_array_shaped_tracking_fields_are_a_blank_lookup_not_a_crash(): void
    {
        [, $store] = $this->makeTenant();
        $order = $this->placedOrder($store, 'ARRAYTRACK9203');

        foreach ([
            ['order_number' => [$order->product_order_id], 'email' => 'arraytrack9203@buyer.test'],
            ['order_number' => $order->product_order_id, 'email' => ['arraytrack9203@buyer.test']],
        ] as $body) {
            $response = $this->track($store, $body);

            $this->assertNotSame(500, $response->getStatusCode());
            $this->assertStringNotContainsString('ARRAYTRACK9203', (string) $response->getContent());
        }
    }

    /**
     * F3 (review lens B). The number is a timestamp, so the EMAIL is the only secret -- and nothing
     * limited how many numbers could be tried against a known address. The route now carries the
     * project's `auth` limiter (10/min per IP + submitted email), whose key is the same `email`
     * field this form posts.
     */
    public function test_order_tracking_is_rate_limited_per_address(): void
    {
        $middleware = Route::getRoutes()->getByName('order.track')->gatherMiddleware();
        $this->assertContains('throttle:auth', $middleware, 'order.track lost its rate limit.');

        [, $store] = $this->makeTenant();
        $order = $this->placedOrder($store, 'LIMITED9204', [], 'limited@buyer.test');

        for ($i = 1; $i <= 10; $i++) {
            $this->assertNotSame(429, $this->track($store, ['order_number' => 'GUESS' . $i, 'email' => 'limited@buyer.test'])->getStatusCode(),
                "Attempt {$i} was throttled before the limit.");
        }

        $eleventh = $this->track($store, ['order_number' => $order->product_order_id, 'email' => 'limited@buyer.test']);

        $this->assertSame(429, $eleventh->getStatusCode(), 'The 11th attempt within a minute for one address was not throttled.');
        $this->assertStringNotContainsString('LIMITED9204', (string) $eleventh->getContent());
    }

    /**
     * F4 (review lens), the OTHER axis. `throttle:auth` keys on ip + the submitted EMAIL, so it bounds
     * one known address swept across numbers. An attacker who has seen an order NUMBER (a screenshot,
     * a receipt) and varies the email gets a fresh bucket per guess. `order-track` keys on ip + store
     * + number, so the guesses against one number share a bucket.
     */
    public function test_guessing_emails_against_one_order_number_is_rate_limited(): void
    {
        $middleware = Route::getRoutes()->getByName('order.track')->gatherMiddleware();
        $this->assertContains('throttle:order-track', $middleware, 'order.track lost its per-number rate limit.');

        [, $store] = $this->makeTenant();
        $order = $this->placedOrder($store, 'SPRAY9205', [], 'spray@buyer.test');

        for ($i = 1; $i <= 10; $i++) {
            $this->assertNotSame(429, $this->track($store, ['order_number' => $order->product_order_id, 'email' => "guess{$i}@evil.test"])->getStatusCode(),
                "Attempt {$i} was throttled before the limit.");
        }

        $eleventh = $this->track($store, ['order_number' => $order->product_order_id, 'email' => 'spray@buyer.test']);

        $this->assertSame(429, $eleventh->getStatusCode(), 'An eleventh guess against one order number in a minute was not throttled.');
        $this->assertStringNotContainsString('SPRAY9205', (string) $eleventh->getContent());

        // The bucket is PER NUMBER: a different number from the same address, with an address not yet seen
        // by the `auth` limiter, is not caught up in it. (Without this a key that ignores the number
        // would pass every assertion above.)
        $other = $this->track($store, ['order_number' => 'SOMEOTHERNUMBER', 'email' => 'fresh-address@buyer.test']);
        $this->assertNotSame(429, $other->getStatusCode(), 'The limiter is not scoped to the order number.');
    }
}
