# P01-SEC-13 — PREPARED, NOT APPLIED

One migration: widen `customers.password_otp` from `INTEGER` to a nullable `VARCHAR(255)`.

## Why it is here and not in `database/migrations/`

The owner's authorisation for this package reads: *"You may prepare a migration and tests on the
isolated branch, but do not apply a migration to the development server."* A runnable file in the
live migration path is one `php artisan migrate` away from being applied — by a deploy step, by a
CI job, by anyone catching up a database — which is a decision this package is not authorised to
make on the owner's behalf.

This follows the convention `_gate_f_pending/` already established in this repository, and relies
on the same two independent properties:

1. Laravel discovers migrations with a **non-recursive** `glob($path . '/*_*.php')`, and nothing
   in this codebase registers a migration path under `database/migrations/`. So no `migrate`,
   `migrate:fresh` or `PackageMigrate` invocation reaches this directory.
2. The file is named `*.php.draft`, so it cannot match `*_*.php` **even if** this directory were
   later registered as a migration path.

`tests/Feature/Security/CustomerPasswordOtpColumnMigrationTest` asserts both properties, and also
asserts that no provider and no registered migrator path mentions this directory.

## VALIDATED AGAINST A REAL MySQL SERVER

The first report could only argue the engine behaviour from the declared column type and documented
MySQL semantics, because the container had no MySQL server. **It now does.** MySQL 8.0.46 was
installed, an isolated instance started on its own datadir and socket, and
`tests/Support/Security/bin/verify-sec13-migration-on-mysql.php` run against it:
**31 checks, 0 failures**, under the stock `sql_mode`
(`ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION`).

What that measured, rather than assumed:

| | measured result |
|---|---|
| a 60-char bcrypt hash into the `INT` column, strict mode | **ERROR 1366** — the write fails, so `sendPasswordOtp()` raises |
| the same write, strict mode off | **warning 1366, stored as `0`** (not null) |
| does the verify step's `empty()` guard catch that `0`? | **yes** — so the defect fails CLOSED |
| `Hash::check()` against that `0` | **throws** `RuntimeException: This password does not use the Bcrypt algorithm` — so the `empty()` guard at `:391` is also what keeps `:413` off a 500 |
| the table built from this repo's own `create_customers_table` migration | `password_otp` is `int`, nullable — as declared |
| after `up()` | `varchar(255)`, nullable |
| rows, `email`, `password` after `up()` | **unchanged**; every seeded password still verifies |
| pre-existing `password_otp` values after `up()` | preserved as their decimal strings (`4321`, `0`, `2147483647`, `-1`, `NULL`) |
| a 60-char hash after `up()` | stores untruncated; `Hash::check()` **matches** — the step that could never succeed on MySQL now does |
| after `down()` | `int` again; row count unchanged; every password still verifies |
| an in-flight hash across `down()` | **cleared to NULL**, not coerced to `0` |
| numeric values across `down()` | preserved, including `-1` and `2147483647` |
| `up()` run twice | harmless |
| `up()`/`down()` with the column absent | guarded no-ops |

**The full migration set also runs clean on MySQL 8.0.46** — `php artisan migrate` against an empty
database completed with exit 0 through every migration in `database/migrations/`. So `migrate` is a
working mechanism on this engine for this repository, which is what the owner's migration window
would use.

Re-run it yourself against any throwaway server:

```
SEC13_MYSQL_SOCKET=/path/to/mysqld.sock SEC13_MYSQL_DATABASE=sec13_verify SEC13_MYSQL_USERNAME=root SEC13_MYSQL_PASSWORD= php tests/Support/Security/bin/verify-sec13-migration-on-mysql.php
```

It creates and drops its own database and refuses to run against one whose name contains neither
`sec13` nor `verify`.

## What the defect is

`database/migrations/2023_12_15_112855_create_customers_table.php:39` declares
`$table->integer('password_otp')->nullable()` and no migration alters it.
`app/Http/Controllers/Customer/Auth/CustomerLoginController.php:154` writes
`Hash::make((string) $otp)` — 60 characters of bcrypt — into it.

SQLite's type affinity accepts that, which is why the test suite never noticed. MySQL raises
error 1366 under `STRICT_TRANS_TABLES` and coerces to `0` without it.

**It fails closed.** The verify step checks `empty($customer->password_otp)` before it checks the
hash, so a coerced `0` is refused as "no active verification code". On MySQL the web password
reset is therefore **broken, not exploitable** — no customer can complete one.

## This migration is not sufficient on its own

The verify step had **no attempt limit** and neither OTP route had a **throttle**. Those are
unreachable today precisely because nothing can verify; applying this widening by itself would
turn a dead flow into a brute-forceable one. Both controls are implemented on this branch
(`CustomerLoginController::MAX_OTP_ATTEMPTS`, `throttle:auth` on
`routes/customer.php:118-131`) and are safe to carry while the flow is still inert.

**Apply the column change only in a release that already contains them.**

## Preconditions

1. `SHOW COLUMNS FROM customers LIKE 'password_otp';` — a server provisioned outside these
   migrations may already be `VARCHAR`, in which case drop this draft instead of running it.
2. Confirm the attempt limit and the two route throttles are in the same release.
3. A backup that has been restored at least once. The DDL rewrites the table on MySQL; size
   `customers` first, because that is a locking operation.
4. A maintenance window. A web reset in flight across the change fails and the customer must
   request a new code.

## What it does and does not touch

`up()` is one DDL statement. **No customer row is read or written.** MySQL converts existing
integers to their decimal strings, and every such value is already inert: the 5-minute expiry on
`password_otp_datetime` refuses it, and it would fail `Hash::check()` regardless because it is
not a parsable hash. Nulling those values would be defensible housekeeping, is not necessary, and
is deliberately not done.

## Rollback is lossy

Narrowing back to `INTEGER` cannot represent a bcrypt hash. `down()` clears non-numeric values
first — so it never leaves a column of zeros the verify step would read as "a code exists" — which
invalidates any reset in flight at that moment. That is the cost of reversing, not a defect in
the reversal.

## Evidence

`tests/Feature/Security/CustomerPasswordOtpColumnMigrationTest` loads this draft and **executes**
`up()` and `down()` against the test schema: row count, `email` and `password` unchanged, a
pre-existing numeric value preserved, a 60-character hash stored and verified afterwards, the
rollback clearing rather than coercing, and the guard making it a no-op where the column is
absent.

The MySQL behaviour itself is established by the script above, on a real server. Precondition 1
remains, because what is still unknown is the **live** column's type: a server provisioned outside
these migrations may already be `VARCHAR`, in which case this draft should be dropped rather than
run.

## DEPENDENCY: P1-SEC-01 item B must be in place before this is applied

Applying this migration **arms an account takeover** that is currently held shut only by the broken
column this migration fixes.

`CustomerLoginController::verifyPasswordOtp()` writes three session flags —
`password_otp_verified`, `password_otp_verified_customer_id`, `password_otp_verified_store_id` —
which are, on their own, a standing authorisation to change that customer's password: the reset
handler reads nothing else, not even `password_otp`. Nothing rotated the session id at that
boundary, so an attacker who could plant the pre-OTP session id could replay it after the victim
verified and take the account over. Unlike the login boundary, which gets `migrate(true)` free from
`SessionGuard::updateSession()`, that one rotated nothing.

**P1-SEC-01 item B added `session()->regenerate(true)` immediately before those flags are written**
(migrate-with-destroy, so the planted `sessions` row is deleted rather than left carrying pre-verify
state, plus a CSRF-token rotation matching the house pattern at
`AuthenticatedSessionController.php:77`). Measured closed, with the legitimate reset preserved as an
explicit oracle.

So: the rotation landed first, deliberately, and it is what makes applying this migration safe.
`tests/Feature/Security/SessionHygieneTest.php` pins both halves and will tell you if either is
removed. Do not apply this migration on a tree where that rotation is absent.
