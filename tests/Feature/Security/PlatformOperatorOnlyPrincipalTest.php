<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

/**
 * `PlatformOperatorOnly` MUST CHECK WHAT THE PRINCIPAL IS BEFORE IT READS WHAT IT CLAIMS TO BE.
 *
 * The middleware asked `UserPolicy::isOperator($user)`, which takes an UNTYPED `$actor` and reads
 * `$actor->type` — and `customers` has a `type` column, with `'type'` in `Customer::$fillable`. So
 * a `Customer` row whose `type` read `super admin` was admitted by the only check on every route
 * the gate carries — handlers whose own docblocks say they rely on this middleware.
 *
 * THAT COUNT WAS 14 IN THIS FILE AND IN THE MIDDLEWARE UNTIL 2026-11-26. IT IS 37. Measured from
 * the booted route table while surveying the platform-admin surface for `GAP-178`, and the
 * partition is written out so the arithmetic is checkable from the file rather than trusted:
 *
 *     LynomiaChat super-admin group   15
 *     platform/* operator screens      9   (the file said "three")
 *     plan-coupon                      7
 *     plan.order.refund, plan.disable,
 *       order.approve, order.reject    4
 *     zid/{storeId}/*                  2
 *                                     --
 *                                     37
 *
 * The figure grew with the Lynomia group and with the `platform/*` screens that `GAP-192`,
 * `GAP-195` and `GAP-107` added. Nothing behaved wrongly at 14; a reader's threat model was
 * understated by 23 routes, which is why `test_premise_the_measured_blast_radius_is_still_37`
 * asserts the partition instead of leaving the number in prose where it went stale once already.
 *
 * LATENT RATHER THAN LIVE, measured on both links before the fix was called hardening: nothing in
 * production makes `customers` the default guard (`shouldUse(` appears zero times outside tests
 * and prose), and every `Customer` creator in `app/` hard-codes `'customer'`. Two independent
 * missing links. Fixed anyway — the blast radius if either ever closes is every route above, and
 * the cost is one `instanceof` clause.
 *
 * Found by the independent authorization review of `/platform/documentation`, whose controller
 * carries the identical guard; this file pins the MIDDLEWARE, which the review found had the same
 * shape and many times the reach.
 *
 * WHY THIS DRIVES ROUTES RATHER THAN THE CLASS. A unit test of the predicate would not have caught
 * a middleware that never calls it, and the thing being asserted is "a Customer cannot reach this
 * URL".
 *
 * AND WHY IT DRIVES FIVE OF THEM RATHER THAN ONE. Until 2026-11-26 this file drove
 * `platform/analytics` alone, described as "one of the fourteen" — a sound choice of representative
 * (a plain read, so a regression in the test moves no money, unlike `plan.order.refund`) but a
 * sample of one standing for a set that turned out to be 37. A gate is attached PER ROUTE, so
 * "this middleware refuses a Customer" and "every route that claims this middleware refuses a
 * Customer" are different statements, and only the second one is the control. One representative
 * from each of the five groups is now driven, chosen for no route parameters wherever possible so
 * that `SubstituteBindings` — which runs BEFORE route middleware — cannot answer 404 ahead of the
 * gate and satisfy an assertion that was never reached.
 *
 * EXACTLY 403 EVERYWHERE, and `assertNotSame(200, ...)` is not good enough — measured on this very
 * file, see `test_a_customer_carrying_the_operators_type_is_refused`.
 */
class PlatformOperatorOnlyPrincipalTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const GUARDED_URI = '/platform/analytics';

    /**
     * ONE REPRESENTATIVE PER GROUP, as `group => [verb, uri]`.
     *
     * Chosen for NO ROUTE PARAMETERS wherever the group has such a route, because
     * `SubstituteBindings` is `web`-group middleware and therefore runs before the route's own
     * `PlatformOperatorOnly`: a missing bound model would answer 404 before the gate was reached,
     * and a test asserting "refused" would be satisfied by a route that never ran the guard.
     * `zid/{storeId}/orders` is the one exception and is safe — it is a CLOSURE route, so there is
     * no typed parameter and no implicit binding to miss.
     *
     * `plan-disable` is a POST and needs no token: Laravel's CSRF middleware skips itself under
     * `runningUnitTests()`.
     */
    private const REPRESENTATIVES = [
        'lynomia super-admin' => ['GET', '/super-admin/lynomia-integration'],
        'platform/* screens' => ['GET', '/platform/analytics'],
        'plan-coupon' => ['GET', '/plan-coupon'],
        'plan/order actions' => ['POST', '/plan-disable'],
        'zid' => ['GET', '/zid/7/orders'],
    ];

    /** The measured size of each group, summing to the blast radius in the docblock. */
    private const GROUP_SIZES = [
        'super-admin/lynomia' => 15,
        'platform/' => 9,
        'plan-coupon' => 7,
        'plan/order actions' => 4,
        'zid/' => 2,
    ];

    /** @return array<string, int> the gate's routes, counted into the five groups */
    private function measuredGroups(): array
    {
        $counts = array_fill_keys(array_keys(self::GROUP_SIZES), 0);

        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            $carries = false;

            foreach (array_filter($route->middleware(), 'is_string') as $middleware) {
                if (str_contains($middleware, 'PlatformOperatorOnly')) {
                    $carries = true;
                }
            }

            if (! $carries) {
                continue;
            }

            $uri = $route->uri();

            if (str_starts_with($uri, 'super-admin/lynomia')) {
                $counts['super-admin/lynomia']++;
            } elseif (str_starts_with($uri, 'platform/')) {
                $counts['platform/']++;
            } elseif (str_starts_with($uri, 'plan-coupon')) {
                $counts['plan-coupon']++;
            } elseif (str_starts_with($uri, 'zid/')) {
                $counts['zid/']++;
            } else {
                $counts['plan/order actions']++;
            }
        }

        return $counts;
    }

    /**
     * THE FIGURE THIS FILE AND THE MIDDLEWARE BOTH GOT WRONG, now asserted instead of stated.
     *
     * Both said 14. It is 37. A count living only in prose is exactly what went stale here, so the
     * partition is measured against the booted route table in both directions: a group that grows
     * fails, and a group that shrinks fails too. When it does fail, update BOTH this const and the
     * table in `PlatformOperatorOnly`'s docblock -- they are the same claim in two files.
     */
    #[Test]
    public function test_premise_the_measured_blast_radius_is_still_37(): void
    {
        $measured = $this->measuredGroups();

        $this->assertSame(
            self::GROUP_SIZES,
            $measured,
            'The set of routes carrying `PlatformOperatorOnly` has changed shape. This is not a '
            . 'defect by itself -- a new operator route is ordinary -- but the blast radius '
            . 'recorded in this file and in the middleware\'s own docblock is now wrong, and that '
            . 'number is what a reader uses to judge how much rests on one `instanceof` clause. '
            . 'Re-measure, update GROUP_SIZES and the table in PlatformOperatorOnly, and check '
            . 'whether the new route belongs in REPRESENTATIVES.'
        );

        $this->assertSame(
            37,
            array_sum($measured),
            'the group counts no longer sum to the 37 this file documents.'
        );
    }

    /**
     * PREMISE FOR THE FIVE-GROUP CASES. Each representative must EXIST and must actually carry the
     * gate -- otherwise a 403 below could be coming from somewhere else entirely, or a 404 from a
     * route that was renamed, and either would satisfy an assertion the guard never reached.
     */
    #[Test]
    public function test_premise_every_representative_route_exists_and_carries_the_gate(): void
    {
        foreach (self::REPRESENTATIVES as $group => [$verb, $uri]) {
            $route = \Illuminate\Support\Facades\Route::getRoutes()->match(
                \Illuminate\Http\Request::create($uri, $verb)
            );

            $carries = false;

            foreach (array_filter($route->middleware(), 'is_string') as $middleware) {
                if (str_contains($middleware, 'PlatformOperatorOnly')) {
                    $carries = true;
                }
            }

            $this->assertTrue(
                $carries,
                $verb . ' ' . $uri . ' (representing ' . $group . ') does not carry '
                . '`PlatformOperatorOnly`. Either it lost the gate -- which is the defect, not a '
                . 'test problem -- or it is the wrong representative for that group.'
            );
        }
    }

    /**
     * THE DEFECT, ACROSS THE WHOLE GATE AND NOT ONE ROUTE OF IT.
     *
     * The gate is attached per route, so one route refusing a Customer does not establish that the
     * other 36 do. Exactly 403 everywhere, for the reason recorded on the single-route case below.
     *
     * WHAT EACH GROUP ACTUALLY DOES WITHOUT THE `instanceof` CLAUSE -- measured by removing it from
     * `PlatformOperatorOnly` and reading all five statuses, because "it fails" is not the useful
     * part and NOT ONE OF THEM IS A REFUSAL:
     *
     *     lynomia super-admin   500   admitted; the handler expected a User and broke
     *     platform/* screens    500   same
     *     plan-coupon           500   same
     *     plan/order actions    200   ADMITTED AND THE HANDLER RAN -- see below
     *     zid                   404   admitted; the closure answered not-found
     *
     * `plan/order actions` IS THE ONE THAT MATTERS, and it corrects something the middleware's own
     * docblock implies. `planDisable` has a check of its own --
     * `$actor->type !== UserPolicy::PLATFORM_ADMIN` -- and the middleware records that for
     * `plan.disable` "the handler's own strict `!==` refused the lookalike, so the pair composed
     * fail-closed". That is true of the LEADING-SPACE lookalike (`' super admin'`) and NOT of this
     * defect: a `Customer` typed exactly `super admin` satisfies a plain string comparison, because
     * the handler never asks what class the principal is. Measured: the request answered 200 with
     * the handler's own business-logic JSON, both inside this loop and in isolation. So for that
     * route the `instanceof` clause is the ONLY thing standing there, not the second of two.
     *
     * WHY `platform/analytics` AND NOT `platform/revenue` AS THE REPRESENTATIVE, which is a trap I
     * walked into and measured my way out of. `/platform/revenue` was the obvious pick -- it is the
     * screen `GAP-178` is about -- and under the mutation it answered **403**, masking the
     * middleware regression entirely, because `SuperAdminRevenueController` now carries its own
     * `instanceof` guard (`PlatformAdminSurfaceDefenceInDepthTest`). A representative that is
     * defended twice cannot detect a regression in either defence. `platform/analytics` relies on
     * the middleware alone, which is exactly what makes it the right probe for it -- and it is a
     * plain read, so a regression here moves no money.
     */
    #[Test]
    public function test_a_customer_carrying_the_operators_type_is_refused_on_every_group(): void
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

        $observed = [];

        foreach (self::REPRESENTATIVES as $group => [$verb, $uri]) {
            $observed[$group] = $this->actingAs($customer, 'customers')
                ->call($verb, $uri)
                ->getStatusCode();
        }

        $this->assertSame(
            array_fill_keys(array_keys(self::REPRESENTATIVES), 403),
            $observed,
            'A Customer row typed `super admin` was not REFUSED with 403 on every group the gate '
            . 'covers. 200 means it was admitted. 500 means it got PAST the guard and broke the '
            . 'handler, which is not a refusal and is the exact shape the `instanceof` clause '
            . 'exists for. 404 means the route is gone or a bound model was missing, so the guard '
            . 'never ran -- fix the representative, do not accept the pass. 419 would mean CSRF '
            . 'answered first.'
        );
    }

    /**
     * AND THE ORDINARY CASE: a merchant, on every group. This is the one a reader assumes is
     * covered and which a single-route test did not establish either.
     */
    #[Test]
    public function test_an_ordinary_merchant_is_refused_on_every_group(): void
    {
        $merchant = $this->makeMerchant();
        $this->makeStore($merchant);

        $observed = [];

        foreach (self::REPRESENTATIVES as $group => [$verb, $uri]) {
            $observed[$group] = $this->actingAs($merchant)->call($verb, $uri)->getStatusCode();
        }

        $this->assertSame(
            array_fill_keys(array_keys(self::REPRESENTATIVES), 403),
            $observed,
            'An ordinary merchant was not refused with 403 on every group. `plan-coupon` operates '
            . 'on the PLATFORM\'s own subscription discount codes, `plan-disable` on its plans, '
            . 'and the Lynomia group on cross-tenant integration links -- none of them is a '
            . 'merchant capability.'
        );
    }

    /**
     * CROSS-GROUP CONTROL. The operator must not be refused ANYWHERE, or the two cases above are
     * satisfied by five screens that refuse everybody.
     *
     * `assertNotSame(403)` and not `assertSame(200)`, deliberately: three of these representatives
     * are write actions, and asserting a successful outcome would mean asserting that this test
     * disabled a plan. What the control needs to establish is only that the GATE does not refuse
     * the operator; what the handler then does with an empty payload is another file's subject.
     */
    #[Test]
    public function test_control_the_operator_is_refused_by_no_group(): void
    {
        $operator = $this->makePlatformAdmin();

        foreach (self::REPRESENTATIVES as $group => [$verb, $uri]) {
            $status = $this->actingAs($operator)->call($verb, $uri)->getStatusCode();

            $this->assertNotSame(
                403,
                $status,
                $verb . ' ' . $uri . ' (' . $group . ') refused the platform OPERATOR, so the gate '
                . 'refuses everybody there and the refusals asserted above prove nothing about it.'
            );
        }
    }

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
