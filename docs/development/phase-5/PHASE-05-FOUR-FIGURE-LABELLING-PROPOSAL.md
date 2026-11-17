# Four figures, four labels — a proposal

**Status: PROPOSAL. Nothing in here is implemented.** Owner recommendation 1 (2026-11-17) asked to
*"keep order value distinct from collected revenue, merchant settlement and profit"* and to
*"propose precise user-visible labels and inclusion rules."* This is that proposal. It needs a
decision before any of it ships, because three of the four figures do not exist on a merchant
screen today and adding them changes what an established card means.

Everything below is grounded in `app/Support/Metrics/` (P5-2), which already separates the three
records, and in the arithmetic verified in P5-3e.

---

## 1. The four figures

The point of separating them is that **they disagree with each other on purpose**, and a merchant
who sees only one of them draws the wrong conclusion from it. A store that is half COD has a large
order value and a small collected revenue; a store that has just paid itself out has a healthy
collected revenue and a near-zero settlement balance. Neither is an error.

| | figure | record | metric | what it answers |
|---|---|---|---|---|
| 1 | **Order value** | settlement — `orders` | `GrossMerchandiseValue` | what customers were asked to pay |
| 2 | **Collected revenue** | entitlement — `store_order_financials.paid_amount` | `CollectedCustomerPayments` | what customers actually paid |
| 3 | **Merchant settlement** | movement — `store_wallets.available_balance` | `MerchantWalletEntitlement` | what the merchant may withdraw now |
| 4 | **Profit** | entitlement — `store_order_financials.gross_profit` | `StoreRevenueMargin` | what is left after the cost of goods |

---

## 2. Proposed labels and inclusion rules

### 1. Order value — **already live** (P5-3e)

| | |
|---|---|
| **EN** | `Order value, including shipping and tax` |
| **AR** | `قيمة الطلبات، بما في ذلك الشحن والضريبة` |
| **includes** | every order placed in the period, **whatever its payment state** — unpaid, pending COD, cancelled, refunded; shipping and tax as charged |
| **excludes** | nothing by payment state. Cancellations are a **stated flag**, never a silent default (§1 of the metric definitions) |
| **after** | coupons — `orders.final_price` is written already net of them |
| **caveats to show** | `FLOAT_PRECISION`; the cancellations flag; `CURRENCY_UNRECORDED` where it fires |
| **is not** | revenue, payments, or profit |

This is a **demand** measure. Counting an unpaid order is correct here and wrong everywhere else.

### 2. Collected revenue — **proposed, not built**

| | |
|---|---|
| **EN** | `Payments received` |
| **AR** | `المبالغ المحصّلة` |
| **includes** | `payment_status` of `paid`; **and** `cancelled`, `refunded` or `partially_paid` rows whose `paid_amount > 0` |
| **excludes** | `unpaid`; `pending` — uncollected COD, unsettled bank transfer, and any gateway return whose capture could not be verified |
| **dated by** | `paid_at` — the month the money arrived, not the month the order was placed |
| **caveats to show** | `CURRENCY_UNRECORDED`; **and the availability limit in §3 below** |

**The inclusion rule that will look wrong and is right:** a customer who paid and then had the
order cancelled **still counts here**. They did pay. P4-F124 deliberately preserves `paid_amount`
through a reversal, and filtering on `paid` alone under-counts. The reversal shows up in figures 3
and 4, which is where it belongs.

### 3. Merchant settlement — **proposed, not built**

| | |
|---|---|
| **EN** | `Available to withdraw` |
| **AR** | `المتاح للسحب` |
| **is** | `store_wallets.available_balance`, in the **wallet's** currency, which can differ from the order's |
| **is reduced by** | withdrawals (`total_withdrawn`) and by reversals |
| **has no period** | a running balance has no date column. A date filter must be **visibly ignored**, not quietly applied to `updated_at` |
| **caveats to show** | that it is not revenue; the wallet currency when it differs from the store's display currency |

**It must never be labelled revenue.** A merchant who withdraws their earnings would otherwise read
that their sales had fallen.

### 4. Profit — **proposed, not built**

| | |
|---|---|
| **EN** | `Profit after cost of goods` |
| **AR** | `الربح بعد تكلفة البضاعة` |
| **is** | `(product_amount − discount_amount) − product_cost` |
| **excludes** | shipping and tax, **by construction** — unconditional caveat |
| **basis** | settled orders only (`payment_status = 'paid'`), as a **stated flag**. A cancelled-but-captured order's margin was reversed: §2 counts that money because the shopper paid it, profit must not because the merchant did not keep it |
| **caveats to show** | `EXCLUDES_SHIPPING_AND_TAX` always; `COST_UNKNOWN` **with the row count** whenever any counted row has `product_cost = 0` |

**`ANA-006` forbids claiming precise profitability when cost data is missing.** Where
`product_cost` is 0 the column reports the whole sale as profit — not an approximation, a 100%
margin on goods that cost something. The caveat is not decoration.

---

## 3. The constraint that shapes all of this

**`store_order_financials` has one migration and no backfill. The entitlement record begins
2026-11-02.** So figures **2 and 4 return zero for every order placed before that date** —
correctly, because the ledger genuinely has no rows, and uselessly, because a merchant reading it
concludes their business stopped.

This is not fixable in code, and backfilling is a live-data operation this proposal does **not**
request. It leaves three options, and this is the decision that matters most:

| option | consequence |
|---|---|
| **A. Show them, with the start date stated** — e.g. "Payments received (from 2 Nov 2026)" and a window that cannot be set earlier | Honest and available now. A merchant asking for last year gets a refusal with a reason instead of a figure. **Recommended.** |
| **B. Show them unbounded, with a caveat** | A merchant selecting any historical window sees a near-zero figure beside a real order value, with an explanation they must read to avoid alarm. This is the shape that produces a flat zero line under a real line — the wrong-number-that-looks-right. **Not recommended.** |
| **C. Hold figures 2 and 4 until the ledger has useful history** | No new number is wrong, and the separation you asked for stays unbuilt for months. |

---

## 4. Where these would render, and the delta per surface

| surface | today | under this proposal |
|---|---|---|
| `reports/_sales_stats.blade.php` (shared by **both** order-report bodies) | 6 tiles, all demand | + `Payments received`. **Two converted together or the partial takes resolved figures** — one copy feeds two handlers |
| `dashboard/overview.blade.php` Order value tile | demand, filters `delivered_status != 2` | unchanged; `Available to withdraw` belongs on the finance screen, not here |
| `finance/index.blade.php` | wallet balance tiles | `Available to withdraw` relabelled from whatever it says now; `Profit after cost of goods` added per the margin metric |
| the four JSON/CSV exports | inherit the screens' figures | **must carry the same caveats.** An export that drops a caveat is a defect by the plan's own §P5-8 |

**No existing figure changes value under this proposal.** Order value keeps its arithmetic
(pinned by `SalesSummaryLabelTruthTest`), and the three new figures are additions. That is
deliberate: you asked for the four to be kept distinct, not for any of them to be recomputed.

---

## 5. One blocker that is not mine to solve

`<x-ss.stat>`'s only caveat affordance is `hint`, and `stat.blade.php:40` renders it inside
`<span class="ss-visually-hidden">` — **a sighted merchant never sees it.** So `ANA-007` ("show data
quality, estimates and omissions") cannot be satisfied through that prop, and every caveat above
would be invisible to the people who most need it.

`<x-ss.card>`'s `note` (`card.blade.php:9`) **is** visible and is the available slot. Either the
caveats go there, or `<x-ss.stat>` gains a visible affordance — which changes a component used
across many surfaces and wants its own slice, in both Arabic RTL and English LTR, contrast-checked.

**Proposal: put the caveats in the card `note` for now** and treat a visible per-tile affordance as
separate work. That is available immediately and needs no component change.

---

## 6. What I need from you

1. **Option A, B or C in §3.** This decides whether figures 2 and 4 can ship at all this phase.
2. **The four labels**, or your corrections to them. The Arabic is a proposal too.
3. **Profit's basis** — settled-only (recommended, and the default the metric already carries) or
   all orders. Either way it is labelled; it is not silently chosen.

Nothing else here needs a decision. Once §3 is settled the rest is engineering, and it does not
change any figure a merchant reads today.
