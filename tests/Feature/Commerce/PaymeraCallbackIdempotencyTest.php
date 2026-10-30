<?php

namespace Tests\Feature\Commerce;

use App\Http\Controllers\PaymentController;
use App\Services\PaymentService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A REPLAYED PAYMERA CALLBACK MUST NOT CREATE A SECOND ORDER.
 *
 * A gateway retrying a callback is normal. Paymera has TWO routed entry points
 * for the same payment -- `handlePaymeraCallback` (the customer's browser
 * returning) and `handlePaymeraTrigger` (the server-to-server poke) -- so they
 * do not merely retry, they race each other by design.
 *
 * WHAT WAS ALREADY THERE, and is not reinvented here: both entry points already
 * tested `$pending['processed']` and both set it after creating the order.
 *
 * THE DEFECT THAT REMAINED. That test ran BEFORE `Cache::lock()` was taken, and
 * the code inside the lock then went on using the `$pending` array read before
 * it. Check and use straddled the lock:
 *
 *   trigger: read (processed=false) -> check ok -> lock -> create -> processed=true -> unlock
 *   callback:         read (processed=false) -> check ok ---------------> lock -> CREATE AGAIN
 *
 * So a second order, a second stock decrement and a second merchant-wallet
 * credit were all reachable from an ordinary gateway retry. The fix re-reads the
 * record INSIDE the lock (PaymentController::freshPaymeraPendingOrNull()) and
 * refuses when it reports processed.
 *
 * SCOPE OF THESE TESTS, stated rather than implied. They drive the real
 * controller methods against the real cache, with a pending record whose
 * `processed` flag is already true -- which is precisely the state the losing
 * side of the race observes once it acquires the lock. They prove the replay is
 * refused and that no order is written.
 *
 * They do NOT prove anything about Paymera itself. Nothing in this container can
 * reach a gateway, and `pollPaymentStatus()` is not called on the paths these
 * tests exercise: the replay is refused before it. The provider contract --
 * which field carries the captured amount, the callback signature scheme -- is
 * unverified and is reported as such, not as verified.
 */
class PaymeraCallbackIdempotencyTest extends TestCase
{
    private const REFERENCE = 'PMR-TEST-REF-0001';

    protected function tearDown(): void
    {
        Cache::forget('paymera_pending_' . self::REFERENCE);
        Cache::forget('paymera_lock_' . self::REFERENCE);
        /* The third prefix, added with F96. Nothing leaks today -- `CACHE_STORE=array` with a fresh
           application per test -- but a tearDown that cleans two of three keys is the kind of
           asymmetry that becomes a cross-test false green the moment the store changes. */
        Cache::forget('paymera_claim_' . self::REFERENCE);

        parent::tearDown();
    }

    /**
     * The browser callback, replayed after the trigger already created the
     * order, creates nothing and sends the customer to the completion page.
     */
    public function test_a_replayed_browser_callback_does_not_create_a_second_order(): void
    {
        $this->putPending(['processed' => true, 'product_order_id' => '0202501010101']);

        $response = $this->controller()->handlePaymeraCallback(
            $this->blankRequest(),
            self::REFERENCE
        );

        $this->assertTrue($response->isRedirect(), 'the replay must be answered with a redirect');
        $this->assertStringContainsString(
            'order',
            $response->getTargetUrl(),
            'the customer belongs on the completion page, not back in checkout'
        );
    }

    /**
     * The server-to-server trigger, replayed after the browser callback already
     * created the order, creates nothing and reports the existing order.
     */
    public function test_a_replayed_gateway_trigger_does_not_create_a_second_order(): void
    {
        // status 'A' is the state the trigger itself writes, so this is the
        // shape of the trigger being retried after it already succeeded. The
        // pre-existing `status === 'A'` guard at the top of the method is what
        // answers here, and it answers without creating anything.
        $this->putPending([
            'status' => 'A',
            'processed' => true,
            'product_order_id' => '0202501010102',
        ]);

        $this->asAuthorisedCaller();   // F210's ownership gate -- see the helper
        $response = $this->controller()->handlePaymeraTrigger(self::REFERENCE);
        $payload = json_decode($response->getContent(), true);

        $this->assertSame('A', $payload['status'] ?? null);
        $this->assertTrue($payload['ok'] ?? false);
    }

    /**
     * The `processed` guard specifically -- reached when the record is marked
     * processed but its status is not 'A'.
     *
     * THIS STATE IS SYNTHETIC, AND THE REASON GIVEN HERE FOR IT WAS FALSE. [corrected 2026-10-29]
     *
     * It said "a trigger arriving between a callback's order write and that put sees exactly
     * this". It does not: `handlePaymeraCallback` writes `status`, `processed`, `order_id` and
     * `product_order_id` in ONE `Cache::put`, so that interleaving sees `processed = false`. The
     * mandated review of F185 traced it, and the conclusion is sharper than the old sentence:
     * **no writer produces `processed === true` with `status !== 'A'`**, so the trigger's
     * already-`processed` branch is unreachable in current code — the `status === 'A'` guard above
     * it returns first.
     *
     * The case is kept because it is the only test of that branch's CONTRACT (report the existing
     * order rather than create another, and report it only to the session that placed it), and
     * because the branch becomes reachable the moment a third writer or a reordered guard appears.
     * What it is NOT is evidence of a reachable path, and it should not be read as one.
     *
     * [2026-10-29] THIS CASE PINNED F185 AND HAD TO BE CORRECTED, which is worth recording rather
     * than quietly fixing. It drove the branch with NO session binding and asserted the order
     * number came back -- which is exactly the disclosure F185 names: on this route, behind `auth`,
     * that caller is any logged-in `web` user who guessed a live reference. The INTENT is still
     * right and is still asserted: the replay reports the existing order rather than creating
     * another. What changed is who is told. The record now carries an `order_id` and the session is
     * bound to it, so this exercises the legitimate path; `PaymeraTriggerOrderNumberTest` covers
     * the refusal, the shape and the `auth` premise.
     *
     * Note `OrderCompleteBinding::holds(null)` is FALSE -- `asId()` rejects a non-scalar -- so a
     * record with no `order_id`, which is what `putPending()` defaults to, can never be told the
     * number. That is fail-closed and is why this case has to name one.
     */
    public function test_the_processed_guard_reports_the_existing_order(): void
    {
        $this->bindSession();
        \App\Services\OrderCompleteBinding::remember(9102);

        $this->putPending([
            'status' => 'P',
            'processed' => true,
            'order_id' => 9102,
            'product_order_id' => '0202501010102',
        ]);

        $this->asAuthorisedCaller();   // F210's ownership gate -- see the helper
        $response = $this->controller()->handlePaymeraTrigger(self::REFERENCE);
        $payload = json_decode($response->getContent(), true);

        $this->assertSame('A', $payload['status'] ?? null);
        $this->assertTrue($payload['ok'] ?? false);
        $this->assertSame(
            '0202501010102',
            $payload['product_order_id'] ?? null,
            'the replay must report the order that already exists, not make a new one -- to the'
            . ' session that placed it. If this is null, F185\'s binding test is refusing the'
            . ' legitimate caller as well as the stranger, which is a regression and not a fix.'
        );
    }

    /**
     * F185 DRIVEN THROUGH THE HANDLER, not just through the helper. Review's F6.5.
     *
     * `PaymeraTriggerOrderNumberTest` drives `paymeraTriggerAnswer()` by reflection, which pins the
     * helper's contract but not the wiring: a branch that stopped calling it would pass every case
     * there. The positive wiring is covered by the case above; this is the negative half, and it is
     * the one that matters, because it is the disclosure.
     *
     * Same synthetic state as the case above and for the same reason (no writer produces
     * `processed === true` with `status !== 'A'` — see that docblock), but with NO binding. A
     * session that did not place the order must be answered null.
     */
    public function test_the_processed_guard_refuses_a_session_that_did_not_place_the_order(): void
    {
        $this->bindSession();
        \App\Services\OrderCompleteBinding::remember(1);

        $this->putPending([
            'status' => 'P',
            'processed' => true,
            'order_id' => 4242,
            'product_order_id' => '0202501010102',
        ]);

        $this->asAuthorisedCaller();   // F210's ownership gate -- see the helper
        $response = $this->controller()->handlePaymeraTrigger(self::REFERENCE);
        $payload = json_decode($response->getContent(), true);

        $this->assertSame('A', $payload['status'] ?? null, 'the response shape changed');
        $this->assertTrue($payload['ok'] ?? false, 'the response shape changed');
        $this->assertArrayHasKey(
            'product_order_id',
            $payload,
            'the refusal omits the key instead of nulling it, which is a shape a client can tell'
            . ' apart from the success'
        );
        $this->assertNull(
            $payload['product_order_id'],
            'a logged-in session that placed a DIFFERENT order was handed this one\'s number'
            . ' through the handler — so the branch is not wired to the guard, whatever the'
            . ' helper\'s own tests say'
        );
    }

    /**
     * THE STRUCTURAL ASSERTION, and the reason it is needed alongside the two
     * behavioural tests above.
     *
     * Those two enter through the PRE-LOCK branch, because by the time they run
     * `processed` is already true. The branch this fix actually adds is the one
     * INSIDE the lock, which only the loser of a genuine race reaches -- and
     * staging that needs two processes contending for one cache lock, which this
     * suite cannot do with one PHP process and an array cache store.
     *
     * So the re-read's POSITION is asserted directly: in both entry points the
     * re-check must sit AFTER the lock is acquired and BEFORE the order writer
     * is called. A fix that drifted back above the lock would restore the exact
     * defect while leaving both tests above green, so this is the assertion that
     * holds the property.
     *
     * Comments are stripped before matching, so a comment cannot satisfy or
     * defeat it.
     */
    public function test_the_recheck_sits_between_the_lock_and_the_order_write(): void
    {
        foreach ([
            'handlePaymeraCallback' => 'the browser callback',
            'handlePaymeraTrigger' => 'the gateway trigger',
        ] as $method => $what) {
            $source = $this->executableSourceOf(PaymentController::class, $method);

            $lock = strpos($source, '$lock->get()');
            $recheck = strpos($source, 'freshPaymeraPendingOrNull(');
            $write = strpos($source, '$this->getProductStatus(');

            $this->assertNotFalse($lock, $what . ' must still take the lock');
            $this->assertNotFalse(
                $recheck,
                $what . ' must re-read the pending record under the lock'
            );
            $this->assertNotFalse($write, $what . ' must still create the order');

            $this->assertGreaterThan(
                $lock,
                $recheck,
                $what . ': the re-check must be INSIDE the lock -- above it, it is the '
                    . 'same check-then-act race it replaces'
            );
            $this->assertLessThan(
                $write,
                $recheck,
                $what . ': the re-check must happen BEFORE the order is written'
            );
        }
    }

    /**
     * EVERY ROUTED PAYMERA ENTRY POINT CARRIES THE REPLAY GUARD -- and the list
     * of entry points is DERIVED, not typed.
     *
     * WHY THIS EXISTS. The test above pins the lock/recheck/write ordering for
     * two methods named as literals. That is only as good as the claim that
     * those two are the only reachable entry points, and PaymentController in
     * fact holds FIVE `handlePaymera*` methods. Measured:
     *
     *   handlePaymeraCallback        :595   routed (GET paymera.callback)   guarded
     *   handlePaymeraTrigger         :952   routed (POST paymera.trigger)   guarded
     *   handlePaymeraCallback_backup :910   NOT routed    lock, but NO replay guard
     *   handlePaymeraCallback00      :1055  NOT routed    no guard, no lock
     *   handlePaymeraTrigger00       :1083  NOT routed    no guard, no lock
     *
     * The three legacy copies are unreachable today -- a repo-wide search for
     * each name outside the controller returns nothing, and no route uses a
     * dynamic controller action -- so a replay cannot reach them. But they are
     * live code in a live class, one route line away from being an unguarded
     * duplicate order writer, and the previous phase's lesson here was exactly
     * that a route owner and a handler owner each assumed the other end carried
     * the control.
     *
     * THE INVARIANT: routed implies guarded. Routed-ness comes from the BOOTED
     * ROUTER rather than a grep of routes/, so it follows the application's own
     * resolution including any group prefix or dynamic registration. Route the
     * `_backup` copy and this test fails and names it.
     *
     * It proves nothing about Paymera's own contract -- see the class docblock.
     */
    /**
     * F97. THE TRIGGER ROUTE IS BEHIND `auth`, SO THE GATEWAY CANNOT REACH IT — and the handler's
     * own comment used to assert the opposite, in exactly the place a reviewer looks to decide
     * whether the path is safe.
     *
     * `routes/web.php` declares `POST store/paymera/trigger/{orderId}` inside the
     * `['auth','xss','setlocate','verified']` group that opens at :400 and closes at :1491, so a
     * server-to-server poke from Paymera is redirected to /login and never reaches
     * `handlePaymeraTrigger`. The CSRF exemption at `bootstrap/app.php:412` is real but does not
     * help: exempting CSRF on a route behind `auth` only changes which refusal it gets.
     *
     * THIS TEST PINS TODAY'S MEASUREMENT, NOT A PREFERENCE. Whether the route should be moved out
     * of the group is the functional half of F97 and an owner decision, because it changes a
     * payment route's authentication. If that decision is taken, this test fails and names the
     * comment that has to be revisited with it — which is the point. It reads the BOOTED ROUTER
     * rather than grepping `routes/`, so a group prefix or dynamic registration is followed.
     */
    public function test_the_paymera_trigger_route_is_still_behind_auth(): void
    {
        $routes = \Illuminate\Support\Facades\Route::getRoutes();

        $trigger = null;
        $callback = null;

        foreach ($routes as $route) {
            $uri = $route->uri();
            if ($uri === 'store/paymera/trigger/{orderId}') {
                $trigger = $route;
            }
            if ($uri === 'store/paymera/callback/{orderId}') {
                $callback = $route;
            }
        }

        $this->assertNotNull($trigger, 'premise: the trigger route is registered at all');
        $this->assertNotNull($callback, 'premise: the callback route is registered at all');

        $triggerMiddleware = $trigger->gatherMiddleware();
        $callbackMiddleware = $callback->gatherMiddleware();

        $this->assertContains('auth', $triggerMiddleware,
            'the Paymera TRIGGER route no longer carries `auth`. If that is deliberate, it is the '
            . 'functional half of F97 and needs the owner decision it records — and the comment in '
            . 'PaymentController::handlePaymeraTrigger, which now states that the gateway cannot '
            . 'reach this route, must be corrected in the same change. Middleware found: '
            . implode(', ', $triggerMiddleware));

        $this->assertNotContains('auth', $callbackMiddleware,
            'the Paymera CALLBACK route has gained `auth`. It is the only live Paymera entry '
            . 'point; putting it behind authentication makes the gateway unable to confirm any '
            . 'payment at all. Middleware found: ' . implode(', ', $callbackMiddleware));
    }

    public function test_every_routed_paymera_entry_point_has_the_replay_guard(): void
    {
        $entryPoints = $this->paymeraEntryPointMethods();

        $this->assertGreaterThanOrEqual(
            5,
            count($entryPoints),
            'fixture assumption: PaymentController holds at least the five surveyed '
                . 'handlePaymera* entry points. Fewer means the survey needs re-deriving, '
                . 'not that the invariant holds.'
        );

        $routed = $this->routedPaymentControllerMethods();

        $routedEntryPoints = array_values(array_intersect($entryPoints, $routed));
        sort($routedEntryPoints);

        $this->assertSame(
            ['handlePaymeraCallback', 'handlePaymeraTrigger'],
            $routedEntryPoints,
            'the set of ROUTED Paymera entry points has changed. Every one of them must '
                . 'carry the replay guard before this list is updated.'
        );

        $unguarded = [];

        foreach ($entryPoints as $method) {
            $source = $this->executableSourceOf(PaymentController::class, $method);

            if (!str_contains($source, 'freshPaymeraPendingOrNull(')) {
                $unguarded[] = $method;
            }
        }

        // THE INVARIANT.
        $this->assertSame(
            [],
            array_values(array_intersect($routedEntryPoints, $unguarded)),
            'a ROUTED Paymera entry point has no replay guard. A gateway retry would '
                . 'create a second order, a second stock decrement and a second merchant '
                . 'wallet credit. Add the freshPaymeraPendingOrNull() re-check inside the '
                . 'lock, as handlePaymeraCallback and handlePaymeraTrigger do.'
        );

        // THE CENSUS, so a NEW unguarded entry point cannot appear unnoticed.
        sort($unguarded);

        $this->assertSame(
            [
                'handlePaymeraCallback00',
                'handlePaymeraCallback_backup',
                'handlePaymeraTrigger00',
            ],
            $unguarded,
            'the set of UNGUARDED handlePaymera* methods has changed. If one was guarded, '
                . 'good -- shorten this list. If a new one appeared, it must be guarded '
                . 'before it is routed.'
        );
    }

    /**
     * The helper fails closed: a processed record yields null, and null is what
     * the callers treat as "create nothing".
     */
    public function test_the_helper_reports_an_already_processed_payment_as_null(): void
    {
        $this->putPending(['processed' => true, 'product_order_id' => '0202501010103']);

        $this->assertNull(
            $this->invokeHelper(self::REFERENCE),
            'an already-processed payment must not yield a usable record'
        );
    }

    /** A record that expired while the lock was awaited also yields null. */
    public function test_the_helper_reports_a_missing_record_as_null(): void
    {
        Cache::forget('paymera_pending_' . self::REFERENCE);

        $this->assertNull(
            $this->invokeHelper(self::REFERENCE),
            'a vanished record must not be substituted by a stale in-memory copy'
        );
    }

    /**
     * THE LEGITIMATE PATH. A first, unprocessed callback still yields its record,
     * so the fix refuses replays without refusing real payments.
     */
    public function test_the_helper_returns_the_record_for_a_first_callback(): void
    {
        $this->putPending(['processed' => false, 'product_order_id' => null]);

        $record = $this->invokeHelper(self::REFERENCE);

        $this->assertIsArray($record, 'a first callback must still be allowed to proceed');
        $this->assertSame('demo-store', $record['slug']);
        $this->assertSame('PAY-1', $record['payment_id']);
    }

    /*
    |--------------------------------------------------------------------------
    | Fixture
    |--------------------------------------------------------------------------
    */

    // =========================================================================================
    //  P1-ORD-13 criterion 3: the replay branch must not hand a stranger the buyer's order
    // =========================================================================================

    /**
     * THE REFERENCE IS NOT A SECRET AND IT WAS THE ONLY THING THE REPLAY BRANCH ASKED FOR.
     *
     * `GET /store/paymera/callback/{orderId}` is anonymous, and the reference is
     * `uniqid('paymera_')` -- about a million candidates per known second -- so it is guessable;
     * and it does not need guessing, because the URL sits in the buyer's history, the server's
     * access log and any Referer. The replayed branch flashed `$pending['product_order_id']` to
     * whoever presented it, and the thank-you page turns a flashed number into the order and a
     * minted link to it. So a stranger replaying the URL was shown the buyer's order.
     *
     * The number is now flashed only to the session that PLACED the order. A stranger still gets
     * the same redirect -- the response does not tell them whether the reference was real -- and
     * nothing is flashed with it.
     */
    public function test_a_replayed_callback_flashes_nothing_to_a_session_that_did_not_place_the_order(): void
    {
        $this->putPending(['processed' => true, 'order_id' => 4242, 'product_order_id' => '0202501010103']);

        $request = $this->blankRequest();          // a session that placed nothing
        $response = $this->controller()->handlePaymeraCallback($request, self::REFERENCE);

        $this->assertTrue($response->isRedirect());
        $this->assertSame(
            '',
            $request->session()->get('data'),
            "The buyer's order number was flashed to a session that never placed it, so the "
            . 'thank-you page would hand a stranger the order and a minted link to it.'
        );
    }

    /** THE POSITIVE CONTROL: the buyer's own replay still completes, or the refusal above is an outage. */
    public function test_the_buyers_own_replay_still_gets_their_number(): void
    {
        $this->putPending(['processed' => true, 'order_id' => 4242, 'product_order_id' => '0202501010104']);

        $request = $this->blankRequest();
        $request->setLaravelSession(app('session.store'));
        $request->session()->put(\App\Services\OrderCompleteBinding::SESSION_KEY, [4242]);

        $response = $this->controller()->handlePaymeraCallback($request, self::REFERENCE);

        $this->assertTrue($response->isRedirect());
        $this->assertSame('0202501010104', $request->session()->get('data'),
            "The buyer who placed the order was refused their own number: the replay branch is now an outage.");
    }

    /**
     * AND THE PENDING RECORD NAMES THE ORDER THE CALLBACK CREATED, not "the latest paymera order in
     * the store". That lookup was `->where('payment_type','paymera')->latest('id')->first()`, so two
     * concurrent Paymera payments in one store each recorded the OTHER buyer's number -- and the
     * record is exactly what the replay branch above hands back.
     *
     * Asserted on the SOURCE because reaching the writer needs a full checkout through
     * getProductStatus() with a live gateway poll, which this suite's own header records as out of
     * reach here. The behavioural half -- that what the record holds is only ever shown to the
     * session that placed it -- is driven above, so a wrong record cannot disclose anything either.
     */
    public function test_the_pending_record_is_written_from_the_session_binding_not_from_the_latest_order(): void
    {
        $source = (string) file_get_contents(app_path('Http/Controllers/PaymentController.php'));

        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        /*
         * WHITESPACE-INSENSITIVE, because the first cut of this ratchet was NOT and therefore proved
         * nothing. It asserted the absence of a two-line literal indented by twelve spaces; the live
         * construct in the TRIGGER was indented by twenty, so the forbidden code was present at
         * `handlePaymeraTrigger()` and this assertion passed anyway. Found by the tenant-authz review
         * of the first cut, not by this test. The pattern below matches the construct at any nesting,
         * with any spacing, in either handler.
         */
        $this->assertDoesNotMatchRegularExpression(
            "/'payment_type'\\s*,\\s*'paymera'\\s*\\)\\s*->latest\\(\\s*'id'\\s*\\)/", $code,
            'The pending record is written from "the latest paymera order in the store" again, which '
            . "records the other buyer's order under concurrency.");

        // The construct the two handlers must use instead: the writer's own before/after difference.
        $this->assertMatchesRegularExpression(
            '/private function orderWrittenBy\(callable \$write, bool \$sessionPlacedIt\)/',
            $code,
            'The order-writer helper that both Paymera handlers identify their order through is gone.'
        );

        $this->assertSame(2, preg_match_all('/\$this->orderWrittenBy\(/', $code),
            'Both Paymera handlers -- the browser callback and the anonymous trigger -- must identify '
            . 'the order they created through the helper; one of them is back to guessing.');
    }

    // ---- P1-ORD-13 FOLLOW-UP: a machine caller keeps no binding, and the order is named by difference ----

    /**
     * B1, FOUND BY THE TENANT-AUTHZ REVIEW OF THE FIRST CUT. `OrderCompleteBinding::remember()` lives
     * inside the shared order writer and writes through the `session()` helper, which resolves the
     * CURRENT HTTP REQUEST'S session. `handlePaymeraTrigger` is `POST /store/paymera/trigger/{orderId}`
     * -- anonymous and CSRF-exempt (bootstrap/app.php) -- and it drives that writer with a synthetic
     * `Request::create(...)`. So the order the trigger created from the VICTIM'S cached checkout was
     * bound into the session of whoever POSTed the trigger, and that session could then read the number
     * and the minted `/order/<encrypted>` link off the thank-you page with no query string at all.
     *
     * SCOPE, stated rather than implied. These four drive `orderWrittenBy()` directly, with a closure
     * standing in for the writer, because reaching the real writer needs a full checkout with a cart,
     * a plan and a gateway. They prove the helper's contract -- which session keeps the binding, and
     * which id is returned -- and nothing about Paymera itself.
     */
    public function test_a_machine_caller_keeps_no_binding_for_the_order_it_wrote(): void
    {
        $this->bindSession();
        \App\Services\OrderCompleteBinding::remember(11);        // an earlier order of this session

        $created = $this->orderWrittenBy(fn () => \App\Services\OrderCompleteBinding::remember(12), false);

        $this->assertSame(12, $created, 'The helper did not name the order the write created.');
        $this->assertSame([11], \App\Services\OrderCompleteBinding::bound(),
            'An anonymous caller came away holding a binding for an order it did not place.');
    }

    /** The buyer's own browser IS the caller on the callback path, so there the binding must stay. */
    public function test_the_buyers_own_request_keeps_the_binding(): void
    {
        $this->bindSession();

        $created = $this->orderWrittenBy(fn () => \App\Services\OrderCompleteBinding::remember(12), true);

        $this->assertSame(12, $created);
        $this->assertSame([12], \App\Services\OrderCompleteBinding::bound(),
            'The buyer lost the binding their own thank-you page resolves through.');
    }

    /**
     * N4. `end(bound())` -- what the first cut used -- is "the most recently first-seen id in this
     * session", which is the new order ONLY when a new order exists. When the writer returns without
     * creating one, `end()` names an EARLIER order of the same session, both the store and
     * payment-type checks pass, and the pending record is written naming the wrong order: the buyer's
     * replay then shows a previous order as this payment's confirmation. The difference cannot.
     */
    public function test_a_write_that_creates_nothing_names_no_order_even_with_an_earlier_one_bound(): void
    {
        $this->bindSession();
        \App\Services\OrderCompleteBinding::remember(11);

        $created = $this->orderWrittenBy(fn () => null, true);

        $this->assertNull($created,
            'A write that created nothing was reported as having created the session\'s earlier order.');
        $this->assertSame([11], \App\Services\OrderCompleteBinding::bound());
    }

    /**
     * The trigger puts the BUYER'S cached checkout into the caller's session for the writer to read
     * (`Session::put('request_data', ...)`), so a machine caller must not keep that either -- it is the
     * victim's cart and billing details.
     */
    public function test_a_machine_caller_keeps_no_request_data_either(): void
    {
        $this->bindSession();

        $created = $this->orderWrittenBy(function () {
            \Illuminate\Support\Facades\Session::put('request_data', ['telephone' => 'VICTIM-PHONE-FIXTURE']);
            \App\Services\OrderCompleteBinding::remember(12);
        }, false);

        $this->assertSame(12, $created);
        $this->assertFalse(\Illuminate\Support\Facades\Session::exists('request_data'),
            "The victim's cached checkout stayed in the anonymous caller's session.");
    }

    /** And a value that was already there is restored, not dropped. */
    public function test_an_existing_request_data_is_restored_for_a_machine_caller(): void
    {
        $this->bindSession();
        \Illuminate\Support\Facades\Session::put('request_data', ['mine' => 'KEEP-FIXTURE']);

        $this->orderWrittenBy(function () {
            \Illuminate\Support\Facades\Session::put('request_data', ['telephone' => 'VICTIM-PHONE-FIXTURE']);
            \App\Services\OrderCompleteBinding::remember(12);
        }, false);

        $this->assertSame(['mine' => 'KEEP-FIXTURE'], \Illuminate\Support\Facades\Session::get('request_data'));
    }

    /*
     * ------------------------------------------------------------------------------------------
     * F96 — THE WINDOW THE TEN-SECOND LOCK LEAVES OPEN, 2026-10-29.
     *
     * `processed` is not written until the ORDER WRITER returns, and that writer makes external
     * calls — `Utility::sendEmailTemplate()` and `Utility::SendMsgs()` — inside its stock loop.
     * An SMTP or SMS timeout alone can exceed the lock's ten seconds. If the writer runs long the
     * lock LAPSES while `processed` is still false, a concurrent replay acquires it, the re-check
     * inside the lock still reads false, and a second order is written for one payment.
     *
     * The project's own reasoning establishes the premise one layer down: the comment at
     * `PaymentController:2878-2886` says the database transaction was deliberately placed OUTSIDE
     * that loop because *"holding row locks across an external call is the mistake this boundary
     * exists to avoid"*. The cache lock is held across exactly those calls.
     *
     * `Cache::lock` on the `array` driver these tests run under is process-local, so no Feature
     * test can stage two genuinely concurrent requests and watch the lock lapse. What a test CAN
     * do is stage the STATE that interleave produces — the claim already taken, `processed` still
     * false — and measure what the handler does with it. That is the thing the fix changes.
     * ------------------------------------------------------------------------------------------
     */

    /*
     * ------------------------------------------------------------------------------------------
     * F96 — THE WINDOW THE TEN-SECOND LOCK LEAVES OPEN, closed 2026-10-29.
     *
     * `processed` is not written until the ORDER WRITER returns, and that writer makes external
     * calls — `Utility::sendEmailTemplate()` and `Utility::SendMsgs()` — inside its stock loop.
     * An SMTP or SMS timeout alone can exceed the lock's ten seconds. If the writer runs long the
     * lock LAPSES while `processed` is still false, a concurrent replay acquires it, the re-check
     * inside the lock still reads false, and a second order is written for one payment.
     *
     * The project's own reasoning establishes the premise one layer down: the comment at
     * `PaymentController:2878-2886` says the database transaction was deliberately placed OUTSIDE
     * that loop because *"holding row locks across an external call is the mistake this boundary
     * exists to avoid"*. The cache lock is held across exactly those calls.
     *
     * THE FIX IS A DURABLE CLAIM, not a longer lock. `Cache::add()` is atomic and has no owner to
     * expire, so the first caller to add the key wins and every later one is refused for as long
     * as the key lives — whatever the first caller is doing meanwhile. It is the same primitive
     * `claimGatewayReturn()` already uses for every other gateway return; this path was using a
     * mutex where the others use a claim. Raising the TTL fixes nothing (no TTL is long enough if
     * the writer is waiting on SMTP) and writing `processed` first is worse (a crash mid-write
     * would leave a payment marked processed with no order).
     *
     * THESE ARE SOURCE ASSERTIONS, AND THE REASON IS MEASURED RATHER THAN ASSUMED. Three driven
     * cases were written first and all three were worthless: a `fwrite(STDERR)` probe on the claim
     * line printed NOTHING for any of them, because `handlePaymeraCallback` polls Paymera before
     * the lock and returns at the `status != 'A'` gate — and even with `Http::fake()` supplying
     * an `'A'`, a later gate still turns the request away before the lock region. One of the three
     * was GREEN anyway, because the early return is also a redirect to the completion page, so it
     * was passing for a reason that had nothing to do with the guard it named.
     *
     * Staging a Paymera callback all the way to the writer is fixture work this file deliberately
     * avoids — `test_the_recheck_sits_between_the_lock_and_the_order_write` pins the ordering of
     * this very region the same way, and for the same reason. The claim is therefore **not
     * driven**, and that is stated rather than implied.
     * ------------------------------------------------------------------------------------------
     */

    /** @return array<string, string> the two ROUTED entry points, as the file's own census names them */
    public static function guardedEntryPoints(): array
    {
        return [
            'the browser callback' => ['handlePaymeraCallback'],
            'the gateway trigger' => ['handlePaymeraTrigger'],
        ];
    }

    /**
     * @dataProvider guardedEntryPoints
     */
    public function test_the_durable_claim_sits_between_the_recheck_and_the_order_write(string $method): void
    {
        $source = $this->executableSourceOf(PaymentController::class, $method);

        $recheck = strpos($source, 'freshPaymeraPendingOrNull(');
        $claim = strpos($source, "Cache::add(\$ssPaymeraClaim");
        $write = strpos($source, '$this->getProductStatus(');

        $this->assertNotFalse($recheck, $method . ' must still re-read the pending record');
        $this->assertNotFalse(
            $claim,
            $method . ' takes no durable claim, so the ten-second lock is the only thing standing'
            . ' between a slow writer and a second order for one payment'
        );
        $this->assertNotFalse($write, $method . ' must still create the order');

        $this->assertGreaterThan(
            $recheck,
            $claim,
            $method . ': the claim must come AFTER the re-check — ahead of it, a request that the'
            . ' re-check would have turned away still consumes the claim'
        );
        $this->assertLessThan(
            $write,
            $claim,
            $method . ': the claim must be taken BEFORE the writer runs, because the window it'
            . ' closes is exactly the time the writer spends inside the lock'
        );
    }

    /**
     * @dataProvider guardedEntryPoints
     */
    public function test_a_failed_write_forgets_the_claim_so_a_retry_is_still_possible(string $method): void
    {
        $source = $this->executableSourceOf(PaymentController::class, $method);

        /*
         * POSITIONAL, NOT A CONTIGUOUS REGEX. The first version required `Cache::forget` to be
         * the very next token after the catch's brace, and failed against code that is correct
         * because an explanatory comment sits between them. The file's own
         * `test_the_recheck_sits_between_the_lock_and_the_order_write` asserts ordering with
         * `strpos`; this does the same.
         */
        $catch = strpos($source, 'catch (\\Throwable $ssPaymeraError)');
        $forget = strpos($source, 'Cache::forget($ssPaymeraClaim);');
        $rethrow = strpos($source, 'throw $ssPaymeraError;');

        $this->assertNotFalse(
            $catch,
            $method . ': a failed write must be caught so the claim can be released (when nothing'
            . ' committed -- see test_the_claim_is_released_only_when_no_order_committed).'
            . ' `processed` is only set on success, so a failed write is retryable today, and a'
            . ' claim that outlived a failure would refuse that retry for two hours. That is the'
            . ' failure mode the "write processed first" patch was rejected for, and the claim'
            . ' must not reintroduce it.'
        );
        $this->assertNotFalse($forget, $method . ': the claim is never forgotten, so a failed write blocks the retry');
        $this->assertNotFalse($rethrow, $method . ': the failure must be rethrown, not swallowed');

        $this->assertGreaterThan($catch, $forget, $method . ': the claim must be forgotten inside the catch');
        $this->assertGreaterThan($forget, $rethrow, $method . ': forget the claim, then rethrow, not the other way round');
    }

    /**
     * THE RELEASE IS CONDITIONAL, AND THE CONDITION IS THE DEFECT THE REVIEW FOUND IN THIS FIX.
     *
     * The first version of the catch released the claim unconditionally and said in a comment that
     * the write "did not complete". `getProductStatus()` commits at `PaymentController:3187` and
     * then runs ~110 lines of unguarded post-commit work whose last statement is
     * `OrderCompleteBinding::remember()`, so a throw in that tail leaves an order written,
     * `processed` still false, and no in-process marker of either. Releasing the claim there hands
     * the next replay the second order this guard exists to prevent.
     *
     * The behaviour of the condition itself is DRIVEN, in `PaymeraPostCommitClaimTest`. What this
     * pins is that the catch asks the question **before** it releases anything — an ordering a
     * driven test cannot reach, for the reason this file's header records.
     *
     * @dataProvider guardedEntryPoints
     */
    public function test_the_claim_is_released_only_when_no_order_committed(string $method): void
    {
        $source = $this->executableSourceOf(PaymentController::class, $method);

        $catch = strpos($source, 'catch (\\Throwable $ssPaymeraError)');
        $asked = strpos($source, 'paymeraOrderCommittedSince(');
        $forget = strpos($source, 'Cache::forget($ssPaymeraClaim);');

        $this->assertNotFalse($catch, $method . ': the failure must still be caught');
        $this->assertNotFalse(
            $asked,
            $method . ' releases its claim without asking whether an order committed. A throw in'
            . ' the writer\'s post-commit tail leaves the order written and `processed` false, so'
            . ' an unconditional release hands the next replay a SECOND order for one payment.'
        );
        $this->assertNotFalse($forget, $method . ': a failed write with nothing committed must still release the claim');

        $this->assertGreaterThan($catch, $asked, $method . ': the question belongs inside the catch');
        $this->assertLessThan(
            $forget,
            $asked,
            $method . ': the claim is released before the question is asked, so the answer cannot'
            . ' affect it'
        );

        /*
         * AND THE ANSWER MUST GATE THE RELEASE, not merely precede it. A second review produced
         * the mutation that defeated the three assertions above:
         *
         *     if ($this->paymeraOrderCommittedSince(...)) { Log::critical(...); }
         *     Cache::forget($ssPaymeraClaim);          // unconditional again
         *
         * Every ordering still holds, the question is still asked, and the defect is fully back.
         * The `else` between the two is what makes the release conditional, so that is what is
         * asserted. (`executableSourceOf()` strips comments, so no comment can satisfy this.)
         */
        $between = substr($source, $asked, $forget - $asked);

        $this->assertMatchesRegularExpression(
            '/\belse\b/',
            $between,
            $method . ': the question is asked and then the claim is released regardless of the'
            . ' answer. That is the unconditional release this case exists to prevent -- it keeps'
            . ' the call, so ordering alone cannot tell the two apart.'
        );
    }

    public function test_the_claim_key_is_the_payment_reference_and_nothing_else(): void
    {
        /*
         * The key names the PAYMENT, not the caller. A key carrying anything a caller supplies
         * would let one request block another's payment; a key carrying less than the reference
         * would let one payment's claim block a different payment.
         */
        foreach (array_keys(self::guardedEntryPoints()) as $what) {
            [$method] = self::guardedEntryPoints()[$what];
            $source = $this->executableSourceOf(PaymentController::class, $method);

            $this->assertStringContainsString(
                "\$ssPaymeraClaim = 'paymera_claim_' . \$orderId;",
                $source,
                $what . ': the claim key must be built from the payment reference alone'
            );
        }
    }

    /**
     * THE CLAIM MUST EXPIRE WELL INSIDE THE LIFE OF THE RECORD A RETRY NEEDS.
     *
     * This is the second defect the review found in the F96 remedy, and it is the one direction in
     * which the first version made things WORSE than the ten-second lock it replaced. `catch` does
     * not run when a worker DIES -- an fpm `request_terminate_timeout`, a `memory_limit` E_ERROR,
     * a SIGKILL, a deploy restart -- so a claim can be stranded with `processed` still false. While
     * it stands every retry is refused, and the gateway's own retry cannot help: F97 measured the
     * trigger route to sit behind `auth`, so the buyer's refresh of the anonymous callback is the
     * only retry path there is.
     *
     * The first version used TWO HOURS, which is exactly the life of `paymera_pending_<reference>`.
     * A claim that outlives that record turns a captured payment into **no order at all**: the
     * retry is refused until the claim expires, and by then the record it needs is gone. The
     * constraint is therefore not a preference — it is "strictly shorter, by enough that a buyer
     * who comes back still finds their payment".
     *
     * Both halves are asserted, because the margin is only meaningful against the record's own
     * TTL: shorten the record and this test asks you to revisit the claim.
     */
    public function test_the_claim_expires_well_before_the_pending_record_does(): void
    {
        $claim = new \ReflectionClassConstant(PaymentController::class, 'PAYMERA_CLAIM_MINUTES');
        $minutes = (int) $claim->getValue();

        /*
         * A REAL LOWER BOUND. The first version asserted only `> 0`, which a second review pointed
         * out would pass a one-minute claim -- reopening F96 far more often than today while still
         * satisfying the test. The floor is "comfortably above the ten-second lock it supplements":
         * below a couple of minutes the claim is barely more than the mutex whose lapsing is the
         * entire finding.
         */
        $this->assertGreaterThanOrEqual(
            2,
            $minutes,
            'the claim is barely longer than the ten-second lock it supplements, so it reopens the'
            . ' very window F96 names: the writer outruns it and a replay writes a second order'
        );

        /*
         * WHERE THE RECORD'S TWO-HOUR LIFE IS DECIDED, AND THIS ASSERTION MOVED BECAUSE THE ANSWER
         * MOVED. [2026-10-29]
         *
         * It used to read the literal `now()->addHours(2)` out of `handlePaymeraCallback`'s own
         * `Cache::put`. **F10** replaced that with `$this->paymeraRecordDeadline($pending)`: a put
         * minting a fresh two hours on every pass made the record RENEWABLE, and one anonymous GET
         * an hour kept it alive past the writer's 48-hour claim. This case failed on that change
         * and its message said to re-derive the number, which is exactly what it is for.
         *
         * The two hours still exist -- they are now stamped ONCE, at initiation, as
         * `$ssPaymeraDeadline = now()->addHours(2)` in `PaymentService::processPaymera()`. So both
         * halves are pinned, and more tightly than before: the figure the ceiling below is measured
         * against, and the fact that the callback CONSULTS the stored deadline instead of minting
         * its own.
         */
        $service = $this->executableSourceOf(\App\Services\PaymentService::class, 'processPaymera');

        $this->assertStringContainsString(
            '$ssPaymeraDeadline = now()->addHours(2);',
            $service,
            'the pending record is no longer stamped with a two-hour deadline at initiation, so the'
            . ' margin this test asserts is measured against the wrong number -- re-derive it'
        );

        $source = $this->executableSourceOf(PaymentController::class, 'handlePaymeraCallback');

        $this->assertStringContainsString(
            '$this->paymeraRecordDeadline($pending)',
            $source,
            'the callback writes the record with a TTL of its own again instead of the deadline the'
            . ' record carries. That is F10: the record becomes renewable, and the invariant this'
            . ' test exists to protect -- the claim fits inside the record\'s life -- stops holding'
            . ' because the record no longer has a fixed life'
        );

        /*
         * THE CEILING IS THE RULE THE CONSTANT IS CHOSEN BY: half the record's life, so a stranded
         * buyer has as long to come back as the claim held them off. At or beyond the record's own
         * two hours the strand is PERMANENT -- a captured payment with no order, ever -- which is
         * what the first review found when this was two hours.
         */
        $this->assertLessThanOrEqual(
            120 / 2,
            $minutes,
            'the claim now exceeds half the life of the pending record a retry needs. A worker that'
            . ' dies mid-write strands it with `processed` still false, and the closer the claim'
            . ' gets to the record\'s two hours the less of a window the buyer has to recover --'
            . ' at or beyond it, none.'
        );
    }

    /**
     * WHAT REALLY BOUNDS A PAYMERA RETRY IS NOT THIS FILE'S CLAIM, AND THE REASONING DEPENDS ON IT.
     *
     * `PAYMERA_CLAIM_MINUTES` was shortened from two hours so that a claim stranded by a worker
     * death would not refuse a legitimate retry until after the pending record had expired. A
     * measurement pass then established that the retry is mostly refused by something else
     * entirely: the shared order writer takes its own claim,
     * `gateway-return-claim:checkout:<sha1 nonce>`, **above every write** and for
     * `GuardsGatewayReturn::GATEWAY_RETURN_CLAIM_TTL` -- 48 hours -- and nothing releases it when
     * the writer throws. Past that line a retry meets `gatewayReturnReplayRefusal()` however short
     * this file's claim is, and the callback then marks the payment `processed` with no order
     * identified. That is F186.
     *
     * [2026-10-29] THE `processed` HALF IS FIXED; THE CLAIM HALF IS STILL RECORDED RATHER THAN
     * FIXED. `processed` is now written only when an order is established to exist
     * (`paymeraOrderEstablished()`), so the record no longer asserts a checkout that does not
     * exist and a captured payment with no order is logged at `critical`. What that does NOT do is
     * recover the capture: the paragraph above is correct, the 48-hour claim IS taken on this path
     * (`PaymentService::process()` stamps `checkout_nonce` into the request before the gateway
     * switch and `processPaymera()` carries it into `request_data`), so the retry the new
     * `processed => false` permits meets `gatewayReturnReplayRefusal()` and writes nothing.
     * Releasing that claim is the general undo `releaseGatewayReturnClaim()`'s own docblock
     * forbids, and it stays BLOCKED for an owner decision.
     *
     * A DRAFT OF THIS NOTE CLAIMED THE OPPOSITE — that the claim is never taken for Paymera — and
     * overwrote the correct paragraph above with it. It was wrong, and the way it went wrong is
     * worth keeping: it rested on a grep for the LITERAL `checkout_nonce` in `PaymentService.php`,
     * which finds nothing because the key is only ever written as `self::CHECKOUT_NONCE_KEY`. A
     * census keyed on a literal cannot see a constant. The mandated review caught it, and the
     * premise is now DRIVEN rather than asserted in prose, by
     * `PaymeraCapturedWithoutOrderTest::test_the_paymera_state_really_does_carry_a_checkout_nonce`
     * and `::test_the_writer_claims_the_paymera_return_and_refuses_the_replay`.
     *
     * So the ORDERING is load-bearing for the comment at `PAYMERA_CLAIM_MINUTES`, and it is
     * asserted rather than assumed. Shorten the writer's claim below the record's life and the
     * recovery analysis changes materially -- this fails and asks for it to be re-derived.
     */
    public function test_the_writers_own_claim_is_what_really_bounds_a_retry(): void
    {
        $writerClaim = (int) (new \ReflectionClassConstant(
            \App\Http\Controllers\Concerns\GuardsGatewayReturn::class,
            'GATEWAY_RETURN_CLAIM_TTL'
        ))->getValue();

        $paymeraClaim = 60 * (int) (new \ReflectionClassConstant(
            PaymentController::class,
            'PAYMERA_CLAIM_MINUTES'
        ))->getValue();

        $pendingRecord = 2 * 60 * 60;

        $this->assertGreaterThan(
            $paymeraClaim,
            $writerClaim,
            'the writer\'s own gateway-return claim is now SHORTER than this path\'s claim, which'
            . ' inverts the reasoning recorded at PAYMERA_CLAIM_MINUTES: that comment says the'
            . ' writer\'s claim is what really refuses a retry'
        );

        $this->assertGreaterThan(
            $pendingRecord,
            $writerClaim,
            'the writer\'s claim no longer outlives the pending record. While it did, a failed'
            . ' Paymera write was unrecoverable past the writer\'s own claim line whatever this'
            . ' file did -- which is why F186 is the fix target and the claim length is not.'
            . ' Re-derive both before changing either.'
        );
    }

    private function bindSession(): void
    {
        $request = \Illuminate\Http\Request::create('/store/paymera/trigger/x', 'POST');
        $request->setLaravelSession(app('session.store'));
        $this->app->instance('request', $request);
    }

    /** Private by design; reflection keeps it that way. */
    private function orderWrittenBy(callable $write, bool $sessionPlacedIt): ?int
    {
        $m = new \ReflectionMethod(PaymentController::class, 'orderWrittenBy');
        $m->setAccessible(true);

        return $m->invoke($this->controller(), $write, $sessionPlacedIt);
    }

    /**
     * AUTHORISE THE CALLER, because F210 gave `handlePaymeraTrigger()` an ownership gate and these
     * three cases drive the method DIRECTLY — bypassing the middleware, with nobody logged in.
     *
     * Found by the independent review of F210 rather than by me: without this the gate's
     * `if (! $ssCaller)` limb returns 404 and all three assertions below read `null`, which is
     * three UNDECLARED Feature failures and a broken known-outcome gate. The gate is correct; the
     * fixtures predated it.
     *
     * THE OPERATOR LIMB RATHER THAN OWNERSHIP, and the reason is the fixture's own shape.
     * `putPending()` hardcodes `'store_id' => 1`, so making the caller OWN that store would tie
     * these cases to whichever row happens to get id 1 — autoincrement luck, in a class that
     * carries no `RefreshDatabase`. `UserPolicy::isOperator()` reads only `->type` and
     * short-circuits before `creatorId()`, so an unsaved model is enough and no database row is
     * created. These cases are about REPLAY IDEMPOTENCY, not about tenancy — F210's own
     * `PaymeraTriggerOwnershipTest` is where the gate is measured — so the right move is to
     * satisfy the gate and leave each case measuring what it was written to measure.
     */
    private function asAuthorisedCaller(): void
    {
        $operator = new \App\Models\User;
        $operator->type = 'super admin';

        $this->actingAs($operator);
    }

    private function putPending(array $overrides = []): void
    {
        Cache::put('paymera_pending_' . self::REFERENCE, array_merge([
            'slug' => 'demo-store',
            'payment_id' => 'PAY-1',
            'status' => 'A',
            'processed' => false,
            'order_id' => null,
            'product_order_id' => null,
            'expected_total' => '100.00',
            'request_data' => ['payment_type' => 'paymera', 'store_id' => 1],
        ], $overrides), now()->addHour());
    }

    private function controller(): PaymentController
    {
        return new PaymentController(app(PaymentService::class));
    }

    private function blankRequest(): \Illuminate\Http\Request
    {
        $request = \Illuminate\Http\Request::create('/paymera/callback', 'GET');
        $request->setLaravelSession(app('session.store'));

        return $request;
    }

    /** The helper is private by design; reflection keeps it that way. */
    private function invokeHelper(string $reference): ?array
    {
        $m = new \ReflectionMethod(PaymentController::class, 'freshPaymeraPendingOrNull');
        $m->setAccessible(true);

        return $m->invoke($this->controller(), $reference);
    }

    /** See OrderWriteTransactionTest::executableSourceOf(). */
    private function executableSourceOf(string $class, string $method): string
    {
        $m = new \ReflectionMethod($class, $method);

        $source = implode('', array_slice(
            file($m->getFileName()),
            $m->getStartLine() - 1,
            $m->getEndLine() - $m->getStartLine() + 1
        ));

        $code = '';

        foreach (token_get_all('<?php ' . $source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
    }

    /**
     * The `handlePaymera*` methods PaymentController actually declares.
     *
     * Read by reflection off the class, so the list cannot drift from the code
     * the way a typed list does.
     *
     * @return list<string>
     */
    private function paymeraEntryPointMethods(): array
    {
        $methods = [];

        foreach ((new \ReflectionClass(PaymentController::class))->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== PaymentController::class) {
                continue;
            }

            if (str_starts_with($method->getName(), 'handlePaymera')) {
                $methods[] = $method->getName();
            }
        }

        sort($methods);

        return $methods;
    }

    /**
     * PaymentController methods reachable through the BOOTED router.
     *
     * Deliberately not a grep of routes/: this follows the application's own
     * route resolution, so a registration inside a group, a package route file
     * or anything added dynamically is included.
     *
     * @return list<string>
     */
    private function routedPaymentControllerMethods(): array
    {
        $methods = [];

        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            $action = $route->getActionName();

            if (!str_contains($action, PaymentController::class . '@')) {
                continue;
            }

            $methods[] = substr($action, strrpos($action, '@') + 1);
        }

        $methods = array_values(array_unique($methods));
        sort($methods);

        return $methods;
    }
}
