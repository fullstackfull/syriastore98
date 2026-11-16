# Master Product Plan — source verification

## ORIGINAL MASTER PLAN SOURCE: NOT FULLY VERIFIED

**The owner-approved "Store Syria Master Product Plan / Decision Register v1.0 (2026-10-14)" does
not exist as a document in this repository, and never has.** It is cited as an external authority by
seven files. Its six-phase *structure* and its decision-ID *index* survive; the decision *content*
does not.

This document records exactly what was searched, what was recovered, and what is missing, so that no
later reader mistakes a derived summary for the original plan. Nothing here is inferred where the
source is absent, and no requirement has been invented to fill a gap.

---

## 1. What was searched

| search | result |
|---|---|
| Every file ever added, deleted or renamed in **git history** matching `master.?(product.?)?plan` or `decision.?register` | **two files only** — `docs/development/phase-2/PHASE-02-DECISION-REGISTER.md` and `docs/development/phase-2.5/PHASE-2.5-DECISION-REGISTER.md`. Both are phase-scoped. |
| Working tree, `docs/` and `CLAUDE.md`, for the literal string `Decision Register v1.0` | 7 files, **all of them citing it as an external authority**; none containing it |
| `docs/development/phase-6/` | **empty directory** |
| Files defining `UX-0xx` / `ARCH-0xx` / `EX-0xx` content | **none** |

**Conclusion: the plan was never committed.** The citations are faithful references to a document
held outside this repository.

---

## 2. What IS recoverable, and from where

### 2.1 The six-phase structure — recoverable verbatim

`docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md:85-92` is an **owner-authored prompt
preserved verbatim and marked not to be edited**, and it quotes the plan directly:

> The approved Store Syria Master Product Plan / Decision Register v1.0, dated October 14, 2026,
> defines six phases:
> - Phase 01 — Foundation
> - Phase 02 — Merchant Experience
> - Phase 03 — Storefront
> - Phase 04 — Commerce
> - Phase 05 — Marketing & Analytics
> - Phase 06 — SaaS & Super Admin

This is the **highest-authority surviving statement** of the plan in the repository. `CLAUDE.md` §4
expands it with per-phase scope wording, and is a summary derived from it.

### 2.2 The decision-ID index — recoverable

The same file, at `:100-107`, names the decision families the owner required be preserved:

| family | range | count |
|---|---|---|
| `UX-001` … `UX-014` | user experience | 14 |
| `PRD-001` | product | 1 |
| `ORD-001`, `ORD-009` | orders | 2 named (the series may be longer) |
| `ARCH-001` … `ARCH-011` | architecture | 11 |
| `GOV-*`, `DATA-*` | governance, data | **count unknown — "applicable" only** |
| `EX-001` … `EX-012` | exclusions | 12 |

**At least 40 decision IDs existed.** The GOV and DATA families are named without a range, so their
size is unknown and must not be guessed.

### 2.3 Decision *meaning* — only partially inferable, never authoritative

`docs/development/phase-2/PHASE-02-TASK-LEDGER.md` cites these IDs **as the authority for individual
tasks**, which lets some meaning be inferred from use. For example:

- `PRD-001` governs honest metric presentation — its rows include *"Honest stat grid, every figure
  carrying its period"* and *"Refuse to display revenue/profit; label order value honestly"*.
- `UX-001…014` governs the design system and component layer — its rows include the Blade component
  layer and the design-system extension.

**This is inference from usage, not the original text, and is labelled as such everywhere it is
used.** No row in the traceability matrix may cite an inferred meaning as the requirement's wording.

---

## 3. What is NOT recoverable

1. **The wording of every decision ID.** `UX-001` through `UX-014`, `ARCH-001` through `ARCH-011`,
   `EX-001` through `EX-012`, `PRD-001`, `ORD-001`, `ORD-009` and the GOV/DATA families are named
   and never defined anywhere in the repository.
2. **The exclusions `EX-001`–`EX-012`.** These matter most and are the most completely lost: an
   exclusion is the record of something deliberately NOT built. Without them, a later reader cannot
   distinguish "never in scope" from "silently dropped" — which is the exact failure this audit
   exists to prevent.
3. **Owner-authored requirement documents for Phases 03, 04, 05 and 06.** What exists for those
   phases are *execution plans written during engineering* (`PHASE-03-EXECUTION-PREP.md`,
   `PHASE-04-EXECUTION-PLAN.md`, `PHASE-05-EXECUTION-PLAN.md`) plus final reports. Phase 02 is the
   only phase whose **owner-authored master prompt** is preserved.
4. **Any Phase 06 source at all.** `docs/development/phase-6/` is empty. The `PHASE-6-*` files under
   `docs/development/lynomia-integration/` belong to the **Lynomia integration project and are not
   the Master Plan's Phase 06** — a distinct numbering that must not be conflated.

---

## 4. Documents that must NOT be treated as the original plan

Stated explicitly because each has been or could be mistaken for it:

| document | what it actually is |
|---|---|
| `CLAUDE.md` §4 | a **summary** of the plan, derived from it |
| `docs/development/PROJECT-STATE.md` | a **status ledger**, not a requirements document |
| `docs/discovery/09-DEVELOPMENT-ROADMAP.md` | an **older ten-phase technical roadmap** (138 KB). The Phase 02 master prompt says directly: *"Do not reinterpret an older technical roadmap as permission to change this approved six-phase product plan."* **It does not override the six-phase plan.** |
| `PHASE-0x-EXECUTION-PLAN.md` | engineering plans written **during** a phase; they can omit a requirement and have |
| `PHASE-0x-FINAL-REPORT.md` | closure claims; **a report saying a requirement is done is not evidence that it is** |

---

## 5. Consequence for the traceability matrix

Because the decision content is unrecoverable, `MASTER-PLAN-REQUIREMENT-TRACEABILITY.md` is built
from the **best surviving derivations**, and every row carries the authority level of its source:

- **DIRECT** — the requirement's wording survives in an owner-authored source (Phase 02's master
  prompt; the owner directives preserved in phase documents).
- **DERIVED** — the requirement is stated in an execution plan or `CLAUDE.md` summary written from
  the plan, but the owner's original wording is not available.
- **INFERRED** — the requirement's existence is known from a decision ID citation, but its content
  is reconstructed from how it was used. **No row may be marked VERIFIED COMPLETE on INFERRED
  authority alone.**

**Total alignment with the original plan cannot be certified from this repository.** It can be
certified only against the external Decision Register v1.0. Until that document is supplied, every
coverage figure in this project is a figure **against the recoverable derivation**, and must say so.

---

## 6. What would close this

The owner supplying the authoritative **Master Product Plan / Decision Register v1.0 (2026-10-14)**,
or any export of it carrying the decision IDs and their wording — in particular the **`EX-001`–
`EX-012` exclusions**, which are the only record of what was deliberately left out.

This is the single piece of missing source material that prevents full scope certification. It is
recorded here rather than worked around, and independently executable engineering continues without
it.
