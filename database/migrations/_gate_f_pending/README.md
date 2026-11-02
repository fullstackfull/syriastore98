# GATE F — PROVISIONAL schema drafts. NOT ACTIVE. NOT REVIEWED AGAINST PRODUCTION.

> **ONE DRAFT HAS BEEN PROMOTED AND IS NO LONGER HERE.**
> `0004_draft_add_cost_price_to_products_table.php.draft` became
> `database/migrations/2026_10_26_000100_add_cost_price_to_products_table.php` on **2026-10-26**,
> on the owner's written approval of ledger finding **F58**, scoped to isolated development and
> test environments with existing data preserved and no production migration run.
>
> It was promotable and the other eight are not, for the reason this README gives below: a
> guarded migration "is safe when the definition is right and a silent trap when it is a guess".
> `products.cost_price` was READ OFF the production schema on 2026-10-24
> (`decimal(15,2) NOT NULL DEFAULT 0.00`, `docs/GATE-F-VERIFIED-SCHEMA.md:382`), so its
> `hasColumn()` guard is a genuine no-op rather than a trap. The remaining drafts' types are
> still not derivable from `$fillable`, so they stay here.
>
> What promoting it fixed: product CREATE was impossible on every database built from this
> repository, because `store()` validates `cost_price` as required and writes it unconditionally.
> Driven end to end afterwards, a create through the real UI with both images now succeeds
> (`docs/development/phase-2/tools/check-product-create-live.cjs`, 9/9), and dropping the column
> again reproduces the original error exactly.
>
> MySQL evidence is PREPARED BUT UNCOLLECTED:
> `tests/Support/Security/bin/verify-cost-price-migration-on-mysql.php` establishes the type, the
> enforced scale, the INSERT-time NULL refusal, the production-shaped no-op and the harmless
> rollback — and the container this was written in has no MySQL server. Run it before anyone
> calls F58 closed on MySQL.

## Why this directory is not `database/migrations/`

Laravel discovers migrations with a **non-recursive** `glob($path . '/*_*.php')`, and nothing in
this codebase registers a migration path under `database/migrations/`. Verified: there is no
`loadMigrationsFrom` in `app/Providers/`, and `app/Console/Commands/PackageMigrate.php:55-61`
only ever points `--path` at `packages/workdo/*/src/database/migrations`.

So **no `php artisan migrate`, `migrate:fresh` or `PackageMigrate` invocation in this application
will execute anything in this directory.** That is the entire reason it exists.

As a second, independent layer, every draft is named `*.php.draft` rather than `*.php`, so it
cannot match the `*_*.php` glob **even if someone later registers this directory as a migration
path**. Two unrelated things have to go wrong before a provisional money-table definition can
touch a database. `tests/Unit/Database/MigrationHygieneTest.php` asserts both properties.

These drafts are deliberately held OUT of the live migration path, because putting them in it
would be actively dangerous. `Schema::hasTable()` guards make a migration a no-op against a
database that already holds the table — which means that if a *provisional* definition with a
guessed column type ran first and created the table, the later *correct* migration would no-op
and the wrong types would become permanent and invisible. A guarded migration is safe when the
definition is right and a silent trap when it is a guess. These are guesses.

## What these files are

`SCHEMA-01` records **15 tables that live code reads and writes and that no migration creates**,
6 of them money-bearing. `03-DATABASE-RECOVERY-PLAN.md` is the specification;
`04-INCREMENTAL-IMPLEMENTATION-PLAN.md` holds the whole set to **Increment 18 behind GATE F**
because the types are **not derivable from `$fillable`**, which omits types, lengths,
nullability, defaults, indexes and foreign keys.

What is in here is a **first draft of the 6 money-bearing tables**, derived from each model's
`$fillable` and `$casts` plus `app/Services/OrderFinancialService.php:90-118`, so that clearing
GATE F is a **diff against a production dump** rather than a write from scratch. Every file is
marked PROVISIONAL in its own docblock and names what specifically must be reconciled.

| # | Table | Draft here | Money-bearing | Evidence for the column list |
|---|---|---|---|---|
| 1 | `store_wallets` | YES | **YES — merchant balance of record** | `app/Models/StoreWallet.php:11,13-21` |
| 2 | `store_wallet_transactions` | YES | **YES — the ledger** | `app/Models/StoreWalletTransaction.php:11,13-27` |
| 3 | `store_withdrawals` | YES | **YES — payouts** | `app/Models/StoreWithdrawal.php:11,13-33` |
| 4 | `store_order_financials` | YES | **YES — per-order P&L** | `app/Services/OrderFinancialService.php:90-118` (an 18-column insert) |
| 5 | `delivery_earnings` | YES | **YES — courier commission** | `app/Models/DeliveryEarning.php:9,11-27` (`decimal:2` x3) |
| 6 | `currency_rates` | YES | **YES — FX multiplier on every converted price** | `app/Models/CurrencyRate.php:9,11-24` (`rate_per_usd` -> `decimal:8`) |
| 7 | `audit_logs` | no | no (compliance) | `app/Models/AuditLog.php:9,11-26`, `::record()` at `:28` |
| 8 | `otp_integrations` | no | no (auth) | `app/Models/OtpIntegration.php:9,11-30` — note `api_secret` is cast `encrypted` |
| 9 | `chatwoot_integrations` | no | no | **MODEL RETIRED.** Table RETAINED in production — see note below |
| 10 | `chatwoot_notification_templates` | no | no | **MODEL RETIRED.** Table RETAINED in production — see note below |
| 11 | `delivery_boy_devices` | no | no (push tokens) | `app/Models/DeliveryBoyDevice.php:12,14-24` |
| 12 | `delivery_boy_notifications` | no | no | `app/Models/DeliveryBoyNotification.php:12,14-31` |
| 13 | `zid_stores` | no | no — but **holds credentials** | `app/Models/ZidStore.php:15-29` |
| 14 | `zid_orders` | no | indirect (order mapping) | `app/Console/Commands/ZidProcessEvents.php:77-101,160-175` |
| 15 | `zid_events` | no | no | `app/Models/ZidEvent.php:15-29` |

**Tables 9 and 10 — the retired Chatwoot pair.** The Eloquent models, controllers, services and
views that used these two tables were removed when the legacy Chatwoot integration was retired.
**The tables themselves are deliberately NOT dropped, and no drop migration exists or should be
written without owner authorization.** They are the only record of which Store Syria store maps to
which Chatwoot `account_id` and `inbox_id`, plus each store's per-event notification template
configuration. That mapping is the migration input for the replacement Store Syria × Lynomia Chat
integration, and the only way to reconstruct what a merchant had configured. Dropping them is
irreversible and would destroy data no backup of the application code can regenerate.

Note `chatwoot_integrations.api_access_token` was written through Laravel's `encrypted` cast under
the application `APP_KEY`. Two consequences: that column is unreadable if `APP_KEY` is rotated
before the data is migrated, and — because `APP_KEY` was published in the tracked `.env` — it is
currently readable by anyone with a copy of the database. Treat every stored agent token as
compromised and re-issue rather than migrate it. See `docs/security/SECRET-ROTATION-CHECKLIST.md`.

Tables 7–15 are not drafted here. Their absence fails **soft** — Eloquent returns null for an
absent column and `app/Observers/OrderObserver.php:15-20` swallows `QueryException` — so they
block no other increment, and a half-researched guess at a credential-bearing table is worse
than no file at all. Their column lists are cited above so the dump diff is still cheap.

## THE WARNING THE OWNER MUST HEAR BEFORE AUTHORISING ANY OF THIS

`app/Observers/OrderObserver.php:15-20` wraps the financial write in `catch (\Exception $e)`, and
`QueryException` descends from `\Exception`. So on a deployment missing `store_wallets` and
`store_order_financials`, **orders save successfully and all financial tracking silently does
nothing** — the rows go to `storage/logs`.

Creating these tables therefore does not "fix a bug". It makes a **silently dead** subsystem
**start working**. Phase 0 reports a hardcoded `payment_status = 'Paid'` at
`app/Services/PaymentService.php:2280` (adjacent lane, NOT re-verified). If that holds, it must be
resolved **before or with** this migration, or COD and bank-transfer orders will credit merchant
wallets **against cash never collected**. Shipping the schema fix alone could convert a silent
no-op into real incorrect payouts. This is the single most important thing to say about SCHEMA-01.

## What GATE F must supply before any of this moves

1. `SHOW CREATE TABLE` for all 15 tables, plus `users.plan_id`, `products.cost_price` and
   `orders` — **from a RESTORED COPY of a backup, with a read-only account, never from the live
   host.** Even a schema-only dump is sensitive: grep it for `DEFINER=` and `IDENTIFIED BY`, move
   it privately, keep it out of git, delete it afterwards.
2. A read-only NULL census: `SELECT COUNT(*) FROM <t> WHERE store_id IS NULL` across the 56
   `store_id` tables.
3. `SELECT DISTINCT type FROM users`.
4. The resolution of the `payment_status = 'Paid'` question above.

## Standing decisions these drafts already encode

- Money columns are `decimal(18,4)`, **never** `float`. Do not copy the `float()` used for all six
  `orders` money columns at
  `database/migrations/2024_01_23_061635_create_orders_table.php:22-27`. Scale **4**, not 2,
  because the seeded currency list includes 3-decimal KWD/BHD/OMR (GATE G, decision 1).
- `currency_rates.rate_per_usd` is `decimal(18,8)`, to match the model's own `decimal:8` cast at
  `app/Models/CurrencyRate.php:22`.
- Foreign-key order: `store_wallets` -> `store_wallet_transactions` -> `store_withdrawals`, with
  `store_order_financials` after `store_wallets` because it writes `wallet_id`
  (`OrderFinancialService.php:21-23` resolves the wallet, `:90-92` writes the column).
- No foreign key is declared to `stores` or `users` in these drafts. `users` is the highest-fan-in
  table in the schema (14 FKs already reference it) and adding cascade semantics to money tables
  is a behaviour decision, not schema catch-up. Confirm against the dump instead.
- `store_id` is indexed on every table that has it: every query filters by it.

## How to promote a draft

1. Diff it against the dump from GATE F step 1. **The dump wins on every disagreement.**
2. Move the file into `database/migrations/` with a fresh timestamp **and the `.draft` suffix
   removed**, keeping the `Schema::hasTable()` guard, and delete the PROVISIONAL banner only once
   the diff is clean.
3. Build from zero into a **scratch** database and compare — never production, never
   staging-with-data.
4. Add the table to the skip-list exemptions in
   `tests/Feature/Tenancy/CrossTenantModelIsolationTest.php`, which currently calls
   `markTestSkipped()` for `store_wallets`, `store_wallet_transactions` and `store_withdrawals`
   precisely because they do not exist yet.

---

## P1-MIG-03 (#40) — two COLUMN drafts, not table drafts

Added after the six table drafts above and listed separately because they are a different kind of
change: `ALTER TABLE` on a table that already exists, not `CREATE TABLE` for one that does not.

| # | Draft | Target | Why it is here |
|---|---|---|---|
| 7 | ~~`0007_draft_add_cost_price_to_products_table.php.draft`~~ **PROMOTED 2026-10-26, see the note at the top of this file** | `products.cost_price` | Read and written as money by live code, declared by no migration. **Sequence it BEFORE `store_order_financials` (0004)**: with no column, `OrderFinancialService.php:214` reads null, `?? 0` makes the unit cost zero, and every P&L row is all-margin. Creating the financials table first would begin recording confident, wrong profit. |
| 8 | `0008_draft_add_commission_columns_to_delivery_boys_table.php.draft` | `delivery_boys.commission_percentage`, `.is_commission_enabled` | Both `$fillable`, both written unconditionally by `DeliveryBoyApiController` store() and update(), neither declared — so **both handlers 500 for everyone today**. **Promote at the same time as `delivery_earnings` (0005) or before it**, never after: `is_commission_enabled` is what gates the earning write, so creating 0005 first leaves the earnings subsystem silently dead in exactly the way this README warns about. |

The same rule applies to both: the drafts are **inferences**, the production dump wins on every
disagreement, and the `hasColumn()` guards make a promotion a no-op against a column that already
exists — which is safe HERE, unlike for the table drafts, because a no-op leaves the production
type in place rather than freezing a guess.

**Promotion is gated on D15** — real MySQL schema evidence plus verified backup restoration —
exactly as the six table drafts are. Writing a migration is not running one.

---

## GATE F VERIFICATION — attempt of 2026-10-24, and what it established

**Prerequisite 4 is now MET.** The `payment_status = 'Paid'` question this README calls *"the single
most important thing to say about SCHEMA-01"* was answered by the owner on 2026-10-24: the
Delivered-based COD trigger is **withdrawn**. Delivered alone never marks COD Paid and never credits
a wallet; settlement requires a separate verified cash collection event naming collector, amount,
time and evidence. Implemented in `47719ce1` (`PaymentVerificationService::codMayBeSettled()`,
`App\Services\CodCollectionService`, and `codShouldSettleOnDelivery()` retired to a method that
throws), pinned by `tests/Feature/Commerce/CodStateMachineTest.php` — **42 tests, executed
2026-10-24.** So the warning above — that shipping the schema alone could convert a silent no-op
into real incorrect payouts — no longer describes the code. It still describes the **data**: rows
already written by the withdrawn trigger keep their status, and nothing was back-filled.

**Prerequisites 1, 2 and 3 are NOT met, and could not be attempted.** A database was reported
uploaded on 2026-10-24; **no dump reached this container.** Searched and found empty:
`/mnt/user-data/uploads`, `/mnt/attach`, both repository trees, and every file over 1MB written
anywhere on the filesystem in the preceding hours. The only database present was the repository's
own development `database/database.sqlite` (dated 2026-10-22, not an upload). **Nothing was
guessed to fill the gap, and no draft was promoted.**

### What exists now so the real verification is one command

`tests/Support/Security/bin/verify-gate-f-schema-on-mysql.php` — **read-only**, no write statement
anywhere in it. Point it at a restored copy with a read-only account:

```
php tests/Support/Security/bin/verify-gate-f-schema-on-mysql.php <database> [--host= --user= --pass= --socket=]
```

It performs prerequisites 1–3 in one pass and reports:

- whether the dump is whole, schema-only or partial (table count, which hold rows, views/routines/
  triggers, and whether `migrations` is present — without it, promotion order cannot be checked
  against the source's own history);
- `SHOW CREATE TABLE` for each of the 15 SCHEMA-01 tables that exists, plus a per-column table of
  types, nullability, defaults, keys and every foreign key; absent tables are listed as **evidence**
  rather than as a gap, since an absent table means its draft has nothing to diff against;
- the six money tables against this README's standing decisions — `decimal(18,4)` and never
  `float`, `currency_rates.rate_per_usd` at `decimal(18,8)`, `store_id` indexed wherever present;
- `products.cost_price` and the two `delivery_boys` commission columns, warning if either already
  exists (the draft would then be a no-op at best and wrong at worst);
- `cod_collection_events` against the owner's four required fields;
- the NULL/zero `store_id` census across every table that **has** the column, enumerated from
  `information_schema` rather than from this README's figure of 56, so a moved schema is reported
  instead of assumed;
- `SELECT DISTINCT type FROM users` with counts, and `users.plan_id`;
- both ordering constraints — `products.cost_price` **before** `store_order_financials`, and the
  commission columns **with or before** `delivery_earnings` — as pass/fail against the live state.

**It exits 1 on any disagreement, and every message says THE SOURCE WINS.** It never reports the
database as wrong; it reports the draft as wrong, which is this README's own promotion rule.

**Proven in both directions before being trusted**, on MySQL 8.0.46 in an isolated instance:

| fixture | result |
|---|---|
| deliberately contradictory (float money column, `decimal(10,2)`, unindexed `store_id`, `rate_per_usd` at the wrong scale, financials without `cost_price`, earnings without the commission flag, NULL and zero `store_id` rows) | **6 contradictions caught, exit 1** |
| correct (every standing decision honoured) | **0 contradictions, exit 0** |

Both fixture databases were dropped and the server stopped; only the four MySQL system schemas
remained. A bug was found and fixed in the harness during that check: `SHOW CREATE TABLE` returns
**two** columns, so the first draft printed the table *name* where the DDL belonged — every
structure section would have been vacuous while looking correct.

---

## 2026-10-24, later the same day — THE DUMP WAS FOUND AND RESTORED

The dump was located on `main` at commit `3935dee8` (`storesyria_ecommercego.sql`, 20,470,785 bytes,
sha256 `6e54b88f…c7df78e`). It was fetched by object hash without merging `main`, held at mode 0600
in a directory outside every git repository, restored into an isolated local MySQL, read, and then
**destroyed**. It was never committed, never copied to a tracked branch, and no row data from it
appears in this repository.

**The full account is `docs/GATE-F-VERIFIED-SCHEMA.md`. Read it before promoting anything.** The
short version:

**Three claims this repository held were refuted.** All 15 SCHEMA-01 tables **exist in production
with live data** — 80 wallet-ledger rows, 92 per-order P&L rows, 40 currency rates. So do
`products.cost_price` and both `delivery_boys` commission columns. The financial subsystem is not
dead; it is running. What survives is that **no migration creates any of it**: the schema is
*unmanaged*, not absent.

**The drafts are now reconciled, and were wrong in three ways.** 22 money-scale contradictions
(production is `decimal(15,2)`, not the standing `(18,4)`); four enums weakened to `varchar(255)`,
including the ledger's `direction`; and — the serious one — **every UNIQUE key and every FOREIGN KEY
was missing.** Production has 3 and 12; the drafts declared none. Among them
`uq_store_order_financial_order` and `uq_store_wallet_store_id`, which are what make "one financial
row per order" and "one wallet per store" *enforced* rather than merely observed. The drafts now
reproduce production's columns, index sets and foreign keys **exactly**, verified by running them
against a stripped copy and diffing back.

**Two `down()` methods destroyed production data, measured not reasoned.** `0004` and `0006` guarded
`up()` on `hasColumn` but dropped unconditionally in `down()`. Against a copy of production,
`migrate:rollback` executed them and lost all 45 `cost_price` values (1170.65) and both commission
columns. Both are now documented no-ops; the same rollback now leaves everything intact.

**Both ordering constraints were violated by the file numbering itself**, since Laravel sorts by
filename. Renumbered: `cost_price` is now `0004` (before financials `0005`), commission is `0006`
(before earnings `0007`).

### The harness was checking the wrong thing, and now checks the structure

`verify-gate-f-schema-on-mysql.php` encoded the `decimal(18,4)` standing decision as its
expectation. Against the real database that made it report **22 contradictions in the wrong
direction** — a checker whose expectation is a decision rather than the database reports the
database as wrong, which is the opposite of this directory's own promotion rule. It now carries the
**measured** per-column types, and a money column that is floating-point is still refused whatever
the source says, because that is a correctness property rather than a choice.

More importantly it now checks **unique keys and foreign keys**, which is the control whose absence
let a set of drafts with no constraints at all pass a type-only comparison. Proven in both
directions before being trusted, as the earlier validation was:

| fixture | result |
|---|---|
| the corrected drafts, applied | **0 contradictions, exit 0** |
| six deliberate breaks — a dropped unique key, a unique key over the wrong column, two missing foreign keys, a `(18,4)` money column, a `double` money column | **6 contradictions caught, exit 1**, each named |
| a money-shaped column absent from the measured map | **warned**, not guessed at — which is how the `(18,4)` error happened in the first place |

The fixture database was dropped and the server stopped. One incidental finding from building it:
MySQL **refuses** to drop `uq_store_order_financial_order` while the `order_id` foreign key needs
it, so that unique key is load-bearing twice over.

### The prerequisites, restated against evidence

| prerequisite | state |
|---|---|
| 1 · `SHOW CREATE TABLE` from a restored copy | **MET** — all 15 tables read; DDL in `docs/GATE-F-VERIFIED-SCHEMA.md` |
| 2 · NULL `store_id` census | **MET** — 0 NULL or zero `store_id` in any money table |
| 3 · `SELECT DISTINCT type FROM users` | **MET** |
| 4 · the `payment_status = 'Paid'` question | **MET** (owner decision, 2026-10-24) |
| 5 · drafts reconciled against the real structure | **MET** — identical, verified by diff |
| 6 · migration sequence tested, including rollback | **MET** — on an isolated restored copy, both directions |
| 7 · ordering constraints enforced | **MET** — by renumbering |
| D15 · the remaining promotion prerequisites | **unchanged** |

### Why GATE F is still NOT ready, and it is no longer about the schema

The schema questions are answered. What blocks promotion now is the **live data**, which activation
would propagate rather than create:

1. **A wallet that cannot be reconciled from its own ledger.** Wallet 1 (store 2) is denominated USD
   and carries 62 SYP, 17 USD and 1 BYR transaction summed into one `available_balance`. Its summary
   columns are internally consistent (706,091.08 − 2.86 = 706,088.22) but **no currency subset of
   the ledger reproduces them**, and the running balance breaks at 9 points, once by 98.28 billion.
   The schema's `UNIQUE (store_id)` means one wallet per store, so there is structurally no place to
   hold a per-currency balance — the mixing is a design consequence, not a data-entry error.
2. **17 COD orders settled with no collection evidence**, totalling 8,866,859,166.16. They are
   historical rows from the Delivered-based trigger withdrawn on 2026-10-24, deliberately not
   back-filled. No collection event can exist for them because `cod_collection_events` does not.
3. **`gross_profit` is not a usable profit figure.** 89 of 92 P&L rows carry `product_cost = 0`, so
   gross profit equals full revenue. Cause measured, and it is **not** the ordering defect:
   recomputing all 89 against today's costs changes nothing (0 would become non-zero). It is that
   `cost_price` is `NOT NULL DEFAULT 0.00`, so the schema **cannot represent "cost unknown"** — 36
   of 45 products read 0.00 and are indistinguishable from genuinely-free goods.

**What is clean**, measured on the same restore: 0 duplicate financial rows per order, 0 orphans,
0 cross-tenant financial rows, 0 cross-tenant wallet transactions, 0 NULL/zero `store_id` in the
money tables, and 0 row-level arithmetic violations across all 80 ledger rows.

**GATE F is NOT ready for production migration.** No draft was promoted, no migration was run
against any server, no live data was altered, and the three throwaway databases used for this
verification were dropped.
