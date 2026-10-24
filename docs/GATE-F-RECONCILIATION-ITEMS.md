# GATE F — the three reconciliation items

**Owner direction, 2026-10-24:** *"Preserve the historical wallet discrepancy, 17 COD orders lacking
collection evidence, and 89 suspicious profit records as explicit reconciliation items. Do not invent
collection evidence, missing costs, or corrected balances. Prepare and test any safe reconciliation
logic against isolated copies. Do not activate unverified balances or payouts."*

These are **open items**, not defects awaiting a code fix. Each is a disagreement between figures
that already exist in production, and **none of them can be closed by this project**: closing them
requires facts only the business holds — what cash was actually collected, what the goods actually
cost, what the balance should actually be. Writing a number in to make a column agree would be
manufacturing a record, which is the one thing the direction forbids twice.

All figures below were measured on 2026-10-24 against a restored copy of the production dump
(`storesyria_ecommercego.sql` at `3935dee8`), which was then destroyed. Structure and method:
`GATE-F-VERIFIED-SCHEMA.md`.

---

## R1 — the wallet balance cannot be reconstructed from its own ledger

| | |
|---|---|
| subject | `store_wallets` id 1 (store 2), the only wallet with transactions |
| the wallet says | `available_balance` **706,088.22**, `total_income` 706,091.08, `total_withdrawn` 2.86 |
| internally consistent? | **yes** — 706,091.08 − 2.86 = 706,088.22 exactly |
| the ledger says | net of 80 rows = **59,334,103,603.20** |
| divergence | ~59.33 billion |

**It is not a rounding or sign error.** No currency subset reproduces the wallet either:

| currency | rows | net |
|---|---|---|
| SYP | 62 | 59,321,403,330.34 |
| USD | 17 | 12,700,262.86 |
| BYR | 1 | 10.00 |

**Two measured properties bound the problem.** Row-level arithmetic is **perfect**: for all 80 rows
`balance_after = balance_before ± amount` holds exactly (0 violations, using the `direction` column —
`type` holds `order_paid`/`manual_deposit` and is not the credit/debit discriminator). But the
**chain breaks at 9 of 79 adjacent pairs**: a row's `balance_before` does not equal the previous
row's `balance_after`. The largest jumps are −98.28 billion (txn 54), +39.71 billion (txn 19) and
+91.09 million (txn 79). So each row was written consistently against *some* balance, and that
balance was not the one the previous row left.

**The structural cause of the currency mixing, and it is not data entry.** `store_wallets` carries
`UNIQUE KEY uq_store_wallet_store_id (store_id)` — one wallet per store — and a single
`currency_code` plus a single `available_balance`. There is **nowhere to hold a per-currency
balance**. A store transacting in SYP and USD has one column for both, so mixing is what the schema
requires rather than what someone did wrong.

**What must not happen:** no corrected balance is to be written, and no payout is to be computed from
either figure. `store_withdrawals` holds 0 rows today; it must stay that way until R1 is resolved.

**What closing it needs (business, not engineering):** a decision on what the balance of record *is*
— the wallet column or the ledger — and, if per-currency balances are wanted, a schema change that
can express them. Until then the reconciliation report below states the divergence rather than
resolving it.

---

## R2 — 17 COD orders settled with no collection evidence

| | |
|---|---|
| rows | **17** `cod` orders with `payment_status = Paid` and a financial row `paid` |
| total | **8,866,859,166.16** |
| of which delivered | 1 (`delivered_status = 1`) |
| collection events | **0, and none can exist** |
| plus | 1 further `cod`/`Paid` order with **no financial row at all** |

**Why no event can exist:** `cod_collection_events` does not exist in production. It is the one
genuinely absent GATE F object, by decision **D15**, and it is the gate on COD settlement —
`CodCollectionService` guards on `Schema::hasTable()` and logs `critical`, and
`PaymentVerificationService::codMayBeSettled()` returns false with no event.

**These rows are historical and deliberately untouched.** They were written by the Delivered-based
COD trigger that was **withdrawn on 2026-10-24**; the same decision says existing rows keep their
status and nothing is reversed or back-filled. The control now works: measured on the same restore,
**42 COD orders are Unpaid and 2 of them are delivered** — delivered and not credited, which is the
withdrawn trigger's absence visible in the data.

**What must not happen:** no collection event is to be fabricated for any of the 17, including a
backfilled one that records "collected" with a guessed collector, amount or time. An event is
evidence; an event invented to satisfy a check is the opposite.

**What closing it needs:** for each of the 17, the business's own record of whether the cash was
collected, by whom and when. Where that record does not exist, the honest outcome is an order marked
as unreconcilable, not one marked collected.

---

## R3 — `gross_profit` equals revenue on 89 of 92 rows

| | |
|---|---|
| financial rows | 92 |
| with `product_cost = 0` | **89** |
| products with `cost_price = 0` | 36 of 45 (and 0 are NULL) |

**Cause measured, and it is NOT the migration-ordering defect.** The obvious hypothesis — that
`store_order_financials` began recording before `products.cost_price` existed — was tested by
recomputing all 89 rows against today's cost data: **0 of 89 would become non-zero.** So the rows are
not stale; the costs were never entered.

**The schema cannot express "cost unknown."** `products.cost_price` is
`decimal(15,2) **NOT NULL DEFAULT 0.00**`, so a merchant who has never entered a cost is stored
identically to one whose cost is genuinely zero. `OrderFinancialService::calculateProductCost()`
reads `$product->cost_price ?? 0` — the `??` never fires, because the column is never null. Every
such line contributes 0 cost and the P&L row's `gross_profit` becomes the full revenue.

**So `gross_profit` is not a usable profit figure for 89 of 92 orders**, and it does not look wrong:
it looks like a very profitable business.

**What must not happen:** no cost is to be invented or back-filled from a margin assumption, a
category average or a supplier list nobody has confirmed.

**What closing it needs:** merchant-entered costs, and a decision on whether `cost_price` should
become nullable so that "not set" and "free" stop being the same stored value. That is a live-column
change on a money column and so is the owner's call.

---

## The reconciliation logic that is prepared, and what it does not do

`tests/Support/Security/bin/report-gatef-reconciliation.php` reports all three items against a
restored copy.

**It is read-only by construction** — there is no `INSERT`, `UPDATE` or `DELETE` anywhere in it, and
that is the safety model rather than a flag. It computes and prints:

- per wallet: the stored balance, the ledger net overall and per currency, the number of chain
  breaks and the largest jump — **R1**;
- COD orders settled without a collection event, with their total, and whether
  `cod_collection_events` exists at all — **R2**;
- financial rows whose `product_cost` is zero, and how many would change if recomputed against
  current `cost_price` — **R3**, including the measurement that distinguishes unentered costs from
  the ordering defect.

**It resolves nothing.** It writes no corrected balance, creates no collection event, fills no cost,
and computes no payout. Its exit code is 1 when any item is present, so it can gate a release
without anybody having to read it carefully.

**Validated on isolated copies, both directions**, because a reconciliation report that always says
"all clear" and one that always says "discrepancy" produce the same output without a control:

| fixture | expected |
|---|---|
| a clean synthetic store — single currency, unbroken chain, no COD settled without evidence, costs entered | **0 items, exit 0** |
| a synthetic store reproducing all three shapes | **3 items, exit 1**, each named |

See `tests/Feature/Commerce/GateFReconciliationReportTest.php` for the fixtures and the assertions.

## Gates that stay pending

Per the same direction: **production migration and final reconciliation gates stay pending until
their actual prerequisites pass.** R1, R2 and R3 are prerequisites of the reconciliation gate, and
none of them is engineering work. `GATE-F-VERIFIED-SCHEMA.md` records the schema side, which *is*
settled; this file records the data side, which is not.
