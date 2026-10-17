# CI regression enforcement (A2)

## The honest headline first

**The official CI gate is RED, on this branch and on `main`, and this work does not make it
green.** Nothing here should be read as "CI is passing".

`.github/scripts/assert-known-test-outcomes.php` compares the observed Feature outcome set
against `.github/known-test-outcomes.json`, in both directions: an unlisted failure fails CI, and
a *listed* entry that starts passing also fails CI. That design is correct and is untouched.

It is, however, **stale**. The manifest declares 8 expected failures. The repository's own merge
base, `0d2ca628`, produces more than that — so the gate fails on `main` as well as on anything cut
from it. The manifest's own README forbids the obvious shortcut:

> Do NOT add it to `.github/known-test-outcomes.json` to get green — that file records failures
> the owner has accepted with a named root cause, not a list of things currently broken.

So the manifest was not touched. Reconciling it is an owner decision, and it remains open.

## What was measured

| | failures |
|---|---|
| Merge base `0d2ca628`, `tests/Feature/Tenancy` | **6** |
| This branch, `tests/Feature/Tenancy` | **5** — the same 5, plus one the base has that this branch resolved |
| Manifest declares | **8** (all `Auth`/`Profile`, none of the tenancy census) |

Every census failure drifts *downward* (`app` 114 → 113 raw-query entry points; 14 → 12 models
without a table). That is the signature of code being removed, consistent with Phase 1 retiring
the legacy Chatwoot integration, and it is inherited rather than introduced.

## What was built instead

A **merge-base regression gate**, additive and temporary. It answers the one question a reviewer
needs while the manifest is stale: *did this branch break something that worked at the merge
base?*

- `.github/scripts/regression-gate.php` — runs the comparison.
- `regression-gate` job in `.github/workflows/ci.yml` — runs the Feature suite at HEAD **and** at
  the merge base, from a single dependency install so a dependency difference cannot masquerade
  as a code regression.
- `tests/Unit/Ci/RegressionGateTest.php` — 7 tests on the comparator itself.

### How it decides

It compares failing test **identities**, never counts. A branch that resolves one failure and
introduces another keeps an identical total and must still fail; that case is pinned by
`test_one_fixed_and_one_broken_still_fails`.

Each failing identity lands in exactly one bucket:

| bucket | meaning | effect |
|---|---|---|
| `new_failures` | fails at HEAD, passed at the merge base | **fails the gate** |
| `resolved_failures` | failed at the merge base, passes at HEAD | reported |
| `inherited_accepted_failures` | fails at both, and is in the manifest | reported |
| `inherited_unaccepted_drift` | fails at both, **not** in the manifest | reported as a warning on every run |

`inherited_unaccepted_drift` is the stale-manifest population. **The gate does not accept it.**
It prints a warning on every run stating that these remain unresolved defects and must not be
added to the manifest to obtain green.

### Outputs

- Human-readable: a summary table plus `::error::` / `::warning::` annotations.
- Machine-readable: `storage/logs/regression-gate.json`, schema
  `storesyria.regression-gate/1`, uploaded as a CI artifact alongside both JUnit files.

The JSON carries an `official_gate` block with the real exit code of
`assert-known-test-outcomes.php` and a status of `passing` / `BLOCKED` / `not_run`. When the
official gate is blocked the script prints `CI IS NOT GREEN` and `OVERALL: NOT GREEN`, so a
passing regression gate can never be mistaken for a passing build.

### Determinism

Both runs use the same commit of the test code? No — deliberately not. Each run uses *its own*
tree's tests, which is what makes "did this branch break something" meaningful. What is held
constant is the dependency set (one `composer install`, shared by both runs via a symlinked
`vendor/`), the database (`sqlite :memory:`, hard-guarded), the PHP version and the extension
list. An empty or truncated JUnit is refused with exit 2 rather than being read as "no failures".

## Measured verdict on this branch

Run against the merge base `0d2ca628` (1398 testcases) and this branch (1558 testcases):

```
new failures    : 0
resolved        : 4
inherited (accepted in manifest) : 8
inherited (UNACCEPTED drift)     : 5
VERDICT: no new failures introduced by this branch.
OVERALL: NOT GREEN -- the official gate remains blocked.
```

The 4 resolved are the legacy-Chatwoot route assertions Phase 1 retired. The 5 unaccepted drift
are the tenancy-census tests. **The official gate exits 1 and the build is not green.**

A bug in the comparator was found by running it against the real manifest rather than a fixture:
`expected_failures` is an object *keyed by test id* with the accepted cause as the value, and the
first implementation iterated the values, classifying all 13 inherited failures as unaccepted
drift — inverting the one distinction this gate exists to draw. Fixed, and pinned by
`test_it_reads_the_manifests_real_object_keyed_shape`.

## Audit preservation (requirement 8)

`tests/Feature/Lynomia/LynomiaAuditPreservationTest.php` pins the module's audit surface:

- all nine required audit actions are still emitted, each with a plain-English statement of the
  compliance fact it records;
- no audit call site references a credential;
- the event ingress still logs every refusal, by distinct reason.

It reads source rather than behaviour, deliberately: the failure mode being guarded against is
**deletion**, and deleted code has no behaviour to assert on. This test family exists because the
Phase 2 defect — an overwritten file that removed a channel-configuration audit trail — produced
a `200` response and a fully green feature suite.

It found a real miss within minutes of being written: the ingress controller's
*signature-failure* branch, the single most important refusal to observe, still returned a bare
401 with no log line. Fixed.

## Human diff review (requirement 8)

- `.github/SECURITY-DIFF-REVIEW.md` — the checklist, which opens with the incident that caused it.
- `.github/scripts/flag-sensitive-diff.sh` — flags a diff touching audit, middleware, routes,
  policies, permissions, billing/entitlement/seat/plan, signature/secret/credential/token, config
  or `.github/`, prints the changed files **ranked by deletions**, and fails unless the pull
  request body carries `SECURITY-DIFF-REVIEW: completed`.

Ranking by deletions is the point. Every Phase 2 defect was a removal, and a reviewer scanning
additions would have missed all three.

## What this gate does not do

- It does not weaken any existing gate. The five original CI jobs are unchanged; the official
  known-outcome gate still runs and its verdict is reported, never suppressed.
- It does not accept inherited failures.
- It does not replace the owner's reconciliation of the manifest.

**It should be deleted once the manifest is reconciled.** Until then it is a detection mechanism
operating around a known-stale gate, not a substitute for fixing it.
