# Resume gate — run on the delivered head `065b4df5`, 2026-10-31

The handoff's §0 told a successor that **the first thing they owe the owner is the full
Known-Outcome Feature Gate on the delivered head**, and that the `e95afda4` figures are a
BASELINE that must not be copied forward as a result. This directory is that gate, executed.

It is **not** the Phase 02 final gate. That evidence is in `../final-gate/` and describes a
different head. This is the resume-point gate, run after the account transfer.

## The result

| suite | result | exit |
|---|---|---|
| Unit | **657 passed** (4094 assertions) | 0 |
| Contract | **26 passed** (83 assertions) | 0 |
| Feature | **2 failed, 17 skipped, 7011 passed** (34876 assertions) | 1 — *expected*, phpunit exits non-zero because two tests fail |
| **Known-Outcome assertion** | **`OK: the observed outcome set matches the manifest exactly`** | **0** |
| MariaDB roster | **passed: 32   failed: 0** | 0 |

The two declared failures appeared by name, exactly as `.github/known-test-outcomes.json`
spells them:

- `Tests\Feature\Auth\RegistrationTest::test_new_users_can_register`
- `Tests\Feature\ProfileTest::test_profile_page_is_displayed`

**Read the assertion script's own caveat rather than the word "passed".** It says, and it is
right: *"This is NOT a statement that the suite is green. It is a statement that the suite is
failing and skipping exactly the tests the project has recorded, for the reasons it has
recorded."* Per **D-30** it is also neither owner acceptance nor release readiness.

## It took two runs, and both are here, including the one that failed

| run | outcome | testcases | assertion |
|---|---|---|---|
| 1 — clean clone | 3 failed / 17 skipped / 7010 passed | 7030 | **`ASSERT_EXIT=1`** — one undeclared failure |
| 2 — precondition restored | 2 failed / 17 skipped / 7011 passed | 7030 | `ASSERT_EXIT=0` |

Durations: 1713.25s and 1640.76s.

**The arithmetic ties in both directions** — `7010 + 3 + 17 = 7030` and `7011 + 2 + 17 = 7030` —
so **exactly one test flipped between the runs and nothing else moved.** That is what makes the
diagnosis below a measurement rather than a story: restoring the symlink changed only the one
outcome it was claimed to change.

## The undeclared failure was the environment, not the code (F238)

Run 1 produced a third failure:

```
Tests\Feature\Security\PaidDownloadEntitlementTest::test_the_old_public_path_is_still_reachable_and_that_is_why_f217_is_not_closed
  the paid file is no longer reachable through public/uploads.
  Failed asserting that file ".../public/uploads/1/ss-f217-....zip" exists.
  at tests/Feature/Security/PaidDownloadEntitlementTest.php:210
```

That test is a deliberate **characterization** test: it asserts the F217 exposure *still exists*,
so that whoever contains it has to come and say so. Its own docblock (`:203-208`) names the
mechanism — *"What makes the file public is that `public/uploads` is a symlink into the uploads
root"*.

But **`/public/uploads` is gitignored** (`.gitignore:8`), so it is absent from every fresh clone.
In the container it existed only as an **empty real directory**, which is why the test's own
"is it absent?" guard at `:205` passed and only the file lookup at `:210` failed. Reinforcing it:
`public/storage` is tracked at mode **100755 — a regular file, not a `120000` symlink** — holding
the literal production path `/var/www/html/Product/EcommerceGo/main_file/storage/app/public`. The
repository carries no working symlinks at all.

**So the `e95afda4` baseline was produced in an environment where someone had created that symlink
by hand, and that precondition is undocumented.** The authoritative baseline is therefore not
reproducible from a clean checkout without rediscovering it — a gate-integrity issue, not a code
defect.

### How it was resolved, and how it was NOT

```bash
rm -rf public/uploads && ln -s ../uploads public/uploads
```

Uncommittable, because the path is gitignored — hence this note, which is the only durable place
to record it.

It was **not** resolved by declaring a third failure in `.github/known-test-outcomes.json`. The
gate script itself says why, and the instruction was followed rather than worked around:

> `::error::This is a regression unless you can show otherwise. Do NOT add it to`
> `::error::.github/known-test-outcomes.json to get green -- that file records failures the owner`
> `::error::has accepted with a named root cause, not a list of things currently broken.`

"Showing otherwise" is run 2 plus the arithmetic above.

A conditional skip was not an option either: the gate asserts the declared skip **set** in both
directions, so a skipped test would have broken it just as a new failure did.

**This is not a remediation of F217**, which remains OPEN. Creating the symlink makes the tracked
`uploads/` tree (407 committed files — P1-OWN-10) web-visible wherever a web server serves
`public/`. Harmless here because no web server runs in the container.

### Worth proposing, deliberately not landed here

Changing a committed security test's semantics is not a documentation fix, so it is recorded rather
than done:

1. the test should tell *"`public/uploads` is not a symlink into the uploads root"* apart from
   *"the file is gone"*. As written, an empty directory produces a failure that reads as though the
   containment had landed. A premise assertion such as `is_link(public_path('uploads'))`, with its
   own message, would make the precondition self-documenting.
2. the precondition belongs in the gate runbook and in a CI setup step, so the gate is reproducible
   by whoever runs it next.

## MariaDB — the "32/32" claim now has a log behind it (F239)

"MariaDB 32/32" appears in `PHASE-02-HANDOFF.md:29`, `PHASE-02-DECISION-REGISTER.md:2420` and
`PHASE-02-ACCEPTANCE-REPORT-AR.md:21` as part of the authoritative baseline. The roster arithmetic
was right — `grep -c '^run_one '` gives 29 at `360b474a` and **32** at both `e95afda4` and
`065b4df5` — but **no committed log recorded a 32-script run**, and the numbers disagreed three ways:

| artifact | number |
|---|---|
| the roster script itself | **32** |
| `../final-gate/mysql-roster.txt` | `passed: 28   failed: 0` |
| `../final-gate/README.md:30`, describing that same file | "all **27** MariaDB verification scripts" |
| `../final-gate/gate-e95afda4.txt` — the gate file for that very head | **no MariaDB section at all** |
| `../final-gate/mariadb-roster-360b474a.txt` | `passed: 29` — the only log that matches its own roster |

`mariadb-roster-065b4df5.txt` in this directory is a real executed run of all **32** on the
delivered head: **`passed: 32   failed: 0`**, on
`10.11.14-MariaDB-0ubuntu0.24.04.1` — the same engine version `../final-gate/README.md:70` records.

So the figure is now measured rather than restated. The earlier documents' 27/28/32 disagreement
is left as it stands rather than retro-fitted, because what those runs actually measured is not
recoverable now.

### Reproducing it

`run-mysql-verifications.sh` needs no arguments when a local server matches its defaults
(`:32-34`, `:47-51`): socket `/var/run/mysqld/mysqld.sock`, user `root`, empty password. Each
script creates and drops its own throwaway database.

```bash
bash tests/Support/Security/bin/run-mysql-verifications.sh
```

## Files

| file | what it is |
|---|---|
| `known-outcome-assertions.txt` | both assertion runs in full, **including the one that failed**, with the script's own error text |
| `feature-totals.txt` | the Feature totals and the failing-test lines from both runs |
| `unit-contract.txt` | Unit and Contract output |
| `mariadb-roster-065b4df5.txt` | the full 32-script MariaDB roster run |

## What this gate does not cover

- **Flutter**: run separately. CI pins `FLUTTER_VERSION: 3.47.7` (`.github/workflows/ci.yml:190`),
  which is what must be used — `pubspec.lock`'s `sdks:` block records only a Dart `>=3.11.0` floor,
  and resolving that floor to its oldest satisfying release (3.41.x) makes
  `flutter pub get --enforce-lockfile` refuse with *"Unable to satisfy `pubspec.yaml` using
  `pubspec.lock`"*. Use the pinned version, not the floor.
- **The owner's outstanding gates.** Phase 01's release gates in
  `../../phase-1/PHASE-01-CLOSURE-EXECUTION.md` are not cleared, and security incident #120 remains
  deferred by the owner and open. Neither is touched by this gate.
