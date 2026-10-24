# P01 — Email-template tenancy: two populations, opposite answers, and a second defect one line away

**Issue:** #69 · **Task:** P1-INV-23 · **Status:** investigated by execution. **No fix applied —
the fix this issue implies would have caused a platform-wide mail outage** (§3). The real defect is
in vendored code with no sound host-side mechanism (§5); recommendation in §6.

Owner decision of this round: *"For #69, investigate a shared host-side solution for tenant-safe
email-template resolution across the six affected modules. Preserve legitimate merchant/staff
functionality."*

The investigation's answer is that **there is nothing to make tenant-safe at the resolve**, because
`email_templates` is not a per-tenant table. Evidence:
`tests/Feature/Security/EmailTemplateOwnerResolutionTest.php` — 10 tests, 46 assertions, executed.

---

## 1. What #69 claimed

That `EmailTemplate::where('name', 'LIKE', $name)->first()` — repeated in six vendored modules and
once in the host — is an unscoped cross-tenant read, so tenant B's customers receive tenant A's
template content. `EmailTemplate` does indeed carry no global scope, is absent from
`config/tenancy.php`'s `scoped_models`, and `->first()` is unordered. The reasoning was sound. The
premise it rested on was not.

## 2. Two populations in one table

`email_templates` has two writers with two different match keys, and they behave oppositely under
the same unscoped resolve.

### Module names are seeded per tenant — and #69's premise holds for them

All seven module writers are the same shape:

```php
$emailTemp = EmailTemplate::where('name', $eTemp)->where('created_by', Auth::user()->id)->count();
if ($emailTemp == 0) { EmailTemplate::create([... 'created_by' => Auth::user()->id]); }
```

| module | writer |
|---|---|
| LotteryPrizes | `LotteryPrizesController.php:191` |
| PartialPayments | `PartialPaymentsController.php:145` |
| ProductEnquiry | `ProductEnquiryController.php:413` |
| ReviewReminder | `ReviewReminderController.php:51` |
| SpinToWinReward | `SpinToWinRewardController.php:177` |
| PreOrder | `PreOrderController.php:386` |

So `Lottery Win`, `Pre Order Created`, `Product Enquiry`, `Lucky Winner`, `Review Reminder`,
`Payment Reminder` and `Product Catelog` get **one row per tenant**, and the unscoped resolve
returns the **lowest-id tenant's row for everyone**.

**Measured.** Two tenants each create their own `Lottery Win` and each activate their own:

```
email_templates:  id=7 Lottery Win created_by=2      <- tenant A
                  id=9 Lottery Win created_by=3      <- tenant B
unscoped resolve of 'Lottery Win' -> id=7 (tenant A's), for both callers
  tenant A: activation row for the resolved template is_active=1, body = BODY-OF-TENANT-A
  tenant B: activation row for the resolved template is_active=0, body = BODY-OF-TENANT-A
```

**The outage is unconditional; the leak is conditional.** Tenant B's lottery mail is silently
skipped, and it cannot be fixed from the UI: the row that would have to be flipped belongs to
tenant A's template, and every module settings screen lists only
`where('created_by', Auth::user()->id)`. For tenant B's customers to actually *receive* tenant A's
body, `(A's template, B)` would have to be `is_active = 1` — which no screen can produce, but a seed
or a manual row can.

### The six host names go the other way — and this is why the obvious fix is dangerous

**For `Order Created`, `Status Change`, `Order Created For Owner`, `Stock Status`, `Abandon Cart`
and `Abandon Wishlist`, `email_templates` is a platform-global catalogue.** `Utility::defaultEmail()` seeds it
with

```php
EmailTemplate::firstOrCreate(
    ['name' => $eTemp],            // <- the match key is `name` ALONE
    ['from' => …, 'created_by' => $createdBy]
);
```

Driven as three different users in one run (`test_email_templates_is_a_platform_global_catalogue…`):
**6 rows after the first call, 6 after the second, 6 after the third**, every one
`created_by = <the super admin>`. A merchant never gets rows of their own, and every
`email_template_langs` row hangs off those same six ids — so there is **one body per template for
the entire platform**. There is no per-tenant content, therefore nothing per-tenant to leak, and
resolving by name is **correct**.

`email_templates` has **no `store_id` column at all**. `created_by` is vestigial.

**The per-tenant state is `user_email_templates`** `(template_id, user_id, is_active)`.
`Utility::userDefaultData($id)` — which `HomeController::index()` runs on every dashboard load and
`StoreController` runs at store creation — seeds one row per user per catalogue template, at
`is_active = 0`.

**And the merchant can turn them on.** Two things made this look otherwise:

- `EmailTemplateController:36,55` list `EmailTemplate::where('created_by', Auth::user()->id)`, which
  for a merchant is empty — but that is the **content-editing** screen, which is the platform
  operator's.
- The merchant-facing screen is Settings → Email Notification, `SettingController:1681-1688`, which
  lists `EmailTemplate::where('created_by', $s_admin->id)` — the **super admin's** rows, not
  `Auth::id()`'s. Its writer, `EmailTemplateController::updateEmailNotificationStatus`, admits
  `type` in `['super admin', 'admin']`, and a merchant is `type = 'admin'`.

Measured end to end: a merchant POSTing that form set all 6 of their `user_email_templates` rows to
`is_active = 1`.

As a side result, `SettingController:1686`'s `whereNot('created_by', \Auth::user()->id)` — which the
earlier census flagged as a deliberate cross-tenant read and treated as a blocker for any global
scope — is simply the fallback branch for a platform with no super admin. It is not an anomaly.

## 3. The fix this issue implied, and why it is not applied

The one-line change #69 points at is, on the host copy (`app/Models/Utility.php:2341`):

```php
$template = EmailTemplate::where('name', 'LIKE', $emailTemplate)
    ->where('created_by', $owner->id)      // <- NOT THIS
    ->first();
```

`$owner` is already a parameter and already used two lines later, so it looked like a free
correction. **It would have made `$template` null for every merchant on the platform**, because no
merchant owns a catalogue row — and `Utility::sendEmailTemplate` is the order-confirmation path,
with 54 call sites across `PaymentService`, `Api\ApiController` (the Flutter app's order creation)
and every payment controller, for `'Order Created'`, `'Order Created For Owner'` and
`'Stock Status'`. Every one would have returned `'Mail not send, email not found'`.

It is recorded here, and in the test file's header, so the next reader does not apply the module
fix to the host line.

**This analysis took four revisions, each forced by measurement:**

| | claim | what measurement showed |
|---|---|---|
| 1 | a cross-tenant content leak everywhere | true for module names, not for host names |
| 2 | tenant B's `UserEmailTemplate` lookup is null → `ErrorException` | not the production shape; `userDefaultData` seeds a row for every pair, so it is a silent skip |
| 3 | a silent outage for every tenant but the lowest-id owner | right for module names; for host names there is only one owner, the platform admin, and merchants can toggle |
| 4 | "the filed premise does not hold" | too broad — generalised from the host population to the module one, which is the population the module copies resolve |

## 4. The real defect

The six module copies do this **unconditionally, and before the `is_active` check**:

```php
$slug = Store::where('created_by', $template->created_by)->select('slug')->first()->slug;
```

| file | line |
|---|---|
| `packages/workdo/PartialPayments/…/PartialPaymentsController.php` | 313 |
| `packages/workdo/ProductCatelog/…/ProductCatelogController.php` | 303 |
| `packages/workdo/SpinToWinReward/…/SpinToWinRewardController.php` | 421 |
| `packages/workdo/ReviewReminder/…/ReviewReminderController.php` | 221 |
| `packages/workdo/ProductEnquiry/…/ProductEnquiryController.php` | 282 |
| `packages/workdo/LotteryPrizes/…/LotteryPrizesController.php` | 722 |

**A seventh copy of the resolve**, which #69's "six modules" misses:
`packages/workdo/PreOrder/src/app/Models/PreOrderHistory.php:95`. It is reached from host code in
`PaymentController` and from `PreOrder/src/app/Console/PreOrder.php:114` — another caller with no
ambient tenant. It shares the **resolve** defect and **not** the slug defect: it is the
`sendEmailTemplate` shape, like the host's, and carries no `Store::where('created_by', …)` line.
Asserted, so the slug finding is not overstated by one site.

`$template->created_by` is **always the platform admin**. So the storefront slug baked into every
tenant's customer-facing email is derived from the platform admin rather than from the `$store` the
function was handed — and `$store` is already a parameter of all six (asserted).

**Measured** by executing `LotteryPrizesController::sendOwnerEmailTemplate` for a real merchant with
a real activated template:

- **Platform admin has no store** (the normal case) → `ErrorException: Attempt to read property
  "slug" on null` at `LotteryPrizesController.php:722`. Laravel sets `error_reporting()` to `-1`, so
  `HandleExceptions::handleError` turns the warning into a throw; `ErrorException extends
  Exception`, so each caller's `catch (\Exception $e)` swallows it. **Every module mail — lottery
  win/fail/reminder, product enquiry, spin-to-win, review reminder, product catalogue,
  partial-payment reminder — silently never sends, for every tenant.**
- **Platform admin has a store** → the slug resolves to *that* store, so every tenant's email links
  point at the wrong storefront. This is the cross-tenant exposure #69 was reaching for, in the slug
  line rather than the template resolve.

**Blast radius, bounded by assertion:** the host's own `Utility::sendEmailTemplate` has **no** slug
line, so the order-confirmation path is **not** affected by this defect.

**One host-side robustness gap, separate and lower severity:** `Utility.php:2350` reads
`$is_actives->is_active` without a null guard, so a user with no `user_email_templates` row — a
merchant who has never loaded their dashboard, since that is what seeds them — makes the host path
throw too. Executed.

## 5. Why there is no shared host-side solution

The owner asked specifically whether one exists. **No mechanism reaches all eight sites.** One
reaches at most four; one reaches all eight but only downstream, after the wrong template has
already been chosen.

**A global scope on `EmailTemplate` is the wrong instrument.** The table holds both populations
(§2), so a single tenant predicate is wrong for one of them whichever way it is written: scoped to
the caller, the six host names return nothing for every merchant, which is §3's outage; unscoped,
the module names stay broken. There is no one predicate correct for both.

**The slug defect is not in a query the host can reach.** It is in `Store::where('created_by',
$template->created_by)`, inside un-editable code. The only host-owned levers are the `Store` and
`EmailTemplate` model classes. A scope on `Store` would reach far beyond mail. An accessor
overriding `EmailTemplate::$created_by` would reach the right expression — and would silently change
what **51 other read sites** see, which is action at a distance this project should not ship.

**A static call cannot be intercepted at all.**
`LotteryPrizesController::sendOwnerEmailTemplate`, `PartialPaymentsController::sendOwnerEmailTemplate`
and `ReviewReminderController::sendOwnerEmailTemplate` are called by literal class name from
listeners, jobs and console commands. PHP resolves those through the Composer autoloader, not the
container, and constructs no instance — so no binding reaches them.

**But four sites ARE container-resolved, and that is a real if narrow mechanism I had missed.**
`ProductCatelogController:130`, `ProductEnquiryController:120`, and `SpinToWinRewardController:322`
and `:640` call `$this->sendOwnerEmailTemplate(...)` from inside a *routed action*, and Laravel
resolves a route's controller through the container (`Illuminate\Routing\Route::getController()`).
A host-side binding of each vendored controller to a host subclass overriding the method therefore
**would** intercept those four. Recorded as available rather than recommended: four of eight,
request paths only, each override must match its parent's staticness
(`ProductCatelogController:294` is `public function`; `ProductEnquiryController:273` and
`SpinToWinRewardController:412` are `public static`, both still invoked via `$this->`), and it means
this repository maintaining subclasses of three vendored controllers indefinitely.

**One host-owned seam reaches all eight, but only downstream.** Every copy constructs
`App\Mail\CommonEmailTemplate($template, $settings, $store)` — host code — where `$template` is the
`EmailTemplateLang` row whose `parent_id` still identifies the owning `EmailTemplate`, with `$store`
in hand. Host code there can compare the template's owner against `$store->created_by` and refuse.
That is damage *detection*, not prevention: the resolve has already picked the wrong row and the
activation check has already been answered against it. It would convert a silent skip into a
visible refusal, which has value, and it would not fix a single send.

**And half the call sites have no ambient tenant to key anything on.** Enumerated:

| call site | context | tenant resolvable? |
|---|---|---|
| `PartialPayments/app/Console/Payment.php:80` | console command | **no** |
| `LotteryPrizes/app/Console/Lottery.php:80` | console command | **no** |
| `LotteryPrizes/app/Jobs/SendLotteryWinEmail.php:42` | queued job | **no** |
| `LotteryPrizes/app/Jobs/SendLotteryFailEmail.php:42` | queued job | **no** |
| `LotteryPrizes/app/Jobs/SendOrderCreatedEmail.php:38` | queued job | **no** |
| `ReviewReminder/app/Listeners/SendOrderReviewMailListner.php:42` | listener in checkout | partly |
| `ProductCatelog/…Controller.php:130` | request | yes |
| `SpinToWinReward/…Controller.php:322, 640` | request | yes |
| `ProductEnquiry/…Controller.php:120` | request | yes |
| `LotteryPrizes/…/LotteryResultController.php:110, 170` | request | yes |

In the console and job cases the owner is a **loop variable** in a process with no resolved tenant.
`config/tenancy.php` sets `null_tenant => 'open'`, so an ambient scope there adds no predicate at
all — today's behaviour — or carries over whatever the previous loop iteration left, which is worse
than the defect.

## 6. Recommendation

| | action | gate |
|---|---|---|
| a | **Re-file #69.** Its title and body describe a defect that does not exist; the real one is the slug derivation. Leaving it as filed will cause someone to apply §3's change. | do now |
| b | **Upstream WorkDo report**, one issue covering all six copies: replace `Store::where('created_by', $template->created_by)->select('slug')->first()->slug` with the slug of the `$store` already passed in. One token per file, six files. | owner approval to send |
| c | **Null-guard `Utility.php:2350`** in the host — `if ($is_actives && $is_actives->is_active == 1)`. Pure robustness, no behaviour change for a seeded user, and it stops the host path throwing for an unseeded one. | low risk; proceed on approval |
| d | **Give the platform admin no store, deliberately and on purpose.** Today the absence of one is what makes (4) a silent outage rather than a wrong-slug leak. That is accidental containment, and it should be written down as a constraint rather than relied on silently: if the operator ever creates a store, six modules start putting the operator's slug in every tenant's customer email. | record as a constraint |
| e | **Do NOT add a global scope to `EmailTemplate`**, and do not add a `created_by` accessor to it. | settled by this investigation |

(b) is the only thing that actually fixes it, and it is not ours to apply. (c) and (d) are what the
host can do. Neither touches money, schema or the request/response contract.

---

### Evidence

```
php artisan test tests/Feature/Security/EmailTemplateOwnerResolutionTest.php
  10 passed (46 assertions)
```

Four tests establish the architecture (so the void premise cannot be re-filed), four pin the real
defect including two that execute the vendored function, one bounds the blast radius away from the
checkout path, and one pins the host null-read. Every line citation in this document is asserted by
file and line number in that suite, because in this project the citation is the evidence.
