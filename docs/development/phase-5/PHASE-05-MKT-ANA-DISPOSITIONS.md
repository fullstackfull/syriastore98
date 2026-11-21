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
| `MKT-009` | No guard against the platform's own competing shopper-messaging path: `POST abandon-carts-messagesend` can send while the Lynomia path is also live, with nothing preventing a shopper receiving both. A send-side guard is engineering. | small |
| `MKT-010` | greentic's product card omits the quick-view/compare hook that the other themes carry. A theme-local fix, and `/analytics` has no dependency on it. | small |
| `ANA-002` | Comparisons are absent entirely — no prior-period baseline, delta or percent change exists anywhere in either repository. The metric layer can express it (`MetricWindow::between`), so this is a second window and a difference, with the same caveat rules. | medium |
| `ANA-004` | Conversion analysis: a single conversion figure now exists on `/analytics` (P5-4b) and states its own limits; device and channel analysis do not, and `shetabit_visits` carries the user agent and referer to compute them from. | medium |
| `ANA-007` | **The one that unblocks `P05-15`, and it is SMALLER than this file first said.** `MetricCaveat` has zero consumers on a `ReportController` screen, so those six screens — and therefore their six exports — disclose nothing. **Corrected 2026-11-21:** this row was sized on CLAUDE.md's claim that `ReportController` "cannot run under the suite AT ALL", which is too strong. Measured: `order_category_reports` (1893-2360) and `order_brand_reports` (2382-2903) hold **0** MySQL-only constructs and **are** suite-reachable, so a caveat consumer can land on those two screens with an ordinary Feature test instead of an executable script. CLAUDE.md is corrected. | small-to-medium |

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
