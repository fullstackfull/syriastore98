# P4-F128 — settlement and entitlement: distinct, and now reconciled

**Owner decision:** *keep settlement and entitlement distinct, add reconciliation, finance-screen
adjustment needs authz + reason + auditable event.*

Those clauses are not in tension, and reading them as if they were is how this gets built wrong.
**Settlement** is what the CUSTOMER paid (`orders.payment_status`). **Entitlement** is what the
MERCHANT is owed (`store_order_financials`, `store_wallets`). They are deliberately independent
records. Nothing added here makes one follow the other. What is added is a check that **where they
are supposed to agree, they do**, and an account of who moved one of them and why.

---

## 1. The finding, confirmed — and it is worse than it was filed as

Filed: *"`StoreFinanceController::updateFinancialPaymentStatus()` moves the wallet without touching
the order, so the two can diverge permanently with no reconciliation."*

Measured, all of it true:

- Every `DB::table()` write in `OrderFinancialService` targets `store_order_financials`. There is no
  write to `orders` and no `Order::` write anywhere in that file.
- The only sync is **one-way** (order → financial), through `OrderObserver::updated`, and it returns
  early unless `wasChanged('payment_status')`.
- No query anywhere in `app/` joins `orders` to `store_order_financials`, and
  `app/Console/Commands/` holds no reconciliation command.
- The finance screen renders the financial row only. Its query does not join `orders`. **So the one
  screen that creates the divergence is the one screen that cannot show it.**

**What the filing did not say, and is the worse half:** the divergence is later resolved in the
**wrong direction**. If the order subsequently moves to any non-paid status,
`OrderFinancialService::syncFromOrder()` takes its `$orderStatus !== 'paid' && $financial->
payment_status === 'paid'` arm and **DEBITS the wallet** — silently reversing a deliberate
finance-screen credit as a side effect of an unrelated edit.

**Three divergence generators**, measured in the code: the finance dropdown; that `syncFromOrder`
arm; and the observer's `wasChanged` early return, which syncs nothing when an order reaches a new
state by a path that does not change that column.

### 1.1 The one surface already named "reconciliation" does not reconcile this, or anything, in production

`StoreFinanceController::unreconciledCaptures()` reconciles gateway captures against **orders**, not
wallet against order. It reads `order_gateway_references`, whose migration is **dev/test-only** and
which has **zero writers in `app/`** — `GatewayReference::claim/claimState/bind` are called only from
tests. In production it returns an empty list with `record_available = false`.

Recorded because the name is misleading in the dangerous direction: a reader could reasonably
conclude the reconciliation question was already handled.

---

## 2. What was built

### 2.1 A read-only reporter on the settlement↔entitlement axis

`Tests\Support\Security\SettlementEntitlementReconciliation`, a sibling of `GateFReconciliation`,
driven by `tests/Support/Security/bin/report-settlement-entitlement.php` against a **restored copy**.

**It resolves nothing by construction.** No `INSERT`, `UPDATE` or `DELETE`, and
`SettlementEntitlementReconciliationTest::test_the_reporter_performs_no_write` asserts that every
statement it issues is a `SELECT` rather than trusting a reading of the file.

| leg | code | what it asserts |
|---|---|---|
| 1 | `D4` | the financial row names its order's store, and a wallet of that same store |
| 2 | `D1` | a non-terminal entitlement state equals the **normalised** settlement |
| 3 | `D2` | the order's wallet-ledger net equals the entitlement the financial row claims |
| 3b | `D3` | the financial row agrees with itself on its own amounts |
| 4 | `D5` | the wallet balance is reconstructible from the ledger rows that **moved** it |

**Three design decisions that are the whole value:**

1. **Leg 2 exempts `cancelled` and `refunded`.** That is P4-F124 made executable: a cancelled order
   deliberately KEEPS the customer's settlement. Reporting it would train the reader to ignore the
   report.
2. **Leg 3 reports in three buckets, never one number.** `not_comparable` (more than one currency, or
   the financial row and its wallet disagree on currency) is a *conversion*, not a discrepancy.
   `control_failure` (a transaction on another wallet) is more serious than an amount that
   disagrees. Merging them would produce exactly the kind of figure this project refuses to publish.
3. **Leg 4 CORRECTS an existing check rather than re-deriving it.** `GateFReconciliation` R1 and the
   F032 procedure's Q-F032-5 both filter on `t.status` and both include `withdrawal_paid` rows. Two
   measured reasons that yields structural false positives: the withdrawal lifecycle **double-counts**
   (the balance is reserved at REQUEST time, so the later `withdrawal_paid` row carries
   `balance_after == balance_before` by design — the handler says so in an inline comment), and a
   currency change rewrites balances with **no ledger row at all**. Leg 4 restricts to rows that
   actually moved the balance and drops the status filter.

The SQL normalisation reimplements `OrderFinancialService::normalizePaymentStatus()`. The two are
held together by a test that drives every arm through both, so they cannot drift.

### 2.2 Reason and auditable event on the finance adjustment

`updateFinancialPaymentStatus` now **requires** a reason and writes an `AuditLog` row.

- **Required, not optional.** This is the surface that creates the divergence. Whether a given
  adjustment is right (cash arrived outside the gateway) or a mistake is a fact only the person
  clicking holds, and it is unrecoverable five minutes later. An optional field is left blank
  precisely when it matters.
- **In `AuditLog`, not a new column.** `store_order_financials` has no reason and no actor column,
  and adding one is a GATE F schema change — a separate owner decision, and unnecessary, since
  `AuditLog` already carries actor, store, subject, before/after, IP and user agent.
- **After the try/catch.** A failed adjustment moved no money; recording one as though it had would
  make the audit trail lie in the direction that matters most. Pinned by
  `test_a_failed_adjustment_writes_no_audit_row`.
- **It records `settlement_unchanged`** — the order's own status, which this handler does not
  touch — so the divergence the adjustment creates is visible **from the audit trail alone, at the
  moment it is created**, without waiting for the report to find it.

That read is **scoped by `store_id`**. The first draft read the order by id alone, reasoning that the
financial row had already been fetched for this store. `RawQueryTenancyInventoryTest` flagged it, and
the reasoning was wrong in exactly the way leg 1 exists to doubt: if a financial row names another
store's order (`D4`), the unscoped read would have copied another tenant's settlement into this
tenant's audit log.

### 2.3 The divergence is pinned where it was already being demonstrated

`FinancialSurfaceCoverageTest::test_marking_a_financial_record_paid_moves_the_money` seeds an order
`Unpaid`, marks the financial row paid, asserts the wallet moved — and asserted nothing about the
order. It demonstrated F128 and did not look. It now asserts the order is **unchanged**, so that a
later change making the service write `orders.payment_status` reads as a decision rather than an
oversight.

---

## 3. What was deliberately NOT done

- **Nothing writes `orders.payment_status` to make the two agree.** Which side is wrong is a question
  about what really happened to money, and the answer is a fact the business holds.
- **No schema change.** No reason column, no actor column, no migration.
- **`syncFromOrder`'s wallet-debiting arm is NOT changed.** It is a real defect — an unrelated order
  edit silently reverses a deliberate credit — but changing it changes money movement on a live path,
  which is an owner decision, not a reporting one. **It is reported by leg 2 instead.** Recorded here
  rather than fixed quietly.

---

## 4. Still open, and named

**Four surfaces move or release money with no reason the caller can supply.** All four authorize
operator-first and run in a transaction with `lockForUpdate()`; none writes an `AuditLog` row:

| surface | why no reason is possible |
|---|---|
| `updateFinancialPaymentStatus` | **CLOSED by this change** |
| `approveWithdrawal($id)` | takes no `Request`, so none can be submitted; `store_withdrawals.admin_note` exists and is left untouched. **Writes no new ledger row at all.** |
| `payWithdrawal($id)` | takes no `Request`. This is the handler that actually pays money out. |
| `rejectWithdrawal` | `admin_note` is `nullable`, so money returns to the wallet with nothing recorded about why |

**A spelling drift worth naming**, measured across three sites: the `finance` gate compares
`$user->type === 'super admin'` with no normalisation; `UserPolicy::isOperator` uses
`strtolower(rtrim(...))`; and `addBalanceApi`/`deductBalanceApi` use a third, raw
`$adminUser->type !== 'super admin'`. Three spellings of one question.

**The three API wallet routes** (`wallet/add-balance`, `wallet/deduct-balance`, `wallet/transactions`)
carry no `finance.permission` middleware, unlike all eight web finance routes — the check exists only
inside the controller. No duplicate route declaration was found, so no middleware is being silently
dropped. Also: `walletTransactionsApi`, a **GET**, lazily creates a wallet row.

---

## 5. How to run it

```bash
php tests/Support/Security/bin/report-settlement-entitlement.php <database> \
    --host=127.0.0.1 --user=root --pass=secret
```

Exit `0` reconciles, `1` divergences present, `2` the database could not be read. **Against a
restored copy, never against production.**

**It has never been run against real data.** No production access exists from this container, so
every number it would produce is unknown. What is established is that the logic bites in both
directions on synthetic fixtures — each leg shown to redden when neutered, and a consistent store
shown to report nothing.
