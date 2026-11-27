# `P04-P9-01` — the reachability audit and the end-to-end journeys

**Branch:** `ws/journeys` · **Measured:** 2026-11-27 against `int/checkpoint` (`b2e823561`)
**Status of this document:** every figure in it was produced by a command in this worktree. Where
something was not read, it says so.

---

## 0. The standard being measured against

> Every implemented feature must survive integration and be accessible through its intended user
> journey. **Backend code, an unreachable screen, or a passing isolated test is not complete
> product delivery.**

Two concrete breaches prompted this work: four operator screens built, gated and tested and
reachable only by typing the URI, and a view hunk lost in a merge while its controller half
survived.

---

## 1. Method

### 1.1 The subject set comes from the booted route table

`RouteFacade::getRoutes()` inside a booted `RefreshDatabase` test, never a grep of `routes/*.php`:
`CLAUDE.md` records that a later declaration **replaces** an earlier one for the same method+URI
and silently drops the earlier middleware, so the files and the table disagree.

| | |
|---|---|
| registered routes in the booted table | **1,468** |
| of them with an `App\` action | **1,117** |
| of them `Workdo\*` (the two static add-ons, `LynomiaChat` and `LandingPage`) | **312** |
| host **web** routes, non-`api/` | **910** |
| host **api** routes | **207** |

**`add_on_managers` is empty under `RefreshDatabase`**, so only the two static add-ons register
their routes. That is stated rather than worked around: the 70 vendored modules' own routes are
**not in this census**, and a module screen nobody links would not be found by it.

### 1.2 "Offered" enumerates the safe set, not the dangerous spellings

`CLAUDE.md` §3: *a list of dangerous spellings is always short by the one nobody thought of* — a
concatenation census keyed on `'literal' . $var` reported one dynamic raw-SQL site where there were
fifteen. So the census never looks for `route(`, `action(`, `url(`, `href=`, `$.ajax` or `fetch(`.
Laravel has exactly **three** ways to name a route's address, and all three are properties of the
route rather than of the call:

| key | searched for | covers |
|---|---|---|
| **name** | the route's name as a quoted literal | `route('x')`, `redirect()->route('x')`, `Form::open(['route' => 'x'])`, a `Menu::add(['route' => 'x'])` entry — anything at all that passes the name |
| **uri** | the route's static URI prefix as a path-shaped literal, in a **view or script** | `url('/x')`, `href="/x"`, `'/x/' + id`, `$.ajax({url:'/x'})`, `<form action="/x">`, a hand-written link |
| **action** | the short class name together with `'method'`, or `Controller@method` | `action([C::class,'m'])`, `action('C@m')` |

A fourth spelling of any of the three still contains the name, the path or the pair, so an
unthought-of spelling is caught by default. **What is not covered**, stated rather than papered
over: a URL assembled from pieces that spell none of them (`'/' . $segment . '-list'`), and
`url('x')` with neither a leading slash nor a second segment.

**Corpus: 2,651 files** — `resources/views`, `resources/js`, `themes`, `packages/workdo` views,
`public` scripts, `app`, `routes` — **read with comments removed**. That is load-bearing, not
tidiness, and two of this audit's own findings prove it: `StoreController.php` contains the
sentence *"nothing in the UI links `stores.edit` or `stores.update` at all"*, and
`OrderDocumentAccessGuard` contains the literal `'order-packing-list/{id}'`. Reading comments would
have let prose about each finding satisfy the question about it — `CLAUDE.md` records that failure
eight times.

**Two layers excluded from all three keys, each for a measured reason:**

* `routes/` — `->name()` names every route, so the declaration would satisfy the census for all of
  them.
* `app/Http/Middleware/` — a middleware never *offers* a route; it passes, refuses, or forces a
  redirect. `DemoModeMiddleware` holds the literal `'stores.edit'` in its list of names blocked in
  demo mode, and `PlatformOperatorOnly` holds `'platform/verification'` in order to refuse requests
  to it. Both would have credited a screen with an entry point it does not have. This also corrects
  a claim the first draft made: a route name is **not** only ever used to build a URL —
  `$request->route()->getName()` is how a gate matches one.

The **uri** key is additionally restricted to views and scripts. In a view a path literal is a
link; in `app/` it is as likely a gate's map or a filesystem path (`Utility.php` holds
`'…workdo.io/cronjob/addon.json'`). And the literal must *start* with the prefix at a path
boundary: `'../../addon/mode/overlay'` in a minified editor plugin contains `/addon` and is not a
reference to `GET /addon`.

### 1.3 Two questions, because "something names it" is not "somebody can get there"

**(a) Is the screen named at all?** A screen no file names can only be reached by typing its URI.

**(b) Is the *feature* entered from outside itself?** The KYC package is why this exists:
`kyc.index` is named by `resources/views/kyc/access-log.blade.php` and `kyc.queue` by
`resources/views/kyc/queue.blade.php` — its own filter form. Every reference is inside the feature,
so the three screens link to each other and nothing links to any of them. Question (a) cannot see
that.

Asked per **controller**, not per route, and that matters in the other direction too:
`countries.create` is named only by `country/index.blade.php`, which `CountryController` itself
renders — and that is *correct*, because the index is in the merchant navigation and carries the
"Add" button. A per-route rule would call it unreachable.

---

## 2. The result

**910 host web routes. 111 are offered by no surface. Six of those are GET screens.**

| category | count | reading |
|---|---|---|
| **GET screen offered by nothing** | **6** | the finding class this audit is about |
| `Route::resource` verb with no UI (`show`/`create`/`edit`/`update`/`destroy`) | 64 | mostly **empty stubs** — `ShippingMethodController::index()` is literally `{ // }`. `GAP-268` already withdrew 55 of these; the rest are the same shape |
| payment / plan gateway hand-off or callback | 9 | **deliberate** — a third party arrives here |
| unnamed route (cannot be `route()`d) | 7 | `POST login`, `POST register`, `POST confirm-password`, `zid/oauth/{redirect,callback}`, `zid/connect/return`, `PATCH update-cart`. Absent from the standing test by construction, which is why it requires a name |
| POST handler that renders a fragment | 2 | `get.country`, `products.sku_combination` |
| other writer or AJAX endpoint | 25 | includes the two genuinely new findings below |
| **total** | **111** | |

### 2.1 The table the brief asked for

Each row: the route, what built it, which principal should reach it, and the nearest surface that
does or does not offer it.

#### Features the brief named

| route | what built it | principal | nearest surface | verdict |
|---|---|---|---|---|
| `GET verification` (`kyc.index`) | `4774ddbc8` KYC-001..003 | merchant | `resources/views/kyc/access-log.blade.php` — **inside KYC** | **OMISSION.** no navigation entry and no link from any screen outside the package |
| `GET platform/verification` (`kyc.queue`) | same | operator | `resources/views/kyc/queue.blade.php` — **its own filter form** | **OMISSION.** the operator's verification queue has no entry point |
| `GET verification/documents/{document}/access-log` | same | both | `kyc/index.blade.php`, `kyc/queue.blade.php` — inside KYC | omission, consequent on the two above |
| `POST verification/documents`, `POST verification/{record}/submit`, `POST platform/verification/.../decide` ×2, `GET …/download` | same | both | the KYC views | writers reached from the screens above; they become reachable when those do |
| `GET platform/approvals` (`platform.approvals.index`) | `1a722c08e` Phase 06 IAM | operator | **only its own controller's redirects** | **OMISSION.** an operator who has not been handed the URL cannot find the queue they are meant to act in. Navigation for the `platform/*` screens is another workstream's |
| `POST platform/approvals/{approval}/{approve,reject}` | same | operator | the queue screen | consequent |
| `GET platform/incidents` + `/{incident}` + 3 writers | Phase 06 | operator | `resources/views/superadmin/dashboard.blade.php` — `route('platform.incidents.index')`, and `platform_incidents/_table.blade.php` for the detail | **REACHABLE.** offered from the operator dashboard |
| `GET platform/operations` | Phase 06 | operator | `superadmin/dashboard.blade.php` and `superadmin/platform_resources.blade.php` | **REACHABLE** |
| `POST order-return-inspection` (`RET-002`) | Phase 04 | merchant | `resources/views/order/refund_details.blade.php:384` — the `.save-return-condition` control posts to it; that view is rendered by `OrderRefundController` and reached from `refund-request.index`, which **is** in `CompanyMenuListener` | **REACHABLE.** chain verified end to end |
| `platform_feature_enabled()` / `platform_feature_decision()` | `25006ed4b`, `d4a9fed6a` CTRL-001..003, on **`ws/p6-ctrl`, not in `int/checkpoint`** | every surface that should honour a flag | **nothing.** measured on that branch: the only occurrences of either helper in `app/`, `resources/`, `routes/` or `packages/` are their own definitions in `app/Helper/helper.php`. `FeatureControl::` has one consumer, `PlatformFeatureControlController`, plus `FeatureControlOverview`, which is that screen's own data builder | **the plane is reachable; its EFFECT is not.** See §2.3 |

#### Everything else the audit found (first-party, not in the brief)

| route | what built it | principal | nearest surface | verdict |
|---|---|---|---|---|
| `GET product-price-explanation/{id}` | `c027734fe` **PRICE-006** "preview the final price and explain it" | merchant | **nothing, anywhere.** 0 occurrences of the route name, the path or the action pair in the whole corpus | **OMISSION, and a plan requirement.** 04-E asks for `معاينة السعر النهائي وشرح طريقة حسابه` (*preview the final price and explain how it is calculated*). The endpoint is complete, operator-first gated, tenant-predicated and has `ProductPriceExplanationAuthorizationTest`; no merchant can see a price explanation |
| `GET order-packing-list/{id}` | `654cc12ad` **ORD-008 / GAP-185** | merchant | was **nothing**; its sibling `order.receipt` is linked from `order/view.blade.php:31` | **OMISSION — FIXED IN THIS CHANGE.** a button beside the invoice and the shipping label, the other two documents 04-C names |
| `GET theme-version/{theme}/history` | `c5a95d438` Phase 03 M4-6 | merchant | **nothing.** its three siblings (`publish`, `rollback`, `draft`) **are** offered, by `theme_customize/_version-panel.blade.php`, which `customize.blade.php` pulls in with `@includeWhen` | **OMISSION (small).** the version panel offers the three writers and not the history read |
| `GET {storeSlug}/communication-preferences` (`marketing.preferences.show`) | `59a274c62` D8-3b "a signed-in shopper's own preference centre" | shopper | **nothing.** the only mention is `StorefrontSeo.php`'s noindex list, which is not an offer | **OMISSION.** a shopper cannot find their own preference centre |
| `GET {storeSlug}/unsubscribe/{token}` (`marketing.unsubscribe.show`) | `17052bda9` D8-3a "the unsubscribe link is real" | recipient of a message | **nothing in this repository, and nothing in an email either**: `UnsubscribeTokens::issue()` — the only way to mint a token — has **zero callers** outside its own class | **OMISSION, and the stronger half of it is a product defect**: the page works, the redemption works, and no outgoing message ever contains an unsubscribe link. See §2.3 |
| `GET {storeSlug?}/order-return-list` (`order.return.list`) | pre-existing | shopper | **nothing** but the SEO list | omission (storefront) |
| `GET currency-rates` | pre-dates the phases | merchant | **nothing.** the feature is reachable only through `api/admin/get-currency-rates` and `api/admin/update-currency-rate`, i.e. from the shipped Flutter app | **OMISSION (web), deliberate-by-accident (Flutter)** |
| `GET otp-integration` + 2 writers | pre-dates the phases | merchant | **nothing**; no entry in `CompanyMenuListener` | **OMISSION (legacy)** |
| `GET stores/{store}/edit` | pre-dates the phases | operator | **nothing** — and `StoreController`'s own source already says so | omission, **already documented** |
| `GET customer-filter-data` | pre-dates the phases | merchant | **nothing**, and `customer/grid.blade.php` already records that the element it writes into *does not exist in this view* | **DELIBERATE (dead)**, already documented |
| `GET pos/product-variant/{id}/{session}` | pre-dates the phases | merchant (till) | **nothing** | **DELIBERATE (dead)** |
| `GET stock-report` (`stock.report`) | pre-dates the phases | merchant | the navigation names `reports.stock_report` (`/stock-reports`) — **the same handler** on a different URI | **DELIBERATE (alias).** not an unreachable feature, an unlinked twin |
| `GET stripe/{code}` (`stripe.plan`) | pre-dates the phases | merchant paying for a plan | **nothing** — a gateway hand-off | **DELIBERATE** |
| `GET /addon`, `/addon-apps`, `/addon/create` | pre-dates the phases | operator | the navigation's "Add-on Manager" points at **`module.index`** (`ModuleController`), which is reachable | **DELIBERATE (superseded).** the earlier parallel set |
| `GET confirm-password` | Breeze | anybody re-confirming | nothing in this repository — Laravel's own `RequirePassword` middleware, in `vendor/`, redirects there | **DELIBERATE (framework redirect target).** a link to it would be wrong |
| 64 `Route::resource` verbs | vendored era | — | nothing | **DELIBERATE (dead stubs).** `GAP-268` withdrew 55 of the same shape; `ShippingMethodController::index()` is `{ // }` |
| 9 gateway callbacks | vendored era | third party | nothing | **DELIBERATE** |

### 2.2 The credible negative

**Three of the four things the brief flagged as "most likely unreachable" turned out reachable, and
the method shows why that answer is trustworthy:**

* `platform/operations` and `platform/incidents` — offered from `superadmin/dashboard.blade.php`
  by name. The navigation workstream's dependency is already paid for these two.
* `RET-002`'s return-condition inspection — offered by a `.save-return-condition` control in
  `order/refund_details.blade.php`, on a screen reached from the merchant navigation's
  `refund-request.index`. Verified link by link, not by the route's existence.
* `theme.version.{publish,rollback,draft}` — offered by `_version-panel.blade.php`. The census's
  first draft reported these as unreachable because its include detector required the view name to
  be the *first* argument and `customize.blade.php` uses
  `@includeWhen(isset($liveVersion) || isset($latestDraft), 'theme_customize._version-panel')`.
  **That was a census defect, found by hand-checking its own output**, and it is why §1.2 states
  what the keys do not cover.

And the two KYC/approvals verdicts were each confirmed by an independent grep before being written
down.

### 2.3 Two of these are product defects rather than missing links

**(a) The unsubscribe link does not exist.** `D8-3a`'s commit title is "the unsubscribe link is
real — storefront route, bi…". The route is real, the page is real, `UnsubscribeTokens::redeem()`
is real and rate-limited. But `UnsubscribeTokens::issue()` — the only producer of a token, and
therefore of a link — is called from **nowhere**: measured over `app/`, `resources/` and
`packages/`, the only occurrences of `issue(` on that class are its own declaration. No mailable,
notification or campaign sender puts an unsubscribe link in a message. A consent surface that
cannot be reached from the message it is about is not a delivered unsubscribe.

**(b) The feature control plane has no adopters.** On `ws/p6-ctrl`, `CTRL-002` is
`إطلاق تدريجي وإيقاف آمن` (*gradual rollout and safe stop*). The operator can define a feature,
release it to a percentage, add a store to a pilot group and disable it — and **no surface in the
product asks**. `platform_feature_enabled()` and `platform_feature_decision()` have zero callers.
A per-surface adoption is a reasonable design; **zero** adoptions means the "safe stop" half of
CTRL-002 has no effect to stop. This is reported as a scope observation, not a code defect: the
plane itself is correct and its screen is linked from the operator dashboard.

---

## 3. The standing test

`tests/Feature/Ui/DeliveredScreenReachabilityTest` — 5 cases, 329 assertions, ~5 s.

* subject set **computed** from the booted route table: named `GET`, action in
  `App\Http\Controllers\` but not `…\Api\`, middleware includes `web`, and the action **method's own
  source** renders a view (`view(…)` or a DataTable `->render('x.y', …)`). **307 screens across 99
  features.**
* two assertions, one per question in §1.3, **each asserted in both directions**: an undeclared
  flagged subject fails, and a declared subject that is no longer flagged *also* fails, so closing
  a debt requires deleting its line.
* a bounded allowance: **6 unnamed screens** and **6 self-contained features**, every entry marked
  `DELIBERATE` with its design reason or `OPEN` with the gap and the workstream that owns it.
* three premise cases: the corpus is really read (floors far below the measured 2,651 files / 307
  screens / 99 features), the stripper agrees exactly with `Tests\Support\StripsSourceComments` on
  a real PHP file and a real Blade file, and it strips a comment that names a finding while leaving
  real markup intact.

**Why `->render('x.y')` is in the detector**, because leaving it out cost the two biggest screens in
the product: `order.index` and `product.index` were both absent from the subject set, since
`OrderController::index()` ends `return $dataTable->render('order.index', …)` — the yajra pattern
every WorkDo list screen uses — and the word `view` never appears. A census that silently omitted
the order list and the product list reads exactly like one that looked and found nothing.

### Mutations — 8 against this file, all caught

| mutation | result |
|---|---|
| drop the packing-list link from `order/view.blade.php` | CAUGHT |
| let `app/Http/Middleware/` count as an offer | CAUGHT — `DemoModeMiddleware` names `'stores.edit'` |
| stop stripping comments | CAUGHT — `StoreController`'s own sentence names `stores.edit` |
| count a URI literal in `app/` as an offer | CAUGHT **by the naming question only** |
| the same, plus letting middleware offer | CAUGHT by the self-containment question |
| treat a controller's own file as outside its feature | CAUGHT |
| drop the `->render('x.y')` detector | CAUGHT by the premise |
| drop the URL-shape rule on path literals | CAUGHT |

The fourth row is worth reading twice: that mutation **survived** the self-containment question and
was caught only by the naming question. The `app/` exclusion is a live control, but only one of the
two lenses exercises it — the kind of thing that reads as "untested" if nobody writes down which
lens caught it.

---

## 4. `P04-P9-01` — the journeys

### 4.1 What the plan asks for, verbatim

`docs/development/phase-4/PHASE-04-EXECUTION-PLAN.md` §6:

> ### P4-9 — End-to-end journeys A–F, integrated verification, closure
> Built on the measured journey in §2.1; every journey asserts on state, never on HTTP 200 alone.
> Then full gates, CI on the Draft PR, and `PHASE-04-FINAL-REPORT.md`.

`MASTER-PLAN-REQUIREMENT-TRACEABILITY.md`, `### P04-P9-01`:

> **Requirement (original wording / faithful reference):** P4-9: 'End-to-end journeys A–F,
> integrated verification, closure ... every journey asserts on state, never on HTTP 200 alone.'
>
> **Source:** `docs/development/phase-4/PHASE-04-EXECUTION-PLAN.md §6 P4-9; the journeys themselves
> are defined only in the owner directive, which is absent`
>
> **Actual behaviour:** NO JOURNEY ARTEFACT EXISTS AND THE CLOSURE REPORT NEVER CLAIMS ONE. A
> repo-wide grep for 'Journey [A-F]' in docs/ and tests/ returns exactly one hit — the plan's own
> reference to 'Journey C in the directive'. […] Journey C (concurrent-checkout oversell) is the
> only one with engine-level coverage […]
>
> **Gap:** Five of six journeys have no evidence and, worse, no recoverable definition. The phase
> closed without ever restating what A, B, D, E and F were.

The plan's §2.1 and §2.3, which P4-9 says the journeys are "built on":

> ### 2.1 The purchase journey completes
> `product-list` → product page → `POST /product_cart` (`{"status":1,"message":"successfull"}`) →
> `/cart` showing `Hand-loomed Table Runner $65.00`, subtotal `$65.00` → `/checkout` → address and
> billing → COD → `POST /process-order` (302) → `/order-complete` with the order reference.
>
> **The form posts to `process-order`, not to the `place-order` route** declared at
> `routes/customer.php:161`. Both exist; the storefront uses the former. Any harness that drives
> `place-order` is not driving what the storefront drives.

> **Journey C in the directive is therefore already covered at the engine level.** P4-7 extends it
> to the paths that harness does not drive, rather than rebuilding it.

The recovered plan's own closing line for Phase 04
(`docs/development/master-plan/MASTER-PRODUCT-PLAN-v1.0-WITH-AMENDMENTS.md`, § `مخرجات Phase 04`):

> محركات تشغيل تجاري متكاملة مع اختبارات للعمليات المالية والمخزنية المتزامنة، والتسويات،
> والمرتجعات، وتكامل القنوات.

(*integrated commercial operating engines, with tests for concurrent financial and inventory
operations, settlements, returns, and channel integration*)

### 4.2 What was built, and what A–F are not

**The lettered list is not recoverable and is not invented here.** The owner directive that named
A–F is not in the repository; `CLAUDE.md` §4.1 forbids silently reducing the approved scope, and
the converse — putting a fabricated scope under a real requirement id — is worse. Journey C keeps
its letter because the plan names it outright.

`tests/Feature/Journeys/CommerceJourneyTest` — **9 cases, 165 assertions, ~5 s.** Every step is an
HTTP request to a registered route, in the order a principal makes them, carrying the application's
own artefacts between them. Every journey's final assertion is database state.

| journey | plan text it is built from | what it asserts |
|---|---|---|
| **1 — a guest buys something** | §2.1 verbatim | catalogue 200 → cart endpoint `{"status":1}` → basket page 200 → checkout 200 → `process-order` **302 to `{slug}/order-complete`** (the landed path, not the status) → one `orders` row with this store, `is_guest=1`, `cod`, the basket total, a reference, one line naming the product and quantity; the attached `customers` row belongs to **this** store (the 2026-11-07 cross-tenant safeguard); one `order_billing_details` row; stock −1; the basket consumed |
| **2 — a signed-in shopper buys something** | 04-E `الحساب النهائي على الخادم وحفظ تفاصيل السعر الأصلي في الطلب` | `asAShopper()` with the default guard back on `web` and `auth()->user()` asserted null; the signed-in basket is keyed on the customer, not a cookie; `is_guest=0`; the order's `customer_id` is the **principal's**, never request input; the server total equals the basket total; the line carries its own price |
| **3 — the merchant fulfils the order** | 04-C `الفصل بين حالة الطلب والدفع والتجهيز والشحن` and `Picking وPacking والباركود والملصقات والفواتير` | order list 200 → detail 200 showing the reference → four transitions over `order-status-change/{id}` (`confirmed`→4, `pickedup`→5, `shipped`→6, `delivered`→1), each asserted on the raw column **and** on the writer's own `status` field → **`payment_status` unchanged** throughout (the owner's Option A) → the invoice, the packing list and the shipping label all render, **and the order screen offers all three links** |
| **C — the last unit cannot be sold twice** | §2.3, the path the MySQL harness does not drive | two guests, one unit, `stock_order_status = 'not_allow'`: one `orders` row, stock 0 and never negative, and **the refused shopper's basket survives** so a restock can still be bought |
| **(04-B, by default)** | 04-B `منع البيع فوق المتاح افتراضياً` | with the column left NULL — the state every product the shipped Flutter app creates is in (`GAP-182`) — **the last unit sells twice** and stock goes to **−1**. The guard works; the *default* does not |
| **(defect pin)** | — | `process-order` with no `billing_info` answers **HTTP 500**, and no order row is written |
| **(defect pin)** | — | a sale that empties a product's stock answers **HTTP 500**, writes **no order**, and leaves the stock **decremented**. See §4.4 |
| 2 premise cases | — | none of the four routes these journeys drive carries a plan/module gate (and a route that *does* carry one is found, so the assertion cannot be vacuous); the guest order route still carries no authentication |

### 4.3 `GAP-345` is settled, and it was the harness — in two places

The recorded state: *"the POST answers 302 to `/` with the session error `Cart is empty.` […] IT IS
NOT ESTABLISHED WHETHER THAT IS THE HARNESS OR THE PRODUCT"*, with three fixture hypotheses tested
and refuted. Both remaining causes are harness-side:

1. **A fourth cookie hypothesis nobody had tested: the `CookieValuePrefix`.** The old fixture
   passed `withCookie('cart', encrypt($value, false))`. But
   `MakesHttpRequests::prepareCookiesForRequest()` encrypts every `withCookie()` value *itself*,
   and prepends `CookieValuePrefix::create($key, $encrypter->getKey())` first; `EncryptCookies`
   validates that prefix and **discards** a cookie without it. The old fixture therefore produced a
   doubly-encrypted, unprefixed value the middleware threw away — indistinguishable from an empty
   basket. `withCookie('cart', $plain)` is correct. (Hypothesis 3, "`serialise=false` changes the
   outcome", was right about Laravel's encrypter and irrelevant to the test client.)
2. **The storefront posts `billing_info[...]`, a nested array.**
   `front_end/common/checkout/checkout_form.blade.php` opens its form on
   `['payment.process', $store->slug]` and names every address field `billing_info[firstname]`,
   `billing_info[billing_address]`, `billing_info[delivery_city]`. Without that key,
   `PaymentController::handleCustomerRegistration()` calls `\Validator::make(null, …)` — a
   TypeError, HTTP 500.

With both corrected the journey completes. `GAP-345`'s own next step — *"drive the basket the way
the storefront does — through the cart endpoints, so the cookie is minted by the application rather
than by a fixture"* — is what `aBasketMintedByTheApplication()` does, and it is kept even though
the fixture cookie now also works, because it is the only version that proves the cart endpoint and
the order endpoint agree about what a basket is.

Two artefacts were corrected rather than left stale: `GuestCheckoutJourneyTest::asGuest()` (a
helper that provably could not work) and `tests/Support/Security/bin/probe-guest-checkout-journey.php`
(which claimed the step was unreproduced).

### 4.4 Product defects found by driving the journeys

**(a) A sale that empties a product's stock is an HTTP 500 that eats the stock.** Found by running
journey C against an ordinary new store, not by reading.

`PaymentService::getProductStatus()` — the COD and bank-transfer order writer — builds its settings
as `Setting::where('store_id', $store->id)->pluck('value','name')->toArray()`, a **raw read of the
`settings` table, not `Utility::Setting()`**, so no defaults are merged. Its stock block then reads
`$settings['notification']` with no `??`, inside
`if ($Product->product_stock <= $Product->low_stock_threshold)`. The threshold column ships
`DEFAULT 0`, so the branch is taken the moment stock reaches zero, and `Undefined array key
"notification"` is thrown.

The decrement has already happened — `decrementStockOrFail()` runs before `DB::beginTransaction()`,
and the `catch` that calls `restoreStockDecrements()` belongs to *that* try. Measured end state:

> HTTP 500 · **0 `orders` rows** · `products.product_stock` = **0** · the basket still present

The merchant's stock says the unit is gone, no order says where it went, and the buyer sees an
error page. **Scope:** any store with `stock_management = on` and no `notification` settings row, on
the first sale that takes a product to or below its low-stock threshold — for an unconfigured
product, every sale that empties it.

**Not fixed here.** `PaymentService` is the order and money writer, and `CLAUDE.md` §2 reserves
"material changes to prices/payments/stock/business rules" for the owner. The one-character repair
(`?? '[]'`) is missing at **36 sites across three files**, counted over comment-stripped source:
`PaymentService` (12), `Api/ApiController` (12), `PayUPaymentController` (12). Only
`PaymentService`'s are **measured** as live; `ApiController`'s nearest `$settings` assignment before
its three stock sites is `Utility::Setting($storeId)`, which *does* merge the
`'notification' => '[]'` default, so those are stated as **not measured** rather than claimed safe.
`PayUPaymentController` was not measured at all.

**(b) `process-order` answers 500 to a POST with no `billing_info`**, rather than refusing it. Low
severity — a real storefront always posts the key — but a 500 is not a refusal, and the next person
to drive this route will hit it. Pinned, not fixed, for the same reason as (a).

**(c) 04-B's `افتراضياً` is unsatisfied** — already `GAP-182`/`INV-006`, now driven over HTTP to a
second `orders` row rather than measured against the reader. `ProductStockService::mustNotOversell()`
is `=== 'not_allow'`: one exact word refuses, everything else — NULL included — permits. The
shipped Flutter admin app writes the column nowhere, so every product it has created is in the
permissive state.

### 4.5 What was not built, and why

| journey | why not |
|---|---|
| the lettered A, B, D, E, F | **no recoverable definition.** The owner directive is not in the repository. Reconstructing five letters from the 04-A…04-G decision groups would be a guess wearing a requirement id. The resupply ask in `P04-P9-01`'s "Next action" still stands |
| 04-F returns and exchange, end to end | the refund flow's own fixtures (`OrderRefund`, `order_refunds`, the entitlement ledger) are a larger surface than this workstream could drive and falsify in the time. `RET-002`'s inspection control is measured **reachable** in §2.1, and `tests/Feature/Commerce/CancellationLedgerSemanticsTest` already covers the ledger half |
| 04-D COD statement reconciliation | needs a COD statement import fixture; none exists |
| 04-G channel integration | the channel modules do not load with `add_on_managers` empty, so a journey would need the row and a module premise test. Out of scope for this slice and stated rather than skipped |
| concurrency for journey C | a second PHPUnit process in one worktree is forbidden (`GAP-314`), and the real concurrency evidence already exists: `tests/Support/Security/bin/verify-stock-oversell-concurrency-on-mysql.php`, 14 checks, which the plan §2.3 reports passing. This workstream drives the **HTTP** path that harness does not |

### Mutations — 6 against the journeys, all caught

| mutation | result |
|---|---|
| journey 1's cookie back to `encrypt($cart, false)` (the `GAP-345` shape) | CAUGHT |
| journey 1 posts flat address fields instead of `billing_info[...]` | CAUGHT |
| `stock_management` set to `off` in the fixture | CAUGHT by journey 1's stock assertion |
| journey C's product left with no oversell choice | CAUGHT |
| `$settings['notification']` guarded with `?? '[]'` at `PaymentService` | CAUGHT by the 500 pin |
| the packing-list link removed from `order/view.blade.php` | CAUGHT by journey 3 |

---

## 5. What this change touches

| file | change |
|---|---|
| `tests/Feature/Ui/DeliveredScreenReachabilityTest.php` | new — the standing census |
| `tests/Feature/Journeys/CommerceJourneyTest.php` | new — the journeys |
| `resources/views/order/view.blade.php` | the packing-list link (ORD-008's missing half) |
| `tests/Feature/Commerce/GuestCheckoutJourneyTest.php` | `asGuest()` corrected; the `CookieValuePrefix` finding recorded |
| `tests/Support/Security/bin/probe-guest-checkout-journey.php` | rewritten: `GAP-345` resolved, both causes recorded |
| this document | new |

**Nothing was added to `.github/known-test-outcomes.json`** and no test was weakened. No navigation
entry was added for any `platform/*` screen — that is another workstream's.

---

## 6. Test runs, and three red tests this branch did not cause

| run | result |
|---|---|
| `--testsuite=Unit` | **7,179 passed, 1 skipped, 0 failed** (70,597 assertions, 330 s) |
| `tests/Feature/{Ui,Journeys,Kyc,Merchant}` | **1,338 passed, 2 failed** (13,638 assertions, 580 s) |
| `tests/Feature/Commerce` | **1,696 passed, 1 failed** (22,719 assertions) |
| `tests/Feature/Journeys/CommerceJourneyTest` alone | 9 passed, 165 assertions |
| `tests/Feature/Ui/DeliveredScreenReachabilityTest` alone | 5 passed, 329 assertions |
| mutations | **15 applied, 15 caught** (`tests/Support/bin/mutate.py`; §3 and §4.5) |

**The three failures are pre-existing on `int/checkpoint` and were proved so**, by restoring
`resources/views/order/view.blade.php` from the base commit and re-running each: all three still
fail. Nothing was added to `.github/known-test-outcomes.json` — they belong to the workstreams whose
censuses moved, and each names its own remedy in its failure message.

| test | message | shape |
|---|---|---|
| `Merchant\OrderActionPermissionCensusTest::test_the_census_covers_every_order_route` | `POST order-return-inspection` is "neither censused above nor listed as guarded elsewhere" | RET-002's inspection route was added without extending this census. The route itself is **reachable and gated** (§2.1) |
| `Ui\HostUtilityCensusPopulationTest::test_the_old_population_still_reproduces_the_registers_own_figure` | `Failed asserting that 26 is identical to 19` | a census population that grew as routes were added. Its own docblock says "if this number moved, the old population is not the one the register describes" |
| `Commerce\HistoricalOrderImportSalesExclusionTest::test_the_metric_layer_is_not_the_only_place_order_money_is_summed` | `HomeController.php:2123/:2138` is now `:2185/:2200` | exactly the `file:line` inventory drift `CLAUDE.md` §6b exists to warn about: an insertion 62 lines above moved two anchors |
