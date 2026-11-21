# Phase 05 — dispositions for the twenty MKT and ANA requirements

**Written 2026-11-21.** The *measurements* are `PHASE-05-MKT-ANA-MEASUREMENT-2026-11-17.md` and the
row-by-row evidence is `MASTER-PLAN-REQUIREMENT-TRACEABILITY.md`. This file adds the one thing
neither has: **for each requirement, who can close it and what closing it would take.**

CLAUDE.md §4.1 requires that before any completion claim every requirement has a verified status or
an authorised exception, that every deferral is grounded in an actual owner decision, and that
totals are exact. So:

## The totals, exactly

| | count |
|---|---|
| MKT requirements (`MKT-001`…`MKT-010`) | **10** |
| ANA requirements (`ANA-001`…`ANA-010`) | **10** |
| **of those 20: VERIFIED COMPLETE** | **1** (`ANA-010`, a prohibition, machine-verified) |
| PARTIAL | **19** |
| NOT IMPLEMENTED | **0** |

`MKT-005` and `MKT-006` are traced under `P05-11` and are not repeated as their own rows; they are
counted here because they are requirements.

**Nothing in this file claims a requirement is complete.** Four of the nineteen are closer than the
matrix shows because P5-4…P5-8 landed after the measurement; that is recorded as *what changed*,
not as a new status, because a status change needs its own verification and the matrix's own gate
(`MasterPlanTraceabilityCountsTest`) requires the summary to move with it.

## A. Engineering can close these without an owner decision — 6

| req | what remains | size |
|---|---|---|
| `MKT-001` | "Linked to analytics" is absent **in the marketing→analytics direction**. Re-verified 2026-11-21 against real paths, because claiming absence from a directory that does not exist is the false reading this project warns about: `resources/views/{coupon,newsletter,marketing}/` all exist (5 + 2 + 3 files) and together contain **zero** `route('reports`, `route('analytics` or `route('finance` references. `/analytics` now exists (P5-4b), so closing this is a link and a permission check, not a feature. | small |
| `MKT-009` | **RE-MEASURED 2026-11-21, and the premise does not hold today — so no guard was built.** This row said a shopper could receive both the platform's nudge and Lynomia's. Measured: **Lynomia does not message shoppers about baskets at all yet.** `CommerceReader::abandonedCarts()` *reports* baskets and its own docblock states "NO RECOVERY LINK IS PRODUCED, here or on the other side… Reporting a cart is not messaging about one: that waits on the consent model", and `merchant/commerce-automation.blade.php` shows the basket trigger **disabled with its reason** ("Abandoned baskets are not collected for this store yet"), because "Store Syria's abandoned carts are still pre-UAT". A send-side guard keyed on "Lynomia is sending" would therefore guard against something that cannot happen; keyed on the existing per-store commerce-activation flag it would be WORSE — it would disable a working first-party feature for a store whose Lynomia basket trigger is off anyway. **What remains is an OWNER decision, not engineering:** which system owns basket messaging once Lynomia's trigger is enabled. That is a business rule. **And the duplication that IS live was found while measuring this and is now `GAP-219`:** the four first-party nudge endpoints keep no send record and carry no throttle, so the same shopper can be nudged repeatedly by one merchant today. | **engineering: none until the owner decides; `GAP-219` is separate** |
| `MKT-010` | **RE-SIZED 2026-11-21 by measurement, and it is not "small".** The stated defect is real: greentic's product card never calls `Product::actionLinks()`, while techzonix (`:10`) and stylique (`:20`) do. But the hook's only wrapper is `<div class="supdiv" style="top: 40px;">`, and the string `supdiv` occurs in **exactly one file in the whole repository** — the hook that emits it. No rule in any theme, the design system or any vendored module, so nothing gives it `position` and the inline `top` is inert even where the hook IS called. Adding the call to greentic would therefore buy structural parity with an overlay that does not position itself, as a blind layout change to a shipped storefront theme — against this project's rule that every UI change is checked in four combinations and contrast-checked. The button partials also come from vendored modules that load in no test run. Prerequisite recorded as `GAP-220`: style the overlay first, then add the call, then capture. | **blocked on `GAP-220` (styling decision + four-combination capture), not on the one-line call** |
| `ANA-002` | **COMPARISONS DONE 2026-11-21; filters and saved periods are not.** `MetricWindow::previous()` returns the equally long window ending the day before, and the two count tiles on `/analytics` (Orders, Page views) now show direction, magnitude, baseline and a percentage — in WORDS, because colour as the sole carrier of direction fails WCAG 1.4.1. A zero baseline yields NO percentage (two orders where there were none is not +100%; without that guard PHP 8 throws `DivisionByZeroError` and the screen 500s for every new store — measured). The MONEY tiles deliberately get none: they inherit `LedgerCoverage`'s verified start, so a baseline 31-60 days back lands before `store_order_financials` holds anything and the delta would be a false claim shaped like a trend; a test asserts their absence. A 24-hour hole in `previous()` was found by its own boundary test and fixed at the source. **Remaining: filters and saved periods** — two thirds of the requirement, each its own slice. | **remaining: medium (filters, saved periods)** |
| `ANA-004` | **DEVICE AND CHANNEL ANALYSIS DONE 2026-11-21.** Both are breakdowns on `/analytics` and in its export, through the metric layer. It is AGGREGATION, not user-agent parsing: `visits.device` is written by `SanitisedVisitor`, whose docblock lists it among the columns the analytics screens read, and `redact()` keeps scheme, host and path so the referring host survives sanitisation. Four refusals, each mutation-verified: an unrecorded device is an absence and not `'Other'`; a missing referer is NOT "direct"; no host is filed under a marketing channel (that needs a curated list of domains — product policy, and a visit filed under the wrong channel is worse than one under its own domain); and the 500-row referer bound is DISCLOSED when reached, driven with 500 real rows. Two defects of the pre-existing `ThemeAnalyticController` are not repeated (it orders by LABEL, and has no date bound at all on these breakdowns). **Remaining, and it is a schema question rather than a slice:** CONVERSION is not broken down by device or channel — only VISITS are. Attributing an order to the visit that produced it needs a durable link between a `visits` row and an `orders` row, and there is none: `CommerceReader` records that Store Syria has no durable cart token either. Stating a conversion rate per device without that link would be an invented join. | **remaining: owner/schema (no visit-to-order link exists)** |
| `ANA-007` | **MOSTLY CLOSED 2026-11-21. It unblocked `P05-15`, which is now VERIFIED COMPLETE.** This row was sized on CLAUDE.md's claim that `ReportController` "cannot run under the suite AT ALL", which is too strong: measured, `order_category_reports` (1893-2360) and `order_brand_reports` (2382-2903) hold **0** MySQL-only constructs and **are** suite-reachable, so the caveat consumer landed on those two screens with an ordinary Feature test rather than an executable script, and CLAUDE.md was corrected. **What is now done:** four report screens state the precision caveat (the two chart fragments, `order_product_chart`, and both money tiles on `_sales_stats`), five of the six exports carry it as a CSV column, and the sixth was measured to be counts-only. All of it reads one source, `App\Support\Reports\ReportFigureNote`, because the defect this row describes was *caused* by one literal duplicated across four blades — two carried the caveat, two did not. **`GAP-217` CLOSED the same day**, so all four money tiles on the sales-summary card and both chart fragments now disclose: `Shipping charged` gained a definition and the caveat, `Coupon value applied` kept its definition and gained the caveat, and the `<x-ss.stat>` census moved 23 -> 24. **What remains is one small consistency item, not a disclosure gap:** `Orders` on that card has no DEFINITION (it is a count, so no money caveat applies), while the `/analytics` orders tile has one. Recorded inside GAP-217's closure. | **remaining: trivial** |

## B. Blocked on an owner decision that has been asked for — 4

These are **not** engineering deferrals. Each names a decision already put to the owner.

| req | the decision | where it is recorded |
|---|---|---|
| `MKT-003` | BOGO does not apply to variant products at all. Making it apply **changes what a shopper is charged**, which the standing directive reserves to the owner. | traceability `MKT-003`; needs a decision row |
| `MKT-005`, `MKT-006` | Loyalty: whether rewards reverse on cancellation and refund, and on what basis. | `PHASE-05-MKT-006-REWARD-REVERSAL.md`, `GAP-203`, `GAP-214` |
| `MKT-008` | What makes a consent record count (double opt-in, verified address, or single opt-in on the record). Until it is answered `MarketingSendGate` stays in `report` mode — switching it to `enforce` would start treating unverified records as authority to send. | `GAP-200`, `GAP-215` |

## C. Larger product work, in scope but not a Phase 05 slice — 5

Each is a feature, not a defect. Recording them here stops them being absorbed silently into a
"Phase 05 complete" claim.

| req | what it is |
|---|---|
| `MKT-002` | "Advanced when needed" on the five Marketing surfaces — none has any progressive disclosure at all. |
| `MKT-004` | Static and dynamic customer segments. Dynamic exists only inside LynomiaChat Audiences, behind `LYNOMIA_INTEGRATION` (off). |
| `MKT-007` | Automated cart recovery. There is **no `app/Jobs` directory at all** (re-verified 2026-11-21) and no scheduled command, so recovery is one merchant click per row. |
| `ANA-003` | Custom reports and **scheduling**. Scheduling is absent entirely: a repo-wide grep for `Schedule::command|->daily()|->weekly()|->cron(` returns 8 hits and none is a report. Export is now partly done (P5-8). |
| `ANA-009` | Proactive analytics alerts — no anomaly, trend, forecast or threshold detection exists in `app/` or `packages/workdo/`. |

## D. Advanced since the 2026-11-17 measurement — recorded, not re-statused

- **`ANA-001`** — the measurement's finding was "FINANCE is not in the centre". `/finance` (P5-10,
  P5-16) and `/analytics` (P5-4) now exist, and `/platform/analytics` (P5-7) adds the operator's
  cross-tenant view. The row stays PARTIAL because the *centre* — one place that gathers sales,
  finance, products, customers and marketing — is still several screens.
- **`ANA-005`** — "collection has no figure" was the finding; `Payments received` now exists on both
  `/finance` and `/analytics`, read from `store_order_financials.paid_amount`. Settlements and
  profitability are separated. Still PARTIAL: the four are not all separately reported.
- **`ANA-006`** — both named profit figures are now disclosed; **fee data remains absent entirely**
  and `GAP-213(d)` records that `EXCLUDES_SHIPPING_AND_TAX` is still half untrue for inclusive-tax
  stores. Still PARTIAL.
- **`ANA-008`** — the `CustomerRetention` half is CLOSED (§12 of the metric definitions, plus a
  both-directions citation gate). The reporting-surface half is not. Still PARTIAL.

## What this file does not do

It does not change a single status, and it is not an acceptance document. Group A is the work
engineering may pick up next without asking; group B must not be started as engineering; group C
must not be counted as Phase 05 scope without the owner saying so; group D is a record that four
rows are closer than the matrix reads, so the next person does not re-measure them from scratch.

**Phase 05 is not complete, and `P5-5`/`P5-6`/`P5-7` being complete does not make it so.**
