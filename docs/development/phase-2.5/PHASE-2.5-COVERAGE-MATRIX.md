# Phase 2.5 — cumulative coverage matrix

**GENERATED. Do not hand-edit** — `docs/development/phase-2.5/tools/build-coverage-matrix.py`
rebuilds it from `PHASE-2.5-SURFACE-INVENTORY.md` and refuses to run if the surface count moves.
The directive requires that "a screen must not silently disappear from the inventory"; a
hand-maintained matrix is precisely how that happens.

## Where Phase 2.5 stands

| state | count | of 295 |
|---|---:|---:|
| PREMIUM VERIFIED | 24 | 8.1% |
| REDESIGNED AND VERIFIED | 184 | 62.4% |
| BLOCKED | 19 | 6.4% |
| OUT OF SCOPE | 15 | 5.1% |
| DUPLICATE | 5 | 1.7% |
| PENDING | 48 | 16.3% |
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
| Flutter merchant admin app | 51 | 0 | 42 | 2 | 0 | 6 |
| CATALOGUE taxonomy | 38 | 13 | 20 | 0 | 0 | 5 |
| Staff/Roles/Permissions + Merchant Settings & Integrations | 38 | 3 | 22 | 3 | 3 | 7 |
| Phase 2.5 Wave A | 30 | 0 | 25 | 0 | 3 | 2 |
| Reports/Analytics presentation, Notifications, and the con | 26 | 0 | 16 | 5 | 1 | 2 |
| Flutter merchant/employee admin app | 24 | 0 | 13 | 1 | 6 | 4 |
| Customers and customer management | 20 | 0 | 12 | 4 | 0 | 4 |
| ORDERS | 17 | 0 | 9 | 3 | 0 | 5 |
| PRODUCTS | 16 | 1 | 7 | 1 | 0 | 7 |
| Merchant admin NAVIGATION SHELL | 13 | 4 | 7 | 0 | 1 | 0 |
| Stock/inventory reporting + finance/wallet/payout screens | 13 | 0 | 6 | 0 | 0 | 6 |
| Merchant dashboard and KPI presentation | 9 | 3 | 5 | 0 | 1 | 0 |

## Every surface

`audit` is the state the Phase 2.5 audit recorded for the surface. `state` is what has
actually been done to it since. They are different questions and the matrix keeps both.

### Flutter merchant admin app

| surface | route / kind | audit | state | evidence or reason |
|---|---|---|---|---|
| Splash / Welcome | ``GetX /splash (initialRoute)`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-AUTH; four captures under evidence/flutter-auth-2026-11-09/ |
| Onboarding | ``GetX /onboarding`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-AUTH; four captures under evidence/flutter-auth-2026-11-09/ |
| Login | ``GetX /login`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-AUTH; four captures under evidence/flutter-auth-2026-11-09/ |
| Dashboard shell (bottom navigation) | ``GetX /dashboard`` | needs-polish | **DUPLICATE** | the same surface as **Bottom-nav shell (DashBoardManagerScreen)** — the same GetX route `/dashboard` and the same widget. Two audit passes recorded the bottom-navigation shell under two names; claiming both would count one screen twice. |
| Home / Overview (tab 0) | ``tab 0 of /dashboard`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-HOME; four captures under evidence/flutter-home-2026-11-09/ |
| Home widgets (stat tiles, QR, profile row) | ``partial/component`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Orders list (tab 1) | ``tab 1 of /dashboard`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-ORDERS; four captures under evidence/flutter-orders-2026-11-09/ |
| Order detail | ``Get.to(() => OrderDetailScreen()) from orders, notifications,` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-DETAIL2; four captures under evidence/flutter-order-detail-2026-11-10/ |
| Create order | ``GetX /addOrder, and Get.to(() => CreateOrderScreen()) from or` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-CREATEORDER; four captures under evidence/flutter-create-order-2026-11-10/ |
| Invoice (PDF build) / Invoice viewer | ``Get.to(() => InvoicePdfViewScreen()) from order detail`` | premium-already | **BLOCKED** | the PDF body cannot be rendered under flutter_test: PdfPreview rasterises through the printing plugin and the capture raises MissingPluginException for `rasterPdf` on channel net.nfet.printing, leaving the loadingWidget shimmer. Measured in all four combinations; documenting captures at evidence/flutter-invoice-2026-11-10/. The page format was wrong while we were there -- it opened at US Letter and is now pinned to A4 by test/screens/invoice_viewer_capture_test.dart. REMEDIATION: capture on a device or emulator; behaviour is pinned by tests meanwhile. |
| Products list (tab 2) | ``tab 2 of /dashboard`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-PRICE; four captures under evidence/flutter-harness-2026-11-09/ |
| Product detail | ``Get.to(() => ProductDetailScreen()) from products list`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-PRODUCTDETAIL; four captures under evidence/flutter-product-detail-2026-11-10/ |
| Add / edit product | ``Get.to(() => AddNewProductScreen(isUpdate:)) from products li` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-EDITORS; four captures under evidence/flutter-editors-2026-11-10/ |
| Add rating / review | ``Get.to(() => AddRatingScreen()) from product detail`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-EDITORS; four captures under evidence/flutter-editors-2026-11-10/ |
| Product card / field partials | ``partial/component`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Categories (tab 3) | ``tab 3 of /dashboard`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-HARNESS; four captures under evidence/flutter-harness-2026-11-09/ |
| Add / edit category (modal) | ``showModalBottomSheet from categories`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-SHEETS; four captures under evidence/flutter-sheets-2026-11-10/ |
| More / Settings grid (tab 4) | ``tab 4 of /dashboard`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-SETTINGS; four captures under evidence/flutter-settings-2026-11-09/ |
| App settings | ``GetX /appSettings`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-SETTINGS; four captures under evidence/flutter-settings-2026-11-09/ |
| Store settings grid | ``Get.to(() => StoreSettingScreen()) from the More grid`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-DETAIL; four captures under evidence/flutter-detail-2026-11-09/ |
| Store information | ``GetX /storeInfo`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-COPY-DECK; four captures under evidence/flutter-store-settings-2026-11-09/ |
| Store language & currencies | ``GetX /lang`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-STORE-SETTINGS; four captures under evidence/flutter-store-settings-2026-11-09/ |
| Store email settings | ``Get.to(() => EmailSettingScreen()) from store settings`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-ACCOUNT; four captures under evidence/flutter-account-2026-11-09/ |
| Loyalty programme | ``Get.to(() => LoyaltyProgramScreen()) from store settings`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-ACCOUNT; four captures under evidence/flutter-account-2026-11-09/ |
| Store payment methods | ``Get.to(() => PaymentSettingScreen()) from store settings`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-DETAIL2; four captures under evidence/flutter-payment-methods-2026-11-10/ |
| COD / Stripe / Bank-transfer gateway editors | ``unreachable — no Get.to, no AppRoutes entry anywhere in lib/`` | deficient | **BLOCKED** | built but unreachable: cod_screen.dart (159 lines), stripe_screen.dart (169) and bank_transfer_screen.dart (152) all define complete screens, and CODScreen / StripeScreen / BankTransferScreen are referenced nowhere in lib/ outside their own directory -- not even by payment_setting_screen.dart, the one payment screen a merchant can open. No Get.to, no named route, no AppRoutes entry. A merchant cannot configure these gateways from the app. REMEDIATION: an owner decision on whether to wire them up; that is new navigation, not a design pass. |
| Tax | ``GetX /tax`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-OPS; four captures under evidence/flutter-ops-2026-11-09/ |
| Add / edit tax (modal) | ``showModalBottomSheet from tax`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-SHEETS; four captures under evidence/flutter-sheets-2026-11-10/ |
| Shipping | ``GetX /shipping`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-OPS; four captures under evidence/flutter-ops-2026-11-09/ |
| Add / edit shipping (modal) | ``showModalBottomSheet from shipping`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-SHEETS; four captures under evidence/flutter-sheets-2026-11-10/ |
| Customers list | ``GetX /customers`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-HARNESS; four captures under evidence/flutter-harness-2026-11-09/ |
| Customer detail | ``Get.to(() => CustomerDetailsScreen()) from customers list`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-DETAIL; four captures under evidence/flutter-detail-2026-11-09/ |
| Add / edit customer (modal) | ``showModalBottomSheet from customers list`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-SHEETS; four captures under evidence/flutter-sheets-2026-11-10/ |
| Delivery men (staff) list | ``GetX /delivery_boys`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-HARNESS; four captures under evidence/flutter-harness-2026-11-09/ |
| Coupons list | ``GetX /coupon`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-HARNESS; four captures under evidence/flutter-harness-2026-11-09/ |
| Coupon detail | ``Get.to(() => CouponDetailScreen()) from coupons list`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-DETAIL2; four captures under evidence/flutter-coupon-detail-2026-11-10/ |
| Notifications | ``GetX /notifications, and Get.to(() => NotificationScreen()) f` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-OPS; four captures under evidence/flutter-ops-2026-11-09/ |
| Analytics / statistics | ``GetX /statistics`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-STATS; four captures under evidence/flutter-stats-2026-11-09/ |
| Statistics widgets | ``partial/component`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Wallet | ``GetX /wallet`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-OPS; four captures under evidence/flutter-ops-2026-11-09/ |
| My plan | ``GetX /myPlan`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-DETAIL2; four captures under evidence/flutter-my-plan-2026-11-10/ |
| Profile / My account | ``Get.to(() => ProfileScreen()) from the dashboard profile row`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-ACCOUNT; four captures under evidence/flutter-account-2026-11-09/ |
| Shared app bar | ``partial/component`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-COMPONENTS; four captures under evidence/flutter-components-2026-11-09/ |
| Shared empty / error / stale states | ``partial/component`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-COMPONENTS; four captures under evidence/flutter-components-2026-11-09/ |
| Shared skeletons | ``partial/component`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-COMPONENTS; four captures under evidence/flutter-components-2026-11-09/ |
| Shared text field | ``partial/component`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-COMPONENTS; four captures under evidence/flutter-components-2026-11-09/ |
| Shared toast (live) | ``partial/component`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Shared toast (duplicate class) | ``partial/component`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Shared confirmation dialog | ``partial/component`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Shared status badge / pill | ``partial/component`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-COMPONENTS; four captures under evidence/flutter-components-2026-11-09/ |
| Shared buttons / loader / search / filter / popup menu | ``partial/component`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-COMPONENTS; four captures under evidence/flutter-components-2026-11-09/ |

### CATALOGUE taxonomy

| surface | route / kind | audit | state | evidence or reason |
|---|---|---|---|---|
| Category list | ``GET /category`` | premium-already | **PREMIUM VERIFIED** | P2.5-WAVE-33; four captures under evidence/wave-33/ |
| Category create (modal) | ``GET /category/create`` | premium-already | **PREMIUM VERIFIED** | P2.5-PKG-B; four captures under evidence/pkg-b-modals/ |
| Category edit (modal) | ``GET /category/{category}/edit`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-MODAL; four captures under evidence/web-modals-2026-11-10/ |
| Category row actions | ``partial/component`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-ROW; four captures under evidence/web-row-actions-2026-11-10/ |
| Brand list | ``GET /product-brand`` | needs-polish | **PREMIUM VERIFIED** | P2.5-WAVE-33; four captures under evidence/wave-33/ |
| Brand create (modal) | ``GET /product-brand/create`` | deficient | **PREMIUM VERIFIED** | P2.5-PKG-B; four captures under evidence/pkg-b-modals/ |
| Brand edit (modal) | ``GET /product-brand/{productBrand}/edit`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-MODAL; four captures under evidence/web-modals-2026-11-10/ |
| Brand row actions | ``partial/component`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-ROW; four captures under evidence/web-row-actions-2026-11-10/ |
| Attributes list | ``GET /product-attributes`` | premium-already | **PREMIUM VERIFIED** | P2.5-WAVE-33; four captures under evidence/wave-33/ |
| Attribute create / edit (modals) | ``GET /product-attributes/create, GET /product-attributes/{id}/` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-WEB-MODALS-2; four captures under evidence/web-modals2-2026-11-10/ |
| Attribute row actions | ``partial/component`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Attribute options (DS pilot screen) | ``GET /attribute-option/{id?}`` | premium-already | **PREMIUM VERIFIED** | P2.5-PKG-B; four captures under evidence/pkg-b-pages/ |
| Attribute option create / edit (modals) | ``GET /add-attribute-option/{id?}, GET /edit-attribute-option/{` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Tag list | ``GET /tag`` | needs-polish | **PREMIUM VERIFIED** | P2.5-PKG-B; four captures under evidence/pkg-b-pages/ |
| Tag create / edit (modals) | ``GET /tag/create, GET /tag/{tag}/edit`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-MODAL; four captures under evidence/web-modals-2026-11-10/ |
| Tag row actions | ``partial/component`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-ROW; four captures under evidence/web-row-actions-2026-11-10/ |
| Product label list | ``GET /product-label`` | needs-polish | **PREMIUM VERIFIED** | P2.5-WAVE-33; four captures under evidence/wave-33/ |
| Product label create / edit (modals) | ``GET /product-label/create, GET /product-label/{id}/edit`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-MODAL; four captures under evidence/web-modals-2026-11-10/ |
| Product label row actions | ``partial/component`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-ROW; four captures under evidence/web-row-actions-2026-11-10/ |
| Coupon list | ``GET /coupon`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-WAVE-2; four captures under evidence/wave-2/ |
| Coupon detail | ``GET /coupon/{coupon}`` | deficient | **PREMIUM VERIFIED** | P2.5-PKG-B; four captures under evidence/pkg-b-pages/ |
| Coupon create (modal) | ``GET /coupon/create`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-MODAL; four captures under evidence/web-modals-2026-11-10/ |
| Coupon edit (modal) | ``GET /coupon/{coupon}/edit`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-MODAL; four captures under evidence/web-modals-2026-11-10/ |
| Coupon row actions | ``partial/component`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-ROW; four captures under evidence/web-row-actions-2026-11-10/ |
| Flash sale list | ``GET /flash-sale`` | needs-polish | **PREMIUM VERIFIED** | P2.5-PKG-B; four captures under evidence/pkg-b-pages/ |
| Flash sale status cell | ``partial/component`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Flash sale create (modal) | ``GET /flash-sale/create`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-MODAL; four captures under evidence/web-modals-2026-11-10/ |
| Flash sale edit (modal) | ``GET /flash-sale/{id}/edit`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Product question list | ``GET /product-question`` | needs-polish | **PREMIUM VERIFIED** | P2.5-PKG-B; four captures under evidence/pkg-b-pages/ |
| Product question reply (modal) | ``GET /product-question/{id}/edit`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-MODAL; four captures under evidence/web-modals-2026-11-10/ |
| Product question row actions | ``partial/component`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-ROW; four captures under evidence/web-row-actions-2026-11-10/ |
| Testimonial list | ``GET /testimonial`` | deficient | **PREMIUM VERIFIED** | P2.5-PKG-B; four captures under evidence/pkg-b-pages/ |
| Testimonial create / edit (modals) | ``GET /testimonial/create, GET /testimonial/{Testimonial}/edit`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-MODAL; four captures under evidence/web-modals-2026-11-10/ |
| Testimonial row actions | ``partial/component`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-ROW; four captures under evidence/web-row-actions-2026-11-10/ |
| FAQ list | ``GET /faqs`` | needs-polish | **PREMIUM VERIFIED** | P2.5-PKG-B; four captures under evidence/pkg-b-pages/ |
| FAQ create (modal) | ``GET /faqs/create`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-MODAL; four captures under evidence/web-modals-2026-11-10/ |
| FAQ edit (modal, repeater) | ``GET /faqs/{faq}/edit`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| FAQ row actions | ``partial/component`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-ROW; four captures under evidence/web-row-actions-2026-11-10/ |

### Staff/Roles/Permissions + Merchant Settings & Integrations

| surface | route / kind | audit | state | evidence or reason |
|---|---|---|---|---|
| Settings hub (26 AJAX tabs) | ``GET /setting`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-WEB-FINAL; four captures under evidence/web-p25-final-2026-11-09/ |
| Brand Settings tab | ``partial/component (POST /business-settings)`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-SET; four captures under evidence/web-settings-tabs-2026-11-09/ |
| System Settings tab | ``partial/component (POST /setting-form)`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-SET; four captures under evidence/web-settings-tabs-2026-11-09/ |
| Storage Settings tab | ``partial/component (POST /storage-settings)`` | premium-already | **OUT OF SCOPE** | super-admin only (setting/index.blade.php:75 gates it on users.type == 'super admin'); a merchant never sees this tab, so it is Phase 06 operator scope, not Phase 2.5 |
| Currency Settings tab | ``partial/component (POST /setting-form)`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-SET; four captures under evidence/web-settings-tabs-2026-11-09/ |
| Cache Settings tab | ``partial/component (GET /config-cache to clear)`` | needs-polish | **OUT OF SCOPE** | super-admin only (setting/index.blade.php:75 gates it on users.type == 'super admin'); a merchant never sees this tab, so it is Phase 06 operator scope, not Phase 2.5 |
| LYNOMIA OTP Settings tab | ``partial/component (POST otp.integration.save)`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-SET; four captures under evidence/web-settings-tabs-2026-11-09/ |
| Shopify Settings tab | ``partial/component (POST /shopify-settings)`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-SET; four captures under evidence/web-settings-tabs-2026-11-09/ |
| WooCommerce Settings tab | ``partial/component (POST /woocommerce-settings)`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-SET; four captures under evidence/web-settings-tabs-2026-11-09/ |
| Webhook Settings tab | ``partial/component`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-SET; four captures under evidence/web-settings-tabs-2026-11-09/ |
| Pixel Settings tab | ``partial/component (resource pixel-setting)`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-SET; four captures under evidence/web-settings-tabs-2026-11-09/ |
| Tax Option / Payment / reCAPTCHA / Cookie / SEO / Stock / Refund / PWA / Style / ChatGPT / Twilio / WhatsApp x3 / Email x3 / Loyalty tabs | ``partial/component (POST /setting-form + per-panel save routes` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-SET; four captures under evidence/web-settings-tabs-2026-11-09/ |
| Delivery Syria (wallet + parcels) | ``GET /setting (tab) and POST /delivery-syria/update`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-SET; four captures under evidence/web-settings-tabs-2026-11-09/ |
| Users / staff list (card grid) | ``GET /users`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-WAVE-33; four captures under evidence/wave-33/ |
| Users / staff list (table view) | ``GET /user-list`` | premium-already | **PREMIUM VERIFIED** | P2.5-WAVE-33; four captures under evidence/wave-33/ |
| Create staff user (modal) | ``GET /users/create -> POST /users`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-MODAL; four captures under evidence/web-modals-2026-11-10/ |
| Edit staff user (modal) | ``GET /users/{id}/edit -> PUT /users/{id}`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Reset staff password (modal) | ``POST users.resetpassword`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Staff detail / user info | ``GET /user-info/{id}`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Own profile (WorkDo) | ``GET /profile`` | premium-already | **PREMIUM VERIFIED** | P2.5-WAVE-33; four captures under evidence/wave-33/ |
| Breeze profile (orphan) | ``GET /profile/edit`` | deficient | **OUT OF SCOPE** | not a screen: GET /profile/edit used to render an empty page (the Breeze `$slot` was dropped by the WorkDo `@yield('content')` layout) and now redirects to GET /profile, the profile screen the product actually ships. Fixed and pinned on this branch. |
| Roles list | ``GET /roles`` | premium-already | **PREMIUM VERIFIED** | P2.5-WAVE-33; four captures under evidence/wave-33/ |
| Create role (xl modal, permission matrix) | ``GET /roles/create -> POST /roles`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-WAVE-12; four captures under evidence/wave-12/ |
| Edit role (xl modal, permission matrix) | ``GET /roles/{id}/edit -> PUT /roles/{id}`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-WAVE-12; four captures under evidence/wave-12/ |
| Shipping classes / zones / methods | ``GET /shipping, /shipping-zone, /shipping-method (+ free-shipp` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-WEB-FINAL; four captures under evidence/web-p25-final-2026-11-09/ |
| Taxes and tax methods | ``GET /taxes, /taxes/{id}, /taxes-method/create/{tax_option_id}` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-WEB-FINAL; four captures under evidence/web-p25-final-2026-11-09/ |
| Webhooks (create/edit modals) | ``GET /webhook/create, /webhook/{id}/edit -> POST\` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Delivery boys | ``GET /deliveryboy, /deliveryboy-list, /deliveryboy/reset-passw` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-WEB-FINAL; four captures under evidence/web-p25-final-2026-11-09/ |
| Shopify import screens (product, category, sub_category, coupon, customer) | ``GET /shopify_product, /shopify_category, /shopify_sub_categor` | deficient | **BLOCKED** | cannot be rendered without a connected Shopify store: ShopifyProductController::index requires the `shopify_setting_enabled` setting plus a store URL and access token, then calls the Shopify Admin API before returning a view. Measured HTTP 500 in all four combinations on the dev fixture. REMEDIATION: an owner-provided Shopify sandbox credential, or an explicit decision to leave these screens unverified in Phase 2.5. |
| WooCommerce import screens (product, category, coupon, customer) | ``GET /woocom_product, /woocom_category, /woocom_coupon, /wooco` | deficient | **BLOCKED** | cannot be rendered without a connected WooCommerce store: measured HTTP 200 with an empty body and no <html> element in all four combinations. REMEDIATION: an owner-provided WooCommerce sandbox credential, or an explicit decision to leave these screens unverified in Phase 2.5. |
| Pixel create/update (modals) | ``GET /pixel-setting/create, /pixel-setting/{id}/edit`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Email templates | ``GET /email_template_lang/{lang?} and /email_template_lang/{id` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Languages | ``GET /languages (LanguageController) + GET /create-language`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-WEB-MODALS-2; four captures under evidence/web-modals2-2026-11-10/ |
| Menus | ``GET /menus, /menus/{id}/edit`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-WEB-FINAL; four captures under evidence/web-p25-final-2026-11-09/ |
| Support tickets | ``GET /support_ticket, /ticket-view/{id}`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-WEB-FINAL; four captures under evidence/web-p25-final-2026-11-09/ |
| Pages / Blog / Blog categories / Contact / Newsletter | ``GET /pages, /blog, /blog-category, /contacts, /newsletter`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-WEB-FINAL; four captures under evidence/web-p25-final-2026-11-09/ |
| Payment gateway checkout pages (plan purchase) | ``various POST /plan-pay-with-*`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Theme customize / storefront visual editor | ``GET /theme-customize (+ /customize-settings)`` | unknown | **BLOCKED** | audit could not determine its state; it needs to be rendered and classified |

### Phase 2.5 Wave A

| surface | route / kind | audit | state | evidence or reason |
|---|---|---|---|---|
| Store Syria Design System stylesheet | ``partial/component — linked globally at resources/views/layout` | premium-already | **OUT OF SCOPE** | not a screen: a 7,305-line CSS file, linked from layouts/app.blade.php:274 and guest.blade.php:245 and never rendered. Tests\Unit\Ui\DesignSystemStylesheetScopeTest already asserts that every selector in it is confined by an `.ss-` class or a `data-ss-` attribute -- i.e. the file only ever paints OTHER surfaces and owns no element of its own. No route serves it as a page. |
| DS theme hook + opt-in partial | ``partial/component`` | premium-already | **OUT OF SCOPE** | not a screen, and it is two artefacts neither of which emits a painting element. The HOOK is an HTML attribute: layouts/app.blade.php:86 writes data-ss-theme onto <html> server-side and an inline script re-derives it with a MutationObserver; an attribute on <html> has no bounding box. The OPT-IN PARTIAL is layouts/includes/store-syria-ds.blade.php -- 52 lines of which 27 are a Blade comment and the rest one <link>, one <script defer> and an inline theme-sync script. |
| x-ss.page-header | ``partial/component`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-DS-GALLERY; four captures under evidence/ds-gallery-2026-11-09/ |
| x-ss.card | ``partial/component`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-DS-GALLERY; four captures under evidence/ds-gallery-2026-11-09/ |
| x-ss.stat | ``partial/component`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-DS-GALLERY; four captures under evidence/ds-gallery-2026-11-09/ |
| x-ss.stats | ``partial/component`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-DS-GALLERY; four captures under evidence/ds-gallery-2026-11-09/ |
| x-ss.status-badge | ``partial/component`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-DS-GALLERY; four captures under evidence/ds-gallery-2026-11-09/ |
| x-ss.empty | ``partial/component`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-DS-GALLERY; four captures under evidence/ds-gallery-2026-11-09/ |
| x-ss.panel (side sheet / drawer) | ``partial/component`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-DS-GALLERY; four captures under evidence/ds-gallery-2026-11-09/ |
| x-ss.confirm-dialog | ``partial/component`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-DS-GALLERY; four captures under evidence/ds-gallery-2026-11-09/ |
| x-ss.actionbar | ``partial/component`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-DS-GALLERY; four captures under evidence/ds-gallery-2026-11-09/ |
| x-ss.breadcrumb | ``partial/component`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-DS-GALLERY; four captures under evidence/ds-gallery-2026-11-09/ |
| x-ss.chip | ``partial/component`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-DS-GALLERY; four captures under evidence/ds-gallery-2026-11-09/ |
| x-ss.switch | ``partial/component`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-DS-GALLERY; four captures under evidence/ds-gallery-2026-11-09/ |
| x-ss.progress | ``partial/component`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-DS-GALLERY; four captures under evidence/ds-gallery-2026-11-09/ |
| x-ss.help | ``partial/component`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-DS-GALLERY; four captures under evidence/ds-gallery-2026-11-09/ |
| x-ss.field-help | ``partial/component`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-DS-GALLERY; four captures under evidence/ds-gallery-2026-11-09/ |
| x-ss.error-summary | ``partial/component`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-DS-GALLERY; four captures under evidence/ds-gallery-2026-11-09/ |
| x-ss.filter-summary | ``partial/component`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-DS-GALLERY; four captures under evidence/ds-gallery-2026-11-09/ |
| x-ss.report-filter | ``partial/component`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-DS-GALLERY; four captures under evidence/ds-gallery-2026-11-09/ |
| x-ss.secret-field | ``partial/component`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-DS-GALLERY; four captures under evidence/ds-gallery-2026-11-09/ |
| x-ss.setup-checklist | ``partial/component`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-DS-GALLERY; four captures under evidence/ds-gallery-2026-11-09/ |
| x-ss.attention | ``partial/component`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-DS-GALLERY; four captures under evidence/ds-gallery-2026-11-09/ |
| x-ss.attention-item | ``partial/component`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-DS-GALLERY; four captures under evidence/ds-gallery-2026-11-09/ |
| x-ss.bar-chart | ``partial/component`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-DS-GALLERY; four captures under evidence/ds-gallery-2026-11-09/ |
| x-ss.advanced-fields | ``partial/component`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-DS-GALLERY; four captures under evidence/ds-gallery-2026-11-09/ |
| x-ss.choice-group | ``partial/component`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-DS-GALLERY; four captures under evidence/ds-gallery-2026-11-09/ |
| x-datatable | ``partial/component — used by 39 host views; 66 of 68 DataTable` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| custom.css F126 region | ``partial/component — public/css/custom.css, loaded on every ad` | premium-already | **OUT OF SCOPE** | not a screen: a ~100-line region of public/css/custom.css (and its mirror in rtl-custom.css) that re-colours text inside `html[data-ss-theme="dark"] .modal-content`. Pure CSS -- there is no markup and no element the region owns. Its effect is visible in every dark modal capture already taken, which is where it was measured and accepted. |
| Legacy Breeze/Tailwind component set (co-resident) | ``partial/component`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |

### Reports/Analytics presentation, Notifications, and the con

| surface | route / kind | audit | state | evidence or reason |
|---|---|---|---|---|
| Customer Reports (reports hub) | ``GET reports`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-WEB-SWEEP; four captures under evidence/web-sweep-2026-11-10/ |
| Customer report body (AJAX fragment) | ``GET reports-chart`` | premium-already | **BLOCKED** | HTTP 500 on the SQLite dev fixture in all four combinations: ReportController::reports_chart builds raw SQL with MONTH(), which SQLite does not implement. Production is MySQL and the screen is expected to work there. REMEDIATION: a MySQL dev fixture; no design work is owed until it can be rendered. |
| Sales Report (line) | ``GET order-reports`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-WEB-SWEEP; four captures under evidence/web-sweep-2026-11-10/ |
| Sales report body (AJAX fragment) | ``GET order-reports-chart`` | premium-already | **BLOCKED** | HTTP 500 on the SQLite dev fixture in all four combinations: ReportController::order_reports_chart (:720) uses MONTH(). Production is MySQL. REMEDIATION: a MySQL dev fixture. |
| Sales Report (radial/bar variant) | ``GET order-reports-data`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-WEB-SWEEP; four captures under evidence/web-sweep-2026-11-10/ |
| Sales report bar body (AJAX fragment) | ``GET order-reports-chart-data`` | premium-already | **BLOCKED** | HTTP 500 on the SQLite dev fixture in all four combinations: ReportController::order_reports_chart_data (:985-1205) uses MONTH() and YEAR(). Production is MySQL. REMEDIATION: a MySQL dev fixture. |
| Stock Reports | ``GET stock-reports (also registered as GET /stock-report, same` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-WEB-SWEEP; four captures under evidence/web-sweep-2026-11-10/ |
| Stock report row cells (status + action) | ``partial/component — rendered into GET stockReport.lowStock / ` | premium-already | **DUPLICATE** | the same surface as **Low in Stock — row payload** — the same pixels. shoot-fragments.cjs clips `#low-stock-table`, `#out-of-stock-table` and `#most-stocked-table` -- the tables the row payloads land in -- so the three payload rows are already claimed on captures OF these cells. A fourth claim on the same images would inflate the count. |
| Sales by Product | ``GET product-order-sale-reports`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-WEB-SWEEP; four captures under evidence/web-sweep-2026-11-10/ |
| Sales by Category | ``GET category-order-sale-reports`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-WEB-SWEEP; four captures under evidence/web-sweep-2026-11-10/ |
| Sales Brand Report | ``GET Brand-order-sale-reports`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-WEB-SWEEP; four captures under evidence/web-sweep-2026-11-10/ |
| Brand sales body (AJAX fragment) | ``GET brand-order-reports`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-WEB-FRAGMENTS; four captures under evidence/web-fragments-2026-11-10/ |
| Category / Product sales bodies (AJAX fragments) | ``GET category-order-reports, GET product-order-reports`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Sales Downloadable Product | ``GET order-downloadable-reports`` | unknown | **BLOCKED** | audit could not determine its state; it needs to be rendered and classified |
| Top Sales Report | ``GET top-all-reports`` | premium-already | **BLOCKED** | HTTP 500 on the SQLite dev fixture in all four combinations: ReportController::top_product joins on SUBSTRING_INDEX (:2814, :2826), which SQLite does not implement -- the controller's own comment at :2883 records this. Production is MySQL. REMEDIATION: a MySQL dev fixture. |
| Order Status Report | ``GET Status-reports`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-WEB-SWEEP; four captures under evidence/web-sweep-2026-11-10/ |
| Top Selling By Country Report | ``GET order-country-reports`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-WEB-SWEEP; four captures under evidence/web-sweep-2026-11-10/ |
| Notifications (full list) | ``GET merchant-notifications`` | premium-already | **DUPLICATE** | the same surface as **Notifications (the bell's destination)** — the same route, GET merchant-notifications. The owning row is already PREMIUM VERIFIED (P2.5-WAVE-33, `_merchant_notifications__`), so claiming this one from the 2026-11-10 sweep would count one screen twice. |
| Notification bell feed (JSON) | ``GET merchant-notifications/feed`` | premium-already | **OUT OF SCOPE** | not a screen: MerchantNotificationController::feed() returns JsonResponse in every branch. The data it serves is rendered and captured as the notification bell dropdown, so a separate capture would reuse that evidence for a second surface. |
| Mark read / mark all read | ``POST merchant-notifications/{id}/read, POST merchant-notifica` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Report period filter (shared component) | ``partial/component`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-DS-GALLERY; four captures under evidence/ds-gallery-2026-11-09/ |
| Chart (shared component) | ``partial/component`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-DS-GALLERY; four captures under evidence/ds-gallery-2026-11-09/ |
| Contextual help (shared component) | ``partial/component`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-DS-GALLERY; four captures under evidence/ds-gallery-2026-11-09/ |
| Field help (shared component) | ``partial/component`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-DS-GALLERY; four captures under evidence/ds-gallery-2026-11-09/ |
| Setup checklist (onboarding component) | ``partial/component`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-DS-GALLERY; four captures under evidence/ds-gallery-2026-11-09/ |
| Needs-attention block (components) | ``partial/component`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-DS-GALLERY; four captures under evidence/ds-gallery-2026-11-09/ |

### Flutter merchant/employee admin app

| surface | route / kind | audit | state | evidence or reason |
|---|---|---|---|---|
| App root / shell | ``n/a — widget root, wraps every route`` | needs-polish | **OUT OF SCOPE** | not a screen: StoreGoApp (lib/store_go.dart) is GetBuilder -> ScreenUtilInit -> GetMaterialApp and sets only configuration -- initialRoute, bindings, getPages, theme, locale, delegates. No Scaffold, no AppBar, no background, no painting widget: it has no pixels of its own, only the current route's. Mounting it would photograph initialRoute /splash, which is the separate "Splash / Welcome" row already captured. |
| Theme definition (the only ThemeData in the app) | ``partial/component`` | needs-polish | **OUT OF SCOPE** | not a screen: theme_controller.dart builds one ThemeData (confirmed the only one -- 17 ThemeData hits in lib/, all in that file bar the pdf package's unrelated class in invoice.dart:149 and a doc comment). A ThemeData is a value object with no build method; each component theme it declares appears only inside another row's capture. golden_harness.dart:146 builds exactly this object in order to render other surfaces. |
| Design tokens (colour) | ``partial/component`` | deficient | **OUT OF SCOPE** | not a screen: utils/colors.dart is 95 lines of bare `static Color` fields (62 across AppColor, DarkThemeColor, LightThemeColor) plus the anchors in store_syria_brand.dart. No widget, no build method. Live rather than inert -- 612 `AppColor.` references across 72 files -- so every token's effect is already inside the captures of the widgets that read it. No palette or style-guide screen exists in lib/ to photograph. |
| Design tokens (typography) | ``partial/component`` | deficient | **OUT OF SCOPE** | not a screen: utils/text_style.dart is `appFontFamily = Tajawal` and 32 top-level getters returning TextStyle only. A TextStyle paints nothing until a Text consumes it. Live -- 36 files import it -- so every step of the scale is already visible inside other rows' captures, and no type specimen is rendered anywhere in the app. |
| Bottom-nav shell (DashBoardManagerScreen) | ``/dashboard (GetX named route)`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Nav destination 1 — Home/dashboard | ``/dashboard tab 0`` | unknown | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-SHELL; four captures under evidence/flutter-shell-2026-11-09/ |
| Nav destination 2 — Orders | ``/dashboard tab 1`` | unknown | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-SHELL; four captures under evidence/flutter-shell-2026-11-09/ |
| Nav destination 3 — Products | ``/dashboard tab 2`` | unknown | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-SHELL; four captures under evidence/flutter-shell-2026-11-09/ |
| Nav destination 4 — Categories | ``/dashboard tab 3`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-SHELL; four captures under evidence/flutter-shell-2026-11-09/ |
| Nav destination 5 — More/Settings | ``/dashboard tab 4`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-SHELL; four captures under evidence/flutter-shell-2026-11-09/ |
| Shared app bar (simpleAppBar) | ``partial/component — 28 live screens`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-COMPONENTS; four captures under evidence/flutter-components-2026-11-09/ |
| Shared text field (AppTextField) | ``partial/component — 45 live call sites`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-COMPONENTS; four captures under evidence/flutter-components-2026-11-09/ |
| Shared dropdown (commonDropdownButtonWidget) | ``partial/component — 2 call sites (add_shipping, add_tax botto` | deficient | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-COMPONENTS; four captures under evidence/flutter-components-2026-11-09/ |
| Shared buttons (CommonButton family) | ``partial/component — 2 live CommonButton + 11 live CommonIconB` | deficient | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-COMPONENTS; four captures under evidence/flutter-components-2026-11-09/ |
| Shared button (AppButton, inside app_dialog.dart) | ``partial/component — used by AppDialog`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-COMPONENTS; four captures under evidence/flutter-components-2026-11-09/ |
| Shared confirmation dialog (AppDialog) | ``partial/component — 12 live call sites`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Shared action feedback (AppSnackBar) | ``partial/component`` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Shared empty / error / stale states | ``partial/component — EmptyState and ErrorState each adopted in` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-COMPONENTS; four captures under evidence/flutter-components-2026-11-09/ |
| Shared skeletons (AppShimmer) | ``partial/component — adopted in 22 files`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-COMPONENTS; four captures under evidence/flutter-components-2026-11-09/ |
| Shared status indicators | ``partial/component — statusPill 5 live sites; StatusBadgeHelpe` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-FLUTTER-COMPONENTS; four captures under evidence/flutter-components-2026-11-09/ |
| Theme switch animation | ``partial/component — wraps the whole app via GetMaterialApp bu` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Loading indicator (Loader / LoadingWidget) | ``partial/component — Loader.showLoader() live at 4 sites`` | deficient | **BLOCKED** | the indicator never renders: Loader.showLoader() (loading_widget.dart:6-8) constructs `const LoadingWidget()` and discards it, so nothing is inserted into any overlay or route -- at three live call sites in category_controller plus my_plan_controller:22. Loader.hideLoader() is meanwhile an unguarded Get.back(closeOverlays: true) that pops whatever is on top, with no loader to dismiss. There is no surface to photograph, and the repair is a navigation behaviour change on a shipped flow. REMEDIATION: an owner decision on whether to show a loader and on what hideLoader() should close. |
| Routing table | ``partial/component`` | needs-polish | **OUT OF SCOPE** | not a screen: routes/routes.dart is 29 path constants and a List<GetPage> of 17 entries, consumed as data at store_go.dart:37-39. It declares nothing visual at all -- no transition, transitionDuration, curve or custom page builder appears in the file, and GetMaterialApp sets no defaultTransition -- so it contributes neither chrome nor animation. Every screen it names is its own inventory row. |
| Bindings / DI | ``partial/component`` | needs-polish | **OUT OF SCOPE** | not a screen: lib/bindings/ is 11 Bindings subclasses whose dependencies() bodies are only Get.put / Get.lazyPut calls. A grep for Widget or transition across the directory returns zero hits -- no file there builds or configures a widget. Live (AppBinding is the initialBinding) but with nothing to render. |

### Customers and customer management

| surface | route / kind | audit | state | evidence or reason |
|---|---|---|---|---|
| Customer list | ``GET customer (customer.index)`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-WAVE-1; four captures under evidence/wave-1/ |
| Customer grid (card view) | ``GET customer-grid (customer.grid)`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-R-07; four captures under evidence/r06-customer-filter/ |
| Customer row actions | ``partial/component (rendered into the DataTable 'action' colum` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-ROW; four captures under evidence/web-row-actions-2026-11-10/ |
| Customer status toggle | ``partial/component (DataTable 'status' column) + POST customer` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Customer filter result fragment | ``GET customer-filter-data (customer.filter.data)`` | deficient | **BLOCKED** | GET customer-filter-data answers HTTP 200 and has NO CALLER. Its former parent was the customer grid, which no longer calls it (recorded in customer/grid.blade.php:38-39 during P2.5-R-07). It returns a JSON-encoded HTML string of customer/filter.blade.php, not a page. It cannot be made a screen without inventing a consumer for it. REMEDIATION: either a deliberate decision to retire the route, or a consumer that a merchant-facing feature actually needs. Neither is a design task. |
| Customer filter field metadata | ``GET customer-filter (customer.filter)`` | needs-polish | **BLOCKED** | GET customer-filter returns application/json and never a view, so it is not a screen and cannot earn a screen state. Its OUTPUT is already rendered -- by the customer filter bar redesigned and captured in P2.5-PKG-G -- so the merchant-visible half of this surface is verified THERE. Claiming it separately would mean reusing that evidence for a second surface, which the owner forbids by name. REMEDIATION: none needed; it is correctly covered as part of the filter bar. |
| Create customer | ``GET customer/create (customer.create) + POST customer (custom` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-PKG-E; four captures under evidence/pkg-e-customer-write/ |
| Customer orders (reached by the cart icon) | ``GET customer/{id} (customer.show)`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-WAVE-13; four captures under evidence/wave-13/ |
| Customer activity timeline | ``GET customer-timeline/{id} (customer.timeline)`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-WAVE-16 + 16c; four captures under evidence/wave-16c/ |
| Archive customer | ``DELETE customer/{customer} (customer.destroy)`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Edit customer (registered, unimplemented) | ``GET customer/{customer}/edit (customer.edit) + PUT/PATCH cust` | deficient | **BLOCKED** | NOT a missing screen -- a WEB/FLUTTER PARITY GAP, and the inventory description is stale. The routes are NOT registered: routes/web.php:1191 is Route::resource('customer', ...)->except(['edit','update']), applied by P2.5-PKG-C (F-C-04) because CustomerController has neither method and GET /customer/1/edit answered HTTP 500. But a COMPLETE customer-update backend ships today and the Flutter admin already uses it: routes/api.php:267 POST update-customer -> DashboardController::updateCustomerInfo (:9113), writing first_name/last_name/email/mobile/status at :9193-9197, consumed by StoreSyria_app98 lib/utils/base_api.dart:122 through an edit bottom-sheet. REMEDIATION: an owner decision on whether the WEB screen should exist. Building it is new merchant functionality, which routes/web.php:1187-1189 records as unauthorised in Phase 2.5. |
| Abandoned carts list | ``GET abandon-carts-handled (abandon.carts.handled)`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-PKG-C; four captures under evidence/pkg-c-abandoned/ |
| Abandoned-cart product cell | ``partial/component (DataTable 'product' column)`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-PKG-C; four captures under evidence/pkg-c-abandoned/ |
| Abandoned-cart products modal | ``GET abandon-carts-show/{customerId} (carts.show)`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-PKG-C; four captures under evidence/pkg-c-abandoned/ |
| Abandoned-cart row actions (send mail / send message / delete) | ``partial/component; POST abandon-carts-emailsend (carts.emails` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Abandoned wishlists list | ``GET wishlist (wishlist.index)`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-PKG-C; four captures under evidence/pkg-c-abandoned/ |
| Abandoned-wishlist product cell | ``partial/component (DataTable 'product' column)`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-PKG-C; four captures under evidence/pkg-c-abandoned/ |
| Abandoned-wishlist products modal | ``GET wishlist/{wishlist} (wishlist.show)`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-PKG-C; four captures under evidence/pkg-c-abandoned/ |
| Abandoned-wishlist row actions (send mail / send message / delete) | ``partial/component; POST abandon-wish-emailsend (wish.emailsen` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Wishlist create / edit / store / update (registered, unimplemented) | ``GET wishlist/create, GET wishlist/{wishlist}/edit, POST wishl` | deficient | **BLOCKED** | All four routes resolve and none renders a byte. WishlistController::create() (:34) and ::store() (:45) have bodies that are literally `//`; ::edit() and ::update() carry P2.5-PKG-B security guards that REFUSE rather than render. No control on any merchant screen targets any of the four. REMEDIATION: owner decision on whether merchant-authored wishlists are a product feature at all. There is nothing to design until there is something to render. |

### ORDERS

| surface | route / kind | audit | state | evidence or reason |
|---|---|---|---|---|
| Order list | ``GET order (order.index)`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-WAVE-1; four captures under evidence/wave-1/ |
| Order board (Kanban) | ``GET order-kanban (order.kanban)`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-ORDERS; four captures under evidence/ord-repair-2026-11-09/ |
| Order detail | ``GET order-view/{id} (order.view)`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-WAVE-11; four captures under evidence/wave-11/ |
| Order quick preview (side panel fragment) | ``GET order-preview/{id} (order.preview)`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-FRAG; four captures under evidence/web-fragments-2026-11-10/ |
| Order AJAX modal (the eye / Show button) | ``GET orders/order_view/{id} (order.order_view)`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-FRAG; four captures under evidence/web-fragments-2026-11-10/ |
| Order grid (card view) | ``GET order-grid (order.grid)`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-ORDERS; four captures under evidence/ord-repair-2026-11-09/ |
| Order row actions (DataTable partial) | ``partial/component`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-ROW; four captures under evidence/web-row-actions-2026-11-10/ |
| Order filter bar + saved views | ``partial/component`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Create order | ``GET order/create (order.create); POST order (order.store)`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-WEB-SWEEP; four captures under evidence/web-sweep-2026-11-10/ |
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
| Product list (grid view) | ``GET /product-list → product.list`` | deficient | **PREMIUM VERIFIED** | P2.5-WAVE-33; four captures under evidence/wave-33/ |
| Product editor — create | ``GET /product/create → product.create`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-WAVE-33; four captures under evidence/wave-33/ |
| Product editor — edit | ``GET /product/{product}/edit → product.edit`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-PARAM; four captures under evidence/web-param-routes-2026-11-10/ |
| Product detail (read-only) | ``GET /product/{product} → product.show`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-PARAM; four captures under evidence/web-param-routes-2026-11-10/ |
| Product row actions (list cell fragment) | ``partial/component — rendered by ProductDataTable::dataTable()` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-ROW; four captures under evidence/web-row-actions-2026-11-10/ |
| Product list filter bar | ``partial/component — included by product/index.blade.php:51`` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| Product quick-preview panel body | ``GET /product-preview/{id} → product.preview`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-FRAG; four captures under evidence/web-fragments-2026-11-10/ |
| Variant panels — create side | ``POST /products/attribute_combination → products.attribute_com` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Variant panels — edit/show side (saved variants) | ``ANY /products/attribute_combination_data/edit → products.attr` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Variant panels — rebuild after attribute change | ``PUT /products/attribute_combination/edit → products.attribute` | needs-polish | **PENDING** | audited needs-polish; not yet redesigned or verified |
| Variant delete confirmation dialog | ``partial/component — included by edit, show, attribute_combina` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |
| SKU combination table | ``POST /products/sku_combination → products.sku_combination`` | deficient | **PENDING** | audited deficient; not yet redesigned or verified |
| Attribute-option picker fragment | ``POST /products/attrinbute_option → products.attribute_option`` | unknown | **BLOCKED** | audit could not determine its state; it needs to be rendered and classified |
| Attribute options list (DS pilot) | ``GET /attribute-option/{id?} → product-attribute-option.show`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-WEB-FINAL; four captures under evidence/web-p25-final-2026-11-09/ |
| Attribute option create / edit (modal) | ``GET /add-attribute-option/{id?} → product-attribute-option.cr` | premium-already | **PENDING** | audited premium-already; not yet redesigned or verified |

### Merchant admin NAVIGATION SHELL

| surface | route / kind | audit | state | evidence or reason |
|---|---|---|---|---|
| Merchant admin layout (the shell itself) | ``partial/component — the layout for the 85 merchant views that` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-PKG-A-09; four captures under evidence/pkg-a-09/ |
| Sidebar (navigation rail) | ``partial/component`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-PKG-A-02; four captures under evidence/package-a/ |
| Sidebar nav tree (items + submenus) | ``partial/component (recursive @include of itself)`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-PKG-A-02; four captures under evidence/package-a/ |
| Sidebar navigation zones (group headings) | ``partial/component`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-PKG-A-02; four captures under evidence/package-a/ |
| Workspace chip (store switcher, sidebar) | ``partial/component; switching posts to POST /change-store/{id}` | premium-already | **PREMIUM VERIFIED** | P2.5-PKG-A-09; four captures under evidence/pkg-a-09/ |
| Header / topbar | ``partial/component`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-PKG-A-06; four captures under evidence/package-a/ |
| Command palette (global search) | ``partial/component; data from GET /merchant-search and POST /m` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-PKG-A-07; four captures under evidence/package-a/ |
| Breadcrumb component | ``partial/component (rendered by the layout on every screen exc` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-PKG-A-09; four captures under evidence/pkg-a-09/ |
| Page-header component | ``partial/component`` | needs-polish | **PREMIUM VERIFIED** | P2.5-PKG-A-09; four captures under evidence/pkg-a-09/ |
| Notifications (the bell's destination) | ``GET /merchant-notifications`` | needs-polish | **PREMIUM VERIFIED** | P2.5-WAVE-33; four captures under evidence/wave-33/ |
| Settings popup (vendored, inert) | ``partial/component — included by layouts/app.blade.php:573 on ` | deficient | **OUT OF SCOPE** | the file was deleted: resources/views/partision/settingPopup.blade.php does not exist. layouts/app.blade.php:767 carries the deletion record and tests/Unit/Ui/SidebarActiveStateAndIconographyTest holds the path as DEAD_MODAL. There is nothing left to render or photograph. |
| Footer + script bundle | ``partial/component`` | needs-polish | **PREMIUM VERIFIED** | P2.5-PKG-A-09; four captures under evidence/pkg-a-09/ |
| Design-system opt-in partial | ``partial/component`` | premium-already | **DUPLICATE** | the same surface as **DS theme hook + opt-in partial** — the same file -- resources/views/layouts/includes/store-syria-ds.blade.php -- which the other row already covers as its "opt-in partial" half. Two audit passes listed it in two sections. |

### Stock/inventory reporting + finance/wallet/payout screens

| surface | route / kind | audit | state | evidence or reason |
|---|---|---|---|---|
| Stock Reports (3-tab shell) | ``GET stock-reports (reports.stock_report) and the duplicate GE` | needs-polish | **DUPLICATE** | the same surface as **Stock Reports** — the same controller action. routes/web.php:1108-1109 register it as BOTH GET stock-reports (reports.stock_report) and GET /stock-report (stock.report); the inventory's own route column says so. Claimed once, on `_stock_reports__`. |
| Low in Stock — row payload | ``GET /stock-report/low-stock (stockReport.lowStock) — routes/w` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-WEB-FRAGMENTS; four captures under evidence/web-fragments-2026-11-10/ |
| Out Of Stock — row payload | ``GET /stock-report/out-stock (stockReport.outOfStock) — routes` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-WEB-FRAGMENTS; four captures under evidence/web-fragments-2026-11-10/ |
| Most Stocked — row payload | ``GET /stock-report/most-stocked (stockReport.mostStocked) — ro` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-WEB-FRAGMENTS; four captures under evidence/web-fragments-2026-11-10/ |
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
| Dashboard overview region (KPI surface) | ``partial/component (included by GET /dashboard)`` | premium-already | **PREMIUM VERIFIED** | P2.5-PKG-A-10; four captures under evidence/pkg-a-10/ |
| Top Category / Top Brand "By Sales" body | ``POST /top-brand-category-chart (routes/web.php:414)`` | deficient | **REDESIGNED AND VERIFIED** | P2.5-PKG-A-03; four captures under evidence/package-a/ |
| x-ss.stat / x-ss.stats (KPI cell + grid) | ``partial/component`` | needs-polish | **PREMIUM VERIFIED** | P2.5-PKG-A-10; four captures under evidence/pkg-a-10/ |
| x-ss.bar-chart | ``partial/component`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-DS-GALLERY; four captures under evidence/ds-gallery-2026-11-09/ |
| x-ss.attention / x-ss.attention-item | ``partial/component`` | premium-already | **PREMIUM VERIFIED** | P2.5-PKG-A-10; four captures under evidence/pkg-a-10/ |
| x-ss.setup-checklist | ``partial/component`` | premium-already | **REDESIGNED AND VERIFIED** | P2.5-DS-GALLERY; four captures under evidence/ds-gallery-2026-11-09/ |
| x-ss.progress | ``partial/component`` | needs-polish | **REDESIGNED AND VERIFIED** | P2.5-DS-GALLERY; four captures under evidence/ds-gallery-2026-11-09/ |
| Super Admin dashboard | ``GET /dashboard (type='super admin' branch)`` | unknown | **OUT OF SCOPE** | Phase 06 (SaaS & Super Admin), not Phase 2.5 Merchant Experience |

