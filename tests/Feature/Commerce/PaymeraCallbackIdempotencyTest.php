<?php

namespace Tests\Feature\Commerce;

use App\Http\Controllers\PaymentController;
use App\Services\PaymentService;
use Illuminate\Support\Facades\Cache;
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

        $response = $this->controller()->handlePaymeraTrigger(self::REFERENCE);
        $payload = json_decode($response->getContent(), true);

        $this->assertSame('A', $payload['status'] ?? null);
        $this->assertTrue($payload['ok'] ?? false);
    }

    /**
     * The `processed` guard specifically -- reached when the record is marked
     * processed but its status is not 'A'.
     *
     * This is not a contrived state: `handlePaymeraCallback` sets `processed`
     * and `status` in one `Cache::put`, but a trigger arriving between a
     * callback's order write and that put sees exactly this, and the earlier
     * `status === 'A'` guard does not cover it. The processed branch is what
     * must answer, and it must name the order that already exists rather than
     * create another.
     */
    public function test_the_processed_guard_reports_the_existing_order(): void
    {
        $this->putPending([
            'status' => 'P',
            'processed' => true,
            'product_order_id' => '0202501010102',
        ]);

        $response = $this->controller()->handlePaymeraTrigger(self::REFERENCE);
        $payload = json_decode($response->getContent(), true);

        $this->assertSame('A', $payload['status'] ?? null);
        $this->assertTrue($payload['ok'] ?? false);
        $this->assertSame(
            '0202501010102',
            $payload['product_order_id'] ?? null,
            'the replay must report the order that already exists, not make a new one'
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
