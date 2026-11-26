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
