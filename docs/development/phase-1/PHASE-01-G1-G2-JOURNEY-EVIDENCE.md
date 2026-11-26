# Phase 01 §G steps 1 and 2 — the end-to-end journey exercises

**Gates:** `P01-G1` / `GAP-020` (merchant journey) · `P01-G2` / `GAP-022` (customer journey)
**Date:** 2026-11-26
**Source of the requirement:** `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md` §G,
steps 1 and 2, under §G's own preamble *"Run as one exercise, not as a sum of task tests"*.

| | |
|---|---|
| `P01-G1` · merchant journey | **IMPLEMENTED AND EXERCISED.** Every leg §G step 1 names is driven end to end. |
| `P01-G2` · customer journey | **PARTIALLY EXERCISED.** Every leg is driven except **one online gateway**, which is blocked for want of a provider sandbox. The blockage is measured, not asserted around. |

This document records what exists, what was measured while building it, and — by name — what is
still not covered. It does not claim a disposition for either register row; the proposed wording is
in §8 for whoever owns those files.

---

## 1. What was there before

```
$ find tests -iname '*journey*' -o -iname '*EndToEnd*' -o -iname '*E2E*'
tests/Contract/FlutterOrderJourneyContractTest.php

$ ls tests/Feature/Journeys
ls: cannot access 'tests/Feature/Journeys': No such file or directory
```

The single hit is a cross-repository Flutter API contract, not a journey through the product. That
matches both register rows verbatim — *"No end-to-end merchant journey exercise exists"* and *"No
end-to-end customer journey exercise"* — and matches the traceability rows, which record the same
`find` as their own measurement.

## 2. What exists now

Two files, 23 tests, 251 assertions, about 10 seconds:

| file | holds |
|---|---|
| `tests/Feature/Journeys/MerchantJourneyTest.php` | the `P01-G1` exercise, 9 controls (one of them the pin for finding **J-2**), 2 mutation proofs |
| `tests/Feature/Journeys/CustomerJourneyTest.php` | the `P01-G2` exercise, the gateway-blockage measurement, 6 negative controls, 2 mutation proofs, 1 harness premise |

```
$ php artisan test tests/Feature/Journeys

   PASS  Tests\Feature\Journeys\CustomerJourneyTest
  ✓ the customer journey runs end to end as one exercise                 1.89s
  ✓ the online gateway leg is blocked for a named and checkable reason   0.15s
  ✓ control an unknown store slug does not render a storefront           0.17s
  ✓ control a zero quantity writes no cart line                          0.13s
  ✓ control a checkout with no basket writes no order                    0.14s
  ✓ control another shoppers order is not in this shoppers history       0.20s
  ✓ control a return request on an undelivered order writes nothing      0.18s
  ✓ control a second return request writes no second refund              0.24s
  ✓ the exercise notices when the cart stops recording the quantity      0.36s
  ✓ the exercise notices when a cod order is recorded paid at checkout   0.55s
  ✓ premise the admin layout name is shadowed by the stylique theme      0.72s

   PASS  Tests\Feature\Journeys\MerchantJourneyTest
  ✓ the merchant journey runs end to end as one exercise                 1.76s
  ✓ control a registration without a store name provisions nothing       0.12s
  ✓ control a storeless merchant cannot reach the panel                  0.11s
  ✓ control the plan seat cascade can deactivate the subject itself      0.11s
  ✓ control a product create without a category writes nothing           0.12s
  ✓ control a merchant without the permission creates no product         0.12s
  ✓ control an unknown status word does not move the order               0.19s
  ✓ control a delivered order cannot be moved back to confirmed          0.20s
  ✓ control cod is not settled by delivery alone                         0.20s
  ✓ control a foreign merchant cannot accept this stores refund          0.19s
  ✓ the exercise notices when a variant loses the stock that was posted  0.46s
  ✓ the exercise notices when a fulfilment move reports success without… 1.23s

  Tests:    23 passed (251 assertions)
```

Each journey is **one test method**, one application process, one HTTP session. Nothing is inserted
between legs: the product the shopper buys is the product the merchant created through `POST /product`
two legs earlier; the order the merchant fulfils is the one the storefront checkout wrote; the return
the merchant accepts is the one the shopper requested. That is what §G's "not as a sum of task tests"
asks for and what a per-endpoint suite cannot show.

---

## 3. `P01-G1` — the merchant journey, leg by leg

§G step 1: *"create product with variants → receive order → fulfil → return"*.

| leg | driven through | what is asserted |
|---|---|---|
| 0 · register | `POST /register` | `users` row with `type = admin`; `stores` row; `store_wallets` row; `users.current_store` points at it; the `admin` role is attached |
| 1 · the panel opens | `GET /dashboard` | 200. This is the `GAP-264` guard: `HomeController::index()` signs a **storeless** merchant out, so a journey built on a hand-made merchant would die here |
| 2 · a product **with variants** | `POST /product` | `flag = success` (the endpoint answers a refusal with HTTP 200); `products.variant_product = 1`; **two** `product_variants` rows named `Red` and `Blue`, each holding the posted stock (12) and price (75.00) and the right `store_id`; `GET /product` renders |
| 3 · a shopper's order arrives | `POST {slug}/product_cart` then `POST {slug}/process-order` (`payment_type=cod`) | the cart add is not the error shape; an `orders` row exists in this store with a non-zero total at or above the basket; `GET /order` and `GET /order-view/{enc}` render and the detail page **names the order reference** |
| 4 · fulfil | `POST /order-status-change/{id}` (AJAX, the shipped caller's shape) × 4 | `confirmed → 4`, `pickedup → 5`, `shipped → 6`, `delivered → 1`, each read back from `orders.delivered_status`; ≥ 4 `order_notes` rows written |
| 5 · the money | `POST /order-payment-status`, `GET /finance` | delivery alone left `payment_status` unsettled; the merchant's separate settlement act writes `Paid`; `/finance` renders; `store_order_financials` holds one row for the order whose `customer_total` **equals the order's own `final_price`** and whose `store_id` is this store |
| 6 · return | `POST {slug}/order-refund-request/{id}`, `GET /refund-request`, `POST /update-refund-status`, `POST /refund-stock` | an `order_refunds` row exists; the merchant's queue renders; acceptance writes `refund_status = Accept`; restoring stock raises the **variant's** stock above its post-sale figure |

Registration is leg 0 although §G step 1 does not name it, because it is what creates the store, the
wallet, `current_store` and the role — and every later leg depends on all four.

### Negative controls for `P01-G1`

Each breaks exactly one leg through the product's own doors and asserts the journey's assertion goes
the other way, so the green journey is discriminating rather than merely green:

* a registration with no `store_name` provisions nothing;
* a storeless merchant cannot reach `/dashboard` (302 to `/login`, session ended);
* a product create with no `category_id` writes neither a product nor a variant;
* a merchant without `Create Product` creates nothing (Laratrust `isAbleTo`, not `@can`);
* an unknown status word does not move `delivered_status`;
* a **delivered** order cannot be moved back to `confirmed` (owner decision H2);
* marking a COD order delivered changes neither `payment_status` nor `store_wallet_transactions`
  (the 2026-10-24 withdrawal: *"Delivered must never, by itself, mark COD as Paid or credit a
  merchant wallet"*);
* a merchant of another store cannot accept this store's refund.

The ninth, `test_control_the_plan_seat_cascade_can_deactivate_the_subject_itself`, is not a control
on a leg — it pins finding **J-2** in §7.

---

## 4. `P01-G2` — the customer journey, leg by leg

§G step 2: *"browse → cart → checkout (COD and one online gateway) → order history → return request"*.

| leg | driven through | what is asserted |
|---|---|---|
| 1 · browse | `GET {slug}/home`, `GET {slug}/product-list`, `GET {slug}/product/{slug}` | 200 each; the detail page names the product |
| 2 · cart | `POST {slug}/product_cart`, `GET {slug}/cart` | not the error shape; the `carts` row holds the right product and quantity; the cart page renders |
| 3 · checkout — **COD** | `GET {slug}/checkout`, `POST {slug}/process-order` | no flashed `error`; an `orders` row written by the `cod` path, attributed to this shopper, priced at the basket; **`payment_status` is not Paid** (owner decision D-06 and the 2026-10-24 withdrawal); stock decremented by exactly the basket; the basket cleared |
| 3b · checkout — **one online gateway** | — | **NOT EXERCISED. BLOCKED.** See §5 |
| 4 · order history | `GET {slug}/order`, `GET {slug}/order-filter`, `GET {slug}/customerorder/{enc}` | 200 each; the **list partial** names the order reference |
| 5 · visible to both sides | `GET /order`, `GET /order-view/{enc}` as the merchant | 200; the merchant's detail page names the shopper's order |
| 6 · return request | merchant marks delivered, then `GET {slug}/order-refund/{id}?refund=true`, `POST {slug}/order-refund-request/{id}`, `GET {slug}/order-return-list`, `GET /refund-request` | no flashed `error`; an `order_refunds` row filed against this store; the shopper's return list renders; the merchant's queue renders |

### Negative controls for `P01-G2`

* an unknown store slug does not render a storefront;
* a zero quantity writes no cart line;
* a checkout with no basket writes no order, moves no stock, and says why;
* **another shopper's order is not in this shopper's history** — without this, "the order is visible
  in the shopper's history" is satisfied by a page that lists every order in the store;
* a return request on an **undelivered** order writes nothing;
* a **second** return request writes no second refund row (`Order::refund()` is a `hasOne`, so two
  rows make three separate screens read an arbitrary one).

---

## 5. THE ONLINE-GATEWAY LEG IS BLOCKED — the exact reason and the exact unblocker

**It is not exercised, nothing stands in for it, and no capture is faked anywhere in either file.**

§G step 2 names two payment paths. COD is exercised end to end above. The online gateway is not,
because every gateway path in `PaymentService` reaches a provider over the network with merchant
credentials, and this repository holds none — by design. `14-PHASE-01-ACCEPTANCE-CRITERIA.md` §H
already lists *"the Authorize.Net SDK and the Stripe keys are unreachable"* among the controls that
cannot be proven in this environment, and `GAP-022` records the dependency as the same one as
`P1-ORD-13` criterion 4.

### How the blockage is measured rather than asserted around

`CustomerJourneyTest::test_the_online_gateway_leg_is_blocked_for_a_named_and_checkable_reason`
measures the absence of a usable credential, so the arrival of one is **loud**: the moment a gateway
secret lands in the environment, that test fails and tells the reader to run the leg and close the
gap. It reads the real source of gateway credentials — the per-store `settings` rows named in
`PaymentSettingController::getPaymentGateways()` — for 25 secret-half keys, and it carries a
**positive control**: it inserts a `paypal_client_id` row, asserts the probe sees it, removes it, and
only then asserts the empty answer.

> **The first version of that test was vacuous, and that is worth recording.** It read
> `config('services.stripe.secret')` and `config('services.paypal.client_id')`.
> `config/services.php` declares **neither** — Paymera is the only gateway with entries there — so
> the probe compared `null` to `null` and would have gone on passing with a live key in the
> database. This is the "a census can be satisfied by the thing it is supposed to measure" failure
> mode, found by checking the config file instead of trusting the key name.

### What would unblock it

From `docs/security/P01-ORD-13-GATEWAY-TEST-REQUIREMENTS.md` §4 and §7.1, the cheapest sufficient
ask for *one* gateway end to end is all three of:

1. **A provider sandbox credential for one gateway that has an in-code sandbox selector**, written as
   `settings` rows for the store under test — **not** `.env`. The three cheapest, because the
   application already switches environment on a stored field rather than needing a code change:
   | gateway | settings keys | selector in this code |
   |---|---|---|
   | `paypal` | `paypal_client_id`, `paypal_secret_key`, `paypal_mode` | `paypal_mode != 'live'` (`PaymentService.php:939`) |
   | `mercado` | `mercado_access_token`, `mercado_mode` | `mercado_mode != 'live'` (`PaymentService.php:797`) |
   | `iyzipay` | `iyzipay_private_key`, `iyzipay_secret_key`, `iyzipay_mode` | `iyzipay_mode == 'sandbox'` → `https://sandbox-api.iyzipay.com` (`PaymentService.php:1482`) |
2. **Outbound reachability to that provider's sandbox host** from wherever the exercise runs. Not
   verified here for any of the three: this container reaches the network through a proxy with an
   allowlist, and `14-PHASE-01-ACCEPTANCE-CRITERIA.md` §H already records one blocked host.
3. For any path where the **provider** initiates the return rather than the shopper's browser
   replaying it, a **public callback hostname** the sandbox can reach — item **E5** in §7.1, which
   also notes that `PaymentService.php:463-464` hard-codes `https://storesyria.com` as the Paymera
   callback base.

### What is deliberately NOT used as a substitute

`PHASE-01-CLOSURE-EXECUTION.md` records that `App\Khalti\Khalti` is now container-resolved, so "a
test double can drive the handler past the curl call". That is real and useful — it proves the
handler's plumbing — and it is **not** a gateway leg. Driving a doubled client proves nothing about
a provider accepting a payment, and presenting it as the §G step 2 gateway path would be a
fabricated green. `P01-G2` stays partially met.

---

## 6. Failing-first, and both directions

### 6a. The journeys were red first, and every red run was a real measurement

Nine red runs built these two files. Each is recorded in the test file at the line it taught, so
the next reader meets the fact rather than the workaround:

| # | journey | red run | cause |
|---|---|---|---|
| 1 | G2 | cart leg: `Failed asserting that 4 is identical to 2` | the fixture pre-seeded a basket, so the cart leg measured an **increment** instead of a creation. The fixture now seeds none and the journey builds its own basket |
| 2 | G2 | `Call to a member function get() on null` | `$response->getSession()` is null for a `redirect()->back()` in this stack. The flash is read from the session **store** |
| 3 | G2 | history leg: the order reference absent from a 200 page | `front_end/pages/order.blade.php` is a **shell**; the list partial that prints `#{{ $order->product_order_id }}` comes from `GET {slug}/order-filter`. The journey now drives the request the browser really makes |
| 4 | G2 | merchant leg: **HTTP 500**, `Undefined variable $store (View: themes/stylique/views/layouts/app.blade.php)` | the theme view root leaks between requests in one process and shadows the panel's own layout name — see §7, finding **J-1** |
| 5 | G2 | status-change leg: 302, expected 200 | `order_status_change` answers a plain form POST with `redirect()->back()` and AJAX with JSON, so a plain post is a 302 whether it succeeded or was refused. The journey drives the shipped AJAX contract and reads `data.status` |
| 6 | G1 | `/dashboard` → 302 `/login`, "Your Account is de-activate" | `users.is_active` was `'0'` straight out of `/register` — see §7, finding **J-2** |
| 7 | G1 | product create refused: *"No file type is enabled for uploads."* | a fixture that inserted a bare `storage_setting` row stopped `Utility::upload_file()` falling back to `Utility::Setting()`, whose defaults carry the mime list. Half-configuring storage was worse than not configuring it |
| 8 | G1 | add-to-cart refused: *"Product variant is not available."* | the combinations panel posts `variation_option_N[] = enabled` as well as `manage_stock`, and `CartController::product_cartlist` refuses a variant without `enabled` |
| 9 | G1 | `UNIQUE constraint failed: permission_role.permission_id, permission_role.role_id` | the `admin` role is **one shared global row**, so `grantMerchantGlobalRole()` re-giving a permission for a second tenant collides. The journey grants idempotently and keeps the shared-row shape, which is the production shape |

### 6b. Revert-and-fail: four mutations, all four caught

This project's rule is stricter than the vendored TDD skill's — after the change, revert it and
confirm the test fails again — because the failure mode here is a test that passes for the wrong
reason. A journey is unusually exposed to it: twelve HTTP requests could be green while asserting
nothing that matters. So each journey carries mutation proofs **in the suite**:

| test | the behaviour broken | the leg that must notice | result |
|---|---|---|---|
| `MerchantJourneyTest::test_the_exercise_notices_when_a_variant_loses_the_stock_that_was_posted` | every saved `ProductVariant` comes out with `stock = 0` | product leg — "did not keep the stock the merchant typed" | **CAUGHT** |
| `MerchantJourneyTest::test_the_exercise_notices_when_a_fulfilment_move_reports_success_without_arriving` | a save that would set `delivered_status = 1` writes `0` instead | fulfilment leg — "reported success and did not move delivered_status" | **CAUGHT** |
| `CustomerJourneyTest::test_the_exercise_notices_when_the_cart_stops_recording_the_quantity` | every saved `Cart` line comes out with `qty = 1` | cart leg — "does not hold the quantity the shopper asked for" | **CAUGHT** |
| `CustomerJourneyTest::test_the_exercise_notices_when_a_cod_order_is_recorded_paid_at_checkout` | every saved `Order` comes out `payment_status = Paid` | checkout leg — "was recorded PAID at checkout time" | **CAUGHT** |

Each applies the break through a **model event**, re-runs the whole exercise, and asserts it fails
**at the leg that owns that behaviour** — the message is asserted, not merely "something failed", so
a journey that died for an unrelated reason does not read as caught. A model event registered in a
test does not leak into the next one: `InteractsWithTestCaseLifecycle:103` re-points
`Model::setEventDispatcher()` at each fresh application's dispatcher in `setUp`.

Nothing on disk is mutated. `tests/Support/bin/mutate.py` remains the right tool for a behaviour
that cannot be reached any other way; a runtime mutation leaves no window in which a concurrent
reader of the working tree sees broken source, and it lives in the suite as evidence rather than in
a script somebody has to remember to run.

The fourth mutation is the one worth keeping above all the others: *a cash-on-delivery order
counting as paid before the cash is collected* is a defect this codebase has already had once, and a
journey is exactly where it would come back unnoticed.

---

## 7. What the journeys measured — findings

### J-1 · The merchant panel's layout name is shadowed by a storefront file, and the theme view root leaks between requests in one process

`ActiveTheme::handle()` calls `Theme::set($store->theme_id)`, which `prependLocation()`s
`themes/<theme>/views` onto the view finder's path list; `FileViewFinder` then memoises name → path.
`themes/stylique/views/layouts/app.blade.php` **exists**, is a storefront layout that prints
`$store->name` at line 8, and answers to `layouts.app` — which is the **merchant panel's** layout
name.

**Measured:** in a process that had served one storefront request, `GET /order` as a provisioned
merchant answered **HTTP 500** with `Undefined variable $store (View:
themes/stylique/views/layouts/app.blade.php)`. The same request in a process with no prior
storefront request answered 200.

**This is a harness fact, not a production defect.** Production is PHP-FPM: one process serves one
request, so the prepended path does not survive. `app/Http/Middleware/ThemeDraftPreview.php:129-140`
already records the same class of leak in the same words ("Neither applies inside a test, where one
application instance serves many requests").

**The shadowed layout NAME is a standing hazard all the same**, and it is the half that could become
a production defect: any admin surface that ever resolves a theme inside its own request would
render that storefront file and 500. Only `stylique` carries the shadow —
`ls themes/*/views/layouts/app.blade.php` returns one path.

Pinned by `CustomerJourneyTest::test_premise_the_admin_layout_name_is_shadowed_by_the_stylique_theme`,
which asserts both directions: 500 without the reset, 200 with it. If the leak is ever closed that
test fails and says so, rather than leaving a private helper whose removal produces a message
pointing at a file neither journey mentions.

**Nothing was changed in production code for this.** Both journeys call a private
`onTheAdminSurface()` that does `Theme::clear()` plus `view()->getFinder()->flush()` before an admin
request.

#### J-1 UPDATE — THE NAME COLLISION IS FIXED (`GAP-265`). The leak is not, and was not the defect.

The test above did exactly what its last paragraph promised: it **failed and said so** when the
collision was closed, which is how this section came to be rewritten rather than left stale.

**What changed.** `themes/stylique/views/layouts/app.blade.php` is now
`themes/stylique/views/front_end/layouts/app-scaffold.blade.php` — still present, still the unused
non-functional scaffold it always was (its `<body>` is a `// ... existing code ...` placeholder, so
it has no `@yield('content')` and no `$slot` and could never have rendered a page), but inside
`front_end/`, the namespace the storefront renders through and where a theme shadowing a host view
is the intended mechanism rather than a cross-surface trap. Nothing referenced it: all three
themes' `main_file.blade.php` `@extends('front_end.layouts.app')`, a different and working file.

**Measured, both directions, two real requests in one process.** With the file moved, a merchant's
`GET /order` after a storefront request answers **200 without** `onTheAdminSurface()`'s reset. With
the file copied back to the old path, the same request answers **500**. So the fix is behavioural
and not merely a filesystem rename.

**The leak itself is untouched and is not a defect to fix.** `Theme::set()` still prepends and
`FileViewFinder` still memoises, so a theme's `front_end.*` and `components.*` files still bleed
into a later request in the same process. That remains a harness fact for the reason this section
already gives. What is fixed is only the part that crossed from the storefront into the **panel**.

**`onTheAdminSurface()` is kept** at its call sites. It is no longer load-bearing for the layout
name — the premise case asserts precisely that — but it still clears a leak that exists for other
names, and removing it is a separate change with its own measurement.

**Now pinned in two places, and the second is the stronger one.**
`CustomerJourneyTest::test_premise_the_admin_panel_layout_is_no_longer_shadowed_by_a_theme` is the
end-to-end half. `tests/Unit/Ui/ThemeViewsStayInTheStorefrontNamespaceTest` is a **property** test:
no view under any `themes/<t>/views` may shadow a `resources/views` file outside `front_end/` and
`components/`. Fixing the one file closed one instance; the hazard was structural, so the next
`layouts/`, `partials/` or `auth/` file added to a theme would have been the same defect under a
different name. There is deliberately **no allowlist entry for a single path** — an exception for
one file is how the next one gets added.

### J-2 · `User::assignPlan()`'s seat cascade can deactivate the subject itself

`app/Models/User.php:487-519`:

```php
$users = User::where('created_by', '=', $this->id)->where('type', '!=', 'super admin')->get();
// ... foreach: $userCount <= $plan->max_users ? is_active = 1 : is_active = 0
```

The predicate selects rows whose `created_by` equals **the subject's own id**, so a user for whom
`id === created_by` is their own staff. `Auth\RegisteredUserController` hard-codes `created_by => 1`
(:133 and :286). The existing `$subjectIsPlatformOperator` guard exempts `type = 'super admin'`
only — so the one row it does not cover is a **merchant who is id 1**.

**Measured**, with a `plans` row inserted without `max_users` (the column is `string` DEFAULT 0 and
not nullable, so it held `'0'`, and `1 <= '0'` is false):

```
POST /register   -> 302; provisioned: users +1, stores +1, store_wallets +1, admin role attached
users.is_active  -> '0'
GET  /dashboard  -> 302 to /login, flash "Your Account is de-activate,please contact your Administrator."
```

`SuspendedAccountGuard` logging out the account registration had just created, one request later.

**Reachability, stated honestly.** On a seeded install user 1 is the platform operator
(`type = 'super admin'`), so a merchant is never id 1 and this path is **latent**. It is live
wherever the first `users` row is a merchant — a from-zero database, a restored install whose
operator row was recreated, a tenant-only import — and the `max_users` half is live for **any** plan
whose `max_users` is `0`, which is the column default, so any plan inserted or imported without that
field.

**Not fixed here**, because `app/Models/User.php` is outside the files this task owns and the fix is
a product decision as well as a code change: the right predicate is "rows this user created *other
than itself*", and whether a 0-seat plan should deactivate existing staff at all is the owner's call.
**Pinned** by `MerchantJourneyTest::test_control_the_plan_seat_cascade_can_deactivate_the_subject_itself`,
which fails in either direction — if the cascade is corrected it says so and tells the reader to
delete it.

### J-3 · Observations, not defects

* **`routes/customer.php:193` points `place.order` at a method that does not exist.**
  `Route::post('{storeSlug?}/place-order', [OrderController::class, 'place_order'])` — and
  `grep -n 'function place_order' app/Http/Controllers/OrderController.php` returns nothing; the only
  `place_order` in `app/Http/Controllers` is `Api\ApiController::place_order`. The storefront's own
  checkout form posts to `payment.process` (`front_end/common/checkout/checkout_form.blade.php:119`),
  so the route is orphaned rather than broken-in-use. Reported, not touched: `routes/**` is outside
  this task's files.
* **The shopper's order history is an XHR shell.** `GET {slug}/order` renders chrome; the list comes
  from `GET {slug}/order-filter`. Any test that asserts an order's presence on the first URL is
  asserting against the wrong page — it was this file's third red run.
* **The product create endpoint answers a refusal with HTTP 200 and `{flag: 'error'}`**, like
  `ApiResponser::error()` and `Utility::error()`. A status code is never evidence on these surfaces.

---

## 8. Proposed register and traceability wording

Offered for whoever owns `docs/development/MASTER-PLAN-GAP-REGISTER.md` and
`docs/development/MASTER-PLAN-REQUIREMENT-TRACEABILITY.md`. Not written by this task.

### `GAP-020` / `P01-G1`

> **IMPLEMENTED.** `tests/Feature/Journeys/MerchantJourneyTest::test_the_merchant_journey_runs_end_to_end_as_one_exercise`
> drives §G step 1 as one exercise: register → store, wallet, role and `current_store` provisioned →
> `/dashboard` → `POST /product` creating a two-variant product whose rows keep the posted stock and
> price → a shopper's COD order arrives through the real storefront checkout and appears on the
> merchant's list and detail screen → four fulfilment moves read back from `orders.delivered_status`
> → settlement and a `store_order_financials` row whose `customer_total` equals the order's own
> total → the shopper's return request, the merchant's acceptance and the variant restock. Seven
> negative controls and two caught mutations. Evidence:
> `docs/development/phase-1/PHASE-01-G1-G2-JOURNEY-EVIDENCE.md`. **Next action:** none for G1;
> §G step 3 (a cross-tenant attempt at *every* step with all three token classes) is still
> per-endpoint rather than per-journey-step.

### `GAP-022` / `P01-G2`

> **PARTIALLY IMPLEMENTED.** `tests/Feature/Journeys/CustomerJourneyTest::test_the_customer_journey_runs_end_to_end_as_one_exercise`
> drives §G step 2 as one exercise for the **COD** path: browse → add to cart → checkout → the order
> written, stock moved, basket cleared and COD left unsettled per D-06 → order history (page, list
> partial and detail) → the order visible to the merchant → return request and the `order_refunds`
> row. **The online-gateway leg is NOT exercised and remains blocked**: it needs (a) a provider
> sandbox credential written as `settings` rows for the store under test — `paypal`, `mercado` or
> `iyzipay` are cheapest, each having an in-code sandbox selector — (b) outbound reachability to that
> sandbox host, and (c) a public callback hostname for any provider-initiated return (item E5 of
> `docs/security/P01-ORD-13-GATEWAY-TEST-REQUIREMENTS.md` §7.1). The absence of a credential is
> measured by `test_the_online_gateway_leg_is_blocked_for_a_named_and_checkable_reason`, which fails
> the moment one appears. No capture is faked and no test double is offered in its place. Evidence:
> `docs/development/phase-1/PHASE-01-G1-G2-JOURNEY-EVIDENCE.md` §5.

### Two new rows these exercises produced

> **`GAP-J1`** — **CLOSED.** `themes/stylique/views/layouts/app.blade.php` shadowed the merchant
> panel's `layouts.app` and printed `$store->name`. It is now
> `themes/stylique/views/front_end/layouts/app-scaffold.blade.php`, inside the storefront
> namespace, so the name no longer collides; measured 200 without the harness reset where it was
> 500, and 500 again when the file is put back. Pinned by
> `CustomerJourneyTest::test_premise_the_admin_panel_layout_is_no_longer_shadowed_by_a_theme` and,
> as a property over every theme and every name, by
> `tests/Unit/Ui/ThemeViewsStayInTheStorefrontNamespaceTest`. See the J-1 UPDATE above. Carried as
> `GAP-265`, whose register row still describes the OPEN state and needs updating by whoever owns
> `docs/development/MASTER-PLAN-GAP-REGISTER.md`.
>
> **`GAP-J2`** — `User::assignPlan()`'s seat cascade (`app/Models/User.php:487-519`) selects rows
> whose `created_by` equals the subject's own id, so a user who is their own `created_by` is their
> own staff and a plan with `max_users` below 1 deactivates them. Registration hard-codes
> `created_by => 1`; the existing guard exempts only `type = 'super admin'`. Measured: a registered
> merchant who is id 1 came out of `/register` with `is_active = '0'` and was signed out of
> `/dashboard`. Latent on a seeded install, live wherever the first `users` row is a merchant or a
> plan carries the column-default `max_users`. Pinned by
> `MerchantJourneyTest::test_control_the_plan_seat_cascade_can_deactivate_the_subject_itself`.
> Needs an owner decision on the 0-seat rule plus a one-predicate fix.

---

## 9. What is NOT covered, by name

Stated so no reader takes these exercises for more than they are.

1. **One online gateway** — §G step 2's second payment path. Blocked; see §5.
2. **A verified COD cash-collection event.** The 2026-10-24 decision requires one, and
   `cod_collection_events` is still a GATE F draft
   (`database/migrations/_gate_f_pending/0009_draft_create_cod_collection_events_table.php.draft`),
   so no such event can exist. The merchant journey settles the order the way the product leaves
   available today — a human asserting payment through `order_payment_status` — and
   `test_control_cod_is_not_settled_by_delivery_alone` pins that delivery alone does not.
3. **Any WorkDo module.** No module loads in a test run unless the test inserts an `add_on_managers`
   row. Both journeys are the **host** application's paths only — so `PreOrder`, `BoostSales`,
   `FrequentlyProduct`, `SizeGuideline`, `WholesaleProduct` and the rest are unexercised, including
   the two vendored cart writers `PaymentController` records as an owner item (S29).
4. **§G step 3** — a cross-tenant attempt at *every* step of both journeys with a merchant, a
   customer **and a driver** token. The journeys make tenant assertions at several legs (the refund
   row's `store_id`, the ledger row's `store_id`, the foreign-merchant refund control, another
   shopper's history) but do not sweep all three token classes at every step. `P01-G3` stays
   PARTIALLY IMPLEMENTED. The per-leg structure here is what it would be built on.
5. **The storefront API journey.** Both exercises drive the **web** storefront, which is the surface
   a shopper's browser actually uses. The API has its own equivalents —
   `POST api/{slug}/place-order`, `POST api/{slug}/product-return`,
   `POST api/{slug}/return-order-list` — and they are **not** driven here as a journey; they are
   covered per-endpoint in `tests/Feature/Commerce` and `tests/Feature/Security`. The web path was
   chosen because CLAUDE.md records that no client in either repository calls the storefront API,
   so it is the path a real shopper takes. An API-side journey is buildable on the same structure
   and is a separate piece of work.
6. **MySQL.** These run on SQLite `:memory:` per `phpunit.xml`. Nothing in them is schema-sensitive
   in the way `P01-SEC-13` was — they assert rows and HTTP answers, not column affinity — but §G
   step 9 is a separate gate and this is not it.
7. **Arabic (RTL).** Both journeys run in the default locale. §G step 8 is its own gate, and
   `BilingualCartCheckoutHarnessTest` and the `tests/Unit/Ui` storefront suites hold that half.
8. **Concurrency.** One request at a time. The replay and race questions belong to
   `WebCheckoutIntakeIdempotencyTest` and the MySQL harness in `tests/Support/Security/bin/`.

---

## 10. How to re-run

```bash
php artisan test tests/Feature/Journeys                      # both journeys, 23 tests, ~10s
php artisan test tests/Feature/Journeys/MerchantJourneyTest.php   # P01-G1
php artisan test tests/Feature/Journeys/CustomerJourneyTest.php   # P01-G2
```

Nothing outside `tests/Feature/Journeys/` was changed to make these pass. They write no file: the
product images go to `Storage::fake('theme')` and the temporary uploads are unlinked in `tearDown`.
Both files live in the Feature suite, so `.github/known-test-outcomes.json` is unaffected — they are
declared nowhere because they pass, and that is the point.
