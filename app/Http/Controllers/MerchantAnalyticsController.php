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

        $period = $this->chosenPeriod($request);

        return view('analytics.index', [
            'figures' => MerchantAnalyticsFigures::forStore($storeId, $currencyCode, null, $period),
            'periodOptions' => \App\Support\Analytics\AnalyticsPeriod::options(),
            'savedPeriods' => \App\Models\SavedView::forCurrentPrincipal(self::SCREEN)->get(),
            'periodKey' => $period->key(),
            'periodFrom' => $period->from()->toDateString(),
            'periodTo' => $period->to()->toDateString(),
            // For the date inputs' `max`: the browser should not offer a day that has not happened.
            'periodToday' => \Carbon\CarbonImmutable::now()->toDateString(),
            // ANA-003. The employee's own standing requests, with their last run so a schedule
            // that has never fired is visibly different from one that works.
            'schedules' => \App\Models\AnalyticsReportSchedule::forCurrentPrincipal()->get(),
            'scheduleFrequencies' => \App\Models\AnalyticsReportSchedule::frequencies(),
            // ANA-009. The store's own floors, as typed -- see `MetricThreshold::rawFor()` for why
            // the form reads the raw rows rather than the checker's filtered view of them.
            'thresholds' => \App\Support\Analytics\MetricThreshold::rawFor($storeId),
            'thresholdMetrics' => \App\Support\Analytics\MetricThreshold::METRICS,
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
    private function chosenPeriod(Request $request): \App\Support\Analytics\AnalyticsPeriod
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
        $period = $this->chosenPeriod($request);

        $figures = MerchantAnalyticsFigures::forStore($storeId, $currencyCode, null, $period);

        /*
         * ANA-003 — THE ROW SET MOVED TO `AnalyticsExportRows`, because it now has two callers.
         *
         * The scheduled export (`ScheduledReportRunner`) has to produce the SAME file as this
         * download. Copying the row set would have made two definitions of what the export
         * contains, so a later edit of one could leave a merchant's scheduled file and their
         * clicked file disagreeing -- the same class of defect as a figure and its caveat living in
         * different files. Nothing about the file changed; it is the same labels, the same
         * breakdowns and the same clamp row, in one place.
         */
        $rows = \App\Support\Analytics\AnalyticsExportRows::build($figures);

        return response(\App\Support\Metrics\FigureCsv::render($rows), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="analytics.csv"',
        ]);
    }

    /**
     * ANA-003 — CREATE OR UPDATE A STANDING REQUEST FOR THIS EXPORT.
     *
     * THE PERIOD IS THE ONE ON SCREEN, resolved through `AnalyticsPeriod` exactly as `index()` does,
     * so a merchant saves the window they are looking at rather than a second description of it.
     * A clamped period is saved AS CLAMPED, for the same reason the screen shows the clamp: saving
     * the un-clamped request would store a period the merchant has never actually seen.
     *
     * THE RECIPIENT IS NOT A PARAMETER. It is written from the authenticated user's own account
     * address. An e-mail field here would make this an authenticated mail relay: store figures to
     * any address on the internet, on a timer. Sending to a colleague is a real feature and a real
     * decision -- who may receive another store's figures is the owner's call, not a text input.
     *
     * RE-SAVING A NAME UPDATES, which is the contract `SavedView` already has and the unique index
     * enforces: the merchant's intent is "this is what that schedule means now".
     */
    public function storeSchedule(Request $request)
    {
        $resolved = $this->resolveStore();

        if (! is_array($resolved)) {
            return $resolved;
        }

        [$storeId] = $resolved;

        $user = auth('web')->user();

        if ($user === null) {
            return redirect()->route('analytics.index')->with('error', __('Permission denied.'));
        }

        $validator = \Validator::make($request->all(), [
            // 60 is the column width. A name is a label, not a note.
            'name' => 'required|string|max:60',
            'frequency' => 'required|string|in:' . implode(',', array_keys(
                \App\Models\AnalyticsReportSchedule::frequencies()
            )),
        ]);

        if ($validator->fails()) {
            return redirect()->route('analytics.index')
                ->with('error', $validator->getMessageBag()->first());
        }

        $recipient = trim((string) $user->email);

        if ($recipient === '') {
            return redirect()->route('analytics.index')
                ->with('error', __('Your account has no e-mail address, so a report cannot be sent to you.'));
        }

        $period = $this->chosenPeriod($request);
        $name = trim((string) $request->input('name'));

        $existing = \App\Models\AnalyticsReportSchedule::forCurrentPrincipal()
            ->where('name', $name)
            ->first();

        $schedule = $existing ?? new \App\Models\AnalyticsReportSchedule();

        $schedule->name = $name;
        $schedule->period_key = $period->key();
        $schedule->period_from = $period->from()->toDateString();
        $schedule->period_to = $period->to()->toDateString();
        $schedule->frequency = (string) $request->input('frequency');
        $schedule->is_active = true;

        /*
         * OWNERSHIP AND THE RECIPIENT, SET AS PROPERTIES. None of these four is fillable, so no
         * mass assignment -- from this request or anywhere else -- can place a schedule in another
         * store, under another employee, or pointed at another address.
         */
        $schedule->store_id = $storeId;
        $schedule->created_by = $user->id;
        $schedule->recipient_email = $recipient;

        /*
         * A NEW SCHEDULE IS DUE AT ITS FIRST INTERVAL, NOT IMMEDIATELY. Leaving `next_due_at` null
         * would make it due at once, so pressing Save would send a report the merchant did not ask
         * for yet -- and on a daily schedule they would get two on the first day. An UPDATE leaves
         * the existing due time alone, so renaming or re-pointing a schedule does not reset its
         * cadence.
         */
        if ($existing === null) {
            $schedule->next_due_at = app(\App\Services\Reports\ScheduledReportRunner::class)
                ->nextDue($schedule->frequency, \Carbon\CarbonImmutable::now());
        }

        $schedule->save();

        return redirect()->route('analytics.index')->with(
            'success',
            $existing === null ? __('Scheduled report created.') : __('Scheduled report updated.')
        );
    }

    /**
     * ANA-009 — THE MERCHANT SETS THE FLOOR. This is the configuration surface, and without it the
     * feature is not configurable, whatever the engine can do.
     *
     * THERE IS NO DEFAULT AND THIS HANDLER MUST NOT INVENT ONE. `MetricThreshold` ships no number:
     * a store has no floors until a merchant writes one, and nothing fires until then. So a blank
     * field here means "do not watch this metric" and is written as an empty string, NOT as zero --
     * a merchant who types 0 means "tell me if I take nothing at all", which is a real and different
     * instruction. `MetricThreshold::nonNegativeFloat()` makes the same distinction on the read side
     * and the two must not disagree.
     *
     * SAME MIDDLEWARE AS THE SCREEN, for the reason the route notes give: a write that decides what
     * the store gets alerted about must not be reachable by someone who cannot read the figures the
     * alert is about. `ReportPermission::ACTIONS` names this action explicitly rather than leaning
     * on the fail-closed fallback.
     *
     * THE STORE COMES FROM `resolveStore()`, never from the request -- so a merchant can only set
     * their own store's floors, and an operator who has selected no store is refused rather than
     * writing settings onto store 1 (GAP-223(b)).
     */
    public function storeThresholds(Request $request)
    {
        $resolved = $this->resolveStore();

        if (! is_array($resolved)) {
            return $resolved;
        }

        [$storeId] = $resolved;

        $user = auth('web')->user();

        if ($user === null) {
            return redirect()->route('analytics.index')->with('error', __('Permission denied.'));
        }

        /*
         * `nullable` ON EVERY FIELD, because clearing a threshold is how a merchant turns the alert
         * off and must not be a validation error. The bounds are the same ones `MetricThreshold`
         * enforces on the read side: a floor is not negative, and a window is 1..365 days. Stating
         * them here as well is not duplication for its own sake -- the reader refuses silently
         * (skips the metric) and a silent refusal of something the merchant just typed would present
         * as "my setting did not save".
         */
        $rules = ['window_days' => 'nullable|integer|min:1|max:365'];

        foreach (\App\Support\Analytics\MetricThreshold::METRICS as $key => $spec) {
            $rules[$key] = 'nullable|numeric|min:0';
        }

        $validator = \Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return redirect()->route('analytics.index')
                ->with('error', $validator->getMessageBag()->first());
        }

        foreach (\App\Support\Analytics\MetricThreshold::METRICS as $key => $spec) {
            $value = $request->input($key);

            // An empty field is "not configured" and is stored as such. `trim` so a field holding
            // only spaces is the same instruction as an empty one.
            $value = ($value === null) ? '' : trim((string) $value);

            \App\Models\Setting::putForStore($spec['setting'], $value, $storeId, $user->id);
        }

        $window = $request->input('window_days');
        $window = ($window === null) ? '' : trim((string) $window);

        \App\Models\Setting::putForStore(
            \App\Support\Analytics\MetricThreshold::WINDOW_SETTING,
            $window,
            $storeId,
            $user->id
        );

        /*
         * THE CONFIRMATION SAYS WHAT WILL HAPPEN, not just that something saved. A merchant who
         * clears every field has switched the alerts off and should be told so plainly rather than
         * reading "updated" and wondering.
         */
        $configured = \App\Support\Analytics\MetricThreshold::configuredFor($storeId);

        return redirect()->route('analytics.index')->with(
            'success',
            $configured === []
                ? __('Metric alerts are off: no threshold is set.')
                : __('Metric alert thresholds updated.')
        );
    }

    /**
     * ANA-003 — stop a standing request.
     *
     * THE ID IS RESOLVED THROUGH THE OWNERSHIP PREDICATE, never `find()`.
     * `analytics_report_schedules` is not in `config/tenancy.php`'s `scoped_models`, so no global
     * scope adds a predicate for anybody and a bare `find()` would let one merchant delete
     * another's schedule by guessing an id -- the pattern CLAUDE.md names as the most productive
     * defect class in this codebase.
     */
    public function destroySchedule(Request $request, $id)
    {
        $resolved = $this->resolveStore();

        if (! is_array($resolved)) {
            return $resolved;
        }

        $schedule = \App\Models\AnalyticsReportSchedule::forCurrentPrincipal()
            ->where('id', (int) $id)
            ->first();

        if ($schedule === null) {
            return redirect()->route('analytics.index')->with('error', __('Scheduled report not found.'));
        }

        $schedule->delete();

        return redirect()->route('analytics.index')->with('success', __('Scheduled report stopped.'));
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
