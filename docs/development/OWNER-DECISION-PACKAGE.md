# Consolidated owner-decision package

**Purpose.** One place for every decision that is genuinely the owner's, so no workstream waits on a
question nobody has been asked. Requested by the owner directive of 2026-11-25 §9.

**What this file is NOT.** It is not a new plan, not a status report, and not a list of engineering
work. Anything engineering can decide for itself is absent by design. Every entry below blocks real
work, and the work it blocks is named.

**Standing rule this file obeys:** engineering does not invent financial, commercial, legal, consent
or authorization policy. Where an option would do that, it is marked and not taken.

---

## A. Decisions that block the most work

### A1 — The disabled-application boundary: `storefront` (100 routes) and `oauth` (16)

| | |
|---|---|
| **Where** | `docs/development/phase-6/PHASE-06-DISABLED-APPLICATION-BOUNDARY.md` §4; preserved verbatim from #204 |
| **The choice** | When a merchant's *application* is switched off, what happens to (a) the 100 routes categorised `storefront` and (b) the 16 categorised `oauth`? |
| **Already decided, do not re-ask** | `payment-callback` (87) and `webhook` (2) — keep a safe, authenticated, idempotent reconciliation for previously initiated payments; do not permit NEW transactions. `own-credential` (6) and `owner-exemption` (2) were settled earlier. `guarded` (22) and `inert` (8) are orthogonal. |
| **Engineering recommendation (a)** | Application disablement should **not** automatically disable independent customer-facing storefront functionality. A shopper browsing a catalogue is not using the disabled application. |
| **Recommendation (b)** | Refuse NEW OAuth authorizations for a disabled application. A previously initiated callback may need validated rejection, cleanup or safe protocol handling — **without** granting a token or restoring a disabled privilege. |
| **Consequence of deciding (a) "disable"** | 100 routes begin refusing, which takes a merchant's storefront down as a side effect of an administrative action. Visible to shoppers. |
| **Consequence of deciding (a) "leave"** | A disabled application's storefront keeps serving. This is the recommendation and the current behaviour. |
| **Blocked** | The remaining half of P06-2B. Nothing else. The payment half is built and committed. |

### A2 — The three development-only payment migrations

| | |
|---|---|
| **Tables** | `plan_purchase_intents`, `order_gateway_references`, `module_payment_initiations` |
| **The choice** | Promote any of them to production, or leave them development-and-test-only? |
| **Why it matters now** | Each is the durable proof a payment was initiated. Without `module_payment_initiations` the disabled-application payment boundary is **inert in production by construction**: a module return cannot be told from a new transaction, and refusing it would turn an outstanding migration into a lost payment. Starting a payment is refused either way. |
| **Recommendation** | Promote `module_payment_initiations` and `plan_purchase_intents`. Both are additive, both are written only by first-party code, and `GateFSchemaGuard`'s pattern exists for exactly this. `order_gateway_references` has no production writer, so promoting it buys nothing yet. |
| **Consequence of leaving them** | The payment boundary and the plan-intent binding stay inert in production. The code is correct and does nothing. |
| **Blocked** | Production effect of P06-2B's payment half, and F229's plan-purchase binding. |
| **Not engineering's** | A migration against production. |

### A3 — Scheduling `payments:reconcile`

| | |
|---|---|
| **The choice** | Should the reconciliation command run on a schedule, and at what cadence? |
| **Recommendation** | Hourly, **report-only** (no `--apply`), until one real provider handshake has been observed; then daily with `--apply`. |
| **Consequence of not scheduling** | A captured payment that activated nothing is found only when somebody runs the command by hand. |
| **Consequence of scheduling with `--apply` now** | No live Paytm transaction has ever been verified from this project, so the first real settlement would happen unattended. |
| **Blocked** | Nothing. The command exists, is committed, and is safe to run by hand. |

### A4 — `PAYTM_CALLBACK_ENABLED`

| | |
|---|---|
| **The choice** | Open the Paytm provider callback route? |
| **Needs** | One real sandbox payment driven by somebody holding Paytm credentials. **External dependency, not a judgement.** |
| **Blocked** | Verification of the provider-callback path end to end. The code path is built, reviewed and tested against a signed synthetic response. |

---

## B. Financial and data policy

### B1 — Should a late payment ever auto-apply? (`ENTITLEMENT_MOVED_ON`)

`assignPlan()` SETS the plan and recomputes the expiry from *now* — it does not accumulate. So
applying a three-day-old payment is not "recording a purchase", it is **changing what the buyer
currently holds**: a measured case downgraded a buyer who had since bought a dearer plan and
switched off two of their three stores.

Current behaviour refuses and records. **Recommendation: keep refusing**; a person decides per case,
and `--acknowledge-intent` now lets them close it on the record. Deciding "auto-apply" would require
a rule for what happens to the entitlement the buyer already has, which is a commercial policy.

**Blocked:** nothing. **Not engineering's:** the rule itself.

### B2 — `product_variants.store_id` is nullable and has been measured drifting (GAP-237)

`ProductController:2873-2895` records an operator's no-op save stamping `store_id = 1` on a
**store-7** product's variants, and names the store-scoped readers that then stop seeing them — one
of which a drifted row *outlives the deletion of its own store*. The application holds **two
contradictory policies**: `productOfCartLineStore()` deliberately accepts a NULL `store_id` and
refuses "only a provable mismatch", while every other reader refuses NULL outright.

| | |
|---|---|
| **The choice** | Is a NULL or drifted `store_id` a provable mismatch or not? One answer, applied to every reader. |
| **Measure first** | `select count(*) from product_variants v join products p on p.id = v.product_id where v.store_id is null or v.store_id <> p.store_id` — needs a production replica. |
| **Recommendation** | Treat it as NOT a mismatch (follow `productOfCartLineStore`), and derive the store from the parent product, which is what the GAP-131 fix now does for `cart_check_guest`. |
| **Blocked** | A consistent policy across the readers. The one handler that mattered is already safe. |
| **Not engineering's** | Backfilling the column — a data repair on a live table. |

### B3 — The order↔wallet axis of P04-FIN-04

Needs `report-settlement-entitlement.php` run against a **restored copy of production**.
**External/operator dependency.** The reconciliation surface it shares a row with is built.

---

## C. Authorization decisions that change what a live user can do

### C1 — GAP-003 / the `AddCustomer` permission gate

Gating it would make a **shipped Flutter endpoint start refusing** for staff who can use it today.

**Recommendation:** gate it, operator-first, returning 403 (never 401 — the app force-clears its
session on a 401). The create side of the same resource is already gated on `Manage Customer`, and
the *delete* side is not, so one staff member can currently delete a customer they cannot create.
That asymmetry is the stronger argument for gating than the row itself.

**Blocked:** GAP-003's remaining half.

### C2 — GAP-218 / should the operator be ADMITTED to the abandoned-cart show and send legs?

The four legs now **refuse** an operator who has chosen no store (GAP-077, committed). That is pure
narrowing and needed no decision. The open question is the opposite: should the platform operator be
*admitted* to those legs at all, as they are to the list leg?

**Recommendation:** yes for the read (`show`), no for `destroy` and the two send legs — admitting the
operator to a list is not admitting them to a delete or an outbound message, which is what
`MarketingScreenAccess`'s own docblock already says.

**Blocked:** nothing. Refusal is in place either way.

### C3 — GAP-044 / D10: may a holder of `Manage Store Setting` alone write four specific names?

Recorded as blocked on an owner decision since 2026-10-22; the decision exists in
`15-DEFERRED-OWNER-DECISIONS.md:299` but the ledger row was never updated. **This may already be
answered** — it needs reading against that record rather than re-asking.

---

## D. Marketing, consent and analytics policy

### D1 — GAP-226 / recovery nudges: transactional or promotional?

The pipeline detects, deduplicates and queues, and **sends nothing, by owner decision**. Two
switches are off: `marketing_consent.enforcement` (D8) and `marketing.nudge_min_interval_seconds`
(GAP-219).

Two parts: **(1)** is a recovery nudge transactional (may send once consent enforcement is on) or
promotional (also needs the cadence)? **(2)** when sending is approved, how is the existing backlog
released — all at once, only candidates newer than a date, or not at all? The queue keeps
`detected_at` precisely so this is a choice: on a store with a year of history, releasing everything
would message every basket ever abandoned.

**Blocked:** all outbound recovery messaging. Engineering has nothing further to do without (1).

### D2 — GAP-225 / threshold alerts: delivery, and trend/anomaly/forecast

**(a)** The alert is written to `store_notifications` with `push_sent => false` and sends nothing. Is
it transactional (may send while D8 enforcement is off) or must it wait for the consent framework?
**(b)** A trend needs a baseline, an anomaly a notion of normal, a forecast a model — each is a
business policy, and the requirement row names inventing one as the trap. Engineering has already
built the comparison, the configuration surface, the schedule and the deduplication.

### D3 — GAP-224 / the Arabic catalogue

68 vendored module catalogues redefine 1,589 of our keys, **1,126 with different Arabic**, much of it
wrong in sense (`"Print"` → *a printing press*). Measured favourable: **our catalogue wins**, so
those are inert wherever we define the key. The live exposure is keys only a module defines —
**753** in `LynomiaChat`, 5 in `LandingPage`.

**The choice:** commission an Arabic review of (a) the 758 module-only keys that render today, and/or
(b) the 1,126 shadowed ones that render only if our entry is removed? Retranslating is a bilingual
content review and would mean inventing wording for vendored modules this project must not edit.
**Engineering can add a CI census pinning the 1,589 overlap so a new disagreement is seen** — that
needs no decision and is the recommendation.

---

## E. Acceptance and evidence

### E1 — GAP-078 / does 24 premium-verified surfaces of 295 satisfy the premium objective?

The owner saw 96 captures of 24 surfaces. The other 225 "redesigned and verified" surfaces were never
put to the owner for a judgement on whether they meet the premium bar. **The choice:** accept, or
name the areas owed a second premium pass.

### E2 — GAP-062 / GAP-084 / Flutter visual evidence

No Flutter screen of this app has ever been rendered and seen by anyone or anything in these
containers; goldens cover 2 widgets of 75 surfaces. **Route 2 (an Android emulator in CI) needs no
owner decision** and is the recommendation. The alternative is to record explicitly that Flutter
evidence stops at the widget harness.

### E3 — The security attestation's human checklist

**Must stay red.** It requires a person to work `.github/SECURITY-DIFF-REVIEW.md` and leave a marker
recording **who** gave it. An agent typing that marker would defeat the control rather than satisfy
it. It is not a required CI job and blocks nothing in development. **No decision is requested here —
this entry exists so nobody mistakes it for one.**

### E4 — GAP-231 / GAP-232 — two recovered requirements whose scope cannot be read from their text

`ADM-001` ("a comprehensive platform command dashboard"): an operator dashboard and
`/platform/analytics` exist. Does `ADM-001` describe what exists, a superset, or a replacement?
Engineering cannot tell from the recovered one-line text.

`ADM-002` ("a Store 360° space"): **not located** — zero hits across `app/`, `resources/`, `routes/`
and `packages/workdo/`. Phase 06 scope, not started.

---

## F. First-party module behaviour

### F1 — LynomiaChat's six self-scheduled reconcilers keep running for a switched-off module

For a bundled first-party integration that is probably right. It is a decision and it is unstated.
**Recommendation:** leave them running and say so in the module's record, since a chat integration
going silent mid-conversation is worse than one that keeps reconciling.

---

## What is NOT in this file, deliberately

- Anything engineering can decide. The `OrderList` owner-limb asymmetry, the storefront API's
  remaining unscoped reads, the eight ungated admin-API families, the `substr`→`mb_substr` sweep and
  the missing `empty($store)` guards are all engineering and are in the gap register.
- Production deployment, credential rotation, live-host changes, repository visibility and merging
  the stacked PR chain. Those are owner actions, not decisions, and the standing restrictions already
  cover them.
- P1-SEC-81. Held local and unpublished **by owner decision**; nothing about it belongs in a public
  file.
