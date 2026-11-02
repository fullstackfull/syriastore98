# The Known-Outcome Feature Gate — and how it proves it measured one tree

Two runs are kept here. `gate-98d6828c.txt` is the later and authoritative one, taken after the
third review round landed; `gate-18ebbf46.txt` is the earlier one, kept because a superseded
measurement is still a measurement and deleting it would leave only the prose.

```
HEAD_AT_LAUNCH  = 98d6828c84ba1a38796b9dde2837055de5261bc1
DIRTY_AT_LAUNCH = 0
Tests:    2 failed, 17 skipped, 7113 passed (35189 assertions)
testcases observed : 7132   failures : 2 (declares 2)   skips : 17 (declares 17)
OK: the observed outcome set matches the manifest exactly.
ASSERT_EXIT=0
HEAD_AT_FINISH  = 98d6828c84ba1a38796b9dde2837055de5261bc1
DIRTY_AT_FINISH = 0
```

## The earlier run, on `18ebbf46`

```
HEAD_AT_LAUNCH  = 18ebbf46e4ee9a900a21499df4156c9a1c21c669
DIRTY_AT_LAUNCH = 0

Tests:    2 failed, 17 skipped, 7109 passed (35174 assertions)
FEATURE_EXIT=1

testcases observed   : 7128  (manifest floor 1000)
failures observed    : 2  (manifest declares 2)
skips observed       : 17  (manifest declares 17)
skip reasons parsed  : 17 from final-teamcity.txt
OK: the observed outcome set matches the manifest exactly -- 2 declared failures,
    17 declared skips, 0 errors, and nothing undeclared.
ASSERT_EXIT=0

HEAD_AT_FINISH  = 18ebbf46e4ee9a900a21499df4156c9a1c21c669
DIRTY_AT_FINISH = 0
```

## Why the HEAD and dirty-count are recorded **twice**

Because an earlier run in this session recorded them **once, at launch**, and was void for it.

`scratchpad/f229-feature.txt` is stamped `HEAD=2f011efb` / `TREE_DIRTY_FILES=0` and reports
`3 failed` against a manifest declaring two — an undeclared failure, which fails the gate. Both
stamps were true of the moment the run *started*. The Feature suite then ran for about nine minutes
while review fixes were written to disk, and PHPUnit reads source as it goes, so the run measured a
tree that was changing underneath it.

The failure it reported makes the mechanism plain. `SecurityLogInventoryTest` compares every
`Log::` line in `app/` against `docs/security/P01-OBSERVABILITY-INVENTORY.md` **by `file:line`**, so
it was comparing *post-edit* line numbers against the *pre-edit* document. At the commit itself the
two matched exactly:

```
$ git show 2f011efb:docs/security/P01-OBSERVABILITY-INVENTORY.md | grep -n Paytm
74:| `app/Http/Controllers/PaytmPaymentController.php:175` | Paytm plan purchase refused: ...
$ git show 2f011efb:app/Http/Controllers/PaytmPaymentController.php | grep -n "Log::warning('Paytm"
175:                    Log::warning('Paytm plan purchase refused: ...
```

`175` against `175`. The gate behaved correctly throughout — it caught a real inconsistency. The
error was entirely in **what the result was a measurement of**.

**So a stamp at launch is worse than no stamp at all**, because it makes a mixed-tree run look
commit-pinned. Stamping both ends is what makes the claim checkable: identical HEAD and identical
dirty-count before and after means nothing moved for the duration, and anyone can see that from the
log without trusting the prose around it.

## What the result does and does not say

Read the assertion script's own caveat rather than the word *passed*:

> *"This is NOT a statement that the suite is green. It is a statement that the suite is failing and
> skipping exactly the tests the project has recorded, for the reasons it has recorded."*

`FEATURE_EXIT=1` is **expected**: the Feature suite carries two accepted failures, so PHPUnit exits
non-zero by construction. `ASSERT_EXIT=0` is the authoritative signal, and it asserts the outcome
*set* in both directions — an undeclared failure and an unexpectedly-*passing* declared failure both
fail it. Nothing was whitelisted; the manifest is unchanged.

**D-30 still holds in full.** This is not owner acceptance and not release readiness.

## Movement since the transfer point

| | `065b4df5` (transfer) | `18ebbf46` |
|---|---|---|
| Feature passed | 7011 | **7109** |
| declared failures / skips | 2 / 17 | 2 / 17 |
| Unit | 657 | 657 |
| Contract | 26 | 26 |
| MariaDB roster | 32 / 32 | **34 / 34** |

The 98 new Feature tests are this session's: F237's stock attribution, F234's coupon ordering,
F230's CyberSource amount, F229's purchase intent and Paytm activation across three review rounds,
and E14's F226 idempotency and F227 characterization. The roster grew by the two MariaDB scripts
F229 added, both of which cover questions SQLite cannot answer.

## Reproducing

```bash
php artisan test --testsuite=Feature \
  --log-junit storage/logs/final-junit.xml \
  --log-teamcity storage/logs/final-teamcity.txt
php .github/scripts/assert-known-test-outcomes.php \
  storage/logs/final-junit.xml .github/known-test-outcomes.json \
  storage/logs/final-teamcity.txt
```

`public/uploads` must be a symlink into the uploads root first — that is **F238**, and it is why
the gate is not reproducible from a clean clone without it:

```bash
[ -L public/uploads ] || ln -s "$(pwd)/storage/app/public/uploads" public/uploads
```
