# Phase 06 — what already exists, measured

**Created 2026-11-24.** Six areas, each mapped by an independent reader working READ-ONLY against this
checkout, and each map then handed to a SECOND reader instructed to refute it. This document is the
surviving content of those maps, with the refutations folded in beside what they corrected.

## Why it exists, and what it is not

`P06-01` records that **no Phase 06 source document exists**: every `P06-*` requirement text in the
traceability matrix is DERIVED from one-line summaries in `CLAUDE.md` §4 and `PROJECT-STATE.md` §2, not
owner-authored. So this is not a design and not a plan. It is the answer to "what is already here", so
that the fifty-one questions in `PHASE-06-OWNER-DECISION-PACKAGE.md` can be asked against facts rather
than against a guess, and so that Phase 06 does not rebuild something that ships.

**Every `where` below is a file and line in this checkout and can be read.** The command logs behind each
claim are not in the repository; the pointers are, which is what a reader needs to disagree.

**One map was REFUTED outright** (area F, scope coverage) and five were `SOUND_WITH_CORRECTIONS`. A
refutation that found a real error is recorded under *What the refutation corrected* in that area, in the
refuter's own terms. Where a correction contradicts the map, the correction is what this document says.

---

## Area A — SaaS subscriptions, plans and subscription billing

**Rows:** `P06-02` · **Buildable without an owner decision:** **NO** · **Refutation verdict:** `SOUND_WITH_CORRECTIONS` (10 refutations, 7 omissions found)

### What exists (18 items)

- **`plans` table — the plan definition, including the four per-plan limits and nine feature flags.
  24 live columns measured at runtime: id, name, price, description, duration, themes, max_stores,
  max_products, max_users, storage_limit, enable_domain, enable_subdomain, enable_chatgpt,
  pwa_store, shipping_method, enable_tax, created_at, updated_at, trial, trial_days, is_disable,
  modules, lynomia_agent_seats, lynomia_campaign_sends. `price` is `string` (migration :17
  `$table->string('price')->default(0)`) — the matrix's "plans.price is a string column" is
  CONFIRMED. All four limit columns are also `string`. `max_stores`/`max_products`/`max_users`
  default to `0`, and `0` is a REAL limit of zero under every gate (`0 < 0` false, `0 == -1`
  false).**
  `database/migrations/2023_12_08_123752_create_plans_table.php:14-32;
    database/migrations/2024_03_13_062916_add_is_disable_to_plans_table.php:15-17 (trial,
    trial_days, is_disable);
    database/migrations/2024_04_10_031608_add_modules_column_in_plans_table.php` — *first-party*

- **`lynomia_agent_seats` and `lynomia_campaign_sends` on `plans` — two per-plan allowances added by
  a module, not by the core. They are the ONLY per-plan limits with a null-aware "says nothing"
  semantic (`$plan->lynomia_agent_seats === null` ⇒ grant nothing, not zero).**
  `packages/workdo/LynomiaChat/src/database/migrations/2026_10_16_000200_add_lynomia_agent_seats_to_
    plans.php:34; .../2026_10_23_000100_add_lynomia_marketing_allowance.php:38; readers at
    packages/workdo/LynomiaChat/src/app/Services/SeatAllowanceSync.php:121-125 and
    MarketingAllowanceSync.php:143-147; writer at packages/workdo/LynomiaChat/src/app/Http/Controlle
    rs/SuperAdmin/IntegrationController.php:411-416, :547-552` — *VENDORED*

- **`plan_orders` table + `PlanOrder` model — the subscription purchase record. 23 live columns
  incl. `card_number`, `card_exp_month`, `card_exp_year`, `coupon`, `coupon_json`, `discount_price`,
  `payment_status`, `payment_type`, `receipt`, `user_id`, `store_id`, `is_refund`. Model is
  `Cachable`. Carries three static aggregates: `total_plan_price()` (UNUSED — grep across app/
  resources/ tests/ returns only the definition), `total_orders()` = bare `PlanOrder::count()`, and
  `total_coupon_used()`.**
  `database/migrations/2023_12_11_102124_create_plan_orders_table.php:14-39;
    database/migrations/2024_03_13_111849_add_is_refund_to_plan_orders_table.php;
    app/Models/PlanOrder.php:9-41` — *first-party*

- **143 plan-related routes, 130 of them first-party `App\Http\Controllers\*`. 13 are vendored
  `Workdo\LandingPage\...PricingPlanController` / `MarketPlaceController@pricing_plan_*` — those are
  LANDING-PAGE marketing pricing blocks, a different resource from the SaaS `plans` table.**
  `routes/web.php:536-760 (the plan block); full census saved at /tmp/claude-0/-home-
    user/c22d1090-9e12-5711-882b-d4cba4c5ce19/scratchpad/p06_subs/plan_routes.txt` — *first-party*

- **39 first-party plan-payment gateway controllers carry the `GuardsPlanActivation` trait (the
  three plan-purchase money rules: fail-closed provider gate, server-owned price, replay no-op). 28
  files call `assertPlanActivationEnabled()` for 26 distinct provider keys.**
  `app/Http/Controllers/Concerns/GuardsPlanActivation.php:48-512 (trait); 39 `use
    GuardsPlanActivation;` sites listed by grep; gate at :66-96; credentials-present guard at :140;
    `serverOwnedPlanPrice()` at :195; replay refusal at :477` — *first-party*

- ****ALL 26 `plan_activation` providers are DISABLED at runtime in this environment, so 26 plan-
  purchase gateways answer 403 before any write.** The remaining open plan-purchase rails are the 18
  "provider-verified" controllers (Tap, Cashfree, Benefit, Paynow, Paytm, Xendit, CinetPay,
  Powertranz, CyberSource, Mollie, Stripe, AuthorizeNet, Braintree, Flutterwave, Khalti, NMI,
  PayPal, Paystack) plus BankTransfer, which is operator-approved.**
  `config/payments.php:77-603 (`plan_activation`, 26 `env('PLAN_ACTIVATION_*', false)` entries);
    :604-665 (`plan_activation_provider_verified`, 18 entries); :672-674
    (`plan_activation_platform_approved`, 1 entry)` — *first-party*

- **`plan_purchase_intents` table + `PlanPurchaseIntent` service — a durable, server-owned intent
  binding (reference UNIQUE, provider, user_id, plan_id, coupon_id/coupon_code, expected_amount
  DECIMAL(16,4), currency, status, consumed_at, plan_order_id, provider_txn_id, failure_reason).
  Consumption is a single conditional UPDATE from `pending`, so two concurrent callbacks cannot both
  win. Bound today for **two** providers only (Cashfree, Mollie); Benefit is deliberately BLOCKED
  for want of a captured tap charge response.**
  `database/migrations/2026_10_31_000200_create_plan_purchase_intents_table.php:108-120+ (and its
    100-line docblock); app/Services/PlanPurchaseIntent.php:67;
    tests/Feature/Security/PlanIntentBoundProvidersTest.php:78-81 (the provider data set)` — *first-
    party*

- **Plan coupons: `plan_coupons` (id, name, code, discount, limit, description, is_active) +
  `plan_user_coupons` (id, user_id, coupon_id, order). `PlanCouponController` is gated by
  `PlatformOperatorOnly` on the **resource route**, with its merchant-permission `isAbleTo()` checks
  deliberately left behind the gate. `apply-coupon` (the AJAX price preview) is deliberately outside
  the gate.**
  `database/migrations/2023_12_11_100854_create_plan_coupons_table.php,
    .../2023_12_11_101905_create_plan_user_coupons_table.php; app/Models/PlanCoupon.php,
    app/Models/PlanUserCoupon.php; app/Http/Controllers/PlanCouponController.php:1-320 (87-line
    docblock recording the pre-fix defect); routes/web.php:571-572 (gate), :578 (apply-coupon
    exempt)` — *first-party*

- **Per-plan limits ARE ENFORCED at six first-party creation gates and four vendored ones — they are
  not merely stored. Products: ProductController.php:710 and :1071, WoocomProductController.php:107,
  ShopifyProductController.php:146, Api/DashboardController.php:3149. Stores:
  StoreController.php:132, Api/DashboardController.php:7987. Users: UserController.php:106. Storage:
  Utility::updateStorageLimit at app/Models/Utility.php:863-874 (refuses when `$plan->storage_limit
  <= $total_storage && != -1`), called e.g. at ProductController.php:701. Vendored readers:
  AuctionProduct:172/:487, DigitalProduct:127.**
  `app/Http/Controllers/ProductController.php:710, :1071;
    app/Http/Controllers/StoreController.php:132; app/Http/Controllers/UserController.php:106;
    app/Models/Utility.php:863-874; app/Http/Controllers/Api/DashboardController.php:3149, :7987;
    packages/workdo/AuctionProduct/src/app/Http/Controllers/AuctionProductController.php:172, :487;
    packages/workdo/DigitalProduct/src/app/Http/Controllers/DigitalProductController.php:127` —
    *first-party*

- **A SECOND limit-enforcement mechanism in `User::assignPlan()`: on every plan assignment it re-
  evaluates the tenant's users and stores against the new plan and flips `is_active` 1/0 on the
  excess, by arbitrary collection order. There is NO products counterpart — nothing deactivates
  products on a downgrade.**
  `app/Models/User.php:284 (assignPlan), :361-385 (users), :387-404 (stores)` — *first-party*

- **Trial handling: `plans.trial` / `plans.trial_days`, `users.trial_plan` /
  `users.trial_expire_date`. Server-side trial eligibility IS enforced (one trial per user,
  permanently — `trial_plan` is never reset anywhere), and the handler refuses a plan with `trial !=
  1` or empty `trial_days`.**
  `app/Http/Controllers/PlanController.php:396-448 (planTrial, with a 15-line comment recording that
    the three conditions previously lived only in resources/views/plans/index.blade.php:296);
    app/Models/User.php:298-300 (assignPlan clears trial_expire_date, deliberately NOT trial_plan);
    database/migrations/2024_03_13_065856_add_trial_plan_to_users_table.php:17-18` — *first-party*

- **Operator subscription administration: `Route::resource('plan')` (CRUD, authorized inside the
  controller by `isAbleTo('Manage|Create|Edit Plan')`); `plan.destroy` is operator-only via
  `UserPolicy::isOperator()` IN the controller; `plan.order.refund` and
  `order_approve`/`order_reject` are operator-only via `PlatformOperatorOnly` route middleware;
  `plan/orders/show/{id}` is gated by a dedicated `PlanOrderViewGate` middleware resolving a `view`
  Gate on the PlanOrder; manual grant at `StoreController::activePlan` (operator-only by `type ==
  'super admin'`).**
  `routes/web.php:536, :548-550, :571-572; app/Http/Controllers/PlanController.php:309-333 (destroy,
    with a 15-line comment recording the measured privilege escalation it closed), :469-512 (refund
    — idempotent, order↔user ownership check, DB::transaction, AuditLog);
    app/Http/Controllers/BankTransferController.php:142-186 (orderapprove — idempotent,
    transactional, audited), :189-200+ (orderreject refuses an Approved order);
    app/Http/Middleware/PlanOrderViewGate.php:34-61;
    app/Http/Controllers/StoreController.php:1159-1211` — *first-party*

- **Plan-change request workflow: `plan_requests` table, `PlanRequestController`
  (index/userRequest/cancelRequest/acceptRequest), `PlanRequestDataTable`, `users.requested_plan`.
  Accepting writes a `PlanOrder` with `payment_type = 'Manually'`, `payment_status = 'succeeded'`
  and no money.**
  `database/migrations/2023_12_11_100414_create_plan_requests_table.php;
    app/Http/Controllers/PlanRequestController.php:17-134;
    app/DataTables/PlanRequestDataTable.php:32-107; routes/web.php:552-555` — *first-party*

- **A first-party PUBLIC pricing page that reads the real `plans` rows (not hardcoded copy),
  filtered to `is_disable = 1` (the column is INVERTED: 1 means enabled), plus
  `PlatformCapabilities::planHighlights()` which renders the four limits and five feature flags with
  -1 ⇒ Unlimited, 0 ⇒ the literal zero, null/'' ⇒ "unspecified".**
  `app/Http/Controllers/PlatformSiteController.php:130-150 (publishedPlans);
    app/Support/Platform/PlatformCapabilities.php:240-300 (planHighlights + limit());
    resources/views/platform/pricing.blade.php:39; route `platform/pricing` carries only
    `App\Http\Middleware\SetLocate` — no auth` — *first-party*

- **Admin (Flutter) subscription read surface: `GET api/admin/indexAllPlan`, behind `custom.auth` +
  `AdminApiLog`, authorizing the store by OWNERSHIP via `resolveOwnedStore($storeId)`. Returns the
  current plan, its limits with used/remaining/percentage, feature flags, and an expiry status
  machine (`active` / `expiring_soon` ≤7 days / `expired` / `unlimited`).**
  `app/Http/Controllers/Api/DashboardController.php:8034-8160+; route uri `api/admin/indexAllPlan`,
    middleware `api ; App\Http\Middleware\CustomAuth ; App\Http\Middleware\AdminApiLog`` — *first-
    party*

- **Plan/PlanOrder/PlanRequest/PlanCoupon/PlanUserCoupon are **PLATFORM-GLOBAL by design, not
  tenant-scoped**, and that is pinned by tests. `grep -n 'Plan' config/tenancy.php` returns NOTHING
  — no Plan* model is in `scoped_models`, so `StoreScope::isActiveFor()` short-circuits and adds no
  predicate for any principal. Four of the five are in `BulkDeleteTenantGuard::PLATFORM_GLOBAL`,
  which refuses the bulk-delete addon endpoint for them.**
  `config/tenancy.php:141 (`scoped_models` opens; no Plan entry anywhere in the file);
    app/Http/Middleware/BulkDeleteTenantGuard.php:140, :155-157, :227, :235-236;
    tests/Feature/Tenancy/PlatformGlobalAndEscapeHatchTest.php:105 (`'plans' => [Plan::class]`)` —
    *first-party*

- **Substantial first-party test coverage. `php artisan test --testsuite=Feature
  --filter='Plan|Subscription'` → **498 passed (1,933 assertions)**, 65s, zero failures.
  `--testsuite=Unit --filter='Plan|Subscription'` → **17 passed (139 assertions)**. Contract → "No
  tests found." Named plan files and their test-method counts: PlanPurchaseIntentTest 23,
  SubscriptionAuthorizationTest 31, PlanPaymentFailClosedTest 18, PlanActivationReplayGuardTest 11,
  PlanOrderRailTest 10, PlanPaymentNewlyGuardedTest 10, PlanCouponRefusalPersistsNothingTest 9,
  ReturnHandlerPlanInitialiserTest 9, PlanPaymentVerificationInventoryTest 7,
  PlanIntentBoundProvidersTest 7, PlanOrderApprovalLocaleTest 7, PlanPaymentBypassTest 7,
  PlanPaymentCardholderDataTest 7, PlanPaymentTypeTest 8 (Unit), PlanPaymentPostureTest 5,
  PlanPaymentInitiatorAvailabilityTest 5, OrderTotalUsesTheStoresOwnPlanTest 5,
  PaymentWallPlanOrderIntegrityTest 4, BenefitPlanRailFailureReasonTest 3,
  PlanPaymentFreePlanRecordTest 2. Plus two MySQL-only evidence scripts:
  tests/Support/Security/bin/verify-plan-coupon-refusal-ordering-on-mysql.php and verify-plan-
  purchase-intent-on-mysql.php.**
  `tests/Feature/Security/Plan*.php, tests/Feature/Security/SubscriptionAuthorizationTest.php,
    tests/Feature/Commerce/*Plan*.php, tests/Unit/Payments/PlanPaymentTypeTest.php,
    tests/Support/Security/bin/` — *first-party*

- **The `$plan` guard the task asked about — MEASURED, and it is NOT a plan-purchase control. It is
  the COMMERCE-side read of the store owner's plan for shipping/tax at checkout. Guard landed on 7
  handlers (not 5): AuthorizeNet::getOrderPaymentStatus, Khalti::getOrderPaymentStatus,
  NMI::getNMIProductStatus, PaymentController::whatsapp, PaymentService::getProductStatus,
  Api\ApiController::place_order, Api\ApiController::ordersave. HELD_BACK on 5 by owner ruling
  D-13/D-18: PaymentController::getProductStatus, PaiementPro, CinetPay, Easebuzz, PayU (all
  `::getProductStatus`). The HELD_BACK list is a ratchet in BOTH directions — landing the guard on
  one without moving it to LANDED fails the test.**
  `tests/Feature/Commerce/ReturnHandlerPlanInitialiserTest.php:17-89 (the 73-line docblock),
    :100-112 (const LANDED, 7 entries), :114-129 (const HELD_BACK, 5 entries), :131+ (const
    SHAPE_COUNTS discovery ratchet); app/Support/Commerce/StorePlan.php:65 (`ownerOf`)` — *first-
    party*

### Traps — things that read as working and do not

- **TRAP 1 — `POST /plan-disable` has NO authorization check whatsoever, and no test covers it.**
  `PlanController::planDisable` (app/Http/Controllers/PlanController.php:378-394) reads
  `$request->id` and `$request->is_disable` and runs `Plan::where('id',
  $request->id)->update(['is_disable' => $request->is_disable])`. The only guard is a referential
  check (`User::where('plan_id', $request->id)->first() != null && $request->id != 1 &&
  $request->is_disable == 0` → refuse). Route middleware, read from the live router: `Authenticate ;
  XSS ; SetLocate ; EmailIsVerified` — no `PlatformOperatorOnly`, no `isAbleTo`. So ANY
  authenticated email-verified user (registration is open) can enable or disable any platform plan.
  Disabling a plan removes it from the merchant list (`PlanController::index:27` shows a merchant
  only `is_disable = 1`), from the PUBLIC pricing page (PlatformSiteController.php:145), from the
  Flutter current-plan read (`indexAllPlan:8064` filters `is_disable = 1`), and
  `StoreController::activePlan:1175` refuses an upgrade to it. `grep -rn 'planDisable|plan-disable'
  tests/` returns 0. Contrast `PlanController::destroy` immediately above it, which was given an
  explicit operator check for exactly this class of problem.

- **TRAP 2 — `GET /request-cancel/{id}` has NO authorization check and writes another user's row.**
  `PlanRequestController::cancelRequest` (app/Http/Controllers/PlanRequestController.php:54-63):
  `$user = User::find($id); $user['requested_plan'] = '0'; $user->update();
  PlanRequest::where('user_id', $id)->delete();`. The id is the URL segment — no ownership test, no
  operator test, no permission test. Live middleware: `Authenticate ; XSS ; SetLocate ;
  EmailIsVerified`. Three consequences: any authenticated user can cancel any other merchant's
  pending plan request; `User::find($id)` returning null makes `$user['requested_plan']` a fatal;
  and `PlanRequest::where('user_id', $id)->delete()` is an unscoped cross-tenant delete. The ONLY
  existing record of this route is StateChangingGetInventoryTest.php:148, which notes the GET verb
  and says nothing about authorization. Compare `::userRequest` (:26-53), which correctly derives
  the user from `auth()->user()`.

- **TRAP 3 — `api/admin/indexAllPlan` divides by zero on the SHIPPED column default, and the
  exception escapes the catch.** Api/DashboardController.php:8120 `round(($usedProducts /
  $maxProducts) * 100, 2)`, :8126 and :8132 the same for stores and users. Each is guarded only by
  `== -1 ? 0 : ...`, so `max_products == 0` reaches the division. `plans.max_products`, `max_stores`
  and `max_users` all `->default(0)` (migration :21-23), and PlatformCapabilities' own docblock
  (app/Support/Platform/PlatformCapabilities.php:257-263) already records that 0 is a REAL limit of
  zero, not unlimited. The handler's outer catch is `catch (\Exception $e)` (~:8182). I verified on
  this container's PHP: `php -r` with `5 / 0` → "NOT an Exception; class=DivisionByZeroError" on PHP
  8.3.6, and `DivisionByZeroError` extends `\Error`, so it is NOT caught — the admin Flutter plan
  screen gets an uncaught 500. No test drives it.

- **TRAP 4 — the enforced limit and the reported limit count DIFFERENT THINGS, on both products and
  users.** Products ENFORCED: `ProductController.php:618` `$total_products = $user->countProducts()`
  and `User::countProducts()` (app/Models/User.php:425) is `Product::where('created_by',
  creatorId())->where('store_id', getCurrentStore())->count()` — PER STORE, matching the form label
  "Maximum Products Per Store" (resources/views/plans/create.blade.php:57). Products REPORTED:
  `indexAllPlan:8068` `Product::where('created_by', $user->id)->count()` — TENANT-WIDE, no
  `store_id`. Users ENFORCED: `UserController.php:102` excludes BOTH `super admin` AND `admin`.
  Users REPORTED: `indexAllPlan:8070-8072` excludes only `super admin`. So a multi-store tenant sees
  a "used/remaining" figure in the Flutter app that the creation gate does not use, in two of the
  three limits. Whether per-store or per-tenant is the intended rule is a policy question, not an
  engineering one.

- **TRAP 5 — three `plans` attributes are read that NO COLUMN BACKS, so they silently evaluate to
  null.** (a) `app/Models/User.php:324` `$oldplan->custom_plan == 1` inside `assignPlan()` —
  `custom_plan` is absent from the runtime column listing, so the custom-plan module-merge branch
  can never be taken (already noted at app/Http/Controllers/Concerns/GuardsPlanActivation.php:519
  and PaytmPaymentController.php:720). (b) `PlanRequestController.php:81` `$price =
  $plan->{$plan_request->duration . '_price'}` — i.e. `Month_price` / `Year_price` /
  `Unlimited_price`, none of which exist; `$price` is then never used. (c)
  `BankTransferController.php:104` `$order->price = isset($coupons) ? $plan->discounted_price :
  $plan->price` — `discounted_price` is not a column either, but here it IS set as a dynamic
  attribute earlier at :59 (`$plan->discounted_price = false`) and :66, so this one happens to work.
  The pattern is the hazard: a `plans` attribute read in this area may or may not exist, and
  `Schema::getColumnListing('plans')` is the only way to tell.

- **TRAP 6 — `plan_requests.duration` is captured, displayed, and then IGNORED on accept.**
  `PlanRequest` stores `duration` (migration :18), `PlanRequestDataTable` renders it, and
  `PlanRequestController.php:80` calls `$user->assignPlan($plan_request->plan_id,
  $plan_request->duration)` — but `User::assignPlan($planID)` (app/Models/User.php:284) takes ONE
  parameter. PHP silently discards the extra argument to a userland method, so the requested
  duration never reaches the expiry computation; `assignPlan` uses `$plan->duration` instead
  (:301-315). `BankTransferController.php:168` does the same (`assignPlan($order->plan_id,
  $plan->duration)`). A merchant who requests an annual term on a monthly-duration plan gets a
  monthly expiry, with no error.

- **TRAP 7 — `assignPlan()` reads the DEFAULT WEB GUARD, not `$this`.** app/Models/User.php:286
  `$user = \Auth::user();` and then :324 `$user->active_module`. The method's subject is `$this`
  everywhere else. On any path where `assignPlan` runs without a populated web session — a gateway
  notify/IPN, a queued job, an artisan command — `$user` is null and the module-merge read is a
  null-property access. 9 of the 39 gateway controllers call `assignPlan` inside their return
  handlers, and some of those routes are `Route::any`.

- **TRAP 8 — `BankTransferController::planPayWithbank` does nothing at all without a receipt file.**
  app/Http/Controllers/BankTransferController.php:19-116: the plan lookup, the coupon pricing, the
  `PlanUserCoupon` write and the `PlanOrder` creation are ALL nested inside `if
  ($request->payment_receipt)` (opened at :44, closed at :114). The
  `$request->validate(['payment_receipt' => 'image'])` at :22-28 is itself conditional on the same
  truthiness, so nothing makes the file required server-side. A POST without the file falls off the
  end of the method and returns null — an empty HTTP 200, no order, no message. I did not drive this
  over HTTP, so the response code is inferred from Laravel's handling of a null controller return,
  not measured.

- **TRAP 9 — the plan coupon's `limit` is PLATFORM-WIDE, and exhausting it deactivates the coupon
  for everybody.** `PlanCoupon::used_coupon()` (app/Models/PlanCoupon.php:21-24) is
  `hasMany(PlanUserCoupon::class,'coupon_id','id')->count()` with no user predicate.
  `planPayWithbank:90-93` then sets `$coupons->is_active = 0` once `limit <= used`.
  GuardsPlanActivation.php:109-112 already records the consequence it fixed for CoinGate: "One
  merchant retrying an unconfigured gateway drives the count to `limit` and deactivates the coupon
  **for everybody**." Whether `limit` means per-coupon-total or per-user is a policy question. Also
  `planPayWithbank` runs TWO coupon lookups (:64 with `is_active = '1'`, :80 WITHOUT it) and the
  second dereferences `$coupons->discount` at :82 one line BEFORE its own `if (!empty($coupons))` at
  :84.

- **TRAP 10 — the operator dashboard's "Total Coupon" card prints a NAME, not a count.**
  resources/views/superadmin/dashboard.blade.php:42-43 `<x-dashboard-card :count="$couponName"
  :label="__('Total Coupon')" ... :url="route('plan-coupon.index')" />`, and `$couponName` is set in
  HomeController.php:258-265 to the name of the most-USED plan coupon. **Conversely, the card the
  task described as mislabelled HAS ALREADY BEEN FIXED — this is a REFUTATION of the task premise.**
  The card now reads `__('Total Plan Orders')`, not "Total Orders" (:37-38), with a 6-line comment
  at :31-36 explaining exactly why the old label was wrong. Do not re-fix it.

- **TRAP 11 — three operator plan figures have no payment-state predicate and one is unbounded.**
  `PlanOrder::total_orders()` is `PlanOrder::count()`, `total_plan_price()` is
  `PlanOrder::sum('price')` (app/Models/PlanOrder.php:30-37), and `Plan::most_purchese_plan()`
  (app/Models/Plan.php:80-94) does `PlanOrder::pluck('plan_id','id')` — loading EVERY plan_orders
  row into PHP memory — then `array_count_values`. None filters `payment_status` (which takes
  'Pending', 'Approved', 'succeeded', 'Rejected' across the writers I read) and none filters
  `is_refund`. So "Total Plan Orders" and "Popular Plan" count unpaid, rejected and refunded
  subscriptions. `total_plan_price()` is called from nowhere, so no money figure is currently wrong
  on screen — but the method is sitting there to be used.

- **TRAP 12 — `Plan`, `PlanOrder`, `PlanRequest`, `PlanCoupon` and `PlanUserCoupon` are ALL
  `Cachable`.** Any raw `DB::table('plans')->update()` leaves Eloquent reads, route-model binding
  included, serving the pre-write row, and under `CACHE_STORE=array` it persists for the rest of the
  test. Worse for this area specifically: `PlanController::update:225` `$plan->update($post)`
  already flushes the WHOLE cache store, and the 46-line comment at :226-271 records the measured
  consequence — on production's non-taggable `database` store that flush is `Cache::flush()` over
  everything, which erases in-flight `gateway-return-claim:*` payment replay claims taken with
  `Cache::add()`, and an erased claim reads as an UN-TAKEN claim. A plan edit therefore weakens the
  duplicate-capture guard on customer payments. The `Artisan::call('optimize:clear', ['--except' =>
  'cache'])` at :272 narrows one of the two flushes, not the erasure.

- **TRAP 13 — `Crypt` is the id transport on four plan paths and it is an ENCODING, not a control.**
  `PlanController::planPrepareAmount:339` `Crypt::decrypt($request->plan_id)`, `::planTrial:399`,
  `PlanRequestController::userRequest:30`, `BankTransferController::planPayWithbank:30`,
  `StoreController::upgradePlan:1161` `decrypt($user_id)`. APP_KEY is in git history and unrotated
  (CLAUDE.md), so an encrypted plan id in a URL is forgeable. This is precisely the reasoning the
  `plan_purchase_intents` migration's docblock gives for existing (:48-56). Any new plan-purchase
  path must bind the plan through the intent, not through a `Crypt` parameter.

### Tenancy

**Plans are deliberately platform-global, not tenant-scoped, and that is correct here — but it means
  the global scope provides ZERO isolation for this area and every control must be explicit.**
  Measured: `grep -n 'Plan' config/tenancy.php` returns nothing, so none of `Plan`, `PlanOrder`,
  `PlanRequest`, `PlanCoupon`, `PlanUserCoupon` is in `scoped_models`; `StoreScope::isActiveFor()`
  short-circuits for a model absent from that allowlist, so no predicate is added for any principal
  — not a merchant, not the operator. This is pinned as INTENDED by
  tests/Feature/Tenancy/PlatformGlobalAndEscapeHatchTest.php:105 (`'plans' => [Plan::class]`, two
  passing cases: "carries no store scope" and "stays visible to every tenant"). `plan_orders` DOES
  carry a `store_id` column (migration :36, indexed) and `plan_coupons` carries none at all.
  `BulkDeleteTenantGuard` lists `plan-order`, `plan-request`, `plan-coupon`, `plan-coupon-detail` in
  `PLATFORM_GLOBAL` (:227, :235-236) and `plan-order` additionally in a second set (:220), refusing
  the bulk-delete addon endpoint for them — and its own docblock at :212-220 says `plan-order` is
  there DESPITE having a `store_id`, "it is the record of ... the platform operator". So isolation
  for this area rests entirely on four explicit mechanisms, each of which I read: (1)
  `PlatformOperatorOnly` route middleware — on `plan-coupon.*` (7 routes), `refund/{id}/{user_id}`,
  `order_approve/{id}`, `order_reject/{id}`; (2) `PlanOrderViewGate` middleware + a `view` Gate on
  `plan/orders/show/{id}`; (3) in-controller `UserPolicy::isOperator()` (`PlanController::destroy`)
  or the string comparison `type == 'super admin'` (`PlanRequestController::acceptRequest`,
  `StoreController::activePlan`, `StoreController::upgradePlan`); (4) `resolveOwnedStore($storeId)`
  ownership on the Flutter endpoint. Where none of those four is present, there is no isolation at
  all — see the two handlers under `traps`. `plan_orders.store_id` is written inconsistently by the
  manual-grant paths: `PlanRequestController::acceptRequest` hardcodes `'store_id' => 1` (:108, with
  the correct `$user->current_store` commented out at :107), while `StoreController::activePlan`
  writes `getCurrentStore()` — the OPERATOR's current store, not the beneficiary's.

### Authorization

**Mixed, and the mix is itself a hazard.** Permission strings in play, all four created only by
  `Utility::addNewData()`: `Manage Plan`, `Create Plan`, `Edit Plan`, `Manage Plan Request`
  (app/Models/Utility.php:2879-2882 and :3351-3354 grant all four; :3135-3136 grants only `Manage
  Plan` + `Manage Plan Request`). There is no `Delete Plan` permission anywhere. Per CLAUDE.md's
  measured rule, `isAbleTo()` ALONE REFUSES THE PLATFORM OPERATOR (Laratrust, `permissions_as_gates
  => false`, no super-admin bypass), so every gate below that is `isAbleTo()`-only admits the
  operator only insofar as the `super admin` ROLE carries the name. **Gates that are
  `isAbleTo()`-only, with no operator-first test:** `PlanController::index` (:22, `Manage Plan`),
  `::create` (:40, `Create Plan`), `::store` (:60, `Create Plan`), `::edit` (:110, `Edit Plan`),
  `::update` (:130, `Edit Plan`), `PlanRequestController::index` (:19, `Manage Plan Request`),
  `PlanCouponController::index/create/store/edit/update/destroy` (merchant coupon names, but now
  sitting BEHIND the route-level `PlatformOperatorOnly`). Note `PlanController::index` tests
  `isAbleTo('Manage Plan')` FIRST and only then branches on `type == 'super admin'` at :24 — so the
  operator must pass the Laratrust check before the operator branch is reachable. **Gates that
  correctly test the operator:** `PlanController::destroy` (:313, `!
  \App\Policies\UserPolicy::isOperator($actor)`, with a 15-line comment recording the measured
  escalation it closed — a merchant holding view-only `Manage Plan` could DELETE platform plans);
  `PlanRequestController::acceptRequest` (:67, `type == 'super admin'`);
  `StoreController::upgradePlan` (:1160) and `::activePlan` (:1172), same form. **Route-level
  operator gates:** `plan-coupon` resource + `refund/{id}/{user_id}` + `order_approve/{id}` +
  `order_reject/{id}` carry `App\Http\Middleware\PlatformOperatorOnly`; `plan/orders/show/{id}`
  carries `App\Http\Middleware\PlanOrderViewGate`. **An asymmetry worth flagging:**
  `PlanRequestController::index` is `isAbleTo('Manage Plan Request')` while `::acceptRequest` is
  `type == 'super admin'` — so a delegated permission-holder can LIST requests but cannot act on
  them, and the operator's ability to list depends on the seeded role carrying the name. **Two
  handlers have NO authorization check of any kind** (see `traps` 1 and 2). The Flutter endpoint
  `api/admin/indexAllPlan` authorizes by store OWNERSHIP (`resolveOwnedStore`) behind `custom.auth`,
  and refuses with 403; note that `custom.auth` admits a merchant, a driver or a customer token
  (CLAUDE.md), so the ownership check is doing all the work.

### What the suite already covers, and what it cannot reach

Substantial and real, not nominal. **Feature: 498 passed (1,933 assertions) in 65.25s** for `php
  artisan test --testsuite=Feature --filter='Plan|Subscription'`, zero failures. **Unit: 17 passed
  (139 assertions)** for the same filter. **Contract: "No tests found."** Twenty dedicated plan
  files, test-method counts measured by `grep -c 'public function test_'`:
  tests/Feature/Security/SubscriptionAuthorizationTest.php 31, PlanPurchaseIntentTest.php 23,
  PlanPaymentFailClosedTest.php 18, PlanActivationReplayGuardTest.php 11, PlanOrderRailTest.php 10,
  PlanPaymentNewlyGuardedTest.php 10, PlanCouponRefusalPersistsNothingTest.php 9,
  PlanPaymentVerificationInventoryTest.php 7, PlanIntentBoundProvidersTest.php 7,
  PlanOrderApprovalLocaleTest.php 7, PlanPaymentBypassTest.php 7, PlanPaymentCardholderDataTest.php
  7, PlanPaymentInitiatorAvailabilityTest.php 5, PlanPaymentPostureTest.php 5,
  PlanPaymentFreePlanRecordTest.php 2, PlanPaymentTestCase.php 0 (base class);
  tests/Feature/Commerce/ReturnHandlerPlanInitialiserTest.php 9,
  OrderTotalUsesTheStoresOwnPlanTest.php 5, PaymentWallPlanOrderIntegrityTest.php 4,
  BenefitPlanRailFailureReasonTest.php 3; tests/Unit/Payments/PlanPaymentTypeTest.php 8. Plus two
  out-of-suite MySQL evidence scripts: tests/Support/Security/bin/verify-plan-coupon-refusal-
  ordering-on-mysql.php and verify-plan-purchase-intent-on-mysql.php. **WHAT IS NOT COVERED,
  measured by grep over tests/: `plan-disable` / `planDisable` → 0 hits. `request-cancel` /
  `cancelRequest` → exactly 1 hit, tests/Feature/Security/StateChangingGetInventoryTest.php:148, and
  that entry records only the VERB problem ("plan request workflow on a GET -- owner decision"), not
  the missing authorization.** Nothing drives plan EXPIRY behaviour: no test exercises
  SetLocate.php's expired branch. Nothing drives `indexAllPlan` with a zero limit. Nothing drives
  `planPayWithbank` without a receipt. Three ratchets exist and must be respected by any change:
  `ReturnHandlerPlanInitialiserTest::HELD_BACK` (fails if the guard is landed on a held handler
  without moving the const), its `SHAPE_COUNTS` per-file census of the `type == 'admin') { $plan =
  Plan::find(` shape, and `PlanPaymentPostureTest` which pins `config('payments.plan_activation')`'s
  key set exactly — so adding or removing a provider key fails CI until somebody reads it.

### The vendored constraint here

**Almost none of this area is vendored, which is the single most important finding for planning.**
  The guard's scope is exactly `packages/workdo/`, with LynomiaChat exempted (.github/scripts/guard-
  no-workdo-changes.sh:3, :19-20; wired at .github/workflows/ci.yml:199). Everything that implements
  P06-02 lives OUTSIDE that tree and is freely editable: `database/migrations/*plan*` (6 files),
  `app/Models/{Plan,PlanOrder,PlanRequest,PlanCoupon,PlanUserCoupon}.php`, `app/Http/Controllers/{Pl
  anController,PlanRequestController,PlanCouponController,BankTransferController}.php`, all 39
  gateway controllers, `app/Http/Controllers/Concerns/GuardsPlanActivation.php`,
  `app/Services/PlanPurchaseIntent.php`, `app/Support/Commerce/StorePlan.php`,
  `app/Support/Platform/PlatformCapabilities.php`, `app/Http/Middleware/{SetLocate,PlanOrderViewGate
  ,PlatformOperatorOnly,BulkDeleteTenantGuard}.php`, `config/payments.php`, `config/tenancy.php`,
  `routes/web.php`, `resources/views/plans/*`, `resources/views/plan-coupon/*`,
  `resources/views/plan_request/*`, `resources/views/superadmin/dashboard.blade.php`,
  `resources/views/platform/pricing.blade.php`. These originate from upstream WorkDo eCommerceGo but
  have been absorbed into the first-party tree, so "vendored" in this project's own sense does not
  apply to them. What IS vendored and therefore off-limits: (a) the two `plans.lynomia_*` allowance
  columns and their sync services + SuperAdmin/IntegrationController — but LynomiaChat is the ONE
  exempted module, so it is editable; (b) four vendored modules that READ `$plan->max_products` to
  gate their own creation paths — AuctionProduct (:172, :487), DigitalProduct (:127) — so a change
  to the products-limit SEMANTICS must keep those two readers working without editing them; (c) 13
  `Workdo\LandingPage` `pricing_plan_*` routes, a separate marketing resource that must not be
  confused with `plans`; (d) `vendor/laravel/cashier-paddle` (composer-managed, not the workdo
  guard). Practical consequence: P06-02 needs almost no wrapping, overriding or container re-binding
  — it can be built directly. But the module blindness still bites the TESTS: no WorkDo module loads
  in a test run without an `add_on_managers` row, so a products-limit change will not be exercised
  against AuctionProduct or DigitalProduct unless the test enables them through the production
  loader.

### What the refutation corrected

- **One refutation of this map is deliberately NOT reproduced here.** It concerns a plan-purchase
  payment rail and is unremediated, so describing it in a public repository would publish an
  exploitable detail. It is handled under the confidential **PAY-SEC** process with the owner and is
  recorded in `docs/security/P01-SECURITY-WORK-QUEUE.md` by reference only, as `P1-SEC-91`'s closing
  section states. Nothing in Phase 06 depends on it, and nothing in Phase 06 may be built on the
  assumption that it is closed.

- **The map said:** "28 files call `assertPlanActivationEnabled()` for 26 distinct provider keys" —
  evidence given as "`grep -rn \"assertPlanActivationEnabled('\" app/Http/Controllers/` → 28 call
  sites, 26 distinct keys".
  **Wrong because:** The same command returns 30 LINES, not 28, and THREE of them are prose inside
    docblocks, not calls — exactly the census-reads-comments failure CLAUDE.md records as having
    been hit four times on 2026-11-21. Real calls = 27; distinct FILES with a real call = 26;
    distinct keys = 26 (that part is right). No reading of the grep yields 28. The map also never
    explains the 39-vs-gate gap: 13 trait carriers make no gate call (AuthorizeNet, Benefit,
    Cashfree, CinetPay, CyberSource, Mollie, Paynow, PayPal, Paystack, Paytm, Powertranz, Tap,
    Xendit) and 26 + 13 = 39, which is the real structure of the trait population.
  **Measured by:** `grep -rn "assertPlanActivationEnabled('" app/Http/Controllers/ | wc -l` → 30;
    the 3 prose lines are PayHerePaymnetController.php:44, PayHerePaymnetController.php:61,
    PaymentWallPaymentController.php:198 (isolated with `grep -vE '\);\s*$'`); real calls `grep -E
    '\);\s*$' | wc -l` → 27; files with a real call `| cut -d: -f1 | sort -u | wc -l` → 26; the 13
    gateless carriers by `comm -23` of the trait-carrier list against the real-call file list

- **The map said:** TRAP 3: the divide-by-zero is "guarded only by `== -1 ? 0 : ...`, so
  `max_products == 0` reaches the division", with `plans.max_products` `->default(0)` cited as the
  trigger — and `not_established` item 3 files "whether `plans.max_products == 0` is a real
  condition on the owner's production data" as the thing that decides whether the bug is live or
  latent.
  **Wrong because:** The map cited the migration default and MISSED THE LINE IN THE HANDLER THAT
    MAKES THE TRIGGER ROUTINE. Api/DashboardController.php:8074 is `$maxProducts = $currentPlan ?
    (int) $currentPlan->max_products : 0;` (and :8075, :8076 the same for stores and users), where
    `$currentPlan = Plan::where('id',$user->plan_id)->where('is_disable',1)->first()` at :8064-8066.
    So $maxProducts is 0 whenever $currentPlan is NULL — i.e. whenever the merchant holds no
    plan_id, or whenever their plan row's is_disable != 1. No unusual column value and no production
    data question is required. Worse, this COMPOSES with the map's own TRAP 1: because /plan-disable
    has no authorization at all, any authenticated email-verified user can flip is_disable on any
    plan, which instantly turns every merchant on that plan into an uncaught DivisionByZeroError
    (HTTP 500) on the Flutter current-plan screen. The map filed the decisive condition under
    not_established when it is on line 8074 of the file it was reading.
  **Measured by:** `awk 'NR>=8064 && NR<=8076' app/Http/Controllers/Api/DashboardController.php`
    printing :8064-8066 ($currentPlan with the is_disable=1 filter) and :8074-8076 (the `? ... : 0`
    fallbacks); division sites confirmed at :8120/:8126/:8132 by `sed -n '8115,8135p'`; error class
    by `php -r 'try{5/0;}catch(\Exception $e){...}catch(\Throwable $t){echo get_class($t);}'` →
    DivisionByZeroError, parents ArithmeticError,Error

- **The map said:** vendored_constraint: "`database/migrations/*plan*` (6 files)"
  **Wrong because:** There are TEN plan-related migrations in database/migrations/, and all ten
    contain 'plan' in the filename so the stated glob matches all ten. The map also contradicts
    itself: its own `what_exists` section cites nine of these ten by name. A planner trusting '6
    files' would under-scope the migration surface.
  **Measured by:** `ls database/migrations/ | grep -i plan | nl` → 10: create_plans_table,
    create_plan_requests_table, create_plan_coupons_table, create_plan_user_coupons_table,
    create_plan_orders_table, add_is_disable_to_plans_table, add_trial_plan_to_users_table,
    add_is_refund_to_plan_orders_table, add_modules_column_in_plans_table,
    create_plan_purchase_intents_table

- **The map said:** "143 plan-related routes, 130 of them first-party `App\Http\Controllers\*`. 13
  are vendored `Workdo\LandingPage\...`"
  **Wrong because:** All three counts are wrong. I ran the same command (the JSON is byte-identical
    at 496,627 bytes, so the input is the same) and applied the same documented filter
    (uri/name/action containing 'plan'), both case-sensitively and case-insensitively: 145 total,
    133 first-party, 12 Workdo, 0 closures/other. The vendored rows are 12, not 13 (5
    MarketPlaceController@pricing_plan_* under marketplace/{slug}/* plus 7 PricingPlanController
    resource routes).
  **Measured by:** `php artisan route:list --json` → 496627 bytes (identical to the map's stated
    size); `python3 -I rcheck.py` → 'TOTAL plan-related routes: 145 / first-party action: 133 /
    Workdo action: 12'; `python3 -I rcheck2.py` (case-insensitive on all three fields) → 145 / 12 /
    133 / other: []

- **The map said:** routes row: "all but four carry Authenticate ; XSS ; SetLocate ;
  EmailIsVerified. The FOUR EXCEPTIONS carry only App\Http\Middleware\ActiveTheme ;
  App\Http\Middleware\ThemeLanguage and therefore NO auth:" followed by a list of FIVE URIs.
  **Wrong because:** Internally contradictory, and the count is wrong in a security claim. Measured:
    SIX plan routes lack Authenticate — the five web ones the map then lists (plan-get-cybersource-
    status, plan-pay-with-cybersource, plan-get-ozow-status/{plan_id}, plan-pay-with/ozow,
    plan/company/senangpay) plus api/admin/indexAllPlan (which has CustomAuth instead). So 'all but
    four' is wrong twice over. Three things the map also left unestablished about its own finding:
    those five skip XSS and EmailIsVerified as well, not just Authenticate; plan-get-cybersource-
    status is a Route::any (GET|HEAD|POST|PUT|PATCH|DELETE|OPTIONS), not a GET; and the consequence
    differs by controller — CyberSourceController reaches `$authuser->assignPlan($plan->id)` at :280
    from `$authuser = \Auth::user()` at :149 with no null guard, so for an anonymous caller this is
    a null-dereference 500 rather than an activation bypass, while OzowController:193 and
    SenangPayController:129 do call the (currently disabled) gate and 403 first. The map presents
    all five as one undifferentiated 'NO auth' fact.
  **Measured by:** `python3 -I rcheck3.py` over route:list JSON → 'plan routes WITHOUT Authenticate
    middleware: 6', each printed with its action and full middleware array; `grep -n
    'assignPlan|Auth::user()|assertPlanActivationEnabled'
    app/Http/Controllers/{CyberSourceController,OzowController,SenangPayController}.php` →
    CyberSource :53/:149/:280 with NO gate call; Ozow :193 gate, :281 assignPlan; SenangPay :129
    gate, :214 assignPlan

- **The map said:** "Per-plan limits ARE ENFORCED at six first-party creation gates and four
  vendored ones"
  **Wrong because:** Both counts disagree with the map's own enumeration in the same sentence and
    with measurement. Its own list names 9 first-party sites (ProductController:710, :1071,
    Woocom:107, Shopify:146, Api/DashboardController:3149, StoreController:132,
    Api/DashboardController:7987, UserController:106, Utility:870) and 3 vendored sites in 2 files
    (AuctionProduct:172, :487, DigitalProduct:127). A full grep confirms exactly that: 9 and 3, not
    6 and 4. Every individual citation is correct — only the totals are wrong.
  **Measured by:** `grep -rn 'max_products|max_stores|max_users|storage_limit' --include=*.php app/
    packages/` with migration paths and comment-only lines filtered out; each gate then read in
    context

- **The map said:** TRAP 8: "the plan lookup, the coupon pricing, the `PlanUserCoupon` write and the
  `PlanOrder` creation are ALL nested inside `if ($request->payment_receipt)` (opened at :44, closed
  at :114)"; TRAP 9: "the second dereferences `$coupons->discount` at :82 one line BEFORE its own
  `if (!empty($coupons))` at :84".
  **Wrong because:** The structural conclusions are RIGHT but the line numbers are not, and two
    further null-return paths were missed. The main block opens at :42, not :44 — there is no `if`
    at :44 — and closes at :114, with a redundant THIRD `if($request->payment_receipt)` nested at
    :46-53 that the map never mentions. Separately, `if ($plan)` at :59 closes at :113, so even WITH
    a receipt a falsy $plan falls off the end of the method at :115 returning null — a second
    silent-null path the map did not find. For TRAP 9, `$coupons->discount` is dereferenced at :81
    and the `if (!empty($coupons))` guard is at :83 (two lines later, not one), and the unfiltered
    second lookup is at :80. The defects are real; a reader going to :44, :82 or :84 to fix them
    lands on the wrong lines.
  **Measured by:** control-structure extraction over `awk 'NR>=19 && NR<=120'
    app/Http/Controllers/BankTransferController.php` with real line numbers, showing if-opens at
    :21, :38, :42, :46, :54, :59, :62, :65, :69, :79, :83, :90 and closes at :28, :41, :113, :114,
    :115; coupon lines by `awk 'NR>=62 && NR<=95' | grep -E 'coupons|discount|limit'` → :80 lookup
    without is_active, :81 `$coupons->discount`, :83 `if (!empty($coupons))`

- **The map said:** requirement_ids: "GAP-210 ... This is the `$plan` guard the task asked about"
  and what_exists: "NOTE: the task's 'five handlers' is the HELD_BACK count; the LANDED count is 7,
  per the test's own const."
  **Wrong because:** The map resolved an ambiguity in its own favour without disclosing that the
    authority it cites says the opposite. GAP-REGISTER.md:349 reads '...and the guard is landed on
    only five of ten handlers' — the register attributes 'five' to LANDED, not to HELD_BACK. The
    current code is as the map states (LANDED = 7 at :100-110, HELD_BACK = 5 at :122-128), so the
    register is STALE; but the correct report is 'the register says five landed and is out of date',
    not 'the five refers to held-back'. A planner reconciling the register against the test needs to
    know the register row itself must be corrected.
  **Measured by:** `awk 'NR==349' docs/development/MASTER-PLAN-GAP-REGISTER.md` → '| `GAP-210` |
    `P04-P5-03` | 05 | **The owner's hold on the `$plan` fix (Phase 02 Decision Register D-13 /
    D-18) rests on an assumption P4-F014 made false, and the guard is landed on only five of ten
    handlers.**'; `awk 'NR>=98 && NR<=132'
    tests/Feature/Commerce/ReturnHandlerPlanInitialiserTest.php` → LANDED 7 entries :100-110,
    HELD_BACK 5 entries :122-128

**And 7 things the map did not say at all:**

- app/Models/PlanOrder.php:12-29 — `$fillable` holds 16 of the table's 23 columns. `email`,
  `coupon`, `coupon_json` and `is_refund` are NOT mass-assignable, and no Eloquent strict mode is
  set (`grep -rn 'preventSilentlyDiscardingAttributes|shouldBeStrict' app/Providers/` → no output),
  so they are silently discarded. PlanRequestController.php:90, :97, :98 pass exactly `email`,
  `coupon`, `coupon_json` to `PlanOrder::create()` and all three are dropped. Harmless today because
  all three are null there, but any Phase 06 code recording a real plan coupon through
  `PlanOrder::create()` loses it with no error. (`is_refund` is safe only because
  PlanController.php:496 sets it as a property, not by mass assignment.) The map listed all 23
  columns and never compared them to $fillable.

- app/Http/Controllers/PlanRequestController.php:100 and :108 — the SAME array literal passed to
  `PlanOrder::create()` contains `'store_id' => null` at :100 and `'store_id' => 1` at :108. PHP
  keeps the last, so store_id = 1. The map reported ':108 hardcodes store_id => 1' and the commented
  `$user->current_store` at :107, but missed the duplicate key: deleting :108 silently falls back to
  null, not to current_store, which is a trap for exactly the fix the map's tenancy section implies.

- app/Http/Controllers/PlanController.php:65-68 and :140-152 — all four per-plan limits are
  validated as `required|numeric` only. No `min:-1`, no `integer`. So 0 is accepted and so is any
  arbitrary negative (e.g. -5) that is NOT the -1 sentinel. A plan with max_products = -5 fails `==
  -1`, fails `$total < -5`, and refuses every creation; in Api/DashboardController.php:8120 it
  divides by -5 and reports a negative percentage. The map asserts '0 is a REAL limit of zero under
  every gate' and builds TRAP 3 and TRAP 4 on it, but never checked the validation that lets 0 and
  -5 in.

- app/Http/Controllers/HomeController.php:572-578 — a fifth per-plan limit reader the map's
  'six/four gates' census does not list: the merchant dashboard storage percentage, `$storage_limit
  = ($user->storage_limit / $plan->storage_limit) * 100;`. Notably it DOES guard the divisor at :577
  (`if ($plan && $plan->storage_limit > 0)`) — the exact guard Api/DashboardController.php:8120
  lacks. It is an in-repo precedent for fixing TRAP 3, and the map found neither the reader nor the
  contrast.

- app/DataTables/PlanRequestDataTable.php:32-33, :35, :56-74, :106-107 — a third place the per-plan
  limit semantics are encoded, rendering and FILTERING max_products/max_stores with the `-1 ⇒
  Unlimited` convention (including `$subQuery->where('max_products','-1')` at :61 and :72). The map
  mentioned this DataTable only for `duration`, so a change to limit semantics would miss its filter
  predicates.

- app/Policies/RolePolicy.php:280-283 — the map says flatly 'There is no `Delete Plan` permission
  anywhere'. The permission row genuinely does not exist, but the STRING is live in a 13-entry
  platform-operator-name denylist used by `grantPermission()` (:287-295), and RolePolicy's own
  docblock already records the discrepancy: 'one of its thirteen entries, `Delete Plan`, matches no
  Permission row at all, so the list is not a reliable enumeration of anything'. A Phase 06
  authorization change touching plan permissions needs that denylist, which the map does not name.

- docs/development/MASTER-PLAN-REQUIREMENT-TRACEABILITY.md:4979 — the authority row the map quotes
  from says '~37 plan-payment gateway routes'. Measured there are 145 plan-related routes of which
  ~117 are gateway initiate/return. The map implicitly supersedes the figure by measuring its own,
  but never flags that the matrix's own number is refuted, so the stale figure stays in the
  authority a planner will read next.

### Not established — stated as such rather than guessed

- Whether the seeded `super admin` ROLE actually carries `Manage Plan`, `Create Plan`, `Edit Plan`
  and `Manage Plan Request` at runtime. I located the three permission-name blocks inside
  `Utility::addNewData()` (app/Models/Utility.php:2879-2882, :3135-3136, :3351-3354) and
  PlanCouponController's docblock asserts that :3126-3129 grants the coupon names to `super admin`,
  but I did not seed a database and assert `$operator->isAbleTo('Manage Plan')` by execution. This
  matters: if the role does not carry them, the operator cannot reach `/plan`, `/plan/create` or
  `/plan-request` at all, since those gates are `isAbleTo()`-only. It is a one-test premise check
  and should be the first thing Phase 06 runs.

- Whether `BankTransferController::planPayWithbank` posted WITHOUT a `payment_receipt` really
  returns an empty HTTP 200. I established the brace structure by reading :19-116 and confirmed the
  order-creating code is entirely inside `if ($request->payment_receipt)`, but I did not drive the
  route. The Blade form may mark the field required client-side, which would make this unreachable
  in the UI though not over HTTP.

- Whether `plans.max_products == 0` is a real condition on the owner's production data, which is
  what decides whether TRAP 3's divide-by-zero is live or latent. `0` is the shipped column default
  and PlatformCapabilities' docblock says a plan left at the default 'sells no products at all', but
  counting such rows needs authorised read-only production access, which I do not have.

- The exact per-provider enablement intent. I measured that all 26 flags are `false` in THIS
  environment with no `.env` overrides. The owner's production `.env` may set some to true, which
  would change the plan-purchase posture materially. `grep -c PLAN_ACTIVATION .env` → 0 here; that
  says nothing about the deployed file.

- Whether `docs/discovery/07-SAAS-GAP-ANALYSIS.md` capability 10 holds other subscription findings I
  have not re-measured. The matrix's own `Next action` instructs a re-measure of that document, and
  I measured the two findings it names by title (expiry enforcement — STILL TRUE;
  `order_approve/{id}` authorization — CONFIRMED FIXED, `PlatformOperatorOnly` on the route,
  idempotent, transactional, audited). I did not open the discovery document and work through the
  rest of capability 10.

- Whether the vendored AuctionProduct and DigitalProduct products-limit gates are reachable in any
  test run. They read `$plan->max_products` at AuctionProductController.php:172/:487 and
  DigitalProductController.php:127, but no WorkDo module loads without an `add_on_managers` row, so
  the suite is structurally blind to them. I did not insert such a row.

- Whether `resources/views/plans/stripe.blade.php` (the merchant pay page, ~3,400 lines by its own
  line references in config/payments.php:69-70) contains further plan-purchase logic. I read only
  the limit-display fragments at :402-424 and relied on config/payments.php's cited line numbers
  (:3228, :3275, :3364) for the minor-units inconsistency. The page itself is unmapped.

- P06-05's matrix claim that `SuperAdmin/IntegrationController` 'is on a different branch and a
  different project' appears REFUTED on this branch —
  packages/workdo/LynomiaChat/src/app/Http/Controllers/SuperAdmin/IntegrationController.php exists
  here and writes per-plan allowances (:411-416, :547-552). I did not check its routes or whether
  the module is enabled, and P06-05 is not my area, so I report this only as a lead for whoever maps
  it.

---

## Area B — Support

**Rows:** `P06-03` · **Buildable without an owner decision:** **NO** · **Refutation verdict:** `SOUND_WITH_CORRECTIONS` (4 refutations, 6 omissions found)

### What exists (18 items)

- **THE MATRIX ROW IS STALE AND MUST BE CORRECTED FIRST. P06-03 says "no first-party support surface
  was found". There are TWO first-party support-ticket surfaces, 19 routes, 2 tables, 2 models, 1
  controller, 9 storefront handlers, 7 views and 10 test files. The row's own next action ("ask the
  owner before inventorying Helpdesk") is sound; its factual claim is not.**
  `docs/development/MASTER-PLAN-REQUIREMENT-TRACEABILITY.md:4984 vs app/Models/SupportTicket.php:10`
    — *first-party*

- **FIRST-PARTY DESK #1 — CUSTOMER -> MERCHANT. `support_tickets` (ticket_id, order_id, customer_id,
  title, description, attachment, status, store_id, created_by, timestamps) and
  `support_conversions` (ticket_id, description, attachments, sender, customer_id, store_id,
  timestamps). Models both use HasFactory + Cachable + BelongsToStore.**
  `database/migrations/2024_01_25_072330_create_support_tickets_table.php:14-28;
    database/migrations/2024_01_25_072513_create_support_conversions_table.php:14-25;
    app/Models/SupportTicket.php:10-12; app/Models/SupportConversion.php:10-12` — *first-party*

- **Merchant panel controller with EXACTLY FIVE methods: index (17), edit (36), conversion_store
  (53), destroy (127), ticket_status_change (142). `create`, `store`, `show`, `update` DO NOT
  EXIST.**
  `app/Http/Controllers/SupportTicketController.php:14-159` — *first-party*

- **FIVE of the ten merchant routes point at those missing methods, so they raise
  BadMethodCallException (HTTP 500): support_ticket.create, .store, .show, .update, and
  support_ticket.view (ticket-view/{id} -> @show). This is already measured and PINNED as a declared
  census set, so it is a known, tracked condition, not a new find.**
  `tests/Feature/Security/CatalogueEndpointIntegrityTest.php:136-139 (DEAD_ROUTE_ACTIONS) and :361
    (test_host_app_routes_pointing_at_missing_methods_match_the_declared_set)` — *first-party*

- **`edit(SupportTicket $support,$id)` and `destroy(SupportTicket $support,$id)` take two parameters
  against one-parameter resource routes. This is NOT a defect: the route parameter is named
  {support_ticket}, which does not match $support, so implicit binding does not fire and Laravel's
  dependency resolver constructs an empty SupportTicket; $id then takes the route value. Discovery
  calls this out as a fault — on this point Discovery overstates.**
  `app/Http/Controllers/SupportTicketController.php:36 and :127; routes/web.php:1311` — *first-
    party*

- **DataTable for the merchant list, with a hand-written tenant predicate
  `where('support_tickets.store_id', getCurrentStore())` joined to customers.**
  `app/DataTables/SupportTicketDataTable.php:71` — *first-party*

- **Merchant views: list (13 lines, just <x-datatable>), reply/edit screen (11,479 bytes), row-
  action cell gated by @permission('Replay Support Ticket') and @permission('Delete Support
  Ticket').**
  `resources/views/support/index.blade.php:1-17; resources/views/support/edit.blade.php;
    resources/views/support/action.blade.php:2,9` — *first-party*

- **FIRST-PARTY DESK #1, STOREFRONT HALF — nine shopper handlers: support_ticket (list),
  add_support_ticket, support_ticket_store, edit_support_ticket, update_support_ticket,
  destroy_support_ticket, attachmentDestroy, reply_support_ticket, ticket_reply.**
  `app/Http/Controllers/AccountProfileController.php:737, 750, 770, 860, 879, 952, 967, 990, 1003` —
    *first-party*

- **The shopper ownership predicate, and it is the right shape: `SupportTicket::withoutGlobalScopes(
  )->where('id',$id)->where('customer_id',$customer->id)->where(store_id = $store->id OR store_id IS
  NULL)`, with the store taken from the ROUTE slug, not from getCurrentStore(). Eight of the nine
  storefront handlers route through it (or through shopper()).**
  `app/Http/Controllers/AccountProfileController.php:1420-1431 (ownedTicket), :1391-1404 (shopper),
    :1438-1441 (shopperAttachmentDir)` — *first-party*

- **Both ticket models are in the tenancy allowlist, with the reason recorded:
  SupportTicketController.php:133 deletes by bare id and "the model has NO where('store_id', ...)
  filter anywhere in the codebase".**
  `config/tenancy.php:151-162 (App\Models\SupportTicket::class at :155,
    App\Models\SupportConversion::class at :162)` — *first-party*

- **FIRST-PARTY DESK #2 IS NOT A DESK — LynomiaChat, the one editable module, is a CUSTOMER-
  CONVERSATION INBOX PROXIED TO AN EXTERNAL SERVICE. 133 files, 99 live routes. It has the only
  assignment and status primitives in the codebase (`Assign Lynomia Chat`, POST .../assign, POST
  .../status) and the only live operator surface in this area (16 `super-admin/lynomia-
  integration/*` routes behind PlatformOperatorOnly). Conversations are NOT stored here:
  MerchantInboxGateway builds an AgentApiClient over LynomiaHttp and `lynomia_inbox_events` holds
  "identifiers and nothing else".**
  `packages/workdo/LynomiaChat/src/app/Http/Controllers/InboxController.php:34-36;
    .../Services/MerchantInboxGateway.php:44-53; .../Models/LynomiaInboxEvent.php:8-26;
    .../Http/Middleware/EnsureStoreOwnsLink.php:44-82` — *first-party*

- **WHY LYNOMIA LOADS AND HELPDESK DOES NOT, measured at the loader: `$staticAddons =
  ['LandingPage', 'LynomiaChat']` are registered in register(); everything else waits for an
  add_on_managers row inside app->booted().**
  `app/Providers/PackageServiceProvider.php:31-37 (static), :40-53 (loadDynamicAddons)` — *first-
    party*

- **VENDORED DESK — packages/workdo/Helpdesk, 70 files, and it is MERCHANT <-> PLATFORM OPERATOR,
  not customer->merchant. Its own marketplace copy says so: "designed to facilitate seamless
  communication between businesses and super admins". `helpdesk_tickets` has NO store_id — it is
  user_id / business_id / created_by. HelpdeskTicket uses HasFactory + Cachable ONLY: no
  BelongsToStore, and it is absent from config/tenancy.php.**
  `packages/workdo/Helpdesk/src/database/migrations/2024_09_19_091732_create_helpdesk_tickets_table.
    php:15-29; packages/workdo/Helpdesk/src/app/Models/HelpdeskTicket.php:10-18;
    packages/workdo/Helpdesk/src/database/seeders/MarketPlaceSeederTableSeeder.php:25` — *VENDORED*

- **The vendored Helpdesk DOES have an operator menu and an operator-visibility branch — the only
  support-shaped operator design that exists anywhere. SuperAdminMenuListener adds Helpdesk >
  Tickets > System Setup; HelpdeskController::index shows ALL tickets when type == 'super admin' and
  `where('user_id', own id)` otherwise; its seeder grants 18 helpdesk permissions to the `super
  admin` role and 7 to the `admin` (company) role.**
  `packages/workdo/Helpdesk/src/app/Listeners/SuperAdminMenuListener.php:15-50;
    packages/workdo/Helpdesk/src/app/Http/Controllers/HelpdeskController.php:26,38-41,91-101;
    packages/workdo/Helpdesk/src/database/seeders/PermissionTableSeeder.php:20-49` — *VENDORED*

- **FIRST-PARTY COMPENSATING CONTROLS OVER THE VENDORED DESK — five artefacts, all host-side, none
  editing packages/workdo/: (a) 8 ownership rules in the route manifest; (b) the guard that enforces
  them, appended to the `web` middleware group so it is global; (c) 2 Blade overrides that print
  ticket rich text through RichText::clean(); (d) 3 container bindings replacing the module's
  DataTables with escaping subclasses; (e) the allowlist sanitizer itself.**
  `app/Support/ModuleRouteManifest.php:301,312,343,344,662,663,664,667;
    app/Http/Middleware/ModuleAdministrativeRouteGuard.php:111-126,261-280; bootstrap/app.php:290;
    resources/views/vendor/helpdesk/helpdesk_ticket/show.blade.php:1 and ticket_reply.blade.php:1;
    app/Providers/AppServiceProvider.php:39-45; app/DataTables/SafeHelpdeskData.php,
    SafeHelpdeskCategoryData.php, SafeHelpdeskStatusData.php; app/Support/Html/RichText.php` —
    *first-party*

- **TEST COVERAGE EXISTS AND I EXECUTED IT. 30 cases passed, 797 assertions, 7.65s:
  SupportTicketStoredXssTest (7 methods) + HelpdeskTicketOwnershipTest (17 methods). Ten further
  test files touch these surfaces: AnonymousAdministrativeModuleRoutesTest (38),
  PlatformUploaderDoorsTest (33), BulkDeleteAuthorizationTest (28), StorefrontAccountOwnershipTest
  (17), TenantRootSinksTest (18), EnforcedModelIsolationTest (16), CustomerAccountBoundaryTest (12),
  HelpdeskStoredXssTest (14), ModuleAdministrativeRouteGuardTest (7), DataTableRawColumnsTest (6),
  HelpdeskRawSinksTest (hash-pins 3 vendored DataTables and counts the module's `{!! !!}`).**
  `tests/Feature/Security/SupportTicketStoredXssTest.php;
    tests/Feature/Security/HelpdeskTicketOwnershipTest.php;
    tests/Unit/Packages/HelpdeskRawSinksTest.php:46-48` — *first-party*

- **DISCOVERY ALREADY MEASURED MOST OF THIS, INDEPENDENTLY, AND THE P06-03 ROW DOES NOT CITE IT. Two
  rows name both desks, with the same findings I reached: the operator exclusion at :19, the five
  existing methods, the dead resource routes, "ticket_status_change has no permission check", "The
  platform owner cannot see merchant tickets here at all", and the stated gap "Working
  show/edit/delete; SLA; assignment; super-admin view" (HIGH/P1).**
  `docs/discovery/03-FEATURE-MATRIX.md:147 (Support tickets, customer to merchant) and :148
    (Helpdesk, merchant to platform)` — *first-party*

- **NO API SURFACE. The Flutter admin app and the storefront API have no support-ticket or helpdesk
  endpoint at all.**
  `routes/api.php` — *first-party*

### Traps — things that read as working and do not

- THE P06-03 ROW IS WRONG ON ITS FACTS AND A BUILDER WHO TRUSTS IT WILL REBUILD WHAT EXISTS. It says
  "no first-party support surface was found". There are 19 live first-party ticket routes, 2 tables,
  2 models and 7 views. Correct the row before planning from it. Its NEXT ACTION, by contrast, is
  right and is why this answer's buildable flag is false.

- DISCOVERY IS STALE IN THE SAFE DIRECTION AND MUST NOT BE RESTATED AS CURRENT.
  docs/discovery/03-FEATURE-MATRIX.md:147 cites `routes/web.php:839` (now :1311) and claims
  ticket_status_change has "no tenant scope" — FALSE at this head: config/tenancy.php:155 allowlists
  SupportTicket, so the scope does filter for a merchant. :148's anonymous-Helpdesk-enumeration
  finding is now covered by app/Support/ModuleRouteManifest.php:343 plus the global guard. Re-
  measure before quoting.

- THE OPERATOR EXCLUSION IS A STRING COMPARISON THAT READS BACKWARDS. SupportTicketController.php:19
  is `type != 'super admin'` and the permission check sits INSIDE that branch, so the operator falls
  to the else and is told "Permission denied". If you add the usual operator-first clause here you
  are not fixing an omission — you are REVERSING a shipped behaviour that decides whether the
  platform operator can read every merchant's customer tickets. That is a product decision (see
  blocking_questions), not a bug fix.

- 'Replay Support Ticket', NOT 'Reply'. The misspelling is the live permission name in five places
  (SupportTicketController.php:38,55; support/action.blade.php:2; SupportTicketDataTable.php:27;
  Utility.php:2935,3265) and in SupportTicketStoredXssTest.php:47. Renaming it silently orphans
  every existing role grant, because addNewData() creates a row only when no row of that name exists
  — the old grant survives under the old name and the new name is granted to nobody.

- THE STOREFRONT TICKET PAGE 500s FOR A GUEST, AND THE TEST THAT DECLARES IT PUBLIC STATES THE WRONG
  REASON. AccountProfileController.php:743 assigns `collect()` for an unauthenticated visitor;
  resources/views/front_end/pages/support_ticket.blade.php:102 then calls `$tickets->onFirstPage()`
  OUTSIDE any guard, and I verified by execution that Illuminate Collection has neither
  onFirstPage() nor links() (`method_exists` -> false for both). A signed-in shopper gets a
  LengthAwarePaginator and renders fine. This matches docs/development/PROJECT-STATE.md:385, which
  lists `support-ticket` among three routes that "answer 500 for a store that exists" — status
  **open**, pre-existing. Meanwhile CustomerAccountBoundaryTest.php:178-180 justifies the route
  being unauthenticated with "support_ticket:561-565 renders an empty list for a guest" — the line
  numbers have drifted (the handler is :737-748) and the guest path does not render an empty list,
  it throws. The security conclusion still holds (it fails before printing anything), but the stated
  premise is false. NOT EXECUTED as a request.

- FIVE OF THE TEN MERCHANT ROUTES ARE DEAD AND THE CENSUS ASSERTS THE SET IN BOTH DIRECTIONS. If
  Phase 06 implements show/create/store/update, CatalogueEndpointIntegrityTest will FAIL until the
  four entries are deleted from DEAD_ROUTE_ACTIONS (:136-139) — the test says so at :418: "These are
  no longer dead, so remove them from DEAD_ROUTE_ACTIONS". Budget that edit into the same change.

- ticket_status_change READS ITS ID FROM THE BODY. SupportTicketController.php:144 is
  `$request->id`, not the `{id}` route parameter, so a posted `id` wins over the URL.
  HelpdeskTicketOwnershipTest already encodes the opposite rule for the Helpdesk row routes ("judged
  by the route parameter not by the body"); this first-party handler does not follow it.

- BOTH TICKET MODELS ARE Cachable (SupportTicket.php:12, SupportConversion.php:12). A raw
  `DB::table('support_tickets')->update()` leaves Eloquent reads — route-model binding included —
  serving the pre-write row, and under CACHE_STORE=array it persists for the rest of the test. Write
  through Eloquent or call flushCache(). And assert absence through `withoutGlobalScopes()`:
  SupportTicket IS scoped, so a plain `SupportTicket::find()` in a test answers against whichever
  tenant resolved, and an "it is gone" assertion passes either way.
  StorefrontAccountOwnershipTest:304 and CustomerAccountBoundaryTest:312 both assert through raw
  `DB::table(...)` for exactly this reason.

- THE STATUS VOCABULARY DISAGREES WITH ITSELF, SO ANY SLA OR REPORTING WORK HAS NO RELIABLE STATE
  MACHINE TO READ. Writer accepts three values (SupportTicket.php:52,60,69: 'open', 'In Progress',
  'Solved'); support/edit.blade.php:68 renders badges for 'New Ticket', 'In Progress', 'On Hold',
  'Closed' and a fallback; :21-26 tests a lowercase 'solved' the writer never produces; :125
  branches on 'Closed', which nothing can set. The column is a nullable string with no allowlist
  anywhere.

- support_tickets.order_id IS A STRING COLUMN (migration :17) holding an orders.id, and
  support_tickets.created_by IS NOT NULLABLE (:24) — CustomerAccountBoundaryTest:298-299 records the
  second one as a fixture constraint. Note created_by holds a CUSTOMER id on the storefront path
  (AccountProfileController.php:800), not a user id, so do not join it to users.

- THE TICKET LIST IS THE ONE STOREFRONT HANDLER WITHOUT AN OWNERSHIP HELPER.
  AccountProfileController.php:741 filters on customer_id ONLY. Combined with a single `customers`
  session across all storefronts and a NULLABLE customers.store_id that shopper() admits for every
  store (:1399), a shopper's ticket list is not bounded to the store whose page they are on.
  Structural, read not executed.

- DO NOT READ 'packages/workdo/' AS 'cannot be first-party'. LynomiaChat lives there and IS first-
  party and editable; it is also STATICALLY LOADED (app/Providers/PackageServiceProvider.php:31),
  which is why it contributes 99 routes while Helpdesk contributes 0. The loader comment at :26-30
  is explicit that loading the provider is not entitlement: every merchant route still carries
  PlanModuleCheck:LynomiaChat.

- DO NOT TAKE THE LYNOMIA INBOX FOR A TICKET STORE. The conversations live in an external service
  (MerchantInboxGateway builds an AgentApiClient over LynomiaHttp); lynomia_inbox_events "carries
  identifiers and nothing else … a security property rather than a storage decision". Any Phase 06
  support reporting built on it is reporting on data this database does not hold.

- EXTENDING THE HOST-OVERRIDE PATTERN OVER HELPDESK IS ITSELF UNRATIFIED. GAP-169 records that two
  Helpdesk view overrides and three container bindings replaced vendored behaviour from the host
  side, that "engineering's convention treats [this] as not an edit", and that "Whether that is
  within the owner's constraint has not been asked" (`D-N39`). Do not treat the existing five
  artefacts as a precedent the owner granted.

### Tenancy

FOUR DIFFERENT TENANCY STORIES ON FOUR TICKET SURFACES. Measure which one you are in before writing
  a predicate. (a) MERCHANT PANEL, customer->merchant desk — the StoreScope DOES filter.
  App\Models\SupportTicket and App\Models\SupportConversion both carry BelongsToStore
  (SupportTicket.php:12, SupportConversion.php:12) AND are both in config/tenancy.php scoped_models
  (:155, :162), which is the two-part test this project requires. For a merchant on the `web` guard
  StoreScope::resolveTenant returns MODE_TENANT and apply() adds `store_id = <one store>` or
  `whereIn` for a multi-store merchant (StoreScope.php:303-321). So `SupportTicket::find($id)` at
  SupportTicketController.php:40 and :59, and `SupportTicket::where('id',$id)->delete()` at :133,
  ARE tenant-filtered for a merchant. The hand-written predicate in SupportTicketDataTable.php:71
  (`where('support_tickets.store_id', getCurrentStore())`) agrees with the scope and is narrower for
  a multi-store merchant. NOTE: docs/discovery/03-FEATURE-MATRIX.md:147 says ticket_status_change
  has "no tenant scope" — that is STALE; the model was allowlisted since. (b) THE OPERATOR — the
  scope deliberately adds NOTHING. StoreScope.php:289-294 is "THE ONE PLATFORM-OPERATOR BYPASS", and
  resolveTenant returns MODE_SUPER_ADMIN ahead of the storefront branch (:538-546). So for an
  operator the ticket queries are unscoped. This does not currently matter because the operator
  cannot reach any of these handlers (see `authorization`), but it means ANY Phase 06 operator
  ticket screen starts from a query with NO predicate and must supply its own. (c) STOREFRONT /
  SHOPPER — the scope adds NOTHING and the isolation is hand-written, correctly. On a
  `customers`-guard or anonymous request `auth()->user()` on `web` is null, so resolveTenant takes
  the `! $user` branch (StoreScope.php:566-592); with config/tenancy.php:557 `storefront_tenant =>
  'additive'` that returns MODE_UNRESOLVED, and config/tenancy.php:470 `null_tenant => 'open'` means
  NO PREDICATE. routes/customer.php:294-298 records exactly this: "TENANCY DOES NOT COVER ANY OF
  THIS. SupportTicket and SupportConversion are two of the six enforced models, but StoreScope FAILS
  OPEN on a null tenant in the storefront context by documented decision". The real control is
  AccountProfileController::ownedTicket() at :1420-1431 — `withoutGlobalScopes()` + `where('id')` +
  `where('customer_id', $customer->id)` + `(store_id = $store->id OR store_id IS NULL)`, with
  `$store` from `getStore($slug)`, never from getCurrentStore(). Eight of the nine storefront
  handlers go through it or through shopper() (:1391-1404, which refuses a customer whose store_id
  names a different store). This is pinned by StorefrontAccountOwnershipTest:272-320 (including the
  exact case "a ticket recorded against another store is not reachable even when the customer id
  matches") and CustomerAccountBoundaryTest:289-323. (d) VENDORED HELPDESK — NO store_id ANYWHERE.
  `helpdesk_tickets` has no store_id column (migration :15-29); HelpdeskTicket carries only
  HasFactory + Cachable, so it has no StoreScope registered at all, and it is absent from
  config/tenancy.php. Its tenancy is `user_id` / `created_by`. The host's compensating rule is
  ownership, not scoping: ModuleAdministrativeRouteGuard::ticketIsTheTenants (:273-280) reads
  `created_by, user_id` from a raw DB::table('helpdesk_tickets') and admits the caller when
  `sameTenant()` holds on EITHER side — "a missing row is nobody's". That is the only tenancy this
  desk has, it is first-party, and it is global on the `web` group. ONE MEASURED HOLE IN (c). The
  LIST handler is the one storefront handler that does NOT use ownedTicket() or shopper():
  AccountProfileController.php:741 is `SupportTicket::where('customer_id',
  auth('customers')->user()->id)->orderBy('id','desc')->paginate(10)` — customer_id only, no store
  predicate, and per (c) the scope adds none. Consequences, both structural: (i) the `customers`
  guard is one session across every storefront, so a store-A shopper opening /{storeB}/support-
  ticket is served THEIR OWN store-A ticket titles inside store B's chrome; (ii)
  `customers.store_id` is NULLABLE (create_customers_table:43) and shopper() admits a NULL store_id
  for EVERY store (:1399), so a NULL-tenant customer can raise tickets in many stores and this list
  genuinely spans them. Neither is a cross-CUSTOMER leak — the customer_id predicate holds — and I
  did NOT execute either case.

### Authorization

PERMISSION STRINGS, AND WHETHER THE OPERATOR IS ADMITTED. Short answer: on the first-party desk the
  operator is NOT admitted — it is explicitly excluded — and one route is not gated at all. THE
  THREE STRINGS (note the shipped misspelling 'Replay', not 'Reply'): 'Manage Support Ticket' ->
  SupportTicketController.php:21 (list) and the merchant menu
  (app/Listeners/CompanyMenuListener.php:895) 'Replay Support Ticket' -> :38 (edit), :55
  (conversion_store), the row-action cell (resources/views/support/action.blade.php:2) and
  SupportTicketDataTable.php:27 'Delete Support Ticket' -> :130 (destroy), action.blade.php:9,
  BulkDeleteTenantGuard.php:285 THE OPERATOR IS REFUSED, THREE INDEPENDENT WAYS: 1. BY CONSTRUCTION
  in the list handler. SupportTicketController.php:19 reads `if (auth()->user() &&
  auth()->user()->type != 'super admin')`. For the operator `type` IS 'super admin', so the
  condition is FALSE and control goes to the else at :30-33 — `redirect()->back()->with('error',
  __('Permission denied.'))`. The permission check at :21 is INSIDE the non-operator branch, so the
  operator is refused before it. This is the inverse of this codebase's usual defect: not a missing
  operator-first test, an explicit operator exclusion. 2. BY SEEDING for the other four handlers.
  app/Models/Utility.php:3080-3083 states addNewData() "is the ONLY permission-creation mechanism in
  the application". All three strings sit in `$arrPermissions` (2935, 2936, 3048) — which creates
  Permission ROWS ONLY and grants nothing (:3085-3087 says so) — and again in
  `$companyNewPermission` (3265, 3266, 3299), whose loop grants to
  `Role::where('name','LIKE','admin')` at :3331. `$superadminNewPermission` (:3338-3367) contains NO
  support or ticket string at all (grep over that range: 0 hits). So the `super admin` role holds
  none of the three, and `isAbleTo()` at :38/:55/:130 refuses it. INFERENCE FROM THE SEEDER, NOT
  EXECUTED. 3. BY NAVIGATION. app/Listeners/SuperAdminMenuListener.php has ten entries (Dashboard,
  Add-on Manager, Users, Coupons, Plan, Plan Request, Email Templates, Settings, Country, Currency)
  and `grep -ci 'support|ticket|helpdesk'` returns 0. The only support menu entry in the codebase is
  in CompanyMenuListener.php:884-897 (merchant), and config/merchant-navigation.php:78 files it
  under 'administration'. ONE ROUTE HAS NO AUTHORIZATION AT ALL.
  SupportTicketController::ticket_status_change (:142-157) contains no isAbleTo(), no type check and
  no ownership check; its route carries only `web, Authenticate, XSS, SetLocate, EmailIsVerified`.
  So ANY authenticated web principal with a verified e-mail can flip a ticket's status — including a
  merchant's staff member holding ZERO permissions, who cannot even open the list. Within a tenant
  the StoreScope still bounds the write (SupportTicket is allowlisted), so this is an intra-tenant
  privilege gap, not a cross-tenant one. It also reads `$request->id` and `$request->status`
  (:144-145) rather than the route parameter, so a body `id` takes precedence over `{id}` — the
  request-input shape this codebase has shipped repeatedly. And it validates nothing: a status
  outside {'open','In Progress','Solved'} falls through all three branches of
  SupportTicket::ticket_status_change (:52-78) and the method returns NULL implicitly, which :147
  and :154 then subscript. THE VENDORED DESK, if ever enabled: HelpdeskController gates
  index/create/store/edit/update/ticketReply/deleteTicket on
  isAbleTo('Manage|Create|Edit|Reply|Delete Helpdesk Ticket') and branches on `type == 'super
  admin'` for cross-store visibility (:38-41) — i.e. the vendored desk DOES admit the operator, by
  design, because it is the merchant->operator desk. storeTicketNote (:347-349) and show (:204-218)
  check NOTHING; the host's 8 manifest rules add OWNERSHIP over them and leave the permission to the
  handler, so storeTicketNote would still be unpermissioned within a tenant. LYNOMIA, for contrast,
  is the shape to copy: the permission is on the ROUTE (Laratrust\Middleware\Permission:Manage
  Lynomia Chat, then :Assign Lynomia Chat or :Reply Lynomia Chat on the mutating routes), and
  InboxController.php:11-24 states the rule explicitly — "THE CAPABILITY FLAGS ARE FOR LAYOUT, NOT
  FOR AUTHORIZATION … a hidden button is not an authorization control".

### What the suite already covers, and what it cannot reach

SUBSTANTIAL ON THE STOREFRONT AND ON THE VENDORED DESK; **NONE** ON THE MERCHANT PANEL'S
  AUTHORIZATION. EXECUTED BY ME: `php artisan test
  tests/Feature/Security/SupportTicketStoredXssTest.php
  tests/Feature/Security/HelpdeskTicketOwnershipTest.php` -> **30 passed, 797 assertions, 7.65s**. -
  tests/Feature/Security/SupportTicketStoredXssTest.php — 7 methods (P1-SEC-79). Drives the real
  routes; oracle is the response DOM (tests/Support/Security/DetectsActiveMarkup), not a substring;
  includes a premise case that feeds the oracle the raw payload to prove it can see the defect. -
  tests/Feature/Security/HelpdeskTicketOwnershipTest.php — 17 methods, 21 cases shown. Covers cross-
  tenant rewrite, delete, note overwrite and reply; route-parameter-beats-body for all three row
  routes; both encrypted spellings of the ticket number; HEAD judged like GET; a ticket the operator
  raised for a merchant; and "a user rooted at the operator is nobody's colleague". OTHER FILES THAT
  TOUCH THESE SURFACES (method counts by grep -c 'public function test_'):
  AnonymousAdministrativeModuleRoutesTest 38 · PlatformUploaderDoorsTest 33 ·
  BulkDeleteAuthorizationTest 28 · StorefrontContextTenantResolutionTest 24 · TenantRootSinksTest 18
  · StorefrontAccountOwnershipTest 17 · EnforcedModelIsolationTest 16 · HelpdeskStoredXssTest 14 ·
  CustomerAccountBoundaryTest 12 · CatalogueEndpointIntegrityTest 9 (one of which is the dead-route
  census) · ResidualTenancyExposureTest 9 · ModuleAdministrativeRouteGuardTest 7 ·
  DataTableRawColumnsTest 6 · RawQueryTenancyInventoryTest 3 · OwnedPathRawQueryInventoryTest 2 ·
  plus tests/Unit/Packages/HelpdeskRawSinksTest (hash-pins 3 vendored DataTables, counts `{!! !!}`
  per vendored view) and three Unit/Ui files that NAME the two vendor/helpdesk overrides as
  verbatim-vendored exemptions (BreadcrumbContractTest:52-54, IconFamilyCoherenceTest:43-46). THE
  HOLE, MEASURED: `grep -rn 'support_ticket\.index|ticket-status-
  change|support_ticket.status.change|conversion\.store|ticket-view' tests/` returns **ZERO HITS**.
  No test drives the merchant list, the status change, the reply write or the ticket view. The only
  tests naming SupportTicketController are CatalogueEndpointIntegrityTest:136-139 (the dead-method
  census, which asserts the methods are ABSENT) and BulkDeleteAuthorizationTest:320 (a permission-
  to-controller mapping). So the operator exclusion at :19 and the missing gate on
  ticket_status_change are both UNTESTED in either direction — a Phase 06 change to either would
  break nothing. AND THE SUITE CANNOT SEE THE VENDORED DESK UNLESS A TEST ENABLES IT: Helpdesk
  contributes 0 of 1,496 runtime routes. HelpdeskStoredXssTest.php:62-66 is the pattern —
  `enableWorkdoModules(['Helpdesk'])` through the production loader, then an assertion that
  Workdo\Helpdesk routes are actually registered, "Premise: Helpdesk is not loaded, so this test
  would reach nothing".

### The vendored constraint here

packages/workdo/Helpdesk is 70 VENDORED files and MUST NOT BE EDITED; LynomiaChat is the one first-
  party exception and MAY be edited. WHAT THAT FORBIDS FOR THE MERCHANT->OPERATOR DESK. Every defect
  in Helpdesk — HelpdeskController::storeTicketNote at :347-349 taking
  `HelpdeskTicket::find($ticketId)` with no permission check and no ownership check; show at
  :204-218 rendering any decryptable ticket_id from a route group declared with `['web']` only
  (packages/workdo/Helpdesk/src/routes/web.php:52-55); ticket ids minted as `time()`;
  `helpdesk_tickets` having no store_id at all — can only be answered from the host side. The
  established pattern is exactly the five artefacts P1-SEC-75 and P1-SEC-68 already built: a rule in
  app/Support/ModuleRouteManifest.php enforced by ModuleAdministrativeRouteGuard (appended to the
  `web` group at bootstrap/app.php:290), a Blade override under resources/views/vendor/helpdesk/,
  and a container rebinding in app/Providers/AppServiceProvider.php:42-45. That is the whole
  toolkit; adding a store_id column to a vendored table, or a permission check inside the vendored
  controller, is not available. AND THE HOST-SIDE PATTERN HAS A STANDING CONSTRAINT OF ITS OWN,
  recorded in GAP-169: replacing vendored behaviour from the host "engineering's convention treats
  as not an edit. Whether that is within the owner's constraint HAS NOT BEEN ASKED" (`D-N39`). So
  Phase 06 extending the override set is continuing an unratified convention, not exercising a
  settled permission. TWO RATCHETS WILL FIRE ON ANY HELPDESK WORK, by design:
  tests/Unit/Packages/HelpdeskRawSinksTest.php:46-48 hash-pins the three vendored DataTable files
  (sha256) and counts the module's `{!! !!}` prints per file, so a module update fails CI until
  somebody re-diffs the host's SafeHelpdesk*Data against it; and
  tests/Feature/Security/HelpdeskStoredXssTest pins the two view overrides equal to their vendored
  originals except for the printing expressions. THE SUITE IS STRUCTURALLY BLIND HERE. Helpdesk
  contributes 0 of 1,496 runtime routes because app/Providers/PackageServiceProvider.php:31 lists
  only ['LandingPage','LynomiaChat'] as static. A Helpdesk test must insert an add_on_managers row
  through the production loader first — HelpdeskStoredXssTest.php:62-66 does exactly that
  (`enableWorkdoModules(['Helpdesk'])`) and then ASSERTS THE PREMISE that Workdo\\Helpdesk routes
  are registered, because without it the test would pass against nothing. Copy that, do not write a
  Helpdesk test without it.

### What the refutation corrected

- **The map said:** DELETE support_ticket/{support_ticket} ... "Also carries the
  BulkDeleteTenantGuard mapping ('supportticket' => 'Delete Support Ticket')" — file_line
  "routes/web.php:1311 -> SupportTicketController.php:127;
  app/Http/Middleware/BulkDeleteTenantGuard.php:167,285"
  **Wrong because:** BulkDeleteTenantGuard never sees that route. `isBulkDeleteRoute()` arms on two
    conditions only: `$route->getName() === self::ROUTE_NAME` where ROUTE_NAME = 'bulk-delete'
    (:118), or `str_starts_with($route->getActionName(), self::CONTROLLER)` where CONTROLLER =
    'Workdo\BulkDelete\app\Http\Controllers\BulkDeleteController' (:124). The per-record route is
    named `support_ticket.destroy` and its action is
    `App\Http\Controllers\SupportTicketController@destroy` — neither matches, so the guard returns
    false and passes the request through untouched. The :167 and :285 entries are the bulk
    endpoint's `type`-parameter lookup tables, not middleware that applies here. This is the one
    consequential error in the map: a reader planning Phase 06 work on the per-record delete would
    believe a tenant/permission control sits in front of it when the only controls are the handler's
    own isAbleTo('Delete Support Ticket') at :130 and the StoreScope.
  **Measured by:** app/Http/Middleware/BulkDeleteTenantGuard.php:118, :124, :507-528. Commands:
    `grep -n 'const CONTROLLER' app/Http/Middleware/BulkDeleteTenantGuard.php`; `sed -n
    '116,122p;500,530p' app/Http/Middleware/BulkDeleteTenantGuard.php`; route name and action read
    from `php artisan route:list --json` parsed in PHP.

- **The map said:** "16 operator routes DO exist in this area: GET super-admin/lynomia-integration
  plus links/{link}/{approve,revoke,suspend,resume,restore,decommission,reauthenticate,remote-
  health,commerce-credential}, marketing-addons, marketing-addons/grant, marketing-allowance, seats"
  — stated twice (requirement_ids P06-05 entry and the routes array)
  **Wrong because:** There are 15, not 16. The route table holds exactly 15 entries whose URI starts
    `super-admin/lynomia-integration`: 1 index + 10 links routes + 4 marketing/seats routes. The
    map's own enumeration names only 14 distinct paths; the real extra entry is that commerce-
    credential has TWO methods (POST and DELETE), which the map collapses into one. The count is
    repeated as a correction to GAP-178, so it would be copied into the register.
  **Measured by:** Printed all 15 with middleware by parsing `php artisan route:list --json` in PHP
    and filtering on str_contains($uri,'super-admin/lynomia'); the loop counter printed 'operator
    lynomia routes: 15'. (PlatformOperatorOnly IS on all 15, as claimed.)

- **The map said:** "ZERO Helpdesk routes exist at runtime. The module declares 20
  (helpdesk/category, status, tickets/{status?}, create-ticket, store-tickets, edit-ticket, update-
  ticket, ticket-reply, delete-ticket, ticket-conversion-store, ticket-note-store, get-user-data,
  helpdesk-ticket-show, helpdesk-ticket/{ticketId}, ...)"
  **Wrong because:** The zero-at-runtime half is right; the declared count is wrong.
    packages/workdo/Helpdesk/src/routes/web.php declares 25 routes: 6 category (:20-25), 6 status
    (:28-33), 1 tickets/{status?} (:37), 7 ticket routes (:39-45), 3 conversion/note/get-user-data
    (:48-50), 2 in the `['web']`-only group (:53-54). The map also silently omits `GET helpdesk-
    tickets/search/{status?}` at :45, a SECOND route onto HelpdeskController@index — which matters
    because the manifest's 8 ownership rules do not name it, so a Phase 06 enabling of the module
    would leave that path to the handler's own gate. Adding routes/api.php:16 gives 26 declared in
    total.
  **Measured by:** `cat -n packages/workdo/Helpdesk/src/routes/web.php` (56 lines) and `grep -c
    'Route::' packages/workdo/Helpdesk/src/routes/web.php` = 27 (25 routes + 2 Route::middleware
    group calls); `grep -n helpdesk app/Support/ModuleRouteManifest.php` = 8 rules, none for
    helpdesk-tickets/search.

- **The map said:** In vendored_constraint, among "Every defect in Helpdesk ... can only be answered
  from the host side": "ticket ids minted as `time()`"
  **Wrong because:** That defect is NOT vendored-only — the first-party storefront writer does the
    same thing, and worse. app/Http/Controllers/AccountProfileController.php:796 is
    `$tickets->ticket_id = time();`, and support_tickets.ticket_id has NO unique index (migration
    :16 is `$table->string('ticket_id')->nullable();`), so two shoppers submitting in the same
    second silently share a ticket number. The vendored twin at HelpdeskController.php:112 writes
    time() into a column declared `unsignedBigInteger('ticket_id')->unique()` (migration :16), which
    would throw instead. Filing this under "what we may not fix" sends the reader away from the one
    copy that is first-party, editable, and has the weaker constraint.
  **Measured by:** `grep -rn 'ticket_id\s*=\s*time()' app/ packages/workdo/Helpdesk/src/` returns
    app/Http/Controllers/AccountProfileController.php:796 and
    packages/workdo/Helpdesk/src/app/Http/Controllers/HelpdeskController.php:112; `grep -n ticket_id
    database/migrations/2024_01_25_072330_create_support_tickets_table.php` shows :16 with no
    ->unique().

**And 6 things the map did not say at all:**

- app/Http/Middleware/DemoModeMiddleware.php:77 — $restrictedMethods names "update_support_ticket",
  "destroy_support_ticket" and "attachmentDestroy", three of the nine storefront ticket handlers. It
  is INERT today: it is registered as the alias 'demo_mode' at bootstrap/app.php:28, not appended to
  the web group, and no ticket route carries it (route:list shows web, ActiveTheme, ThemeLanguage,
  EnsureCustomerAuthenticated). But it is a first-party middleware that already names these handlers
  and is absent from the map's inventory and from its vendored/first-party table.

- packages/workdo/Helpdesk/src/routes/api.php:16 — `Route::middleware('auth:api')->get('/helpdesk',
  fn (Request $r) => $r->user());`. The map says "ZERO Helpdesk routes exist at runtime. The module
  declares 20" and counts web only. Worse, `auth:api` names a guard that does not exist here:
  executed `config('auth.guards')` returns exactly web, customers, deliveryboy, sanctum. So if Phase
  06 ever enables the module, that route throws on guard resolution rather than returning 401 — a
  fact the map's own "NO API SURFACE" item would have caught had it looked at the module's api.php
  (its commands_run shows it cat'ed the file).

- app/Models/SupportTicket.php:21 — `'answer'` is in $fillable and there is NO `answer` column in
  either migration, and no other migration touches the table. The map enumerated the ten columns
  correctly from the migration but did not notice the divergence, so a Phase 06 handler mass-
  assigning `answer` (the obvious column name for a merchant reply) would write nothing and fail
  silently on SQLite/MySQL alike.

- resources/views/front_end/common/account-tab.blade.php:62 and :68 — the storefront account
  navigation gates the whole support-ticket tab on
  `$themeSettings['account_tab_support_ticket_status'] == '1'` and titles it from
  `$themeSettings['account_tab_support_ticket_title']`. This is an 8th first-party blade on the
  surface and, more importantly, a per-store theme switch that can hide the shopper's entry point to
  the desk entirely. Absent from the map's "7 views".

- resources/views/theme_analytic.blade.php:66 — `elseif (str_contains($path, '/support-ticket'))
  $pageName = __('Support Ticket');` ties the storefront ticket path into page analytics. A 9th
  first-party blade referencing the surface, and the only place support appears in reporting.

- resources/views/front_end/pages/support_ticket.blade.php — the guest 500 is overdetermined, not
  one call. Beyond :102 onFirstPage() the view also calls previousPageUrl() (:111),
  currentPage()/lastPage() (:122-123), url() (:132+) and hasMorePages() (:168); I executed
  method_exists for hasMorePages on Illuminate\Support\Collection and it is also false. So removing
  the :102 guard alone would not fix the page — relevant because the map presents :102 as the single
  cause.

### Not established — stated as such rather than guessed

- WHAT 'support' MEANS. There is no Phase 06 source (P06-01, measured). I did not and cannot
  establish which of the two relationships the requirement names, and I did not invent one.

- WHETHER THE HELPDESK MODULE IS ENABLED IN PRODUCTION. It is NOT loaded here (0 of 1,496 routes).
  Whether a production `add_on_managers` row exists is a database fact I have no access to. `grep
  -rn Helpdesk database/seeders/` returns nothing, so nothing first-party enables it; the only
  seeder that mentions it is the module's own vendored marketplace seeder.

- WHETHER THE OPERATOR CAN REACH SupportTicketController::edit / conversion_store / destroy. Those
  three check only `isAbleTo(...)` with no type branch. I established that the `super admin` ROLE is
  granted none of the three permissions by the only permission-granting mechanism, which makes
  refusal the expected outcome — but I did NOT execute an operator request against those routes, and
  I did not rule out a direct user-level grant made through the role editor on a live database.
  Reported as inference from the seeder, not as a measurement.

- THE EFFECT OF THE MISSING-METHOD ROUTES AS AN HTTP STATUS. CatalogueEndpointIntegrityTest proves
  the methods are absent and pins that set; I did not drive those five routes and so did not observe
  500 myself.

- WHETHER A CUSTOMER WITH store_id = NULL EXISTS IN ANY REAL DATABASE. The column is nullable
  (database/migrations/2023_12_15_112855_create_customers_table.php:43) and both shopper() and
  ownedTicket() admit a NULL, so the hazard is structural; whether any such row exists is a data
  fact I cannot see.

- THE CAUSE OF THE RECORDED storefront 500 WAS NOT EXECUTED. I read the mechanism (see traps) and it
  matches PROJECT-STATE.md:385, but I did not issue the request.

- WHETHER THE OWNER EVER APPROVED EITHER DESK AS THE PRODUCT. The traceability row records "Owner
  decisions: None found." I searched and found none either. Discovery rates both desks HIGH/P1 but
  that is an engineering rating, not an owner decision.

---

## Area C — Platform controls and operations

**Rows:** `P06-04` · **Buildable without an owner decision:** **NO** · **Refutation verdict:** `SOUND_WITH_CORRECTIONS` (9 refutations, 9 omissions found)

### What exists (9 items)

- **StoreController — the whole first-party operator store surface: index, list, create, store,
  show, edit, update, destroy, pwaSetting, changeStore, storeResetPassword(+Update),
  ownerstoredestroy, LoginWithAdmin, ExitAdmin, StoreLinks, upgradePlan, activePlan, customDomain,
  subDomain, changeStatus**
  `app/Http/Controllers/StoreController.php:1-1321 (23 public methods; signatures at :42,:60,:78,:92
    ,:436,:468,:516,:608,:691,:871,:913,:929,:963,:1085,:1098,:1122,:1159,:1171,:1213,:1248,:1276,:1
    314)` — *first-party*

- **Enable/disable a store — door 1 of 2. Writes `stores.is_active` only. No audit row, no
  `is_enable_login` propagation, no `current_store` repair. Refuses deactivating the owner's LAST
  active store (counts `Store::where('is_active',1)->where('created_by',$adminId)`)**
  `app/Http/Controllers/StoreController.php:1276-1311 (`changeStatus`), route POST store/change-
    status` — *first-party*

- **Enable/disable a store — door 2 of 2, and it is NOT equivalent to door 1. Same `is_active` write
  PLUS `User::where('current_store',$targetStore->id)->where('type','!=','admin')-
  >update(['is_enable_login'=>$isActive])` and a repoint of the OWNER's `current_store` to a
  surviving enabled store**
  `app/Http/Controllers/UserController.php:829-945 (`UserUnable`), the `$request->name == 'store'`
    branch at :871-931; the login-gate write is :920, the owner repoint :921. Route POST user-
    unable` — *first-party*

- **Impersonation ("log in as"), via lab404/laravel-impersonate. Gated by a real policy;
  `Impersonate::take()` then makes `auth()->user()` the merchant. Exit fails closed when no
  impersonation is active**
  `app/Http/Controllers/StoreController.php:1085-1096 (`LoginWithAdmin`,
    `$this->authorize('impersonate',$user)` at :1091) and :1098-1120 (`ExitAdmin`); policy at
    app/Policies/UserPolicy.php:385-401` — *first-party*

- **`AuditLog` model + `audit_logs` table. NOT Cachable (plain `extends Model`), static `record()`
  helper stamping user_id, store_id, action, subject, old/new values, ip, user agent**
  `app/Models/AuditLog.php:1-50; migration
    database/migrations/2026_10_12_000105_create_audit_logs_table.php; MySQL DDL pinned at
    app/Support/Database/GateFProductionSchema.php:201-221` — *first-party*

- **Audit WRITERS: 21 first-party call sites across 6 controllers, plus ~30 in the first-party
  LynomiaChat module. Actions actually written: plan_order_approved/rejected/refunded,
  user_created/updated/deleted/password_reset, user_login_enabled/disabled,
  store_user_password_reset, finance.* (5 in StoreFinanceController), theme publication
  (AuditLog::create)**
  `BankTransferController.php:170,210; PlanController.php:502;
    UserController.php:175,338,434,495,621,637,865,989;
    StoreFinanceController.php:526,722,1830,2096,2294; StoreController.php:958;
    ThemeVersionController.php:319; LynomiaChat services (~30 sites)` — *first-party*

- **Plan-derived store and user QUOTAS, enforced by looping and disabling the overflow: `max_stores`
  and `max_users` (-1 = unlimited). Plus `max_products` (3 live gates) and `storage_limit` (1 live
  gate)**
  `app/Models/User.php:359-406 (`assignPlan`); columns app/Models/Plan.php:16-29; max_products gates
    Api/DashboardController.php:3149 and ProductController.php:710,1071; storage gate
    app/Models/Utility.php:869-874` — *first-party*

- **`PlatformOperatorOnly` middleware — normalised operator test (`strtolower(trim())`), 403 not
  401, and it deliberately PASSES an anonymous caller through so `auth` owns the redirect**
  `app/Http/Middleware/PlatformOperatorOnly.php:47-74` — *first-party*

- **The one coherent, audited, reversible tenant-lifecycle surface in the repository — but it
  governs INTEGRATION LINKS, not stores: suspend, resume, revoke, restore, decommission,
  reauthenticate, credential issue/revoke, seats, marketing allowance. Every operation writes a
  typed AuditLog row and the states are modelled (`LinkState::REVOKED` etc.)**
  `packages/workdo/LynomiaChat/src/app/Http/Controllers/SuperAdmin/IntegrationController.php (routes
    `super-admin/lynomia-integration*`), with AuditLog rows at :415,:444,:487,:551,:651,:686 and in
    AccountLinker.php:216,297,376,474,584,650 and InstallationLifecycle.php:108,169,189,215` —
    *first-party*

### Traps — things that read as working and do not

- THE RECORDED LEAD IS MISATTRIBUTED — CORRECT IT BEFORE BUILDING. `StoreController::destroy()` does
  NOT re-point `current_store` at all; it has no `current_store` statement anywhere (`grep -n
  current_store` on the file returns :297, :907, :1061 only, none in :608-689). It deletes the USER
  and relies on `users.id` cascades. The `current_store` re-pointing is in `ownerstoredestroy()` at
  app/Http/Controllers/StoreController.php:1058-1062 and in `Api\DashboardController::DeleteStore`
  at :5466-5470. The substance of the lead is CORRECT at those two sites, and worse than stated:
  `$user->current_store = $userstore->id; $user->save();` writes only the CALLING user, and there is
  no observer, no `booted`, no `deleting`/`deleted` hook on `Store` to repair anyone else (`grep -n
  'SoftDeletes|booted|::deleting|::deleted' app/Models/Store.php` → nothing; `grep -rn
  'Store::observe|StoreObserver' app/` → nothing). So after a delete, EVERY other user whose
  `current_store` pointed at that store — all the merchant's staff in every case, and the OWNER
  THEMSELVES when the operator does the deleting (because `$user` is then the operator and
  `Store::where('created_by',$user->id)->first()` returns one of the OPERATOR's own stores) — is
  left pointing at a store id with no row. With `null_tenant => 'open'` that is not a clean failure:
  the scope adds no predicate. The API twin at :5466 at least guards with `if
  ((int)$user->current_store === (int)$deletedStoreId)`, but it still writes only `$user`.
  Uncovered: no test asserts any other user's `current_store` after a store delete.

- `is_active = 0` IS NOT A SUSPENSION, AND THE REFUSAL THAT WOULD MAKE IT ONE IS COMMENTED OUT IN
  THE SOURCE. app/Http/Middleware/ActiveTheme.php:24-26 holds `//if (empty($store)){ // return
  abort('403','The store is not active.'); // }`. With it commented, a disabled store's request
  falls to the else-branch, `getThemeNameFromUrl()` returns null for an ordinary URL, `$store` is
  null (the query filtered `is_active = 1`) so the `if (empty($themeName) && $store)` fallback at
  :57 does not fire either, `Theme::set()` is never called — and the request proceeds to a handler
  that resolves the store through `getStore()`, which has NO `is_active` predicate. Net effect
  measured from the three resolution sites: disabling a store changes WHICH THEME RENDERS, and the
  storefront stays up. Anyone who 'fixes' this by uncommenting the abort should know what they are
  shipping: 136 web routes carry ActiveTheme, so every disabled store's storefront would start
  answering 403 at once — that is a live-customer-facing change and a product-policy decision, not a
  bug fix. NOT ESTABLISHED BY EXECUTION: I read the three resolution sites (ActiveTheme.php:21,
  helper.php:3776-3779, helper.php:3822) and counted the route middleware; I did not drive an HTTP
  request against a disabled store. That premise test is the first thing to write.

- TWO STORE-DISABLE DOORS WITH DIFFERENT SIDE EFFECTS, AND NEITHER IS THE WHOLE OPERATION.
  `StoreController::changeStatus` (:1301-1302) writes `is_active` and stops.
  `UserController::UserUnable`'s store branch writes `is_active` (:912), THEN disables sign-in for
  every non-admin user whose `current_store` is that store (:920), THEN re-points the owner's
  `current_store` (:921). So the same product gesture leaves the system in two different states
  depending on which screen it was clicked from, and `changeStatus` is the one wired to
  `route('store.active.status')` in the operator's own store views. Re-enabling through
  `changeStatus` also never restores the `is_enable_login` rows that `UserUnable` cleared.

- `can()` MEANS TWO DIFFERENT THINGS IN THIS AREA AND ONLY ONE OF THEM IS DEAD. CLAUDE.md's rule —
  `$user->can('X')` is always false because `permissions_as_gates => false` — applies to a
  PERMISSION NAME. `auth()->user()->can('update', $target)` at UserController.php:843 and :976
  passes a MODEL, so Laravel resolves `UserPolicy::update()` and the call is genuinely live. Do not
  'fix' these two as dead gates. The real hazard is the opposite one: because `UserPolicy::update()`
  is ordered `isAbleTo('Edit User')` FIRST and `isPlatformAdmin()` second, and
  `database/seeders/PermissionTableSeeder.php` attaches no permission to the `super admin` role at
  all, the seeded operator is refused by both doors. Two passing tests pin that as the contract
  (CrossTenantPolicyGridTest:355 and :372) and the policy's own comment records the ordering as an
  owner instruction — so this is to be ESCALATED, never reordered in passing.

- THE OPERATOR'S STORE LIST CAN BE ESCAPED BY THE TENANT. Both `StoreController::index` (:46-52) and
  `StoreDataTable::query` (:50) require `users.type = 'admin'`. CLAUDE.md records that a merchant
  can demote their own `type` through the product (`PUT /users/{self}` with a role they created) —
  the manoeuvre behind the P0-TENANT-ROOT account takeover. A merchant who does so vanishes from the
  only operator-facing store list in the application. The same two queries also filter
  `users.created_by = Auth::user()->creatorId()`, and `creatorId()` returns the row's own id for a
  `super admin` (app/Models/User.php), so a SECOND operator account sees an empty list. Any Phase 06
  tenant inventory must be built from `stores`, not from this `users` join.

- AN IMPERSONATED OPERATOR IS INDISTINGUISHABLE FROM THE MERCHANT IN THE RECORD, AND THIS IS ALREADY
  WRITTEN DOWN AS AN UNCLOSED ITEM. app/Services/OperatorCatalogueWrite.php:40-57 records it
  verbatim: after `Impersonate::take()` every gate in the codebase reads the merchant's identity and
  the merchant's `current_store`, and 'no catalogue write is audit-logged anywhere in this
  application' — so a price edited while impersonating reads as the merchant's own. `LoginWithAdmin`
  (:1085) and `ExitAdmin` (:1098) write no audit row, there is no time bound on the session, and
  there is no reader for `audit_logs` even if they did. Any Phase 06 'log in as' feature inherits a
  delegation mechanism its own source calls unsound.

- AUDIT WOULD BE WRITE-ONLY ON ARRIVAL. `audit_logs` has zero production readers — every reader hit
  is under `tests/`. Adding `AuditLog::record()` to the governance doors is cheap and correct, but
  it does not produce an operator-visible trail, and a Phase 06 report that calls the area 'audited'
  on the strength of new write sites would be the same error P04-FIN-04 already recorded ('the audit
  rows it writes have no reader anywhere in the product'). Note also that `AuditLog::record()`
  stamps `store_id => getCurrentStore()`, which reads the DEFAULT `web` guard and returns the
  hardcoded literal 1 for an operator with an empty `current_store` — so operator-initiated rows
  would be mis-filed against store 1 unless the governance writer passes the store explicitly.

- DELETION IS IRREVERSIBLE AND THE PRESERVED RECORDS ARE LEFT DANGLING BY DESIGN. `Store` has no
  `SoftDeletes` (app/Models/Store.php:12 is `use HasFactory, Cachable;`). Orders and PlanOrders are
  deliberately NOT deleted (owner decision D14, recorded at StoreController.php:995-1008 and
  :1045-1048, and in the API twin at DashboardController.php:5413-5428), and the comment states the
  cost plainly: those rows then reference a `store_id` whose row is gone. So any Phase 06 'restore a
  tenant' feature is not a soft-delete flip — the store row, settings, pages, categories, products
  and product media are gone from disk, while the financial history survives orphaned. Reversibility
  is new engineering, not a flag.

- `Store` IS Cachable (app/Models/Store.php:12) while `AuditLog` is NOT. A governance handler that
  flips `is_active` with a raw `DB::table('stores')->update()` will leave Eloquent reads — route-
  model binding included — serving the pre-write row, and under the suite's `CACHE_STORE=array` it
  persists for the rest of the test. Write `is_active` through Eloquent (as both current doors do)
  or call `flushCache()`.

### Tenancy

Governance is where tenancy is weakest, because the models involved are the unscoped ones. `Store`,
  `User` and `Order` are all absent from `config/tenancy.php`'s `scoped_models`, so
  `StoreScope::isActiveFor()` short-circuits and NO predicate is added for any principal — operator,
  merchant or anonymous. Every tenant boundary in this area is therefore hand-written, and there are
  three different hand-written forms. (1) `StoreController::resolveOwnedStore`
  (app/Http/Controllers/StoreController.php:838-868) — the good one: normalised operator test, and
  for a non-operator it resolves `UserPolicy::merchantTenantRoot($user)` and REFUSES when that is
  null (the P1-SEC-66 fix: `creatorId()` alone returns the operator's id for a user rooted at the
  platform, and `created_by = 1` is not a tenant). Used by `ownerstoredestroy` (:985) and
  `changeStatus` (:1283). (2) `changeStore` (:871-911) — same shape but with the EXACT `type !==
  'super admin'` compare at :882, so it diverges from (1) for a `'Super Admin'` row. It guards the
  tenant DISCRIMINATOR itself (`users.current_store`), which is caller-writable, so this is the
  highest-value predicate in the file. (3) `UserUnable`'s store branch
  (app/Http/Controllers/UserController.php:884-897) — normalised, `merchantTenantRoot`, and
  `$ownerId = $targetStore->created_by` derived FROM THE RESOLVED STORE rather than from
  `$request->owner_id`, which the handler still accepts in its entry condition at :831. THE MEASURED
  TENANCY DEFECT — the operator's own-store count governs another tenant's store: in
  `ownerstoredestroy`, `resolveOwnedStore` drops its `created_by` predicate for the operator
  (:856-866), but the very next statement is `$user_stores = Store::where('created_by',
  $user->id)->count()` (:987) — the CALLER's stores, not the target store's owner's. So the "you
  cannot delete your last store" guard answers a question about the operator while the wipe runs
  against a merchant's store. The code comment at :1017-1023 states this is deliberate (narrowing it
  would newly permit staff to delete their employer's store) and it is PROVEN BY EXECUTION:
  `tests/Feature/Security/StoreWipeMediaOrderingTest.php:632-668
  test_the_operator_wiping_a_merchants_store_charges_the_merchant_not_the_operator` gives the
  operator two stores of their own precisely "so ownerstoredestroy's last-store count admits them"
  (:639-641), points `$operator->current_store` at the merchant's store (:649-650), DELETEs
  `/ownerstore-remove/{merchantStore}` and asserts the store row is gone (:655). EXECUTED: 13
  passed, 51 assertions. Assertion discipline for anyone building here: `Store` and `User` do NOT
  filter, so a plain `Store::find()` is a true cross-tenant read and an "it is gone" assertion is
  honest; but `Customer` and `Cart` DO filter, so any governance test that checks a tenant's
  customers or carts were (or were not) destroyed must go through `withoutGlobalScopes()` or it
  passes regardless.

### Authorization

NOT ONE store-governance route carries any authorization middleware. Measured over `php artisan
  route:list --json`: every one of the 19 StoreController routes plus `POST user-unable` and `GET
  user-enable-login/{id}` carries exactly `['web', Illuminate\Auth\Middleware\Authenticate,
  App\Http\Middleware\XSS, App\Http\Middleware\SetLocate, App\Http\Middleware\EmailIsVerified]`. All
  authorization is in-handler. Of the 29 routes that DO carry `PlatformOperatorOnly`, zero are store
  governance. PERMISSION STRINGS: none exist for this area (gap 7 above) — so the operator is
  admitted or refused purely by `users.type`. THE OPERATOR IS ADMITTED on the `type`-compare doors:
  index (:44), create (:69), userCreate (:80), store (:251), callerMayAdministerStores (:602, used
  by show :470 and update :518), changeStore (:882), StoreLinks (:1146), upgradePlan (:1161),
  activePlan (:1173), customDomain (:1215), subDomain (:1250), list (:1316) — all `type == 'super
  admin'` EXACT, un-normalised; and destroy (:615), resolveOwnedStore (:857), UserUnable's store
  branch (:885) — `UserPolicy::isOperator()`, NORMALISED. The disagreement is real and documented,
  not theoretical: `UserPolicy::isOperator` folds case and trailing space to match MySQL's
  utf8mb4_unicode_ci (app/Policies/UserPolicy.php:233-244), so a row typed `'Super Admin'` or
  `'super admin '` IS the operator to the database and to the normalised sites, and a STRANGER to
  the exact ones. Both forms sit in one controller: `changeStatus` reaches the store via normalised
  `resolveOwnedStore` while `changeStore` at :882 uses exact `!==`, so the same principal is
  operator on one door and merchant on the other. This is pinned, with a 101-comparison ceiling, by
  `tests/Feature/Security/OperatorCheckCensusTest.php:47` (`private const CEILING = 101;`) —
  EXECUTED: 19 passed, 33 assertions. THE OPERATOR IS REFUSED — THE GAP-218 SHAPE, MEASURED AND
  DECISIVE: `UserPolicy::update()` (app/Policies/UserPolicy.php, first two statements) is ordered
  PERMISSION FIRST — `if (!$actor->isAbleTo('Edit User')) { return false; }` and only THEN `if
  ($this->isPlatformAdmin($actor)) { return true; }`. Because Laratrust has no super-admin bypass,
  an operator without `Edit User` is refused before the operator branch is ever reached. Two doors
  depend on it: `UserController::UserUnable` user-branch (:843
  `auth()->user()->can('update',$target)`) and `UserController::userLoginManage` (:976, same call).
  Note these `can()` calls take a MODEL, so they resolve the POLICY and are genuinely live — they
  are NOT the always-false `can('Permission Name')` form. And
  `database/seeders/PermissionTableSeeder.php` creates the `super admin` Role at :58-64, assigns it
  at :66, re-fetches it into `$superAdminRole` at :69 — and NEVER ATTACHES A SINGLE PERMISSION:
  `grep -n 'superAdminRole|givePermission|syncPermission|attachPermission'` over that file returns
  only lines 58 and 69, so `$superAdminRole` is a dead variable and the seeded operator holds zero
  Laratrust permissions, `Edit User` among them. The refusal is the pinned shipped contract:
  `tests/Feature/Security/CrossTenantPolicyGridTest.php:355
  test_an_operator_without_edit_user_may_not_update_anyone` and `:372
  test_an_operator_holding_edit_user_may_still_do_the_work` — EXECUTED: 13 passed, 44 assertions.
  The code comment at UserPolicy::update records the ordering as deliberate ("it does not move the
  operator check past the permission check on any path — which the owner's closure instruction
  explicitly forbids"), so this is an OWNER-OWNED ordering, not a bug to silently reorder.

### What the suite already covers, and what it cannot reach

PARTIAL, and the coverage is concentrated on "who may act", with nothing on "what the action
  records" or "can it be undone". EXECUTED IN THIS PASS, all green: (1)
  `tests/Feature/Security/ImpersonationTest.php` — 10 tests, 22 assertions (merchant refused, forged
  encrypted id refused, anonymous refused, operator not impersonable, operator CAN impersonate a
  merchant, exit without active impersonation rejected, model predicates, 3 StoreLinks cases). (2)
  `tests/Feature/Security/CrossTenantPolicyGridTest.php` — 13 tests, 44 assertions, including the
  two that pin the operator/`Edit User` ordering (:355, :372) and
  `test_no_path_may_act_on_a_platform_operator_however_the_row_is_spelled` (:271). (3)
  `tests/Feature/Security/StoreWipeMediaOrderingTest.php` — 13 tests, 51 assertions, including the
  operator-wipes-a-merchant's-store case (:632) and a cross-tenant positive control (:680). (4)
  `tests/Feature/Security/OperatorCheckCensusTest.php` — 19 tests, 33 assertions; the un-normalised-
  comparison ceiling of 101 (:47) and the collation table in both directions. ALSO PRESENT, NOT RUN:
  `UserDeletionAuthorizationTest.php` (:527 records that `stores.destroy` is reachable only by a
  crafted request), `OrderRecordPreservationTest.php` (:101-125 — asserts the D14 order-preservation
  by reading the SOURCE of `ownerstoredestroy`, not by driving it),
  `SubscriptionAuthorizationTest.php:394`, `UserAuthorizationTest.php:151` (the only non-Lynomia
  test that reads an AuditLog row, action `user_updated`), `MarketingScreenOperatorAccessTest.php`,
  `ZidAdminRoutesOperatorOnlyTest.php`. NONE: zero tests assert a store suspension takes the
  storefront offline; zero assert any destructive tenant operation writes an audit row
  (`changeStatus`, `UserUnable` store branch, `ownerstoredestroy`, `LoginWithAdmin` all write none,
  so there is nothing to assert); zero assert any other user's `current_store` survives a store
  delete; zero drive `POST store/change-status` at all. `tests/Support/Security/bin/report-
  storefront-entitlement-gap.php` is a read-only production reporter for a DIFFERENT storefront gap
  (module entitlement: `PlanModuleCheck` requires plan AND activation row, `module_is_active()`
  consults `plans.modules` alone) — do not mistake it for suspension evidence.

### The vendored constraint here

The governance code itself is almost entirely FIRST-PARTY and therefore editable: StoreController,
  UserController, UserPolicy, PlatformOperatorOnly, ActiveTheme, AuditLog, User::assignPlan,
  StoreDataTable and the `resources/views/store/` views all live outside `packages/workdo/`. So
  P06-04 is not blocked by the vendored-tree rule. Three vendored constraints do bind, and one is
  load-bearing. (a) The reference implementation of audited, reversible tenant lifecycle —
  suspend/resume/revoke/restore/decommission with a typed AuditLog row per transition — lives in
  `packages/workdo/LynomiaChat`, which CLAUDE.md names as the ONE first-party exception to the no-
  edit rule, so it may be read, copied and extended. (b)
  `packages/workdo/BulkDelete/src/app/Http/Controllers/BulkDeleteController.php:111-118` is
  genuinely untouchable and is a trap for anyone adding a bulk store action: it dispatches
  `$model::whereIn('id', $request->ids)->delete()` with NO tenant predicate and NO ownership check,
  gated only by `PlanModuleCheck:BulkDelete` (routes/web.php in that package, line 17), and its map
  includes the PLATFORM-level `plan-coupon`, `plan-order`, `plan-request` and `roles`. A first-party
  bulk store action must NOT be routed through it; it would have to be a new first-party controller.
  (c) `packages/workdo/Marketing/src/app/Http/Controllers/StoreController.php` (2,416 lines) is a
  different `StoreController` for vCard/microsite "stores" and shares nothing with tenant governance
  — do not conflate the two when grepping. Finally, the usual module blindness applies: of 70
  vendored modules only LandingPage (213 routes) and LynomiaChat (99) appeared in my `route:list`
  run, so I have NOT enumerated governance surfaces in the other 68; they load only behind an
  `add_on_managers` row.

### What the refutation corrected

- **The map said:** "NOTE a defect in the register itself: the phase column reads '05 Marketing &
  Ana' for a P06 requirement; every other P06 row I read is phase-06. Report as a register data
  error, not as evidence the row belongs to Phase 05."
  **Wrong because:** The second half is false and it inverts the finding. ALL EIGHT P06 rows carry
    '05 Marketing & Ana' — GAP-174 (P06-01), GAP-175 (P06-02), GAP-176 (P06-03), GAP-177 (P06-04),
    GAP-178 (P06-05), GAP-179 (P06-06), GAP-180 (P06-07), GAP-181 (P06-08). There is no P06 row in
    the register spelled phase-06, so the map cannot have read one. This is a SYSTEMATIC column
    error across the entire P06 block, not a one-row anomaly — which matters because a reader told
    'every other row is phase-06' will try to reconcile GAP-177 against its siblings and find they
    all disagree with the map instead.
  **Measured by:** docs/development/MASTER-PLAN-GAP-REGISTER.md:125 (GAP-177), :126 (GAP-180), :143
    (GAP-174), :144 (GAP-175), :145 (GAP-176), :146 (GAP-178), :147 (GAP-179), :148 (GAP-181).
    Command: `grep -n "P06-" docs/development/MASTER-PLAN-GAP-REGISTER.md`

- **The map said:** "Enable/disable a store — door 1 of 2" / "door 2 of 2" — the map frames store
  enable/disable as exactly two doors.
  **Wrong because:** There are THREE first-party writers of `stores.is_active`. `User::assignPlan()`
    writes the column directly in three places and is reached from `activePlan` (an operator route
    the map itself lists) AND from SetLocate.php:75 and :86 on plan/trial expiry with no operator in
    the room at all. The map mentions assignPlan only as a quota enforcer, never as a third
    enable/disable door, so a Phase 06 governance state machine built from the map's two doors would
    be silently bypassed by the plan layer.
  **Measured by:** app/Models/User.php:392 (`$store->is_active = 1;`), :400 (`= 1`), :403 (`= 0`),
    inside the max_stores loop at :388-407. Reached unattended at
    app/Http/Middleware/SetLocate.php:75 (`$user->assignPlan($plan->id)`) and :86
    (`$user->assignPlan(1)`). Command: `grep -rn "is_active" app/ --include=*.php | grep -i store`
    then `awk 'NR>=385 && NR<=410' app/Models/User.php`

- **The map said:** "Re-enabling through `changeStatus` also never restores the `is_enable_login`
  rows that `UserUnable` cleared." — the map locates the asymmetry BETWEEN the two doors.
  **Wrong because:** The asymmetry is INSIDE door 2 as well, and the map misses it. In `UserUnable`,
    the login propagation (:915) and the owner repoint (:921) sit inside `if($isActive === 0){
    if($enabled_stores != 1){ ... } }`. The ENABLE path is the else at :926-928 and does ONLY
    `Store::where('id',$targetStore->id)->update(['is_active' => $isActive]);`. So re-enabling
    through UserUnable ITSELF never restores `is_enable_login` either — the staff lockout is one-way
    through both doors, not just through door 1. That is a strictly worse and differently-shaped
    defect than the one reported.
  **Measured by:** app/Http/Controllers/UserController.php:910-928 — disable branch :910-925
    (is_active :913, login gate :915, repoint :921), enable branch :926-928. Command: `awk 'NR>=826
    && NR<=950 {printf "%d: %s\n", NR, $0}' app/Http/Controllers/UserController.php`

- **The map said:** "DELETE api/admin/deletestore — the Flutter admin twin of ownerstore-remove ...
  so the handler's own predicate is the only tenant check", and (in `tenancy`) "there are three
  different hand-written forms" of the tenant boundary in this area.
  **Wrong because:** Two errors. (a) `DeleteStore` does NOT use the shared helper and is NOT the
    twin of `ownerstore-remove` in authorization. It hand-writes `Store::where('id',
    $request->store_id)->where('created_by', $user->id)` — keyed on the CALLER's own id, with NO
    operator exemption and without `creatorId()` or `merchantTenantRoot()`. That is a FOURTH hand-
    written form, and it diverges from the web door in the direction that matters for building: the
    platform operator CAN wipe a merchant's store on the web and CANNOT through the API, and a
    merchant's staff can do neither. The file's own `resolveOwnedStore()` exists at :4830-4859 and
    `ChangeStore` does call it at :5267 — `DeleteStore` simply does not. (b) The 'CustomAuth admits
    a Customer or DeliveryBoy token, so the handler's own predicate is the only tenant check'
    framing is misleading here: `\Auth::user()` reads the DEFAULT `web` guard, so a Customer or
    DeliveryBoy token yields null and the handler returns at :5332-5336 before any predicate runs.
    This door fails CLOSED for a non-web principal; the predicate is never the thing standing
    between those tokens and the wipe.
  **Measured by:** app/Http/Controllers/Api/DashboardController.php:5338-5340 (the hand-written
    predicate), :5330-5336 (the \Auth::user() null refusal), :4830-4859 (the unused
    resolveOwnedStore), :5267 (ChangeStore using it). Commands: `grep -rn "function
    resolveOwnedStore" app/ --include=*.php` (two definitions only:
    Api/DashboardController.php:4830, StoreController.php:838) and `awk 'NR>=5336 && NR<=5346'
    app/Http/Controllers/Api/DashboardController.php`

- **The map said:** "NOT ONE store-governance route carries any authorization middleware ... Of the
  29 routes that DO carry `PlatformOperatorOnly`, zero are store governance" — presented as the
  complete authorization picture for the area, with PlatformOperatorOnly as the only operator-gating
  middleware.
  **Wrong because:** The scoped claim about store governance survives, but the area inventory does
    not: ELEVEN routes carry a real authorization middleware the map never mentions —
    `Illuminate\Auth\Middleware\Authorize:manage-platform-addons`, i.e. `can:manage-platform-
    addons`. They are the add-on CRUD plus install-modules, theme-addon and theme-enable: platform
    OPERATIONS tooling, which is half of what P06-04's two words ask for. The map's own quoted
    P06-04 row names this group explicitly ('a `can:manage-platform-addons` route group at
    routes/web.php:1155') and names `App\Support\Platform\PlatformCapabilities`, and neither appears
    anywhere in `what_exists`, `routes` or `authorization`. Worse for the map's census: the gate
    behind them is a FOURTH spelling of the operator test — an exact, un-normalised `$user->type ===
    UserPolicy::PLATFORM_ADMIN` inside a Gate closure — which the map's exact-vs-normalised table
    does not list. (A `grep` for 'can:' over route:list returns 0 because Laravel resolves the alias
    to the Authorize class; that is probably why the map missed them.)
  **Measured by:** app/Providers/AuthServiceProvider.php:43-45 (the Gate::define with the exact
    `===` compare); routes/web.php:1155 and :1611 (the two middleware attachments);
    app/Support/Platform/PlatformCapabilities.php (15,527 bytes, public API at
    :177,:190,:201,:214,:234,:270). The 11 routes: addon (GET/POST), addon-apps, addon/create,
    addon/{addon} (GET/PUT/DELETE), addon/{addon}/edit, install-modules, theme-addon, theme-enable.
    Command: python3 filter over route:list --json for any middleware containing 'Authorize'.

- **The map said:** The map's `routes` array and `what_exists` present the store-lifecycle surface
  as fully inventoried (23 StoreController methods + user-unable + user-enable-login + one API
  delete door).
  **Wrong because:** It misses two more admin-API store-lifecycle doors, one of which writes the
    tenant discriminator the map itself calls 'the highest-value predicate in the file'. `POST
    api/admin/createStoreApi` is TENANT CREATION — it writes a `stores` row, enforces `max_stores`,
    and is authorized by `in_array($user->type, ['admin','super admin'])`, a FIFTH spelling of the
    operator test. `POST api/admin/changestore` is the API twin of the web `changeStore` and writes
    `users.current_store`. Neither appears in the map. A Phase 06 governance design taken from this
    map would add state to `stores` and leave the API creation door writing rows that do not carry
    it.
  **Measured by:** app/Http/Controllers/Api/DashboardController.php:7967 (createStoreApi),
    authorization :7971, quota :7983-7987, `Store::create` :7991-7998; and :5237 (ChangeStore),
    ownership :5267, discriminator write :5273-5274. Command: python3 filter over route:list --json
    for uri startswith 'api/admin' and 'store' in uri — returns changestore, createStoreApi,
    deletestore, deliveryboyStore, getStoreInfo, store/fcm-token, updateStoreLanguage,
    updateStoreOwnerInfo.

- **The map said:** The map treats `changeStatus` as a plain boolean flag write — "Writes
  `stores.is_active` only" — and reports its only weakness as the missing audit row and the absent
  login propagation.
  **Wrong because:** MISSED DEFECT, and it is in the very handler the map calls door 1.
    `changeStatus` contains ZERO `validate()` calls and writes `$request->status` RAW into the
    column, while its last-active-store guard tests only `$request->status == 0`. So `status=2`
    passes the guard (2 != 0) and writes `is_active = 2`. Every live reader of the column tests
    `is_active = 1` — ActiveTheme.php:21, the guard's own count at :1293, UserUnable's count at
    :907-909 — so the store becomes non-active while the 'you cannot deactivate this store as it is
    the last active store' refusal never fires. Door 2 normalises its input (`$isActive = (int)
    ((bool) $request->is_active)` at UserController.php:833); door 1 does not. This is a measured
    bypass of the one business rule the area actually enforces today, and it would silently defeat
    any status enum migrated onto this column.
  **Measured by:** app/Http/Controllers/StoreController.php:1295 (`if ($activeStoresCount == 1 &&
    $request->status == 0)`) and :1302 (`$store->is_active = $request->status;`). Commands: `awk
    'NR>=1292 && NR<=1306 {printf "%d: %s\n", NR, $0}' app/Http/Controllers/StoreController.php` and
    `awk 'NR>=1276 && NR<=1312' app/Http/Controllers/StoreController.php | grep -c validate` -> 0.

- **The map said:** Citation drift on load-bearing lines — the map's numbers do not point at the
  code it describes in at least eight places.
  **Wrong because:** Each of these is a line a reader would open to verify a central claim, and each
    lands on the wrong statement. ActiveTheme's commented abort is :23-25, not ':24-26' (:26 is the
    live `if($store && $store->theme_id) {`), and its empty-theme fallback is :55, not ':57'.
    `getRouteStoreId()` is defined at :3804 and its unfiltered
    `Store::where('slug',$slug)->value('id')` is at :3827 — ':3822' is a bare `return null;`. The
    caller's-own-store count in ownerstoredestroy is :989, not ':987' (:987 is a closing brace). In
    UserUnable the is_active write is :913 not ':912', and the login propagation is :915 not ':920'
    (:920 is `if ($stores_enabled) {`). In the API DeleteStore the delete is :5443 not ':5444', the
    replacement lookup :5448-5450 not ':5450-5453', the repoint :5467-5470 not ':5466-5470'. Two
    counts are also wrong: StoreController has 22 public methods (+2 private = 24 signatures), not
    '23 public methods'; and there are 22 App\StoreController routes, not 'the 19 StoreController
    routes'. The lynomia surface is 15 routes, not '13 operations' / 'GET + 12 siblings' — and the
    map's own PlatformOperatorOnly breakdown sums to 27 while the total it states, 29, is correct
    (measured split: lynomia 15, plan-coupon 7, platform/analytics 2, zid 2, order_approve 1,
    order_reject 1, refund 1). Finally the map reports `grep -n current_store` on StoreController as
    returning 'only :297, :907, :1061'; it returns six lines (297, 875, 877, 885, 907, 1061), three
    of them comment lines — the comment-contamination failure CLAUDE.md §3 warns about. The map's
    conclusion there (no current_store statement in :608-689) is nonetheless correct.
  **Measured by:** app/Http/Middleware/ActiveTheme.php:23-25,:26,:55;
    app/Helper/helper.php:3804,:3822,:3827; app/Http/Controllers/StoreController.php:989,:1058-1061;
    app/Http/Controllers/UserController.php:913,:915,:920;
    app/Http/Controllers/Api/DashboardController.php:5443,:5448-5450,:5467-5470. Commands: `cat -n
    app/Http/Middleware/ActiveTheme.php`; `awk 'NR>=3770 && NR<=3830 {printf "%d: %s\n", NR, $0}'
    app/Helper/helper.php`; `awk 'NR>=985 && NR<=990' app/Http/Controllers/StoreController.php`;
    `grep -n "public function\|private function" app/Http/Controllers/StoreController.php` (24
    signatures); `grep -n current_store app/Http/Controllers/StoreController.php`; python3 counts
    over route:list --json.

- **The map said:** Gap 7: "`grep -rn \"'Manage Store'|Create Store|Delete Store|Edit Store\"
  database/seeders/ app/` finds only `Manage Store Analytics` and `Manage Store Setting`."
  **Wrong because:** The grep finds a THIRD string, `Edit Store Setting`. It is still merchant-side
    and still not a governance permission, so the map's CONCLUSION (no Laratrust permission exists
    for tenant governance) stands — but the stated measurement is incomplete, and anyone re-running
    the census to confirm the gap will get a different answer than the map reports.
  **Measured by:** app/Models/Utility.php:3063 (`'Edit Store Setting',`) and :3305 (`'Edit Store
    Setting'`). Command: `grep -rn "Manage Store\|Create Store\|Delete Store\|Edit Store\|Show
    Store" app/ database/seeders/ --include=*.php`

**And 9 things the map did not say at all:**

- app/Providers/AuthServiceProvider.php:43-45 + routes/web.php:1155,:1611 — the `can:manage-
  platform-addons` Gate and its ELEVEN gated routes (addon GET/POST, addon-apps, addon/create,
  addon/{addon} GET/PUT/DELETE, addon/{addon}/edit, install-modules, theme-addon, theme-enable). An
  operator-only platform-OPERATIONS surface that P06-04's own traceability row names and the map's
  inventory omits entirely. The gate is a Gate::define closure, so it WORKS despite
  `permissions_as_gates => false`, and it tests `$user->type === UserPolicy::PLATFORM_ADMIN` — exact
  and un-normalised, a spelling absent from the map's operator census.

- app/Support/Platform/PlatformCapabilities.php (15,527 bytes; public API at :177 groupNames, :190
  available, :201 declared, :214 moduleIsInstalled, :234 themes, :270 planHighlights) — named
  verbatim in the P06-04 row the map quotes, never inventoried.

- app/Http/Controllers/Api/DashboardController.php:7967 — `POST api/admin/createStoreApi`, tenant
  CREATION. Authorization `in_array($user->type, ['admin','super admin'])` at :7971 (a fifth
  operator spelling); `max_stores` quota at :7983-7987; `Store::create` at :7991-7998; wallet
  provisioning at :8010. A governance door with no audit row and no governance state.

- app/Http/Controllers/Api/DashboardController.php:5237 — `POST api/admin/changestore`, the API twin
  of the web `changeStore`. Writes `users.current_store` (the tenant discriminator the map calls the
  highest-value predicate in StoreController) at :5273-5274, via `resolveOwnedStore()` at :5267.

- app/Http/Controllers/Api/DashboardController.php:4830-4859 —
  `Api\DashboardController::resolveOwnedStore()`, a SECOND shared tenant helper the map's "three
  different hand-written forms" does not count. Its own comment says it "carries the tenancy
  decision for 102 call sites in this file".

- app/Http/Controllers/StoreController.php:1295 + :1302 — the unvalidated `$request->status` write
  that bypasses the last-active-store guard (full reasoning in refutations).

- app/Models/User.php:392,:400,:403 — `User::assignPlan()` as a THIRD writer of `stores.is_active`,
  reachable unattended from SetLocate.php:75 and :86.

- app/Models/Utility.php:3063,:3305 — the third 'Store' permission string, `Edit Store Setting`.

- LEAD, NOT ESTABLISHED: resources/views/AppSetting/index.blade.php:285 passes `getCurrentStore()`
  as the id to `ownerstore.remove`. For an operator with an empty `current_store`,
  helper.php:888-889 returns the literal 1 — so that link would target store id 1, whoever owns it,
  and `resolveOwnedStore()` drops the created_by predicate for the operator. I did not establish
  that an operator can reach this view, so this is a lead that sharpens the map's existing operator-
  count finding rather than a new finding.

### Not established — stated as such rather than guessed

- WHAT P06-04 ACTUALLY ASKS FOR. The requirement text is two words in a derived summary; P06-01
  records that no Phase 06 source document exists in the repository or anywhere in git history. I
  confirmed `docs/development/phase-6/` is absent. Nothing in my measurement tells you what
  'controls' or 'operations' means to the owner, and I have not inferred it.

- Whether a disabled store's storefront is reachable END-TO-END. Established by reading the only
  three store-resolution sites (ActiveTheme.php:21 with its commented abort at :24-26;
  helper.php:3776-3779; helper.php:3822) and by counting route middleware (0 of 81 `api/{slug}`
  routes carry ActiveTheme). NOT driven by an HTTP request against an `is_active = 0` store. Write
  that premise test before acting on it.

- Whether PRODUCTION's `super admin` role carries `Edit User`. I measured only that
  `database/seeders/PermissionTableSeeder.php` attaches no permission to it and that
  `$superAdminRole` is a dead variable after :69. A live install may have had permissions hand-
  granted, or granted by a path I did not search (I did not read every migration or every other
  seeder). The refusal is certain on a freshly seeded install and in the test suite; its production
  reach is unmeasured.

- Governance surfaces in 68 of the 70 vendored modules. My `route:list` run loaded only LandingPage
  (213 routes) and LynomiaChat (99) — measured by counting `Workdo\*` actions. Per CLAUDE.md no
  WorkDo module loads without an `add_on_managers` row, so this is NOT a complete route census of
  the platform and I cannot say whether another module ships a store-governance door.

- Whether `StoreController::index`'s `creatorId()` scoping actually hides merchants in production.
  The query is measured; whether every merchant row really carries `created_by = <the single
  operator id>` is a production-data question I did not read.

- Whether the Discovery P0 'plan-expiry enforcement commented out' has any live consequence. I
  CONFIRMED it is still commented at this head — app/Http/Middleware/SetLocate.php:79 (`// return
  redirect()->route('plan.index')->with('error', __('Your Plan is expired.'));`) and :87-88 for the
  trial — and that what still runs is `$user->assignPlan(1)` on trial expiry (a HARDCODED plan id 1)
  and `assignPlan($plan->id)` for a `duration = 'Unlimited'` plan when `plan_id` is empty, both of
  which silently disable overflow stores and users via User.php:359-406. I did NOT measure what plan
  1 is in production, so I cannot say how many stores that disables.

- Whether `changeStatus`'s last-active-store guard can be raced. It reads a count then writes with
  no lock or transaction (StoreController.php:1294-1302), unlike `destroy()` which was given
  `lockForUpdate()` inside `DB::transaction()` (:656-675). I read the difference; I did not attempt
  to drive the race.

- The reachability of the vendored `POST /bulk-delete` by a non-operator. The handler has no tenant
  predicate and its map includes `plan-order`, `plan-coupon`, `plan-request` and `roles`, but it is
  gated by `PlanModuleCheck:BulkDelete` and the module did not load in my route:list run, so I could
  not confirm whether it is live on a given install or who can reach it. RECORDED AS A LEAD, not a
  finding.

---

## Area D — Platform admin and its premium UX

**Rows:** `P06-05, P06-06` · **Buildable without an owner decision:** YES · **Refutation verdict:** `SOUND_WITH_CORRECTIONS` (6 refutations, 6 omissions found)

### What exists (20 items)

- **`App\Http\Middleware\PlatformOperatorOnly` — the route-level operator gate. Operator test is
  `strtolower(trim((string) $user->type)) === 'super admin'`. Anonymous callers are passed through
  deliberately (`auth` runs first and owns the sign-in redirect); refusal is 403 with a JSON branch
  for `expectsJson()`, never 401.**
  `app/Http/Middleware/PlatformOperatorOnly.php:47-73 (const PLATFORM_ADMIN at :49, the comparison
    at :61, the anonymous pass-through at :57-59)` — *first-party*

- **EXACTLY 29 routes carry `PlatformOperatorOnly` at this HEAD, out of 1,496 registered routes.
  Breakdown: 2 bank-transfer plan-order decisions (order.approve, order.reject), 7 `plan-coupon`
  resource routes, 2 `platform/analytics` (index + export), 1 `plan.order.refund`, 15 `super-
  admin/lynomia-integration/*`, 2 `zid/{storeId}/*` closures. Every one of the 27 non-zid routes
  carries the same stack: web, Authenticate, XSS, SetLocate, EmailIsVerified, PlatformOperatorOnly.
  The 2 zid closures carry only web, Authenticate, PlatformOperatorOnly (no XSS, no SetLocate, no
  EmailIsVerified).**
  `routes/web.php:549, 572, 716, 719, 1241, 1246, 1914, 1918;
    packages/workdo/LynomiaChat/src/routes/web.php:564 (the group of 15)` — *first-party*

- **Two further route-level operator-ish gates: `Authorize:manage-platform-addons` on 11 routes
  (addon resource x8, install-modules, theme-addon, theme-enable) and `PlanOrderViewGate` on 1
  (`plan/orders/show/{id}`). The `manage-platform-addons` Gate closure is a FOURTH, un-normalised
  operator spelling: `$user->type === UserPolicy::PLATFORM_ADMIN` (strict `===`, no trim, no
  strtolower). TOTAL route-level operator-gated surface = 29 + 11 + 1 = 41 routes.**
  `app/Providers/AuthServiceProvider.php:43-45; routes/web.php:1611;
    app/Http/Middleware/PlanOrderViewGate.php:32-60` — *first-party*

- **The operator dashboard: `HomeController::index()` dispatches on `users.type` and renders
  `superadmin.dashboard`; `handleSuperAdmin()` builds 6 stat cards plus 3 panels. Card data:
  `stores` = `$user->countCompany()`, `orders` = `PlanOrder::total_orders()`, `plans` =
  `Plan::total_plan()`, `$couponName` (a loop over every PlanCoupon), `$plan_order` =
  `Plan::most_purchese_plan()`, `$plan_requests` = `PlanRequest::count()`. Plus
  `topStoresByCurrency` and `popularStores` via `PlatformAnalyticsFigures` (already repaired under
  P05-R3/R3b, with scope declared at the call site), and `topAdmins`.**
  `app/Http/Controllers/HomeController.php:209-223 (index), :225-309 (handleSuperAdmin);
    resources/views/superadmin/dashboard.blade.php:29-46 (the six cards)` — *first-party*

- **CONFIRMED — *Popular Plan* reads every `plan_orders` row into PHP. `Plan::most_purchese_plan()`
  is `PlanOrder::pluck('plan_id','id')->toarray()` then `array_count_values()` / `max()` /
  `array_search()` in PHP. No predicate, no limit, no aggregate, no GROUP BY. It also resolves ties
  arbitrarily (`array_search` returns the first key holding the max).**
  `app/Models/Plan.php:80-94 (the pluck at :82)` — *first-party*

- **CONFIRMED — no `payment_status` predicate on the plan-order figures. `PlanOrder::total_orders()`
  is a bare `PlanOrder::count()`; `most_purchese_plan()` plucks every row; `$plan_requests` is a
  bare `PlanRequest::count()`. So pending, failed, rejected and refunded subscription orders all
  count toward *Total Plan Orders* and toward which plan is *Popular*. `PlanOrder`, `PlanRequest`,
  `Plan` and `PlanCoupon` are ALL absent from `config/tenancy.php`'s `scoped_models`, so no global
  scope narrows them either — which is correct for an operator screen but means the breadth is
  undeclared at these two call sites, unlike the two panels beside them.**
  `app/Models/PlanOrder.php:34-37; app/Models/Plan.php:82;
    app/Http/Controllers/HomeController.php:305; config/tenancy.php:141-176` — *first-party*

- **CONFIRMED — *Total Store* counts MERCHANTS, not stores. `User::countCompany()` is
  `User::where('type','admin')->where('created_by', $this->creatorId())->count()` — a count of
  type-`admin` USER rows. `stores` is a separate table and `User::stores()` is a `hasMany`, so one
  merchant owns N stores. The card is labelled `__('Total Store')` and links to
  `route('stores.index')`, whose handler `StoreController::index()` also lists USERS (`User::select(
  ['users.*'])->join('stores',...)->where('users.type','admin')->groupBy('users.id')`) and whose own
  breadcrumb already reads `__('Users')`. So the number, the destination screen and the breadcrumb
  are all consistently merchants; only the WORD "Store" on the card is wrong.**
  `app/Models/User.php:430-433 (countCompany), :190-193 (stores hasMany);
    app/Http/Controllers/HomeController.php:244;
    resources/views/superadmin/dashboard.blade.php:29-30;
    app/Http/Controllers/StoreController.php:42-55; resources/views/store/index.blade.php:27` —
    *first-party*

- **CONFIRMED and WIDER THAN REPORTED — one request path holds TWO definitions of the operator, and
  the surface holds FOUR normalisation rules in total. `HomeController::index():214` is `$user->type
  == 'super admin'` (loose `==`, NO normalisation). It calls `handleSuperAdmin()` →
  `getOrderChart():826`, which asks `UserPolicy::isOperator($user)` (`strtolower(rtrim($type,'
  '))`). `handleRegularUser():567` calls the SAME `getOrderChart()`. So a row typed `'Super Admin'`
  or `'super admin '` is routed to the MERCHANT dashboard by :214 while :826 treats it as the
  operator — skipping the no-store sign-out at :828 and building the chart from unscoped
  `PlanOrder`. The other two rules: `PlatformOperatorOnly::handle():61` uses `strtolower(trim(...))`
  (BOTH ends — deliberately wider than `isOperator()`, which right-trims only to match MySQL PAD
  SPACE), and `AuthServiceProvider:44` uses bare `===`.**
  `app/Http/Controllers/HomeController.php:214 and :826 and :567; app/Policies/UserPolicy.php:243;
    app/Http/Middleware/PlatformOperatorOnly.php:61; app/Providers/AuthServiceProvider.php:44` —
    *first-party*

- **The divergence above is KNOWN IN AGGREGATE AND CAPPED, not individually tracked.
  `OperatorCheckCensusTest` ratchets un-normalised operator comparisons under `app/` at CEILING 101.
  I re-implemented its exact three patterns and comment-stripping and measured the LIVE total: 87
  across 639 files (20 strict-against-constant, 58 loose-against-literal, 9 strict-against-literal).
  The test names only THREE sites that must use the shared helper (OrderPolicy,
  OrderDocumentAccessGuard, OrderController::fileExport). `HomeController::index():214` and
  `AuthServiceProvider:44` are inside the 87 and inside the ceiling — so nothing asserts that
  `index()` and `getOrderChart()` agree with each other.**
  `tests/Feature/Security/OperatorCheckCensusTest.php:47 (CEILING = 101), :50-53 (PATTERNS), :83-114
    (the ratchet), :129-152 (the three named sites)` — *first-party*

- **DESIGN-SYSTEM ADOPTION ON THE OPERATOR SURFACE IS ESSENTIALLY ZERO. `public/css/store-syria-
  ds.css` (7,569 lines) and the 28-component `<x-ss.*>` library are loaded UNCONDITIONALLY on every
  admin page by `layouts/app.blade.php:274-285`, so the asset is present everywhere — but the DS is
  opt-in BY MARKUP. Comment-stripped, boundary-correct census of 15 operator-reachable views:
  `platform/analytics.blade.php` uses 6 `<x-ss.*>` (stat x6, stats x1) and 4 distinct `ss-` classes;
  EVERY other operator view uses ZERO `<x-ss.*>` components. `superadmin/dashboard.blade.php` has 0
  components and 2 distinct `ss-` classes (ss-field__hint, ss-num). plans/, plan-coupon/,
  plan_request/, store/, addon/, country/, language/ index views carry one `ss-breadcrumb__item`
  each and nothing more; `plans/action.blade.php` has 4 `ss-btn*` classes. `order/show.blade.php`
  and the three Lynomia operator views have ZERO of either.**
  `resources/views/layouts/app.blade.php:274 and :285; resources/views/layouts/includes/store-syria-
    ds.blade.php:28-29 (the now-redundant opt-in partial); public/css/store-syria-ds.css (7569
    lines); resources/views/components/ss/ (28 files);
    resources/views/superadmin/dashboard.blade.php; resources/views/platform/analytics.blade.php` —
    *first-party*

- **The operator dashboard's stat card is a pre-DS WorkDo component, not `<x-ss.stat>`.
  `components/dashboard-card.blade.php` (20 lines) emits `.details-card` Bootstrap markup, injects
  its icon with raw `{!! $icon !!}`, prints the value as `{{ $count ?? 0 }}` with no number
  formatting or caveat slot, and inverts heading levels: the LABEL is an `<h2 class="h5">` and the
  VALUE an `<h3 class="h3">`. Consequence measured in the view: two cards pass a STRING into that
  numeric slot — `:count="$couponName"` under `:label="__('Total Coupon')"` and
  `:count="$plan_order"` under `:label="__('Popular Plan')"` — so a card headed "Total Coupon"
  renders a coupon's NAME. By contrast `<x-ss.stat>` (used only on platform/analytics) supports a
  caveat/hint, which is what Phase 05's disclosure work was built on.**
  `resources/views/components/dashboard-card.blade.php:1-20 (value at :15, label at :14);
    resources/views/superadmin/dashboard.blade.php:41-44` — *first-party*

- **An N+1 beside the unbounded Popular Plan read: `handleSuperAdmin()` does `PlanCoupon::get()`
  then calls `$coupon->used_coupon()` inside a foreach, and `used_coupon()` is
  `$this->hasMany(PlanUserCoupon::class,...)->count()` — one COUNT query per coupon, unbounded by
  coupon count, on every load of the operator dashboard.**
  `app/Http/Controllers/HomeController.php:257-266; app/Models/PlanCoupon.php:21-24` — *first-party*

- **BILINGUAL: translation coverage on the operator surface is COMPLETE today and the values are
  real Arabic; what is missing is the PIN. I scanned 19 operator-reachable views for `__()` strings
  with comments stripped and checked each against `resources/lang/ar.json` (4,538 keys): 314
  translatable strings, 0 with no Arabic. Spot-checked 11 operator-dashboard/analytics keys — all
  contain Arabic script (e.g. 'Total Store' → 'إجمالي المخزن', 'Popular Plan' → 'الخطة الشعبية').
  Only 31 of 4,538 ar.json values contain no Arabic script at all.**
  `resources/lang/ar.json; resources/views/superadmin/dashboard.blade.php (16 strings, 0 missing);
    resources/views/platform/analytics.blade.php (25 strings, 0 missing); plans/ plan-coupon/
    plan_request/ store/ addon/ country/ language/ (the remaining 273)` — *first-party*

- **ANSWERING THE TASK'S DIRECT QUESTION: `resources/views/platform/*.blade.php` IS scanned by
  ArabicUiCoverageTest (glob 14 of 16, added 2026-11-21 per the comment at :86-93) — but that glob
  matches 9 files and only ONE of them (`analytics.blade.php`) is operator-gated; the other 8 (home,
  features, onboarding, pricing, faq, contact, legal, layout) are the PUBLIC Phase-03 marketing
  site, carrying only `web, SetLocate` with NO `Authenticate`.
  `resources/views/platform/docs/*.blade.php` (7 files) is NOT matched — the glob does not descend.
  And `resources/views/superadmin/` is scanned by NOTHING: `grep -n superadmin
  tests/Unit/Merchant/ArabicUiCoverageTest.php` exits 1, zero occurrences. Of the 19 operator views
  I scanned, 17 are outside the glob set. So the 314 translated strings are unpinned and can regress
  silently.**
  `tests/Unit/Merchant/ArabicUiCoverageTest.php:46-103 (the 16 globs; platform at :94), :177 (`$path
    = self::root() . '/resources/lang/ar.json'`), :23-24 ("SCOPE IS THE DIRECTORY LIST,
    DELIBERATELY"); app/Http/Controllers/PlatformSiteController.php:51-118 and
    PlatformDocsController.php:43-102 (the public handlers)` — *first-party*

- **A SECOND, UNCHECKED TRANSLATION CATALOGUE. The Lynomia operator screen's Arabic lives in
  `packages/workdo/LynomiaChat/src/resources/lang/ar.json` (830 keys), loaded by
  `loadJsonTranslationsFrom()` in the module provider. ArabicUiCoverageTest reads ONLY
  `resources/lang/ar.json` (:177), so no test can see that catalogue — and the test's own docblock
  at :33-36 already warns "a string can be absent from both files and that test passes" /
  "Translations belong in BOTH files." Measured against BOTH files, the 3 Lynomia operator views
  hold 123 distinct strings and 7 unresolved, and all 7 are artefacts of my own extraction (6
  strings containing an escaped `\'` that my regex truncated, plus the dynamic key prefix
  `__('lynomia-chat::contact.' . $reason)`). Real missing Arabic on that screen: effectively 0.**
  `packages/workdo/LynomiaChat/src/resources/lang/ar.json (830 keys);
    packages/workdo/LynomiaChat/src/app/Providers/LynomiaChatServiceProvider.php:473-480;
    tests/Unit/Merchant/ArabicUiCoverageTest.php:177` — *first-party*

- **The P06-05 row's "different branch, different project" claim is REFUTED.
  `Workdo\LynomiaChat\app\Http\Controllers\SuperAdmin\IntegrationController` exists at this HEAD
  (884 lines, 15 public action methods + constructor) and all 15 of its operator routes are in the
  live `route:list`. It is the single LARGEST operator-only surface in the product — 15 of the 29
  PlatformOperatorOnly routes, i.e. 52%. It is reachable in every test run because
  `PackageServiceProvider::register()` loads LynomiaChat STATICALLY (`$staticAddons =
  ['LandingPage', 'LynomiaChat']`), exempting it from the `add_on_managers` gate that blinds the
  suite to every other module. The matrix's separate claim "`grep -n SuperAdmin routes/web.php`
  returns nothing" is still literally TRUE (exit 1) — the routes are declared in the module's own
  routes file, which is why the row read thin.**
  `packages/workdo/LynomiaChat/src/app/Http/Controllers/SuperAdmin/IntegrationController.php:1-884
    (actions at :87 show, :394 updateMarketingAllowance, :427 storeAddon, :462 grantAddon, :528
    updateSeats, :607 issueCommerceCredential, :669 revokeCommerceCredential, :697 approve, :718
    revoke, :739 restore, :763 suspend, :772 resume, :786 decommission, :801 reauthenticate, :868
    remoteHealth); packages/workdo/LynomiaChat/src/routes/web.php:558-566 (the gated group);
    app/Providers/PackageServiceProvider.php:16-32` — *first-party*

- **PHASE 2.5 EXPLICITLY DEFERRED THREE OPERATOR SURFACES TO PHASE 06, BY NAME, and they are the
  only operator rows in its 342-row coverage matrix: Storage Settings tab, Cache Settings tab, and
  Staff detail / user info (`GET /user-info/{id}`). All three are marked **OUT OF SCOPE** with the
  reason "Phase 06 operator scope, not Phase 2.5". The matrix also names a FIFTH operator gate site:
  `setting/index.blade.php:75`, a Blade `@if (auth()->user()->type == 'super admin')` that hides
  those settings tabs.**
  `docs/development/phase-2.5/PHASE-2.5-COVERAGE-MATRIX.md:158 (Storage Settings), :160 (Cache
    Settings), :173 (user-info); resources/views/setting/index.blade.php:75` — *first-party*

- **Those three deferred surfaces are gated in BLADE and in the HANDLER, never on the route — and I
  checked each handler rather than asserting. route:list shows all three carry only `web,
  Authenticate, XSS, (SetLocate,) EmailIsVerified`. Two ARE guarded internally:
  `UserController::userInfo():727` is operator-or-self through `UserPolicy::isOperator()`;
  `SettingController::StorageSettings():215` is `StoreSettingsAccess::permits()` (operator, then
  store owner, then `Manage Setting`) — i.e. WIDER than the Blade `@if` that hides the tab, so
  merchants are admitted to the POST while the UI hides it. The THIRD, `GET /config-cache`, is a
  route CLOSURE with ZERO authorization in its body: it runs `Artisan::call('optimize')`,
  `route:clear`, `view:clear`, `optimize:clear --except=cache` and `config:clear` for any verified
  authenticated user.**
  `routes/web.php:873-898 (the config-cache closure; the body has no auth check);
    app/Http/Controllers/UserController.php:673-729 (guard at :727);
    app/Http/Controllers/SettingController.php:210-217 (guard at :215);
    app/Services/StoreSettingsAccess.php` — *first-party*

- **The config-cache gap is a KNOWN, consciously-deferred residual — NOT a new finding. The
  destructive part (whole-store `cache:clear` erasing other tenants' payment keys) was FIXED under
  F197; the missing permission check is recorded as open in the code comment itself and in the
  ledger. One doc-drift to note: the runbook and ledger cite `routes/web.php:776` for this route; it
  is now at :873.**
  `routes/web.php:869-872 ("STILL OPEN AND RECORDED, NOT FIXED HERE: this route has no permission
    check... See F197."); docs/development/phase-2/PHASE-02-TASK-LEDGER.md:311 (F197);
    docs/development/phase-2/F194-MODEL-CACHE-ISOLATION-RUNBOOK.md:106 ("any authenticated merchant,
    no permission check")` — *first-party*

- **The operator analytics screen (the one genuinely premium operator surface) is
  `PlatformAnalyticsController` + `PlatformAnalyticsFigures::forPlatform()`, carrying 9 figure
  groups plus a coverage object: stores, orders, visits, buyers, retention, payments, profit,
  devices, sources. Export shares the same figures class and the same gate.**
  `app/Http/Controllers/PlatformAnalyticsController.php:48-53 (index), :76-82 (breakdownLabels),
    :100+ (figureLabels); app/Support/Metrics/PlatformAnalyticsFigures.php:80-89 (forPlatform)` —
    *first-party*

### Traps — things that read as working and do not

- THE P06-05 ROW IS STALE IN THE DIRECTION THAT MATTERS, AND GAP-178 ASKS EXACTLY THIS. The row says
  the Lynomia `SuperAdmin/IntegrationController` is "on a different branch and a different project".
  At this HEAD it is
  `packages/workdo/LynomiaChat/src/app/Http/Controllers/SuperAdmin/IntegrationController.php` (884
  lines, 15 operator actions), its 15 routes are in `php artisan route:list`, and it is 52% of the
  operator-gated surface. Anyone scoping P06-05 from the row will size the surface at roughly half
  of what exists. The row's sibling claim `grep -n SuperAdmin routes/web.php` returns nothing is
  still literally true (exit 1) — the routes are in the module's own routes file, which is why the
  row read thin.

- A CENSUS OF `ss-` CLASSES OVER RAW BLADE IS WRONG TWICE OVER, AND IT CAUGHT ME. `grep -o
  'ss-[a-z]'` matches inside ordinary words ("address-line" → "ss-line") and matches DS class names
  written in `{{-- --}}` prose explaining the design system. My first pass reported 12 ss- hits on
  plans/create.blade.php and 16 on platform/analytics; comment-stripped with a `(?<![A-Za-z0-9_-])`
  boundary the real figures are 10 (3 distinct, all breadcrumb/label-required) and 16 (4 distinct).
  CLAUDE.md warns about this in three syntaxes; it bites hardest on operator views, whose docblocks
  are unusually long. Use `Tests\Support\StripsSourceComments` for PHP and strip `{{-- --}}` for
  Blade, and scope the search to the block.

- A MODULE'S ARABIC IS IN A SECOND CATALOGUE AND CHECKING ONLY THE GLOBAL ar.json PRODUCES A HUGE
  FALSE POSITIVE. I measured the Lynomia operator views against `resources/lang/ar.json` alone and
  got "103 of 119 strings have no Arabic". `packages/workdo/LynomiaChat/src/resources/lang/ar.json`
  holds 830 keys and is loaded by `loadJsonTranslationsFrom()` at
  LynomiaChatServiceProvider.php:473-480; merged, the real figure is effectively 0 missing. I nearly
  reported a 103-string bilingual gap that does not exist. ArabicUiCoverageTest reads only the
  global file (:177) and its own docblock at :33-36 says so — "Translations belong in BOTH files" —
  so no test can see that catalogue either way.

- `resources/views/platform/` IS NOT THE OPERATOR SURFACE, DESPITE THE NAME, AND THE TASK'S
  BILINGUAL QUESTION TURNS ON THIS. Of the 9 files the Arabic glob matches, ONE
  (analytics.blade.php) is behind PlatformOperatorOnly; the other 8 are the public Phase-03
  marketing site carrying only `web, SetLocate` with NO Authenticate. So "platform/* is scanned" is
  true and gives almost no operator coverage. The operator views are under
  `resources/views/superadmin/` (1 file) plus plans/, plan-coupon/, plan_request/, store/, addon/,
  country/, language/ — none of them scanned. And the glob does not descend:
  `platform/docs/*.blade.php` (7 files) is unmatched, the same `product/*` vs `product/partials/*`
  hazard CLAUDE.md records.

- FIXING "Total Store" BREAKS A GREEN TEST. `OperatorDashboardWithoutAStoreTest.php:112` asserts the
  card equals `$operator->fresh()->countCompany()` — the implementation itself. Change the
  controller to count `Store` rows and that test goes red. It must be updated in the same slice, and
  the slice should assert against `Store::count()` (through `withoutGlobalScopes()` if `Store` is
  ever added to scoped_models) rather than against another call to the helper. Also note the label
  is the ONLY thing that is wrong: the card's destination `StoreController::index()` lists merchants
  and `store/index.blade.php:27`'s breadcrumb already says `__('Users')` — so the cheap, correct fix
  may be renaming the card, not rewriting the figure. Which of the two is intended is a product
  decision.

- THE OPERATOR DASHBOARD IS A KNOWN OUTPUT-BUFFER AND SELF-ASSIGNMENT MINEFIELD AND THE COMMENTS SAY
  SO. `superadmin/dashboard.blade.php:9-13` records that `@section('action-button')` was opened and
  never closed, leaking one output buffer per render and making PHPUnit mark any test that rendered
  it as risky — which is why none did. HomeController:227-242 records that three figures used to be
  assigned onto the authenticated `User` model as non-columns, so a later save became `UPDATE users
  SET total_user = ...` and 500'd the operator's landing page. :181-184 of the view records that the
  Top Customers loop used to be named `$user`, overwriting the authenticated principal for the rest
  of the view and the layout. All three are fixed; all three will re-appear if the view is rewritten
  from scratch rather than edited.

- RENDERING THIS SCREEN IN A TEST CAN POISON THE WHOLE SUITE. F197 records that driving `GET
  /config-cache` calls `Artisan::call('optimize')`, which WRITES bootstrap/cache/config.php,
  events.php and routes-v7.php — files that persist after the process and make every later `php
  artisan test` boot from them. It contaminated a full gate run on aea28d08 with two phantom
  failures that passed in isolation. No test now drives that route or calls `optimize`; do not add
  one. If a P06 slice must prove something about that route, put it in `tests/Support/Security/bin/`
  as an executable script, as `verify-login-and-settings-cache-paths-on-mysql.php` already does for
  its sibling cases.

- THE TWO zid ROUTES ARE GATED DIFFERENTLY FROM THE OTHER 27 AND IT IS EASY TO COPY THE WRONG STACK.
  routes/web.php:1912-1918 carry only `web, Authenticate, PlatformOperatorOnly` — no XSS, no
  SetLocate, no EmailIsVerified. Every other PlatformOperatorOnly route carries all six. If P06
  builds new operator routes by copying a neighbour, copy a plan-coupon or analytics route, not a
  zid closure. (`ZidAdminRoutesOperatorOnlyTest` covers the gate, not the rest of the stack.)

- ADDING A ROUTE TO THE OPERATOR SURFACE WITH THE WRONG GATE CREATES A FIFTH SPELLING. There are
  already four normalisation rules for one decision, and they disagree on real inputs: a
  `users.type` of `'Super Admin'` or `'super admin '` is the operator to PlatformOperatorOnly
  (`trim`) and to UserPolicy::isOperator (`rtrim ' '`, matching MySQL PAD SPACE) but NOT to the
  `manage-platform-addons` Gate (`===`) and NOT to HomeController::index:214 (`==`). The last of
  those routes such a user to the MERCHANT dashboard while `getOrderChart():826` still treats them
  as operator — and `dashboard/overview.blade.php:620-623` DOES render `$chartData`, so the merchant
  dashboard would print platform-wide subscription volume. HomeController:865-868's comment calls
  this latent on the grounds that overview never references `$chartData`; `grep -rn chartData
  resources/views/` refutes that at :606-623. Use `UserPolicy::isOperator()` and nothing else;
  `OperatorCheckCensusTest`'s CEILING is 101 and the live count is 87, so a new un-normalised
  comparison will NOT fail CI.

- FOUR OF THE SIX CARDS ARE UNDECLARED CROSS-TENANT READS, NOT SCOPED ONES, AND THAT DISTINCTION
  WILL MATTER LATER. `PlanOrder::count()`, `PlanRequest::count()`, `Plan::count()` and
  `PlanOrder::pluck()` are platform-wide because those models are absent from `config/tenancy.php`,
  not because anything said so. The two panels beside them pass `MetricScope::stores($everyStoreId)`
  and say so in a comment. Copy the panels' pattern when touching the cards, so the intent survives
  any future change to the allowlist.

- THE CONFIG-CACHE PERMISSION GAP IS ALREADY WRITTEN DOWN — DO NOT RE-RAISE IT AS A DISCOVERY.
  routes/web.php:869-872 states "STILL OPEN AND RECORDED, NOT FIXED HERE: this route has no
  permission check... See F197", and F194-MODEL-CACHE-ISOLATION-RUNBOOK.md:106 lists it as "any
  authenticated merchant, no permission check". The destructive half (whole-store cache flush
  erasing other tenants' payment-replay claims) IS fixed. One doc-drift worth correcting while in
  the area: the runbook and ledger cite `routes/web.php:776` for this route; it is now at :873.

### Tenancy

The operator surface is, by design, where tenancy is NOT enforced — and that is the correct reading
  here, not a defect. What matters for P06-05/06 is whether the breadth is DECLARED at each call
  site. Models behind the operator dashboard and their scoping, measured: `PlanOrder`,
  `PlanRequest`, `Plan` and `PlanCoupon` are ALL absent from `config/tenancy.php`'s `scoped_models`
  (`grep -n 'PlanOrder\|PlanRequest\|Plan::' config/tenancy.php` → exit 1, zero matches; the
  allowlist I read holds Menu, SupportTicket, SupportConversion, FlashSaleCondition,
  ThemeCustomizeOrder among others). `StoreScope::isActiveFor()` short-circuits for a model outside
  that list, so no predicate is added for ANY principal. `Store` and `User` are likewise unscoped,
  as CLAUDE.md records. Two of the three panels on the dashboard declare their breadth explicitly
  and go through the metric layer: `topStoresByCurrency` and `popularStores` pass
  `MetricScope::stores($everyStoreId)` into `PlatformAnalyticsFigures`, with a comment at
  HomeController.php:269-304 stating "THE ONE DELIBERATELY CROSS-TENANT MONEY FIGURE ON THIS SCREEN,
  AND IT NOW SAYS SO WHERE IT IS READ" and an empty-platform guard so a no-store scope cannot
  compile to "0 = 1". That is the pattern P05-R3/R3b established and it is the pattern P06 should
  copy. Four of the six CARDS do not. `PlanOrder::count()`, `PlanRequest::count()`, `Plan::count()`
  and `PlanOrder::pluck('plan_id','id')` are bare reads with no scope argument, no predicate and no
  payment_status filter — platform-wide by accident of the model not being in the allowlist, rather
  than platform-wide by declaration. Same number either way today; the difference is that nothing
  records the intent, so a later addition of `PlanOrder` to `scoped_models` would silently change
  four operator figures. `null_tenant => 'open'` is not a hazard on this surface specifically,
  because every one of the 41 operator routes carries `Authenticate` first and the handlers derive
  nothing from request input for the figures. The one place the operator surface DOES read an id
  from the URL is `user-info/{id}`, and I read its guard: it accepts either a `Crypt::decrypt()` or
  a raw numeric id, treats NEITHER as proof (UserController.php:681), and then gates on operator-or-
  self at :727 — with a docblock recording that an earlier same-tenant limb was deliberately REMOVED
  because it let a zero-permission staff member read the roster. Tenant isolation is therefore not
  the open question for P06-05/06. The open question is label/figure honesty and gate consistency.

### Authorization

Permission strings and whether the operator is admitted, measured per mechanism: **(1) Route
  middleware `PlatformOperatorOnly` — 29 routes.** No permission string at all; the test is
  `strtolower(trim((string) $user->type)) === 'super admin'` (PlatformOperatorOnly.php:61). Operator
  ADMITTED. Anonymous callers are PASSED THROUGH deliberately (:57-59) because `auth` runs first and
  owns the sign-in redirect; a merchant gets 403 (never 401, because repo B's network layer forces
  logout on 401). **(2) Laravel Gate `manage-platform-addons` — 11 routes.** The closure is `$user
  !== null && $user->type === UserPolicy::PLATFORM_ADMIN` (AuthServiceProvider.php:44) — strict
  `===`, no trim, no strtolower. Operator ADMITTED for the canonical spelling only. A row typed
  `'Super Admin'` or `'super admin '` is admitted by PlatformOperatorOnly and REFUSED here, so the
  two gates disagree about who the operator is — exactly the drift `OperatorCheckCensusTest` was
  written to stop, in a site that census does not name. **(3) `PlanOrderViewGate` — 1 route.**
  Operator OR owning merchant. Same anonymous pass-through for the same reason (:40). **(4)
  Controller `if` blocks — the bulk of the screens the operator actually opens.**
  `StoreController::index():44`, `CountryController::index():20`, `CityController`,
  `StateController`, `AddonController:23/:184`, `PlanRequestController:67`,
  `LanguageController:455`, `PlanController:24` all test `auth()->user()->type == 'super admin'`
  directly, loose and un-normalised. Refusals are `redirect()->back()->with('error', ...)` — and the
  vocabulary is inconsistent: CountryController says `__('Permission Denied.')`, PlanController says
  `__('Permission denied.')`, StoreController says `__('Something went wrong')` for the same
  refusal. **(5) Blade `@if` — hides without gating.** `setting/index.blade.php:75` is `@if
  (auth()->user() && auth()->user()->type == 'super admin')`. It hides the Storage and Cache
  settings tabs; the POST/GET handlers behind them are gated differently
  (StoreSettingsAccess::permits — operator, owner, `Manage Setting`) or not at all (config-cache).
  **The CLAUDE.md trap does NOT bite the operator here, and I checked rather than assumed.**
  `PlanController::index():22` gates on `isAbleTo('Manage Plan')` BEFORE any type test, and
  `isAbleTo()` alone refuses the operator. I expected the operator to be locked out of `plan.index`
  — the destination of FOUR of the six dashboard cards. REFUTED BY EXECUTION: `php artisan test
  --filter=PanelListStoredXssTest` passes 10/10 including "a merchants name is not printed raw on
  the operators plan order list", "...on the operators store list" and "...on the operators plan
  request list" — so the seeded `super admin` ROLE (PermissionTableSeeder.php:55-66, permissions
  created by `Utility::addNewData()` which includes "Manage Plan" at Utility.php:2879/3135/3351)
  carries it and the operator reaches all three screens. I am reporting this as REFUTED rather than
  as a finding. **Four normalisation rules for one decision**, all on the operator surface: `==`
  with no normalisation (HomeController:214 and ~8 controllers), `===` with no normalisation
  (AuthServiceProvider:44), `strtolower(trim())` (PlatformOperatorOnly:61), `strtolower(rtrim(' '))`
  (UserPolicy:243 — deliberately matching MySQL PAD SPACE, documented at UserPolicy:74-93). The
  aggregate is ratcheted at CEILING 101 by `OperatorCheckCensusTest`; I measured the live total as
  87 across 639 files. So the divergence is known and capped but no test asserts that any two
  specific operator gates agree.

### What the suite already covers, and what it cannot reach

Operator-surface coverage exists but is thin and skewed, and one test pins the defect.
  **tests/Feature/Platform/ — 165 tests across 19 files** (counted with `grep -c 'public function
  test_'`). Of these, the operator DASHBOARD has 41: OperatorDashboardTopStoresTest 14,
  OperatorDashboardPopularStoresTest 10, OperatorTopStoresAreRankedByTheDatabaseTest 9,
  OperatorDashboardWithoutAStoreTest 8. All four were produced by the P05-R3/R3b top-stores work,
  i.e. they cover the two panels and NOT the six cards. The analytics screen has 68
  (PlatformAnalyticsScreenTest). 44 of the 165 are the PUBLIC Phase-03 site, not the operator
  surface: PlatformDocsRoutesTest 12, PlatformSiteRoutesTest 11, PlatformPricingTest 10,
  PlatformDocsBilingualTest 7, PlatformSiteBilingualTest 5 — easy to miscount as operator coverage
  because of the shared `platform` name. **Operator-only authorization: 34 files in
  tests/Feature/Security/** whose names begin Operator*/Platform*/Plan*, including PlanOrderRailTest
  (asserts order.approve/order.reject/plan.order.refund still carry BOTH PlatformOperatorOnly AND
  `auth`, the second because the middleware passes anonymous through),
  ZidAdminRoutesOperatorOnlyTest, AnalyticsRouteAuthorizationCensusTest (asserts
  PlatformOperatorOnly on the analytics routes AND asserts its ABSENCE where the report gate
  belongs), OperatorCheckCensusTest (the 101-ceiling ratchet). Lynomia's 15 operator routes are
  asserted by LynomiaConnectionTest:114, LynomiaCommerceCredentialUiTest:358,
  LynomiaMarketingAllowanceTest:235, LynomiaSeatAllowanceTest:252. **A TEST PINS THE "Total Store"
  DEFECT — a trap for whoever fixes it.**
  `tests/Feature/Platform/OperatorDashboardWithoutAStoreTest.php:111-120` builds its expectation as
  `'stores' => (int) $operator->fresh()->countCompany()` — i.e. by calling the implementation. It
  then asserts `assertCount(3, array_unique($expected))` as a premise so a number under the wrong
  label would be visible. So the merchant count is the asserted contract: changing the card to count
  `Store` rows will make this test FAIL, and it must be changed in the same slice. **NO coverage at
  all for:** the six dashboard cards' values and labels (nothing asserts *Total Store* equals
  anything, or that *Total Plan Orders* filters payment_status); `plan-coupon`'s 7 routes beyond
  PlanCouponRefusalPersistsNothingTest; the dashboard-card component's markup; any contrast, RTL or
  four-combination capture of ANY operator screen (the Phase 2.5 matrix's 342 rows contain zero
  operator rows — its only three mentions mark them OUT OF SCOPE); and Arabic on 17 of the 19
  operator views (`grep -n superadmin tests/Unit/Merchant/ArabicUiCoverageTest.php` → exit 1).
  **Premise checks I ran green:** ArabicUiCoverageTest 3 passed/36 assertions;
  OperatorCheckCensusTest 19 passed/33 assertions; PanelListStoredXssTest 10 passed/61 assertions.

### The vendored constraint here

Three distinct constraints, measured: 1. **The largest operator surface is first-party-by-exception,
  so it IS editable.** 15 of 29 PlatformOperatorOnly routes are
  `Workdo\LynomiaChat\...\SuperAdmin\IntegrationController` under `packages/workdo/LynomiaChat/`.
  CLAUDE.md names LynomiaChat as the ONE exception to the do-not-edit rule, and
  `tests/Unit/Ci/ClaudeConfigurationTest.php:179` encodes it ("RULE 1 — packages/workdo/, WITH the
  LynomiaChat exception"). So its view (`src/resources/views/superadmin/integration.blade.php`,
  currently 0 DS classes, 0 DS components, 22 Bootstrap-ish class hits) and its own
  `src/resources/lang/ar.json` can be changed directly. This is the single highest-leverage P06-06
  target and it needs no wrapper. 2. **It is also the one module the test suite can see.**
  `app/Providers/PackageServiceProvider.php:16-32` loads `['LandingPage','LynomiaChat']` statically,
  outside `$this->app->booted()` and outside the `add_on_managers` read — explicitly so "a suite
  [can] assert that a route is properly gated when the route was never registered". So the
  structural blindness CLAUDE.md warns about does NOT apply here: its 15 operator routes are in
  `route:list` and already asserted by LynomiaConnectionTest, LynomiaCommerceCredentialUiTest,
  LynomiaSeatAllowanceTest and LynomiaMarketingAllowanceTest. 3. **Everything else on the operator
  surface is first-party.** The dashboard (HomeController, superadmin/dashboard.blade.php,
  components/dashboard-card.blade.php), the models behind the cards (Plan, PlanOrder, PlanCoupon,
  User), the analytics screen, the gate, the policy, the settings view and routes/web.php are all
  under `app/`, `routes/` or `resources/`. I found NO case where a P06-05/06 improvement is blocked
  by the vendored tree. The two vendored-adjacent items are: the WorkDo Bootstrap 5 theme the admin
  layout loads (which the DS is designed to sit on top of, not replace —
  `layouts/app.blade.php:263-269` records that two host-selector rules in public/css/custom.css
  already restyle the sidebar), and `packages/workdo/` seeders behind the addon installer.

### What the refutation corrected

- **The map said:** "Comment-stripped, boundary-correct census of 15 operator-reachable views: ...
  EVERY other operator view uses ZERO `<x-ss.*>` components." and, in the gap: "0 of 28 `<x-ss.*>`
  components on every operator view except platform/analytics, which has 6."
  **Wrong because:** The map chose a 15-view list and then stated the result as a property of "the
    operator surface". The seven settings tabs hidden behind the SAME operator Blade gate the map
    itself cites are all on the design system. Comment-stripped census of the seven view files:
    cookie_setting 3 components / 14 distinct ss- classes; cache_setting 2 / 10; storage_setting 2 /
    18; recaptcha_setting 2 / 13; chatgpt_setting 2 / 18; style_setting 0 / 13; seo_setting 0 / 14.
    Plus setting/index.blade.php itself: 1 `<x-ss.secret-field>` and 15 distinct ss- classes. So the
    operator-surface component total is at least 6 (analytics) + 11 (the seven tabs) + 1
    (setting/index) = 18, not 6, and "DESIGN-SYSTEM ADOPTION ON THE OPERATOR SURFACE IS ESSENTIALLY
    ZERO" is not supportable. Two further operator views the map's census omitted also carry real DS
    usage: store/action.blade.php has 40 ss- hits / 5 distinct (ss-btn, ss-btn--danger-quiet, ss-btn
    --ghost, ss-btn--icon, ss-btn--sm) and is the row-action cell of the very store list the Total
    Store card opens; plans/create and plans/edit have 10 hits / 3 distinct each.
  **Measured by:** resources/views/setting/storage_setting.blade.php:65-77 (ss-choice__input / ss-
    choice__label on the driver radios), resources/views/setting/cookie_setting.blade.php,
    cache_setting.blade.php, recaptcha_setting.blade.php, chatgpt_setting.blade.php,
    style_setting.blade.php, seo_setting.blade.php, resources/views/setting/index.blade.php,
    resources/views/store/action.blade.php. Command: python3 -I with a script that strips {{-- --}},
    <!-- --> and /* */ and matches `<x-ss\.([a-z0-9._-]+)` plus
    `(?<![A-Za-z0-9_-])(ss-[a-z][a-z0-9_-]*)` — /tmp/claude-0/-home-
    user/c22d1090-9e12-5711-882b-d4cba4c5ce19/scratchpad/ds.py over 18 operator views, then a second
    inline python3 -I run over the seven gated tab files.

- **The map said:** "The matrix also names a FIFTH operator gate site: `setting/index.blade.php:75`,
  a Blade `@if (auth()->user()->type == 'super admin')` that hides those settings tabs." ("those" =
  the Storage Settings and Cache Settings tabs, the only two the Phase 2.5 matrix records)
  **Wrong because:** The `@if` at :75 runs to `@endif` at :116 and hides SEVEN operator-only
    settings tabs, not two. Enumerated by reading the block: cookie_setting (:77), cache_setting
    (:83), storage_setting (:89), recaptcha_setting (:95), chat_gpt_setting (:101), style_setting
    (:107), seo_setting (:112). Every one has a matching top-level view file in
    resources/views/setting/. So five operator-only settings surfaces — Cookie, reCAPTCHA, ChatGPT,
    Style, SEO — are counted by neither the map nor the Phase 2.5 matrix's two rows. That is a scope
    item for P06-05, not a detail: it more than triples the deferred settings surface.
  **Measured by:** resources/views/setting/index.blade.php:75 (`@if (auth()->user() &&
    auth()->user()->type == 'super admin')`), :77, :83, :89, :95, :101, :107, :112 (the seven `data-
    tab=` buttons), :116 (`@endif`). Commands: `grep -n "type == 'super admin'\|@endif\|data-tab="
    resources/views/setting/index.blade.php` (only ONE super-admin hit, line 75 — the map got that
    part right), then python3 -I slicing lines 75..116 and regexing `data-tab="([a-z_]+)"`, then `ls
    resources/views/setting/`.

- **The map said:** "Of the 19 operator views I scanned, 17 are outside the glob set. So the 314
  translated strings are unpinned and can regress silently."
  **Wrong because:** At least 3 are inside, not 2, and the extra ones are the surfaces that matter
    most to the question. ArabicUiCoverageTest's SURFACES list contains
    `resources/views/order/*.blade.php` (glob #2) and `resources/views/setting/*.blade.php` (glob
    #7). order/show.blade.php is in the map's OWN operator view list — it is the bank-transfer
    receipt screen PlatformOperatorOnly's docblock names at :39 — and it is SCANNED.
    setting/index.blade.php is SCANNED. And because all seven gated tab views are top-level files in
    resources/views/setting/, glob #7 already pins BOTH Phase-2.5-deferred settings surfaces
    (Storage and Cache) plus the five the map never found. So "the 314 translated strings are
    unpinned" is wrong for a substantial part of the operator surface. The map's
    `resources/views/superadmin/` claim survives: grep for superadmin in that test returns EXIT=1.
  **Measured by:** tests/Unit/Merchant/ArabicUiCoverageTest.php:45-103 — the 16-glob SURFACES const,
    with 'resources/views/order/*.blade.php' as #2 and 'resources/views/setting/*.blade.php' as #7.
    Command: `php -r` extracting the SURFACES const with a regex, expanding every glob (162 files
    matched in total), then testing membership for 17 operator views → printed 'SCANNED: 3 NOT
    SCANNED: 14', naming platform/analytics.blade.php, order/show.blade.php and
    setting/index.blade.php as the three.

- **The map said:** "the number, the destination screen and the breadcrumb are all consistently
  merchants; only the WORD \"Store\" on the card is wrong" — and therefore "the cheap, correct fix
  may be renaming the card, not rewriting the figure."
  **Wrong because:** The number and its destination list are NOT consistent. `countCompany()` counts
    every `users` row with type='admin' and created_by = creatorId(), with no reference to the
    stores table. `StoreController::index()` INNER JOINs `stores` on stores.created_by = users.id
    and then groupBy('users.id'), so it lists only merchants holding at least one store row. A
    merchant with zero stores is counted by the card and absent from the list it opens. The card is
    therefore a superset of its destination, and renaming it to "Total Merchants" still leaves a
    figure that does not match the screen it links to. The owner decision is consequently wider than
    the map framed it: it is not "rename or re-count" but "which population, and make the list
    agree".
  **Measured by:** app/Models/User.php:432 `return User::where('type', '=',
    'admin')->where('created_by', '=', $this->creatorId())->count();` versus
    app/Http/Controllers/StoreController.php:47-50 `User::select(['users.*'])-
    >join('stores','stores.created_by','=','users.id')->where('users.created_by',
    \Auth::user()->creatorId())->where('users.type','=','admin')->groupBy('users.id')->paginate(7)`.
    Commands: `grep -n 'function countCompany' -A 10 app/Models/User.php` and `sed -n '40,58p'
    app/Http/Controllers/StoreController.php`.

- **The map said:** In the gap narrative: "The operator dashboard is still the vendored WorkDo
  `dashboard-card` (raw `{!! $icon !!}`, no number formatting, no caveat slot...)"
  **Wrong because:** It is not vendored. `find packages/workdo -name 'dashboard-card*'` returns
    nothing; the only copy on disk is resources/views/components/dashboard-card.blade.php, which is
    first-party and freely editable — the CI guard covers packages/workdo/ only. The map's own
    what_exists row for this component correctly carries first_party: true, so the gap text
    contradicts the evidence beside it. The error is the inverse of the one the task warns about: it
    would send someone to build a wrapper or a replacement component when a 20-line first-party file
    can simply be edited. (The provenance claim "a pre-DS WorkDo component" in the what_exists row
    is fine; "vendored" in the gap is not.)
  **Measured by:** `find packages/workdo -name 'dashboard-card*'` → no output, exit 0; `ls -la
    resources/views/components/dashboard-card.blade.php` → 723 bytes, present;
    tests/Unit/Ci/ClaudeConfigurationTest.php:179 shows the guard's scope is 'packages/workdo/, WITH
    the LynomiaChat exception'.

- **The map said:** "`platform/analytics.blade.php` uses 6 `<x-ss.*>` (stat x6, stats x1)"
  **Wrong because:** Internally inconsistent — 6 total cannot be stat x6 plus stats x1 — and the
    breakdown is wrong. The real distribution is stat x5 and stats x1. The total of 6 is correct, so
    the conclusion is unaffected, but a stated measurement that does not reproduce is exactly what
    this project's history says to check.
  **Measured by:** resources/views/platform/analytics.blade.php. Command: python3 -I stripping `{{--
    --}}` then `collections.Counter(re.findall(r'<x-ss\.([a-z0-9._-]+)', s))` → Counter({'stat': 5,
    'stats': 1}).

**And 6 things the map did not say at all:**

- The `manage-platform-addons` Gate is attached at TWO sites, not the one the map cites.
  routes/web.php:1155 is `Route::middleware('can:manage-platform-addons')->group(...)` covering 10
  of the 11 routes (addon resource x8, addon-apps, theme-addon, theme-enable); routes/web.php:1611
  is the single `->middleware('can:manage-platform-addons')` on install-modules. The map's `where`
  names only :1611 — i.e. 1 route of 11. The P06-04 traceability row already cites :1155, so the map
  under-cited a line its own authority had. Also worth knowing before touching it: the comment at
  routes/web.php:1150-1154 records that each AddonController method ALSO calls $this->authorize()
  itself, so the Gate is not the only control there. Command: `grep -n 'manage-platform-addons'
  routes/web.php` → two hits, then `sed -n '1150,1175p'` and `sed -n '1605,1616p'`.

- A FIFTH operator-comparison site the map's four-rule inventory never names, and it is load-bearing
  for the map's own headline finding: app/Support/MerchantDashboardAbilities.php:131-132, `return
  $user instanceof User && strtolower(trim((string) $user->type)) === 'super admin';` — an inline
  copy of PlatformOperatorOnly's rule rather than a call to UserPolicy::isOperator(). It is the same
  RULE, so the map's count of four rules stands, but it is the gate ($ssMayOrders, via holds() at
  :160) that decides whether the merchant dashboard renders the platform-wide chart. The map's trap
  says "Use `UserPolicy::isOperator()` and nothing else" without noting that this class does not.

- **One refutation of this map is deliberately NOT reproduced here.** It concerns a plan-purchase
  payment rail and is unremediated, so describing it in a public repository would publish an
  exploitable detail. It is handled under the confidential **PAY-SEC** process with the owner and is
  recorded in `docs/security/P01-SECURITY-WORK-QUEUE.md` by reference only, as `P1-SEC-91`'s closing
  section states. Nothing in Phase 06 depends on it, and nothing in Phase 06 may be built on the
  assumption that it is closed.

- config/tenancy.php's scoped_models has ELEVEN entries, and three of them matter to the authorities
  this task told me to trust. Resolved through the booted container: Menu, SupportTicket,
  SupportConversion, FlashSaleCondition, ThemeCustomizeOrder, NotifyUser, OrderRefund, OrderNote,
  Customer, Cart, **DeliveryBoy**. `App\Models\DeliveryBoy` is BOTH in scoped_models AND registers
  App\Scopes\StoreScope, so it really filters — which contradicts CLAUDE.md §1 ("`Customer` is;
  `Store`, `DeliveryBoy` and `Order` are not") and contradicts this task's own counter-intuitive-
  facts list ("`Product`, `Order`, `Store`, `Wishlist`, `User`, `DeliveryBoy` do NOT"). OrderRefund
  and OrderNote are likewise scoped and appear in no authority I was given. Outside the P06 area,
  but the parent agent is building on those tables and should know they are stale in the unsafe
  direction. Verified at config/tenancy.php:141 (the array) via /tmp/claude-0/-home-
  user/c22d1090-9e12-5711-882b-d4cba4c5ce19/scratchpad/tenancy_check.php, which boots the kernel and
  prints both scoped_models membership and getGlobalScopes() per model.

- DOC DRIFT INSIDE THE VERY ROW THE MAP WAS AUDITING, unflagged. The P06-06 row says Phase 2.5's
  "295-row matrix covers merchant surfaces" (docs/development/MASTER-PLAN-REQUIREMENT-
  TRACEABILITY.md:5023 block, Actual behaviour). The matrix is 342 rows — the map measured 342
  itself and never reconciled it against the row. A P06-06 scoping pass that quotes the row's 295
  will understate the Phase 2.5 baseline by 47 rows. Command: `grep -c '^|'
  docs/development/phase-2.5/PHASE-2.5-COVERAGE-MATRIX.md` → 342.

- A bilingual QUALITY defect on the exact card the map built a blocking question around, which a
  key-presence census cannot see: resources/lang/ar.json maps 'Total Store' → 'إجمالي المخزن'.
  المخزن is a storehouse/warehouse, not a shop — so the Arabic reinforces the wrong reading of a
  figure that actually counts merchants, and it would still be wrong after the English label is
  fixed. The map declared bilingual "NOT the gap" on the strength of 314-of-314 key presence;
  presence is not correctness. Command: python3 -I reading resources/lang/ar.json and printing the
  six operator card keys.

### Not established — stated as such rather than guessed

- WHETHER ANY OPERATOR SCREEN PASSES CONTRAST OR RTL. I measured only translation-key presence and
  class names from source. I rendered nothing, captured nothing and ran no contrast check. CLAUDE.md
  records that a verification pass found option names at 1.00:1 white-on-white in dark mode, so
  source-level DS adoption says nothing about whether these screens are legible in the four
  combinations. The Phase 2.5 matrix contains zero operator rows, so no prior capture exists to lean
  on either.

- WHETHER THE SIX DASHBOARD CARD FIGURES ARE NUMERICALLY WRONG IN PRODUCTION. I established what the
  queries DO (no payment_status predicate, merchants not stores, unbounded pluck) by reading them. I
  did not seed data and compare rendered output against an independent count, and I did not run
  anything on MySQL. SQLite type affinity and the `Cachable` array-store behaviour both hide real
  defects here, and 79 of 95 models are Cachable.

- THE PERFORMANCE COST OF `most_purchese_plan()` AND THE COUPON N+1 AT PRODUCTION SCALE. I
  established the shapes — an unbounded `pluck` into PHP, and one COUNT query per PlanCoupon row —
  but I have no row counts from any real database, so I cannot say whether this is milliseconds or a
  timeout. `docs/GATE-F-VERIFIED-SCHEMA.md` and a dump would be the place to size it.

- WHETHER THE 15 LYNOMIA OPERATOR ACTIONS ARE INTERNALLY CORRECT OR TENANT-SAFE. I established that
  all 15 are route-gated to the operator, that the controller is 884 lines with 15 actions, and that
  four Feature test files assert the gate. I did not read the action bodies. Their authorization
  beyond the route, their writes and their cross-tenant reads are unmeasured by me.

- WHICH PERMISSIONS THE SEEDED `super admin` ROLE ACTUALLY HOLDS. I established that the role is
  created and assigned (PermissionTableSeeder.php:55-66), that `Utility::addNewData()` is the only
  permission-creating mechanism and that it lists "Manage Plan", and — by EXECUTION — that the
  operator reaches /plan, /stores and plan-request in the suite. I did NOT trace the attachment of
  permissions to that role, so I cannot enumerate what else the operator can and cannot do through
  `isAbleTo()`. For any new operator screen gated on a permission string rather than on type, drive
  it with a real operator before trusting it.

- WHETHER THE FOUR-SPELLING DIVERGENCE IS LIVE ON THE PRODUCTION DATABASE. The divergence is real in
  code and real against MySQL's collation semantics (documented and measured at UserPolicy:74-93).
  Whether any row in production actually carries `'Super Admin'` or a trailing space — i.e. whether
  the HomeController:214 vs getOrderChart:826 split ever fires — I did not and cannot establish from
  this repository.

- THE FULL OPERATOR-REACHABLE SCREEN SET. My DS and Arabic censuses cover 19 views I identified from
  the 41 operator-gated routes plus the controllers carrying `type == 'super admin'`. There are ~900
  files under resources/views and I did not enumerate every controller-level operator branch
  (StoreController alone renders 10 views). The 19 are a measured sample of the operator surface,
  not a census of it. Treat my 314-string and 0-component figures as covering those 19 files only.

- WHETHER `platform/docs/*` AND THE 8 PUBLIC `platform/*` FILES MATTER TO P06-06 AT ALL. I
  established they are public (web, SetLocate, no Authenticate) and that 7 of them escape the Arabic
  glob. Whether the owner considers the public site part of Phase 06's "premium Super Admin UX" or
  finished Phase-03 work is not something I can read from any document.

- WHETHER ANY OF THIS WAS ALREADY SCOPED IN A PHASE 06 PLAN. `docs/development/phase-6/` does not
  exist (the P06-01 row records `ls` returning "No such file or directory" and git history holding
  only three PHASE-6-* paths, all under lynomia-integration/). I did not re-run that git check
  myself — I am relying on the matrix row for it, and the row is dated before the 2026-11-16 plan
  recovery.

---

## Area E — Payment-gateway configuration in the Flutter admin app

**Rows:** `P06-07` · **Buildable without an owner decision:** **NO** · **Refutation verdict:** `SOUND_WITH_CORRECTIONS` (6 refutations, 6 omissions found)

### What exists (19 items)

- **THE SECOND REPOSITORY IS CHECKED OUT IN THIS CONTAINER. `fullstackfull/StoreSyria_app98` at
  /home/user/StoreSyria_app98, branch claude/vibrant-bell-12tquy, HEAD ecb8124 "The Flutter analyzer
  gates were red on this branch, and both were ours". The task's premise that it might be absent is
  refuted.**
  `/home/user/StoreSyria_app98 (git remote origin
    https://github.com/fullstackfull/StoreSyria_app98)` — *first-party*

- **PREMISE CONFIRMED EXACTLY, INCLUDING THE LINE COUNT. The six files are the three payment-gateway
  CONTROLLERS and the three payment-gateway SCREENS. Totals: bank_transfer_controller.dart 94,
  cod_controller.dart 129, stripe_controller.dart 112, bank_transfer_screen.dart 152,
  cod_screen.dart 159, stripe_screen.dart 169. Sum = 815 — the figure in the requirement row, to the
  line. Every one of the 815 lines matches `^[[:space:]]*//`: blank=0, slash-comment=815, other=0,
  and `grep -nE '/\*|\*/'` over both directories returns nothing, so there is no block-comment form
  hiding live code. "100% commented out, 815 lines, 0 live" is measured true.**
  `/home/user/StoreSyria_app98/lib/core/controller/setting_controller/payment_setting_controller/{ba
    nk_transfer,cod,stripe}_controller.dart and /home/user/StoreSyria_app98/lib/view/screen/setting_
    screen/payment_setting_screen/{bank_transfer,cod,stripe}_screen.dart` — *first-party*

- **THEY WERE NEVER LIVE — not a regression, a vendor-template leftover. `git log --follow` on
  cod_screen.dart returns exactly two commits: 4db6622 "initial" and 81deae9 "edit order status".
  The file AS OF THE INITIAL COMMIT already had 0 non-comment, non-blank lines. So these screens
  have never executed in this repository's entire history; they arrived commented out from the
  upstream WorkDo StoreGo app template.**
  `/home/user/StoreSyria_app98 commit 4db6622c71756f8e49953ec2f6089e46c7c820db` — *VENDORED*

- **NOTHING IN THE FLUTTER APP REFERENCES THEM. Grepping lib/, test/ and tool/ for all six basenames
  and all six class names (BankTransferController, CodController, StripeController,
  BankTransferScreen, CodScreen, StripeScreen), excluding comment lines, returns ZERO hits in lib/.
  The only hits anywhere are three string keys in a test census
  (test/ui/screen_state_census_test.dart:101-103, classifying the three screen paths as
  ScreenKind.form). lib/routes/routes.dart registers no route for them; lib/bindings/ binds none of
  them.**
  `/home/user/StoreSyria_app98/test/ui/screen_state_census_test.dart:101-103 (the only references)`
    — *first-party*

- **A LIVE PAYMENT HUB EXISTS IN FLUTTER AND IS NOT ONE OF THE SIX. payment_setting_screen.dart (265
  lines) and payment_setting_controller.dart (63 lines, fully live) read the merchant's ACTIVE
  payment methods through `API.paymentTypesUrl` = '/getActivePaymentMethods'. It is read-only: a
  list of enabled methods, no editing. The hub does NOT navigate to the three dead editors —
  grepping it for cod/bank/stripe/Get.to/Navigator returns nothing.**
  `/home/user/StoreSyria_app98/lib/core/controller/setting_controller/payment_setting_controller/pay
    ment_setting_controller.dart:1-64 and
    lib/view/screen/setting_screen/payment_setting_screen/payment_setting_screen.dart` — *first-
    party*

- **THE ENDPOINTS THE REVIVED EDITORS WOULD CALL ARE NAMED, AND THEIR CONSTANTS ARE ALSO COMMENTED
  OUT. base_api.dart:76-81 holds six commented constants: getCodPaymentUrl '/get-cod-payment',
  codPaymentUrl '/cod-payment', getBankPaymentUrl '/get-bank-payment', bankPaymentUrl '/bank-
  payment', getStripePaymentUrl '/get-stripe-payment', stripePaymentUrl '/stripe-payment'. The base
  URL is live at line 3: `baseUrl = "https://storesyria.com/api/admin"`. So the full paths are
  api/admin/<x> — matching the task's note about the prefix group. payment_setting_repository.dart
  has 10 live lines (getPaymentTypesData) and 19 commented ones (the three gateway getters), so it
  is NOT one of the six 100%-dead files.**
  `/home/user/StoreSyria_app98/lib/utils/base_api.dart:3,76-81,118 and
    lib/config/repository/payment_setting_repository.dart:7-11 (live) / 13-31 (commented)` — *first-
    party*

- **ALL SIX LARAVEL ENDPOINTS ARE LIVE TODAY — the backend for P06-07 already ships. Confirmed by
  EXECUTING `php artisan route:list --json`, not by reading routes/api.php. Every one resolves to
  App\Http\Controllers\Api\DashboardController and carries exactly `api,
  App\Http\Middleware\CustomAuth, App\Http\Middleware\AdminApiLog` — no permission middleware, no
  operator middleware, no Sanctum ability check. They sit inside
  `Route::prefix('admin')->as('admin.')` opened at routes/api.php:169.**
  `/home/user/syriastore98/routes/api.php:348-355 (declarations), :169 (prefix group); handlers at
    /home/user/syriastore98/app/Http/Controllers/Api/DashboardController.php:4381, 4465, 4488, 4571,
    4594, 4682` — *first-party*

- **THE WRITE HANDLERS ARE PROPERLY GUARDED, IN THE ORDER THIS CODEBASE REQUIRES. CodPayment
  (:4384), BankPayment (:4490) and StripePayment (:4596) each call
  `resolveOwnedStore($request->store_id)` and THEN `refuseUnlessMayAdministerStoreSettings()`.
  resolveOwnedStore (:4830-4860) normalises the operator with `strtolower(trim((string)
  $user->type)) !== 'super admin'` and, for a non-operator, requires
  `UserPolicy::merchantTenantRoot($user)` to be non-null before ANDing `created_by = $root` — so it
  is an ownership predicate over server state, not a comparison of the caller's own two values,
  despite taking store_id from the request.**
  `/home/user/syriastore98/app/Http/Controllers/Api/DashboardController.php:4384-4392, 4490-4496,
    4596-4602, 4830-4860` — *first-party*

- **THE PERMISSION DECISION IS A FIRST-PARTY SHARED SERVICE AND IT IS OPERATOR-FIRST, CORRECTLY.
  StoreSettingsAccess::permits() asks three questions in order: (1) `strtolower(trim((string)
  $user->type)) === 'super admin'`; (2) `(int) $user->creatorId() === (int) $user->id` (the tenant
  owner); (3) `$user->isAbleTo('Manage Setting')`. It fails closed on a null user. Its docblock
  states why the operator must come first: "Laratrust here has no super-admin bypass
  (`permissions_as_gates` is off): `isAbleTo()` alone REFUSES the operator." It deliberately asks
  `Manage Setting` and NOT `Manage Store Setting`.**
  `/home/user/syriastore98/app/Services/StoreSettingsAccess.php:374-389 (permits), :16-35 (the
    ordering docblock), :39-42 (the two permission constants)` — *first-party*

- **MEASURED GATE ASYMMETRY ON THE READ SIDE. GetStripePayment calls the permission gate;
  GetCodPayment and GetBankPayment DO NOT. Counting `refuseUnlessMayAdministerStoreSettings`
  occurrences inside each handler's own line range: lines 4465-4487 (GetCodPayment) = 0; lines
  4571-4593 (GetBankPayment) = 0; lines 4682-4709 (GetStripePayment) = 1. All three DO call
  resolveOwnedStore. The helper's own docblock enumerates the handlers it was written for —
  "EmailSetting, GetEmailSetting, StripePayment, GetStripePayment, TestMail" — and names neither COD
  nor bank read, which is consistent with those two never having been in P1-SEC-18 F5's scope rather
  than with a decision to leave them open. `get-bank-payment` returns the row named `bank_transfer`,
  which is the merchant's bank-account detail, and StoreSettingsAccess::RESTRICTED_EXACT:74 lists
  `bank_transfer` as a name whose WRITE is restricted — so the write of that value is gated and the
  read of it is not.**
  `/home/user/syriastore98/app/Http/Controllers/Api/DashboardController.php:4465-4487, 4571-4593,
    4682-4709; helper docblock at :4762-4776; StoreSettingsAccess.php:74` — *first-party*

- **GetStripePayment RETURNS THE LIVE STRIPE SECRET KEY IN THE RESPONSE BODY, by design and test-
  pinned. :4701 sets `$stripedata['stripe_secret'] = $settings['stripe_secret']`. Three independent
  tests assert a permitted principal receives it (`assertJsonPath('data.stripe_secret',
  self::STRIPE_SECRET)` for merchant, operator and permission-holding staff) and two assert staff
  WITHOUT the permission do not. So "the app can read back the secret" is the shipped contract, not
  an accident — which is exactly what a revived Flutter editor would consume (the commented
  stripe_controller.dart:86-88 reads `response['data']['stripe_secret']` into a
  TextEditingController, i.e. it would render the secret into a mobile text field).**
  `/home/user/syriastore98/app/Http/Controllers/Api/DashboardController.php:4701; tests at /home/use
    r/syriastore98/tests/Feature/Security/AdminApiSettingsSecretsTest.php:152,161,184,193,214;
    Flutter reader at
    /home/user/StoreSyria_app98/lib/core/controller/.../stripe_controller.dart:86-88` — *first-
    party*

- **ZERO TEST COVERAGE OF THE TWO UNGATED READS. `grep -n -E 'get-cod-payment|get-bank-payment'`
  over tests/Feature/Security/AdminApiSettingsSecretsTest.php returns NOTHING, and a repo-wide grep
  for those two URIs finds them only in routes/api.php. The 38-test class covers the stripe and
  email reads and the cod/bank/stripe IMAGE writes, never the cod/bank reads.**
  `/home/user/syriastore98/tests/Feature/Security/AdminApiSettingsSecretsTest.php (38 tests; the 27
    test method names were listed)` — *first-party*

- **PREMISE CHECK EXECUTED: the routes are reachable under the Feature suite with no module loading.
  `php artisan test --filter=AdminApiSettingsSecretsTest` → "Tests: 38 passed (96 assertions),
  Duration: 5.77s". These are first-party routes in routes/api.php, not WorkDo module routes, so the
  "no module loads without an add_on_managers row" blindness does NOT apply to this area.**
  `/home/user/syriastore98/tests/Feature/Security/AdminApiSettingsSecretsTest.php` — *first-party*

- **THE PRODUCT CAPABILITY ALREADY SHIPS ON MERCHANT WEB, FIRST-PARTY, FOR 52 GATEWAYS. `POST
  payment-setting` → PaymentSettingController::update, in app/Http/Controllers/ (NOT vendored).
  Executing `getPaymentGateways()` returns 52 gateways, cod, bank_transfer and stripe among them.
  Its tenancy comes from `getCurrentStore()` (session/principal), not request input. Its
  authorization is IN-HANDLER, not middleware: route middleware is only `web, Authenticate, XSS,
  SetLocate, EmailIsVerified`, and :31-33 does `if (! StoreSettingsAccess::permits(auth()->user()))
  return redirect()->back()->with('error', __('Permission denied.'))`. So "merchants configure
  payment gateways" is NOT a new capability Phase 06 would introduce — the Flutter editors are a
  PARITY question over an existing, shipped, permission-gated surface.**
  `/home/user/syriastore98/routes/web.php:607;
    /home/user/syriastore98/app/Http/Controllers/PaymentSettingController.php:27-35 (the gate), :630
    (getPaymentGateways), :119-235 (processGateway)` — *first-party*

- **THE WEB EDITOR AND THE ADMIN API WRITE DIFFERENT SETTING ROWS FOR THE STRIPE CREDENTIALS, AND
  NEITHER WRITES THE OTHER'S. Web stripe fields (from the executed getPaymentGateways()):
  ["is_stripe_enabled","stripe_publishable_key","stripe_secret_key","stripe_unfo"], and
  processGateway writes each verbatim as the row name (`$this->post[$field] = ...`, :205-227). Admin
  API StripePayment writes: is_stripe_enabled, stripe_unfo, publishable_key, stripe_secret,
  stripe_image (:4652-4661). Counting the other's names: `grep -c "'stripe_secret'"
  PaymentSettingController.php` = 0; `stripe_secret_key` inside the API handler range 4594-4680 = 0.
  So `is_stripe_enabled` and `stripe_unfo` are SHARED between the two editors while the SECRET and
  the PUBLISHABLE KEY are not.**
  `/home/user/syriastore98/app/Http/Controllers/PaymentSettingController.php:205-227, 644-648;
    /home/user/syriastore98/app/Http/Controllers/Api/DashboardController.php:4652-4661, 4698-4703` —
    *first-party*

- **AND TWO DIFFERENT CHECKOUT PATHS READ THE TWO DIFFERENT ROWS. Row `stripe_secret` (the ADMIN API
  name) is read by Api\ApiController::payment_sheet at :8695 (`$sk_key =
  Utility::GetValueByName('stripe_secret', $store->id)`) and by the payment-list bridge at
  Utility.php:1338 and Setting.php:133, which re-expose it under the ARRAY key `stripe_secret_key`.
  Row `stripe_secret_key` (the WEB name) is read directly by Services/PaymentService.php:685 in
  processStripe (`GetValueByName('stripe_secret_key', $store->id)`). A third reader,
  StripePaymentController.php:125, uses `getSuperAdminAllSetting()`, i.e. the PLATFORM operator's
  own rows for SaaS plan billing — docs/development/phase-2/E14-GATEWAY-MATRIX-2026-10-31.md:644
  already measured that one as "THE PLATFORM OPERATOR'S CREDENTIALS, ALWAYS". So for a merchant's
  own store there are two secret rows with two writers and two readers.**
  `/home/user/syriastore98/app/Http/Controllers/Api/ApiController.php:8695 (in payment_sheet, opened
    :8512); /home/user/syriastore98/app/Services/PaymentService.php:685 (processStripe);
    /home/user/syriastore98/app/Models/Utility.php:1336-1347;
    /home/user/syriastore98/app/Models/Setting.php:133,146` — *first-party*

- **THE DEAD FLUTTER SCREENS ARE NOT BILINGUAL AND THE LIVE HUB IS. `grep -c '\.tr\b'` over the
  three dead screens returns 0, 0, 0; the live payment_setting_screen.dart has 4 `.tr` calls. The
  dead screens carry hardcoded English literals — cod_screen.dart:102 `labelText: 'Description'`,
  :108 `"Image"`, :134 `title: "Save"`. Reviving them as written would ship untranslated English
  into an app CLAUDE.md requires to be checked in Arabic (RTL) and English (LTR).**
  `/home/user/StoreSyria_app98/lib/view/screen/setting_screen/payment_setting_screen/cod_screen.dart
    :102,108,134 (and the two sibling screens)` — *first-party*

- **THE COMMENTED FLUTTER WRITERS WOULD POST CURRENCY_NAME AND CURRENCY ON EVERY GATEWAY SAVE. All
  three commented setters send `store_id`, `CURRENCY_NAME`, `CURRENCY` plus the gateway's own
  fields: cod_controller.dart:56-62 ({store_id, CURRENCY_NAME, CURRENCY, is_cod_enabled, cod_info} +
  multipart cod_image), bank_transfer_controller.dart:73-75, stripe_controller.dart:85-95 (+
  stripe_secret, publishable_key, stripe_unfo). The Laravel handlers VALIDATE CURRENCY_NAME and
  CURRENCY as `required` (DashboardController:4395-4398) and write them (:4408-4409). So a COD
  settings save is also a STORE CURRENCY write. StoreSettingsAccess::RESTRICTED_EXACT:81 lists
  currency, currency_name and currency_symbol as restricted precisely because they decide "what a
  shopper is charged", and :76-80 records that currency_symbol was missing from that list until
  2026-11-22.**
  `/home/user/StoreSyria_app98/lib/core/controller/setting_controller/payment_setting_controller/cod
    _controller.dart:43-62, bank_transfer_controller.dart:68-75, stripe_controller.dart:80-95;
    /home/user/syriastore98/app/Http/Controllers/Api/DashboardController.php:4393-4409;
    /home/user/syriastore98/app/Services/StoreSettingsAccess.php:76-81` — *first-party*

- **`Setting` IS NOT A SCOPED MODEL, so the global tenant scope adds no predicate to any of these
  reads or writes — the ownership check is the only isolation. Executed against the live config:
  scoped_models has 11 entries; Setting, Store, Product and Order are NOT in it; Customer and Cart
  are. `null_tenant => 'open'` at config/tenancy.php:470. Every one of the six handlers therefore
  depends entirely on resolveOwnedStore() for tenant isolation, and the reads use
  `Setting::where('store_id', $request->store_id)` — the RAW request value, which is safe here only
  because resolveOwnedStore already proved that id belongs to the caller's tenant.**
  `/home/user/syriastore98/config/tenancy.php:141 (scoped_models), :470 (null_tenant); the reads at
    app/Http/Controllers/Api/DashboardController.php:4474, 4580, 4694` — *first-party*

### Traps — things that read as working and do not

- THE DISPOSITION'S FRAMING IS MISLEADING AND WILL MIS-SCOPE THE OWNER QUESTION IF QUOTED AS-IS.
  "Reviving them exposes financial configuration" implies the capability would be newly exposed. It
  is already exposed: POST payment-setting → PaymentSettingController::update ships today for 52
  gateways including cod, bank_transfer and stripe, first-party, gated by the same
  StoreSettingsAccess::permits(). And four of the six API endpoints the Flutter editors would call
  are themselves live and gated. Measured by route:list execution and by a tinker call returning 52
  gateways. Put the question to the owner as PARITY (should the Flutter app reach a capability the
  web already has?), not as NEW EXPOSURE.

- TWO LIVE STRIPE SECRET ROWS, TWO WRITERS, TWO READERS — the single largest hazard here, and it is
  not registered anywhere. The web editor writes `stripe_secret_key` and `stripe_publishable_key`
  (processGateway writes each field name verbatim, PaymentSettingController.php:205-227, field list
  from an executed getPaymentGateways()). The admin API writes `stripe_secret` and `publishable_key`
  (DashboardController:4661,4660). Neither writes the other's names: `grep -c "'stripe_secret'"` in
  PaymentSettingController = 0, and `stripe_secret_key` inside DashboardController:4594-4680 = 0. On
  the read side, PaymentService.php:685 (storefront web checkout, processStripe) reads
  `stripe_secret_key`, while ApiController.php:8695 (payment_sheet, opened :8512) reads
  `stripe_secret`. Meanwhile `is_stripe_enabled` and `stripe_unfo` ARE shared between both editors.
  So a merchant who configures Stripe in only one place can end up with the gateway flagged ON and
  the secret the OTHER checkout path needs absent. A revived Flutter editor writes the API names,
  i.e. the set the web storefront checkout does not read. I did NOT establish which failure actually
  occurs in production (no production access); what is established is the name divergence, the two
  writers and the two readers, by grep over app/ and packages/.

- GET api/admin/get-cod-payment AND GET api/admin/get-bank-payment HAVE NO PERMISSION CHECK while
  their write twins and the stripe read all do. Counted inside each handler's own range: 0, 0, and 1
  for the stripe read. Both still enforce ownership via resolveOwnedStore, so this is not cross-
  tenant — it is staff-of-the-merchant without `Manage Setting` reading settings their writes are
  refused, and get-bank-payment returns the row named `bank_transfer`, the merchant's bank-account
  detail, which StoreSettingsAccess::RESTRICTED_EXACT:74 itself lists as restricted. Neither handler
  has any test. Do not assume this was decided: the helper's docblock at
  DashboardController:4763-4765 enumerates the handlers it was written for as "EmailSetting,
  GetEmailSetting, StripePayment, GetStripePayment, TestMail" and names neither, which reads as out-
  of-scope rather than as a ruling.

- A REVIVED COD OR BANK EDITOR WOULD WRITE THE STORE CURRENCY AS A SIDE EFFECT. All three commented
  Flutter setters post CURRENCY_NAME and CURRENCY alongside the gateway fields
  (cod_controller.dart:56-62, bank_transfer_controller.dart:73, stripe_controller.dart:85-95), and
  the Laravel handlers validate both as `required` (DashboardController:4395-4398) and write them
  unconditionally (:4408-4409). StoreSettingsAccess:81 lists currency/currency_name/currency_symbol
  as restricted because they decide what a shopper is charged, and :76-80 records currency_symbol
  having been missing from that list until 2026-11-22. A merchant saving COD text from their phone
  would rewrite the store currency with whatever the mobile form happened to hold.

- GetStripePayment SERVES THE LIVE SECRET KEY AND THE COMMENTED FLUTTER CODE PUTS IT IN A TEXT
  FIELD. :4701 returns `stripe_secret` in the body; three tests pin that a merchant, the operator
  and permission-holding staff all receive it. stripe_controller.dart:86-88 assigns
  `response['data']['stripe_secret']` into a TextEditingController. So reviving the stripe editor
  means a plaintext live secret key rendered on a mobile screen and held in app memory. Note the web
  side went the OTHER way: PaymentSettingController masks 58 secret fields and uses
  `SecretSetting::resolve()` to keep the stored value when nothing was typed (:220-225, comment
  F117). Reviving the Flutter editor unmasked would reverse a deliberate first-party decision.

- THE DEAD SCREENS ARE ENGLISH-ONLY. `grep -c '\.tr\b'` over the three dead screens → 0, 0, 0,
  against 4 in the live payment_setting_screen.dart. Hardcoded literals at cod_screen.dart:102
  ('Description'), :108 ("Image"), :134 ("Save"). CLAUDE.md requires every UI change checked in
  Arabic (RTL) and English (LTR); as-written these would ship untranslated.

- DO NOT TREAT THE 815 LINES AS A RECOVERABLE IMPLEMENTATION. They were already 100% commented at
  commit 4db6622 "initial" (0 non-comment lines there, measured with git show), so they are upstream
  WorkDo StoreGo scaffolding that has never run against Store Syria. They hand-roll multipart POSTs
  with their own URL building, and one carries an in-comment instruction from an earlier reviewer —
  cod_controller.dart:54 "R7-N1: do NOT hand-roll the Authorization header here" — i.e. the dead
  code was already found wanting once. Reviving by uncommenting would reintroduce patterns the live
  code has moved past (NetworkHttps + Result.fold, as payment_setting_controller.dart:26-56 uses).

- THE RAW WRITES LEAVE THE Setting ELOQUENT CACHE STALE, so a test written against these six
  handlers can read the pre-write row. The writers are `\DB::insert(... ON DUPLICATE KEY UPDATE
  ...)` (DashboardController:4453-4456 and the two equivalents), and StoreSettingsAccess::storedRows
  at :330-344 deliberately reads `DB::table('settings')` with the comment that the model "is not
  flushed by the admin API's raw `insert ... on duplicate key`". Under CACHE_STORE=array that stale
  read lives for the rest of the test — the measured false-reading pattern in CLAUDE.md.

- THE SIX ENDPOINTS DO NOT COVER THE 52 GATEWAYS. The admin API has gateway handlers for exactly
  COD, bank transfer and Stripe; the web editor covers 52, with RetiredGateways pinning some to
  'off' (PaymentSettingController:136-140). So "gateway editors in Flutter" at full web parity is
  far more than reviving six files, and the owner question should say which scope is being asked
  about.

- A BOOKKEEPING DEFECT TO CORRECT WHILE HERE: MASTER-PLAN-GAP-REGISTER.md:126 files GAP-180 (which
  points at P06-07) under phase "05 Marketing & Ana", while the traceability row at :5036 and the
  source disposition both say Phase 06. A reader filtering the gap register by phase will not see
  this item under Phase 06.

### Tenancy

Tenant isolation here does NOT come from the global scope and must not be expected to. Measured
  against the live config: `config('tenancy.scoped_models')` has 11 entries and `App\Models\Setting`
  is NOT among them (nor are Store, Product or Order; Customer and Cart are), and `null_tenant =>
  'open'` at config/tenancy.php:470 means that where no tenant resolves the scope adds no predicate
  at all. Every read in these six handlers is `Setting::where('store_id',
  $request->store_id)->pluck('value','name')` (DashboardController:4474, 4580, 4694) and every write
  is a raw `\DB::insert('insert into settings (value,name,store_id,created_by) ... ON DUPLICATE KEY
  UPDATE')` with `$request->store_id` bound as the third parameter (:4446-4455, and the bank/stripe
  equivalents) — so the predicate is built from CALLER-SUPPLIED INPUT, which this codebase correctly
  treats as not a tenant check. What makes it sound anyway is that
  `resolveOwnedStore($request->store_id)` runs FIRST on all six and refuses unless that exact id
  resolves to a Store whose `created_by` equals the caller's merchant tenant root (or the caller is
  the normalised operator). The isolation is thus an explicit ownership proof over server state, and
  the scope contributes nothing. TWO RESIDUAL IMPRECISIONS WORTH NAMING, both measured: the image
  branch of each writer uses `$store->id` (the RESOLVED store) for the upload folder and the delete
  lookup — DashboardController:4420 `upload_theme_image(..., $store->id)` and :4428
  `Setting::where($where)->where('store_id', $store->id)` with an in-code comment recording that as
  the P1-SEC-72/P1-SEC-18-F6 fix — while the surrounding settings rows are still written with
  `$request->store_id` (:4449). Those two values are equal only because resolveOwnedStore proved it,
  so the code is correct today but reads inconsistently and a future edit that drops the ownership
  call would silently become a cross-tenant write. And because `Setting` is Cachable-adjacent,
  StoreSettingsAccess::storedRows (:330-344) deliberately reads `DB::table('settings')` rather than
  the model, with its own comment saying the model "is not flushed by the admin API's raw `insert
  ... on duplicate key`" — i.e. the raw writes in these handlers leave the Eloquent Setting cache
  stale, which is the measured three-false-readings hazard from CLAUDE.md and will bite any test
  written against these six handlers.

### Authorization

MEASURED PER ENDPOINT, naming the permission strings and the operator's admission. Route middleware
  is identical and minimal on all six: `api, App\Http\Middleware\CustomAuth,
  App\Http\Middleware\AdminApiLog` (from an executed `php artisan route:list --json`). There is NO
  permission middleware, NO operator middleware and NO Sanctum ability check on any of them;
  `CheckAbilities` is imported in routes/api.php and unused. Authorization is therefore entirely in-
  handler, in two layers. LAYER 1, ownership, on all six: `resolveOwnedStore($request->store_id)`
  (DashboardController:4830-4860). It normalises the operator correctly — `strtolower(trim((string)
  $user->type)) !== 'super admin'` — and admits them unfiltered; for everyone else it requires
  `UserPolicy::merchantTenantRoot($user)` to be non-null and ANDs `created_by = $root`, which is the
  post-P1-SEC-66 form that refuses a demoted merchant rooted at the platform. Staff reach their
  employer's stores because creatorId() resolves them to it. LAYER 2, permission, on only FOUR of
  six: `refuseUnlessMayAdministerStoreSettings()` → `StoreSettingsAccess::permits()`, which asks, IN
  THIS ORDER, (1) `strtolower(trim((string) $user->type)) === 'super admin'`, (2) `(int)
  $user->creatorId() === (int) $user->id` (tenant owner, admitted whatever their role rows say), (3)
  `$user->isAbleTo('Manage Setting')` — deliberately NOT `Manage Store Setting`, because
  StoreSettingsAccess.php:30-32 records that a role holding only the latter must not be handed the
  SMTP password. Null user fails closed. THE OPERATOR IS ADMITTED, by limb 1, and the docblock at
  :18-23 states why that limb must come first: "Laratrust here has no super-admin bypass
  (`permissions_as_gates` is off): `isAbleTo()` alone REFUSES the operator." Refusal shape is
  `$this->error([...], 'fail', 403)` — 403 and never 401, because the shipped Flutter app forces a
  logout on any 401 (network.dart:285-288). WHERE LAYER 2 IS ABSENT: POST cod-payment HAS it
  (:4389), GET get-cod-payment does NOT (0 occurrences in 4465-4487); POST bank-payment HAS it
  (:4495), GET get-bank-payment does NOT (0 in 4571-4593); POST stripe-payment HAS it (:4601) and
  GET get-stripe-payment HAS it (1 in 4682-4709). So a staff member of the merchant who holds no
  `Manage Setting` is refused every write and the stripe read, but is admitted by ownership alone to
  the COD and bank reads — and the bank read returns the row named `bank_transfer`, the merchant's
  bank-account detail, which StoreSettingsAccess::RESTRICTED_EXACT:74 lists as restricted for
  writes. The web twin uses the same single decision point (PaymentSettingController.php:31-33), so
  the two doors do not drift; the gap is between the API's own six handlers, not between web and
  API.

### What the suite already covers, and what it cannot reach

PARTIAL, and the gap is exactly where the authorization gap is. The one relevant class is
  /home/user/syriastore98/tests/Feature/Security/AdminApiSettingsSecretsTest.php — 27 test methods,
  38 cases with data providers, EXECUTED: "Tests: 38 passed (96 assertions), Duration: 5.77s". It
  covers: a permitted principal reads the secrets through the real routes; the operator admitted
  without the permission; the operator recognised under a non-canonical `type` spelling; the tenant
  owner with no role rows; staff WITH the permission read and pass the write gate; staff WITHOUT it
  refused the SMTP password, the stripe secret, the stripe write, the SMTP write and TestMail; the
  refusal is 403 and never 401; a foreign merchant refused for the ownership reason; a customer
  token and a driver token get neither the secret nor a 401; `Manage Store Setting` does not open
  the SMTP password; a staff row whose `type` only looks like admin is not an owner (3 data sets). A
  second class, tests/Feature/Security/ThemeImageUploadTypeTest.php:651-653, drives cod-payment,
  bank-payment and stripe-payment for image-type enforcement. WHAT IS NOT COVERED, measured: `grep
  -n -E 'get-cod-payment|get-bank-payment'` over AdminApiSettingsSecretsTest.php returns NOTHING,
  and a repo-wide `grep -rn --include=*.php` for those two URIs finds them ONLY at
  routes/api.php:349 and :352. So the two handlers that lack the permission gate are also the two
  with zero test coverage — no test asserts either that they are open or that they are closed, in
  either direction. Also uncovered: nothing anywhere asserts anything about the `stripe_secret` vs
  `stripe_secret_key` row-name divergence, or that the two checkout readers agree. On the Flutter
  side the only coverage is test/ui/screen_state_census_test.dart:101-103, which classifies the
  three dead screen FILES as ScreenKind.form by path string — a census over file paths, not over
  behaviour, and one that would keep passing whether the files were revived or deleted.

### The vendored constraint here

NOTHING IN THIS AREA IS VENDORED, so the usual packages/workdo/ prohibition does not bind here —
  which is the unusual and useful finding. Measured: the six Flutter files live under
  /home/user/StoreSyria_app98/lib/, a separate first-party repository with no packages/workdo/ at
  all (`find lib -type d` lists 48 first-party directories). On the Laravel side every component is
  first-party: routes/api.php:348-355, app/Http/Controllers/Api/DashboardController.php,
  app/Http/Controllers/PaymentSettingController.php (44,826 bytes, app/Http/Controllers/, not
  packages/), app/Services/StoreSettingsAccess.php, app/Models/Utility.php, app/Models/Setting.php,
  app/Services/PaymentService.php. A repo-wide `grep -rn --include=*.php` for the six endpoint names
  returned hits ONLY in routes/api.php and tests/ — zero under packages/workdo/. TWO CONSEQUENCES.
  First, Phase 06 may edit all of it directly; no wrapping, view override or container re-binding is
  needed. Second, the "no WorkDo module loads in any test run without an add_on_managers row"
  blindness does NOT apply: the premise check `php artisan test
  --filter=AdminApiSettingsSecretsTest` reached these real routes and returned 38 passed / 96
  assertions, so this surface is genuinely suite-testable. The one vendored-adjacent constraint is
  the TEMPLATE PROVENANCE, not the licence: the dead screens came in at commit 4db6622 "initial"
  already commented, i.e. they are upstream WorkDo StoreGo app scaffolding, so their field names,
  their `CURRENCY_NAME`/`CURRENCY` payload shape and their hand-rolled multipart calls encode the
  vendor's assumptions and must not be revived as-written on the assumption that they ever matched
  this platform.

### What the refutation corrected

- **The map said:** "NOTHING IN THIS AREA IS VENDORED, so the usual packages/workdo/ prohibition
  does not bind here -- which is the unusual and useful finding. ... A repo-wide `grep -rn
  --include=*.php` for the six endpoint names returned hits ONLY in routes/api.php and tests/ --
  zero under packages/workdo/. TWO CONSEQUENCES. First, Phase 06 may edit all of it directly"
  **Wrong because:** A VENDORED artefact in this exact area exists and the map's own method hid it:
    it restricted the sweep to `--include=*.php`, and the file is JSON.
    packages/workdo/Api/src/documentation/admin.json is the WorkDo Api module's admin-API
    documentation and it documents ALL SIX endpoints by name -- "cod-payment" :2828, "get-cod-
    payment" :2862, "bank-payment" :2896, "get-bank-payment" :2930, "stripe-payment" :2964, "get-
    stripe-payment" :3000 -- and specifies the very two things P06-07 would have to change: the
    `stripe_secret` field (:2979, :3021) and the `CURRENCY_NAME` payload (:2837, :2878, :2905,
    :2946, :2973, :3016). ci.yml:199 is a required job named "Guard - packages/workdo/ is
    unmodified" backed by .github/scripts/guard-no-workdo-changes.sh. So the conclusion "Phase 06
    may edit all of it directly" is wrong for one component: the only artefact that documents this
    contract to a client developer cannot be edited. If Phase 06 stops serving `stripe_secret` or
    drops CURRENCY from the payload, the vendored documentation goes stale and must be superseded in
    first-party docs, not corrected in place. This is the precise failure mode the brief warned
    about, inverted: a map declaring an area wholly un-vendored sends someone to assume a freedom
    they do not have.
  **Measured by:** packages/workdo/Api/src/documentation/admin.json:2828,2862,2896,2930,2964,3000
    (field refs :2979,:3021,:2837); .github/workflows/ci.yml:199; .github/scripts/guard-no-workdo-
    changes.sh. Commands: `grep -n -E '"(get-)?(cod|bank|stripe)-payment"'
    packages/workdo/Api/src/documentation/admin.json`; `grep -n -E 'stripe_secret|CURRENCY_NAME'
    packages/workdo/Api/src/documentation/admin.json`; `grep -rn 'packages/workdo'
    .github/workflows/*.yml`; `grep -rln workdo .github/workflows/ .github/scripts/`.

- **The map said:** "the commented stripe_controller.dart:86-88 reads
  `response['data']['stripe_secret']` into a TextEditingController, i.e. it would render the secret
  into a mobile text field" -- asserted twice (what_exists and traps)
  **Wrong because:** Lines 86-88 of stripe_controller.dart are the WRITE payload, not a read: `//
    'store_id': Prefs.getStoreID(),` / `// 'CURRENCY_NAME': currencyName,` / `// 'CURRENCY':
    currency,`. The actual read of the secret into a TextEditingController is at :51 -- `//
    secretController.text = response['data']['stripe_secret'];` -- inside getStripePaymentData()
    (opened :45), with the controller declared at :22. The SUBSTANCE of the finding is confirmed;
    only the citation is false. It matters because the map's own evidence list says it ran `sed -n
    '40,80p'` on this file, which would have shown :51 -- so the right evidence was in hand and the
    wrong line was written down, and :86-88 is where the CURRENCY side-effect payload lives, a
    different finding entirely.
  **Measured by:** /home/user/StoreSyria_app98/lib/core/controller/setting_controller/payment_settin
    g_controller/stripe_controller.dart:51 (read), :22 (controller), :86-88 (what is actually
    there). Commands: `grep -n -E "stripe_secret|data\[|secretController" stripe_controller.dart`;
    `sed -n '86,88p' stripe_controller.dart`; `sed -n '20,45p' stripe_controller.dart`.

- **The map said:** "A refusal is HTTP 403 and never 401, because the shipped Flutter app forces a
  logout on any 401 (network.dart:285-288)" -- stated in the map's `authorization` field as its own
  measurement
  **Wrong because:** lib/network/network.dart:285-288 says nothing about 401 or logout. It is an
    R7-N4 comment about blank error toasts: "R7-N4. The parser above accepts any non-null `message`,
    and an empty string is not null -- so a server answering {\"message\": \"\"} ... overwrote the
    generic wording with nothing and the merchant got a completely BLANK error toast". The file is
    1398 lines; the real 401 handling is `case 401:` at :324 under a `// 401` banner at :321, and
    :275 states "the status-code switch below still has to run, because that is what logs the
    merchant out on 401". So the substance is TRUE at different lines. The map inherited the stale
    citation from the repository's own docblock at DashboardController.php:4772
    ("network.dart:285-288") and presented it as a measurement without opening the file -- the exact
    pattern this project's CLAUDE.md warns about, where prose about a thing is mistaken for the
    thing.
  **Measured by:** /home/user/StoreSyria_app98/lib/network/network.dart:285-288 (what is actually
    there), :321-324 and :275 (the real behaviour); source of the stale citation at
    /home/user/syriastore98/app/Http/Controllers/Api/DashboardController.php:4772. Commands: `sed -n
    '285,288p' lib/network/network.dart`; `grep -rn -E '_handleUnauthorized|401'
    lib/network/network.dart`; `wc -l lib/network/network.dart`; `sed -n '273,277p'
    lib/network/network.dart`.

- **The map said:** The test inventory: "The one relevant class is
  tests/Feature/Security/AdminApiSettingsSecretsTest.php ... A second class,
  tests/Feature/Security/ThemeImageUploadTypeTest.php:651-653, drives cod-payment, bank-payment and
  stripe-payment for image-type enforcement."
  **Wrong because:** There is a THIRD class the map never found, and it is the one most directly on
    point for the owner question about staff permissions:
    tests/Feature/Security/SettingsRestrictedWriteTest.php, 47 test methods, drives `/api/admin/cod-
    payment` at :972 and `/api/admin/bank-payment` at :973 -- the latter with `'bank_transfer' =>
    'ATTACKER-ACCOUNT'` -- through cases named
    test_a_settings_screen_writer_refuses_a_user_without_manage_setting_outright (:254),
    test_staff_holding_only_manage_store_setting_cannot_write_a_restricted_name_by_name (:295) and
    others. The map searched by class name rather than by URI across tests/, so it missed it. Its
    NARROW claim survives intact (the two GET reads still have zero coverage anywhere, which I re-
    verified repo-wide), but "the one relevant class" understates existing protection on the write
    side and would send a Phase 06 implementer to write tests that already exist.
  **Measured by:** tests/Feature/Security/SettingsRestrictedWriteTest.php:972-973 (the two URIs),
    :254, :295 (test names), 47 test methods. Commands: `grep -rln --include=*.php -E 'cod-
    payment|bank-payment|stripe-payment' tests/` (returned THREE files); `grep -n -E 'cod-
    payment|bank-payment|api/admin' tests/Feature/Security/SettingsRestrictedWriteTest.php`; `grep
    -c 'public function test' tests/Feature/Security/SettingsRestrictedWriteTest.php`.

- **The map said:** "the image branch of each writer uses `$store->id` ... and :4428
  `Setting::where($where)->where('store_id', $store->id)`"; and elsewhere the raw write is cited as
  ":4446-4455" and ":4453-4456"
  **Wrong because:** Off-by-one and two mutually inconsistent ranges for the same statement.
    `Setting::where($where)->where('store_id', $store->id)->first()` is at :4429, not :4428 (:4428
    is the last line of the preceding P1-SEC-18-F6 comment block). The `\DB::insert(` call is at
    :4454 with the SQL string on :4455 and `$request->store_id` bound at :4450 -- so neither of the
    map's two ranges (:4446-4455, :4453-4456) names it consistently. Everything else on this write
    path is exact (:4384 ownership, :4389 gate, :4396-4397 rules, :4408-4409 currency writes, :4420
    upload_theme_image), so this is a precision defect in a map that is otherwise line-accurate, and
    in a repository where a comment line being mistaken for a code line has previously produced a
    false reading.
  **Measured by:** app/Http/Controllers/Api/DashboardController.php:4429, :4450, :4454-4455.
    Commands: `grep -n "Setting::where(\$where)" app/Http/Controllers/Api/DashboardController.php |
    awk -F: '$1>4410 && $1<4440'` -> 4429; `grep -n 'DB::insert' ... | awk -F: '$1>4440 && $1<4470'`
    -> 4454; plus an offset-corrected awk/grep pass over NR 4381-4460.

- **The map said:** "payment_setting_controller.dart:63 lines, fully live"; and "`find lib -type d`
  lists 48 first-party directories"
  **Wrong because:** Two small numeric overstatements in a map that elsewhere insists on exact
    censuses. payment_setting_controller.dart is 63 lines TOTAL but only 49 are non-comment, non-
    blank -- it is live code, but not 63/63, and the map applied its own strict census method to the
    six dead files while asserting 'fully live' loosely here. And `find lib -type d | wc -l` returns
    50 (49 excluding lib itself), not 48. Neither changes a conclusion; both are reported because
    this map's value rests on its arithmetic being checkable.
  **Measured by:** /home/user/StoreSyria_app98/lib/core/controller/setting_controller/payment_settin
    g_controller/payment_setting_controller.dart (63 total / 49 live). Commands: `wc -l` plus `grep
    -vcE '^[[:space:]]*(//|$)'` on that file -> 63 and 49; `find lib -type d | wc -l` -> 50.

**And 6 things the map did not say at all:**

- packages/workdo/Api/src/documentation/admin.json:2828,2862,2896,2930,2964,3000 - the VENDORED
  WorkDo Api module documents all six P06-07 endpoints, including the `stripe_secret` field (:2979,
  :3021) and the CURRENCY_NAME payload (:2837). Un-editable under .github/workflows/ci.yml:199. This
  refutes the map's 'nothing in this area is vendored'. Found by dropping the map's
  `--include=*.php` filter.

- tests/Feature/Security/SettingsRestrictedWriteTest.php:972-973 - a third test class (47 methods)
  drives /api/admin/cod-payment and /api/admin/bank-payment with restricted names, including
  'bank_transfer' => 'ATTACKER-ACCOUNT'. Found by searching tests/ by URI instead of by class name.

- app/Http/Controllers/Api/ApiController.php:2368 and :2381 - a THIRD bridge between the two Stripe
  row names: payment_list reads row `stripe_secret` and re-exposes it under the array key
  `stripe_secret_key`. The map named only Utility.php:1338/1346 and Setting.php:133/146. It IS
  redacted at ApiController.php:3362 via GatewaySecretRedactor::redactAll and pinned by
  tests/Feature/Security/PaymentListSecretDisclosureTest:98, so it is not a defect - but it is a
  fourth writer-to-reader path a reconciliation would have to account for.

- app/Services/PaymentService.php:685 is not the only reader of row `stripe_secret_key`. The full
  `grep -rn stripe_secret_key --include=*.php app/ packages/` hit list adds
  packages/workdo/Donation/src/app/Services/DonationPaymentService.php:131 and
  packages/workdo/LotteryPrizes/src/app/Services/LotteryPaymentService.php:132 (both
  `GetValueByName('stripe_secret_key', $store->id)`, both VENDORED and un-editable), plus array-key
  readers at app/Http/Controllers/StripePaymentController.php:125 and
  packages/workdo/Campaigns/src/app/Services/CampaignsPaymentService.php:212. The map said 'two
  writers and two readers'; it is two writers and at least five readers, three of them vendored.
  This makes the canonical-name question strictly harder than the map presents it: converging on
  `stripe_secret` would silently break three vendored payment paths that cannot be edited.

- app/Http/Controllers/SettingController.php:1951-1935 - THIS FILLS THE MAP'S OWN 'NOT ESTABLISHED'
  #4, and in the direction that matters. settingForm renders `setting.payment_setting` ('the payment
  tab with every gateway key in it', its own comment at :1931) and IS gated: `if (!
  StoreSettingsAccess::permits($user)) return response()->json([... 'Permission denied.'], 403)` at
  :1935. Route at routes/web.php:626. So the web READ door for cod/bank/stripe values is gated while
  GET api/admin/get-cod-payment and get-bank-payment are not: the API gate gap is a genuine web/API
  asymmetry, NOT a mirror of an already-open web door. This strengthens the map's trap #3 and should
  be stated to the owner that way.

- config/tenancy.php - the map asserted Setting 'IS NOT a scoped model' but never answered the
  second of the two questions the brief insists on separating. Executed: App\Models\Setting
  registers NO global scope at all (getGlobalScopes() = []), so it is unscoped on both counts.
  Conservative in the map's favour, but unmeasured in the map.

### Not established — stated as such rather than guessed

- WHETHER THE OWNER EVER APPROVED THE DEFERRAL OF THIS ITEM TO PHASE 06. I did not independently
  establish it and I did not assume it. The traceability row at :5041 states "I found NO evidence
  the owner approved this specific deferral to Phase 06, so it must not be treated as an owner-
  approved boundary." What I CAN add from reading the source: the disposition table's own header
  says it was "Settled by the owner's final-acceptance directive of 2026-11-11" and each row is
  "closed as a decision" — so the SCHEDULING (that Phase 2.5 owes nothing further) appears owner-
  ratified, while the table itself records the thing still owed as "a product decision in Phase 06",
  i.e. the product decision is explicitly NOT made. I did not read the 2026-11-11 directive; `find
  docs -iname '*directive*'` was not run for it.

- WHICH STRIPE SECRET ROW PRODUCTION MERCHANTS ACTUALLY HOLD, and therefore whether the
  stripe_secret / stripe_secret_key divergence is causing live checkout failures today. That needs a
  production or restored-dump query over `settings` and I have no production access and ran no such
  query. What IS established is the divergence itself: two names, two writers that each never write
  the other's, and two readers on two different checkout paths, all by grep over app/ and packages/
  with the hit lists read.

- WHETHER THE MISSING PERMISSION GATE ON get-cod-payment AND get-bank-payment IS A DECISION OR AN
  OVERSIGHT. No document anywhere states an intent for those two. The only evidence is indirect and
  I report it as such: the helper's docblock (DashboardController:4763-4765) enumerates its intended
  handlers as "EmailSetting, GetEmailSetting, StripePayment, GetStripePayment, TestMail" and names
  neither COD nor bank read, which is consistent with out-of-scope rather than with a ruling that
  they should be open. I did not search the git history of DashboardController for when the gate was
  added.

- WHETHER A WEB SURFACE RENDERS THE SAME COD/BANK/STRIPE VALUES WITHOUT A PERMISSION CHECK, which
  would make the two ungated API reads equivalent to an already-open web door rather than a new one.
  I measured the WRITE door (PaymentSettingController::update, gated) but did not inventory the
  settings-screen READ handlers (SettingController::settingForm and siblings are named in
  StoreSettingsAccess's docblock as using the same gate, but I did not open them).

- WHETHER ANY OTHER CLIENT CALLS THE SIX ENDPOINTS. I established that nothing in
  StoreSyria_app98/lib references the three dead screens or their endpoint constants, and that the
  constants themselves are commented out. I did NOT survey Lynomia Chat or any third client, so "no
  client calls these six endpoints" is established for the Flutter admin app only.

- THE DART ANALYZER / BUILD CONSEQUENCES OF REVIVING THE FILES. I did not run `flutter analyze` or
  `dart analyze` (no attempt made). The dead code imports image_picker, http, loading_widget and
  common_snak_bar_widget; whether those APIs still match current call signatures after the Phase 2.5
  Flutter work is unmeasured, and HEAD's own commit message ("The Flutter analyzer gates were red on
  this branch, and both were ours") suggests the analyzer is load-bearing on this branch.

- WHETHER PHASE 2.5's MATRIX STILL COUNTS THIS ROW AS BLOCKED. The disposition text says all ten
  remain BLOCKED in PHASE-2.5-COVERAGE-MATRIX.md and that the matrix stays at 225/24/28/8/10/0, but
  I read that claim in the dispositions document and did not open the matrix to verify the count.

---

## Area F — Scope, and the requirements that were never tracked

**Rows:** `P06-08` · **Buildable without an owner decision:** **NO** · **Refutation verdict:** `REFUTED` (4 refutations, 8 omissions found)

### What exists (14 items)

- **P06-08's premise is CONFIRMED by reading both sides. The Lynomia PHASE-6 is a DIFFERENT PROJECT:
  'Store Syria × Lynomia — Integration Phase 6', scope 'Integration Administration, Partner
  Operations & Platform Communication Operations', spanning two repositories. The document disclaims
  the collision itself in its own text: 'This is NOT the Store Syria platform roadmap's Phase 6.'**
  `docs/development/lynomia-integration/PHASE-6-SCOPE-PROPOSAL.md:1-12` — *first-party*

- **The Lynomia Phase 6 is on a DIFFERENT BRANCH and its code is absent from this checkout — which
  is what makes the two safely separable at HEAD. Its completion report names branch `claude/store-
  syria-lynomia-integration-1oa1tt`, Store Syria HEAD `a24c974f`, status 'CONDITIONAL GO for
  staging. NOT production-ready. A1 remains OPEN.' The `SuperAdmin/IntegrationController` it
  describes does not exist here.**
  `docs/development/lynomia-integration/PHASE-6-COMPLETION-REPORT.md:1-14` — *first-party*

- **Exactly THREE PHASE-6-* files exist, all under the Lynomia directory, and
  `docs/development/phase-6/` does not exist at all — so there is no competing first-party Phase 06
  directory to confuse them with.**
  `docs/development/lynomia-integration/PHASE-6-{SCOPE-PROPOSAL,COMPLETION-MATRIX,COMPLETION-
    REPORT}.md` — *first-party*

- **The THIRD numbering is real and is a separate document still in the repository: the older ten-
  phase technical roadmap's 'Phase 6' is Arabic/localisation, not SaaS and not integration. Its §9
  text demotes 'Localisation' from Phase 1 to Phase 6 and refers to 'Phase 6 (Arabic)', 'Phase 6.3',
  'Phase 6.7', 'Phase 6.8'.**
  `docs/discovery/09-DEVELOPMENT-ROADMAP.md:74, :161, :1342, :1456, :1524, :1598` — *first-party*

- **KYC / ADM-002 Store 360 / MARKET-001..005 ARE present in the recovered owner-approved plan, with
  verbatim Arabic text — so the owner's scope words in the task are grounded, not a paraphrase.
  Phase 06 there has eight sections 06-A..06-H: ADM-001/ADM-002 (06-A, Super Admin and Store 360°),
  SAAS-001..007, KYC-001..003, OPS-001..003, MARKET-001..005, SUPPORT-001..005, IAM-001..004,
  CTRL-001..004.**
  `docs/development/master-plan/MASTER-PRODUCT-PLAN-v1.0-BASE.md:373-460 (and the WITH-AMENDMENTS
    twin at :387-474)` — *first-party*

- **KYC and MARKET are tracked as GAP ids with phase 06: `GAP-194` covers `KYC-001 … KYC-003` ('Not
  located. No KYC or identity-verification surface'), `GAP-196` covers `MARKET-001 … MARKET-005`
  ('Not located. No app/theme marketplace. MarketPlaceList is a different thing'). Five more Phase
  06 GAP rows exist: GAP-192 (SAAS-007 MRR/ARR half), GAP-195 (OPS-002), GAP-197 (SUPPORT-004),
  GAP-198 (IAM-003), GAP-199 (CTRL-001..004).**
  `docs/development/MASTER-PLAN-GAP-REGISTER.md:306, :308, :309, :310, :311, :312, :313` — *first-
    party*

- **GAP-194's 'no KYC' finding SURVIVES my independent refutation attempt. A first pass looked
  alarming — 'kyc' matched 83 files under packages/ and 'IdentityVerification' matched 4 under app/
  — but both are false positives. All 83 package matches are binary assets; zero are PHP or Blade.
  The app/ matches are `SocialIdentityVerificationException`, i.e. social-login
  (Google/Facebook/LinkedIn) token verification, not identity documents.**
  `app/Services/SocialIdentity/JwksProvider.php:36-97 and
    app/Services/SocialIdentity/SocialIdentityVerifier.php:81-161` — *first-party*

- **GAP-196's characterisation of `MarketPlaceList` is ACCURATE. The module is one controller (135
  lines), one model `StoreBusinessHour` on table `store_business_hour`, one migration
  `create_store_business_hour_table`, and a storefront store-listing page. It is business hours plus
  a store directory — not an app/theme marketplace.**
  `packages/workdo/MarketPlaceList/src/app/Models/StoreBusinessHour.php:18-20;
    .../src/routes/web.php:19-33;
    .../src/database/migrations/2024_05_03_114329_create_store_business_hour_table.php` — *VENDORED*

- **An ADD-ON CATALOGUE surface does exist and is first-party, which qualifies GAP-196's flat 'Not
  located'. `resources/views/marketplace/` holds five Blade views (landing 203KB, pricing 99KB,
  marketplace 19KB, software 7.8KB, detail_not_found 2.4KB), rendered by HomeController over
  `Module::getByStatus(1)` with a name search. Public routes `add-on` and `add-on/details/{slug}`;
  operator-only install/theme writes behind `can:manage-platform-addons`.**
  `resources/views/marketplace/ (5 files); app/Http/Controllers/HomeController.php:1591-1660;
    routes/web.php:403, :406, :1155-1159` — *first-party*

- **GAP-197 IS REFUTED BY EXECUTION — an impersonation / login-as door EXISTS. GAP-197 states 'Not
  located, and its absence is currently a security property. No impersonation or login-as door
  exists in app/Http/Controllers/ or routes/web.php.' Both halves are wrong.
  `StoreController::LoginWithAdmin` calls `Impersonate::take($request->user(), $user)` after
  `$this->authorize('impersonate', $user)`; `ExitAdmin` leaves it;
  `Lab404\Impersonate\Models\Impersonate` is a trait on the User model with
  `canImpersonate()`/`canBeImpersonated()`.**
  `app/Http/Controllers/StoreController.php:1085-1118 (take at :1093); routes/web.php:1131
    (login.with.admin) and :1132 (exit.admin); app/Models/User.php:14, :25, :492, :501` — *first-
    party*

- **The impersonation door is gated operator-only and is NOT a cross-tenant hole, which is the part
  GAP-197 gets right in spirit: `UserPolicy::impersonate` requires `isPlatformAdmin($actor)`,
  refuses self-impersonation, and refuses impersonating another operator (normalised, because a
  `'Super Admin'` row was previously impersonable).**
  `app/Policies/UserPolicy.php:385-402` — *first-party*

- **What SUPPORT-004 actually still lacks is MEASURED, and this project already wrote it down
  against itself. SUPPORT-004 asks for support access that is temporary, scoped, consented-to and
  audit-logged. The existing door is none of those four: `Impersonate::take()` makes
  `auth()->user()` BE the merchant (so access is total, not scoped), and NO audit row is written.**
  `app/Services/OperatorCatalogueWrite.php:40-57` — *first-party*

- **NO Store 360 code exists, and the stub that would host it is empty. `StoreController::show` —
  the handler behind `GET stores/{store}` (route name stores.show) — has an empty body (`//`), so it
  returns null. The operator dashboard is 252 lines with exactly one store link, to the flat list
  `stores.index`; there is no per-store drill-down.**
  `app/Http/Controllers/StoreController.php (show(), body is `//`);
    resources/views/superadmin/dashboard.blade.php:30` — *first-party*

- **ADM-001 (platform command dashboard) is PARTLY built and already re-traced DOWN by this project:
  the operator's dashboard mirrors only 3 of the merchant's 12 figure groups.**
  `docs/development/PROJECT-STATE.md:48 (and :242 for the phase row)` — *first-party*

### Traps — things that read as working and do not

- THE NUMBERING TRAP IS LIVE AND HAS THREE SIDES, NOT TWO. A Lynomia 'Phase 6 Completion Report'
  reads exactly like Phase 06 delivery and is not: different project, two repositories, different
  branch (`claude/store-syria-lynomia-integration-1oa1tt`, Store Syria HEAD `a24c974f`), status
  'CONDITIONAL GO for staging. NOT production-ready. A1 remains OPEN.' The third is
  docs/discovery/09-DEVELOPMENT-ROADMAP.md §9's Phase 6 = Arabic/Localisation, from the ten-phase
  roadmap the owner's master prompt forbids treating as overriding the six-phase plan. Measured:
  `find docs -iname 'PHASE-6*'` returns three files, ALL Lynomia; `docs/development/phase-6/` does
  not exist. Anyone citing 'Phase 6 is done' must say WHICH.

- DO NOT BUILD SUPPORT-004 AS IF NO DOOR EXISTED. GAP-197 says 'No impersonation or login-as door
  exists in app/Http/Controllers/ or routes/web.php' and that its absence 'is currently a security
  property'. Both are FALSE: routes/web.php:1131 -> StoreController::LoginWithAdmin:1085 calls
  `Impersonate::take()`, and 10/10 tests pass including 'platform admin can still impersonate a
  merchant'. Building to that register row would add a second cross-tenant door beside an existing
  unaudited one. The honest framing: the door is open, operator-gated, and writes NO audit row
  (`AuditLog::record` appears once in StoreController.php, at :958, for password reset). What
  SUPPORT-004 needs is consent, scoping, a time limit and an audit trail AROUND the existing door.

- A CENSUS BY STRING NEARLY COST ME TWO WRONG ANSWERS IN THIS VERY TASK — the GAP-229 failure mode,
  twice in one pass. (i) `grep -rl kyc packages/` matched 83 files and looked like a refutation of
  'no KYC'; every one is a binary asset (57 png, 16 svg, 4 js, 4 jpg, 1 gif, 1 css), the literal
  'kyc' sitting inside base64 image data, and the grep dumped 14.5MB. (ii) `IdentityVerification`
  matched 4 app/ files; all are `SocialIdentityVerificationException` — social-login token
  verification, not identity documents. The correct measurement is `grep -rniw kyc --include='*.php'
  --include='*.blade.php'` = 0. Restrict to source extensions and use word boundaries, or a scope
  word will appear to be implemented.

- A SCOPE WORD ABSENT FROM THE DERIVED SUMMARY COULD NEVER BECOME A ROW, SO DO NOT READ THE EIGHT
  P06 ROWS AS THE PHASE'S SCOPE. P06-02..P06-06 were derived from CLAUDE.md §4 and PROJECT-
  STATE.md:242, and neither contains 'Store 360', 'KYC' or 'marketplace'. P06-01 says so outright:
  'Everything below this row is DERIVED from summaries, not from an owner requirements document.'
  The recovered plan has eight Phase 06 sections (06-A..06-H); the matrix has eight rows that do not
  map to them. Plan from docs/development/master-plan/, not from the P06 rows.

- THE PROJECT'S OWN UNTRACKED CENSUS IS INCOMPLETE, SO 'it would have been in GAP-182..199' IS NOT A
  VALID INFERENCE. DECISION-ID-REGISTER.md §4.3 lists 15 untracked + 3 half-tracked = 18, with 7 for
  Phase 06, and ADM-001/ADM-002 are in neither list nor in any GAP row. An audit written
  specifically to find what the matrix missed, missed this. Treat Store 360 as a nineteenth, and re-
  derive from the plan's section list rather than trusting either census.

- GAP-196'S FLAT 'Not located. No app/theme marketplace' WILL CAUSE DUPLICATE WORK. A first-party
  add-on catalogue exists: `resources/views/marketplace/` (5 views, landing 203KB / pricing 99KB),
  HomeController.php:1591-1660 over `Module::getByStatus(1)` with name search, public routes at :403
  and :406, and operator-gated install/theme-enable at :1155-1159. MARKET-001's browse half is
  partly built; MARKET-002..005 (security review, versioning, developer revenue, untrusted-code
  sandboxing, suspend-without-data-loss) are the genuinely absent part. Note EX-010 forbids running
  untrusted Marketplace code in production, and DECISION-ID-REGISTER.md:463 marks it as holding only
  'vacuously -- Phase 06 has not started'.

- `StoreController::show` IS AN EMPTY STUB (`//`) BEHIND A REGISTERED ROUTE. `GET stores/{store}`
  resolves to a handler that returns null. It is where Store 360 belongs, but the adjacent source
  records that `edit()` takes a USER id while `update()` takes a STORE id on the same resource
  route, so `PUT /stores/{userId}` can write to whichever store carries that number. Do not inherit
  that id convention into a new `show()`.

- TWO `Route::resource('stores', ...)` DECLARATIONS, at routes/web.php:957 and :983. Later
  declarations replace earlier ones for the same method+URI and silently drop the earlier
  middleware. Any Store 360 route added to the first block may be shadowed. Confirm with `php
  artisan route:list -v`, not by reading the file.

- STORE 360 IS A CROSS-TENANT READ OVER THE MODELS THAT DO NOT FILTER. `Store`, `Order`, `Product`,
  `User`, `Wishlist`, `DeliveryBoy` are absent from `scoped_models`, and `null_tenant => 'open'`
  means the scope adds no predicate when no tenant resolves. The scope will not isolate this
  surface. Derive the store from the operator's selection —
  `OperatorStoreSelection::reportStoreId()` (app/Support/Tenancy/OperatorStoreSelection.php:78),
  which has only 5 callers — never from `$request->store_id`.

- THE HELPDESK AND MARKETPLACELIST MODULES ARE INVISIBLE TO THE SUITE. No WorkDo module loads in any
  test run without an `add_on_managers` row, so a green test over a SUPPORT or MARKET surface may
  never have reached the module. Enable through the production loader and add a premise test.

- ADM-001 IS PARTLY BUILT AND WAS ALREADY RE-TRACED DOWN. PROJECT-STATE.md:48 records 'P05-14 re-
  traced DOWN from VERIFIED COMPLETE: the operator's view mirrors 3 of the merchant's 12 figure
  groups.' The operator dashboard is 252 lines with ONE store link, to the flat `stores.index`. Do
  not start ADM-001 from zero, and do not assume the existing dashboard satisfies it.

### Tenancy

Not the primary subject of this area, but three measured points bear on it, because Phase 06 is the
  phase that deliberately CROSSES tenants. 1. Store 360 (ADM-002) is by definition a cross-tenant
  read surface: one operator viewing any store's full state. The models it would need are the ones
  that do NOT filter. Per CLAUDE.md's measured table and config/tenancy.php's `scoped_models`:
  `Store`, `Order`, `Product`, `User`, `Wishlist`, `DeliveryBoy` are NOT scoped; only `Customer` and
  `Cart` really filter. With `null_tenant => 'open'`, when no tenant resolves the global scope adds
  NO predicate. So a Store 360 handler gets no isolation from the scope and must carry its own
  explicit store predicate derived from the authenticated operator's chosen store — NOT from request
  input. `app/Support/Tenancy/OperatorStoreSelection.php:78` (`reportStoreId()`) is the existing
  first-party idiom for 'which store is this operator looking at', with `unchosen()` at :56 and
  `logRefusal()` at :92; it has only 5 callers today (ReportController, CustomerSegmentController,
  RecoveryQueueController, OrderCurrencyStamp, itself). Reuse it rather than inventing a second
  selector. 2. The impersonation door is the sharpest tenancy fact here, and it is live.
  `Impersonate::take()` does not grant scoped access — after the call `auth()->user()` IS the
  merchant, so every downstream gate and every `current_store` read answers as that merchant.
  `app/Services/OperatorCatalogueWrite.php:45-48` states this in first-party source. It is gated
  operator-only by `UserPolicy::impersonate` (app/Policies/UserPolicy.php:385) and is NOT merchant-
  reachable — proven by execution, 10/10 tests pass including the three refusal cases. But it is
  UNAUDITED: StoreController.php writes `AuditLog::record` only at :958, for password reset. A write
  made while impersonating is indistinguishable in the record from the merchant's own. That is the
  gap SUPPORT-004's 'audit log' clause names, and it exists today regardless of Phase 06. 3.
  `StoreController::show` being an empty stub is a tenancy opportunity, not just a hole: `GET
  stores/{store}` is registered and currently returns nothing, so Store 360 can be built there
  without displacing a working surface. Note the adjacent measured footgun recorded in the source:
  `edit()` takes a USER id while `update()` takes a STORE id on the same resource route, so `PUT
  /stores/{userId}` can write to whichever store happens to carry that number. Operator-only, so an
  integrity footgun rather than a cross-tenant hole — but do not copy the id convention into a new
  `show()`.

### Authorization

Measured permission strings and operator admission for every door in this area. IMPERSONATION (the
  SUPPORT-004-adjacent door) — `routes/web.php:1131` -> `StoreController::LoginWithAdmin`.
  Middleware, read from `php artisan route:list -v`, is `web`,
  `Illuminate\Auth\Middleware\Authenticate`, `App\Http\Middleware\XSS`,
  `App\Http\Middleware\SetLocate`, `App\Http\Middleware\EmailIsVerified`. NO permission middleware
  and NO PlatformOperatorOnly on the route. The whole control is in the handler:
  `$this->authorize('impersonate', $user)` at StoreController.php:1091, resolving to
  `UserPolicy::impersonate` (app/Policies/UserPolicy.php:385). That policy admits ONLY the platform
  operator (`isPlatformAdmin($actor)`), refuses self (`$actor->id === $target->id`), and refuses
  impersonating another operator (`! self::isOperator($target)`, normalised — a `'Super Admin'` row
  used to be impersonable). This is the correct operator-first shape CLAUDE.md prescribes, not a
  Laratrust `isAbleTo()` call, so the operator is ADMITTED rather than refused. No permission NAME
  is involved at all. Verified by execution: ordinary merchant denied, anonymous denied, forged
  encrypted id denied, operator admitted. ADD-ON / MARKETPLACE WRITES — `routes/web.php:1155` wraps
  `Route::resource('addon')`, `addon-apps`, `theme-addon`, `theme-enable` in
  `Route::middleware('can:manage-platform-addons')`. This is a GATE (defined in
  AuthServiceProvider::boot per the comment at :1150-1154), not a Laratrust permission — which
  matters, because `permissions_as_gates => false` makes `@can`/`$user->can()` always false for
  PERMISSIONS, while an explicitly registered Gate still evaluates. The source comment records that
  each controller method ALSO calls `$this->authorize()` itself, 'so the control does not rest on
  this grouping alone -- relying on grouping alone is what failed in SEC-F2'. I did not
  independently verify those in-method calls — NOT ESTABLISHED. ADD-ON CATALOGUE READS —
  `routes/web.php:403` (`add-on/details/{slug}`, middleware `setlocate`) and `:406` (`add-on`).
  These are public browse surfaces; `HomeController::Software` and `::Pricing` apply no
  authorization, and `Pricing()` redirects an authenticated `type == 'admin'` user to `plans`.
  Appropriate for a public catalogue; worth knowing before MARKET work adds anything sensitive to
  those views. STORE 360 — no door exists, so nothing to measure. `StoreController::show` is empty.
  Whoever builds it must write the operator-first check explicitly, because `isAbleTo()` alone
  REFUSES the platform operator, who holds no Laratrust role — the exact defect GAP-218 recorded for
  five Marketing gates. The nearby family idiom is a `type == 'super admin'` string test
  (StoreController.php:1128 lists upgradePlan, activePlan, customDomain, subDomain, index and
  LoginWithAdmin as all carrying one). KYC — no door, no permission string, nothing to measure.

### What the suite already covers, and what it cannot reach

For the things that EXIST, coverage is real. For the three scope words, there is NONE — which is
  expected, since none of them has code. EXISTS AND TESTED —
  `tests/Feature/Security/ImpersonationTest.php` (8,808 bytes, 10 test methods). EXECUTED by me:
  `php artisan test --filter=ImpersonationTest` -> 10 passed, 22 assertions, 3.35s. The ten:
  ordinary merchant cannot impersonate; forged encrypted id is denied; anonymous caller cannot
  impersonate; platform admin cannot be impersonated; platform admin can still impersonate a
  merchant; exit admin without active impersonation is rejected; model level impersonation
  predicates; a merchant cannot list another users store links; an anonymous caller cannot list
  store links; a platform admin can still list a users store links. Both directions are covered
  (refusal AND the legitimate path), which is why I can state GAP-197 is refuted rather than merely
  doubted. Note what these tests do NOT assert: nothing about an audit row, a time limit, or
  merchant consent — consistent with my measurement that none of those three exists. STORE 360
  (ADM-002) — **NONE.** No code, so no tests. `grep -rn 'Store 360|store360|store_360' app/
  packages/ resources/ routes/` = 0. KYC (KYC-001..003) — **NONE.** No code, so no tests. 0 word-
  boundary hits in any PHP or Blade file. MARKETPLACE (MARKET-001..005) — **NOT ESTABLISHED.** I did
  not search for tests over the add-on catalogue views or `can:manage-platform-addons`. The surfaces
  exist, so tests may; I did not measure it and will not guess. P06-08 ITSELF — **NONE, and none is
  possible in the suite.** The requirement is a documentary separation of three numbering schemes;
  there is nothing executable to assert. It belongs in prose, which is where its Next action puts
  it. STRUCTURAL CAVEAT that bounds any future Phase 06 test work: no WorkDo module loads in any
  test run unless the test inserts an `add_on_managers` row. So `packages/workdo/Helpdesk` (the only
  support surface P06-03 names) and `packages/workdo/MarketPlaceList` are both invisible to the
  suite by default. A test that 'passes' against either may never have reached the module. Any
  SUPPORT or MARKET work must enable the module through the production loader and add a premise test
  — and must not read a green suite as evidence it touched module code.

### The vendored constraint here

Almost nothing in this area is vendored, which is unusual and is good news for Phase 06 — but the
  one vendored piece sits exactly where the sharpest requirement lands. VENDORED, must not be
  edited: (1) `packages/workdo/MarketPlaceList` — the store-directory/business-hours module that
  GAP-189 and GAP-196 both warn is NOT the marketplace. 17 PHP files, controller 135 lines, model
  `StoreBusinessHour`. If Phase 06 wants MARKET-001's app/theme marketplace it must be first-party;
  this module cannot be grown into it. (2) `packages/workdo/Helpdesk` — named by P06-03 as the only
  support surface; its enablement, tenant-safety and authorisation are explicitly NOT established by
  that row, and I did not establish them either. (3) The 70-module add-on catalogue the marketplace
  views enumerate via `Module::getByStatus(1)` is vendored content; the catalogue VIEWS are first-
  party. NOT VENDORED — so Phase 06 can build here directly: `resources/views/marketplace/` (5
  views), `app/Http/Controllers/HomeController.php` (the catalogue handlers),
  `app/Http/Controllers/StoreController.php` (impersonation + the empty `show()` stub where Store
  360 would live), `app/Policies/UserPolicy.php` (the impersonate policy),
  `app/Support/Tenancy/OperatorStoreSelection.php`,
  `resources/views/superadmin/dashboard.blade.php`, `app/Models/AuditLog.php`. One genuinely
  vendored dependency inside first-party code: `lab404/laravel-impersonate` supplies
  `Impersonate::take()` and the `Impersonate` trait on `app/Models/User.php:14,25`.
  StoreController.php:1105 records that `vendor/lab404` is EMPTY in this environment, so
  `isImpersonated()` could not be verified and the code falls back to checking the `impersonated_by`
  session key. Any SUPPORT-004 work that adds time limits or audit rows around impersonation must
  wrap this package from first-party code, not patch it — and must not assume the vendor directory
  is populated. Separate hazard, not vendoring but worth carrying: `Route::resource('stores',
  StoreController::class)` is declared TWICE, at routes/web.php:957 and again at :983. Per
  CLAUDE.md, the later declaration replaces the earlier for the same method+URI and silently drops
  the earlier middleware, so any Store 360 route added to the :957 block may be shadowed. Measure
  with route:list before trusting either block's middleware.

### What the refutation corrected

- **The map said:** "Per CLAUDE.md's measured table and config/tenancy.php's `scoped_models`:
  `Store`, `Order`, `Product`, `User`, `Wishlist`, `DeliveryBoy` are NOT scoped; only `Customer` and
  `Cart` really filter."
  **Wrong because:** `scoped_models` holds ELEVEN models, not two, and `App\Models\DeliveryBoy` IS
    one of them. All eleven also register StoreScope, so all eleven genuinely filter: Menu,
    SupportTicket, SupportConversion, FlashSaleCondition, ThemeCustomizeOrder, NotifyUser,
    OrderRefund, OrderNote, Customer, Cart, DeliveryBoy. The map was told to check
    config/tenancy.php itself and claimed it had. This is not cosmetic: the map's operative advice
    for Store 360 is 'the scope will not isolate this surface'. For
    Store/Order/Product/User/Wishlist that is right; for the other nine it is backwards — StoreScope
    is LIVE and will actively interfere. A Store 360 panel showing a store's support tickets, order
    notes, order refunds or notifications would, for an operator with a `current_store` set,
    silently return the OPERATOR's store's rows instead of the target store's, and with no tenant
    resolved (null_tenant => 'open') return every tenant's. Support and refunds are exactly Phase 06
    subject matter (SUPPORT-001..005), so this is the single most likely way a Phase 06 Store 360
    build goes wrong. (CLAUDE.md §1 is also stale here, so the error is inherited — but it was
    restated as a fresh measurement.)
  **Measured by:** Executed in a bootstrapped container: `php -r 'require "vendor/autoload.php";
    $app=require "bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    var_dump(config("tenancy.scoped_models"));'` -> 11 entries, including App\Models\DeliveryBoy.
    Second run over each: `(new $cls)->getGlobalScopes()` -> StoreScope registered on all 11
    (Customer additionally SoftDeletingScope). Per-model cross-check printed: DeliveryBoy | IN
    scoped_models | scopes: StoreScope. config/tenancy.php:470 `'null_tenant' =>
    env('TENANCY_NULL_TENANT', 'open')`.

- **The map said:** "The Lynomia Phase 6 is on a DIFFERENT BRANCH and its code is absent from this
  checkout — which is what makes the two safely separable at HEAD... The
  `SuperAdmin/IntegrationController` it describes does not exist here." Evidence offered: "`ls
  app/Http/Controllers/SuperAdmin/` -> 'No such file or directory'; `grep -c SuperAdmin
  routes/web.php` -> 0. This confirms P06-05's claim at current HEAD."
  **Wrong because:** The controller EXISTS at HEAD, in the one package CLAUDE.md permits editing,
    and it is fully routed and live.
    `packages/workdo/LynomiaChat/src/app/Http/Controllers/SuperAdmin/IntegrationController.php` is
    884 lines with 15 public methods (show, approve, revoke, restore, suspend, resume, decommission,
    reauthenticate, updateSeats, updateMarketingAllowance, storeAddon, grantAddon,
    issueCommerceCredential, revokeCommerceCredential, remoteHealth). `php artisan route:list
    --path=super-admin/lynomia-integration` returns 15 registered routes under name prefix
    `lynomia.admin.`, gated by App\Http\Middleware\PlatformOperatorOnly. The map's two commands
    could not have found it: the file is under packages/, not app/, and its routes live in the
    module's own routes file, not routes/web.php. Three consequences. (a) The map's first-
    party/vendored call is wrong in the direction that matters most — it listed LynomiaChat nowhere,
    while CLAUDE.md names LynomiaChat as the sole editable package, so this is first-party Phase
    06-adjacent code, not untouchable vendored code. (b) 'Safely separable at HEAD' rests on a false
    premise; separability is now an owner question. (c) 'This confirms P06-05's claim' is a
    measurement claim about 'platform admin' derived from one missing directory name, and it does
    not survive.
  **Measured by:** ls -la packages/workdo/LynomiaChat/src/app/Http/Controllers/SuperAdmin/
    (IntegrationController.php, 42157 bytes); wc -l = 884; grep -n 'public function' on it = 15
    methods; grep -rn 'IntegrationController' packages/workdo/LynomiaChat/src/routes/web.php -> :23
    import, routes at :568, :570, :574, :578, :585, :589, :594, :599, :608, :615, :624, :626, :628,
    :634, :638; sed -n '552,572p' on that file shows
    `Route::middleware([\App\Http\Middleware\PlatformOperatorOnly::class])->prefix('super-
    admin/lynomia-integration')->name('lynomia.admin.')`; `php artisan route:list --path=super-
    admin/lynomia-integration` -> 'Showing [15] routes'; `php artisan route:list
    --name=lynomia.admin.index -v` -> web, Authenticate, XSS, SetLocate, EmailIsVerified,
    PlatformOperatorOnly.

- **The map said:** "StoreController.php:1105 records that `vendor/lab404` is EMPTY in this
  environment, so `isImpersonated()` could not be verified and the code falls back to checking the
  `impersonated_by` session key. Any SUPPORT-004 work that adds time limits or audit rows around
  impersonation ... must not assume the vendor directory is populated."
  **Wrong because:** vendor/lab404 is NOT empty — it holds 66 files including
    src/Models/Impersonate.php, src/Services/ImpersonateManager.php and src/Guard/SessionGuard.php —
    and the trait method resolves at runtime. So in `ExitAdmin` the ternary `method_exists($actor,
    'isImpersonated') ? $actor->isImpersonated() : Session::has('impersonated_by')` takes the FIRST
    branch; the session-key fallback is dead code today. The map read a stale source comment and
    reported it as a current measurement of the environment, then derived a forward-looking
    engineering instruction from it. Anyone following 'must not assume the vendor directory is
    populated' would build defensive handling for a condition that does not hold, and would mis-
    model which code path actually runs when adding a time limit or an audit row around
    impersonation — the exact work SUPPORT-004 names.
  **Measured by:** `find vendor/lab404 -type f | wc -l` -> 66; `ls -la vendor/lab404/` -> laravel-
    impersonate present; `grep -n lab404 composer.json` -> :29 "lab404/laravel-impersonate": "^1.7".
    EXECUTED: `php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    var_export(method_exists(new App\Models\User,"isImpersonated"));'` -> true (leaveImpersonation
    also true; ImpersonateManager::getSessionKey() -> 'impersonated_by'). The stale comment is at
    app/Http/Controllers/StoreController.php:1105.

- **The map said:** "`Route::resource('stores', StoreController::class)` is declared TWICE, at
  routes/web.php:957 and again at :983. Per CLAUDE.md, the later declaration replaces the earlier
  for the same method+URI and silently drops the earlier middleware, so any Store 360 route added to
  the :957 block may be shadowed. Measure with route:list before trusting either block's
  middleware."
  **Wrong because:** There are not two blocks. Both declarations sit inside ONE middleware group —
    `Route::middleware(['auth','xss','setlocate','verified'])->group(...)` opened at
    routes/web.php:466 and closed at :1901 — and the two lines are byte-identical. No middleware is
    dropped, because there is no differing middleware to drop. route:list confirms every stores.*
    route carries the same five-item stack. The duplicate is a harmless redundancy, not a middleware
    hazard. The map itself listed this under not_established ('I did not diff the two blocks'
    middleware stacks to establish what was shadowed') and then stated it as fact twice — in
    `vendored_constraint` and in the `routes` table — including the fabricated detail 'the earlier
    block's middleware is silently dropped'. Carrying this forward would send an implementer hunting
    a non-existent middleware discrepancy. (The narrow residual risk is real and worth keeping: a
    NEW route whose method+URI collides with `GET stores/{store}` must be declared after :983 to
    win. That is a registration-order point, not a middleware point.)
  **Measured by:** `grep -n "Route::group\|Route::middleware\|Route::prefix" routes/web.php | awk
    -F: '$1>400 && $1<1200'` returns only three lines — :430, :466, :1155 — so no group boundary
    exists between :957 and :983. `awk 'NR>=466 && /^}\);/ {print NR; exit}' routes/web.php` ->
    1901, the close of the :466 group. `sed -n '930,990p' routes/web.php` shows both bare
    `Route::resource('stores', StoreController::class);` lines with no intervening group. `php
    artisan route:list --path=stores -v` prints the identical stack (web, Authenticate, XSS,
    SetLocate, EmailIsVerified) for stores.index/store/create/show/update/destroy/edit and for
    store.user.create and plan.upgrade.

**And 8 things the map did not say at all:**

- app/Http/Controllers/PlatformAnalyticsController.php:1-175 plus
  app/Support/Metrics/PlatformAnalyticsFigures.php:1-1288 — 1,463 lines of first-party OPERATOR
  platform analytics, routed at routes/web.php:1240 (platform/analytics) and :1245
  (platform/analytics/export), both gated by PlatformOperatorOnly at :1241 and :1246. This is the
  closest existing thing to ADM-001 'a comprehensive platform command dashboard'. The map's ADM-001
  treatment cited only the 252-line resources/views/superadmin/dashboard.blade.php and PROJECT-
  STATE.md:48, so its warning 'do not start ADM-001 from zero' points at the wrong file and
  understates what exists by ~1,460 lines. Measured by `wc -l` and `grep -n PlatformAnalytics
  routes/web.php`.

- app/Http/Middleware/PlatformOperatorOnly.php:51-73 — the first-party middleware that already
  implements exactly the operator-first control CLAUDE.md prescribes: `if (! $user) { return
  $next($request); }` at :57-59 (deliberately not a denial, so `auth` owns the anonymous case) and
  `strtolower(trim((string) $user->type)) === self::PLATFORM_ADMIN` at :61, else 403/JSON-403. Used
  in 17 files (grep -rln). The map noticed its ABSENCE on the impersonation route but never offered
  it as the reusable control for a new Store 360 door, instead pointing at a hand-rolled `type ==
  'super admin'` string test at StoreController.php:1128. It is also missing from the map's 'NOT
  VENDORED — so Phase 06 can build here directly' list.

- resources/views/partision/header.blade.php:148-155 — the shipped impersonation UI, which the map
  missed entirely: lab404's `@impersonating($guard = null)` directive wrapping an 'Exit Admin Login'
  link to `route('exit.admin')`, closed by `@endImpersonating`. A third first-party file any
  SUPPORT-004 change must touch, and independent corroboration that the impersonation door is a live
  user-visible feature rather than dead code.

- AuditLog::record has 52 call sites across 20 files (`grep -rn 'AuditLog::record' app/ packages/ |
  wc -l` = 52), including ThemeVersionController, StoreFinanceController, BankTransferController,
  PlanController, UserController and twelve LynomiaChat services. The map quoted
  app/Services/OperatorCatalogueWrite.php:51-52 as a measurement — "`AuditLog::record` is called
  only for plan orders, users and password resets" — and that docblock sentence is itself stale. The
  map's own narrower conclusion (no audit row on impersonation) still stands, because
  StoreController.php has exactly one call at :958.

- app/Http/Controllers/Customer/Auth/CustomerLoginController.php:987 — a measured interaction a
  SUPPORT-004 time-limit or session-kill implementation needs: the single session cookie carries a
  merchant's `web` session AND lab404's impersonation keys, and this handler deliberately uses
  `$session->forget([...])` rather than `invalidate()`/`flush()` because clearing it wholesale is 'a
  measured regression this file's own test forbids by method text'. Also :574, which records that
  impersonation take AND leave rotate the session id through lab404's `quietLogin()`.

- app/Http/Controllers/AddonController.php:21 and :184 — the map left the in-method authorize calls
  NOT ESTABLISHED; I established them, and the picture is more mixed than the route comment claims.
  Three routed methods do call the gate (destroy :54, ThemeInstall :76, ThemeEnable :164) but
  index() at :23 and AddonApps() at :184 use `auth()->user()->type == 'super admin'` instead. So
  routes/web.php:1153's 'Each controller method ALSO calls $this->authorize() itself' is imprecise —
  two use the operator string test. Both are real controls, so this is a documentation inaccuracy,
  not a hole.

- Further first-party platform controllers the map never inventories, relevant to P06-02 (the one
  PARTIALLY IMPLEMENTED row) and P06-05: app/Http/Controllers/PlanController.php,
  PlanRequestController.php, PlanCouponController.php, PlatformDocsController.php,
  PlatformSiteController.php, StoreFinanceController.php (`ls app/Http/Controllers/ | grep -iE
  'plan|platform'`), plus the SaaS tables create_plans_table, create_plan_requests_table,
  create_plan_coupons_table, create_plan_orders_table, create_plan_purchase_intents_table in
  database/migrations/.

- A phone/contact verification surface exists in LynomiaChat and the map's KYC variant list never
  tested for it: packages/workdo/LynomiaChat/src/database/migrations/2026_10_30_000100_add_mobile_ve
  rified_at_to_users.php, .../2026_10_30_000200_create_lynomia_contact_verifications_table.php,
  .../2026_10_16_000100_create_lynomia_store_identities_table.php. These are channel/contact
  verification and integration identity, NOT identity documents, so GAP-194 'no KYC' survives — but
  the map searched only kyc, identity_verification, national_id, id_document and
  verification_document, missing mobile_verified_at and contact_verifications, which are the
  adjoining primitives a KYC build would meet.

### Not established — stated as such rather than guessed

- Whether `packages/workdo/Helpdesk` is enabled, tenant-safe or authorised. P06-03 explicitly leaves
  this open and I did not measure it either. Any SUPPORT-001..005 work must inventory it first,
  through the production module loader, because no WorkDo module loads under the suite without an
  `add_on_managers` row.

- Whether the `can:manage-platform-addons` controller methods really call `$this->authorize()`
  individually. The source comment at routes/web.php:1150-1154 asserts they do; I did not open
  AddonController to confirm it, so the depth of that control is unverified.

- Whether any test coverage exists over the add-on catalogue views or the addon install/theme-enable
  doors. Not searched.

- Whether the Phase 06 ids other than ADM are each covered by a P06-* row. The DECISION-ID-
  REGISTER's own §4.3 implies SAAS-001..006, OPS-001/003, IAM-001/002/004, SUPPORT-001/002/003/005
  are covered in substance by the derived rows P06-02..P06-06, but I did not trace each of those ~20
  ids to a specific row. Only ADM was traced to ZERO rows, and only that claim should be relied on.

- Whether the three missing decision texts (1 GOV, 2 ARCH) bear on Phase 06. DECISION-ID-
  REGISTER.md:574 records them as unrecovered and says they must not be invented; I did not attempt
  to determine their phase.

- Whether `EX-011` (auto-deleting store data on subscription end) is actually honoured in code.
  DECISION-ID-REGISTER.md:464 marks it 'UNVERIFIED -- must be checked before any Phase 06
  subscription work' and notes Discovery recorded the subscription-expiry path as having enforcement
  commented out. I did not re-measure SetLocate.php, so P06-02's expiry finding remains unverified
  at current HEAD, exactly as its Next action says.

- Whether the duplicate `Route::resource('stores', ...)` at routes/web.php:957 and :983 actually
  drops middleware in practice. I measured the duplication and route:list shows a single set of
  stores.* routes, but I did not diff the two blocks' middleware stacks to establish what was
  shadowed.

- Whether impersonation has any time limit anywhere. I found no expiry mechanism around it, but my
  word-boundary grep for 'impersonat' was malformed (returned 0 while the substring plainly occurs),
  so I am reporting the absence of a time limit as UNVERIFIED rather than measured. `expires_at`
  occurs in 10 app/ files; I did not check whether any relates to impersonation.
