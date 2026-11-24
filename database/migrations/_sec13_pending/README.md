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
| `Hash::check()` against that `0` | **throws** `RuntimeException: This password does not use the Bcrypt algorithm` — so the `empty()` guard at `:410-417` is also what keeps the `Hash::check()` at `:463` off a 500 |
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
`app/Http/Controllers/Customer/Auth/CustomerLoginController.php:174` writes
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
(`CustomerLoginController::MAX_OTP_ATTEMPTS`, and `throttle:auth` on both OTP POSTs —
`routes/customer.php:126` for `send` and `:136` for `verify`) and are safe to carry while the flow
is still inert.

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

`CustomerLoginController::verifyPasswordOtp()` writes **four** session values —
`password_otp_verified`, `password_otp_verified_customer_id`, `password_otp_verified_store_id` and,
since SEC-SESS part A, `password_otp_verified_session_id` — the first three of which are, on their
own, a standing authorisation to change that customer's password: the reset handler reads nothing
else, not even `password_otp`. Nothing rotated the session id at that
boundary, so an attacker who could plant the pre-OTP session id could replay it after the victim
verified and take the account over. Unlike the login boundary, which gets `migrate(true)` free from
`SessionGuard::updateSession()`, that one rotated nothing.

**P1-SEC-01 item B added `session()->regenerate(true)` immediately before those flags are written**
(migrate-with-destroy, so the planted `sessions` row is deleted rather than left carrying pre-verify
state, plus a CSRF-token rotation matching the house pattern at
`AuthenticatedSessionController.php:77`). Measured closed, with the legitimate reset preserved as an
explicit oracle.

**SEC-SESS PART A THEN CLOSED THE SECOND ARMING PATH, and this migration arms that one too.** The
rotation stops a planted id being replayed; it does not stop the flags travelling FORWARD, because
`Store::migrate()` takes a new id without clearing the attributes — so `regenerate()` and
`regenerate(true)` both carry them. Part A binds the authorisation to the session that earned it
(`password_otp_verified_session_id`, compared at both read sites) and drops all four on customer
logout. **It is a narrowing, not a closure:** `SessionGuard::logout()` rotates nothing, so the two
forced merchant sign-outs (`APP_THEME()`, the `HomeController` chart helper) still change principal
without rotating, and a walk-away with no logout and no login rotates nothing at all. Two further
predicates would bound that residual and are recorded as follow-up, NOT applied: refusing when
`customers.password_otp` is already null (which would also make the claim in
`Api/AuthController.php` about re-checking true), and an expiry on the authorisation so it ends
with the flow rather than with the 120-minute idle session.

So: the rotation landed first and the binding second, both deliberately, and together they are what
make applying this migration safe.
`tests/Feature/Security/SessionHygieneTest.php` pins both halves and will tell you if either is
removed. Do not apply this migration on a tree where that rotation is absent.

### THE TWO FOLLOW-UP PREDICATES: REACHABILITY CANNOT BE ESTABLISHED IN THIS SUITE (2026-11-24)

The owner asked for the two predicates above to have their **actual reachability verified** before
either is implemented. The answer is that a Feature test cannot establish it, and the reason is
specific enough to be worth recording rather than re-discovering.

**Laravel's test client gives every request a NEW session id.** Measured five ways, all on this
tree:

| what was driven | result |
|---|---|
| two plain `GET`s of `/{slug}/forgot-password` | ids differ |
| two requests seeded with `withSession([...])` | ids differ |
| an unencrypted session cookie planted with `withUnencryptedCookie()` | ids differ, and neither matches the planted value |
| the same, with `config(['session.driver' => 'file'])` | ids still differ |
| the real pair: `POST .../otp/verify` then `POST .../reset-password/otp` | the reset is REFUSED, flashing `OTP verification is required.` |

So it is not the `array` driver (`phpunit.xml:40`, against `database` in production at
`config/session.php:21`) — the `file` driver behaves the same. The id rotates because the client
does not carry the session cookie back into the next request.

**And that interacts with SEC-SESS part A exactly as part A intends.** Part A binds the
authorisation to the session that earned it and compares `password_otp_verified_session_id` against
`session()->getId()` at both read sites. In the harness the second request's id never matches, so
**every** follow-up request is refused — the legitimate reset included. Confirmed directly: after
the verify, `password_otp_verified` is `true` and the bound id equals the live id; by the next
request the live id has changed while the bound id has not.

**WHAT THIS MEANS FOR THE PREDICATES.** A test for either one would assert that a reset is refused
— and a reset is refused here no matter what, so the test passes without touching the predicate.
That is not a hypothetical: the first version of this work had four such tests and **all four
passed on the unmodified tree**. Only the mandatory oracle ("a prompt legitimate reset still
completes") failed, and it is the single reason the vacuity was caught rather than committed as
evidence. The test file was deleted rather than kept, because a test that passes for the wrong
reason is worse than no test: the accepted-outcome gate would bless it.

**DISPOSITION: NEITHER PREDICATE IS IMPLEMENTED.** The owner's instruction was to verify
reachability and address *confirmed* defects. Nothing is confirmed:

- Both predicates guard a flow that is **inert on production MySQL today** (the column cannot hold
  the hash — see above), so neither is a live defect.
- Their necessity after the widening is **argued, not measured**, and this suite cannot measure it.
- Writing an authorisation guard whose effect no test can observe would add a control nobody could
  prove works or could notice breaking.

**WHAT WOULD ESTABLISH IT.** A harness that preserves one session id across requests — a real HTTP
client against a served instance, in the shape of `tests/Support/Security/bin/`, which is where
CLAUDE.md puts evidence that cannot live in the suite. That is the prerequisite for these two
predicates, and it is also what would let anyone drive **any** multi-request customer session flow,
including part A's binding and the forced-sign-out residual the paragraph above describes. Until it
exists, part A's coverage in `SessionHygieneTest` is necessarily single-request and source-level —
which is a limit on the EVIDENCE, not a defect in part A.

**So the ordering stands, with one item added.** Before this migration is applied: the attempt
limit and the two route throttles (already on the branch), and a decision on whether these two
predicates are wanted — which now needs the harness above, not more reading.
