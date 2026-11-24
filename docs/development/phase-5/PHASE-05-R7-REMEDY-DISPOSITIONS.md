# P5-R7 — what the five remaining surfaces actually need

**Measured 2026-11-23 at `c1c65618`.** One designer per surface produced an implementable plan from
the code; one adversarial reader per plan was told to refute it. 10 agents, 0 errors.

**All five plans were refuted.** As with the surface map, almost every refutation confirmed the
diagnosis and rejected the *remedy* — twice because the remedy would have **deleted or mislabelled
an owner-decided disclosure while CI stayed green**. A sweep driven off the design pass alone would
have shipped two of those.

## 1. Merchant dashboard "Store totals" — Order value tile

`HomeController.php:364-366` · `resources/views/dashboard/overview.blade.php:373-377`

**The collected-payments counterpart was refuted and must NOT be added here** (wrong audience,
wrong period, real cost). What the line *does* have is a **different, unrecorded defect**: it is one
ungrouped `SUM(final_price)` with no currency grouping, and `currency_format_with_sym()` stamps the
store's own `defult_currancy_symbol` over the result — so a store trading in two currencies is shown
their sum behind one symbol. Same class as ANA-TOP, on the dashboard's most prominent money tile.

**The remedy is bounded** — route the identical predicate through
`GrossMerchandiseValue(includeCancelled: false)` and render per currency — **with two corrections
the refutation insisted on**:

- **Do not attach `ReportFigureNote::orderValue()`.** That sentence is item-level and net of
  shipping; this tile is `SUM(final_price)`, arithmetically identical to `$TotalgrossSale`, whose
  designated sentence is `grossOrderValue()`. The codebase's own convention is measured side by side
  in `_sales_stats.blade.php:14-22`.
- **Do not replace the existing hint literal.** `overview.blade.php:376` ends *"...excluding orders
  that were cancelled. Returned orders are still included."* — that returns clause is **F257
  owner-decision text** (`HomeController.php:343-350`), and `grep -rn "Returned orders are still
  included" tests/` returns **nothing**. Replacing it would drop the disclosure silently, with the
  Feature gate green.

`MetricResult::total()` throws `MixedCurrencyException` when not single-currency, so the naive
wiring is an HTTP 500 on exactly the stores that have the defect.

## 2. Merchant Top-5 report — the owner-decision disposition STANDS

`ReportController::top_product()` · `reports/top_5_reports.blade.php`

Three of the four panels **cannot** express collected money without an owner-chosen allocation rule:
the ledger is unique on `order_id` and carries no product dimension. The fourth would produce a
second, differently-ordered top five — a ranking is not a figure, and changing the sort key changes
what the panel claims. **Do not re-litigate.**

**One item lands unprompted:** the four card notes carry no cancelled/returned disclosure. No money,
no decision.

## 3. Dashboard Top Category / Top Brand — larger than a tile, and the blocker is decisive

A per-dimension collected figure has **no `MetricResult` shape** — `DimensionPeriodRevenue.php:42-45`
already records this for the same reason. Parts A and C of the plan stand; part B must not be
implemented from its text, because one of its stated measurements is **false**: it claimed no test
asserts the AJAX key set, and `DashboardFigureHonestyTest:283` pins it with `assertExactJson`.

## 4. Operator "Total Orders" card — it is a COUNT, and the label is wrong

`PlanOrder::count()` (`app/Models/PlanOrder.php:34-37`) — a bare row count of the **SaaS subscription**
table. No money counterpart belongs here and **none can be built**:
`store_order_financials.order_id` is a foreign key to the **customer** `orders` table, so a
`plan_orders` row can never have a ledger row.

**Bounded fix: relabel to "Total Plan Orders".** On an operator dashboard, "Total Orders" reads as
customer orders and is not.

**Fixture trap recorded:** `is_refund` exists on the table but is **absent from `PlanOrder::$fillable`**,
so `PlanOrder::create([... 'is_refund' => 1 ...])` silently drops it — a test written that way would
"prove" refunded orders are counted without ever setting the flag.

## 5. Operator "Top Selling Store" — the operator ALREADY has this figure

`PlatformAnalyticsFigures::payments()` (`:654-686`) renders `CollectedCustomerPayments` for the
operator on `/analytics`, under that class's own two cross-tenant rules. The plan did not know this.
Refuted on three load-bearing points; if anything lands it is **annotate, never re-rank**, computed
under the **same coverage rules** that sibling figure already uses rather than `allTime()`.

---

## What this leaves

| # | Item | State |
|---|---|---|
| 1 | Store-totals tile adds currencies together | **bounded, do it** — with both corrections above |
| 2 | Operator "Total Orders" is mislabelled plan orders | **bounded, do it** |
| 3 | Top-5 card notes lack a cancelled/returned disclosure | **bounded, do it** |
| 4 | Top-5 collected counterpart | **owner decision** — allocation rule; not engineering's |
| 5 | Top Category/Brand collected counterpart | **larger than a tile** — no MetricResult shape |
| 6 | Operator Top Selling Store | **likely already covered** by `/analytics`; verify before building |

---

## Outcome — what was built, 2026-11-23

Recorded here rather than in a new file, so the disposition and its result stay together.

| # | Item | Outcome | Commit |
|---|---|---|---|
| 1 | Store-totals tile adds currencies together | **DONE** — `GrossMerchandiseValue` + per-currency disclosure | `4d9c396d` |
| 2 | Operator "Total Orders" mislabelled | **DONE** — relabelled "Total Plan Orders" | `9b54687e` |
| 3 | Top-5 notes lack a cancelled/returned disclosure | **DONE** — one translated sentence on all four | `9b54687e` |
| 4 | Top-5 collected counterpart | **OWNER DECISION** — unchanged; needs an allocation rule |  |
| 5 | Top Category/Brand collected counterpart | **NOT BUILT** — no `MetricResult` shape; unchanged |  |
| 6 | Operator Top Selling Store | **VERIFIED — NEEDS NOTHING** | — |

### Item 6, measured rather than assumed

Two things had to be true for this to need work, and neither is.

1. **The operator already has the collected figure.** `PlatformAnalyticsFigures::payments()`
   (`:654-686`) renders `CollectedCustomerPayments` over `MetricScope::stores($coverage['included'])`,
   **one line per currency, explicitly not added together**, under a coverage window that is
   `MetricWindow::since($verifiedFrom)` rather than `allTime()` whenever the ledger can name a start.
   It also states how many stores were left out. That is the collected counterpart, on `/analytics`.

2. **The dashboard panel already discloses that it is demand.** Its note reads, verbatim:
   *"Order value: every order placed, including unpaid and cancelled orders. Stores are ranked only
   against stores in the same currency."* Both halves of the P5-R7 question — demand-vs-collected,
   and cross-currency ranking — are already answered on the screen, by P05-14 / P05-19.

So the remaining idea was "annotate, never re-rank", and **the annotation is already there**. Adding
a second one would restate it; re-ranking by collected money would change what the panel claims,
which the disposition above already rules out. Nothing is built, and that is the finding.

### Two corrections this work produced, worth keeping

- **Item 1's first draft would have shipped two live defects**, both found by the independent review
  and neither visible to any existing test: reading one currency bucket silently dropped every order
  placed before `orders.currency_code` was stamped (worst on the oldest, largest stores), and an
  un-normalised `defult_currancy` naming an empty bucket showed `0.00` to a store that had taken
  money. `DashboardOrderValueTest` could not catch either — it writes no currency setting, so every
  order lands in one bucket and the test passes whatever the code does.
- **A filter that looks like a guard can be the defect.** Item 1's draft filtered the unattributed
  bucket out of the hint, which drops that money silently — the thing the tile exists to stop.
  `MoneyText::currencyLabel()` already renders it honestly as "Currency not recorded". A mutation
  removing the view filter **survived every case**, which is how the redundancy was found.

