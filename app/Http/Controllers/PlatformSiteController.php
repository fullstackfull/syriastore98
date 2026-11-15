<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Support\Platform\PlatformCapabilities;

/**
 * PHASE 03 M5 — THE OFFICIAL STORE SYRIA WEBSITE.
 * =================================================================================================
 *
 * The platform's own public site: what Store Syria is, who it is for, what it actually does, what
 * it costs and how to get help. Distinct from a MERCHANT'S storefront (themed, tenant-scoped,
 * served from `{storeSlug}/…`) and from the merchant admin.
 *
 * WHY THESE ARE FIRST-PARTY ROUTES AND NOT A SECOND LANDING-PAGE ENGINE.
 * The vendored WorkDo `LandingPage` module is a BUILDER: every route in
 * `packages/workdo/LandingPage/src/Routes/web.php` except two sits behind
 * `['auth','web','xss','setlocate','verified']`, so the module configures a page rather than
 * serving one, and CLAUDE.md puts the package out of bounds for editing. Its public surface is the
 * root route -- `HomeController::Landing` renders `landing-page::layouts.landingpage` -- plus
 * `landing-pages/{slug}`, whose content is HTML typed into the admin builder and stored in a row.
 *
 * So the foundation is kept and not replaced: `/` still renders the module exactly as before, this
 * controller adds no builder, no block system and no second set of configurable sections. What it
 * adds is the fixed, code-owned, version-controlled pages the owner asked for -- pages whose text
 * belongs in git and under test rather than in a database row that only exists after somebody runs
 * a seeder on production, which M5 is not authorised to do.
 *
 * ROUTE ORDER IS LOAD ORDER, AND IT IS NOT ACCIDENTAL. `routes/customer.php` declares
 * `{storeSlug?}/home`, which would otherwise match `/platform/home`. `bootstrap/app.php:9`
 * registers `routes/web.php` first, so these win. `PlatformSiteRoutesTest` pins both halves of
 * that: the real dispatcher resolves `/platform/...` to this controller, and `platform` is
 * refused as a store slug, because a merchant who registered it would otherwise be shadowed by
 * the platform's own pages for ever.
 *
 * -------------------------------------------------------------------------------------------------
 * PRICING COMES FROM `plans`, NEVER FROM A VIEW. The owner's standing rule for M5 is that the site
 * must not invent prices or promise features the product does not have. So the pricing page reads
 * the canonical `Plan` rows -- the same table the subscription flow charges against -- and renders
 * what is there, including nothing at all. A hardcoded figure would drift from what a merchant is
 * actually billed and the drift would stay invisible until somebody complained.
 *
 * Capability copy is governed the same way, by `App\Support\Platform\PlatformCapabilities`.
 */
class PlatformSiteController extends Controller
{
    /** What Store Syria is, who it is for, and the shortest path to the rest of the site. */
    public function home()
    {
        return view('platform.home', [
            'plans'  => $this->publishedPlans(),
            'themes' => PlatformCapabilities::themes(),
        ]);
    }

    /**
     * What the platform actually does.
     *
     * Four groups, each filtered to capabilities the product can support -- see the registry. The
     * themes list is read from `themes/` so the page cannot advertise one that was removed.
     */
    public function features()
    {
        return view('platform.features', [
            'groups' => array_combine(
                PlatformCapabilities::groupNames(),
                array_map(
                    static fn (string $g) => PlatformCapabilities::available($g),
                    PlatformCapabilities::groupNames()
                )
            ),
            'themes' => PlatformCapabilities::themes(),
        ]);
    }

    /** How a merchant gets from nothing to a live shop. */
    public function onboarding()
    {
        return view('platform.onboarding', [
            'plans' => $this->publishedPlans(),
        ]);
    }

    /**
     * Real plans, real prices.
     *
     * An empty collection is a legitimate state and the view says so, rather than falling back to
     * illustrative figures. "No plans are published yet" is honest; an invented price is not.
     */
    public function pricing()
    {
        return view('platform.pricing', [
            'plans' => $this->publishedPlans(),
        ]);
    }

    public function faq()
    {
        return view('platform.faq');
    }

    public function contact()
    {
        return view('platform.contact');
    }

    /**
     * Legal entry points.
     *
     * THE BINDING TEXT IS NOT WRITTEN HERE. The owner's standing restrictions reserve legal and
     * commercial policy, so this page states what each document will cover and records plainly
     * that the approved text is outstanding. Inventing terms of service would make the business
     * liable for words nobody approved the moment the page went live.
     */
    public function legal()
    {
        return view('platform.legal');
    }

    /**
     * Plans a visitor may actually buy -- which excludes the ones the operator has disabled.
     *
     * Ordered by price so the cheapest reads first. `plans.price` is a STRING column, so the sort
     * is applied in PHP after the fetch -- a SQL `ORDER BY` on it would be lexicographic and put
     * "100" before "20".
     *
     * @return \Illuminate\Support\Collection<int, Plan>
     */
    private function publishedPlans()
    {
        return Plan::query()
            /* `is_disable` IS INVERTED: 1 MEANS ENABLED. Measured, because the name reads the other
               way round. `PlanController.php:27` shows a merchant `Plan::where('is_disable', 1)`
               and only the operator the unfiltered set; `StoreController.php:1160` refuses an
               upgrade with "this plan is disabled" when the value is 0; the Flutter plan list at
               `Api\DashboardController.php:7433` filters the same way. The column is
               `integer default(1)` (`2024_03_13_062916_add_is_disable_to_plans_table.php:17`).

               WITHOUT THIS PREDICATE the public site advertised every row in the table -- a
               strictly WIDER view than a signed-in merchant gets, since the unfiltered read was
               previously operator-only. A draft, retired or privately negotiated plan would have
               appeared on the company's own pricing page with a "Start with this plan" button that
               `StoreController:1160` then refuses. Found by independent review of M5. */
            ->where('is_disable', 1)
            ->get()
            ->filter(static fn (Plan $p) => $p->name !== null && trim((string) $p->name) !== '')
            ->sortBy(static fn (Plan $p) => (float) $p->price)
            ->values();
    }
}
