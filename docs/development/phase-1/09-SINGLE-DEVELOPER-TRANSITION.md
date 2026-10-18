# 09 — Single-developer transition, and the real state of Phase 01

**Status of this document.** It records a change of ownership and the measured state of Phase 01
at the moment of that change. It does **not** replace
[`docs/discovery/09-DEVELOPMENT-ROADMAP.md`](../../discovery/09-DEVELOPMENT-ROADMAP.md), which
remains the phase plan and its evidence; this is the delta on top of it. The six approved phases
are unchanged.

## 1. What changed

The two-Claude parallel model (Claude A — backend and core; Claude B — web design system and
merchant UI) is discontinued after the current assignments. One developer now owns the Laravel and
WorkDo backend, web administration and Super Admin, the web design system and UX, storefronts and
themes, security, commerce and integrations, and testing, documentation and delivery.

The comprehensive Flutter redevelopment stays a **separate future project**, outside the web
delivery schedule. Small owner-approved compatibility fixes are the only Flutter-adjacent work
inside these phases, and the reason is recorded in the roadmap's adjustment 6: repo B is repo A's
client, its endpoint paths are compiled into binaries already on merchants' phones, so a
server-side rename breaks every installed copy.

Both branches are preserved. Nothing was force-pushed and no published history was rewritten.

| Branch | Role now |
|---|---|
| `claude/store-syria-p01a-ci-foundation` | the continuing development base |
| `claude/store-syria-p01b-design-foundation` | preserved unchanged, as the provenance record for the design system |

## 2. What Phase 01 has actually delivered

Three things, all on the continuing branch and none merged to `main`.

**The CI and regression foundation (P01-A′).** A known-outcome gate that asserts the failing and
skipped SET by name rather than a count, so a new failure and a silently-fixed old one both break
the build; a dependency-audit ratchet; a vendor-modification guard over `packages/workdo/`; a
sensitive-diff checklist; cross-repository API contract tests; and a repo-wide parse check. Eleven
guard scripts under `.github/scripts/`.

**Order-document and privilege security (P01-SEC-2, S12, S13, S14, P01B-SEC-1/2).** Covered in
§3, because its accurate state is a list of what is closed and what is not.

**The imported design system (P01B).** Design tokens and component families in
`public/css/store-syria-ds.css`, Arabic RTL and English LTR, dark mode following the host's own
stylesheet swap rather than a competing class, one pilot screen (attribute options), a component
gallery, an admin layout architecture note, and two verification tools. Imported by cherry-pick
with provenance recorded; **re-verified by execution on import** — 40/40 WCAG contrast pairs and 66
browser checks, 0 failures.

It is **opt-in per page** (`@include('layouts.includes.store-syria-ds')`), not a global layout
change, so it cannot affect a screen that has not adopted it. That is why adopting it screen by
screen in Phase 02 is safe.

## 3. Security: closed, and not closed

Closed, each with executed regression tests and each verified by removing the fix and watching the
tests fail:

| Ref | What |
|---|---|
| P01-SEC-2 | 17 order-document uris guarded against anonymous and cross-tenant access |
| S12(a)(b) | ownership enforced on both order-detail API endpoints, contract preserved |
| S13 | a merchant can no longer mint a platform operator through user creation |
| S14 | order export gated; the guard no longer refuses a doubled principal |
| P01B-SEC-1 | attribute options: cross-tenant read, reorder, update and delete all closed |
| P01B-SEC-2 | add-customer gated server-side; the `?? 1` store fallback closed |

**Not closed, and Phase 01 is not complete until these are decided:**

- **S12(c)** `GET {storeSlug?}/customerpayment/{id}` is an anonymous cross-tenant order document
  in PartialPayments. Gating it removes a customer payment-by-link journey, so it needs an owner
  decision, not an invented token protocol.
- **Customer-facing documents generally.** The storefront document link breaks for a visitor who
  is not signed in. Preserving it safely needs a scoped expiring document token; this repository
  has no signed-URL machinery for documents.
- **S11** 47 of 70 module route files declare a `['web']`-only group, 184 routes inside them. An
  upper bound, not the exposure — many are legitimately public storefront routes — but it means no
  per-module fix closes the class.
- **S14 residue** untriaged order-adjacent surfaces, listed in
  [`../../security/P01-SECURITY-WORK-QUEUE.md`](../../security/P01-SECURITY-WORK-QUEUE.md).

## 4. Phase 01 acceptance: what is still owed

1. The three open security items above, each either closed or formally accepted by the owner.
2. Branch protection and required status checks on `main` — a repository setting no script can
   assert, carried from P01-A′.
3. Whether `BackupRestore` is active on the dev server (S1). The same
   `SELECT module, is_enable FROM add_on_managers` answers S11's triage too.
4. GATE F: the six undeclared money tables. Unchanged, still blocking, still an owner/DBA decision.
5. Review and merge of this branch into `main` — last, after review, by the owner's instruction.

## 5. The six phases, with ownership corrected

| Phase | Scope | Change from the roadmap |
|---|---|---|
| 01 Foundation & Stability | CI, regression gates, security, the design-system foundation | **in progress.** The design system is a Phase 01 deliverable now that it is imported and verified |
| 02 Merchant Web Experience | adopt the design system screen by screen, merchant admin UX | unchanged in scope. Do **not** start before Phase 01 acceptance |
| 03 Storefront & Theme Builder | storefront and themes | unchanged |
| 04 Advanced Commerce | advanced commerce, **merchant Odoo** | unchanged. Odoo stays here and is **not started** |
| 05 Marketing & Analytics | marketing, analytics | unchanged |
| 06 SaaS & Super Admin | SaaS operations, **platform Odoo** | unchanged. Odoo stays here and is **not started** |

Phase 00 discovery is complete. Every phase is now single-owner; the parallel-track structure in
the roadmap's dependency map should be read as sequencing for one developer rather than as
concurrent assignments.

## 6. How the design system should be adopted in Phase 02

Recorded here because the pilot already answers most of the questions, and the answers are
measured rather than preferred:

- **Opt in per screen.** The include is one line in a `@push('css')`. A screen that has not opted
  in is byte-for-byte unaffected.
- **Follow the host's dark signal, never a competing one.** The host swaps the stylesheet in
  `#main-style-link`; the include reads that same signal, so it cannot disagree with the layout.
  See [`ADMIN-LAYOUT-ARCHITECTURE.md`](../../design-system/ADMIN-LAYOUT-ARCHITECTURE.md).
- **Preserve the functional contract explicitly.** The pilot kept every route, `data-ajax-popup`
  hook, delete form and `show_confirm` binding, and
  `tests/Feature/DesignSystem/PilotScreenContractTest` asserts them so a later screen cannot drop
  one silently. Adopt that test shape per screen.
- **Authorization is not a UI concern.** The pilot's own harness noted that a view-only merchant
  could still drag to reorder; that was a server defect (P01B-SEC-1) and was fixed in the
  controller. Hiding a control is never the fix.
