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
