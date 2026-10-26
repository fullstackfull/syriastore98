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
| Orders list / view | yes | `/orderlist`, `/vieworder` | **parity** | **Both sides now name all NINE `delivered_status` values.** The web dashboard's honesty pass came first (P2-04-2); the app said "unknown" for 7 and 8 until P2-07-6. **The SERVER still does not**: `Order`'s `order_status_text` defaults to `__('Pending')` and is overwritten only for 0–6, so the API reports a Pre Order to the app as Pending — ledger F29, a public-response change and an owner decision. |
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
| Notifications | **inbox + bell badge (P2-06-3)** | `/notification` + FCM (`/store/fcm-token`) | **parity on the inbox; Flutter still ahead on PUSH** | Both now read the same `store_notifications` rows. The web adds what the app does not have: per-TYPE authorisation and a real `is_read` the merchant can clear. The app keeps what the web does not: Firebase push. The web is deliberately NOT polling — see the note below. |
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
| **Translation parity** | `en.dart` / `ar.dart`; no key present in en and missing from ar. **P2-07-6 added 13 keys to each**: statuses 7 and 8, an unrecognised-status label, and the nine status descriptions that had been ARABIC STRING LITERALS with no `.tr` — so an English merchant read them in Arabic. Translation parity by key count had not caught that, because the strings were never keyed at all. |
| **Push notifications** | FCM wired in the app; the web has nothing equivalent **and was not given a timer instead**. An interval on every admin page is a request per merchant per tab per interval, forever, for a number that changes when an order arrives — which the merchant sees on the next page they load anyway. Live updates on the web are a push channel (the same Firebase path `StoreNotificationService` already uses), not a poll added quietly. |

## 5. The real gaps, and their state

| # | Gap | Severity | State |
|---|---|---|---|
| **G1** | **RTL was inert.** `flutter_localizations` was declared and never wired, so the ambient `Directionality` was LTR while Arabic was both the default and the fallback locale. Every `*Directional` call resolved the wrong way; Material's date pickers and selection menus rendered English. | **P0** | **FIXED** (`3c45fbf`). Two tests, both bite. **The RTL visual pass over the screens is NOT done and needs a device** — see §6. |
| **G2** | **Avatar fallback fetched from the upstream vendor's demo server** (`apps.rajodiya.com`), on two production screens. Availability dependency, traffic/IP leak, another company's demo face. | **P1** | **FIXED** (`e69f531`). Host-level guard test. |
| **G3** | **The app's dashboard has not had the honesty pass the web one just had.** No period on its figures, and no permission gate on money. The web now withholds order value from staff without `Manage Order`; the app shows whatever `/dashboard` returns. | **P1** | **OPEN.** The right fix is partly server-side — `/dashboard` should not return a figure the caller may not see — which makes it an API change and therefore an escalation, not a quiet edit. |
| **G4** | **Zero accessibility affordances.** `grep -rn "Semantics(\|semanticLabel" lib` → **0** across 198 files. Icon-only controls (the 5-tab bottom bar, the 6-item popup menu, the password-visibility toggle) expose no label to TalkBack or VoiceOver. | **P2** | **OPEN.** Mechanical but broad; wants its own slice. |
| **G5** | **The same order status is a different colour on the two surfaces, and the web's own two surfaces had drifted first.** P2-04-4 found the dashboard's "Latest orders" card and the order list disagreeing about four of the nine statuses, and fixed that by giving both one source (`App\Support\OrderStatusTone`). The web now has **five semantic tones** — warning, success, danger, info, neutral — while the app's `order_status_badge.dart` gives each status **its own hue**: 3 Return purple (web: danger), 5 Picked Up cyan (web: info), 6 Shipped amber (web: info), 7 Partially Paid indigo (web: neutral), 8 Pre Order brown (web: neutral). Both sides are internally consistent, both are above AA on their own surface, and both name the status in text — so nothing is unreadable or ambiguous. What a merchant loses is recognising a status by its colour when moving between app and web. | **P2** | **OPEN, and deliberately not fixed here.** Closing it means either adding four tone tokens to the design system — a change to every surface that uses tones, for one screen's benefit — or collapsing the app's nine hues to five, which is a visible change to a shipped app. That is a design decision across two products, not a drive-by in a contrast fix. Recorded with the measurement so the next person decides it rather than discovers it. |
| **G6** | **P2-03-8 organised the WEB product editor into basic and advanced fields; the app's `add_new_product_screen.dart` (1111 lines) is still one flat run.** Measured against the app's own screen rather than assumed: it renders name, description, category, price, sale price, cost price, stock, `tax_id`, `shipping_id` and `product_weight` — so **three of the six fields the web just collapsed exist on the app too** (`tax_id`, `shipping_id`, `product_weight`) and would benefit from the same grouping. Two do **not exist in the app at all**: `brand_id` and `label_id`, which is a field-coverage gap in the app's favour of the web, separate from the grouping question. Also note the app already carries the web's `cost_price` rule — `'Cost price must be less than or equal to price.'` — so **F58 will bite the app's create screen too** the moment it posts against a migration-built database. | **P2** | **OPEN, and correctly out of this slice.** P2-03-8 changed no API contract and no payload key, so the app is unaffected today and the Contract suite stays at 26/26. Closing it is a Flutter UI slice with its own device verification, and it should wait for the owner's F58 decision — grouping a create screen whose create cannot succeed would be polish over a broken path. |

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
  `merchant-search`, `notifications` and its three siblings), inside the authenticated admin
  group, and no client in either repository calls them. The app's own product read stays
  `/api/admin/viewproduct`, untouched.
- **NOTIFICATIONS ARE THE ONE PLACE THE TWO CLIENTS SHARE A TABLE, and the API is untouched.**
  P2-06-3 reads the same `store_notifications` rows the app reads through
  `GET api/admin/notification` (`Api\DashboardController::noti`), with no schema change and not a
  line changed in that handler. Two consequences worth stating rather than discovering:

  - **That endpoint's `count` is a TOTAL, not an unread count**, and the app derives its badge by
    diffing it against a previous total it stores locally
    (`NotificationController._calculateUnreadCount`) — it never reads `is_read` at all. So
    correcting `count` to mean "unread" would break the app's arithmetic in a shipped build. That
    is a public-response change and an owner decision; it is recorded as a finding, not done.
  - **Marking a row read on the web is therefore safe for the app** — precisely because the app's
    number is a total, which marking does not change. The flip side is the honest gap: a merchant
    who clears their notifications on the web will NOT see the app's badge clear, because the two
    compute "unread" from different facts. Closing that properly means the API reading `is_read`,
    which is the same owner decision as above.
- **The concurrent-edit token is opt-in by design.** A save with no `ss_last_seen_at` is not
  refused, precisely so the API and the Flutter app — which post the product form without it —
  keep working unchanged. That is asserted by a test rather than assumed.
- **The concurrent-edit refusal's SHAPE is the web page's shape, and it adds no new one.** It
  returns `['flag' => 'error', 'msg' => …]`, which is what every other refusal in
  `ProductController::update` already returns and what `product/edit.blade.php`'s `$.ajax` handler
  already reads. A client that posts no token never reaches it; a client that does would receive a
  body it can already parse. The earlier `redirect()->back()` would have been the new shape — and
  on the web page it produced a blank error toast, which is how it was found.
