<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\PlatformOperatorOnly;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

/**
 * THE TWO PLATFORM-ADMIN SCREENS HAD NO CHECK OF THEIR OWN. `GAP-178` / `P06-05`.
 * =================================================================================================
 *
 * `app/Http/Controllers/` holds exactly two `SuperAdmin*` controllers —
 * `SuperAdminResourcesController` (`/platform/resources`, GAP-195 / OPS-002) and
 * `SuperAdminRevenueController` (`/platform/revenue`, GAP-192 / SAAS-007). Both are one read, no
 * writes, no request input. Their only authorization was `PlatformOperatorOnly` attached to the
 * route.
 *
 * WHY THAT IS NOT ENOUGH HERE, measured against the surface's own siblings rather than from taste.
 * Of the four operator-only controllers under `platform/`, `PlatformContentController` carries an
 * in-controller `refuseAnyoneButTheOperator()` — `! $user instanceof User || !
 * UserPolicy::isOperator($user)` then `abort(403)` — called from all three of its methods. The
 * other three relied on the route alone. `SEC-F2` in this codebase is precisely the finding that
 * relying on route grouping alone fails, and the `can:manage-platform-addons` group records the
 * remedy in its own comment: "Each controller method ALSO calls $this->authorize() itself, so the
 * control does not rest on this grouping alone."
 *
 * AND THE WAY THE ROUTE GATE DISAPPEARS IS MEASURED IN THIS REPOSITORY, not hypothetical. A later
 * declaration of the same method+URI REPLACES the earlier one and silently drops its middleware
 * (`RouteCollection::addToCollections()` keys on method+domain+uri); `GAP-268`'s work found a real
 * instance of a duplicated declaration and `NoAccidentalDuplicateRouteDeclarationsTest` now
 * ratchets the set. If `GET platform/revenue` is ever re-declared, the gate vanishes — and these
 * two handlers would have answered.
 *
 * WHAT EACH SCREEN WOULD HAVE LEAKED, so this is not abstract: `/platform/revenue` reports what
 * every merchant on the platform pays — MRR/ARR, commercial information about third parties — and
 * `/platform/resources` reports the shared machine's disk and database figures.
 *
 * HOW THIS IS DRIVEN. `$this->withoutMiddleware(PlatformOperatorOnly::class)` disables ONLY the
 * route gate, leaving `auth` and the rest of the `web` group in place. That is the simulation of
 * "the route lost its middleware", and it is the only way to test a second line of defence while
 * the first one is working.
 *
 * EXACTLY 403, AND `assertNotSame(200, ...)` WOULD NOT DO — the lesson is
 * `PlatformOperatorOnlyPrincipalTest`'s, recorded there after its own first version survived the
 * mutation it was written for: without a class check a `Customer` gets past the guard, reaches a
 * handler that expects a `User`, and raises HTTP **500**. A 500 is not a refusal; it means the
 * principal was ADMITTED and the code behind the door happened to break. So every assertion below
 * names the one status that means "refused".
 */
class PlatformAdminSurfaceDefenceInDepthTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The whole platform-admin surface this row is about. */
    private const SCREENS = [
        '/platform/resources',
        '/platform/revenue',
    ];

    /**
     * PREMISE. The route gate really is what admits these screens today, and disabling it really
     * does disable it — otherwise every assertion below is measuring a gate that was never there.
     */
    #[Test]
    public function test_premise_the_route_gate_is_real_and_disabling_it_disables_it(): void
    {
        $merchant = $this->makeMerchant();
        $this->makeStore($merchant);

        foreach (self::SCREENS as $uri) {
            $withGate = $this->actingAs($merchant)->get($uri);

            $this->assertSame(
                403,
                $withGate->getStatusCode(),
                'PREMISE: ' . $uri . ' did not refuse a merchant with the route gate in place, so '
                . 'the gate is not doing what this file assumes.'
            );
        }

        /* And the operator is admitted, so 403 above is a refusal and not a broken screen. */
        $operator = $this->makePlatformAdmin();

        foreach (self::SCREENS as $uri) {
            $this->assertSame(
                200,
                $this->actingAs($operator)->get($uri)->getStatusCode(),
                'PREMISE: the platform operator cannot reach ' . $uri . ', so this file is '
                . 'measuring a screen that is broken for everybody.'
            );
        }
    }

    /**
     * THE DEFECT. With the route gate gone, the handler must still refuse a merchant.
     */
    #[Test]
    public function test_a_merchant_is_refused_by_the_handler_when_the_route_gate_is_gone(): void
    {
        $merchant = $this->makeMerchant();
        $this->makeStore($merchant);

        foreach (self::SCREENS as $uri) {
            $response = $this->withoutMiddleware(PlatformOperatorOnly::class)
                ->actingAs($merchant)
                ->get($uri);

            $this->assertSame(
                403,
                $response->getStatusCode(),
                $uri . ' answered ' . $response->getStatusCode() . ' to an ordinary MERCHANT once '
                . 'the route gate was removed, so the handler has no check of its own. 200 is a '
                . 'cross-tenant disclosure — /platform/revenue reports what every merchant on the '
                . 'platform pays. A 500 is not a refusal either: it means the merchant was '
                . 'admitted and the handler broke.'
            );
        }
    }

    /**
     * THE SAME DEFECT FOR THE PRINCIPAL-CLASS SHAPE. `UserPolicy::isOperator()` reads `->type` off
     * whatever it is handed and `customers.type` is a fillable column, so the handler's own check
     * must test the CLASS first — `instanceof` short-circuits, which is why the order matters.
     */
    #[Test]
    public function test_a_customer_carrying_the_operators_type_is_refused_by_the_handler(): void
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
            'premise: `type` is fillable on Customer and the value persisted. If that stops being '
            . 'true the shape is unreachable — but delete this case on that evidence, not on the '
            . 'assumption.'
        );

        foreach (self::SCREENS as $uri) {
            $response = $this->withoutMiddleware(PlatformOperatorOnly::class)
                ->actingAs($customer, 'customers')
                ->get($uri);

            $this->assertSame(
                403,
                $response->getStatusCode(),
                $uri . ' answered ' . $response->getStatusCode() . ' to a Customer row typed '
                . '`super admin` once the route gate was removed. The handler must check the '
                . 'principal\'s CLASS before its `type` means anything. A 500 means it got past '
                . 'the check and broke the handler, which is not a refusal.'
            );
        }
    }

    /**
     * CONTROL. The operator must still be admitted with the route gate disabled, or the handler's
     * own check refuses everybody and the two cases above are satisfied by a dead screen.
     */
    #[Test]
    public function test_control_the_operator_is_still_admitted_without_the_route_gate(): void
    {
        $operator = $this->makePlatformAdmin();

        foreach (self::SCREENS as $uri) {
            $response = $this->withoutMiddleware(PlatformOperatorOnly::class)
                ->actingAs($operator)
                ->get($uri);

            $this->assertSame(
                200,
                $response->getStatusCode(),
                $uri . ' refused the platform OPERATOR, so the handler\'s own check is refusing '
                . 'everybody and the refusals asserted above prove nothing.'
            );
        }
    }
}
