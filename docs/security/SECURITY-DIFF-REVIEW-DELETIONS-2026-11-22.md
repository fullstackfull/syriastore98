# Deletion audit for the security checklist — the 2026-11-22 commits

**This is an UNSIGNED engineering draft, not a review.** `.github/SECURITY-DIFF-REVIEW.md` is
titled *"Human review checklist for security-sensitive diffs"* and exists because an audit emitter
was once overwritten by unrelated validation while every test still passed. Adding
`SECURITY-DIFF-REVIEW: completed` to the pull request description is a human signature; an
automated agent adding it would defeat the control rather than satisfy it. So this file answers the
questions and stops there. **No marker is added, and the check stays red for a person to clear.**

It extends the draft already posted to `fullstackfull/syriastore98#140` on 2026-11-18, which was
measured against the diff as it stood then. Six commits have landed since —
`4810b874`, `deea9d40`, `61a41b98`, `5dbb6c0c`, `b93e047d`, `39e188ae` — so that draft no longer
covers the head. This file closes that window.

## Scope

Head `39e188ae`. Everything the six commits deleted under `app/`, `config/`, `routes/` and
`resources/views/`:

| deleted lines | file |
|---|---|
| 6 | `app/Http/Controllers/ReportController.php` |
| 1 | `app/DataTables/SalesDownloadableProductDataTable.php` |

**Seven lines in total**, so this is an exhaustive line-by-line audit rather than a sample. The
other files in these commits are pure additions (a new Blade component, four new test files, two
translation keys, a new Unit gate) plus documentation.

## Q1 — What does this diff REMOVE or NARROW?

Every one of the seven deleted lines is a line that was **re-chained, not removed.** In each case
the behaviour it carried is present in the replacement, and in six of the seven the replacement is
strictly wider.

| deleted | replaced by | effect |
|---|---|---|
| `->where('orders.store_id', getCurrentStore());` | the same predicate, re-terminated so `->when($this->period !== null, …whereBetween(…))` can follow | **the store predicate is preserved**; a date predicate is ADDED beside it |
| `$total = Order::where('store_id', …)->count();` | the same query with `->when(…)` chained before `->count()` | same; store predicate preserved |
| `'ledgerFigures' => FinanceLedgerFigures::forStore($storeId, (string) $currencyCode),` | the same call with two further arguments (`?MetricWindow`, `?string`) | same assembler, same store; an existing capability is now reachable |
| `return $dataTable->render('reports.downloadable_product_report');` | `return $dataTable->forPeriod($period)->render(<same view>, [<period view data>])` | same render, same view |
| `public function showOrderStatusReport()` | `public function showOrderStatusReport(Request $request)` | signature **widened**, not narrowed |
| `'periodLabel' => __('All time'),` ×2 | `$period === null ? __('All time') : $period->label()` | the label becomes conditional; with no period requested it is byte-identical |

**Nothing was deliberately dropped.** There is no line in this set whose behaviour is absent from
the result.

## Q2 — Did any file in this diff already exist and do something else?

No path was overwritten. `resources/views/components/ss/analytics-period-filter.blade.php` is new.
The one case worth naming is the opposite shape: `resources/views/reports/orderStatusReport.blade.php`
had its inline form **replaced by a call to that new component**. The markup is not asserted to be
equivalent — it is **proved** equivalent by a pre-existing test,
`OrderStatusReportPeriodTest::test_the_screen_offers_the_period_control`, which pins
`name="ss_period"`, `name="ss_from"`, `name="ss_to"` and the `<option value="">All time</option>`
element, and which passed unchanged across the extraction.

## Q3 — What existing control assumed the surface you widened was narrow?

The widened surface is four report screens now accepting `ss_period` / `ss_from` / `ss_to` from the
query string. The control written against the narrower reach is the **store predicate** on each
screen's query, and `Order` is **not** in `config/tenancy.php`'s `scoped_models`, so no global scope
would confine these reads if that predicate were lost.

Each of the four holds, and each is pinned by a test *and* by a mutation that was caught:

| screen | test | mutation |
|---|---|---|
| `orderStatusReport` | `test_the_window_does_not_replace_the_store_predicate` | S4 |
| `order_country_Report` | same name, both queries | S7 |
| `finance_report` | same name (assembler-scoped) | none run — the scope lives inside `FinanceLedgerFigures`, which this change does not edit |
| `downloadable_product_report` | same name | D5 |

A second control also applies: the **authorization** door. None of it changed — `routes/web.php`
maps these actions to `ReportPermission::ORDERS` and `finance.permission:View Finance Dashboard` as
before, and `ReportAuthorizationTest` and `FinanceReportInTheCentreTest` continue to own that
question. The period is read **after** the gate, never before it.

A third, from an independent read-only review of the country-report change: no caller-controlled
byte is reflected on any of these screens. `value="{{ $periodFrom }}"` receives
`$period->from()->toDateString()` — a normalised Carbon output — not the submitted `ss_from`, and
`AnalyticsPeriod::strictDate()` rejects anything it cannot round-trip. Raw `ss_*` bytes reach a
query only as two `CarbonImmutable` bindings.

## Q4 — Is any audit, logging or compliance emission removed, skipped, or made conditional?

**No.** No emission is touched. `OperatorStoreSelection::logRefusal('financeReport')` still fires
on the unchosen-store path, ahead of everything this change added. `SecurityLogInventoryTest`
passed in the Feature gate on this head (3 of 3), which is the census that would catch an observability row
moving.

## Q5 — Does any failure become silent?

**One failure became LOUDER, which is the reason it was found.** Both column formatters of
`SalesDownloadableProductDataTable` ran `foreach (json_decode($order->product_json, true))`, and
that column is nullable. yajra formats inside the draw and catches at the top, so one malformed
order produced
`{"recordsTotal":2,"recordsFiltered":0,"data":[],"error":"foreach() argument must be of type array|object, null given"}`
— every healthy row replaced by a raw PHP message under a count that contradicted the empty list
beside it. Four data-driven tests and two caught mutations now hold the guard.

Nothing in this set makes a control stop running while the response stays `2xx`. The period is
**opt-in**: with no `ss_period` in the query string every screen answers exactly as before, which
is asserted on all four and proved by a caught mutation on each (S3 / D4 / F3).

## Q6 — Is method-resolution order involved?

**N/A.** This question is about the Lynomia Ruby fork's `ActiveSupport::Concern` semantics. These
commits are PHP, touch no trait composition and no `packages/workdo/LynomiaChat`.

## Q7 — Was the change verified by removing it?

**Yes, 33 times, and that is the whole evidence basis rather than a formality.**

| slice | mutations | caught |
|---|---|---|
| `orderStatusReport` | 6 | 6 |
| `order_country_Report` | 8 | 8 |
| `finance_report` | 6 | 6 |
| `downloadable_product_report` + the null-guard | 8 | 8 |
| the period-hint wording ratchet | 2 | 2 |
| the gap-register gate | 3 | 3 |

Two results are worth stating because they are the checklist's own failure mode, found in my work:

- **Four of the six finance mutations SURVIVED the first round**, each because an assertion was
  vacuous. The clearest: the tile prints a **sum**, so "one payment inside the window, two outside,
  search the page for each amount" passed with the window removed entirely — the all-time figure
  `11,100.00` does not contain `4,000.00` and does contain `100.00`. All four now compare the
  tile's own value through `viewData()` rather than searching the page.
- **One country-report mutation could not be APPLIED**: the two handlers' view-data blocks are now
  byte-identical, so the harness refused an anchor matching twice. That is the guard working, and
  it is recorded as a refusal rather than counted as a pass.

One test is labelled in its own docblock as **not** being evidence:
`test_the_tables_own_draw_url_carries_the_chosen_period` passed before the filter existed, because
yajra's `ajax.url` is `url()->full()` already. It is kept as a ratchet against a hand-written url,
not as proof.

## Q8 — Does the merge-base regression gate report zero new failures?

The gate on the **previous** head `79078e18` concluded **success** (CI #587, all seven jobs). On
this head it is CI #588, running when this was written — so this answer is **incomplete by one
run**, and is stated that way rather than assumed.

The local Feature gate on `39e188ae` is complete and clean:

```
testcases observed   : 10804
failures observed    : 1  (manifest declares 1)
errors observed      : 0
skips observed       : 1  (manifest declares 1)
OK: the observed outcome set matches the manifest exactly
```

10,802 passed, 59,440 assertions. Plus Unit 5,997 + 1 declared skip, Contract 45,
`tests/Unit/Ui` 1,112, Reports/Analytics/Finance 668, Governance 8.

**As `.github/SECURITY-DIFF-REVIEW.md` says in its closing section: a green suite is not the same
claim, and ticking these boxes accepts no inherited failure.** The one declared failure and one
declared skip in `.github/known-test-outcomes.json` remain the owner's to reconcile.
