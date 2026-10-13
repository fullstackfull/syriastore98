# GATE F — read-only schema export procedure for the database administrator

**Audience:** the DBA with production access. **Nobody on the development side has it, which is
why GATE F is still closed.**

**What is needed:** the *definition* of six tables. **Not one row of data.**

---

## Why this request exists

Six tables are read and written by live application code and are created by **no migration** in
the repository — not in `database/migrations/`, not in any of the 69 modules under
`packages/workdo/`:

| # | Table | Written by (example) |
|---|---|---|
| 1 | `store_wallets` | `OrderFinancialService::createForOrder()` — credits a merchant's wallet |
| 2 | `store_wallet_transactions` | the same function's ledger write |
| 3 | `store_withdrawals` | `StoreFinanceController` approve / reject / pay |
| 4 | `store_order_financials` | `OrderFinancialService::createForOrder()` / `markFinancialAsPaid()` |
| 5 | `currency_rates` | read by `StoreCurrencyConversionService::getRate()` |
| 6 | `delivery_earnings` | `OrderController::order_assign()` |

The application's PHP models give us **column names only**. They never give a type, a `DECIMAL`
precision or scale, nullability, a default, a key or an index. For money columns the precision is
exactly what must not be guessed.

**And a guess here becomes permanent and silent.** The migrations that will eventually create
these tables are written with `Schema::hasTable()` guards. If a guessed definition is applied
first, the later *correct* migration becomes a no-op — the wrong column types stay, and nothing
reports it. That is why no one has invented them, and why this export is the only way forward.

---

## The export — read-only, six tables, no data

Run as a user with `SELECT` on the schema. Substitute your database name for `YOUR_DB`.

### Step 1 — which of the six actually exist

Run this first. If a table is **absent in production too**, that is a different and more serious
finding than a missing migration, and development needs to know before anything else.

```sql
SELECT TABLE_NAME, ENGINE, TABLE_COLLATION, ROW_FORMAT, CREATE_OPTIONS
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'YOUR_DB'
  AND TABLE_NAME IN ('store_wallets','store_wallet_transactions','store_withdrawals',
                     'store_order_financials','currency_rates','delivery_earnings')
ORDER BY TABLE_NAME;
```

### Step 2 — the definitions (this is the artifact we need)

One statement per table. `SHOW CREATE TABLE` is a **metadata read**: it returns no rows from the
table, and the brief shared metadata lock it takes does not block reads or writes on a live site.

```sql
SHOW CREATE TABLE YOUR_DB.store_wallets;
SHOW CREATE TABLE YOUR_DB.store_wallet_transactions;
SHOW CREATE TABLE YOUR_DB.store_withdrawals;
SHOW CREATE TABLE YOUR_DB.store_order_financials;
SHOW CREATE TABLE YOUR_DB.currency_rates;
SHOW CREATE TABLE YOUR_DB.delivery_earnings;
```

This single output already carries everything GATE F is waiting for: column types with
`DECIMAL(p,s)`, `NULL` / `NOT NULL`, `DEFAULT`, `AUTO_INCREMENT`, primary key, unique keys,
secondary indexes, foreign keys with their `ON DELETE` / `ON UPDATE` rules, `ENGINE`,
`DEFAULT CHARSET` and `COLLATE`.

### Step 3 — the machine-readable cross-check

Same facts in a form that can be diffed against the drafts programmatically. Useful because it
separates `NUMERIC_PRECISION` and `NUMERIC_SCALE` into their own columns.

```sql
-- columns: types, DECIMAL precision and scale, nullability, defaults
SELECT TABLE_NAME, ORDINAL_POSITION, COLUMN_NAME, COLUMN_TYPE, DATA_TYPE,
       NUMERIC_PRECISION, NUMERIC_SCALE, CHARACTER_MAXIMUM_LENGTH,
       IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_KEY, COLLATION_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'YOUR_DB'
  AND TABLE_NAME IN ('store_wallets','store_wallet_transactions','store_withdrawals',
                     'store_order_financials','currency_rates','delivery_earnings')
ORDER BY TABLE_NAME, ORDINAL_POSITION;

-- indexes
SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME,
       INDEX_TYPE, NULLABLE, SUB_PART
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = 'YOUR_DB'
  AND TABLE_NAME IN ('store_wallets','store_wallet_transactions','store_withdrawals',
                     'store_order_financials','currency_rates','delivery_earnings')
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

-- foreign keys and their referential actions
SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.COLUMN_NAME,
       k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME,
       r.UPDATE_RULE, r.DELETE_RULE
FROM information_schema.KEY_COLUMN_USAGE k
JOIN information_schema.REFERENTIAL_CONSTRAINTS r
  ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
 AND r.CONSTRAINT_NAME   = k.CONSTRAINT_NAME
WHERE k.TABLE_SCHEMA = 'YOUR_DB'
  AND k.REFERENCED_TABLE_NAME IS NOT NULL
  AND k.TABLE_NAME IN ('store_wallets','store_wallet_transactions','store_withdrawals',
                       'store_order_financials','currency_rates','delivery_earnings')
ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION;
```

---

## What this procedure deliberately does **not** do

- **No row data.** Nothing above returns a single row from the six tables. They hold merchant
  financial records — wallet balances, transaction ledgers, withdrawal requests, delivery
  earnings. **Please do not send row data, and do not accept a request for it.** The schema is
  sufficient; the data is not needed and must not leave production.
- **No writes.** No `CREATE`, `ALTER`, `DROP`, `INSERT`, `UPDATE`, `DELETE`, no temporary tables,
  no `ANALYZE`, no `OPTIMIZE`.
- **No blocking locks.** No `LOCK TABLES`, no `FLUSH TABLES WITH READ LOCK`. The site stays up.
- **No `mysqldump` of data.** If you would rather use `mysqldump`, use **schema only** and
  without table locks:

  ```
  mysqldump --no-data --skip-add-drop-table --skip-lock-tables --single-transaction \
            YOUR_DB store_wallets store_wallet_transactions store_withdrawals \
                    store_order_financials currency_rates delivery_earnings
  ```

  `--no-data` is load-bearing. **Omitting it exports every merchant money row.**

A reviewer can confirm all of the above by reading the statements: every one is a `SELECT` or a
`SHOW`.

---

## What to do with the output

1. Save the Step 2 output as one file per table:
   `docs/gate-f-schema/<table_name>.sql` — for example `docs/gate-f-schema/store_wallets.sql`.
   Save the Step 1 and Step 3 output as `docs/gate-f-schema/_information_schema.txt`.
2. You may delete the trailing `AUTO_INCREMENT=<n>` from the `SHOW CREATE TABLE` output. It is a
   counter, not part of the definition, and it makes the file change on every export.
3. These files contain **no credentials and no row data**, so they are safe to commit. Please
   confirm that before committing, since you are the one who can see the output.
4. Tell the development side it has landed. GATE F lifts from there, not from a code change.

### The drafts are to be checked against your output, never trusted

Provisional drafts exist at `database/migrations/_gate_f_pending/*.php.draft`. They are
**inferences from PHP model definitions** and are deliberately inert — Laravel's migration lookup
is a non-recursive `*_*.php` glob, and `.php.draft` is not `.php`, so they cannot run. A CI guard
blocks any attempt to create these six tables elsewhere.

Every column type in those drafts is a guess. Where a draft and your export disagree,
**your export is correct and the draft is wrong.** In particular the drafts assume
`decimal(18,4)` for money columns, on the reasoning that the seeded currency list includes
3-decimal KWD/BHD/OMR. That reasoning may well be wrong about production. The real precision is
in your output and nowhere else.

---

## Related

- `docs/STABILIZATION-REGISTER.md` — finding `SCHEMA-01`, and the financial functions that stay
  unverified until this lands.
- `database/migrations/_gate_f_pending/README.md` — promotion procedure for the drafts.
- `.github/scripts/guard-gate-f-containment.sh` — the CI guard that keeps the six tables from
  being invented. Update it in the **same commit** that adds the real migrations.
