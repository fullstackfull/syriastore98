# P2-03-4 — variant persistence, and three decision points

**Date:** 2026-10-26
**Slice:** P2-03-4 — product media and variant experience
**Status:** the slice's verified items are DONE; three items are **BLOCKED on an owner decision**
and named below. Nothing in this document has been changed in the code.

The owner's condition for closing P2-03-4 was explicit:

> لكن أكمل بقية P2-03-4 الموجودة ضمن Phase 02، ولا تغلقها قبل التحقق من رفع الصور وحذفها
> والصورة الرئيسية وتجربة المتغيرات وأخطاء التحقق.

Five items. This document reports each, with how it was measured.

---

## 1. Verified items

| Item | State | Evidence |
|---|---|---|
| رفع الصور — gallery upload | **DONE** | `tools/check-gallery-upload-feedback-live.cjs` 17/17; a refused file now reports (F75) instead of vanishing |
| حذف الصور — deletion | **DONE** | `tools/check-media-variant-lifecycle-live.cjs`, delete cases |
| الصورة الرئيسية — primary/cover image | **DONE** | same harness; cover render verified after the upload-root correction |
| أخطاء التحقق — validation errors | **DONE** | `tools/probe-validation-errors.cjs` **93 pass / 0 fail**, seven cases × two languages |
| تجربة المتغيرات — the variant experience | **BLOCKED** | §2 below |

### Validation errors — what was found and fixed

Driving seven invalid inputs through the real form in both languages found **two** real defects and
refuted one suspicion.

**Refuted first, because it nearly became a false finding.** A negative `price` and a
`cost_price` above `price` were both accepted on product 1. That is **not** a missing rule:
`update()` builds two rule sets, and the `variant_product == 1` branch omits the price rules on
purpose, because a variant product's prices live on `product_variants` and the parent `price`
column is only written inside `if ($request->variant_product == 0)`
(`ProductController.php:1643`). The probe was measuring the absence of a rule that is absent by
design. Re-driven against a simple product, every price rule fires. **A probe that reaches the
wrong branch reports a defect in the code instead of in itself.**

**Defect 1 — Arabic had no `validation.php`, so every built-in rule message was English.**
Measured: `lang=ar`, empty name → `"The name field is required."` at the field, while the five
hand-written custom messages rendered in Arabic. The difference is the source: custom messages go
through `__()` and resolve from `ar.json`; Laravel's built-in rule messages resolve from
`lang/<locale>/validation.php`, and `resources/lang/ar/` held only `installer_messages.php` and
`myfatoorah.php`. Arabic — the platform's **primary** language — was the one locale without it,
while `he`, `zh` and `pt-br` all shipped one.

Fixed additively: `resources/lang/ar/validation.php` (106 messages), plus `auth.php`,
`passwords.php` and `pagination.php`, which were missing for the same reason.
`ArabicValidationMessagesTest` (14 tests) compares the files key-for-key **and
placeholder-set-for-placeholder-set**, because a dropped `:min` renders a message with the value
missing and the live probe's "no literal `:placeholder` leaked" assertion checks the opposite
failure. Revert-checked three ways: file removed, key removed, placeholder dropped — each fails.

**Defect 2 — `:attribute` rendered the raw column name.** `"الحقل name مطلوب."` — an Arabic
sentence with an English column name in it. Fixed with the Validator's **fourth** argument at both
call sites (`ssProductFieldNames()`), not a global `validation.attributes` map: that map would
rename the field in every message on every form in the platform. Now reads
`"الحقل الاسم مطلوب."` / `"The Name field is required."`, and
`"يجب أن تكون قيمة السعر 0 على الأقل."` / `"The Price must be at least 0."`.

**A third finding came out of the same sweep.** Scanning every `__()` string on Phase 02's
merchant surfaces against `ar.json` found **16 untranslated**, and **four were added by earlier
Phase 02 slices of this project** — `'You are not allowed to do this.'`,
`'The delivery boy could not be assigned.'`, `'Your session has expired. Please reload the page.'`,
`'Something went wrong. Please try again.'`. Work done under a bilingual rule had shipped
English-only four times before anything measured it. 15 are now translated;
`ArabicUiCoverageTest` keeps the set closed. The sixteenth is **decision point C** below.

---

## 2. تجربة المتغيرات — the variant experience

### F82 — a variant price or stock edit from the edit page is silently discarded

`update()` has **two** variant rebuild paths, each gated on request keys:

| | gate | per-variant fields read | delete |
|---|---|---|---|
| A | `ProductController.php:1828` `$request->has('choice_no')` | `price_<v>`, `stock_<v>`, `sku_<v>` | `:1874` |
| B | `ProductController.php:1910` `attribute_no[]` + `for_variation_<no> == 1` + `options_datas` | `product_variation_price_<v>`, `product_stock_<v>`, … | `:2021` |

The per-variant fields are read **only** inside those paths. The edit page posts neither gate's
keys on load, so:

```
POST /product/1          118 named parts, including
    product_variation_price_M/L-Indigo = "77.25"
    attribute_no[], visible_attribute_1 = "0", visible_attribute_2 = "0"
    (no for_variation_*, no options_datas, no choice_no)

response  {"flag":"success","msg":"تحديث المنتج بنجاح"}
database  variation_price = NULL        ← unchanged
```

The merchant is told the save succeeded. Nothing was written. `for_variation_*` and
`options_datas` **do** exist in `edit.blade.php` — they live in the attribute block that is
populated when the merchant touches the attribute picker, not on page load.

### F83 — the path that does persist rebuilds, and the rebuild hard-deletes

Driving the only persisting path (touch the attribute picker → tick "use for variation" →
re-select the terms → edit a price → save):

```
BEFORE   8 variants
POSTED   for_variation_* present, options_datas present, product_variation_price_S = 77.25
AFTER    4 variants      S price=77.25   M price=0   L price=0   M/L price=0
         4 variants deleted outright; three prices overwritten with 0
```

Two mechanisms, both in the code:

- `ProductVariant::where('product_id', …)->whereNotIn('variant', $com)->delete()` at `:2021`
  (and `:1874`) deletes every variant **absent from the posted option set**. A raw `DB::table`
  read cannot see the rows either, so it is a hard delete.
- every field is read as `!empty($request[…]) ? … : 0`, so a **covered** variant whose price
  field is absent or blank is written as `0` rather than left alone.

### Why this was not fixed, and what the decision is

The obvious fix — have the edit page post the gate's keys for the variants it rendered — routes
**every ordinary product save** through a path that deletes variant rows and zeroes prices. That
is a change to what a save does to pricing and stock, which the owner's standing limits reserve:

> تغيير سلوك الدفع أو التسعير أو المخزون أو الطلبات

and the instruction given for E13 applies here in the same spirit:

> إذا لم يمكن تنفيذ المسار بأمان دون قرار تجاري إضافي غير محسوم، وثّق نقطة القرار تحديداً
> وواصل باقي المهام. لا تخترع سلوكاً مالياً من عندك.

So the behaviour is **characterised, not changed**: `tests/Feature/Merchant/ProductVariantPersistenceTest.php`
(4 tests) asserts the discard, the hard delete, the zeroing, and — as a static ratchet — that
there are exactly two `whereNotIn` rebuild deletes and one unconditional one. Mutation-checked:
adding a third delete and changing a predicate each fail it. The three driven tests are
differential by construction — the same payload with and without the gate keys produces opposite
outcomes — so none of them can pass vacuously.

#### Decision point A — how a variant edit should persist

Two safe designs; they differ in a commercial way, so the owner chooses.

**A1 — edit in place (recommended).** A variant panel's price/stock edit writes **only** the rows
the merchant actually edited: no rebuild, no delete, no zeroing. Add a dedicated per-variant
update that the panel posts, leaving both rebuild paths exactly as they are for the case they
exist for (the merchant changing the attribute configuration).
*Smaller blast radius: nothing a merchant does on the edit page can delete a variant row.*
*Cost: a new write path, so it needs its own ownership guard and its own tests.*

**A2 — make the page post the full existing configuration.** Render hidden `for_variation_<no>`
and `options_datas` reflecting the product's **current** attribute set, so the rebuild regenerates
exactly what is already there plus the edits.
*Smaller diff, reuses the existing engine.*
*Risk: correctness now depends on those hidden fields always reproducing the stored set exactly.
A product whose attribute terms were deleted after its variants were created would have its
uncovered variants deleted by an ordinary save. That is a live data-loss path, not a theoretical
one.*

**Not proposed:** leaving it as is. A merchant who edits a price, sees "تحديث المنتج بنجاح" and
sells at the old price is a commercial defect regardless of which fix is chosen.

### F84 — an unguarded request read, low severity, recorded for completeness

`$input['visible_attribute_' . $no]` at `:1668` and `:1762` is an unguarded array access: a POST
carrying `attribute_no` without `visible_attribute_<no>` is a 500. A browser always sends it (a
hidden `0` partner beside the checkbox, `edit.blade.php:592`), so it is **not reachable from the
UI** — the same shape as `trending` in `ProductUpdateNullGuardsTest`. Not fixed in this slice
because the fix belongs with whichever of A1/A2 is chosen; recorded so it is not rediscovered.

---

## 3. Decision point B — F78, gallery image reorder

Already recorded as an explicit, scope-limited **DEFERRED to Phase 03** decision (D-07 in
`PHASE-02-DECISION-REGISTER.md`), on the owner's instruction. Both prohibitions were verified in
code: no `sort_order`/`position` column was created, and there are **0** gallery `orderBy` sites,
so no web-only ordering exists that Flutter would disagree with.

## 4. Decision point C — a foreign brand's truncated refund policy on every printed receipt

`resources/views/order/receipt.blade.php:262` hard-codes:

> "Merchandise may not be returned for refund at any time. Power bases cannot be exchanged and
> are non-refundable. For purchases made in a **Milo Showroom**, no refunds are available and
> sales c"

WorkDo demo boilerplate. It names an unrelated brand, and it is **truncated mid-sentence**. It
prints on every customer receipt.

It is the one string exempted from `ArabicUiCoverageTest`, because every available action states
a refund rule:

- translating it publishes a refund policy that is not Store Syria's, in Arabic, on every receipt;
- rewriting it means writing Store Syria's actual return policy;
- deleting the paragraph means the receipt carries no return policy at all.

The owner's instruction is directly on point:

> ولا تغيّر قواعد التسوية أو رد الأموال دون ضرورة مثبتة ومراجعة مستقلة

**Owner supplies** either the real Arabic/English return-policy text, or authorisation to remove
the paragraph. Until then the exemption stands and is asserted to be the only one.

---

## 5. What changed in the code

| File | Change |
|---|---|
| `resources/lang/ar/validation.php` | **new** — 106 messages, placeholders preserved |
| `resources/lang/ar/auth.php`, `passwords.php`, `pagination.php` | **new** — the same gap |
| `resources/lang/ar.json` | +15 keys; 4 of them strings earlier Phase 02 slices shipped untranslated |
| `app/Http/Controllers/ProductController.php` | `ssProductFieldNames()` + passed as the validators' 4th argument at both call sites. **No change to any pricing, stock or variant write.** |
| `tests/Unit/Merchant/ArabicValidationMessagesTest.php` | **new**, 14 tests |
| `tests/Unit/Merchant/ArabicUiCoverageTest.php` | **new**, 3 tests |
| `tests/Feature/Merchant/ProductVariantPersistenceTest.php` | **new**, 4 tests — characterisation, no fix |

## 6. Ledger effect

P2-03-4 moves to **PARTIAL**, not DONE: four of the owner's five items are verified, the fifth
(تجربة المتغيرات) is BLOCKED on decision point A. It is not closed, and no requirement has been
dropped or narrowed — which is the condition the owner set:
"ولا تختصر أي متطلب دون قرار موثق."
