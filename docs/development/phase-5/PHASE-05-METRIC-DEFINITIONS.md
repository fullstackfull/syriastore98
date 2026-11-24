# Phase 05 — revenue and commerce metric definitions

**These definitions are the contract between the ledger and every report built on it.** Owner
instruction: *"Every metric must have an explicit definition, source, inclusion/exclusion rules and
verification tests"*, and *"Do not reuse `SUM(orders.final_price)` as settled revenue merely because
existing reports do."*

They are written against the semantics established by the Phase 05 financial-policy work, not
against the pre-existing ambiguity:

| record | what it now means |
|---|---|
| `orders.payment_status` | **the customer's settlement.** `Paid` / `Unpaid` / `Pending`. Cancellation no longer rewrites it (P4-F124). A merchant cannot assert it without evidence (P4-F126, P4-F145). |
| `store_order_financials.payment_status` | **the merchant's entitlement.** `paid` / `unpaid` / `pending` / `partially_paid` / `cancelled` / `refunded`. One row per order, enforced by `UNIQUE (order_id)`. |
| `store_wallet_transactions` | **the audit trail of every balance movement**, typed and reasoned: `order_paid`, `order_payment_reversed`, `order_cancelled`, `order_refunded`. |

**The single most important consequence:** settlement and entitlement are deliberately *independent*
(owner decision on P4-F128). A metric must name which one it is measuring. A report that mixes them
is not a rounding difference — it is a different question.

---

## 0a. Which approved decisions this document answers to

**Added 2026-11-16, after the Master Product Plan was recovered.** These ids were unreadable when this
document was written, so it was built from the owner's instruction wording alone. The register
(`docs/development/master-plan/DECISION-ID-REGISTER.md`) shows it was answering `ANA-*` all along:

| id | recovered decision | where it lands here |
|---|---|---|
| `ANA-005` | separate sales, collection, settlements and profitability | §§1–8 — the entire settlement/entitlement split |
| `ANA-006` | **do not claim precise profitability when cost or fee data is missing** | §8, and its cost caveat |
| `ANA-007` | show data quality, estimates and omissions | §11 rule 5, and every per-metric caveat |
| `ANA-008` | **document metric definitions and their sources** | **this document is `ANA-008`** |
| `ANA-010` | do not build a new accounting engine | §11, and `PHASE-05-EXECUTION-PLAN.md` §3 |

**`ANA-006` and `ANA-007` are already partly delivered, by PHASE 02.** The honest stat grid, the refusal
to display revenue or profit, the period on every figure and the eleven report screens' qualifications
were built under the label `PRD-001`, which the recovered plan shows means *"full product editor"* — a
mis-attribution, traced in `DECISION-ID-REGISTER.md` §4.1. The work is sound; it answers here. Phase 05
should **extend** it rather than re-derive it, and a Phase 05 completeness claim must count it.

**This is a citation, not a status change.** No metric became verified by acquiring an id.

---

## 0. Why `SUM(orders.final_price)` is not revenue

Measured, and it is the reason this document exists. `app/Http/Controllers/ReportController.php` is
**3660 lines and contains zero `payment_status` predicates**. Every money figure it produces counts
orders irrespective of whether the customer ever paid, whether the order was cancelled, and whether
the money was refunded.

`SUM(orders.final_price)` over an unfiltered order set therefore includes:

- orders never paid for (`Unpaid`, and COD `Pending` that was never collected);
- orders **cancelled** — which keep their `final_price` and, since P4-F124, correctly keep
  `payment_status = 'Paid'` when the customer really did pay;
- orders **fully refunded** — `order_refunds` records the refund; `orders.final_price` does not
  change on the online path at all;
- the **shipping and tax** the merchant collects but does not keep as margin.

It is a usable figure for exactly one metric — gross merchandise value — and only with explicit
exclusions.

---

## 1. Gross merchandise value (GMV)

**Computed by.** `App\Support\Metrics\GrossMerchandiseValue`.

**Definition.** The total customer-facing value of orders *placed* in the period, before any
settlement, cancellation or refund is considered. A demand measure, not a money measure.

**Source.** `orders.final_price`, keyed on `orders.store_id`, dated by `orders.order_date`.

**Includes.** Every order regardless of `payment_status`.

**Excludes.** Orders with `delivered_status = 2` (cancelled) — **stated as an option, not a
default**: GMV conventionally includes cancellations, and this platform has no prior convention. The
report must label which it used. Never silently pick one.

**Verification test.** Two orders, one paid and one unpaid, both counted; one cancelled order
counted or not according to the stated flag, and the label asserted to match.

---

## 2. Collected customer payments

**Computed by.** `App\Support\Metrics\CollectedCustomerPayments`.

**Definition.** Money the customer actually settled. The answer to *"what did shoppers pay us?"*

**Source.** `store_order_financials.paid_amount` where the row's history shows a capture.

**Includes.** Rows whose `payment_status` is `paid`, **and rows now at `cancelled` or `refunded`
whose `paid_amount > 0`** — because P4-F124 deliberately preserves `paid_amount` and `paid_at`
through a reversal. The customer *did* pay; that fact does not stop being true.

**Excludes.** `unpaid`, and `pending` (COD not yet collected, bank transfer not yet settled, and any
gateway return whose capture could not be verified).

**The trap this definition exists to avoid.** Filtering on `payment_status = 'paid'` alone
*under-counts*, because a cancelled-but-captured order drops out. That is the direct consequence of
the F124 change and the reason this metric reads `paid_amount`, not the status.

**Verification test.** An order paid then cancelled contributes to this metric and not to net
receipts (§6).

---

## 3. Unpaid orders

**Computed by.** `App\Support\Metrics\UnpaidOrders`.

**Definition.** Orders awaiting money, split by *why*.

**Source.** `store_order_financials.payment_status` with `orders.payment_type`.

**Three sub-metrics, never one.** Collapsing them is how a COD book looks like bad debt:

| sub-metric | predicate |
|---|---|
| awaiting COD collection | `payment_status = 'pending'` and `payment_type` in the COD set |
| awaiting manual settlement | `payment_status = 'pending'` and `payment_type = 'bank_transfer'` |
| genuinely unpaid | `payment_status = 'unpaid'` |

**What "the COD set" is, and why matching it is not a plain `IN`.** The set is
`PaymentVerificationService::COD_PAYMENT_TYPES` — `['cod', 'cash_on_delivery', 'cash']` — and
`UnpaidOrders` compares against it through `Metric::normalisedPaymentTypeSql()`, which wraps the
column in `LOWER(TRIM(...))`. Both halves carry weight: `payment_type` is free text and this database
holds `COD` and `Cash On Delivery`, so a raw `IN` against a lowercase list would silently reclassify
those orders as *genuinely unpaid* — a COD book reported as bad debt, which is the precise failure
this section's three-way split exists to prevent. The SQL reproduces
`PaymentVerificationService::isCashOnDelivery()` rather than re-inventing it.

**Excludes.** Cancelled orders — a cancelled unpaid order is not awaiting anything, which is exactly
why P4-F124 moves such rows to `cancelled` rather than leaving them at `unpaid`.

**Verification test.** A cancelled COD order appears in none of the three.

---

## 4. Cancelled orders

**Computed by.** `App\Support\Metrics\CancelledOrders`.

**Definition.** Orders cancelled in the period, with the value involved.

**Source.** `store_order_financials.payment_status = 'cancelled'`, dated by `orders.cancel_date`.

**Note on the date.** `DashboardController::orderCancel` writes **no `cancel_date`** — measured;
every other door stamps it. Until that is fixed, a `cancel_date`-dated report under-counts
cancellations from that door, and the ledger row's `updated_at` is the fallback. **Stated rather
than silently worked around.**

**Verification test.** A cancellation through each door appears, and the one that writes no
`cancel_date` is still counted via the fallback.

---

## 5. Completed refunds

**Computed by.** `App\Support\Metrics\CompletedRefunds`.

**Definition.** Money actually returned to customers. **A refund request is not a refund.**

**Source.** `store_order_financials.payment_status = 'refunded'` plus the
`store_wallet_transactions` row of type `order_refunded`.

**Explicitly NOT** `order_refunds.refund_status`, and this is the sharpest exclusion in the
document. That column holds `Processing` (a shopper's request), `Accept` (approved, **not settled**)
and `Refunded` (completed). Only the third is money. For every online gateway the resolved status is
`Accept` and the `Accept → Refunded` edge is **unreachable by construction**, because
`hasGatewayRefundDriver()` is a hardcoded `return false` and no refund request has ever left this
server.

**Partial refunds are excluded entirely and must be reported as a known gap**, per
`docs/finance/P4-F127-PARTIAL-REFUND-CAPABILITY.md`: the ledger cannot represent them safely, so no
metric may imply it has counted them.

**Verification test.** An order at `Accept` contributes nothing; one at `Refunded` contributes once;
a replayed refund contributes once.

---

## 6. Net financial receipts

**Computed by.** `App\Support\Metrics\NetFinancialReceipts`.

**Definition.** Collected payments minus completed refunds minus cancellations of captured
payments. The closest thing to *"money we kept"* at the order level.

**Source.** §2 − §5 − (cancelled rows with `paid_amount > 0`).

**Equivalently, and this is the preferred computation** because it needs no inclusion rules at all:
the signed sum of `store_wallet_transactions` for `reference_type = 'order'`:

```sql
SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END)
```

**Why the transaction sum is preferred.** It is the only representation that is *self-reconciling*:
every movement is typed, reasoned, and written exactly once by a locked, idempotent path. The
status-based computation has to re-derive what the transactions already record.

**THE EQUIVALENCE IS CONDITIONAL, AND THIS SECTION CLAIMED IT UNCONDITIONALLY.** The two
computations are comparable only when the wallet's currency is the store's display currency. The
movement leg is stamped in the **wallet's** currency carrying a **converted** amount; the status leg
is stamped in the store's display currency. Measured with a USD wallet and `defult_currancy` SYP at
10,000/USD:

```
movement {"USD":200}   status {"SYP":2900000,"USD":-90}   agrees: false
```

§11 rule 3 forbids conversion inside a metric, so **no shared rule can reconcile those two legs** —
the difference is real and neither leg is wrong. A second, narrower case has the same shape: the
legs read different columns (`store_wallet_transactions.currency_code` for refunds,
`store_order_financials.currency_code` for collected payments), so re-spelling one column's currency
splits them by construction.

**So "NOT COMPARABLE" is not "DIVERGES", and `reconcile()` does not yet tell the two apart.** It
reports a per-currency difference either way, which is investigable but labels a currency mismatch
as a reconciliation defect. Separating the two states is **proposed and not implemented**; the
underlying wallet-versus-display misalignment is held pending an owner decision.

**No shipped surface calls this metric today.** `NetFinancialReceipts` has no caller anywhere in
`app/` — the only repository references outside the class are prose in docblocks. The
`RECONCILIATION_DIVERGENCE` caveat it attaches is a capability, not something a merchant is
currently shown, and the paragraphs above must not be read as describing live behaviour.

**Verification test.** The two computations agree on a dataset containing a paid order, a cancelled
paid order, a refunded order and an unpaid order, **in one currency**. **Disagreement there is a
reconciliation defect and must fail the build, not be averaged.**

---

## 7. Merchant wallet entitlement

**Computed by.** `App\Support\Metrics\MerchantWalletEntitlement`.

**Definition.** What the merchant may withdraw. `store_wallets.available_balance`.

**It is NOT revenue** and must never be labelled as such in any UI: it is reduced by withdrawals
(`total_withdrawn`) and by reversals, and it is denominated in the **wallet's** currency, which can
differ from the order's.

**Verification test.** `available_balance` agrees with the signed sum of the ledger rows **that
actually moved it**, within `MerchantWalletEntitlement::EPSILON` (0.005, one unit of
`DECIMAL(15,2)`). This is the **corrected** form of the drift check in
`P4-F032-WALLET-RECONCILIATION-PROCEDURE.md` §Q-F032-5, not a copy of it. That check — and
`GateFReconciliation` R1 — sums every transaction, which produces two structural false positives:

- **The withdrawal lifecycle double-counts.** `available_balance` is reserved when a withdrawal is
  *requested*, so the later `withdrawal_paid` row carries `balance_after = balance_before` by
  design. Summing it debits the wallet a second time. `drift()` therefore joins only rows where
  `balance_after <> balance_before`.
- **A currency change rewrites balances with no ledger row at all**, so no sum over the ledger can
  reproduce the result. A wallet whose ledger spans more than one currency is reported as its own
  fact (`ledger_currencies`) rather than folded into a difference that would mean nothing.

The invariant is therefore **not** "equals the transaction sum". Implementing that literal sentence
yields a false drift report for every wallet that has ever paid out a withdrawal.

---

## 8. Actual store revenue (margin)

**Computed by.** `App\Support\Metrics\StoreRevenueMargin`.

**Definition.** What the merchant earned, net of the cost of goods.

**Source.** `store_order_financials.gross_profit`, which is
`(product_amount − discount_amount) − product_cost`.

**Which orders count is a stated choice, as in §1.** The definition names no status predicate, and
§0 is explicit that counting orders nobody paid for is the error this document exists to prevent —
so the default is **settled orders only** (`StoreRevenueMargin::__construct(settledOnly: true)`),
meaning `payment_status = 'paid'` and **not** §2's wider eligibility. §2 counts a
cancelled-but-captured order because the *shopper* really paid it; margin must not, because the
*merchant* did not keep it. Whichever way the flag falls, `notes()['basis']` records which was used.
Never silently pick one.

**The date column swaps with the basis.** Settled margin is dated by
`store_order_financials.paid_at`, all-orders margin by `store_order_financials.created_at`, because
an unsettled row has no `paid_at` to be dated by at all. A report that mixes the two bases is
comparing two clocks.

**THREE CAVEATS, AND THEY DO NOT BEHAVE ALIKE.**

- **`COST_UNKNOWN` is conditional.** `product_cost` is a snapshot of `products.cost_price` **at
  order time**; for any financial row written before the P1-SEC-44 fix it may be 0 or, historically,
  another store's cost. Where it is 0 the column reports the whole sale as profit — not an
  approximation, a 100% margin on goods that cost something. The flag is attached **only** when rows
  in the answer actually carry `product_cost = 0`, with `notes()['rows_without_cost']` giving the
  count, because attaching a warning to answers that do not have the problem is how a warning
  becomes furniture.
- **`EXCLUDES_SHIPPING_AND_TAX` is unconditional and only half true.** Shipping is excluded for
  every store — it is its own column. **Tax is excluded only when the merchant enters prices without
  it.** A store set to `TaxOption price_type = inclusive` carries tax inside `product_price`, which
  `Cart::cart_list*` passes into `PaymentService` as `$product_price`, so for that store this figure
  **includes** the tax and the caveat's claim is false. Found by independent review of P5-16; the
  `/finance` tile no longer makes the claim, but `MetricCaveat::MESSAGES` still does. Tracked as
  **GAP-213(d)**.
- **`EXCLUDES_PAYMENT_FEES` is unconditional and was disclosed on no surface at all** until
  ANA-006(c). `store_order_financials` has no fee column, so the arithmetic omits processing fees as
  completely as it omits shipping. Both are unconditional because the absence is total: there is no
  per-order signal that would let either be shown "only when it applies".

**Verification test.** An order whose product has no `cost_price` reports margin equal to
`product_amount − discount_amount` and is flagged cost-unknown, not silently treated as 100% margin.

---

## 9. Coupon redemption

**Computed by.** `App\Support\Metrics\CouponRedemption`.

**Definition.** Coupons actually consumed.

**Source.** `order_coupon_details` joined to the order.

**CONDITIONALLY AUTHORITATIVE, PER WINDOW** (was: blocked outright). The owner's instruction was
*"Review P4-F134 and P4-F135 before using coupon-redemption records as authoritative data."* That
condition is a **review**, not a permanent ban, and the review is done: both defects were measured,
fixed and mutation-proved on 2026-11-20 (`PHASE-04-FINDINGS-REGISTER.md` P4-F134/F135).

What each was, and what it means for history:

- **P4-F134**: `ordersave` recorded **no coupon redemption at all**, so caps never saw those orders
  — redemption **under-counted** by an unknown amount before the fix;
- **P4-F135**: a **refused** coupon still wrote `order_coupon_details` and `user_coupons`, burning
  the shopper's allowance — redemption **over-counted** by an unknown amount before the fix.

**The fix does not repair the records already written, and nothing reconstructs them.** Errors in
both directions exist in the back history and are still unquantified. So authority is decided
**per window**, not once for the metric:

| the window | verdict | mechanism |
|---|---|---|
| lies entirely inside the store's fully-recorded period | **authoritative**, qualified by the start date | `MetricCaveat::COUPON_REDEMPTION_COVERAGE` — deliberately **not** blocking |
| reaches back before the recording fix, or is all-time, or has no `from()` | **not authoritative** | `MetricCaveat::COUPON_REDEMPTION_UNRELIABLE` — the only member of `BLOCKING` |

`CouponRedemption::windowIsFullyRecorded()` decides which, and
`CouponRedemptionCoverage::forStore()` computes the fully-recorded start date by anti-joining
`orders` (`coupon_price > 0`) to `order_coupon_details` — **by measurement, never by a hardcoded
cut-over date**, because the fix date is not the same as the date a given store's records became
complete. A store with an unrowed discounted order *after* the fix is not complete, and says so.

**A merchant-facing figure must therefore state its period**, which the `Coupon discounts given`
tile on `/analytics` does: outside the complete period it renders *Not available* with the reason
rather than a number, because a number under a "this cannot be trusted" sentence gets read as a
number. **Campaign-redemption reporting over historical windows is still not supported.**

---

## 10. Shipping and tax

**Computed by.** `App\Support\Metrics\ShippingAndTax`.

**Definition.** Amounts collected on the merchant's behalf and largely passed through.

**Source.** `store_order_financials.shipping_amount` and `tax_amount` — **not**
`orders.delivery_price` / `tax_price`, because `RefundAmonut` zeroes `orders.delivery_price` on a
client-supplied boolean without recomputing `final_price`, so the order's own components can stop
agreeing with its total. The financial row is the snapshot taken at order time and does not move.

**Verification test.** An order whose `delivery_price` was later zeroed still reports its original
shipping on the financial row.

---

## 11. The rules that apply to every metric

1. **Name the record.** Every figure states whether it measures settlement (`orders`), entitlement
   (`store_order_financials`) or movement (`store_wallet_transactions`).
2. **Tenant predicate at the call site.** `Order` is not in `config/tenancy.php`'s `scoped_models`,
   so no global scope will add `store_id` for you — on any principal, operator included.
3. **Currency is not assumed.** `store_order_financials` carries `currency_code`;
   `order_refunds.refund_amount` carries none and is never converted. A cross-store total without
   conversion is meaningless.

   **Currency IDENTITY is a separate rule from currency conversion, and only the second one is
   forbidden** (FIN-SYM, 2026-11-22). `MetricResult::make()` keys its money buckets through
   `App\Support\Money\CurrencySymbol::code()` — three ASCII letters, case folded, or `''` — which
   settles two questions the earlier wording left open:

   - **Spellings of one currency are one bucket.** `usd`, `USD` and ` USD ` are the same currency
     and are summed. They were three buckets, each with a partial subtotal, and the figure was
     reported as mixed-currency. Summing them converts nothing; it recognises them.
   - **A recorded value that is NOT a currency code is not a currency.** It joins
     `MetricResult::UNKNOWN_CURRENCY`, the same bucket a `NULL` or empty `currency_code` goes to,
     because a writer that stored a symbol recorded no currency. P5-R14 measured that
     `Api\ApiController::place_order()` and `::ordersave()` really did stamp the store's SYMBOL
     into `orders.currency_code`, and owner decision D-N34 leaves those rows in place, so this is
     a live state and not a hypothetical. `assumingUnknownCurrencyIs()` then folds that bucket into
     the store's own currency **only when a caller opts in**, attaching
     `MetricCaveat::CURRENCY_UNRECORDED` and the exact amount assumed — no rate is applied, and no
     rate exists to apply (`currency_rates` holds current rates; nothing stamps one onto an order).

   What this cost before it was fixed was NOT an exception. `total()` does throw on more than one
   bucket, but every caller checks the bucket count first, so the figure was **suppressed or
   split**: a single-currency store's tile refused to show one number, the wallet surface offered a
   merchant a symbol as a currency beside its own code, and `MetricThreshold` skipped the
   comparison entirely, so a real floor breach went unreported.

   The one bucket key that is deliberately not a currency code is printed through
   `MoneyText::currencyLabel()` ("Currency not recorded"), never as the raw token.
4. **`FLOAT`, not `DECIMAL`, on `orders`.** ~7 significant digits on a currency that runs to
   millions. Aggregate on `store_order_financials` (`DECIMAL(15,2)`) wherever both are available.
   `MetricCaveat::FLOAT_PRECISION` states this of one figure; `MONEY_FLOAT_PRECISION` states the
   same measured thing of a row carrying several, where "This figure" cannot name which it means.
   Every money figure in `ReportController`'s report handlers is in this class — each is
   accumulated in PHP as `+= (float)` off `orders.final_price`, `delivery_price` or `coupon_price`.
5. **A metric with a known gap displays the gap.** §9 is blocked, §5 excludes partials, §8 has a
   cost caveat, §4 has a date caveat. A number that hides its own limits is worse than no number —
   which is the whole point of "trustworthy analytics".
6. **An export carries the same disclosure as the screen, as a COLUMN.** A caveat in a header
   preamble or a trailing comment line does not survive sorting, filtering or copying a range out
   of a spreadsheet, which is exactly what a merchant does to a downloaded file — so the
   disclosure is a column repeated on every row (`App\Support\Metrics\FigureCsv` for the
   `/analytics` and `/finance` figures; `figureNote` in the five `ReportController` export
   payloads, rendered by each view's client-side CSV builder).

   The sentence must be **read from one source, not restated**: `App\Support\Reports\ReportFigureNote`
   is what both the screen's `<x-ss.stat>` `:hint` and the export handler call, so the two cannot
   drift. This rule was vacuous until 2026-11-21 — `MetricCaveat` had zero consumers outside
   `app/Support/Metrics/` and `tests/`, so there was nothing on any report screen for an export to
   carry, and all five exports shipped a bare money column. The duplication is also what caused
   the divergence it was meant to prevent: the "Order value" definition was an identical literal in
   **four** blades, and two of those four carried the precision caveat beside it while two did not.

---

## 12. Customer retention and repeat purchase

**Computed by.** `App\Support\Metrics\CustomerRetention`.

**Definition.** Of the customers who can be recognised again, the share who ordered more than once.
`repeat_customers / identified_customers`. A **customer**, not an order: two orders from one person
is one repeat buyer, not two.

**Source.** `orders`, joined to `customers` by `orders.customer_id`, over a **declared basis**:

| basis | what it reads | honest about |
|---|---|---|
| `settled_only` (**default**) | INNER JOIN `store_order_financials` on `payment_status = 'paid'` | **authoritative** for what it covers, and blind to every order with no financial row |
| `all_orders` | `orders` alone | **all history**, and overstates retention by however much of the book was never paid |

`RetentionFigures::notes()['basis']` always says which was used. The default is the settled one by
the P5-2 gate: *a repeat-purchase rate computed over orders nobody has confirmed were paid is not a
retention figure.*

**Two exclusions, both reported as counts rather than assumed away:**

- `orders_without_identifiable_customer` — a guest order cannot be attributed to a person, so it
  cannot be a first or a repeat purchase. Excluded from both numerator and denominator.
- `orders_without_ledger_row` — on the settled basis, an order the ledger cannot see. Reported
  **by count, never by a cut-over date**, for the reason given in §9.

**Null is a real answer.** `repeatRate()` returns `null`, not `0.0`, when no order in the window
belongs to an identifiable customer: there is nothing to divide by, and zero would claim every
buyer is a one-time buyer.

**Its period is not the dashboard's.** A repeat purchase needs two orders, so a thirty-day window
systematically reports a store as less loyal than it is. The `Repeat purchase` tile measures over
`LedgerCoverage::window()` — the span the financial record can vouch for under owner decision
**D1** — and prints that period beside the figure: *all time* when coverage is complete, *from
&lt;date&gt;* when it is not, and nothing at all, with the reason, when the ledger can vouch for no
period yet.

**Not built, and not inferred:** cohort retention, churn, lifetime value, and
new-vs-returning *trend*. The first three need either a subscription record or an assumption about
what counts as churned; the plan invents neither.

## 13. Storefront conversion

**Computed by.** `App\Support\Metrics\MerchantAnalyticsFigures::conversion()` — a presenter figure, not a
`Metric` subclass, declared as such in `MetricDefinitionsAreDocumentedTest::PRESENTER_FIGURE_SECTIONS`.

> **Written from the shipped code on 2026-11-22, not ahead of it.** The `Conversion` tile reached
> `/analytics` with P5-5 and had no entry here: the definition lived only in the docblocks and
> on-screen hints of `MerchantAnalyticsFigures`. `GAP-155` recorded that as "conversion has no
> definition" with a next action of asking the owner. Engineering had by then already settled the
> only basis the data supports and disclosed its limits on the tile, so this section specifies what
> is there. It decides nothing new.
>
> **THE OWNER HAS NOT RULED ON THIS BASIS, AND THIS SECTION IS NOT THAT RULING.** The traced
> requirement (`P05-07`) expects *"visitors or sessions against orders"*. What ships is neither: it
> divides by **page views**. Whether that satisfies the requirement, or whether a visitor- or
> session-based figure is wanted, or whether conversion should be withdrawn until one exists, is
> still the owner's to say and stays open on `GAP-155`. Documenting a shipped figure is `ANA-008`;
> it does not make the figure approved.

**Definition.** Orders placed in the period divided by storefront page views recorded in the same
period, as a percentage to two decimals: `orders / page_views × 100`.

**Source.** Two records that nothing joins:

| | what it reads | counted by |
|---|---|---|
| numerator | `orders` for the store — **every** order, whatever its payment or fulfilment state | `order_date` |
| denominator | `shetabit_visits` for the store — one row per themed storefront request | `created_at` |

The numerator is the same count as the `Orders` tile beside it (`countOrders()`), so the two
cannot disagree. A paid-orders rate would be a different metric; it is not built.

**A page view is not a person and not a session.** The tile says so in words every time: *"the
same visitor browsing five pages is five, and automated traffic such as search-engine crawlers and
link previews is included."* Nothing in the log distinguishes a crawler from a shopper.

**It is a ratio of two counts, not a funnel.** No visit row is linked to an order row — there is no
cart token or session key carried onto an order — so the figure cannot say *which* visits became
orders. Conversion **by device, by source or by campaign is therefore not derivable** and is not
shown; that is a schema limit recorded under `ANA-004`, not a gap waiting for a query.

**Null is a real answer.** With no page view in the period the tile shows no figure and says
there is nothing to divide by. `0%` would read as a failing store; the truth is no data.

**More orders than page views is possible, and is stated.** Some orders arrive by a route that
logs no page view. The tile then says so rather than printing a rate above 100% unexplained.

**Coverage is disclosed, and the period is not silently narrowed.** Page views are attributed to a
store only from the first row carrying its `store_id`. A period that starts before that date is
only partly covered, and the tile names the date. The window stays exactly as the merchant asked
for it — re-scoping it would answer a question they did not ask and make this tile disagree with
the `Orders` tile.

**The change against the earlier period is in percentage POINTS.** `2.10%` rising to `2.80%` is
`+0.70` points; calling it `+33%` is how an analytics screen overstates a small movement. And
**no change is shown at all** when the earlier period has no page views, or when it reaches back
across the date page views began to be recorded — its figure would be understated, and the
comparison would report a trend that did not happen.

**Verification test.** `MerchantAnalyticsFiguresTest` — orders over page views, and no figure with
the reason when there are no page views. `ConversionBaselineTest` — the change in percentage
points, rising and falling, and no rate rather than a rise from zero when the earlier period has no
page views. `VisitCoverageStartTest` — the coverage date is this store's own, the partly-covered
sentence appears only when the period really starts before it, and no comparison is shown when
the earlier period straddles that date.

**Computed by** `MerchantAnalyticsFigures::conversion()` and `::rateComparison()`. It is a screen
figure and deliberately **not** a class of the `Metric` family: that base reads the three money
records of §0, and this reads none of them.

**Not built, and not inferred:** a visitor- or session-based rate; conversion by device, source or
campaign; a paid-orders rate; attribution of any order to any visit.

## 14. Campaign arrivals

**Computed by.** `App\Support\Metrics\MerchantAnalyticsFigures::campaigns()` — a presenter figure, not a
`Metric` subclass, declared as such in `MetricDefinitionsAreDocumentedTest::PRESENTER_FIGURE_SECTIONS`.

> **Written from the shipped code on 2026-11-22.** `GAP-161` recorded marketing performance as
> having "no slice and no metric definition". Half of that was overtaken — the arrivals panel
> shipped under P5-5 — and half is a limit of the data. This section specifies the half that
> exists and names the half that cannot.

**Definition.** For each `utm_source` / `utm_campaign` pair, the number of storefront requests in
the period whose **landing address carried that tag**.

**Source.** `shetabit_visits.url` for the store, by `created_at`. The tags have been recorded all
along — the visit logger keeps the query string and redacts only credential-shaped parameter
names, which `utm_*` do not match — and nothing displayed them before this panel.

**It counts ARRIVALS — not people, not sessions, and not revenue.**

- **Not people or sessions.** A shopper who arrives on a tagged link and then browses five untagged
  pages is **one** row here and **six** page views on the tile beside it. The two columns are not
  comparable and the panel says so, because the layout invites exactly that comparison.
- **Not last-touch.** Only the request that actually carried the tag is counted. No tag is carried
  forward across a visit.
- **A campaign with no source is not shown.** `utm_campaign=spring` alone names a campaign and not
  where it ran; a row labelled only `spring` beside `spring — newsletter` would read as a third
  channel. This is a judgement, recorded as one. **The converse is kept:** a source with no
  campaign is a real statement about where traffic came from, so it is shown and labelled as
  having no campaign name.
- `utm_term` and `utm_content` are ad-level and are not shown.

**CAMPAIGN REVENUE AND CAMPAIGN CONVERSION ARE NOT BUILT, AND MUST NOT BE BUILT BY INFERENCE.**
They need the link §13 says does not exist: a visit row tied to an order row. A per-campaign
revenue figure derived from tags alone would be an invented attribution, which this plan's own
rules forbid. `GAP-161`'s revenue half is therefore **blocked on the schema**, not unimplemented:
it becomes buildable only if an order starts carrying the identity of the visit that produced it,
which is a persistent schema change and so an owner decision.

**The list is bounded, and says when the bound is reached.** Only the most common tagged landing
addresses are read; when the cap applies the panel states it, because a silently truncated
breakdown reads as a complete one.

**Verification test.** `CampaignTrafficTest` — tagged landings counted per campaign; untagged
requests absent; a source without a campaign kept; the letters `utm` elsewhere in an address not
mistaken for a tag; another tenant's campaigns never shown; and the breakdown present in the
export as well as on the screen.

**Computed by** `MerchantAnalyticsFigures::campaigns()` and `::campaignTags()`. A screen figure,
not a class of the `Metric` family, for the reason given in §13.

**Not built, and not inferred:** revenue, orders or conversion per campaign; a people- or
session-based count; multi-touch or last-touch attribution; spend, cost per arrival or return on
ad spend — no campaign cost is recorded anywhere in this product.

## 15. What one customer is worth: collected, and retained

**Computed by.** `App\Support\Metrics\CustomerLedgerMoney`.

**Why this section exists.** `GAP-156` states the defect in one line: *every money-bearing customer
figure counts cancelled, unpaid and refunded orders, and no customer metric is defined in this
document*. Both halves were true. The customer timeline, the merchant customer list and its
financial filter, and the admin API all read `SUM(orders.final_price)` with no payment predicate at
all, so a shopper whose only order was cancelled before delivery read as a paying customer, and a
merchant could build a segment out of money nobody ever handed over.

**The predicate cannot live on `orders`, and that is a decision, not a preference.** Owner decision
**Option A**, quoted verbatim in `Order::order_status_change()`: *"`orders.payment_status`
represents the customer's actual payment settlement. Cancellation must not rewrite a captured
payment as Unpaid merely to trigger wallet reversal. Represent cancellation and financial reversal
reasons in the existing financial ledger structures."* So the cancel branch leaves
`orders.payment_status` alone and `OrderEntitlementReversal` records the reversal in the ledger
instead. **No filter on `orders.payment_status` can exclude a cancelled order.** The money has to
come from the ledger, which is also where §2 and §6 already read it.

**Two figures, and the whole point is that they differ.**

| figure | section it applies | includes money later reversed? | answers |
|---|---|---|---|
| **Collected from this customer** | §2, per customer | **yes** | *has this person ever paid us?* |
| **Retained from this customer** | §6, per customer | **no** | *what is this person worth to the business?* |

A paid-then-cancelled order makes them disagree by exactly the captured amount. That disagreement
is information, not an error: the shopper really did pay, and the merchant really did not keep it.

**NEITHER IS EVER PRINTED UNDER THE OTHER'S NAME.** They carry different machine keys
(`customer_collected_payments`, `customer_net_retained_receipts`), and a surface that shows one
labels it for what it is. This is the rule §11 states generally, written out here because the two
quantities are close enough to be confused and far enough apart to matter.

**Source.** `collected` reads `store_order_financials.paid_amount`, dated by `paid_at`, under §2's
own inclusion rule — `paid`, plus the reversed-but-captured states where `paid_amount` is still
above zero because P4-F124 deliberately preserves it through a reversal. `partially_paid` is
**included**, stated here as §11 requires rather than chosen silently: such a row holds real money
the shopper handed over, and excluding it would commit the exact under-count §2 exists to prevent.
`retained` reads the signed sum of order-referenced `store_wallet_transactions`, dated by
`created_at`, which is §6's preferred computation for §6's own reason — every movement is typed,
reasoned and written once, so a reversal subtracts itself without this layer restating the rule.

**Which orders are this customer's** is not decided here. `Customer::ownStoreOrders()` already
answers it for the product — directly by `orders.customer_id`, and for a customer with no such
orders by matching unattributed orders on the billing e-mail — and the figures follow that answer
rather than inventing a second one. A figure that covered a different order set from the
`Total Spend` beside it would read as a payment difference when it was really an attribution one.

**`customer_id <= 0` is refused, not aggregated.** `orders.customer_id` is `INTEGER DEFAULT 0`, so
"nobody attached" and "customer zero" are one value. §12 excludes such orders from a store-wide
rate and reports the count; for a figure *about one person* it is worse than a caveat, because id 0
would return every unattributed order the store ever took, presented as one shopper's history.

**Two coverage boundaries, reported by count rather than assumed away** — the §9 rule:

- `orders_without_ledger_row` — an order the ledger cannot see. `store_order_financials` is written
  only by `OrderObserver::created()` and was never backfilled, so orders predating it have no row
  and contribute nothing. Without this count the new figure would silently under-report to 0.00
  where the old one over-reported, and `MetricCaveat::ENTITLEMENT_RECORD_INCOMPLETE` would not fire.
- `movements_without_resolvable_order` — `store_wallet_transactions.reference_id` carries **no
  foreign key**, so an order-referenced movement whose order row is gone resolves to nothing. It is
  excluded from the per-customer figure, which is why the sum of every customer's `retained` need
  not equal §6 for the same store and window.

**Verification test.** `CustomerLedgerMoneyTest` — a paid order in both figures; a paid-then-
cancelled and a refunded order in `collected` but not in `retained`; an unpaid order in neither; a
never-paid cancellation contributing no row; another tenant's orders for the same customer id
absent; a ledger row whose order belongs to another store absent; two currencies kept apart and
refused a total; an order with no ledger row contributing nothing rather than a guess; repeated
movements on one order counted once; a movement of another reference type not attributed; a
voided movement excluded; bounded windows on both figures, including the case that tells
*dated by the movement* apart from *dated by the order*; the bulk read agreeing with the single
read and not leaking between customers; and `customer_id <= 0` refused.

**Computed by** `CustomerLedgerMoney`.

**Not built, and not inferred:** customer lifetime value, VIP or segment eligibility, churn, and
any refund policy. Each is a business choice this document does not hold, and §11's rule applies —
the quantities are supplied and the choice is left to whoever is entitled to make it.

### 15.1 A third boundary, found by a test that failed for the right reason

The two figures read two different tables, so there are **two** ways the record can fail to answer
and only one of them is `orders_without_ledger_row`:

- **The financial record cannot see the order.** Counted per customer; fires
  `ENTITLEMENT_RECORD_INCOMPLETE`; applies to BOTH figures, because both cover the same order set.
- **The financial record says money was collected and the wallet holds no movement against it.**
  `syncFromOrder()`'s early return blocks a late-collection wallet credit (AUD-3), so this is a
  live divergence rather than a theoretical one. Left alone it renders as *collected 125.00,
  retained 0.00* — which reads as "every penny was refunded", a claim about that shopper that
  nothing in the data supports. The retained figure is therefore **withheld**, not zeroed.

**Counted by ROWS, never by the amount, and the two cases are opposite.** A retained figure of 0.00
reached by one credit and one equal debit is a real, fully-refunded zero and must be shown; a 0.00
reached by there being no movement to read is an absence. Only `rowCount()` separates them.

### 15.2 What a merchant may filter on, and what an absence means there

Both figures are offered as customer-list filter fields (`Payments Collected`, `Net Retained`)
**beside** `Total Spend`, never instead of it. Repointing `Total Spend` would have changed which
customers an existing saved segment returns with nothing on screen to say so, and an absence from a
list is the hardest thing in a product to notice.

A filter needs a scalar where a tile can refuse, so the comparison distinguishes three states —
`CustomerMoneyFigures::filterValue()`:

| state | value | why |
|---|---|---|
| no orders attributed at all | `0.0` | nothing to collect and nothing unknown; belongs in "less than" |
| orders, all covered | the sum (may be `0.0`) | a customer who has genuinely paid nothing is a real zero |
| orders the record cannot see, or the wallet divergence above | `null` | unknown; excluded from **both** directions, since including it either way asserts a figure nobody has |

`applyNumeric` skips a null for every operator, so the naive implementation — `comparable()`, which
answers null for any empty ledger — would have dropped exactly the customers a re-engagement
segment is looking for. **The filter refuses precisely what the tile refuses**, so the list can
never select on a number it declines to print.

**Surfaces.** Customer timeline, merchant customer list and its filter, and the admin API's
`ledger` block on `customerlist` and `/customer/orders`. All four go through
`CustomerMoneyFigures`, so display and selection cannot drift. `Total Spend` / the API's
`total_spend` keep their shipped values and now carry a caveat saying they are order value and not
money received.

**One attribution question is left open, deliberately.** The two admin API endpoints attribute
`orders`/`total_spend`/`aov` by `customer_id` alone, while `Customer::ownStoreOrders()` — which
these ledger figures follow, so that a figure and the `Total Spend` beside it cover one order set —
falls back to matching unattributed orders on the billing e-mail for a customer who has none of
their own. So those endpoints can report `orders: 0` beside a non-zero collected figure. Which rule
is right is a product question, not a correctness one; the payload states `attribution` rather than
a guess being made.

**Verification test.** `CustomerMoneySurfacesTest` — the tiles in the customer's own currency and
not the viewer's; the two figures differing on a refund; an unpaid order as a real zero; an
uncovered order withheld with its count; two currencies listed and never added; a storeless
customer refused; the wallet divergence withheld and its fully-refunded opposite shown; the filter
selecting on the printed figure, keeping a never-paid customer in "less than", excluding an unknown
one both ways, refusing the divergence case, never crossing tenants, and leaving `Total Spend`'s
selection unchanged; the dropdown's field names matching the filter's exactly; and the API carrying
both figures with their notes, keeping `total_spend` unchanged, sending `null` rather than 0.00 for
an unreadable figure, listing no other tenant's customer, and refusing another tenant's store.
