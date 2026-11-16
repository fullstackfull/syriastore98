# Phase 05 — Marketing & Analytics: executable plan

**Grounded in the actual repository and the existing WorkDo modules, not in a feature wish-list.**
Owner instruction: *"Create an executable Phase 05 plan grounded in the actual repository and
existing WorkDo modules"*, and *"Do not create a second analytics or campaign engine if equivalent
functionality already exists."*

Read first: `PHASE-05-METRIC-DEFINITIONS.md` (what each number means) and
`PHASE-05-REPORTING-SURFACE-INVENTORY.md` (the 70 surfaces, 53 ledger-dependent).

---

## 0. The dependency gate, and its current state

Phase 05 could not start until the ledger meant something. That work is on this branch:

| finding | state |
|---|---|
| **P4-F145** COD/Pending delivery-status 422 | **DONE** — echo is not a transition; 15 cases; both limbs falsified |
| **P4-F032** wallet-less store loses every credit | **DONE** — single provisioning service, locking-read recovery, verified on real MariaDB (13 checks) |
| **P4-F124** cancellation ledger semantics | **ENGINE DONE** — explicit, idempotent, reason-carrying reversal; settlement no longer rewritten. Three non-engine doors outstanding |
| **P4-F127** partial refunds | **DETERMINED BLOCKED** and documented, per instruction. Full verified refunds outstanding |
| **P4-F126** COD asserted Paid at creation | outstanding |
| **P4-F128** reconciliation + finance-screen audit | outstanding |
| **P4-F046** webhook.site egress + Benefit capture | outstanding |

**No Phase 05 metric may be built on an outstanding row above.** §9 of this plan says which
surfaces each one blocks.

---

## 1. What already exists, and must not be rebuilt

Measured from the repository. The single biggest risk to this phase is building a second engine
beside one of these.

| capability | where it already lives | verdict |
|---|---|---|
| order/sales reporting | `app/Http/Controllers/ReportController.php`, 3660 lines | **REUSE THE SURFACE, REPLACE THE PREDICATES.** It has zero `payment_status` predicates, so every figure counts orders irrespective of settlement. The screens, filters, exports and permissions are sound; the SQL is not |
| order data tables & filters | `app/DataTables/OrderDataTable.php` + `order/partials/filters.blade.php` | reuse; the filter list is already derived from constants rather than hand-typed |
| coupons & promotions | first-party coupon tables + `order_coupon_details`, `user_coupons` | reuse — but see §9: redemption is **not authoritative** |
| wallet / finance screens | `app/Http/Controllers/StoreFinanceController.php` | reuse; needs the new statuses admitted (§4) |
| campaigns | vendored `packages/workdo/` modules | **inventory before building.** Vendored code must not be edited; a first-party campaign surface may wrap it but must not fork it |
| notifications | `StoreNotificationService`, `FirebaseNotificationService` | reuse |
| design system | `public/css/store-syria-ds.css`, `docs/design-system/` | authoritative for all new UI |
| Flutter admin | `StoreSyria_app98` | parity surface; any API change is a contract change |

---

## 2. Slices, in dependency order

Each slice is independently committable, independently testable, and names the gate it needs.

### P5-1 — Finance ledger surfacing (no new metrics)
Admit `cancelled` and `refunded` to the finance screens and validators that today accept only
`in:paid,unpaid`, so a merchant can see and act on a reversed order. **Gate: P4-F124, P4-F127.**
Without it a reversed row is invisible to the one screen that owns it.

### P5-2 — The metric layer
One query object per metric in §1–§10 of `PHASE-05-METRIC-DEFINITIONS.md`, each with its tenant
predicate at the call site, its currency handling, and its caveat flag. **No controller computes
money inline.** Every metric gets the verification test its definition names.
**Gate: P4-F124, P4-F128.**

### P5-3 — ReportController predicate replacement
Point the existing report surfaces at the P5-2 metric layer, one surface at a time, with a
before/after figure recorded for each. **This changes numbers merchants currently see** — every
surface needs its delta stated, not just its code changed. **Gate: P5-2.**

### P5-4 — Merchant analytics dashboard
Sales, orders, conversion and customer insight, bilingual (AR RTL / EN LTR), contrast-checked in
light and dark, permission-gated and tenant-isolated. Built on P5-2 only.

### P5-5 — Campaign & promotion analytics
**Gate: P4-F134 / P4-F135.** Until those are resolved, coupon redemption is reportable only with
its two-directional error displayed. Campaign *management* is not blocked; campaign *outcome
measurement* is.

### P5-6 — Retention and repeat purchase
Derived from `orders` + `customers`, no new events invented. **Gate: P5-2** (a repeat-purchase rate
computed over unsettled orders is not a retention figure).

### P5-7 — Platform/operator reporting
The super-admin view of the same metrics across tenants. **Operator-first authorization**
(`MerchantPermissions::allowsAny()`), never bare `isAbleTo()`.

### P5-8 — Export
Wherever the existing export surfaces already support it. Exports carry the same caveats as the
screens; an export that drops a caveat is a defect.

---

## 3. What this phase will NOT do

- **Invent metrics, customer events or campaign outcomes.** If the data does not record it, the
  metric does not exist. There is no event stream in this repository and none is being created.
- **Build a second analytics or campaign engine.** See §1.
- **Change live coupon eligibility, quotas or redemption semantics.** P4-F134/F135 are to be
  *reviewed before relying on the data*, not fixed by this phase without a decision.
- **Edit vendored `packages/workdo/`.**
- **Migrate production, deploy, or run a real financial operation.**

---

## 4. Verification standard, per slice

Same as the financial work, because the owner's standard does not change between phases:

1. a demonstrated failing case;
2. a positive control;
3. a negative regression;
4. falsification — revert the fix, confirm the test reddens;
5. independent review for anything touching money, authorization or tenancy;
6. real MySQL where SQLite's type affinity or writer serialisation could hide the defect;
7. the full applicable gates on the final committed tree;
8. honest documentation, including every caveat the metric carries.

---

## 5. Honest statement of scope

Phase 05 as scoped by the owner — merchant analytics, platform reporting, sales and order metrics,
conversion and customer insight, campaign management, coupon and promotion analytics, loyalty,
retention, marketing performance, bilingual premium dashboards, permissions, tenant isolation and
export — is **substantially larger than the financial-policy work that gates it**, and the financial
work is not finished.

This plan is therefore written so that a partial delivery is still coherent: P5-1 and P5-2 are the
foundation everything else reads, and each later slice is independently valuable and independently
blocked. **Work completed will be reported as completed and work not started will be reported as not
started** — the final report states, per slice, which of the two it is, with evidence.
