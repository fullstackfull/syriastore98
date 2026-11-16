# P4-F127 — can the existing ledger safely represent a partial refund?

**ANSWER: NO. The partial-refund financial mutation is therefore left BLOCKED, and the missing
capability is documented here rather than approximated.**

Owner instruction, verbatim: *"For partial refunds, implement proportional financial handling only
if actual partial amounts can be safely reconciled and made idempotent. If the existing ledger
cannot safely represent a partial refund, leave the financial mutation blocked and document the
missing capability rather than approximating it."*

This document is that determination. Every claim below was measured from the code, with citations,
by a read-only audit of the refund surface; none of it is inferred from naming.

---

## 1. The short version

A "partial refund" today is **one scalar that happens to be smaller than the order total**. It is
not a position, not a running balance, and not reconcilable against anything. Five independent
properties each break proportional handling on their own; together they make any proportional
wallet reversal a number nobody could defend.

| # | property | why it blocks proportional handling |
|---|---|---|
| 1 | `order_refunds.refund_amount` is written by **assignment, not accumulation** | the column records the *last value typed*, not a running total. Two partial refunds leave the second amount only — the first is simply gone |
| 2 | **At most one `order_refunds` row per order**, enforced only in application code | there is no unique constraint, and no second row to hold a second partial. The data model permits duplicates that the code tries to prevent |
| 3 | per-line detail is **untyped shopper display text** inside a `VARCHAR(255)` JSON blob | each entry's `return_price` is a 32-character display *string*; only `quantity` is validated. A blob at 255 characters truncates silently |
| 4 | `refund_amount` carries **no currency and is never converted** | the store-currency sweep rewrites every money column on `orders` and never touches this one. Its unit is whatever the store's currency was at the moment it was typed |
| 5 | every money column here is single-precision **`FLOAT`**, not `DECIMAL` | ~7 significant digits, on a platform whose currency runs to millions. Proportional arithmetic compounds that error across lines |

Any one of these is enough. (1) and (2) together mean a second partial refund **cannot be
represented at all** — so "proportional handling" would be arithmetic over a number that does not
describe the refunds that happened.

---

## 2. The idempotency question, separately

Even for a *single* partial amount, the mutation could not be made idempotent against the current
data:

- **The transition guard is deliberately skipped when the target equals the current state.** For an
  online payment type the resolved target is always `Accept`, and the row already sits at `Accept`,
  so the guard never fires and `RefundAmonut` is **replayable without limit**. Each replay rewrites
  `refund_amount` (overwriting, not accumulating) and re-runs the order-total adjustment.
- **The restock idempotency marker is an `order_notes` row, not a column**, and the controller says
  so in its own source: a merchant who deletes that note can restock a second time. A durable column
  is named in-source as the fix and has never been made.
- **The refund door is outside the per-line marker protocol** the other three stock doors share — it
  references neither `Order::LINE_STOCK_RESTORED` nor `lineStockAlreadyRestored`.

So a proportional wallet reversal bolted onto this path would be replayable too, and the thing it
reversed would be a figure that the replay had already changed.

---

## 3. What is NOT blocked, and is implemented

The blockage is specific to **partial** amounts. A **full, completed** refund is representable and
is implemented in this branch:

- `store_order_financials.payment_status` already carries `'refunded'` in its production enum, and
  **nothing in the codebase could previously reach it** — the normalizer maps the spelling through,
  but no writer ever set `orders.payment_status` to it.
- `store_wallet_transactions.type` is `varchar(50)` with no enum and no CHECK, so a new reversal
  type needs no DDL.
- The reversal reuses the existing locked, status-guarded path, so it moves money at most once.

**And it fires only on the transition that actually asserts completion.** `'Refunded'` is terminal,
and it is reachable only for an **offline** payment type — `cod`, `cash_on_delivery`, `cash`,
`bank_transfer`, `whatsapp`, `telegram`, `pos` and the like. For every online gateway the service
resolves `'Accept'` (approved, not settled) and the `Accept → Refunded` edge is **unreachable by
construction**.

That is exactly the owner's rule — *"Do not call a gateway refund complete without provider
confirmation or independently evidenced manual settlement"* — and it holds today for a blunt reason
worth stating plainly: **no provider confirmation is ever sought on any path.**
`RefundIntegrityService::hasGatewayRefundDriver()` is a hardcoded `return false` for every payment
type, and the two gateway refund HTTP clients that exist in the tree have **zero callers**. No
refund request has ever left this server.

So the online path stays blocked not as a new restriction but because it was never settled in the
first place, and this change does not pretend otherwise.

---

## 4. The offline evidence gap, recorded not closed

An offline refund reaching `'Refunded'` means a merchant clicked a button asserting that cash went
back. That is "independently evidenced manual settlement" only if the evidence is recorded, and
today it is not:

- `order_refunds` has **no actor column**;
- neither `OrderRefundController` nor `RefundIntegrityService` writes an `AuditLog` or `ActivityLog`
  row;
- the restock `OrderNote` is mass-assigned with **no author**.

This branch closes the half it can without schema change: the wallet reversal it writes carries the
acting user on the `store_wallet_transactions` row, so *the financial event* has an actor even
though *the refund request* still does not. **Adding an actor column to `order_refunds` is an
unmade decision and is not made here.**

---

## 5. Two adjacent defects found while measuring, recorded not fixed

Neither is a partial-refund question; both would corrupt any future reconciliation and are recorded
so they are not discovered again from scratch.

1. **`orders.return_price` has two writers with incompatible semantics.** `Order::product_return()`
   writes the *cumulative* sum of returned lines; `order_return_request()` writes
   `return_price = final_price` **unconditionally**. The second poisons the first: `product_return`
   reconstructs the order's immutable base as `final_price + return_price` on the premise that
   `return_price` holds exactly what has been taken off, which stops being true the moment the other
   writer runs.
2. **`RefundAmonut` zeroes `orders.delivery_price` on a client-supplied boolean and does not
   recompute `final_price`.** It runs on every call, including the online non-settling path, so the
   order's total stops agreeing with its own components.

---

## 6. What would unblock partial refunds

Stated so the decision is costed rather than deferred vaguely. All four are needed; the first two are
schema changes.

1. **A refund *position*, not a scalar.** Either `order_refunds` gains a row per refund event with a
   `UNIQUE (order_id, idempotency_key)`, or a new `order_refund_events` table does. Today's
   one-row-per-order model cannot hold two partials.
2. **`DECIMAL` money columns and an explicit currency on the refund amount.** `FLOAT` plus an
   unconverted, unlabelled amount cannot be reconciled against `store_order_financials`, which is
   `DECIMAL(15,2)` with a currency.
3. **A durable restock marker**, replacing the deletable `order_notes` row, so the goods half is
   idempotent on the same event boundary as the money half.
4. **A decision on what a partial refund means for entitlement**: does the merchant's wallet fall by
   the refunded amount, or by the refunded amount net of the shipping and tax the merchant does not
   get back? That is a business rule, not an implementation detail, and the data cannot imply it.

Until those exist, a proportional reversal would be an approximation presented as a ledger entry —
which is the specific outcome the owner's instruction forbids.
