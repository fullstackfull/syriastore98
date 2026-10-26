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

**Status:** IN PROGRESS. Design inputs are being gathered first, as the owner required.

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

## D-10 — the printed receipt's return policy: AWAITING OWNER TEXT (F85)

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

**Needed from the owner:** the real return-policy text in Arabic and English, **or** authorisation
to remove the paragraph.

Until then it is the single declared exemption in `ArabicUiCoverageTest`, which asserts the
exemption list holds exactly one entry **and** that the string is still present in the receipt —
so the exemption is deleted along with the string, not left behind as dead permission.

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
