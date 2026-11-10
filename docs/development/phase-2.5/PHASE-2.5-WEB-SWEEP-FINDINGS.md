# Phase 2.5 — web route sweep findings (2026-11-10)

Evidence: `evidence/web-sweep-2026-11-10/` (100 captures, 32 routes x 4 combinations).

---

## 1. `GET /profile/edit` rendered a blank page — FIXED

**Severity: blocker. Already carried in the inventory as the finding `profile-edit-renders-empty`,
marked _not yet verified_. It is now measured, fixed and pinned.**

All four combinations answered **HTTP 200**, carried the correct `dir`, and loaded the correct
theme sheet. The sidebar, header, breadcrumb and footer all drew perfectly. Between the breadcrumb
and the footer there was **nothing at all**.

| capture | what it shows |
|---|---|
| `after/_profile_edit__en_ltr_light.png` | chrome only, empty content area |
| `after/_profile_edit__en_ltr_dark.png` | chrome only, empty content area |
| `after/_profile_edit__ar_rtl_light.png` | chrome only, empty content area |
| `after/_profile_edit__ar_rtl_dark.png` | chrome only, empty content area |

The second tell was the page title: `Store Syria -`, with nothing after the dash, because
`@yield('page-title')` had nothing to yield.

### Root cause — two layout conventions colliding silently

| file | convention |
|---|---|
| `resources/views/profile/edit.blade.php` | **Breeze**: `<x-app-layout>` … `</x-app-layout>`, so its body is passed as **`$slot`** |
| `app/View/Components/AppLayout.php` | `render()` returns `view('layouts.app')` |
| `resources/views/layouts/app.blade.php` | **WorkDo**: **zero** references to `$slot`; emits content with `@yield('content')` (:708) |

A layout that yields a section never outputs the slot it was handed. The whole form — profile
information, password change, delete account — was discarded with no exception, no warning and a
200 status. Verified by grep: `$slot` appears **0 times** in `layouts/app.blade.php`.

### The fix, and what it deliberately does not do

`ProfileController@edit` now returns `Redirect::route('profile')`.

**Why not simply render the Breeze form?** Because that would have been a product change wearing a
bug fix's clothes:

1. The product already ships a profile screen — `GET /profile` → `UserController@profile` →
   `resources/views/users/profile.blade.php`, which updates the profile and changes the password.
   Rendering the Breeze view would have put a **second** profile editor beside it.
2. The Breeze view includes `profile.partials.delete-user-form`, whose target
   `ProfileController@destroy` is a bare `$user->delete()` — **no store, order, subscription or
   tenant cleanup**. Rendering the form would have introduced self-service tenant deletion into
   the merchant UI. That is an owner decision, not a rendering repair.

Nothing is lost by redirecting: `/profile/edit` is **unreachable from the shipped UI**. Its only
two callers are `resources/views/layouts/navigation.blade.php:37,83`, and that partial is included
by **nothing** (verified by grep across `resources/`, `app/` and `packages/`). The route exists so
that `ProfileController::update()`'s `Redirect::route('profile.edit')` resolves — the
NEW-ROUTE-SHADOW-01 repair — and every pinned guarantee still holds: `Route::has('profile.edit')`,
the `profile/edit` URI, and `route('profile.edit')`.

The save path improves as a side effect: a successful `PATCH /profile` previously landed the
merchant on the blank page; it now lands on their actual profile.

**Pinned by** `tests/Feature/ProfileEditRendersSomethingTest` (2 cases). Confirmed failing before
the change and passing after.

### Open item for the owner (not actioned)

`DELETE /profile` (`profile.destroy`) remains registered and performs an untenanted
`$user->delete()`. No UI reaches it today. Whether merchants should be able to delete their own
account — and what must happen to their store, orders and subscription if they can — is a product
and data-integrity decision. **No change was made.**

---

## 2. Six routes answer HTTP 500 on the dev fixture — not product defects

`MONTH()`, `YEAR()` and `SUBSTRING_INDEX` are **MySQL** functions used in raw SQL. The Phase 2.5
dev fixture is SQLite (`database/p2dev.sqlite`), which implements none of them.

| route | controller method | missing function |
|---|---|---|
| `/reports-chart` | `ReportController::reports_chart` (:202-216) | `MONTH` |
| `/order-reports-chart` | `order_reports_chart` (:720) | `MONTH` |
| `/order-reports-chart-data` | `order_reports_chart_data` (:985-1205) | `MONTH`, `YEAR` |
| `/product-order-reports` | `order_product_reports` (:1752) | `MONTH` |
| `/top-all-reports` | `top_product` (:2814, :2826) | `SUBSTRING_INDEX` |

`ReportController:2883` already carries a comment saying SQLite cannot run the `top_product` join,
so this limitation was known in the code before this sweep.

**Production runs MySQL, where all of these exist.** Reporting them as defects would be false; so
would reporting the screens as verified, because nobody has seen them. They are recorded **BLOCKED
with a remediation**: stand up a MySQL dev fixture (the pattern is
`tests/Support/Security/bin/verify-sec13-migration-on-mysql.php`, which creates and drops its own
throwaway database) and re-run the sweep unchanged.

`/shopify_product` and `/woocom_product` are blocked differently: both controllers require a
connected third-party store — credentials, a store URL and a live API call — before they return a
view. No merchant-visible layout exists to photograph without one.

---

## 3. Inventory arithmetic: 295 rows, **293 distinct surfaces**

The owner asked for the exact two statuses behind 293 of 295. They are **two rows that describe a
screen already inventoried under another name** — a duplicate, not a disappearance, and both stay
visible in the matrix under a new `DUPLICATE` state.

| duplicate row | owner row | proof they are one screen |
|---|---|---|
| `Notifications (full list)` (inventory :428) | `Notifications (the bell's destination)` (:96) | the same route, `GET merchant-notifications`. The owner row is **already PREMIUM VERIFIED** (P2.5-WAVE-33, `_merchant_notifications__`). The two rows' audit columns even disagree — `premium-already` vs `needs-polish` — which is what two auditors reaching one screen from different entry points looks like. |
| `Stock Reports (3-tab shell)` (:363) | `Stock Reports` (:417) | one controller action on two URIs. `routes/web.php:1108-1109` register it as both `GET stock-reports` (`reports.stock_report`) and `GET /stock-report` (`stock.report`); the inventory's own route column says so in both rows. Both URIs were captured and both sets show the same page with the same title. |

Claiming either duplicate would have counted one screen twice and inflated the completion figure
using a single set of photographs. `DUPLICATE_OF` in the matrix builder is checked **before** any
verified claim, so a future well-meaning claim cannot re-inflate the count, and the orphan guard
now also refuses a duplicate whose owner row does not exist.

**Both numbers are reported from here on: 295 inventory rows, 293 distinct surfaces.**

---

## 4. One in four clean is a positive identification, not a partial pass

Ten routes came back clean in **exactly one** of four combinations, always `en-ltr-light`. That is
not flakiness and not a partial result. Each answered 200 with **no `<html>` element**, so the
harness read `dir` as `null`:

* `null !== 'rtl'` → the direction premise fails for both Arabic rows;
* no dark sheet link → the theme premise fails for both dark rows;
* `en-ltr-light` asks for neither, so it passes **by coincidence**.

A fragment therefore scores exactly 1/4, every time. These are captured by `shoot-fragments.cjs`,
which drives the parent page and photographs the fragment in place, rather than by navigating to
the URL directly.
