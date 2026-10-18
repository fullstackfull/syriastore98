# 10 — Next Phase 01 work packages

Ordered. Each is independently reviewable, independently revertable, and scoped so one can be
rejected without blocking the next. Severity vocabulary and the findings themselves live in
[`../../security/P01-SECURITY-WORK-QUEUE.md`](../../security/P01-SECURITY-WORK-QUEUE.md).

**P01-SEC-10 is closed** by the password-recovery fix; it is listed below only to record what
remains attached to it.

---

## P01-SEC-13 — The web OTP reset cannot verify on MySQL  ·  **prepared, awaiting a migration window**

`Customer\Auth\CustomerLoginController:154` writes `Hash::make($otp)` — a 60-character bcrypt
string — into `customers.password_otp`, declared `integer()` by
`database/migrations/2023_12_15_112855_create_customers_table.php:39` with **no migration altering
it**. SQLite's dynamic typing accepts the string, which is why the flow passes in tests; MySQL
raises error 1366 under `STRICT_TRANS_TABLES` and coerces to `0` without it.

**The secure web reset therefore cannot complete on the production engine, and that is very
likely why the insecure API path existed.**

**It fails closed.** The verify step tests `empty($customer->password_otp)` at
`CustomerLoginController:391` before it reaches `Hash::check()` at `:413`, so a coerced `0` is
refused as "no active verification code". This is an availability defect, **not a bypass** — a
correction to how the first report left it open.

### What the verification package did with it

**Prepared, executed in isolation, and deliberately NOT runnable.**
`database/migrations/_sec13_pending/0001_draft_widen_customers_password_otp.php.draft` widens the
column to a nullable `VARCHAR(255)`. It follows the `_gate_f_pending/` convention already in this
repository: Laravel's migration glob is non-recursive and the file is named `*.php.draft`, so two
unrelated things must go wrong before a deploy could apply it.
`tests/Feature/Security/CustomerPasswordOtpColumnMigrationTest` loads the draft and **runs** `up()`
and `down()` against the test schema — row count, `email` and `password` unchanged, a pre-existing
numeric value preserved, a 60-character hash stored and verified afterwards, the rollback clearing
rather than coercing, and the guard making it a no-op where the column is absent.

`up()` is one DDL statement and reads or writes **no customer row**. Existing integer values are
left in place: they are already inert (refused by the 5-minute expiry, and unparsable as a hash).

### The migration alone would have made things worse, so two controls ship ahead of it

The web verify step had **no attempt limit** and neither of its POST routes had a **throttle** —
only the sibling login route at `routes/customer.php:102-104` did. The 5-minute expiry was the
sole bound on guessing a 6-digit code, and `customer.password.otp.send` could mint a fresh one
without limit.

None of that is reachable while the column is an INTEGER, because nothing can verify at all.
**Applying the widening by itself would have turned a dead flow into a brute-forceable one.** Both
controls are now in place and are safe to carry while the flow is still inert:

- `CustomerLoginController::MAX_OTP_ATTEMPTS` (5, matching the API service), counted in the session
  the flow already uses for its customer and store binding, destroying the code when exhausted;
- `throttle:auth` on `customer.password.otp.send` and `customer.password.otp.verify`.

### Preconditions on the owner, before this runs anywhere

1. `SHOW COLUMNS FROM customers LIKE 'password_otp';` — a server provisioned outside these
   migrations may already be `VARCHAR`, in which case drop the draft rather than run it. **The
   MySQL behaviour above was not executed:** this container has the PDO mysql client driver and no
   MySQL server, so it rests on the declared type plus documented MySQL semantics.
2. The attempt limit and both throttles must be in the same release.
3. A backup that has been restored at least once — the DDL rewrites the table on MySQL, which
   locks; size `customers` first.
4. A maintenance window. A reset in flight across the change fails and the customer re-requests.

**Rollback is lossy and says so:** narrowing back to `INTEGER` cannot represent a bcrypt hash, so
`down()` clears non-numeric values first, invalidating any reset in flight at that moment.

**Blocked on:** owner approval to run a migration on the dev server. Nothing else.

---

## P01-SEC-11 — One API authorization pass  ·  **second, needs a Flutter window**

The thirteen remaining reproduced escalations in `routes/api.php` handlers (S15-B rows 1–7, plus
the `DeliveryBoyApiController` set). Seven of eight share one shape: **a credential or identity
written on a caller-supplied row with no ownership predicate.**

Do it as **one pass with one shared ownership helper**, not thirteen patches.
`Api\DashboardController::resolveChangeableOrder()` and the `resolveViewableOrder()` added for S12
are the precedent; `CustomerPasswordResetService` is the precedent for authorization that must
survive a compiled client.

Non-negotiables carried from the password fix: paths, verbs and success envelopes preserved and
asserted; **no denial answers 401**, because `network.dart` turns one into a forced logout;
denials reuse each endpoint's own error envelope so "not yours" is indistinguishable from "not
found".

**Blocked on:** an owner-approved window in which API behaviour may change, and confirmation of
which endpoints a customer-facing client calls — `flutter-endpoints.json` covers the admin app
only.

---

## P01-SEC-12 — `config/superadminaccess.php` vs the finance permissions  ·  **third**

The `RolePolicy::grantPermission` denylist has 13 names and omits the six money-moving finance
permissions, so a merchant with `Create Role` — every merchant — can grant a role authority the
merchant does not itself hold. No API impact, no migration, small and self-contained.

---

## P01-TOOL-1 — Agent tooling  ·  **separate, subsequent, and deliberately last**

Scheduled as its own package because tooling must not delay security work, and because each item
below changes how future work is done rather than what the product does. In priority order:

1. **A project `CLAUDE.md`.** The single highest-leverage artifact and there is none. Every
   session re-derives: Laratrust not spatie; `permissions_as_gates` disabled so `@can()` is always
   false; no super-admin bypass, so operator status is a `users.type` string comparison;
   `'admin'` and `'super admin'` are both authority literals; `Order` is not in
   `config/tenancy.php`'s `scoped_models`; no WorkDo module loads in any test run unless a test
   inserts an `add_on_managers` row; denials must be 403 because of `network.dart`; GATE F.
   Several of this phase's findings cost real time to rediscover.
2. **A `PreToolUse` hook refusing edits under `packages/workdo/`.** CI already catches these —
   after a push. The hook makes the wasted work impossible; CI stays the backstop.
3. **A `tenant-authz-reviewer` subagent.** Every major finding this phase — order documents, S12,
   S13, S15, the password reset — was the same defect shape, and CI could not see any of them;
   adversarial review found all of them. Seed it with the facts from item 1.
4. **A `/tenant-check` command** wrapping the existing guard scripts and the tenancy test subset.
   Wraps, does not duplicate.

Keep all four in `.claude/` **outside** the application repository unless the owner decides
otherwise — the P01B tooling commit (176 files of unrelated design skills) is the precedent for
why.

---

## Still owed before Phase 01 acceptance

Unchanged from [`09-SINGLE-DEVELOPER-TRANSITION.md`](09-SINGLE-DEVELOPER-TRANSITION.md), plus:

| # | Item | Owner action |
|---|---|---|
| 1 | Password-recovery **server exposure** | Confirm whether the route was reachable; block it at the edge until the fix deploys |
| 2 | `storage/logs/api-*.log` on the server | Treat 15 days of it as compromised material; rotate anything found |
| 3 | Operator and administrator passwords | Rotate — they were settable by anyone who knew an email address |
| 4 | S12(c) anonymous `customerpayment` document | Decide: gate it, or accept link-bearer access |
| 5 | Customer-facing documents generally | Decide: scoped expiring token, or accept |
| 6 | Branch protection + required checks on `main` | Repository setting; no script can assert it |
| 7 | `BackupRestore` active on the dev server? | `SELECT module, is_enable FROM add_on_managers` also answers S11's triage |
| 8 | GATE F — six undeclared money tables | Owner/DBA |
