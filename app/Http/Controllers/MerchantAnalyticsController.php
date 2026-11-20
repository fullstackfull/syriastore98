<?php

namespace App\Http\Controllers;

use App\Support\Metrics\MerchantAnalyticsFigures;
use Illuminate\Http\Request;

/**
 * THE MERCHANT ANALYTICS SCREEN. P5-4 / ANA-001…ANA-004.
 *
 * ONE READ, NO WRITES, NO REQUEST INPUT. The store comes from `getCurrentStore()` and the currency
 * from that store's own row; nothing on this screen is selectable, so there is no parameter a caller
 * could supply and therefore nothing to validate. CLAUDE.md records that a tenant predicate built
 * from caller-supplied input is not a tenant check and has produced four separate defects here, so
 * the absence of input is the design rather than an omission.
 *
 * WHY IT IS NOT A METHOD ON ReportController. That class is 3,660 lines and, more to the point,
 * **cannot run under the Feature suite at all**: 36 `MONTH(` and 9 `YEAR(` in `selectRaw` are absent
 * from SQLite, so every handler that uses them answers HTTP 500 in a test and the suite is blind to
 * them. A surface whose whole purpose is to state figures honestly must be testable, so it is its
 * own controller and its figures come from `MerchantAnalyticsFigures`, which uses Eloquent only.
 *
 * AUTHORIZATION IS `ReportPermission`, REUSED RATHER THAN RE-DERIVED. It already encodes the
 * ordering this codebase gets wrong by default -- platform operator first (Laratrust has no
 * super-admin bypass, so `isAbleTo()` alone refuses the one principal who reads every store), then
 * the tenant OWNER (`creatorId() === id`, so a merchant created before a permission name existed is
 * not locked out of their own reports), then the named permission. Attached in `routes/web.php`; the
 * action is `index`, which `ReportPermission::ACTIONS` maps to the seeded `Manage Reports`. Nothing
 * new is seeded, because a permission name absent from `Utility::addNewData()` cannot exist as a row
 * and would evaluate false for everybody -- including the owner. That exact lockout has happened in
 * this repository (all six finance names were gated on while none was seeded).
 *
 * THE FOUR-FIGURE LABELLING DECISION IS NOT PRE-EMPTED. PHASE-05-FOUR-FIGURE-LABELLING-PROPOSAL.md
 * §3 is still with the owner, so this screen shows no retention tile: the figures it does show
 * (orders, page views, conversion, new vs returning, and the two ledger money tiles it inherits from
 * `/finance`) each already have a settled label.
 */
class MerchantAnalyticsController extends Controller
{
    public function index(Request $request)
    {
        /*
         * AN OPERATOR WHO HAS CHOSEN NO STORE IS REFUSED, not shown store 1. `getCurrentStore()`
         * (helper.php) returns the HARDCODED LITERAL 1 for a `super admin`, so without this check the
         * platform operator would be shown one particular merchant's analytics as though they were
         * "the" figures. The condition and the message are StoreFinanceController's, reused: for an
         * operator who has chosen nothing, no store is in fact selected. `dashboard` rather than
         * `back()` because `back()` on a refused GET resolves to this handler and loops.
         */
        $caller = auth()->user();

        if ($caller !== null
            && \App\Policies\UserPolicy::isOperator($caller)
            && empty($caller->current_store)) {
            return redirect()->route('dashboard')->with('error', __('No store is selected.'));
        }

        $storeId = (int) getCurrentStore();

        /*
         * NO STORE, NO SCREEN -- the same guard and the same reasoning as StoreFinanceController's
         * four null-store refusals. A delegated principal who holds a real report permission but
         * resolves no store would otherwise read figures computed with `where('store_id', 0)`. That
         * discloses nothing (no row in any tenant carries store_id 0) but it renders a page of
         * confident zeroes about nothing, which is precisely what this surface exists not to do.
         */
        if ($storeId <= 0) {
            return redirect()->route('dashboard')->with('error', __('No store is selected.'));
        }

        /*
         * The store's own currency, read the way every other money surface in this application reads
         * it: the `defult_currancy` setting row, vendored misspelling and all, defaulting to USD.
         * It is passed to the figures so `assumingUnknownCurrencyIs()` can fold a null currency_code
         * on an order row into the store's own rather than refuse the tile.
         */
        $currencyCode = \App\Models\Setting::where('store_id', $storeId)
            ->where('name', 'defult_currancy')
            ->value('value') ?? 'USD';

        return view('analytics.index', [
            'figures' => MerchantAnalyticsFigures::forStore($storeId, (string) $currencyCode),
        ]);
    }
}
