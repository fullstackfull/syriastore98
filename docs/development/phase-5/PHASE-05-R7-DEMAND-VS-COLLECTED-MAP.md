# P5-R7 / GAP-153 — which reporting surfaces still show demand with no collected counterpart

**Measured 2026-11-23 at `60d439b9`.** Five readers over the surface groups, each claimed gap then
re-checked by an adversarial pass told to default to refuting it. 25 agents, 0 errors.

| | |
|---|---|
| surfaces measured | **32** |
| already carry a ledger-sourced collected figure | **12** |
| do not | **20** |
| gap-claims put to adversarial re-check | **20** |
| **refuted** (remedy wrong, overstated, or already done) | **17** |
| **survived** | **3** |

**The refutation rate is the headline, and it is not a failure of the measurement.** Almost every
refutation confirmed the arithmetic and the file:line and then rejected the *proposed remedy* — as
"already done and pinned", "the narrowest change is different", or "this is not a P05-05 gap at
all". A sweep driven off the first pass would have changed a dozen surfaces that needed nothing.

## 1. The constraint that decides every remedy

Owner Decision **Option A**: `orders.payment_status` is the customer's settlement record, and
cancellation must not rewrite it. **So no predicate on `orders` can turn a demand figure into
collected money.** Every counterpart must be a *separate* figure sourced from the financial
ledger, shown beside the demand one. `where('payment_status', 'Paid')` is not available as a
remedy and was explicitly ruled out in the brief, so none of the 32 readings proposes it.

This is why GAP-153's own text said Phase 05 "cannot start": the predicate choice *was* the
financial-policy decision. All six blocking questions have since been decided (F124 Option A,
F126, F127, F128, F032, F046), and the metric layer exists, so the sweep is now unblocked.

## 2. Confirmed as needing NOTHING — do not re-open

| surface | why |
|---|---|
| `/finance` inventory valuation tiles (Units, Capital, Retail Value, …) | computed over STOCK (`products` x `product_variants`, `StoreFinanceController:998-1148`), no order, window or payment predicate. A collected counterpart to a stock valuation is meaningless, and these touch no order table at all. |
| Operator "Orders" area chart (`#traffic-chart`) | sums nothing — `PlanOrder::select(count(*))` per day over SaaS subscription orders (`HomeController:836-838`). No money column. |
| Operator "Most Popular Store", summary cards, "Top Customers" | no money figures: page views and counts (`PlatformAnalyticsFigures:451-461`, `COUNT(*)` over `Visit` grouped by `store_id`). |
| `/finance` wallet sibling tiles (Pending Withdrawals, Pending Balance, Total Income, Total Withdrawn) | the counterpart is PRESENT on that surface, not partial — `index.blade.php:127-131` renders "Payments received" from `FinanceLedgerFigures` (`StoreFinanceController:1160`). The hintless Total Income state is separately PINNED by an existing assertion. |
| Per-order P&L "Profit" (`gross_profit`) | "no collected counterpart" was wrong. Arithmetic is `($productAmount - $discountAmount) - $productCost` (`OrderFinancialService:82-83`) — the discounted product subtotal minus cost. |
| Sales report tiles, line chart, bar chart, and both chart-data endpoints | **ANA-005 already covers the screens.** `_sales_stats.blade.php:56-61` renders "Payments received" from `salesCollectionTile()` (`ReportController:603-632` → `FinanceLedgerFigures['payments']` → `CollectedCustomerPayments`), reached from BOTH bodies (`:1245` line, `:1788` bar). Pinned by two Feature tests and a MySQL harness. |

## 3. Open and bounded — the actual work list

| # | surface | what is missing | notes |
|---|---|---|---|
| 1 | **`order_report_export`** (`:1268-1297`) and **`order_bar_report_export`** CSVs | the screen has the ledger figure; the **downloaded file does not**, and its only disclosure is `MONEY_FLOAT_PRECISION` via `moneyRowHint()` — it does not even state the money is order demand rather than payments received | the Phase 05 "an export carries the screen's caveats" rule, applied to the two sales exports. Two sites, not one. |
| 2 | **sales-by-product** screen + **`product_order_export`** | no collected counterpart anywhere in the chain; AND the per-period series sums `final_price` out of `product_json` with **no reference to `orders.currency_code`**, so it adds foreign currencies together, and `x-ss.bar-chart` prints bare `number_format()` with **no currency symbol at all** | the direct twin of what ANA-TOP fixed for category/brand. Same defect, same remedy shape. |
| 3 | **merchant dashboard "Store totals" Order value tile** | `HomeController:364-366` — `delivered_status != 2` then `sum('final_price')`: fulfilment, not payment | confirmed as the remaining item in its group, with a corrected (narrower) remedy than first proposed |
| 4 | **merchant Top-5 report** (`top_product`, `:3438`) | Top Selling Products / Category / Brand / Payment Method, labelled "All time" | **survived refutation on all four legs** |
| 5 | **dashboard Top Category / Top Brand cards** | ANA-TOP gave them currency correctness and the order-value disclosure; they still have **no collected counterpart** | **survived refutation** |
| 6 | **operator "Total Orders" card** (`superadmin/dashboard.blade.php:31`) | — | **survived refutation** |
| 7 | **operator "Top Selling Store" panel** | pure demand — `SUM(orders.final_price)` per normalised currency; `GrossMerchandiseValue:9-10` calls itself "a demand measure, not a money measure" | gap confirmed; a narrower remedy than first proposed exists |

## 4. Two findings that are not P5-R7 work

- **`HomeController::best_selling_brand_chart` (`:1708-2121`) is a third, UNROUTED copy of the
  sales line-chart data endpoint**, carrying its own money arithmetic (`final_price`,
  `final_price - delivery_price - tax_price`, `coupon_price`, `delivery_price` at `:1764-1767`,
  `:1828-1831`, `:1966-1969`, `:2063-2066`, plus a per-day re-read in the seven-day branch). Dead
  code that would need the same fix as the live copy if it were ever routed. Recorded, not fixed.
- **The sales-by-product "Order value" tile is NOT engineering's to close** — it is a recorded
  owner decision. It stays open with that disposition rather than being changed.

## 5. A number corrected

P5-R13 records that the operator screen carries **4** figures where the merchant screen carries 13.
Measured here: the operator card census is **SIX**, not four. The P05-05 disposition for those
cards is unchanged (no money figures), but the count in that row is wrong.

## 6. What a per-bucket collected series would take, and why it is not bounded

Items 1-7 are all tile-or-cell work. A *per-bucket* collected series for the money charts is
different: `CollectedCustomerPayments::for()` answers ONE window, so a day-by-day counterpart needs
either a grouped query over `store_order_financials.paid_at` or N window calls, plus a decision
about how to draw a series whose ledger coverage starts later than the chart's first label. That is
its own slice with its own owner question, and it must not be faked from `orders`.
