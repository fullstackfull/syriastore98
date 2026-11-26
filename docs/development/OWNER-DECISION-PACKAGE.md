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
| **DECIDED 2026-11-25 — option (a)** | *"Enforce Unlimited, Month and Year as the currently approved plan duration values on creation and update. Do not silently rewrite historical values or introduce new billing periods."* So the three words in `Plan::$arrDuration` become the contract on both write paths, the vocabulary is NOT widened, and **existing rows carrying anything else are left exactly as they are** — the read side already reports them in their own named `Duration not recognised` bucket rather than guessing. |
| **IMPLEMENTED 2026-11-25, and one reading was chosen that the owner can reverse in a line** | The rule is on all **three** request-side writers — `store()` and **both** of `update()`'s rule arrays, since the branch is picked by the stored `$plan->price > 0` and a zero-priced plan takes the second one. The set is `array_keys(Plan::$arrDuration)`, never a literal. Nothing is migrated and nothing is auto-corrected. **The one judgement call, and why it was not left to the literal reading:** the edit form renders `Form::select('duration', $arrDuration, null, …)`, and measured on the rendered page a stored `Yearly` matched no option, emitted **no `selected` attribute at all**, and a browser therefore showed and submitted the **first** option — so an operator editing `max_products` on a legacy plan silently replaced `Yearly` with `Unlimited`, *accepted* by the new rule because `Unlimited` is approved. The decision's two clauses cannot both hold that way, so the form now shows each row **its own** stored value, labelled `(no longer offered)`, and the update rule accepts **that one value back from that one row** — read from the stored column, never from the request, so no request can introduce a billing period and a plan stored as `Month` still refuses `Yearly` while another plan legitimately carries it. Re-submitting the word already in the column writes nothing new. **If you meant the literal reading** — that a legacy row cannot be saved at all until its duration is corrected — say so and it is one `Rule::in` argument away; be aware it then blocks every edit to every other field of such a plan, and the only way out for an operator is to change a live plan's billing period, which is itself a material payments change. The deleted test case and this trade-off are stated in `tests/Feature/Saas/PlanDurationValidationTest`'s "Historical values" section header. |
| **Two adjacent corrections made at the same time** | `packages/workdo/LynomiaChat`'s staging fixture seeder wrote `'Monthly'`, which is not one of the three and which `SaasRevenueFigures` cannot read — a seeder uses `forceFill()` and bypasses both `$fillable` and request validation, so H1's rule could never have caught it. Corrected to `Month`. A second suggestion was **tried and withdrawn**: adding an `attributes` entry so the refusal reads `Duration` / `المدة` rather than the raw column name. `ArabicValidationMessagesTest::test_the_attributes_map_is_empty_in_both_locales` refuses it, and its reasoning is right — that map changes **every form in the platform at once**, which no cosmetic gain in one message justifies. The raw word stays. **Still open and not touched:** `plan_requests.duration` is a different table with no validation anywhere, `PlanRequestController` copies a plan's duration into it and then builds a dynamic property name from it (`$plan->{$duration . '_price'}`). |

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
| **TWO COSTS OF H2, ACCEPTED BY THE DECISION AND RECORDED SO THEY ARE NOT DISCOVERED LATER** | (1) **A wrongly-DELIVERED order is now unrecoverable from any product surface.** `1 → 4/5/6` was already refused by `PERMITTED[STATE_DELIVERED]`, so `1 → 0` through an integer door was the only correction path and H2 closes it. The one move left out of DELIVERED is `return`, which stamps `return_status = 1` and puts the order into the REFUND workflow — not a correction. So a mis-click on the order detail page, which offers `delivered` from six states, is permanent short of a database edit. This compounds with a defect that is **still open**: `Api\ApiController::order_status_change` constrains ownership but not WHICH status a principal may set, and `PERMITTED[PENDING]` contains `delivered` — so a storefront customer can push their own order to DELIVERED and, after H2, nobody can move it back. "Correct an order that reached Delivered in error" is a legitimate workflow that now has no surface; it is the accepted cost of the invariant, and closing `P4-F036` is what removes the sharp edge. (2) **The shared writer inherits the rule**, not only the two doors — `Order::refuseIllegalTransition()` calls `refusalFor()` alone — so out of the three forward-only states an unrecognised word now answers `MESSAGE_NOT_PERMITTED` instead of F81's "That order status is not recognised.", and the `partial_paid` / `pre_order` module arms become unreachable. The second is probably right (it closes `2 → 7 → 1` at the writer) but no WorkDo module loads in any test run, so neither is visible to the suite. |
| **DECIDED 2026-11-25 — option (a), and IMPLEMENTED** | *"Enforce the delivered-order backward-transition invariant centrally in the existing fulfilment state machine. Preserve legitimate return and refund workflows, idempotency, inventory integrity and existing approved client compatibility."* Delivered as `FulfilmentStateMachine::FORWARD_ONLY` — `[STATE_DELIVERED, STATE_CANCELLED, STATE_RETURN]` — enforced inside `refusalFor()` where an untranslatable target arrives. **Both doors inherited it with no edit to either controller**, which is the point of "centrally": the previous attempt at this defect was a local check, and a local check disagreeing with the single definition is what made the door wrong to begin with. Each of the four preservation clauses is enforced by a named test rather than asserted: the RETURN workflow (`returned` and `delivered` are governed words in `PERMITTED[STATE_DELIVERED]`, so the invariant never reaches them, and `return_status` 1→2/3 does not come through this machine at all); IDEMPOTENCY (both doors short-circuit an unchanged status *before* consulting the machine, so a courier retry still answers 200); INVENTORY INTEGRITY (**one** stock-bearing route, not two — the independent review corrected this: `1 → 0 → 2` is refused at the first hop, while `1 → 7 → 2` was already closed, because hop 2 is `refusalFor(7, 'cancel')` and `7 != 0` answers `MESSAGE_CANCEL_REFUSED`. The documented residual was `1 → 7|8 → 4|5|6`, which un-does a delivery and moves no stock); and CLIENT COMPATIBILITY, which the review pressed hardest on and which is now settled BY MEASURING THE CLIENT rather than by reasoning about the server. `FORWARD_ONLY` is per-state, so a PENDING row still accepts 7 and 8 — the values `P2-07-12` widened the endpoint for — and a mutation applying the rule as a *blanket* fails that case, so the distinction is load-bearing. **But the newly refused set is `{from = 1} × {target = 0, 7, 8}`, and the ledger's first version only claimed the PENDING half**, which left the changed half undisclosed. Measured in the shipped app's own source: `payment_status_bottom_sheet.dart:367` declares `const statusIds = [0, 1, 2, 3, 4, 5, 6]`, so **the app cannot send 7 or 8 as a write target at all** and refusing them out of DELIVERED breaks nothing a client does. The app DOES echo the order's current `delivered_status` when changing payment only (`:177`), and for a DELIVERED order that echo is the governed word `delivered`, which `PERMITTED[STATE_DELIVERED]` admits — so that path still succeeds, driven and confirmed.) Four mutations, all caught; 59 tests across the machine and both doors. The two `test_MEASURED_*` residual pins that recorded the open behaviour were inverted in the same change, by this repository's own convention. |

### H5 — A bilingual storefront is labelled from single-language text fields: translate the chrome, or keep the merchant's ability to rename it? (GAP-259)

| | |
|---|---|
| **Where it stands** | The cart and checkout print **thirteen** labels as `{{ $themeSettings['<key>'] ?? __('<English>') }}`, and **all three themes ship a default for all thirteen**, so `??` never fires and the `__()` is unreachable for every real store. All thirteen Arabic strings are in `ar.json` and nothing prints them. Found by the new bilingual harness, which drove these two screens in both languages for the first time. |
| **What a shopper sees, measured** | On an Arabic store, inside **one response**: `المنتج كمية السعر مجموع الجزئي … Continue Shopping  Clear Cart  Order Summary  Subtotal  Total  Proceed to Checkout`, and the checkout summary ends `… إجمالي  Place Order`. The same words in the slide-out basket — which has no shadow — come out `مجموع الجزئي / الضريبة / إجمالي / استمر في التسوق / إذهب إلى الخروج`. So the page contradicts itself, and the shipped default is English for every store in every language. `checkout_button` is sharper: it substitutes a *different* English string, so `__('Complete Order')` is never printed and `Place Order` is. |
| **Why it is a decision and not a bug to fix** | The shadow is a **feature**: it is how a merchant renames their own buttons. Removing it to let the translations through takes that away. Keeping it means a storefront that serves two languages is labelled from single-language text fields — and an Arabic merchant who edits one **breaks the English view of the same shop**. Neither side of that is engineering's to choose. |
| **The class is wider than the cart, and the first report of it was wrong about why** | The footer's `Quick Links` and `Customer Service` are not hardcoded Blade literals, as first reported — they are `footer_menu_repeater` in each theme's `default_data/settings.php`, a JSON blob of merchant-editable titles shipped with English defaults. **Same mechanism, one layer out.** So this is one decision about how theme settings relate to languages, not a list of views to patch. Measured before recording, because the view-literal reading would have pointed the fix at the wrong place. |
| **The choice** | (a) **Per-language theme settings** — the only option that satisfies both halves: a merchant renames a button *per language*, and an unset language falls back to the `__()` that is already translated. The largest change, and it touches the theme editor, the settings schema and the defaults of three themes. (b) **Drop the shadow on these thirteen** and let the translations through: one-line view edits, and merchants lose renaming on exactly these labels. (c) **Keep it and accept it**, on the basis that a merchant can type Arabic into the field — which only works for a single-language shop. |
| **Recommendation** | **(a)** as the answer, **(b) as a staged first step** if (a) is not scheduled soon. (c) is the one option that leaves a self-contradicting page shipping, and the defaults are English, so it is not neutral — it is "English unless every merchant notices and retypes thirteen fields". |
| **What is NOT blocked** | Nothing. The harness ships and pins the present rendering, so whichever way this goes it shows up as a diff rather than as a silent change. The adjacent half — three attributes hardcoded with no `__()` at all, which no key census could see, including an `alt` that announced every basket line as "Artisan Sourdough Bread" to a screen reader — needed no decision and is **already fixed**. |

### H4 — May PENDING be a status the two integer doors can write? The cancel refusal is still one request from irrelevant for five states (GAP-255)

| | |
|---|---|
| **Where it stands** | H2 closed the three forward-only states. It did not close the defect CLASS, and the review of the H2 change measured the rest: `MESSAGE_CANCEL_REFUSED` restricts `cancel` to a PENDING order, and **`6 (SHIPPED) → 0 (PENDING) → 2 (CANCELLED)` lands** — hop 1 because 6 is not forward-only and `cancel` is not the word asked about, hop 2 because `PERMITTED[PENDING]` holds `cancel`. Same from 4, 5, 7 and 8. |
| **Why it is money and not ordering** | The cancel arm runs `restoreCancelledOrderStock()` and `OrderEntitlementReversal::reverseOrLog()`, so **stock is credited for goods in transit** and the merchant's wallet entitlement is reversed. The admin door's cancel arm restocks unconditionally, its own comment stating "The guard above means this only ever runs from Pending" — which the waypoint defeats. **On the courier webhook the principal is a third party:** whoever holds that merchant's `deliverysyria_secret` can cancel any shipped order of those stores, crediting stock for goods it is itself carrying. |
| **Why engineering has not taken it** | The obvious fix is to add 4..8 to `FORWARD_ONLY`, and the reviewer and I both think that is **wrong**: it would refuse `delivered_status` 7 and 8 out of in-flight rows, which is a larger compatibility change than H2 authorised and exactly the blanket over-reach H2's own implementation withdrew. The shape that fits is to make **PENDING a target the two integer doors may not write** — both validators currently advertise `0`, so that is a contract change on two endpoints, one of them a third-party integration. That is the same class of decision as H2 itself. |
| **The choice** | (a) Refuse `0` as a write target at both integer doors, and tell the courier platform. (b) Refuse it at the webhook only — the unmitigated surface — and leave the admin door, whose exposure is to a crafted request rather than a button. (c) Add a from-state rule for `cancel` wherever it is reached rather than constraining the waypoint. (d) Accept it and record it, on the basis that the admin door needs a merchant's own credentials and the webhook needs the merchant's secret. |
| **Recommendation** | **(a).** The waypoint is the mechanism for all five states at once, `0` has no legitimate meaning as a destination on either door (no merchant surface in the product writes Pending), and (b) leaves the admin door able to do by request what its own comment says cannot happen. If the contract change must be staged, do (b) first — the webhook is the surface with a third-party principal and no client-side mitigation. |
| **Measured mitigation, so the urgency is not overstated** | The shipped app offers only `[0,1,2,3,4,5,6]` as write targets (`payment_status_bottom_sheet.dart:367`), and reaching a cancel this way needs two deliberate requests, so the admin-door exposure is to a crafted request rather than to a mis-click. The webhook's is **not** mitigated: the courier platform composes its own requests. |
| **What is blocked** | Nothing is blocked, and nothing is waiting on this to proceed. It is recorded as HIGH because the consequence is stock and entitlement, not because it stops work. |

### H3 — The two GAP-135 doors answer the same refusal with different HTTP codes

| | |
|---|---|
| **Where it stands** | `Api\DeliverySyriaOrderController` answers a refused transition with **409**, consistent with its own neighbours and with `ProductController:4222`, `StoreFinanceController:2582`, `OrderRefundController:709`, `PosController:417`. The sibling door of the same fix, `Api\DashboardController::updateStatus`, answers **HTTP 200 with `success: false`**, consistent with ITS neighbours — `ApiResponser::error()` answers 200 by default and the shipped Flutter app force-logs-out on any 401, so that controller answers 200 for nearly everything. |
| **Why it is not simply a bug** | Each door matches its own client. The Flutter admin app reads the `admin` API and would need a release to understand a 409; the Delivery Syria webhook is a server-to-server integration with no such constraint. Neither answer is wrong for its caller. |
| **The choice** | (a) Leave them different and record it, as here. (b) Make the admin door answer 409 too, which needs a Flutter release first. (c) Make the webhook answer 200, which hides a refusal from a machine client that has no reason to read a body flag. |
| **Recommendation** | **(a) now, (b) with the next Flutter release.** (c) is the wrong direction for a server-to-server caller. |
| **What is blocked** | Nothing. It is declared so it reads as a decision rather than as drift. |
| **DECIDED 2026-11-25 — option (a) now, option (b) with the appropriate Flutter release** | *"Preserve the current caller-specific response contracts until coordinated migration is safe."* So **no code changes under this decision today**, deliberately: the webhook keeps its 409 and the admin door keeps HTTP 200 with `success: false`, each matching its own client, and the difference is now an approved decision rather than drift. **What this decision creates is an obligation on a FUTURE change**, recorded here because it will otherwise be forgotten: when the next Flutter release is prepared, the admin door moves to 409 *in the same coordinated change as the client that understands it* — not before, because a 409 the shipped app does not expect is a user-visible regression, and not silently, because `ApiResponser::error()` answering 200 is what every other refusal on that controller does. Until then, a reader finding the two codes different should find this row rather than conclude one door is wrong. |

---

## I. Scope questions that block a NOT-IMPLEMENTED requirement, collected 2026-11-25

**These are the requirements that cannot be built from this repository**, pulled out of the gap
register so they sit in one place instead of twelve rows. Each one is blocked on a *scope* answer,
not on engineering: nothing here is waiting for a design, a measurement or a test. Implementation of
every other actionable requirement continues in parallel and is not held by any of them.

**Two kinds, and the difference matters.** `I1`–`I5` are blocked because **the original text is
lost** — the Decision Register's `EX-*` exclusions and three requirement texts (1 GOV, 2 ARCH) were
never committed, so the repository cannot say whether the thing was excluded or simply dropped.
Those cannot be answered by anyone but the owner, and must not be inferred from the WorkDo surface
that happens to exist. `I6`–`I11` have a recoverable requirement but a genuine product or
commercial choice inside them.

| | Requirement | The question, in one line | Why engineering cannot take it |
|---|---|---|---|
| **I1** | `P04-P4-02` (`GAP-134`) — multi-warehouse stock | Was it `EX-*` excluded, or is it still required? | The entire capability turns on the answer, and the exclusions list is lost. |
| **I2** | `P04-CH-01` (`GAP-119`) — "channels" | What does the word mean in the Decision Register? | Both the requirement's meaning **and** any work against it are unrecoverable here. |
| **I3** | `P04-P9-01` (`GAP-145`) — six end-to-end journeys | Resupply the list of journeys A, B, D, E and F. | Five of six have no recoverable definition; the phase closed without restating them. The owner is the only source. |
| **I4** | `P06-04` (`GAP-177`) — platform "controls" and "operations" | What do those two words cover? | They are two words in a derived summary with no original behind them. Inferring scope from the vendored super-admin surface would be engineering inventing the requirement. |
| **I5** | `P03-C-05` (`GAP-104`) — official-website content management | Accept code-owned marketing copy, or schedule a managed-content path? | Also gates the five legal documents and the contact details, which only the owner can supply, and names where legal text is published from. |
| **I6** | `P04-P6-05` (`GAP-141`) — gateway refunds | In scope? | Building it needs provider credentials, so **verification stays externally blocked even once built** — worth knowing before it is scheduled. Phase 02's E13 records the same blocker. |
| **I7** | `P05-11` (`GAP-159`) — loyalty | Add the slice to Phase 05, or defer it explicitly? | It is an approved requirement the execution plan omitted. The owner's own accounting rule says a slice list must not stand as the definition of scope, so it needs a decision either way rather than silence. |
| **I8** | `P03-MP-01` (`GAP-117`) — storefront SEO | Scope it (canonical, hreflang ar/en, sitemap, robots, structured data) and assign it to a phase. | An owner-approved Phase 03 scope item the execution plan omitted, so it was never built, dispositioned or reported. The per-store meta half is being implemented now under `GAP-118`; the rest is a scoping decision. |
| **I9** | `P03-B-06` (`GAP-099`) — the theme reorder control | Should saved section ordering drive the storefront at all? | The control is now fully keyboard-operable (`GAP-100`, closed today) and still changes nothing a shopper sees. Removing a control is a product decision; making it accessible was not. |
| **I10** | `P03-D-04` (`GAP-107`) — documentation editorial workflow | Name a documentation owner and the release process. | Working documentation exists; an editorial workflow does not. "Who drafts, who reviews the Arabic, who approves, how a change reaches production" is an organisational answer, not a code change. |
| **I11** | `P06-07` (`GAP-180`) — a financial-configuration question | Stated on the row. | Financial configuration is reserved to the owner by the standing escalation rules. |
| **I12** | `P01-LEDGER-MIG-02` (`GAP-030`) | Apply the prepared migration. | **Not a scope question — a production action.** The migration exists and is verified; applying it is a persistent migration against live data, which this authorization explicitly excludes. On MySQL the web password reset silently fails or truncates until it is applied (P01-SEC-13). |

| **I13** | `P04-P8-02` (`GAP-262`) — shipping cost is chosen by the method's NAME | Decide the cost arm from `calculation_type`/`shipping_requires` instead of from three hardcoded English names? | **It changes what shoppers are charged on live orders.** Every merchant whose method is named anything other than `Delivery Syria`, `Flat Rate` or `Local pickup` currently ships **free** — including every method named in Arabic, which on a bilingual product is the ordinary case. Measured on one row, name the only variable: `Local pickup` charges 25.00, `استلام من المتجر` charges 0.00. The engineering is obvious; starting to charge shipping that is not being charged today is a price change, which the standing rules reserve to you. The present behaviour is pinned by a test, so whichever way this goes it shows as a diff. **ONE INPUT CORRECTED 2026-11-26, and it changes the COST of this decision rather than the answer: the platform already charges that cost on its other door.** There is a second copy of the same name-based decision in `Api\ApiController::delivery_charge`, and measured on ONE row with only the name changed, the web checkout quotes **0.00** while the storefront API quotes the configured **25.00**. That API arm also knows only two of the three names, so `Delivery Syria` falls through it too. So "merchants would start charging what they do not charge today" is true of the web door and already false of the API door -- the two doors disagree about the same merchant's row. Both answers are now pinned, with an assertion that fails if they ever converge. The free-text premise is also confirmed from the markup rather than assumed: the zone form's method control is a `tagsinput`, and the store validator has no `in:` rule. Nothing was changed. |
| **I14** | `P05-01` (`GAP-149`) — which entitlement states the finance door accepts | Carried already as `D-N43` in the Phase 05 decision register; listed here so the one package shows it. | The finance screen **renders** all six states correctly in both languages, but the door accepts only `paid` and `unpaid`. Admitting `cancelled` or `refunded` would let the finance screen declare a reversal the refund engine never recorded, and `P5-FIN-3` already settled that a refund REQUEST is not a refund COMPLETION. The three copies of that list are now one constant, so whichever option you choose is a single edit. |

| **I15** | `P04-P5-01` (`GAP-263`) — every order taken over Telegram sits unpaid | Is `telegram` a settle-by-arrangement relay like `whatsapp`, or should it stay Pending until collection is confirmed? | **Live, and it decides when a merchant's wallet is credited.** `OFFLINE_PAYMENT_TYPES` lists `whatsapp` but not `telegram`, while `POST {slug}/telegram` routes to the **same handler** — so two identical relay channels resolve to Paid and Pending respectively. The asymmetry was invisible until your no-unverified-Paid decision inverted the default; the constant's docblock shows `pos` and `lottery product` were added for exactly this reason and `telegram` was missed. The fix is one list entry, but it changes a money rule for every store taking Telegram orders. The opposite reading is equally coherent: if the carve-out is meant to be narrow, it is `whatsapp`'s entry that needs re-examining. Pinned by a ratchet, so either choice shows as a failing test. |
| **I16** | `P01-DEFECT-REG`, `P02-A-01` (`GAP-264`) — a merchant with no resolvable store is signed out of the panel | What should a storeless merchant see: a store-creation screen, an explanatory page that KEEPS the session, or the present sign-out? | **It is the first screen the affected merchant sees, and today it is a closed door.** `HomeController::index()` signs out any merchant whose `current_store` resolves to nothing and sends them to `/login`; they can sign in, and are bounced again, indefinitely. This PREDATES GAP-013 — `getOrderChart()` already did it — and GAP-013 only replaced the HTTP 500 that used to happen on the way, so **the present behaviour is strictly better than what it replaced and nothing is urgent.** Registration always creates a store, so the reachable population is a merchant whose only store was deleted or whose `current_store` was cleared. Engineering is small either way; which of the three a merchant should get is a product call, not a defect fix, so it is not taken here. The redirect is pinned by its own test, so whichever way this goes it shows as a diff rather than as silence. |
| **I17** | `P06-01`, `P01-G1` (`GAP-266`) — a plan's seat cascade can deactivate the account it is applied to | Should a plan with fewer seats than 1 deactivate anybody, and should the cascade ever be able to select the subject itself? | **It locks a real merchant out of the product, and the suspension guard completes it one request later.** `User::assignPlan()` selects `where('created_by', '=', $this->id)`, so a user whose `created_by` is their own id is their own staff. Registration hard-codes `created_by => 1`, and the existing exemption covers only `type = 'super admin'`, so a MERCHANT who is id 1 is the uncovered row. Measured end to end: `POST /register` provisioned the user, store, wallet and role, left `users.is_active = '0'`, and `GET /dashboard` then redirected to `/login` flashing "Your Account is de-activate". Latent on a seeded install, live wherever the first `users` row is a merchant, and the `max_users` half is live for any plan carrying the column default of 0. **Two questions, and only the first is a bug:** the predicate should exclude the subject itself (a one-line fix engineering can make on your word), but whether a 0-seat plan should deactivate existing staff at all is a product rule about what customers get for their money, which the standing rules reserve to you. Pinned by a test that fails in either direction, so neither answer can land silently. |
| **I18** | `P03-B-06` (`GAP-099`) — the themes' reorder tokens do not describe the themes | Align each theme's declared `orders` tokens with its real sections, and does greentic gain the control at all? | **It decides how much of their own shop a merchant can actually rearrange.** The storefront now honours a saved ordering, so the control works — but a stylique merchant can reorder **5 of 9** top-level sections and a techzonix merchant **6 of 9**; four stylique sections (logo, collection, blog, service) cannot be moved at all, and greentic declares no orderable page. The declared tokens list four names that match no block and omit four blocks that exist. Exact proposed strings are in `docs/development/phase-3/PHASE-03-SECTION-ORDERING-STOREFRONT-CONSUMPTION.md` §7. Not engineering's call: it changes what the merchant editor shows, and giving greentic the control is a NEW capability rather than a correction. Nothing in the delivered mechanism has to move whichever way you decide — stored rows stay valid either way. |
| **I19** | `P03-D-04`, `P03-D-05` (`GAP-107`, `GAP-108`) — the documentation workflow's three unnamed things | Who owns the documentation, who reviews the Arabic, may review be delegated to a non-operator — and what version should an article say it describes? | **A review process with no named reviewer is a document, not a process.** The mechanism is built and enforced: drafts are invisible to readers on all four read paths, every article must declare a recognised status in both locales, and the operator gets an editorial screen with preview. What it does not have is people. Delegation to a non-operator additionally needs a permission seeded in `Utility.php::$arrPermissions`, which was deliberately NOT added because an unseeded permission refuses everybody. On the version: `version:` parses and renders, but **nothing in this repository carries a version to populate it from** — `composer.json` and `config/app.php` both have none — so the question is partly "does this product version itself at all". A test re-measures that and will say so if it ever changes. |

## J. Decisions raised by the 2026-11-26 accelerated parallel batch

Eight workstreams and nine independent security reviews ran on 2026-11-26. Everything engineering
could settle was settled; these are what is left. **Six of the thirteen are money**, which is why
the section is this long rather than this being a list of preferences.

Two things about this section's shape, stated so it is read correctly. First, **three entries exist
because a measurement refuted something this repository already believed** — `J9`, `J11` and `J13`.
Those are not new questions; they are old answers that stopped being true, and leaving them
unasked would mean a document in this repository keeps telling the owner something measurably
wrong. Second, **`J5` is a question with one missing fact rather than a choice between options.**
It is here because engineering declined to guess the fact, not because the owner must weigh
anything.

| | row | the question | why it is yours and not engineering's |
|---|---|---|---|
| **J1** | `P04-P6-04` (`GAP-140`) — `shiping_deduct`, the third refund toggle | Should the refund engine honour the store's "deduct shipping" setting, and what happens to refunds already issued under the present behaviour? | **It moves a refund AMOUNT, which is the one thing the standing rules put furthest out of engineering's reach.** The other two toggles in the same setting group — `manage_stock` and `attachment` — were enforced, because one gates a stock movement and one gates whether a file is stored: neither changes a figure. `shiping_deduct` is different and the chain was measured rather than assumed: `RefundAmonut()` zeroes `orders.delivery_price` on `isTrendingChecked` and then recomputes `orders.final_price` **from that column in the same transaction**, and `final_price` is what `OrderEntitlementReversal::reverse()` debits. So honouring the toggle changes what a shopper is refunded. Engineering stopped at the measurement and shipped the other two. |
| **J2** | `P04-P5-04`, `SAAS-007` (`GAP-283`) — the MyFatoorah webhook | Should Store Syria consume MyFatoorah webhooks at all? | **Because a payer who closes the tab after paying is charged and never activated.** A plan purchase is booked only on the browser callback (`paymentCallback()` creates the `PlanOrder` and calls `assignPlan()`), so an abandoned tab is a completed payment with no entitlement. Closing that needs a handler that **writes a payment record**, which is a money path. Three things were measured so the question is not confused with the 500 that was there: the vendored sample `webhook()` returns 404 when `myfatoorah.webhook_secret_key` is empty and the config ships it as `''`, so it would have answered 404 anyway — the 500 was a trap for whoever pastes a key in later, not a capture dropped in flight; the package's `changeTransactionStatus()` writes nothing (its four steps are comments); and `dont-discover` is ruled out because three live payment call sites read a constant that same `register()` defines. The four routes now answer 404 host-side, which reproduces the vendored integration's own answer for this configuration. |
| **J3** | `P01-D4`, `P04-P5-02` (`GAP-282`) — the eSewa failure callback | Should the eSewa failure callback exist at its own URI, and which? | **Because the remedy starts executing a handler that has never executed, on a live gateway's POST.** `routes/web.php:857` declares `Route::post('/plan-pay-with-esewa', …'failure')` and `:858` declares `Route::any('/plan-pay-with-esewa', …'success')` — the same URI. `Route::any` includes POST and is second, so it takes the slot, while `route('plan.esewa.payment.failure')` **still resolves by name to that URL**. Driven: a POST reaches `@success`. It looks like a missing `s`, because the Sofort pair three lines above uses two different URIs — and "looks like" is no basis for re-routing a payment callback. Declared, driven and pinned so nobody tidies the spelling. |
| **J4** | `P5-FIN-6` (`GAP-138`) — which capture predicate every Benefit rail shares | The plan rail gates on `$data->gateway->response->code == '00'`; the new storefront check gates on `status === 'CAPTURED'`. Which should both use? | **A money rule, and the divergence is currently in the SAFE direction, which is why it was left rather than unified.** Independent review established that `CAPTURED` rejects authorised-but-uncaptured and pending states that `'00'` accepts, while every genuinely captured charge was also approved — so unifying would **tighten the plan rail, never loosen the storefront one**. Engineering kept one handler internally consistent rather than changing a second rail's behaviour on its own authority. Note one sub-case that belongs to `J5` rather than here: a refunded Tap charge can still read `CAPTURED`. |
| **J5** | `GAP-285` — binding a Benefit charge to its order's AMOUNT | What is a BHD store's stored `cartlist_final_price`? | **This is one missing fact, not a choice.** The single-use claim half is implemented (one `Cache::add` on store + `tap_id`, reusing `claimGatewayReturn()`'s own contract), so a captured charge now pays for one order. The amount half was deliberately not implemented, and the reason is arithmetic rather than caution: `serverPayableAmount()` rounds to 2 decimals, `OrderTotalReconciler::amountsMatch()` compares at SCALE=4 and **refuses rather than rounds**, Benefit is the Bahraini network and **BHD has 3 decimals**, while `processBenefit()` sends the unrounded `cartlist_final_price`. Too loose a comparison is theatre; too tight refuses real Bahraini payments. Given the fact, it is three lines. |
| **J6** | `P04-P2-03` (`GAP-293`) — `tax_methods.priority` is a VARCHAR | Cast the sort, or migrate the column to an integer — and what should an existing non-numeric `priority` become? | **It changes what an order is CHARGED for existing stores.** Measured on 10.11.14-MariaDB and proved to be the sort rather than insertion order: `ProductPrice()` does `orderBy('priority','asc')` on a VARCHAR, so the engine sorts as text and `'10'` precedes `'9'`. With bands at 9 (25%) and 10 (10%) the engine returns the 10 row — **20.00 of tax where the merchant's own lowest-numbered band says 50.00, a 30.00 difference per order.** A merchant who numbers bands 1–9 never sees it. Either remedy changes which band applies to live stores, and a migration additionally has to decide the non-numeric case. |
| **J7** | `P04-P6-04` (`GAP-294`) — a NULL `orders.final_price` | What does a NULL `final_price` mean, and what should the refund ceiling be for such an order? | **Because the two obvious defaults are both wrong, in opposite directions.** The ceiling derives from `final_price`, which is nullable, so a NULL caps every refund at zero and the merchant cannot refund that order at all. Treating NULL as zero refuses a legitimate refund; treating it as unbounded uncaps one. So the remedy is a question about the DATA, not about the expression — and engineering should not pick which. The ceiling demonstrably works where the value exists: a 500 request on a 267 order caps at 267, and an in-range request is not capped. |
| **J8** | `P04-P1-06` (`GAP-288`) — `carts` has no product-name column | Should `carts` carry a denormalised product name, or the basket a snapshot, so a delisted product can be named to the shopper? | **A new column on a live table is a persistent migration, which the standing rules reserve.** The agreed `GAP-241` remedy is "drop the stale line and tell the shopper WHICH product went" — and with no name column, a deleted **product** cannot be named, only a deleted **variant** can. The nameless case is the common one, because "a merchant delisted a product" is precisely the scenario `GAP-241` exists for. So that remedy is **half deliverable by construction**, and the register says so rather than reading as met. Both cases are pinned, including an assertion that the nameless wording is what a deleted product produces. |
| **J9** | `P05-04`, `ANA-007` (`GAP-287`) — `MetricCaveat::FLOAT_PRECISION`'s basis is refuted | Re-ground the caveat on a production reading, or withdraw it? | **Only you or the operator can take the reading, and the sentence is on the busiest money figure in the product.** The caveat tells a merchant a figure "is stored at limited precision", grounded in a docblock claiming `orders.final_price` is FLOAT. Measured through Laravel's own schema builder on 10.11.14-MariaDB, with a literal-`FLOAT` control: `$table->float()` emits **`double`** and **0 of 5** money values lose precision, while the control loses **3 of 5** (`999999.99` → `1000000`). So the reasoning would have been sound for the column the docblock names, and that is not the column the migrations create. **The limit is real and is why this is yours:** that measures a FRESH schema, and the migrations are not the schema — `SHOW CREATE TABLE orders` on production is the only thing that closes it, and nothing in a container can reach production. Re-runnable at `tests/Support/Security/bin/measure-float-money-basis.php`. CLAUDE.md records a caveat withdrawn on exactly this point once before. **SHARPENED 2026-11-26, and the sharpening changes the question rather than answering it.** An independent re-measurement asked what happens to a figure every surface ACCUMULATES: over 10,000 rows, `SUM()` and PHP `+=` differ from the exact decimal total by **0.0000111163 on 253,136,425.00**, where a `DECIMAL(15,2)` sum is exact to the cent. **So the caveat is wrong in two directions at once.** Its stated basis is refuted — and a real accumulation error does exist, which is why simple withdrawal is not obviously right either. But that error is about a thousandth of a cent, **three orders of magnitude smaller than "the smallest units" the sentence promises**, and would need ~450x more accumulation than a merchant performs to reach half a cent. **Engineering's recommendation, stated as one:** the sentence overstates on every basis that survives measurement, so it should be re-worded or withdrawn rather than kept as is — but engineering did neither, because it is a monetary disclosure on eleven merchant-facing surfaces and the deciding input is still production's own column type. Nothing new may attach on the float reasoning meanwhile: a ratchet refuses a fifth site. |
| **J10** | `P03-B-02` (`GAP-098`) — the theme editor's generated form | Should `customize.blade.php`'s **generated** form controls move onto the design system, or does the shell stay on host Bootstrap now its contrast failures are closed? | **It is a merchant-visible visual change across all three themes, through generated markup, and it closes no measured failure.** The editor shell's 14 contrast findings are down to 2 (the excluded `D-25` item), so a further conversion buys legibility nowhere — and the precedent is measured: 23 of a previous slice's 30 conversions closed nothing measurable, and one produced byte-identical captures. The remainder is ~498 host class tokens across five views (23 `form-control` fields built from theme JSON, 122 field labels, 73 section titles, 29 page titles). **The plan's own cost estimate points the wrong way**, so a "too expensive" ruling given on its figures would be given on stale ones: the shell as built has **no tabs and no modals**, which was the plan's single largest named cost for this package. Engineering's recommendation is to keep this row open on this branch alone and record the contrast half as done — declaring the whole shell out of scope would be false now that its contrast is clean, and converting on a slice's authority would start a three-theme visual change. |
| **J11** | `P06-05`, `P06-01` (`GAP-178`, `GAP-174`) — is `P06-05` the same requirement as `ADM-001` + `ADM-002`? | Rule on the MAPPING before ruling on scope: is `P06-05` a derived duplicate of two requirements already tracked as `GAP-231`/`GAP-232`, or does it name something additional? | **Ruling on the mapping is cheaper than ruling on a candidate set that may belong to a different row, and it may dissolve the requirement rather than answer it.** `P06-05`'s entire text is the two words "platform admin" from a **derived** summary; `P06-01`…`P06-08` appear nowhere in the recovered plan, and `GAP-174`'s own next action is to re-key these rows onto the family ids they trace to. The platform-admin family is `ADM-001` (platform command dashboard) and `ADM-002` (Store 360°), both already tracked separately, with the scope reconciliation recording `ADM-001` as overlapping `P06-05`/`P06-06` "by subject only". **Measured input the existing escalation lacked:** `D-P6-36`'s candidate list was written from screen NAMES and predates four operator screens, understating the set by six routes — two of which are this row's whole subject. The real partition is `PlatformOperatorOnly` 37 routes (Lynomia 15, `platform/*` 9, `plan-coupon` 7, four plan/order actions, `zid/*` 2), `can:manage-platform-addons` 7, and the `platform/` prefix as 9 operator + 11 public. No screen, route or capability was added pending your ruling. |
| **J12** | `P2.5-I-02` (`GAP-270`) — the web and the app disagree in Arabic | Should the Flutter app's Arabic and the web's Arabic be unified, and on whose wording? | **Because unifying it re-words values already shipped on a screen under review.** The app's `ar.dart` glosses the wallet transaction types as verbal nouns and uses استرجاع for refund; the web's `resources/lang/ar.json` spells the same nine as completed actions (تم إلغاء الطلب, تم استرداد الطلب) and uses استرداد. Only `withdrawal_request` agrees exactly. Engineering added the five missing app keys in the app's own house terminology so the nine read as one set there, and did **not** re-word the four already shipped. A copy decision, and the kind that is cheap now and expensive after a release. |
| **J13** | `D-25` (`GAP-179`) — unchanged question, **corrected inputs** | No new question. Two things you were told about `D-25` were wrong, and the estimate may be incomplete. | **The file list in the row was wrong and a brief issued from it repeated the error.** It named `layouts/app.blade.php` and `partision/sidebar.blade.php`; measured, **`sidebar.blade.php` contains `dash-mtext` ZERO times** and the three files that do are `layouts/app.blade.php`, `partision/menu-tree.blade.php` and `partision/menu-zones.blade.php`. A worker told to avoid the two named files could have edited `menu-tree.blade.php` believing it outside `D-25`. **A fourth site is not a Blade file at all:** the admin chrome builds a `btn btn-sm btn-primary` inside a **JavaScript template string** on every admin page, at the same 2.58:1, invisible to a DOM harness. **And the population may be incomplete:** `GAP-179`'s surface list derives from `PlatformOperatorOnly`, so surfaces behind other middleware were never measured — twelve of fourteen findings on the theme editor were defect classes `D-25`'s sibling slices had already repaired elsewhere. A re-derived census is under way; its count is the input your ruling needs. |

**Still open from earlier sections and not restated here:** the double-applied tax band charging
267.00 where 245.00 is correct (work-queue S21.4), `D-25` itself, `GAP-006`/`GAP-024`, `GAP-030`'s
prepared and MySQL-proven migration, the five legal documents and contact details, the Flutter
baselines awaiting human review, and `I1`–`I19` above.

### J14 — `GAP-280`: should a basket holding another store's product COMPLETE without it, or keep being refused?

| | |
|---|---|
| **Where** | `GAP-280`, `GAP-241`; measurement at `tests/Support/Security/bin/measure-foreign-product-cart-read.php` |
| **The choice** | **(A)** scope the cart reader to the slug-resolved store, so a foreign line is dropped and reported and **the basket completes without it**; or **(B)** keep the present refusal and only make it name the offending line. |
| **Engineering has taken (B)** | Integrator decision, 2026-11-26, because (B) changes no behaviour and fixes the user-visible half. (A) is yours. |
| **Why (A) is not engineering's** | **It is a payments behaviour change, measured rather than argued.** On `POST {slug}/get-payment-paiementpro` with a guest cookie basket: today a mixed own+foreign basket writes **0 orders** and flashes *"item this store does not sell"*; under (A) it writes **1 order** and the shopper's own stock moves **50 → 48**. So a basket that is refused today completes. |
| **And (A) disarms a control as a side effect** | `basketHasForeignProduct()` takes `$cartlist['product_list']` as its **input**, so dropping the line empties the gate's input and the gate cannot fire. Six security cases assert that refusal. The control's own docblock says it was extended and wired for this leg. |
| **Cross-tenant protection holds either way** | Measured in all four combinations: **no foreign stock moves and no foreign product reaches an order** under (A) or (B). This is not a tenancy question. |
| **What (B) leaves unfixed** | The foreign line's price stays folded into the displayed total (`87.00` measured on a `10.00` basket). The basket still cannot be paid — so the total is not a charge — but the screen shows a figure the shopper cannot act on until they remove the line. (B) gives them the affordance; it does not correct the arithmetic. |
| **(A) additionally needs a second workstream** | The gateway arm lives in `PaymentController` and the gateway controllers. A half-enacted (A) would let a mixed basket through the API arm while the gateway arm still refused it, so behaviour would depend on which checkout the shopper reached. |
| **Severity, corrected** | The row originally read as a confidentiality exposure. It is **not**: the same principal reads the same name, price and image through store B's own slug on `product-detail` (`GAP-244`'s recorded property). It is an **integrity** defect plus an ownership oracle of small increment. |
| **Blocked** | Nothing. (B) ships without you. (A) waits. |

### J15 — `GAP-300`: the storefront focus ring is one fixed colour, and the storefront has brand-coloured bars

| | |
|---|---|
| **Where** | `GAP-300`; measured by `tests/Support/bin/check-storefront-keyboard-live.cjs`, 884 checks over 30 pages |
| **The choice** | A second ring tone, an outline-plus-shadow pair, or per-theme ring tokens? |
| **Why it is yours** | It is a visual-design decision across **three** themes, and the engineering is trivial next to it. |
| **Measured** | 32 of 534 focus stops fall below WCAG 1.4.11's 3:1 — techzonix purple `rgb(153,82,224)` **1.02:1**, greentic green `rgb(57,132,60)` **1.04:1**, stylique's coral arrow **2.64:1**. Read with the runtime Tailwind JIT's 216–263 injected rules present, because a ring read without them is read against a cascade no shopper sees. |
| **And a figure in the repository was surface-specific** | The focus layer records "4.44:1 / 8.27:1". That is true **against the page background only** — the one surface that existed when it was written. A contrast figure taken on one surface and recorded as a property of the ring is how 32 failures sat behind a documented pass, and it is the same shape as `GAP-272`'s "dark passes throughout". |
| **What engineering did and did not do** | The *other* half of this surface is fixed: a closed off-canvas menu was in the tab order of **every** page, focus leaving the visible page after the footer, and that is closed using the themes' own `hidden` convention with offscreen stops 90 → 0. The ring is left alone. |
| **The closing condition is executable** | The pinning test fails the moment the focus layer declares a `box-shadow`, so whichever remedy you choose announces itself rather than needing to be noticed. |
| **Blocked** | Nothing. The measurement, the fix to the menu, and the declarations all ship without you. |

### J16 — `GAP-257`: may a `Paid` on a CANCELLED or RETURNED order credit the merchant's wallet?

**This entry exists because the decision was named and never asked.** `GAP-257`'s disposition has
said "both are a change to a money rule and belong with an owner decision" since it was raised, and
it was not in this file — so the one place the owner reads did not contain it. Added 2026-11-26 by
the workstream sent to fix it, which found the money half reserved and the cases unmeasured.

| | |
|---|---|
| **Where** | `GAP-257`; `OrderFinancialService::syncFromOrder()`'s P4-F124 guard. Both doors: `OrderController::order_payment_status` (panel) and `Api\DashboardController` (app), each behind `Manage Order`. |
| **The choice** | Treat `delivered_status` 2 (cancelled) and 3 (returned) as terminal for the `paid` direction regardless of the financial row's state — or route a settled-order payment correction through the finance surface on both doors — or leave both as they are. |
| **Why it is yours** | It decides whether a merchant is credited for an order the customer cancelled or returned, and the opposite error is already on record: refusing at the door is the regression the settled-order echo fix exists to close, because a genuine late payment on a settled order has to be recordable. |
| **MEASURED 2026-11-26, through the real panel route, not traced** | `tests/Feature/Commerce/SettledOrderPaymentCreditTest` drives all four cases and reads the raw wallet row. The guard COVERS a cancellation and a refund whose reversal ran (financial row terminal): wallet stays **0.00**, no credit. It MISSES two. **(C) a RETURNED order** — nothing calls `OrderEntitlementReversal` for a return, so the row sits at `unpaid` and a `Paid` moves the wallet **0.00 → 100.00** on a fixture whose goods came back. **(D) a cancellation whose row is non-terminal** (a reversal that threw, or one predating P4-F124, whose unconditional update wrote the row back after the debit): the ledger ends holding **two `order_paid` credits and one `order_cancelled` debit for one order**, and the wallet keeps **100.00** of a cancelled order's value. That is the "credit the merchant a second time" P4-F124's own docblock names, now measured rather than inferred. |
| **One correction to the row** | Case (D) needs the order's OWN `payment_status` column to move, because `OrderObserver::updated()` returns early unless `wasChanged('payment_status')`. A `Paid` posted onto an order already reading `Paid` moves nothing — the first draft of the test read 0.00 for exactly that reason, which is a request that did nothing, not a defect that is absent. |
| **What engineering did ship, because it is not a money rule** | The register's stated mitigation — "an audit line already ships, so a double credit is reconstructible in the meantime" — was true of **one of the two doors**. `Log::warning('Payment status changed on a settled order')` existed only in `Api\DashboardController`, whose own comment names the panel door as the older and wider one. The panel door now carries the identical line and predicate, so a credit on a settled order names the actor on both. Nothing refuses, no figure moves. |
| **Blocked** | Nothing is waiting on you. The behaviour is pinned in both directions, so whichever way you decide shows as a failing test rather than as silence. **Also not answered here:** a `Paid` after a return credits the financial row's `customer_total`, which is the ORIGINAL total — `Order::product_return()` has by then written `orders.final_price` down — so the figure credited can exceed what the order is now worth. Same decision, stated so it is not discovered separately. |

**Two findings registered this batch that are NOT owner decisions**, listed so nobody escalates
them by mistake: the public route reading every wishlist on the platform (`GAP-286`) and the
settings panel that switches another principal's setting off (`GAP-289`) are both engineering's to
fix, and are assigned or queued as such.

---

**What is already decided and must not be re-asked:** `H1`–`H5` above, and everything in sections
A–G. Section `J` is new on 2026-11-26 and nothing in it has been decided. A recommendation in this file is a recommendation; only a dated `DECIDED` line is an answer.
