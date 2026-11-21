<?php

namespace App\Http\Controllers;

use App\Support\Metrics\MerchantAnalyticsFigures;
use Illuminate\Http\Request;

/**
 * THE MERCHANT ANALYTICS SCREEN. P5-4 / ANA-001…ANA-004.
 *
 * ONE READ AND NO WRITES. The store comes from `getCurrentStore()` and the currency from that
 * store's own row -- NEVER from the request, which is the whole of the tenancy argument here.
 *
 * ANA-002 ADDED THE ONE THING A CALLER DOES CONTROL: the period. This docblock used to say the
 * screen took no request input at all and therefore had nothing to validate; that is no longer
 * true, so it states what is taken and why it is safe instead of repeating a claim that has gone
 * stale. CLAUDE.md's warning is specifically that *a tenant predicate* built from caller-supplied
 * input is not a tenant check, and that has produced four separate defects here. A period is not a
 * tenant predicate: every query still carries the `store_id` resolved above, so what a merchant
 * chooses is only WHICH OF THEIR OWN DAYS to read. `AnalyticsPeriod::fromRequest()` is the only
 * reader of that input and resolves it through an allowlist and two clamps, never into SQL.
 *
 * WHY IT IS NOT A METHOD ON ReportController. That class is 3,660 lines and, more to the point,
 * **cannot run under the Feature suite for MOST of its handlers**: 37 `MONTH(` and 10 `YEAR(` in `selectRaw` are absent
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
 * EVERY FIGURE HERE HAS A SETTLED LABEL. This docblock previously said the screen showed no
 * retention tile because PHASE-05-FOUR-FIGURE-LABELLING-PROPOSAL.md §3 was still with the owner.
 * Reconciled 2026-11-20: that §3 question IS owner decision D1, answered Option A and already
 * implemented in `LedgerCoverage`, so P5-6 added the `Repeat purchase` tile and nothing was
 * reopened. The screen now shows orders, page views, conversion, buyers and repeat purchase, plus
 * the ledger money tiles it inherits from `/finance` and the P5-5 coupon figure.
 */
class MerchantAnalyticsController extends Controller
{
    public function index(Request $request)
    {
        $resolved = $this->resolveStore();

        if (! is_array($resolved)) {
            return $resolved;
        }

        [$storeId, $currencyCode] = $resolved;

        $period = $this->chosenPeriod($request, $storeId);

        return view('analytics.index', [
            'figures' => MerchantAnalyticsFigures::forStore($storeId, $currencyCode, null, $period),
            'periodOptions' => \App\Support\Analytics\AnalyticsPeriod::options(),
            'savedPeriods' => \App\Models\SavedView::forCurrentPrincipal(self::SCREEN)->get(),
            'periodKey' => $period->key(),
            'periodFrom' => $period->from()->toDateString(),
            'periodTo' => $period->to()->toDateString(),
        ]);
    }

    /**
     * ANA-002 — THE SAVED-PERIOD SCREEN NAME, shared by the controller, the view and `SavedView`.
     *
     * A string literal in three files is how a saved view ends up written under one screen name and
     * read under another, which presents to a merchant as "my saved periods vanished".
     */
    public const SCREEN = 'analytics';

    /**
     * The period this request is for: an explicitly chosen one, or a saved one, or the default.
     *
     * PRECEDENCE IS EXPLICIT-BEATS-SAVED, and that ordering is deliberate. A merchant who picks a
     * period in the form means it for this request; a saved period is a starting point, not a lock.
     * The reverse order makes the filter look broken the first time somebody saves one.
     *
     * A SAVED VIEW IS NOT TRUSTED EITHER. `appliedFilters()` re-sanitises on read against the
     * screen's vocabulary -- the row outlives the code that wrote it -- and the result then goes
     * through `AnalyticsPeriod::fromRequest()` exactly as request input does, so a hand-written or
     * stale row cannot reach the window by a path the query string does not have.
     */
    private function chosenPeriod(Request $request, int $storeId): \App\Support\Analytics\AnalyticsPeriod
    {
        $period = \App\Support\Analytics\AnalyticsPeriod::class;

        $input = $request->only([
            $period::KEY_PERIOD,
            $period::KEY_FROM,
            $period::KEY_TO,
        ]);

        if ($input !== [] && ($input[$period::KEY_PERIOD] ?? '') !== '') {
            return $period::fromRequest($input);
        }

        /*
         * `ss_view` NAMES A ROW ID, so it is resolved through the model's OWN ownership predicate
         * (`forCurrentPrincipal` scopes to this store and this employee, and fails closed when either is absent) rather than by `find()`. A
         * `SavedView::find($id)` here would be the exact caller-supplied-identifier shape CLAUDE.md
         * records as the most productive defect pattern in this codebase: saved views are not in
         * `config/tenancy.php`'s `scoped_models`, so no global scope would add a predicate for
         * anybody, and one merchant could read another's saved filters by guessing an id.
         */
        $viewId = (int) $request->input('ss_view', 0);

        if ($viewId > 0) {
            $saved = \App\Models\SavedView::forCurrentPrincipal(self::SCREEN)
                ->where('id', $viewId)
                ->first();

            if ($saved !== null) {
                return $period::fromRequest($saved->appliedFilters());
            }
        }

        return $period::default();
    }

    /**
     * P5-8 — THE SAME FIGURES AS A FILE, WITH EVERY DISCLOSURE ATTACHED TO ITS OWN ROW.
     *
     * Phase 05's rule for exports is that they carry the same caveats as the screen and that an
     * export which drops one is a defect. This calls the SAME figures class with the SAME arguments
     * as `index()` -- no second query and no second definition -- and `FigureCsv` makes the period
     * and the limits columns rather than a footnote, so a spreadsheet cannot separate a number from
     * what it leaves out.
     *
     * The labels are repeated from `analytics/index.blade.php` on purpose: they are the only strings
     * that view chooses, and a test asserts the file's row set matches the screen's tile set so the
     * two cannot drift. Everything else -- value, period, disclosure -- comes from the figures.
     *
     * SAME GATE AS THE SCREEN, by sharing `resolveStore()` rather than re-deriving it. An export
     * route with a weaker guard than the screen it exports is a classic way to lose a control, and
     * the refusals here are the screen's refusals, including the operator-with-no-store case.
     */
    public function export(Request $request)
    {
        $resolved = $this->resolveStore();

        if (! is_array($resolved)) {
            return $resolved;
        }

        [$storeId, $currencyCode] = $resolved;

        /*
         * ANA-002 — THE EXPORT HONOURS THE SAME PERIOD AS THE SCREEN, resolved by the same method.
         *
         * An export that ignored the filter would be the worst available outcome: a merchant
         * filters to one week, presses Download, and gets a thirty-day file whose rows are labelled
         * with the period they really cover -- so nothing is technically false and the figures are
         * still wrong for what was asked. P5-8's rule is that the file and the screen cannot
         * disagree about a number; after ANA-002 that has to include WHICH DAYS the number is for.
         */
        $period = $this->chosenPeriod($request, $storeId);

        $figures = MerchantAnalyticsFigures::forStore($storeId, $currencyCode, null, $period);

        $rows = \App\Support\Metrics\FigureCsv::rows($figures, [
            'orders' => __('Orders'),
            'visits' => __('Page views'),
            'conversion' => __('Conversion'),
            'customers' => __('Buyers'),
            'retention' => __('Repeat purchase'),
            'payments' => __('Payments received'),
            'profit' => __('Estimated gross profit'),
            'redemptions' => __('Coupon discounts given'),
        ]);

        /*
         * ANA-004. The two breakdowns are rows, not tiles, so they go through `breakdownRows()`
         * -- same four columns, every row carrying its own period and disclosure. They are
         * appended rather than interleaved so the single figures stay together at the top of the
         * file, and each breakdown's rows are prefixed with its own name so two lists in one
         * download stay readable.
         */
        foreach ([
            'devices' => __('Devices'),
            'channels' => __('Referring pages'),
        ] as $key => $label) {
            if (isset($figures[$key])) {
                $rows = array_merge($rows, \App\Support\Metrics\FigureCsv::breakdownRows($figures[$key], $label));
            }
        }

        /*
         * AND THE CLAMP TRAVELS WITH THE FILE. When a requested period was shortened or moved, the
         * screen says so above the figures; a file that dropped that sentence would be a figure
         * separated from its own limits, which is the single defect `FigureCsv` exists to prevent.
         * It is a ROW, in the same four columns, because a header or a trailing comment survives
         * neither sorting nor copying a range.
         */
        if (! empty($figures['period']['clamped'])) {
            $rows[] = [
                __('Period'),
                $figures['period']['label'],
                $figures['period']['label'],
                (string) $figures['period']['clamped'],
            ];
        }

        return response(\App\Support\Metrics\FigureCsv::render($rows), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="analytics.csv"',
        ]);
    }

    /**
     * The screen's two refusals, in one place so `index()` and `export()` cannot diverge.
     *
     * @return array{0: int, 1: string}|\Illuminate\Http\RedirectResponse
     */
    private function resolveStore()
    {
        /*
         * AN OPERATOR WHO HAS CHOSEN NO STORE IS REFUSED, not shown store 1. `getCurrentStore()`
         * (helper.php) returns the HARDCODED LITERAL 1 for a `super admin`, so without this check the
         * platform operator would be shown one particular merchant's analytics as though they were
         * "the" figures. The condition and the message are StoreFinanceController's, reused: for an
         * operator who has chosen nothing, no store is in fact selected. `dashboard` rather than
         * `back()` because `back()` on a refused GET resolves to this handler and loops.
         *
         * The operator's own cross-tenant view is `/platform/analytics` (P5-7), which needs no store
         * selected precisely because it is about all of them.
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

        return [$storeId, (string) $currencyCode];
    }
}
