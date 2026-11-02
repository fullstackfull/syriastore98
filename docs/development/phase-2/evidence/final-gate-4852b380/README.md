# Phase 02 final verification — run on the frozen head `4852b380`, 2026-11-01

The owner's final completion directive asked for comprehensive verification **once, at the end, on a
frozen HEAD**. This directory is that run.

| repository | branch | final SHA |
|---|---|---|
| `fullstackfull/syriastore98` | `claude/store-syria-p02-continuation-smlpwi` | `4852b380dbbd01f12bf29b4e660fc48279a12403` |
| `fullstackfull/StoreSyria_app98` | `claude/store-syria-p02-continuation-smlpwi` | `f083da87e5cd06b1a641937fd499099a90bd3359` |

Both working trees were clean at measurement time and no file was changed while any suite was
running.

## The result

| suite | result | exit | log |
|---|---|---|---|
| Unit | **668 passed** (4,142 assertions) | 0 | [`unit.txt`](unit.txt) |
| Contract | **27 passed** (91 assertions) | 0 | [`contract.txt`](contract.txt) |
| Feature | **2 failed, 17 skipped, 7,324 passed** (36,175 assertions) | 1 — *expected*, phpunit exits non-zero because two tests fail | [`feature-gate.txt`](feature-gate.txt) |
| **Known-Outcome assertion** | **`OK: the observed outcome set matches the manifest exactly`** | **0** | same file, tail |
| MariaDB roster (35 scripts) | **passed: 35   failed: 0** | 0 | [`mariadb-roster.txt`](mariadb-roster.txt) |
| Flutter `flutter test` | **1,358 passed** | 0 | [`flutter-test.txt`](flutter-test.txt) |
| Flutter `flutter analyze` | **247 info, 0 warning, 0 error** | 1 (infos only) | [`flutter-analyze.txt`](flutter-analyze.txt) |
| Flutter `flutter build bundle --release` | **compiled** | 0 | — |
| Browser — E13 replace control | **211 checks, 0 failures, 9 configurations** | 0 | [`../e13-order-replace/`](../e13-order-replace/) |
| Performance baseline | **12 measurements, 0 skipped** | 0 | [`../performance-baseline/`](../performance-baseline/) |

The two declared failures appeared by name, exactly as `.github/known-test-outcomes.json` spells
them:

- `Tests\Feature\Auth\RegistrationTest::test_new_users_can_register`
- `Tests\Feature\ProfileTest::test_profile_page_is_displayed`

**Read the assertion script's own caveat rather than the word "OK".** It says, and it is right:
*"This is NOT a statement that the suite is green. It is a statement that the suite is failing and
skipping exactly the tests the project has recorded, for the reasons it has recorded."* Per **D-30**
it is neither owner acceptance nor release readiness, and this document claims neither.

## The first pass of this run FAILED, and that is the point of running it

The gate was run twice. The **first** pass, on `401a185c`, reported **3 failures** — one undeclared:

```
::error::NEW FAILURE, not in the manifest:
  Tests\Feature\Security\SecurityLogInventoryTest::test_the_observability_inventory_matches_the_source
```

Two regressions of my own were behind that pass, both introduced by this cycle's own E13 commit
(`33a12e38`) and both caught by the project's ratchets rather than by review:

1. **Four English-only strings on an Arabic merchant's screen**, on a destructive action
   (`ArabicUiCoverageTest`). Fixed in `ar.json` **and** `en.json` — the first attempt used `ar.json`
   alone and `TranslationFileIntegrityTest` then failed, because ar-only keys are a tracked debt
   capped at 31.
2. **Twelve observability-inventory entries pointing at stale line numbers.** The same commit
   inserted code in `order_view()` and shifted every later line in `OrderController.php` by 31; the
   inventory is line-addressed. Relocated by re-deriving each line **from the source**, not by
   adding 31 — the script refused to guess wherever a message legitimately appears at several lines
   (24 such entries left untouched). Every message is byte-identical.

Neither was added to `known-test-outcomes.json`. The gate's own error text forbids exactly that:
*"Do NOT add it to .github/known-test-outcomes.json to get green — that file records failures the
owner has accepted with a named root cause, not a list of things currently broken."*

Logs for the failing pass are not kept separately; the fixes and the reasoning are in commit
`4852b380`, and this directory holds the passing run that followed.

## Reproducing it

**The F238 precondition applies.** `public/uploads` must be a symlink into the uploads root or
`PaidDownloadEntitlementTest`'s F217 characterization case fails; the path is gitignored, so a fresh
clone does not have it. See [`../resume-gate-065b4df5/README.md`](../resume-gate-065b4df5/README.md).
It was satisfied in this container (`public/uploads -> ../uploads`), verified before the run.

```bash
php artisan test --testsuite=Feature \
  --log-junit storage/logs/feature-junit.xml \
  --log-teamcity storage/logs/feature-teamcity.txt
php .github/scripts/assert-known-test-outcomes.php \
  storage/logs/feature-junit.xml .github/known-test-outcomes.json \
  storage/logs/feature-teamcity.txt

php artisan test --testsuite=Unit
php artisan test --testsuite=Contract

MYSQL_SOCK=/run/mysqld/mysqld.sock MYSQL_USER_NAME=root MYSQL_PASS= \
  bash tests/Support/Security/bin/run-mysql-verifications.sh
```

## What this run does NOT establish

- **`flutter build apk` was not run** — this container has no Android SDK (`flutter doctor` says so).
  What ran is `flutter build bundle --release`, which compiles every reachable Dart file in release
  mode without a platform toolchain. That is a container limit, not a statement about the code, and
  it is recorded rather than worked around.
- **The Flutter toolchain moved** since the Phase 02 baseline: **3.47.7** here against the
  **3.41.2 / Dart 3.11.0** recorded at `P2-00-4`. The analyzer reads 247 info against that
  baseline's 248 — one fewer, no regression — but the comparison is across toolchain versions.
- **PHPUnit cannot prove the idempotency claims' atomicity.** `phpunit.xml` sets
  `CACHE_STORE=array`, and `ArrayStore` has no `add()`, so `Cache::add()` degrades to a
  get-then-put. Atomicity is proven only by the MariaDB scripts in the roster above, where the
  second concurrent caller blocks on the `cache` table's primary-key row lock.
- **No production figures of any kind**, and no production access was used or implied.
- The **performance baseline** is a same-hardware starting point measured against the PHP built-in
  server and a SQLite fixture. Its absolute values are not production figures; see its own README.
