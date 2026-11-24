# Phase 05 — MKT and ANA decision IDs, measured against the code

**Measured 2026-11-17.** Eighteen of the 181 recovered decision IDs — `MKT-001`…`MKT-004`,
`MKT-007`…`MKT-010` and all ten `ANA-*` — had never been measured against this codebase.
`MKT-005` and `MKT-006` are not here because they were measured directly first and are
recorded in `PHASE-05-MKT-006-REWARD-REVERSAL.md`.

## How this was produced, and how far it may be trusted

Each id got one measuring pass over the code, then **two adversarial passes** with opposed
briefs: one hunting an implementation the measurer had missed across the ~70 vendored modules,
one hunting reasons a claimed implementation does not actually work or is not routed. 54 passes,
0 errors. **17 of the 18 verdicts were challenged by at least one lens** — only `MKT-010` went
unchallenged — which is the useful part: a measurement nobody argued with has not been tested.

**THIS IS A SURVEY, NOT A CERTIFICATION, AND IT IS MACHINE-PRODUCED.** Every status below is a
reading of the code by an automated pass, not an owner acceptance and not a human review. The
claims that CHANGED something — a matrix row, or a line of code — were re-verified by hand
before being acted on, and those are named in §2. Everything else is recorded as what the pass
reported, with its file-and-line citations so it can be checked rather than believed.

No status here is promoted to VERIFIED COMPLETE in the requirement matrix on the strength of
this document alone, with the single exception argued in §2.

## 1. The eighteen, at a glance

| id | requirement | measured | lens 1 — missed implementation | lens 2 — does it work |
|---|---|---|---|---|
| `MKT-001` | An independent marketing section, linked to analytics | **PARTIAL** | **challenged**, label stands | verdict stands |
| `MKT-002` | Simple by default, advanced when needed | **PARTIAL** | **challenged**, label stands | verdict stands |
| `MKT-003` | Coupons, automatic discounts and BOGO | **PARTIAL** | **challenged**, label stands | **challenged**, label stands |
| `MKT-004` | Static and dynamic customer segments | **PARTIAL** | **challenged**, label stands | **challenged**, label qualified |
| `MKT-007` | Recover carts and incomplete payments, without duplication | **PARTIAL** | **challenged**, label stands | **challenged**, label stands |
| `MKT-008` | Respect customer eligibility and consent for messages | **PARTIAL** | verdict stands | **challenged**, label stands |
| `MKT-009` | Lynomia integration when ready, without duplication | **PARTIAL** | **challenged**, label stands | **challenged**, label stands |
| `MKT-010` | In-store conversion tools, linked to the themes | **PARTIAL** | **challenged**, label stands | **challenged**, label stands |
| `ANA-001` | A centre for sales, finance, products, customers and marketing | **PARTIAL** | **challenged**, label stands | **challenged**, label stands |
| `ANA-002` | Comparisons, filters and saved periods | **PARTIAL** | verdict stands | **challenged**, label stands |
| `ANA-003` | Custom reports, export and scheduling, permission-gated | **PARTIAL** | **challenged**, label stands | **challenged**, label stands |
| `ANA-004` | Conversion, device and channel analysis | **PARTIAL** | **challenged**, label stands | **challenged**, label stands |
| `ANA-005` | Separate sales, collection, settlements and profitability | **PARTIAL** | verdict stands | **challenged**, label stands |
| `ANA-006` | Do NOT claim precise profitability when cost or fee data is missing | **PARTIAL** | verdict stands | **challenged**, label stands |
| `ANA-007` | Show data quality, estimates and omissions | **PARTIAL** | **challenged**, label stands | **challenged**, label stands |
| `ANA-008` | Document metric definitions and their sources | **PARTIAL** | verdict stands | **challenged**, label stands |
| `ANA-009` | Alerts and proactive analytics, with clear controls | **PARTIAL** | **challenged**, label stands | **challenged**, label stands |
| `ANA-010` | Do NOT build a new accounting engine | **COMPLETE** | verdict stands | verdict stands |

**Totals: 17 partially implemented, 1 verified complete, 0 not implemented.** Exact counts over the eighteen measured, not a sample and not a percentage.

Seventeen of eighteen landing on PARTIAL is itself worth stating plainly: these requirements
each name two or three things joined by "and", and in nearly every case the product has built
the first and not the rest. The per-id sections below say which half is missing, because
"partial" on its own tells an owner nothing actionable.

## 2. What was re-verified by hand, and what was not

Seven claims from this survey were checked against the code directly, because each one either
changed a row in the requirement matrix or justified a change to shipped code. Each is stated
with the file that settles it.

| # | claim | checked against | outcome |
|---|---|---|---|
| 1 | `products.sale_price` is `float NOT NULL DEFAULT 0`, so `sale_price ?? price` can never reach its second operand | `database/migrations/..._create_products_table.php:32` | **CONFIRMED**, and the consequence measured by test: a product sold at list price was valued at **0** instead of its list price on `/finance` |
| 2 | `product_variants.variation_price` is nullable, so the variant branch's identical `??` is correct | `..._create_product_variants_table.php:21` | **CONFIRMED** — the asymmetry is why only one of the two branches was repaired |
| 3 | `products.cost_price` is `decimal(15,2) NOT NULL DEFAULT 0.00`, so an unrecorded cost is indistinguishable from a free item | `app/Support/Database/GateFProductionSchema.php:395` | **CONFIRMED** — this is the mechanism behind `ANA-006` |
| 4 | `/finance` renders profit figures with no disclosure, and is the only surface in either repository that renders profit at all | `app/Http/Controllers/StoreFinanceController.php`, `resources/views/finance/index.blade.php:130-180` | **CONFIRMED** by reading both. Now disclosed — see §4 |
| 5 | The measuring pass had the direction of the `Potential Profit` error BACKWARDS; lens 2 was right | reproduced as a failing test before any fix | **LENS 2 CONFIRMED, MEASURER WRONG.** The figure was *understated* and could go negative, not overstated |
| 6 | The Lynomia integration is off by an engineering kill switch, so `MKT-004`'s dynamic half is not reachable as shipped | `packages/workdo/LynomiaChat/src/config/lynomia.php:29`, `.env.example:106`, `.env:106` | **CONFIRMED** — `env('LYNOMIA_INTEGRATION_ENABLED', false)` and both env files ship `false` |
| 7 | The metric layer exists, contradicting this project's own matrix row `P05-02` ("No metric layer exists") | `ls app/Support/Metrics/` | **CONFIRMED PRESENT: 20 classes.** The matrix row was stale and is corrected |

**Everything else below is machine-measured and has not been re-verified by hand.** It carries
file-and-line citations throughout so any single claim can be checked in seconds; it should be
checked before it is relied on for an acceptance decision.

### The one status promotion, and why it is defensible

`ANA-010` ("do NOT build a new accounting engine") is the only id measured **VERIFIED COMPLETE**,
and **both** lenses let it stand. It is a PROHIBITION, which inverts the burden of proof: the
claim is that something does not exist, and the lens hunting for a missed implementation is
exactly the right instrument for that — its job and the verdict's survival are the same test.
Lens 2 noted the inversion explicitly: for a prohibition, "unreachable" and "writes nothing" are
what compliance looks like, so to refute it it had to FIND a new accounting engine, and did not.

Even so this is recorded as **machine-verified, not accepted**: it moves no VERIFIED COMPLETE
count in the requirement matrix without a human reading, and it is listed in §5 as the one id
where a reviewer's time would be best spent confirming rather than re-deriving.

## 3. The eighteen, one by one

Each section gives the requirement as the register records it, what the measuring pass found,
what the two lenses did to that finding, and the citations. `(v)` marks a vendored module.

### `MKT-001` — An independent marketing section, linked to analytics

> قسم تسويق مستقل مرتبط بالتحليلات.

**Measured: PARTIALLY IMPLEMENTED** (confidence: probable)

**Finding.** An independent top-level Marketing section exists in the merchant admin with five working children (Coupon, Newsletter, Flash Sale, Wishlist, Abandon Cart), but it is not linked to analytics in any direction: no marketing page links to a report or the analytics page, no report or analytics surface covers marketing, and the one marketing metric class that exists is read only by tests.

**What is missing**

- THING 2 — 'linked to analytics' (مرتبط بالتحليلات) is not implemented in the marketing-to-analytics direction. grep for route('reports / analytic / chart across resources/views/{coupon,newsletter,flash-sale,wishlist,cart} returns ZERO matches: no page in the Marketing section links to, embeds or references any report or the analytics page.
- THING 2, reverse direction — the analytics side has no marketing domain. The 'reports' section's nine children are Customer, Order (x7) and Stock; the separate 'store-analytics' page is visitor/device/order traffic. Neither has a coupon, newsletter, flash-sale, campaign or abandoned-cart-recovery report. The companion requirement ANA-001 asks for an analytics centre covering marketing (…والتسويق); that marketing …
- No marketing performance figure is rendered anywhere a merchant can see. The coupon list has no redemption count (CouponDataTable: Name/Code/Discount/Limit/Expiry); newsletter/index, flash-sale/index, wishlist/index and cart/index contain no count, total, rate or chart; the merchant dashboard carries only an 'Add New Coupon' quick action (resources/views/dashboard.blade.php:300-303) and no marketing metric.
- The analytics page is unreachable from any view at all, marketing included: `grep -rn "theme_analytic" resources/views/ themes/` returns zero hits. The route name appears in exactly three non-view places — the menu entry (CompanyMenuListener.php:57), its declaration (routes/web.php:471) and a SidebarCustomization seeder row (:72). So the only path to analytics is the Dashboard menu entry.
- The marketing section has no landing or overview page of its own — the parent entry is `'route' => ''`, i.e. a collapsible sidebar container only. There is no marketing index/overview controller or view anywhere in app/ or resources/ (broad grep for marketing-analytic/report/dashboard/overview wiring across app/, resources/, routes/, config/ returned zero matches), so there is no surface on which marketing-linked …
- The existing marketing metric is not surfaced: app/Support/Metrics/CouponRedemption.php has no production consumer — only two test files reference it. The metric layer is built but not connected to the marketing section or to any report page.
- On the shipped ADMIN Flutter app (/home/user/StoreSyria_app98) there is no marketing section at all: `grep -rn "marketing" lib/` returns ZERO occurrences. lib/view/screen/ has coupon_screen and a separate statistics_screen, but no marketing grouping and no link between them.

**Cited as present**

- `app/Listeners/CompanyMenuListener.php:574-651` — THING 1 — the independent marketing section. A top-level menu entry `'name' => 'marketing', 'parent' => null, 'order' => 220, 'permission' => 'Manage Marketing'`, followed by five children all …
- `app/Classes/Menu.php:78-79` — Why I believe the section actually renders rather than just being declared: the only gate is `in_array($array['module'], $this->modules) && (empty($permission) || …
- `config/merchant-navigation.php:63` — The section is drawn as its own sidebar group under a 'Grow' zone heading, alongside 'reports': `'grow' => ['title' => 'Grow', 'order' => 30, 'items' => ['marketing', 'reports']]`. Rendered by …
- `routes/web.php` (n/a — measured via php artisan route:list --json) — All five marketing child routes resolve, so the section's pages are reachable and not dead code: `coupon.index` (CouponController@index), `newsletter.index` (NewsletterController@index), …
- `app/Http/Controllers/ThemeAnalyticController.php:9-103` — A real analytics surface DOES exist (visitor counts from shetabit_visits, device/browser/platform breakdowns, a store order chart), route `GET themean-alytic` name `theme_analytic`. But its menu …
- `app/Http/Controllers/ReportController.php` (3,835 lines; its menu is `app/Listeners/CompanyMenuListener.php:423-572`) — A separate top-level 'reports' section (parent null) with Customer Reports, Order Reports (Sales, Sales Product, Sales Category, Sales Downloadable, Sales Brand, Country, Order Status), Top Sales …
- `app/Support/Metrics/CouponRedemption.php` — A marketing-relevant analytics building block EXISTS from the Phase 05 metric layer — but it is UNWIRED. grep across app/, resources/ and routes/ finds zero consumers; the only references are …
- `app/DataTables/UserCouponDataTable.php:96-104` — The closest thing to marketing reporting that a merchant can actually open: coupon.show renders a per-coupon redemption LOG with columns Name, Order ID, Amount, Date. It is a row list, not an …
- `packages/workdo/Marketing/` (src/routes/web.php; src/app/Listeners/CompanyMenuListener.php) `(v)` — Despite its name this module is NOT a marketing section: it is a vCard / digital-business-card module (Store vCard with 21 card themes, Appointments, Contacts, galleries, testimonials, socials, …
- `packages/workdo/Campaigns/src/app/Http/Controllers/CampaignsController.php` (productAnalytics()) `(v)` — The one vendored surface literally named 'analytics' — route `campaigns-analytics/{id}` name `campaigns.analytics`, view campaigns::analytics. I read it: it builds `['label' => [$storeName], …
- `packages/workdo/LynomiaChat/src/app/Listeners/CompanyMenuListener.php:132-143` `(v)` — First-party LynomiaChat has a Campaigns surface with a results endpoint (lynomia.campaigns, lynomia.campaigns.api.results) and it IS registered in route:list because PackageServiceProvider loads it …

**Lens — missed-implementation: CHALLENGED** (confidence: certain)

The STATUS LABEL survives — PARTIALLY_IMPLEMENTED is right, and I found no marketing-analytics centre or marketing-to-analytics navigation link. But the verdict's substance is refuted in three named places, so its rationale must be rewritten; two of the three are things the measurer searched past.

Citations: `resources/views/reports/_sales_stats.blade.php:38-41`, `resources/views/reports/order_chart_data.blade.php:19`, `app/Http/Controllers/ReportController.php:526, 584, 652, 747, 796, 896, 938-941, 958, 977, 991`, `resources/views/reports/order_report.blade.php:160-162, 312, 320`, `app/Http/Controllers/ReportController.php:n/a — via php artisan route:list --json (1476 routes)`, `packages/workdo/LynomiaChat/src/app/Listeners/CompanyMenuListener.php:35-46 (section), 49-61, 132-143` and 10 more.

**Lens — does-it-actually-work: verdict stands** (confidence: certain)

VERDICT SURVIVES. I attacked six seams and the section held on all six; the three defects I did find all push in the SAME direction as the verdict (the marketing side has no working analytics), so the status is unchanged at PARTIALLY_IMPLEMENTED.

Citations: `app/Listeners/CompanyMenuListener.php:17, 574-651`, `app/Classes/Menu.php:74-78`, `app/Helper/helper.php:2719-2721 (sideMenuHref 'route' case)`, `app/Http/Controllers/ThemeAnalyticController.php:18-66`, `resources/views/coupon/show.blade.php:21-38`, `app/DataTables/UserCouponDataTable.php:41-49` and 4 more.

---

### `MKT-002` — Simple by default, advanced when needed

> تجربة بسيطة افتراضياً ومتقدمة عند الحاجة.

**Measured: PARTIALLY IMPLEMENTED** (confidence: probable)

**Finding.** The project owns a proven first-party "Advanced options" disclosure component, but it is wired into exactly two views — both the PRODUCT editor — and ZERO of the five Marketing-menu surfaces; the coupon form is still 16-24 controls in one flat grid, which is the exact condition that component's own docblock says it was built to fix.

**What is missing**

- "Advanced when needed" on the marketing surfaces — ABSENT, and this is the decisive measurement. Zero of the five Marketing-menu surfaces (Coupon, Newsletter, Flash Sale, Wishlist, Abandon Cart) uses the project's own x-ss.advanced-fields component, or any <details>, data-ss-disclose, Bootstrap collapse, accordion or tab-pane. I enumerated every native <details> in resources/ and packages/: the complete list is the …
- "Simple by default" on the coupon form — ABSENT. Coupon create is 16 controls in one undifferentiated grid and coupon edit 24, which is verbatim the condition the advanced-fields docblock says it was written to fix on the product editor ("thirteen controls in one undifferentiated grid, so the six a merchant sets once for the whole catalogue ... sat with exactly the same weight as the five they set on every single …
- An advanced coupon option that is persisted but UNREACHABLE when needed: coupon_limit_x_item ("Limit usage to X items") carries the .format class in create.blade.php and edit.blade.php, so it is hidden unless coupon_type == 'fixed product discount' — yet CouponController.php:114 (store) and :426 (update) assign it UNCONDITIONALLY, outside the `if ($request->coupon_type == 'fixed product discount')` block that closes …
- A newsletter authoring form of any tier, simple or advanced: NewsletterController@create (app/Http/Controllers/NewsletterController.php:34-37) is an empty stub whose body is `//`, and there is no newsletter/create view — resources/views/newsletter/ holds only index.blade.php and action.blade.php. The route newsletter/create resolves and renders nothing. Newsletter is a captured-subscriber list, not a composer.
- Any advanced tier for abandoned-cart and wishlist recovery — the two surfaces that are genuinely simple by default. Recovery is a single click with no options at all, so a merchant who needs to change the message, pick a template, set a delay, cap the send rate or inspect consent has nowhere to go.
- Any traceability row for MKT-002. It appears in exactly ONE file repo-wide — docs/development/master-plan/DECISION-ID-REGISTER.md:129 — and in NO row of docs/development/MASTER-PLAN-REQUIREMENT-TRACEABILITY.md and no entry in MASTER-PLAN-GAP-REGISTER.md (its MKT siblings MKT-005 and MKT-006 both have rows). So this requirement is currently untracked, which is a CLAUDE.md §4.1 reporting gap independent of the …
- A written acceptance criterion for MKT-002 anywhere: no docs/design-system/ or docs/development/phase-5/ file commits to progressive disclosure on the marketing surfaces. The only ledger row for the pattern is P2-03-8, scoped to 'Products experience' (PHASE-02-TASK-LEDGER.md:243), and even its Flutter parity gap G6 is about the product editor.

**Cited as present**

- `resources/views/components/ss/advanced-fields.blade.php:1-126` — THE MECHANISM, and it is real: a 126-line native <details>/<summary> progressive-disclosure component (Phase 02 slice P2-03-8). Deliberately native so a CLOSED disclosure STILL SUBMITS its controls, …
- `public/css/store-syria-ds.css:4916-5022 (plus 1867, 4282)` — The design-system styling for that disclosure (.ss-advanced, __summary, __chevron with an RTL 180deg rotation, __badge, __hint, __body, a forced-colors block, and a prefers-reduced-motion opt-out). …
- `resources/views/product/create.blade.php:96-166` — USE #1 OF 2, and it is a PRODUCT surface, not a marketing one: <x-ss.advanced-fields :title="__('Advanced options')" :hint="__('Taxes, brand, label, shipping and weight...')" …
- `resources/views/product/edit.blade.php:148-196` — USE #2 OF 2 — the same component, same six fields, on the product EDIT form. A repo-wide grep for 'x-ss.advanced-fields' / 'ss-advanced' returns only these two views plus the component itself, its …
- `tests/Feature/Merchant/AdvancedFieldsDisclosureTest.php:12-283` — The disclosure is PINNED, which is why I believe the mechanism works rather than merely exists: it asserts the six fields sit inside the <details> by CONTROL SHAPE (name="tax_id[]", …
- `app/Listeners/CompanyMenuListener.php:574-650` — THE MARKETING SECTION EXISTS, so there is a real surface for MKT-002 to be judged against: a top-level 'Marketing' menu node (name 'marketing', icon confetti, order 220, permission 'Manage …
- `resources/views/coupon/create.blade.php:1-224 (the .format script at 169-176)` — THE COUNTER-EVIDENCE, measured: 16 controls in ONE flat grid with no <details>, no data-ss-disclose, no collapse, no accordion, no tabs (grep count: 0). Minimum spend, Maximum spend, Usage limit per …
- `resources/views/coupon/edit.blade.php:1-189 (script 138-148)` — Same flat shape on the edit path: 24 control matches, the identical .format script, zero disclosure hits.
- `resources/views/flash-sale/create.blade.php:1-274` — Eight flat fields (Name, Start/End Date, Start/End Time, Discount Type, Discount Amount) plus an always-visible condition repeater headed 'Apply this Campaign when these conditions are matched'. Zero …
- `resources/views/cart/action.blade.php:47-71` — Abandon Cart recovery is SIMPLE to a fault and has no advanced tier at all: two one-click row buttons, 'Send Mail' (.bandoncart -> carts.emailsend) and 'Send Message' (.bandoncartmess -> …
- `packages/workdo/LynomiaChat/src/resources/views/merchant/campaigns.blade.php:68-112` — The only campaign composer that is both routed and graded: a 6-control modal (Name, Send from, Template, Audience, Message, Send at) where #lyncp-template-block carries d-none and is revealed only …
- `packages/workdo/Campaigns/src/resources/views/create.blade.php:1-353` `(v)` — The vendored campaign form, measured so the absence claim is credible: a flat 8-field form (Name, Store, Product, Start Date, End Date, Total Days, Total Cost, Payment Method) with ZERO <details>, …

**Lens — missed-implementation: CHALLENGED** (confidence: probable)

REFUTED IN ITS DECISIVE ELEMENT, THOUGH THE LABEL SURVIVES. The verdict surveyed ONE of this product's TWO marketing sections. Its "decisive measurement" — "ZERO of the five Marketing-menu surfaces" — is true of the WorkDo `marketing` node, and I re-measured it with a WIDER grep set than theirs and it holds. But the sidebar carries a SECOND, top-level marketing node that the verdict never mentions, and the simple/advanced two-tier design is that section's central shape.

Citations: `packages/workdo/LynomiaChat/src/app/Listeners/CompanyMenuListener.php:35-140`, `app/Providers/PackageServiceProvider.php:16-31`, `packages/workdo/LynomiaChat/src/resources/views/merchant/automation.blade.php:72-78, 137-157, 454-524`, `tests/Feature/Lynomia/LynomiaFlowBuilderTest.php:460-475, 519-529`, `tests/Browser/p4f-templates-history.mjs:1-36`, `packages/workdo/LynomiaChat/src/resources/views/merchant/commerce-automation.blade.php:23-25, 55-70, 228-253` and 7 more.

**Lens — does-it-actually-work: verdict stands** (confidence: probable)

VERDICT STANDS. I attacked the "it works" half six ways and it survived every one; I then found a reachability defect that makes the verdict's MISSING half worse, not its EXISTS half wrong. Status unchanged.

Citations: `resources/views/components/ss/advanced-fields.blade.php:125`, `public/js/store-syria-ds.js:691-746`, `resources/views/product/create.blade.php:78, 96-166, 1157, 1240`, `resources/views/product/edit.blade.php:148-196, 1459, 1533`, `app/Classes/Menu.php:44-64, 76-80`, `app/Helper/helper.php:49-61, 88-117` and 7 more.

---

### `MKT-003` — Coupons, automatic discounts and BOGO

> كوبونات وخصومات تلقائية وBOGO.

**Measured: PARTIALLY IMPLEMENTED** (confidence: probable)

**Finding.** All three named things exist and all three reach the charged price, but BOGO is a vendored WorkDo module that applies to no variant product at all and has no menu entry, and every automatic-discount time window is evaluated in a hardcoded Asia/Kolkata timezone rather than the store's.

**What is missing**

- BOGO does not apply to variant products AT ALL. Both of the only two call sites that invoke the rule engine gate on `empty($cart_value->variant_id) && $cart_value->variant_id == 0` — app/Models/Cart.php:594 and app/Http/Controllers/Api/ApiController.php:853. So a buy_x_get_x or buy_x_get_y rule (and every simple_pricing/bulk_pricing automatic rule) is silently skipped for any cart line that has a variant, on both …
- The automatic-discount time window is evaluated in a HARDCODED Asia/Kolkata timezone, not the store's. `date_default_timezone_set('Asia/Kolkata')` precedes every flash-sale window comparison: app/Models/FlashSale.php:52, app/Models/Product.php:208, :282, :336, :522, app/Models/ProductVariant.php:46. The start_date/start_time/end_date/end_time a merchant types are therefore parsed and compared against IST. For a …
- BOGO has no admin menu entry anywhere. `grep -i 'productpricing\|product_pricing\|Product Pricing'` over app/Listeners/ and packages/workdo/SidebarCustomization/src/database/seeders/ returns zero hits, and ProductPricingServiceProvider.php registers no menu listener (its EventServiceProvider $listen is empty). The only way in is the App Settings page, as a tab pushed by ViewComposer. Coupons and flash sales both …
- Two of BOGO's routed endpoints reference views that do not exist. ProductPricingController@index returns view('product-pricing::index') (:23) and @show returns view('product-pricing::show') (:150), but src/resources/views/ contains only the admin/ and marketplace/ directories — no index.blade.php, no show.blade.php (confirmed by ls). GET /productpricing and GET /productpricing/{id} would throw 'View not found'.
- BOGO and automatic discounts have no API surface, so the shipped admin Flutter app cannot manage them. php artisan route:list shows five coupon endpoints under api/admin (addcoupon, couponlist, updatecoupon, deletecoupon, view-used-coupon) and NO flash-sale, pricing-rule or BOGO endpoint. The ProductPricing module's own src/routes/api.php holds only a stub — `Route::middleware('auth:api')->get('/productpricing', fn …
- No cart-subtotal (spend-threshold) automatic discount. ProductPricingRule::$condition_types (:81-92) offers cart_total_quantity, cart_item_count, cart_items_products, cart_item_quantity_products, customer, guest, is_logged_in and date_and_time — all quantity-, product- or identity-based. There is no 'cart total AMOUNT >= N' condition, so the common 'spend 100, get 10% off the order' automatic discount cannot be …

**Cited as present**

- `app/Models/Coupon.php:1-170` — Coupon model: findByExactCode() (portable byte-exact code match), applyUsageLimit() (treats 0/null coupon_limit as unlimited), usesByCustomer() (per-user cap counted by user_id AND billing e-mail, …
- `app/Http/Controllers/CouponController.php:48-500` — Merchant coupon CRUD + CSV export. Operator-first authorization (strtolower type === 'super admin' before isAbleTo('Manage Coupon')) at :189-193; per-action gates isAbleTo('Create|Edit|Delete …
- `app/Services/Commerce/CouponBasketAllocator.php:1-132` — Per-basket coupon allocation. 'flat' is the only type with a basket-wide allowance (isFlat :89, flatDiscount :109-124); percentage is deliberately not re-multiplied. Replaces a per-line subtraction …
- `app/Models/UserCoupon.php` — Coupon redemption ledger (user_id, coupon_id, amount, order_id, date_used) — the row the per-user cap counts.
- `app/Models/OrderCouponDetail.php` — Per-order coupon snapshot: coupon_name, coupon_code, coupon_discount_type, coupon_discount_amount, coupon_final_amount.
- `app/Models/FlashSale.php:1-146` — AUTOMATIC DISCOUNT mechanism 1 (no code needed): scheduled start/end date + time, discount_type flat|percentage, discount_amount, is_active, sale_product (JSON product-id list). saleDiscount() …
- `app/Http/Controllers/FlashSaleController.php:18-640` — Flash-sale CRUD. scopedFlashSale() at :120-128 adds the store_id predicate that FlashSale lacks (the model is absent from config/tenancy.php scoped_models, documented at :18-25); operator-first gate …
- `app/Models/Product.php:205-560, 739-763` — Where the automatic discount reaches the PRICE CHARGED. ProductPrice() at :316 applies the flash sale (loop :336-...); getFinalPriceAttribute() at :512 applies it and falls back to the product's own …
- `app/Models/Cart.php:332-614` — The cart calls Product::ProductPrice() at 12 sites (332, 345, 366, 379, 425, 438, 487, 500, 512, 523, 535, 546), so the flash-sale price is the price totalled. The ProductPricing (incl. BOGO) rule …
- `packages/workdo/ProductPricing/src/app/Models/ProductPricingRule.php:54-60, 114-8820, 8810-8819` `(v)` — BOGO. $pricing_methods at :54-60 declares 'buy_x_get_x' => 'Buy x get x' and 'buy_x_get_y' => 'Buy x get y' alongside simple_pricing and bulk_pricing. productPricingApply() spans :114-8820 with …
- `packages/workdo/ProductPricing/src/resources/views/admin/fields.blade.php:456-566` `(v)` — The BOGO merchant form: @elseif ($pricing_method == 'buy_x_get_x' || 'buy_x_get_y') at :456 renders Buy Quantity and Get Quantity; Discount Type + Discount value at :470-478; a 'Products - Buy' …
- `packages/workdo/ProductPricing/src/app/Http/Controllers/ProductPricingController.php:62-130` `(v)` — BOGO persistence: :62-69 writes buy_quantity, get_quantity, discount_type, discount_value into field_json for both buy_x_get_x and buy_x_get_y; product_json (Buy list), product_get_json (Get list) …
- …and 6 further citations in the run record

**Lens — missed-implementation: CHALLENGED** (confidence: probable)

The STATUS label survives, but the verdict's substance does not: two of its six named absences are refuted by code, one of its own evidence citations points at a live defect that inverts its meaning, and four coupon/discount surfaces that reach the charged price are missing from its inventory.

Citations: `app/Http/Controllers/Api/ApiController.php:8842-8870`, `database/migrations/2023_12_25_093243_create_flash_sales_table.php:14-25`, `app/Http/Controllers/CartController.php:705-708`, `packages/workdo/FreeShippingPopup/src/app/Http/Controllers/FreeShippingPopupController.php:71-89`, `packages/workdo/WholesaleProduct/src/app/Models/WholesaleProduct.php:92-250`, `app/Models/Cart.php:301, 360, 594` and 8 more.

**Lens — does-it-actually-work: CHALLENGED** (confidence: certain)

REFUTED: the verdict's central positive claim — "all three named things exist and ALL THREE REACH THE CHARGED PRICE" — is false for automatic discounts. The automatic discount does reach the charged price, and when it fires it sets that price to 0.00 for the ordinary product. The verdict's only automatic-discount criticism was a timezone offset ("the discount fires, but at the wrong local moment"); the real defect is that it fires at the wrong AMOUNT, and the function it names as the site is the one that is wrong.

Citations: `app/Models/Product.php:321-327, 396, 399-400, 417`, `database/migrations/2023_12_26_092822_create_products_table.php:31-32`, `app/Http/Controllers/ProductController.php:631, 836, 1868, 2226`, `app/Models/Product.php:72, 556-562`, `app/Models/FlashSale.php:111, 113, 116-117`, `app/Models/Cart.php:512, 523, 535, 619, 716-718, 730` and 6 more.

---

### `MKT-004` — Static and dynamic customer segments

> شرائح عملاء ثابتة وديناميكية.

**Measured: PARTIALLY IMPLEMENTED** (confidence: probable)

**Finding.** Dynamic segments exist and are reachable (LynomiaChat Audiences — named, saved, condition-based, re-evaluated each use); static segments exist nowhere, and the one implementation explicitly refuses to store membership.

**What is missing**

- STATIC customer segments — absent entirely, and refused by design. No table exists for one: a search of every migration in the repo for Schema::create of 'segments', 'customer_segments', 'audiences' or 'customer_groups' returns nothing, and LynomiaChat's own 23 migrations create no audience table. The implementation states the refusal three times: AudienceApiController.php:18 'AN AUDIENCE IS A QUERY, NOT A LIST ... …
- The static-membership mechanism that upstream DOES accept is unreachable from Store Syria. CampaignApiController.php:221 and :384 validate audience.*.type as in:Label,Audience — a Label is hand-applied contact membership, i.e. the static half. But campaigns.blade.php:425-441 populates the picker only from state.audiences and chosenAudience() hard-codes [{type: 'Audience', id: id}], so a Label can never be chosen in …
- Customer segments over the Store Syria `customers` table — absent. The customer screens carry only a per-request, unstored single filter triple: CustomerController::CustomFilter() (:595), CustomFilterData() (:643), customer_grid_view() (:777), all routed through app/Support/Customers/CustomerListFilter.php, which takes one field/operator/value and narrows the query for that request only. CustomerController has no …
- Saving a customer filter for reuse — not possible. app/Services/TableFilterMemory.php:77 and :86 define VOCABULARY with exactly two screens, 'product' and 'order'. SavedView::sanitiseFilters() returns [] for any screen outside it, and SavedView.php documents that callers must treat an empty vocabulary as 'nothing may be saved for this screen'. Only OrderController.php:713 ever loads saved views (screen 'order'). So …
- Population fidelity (affects the dynamic half too): the segment is evaluated over LYNOMIA CONTACTS joined to this store's commerce events, not over the store's `customers` rows. references() returns unread_contacts — 'Contacts linked to a store whose orders Lynomia has not read yet ... their order conditions are UNKNOWN, never zero' (AudienceApiController.php:80-86). Commerce facts reach Lynomia asynchronously …
- Reach is conditional, not general. The only segment surface is gated by PlanModuleCheck:LynomiaChat, permission 'Manage Lynomia Chat', permission 'Manage Lynomia Audiences', and EnsureStoreOwnsLink:existing. A merchant without the LynomiaChat module in their plan, or without a provisioned/linked Lynomia workspace, has no segment feature at all — there is no first-party fallback.
- No vendored module supplies either half. All ~70 module directories were listed; the plausible names were opened and ruled out by reading them: Campaigns is advertising spend (Campaigns model fillable = name, user, store, product, start_date, end_date, total_days, total_cost, payment_method, status, approval; 2 migrations, no audience); Marketing is a vCard/business-card module (contact_infos, business_hours, …

**Cited as present**

- `packages/workdo/LynomiaChat/src/app/Http/Controllers/AudienceApiController.php:1-242` — DYNAMIC segment CRUD + preview. index/references/store/update/destroy/preview. A segment is a named condition set: validatePayload() accepts up to 10 conditions of {attribute_key, filter_operator, …
- `packages/workdo/LynomiaChat/src/app/Http/Controllers/AudienceController.php:1-40` — The Audiences page shell (view lynomia-chat::merchant.audiences). Deliberately renders nothing server-side; the browser fills it from the API controller above.
- `packages/workdo/LynomiaChat/src/resources/views/merchant/audiences.blade.php:13, 58, 86-98, 142-158, 177-205, 425-446` — The segment builder UI: name, AND/OR match mode ('A customer is in this audience when' every/any condition is true), add/remove condition rows, per-segment live count, edit, delete. Line 13 states …
- `packages/workdo/LynomiaChat/src/app/Services/AgentApiClient.php:537-613` — listAudiences/createAudience/updateAudience/deleteAudience/previewAudience/audienceFields. Persistence is upstream Lynomia 'custom_filters' with filter_type=contact — so the segment IS stored and …
- `packages/workdo/LynomiaChat/src/app/Listeners/CompanyMenuListener.php:106-117` — The menu entry that makes it reachable: 'Audiences' under parent 'lynomia' (Communication & Marketing), route lynomia.audiences, permission 'Manage Lynomia Audiences'.
- `packages/workdo/LynomiaChat/src/app/Http/Controllers/CampaignApiController.php:220-222, 242-243, 383-385, 405` — Segments are CONSUMED: a campaign carries audience[] of {type in Label|Audience, id} and previews with the same query the sender uses. This is why the dynamic half is a real feature and not a dead …
- `tests/Feature/Lynomia/LynomiaAudienceTest.php:44-290` — 15 Feature tests over the segment surface: full middleware stack present, anonymous refused, staff without 'Manage Lynomia Audiences' refused before any upstream call, unconnected store refused, …
- `app/Models/SavedView.php:1-130` — A stored, reusable, named filter set per (store_id, created_by, screen) — the closest thing in first-party code to a saved segment, and it CANNOT serve this requirement: its vocabulary comes from …

**Lens — missed-implementation: CHALLENGED** (confidence: certain)

REFUTED IN PART. The status label PARTIALLY_IMPLEMENTED is correct and I am not changing it — but three load-bearing absence claims in the verdict are false, and the measurer never opened the module that falsifies them.

Citations: `packages/workdo/ProductPricing/src/app/Models/ProductPricingRule.php:80-96, 272-279`, `packages/workdo/ProductPricing/src/resources/views/admin/conditionFields.blade.php:209-233, 236-252, 387-403`, `packages/workdo/ProductPricing/src/app/Http/Controllers/ProductPricingController.php:107-115, 130, 241-243`, `packages/workdo/ProductPricing/src/resources/views/admin/fields.blade.php:641`, `packages/workdo/ProductPricing/src/database/migrations/2024_05_29_065901_create_product_pricing_rules_table.php:14-30`, `packages/workdo/ProductPricing/src/routes/web.php:17-25` and 6 more.

**Lens — does-it-actually-work: CHALLENGED** (confidence: certain)

REFUTED, on reachability. The verdict's headline label (PARTIALLY_IMPLEMENTED) survives, but its load-bearing sub-finding — "DYNAMIC HALF IS REACHABLE, conditionally", with an enumerated condition list of (module in plan, provisioned+linked workspace, two permissions) — is wrong because it omits the one condition that actually blocks it: the module's ENGINEERING KILL SWITCH IS OFF, and the dynamic segment is the one feature in this requirement that cannot degrade gracefully, because none of it is stored on this side.

> *Status as the lens would word it:* PARTIALLY_IMPLEMENTED — label unchanged, but the dynamic half must be recorded as IMPLEMENTED_VERIFICATION_INCOMPLETE and NOT REACHABLE AS SHIPPED (engineering kill switch LYNOMIA_INTEGRATION_ENABLED=false by config default and in both committed env files; no store can hold a link, so every audience route redirects away or answers 503), not as "exists and is reachable, conditionally". Static half: NOT_IMPLEMENTED, as measured.

Citations: `packages/workdo/LynomiaChat/src/config/lynomia.php:20-35`, `.env.example:106, 110, 112`, `packages/workdo/LynomiaChat/src/app/Services/PlatformApiClient.php:59-68, 319-326, 651, 662-674`, `packages/workdo/LynomiaChat/src/app/Http/Middleware/EnsureStoreOwnsLink.php:76-79, 86-93`, `packages/workdo/LynomiaChat/src/app/Services/MerchantInboxGateway.php:44-57, 94-118`, `packages/workdo/LynomiaChat/src/app/Http/Controllers/Concerns/ProxiesToLynomia.php:42-61, 63-72` and 9 more.

---

### `MKT-007` — Recover carts and incomplete payments, without duplication

> استعادة السلات والمدفوعات غير المكتملة بلا تكرار.

**Measured: PARTIALLY IMPLEMENTED** (confidence: probable)

**Finding.** Abandoned-cart recovery exists as a manual, one-click-per-row merchant nudge and duplicate-ORDER prevention is genuinely implemented server-side on 7 gateway returns plus 3 checkout writers, but there is no automated recovery, no shopper-facing link back to the cart, no server-side duplicate-MESSAGE control (only an in-flight button disable), and the incomplete-payment surface is a review queue whose table has zero production writers and, by owner decision, does not exist in production.

**What is missing**

- NO AUTOMATED OR SCHEDULED CART RECOVERY. Recovery is one merchant click per cart row. There is no app/Jobs directory at all, and the only scheduled commands in the whole tree are zid:process-events (routes/console.php:9) and seven lynomia:* commands (packages/workdo/LynomiaChat/src/app/Providers/LynomiaChatServiceProvider.php:114-144) — none of which is a cart or payment nudge. grep for …
- NO SHOPPER-FACING WAY BACK TO THE CART. replaceVariables() (app/Models/Utility.php:2389-2403) defines exactly nine placeholders and NOT ONE is a cart or checkout URL, and all 18 locale bodies of the 'Abandon Cart' template (:718-746) use only {app_name} and {cart_table}. So the recovery email is prose plus a product table with no link. Worse, {order_url} (:2439) is built as /order/<the 6th argument>, and the …
- NO SERVER-SIDE DUPLICATE-MESSAGE CONTROL (the requirement's 'بلا تكرار' / 'without duplication' half that concerns messages). There is no send state anywhere to dedupe against: the carts table has no reminder/sent/recovered column across all three of its migrations (2024_01_18_103138, 2024_06_19_083502, 2024_11_22_052453); the list has no last-contacted column; no named rate limiter covers these routes …
- NO WORKING INCOMPLETE-PAYMENT RECOVERY. The capture→order record is complete code over a table that nothing in production writes: GatewayReference::claim()/claimState()/bind() have zero callers in app/ or packages/ (the service's own docblock says so; only tests call them), and the migration is dev/test-only by owner decision, so StoreFinanceController::unreconciledCaptures() is structurally always empty. The …
- NO RETRY OR RESUME PATH FOR A SHOPPER'S INCOMPLETE PAYMENT. php artisan route:list has no retry/resume/recover/pay-now/complete-payment route of any kind; routes/customer.php offers cart, checkout and order-track only. And a failed order write EMPTIES THE CART AND DOES NOT RESTORE IT — stated in the code at app/Http/Controllers/PaymentController.php:4005-4009 ('The cart rows deleted inside that loop are NOT …
- GUEST ABANDONED CARTS ARE NOT RECOVERABLE. $customer_id = $customer->id ?? 0 (CartController.php:235), so guest rows carry customer_id = 0, and the list groups by customer_id with whereNotNull (AbandonCartDataTable.php:96-97) — 0 is not null, so every guest cart in the store collapses into ONE row whose UserData relation (hasOne(Customer, 'id', 'customer_id'), app/Models/Cart.php:981) resolves to null, i.e. blank …
- A PREVIOUS AUTOMATED RECOVERY PATH WAS RETIRED AND NOT REPLACED. routes/web.php:311-320 and :373 record that POST /chatwoot/abandoned-carts/send and eight sibling Chatwoot routes, with their controllers, services, models and views, are retired; the replacement Lynomia integration exposes only the read feed above. So the automation that once existed is gone and the remaining flow is manual.

**Cited as present**

- `app/Http/Controllers/CartController.php:1151, 1191, 1227, 1241, 1341` — Merchant abandoned-cart recovery: abandon_carts_handled (list), abandon_carts_show (products modal), abandon_carts_destroy (clear), abandon_carts_emailsend (sends the 'Abandon Cart' email template), …
- `app/DataTables/AbandonCartDataTable.php:41-99` — The abandoned-cart list itself. Query is store-scoped and groups by customer_id (:95-97); columns are customer name, email, product count and action — there is NO last-contacted / reminder-sent …
- `app/Http/Controllers/WishlistController.php:160, 514` — Matching abandoned-WISHLIST email and WhatsApp nudges (abandon_wish_emailsend, abandonWishlistMsgSend), same shape as the cart pair.
- `app/Services/AbandonedMessageDispatch.php:68-110` — The WhatsApp hand-over seam for both nudges. blockedReason() checks mobile present, store WhatsApp credentials, and template existence/active — it contains no deduplication, no send log and no …
- `app/Models/Utility.php:614, 718-746, 2389-2403, 2456-2490` — The 'Abandon Cart' email template, seeded in 18 locales, plus replaceVariables() which renders it. The whole placeholder set is exactly nine names (app_name, order_id, order_status, app_url, …
- `app/Listeners/CompanyMenuListener.php:641-651` — The merchant menu entry: 'Abandon Cart' under Marketing, route abandon.carts.handled, permission 'Manage Cart'. This is how a merchant reaches the feature.
- `app/Http/Controllers/Concerns/GuardsGatewayReturn.php:102, 277-310, 343-371, 439` — NO DUPLICATE ORDER, and this one works: claimGatewayReturn() uses Cache::add() (atomic put-if-absent; CACHE_STORE=database in production) on two keys — the checkout nonce and a hash of the gateway's …
- `app/Http/Controllers/KhaltiPaymnetController.php:332` — One of seven live call sites of claimGatewayReturn(), taken above every write and above the Session::forget(). The others: CinetPayController.php:455, EasebuzzController.php:316, …
- `app/Http/Controllers/Concerns/ClaimsCheckoutIntake.php:29-72` — The second duplicate-order control: a 120-second Cache::add() claim fingerprinted on store + customer + cart rows, shared by the web process-order door and the two storefront API checkout writers. …
- `database/migrations/2026_10_30_000100_create_order_gateway_references_table.php:68-110` — The incomplete-payment record: order_gateway_references with UNIQUE (store_id, gateway, reference) so a replayed return or re-delivered webhook cannot create a second row, and a nullable order_id so …
- `app/Services/GatewayReference.php:100-120, 240, 286, 329` — claim()/claimState()/bind()/unreconciled()/available(). Its own docblock states that claim() and bind() have 'zero production callers today' — my grep over app/ and packages/ confirms it; only tests …
- `app/Http/Controllers/StoreFinanceController.php:636-737` — unreconciledCaptures(): the merchant review queue for collected money with no order. Permission-gated, refuses an unresolved store and an operator who has chosen no store rather than rendering an …
- …and 2 further citations in the run record

**Lens — missed-implementation: CHALLENGED** (confidence: certain)

The status LABEL survives, but two of the verdict's five "missing" bullets are refuted with file:line, and it missed an entire incomplete-payment subsystem — including a live, hardened, test-pinned shopper resume path that this very project built.

Citations: `app/Http/Controllers/Concerns/ResolvesPendingPartialPayment.php:1-120`, `app/Services/PaymentService.php:3054, 2520-2521`, `packages/workdo/PartialPayments/src/resources/views/theme/payment-view.blade.php:110-117`, `packages/workdo/PartialPayments/src/app/Console/Payment.php:38-85`, `packages/workdo/PartialPayments/src/app/Http/Controllers/PartialPaymentsController.php:138-200, 304, 408-435, 737-785`, `app/Http/Controllers/Customer/Auth/CustomerLoginController.php:805-813, 881` and 4 more.

**Lens — does-it-actually-work: CHALLENGED** (confidence: certain)

REFUTED (basis, not bucket). The verdict's one claimed-working RECOVERY mechanism — the merchant "Send Mail" nudge that "sends the 'Abandon Cart' email template" — does not produce a usable recovery message. The verdict spotted that CartController.php:1284 passes Crypt::encrypt($cart->product_id) in the $order slot but dismissed it because the template never uses {order_url}. That dismissal is wrong: the same mis-passed identifier hijacks {cart_table}, the ONE placeholder the template does use. Four defects, all read not inferred.

Citations: `app/Models/Utility.php:2515, 2536, 2389`, `app/Http/Controllers/CartController.php:1284, 1291`, `app/Models/Utility.php:2442-2443, 2458-2462, 2472`, `app/Models/Utility.php:2457, 2490-2499`, `app/Models/Utility.php:2391-2400, 2511, 721`, `app/Models/Order.php:11-13` and 3 more.

---

### `MKT-008` — Respect customer eligibility and consent for messages

> احترام أهلية وموافقة العميل على الرسائل.

**Measured: PARTIALLY IMPLEMENTED** (confidence: certain)

**Finding.** A real, carefully-built consent mechanism exists for exactly one message channel (the first-party LynomiaChat campaign integration, whose enforcement is upstream and not measurable here); every other message sender in the repo — the promotional CouponEmail, the ReviewReminder email, the newsletter list and the back-in-stock push — consults no consent flag at all, there is no consent/eligibility column on `customers`, and no unsubscribe path exists anywhere.

**What is missing**

- A customer-level consent flag: `customers` has NO consent/eligibility column at all. Read the create migration (database/migrations/2023_12_15_112855_create_customers_table.php, all 25 columns) and `Customer::$fillable` — there is no `is_subscribed`, `opt_in`, `opt_out`, `marketing_consent`, `email_consent`, `sms_consent`, `accepts_marketing` or `newsletter` field, and a repo-wide grep for those names across …
- Consent enforcement inside this repository: nothing in app/ or packages/workdo/ reads `lynomia_consent_states` to gate a send. Its only three readers are ConsentReconciler (compares), ConsentStateProjector (writes) and StoreConsentController (displays). The refusal-to-send lives in the upstream Lynomia service, so the half of MKT-008 that actually stops a message cannot be confirmed from this codebase.
- Consent respected by the CouponEmail sender: three promotional sends (on registration, on first order, on order-value threshold) carry no consent predicate — packages/workdo/CouponEmail/src/app/Listeners/CreateCustomerLis.php:28 and CreateOrderLis.php:32,51.
- Consent respected by the ReviewReminder sender: packages/workdo/ReviewReminder/src/app/Listeners/SendOrderReviewMailListner.php:38 gates only on the merchant's template being active.
- Any unsubscribe or opt-out path for a customer: a case-insensitive grep for `unsubscribe|opt.?out` across app/, resources/, routes/ and packages/workdo/ returns only LynomiaChat source COMMENTS and one unrelated PlanController comment. There is no route, controller method, email footer link, token or customer-account control by which a shopper can stop the newsletter or the CouponEmail. The checkout label promises …
- The captured newsletter opt-in is never consumed: no sender of any kind reads the `newsletters` table, so the flag that is collected in four places (NewsletterController@store, AccountProfileController@add_newsletter, Api\ApiController@subscribe:8829-8837, Subscribe module SubscribeController@store) exists and is read by nothing but an xlsx export. The rows also record no purpose, channel or policy version.
- Per-customer eligibility on this side: the only eligibility implemented locally is store-level send allowance (MarketingAllowanceSync) and tenant scoping of the audience (CampaignApiController::scopedStoreId). `customers.status` is never consulted on any messaging path, and per-contact suppression/blocking exists only as a proxied read of upstream state.
- A merchant-visible consent surface for anything other than Lynomia: the consent ledger, the checkout switch and the per-contact consent admin are all behind `Manage Lynomia Consent` on the /lynomia/* routes. A store that uses CouponEmail, ReviewReminder or the newsletter list has no consent screen whatsoever.
- `docs/partner-consent/AUTHORITY.md`, cited four times as the governing authority document (CheckoutConsentCapture.php:30,40 and the consent-tables migration), does not exist — `docs/partner-consent/` is absent and no AUTHORITY.md exists anywhere in the repo.
- MKT-008 is not traced in docs/development/MASTER-PLAN-REQUIREMENT-TRACEABILITY.md at all. It appears only as a family-level row in docs/development/master-plan/DECISION-ID-REGISTER.md:135; the file's MKT discussion covers only MKT-005 and MKT-006 (lines 4631-4635). No Phase 05 document mentions consent.

**Cited as present**

- `packages/workdo/LynomiaChat/src/database/migrations/2026_10_20_000100_create_lynomia_consent_tables.php:37-93` `(v)` — Two consent tables with a deliberate split: `lynomia_consent_decisions` (append-only, what the shopper told THIS store: purpose, channel, state, source, policy_version, re_consent, evidence, …
- `packages/workdo/LynomiaChat/src/app/Services/CheckoutConsentCapture.php:47-150` `(v)` — Consent CAPTURE, and it is genuinely careful: field `lynomia_marketing_optin`, purpose `promotional`, channel `any`; absence of the parameter means no; `staffRequest()` refuses any request where the …
- `packages/workdo/LynomiaChat/src/app/Observers/CheckoutConsentObserver.php:33-44` `(v)` — Where capture is hooked: `Order::created`, so it fires inside whichever writer's transaction opened, for all ~50 order-writing sites. Registered for real at LynomiaChatServiceProvider.php:173 …
- `packages/workdo/LynomiaChat/src/resources/views/storefront/marketing-optin.blade.php:13-31` `(v)` — The shopper-facing checkbox. Measured: no `checked` attribute and no setting that can add one; the label names the store and says messages can be stopped and that it does not affect order messages. …
- `packages/workdo/LynomiaChat/src/app/Services/ConsentDecisionDispatcher.php:41-125` `(v)` — Relay of the decision to Lynomia over the platform credential, with stored backoff [30,120,600,1800,3600], a 30-day expiry measured from when the shopper decided, and SETTLED outcomes …
- `packages/workdo/LynomiaChat/src/app/Http/Controllers/StoreConsentController.php:43-115` `(v)` — Merchant-facing consent ledger: each local decision beside Lynomia's EFFECTIVE remote state (union of negatives across origins via `LynomiaConsentState::effectiveFor`), a `pending` undelivered count, …
- `packages/workdo/LynomiaChat/src/app/Http/Controllers/CampaignApiController.php:108-250` `(v)` — The campaign surface. `preview()` deliberately asks the SAME upstream audience resolver the sender uses, passing `commerce_store_id`, the inbox channel and the template params, so the count already …
- `packages/workdo/LynomiaChat/src/app/Http/Controllers/AutomationApiController.php:577-665` `(v)` — Per-contact consent administration: list by purpose/state (granted|withdrawn|suppressed), read one contact's consent incl. a `refusal` field for 'what would happen NOW', record a grant or withdrawal …
- `packages/workdo/LynomiaChat/src/app/Services/MarketingAllowanceSync.php:51-167` `(v)` — The only ELIGIBILITY notion implemented on this side, and it is STORE-level not customer-level: plan + add-on included sends, surfaced by CampaignApiController@allowance which reads …
- `tests/Feature/Lynomia/LynomiaCheckoutOptInTest.php:76-262` — 14 tests pinning the capture semantics by name: a tick is a promotional grant, never a re-consent, an unticked box records nothing AND does not withdraw an existing grant, a back-office request …
- `packages/workdo/CouponEmail/src/app/Listeners/CreateCustomerLis.php:20-37` `(v)` — VIOLATION. A promotional coupon email sent on customer registration. The only predicates are merchant settings (`couponemail_setting_enabled`, `is_active`, `couponemail_setting == 0`) and …
- `packages/workdo/CouponEmail/src/app/Listeners/CreateOrderLis.php:22-61` `(v)` — VIOLATION. Two more promotional coupon sends — first-order and order-value-threshold — reading `OrderBillingDetail->email`, so GUESTS are messaged too. Gated only by merchant settings and …
- …and 4 further citations in the run record

**Lens — missed-implementation: verdict stands** (confidence: certain)

NO MISSED IMPLEMENTATION. The status PARTIALLY_IMPLEMENTED stands. But the verdict's EVIDENCE is materially incomplete: it missed four live, first-party, consent-free promotional senders that sit under the merchant's own Marketing menu, and it missed the central dispatcher they share. The "partial" is thinner than it was described.

Citations: `app/Http/Controllers/CartController.php:1241-1291`, `app/Http/Controllers/CartController.php:1341-1420`, `app/Http/Controllers/WishlistController.php:160-205`, `app/Http/Controllers/WishlistController.php:514-596`, `app/Services/AbandonedMessageDispatch.php:71-100`, `app/Models/Utility.php:2515-2527` and 6 more.

**Lens — does-it-actually-work: CHALLENGED** (confidence: probable)

The LABEL stands but three of the verdict's evidence items do not survive a reachability check, and all three fail in the direction of the verdict having credited a surface that cannot do what it says.

Citations: `app/Http/Controllers/Api/ApiController.php:8552,8577-8606,8623,8629-8631`, `app/Http/Controllers/ProductController.php:2958-2975`, `app/Http/Controllers/NewsletterController.php:56,38-40`, `app/Http/Controllers/AccountProfileController.php:513-540`, `app/Models/Newsletter.php:1-17`, `resources/views/front_end/pages/checkout.blade.php:25,248,263,368,373` and 6 more.

---

### `MKT-009` — Lynomia integration when ready, without duplication

> تكامل Lynomia عند الجاهزية دون ازدواج.

**Measured: PARTIALLY IMPLEMENTED** (confidence: probable)

**Finding.** A substantial first-party Lynomia integration exists (129 files / 27,164 lines, 99 resolving routes) and the "when ready" clause is genuinely respected in code — it ships with LYNOMIA_INTEGRATION_ENABLED=false and a "Not available yet" banner — but "without duplication" is only half met: the retired Chatwoot path is deleted and pinned by a test and internal duplicate-prevention is real, while nothing stops the platform's other live messaging paths (the routed WhatsApp abandoned-cart nudge, and the vendored TawktoMessenger/Wizzchat/Campaigns/Marketing/SMS modules) from running alongside it.

**What is missing**

- NO GUARD AGAINST THE PLATFORM'S OWN COMPETING SHOPPER-MESSAGING PATH. `POST abandon-carts-messagesend` (CartController@abandon_carts_messsend, /home/user/syriastore98/app/Http/Controllers/CartController.php:1438) and `POST abandon-wishlist-message-send` (WishlistController.php:587) both resolve in route:list today and send WhatsApp nudges through /home/user/syriastore98/app/Services/AbandonedMessageDispatch.php + …
- NO EXCLUSIVITY BETWEEN LynomiaChat AND THE VENDORED MESSAGING/MARKETING MODULES. `grep -rniE 'TawktoMessenger|Wizzchat' packages/workdo/LynomiaChat/` returns ZERO hits, and the same for every Lynomia doc directory. packages/workdo/ ships TawktoMessenger and Wizzchat (two further chat widgets), plus Campaigns, Marketing, SMS, Subscribe, Helpdesk and HubSpot, each independently activatable by an add_on_managers row …
- THE "WHEN READY" CLAUSE IS PROVED BY CONFIGURATION, NOT BY A READINESS RUN. Because the kill switch is off and base_url/platform_token are empty, I could not trace a single value end to end: no connection can be provisioned, no conversation fetched, no campaign sent. The integration's own register (a doc) states blocker A1 — no run against a live Lynomia has ever happened — is open, and Lynomia-side mechanisms the …
- MKT-009 IS NOT TRACKED BY ID ANYWHERE. `grep -rn MKT-009` across the repo returns exactly one line — docs/development/master-plan/DECISION-ID-REGISTER.md:136. It has no row in docs/development/MASTER-PLAN-REQUIREMENT-TRACEABILITY.md and none in docs/development/MASTER-PLAN-GAP-REGISTER.md (same for MKT-007, MKT-008 and MKT-010), so no disposition exists for it under CLAUDE.md §4.1.
- RESIDUAL DUPLICATE DATA SURFACE, DELIBERATELY RETAINED. database/migrations/2026_11_02_000900_create_chatwoot_integrations_table.php and _001000_create_chatwoot_notification_templates_table.php are still present. LegacyChatwootRemovedTest's docblock states this is intentional — they are the store -> account_id/inbox_id mapping the replacement migrates from — and that the test asserts neither presence nor absence. …

**Cited as present**

- `packages/workdo/LynomiaChat/` (whole package) `(v)` — The integration itself: 129 PHP files, 27,164 lines, 22 migrations, 8 seeded permissions (Manage/Reply/Assign Lynomia Chat, Manage Lynomia Automation/Consent/Audiences/Templates/Campaigns). Surfaces: …
- `packages/workdo/LynomiaChat/src/config/lynomia.php:27-45, 136` `(v)` — THE "WHEN READY" GATE. `'enabled' => env('LYNOMIA_INTEGRATION_ENABLED', false)` — documented at the top of the file as "the engineering kill switch for the whole integration" that "stays false until …
- `.env:90-136` — The gate is measured OFF in this installation, not merely defaulted off: LYNOMIA_INTEGRATION_ENABLED=false, LYNOMIA_BASE_URL= (empty), LYNOMIA_PLATFORM_TOKEN= (empty), LYNOMIA_WEBHOOK_MASTER_SECRET= …
- `packages/workdo/LynomiaChat/src/app/Services/PlatformApiClient.php:60-70` `(v)` — isConfigured() = isEnabled() && filled(base_url) && filled(platform_token) — the single readiness predicate the UI renders from.
- `packages/workdo/LynomiaChat/src/resources/views/merchant/overview.blade.php:13-20, 66, 323, 341, 368` `(v)` — The readiness clause made visible rather than implied: when !$integrationConfigured the page renders an "Not available yet / Lynomia Chat has not been configured for this installation" alert, and the …
- `tests/Feature/Security/LegacyChatwootRemovedTest.php` (whole file) — THE NO-DUPLICATION EVIDENCE FOR THE PREDECESSOR. Asserts absence of 9 legacy `chatwoot.*` route names, of any route URI containing "chatwoot", of 14 legacy files plus the …
- `packages/workdo/LynomiaChat/src/database/migrations/` (13 unique indexes across 8 migrations) `(v)` — Duplicate-prevention as schema, not convention: `store_id` UNIQUE and `lynomia_account_id` UNIQUE on lynomia_store_links (one link per store, one workspace per store); `delivery_id` UNIQUE on …
- `packages/workdo/LynomiaChat/src/app/Services/AccountProvisioner.php:59-61, 251-268, 380` `(v)` — Adopt-before-create against duplicate workspaces: every attempt first asks Lynomia whether the store already has one, a create whose answer was lost is adopted rather than repeated, POST /accounts is …
- `packages/workdo/LynomiaChat/src/app/Http/Controllers/EventIngressController.php:72-122, 190` `(v)` — Replay de-duplication at the ingress: a repeated delivery_id is caught as a unique violation and answered as a SUCCESS rather than reprocessed ("A duplicate is a success, not a problem: the unique …
- `app/Providers/PackageServiceProvider.php:15-37` — Why the module's 99 routes resolve even with the integration disabled, and why its 44 test files can actually execute (unlike every other WorkDo module): LynomiaChat is in $staticAddons = …
- `packages/workdo/LynomiaChat/src/routes/web.php:109-172, 176-178, 287-295` `(v)` — The merchant group carries ['web','auth','xss','setlocate','verified'] + PlanModuleCheck:LynomiaChat + Laratrust Permission:'Manage Lynomia Chat', with per-action permissions (Reply/Assign/Consent) …
- `packages/workdo/LynomiaChat/src/app/Observers/CommerceEventObserver.php:53-80` `(v)` — The kill switch enforced on the write path: record() returns before creating any LynomiaCommerceEvent unless BOTH lynomia.enabled and lynomia.commerce_events_enabled are true, and only for a store …
- …and 2 further citations in the run record

**Lens — missed-implementation: CHALLENGED** (confidence: certain)

THE MEASURER MISSED AN ENTIRE SECOND LYNOMIA INTEGRATION. They equated "Lynomia integration" with `packages/workdo/LynomiaChat/` and never found the first-party **LYNOMIA OTP** integration in `app/` — and it is that surface, not the module, that breaks the clause they scored as respected.

Citations: `app/Http/Controllers/OtpIntegrationController.php:whole file (724 lines)`, `database/migrations/2026_11_02_000800_create_otp_integrations_table.php:58, 8-30, 80-90`, `resources/views/setting/index.blade.php:156-157, 174`, `packages/workdo/LynomiaChat/src/app/Services/MerchantContactVerification.php:31-38`, `app/Http/Controllers/Customer/Auth/CustomerLoginController.php:150-215`, `app/Services/OtpTransport.php:29-33, 108-120` and 6 more.

**Lens — does-it-actually-work: CHALLENGED** (confidence: probable)

THE STATUS LABEL SURVIVES; ONE OF ITS TWO PILLARS DOES NOT. The verdict's "when ready is genuinely respected" rests on a surface it measured in the test harness, not in the shipped product: the merchant half of LynomiaChat is UNREACHABLE in a default-seeded installation, so the "Not available yet" banner it cites as the visible readiness disclosure never renders for any merchant, and the "Communication & Marketing" sidebar section it describes as the entry point is never drawn at all.

Citations: `app/Http/Middleware/PlanModuleCheck.php:34, 42, 58-63, 66`, `app/Helper/helper.php:1652-1656, 1691-1711, 1775, 1834`, `app/Classes/Module.php:46, 117-120, 138-143`, `database/seeders/DatabaseSeeder.php:20-27`, `packages/workdo/LynomiaChat/src/database/seeders/MarketPlaceSeederTableSeeder.php:24-32`, `database/seeders/PermissionTableSeeder.php:whole file (146 lines)` and 9 more.

---

### `MKT-010` — In-store conversion tools, linked to the themes

> أدوات تحويل داخل المتجر مرتبطة بالثيمات.

**Measured: PARTIALLY IMPLEMENTED** (confidence: probable)

**Finding.** A large set of in-store conversion tools exists, all vendored WorkDo modules, and the hook/stack plumbing does reach all three storefront themes plus the fallback — but the "linked to the themes" half is only partly true: three wirings are dead or missing in a named theme, the brand-colour linkage is drifted or hardcoded in four widgets, and no conversion tool is exposed as a theme section or in the Phase 03 visual theme editor.

**What is missing**

- greentic's product card omits the quick-view/compare hook entirely. themes/greentic/views/components/product-card.blade.php calls productSalesPage (line 5) and ProductcardButton (line 42) but NEVER \App\Models\Product::actionLinks() — unlike stylique (line 20), techzonix (line 10) and even the resources/views/components/product-card.blade.php fallback (line 20). front_end.hooks.action_link has exactly two includers …
- The product-DETAIL badge surface is never rendered in any theme. \App\Models\Product::productSalesPage($slug, $id, $details) is the only renderer of resources/views/front_end/sections/product_detail_sale_lable.blade.php, which carries @stack("BadgeView") at line 1 — and all four callers pass `false`. grep for productSalesPage returns 5 hits: the definition plus four call sites (three themes + the fallback …
- LotteryPrizes' storefront slider is dead wiring. packages/workdo/LotteryPrizes/src/app/Providers/Viewcomposer.php:71 does startPush('ProductSlider', view('lottery-prizes::theme.product_slider', compact('products','store','slug','currentTheme'))) — and there is no @stack('ProductSlider') anywhere in resources/views/, themes/ or packages/workdo/, case-insensitively. The push has no consumer, so this gamified …
- The brand-colour token two of the three themes need is undefined. PurchaseNotification/pages/model_popup.blade.php, RecentlyViewedProducts/pages/model_popup.blade.php and FrequentlyProduct/pages/frequently_product_slider.blade.php all tint with rgba(var(--primary-color-rgb, 59, 130, 246), ...). `--primary-color-rgb` is declared in stylique's main-style.css only (line 31: `248, 121, 108`, added with a comment noting …
- BoostSales' theme colour map has drifted from the themes it names. boostSalesModelPopup.blade.php hardcodes greentic primary #4caf50 and techzonix primary #007bff; the themes' own main-style.css declare --primary-color: #39843c (greentic) and #9952e0 (techzonix). Only stylique matches (#f8796c both sides). So the popup paints the wrong brand colour on 2 of 3 themes while appearing to be theme-linked — the same …
- SpinToWinReward and FreeShippingPopup read no theme or design-system token at all. spin_button.blade.php hardcodes linear-gradient(135deg, #667eea, #764ba2) and freeShippingPopupbutton.blade.php hardcodes a green gradient (#28a745 → #20c997); neither references var(--primary-color), var(--ss-*) nor hsl(var(--primary)). The shared FAB chrome in footer_link.blade.php is likewise hardcoded (#ff6b6b → #ee5a24 → …
- CountDownTimer theme_layout_3, _4 and _5 read no brand token. Only theme_layout_1 and theme_layout_2 contain --primary-color; a merchant who picks one of the other three countdown layouts gets a popup with no relationship to their theme's colour.
- No conversion tool is a theme section, so it cannot be placed or styled per theme. Extracting every section/name/slug/type identifier from the 99 files under themes/*/theme_json/ yields 100 identifiers (banner, bestseller, collection, counter, footer_newsletter, newsletter, offer, more_offer, testimonial, top_category, ...) and not one popup, countdown, badge, spin-wheel, upsell or social-proof section. Grepping …
- The conversion rail collides with the chat/WhatsApp rail in Arabic RTL. In footer_link.blade.php, .floating-action-menu is positioned `left: 20px; bottom: 20px` with no direction variant, while .external-btns is given [dir="ltr"] right:30px and [dir="rtl"] left:30px. In RTL both therefore occupy the bottom-left corner (and bottom-left at 15px each under the 768px media query). Not verified in a browser — measured …
- Nothing here is first-party except the LynomiaChat opt-in, and the whole set is gated behind an operator action. Every conversion tool above is vendored WorkDo code under packages/workdo/, and PackageServiceProvider loads only LandingPage and LynomiaChat statically. In this checkout `add_on_managers` holds 0 rows (read directly from database/database.sqlite), and `php artisan route:list --json` accordingly registers …

**Cited as present**

- `resources/views/front_end/hooks/footer_link.blade.php:1-21, 320-336` — The single shared storefront hook host for the floating-action-button (FAB) conversion rail. Declares 8 stacks: addCompareButton, addCatelogRequestButton, SpinwheelButton, DonationFormButton, …
- `resources/views/front_end/jQueryRoute.blade.php:130-135` — Second shared hook host, included by all four layouts. Carries @stack('recentViewModelPopup'), @stack('subscribeStorePopup'), @stack('countDownPopup'), @stack('tawktoModelPopup'), …
- `packages/workdo/SpinToWinReward/src/app/Providers/ViewComposer.php:21-32` `(v)` — Spin-to-win wheel. view()->composer(['front_end.layouts.app']) gated on module_is_active('SpinToWinReward') + Utility::GetValueByName('enable_spin_to_win', $store->id) == 'on', then …
- `packages/workdo/BoostSales/src/app/Providers/ViewComposer.php:44-164` `(v)` — Upsell/bundle popup. view()->composer(['front_end.hooks.footer_link']) gated on boost_sales_enabled == 'on'; reads $currentTheme = $store->theme_id and pushes boostSalesModelPopup only when …
- `packages/workdo/BoostSales/src/resources/views/pages/boostSalesModelPopup.blade.php:1-33` `(v)` — The only conversion widget with a per-theme colour map: $themeColors keyed 'greentic'/'stylique'/'techzonix', selected by $colors = $themeColors[$currentTheme] ?? $themeColors['greentic']. Literal …
- `packages/workdo/CountDownTimer/src/app/Providers/ViewComposer.php:35-47` `(v)` — Urgency countdown popup. Composes front_end.layouts.app and pushes countDownPopup with view('count-down-timer::theme.theme_layout_' . $countDown->theme_layout) — five selectable layout variants …
- `packages/workdo/CountDownTimer/src/resources/views/theme/theme_layout_1.blade.php` `(v)` — Tailwind-utility countdown markup that reads the theme's own brand token as bg-[var(--primary-color)]. theme_layout_2 does the same. Genuine token-level theme linkage for 2 of the 5 layouts.
- `packages/workdo/FreeShippingPopup/src/app/Providers/ViewComposer.php:31-39` `(v)` — Free-shipping-threshold popup. Composes front_end.hooks.footer_link, pushes freeShippingPopupButton. The widget guards itself on free_shipping_popup_enabled == 'on' && free_shipping_apply_price != 0 …
- `packages/workdo/PurchaseNotification/src/app/Providers/ViewComposer.php:42-53` `(v)` — Social-proof 'someone just bought' toast. Composes front_end.layouts.app, pushes purchaseNotificationPopup, consumed at jQueryRoute:134. Its model_popup.blade.php styles itself from …
- `packages/workdo/RecentlyViewedProducts/src/app/Providers/ViewComposer.php:27-39` `(v)` — Recently-viewed-products popup. Composes front_end.layouts.app, pushes recentViewModelPopup, consumed at jQueryRoute:130. model_popup.blade.php reads var(--primary-color) and …
- `packages/workdo/FrequentlyProduct/src/app/Providers/ViewComposer.php:34-96` `(v)` — Frequently-bought-together / complementary-product upsell slider. Composes front_end.pages.product-detail (a view no theme overrides), computes cart totals and tax, and pushes frequentlyproductslider …
- `packages/workdo/ProductBadgeManagement/src/app/Providers/ViewComposer.php:28-37` `(v)` — Product badges. view()->composer(['front_end.sections.product_sales', 'components.product-card']) — note the second target IS the theme-overridden component — pushing BadgeView, which …
- …and 6 further citations in the run record

**Lens — missed-implementation: CHALLENGED** (confidence: certain)

The label PARTIALLY_IMPLEMENTED survives, but the verdict's evidence base is wrong in a way that inverts its two most load-bearing absence claims. The measurer searched the vendored `packages/workdo/` conversion modules exhaustively and never found the FIRST-PARTY, non-vendored, non-module-gated conversion layer — which is also the layer that is most genuinely "linked to the themes".

Citations: `resources/views/front_end/common/product/sale_counter.blade.php:1-22`, `resources/views/front_end/pages/product-detail.blade.php:160`, `app/Http/Controllers/HomeController.php:1101-1165`, `public/themes/greentic/assets/css/main-style.css:750-799`, `themes/greentic/theme_json/newsletter.json:1-45`, `themes/greentic/theme_json/pages.json:slug list, last entry` and 10 more.

**Lens — does-it-actually-work: CHALLENGED** (confidence: probable)

The label PARTIALLY_IMPLEMENTED survives, but three load-bearing pieces of the verdict's evidence do not. I challenge its "exists and works" column, its dead-wiring census, and its entitlement gate.

Citations: `packages/workdo/ProductBadgeManagement/src/app/Providers/ViewComposer.php:28, 37`, `resources/views/front_end/sections/product_sales.blade.php:1`, `vendor/laravel/framework/src/Illuminate/View/Concerns/ManagesStacks.php:73-84, 149-166`, `vendor/laravel/framework/src/Illuminate/View/Concerns/ManagesEvents.php:175-180`, `packages/workdo/PartialPayments/src/app/Providers/CheckoutViewComposer.php:51`, `packages/workdo/PartialPayments/src/resources/views/theme/deposit-checkout-amount.blade.php:2` and 5 more.

---

### `ANA-001` — A centre for sales, finance, products, customers and marketing

> مركز للمبيعات والمالية والمنتجات والعملاء والتسويق.

**Measured: PARTIALLY IMPLEMENTED** (confidence: certain)

**Finding.** A "Reports" centre exists and covers three of the five named domains (sales, products, customers); finance reporting exists but as its own separate top-level menu outside the centre, and marketing analytics does not exist anywhere routed — so this is not one centre but four separate nav groups.

**What is missing**

- FINANCE is not in the centre. The Reports group has no finance screen and no finance permission, and ReportController's 3,835 lines contain 0 occurrences of payment_status, refund, cost_price, profit or margin ('tax' only as a subtraction to derive net sale). Its own on-screen hints disclaim the domain: 'not what they paid, and not profit'. Finance reporting exists, but as a separate top-level menu …
- MARKETING analytics does not exist on any reachable surface. The 'Marketing' top-level menu (CompanyMenuListener.php:577) is CRUD management only — Coupon, Newsletter, Flash Sale, Wishlist, Abandon Cart. No marketing entry in the Reports group; no coupon-performance, campaign-performance, newsletter, abandoned-cart-recovery or loyalty report anywhere in route:list. OrderCouponDetail is imported into ReportController …
- THE 'CENTRE' ITSELF. The five domains are not behind one entry point: analytics is split across four separate top-level nav groups in CompanyMenuListener.php — Admin Dashboard (dashboard + Store Analytics, :50), Finance (:388), Reports (:421) and Marketing (:577). What exists is a reports centre for three domains, plus two sibling destinations.
- CUSTOMERS is present but thin: counts only (orders by registered vs guest, new sign-ups). No aggregate customer value, no cohorts or retention, no top-customers figure on any report screen. CustomerRetention and RetentionFigures exist in app/Support/Metrics/ with zero consumers; the only lifetime-spend figure shipped is per-record, on CustomerController::customerTimeline under the Customers menu.
- The figures that would close the finance and marketing gaps are computed by no screen: all 11 classes in app/Support/Metrics/ (NetFinancialReceipts, CollectedCustomerPayments, CompletedRefunds, CouponRedemption, StoreRevenueMargin, CustomerRetention, and the rest) have 0 references outside their own directory in app/, resources/, routes/ and packages/.
- The one marketing-adjacent screen a merchant can reach is broken: the 'Store Analytics' visitor chart plots 15 identical all-time totals because ThemeAnalyticController.php:134 and :136 have the per-date whereDate commented out inside the loop at :121. Its API twin (Api/DashboardController.php:3991-3992) keeps the predicate, so the web screen is wrong, not merely different.

**Cited as present**

- `app/Listeners/CompanyMenuListener.php:421-573` — THE CENTRE ITSELF, and the proof it is not one centre. A 'Reports' top-level nav group (:421, order 200, permission 'Manage Reports') with 11 screens: Customer Reports (:434 -> reports.index), an …
- `app/Http/Controllers/ReportController.php:1-3835 (29 public methods)` — SALES + PRODUCTS + CUSTOMERS, all routed. Sales: OrderReport (:521), BarChartOrderReport (:995), order_product_reports (:1479), order_category_reports (:1887), order_brand_reports (:2376), …
- `app/Http/Middleware/ReportPermission.php:30-70` — The centre is really gated, and its ACTIONS map names exactly THREE permissions — 'Manage Reports', 'Manage Order Reports', 'Manage Stock Reports'. There is no finance-report and no marketing-report …
- `resources/views/reports/_sales_stats.blade.php:14-42` — The sales figures I traced, shared by order_chart_data and order_bar_chart_data: order value including shipping and tax, order value, orders, items ordered, shipping charged, coupon value applied — …
- `resources/views/reports/chart_data.blade.php:20-55` — The CUSTOMERS coverage, rendered: orders, from registered customers, from guests, new customer sign-ups, plus two bar charts (orders placed; new sign-ups). Fed by ReportController::reports_chart, …
- `resources/views/reports/top_5_reports.blade.php:42-100` — Top Selling Products, Top Selling Category, Top Selling Brand, Top Payment Method. Confirms products are covered and confirms there is no top-customers figure.
- `app/Http/Controllers/StoreFinanceController.php:736-1070` — FINANCE EXISTS — but as its own screen, not in the centre. /finance (finance.index) returns view('finance.index') with wallet balances, a filtered store_wallet_transactions ledger (search by …
- `app/Http/Controllers/HomeController.php:209-510 (index -> handleRegularUser at :263)` — The merchant dashboard: product count, order counts by status with today-percentages, customer count, $total_revenues (order value with cancelled excluded), top-selling products, out-of-stock and …
- `app/Http/Controllers/ThemeAnalyticController.php:9-150` — The closest thing to marketing analytics that a merchant can actually reach — and it is TRAFFIC, not marketing: shetabit_visits counts, device, browser, platform and top-20 storefront URLs, at …
- `app/Support/Metrics/:11 concrete metrics + 9 support classes` — BUILT AND WIRED TO NOTHING — and it is precisely the missing finance and marketing figures. NetFinancialReceipts, CollectedCustomerPayments, CompletedRefunds, UnpaidOrders, CancelledOrders, …
- `packages/workdo/Campaigns/src/app/Http/Controllers/CampaignsController.php:355-366 (productAnalytics)` `(v)` — The vendored module whose name covers marketing analytics, checked rather than assumed. Its 'analytics' screen (campaigns-analytics/{id} -> campaigns::analytics) passes only the campaign's own …
- `packages/workdo/Marketing/src/` (resources/views: appointments, card, contacts, store) `(v)` — The module literally called 'Marketing' is a business-card / appointments / contacts module (its menu listener adds only Store, Appointment, Contacts). It is not marketing analytics, and it is not …
- …and 1 further citations in the run record

**Lens — missed-implementation: CHALLENGED** (confidence: certain)

REFUTED IN PART. The status label is unchanged (still PARTIALLY_IMPLEMENTED) but two of the verdict's load-bearing factual claims are wrong, and one of them is its central absence claim.

Citations: `packages/workdo/LynomiaChat/src/app/Listeners/CompanyMenuListener.php:36-45, 133-142`, `packages/workdo/LynomiaChat/src/app/Http/Controllers/CampaignApiController.php:175-202`, `packages/workdo/LynomiaChat/src/app/Services/AgentApiClient.php:477-486`, `packages/workdo/LynomiaChat/src/resources/views/merchant/campaigns.blade.php:50, 126, 132, 202-206, 319, 349-355, 360-369, 399`, `app/Providers/PackageServiceProvider.php:16-31`, `app/Helper/helper.php:1760-1776` and 5 more.

**Lens — does-it-actually-work: CHALLENGED** (confidence: certain)

The label survives but the finding overstates what works. The verdict's gap is "finance is outside the centre, marketing is absent" — i.e. 4 of 5 domains present. Measured, the finance half is not merely outside the centre: it is permission-dead by default and the figures it does render are arithmetically wrong. Three refutations, all file:line.

Citations: `app/Http/Controllers/StoreFinanceController.php:1040-1045 (and 989, 1016-1021, 1047-1062)`, `app/Http/Controllers/ProductController.php:630-631, 832-836`, `app/Models/Product.php:321-326`, `database/p2dev.sqlite:products table, store 1`, `resources/views/finance/index.blade.php:136, 144, 152, 172-173`, `app/Models/Utility.php:3080-3100 vs 3115-3302 and 3326-3329` and 6 more.

---

### `ANA-002` — Comparisons, filters and saved periods

> مقارنات وفلاتر وفترات محفوظة.

**Measured: PARTIALLY IMPLEMENTED** (confidence: probable)

**Finding.** Filters exist and work on the report and list screens; "saved periods" exist only as named saved filter sets on the ORDER LIST (absolute dates, no analytics screen, product screen has backend but no UI); period-over-period COMPARISON does not exist anywhere in either repository or in any of the 70 vendored modules.

**What is missing**

- COMPARISONS — absent entirely. No period-over-period figure, prior-period baseline, delta, percent change or growth rate exists in either repository or in any vendored module. Greps for previous/prior/comparison period, period-over-period, yoy, percent/pct change and growth_rate across app, resources, routes, database and packages/workdo returned only date-AXIS builders (ReportController:1506,1897,2387; …
- COMPARISON on the merchant dashboard — absent and not even period-filterable: HomeController@index calls getOrderChart(['duration' => 'week']) hard-coded (app/Http/Controllers/HomeController.php:227,452) and resources/views/dashboard/overview.blade.php states fixed periods ('all time', 'last 7 days') with no period control and no prior-period figure.
- COMPARISON in the Flutter admin app — absent, and so is any period filter: /home/user/StoreSyria_app98/lib/view/screen/statistics_screen/statistics_screen_widget.dart:791-800 records in code that 'there is no selector anywhere in this app, and the dashboard endpoint hardcodes its own window (["duration" => "month"], which it then walks as 15 days)'. The API side matches: Api/DashboardController::dashboard() sets …
- SAVED PERIODS on any analytics/report surface — absent. TableFilterMemory::VOCABULARY has only 'product' and 'order'; SavedViewController::apply() can redirect only to order.index or product.index; no report view persists chart_data in session, localStorage or sessionStorage (greps over resources/views/reports, components/ss and dashboard are empty), so the reports hub always re-opens on the default selected='year'.
- SAVED PERIODS are ABSOLUTE ONLY — no relative/rolling period can be saved. SavedView::sanitiseFilters() stores each value as a trimmed scalar string, so a view saved as 'Unpaid this week' freezes the ss_from/ss_to dates of the day it was saved; there is no 'last 30 days'-style token anywhere in the vocabulary.
- SAVED VIEWS UI exists on the ORDER screen only. grep for 'saved-views' across resources/views hits exactly one file (order/partials/filters.blade.php); $ssSavedViews is passed from one place (app/Http/Controllers/OrderController.php:713). The product screen has vocabulary, policy, controller and route support but no save form, no chips and no way to reach them (resources/views/product/partials/filters.blade.php has …
- NO saved-report / saved-period ENTITY behind the reports resource: routes/web.php:1163 declares Route::resource('reports', ReportController::class), which publishes reports/create, POST reports, reports/{report}, reports/{report}/edit, PUT/PATCH and DELETE reports/{report} — but ReportController defines none of create/store/show/edit/update/destroy (grep exits 1) and App\Http\Controllers\Controller has no methods, …
- The report screens' CUSTOM RANGE is unreachable from the shipped UI: ReportController parses a range only via str_contains($request->Date, ' to ') (:281,819,1298,1769,2207,2777), while the only first-party control is a single <input type="date" name="date"> sent as Date (report-filter.blade.php:66-72; reports/index.blade.php:50-57), so from == to and the range branch has no first-party caller.
- Verification of the filtered report surfaces is outside the test suite: ReportController cannot execute under SQLite (36 MONTH(/9 YEAR( in selectRaw — stated at ReportController:60-66), so the period-window behaviour rests on the MySQL script tests/Support/Security/bin/verify-report-window-boundary-on-mysql.php, which I did not run (read-only task, no MySQL here).

**Cited as present**

- `resources/views/components/ss/report-filter.blade.php:1-84` — FILTERS (period): the shared report period control — four presets (This year / Last month / This month / Last 7 days) as aria-pressed chips named chart_data, one date field, and a Generate button. …
- `app/Http/Controllers/ReportController.php:73-3835 (branches at 82,148,207,230,281; 530,606,674,751,819; 1484,1568,1618,1694,1766; 1891,1967,2042,2124,2207; 2386,2477,2568,2694,2777)` — FILTERS (server side): every report handler branches on $request->chart_data ('last-month' / 'this-month' / 'seven-day' / 'year', else a Date-wise branch) and narrows by entity — selectedProducts / …
- `app/DataTables/OrderDataTable.php:686-800` — FILTERS (order list): applyFilters() applies real predicates for ss_status, ss_payment, ss_return='1', ss_source (against Order::SOURCES), ss_from (>= startOfDay) and ss_to (<= endOfDay, with the …
- `app/Services/TableFilterMemory.php:76-96 (VOCABULARY), 98-140, 268-330` — FILTERS (vocabulary + session memory) and the reason saved periods are list-only: VOCABULARY has exactly two screens — 'product' => ss_status, ss_stock, ss_category, ss_variant and 'order' => …
- `app/Models/SavedView.php:1-104` — SAVED PERIODS (partial): a named filter set owned by (store_id, created_by); filters cast to array and re-sanitised against TableFilterMemory::vocabularyFor($screen) on read as well as write; …
- `app/Http/Controllers/SavedViewController.php:44-150` — SAVED PERIODS (routed behaviour): store() validates screen against TableFilterMemory::screens(), sanitises filters, sets store_id/created_by from the principal (neither is fillable), and re-saving a …
- `resources/views/order/partials/filters.blade.php:146-258` — SAVED PERIODS (the only UI): the date-range fields (ss_from/ss_to) at :146-153 and the saved-views block at :194-258 — Apply chips (POST saved-views/{id}/apply), a delete control, and a 'Save these …
- `database/migrations/2026_10_28_000003_create_saved_views_table.php:57-88` — SAVED PERIODS (schema): saved_views (store_id, created_by, screen(32), name(60), filters text) with unique (store_id, created_by, screen, name). Its own docblock states 'PREPARED AND TESTED IN …
- `app/Support/Metrics/MetricWindow.php:27-110` — The Phase 05 metric layer's period object: allTime(), between(), since(), applyTo(), describe(). It has NO previous-window/shift helper, and no file in app/Support/Metrics or app/Support/Reports …
- `app/Listeners/CompanyMenuListener.php:419-500` — Reachability of the filtered report screens: the sidebar 'Grow' group (config/merchant-navigation.php:60-64) carries Reports (permission 'Manage Reports') -> Customer Reports (route reports.index) …

**Lens — missed-implementation: verdict stands** (confidence: probable)

STATUS SURVIVES (PARTIALLY_IMPLEMENTED), but THREE of its evidence claims are refuted with file:line, and I add five new reasons the comparison absence is stronger than stated.

Citations: `app/Http/Controllers/StoreFinanceController.php:2184, 2280, 2290`, `/home/user/StoreSyria_app98/lib/view/screen/wallet/wallet_screen.dart:252-253, 372-387, 464-465`, `app/Http/Controllers/Api/DashboardController.php:1607, 1629-1630, 1655-1659`, `packages/workdo/Campaigns/src/resources/views/analytics.blade.php:1-156`, `app/Http/Controllers/ReportController.php:3005, 3143, 3402`, `app/Http/Controllers/ReportController.php:1551, 1559 (repeated 1748, 1756)` and 5 more.

**Lens — does-it-actually-work: CHALLENGED** (confidence: certain)

THE LABEL SURVIVES; TWO OF ITS EVIDENCE ITEMS DO NOT. I could not break the report-period filters, the order-list filters, or the saved-view create/apply/delete path — I traced each end to end and they work (details below). But the verdict's reach claims for the filter bar and the saved-period UI name three order screens, and on one of them the control is inert.

Citations: `resources/views/order/kanban.blade.php:73`, `resources/views/order/partials/filters.blade.php:42-49`, `app/Http/Controllers/OrderController.php:2734-2755`, `tests/Feature/Merchant/OrderKanbanTest.php:254-258`, `app/Http/Controllers/OrderController.php:713`, `resources/views/order/partials/filters.blade.php:194-240` and 6 more.

---

### `ANA-003` — Custom reports, export and scheduling, permission-gated

> تقارير مخصصة وتصدير وجدولة بصلاحيات.

**Measured: PARTIALLY IMPLEMENTED** (confidence: certain)

**Finding.** Of the four things ANA-003 names, only permission gating is complete; export exists on 9 of 11 report screens but is client-side-only with no server-side report export; there is no custom report BUILDER (no Report model, no reports table, and six of the seven Route::resource('reports') actions have no controller method at all); and scheduling is entirely absent — no scheduled report command, mailable, or cron entry exists anywhere in app/, routes/ or the ~70 vendored modules.

**What is missing**

- SCHEDULING — absent entirely, the hardest-checked of the four. grep -rn 'Schedule::command|->daily()|->dailyAt|->weekly()|->monthly()|->hourly()|->cron(' over app/ routes/ packages/ returns 8 hits and none is a report: routes/console.php has only 'inspire' (hourly) and 'zid:process-events' (everyMinute); packages/workdo/LynomiaChat/src/app/Providers/LynomiaChatServiceProvider.php:115,118,122,127,134,144 has six …
- CUSTOM REPORT BUILDING — no builder, and the surface that looks like one is dead code. There is no App\Models\Report and no reports table (Schema::create('reports' found nowhere under database/ or packages/). routes/web.php:1163 is Route::resource('reports', ReportController::class), which registers 7 routes, but the controller's 29-method census contains only index(); create, store, show, edit, update and destroy …
- CUSTOM REPORT BUILDING — no report definition can be saved, named or re-run. No CRUD, no storage, and SavedView (the one saved-filter mechanism) is restricted by vocabulary to the product and order list screens.
- CUSTOM REPORT BUILDING — no arbitrary date range. The request-input census shows only chart_data (4 fixed presets: This year / Last month / This month / Last 7 days, plus 'date-wise') and a single `Date`; resources/views/components/ss/report-filter.blade.php offers four period chips and one date input. A merchant cannot ask for an arbitrary start-to-end window, which the order LIST screen can do (ss_from/ss_to).
- CUSTOM REPORT BUILDING — no choice of metrics, dimensions or columns. The 11 report screens are fixed; only selectedProducts / selectedCategory / selectedBrand narrow the sale-by screens.
- EXPORT — two of the eleven report screens have no export of any kind. resources/views/reports/stock_report.blade.php: all three of its DataTables declare buttons: [Reset, Reload] only (:283, :346, :409) — no csvHtml5/excelHtml5/pdfHtml5. resources/views/reports/downloadable_product_report.blade.php has neither exportChartButton nor saveAsPDF.
- EXPORT — no server-side report export. All nine working exports are built in the browser (data:text/csv URI, or html2pdf on the DOM), so what is exported is what the page already rendered: it is capped by the rendered rows, cannot be requested by URL, cannot be produced without a session, and cannot be attached to anything — which is also the structural reason scheduling could not be added on top of it as it stands. …
- EXPORT/REPORTS — no API or Flutter reachability. Zero api/ routes match report|export in php artisan route:list --json, and the shipped admin app at /home/user/StoreSyria_app98 contains no report screen (find -iname '*report*' under it returns zero files). Reports are web-admin only.

**Cited as present**

- `app/Http/Middleware/ReportPermission.php:1-80` — PERMISSION GATING — complete. Gates every report handler, attached once in ReportController::__construct(). Uses isAbleTo() (not can(), which is always false here) and follows the project's required …
- `app/Http/Controllers/ReportController.php:1-3700` — The report engine: 3,700 lines, 29 public methods, 11 report screens (customer/sales/bar-chart/sale-by-product/category/brand/downloadable/order-status/country/top-5/stock). Parameterisation measured …
- `app/Http/Controllers/ReportController.php:510-520, 965-994` — EXPORT, mechanism traced. The six *_export handlers generate NO file: each reads the already-computed figures back out of the SESSION and returns JSON — export() is Session::get('return') at :510, …
- `resources/views/reports/index.blade.php:105-166` — Client-side CSV export: .exportChartButton jQuery click -> AJAX to route('reports.export') -> builds a data:text/csv URI with a UTF-8 BOM and clicks a synthetic link (:105-166). Hardened with an …
- `resources/views/reports/orderStatusReport.blade.php:16, 76-96` — Client-side PDF export: a real <button> calling saveAsPDF(), which IS defined in the view at :76 and uses html2pdf() on #printableArea (it opens the closed <details> holding the numbers first, so the …
- `app/Models/Utility.php:3013-3015, 3178-3180` — The three report permissions are really seeded into roles: 'Manage Order Reports', 'Manage Reports', 'Manage Stock Reports'.
- `app/Listeners/CompanyMenuListener.php:420-470, 571` — Sidebar reachability and the second (menu-level) permission gate: Reports parent ('Manage Reports'), Customer Reports -> reports.index, Order Reports submenu ('Manage Order Reports') -> …
- `tests/Feature/Security/ReportAuthorizationTest.php:72-178` — 8 tests pinning the gate, including test_every_routed_report_action_is_mapped_and_gated() which scans Route::getRoutes() and asserts ReportPermission is in gatherRouteMiddleware for every …
- `app/Exports/OrderExport.php` — Real server-side Maatwebsite exports exist, but NOT for reports: OrderExport, CouponExport and NewsletterExport are driven from OrderController.php:2444, CouponController.php:507 and …
- `app/Support/Metrics/` — 18 Phase-05 metric query objects (GrossMerchandiseValue, NetFinancialReceipts, CollectedCustomerPayments, UnpaidOrders, CustomerRetention, CouponRedemption, StoreRevenueMargin, ShippingAndTax, …
- `app/Support/Reports/AggregateSeriesLabel.php` — The only other first-party report support class: translates the two application-authored aggregate row keys ('All Brands', 'All Categories') for display. Used by order_brand_chart.blade.php and …
- `app/Services/TableFilterMemory.php:76-104` — Near-miss checked and ruled out: SavedView (named filter sets, app/Models/SavedView.php, routes POST/DELETE saved-views) is NOT custom reporting. Its VOCABULARY const covers exactly two screens, …

**Lens — missed-implementation: CHALLENGED** (confidence: probable)

The STATUS LABEL survives — no custom report builder, no scheduling — but two of the verdict's named "missing" items are REFUTED, and its Flutter/API absence claim is a search-term artefact.

Citations: `app/Http/Controllers/ReportController.php:2363-2366`, `app/DataTables/SalesDownloadableProductDataTable.php:18, 110-117, 155-158`, `vendor/yajra/laravel-datatables-buttons/src/Services/DataTable.php:112, 170-188, 327-340, 396-412, 421-440, 519-536, 559-562, 37`, `vendor/composer/installed.json`, `app/Http/Middleware/ReportPermission.php:17, 44`, `app/Helper/helper.php:3264, 3293-3300, 3348-3353` and 6 more.

**Lens — does-it-actually-work: CHALLENGED** (confidence: probable)

The PARTIALLY_IMPLEMENTED label survives, but three load-bearing export claims inside the verdict are FALSE, and I found one surface that is genuinely broken.

Citations: `app/Http/Controllers/ReportController.php:2363-2366`, `app/DataTables/SalesDownloadableProductDataTable.php:15, 17, 141-144`, `vendor/yajra/laravel-datatables-buttons/src/Services/DataTable.php:112, 170-188, 519-534, 421-440, 327-341, 485, 47, 134`, `vendor/yajra/laravel-datatables-buttons/src/Services/DataTablesExportHandler.php`, `vendor/yajra/laravel-datatables-buttons/src/config/config.php:40`, `app/Helper/helper.php:3264, 3293, 2949-2955` and 2 more.

---

### `ANA-004` — Conversion, device and channel analysis

> تحليل التحويل والأجهزة والقنوات.

**Measured: PARTIALLY IMPLEMENTED** (confidence: certain)

**Finding.** Of the three analyses ANA-004 names, only DEVICE exists (device/platform/browser pies on a routed merchant page plus a routed Flutter endpoint); conversion and channel analysis do not exist anywhere, and even the device surface cannot render a non-zero figure because every read predicates on shetabit_visits.store_id, which no write path in the repo ever populates.

**What is missing**

- CONVERSION ANALYSIS -- absent entirely. No conversion rate, no funnel, no stage counts: `conversion_rate|conversionRate|funnel|add_to_cart_rate|checkout_rate` has zero hits across app/, packages/, resources/views/, routes/ in .php/.blade.php (the only 'conversion' hits are currency conversion, SupportTicket conversions and unrelated prose). ReportController's 29 public surfaces are …
- CONVERSION ANALYSIS IS ALSO STRUCTURALLY BLOCKED, not merely unbuilt. There is no event stream: no page-view, session, funnel or analytics-event migration exists (`ls database/migrations | grep -iE 'event|session|analytic|funnel|channel'` yields only create_visits_table, add_cookie_session_id_to_carts, store_devices, delivery_boy_devices, zid_events and theme_publication_events -- the last three are device …
- CHANNEL ANALYSIS, TRAFFIC SENSE -- absent. `shetabit_visits.referer` IS written (redacted by SanitisedVisitor::referer(), app/Services/SanitisedVisitor.php:80-85) but nothing reads it: grep of referer|referrer across app/, packages/, resources/, routes/ returns only that service's own doc comment, a Referrer-Policy header, rel="noopener noreferrer" and tinymce referrerpolicy attributes. No UTM capture at all -- …
- CHANNEL ANALYSIS, SALES-CHANNEL SENSE -- the dimension exists but no analysis over it. `groupBy('order_source')` appears nowhere in app/ or packages/; order_source is only ever a per-row badge, an export column and an equality filter. A merchant can list 'show me POS orders'; nobody can see sales or counts BY channel, nor compare channels.
- THE DEVICE ANALYSIS CANNOT PRODUCE A NON-ZERO FIGURE: shetabit_visits.store_id is never written. Every read predicates on it (16 raw-query sites, which tests/Feature/Tenancy/RawQueryTenancyInventoryTest.php:759 records as `'shetabit_visits' => [16, 0]`, i.e. all 16 scoped). The sole writer is ActiveTheme -> `visitor()->visit($store)`; vendor/shetabit/visitor/src/Visitor.php:244-247 attaches the row to the model only …
- A SECOND, INDEPENDENT DEFECT in the web visitor trend: in ThemeAnalyticController::getStoreOrderChart the per-day predicate is commented out on BOTH queries (lines 134-137 -- the live lines 135 and 137 have no `whereDate('created_at','=',$date)`), so all 15 points of 'Total Page View' and 'Unique Page View' repeat one store-wide all-time total: a flat line presented as a 15-day trend. The API twin at …
- No comparison, segmentation or date-range control on the analytics page: the duration is hard-coded (`['duration' => 'month']`, ThemeAnalyticController.php:17 and DashboardController.php:3973), and the only branch implemented inside getStoreOrderChart is `== 'month'`, which actually means a fixed 15-day window.

**Cited as present**

- `app/Http/Controllers/ThemeAnalyticController.php:64-66` — DEVICE ANALYSIS (web). Three GROUP BY aggregations over shetabit_visits: device, browser, platform, each `selectRaw("count('*') as total, <dim>")->where('store_id', $user->current_store)`. Aliases …
- `resources/views/theme_analytic.blade.php:90-115, 169-233` — The rendering: three ApexCharts pie/donut cards headed Device (92), Platform (102), Browser (110), plus a Visitor trend (15, series at 140-148) and a Top URL table (25-81) that maps storefront paths …
- `app/Listeners/CompanyMenuListener.php:49-59` — Reachability: menu entry 'Store Analytics', parent admin_dashboard, order 2, route theme_analytic, permission 'Manage Store Analytics'.
- `routes/web.php:471` — `Route::get('themean-alytic', [ThemeAnalyticController::class,'index'])->name('theme_analytic')`, inside the `['auth','xss','setlocate','verified']` group opened at line 466. Confirmed live by `php …
- `app/Http/Controllers/Api/DashboardController.php:3959-4116` — DEVICE ANALYSIS (Flutter admin API) -- `StaticsData`: the same visitor trend, top_url, platform, browser and device breakdowns, assembled into $DashboardData and returned via $this->success(). …
- `routes/api.php:337` — `Route::get('staticsdata', [DashboardController::class,'StaticsData'])->middleware(['custom.auth','AdminApiLog'])`, inside the `admin` prefix, so `GET api/admin/staticsdata`. Confirmed by `php …
- `/home/user/StoreSyria_app98/lib/core/model/static_model.dart:58-96` — The shipped admin Flutter app parses `platform`, `browser` and `device` lists out of /staticsdata; the screen is lib/view/screen/statistics_screen/statistics_screen.dart via …
- `app/Http/Middleware/ActiveTheme.php:26-37` — The ONLY write path for visit data: `visitor()->visit($store)` on every request whose first path segment is an active, themed store slug, wrapped in try/catch. This is what records the …
- `app/Services/SanitisedVisitor.php:28-151` — First-party subclass of the vendored Shetabit visitor, bound as the `shetabit-visitor` singleton in app/Providers/AppServiceProvider.php:44-46. It keeps device/platform/browser/url and redacts …
- `app/Models/Order.php:60-110` — SALES-CHANNEL DATA (not an analysis): the `order_source` vocabulary -- SOURCE_POS 'pos', SOURCE_MANUAL 'manual', SOURCE_STOREFRONT 'storefront', SOURCES, orderSourceLabel(). Deliberately no …
- `app/DataTables/OrderDataTable.php:230-262, 750-754, 857-861` — The only consumers of order_source: a per-row translated badge (230-262), a list FILTER `?ss_source=` validated against Order::SOURCES (750-754), and a column declared neither searchable nor …

**Lens — missed-implementation: CHALLENGED** (confidence: certain)

THE STATUS LABEL SURVIVES; THE EVIDENCE DOES NOT. I could not find a conversion or channel ANALYSIS either, so PARTIALLY_IMPLEMENTED stands. But the verdict asserts "CONVERSION: no route, no menu entry, no partial, no API field -- I could find no way to reach any conversion figure, because none exists" and "CHANNEL ANALYSIS, TRAFFIC SENSE -- absent", and both are wrong as written: there are TWO routed, menu-reachable, merchant-facing surfaces whose whole purpose is third-party conversion/device/channel analytics, and the measurer named neither. Both are inert, for reasons it never found.

Citations: `routes/web.php:749`, `resources/views/setting/index.blade.php:189-193`, `app/Helper/helper.php:968-1200`, `resources/views/front_end/layouts/app.blade.php:62-66`, `resources/views/AppSetting/index.blade.php:326-340`, `resources/views/front_end/jQueryRoute.blade.php:253, 264, 328-332` and 7 more.

**Lens — does-it-actually-work: CHALLENGED** (confidence: certain)

The STATUS LABEL survives — I could not find working conversion or channel analysis, and the device code genuinely exists — but two load-bearing claims inside the verdict are refuted, and one of them hides a second broken surface the sweep never looked for.

Citations: `resources/views/theme_analytic.blade.php:121-167`, `resources/views/theme_analytic.blade.php:256-354`, `resources/views/front_end/jQueryRoute.blade.php:253, 264, 328, 333`, `app/Providers/ViewServiceProvider.php:23, 93-104`, `resources/views/front_end/layouts/app.blade.php:71`, `app/Http/Controllers/AppSettingController.php:289-299` and 5 more.

---

### `ANA-005` — Separate sales, collection, settlements and profitability

> الفصل بين المبيعات والتحصيل والتسويات والربحية.

**Measured: PARTIALLY IMPLEMENTED** (confidence: certain)

**Finding.** The metric layer separates all four figures rigorously and correctly, but it has ZERO production callers (only tests), and on user-facing surfaces only SALES is rendered as a separated, labelled figure — collection, settlements and profitability exist solely as per-order row columns and wallet running balances, with no aggregate of either anywhere in the codebase.

**What is missing**

- COLLECTION HAS NO FIGURE. Measured: `sum('paid_amount')`, `SUM(paid_amount)` and `sum('remaining_amount')` appear ZERO times across app/, resources/ and packages/. A merchant can read what one order paid, 15 rows at a time, but cannot learn what was collected in a period. `CollectedCustomerPayments` computes exactly this and is never called.
- PROFITABILITY HAS NO FIGURE. Measured: `sum('gross_profit')` and `SUM(gross_profit)` appear ZERO times across app/, resources/ and packages/. The only aggregate word 'Profit' on any screen is 'Potential Profit' on /finance, which is inventory retail value minus inventory cost — an inventory valuation of unsold stock, not realised profitability. `StoreRevenueMargin` computes realised margin and is never called.
- SETTLEMENTS ARE PRESENT BUT NOT AS A PERIOD FIGURE. /finance shows five wallet running balances; there is no settled-in-this-period figure, and `NetFinancialReceipts` — the metric that answers it, complete with its two-computation reconciliation — has no caller. So the reconciliation defect it is built to surface can never reach a merchant.
- THE METRIC LAYER IS UNREACHABLE BY ANY USER. Measured: `Support\Metrics` and all five metric keys (gmv, collected_customer_payments, net_financial_receipts, merchant_wallet_entitlement, store_revenue_margin) appear ZERO times in app/, routes/, config/, resources/, packages/ and database/. The only referencing files are tests/Unit/Metrics/MetricValueObjectTest.php, tests/Feature/Metrics/MetricLayerTest.php, …
- NO SURFACE PLACES THE FOUR SIDE BY SIDE. Sales lives under Reports and on /dashboard; collection and profit live as columns inside one tab of /finance; settlement lives as cards on /finance. Nothing lets a merchant see that order value, payments received, settlement and margin disagree — which the proposal doc identifies as the whole point of separating them ('they disagree with each other on purpose').
- THE WALLET CARDS CARRY NO CAVEAT, NO PERIOD AND NO CURRENCY, and one is mislabelled. 'Total Income' (store_wallets.total_income) is a settlement-ledger lifetime credit total presented as bare `<h3>{{ number_format(...) }}` with no hint — the exact labelling `MerchantWalletEntitlement`'s docblock prohibits ('IT IS NOT REVENUE AND MUST NEVER BE LABELLED AS SUCH IN ANY UI'). The sales surfaces use x-ss.stat with :hint …
- THE COST_UNKNOWN CAVEAT IS COMPUTED BUT NEVER RENDERED. StoreRevenueMargin attaches MetricCaveat::COST_UNKNOWN with notes()['rows_without_cost'] when product_cost <= 0 — where the column reports the whole sale as profit. /finance's Profit column prints gross_profit raw with no such warning, so a pre-P1-SEC-44 row renders a 100% margin as fact.
- THE FOURTH FIGURE'S USER-VISIBLE LABELS AND INCLUSION RULES ARE STILL AWAITING A DECISION. The labelling work exists as a proposal requiring owner sign-off, and the ledger row that would wire the layer into the reporting surfaces (P5-3, 'ReportController predicate replacement, with a stated delta per surface') is still open.

**Cited as present**

- `app/Support/Metrics/` (whole directory) — The metric layer: 21 files, one query object per metric. All four figures ARE separated here — distinct classes, distinct source tables (Metric.php finalises …
- `app/Support/Metrics/GrossMerchandiseValue.php:1-96` — FIGURE 1, SALES. Sums orders.final_price dated by orders.order_date; the docblock calls it 'a demand measure, not a money measure' and the only metric for which SUM(orders.final_price) is legitimate. …
- `app/Support/Metrics/CollectedCustomerPayments.php:1-80` — FIGURE 2, COLLECTION. Reads store_order_financials.paid_amount dated by paid_at (not order date), with the F124 limb including cancelled/refunded/partially_paid rows whose paid_amount > 0 — so a …
- `app/Support/Metrics/NetFinancialReceipts.php:1-250` — FIGURE 3a, SETTLEMENT. Two independent computations that must agree: a movement sum over store_wallet_transactions where reference_type='order', cross-checked against status-based (collected − …
- `app/Support/Metrics/MerchantWalletEntitlement.php:1-180` — FIGURE 3b, SETTLEMENT. store_wallets.available_balance, with datedBy() deliberately null because a running balance is not a period figure. Docblock: 'IT IS NOT REVENUE AND MUST NEVER BE LABELLED AS …
- `app/Support/Metrics/StoreRevenueMargin.php:1-130` — FIGURE 4, PROFITABILITY. store_order_financials.gross_profit = (product_amount − discount_amount) − product_cost. Uses payment_status='paid' ONLY — narrower than figure 2 on purpose: 'Section 2 …
- `app/Support/Metrics/MetricResult.php:22-33, 180` — The separation vocabulary: RECORD_SETTLEMENT / RECORD_ENTITLEMENT / RECORD_MOVEMENT, so every figure states which record it came from. Plus UNKNOWN_CURRENCY handling.
- `resources/views/reports/_sales_stats.blade.php:13-41` — SALES, user-facing and properly separated. x-ss.stats 'Sales summary' with 'Order value, including shipping and tax' and 'Order value', each carrying a :hint that explicitly disclaims the other three …
- `resources/views/dashboard/overview.blade.php:342-386` — SALES on the merchant dashboard. 'Order value' stat, period 'all time, gross, excluding cancelled', hint 'This is not payments received, and not profit.' Gated by …
- `resources/views/order/partials/preview.blade.php:246-254` — Per-order sales figure labelled 'Order value' with an x-ss.help: 'Order value is what this order was worth when placed. It is not revenue and not profit.'
- `resources/views/finance/index.blade.php:88-180 (wallet + inventory cards), 405-520 (per-order table)` — The only surface carrying collection and profitability at all, and only PER ORDER. The 'Order Financial Records' table has columns Total / Cost / Profit (gross_profit) / Paid (paid_amount) / …
- `app/Http/Controllers/StoreFinanceController.php` (index() ~line 800-1070, orderFinancials at 959-969, inventory at 977-1060) — index() passes wallet, transactions, orderFinancials (a raw paginated DB::table('store_order_financials') list — no aggregation), and four inventory figures. Gated on 'View Finance Dashboard'; …
- …and 3 further citations in the run record

**Lens — missed-implementation: verdict stands** (confidence: certain)

STATUS UNCHANGED (PARTIALLY_IMPLEMENTED). I searched differently and found no implementation of the store's four-figure separation that the measurer missed — so no status refutation. But I found four things it missed, and one of them materially corrects its characterisation, in the opposite direction from a refutation: the verdict is too GENEROUS, not too harsh.

Citations: `app/Models/Customer.php:270-299`, `app/Models/ActivityLog.php:209-224`, `resources/views/customer/timeline.blade.php:54-57`, `resources/lang/ar.json:3076`, `app/DataTables/CustomerDataTable.php:92-97, 197`, `app/Http/Controllers/Api/DashboardController.php:6556, 6607, 8862, 8977` and 8 more.

**Lens — does-it-actually-work: CHALLENGED** (confidence: certain)

The STATUS LABEL survives — PARTIALLY_IMPLEMENTED is right, and I independently re-confirmed the central claim (all 13 metric classes in app/Support/Metrics/ have ZERO non-test, non-self references anywhere in the repo, packages/, routes/, config/, database/ and app/Console included). But THREE of the verdict's affirmative "it exists and works" claims do not hold, and all three concern the one figure it says is working.

Citations: `resources/views/components/ss/stat.blade.php:39-41`, `public/css/store-syria-ds.css:2238-2246`, `resources/views/reports/_sales_stats.blade.php:15,17,20,22,35,39`, `tests/Feature/DesignSystem/ChartMoneyPrecisionTest.php:753-757`, `app/Http/Controllers/ReportController.php:582-585, 939-941`, `routes/web.php:240-242` and 4 more.

---

### `ANA-006` — Do NOT claim precise profitability when cost or fee data is missing

> عدم ادعاء ربحية دقيقة عندما تكون بيانات التكلفة أو الرسوم ناقصة.

**Measured: PARTIALLY IMPLEMENTED** (confidence: certain)

**Finding.** The prohibition is respected on every surface that renders no profit (dashboard, 11 report screens, product preview, admin API, Flutter app — several with explicit "not profit" hints pinned by tests), but it is VIOLATED on /finance, the only surface in either repo that actually renders a profit figure: two of them, with zero disclosure, while the COST_UNKNOWN caveat machinery that exists for exactly this purpose is referenced by nothing outside tests.

**What is missing**

- Cost-data disclosure on the 'Potential Profit' tile (finance/index.blade.php:159-176). It presents inventoryRetailValue minus inventoryCost as profit while treating every product whose cost was never entered as costing 0.00 — contributing that product's full retail value to the figure. In the measured production dump 36 of 45 products read 0.00. No caveat, no coverage count, no hint is rendered.
- Cost-data disclosure on the per-order 'Profit' column (finance/index.blade.php:417, 434-438). It prints store_order_financials.gross_profit raw. In the measured production dump 89 of 92 rows carry product_cost = 0 and a gross_profit equal to full revenue — i.e. almost every row on this screen reports a 100% margin as a precise two-decimal figure.
- Any wiring of the caveat machinery to a user-facing surface. MetricCaveat and StoreRevenueMargin are referenced only by tests/Feature/Metrics/MetricLayerTest.php and tests/Support/Security/bin/verify-metric-layer-on-mysql.php; a grep for 'MetricCaveat' and for 'Support\\Metrics' across app/, resources/, routes/ and packages/workdo/ returns zero hits outside app/Support/Metrics/ itself. The COST_UNKNOWN sentence …
- A fee caveat, and the fee data behind it. The requirement names cost OR FEE data. gross_profit is (product_amount - discount_amount) - product_cost, so it silently excludes payment/gateway/processing fees entirely; store_order_financials has no fee column in the measured production DDL, and MetricCaveat has no fee constant at all. EXCLUDES_SHIPPING_AND_TAX covers only shipping and tax, and renders nowhere anyway.
- Schema ability to distinguish 'cost unknown' from 'cost is genuinely zero'. products.cost_price is NOT NULL DEFAULT 0.00, so the two are stored identically and no surface could disclose the difference even if it tried. The migration header records this as an open owner decision, not a closed one.
- A regression test for the violating surface. The 'not profit' hints on the dashboard and the sale-by reports are pinned (DashboardOrderValueTest:258, SaleByReportHonestyTest:85); nothing pins disclosure on /finance. The four tests that do touch finance/index.blade.php (FinanceMoneyFigureContrastTest, FinanceMoneyFiguresTest, FinanceTablesUseDesignSystemTest, DarkPageSurfaceTest) assert contrast, tabular numerals and …

**Cited as present**

- `app/Support/Metrics/MetricCaveat.php:28-40, 152-156` — The caveat vocabulary. COST_UNKNOWN (line ~30) with message 'Cost price was not recorded for some orders, so margin is overstated for those rows.' (line 154), and EXCLUDES_SHIPPING_AND_TAX (line …
- `app/Support/Metrics/StoreRevenueMargin.php:30-120` — Margin metric that attaches EXCLUDES_SHIPPING_AND_TAX unconditionally and COST_UNKNOWN conditionally via rowsWithoutCost(), with the count exposed as notes()['rows_without_cost'] and the status …
- `resources/views/dashboard/overview.blade.php:22-29, 376` — RESPECTS the prohibition: renders no profit figure, and the 'Order value' tile hint says explicitly 'This is not payments received, and not profit.' (line 376). NOTE a stale claim at line 29 — 'There …
- `resources/views/reports/_sales_stats.blade.php:17, 22` — RESPECTS it. No profit figure; hints state 'not what they paid, and not profit' and 'This is not payments received, and not profit.' Same pattern in order_brand_chart.blade.php:22, …
- `resources/views/product/partials/preview.blade.php:68-70` — RESPECTS it, with an explicit disclosure: <x-ss.help> 'This is the listed price. It is not revenue, and no margin is calculated here.' (Its reasoning at 68-69 — 'cost_price is a GATE F column with no …
- `tests/Feature/Merchant/DashboardOrderValueTest.php:258` — Pins the dashboard's 'not profit' hint text (line 258). tests/Feature/DesignSystem/SaleByReportHonestyTest.php:85 pins the same sentence on the sale-by reports. So the respected half is …
- `/home/user/StoreSyria_app98/lib/view/screen/product_screen/product_detail_screen.dart:343, 391-405` — The shipped admin Flutter app RESPECTS it. Across 326 .dart files there is no profit or margin figure at all; cost_price appears only as a form input (add_new_product_screen.dart:477-492) and a …
- `resources/views/finance/index.blade.php:159-176, 417, 434-438` — THE VIOLATING SURFACE. Line 159 'Potential Profit' tile renders $inventoryPotentialProfit; line 417 a 'Profit' table header, lines 434-438 render $financial->gross_profit per order. The file has ZERO …
- `app/Http/Controllers/StoreFinanceController.php:736, 982-1073` — index() (line 736) computes both violating figures and passes neither a caveat nor a coverage count. $costPrice = (float)($product->cost_price ?? 0) at 988-989; $inventoryPotentialProfit = …
- `database/migrations/2026_10_26_000100_add_cost_price_to_products_table.php:33-42, 60-70` — Quantifies the gap from a restored production dump, in the repo's own words: 'in the same dump 36 of 45 products read 0.00 while 89 of 92 store_order_financials rows carry product_cost = 0 and a …
- `app/Support/Database/GateFProductionSchema.php:125-152` — The measured production DDL for store_order_financials. Columns are product_amount, discount_amount, tax_amount, shipping_amount, customer_total, product_cost, gross_profit, paid_amount, …

**Lens — missed-implementation: verdict stands** (confidence: certain)

THE VERDICT SURVIVES. I searched for a missed implementation along six axes the measurer did not use, and every one came back empty or came back strengthening their claim. PARTIALLY_IMPLEMENTED stands.

Citations: `config/merchant-navigation.php:53, 63`, `resources/lang/ar.json:1894, 2137, 2267, 2936-2939, 2992`, `app/Http/Controllers/StoreFinanceController.php:977-1073`, `app/Support/Database/GateFProductionSchema.php:389, 395`, `tests/Support/Security/GateFReconciliation.php:254-300`, `tests/Unit/Ui/SettingsTabRailKeyboardTest.php:199, 338` and 4 more.

**Lens — does-it-actually-work: CHALLENGED** (confidence: probable)

THE LABEL SURVIVES; THE MECHANISM FOR ONE OF ITS TWO VIOLATION SITES DOES NOT. The verdict's load-bearing claim about the "Potential Profit" tile is wrong, and wrong in the opposite direction.

Citations: `app/Http/Controllers/StoreFinanceController.php:1041-1046`, `database/migrations/2023_12_26_092822_create_products_table.php:32-33`, `app/Http/Controllers/ProductController.php:632, 659, 832, 836, 1939, 2226`, `app/Http/Controllers/Api/DashboardController.php:3093, 3169, 3256, 3333`, `app/Models/ProductVariant.php:26`, `tests/Unit/Ui/FinanceMoneyFigureContrastTest.php:125-131` and 3 more.

---

### `ANA-007` — Show data quality, estimates and omissions

> إظهار جودة البيانات والتقديرات والنواقص.

**Measured: PARTIALLY IMPLEMENTED** (confidence: certain)

**Finding.** Omissions are disclosed visibly on ~16 report/dashboard surfaces and data quality on exactly one finance surface, but estimates are disclosed nowhere, and the 12-key MetricCaveat mechanism built for all three has zero consumers outside app/Support/Metrics and tests — so no caveat it carries can reach a merchant.

**What is missing**

- ESTIMATES (التقديرات) — no merchant analytics surface labels any figure as an estimate, approximation or assumption. Greps for estimate/approximate/assumed/assumption/provisional/unreliable across every non-storefront Blade view returned only code comments plus one platform marketing line ('Nothing on this site is an estimate.', resources/views/platform/home.blade.php:109). The only user-facing 'Estimated' strings …
- DATA QUALITY — the whole caveat mechanism is unwired. A repo-wide grep for 'caveat' (excluding vendor/node_modules) hits exactly 11 files under app/Support/Metrics/ and 7 files under tests/. Zero Blade views, zero controllers, zero API resources, zero routes. So none of the 12 caveats, isAuthoritative(), caveatMessages() or notes() can reach a merchant on any surface.
- DATA QUALITY — no surface anywhere tells a merchant that a figure is non-authoritative. COUPON_REDEMPTION_UNRELIABLE is the single BLOCKING caveat ('This figure is not authoritative', by owner instruction pending P4-F134/F135) and nothing renders it; the coupon figure at resources/views/reports/_sales_stats.blade.php:38-41 ('Coupon value applied') prints as a plain money total with no reliability statement at all.
- DEFECT — <x-ss.stat>'s only caveat slot is permanently invisible, not merely hover-only. stat.blade.php:40 puts `hint` inside <span class="ss-visually-hidden">; store-syria-ds.css:2238-2246 clips it to 1x1 with clip: rect(0,0,0,0); there is no :hover, :focus or :focus-within override in ANY stylesheet under public/css/ and no .ss-stat__hint class exists. Nine hint sentences carrying the strongest caveats in the …
- DEFECT — the honest-unavailable branch is dead code. stat.blade.php:28-33 renders __('Not available') for a null value, documented as 'the system cannot produce this figure ... must not look like a zero'. Not one of the 20 <x-ss.stat> call sites passes null: every value expression coerces absence into a confident number, e.g. $currency . (!empty($TotalgrossSale) ? $TotalgrossSale : '0.00') at …
- FLUTTER ADMIN APP — the contract carries no quality information. POST api/admin/dashboard (app/Http/Controllers/Api/DashboardController.php:1239) returns products, sales, order, product, recent_order, coupons, orderchart, visitorchart as bare values with no caveat field, no period label and no omission field. 'sales' is Order::where('delivered_status', 1)->count() (line 1344) — a count of delivered orders published …
- UNVERIFIED BY SUITE — every report body carrying the visible notes is a ReportController surface, which per the project's own record cannot execute under the test suite at all (36 MONTH( / 9 YEAR( in selectRaw, absent from SQLite). The notes and hints on reports/_sales_stats, chart_data, top_5_reports, order_country_Report, order_*_chart have therefore never been rendered in a Feature test; I read them statically …

**Cited as present**

- `resources/views/components/ss/card.blade.php:2-13` — `note` prop renders as `<p class="ss-page-header__subtitle">` (line 9). That class is ordinary visible muted text (public/css/store-syria-ds.css:763-767), so this IS the working visible disclosure …
- `resources/views/reports/chart_data.blade.php:29-30, 43-44` — Two visible `:note=` inclusion-rule statements: 'Counts every order placed in the period, whatever its status or value. Not revenue.' and 'Counts accounts registered, and guests seen for the first …
- `resources/views/reports/top_5_reports.blade.php:43, 56, 69, 82` — Four visible `:note=` statements naming what is and is not counted, e.g. 'Counts orders, not quantity or value' and 'Spellings of the same method are counted together.'
- `resources/views/reports/order_country_Report.blade.php:52, 78` — Visible `:note=` 'Counts orders that carry a billing country. Counts orders, not value.' — an explicit statement of which rows are omitted.
- `resources/views/components/ss/stat.blade.php:11-18, 28-33` — `period` is a REQUIRED prop with no default, so no figure can render without naming its window — structural, not a convention. Also holds the honest-unavailable branch rendering __('Not available') …
- `resources/views/dashboard/overview.blade.php:370-376` — The one place an exclusion is put in the VISIBLE period line rather than the invisible hint: :period="__('all time, gross, excluding cancelled')". The adjacent comment states the reasoning (an …
- `resources/views/finance/unreconciled-captures.blade.php:55-59` — THE ONE REAL DATA-QUALITY SURFACE. Renders a visible role="status" warning when the gateway-reference table is absent: 'The payment reference record is not installed on this environment, so this list …
- `app/Http/Controllers/StoreFinanceController.php:636-669` — unreconciledCaptures() refuses to render rather than show an empty list when the store does not resolve, with the reason stated in the refusal — because 'an empty list is indistinguishable from a …
- `app/Support/Metrics/MetricCaveat.php:1-209` — The data-quality/estimates VOCABULARY: 12 caveat keys (COST_UNKNOWN, EXCLUDES_SHIPPING_AND_TAX, PARTIAL_REFUNDS_EXCLUDED, CANCEL_DATE_INCOMPLETE, COUPON_REDEMPTION_UNRELIABLE, MIXED_CURRENCY, …
- `app/Support/Metrics/MetricResult.php:243-330` — caveats(), caveatMessages(), isAuthoritative(), notes(), unrecordedCurrencyAmount(), and assumingUnknownCurrencyIs() — the ESTIMATES limb's mechanism: it folds the unknown-currency bucket, attaches …
- `app/Support/Metrics/NetFinancialReceipts.php:60-140` — reconcile() reports the settlement-vs-movement figures and their per-currency difference, attaching RECONCILIATION_DIVERGENCE. Its own docblock says this 'is what a reconciliation report should …
- `app/Listeners/CompanyMenuListener.php:418-467` — Menu registration proving reachability: top-level 'Reports' (permission Manage Reports) with children Customer Reports (reports.index) and Order Reports -> Sales Report (reports.order_report).

**Lens — missed-implementation: CHALLENGED** (confidence: certain)

The STATUS LABEL survives — estimates really are absent and the MetricCaveat layer really is unreachable (I re-ran the consumer grep a different way, `grep -rn "App\\Support\\Metrics"` over the whole tree minus vendor/node_modules/tests/app-Support-Metrics, and it returned EMPTY). But four of the verdict's load-bearing claims are false, and the errors run in BOTH directions.

Citations: `resources/views/reports/low_stock_status.blade.php:48-50`, `app/Support/DsBadge.php:39-48`, `public/css/store-syria-ds.css:1444-1456`, `app/Http/Controllers/ReportController.php:3376-3394`, `resources/views/reports/order_country_Report.blade.php:62-71, 84-88`, `tests/Feature/DesignSystem/CountryReportHonestyTest.php:76-80, 95-98, 119-130` and 6 more.

**Lens — does-it-actually-work: CHALLENGED** (confidence: certain)

The label PARTIALLY_IMPLEMENTED survives, but the single piece of evidence carrying the entire DATA QUALITY limb does not. The verdict calls finance/unreconciled-captures "THE ONE REAL DATA-QUALITY SURFACE" and asserts it is "reached from the Finance screen via its breadcrumb". Both halves fail.

Citations: `resources/views/finance/unreconciled-captures.blade.php:20-28, 41-43, 54-59, 61-64`, `app/Http/Controllers/StoreFinanceController.php:704, 712-721, 730-733`, `app/Services/GatewayReference.php:54, 329-332`, `database/migrations/2026_10_30_000100_create_order_gateway_references_table.php:69-107`, `routes/web.php:234-242`, `app/Listeners/CompanyMenuListener.php:387-396, 441, 467, 480, 493, 506, 519, 531, 543, 556, 569` and 6 more.

---

### `ANA-008` — Document metric definitions and their sources

> توثيق تعريفات المؤشرات ومصادرها.

**Measured: PARTIALLY IMPLEMENTED** (confidence: certain)

**Finding.** A real metric-definitions document exists and 10 of the 11 implemented metrics have a definition AND a named source there, measured against code by 48 verification tests — but the eleventh metric (CustomerRetention) has no section at all and its source is documented wrongly elsewhere, three source statements (§3 and §4 amount columns, §5's record) disagree with or omit what the code reads, nothing pins doc-to-code linkage, and no source is documented on any user-reachable surface.

**What is missing**

- CustomerRetention has NO section in the metric-definitions document. It is an implemented metric (app/Support/Metrics/CustomerRetention.php + RetentionFigures.php, exercised by 9 of the 48 tests), but `grep -i 'retention\|repeat' docs/development/phase-5/PHASE-05-METRIC-DEFINITIONS.md` returns nothing. Its definition() returns 'PHASE-05-EXECUTION-PLAN.md P5-6', not a section of the definitions doc. GAP-160 in …
- Retention's documented SOURCE is wrong. PHASE-05-EXECUTION-PLAN.md:94 says 'Derived from `orders` + `customers`, no new events invented.' Measured: CustomerRetention queries `orders` and joins `store_order_financials` (settled basis), and never references the `customers` table in any of its four queries. The one document that names retention's sources names a table the code does not read and omits the table it does.
- §3's amount source column is documented nowhere. The definitions doc gives §3's source as 'store_order_financials.payment_status with orders.payment_type' — the predicate columns only. UnpaidOrders actually sums `store_order_financials.remaining_amount`; `grep -rn remaining_amount docs/development/phase-5/` returns no hit.
- §4's amount source column is documented nowhere. The definitions doc gives §4's source as 'store_order_financials.payment_status = cancelled, dated by orders.cancel_date'. CancelledOrders actually sums `store_order_financials.customer_total`; the only mention of that column anywhere in docs/development/phase-5/ is an unrelated label note in PHASE-05-P5-3-PREREQUISITES-AND-FINDINGS.md:90.
- §5's documented source is half wrong. Line 149 reads 'Source. store_order_financials.payment_status = refunded plus the store_wallet_transactions row of type order_refunded.' Measured: CompletedRefunds reads ONLY store_wallet_transactions (type=order_refunded, reference_type=order) and never touches store_order_financials. A reader reconciling the figure against the entitlement status would get a different number.
- §5's real exclusion rule is undocumented. The code excludes voided movements with `->where('store_wallet_transactions.status', '<>', 'cancelled')`, and a test drives it ('a voided refund movement returns nothing and is not counted'). No document states that exclusion — grep for 'voided' across docs/development/phase-5/ returns nothing.
- PHASE-05-METRIC-LAYER.md has gone stale against the code it documents, in three places: line 13 'Ten query objects under app/Support/Metrics/, one per section' (eleven metric classes exist); lines 79 and 253 'MetricCaveat holds nine keys' (twelve constants, twelve MESSAGES entries); and line 254's ANA-008 claim 'Every metric's definition() returns its section of PHASE-05-METRIC-DEFINITIONS.md', which is false for …
- Nothing pins the document-to-code linkage. `grep -rn 'definition()' tests/` returns zero hits: no test asserts that a metric's definition() cites a section that exists in the document, that every section has an implementing class, or that every class has a section. The citation can rot silently, and for retention it already has.
- No source is documented on any user-reachable surface, and the bilingual documentation centre has no analytics article. GET platform/docs (PlatformDocsController, content in resources/docs/{ar,en}) holds 5 categories — getting-started, your-storefront, products, orders, your-account — and `grep -rli 'metric|gross|revenue|مؤشر' resources/docs/` returns nothing. No route in `php artisan route:list` serves a metric …
- The merchant-facing definitions are screen-reader-only and cover 8 of 14 report tiles. resources/views/components/ss/stat.blade.php:39-41 renders $hint inside `<span class="ss-visually-hidden">`, which public/css/store-syria-ds.css:2238 defines as `position:absolute; width:1px; height:1px; clip:rect(0,0,0,0)` — announced to assistive technology, never visible to a sighted merchant. Across resources/views/reports/ …
- The documented definitions describe a layer no surface uses. `grep -rn 'Support\\Metrics' app/ packages/ routes/` returns zero consumers outside the namespace itself — only tests and tests/Support/Security/bin/verify-metric-layer-on-mysql.php. The 70 inventoried money surfaces are still ReportController's inline computations (P5-3 pending), so for the figures a merchant actually sees, definition-and-source …
- All twelve caveat sentences are absent from Arabic, although PHASE-05-METRIC-LAYER.md:253 says they are 'resolved bilingually'. Checked all 12 MESSAGES values against resources/lang/ar.json: 12 of 12 missing, so __() falls through to English for an Arabic merchant.

**Cited as present**

- `docs/development/phase-5/PHASE-05-METRIC-DEFINITIONS.md` (whole file (13,238 bytes); ANA-008 claim at 34; §5 source at 149) — The document the requirement points at, and it is substantive, not a stub: §1-§10 each carry an explicit **Definition**, **Source** (named table.column), **Includes**, **Excludes** and a named …
- `docs/development/phase-5/PHASE-05-METRIC-LAYER.md:13-31, 88-99, 240-266` — The second half of the source documentation: a per-metric table giving each §'s class, the RECORD it measures (settlement/entitlement/movement) and the exact column it is DATED BY (lines 13-31); a …
- `app/Support/Metrics/Metric.php:42-43 (definition), 58-94 (the four source accessors)` — Definition traceability is in the type system, not just prose: `abstract public function definition(): string` with the docblock 'The section of PHASE-05-METRIC-DEFINITIONS.md this metric …
- `app/Support/Metrics/:11 files` — Eleven metric classes. Ten return their definitions-doc section from definition(): GrossMerchandiseValue §1, CollectedCustomerPayments §2, UnpaidOrders §3, CancelledOrders §4, CompletedRefunds §5, …
- `app/Support/Metrics/MetricCaveat.php:24-175` — Twelve caveat constants, each with a docblock citing the measured property and the section it belongs to (cost_price snapshot possibly 0; margin excludes shipping/tax; partial refunds …
- `tests/Feature/Metrics/MetricLayerTest.php:48 test methods, 149-1400` — This is why the definitions can be called measured rather than asserted: 48 tests implementing the 'Verification test' line under §1-§10 plus retention, including the ones the definitions name …
- `docs/development/phase-5/PHASE-05-FOUR-FIGURE-LABELLING-PROPOSAL.md:1-55` — Definitions and sources for the four MERCHANT-VISIBLE figures: each gets a record, the metric class, the source table.column, EN and AR labels, explicit includes/excludes, the column it is dated by, …
- `resources/views/reports/_sales_stats.blade.php:14-40` — The only merchant-facing metric definitions in the product: plain-language :hint text on the sales-summary tiles, e.g. 'The full amount the orders came to: after coupons, and including shipping and …

**Lens — missed-implementation: verdict stands** (confidence: certain)

The status survives, but the verdict's "what is missing" list contains three overstated absence claims about merchant-visible documentation, all caused by one scoping error: the census grepped only `:hint=` inside `resources/views/reports/`.

Citations: `resources/views/dashboard/overview.blade.php:368-376, 500, 519, 561`, `resources/views/components/ss/card.blade.php:9`, `public/css/store-syria-ds.css:763-767, 1863`, `resources/views/reports/top_5_reports.blade.php:43, 56, 69, 82`, `resources/views/reports/chart_data.blade.php:30, 44`, `resources/views/components/ss/stat.blade.php:4-7, 37-42` and 5 more.

**Lens — does-it-actually-work: CHALLENGED** (confidence: certain)

The status label survives, but one of the verdict's "exists and is pinned" claims does not. The verdict characterises the merchant-reachable definition layer as merely HIDDEN ("screen-reader-only", "8 of 14 tiles") and says its arithmetic "is pinned by tests/Unit/Reports/SalesSummaryLabelTruthTest.php, so a relabel cannot silently move the figure". Measured: the one definitional statement a SIGHTED merchant can read on those screens is FALSE, it renders in both languages, and the cited guard test does not catch it.

Citations: `resources/views/reports/order_chart_data.blade.php:13, 23, 33`, `resources/views/reports/order_bar_chart_data.blade.php:43`, `app/Http/Controllers/ReportController.php:568-569, 590-591, 725-731, 943-944, 939, 962`, `app/Models/Cart.php:716, 718`, `tests/Unit/Reports/SalesSummaryLabelTruthTest.php:44-50, 57-67, 81-84, 94-101`, `resources/views/components/ss/card.blade.php:5-12` and 3 more.

---

### `ANA-009` — Alerts and proactive analytics, with clear controls

> تنبيهات وتحليلات استباقية بضوابط واضحة.

**Measured: PARTIALLY IMPLEMENTED** (confidence: probable)

**Finding.** Inventory alerts exist in two forms (Twilio SMS fired inline in the order-write path, and a pull-based "Needs attention" region on the dashboard/order list) and stock controls exist and are reachable, but proactive ANALYTICS alerting is absent entirely (no scheduler job, no anomaly/trend/forecast code, no alert rules), and the controls are materially broken on four measured counts — including a store-level "Low Stock Threshold" that governs no alert and a both-off opt-out that fails validation.

**What is missing**

- Proactive ANALYTICS alerts of any kind. No sales-drop, trend, anomaly, forecast or threshold-on-a-metric detection exists anywhere in app/ or packages/workdo/ — a repo-wide grep for sales drop / anomal / forecast / predictive / alert rule / threshold alert returned only unrelated prose (module marketplace seeder copy, three code comments using 'anomaly' about foreign rows). The metric layer computes figures and …
- Any scheduled or background evaluation. routes/console.php schedules only `inspire` and `zid:process-events`; there is no app/Jobs directory and app/Notifications contains only the two password-reset classes. Every alert in the codebase is fired synchronously inside a checkout request, so nothing can detect a condition that develops between orders (a sales drop, a stall, a stock level reached by a refund or a manual …
- A store-level threshold control that actually governs the low-stock alert. SettingController::StockSettings writes low_stock_threshold (1331) and stock_setting.blade.php displays it (30), but the alert path compares against the per-PRODUCT column with no fallback (PaymentService 2806/2830/2887), and every other low-stock surface falls back to out_of_stock_threshold instead (ProductDataTable::stockFallbackThreshold, …
- A working opt-out. SettingController::StockSettings:1323 validates 'notification' => 'required', so a merchant who unchecks both enable_low_stock and enable_out_of_stock gets a validation error and no save at all — the stock alerts cannot be switched off from the only screen that offers the switches, and threshold edits are blocked while both are off.
- A per-product threshold that survives editing. product/edit.blade.php:299 passes the store setting as the explicit $value inside Form::model($product), and konekt/html FormBuilder::getValueAttribute:1318 prefers that over the bound model's attribute, so the saved per-product threshold is neither shown nor preserved; ProductController::update:2118 writes the posted store value back over it.
- An advance warning in the default configuration. With the per-product threshold at its default 0 (ProductController 1159/1163, 2118/2122) and no fallback applied in the alert path, the low-stock SMS predicate is product_stock <= 0 — it fires only once stock is already exhausted, duplicating the out-of-stock alert.
- Any delivery channel for inventory alerts other than Twilio SMS. No 'Low Stock' email template exists in database/seeders or database/migrations, and MerchantNotificationController::TYPE_PERMISSIONS registers only order_created / order_paid / payment_needs_attention and refuses unregistered types — so the in-app bell carries no stock or analytics alert, and a merchant without a paid Twilio account receives no alert …
- Controls for the in-app 'Needs attention' region. The dashboard and order-list attention items (pending orders, out of stock, low stock, returns) have no configurable threshold, no mute/dismiss and no opt-out; they are hard-coded to count > 0.
- Alert state of any kind: no de-duplication, no acknowledgement, no history. Utility::low_stock_threshold sends an SMS on every qualifying order line with no record that it was sent, so a product sitting below its threshold re-alerts on every subsequent order (contrast StoreNotificationService::orderPaid, which does check for an existing row before inserting).
- Flutter admin parity for inventory alerts. Api/DashboardController exposes out_of_stock_threshold and a per-product stock_status (1519-1573) but no low-stock count and no attention region, and there is no api/admin endpoint for the stock settings — so the shipped admin app can neither see the low-stock alert nor change its controls.

**Cited as present**

- `app/Services/PaymentService.php:2755, 2790, 2806-2851, 2887-2910` — The low-stock / out-of-stock ALERT trigger, inline in the order-write path. Whole block nested under $settings['stock_management'] == 'on' (2790). Variant arm 2806-2851, plain-product arm 2887-2910. …
- `app/Models/Utility.php:110-113, 3460-3525, 3698-3712` — The alert messages and their only delivery channel. low_stock_threshold() 3479-3492, out_of_stock() 3494-3509, variant_out_of_stock() 3511-3525, variant_low_stock_threshold() 3698-3712 — every one …
- `resources/views/setting/stock_setting.blade.php:26-67` — The merchant's alert CONTROLS: Low Stock Threshold and Out of Stock Threshold number inputs (26-39), and — only when auth()->user()->type == 'admin' (39) — the Enable Stock Management switch plus the …
- `app/Http/Controllers/SettingController.php:1309-1347` — StockSettings() — the control's write path, permission-gated by StoreSettingsAccess::permits (1313). DEFECT: the validator requires 'notification' (1323), so unchecking BOTH notification boxes posts …
- `app/DataTables/ProductDataTable.php:303-324, 343-380` — The shared low-stock PREDICATE used by the product list badge/filter, the dashboard count and the low-stock report: trackedStockState() 303-317 and scopeToLowStock() 343-352 (`product_stock <= …
- `app/Http/Controllers/HomeController.php:380-424, 487` — The in-app proactive surfacing: $out_of_stock_products (stock <= 0) and $low_stock_products via ProductDataTable::scopeToLowStock(), compacted into the dashboard payload (487). Also …
- `resources/views/dashboard/overview.blade.php:33-37, 44-48, 222-296` — The "Needs attention" region — the nearest thing to a proactive alert surface: pending orders, out-of-stock products, low-stock products and returned orders, each as an <x-ss.attention-item> linking …
- `resources/views/order/index.blade.php:75-102` — The second "Needs attention" region, on the order list. Same component, same absence of controls.
- `app/Http/Controllers/MerchantNotificationController.php:63-140, 86-98` — The merchant notification centre (bell + full page). TRANSACTIONAL ONLY: TYPE_PERMISSIONS registers exactly order_created, order_paid, payment_needs_attention (86-98) and an unregistered type is …
- `resources/views/product/create.blade.php:25, 256-258` — The per-product threshold control (the only threshold the SMS alert actually reads). Prefilled from Utility::GetValueByName('low_stock_threshold') at 25, rendered at 256-258 with the help text "Leave …
- `resources/views/product/edit.blade.php:31, 39, 299-301` — DEFECT: inside Form::model($product) (39) the Low stock threshold input is given the STORE setting as its explicit $value (31, 299). konekt/html FormBuilder::getValueAttribute() returns the explicit …
- `app/Http/Controllers/ProductController.php:854-858, 1159-1163, 2118-2122` — store() writes low_stock_threshold defaulting to 0 / '' (854, 858, 1159, 1163); update() writes it defaulting to 0 (2118, 2122). With the default 0 and no fallback in the alert path, the "low stock" …
- …and 4 further citations in the run record

**Lens — missed-implementation: CHALLENGED** (confidence: probable)

The LABEL survives — genuinely proactive analytics alerting (a metric condition evaluated and raised) really is absent, and I confirmed that with searches the measurer did not run. But FOUR of the verdict's ten "what is missing" bullets are factually wrong, and an entire merchant analytics surface is absent from its evidence list. (1) "Any scheduled or background evaluation [missing]; routes/console.php schedules only inspire and zid:process-events" is false repo-wide: the first-party LynomiaChat module registers SIX scheduled commands in its own provider (LynomiaChatServiceProvider.php:113-145 — everyMinute x2, everyFiveMinutes, everyFifteenMinutes, hourly x2) inside app->booted(), which a …

Citations: `packages/workdo/LynomiaChat/src/app/Providers/LynomiaChatServiceProvider.php:98-146`, `packages/workdo/LynomiaChat/src/database/migrations/2026_10_28_000100_create_lynomia_platform_notices.php:38-78`, `packages/workdo/LynomiaChat/src/app/Services/InstallationLifecycle.php:246-285`, `packages/workdo/LynomiaChat/src/app/Services/PlatformNotifier.php:53-125`, `app/Services/PaymentService.php:2846-2870, 2899-2925`, `app/Models/Utility.php:613, 696-713` and 12 more.

**Lens — does-it-actually-work: CHALLENGED** (confidence: probable)

The STATUS survives — PARTIALLY_IMPLEMENTED is still right, and the verdict's central claim (no proactive analytics alerting anywhere; no scheduler; no alert rules) I re-measured and confirmed sound. But the verdict is wrong in BOTH directions on what works, and it misses the break that actually kills the in-app alert.

Citations: `app/Helper/helper.php:1346-1498`, `app/DataTables/ProductDataTable.php:303-324, 343-352, 118, 131-139`, `resources/views/product/create.blade.php:25, 256-258`, `app/Http/Controllers/Api/DashboardController.php:3189-3195 (0 occurrences of low_stock_threshold in the whole file)`, `resources/views/order/index.blade.php:72-102`, `resources/views/setting/stock_setting.blade.php:42-70` and 3 more.

---

### `ANA-010` — Do NOT build a new accounting engine

> عدم بناء محرك محاسبي جديد.

**Measured: VERIFIED COMPLETE** (confidence: probable)

**Finding.** Prohibition RESPECTED: the per-order P&L and wallet ledger are inherited, already-live production structures (80 ledger + 92 P&L rows measured in a restored dump before this phase), and everything the analytics work actually added — ten metric classes and a settlement/entitlement reconciliation — contains zero write statements and derives on read.

**Cited as present**

- `app/Support/Metrics/` (whole directory) — The Phase 05 metric layer, 21 files, added 2026-11-17 (commit 0794314c). THE DECIDING EVIDENCE: a grep of the whole namespace for ->insert( / ->update( / ->save() / ->create( / ->delete( / upsert( / …
- `app/Support/Metrics/Metric.php:1-90` — Base class that explicitly subordinates the layer to the pre-existing records as sources of truth, naming each: orders = 'the SETTLEMENT record', store_order_financials = 'the ENTITLEMENT record', …
- `app/Support/Metrics/GrossMerchandiseValue.php` (for() method) — A concrete metric traced end to end to confirm derivation-on-read: for() calls $this->orders($scope), applies the window to orders.order_date, adds the not-cancelled limb, then sumByCurrency(...) and …
- `database/migrations/2026_11_02_000400_create_store_order_financials_table.php:1-110` — THE STRONGEST-LOOKING CASE FOR VIOLATION, and it dissolves on reading: this is the per-order profit-and-loss table with stored DERIVED columns (gross_profit, product_cost, paid_amount, …
- `docs/GATE-F-VERIFIED-SCHEMA.md:77, 102-202` — PROVENANCE, measured rather than asserted (and flagged as a doc per instruction 1, but it reports a measurement off a restored dump, commit 3935dee8, read 2026-10-24, MariaDB 11.4.9): …
- `app/Services/OrderFinancialService.php:1-330` — The writer of the per-order P&L row and the wallet credit (available_balance += amount, total_income +=, plus a StoreWalletTransaction with balance_before/balance_after). This is engine-shaped code — …
- `app/Services/OrderEntitlementReversal.php:1-70, 236-290` — The one piece of RECENT work that writes into the ledger (added 2026-11-16, P4-F124): it updates store_order_financials, mutates wallet balances and creates a transaction typed …
- `tests/Support/Security/SettlementEntitlementReconciliation.php:1-48` — The P4-F128 settlement-to-entitlement reconciliation. Read-only by construction: 'THIS CLASS RESOLVES NOTHING... There is no INSERT, UPDATE or DELETE in it... The safety model is the absence of a …
- `packages/workdo/` `(v)` — All ~70 vendored modules enumerated (Campaigns, Marketing, BoostSales, RewardClubPoint, SpinToWinReward, PartialPayments, ProductPricing, Donation, PDFVouchers etc.). NONE contains any reference to …

**Lens — missed-implementation: verdict stands** (confidence: probable)

VERDICT STANDS (prohibition RESPECTED), but its deciding evidence is narrower than it claims and I found three things the measurer's sweep could not have found. Their census never opened `app/Http/Controllers/ReportController.php`, `Api/DashboardController.php`, `OrderRefundController.php`, `app/Support/Money/`, `app/Support/TopSellersByRevenue.php`, the 52 vendored migration directories, `_gate_f_pending/`, `config/`, the nav config, the scheduler, or the inherited baseline of the finance controller; and their migration sweep searched for the words rollup/summary/aggregate/snapshot/metric/journal, which matches none of the money tables this project actually created.

Citations: `app/Http/Controllers/StoreFinanceController.php:the f33e8525 version, 1,466 lines`, `app/Http/Controllers/ReportController.php:whole file, 3,835 lines`, `app/Http/Controllers/Api/DashboardController.php:10099-10147`, `app/Services/CodCollectionService.php:9-24, 95, 187-196, 241`, `database/migrations/_gate_f_pending/0009_draft_create_cod_collection_events_table.php.draft:5-16, 88-110`, `database/migrations/2026_10_30_000100_create_order_gateway_references_table.php:72-109` and 4 more.

**Lens — does-it-actually-work: verdict stands** (confidence: probable)

VERDICT SURVIVES. I attacked it five ways and every attack either confirmed it or strengthened it. Note first that this lens cuts backwards on a prohibition: for ANA-010, "unreachable" and "writes nothing" are what COMPLIANCE looks like, so finding dead code is not a refutation. To refute I had to find a new accounting engine that the measurer missed. I did not.

Citations: `resources/views/finance/index.blade.php:281-286`, `app/Http/Controllers/StoreFinanceController.php:736, 939-945`, `app/Services/OrderEntitlementReversal.php:93-96, 275-286`, `app/Support/Metrics/Metric.php:sumByCurrency()`, `app/Support/Metrics/StoreRevenueMargin.php:for(), marginRows()`, `app/Support/Metrics/NetFinancialReceipts.php:114-128` and 3 more.

---

## 4. What this survey caused to change in the code

One finding was actionable without an owner decision, and was acted on the same day as **P5-10**.

### The `/finance` inventory tiles

Lens 2 on `ANA-006` challenged the measuring pass's account of the "Potential Profit" tile and was
right to. Re-measured by hand and then reproduced as a failing test:

```php
// app/Http/Controllers/StoreFinanceController.php, as shipped
$sellingPrice = (float) ($product->sale_price ?? $product->price ?? 0);
```

`??` fires on `null` and nothing else, and `products.sale_price` is `float NOT NULL DEFAULT 0`. A
product that is simply **not on sale** therefore carried `0` here: it contributed nothing to
`Inventory Retail Value` while still contributing its full `cost_price × stock` to
`Inventory Capital`, so `Potential Profit` rendered `0 − cost` — **negative, in debit red**, for an
ordinary store. Measured on a one-product fixture: retail `0.00` where `1,000.00` is correct, and
profit `−600.00` where `+400.00` is correct.

The variant branch twenty lines earlier has the identical spelling and is **correct**, because
`product_variants.variation_price` is nullable and its fallthrough really does reach `price`. One
expression, two column definitions. That asymmetry is now pinned by two tests, so if the variant
column is ever made `NOT NULL` the suite says so.

The replacement is not a new judgement about what a zero sale price means — the product had already
decided, in `Product.php:323`, `product_price.blade.php:4`, `preview.blade.php:59` and
`ApiController.php:499/781/804`, all of which read `0` as "no sale price". One handler disagreed.

**Nothing stored moved.** These tiles are derived on read; no order total, ledger row, wallet
balance or customer-facing price is touched. The block was inherited at the Phase 2.5 merge
(`4e878b8d`, 2026-11-12) and is not Phase 05's own code.

### And the disclosure `ANA-006` actually asks for

`products.cost_price` is `decimal(15,2) NOT NULL DEFAULT 0.00`, so a cost nobody entered reads
`0.00` and is arithmetically indistinguishable from an item that genuinely cost nothing. Such a
product hands its **whole retail value** to Potential Profit with nothing subtracted — the error
pointing the other way, and one no expression can fix, because the data is absent.

So the figure is qualified instead of corrected, and the qualification is **measured**:

> Cost is not recorded for 1 of 2 in-stock products. Those products contribute 200.00 of retail
> value with no cost subtracted, so Potential Profit is overstated by up to that amount.

It names the count and the exact amount at stake rather than saying "some data may be missing", it
self-adjusts as merchants fill cost in, and it disappears on its own — the same shape as
`ENTITLEMENT_RECORD_INCOMPLETE` in the metric layer, and the reason `ss-attention` gives for
refusing to render an empty state.

This is the **first caveat any figure in the product carries on screen**, which is the whole of
matrix row `P05-04` ("a metric with a known gap displays the gap"). That row stays PARTIAL: one
surface now discloses, and the report screens still do not.

**Evidence.** `tests/Feature/Merchant/FinanceInventoryValuationTest.php` — 17 tests, 69 assertions.
**14 mutations run, 14 reddened, none surviving.** Two survived along the way and both were
informative:

- dropping the `$productUnits > 0` guard **survived at first**, which exposed a real hole rather
  than a dead guard. For a *simple* product the branch's own `continue` already skips the tail, so
  the guard looked redundant — but a **variant** product whose variants are all out of stock
  reaches the tail anyway. A test for that case was added and the mutation now reddens.
- `> 0.0` → `!= 0.0` survived, and **the reason I wrote down for accepting that was false.**
  The four *host* validators do enforce `nullable|numeric|min:0`, but the census was keyed on
  `app/` and missed three **module** writers of the same `products` table —
  `ImportExport/…/ProductsImportData.php:30` (`required|numeric`, no `min:0`),
  `AuctionProduct/…/AuctionProductController.php:118` and
  `DigitalProduct/…/DigitalProductController.php:98` (both `required` alone). So a negative sale
  price is reachable, `> 0.0` is load-bearing, and a test now pins it. Found by independent review;
  the census failure — an inventory keyed on one directory — is this repository's recurring defect
  and I reproduced it exactly.

Independent review found three further defects, all in the EVIDENCE rather than the behaviour, and
all corrected: the catalogue tenancy test asserted only **pre-existing** view data, so it was green
with the whole slice reverted and said nothing about the three new variables; the Arabic amount
assertion (`assertStringContainsString('200.00', …)`) was satisfied by the `1,200.00` rendered in an
adjacent tile; and the ledger caveat **itself overstated** — with no cost, `gross_profit` is the
discounted product subtotal, not the order value, which is the neighbouring `Total` column. On a
screen whose purpose is to stop a figure claiming more than it can, that last one mattered most.

An assertion bug was also caught and is written into the test file so it is not repeated:
`strip_tags()` must not be used on merchant pages. The layout inlines scripts containing bare `<`
comparisons, and `strip_tags()` treats each as a tag opening and discards everything to the next
`>` — measured, 80,007 bytes became 18,679. The *absence* assertion in the "nothing to disclose"
test was passing for that reason and would have passed whether or not the caveat was on the page.

**Bilingual and contrast.** Arabic and English strings added to `resources/lang/{ar,en}.json`
(2 keys each, inserted without reordering either file), and the page is driven with
`users.language = 'ar'` in the suite to prove the `:uncosted` / `:valued` / `:amount` placeholders
survive translation. `.ss-help` draws its accent with `border-inline-start`, so it flips for RTL
from one declaration — asserted against the stylesheet. Contrast measured on the token pairs:
**8.28:1** light, **8.25:1** dark, both past AA and AAA.

## 5. What is open, and what only the owner can settle

### 5.1 Two findings that outrank the rest

Of everything this survey turned up, two are worth an owner's attention ahead of the ordinary
scope gaps, and neither was acted on.

**`MKT-008` — four message senders consult no consent, and there is no unsubscribe path.**
Measured with `certain` confidence. `customers` has **no consent or eligibility column at all** (all
25 columns of the create migration were read, and `Customer::$fillable` with them). The one real
consent mechanism that exists — `lynomia_consent_states`, with a reconciler, a projector and a
merchant-facing controller — is enforced *upstream*, outside this repository, and nothing in `app/`
or `packages/workdo/` reads it to gate a send. Meanwhile four senders go out with no consent
predicate of any kind: the promotional `CouponEmail` on registration, on first order and on an
order-value threshold (`CreateCustomerLis.php:28`, `CreateOrderLis.php:32,51`), the
`ReviewReminder` email (`SendOrderReviewMailListner.php:38`, which gates only on the merchant's
template being active), the newsletter list, and the back-in-stock push.

**Not acted on, deliberately, and it is not a judgement about priority.** Every sender named is in
a **vendored** `packages/workdo/` module, which CLAUDE.md forbids editing as a shortcut, and a
consent column is a **persistent migration** — both are named in the standing restrictions as owner
decisions. This is reported rather than fixed for that reason alone. It is the one finding here with
a real-world compliance dimension rather than a scope dimension.

**`ANA-007` — the honesty mechanism cannot reach a sighted merchant.** `<x-ss.stat>` renders its
`hint` inside `<span class="ss-visually-hidden">`, and `store-syria-ds.css:2238-2246` clips that to
1×1 with `clip: rect(0,0,0,0)` and **no `:hover`, `:focus` or `:focus-within` reveal anywhere**.
Verified by hand. Ten `:hint=` usages sit on `<x-ss.stat>` across six files, and nine of them are precisely the sentences
Phase 05 wrote to stop a figure being misread — "This is not payments received, and not profit",
"How many units were ordered, not how many were delivered or paid for".

The three sibling components all render their hint **visibly** (`ss-advanced__hint`,
`ss-field__hint`, and `choice-group`'s `aria-describedby` target), so `<x-ss.stat>` is the only one
that hides it, and **no test pins that as intended**. The component arrived with the Phase 2.5 merge
`4e878b8d`, so it is inherited rather than a Phase 05 decision.

The effect is that Phase 05's own corrective wording — including the work done under the owner's
third recommendation, to correct misleading "before discounts" labels — is **invisible to everyone
who is not using a screen reader**. **SHIPPED as P5-11 on 2026-11-17**, the same day this survey was taken. The hint now renders visibly and is associated with the figure by `aria-describedby`; measured 5.98:1 light and 7.18:1 dark in all four language/theme combinations. The cost is recorded rather than hidden: a six-tile stat row grows from 123px to 257px, because a 160-character sentence wraps to about seven lines in a 190px grid cell. `COUPON_REDEMPTION_UNRELIABLE` is marked BLOCKING ("This figure is not authoritative", by owner
instruction pending P4-F134/F135) and nothing renders it.

### 5.2 Where these eighteen now sit in the accounting

All eighteen are traced in `MASTER-PLAN-REQUIREMENT-TRACEABILITY.md`. **None is promoted to
VERIFIED COMPLETE on this survey's authority.** `ANA-010` is recorded as machine-verified with both
lenses concurring, and still needs a human reading before it counts toward the verified total — the
project's own rule that an engineering report is not an owner decision applies to an automated
survey at least as strongly.

The requirement totals are therefore **unchanged**: 169 verified complete, 181 not, plus the 18
recovered and untracked, for 368 known requirements and 199 open. What changed is that eighteen of
those open ids now have a measured status and citations instead of nothing.

### 5.3 Owner decisions this survey adds to the queue

1. **`MKT-008` consent** — whether to add a customer consent column and an unsubscribe path, which
   needs a persistent migration, and whether the four vendored senders may be modified to read it.
2. **`ANA-007` / `MetricCaveat`** — whether the caveat vocabulary should be surfaced on the report
   screens, which is the same §3 labelling decision the four-figure proposal is waiting on.

Both are additions to the existing queue, not replacements for anything in it. The decisions already
outstanding — the MKT-006 remedy, the negative order total, the Blade-only redemption thresholds,
the `0.00` loyalty figure's label, the four-figure labelling option, the withdrawal reason, the
Benefit callback destination and a Tap credential — are unchanged and unaffected.

### 5.4 The run record

The full machine output, including every finding at full length and all 36 challenge evidence sets,
was 866 KB and is not committed; this document is the durable record of it. Each per-id section
above keeps the citations, which is what makes a claim checkable. Where a reader wants to go
further, the measurement is reproducible: the briefs were one measuring pass per id over the whole
repository including the ~70 vendored modules, then two opposed adversarial passes per verdict.
