<?php

namespace Tests\Feature\Security;

use App\Models\AddOnManager;
use App\Models\Customer;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P1-INV-20 / #67 item 1 -- REPRODUCTION FIRST.
 *
 * `StoreLotteryPayment` writes a `purchase_tickets` row into ANY store, ANONYMOUSLY, with the
 * target store taken from the caller's own URL slug.
 *
 * Written against UNFIXED code and expected to FAIL.
 */
class LotteryTicketWriterTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function enableModule(): void
    {
        AddOnManager::create([
            'module' => 'LotteryPrizes',
            'name' => 'Lottery Prizes',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'is_enable' => 1,
            'package_name' => 'lottery-prizes',
        ]);

        Cache::flush();

        $provider = new \App\Providers\PackageServiceProvider($this->app);
        $loadAddons = new \ReflectionMethod($provider, 'loadAddons');
        $loadAddons->setAccessible(true);
        $loadAddons->invoke($provider, ['LotteryPrizes']);

        foreach (glob(base_path('packages/workdo/LotteryPrizes/src/database/migrations/*.php')) ?: [] as $file) {
            (require $file)->up();
        }

        foreach (['purchase_tickets', 'lottery_settings', 'lottery_customers'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Module table `{$table}` was not created.");
        }
    }

    /**
     * PREMISE TEST. If the route is not registered the probe below would 404 and "no row written"
     * would be vacuously true -- the exact failure mode CLAUDE.md names.
     */
    public function test_premise_the_route_is_registered_and_carries_no_auth(): void
    {
        $this->enableModule();

        $route = collect(app('router')->getRoutes())->first(
            fn ($r) => $r->getName() === 'store.lottery.payment'
        );

        $this->assertNotNull($route, 'store.lottery.payment is not registered, so every probe below would 404.');

        $middleware = $route->gatherMiddleware();
        fwrite(STDERR, "\nROUTE  uri=" . $route->uri() . "\n");
        fwrite(STDERR, 'MIDDLEWARE  ' . implode(', ', array_map('strval', $middleware)) . "\n");

        $this->assertNotContains('auth', $middleware,
            'If auth were already present the defect would not be reachable anonymously.');
    }

    public function test_an_anonymous_caller_cannot_write_a_ticket_into_a_store(): void
    {
        $this->enableModule();

        $victimOwner = $this->makeMerchant(['email' => 'victim@example.com']);
        $victimStore = $this->makeStore($victimOwner, ['slug' => 'victim-store']);

        $productId = (int) DB::table('products')->insertGetId([
            'name' => 'Victim Lottery Product',
            'slug' => 'victim-lottery-product',
            'store_id' => $victimStore->id,
            'created_by' => $victimOwner->id,
            'price' => 10,
            'track_stock' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $lotteryId = (int) DB::table('lottery_settings')->insertGetId([
            'store_id' => $victimStore->id,
            'lottery_products' => $productId,
            'created_by' => $victimOwner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $before = DB::table('purchase_tickets')->count();

        // NO AUTHENTICATION AT ALL. No bearer token, no session, no guard.
        $response = $this->get("/victim-store/store-lottery-payment?" . http_build_query([
            'ticket_price' => 1,
            'payment_methods' => 'telegram',
            'product_id' => $productId,
        ]));

        $after = DB::table('purchase_tickets')->count();
        $row = DB::table('purchase_tickets')->orderByDesc('id')->first();

        fwrite(STDERR, "\nSTATUS  " . $response->getStatusCode() . "\n");
        fwrite(STDERR, "TICKETS before={$before} after={$after}\n");
        if ($row) {
            fwrite(STDERR, 'ROW  store_id=' . $row->store_id
                . ' lottery_id=' . $row->lottery_id
                . ' customer_id=' . $row->customer_id
                . ' amount=' . $row->amount . "\n");
        }

        $this->assertSame($before, $after,
            'An anonymous caller wrote a purchase_tickets row into another tenant.');
    }
    /* ---------------------------------------------------------------------------------------- *
     *  ITEM 1 -- THE POSITIVE SIDE. A guard that refuses everyone is not a fix.
     * ---------------------------------------------------------------------------------------- */

    /**
     * THE LEGITIMATE FLOW STILL WRITES ITS TICKET.
     *
     * This is the test that decides whether the guard is acceptable, and it is shaped to match the
     * real path rather than to pass. `LotteryPaymentService` puts `lottery_request_data` into the
     * session on every one of its payment paths before handing off to the gateway -- **34** call
     * sites, counted with `grep -c`; an earlier version of this comment said 15, which was a
     * partial count of the file. The session is seeded here with the same shape
     * `lottery_form_data` produces.
     */
    public function test_a_caller_inside_the_real_flow_still_writes_the_ticket(): void
    {
        $this->enableModule();

        [, $store, $productId] = $this->makeLotteryStore('flow-store');
        $customer = $this->makeCustomerIn($store);

        $before = DB::table('purchase_tickets')->count();

        $response = $this->actingAs($customer, 'customers')
            ->withSession(['lottery_request_data' => $this->flowData($productId)])
            ->get("/flow-store/store-lottery-payment");

        $after = DB::table('purchase_tickets')->count();
        $row = DB::table('purchase_tickets')->orderByDesc('id')->first();

        $this->assertSame($before + 1, $after,
            'The guard refused a caller inside the real payment flow. Status '
            . $response->getStatusCode() . ', error: ' . var_export(session('error'), true));
        $this->assertSame((int) $store->id, (int) $row->store_id);
        $this->assertSame((int) $customer->id, (int) $row->customer_id);
    }

    /**
     * AND THE SAME FOR AN ANONYMOUS CALLER INSIDE THE FLOW.
     *
     * Deliberate: `lottery_form_data` carries no `auth` either, so a guest genuinely can buy a
     * ticket, and `:613` writes `customer_id = 0` for them. A guard that required a signed-in
     * customer would have closed the defect by breaking guest checkout -- which is why this guard
     * tests the FLOW rather than the principal.
     */
    public function test_a_guest_inside_the_real_flow_still_writes_the_ticket(): void
    {
        $this->enableModule();

        [, $store, $productId] = $this->makeLotteryStore('guest-store');

        $before = DB::table('purchase_tickets')->count();

        $response = $this->withSession(['lottery_request_data' => $this->flowData($productId)])
            ->get("/guest-store/store-lottery-payment");

        $this->assertSame($before + 1, DB::table('purchase_tickets')->count(),
            'The guard broke guest ticket purchase. Status ' . $response->getStatusCode()
            . ', error: ' . var_export(session('error'), true));
        $this->assertSame(0, (int) DB::table('purchase_tickets')->orderByDesc('id')->first()->customer_id);
    }

    /**
     * EVERY ONE OF THE EIGHT ROUTES THAT REACH THE HANDLER, not just the one the reproduction used.
     *
     * The guard is keyed on the controller action precisely so that this list cannot drift, and
     * this test is what proves the keying works. P1-INV-17's census missed live routes twice by
     * keying on uri shape -- once on uri words, once on a prefix -- so "the uri I happened to
     * probe" is not evidence about the other seven.
     *
     * Two of them take `{storeSlug?}` as an OPTIONAL segment, which is exactly the kind of variant
     * a uri-keyed target list drops.
     */
    public function test_every_route_reaching_the_handler_is_guarded(): void
    {
        $this->enableModule();

        [, , $productId] = $this->makeLotteryStore('all-routes');

        $uris = [
            '/all-routes/store-lottery-payment',
            '/all-routes/get-ticket-payment-paytm',
            '/all-routes/get-ticket-payment-aamarpay',
            '/all-routes/get-ticket-payment-midtrans',
            '/all-routes/store-ticket-payment-khalti',
            '/all-routes/store-ticket-payment-authorized',
            '/all-routes/ticket-telegram',
            '/all-routes/ticket-whatsapp',
        ];

        // PREMISE: the list is the real one, derived from the router rather than from this array.
        $reaching = [];
        foreach (app('router')->getRoutes() as $route) {
            if (str_contains((string) $route->getActionName(), '@StoreLotteryPayment')) {
                $reaching[] = $route->uri();
            }
        }
        $this->assertCount(count($uris), array_unique($reaching),
            'The number of routes reaching StoreLotteryPayment changed. The router reports: '
            . implode(', ', array_unique($reaching)));

        foreach ($uris as $uri) {
            $before = DB::table('purchase_tickets')->count();

            $this->get($uri . '?' . http_build_query([
                'ticket_price' => 1,
                'payment_methods' => 'telegram',
                'product_id' => $productId,
            ]));

            $this->assertSame($before, DB::table('purchase_tickets')->count(),
                "An anonymous session-less caller wrote a ticket through {$uri}.");
        }
    }

    /**
     * THE GUARD ASKS THE HANDLER'S QUESTION, NOT A TIDIER ONE.
     *
     * The handler's test is `$requests_data == null`, which is loose equality -- true of null, of
     * `''` and of `[]`. A guard using `session()->has()` would accept an empty array, pass the
     * request through, and the handler would then take the `$request->all()` fallback anyway. The
     * guard would be present and useless.
     */
    public function test_a_session_value_the_handler_treats_as_empty_is_also_refused(): void
    {
        $this->enableModule();

        [, , $productId] = $this->makeLotteryStore('loose-store');

        foreach ([['empty array', []], ['empty string', ''], ['zero', 0]] as [$label, $value]) {
            $before = DB::table('purchase_tickets')->count();

            $this->withSession(['lottery_request_data' => $value])
                ->get('/loose-store/store-lottery-payment?' . http_build_query([
                    'ticket_price' => 1,
                    'payment_methods' => 'telegram',
                    'product_id' => $productId,
                ]));

            $this->assertSame($before, DB::table('purchase_tickets')->count(),
                "A session value the handler treats as empty ({$label}) let the fallback run.");
        }
    }

    /**
     * KNOWN LIMITATION -- #67 ITEM 1 IS NOT CLOSED, AND THIS TEST IS WHY THAT IS NOT A SURPRISE.
     *
     * Found by adversarial review AFTER the guard was written, tested and green, then MEASURED.
     * This test asserts the defect that REMAINS, deliberately, so the limit is visible in the
     * suite rather than only in a docblock -- and so that whoever closes it properly sees this
     * test fail and deletes it, instead of this file quietly claiming more than it covers.
     *
     * The guard refuses the session-less `$request->all()` fallback. It costs the attacker ONE
     * EXTRA REQUEST and nothing else:
     *
     *   1. POST /{victim}/lottery-form-data -- that route carries no `auth` either -- with the
     *      victim's own lottery product, `payment_methods=telegram`, the caller's own
     *      `ticket_price`, and the nine `lottery_info[*]` fields the validator wants.
     *      `processTelegram` (LotteryPaymentService:1135-1140) is literally
     *      `Session::put('lottery_request_data', $request); return view(...)`.
     *   2. GET /{victim}/store-lottery-payment -- no query string needed.
     *
     * Outcome: the same row the one-request defect produced. Victim's store, `customer_id` 0, the
     * caller's own amount.
     *
     * WHY NOT FIX IT HERE. The two requests are the shape of a legitimate guest purchase minus the
     * payment, and for `telegram` and `whatsapp` there IS no payment step -- the module creates the
     * row and settles out of band by design. The only discriminator is whether money moved, which
     * means the gateway's signature or a transaction lookup inside the handler. Requiring
     * `auth('customers')` would close it by breaking guest purchase, which
     * `test_a_guest_inside_the_real_flow_still_writes_the_ticket` pins as a real flow.
     *
     * Tracked on #67 (kept open) and #68 (the forgeable amount).
     */
    public function test_KNOWN_LIMITATION_two_anonymous_requests_still_write_a_ticket_into_any_store(): void
    {
        $this->enableModule();

        [, $store, $productId] = $this->makeLotteryStore('victim-store');

        $before = DB::table('purchase_tickets')->count();

        $post = $this->post('/victim-store/lottery-form-data', [
            'product_id' => $productId,
            'payment_methods' => 'telegram',
            'ticket_price' => 1,
            'lottery_info' => [
                'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.test',
                'lottery_user_telephone' => '1', 'lottery_address' => 'x',
                'lottery_country' => 1, 'lottery_state' => 1, 'lottery_city' => 1,
                'lottery_postecode' => '1',
            ],
        ]);

        // PREMISE, asserted rather than assumed: step 1 must actually leave the key behind, or
        // step 2 would be refused and this test would record a closure that is not real.
        $this->assertNotNull(session('lottery_request_data'),
            'Step 1 did not populate the session, so step 2 below proves nothing. Status '
            . $post->getStatusCode());

        $this->get('/victim-store/store-lottery-payment');

        $row = DB::table('purchase_tickets')->orderByDesc('id')->first();

        $this->assertSame($before + 1, DB::table('purchase_tickets')->count(),
            'ITEM 1 MAY NOW BE CLOSED. If this assertion fails, the two-request path no longer '
            . 'writes a ticket -- which is the outcome #67 item 1 wants. Verify that is what '
            . 'happened, update #67, and DELETE this test; do not adjust it to keep it green.');
        $this->assertSame((int) $store->id, (int) $row->store_id);
        $this->assertSame(0, (int) $row->customer_id);
        $this->assertSame(1.0, (float) $row->amount,
            "And the amount is still the caller's own -- #68.");
    }

    /* ---------------------------------------------------------------------------------------- *
     *  ITEM 2 -- THE POSITIVE SIDE AND THE REMAINING NEGATIVES.
     * ---------------------------------------------------------------------------------------- */

    /**
     * A CUSTOMER CAN STILL REFUND THEIR OWN TICKET IN THEIR OWN STORE.
     *
     * Asserts the row is GONE through a raw `DB::table()` read. `PurchaseTicket` is a module model
     * and the two host models in this family are Cachable; CLAUDE.md records a measured false
     * reading where a test reported a store-B row deleted while the raw table still held it.
     */
    public function test_a_customer_can_still_refund_their_own_ticket(): void
    {
        $this->enableModule();

        [, $store, $productId, $lotteryId] = $this->makeLotteryStore('own-store');
        $customer = $this->makeCustomerIn($store);
        $ticket = $this->makeTicket($store, $lotteryId, $productId, 'MINE0001', $customer->id);

        $response = $this->actingAs($customer, 'customers')
            ->post('/own-store/refund-lottery-form-data', ['ticket_code' => 'MINE0001']);

        $this->assertFalse(DB::table('purchase_tickets')->where('id', $ticket)->exists(),
            'The guard refused a customer their own refund. Status ' . $response->getStatusCode()
            . ', error: ' . var_export(session('error'), true));
    }

    /**
     * ANOTHER SHOPPER'S TICKET IN THE SAME STORE IS NOT REFUNDABLE.
     *
     * The store predicate alone would admit this: both rows are in the caller's store. The handler
     * does key on `customer_id`, so this is the guard being no WEAKER than the handler -- the
     * failure mode a guard introduces when it replaces a handler's check with its own instead of
     * adding to it.
     */
    public function test_another_shoppers_ticket_in_the_same_store_is_not_refundable(): void
    {
        $this->enableModule();

        [, $store, $productId, $lotteryId] = $this->makeLotteryStore('shared-store');
        $mine = $this->makeCustomerIn($store);
        $theirs = $this->makeCustomerIn($store);
        $ticket = $this->makeTicket($store, $lotteryId, $productId, 'THEIRS01', $theirs->id);

        $response = $this->actingAs($mine, 'customers')
            ->post('/shared-store/refund-lottery-form-data', ['ticket_code' => 'THEIRS01']);

        $this->assertTrue(DB::table('purchase_tickets')->where('id', $ticket)->exists(),
            "One shopper refunded another shopper's ticket in the same store.");

        /*
         * AND NOT THROUGH A 500, which is what this test USED to tolerate -- found by adversarial
         * review, and it is CLAUDE.md's named trap in my own test. With all three guards removed
         * the row ALSO survives, for the wrong reason: the handler keys on `customer_id` too
         * (LotteryPrizesController:870), so its lookup is null and `:871`'s `->delete()` on null is
         * an HTTP 500. The row surviving proved nothing about the guard. The status assertion is
         * what makes this test about the `$ownedByCaller` limb rather than about the crash.
         */
        $this->assertNotSame(500, $response->getStatusCode(),
            'The row survived because the endpoint crashed, not because the guard refused. Body: '
            . substr(strip_tags((string) $response->getContent()), 0, 200));
    }

    /**
     * THE FLOW AN EARLIER VERSION OF THIS GUARD BROKE -- a legitimate cross-store refund.
     *
     * Found by adversarial review, and it was a regression I introduced rather than a defect I
     * inherited. `resolveStore()` required the slug's store to equal the signed-in customer's own
     * `store_id`, which refuses the ticket the module's own screen offers: `refund_lottery():862`
     * lists `where('store_id', $slugStore)->where('customer_id', $caller)`, so this ticket appears
     * as a radio input on store B's refund form and submits to B's slug.
     *
     * The mismatch is not an anomaly -- it is the PREMISE of the defect this guard fixes. Making
     * it a refusal meant the fix closed the attack by also closing the flow, and with the
     * deliberately uniform refusal body neither the shopper nor support could tell which had
     * happened.
     *
     * This test is the reason that limb cannot come back.
     */
    public function test_a_shopper_can_refund_a_ticket_they_bought_on_another_stores_storefront(): void
    {
        $this->enableModule();

        [, $storeA] = $this->makeLotteryStore('cross-a');
        [, $storeB, $productB, $lotteryB] = $this->makeLotteryStore('cross-b');

        // Registered at A, ticket bought on B's storefront -- exactly what
        // StoreLotteryPayment:613 writes, because `customers` is one session guard.
        $customer = $this->makeCustomerIn($storeA);
        $ticket = $this->makeTicket($storeB, $lotteryB, $productB, 'CROSS001', $customer->id);

        // PREMISE: the module's own form really does list this ticket. Asserted rather than
        // assumed, because if it did not, this test would be defending a flow that does not exist.
        $listed = DB::table('purchase_tickets')
            ->where('store_id', $storeB->id)
            ->where('customer_id', $customer->id)
            ->where('product_id', $productB)
            ->where('lottery_id', $lotteryB)
            ->exists();
        $this->assertTrue($listed,
            "refund_lottery():862's listing predicate does not match this fixture, so the flow "
            . 'this test defends is not the one the module offers.');

        $response = $this->actingAs($customer, 'customers')
            ->post('/cross-b/refund-lottery-form-data', ['ticket_code' => 'CROSS001']);

        $this->assertFalse(DB::table('purchase_tickets')->where('id', $ticket)->exists(),
            'The guard refused a refund the module lists as refundable. Status '
            . $response->getStatusCode() . ', error: ' . var_export(session('error'), true));
    }

    /**
     * AND THE ATTACK IS STILL REFUSED WITHOUT THAT LIMB -- the other half of the F3 correction.
     *
     * Dropping the slug/customer comparison would be a bad trade if it reopened anything. It does
     * not: the set predicate refuses a code that reaches a row outside the slug's store, which is
     * what the cross-store attack is. `test_a_slug_naming_a_different_store_is_refused` already
     * covers the caller's own code against a foreign slug; this covers the mirror case, a FOREIGN
     * code against the caller's own slug, with the caller's own id on it.
     */
    public function test_a_foreign_code_posted_to_the_callers_own_slug_is_still_refused(): void
    {
        $this->enableModule();

        [, $storeA] = $this->makeLotteryStore('mirror-a');
        [, $storeB, $productB, $lotteryB] = $this->makeLotteryStore('mirror-b');

        $customer = $this->makeCustomerIn($storeA);
        $ticket = $this->makeTicket($storeB, $lotteryB, $productB, 'MIRROR01', $customer->id);

        $this->actingAs($customer, 'customers')
            ->post('/mirror-a/refund-lottery-form-data', ['ticket_code' => 'MIRROR01']);

        $this->assertTrue(DB::table('purchase_tickets')->where('id', $ticket)->exists(),
            "A code naming store B's row was refunded through store A's slug.");
    }

    /**
     * AN ANONYMOUS REFUND IS REFUSED, NOT A 500.
     *
     * The handler dereferences `auth('customers')->user()->id` with no guard and the route carries
     * no `auth`, so today this is an error page for anyone who asks.
     */
    public function test_an_anonymous_refund_is_refused_rather_than_crashing(): void
    {
        $this->enableModule();

        [, $store, $productId, $lotteryId] = $this->makeLotteryStore('anon-store');
        $customer = $this->makeCustomerIn($store);
        $ticket = $this->makeTicket($store, $lotteryId, $productId, 'ANON0001', $customer->id);

        $response = $this->post('/anon-store/refund-lottery-form-data', ['ticket_code' => 'ANON0001']);

        $this->assertNotSame(500, $response->getStatusCode(),
            'An anonymous refund dereferences a null customer and 500s.');
        $this->assertTrue(DB::table('purchase_tickets')->where('id', $ticket)->exists(),
            'An anonymous caller deleted a ticket.');
    }

    /**
     * A SLUG NAMING A STORE THAT IS NOT THE CUSTOMER'S IS REFUSED, even for the customer's own code.
     *
     * The slug is caller-supplied, so the guard uses it only to NAME a store and compares it with
     * the signed-in customer's own `store_id`. CLAUDE.md records four separate defects produced by
     * treating a predicate built from request input as a tenant check; this asserts that the slug
     * is not one here.
     */
    public function test_a_slug_naming_a_different_store_is_refused(): void
    {
        $this->enableModule();

        [, $storeA, $productA, $lotteryA] = $this->makeLotteryStore('slug-a');
        $this->makeLotteryStore('slug-b');

        $customer = $this->makeCustomerIn($storeA);
        $ticket = $this->makeTicket($storeA, $lotteryA, $productA, 'OWNCODE1', $customer->id);

        $this->actingAs($customer, 'customers')
            ->post('/slug-b/refund-lottery-form-data', ['ticket_code' => 'OWNCODE1']);

        $this->assertTrue(DB::table('purchase_tickets')->where('id', $ticket)->exists(),
            "A refund posted on another store's slug still reached the customer's own row.");
    }

    /**
     * THE REFUSAL DOES NOT ANSWER THE ATTACKER'S QUESTION.
     *
     * "Does this code exist in some other store?" must not be answerable from the response. The
     * three ownership refusals -- foreign store, foreign shopper, no such code -- have to be
     * indistinguishable. #64's guard shipped with a disclosing body and its removal check could not
     * see it, because the test that looked compared two denials that happened to share a reason.
     */
    public function test_the_three_ownership_refusals_are_indistinguishable(): void
    {
        $this->enableModule();

        [, $storeA, $productA, $lotteryA] = $this->makeLotteryStore('oracle-a');
        [, $storeB, $productB, $lotteryB] = $this->makeLotteryStore('oracle-b');

        $mine = $this->makeCustomerIn($storeA);
        $theirs = $this->makeCustomerIn($storeA);

        $this->makeTicket($storeB, $lotteryB, $productB, 'FOREIGN1', $mine->id);
        $this->makeTicket($storeA, $lotteryA, $productA, 'OTHERS01', $theirs->id);

        $bodies = [];
        foreach (['FOREIGN1', 'OTHERS01', 'NOSUCH01'] as $code) {
            $response = $this->actingAs($mine, 'customers')
                ->postJson('/oracle-a/refund-lottery-form-data', ['ticket_code' => $code]);
            $bodies[$code] = [$response->getStatusCode(), $response->getContent()];
        }

        $this->assertSame($bodies['FOREIGN1'], $bodies['OTHERS01'],
            'A code in another STORE and a code belonging to another SHOPPER give different '
            . 'responses, so the response distinguishes them.');
        $this->assertSame($bodies['FOREIGN1'], $bodies['NOSUCH01'],
            'A code that exists elsewhere and a code that exists nowhere give different responses, '
            . 'which makes the endpoint an existence oracle across tenants.');
    }

    /* ---------------------------------------------------------------------------------------- *
     *  INERTNESS. A guard on the `web` GROUP sees every web request in the application.
     * ---------------------------------------------------------------------------------------- */

    /**
     * The three guards must do NOTHING to a request that is not theirs. They are appended to the
     * `web` group, so every storefront and admin page passes through all three; a target test that
     * matched too broadly would be an application-wide outage rather than a fix.
     */
    public function test_the_guards_are_inert_on_a_non_target_route(): void
    {
        $this->enableModule();

        [, $store, $productId, $lotteryId] = $this->makeLotteryStore('inert-store');
        $customer = $this->makeCustomerIn($store);
        $ticket = $this->makeTicket($store, $lotteryId, $productId, 'INERT001', $customer->id);

        // A HOST ROUTE THAT REALLY RETURNS 200, so this is an inertness test and not a tautology.
        // Every web request in the application passes through all three guards, so a target match
        // that was too broad would show up here first.
        //
        // NOT `assertNotSame(403, ...)`, which was the first version of this test and which an
        // over-broad LotteryTicketWriteGuard would have SURVIVED -- its web refusal is a 302
        // redirect, not a 403. Pointed out by adversarial review.
        $login = $this->get('/login');

        $this->assertSame(200, $login->getStatusCode(),
            'A guard broke an ordinary host page. Error: ' . var_export(session('error'), true));
        $this->assertNull(session('error'),
            'A guard flashed a refusal message on an ordinary host page.');

        // AND A SIBLING ROUTE IN THE SAME MODULE FILE, same group, same slug shape.
        //
        // 200 IS NOT ASSERTABLE HERE AND THE REASON IS MEASURED, NOT ASSUMED: `ticket-success`
        // returns 500 on its own, with all three guards unregistered -- `showSuccessPage` reads
        // `Session::get('lottery_data')`, which a direct visit does not have. That is pre-existing
        // and outside this change. So the claim made here is the one that is actually about the
        // guards: none of them refused, which a refusal would show as a flashed error plus a 302
        // to the store's landing page or a 403.
        $sibling = $this->get('/inert-store/ticket-success');

        $this->assertNull(session('error'),
            'A guard flashed a refusal message on a route that is not its target.');
        $this->assertNotSame(403, $sibling->getStatusCode(),
            'A guard forbade a route that is not its target.');
        $this->assertNotSame(302, $sibling->getStatusCode(),
            'A guard redirected a route that is not its target.');
        $this->assertTrue(DB::table('purchase_tickets')->where('id', $ticket)->exists());

        // And no guard created email templates for a request that is not the draw.
        $this->assertSame(0, DB::table('email_templates')->count(),
            'The mail-template prerequisite ran on a route that is not the draw.');
    }

    /**
     * THE CASE THE `$ownedByCaller` CHECK ALONE CANNOT SEE -- and the reason the store predicate is
     * a separate limb rather than folded into it.
     *
     * FOUND BY A REMOVAL CHECK, not by design. Deleting the `noMatchedRowOutsideStore()` call left
     * the suite green, which said the limb was unprotected, not that it was redundant. It is not
     * redundant:
     *
     *   `$ownedByCaller` asks "does a row exist that is MINE, in MY store". That can be true while
     *   the handler still destroys a different row, because the handler's query
     *   (`lottery_code = ? AND customer_id = ?`, LotteryPrizesController:870) has NO store filter
     *   and resolves with `->first()` -- primary key order. A row with the SAME code and the SAME
     *   customer id in another store, inserted EARLIER, is the one `->first()` returns.
     *
     * Reachable for exactly the reason item 2 is reachable at all: `customers` is one session
     * guard, not one per store, so a shopper's id lands on ticket rows in several stores, and
     * nothing stops two of those rows sharing a code -- `generateRandomCode` is 8 symbols per store
     * with no uniqueness constraint (no index on `purchase_tickets.lottery_code`).
     *
     * The foreign row is created FIRST here, deliberately, so that `->first()` prefers it.
     */
    public function test_an_earlier_foreign_row_with_the_same_code_and_customer_is_not_the_one_deleted(): void
    {
        $this->enableModule();

        [, $storeA, $productA, $lotteryA] = $this->makeLotteryStore('collide-a');
        [, $storeB, $productB, $lotteryB] = $this->makeLotteryStore('collide-b');

        $customer = $this->makeCustomerIn($storeA);

        // STORE B'S ROW FIRST, so it holds the lower primary key and ->first() prefers it.
        $foreign = $this->makeTicket($storeB, $lotteryB, $productB, 'COLLIDE1', $customer->id);
        $own = $this->makeTicket($storeA, $lotteryA, $productA, 'COLLIDE1', $customer->id);

        $this->assertLessThan($own, $foreign,
            'The premise of this test is that the FOREIGN row is the one ->first() returns.');

        $this->actingAs($customer, 'customers')
            ->post('/collide-a/refund-lottery-form-data', ['ticket_code' => 'COLLIDE1']);

        $this->assertTrue(DB::table('purchase_tickets')->where('id', $foreign)->exists(),
            "The refund destroyed store B's row: the handler's unscoped ->first() reached it even "
            . "though a row of the caller's own existed in their own store.");
    }

    /**
     * THE ANONYMOUS REFUSAL SAYS "SIGN IN", which is both the better message and what makes the
     * explicit check testable.
     *
     * Without the explicit check the anonymous case is still refused, but only by accident:
     * `$customer->store_id ?? null` on a null `$customer` evaluates to null under
     * null-coalescing, `resolveStore()` returns null, and the generic ownership refusal fires. A
     * removal check showed that, so the implicit path is real -- but it is fragile (it depends on
     * `??` suppressing a property read on null) and it tells a signed-out shopper their ticket
     * "could not be refunded" rather than that they need to sign in.
     *
     * Asserting the message is what keeps the explicit check from being deleted as redundant.
     */
    public function test_the_anonymous_refusal_asks_the_shopper_to_sign_in(): void
    {
        $this->enableModule();

        [, $store, $productId, $lotteryId] = $this->makeLotteryStore('signin-store');
        $customer = $this->makeCustomerIn($store);
        $this->makeTicket($store, $lotteryId, $productId, 'SIGNIN01', $customer->id);

        $response = $this->postJson('/signin-store/refund-lottery-form-data', ['ticket_code' => 'SIGNIN01']);

        $this->assertStringContainsString('sign in', strtolower((string) $response->getContent()),
            'A signed-out shopper is told their ticket could not be refunded rather than that they '
            . 'need to sign in.');
    }

    /**
     * The shape `lottery_form_data` leaves in the session, which is what the real flow carries.
     */
    private function flowData(int $productId): array
    {
        return [
            'product_id' => $productId,
            'ticket_price' => 25,
            'payment_methods' => 'telegram',
            'lottery_info' => [
                'firstname' => 'Flow',
                'lastname' => 'Caller',
                'email' => 'flow@example.test',
                'lottery_user_telephone' => '0000000000',
                'lottery_address' => 'Somewhere',
                'lottery_country' => 1,
                'lottery_state' => 1,
                'lottery_city' => 1,
                'lottery_postecode' => '00000',
            ],
        ];
    }

    private function makeCustomerIn(Store $store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Cust',
            'last_name' => 'Omer',
            'email' => 'cust' . uniqid() . '@example.test',
            'password' => bcrypt('not-a-real-credential'),
            'store_id' => $store->id,
            'status' => 1,
            'billable_id' => $store->created_by,
            'billable_type' => \App\Models\User::class,
        ]);
    }

    /**
     * A store with one lottery product and its LotterySetting.
     *
     * @return array{0: \App\Models\User, 1: Store, 2: int, 3: int}
     */
    private function makeLotteryStore(string $slug): array
    {
        $owner = $this->makeMerchant();
        $store = $this->makeStore($owner, ['slug' => $slug]);
        $owner->current_store = $store->id;
        $owner->save();

        $productId = (int) DB::table('products')->insertGetId([
            'name' => 'Lottery Product ' . $slug,
            'slug' => 'lottery-product-' . $slug,
            'store_id' => $store->id,
            'created_by' => $owner->id,
            'price' => 10,
            'track_stock' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $lotteryId = (int) DB::table('lottery_settings')->insertGetId([
            'store_id' => $store->id,
            'lottery_products' => $productId,
            'ticket_price' => 25,
            'created_by' => $owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$owner, $store, $productId, $lotteryId];
    }

    private function makeTicket(Store $store, int $lotteryId, int $productId, string $code, int $customerId): int
    {
        return (int) DB::table('purchase_tickets')->insertGetId([
            'firstname' => 'Tick',
            'lastname' => 'Et',
            'email' => 'ticket' . uniqid() . '@example.test',
            'lottery_code' => $code,
            'product_id' => $productId,
            'lottery_id' => $lotteryId,
            'customer_id' => $customerId,
            'amount' => 25,
            'payment_type' => 'telegram',
            'store_id' => $store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * ITEM 2 -- `refund_lottery_form_data` deletes keyed on `lottery_code` + `customer_id` with NO
     * store predicate, and `->delete()` on a null lookup 500s.
     *
     * The `customers` guard is ONE session guard, not one per store, so a shopper's session spans
     * storefronts. A person registered at store A who also buys a ticket at store B has rows in
     * both; store A's refund form then reaches store B's row, because the only keys are the code
     * and the customer id.
     *
     * This test drives the SAME customer id against the OTHER store's slug.
     */
    public function test_a_refund_on_one_stores_form_cannot_delete_another_stores_ticket(): void
    {
        $this->enableModule();

        [, $storeA, $productA, $lotteryA] = $this->makeLotteryStore('store-a');
        [, $storeB, $productB, $lotteryB] = $this->makeLotteryStore('store-b');

        $customer = $this->makeCustomerIn($storeA);

        // The customer's own ticket in store B -- reachable because the customers guard is not
        // per-store, so StoreLotteryPayment stamps their id onto a store-B row.
        $foreignTicket = $this->makeTicket($storeB, $lotteryB, $productB, 'BBBB1111', $customer->id);

        $response = $this->actingAs($customer, 'customers')
            ->post("/store-a/refund-lottery-form-data", ['ticket_code' => 'BBBB1111']);

        $stillThere = DB::table('purchase_tickets')->where('id', $foreignTicket)->exists();

        fwrite(STDERR, "\nITEM2 STATUS  " . $response->getStatusCode() . "\n");
        fwrite(STDERR, 'ITEM2 store-B ticket still present: ' . ($stillThere ? 'yes' : 'NO -- DELETED') . "\n");

        $this->assertTrue($stillThere,
            "A refund submitted on store A's form deleted a purchase_tickets row belonging to store B.");
    }

    /**
     * ITEM 2b -- an unmatched code is a null `->delete()`, which 500s.
     */
    public function test_a_refund_with_an_unmatched_code_does_not_crash(): void
    {
        $this->enableModule();

        [, $storeA] = $this->makeLotteryStore('store-a');
        $customer = $this->makeCustomerIn($storeA);

        $response = $this->actingAs($customer, 'customers')
            ->post("/store-a/refund-lottery-form-data", ['ticket_code' => 'NOSUCHCODE']);

        fwrite(STDERR, "\nITEM2b STATUS  " . $response->getStatusCode() . "\n");

        $this->assertNotSame(500, $response->getStatusCode(),
            'An unmatched refund code is a null ->delete() and 500s.');
    }

    /**
     * ITEM 4 -- a legitimate own-store draw 500s AFTER writing, because
     * LotteryResultController:157 does `$FailTemplate['id']` on null when the caller has no
     * 'Lottery Fail' email template.
     *
     * WHY A REAL MERCHANT HAS NO SUCH TEMPLATE, which is sharper than #67 records:
     * `LotteryPrizesController::EmailTemplateDefaultData()` is the only thing that creates it, and
     * it is called from exactly ONE place -- `LotteryemailTemplate()`, the lottery mail-settings
     * SCREEN, behind `Manage Lottery Mail Setting`. Sibling modules wire the same call into a
     * ViewComposer so it runs on every render (SpinToWinReward ViewComposer.php:42,
     * PartialPayments likewise). LotteryPrizes does not. So a merchant who activates the module,
     * configures a lottery, sells tickets and draws a winner WITHOUT ever opening that screen has
     * no template at all -- an ordinary path, not an edge case.
     *
     * This test deliberately does NOT seed the 'Lottery Fail' template, which is what
     * LotteryResultOwnershipTest's fixture does.
     */
    public function test_a_legitimate_draw_does_not_crash_when_the_caller_has_no_fail_template(): void
    {
        $this->enableModule();

        [$owner, $store, $productId, $lotteryId] = $this->makeLotteryStore('draw-store');
        $this->entitleForDraw($owner);
        $this->grantPermission($owner, 'Manage Lottery Winner');
        $this->giveCallerTheWinTemplateOnly($owner);

        $customer = $this->makeCustomerIn($store);
        $this->makeTicket($store, $lotteryId, $productId, 'WINNER01', $customer->id);
        // A SECOND, LOSING ticket, because :151's $fail_ticket query is what reaches :157.
        $this->makeTicket($store, $lotteryId, $productId, 'LOSER001', $customer->id);

        $response = $this->actingAs($owner)->post('/lotteryresult', [
            'selected_codes' => 'WINNER01',
            'lottery_id' => $lotteryId,
        ]);

        fwrite(STDERR, "\nITEM4 STATUS  " . $response->getStatusCode() . "\n");

        /*
         * THE POSITIVE ASSERTION COMES FIRST, because `assertNotSame(500, ...)` alone is the exact
         * trap that hid this defect in the first place: #64's positive test asserted only
         * `assertNotSame(403, ...)` plus its row, and 500 != 403, so it reported green while every
         * draw in the suite was crashing. A test that only rules out one status says nothing about
         * whether the work happened.
         */
        $order = DB::table('orders')->where('lottery_code', 'WINNER01')->first();

        $this->assertNotNull($order,
            'The draw wrote no order row. Status ' . $response->getStatusCode()
            . ', error: ' . var_export(session('error'), true));
        $this->assertSame((int) $store->id, (int) $order->store_id);
        $this->assertSame(1, DB::table('lottery_customers')
            ->where('lottery_code', 'WINNER01')->where('store_id', $store->id)->count(),
            'The draw did not record its lottery_customers row.');

        $this->assertNotSame(500, $response->getStatusCode(),
            'A legitimate own-store draw 500s because the caller has no Lottery Fail template. Body: '
            . substr(strip_tags((string) $response->getContent()), 0, 200));
    }

    /**
     * THE LINE THE PREREQUISITE MIDDLEWARE DOES NOT CROSS, asserted rather than promised.
     *
     * `LotteryemailTemplate()` calls BOTH `EmailTemplateDefaultData()` and
     * `userTemplateDefaultData()`. The middleware calls only the first. The second creates the
     * `user_email_templates` join rows, and those are what `if ($FailEmailTemplate)` tests to
     * decide whether to SEND mail -- so creating them would start sending lottery-loser email on
     * behalf of a merchant who never configured a template. An outbound behaviour change is not a
     * crash fix.
     *
     * Without the join row `$FailEmailTemplate` stays null, the `if` is skipped exactly as it is
     * today, and the only thing that changed is that the request does not 500.
     */
    public function test_the_prerequisite_creates_the_template_but_does_not_enable_sending(): void
    {
        $this->enableModule();

        [$owner, $store, $productId, $lotteryId] = $this->makeLotteryStore('draw-store');
        $this->entitleForDraw($owner);
        $this->grantPermission($owner, 'Manage Lottery Winner');

        $customer = $this->makeCustomerIn($store);
        $this->makeTicket($store, $lotteryId, $productId, 'WINNER01', $customer->id);
        $this->makeTicket($store, $lotteryId, $productId, 'LOSER001', $customer->id);

        $this->actingAs($owner)->post('/lotteryresult', [
            'selected_codes' => 'WINNER01',
            'lottery_id' => $lotteryId,
        ]);

        $this->assertSame(1, DB::table('email_templates')
            ->where('name', 'Lottery Fail')->where('created_by', $owner->id)->count(),
            'The prerequisite did not create the template that :157 dereferences.');

        $this->assertSame(0, DB::table('user_email_templates')
            ->where('user_id', $owner->id)->count(),
            'The prerequisite created a user_email_templates row, which would start sending mail '
            . 'for a merchant who never configured a template.');
    }

    /**
     * TWO DRAWS IN ONE REQUEST CYCLE -- the test that the first version of this guard needed and
     * did not have.
     *
     * FOUND BY ADVERSARIAL REVIEW AND THEN MEASURED: `email_templates` went **3 -> 6** across two
     * draws. `App\Models\EmailTemplate` is `Cachable`, the Eloquent `pluck()` the guard used for
     * its existence check caches even an EMPTY result, and the raw `DB::table()->insert()` does
     * not flush it -- so the second draw read the pre-write empty set, recomputed all three names
     * as missing, and inserted them again. Three rows per draw, unbounded.
     *
     * WHY `test_the_prerequisite_does_not_duplicate_an_existing_template` BELOW COULD NOT SEE IT:
     * it seeds the row BEFORE the request, which is the one case the cache cannot affect, and
     * every other test in this file is a single request against a fresh `array` store, so the
     * check always read cold. CLAUDE.md names this trap explicitly and it still landed in my own
     * fix -- the read has to go through the same builder as the write.
     */
    public function test_a_second_draw_does_not_insert_the_templates_again(): void
    {
        $this->enableModule();

        [$owner, $store, $productId, $lotteryId] = $this->makeLotteryStore('twice-store');
        $this->entitleForDraw($owner);
        $this->grantPermission($owner, 'Manage Lottery Winner');
        $this->giveCallerTheWinTemplateOnly($owner);

        $customer = $this->makeCustomerIn($store);
        foreach (['WIN00001', 'WIN00002', 'LOSE0001', 'LOSE0002'] as $code) {
            $this->makeTicket($store, $lotteryId, $productId, $code, $customer->id);
        }

        $this->actingAs($owner)->post('/lotteryresult', [
            'selected_codes' => 'WIN00001',
            'lottery_id' => $lotteryId,
        ]);
        $afterFirst = DB::table('email_templates')->where('created_by', $owner->id)->count();

        $this->actingAs($owner)->post('/lotteryresult', [
            'selected_codes' => 'WIN00002',
            'lottery_id' => $lotteryId,
        ]);
        $afterSecond = DB::table('email_templates')->where('created_by', $owner->id)->count();

        fwrite(STDERR, "\nF2 email_templates after draw 1: {$afterFirst}, after draw 2: {$afterSecond}\n");

        $this->assertSame($afterFirst, $afterSecond,
            "The second draw inserted the merchant's email templates again. The existence check is "
            . 'reading a cached result that the raw insert did not flush.');

        // AND THE ABSOLUTE COUNT, so a version that inserted nothing at all would not pass this
        // by keeping two equal numbers.
        $this->assertSame(3, $afterSecond,
            'Expected exactly the three template names, once each: Lottery Win (seeded by the '
            . 'fixture), Lottery Fail and Lottery Remainder.');
    }

    /**
     * AND IT DOES NOT DUPLICATE what the merchant already has. Idempotence matters because this
     * runs on every draw, not once at setup.
     */
    public function test_the_prerequisite_does_not_duplicate_an_existing_template(): void
    {
        $this->enableModule();

        [$owner, $store, $productId, $lotteryId] = $this->makeLotteryStore('draw-store');
        $this->entitleForDraw($owner);
        $this->grantPermission($owner, 'Manage Lottery Winner');

        DB::table('email_templates')->insert([
            'name' => 'Lottery Fail',
            'from' => 'pre-existing',
            'created_by' => $owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customer = $this->makeCustomerIn($store);
        $this->makeTicket($store, $lotteryId, $productId, 'WINNER01', $customer->id);
        $this->makeTicket($store, $lotteryId, $productId, 'LOSER001', $customer->id);

        $this->actingAs($owner)->post('/lotteryresult', [
            'selected_codes' => 'WINNER01',
            'lottery_id' => $lotteryId,
        ]);

        $rows = DB::table('email_templates')
            ->where('name', 'Lottery Fail')->where('created_by', $owner->id)->get();

        $this->assertCount(1, $rows, 'The prerequisite duplicated an existing template row.');
        $this->assertSame('pre-existing', $rows->first()->from,
            "The prerequisite overwrote the merchant's own template.");
    }

    /**
     * THE CALLER'S OWN 'Lottery Win' SETUP -- AND DELIBERATELY NOT 'Lottery Fail'.
     *
     * That asymmetry IS the state item 4 describes, and getting it wrong makes the test vacuous in
     * both directions. Both the host `Order` write and the `LotteryCustomer` row sit inside
     * `if ($UserEmailTemplate)` at LotteryResultController:103, keyed on the caller's own
     * `Lottery Win` join row -- so without this fixture the handler writes NOTHING and a positive
     * assertion fails for the wrong reason. The first draft of this test had exactly that: status
     * 302, no error, no order row, because the draw never got past :103.
     *
     * `email_template_langs` is here for the same class of reason:
     * `sendOwnerEmailTemplate:739` assigns `$content->from` on an unguarded
     * `EmailTemplateLang` lookup, so without the row the request is a 500 before any write.
     * `content` is left EMPTY on purpose -- `:740`'s `if (!empty($content->content))` then skips
     * the actual send, so this test reaches the writes without attempting outbound mail.
     *
     * None of this is a barrier an attacker defeats; it is the merchant's own notification setup,
     * which any merchant using the lottery feature has. It is a stated precondition.
     */
    private function giveCallerTheWinTemplateOnly(\App\Models\User $user): void
    {
        $templateId = (int) DB::table('email_templates')->insertGetId([
            'name' => 'Lottery Win',
            'from' => 'lottery',
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_email_templates')->insert([
            'template_id' => $templateId,
            'user_id' => $user->id,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('email_template_langs')->insert([
            'parent_id' => $templateId,
            'language' => 'en',
            'subject' => 'Lottery Win',
            'content' => '',
            'is_default' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(0, DB::table('email_templates')->where('name', 'Lottery Fail')->count(),
            "The premise of item 4 is that the caller has 'Lottery Win' but NOT 'Lottery Fail'.");
    }

    private function entitleForDraw(\App\Models\User $owner): void
    {
        DB::table('user_active_modules')->insert([
            'user_id' => $owner->id,
            'module' => 'LotteryPrizes',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertContains('LotteryPrizes', ActivatedModule($owner->id),
            'The entitlement fixture did not take, so PlanModuleCheck would refuse the draw and '
            . 'the assertion would pass without reaching the handler.');
    }
}
