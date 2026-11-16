# Store Syria — consolidated financial-policy decision package

**For the owner. Prepared 2026-11-16 against `feature/phase-04-commerce` @ `50436028`.**
**Status: AWAITING DECISION. Nothing in this document has been implemented.**

This is the single package for **P4-F124, P4-F126, P4-F127, P4-F128, P4-F032 and P4-F046**. It
supersedes `P4-F124-LEDGER-SEMANTICS.md` as the decision document; that file remains as the
original measurement, and §8 lists where it has since gone stale.

---

## 0. How to read this, and what it is not

**It is a measurement and a set of options. It is not a change.** No code in this package has been
written. Six of the seven items below need your decision before any of them can be implemented.

**There is no production access from this session.** Every statement about *how many* rows are in
some state is therefore absent by design. Where a decision turns on such a number, the exact
read-only SQL is in §7 and the item says plainly that it is unanswered. **No number in this document
is estimated.**

**Everything else was measured from the code at `50436028`**, with `file:line` citations. Where the
findings register was wrong, §8 says so — that register has been wrong before, and correcting it is
part of the job.

**One item in here is not a policy question at all.** §6 is a live, pre-existing defect found while
measuring §1. It needs a yes/no on whether to fix it, not a policy.

---

## 1. Your six principles, assessed against shipped behaviour

| # | principle | verdict |
|---|---|---|
| 1 | Payment status reflects actual customer settlement | **In tension.** `orders.payment_status` currently does *two* jobs: it states settlement **and** it is the only trigger that reverses a merchant's wallet credit. Separating them is the core of §1. |
| 2 | Cancellation does not erase evidence of a captured payment | **Satisfiable with no DDL.** The reversal controls already exist; what is missing is a *reason* distinct from the *state*. |
| 3 | COD is paid only after verified collection | **Already decided and already implemented at the storefront** (decision D-06; COD resolves to `Pending`). **Not enforced on the two manual order doors** — that is §2. |
| 4 | Refunds and wallet reversals are separate, auditable, idempotent events | **Satisfiable, mostly without DDL.** §3. |
| 5 | Missing wallets must never silently lose credits | **Satisfiable with no migration.** §5. The harder half is whether already-lost credits are recoverable. |
| 6 | Payment callbacks require trusted destinations and server-side verification | **Satisfiable in first-party code**, with one provider-side unknown. §6a. |

---

## 2. §1 — P4-F124: cancellation ledger semantics

### What happens today, measured

`orders.payment_status` carries two meanings at once. `OrderObserver::updated()` returns early
unless `wasChanged('payment_status')`, so writing `'Unpaid'` on cancel is *the mechanism* that makes
`OrderFinancialService` reverse the wallet credit. The cancel arm's own inherited comment calls it
"the missing magic line … so the Observer detects the change and calls the wallet service".

**Seven doors cancel an order. Four write `'Unpaid'`; three do not.**

| door | writes `Unpaid`? |
|---|---|
| merchant web `order-status-change` | yes |
| storefront customer `status-cancel` | yes |
| storefront API `order-status-change` | yes |
| admin API `status-change` | yes |
| Flutter admin `orders/update-status` | **no** — see §6 |
| Delivery Syria webhook | **no** — `payment_status` appears nowhere in the file |
| driver / storefront `order-cancel` | **no** — writes `delivered_status = 2` only |

### The decision

Principles 1 and 2 both say the *state* and the *reason* must stop being the same field. The
question is where the reason lives.

**Option A — a reversal reason on the financial record (recommended).** Write the reason to
`store_order_financials` / `store_wallet_transactions` and stop overloading `payment_status`.

**Option B — a new `payment_status` value** (e.g. `Cancelled`, `Refunded`).

### Implications — and the asymmetry that should drive the choice

**No DDL is needed for the reversal repair itself**, and that is the most useful fact here. Three
controls already exist in production:

- `store_order_financials` has `UNIQUE KEY uq_store_order_financial_order (order_id)` — one
  financial row per order is an *enforced* guarantee
- `markFinancialAsPaid()` / `markFinancialAsUnpaid()` re-read under `lockForUpdate()` inside
  `DB::transaction` and return early unless the status warrants the move — already idempotent
- the financial row's enum **already carries `'cancelled'` and `'refunded'`**, and no code writes
  either

**Option B is the expensive one, and the cost is entirely client-side.** A new `payment_status`
value would have to be agreed by three server validators (`in:Paid,Unpaid` in two places in
`DashboardController`, one in `OrderController`), one form request, one model accessor, **and the
shipped app's boolean collapse** (`== 'paid'`). Shipping the server first strands the app.

> **Decision needed:** A or B. If A, confirm the reversal reason belongs on the financial record and
> that `payment_status` keeps exactly its two current values.

**Optional, if you want database-level de-duplication of the ledger:** one additive migration adding
`idempotency_key VARCHAR(191) NULL` plus a unique index on `store_wallet_transactions`. Not required
for correctness today — the lock plus status guards already prevent double movement.

---

## 3. §2 — P4-F126: a merchant may assert `Paid` on a COD order at creation

### Measured

Both manual order doors (`OrderController::store`, `DashboardController::createOrderViaApi`) accept
`payment_type` as a free string and `payment_status` as `Paid|Unpaid`, write both verbatim, and
**neither calls `resolveOrderPaymentStatus()`**. `OrderObserver::created()` then credits the wallet
at creation. This is the literal condition decision D-06 forbids — enforced at the storefront, not
on these two doors.

### The decision

> **Decision needed:** should a merchant be able to assert `Paid` at creation, and if so, for what?
> A counter sale is a legitimate case; a COD parcel that has not left is not. **The data cannot
> currently tell them apart** — both are `payment_type = 'cod'`.

**Option A — refuse `cod` + `Paid` at creation.** Pure validation in two places. **Zero schema
change, zero migration, zero backfill.**

**Option B — keep it, but make the counter sale *sayable*** — a distinct `payment_type` for an
over-the-counter sale. Also no DDL (`payment_type` is `VARCHAR(255)`); it is vocabulary and
configuration, not schema.

### Implications

**Historical rows are untouched under either option**, following the precedent already set in this
codebase for the withdrawn COD trigger: *"COD orders already marked Paid … keep that status. Nothing
is reversed or back-filled."* Reclassifying them would mean rewriting live `payment_status`, which
the observer would turn into real wallet movements.

**One test file is affected, and it is one I wrote.**
`tests/Feature/Commerce/OrderStoreOwnershipTest.php` uses `cod` + `Paid` as its *default fixture*
(15 of its 17 tests). Those tests are about tenancy and stock, not payment; under Option A the
fixture changes, not the assertions.

---

## 4. §3 — P4-F127: a refund moves no money record

### Measured

`OrderRefundController.php` is 1218 lines and contains **zero** occurrences of `payment_status`,
`StoreWallet` or `OrderFinancialService`. A fully refunded order keeps `payment_status = 'Paid'`,
keeps its financial row at `paid`, and keeps the merchant's wallet credit. `order_refunds.refund_status`
is the only record that money came back.

### Implications — three things need no DDL

- **a new `store_wallet_transactions.type` value** (e.g. `order_refunded`): `type` is
  `varchar(50)` with no enum and no CHECK, and seven distinct values are already in use. Zero DDL,
  and no reader can reject an unknown value.
- **`store_order_financials.payment_status = 'refunded'`**: already in the production enum, so the
  write cannot fail.
- the reversal itself, which reuses the existing locked, guarded path.

**The one real risk is a UI control, not the data:** `StoreFinanceController` validates the finance
status control as `in:paid,unpaid`, so a row sitting at `'refunded'` needs that screen updated in
the same change or a merchant cannot act on it.

> **Decision needed:** should a refund produce (a) a wallet reversal, (b) a financial-row status
> change, (c) both? And should a *partial* refund reverse proportionally or not at all?

---

## 5. §4 — P4-F128: the wallet can be moved without the order

### Measured

`StoreFinanceController::updateFinancialPaymentStatus()` acts on a `store_order_financials` id and
**never reads or writes `orders.payment_status`**. There are **two** producers of divergence, not the
one the register records. No reconciliation exists anywhere between the two records.

### The decision

> **Decision needed:** should `orders.payment_status` and `store_order_financials.payment_status` be
> *coupled* (one cannot move without the other) or *deliberately independent* (the finance ledger is
> the merchant's book, the order is the customer's statement)?

### Implications

**Coupling costs zero schema change**, and both write orderings were traced and are safe — neither
moves money twice, because the observer's re-read finds the financial row already in the target
state and falls through to a status-only update.

**What changes is what merchants see**: marking a financial paid on `/finance` would flip the order
to "Paid" on the order list, the kanban board, the order detail page and the Flutter app. No API
shape changes, no new 401, no WorkDo surface touched.

---

## 6. §5 — P4-F032: a wallet-less store loses its credit permanently

### Measured

`OrderFinancialService::createForOrder()` opens with `StoreWallet::...->lockForUpdate()->firstOrFail()`.
`OrderObserver::created()` catches `\Exception`. The order commits, no financial record is written,
and the credit is lost — nothing revisits it. (This phase made the loss *audible*: a `critical` log
line now names the order, store, amount and payment status. It does not recover anything.)

**Two writers create a store without a wallet**: `RegisteredUserController::store` and
`DashboardController::createStoreApi`.

### Implications — and one fact that should reassure you

**Forward-only provisioning needs no migration.** `store_wallets` already carries
`UNIQUE KEY uq_store_wallet_store_id (store_id)`, so `firstOrCreate` is race-safe at the database
level. The change is to the two writers, plus optionally a defensive `firstOrCreate` in the service.

**The mid-stream transition you might worry about already happens today**, non-deterministically: a
wallet-less merchant's wallet is created the first time they open `/finance` or the Flutter wallet
tab. Provisioning at store creation makes an existing uncontrolled transition deterministic rather
than introducing a new one.

### The question that actually matters

> **Are the already-lost credits recoverable?** This cannot be answered without a production read.
> Queries Q-F032-1 and Q-F032-3 in §7 settle it: if `orders` and `store_order_financials` together
> still hold enough to reconstruct what was dropped, a backfill is possible; if not, those credits
> are gone and the decision is only about stopping future loss.

---

## 7. §6a — P4-F046: the Benefit callback destination and absent capture verification

### Measured

`PaymentService::processBenefit()` sets the Tap charge's `post.url` to a **hardcoded public
`webhook.site` bin**, committed in this repository and in its history. The storefront return has
**no `benefit` branch at all**, so a storefront Benefit order is written with no capture
verification whatsoever. Three further copies of the bin live in vendored `packages/workdo/` code,
which must not be edited. (The dead `webhook_backup()` helper, which POSTed whole orders to a second
bin, was deleted in this phase and had no callers.)

### Implications

**The cheapest correct option needs no schema change**, and there is a precedent in the same file:
the sibling arm on the same provider already ships `"post": {"url": ""}`.

**`order_gateway_references` already exists** (migration `2026_10_30_000100`) and is the right shape
for a durable, idempotent first-party callback, should you want one. It has zero production writers
today; its own docblock states backfill is impossible, and that premise was confirmed — no row
anywhere holds a Tap charge id for a past storefront order.

**One provider-side unknown, stated rather than guessed:** whether Tap validates `post.url` at
charge creation cannot be determined from this repository. If it does, a rejected URL makes the
charge POST fail — and `processBenefit`'s catch converts **any** `Throwable` into
*"Currency Not Supported.Contact To Your Site Admin"*, so a bad destination would present to the
shopper as a misleading currency error with no diagnostic. **Whichever option you choose, that catch
should be narrowed in the same change.**

> **Decision needed:** (a) empty `post.url`, (b) point it at a first-party route, or (c) leave it and
> accept the exposure. And separately: should the storefront return gain a `benefit` capture check?

---

## 8. §6b — NOT A POLICY QUESTION: a live defect found while measuring §1

**A merchant cannot change the delivery status of a COD order from the shipped Flutter app.**

Verified end to end, four links:

| link | evidence |
|---|---|
| the app always sends `payment_status` | `order_repository.dart` — `required String paymentStatus`, unconditional in the body |
| the status sheet echoes the order's **current** value | `payment_status_bottom_sheet.dart:692` — `paymentStatus: getPaymentStatus(model)` |
| COD resolves to `Pending` | `PaymentVerificationService` — owner decision D-06 |
| `updateStatus` validates `in:Paid,Unpaid` | `DashboardController` — a `Pending` echo is **HTTP 422** |

**This is pre-existing, not a Phase 04 regression**: the validator was present at the Phase 2.5 merge
(`4e878b8d`, 2026-11-12).

It belongs in this package because **any repair arising from §1 needs merchants able to record a
payment correction**, and today they cannot on the orders most affected. A second, related guard
refuses a status change on an already-cancelled order, which is exactly the operation a correction
would require.

> **Decision needed:** fix now as a bounded defect, or schedule it with the §1 implementation?

---

## 9. The production queries

**All read-only. None modifies anything. Run on a replica.** Forty-six queries were produced across
the six measurements; these are the ones that gate a decision. The full set is in the per-finding
measurements.

```sql
-- The actual vocabulary of orders.payment_status. Settles P4-F129 as a side effect:
-- if 'succeeded' returns zero rows, PAYMENT-STATUS-BACKFILL-ASSESSMENT.md's premise is void.
SELECT payment_status, COUNT(*) FROM orders GROUP BY 1;

-- How much of what the dashboard calls revenue is unsettled.
SELECT payment_status, delivered_status, COUNT(*), SUM(final_price) FROM orders GROUP BY 1, 2;

-- P4-F032: how many stores have no wallet, and how many orders they carry.
SELECT COUNT(*) FROM stores s LEFT JOIN store_wallets w ON w.store_id = s.id WHERE w.id IS NULL;

-- P4-F032: orders with no financial row — the population whose credit was dropped.
SELECT COUNT(*), SUM(o.final_price) FROM orders o
LEFT JOIN store_order_financials f ON f.order_id = o.id WHERE f.id IS NULL;

-- P4-F128: live divergence between the two records.
SELECT o.payment_status, f.payment_status, COUNT(*) FROM orders o
JOIN store_order_financials f ON f.order_id = o.id
WHERE LOWER(o.payment_status) <> LOWER(f.payment_status) GROUP BY 1, 2;

-- P4-F126: COD orders asserted Paid.
SELECT COUNT(*), SUM(final_price) FROM orders
WHERE LOWER(payment_type) = 'cod' AND payment_status = 'Paid';

-- P4-F127: refunded orders still carrying a wallet credit.
SELECT COUNT(*) FROM order_refunds r JOIN orders o ON o.id = r.order_id
WHERE o.payment_status = 'Paid';
```

---

## 10. Corrections to the existing record

- **`P4-F124-LEDGER-SEMANTICS.md` is stale in its citations.** It was written at `4f804baf`;
  `Order.php`, `OrderObserver.php` and `DashboardController.php` have all changed since.
- **Its §7.4 says "six doors"; its own §1 table lists seven.** Seven is right: 4 write, 3 do not.
- **The register says the Flutter admin door leaves `payment_status` untouched.** The handler *does*
  assign it; the outcome matches only because the app echoes the stored value. The mechanism
  recorded is wrong, and the test pinning it sends no `payment_status` at all — so it pins a request
  shape no shipped client produces.
- **A same-family gap is not in the register:** `OrderObserver::updated()`'s catch is
  `Log::error` with no order id, store id or amount — below the two levels the observability census
  tracks. It is `P4-F032`'s twin on the *reversal* leg, and the order save it guards is in no
  transaction.

---

## 11. What this package deliberately does not do

- It does not implement anything.
- It does not reclassify, backfill or migrate any historical row.
- It does not touch `packages/workdo/`.
- It does not resolve P4-F134, P4-F135 or P4-F138, which keep their recorded dispositions.
- It does not assert any production figure.
- It does not treat the human security checklist as anything other than outstanding.
