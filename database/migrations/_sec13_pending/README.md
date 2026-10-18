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

What those tests **cannot** establish is the MySQL failure itself — this container has the PDO
mysql client driver and no MySQL server. Precondition 1 is how that gets confirmed.
