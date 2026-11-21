# P5-3 — the planning pass, the prerequisites, and the engineering that followed

**Status (2026-11-20):** the eleven on-screen defects in §2 are closed or refuted (see §4), the two
prerequisites are resolved, and the six CSV export handlers have been reconciled against their
on-screen twins (§5). What remains is surface wiring gated on the entitlement-ledger start date and
the one owner labelling decision (both in §4), tracked in the task ledger — not here.

This document began as the output of the planning pass and its two prerequisites (the sections
below, written then and left intact as the record); §4 and §5 record what was built from it.

**Method.** Six report-surface groups were analysed and then adversarially challenged by an
independent pass instructed to attack each proposal and to default to *unsound* when uncertain.
**49 surfaces were planned; the challenger rejected 36 of them and named 45 further money surfaces
the first pass had missed.** A 73% rejection rate is not a tidy result and is not presented as one:
the first pass was not reliable enough to implement from, and the objections are the deliverable.

Every claim below marked **[verified here]** was re-measured directly against primary sources
rather than accepted from the analysis.

---

## 1. The two prerequisites, and why they block wiring

### 1a. `total()` throws on any store with history — **[verified here]**

`database/migrations/2026_10_12_000102_add_currency_and_created_by_to_orders_table.php` states
verbatim:

> *"historical rows keep NULL — this migration does NOT backfill, because the correct per-order
> historical rate is not recoverable from code, and inventing one would be exactly the silent
> divergence GATE F exists to prevent. Reading code must tolerate NULL on pre-migration orders."*

The metric layer does tolerate it: `Metric::sumByCurrency()` maps a null `currency_code` to the
bucket `unknown`, and `MetricResult::make()` then sees two buckets and auto-attaches
`MIXED_CURRENCY`. `MetricResult::total()` **throws `MixedCurrencyException`** on more than one
bucket — by design, because adding two currencies produces a number with no unit.

**The consequence for P5-3 is not a caveat, it is a 500.** Every merchant report tile renders one
number beside one currency symbol. A single-currency store with any order placed before
2026-10-12 has two buckets, so the obvious wiring — `->total()` into a Blade tile — is an uncaught
exception on `GET order-reports-chart`, a merchant admin route.

**This is not a defect in the layer.** Refusing to add currencies is the correct behaviour and
§11 rule 3 requires it. What is missing is an accessor for the question the surfaces actually ask:
*"my figure, in my currency, and tell me what you assumed."* Inventing a rate is forbidden;
**stating an assumption is not.** That accessor is prerequisite P5-3a.

### 1b. The entitlement record is 15 days old — **[verified here]**

`store_order_financials` has exactly one migration — `2026_11_02_000400_create_...` — with **zero**
`INSERT … SELECT` and no backfill anywhere in `database/migrations/`. It is written only by
`OrderObserver::created()`.

So §2 (collected payments), §4 (cancellations), §5 (refunds), §8 (margin) and §10 (shipping and
tax) **return zero for every order placed before 2026-11-02.** Any surface switched from `orders`
to the entitlement record collapses to near-zero on a historical window — correctly, in that the
ledger genuinely has no rows, and uselessly, in that a merchant reading it concludes their
business stopped.

The challenger's sharpest instance: a proposal to draw a "collected" series beneath the demand
series on a per-day chart would render **a flat zero line, correctly labelled, under a real
line** — precisely the wrong-number-that-looks-right this slice exists to prevent.

**Nothing here is fixable by code.** Either a window is restricted to the ledger's own lifetime
and says so, or the figure is not offered. Backfilling is a live-data operation and an owner
decision; it is **not** proposed here.

---

## 2. Pre-existing defects this pass measured, independent of Phase 05

These are faults in the shipped reporting suite. None is caused by P5-2 and none is fixed by it.
They are recorded because a planning pass that found them and did not write them down would have
wasted them.

### 2a. The finding that explains all of the others — **[verified here]**

**`ReportController` cannot be executed by this project's test suite at all.** It calls `MONTH()`
**36** times and `YEAR()` **9** times inside `selectRaw`, and SQLite has neither — measured:
`SELECT MONTH('2026-11-30')` answers `SQLSTATE[HY000]: General error: 1 no such function: MONTH`.
I found this by writing a Feature test for defect 1 and watching all five cases return **HTTP 500**
before they reached a single assertion.

So a 3,660-line controller carrying zero `payment_status` predicates and an eighteen-site date
bug has been shipped **unexercised by 9,112 Feature tests**. Nothing was skipped and nothing was
declared; the endpoints simply cannot run on the suite's engine. Any future work on these surfaces
needs the MySQL harness, and a Feature test written for them will pass vacuously or fail
confusingly.

---

| # | defect | evidence |
|---|---|---|
| 1 | **FIXED.** **Three of five period buttons silently drop the final day of the period.** `->where('order_date','<=', date('Y-m-t',$end))` compares a bare DATE string against a DATETIME column. Since `date('Y-m-t')` for *last month* always resolves to a date already past, **"last month" permanently loses its last day of trading.** | **[verified here]** `ReportController.php:511`, `:577`, `:723`, and again in the chart-data endpoint at `:1013-1016`, `:1085-1088`, `:1229-1232`, `:1349-1352`. Measured on MariaDB 10.11.14: of four rows (29 Nov 12:00, 30 Nov 00:00, 30 Nov 09:15, 30 Nov 23:59:59), the bare bound keeps **2**, an `endOfDay()` bound keeps **4**. On SQLite the bare bound keeps **1**. `MetricWindow::applyTo()` already uses `startOfDay()`/`endOfDay()`, so surfaces moved onto it get this fixed as a side effect. **Fixed at all 18 sites** (12 on `order_date`, 3 on `regiester_date`, 3 on `last_active`) by converting both bounds to `whereDate`, the idiom the same file already uses at `:260-261` and `:269-297`. End-to-end on MariaDB: with 195.00 placed inside the period the report returned **112.00** before and **195.00** after — short by exactly the 70.00 and 13.00 placed on the final day at 14:00 and 23:59:59, while the 7.00 at midnight was admitted, which pins the mechanism. Reverting one site drops it back to 112.00. |
| 2 | **The tile labelled "Order value, before discounts" is after discounts.** `orders.final_price` is written already net of the coupon *and* inclusive of shipping and tax. A merchant reading that tile beside "Order value" and "Coupon value applied" concludes the first two differ by the discount; measured, they differ by shipping + tax. | `PaymentService.php:3026` sets `coupon_price` separately and computes `final_price` from the already-discounted subtotal; `OrderFinancialService` maps `discount_amount` and `customer_total` as distinct columns. Renders at `resources/views/reports/_sales_stats.blade.php:14-17`. |
| 3 | **FIXED.** **The category report drops the last day of a custom range, and returns nothing at all for a single-day selection.** `DatePeriod`'s end is exclusive and the category report was the only one of the three per-dimension reports not compensating — the product report does it inline, the brand report on the preceding line. **`$request->Date` with no `' to '` sets `from_date = to_date`, which is exactly what a single-date picker posts**, so one-day category reports were always empty: the orders are fetched and then discarded by `array_search($orderDate, $monthList)` against a zero-length list. | **[verified here]** `DatePeriod('2026-03-01' … '2026-03-31')` yields 30 days ending 30 March; `('2026-03-05' … '2026-03-05')` yields **0**. End to end on MariaDB: a single-day selection gave a day list of length **0** and a category total of **0.00** against 60.00 of sales; after the fix, length 1 and 60.00. Reverting it reddens both checks. I first mis-read this as the review being wrong about brand — it was not; brand does the `modify` on the line before the `DatePeriod`, which I missed by reading only the `DatePeriod` line. |
| 4 | **FIXED.** **The brand year report omits December from every brand card.** At `:2532` the card total `array_sum($brandNetSales[...])` executes *before* `:2534` assigns that month's value, inside a months-outer/brands-inner loop, so the final index is never included. A correctly-accumulated `$NetSalesOfBrand` (capital O) exists at `:2576-2579` and is read nowhere — the view reads `$NetSalesofbrand`. | **[verified here]** The card total was computed before the current month was written into the series, inside a months-outer loop over `yearMonth()`'s twelve entries, so the final iteration summed indices 0..10. End to end on MariaDB with 40.00 in January and 70.00 in December: the chart series read 110.00 while the CARD read **40.00**; after the fix both read 110.00. Reverting it reproduces the 40-against-110 disagreement. The correctly-accumulated `$NetSalesOfBrand` (capital O) is dead code and now agrees by construction. |
| 5 | **FIXED.** **A cleared brand multi-select yielded an empty selection on two branches while the other three defaulted correctly.** Last-month (`:2338`) and this-month (`:2411`) use `$selectedBrand ?? …`, which fires only on `null`; year and seven-day use `empty()`. `reportSelectionWithinCurrentStore()` returns its argument unchanged when empty (`:3615-3617`). | **[verified here]** `??` fires only on `null`, and `reportSelectionWithinCurrentStore()` returns its argument unchanged when empty. Measured: `selectedBrand=''` makes the loop throw `ErrorException: foreach() argument must be of type array|object` on a merchant admin route, and `selectedBrand=[]` iterates nothing for a silently empty report. So the SAME cleared selection behaved differently depending on which period button the merchant pressed. All five brand branches now use `empty()`; three harness cases (empty string, empty array, nothing posted) pass, and reverting reproduces both failure modes. |
| 6 | **FIXED.** **The "Top 5" charts are neither top nor five.** `:2807-2821` (category) and `:2823-2833` (brand) end in `->get()` with **no `orderBy` and no `limit`**. A store with 40 categories gets 40 unordered bars under a page headed "Top Sales Report". | **[verified here]** Owner recommendation 4, first branch: ranked, limited to five, with a deterministic name tie-break. **No new product decision was needed** — the card notes already documented the metric ("the five are the top five"; "the number of distinct orders containing one of their products"), and the controller simply did not honour it. Also fixed in the same family: payment methods were grouped on the raw case-sensitive column, so 'cod' and 'COD' were two bars competing for five slots — which makes a top five *wrong*, not merely unordered. 14 checks 0 failed on MariaDB with seven items seeded so a correct five must exclude two; 6 of 6 mutations redden. |
| 7 | **FIXED (as a metric), writers unchanged.** **`orders.product_id` carries duplicates on two of its three writers**, inflating top-selling-product counts for merchant-admin and Flutter orders. `OrderController.php:460` and `Api/DashboardController.php:7112` both `implode(',', array_column(...))` with no de-duplication; only `PaymentService.php:3017` applies `array_unique`. | **[verified here]** The chart now counts DISTINCT orders per product, which is what its own note claims ("Counts orders, not quantity or value") and what the category and brand charts already used. Measured: one order listing a product eight times put it in **second place at 9** under occurrence counting and correctly outside the five at 2 distinct orders. **The writers are deliberately untouched** — de-duplicating `orders.product_id` itself would change stored data on a column other code reads, which is outside this authorisation. |
| 8 | **One payment method becomes several bars.** `groupBy('payment_type')` at `:2838` groups the raw column with PHP's case-sensitive comparison, while COD alone has three spellings (`PaymentVerificationService::COD_PAYMENT_TYPES`) plus casing variants. | Read directly. |
| 9 | **`<x-ss.stat>`'s only caveat affordance is invisible.** Its `hint` prop renders inside `<span class="ss-visually-hidden">` (`stat.blade.php:40`), so a sighted merchant never sees it. **ANA-007 — "show data quality, estimates and omissions" — cannot be satisfied through that prop.** `<x-ss.card>`'s `note` (`card.blade.php:9`) is visible and is the available slot. | Read directly. |
| 10 | **`_sales_stats.blade.php` is shared by two different handlers** — `order_chart_data.blade.php:19` and `order_bar_chart_data.blade.php:19`, fed by `order_reports_chart()` and `order_reports_chart_data()` respectively. Any change to the partial lands on both, so the two handlers must be converted together or the partial must take resolved figures. | Read directly. |
| 11 | **`final_price - delivery_price - tax_price` can double-subtract shipping and go negative.** `PaymentService.php:3031/3033` and `:3067/3069` add `delivery_price` into `final_price` **only** when `$plan->shipping_method == "on"`; the net-sale expression subtracts it unconditionally. | Read directly. |

**Defect 1 is the one worth fixing first**, because it is small, bounded, every-month, and already
solved correctly inside `MetricWindow`.

---

## 3. What the challenger established about the surfaces themselves

Three findings that shape how the remaining slices must be written:

**There is no honest one-to-one replacement for the "Order value" (net sale) tile.** The layer has
nothing yielding merchandise-value-excluding-shipping-and-tax over all orders, and composing
`GMV − ShippingAndTax` is invalid: different tables, different date columns (`orders.order_date`
vs `store_order_financials.created_at`), therefore different row sets. Substituting
`CollectedCustomerPayments` was proposed and **correctly rejected** — ANA-005 says *separate* sales
from collection, not *replace* one with the other, and the five neighbouring tiles are all demand
figures under a heading that is demand. **What that tile should show is an owner decision**, not an
engineering one.

**The metric layer has no time-series and no per-dimension shape.** Every metric reduces one scope
and one window to one `MetricResult`. A 31-day chart is 31 queries, and `orders` carries no
`(store_id, order_date)` composite index — so each is a `store_id` scan filtering by date, on an
AJAX endpoint. A per-category or per-brand breakdown is not expressible at all: `MetricScope` offers
only `store()` and `stores()`, and a category is not a tenant. Both are **layer additions**, not
wiring.

**Switching a date basis relocates history, which a chart cannot explain.** Moving a per-bucket
series from `order_date` to `paid_at` or to `store_order_financials.created_at` does not merely
change values — it changes which bucket a row belongs to. The clearest measured instance: an order
placed 31 Oct whose financial row was written 4 Nov moves its shipping spike four days, and the
chart offers no account of why.

---

## 4. Disposition

| | |
|---|---|
| **P5-3a** | **DONE.** `MetricResult::assumingUnknownCurrencyIs()` plus `unrecordedCurrencyAmount()` and the `CURRENCY_UNRECORDED` caveat. 7 new unit tests, 7 of 7 mutations redden, and 7 checks on a genuinely NULL `currency_code` row on MariaDB — including that `total()` really does throw before folding, which is the 500 this prevents. See `PHASE-05-METRIC-LAYER.md`. |
| **P5-3b** | **DONE.** Defect 1 fixed at **18** sites, not the eight first reported. Evidence: `tests/Support/Security/bin/verify-report-window-boundary-on-mysql.php`, 5 checks 0 failed, with the before-figure and the single-site mutation both measured. The merchant's "last month" sales report now includes the last day of the month. |
| **P5-3c** | Wire surfaces, one at a time, each with its before/after figure recorded — and only where the entitlement record's 2026-11-02 start does not make the figure useless. |
| ~~**owner**~~ | **ANSWERED 2026-11-17 (owner recommendation 1):** keep order value distinct from collected revenue, merchant settlement and profit. Order value stays a demand figure and was relabelled truthfully under P5-3e; the other three are proposed in `PHASE-05-FOUR-FIGURE-LABELLING-PROPOSAL.md` and await the one decision in its §3. |
| **owner** | Whether `orders.currency_code` should ever be backfilled. **Not proposed here** — the migration argues against it, and it is a live-data operation. |
| **P5-3c** | **DONE.** Defect 3 fixed, with the single-day case added to `verify-report-window-boundary-on-mysql.php` (now 7 checks, 0 failed) and the revert measured. All three per-dimension reports now read alike. |
| **P5-3d** | **DONE.** Defect 4 fixed, with the brand year card added to the harness (now 9 checks, 0 failed) and the revert measured. |
| **P5-3e** | **DONE.** Owner recommendation 3. Defect 2 corrected from verified arithmetic at 5 view sites and both catalogues; no money expression moved, and a test asserts the exact count of 8 occurrences of each accumulator so a relabelling cannot carry an arithmetic change. |
| **P5-3f** | **DONE.** Owner recommendation 4. Defects 6 and 7 (as a metric). All four charts now rank by the same documented metric — distinct orders — limited to five with a name tie-break. |
| **P5-3h** | **DONE.** Defect 5 fixed at both outlier branches, with three harness cases and both failure modes measured on revert. |
| **nothing open** | **All eleven defects in §2 are now closed or refuted.** 1, 3, 4, 5 fixed; 2 fixed under P5-3e (owner recommendation 3); 6 and 8 fixed under P5-3f (owner recommendation 4); 7 fixed as a metric with the writers deliberately untouched; 9 (the invisible caveat affordance) is carried as the blocker in the labelling proposal's §5; 10 (one partial, two handlers) is a constraint on how any wiring must be done, recorded in §4 of that proposal; and **11 is REFUTED** — `$delivery_price` is 0 whenever shipping is off, the same condition under which it is added, so `final_price − delivery − tax` is exact in both branches. |
| **tooling** | Finding 2a means these surfaces need the MySQL harness as a standing requirement, not a one-off. Two scripts now exist (`verify-metric-layer-on-mysql.php`, `verify-report-window-boundary-on-mysql.php`); `verify-top-five-reports-on-mysql.php` is the third. All three are registered in `run-mysql-verifications.sh` so CI or a reviewer can run the set. |

## 5. Export integration — the six CSV download handlers vs their on-screen twins

Each merchant report screen offers a CSV download built client-side from the same JSON the chart
reads. All six were checked against the screen they export, measured from primary source, because an
export that silently carries fewer or wrong series is a data-integrity defect, not a cosmetic one.
`ReportController` cannot run under the suite (MONTH()/YEAR() in `selectRaw`), but the export
handlers hold no `selectRaw` — they only read their own session key — so they do run under the
Feature suite, and the view JS is pinned by static source tests.

| Export handler | On-screen twin | Finding | Disposition |
|---|---|---|---|
| `category_order_export` | sales by category | **HIGH — wrong data.** The handler already returned every category's series, but the **view** row read `data.NetSaleTotal[0]` on every period, so a merchant with ten categories exported a file about **one**, repeated and stamped with that one name. | **FIXED (view).** The row loop now walks `data.NetSaleTotal.forEach` as the chart does — one row per (series, period) — and CSV-quotes the merchant-typed name. Pinned by `ReportExportBuildsEverySeriesTest`; the three-field template-literal row shape is kept so `ReportCsvHeaderLocalisationTest` still measures it. |
| `brand_order_export` | sales by brand | **HIGH — wrong data.** Same first-series-only view bug as category. | **FIXED (view).** Same walk-every-series fix, pinned the same way. |
| `order_bar_report_export` | sales bar report | **MEDIUM — incomplete.** The screen plots Total Orders and Purchased Items in a second chart, and the chart handler already stores both in the `order_bar_chart_report` session, but the export returned only the six money series — a file two columns narrower than the screen. | **FIXED (handler + view).** The handler also returns `TotalOrderCount` and `PurchasedItemTotal` (read from the same session; no new query, no request input); the view appends two columns with the same `Orders`/`Items ordered` labels `order_report` uses, type-guarded. Pinned by the bar entries in `ReportExportFailureFeedbackTest` (returned-key set, guarded-series set) and `ReportCsvHeaderLocalisationTest` (column count 7→9). |
| `order_report_export` | order line report | **None — already complete.** All eight series plus Duration = nine columns; every series is written. | No change. |
| `product_order_export` | sales by product | **None — already complete.** `NetSaleTotal`/`PurchasedItemTotal` are flat per-period arrays (the chart feeds them straight to ApexCharts' `data:`), so the existing `[i]` row already reads every period. No first-series bug. | No change. |
| `export` (registrations) | reports hub | Out of scope for the money work — it serves customer counts (users, guests), not money, and is the exemplar the header-localisation finding copied. | No change. |

**Labels and the date boundary.** The export column labels already use the settled P5-3e/P5-3g
wording (`Order value, including shipping and tax`, etc.) — label parity landed with the on-screen
labels. The date-boundary family is green on MariaDB (`verify-report-window-boundary-on-mysql.php`,
12 checks, 0 failed), including the product report's inline day-compensation, so no export inherits
a dropped-last-day window.

**Deliberately not done.** Carrying the ANA-006/007 data-coverage *caveat* (the entitlement-ledger
start date, the mixed-currency assumption) into the CSV as a footnote row is a disclosure-design
choice, not a correctness defect — the exported figures are the same numbers the screen shows with
its visible caveat. Recorded as a deliberate non-change rather than implemented speculatively; if
the owner wants the CSV self-describing, it is a small follow-up.
