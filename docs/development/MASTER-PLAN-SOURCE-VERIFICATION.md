# Master Product Plan — source verification

## ORIGINAL MASTER PLAN SOURCE: RECOVERED FROM OUTSIDE THE REPOSITORY (2026-11-16)

> **THIS DOCUMENT IS SUPERSEDED IN PART, NOT WITHDRAWN.** On 2026-11-16 the owner recovered the plan
> from preserved Store Syria project conversations and supplied it. It is committed verbatim under
> `docs/development/master-plan/`, and the 181 decision IDs are registered with their content in
> `docs/development/master-plan/DECISION-ID-REGISTER.md`.
>
> **What this document got right and keeps:** section 1's finding - *the plan was never committed to
> this repository* - is **verified against git history and remains true**. The seven citing files
> really were citing an external authority. Section 4's warnings about which documents must not be
> mistaken for the plan still stand, as do section 5's authority levels.
>
> **What changed:** the content is now available **from an external source**, so "unrecoverable" in
> section 3 is wrong and is corrected there. **What did not change:** a recovered requirement is not
> an implemented one, and no status moved because of the recovery.
>
> **One inference in section 2.3 is REFUTED by the recovered text.** It is struck through rather
> than deleted, because an inference that was reasonable, consistently applied and wrong is the best
> argument this project has for why INFERRED authority may never close a row.

**The owner-approved "Store Syria Master Product Plan / Decision Register v1.0 (2026-10-14)" was
never committed to this repository before 2026-11-16.** A recovered copy is committed now; no
version of it existed here before that date. It was cited as an external authority by seven files.
Its six-phase *structure* and its decision-ID *index* survived here; the decision *content* did not,
and had to come from outside.
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

- ~~`PRD-001` governs honest metric presentation - its rows include *"Honest stat grid, every figure
  carrying its period"* and *"Refuse to display revenue/profit; label order value honestly"*.~~
  **REFUTED 2026-11-16.** The recovered plan defines `PRD-001` as *محرر منتج كامل* - **"full product
  editor"** - the first of `PRD-001`...`PRD-006` in section 04-A, the product catalogue family, and
  cites it in 02-C and 02-E beside the product-editor requirements. It has nothing to do with metric
  honesty. The Phase 02 ledger attached the label to four honest-metric rows and this document
  canonised that usage as meaning. **The work those rows describe is sound and stands**; it answers
  to `ANA-006` and `ANA-007`, which are Phase 05 decisions it pre-satisfied in part. See
  `master-plan/DECISION-ID-REGISTER.md` section 4.1.
- `UX-001…014` governs the design system and component layer — its rows include the Blade component
  layer and the design-system extension.

**This is inference from usage, not the original text, and is labelled as such everywhere it is
used.** No row in the traceability matrix may cite an inferred meaning as the requirement's wording.

---

## 3. What is NOT recoverable

**[CORRECTED 2026-11-16 - items 1 and 2 are largely resolved by the recovery.]**

1. ~~**The wording of every decision ID.**~~ **RESOLVED for 178 of 181.** The recovery defines 181
   decision IDs across 25 families - 19 of those families were not even known to exist when this
   list was written. **Three texts are still missing**: `GOV-001`...`GOV-014` is supplied with 13
   texts and `ARCH-001`...`ARCH-011` with 9, so one governance and two architecture decisions remain
   unknown and must not be invented. For 64 further ids the family's obligations are recovered but
   the per-id pairing is not. See `master-plan/DECISION-ID-REGISTER.md` sections 1 and 5.
2. ~~**The exclusions `EX-001`-`EX-012`.**~~ **RESOLVED - all 12 recovered with their wording**, and
   each reconciled against the matrix, the gap register and, where it is a claim about behaviour,
   the code. None was found violated; `EX-011` could not be verified because Phase 06 has not
   started. See `DECISION-ID-REGISTER.md` section 3.
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

**[LARGELY CLOSED 2026-11-16.]** The owner supplied an export carrying the decision IDs and their
wording, including the `EX-001`-`EX-012` exclusions. What remains open is narrower, and is stated
exactly:

1. **Three decision texts** (1 `GOV`, 2 `ARCH`) are still unknown.
2. **Per-id pairing for 64 ids** is not derivable from the recovered text.
3. **Byte-fidelity to the owner's pre-reorganization original is not established.** The recovery
   document says of itself that it is *"a reorganization of owner-supplied approved decisions, not
   necessarily the unedited owner-authored original Decision Register"*.

So **alignment can now be certified against the recovered plan**, and still **not** against a
pre-reorganization original, should one differ. Every coverage figure must continue to say which of
the two it is measured against.
