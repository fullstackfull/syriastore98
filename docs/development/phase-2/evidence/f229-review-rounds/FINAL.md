# The final run on `7cf378f8` — everything, on a tree that provably did not move

```
HEAD_AT_LAUNCH  = 7cf378f893a9dc6650250dc937b44c19ebd2831a
DIRTY_AT_LAUNCH = 0

MariaDB roster  : passed: 35   failed: 0   (ROSTER_EXIT=0)
Unit            : 657 passed (4096 assertions)
Contract        : 27 passed (91 assertions)
Feature         : 2 failed, 17 skipped, 7158 passed (35326 assertions)
                  FEATURE_EXIT=1  <- expected; the suite carries two accepted failures
OK: the observed outcome set matches the manifest exactly -- 2 declared failures,
    17 declared skips, 0 errors, and nothing undeclared.
ASSERT_EXIT=0

HEAD_AT_FINISH  = 7cf378f893a9dc6650250dc937b44c19ebd2831a
DIRTY_AT_FINISH = 0
```

## This is the third attempt at this measurement, and the first two were void

Both for the same reason, and it is worth writing down because it is not an obvious failure mode:
**a gate run is a measurement of a commit only if the tree is quiescent for its whole duration.**
PHPUnit reads source as it goes.

| run | stamp | what went wrong |
|---|---|---|
| `2f011efb` | HEAD + dirty recorded **at launch only** | nine minutes of review fixes landed on disk mid-run; its one "undeclared failure" was post-edit line numbers compared against a pre-edit document. At the commit itself the inventory matched exactly. |
| `44129fe9` | both ends recorded — and `DIRTY_AT_FINISH=1` | I launched it and then kept working. The stamp caught me, which is the whole point of stamping both ends. |
| `7cf378f8` | both ends, both `0` | nothing was touched until it finished. |

The fix is the stamp at **finish**, not better intentions: a stamp at launch alone is worse than none,
because it makes a mixed-tree run look commit-pinned.

## Read the assertion script's own caveat, not the word "passed"

> *"This is NOT a statement that the suite is green. It is a statement that the suite is failing and
> skipping exactly the tests the project has recorded, for the reasons it has recorded."*

`FEATURE_EXIT=1` is expected by construction — two accepted failures mean PHPUnit exits non-zero.
`ASSERT_EXIT=0` is the authoritative signal, and it asserts the outcome *set* in both directions: an
undeclared failure **and** an unexpectedly-passing declared failure both fail it. Nothing was
whitelisted and `.github/known-test-outcomes.json` is unchanged.

**D-30 holds in full. This is not owner acceptance and not release readiness.**

## Movement across the session

| | `065b4df5` (transfer) | `7cf378f8` |
|---|---|---|
| Feature passed | 7011 | **7158** |
| declared failures / skips | 2 / 17 | 2 / 17 |
| Unit | 657 | 657 |
| Contract | 26 | **27** |
| MariaDB roster | 32 / 32 | **35 / 35** |

147 new Feature tests and 1 new Contract test. The roster grew by three scripts: the plan-coupon
ordering and plan-purchase-intent ones for F229, and the order-replacement concurrency one for
E13-F. **The roster count was itself a small correction** — I first recorded 36, having counted the
`run_one() {` function definition as an invocation. 35 are declared, 35 ran, 35 passed, and the
declared-vs-executed name sets were diffed rather than assumed, because a roster reporting 35/35
while 36 are declared would be exactly F239's shape: a number nobody checked.

## Reproducing

```bash
[ -L public/uploads ] || ln -s "$(pwd)/storage/app/public/uploads" public/uploads   # F238

MYSQL_SOCK=/var/run/mysqld/mysqld.sock MYSQL_USER_NAME=root MYSQL_PASS= \
  tests/Support/Security/bin/run-mysql-verifications.sh

php artisan test --testsuite=Unit
php artisan test --testsuite=Contract
php artisan test --testsuite=Feature \
  --log-junit storage/logs/fin-junit.xml --log-teamcity storage/logs/fin-tc.txt
php .github/scripts/assert-known-test-outcomes.php \
  storage/logs/fin-junit.xml .github/known-test-outcomes.json storage/logs/fin-tc.txt
```

And do not edit the tree while it runs.
