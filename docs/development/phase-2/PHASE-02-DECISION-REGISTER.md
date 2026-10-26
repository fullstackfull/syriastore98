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

## D-09 — variant price and stock persistence: AWAITING OWNER DECISION (F82, F83, F84)

**Raised:** 2026-10-26, by driving the product edit page. **Status: open — the owner chooses.**
**Nothing in the code has been changed.** Full evidence:
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

**Raised:** 2026-10-26 by the E14 review. **Status: open.** Nothing changed.
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

## D-16 — D-09: design A1 approved (owner, 2026-10-26)

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

## D-17 — D-12: Paystack treated as an isolated security fix (owner, 2026-10-26)

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

## D-18 — D-13: inventory security on the four named paths (owner, 2026-10-26)

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
