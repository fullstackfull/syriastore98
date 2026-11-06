<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * A REGISTERED MERCHANT ROUTE WHOSE VIEW DOES NOT EXIST, pinned so it cannot rot into folklore.
 * ================================================================================================
 *
 * FOUND BY MEASUREMENT, not by reading. P2.5-WAVE-29's phone-table census drove 73 merchant routes
 * with a signed-in merchant and recorded the HTTP status of each. Five answered 500, and reading
 * the log separated them into two kinds that must not be reported as one:
 *
 *   ENVIRONMENT, NOT DEFECTS -- three of them, and they would work in production:
 *     /themean-alytic          no such function: regexp            MySQL REGEXP
 *     /product-order-reports   no such function: MONTH             MySQL date function
 *     /top-all-reports         no such function: SUBSTRING_INDEX   MySQL string function
 *   The dev harness runs SQLite; `CLAUDE.md` records this gap and it is why schema-sensitive work
 *   is verified against a real MySQL.
 *
 *   DEFECTS ON ANY DATABASE -- two, both the same shape:
 *     /otp-integration   View [otp.integration] not found
 *     /currency-rates    View [currency_rates.index] not found
 *
 * THE OTP ONE WAS ALREADY KNOWN and is pinned by
 * `OtpOutboundHardeningTest::test_the_index_route_is_still_the_known_broken_one`, recorded in
 * docs/security/P1-SEC-55-OUTBOUND-SENDER-CENSUS.md §4.2, with the owner-scope reasoning for not
 * deleting it. ITS TWIN WAS NOT RECORDED ANYWHERE. This is that record.
 *
 * `CurrencyRateController::index()` (app/Http/Controllers/CurrencyRateController.php:17) returns
 * `view('currency_rates.index', compact('currencies'))`, and no such view exists in the
 * repository. The route is registered and `auth`-protected (routes/web.php:198-201), so a merchant
 * who reaches that URL gets a 500.
 *
 * NOTHING LINKS TO IT. The live UI for currency rates is the Settings screen's own tab,
 * `resources/views/setting/currency_setting.blade.php`, whose form posts to
 * `route('currency-rates.update', ...)` at :232 -- the POST twin of the same controller, which
 * works and is permission-gated. So the impact is a deep link or a bookmark, not a screen a
 * merchant navigates to. That is a smaller claim than "a merchant screen is broken", and it is
 * the true one.
 *
 * NOT FIXED HERE, FOR THE REASON ITS TWIN WAS NOT. Both honest repairs -- point `index()` at the
 * real Settings tab, or drop the route and the method -- change what a registered route does, and
 * the recorded decision on the OTP twin is that removing a method "is a scope decision for the
 * owner". Re-deciding it here because I happened to find the second instance would be exactly the
 * kind of quiet scope widening that decision exists to prevent.
 *
 * ASSERTED IN THE FALSE DIRECTION ON PURPOSE. Making the view exist, or repointing `index()`, is
 * the fix; this test will then fail and tell whoever did it to come and delete it.
 */
class KnownUnrenderableRoutesTest extends TestCase
{
    public function test_the_currency_rates_index_route_is_still_the_known_broken_one(): void
    {
        $this->assertFalse(
            view()->exists('currency_rates.index'),
            'view("currency_rates.index") now EXISTS. CurrencyRateController::index() was measured '
            . 'broken on 2026-11-06 -- the registered, auth-protected GET /currency-rates answered '
            . '500 with "View [currency_rates.index] not found" for a signed-in merchant -- and '
            . 'this test pins that so the finding cannot rot. If the view was added or index() was '
            . 'repointed at the Settings currency tab, the finding is CLOSED: delete this test and '
            . 'the section in docs/security/P1-SEC-55-OUTBOUND-SENDER-CENSUS.md.'
        );
    }

    /**
     * The premise, so this test cannot pass for the wrong reason. If the route were gone, the
     * assertion above would still be true and would be pinning nothing at all.
     */
    public function test_premise_the_route_is_registered_and_points_at_that_controller(): void
    {
        $route = collect(app('router')->getRoutes())->first(
            fn ($r) => $r->uri() === 'currency-rates' && in_array('GET', $r->methods(), true)
        );

        $this->assertNotNull($route, 'GET /currency-rates is no longer registered; this test pins nothing.');
        $this->assertSame(
            'App\Http\Controllers\CurrencyRateController@index',
            $route->getActionName(),
            'GET /currency-rates no longer points at CurrencyRateController::index().'
        );
        $this->assertContains(
            'auth',
            $route->gatherMiddleware(),
            'GET /currency-rates lost its auth middleware -- that is a security change, not a '
            . 'rendering one, and it is more urgent than the broken view this test pins.'
        );
    }
}
