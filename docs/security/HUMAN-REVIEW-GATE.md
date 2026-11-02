# The human review gate — what it can and cannot prove, and what must change before it can enforce

Owner direction of 2026-11-02, Decision 3, items 6 and 7. Measured facts carry the command that
produced them. Everything in §1 is a measurement, not a recollection.

---

## 1. Item 6 — branch protection and required status checks, MEASURED

**This is the load-bearing finding of the whole exercise, and it was the last thing anyone
checked.**

| probe | result |
|---|---|
| `gh api repos/fullstackfull/syriastore98/branches/main --jq '.protected'` | **`false`** |
| …`.protection` | `{"enabled": false, "required_status_checks": {"checks": [], "contexts": [], "enforcement_level": "off"}}` |
| `gh api repos/…/rulesets` | **`[]`** |
| `gh api repos/…/rules/branches/main` | **`[]`** |
| `gh api repos/…/branches/main/protection` | `403 Resource not accessible by integration` (the endpoint needs a different token scope; the three probes above answer the same question and agree) |

### What follows from that, stated plainly

**`main` has no branch protection and no rulesets. There are zero required status checks.
Therefore every gate in this repository is advisory.**

- A red check has never blocked a merge. `mergeable_state: unstable` is **information**, not a
  refusal.
- `security-attestation.yml`'s own header anticipated this — *"if 'Merge-base regression gate' is a
  required check, add 'Security-sensitive diff: human checklist' to the required list as well"* —
  and the answer is that **neither is required, because the branch is not protected at all.**
- So when PR #125 was described as *"held red"*, nothing was holding it. The automation declined to
  merge it. That is a decision, not a control, and a decision is not a gate.

This reframes items 1–5. Anchoring the acknowledgement and adding an approval check make the
**signal** honest. They do not make it **binding**. Only a repository setting can do that.

### Not changed by this work, deliberately

The token this automation holds reports `"permissions": {"admin": true, "maintain": true,
"push": true, …}` on the repository, so it **could** enable branch protection in one API call. It
has not, for two reasons, and both are the same reason as not writing the acknowledgement marker:

1. Branch protection is the control that would constrain this automation's own merges. An agent
   configuring its own constraints has a conflict of interest that no amount of care removes.
2. The owner's direction was *"**Verify** repository branch protection and required status
   checks"*, and *"Prepare the gate change as a separate, small, independently reviewable PR."*
   A repository setting is neither a code change nor reviewable in a diff.

§4 is the exact list to apply.

---

## 2. Item 7 — why the automation's identity must be separated first

### The measurement

| | |
|---|---|
| `gh api user` | `login: fullstackfull`, `id: 287595574`, `type: User` |
| PR #123 | `author: fullstackfull`, `merged_by: fullstackfull` |
| PR #124 | `author: fullstackfull`, `merged_by: fullstackfull` |
| PR #125 | `author: fullstackfull` |
| reviews on #123, #124, #125 | **none, on any of them** |

**The automation acts as the owner's own GitHub account.** Not an account that resembles it — the
same login and the same numeric id.

### Why that makes a non-author approval gate *unsatisfiable*, not merely strict

Two facts combine:

1. **GitHub refuses to let a pull request's author approve their own pull request.** Submitting an
   `APPROVED` review on your own PR is rejected by the API and the UI.
2. **Every pull request here is authored by `fullstackfull`**, because the automation opens them
   through that credential — including the ones the owner is being asked to review.

So *"an APPROVED review from a login other than the author"* cannot exist today **no matter what
the owner does.** The owner is the author. Enforcing the rule now would not produce a strict gate;
it would produce a gate with no satisfying assignment, and the predictable result of an
unsatisfiable gate is that somebody disables it or merges past it — which is the behaviour this
entire sequence of findings is about.

**This is why `assert-human-approval.sh` defaults to `report`.** Reported is not satisfied, and the
script says so in those words.

### What separation looks like, with the trade-offs

| | mechanism | the automation becomes | cost / caveat |
|---|---|---|---|
| **A** | **A GitHub App** installed on the repository; the session uses an installation token | `your-app-name[bot]` — a distinct actor | Recommended. Permissions are scoped per repository and per capability, and the App can be revoked without touching the owner's account. Requires creating and installing the App and giving this session an installation token instead of a user PAT. |
| **B** | **A machine user** — a second GitHub account (e.g. `storesyria-bot`) with write access; the session uses its PAT | `storesyria-bot` | Simplest to set up. GitHub's terms permit machine accounts. The account is a long-lived credential and may consume a seat in a paid organisation. |
| **C** | A fine-grained PAT belonging to that second account | same as B | B with a narrower, expiring token. Strictly better than B if the expiry is managed. |

A note on what does **not** help: GitHub Actions' built-in `GITHUB_TOKEN` already acts as
`github-actions[bot]`, but only *inside* a workflow run. It cannot open the pull requests this
session opens, so it does not separate the authoring identity.

### The rule that must survive separation

**The automation must never be able to approve.** Giving it a separate identity makes a non-author
approval *possible*; it must not make an automation approval *count*. That is what
`assert-human-approval.sh`'s optional trusted-logins file is for: enumerate the human reviewers,
and leave the automation's login out of it. A separate identity that may approve its own work is
the same defect one level up.

---

## 3. What each piece of this change actually proves

| | proves | does **not** prove |
|---|---|---|
| `flag-sensitive-diff.sh`, anchored (items 1–3) | a line consisting exactly of the marker is present in the pull request body | **who put it there.** No field in GitHub's record distinguishes a line the owner typed from one an automation generated |
| `assert-human-approval.sh` (items 4–5) | an `APPROVED` review exists, from a named login, that is not the author's, bound to the current head | nothing, while it runs in `report` mode — and nothing at all while `main` is unprotected |
| `verify-attestation-gate-scenarios.sh` | both scripts behave as specified across 25 scenarios, re-checked on every pull request | that either gate is **required**. That is §1. |

The honest summary: items 1–5 remove the ways the signal could lie. Item 6 is the only one that
makes it bind.

---

## 4. The exact sequence to apply, in order

Steps 1 and 2 are repository settings and are the owner's. Step 4 is a one-line code change.

**1. Enable branch protection (or an equivalent ruleset) on `main`.** Require a pull request
before merging, and require these status checks:

```
Static checks (no vendor needed)
Secret scan
Tests (REQUIRED -- real suites, clean install from composer.lock)
Tests (REQUIRED -- real suites, PayHere excluded and NOT fixed)
Merge-base regression gate (new failures only)
Security-sensitive diff: human checklist
```

Also turn on **"Dismiss stale pull request approvals when new commits are pushed"** — that is
GitHub's native form of item 5, and it belongs on as well as the script's head-binding, not
instead of it.

Do **not** add `Security-sensitive diff: human approval` to the required list yet. It is in
`report` mode and would be required-but-unsatisfiable until step 2 is done.

**2. Separate the automation's identity** — option A, B or C from §2.

**3. Record the human reviewers** in a trusted-logins file and pass it as
`assert-human-approval.sh`'s fifth argument. One login per line, `#` comments allowed. **Leave the
automation's login out.**

**4. Switch the approval check to enforcing.** One literal, in
`.github/workflows/security-attestation.yml`, the `approval` job's last step:

```diff
-          .github/scripts/assert-human-approval.sh "$PR_AUTHOR" "$PR_HEAD" /tmp/pr-reviews.json report
+          .github/scripts/assert-human-approval.sh "$PR_AUTHOR" "$PR_HEAD" /tmp/pr-reviews.json enforce
```

Then add `Security-sensitive diff: human approval` to the required checks from step 1.

**Order matters.** Step 4 before step 2 creates an unsatisfiable gate. Step 1 without step 4 still
leaves the checklist advisory-but-required, which is a real improvement on today and is worth doing
on its own.

---

## 5. Where the findings behind this live

**Read this note before following the first three links.** Sections S51–S55 of
`P01-SECURITY-WORK-QUEUE.md` are **not on `main` yet** — `main`'s copy ends at S50. They arrive
with [PR #125](https://github.com/fullstackfull/syriastore98/pull/125), which is **open and
unmerged** by the owner's Decision 2. This branch deliberately does **not** touch that file, so
that this change stays small, independently reviewable, and free of a merge conflict with #125 —
both would otherwise insert at the same anchor.

| | where |
|---|---|
| the acknowledgement written by an agent, on a PR that agent then merged — **P1-SEC-50** | `P01-SECURITY-WORK-QUEUE.md` §S53, **arriving with #125** |
| the substring match satisfied by a sentence denying it, and the commit-message surface — **P1-SEC-53** | §S55, **arriving with #125** |
| the four controls that reported success without measuring anything | §S51, §S52, §S54, **arriving with #125** |
| **`main` unprotected, so every gate is advisory — P1-SEC-54** | **this document, §1.** It is recorded here rather than in the queue for the conflict-avoidance reason above; transcribe it into the queue once #125 has merged |

---

## 6. The sentence this document exists to make unambiguous

A control can fail in three distinct ways, and this repository has now produced all three in one
week:

1. it measures nothing and reports success (`|| true` on a measurement);
2. it measures the wrong proposition — the presence of a string rather than the occurrence of an
   act (a substring match for an acknowledgement);
3. it measures the right proposition, reports it correctly, **and nothing is required to listen**
   (an unprotected branch).

Items 1–5 of the owner's direction fix (1) and (2). **Only §4 step 1 fixes (3)**, and until it is
done, the most honest description of every gate in this repository is: a report.
