# F216 — a safe review-and-recovery path for a captured payment with no order

> **Status: DESIGN ONLY. Nothing here is implemented.** The owner's instruction, 2026-10-30:
> *"Do not release the Gateway Return Claim automatically. Design a safe review and recovery path for
> a collected payment without an order, with evidence that prevents a duplicate order and a duplicate
> capture, and do not change settlement rules without approval."*
>
> Two parts of this design need owner approval before any of it can be built, and they are named in
> §6 rather than left to be discovered. One part needs neither and is proposed as safe independent
> work in §7.

---

## 1. The situation this has to recover from

A shopper is redirected back from a gateway. `PaymentController::getProductStatus()` takes the
48-hour replay claim at `:2923`. Then, somewhere in the **fourteen** non-writing `return` exits
between that line and `DB::beginTransaction()` at `:3617`, the request leaves without writing an
order — an empty cart, a refused quantity, a foreign product, a missing product row (F216's own
fix), a user the guard does not recognise.

The money may already be gone. The claim is held for two days. **And `releaseGatewayReturnClaim()`
has exactly one call site in all of `app/`** — `NMIPayController:1526` — so nothing on this path
releases it. The shopper's retry and the merchant's retry are both refused, deterministically, for
48 hours, on a payment that may well have been captured.

The only existing record is a `payment_needs_attention` bell notification
(`StoreNotificationService::captureWithoutOrder()`), which the web bell and the Flutter app both
read. **There is no reconciliation command anywhere** — `app/Console/Commands/` holds eight commands
and none of them is about payments.

---

## 2. THE PRINCIPLE: the claim is a one-way door and must stay one

The tempting fix is to release the claim so the shopper can try again. **That is the wrong direction
and the code already says so.** `releaseGatewayReturnClaim()`'s own docblock calls itself *"not a
general undo"* and names the one legitimate reason it exists: NMI consumes the checkout state
*before* charging, so a declined card must not kill the checkout. Its sibling comment in
`GuardsGatewayReturn` is blunter — *"un-refusing it to keep two cache entries tidy would be the
wrong direction for a money control."*

Releasing a claim on a path where money **may have been taken** re-opens exactly the replay the
claim exists to refuse. A shopper who retries after a release can be charged twice, and the second
charge will look like a first arrival.

**So recovery does not go through the front door.** It goes through a deliberate, attributable,
single-use action that **writes the order directly**, leaving the claim held. The claim keeps doing
its job — refusing automated replays — while a human with evidence completes the one order it is
standing in front of.

That inversion is the whole design. Everything below is about what evidence that human needs and
what the system can actually supply.

---

## 3. The two pieces of evidence the owner requires, and what the schema can support

### 3.1 "No duplicate order" — THE IDENTIFIER DOES NOT EXIST TODAY

To prove no order exists for a gateway reference, something must link the two. Measured:

| | |
|---|---|
| `orders` | **no** gateway transaction-reference column. `payment_id`, `transaction_id`, `txn_id` — none of them. Only `plan_orders` has `txn_id`, and that is for plan purchases, not storefront orders. |
| `OrderCompleteBinding` | **session-based** (`session()->put`), so it answers *"did this browser place this order"* and is useless to a merchant reconciling someone else's abandoned checkout. |
| `paymeraOrderCommittedSince()` | a time-window heuristic over `orders`, not a reference match. It is good enough for its own narrow job and cannot answer this one. |

**So the duplicate-order evidence requires a durable reference on `orders`, which is a persistent
migration — an owner escalation under the standing rules, and §6 asks for it.** Without it, any
recovery action is guessing, and a design that guesses about whether an order already exists is how
you get the duplicate the owner asked to prevent.

### 3.2 "No duplicate capture" — ONLY ONE GATEWAY CAN ANSWER IT

To prove the money was taken exactly once, the gateway must be asked at recovery time — not read
from the stored state, which is what was already wrong when the order was lost.

`PaymentVerificationService` holds **three** provider verifiers. On the storefront return path that
reaches `getProductStatus()`, **only Khalti has one.** Easebuzz, PaiementPro, CinetPay and every
gateway reaching the generic handler have none, which is why
`gatewayReturnMissingProductRefusal()` classifies their capture state as `unknown` — *"the money may
already have been taken"* — rather than as confirmed or rejected.

**For most gateways the application cannot answer this question, and the design must say so instead
of implying a check it cannot perform.** The answer lives in the merchant's gateway dashboard, and
the recovery path has to make that a required, recorded human step.

---

## 4. The design

### 4.1 A review surface (read-only)

A merchant-facing list of **captures that produced no order**, assembled from what already exists:
the `payment_needs_attention` notifications, each carrying gateway, amount, capture state and cause.
Per row it shows the gateway, the amount, the capture state (`confirmed` / `unknown`), the cause
(`missing_product_row`, and the other refusal causes as they are instrumented), the time, and
**whether the 48-hour claim is still held**.

It changes nothing. It is the part that needs no approval — see §7.

### 4.2 A recovery action (writes, and gated)

Available only on a row the review surface shows, and gated on **all** of:

1. **Permission.** `Manage Order` in that store, answered through
   `MerchantPermissions::allowsAny($user, ['Manage Order'], $storeId)` — the store-scoped reader, not
   `isAbleTo()`, which is a union across every store (F209's lesson).
2. **A duplicate-order check against the durable reference** from §3.1. If the reference is absent
   because the migration has not been made, **the action must not exist** — not "proceed with a
   warning".
3. **An explicit capture attestation.** For a gateway with a verifier: re-verify live, and refuse if
   it does not return confirmed. For a gateway without one: the merchant must record the gateway's
   own transaction id and confirm, in a typed field, that they have seen it settled in the gateway
   dashboard. **That attestation is stored with the order**, so the decision is attributable to a
   person and a reference rather than to a button.
4. **Single use.** The notification row is marked recovered before the write, in the same
   transaction, so two operators cannot both act on it. This is the same consent discipline F205
   uses: the server issues and consumes, rather than trusting a flag.
5. **The claim is NOT released.** It expires on its own 48-hour schedule. The recovery writes the
   order *behind* it.

### 4.3 What the write itself must be

**Not a new order-writing code path.** The one thing this design must not do is add a second writer
alongside the seven D-13 already governs — that is how F208 happened. It must reuse the existing
writer with the stock, tax and total discipline already proven there, which means the recovery
action assembles the same `$requests_data` the handler would have had and calls the same path.

Whether that is feasible without refactoring is the open engineering question, and it is why this is
a design rather than a patch: the stored checkout state has a TTL and may be gone by the time a
merchant reviews the row, in which case **the order cannot be reconstructed and the honest recovery
is a refund, not an order.** Which leads to §5.

---

## 5. The case this design deliberately does not solve

**When the checkout state has expired, there is nothing to turn into an order.** The cart contents,
the prices, the shipping choice and the coupon are all in that state. Reconstructing them from a
notification that carries only a gateway and an amount would be inventing an order, and an invented
order is worse than an acknowledged refund: it ships goods at guessed prices and it corrupts the
very reconciliation it is meant to fix.

So in that case the correct outcome is **a refund to the shopper and no order** — and refunds are
settlement. `RefundIntegrityService:119-122` already refuses every collected payment because **no
gateway refund driver exists** (the same blocker E13 is stuck behind). **This design therefore does
not propose a refund path, and must not be read as providing one.** It is the owner's decision
whether recovery-by-refund is in scope, and it needs a refund driver first.

---

## 6. What needs owner approval before anything is built

1. **A durable gateway reference on `orders`** — a persistent migration. Without it §4.2's
   duplicate-order check cannot exist, and without that check the recovery action should not. This
   is the blocking item.
2. **Whether a merchant may complete an order on their own attestation** for a gateway with no
   verifier. That is a commercial-trust decision, not an engineering one: it lets a merchant create
   a paid order on the strength of their own statement that money arrived. The alternative is that
   such captures are only ever refunded, which needs §5's refund driver.

Neither is a change to settlement **rules** — no rate, no fee, no payout timing moves — but item 2
decides who may assert that money was received, which is close enough to settlement that the owner
should decide it rather than infer it from a design doc.

---

## 7. What can be built now, without approval, and what it is worth

**§4.1's review surface alone.** It is read-only: it lists existing notification rows with their
existing fields and adds a claim-still-held indicator. No migration, no new identifier, no write, no
settlement implication, and it needs none of §6.

What it buys: today a captured-payment-without-order reaches the merchant as **one bell notification
among all the others**, with no list, no filter and no way to see how many are outstanding or
whether the claim has expired. The merchant cannot act, but they currently cannot even **count**.
Turning an invisible condition into a visible queue is the prerequisite for every option above, and
it is the part that cannot be wrong in a way that costs money.

**Proposed, not started** — it is merchant UI on a payments surface, and the owner's standing rule is
not to widen a slice without asking. This document is the ask.

---

## 8. What is unchanged by all of this

The claim still blocks the legitimate retry for 48 hours. **That is the live residual and this design
does not remove it** — it routes around it for a reviewed case, and leaves the automated path
refused, which is the owner's instruction. Shortening the TTL is a separate question with its own
trade: a shorter window recovers faster and refuses replays for less time, and nothing in this
repository measures how long a real replay attempt takes to arrive.
