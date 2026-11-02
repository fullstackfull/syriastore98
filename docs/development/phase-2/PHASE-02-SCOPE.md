# Phase 02 — Merchant Experience: scope, as executed

**Branch:** `claude/store-syria-p02-merchant-experience` · **base:** `5c63bcea` (`P2-GOV`)
**Authority:** Master Product Plan / Decision Register v1.0 (2026-10-14), six phases.
**Authorisation:** isolated Phase 02 engineering (owner, 2026-10-25). **Not** merge, **not** release.

This file says what Phase 02 is allowed to touch and — more usefully — what it is not. The reuse
decisions are in [`PHASE-02-REUSE-INVENTORY.md`](PHASE-02-REUSE-INVENTORY.md); per-task state is in
[`PHASE-02-TASK-LEDGER.md`](PHASE-02-TASK-LEDGER.md).

---

## 1. What Phase 02 is

The merchant-facing experience, on **both** surfaces:

| | surface | repository |
|---|---|---|
| web | merchant admin panel (`resources/views/**`, the `layouts.app` chrome) | `syriastore98` |
| mobile | merchant/staff admin app | `StoreSyria_app98` |

Eleven workstreams, A–K, from the owner's assignment. They map onto the delivery slices P2-00 …
P2-08 in the ledger.

## 2. The six-phase boundary, stated as refusals

Phase 02 is **presentation, navigation, and the merchant's own preferences**. It is not commerce
behaviour. Concretely, the following are out of scope and were refused when the work drifted
towards them:

| Temptation | Belongs to | Why it was refused here |
|---|---|---|
| a second pricing, stock, product or order engine | 04 Commerce | `ARCH`: keep the WorkDo engine. Phase 02 reads it, never replaces it |
| editing an order's products or quantities after creation | 04 (and `ORD-001` forbids it outright) | correction is cancel + linked replacement, not mutation |
| multi-warehouse stock, returns workflow, channels | 04 Commerce | not merchant *experience*, merchant *capability* |
| storefront, themes, visual editor, checkout UX | 03 Storefront | Phase 02 touches no `front_end/` or `themes/` view |
| campaigns, loyalty, analytics beyond what the backend already computes | 05 Marketing & Analytics | a new metric is a Phase 05 deliverable, not a tile |
| SaaS plans, super-admin operations, platform Odoo | 06 SaaS & Super Admin | `superadmin/**` is read for layout compatibility only |
| remediating the production dump exposure (`P1-SEC-40`, #120) | independent incident | **UI work does not remediate it.** It stays open and separately tracked |
| Phase 01's open release gates | Phase 01 acceptance | engineering completion here waives none of them |

Exclusions `EX-001`–`EX-012` from the Decision Register are inherited unchanged; nothing in Phase 02
re-opens one.

## 3. Standing protections (non-negotiable)

1. **Preserve approved work.** Inventory before building; never blind-merge a branch because its
   name contains *design* or *phase*. Measured in `PHASE-02-REUSE-INVENTORY.md` §1.
2. **Preserve Flutter and Lynomia Chat compatibility**, including API response shapes and status
   codes. `ApiResponser::error()` returns HTTP 200 by default and **the shipped Flutter app logs the
   merchant out on any 401** — so a new 401 on a path a client calls is a user-visible regression,
   not a hardening win.
3. **Green CI is necessary, never sufficient.** Phase 02 claims engineering state, never release
   readiness.

And the house rules that already applied: no edits under `packages/workdo/` (first-party
`LynomiaChat` excepted); no weakening a CI guard or deleting a test to get green; no history
rewrite, no force-push, no production migration, no deployment; `getCurrentStore()` semantics frozen.

## 4. Data honesty rules for Workstream C and H

The dashboard and the search surface are where a UI most easily lies. Three rules:

- **Sales ≠ payments ≠ settlements ≠ profit.** `Order::sum('final_price')` is *gross order value*.
  It is not revenue, not collected cash, not margin. It is labelled as order value and nothing else.
  (Phase 01 `P1-PAY-04` established *Delivered is not Paid*; `HANDOFF §13c` records that an order's
  stored components cannot always reproduce its own `final_price`. Phase 02 therefore displays the
  persisted total and derives nothing from its parts.)
- **No profitability figure is displayed**, because none is computed anywhere in the codebase.
- **Where the data does not exist, the screen says so** — an honest unavailable state, never a zero
  dressed as a measurement.

For search: every result is filtered **server-side** by tenant, by Laratrust permission and by module
entitlement. Frontend filtering would leak record existence across stores and is not acceptable.

## 5. Accessibility, RTL and theming are acceptance criteria, not polish

Every screen Phase 02 touches must be checked in four combinations — LTR/RTL × light/dark — plus
mobile width, keyboard traversal and contrast. The project already owns the tooling that proves it
(`docs/design-system/tools/`), so "verified" means those scripts ran, and screenshots exist under
`docs/development/phase-2/screenshots/`. A statically-inspected screen is **not** a verified screen,
and is reported as inspected.

## 6. Escalation list

Only these go to the owner: merge to `main`; deployment; a persistent or production migration;
destructive live-data operations; credential rotation; a material change to price, payment, stock or
order behaviour; a breaking Flutter or Lynomia Chat API change; a licence conflict; any departure
from the six-phase scope. Routine engineering — a component, a test, a refactor, a commit — does not.
