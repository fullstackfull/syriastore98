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

One more measurement, because it is the one that makes the restraint meaningful rather than
convenient: the credential this automation holds reports `"permissions": {"admin": true,
"maintain": true, "push": true, …}` on the repository, so **it could have enabled branch
protection in a single API call at any point** and did not.

Round 1's direction was *"**Verify** repository branch protection and required status checks"* and
*"Prepare the gate change as a separate, small, independently reviewable PR"*; Round 2's Decision 6
then said it outright — *"**Do not modify repository protection settings using the automation's
current owner credentials.** Provide the owner with precise UI instructions."*

**§4.0 states the three reasons and §4.1 is the click path.** They are written there once rather
than summarised here as well, because a control described twice in two places is how the two false
premises corrected in this round came to exist.

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
| `assert-human-approval.sh` (items 4–5) | an `APPROVED` review exists, from a named login, that is not the author's, bound to the current head | nothing, while it runs in `report` mode. It **exits 0 either way**, so its check reports `success` on a pull request with zero reviews — measured on #126's head `abb1852f`. That is why §4.1.2 says not to require it yet, and why requiring it today would be worse than leaving it out |
| `verify-attestation-gate-scenarios.sh` | both scripts behave as specified across 31 scenarios, re-checked on every pull request | that either gate is **required**. That is §1, and the click that fixes it is §4.1. |

The honest summary: items 1–5 remove the ways the signal could lie. Item 6 is the only one that
makes it bind.

---

## 4. The exact sequence to apply, in order — owner Decision 6

> Owner direction of 2026-11-02, Decision 6: *"**Do not modify repository protection settings using
> the automation's current owner credentials.** Provide the owner with precise UI instructions for
> enabling branch protection on `main`. Require pull requests and the applicable six existing CI
> checks. Do not yet make the new non-author approval check required while all PRs are still
> authored using the owner's identity. Recommend a separately scoped GitHub App identity for Claude
> Code. The owner must retain control over approval and protected-branch settings. Once identity
> separation is established, propose the reviewed switch from report to enforce and the
> corresponding required status check."*

### 4.0 What was deliberately not done

**No repository setting was changed.** The credential this automation holds reports
`"permissions": {"admin": true, "maintain": true, "push": true, …}` on `fullstackfull/syriastore98`,
so a single `PUT repos/…/branches/main/protection` call was available throughout and was not made.

Three reasons, and the owner's instruction is the first:

1. The direction is explicit — *do not modify repository protection settings using the automation's
   current owner credentials*, and *provide the owner with precise UI instructions*.
2. **Branch protection is the control that would constrain this automation's own merges.** An agent
   configuring its own constraints has a conflict of interest that no amount of care removes. It is
   the same reason the acknowledgement marker was not written (§3) and the same reason P1-SEC-50
   exists.
3. A repository setting is not a code change and cannot be reviewed in a diff, so it cannot travel
   through the *"separate, small, independently reviewable PR"* the owner asked for. **This document
   is the deliverable; the click is the owner's.**

Steps 1 and 2 below are settings and are the owner's alone. Steps 3 and 4 are a file and a one-word
literal, and should arrive as their own reviewed pull request **after** step 2.

---

### 4.1 STEP 1 — protect `main` (the owner's click path)

GitHub offers two mechanisms. **A ruleset is the better choice** — it is the surface GitHub is
actively developing, it can be set to *Evaluate* (dry-run) before *Active*, and it lists bypass
actors explicitly. The classic path is given second because it is the one most of this repository's
history was written against, and either produces the required outcome.

Every quoted label below was checked against GitHub's own current documentation on 2026-11-02.
**GitHub renames these controls** — the button is now *"Add classic branch protection rule"*, where
it used to read *"Add rule"*. If a label has moved again, match it by meaning rather than by string
and record what you actually saw.

#### Route A — a ruleset (recommended)

1. Open `https://github.com/fullstackfull/syriastore98` and click **Settings**. *(If the Settings
   tab is not visible, open the `⋯` dropdown at the end of the tab strip and click Settings.)*
2. In the left sidebar, under **"Code, planning, and automation"**, click **Rulesets**, then
   **Rulesets** again.
3. Click **New ruleset** → **New branch ruleset**.
4. **Name** it something durable, e.g. `main: PR + CI required`.
5. Set **Enforcement status** to **Active**. *(**Evaluate** records what the ruleset would have done
   without blocking anything — useful for one day, but it is a report, and §6 of this document is
   about exactly that distinction. Do not leave it on Evaluate and consider `main` protected.)*
6. Under **"Target branches"**, click **Add a target** → **Include default branch**. *(That is
   `main`. Targeting it by role rather than by name survives a future default-branch rename.)*
7. Under **"Bypass list"** — **leave it empty.** Anyone listed here can push past every rule below.
   An organisation's owners and repository admins do *not* bypass a ruleset unless they are listed,
   which is the main practical advantage over the classic path. See §4.5.
8. Under **"Rules"**, tick:
   - **Require a pull request before merging**
     - **Required approvals: 1** *(see the note in §4.1.1 — it will not be satisfiable until step 2,
       so set this to **0** today and raise it to 1 as part of step 2)*
     - **Dismiss stale pull request approvals when new commits are pushed** — on
   - **Require status checks to pass**
     - **Require branches to be up to date before merging** — on
     - **Add checks** → add the six names in §4.1.1, one at a time, exactly as written
   - **Block force pushes** — on
9. Click **Create**.

#### Route B — classic branch protection

1. **Settings** (as above).
2. Left sidebar → **Branches**.
3. Under **"Branch protection rules"**, click **Add classic branch protection rule**.
4. Under **"Branch name pattern"**, type `main`.
5. Tick **Require a pull request before merging**.
   - **Require approvals** → **1** *(again: **0** today, 1 at step 2 — §4.1.1)*
   - **Dismiss stale pull request approvals when new commits are pushed** — on
6. Tick **Require status checks to pass before merging**.
   - **Require branches to be up to date before merging** — on
   - In the search box, add each of the six names in §4.1.1, exactly as written
7. Tick **Do not allow bypassing the above settings**. **This one matters.** Without it a repository
   administrator — which is the identity this automation currently uses — is exempt from every box
   above, and the protection is advisory for precisely the actor it is meant to constrain.
8. Click **Create**, then **Save changes**.

#### 4.1.1 The six checks to require — exact names, measured

A required-check name that does not match the check's reported name **never becomes satisfied** and
the pull request waits forever, so these are reproduced verbatim from the API rather than retyped
from the Actions UI:

```
Static checks (no vendor needed)
Secret scan
Tests (REQUIRED -- real suites, clean install from composer.lock)
Tests (REQUIRED -- real suites, PayHere excluded and NOT fixed)
Merge-base regression gate (new failures only)
Security-sensitive diff: human checklist
```

Note the **two** ASCII hyphens in `REQUIRED --`, not an en dash.

Measured 2026-11-02 against PR #125's head `1a7a7628` and PR #126's head `abb1852f`:

```
gh api "repos/fullstackfull/syriastore98/commits/<head-sha>/check-runs?per_page=100" \
  --jq '.check_runs[] | "\(.conclusion // .status)\t\(.name)"' | sort
```

| check name as reported | require it? | why |
|---|---|---|
| `Static checks (no vendor needed)` | **yes** | |
| `Secret scan` | **yes** | |
| `Tests (REQUIRED -- real suites, clean install from composer.lock)` | **yes** | |
| `Tests (REQUIRED -- real suites, PayHere excluded and NOT fixed)` | **yes** | |
| `Merge-base regression gate (new failures only)` | **yes** | |
| `Security-sensitive diff: human checklist` | **yes** | the gate items 1–3 of this work repaired |
| `Is the PayHere blocker still there? (report)` | no | a report by name and by design — it is green while the blocker is still there |
| `Security-sensitive diff: push report (non-blocking)` | no | non-blocking by name; it fires on push, not on a pull request |
| `Security-sensitive diff: human approval` | **not yet** | §4.1.2 — and the reason is the opposite of the obvious one |

That is the *"applicable six existing CI checks"* of the owner's direction, named.

#### 4.1.2 Why the approval check must not be required yet — a correction

**An earlier draft of this document said requiring it today would make it
"required-but-unsatisfiable". That was wrong, and the truth is worse.**

`assert-human-approval.sh` defaults to `report`, and its own usage line reads *"Exit 0 = a
qualifying approval exists, **or `report` mode**"*. Measured on PR #126's head `abb1852f`:
`Security-sensitive diff: human approval` → **`success`**, on a pull request with **zero reviews**.

So adding it to the required list today would not block anything. It would produce a required check
that is **green unconditionally** — a name in the protection settings that looks like a human-review
requirement and constrains nothing. That is failure mode (2) in §6 wearing the costume of a fix, and
it is more dangerous than an unsatisfiable gate, because an unsatisfiable gate announces itself the
first time someone tries to merge.

Require it at step 4, when the literal is `enforce` and a qualifying approval can actually exist.

#### 4.1.3 Set required approvals to 0 today, 1 at step 2

GitHub's own **Required approvals** counter has the same problem from the other side, and it is the
genuinely unsatisfiable one: the automation opens every pull request as `fullstackfull`, GitHub
refuses to let an author approve their own pull request, and `fullstackfull` is the only identity
there is (§2). Set it to **1** at step 2, in the same sitting as the identity separation — not
before, or the next pull request cannot be merged by anyone.

**Everything else in step 1 is satisfiable today and worth doing today.** *Require a pull request*,
the six checks, *up to date before merging*, *block force pushes* and *do not allow bypassing* are
all immediately effective, and together they convert every gate in this repository from a report
into a constraint. That is the single highest-value change available in this whole document, and it
takes about four minutes.

---

### 4.2 STEP 2 — separate the automation's identity (recommended: a GitHub App)

The mechanisms and their trade-offs are in §2. **A GitHub App is the recommendation**, and this is
the concrete path:

1. As the account that will own the App: **Settings** → **Developer settings** → **GitHub Apps** →
   **New GitHub App**. *(An organisation-owned App is better than a personal one if this repository
   ever moves into an organisation — the App survives the move.)*
2. Name it for what it is, e.g. `storesyria-claude-code`. Its commits and pull requests will then
   appear as `storesyria-claude-code[bot]`, visibly distinct from the owner.
3. **Repository permissions** — grant only:

   | permission | level | why |
   |---|---|---|
   | Contents | Read and write | push branches |
   | Pull requests | Read and write | open and update pull requests |
   | Metadata | Read | mandatory |
   | Actions | Read | read check results |
   | Issues | Read and write | *only* if the automation should file findings |

   **Do not grant Administration.** That is the permission that would let the automation edit the
   very branch protection step 1 creates, and withholding it is what makes §4.5 true rather than
   merely intended.
4. Install it on `fullstackfull/syriastore98` **only** — *Only select repositories*, not *All*.
5. Generate a private key, mint an installation token from it, and give this session that token in
   place of the owner's PAT.
6. **Then** raise *Required approvals* to **1** (§4.1.3).

Checks that it worked, run from the session once the token is swapped:

```
gh api user --jq '.login'                                   # expect the App's bot identity, not fullstackfull
gh api repos/fullstackfull/syriastore98/pulls/<n> --jq '.user.login'   # expect …[bot] on a NEW pull request
```

Until both answer with the bot, step 4 must not be taken. Pull requests opened **before** the swap
keep `fullstackfull` as their author and will still be unapprovable by the owner — reopen them from
the new identity, or merge them under the owner's own review, consciously.

---

### 4.3 STEP 3 — enumerate the human reviewers

Create a trusted-logins file and pass it as `assert-human-approval.sh`'s fifth argument. One login
per line, `#` comments allowed:

```
# docs/security/trusted-reviewers.txt — logins whose APPROVED review satisfies the gate.
fullstackfull
```

**Leave the automation's login out of it.** A separate identity that may approve its own work is the
same defect one level up (§2, *"The rule that must survive separation"*).

### 4.4 STEP 4 — switch the approval check to enforcing, then require it

One literal, in `.github/workflows/security-attestation.yml`, the `approval` job's last step:

```diff
-          .github/scripts/assert-human-approval.sh "$PR_AUTHOR" "$PR_HEAD" /tmp/pr-reviews.json report
+          .github/scripts/assert-human-approval.sh "$PR_AUTHOR" "$PR_HEAD" /tmp/pr-reviews.json enforce
```

…plus the trusted-logins path as the fifth argument. Then, and only then, add
`Security-sensitive diff: human approval` to the required checks from step 1.

This belongs in its own pull request, reviewed by the owner under the new identity — which is itself
the first real exercise of the gate. Before merging it, confirm on that pull request that the check
is **red with no approval and green with one**; a flip that cannot be seen to change state has not
been shown to work.

**Order matters.** Step 4 before step 2 is an unsatisfiable gate. Requiring the approval check
before step 4 is a vacuous one (§4.1.2). Step 1 alone, without 2–4, leaves the checklist
advisory-but-required — a real and immediate improvement, and worth doing on its own.

---

### 4.5 Who holds what, afterwards

The owner's direction requires that *"the owner must retain control over approval and
protected-branch settings."* After steps 1–4 that is a property of the configuration rather than a
promise:

| | the owner | this automation |
|---|---|---|
| open and update pull requests | yes | yes |
| push to a feature branch | yes | yes |
| push to `main` directly | no (blocked by the ruleset; *"Do not allow bypassing"* / empty bypass list) | no |
| **submit an APPROVED review that satisfies the gate** | **yes — only the owner** | **no** (absent from the trusted-logins file) |
| **change branch protection or the ruleset** | **yes — only the owner** | **no** (no `Administration` permission) |
| edit the trusted-logins file | yes | it can propose a diff; the change needs the owner's own approval to merge, because that file lives behind this gate |

The last row is the one that closes the loop: once step 1 is in place, the automation cannot widen
its own permissions without an approval it cannot give itself.
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
