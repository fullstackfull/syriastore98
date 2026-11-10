# F153 — `plan_orders.payment_type` is written translated and compared untranslated

**Found 2026-11-10, during independent review of P2.5-SET-03. Pre-existing; not introduced by that
change, and not fixed by it.** Recorded for the owner rather than repaired, because the controls it
breaks are **Super Admin** surfaces, which the approved six-phase plan puts in **Phase 06**.

---

## 1. The defect

`plan_orders.payment_type` is written through the translator and read as an English literal. When
the two locales disagree, the comparison fails and a control silently does not render.

### Verified by reading both ends

| written | compared |
|---|---|
| `app/Http/Controllers/BankTransferController.php:103` — `$order->payment_type = __('Bank Transfer');` | `resources/views/plans/action.blade.php:2` — `@if ($order->payment_status == 'Pending' && $order->payment_type == 'Bank Transfer')` |
| the same value | `resources/views/plans/receipt.blade.php:9` — `@elseif ($order->payment_type == 'Bank Transfer')` |
| `app/Http/Controllers/StoreController.php` / `PlanRequestController.php:103` — `__('Manually')` | `resources/views/plans/receipt.blade.php:7` — `@elseif($order->payment_type == 'Manually')` |

`resources/lang/ar.json` translates both, **correctly** — `Bank Transfer` → `تحويل مصرفي`,
`Manually` → `يدوياً`. These are ordinary descriptive terms, not brand names, so translating them
is right and removing the keys would be the wrong fix.

### What a merchant and operator actually see

A merchant submits a bank transfer with the UI in Arabic. The row stores `تحويل مصرفي`.
`plans/action.blade.php:2` compares it against `'Bank Transfer'`, the comparison is false, and
**the super admin's Payment Status (approve) control never renders for that order.** The operator
cannot approve the transfer from the list. The same row falls through `receipt.blade.php` to the
`-` at the end, so its receipt link is missing too.

An order paid in English is unaffected. The defect is therefore invisible to anyone testing in
English, which is why it has survived.

---

## 2. What P2.5-SET-03 did and did not change

That change removed 32 mistranslated **brand names** from `ar.json`, including the uppercase
`STRIPE` key (`الشريط`, "the ribbon") which `StripePaymentController` stores and
`plans/receipt.blade.php:2` compares against `'STRIPE'`. **Future Stripe plan orders now match**,
because the stored value is the canonical spelling again.

It did **not** touch `Bank Transfer` or `Manually`, and should not have: those are correct
translations. So the shape of the defect survives for them.

It also exposed a second consequence of the same root cause, which **was** repaired, because it is
a live money control rather than a display one:
`GuardsPlanActivation::planProviderTypeSpellings()` built its plan-activation **replay** lookup by
globbing `resources/lang/*.json`, so removing brand keys silently shrank the set of spellings it
searched. `GuardsPlanActivation::HISTORICAL_SPELLINGS` now pins every spelling this application has
ever written, and `tests/Unit/Payments/HistoricalPaymentSpellingsTest.php` ratchets it. See
`evidence/web-settings-tabs-2026-11-09/README.md` §3.

---

## 3. Why it is not fixed here

1. **The broken controls are Super Admin.** `plans/action.blade.php` and `plans/receipt.blade.php`
   render in the platform operator's plan-order list. Phase 2.5 is Merchant Experience; operator
   UX is Phase 06 by the approved plan.
2. **The right fix is a data-shape change, not a string change.** `payment_type` should hold a
   canonical identifier and be *rendered* through `App\Payments\PaymentTypeLabel`, exactly as
   `orders.payment_type` already is. Changing what the writers store affects rows a plan's billing
   state depends on, which is squarely in the "material changes to payments" class the owner
   reserved for their own decision.
3. **Existing rows would still be wrong.** Any repair that only changes the writers leaves every
   row written in Arabic unmatched. A complete fix needs either a reconciling read (the
   `HISTORICAL_SPELLINGS` approach, generalised) or a data migration — and a migration against live
   plan billing data is explicitly an owner decision.

---

## 4. Recommended fix, when it is authorised

Mirror what `orders` already does, in this order, without a migration:

1. Make the two blades resolve through `PaymentTypeLabel` (or a small canonicalising accessor on
   `PlanOrder`) instead of comparing raw strings, so a row written in **any** locale matches.
2. Extend that resolver with the translated phrases `Bank Transfer` and `Manually` have carried, in
   the same append-only, measured form as `GuardsPlanActivation::HISTORICAL_SPELLINGS`.
3. Change the writers to store the canonical identifier, so new rows need no reconciliation.
4. Only then consider a migration for historical rows — a separate, owner-approved step.

Step 1 alone removes the user-visible symptom and touches no stored data.

---

## 5. Scope note

`PaymentTypeLabel`'s own docblock reports that "the database stores canonical identifiers". That is
**true for `orders`** — every `new Order()` arm takes `payment_type` from the checkout slug — and
**not true for `plan_orders`**, where every `payment_type = __(...)` site in `app/` writes a
translated string. The census is not wrong about the table it names; the two tables simply differ,
and this document is the record of that difference.
