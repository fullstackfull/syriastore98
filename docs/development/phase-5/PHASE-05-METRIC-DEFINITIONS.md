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

**Definition.** Orders awaiting money, split by *why*.

**Source.** `store_order_financials.payment_status` with `orders.payment_type`.

**Three sub-metrics, never one.** Collapsing them is how a COD book looks like bad debt:

| sub-metric | predicate |
|---|---|
| awaiting COD collection | `payment_status = 'pending'` and `payment_type` in the COD set |
| awaiting manual settlement | `payment_status = 'pending'` and `payment_type = 'bank_transfer'` |
| genuinely unpaid | `payment_status = 'unpaid'` |

**Excludes.** Cancelled orders — a cancelled unpaid order is not awaiting anything, which is exactly
why P4-F124 moves such rows to `cancelled` rather than leaving them at `unpaid`.

**Verification test.** A cancelled COD order appears in none of the three.

---

## 4. Cancelled orders

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

**Verification test.** The two computations agree on a dataset containing a paid order, a cancelled
paid order, a refunded order and an unpaid order. **Disagreement is a reconciliation defect and must
fail the build, not be averaged.**

---

## 7. Merchant wallet entitlement

**Definition.** What the merchant may withdraw. `store_wallets.available_balance`.

**It is NOT revenue** and must never be labelled as such in any UI: it is reduced by withdrawals
(`total_withdrawn`) and by reversals, and it is denominated in the **wallet's** currency, which can
differ from the order's.

**Verification test.** `available_balance` equals the signed transaction sum for the store. This is
the drift check from `P4-F032-WALLET-RECONCILIATION-PROCEDURE.md` §Q-F032-5, run as a test rather
than a query.

---

## 8. Actual store revenue (margin)

**Definition.** What the merchant earned, net of the cost of goods.

**Source.** `store_order_financials.gross_profit`, which is
`(product_amount − discount_amount) − product_cost`.

**Carries a stated caveat and must display it.** `product_cost` is a snapshot of
`products.cost_price` **at order time**, and for any order whose financial row predates the P1-SEC-44
fix it may be 0 or, historically, another store's cost. It also excludes shipping and tax by
construction.

**Verification test.** An order whose product has no `cost_price` reports margin equal to
`product_amount − discount_amount` and is flagged cost-unknown, not silently treated as 100% margin.

---

## 9. Coupon redemption

**Definition.** Coupons actually consumed.

**Source.** `order_coupon_details` joined to the order.

**BLOCKED AS AN AUTHORITATIVE FIGURE, by instruction** — owner: *"Review P4-F134 and P4-F135 before
using coupon-redemption records as authoritative data."* Both are open:

- **P4-F134**: `ordersave` may record **no coupon redemption at all**, so coupon caps never see
  those orders — redemption is under-counted by an unknown amount;
- **P4-F135**: a **refused** coupon still writes `order_coupon_details` and `user_coupons`, burning
  the shopper's allowance — redemption is over-counted by an unknown amount.

**Errors in both directions, neither quantified.** Any campaign-redemption report must carry that
caveat or not be built. **No metric in Phase 05 may treat this as settled.**

---

## 10. Shipping and tax

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
4. **`FLOAT`, not `DECIMAL`, on `orders`.** ~7 significant digits on a currency that runs to
   millions. Aggregate on `store_order_financials` (`DECIMAL(15,2)`) wherever both are available.
5. **A metric with a known gap displays the gap.** §9 is blocked, §5 excludes partials, §8 has a
   cost caveat, §4 has a date caveat. A number that hides its own limits is worse than no number —
   which is the whole point of "trustworthy analytics".
