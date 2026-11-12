# Production preflight — customer e-mail uniqueness index

**For a trusted production administrator to run. Read-only. Nothing here changes any data,
any schema, or any setting.**

Owner instruction, 2026-11-07: *"Prepare a minimal read-only preflight checklist for a trusted
production administrator to run."* The migration is **NOT authorised** and no migration file exists
in the repository.

---

## Why this exists at all

Everything the engineering work established about production came from **the dump committed to this
repository** (`storesyria_ecommercego.sql`). That is a point in time, and the owner's instruction
names the limit exactly: *"a restored production dump does not prove the current production state."*

This checklist is the smallest set of questions whose answers would make the migration decision
safe. **Nine commands. All `SELECT` or `SHOW`. No `ALTER`, no `CREATE`, no `UPDATE`, no `mysqldump`.**

> **NOTHING FROM THIS CHECKLIST GOES INTO GIT.** Not the output, not a transcript, not a dump.
> Paste the **numbers** into the decision thread. Query 6 is designed so that it cannot return an
> address even if run carelessly.

---

## What the repository currently believes, and on what evidence

| claim | evidence | strength |
|---|---|---|
| engine is **MariaDB 11.4.9** | the dump's own header, `-- Serverversie: 11.4.9-MariaDB` | a dump header, not the live server |
| `customers` collation is **utf8mb4_unicode_ci** | the dump's `CREATE TABLE` | same |
| **no unique index** on `customers.email` | the dump's `ALTER TABLE ... ADD KEY` block | same |
| `store_id` is `bigint(20) UNSIGNED` **NULL-able** | same | same |
| **0** duplicate groups, **0** multi-store addresses, **0** NULL-store live rows | two independent parsers over the dump, agreeing at 62 rows | same, and a point in time |
| `sql_mode` | **nothing** | **unknown — a dump does not record it** |

**Every row above needs confirming against the live server before a schema change.** The last one
has never been measured at all.

---

## The nine queries

Run as a user with `SELECT` on the application schema. Substitute the real schema name for
`:schema`.

### 1 — engine, version and `sql_mode`

```sql
SELECT VERSION() AS version,
       @@version_comment AS comment,
       @@sql_mode AS sql_mode;
```

**Why it matters.** The engine decides which enforcement route is even possible: MariaDB has **no
functional indexes at any version**, so the only route is a STORED generated column plus a unique
index over it. `sql_mode` is the one property the dump cannot record.

**Expected:** `11.4.9-MariaDB`. **If the major version differs, stop** — the verification run was
performed against 11.4.9 and its conclusions are version-specific.

### 2 — the table's own charset and collation

```sql
SELECT TABLE_COLLATION, ENGINE, ROW_FORMAT
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = ':schema' AND TABLE_NAME = 'customers';

SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, CHARACTER_SET_NAME, COLLATION_NAME
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = ':schema' AND TABLE_NAME = 'customers'
   AND COLUMN_NAME IN ('email', 'store_id', 'deleted_at');
```

**Why it matters.** The collation decides whether the index folds case and accents. Under
`utf8mb4_unicode_ci` it **does**, so `Alice@x.com` and `alice@x.com` in one store would collide —
and resolving such a pair is a product decision about two real people, not a schema step.
`store_id`'s nullability decides whether `COALESCE(store_id, 0)` is required in the expression.

### 3 — every index currently on the table

```sql
SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = ':schema' AND TABLE_NAME = 'customers'
 ORDER BY INDEX_NAME, SEQ_IN_INDEX;
```

**Why it matters.** Confirms no unique index on `email` already exists, and shows whether a column
named `email_store_live` is somehow present from an earlier attempt.

### 4 — row counts, live and archived

```sql
SELECT COUNT(*) AS total,
       SUM(deleted_at IS NULL)     AS live,
       SUM(deleted_at IS NOT NULL) AS archived
  FROM customers;
```

**Why it matters.** The migration forces `ALGORITHM=COPY` — a full table rebuild holding a write
lock. The dump showed 62 rows, where the rebuild is milliseconds. **If production is now orders of
magnitude larger, the window changes and so does the plan.**

### 5 — exact duplicate groups among LIVE rows

```sql
SELECT COUNT(*) AS duplicate_groups
  FROM (SELECT email, store_id
          FROM customers
         WHERE deleted_at IS NULL
         GROUP BY email, store_id
        HAVING COUNT(*) > 1) g;
```

**Why it matters.** **Any non-zero result blocks the migration.** The `ALTER` is refused on a table
holding live duplicates — which is correct behaviour, not an obstacle — and each group must be
resolved as a product decision before the schema change is scheduled.

### 6 — the same question case-insensitively, and WITHOUT returning an address

```sql
SELECT COUNT(*) AS case_folded_groups
  FROM (SELECT LOWER(email) AS e, store_id
          FROM customers
         WHERE deleted_at IS NULL
         GROUP BY LOWER(email), store_id
        HAVING COUNT(*) > 1) g;
```

**Why it matters.** The index folds case, so a pair differing only in case blocks the migration even
though query 5 reports zero. **This returns a count and never a row**, so it cannot disclose a
customer address.

> **If this is non-zero and query 5 is zero**, that is the hardest case: two accounts a merchant
> considers separate that the index would consider one. **Do not resolve them from the database.**
> Report the count and stop.

### 7 — addresses live in more than one store, and NULL-store live rows

```sql
SELECT
  (SELECT COUNT(*) FROM (SELECT LOWER(email) e
                           FROM customers
                          WHERE deleted_at IS NULL
                          GROUP BY LOWER(email)
                         HAVING COUNT(DISTINCT store_id) > 1) x) AS multi_store_addresses,
  (SELECT COUNT(*) FROM customers
                   WHERE deleted_at IS NULL AND store_id IS NULL)  AS live_null_store_rows;
```

**Why it matters.** The first is **legal** under the approved policy and shows the state is already
occurring. The second is the gap `COALESCE(store_id, 0)` closes: `CONCAT()` of a `NULL` is `NULL`,
and a unique index **exempts** `NULL` — so every such row would silently escape the constraint.

### 8 — table size and the migration window

```sql
SELECT ROUND(DATA_LENGTH/1024/1024, 1)  AS data_mb,
       ROUND(INDEX_LENGTH/1024/1024, 1) AS index_mb,
       TABLE_ROWS                       AS approx_rows
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = ':schema' AND TABLE_NAME = 'customers';
```

**Why it matters.** `ALGORITHM=COPY` is forced — `INSTANT`, `NOCOPY` and `INPLACE` are all refused,
measured. The rebuild **blocks writes to `customers` for its duration**, which means no customer
registration, no checkout that creates a customer, and no admin customer create while it runs.

### 9 — what else would block or be blocked

```sql
SELECT COUNT(*) AS foreign_keys_referencing_customers
  FROM information_schema.KEY_COLUMN_USAGE
 WHERE REFERENCED_TABLE_SCHEMA = ':schema' AND REFERENCED_TABLE_NAME = 'customers';

SHOW FULL PROCESSLIST;        -- run DURING the proposed window, not now
```

**Why it matters.** A long-running transaction touching `customers` will make the `ALTER` wait on a
metadata lock, and the `ALTER` will then block everything queued behind it. The process list is the
only way to see that before starting.

---

## Prerequisites before any migration is scheduled

**None of these is engineering work. All of them are the administrator's.**

| | prerequisite | why |
|---|---|---|
| ☐ | **A verified, restorable backup taken immediately before the window** — and a restore *tested*, not merely taken | `ALGORITHM=COPY` rebuilds the table. A backup nobody has restored is a hope |
| ☐ | **A written maintenance window** with writes to `customers` stopped or drained | the rebuild holds a write lock; registration and checkout both write here |
| ☐ | **Queries 5, 6 and 7 all returning the expected values**, re-run **inside** the window, not hours before | a duplicate created between the check and the `ALTER` fails the migration halfway |
| ☐ | **The rollback rehearsed on a copy** — `DROP INDEX` then `DROP COLUMN`. It destroys no data because the column is **generated**, but it forces a second `COPY` rebuild | this project has already had a `down()` destroy 45 `cost_price` values |
| ☐ | **An agreed abort condition** — what the administrator does if the `ALTER` is still running at N minutes | without one, the decision gets made under pressure |
| ☐ | **Someone who can read the refusal** — the migration is deliberately **two statements** so a duplicate-key error names the real colliding address rather than `'NULL'` | the single-statement form reports the wrong value when archived rows are present |

---

## What this checklist deliberately does NOT ask for

* **No `mysqldump`, no table copy, no data export.** Nothing leaves the server.
* **No customer addresses, names or ids.** Every query returns an aggregate. Query 6 is written as a
  count specifically so a careless run cannot print an address.
* **No credentials, connection strings or host names** in any reply.
* **No write of any kind**, including no `ANALYZE TABLE` and no `OPTIMIZE TABLE`.

**Report only the numbers.** If any query cannot be run, say which and why — a missing answer is
useful; a guessed one is not.

---

## Separately, and not part of this checklist: the S1 BackupRestore question

Owner instruction of 2026-11-07: ask the deployment administrator to verify whether the documented
**S1 `BackupRestore` module** is active and externally accessible — see
`docs/security/P01-SECURITY-WORK-QUEUE.md`.

**This is not something engineering may test from here, and it has not been tested.** The owner's
instruction is explicit: *"Do not probe production or retrieve backups without explicit
authorization."* No request was made to any production host.

What the administrator should check, **without downloading anything**:

1. Is the `BackupRestore` add-on **enabled** for any store? (`add_on_managers`, and the module's own
   settings.)
2. Does the document root serve the repository root rather than `public/`? The work queue's S1 row
   records that it does, with the evidence it was read from.
3. Are `storage/uploads/Files_Backup` and `storage/uploads/Database_Backup` reachable over HTTP —
   for example, does a request for a **known-nonexistent** path under them return 403/404 rather
   than a directory listing? **Do not request a real artefact.**

> **If exposure is confirmed, escalate immediately and do not wait for this checklist.** A reachable
> database backup is a disclosure of every customer record at once, which is a different order of
> problem from the uniqueness index this document exists for.
