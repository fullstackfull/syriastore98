# Consolidated owner-decision package

**Purpose.** One place for every decision that is genuinely the owner's, so no workstream waits on a
question nobody has been asked. Requested by the owner directive of 2026-11-25 §9.

**What this file is NOT.** It is not a new plan, not a status report, and not a list of engineering
work. Anything engineering can decide for itself is absent by design.

**Every entry names what it blocks — and for some the honest answer is "nothing".** The header said
"every entry below blocks real work" until section G was added, and that would have been a reason to
overstate three new entries or to leave them out. Both are worse than the entry. A decision belongs
here when **engineering must not pick the answer** — because it would contradict a decision the
owner already took, invent policy the platform does not have, or change a contract a shipped client
reads. Whether work is waiting on it is a separate fact, stated per entry. G1 and G3 block nothing
and are still the owner's; the alternative was shipping an answer under the owner's name.

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

### E5 — What should an Arabic store name become in a URL? (audit §3.1, stage 4)

| | |
|---|---|
| **Settled already, not asking** | The defect is fixed. `User::slugs()` no longer hands out `-` or an empty slug for an Arabic name; it falls back to `store-<uniqid>`, which is the fallback the function already shipped. The audit authorised stage 4 directly ("worth doing on its own merits ... whether or not numeric subdomains ship"). |
| **The choice** | Should an Arabic name be TRANSLITERATED instead — `متجر سوريا` → `mtjr-swrya` — rather than replaced with an opaque `store-a1b2c3`? |
| **Why it is yours** | It decides what a merchant's public URL looks like. Engineering must not pick a URL scheme. |
| **Evidence it was the original intent** | `User::slugs()` carries a commented-out `transliterator_transliterate('Any-Latin; Latin-ASCII', ...)` on the line above the pass that strips Arabic. Somebody meant to transliterate and the line was disabled. |
| **Consequence of transliterating** | Readable, name-bearing URLs for Arabic merchants. Needs the `intl` PHP extension present in production, and transliteration of Arabic to Latin is lossy and ambiguous (`سوريا` → `swrya`), so two different names can still collide. |
| **Consequence of leaving it** | Arabic merchants get `store-a1b2c3`: unique, routable, and meaningless. Latin-named stores keep readable slugs, so the two groups are treated visibly differently. |
| **Blocked** | Nothing. The breakage is fixed either way; this is the aesthetic. |
| **Related, and genuinely blocking a bigger thing** | §8(c) and §8(d) of the audit — whether the numeric subdomain supersedes discovery recommendation D-08(a), and the cookie-domain question that would log every session out at stage 3. Those are untouched and still the owner's. |

### E6 — Acceptance of the two platform screens (added with this batch)

Neither `/superadmin` platform-revenue nor platform-resources has been seen by the owner. They are
operator-only, they are additive (no existing screen changed), and they are covered by 93 tests, so
nothing is blocked by the absence of acceptance — but **GAP-192 cannot be closed without it**, and
the figures are the platform's own revenue, which is exactly the kind of number worth one human
reading before it is relied on.

## F. First-party module behaviour

### F1 — LynomiaChat's six self-scheduled reconcilers keep running for a switched-off module

For a bundled first-party integration that is probably right. It is a decision and it is unstated.
**Recommendation:** leave them running and say so in the module's record, since a chat integration
going silent mid-conversation is worse than one that keeps reconciling.

## G. Decisions raised by the 2026-11-25 admin-API and storefront gating batch

These three came out of fixing nine authorization families in one batch. All three are cases where
**engineering could pick an answer and must not**, because each one either contradicts a decision
already taken on the merchant web, invents a rule the platform does not have, or changes a payload a
shipped client reads.

### G1 — The admin API now admits the platform operator to coupon, tax and shipping writes. The merchant web refuses them on all nine.

| | |
|---|---|
| **Where it stands** | Nine handlers were gated in this batch: 3 coupon writers (P4-F065) and 6 tax/shipping writers (P4-F075). The gate is the project's standard two-limb shape, which means `MerchantPermissions::allowsAny()` — and that returns `WILDCARD` for the platform operator, so **the operator is admitted**. |
| **The contradiction** | On the merchant web, the operator is **refused on all nine**. Two different reasons, and the difference matters: `ShippingController::shippingWriteRefusal()` refuses them **by name**, consistent with the F157 coupon precedent the owner set on 2026-10-28 — a deliberate decision. `TaxController` refuses them as a **by-product** of bare `isAbleTo()` with no operator limb (`:50`, `:172`, `:218`) — nobody decided that; it is the shape of the call. The independent review drew this distinction and it changes the question. |
| **The choice** | (a) Admit the operator on the API, and fix the web to match — i.e. treat F157 as superseded. (b) Refuse the operator on the API, matching the web and extending F157 to tax and shipping. (c) Admit on the API, refuse on the web, deliberately, and record why two surfaces differ. |
| **Recommendation** | **(b).** F157 is an existing owner decision about exactly this question (may the platform operator write a merchant's commercial terms), and the API should not quietly reverse it. It is also the conservative direction: refusing is reversible, whereas admitting leaves writes in merchants' ledgers. |
| **Why it is yours** | F157 was an owner decision. Engineering cannot overturn it by porting a different default into a second surface. |
| **What is blocked** | Nothing is blocked — the nine handlers are gated and tested either way. But **two test names currently carry the open question rather than an answer**: `test_the_platform_operator_still_writes_coupons_PENDING_AN_OWNER_DECISION` and `test_the_platform_operator_still_writes_PENDING_AN_OWNER_DECISION`. They were renamed because their old names read as an APPROVAL of behaviour the owner had explicitly withheld on the other surface. On answer (a) or (c) they lose the suffix; on (b) the expectation flips and the web gains a named refusal for tax. |

### G2 — What may a storefront product payload contain? It currently includes `products.cost_price` and a `users.email`.

| | |
|---|---|
| **Where it stands** | GAP-233 closed the CROSS-TENANT read on `product-detail`/`product-detail-guest`. It did **not** close what the endpoint serves at the owning store's own slug, which any token holder may name. `Product` has no `$hidden`, so `$product->toArray()` includes **`cost_price`** — the merchant's margin — plus `created_by` and stock internals. The review payload also emits `user_email` from `Testimonial::UserData()`, a `hasOne(User::class, 'id', 'user_id')`: a **`users`** address, where `testimonials.user_id` defaults to **1** (the platform operator) and the storefront's own writer puts a **customers** id in it. |
| **The choice** | What belongs in a public product payload? At minimum: does `cost_price` leave the server at all, and should `user_email` be dropped, or sourced from the `customers` row the writer actually references? |
| **Recommendation** | Add `cost_price` to `Product::$hidden` and drop `user_email` from both twins. A reviewer's display name is the product requirement; their e-mail address is not, and `cost_price` has no storefront use at all. |
| **Why it is yours** | It is a payload contract on a storefront surface, and a shipped client cannot be redeployed alongside the server. Narrowing a payload is a compatibility decision, not a bug fix — which is why this was NOT folded into the tenancy fix. |
| **One thing to know before answering** | The GAP-233 control test currently **pins** the disclosure: `StorefrontProductDetailTenancyTest:258-263` asserts the merchant's e-mail IS present for a store's own review. That was a defensible scoping choice when the test was written, but it means a narrowing must edit that test — it will not simply go green. |
| **What is blocked** | GAP-244. |

### G3 — None of the three review handlers has a permission check. Should they?

| | |
|---|---|
| **Where it stands** | `DeleteReview` had **no predicate at all** and deleted any store's review for any bearer token (GAP-242, fixed in this batch with an ownership predicate). Its two siblings, `AddReview` and `UpdateReview`, have the ownership predicate but — like the fixed deleter — **no permission check**. So any staff member of the store can write and delete reviews. |
| **The choice** | Should the review handlers require a permission (e.g. a `Manage Testimonial` family), or is store membership the intended boundary for moderating one's own reviews? |
| **Recommendation** | Leave as-is for now and decide with the F113 family, not separately. A permission added to one handler of three invents a rule; added to all three it is a product decision about who moderates social proof, and it should be made once, with the name chosen from whatever the merchant web uses for the same screen. |
| **Why it is yours** | It is a new permission family, i.e. new product surface — not a port of an existing web gate, which is what every other row in this batch was. |
| **What is blocked** | Nothing. The destructive defect is closed; this is the residual policy question, recorded so it is not mistaken for an oversight. |

---

## What is NOT in this file, deliberately

- Anything engineering can decide. The `OrderList` owner-limb asymmetry, the storefront API's
  remaining unscoped reads, the **four remaining** ungated admin-API families (eight until
  2026-11-25; F106, F109, F065, F075 and F110 are now gated), the `substr`→`mb_substr` sweep and
  the missing `empty($store)` guards are all engineering and are in the gap register.
- Production deployment, credential rotation, live-host changes, repository visibility and merging
  the stacked PR chain. Those are owner actions, not decisions, and the standing restrictions already
  cover them.
- P1-SEC-81. Held local and unpublished **by owner decision**; nothing about it belongs in a public
  file.

## H. Decisions raised by the 2026-11-25 platform-revenue batch — **ALL THREE DECIDED 2026-11-25**

Raised from building `SaasRevenueFigures` (GAP-192 / `SAAS-007`) and from closing the second
fulfilment-state-machine door (GAP-135). Each was a case where engineering had already taken the
conservative half and the remaining half was a rule rather than a fix.

> **OWNER DECISION, 2026-11-25. All three approved, option A — with H3 carrying a staged second
> half.** Recorded verbatim as given:
>
> - **H1: Approved option A.** *Enforce Unlimited, Month and Year as the currently approved plan
>   duration values on creation and update. Do not silently rewrite historical values or introduce
>   new billing periods.*
> - **H2: Approved option A.** *Enforce the delivered-order backward-transition invariant centrally
>   in the existing fulfilment state machine. Preserve legitimate return and refund workflows,
>   idempotency, inventory integrity and existing approved client compatibility.*
> - **H3: Approved option A now, and option B with the appropriate Flutter release.** *Preserve the
>   current caller-specific response contracts until coordinated migration is safe.*
>
> Each entry below keeps the question and the options as they were put, so the decision can be read
> against what was actually asked, and adds what was implemented under it. **No entry is deleted
> once decided** — a decided entry that disappears leaves the next reader unable to tell an approved
> rule from an unasked one.

### H1 — What may `plans.duration` contain, and should the plan form enforce it?

| | |
|---|---|
| **Where it stands** | `Plan::$arrDuration` defines exactly three words: `Unlimited`, `Month`, `Year`. `plans.duration` is `$table->string('duration')->nullable()` — free text — and **`PlanController`'s validation for store and update does not mention `duration` at all** (it covers `name`, `price`, `max_stores`, `max_products`, `max_users`, `storage_limit`). The form is a `Form::select`, which constrains the UI and not the request. |
| **What it cost** | The platform-revenue producer branched `$duration === 'Year' ? $price / 12 : $price`, so every other spelling fell down the MONTHLY branch at its whole price. A plan priced 1200 a year reading `Yearly` reported MRR 1200 instead of 100 — a **twelvefold overstatement** of the platform's own revenue. |
| **Already done, and not needing a decision** | The READ side is closed. An unrecognised duration now goes to a named, visible `Duration not recognised` bucket with its reason, instead of being counted or dropped — the same refusal `Unlimited` and `plan_missing` already get. Deliberately **not** a synonym table mapping `Yearly`/`year`/`annual` onto `Year`: that is the producer inventing the figure it exists to stop inventing, and it is wrong the first time somebody adds a quarterly plan. |
| **The choice** | (a) Add `'duration' => 'required|in:Unlimited,Month,Year'` to `PlanController`'s store and update rules, so the three words become the contract. (b) Widen the vocabulary first — a quarterly or weekly plan is a product decision, and if one is coming the rule should admit it on the same day. (c) Leave the column unvalidated and rely on the read-side refusal, accepting that a bad duration silently removes a paying merchant from MRR until somebody looks at the bucket. |
| **Recommendation** | **(a)**, unless a new billing period is actually planned. It makes the existing three words enforceable rather than conventional, and it is the write-side counterpart of a read-side refusal that already works. |
| **Why it is yours** | It is a validation rule on plan creation — a contract change on the operator's own plan surface — and the vocabulary question ("are three periods the product's whole answer?") is a product decision, not an engineering one. |
| **What is blocked** | Nothing. Both figures are correct and tested either way; (a) stops bad data being written, it does not fix any figure. |
| **DECIDED 2026-11-25 — option (a)** | *"Enforce Unlimited, Month and Year as the currently approved plan duration values on creation and update. Do not silently rewrite historical values or introduce new billing periods."* So the three words in `Plan::$arrDuration` become the contract on both write paths, the vocabulary is NOT widened, and **existing rows carrying anything else are left exactly as they are** — the read side already reports them in their own named `Duration not recognised` bucket rather than guessing. One consequence follows from the instruction and is the owner's to accept rather than engineering's to work around: an operator editing an unrelated field on a plan whose stored duration is already invalid will be refused until they also correct the duration, because the alternative is auto-correcting a historical value, which the decision forbids. |

### H2 — What may a DELIVERED order become? Two doors can still walk delivery backwards in two requests

| | |
|---|---|
| **Where it stands** | Both GAP-135 doors now compose the machine's two questions — `refusalForLeaving()` (may this row move at all: no, out of `TERMINAL`) and `refusalFor()` (is this target permitted). That closes the money path: `2 (CANCELLED) -> 0 -> 1 (DELIVERED)` was measured answering **200 then 200** and reaching the state `Order::product_return()` gates on before it REDUCES `final_price`; the first hop is now refused, so nothing composes past it. The same shape out of `3 (RETURN)` is closed with it. |
| **What is still open, measured on both doors independently** | **DELIVERED is not `TERMINAL`.** So `1 -> 0 -> 2` lands on the courier webhook, and `1 -> 7|8 -> 4|5|6` lands on the admin door, while each one-step move is refused. A cancel RESTORES STOCK, so the first path gives stock back for goods already delivered; the second un-does a delivery. Both are pinned as `test_MEASURED_*` cases asserting TODAY's behaviour, by this repository's `test_UNGATED_*` convention — whoever closes it inverts the assertion in the same change. |
| **Why engineering stopped here, and this is the part that makes it yours** | `PERMITTED[STATE_DELIVERED]`'s own comment says *"forward into the return workflow, or re-stamped. **Never backwards**"* — so the INTENT is already stated in the machine and the MECHANISM cannot express it. `PERMITTED` rows list WORDS, and no word targets Pending, 7 or 8. Closing it therefore needs either an ordering over the state integers (which they do not carry: 2 is cancel, 3 is return, 4 is confirmed, so "backwards" is not computable from them) or a governed word for each non-fulfilment value — and the machine's own docblock calls 7 and 8 "a schema problem that this machine must not pretend to have solved". Either is a business rule about what an order may do, on a surface every order door reads. |
| **A draft that overstepped, recorded so it is not retried** | My first fix had the machine refuse EVERY untranslated target. It closed this residual as a side effect, and it also refused 7 and 8 out of a Pending row — undoing `P2-07-12`, which deliberately widened the admin endpoint to all nine values, on a shipped client path. Its justification ("no `PERMITTED` row targets Pending") is equally true of 7 and 8, so it never singled Pending out. Withdrawn, and the narrower machine-grounded predicate kept. |
| **The choice** | (a) Add a backwards invariant to the machine — name the states a DELIVERED row may not reach, in one place, and both doors inherit it. (b) Govern 7 and 8 as targets, which means deciding what they mean as destinations, not just as stored values. (c) Leave it, accepting that a delivered order can be walked back by any principal who can reach either door. |
| **Recommendation** | **(a).** It is the smallest change that matches what the machine already says it means, it needs no new vocabulary, and it closes both doors at once. (c) is the status quo and leaves a stock-restoring path open. |
| **What is blocked** | Nothing in the code: the terminal-reopening path, which is the one that touches `final_price`, is closed and tested on both doors. |
| **DECIDED 2026-11-25 — option (a), and IMPLEMENTED** | *"Enforce the delivered-order backward-transition invariant centrally in the existing fulfilment state machine. Preserve legitimate return and refund workflows, idempotency, inventory integrity and existing approved client compatibility."* Delivered as `FulfilmentStateMachine::FORWARD_ONLY` — `[STATE_DELIVERED, STATE_CANCELLED, STATE_RETURN]` — enforced inside `refusalFor()` where an untranslatable target arrives. **Both doors inherited it with no edit to either controller**, which is the point of "centrally": the previous attempt at this defect was a local check, and a local check disagreeing with the single definition is what made the door wrong to begin with. Each of the four preservation clauses is enforced by a named test rather than asserted: the RETURN workflow (`returned` and `delivered` are governed words in `PERMITTED[STATE_DELIVERED]`, so the invariant never reaches them, and `return_status` 1→2/3 does not come through this machine at all); IDEMPOTENCY (both doors short-circuit an unchanged status *before* consulting the machine, so a courier retry still answers 200); INVENTORY INTEGRITY (the `1 → 0 → 2` and `1 → 7 → 2` routes that restored stock for delivered goods are refused at the first hop); and CLIENT COMPATIBILITY (`FORWARD_ONLY` is per-state, so a PENDING row still accepts 7 and 8 — the values `P2-07-12` widened the admin endpoint for. A mutation applying the rule as a *blanket* instead of per-state fails that case, so the distinction is load-bearing and not a comment.) Four mutations, all caught; 59 tests across the machine and both doors. The two `test_MEASURED_*` residual pins that recorded the open behaviour were inverted in the same change, by this repository's own convention. |

### H3 — The two GAP-135 doors answer the same refusal with different HTTP codes

| | |
|---|---|
| **Where it stands** | `Api\DeliverySyriaOrderController` answers a refused transition with **409**, consistent with its own neighbours and with `ProductController:4222`, `StoreFinanceController:2582`, `OrderRefundController:709`, `PosController:417`. The sibling door of the same fix, `Api\DashboardController::updateStatus`, answers **HTTP 200 with `success: false`**, consistent with ITS neighbours — `ApiResponser::error()` answers 200 by default and the shipped Flutter app force-logs-out on any 401, so that controller answers 200 for nearly everything. |
| **Why it is not simply a bug** | Each door matches its own client. The Flutter admin app reads the `admin` API and would need a release to understand a 409; the Delivery Syria webhook is a server-to-server integration with no such constraint. Neither answer is wrong for its caller. |
| **The choice** | (a) Leave them different and record it, as here. (b) Make the admin door answer 409 too, which needs a Flutter release first. (c) Make the webhook answer 200, which hides a refusal from a machine client that has no reason to read a body flag. |
| **Recommendation** | **(a) now, (b) with the next Flutter release.** (c) is the wrong direction for a server-to-server caller. |
| **What is blocked** | Nothing. It is declared so it reads as a decision rather than as drift. |
| **DECIDED 2026-11-25 — option (a) now, option (b) with the appropriate Flutter release** | *"Preserve the current caller-specific response contracts until coordinated migration is safe."* So **no code changes under this decision today**, deliberately: the webhook keeps its 409 and the admin door keeps HTTP 200 with `success: false`, each matching its own client, and the difference is now an approved decision rather than drift. **What this decision creates is an obligation on a FUTURE change**, recorded here because it will otherwise be forgotten: when the next Flutter release is prepared, the admin door moves to 409 *in the same coordinated change as the client that understands it* — not before, because a 409 the shipped app does not expect is a user-visible regression, and not silently, because `ApiResponser::error()` answering 200 is what every other refusal on that controller does. Until then, a reader finding the two codes different should find this row rather than conclude one door is wrong. |
