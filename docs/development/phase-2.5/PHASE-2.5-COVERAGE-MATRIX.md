# Phase 2.5 — cumulative coverage matrix

**GENERATED. Do not hand-edit** — `docs/development/phase-2.5/tools/build-coverage-matrix.py`
rebuilds it from `PHASE-2.5-SURFACE-INVENTORY.md` and refuses to run if the surface count moves.
The directive requires that "a screen must not silently disappear from the inventory"; a
hand-maintained matrix is precisely how that happens.

## Where Phase 2.5 stands

| state | count | of 295 |
|---|---:|---:|
| PREMIUM VERIFIED | 0 | 0.0% |
| REDESIGNED AND VERIFIED | 12 | 4.1% |
| BLOCKED | 9 | 3.1% |
| OUT OF SCOPE | 1 | 0.3% |
| PENDING | 273 | 92.5% |
| **total** | **295** | |

**PENDING is not one of the directive's four states, and that is the point.** The four
describe a finished surface. A cumulative matrix has to be able to say "not yet", and
saying it out loud is the opposite of claiming completion. **Phase 2.5 cannot be presented
for acceptance while PENDING is above zero.**

**VERIFIED means rendered.** A surface earns a verified state only when the running
application was driven and captured in all four combinations. Inheriting a shared
component's repair is not verification — Wave 1's table work reaches roughly a hundred
screens and exactly three of them have been looked at. The other ~97 stay PENDING.

## By merchant area

| area | total | premium | redesigned | blocked | out of scope | pending |
|---|---:|---:|---:|---:|---:|---:|
| Flutter merchant admin app | 51 | 0 | 0 | 0 | 0 | 51 |
| CATALOGUE taxonomy | 38 | 0 | 1 | 0 | 0 | 37 |
| Staff/Roles/Permissions + Merchant Settings & Integrations | 38 | 0 | 2 | 1 | 0 | 35 |
| Phase 2.5 Wave A | 30 | 0 | 0 | 0 | 0 | 30 |
| Reports/Analytics presentation, Notifications, and the con | 26 | 0 | 0 | 1 | 0 | 25 |
| Flutter merchant/employee admin app | 24 | 0 | 0 | 3 | 0 | 21 |
| Customers and customer management | 20 | 0 | 1 | 0 | 0 | 19 |
| ORDERS | 17 | 0 | 3 | 3 | 0 | 11 |
| PRODUCTS | 16 | 0 | 1 | 1 | 0 | 14 |
| Merchant admin NAVIGATION SHELL | 13 | 0 | 0 | 0 | 0 | 13 |
| Stock/inventory reporting + finance/wallet/payout screens | 13 | 0 | 3 | 0 | 0 | 10 |
| Merchant dashboard and KPI presentation | 9 | 0 | 1 | 0 | 1 | 7 |

## Every surface

`audit` is the state the Phase 2.5 audit recorded for the surface. `state` is what has
actually been done to it since. They are different questions and the matrix keeps both.

### Flutter merchant admin app

| surface | route / kind | audit | state | evidence or reason |
|---|---|---|---|---|
| Splash / Welcome | ``GetX /splash (initialRoute)`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Onboarding | ``GetX /onboarding`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Login | ``GetX /login`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Dashboard shell (bottom navigation) | ``GetX /dashboard`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Home / Overview (tab 0) | ``tab 0 of /dashboard`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Home widgets (stat tiles, QR, profile row) | ``partial/component`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Orders list (tab 1) | ``tab 1 of /dashboard`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Order detail | ``Get.to(() => OrderDetailScreen()) from orders, notifications,` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Create order | ``GetX /addOrder, and Get.to(() => CreateOrderScreen()) from or` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Invoice (PDF build) / Invoice viewer | ``Get.to(() => InvoicePdfViewScreen()) from order detail`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Products list (tab 2) | ``tab 2 of /dashboard`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Product detail | ``Get.to(() => ProductDetailScreen()) from products list`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Add / edit product | ``Get.to(() => AddNewProductScreen(isUpdate:)) from products li` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Add rating / review | ``Get.to(() => AddRatingScreen()) from product detail`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Product card / field partials | ``partial/component`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Categories (tab 3) | ``tab 3 of /dashboard`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Add / edit category (modal) | ``showModalBottomSheet from categories`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| More / Settings grid (tab 4) | ``tab 4 of /dashboard`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| App settings | ``GetX /appSettings`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Store settings grid | ``Get.to(() => StoreSettingScreen()) from the More grid`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Store information | ``GetX /storeInfo`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Store language & currencies | ``GetX /lang`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Store email settings | ``Get.to(() => EmailSettingScreen()) from store settings`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Loyalty programme | ``Get.to(() => LoyaltyProgramScreen()) from store settings`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Store payment methods | ``Get.to(() => PaymentSettingScreen()) from store settings`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| COD / Stripe / Bank-transfer gateway editors | ``unreachable — no Get.to, no AppRoutes entry anywhere in lib/`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Tax | ``GetX /tax`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Add / edit tax (modal) | ``showModalBottomSheet from tax`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Shipping | ``GetX /shipping`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Add / edit shipping (modal) | ``showModalBottomSheet from shipping`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Customers list | ``GetX /customers`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Customer detail | ``Get.to(() => CustomerDetailsScreen()) from customers list`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Add / edit customer (modal) | ``showModalBottomSheet from customers list`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Delivery men (staff) list | ``GetX /delivery_boys`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Coupons list | ``GetX /coupon`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Coupon detail | ``Get.to(() => CouponDetailScreen()) from coupons list`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Notifications | ``GetX /notifications, and Get.to(() => NotificationScreen()) f` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Analytics / statistics | ``GetX /statistics`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Statistics widgets | ``partial/component`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Wallet | ``GetX /wallet`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| My plan | ``GetX /myPlan`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Profile / My account | ``Get.to(() => ProfileScreen()) from the dashboard profile row`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Shared app bar | ``partial/component`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Shared empty / error / stale states | ``partial/component`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Shared skeletons | ``partial/component`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Shared text field | ``partial/component`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Shared toast (live) | ``partial/component`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Shared toast (duplicate class) | ``partial/component`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Shared confirmation dialog | ``partial/component`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Shared status badge / pill | ``partial/component`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Shared buttons / loader / search / filter / popup menu | ``partial/component`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |

### CATALOGUE taxonomy

| surface | route / kind | audit | state | evidence or reason |
|---|---|---|---|---|
| Category list | ``GET /category`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Category create (modal) | ``GET /category/create`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Category edit (modal) | ``GET /category/{category}/edit`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Category row actions | ``partial/component`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Brand list | ``GET /product-brand`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Brand create (modal) | ``GET /product-brand/create`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Brand edit (modal) | ``GET /product-brand/{productBrand}/edit`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Brand row actions | ``partial/component`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Attributes list | ``GET /product-attributes`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Attribute create / edit (modals) | ``GET /product-attributes/create, GET /product-attributes/{id}/` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Attribute row actions | ``partial/component`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Attribute options (DS pilot screen) | ``GET /attribute-option/{id?}`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Attribute option create / edit (modals) | ``GET /add-attribute-option/{id?}, GET /edit-attribute-option/{` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Tag list | ``GET /tag`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Tag create / edit (modals) | ``GET /tag/create, GET /tag/{tag}/edit`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Tag row actions | ``partial/component`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Product label list | ``GET /product-label`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Product label create / edit (modals) | ``GET /product-label/create, GET /product-label/{id}/edit`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Product label row actions | ``partial/component`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Coupon list | ``GET /coupon`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-WAVE-2; four captures under evidence/wave-2/ |
| Coupon detail | ``GET /coupon/{coupon}`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Coupon create (modal) | ``GET /coupon/create`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Coupon edit (modal) | ``GET /coupon/{coupon}/edit`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Coupon row actions | ``partial/component`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Flash sale list | ``GET /flash-sale`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Flash sale status cell | ``partial/component`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Flash sale create (modal) | ``GET /flash-sale/create`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Flash sale edit (modal) | ``GET /flash-sale/{id}/edit`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Product question list | ``GET /product-question`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Product question reply (modal) | ``GET /product-question/{id}/edit`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Product question row actions | ``partial/component`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Testimonial list | ``GET /testimonial`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Testimonial create / edit (modals) | ``GET /testimonial/create, GET /testimonial/{Testimonial}/edit`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Testimonial row actions | ``partial/component`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| FAQ list | ``GET /faqs`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| FAQ create (modal) | ``GET /faqs/create`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| FAQ edit (modal, repeater) | ``GET /faqs/{faq}/edit`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| FAQ row actions | ``partial/component`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |

### Staff/Roles/Permissions + Merchant Settings & Integrations

| surface | route / kind | audit | state | evidence or reason |
|---|---|---|---|---|
| Settings hub (26 AJAX tabs) | ``GET /setting`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Brand Settings tab | ``partial/component (POST /business-settings)`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| System Settings tab | ``partial/component (POST /setting-form)`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Storage Settings tab | ``partial/component (POST /storage-settings)`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Currency Settings tab | ``partial/component (POST /setting-form)`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Cache Settings tab | ``partial/component (GET /config-cache to clear)`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| LYNOMIA OTP Settings tab | ``partial/component (POST otp.integration.save)`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Shopify Settings tab | ``partial/component (POST /shopify-settings)`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| WooCommerce Settings tab | ``partial/component (POST /woocommerce-settings)`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Webhook Settings tab | ``partial/component`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Pixel Settings tab | ``partial/component (resource pixel-setting)`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Tax Option / Payment / reCAPTCHA / Cookie / SEO / Stock / Refund / PWA / Style / ChatGPT / Twilio / WhatsApp x3 / Email x3 / Loyalty tabs | ``partial/component (POST /setting-form + per-panel save routes` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Delivery Syria (wallet + parcels) | ``GET /setting (tab) and POST /delivery-syria/update`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Users / staff list (card grid) | ``GET /users`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Users / staff list (table view) | ``GET /user-list`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Create staff user (modal) | ``GET /users/create -> POST /users`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Edit staff user (modal) | ``GET /users/{id}/edit -> PUT /users/{id}`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Reset staff password (modal) | ``POST users.resetpassword`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Staff detail / user info | ``GET /user-info/{id}`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Own profile (WorkDo) | ``GET /profile`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Breeze profile (orphan) | ``GET /profile/edit`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Roles list | ``GET /roles`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Create role (xl modal, permission matrix) | ``GET /roles/create -> POST /roles`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-WAVE-12; four captures under evidence/wave-12/ |
| Edit role (xl modal, permission matrix) | ``GET /roles/{id}/edit -> PUT /roles/{id}`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-WAVE-12; four captures under evidence/wave-12/ |
| Shipping classes / zones / methods | ``GET /shipping, /shipping-zone, /shipping-method (+ free-shipp` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Taxes and tax methods | ``GET /taxes, /taxes/{id}, /taxes-method/create/{tax_option_id}` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Webhooks (create/edit modals) | ``GET /webhook/create, /webhook/{id}/edit -> POST\` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Delivery boys | ``GET /deliveryboy, /deliveryboy-list, /deliveryboy/reset-passw` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Shopify import screens (product, category, sub_category, coupon, customer) | ``GET /shopify_product, /shopify_category, /shopify_sub_categor` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| WooCommerce import screens (product, category, coupon, customer) | ``GET /woocom_product, /woocom_category, /woocom_coupon, /wooco` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Pixel create/update (modals) | ``GET /pixel-setting/create, /pixel-setting/{id}/edit`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Email templates | ``GET /email_template_lang/{lang?} and /email_template_lang/{id` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Languages | ``GET /languages (LanguageController) + GET /create-language`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Menus | ``GET /menus, /menus/{id}/edit`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Support tickets | ``GET /support_ticket, /ticket-view/{id}`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Pages / Blog / Blog categories / Contact / Newsletter | ``GET /pages, /blog, /blog-category, /contacts, /newsletter`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Payment gateway checkout pages (plan purchase) | ``various POST /plan-pay-with-*`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Theme customize / storefront visual editor | ``GET /theme-customize (+ /customize-settings)`` | unknown | **BLOCKED** | audit could not determine its state; it needs to be rendered and classified |

### Phase 2.5 Wave A

| surface | route / kind | audit | state | evidence or reason |
|---|---|---|---|---|
| Store Syria Design System stylesheet | ``partial/component — linked globally at resources/views/layout` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| DS theme hook + opt-in partial | ``partial/component`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| x-ss.page-header | ``partial/component`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| x-ss.card | ``partial/component`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| x-ss.stat | ``partial/component`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| x-ss.stats | ``partial/component`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| x-ss.status-badge | ``partial/component`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| x-ss.empty | ``partial/component`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| x-ss.panel (side sheet / drawer) | ``partial/component`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| x-ss.confirm-dialog | ``partial/component`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| x-ss.actionbar | ``partial/component`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| x-ss.breadcrumb | ``partial/component`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| x-ss.chip | ``partial/component`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| x-ss.switch | ``partial/component`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| x-ss.progress | ``partial/component`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| x-ss.help | ``partial/component`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| x-ss.field-help | ``partial/component`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| x-ss.error-summary | ``partial/component`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| x-ss.filter-summary | ``partial/component`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| x-ss.report-filter | ``partial/component`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| x-ss.secret-field | ``partial/component`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| x-ss.setup-checklist | ``partial/component`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| x-ss.attention | ``partial/component`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| x-ss.attention-item | ``partial/component`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| x-ss.bar-chart | ``partial/component`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| x-ss.advanced-fields | ``partial/component`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| x-ss.choice-group | ``partial/component`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| x-datatable | ``partial/component — used by 39 host views; 66 of 68 DataTable` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| custom.css F126 region | ``partial/component — public/css/custom.css, loaded on every ad` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Legacy Breeze/Tailwind component set (co-resident) | ``partial/component`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |

### Reports/Analytics presentation, Notifications, and the con

| surface | route / kind | audit | state | evidence or reason |
|---|---|---|---|---|
| Customer Reports (reports hub) | ``GET reports`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Customer report body (AJAX fragment) | ``GET reports-chart`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Sales Report (line) | ``GET order-reports`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Sales report body (AJAX fragment) | ``GET order-reports-chart`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Sales Report (radial/bar variant) | ``GET order-reports-data`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Sales report bar body (AJAX fragment) | ``GET order-reports-chart-data`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Stock Reports | ``GET stock-reports (also registered as GET /stock-report, same` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Stock report row cells (status + action) | ``partial/component — rendered into GET stockReport.lowStock / ` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Sales by Product | ``GET product-order-sale-reports`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Sales by Category | ``GET category-order-sale-reports`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Sales Brand Report | ``GET Brand-order-sale-reports`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Brand sales body (AJAX fragment) | ``GET brand-order-reports`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Category / Product sales bodies (AJAX fragments) | ``GET category-order-reports, GET product-order-reports`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Sales Downloadable Product | ``GET order-downloadable-reports`` | unknown | **BLOCKED** | audit could not determine its state; it needs to be rendered and classified |
| Top Sales Report | ``GET top-all-reports`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Order Status Report | ``GET Status-reports`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Top Selling By Country Report | ``GET order-country-reports`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Notifications (full list) | ``GET merchant-notifications`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Notification bell feed (JSON) | ``GET merchant-notifications/feed`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Mark read / mark all read | ``POST merchant-notifications/{id}/read, POST merchant-notifica` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Report period filter (shared component) | ``partial/component`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Chart (shared component) | ``partial/component`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Contextual help (shared component) | ``partial/component`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Field help (shared component) | ``partial/component`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Setup checklist (onboarding component) | ``partial/component`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Needs-attention block (components) | ``partial/component`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |

### Flutter merchant/employee admin app

| surface | route / kind | audit | state | evidence or reason |
|---|---|---|---|---|
| App root / shell | ``n/a — widget root, wraps every route`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Theme definition (the only ThemeData in the app) | ``partial/component`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Design tokens (colour) | ``partial/component`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Design tokens (typography) | ``partial/component`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Bottom-nav shell (DashBoardManagerScreen) | ``/dashboard (GetX named route)`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Nav destination 1 — Home/dashboard | ``/dashboard tab 0`` | unknown | **BLOCKED** | audit could not determine its state; it needs to be rendered and classified |
| Nav destination 2 — Orders | ``/dashboard tab 1`` | unknown | **BLOCKED** | audit could not determine its state; it needs to be rendered and classified |
| Nav destination 3 — Products | ``/dashboard tab 2`` | unknown | **BLOCKED** | audit could not determine its state; it needs to be rendered and classified |
| Nav destination 4 — Categories | ``/dashboard tab 3`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Nav destination 5 — More/Settings | ``/dashboard tab 4`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Shared app bar (simpleAppBar) | ``partial/component — 28 live screens`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Shared text field (AppTextField) | ``partial/component — 45 live call sites`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Shared dropdown (commonDropdownButtonWidget) | ``partial/component — 2 call sites (add_shipping, add_tax botto` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Shared buttons (CommonButton family) | ``partial/component — 2 live CommonButton + 11 live CommonIconB` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Shared button (AppButton, inside app_dialog.dart) | ``partial/component — used by AppDialog`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Shared confirmation dialog (AppDialog) | ``partial/component — 12 live call sites`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Shared action feedback (AppSnackBar) | ``partial/component`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Shared empty / error / stale states | ``partial/component — EmptyState and ErrorState each adopted in` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Shared skeletons (AppShimmer) | ``partial/component — adopted in 22 files`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Shared status indicators | ``partial/component — statusPill 5 live sites; StatusBadgeHelpe` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Theme switch animation | ``partial/component — wraps the whole app via GetMaterialApp bu` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Loading indicator (Loader / LoadingWidget) | ``partial/component — Loader.showLoader() live at 4 sites`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Routing table | ``partial/component`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Bindings / DI | ``partial/component`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |

### Customers and customer management

| surface | route / kind | audit | state | evidence or reason |
|---|---|---|---|---|
| Customer list | ``GET customer (customer.index)`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-WAVE-1; four captures under evidence/wave-1/ |
| Customer grid (card view) | ``GET customer-grid (customer.grid)`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Customer row actions | ``partial/component (rendered into the DataTable 'action' colum` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Customer status toggle | ``partial/component (DataTable 'status' column) + POST customer` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Customer filter result fragment | ``GET customer-filter-data (customer.filter.data)`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Customer filter field metadata | ``GET customer-filter (customer.filter)`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Create customer | ``GET customer/create (customer.create) + POST customer (custom` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Customer orders (reached by the cart icon) | ``GET customer/{id} (customer.show)`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Customer activity timeline | ``GET customer-timeline/{id} (customer.timeline)`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Archive customer | ``DELETE customer/{customer} (customer.destroy)`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Edit customer (registered, unimplemented) | ``GET customer/{customer}/edit (customer.edit) + PUT/PATCH cust` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Abandoned carts list | ``GET abandon-carts-handled (abandon.carts.handled)`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Abandoned-cart product cell | ``partial/component (DataTable 'product' column)`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Abandoned-cart products modal | ``GET abandon-carts-show/{cartId} (carts.show)`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Abandoned-cart row actions (send mail / send message / delete) | ``partial/component; POST abandon-carts-emailsend (carts.emails` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Abandoned wishlists list | ``GET wishlist (wishlist.index)`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Abandoned-wishlist product cell | ``partial/component (DataTable 'product' column)`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Abandoned-wishlist products modal | ``GET wishlist/{wishlist} (wishlist.show)`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Abandoned-wishlist row actions (send mail / send message / delete) | ``partial/component; POST abandon-wish-emailsend (wish.emailsen` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Wishlist create / edit / store / update (registered, unimplemented) | ``GET wishlist/create, GET wishlist/{wishlist}/edit, POST wishl` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |

### ORDERS

| surface | route / kind | audit | state | evidence or reason |
|---|---|---|---|---|
| Order list | ``GET order (order.index)`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-WAVE-1; four captures under evidence/wave-1/ |
| Order board (Kanban) | ``GET order-kanban (order.kanban)`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Order detail | ``GET order-view/{id} (order.view)`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-WAVE-11; four captures under evidence/wave-11/ |
| Order quick preview (side panel fragment) | ``GET order-preview/{id} (order.preview)`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Order AJAX modal (the eye / Show button) | ``GET orders/order_view/{id} (order.order_view)`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Order grid (card view) | ``GET order-grid (order.grid)`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Order row actions (DataTable partial) | ``partial/component`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Order filter bar + saved views | ``partial/component`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Create order | ``GET order/create (order.create); POST order (order.store)`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Refund request list | ``GET refund-request (refund-request.index)`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Refund detail / decision screen | ``GET refund-request/{id} (refund-request.show)`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-WAVE-11; four captures under evidence/wave-11/ |
| Printable receipt | ``GET order-receipt/{id} (order.receipt)`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Printable shipping label | ``GET shippinglabel/pdf/{id} (shippinglabel.pdf)`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Order note author (metadata partial) | ``partial/component`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Plan-order bank-transfer review modal (OUT OF SCOPE — Phase 06) | ``GET plan/orders/show/{id} (plan.order.show)`` | unknown | **BLOCKED** | audit could not determine its state; it needs to be rendered and classified |
| Top brand/category chart fragment (OUT OF SCOPE — Phase 05) | ``partial/component`` | unknown | **BLOCKED** | audit could not determine its state; it needs to be rendered and classified |
| order/create.blade.php and order/edit.blade.php (DEAD) | ``partial/component`` | unknown | **BLOCKED** | audit could not determine its state; it needs to be rendered and classified |

### PRODUCTS

| surface | route / kind | audit | state | evidence or reason |
|---|---|---|---|---|
| Product list (table view) | ``GET /product → product.index`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-WAVE-1; four captures under evidence/wave-1/ |
| Product list (grid view) | ``GET /product-list → product.list`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Product editor — create | ``GET /product/create → product.create`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Product editor — edit | ``GET /product/{product}/edit → product.edit`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Product detail (read-only) | ``GET /product/{product} → product.show`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Product row actions (list cell fragment) | ``partial/component — rendered by ProductDataTable::dataTable()` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Product list filter bar | ``partial/component — included by product/index.blade.php:51`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Product quick-preview panel body | ``GET /product-preview/{id} → product.preview`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Variant panels — create side | ``POST /products/attribute_combination → products.attribute_com` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Variant panels — edit/show side (saved variants) | ``ANY /products/attribute_combination_data/edit → products.attr` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Variant panels — rebuild after attribute change | ``PUT /products/attribute_combination/edit → products.attribute` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Variant delete confirmation dialog | ``partial/component — included by edit, show, attribute_combina` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| SKU combination table | ``POST /products/sku_combination → products.sku_combination`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Attribute-option picker fragment | ``POST /products/attrinbute_option → products.attribute_option`` | unknown | **BLOCKED** | audit could not determine its state; it needs to be rendered and classified |
| Attribute options list (DS pilot) | ``GET /attribute-option/{id?} → product-attribute-option.show`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Attribute option create / edit (modal) | ``GET /add-attribute-option/{id?} → product-attribute-option.cr` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |

### Merchant admin NAVIGATION SHELL

| surface | route / kind | audit | state | evidence or reason |
|---|---|---|---|---|
| Merchant admin layout (the shell itself) | ``partial/component — the layout for the 85 merchant views that` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Sidebar (navigation rail) | ``partial/component`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Sidebar nav tree (items + submenus) | ``partial/component (recursive @include of itself)`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Sidebar navigation zones (group headings) | ``partial/component`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Workspace chip (store switcher, sidebar) | ``partial/component; switching posts to POST /change-store/{id}` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Header / topbar | ``partial/component`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Command palette (global search) | ``partial/component; data from GET /merchant-search and POST /m` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Breadcrumb component | ``partial/component (rendered by the layout on every screen exc` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Page-header component | ``partial/component`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Notifications (the bell's destination) | ``GET /merchant-notifications`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Settings popup (vendored, inert) | ``partial/component — included by layouts/app.blade.php:573 on ` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Footer + script bundle | ``partial/component`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Design-system opt-in partial | ``partial/component`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |

### Stock/inventory reporting + finance/wallet/payout screens

| surface | route / kind | audit | state | evidence or reason |
|---|---|---|---|---|
| Stock Reports (3-tab shell) | ``GET stock-reports (reports.stock_report) and the duplicate GE` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Low in Stock — row payload | ``GET /stock-report/low-stock (stockReport.lowStock) — routes/w` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Out Of Stock — row payload | ``GET /stock-report/out-stock (stockReport.outOfStock) — routes` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Most Stocked — row payload | ``GET /stock-report/most-stocked (stockReport.mostStocked) — ro` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Stock Settings panel | ``POST stock-settings (stock.settings) — routes/web.php:842 (re` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Finance dashboard | ``GET /finance (finance.index) — routes/web.php:208`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-WAVE-10 + #12; four captures under evidence/wave-10/ |
| Add Balance modal | ``POST /finance/add-balance (finance.add.balance) — routes/web.` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Deduct Balance modal | ``POST /finance/deduct-balance (finance.deduct.balance) — route` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Request Withdrawal modal | ``POST /finance/withdrawal/request (finance.withdrawal.request)` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Withdrawal queue | ``GET /finance/withdrawals (finance.withdrawals) — routes/web.p` | deficient | **REDESIGNED AND VERIFIED** | P2.5-WAVE-10 + #12; four captures under evidence/wave-10/ |
| Withdrawal approve / pay / reject | ``POST /finance/withdrawals/{id}/approve\` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Order payment-status update (inline) | ``POST /finance/order-financials/{id}/payment-status (finance.o` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Payments Awaiting Review (unreconciled captures) | ``GET /finance/unreconciled-captures (finance.unreconciled.capt` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-WAVE-10; four captures under evidence/wave-10/ |

### Merchant dashboard and KPI presentation

| surface | route / kind | audit | state | evidence or reason |
|---|---|---|---|---|
| Merchant dashboard (page shell) | ``GET /dashboard`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-WAVE-3 + 3b; four captures under evidence/wave-3b/ |
| Dashboard overview region (KPI surface) | ``partial/component (included by GET /dashboard)`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Top Category / Top Brand "By Sales" body | ``POST /top-brand-category-chart (routes/web.php:414)`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| x-ss.stat / x-ss.stats (KPI cell + grid) | ``partial/component`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| x-ss.bar-chart | ``partial/component`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| x-ss.attention / x-ss.attention-item | ``partial/component`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| x-ss.setup-checklist | ``partial/component`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| x-ss.progress | ``partial/component`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Super Admin dashboard | ``GET /dashboard (type='super admin' branch)`` | unknown | **OUT OF SCOPE** | Phase 06 (SaaS & Super Admin), not Phase 2.5 Merchant Experience |

