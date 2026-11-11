# Phase 2.5 — final acceptance report

**Date:** 2026-11-11 · **Branch:** `claude/vibrant-bell-12tquy` · **Not merged. Not deployed.**

> **This report does not claim release readiness, and it cannot.** Mandatory security gates are
> red, and this closure found **five live security defects** that were not known when the
> implementation inventory reached zero PENDING. Four of them destroy data. All five are fixed with
> tests in this branch; none of them was a matrix row, which is the point §2 makes below.

---

## 1. The two things that are true at once

| | state |
|---|---|
| **Phase 2.5 implementation scope** | **COMPLETE** — 295 rows, 0 PENDING, frozen and unchanged by this closure |
| **Phase 2.5 release readiness** | **NOT REACHED** — two blocking CI gates red, one unremediated incident, five live defects found during closure |

Both statements are measured. Neither cancels the other, and the report keeps them apart
throughout, because "the implementation is done" and "this is safe to ship" are different claims
and only the first is engineering's to make.

---

## 2. Matrix reconciliation — frozen, and reconciled line by line

The directive required the 295-row matrix preserved with no artificial promotions. It is.

| state | at the start of this closure | now | moved? |
|---|---:|---:|---|
| REDESIGNED AND VERIFIED | 225 | **225** | no |
| PREMIUM VERIFIED | 24 | **24** | no |
| OUT OF SCOPE | 28 | **28** | no |
| DUPLICATE | 8 | **8** | no |
| BLOCKED | 10 | **10** | no |
| **PENDING** | **0** | **0** | no |
| **total** | **295** | **295** | no |

`build-coverage-matrix.py --check` is green. **Three rows changed their EVIDENCE or their stated
REASON during this closure; none changed state:**

| row | what changed | state |
|---|---|---|
| WooCommerce import screens | its stated reason was false in every clause, and its four captures were pictures of another surface | **BLOCKED, unchanged** |
| Attribute options list (DS pilot) | its four captures photographed a permission-denial; re-captured and re-pointed | **REDESIGNED AND VERIFIED, unchanged** |
| Edit customer (unimplemented) | a stale `routes/web.php:1191` corrected to `:1200` at source | **BLOCKED, unchanged** |

**The five live security defects in §5 are NOT matrix rows and were not added as any.** They are
security findings against code the matrix had already classified. A reader who takes "0 PENDING"
to mean "nothing left to fix" would be reading the matrix to say something it has never said: it
tracks whether a *surface* was designed and verified, not whether the code behind it is safe.

---

## 3. Evidence integrity — all three gates green, and two of them were red

| check | catches | before | now |
|---|---|---|---|
| `build-coverage-matrix.py --check` | a claim whose four captures are absent | green | **green** |
| `check-distinct-surface-captures.py` | two surfaces claimed distinct with identical pixels | **RED, 2 collisions** | **green, 0** |
| `detect-refusal-toast-captures.py` | a capture photographing a permission refusal | **RED, 4 captures** | **green, 932 checked** |

Neither was cleared by weakening a guard, deleting valid evidence or approving a duplicate.

### 3.1 The WooCommerce captures were pictures of the webhook page

Both refusal arms of `WoocomProductController::index` end in **`redirect()->back()`**, so the
browser goes **wherever it came from**. Measured:

| driven from | HTTP | landed on | title |
|---|---|---|---|
| `/dashboard` | 200 | `/dashboard` | `Store Syria - لوحة التحكم` |
| `/webhook/create` | 200 | `/webhook/create` | `""` |

The sweep drove `/webhook/create` immediately before, so the refusal returned to it and the
screenshot photographed it. The recorded `title: ""` came from the same place — a modal fragment
has no `<title>`, and that was written down as "empty body".

**Not regenerated, because this surface has no pixels of its own.** Replaced with
`_woocom_product__MEASUREMENT.json`. This is the `redirect()->back()` trap the project already
records, in its most literal form: there it keeps the previous page's *title*, here it kept its
*pixels*.

### 3.2 Four captures photographed a "permission denied" toast

`detect-refusal-toast-captures.py` is documented as a manual run because it needs Pillow.
**Pillow 12.3.0 is installed in this environment**, so it ran: of 932 cited captures, four failed
at 1.12–1.77% toast-red against a next-closest clean capture of 0.47%.

The cause was not a missing permission — the fixture holds `Manage Attributes Option`.
`/attribute-option/{id?}` declares its id **optional** and the sweep drove it **without one**, so
the handler found no attribute and refused. `/attribute-option/1` renders the real screen.
Re-captured, 4 combinations, 0 not clean; the filename now carries the id.

**Recommendation:** this detector should become a required CI step rather than a documented manual
run. It found a false VERIFIED claim that every other gate passed, and the only stated reason it
was manual — the Pillow dependency — is satisfied here.

---

## 4. Tests and CI

All run against this branch. **Unit and Contract and the Feature known-outcome gate are green;
two CI steps outside the test suites are red and are §6.**

| gate | result |
|---|---:|
| `php artisan test --testsuite=Unit` | **1588 passed** (12,144 assertions) |
| `php artisan test --testsuite=Contract` | **38 passed** (149 assertions) — the Flutter API contract |
| `php artisan test --testsuite=Feature` | see below |
| `assert-known-test-outcomes.php` | asserts the outcome set **in both directions** |
| `build-coverage-matrix.py --check` | **green** |
| `check-distinct-surface-captures.py` | **green** (was red) |
| `detect-refusal-toast-captures.py` | **green** (was red) |
| `composer audit must not grow` | **RED** — §6.2 |

### 4.1 The two declared failures and the one declared skip

The gate asserts this set in **both** directions: an undeclared failure fails CI, and a declared
failure that starts passing **also** fails CI, because the record has become a lie. Nothing was
added to `.github/known-test-outcomes.json` during this phase.

**`Tests\Feature\Auth\RegistrationTest::test_new_users_can_register`** — a fixture gap exposing a
latent robustness defect, not a casing bug. `RefreshDatabase` runs migrations and not seeders, so
`roles` holds 0 rows and `Role::where('name','admin')->first()` is NULL. Both real call sites are
null-guarded by `if ($role_r)`, so nothing dies — `POST /register` returns 302 and the request
completes. **The role assignment is silently skipped**, which is the latent defect: in production a
missing `admin` role would register a user with no role and no error.

**`Tests\Feature\ProfileTest::test_profile_page_is_displayed`** — a real application defect.
`GET /profile` answers 500 with `Attempt to read property "slug" on null` at
`HomeController.php:610`, reached from `partision/header.blade.php` via
`getStoreById(getCurrentStore())`, which is null for a User with no `current_store` and no store
they created. This is the condition behind several other "the test can't render it" notes in this
phase, and it is the reason `EmailTemplateEmptyStateTest` asserts a redirect rather than a 200.

**`Tests\Feature\Security\CustomerAccountBoundaryTest::test_an_anonymous_visitor_is_not_gated_out_of_newsletter_signup`**
(skip) — SCHEMA-01. `AccountProfileController::add_newsletter` writes `newsletters.user_id`, a
column **no migration declares**, so the insert always fails. It stays skipped deliberately: the
column's type was never measured, and `docs/GATE-F-VERIFIED-SCHEMA.md` covers the 15 tables and 3
columns and not this one, so declaring it would be exactly the guess GATE F exists to forbid. The
authorization assertions in that same test ran and passed before the skip.

### 4.2 Tests added by this closure

| file | cases | what it pins |
|---|---:|---|
| `tests/Feature/Security/DestructiveRoutesTenantGuardTest.php` | 10 | the four destructive defects of §5, **and** that the legitimate owner and the operator can still do the work |
| `tests/Feature/Security/CrossTenantPolicyGridTest.php` (extended) | +2 | the cross-tenant read of §5.5, and that a merchant can still open their own staff's form |

Every fix was reverted individually and the matching test reddened:

```
BankTransferController reverted -> 2 red      MenuItemsController reverted -> 1 red
MenuController         reverted -> 1 red      PlanController      reverted -> 1 red
UserController::edit   reverted -> 1 red      restored            -> all green
```

**That check earned its keep twice.** It caught that F-3 initially had **no test at all** — its row
was only ever reached as a cascade through F-2's route, so reverting F-3's fix left everything
green. Direct-route coverage was added. It also caught a partial revert of my own that would have
reported a false result.

---

## 5. Security findings — five live defects, found during closure, all fixed with tests

**None of these was known when the inventory reached 0 PENDING.** Four destroy data; one leaks
personal data. All five share one shape, which is this codebase's recorded defect shape: *a routed
action, on a model absent from `scoped_models`, whose target id comes from the URL, with no
authorization in the handler and no gate on the route.*

Each was measured end to end through the real routes before being fixed, and each fix mirrors a
gate that already exists elsewhere in the same codebase rather than inventing a new policy.

### 5.1 `DELETE /bank_transfer/{order}` — any account erased any merchant's payment record

| | |
|---|---|
| **Code path** | `BankTransferController::destroy(PlanOrder $order)`; route `routes/web.php:650` |
| **Exposure** | **REACHABLE NOW.** Route-model bound, so the handler's `if ($order)` is decoration — never null. Route middleware is `web, auth, xss, setlocate, verified` and **nothing else**. Measured: merchant A deleted merchant B's plan order (302, row gone); a **staff member holding no permission at all** did the same. What goes is a subscription payment record — order id, price paid, payment status, and the pointer to the bank slip the merchant uploaded. **Hard delete: no soft delete on PlanOrder, no audit row.** |
| **Remediation** | Operator-first gate mirroring `PlanOrderDataTable::query()`, which already shows a non-operator only `users.id = auth()->id()`. **Non-breaking** — it refuses what a merchant could never have listed. |
| **Tests** | 2 refusal cases + 2 legitimate-path cases (owner can still delete; operator can still delete) |
| **Residual** | **An owner decision this fix deliberately does not take:** whether the legitimate owner should be able to erase their own payment record at all. The repository already states the principle — `BulkDeleteTenantGuard` classifies `plan-order` as PLATFORM_GLOBAL because *"letting the payer erase it is an audit problem"*. **The bulk door refuses it; the single-row door did not look.** Changing that is a financial-behaviour change and is reserved. |

### 5.2 `DELETE /menu/delete-item/{id}` — silent cross-tenant delete, with a cascade

| | |
|---|---|
| **Code path** | `MenuController::deleteItem($id)`; route `routes/web.php:972` |
| **Exposure** | **REACHABLE NOW.** Measured: merchant A deleted merchant B's menu item and received `{"status":true,"message":"Menu item deleted successfully"}`. It **cascades** into `CustomLink::find(...)->delete()`, so the linked row goes too. Any tenant's storefront navigation, removed by anyone with an account, with a success message. `index()` and `store()` on the same controller both check `isAbleTo('Manage Menu')`; `deleteItem()` checked nothing. |
| **Remediation** | The sibling methods' own permission check, plus an ownership test one hop through the store-scoped `menus` table. **Non-breaking.** |
| **Tests** | cross-tenant refusal asserting **both** rows survive; legitimate owner can still delete, cascade included |
| **Residual** | None for this path. |

### 5.3 `DELETE /delete-custom-link/{id}` — the same, one model along

| | |
|---|---|
| **Code path** | `MenuItemsController::deleteCustomLink($id)`; route `routes/web.php:974` |
| **Exposure** | **REACHABLE NOW.** Measured: 302, another tenant's `custom_links` row gone. |
| **Remediation** | Same gate as §5.2. **Non-breaking.** |
| **Tests** | direct-route refusal **by an attacker who holds `Manage Menu`**, so the case fails on ownership rather than on the permission; plus the legitimate path |
| **Residual** | None. |

### 5.4 `DELETE /plan/{plan}` — a merchant deleted a platform subscription plan

| | |
|---|---|
| **Code path** | `PlanController::destroy`; route `routes/web.php:481` |
| **Exposure** | **REACHABLE NOW**, and this is **privilege escalation**, not only isolation. The merchant role is granted `Manage Plan` and **never** Create or Edit, yet `destroy` outranked the view-only grant it was paired with. The only protection present was incidental — `User::where('plan_id',$id)` refuses a plan somebody is subscribed to — which is referential integrity, not authorization. So unsubscribed tiers went. |
| **Remediation** | Operator only. Neither broadens an operator capability nor narrows a permission a merchant legitimately held. **Non-breaking.** |
| **Tests** | merchant refused; operator can still retire a tier |
| **Residual** | None. |

### 5.5 `GET /users/{id}/edit` — every merchant could read every user's name and email

| | |
|---|---|
| **Code path** | `UserController::edit($id)`, `app/Http/Controllers/UserController.php:230` |
| **Exposure** | **REACHABLE NOW.** The method checks `isAbleTo('Edit User')` and then does `User::find($id)` with **no ownership test**, rendering `users.edit`, whose form prints the target's **name and email**. Measured: a merchant holding `Edit User` requested another tenant's staff member and got HTTP 200 with the victim's address in the body. `Utility::addNewData()` grants `Edit User` to the `admin` role every merchant holds — **31 of the 33 users in the production dump** — so this reached every user on the platform. |
| **Why it survived** | **SEC-F4 closed the write door and left the read door open.** `update()`, twenty lines below, carries SEC-F4's own description of this exact defect and was repaired with `authorize('update', $user)`. `edit()` was not touched: same file, same permission, same missing check, only the verb differs. |
| **Remediation** | The sibling's own call, `$this->authorize('update', $user)`, with a null guard. `update` is the right ability: being allowed to open the form is the same question as being allowed to submit it. **Non-breaking**, refuses only cross-tenant reads. |
| **Tests** | asserts on the **body**, not the status — a 200 rendering an empty form would pass a status check; the victim's address is the thing that must not appear. Plus the legitimate own-staff read. |
| **Residual** | None for this path. The adjacent operator path `StoreController::edit/update` rewrites a merchant's name and email gated on `type == 'super admin'` alone, with no permission and no policy — recorded in §7 as an owner decision, not changed here. |

---

## 6. The security items the directive named, with what each needs

### 6.1 The four critical Composer advisories

Installed versions and the floor each advisory names, with the bump class:

| advisory | package | installed | min fixed | bump |
|---|---|---|---|---|
| CVE-2026-114509 | `laravel/framework` | v11.47.0 | 11.56.1 | **MINOR**, within major 11 |
| CVE-2026-54133 | `mtdowling/jmespath.php` | 2.8.0 | 2.9.1 | **MINOR**, within major 2 |
| CVE-2026-34084 | `phpoffice/phpspreadsheet` | 1.30.1 | 1.30.3 | **PATCH** |
| CVE-2026-45034 | `phpoffice/phpspreadsheet` | 1.30.1 | 1.30.5 | **PATCH** |

**Exposure, measured rather than assumed — none is reachable today:**

* **TokenGuard — NOT REACHABLE.** Runtime `config('auth.guards')` has **no guard with
  `driver => 'token'`**. `Auth::guard('api')` raises *"Auth guard [api] is not defined"*. The 58
  module route files declaring `auth:api` **error** rather than constructing a TokenGuard. *(Side
  correction: `CLAUDE.md`'s "there is no api or sanctum guard" is measurably wrong about **sanctum**
  — Sanctum's service provider injects that guard at register(). It is not TokenGuard, so the
  verdict holds, but the documentation should be corrected.)*
* **jmespath — LATENT.** `Env::createRuntime()` returns `AstRuntime` here; `CompilerRuntime` — the
  vulnerable one — is never constructed. All JmesPath expressions in the tree are SDK-shipped
  literals; first-party `->search(` is **0**.
* **phpspreadsheet — LATENT.** The reader **is** merchant-reachable (`POST import/product` has no
  `isAbleTo` check on the import action), but the CVE needs the **filename string** attacker-
  controlled with a stream wrapper, and it is not: every one of the 13 call sites passes an
  `UploadedFile`, and the path reaching the reader is a server-generated temporary path.

**One CI comment is refuted and should be corrected.** `.github/workflows/ci.yml:616-625` justifies
"not reachable" partly with *"the vulnerable auto-detection branch is never entered"*. **Measured
false:** all eight import controllers accept `txt`, `config('excel.extension_detector.txt')` is
NULL, so a merchant uploading `evil.txt` **does** fall through into `IOFactory::identify()` and the
auto-detection branch. The *conclusion* survives on the server-generated filename; the *stated
reason* does not, and someone will one day rely on it.

**Remediation** — three bumps, all **same-major and non-breaking**, all already permitted by the
existing constraints so **no `composer.json` edit is needed**: phpspreadsheet → 1.30.8 (clears both
criticals and all 11 of its advisories), jmespath → 2.9.2, laravel/framework → v11.57.1.

**Residual / owner decision.** Applying them edits `composer.lock`, which is owner-gated in this
project, and needs a runner that can install. **Not applied here.**

### 6.2 The 66-advisory inventory against the ceiling of 58 — **CI RED**

`composer audit` reports **66 advisories across 22 packages** — 4 critical, 16 high, 30 medium,
7 low, 9 unscored. `ci.yml:754` sets `AUDIT_CEILING: '58'` and `:829` exits 1 above it, with no
`continue-on-error`. **The step fails by 8.**

`composer.lock` is **byte-identical** across this whole phase, so nothing entered the lock here:
the **advisory database** grew against dependencies nobody touched. The step's own error text says
what is needed, and it is not engineering's to do:

> record the measurement and raise AUDIT_CEILING deliberately, never silently.

**The ceiling is untouched.** The investigation found a lock-only route that would clear the gate
*without raising anything* — a symfony bump taking 66 → 53 — which is a better answer than raising
a ratchet, and is likewise an owner-gated lock change.

### 6.3 Incident P1-SEC-40 — the committed production dump

**`storesyria_ecommercego.sql` is tracked at the repository root today.** Verified:
`git ls-files` returns it.

**What is in it, measured by counting and never by printing values:** a phpMyAdmin dump of
`storesyria_ecommercego`, generated 24 Oct 2026; 198 `CREATE TABLE`, 84 with data, ~29,790 rows.
Including **90 bcrypt password hashes** (33 users, 57 customers), customer email / mobile /
date_of_birth, **137 distinct IPv4 addresses** across 6,471 occurrences, and **6,442 rows of raw
visitor request logs** carrying headers, user agents and referers.

**What is NOT in it, also measured:** no live gateway key (Stripe keys present are **TEST** mode,
`sk_live_`/`pk_live_`/`rzp_live_` all **0**), no AWS/Google/GitHub/SendGrid key, and **no
`APP_KEY`**. The repository's existing "no credentials" test checks `DEFINER=` / `IDENTIFIED BY` /
`GRANT` — all genuinely 0 — but **those test MySQL accounts only and cannot see the password
hashes or the gateway settings rows.**

**Applied here (recurrence containment only):** `.gitignore` now refuses dump shapes. Verified in
both directions — a new `.sql` is ignored, and the existing tracked file **remains tracked**,
because ignoring never untracks.

**This remediates nothing that already happened, and the report will not pretend otherwise.**
`git rm` would not undo it either; the blob stays fetchable. The only true remediation of the git
channel is a history rewrite, which this project forbids. **Owner decisions in §7.**

### 6.4 The scoped operator-authorization decision

§8.1 of `P0-USER-DELETION-CASCADE.md` writes out an unmade change: one Laratrust role
`platform-operator` carrying exactly `Edit User`.

**Measured finding: the change is a no-op in production, and the condition it was written for does
not exist.** In the production dump there is exactly one operator (user 1), `role_user` assigns it
role 1 `super admin`, and `permission_role` gives that role **75 permissions including Manage User,
Create User, Edit User, Delete User and Reset Password**. The grant is product code, not a hand
edit: `Utility::addNewData()` grants those names to the role named `super admin`, and it runs **on
every successful login**. So `UserPolicy::update()` already admits the live operator.

**Recommendation: decline §8.1 and close it as superseded.** Creating the role would broaden an
operator capability — the category the owner reserved — while changing nothing in production.

**The more material question the measurement surfaces** is the opposite one: the operator's
user-management grant is *already live and automatic*, re-applied at every login. Whether that is
intended is an owner decision, and it is a bigger question than §8.1 was.

### 6.5 Destructive deletion and tenant isolation

**Five live defects, in §5, all fixed with tests.** Beyond them, the sweep confirmed as
**already closed** (verified, not assumed): all three user-deletion doors fail-closed;
`forceDelete()` has **zero** first-party call sites; the three `truncate()` sites are platform
reference data; the one live raw delete is store-scoped; and store deletion remains the documented
**functional limitation** — disabled, not solved.

**Tenant isolation, measured at runtime:** `scoped_models` holds **11** entries and
`null_tenant => 'open'`. Absent and commonly used: Product, Order, Category, Coupon, ProductVariant,
Wishlist, Role and others — all of which *have* a `store_id` and could be allowlisted. Store, User,
Plan, MenuItem and CustomLink have **no `store_id` at all**, so no global scope can ever constrain
them, which is why §5.2–5.4 had to be fixed in the handler.

**`Product` remains unscoped and that is unchanged:** `StoreScope` is registered on it but
`isAllowlisted()` is false, so it adds no predicate for anyone. Compensating controls exist in
`ProductController` and are applied at ten sites — **but not at `product_price` and
`getProductsVariantQuantity`**, both `Product::find($request->...)` with no store predicate. Those
are cross-tenant **reads** of another tenant's price and remaining stock, in the already-recorded
`{slug}` storefront family. **Not fixed here** — read-only, and outside the destructive scope this
closure was asked to prioritise.

---

## 7. The ten BLOCKED rows, with individual dispositions

Not one is a capture gap. **One of the ten IS** — the Invoice row — and that is stated rather than
smoothed over. None can be closed by more engineering on this branch alone.

### SQLite cannot run the query; production is MySQL  (4 of 10)

**Customer report body (AJAX fragment)**  
`GET reports-chart`

**Sales report body (AJAX fragment)**  
`GET order-reports-chart`

**Sales report bar body (AJAX fragment)**  
`GET order-reports-chart-data`

**Top Sales Report**  
`GET top-all-reports`


**What blocks it.** Each answers HTTP 500 **on the dev fixture only**, because the handler builds raw SQL with a function SQLite does not implement. Production runs MySQL, where all of them exist. These are almost certainly fine in production and **cannot be shown to be from here**.

**Remediation.** A MySQL dev fixture. That is a tooling package, not a UI one.

**Disposition.** Engineering: build the MySQL fixture, or accept these four unverified for Phase 2.5.


### Needs live third-party credentials  (2 of 10)

**Shopify import screens (product, category, sub_category, coupon, customer)**  
`GET /shopify_product, /shopify_category, /shopify_sub_categor`

**WooCommerce import screens (product, category, coupon, customer)**  
`GET /woocom_product, /woocom_category, /woocom_coupon, /wooco`


**What blocks it.** Both require a connected external store before they render anything. Neither can be driven without credentials this environment does not have.

**Remediation.** An owner-provided sandbox credential for each.

**Disposition.** Owner: provide sandbox credentials, or accept these unverified for Phase 2.5.


### Cannot be rendered by the test harness  (1 of 10)

**Invoice (PDF build) / Invoice viewer**  
`Get.to(() => InvoicePdfViewScreen()) from order detail`


**What blocks it.** The viewer CHROME is captured; the PDF BODY is not. `PdfPreview` rasterises through the `printing` plugin and the capture raises `MissingPluginException` under `flutter_test`.

**Remediation.** Capture on a real device or emulator. Behaviour is pinned by tests meanwhile.

**Disposition.** Engineering: a device/emulator capture run, if the owner wants the body seen.


### Inactive code, not an unreachable screen  (1 of 10)

**COD / Stripe / Bank-transfer gateway editors**  
`unreachable — no Get.to, no AppRoutes entry anywhere in lib/`


**What blocks it.** Measured: all six files are **100% commented out — 815 lines, 0 live**. There is no screen to redesign, approve or reject.

**Remediation.** None available. There is nothing to render.

**Disposition.** Owner: decide whether these gateway editors are a Phase 04 commitment or should be deleted.


### Registered but unimplemented — an owner decision, not a capture gap  (2 of 10)

**Edit customer (registered, unimplemented)**  
`GET customer/{customer}/edit (customer.edit) + PUT/PATCH cust`

**Wishlist create / edit / store / update (registered, unimplemented)**  
`GET wishlist/create, GET wishlist/{wishlist}/edit, POST wishl`


**What blocks it.** Routes that resolve to handlers which render nothing. Building the screens is NEW merchant functionality, which Phase 2.5 is not authorised to add.

**Remediation.** An owner decision on whether each screen should exist at all.

**Disposition.** Owner: decide. Customer edit is a web/Flutter PARITY gap - the backend ships and the Flutter app already uses it.


---

## 8. Owner decisions required

Nothing below is pre-filled, and none of it was taken on the owner's behalf.

### Security — in the order they unblock each other

1. **The committed production dump (P1-SEC-40).** Three decisions: (a) whether to authorise a
   **read-only probe** of whether the file is served over the web — the one thing that would say
   whether this is an active anonymous disclosure; (b) whether to apply a root `.htaccess` deny for
   dump extensions, which is the only repository-side change that closes the web channel without
   touching git history, and which changes live webserver behaviour at the next deploy; (c) whether
   to authorise a **history rewrite**, the only true remediation of the git channel, which this
   project otherwise forbids. **And independently of all three: 90 bcrypt password hashes and 6,442
   rows of visitor request logs are in a tracked file. Credential rotation and customer
   notification are owner calls, and neither is an engineering task.**

2. **The `composer.lock` change.** Three same-major, non-breaking bumps clear all four critical
   advisories. A separate lock-only symfony bump would take the inventory 66 → 53 and clear the
   red CI gate **without raising the ratchet**. Both need a runner that can install and both are
   owner-gated.

3. **`AUDIT_CEILING`.** Only if the lock route above is declined. The step's own instruction is to
   raise it deliberately with the measurement recorded — never silently.

4. **Whether a merchant may delete their own subscription payment record** (§5.1). The repository
   already argues they should not: `BulkDeleteTenantGuard` calls it *"an audit problem"*. Changing
   it is a financial-behaviour change and was reserved.

5. **§8.1's `platform-operator` role.** The measurement supports **declining** it as superseded.
   The larger question it surfaces: the operator's user-management grant is already live and is
   **re-applied at every login** by product code. Is that intended?

6. **`StoreController::edit/update`**, which rewrites a merchant's name and email gated on
   `type == 'super admin'` alone — no permission, no policy. Not changed here.

### Acceptance

7. **Visual approval of the 24 PREMIUM VERIFIED surfaces** — the package is
   `PHASE-2.5-VISUAL-ACCEPTANCE-PACKAGE.md`. Accept, accept with exceptions, defer pending a
   reconstructed pixel before/after, or reject named surfaces.

8. **Whether the five live defects of §5 are admitted as release blockers** against the frozen
   matrix, or logged separately. They are security findings, not matrix rows.

9. **The four MySQL-only BLOCKED report bodies** — build a MySQL dev fixture, or accept them
   unverified for Phase 2.5.

10. **Shopify and WooCommerce sandbox credentials**, or an explicit decision to leave those screens
    unverified.

### Recommended, cheap, not done without a nod

11. **Make `detect-refusal-toast-captures.py` a required CI step.** It found a false VERIFIED claim
    that every other gate passed. Its only stated reason for being manual — the Pillow dependency —
    is satisfied in this environment.

---

## 9. Engineering completion versus release readiness

| | verdict | who decides |
|---|---|---|
| Phase 2.5 implementation scope | **COMPLETE** — 295 rows, 0 PENDING, frozen | engineering |
| Evidence integrity | **COMPLETE** — all three gates green, two repaired during this closure | engineering |
| Test suites | **Unit, Contract and the Feature known-outcome gate green** | engineering |
| Security remediation | **PARTIAL** — five live defects fixed with tests; four owner-gated items open | engineering proposes, owner decides |
| Owner visual acceptance | **NOT GIVEN** | the owner |
| Security approval | **NOT GIVEN** | the owner |
| **Release readiness** | **NOT REACHED** | the owner |

**Release readiness is not claimed and cannot be**, on the directive's own terms: a mandatory
security gate is red, an incident is unremediated, and credential rotation for 90 exposed password
hashes is outstanding. Phase 01's separate release gates are also not waived by anything here.

The honest summary of this closure is narrower than "Phase 2.5 is done", and more useful:
**the implementation scope is complete and frozen, the evidence behind it is now sound in a way it
measurably was not three days ago, and the act of verifying it carefully surfaced five live
security defects that the inventory reaching zero had not.** That last clause is the reason this
report exists.

---

## 10. Commits

| commit | what |
|---|---|
| `78e14d03` | the last 24 PENDING surfaces; eight defects found by driving them |
| `5284c57b` | Feature testcase count corrected; gate confirmed |
| `f9424503` | audit of the closure report — two of its capture claims were false |
| `5326caa4` | `FinanceControlLabellingTest` asserted attribute order and failed on its own prescribed fix |
| `e1c80472` | gate confirmed on `5326caa4`; assertion count corrected |
| `b4a4a924` | capture integrity: two surfaces verified on pictures of a different screen |
| *this commit* | five live security defects fixed with tests; this report |

**No merge to `main`. No deployment. Phase 03 not started.**
