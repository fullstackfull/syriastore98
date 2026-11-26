# Phase 05 — Marketing & Analytics: engineering report

**Branch** `ws/p06-ws6-analytics`, off `feature/phase-06-saas-super-admin` · **Not merged, not
pushed, not released, not deployed, not accepted.**

`GAP-172` / `P05-24`. This is the per-slice completed/not-started statement the execution plan's §5
promised and that the traceability matrix has been standing in for. Every figure in it names the
command that produced it, and every command was run on this tree on **2026-11-26** after the
worktree's own `vendor/` was corrected — before that correction a worktree's autoloader resolved
`App\*` to the *main checkout*, so any earlier figure measured the wrong tree and none is carried
forward here.

`Phase05FinalReportTest` gates this document. It asserts the slice set against the plan's own slice
list, the requirement set against the traceability matrix, that every cited path exists, that every
blocker names an id the owner decision register carries, and that no verdict claims what only a
person or a CI run can grant.

---

## 1. The one-line answer

**Sixteen slices exist; every slice's evidence set executes green; not one slice is accepted or
release-ready, and thirteen of the twenty-four Phase 05 requirements are owned by no slice at all.**

Measured, not estimated:

- **1,006 tests over 66 files, 0 failed, 0 skipped** — the per-slice evidence sets, run as one
  command (§8a). Every one of the sixteen slices has a non-empty set and every set is green.
- **204 real-MySQL checks over 10 scripts, 0 failed** (§8b). This is the first run of the whole
  Phase 05 real-MySQL evidence set together. It found a defect in three of the scripts (§9d) and,
  through the tenth — written for this report — **refuted the stated basis of the one caveat Phase 05
  prints on every money figure** (§9e). That is the single most important thing in this document.
- **6,761 unit tests passed with the one accepted skip** (§8c), **58 Contract tests passed**, and
  **4,464 tests over fourteen directories, 0 failed** (§8d).
- **6 of 6 registered surfaces CAPTURED in all four AR/EN × light/dark combinations, blocked
  ceiling 0** — the §4.9 acceptance condition, held by `Phase05BilingualAppearanceGateTest`.

And the three things this report exists to say that none of those figures says:

1. **Green tests are not requirement coverage.** 12 of the 24 requirements have no owning slice, so
   a report organised by slice would have silently defined them away. §6 carries all 12. **This read
   13 until 2026-11-26: `P05-11` was counted here while its own matrix row had carried `P5-9` since
   2026-11-17, because the matrix HEADING still read `Loyalty` with no slice id. The heading is
   corrected and `P05-11` leaves this set. `Phase05FinalReportTest` asserts the set in both
   directions, so it refused the disagreement rather than letting the two documents drift — which
   is the only reason the count was ever re-counted.** That is
   `P05-11`'s own recorded failure — loyalty was in the scope statement and in none of the original
   eight slices — and this row's second instruction.
2. **No slice has human acceptance and nothing is released.** Those two of CLAUDE.md §4.1's four
   states are not engineering's to grant, and this report grants neither to anything.
3. **The plan's own status words are not the plan's own vocabulary.** §0 declares seven terms "used
   consistently from here on"; **16 of 16 slice headings use something else** — four say a bare
   `COMPLETE`, three say nothing at all. §3 measures it.

---

## 2. What this report establishes, and what it cannot

| | |
|---|---|
| **engineering complete** | Established per slice from an executed run (§4, §8a). |
| **original requirement coverage** | Established as a SET (§5, §6): which requirements exist, which have an owning slice, and which rest on an owner decision. NOT established per requirement as a percentage — CLAUDE.md §4.1 forbids a percentage and this report gives exact totals only. |
| **human acceptance** | **Not established for anything, and not establishable here.** `P05-23`'s own gap text records that human acceptance owes the `Security-sensitive diff: human checklist`, "which is red on every head by design and which only a person may clear." |
| **production readiness** | **Not established for anything.** The plan's §0 says of `released to production`: "which **nothing in this branch is**." CLAUDE.md §4: no phase after 02 is authorised to merge or release. |

Two more things it does not do, stated because their absence is otherwise invisible:

- **`verified by CI` is claimed for no slice.** That verdict needs GitHub's check-runs API for a
  specific head; this report has no network access to it. Every figure here is local.
- **It does not re-audit the slices' own conclusions.** It executes their evidence and reports what
  the run said. Where a slice's status rests on a document rather than on a test, §4's `not
  established` column says so.

---

## 3. The vocabulary, and the plan's measured drift from it

The plan's §0 declares the vocabulary and the rule:

> A row is *approved policy* when the owner has decided it; *implemented in development* when code
> exists; *verified by local tests* when a suite measured it on a clean tree; *verified by CI* when
> GitHub's own check-runs API says so on that head; *externally blocked* when a provider or access
> this session does not have is what stops it; *awaiting human approval* when only a person can
> clear it; and *released to production* — which **nothing in this branch is**. An approved design is
> never described as implemented until its behaviour has been measured, and engineering completion
> is never described as production readiness.

**Measured against the plan's own sixteen slice headings: not one of them uses a term from that
list.** The command is in §8e. What they say instead:

| wording used | slices | why it is not sayable |
|---|---|---|
| bare `COMPLETE` | `P5-5`, `P5-6`, `P5-7`, `P5-8` | `COMPLETE` of *what*? It is the single label §0 and CLAUDE.md §4.1 both forbid: it reads as all four states at once. |
| `SHIPPED` / `ADDED AND SHIPPED` | `P5-10`, `P5-11`, `P5-12` | "Shipped" names a release. Nothing in this branch is released. |
| `ENGINEERING COMPLETE` | `P5-2` | Accurate, and still outside the list — the nearest declared term is *verified by local tests*. |
| `WIRED LOCALLY` / `BUILT LOCALLY` / `FIXES LANDED LOCALLY` | `P5-13`, `P5-14`, `P5-15`, `P5-16` | Honest in substance; "locally" is doing the work §0 gives to *implemented in development*. |
| nothing at all | `P5-1`, `P5-3`, `P5-4` | §5 requires every slice to say which of the two it is. Three say neither. |

**This report does not rewrite those headings.** Four of them are status claims and moving a status
is the coordinator's, not an engineering report's — the dispositions are handed over instead. What
the report does is give each slice a verdict **in §0's own words**, which is what §4 is.

---

## 4. The per-slice verdicts

Read with §2: the verdict column is engineering state only. **No slice has human acceptance and no
slice is released**, and the gate on this document refuses those two words outright.

`evidence` names the files executed for that slice in the single run of §8a, with that slice's own
test count from the same run. `not established` is the column that keeps the verdict honest.

<!-- REPORT-TABLE: VERDICTS -->

| slice | subject | verdict | blocked by | evidence (executed 2026-11-26, §8a) | not established |
|---|---|---|---|---|---|
| `P5-1` | finance ledger surfacing: admit `cancelled`/`refunded` to the finance screens and validators | VERIFIED BY LOCAL TESTS (reading half) · AWAITING HUMAN APPROVAL (writing half) | `D-N43` — may a merchant DECLARE those two states by hand; `P4-F127` full verified refund is still outstanding on the plan's own §0 gate | `tests/Unit/Merchant/FinanceLedgerTypeFilterTest.php`, `tests/Feature/Commerce/FinanceAdjustmentAccountabilityTest.php`, `tests/Feature/Commerce/CancellationLedgerSemanticsTest.php`, `tests/Feature/Commerce/RefundEntitlementReversalTest.php` — 57 tests, 0 failed | Whether the form should OFFER the two states. The screen states all six entitlement states truthfully; what is undecided is whether a merchant may set two of them. |
| `P5-2` | the metric layer — one query object per metric, no controller computing money inline | VERIFIED BY LOCAL TESTS | `D-N35` — whether the five figures no surface renders should be shown, and under which labels; `P4-F128` reconciliation is outstanding on the §0 gate | `tests/Feature/Metrics/MetricLayerTest.php`, `tests/Unit/Metrics/MetricValueObjectTest.php`, `tests/Unit/Metrics/MetricResultCurrencyIdentityTest.php`, `tests/Unit/Metrics/MetricResultWithoutEmptyCurrenciesTest.php`, `tests/Unit/Metrics/MetricWindowPreviousTest.php`, `tests/Unit/Metrics/MetricDefinitionsAreDocumentedTest.php` — 114 tests, 0 failed; real MySQL `tests/Support/Security/bin/verify-metric-layer-on-mysql.php` 65 checks, 0 failed | Nothing about the five unrendered figures. `UnpaidOrders`, `CancelledOrders`, `CompletedRefunds`, `NetFinancialReceipts` and `ShippingAndTax` are each tested and read by no screen, export or alert — a tested metric is not a delivered figure. |
| `P5-3` | point the existing report surfaces at the metric layer, one at a time, with the delta stated | IMPLEMENTED IN DEVELOPMENT — PARTIAL | `D1`, `D2` govern what a re-pointed money figure may be labelled | `tests/Feature/Reports/FinanceReportInTheCentreTest.php`, `tests/Feature/Reports/FinanceReportPeriodTest.php`, `tests/Feature/Reports/DimensionPeriodRevenueTest.php`, `tests/Unit/Reports/SalesSummaryLabelTruthTest.php`, `tests/Unit/Reports/CalendarPeriodTest.php`, `tests/Feature/Reports/LastMonthWindowOverflowTest.php` — 53 tests, 0 failed; real MySQL `tests/Support/Security/bin/verify-report-window-boundary-on-mysql.php` 12 checks, 0 failed | **How many report surfaces remain.** The appearance register's own note says "one report surface re-pointed so far", and this report does not establish a count of the rest. `ReportController` still holds 34 `MONTH(`/`YEAR(` sites in code, measured by the ANA-005 script, so most of it is unreachable by the suite. |
| `P5-4` | merchant analytics dashboard: sales, orders, conversion, customer insight | VERIFIED BY LOCAL TESTS | `D-N32` — is a conversion rate that divides by PAGE VIEWS acceptable, or must it be visitors or sessions | `tests/Feature/Analytics/MerchantAnalyticsScreenTest.php`, `tests/Feature/Analytics/MerchantAnalyticsFiguresTest.php`, `tests/Feature/Analytics/AnalyticsComparisonTest.php`, `tests/Feature/Analytics/ConversionBaselineTest.php`, `tests/Feature/Analytics/VisitCoverageStartTest.php`, `tests/Feature/Analytics/AnalyticsPeriodFilterTest.php`, `tests/Feature/Analytics/MerchantAnalyticsTenantIsolationSweepTest.php`, `tests/Feature/Analytics/AnalyticsTilesCarryTheirCaveatsTest.php` — 98 tests, 0 failed; real MySQL `tests/Support/Security/bin/verify-analytics-breakdowns-on-mysql.php` 12 checks, 0 failed; appearance `analytics` CAPTURED ×4 | That the conversion figure MEETS the requirement. `P05-07`'s own gap says the figure divides by page views and the requirement names visitors or sessions: "the requirement as worded is not met; an honest approximation of it is delivered." |
| `P5-5` | campaign and promotion analytics; the coupon-discount tile | VERIFIED BY LOCAL TESTS | `D-N36` — what *Coupon discounts given* INCLUDES (it counts a coupon on an order later cancelled or refunded); `D-N33` for the attribution half, see `P05-13` | `tests/Feature/Metrics/CouponRedemptionCoverageTest.php`, `tests/Feature/Analytics/CampaignTrafficTest.php`, `tests/Feature/Analytics/OrdersByChannelTest.php`, `tests/Feature/Analytics/VisitBreakdownTest.php` — 47 tests, 0 failed; real MySQL `tests/Support/Security/bin/verify-coupon-code-matching-on-mysql.php` 6 checks, 0 failed | Campaign ORDERS and REVENUE. The panel counts arrivals; nothing links a visit row to an order row, so "this campaign earned you X" has no input — `D-N33`, a schema decision. |
| `P5-6` | retention and repeat purchase | VERIFIED BY LOCAL TESTS | `D1` governs the period the surface may show (Option A, already approved) | `tests/Feature/Analytics/MetricThresholdTest.php`, `tests/Feature/Metrics/CustomerLedgerMoneyTest.php` — 59 tests, 0 failed; appearance `analytics` CAPTURED ×4 | Nothing outstanding that this report found. `P05-12` is the one Phase 05 requirement the matrix marks VERIFIED COMPLETE. |
| `P5-7` | platform / operator reporting | VERIFIED BY LOCAL TESTS | none open that this report found | `tests/Feature/Platform/PlatformAnalyticsScreenTest.php`, `tests/Feature/Platform/OperatorDashboardTopStoresTest.php`, `tests/Feature/Platform/OperatorDashboardPopularStoresTest.php`, `tests/Feature/Platform/OperatorTopStoresAreRankedByTheDatabaseTest.php`, `tests/Feature/Security/OperatorUnchosenStoreReportsTest.php` — 116 tests, 0 failed; real MySQL `tests/Support/Security/bin/verify-top-five-reports-on-mysql.php` 19 checks and `verify-dashboard-top-sales-scope-on-mysql.php` 13 checks, 0 failed; appearance `platform-analytics` + `operator-dashboard` CAPTURED ×4 | Parity with the merchant screen. `P05-14` records that the operator screen carried 4 figures where the merchant screen carries 13; this report does not re-count them. |
| `P5-8` | export | VERIFIED BY LOCAL TESTS | `D-N38` — whether a qualifier belongs on a CSV column header | `tests/Feature/Analytics/FigureExportTest.php`, `tests/Unit/Metrics/FigureCsvEscapingTest.php`, `tests/Unit/Reports/ReportCsvHeaderLocalisationTest.php`, `tests/Feature/Reports/ReportExportsCarryTheScreensCaveatTest.php`, `tests/Feature/Reports/SalesExportCarriesTheCollectedFigureTest.php`, `tests/Feature/Reports/SalesExportCarriesTheLedgerCaveatsTest.php`, `tests/Feature/Reports/ScheduledAnalyticsReportTest.php` — 83 tests, 0 failed | — The export's disclosure column was found DROPPING the ledger figure's caveats on 2026-11-26 and is fixed (§9b), so this verdict is younger than the slice's own heading. |
| `P5-9` | loyalty | IMPLEMENTED IN DEVELOPMENT — PARTIAL | `MKT-006` — reverse rewards on cancellation and refund: measured NOT MET, and recorded as not met rather than deferred; `MKT-005` PARTIAL (VIP tiers and referrals not located, `GAP-191`) | `tests/Unit/Loyalty/RewardDiscountCapTest.php`, `tests/Unit/Loyalty/RewardPointDiscountTest.php` — 34 tests, 0 failed | **The measurable half.** `P05-11`'s own words: the metric document defines no loyalty metric and no merchant-facing loyalty surface exists. The shipped half is a division-by-zero fix on eleven payment paths, which is a defect repair, not the requirement. |
| `P5-10` | `/finance` profit figures | VERIFIED BY LOCAL TESTS | `ANA-006` answered by `MetricCaveat::EXCLUDES_PAYMENT_FEES`; `D-N35` for the figures no surface renders | `tests/Feature/Merchant/FinanceInventoryValuationTest.php`, `tests/Feature/Merchant/FinanceLedgerFiguresTest.php`, `tests/Feature/Metrics/MarginDisclosesPaymentFeesTest.php`, `tests/Feature/Reports/StoreInventoryValuationMetricTest.php` — 82 tests, 0 failed; real MySQL `tests/Support/Security/bin/verify-store-inventory-valuation-on-mysql.php` 20 checks and `verify-ana005-sales-collection-on-mysql.php` 22 checks, 0 failed; appearance `finance` CAPTURED ×4 | Whether a margin that subtracts no payment fee is the figure the owner wants. The omission is DISCLOSED on every surface since 2026-11-25; the fee DATA question (`D-N9`/`D-N13`) is reserved. |
| `P5-11` | `<x-ss.stat>` could not display a caveat | VERIFIED BY LOCAL TESTS | none open; `GAP-201`'s hint half closed 2026-11-17, its `MetricCaveat`-consumer half closed 2026-11-25/26 | `tests/Unit/Ui/StatHintIsVisibleTest.php`, `tests/Unit/Ui/CaveatedFigureRendersItsCaveatsTest.php`, `tests/Unit/Metrics/CaveatedFigureTest.php` — 34 tests, 0 failed; appearance `analytics` + `finance` CAPTURED ×4 | The Chromium contrast figures (5.98:1 light, 7.18:1 dark) were measured by the slice and are NOT re-measured here; `docs/development/phase-5/evidence/` holds the capture. |
| `P5-12` | the vendored reward listener destroyed balances on orders that redeemed nothing | VERIFIED BY LOCAL TESTS | `D6` governs redemption eligibility, see `P5-13` | `tests/Unit/Loyalty/RewardRedemptionFlagTest.php` — 19 tests, 0 failed | Nothing outstanding that this report found. The containment is a first-party listener pre-empting the module's, which `docs/development/phase-5/PHASE-05-MKT-006-REWARD-REVERSAL.md` records. |
| `P5-13` | D6 server-side redemption eligibility | IMPLEMENTED IN DEVELOPMENT — PARTIAL | `GAP-214` — a shopper who starts a checkout as one customer and returns as another is priced from one and debited from the other: an owner decision; `D6` is NOT closed | `tests/Feature/Commerce/RewardRedemptionCheckoutGateTest.php`, `tests/Unit/Loyalty/RewardRedemptionEligibilityTest.php` — 56 tests, 0 failed | The gateway-return handlers. Stage 1 covers the quote and the WhatsApp/Telegram writer; the returns are not wired, and that is the open half. |
| `P5-14` | the PR #140 deletion audit | IMPLEMENTED IN DEVELOPMENT — PARTIAL | `GAP-202`, `GAP-204`, `GAP-209`, `GAP-210`, `GAP-211` recorded, not decided; `D6` governs the redemption half. **An earlier draft of this row also named `D3` and `D5`, and the gate on this document refused them — correctly: those are PR #140's own internal labels as the plan's `P5-14` section uses them, not ids any owner decision register carries, and `D3` in `OWNER-DECISION-PACKAGE.md` is a different question entirely (the Arabic catalogue). An engineering report cannot defer behind a label it invented.** | `tests/Feature/Commerce/ReturnHandlerPlanInitialiserTest.php`, `tests/Feature/Commerce/SecondReturnTotalTest.php` — 25 tests, 0 failed; appearance `finance` CAPTURED ×4 | The owner items. The slice's own heading says "OWNER ITEMS RECORDED, NOT DECIDED", and recording is not deciding. |
| `P5-15` | D8 first-party consent store | IMPLEMENTED IN DEVELOPMENT — UNWIRED | `GAP-200` stays open: the store is built and reads from no screen; `MKT-008` is the enforcement half | `tests/Feature/Marketing/ConsentLedgerTest.php`, `tests/Feature/Marketing/ConsentSchemaTest.php`, `tests/Feature/Marketing/ConsentLedgerOrderingAndEvidenceTest.php`, `tests/Unit/Marketing/ConsentLedgerCensusTest.php`, `tests/Unit/Marketing/ConsentStateTest.php` — 77 tests, 0 failed; real MySQL `tests/Support/Security/bin/verify-consent-ledger-concurrency-on-mysql.php` 17 checks, 0 failed | Any merchant-visible behaviour. A ledger nothing reads changes nothing a merchant or a shopper can see. Wiring it to a screen takes an appearance-register row that does not exist. |
| `P5-16` | D1 and D2 on `/finance` | VERIFIED BY LOCAL TESTS (the `/finance` half) · IMPLEMENTED IN DEVELOPMENT — PARTIAL (reports and exports) | `D1`, `D2` are approved and applied; `GAP-213` carries the margin wording | `tests/Feature/Merchant/LedgerCoverageTest.php`, `tests/Feature/Metrics/CaveatCarrierReachesEverySurfaceTest.php`, `tests/Unit/Reports/ReportFigureNoteIsTheOnlySourceTest.php`, `tests/Feature/Reports/SalesReportCollectionFigureTest.php`, `tests/Feature/Reports/SalesStatsPassesItsCollectionFigureTest.php` — 52 tests, 0 failed; appearance `finance` + `finance-reports` CAPTURED ×4 | The slice's own heading says "THE REPORTS AND EXPORTS ARE NOT DONE". Part of that closed on 2026-11-26 (§9b) and this report does not establish how much is left. |

<!-- /REPORT-TABLE -->

---

## 5. Every Phase 05 requirement, slice or no slice

The slice table above is **not** the scope. This table is, and it is the matrix's own set: 24
requirements, every one carried, in both directions, by the gate on this document.

`slice` is the owning slice as the matrix's own heading names it. `—` means the requirement belongs
to no slice, which is `P05-11`'s recorded failure mode and the reason this table exists.
`rests on an owner decision` answers CLAUDE.md §4.1's rule: a deferral that rests on an engineering
note is **not** a deferral.

<!-- REPORT-TABLE: REQUIREMENTS -->

| requirement | slice | matrix status | rests on an owner decision | this report's note |
|---|---|---|---|---|
| `P05-01` | `P5-1` | OWNER DECISION REQUIRED | yes — `D-N43` | The reading half is verified by local tests; the writing half is the decision. |
| `P05-02` | `P5-2` | PARTIALLY IMPLEMENTED | yes — `D-N35` open | The layer is verified on SQLite and on real MySQL. Five tested metrics reach no surface. |
| `P05-03` | — | PARTIALLY IMPLEMENTED | no — the requirement wording is the owner's; no decision is pending | `MetricDefinitionsAreDocumentedTest` ties every metric to its section and its named verification tests, executed here inside `P5-2`'s 114. |
| `P05-04` | — | PARTIALLY IMPLEMENTED | **no — "None found"** | `GAP-152`. The carrier mechanism exists and was ADOPTED by 21 more figures on 2026-11-26 (§9a); 26 display figures in four classes still compose their hint by hand. Adoption, not mechanism. |
| `P05-05` | `P5-3` | PARTIALLY IMPLEMENTED | no — "None found" | The owner instruction not to reuse `SUM(orders.final_price)` is quoted in the plan; the count of un-re-pointed surfaces is not established. |
| `P05-06` | `P5-4` | PARTIALLY IMPLEMENTED | yes — `D-N32` | The dashboard ships and is verified; one of its four figures has an undecided basis. |
| `P05-07` | `P5-4` | PARTIALLY IMPLEMENTED | **no — "None. The §3 exclusion is an engineering plan statement, NOT a cited owner decision"** | **One of the two the row names.** It now HAS an owning slice (`P5-4`), which the row's wording predates. The figure ships; it divides by page views where the requirement says visitors or sessions, and says so on the tile every time. `D-N32`. |
| `P05-08` | `P5-4` | PARTIALLY IMPLEMENTED | no — "None found" | New-vs-returning buyers ship on `/analytics`; `UNIDENTIFIED_CUSTOMERS_EXCLUDED` now travels with the figure as a caveat key (§9a). |
| `P05-09` | `P5-5` | PARTIALLY IMPLEMENTED | yes — `D-N37` open | Which surface is the supported campaign surface is undecided. |
| `P05-10` | `P5-5` | OWNER DECISION REQUIRED | yes — the F134/F135 review instruction, and `D-N36` | Redemption records are authoritative per window since the fix; what the figure INCLUDES is the decision. |
| `P05-11` | `P5-9` | NOT IMPLEMENTED | **no — "No owner decision deferring or descoping loyalty was found anywhere in the repository"** | **NO LONGER IN THIS SET, and that is the correction of 2026-11-26.** The slice gap is closed: `P5-9 — Loyalty` was added to the plan on 2026-11-17 rather than deferred, and this row's own body has said so ever since — only the matrix HEADING still read `Loyalty` with no id, which is what kept it counted as sliceless for nine days. **The measurable capability is still not built**, `MKT-006` is measured NOT MET and recorded as not met, and `GAP-191` carries `MKT-005`'s open half; none of that changes because the slice exists. The row is kept, struck from the count. |
| `P05-12` | `P5-6` | VERIFIED COMPLETE | yes — `D1`, approved | The one Phase 05 requirement the matrix marks verified complete. |
| `P05-13` | — | PARTIALLY IMPLEMENTED | **no — "None found"** | **The other of the two the row names, and it genuinely has no slice.** Arrivals ship inside `P5-5`; orders and revenue per campaign are blocked on the schema, `D-N33`. |
| `P05-14` | `P5-7` | PARTIALLY IMPLEMENTED | yes — `D1` governs the period | Operator reporting ships and is verified; parity with the merchant screen is not established. |
| `P05-15` | `P5-8` | PARTIALLY IMPLEMENTED | yes — `D-N38` open | Export ships and carries the screen's disclosure as a COLUMN; one of its gaps was found and closed on 2026-11-26 (§9b). |
| `P05-16` | — | PARTIALLY IMPLEMENTED | no — "None found" | 6 of 6 registered surfaces CAPTURED in all four combinations, blocked ceiling 0. The gate covers the REGISTERED set; a surface nobody registered is outside it by construction. |
| `P05-17` | — | PARTIALLY IMPLEMENTED | no — the Phase 2.5 BLOCKED rows were dispositioned in a phase document | Not re-measured here. A Phase 2.5 disposition is an engineering disposition. |
| `P05-18` | — | IMPLEMENTED - VERIFICATION INCOMPLETE | no — traces to P1-SEC-09 in Phase 01 | Not re-measured here beyond the permission gating exercised inside the `P5-4` and `P5-7` sets. |
| `P05-19` | — | PARTIALLY IMPLEMENTED | no — "None found" | `GAP-167`. A 280-site Eloquent tenancy census over the analytics and report surfaces landed on 2026-11-26: 126 principal-derived, every other site dispositioned by shape, 0 unscoped analytics reads found. §9c. |
| `P05-20` | — | PARTIALLY IMPLEMENTED | no — the constraint wording is the owner's, quoted in the plan | Not re-measured here. |
| `P05-21` | — | IMPLEMENTED - VERIFICATION INCOMPLETE | yes — `D-N39` open | Standing owner constraint recorded in CLAUDE.md §2. |
| `P05-22` | — | OWNER-APPROVED DEFERRED | **yes — a conditional owner instruction whose condition was met** | The one deferral in Phase 05 that rests on an owner decision rather than an engineering note. |
| `P05-23` | — | PARTIALLY IMPLEMENTED | no — the standard is the financial work's, unchanged | §7 records which of the nine points this report establishes per slice and which it does not. Human acceptance owes a checklist only a person may clear. |
| `P05-24` | — | NOT IMPLEMENTED | no — "None found" | **This document.** It does not close the row by existing: the row is the coordinator's to move, and §2 says what the document does not establish. |

<!-- /REPORT-TABLE -->

---

## 6. The thirteen requirements no slice owns

The row asks for "the conversion and marketing-performance requirements that still have none".
**Measured against the matrix's own headings there are thirteen, not two**, and two of the row's
three named examples have moved since it was written:

- **conversion (`P05-07`) now HAS an owning slice** — the matrix heading reads "P5-4 Conversion"
  since the 2026-11-22 re-measurement. The row's "still have none" predates that. It is carried in
  §5 anyway, and its substance is still open: `D-N32`.
- **loyalty (`P05-11`) had no slice in the matrix HEADING until 2026-11-26** (now `P5-9 Loyalty`), although the row correctly notes
  `P5-9 — Loyalty` was added to the plan on 2026-11-17. So the plan closed the slice gap and the
  matrix heading did not follow. That is a disposition for the coordinator, not an edit here.
- **marketing performance (`P05-13`) genuinely has none**, and is blocked on `D-N33`.

The other ten with no owning slice are `P05-03`, `P05-04`, `P05-16`, `P05-17`, `P05-18`, `P05-20`,
`P05-21`, `P05-22`, `P05-23`, `P05-24`. Nine of them are cross-cutting conditions rather than
features — the definition contract, trustworthy analytics, bilingual interfaces, responsive
dashboards, permissions, two scope constraints, the verification standard and this report — and one,
`P05-22`, is the single owner-approved deferral.

**Why the count is asserted rather than the two names.** `Phase05FinalReportTest` compares the
no-slice set in both directions against the matrix. A figure that only bounds from above lets the
set grow quietly; one that is not asserted downwards keeps asking for work that a slice has since
taken.

---

## 7. The nine-point verification standard, per point

§4 of the plan sets nine points. `P05-23`'s gap text says a per-slice record of them "may be a
section of the Phase 05 final report (`P05-24`) but does not replace the work". This is that
section, and it says which points this report establishes rather than claiming all nine.

| point | established by this report | how, or why not |
|---|---|---|
| 1. a demonstrated failing case | **no, except where this report created one** | A failing case is a historical event. It is recorded per slice in the phase documents; this report executes the resulting tests, which is a different claim. Where this report found a defect it demonstrated the failing case itself — §9. |
| 2. a positive control | **partly** | Every slice's set contains premise assertions that fail when a fixture stops producing the thing under test; several are named `the premise that…`. Not enumerated per slice here. |
| 3. a negative regression | **partly** | Same. The tenant-isolation sweeps in the `P5-4` and `P5-7` sets are negative by construction. |
| 4. falsification — revert the fix, confirm the test reddens | **no, except for this report's own changes** | Falsification is an act at the time of the fix. The three changes in §9 were each falsified here and the output is quoted. For the slices' own fixes, the records are in the phase documents. |
| 5. independent review for money, authorization or tenancy | **no** | Not this report's to grant, and it says so rather than implying it. The `GAP-167` census change of §9c was reviewed against the committed `tenant-authz-reviewer` checklist by its own author, which is weaker than an independent pass and is recorded as such. |
| 6. real MySQL where SQLite could hide the defect | **YES — 10 scripts, 204 checks, 0 failed** | §8b. The first execution of the whole set together. It found a defect in three of the scripts (§9d) and, through the tenth, refuted the stated basis of the caveat every money figure carries (§9e). |
| 7. the full applicable gates on the final committed tree | **partly: the local gates, yes; CI, no** | §8c and §8d. `verified by CI` is claimed for nothing. |
| 8. honest documentation, including every caveat the metric carries | **PARTLY, and the gap is the opposite of the one expected** | The MECHANISM is gated: `CaveatCarrierReachesEverySurfaceTest` and `AnalyticsTilesCarryTheirCaveatsTest` enumerate each figure's caveats AT RUN TIME and require every one on the screen and in the export, which found two live omissions (§9a). But a caveat's BASIS was gated by nothing, and the one Phase 05 prints on every money figure turns out to be refuted (§9e). An enforced mechanism carrying an unmeasured claim is a worse failure than a forgotten sentence, because it propagates. `FloatPrecisionBasisIsRecordedTest` closes that half. |
| 9. AR/EN × light/dark on every surface the slice renders | **YES — 6 of 6 surfaces CAPTURED, blocked ceiling 0** | `Phase05BilingualAppearanceGateTest`, 12 tests, 614 assertions. The gate covers the REGISTERED surfaces; a surface nobody registered is outside it, which `PHASE-05-BILINGUAL-APPEARANCE-GATE.md` states as its own limit. |

---

## 8. Evidence — every command

Run on `ws/p06-ws6-analytics` at `1ab3c1830` (§8a–§8d) on 2026-11-26, from
`/home/user/wt/ws6-analytics`, after the worktree's own `vendor/` was corrected.

### 8a. The per-slice evidence sets — one run, 66 files

```
php artisan test <the 66 files named in §4> --log-junit <junit>
Tests:    1006 passed (5213 assertions)
Duration: 299.73s
```
JUnit aggregation by class: **1,006 tests, 0 failures, 0 skipped.** The per-slice counts in §4 are
that run's own, aggregated by test class. The 66 files partition across the sixteen slices — no file
is counted for two slices, so the per-slice counts sum to exactly 1,006.

### 8b. Real MySQL — MariaDB 10.11.14, nine scripts

Each script creates and drops its own throwaway database and refuses a name without `verify` in it.

```
php tests/Support/Security/bin/verify-metric-layer-on-mysql.php                  65 checks, 0 failed
php tests/Support/Security/bin/verify-ana005-sales-collection-on-mysql.php       22 checks, 0 failed
php tests/Support/Security/bin/verify-analytics-breakdowns-on-mysql.php          12 checks, 0 failed
php tests/Support/Security/bin/verify-report-window-boundary-on-mysql.php        12 checks, 0 failed
php tests/Support/Security/bin/verify-top-five-reports-on-mysql.php              19 checks, 0 failed
php tests/Support/Security/bin/verify-coupon-code-matching-on-mysql.php           6 checks, 0 failed
php tests/Support/Security/bin/verify-consent-ledger-concurrency-on-mysql.php    17 checks, 0 failed
php tests/Support/Security/bin/verify-dashboard-top-sales-scope-on-mysql.php     13 checks, 0 failed
INVMETRIC_MYSQL_SOCKET=/run/mysqld/mysqld.sock \
  php tests/Support/Security/bin/verify-store-inventory-valuation-on-mysql.php   20 checks, 0 failed
php tests/Support/Security/bin/verify-money-column-precision-on-mysql.php        18 checks, 0 failed
                                                                          total 204 checks, 0 failed
```

The last of those is NEW, written for this report, and it is the one that changed a conclusion —
§9e.

**One script needs an environment variable and says so.** `verify-store-inventory-valuation-on-mysql.php`
does not auto-discover the server socket as its siblings do; it exits 3 and names the remedy
(`Set INVMETRIC_MYSQL_SOCKET …`), which is a correct precondition report, so it is recorded here
rather than patched. The other three that needed one did NOT say so, and that is §9d.

### 8c. The CI-blocking suites

```
php artisan test --testsuite=Unit        Tests: 1 skipped, 6761 passed (60559 assertions)  483.90s
php artisan test --testsuite=Contract    Tests: 58 passed (242 assertions)                  17.56s
```
The one skip is the single accepted skip CLAUDE.md §3 declares. The Feature suite's declared
accepted-failure set is empty, and this report did not run the whole Feature suite — §8d is the
focused sweep instead.

### 8d. The fourteen-directory sweep

```
php artisan test tests/Feature/{Analytics,Reports,Metrics,Platform,Tenancy,Merchant} \
                 tests/Unit/{Metrics,Reports,Ui,Ci,Governance,Tenancy,Analytics,Localisation}
Tests:    4464 passed (35741 assertions)
Duration: 1295.72s
```
JUnit: **4,464 tests, 0 failures.**

### 8e. The vocabulary measurement of §3

Every `### P5-N — …` heading of the execution plan, with its bolded status claim tested against
§0's seven declared terms, case-insensitively:

```
16 of 16 slice headings use wording outside the plan's own §0 vocabulary
  bare COMPLETE          P5-5  P5-6  P5-7  P5-8
  SHIPPED                P5-10 P5-11 P5-12
  ENGINEERING COMPLETE   P5-2
  …LOCALLY               P5-13 P5-14 P5-15 P5-16
  no status claim at all  P5-1  P5-3  P5-4
```

---

## 9. What was found and fixed while writing this report

Writing a verdict from executed evidence found four things. All four are fixed, each was falsified,
and none was discovered by reading a document.

### 9a. Two caveats a metric attached and no surface stated — `P05-04`

`CouponRedemption.php:152` attaches `MetricCaveat::FLOAT_PRECISION` unconditionally, because
`order_coupon_details.coupon_discount_amount` is FLOAT. The merchant coupon-discount tile composed
its hint by hand and stated the precision limit on **none** of its three surfaces — `/analytics`,
`/analytics/export` and the scheduled-report mail. `GrossMerchandiseValue.php:55` attaches the same
caveat and the merchant dashboard's headline `Order value` tile stated it nowhere either, its hint
having been composed in a thirty-line raw-PHP block inside the view.

Closed by converting 21 analytics figures to `CaveatedFigure`, whose display text is COMPUTED from
its caveat list, and by rendering them through `<x-ss.figure>`, which has no `hint` prop to forget.
`AnalyticsTilesCarryTheirCaveatsTest` and `DashboardOrderValueStatesItsPrecisionTest` enumerate each
tile's caveats at run time rather than listing sentences.

### 9b. The sales exports replayed a money figure and dropped its disclosures — `P05-15`

`ReportFigureNote::salesRowHint($tile)` read `$tile['value']` and nothing else — not `hint`, not
`caveats` — so the two sales exports carried the ledger's `Payments received` NUMBER with none of
the disclosures the screen prints beside the same number. The caveat list was already in the
method's hand: `ReportController::salesCollectionTile()` flattens a `CaveatedFigure` with
`->toArray()`, whose wire form carries `caveats` for exactly this purpose. Phase 05's own rule is
that an export which drops a caveat is a defect.

Independent review noted one side effect and accepted it as a non-defect, recorded here rather than
left to be rediscovered: the cell's sentences now appear in a different ORDER — the row's own
precision statement, then the ledger figure, where before the figure came second. Nothing is lost
and the disclosure column has no sort order a reader depends on.

### 9c. The analytics tenancy census — `P05-19`

`GAP-167` asked for a census of every read of `Order`, `Visit` and the other unscoped models in the
analytics and report controllers, asserting a store predicate **derived from the principal**.

**354 sites** across six controllers and four producer directories: **187 principal-derived, 50
ownership-checked, 10 route-resolved, 14 parameter-threaded, 6 structurally un-predicable, 11
request-sourced and 76 with no predicate in the statement window** — every non-principal shape
dispositioned with its count and its reason, in both directions, 108 vouched for and 59 declared.
**No unscoped analytics read was found to fix**, and that is a measured result: every non-principal
site in the analytics producers is accounted for.

**The five money-bearing models were added on 2026-11-26** (`OrderCouponDetail`, `OrderTaxDetail`,
`OrderBillingDetail`, `Tax`, `Shipping`), which is §9f.

**Those are the figures AFTER an independent review found three defects in the census itself**, and
the corrections are worth more than the totals:

- **One character hid 34 reads.** The site pattern's lookbehind excluded a literal backslash, so
  `\App\Models\Order::where(...)` was invisible — 31 of them in `Api/DashboardController.php`, the
  file the census claims to read whole, several with no store predicate at all. 280 → 312 sites. The
  premise test could not catch it, because it required the known shapes to be found *somewhere* and
  the unqualified spelling satisfied it; the premise is now a self-test of the pattern against both
  spellings and against what it must refuse.
- **44 dispositions asserted the absence of a gate nobody had looked for.** Their reason read "with
  no `resolveOwnedStore()` in the handler", and the request tokens were tested BEFORE the principal
  ones — so the admin API's documented shape, gate the requested id then filter on the gated store,
  classified as `request`. Testing the principal first moved **35 sites from `request` to
  `principal`**, and the 17 that remain say only what is supported.
- **`ProductBrand` was `Category`'s identical twin and was not watched at all** — 17 reads in
  `ReportController`, 10 of them a bare `find()`, with no `BelongsToStore` and no `StoreScope`
  whatever. 312 → 337 sites. The watch list's completeness is now a measurement: every unscoped
  Eloquent model these files read must be watched or exempted by name with a reason, in both
  directions, and **five of the 24 exemptions are money-bearing and named as the next to watch**
  (`OrderCouponDetail`, `OrderTaxDetail`, `OrderBillingDetail`, `Tax`, `Shipping`).

`tests/Support/bin/mutate-gap167.py` makes the census's own strongest claim re-runnable: five
mutations caught, and **one declared survivor** — deleting one of the four
`reportSelectionWithinCurrentStore()` calls in `order_brand_reports` moves no number, because the
gate check is per-method and that one method holds four. That is the census's LIMIT 1 quantified
rather than asserted.

### 9d. Three real-MySQL evidence scripts reported a precondition as a failed check

**Found by executing the whole Phase 05 real-MySQL set together, which §8b appears to be the first
run of.** `verify-ana005-sales-collection-on-mysql.php` exited **1 — "a check FAILED"** — with

```
FAIL  the script threw: Illuminate\Database\QueryException -- Database file at path
      [.../database/database.sqlite] does not exist (Connection: sqlite,
      SQL: delete from "sessions" where "id" = …)
2 checks, 1 failed
```

`config/session.php` defaults to the `database` driver, so booting the framework with no `.env`
lets the DEFAULT connection — still sqlite — touch the session table before the script points
anything at MySQL. The sibling `verify-analytics-breakdowns-on-mysql.php` documents the remedy in
its USAGE line (`SESSION_DRIVER=array`); these three did not. With it, the same script is **22
checks, 0 failed**.

**So the product was sound and the script was wrong about its own result.** Its stated exit contract
reserves 2 for "a precondition was not met"; reporting an absent sqlite file as a failed CHECK tells
a reader of the Phase 05 evidence that a money figure is broken when nothing is. An evidence script
that cries wolf makes the whole evidence set less trustworthy — which is the reporting-honesty
failure this row exists to close, arriving through the evidence rather than through the prose.

Fixed in `verify-ana005-sales-collection-on-mysql.php`,
`verify-report-window-boundary-on-mysql.php` and `verify-top-five-reports-on-mysql.php` by setting
the session driver **before** `bootstrap()` — a precondition nobody can forget is better than one
they must remember. The ordering is the whole fix and was measured: a `config([...])` call after
`bootstrap()` changed nothing, because `config/session.php` reads the environment while the config
is loaded.

Falsified: with the three lines removed, `exit=1, 2 checks, 1 failed`; with them, `exit=0, 22
checks, 0 failed`. Checksums before and after confirm the restore.

### 9e. THE CAVEAT PHASE 05 PRINTS ON EVERY MONEY FIGURE HAS A REFUTED BASIS — `P05-03`, `P05-04`

This is the finding this report exists to produce, and it arrived through the evidence rather than
through the prose. The coordinator raised it; it is independently reproduced here, and the
reproduction asks one question more than the challenge did.

**The claim under test**, verbatim from `MetricCaveat.php`: "The figure comes off
`orders.final_price`, which is FLOAT — about seven significant digits on a currency that runs to
millions." The sentence it produces — *"This figure is stored at limited precision and may differ
from the ledger in the smallest units"* — reaches a merchant on the dashboard's headline `Order
value` tile, on `/analytics`' coupon-discount tile, on four report screens and in five export
files.

**Measured on MariaDB 10.11.14** by `verify-money-column-precision-on-mysql.php`, 18 checks, 0
failed, through Laravel's own schema builder with the migrations' exact declarations:

```
A. $table->float() emits        DATA_TYPE=double  NUMERIC_PRECISION=22   (both columns)
B. round trip, exact            0.01 · 99.99 · 999999.99 · 1234567.89 · 2500000.49
C. a real FLOAT, for contrast   999999.99 -> 1000000 · 1234567.89 -> 1234570 · 2500000.49 -> 2500000
D. 10,000 accumulated rows      SQL SUM() and PHP `+=` differ from the exact decimal total by
   (including 0.07, which has   0.0000111163 on a total of 253,136,425.00
    no exact binary form)
E. the ledger comparison        DECIMAL(15,2) sum identical to the cent
```

So the caveat's *reasoning* would be right if the column were a real `float` — section C measures
exactly what such a column costs — **and it is not one.** Section D is the question neither the
caveat nor the challenge asked, and it answers the same way: the divergence is four orders of
magnitude below the "smallest units" the message names.

**CLAUDE.md already says this.** Its schema section records the identical withdrawal having happened
once, on `plan_orders.price` (`GAP-192`), and states the rule in terms: "money off such a column is
NOT `MetricCaveat::FLOAT_PRECISION`'s basis". The caveat was nonetheless propagated to more surfaces
afterwards — by the change recorded in §9a, among others — which is why the finding is now gated
rather than written down. `FloatPrecisionBasisIsRecordedTest` pins the measurement, the record of
it, and the four sites that attach the caveat; a fifth fails the test, and a mutation adding one was
caught.

**WHAT WAS NOT DONE, AND WHY IT IS THE OWNER'S.** The caveat is not withdrawn. Withdrawing it
removes a monetary disclosure from eleven merchant-facing surfaces, and the input that decides it is
not available here: CLAUDE.md's standing rule is that the migrations are not the schema, so
production's own column type needs `SHOW CREATE TABLE orders` **on production**, which no session
here has. If production's column is a real `float`, the original wording is correct there and
section C says what it costs. So the measurement is recorded, the attachment sites are named, no new
attachment may be made on the float reasoning, and the withdrawal is §10's.

One consistency repair came with it: `DashboardOrderValueFigure` stated flatly that
`orders.final_price` "is FLOAT" where its sibling `MerchantAnalyticsFigures` already hedged the same
claim about `order_coupon_details.coupon_discount_amount` ("declared `float default 0` BY ITS
MIGRATION (per CLAUDE.md the migrations are not the schema)"). The two now say the same thing.

### 9f. THE FIVE MONEY-BEARING MODELS — 17 sites, and a third kind of "no predicate"

`Tax` and `Shipping`: **10 sites, every one `principal`.** Each filters on `$store->id` traced to a
principal source. No finding.

The three order sub-tables: **7 sites, and a `store_id` predicate is not missing at any of them, it
is impossible.** `order_coupon_details`, `order_tax_details` and `order_billing_details` carry
`order_id` and **no `store_id` column** — on their create migrations and on every migration since,
and confirmed against the live migrated schema through `Schema::hasColumn()`. Reporting them as
`none` would tell a reader a predicate had been forgotten when none was ever available, so the
census gained a verdict that states the schema fact and a per-site disposition that says where the
tenancy really comes from. That is the distinction between "no predicate in the window" and "no
predicate at all", with a third case: **no predicate possible.**

Site by site, and none is a genuine unscoped read reachable by a merchant principal:

| site | what it is |
|---|---|
| `DashboardController:4348–4350` | the three child deletes of `DeleteOrder`, in one transaction, behind `resolveOwnedStore($request->store_id)` with the order resolved through that owned store first. **The handler's own comment records that the absence of exactly this gate was once live: "Any holder of any valid admin token deleted any order on the platform, plus its tax, coupon and billing detail rows, by sending one integer. Irreversible."** This is the defect class the census exists to find, already closed. |
| `DashboardController:4349` | **the only direct read of `OrderCouponDetail` in the censused files.** The coupon-discount tile a merchant reads does **not** come through here: `CouponRedemption` joins FROM `Metric::orders($scope)`, so the detail rows it sums are reachable only through orders the scope owns, and its docblock states that the table has no `store_id` column for exactly that reason. **The chain the coordinator asked to check first is sound and was already documented.** |
| `DashboardController:11042` | reads **every tenant's** billing rows for one email — `where('email', $customer->email)`, no store column, no join — and the containment is the *next* statement, `Order::where('store_id', $storeId)->whereIn('id', $order_id)`, so no foreign order survives. Same shape as `OwnedPathRawQueryInventoryTest`'s own disposition: the tenant control is the comparison two lines later. **Residual named:** the intermediate array holds another tenant's name, email and order ids in memory and is not returned; a future line that returned or logged it would disclose them. |
| `DashboardController:9037` | a **write**, not a read: the billing row of an order the handler has just created. |
| `HomeController:1580` | storefront order lookup, inside a loop over `Order::where('product_order_id', …)->where('store_id', $store->id)`, so every candidate is already scoped to the route-resolved store. |

**And the schema test found something in the direction nobody had checked.** Asserting the converse —
that every watched model *not* declared structural really has `store_id` — failed on `stores` at the
first run. `stores` has no `store_id` (its `id` **is** the tenant) and `users` carries `created_by`
(the tenant **root**, which CLAUDE.md records was once read as a tenant and was an account
takeover). So for those two the census asks the wrong column: a `none` verdict on either means *no
`store_id` predicate*, not *no tenant predicate*. Declared as `TENANT_COLUMN_IS_NOT_STORE_ID` with
LIMIT 6, and named as the next refinement rather than expanded quietly.

**One more false reading corrected, and it was on a delete.** `DeleteStore`'s cascade —
`Shipping`, `Tax`, `Testimonial`, `Setting`, `ProductVariant`, all `->delete()` on `$store->id` —
read as `request`, because `bindings()` captured only the **first line** of
`Store::where('id', $request->store_id)` and the ownership predicate `->where('created_by', $user->id)`
is on the second. That is §9c's item-2 defect one level up. Capturing the whole assignment and
recognising the inline ownership predicate as the third spelling of a gate moved **9 sites from
`request` to `principal` (20 → 11)**.

`tests/Support/bin/mutate-gap167.py` is now ten mutations: **nine caught, one declared survivor —
and the survivor is RUN, not asserted.** `run_declared()` applies it and measures that the totals
test still passes with the gate call deleted, so "moves no census number" is executed every time the
battery runs; it fails if the survivor is ever caught (the limit has been fixed, so the declaration
is the stale thing) and if the anchor no longer applies (the declaration proves nothing). Both
directions were falsified: exit 1 with `STALE DECLARATIONS` and exit 1 with `BROKEN … proves
nothing`, against exit 0 and `EVERY DECLARED SURVIVOR STILL SURVIVES` when the declaration holds.

---

## 10. What the owner still has to decide

Named by id, with no engineering verdict attached. Nothing below is deferred by this report:
CLAUDE.md §4.1 is explicit that an engineering report deferring something is not an owner decision.

| id | the question | what it blocks |
|---|---|---|
| `D-N32` | Is a conversion rate that divides by PAGE VIEWS acceptable, or must it be visitors or sessions? A visitor-based figure needs an order to carry the identity of the visit that produced it — a persistent schema change. | `P05-07`, and `P5-4`'s fourth figure |
| `D-N33` | May an order record the visit that produced it? Without it, orders and revenue per campaign are an invented attribution, and no campaign cost is recorded anywhere either. | `P05-13` entirely |
| `D-N35` | Are the five figures nothing renders to be shown, and under which labels? `UnpaidOrders`, `CancelledOrders`, `CompletedRefunds`, `NetFinancialReceipts`, `ShippingAndTax` are each tested and read by no surface. | `P05-02`, `P5-10` |
| `D-N36` | What do two defined figures INCLUDE? *Coupon discounts given* counts a coupon on an order later cancelled or refunded; *Shipping and tax* sums every financial row whatever its payment state. Neither rule is written down — the code decided. | `P05-10`, `P5-5` |
| `D-N43` | May a merchant declare `cancelled` or `refunded` by hand on the finance screen? The screen half shipped; what is undecided is whether the form may SET two of the six states. | `P05-01`, `P5-1` |
| `MKT-006` | How are rewards reversed on cancellation and refund? Measured NOT MET: the module contains no reversal handling, so a shopper keeps the points for a cancelled order. Four remedy options are in `docs/development/phase-5/PHASE-05-MKT-006-REWARD-REVERSAL.md` §3d; all change customer-visible balances, so none was taken. | `P05-11`, `P5-9` |
| `GAP-214` | A shopper who starts a checkout as one customer and returns as another is priced from one and debited from the other. | `P5-13`, and `D6` stays open |
| `GAP-200` | The consent store is built and reads from no screen. | `P05-15`'s consent half, `P5-15` |
| **the `FLOAT_PRECISION` withdrawal** | **NEW, raised by this report (§9e).** The caveat's stated basis is refuted on a fresh schema from this repository's own migrations: `$table->float()` emits a MySQL `double`, every money value round-trips exactly, and a 10,000-row accumulation differs from the exact decimal total by 0.0000111163 on 253,136,425.00. Withdrawing it removes a monetary disclosure from eleven merchant-facing surfaces; keeping it tells merchants a money figure is imprecise when it is not, which trains them to discount every other disclosure. **The one input that decides it is production's real column type — `SHOW CREATE TABLE orders` there — which no session here has.** | `MetricCaveat::FLOAT_PRECISION` and `MONEY_FLOAT_PRECISION` on four producers and eleven surfaces. No new attachment may be made on the float reasoning in the meantime; `FloatPrecisionBasisIsRecordedTest` refuses a fifth. |

Four more are open on the plan's own §0 dependency gate and are Phase 04's, not Phase 05's:
`P4-F127` (full verified refund), `P4-F128` (reconciliation and finance-screen audit), `P4-F046`
(webhook egress and Benefit capture), and the three non-engine doors of `P4-F124`. **The plan's own
rule is that no Phase 05 metric may be built on an outstanding row above**, and two slices
(`P5-1`, `P5-2`) name one.

---

## 11. What this report does not prove

- **Not accepted.** No slice has human acceptance. `P05-23` records that acceptance owes the
  `Security-sensitive diff: human checklist`, red on every head by design, which only a person may
  clear.
- **Not released.** Nothing in this branch is released to production, migrated or deployed, and
  CLAUDE.md §4 says no phase after 02 is authorised to merge or release.
- **Not verified by CI.** Every figure here is local. The verdict `verified by CI` needs GitHub's
  check-runs API for a specific head and is claimed for nothing.
- **Green does not mean TRUE.** 1,006 green tests and 204 green real-MySQL checks sat beside a
  caveat whose stated basis is refuted and which two of those very tests ratchet (§9e). A gate
  enforces that a sentence REACHES a surface; nothing was enforcing that the sentence was true.
- **Green does not mean covered.** Those figures sit beside thirteen requirements no slice owns and
  nine open owner decisions. §5 and §10 are the parts of this
  report that matter for a completion claim; §4 and §8 are the parts that are measurable.
- **The evidence sets are this report's mapping, not the plan's.** The plan names a test for only
  five of its sixteen slices, and the tests cite their slice id inconsistently — so "with evidence"
  could not be assembled mechanically from the current documents, and the mapping in §4 was
  curated by subject and then executed. It is gated for existence, not for completeness: a test
  that belongs to a slice and is missing from §4 would not redden anything.
