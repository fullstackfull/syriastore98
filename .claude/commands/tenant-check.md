---
description: Run the existing tenancy and authorization test suites and interpret the result against the known-outcome manifest
argument-hint: "[PHPUnit --filter pattern, e.g. OrderDocument or CredentialBoundary]"
allowed-tools: Bash(php artisan test:*), Bash(php .github/scripts/assert-known-test-outcomes.php:*), Bash(ls:*), Bash(grep:*), Read, Grep, Glob
---

# /tenant-check

Run the tenancy and authorization checks this repository already has. **Do not write new tests
here** and do not reimplement any part of the test framework — this command only orchestrates what
exists.

`$ARGUMENTS` is an optional PHPUnit `--filter` pattern. If it is empty, run the full set.

## What to run

**1. The targeted suites** — these are the tenancy and authorization bodies of work:

```bash
php artisan test --testsuite=Unit    tests/Unit/Tenancy
php artisan test --testsuite=Feature tests/Feature/Tenancy
php artisan test --testsuite=Feature tests/Feature/Security
```

With a filter, pass it through instead and skip the rest:

```bash
php artisan test --testsuite=Feature --filter=$ARGUMENTS
```

**2. The Contract suite**, whenever the change touched `routes/api.php` or any `Api\*` controller —
it is what protects the shipped Flutter app's request and response shapes:

```bash
php artisan test --testsuite=Contract
```

**3. The known-outcome gate**, whenever the whole Feature suite was run. The suite is **not green
and is not meant to be**: it carries 2 accepted failures and 17 accepted skips declared by name in
`.github/known-test-outcomes.json`. Reading "2 failed" as a problem is a misreading; the gate is
what decides.

```bash
php artisan test --testsuite=Feature \
  --log-junit storage/logs/feature-junit.xml \
  --log-teamcity storage/logs/feature-teamcity.txt
php .github/scripts/assert-known-test-outcomes.php \
  storage/logs/feature-junit.xml .github/known-test-outcomes.json \
  storage/logs/feature-teamcity.txt
```

## How to interpret it

- **Gate exit 0** — the outcome set matches the manifest exactly. That is the pass condition. It is
  *not* a statement that the suite is green.
- **An undeclared failure** — a regression. Fix it; do not add it to the manifest. Adding an entry
  to obtain green is the one use of that file its own README forbids.
- **A declared failure that now passes** — also a gate failure, and it means someone fixed
  something without recording it. Remove the entry and say what fixed it.
- **A new skip** — the gate declares the skip set too, so a conditionally-skipped test breaks it.
  Evidence that cannot run inside the suite belongs in `tests/Support/Security/bin/` as a script
  that states its own preconditions.
- **Test count fell** — a test disappeared. Find out which before doing anything else. The junit
  log lists every testcase; diffing the names against a clean checkout is how to tell.

## After the suites pass

Two checks the suites cannot make. Do them, and say which you did:

1. **Did the new tests bite?** Revert the fix, re-run just those tests, confirm they fail, restore.
   A test that passes against the vulnerable code proves nothing — that has happened here more than
   once (a test asserting "the row did not change" passes when the endpoint 500s for everyone; a
   test posting to a route path that does not exist passes on the 404).
2. **Is this change schema-sensitive?** Tests run on SQLite `:memory:`, production is MySQL.
   SQLite's type affinity hides real MySQL defects — that is exactly how the
   `customers.password_otp` column defect survived. If the change touches a column type, a cast or
   a raw query, verify against a real MySQL;
   `tests/Support/Security/bin/verify-sec13-migration-on-mysql.php` shows the pattern and creates
   and drops its own throwaway database.

## What this command does not do

- It does not run migrations against any server, and it must never be made to.
- It does not deploy, push, or merge.
- It is not a substitute for the **`tenant-authz-reviewer`** subagent. The suites catch regressions
  against defects already known; the reviewer is what finds the next one. For a security-sensitive
  change, use both.
