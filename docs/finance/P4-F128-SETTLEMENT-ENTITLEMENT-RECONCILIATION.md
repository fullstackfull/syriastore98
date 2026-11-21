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

---

## 6. What independent review found, after this was built

The `tenant-authz-reviewer` pass read the handler, the model, the view, both route files, the
service, the helper, the reporter and the three test files. **Its headline was that the tenancy is
correct and there is no cross-tenant read or write.** Both defects it found were in the *audit
trail* — the property this change exists to add.

### 6.1 Fixed: a merchant could move money and guarantee no audit row (confirmed by execution)

The `reason` went straight into a `json`-cast column outside the try/catch. A reason carrying a
**malformed UTF-8 byte sequence** passes `string` (`is_string`) and `max` (`mb_strlen`), reaches the
audit write, and `json_encode()` returns `false` — so Eloquent throws `JsonEncodingException`. By
then `markFinancialAsPaid` has **committed inside its own transaction**, and this handler opens no
outer one. Result: **money moved, no audit row, HTTP 500** — and the beneficiary is the principal the
slice exists to hold to account. This route carries no `xss` middleware, and Laravel's `TrimStrings`
falls back to plain `trim()` when its `/u` regex fails, so nothing upstream normalises the bytes.

The review rated this *probable* from reading. **It was driven and confirmed**: `reason="bad \xC3\x28
bytes"` produced exactly that exception.

Fixed in the **validator, before any money moves** — explicitly *not* by catching around the audit
write, since a silently dropped audit row is the same evasion with no 500 to notice it by.

### 6.2 Fixed: the audit row recorded the state that was *requested*, not the one that resulted

`markFinancialAsUnpaid` writes **nothing** unless the row is exactly `'paid'`. So an adjustment to
`unpaid` on a `cancelled`, `refunded`, `pending` or `partially_paid` row is a complete no-op — and
the audit row asserted the entitlement had moved.

**This is ordinary use, not a crafted case.** P4-F124 writes `cancelled` on every cancellation, and
the screen's select offers only `unpaid`/`paid`, so on a cancelled row **neither option carries
`selected`** and the browser submits the first — `unpaid`. A merchant clicking *Update* on a
cancelled row would have signed a reasoned audit entry describing a movement that never happened.

Both values are now recorded: `requested_state` and the re-read `entitlement_state`.

### 6.3 Fixed: the one tenancy-relevant line in this change was unpinned

The review found that `test_..._records_the_settlement_this_adjustment_did_not_change` passes
**whether or not** `->where('store_id', $storeId)` is present, because its fixture has one store, so
the scoped and unscoped reads return the same row. A test passing for the wrong reason, protecting
the exact line added in response to the tenancy inventory.

`test_the_audit_read_cannot_reach_another_tenants_order` now builds the **D4 shape** — store A's
financial row naming store B's order, with different settlements — which is the only state in which
the two reads differ. Stripping the predicate now reddens it.

### 6.4 Fixed: leg 1 could never report an orphan, and the honest limits of the fix

The review found leg 1's `JOIN orders` was an **INNER** join, so a financial row whose `order_id`
matches no order was silently dropped — the most complete form of the failure that leg looks for.
Now a `LEFT JOIN` with an `o.id IS NULL` limb.

**Two things measured while fixing it, both of which bound the claim:**

1. **This schema cannot reach that state.** Deleting an order **cascades** and takes the financial
   row with it. The limb is **defensive**, against a restored copy that lost referential integrity —
   which is exactly the kind of database this reporter is pointed at.
2. **The suite cannot construct it either.** SQLite's `PRAGMA foreign_keys` is a **no-op inside a
   transaction**, and `RefreshDatabase` wraps every test in one; switching enforcement off in a test
   does nothing, measured — the cascade still fired.

A conditionally-skipped test would break the declared outcome set, so the evidence is an executable
script: `tests/Support/Security/bin/verify-orphaned-entitlement-is-reported.php`. It asserts both
premises, then builds the orphan the way a lossy restore would. **Falsified: with the INNER JOIN
restored it reports `FAIL — codes present: none`.**

### 6.5 Fixed: a refused adjustment was a silent reload

No `@error('reason')` block existed, so a server-side refusal was a 302 back with nothing shown. The
browser's `required` catches the ordinary case; the encoding rule and anyone who defeats the
attribute did not. Now displayed with `role="alert"`.

### 6.6 Recorded, not acted on

| finding | why it is left |
|---|---|
| **`markFinancialAsPaid` has no terminal-state guard**, so selecting `paid` on a `cancelled`/`refunded` row credits the wallet and overwrites the F124 reversal | `syncFromOrder`'s own docblock says this is deliberate — the finance surface is the authorised escape hatch. **This change improves it**: the prior `cancelled` state and a mandatory reason now land in the audit row. |
| **`audit_logs` has no reader anywhere in the product.** Zero matches for `AuditLog::(where\|all\|query\|find)` outside the model | The "auditable event" half is satisfied at the storage layer only; reading the trail needs database access. A reader is a new screen and a new permission — beyond this change. *Upside:* nothing renders `new_values`, so the caller-supplied reason is not a stored-XSS vector despite the absent `xss` middleware. |
| **The view gate is a bare `isAbleTo`**, which refuses the platform operator, so an ungranted operator sees the read-only badge and never the new input — while able to drive the endpoint by hand | Pre-existing, **fails closed**, and the right spelling. It matters only because the new tests `post()` directly and never render the page, so the suite would not notice. |
| **`AuditLog::record()` resolves `getCurrentStore()` a second time** | Measured not to differ on this path: `auth()->user()` is memoised for the request and nothing between the two reads writes `current_store`. Passing `$storeId` explicitly would be stronger but reaches every call site. |
| **The wrapper accepts `--pass=`**, so a restored-copy password lands in the process list and shell history | Operational note; the sibling `report-gatef-reconciliation.php` has the same shape. |

### 6.7 One coverage statement the review made, kept rather than papered over

**Every case in `FinanceAdjustmentAccountabilityTest` drives a `super admin`.** The *delegated*
`isAbleTo('Edit Finance Payment Status')` principal — which CLAUDE.md names as the only finance
principal that can resolve no store — is never exercised. Stated here rather than implied by the
word "passed".
