# Phase 2.5 — the remaining work as large design packages

**Owner directive, 2026-11-06:** stop fragmenting the design work into one-fix waves. Organise the
remainder into roughly 5–8 Laravel packages, 3–5 Flutter packages and 2–4 verification packages.
These are organisational targets and **not permission to omit a screen or reduce verification**.
The completion criterion remains the full 295-surface inventory.

**Historical wave numbering is preserved.** Waves 1–33 and their reports are untouched; this file
is the forward structure, not a rewrite of the record. Packages carry letters, waves carry numbers,
and a package's commits say which package they belong to.

## The arithmetic, from the generated matrix at `dac7f41e`

| state | count |
|---|---:|
| PREMIUM VERIFIED | 15 |
| REDESIGNED AND VERIFIED | 16 |
| BLOCKED | 9 |
| OUT OF SCOPE | 1 |
| **PENDING** | **254** |
| total | 295 |

Every one of the 254 is assigned below. The package column sums to 254 by construction — the
assignment is generated from the inventory's own area field, not chosen by hand.

## Laravel packages

| pkg | what it covers | inventory areas | pending |
|---|---|---|---:|
| **A** | Navigation shell, dashboard and the shared components they are built from. The sidebar rail and nav tree, header/topbar, command palette, page headers, breadcrumbs, the footer bundle, the KPI surface and the "By Sales" body — plus the `x-ss.*` component set, `x-datatable` and the design-system stylesheet, because the shell IS those components and polishing one without the other produces two visual languages. | Merchant admin NAVIGATION SHELL (12) · Merchant dashboard and KPI presentation (7) · Phase 2.5 Wave A, the shared DS component set (30) | **49** |
| **B** | Products and catalogue. Product list and grid, the product editor, media/image workflow, categories, brands, labels, attributes, tags, questions and the taxonomy family. | PRODUCTS (12) · CATALOGUE taxonomy (28) | **40** |
| **C** | Orders and customers. Order list, detail, grid, Kanban, quick-view, refunds, filters; customer list, detail, timeline, addresses, carts and wishlists. | ORDERS (11) · Customers and customer management (17) | **28** |
| **D** | Finance and analytics. Wallet, payouts, withdrawal queue, transaction lists, summary cards, stock reports and the whole reports/analytics presentation, plus notifications. **Presentation only** — no calculation, balance or settlement path changes without separate authorisation. | Stock/inventory + finance/wallet/payout (10) · Reports/Analytics, Notifications, chart family (25) | **35** |
| **E** | Settings, staff and integrations. The settings hub and its tabs, staff/roles/permissions interfaces, dialogs, and the merchant-facing integration screens. | Staff/Roles/Permissions + Merchant Settings & Integrations (30) | **30** |

**Laravel pending total: 182.** Five packages, each a coherent merchant area rather than a list of
unrelated fixes. Each ends with its own before/after captures in the four combinations and a
matrix update; none is complete until every surface in its areas carries a state.

## Flutter packages

| pkg | what it covers | pending |
|---|---|---:|
| **F0** | **Rendering-evidence infrastructure, first.** The verification blocker is infrastructure, not permission to skip Flutter. Establish a reproducible harness that produces real frames; keep BLOCKED classifications honest where a device genuinely is unavailable, and never present Flutter-web screenshots as native verification. Owner items #37/#57. | 0 (enables the rest) |
| **F1** | Flutter design system and shell: brand tokens, typography, spacing, iconography, the bottom navigation and the app's shared widgets. | ~20 |
| **F2** | Flutter orders and products: lists, details, create flows, status presentation. | ~26 |
| **F3** | Flutter dashboard, customers, profile and settings, plus remaining screens. | ~26 |

**Flutter pending total: 72**, plus 3 already BLOCKED = the 75 inventoried surfaces. The split is
by screen family; exact membership is fixed when F0 makes verification possible, because a package
that cannot be verified cannot be closed.

## Verification and remediation packages

| pkg | what it covers |
|---|---|
| **V1** | Cross-surface visual verification: drive every surface a package touched in en-LTR and ar-RTL, light and dark, at desktop and phone widths; measure the premium checklist; promote only what passes, with four captures per surface. The generated matrix refuses a promotion whose captures are absent. |
| **V2** | Adversarial design review and remediation. Independent lenses read the diff rather than the commit messages, every finding is put to a skeptic, and the survivors are fixed. Waves 32 and 33 both found regressions this way that green CI had not. |
| **V3** | Acceptance assembly for the owner's visual review: before/after pairs, the Arabic report, and the open owner decisions stated as decisions rather than buried. |

## What governs every package

- **Premium is not a contrast ratio.** A surface is not premium because it renders, answers 200 or
  passes WCAG. Layout, information hierarchy, iconography, spacing and responsiveness are the work;
  the accessibility and contrast repairs already landed are a floor to preserve, not the goal.
- **Preserve every function, field, permission and workflow.** Nothing is removed or hidden without
  an accessible way to reach it.
- **The four design skills are applied and the application is documented**, including recommendations
  that were rejected and why.
- **No merge to `main`, no production deployment**, and no change to payment logic, wallet balances,
  tenant ownership, financial calculation or commercial rules for a visual reason.
