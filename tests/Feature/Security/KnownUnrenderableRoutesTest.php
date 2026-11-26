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
 *   ENVIRONMENT, NOT DEFECTS -- TWO of them now, and they would work in production:
 *     /themean-alytic          no such function: regexp            MySQL REGEXP
 *     /product-order-reports   no such function: MONTH             MySQL date function
 *   The dev harness runs SQLite; `CLAUDE.md` records this gap and it is why schema-sensitive work
 *   is verified against a real MySQL.
 *
 *   `/top-all-reports  no such function: SUBSTRING_INDEX` WAS THE THIRD AND IS NO LONGER TRUE
 *   (corrected 2026-11-25). GAP-221's own disposition called removing that blindness "the one that
 *   removes it permanently", and `top_product()`'s category and brand rankings no longer join
 *   against a generated series with `SUBSTRING_INDEX` -- they go through
 *   `App\Support\Reports\TopSellersByOrderCount`, so that screen is now suite-reachable and
 *   `TopSalesReportSuiteReachabilityTest` drives it. The figures were proved unchanged on the
 *   production engine first (`verify-top-five-reports-on-mysql.php`, 19 checks, 0 failed).
 *
 *   This is prose in a docblock and no assertion depended on it, which is exactly why it went
 *   stale unnoticed -- the list is a reader's map, not a ratchet.
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
 *
 * -------------------------------------------------------------------------------------------------
 * A THIRD MEMBER, AND THE CENSUS THAT FOUND IT IS WORTH MORE THAN THE MEMBER.
 *
 * A route-wide census reported ~151 authenticated routes naming a missing view.
 * `tests/Support/Security/bin/census-routes-naming-missing-views.php` reproduces the funnel; at
 * this HEAD it is 113 -> 48 -> 40 -> 3 -> ONE not already recorded:
 *
 *     113  pairs whose view is absent at `resources/views/<dotted>.blade.php` (a FILE-PATH check)
 *      65  RESCUED by `view()->exists()` -- the view resolves through a registered module
 *          namespace or an extra path. 58% of the path-based count is the census's own artefact,
 *          and that ratio is the transferable result.
 *      48  resolver-confirmed absent
 *       8  never reached -- the action refuses first: 5 `redirect()->back()` and 3 to
 *          `/dashboard` via the module gate. The missing view is never rendered, so no 500.
 *      40  really answer 500 for a verified provisioned merchant
 *      37  of those are the VENDORED `Workdo\LandingPage` module, which does not ship the views
 *          its own controllers name. Real, and not ours to edit.
 *       3  first-party: the two above, and the one below.
 *
 * 151 was not reproducible under any framing tried (113 pairs, 113 routes, 109 auth-only, 187 with
 * the separate missing-METHOD family added). Said rather than rounded towards.
 *
 * THE THIRD MEMBER WAS EXONERATED BY A CENSUS THAT ASKED THE WRONG QUESTION, which is the same
 * failure mode as those 65 rescues, in reverse. `routes/web.php` records, in the comment that
 * removed this route's PUT sibling as P1-INV-10 (b):
 *
 *     "The POST sibling `products.sku_combination` -> `sku_combination()` is left alone: its
 *      method exists, so it does not 500"
 *
 * The method does exist. The VIEW does not. MEASURED as a verified provisioned merchant:
 * `POST /products/sku_combination` -> HTTP 500,
 * `InvalidArgumentException: View [product.sku_combinations] not found.` That false claim is the
 * reason the route was passed over, and correcting it is a `routes/` edit, which is reported rather
 * than made here.
 *
 * NOT FIXED, FOR THE REASON ITS TWO SIBLINGS WERE NOT, and one more of its own: `sku_combination`
 * has **0** references in `resources/` and `public/`, so nothing calls it, and its live replacement
 * is `attribute_combination_data()` -- the renderer the product editor actually injects. Inventing
 * a Blade file for an endpoint nothing calls would be building a feature, not fixing a defect;
 * deleting the route is the P1-INV-10 decision reopened. Both are owner-scope.
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

    /**
     * THE THIRD MEMBER. Same shape, same false direction, same instruction on the way out.
     */
    public function test_the_sku_combination_route_is_still_the_third_known_broken_one(): void
    {
        $this->assertFalse(
            view()->exists('product.sku_combinations'),
            'view("product.sku_combinations") now EXISTS. ProductController::sku_combination() was '
            . 'measured broken -- POST /products/sku_combination answered 500 with '
            . '"View [product.sku_combinations] not found" for a verified provisioned merchant -- '
            . 'and routes/web.php still records the FALSE claim that this route "does not 500" '
            . 'because its method exists. If the view was added or the method repointed, the '
            . 'finding is CLOSED: delete this test AND correct that comment.'
        );
    }

    /**
     * The premise for the third member, for the same reason as the first: a route that is gone
     * would leave the assertion above true and pinning nothing.
     *
     * NOT `in_array('GET', ...)`: this one is POST-only, which is itself a thing the census got
     * wrong before it was driven -- a path-and-name census does not look at the verb, so it
     * reported a GET that is not registered.
     */
    public function test_premise_the_sku_combination_route_is_registered_and_post_only(): void
    {
        $routes = collect(app('router')->getRoutes())->filter(
            fn ($r) => $r->uri() === 'products/sku_combination'
        );

        $this->assertNotEmpty($routes, 'products/sku_combination is no longer registered; this test pins nothing.');

        $verbs = $routes->flatMap(fn ($r) => $r->methods())->unique()->values()->all();
        $this->assertContains('POST', $verbs);
        $this->assertNotContains('GET', $verbs,
            'products/sku_combination now answers GET. The census that first reported this family '
            . 'did not check the verb and reported a GET that was not registered; if one exists '
            . 'now it is a new route and a new question.');

        $this->assertSame(
            'App\Http\Controllers\ProductController@sku_combination',
            $routes->first()->getActionName()
        );
    }
}
