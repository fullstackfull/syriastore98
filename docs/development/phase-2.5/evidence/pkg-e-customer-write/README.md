# P2.5-PKG-E — the customer write path

**Captured 2026-11-07.** `GET /customer/create`, four locale/theme combinations, before and after.
Three repairs to `CustomerController::store()`, five corrections to the form, and **one question
escalated to the owner rather than answered in code**.

## The repairs

### E-1 — archive, then add that person again: a dead end a merchant reaches in two clicks

`destroy()` is `$customer->delete()`; `Customer` uses `SoftDeletes`. So archiving sets `deleted_at`
and the merchant is told *"Customer archived successfully."*

`store()` validated `'email' => '…|unique:customers,email'`, and Laravel's `unique` rule queries the
table with **no `deleted_at` predicate** — so it counted the archived row. The address was reserved
for ever, and the create form answered *"The email has already been taken"* for a person the
merchant can no longer see anywhere in their own interface.

**And there is no way back.** No restore route, no archived-customer view, and no `withTrashed()`
anywhere in the controller. The merchant archived someone, wanted them back, and the product
offered neither re-create nor restore.

Repaired with `Rule::unique('customers', 'email')->whereNull('deleted_at')`. **A live duplicate in
the same store is still refused**, asserted by its own test — removing the rule instead of narrowing
it is the obvious wrong fix here.

### E-2 — the raw exception message was printed on the merchant's screen

`->with('error', 'Error: ' . $e->getMessage())`. A database exception's message carries SQL, table
and column names and sometimes values, and `'Error: '` was untranslated so an Arabic merchant read
an English prefix in front of it. Now a translated generic sentence; the exception class, message,
file and line are still logged — the class is newly added.

### E-3 — validation was reported twice, the second time in English only

`$request->validate()` throws, and Laravel's handler already redirects back with the errors and the
old input. The handler **caught that exception** and redid the redirect, adding
`'Validation failed: ' . implode(', ', $errorMessages)` — an untranslated English sentence listing
every message, on top of the per-field messages. One bad field, two complaints, one of them in a
language the merchant may not read.

**And the first attempt at this fix was wrong, which is recorded because the tests caught it.**
Deleting the `catch (ValidationException $e)` block while leaving `validate()` inside the `try` made
things worse: **`ValidationException` extends `Exception`**, so the generic catch swallowed it and
replaced every field error with the generic flash. `validate()` now runs **outside** the `try`.

## The form — five corrections, each one measured, not assumed

| # | correction | why it is not cosmetic |
|---|---|---|
| 1 | `dir="ltr"` on the e-mail and mobile inputs | in the Arabic interface the document is RTL, and a Latin address or phone number typed into an RTL input **reorders as it is typed**. The customer grid already guards its e-mail this way (`grid.blade.php:159`); the form a merchant *types* it into did not. |
| 2 | `autocomplete` on all five text fields | six standard tokens, none declared. `new-password` is the one that matters most: without it a password manager offers the **merchant's own** saved credential for the customer being created. |
| 3 | `minlength="8"` + a bound `<x-ss.field-help>` | the server requires `min:8` and the form said nothing, so a merchant learned the rule by being refused. Bound through `aria-describedby`, which `ContextualFieldHelpTest` enforces in both directions. |
| 4 | a real `<button type="submit">` | `Form::submit()` renders `<input type="submit">`, which cannot contain a child — so the primary action on the page was the only button in this interface with no icon. |
| 5 | five Arabic HTML comments removed | `<!-- الاسم الأول -->` and its siblings were shipped to every browser on every render. Blade comments do not survive compilation; HTML comments do. |

And the composition: **three `<fieldset>` groups** — identity, contact, account access — because
nothing previously told the merchant that the password was an *account* being created rather than
another field like the phone number.

## Two defects of my own, measured out before this evidence was accepted

**Both are recorded rather than quietly fixed, because each is a failure mode this phase keeps
hitting.**

1. **The three new `<legend>` headings were invisible in dark mode.** They carried the host's
   `.form-label`, and `census-low-contrast-text.cjs` measured **1.1:1** —
   `rgb(41,50,64)` on `rgb(41,42,51)`, three failing nodes. The host's class is not theme-aware on a
   `<legend>`. Replaced with `.ss-label` plus a new `.ss-form-legend` whose colour comes from
   `--ss-fg`; **re-measured: 0 failing nodes in both themes.**

2. **The submit button was outside the form.** The first draft opened `Form::open()` inside
   `.card-body` and put the button in `.card-footer`, so the `<form>` was opened inside one `<div>`
   and closed after another had already closed. A test asserting the button *exists* passes against
   that, and so does a test that matches the form with a **regex** — regexes read source order, and
   the button *is* textually between the tags. Only a **DOM-parsing** assertion sees it: parsed, the
   button was **not a descendant of the form**, so pressing it would have submitted nothing.
   `test_every_control_is_a_descendant_of_the_form_in_the_parsed_document` now walks ancestors in
   the parsed tree, and **bites**: restoring the mis-nested markup fails it.

## What is NOT changed — escalated to the owner

`unique:customers,email` is still **global**: no `store_id` predicate, so a merchant still cannot
create a customer whose **live** address belongs to another store.

That is a defect — the storefront's own registration is **per-store**
(`CustomerLoginController:104`, `Customer::where('email', …)->where('store_id', $store->id)`), so
two stores having the same shopper is a state the product already produces, and the admin form is
the only place that refuses it. It is also an existence oracle across tenants.

**It is left alone on purpose.** `StorefrontRegistrationTakeoverTest`'s header records that on the
API register endpoint the same `unique` rule is what keeps the e-mail path to create-only — there it
is **protective**. Scoping uniqueness per store is therefore a business-policy decision with
authorization implications, which the owner's directive reserves to the owner. The current
behaviour is **pinned by a test** so it cannot drift while the decision is open, and that test says
in its own message that it is what should be rewritten when the decision lands.

## Evidence and verification

```
before/  _customer_create__<combo>.png   render-matrix.cjs, the pre-change view
after/   _customer_create__<combo>.png   render-matrix.cjs, the current view
```

`<combo>` ∈ `en_ltr_light`, `en_ltr_dark`, `ar_rtl_light`, `ar_rtl_dark`.

- `render-matrix.cjs`: **4 rows, 0 not clean**, both before and after. It asserts its own premises —
  the observed direction matches the requested one and `data-ss-theme` matches the requested theme —
  and reports `PREMISE-FAIL` rather than a reading when they do not. The Arabic title renders as
  *إنشاء عميل*.
- `census-low-contrast-text.cjs --paths /customer/create --themes light,dark`: **0 failing text
  nodes** after the legend fix (3 before).
- `CustomerCreateFormTest`: **13 tests, 49 assertions**, from a bite of **6 failed / 6 passed**
  against the unmodified handler and form. The six that passed were the behaviours that already
  held — the live-duplicate refusal, the authorization checks, `store_id` coming from
  `getCurrentStore()` and a posted `store_id` being ignored — asserted so the repair could not
  widen them.
- `ContextualFieldHelpTest` **7 passed**, `Unit` **1429 passed**, `Contract` **27 passed**.

## What this does not claim

- `REDESIGNED AND VERIFIED`, not `PREMIUM VERIFIED`. The form was regrouped and corrected; it is not
  presented as the finished premium article.
- Nothing here is said about `customer.edit` / `customer.update`, which **do not exist**:
  P2.5-PKG-C (F-C-04) excluded them from the resource route because the controller has neither
  method and `GET /customer/1/edit` answered HTTP 500.
- 229 surfaces remain `PENDING`, and all 75 Flutter surfaces still have zero rendering evidence.
