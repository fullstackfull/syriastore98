# Final full gate — run on `7173ebb8`, re-run on the final HEAD `ae4251de`

Owner directive §7, and specifically the standing instruction that **nothing substitutes for the
full gate on the FINAL HEAD** — a passing Merchant or Security run is not that gate.

The gate was run twice, and the second run is the one that satisfies §7:

| run | commit | what that commit was |
|---|---|---|
| first | `7173ebb8` | the F184 engineering commit |
| **second** | **`ae4251de`** | **the final HEAD** — the first run's evidence, the Arabic report, the F206 ledger row, and one verification script |

Both produced the identical outcome set. The second run exists because the first one's own evidence
had to be committed before it could be the HEAD, and a gate result that predates the tree it claims
to describe is exactly the substitution the directive rules out.

**One commit still follows this file** — the one that adds these two paragraphs and the F206
reference. That it changes nothing any test reads is **verified, not asserted**: no file under
`tests/` reads any `docs/` path. The check is
`grep -nE "(file_get_contents|base_path|realpath|fopen|is_file|file_exists)\([^)]*docs/" tests/ -r`,
which returns nothing; the ten test files that mention `PHASE-02-` cite it in a docblock.

Every number below was produced on `ae4251de` unless the table above says otherwise.

| file | what it is |
|---|---|
| `feature-known-outcome-gate.txt` | the **official** Feature gate on `7173ebb8` — the manifest assertion, not a shortened substitute |
| `feature-known-outcome-gate-final-head.txt` | the same gate re-run on the final HEAD `ae4251de` |
| `local-suites.txt` | Unit, Contract, Security, Merchant, Commerce, Tenancy |
| `mysql-roster.txt` | all 27 MariaDB verification scripts |

Browser evidence lives in `../f184/` (229 checks, five configurations, five screenshots).

## The gate

```
testcases observed   : 6808  (manifest floor 1000)
failures observed    : 2  (manifest declares 2)
errors observed      : 0  (always expected: 0)
skips observed       : 17  (manifest declares 17)
OK: the observed outcome set matches the manifest exactly
gate exit 0
```

Identical on both runs: 6808 / 2 / 0 / 17, `gate exit 0`, `2 failed, 17 skipped, 6789 passed
(34102 assertions)` — 1332.73s on `7173ebb8`, 1318.25s on `ae4251de`.

`php artisan test` itself exits **1**, and that is expected: the suite carries two declared
failures. The gate script is the authority, and it asserts the outcome *set* in both directions —
an undeclared failure and an unexpectedly-passing declared failure both fail it.

The two declared failures, by name, so nobody has to go looking:

- `Tests\Feature\Auth\RegistrationTest::test_new_users_can_register`
- `Tests\Feature\ProfileTest::test_profile_page_is_displayed`

Neither was hidden and the manifest was not edited.

## Suites

```
Unit        650 passed  (4071 assertions)
Contract     26 passed  (  83 assertions)
Security   4361 passed,  4 skipped (18386 assertions)
Merchant    389 passed  (2459 assertions)
Commerce    709 passed,  7 skipped ( 2594 assertions)
Tenancy     439 passed,  6 skipped ( 1601 assertions)
```

## MariaDB 10.11.14

**27 scripts, 27 passed, 0 failed.** One failed on the first run —
`verify-product-ownership-on-mysql.php` — and it was a **stale expectation, not a broken fix**: it
asserted that the platform operator writes nothing through `update()`, which was true only while
the gate was `isAbleTo('Edit Products')`, a check that refuses the operator outright. F195 replaced
that with a per-store read that admits the operator, making the `PLATFORM_ADMIN` exemption already
written into the ownership guard reachable for the first time.

That is a **capability change** — the operator can now edit any store's product through the
merchant web door — and it is recorded as **F206** rather than left to be inferred from a green
run. The script now asserts the new behaviour with the whole account in its own comment.

## What this gate does NOT establish

- ~~**Flutter: nothing was executed.**~~ **CORRECTED [2026-10-30] — that line was wrong, and it
  was my error.** It contradicted this repository's own F159 ledger row, which was closed on
  2026-10-28 and re-verified on 2026-10-29. CI **did** run and **did** pass: run
  [40735373693](https://github.com/fullstackfull/StoreSyria_app98/actions/runs/40735373693) on
  `head_sha 7ef0b6b4`, which is the Phase 02 Flutter HEAD, through draft PR #4 — both jobs green,
  every step. **The conflation worth naming:** "no Flutter SDK in this container" and "no Flutter
  tests ran" are different statements. The first is true; the second is not.
  Reading the real job log also closed a limit the F159 row had left open — it had recorded 1283 as
  a *local* figure and "zero skipped" as unverified, because the log API returns a capped tail. The
  line is now extracted from CI itself: `00:37 +1283: All tests passed!`, with neither a `~N` skip
  marker nor a `-N` failure marker anywhere in the 499,880-character log. **1283 passed, 0 skipped,
  0 failed**, Flutter 3.47.7 / Dart 3.13.5, lockfile enforced, both analyzer gates held. Evidence:
  `../flutter-ci/run-40735373693.txt`.
  What remains true: this gate did not *re-run* Flutter CI, because no Flutter code changed in it.
- **Not every variant-deletion path is closed.** The vendored `AuctionProductController::update()`
  path (F200) is live and bypasses the hard blocks, not merely the confirmation. §4.7 forbids
  editing it.
- **Not production readiness.** A passing gate is necessary evidence, not proof of a releasable
  build. Phase 01's closure gates and Issue #120 are untouched and still owed.
