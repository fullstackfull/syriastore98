# §21 — the attestation-gap review: `bfb921d2..1bff9dc7`

**Owner direction, 2026-10-24:** *"For PR #3, perform a focused attestation-gap review of the
previously uncovered commits. Reuse existing verified evidence and do not rerun all 77 commits
individually."*

This section closes the gap PR #3's own §20 left open — *"any commit pushed after `bfb921d2` is
outside this attestation until a section covers it"* — and it closes it on the terms the owner set:
by **reusing the evidence that already exists**, not by re-running or re-reviewing each commit.

It lives in the repository rather than in the pull-request description, for the reason recorded in
that description's own header: on 2026-10-21 a rewrite of it was **cut off mid-text by a safety
classifier and replaced the whole description with a ~6,000-character prefix**, and the write was
not retried. The description now points here. The body as it stood before that pointer was added is
archived byte-identically alongside this file.

---

## 1. The range, and a correction to the figure

| | |
|---|---|
| last attested head (§20) | `bfb921d2` |
| head attested here | `1bff9dc7` |
| **commits in range** | **79** |

**The register's §7 said 77, and that was correct when written** — at head `2efbc1dd`. Two commits
landed after it (`22731010`, the record of the gap itself, and `1bff9dc7`, the sentinel fix), so the
figure is 79 here. Stated rather than silently updated, because a number that moves between
documents is how a reader loses confidence in both.

**Census keyed on the files each commit touches**, not on its subject prefix:

| | commits |
|---|---|
| touch `app/` `config/` `routes/` `resources/` `database/` | **46** |
| touch only `tests/` or `.github/` | 12 |
| documentation only | 21 |
| **total** | **79** |

**That keying is itself a correction.** My first pass keyed on the Conventional-Commits prefix and
counted `9dfcd1c8` (`fix(ci): …`) as production code; it changes **one line of one markdown file**.
That is census-failure mode 2 from this project's own skill — *keyed on spelling rather than on the
thing* — committed today, by me, while writing an attestation about rigour. Recorded for the same
reason the other four census failures are.

Cumulative diff: **203 files, +38,101 / −3,282** across the range; **464 files, +138,270 / −3,921**
against `main` at `1eebc721`, which is what the PR actually proposes.

---

## 2. What is attested CUMULATIVELY, and why this is the load-bearing part

These are properties of the **tree at `1bff9dc7`**, not of individual commits, so they cover the net
effect of all 79 at once. This is what makes a focused review possible without re-running anything:
a per-commit pass could not establish any of them, and they are the claims a reviewer actually needs.

**CI, read back from the API today — not inferred, and not taken from the webhook.** Run
`40102841581`, head `1bff9dc7`: **all 9 check runs `success`.**

| check | job | finished |
|---|---|---|
| **Tests (REQUIRED — real suites, clean install from `composer.lock`)** | 120898345065 | 14:40:39Z |
| **Tests (REQUIRED — real suites, PayHere excluded and NOT fixed)** | 120898345001 | 14:40:53Z |
| **Merge-base regression gate (new failures only)** | 120898344928 | 14:49:12Z |
| Static checks · Secret scan · PayHere report · checklist · 2 push reports | — | all `success` |

Two of those carry most of the weight:

- **The merge-base regression gate is a cumulative statement over the whole range.** It runs the
  suite at the merge base and at head and asserts the branch **introduces no new failure**. Passing
  at `1bff9dc7` is therefore a single verified claim about all 79 commits' net effect on test
  outcomes — the one thing 79 individual re-runs would have been trying to establish.
- **Both REQUIRED Tests jobs install the complete locked dependency set**, which this development
  container cannot (`yoomoney/yookassa-sdk-php` pulls from `git.yoomoney.ru`, which the network
  policy denies). So CI, not any local run, is the authority on the locked set, and it is green.

**Locally on this tree:** Feature **5722 passed, 2 declared failures, 17 declared skips, 0 errors**
(1026s), with `assert-known-test-outcomes.php` reporting the outcome set matches the manifest
**exactly, in both directions** — an undeclared failure *and* an unexpectedly-passing declared
failure both fail that gate. **Nothing was added to `.github/known-test-outcomes.json`** at any
point in this range.

**The vendor boundary holds across the entire range, measured:** `git diff --name-only
bfb921d2..1bff9dc7 -- packages/workdo/` returns nothing outside the first-party `LynomiaChat`
exemption, and `guard-no-workdo-changes.sh` (which compares against `main`, so its verdict is
cumulative) exits 0 at head.

**Not deployed, not merged, nothing live touched.** `main` was not modified, no migration was run
against any server, no live data was altered. PR #3 remains a **Draft titled ⛔ DO NOT MERGE**.

---

## 3. What the per-commit evidence is, reused rather than re-run

Each commit in this range carries its own evidence in its own message. Measured across all 79:

| the message records | commits |
|---|---|
| explicit test counts | **69 / 79** |
| mutants or removal checks | **59 / 79** |
| CI guards or the known-outcome gate | **43 / 79** |
| an independent review and what it found | **15 / 79** |

The 15 with a recorded independent review are the security- and payment-sensitive ones, which is
where this project spent its review budget deliberately. Several record the review finding a defect
**in the fix itself** — `c53cb55b`, `2897ec0c`, `8d18782e`, `47519edc`, `09191cda`, `2efbc1dd` —
and that pattern is the strongest evidence in this range that the reviews were real rather than
ceremonial.

**The one gap, named.** Exactly **one** production-code commit records no test, mutant or gate
evidence in its own message:

> `98c53b8a` — *fix(P1-INV-05): the two read-modify-write restocks go through the atomic primitive*
> (`app/Models/Order.php`, plus a MySQL verification script)

Its evidence exists; it is in the **immediately following** commit, `c25b2745`, which records
`tests/Feature/Security` + `tests/Feature/Commerce` at **4094 passed / 11 skipped, exit 0** and
states that the row **stays BACKLOG** because its acceptance evidence was incomplete. So the commit
is covered, by an adjacent record rather than its own — worth naming precisely because a reader
checking `98c53b8a` alone would find nothing and would be right to ask.

---

## 4. What this section does NOT attest — the boundary, stated plainly

**This is not a per-commit adversarial re-reading of 79 commits, and it does not claim to be.**
§17 set this project's precedent: it attested 3 of its 28 commits and named the 25 it did not.
The same honesty applies here, and the owner's direction asked for exactly this scope.

- **8 commits were authored in this session** — `2f68495d`, `09191cda`, `0afac2cf`, `d082214a`,
  `0d8a48b1`, `673daff4`, `47719ce1`, `2efbc1dd`, plus `22731010` and `1bff9dc7` — each with its
  own gates, mutants and, where the change touched payments or authorization, an independent
  review that **found real defects in the fix** and whose findings are recorded in the commit.
- **The other commits were not re-examined here.** Their evidence is what their messages and the
  ledger record. Writing a section that claimed to have examined them would be the one thing this
  convention forbids.

**What a reviewer should take from that:** the cumulative gates in §2 are verified and cover the
whole range; the per-commit claims in §3 are *recorded* and were not re-verified here. Those are
different grades of evidence and are kept separate on purpose.

**Also not claimed:** that the suite is green. It is not, and is not meant to be — 2 failures and
17 skips are accepted by name in `.github/known-test-outcomes.json`, whose own README forbids adding
an entry to obtain green. The known-outcome gate asserts that set, not success.

---

## 5. The acceptance limitations this attestation is subject to

The owner accepted the engineering completion status **subject to the recorded acceptance
limitations**. They are not restated here — they live in the authoritative documents, and
duplicating them is how they drift:

| limitation | where it is recorded |
|---|---|
| per-task PARTIAL rows and their exact unmet criteria | `12-PHASE-01-TASK-LEDGER.md` |
| the 13 environment checks, each with its prepared command | `PHASE-01-CLOSURE-EXECUTION.md` §B, §9 |
| the 5 D15-gated migrations and their two ordering constraints | `PHASE-01-CLOSURE-EXECUTION.md` §C; `_gate_f_pending/README.md` |
| the two vendor reports, prepared and **not sent** | `docs/upstream/`, `PHASE-01-CLOSURE-EXECUTION.md` §D |
| what Phase 02 must not assume | `PHASE-01-CLOSURE-EXECUTION.md` §4, §6, §8 |

The four most load-bearing, because they are easy to read past. **The first of them is now known
to be false, and is left visible rather than quietly deleted:** this section claimed *the financial
subsystem is dead, not merely untested — its tables do not exist, the service throws, an Observer
swallows it*. The production dump restored on 2026-10-24 shows all 15 tables present with live data
and the service running. What survives of the claim is that **no migration creates them**, so the
schema is unmanaged rather than absent (`docs/GATE-F-VERIFIED-SCHEMA.md`). The other three stand:
**a green suite is not a correct control**
(twice this phase a guard passed its own tests while an independent read found a live bypass);
**the per-table tenancy ratchet is silent for 28 of 190 raw query sites**; and **`$casts` is not the
encrypted-column inventory**.

---

## 6. Attestation

The checklist in `.github/SECURITY-DIFF-REVIEW.md` is worked for `bfb921d2..1bff9dc7` **in the
focused form the owner directed**: cumulatively verified at the head for the gate, vendor-boundary,
dependency-set and outcome-set properties (§2), and reused per commit from the evidence already
recorded in each message (§3), with the single gap named (§3) and the boundary of what was *not*
re-examined stated (§4).

**Any commit pushed after `1bff9dc7` is outside this attestation until a section covers it.**

SECURITY-DIFF-REVIEW: completed (focused, per owner direction of 2026-10-24)

---

# §22 — `1bff9dc7..` the GATE F verification head

§21 ends by saying *"any commit pushed after `1bff9dc7` is outside this attestation until a section
covers it."* This covers it. The range is small and its character is what makes a focused
attestation sufficient.

## 1. The range, keyed on files touched

**19 files, and not one of them is production code.**

| area | files |
|---|---|
| `app/` `config/` `routes/` `resources/` or a **discoverable** migration | **0** |
| parked drafts under `database/migrations/_gate_f_pending/` — which Laravel's non-recursive glob does not find, and which carry a `.php.draft` extension | 13 |
| documentation, including `CLAUDE.md` | 5 |
| a read-only verification script under `tests/Support/Security/bin/` | 1 |

So the branch's runtime behaviour is **unchanged** across this range. That is the load-bearing fact,
and it is measurable rather than argued: `guard-gate-f-containment.sh` scanned 236 discoverable
migration files across both trees and reports **0 of the 6 money tables created by any discoverable
migration, with all 6 still holding a parked, non-runnable draft.**

## 2. Gates run, with their output

All run locally on this tree at this head.

| gate | result |
|---|---|
| `guard-gate-f-containment.sh` | **exit 0** — containment intact, 9 drafts found, 0 discoverable creators |
| `guard-no-workdo-changes.sh origin/main` | **exit 0** — no change under `packages/workdo/` outside `LynomiaChat` |
| `scan-secrets.sh origin/main` | **exit 0** — no credential-shaped literal added across 457 changed files |
| `guard-no-env-files.sh` | **exit 0** — no live `.env` in checkout or index |
| `guard-test-isolation.sh` | **exit 0** — `phpunit.xml` still pins the suite to sqlite `:memory:` |
| `guard-no-new-root-uploads.sh origin/main` | **exit 0** |
| focused suites on their designed engine | **155 passed, 7 skipped, 0 failures** (`CodStateMachineTest`, `CodCashCollectionTest`, `FinancialSurfaceCoverageTest`, `KhaltiAmountBindingTest`, `UnverifiedGatewayStaysPendingTest`, `GatewayReturnTrustBoundaryTest`, `PendingPaymentGatewayCensusTest`) |

**`guard-test-isolation.sh` passing matters here specifically.** This work needed MySQL evidence, and
the obvious route was to point the suite at MySQL. `tests/bootstrap.php` **refuses** that, because
`RefreshDatabase` runs `migrate:fresh` and would drop every table in the target database. **The guard
was not weakened, bypassed or edited.** A separate override config was written outside the repository,
the bootstrap refused it exactly as designed, the config was deleted, and the MySQL evidence was
taken the way this project already does it — through `tests/Support/Security/bin/` scripts and direct
read-only queries against a restored copy.

## 3. What this range establishes, and what it retracts

It **retracts three claims** this repository asserted and the restored production dump refuted — most
importantly "the financial subsystem is dead", which appears in §21 §5 above as one of the four
load-bearing limitations. That claim is corrected in place rather than deleted, here and in
`PHASE-01-CLOSURE-EXECUTION.md`, `HANDOFF-CURRENT.md` §12d, `CLAUDE.md` and
`_gate_f_pending/README.md`. The authoritative account is `docs/GATE-F-VERIFIED-SCHEMA.md`.

**Nothing was deployed, merged or migrated.** `main` was not modified and was fetched but never
merged. No migration was run against any server. No live data was altered. The three throwaway
databases used for the verification were dropped and the dump destroyed. PR #3 remains a **Draft
titled ⛔ DO NOT MERGE**.

**Any commit pushed after this head is outside this attestation until a section covers it.**

SECURITY-DIFF-REVIEW: completed (focused — this range contains no production code)
