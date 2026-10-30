# Final full gate — HEAD `7173ebb8`

Owner directive §7. Every number here was produced on that commit, not on an earlier tree.

| file | what it is |
|---|---|
| `feature-known-outcome-gate.txt` | the **official** Feature gate — the manifest assertion, not a shortened substitute |
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

- **Flutter: nothing was executed.** `ci.yml` fires only on pull requests and on pushes to
  `main`/`master`, and no Flutter toolchain exists in this container. Every Flutter claim in the
  ledger rests on reading code. (F159, unchanged.)
- **Not every variant-deletion path is closed.** The vendored `AuctionProductController::update()`
  path (F200) is live and bypasses the hard blocks, not merely the confirmation. §4.7 forbids
  editing it.
- **Not production readiness.** A passing gate is necessary evidence, not proof of a releasable
  build. Phase 01's closure gates and Issue #120 are untouched and still owed.
