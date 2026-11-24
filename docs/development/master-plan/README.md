# The recovered Master Product Plan — provenance

Two documents, both committed verbatim as received from the owner on 2026-11-16. **Neither may be
edited.** Corrections, reconciliations and status belong in the traceability matrix and the gap
register, never in these files.

| file | what it is | authority |
|---|---|---|
| `MASTER-PRODUCT-PLAN-v1.0-BASE.md` | the organized Arabic Master Product Plan / Decision Register v1.0, reference date **2026-10-14** | **historical reference, preserved unchanged** |
| `MASTER-PRODUCT-PLAN-v1.0-WITH-AMENDMENTS.md` | the same base text (Part I) **plus** three separately-labelled approved addenda (Part II), application guidance (Part III) and verbatim owner approval excerpts (Part IV) | **the consolidated approved planning reference** |

**The amended document contains the base document verbatim.** Measured, not assumed: a unified diff
of the two is 168 added lines and **zero removed lines of content**. So Part I can be relied on as
the unaltered base text, and every difference between the two files is an addition that Part II–IV
labels and dates by source.

## What the source says about itself

The recovery document states its own limits, and they are repeated here so that no later reader
over-reads it:

- The base text was recovered from a preserved Store Syria ChatGPT project conversation
  (attachment `معالجة تسريب البيانات.txt`), and that conversation says it is itself **"a
  reorganization of owner-supplied approved decisions"** — *not necessarily the unedited
  owner-authored original Decision Register*.
- So this recovery **resolves** the claim that the decision content and the exclusions were
  unavailable anywhere. It does **not** prove that every pre-reorganization owner-authored decision
  text was reproduced byte for byte.
- No deployment, merge, production migration or policy change is authorized by it.

`docs/development/MASTER-PLAN-SOURCE-VERIFICATION.md` is **corrected, not discarded**: its central
finding — that the plan was never committed to this repository — remains true and verified against
git history. What has changed is that the content is now available from an external source.

## The three approved addenda, kept separately identifiable

| addendum | scope | where it lands |
|---|---|---|
| **A** | Phase 2.5 — Premium Merchant Experience & Visual Excellence, approved as a **separate milestone between Phase 02 and Phase 03**, explicitly *not* a renumbering of the six phases | Phase 2.5 |
| **B** | the official Store Syria website and a bilingual documentation centre as a **separate package inside Phase 03**, built by assessing and extending the existing WorkDo LandingPage rather than adding a CMS | Phase 03 |
| **C** | comprehensive premium UI/UX for **all** Super Admin screens, as part of Phase 06's **acceptance criteria** | Phase 06 |

Addendum A carries one condition that is a scope rule rather than a design one: **security,
authorization, functional and basic-accessibility fixes must not be postponed out of Phase 02 by
reclassifying them as Phase 2.5 polish.**

## Decision IDs

181 decision IDs across 25 families are now defined with content. The index and the per-ID
disposition are in `docs/development/MASTER-PLAN-REQUIREMENT-TRACEABILITY.md`; the ones not
complete are in `docs/development/MASTER-PLAN-GAP-REGISTER.md`. **Recovery of a requirement's text
is not evidence that it was implemented**, and no gap was marked complete or approved by the act of
recovering the plan.
