# Phase 05 — the bilingual / appearance acceptance condition, and its register

**Requirement `P05-16` · gap `GAP-164` · the condition is enforced by
`tests/Unit/Governance/Phase05BilingualAppearanceGateTest`.**

---

## 1. The condition

The owner's next action on `GAP-164`, verbatim:

> Make AR/EN plus light/dark a per-slice acceptance condition at P5-4, as Phase 03 M7 did for the
> storefront.

Operatively, and this is the wording the gate enforces:

> **No Phase 05 slice is accepted until every surface it renders has been driven in all four
> combinations — Arabic RTL and English LTR, light and dark — and the record of that run is
> committed beside the slice.**

It is a **gate, not a feature**: there is nothing to build, and `GAP-164`'s own "what is missing"
column says so — *"Applies to work not yet begun. Carry it as a standing acceptance condition for
every Phase 05 surface rather than a separate task."* A standing condition carried as a habit is
the thing that fails. Phase 03 M7 is the precedent the action names, and M7's lesson is the whole
argument: the three defects it found — a public site following the merchant admin's dark-mode
setting, 15 brand-text contrast nodes, a mis-placed landmark — were **all invisible in the
source**, and `StorefrontThemeCoverageTest` is what keeps M7's coverage claim true without anybody
remembering to re-check it.

### §4.9 of the execution plan

The condition is the **ninth point** of `PHASE-05-EXECUTION-PLAN.md` §4 *Verification standard, per
slice*. The other eight stand unchanged.

---

## 2. How a slice satisfies it

Three steps. The whole thing takes a few minutes per surface.

**1 — capture.** Serve the dev fixture and drive the surface in four combinations. Use a **copy**
of `database/p2dev.sqlite` if anything else might be serving it: the harness rewrites the
principal's theme, direction and language between rows.

```bash
php artisan serve --host=127.0.0.1 --port=8917          # DB_DATABASE=<a copy of p2dev.sqlite>
node docs/development/phase-2.5/tools/render-matrix.cjs \
  --base http://127.0.0.1:8917 \
  --out docs/development/phase-5/evidence/<slice>-four-combinations-<date>/<principal> \
  --paths /your-surface --email merchant@example.test
```

One directory **per principal**: the record carries no principal field, so the directory is what
says whose session a row is, and two principals' captures of the same path collide by filename.

**2 — register.** Add a row to **Table B** naming the route, the view, the principal, the English
`h1` the page prints (the content marker), the status and the evidence directory. Add the surface
to the slice's **Table A** row. If the slice touches a new file that reads `App\Support\Metrics\`,
or changes how many times an existing one does, update **Table C**.

**3 — run the gate.**

```bash
php artisan test --filter=Phase05BilingualAppearanceGateTest
```

### What the gate checks about a capture

Everything in this list is a way a capture run has **actually lied in this repository**:

| check | the failure it refuses |
|---|---|
| four rows, one per combination | a combination that was never driven |
| HTTP 200, no premise failure, no console error | four of the five ways a capture lies answer 200 (M7 §3) |
| rendered `dir` matches the language | 22 Arabic loads once came back `dir="ltr"` — a raw `LANGUAGE` cookie fails `EncryptCookies` and is discarded (M7 §5) |
| four **distinct** stylesheets | one sheet serving two combinations means the run never changed state |
| four **distinct** screenshots by SHA-1 | the first P5-R8 run: the operator's four images byte-identical in light/dark pairs — four pictures about to be committed as eight states |
| English `h1` equals the declared marker | a permission refusal redirects, and the page it lands on photographs perfectly |
| Arabic `h1` present and **different** | a screen that renders under `dir="rtl"` without being translated |
| every `__()` key declared in `ar.json` | `__()` returns its key, so an undeclared sentence renders in English on the Arabic page |
| no undeclared physical-direction construct | a physical margin is invisible in an English capture and wrong in Arabic |

---

## 3. Table A — every slice carries the condition

The gate asserts this table's slice ids against the `### P5-n` headings of
`PHASE-05-EXECUTION-PLAN.md` **in both directions**: a slice with no row has no acceptance
condition, and a row with no slice is a condition against nothing. A seventeenth slice is red until
it says what it renders.

`SURFACES-IN-TABLE-B` names at least one surface below. `NO-RENDERED-SURFACE` is a claim that the
slice puts no pixels on any screen, and the surfaces cell must then be empty.

<!-- GATE-TABLE: SLICES -->

| slice | surfaces | appearance | note |
|---|---|---|---|
| `P5-1` | `finance` | SURFACES-IN-TABLE-B | admits `cancelled`/`refunded` to the finance screens and their validators |
| `P5-2` | — | NO-RENDERED-SURFACE | the metric layer. Deliberately wired to nothing: "no merchant-visible figure has changed" |
| `P5-3` | `finance-reports` | SURFACES-IN-TABLE-B | one report surface re-pointed so far. Each further re-point takes a Table B row — see §6 |
| `P5-4` | `analytics` | SURFACES-IN-TABLE-B | the slice this condition is attached at |
| `P5-5` | `analytics` | SURFACES-IN-TABLE-B | the `Coupon discounts given` tile |
| `P5-6` | `analytics` | SURFACES-IN-TABLE-B | the `Repeat purchase` tile |
| `P5-7` | `platform-analytics` `operator-dashboard` | SURFACES-IN-TABLE-B | the operator dashboard's Top Selling Store / Most Popular Store took `MetricScope::stores()` in this slice |
| `P5-8` | `analytics` `platform-analytics` | SURFACES-IN-TABLE-B | the CSV itself has no appearance. This slice's **pixels** are the export control and the schedule panel on both analytics screens, so those are the surfaces it owns here |
| `P5-9` | — | NO-RENDERED-SURFACE | the shipped half is a division-by-zero fix on eleven payment paths. **The display half is not built**, and takes a Table B row when it is |
| `P5-10` | `finance` | SURFACES-IN-TABLE-B | the profit figures |
| `P5-11` | `analytics` `finance` | SURFACES-IN-TABLE-B | `<x-ss.stat>`'s caveat slot, measured in Chromium at the time (`evidence/p5-11-stat-hint-2026-11-17/`) |
| `P5-12` | — | NO-RENDERED-SURFACE | a vendored reward listener; no first-party view |
| `P5-13` | — | NO-RENDERED-SURFACE | server-side redemption eligibility. The checkout it decides for is Phase 03's surface, not a Phase 05 screen |
| `P5-14` | `finance` | SURFACES-IN-TABLE-B | an audit of PR #140's deletions, whose one UI restoration was the `/finance` status filter (`order_cancelled` / `order_refunded`) |
| `P5-15` | — | NO-RENDERED-SURFACE | the consent store is built and **unwired**; wiring it to a screen takes a Table B row |
| `P5-16` | `finance` | SURFACES-IN-TABLE-B | D1 and D2 on `/finance`. The slice recorded "I did not capture screenshots for this slice" — that is what `finance`'s row below now closes |

<!-- /GATE-TABLE -->

---

## 4. Table B — the Phase 05 surfaces, and their four-combination evidence

`marker` is the English `h1` the page prints; the gate asserts it against the capture record, and
asserts the Arabic `h1` is present and different.

Status is a closed vocabulary pinned in the test, not in this document: `CAPTURED`, or
`BLOCKED:<reason>` from `SQLITE-MYSQL-ONLY-SQL` / `NO-DEV-FIXTURE-DATA` /
`OWNER-DECISION-PENDING`. A new blocker is a change to the test file, which a reviewer reads.

<!-- GATE-TABLE: SURFACES -->

| surface | route | view | principal | marker | status | evidence |
|---|---|---|---|---|---|---|
| `analytics` | `GET /analytics` | `resources/views/analytics/index.blade.php` | merchant | `Analytics` | `CAPTURED` | `docs/development/phase-5/evidence/p05-16-four-combinations-2026-11-26/merchant` |
| `platform-analytics` | `GET /platform/analytics` | `resources/views/platform/analytics.blade.php` | operator | `Platform analytics` | `CAPTURED` | `docs/development/phase-5/evidence/p05-16-four-combinations-2026-11-26/operator` |
| `finance` | `GET /finance` | `resources/views/finance/index.blade.php` | merchant | `Finance` | `CAPTURED` | `docs/development/phase-5/evidence/p05-16-four-combinations-2026-11-26/merchant` |
| `finance-reports` | `GET /finance-reports` | `resources/views/reports/finance_report.blade.php` | merchant | `Finance Report` | `CAPTURED` | `docs/development/phase-5/evidence/p05-16-four-combinations-2026-11-26/merchant` |
| `merchant-dashboard` | `GET /dashboard` | `resources/views/dashboard.blade.php` | merchant | `Dashboard` | `CAPTURED` | `docs/development/phase-5/evidence/p05-16-four-combinations-2026-11-26/merchant` |
| `operator-dashboard` | `GET /dashboard` | `resources/views/superadmin/dashboard.blade.php` | operator | `Dashboard` | `CAPTURED` | `docs/development/phase-5/evidence/p05-16-four-combinations-2026-11-26/operator` |

<!-- /GATE-TABLE -->

**The blocked ceiling is asserted as an EXACT figure, in both directions** — the
`assert-known-test-outcomes.php` pattern, where an undeclared failure and an unexpectedly-passing
declared failure both fail the gate. A ceiling that only bounds from above lets blocked rows
accumulate; one that is not asserted downwards stays stale after the blocker clears.

<!-- GATE-BLOCKED-CEILING: 0 -->

**Zero, and it is a real zero.** `/finance-reports` was expected to be `BLOCKED:SQLITE-MYSQL-ONLY-SQL`
like the three Phase 2.5 report chart bodies; it was driven instead of assumed and it renders in all
four combinations, because `ReportController@financeReport` reads `FinanceLedgerFigures` rather than
raw `MONTH()`.

---

## 5. Table C — who reads the metric layer, and what it feeds

**This is the arm that makes a new Phase 05 surface impossible to add quietly.** §1 of the
execution plan forbids a controller computing money inline, so every Phase 05 figure reaches a
screen through `App\Support\Metrics\`. The gate counts the references to that namespace — comments
stripped — in every first-party file under `app/` and `resources/views/` outside the layer, and
asserts this table matches **exactly**, both the set of files and each file's count.

The count matters as much as the set. Re-pointing an existing screen at the metric layer, which is
what `P5-3` does, adds no file and no `view()` call; it adds a reference. A changed number is a red
test naming the file and asking what it now puts on a screen.

**2026-11-26 (GAP-152 / P05-04, the adoption half). A ROW WAS DELETED AND TWO COUNTS MOVED, and the
deletion is the interesting one.** `resources/views/dashboard/overview.blade.php` no longer reads
the metric layer at all: the merchant dashboard's `Order value` tile composed its hint inside a
thirty-line raw-PHP block in the view, which read the `MetricResult`'s `currencies()` and
`totalIn()` and never its `caveats()` -- so `GrossMerchandiseValue`'s own `FLOAT_PRECISION`
(measured: GMV is the one money metric that cannot read `store_order_financials`' DECIMAL and must
come off `orders.final_price`, which is FLOAT) reached the busiest money figure in the product on no
screen. The composition moved to `App\Support\Metrics\DashboardOrderValueFigure`, beside the
metric, and the view renders `<x-ss.figure>`, which has no `hint` prop to forget. **The view reading
the layer FEWER times is the improvement**, not a regression: it now reads no figure logic at all.
`HomeController.php` 7 -> 8 for the new producer call, and
`app/Support/Reports/ReportFigureNote.php` 1 -> 2, because its five composites now derive their
words from one declared caveat list through `CaveatedFigure` instead of each ending with its own
`MetricCaveat::message(FLOAT_PRECISION)` literal. The surface is unchanged -- `merchant-dashboard`
is still the screen and its four-combination evidence still covers it -- so no SURFACES row moves.
Pinned by `DashboardOrderValueStatesItsPrecisionTest` and `AnalyticsTilesCarryTheirCaveatsTest`.

<!-- GATE-TABLE: READERS -->

| file | references | feeds |
|---|---|---|
| `app/Http/Controllers/HomeController.php` | 8 | `merchant-dashboard`, `operator-dashboard` |
| `app/Http/Controllers/MerchantAnalyticsController.php` | 2 | `analytics`; `GET /analytics/export` (CSV, no rendered page) |
| `app/Http/Controllers/PlatformAnalyticsController.php` | 6 | `platform-analytics`; `GET /platform/analytics/export` (CSV, no rendered page) |
| `app/Http/Controllers/ReportController.php` | 3 | `finance-reports`; the period window and sales tile it shares with report surfaces `P5-3` has not re-pointed yet |
| `app/Http/Controllers/StoreFinanceController.php` | 6 | `finance` |
| `app/Services/Reports/ScheduledReportRunner.php` | 2 | the scheduled analytics **e-mail** (`ScheduledAnalyticsReport`), not a screen — see §6 |
| `app/Services/SaasRevenueFigures.php` | 2 | `PlanController` and `SuperAdminRevenueController`: **Phase 06** surfaces, whose appearance acceptance is Phase 06's |
| `app/Support/Analytics/AnalyticsExportRows.php` | 1 | the two CSV exports above |
| `app/Support/Analytics/AnalyticsPeriod.php` | 1 | the period selector on `analytics` |
| `app/Support/Analytics/MetricThreshold.php` | 4 | the threshold panel on `analytics`; `RecoveryQueueController` (Phase 04 surface) |
| `app/Support/Customers/CustomerMoneyFigures.php` | 4 | the customer detail money tiles: **Phase 02 / 2.5** surface; and `Api\DashboardController` (JSON) |
| `app/Support/Customers/CustomerMoneyTiles.php` | 5 | as above |
| `app/Support/Operator/StoreThreeSixty.php` | 5 | `platform-stores-360` — the operator's Store 360 screen (`GAP-232` / `ADM-002`): **Phase 06** surface, whose appearance acceptance is Phase 06's. It reads `GrossMerchandiseValue` through `MetricScope::store()` for one tenant and renders the per-currency rows and the metric's own caveats; it adds no new figure of its own |
| `app/Support/Reports/DimensionPeriodRevenue.php` | 1 | the by-dimension report bodies (`ReportController`) |
| `app/Support/Reports/ReportFigureNote.php` | 2 | the disclosure note under a report figure, and the carrier its five composites now derive their words from |
| `app/Support/TopSellersByRevenue.php` | 1 | the top-sellers panel shared by the dashboards and the reports |
| `resources/views/components/ss/figure.blade.php` | 2 | the shared figure component rendered on `analytics`, `finance` and the dashboards |
| `resources/views/finance/index.blade.php` | 3 | `finance` |
| `resources/views/order/brand_category_chart.blade.php` | 1 | the brand/category chart body (AJAX fragment of the order reports) |
| `resources/views/reports/order_brand_chart.blade.php` | 1 | the brand sales chart body (AJAX fragment) |
| `resources/views/reports/order_category_chart.blade.php` | 1 | the category sales chart body (AJAX fragment) |
| `resources/views/reports/order_product_chart.blade.php` | 1 | the product sales chart body (AJAX fragment) |

<!-- /GATE-TABLE -->

### Table D — the declared physical-direction constructs

A registered surface must not pin a side. The exceptions are pinned **exactly**: an undeclared hit
fails, and so does a declared hit the view no longer carries, because a stale exception is a claim
nobody re-reads. Logical properties — `margin-inline-start`, Bootstrap 5's `ms-*`/`me-*`, `text-end`
— flip with the document and are not hits.

<!-- GATE-TABLE: DIRECTION-EXCEPTIONS -->

| view | hit | count | reason |
|---|---|---|---|
| `resources/views/analytics/index.blade.php` | `dir="ltr"` | 1 | **correct bidi, not a defect.** The scheduled-export clock time (`H:i`) must not reorder inside an Arabic sentence. An isolated LTR run for a time is the right markup, and removing it would be the bug |
| `resources/views/dashboard.blade.php` | `text-right` | 1 | **a finding, not an exemption with a reason.** Bootstrap 4's `text-right` does NOT flip; the column header it sits on stays right-aligned in Arabic. The file is Phase 02's merchant dashboard and went through Phase 2.5 owner visual acceptance, so it is recorded here and left to its owner rather than changed inside a Phase 05 gate task. The fix is one word: `text-end` |

<!-- /GATE-TABLE -->

---

## 6. What this gate does NOT catch

Stated rather than implied, because a gate that overstates its reach is worse than one that states
its limits.

- **It does not look at pixels.** It reads the capture's own record. A premise line can be typed by
  a person exactly as a JSON file can; what the gate makes impossible is the **absence** of a
  record, not its forgery.
- **It computes no contrast ratios.** `render-matrix.cjs` records what rendered.
  `audit-merchant-screens.cjs` is the tool that composites a colour against its first opaque
  ancestor and measures; the 2026-11-24 run of it over `/analytics` and `/platform/analytics` found
  **zero** failures in all four combinations. `finance`, `finance-reports` and the two dashboards
  have **not** been re-audited for contrast under this gate, and no row here claims they have.
- **A view that receives figures as plain variables never names the metric layer** and is invisible
  to the Table C census. Two measured examples on this tree: `superadmin/dashboard.blade.php` and
  `reports/order_report.blade.php` name it **only in comments**. Such a view is reached through its
  controller, which does name it, and Table C's `feeds` column is the human-written bridge. The
  gate checks that the bridge's view files exist; it cannot derive the bridge.
- **The scheduled analytics e-mail is a rendered Phase 05 artefact with no Table B row.**
  `ScheduledReportRunner` sends `ScheduledAnalyticsReport`, whose body is a Blade view. It is not a
  `GET` route and has no light/dark of its own, so the appearance half does not apply — but the
  **bilingual half does**, and nothing here checks it. Recorded as an open item.
- **It cannot tell which principal produced a capture.** The record has no such field. The register
  declares the principal and the directory name carries it; the content marker catches a capture of
  a *different screen*, not of the same screen by the wrong principal.
- **A slice that renders a screen and never writes its route down escapes the anti-escape arm.**
  That arm reads the slice's own section of the plan, so it can only make the requirement stricter
  — it stops `P5-4` declaring it renders nothing, because `P5-4`'s text says `/analytics`. `P5-1`,
  `P5-3` and `P5-11` each render a surface and name no route; their rows are honest rather than
  forced. A slice is still free to be written in a way that avoids the tripwire. What it is not
  free to do is touch the metric layer silently: that is Table C's count, and it is not prose.
- **Evidence nobody cites is not flagged.** A capture directory under `evidence/` that no row
  points at is left alone, because three such directories belong to other records
  (`p5-11-stat-hint-2026-11-17`, `p5-r8-four-combinations-2026-11-24`, `mkt-008-batch2`) and a
  gate that demanded a citation for each would be asserting other slices' filing, not this
  condition.
- **It says nothing about whether the Arabic reads like Arabic**, or whether a label describes its
  control. That is human review, outstanding here exactly as M7 §7 records it for the storefront.
- **Further `P5-3` re-points are caught by the reference count, not by name.** When a report
  surface is re-pointed at the metric layer, `ReportController`'s count moves and the gate reddens;
  the author then adds that surface's Table B row. The three report chart bodies Phase 2.5 recorded
  as `BLOCKED` on SQLite (`reports-chart`, `order-reports-chart`, `order-reports-chart-data`) are
  **not** Phase 05 surfaces yet and deliberately have no row.
