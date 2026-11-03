# Phase 02 — development integration closure record

**Owner authorization: 2026-11-03.** Integration executed the same day. Every SHA below was read
back from the repository after the merge, not predicted.

---

## 1. The authorization, verbatim

> "I am the project owner. I authorize completing Phase 02 development integration into `main` now.
> I do not want to configure branch protection, GitHub Rulesets, additional accounts or mandatory
> reviewer approvals at this stage. For this development-only integration, I explicitly accept a
> documented owner exception to the outstanding human-checklist acknowledgement. **Do not fabricate
> an approval, change a failing gate to success, or claim independent review occurred.**"

And, on the gate itself:

> "Record any intentionally unacknowledged human-checklist gate as an explicit owner-accepted
> development integration exception. **Do not weaken the gate.**"

This document is that record.

---

## 2. What was merged, in the owner's order

| # | PR | title | merge commit | head merged | exception |
|---|---|---|---|---|---|
| 1 | **#127** | retrospective security review of PR #124 (documentary evidence) | **`8954df62`** | `9520f705` | **none** |
| 2 | **#126** | harden the security-attestation gate; P1-SEC-56 classifier widening | **`077d0583`** | `457f98ea` | **yes** |
| 3 | **#125** | four CI controls that reported success without taking a measurement | **`56182568`** | `f09f13e3` | **yes** |
| 4 | **#128** | P1-SEC-55: OTP sends pinned, authorization, HTTP-status restored | **`25cf8182`** | `9cae4518` | **yes** |

**Final Laravel `main`: `25cf8182cf7ccfc117d4799395fa3b854b22b5fe`.**
**Flutter `main`: `94cdcdb0`** (unchanged by this integration — already complete).

`main` before the integration was `230d2e12`. No open pull requests remain on either repository.

### Why this order, and it was not arbitrary

File overlap across all four was **one file**: `.github/workflows/ci.yml`, between #125 and #126.
Every pair was trial-merged with `git merge-tree` before starting — **clean in every order, both
directions** — so the order was never about conflicts. It was about what the gate measures and
when:

**#126 had to precede #125 and #128** because it is what makes the checklist gate mean anything on
them. Before it, #128's checklist read GREEN — not because the diff had been reviewed, but because
`main`'s classifier did not look at `app/Http/Controllers/` or `app/Services/`, and its job log
read, in full, `no security-sensitive paths in this diff.` Merging #128 first would have merged a
security-sensitive change on a signal that measured nothing.

Measured after #126 landed and #128's branch was updated: the widened classifier flags all eight of
its code and test files and leaves the four documentation files quiet. **The red checklist on #128
is the first time that check measured anything on that diff.**

---

## 3. THE OWNER-ACCEPTED EXCEPTION — what it is, and what it is not

`Security-sensitive diff: human checklist` was **RED** on #126, #125 and #128 when they were merged.

**What the exception covers:** development integration into `main`, on the owner's explicit
instruction of 2026-11-03.

**What was deliberately NOT done — each of these is checkable against the repository:**

| | |
|---|---|
| the gate was weakened, edited or disabled | **NO.** `flag-sensitive-diff.sh` is unchanged by the integration and still exits 1 |
| an acknowledgement marker was added to a PR body | **NO.** `SECURITY-DIFF-REVIEW: completed` appears in no body and no commit message of this integration |
| a GitHub review was created or simulated | **NO.** All four pull requests carried **zero** reviews at merge |
| independent human review occurred | **NO, and it is not claimed.** No diff in this integration has had one |
| a repository protection setting was changed | **NO.** `main` remains unprotected (S58) |
| a failing check was flipped to success | **NO.** The checklist is red in the record, truthfully |

**`main` being unprotected is the only reason a red check did not block these merges.** That is
stated plainly rather than relied on silently: the automation's credential reports `admin: true`,
so the restraint is a choice and is recorded as one.

**This exception is NOT a release approval.** `RELEASE READY` remains **NO**; `DEPLOYED` remains
**NO**. It does not transfer to any future merge: a later integration needs its own authorization.

The same text is recorded in each of the three merge commits, attached to the merge it applies to —
deliberately, so it cannot drift from the thing it authorizes the way a separate file can.

---

## 4. The one real blocker, and that it was not bypassed

**#128's first attempt failed and was stopped**, per the owner's rule 4 (*"Do not merge changes
with new unexplained test failures"*). On head `c1406708`, **both** test jobs failed:

```
NEW FAILURE, not in the manifest:
  Tests\Feature\Security\SecurityLogInventoryTest::test_the_observability_inventory_matches_the_source
failures observed : 3  (manifest declares 2)
KNOWN-OUTCOME GATE FAILED
```

Reproduced locally on the same head before anything was changed. Six rows in
`P01-OBSERVABILITY-INVENTORY.md` pointed at stale line numbers, because the HTTP-status repair and
the WhatsApp coverage inserted lines into the two OTP controllers and the resync was not re-run
after them. **My own sequencing error, not a defect in the code under review.**

Confirmed mechanical rather than assumed: `resync-observability-inventory.py` re-points rows by
`(file, message)` and **refuses** to write when a log line is genuinely new or deleted. Dry run:
6 re-pointed, 211 unchanged, 217 source = 217 documented, zero problems. The committed diff is six
integers with every message byte-identical. Fixed in `9cae4518`; CI re-run; both test jobs green;
then merged.

**This is the third time this round the observability ratchet caught an edit to those two
controllers.** The ratchet is working; the batching was the defect. The resync belongs immediately
after any edit to a file carrying logged lines.

---

## 5. Verification — the integrated `main`

**THE KNOWN-OUTCOME GATE ON `25cf8182`, the integrated `main` itself:**

```
Feature suite      7,519 passed, 2 failed, 1 skipped   (37,351 assertions)
testcases observed 7,522
failures           2   (manifest declares 2)
errors             0   (always expected: 0)
skips              1   (manifest declares 1)
GATE EXIT          0
```

> "OK: the observed outcome set matches the manifest exactly -- 2 declared failures, 1 declared
> skips, 0 errors, and nothing undeclared."
>
> "This is NOT a statement that the suite is green. It is a statement that the suite is failing and
> skipping exactly the tests the project has recorded, for the reasons it has recorded."

The two declared failures are `new users can register` (roles are not seeded by `RefreshDatabase`)
and `profile page is displayed` (the Breeze Tailwind view on a registered route). Both are named in
`.github/known-test-outcomes.json` with a stated root cause; neither is new, and the gate asserts
the outcome set in BOTH directions, so an unexpectedly-passing declared failure would fail it too.


Per-merge, every check was read before merging. On each of the three exception merges the state was
**eight of nine green**, the single failure being the checklist:

```
success  Static checks (no vendor needed)
success  Secret scan
success  Tests (REQUIRED -- real suites, clean install from composer.lock)
success  Tests (REQUIRED -- real suites, PayHere excluded and NOT fixed)
success  Merge-base regression gate (new failures only)
success  Security-sensitive diff: human approval        [report mode -- see below]
success  Security-sensitive diff: push report (non-blocking)
success  Is the PayHere blocker still there? (report)
FAILURE  Security-sensitive diff: human checklist        [the owner exception]
```

No failed security scan and no regression on any merge.

**`Security-sensitive diff: human approval` reading green means nothing yet.** It is in `report`
mode, and its own usage line says *"Exit 0 = a qualifying approval exists, OR `report` mode."* It
reads `success` on a pull request with zero reviews. `HUMAN-REVIEW-GATE.md` §4.1.2 is why it must
not be added to any required-checks list before the automation's identity is separated from the
author's.

Local verification on the merge results, before each push:

| | |
|---|---|
| `Feature/Security` (whole body, #128's tree) | **4,743 passed, 1 skipped, 0 failures** (20,080 assertions) |
| Unit | **721 passed** (4,551 assertions) |
| Contract — the Flutter API contract | **27 passed** (92 assertions), unaffected |
| attestation gate scenarios | **58 passed, 0 failed** |
| `ci.yml` after the #125/#126 overlap | YAML valid, six jobs intact, **both** PRs' changes present |

The combined `ci.yml` was checked directly rather than trusted: a clean textual merge is not a
clean semantic one. #125's guarded audit fetch and renamed `.env` step are present; #126's
attestation-scenario step and harness invocation are present; the only surviving `depth=1` strings
are inside #125's own explanatory comment.

---

## 6. Both repositories carry the accepted Phase 02 work

**Laravel `main` @ `25cf8182`** — verified by spot-checking that each stale branch's deliverable
arrived by some route, rather than inferring it:

| artifact | on `main` |
|---|---|
| `public/css/store-syria-ds.css` (P01b design system) | yes |
| `docs/design-system/README.md` | yes |
| `packages/workdo/LynomiaChat/src/app` | yes |
| `.claude/skills/frontend-design` | yes |

**Flutter `main` @ `94cdcdb0`** — `ab4d684a` "Merge pull request #5 — release integration: Phase 02
admin app into main" and `94cdcdb0` "Merge pull request #6 — P1-SEC-46's client half". **No open
pull requests.** Nothing outstanding.

### Eight branches sit ahead of `main`, and were INVENTORIED rather than merged

`CLAUDE.md`'s first standing principle: *"Preserve every approved change; inventory existing work
and do not blindly merge experimental or redundant branches."* The authorization named four pull
requests, not eight branches.

| branch | ahead | behind | last commit | reading |
|---|---|---|---|---|
| `claude/wonderful-gates-60khob` | 196 | **428** | 2026-10-22 | superseded; diverged at `1eebc721` |
| `claude/hopeful-fermat-w3nl79` | 66 | **428** | 2026-10-19 | superseded; same divergence |
| `claude/store-syria-p01b-design-foundation` | 6 | — | 2026-10-17 | its deliverables are on `main` |
| `claude/pensive-cerf-h6rizn` | 4 | — | 2026-10-12 | Phase 0 / 1A discovery docs |
| `claude/awesome-meitner-bop1lb` | 2 | — | 2026-10-17 | Lynomia phase-7 notes |
| `claude/beautiful-planck-w6wu9x` | 1 | — | 2026-10-17 | the design skills — on `main` |
| `claude/gallant-rubin-gnrmhy` | 1 | — | 2026-10-17 | Lynomia commerce-token UI |
| `claude/otp-ssrf-and-permissions` | — | — | 2026-11-03 | **merged as #128** |

The two large ones being **428 commits behind** is the telling figure: `main` moved past them
through #121, #123, #124 and the release-integration merge. **"Ahead of `main`" is not the same as
"contains unmerged accepted work", and the opposite is not asserted either** — this is recorded as
an inventory, and any of them can be diffed in full on request before it is called settled.

---

## 7. Status that did NOT change

| | |
|---|---|
| `RELEASE READY` | **NO** |
| `DEPLOYED` | **NO** |
| #120 / P1-OWN-09 (database dump exposure) | **OWNER-DEFERRED / OPEN** |
| **P1-SEC-43** (forged Zid webhook creates a real parcel) | **OPEN, DEPLOYMENT-BLOCKING** |
| P1-SEC-57 (`WoocomCategoryController` judged, not pinned) | **OPEN, escalated, tracked independently** |
| P1-SEC-50, P1-SEC-53 (the gate's attestation and substring defects) | **OPEN, owner decision** |
| P1-SEC-54 (`main` unprotected) | **OPEN, owner-deferred — S58** |
| Paytm activation | **DISABLED** |
| production migrations / deployment / credentials / providers | **NONE TOUCHED** |
| GitHub protection settings | **UNCHANGED** |

---

## 8. Phase 02 closure

**Phase 02 development integration is COMPLETE** as of `25cf8182`, and Phase 02 is **CLOSED for
development** on that basis.

What "closed" means here, stated precisely so it cannot be read as more than it is:

- **It is closed as a development integration**, which is what was authorized.
- **It is not a release.** `RELEASE READY` is NO and the deployment-blocking findings above are
  unchanged. Phase 02 being closed does not make the platform deployable.
- **No diff in this integration has had independent human review.** The checklist exception is
  recorded, not satisfied.

The six-phase Master Product Plan is preserved. Phase 02 kept its original Merchant Experience
scope for both merchant web and Flutter; nothing from Phase 2.5, 03 or 06 was absorbed into it.

---

## 9. Phase 2.5 — prepared, not started

The approved next stage is **Phase 2.5 — Premium Merchant Experience**, a polish stage over Phase
02's surfaces. Its handoff is `docs/development/phase-2/PHASE-2.5-HANDOFF.md` (on `main` since
`56182568`).

**Nothing of Phase 2.5 is implemented**, and this record does not start it. What it inherits:

- a merchant surface whose authorization, tenancy and outbound-credential paths are the ones this
  round hardened — and whose remaining open findings are listed in §7 rather than carried silently;
- a sensitive-diff gate that now looks at the directories where this codebase keeps its defects
  (S57), with its bound asserted in both directions;
- `HUMAN-REVIEW-GATE.md` §4, the manual branch-protection checklist, **unapplied by owner
  decision** — the single highest-value change still available, about four minutes of clicking, and
  the thing that converts every gate here from a report into a constraint.
