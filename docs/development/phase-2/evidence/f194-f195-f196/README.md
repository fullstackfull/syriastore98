# Evidence — F194 / F195 / F196 (owner directive of 2026-10-29)

## §1 — the official Known-Outcome Gate, as the directive asks it to be recorded

The directive requires the **official** gate, with the same manifest and the same assertions, and
no shortened substitute. Two runs are recorded here. Both used the project's own command and the
project's own checker; neither weakened anything.

| | baseline | after F194 + F197 |
|---|---|---|
| **evidence file** | `gate-baseline-aea28d08.txt` | `gate-after-f194-f197.txt` |
| **HEAD tested** | `aea28d082046c9dbc2013e8b96dfb72f7ba76c81` | the tree committed as **`ba34fce8`** (the run began before the commit, so the file's header names the parent `de31ce35`; see the note at the top of that file) |
| **command** | `php artisan test --testsuite=Unit` · `--testsuite=Contract` · `--testsuite=Feature --log-junit storage/logs/feature-junit.xml --log-teamcity storage/logs/feature-teamcity.txt` · then `php .github/scripts/assert-known-test-outcomes.php storage/logs/feature-junit.xml .github/known-test-outcomes.json storage/logs/feature-teamcity.txt` | identical |
| **Unit** | 646 passed (4,054 assertions), 36.96s | 650 passed (4,071 assertions), 26.16s |
| **Contract** | 26 passed (83 assertions), 3.06s | 26 passed (83 assertions), 3.10s |
| **Feature** | discarded — see below | 6,733 passed, 2 failed, 17 skipped (33,799 assertions), 1,084.16s |
| **testcases observed** | — | 6,752 (manifest floor 1,000) |
| **declared failures** | — | 2 observed / 2 declared: `new users can register`, `profile page is displayed` |
| **declared skips** | — | 17 observed / 17 declared, every reason parsed from the TeamCity log |
| **undeclared failures / errors** | — | **0 / 0** |
| **gate verdict** | — | `OK: the observed outcome set matches the manifest exactly` — `gate exit: 0` |

### The baseline Feature run was discarded, and why that is recorded rather than hidden

The directive asks that a new failure be distinguished from the two known ones and dealt with
before the baseline is called stable. Two appeared in the baseline attempt —
`RoleEscalationTest::test_role_update_cannot_grant_superadminaccess_permissions` and
`ResidualSafetyCorrectionsTest::test_a_coupon_code_is_still_case_sensitive` — and **the run itself
caused them.** A test of this session's own writing drove `/config-cache`, which calls
`Artisan::call('optimize')`, which **writes** `bootstrap/cache/config.php`, `events.php` and
`routes-v7.php`. Those files persist on disk and poison every later test in the run and in any
run after it. Both tests passed in isolation; deleting the three files restored the file they were
in to 42/42. The run was therefore discarded as contaminated rather than reported, and `aea28d08`'s
real outcome is the declared 2.

That hazard is itself fixed — see the F197 section of `PHASE-02-TEST-EVIDENCE.md` — and the test
that caused it no longer calls `optimize`.

### What these numbers are not

A passing gate is **not** a statement that the suite is green. It states that the suite fails and
skips exactly the tests the project has recorded, for the recorded reasons. The 2 failures and 17
skips are real and are never hidden: `.github/known-test-outcomes.json` names each one and who owes
it. Neither run is a statement about production readiness.

## Files

| file | what it is |
|---|---|
| `gate-baseline-aea28d08.txt` | the §1 baseline run |
| `gate-after-f194-f197.txt` | the §1 run on the tree committed as `ba34fce8`, gate exit 0 |
| `f196-local-suites.txt` | F196's per-slice runs: the finished test file against the pre-fix controller, the two bites, and the regression suites |

MariaDB evidence lives with the scripts, in `tests/Support/Security/bin/`:
`verify-model-cache-isolation-on-mysql.php` (F194, 16 checks),
`verify-login-and-settings-cache-paths-on-mysql.php` (F197, 8 checks) and
`verify-empty-variant-name-wipe-on-mysql.php` (F199, 13 checks). Their results are quoted in
`PHASE-02-TEST-EVIDENCE.md`. All three, plus the F194 flush-scope script, were **off** the
`run-mysql-verifications.sh` roster and were added to it in the F196 slice; the whole roster then
read **27 passed / 0 failed** on MariaDB 10.11.14.
