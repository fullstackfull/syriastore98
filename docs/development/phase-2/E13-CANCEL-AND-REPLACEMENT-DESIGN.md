# E13 — cancel and linked replacement order: design, schema review, and the decision point

**Date:** 2026-10-26
**Authority:** owner approval of 2026-10-26 (D-07) for the **minimum safe path inside Phase 02**,
engineering and dev/test environments only — *"هذه الموافقة تشمل التنفيذ الهندسي والتغييرات اللازمة
في بيئات التطوير والاختبار فقط، ولا تشمل تطبيق Migration على الإنتاج."*
**Status:** design complete; **one commercial decision is unresolved** and is stated in §6. No code
has been written.

The owner set the sequence explicitly:

> ابدأ بتصميم سلوك الإلغاء والاستبدال، ومراجعة العلاقات والمخطط الإنتاجي، وتحديد تأثير الدفع
> والمخزون، ثم نفذ واختبر المسار في بيئة معزولة.

and the four conditions:

> بشرط إعادة استخدام محرك الطلبات الحالي وعدم تغيير الطلب الأصلي أو تكرار المدفوعات أو حركات المخزون.

This document is steps 1–3. Everything below was read or executed; nothing is inferred from the
migrations alone, which for this codebase are not the schema.

---

## 1. What cancellation does today, measured

`Order::order_status_change()` — `app/Models/Order.php:579-598`. The whole arm:

```php
if ($order_status == 'cancel') {
    if ($order->delivered_status != 0) {
        return ['status' => 'error', 'message' => 'The order has been delivered. So you cannot cancel the order.'];
    }
    $order->cancel_date = now();
    $order->delivered_status = 2;
    $order->payment_status = 'Unpaid';   // ← the money reversal, via the Observer
    $order->save();
    event(new OrderPaymentStatus($order));
    return ['status' => 'success', 'message' => 'Order status changed.'];
}
```

Three facts worth stating because each one constrains the design:

**(a) Cancellation is only possible before dispatch.** `delivered_status != 0` refuses. So a
cancel-and-replace flow can never be the remedy for goods already with the customer — that is the
returns system, which is Phase 04, exactly as the owner distinguished.

**(b) Cancellation reverses the money, through an Observer, not inline.**
`OrderObserver::updated()` fires only `if ($order->wasChanged('payment_status'))` and calls
`OrderFinancialService::syncFromOrder()`, which is **state-guarded**:

| order's normalised status | financial row's status | action |
|---|---|---|
| `paid` | not `paid` | `markFinancialAsPaid()` — wallet credit |
| not `paid` | `paid` | `markFinancialAsUnpaid()` — wallet reversal |
| anything else | — | status field updated, **no financial movement** |

So cancelling a **paid** order reverses the wallet credit; cancelling an **unpaid** (e.g. COD)
order moves no money. The guard is a genuine idempotency property and it is re-used below.

**(c) Cancellation does NOT restore stock.** Nothing in the arm, the Observer, or any site keyed
on `delivered_status = 2` increments stock. Confirmed by census: the only restore call sites are
`ProductStockService::restoreProductStock()` / `restoreVariantStock()`, reached from the **return**
path (`Order.php:1004`, `:1035`) and from `PaymentService`/`OrderController`/`OrderRefundController`
failure rollbacks. **Cancel is not among them.**

This asymmetry — cancel reverses money but not stock — is the single most important input to the
design, and it is pre-existing behaviour, not something this slice introduces.

---

## 2. Relations and schema review

### The order engine already owns every primitive this needs

| Primitive | Where | Property that matters here |
|---|---|---|
| atomic stock restore | `ProductStockService::restoreProductStock/restoreVariantStock` | `UPDATE … SET product_stock = product_stock + ?` — arithmetic in the database, flushes the model cache |
| per-line restore idempotency | `Order::LINE_STOCK_RESTORED` (`'stock_restored'`, `Order.php:753`) + `Order::lineStockAlreadyRestored()` | the marker is stamped **into the line inside `product_json`** and saved with the order, so a sibling endpoint's loop cannot credit the same goods twice |
| one financial row per order | `store_order_financials`, `UNIQUE KEY uq_store_order_financial_order (order_id)` | a **database-level** bar on a duplicate financial row for an order |
| state-guarded money movement | `OrderFinancialService::syncFromOrder()` | re-asserting the same payment status moves no money |

**This is why the owner's "reuse the existing engine" condition is satisfiable.** A replacement
flow does not need a new stock primitive, a new ledger, or a new money path — it needs a link and a
policy.

### What does NOT exist: the link

Measured three ways, all negative:

- **No column.** `orders` has no `parent_order_id`, `original_order_id`, `replacement_order_id` or
  `replaced_by`. `Order::$fillable` lists 33 keys and none is a link.
- **No migration.** No migration in `database/migrations/` adds such a column; the four that touch
  `orders` add `deliveryboy_id`, `payment_receipt`, `product_order_status`, and
  `currency_code`/`created_by`.
- **Not in production either.** `orders` is a *managed* table (it has migrations, so it is not a
  GATE F table), and the verified production schema carries no link column.

So **E13 requires one additive migration.** Under the owner's approval that migration may be
created and applied in **dev and test only**; applying it to production is explicitly excluded and
is not proposed here.

### A schema hazard that must be stated

`store_order_financials` and `store_wallet_transactions` are **GATE F tables: they exist in
production with live data and no migration creates them.** The `UNIQUE (order_id)` guard in §2 is
therefore present in production and **absent from every environment built from migrations** —
CI, a fresh staging database, the SQLite test database. Any test that claims "a duplicate financial
row is impossible" proves nothing unless it creates that table with that constraint first. This is
recorded here because it is also an **E14** finding, and because a test written without it would be
one of the false readings CLAUDE.md warns about.

---

## 3. Payment impact

| Original order was | On cancel | A replacement order would be | Duplicate payment? |
|---|---|---|---|
| **Paid** | wallet credit reversed (`markFinancialAsUnpaid`) | created `Unpaid`; the customer pays it | **No** — one reversal, one new charge. Net: the customer is charged once for the goods they receive. |
| **Unpaid / COD** | no money moves | created `Unpaid` | **No** — nothing moved either way. |
| **Partially paid** | *not handled by the cancel arm*: `normalizePaymentStatus` maps it to a non-`paid` value, so a `paid` financial row is fully reversed | created `Unpaid` | **Reversal is for the full amount**, not the part paid. See §6. |

The first two rows satisfy the owner's "لا تكرار المدفوعات" condition **with no new code**,
because the reversal and the new charge are two different orders and
`UNIQUE (order_id)` keeps one financial row per order. The third row is a pre-existing gap in the
cancel arm, not introduced here, and it is one half of the unresolved decision.

---

## 4. Stock impact — where the real difficulty is

Cancel takes nothing back (§1c). So for a replacement covering **the same goods**:

```
original order placed    stock -N      (at payment, via ProductStockService)
original cancelled       stock  0      (no restore — measured)
replacement created      stock -N ?    ← if the replacement decrements like any new order,
                                         the same goods are taken from stock TWICE
```

That second decrement is exactly what the owner forbade: *"ولا ... تكرار ... حركات المخزون"*.

Two ways to avoid it, and they are **not** equivalent:

**S1 — the replacement carries the original's stock.** The replacement is created with its lines
pre-stamped as already-taken, so no path decrements again. Net stock movement across the pair:
`-N`, which is correct for a pure replacement. Reuses `LINE_STOCK_RESTORED`'s exact idea (a marker
in `product_json` that a later loop checks) rather than inventing a mechanism.
*Only correct while the replacement's lines are identical to the original's.* The moment a
quantity or an item changes, the delta must move, and deciding **which** delta moves and when is a
commercial rule.

**S2 — cancel restores, replacement takes normally.** Internally consistent and needs no marker.
**But it changes what cancellation does to stock for every existing caller** — the merchant web
UI, `Api\DashboardController`, the courier API — and the owner's standing limits reserve changes to
stock behaviour. It is also the larger behavioural change, so under *"الحد الأدنى الآمن"* it is the
wrong one to pick unilaterally.

**Recommendation: S1, restricted to an identical-lines replacement.** It is the only option that
changes no existing caller's behaviour, adds no money path, and reuses a primitive that already
exists with a proven idempotency marker.

---

## 5. The design, under the four conditions

A single operation, `cancelAndReplace(Order $original)`, which:

1. **Refuses unless the original can be cancelled** — the same `delivered_status != 0` test, read
   from the existing arm rather than re-expressed, so dispatch-time semantics cannot drift.
2. **Cancels the original by calling the existing engine** — `order_status_change(['order_status' => 'cancel'])`.
   Not a copy of its body. The Observer then reverses the money exactly as it does today.
   *Condition "do not change the original order": the original is cancelled, which is the point of
   the operation, and nothing else about it is touched — no line, no price, no total.*
3. **Creates the replacement from the original's own `product_json`**, identical lines, each line
   stamped as already-stocked, linked by the new column.
   *Condition "do not duplicate stock movements": satisfied by the stamp (S1).*
   *Condition "do not duplicate payments": satisfied by `UNIQUE (order_id)` plus the fact that the
   replacement is a distinct order with its own financial row.*
4. **Is wrapped in one transaction**, so a failure leaves neither a cancelled order without a
   replacement nor the reverse.
5. **Is authorised exactly as the existing order-status change is** — `OrderPolicy::allow()`, which
   tests `isPlatformAdmin` first (CLAUDE.md: `isAbleTo()` alone refuses the platform operator), and
   the store ownership check. **No new permission is invented.**

### What it must NOT do, each for a stated reason

- **Not touch the original's lines, prices or totals.** Owner condition, and an audit trail
  requirement: the cancelled order is the record of what was cancelled.
- **Not create a second financial row for the original.** `UNIQUE (order_id)` would reject it,
  and the reversal is the correct financial event.
- **Not restore stock** (that is S2, not approved).
- **Not change the `cancel` arm.** Every existing caller keeps today's behaviour.
- **Not be reachable from the storefront or the customer API in this slice.** Merchant-initiated
  only; a customer-initiated replacement is a different authorisation question.

---

## 6. The unresolved decision — what the owner needs to settle

The design above is safe **only for a replacement whose lines are identical to the original's**.
That is a genuine restriction, and it is the point at which engineering stops and a commercial
rule begins. Three questions, each of which changes the implementation:

**Q1 — may a replacement differ from the original at all?**
If the answer is "identical lines only" (a reissue — e.g. the original was placed against the
wrong address or payment method), S1 is complete and the slice can be implemented and tested now.
If a replacement may change quantities or items, then stock deltas must move, and **which** delta
moves (and whether an increased quantity may be taken when stock is short) is a pricing-and-stock
rule that the standing limits reserve.

**Q2 — what happens to a partially-paid original?**
Today's cancel arm reverses a `paid` financial row in full and has no partial handling (§3). A
replacement flow makes this visible: the customer has paid part, the whole is reversed, and the
replacement starts unpaid. Whether that is the intended settlement, or whether the part paid should
carry across, is a settlement rule — covered by *"ولا تغيّر قواعد التسوية أو رد الأموال دون ضرورة
مثبتة ومراجعة مستقلة"*.

**Q3 — is the replacement priced at the original's prices or today's?**
The original's `product_json` carries the prices at the time of order. If a product's price has
changed since, a replacement built from that JSON charges the old price, and a replacement built
from the catalogue charges the new one. Both are defensible; it is a commercial choice and
**"لا تخترع سلوكاً مالياً من عندك"** applies directly.

### What is being done about it

Per the owner's own instruction for this case —

> إذا لم يمكن تنفيذ المسار بأمان دون قرار تجاري إضافي غير محسوم، وثّق نقطة القرار تحديداً وواصل
> باقي المهام.

— the decision point is documented here and in `PHASE-02-DECISION-REGISTER.md` (D-07, updated),
and work continues on the rest of the phase. **If the owner answers Q1 with "identical lines
only", Q2 and Q3 fall away** (an identical reissue at the original's own prices, with a full
reversal), and the slice is implementable immediately as specified in §5 — which is why Q1 is
first.

---

## 7. What would be built, once Q1 is answered

Listed so the size of the remaining work is visible and so nothing is quietly expanded later.

| Item | Note |
|---|---|
| one additive migration: `orders.replaced_order_id` (nullable, FK to `orders.id`, indexed) | **dev/test only** under D-07 |
| `$fillable` entry | without it Laravel silently discards the value — the mistake `created_by` already recorded in that array |
| `Order::replacedOrder()` / `replacementOrder()` relations | read-only |
| `cancelAndReplace()` on the existing service layer | calls `order_status_change`, does not reimplement it |
| the already-stocked line stamp | reuses the `LINE_STOCK_RESTORED` pattern, new constant |
| merchant UI entry point | one action on the order detail page, behind the existing policy |
| tests | the pair's net stock movement is `-N` not `-2N`; exactly one financial reversal; the original's lines and totals byte-identical after the operation; a replayed call does not produce a second replacement; a cross-tenant original is refused; **and the `UNIQUE (order_id)` constraint created in the test schema first**, per §2's hazard |
| Flutter/Lynomia contract | the new column is additive and no existing response shape changes; a Contract-suite assertion that the admin order payloads are unchanged |
