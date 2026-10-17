# `succeeded` payment status — historical impact assessment

**Status: ASSESSMENT ONLY. No historical record has been modified, and none should be without
owner authorization — a correction moves money into merchant wallets.**

Companion to the code fix in `app/Services/OrderFinancialService.php` and its regression suite
`tests/Feature/Commerce/PaymentStatusNormalizationTest.php`.

---

## Root cause

`OrderFinancialService::normalizePaymentStatus()` reduces the free text in
`orders.payment_status` to one of six states, and anything it does not recognise falls through
to `default => 'unpaid'`. Its match arms named `paid`, `success` and `completed`. They did not
name **`succeeded`**.

That one omission mattered more than any other could, because `succeeded` is the most common
spelling in this codebase: **20 order-write sites, against 4 for `success`**.

## Affected providers

Order-write sites that set `payment_status` to `succeeded` or `Succeeded`:

Stripe · Mercado Pago · Skrill · Midtrans · PayTabs · PayTR · Paytm · Flutterwave · CoinGate ·
NMI · SenangPay · PayU · Powertranz · PayNow · MyFatoorah

Providers that write `success` or `Paid` were always classified correctly and are unaffected.

## What a mis-normalised order looks like

`createForOrder()` derives every money field from this one answer
(`OrderFinancialService.php:54-65`), so an affected `store_order_financials` row holds:

| column | recorded | should have been |
|---|---|---|
| `payment_status` | `unpaid` | `paid` |
| `paid_amount` | `0` | the customer total |
| `remaining_amount` | the full total | `0` |
| `paid_at` | `null` | the time it was recorded |

And because `syncFromOrder()` asks the same question before calling `markFinancialAsPaid()`,
**the merchant's `store_wallets` balance was never credited** for those orders.

## Scope — the query an operator should run

Read-only. Run against a production replica.

```sql
-- 1. How many financial records are affected, and what they are worth.
SELECT  f.store_id,
        COUNT(*)                     AS affected_orders,
        f.currency_code,
        SUM(f.customer_total)        AS uncredited_total
FROM    store_order_financials f
JOIN    orders o ON o.id = f.order_id
WHERE   LOWER(TRIM(o.payment_status)) = 'succeeded'
  AND   f.payment_status <> 'paid'
GROUP BY f.store_id, f.currency_code
ORDER BY uncredited_total DESC;

-- 2. The same rows, for spot-checking before any decision.
SELECT  f.id, f.store_id, f.order_id, o.payment_status AS order_status,
        f.payment_status, f.paid_amount, f.remaining_amount, f.paid_at, f.created_at
FROM    store_order_financials f
JOIN    orders o ON o.id = f.order_id
WHERE   LOWER(TRIM(o.payment_status)) = 'succeeded'
  AND   f.payment_status <> 'paid'
ORDER BY f.created_at DESC;

-- 3. Sanity check the other direction: nothing should already be paid-but-unpaid-order.
SELECT COUNT(*) FROM store_order_financials f
JOIN   orders o ON o.id = f.order_id
WHERE  f.payment_status = 'paid'
  AND  LOWER(TRIM(o.payment_status)) IN ('unpaid', 'pending');
```

## The fix does NOT backfill, and that is deliberate

`OrderObserver::updated()` calls `syncFromOrder()` **only when `payment_status` actually
changed** (`app/Observers/OrderObserver.php:31`). An order already sitting at `succeeded` is not
re-synced by deploying this fix, so:

- **No wallet is silently credited on deploy.** Existing balances do not move.
- **No historical row is silently rewritten.** The affected rows stay exactly as they are.
- From the deploy onward, **new** `succeeded` orders are recorded and credited correctly.

A correction of the historical rows is therefore a separate, deliberate act.

## If the owner authorizes a correction

The mechanism already exists and is idempotent — `markFinancialAsPaid()` returns early when the
row is already `paid` (`OrderFinancialService.php:289-294`), takes `lockForUpdate()` on both the
financial row and the wallet, and converts currency through
`StoreCurrencyConversionService`. A backfill should therefore call that method per affected row
rather than issuing UPDATEs.

Before running one, decide explicitly:

1. **Which orders are in scope** — all of history, or from a cutoff date.
2. **Whether crediting now is correct**, per store. If a merchant was already paid out by
   another route, crediting the wallet now double-counts.
3. **Currency at which rate.** `markFinancialAsPaid()` converts at *today's* rate, not the rate
   on the order date, so a historical backfill credits a present-day amount.
4. **Reconciliation with payouts and withdrawals** already made against those wallets.

Items 2–4 are accounting decisions, not engineering ones. **None of this is in scope for the
Phase 4 workstream and none of it has been performed.**

## Residual risk after the fix

- **GATE F remains.** `store_order_financials` and `store_wallets` are read and written by live
  code and are created by **no migration in this repository**
  (`database/migrations/_gate_f_pending/README.md`). They do not exist in the test schema, so
  no automated test can assert what lands in a financial row; the regression suite pins the
  decision every such write is derived from, and asserts the tables are still absent so that
  limit stays visible. Clearing GATE F is the separate workstream that would let an end-to-end
  case exist.
- **The default is still `unpaid`.** That is correct for a financial record — never claim money
  arrived on the strength of an unrecognised word — but it means the next gateway added with a
  novel spelling repeats this defect silently. The census test
  (`test_the_set_of_written_payment_statuses_is_the_reviewed_one`) is the tripwire for that: it
  fails and names the new value.
- **Unverified `Paid` writes are unrelated and unchanged.** `PaymentVerificationService` already
  logs every order recorded as paid without a gateway verification seam; that backlog is its own
  tracked item and this fix neither widens nor narrows it.
