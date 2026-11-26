<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * `PlatformOperatorOnly` MUST CHECK WHAT THE PRINCIPAL IS BEFORE IT READS WHAT IT CLAIMS TO BE.
 *
 * The middleware asked `UserPolicy::isOperator($user)`, which takes an UNTYPED `$actor` and reads
 * `$actor->type` — and `customers` has a `type` column, with `'type'` in `Customer::$fillable`. So
 * a `Customer` row whose `type` read `super admin` was admitted by the only check on 14 routes,
 * among them `plan.order.refund`, the `plan-coupon` resource, `order.approve`/`order.reject`, the
 * three `platform/*` screens and LynomiaChat's super-admin group — handlers whose own docblocks
 * say they rely on this middleware.
 *
 * LATENT RATHER THAN LIVE, measured on both links before the fix was called hardening: nothing in
 * production makes `customers` the default guard (`shouldUse(` appears zero times outside tests
 * and prose), and every `Customer` creator in `app/` hard-codes `'customer'`. Two independent
 * missing links. Fixed anyway — the blast radius if either ever closes is every route above, and
 * the cost is one `instanceof` clause.
 *
 * Found by the independent authorization review of `/platform/documentation`, whose controller
 * carries the identical guard; this file pins the MIDDLEWARE, which the review found had the same
 * shape and fourteen times the reach.
 *
 * WHY THIS DRIVES A ROUTE RATHER THAN THE CLASS. A unit test of the predicate would not have
 * caught a middleware that never calls it, and the thing being asserted is "a Customer cannot
 * reach this URL". `platform/analytics` is chosen because it is one of the fourteen, is a plain
 * read, and changes nothing if the refusal ever fails — unlike `plan.order.refund`, where a
 * regression in this test would move money.
 */
class PlatformOperatorOnlyPrincipalTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const GUARDED_URI = '/platform/analytics';

    /**
     * THE PREMISE. If `customers.type` could not hold this value, the defect would be unreachable
     * and the assertion below would prove nothing.
     */
    #[Test]
    public function test_the_premise_a_customer_row_can_carry_the_operators_type_word(): void
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);

        $customer = Customer::create([
            'name' => 'Impostor',
            'email' => 'impostor' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => bcrypt('secret-probe'),
            'store_id' => $store->id,
            'created_by' => $merchant->id,
            'type' => 'super admin',
        ]);

        $this->assertSame(
            'super admin',
            $customer->fresh()->getAttribute('type'),
            'premise: `type` is fillable on Customer and the value persisted. If this ever stops '
            . 'being true the defect is unreachable and this file can go — but delete it on that '
            . 'evidence, not on the assumption.'
        );
    }

    /**
     * THE DEFECT.
     */
    #[Test]
    public function test_a_customer_carrying_the_operators_type_is_refused(): void
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);

        $customer = Customer::create([
            'name' => 'Impostor',
            'email' => 'impostor' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => bcrypt('secret-probe'),
            'store_id' => $store->id,
            'created_by' => $merchant->id,
            'type' => 'super admin',
        ]);

        $response = $this->actingAs($customer, 'customers')->get(self::GUARDED_URI);

        /*
         * EXACTLY 403, AND `assertNotSame(200, ...)` IS NOT GOOD ENOUGH — measured, on this file.
         *
         * The first version of this test asserted "not 200" and SURVIVED the mutation that
         * removes the `instanceof` clause. Probing the mutant showed why: without the class check
         * the Customer gets PAST the middleware, reaches the handler, and the handler — which
         * expects a `User` — raises an HTTP **500**. So "not 200" was satisfied by the crash
         * rather than by any refusal, and the test would have gone on passing with the guard
         * removed.
         *
         * A 500 IS NOT A REFUSAL. It means the principal was admitted and the code behind the
         * door happened to break. On this route that is merely loud; on another of the fourteen
         * the handler might cope and answer, which is the whole point of fixing the middleware
         * rather than relying on what is behind it.
         *
         * So the assertion names the one status that means "the guard refused this principal".
         * With the clause: 403. Without it: 500. The mutation is now CAUGHT.
         */
        $this->assertSame(
            403,
            $response->getStatusCode(),
            'A Customer carrying `type = "super admin"` was not REFUSED by the operator guard. '
            . '`UserPolicy::isOperator()` reads `->type` off whatever it is handed, and a Customer '
            . 'has that column, so the principal\'s CLASS has to be checked first. A 500 here '
            . 'means it got past the guard and broke the handler, which is not the same thing.'
        );
    }

    /**
     * CONTROL ONE. The real operator must still get in, or the guard refuses everybody and the
     * assertion above is satisfied by a broken screen rather than by a working check.
     */
    #[Test]
    public function test_control_the_platform_operator_is_still_admitted(): void
    {
        $operator = $this->makePlatformAdmin();

        $response = $this->actingAs($operator)->get(self::GUARDED_URI);

        $this->assertSame(
            200,
            $response->getStatusCode(),
            'The platform operator was refused their own screen. CLAUDE.md records that there is '
            . 'no super-admin bypass in this codebase, so an authorization check that refuses the '
            . 'operator is the characteristic failure here, not an edge case.'
        );
    }

    // =============================================================================================
    //  GAP-307 -- THE OTHER THREE ROUTES IN THIS GATE'S `plan/order actions` BUCKET
    // =============================================================================================

    /**
     * EVERY ROUTE IN THE BUCKET, NOT JUST THE ONE. The assertions above drove
     * `/platform/analytics` alone, so they proved the MIDDLEWARE is class-safe and said nothing
     * about the other routes it is the sole control on.
     *
     * These four are the `plan/order actions` bucket, and `order_approve` is the one that matters
     * most: it sets `payment_status = 'Approved'` and calls `assignPlan()`, so it is a
     * money-to-entitlement write. None of the four uses route-model binding, so
     * `SubstituteBindings` cannot answer 404 ahead of the gate either -- the gate really is what
     * stands in front of them.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function bucketRoutes(): array
    {
        return [
            'plan refund' => ['get', '/refund/1/1'],
            'bank order approve' => ['get', '/order_approve/1'],
            'bank order reject' => ['get', '/order_reject/1'],
            'plan disable' => ['post', '/plan-disable'],
        ];
    }

    #[Test]
    #[DataProvider('bucketRoutes')]
    public function test_a_customer_carrying_the_operators_type_is_refused_on_every_bucket_route(
        string $verb,
        string $uri
    ): void {
        $customer = $this->impostor();

        $response = $this->actingAs($customer, 'customers')->{$verb . 'Json'}($uri);

        $this->assertSame(
            403,
            $response->getStatusCode(),
            'EXACTLY 403, for the reason the first case in this file records: a 500 from the '
            . 'handler also fails `assertNotSame(200, ...)`, so only the exact code tells a refusal '
            . 'from a crash. Route ' . strtoupper($verb) . ' ' . $uri
            . ' answered ' . $response->getStatusCode()
        );
    }

    /** THE CONTROL on every route: the operator is not refused by any of them. */
    #[Test]
    #[DataProvider('bucketRoutes')]
    public function test_control_the_operator_is_not_refused_on_any_bucket_route(
        string $verb,
        string $uri
    ): void {
        $operator = $this->makePlatformAdmin();

        $response = $this->actingAs($operator)->{$verb . 'Json'}($uri);

        $this->assertNotSame(
            403,
            $response->getStatusCode(),
            'The platform operator was refused their own route, which is the characteristic failure '
            . 'in this codebase -- there is no super-admin bypass, so a check that refuses them is '
            . 'a lockout rather than a tightening. Route ' . strtoupper($verb) . ' ' . $uri
        );
    }

    /**
     * AND NOW THE HALF AN HTTP TEST CANNOT SEE. GAP-307.
     *
     * `PlatformOperatorOnly` `abort(403)`s, so every case above passes whether or not the HANDLER
     * carries a check of its own -- the middleware answers first and the handler is never reached.
     * That is exactly the shape that makes a gate the SOLE control on a money write, and
     * CLAUDE.md records the way it breaks: a later route re-declaration for the same method+URI
     * silently replaces the earlier one and drops its middleware.
     *
     * So each handler is called DIRECTLY, with the middleware out of the path, and must refuse a
     * principal that is not a `User`. `planDisable` is the only one of the four with any
     * in-handler check at all today, and it is CLASS-BLIND: `$actor->type !== PLATFORM_ADMIN` reads
     * `->type` off whatever it is handed, and `'type'` is in `Customer::$fillable`. It also uses a
     * raw `!==` rather than `UserPolicy::isOperator()`, so it misses that helper's PAD-SPACE
     * normalisation as well.
     *
     * NOT A CLAIM THAT THIS IS LIVE TODAY. These are `web` routes behind `auth`, so the default
     * guard holds a `User` in production and the middleware runs first; the register records the
     * defect as LATENT for exactly that reason. This drives the handler's own check as a check --
     * a control is correct in its own right or it is decoration.
     *
     * KEYED ON THE REASON, not merely on "something refused": a handler with NO check reaches its
     * own "not found" branch for the absent fixtures, which is a 302 redirect and would satisfy a
     * loose assertion. The refusal must be the GATE's.
     */
    #[Test]
    public function test_each_handler_refuses_a_non_user_principal_on_its_own(): void
    {
        $customer = $this->impostor();

        // The handler reads `auth()->user()` -- the DEFAULT guard. Signing the impostor in there is
        // what puts a non-`User` in front of the handler's own check with no middleware in the way.
        $this->actingAs($customer);

        $refusals = [];

        $refusals['planDisable'] = $this->refusalShapeOf(
            fn () => (new \App\Http\Controllers\PlanController())->planDisable(
                \Illuminate\Http\Request::create('/plan-disable', 'POST', ['plan_id' => 1, 'is_disable' => 1])
            )
        );

        $refusals['refund'] = $this->refusalShapeOf(
            fn () => (new \App\Http\Controllers\PlanController())->refund(
                \Illuminate\Http\Request::create('/refund/1/1', 'GET'), 1, 1
            )
        );

        $refusals['orderapprove'] = $this->refusalShapeOf(
            fn () => (new \App\Http\Controllers\BankTransferController())->orderapprove(1)
        );

        $refusals['orderreject'] = $this->refusalShapeOf(
            fn () => (new \App\Http\Controllers\BankTransferController())->orderreject(1)
        );

        foreach ($refusals as $handler => $shape) {
            $this->assertSame(
                'REFUSED',
                $shape,
                $handler . ' admitted a principal that is not an App\\Models\\User. Its own check is '
                . 'either absent or class-blind, which leaves PlatformOperatorOnly as the sole '
                . 'control -- and a route re-declaration drops middleware silently. Saw: ' . $shape
            );
        }
    }

    /**
     * Did the gate refuse, or did the handler simply run and fail on its own missing fixtures?
     *
     * `REFUSED` only for the gate's own answer: a 403, or a redirect carrying the gate's message.
     * A handler with no gate reaches "Order not found." / "Something is wrong", which is also a
     * 302 -- so the MESSAGE is what separates them, and that is why this returns a word rather
     * than a boolean.
     */
    private function refusalShapeOf(callable $call): string
    {
        try {
            $response = $call();
        } catch (\Illuminate\Auth\Access\AuthorizationException|\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return $e->getStatusCode() === 403 ? 'REFUSED' : 'THREW ' . $e->getStatusCode();
        } catch (\Throwable $e) {
            return 'THREW ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 80);
        }

        $status = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 0;

        if ($status === 403) {
            return 'REFUSED';
        }

        $said = '';

        if ($response instanceof \Illuminate\Http\RedirectResponse) {
            $said = (string) ($response->getSession()?->get('error') ?? '');
        } elseif ($response instanceof \Illuminate\Http\JsonResponse) {
            $said = json_encode($response->getData(true));
        }

        if (str_contains($said, 'Permission denied')) {
            return 'REFUSED';
        }

        return 'ADMITTED (' . $status . ' ' . substr($said, 0, 60) . ')';
    }

    /** A `Customer` row carrying the operator's own type word. */
    private function impostor(): Customer
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);

        return Customer::create([
            'name' => 'Impostor',
            'email' => 'impostor' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => bcrypt('secret-probe'),
            'store_id' => $store->id,
            'created_by' => $merchant->id,
            'type' => 'super admin',
        ]);
    }

    /**
     * CONTROL TWO. An ordinary merchant is refused — so the screen is genuinely operator-only and
     * the first assertion is not passing because everybody gets 200.
     */
    #[Test]
    public function test_control_an_ordinary_merchant_is_refused(): void
    {
        [$merchant] = $this->makeTenant();

        $response = $this->actingAs($merchant)->get(self::GUARDED_URI);

        $this->assertNotSame(
            200,
            $response->getStatusCode(),
            'A merchant reached an operator-only screen.'
        );
    }
}
