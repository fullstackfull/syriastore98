# Session-closing gate — `f93de37f`, 2026-10-31

The **attributable** gate for this session's work. Run on a tree verified clean at launch
(`TREE_DIRTY_FILES=0`), which is the point: two earlier runs in this session overlapped edits that
were still in flight, so their numbers describe no particular commit. Those are kept for honesty
but are **not** the result.

## The result

| suite | result | exit |
|---|---|---|
| Feature | **2 failed, 17 skipped, 7035 passed** (34942 assertions) | 1 — *expected*, two declared tests fail |
| Known-Outcome assertion | **`OK: the observed outcome set matches the manifest exactly`** | **0** |
| Unit | **657 passed** (4094 assertions) | 0 |
| Contract (Flutter/Lynomia) | **26 passed** (83 assertions) | 0 |
| MariaDB roster | **passed: 33   failed: 0** on `10.11.14-MariaDB` | 0 |

Duration 1678.94s. `7035 + 2 + 17 = 7054` testcases observed, against the manifest floor of 1000.

The two declared failures appeared by name, exactly as `.github/known-test-outcomes.json` spells
them: `RegistrationTest::test_new_users_can_register` and
`ProfileTest::test_profile_page_is_displayed`.

**Read the assertion script's own caveat, not the word "passed":** *"This is NOT a statement that
the suite is green. It is a statement that the suite is failing and skipping exactly the tests the
project has recorded, for the reasons it has recorded."* Per **D-30** it is also neither owner
acceptance nor release readiness.

## What the numbers say about the session

| | testcases | passed |
|---|---|---|
| `065b4df5` — the delivered head, baseline for this session | 7030 | 7011 |
| `f93de37f` — session close | **7054** | **7035** |

**+24 testcases, +24 passing, and the declared failure and skip sets never moved** — 2 and 17 at
both ends. Every test added this session passes, and nothing that was passing stopped.

## The roster is 33, not 32

`run-mysql-verifications.sh` gained `verify-plan-coupon-refusal-ordering-on-mysql.php` (F234),
which exists because the Feature suite **cannot** answer that question:
`whereRaw('BINARY `code` = ?')` is MySQL-only and a *syntax error* on SQLite, so a Feature test
asserting "no coupon use was persisted" passes for a reason with nothing to do with the defect.
Two such tests were written, both passed, and both were deleted once driven.

`F239` in `../resume-gate-065b4df5/README.md` records why the previous "MariaDB 32/32" claim had no
log behind it at all.

## One precondition this gate needs and cannot carry

`public/uploads` must be a **symlink** into the uploads root, or
`PaidDownloadEntitlementTest`'s F217 characterization case fails. The path is **gitignored**, so it
cannot be committed and no fresh clone has it:

```bash
rm -rf public/uploads && ln -s ../uploads public/uploads
```

That is **F238**, documented in `../resume-gate-065b4df5/README.md`. It is the reason the previous
"authoritative" baseline was not reproducible from a clean checkout.

## Reproducing

```bash
php artisan test --testsuite=Feature \
  --log-junit storage/logs/feature-junit.xml \
  --log-teamcity storage/logs/feature-teamcity.txt
php .github/scripts/assert-known-test-outcomes.php \
  storage/logs/feature-junit.xml .github/known-test-outcomes.json \
  storage/logs/feature-teamcity.txt
php artisan test --testsuite=Unit
php artisan test --testsuite=Contract
bash tests/Support/Security/bin/run-mysql-verifications.sh
```

**Run it on a clean tree and record the HEAD.** A gate whose tree moved underneath it measures
nothing in particular — a mistake made twice in this session before it was caught.

## What this gate does NOT cover

- **Flutter.** CI pins `FLUTTER_VERSION: 3.47.7` (`.github/workflows/ci.yml:190`). `pubspec.lock`'s
  `sdks:` block records only a Dart `>=3.11.0` floor, and resolving *that floor* to its oldest
  satisfying release (3.41.x) makes `flutter pub get --enforce-lockfile` refuse with *"Unable to
  satisfy `pubspec.yaml` using `pubspec.lock`"*. Use the pinned version, not the floor. No Flutter
  code was changed this session.
- **The owner's outstanding gates.** Phase 01's release gates in
  `../../phase-1/PHASE-01-CLOSURE-EXECUTION.md` are not cleared, and security incident #120 remains
  deferred by the owner and open. Neither is touched.
- **Anything not built.** E13-D, E13-E/F and E14's F226/F227 are not started; F229's permanent fix
  is scoped (`../../F229-PERMANENT-FIX-PLAN.md`) and not built, because D-31 requirement 1 — the
  server-owned payment intent — does not exist and requirement 4's binding has no subject without
  it. F231 is **BLOCKED** on one captured provider response.
