# Phase 02 — merchant web ↔ Flutter parity

Measured, not estimated. The Flutter app was read with a working SDK
(**Flutter 3.41.2 / Dart 3.11.0**) in this container, which closes the Phase 0 blocked check
*"whether Repo B compiles at all"*.

```
flutter analyze   246 info · 0 warning · 0 error
flutter test      1186 PASSED, 0 FAILED
```

---

## 1. What the Flutter app is

A **merchant / store-owner admin console**, not a shopper app and not a courier app. Five bottom-nav
tabs — Home · Orders · Products · Categories · More
(`lib/core/controller/dashboard_manager_controller/dashboard_manager_controller.dart:15-19`). It
*assigns* orders to delivery boys rather than receiving them, and there is no cart, checkout or
wishlist anywhere in `lib/view/screen/`.

Its auth entry point is `POST /api/admin/adminlogin` and its base URL is
`https://storesyria.com/api/admin` (`lib/utils/base_api.dart:2`). **It must therefore be
benchmarked against the merchant web panel, not against a storefront** — and on several dimensions
it is better than the web panel.

## 2. The shared API surface

**91 distinct endpoint paths** are declared in `lib/utils/base_api.dart`, all under the
`Route::prefix('admin')` group at `routes/api.php:133-387`. The storefront API (`api/{slug}/...`)
is called by **no client in either repository**.

This is the contract Phase 02 must not break, and the ways it could:

| Contract property | Why it is load-bearing |
|---|---|
| `ApiResponser::error()` returns **HTTP 200** with `{status: 0, ...}` by default | A handler "hardened" to return a non-200 changes the shape every one of these 91 paths is parsed against |
| **The app clears its session on any 401** | A new 401 on a path the app calls logs the merchant out. This is a user-visible regression, not a hardening win — and it is the single easiest way to break this app from the Laravel side |
| Sanctum tokens never expire and carry `['*']` | There is no refresh path for this client. See `docs/security/P01-TOKEN-EXPIRY-PROPOSAL.md` before touching token lifetime |
| Lynomia Chat | `lib/view/widget/powered_by_lynomia.dart` on the login screen. Behaviour preserved; placement is an owner decision, not a bug |

**Phase 02 has changed no Laravel API handler, no route, no middleware and no response shape.** The
web work so far is presentation (`resources/views/**`, `public/css`, `public/js`) plus one additive
read-only count in `HomeController`, which is a web-session controller the app never calls.

## 3. Feature parity matrix

Legend — **Web**: the merchant panel. **Flutter**: the app. *parity* = both can do it;
*web-only* = deliberately stays on the web; *gap* = the app should have it and does not.

| Area | Web | Flutter | Verdict | Note |
|---|---|---|---|---|
| Login / logout | session | `/adminlogin`, `/adminlogout` | **parity** | different mechanisms, same capability |
| Dashboard summary | redesigned in P2-02 | `/dashboard`, `/staticsdata`, `/Top_orders` | **parity, diverging** | the web dashboard now states periods and withholds order value from ungated staff; **the app does neither** — see §5 G3 |
| Orders list / view | yes | `/orderlist`, `/vieworder` | **parity** | |
| Order status change | yes | `/orders/update-status` | **parity** | |
| Order notes | yes | `/orders/notes/create`, `/orders/notes/delete` | **parity** | |
| Manual order entry | yes | `/createOrderViaApi` | **parity** | |
| Assign order to a delivery boy | yes | `/assignOrders` | **parity** | |
| Products CRUD | yes | `/productlist`, `/addproduct`, `/updateproduct`, `/deleteproduct`, `/viewproduct` | **parity** | |
| Product search | yes | `/searchproduct`, `/searchProductsByName` | **parity** | |
| Categories | yes | `/categorylist`, `/addcategory`, `/updatecategory`, `/deletecategory` | **parity** | |
| Customers | yes | `/customerlist`, `/AddCustomer`, `/update-customer`, `/destroyCustomer` | **parity** | |
| Delivery boys | yes | `/deliveryboysIndex` + 4 more | **parity** | |
| Coupons | yes | `/couponlist`, `/addcoupon`, `/updatecoupon`, `/deletecoupon` | **parity** | |
| Taxes, shipping | yes | `/taxlist`, `/shippinglist` + CRUD | **parity** | |
| Reviews | yes | `/reviewlist`, `/reviewstatus`, `/addreview` | **parity** | |
| Wallet | yes | `/wallet/transactions`, `/wallet/add-balance`, `/wallet/deduct-balance` | **parity** | money paths — any change is an escalation |
| Notifications | header icon | `/notification` + FCM (`/store/fcm-token`) | **Flutter ahead** | the app has push; the web has no durable inbox at all |
| Store switching | header switcher | `/changestore`, `/getStoreInfo` | **parity** | |
| Language / currency | yes | `/getAvailableLanguages`, `/updateStoreLanguage`, `/getCurrenciesApi` | **parity** | |
| Plans / subscription | yes | `/indexAllPlan` | **partial** | viewing only |
| **Theme builder, CMS pages, blog** | yes | — | **web-only, correct** | Phase 03 scope; complex editors belong on a large screen |
| **Reports / analytics** | yes | `/staticsdata` only | **web-only, correct** | Phase 05 scope |
| **Staff and permissions admin** | yes | — | **gap, deliberate for now** | see §5 G4 |
| **Global search** | neither | `/searchdata` exists | **gap on web** | the app already has a search endpoint the web panel does not use |

**Conclusion: the app is at functional parity on the daily operational surface.** The gaps are not
missing CRUD; they are the four UX/behaviour items in §5.

## 4. Where the app is better than the web panel, and should not be "upgraded" away

Measured, and worth stating because a modernisation pass can destroy these by accident:

| Strength | Evidence |
|---|---|
| **Loading / shimmer** | `lib/view/new_widgets/shimmer.dart`, 668 lines, theme-aware **and** RTL-aware, imported by 20 files — the strongest UX artefact in either repository |
| **Empty and error states** | `lib/view/widget/error_empty_state.dart`, imported by 15 files; `SliverFillRemaining` so pull-to-refresh still works on an empty list |
| **Pull-to-refresh** | 17 files |
| **Translation parity** | `en.dart` 640 keys / `ar.dart` 642; no key present in en and missing from ar |
| **Push notifications** | FCM wired; the web has nothing equivalent |

## 5. The real gaps, and their state

| # | Gap | Severity | State |
|---|---|---|---|
| **G1** | **RTL was inert.** `flutter_localizations` was declared and never wired, so the ambient `Directionality` was LTR while Arabic was both the default and the fallback locale. Every `*Directional` call resolved the wrong way; Material's date pickers and selection menus rendered English. | **P0** | **FIXED** (`3c45fbf`). Two tests, both bite. **The RTL visual pass over the screens is NOT done and needs a device** — see §6. |
| **G2** | **Avatar fallback fetched from the upstream vendor's demo server** (`apps.rajodiya.com`), on two production screens. Availability dependency, traffic/IP leak, another company's demo face. | **P1** | **FIXED** (`e69f531`). Host-level guard test. |
| **G3** | **The app's dashboard has not had the honesty pass the web one just had.** No period on its figures, and no permission gate on money. The web now withholds order value from staff without `Manage Order`; the app shows whatever `/dashboard` returns. | **P1** | **OPEN.** The right fix is partly server-side — `/dashboard` should not return a figure the caller may not see — which makes it an API change and therefore an escalation, not a quiet edit. |
| **G4** | **Zero accessibility affordances.** `grep -rn "Semantics(\|semanticLabel" lib` → **0** across 198 files. Icon-only controls (the 5-tab bottom bar, the 6-item popup menu, the password-visibility toggle) expose no label to TalkBack or VoiceOver. | **P2** | **OPEN.** Mechanical but broad; wants its own slice. |

## 6. What cannot be verified here, stated plainly

- **No device, no emulator, no Flutter screenshot.** Everything claimed about the app is from
  `flutter analyze`, `flutter test` and reading the source. **No screen of this app has been seen
  rendering**, by me or by anything in this container. The RTL visual pass G1 unblocks is therefore
  outstanding, and it is the larger half of that item.
- **`flutter pub get --enforce-lockfile` fails** on Flutter 3.41.2; a plain `pub get` resolves by
  moving five SDK-pinned packages. An SDK-version artefact, and `pubspec.lock` is deliberately not
  committed.
- **Syncfusion charts licence.** `syncfusion_flutter_charts ^34.1.32` is used by the statistics
  screen with **no `registerLicense` call anywhere**. Commercial, and without a key it renders a
  licence banner in release builds. **An owner decision; it blocks release of that screen, not
  engineering.**
