# UPSTREAM REPORT — PREPARED, NOT SUBMITTED

**Status:** drafted for owner review. **Not sent.** No vendor file has been modified.
**Internal references:** issue #69, task `P1-SEC-07`, write-up
`docs/security/P01-EMAIL-TEMPLATE-TENANCY-PROPOSAL.md`.

> **Owner approval is required before this leaves the building.** It names file paths, line numbers
> and two defects in a commercial vendor's code. It contains no credentials, no customer data, no
> store names and no schema beyond the two column names the defects turn on — but it is still an
> outward-facing disclosure and the decision to send it is the owner's.

---

## Reporting against

| | |
|---|---|
| Platform | eCommerceGo SaaS addons, Laravel 11.47.0, PHP 8.3 |
| Modules | `workdo/lottery-prizes`, `workdo/partial-payments`, `workdo/product-catelog`, `workdo/product-enquiry`, `workdo/review-reminder`, `workdo/spin-to-win-reward`, `workdo/pre-order` |
| Version pinning | none of the seven `composer.json` files declares a `version` key, so the report pins by **file path plus line number plus the quoted line**, which is reproducible against any checkout |

Line numbers were verified with `grep -n` against the installed tree at the time of writing. Each
quoted line is given in full so the report survives a shift.

---

## Defect 1 — the email-template resolve is not tenant-scoped

### The line

Seven copies, identical in shape:

| module | file | line |
|---|---|---|
| LotteryPrizes | `src/app/Http/Controllers/LotteryPrizesController.php` | 715 |
| PartialPayments | `src/app/Http/Controllers/PartialPaymentsController.php` | 307 |
| ProductCatelog | `src/app/Http/Controllers/ProductCatelogController.php` | 296 |
| ProductEnquiry | `src/app/Http/Controllers/ProductEnquiryController.php` | 276 |
| ReviewReminder | `src/app/Http/Controllers/ReviewReminderController.php` | 214 |
| SpinToWinReward | `src/app/Http/Controllers/SpinToWinRewardController.php` | 415 |
| PreOrder | `src/app/Models/PreOrderHistory.php` | 95 |

```php
$template = EmailTemplate::where('name', 'LIKE', $emailTemplate)->first();
```

### Why it is a defect

`App\Models\EmailTemplate` carries no global scope, is absent from `config/tenancy.php`'s
`scoped_models`, and `email_templates` has **no `store_id` column** — its only tenant key is
`created_by`. The resolve above applies no predicate at all, and `->first()` is unordered.

**And the names these seven resolve are per-tenant.** Each module's own settings screen creates its
templates with the tenant's id:

```php
// e.g. LotteryPrizesController.php:191, and the same shape at
// PartialPaymentsController.php:145, ProductEnquiryController.php:413,
// ReviewReminderController.php:51, SpinToWinRewardController.php:177,
// PreOrderController.php:386
$emailTemp = EmailTemplate::where('name', $eTemp)->where('created_by', Auth::user()->id)->count();
if ($emailTemp == 0) {
    EmailTemplate::create(['name' => $eTemp, 'from' => env('APP_NAME'), 'created_by' => Auth::user()->id]);
}
```

So `Lottery Win`, `Pre Order Created`, `Product Enquiry`, `Lucky Winner`, `Review Reminder`,
`Payment Reminder` and `Product Catelog` each get **one row per tenant**, and the unscoped resolve
returns **the lowest-id tenant's row for every tenant**.

### Measured consequence

Two tenants each create their own `Lottery Win` through the module settings screen and each activate
it:

```
email_templates:  id=7  Lottery Win  created_by=2      <- tenant A
                  id=9  Lottery Win  created_by=3      <- tenant B
unscoped resolve of 'Lottery Win' -> id=7 (tenant A's), for BOTH callers
  tenant A: activation row for the resolved template is_active=1, body = BODY-OF-TENANT-A
  tenant B: activation row for the resolved template is_active=0, body = BODY-OF-TENANT-A
```

- **The outage is unconditional.** Tenant B's mail is silently skipped, because the
  `user_email_templates` row that would have to be active belongs to tenant A's template — and every
  module settings screen lists only `where('created_by', Auth::user()->id)`, so **no screen in the
  product can reach it**. The merchant has no way to fix this themselves.
- **The content exposure is conditional.** For tenant B's customers to actually *receive* tenant A's
  body, `(A's template, B)` would have to be `is_active = 1`, which no screen can produce but a seed
  or a manual row can.

### Suggested fix

The owner is already a parameter of all seven functions and is already used on the very next line
(e.g. `LotteryPrizesController.php:721`, `UserEmailTemplate::where(... 'user_id', $user->id)`), so
no new value has to be derived:

```php
- $template = EmailTemplate::where('name', 'LIKE', $emailTemplate)->first();
+ $template = EmailTemplate::where('name', 'LIKE', $emailTemplate)
+     ->where('created_by', $user->id)        // $owner in the ProductCatelog/ReviewReminder copies
+     ->first();
```

### ⚠️ A caveat that matters, so the same change is not applied platform-wide

**Do not apply this to `app/Models/Utility.php:2341`**, the host's own copy. Its six template names
(`Order Created`, `Status Change`, `Order Created For Owner`, `Stock Status`, `Abandon Cart`,
`Abandon Wishlist`) are seeded by `Utility::defaultEmail()` with
`firstOrCreate(['name' => $eTemp], …)` — **matched on `name` alone** — so exactly one row exists per
name platform-wide, owned by the platform operator, and the unscoped resolve there is **correct**.
Measured: adding the owner predicate to that line returns null for every merchant and stops **all**
order-confirmation mail, across 54 call sites.

Two populations, one table, opposite answers. The seven module copies need the predicate; the host
copy must not have it.

---

## Defect 2 — the storefront slug is taken from the template's owner, not from the store

### The line

Six copies (the PreOrder copy does **not** carry this line):

| module | file | line |
|---|---|---|
| PartialPayments | `src/app/Http/Controllers/PartialPaymentsController.php` | 313 |
| ProductCatelog | `src/app/Http/Controllers/ProductCatelogController.php` | 303 |
| SpinToWinReward | `src/app/Http/Controllers/SpinToWinRewardController.php` | 421 |
| ReviewReminder | `src/app/Http/Controllers/ReviewReminderController.php` | 221 |
| ProductEnquiry | `src/app/Http/Controllers/ProductEnquiryController.php` | 282 |
| LotteryPrizes | `src/app/Http/Controllers/LotteryPrizesController.php` | 722 |

```php
$slug = Store::where('created_by', $template->created_by)->select('slug')->first()->slug;
```

### Why it is a defect

`$template->created_by` is the **template's** owner, which after Defect 1 is whichever tenant
registered first — and for the host-seeded names is the platform operator. It is not the tenant whose
mail is being sent. The line runs **unconditionally, before** the `is_active` check, so it executes
on every call.

### Measured consequence

Driving `LotteryPrizesController::sendOwnerEmailTemplate` with a real merchant and a real activated
template:

- **The template's owner has no store** (the normal case, since the platform operator typically does
  not own a storefront) → `ErrorException: Attempt to read property "slug" on null` at
  `LotteryPrizesController.php:722`. `ErrorException extends Exception`, so every caller's
  `catch (\Exception $e)` swallows it and **the mail silently never sends, for every tenant** — lottery
  win/fail/reminder, product enquiry, spin-to-win, review reminder, product catalogue and
  partial-payment reminder alike.
- **The template's owner does have a store** → the slug resolves to *that* store, so **every tenant's
  customer-facing email links point at the wrong storefront**.

### Suggested fix

`$store` is already a parameter of all six functions:

```php
- $slug = Store::where('created_by', $template->created_by)->select('slug')->first()->slug;
+ $slug = $store->slug;
```

One token per file. A null-guard would also be welcome, since the current line dereferences a
`->first()` that can be null.

---

## Two further robustness notes, offered rather than claimed as defects

1. **`$is_actives->is_active` is read without a null guard** — e.g.
   `PartialPaymentsController.php:317`, `ProductCatelogController.php:306`,
   `SpinToWinRewardController.php:425`, `ReviewReminderController.php:225`,
   `ProductEnquiryController.php:286`, and the host's `app/Models/Utility.php:2350`. A user with no
   `user_email_templates` row makes that a null property read, which Laravel's error handler
   (`error_reporting()` is `-1`) turns into a thrown `ErrorException`. `LotteryPrizesController.php:725`
   already uses the truthy form `if ($is_actives)`, which is the shape the others want.

2. **`Utility::userDefaultData()` does `EmailTemplate::all()`** and creates a `user_email_templates`
   row for a new user against **every tenant's** templates. That is what turns Defect 1 into a silent
   skip rather than a thrown error, and it grows as O(users × templates) across the whole platform.
   Noted as context for Defect 1 rather than as a defect in its own right.

---

## What we have done on our side, so there is no duplicated work

- **No vendor file is modified.** Our CI enforces that `packages/workdo/` is byte-identical to
  upstream, so none of the above is patched locally.
- We added a **host-side null guard** at `app/Models/Utility.php:2350` only (our own file).
- We recorded a **constraint**: the platform operator must not own a store, because the absence of one
  is what currently makes Defect 2 a silent outage rather than a wrong-slug exposure. That is
  accidental containment and we have written it down rather than relying on it silently.
- We established that **no shared host-side mechanism closes this**. A global scope on
  `EmailTemplate` is wrong for one of the two populations whichever way it is written; a `created_by`
  accessor would change what 51 other read sites see; the static calls from listeners, jobs and
  console commands cannot be intercepted at all; and half the twelve call sites are console commands
  and queued jobs with no resolvable tenant. Four of the twelve are container-resolved controller
  actions and *could* be intercepted by binding a host subclass, which we have documented as
  available and not recommended.

Evidence for every measurement above: `tests/Feature/Security/EmailTemplateOwnerResolutionTest.php`
(13 tests, 66 assertions) in our tree. We are happy to share the reproduction harness if that would
help.
