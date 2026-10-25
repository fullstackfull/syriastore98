# Phase 02 — merchant web ↔ Flutter parity

Measured, not estimated.

**Re-measured on Flutter 3.47.8 / Dart 3.13.7** (head `2774a76d`):

```
flutter pub get --enforce-lockfile   exit 0      # blocker B2 CLEARED; pubspec.lock unchanged
flutter analyze                      246 issues · 0 warning · 0 error
flutter test                         1195 PASSED, 0 FAILED        # was 1186, then 1189
flutter build web --release          FAILS — blocker B4, see §6
```

The earlier figures (Flutter 3.41.2 / Dart 3.11.0, 1186 tests) are superseded, not contradicted:
the counts grew because tests were added, and `analyze` is unchanged at 246 with zero errors.

**`flutter pub get --enforce-lockfile` now succeeds**, which settles blocker B2 — it was an
artefact of 3.41.2, as that row suspected. The lockfile is untouched by this phase.

**One SDK behaviour to know about:** `pub get` rewrites `analysis_options.yaml` to exclude the
platform directories. That silently narrows what `analyze` sees, so it is reverted after every
`pub get` and the 246 above is measured without it.

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

## 5b. RTL — measured for LAYOUT this cycle

The RTL visual pass was recorded as blocked for *"no device, no emulator, no Flutter rendering"*.
**Two of those three have changed.** A widget test is not a mock: `pumpWidget` builds, lays out and
paints through the same RenderObject tree a device uses, and `tester.getRect()` reads the resolved
geometry — which is what *"does it mirror"* actually asks.

A census of `lib/view` found only **seven files** using a direction-blind alignment or padding, and
**five of those shapes were defects**:

| file | was | why it mattered |
|---|---|---|
| `customers_details.dart` | `Alignment.centerLeft` on the `latest_order` and `personal_data` headings | an ARABIC heading pinned to the physical left of its row |
| `top_orders.dart` | `EdgeInsets.only(right:)` margin; `centerLeft` date | an RTL row spaced from the wrong side |
| `app_txt_field.dart` | `EdgeInsets.only(left: 8, right: 12)` | the field suffix's inset did not follow the text |
| `common_drop_down_widget.dart` | `EdgeInsets.only(right: 4)` | the chevron flush in Arabic, gapped in English |

`invoice.dart` and `product_widget.dart` are deliberately left alone: an invoice is a printed
document with its own direction, and `product_widget`'s two are a default parameter and a numeric
column.

`test/localization/rtl_mirroring_test.dart` asserts the painted geometry is a **mirror** rather
than merely different — the start inset equal, the physical sides swapped — plus that the OLD shape
does **not** mirror (so the difference is a measured fact, not a claim), plus a "did it move" case
so a centred layout cannot pass by symmetry, plus a comment-proofed ratchet over the four files.

**Still not verified:** no Flutter screen has been SEEN. See §6.

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


---

## 7. Contract compatibility — unchanged, and checked

Nothing in this phase altered an API response shape, a status code or a route the app calls.

- **The Contract suite passes: 26 tests, 83 assertions.** It replays a committed artifact of
  Lynomia's real producer output through this side's real ingress, so a drift between the two
  repositories fails it.
- **The store switch changed verb on the WEB route only** (`change.store`, GET → POST). The app
  calls the API sibling, `POST /api/admin/changestore`, which was already POST and is untouched.
- **No new 401 on any path a client calls.** The shipped app force-logs-out on any 401, so the new
  endpoints answer 403 for a permission refusal and 404 for an unreadable row — never 401 — and the
  authorization tests assert exactly that.
- **The new endpoints are web-only** (`order-preview/{id}`, `product-preview/{id}`,
  `merchant-search`), inside the authenticated admin group, and no client in either repository
  calls them. The app's own product read stays `/api/admin/viewproduct`, untouched.
- **The concurrent-edit token is opt-in by design.** A save with no `ss_last_seen_at` is not
  refused, precisely so the API and the Flutter app — which post the product form without it —
  keep working unchanged. That is asserted by a test rather than assumed.
- **The concurrent-edit refusal's SHAPE is the web page's shape, and it adds no new one.** It
  returns `['flag' => 'error', 'msg' => …]`, which is what every other refusal in
  `ProductController::update` already returns and what `product/edit.blade.php`'s `$.ajax` handler
  already reads. A client that posts no token never reaches it; a client that does would receive a
  body it can already parse. The earlier `redirect()->back()` would have been the new shape — and
  on the web page it produced a blank error toast, which is how it was found.
