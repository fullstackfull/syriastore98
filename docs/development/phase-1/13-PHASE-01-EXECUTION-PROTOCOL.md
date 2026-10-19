# Phase 01 — Execution Protocol

How work is picked up, done and closed in this phase. Written for a session with **no previous
conversation context**: everything needed to continue is in this directory and in GitHub.

The task records live in [`phase-01-tasks.json`](phase-01-tasks.json) and are rendered to
[`12-PHASE-01-TASK-LEDGER.md`](12-PHASE-01-TASK-LEDGER.md). GitHub Issues under milestone
**Phase 01 — Foundation & Stability** carry the same records. The two must agree.

---

## 1. Where things are

| | |
|---|---|
| Branch | `claude/store-syria-p01a-ci-foundation` — all Phase 01 work, no exceptions |
| Pull request | #3, **Draft, never merged**. Its §8/§10/§11 are the security attestations |
| Milestone | #1 |
| Task source of truth | `docs/development/phase-1/phase-01-tasks.json` |
| Rendered ledger | `docs/development/phase-1/12-PHASE-01-TASK-LEDGER.md` |
| Security backlog (evidence) | `docs/security/P01-SECURITY-WORK-QUEUE.md` — S1…S37 |
| Accepted test outcomes | `.github/known-test-outcomes.json` |
| Architecture traps | `CLAUDE.md` — read it first, every session |

## 2. Picking the next task

```bash
gh issue list --milestone "Phase 01 — Foundation & Stability" --label status:ready --state open
```

Take a `READY` task whose dependencies are all `DONE`. If two are equally ready, the ledger's
**Execution order** section decides. Never start a `BLOCKED` task: its `blocked_on` field names
what must happen first, and **owner silence is not approval**.

Only one task is `IN_PROGRESS` per working file. The ledger's dependency table exists mostly to
keep two agents out of the same file.

## 3. The loop, per task

1. Read the issue and the ledger row. Read `CLAUDE.md` if this session has not.
2. **Verify the defect or the missing behaviour yourself.** A recorded finding is a claim, not a
   fact: line numbers drift, and this branch has several findings that measurement refuted.
3. Set the issue to `status:in-progress` (remove `status:ready`).
4. Implement the **smallest correct change**. Prefer the narrowest fix that closes the defect and
   keeps the request/response contract — and when security requires breaking it, say so explicitly.
5. Add tests: at least one **negative** (the defect is refused) and one **positive** (the
   legitimate journey still works). A refusal test with no positive control is not evidence.
6. **Removal-check every control.** Revert the fix, confirm the test fails, restore. If a control
   does not bite alone, say so and record it as subsumed rather than claiming it. This project has
   found eight controls and four fixtures that proved nothing until they were measured.
7. Run the checks in §4.
8. Adversarial review: the `tenant-authz-reviewer` subagent for anything touching tenancy,
   authorization, money or stock. Treat its findings as claims to verify — several have been wrong,
   and several have been right about work that had just passed its own tests.
9. Commit with the task id in the subject (`P1-INV-01: …`). Push to the branch. No force-push, no
   history rewrite, no destructive git.
10. Update the issue with the evidence (commit sha, test names, measured numbers) and set
    `status:in-review`, or `status:done` once the acceptance criteria are actually met.
11. Re-render the ledger (`python3 docs/development/phase-1/tools/render-ledger.py`) and commit it
    with the JSON.

## 4. Checks before a push

```bash
php artisan test --testsuite=Unit          # 278
php artisan test --testsuite=Contract      # 26 — the Flutter/Lynomia contract
php artisan test --testsuite=Feature \
  --log-junit storage/logs/feature-junit.xml \
  --log-teamcity storage/logs/feature-teamcity.txt
php .github/scripts/assert-known-test-outcomes.php \
  storage/logs/feature-junit.xml .github/known-test-outcomes.json \
  storage/logs/feature-teamcity.txt      # must EXIT 0
bash .github/scripts/guard-no-workdo-changes.sh "$(git merge-base origin/main HEAD)" .
bash .github/scripts/guard-gate-f-containment.sh .
bash .github/scripts/guard-test-isolation.sh .
bash .github/scripts/guard-no-env-files.sh .
bash .github/scripts/scan-secrets.sh "$(git merge-base origin/main HEAD)" .
```

The Feature suite is **not green and is not meant to be**: 2 accepted failures and 17 accepted
skips, declared by name in the manifest. The gate asserts the outcome **set** in both directions —
an undeclared failure *and* an unexpectedly-passing declared failure both fail it. **Never add an
entry to the manifest to obtain green**; its own README forbids it.

**Do not run two suites against the same working tree at once, and do not edit application files
while a suite runs.** A disturbed run has already produced a `GATE EXIT: 1` whose counts were all
correct — only 13 of 17 skip reasons were recoverable from the TeamCity log.

GitHub CI is the authority, not the local run: this container cannot reach `dev.azure.com`, so the
two `myfatoorah/*` packages are absent locally and the local suites run against a tree missing them.

## 5. What stops work and needs the owner

Stop the affected task, leave it `BLOCKED` with `blocked_on` filled in, and continue other
independent `READY` tasks. Never infer approval from silence.

- production or server deployment · merging into `main`
- applying a migration to any persistent database · any destructive data change
- rotating credentials · changing financial retention policy
- breaking public API compatibility · changing gateway settlement behaviour without verified
  provider support · disabling currently supported customer-facing functionality

## 6. Standing constraints

- `packages/workdo/` is **vendored and not editable** (`LynomiaChat` is the one exemption). A fix
  that seems to need a module file belongs in the host app: a policy, FormRequest, gate, query
  scope or route middleware. Enforced by CI.
- **Do not weaken a CI guard or delete a test to get green.**
- Preserve the shipped **Flutter admin** contract and **Lynomia Chat** contract — `tests/Contract`
  pins both. `ApiResponser::error()` returns HTTP 200 with `{status: 0}`, and the Flutter app
  **force-logs-out on any 401**, so 403 is the refusal code.
- Preserve the imported **Design System** foundation. Phase 02 owns the interface redesign; Phase 01
  does foundation maintenance only.
- **Claim only what you executed.** State the engine, the command and the output.
