# Phase 02 — decision register

Every owner decision that governs Phase 02 engineering, with its **date, exact scope and stated
limits**. Before this file existed those decisions lived only in the execution conversation, while
the task ledger referred to them in passing — so a reader could see that a row was "closed on the
owner's approval" without being able to read what was approved, or what was explicitly *not*.

**This file records decisions. It does not record progress.** Status lives in
`PHASE-02-TASK-LEDGER.md`; evidence lives in `PHASE-02-TEST-EVIDENCE.md`; the requirements
themselves live in `PHASE-02-MASTER-EXECUTION-PROMPT.md`, which is verbatim and not to be edited.

---

## The four status words, used precisely

The owner asked for these to be distinguished rather than blurred. They are not degrees of the same
thing — each says something different about *why* a row is where it is.

| word | means | what it implies about the work |
|---|---|---|
| **DONE** | the acceptance criteria are met **and verified by executed evidence** | nothing outstanding on that row |
| **PARTIAL** | some acceptance criteria are met and verified; others are **not started or not verified**, and the row names which | the remainder is ordinary Phase 02 work, still owed |
| **DEFERRED** | the requirement is **intact and owner-approved for a later phase**, with the phase named | not owed in Phase 02; must not be reported as delivered |
| **BLOCKED** | the work cannot proceed on something **outside this session's authority or environment**, and the row names the blocker | not owed until the blocker clears; needs a decision or an environment change |

A row is never DEFERRED because it was hard, and never DONE because CI is green. Green CI is
necessary evidence, not proof of a release-ready result — the third of the three standing principles
in `CLAUDE.md` §2.

---

## D-01 — `products.cost_price`, the migration that unblocked product creation (F58)

**Approved 2026-10-26.** Fix `products.cost_price` and create and test a migration matching the
real production schema, preserving existing data. **Permitted to apply it in isolated development
and test environments** after verifying its safety.

**Limits stated by the owner:** not approval to deploy to production, to run the migration against
the production database, to merge into `main`, or to rewrite Git history.

**Status:** DONE in dev/test. The MySQL verification script is written and registered and
**has not been executed** — this container has no MySQL server — so F58 is **not closed on MySQL**,
and the ledger says so rather than implying otherwise.

## D-02 — order attribution to the authenticated customer (F46)

**Approved 2026-10-26.** Attribute an order to the authenticated customer instead of the posted
value, with comprehensive security and integration tests, preventing any regression in payments and
orders.

**Status:** DONE. The finding was recorded against one file; a census found the same block **byte
for byte in nine**, and all ten authenticated branches now take the id from the session. What is
**not** claimed: whether the nine web/gateway copies were reachable with a hostile `customer_id` at
all — `ModuleStorefrontInputGuard` is per-route and whether it covers the gateway callbacks was not
determined. Defence in depth of undemonstrated reachability, not a proven exploit closure.

## D-03 — product validation, surfaced errors and opened sections (F70)

**Approved 2026-10-26.** Complete field validation, surface save errors and open the sections
containing errors, **while preserving Flutter compatibility and existing API contracts**.

**Status:** DONE. The response gained an additive `errors` key with `flag` and `msg` byte-identical.
Flutter compatibility is **measured, not assumed**: the shipped app posts product writes to
`api/admin/addproduct` → `Api\DashboardController`, a different handler on a different route, and
the Contract suite stayed 26/26.

## D-04 — order status display and translation between Laravel and Flutter (F29)

**Approved 2026-10-26.** Fix the display and translation of order statuses, **preserving
compatibility with published versions and not changing the statuses' commercial rules**.

**Status:** DONE. `order_status` still carries the numeric value, so a client switching on the
number is unaffected; only the human-readable string stops being wrong for statuses 7 and 8.

## D-05 — Flutter dependencies, and the Syncfusion licence

**Approved 2026-10-26.** Handle Firebase Messaging compatibility and the dependencies needed to
build and test. For **Syncfusion: verify the actual licence and use a licensed, compatible
solution. Do not assume a licence that has not been proven, and do not purchase one without
separate financial approval.**

**Status:** BLOCKED on the environment for the build half (blocker **B4**:
`firebase_messaging_web` 3.5.18 does not compile under Dart 3.13.7, and **zero files under `lib/`
appear in the failure**), and BLOCKED on a commercial decision for Syncfusion (**B3**: no
`registerLicense` call exists anywhere). Neither is engineering work this session can finish, and
neither has been worked around by assuming a licence.

## D-06 — gallery image reorder: DEFERRED to Phase 03 (F78)

**Decided 2026-10-26.** Deferred to **Phase 03**, to be implemented with **full parity across the
storefront, the web merchant UI, Flutter and the WorkDo add-ons, without editing the vendored module
files.**

**Recorded explicitly as a scope-limited deferral, not as an implemented feature.** Two things the
owner ruled out by name, and neither has been done:

- **No unused `sort_order` column.** A column nothing reads would let a merchant reorder images and
  see nothing change. Verified: `product_images` has `id, product_id, image_path, image_url,
  store_id, created_at, updated_at` and nothing was added.
- **No web-only ordering that differs from Flutter.** Nothing orders gallery images today —
  insertion order by `id` is the de-facto order on every surface, and it stays that way, so the web
  and the app continue to agree.

**Why it is a Phase 03 shape and not a Phase 02 one:** the column is the easy part. A census found
the gallery is read in about twelve files across four surfaces — the web controller, the Flutter API
(`Api\DashboardController`, `Api\ApiController`), the storefront/theme views, and **three vendored
WorkDo modules** (`AuctionProduct`, `ProductsPDF`, `DigitalProduct`). Two of those are the blocker:
`packages/workdo/` must not be edited, so a complete feature is impossible in Phase 02 without
either breaking that rule or shipping the surface-to-surface disagreement the owner ruled out.

**What is NOT deferred, and is still owed in Phase 02:** the rest of P2-03-4 — upload, deletion,
the primary image, the variant experience and validation errors — each verified by driving it.

## D-07 — E13: cancel and linked replacement order, minimum safe path IN Phase 02

**Decided 2026-10-26.** The owner did **not** accept E13 as complete on the strength of proving
that an order's products cannot be edited after creation. The original plan requires **cancellation
plus a linked replacement order**, and that is **distinct from the advanced returns system reserved
for Phase 04**.

**Approved:** prepare and implement the **minimum safe path** within Phase 02, on four conditions —
**reuse the existing order engine**, **do not change the original order**, **do not duplicate
payments**, **do not duplicate stock movements**. Sequence required by the owner: design the
cancel-and-replace behaviour, review the relations and the production schema, determine the payment
and stock impact, then implement and test in an isolated environment.

**Limits:** engineering and changes in **development and test environments only**. Not approval to
apply a migration to production. And: *"if the path cannot be implemented safely without an
additional unresolved commercial decision, document the decision point precisely and continue the
other tasks. Do not invent financial behaviour."*

**Status [updated 2026-10-26]:** **STEPS 1-3 DELIVERED, STEP 4 BLOCKED ON ONE QUESTION.** The design, the relations-and-production-schema review and the payment-and-stock impact are complete in `E13-CANCEL-AND-REPLACEMENT-DESIGN.md`, in the order the owner required. The decisive measurement: **cancellation reverses the money but does not restore stock**, while `return` does — so a replacement that takes stock again duplicates the movement, which this approval forbids. The engine already owns every primitive needed (atomic restore, a per-line idempotency marker, a database-level `UNIQUE (order_id)` on the financial row), but **no link column exists anywhere** — not in the model, not in any migration, not in the verified production schema — so one additive migration is required, dev/test only under this approval.

**The one blocking question (Q1): may a replacement differ from the original at all?** Answer "identical lines only" and the recommended design is implementable immediately and the other two questions fall away; allow changed lines and stock deltas must move, which is a pricing-and-stock rule this approval does not cover. Documented rather than guessed, which is what the limit above requires.

## D-08 — E14: independent security and financial review of the payment callbacks

**Decided 2026-10-26.** Approved an independent security and financial review of the payment
gateways and their callback paths. Add **real tests** for callback replay resistance and for the
prevention of duplicate payments, duplicate state transitions and duplicate stock movements, with
**concurrency testing where appropriate**.

**The owner's standard of proof, verbatim in effect:** *"do not assume that a ratchet which only
reads the code is enough to prove that a financial operation is not duplicated."* So a static
census is step one and not the deliverable.

**Limits:** preserve existing gateway compatibility; **do not change settlement or refund rules
without a proven necessity and an independent review.**

**Status:** IN PROGRESS. One correction to the scope as the owner framed it, recorded here because
it enlarges the work rather than shrinking it: the owner's figure of **nine** gateways came from
this session's own earlier wording, which was F46's count of *files sharing one order-writing
block*. The real population is **35 files with order-write sites**, of which about 28 are payment
gateways. The review covers that population, not nine.

---

## D-09 — variant price and stock persistence: DECIDED (D-16) AND IMPLEMENTED (F82, F83, F84, F105)

**Raised:** 2026-10-26, by driving the product edit page. **Decided by the owner as D-16 (A1)
and implemented on 2026-10-26** — see "How A1 was actually built" at the end of this entry, which
also records that **this entry's original diagnosis was wrong** and how. Full evidence:
`P2-03-4-VARIANT-PERSISTENCE-DECISION.md` §2.

**What was measured.** A variant price or stock edit made on the edit page is **silently
discarded** and the merchant is told the save succeeded (F82). The only path that does persist
such an edit **rebuilds** the variant set: it hard-deletes every variant absent from the posted
option set and writes `0` over the price of every covered variant whose field is blank — measured
as 8 variants in, 4 out, three prices `NULL → 0` (F83).

**Why it is a decision and not a fix.** The obvious repair — have the page post the keys the
rebuild gate requires — routes **every ordinary product save** through that delete-and-zero path.
That is a change to what a save does to pricing and stock, reserved by the standing limits below
("تغيير سلوك الدفع أو التسعير أو المخزون أو الطلبات"), and the owner's E13 instruction applies in
the same spirit: *"إذا لم يمكن تنفيذ المسار بأمان دون قرار تجاري إضافي غير محسوم، وثّق نقطة القرار
تحديداً وواصل باقي المهام. لا تخترع سلوكاً مالياً من عندك."*

**The two options, costed.**

| | What it does | Cost | Risk |
|---|---|---|---|
| **A1 (recommended)** | A variant panel's edit writes **only** the rows the merchant edited: no rebuild, no delete, no zeroing. Both rebuild paths stay exactly as they are for the case they exist for — the merchant changing the attribute configuration. | A new per-variant write path, so it needs its own ownership guard and its own tests. | Smallest blast radius: nothing a merchant does on the edit page can delete a variant row. |
| **A2** | Render hidden gate fields reflecting the product's **current** attribute set, so the rebuild regenerates what is already there plus the edits. | Smaller diff, reuses the existing engine. | Correctness depends on those hidden fields always reproducing the stored set exactly. A product whose attribute terms were deleted after its variants were created would lose the uncovered variants on an ordinary save — a live data-loss path. |

**Not an option:** leaving it. A merchant who edits a price, reads "تحديث المنتج بنجاح" and keeps
selling at the old price is a commercial defect either way.

**What was done in the meantime**, so nothing drifts while the decision is open:
`tests/Feature/Merchant/ProductVariantPersistenceTest.php` characterises the discard, the hard
delete and the zeroing, plus a static ratchet on the delete sites — mutation-checked both ways.
F84 (an unguarded `visible_attribute_<no>` read, not UI-reachable) is recorded against whichever
option is chosen rather than patched in isolation.

### How A1 was actually built, and the two things that had to be unlearned first

**THE DIAGNOSIS ABOVE IS WRONG WHERE IT SAYS THE EDIT PAGE DOES NOT POST THE GATE'S KEYS.** It
does. The page calls `attribute_option_data()` from `$(document).ready`, which injects the values
panel — carrying `options_datas[]` — into the form before the merchant has touched anything.
Driving the real form produced **154 named parts carrying all three gate keys**; the probe that
reported otherwise matched only the FIRST occurrence of a repeated multipart name. So the edit
was never "discarded for want of a key": it reached a writer that overwrote it. That is also why
F105 exists — an ordinary save with no field touched was already running the rebuild.

**THE OBVIOUS IMPLEMENTATION OF THE OWNER'S EXTRA CONDITION WAS DRAFTED AND REFUTED.** The
condition is that an ordinary save must stop opening the rebuild merely because `for_variation=1`
sits in the stored product data. The natural reading — compare the posted attribute configuration
against the stored `products.product_attribute` and rebuild only on a difference — was designed
in full and then independently refuted. Three findings killed it:

- That column holds **at least four incompatible shapes**: `store()` writes the terms verbatim,
  `update()` writes resolved option ids, the degenerate `[""]`, and the literal string `'NULL'`
  that `edit.blade.php` already tests for. `product_attribute_options.terms` is an unconstrained
  varchar, so a numeric term is indistinguishable from an option id. The comparison would have
  read "changed" on the first save of most products and fired the very rebuild it was added to
  prevent.
- The same draft gated the write of `product_attribute` on that comparison. That column is the
  **only** store for the "Visible on the product page" flag, so the toggle would have become
  unsavable; and it is `''` on every simple product, so enabling variations on one would have
  been **permanently locked out** — first save false, nothing written, still `''`, forever.
- Its test plan would have passed against unfixed code, because the values panel echoes each
  stored price back.

**What was built instead.** Two independent halves, neither of which touches
`products.product_attribute`:

1. **The writer is non-destructive.** `ssVariantValue()` replaces
   `!empty($request[k]) ? $request[k] : DEFAULT` on every per-variant column: an absent or blank
   key leaves an existing column alone and only takes the default on a row being created, and a
   posted `"0"` is stored as 0 — which `!empty("0") === false` made impossible before. The `sku`
   write, which was unconditional and recomputed from the product name, now only fires when the
   merchant actually typed a token.
2. **The destructive delete is left exactly as it was** — and that is the second thing that had
   to be unlearned. A set-difference guard over `product_variants.variant` was built for it and
   then refuted by review of my own change: `whereNotIn('variant', $com)` already deletes exactly
   the rows in `array_diff(existing, posted)`, so such a guard is a restatement of "this delete
   has something to delete" and can never change an outcome. A `store_id` predicate added
   alongside it was worse — it bought nothing the ownership check does not already give, and it
   would have silently stopped the **platform operator's** cleanup, because `getCurrentStore()`
   returns the literal 1 for an admin with no `current_store`. Both are removed and recorded as
   **F113**, with the reasoning left at the delete so they are not re-added.

The gate itself still opens on `for_variation_N == 1`; what it can no longer do is damage
anything, because the writer no longer overwrites a column the merchant did not send. What
protects an ordinary save from the delete is not a new guard: it is that the values panel is
built FROM the variant rows, so an ordinary save posts the set that already exists and the delete
matches nothing — plus the pre-existing `ss_last_seen_at` conflict guard for the stale-form race.
**That is thinner than a guard would be, and it is stated here rather than dressed up:** what a
deliberate narrowing should do is a commercial decision, recorded as D-20 below.

**Against the binding conditions.** No deletion or zeroing on an ordinary save: proven, and six
of twelve tests across the two variant files fail when the controller is reverted.
Configuration path separate from the daily-values path: the destructive operation now requires a
genuine set difference, which only the configuration panel produces. Permissions: unchanged —
the path sits behind `update()`'s existing operator-first ownership check, which was reviewed
rather than assumed. Partial edits: covered (absent key, blank key, typed `"0"`). **Concurrency
is NOT covered by a test and must not be read as if it were** — the suite runs SQLite
`:memory:` with `CACHE_STORE=array`, so `Cache::lock` is process-local and no Feature test can
stage two merchants saving one product; what exists is the pre-existing `ss_last_seen_at`
conflict guard, unchanged by this work. **F82, F83 and F105 are closed; F84 remains recorded.**
Six further defects found while reading this code are recorded as **F107–F112** and deliberately
**not** fixed here.

---

## D-10 — the printed receipt's return policy: DECIDED AND IMPLEMENTED (F85)

**Raised:** 2026-10-26, by the Arabic `__()`-coverage sweep. **Status: open — the owner supplies
the text.** Nothing changed.

`resources/views/order/receipt.blade.php:262` hard-codes WorkDo demo boilerplate on **every
printed customer receipt**: it states that merchandise may not be returned, names an unrelated
brand ("a Milo Showroom"), and **ends mid-sentence** — `"and sales c"`.

Every available action states a refund rule, which is why this is a decision and not a cleanup:
translating it publishes a refund policy that is not Store Syria's, in Arabic, on every receipt;
rewriting it means authoring the real policy; deleting the paragraph leaves the receipt with no
return policy at all. Covered directly by the owner's instruction *"ولا تغيّر قواعد التسوية أو رد
الأموال دون ضرورة مثبتة ومراجعة مستقلة"*.

**DECIDED [2026-10-26]:** the owner authorised **removing** the paragraph and explicitly forbade
substituting one — *"لا تستبدله بسياسة إرجاع أو استرداد جديدة من عندك"*. He allowed a **real**
approved policy stored in the store's settings to be shown instead, safely and translated, after
verification.

**IMPLEMENTED, and the "if one exists" branch was measured rather than assumed.** There is no such
setting: **zero** rows in `settings` match `polic`, `refund` or `invoice_term`; no
`return_policy`/`refund_policy`/`invoice_terms` setting appears in any migration or any reader in
`app/`; and the one candidate — the storefront route `return-policy` — resolves to
`TestimonialController::return_policy()`, which renders `other_page.privacy`, a generic privacy
page and not a store setting. So the footer renders **nothing**, because a placeholder would be a
policy statement by implication.

**No actual return or refund rule changed.** The paragraph was display text on a printed document;
nothing read it and no handler branched on it.

**Pinned by `ReceiptReturnPolicyTest` (3 tests) at the RENDERED level, not the source**, because
the explanatory Blade comment left in that view quotes the brand name to record what was removed,
and Blade strips comments at compile time — so a source grep would pass while the paragraph was
live, or fail while it was correctly gone, depending only on comment wording. It asserts the
rendered receipt carries **no refund-policy language at all** (about meaning, not the one removed
string), that the comment really is stripped while remaining in the source, and that no
policy-shaped setting exists yet — so the day somebody adds one, the test fails and they must
decide deliberately whether the receipt shows it. **Revert-checked in both directions:** restoring
the WorkDo paragraph fails it, and so does a well-meant substitute (*"Items may not be returned
after 14 days. All sales are final."*), which is the case the owner forbade by name.

The `ArabicUiCoverageTest` exemption went with the string, exactly as its old docblock said it
would; the list is now asserted **empty**, kept as a named constant so adding one is a visible edit.

**Still outstanding and not something a view can answer:** the owner asked that any mandatory
invoice-content requirement be checked before release. Recorded in the ledger as an open release
gate.

---

## D-11 — Arabic validation and UI translations: implemented under existing authority (F86)

**Date:** 2026-10-26. **No new owner decision required**, recorded so the scope of what was
changed is explicit and auditable.

Arabic is the platform's primary language and was the one locale with no
`lang/<locale>/validation.php`, so **every built-in validation message in the product was
English** for an Arabic merchant. Fixed additively: `resources/lang/ar/validation.php` (106
messages), `auth.php`, `passwords.php`, `pagination.php`, and 15 keys in `ar.json` — four of which
were strings **earlier Phase 02 slices of this project had shipped untranslated**.

**What makes this inside existing authority rather than a new decision:** it adds translations and
changes no rule, no threshold and no write. The one behavioural touch is the Validator's fourth
argument, which supplies the field **names** a message substitutes into `:attribute` — chosen over
a global `validation.attributes` map precisely because the map would alter messages on every form
in the platform, and that would have needed a decision.

---

## D-12 — the Paystack plan-activation gate, and what "provider verified" should mean (F87, F88)

**Raised:** 2026-10-26 by the E14 review. **Status: open — the owner chooses.** Nothing changed.
Evidence: `E14-PAYMENT-CALLBACK-REVIEW.md` §1.

**What was measured.** Of the 18 providers `config/payments.php` classifies as
`plan_activation_provider_verified`, seventeen gate on the **payment state** and one — Paystack —
gates on the **API envelope** (`$result['status'] == true`, i.e. whether the verify *call*
succeeded). The handler records the real state as `payment_status` and never tests it, has no
`GuardsPlanActivation` kill switch, and calls none of the project's replay helpers. A failed or
abandoned transaction's reference therefore activates a plan, repeatably.

**Two options, and neither is a pure correction.**

| | What it does | Cost |
|---|---|---|
| **E1** | Require `$result['data']['status'] === 'success'` at the gate | One line, almost certainly right — but it changes what a payment callback accepts, which the standing limits reserve |
| **E2** | Move Paystack onto the existing fail-closed map (26 providers already there) | No new code, uses machinery already built and tested — but Paystack plan purchases then return **403**, which breaks the flow for any merchant actually subscribing through it |

**And the durable half (F88).** `PlanPaymentPostureTest` asserts that each verified provider carries
a verification *marker*, not that the marker describes an adequate check. Paystack's marker is
accurate and describes a weak gate, so the test passes while the classification is wrong. Whatever
is decided for Paystack, the question "what must be true for a provider to count as verified" is
the one that stops the next weak gate being absorbed just as quietly.

**Scope note.** Plan activation is **Phase 06**. This is reported because the owner authorised a
security and financial review of the payment paths, not as a request to start Phase 06.

---

## D-13 — stock consumed without payment evidence on four gateway return paths (F90, F91, F92)

**Raised:** 2026-10-26 by the E14 review. **Status: PARTIAL as of 2026-10-28** — the unverified
decrement is CLOSED on Khalti (verification hoisted above the stock loop, measured and bitten) and
**BLOCKED on PaiementPro, CinetPay and Easebuzz**, which have no provider verification to hoist.
See the implementation outcome under D-18.
Evidence: `E14-PAYMENT-CALLBACK-REVIEW.md` §2.

**The cross-cutting result of the review, stated with its bounds.** Replay resistance is largely
**present** — every live customer-order writer holds a `claimGatewayReturn()` claim keyed on the
checkout nonce — and the **money** axis is defended by Phase 01's inversion of
`resolveOrderPaymentStatus()` to `Pending` when no verification record exists, so no merchant wallet
is credited on the weak paths. What is **not** defended is stock: Khalti, PaiementPro, CinetPay and
Easebuzz each decrement inside their cart loop, with an inline read-modify-write, before or without
any payment evidence, and never give it back on any exit. The routes are anonymous and unthrottled.

So the exposure is **denial of inventory**, repeatable with a fresh checkout each time, rather than
free paid goods. That bound is why this is one decision and not four incidents.

**Why it was not fixed.** The remedy is either to move each stock loop below verification or to
adopt the `AppliesCartStockChanges` trait that `NMIPayController` already uses (atomic decrement
plus `restoreStockDecrements` on every exit). Both change what a payment path does to stock, which
the standing limits reserve. F92 (AuthorizeNet's captured amount never reconciled against the order
total) is a one-line enrolment in `ENFORCED_PAYMENT_TYPES` and changes whether a payment is
**accepted**, so it is reserved for the same reason.

**One thing the owner should have measured before any of this is escalated as live:** Khalti and
PaiementPro dereference `$plan->shipping_method` unguarded *before* the stock loop, having assigned
`$plan` only inside `if ($user->type == 'admin')`. If the platform's first `admin` row has no
resolvable plan, both handlers 500 before any stock moves and these two paths are latent. One query
settles it. **NOT ESTABLISHED from the repository.**

**F91 is listed here but is a different question.** `CyberSourceController` takes `store_id` from
request input and uses it to choose **which merchant's gateway credentials sign the charge** — the
caller-supplied tenant predicate, applied to credentials. It is bounded today only because the
endpoint host is hardcoded to the CyberSource **sandbox**, which also means a real CyberSource
shopper is never charged and still reaches the order writer. The decision there is what that path
should do at all, not how to patch it.

---

## D-14 — F89 was fixed under existing authority, recorded for audit

**Date:** 2026-10-26. **No owner decision required.** Recorded so the one code change this review
made is explicit.

A charged card ended in an HTTP 500 for any order containing a variant product
(`NMIPayController:1328`, object syntax on an array), **after** the capture and after the order row
committed. It is a crash, not a rule: it changes no settlement, refund, price or stock behaviour,
and a crash is not gateway compatibility. Reproduced as a 500 with the capture recorded, fixed, and
revert-checked — with two limits recorded rather than glossed: only the accessor is proven by the
test, and the sibling writers are **not** affected because their basket is a shallow-cast array of
objects, so object syntax is correct for them.

---

## D-15 — E13: the limited replacement path is approved CONDITIONALLY (owner, 2026-10-26)

**Status: approved in principle, conditional on proof.** The owner's words matter here more than a
paraphrase, because the approval is narrower than "A1 is approved" would suggest:

> أعتمد مبدئياً مسار الاستبدال المحدود الذي يحافظ على البنود والكميات والأسعار الأصلية دون تغيير.
> لكن موافقتي مشروطة بإثبات سلامة الدفع والمخزون، وليست موافقة على افتراض أن الإلغاء يرد أموال
> العميل تلقائياً.

**And he corrected the framing I had offered.** My write-up implied that answering Q1 with
"identical lines only" would settle the settlement and stock questions. It does not:

> لا تفترض أن اختيار "نفس البنود" يحسم تلقائياً جميع مسائل التسوية والمخزون.

### The nine binding conditions, as given

1. No change to products, quantities or prices in the replacement, within this slice.
2. No replacement order that causes the amount to be collected twice.
3. **Explicitly distinguish reversing the MERCHANT's balance from a real refund to the CUSTOMER
   through the payment provider.** These are not the same event and must not be conflated.
4. **Do not treat the original order as having consumed stock without evidence from the real
   processing path.**
5. **Do not use a "stock already decremented" marker on an order whose decrement is not proven.**
6. Refuse or disable the automatic replacement path for partial payments, and for collected
   payments whose refund or transfer is not provably correct.
7. Use the existing order, stock and payment services. No parallel engine.
8. Safe under failure, repetition and concurrency, with independent tests for each case.
9. The link migration may be prepared and run in **isolated development and test environments
   only**.

> إن تعذّر إثبات سلامة أحد السيناريوهات، اتركه BLOCKED مع سببه، واستكمل السيناريوهات الآمنة.

**Scope:** E13 stays part of Phase 02. Replacement with different products or quantities, and the
advanced returns system, stay in **Phase 04**.

**Consequence for the design already written.** `E13-CANCEL-AND-REPLACEMENT-DESIGN.md` proposed S1 —
the replacement carries the original's stock via the existing per-line marker. Conditions 4 and 5
put that behind a proof obligation the design did not discharge: it assumed the original consumed
stock because its payment path normally does. Whether a durable record exists that distinguishes
"this order decremented stock" from "this order did not" is now the gating question, and S1 is
**not implementable** until it is answered. That is being established before any code is written.

---

## D-16 — D-09: design A1 approved (owner, 2026-10-26) — IMPLEMENTED 2026-10-26

**Approved.** Edit existing variants **in place**, without rebuilding every variant when a price,
stock or SKU changes.

Binding conditions:

- An ordinary save must **not** delete variants that were not sent, and must **not** zero financial
  values the merchant did not intend to change.
- The **attribute-configuration change** path stays **separate** from the **daily-values edit**
  path. Two paths, not one with a flag.
- Test store permissions, concurrency, partial edits and data integrity.
- **F82 and F83 close only after real saving is proven, with no unintended deletion or zeroing.**

This supersedes the open decision recorded at D-09, where both options were costed. A1 was the
recommendation and is now the instruction.

---

## D-17 — D-12: Paystack treated as an isolated security fix (owner, 2026-10-26) — IMPLEMENTED 2026-10-26

**Approved**, and explicitly **not** as the start of Phase 06:

> أوافق على معالجة مشكلة F87 وF88 كتصحيح أمني معزول، وليس كبداية لأعمال Phase 06.

Required:

- A successful API call must **not** by itself activate a SaaS plan.
- Verify the real payment status; match transaction, amount, currency, plan and user **where the
  provider's contracts support it** — i.e. match what can actually be matched, not an invented
  contract.
- Prevent the same reference being processed more than once.
- Tests for **failure, cancellation, success and callback replay**.
- If verification safety cannot be proven, use the **existing** fail-closed mechanism in the
  development environment, and document the impact of disabling the payment method and any release
  decision that follows.
- No subscription activated without valid payment evidence. **No production change before release
  review.**

---

### How D-17 was met, condition by condition

- **"A successful API call must not by itself activate a plan."** The gate now requires
  `data.status === 'success'`. The old gate tested `$result['status']`, which is whether the
  verify *call* succeeded.
- **"Verify the real payment status; match transaction, amount, currency, plan and user where the
  provider's contracts support it."** Status, reference, **amount and currency** are all matched,
  and a mismatch REFUSES.

  **The first version of this fix logged the amount instead of refusing, and that was wrong.**
  It reasoned that `GuardsPlanActivation`'s docblock warns a blind amount comparison "would refuse
  real payments on a live integration", and that this repo only *believed* Paystack used minor
  units because it divides by 100. Independent review refuted it with evidence that was already in
  the repository: **this server renders the checkout**, and it sends `amount: res.total_price * 100`
  (`resources/views/plans/stripe.blade.php:3228`) with `currency: res.currency` — the same platform
  currency the handler compares against. Both sides are first-party and agree, so the unit is
  corroborated by the write side, not assumed.

  **Without that refusal the gate proved the wrong thing.** Status and reference together establish
  that *some* transaction in the platform's Paystack account succeeded; they say nothing about
  **which plan** it bought — and the plan is a route parameter whose encrypted id the plans page
  prints for every plan card. So a merchant could pay once for the cheapest plan and open the
  callback URL with the most expensive plan's id. Recorded as **F120**.

  Plan and user are not provider-supplied (route and session), so there is nothing to match them
  against directly — the amount is what ties the payment to the plan.
- **"Prevent the same reference being processed twice."** `existingPlanOrderForProviderTxn()`,
  called across **every locale spelling** of `payment_type` rather than the current one — the
  caller chooses the locale through the ordinary language switcher, so checking only `__('Paystack')`
  let a merchant step around a row written in another language. A replay by the same buyer for the
  same plan **re-runs `assignPlan()`** rather than merely claiming success, because the PlanOrder
  row is written before the activation and a failure between them would otherwise strand a real
  payer permanently; a different buyer or a different plan is refused and logged.
  which the handler now reaches by adopting the trait. **This surfaced a defect in the guard
  itself (F115):** both existing callers build the lookup key with `__()`, and `ar.json`
  translates the provider names — `__('SenangPay')` is `سينانغ باي` under Arabic, executed, not
  assumed. A row written in one locale is invisible to a lookup in another, so the guard silently
  misses in ordinary bilingual use. Paystack now uses a locale-independent constant for both the
  key and the stored value; SenangPay and Ozow are recorded, not changed, because altering what
  they store is a data decision.
- **"Tests for failure, cancellation, success and callback replay."** All four, plus a mismatched
  reference and an unusable response. **The decision was extracted into a pure method on purpose:**
  the handler reaches Paystack with a raw `curl_init()`, so there is nothing to fake, and in this
  container a failed curl refuses on the OLD code too — a route-level test would have passed
  against the defect. Four of the nine tests fail when the gate is mutated back.
- **"If verification safety cannot be proven, use the existing fail-closed mechanism."** Not used,
  because it can be proven. Note that calling `assertPlanActivationEnabled()` here would NOT be a
  soft fallback: Paystack has no entry in the `plan_activation` map and a missing key is treated as
  disabled, so it would `abort(403)` every Paystack plan purchase.
- **"No production change before release review."** Nothing deployed; branch only.

**One thing this did not close.** F88's general half: nothing compares the other 17 verified
providers' markers against what their code requires, so the list would absorb the next weak gate
just as quietly. That is its own slice.

**And one thing it uncovered that is NOT D-12's to fix (F116).** `plan_orders.payment_type` holds
a translated string, and `plans/action.blade.php` and `plans/receipt.blade.php` compare it against
English literals — so a bank-transfer plan order created while the product was in Arabic shows the
super admin **no approve action and no invoice link**. Fixing it properly needs a backfill of
existing rows, which is production data.

---

## D-18 — D-13: inventory security on the four named paths (owner, 2026-10-26) — **NOT DONE.** Atomicity and accounting landed 2026-10-27; the security dependency is OPEN

**Approved** for Khalti, PaiementPro, CinetPay and Easebuzz. The objective as stated: prevent stock
being drained without a payment, or without a legitimate and proven stock reservation.

Required **in this order**:

1. **Verify actual reachability of each path first**, including Plan conditions and any dependency
   that might prevent reaching the decrement code. (This is the `$plan->shipping_method` unguarded
   dereference recorded as NOT ESTABLISHED — it may make two of the four latent rather than live.)
2. Then use the existing `ProductStockService` and `AppliesCartStockChanges` mechanisms **where
   appropriate**, with tests proving no unauthorised decrement, restoration on failure, and no
   duplication.
3. **Do not conflate** verified electronic payment with COD and external-payment paths, which have
   different rules.

**Also in scope for triage:** F91 (a caller-supplied `store_id` selecting which merchant's gateway
credentials sign a charge), F92 (AuthorizeNet's capture never reconciled against the order total),
F96 (the Paymera lock's 10-second TTL held across SMTP and SMS) and F97 (a gateway's
server-to-server route behind `auth`, with the code's own comment asserting the opposite). For each:
decide whether it needs an urgent fix or an independent decision.

---

### OWNER RULING, 2026-10-27: THIS IS NOT DONE, AND SAYING SO IS THE POINT

> ما تم إنجازه من تحويل عمليات الخصم إلى ProductStockService هو تحسين مهم للذرّية والمحاسبة، لكنه
> ليس إثباتاً لمنع استنزاف المخزون… لا تعتبر D-13 DONE لمجرد وجود خصم ذرّي أو استعادة دفاعية لم
> يصل إليها اختبار فعلي.

Recorded verbatim because it corrects something this register was drifting towards. The atomicity
and the accounting are real and are kept; neither is evidence that an unverified decrement cannot
drain stock, and the restoration added at the three in-loop exits is **defensive code no test has
reached** (F121). **D-13 stays OPEN.**

**The `$plan` fix stays suspended**, and the owner has narrowed the condition for lifting it:

> لا يشترط إكمال E13 بكامل واجهة الاستبدال لإصلاح `$plan`، لكن يشترط إغلاق الاعتماد الأمني الخاص
> بالمخزون أولاً.

So E13's **full replacement path is not a prerequisite** — only the stock-security dependency is.
`$plan` may then land as its own reviewed commit, with tests proving no illegitimate decrement
path was opened. That is a materially easier bar than "finish E13", and it is the one to aim at.

### What was implemented, and the half that was not

**Implemented.** The twelve raw read-modify-write decrements across the four handlers D-18 names
by name — Khalti, PaiementPro, CinetPay, Easebuzz — now go through the existing
`AppliesCartStockChanges` / `ProductStockService`, three sites per handler. Two things follow:

1. **The decrement is atomic.** `$remain_stock = $X->col - $qtyy; save()` is a read-modify-write:
   two concurrent returns read the same figure and the second write erases the first. The service
   issues `UPDATE ... SET col = col - ?`. This is the half that matters most and **it cannot be
   driven in this suite** — SQLite `:memory:` with `CACHE_STORE=array` makes `Cache::lock`
   process-local, so no Feature test can stage two overlapping returns.
2. **The decrement is accounted for**, which is the prerequisite E13's durable stock evidence
   depends on (`E13-STOCK-EVIDENCE-ANALYSIS.md`): a movement record written from the shared
   service is only evidence when every writer goes through it.

**NOT implemented, and not claimed.** D-13's "close the unverified decrement" half. These handlers
still decrement before the payment is known to have succeeded, and they cannot be made to wait:
nothing in the schema records that an order consumed stock, so a decrement deferred to
confirmation would simply never be taken. That is E13, which is BLOCKED.

**The `$plan` fault is deliberately untouched**, and this is the owner's own constraint being
honoured rather than an omission. The owner required the decrement path to be closed first,
*"لا تُصلح الاستثناء أولاً بطريقة تجعل الخصم غير المشروع قابلاً للوصول"*. Since the decrement
cannot be closed on three of the four handlers, landing the `$plan` guard now would convert a
pre-decrement crash into a **working** unverified decrement on any platform where that
dereference currently throws — the forbidden outcome, exactly. **The question of whether `$plan`
may land as a separate later commit is with the owner.**

**One claim had to be withdrawn during the work (F121).** The change also adds a restoration at
each of the three in-loop error exits. A behavioural test for it was written, passed, and was
**deleted**: the revert check showed it passing against the unfixed handlers too. Driving the
fixture produced a 500 from `Cart::cart_list_cookie()` — which dereferences the same missing
product or variant rows earlier in the request — so the loop's exits are not reachable from there
at all. The restores are therefore **defensive**, the test that replaced it asserts only what is
true, and the crash is recorded as its own defect.

### Implementation outcome, 2026-10-28 — **KHALTI CLOSED. THE OTHER THREE ARE BLOCKED, AND THE REASON IS NOT THE SAME REASON**

Evidence: `tests/Feature/Security/GatewayStockBeforeVerificationTest.php` (10 cases).

**THE DRAIN WAS MEASURED BEFORE IT WAS TOUCHED, AND IT WAS REAL.** On the Khalti return, with the
stock loop in its old position, a capture Khalti itself REJECTED took `products.product_stock` from
50 to 49 and `product_variants.stock` from 50 to 49, with **zero order rows written**. A stored
state carrying no server-computed payable did the same, refusing before Khalti was even asked. Both
are repeatable: the route is anonymous and a checkout can be re-initiated.

**D-18 STEP 1 IS NOW DISCHARGED — reachability, which this register recorded as NOT ESTABLISHED.**
The loop is reached: a confirmed capture moves the figure and writes its order, asserted as its own
case so that every negative case in the file has a premise. And the `$plan->shipping_method`
question is settled by driving it rather than reasoning about it: with `users.plan_id` pointing at
nothing the handler throws `ErrorException: Attempt to read property "shipping_method" on null`
**before** the loop, stock still 50, no order. So the path is **latent only on a platform whose
admin has no resolvable plan, and LIVE on one that has** — the ordinary configuration. The
register's hope that this might make the drain latent in practice holds only for a broken install.
Two bounds stated rather than glossed: the crash is a PHP warning promoted by Laravel's handler, so
it needs `error_reporting()` to include `E_WARNING` — where it does not, the deref yields null,
`null == 'on'` is false and the path runs LIVE; and nothing here says which configuration
production runs, which still needs the one query.

**WHAT CLOSED IT ON KHALTI, AND WHY IT IS NOT THE DEFERRAL D-18 RULED OUT.** D-18 recorded that the
decrement "cannot be made to wait" because no column records that an order consumed its stock. That
reasoning is about deferring a decrement to a **later request** — a webhook or confirmation
callback would need durable evidence not to double-take. It does not apply here, and that is the
whole finding of this slice: on this handler the decrement and the verification are **in the same
request**, roughly 250 lines apart, in the wrong order. Closing the gap is therefore an **ordering
of two statements** — no schema, no marker, no E13 dependency. The verification block was hoisted
to sit immediately above the loop, and a REJECTED capture now returns before the first stock row
moves. Nothing in that block reads anything the loop produces (`$request->payload`, `$store` and
the stored checkout state are all resolved long before it), which is what makes the move safe
rather than merely desirable.

**NO BUSINESS RULE CHANGED, and the tests are shaped to prove that rather than assert it.** A
verified capture decrements and writes its order exactly as before. The deliberate UNVERIFIABLE
branch — the verify call could not be completed, so this server cannot say whether money moved —
still reaches the loop and still writes its Pending order for reconciliation; refusing it would
strand a charged customer, the one outcome worse than the drain. The shopper-visible refusal for a
REJECTED capture is the same redirect with the same message the old post-loop `else` returned, and
that is pinned. Only two cases change, and both change from *"stock taken, no order"* to *"nothing
taken, no order"*.

**Three controls keep the fix honest.** A fix that stopped the drain by simply not decrementing
would pass every negative case, so the reachability case, the UNVERIFIABLE case and the replay case
all assert that the decrement still happens exactly once. A fourth asserts Khalti is asked
**exactly once per return** — a hoist that left the old call standing would double every outbound
verify request, and nothing else in the file would have noticed. **Bitten:** reverting the
controller fails exactly the three drain cases and leaves all six controls passing.

**CONSEQUENCE FOR THE SUSPENDED `$plan` FIX.** The owner's condition was that fixing the exception
must not make an illegitimate decrement reachable. On Khalti it no longer can — a resolvable plan
now reaches a gate, not a drain — so that condition is discharged **for this handler only**. It
still binds on PaiementPro, which shares the dereference and has nothing to gate on.

### The independent review found two defects IN THE FIX, and one of them was the serious kind

Recorded at this length because it is the third time in four packages that `tenant-authz-reviewer`
has found the defect *inside the remedy*, and because both of these were invisible to every test in
the repository.

**1. The hoist clobbered `$response`, and a CONFIRMED GUEST PAYMENT would have ended in an error
after the order committed.** The verify result went into `$response`, which was fine while the
verification sat *below* the cart read. D-22 then moved it *above* that read — and the guest arm
does

```php
$response = Cart::cart_list_cookie($requests_data, $store->id);
$response = json_decode(json_encode($response));      // a stdClass
```

while the thank-you payload 800 lines later reads `$response['status_code']`. **The reviewer
predicted a silent `0`** (so the storefront would show "Payment Failed" for a successful payment).
**Measured on PHP 8.3.6, it is worse than that: it throws** `Error: Cannot use object of type
stdClass as array`. So a guest whose Khalti capture was confirmed would have got an unhandled error
**after** the order row committed `Paid` and the stock was decremented — the same shape as F89, a
charged card ending in a 500. Fixed by giving the verify result its own name, `$khaltiResponse`.

**Why no test saw it: every Khalti test in this repository drives
`actingAs($customer, 'customers')`, and the guest arm is the one that reads `$response`.** That is a
coverage shape, not an oversight in one file, and it is now closed in two places — a verified-guest
case in `GatewayStockBeforeVerificationTest` that asserts the request completes, the payload carries
Khalti's real status code and the order is `Paid`, and a guest-arm file covering all four handlers.
**The new case bites:** restoring `$response` fails it with that exact `Error`.

**2. The new guard swept the whole store when a guest had no cookie.** `whereNull` is what Laravel
makes of `where('cookie_session_id', null)`, and `carts.cookie_session_id` is nullable with no
default — so **every registered customer's row in the store carries NULL there**. The predicate
silently became *"this store's customer baskets"*, and one deleted catalogue product would have
refused every cookie-less guest's return, after their capture had been classified. The guard now
returns `false` for a principal it cannot identify: failing **open** is right rather than merely
convenient, because this guard exists to pre-empt a crash in the cart read and a guest with no
cookie has no cookie basket for that read to crash on. Driven by a test that creates the trigger —
another customer's dangling basket in the same store — and asserts the premise that the row really
does carry NULL.

**3. A third finding was about the window the hoist opens, and it is fixed rather than argued
away.** There are now ~450 lines between *"the gateway confirmed this capture"* and the order write,
and several exits in that window returned `{status: 0}` with **no log at all** — silence that cost
nothing before the hoist, because those exits used to run *before* the verify call. The two
`'Cart is empty.'` exits now call a new `logGatewayExitAfterCapture()`, whose `capture_was_confirmed`
field is the one to search on: `true` means money moved and that shopper has no order. The three
in-loop exits are deliberately left alone — F121 established they are not reachable from the
dangling-basket fixture, and the new guard refuses before them, so a log there would be inventory
churn for unreachable code.

**4. And the review caught a false claim in my own docblock** — that the D-22 file drove "BOTH
arms", when all five of its cases drive the authenticated one. Load-bearingly false, since the guest
arm is exactly where findings 1 and 2 lived. Corrected, with the guest coverage named where it
actually is.

**Two things the review cleared that were worth asking about.** The replay claim is not a
regression: `claimGatewayReturn()` and `Session::forget('request_data')` both sit **above** both
positions, so a rejected return consumed them before this change and still does — and a shopper
whose attempt genuinely failed re-initiates checkout and gets both keys fresh, now *with their
basket intact*, because the cart delete no longer runs for a payment that was refused. And the
tenancy of the gateway secret is unchanged: `$store` comes from the stored state's slug, never from
caller input, so the hoist cannot make another merchant's secret sign the verification.

**A fifth, smaller finding is also applied:** the comment called REJECTED *"the payment does not
cover this basket"*, but `verifyKhaltiCapture()` classifies **any** non-200, non-zero HTTP status as
REJECTED — a 502 included. The comment now says what the code does, and the observability inventory
records the consequence: a run of these may mean Khalti is unwell rather than that shoppers are
being refused.

**The observability ratchet failed in both directions and is repaired properly.** Three log lines
were genuinely **added**, which is exactly the case the resync tool refuses to write, because a new
row needs a destination and a reader that only a person can state. Those three statements are now in
`P01-OBSERVABILITY-INVENTORY.md` before the tool was allowed to re-point the five rows that had
merely moved.

---

### One real behaviour change, stated rather than absorbed into a test

On Khalti the **quantity** and **foreign-product** refusals now fire *after* the verification rather
than before it. That is forced, not chosen: both read `$products`, which comes from the cart read,
and D-22 requires the verification to precede that read. The security outcome is identical in every
case — refused, no order, no stock — and only the message a shopper sees changes, from *"Your cart
contains an invalid quantity"* to *"Transaction has been failed"* when the capture also fails.

It surfaced as three failing rows in `GatewayReturnTrustBoundaryTest` and
`StockManagementSettingTest`, and the honest reading of those failures is worth keeping: **before
D-13 they reached the stock loop with no payment evidence at all.** They were reachable only because
of the defect. Each now binds a confirming Khalti double, so the fixture is a legitimate paid return
— the only kind that should reach a stock loop — and each row's claim is unchanged and stronger. No
assertion was loosened and nothing went into the known-outcome manifest; the full reasoning is in
`PHASE-02-TEST-EVIDENCE.md`.

A fourth failure was the project catching me rather than the reverse: `NullCookieCartTest` forbids
any query naming `cookie_session_id` outside `Cart::scopeForGuestCookie()`, and the new guard had a
raw predicate. That census exists because the mistake has been made before, and it caught the exact
`whereNull` defect the reviewer had found independently. **The code changed, not the test** — the
guard now goes through `forGuestCookie()`/`forCustomer()`, so its fail-open behaviour comes from the
project's own mechanism instead of a hand-rolled early return.

---

### PaiementPro, CinetPay, Easebuzz — **BLOCKED, and not for want of effort**

**There is nothing to hoist, because there is no verification to hoist.** Measured against the
source: `PaymentVerificationService` holds exactly three provider verifiers — `verifyKhaltiCapture`,
`verifyPaymeraCapture`, `verifyPaymeraSignature`. None of these three gateways has one. Each goes
from the stock loop straight to `new Order()`, and the only returns between the loop and the order
write are the three in-loop *"Product not found"* / *"bad json"* exits D-18 already fitted with
`restoreStockDecrements()`. There is no post-loop refusal to reorder and no provider answer to
refuse on.

**So their shape differs from Khalti's in a way worth stating precisely.** On these three an order
row **is** written — Pending, since `gatewayReturnPaymentStatus()` has no verification record to
read — so the consumed stock is attached to an order a merchant can see and cancel. That is not a
defence, but it is not Khalti's silent drain either.

**Closing it needs one of two things the directive reserves.** Either (a) **add** a provider
verification for each gateway — for Easebuzz the reverse hash is verifiable locally with the stored
salt and no outbound call, and this repository already uses exactly that to gate the Easebuzz
**plan** path while deliberately leaving the storefront order path alone *"a merchant's customers
keep checking out"* — or (b) refuse a return carrying no evidence. (a) is *"لا تفعّل بوابة دفع أو
SDK غير متحقق منه"* and *"لا تدّعِ أن اختبار مصدر الشيفرة وحده يثبت الأمان المالي"*: the hash
computation has never been checked against a real Easebuzz response in this repository, and getting
it wrong refuses live customers. (b) loses paid orders, which the owner forbade by name.

Both change **whether a payment is accepted**, on live storefront paths, for gateways whose
responses are unverified here. Per *"إذا كان الإصلاح الأمني سيغيّر سلوكاً تجارياً مؤثراً لم يسبق
اعتماده، أوقف تلك الشريحة فقط، وقدّم الخيارات، وتابع العمل المستقل"* these three stay **BLOCKED**
with the options above recorded, and the work continues elsewhere. **D-13 is now PARTIAL: closed on
Khalti, open on three.**

---

## D-19 — Phase 02 continuation authority (owner, 2026-10-26)

After the safe security remediations: **P2-05-3 through P2-05-8, then P2-06 and P2-07, then the
remainder of P2-01 and P2-04, and finally P2-08.**

> نفّذ كل المهام الروتينية المعتمدة دون موافقة جديدة لكل شريحة.

So no per-slice approval is needed for approved routine work. Test, review, record the evidence,
commit and push to the designated branches, and continue. Preserve Flutter, Lynomia Chat and WorkDo
compatibility. Complete the real isolated MySQL tests where necessary. Keep the Decision Register,
Task Ledger, Test Evidence and Handoff updated, and **treat nothing as DONE without appropriate
testing**.

Unchanged prohibitions: no merge to `main`, no production deployment, no production migration, no
rewriting Git history, no Phase 2.5.

---

## Standing limits that govern every row above

Reaffirmed by the owner on 2026-10-26 and in force for all of Phase 02:

- **No merge into `main`.** No production deployment. No migration run against the production
  database.
- **No history rewriting and no force-push.** Issue **#120** (the database-dump exposure) stays an
  independent security track; preventive fixes and a remediation plan may be prepared, but
  **repository history must not be rewritten and no destructive action taken** without separate,
  specific authorisation. The leaked file is not to be pulled into the work branch.
- **No Phase 2.5**, and Phase 03 and Phase 06 scope stays out of Phase 02.
- **No new or duplicate commercial engines.** Reuse the existing product, order, payment and stock
  services.
- **No weakening of CI and no manufactured results.** Green CI is necessary evidence, not proof.

## D-20 — what a deliberate narrowing of an attribute's option set should do: **PARTIAL.** Refusal and blocking implemented 2026-10-27; the no-loss alternative is analysed, not built

**Raised:** 2026-10-26, out of the D-09 implementation. **Nothing has been changed; today's
behaviour is preserved exactly.**

**What happens today.** When a merchant removes a term from an attribute — so the posted
combination set is narrower than the variants that exist — the uncovered variant rows are
**hard-deleted**. Not soft-deleted: a raw table read cannot see them either. Measured: a product
with 8 variants saved with a 4-combination option set came back with 4 rows and the other 4 gone,
with their prices, SKUs and stock.

**Why it is now a question.** D-09 removed the case where this fired by **accident** — the writer
no longer overwrites untouched columns, so an ordinary save is harmless. What remains is the
deliberate case, and there the delete is doing exactly what it was written to do. The owner's
D-16 condition was that an ordinary save must not delete unsent variants; it is silent on what a
*deliberate* narrowing should do, and inventing an answer would be inventing a merchant-facing
rule.

**The two answers, and what each costs.**

| | What the merchant sees | Cost |
|---|---|---|
| **Keep the hard delete** (today) | Removing a term removes its variants, silently. | Irreversible. A merchant who removes a term to re-add a renamed one loses every price and SKU under it, with no warning and no undo. |
| **Refuse and name what would be lost** | The save is rejected with "this would delete S-Red, M-Red (…) — confirm to proceed", and a confirmed save then deletes. | One extra round trip and a confirmation key on the configuration path. Reversible, which a hard delete is not. |

**Recommendation: refuse and name.** A refusal can be undone by pressing the button again; a hard
delete of priced rows cannot be undone at all. But this is a decision about what the product does,
not a defect, so it waits.

**Not blocking anything.** F82, F83 and F105 are closed either way; this governs only the
configuration path, which is unchanged.

---

---

### The owner's answer, 2026-10-27

Approved: **refusal with the data named, then an independent confirmation.** Six binding
conditions, verbatim in substance:

1. **Do not delete variants on an ordinary save.**
2. **Show the affected variants' names and counts**, and state that their prices, SKUs and
   associated stock may be lost.
3. **A deliberate delete is a SEPARATE operation requiring explicit confirmation.**
4. **Before permitting the delete, check the references and links** that could make removing a
   variant dangerous for orders or commercial data.
5. **If the delete's safety is not established, refuse with a clear message. No silent hard
   delete.**
6. **Test** permissions, concurrency, that unaffected variants are preserved, and that nothing is
   lost on failure.

And a scope limit: *"لا تغيّر آلية الطلبات أو تضف نظام أرشفة جديداً دون ضرورة مثبتة"* — do not
change the order mechanism or introduce a new archiving system without proven necessity. The goal
is preventing unintended loss and preserving data, not building a new subsystem.

---

### How it was built, and the condition that could not be fully met

`ProductController::ssRefuseVariantRemoval()` runs **before the upsert loop**, not around the
delete. That placement is the owner's condition 6 taken literally — a refusal leaves every variant
row exactly as it was, rather than half-upserting the survivors and then refusing.

**Condition 4 is the one that cannot be fully met, and F122 records why.** Only `carts` and
`wishlists` carry a `variant_id` column. An order records its contents in `orders.product_json`,
and the storefront writer's line shape has **no `variant_id` key at all** — measured, all 14 order
lines in the fixture carry `final_price, name, price, product_id, qty, total_orignal_price` and
nothing else. So a search of order history can find *some* references and can never establish
their *absence*.

The implementation therefore splits the refusal, and the split is the whole point:

- **a reference it CAN see** — a cart row, a wishlist row, or an order line that does carry the id
  — is a **hard refusal**; confirming does not make it safe, and the message names what holds it;
- **everything else** is refused until the merchant confirms explicitly, and the message says
  plainly that order history may still refer to these variants and **that this cannot be checked**.

Reporting "no references found" would have been true of the query and misleading about the world.
The deeper fix — recording the variant on every order line — is order-schema work the owner's own
scope limit excludes.

**Tested:** refusal names the count, the variant names and what each carries; a confirmed
narrowing removes exactly the uncovered rows and nothing else; a cart reference blocks it even
with confirmation; a merchant without `Edit Products` cannot remove variants by posting the
confirmation flag (a request field is never an authorisation); and the stale-form guard still
refuses a confirmed narrowing from an out-of-date form, which is the realistic shape of the
concurrency hazard here — a merchant confirming a list another session has already changed. Three
of those fail when the refusal is disabled, verified by executing that mutation.

**Not covered, and stated rather than implied:** true concurrency. `CACHE_STORE=array` makes
`Cache::lock` process-local, so no Feature test can stage two merchants saving one product.

---

### OWNER CORRECTION, 2026-10-27: CONFIRMATION IS NOT PROOF — and D-20 is PARTIAL

> لا أوافق على الحذف النهائي لمتغير قد تكون له مراجع تاريخية غير قابلة للتحقق، حتى مع تأكيد
> التاجر. التأكيد شرط للتغييرات المسموحة، لكنه لا يلغي شرط إثبات السلامة.

The first implementation let an explicit confirmation carry a delete whose safety was unprovable,
on the reasoning that the merchant had been told. **That is the trade the owner refused.** A
merchant agreeing to a warning does not make order history checkable, and what is lost is
commercial data.

#### The permitted case and the blocked case, kept apart

What makes a permitted case possible at all is an **asymmetry in the order line shape**:

| | in an order line? | consequence |
|---|---|---|
| `variant_id` | **absent** from the storefront writer's shape (0 of 14 lines) | "no order used this variant" is **not** establishable |
| `product_id` | **on every line** (14 of 14) | "no order ever contained this PRODUCT" **is** establishable — and it entails no order contained any of its variants |

So:

- **PERMITTED** — no cart, wishlist or variant-id reference, **and no order names this product**.
  Safety is established, and an explicit confirmation carries the delete (condition 3).
- **BLOCKED, confirmation ignored** — any order names this product. Nothing can show the variant
  is absent from that history, so there is nothing a merchant could usefully confirm. The message
  says why and points at the alternative below.
- **BLOCKED** — a cart row, a wishlist row, or an order line that *does* carry the id.

#### The no-loss alternative: analysed, NOT built, and the analysis is the reason

The owner asked whether the operation can be achieved without losing data, using existing
mechanisms first — *"مثل تعطيل إتاحة المتغير للبيع مع الاحتفاظ بسجله إذا كان ذلك مدعوماً وآمناً"*.
The mechanism exists, and measuring it is what stopped it being built in this slice:

- for a variant **not** stock-managed (`manage_stock` absent from `variation_option`),
  `stock_status = 'out_of_stock'` blocks the cart add — `Cart.php:793` admits only `in_stock` and
  `on_backorder`, and an explicit `out_of_stock` falls to the refusal arm;
- for a **stock-managed** variant, `stock_status` is **not consulted at all** on that path — the
  only lever is `qty >= $ProductVariant->stock`, i.e. `stock = 0`.

So "retire this variant" is **two different levers chosen by a flag inside a comma-separated
string**, and whether every cart entry point honours either is **not established** — the
storefront API and the vendored module cart routes were not traced. Building a merchant-facing
"retire" action on that footing would be exactly the kind of unproven safety the owner has just
refused, and the standing limit forbids inventing a new archiving mechanism or migration for it
*"دون تحليل وقرار محدد"*.

**It is therefore named in the block message as the thing to do manually** (set the variant out of
stock) and recorded here as needing its own decision.

#### Why this stays PARTIAL

Conditions 1, 2, 3, 5 and 6 are met and tested. **Condition 4 is met only in the direction that
can be proven**: references that exist are found; references that cannot be checked now block
rather than warn. The acceptance condition "the operation can be achieved without losing data" is
**not** met — the retirement path is analysed and not built. D-20 stays **PARTIAL** until that is
decided and, if approved, implemented with its own evidence.

---

## D-21 — the stock writers D-18 does not cover: **APPROVED (owner, 2026-10-27) — inventory first, then independent slices**

**Raised:** 2026-10-26, out of the E13 least-change review
(`E13-STOCK-EVIDENCE-ANALYSIS.md`). **Nothing has been changed.**

**What D-18 authorises.** Routing raw stock decrements through the existing `ProductStockService`
/ `AppliesCartStockChanges` for **Khalti, PaiementPro, CinetPay and Easebuzz**, by name.

**What it does not.** Five further writers decrement stock with a raw read-modify-write and touch
neither the service nor the trait:

| writer | reachable as | why it is a separate decision |
|---|---|---|
| `Api/ApiController` (two paths) | storefront order writers | a public API; changing what it does to stock on a shortfall changes what a shopper's client receives |
| `AuthorizeNetPaymnetController` | gateway return | a card capture has already happened by the time it runs |
| `PayUPaymentController` | gateway return | same |
| **`Api/DashboardController::createOrderViaApi`** | **`POST api/admin/createOrderViaApi` — the shipped Flutter ADMIN app's order writer** | **the most important writer in Phase 02**, and the one every census missed (ledger F118) |
| `OrderController::store` | merchant admin, manual order | carries its own private copy of the service rather than the trait |

**Why it matters beyond tidiness.** E13 needs durable proof that an order consumed stock. A
movement record written from the shared service would be **silent** on all five, so an absent
record would mean *"no decrement, OR a decrement on an uninstrumented writer"* — the exact
inference D-15 forbids. Coverage is therefore a precondition for E13, not a refinement of it.

**The two API writers are the sensitive ones.** `createOrderViaApi` already runs inside a
transaction with a rollback and already refuses a shortfall, so the change there is not "make it
reversible" — it is "make the availability test and the decrement one atomic statement", which
alters the **timing of the refusal** and therefore the response the Flutter app receives. That is
an API-behaviour change, which the standing limits reserve.

**Recommendation:** authorise `OrderController::store` and the two gateway returns (AuthorizeNet,
PayU) on the same terms as D-18, and treat the two API writers as their own slice with a Flutter
contract check. **No answer is needed to continue D-13**, which is confined to the four D-18 names.

### The owner's answer, 2026-10-27

Approved, within a defined engineering and security scope. **Inventory every path first** — its
success conditions, its failure conditions and its resend/replay behaviour — **then implement
independent slices**, in this order:

- `OrderController::store`
- `AuthorizeNetPaymnetController`
- `PayUPaymentController`
- the two `Api/ApiController` paths
- `Api/DashboardController::createOrderViaApi`

Binding conditions:

- Reuse the **existing** stock services. Prevent lost updates and double decrements. Preserve the
  current payment and stock rules as far as possible.
- **On paths that run after a card has been charged, the stock fix must not produce an error after
  capture without a settlement or restoration mechanism.** A refusal that strands a paid customer
  is worse than the defect.
- **FLUTTER HAS ITS OWN CONDITION, and it is the sharpest one here:** do not change API responses,
  HTTP status codes, or logout behaviour without testing compatibility against the shipped app —
  *"خاصةً لا تحوّل حالات رفض العمليات إلى 401 غير مبررة"*. A new 401 forces a logout in the shipped
  client (`lib/network/network.dart`), so turning a refusal into one is a user-visible regression,
  not a hardening.
- If a path needs an unresolved commercial behaviour change, **document it precisely and continue
  with the other independent paths** rather than stopping.

### Implementation outcome, 2026-10-27 — **D-21 DELIVERED; the unverified decrement is NOT closed**

Full detail in `D-21-STOCK-WRITER-INVENTORY.md` §4. The short version, including what went wrong:

**Scope turned out to be four writers, not five.** `OrderController::store` was already done — a
comment-stripped census found 0 raw decrements and 2 service calls, inside `DB::beginTransaction()`
at `:96` with `rollBack()` at `:410`. It needed nothing.

**Converted:** `AuthorizeNetPaymnetController`, `PayUPaymentController`, both `Api/ApiController`
loops (6 sites across 2 methods) through `AppliesCartStockChanges`; and
`Api/DashboardController::createOrderViaApi` through `ProductStockService` directly with
`$requireAvailability = true`, because it already refused a shortfall and already rolled back — what
it lacked was atomicity, not a refusal.

**The Flutter condition is met and was tested, not assumed.** `createOrderViaApi` keeps its refusal
byte for byte: same `status`/`message` keys, same wording, **HTTP 400 and not 401**, same
`rollBack()`. `AdminApiStockRefusalContractTest` drives the variant shortfall, the product
shortfall, the happy path and the exact boundary (`qty == stock` must still be allowed).

**A defect was introduced by the fix and found by the review of it — ledger F123.** Two
post-capture exits in AuthorizeNet's loop took stock and gave nothing back, because the pass that
added the restores matched one spelling of `return` and those two use another. CI's ratchet was a
*floor* of one restore per loop, so three correct exits hid two wrong ones. Both fixed; the ratchet
replaced with "a restore stands before **every** `return` inside a stock-taking loop", with no list
of spellings — the first replacement did carry one and immediately mis-reported Easebuzz, which
returns `Utility::error(...)`.

**Two findings recorded rather than fixed**, to avoid widening the slice:

- **PayU and `Api/ApiController` have no `basketHasUnusableQuantity()` pre-flight** (the other five
  handlers do), so a negative quantity reached their raw decrement and `$stock - $qtyy` **added**
  stock. `normaliseQuantity()` returns null for anything `<= 0` and the service SKIPs, so the
  conversion closes a stock-inflation path on those two. **The change is therefore not inert there,
  and is not claimed to be.**
- **`PayUPaymentController:507`** dereferences `$ProductStock->variation_option` before `:509`'s
  null test, so its restore at `:605` is unreachable — the same post-capture 500 as F121. Left to
  **D-22**, which is exactly that defect and which requires before-decrement and after-capture
  tests this slice does not have.

**What is explicitly NOT closed, per the owner's own instruction that conversion is not proof:**
the decrement on the gateway paths still happens before the payment is known to have succeeded.
That is D-13's open half and it needs E13's durable evidence, which stays BLOCKED. **D-13 remains
NOT DONE and `$plan` stays suspended.** The lost-update property these conversions buy cannot be
driven in this suite at all — `CACHE_STORE=array` makes `Cache::lock` process-local — so it is
proven against the service by `OrderStockConcurrencyTest`, and the per-writer tests prove only that
each writer delegates rather than doing its own arithmetic (ledger F124).

---

## D-22 — F121: the deleted product or variant that 500s a payment return — APPROVED as its own slice (owner, 2026-10-27)

**Approved**, and scoped by the owner:

> افتح شريحة إصلاح مستقلة لمعالجة حالة المنتج أو المتغير المحذوف الذي يؤدي إلى HTTP 500 أثناء عودة
> الدفع. اختبر ما قبل الخصم وما بعد التحصيل، بحيث لا يضيع طلب مدفوع أو يُطلب من العميل الدفع مجدداً
> نتيجة خطأ في السلّة. لا تعتمد على اختبارات تمر حتى بعد التراجع عن الإصلاح.

**What is established.** A cart line whose product or variant row no longer exists makes
`Cart::cart_list_cookie()` dereference null — `Cart.php:531` (`$cart_product_data->id`) and
`:537` (`ProductVariant::find(...)->variant`) — and the gateway return answers **HTTP 500**. The
same unguarded shape repeats at roughly a dozen sites in that file, so the authenticated path has
it too. `GuardsGatewayReturn::basketHasForeignProduct()` deliberately passes such lines through on
the grounds that "every one of these loops already has a 'Product not found' path for it" — and
the crash means the loop never gets to use it.

**The two halves the owner named, and they pull in opposite directions:**

- **Before the decrement**, a missing row should produce a clean refusal, not a 500.
- **After the capture**, a refusal is the wrong answer entirely: the customer has paid. The fix
  must not lose a paid order or ask the customer to pay again.

So this is not one null-guard. It needs the handler to distinguish "we have not charged yet" from
"the money is taken", and that distinction is exactly what these handlers are weakest at.

**The last line is a direct instruction about method**, and it is answered by the discipline that
found F121 in the first place: the behavioural test written for D-13's restoration passed, then
passed again against the unfixed code, and was deleted. Any test for this slice must be shown to
fail on revert before it is claimed.

---

### Implementation outcome, 2026-10-28 — **THE PRE-COLLECTION HALF IS CLOSED. THE POST-COLLECTION HALF IS A DECISION, AND IT IS STATED AS ONE**

Evidence: `tests/Feature/Security/GatewayReturnMissingProductTest.php` (5 cases).

**F121 IS CONFIRMED ON A DRIVEN REQUEST, which it had never been.** Driving a Khalti return whose
basket names a deleted row, before anything was changed:

| arm / capture | outcome |
|---|---|
| authenticated, capture **VERIFIED**, `products` row deleted | `ErrorException: Attempt to read property "id" on null`, 0 orders |
| authenticated, capture **REJECTED**, `products` row deleted | the same exception |
| authenticated, capture **VERIFIED**, variant row deleted | `ErrorException: Attempt to read property "discount_price" on null`, 0 orders, stock still 50 |

So **a paid customer whose product was deleted mid-checkout got an unhandled exception and no
order.** Two things follow. The crash **precedes the decrement** — it is in the cart read, which
runs before the loop — so F121 is not a drain; and that same ordering is why D-13's restoration
test could never reach the in-loop exits it was written for. The register had this right and now it
is measured.

**WHAT IS FIXED, in the owner's own words:** *"before proof of collection → structured refusal
without HTTP 500 and no illegitimate deduction"*. Two changes, in this order:

1. **The verification moved above the cart read**, not merely above the stock loop. It needs only
   `$request->payload`, `$store` and the stored checkout state, so this is the earliest position it
   can occupy — and the earliest is the right one, because a refusal fired at the cart read needs
   to know whether money was collected before deciding how loudly to log.
2. **A new guard, `basketHasMissingProductRow()`**, asked of the `carts` rows directly, before the
   read. It refuses the basket **whole**, so a healthy line is not decremented on the way to
   discovering a dangling one.

**WHY NOT GUARD THE DEREFERENCES THEMSELVES.** `Cart::cart_list_cookie()` and
`Api\ApiController::cart_list()` are read by the storefront cart page, the sidebar, the API and
nine checkout writers. Making a dangling line **skip** would silently drop a line the shopper has
already paid for; making it yield nulls changes an array shape all of those read. The gateway return
is the surface where a dangling line is dangerous, so the guard lives there, additive, and nothing
else changes behaviour. (Also: `basketHasForeignProduct()` deliberately `continue`s on a missing row
— *"not a provable mismatch"* — which is precisely the line this one catches. They are different
questions and are kept apart.)

**ONE MESSAGE MOVED WITH THE VERIFICATION and it is recorded rather than discovered later:**
`"Cart is empty."` is now reached only after the capture has been classified, so a **REJECTED**
return with an empty basket says `"Transaction has been failed"` where it used to say
`"Cart is empty."`. For a verified capture nothing changes, and the new sentence is the more
accurate of the two for a payment the gateway refused.

### What is NOT closed, stated plainly

*"after proof → do not lose the paid order"* is **NOT done and is not claimed.** A paid customer
whose product was deleted still gets **no order**. What they get instead of an unhandled exception
is a structured refusal and a `critical` log naming the confirmed capture, its outcome and its
amount — strictly more recoverable, and not the order.

Writing the order would mean deciding **what an order line for a deleted product contains**, and
that shape is read by the merchant order UI, the invoice/printing modules and the Flutter app. The
owner forbade inventing a substitute product or variant, so the options are:

- **(A) Write the order from the surviving facts** — the `carts` row still holds `product_id`,
  `variant_id` and `qty`, and the stored state holds the amount actually charged. The line would
  carry no name, price or image, because the row that held them is gone. Preserves the money and
  the customer's claim; needs every reader of an order line to tolerate a line whose product is
  absent, which is a contract question across two repositories.
- **(B) Keep the refusal and make the capture a first-class record** — write a verification/payment
  row the merchant can see and settle from, without an order. Loses no money and invents nothing,
  but the customer still has no order to look at.
- **(C) Prevent the cause** — refuse to delete a product that appears in a live `carts` row, or
  soft-delete it. Touches D-20's territory (deletion behaviour) and does nothing for baskets that
  already dangle.

**(A) is the only one that satisfies *"do not lose the paid order"* literally, and it is the one
that changes a contract the Flutter app reads.** That is an owner decision, not a judgement call,
so it is recorded here and the slice stops. The structured refusal is in place meanwhile, so the
failure mode is a message and a log rather than a 500.

**Bite matrix**, because the owner attached *"لا تعتمد على اختبارات تمر حتى بعد التراجع عن
الإصلاح"* to this finding — and running it corrected one of my own tests:

| state | result |
|---|---|
| HEAD, neither change | 4 of 5 fail |
| the hoist only, guard removed | **3** of 5 fail |
| both | 5 pass |

The rejected-capture case does **not** detect the guard — it detects the **hoist**, because once
the verification sits above the cart read a rejected capture never reaches the crash. It is kept and
relabelled as evidence for D-13 rather than for this slice. The three cases that bite on the guard
are the **verified** paths, which is the correct answer: a verified capture is exactly the caller
the hoist cannot turn away. The intact-basket case passes in all three states, as a control must.

---

## D-23 — P2-07-13: the admin API's permission contract (owner-authorised 2026-10-28, implemented the same day)

**The escalation the parity matrix asked for was answered before the work started.** Gap G3 recorded
that closing it "makes it an API change and therefore an escalation, not a quiet edit". The owner's
resume directive granted exactly that, in writing, with five conditions:

> مسموح بإضافة معلومات الصلاحيات إلى استجابة `adminlogin` بشكل إضافي ومتوافق رجعياً، دون حذف أو تغيير
> الحقول الحالية. يجب أن تكون الصلاحيات مرتبطة بالمتجر الحالي، وأن يفرض Laravel التحقق الأمني فعلياً.
> اختبر التاجر والموظف محدود الصلاحيات والمشغّل، وتبديل المتجر وتغير الصلاحيات. لا تحول رفض الصلاحية إلى
> HTTP 401 يؤدي إلى تسجيل خروج المستخدم من التطبيق. احفظ توافق تطبيق Flutter مع API وLynomia Chat.

Each condition and where it is held:

| Condition | How it is met | Pinned by |
|---|---|---|
| Additive, nothing removed or retyped | two new keys; the eight existing fields are asserted by name | `test_the_login_payload_keeps_every_field_it_had` |
| Bound to the current store | `store_id IS NULL OR store_id = current_store`, reported on the dashboard too so a switch refreshes it | `test_the_permission_list_follows_a_store_switch`, `test_an_employee_of_another_store_holds_nothing_here` |
| Laravel enforces for real | the dashboard withholds order counts, `final_price`, `discount_amount`, the top-product list and the order chart | `test_money_is_withheld_without_the_order_permission` + its permitted counterpart |
| Merchant, limited employee, operator all tested | three principals, plus a store switch and a permission change | the 14 tests in `AdminApiPermissionExposureTest` |
| Never a 401 | nothing on this path answers 401 or 403; fields are omitted and the status stays 200 | `test_a_permission_refusal_is_not_401` |

### The four decisions taken inside that authority

**1. The operator is reported as `'*'`, not as a dump of the `permissions` table.** `isAbleTo()`
refuses the platform operator — there is no super-admin bypass in this codebase — so a list built
from role membership comes back **empty** for the one principal who may do everything, and a client
that hides what the list omits would hide every screen from them. A wildcard does not depend on the
table being seeded (a fresh install and every test database have no rows in it), it is one rule for
a client to implement, and it is the convention Sanctum already uses for an unrestricted token.

**2. Refusal is withholding, not a status code.** A 403 on `/dashboard` would blank the app's home
screen; a **401 would log the merchant out**, because the shipped app forces a logout on any 401 and
treats 403 as session-intact. The handler therefore omits what the caller may not see and still
answers 200 — **no status code on this path changed**, so no shipped client sees anything new.

**3. A withheld figure is OMITTED, never zeroed.** The app rendered `?? "0"`, so a zero would have
read as "this store has no orders". The data exists and the viewer may not see it; those are
different statements, and the web dashboard already says so in as many words
(`overview.blade.php`: *"Omitted entirely rather than shown as zero or 'unavailable'"*).

**4. The client's navigation gate fails OPEN while uninformed, and only then.** A client shipped
ahead of its backend receives no permission list; gating strictly on an empty list would leave the
merchant an app with no navigation. So `mayOffer` offers everything until the server has listed
anything at all, and gates from then on. This is safe **only** because the server enforces
independently — the app's gate is cosmetic by design, and the permission list lives in unencrypted
local storage, so on a rooted device it is attacker-controlled.

### What independent review changed, after the first version was written

The `tenant-authz-reviewer` pass found no hole in the resolver — the predicate, the `instanceof`
guard, the strict `=== null` comparison and the `!$store` guard all verified clean, and it
confirmed `names()` is unreachable with a `Customer` or `DeliveryBoy` principal. What it found was
that **the slice was scoped wrongly**, and that is the more valuable finding:

**The money was still one route away.** `Top_orders`, `orderlist`, `productlist` and `couponlist`
served the same three money fields to the same token, each guarded by `resolveOwnedStore()` —
a *tenant* control — and nothing else. Gating the dashboard alone would have produced a UI telling
staff they may not see order money while another route handed it over. **Decision: gate them, at
403.** The shipped app already maps 403 to "not permitted, session intact", so refusing a list
endpoint is correct and already handled; the dashboard stays a withholding 200 because it is the
home screen. `staticsdata` and the dashboard's own `visitorchart` block are gated the same way, on
`Manage Store Analytics` / `Manage Dashboard` — store traffic is business data, and without it the
endpoint that withheld everything else still served 15 days of it.

**`$storeId` is required, not defaulted.** Defaulting to `current_store` was harmless in
`dashboard()` and a live trap everywhere else: the other handlers key their rows on
`?store_id=`, which `resolveOwnedStore()` admits anywhere in the tenant, so a gate reading
`current_store` would evaluate store A's grants against store B's rows. Making the caller name the
store makes that mistake unavailable rather than documented.

**`Show Coupon` counts.** The order gate accepted its view-only twin and the coupon gate did not,
so a "view coupons, do not edit" role lost the dashboard's coupon block.

**And `adminlogin` now honours the account-state switches.** It checked neither `is_active` nor
`is_enable_login`, so a merchant's "disable this employee" switch did not hold on the app's door —
and this change would have handed the disabled employee a permission list. Mirrored from
`AuthenticatedSessionController:68-74` rather than reinvented, **including the `super admin`
exemption both switches carry there**: without it the platform operator would have been locked out
of the API. It keeps `error()`'s default 200 + `status: 0`, so no status code changes on a path
every client calls.

### What was deliberately NOT done

- **`Utility::addNewData()` was not added to the API login.** It is what grants the merchant role
  the names the dashboard gates on, and the **web** login calls it — but it opens with
  `Artisan::call('cache:clear')`, so wiring it into `adminlogin` would flush the whole application
  cache on every launch of the Flutter app. The dependency is pinned by a test instead.
- **The remaining ~119 `api/admin/*` routes are still ungated.** `routes/api.php` declares no
  permission middleware anywhere. This slice gates the eight endpoints that serve money or
  analytics; the rest is a larger slice and is not claimed.
- **`Utility::addNewData()` was still not added to the API login** — see above. The reviewer also
  noted a consequence worth recording: because `MerchantPermissions::names()` reads live
  (`$user->roles()->with(...)->get()`) it bypasses the Laratrust cache, so a revoked role takes
  effect on the API immediately where `isAbleTo()` on the web can serve a stale grant for up to an
  hour. The new door is the stricter of the two, so the divergence creates no hole.
- **The bottom navigation bar was left alone.** Its five entries are index-coupled to an
  `IndexedStack` through `currantIndex`, so hiding one shifts the indices of the rest. A bug there
  breaks core navigation for every merchant, for the benefit of hiding a tab the server already
  refuses. The settings grid — five independent cards, no index coupling — is gated instead.

---

## D-24 — the owner's decisions of 2026-10-28, and the two scope questions still open

The owner approved four **development/test-only** migrations, resolved the P2-01-19 / P2-01-21
conflict, and asked for "the third scope decision" to be documented by name. My report of
2026-10-28 named **three** scope questions without labelling them, which is the fair criticism
behind that instruction. P2-01-19 is now decided, so **two** remain. Both are recorded here with
the original requirement, the conflict, and the least-risky alternative, and **neither is treated as
approved**.

### D-24a — P2-01-18: which create-actions belong in the command palette

> **DECIDED 2026-10-28 — APPROVED.** The owner named the set, which is the part this entry said
> was theirs to choose: **create a product, create a manual order, add a customer, create a
> coupon** — four create-actions, in the existing palette. Conditions carried verbatim into the
> work: take the routes from the real registration or `MerchantPageDirectory`; show nothing when
> the page or module is unavailable; check the employee's permission **and** the current store both
> when rendering and when the request runs; grant no new permission; **no delete, payment or refund
> action inside the palette**; build no second search or action mechanism; keep Ctrl/Cmd+K and
> keyboard navigation; keep RTL and both languages; and test the merchant, a limited employee, the
> operator, and a store switch. The entry's own recommendation — inherit P2-06-8's "nothing
> destructive" — is therefore confirmed by the owner rather than assumed by me.

**The original requirement** (WS B5 / H5, ledger P2-01-18): *"a quick-action surface a merchant can
reach from anywhere, and from search results"*.

**What is already built.** The palette is in the layout on every admin page
(`layouts/app.blade.php:520` → `partision/command-palette.blade.php`, 160 lines), opened by
Ctrl/Cmd+K (`store-syria-ds.js:770-773`). Favourites, Recents and the search half are done, and
P2-06-12 gave each search result its own permission-aware action.

**The conflict.** `command-palette.blade.php:57` states the contract in the file: the action bar
*"is never populated client-side"* and stays hidden with no selection. The palette's standing
content is Favourites and Recents — **navigation destinations, not actions**. So "from anywhere"
is satisfied and "a quick ACTION surface independent of a selected result" is not, and building one
means deciding **which create-actions** belong there. That is a product judgement about the
merchant's daily work, not a technical choice.

**The least-risky alternative, and why it is not automatic.** P2-06-8 already settled the adjacent
question for this same surface — **nothing destructive in the palette** — and the smallest safe step
is to inherit that decision and offer only the create-actions whose screens the principal may
already reach, derived from `MerchantPageDirectory` so the palette cannot offer what navigation
hides. That is narrow and reversible. It is **not** taken as approved, because choosing the set is
the part the owner owns.

### D-24b — P2-04-12: the invoice-preview clause is module-owned

> **DECIDED 2026-10-28 — APPROVED: KEEP WORKDO.** The existing WorkDo invoice preview stays.
> `packages/workdo/AutomaticOrderPrinting/` is not edited, no parallel invoicing system is built,
> no existing feature is copied into the first-party codebase, and the current permissions and
> guards are kept. **The absence of a new first-party preview is NOT a Phase 02 shortfall** now
> that this scope decision is recorded; any further preview work is deferred to a later phase.
> So P2-04-12's invoice-preview clause is CLOSED by scope, not left open.

**The original requirement** (WS E10, ledger P2-04-12): *"printing and export UI where already
supported"*, including an invoice preview.

**What is already satisfied.** Two of the three clauses: the documents are reachable
(`order/view.blade.php:25` shipping label, `:31` receipt, `:23` the module invoice button behind
`module_is_active('AutomaticOrderPrinting')`), and all of them are permission-gated by
`OrderDocumentAccessGuard`.

**The conflict.** The invoice preview is **not first-party**. Its view lives under
`packages/workdo/AutomaticOrderPrinting/`, and both CLAUDE.md §2 and
`.github/scripts/guard-no-workdo-changes.sh` forbid editing `packages/workdo/`. The guard's own
entry (`OrderDocumentAccessGuard.php:284-288`) records that `invoices/preview` builds `new Order()`
and leaks no order data, and that gating it on an order permission would break the module's settings
iframe. So the clause cannot be delivered by editing the module, and the row's own wording —
*"where already supported"* — may mean it is out of scope.

**The least-risky alternative.** Read the clause as satisfied by what is already supported, record
the module boundary, and leave a first-party invoice preview to a later phase. The alternative —
building a first-party preview — duplicates a vendored feature and is the larger, less reversible
choice. **Not taken as approved either way**; the rest of P2-04-12 proceeds regardless, per the
owner's instruction not to let this stop other work.

### D-24c — the nested-modal rule, as the owner stated it

**Preserve Existing Contracts, Eliminate Nested User Experience.** Binding, and recorded here so it
is not re-litigated:

- Keep every existing `data-ajax-popup` contract and all **266** callers. **No mass deletion.**
- Where a flow opens a dialog inside a dialog, restructure it so only **one** dialog level is ever
  really on screen — or use a full page or the side panel — preserving the operation's result, its
  permissions, and its request/response contract.
- **Measure the real nesting sites.** Do not assume all 266 callers are broken; the host theme's
  `[data-ajax-popup-over]` / `#commanModelOver` mechanism (`custom.js:72-95`,
  `layouts/app.blade.php:484-495`) is deliberate, and only the flows that actually stack need
  restructuring.
- Test close, focus, keyboard, save and cancel behaviour, and add a ratchet that protects the
  callers from accidental deletion.

### D-24d — the four approved migrations, with the owner's conditions attached

**Development and test environments only. No production migration, prepared and tested in an
isolated environment.** Each must be additive and non-destructive, checked against the current
schema *and* the documented production schema (`docs/GATE-F-VERIFIED-SCHEMA.md`) for dependencies,
keys, indexes and backward compatibility, and tested against old rows, new rows, and store
isolation.

| Row | Approved for | The owner's condition, verbatim in effect |
|---|---|---|
| P2-04-9 | named filter sets, per employee and per store | isolation and permissions enforced; sharing a filter combination stays a safe LINK and must not become unrestricted sharing of tenant data |
| P2-04-11 | `orders.order_source` to distinguish POS, manual and storefront | **old rows stay unattributed** unless there is reliable evidence to classify them; payment and stock logic unchanged |
| P2-03-4 | the smallest addition compatible with the existing media model, **if proven necessary** | no parallel media engine; no change to variant identity; no deletion of variant references. If it exceeds supporting today's variants and becomes a Phase 03 feature, document the deferral and do not let it block closing approved requirements |
| P2-04-6 | an **optional** author on NEW notes | appropriate permissions, honest display, and **old notes must not be attributed** to the current employee or to a presumed author |

### D-25 — P2-04-11 `orders.order_source`: the five decisions taken inside the owner's approval

The owner approved this migration on 2026-10-28 (§1, "عند ثبوت الحاجة") with four conditions, recorded
in **D-24d**. Need was proven before anything was written, and five choices inside that approval are
recorded here because each one could reasonably have gone the other way.

**1. NEED. No column already in `orders` answers the question — measured, not assumed.**
Three candidates were read before a new column was accepted:

| candidate | why it does not answer "which path created this order" |
|---|---|
| `payment_type` | a payment METHOD. A storefront COD order and a merchant-typed cash order both read `cod`. It is also the wrong KIND of value: POS wrote it through the translator, so the stored discriminator depended on the UI language — recorded and fixed as **F146** |
| `created_by` | added 2026-10-12, so NULL for every historical row, and NULL for the POS and admin-API paths. It cannot separate "storefront" from "before the column existed" |
| `is_guest` | whether a *customer* was signed in. Orthogonal: a merchant-typed order for a known customer and a storefront order by that customer both read `0` |

**2. NO DEFAULT.** This is the owner's condition («أبقِ السجلات القديمة مجهولة المصدر») expressed in DDL
rather than in prose. A default of `'storefront'` applies to NEW inserts too, so any writer the change
failed to reach would silently CLAIM a provenance nobody established. That is not hypothetical: the
first census of the writers **missed five of sixteen** because the grep output was truncated, and with a
default those five would have produced confident false attributions instead of visible NULLs.
`OrderSourceWriterCensusTest` is the standing guard, and the no-default DDL is what makes an unstamped
writer honest in the meantime.

**3. NO BACKFILL.** Every pre-migration row keeps NULL. A historical order's provenance is not
recoverable from the schema (see the table above), so an UPDATE here would be invention. The list cell
and the detail row both render that absence as an absence, and two tests assert the negative directly —
a legacy order must not be presented as a storefront order.

**4. A COMPOSITE INDEX `(store_id, order_source)`, not a single-column one.** The only query that
filters the column is the merchant order list, which is always store-scoped, so the real predicate is
`WHERE store_id = ? AND order_source = ?`. A standalone index on a three-value column is close to
useless to the planner. **Production note for whoever is authorised to run this:** the column add is
instant on MySQL 8, the index is a build and wants an online DDL path. Neither statement reads or
rewrites existing row values. **This migration has not been run anywhere but an isolated test
environment, per the owner's prohibition.**

**5. THE FLUTTER AND LYNOMIA CONTRACTS ARE DELIBERATELY NOT WIDENED.** Two separate restraints:
the `order_source_label` accessor is kept OUT of `$appends` (everything in `$appends` is serialised into
every API response returning an Order), and `OrderController::order_view` reads the source itself rather
than taking it from `Order::order_detail()`, which builds the web view's array **and** the order-detail
API response. The wire format is therefore byte-identical, confirmed by `--testsuite=Contract` (26 pass).
The cost is recorded rather than hidden: parity gap **G8** — the admin app cannot yet show the source,
and widening the API to let it is a deliberate later decision, not a side effect of this slice.

**What was NOT decided here, and stays as it is:** the vocabulary has three values because the owner
named three (POS, manual, storefront); the admin app's order form records `manual`, the same as the web
form, because the column records the PATH that created the order and not the device that reached it.
Phase 04's approved scope includes "channels", which is why the column is a widenable string rather than
an enum — but no channel value is invented now.

### Implementation outcome, 2026-10-28 — **DONE**, and the useful part is that three deliberate bites did not bite

`app/Services/MerchantQuickActions.php` (new), `resources/views/partision/command-palette.blade.php`,
`tests/Feature/Security/MerchantQuickActionTargetsTest.php` (new, 31),
`docs/development/phase-2/tools/check-quick-actions-live.cjs` (extended).

**IN THE EXISTING PALETTE, WITH NO SECOND MECHANISM.** The four actions are plain links in the
shortcuts region Favourites and Recent already use, with the same classes — so the dialog's focus
trap and Tab order cover them for free, and no new CSS or JS exists to maintain. The per-result
action bar (`#ss-command-actions`, P2-06-8) is untouched: it answers *"what can I do to THIS search
result"* and these four answer *"make something new"*. Rendered **above** Favourites deliberately:
this list is fixed and short while favourites and recents grow, and a merchant should not scroll
past twelve favourites to reach "create product".

**NO NEW PERMISSION IS INVENTED.** Each action declares the permission its own target enforces:
`Create Product`, `Manage Order`, `Manage Customer`, `Create Coupon`. And the list is a **constant**,
not anything derived from the menu — a menu-derived list would grow a delete action the day a module
registered one. No route among them is destructive or money-moving, asserted.

**THE FOUR TARGETS DISAGREE ABOUT THE PLATFORM OPERATOR, which is the one genuinely surprising
thing here.** `order` admits them (`mayUseOrderScreen()` is `isOperator() || isAbleTo()`),
`customer` admits them (`create()` tests the operator first), `coupon` refuses them (F157's
decision), `product` refuses them (gated on a bare `isAbleTo`, per F160). So neither
"operator-first everywhere" nor "no operator branch" is correct: the first offers two actions whose
targets will refuse, the second withholds two the operator can really use. Each row therefore
**declares** what its target does.

### Three bites did not bite, and finding that out is the work

Reverting the permission check, then the operator store guard, then flipping a declaration to a
knowingly wrong value — all 25 cases stayed green on every one. The reason: **the page gate
dominates.** `MerchantPageDirectory::available()` is built from the real `Menu`, which is
permission-filtered, so a principal holding nothing has no pages and therefore no actions whatever
the permission check does.

So the cases that actually exercise each gate had to be constructed:

- **The permission check** is decided only for a principal who HAS the menu page and NOT the action
  permission — and for two of the four those are *different* permissions (`Manage Products` vs
  `Create Product`, `Manage Coupon` vs `Create Coupon`). Those cases now exist, with the premise
  asserted both ways, and they fail the moment the check is removed.
- **The page gate** is a module-availability rule, not a security property: dropping it offers MORE
  actions and every one is still served by its target, so the safety invariant holds perfectly while
  the owner's condition *"لا تعرض إجراءً إذا كانت الصفحة أو الوحدة غير متوفرة"* is violated. It
  therefore needed its own direct test — an employee holding the action permission whose page is
  absent — which now bites it.
- **The operator paths are unreachable today**, measured: the operator's navigation holds **none**
  of the four pages these actions target. (My expectation was `['coupon']` from reading
  `SuperAdminMenuListener`; the measurement said `[]`, because that entry's own permission is not
  among the four.) The declarations and the store guard are kept as defence-in-depth and the test
  **asserts that premise**, so the day someone adds one of these pages to the operator's menu the
  premise fails and whoever added it must exercise the declaration path properly.

**THE INVARIANT WAS ALSO WRONG AT FIRST and that is corrected rather than papered over.** I asserted
`offered === served`. That is a UX preference masquerading as a security property: the right claim is
the **implication** — if the palette offers an action, its target must serve the same principal. The
converse legitimately fails, for the two reasons above.

**THE F157 TRAP APPLIES HERE TOO and is closed.** `getCurrentStore()` hands an operator with an
empty `current_store` the **hardcoded literal 1**, and `getStoreById(1)` normally resolves — so a
null-store guard passes and the operator would be offered four create-actions into **store 1**, a
store they never selected. Refused, with the premise asserted the way F157's was (a store whose id
really is 1, and `getCurrentStore()` really answering 1).

### Measured in a real browser, in both languages

`check-quick-actions-live.cjs`, extended: Ctrl+K on six screens × `en`/`ltr` and `ar`/`rtl`,
**0 failures**. Every rendered action is one of the approved four, in the approved relative order,
each with a resolvable href, each in the tab order, the group above Favourites, and the Arabic copy
**on the rendered page** — إنشاء منتج / إنشاء طلب يدوي / إضافة عميل — which is a stronger claim than
the source ratchet's, since only the DOM proves the translation reached the screen.

**The tool's first expectation was wrong in an instructive way.** It demanded four and reported
three on every page in both languages. The dev merchant holds `Manage Coupon` and not
`Create Coupon`, so `create-coupon` is correctly withheld — the permission gate working, live. A
tool that insisted on four would have reported the gate as a defect. It now asserts subset and
relative order, **and carries a live bite**: grant `Create Coupon`, reload, watch the fourth action
appear; revoke it, watch it go. Without that, "correctly withheld" and "never renders" look
identical.

**That bite did not bite either, at first** — granting the permission changed nothing on screen,
because Laratrust caches a user's permissions in the **cache store** and this server runs
`CACHE_STORE=file`, so clearing the `cache` *table* cleared the wrong thing entirely. The same trap
CLAUDE.md records for `Cachable` models. `cache:clear` empties the store the app is actually using.

**Ratchet widened as a side effect:** `ArabicUiCoverageTest`'s surface list never scanned
`resources/views/partision/`, where the palette, header and sidebar live. Measured before adding it
— every `__()` string already there has Arabic — so this closes a **latent** gap rather than finding
a live defect, and it now enforces the new strings automatically. Adding them also tripped
`TranslationFileIntegrityTest`, which caps how many keys `ar.json` may declare beyond `en.json`; the
six keys went into both files.

---

### Consequence recorded, 2026-10-28 — **P2-04-12 is CLOSED, with this clause as an authorised exception**

This decision needed no code, and that is precisely what makes recording it the deliverable. The
owner's words — *"لا تعدّل `packages/workdo/AutomaticOrderPrinting/`. لا تنشئ نظام فواتير موازياً. لا
تنسخ ميزة موجودة إلى النظام الأساسي"* — rule out all three ways the invoice-preview clause could
have been satisfied: editing the module, building a parallel preview in the core, or copying the
module's feature out of it.

So the clause is not satisfied and is not meant to be. P2-04-12 moves from **PARTIAL** to **DONE
with one authorised exception**, and the open-decisions table's row 3 is **CLOSED**. CLAUDE.md §4
says Phase 02 is not complete until every master-prompt requirement has *"a verified status or an
authorised exception"*; this is the second kind, and it only counts as one because the decision and
its reasoning are written down here rather than inferred from an absence.

**Nothing was built. That is the decision, not an omission** — and it is stated that way so a later
reader cannot mistake the missing preview for work that was dropped.

### Step 1 outcome, 2026-10-28 — **the verification harness and three real defects landed; the CONVERSION ITSELF IS BLOCKED on a decision, with the measurement below**

**What Step 1 asked for, and what measuring it first established.** The instruction is to convert
the shared header to `ss-*` while preserving every id, event handler, link and permission, and
without deleting WorkDo elements. Measured before touching anything:

| measurement | result |
|---|---|
| theme CSS rules targeting `.dash-header` | **210** across `style.css` and `style-dark.css` |
| of those, rules carrying an **id** in the selector | 2 (`#vertical-nav-toggle`, `.dash-head-link#mobile-collapse`) |
| theme JS selecting the header | `dash.js` selects `#mobile-collapse` and `.dash-horizontal #mobile-collapse` |
| header theme classes also used by WorkDo files | `drp-language` 22, `hide-mob` 15, `dash-head-link` 13, `drp-arrow` 12, `dash-h-dropdown` 10 … |
| design-system header component to migrate **to** | **none** — the DS has `.ss-menu` for a dropdown's contents and `.ss-bell__count`, and no header, topbar or dropdown component at all |

So "convert to `ss-*`" has no destination today, and the two ways to create one are both larger than
a small coherent slice:

- **(A) Replace** the theme classes on the first-party header. The DS would then have to reproduce
  what those 210 rules do — layout, spacing, colours, the mobile collapse, light and dark, LTR and
  RTL — because the header renders on **every** merchant screen across 70 modules. If the new
  component misses anything, every screen's header is wrong at once, which is the outcome *"لا تنفذ
  تغييرات واسعة دفعة واحدة"* exists to prevent.
- **(B) Layer** `ss-*` alongside. Nothing breaks, but two of the theme's rules carry an id, so DS
  class rules lose the cascade to them; making the DS win means escalating specificity or
  `!important` across a shared surface, and the result is a hybrid whose every state has to be
  re-verified anyway.
- **(C) Leave the header on the theme** and spend Step 1's budget on the defects the verification
  actually surfaced — which is what has been done, below.

**This is recorded as a decision rather than taken, because both (A) and (B) change a surface shared
by 70 modules and (A) in particular is a re-implementation rather than a migration.** Note the
WorkDo files that share those classes are that module's own CSS/JS copies, not Blade views — so a
rule added to the host theme does not reach them, and `.dash-header` appears in exactly three
first-party views and no WorkDo view. That is what made (C)'s fixes safe.

### What landed on that surface instead, all of it measured

**The verification harness Step 1's conditions require, built first and built to be distrusted.**
`capture-header-contract.cjs` records the header's behavioural contract as data — every id, every
link href and accessible name, every form's action and method, every Bootstrap dropdown **clicked
for real** and its open state read, the header's geometry and computed colours, the focusable set
and the real Tab order — across 8 configurations (en/ltr, ar/rtl × light, dark) × 6 screens.

**And it was non-deterministic at first, which nearly produced a false finding.** Two runs of the
*same* tree disagreed in 2 of 24 captures: the Store Link and POS controls are icon-only with a
`title`, and Bootstrap's tooltip plugin **moves** that attribute into `data-bs-original-title` and
clears `title` when it initialises — so whether the control had a name depended on whether the
plugin had run. The first before/after diff showed those two "gaining accessible names" and I was
one step from attributing it to a CSS change. Reading both attributes is stable *and* the more
accurate answer. **The comparison was only trusted after two runs of one tree came back
byte-identical in every field.**

**The before/after, on the trustworthy tool:** across all 24 captures the **only** difference in
any field is the one intended link change. ids, forms, dropdown toggles, all four dropdowns still
opening, geometry, the focusable set and the Tab order are identical.

**F158 on the header — three failures, three surfaces, measured in a real browser.**

| surface | before | after |
|---|---|---|
| header bar links, dark | `#808191` on `rgb(41,42,51)` — **3.71:1** | `#9596a8` — **5.03:1** |
| header dropdown items, dark | `#808191` on `rgb(38,47,63)` — **3.50:1** | `#9596a8` — **4.75:1** |
| brand as text, light | `var(--theme-color)` on white — **2.59:1** | `--ss-brand-ontext` — AA |

Dark went from **28 of 30** text nodes below AA to **0 of 30**; light from 2 to 0; all four
configurations.

**OPENING THE DROPDOWNS IS WHY THOSE NUMBERS ARE 30 AND NOT 5.** The probe's first version read
only the visible bar and found five nodes. The menus are `display:none` until opened, so the profile
menu, the quick-add list, the store switcher and all eighteen language entries were invisible to it
— **23 of the 28 dark failures and BOTH light failures were in there.** A contrast tool that does
not open what the user opens is measuring a different page.

**The brand token is the merchant's and is not changed.** `--theme-color` is the merchant's brand —
ten presets plus a picker — and the owner's F158 condition protects it by name. So the third fix
reuses P2-A-03's existing `--ss-brand-ontext`: the merchant's own colour darkened only until it
reaches AA, computed once in the layout. No parallel mechanism, no colour of mine, and a custom
picker value handled by the same arithmetic.

**And that fix was wrong in one direction until it was measured.** Applied unconditionally it took
dark mode from 0 failures back to **2, at 2.72:1** — because `--ss-brand-ontext` is darkened against
the *light* page, and the bright brand colour was already compliant on a dark surface (5.34:1). It
is now scoped to light mode via `html:not([data-ss-theme="dark"])`. The P2-A-03 breadcrumb rule has
the same shape and has not been measured on a dark surface; it is left alone, because F158 says to
fix the sites that actually failed and the breadcrumb is not one this measurement covered.

**Every token is confined to `.dash-header`**, which is the scope decision rather than caution: a
bare `.dropdown-item` rule would restyle every dropdown in the application, which F158's conditions
forbid, and `.dash-h-dropdown` — the other natural anchor — is carried by **eight vendored WorkDo
Blade views**, so anchoring there would change surfaces this measurement never looked at.

**F161, found by the harness rather than by reading the markup:** the Log Out link's `href` was the
literal string `route('logout')` — the Blade braces were missing — so opening it in a new tab
requested `<current-path>/route('logout')` and got a 404, on every merchant screen. Set to `#`, not
to the real route, because that route is POST-only and a real URL would trade a 404 for a 405 while
reading as though the link worked without JavaScript. It does not; the onclick submits the form.

`tests/Unit/Ui/HeaderContrastTokensTest.php` (9) pins all three tokens **and recomputes their WCAG
ratios from the two colours**, so a revert fails with the arithmetic attached. Bitten twice:
restoring the old grey fails the bar case, removing the light-mode scoping fails the scoping case.

---

## D-25 — the shared merchant chrome on the design system: ONE scope question, three rows waiting on it

> **DECIDED 2026-10-28 — APPROVED: PHASED DESIGN-SYSTEM MIGRATION, in a fixed order.**
> **Step 1 — shared header:** measure it, convert the shared components to `ss-*`, **keep every
> element id, event handler, link and permission**, delete no WorkDo element, and test the shared
> screens before and after. **Step 2 — shared action buttons:** convert the export button and its
> three neighbours **as one unit**, changing no action behaviour and breaking no form submission or
> Bootstrap/JavaScript contract. **Step 3 — order detail page:** finish the shell with the
> `ss-card` / `ss-row` components that already exist, preserving order data, notes, deletion,
> status, invoices and permissions; duplicated note-rendering branches may be unified **only if
> their equivalence is proved**. Mandatory verification for each step: Arabic and English, RTL and
> LTR, dark and light, phone and desktop, AA contrast, keyboard access, the screens that inherit
> the header with representative coverage of the 70 modules, and a before/after behaviour
> comparison. **If a functional break appears, stop widening that step, fix or revert it, then
> continue with the next slice.** No broad change in one go.

**Raised 2026-10-28, out of P2-01-17. Nothing has been changed; this is a question, not a plan.**

**Why it is recorded once instead of three times.** Three rows carry a PARTIAL whose remainder is
the same change, and each was describing it in its own words:

| row | its remainder, as written | what it actually is |
|---|---|---|
| **P2-01-17** | *"the merchant header is on the design system"* | `partision/header.blade.php` — 281 lines, **4** `ss-` occurrences |
| **P2-04-6** | the order detail page SHELL — **107 legacy classes** | the `@section('action-button')` / page-header region the layout renders |
| **P2-04-12** | the export control is legacy-classed with its three siblings | the same `action-button` region, on the order list |

Converting any one of them in isolation makes the result **worse**, not better: the export control
and its three siblings sit in one header region, so restyling one of four leaves that header
visibly inconsistent on the screen a merchant uses most. And converting the region properly means
touching the shared chrome that `layouts/app.blade.php` renders on **every** merchant screen and
across all **70** vendored modules.

**The owner's own limit is what stops it here.** The instruction of 2026-10-28 was to handle F152
*"from its shared location, with the narrowest safe change, measuring the impact on other
screens"*, and explicitly: **"do not perform a general theme change without a scope decision and
proper review."** A 281-line header conversion plus a page-shell region is a general theme change.
F152 was narrow enough to do under that instruction — one selector token, with a measured blast
radius of zero outside the design system. This is not.

**What has already been done inside these rows, so the remainder is only the styling.** The
accessibility and correctness halves are closed and measured, which is why this is a scope question
and not a defect:

- the header's only unnamed control now has a translated `aria-label` (P2-01-17), and **F52** —
  five bare `target="_blank"` — was fixed earlier with a rendered-html assertion;
- **F158 cannot reach the header at all**: the partial contains **zero** `<p>` elements, pinned by
  a test, so the dark-mode paragraph rule is not part of this question;
- the order detail page's correctness, accessibility, dark-mode legibility and author attribution
  are all DONE under P2-04-6; only the shell's classes remain;
- P2-04-12's documents measure **0 contrast failures in both `screen` and `print` media, in both
  directions**, so the export control's classes are the entire styling remainder there.

**The least-risky alternative, and why it is not automatic.** Convert the shared `action-button`
header region **as one unit** — all four controls together, in one change, measured in the four
real configurations before and after, with the 107-class page shell following only if that first
step measures clean. That is still a change every merchant screen inherits, so the first step needs
approval before it is taken, and the sequencing is the part the owner owns.

**Not taken as approved in either direction.** Until it is decided, P2-01-17, P2-04-6 and P2-04-12
each stay PARTIAL and each point here, so the remainder is tracked once rather than re-described
three times.

---

### Where the three steps stand — 2026-10-29

| step | state |
|---|---|
| **Step 1 — shared header** | **DONE [2026-10-29].** Additive `ss-appbar` scope on both `<header>` branches; the header's inherited text colour onto `--ss-fg-muted`. 24-capture contract diff: only `style.color` moved. The probe written for its keyboard condition found **F177** first. |
| **Step 2 — shared action buttons** | **DONE [2026-10-29].** The order list's four controls and the grid view's three on `ss-btn`, one primary action and the utilities outlined. 16-capture before/after contract: **0 invariant differences** — hrefs, the `.export-btn` hook, the `filename` attribute, the tooltip wiring, the icon classes, the region's layout classes and the export handler's real clicked behaviour all identical. **56 glyph-named accessible names → 0**, and dark-mode icon contrast **2.59:1 → 8.92/11.1** against the 3:1 non-text minimum. Closes P2-04-12's second remainder. Found **F179** (an Arabic export downloads as `download`, no extension) and did not fix it, because a download filename is action behaviour. |
| **Step 3 — order detail page shell** | **DONE [2026-10-29].** Five note branches collapsed to one (**proved byte-identical**, 912 characters each after normalising whitespace and the tone class); both address lists onto `ss-deflist`; the four shell cards onto `ss-card`; the four header actions onto `ss-btn` with translated names. `check-order-detail-live.cjs` **112/112 before and after**, the rendered note list byte-identical. The collapse exposed **F180** — three of the four `order_notes` writers produce shapes no branch matches, measured as 5 blocks rendered of 8 rows — which Step 3 **reproduces rather than fixes**, because its condition is to preserve notes. Closes P2-04-6's named remainder. The Bootstrap 12-column grid stays: `.ss-row` is a flex row, not a 12-column grid, so swapping them is a layout rewrite rather than a conversion. |

**All three steps are now done.** What remains of this entry is the 49 other `action-button` regions, below, and that is a separate decision rather than a leftover.

**The 49 other `action-button` regions stay unconverted and that is the decision, not an
oversight.** Forty-nine more first-party views carry an `action-button` region with the same
`btn btn-sm btn-primary btn-icon` classes. Step 2 converted the two the owner named and the one
that shares a screen with them. Converting the rest is a general theme change of exactly the kind
this entry exists to hold back; it needs its own slice, and it should follow Step 3 rather than
precede it, because Step 3 is where the page shell those regions sit in gets decided.

## D-26 — P2-03-4: per-variant media and bulk edit across combinations are NEW capability

> **DECIDED 2026-10-28 — APPROVED WITH PHASE BOUNDARIES, and the split this entry costed is the
> one taken.** Phase 02 gets **one image per product variant**: the existing media model, reusing
> the gallery upload path and `UploadLimits`; the image bound to the correct variant id **and the
> correct store**; add, replace and remove supported; no loss of a variant's price, stock or SKU;
> no change to variant identity; **no access to another store's media**; save and edit compatible
> with existing data; and the smallest additive migration only where the need is proved, in
> **DEV/TEST only**. **Deferred as approved:** bulk edit across combinations → **Phase 04**;
> gallery image reorder → **Phase 03** (D-06, unchanged). No parallel media engine, and no new
> price or stock engine. F76, F73 and the remaining file-type-acceptance problems are to be handled
> in their own proved slice, keeping downloadable files and video working.

**Raised 2026-10-28, scoping P2-03-4 against the decisions already approved. Nothing has been
changed.**

**What the approved decisions already settle, so this is narrower than the row reads.**

- **D-16 closed the persistence question.** *"D-09: design A1 approved (owner, 2026-10-26) —
  IMPLEMENTED 2026-10-26"*, and the four findings it governed — **F82** (a variant price edit
  silently discarded), **F83** (the rebuild hard-deleting variants and zeroing prices), **F84** and
  **F105** (an ordinary save zeroing 11 of 12 variant prices) — are each marked FIXED by that
  change. The row's status still cited *"BLOCKED on owner decision point A"* until 2026-10-28;
  that blocker no longer exists and the citation has been corrected.
- **D-06 defers gallery image reorder to Phase 03** by name, *"with full parity across the
  storefront, the web merchant UI, Flutter and the WorkDo add-ons, without editing the vendored
  module files"*, and explicitly **as a scope-limited deferral, not an implemented feature**.
- Four of the owner's five closing conditions are **verified**: upload, deletion, the primary
  image, and validation errors (media driven 17/17, contrast 56 measured with 0 below AA, bilingual
  26/26, validation 93/0).

**So exactly one thing is left undecided: per-variant media, and bulk edit across combinations.**

**The conflict.** Neither exists today in any form, so both are **new capability** rather than a
reorganisation of what is there — and the master prompt's Workstream D says *"do not implement new
Phase 04 product pricing or stock engines in Phase 02"*. The scope-boundary table in the ledger
already applies that rule to this workstream: *"P2-03-8 and P2-03-9 reorganise and label what
exists; they add no pricing or stock logic."* Per-variant media is not pricing or stock, so the
rule does not forbid it outright; bulk edit across combinations writes prices and stock to many
rows at once, which is much closer to the engine the rule reserves.

**Why it is not simply ruled out.** *"تجربة المتغيرات"* was one of the owner's five closing
conditions for this row, so dropping it silently would discard a stated requirement. The honest
position is that its two halves sit on opposite sides of the Workstream D line and need to be
decided separately rather than together.

**The least-risky alternative, and why it is not automatic.** Take **per-variant media only** —
one image per combination, reusing the gallery upload path and `UploadLimits` already built and
driven under this row — and leave **bulk edit across combinations to Phase 04**, where the pricing
and stock engine it needs belongs. That is additive, touches no existing price or stock write, and
inherits the media evidence already gathered. It is **not** taken as approved: whether a stated
closing condition may be split, and which half ships in Phase 02, is the owner's call.

**Also still open inside this row, and unchanged by this entry:** **F76** (the dropzone panel
ignores dark mode — recorded, worked around, not fixed), **F73** (`$safe_str` collides for two terms
differing only in a special character), and the missing `accept` on `downloadable_product` /
`preview_video`, which needs its own per-field setting because the server's single configured list
is an image list and would wrongly constrain a video or a ZIP.

---

## D-27 — the owner's final execution directive of 2026-10-28, and what each decision became

**Recorded at the start of the work it authorises, so no slice below has to re-derive its mandate.**
The four entries above (D-24a, D-24b, D-25, D-26) now carry the owner's answer inline; this entry
holds the eleven further decisions and tracks execution.

**The directive's own framing matters and is kept:** *"execute the approved decisions, not prepare a
new plan or re-ask what the owner has answered"*, and — the line that governs every disputed
slice — *"if a financial or security path's safety cannot be proved, do not guess and do not change
dangerous commercial behaviour; put that slice in BLOCKED with clear evidence, then continue with
the rest"*.

| # | decision | owner's answer | state |
|---|---|---|---|
| 1 | **D-24a** quick actions | APPROVED — product, manual order, customer, coupon | tracked in D-24a |
| 2 | **D-24b** invoice preview | APPROVED — keep WorkDo; absence of a new preview is not a shortfall | **CLOSED by scope** |
| 3 | **D-25** shared chrome | APPROVED — phased: header → action buttons → order detail | tracked in D-25 |
| 4 | **D-26** variant media | APPROVED — one image per variant; bulk edit → P04, reorder → P03 | tracked in D-26 |
| 5 | **P2-01-19** nested dialogs | APPROVED — move *Generate with AI* out of the stacked dialog into a side panel or full page; preserve every AJAX contract; batches, not one sweep | — |
| 6 | **financial confirmations** | **CONDITIONAL** — unify only if equivalence is proved across permissions, amount, payee, ordering, payment state, execution count, cancel, double-submit and failure/timeout; withdrawal approve-and-paid needs its own single-execution test; **if equivalence cannot be proved, leave the site as it is and document it** | — |
| 7 | **F158** dark mode | APPROVED — incremental, measured in a real browser, starting from sites that actually failed; no single sweeping theme-wide rule | — |
| 8 | **F153** reward points | APPROVED — export the stored `orders.reward_points`; remove the literal `1`; export stays read-only | — |
| 9 | **F157** operator on coupons | **READ AND EXPORT ONLY** — no create, edit, delete or value change on a merchant's coupons; unify `CouponController` so export and read agree; keep the store explicit and tenant-isolated, with no default that could expose an unintended store | — |
| 10 | **F150** payment keys | **FORWARD-ONLY canonical keys** — the database stores canonical identifiers, the UI shows translated names; per-writer slices; **no backfill, no historical edit, no amount or state change, no re-charge, and an ambiguous value stays as it is** | **DISPLAY HALF DONE; WRITER HALF ALREADY SATISFIED ON `orders`; `plan_orders` RESERVED** — see below |
| 11 | **security & commerce** | APPROVED with business-logic restrictions — D-13/D-18 stock drain, D-22/F121, D-20, E13, F95/F96, each as its own slice, each proved by driving the scenario rather than reading the source | — |
| 12 | **F159** Flutter CI | **DRAFT PR AUTHORIZED** — draft only, no merge, no auto-merge, no change to `main` | **DONE** — PR #4 opened, CI subscribed |
| 13 | **token security** | APPROVED — staged: observability → explicit abilities → **30-day expiry for NEW merchant tokens only**; customers and drivers out of scope; no Sanctum global expiration; no mass invalidation | — |
| 14 | **MySQL/MariaDB** | APPROVED — isolated sandbox, synthetic or scrubbed data only, no production connection; **if unavailable, record the test BLOCKED and never substitute a SQLite result** | — |
| 15 | **Issue #120** | **DEFERRED BY OWNER** — no key rotation, no production handling, no new workstream this cycle; must not be called FIXED, CLOSED or safe; stays in the risk report as deferred by owner decision | **held as deferred** |

**Two standing limits that cut across all of them, quoted because they decide close calls:** an
escalation is required if a security fix *"would change material commercial behaviour not previously
approved"* — then that slice alone stops and the options are presented; and for F150, *"if
canonicalising a given writer requires a change to collection logic or to a payment callback's
result, leave that writer out and record it for independent review"*.

**Not authorised by any of the above, and unchanged:** merge to `main`, production deployment,
production migrations, production backfill, production secret rotation, force-push, history
rewriting, breaking payment or inventory behaviour, deleting historical data or altering
settlements, editing `packages/workdo/`, and starting Phase 2.5 or Phase 03.

---

## F150, as executed — and why the writer half turned out to be empty

**Recorded here because the finding changes the shape of an approved decision**, not to re-open it.
The decision is unchanged and nothing in it was waived.

A six-scope census of every consumer and writer of `orders.payment_type` — `app/`, all 70 vendored
modules, the Flutter app, Lynomia, the display maps and writer feasibility — returned 183
consumers and 41 blockers, and one answer that resized the work: **there is no writer of
`orders.payment_type` anywhere in `app/` or `packages/workdo/` that passes a value through
`__()`.** The single genuine translator writer of that column was `PosController:330`, and **F146
already fixed it**: it stores the literal `'POS'`. So on `orders`, *"the database stores canonical
payment identifiers"* was already true before this slice began, and there was no safe writer left
to canonicalise.

**The 51 remaining translator writers all write `plan_orders`,** which is the SaaS subscription
table, not the order table. The owner's F150 text names no column, so whether they are in scope is
a real question — and three of them are keyed by a replay guard
(`GuardsPlanActivation::existingPlanOrderForProviderTxn`, a raw
`where('payment_type', $paymentType)`, called from `SenangPayController:174`,
`OzowController:234` and `PaystackPaymentController:94`). Changing the value those three store
changes which historical row a returning callback matches, which is *"تغيير في نتيجة Payment
Callback"* — exactly what the decision's standing limit reserves for independent review. They are
**left out and recorded**, as that limit instructs. `PaystackPaymentController:499` shows the shape
any future slice there must copy: `paystackPaymentTypeSpellings()` enumerates the canonical literal
**plus** `trans('Paystack')` for every shipped locale, so the replay guard still matches rows
written before the change — prepending the canonical spelling rather than substituting it.

**Two writers stay as they are for recorded reasons, not by omission.**
`packages/workdo/LotteryPrizes/.../LotteryResultController:135` stores `'Lottery Product'` — a
stable literal, so not part of the locale defect, but also not a payment method, and the module is
vendored and must not be edited. `PaymentController:2842` and its sibling
`PaymentService:2773` derive their stored value from the same expression that three gateway
verification gates branch on in the same function and that the Paymera callback identifies its own
order with; canonicalising the shared source would touch all four at once. Both are listed for
independent review.

**So what was built is the display half only** — one read-side resolver, `App\Payments\PaymentTypeLabel`,
and three callers. It changes no writer, no stored value, no validation rule, no vocabulary and no
API contract. It repairs a defect that is live **in English, with no locale involved**: measured
over the 71-value vocabulary, 26 values rendered a bare dash in the order list and 52 rendered an
empty cell on the customer detail page, `POS` among them. It is forward-only by construction,
because a resolver that only reads cannot backfill, re-charge or reinterpret anything — an
unattributable value such as `'没有'` resolves to NULL and renders an absence, which is what *"أبقِ
القيم الملتبسة على حالها"* asks for.

**The SenaGePay / SenanGpay question — INVESTIGATED 2026-10-29, and the answer is BLOCKED rather
than undecided.**

The provider is real and its name is not in doubt: senangPay, a Malaysian gateway, and `Senang*`
outnumbers `Senage*` 174 to 20 across first-party code. What is in doubt is something narrower and
more consequential: **which string is the technical identifier**, and there are TWO live answers,
each self-consistent on its own surface.

| site | spelling | role |
|---|---|---|
| `PaymentSettingController::getPaymentGateways():758` | `Senangpay` | the id the ADMIN picker posts, and the settings key `is_Senangpay_enabled` derives from it |
| `Setting::paymentList()` `['name']` (`Setting.php:818`) | **`SenagePay`** | the value the STOREFRONT checkout posts — `payment_list.blade.php:12` renders `value="{{ $payment_data->name }}"` |
| `PaymentService.php:404` | `case 'SenagePay':` | the storefront's own dispatch |
| `Utility.php:1842` | `if ($payment == 'SenagePay')` | the storefront's second consumer |
| `SenangPayController:166` | `__('SenangPay')` | what is STORED in `plan_orders.payment_type` — translated, and replay-keyed |
| `routes/customer.php:346` | `plan.pay.with.senagapay` | a third spelling, in a route NAME |

**So `SenagePay` is not a typo in the sense that matters — it is the storefront's working
identifier, posted and branched on consistently**, and `'Senangpay'` appears in **no** storefront
branch at all. The two surfaces never meet. Changing either one to match the other would alter a
payment identifier on a live commercial path: renaming `paymentList()`'s `name` would stop the
storefront's two `SenagePay` branches matching, and renaming the registry id would break
`is_Senangpay_enabled`.

**Nothing is changed, per the owner's condition** (*"لا تغيّر Payment ID أو Callback Route أو
بيانات دفع تاريخية اعتماداً على اختلاف إملائي فقط"*). The callback route, the registry id, the
settings keys and every historical row stay exactly as they are.

**The safe accommodation is already shipped and needs no identifier change:**
`App\Payments\PaymentTypeLabel` folds case and resolves all six observed spellings, so every
stored row renders a label whichever surface wrote it. `SenagePay` → "SenagePay",
`Senangpay`/`SenangPay`/`senangpay` → "SenangPay". That is read-side only.

**What a future unification would need, and why it is not a Phase 02 slice:** evidence of which
identifier the merchant-facing settings and any live integration actually require, a migration
decision for historical `orders`/`plan_orders` rows carrying the other spelling, and a plan for
`SenangPayController:166`, which is one of the three writers keyed by
`GuardsPlanActivation::existingPlanOrderForProviderTxn` — so changing what it stores changes which
historical row a returning callback matches. **That is a payment-callback result, which the
standing limit reserves for independent review.** Recorded as BLOCKED.

