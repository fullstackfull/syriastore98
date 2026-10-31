# Phase 02 — what the recount actually counted, and why 86 PARTIAL is not 86 projects

**Purpose: to stop a coverage map being read as a backlog.** The owner's instruction is explicit —
*"86 PARTIAL must not become 86 new feature projects"*, and *"preserve a clear distinction between
functionality that is genuinely missing, functionality already implemented but lacking regression
evidence, and functionality already complete and verified."* This file is that distinction, and it
adds no requirement to the approved scope.

---

## 1. Why the count moved from 92/11/1 to 44/86

**The requirements did not change. The question did.**

| | the old snapshot | the recount |
|---|---|---|
| asked | *"does a ledger row exist for this requirement?"* | *"is it implemented **and** would anything catch its regression?"* |
| dated | 2026-10-25 | 2026-10-31, against current code in both repositories |
| method | trace the prompt to the ledger | eleven auditors reading the code, each with an adversarial pass attacking its own DONE claims |

`PHASE-02-COVERAGE-AUDIT-2026-10-25.md` states its own question at the top, and it is the first one.
That is a legitimate thing to measure and it was measured correctly. It is simply not the same
question, so **the two numbers are not comparable and the difference is not a regression.** Read as
one, it would say the project went backwards in six days, which is false.

**14 DONE claims were overturned** by the adversarial passes. Those are the only places where the
recount contradicts rather than re-scopes, and each is named in the per-workstream detail.

---

## 2. The 104 and the 130 — the original scope is unchanged

The Master Execution Prompt's Workstreams A–K carry **104 numbered product requirements**. The
recount returned **130 rows** because each auditor also classified the **"Do not …" constraints and
cross-cutting verification obligations** stated inside its own workstream section — things like
*"do not introduce a second competing design system"*, *"preserve `Menu::add()` entitlement"*,
*"report SDK/env limits accurately"*.

Those 26 extra rows are **obligations the prompt already imposes, not new product scope.** They were
counted because a requirement met by violating one of them is not met. No feature was invented, and
nothing in this file widens what Phase 02 is for.

| | count |
|---|---|
| original A–K product requirements | **104** |
| constraints and verification obligations stated in the same sections | **26** |
| recount rows | **130** |

---

## 3. The three-way split the owner asked for

Of the 86 not-DONE rows, classified by **what the named remaining work actually is**:

| class | rows | what it means | what it costs |
|---|---|---|---|
| **EVIDENCE** | **17** | built and working; nothing would catch a regression | one test each |
| **MISSING** | **14** | functionality genuinely absent | real implementation |
| **BOTH** | **47** | part built, part absent — usually the feature exists on some screens and not others | a slice each |
| **UNCLEAR** | **8** | the auditor could not settle it, or it turns on an owner decision | triage |

By workstream:

| WS | | EVIDENCE | MISSING | BOTH | UNCLEAR |
|---|---|---|---|---|---|
| A | Design System | 1 | — | 9 | — |
| B | Workspace & Navigation | 1 | 2 | 4 | 1 |
| C | Dashboard | 2 | 3 | 3 | 2 |
| D | Products | 1 | 1 | 4 | 1 |
| E | Orders | 3 | — | 5 | — |
| F | Forms & Panels | 3 | 1 | 5 | — |
| G | Preferences | 1 | 1 | 2 | — |
| H | Search & Notifications | 1 | 1 | 1 | — |
| I | Onboarding | — | 1 | 5 | — |
| J | Other Screens | — | 2 | 4 | — |
| K | Flutter | 4 | 2 | 5 | 4 |

**The 17 EVIDENCE rows are the cheapest real improvement available** and they are where the owner's
*"minimum meaningful regression evidence without rewriting working features"* applies exactly: the
feature stays untouched, one test is added.

---

## 4. The one finding that is not a row at all

Both the Workstream A and Workstream B auditors arrived at the same structural fact independently,
and it explains a large share of the EVIDENCE and BOTH rows:

> **Nothing in CI exercises any rendered property of the merchant UI.**

`.github/workflows/ci.yml:627` excludes the browser suite by decision — it needs Playwright,
Chromium and eight rendered fixtures — and `tests/Feature/DesignSystem/PilotScreenContractTest.php`
says in terms that contrast ratios, focus rings, tab order, RTL geometry and overflow are covered by
no test.

So the 100-check RTL × theme × viewport evidence in `docs/development/phase-2/` is a **point-in-time
artifact, not a gate**, and a direction, theme, viewport or keyboard regression ships green.

**This is not a request to enable the browser suite.** The owner's instruction is to *"respect
existing CI decisions; do not silently enable expensive suites or claim source tests prove visual
behavior."* Both halves are observed here: the suite stays out of CI, and the source ratchets added
in this phase say in their own files that they assert templates rather than rendered pixels. The
proportionate answer for the critical shared components is a real browser run captured as evidence
plus a cheap structural check — which is what `MerchantShellDesignSystemTest` is, and it says so.

---

## 5. How to read a row, so this file is used rather than re-derived

Each of the 86 came back with a named `file:line` and the smallest change that would close it. The
full set is in the recount output; the per-workstream summaries, the overturned DONE claims and the
stale documentation claims are in `PHASE-02-HANDOFF.md`.

**Order of work, as the owner set it:** finish the genuinely MISSING approved functionality first,
add the minimum meaningful evidence for what is already built, and do not rewrite working features
to make them look newer. A BOTH row is finished when the named absent part exists — not when the
whole requirement has been rebuilt.
