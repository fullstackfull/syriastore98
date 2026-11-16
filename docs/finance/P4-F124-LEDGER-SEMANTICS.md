# P4-F124 — what a cancelled order's payment record should say

**Status: UNRESOLVED. A material business decision is required.** Nothing in this document has been
implemented. The shipped financial semantics are preserved exactly as they are.

This exists because the obvious repair is wrong. The Flutter admin app's cancel writes no
`payment_status`, so `OrderObserver` never reverses the merchant's wallet credit, and the apparent fix
is "write `'Unpaid'` like the web door does". **Measurement says the web door's write is itself
unsound**, for a different reason in each direction, so copying it would spread a defect rather than
close one.

Every claim below was measured by reading the code at `4f804baf` (branch `feature/phase-04-commerce`),
with two facts measured by executing a fresh migration. File:line references are to that commit.

---

## 1. The defect as reported

| door | `payment_status` | wallet | goods |
|---|---|---|---|
| merchant web `order-status-change` | `'Unpaid'` (`Order.php:712`) | **reversed** | restored |
| storefront customer `status-cancel` | `'Unpaid'` | **reversed** | restored |
| storefront API `order-status-change` | `'Unpaid'` | **reversed** | restored *(since `4f804baf`)* |
| admin API `status-change` | `'Unpaid'` | **reversed** | restored *(since `4f804baf`)* |
| **Flutter admin `orders/update-status`** | **untouched** | **nothing** | restored |
| **courier webhook `delivery-syria/orders/update-status`** | **untouched** | **nothing** | restored |
| driver `order-cancel` | untouched | nothing | restored *(since `4f804baf`)* |

`OrderObserver::updated()` returns early unless `wasChanged('payment_status')` (`OrderObserver.php:32`).
That one line is the whole of P4-F124: the two doors that write no `payment_status` reverse no wallet
credit, so a merchant who cancels in the app keeps money the order no longer justifies.

---

## 2. Why "just write `'Unpaid'`" is the wrong repair

### 2.1 The web door's write is a reversal trigger, not a payment statement

`Order.php:708-716`, with its own inherited comment, in translation: *"the missing magic line: the
payment status must be changed in order to refund the amount … here the Observer will detect the
change and call the wallet service."*

`PaymentVerificationService.php:175-182` says the same thing from the other side:

> *"The evidence that settle-at-order is wrong is in the platform's own code: `Order::order_status_change()`
> writes `payment_status = 'Unpaid'` when an order is CANCELLED … There is nothing to refund on a COD
> order that was never delivered. That reversal exists only to undo a Paid state the order should
> never have been given."*

So one column is doing two jobs: **the order's payment state** and **the observer's reversal signal**.
They disagree in both directions.

### 2.2 For a prepaid order, `'Unpaid'` asserts something false

A captured gateway payment really moved money. Writing `'Unpaid'` on cancellation records that the
customer was never charged. The customer *was* charged — the money is with the PSP — and
`RefundIntegrityService::hasGatewayRefundDriver()` is **hardcoded `false`** for every provider
(`:119-122`), so no automated refund follows. The wallet is reversed correctly and the order's payment
record becomes a false statement.

### 2.3 For an uncollected COD order, `'Unpaid'` is accidentally right and structurally meaningless

Nothing was collected, so nothing is owed back. But the same write would be emitted for a COD order
whose cash *was* collected, and the platform cannot tell the two apart, because:

> **The COD settlement seam is complete and has no door into it.**
> `CodCollectionService` defines the record — `collector_type` ∈ {merchant, courier, delivery_company},
> `collector_id`, `amount`, `collected_at` (*"WHEN the cash changed hands — NOT when the row was
> written"*), `evidence`, and `recorded_by` kept separate from `collector_id` on purpose.
> `PaymentVerificationService::codMayBeSettled()` is the gate, and its own comment at `:873` reads
> *"NO EVENT, NO SETTLEMENT. This is the whole decision in one line."*
>
> **Measured:** `cod_collection_events` exists in neither the test schema nor production — by design,
> decision D15. `codMayBeSettled()` has **zero production callers**. `CodCollectionService` is **never
> instantiated anywhere in `app/`**.

So "has this COD order's cash been collected?" is a question the system is built to answer and has no
way to ask.

---

## 3. Two services already disagree about what a COD `Paid` means

This is the sharpest evidence that the decision is a business one and not an implementation detail.

| service | position | evidence |
|---|---|---|
| `OrderReplacement::paymentVerdict()` | a COD `Paid` is **real collected cash** | `:154-156`: *"COD IS NOT EXEMPT, deliberately. A `Paid` cash-on-delivery order means the merchant holds the shopper's cash. Returning it is a real-world act this system cannot observe."* It refuses the operation. |
| `RefundIntegrityService::resolveRefundStatus()` | a COD refund is **settled with no gateway call** | `OFFLINE_PAYMENT_TYPES` (`:89-93`) includes `cod`, `cash_on_delivery`, `cash`, on the premise *"The merchant hands the cash back themselves"* (`:136-138`). |

For an **uncollected** COD order the second is a false settlement record — nothing was taken, so nothing
is handed back — and `RefundAmonut()`'s `Refunded` branch then rewrites the order's `product_price` and
`final_price` downward (`OrderRefundController.php:1154-1171`).

`PaymentVerificationService`'s own `OFFLINE_PAYMENT_TYPES` (`:111-144`) deliberately **excludes** COD:
*"this list means 'settles off-platform by arrangement, so record Paid at once', and COD no longer does:
it has its own state machine and is Pending until collection."* `RefundIntegrityService`'s list includes
it. The two lists are adjacent in purpose and opposite in content.

---

## 4. What the ledger actually guarantees today

| control | file:line | guarantees |
|---|---|---|
| `exists()` pre-check + `UNIQUE(order_id)` | `OrderFinancialService.php:28-35`; migration `:80` (**measured present**) | one financial row per order |
| financial row already `'paid'` → return, under `lockForUpdate()` | `:364-369` | no second credit |
| financial row not `'paid'` → return, under `lockForUpdate()` | `:477-482` | no second debit |
| `wasChanged('payment_status')` | `OrderObserver.php:32` | the observer runs only when payment state moves |
| per-door transition guards | `Order.php:702`, `DashboardController.php:536`, `:5775` | one cancel per order, per door |

**The idempotency control is the FINANCIAL ROW's status, not the order's.** That is the right design and
it is why a second credit is impossible. But:

- **`store_wallet_transactions` has no unique index of any kind** — measured. The ledger is append-only
  with no database-level de-duplication, so `Paid → Unpaid → Paid` writes three rows and nets correctly
  only because the row-status guards hold.
- **The Delivery Syria webhook's only idempotency is `$oldStatus === $newStatus`**, and its sender
  retries. A `2 → 1 → 2` sequence re-enters the cancel arm. It writes no `payment_status` today, so
  there is no money consequence — **but any repair that makes it write one inherits that gap.**

---

## 5. Four further defects the inventory surfaced

Recorded here because each bears on the decision; none is fixed.

**(a) Two order writers bypass the verification seam entirely.** `OrderController::store` (`:385`) and
`Api\DashboardController::createOrderViaApi` (`:6739`) accept `payment_type` as a free string and
`payment_status` as `Paid|Unpaid`, and neither calls `resolveOrderPaymentStatus()`. `payment_type = 'cod'`
with `payment_status = 'Paid'` is accepted and `OrderObserver::created()` credits the wallet at creation —
**the literal condition owner decision D-06 forbids**. The code's defence (`OrderController.php:374-384`)
is that a merchant is asserting an out-of-band fact, which is coherent for a counter sale and is not
coherent for a COD parcel that has not left. *(`OrderController::paymentVerifier()` at `:86` has no call
site — a dead accessor left when the COD block was withdrawn.)*

**(b) A refund never touches the order's payment record or the wallet.** Grep of the whole of
`OrderRefundController.php` finds no `payment_status`, no `StoreWallet`, no `OrderFinancialService`. A
fully refunded order keeps `payment_status = 'Paid'`, keeps its `store_order_financials` row at `paid`,
and keeps the merchant's wallet credit. `order_refunds.refund_status` is the only record money came back.

**(c) A sixth money door moves the wallet without touching the order at all.**
`StoreFinanceController::updateFinancialPaymentStatus()` (`:376-452`) calls `markFinancialAsPaid()` /
`markFinancialAsUnpaid()` directly on a `store_order_financials.id`. The order and its financial record
can diverge permanently, in both directions, with no reconciliation anywhere.

**(d) The repository's own documentation of the vocabulary is wrong.**
`OrderFinancialService.php:651-659` states that `'succeeded'` is written to orders by *"twenty order-write
sites"*, and `docs/finance/PAYMENT-STATUS-BACKFILL-ASSESSMENT.md` builds an impact assessment and
production SQL on that premise. **Every one of those assignment sites was classified as `$order = new
PlanOrder()`** — the SaaS plan-purchase rail, not `orders`. The `orders` gateway writers all route through
`resolveOrderPaymentStatus()`, which returns only `Paid` or `Pending`. *That same document also still says
the financial tables "do not exist in the test schema"; they were created on 2026-11-02 and are measured
present.*

---

## 6. The canonical semantics this needs, stated as questions only the owner can answer

Each is phrased so that an answer is implementable and a non-answer is visible.

1. **Does `orders.payment_status` record what the CUSTOMER did, or what the MERCHANT is owed?** Every
   contradiction above follows from the column doing both. If it records the customer's payment, a
   cancelled prepaid order stays `Paid` and the reversal needs its own signal. If it records the
   merchant's entitlement, `Unpaid` on cancellation is right and the customer's payment needs its own
   record.
2. **For a cancelled PREPAID order, is the money refunded, retained, or pending manual refund?** There is
   no gateway refund driver, so whatever the answer, the system cannot execute it — but it can record it.
3. **For a cancelled COD order, does the answer differ by whether cash was collected?** If yes, the
   collection event must exist first; the seam is built and D15 deliberately did not create the table.
4. **Should a refund move the wallet?** Today it does not. If it should, the refund rail needs the same
   financial-row seam the order rail has.
5. **What do the historical COD `Paid` rows mean?** The withdrawn Delivered trigger left them and
   `OrderController.php:1892-1893` records that they were deliberately not back-filled.
6. **May a merchant assert `Paid` on a COD order at creation?** (5a) above. A yes makes D-06 narrower than
   its wording; a no makes two shipped manual-order forms wrong.

---

## 7. The correction this proposes, once the semantics exist

Stated so it can be reviewed now and implemented later. **Not implemented.**

**Separate the signal from the state.** The reversal is an accounting event, not a payment fact, so it
should not be inferred from a column that means something else.

1. **A reversal reason, not a status overwrite.** The cancel doors record *why* the entitlement ended —
   `order_cancelled`, `order_refunded`, `collection_failed` — and `OrderFinancialService` reverses on that,
   not on `wasChanged('payment_status')`. The order's payment record then stays true to what the customer
   did.
2. **Idempotency at the financial row, which already works.** `markFinancialAsUnpaid()`'s
   `payment_status !== 'paid' → return` under `lockForUpdate()` is already the right control and needs no
   change; it is what makes a retried webhook or a second door harmless.
3. **A unique index on the reversal event**, so an append-only ledger cannot accumulate duplicate
   reversals for one order even if a caller is wrong. `store_wallet_transactions` has none today.
4. **Each door calls the seam; no door writes the column.** Six doors cancel an order; four write
   `'Unpaid'` and two do not. One seam removes the asymmetry without any door needing to know the ledger.
5. **COD stays Pending until a collection event exists.** That is D-06 and the existing
   `codMayBeSettled()` gate. It needs the table and one caller, both of which D15 deferred.

**Migration, schema change and any back-fill are out of scope** and are owner decisions in their own
right under the standing rules.

---

## 8. What a reading cannot settle

Four read-only production queries. None has been run; this environment has no production access.

1. `SELECT payment_status, COUNT(*) FROM orders GROUP BY 1` — settles §5(d) definitively and shows
   whether `Pending` has accumulated since the verification seam landed.
2. `SELECT COUNT(*) FROM orders WHERE LOWER(payment_type) IN ('cod','cash_on_delivery','cash') AND LOWER(payment_status) IN ('paid','success','succeeded')`
   — the rows the withdrawn Delivered trigger left behind, which question 5 must cover.
3. The reconciliation query in `PAYMENT-STATUS-BACKFILL-ASSESSMENT.md:62-78`, re-pointed at whatever
   query 1 shows the real vocabulary to be — do the 92 live `store_order_financials` rows agree with their
   orders?
4. A join of `order_refunds` against `store_order_financials` — how many live orders are both refunded and
   still credited? That measures §5(b), which cannot be estimated from code.
