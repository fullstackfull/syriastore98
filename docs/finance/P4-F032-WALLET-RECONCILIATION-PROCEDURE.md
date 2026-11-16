# P4-F032 — read-only reconciliation of lost wallet credits

**STATUS: PROCEDURE ONLY. NOTHING HERE HAS BEEN RUN, AND NOTHING HERE WRITES.**

This document exists because the forward fix and the historical question are two different things,
and only the first one has been done.

- **Forward (done, in this branch):** `App\Services\StoreWalletProvisioning` is now the single
  creator of a `store_wallets` row, and the two store-creation doors that forgot — plus the
  defensive path inside `OrderFinancialService::createForOrder()` — all call it. From now on an
  order cannot be lost to a missing wallet.
- **Historical (this document):** orders taken *before* that fix, in stores that had no wallet, have
  no `store_order_financials` row and never received their credit. **Provisioning a wallet today
  does not reach back.** How many such orders exist, and whether the lost credits can be
  reconstructed, cannot be answered from this repository.

Owner instruction, verbatim: *"Do not backfill or credit existing production orders automatically.
Prepare a READ-ONLY reconciliation procedure for historical missing credits, including the required
production-replica queries. Historical recovery remains separately approval-gated."*

---

## 1. Preconditions, stated rather than assumed

1. **Run against a read-only replica or an isolated restore, never against the live primary.**
   Every statement below is a `SELECT`. There is no `UPDATE`, `INSERT` or `DELETE` in this document
   by design, and none should be added to it — a recovery script, if one is ever authorised, is a
   separate artefact with its own review.
2. **This session has no production access.** No query here has been executed. Every row count
   below is written as `?` and must stay that way until somebody with access fills it in.
3. The queries assume the GATE F schema as measured in `docs/GATE-F-VERIFIED-SCHEMA.md`:
   `store_wallets` with `uq_store_wallet_store_id (store_id)`, and `store_order_financials` with
   `uq_store_order_financial_order (order_id)`.

---

## 2. The queries

### Q-F032-1 — how many stores have no wallet at all?

The population at risk. A store in this list has lost the credit for **every** order it has ever
taken.

```sql
SELECT  s.id   AS store_id,
        s.name AS store_name,
        s.created_by,
        s.created_at
FROM    stores s
LEFT JOIN store_wallets w ON w.store_id = s.id
WHERE   w.id IS NULL
ORDER BY s.created_at;
```

> Result: **?** stores.

### Q-F032-2 — how many orders sit behind those stores, and what are they worth?

The size of the hole. `final_price` is the customer total; it is what `createForOrder()` would have
written as `customer_total` and, for a paid order, credited to the wallet.

```sql
SELECT  o.store_id,
        COUNT(*)                                             AS orders,
        SUM(CASE WHEN o.payment_status = 'Paid' THEN 1 ELSE 0 END)            AS paid_orders,
        SUM(CASE WHEN o.payment_status = 'Paid' THEN o.final_price ELSE 0 END) AS paid_value,
        MIN(o.created_at)                                    AS first_order,
        MAX(o.created_at)                                    AS last_order
FROM    orders o
LEFT JOIN store_wallets w ON w.store_id = o.store_id
WHERE   w.id IS NULL
GROUP BY o.store_id
ORDER BY paid_value DESC;
```

> Result: **?** orders across **?** stores, **?** of them paid, totalling **?**.

### Q-F032-3 — the decisive one: orders with no financial row

This is wider than Q-F032-2 and is the query that actually answers *"is recovery possible?"*. An
order with no `store_order_financials` row lost its posting — whether or not its store has a wallet
now, because a wallet created later (by `/finance`, by the Flutter wallet tab, or by this fix) does
not backfill.

```sql
SELECT  o.id                AS order_id,
        o.product_order_id,
        o.store_id,
        o.payment_status,
        o.payment_type,
        o.final_price,
        o.product_price,
        o.coupon_price,
        o.tax_price,
        o.delivery_price,
        o.delivered_status,
        o.created_at,
        (w.id IS NOT NULL)  AS store_has_wallet_now
FROM    orders o
LEFT JOIN store_order_financials f ON f.order_id = o.id
LEFT JOIN store_wallets         w ON w.store_id  = o.store_id
WHERE   f.id IS NULL
ORDER BY o.store_id, o.created_at;
```

> Result: **?** orders.

**How to read it.** Every column this query selects is a column `createForOrder()` reads. If these
rows are present and populated, the lost postings are **reconstructible** — the inputs still exist.
If `orders` rows for the affected period are themselves gone, they are not, and the decision reduces
to stopping future loss, which the forward fix already does.

**The one input that is NOT reconstructible from `orders`** is `product_cost` / `gross_profit`:
`calculateProductCost()` reads `products.cost_price` **as it is now**, not as it was at order time.
A reconstruction would therefore produce a correct `customer_total` and a *today's-cost* profit
figure. That difference must be stated in any recovery, not papered over.

### Q-F032-4 — control: does the reverse case exist?

A financial row whose store has no wallet would mean the hole is not the shape described here.
Expected to be empty; if it is not, stop and re-describe the defect before doing anything else.

```sql
SELECT  f.id, f.order_id, f.store_id, f.wallet_id, f.payment_status, f.customer_total
FROM    store_order_financials f
LEFT JOIN store_wallets w ON w.id = f.wallet_id
WHERE   w.id IS NULL;
```

> Result: **?** rows. **Expected: 0.**

### Q-F032-5 — control: wallet balance vs the sum of its own transactions

Not strictly part of F032, but it is the cheapest available check on whether any *other* mechanism
has moved a balance without a transaction row, which would change how much a reconstruction can be
trusted.

```sql
SELECT  w.store_id,
        w.available_balance,
        COALESCE(SUM(CASE WHEN t.direction = 'credit' THEN t.amount
                          WHEN t.direction = 'debit'  THEN -t.amount
                          ELSE 0 END), 0) AS sum_of_transactions,
        w.available_balance
          - COALESCE(SUM(CASE WHEN t.direction = 'credit' THEN t.amount
                              WHEN t.direction = 'debit'  THEN -t.amount
                              ELSE 0 END), 0) AS drift
FROM    store_wallets w
LEFT JOIN store_wallet_transactions t
       ON t.wallet_id = w.id AND t.status = 'completed'
GROUP BY w.id, w.store_id, w.available_balance
HAVING  drift <> 0;
```

> Result: **?** wallets with drift.

**A non-zero drift is not automatically a defect** — withdrawals and manual finance adjustments
move `available_balance` too, and whether each writes a transaction row has not been verified here.
Treat this as a question generator, not a verdict.

---

## 3. What this procedure deliberately does not do

- **It proposes no backfill.** Writing the missing `store_order_financials` rows and crediting the
  wallets is a real financial operation on live merchant balances. It needs its own authorisation,
  its own script, its own review, and a decision on the `product_cost` caveat above.
- **It does not reclassify any order.** No `payment_status` is read as anything other than what it
  says.
- **It does not assume the hole is non-empty.** It is entirely possible that every affected store
  had its wallet created lazily by `/finance` before taking an order, in which case Q-F032-3 returns
  few rows or none. Nobody knows yet, and this document must not be cited as evidence that money was
  lost in production — only that the mechanism existed.

---

## 4. If recovery is later authorised

The order of operations, so it is on record before anyone is under time pressure:

1. Run Q-F032-1 through Q-F032-5 on a replica and record the real numbers in this file.
2. Decide the `product_cost` question explicitly: reconstruct with today's cost, or write `0` and
   mark the rows as cost-unknown. Both are defensible; silently using today's cost is not.
3. Write the reconstruction as an idempotent script keyed on `store_order_financials.order_id`,
   whose UNIQUE constraint makes a second run a no-op.
4. Dry-run it against the replica and diff the proposed writes against Q-F032-3's output.
5. Only then, with explicit owner approval, against production.

Steps 3–5 are out of scope for this phase and nothing in this branch implements them.
