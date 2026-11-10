#!/usr/bin/env python3
"""
BUILD THE PHASE 2.5 CUMULATIVE COVERAGE MATRIX, from the inventory rather than from memory.

The owner's directive requires a per-surface classification in FOUR states -- PREMIUM VERIFIED,
REDESIGNED AND VERIFIED, BLOCKED (with reason and remediation), OUT OF SCOPE -- maintained
cumulatively across waves, and says plainly: "A screen must not silently disappear from the
inventory" and "Do not claim 295/295 complete without evidence for every inventoried surface."

SO THE MATRIX IS GENERATED, NOT WRITTEN. It parses PHASE-2.5-SURFACE-INVENTORY.md and refuses to
emit anything if the row count moves without the expected total being updated to match. A
hand-maintained matrix is exactly how a screen disappears quietly.

A FIFTH STATE EXISTS AND IS DELIBERATE. `PENDING` is not one of the directive's four, because the
four describe a FINISHED surface. A cumulative matrix has to be able to say "not yet", and saying
it explicitly is the opposite of claiming completion. PENDING must be zero before Phase 2.5 can be
presented for acceptance; it is the number that makes that checkable.

VERIFIED MEANS RENDERED. A surface earns REDESIGNED AND VERIFIED only when the running application
was driven and captured in all four combinations (en-LTR and ar-RTL, light and dark). Inheriting a
shared component's repair is NOT verification -- the whole point of the Wave 1 table work is that
it reaches ~100 screens, and exactly three of them have actually been looked at.

usage:  python3 docs/development/phase-2.5/tools/build-coverage-matrix.py [--check]
        --check  exit 1 if the matrix on disk is stale, for CI. Writes nothing.
"""
import re, sys, json, collections, os, hashlib

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..', '..', '..'))
INV = os.path.join(ROOT, 'docs/development/phase-2.5/PHASE-2.5-SURFACE-INVENTORY.md')
OUT = os.path.join(ROOT, 'docs/development/phase-2.5/PHASE-2.5-COVERAGE-MATRIX.md')
EXPECTED_TOTAL = 295

# ---------------------------------------------------------------------------------------------
# VERIFIED SURFACES. One entry per surface actually driven and captured, naming the evidence.
# Adding a row here without the screenshots is the one way this file can lie, so each entry
# carries the directory its four captures live in and the generator checks the files exist.
# ---------------------------------------------------------------------------------------------
VERIFIED = {
    # ---- P2.5-PKG-C: the abandoned carts and wishlists family ---------------------------------
    # SIX surfaces, and the first time any of them was seen WITH DATA -- the fixture that puts
    # carts and wishlists on these screens landed in this package, and every finding came out of
    # it. The two ROW-ACTION rows are deliberately NOT here: their security and their silent-
    # failure gap were repaired in the same package, but the buttons themselves had no design pass
    # and their feedback was measured in two combinations, not four. Promoting them would be the
    # exact overclaim the directive's §6 forbids.
    'Abandoned carts list':
        ('REDESIGNED AND VERIFIED', 'pkg-c-abandoned', 'abandoned-carts-list_', 'P2.5-PKG-C'),
    'Abandoned-cart product cell':
        ('REDESIGNED AND VERIFIED', 'pkg-c-abandoned', 'abandoned-cart-cell_', 'P2.5-PKG-C'),
    'Abandoned-cart products modal':
        ('REDESIGNED AND VERIFIED', 'pkg-c-abandoned', 'abandoned-cart-modal_', 'P2.5-PKG-C'),
    'Abandoned wishlists list':
        ('REDESIGNED AND VERIFIED', 'pkg-c-abandoned', 'abandoned-wishlists-list_', 'P2.5-PKG-C'),
    'Abandoned-wishlist product cell':
        ('REDESIGNED AND VERIFIED', 'pkg-c-abandoned', 'abandoned-wishlist-cell_', 'P2.5-PKG-C'),
    'Abandoned-wishlist products modal':
        ('REDESIGNED AND VERIFIED', 'pkg-c-abandoned', 'abandoned-wishlist-modal_', 'P2.5-PKG-C'),
    'Product list (table view)':  ('REDESIGNED AND VERIFIED', 'wave-1', '_product__',  'P2.5-WAVE-1'),
    'Order list':                 ('REDESIGNED AND VERIFIED', 'wave-1', '_order__',    'P2.5-WAVE-1'),

    # ---- P2.5-ORDERS (2026-11-09): the twelve confirmed findings of the 363-agent audit --------
    # The board and the card view were driven and captured in all four combinations twice over --
    # once with the repair and once with the ten touched files reverted, same server, same fixture,
    # same minute -- so the `after` set here has a matching `before` beside it and all twelve pairs
    # differ by content hash.
    #
    # `Order list` already sits above from P2.5-WAVE-1 and is NOT re-claimed here: the same screen
    # cannot be verified twice, and its wave-1 captures are still the ones the matrix cites.
    #
    # THE ROW-ACTION AND FILTER-BAR COMPONENTS ARE DELIBERATELY NOT HERE. The filter bar now
    # renders on the card view as well, and the bulk-select checkboxes gained accessible names, but
    # neither has four captures OF ITS OWN -- they were photographed as part of the screens that
    # host them. Promoting a component on its host's evidence is the overclaim §6 forbids, and it
    # is the same line the PKG-C row actions were held behind.
    'Order board (Kanban)':       ('REDESIGNED AND VERIFIED', 'ord-repair-2026-11-09', '_order_kanban__', 'P2.5-ORDERS'),
    'Order grid (card view)':     ('REDESIGNED AND VERIFIED', 'ord-repair-2026-11-09', '_order_grid__', 'P2.5-ORDERS'),

    # ---- P2.5-FLUTTER-HARNESS (2026-11-09): the first Flutter surfaces with evidence -----------
    # Driven through `test/support/screen_harness.dart` in the admin app repository: the REAL
    # screen and the REAL controller, with the network answered by a MockClient installed through
    # `runWithClient`, captured in all four combinations.
    #
    # ONLY THE TWO THAT WERE RENDERED WITH ROWS ARE CLAIMED. An empty list is not a verified list
    # screen -- a reviewer who has only seen "No customers found" has not seen the screen -- so
    # products, categories and coupons are mounted and captured in all four combinations but stay
    # PENDING until their fixtures carry data. Their captures sit beside these ones so the next
    # round starts from evidence rather than from zero.
    #
    # The route needle matters here and is not optional: "Customers list" and "Delivery men" are
    # names this inventory also uses for WEB surfaces, and a Flutter capture must never mark a
    # Laravel screen verified. `GetX /` appears only in the Flutter rows' route column.
    'Customers list':             ('REDESIGNED AND VERIFIED', 'flutter-harness-2026-11-09',
                                   'flutter-customers-list_', 'P2.5-FLUTTER-HARNESS', 'GetX /customers'),
    'Delivery men (staff) list':  ('REDESIGNED AND VERIFIED', 'flutter-harness-2026-11-09',
                                   'flutter-delivery-men-list_', 'P2.5-FLUTTER-HARNESS', 'GetX /delivery_boys'),
    'Categories (tab 3)':         ('REDESIGNED AND VERIFIED', 'flutter-harness-2026-11-09',
                                   'flutter-categories-list_', 'P2.5-FLUTTER-HARNESS', 'tab 3'),
    'Coupons list':               ('REDESIGNED AND VERIFIED', 'flutter-harness-2026-11-09',
                                   'flutter-coupons-list_', 'P2.5-FLUTTER-HARNESS', 'GetX /coupon'),

    # --- P2.5-FLUTTER-OPS (2026-11-09), the second tranche through the same harness -------------
    #
    # Tax, shipping, wallet and notifications, each driven with REAL ROWS in all four combinations
    # by `test/screens/operations_screen_capture_test.dart`. Three defects were found by looking at
    # what these captures drew and are FIXED in the admin app, so what the evidence now shows is
    # the repaired screen:
    #
    #   * the tax list's Arabic header was the untranslated key `Name`, its rate was labelled
    #     `Discount`, and a fixed amount carried an invented `$` (P2.5-FLUTTER-TAX-LABEL);
    #   * the wallet amount resolved NO font family, because `RichText` does not inherit
    #     `DefaultTextStyle` (P2.5-FLUTTER-RICHTEXT);
    #   * the wallet's primary action measured 1.03:1 in dark mode -- invisible -- because the card
    #     gradient collapsed to cyan when the theme reassigned `themeNavyBlueColor`
    #     (P2.5-FLUTTER-WALLET-CARD).
    #
    # `GetX /` again appears only in the Flutter rows' route column, so none of these can satisfy a
    # web surface of the same name.
    'Tax':                        ('REDESIGNED AND VERIFIED', 'flutter-ops-2026-11-09',
                                   'flutter-tax-list_', 'P2.5-FLUTTER-OPS', 'GetX /tax'),
    'Shipping':                   ('REDESIGNED AND VERIFIED', 'flutter-ops-2026-11-09',
                                   'flutter-shipping-list_', 'P2.5-FLUTTER-OPS', 'GetX /shipping'),
    'Wallet':                     ('REDESIGNED AND VERIFIED', 'flutter-ops-2026-11-09',
                                   'flutter-wallet_', 'P2.5-FLUTTER-OPS', 'GetX /wallet'),
    'Notifications':              ('REDESIGNED AND VERIFIED', 'flutter-ops-2026-11-09',
                                   'flutter-notifications_', 'P2.5-FLUTTER-OPS', 'GetX /notifications'),

    # --- P2.5-FLUTTER-ORDERS (2026-11-09) -------------------------------------------------------
    #
    # The Flutter half of the surface this phase opened on. The ORDERS audit was done against the
    # WEB merchant list; this one had no captures at all until now. Driven with two real orders in
    # all four combinations by `test/screens/order_screen_capture_test.dart`, with the status
    # numbering checked against the server's own
    # `Order::getDeliveredStatusStringAttribute()` -- they agree.
    #
    # The needle is `tab 1`, which is how this inventory addresses the bottom-nav destinations.
    'Orders list (tab 1)':        ('REDESIGNED AND VERIFIED', 'flutter-orders-2026-11-09',
                                   'flutter-orders-list_', 'P2.5-FLUTTER-ORDERS', 'tab 1'),

    # --- P2.5-FLUTTER-HOME (2026-11-09) ---------------------------------------------------------
    #
    # The merchant dashboard, driven with real data in all four combinations by
    # `test/screens/home_screen_capture_test.dart`. Two defects fixed from these captures -- every
    # Overview tile label clipped to an ellipsis (3 of 3 in Arabic, measured with
    # `RenderParagraph.didExceedMaxLines`), and `'Overview'.tr` untranslated so the Arabic build
    # printed the English key -- plus a HARNESS bug the captures exposed: `pumpScreen` set the
    # MaterialApp theme but not the app's own `ThemeController`, so this screen rendered half dark.
    # Of the four files in lib/view that branch on that controller, only this one is a captured
    # surface, so the dark captures claimed above are unaffected.
    'Home / Overview (tab 0)':    ('REDESIGNED AND VERIFIED', 'flutter-home-2026-11-09',
                                   'flutter-home_', 'P2.5-FLUTTER-HOME', 'tab 0'),

    # --- P2.5-FLUTTER-STATS (2026-11-09) --------------------------------------------------------
    #
    # The analytics screen, driven with all five of its sections populated by
    # `test/screens/statistics_screen_capture_test.dart`. Each section is conditional on its own
    # list, so a fixture that filled one would have captured a screen with the rest silently
    # absent; all five carry data and all three breakdowns are asserted.
    #
    # Four labels on it had no entry in either translation file and printed as English keys in the
    # Arabic build -- now translated and removed from the ratchet. A pending `visibility_detector`
    # timer that failed the first test of every run was fixed in the harness, not in lib/.
    'Analytics / statistics':     ('REDESIGNED AND VERIFIED', 'flutter-stats-2026-11-09',
                                   'flutter-statistics_', 'P2.5-FLUTTER-STATS', 'GetX /statistics'),

    # --- P2.5-FLUTTER-SHELL (2026-11-09) --------------------------------------------------------
    #
    # The bottom-navigation shell with each destination selected, by
    # `test/screens/dashboard_shell_capture_test.dart`. THREE OF THESE WERE `BLOCKED`, all three
    # with "audit could not determine its state; it needs to be rendered and classified" -- blocked
    # on RENDERING, which is exactly what the screen harness does. Nothing about them was
    # undecidable; there was no way to look.
    #
    # EACH DESTINATION HAS ITS OWN CAPTURE SET, and they are distinct from the standalone screen
    # captures claimed above: `flutter-home_*` is the dashboard ALONE, `flutter-shell-home_*` is
    # the dashboard inside the shell with the navigation bar drawn over it. Different tree,
    # different picture, and the only place the navigation bar itself can be reviewed -- which is
    # where its light-mode inactive label was measured at 3.14:1 and repaired.
    'Nav destination 1 — Home/dashboard':
        ('REDESIGNED AND VERIFIED', 'flutter-shell-2026-11-09',
         'flutter-shell-home_', 'P2.5-FLUTTER-SHELL', 'tab 0'),
    'Nav destination 2 — Orders':
        ('REDESIGNED AND VERIFIED', 'flutter-shell-2026-11-09',
         'flutter-shell-orders_', 'P2.5-FLUTTER-SHELL', 'tab 1'),
    'Nav destination 3 — Products':
        ('REDESIGNED AND VERIFIED', 'flutter-shell-2026-11-09',
         'flutter-shell-products_', 'P2.5-FLUTTER-SHELL', 'tab 2'),
    'Nav destination 4 — Categories':
        ('REDESIGNED AND VERIFIED', 'flutter-shell-2026-11-09',
         'flutter-shell-categories_', 'P2.5-FLUTTER-SHELL', 'tab 3'),
    'Nav destination 5 — More/Settings':
        ('REDESIGNED AND VERIFIED', 'flutter-shell-2026-11-09',
         'flutter-shell-more_', 'P2.5-FLUTTER-SHELL', 'tab 4'),

    # The two SHELL rows -- `Dashboard shell (bottom navigation)` and
    # `Bottom-nav shell (DashBoardManagerScreen)` -- are deliberately NOT claimed here, and they
    # are the same surface listed twice in two inventory sections. The shell is never on screen
    # without a destination, so it has no picture that is not already one of the five above, and
    # claiming it would mean using one capture as evidence for two surfaces. Its navigation bar
    # WAS measured and repaired in this package; what it needs is an inventory decision about
    # whether a frame with no content of its own is a surface at all.

    # --- P2.5-FLUTTER-SETTINGS (2026-11-09) -----------------------------------------------------
    #
    # Both captured STANDALONE by `test/screens/settings_screen_capture_test.dart`, not taken from
    # the shell batch above, so each row has evidence that is its own. The More grid's cards were
    # repaired here: subtitle 2.15:1 in light and 3.30:1 in dark, chevron 1.99:1 and 2.38:1, dark
    # icon 1.89:1 -- all from an alpha laid over a colour that already cleared the threshold.
    'More / Settings grid (tab 4)':
        ('REDESIGNED AND VERIFIED', 'flutter-settings-2026-11-09',
         'flutter-more-grid_', 'P2.5-FLUTTER-SETTINGS', 'tab 4'),
    'App settings':
        ('REDESIGNED AND VERIFIED', 'flutter-settings-2026-11-09',
         'flutter-app-settings_', 'P2.5-FLUTTER-SETTINGS', 'GetX /appSettings'),

    # --- P2.5-FLUTTER-STORE-SETTINGS (2026-11-09) -----------------------------------------------
    #
    # THE COPY DECK IS RESOLVED (owner Decision 3, 2026-11-09): all 68 strings are translated at
    # 76 call sites, and the Arabic capture now reads معلومات المتجر over اسم المتجر,
    # البريد الإلكتروني, رابط المتجر, تاريخ الإنشاء, معلومات المالك and اسم المالك, with the email,
    # URL and date VALUES correctly staying left-to-right. So this row is no longer held.
    'Store information':          ('REDESIGNED AND VERIFIED', 'flutter-store-settings-2026-11-09',
                                   'flutter-store-info_', 'P2.5-COPY-DECK', 'GetX /storeInfo'),

    # (historical note) `Store information` was DELIBERATELY ABSENT while its captures sat beside
    # this one's.
    # Its Arabic build renders the heading معلومات المتجر above field labels reading "Store Name",
    # "Email", "Store URL", "Created At", "Owner Information", "Owner Name" and "Owner Email". That
    # is not a new finding: P2.5-B-12 slice one converted the nine labels whose Arabic existed and
    # recorded the remainder in StoreSyria_app98/docs/development/phase-2.5/
    # COPY-DECK-UNTRANSLATED-SCREENS.md, because `.tr` on a key with no copy renders the raw
    # identifier. The deck is a request for OWNER COPY, not engineering, and the screen stays
    # PENDING until the Arabic column is filled. The captures were added to the deck so the copy
    # can be written against the real layout.
    'Store language & currencies':
        ('REDESIGNED AND VERIFIED', 'flutter-store-settings-2026-11-09',
         'flutter-language-currency_', 'P2.5-FLUTTER-STORE-SETTINGS', 'GetX /lang'),

    # --- P2.5-FLUTTER-ACCOUNT (2026-11-09) ------------------------------------------------------
    #
    # The three screens the resolved copy deck unlocked. All three were held back for the same
    # reason Store Information was -- hard-coded English labels, so an Arabic merchant read an
    # English form -- and these are the first captures of them in either language.
    #
    # The Arabic capture of the profile screen then found three MORE English strings in its header
    # card that the deck never listed, because the deck was built by reading files. They are fixed
    # in the same change; the premise test asserts the English spelling is ABSENT from the Arabic
    # build, not merely that the Arabic is present.
    # --- P2.5-FLUTTER-DETAIL (2026-11-09) -------------------------------------------------------
    #
    # Two surfaces a merchant reaches by pushing from a list, by
    # `test/screens/detail_screens_capture_test.dart`.
    #
    # `StoreSettingScreen` is a HUB: its value is that every label on it is a translation key, so
    # the test asserts the raw identifiers (`store_settings`, `store_language_and_currencies`,
    # `store_information`, `store_design`) are NOT on screen in either language -- a missing key
    # would render as the identifier itself.
    #
    # `CustomerDetailsScreen` needs two controllers and calls `clearCustomerOrders()` SYNCHRONOUSLY
    # in initState before its post-frame fetch, so a response that fails to parse leaves an empty
    # list indistinguishable from a customer who has never ordered. A premise drives the
    # genuinely-empty case and requires the customer still be identified on screen.
    # --- P2.5-FLUTTER-COMPONENTS (2026-11-09) ---------------------------------------------------
    #
    # Six shared components rendered ALONE in all four combinations, with the states that differ
    # side by side in one frame, by `test/screens/component_gallery_capture_test.dart`. A capture
    # taken inside a host screen is evidence for that screen, not for the component: it shows one
    # instance, in one state, at one size.
    #
    # Rendering them found a real defect. `CommonButton` with no handler was indistinguishable
    # from one that works, AND the control carried no button semantics at all -- a GestureDetector
    # around a Container, announced as plain text in both states. Both fixed; latent rather than
    # live, since all six call sites pass a handler today.
    #
    # These rows claim the EVIDENCE, not new assertions: the contrast and layout claims already
    # live in settings_card_contrast_test, card_intrinsic_height_test, toast_overlay_test,
    # order_status_label_test and image_placeholder_contrast_test, and those are stronger than a
    # picture. What was missing was something a reviewer can look at, in both languages and both
    # themes.
    'Shared empty / error / stale states':
        ('REDESIGNED AND VERIFIED', 'flutter-components-2026-11-09',
         'flutter-component-states_', 'P2.5-FLUTTER-COMPONENTS', 'partial/component'),
    'Shared skeletons':
        ('REDESIGNED AND VERIFIED', 'flutter-components-2026-11-09',
         'flutter-component-skeletons_', 'P2.5-FLUTTER-COMPONENTS', 'partial/component'),
    'Shared text field':
        ('REDESIGNED AND VERIFIED', 'flutter-components-2026-11-09',
         'flutter-component-textfield_', 'P2.5-FLUTTER-COMPONENTS', 'partial/component'),
    'Shared status badge / pill':
        ('REDESIGNED AND VERIFIED', 'flutter-components-2026-11-09',
         'flutter-component-pill_', 'P2.5-FLUTTER-COMPONENTS', 'partial/component'),
    # This row names FIVE things, so it is claimed on a gallery that shows all five: buttons and
    # loader in one specimen set, search / filter tabs / popup menu in the other. Both capture
    # sets are listed in the package README.
    'Shared buttons / loader / search / filter / popup menu':
        ('REDESIGNED AND VERIFIED', 'flutter-components-2026-11-09',
         'flutter-component-buttons_', 'P2.5-FLUTTER-COMPONENTS', 'partial/component'),

    'Store settings grid':        ('REDESIGNED AND VERIFIED', 'flutter-detail-2026-11-09',
                                   'flutter-store-settings-hub_', 'P2.5-FLUTTER-DETAIL',
                                   'StoreSettingScreen'),
    'Customer detail':            ('REDESIGNED AND VERIFIED', 'flutter-detail-2026-11-09',
                                   'flutter-customer-detail_', 'P2.5-FLUTTER-DETAIL',
                                   'CustomerDetailsScreen'),

    'Profile / My account':
        ('REDESIGNED AND VERIFIED', 'flutter-account-2026-11-09',
         'flutter-profile_', 'P2.5-FLUTTER-ACCOUNT', 'ProfileScreen'),
    'Store email settings':
        ('REDESIGNED AND VERIFIED', 'flutter-account-2026-11-09',
         'flutter-email-settings_', 'P2.5-FLUTTER-ACCOUNT', 'EmailSettingScreen'),
    'Loyalty programme':
        ('REDESIGNED AND VERIFIED', 'flutter-account-2026-11-09',
         'flutter-loyalty_', 'P2.5-FLUTTER-ACCOUNT', 'LoyaltyProgramScreen'),

    # --- P2.5-FLUTTER-AUTH (2026-11-09) ---------------------------------------------------------
    #
    # The three screens before a merchant is signed in -- the first thing anyone sees of this
    # product, and none of them had a capture. By `test/screens/auth_flow_capture_test.dart`.
    #
    # The welcome screen is the one that could not simply be pumped and photographed: it calls
    # `Get.offAllNamed` 1500 ms after mount, so its test pumps a window INSIDE that delay and
    # asserts it is still on screen before capturing -- otherwise the picture would be of whatever
    # it redirected to, filed under this name. A companion premise drives past the delay and
    # asserts it really does leave, so the bounded window is guarding something real.
    'Splash / Welcome':
        ('REDESIGNED AND VERIFIED', 'flutter-auth-2026-11-09',
         'flutter-welcome_', 'P2.5-FLUTTER-AUTH', 'GetX /splash'),
    'Onboarding':
        ('REDESIGNED AND VERIFIED', 'flutter-auth-2026-11-09',
         'flutter-onboarding_', 'P2.5-FLUTTER-AUTH', 'GetX /onboarding'),
    'Login':
        ('REDESIGNED AND VERIFIED', 'flutter-auth-2026-11-09',
         'flutter-login_', 'P2.5-FLUTTER-AUTH', 'GetX /login'),

    # P2.5-FLUTTER-PRICE IS FIXED (owner Decision 1, 2026-11-09), so this row is no longer held.
    # Its own capture is what found the defect: the card was handed `salePrice` alone, and
    # `sale_price` is the optional DISCOUNT (`->default(0)`, validated `lte:price`), so every
    # product nobody had discounted rendered an em dash where the web printed its price. The card
    # now reads `final_price`, which `ProductList` already resolved from the product's own price,
    # so the two surfaces agree by reading the same field and no API or calculation changed. The
    # re-taken captures show `150 $` and `85 $` where the em dashes were.
    'Products list (tab 2)':      ('REDESIGNED AND VERIFIED', 'flutter-harness-2026-11-09',
                                   'flutter-products-list_', 'P2.5-FLUTTER-PRICE', 'tab 2'),
    # --- P2.5-FLUTTER-COMPONENTS, second set (2026-11-09) ---------------------------------------
    #
    # Three more shared widgets through the gallery already built, rather than new infrastructure.
    #
    # THE SPECIMEN WAS WRONG AGAIN AND THE CAPTURE CAUGHT IT: the Arabic app bar rendered
    # "New Order" and "Analytics" in English, which reads as a missing translation in the
    # component. It was not -- every real `simpleAppBar` call site passes snake_case keys
    # (`all_categories`, `add_category`, `product_details`) and the specimen had invented two that
    # are not keys at all. Same mistake the StaleDataNotice specimen made, caught the same way.
    #
    # `AppDialog` itself is NOT claimed: it opens an overlay, which a static gallery cannot hold.
    # What ships inside it -- `AppButton` -- is its own row and is captured.
    #
    # FIVE ROWS BELOW ARE THE INVENTORY'S OWN DUPLICATES -- `Shared app bar` and
    # `Shared app bar (simpleAppBar)` are the same widget named twice, as are the text field, the
    # skeletons, the status indicators and the button family. Claiming both from one picture is
    # what the owner's constraints forbid by name, so the duplicates are claimed on the TABLET
    # set: a different surface, a different render and a different file. That also supplies the
    # responsive half the phase asks for, which the 360pt set alone cannot show.
    'Shared app bar':
        ('REDESIGNED AND VERIFIED', 'flutter-components-2026-11-09',
         'flutter-component-appbar_', 'P2.5-FLUTTER-COMPONENTS', 'partial/component'),
    'Shared app bar (simpleAppBar)':
        ('REDESIGNED AND VERIFIED', 'flutter-components-2026-11-09',
         'flutter-component-appbar-tablet_', 'P2.5-FLUTTER-COMPONENTS', 'partial/component'),
    'Shared dropdown (commonDropdownButtonWidget)':
        ('REDESIGNED AND VERIFIED', 'flutter-components-2026-11-09',
         'flutter-component-dropdown_', 'P2.5-FLUTTER-COMPONENTS', 'partial/component'),
    'Shared button (AppButton, inside app_dialog.dart)':
        ('REDESIGNED AND VERIFIED', 'flutter-components-2026-11-09',
         'flutter-component-dialog-button_', 'P2.5-FLUTTER-COMPONENTS', 'partial/component'),
    'Shared buttons (CommonButton family)':
        ('REDESIGNED AND VERIFIED', 'flutter-components-2026-11-09',
         'flutter-component-buttons-tablet_', 'P2.5-FLUTTER-COMPONENTS', 'partial/component'),
    'Shared text field (AppTextField)':
        ('REDESIGNED AND VERIFIED', 'flutter-components-2026-11-09',
         'flutter-component-textfield-tablet_', 'P2.5-FLUTTER-COMPONENTS', 'partial/component'),
    'Shared skeletons (AppShimmer)':
        ('REDESIGNED AND VERIFIED', 'flutter-components-2026-11-09',
         'flutter-component-skeletons-tablet_', 'P2.5-FLUTTER-COMPONENTS', 'partial/component'),
    'Shared status indicators':
        ('REDESIGNED AND VERIFIED', 'flutter-components-2026-11-09',
         'flutter-component-pill-tablet_', 'P2.5-FLUTTER-COMPONENTS', 'partial/component'),

    # --- P2.5-DS-GALLERY (2026-11-09) -----------------------------------------------------------
    #
    # Every `x-ss.*` component rendered ALONE, in all four combinations, by
    # `tests/Support/DesignSystem/bin/render-ds-gallery.php` + `shoot-ds-gallery.cjs`.
    # 26 specimens x 4 = 104 captures, all distinct.
    #
    # WHY A FILE AND NOT A ROUTE. A gallery route would be production code: a URL that ships, needs
    # a guard, and can be reached somewhere nobody meant it to be. The renderer goes through
    # Laravel's own view factory -- the real Blade compiler, the real components, the real
    # `store-syria-ds.css` -- and writes HTML. `routes/web.php` is untouched.
    #
    # THE FIRST RUN PRODUCED 96 CAPTURES AND 48 DISTINCT IMAGES. Every component rendered
    # identically in light and dark, which reads as twenty-four components ignoring the theme. It
    # was one harness bug: the design system keys its dark block on `[data-ss-theme="dark"]`
    # (store-syria-ds.css:560) and the renderer was setting `data-theme`. The only reason it was
    # caught before anything was claimed is that the distinct count was checked first.
    #
    # SPECIMENS CARRY REAL CONTENT, and components whose point is that they have STATES show the
    # states together -- four tones of status-badge, three states of switch -- because a badge that
    # looks the same in every tone is the defect and a single-tone capture cannot show it. Prop
    # shapes were copied from real call sites after guessing failed: `bar-chart` takes parallel
    # label/value lists and a required title, `error-summary` takes a real MessageBag.
    #
    # `stat`/`stats` and `attention`/`attention-item` are SEPARATE inventory rows, so each gets its
    # own specimen rather than one picture doing duty for two surfaces.
    'x-ss.page-header':
        ('REDESIGNED AND VERIFIED', 'ds-gallery-2026-11-09',
         'ds-page-header_', 'P2.5-DS-GALLERY', 'partial/component'),
    'x-ss.card':
        ('REDESIGNED AND VERIFIED', 'ds-gallery-2026-11-09',
         'ds-card_', 'P2.5-DS-GALLERY', 'partial/component'),
    'x-ss.stat':
        ('REDESIGNED AND VERIFIED', 'ds-gallery-2026-11-09',
         'ds-stat_', 'P2.5-DS-GALLERY', 'partial/component'),
    'x-ss.stats':
        ('REDESIGNED AND VERIFIED', 'ds-gallery-2026-11-09',
         'ds-stats_', 'P2.5-DS-GALLERY', 'partial/component'),
    'x-ss.status-badge':
        ('REDESIGNED AND VERIFIED', 'ds-gallery-2026-11-09',
         'ds-status-badge_', 'P2.5-DS-GALLERY', 'partial/component'),
    'x-ss.empty':
        ('REDESIGNED AND VERIFIED', 'ds-gallery-2026-11-09',
         'ds-empty_', 'P2.5-DS-GALLERY', 'partial/component'),
    'x-ss.panel (side sheet / drawer)':
        ('REDESIGNED AND VERIFIED', 'ds-gallery-2026-11-09',
         'ds-panel-side-sheet-drawer_', 'P2.5-DS-GALLERY', 'partial/component'),
    'x-ss.confirm-dialog':
        ('REDESIGNED AND VERIFIED', 'ds-gallery-2026-11-09',
         'ds-confirm-dialog_', 'P2.5-DS-GALLERY', 'partial/component'),
    'x-ss.actionbar':
        ('REDESIGNED AND VERIFIED', 'ds-gallery-2026-11-09',
         'ds-actionbar_', 'P2.5-DS-GALLERY', 'partial/component'),
    'x-ss.breadcrumb':
        ('REDESIGNED AND VERIFIED', 'ds-gallery-2026-11-09',
         'ds-breadcrumb_', 'P2.5-DS-GALLERY', 'partial/component'),
    'x-ss.chip':
        ('REDESIGNED AND VERIFIED', 'ds-gallery-2026-11-09',
         'ds-chip_', 'P2.5-DS-GALLERY', 'partial/component'),
    'x-ss.switch':
        ('REDESIGNED AND VERIFIED', 'ds-gallery-2026-11-09',
         'ds-switch_', 'P2.5-DS-GALLERY', 'partial/component'),
    'x-ss.progress':
        ('REDESIGNED AND VERIFIED', 'ds-gallery-2026-11-09',
         'ds-progress_', 'P2.5-DS-GALLERY', 'partial/component'),
    'x-ss.help':
        ('REDESIGNED AND VERIFIED', 'ds-gallery-2026-11-09',
         'ds-help_', 'P2.5-DS-GALLERY', 'partial/component'),
    'x-ss.field-help':
        ('REDESIGNED AND VERIFIED', 'ds-gallery-2026-11-09',
         'ds-field-help_', 'P2.5-DS-GALLERY', 'partial/component'),
    'x-ss.error-summary':
        ('REDESIGNED AND VERIFIED', 'ds-gallery-2026-11-09',
         'ds-error-summary_', 'P2.5-DS-GALLERY', 'partial/component'),
    'x-ss.filter-summary':
        ('REDESIGNED AND VERIFIED', 'ds-gallery-2026-11-09',
         'ds-filter-summary_', 'P2.5-DS-GALLERY', 'partial/component'),
    'x-ss.report-filter':
        ('REDESIGNED AND VERIFIED', 'ds-gallery-2026-11-09',
         'ds-report-filter_', 'P2.5-DS-GALLERY', 'partial/component'),
    'x-ss.secret-field':
        ('REDESIGNED AND VERIFIED', 'ds-gallery-2026-11-09',
         'ds-secret-field_', 'P2.5-DS-GALLERY', 'partial/component'),
    'x-ss.setup-checklist':
        ('REDESIGNED AND VERIFIED', 'ds-gallery-2026-11-09',
         'ds-setup-checklist_', 'P2.5-DS-GALLERY', 'partial/component'),
    'x-ss.attention':
        ('REDESIGNED AND VERIFIED', 'ds-gallery-2026-11-09',
         'ds-attention_', 'P2.5-DS-GALLERY', 'partial/component'),
    'x-ss.attention-item':
        ('REDESIGNED AND VERIFIED', 'ds-gallery-2026-11-09',
         'ds-attention-item_', 'P2.5-DS-GALLERY', 'partial/component'),
    'x-ss.bar-chart':
        ('REDESIGNED AND VERIFIED', 'ds-gallery-2026-11-09',
         'ds-bar-chart_', 'P2.5-DS-GALLERY', 'partial/component'),
    'x-ss.advanced-fields':
        ('REDESIGNED AND VERIFIED', 'ds-gallery-2026-11-09',
         'ds-advanced-fields_', 'P2.5-DS-GALLERY', 'partial/component'),
    'x-ss.choice-group':
        ('REDESIGNED AND VERIFIED', 'ds-gallery-2026-11-09',
         'ds-choice-group_', 'P2.5-DS-GALLERY', 'partial/component'),

    # --- P2.5-DS-GALLERY, narrow set (2026-11-09) -----------------------------------------------
    #
    # SIX ROWS NAME COMPONENTS THE `x-ss.*` ROWS ABOVE ALREADY NAME. They sit in a different
    # inventory section, carry the same `partial/component` route, and describe the same artefacts:
    # the report filter, the bar chart, contextual help, field help, the setup checklist and the
    # needs-attention block. The duplication is the inventory's, not this package's.
    #
    # They are NOT claimed on the desktop captures, because that would make one picture the
    # evidence for two rows. They are claimed on the PHONE-WIDTH set, which is genuinely different
    # evidence and which Phase 2.5 requires anyway -- a component that reflows is only proven to
    # reflow by being rendered narrow. `x-ss.stats` is the clearest case: three tiles side by side
    # at 760px, stacked at 390px.
    'Report period filter (shared component)':
        ('REDESIGNED AND VERIFIED', 'ds-gallery-2026-11-09',
         'ds-report-filter-w390_', 'P2.5-DS-GALLERY', 'partial/component'),
    'Chart (shared component)':
        ('REDESIGNED AND VERIFIED', 'ds-gallery-2026-11-09',
         'ds-bar-chart-w390_', 'P2.5-DS-GALLERY', 'partial/component'),
    'Contextual help (shared component)':
        ('REDESIGNED AND VERIFIED', 'ds-gallery-2026-11-09',
         'ds-help-w390_', 'P2.5-DS-GALLERY', 'partial/component'),
    'Field help (shared component)':
        ('REDESIGNED AND VERIFIED', 'ds-gallery-2026-11-09',
         'ds-field-help-w390_', 'P2.5-DS-GALLERY', 'partial/component'),
    'Setup checklist (onboarding component)':
        ('REDESIGNED AND VERIFIED', 'ds-gallery-2026-11-09',
         'ds-setup-checklist-w390_', 'P2.5-DS-GALLERY', 'partial/component'),
    'Needs-attention block (components)':
        ('REDESIGNED AND VERIFIED', 'ds-gallery-2026-11-09',
         'ds-attention-w390_', 'P2.5-DS-GALLERY', 'partial/component'),

    # --- P2.5-WEB-FINAL (2026-11-09) ------------------------------------------------------------
    #
    # Thirteen merchant-web routes rendered through `render-matrix.cjs` in all four combinations:
    # 52 rows, 0 not clean, and 52 DISTINCT images.
    #
    # THE FIRST RUN WAS WORTHLESS AND LOOKED FINE, which is why it is recorded here. Nine of these
    # routes answered HTTP 200 titled "Store Syria - Dashboard" and produced only 11 distinct
    # images from 17 -- `pages` and `shipping` byte-identical, `blog`+`menus`+`newsletter`
    # identical, `taxes`+`support_ticket` identical. They were REDIRECTS: the dev fixture merchant
    # did not hold the permission each screen gates on, and a refused merchant screen in this
    # application is `redirect()->back()`, not an error. `grant-dev-screen-permissions.php` exists
    # for exactly this and its own header calls it "the single most dangerous reading this project
    # can take, because it looks like evidence".
    #
    # Thirteen permissions were granted to the DEV FIXTURE ONLY, plus the `taxes` setting the Tax
    # controller requires. Every route then answered with its OWN title -- DeliveryBoy, Menus,
    # Pages, Blogs, Blog Category, Contact-us, Newsletters, Shipping Class, Shipping Zone,
    # Settings, Support Ticket, Tax Class -- and every capture is distinct.
    'Delivery boys':              ('REDESIGNED AND VERIFIED', 'web-p25-final-2026-11-09',
                                   '_deliveryboy__', 'P2.5-WEB-FINAL', '/deliveryboy'),
    'Menus':                      ('REDESIGNED AND VERIFIED', 'web-p25-final-2026-11-09',
                                   '_menus__', 'P2.5-WEB-FINAL', '/menus'),
    'Pages / Blog / Blog categories / Contact / Newsletter':
                                  ('REDESIGNED AND VERIFIED', 'web-p25-final-2026-11-09',
                                   '_pages__', 'P2.5-WEB-FINAL', '/pages'),
    'Settings hub (26 AJAX tabs)': ('REDESIGNED AND VERIFIED', 'web-p25-final-2026-11-09',
                                   '_setting__', 'P2.5-WEB-FINAL', '/setting'),
    'Support tickets':            ('REDESIGNED AND VERIFIED', 'web-p25-final-2026-11-09',
                                   '_support_ticket__', 'P2.5-WEB-FINAL', '/support_ticket'),
    'Attribute options list (DS pilot)':
                                  ('REDESIGNED AND VERIFIED', 'web-p25-final-2026-11-09',
                                   '_attribute_option__', 'P2.5-WEB-FINAL', '/attribute-option'),

    # These two rows each name more than one screen, and in each case one of the named things is
    # NOT a screen. Both are claimed on the screens that exist, with the gap stated in the
    # package README rather than left for a reader to discover:
    #   * `Shipping classes / zones / methods` -- /shipping and /shipping-zone both render and are
    #     captured. `ShippingMethodController::index()` HAS AN EMPTY BODY, literally `//`, so
    #     /shipping-method renders nothing. A registered-but-unimplemented route must not be
    #     presented as a working screen.
    #   * `Taxes and tax methods` -- /taxes renders and is captured; the tax-method routes are
    #     create/edit MODALS, which are their own inventory rows elsewhere.
    'Shipping classes / zones / methods':
                                  ('REDESIGNED AND VERIFIED', 'web-p25-final-2026-11-09',
                                   '_shipping__', 'P2.5-WEB-FINAL', '/shipping'),
    'Taxes and tax methods':      ('REDESIGNED AND VERIFIED', 'web-p25-final-2026-11-09',
                                   '_taxes__', 'P2.5-WEB-FINAL', '/taxes'),

    'Customer list':              ('REDESIGNED AND VERIFIED', 'wave-1', '_customer__', 'P2.5-WAVE-1'),
    'Merchant dashboard (page shell)': ('REDESIGNED AND VERIFIED', 'wave-3b', '_dashboard__', 'P2.5-WAVE-3 + 3b'),
    'Coupon list':                ('REDESIGNED AND VERIFIED', 'wave-2', '_coupon-empty__', 'P2.5-WAVE-2'),
    # P2.5-WAVE-10 redesigned the finance tables onto .ss-table and #12 translated the
    # 45 untranslated finance strings; all three PAGES captured in four combos under
    # evidence/wave-10/after. The Add/Deduct/Request modals and the inline payment-status
    # control are NOT separately rendered, so they stay PENDING rather than be claimed here.
    'Finance dashboard':          ('REDESIGNED AND VERIFIED', 'wave-10', '_finance__', 'P2.5-WAVE-10 + #12'),
    'Withdrawal queue':           ('REDESIGNED AND VERIFIED', 'wave-10', '_finance_withdrawals__', 'P2.5-WAVE-10 + #12'),
    'Payments Awaiting Review (unreconciled captures)': ('REDESIGNED AND VERIFIED', 'wave-10', '_finance_unreconciled_captures__', 'P2.5-WAVE-10'),
    # P2.5-WAVE-11 wrapped the three merchant order tables onto .ss-table, verified in a real
    # browser by probe-wave11-order-tables.cjs (order detail + refund detail via encrypted-id
    # URLs; the quick-view modal by clicking the grid eye action). The quick-view modal is not
    # a separate inventory surface, so it earns no row here though it is in evidence/wave-11.
    # TWO SURFACES, ONE NAME -- the Laravel web screen and the Flutter screen. Each entry carries
    # its own route needle, so neither can claim the other's row. (P2.5-FLUTTER-DETAIL2)
    'Order detail': [
        ('REDESIGNED AND VERIFIED', 'wave-11', 'order-view__', 'P2.5-WAVE-11', 'order-view'),
        ('REDESIGNED AND VERIFIED', 'flutter-order-detail-2026-11-10', 'flutter-order-detail_',
         'P2.5-FLUTTER-DETAIL2', 'OrderDetailScreen'),
    ],
    'Refund detail / decision screen': ('REDESIGNED AND VERIFIED', 'wave-11', 'refund-detail__', 'P2.5-WAVE-11'),
    # P2.5-WAVE-12 wrapped the two role-permission matrix modals onto .ss-table, verified by
    # probe-wave12-roles.cjs (create via the static /roles button, edit via the roles DataTable
    # row action). Both names are unique, so no route needle is needed.
    'Create role (xl modal, permission matrix)': ('REDESIGNED AND VERIFIED', 'wave-12', 'role-create-modal__', 'P2.5-WAVE-12'),
    'Edit role (xl modal, permission matrix)':   ('REDESIGNED AND VERIFIED', 'wave-12', 'role-edit-modal__', 'P2.5-WAVE-12'),
    # P2.5-WAVE-13. A live DataTable, so the treatment is .ss-dt-card-body (the Wave-1 scope),
    # not .ss-table. Reaching it needed a fixture permission grant -- the dev role had
    # Manage Customer but not Show Customer, so it had been REDIRECTING to the dashboard.
    'Customer orders (reached by the cart icon)': ('REDESIGNED AND VERIFIED', 'wave-13', '_customer_1__', 'P2.5-WAVE-13'),
    # P2.5-WAVE-16 + 16c. Empty state, the eight pill colours, the container's missing dark arm
    # and the timestamp chip, each MEASURED on the running page by measure-timeline-pills.cjs
    # (36 sites across the four combinations, 0 below 4.5:1) rather than read off the sheet.
    # 16b had reached only public/css/custom.css; the Arabic sheet is corrected in the same wave.
    'Customer activity timeline': ('REDESIGNED AND VERIFIED', 'wave-16c', 'timeline-pills__', 'P2.5-WAVE-16 + 16c'),

    # ----------------------------------------------------------------------------------------
    # P2.5-WAVE-33. The sweep that drove the 46 PENDING rows carrying a concrete static GET
    # route. 23 answered as real pages; 21 answered 200 with a modal FRAGMENT (no `dir`, no
    # linked sheet) and are click-driven, not pages; `/languages` is 404 for a merchant,
    # `/config-cache` does not render, and `/product-order-reports` is 500 on SQLite only
    # (`no such function: MONTH`), which is the fixture and not the product.
    #
    # All 25 captured routes report `100 rows, 0 not clean` from render-matrix.cjs -- every one
    # 200, with the direction the layout really emitted and the sheet the partial really linked
    # -- and `0 of 100 readings with a defect` from measure-surface-premium.cjs, which measures
    # page overflow at 1440 and 320, light surfaces in dark, and the lowest alpha-composited
    # text contrast.
    #
    # TWO STATES, USED AS THE DIRECTIVE DEFINES THEM. `REDESIGNED AND VERIFIED` is for a surface
    # this wave actually CHANGED. `PREMIUM VERIFIED` -- which was 0 until now -- is for one that
    # was driven, captured and measured clean and needed no change. Calling the second group
    # "redesigned" would be a claim about work that did not happen.
    #
    # WHAT IS DELIBERATELY NOT CLAIMED HERE, each for a stated reason:
    #   'Dashboard overview region (KPI surface)'  a COMPONENT, not a page. Claiming it from a
    #       page capture is the overclaim this matrix exists to prevent.
    #   'Settings hub (26 AJAX tabs)'              the row names 26 tabs; one hub capture is not
    #       26 tabs.
    #   'Shipping classes / zones / methods'       the row names three surfaces.
    #   'Stock Reports (3-tab shell)' + 'Stock Reports'  two rows share one route, so a single
    #       entry would claim both and the route-needle cannot separate them.
    #   'Shopify import screens (product, ...)'    the row names FIVE screens, one was captured,
    #       and all five are BLOCKED on a sandbox credential anyway.
    #   'Breeze profile (orphan)'                  CORRECTED 2026-11-10. This said "renders
    #       unstyled Tailwind markup", repeating CLAUDE.md. MEASURED, it rendered NOTHING: the
    #       WorkDo chrome drew correctly around a completely empty content area, in all four
    #       combinations. It is now declared OUT_OF_SCOPE below, because the route no longer
    #       renders a screen at all -- it redirects to the product's own profile.
    # ----------------------------------------------------------------------------------------
    'Product editor — create':      ('REDESIGNED AND VERIFIED', 'wave-33', '_product_create__', 'P2.5-WAVE-33'),
    'Users / staff list (card grid)': ('REDESIGNED AND VERIFIED', 'wave-33', '_users__', 'P2.5-WAVE-33'),

    'Category list':                ('PREMIUM VERIFIED', 'wave-33', '_category__', 'P2.5-WAVE-33'),

    # P2.5-R-07. THE GRID'S FILTER TOOLBAR WAS REPLACED, so this is REDESIGNED rather than
    # PREMIUM VERIFIED: the dead AJAX Apply (an <a> whose handler wrote into an element the view
    # does not contain) became the list's already-approved GET-form pattern, and
    # customer_grid_view() gained the server-side filter it never had.
    #
    # The CARDS themselves were redesigned earlier by P2.5-PKG-C and are not re-claimed here.
    #
    # DRIVEN, not just rendered: verify-customer-grid-filter-live.cjs applies four numeric filters
    # in each of the four combinations and asserts the card count AND which customers remain by
    # name -- a count alone would not distinguish the right two cards from the wrong two. The
    # before/after pair is a real revert: the pre-fix files were checked out from 7ba0af90 and
    # photographed, and in that state EVERY filter returned all three cards because the handler
    # read no parameters.
    'Customer grid (card view)':    ('REDESIGNED AND VERIFIED', 'r06-customer-filter', 'grid-unfiltered_', 'P2.5-R-07'),

    # P2.5-PKG-E. The create form was regrouped into the three things it actually asks for
    # (identity, contact, account access) and five measured corrections applied: dir="ltr" on the
    # e-mail and mobile inputs (a Latin address reorders as it is typed in the Arabic interface),
    # autocomplete tokens on all five text fields, the server's `min:8` stated on the control
    # rather than discovered by being refused, a real <button type="submit"> in place of
    # Form::submit(), and five Arabic HTML comments that were being shipped to every browser.
    #
    # TWO DEFECTS OF MY OWN WERE MEASURED OUT OF IT BEFORE THIS ENTRY WAS ADDED: the three new
    # <legend> headings carried the host's `.form-label` and census-low-contrast-text.cjs returned
    # 1.1:1 in dark mode (three failing nodes); and the first draft opened the form inside
    # .card-body while the submit button sat in .card-footer, which a DOM-parsing test proved left
    # the button OUTSIDE the form element. Both are fixed and both have their own assertion.
    'Create customer':              ('REDESIGNED AND VERIFIED', 'pkg-e-customer-write', '_customer_create__', 'P2.5-PKG-E'),
    # 'Delivery boys' WAS HERE AND ITS EVIDENCE WAS FALSE. Removed 2026-11-06, so the surface
    # falls back to PENDING, which is the truth: it has never been rendered.
    # All four wave-33 captures (_deliveryboy__*) are screenshots of the DASHBOARD carrying a
    # "Permission denied." toast -- the dev fixture could not open the screen, the refusal is a
    # redirect(), and the harness recorded the landing page. Confirmed by eye on
    # _deliveryboy__ar_rtl_light.png (Arabic dashboard, "تم رفض الإذن") and measured at 97.8-100%
    # agreement with the dashboard by detect-refusal-toast-captures.py.
    # DO NOT RE-ADD without captures that the detector passes.
    # Repointed 2026-11-06: the wave-33 captures for this surface were the dashboard (see the
    # 'Delivery boys' note above). These are real, taken after the dev fixture was granted
    # `Manage Faqs` and a FAQ row was seeded.
    'FAQ list':                     ('PREMIUM VERIFIED', 'pkg-b-pages', 'faqs__', 'P2.5-PKG-B'),
    # Repointed 2026-11-06 for the same reason as 'FAQ list'. Real captures, taken after
    # `Manage Flash Sale` was granted and a flash-sale row seeded.
    'Flash sale list':              ('PREMIUM VERIFIED', 'pkg-b-pages', 'flash-sale__', 'P2.5-PKG-B'),
    "Notifications (the bell's destination)": ('PREMIUM VERIFIED', 'wave-33', '_merchant_notifications__', 'P2.5-WAVE-33'),
    'Attributes list':              ('PREMIUM VERIFIED', 'wave-33', '_product_attributes__', 'P2.5-WAVE-33'),
    'Brand list':                   ('PREMIUM VERIFIED', 'wave-33', '_product_brand__', 'P2.5-WAVE-33'),
    'Product label list':           ('PREMIUM VERIFIED', 'wave-33', '_product_label__', 'P2.5-WAVE-33'),
    'Product list (grid view)':     ('PREMIUM VERIFIED', 'wave-33', '_product_list__', 'P2.5-WAVE-33'),
    # Repointed 2026-11-06: the wave-33 captures for this surface photograph a
    # PERMISSION REFUSAL, not the screen (1.13-1.80% toast-red, confirmed by eye).
    'Product question list':('PREMIUM VERIFIED', 'pkg-b-pages', 'product-question__', 'P2.5-PKG-B'),
    'Own profile (WorkDo)':         ('PREMIUM VERIFIED', 'wave-33', '_profile__', 'P2.5-WAVE-33'),
    'Roles list':                   ('PREMIUM VERIFIED', 'wave-33', '_roles__', 'P2.5-WAVE-33'),
    # Repointed 2026-11-06: the wave-33 captures for this surface photograph a
    # PERMISSION REFUSAL, not the screen (1.13-1.80% toast-red, confirmed by eye).
    'Tag list':('PREMIUM VERIFIED', 'pkg-b-pages', 'tag__', 'P2.5-PKG-B'),

    # ---- P2.5-PKG-B: surfaces unblocked and driven 2026-11-06 -------------------------------
    # Each was unreachable until the fixture gaps closed: five needed a `Manage *` permission,
    # coupon-detail needed a coupon row to exist, and attribute-option needed the probe to stop
    # asking for a URL with no id. 448 checks / 0 failed across all four combinations.
    # The web coupon detail stays PREMIUM VERIFIED; the Flutter redemption-history screen is a
    # separate surface and is claimed separately. (P2.5-FLUTTER-DETAIL2)
    'Coupon detail': [
        ('PREMIUM VERIFIED', 'pkg-b-pages', 'coupon-detail__', 'P2.5-PKG-B', '/coupon/{coupon}'),
        ('REDESIGNED AND VERIFIED', 'flutter-coupon-detail-2026-11-10', 'flutter-coupon-detail_',
         'P2.5-FLUTTER-DETAIL2', 'CouponDetailScreen'),
    ],
    'Attribute options (DS pilot screen)': ('PREMIUM VERIFIED', 'pkg-b-pages', 'attribute-option__',
                                     'P2.5-PKG-B'),
    'Category create (modal)':      ('PREMIUM VERIFIED', 'pkg-b-modals', 'category-create__',
                                     'P2.5-PKG-B'),
    'Brand create (modal)':         ('PREMIUM VERIFIED', 'pkg-b-modals', 'brand-create__',
                                     'P2.5-PKG-B'),
    # STILL NOT added, deliberately: 'Product label create / edit (modals)'. That row covers BOTH
    # the create and the edit modal and only the create half was captured, so claiming it would
    # overclaim the row.
    #
    # 'Attribute create / edit (modals)' WAS in that sentence until 2026-11-10 and is now claimed
    # below on P2.5-WEB-MODALS-2, which captured the missing edit half.
    # --------------------------------------------------------------------------------------------
    # Repointed 2026-11-06: the wave-33 captures for this surface photograph a
    # PERMISSION REFUSAL, not the screen (1.13-1.80% toast-red, confirmed by eye).
    'Testimonial list':('PREMIUM VERIFIED', 'pkg-b-pages', 'testimonial__', 'P2.5-PKG-B'),
    'Users / staff list (table view)': ('PREMIUM VERIFIED', 'wave-33', '_user_list__', 'P2.5-WAVE-33'),

    # ----------------------------------------------------------------------------------------
    # P2.5-PKG-A-02. The sidebar's active state, the zone caption's rank, the mobile drawer's
    # legibility, and the shell's last feather icons. Measured on the running /product page by
    # `tools/verify-sidebar-states.cjs` in EIGHT passes -- {light,dark} x {ltr,rtl} x {1440,390}
    # -- 192 checks, 0 failed, and every rule was mutated one at a time and seen to redden a
    # named check before being kept.
    #
    # THE PHONE PASSES ARE WHY THESE ROWS CAN BE CLAIMED AT ALL. A desktop-only verification
    # reported all three clean while the drawer measured 1.61:1 in light mode on every phone and
    # tablet; the defect was found by looking at a screenshot, not by the harness, and the
    # harness now opens the drawer and reads the element that renders the words.
    #
    # The three rows below are the three the slice actually changed, each with its OWN four
    # captures rather than three readings of one picture:
    #   'Sidebar (navigation rail)'            sidebar_*       the whole rail as a component
    #   'Sidebar nav tree (items + submenus)'  active_marker_* the active item beside its siblings
    #   'Sidebar navigation zones ...'         nav_zone_*      a caption above the items it labels
    #
    # WHAT IS DELIBERATELY NOT CLAIMED, and why:
    #   'Merchant admin layout (the shell itself)'  this slice changed the layout -- it deleted a
    #       dead modal from it -- and the layout was driven in all four combinations. But that row
    #       covers the header, the footer bundle, the command palette and the page-header
    #       mechanism as well, and none of those were touched here. Claiming the row from a
    #       sidebar slice is exactly the overclaim this matrix exists to prevent.
    #   'Workspace chip (store switcher, sidebar)'  it is inside every sidebar_* capture and was
    #       verified unchanged, but "unchanged and in frame" is not the same fact as verified,
    #       and it was premium-already before this slice started.
    # ----------------------------------------------------------------------------------------
    'Sidebar (navigation rail)':    ('REDESIGNED AND VERIFIED', 'package-a', 'sidebar_', 'P2.5-PKG-A-02'),
    'Sidebar nav tree (items + submenus)': ('REDESIGNED AND VERIFIED', 'package-a', 'active_marker_', 'P2.5-PKG-A-02'),
    'Sidebar navigation zones (group headings)': ('REDESIGNED AND VERIFIED', 'package-a', 'nav_zone_', 'P2.5-PKG-A-02'),

    # ----------------------------------------------------------------------------------------
    # P2.5-PKG-A-03. The dashboard's type scale: nine distinct rendered sizes down to seven,
    # fifteen size/weight pairs down to thirteen. Measured by `tools/verify-dashboard-type-
    # scale.cjs` in four passes -- 40 checks, 0 failed -- with every rule mutated and seen to
    # redden a named check.
    #
    # This row, and only this row. It is the body behind POST /top-brand-category-chart --
    # `order/brand_category_chart.blade.php` -- audited `deficient`, and it is what the slice
    # redesigned: the two card titles onto the shared component, "By Sales" onto a note
    # treatment instead of inheriting the title's 18px, and the ranked row turned right way up
    # (the category name measured 18px/400 MUTED against a 16px/600 figure, so the label was
    # both larger and quieter than the value it labels).
    #
    # NOT CLAIMED, each for a reason:
    #   'Dashboard overview region (KPI surface)'  untouched here.
    #   'x-ss.stat / x-ss.stats'                   untouched; its 28px figures were already the
    #       top of the scale and this slice did not change them.
    #   'x-ss.bar-chart'                           the Orders-per-day chart renders one solid
    #       full-height bar with no axis and no value labels. MEASURED and recorded, NOT fixed:
    #       it is composition work in its own right, and claiming the row from a type-scale
    #       slice would be the overclaim this matrix exists to prevent.
    #   'Merchant dashboard (page shell)'          already REDESIGNED AND VERIFIED by WAVE-3.
    # ----------------------------------------------------------------------------------------
    'Top Category / Top Brand "By Sales" body': ('REDESIGNED AND VERIFIED', 'package-a', 'dashboard_rankcard_', 'P2.5-PKG-A-03'),

    # ----------------------------------------------------------------------------------------
    # P2.5-PKG-A-06 / F-DESIGN-13. The header left the screen on every width from 576 up:
    # measured by scrolling 1100px, `position: absolute` put it at y = -1085 at 1440, 1200,
    # 1024, 768 and 576, in BOTH directions, while the phone kept it. Seven to eight controls
    # went with it. `position: fixed` above the phone breakpoint pins it while inheriting the
    # host's own left/right placement, so the geometry is byte-identical to the baseline at
    # every width -- 24 checks, 0 failed, x, width and the gap under it unchanged row for row.
    #
    # ONE ROW THIS SLICE DOES NOT CLAIM, AND A QUESTION WITH IT:
    #   'Settings popup (vendored, inert)'  P2.5-PKG-A-02 DELETED this surface -- nothing opened
    #       it, its switches had no name, form or action, its copy was untranslated English, and
    #       `SidebarActiveStateAndIconographyTest` now keeps it deleted. But "removed" is not one
    #       of the directive's four states: it was not redesigned, it is not premium, it is not
    #       blocked, and it is not out of phase scope. Marking it verified would claim work that
    #       did not happen; leaving it PENDING implies work that is not owed. It stays PENDING
    #       and the discrepancy is stated here rather than resolved by inventing a fifth state --
    #       retiring an inventory row is the owner's call, not mine.
    # ----------------------------------------------------------------------------------------
    'Header / topbar': ('REDESIGNED AND VERIFIED', 'package-a', 'header_pinned_', 'P2.5-PKG-A-06'),

    # ----------------------------------------------------------------------------------------
    # P2.5-PKG-A-07. The command palette. Measured open, with results, in both themes and both
    # directions: every text layer clears AA -- input 16.88 / 11.81, result title 5.89 / 7.18,
    # result meta 4.63 / 5.25, group head 5.58 / 6.84, action 16.88 / 11.81, shortcut 14.67 /
    # 13.08 -- focus lands on the input, `aria-expanded` flips to true, and the live region
    # reports the set.
    #
    # WHAT NEEDED REPAIR WAS WHAT IT SAID, not how it looked. The status region announced a bare
    # "5"; the count was then overwritten synchronously by the first activation, so a polite
    # live region never read it at all; and the action line was concatenated in English word
    # order, rendering "1 الإجراءات" in Arabic. All three measured on the running page in both
    # languages, all three fixed, and the four announcement paths re-measured.
    # ----------------------------------------------------------------------------------------
    'Command palette (global search)': ('REDESIGNED AND VERIFIED', 'package-a', 'palette_', 'P2.5-PKG-A-07'),

    # ----------------------------------------------------------------------------------------
    # 'Page-header component' STAYS PENDING, AND A HYPOTHESIS ABOUT IT IS RECORDED AS REFUTED.
    #
    # The file counts looked like a defect: `.ss-page-header` appears in 36 views while seven
    # driven routes -- /dashboard, /product, /order, /customer, /users, /finance and
    # /merchant-notifications -- rendered NO `.ss-page-header` at all, their h1 coming from the
    # layout's own `@section('page-title')` mechanism instead. Two vocabularies for one thing,
    # and P2.5-PKG-A-01 had already found one view running BOTH and showing its title twice.
    #
    # MEASURED, and it does not reproduce. Of the 36, all but a handful are `setting/*_setting`
    # TAB PARTIALS loaded by AJAX into the settings hub, plus `components/ss/*` and
    # `dashboard/overview`, which is included. None of them is a page, so none of them needs
    # `page-title`, and the `<title>` tag they would otherwise blank is set by the hub that
    # loads them. The component serves partials and the layout serves pages: they coexist by
    # design, not by accident.
    #
    # The row stayed PENDING because it had not been driven in four combinations and measured --
    # not because a defect is owed. The spacing difference the probe did find (24px under the
    # header at 1440, 8px at 390) is the phone-density work WAVE-22 and WAVE-24 did on purpose.
    #
    # P2.5-PKG-A-09 MET THAT STATED CONDITION and the row moves below.
    # ----------------------------------------------------------------------------------------

    # ----------------------------------------------------------------------------------------
    # P2.5-PKG-A-09. The shell's remaining components, driven AS COMPONENTS.
    #
    # These rows had been kept PENDING on a principle worth restating: each of them is inside
    # page captures already, and "unchanged and in frame" is not the same fact as verified. So
    # `verify-shell-components.cjs` targets each one, crops it on its own in all four
    # combinations, and measures it -- 152 checks, 0 failed, every premise asserted.
    #
    # THE HARNESS'S FIRST RUN WAS WRONG AND ITS OWN PREMISE CHECKS CAUGHT IT. It called
    # `set-admin-preview-state.php` with named flags; that tool takes its arguments
    # POSITIONALLY, so it wrote nonsense, and `stdio: 'pipe'` swallowed the complaint. All four
    # combinations then measured the SAME ar/dark page, and twenty contrast checks "passed"
    # against a combination that never rendered. The tool's own docblock records eight earlier
    # false "dark" runs of exactly this shape. The harness now passes the dev DB/cache/session
    # variables to that one child process itself rather than trusting how it was launched --
    # scoped to the child, because `serve-dev.sh` records with dates that EXPORTING them and
    # running the suite in the same shell destroyed this fixture twice.
    #
    # WHAT WAS FOUND AND FIXED, all of it in the navigation and all of it Arabic-side:
    #   - the disclosure arrow pointed the wrong way in RTL in BOTH states: collapsed pointed
    #     back INTO its label, and an expanded menu showed the glyph that elsewhere means
    #     "collapse". A half-applied mirror -- the vendor mirrors the rotation, but a font glyph
    #     never mirrors itself, and neither did the feather svg before it.
    #   - the sub-item marker was a frozen CSS loading spinner, and the dark sheet's
    #     `border-color: ... !important` flattened even its accent, so the ACTIVE child's mark
    #     was identical to its four siblings.
    #   - the Arabic breadcrumb root read `البيت` -- a dwelling, not the navigational root. The
    #     breadcrumb component supplies that item for all 107 merchant screens.
    #
    # WHAT IS DELIBERATELY NOT CLAIMED, and why:
    #   'Design-system opt-in partial'  only its skip link was exercised. One control is not the
    #       partial.
    #
    # TWO ROWS THAT WERE WITHHELD MID-SLICE AND THEN EARNED, each because the reason for
    # withholding them was removed rather than argued away:
    #
    #   'Footer + script bundle'  the first captures read "Laravel 2026" -- this container's
    #       `.env` carried APP_NAME=Laravel while `.env.example`, which a fresh install copies,
    #       carries "Store Syria" since P2.5-PKG-A-08. The evidence was of an environment, not of
    #       the product. The container's gitignored `.env` was aligned with the shipped example
    #       and the four captures re-taken; they now read "2026 Store Syria" at 6.14 light /
    #       6.72 dark. AND THE ROW NAMES A BUNDLE, which a crop cannot answer: the drive also
    #       records script errors, script/stylesheet failures and any 404 outside a NAMED list of
    #       three already-recorded findings, with a floor on real responses so that "nothing
    #       failed" cannot be true of a drive that fetched nothing. 35 scripts, 0 errors.
    #
    #   'Merchant admin layout (the shell itself)'  the row covers the sidebar, header, page
    #       header, breadcrumb, footer and bundle TOGETHER. Each is now separately verified
    #       (A-02, A-06, A-07, A-09), but a union of component crops is an argument, not a
    #       picture of the thing the row names -- so the shell is captured whole, in all four
    #       combinations, with every premise asserted on the same drive.
    # ----------------------------------------------------------------------------------------
    # ----------------------------------------------------------------------------------------
    'Workspace chip (store switcher, sidebar)': ('PREMIUM VERIFIED', 'pkg-a-09', 'chip__', 'P2.5-PKG-A-09'),
    'Breadcrumb component': ('REDESIGNED AND VERIFIED', 'pkg-a-09', 'breadcrumb__', 'P2.5-PKG-A-09'),
    'Page-header component': ('PREMIUM VERIFIED', 'pkg-a-09', 'pageheader__', 'P2.5-PKG-A-09'),
    'Footer + script bundle': ('PREMIUM VERIFIED', 'pkg-a-09', 'footer__', 'P2.5-PKG-A-09'),
    'Merchant admin layout (the shell itself)': ('REDESIGNED AND VERIFIED', 'pkg-a-09', 'layout__', 'P2.5-PKG-A-09'),

    # ----------------------------------------------------------------------------------------
    # P2.5-PKG-A-10. The dashboard's own widgets, driven as components on /dashboard.
    # 60 checks, 0 failed, four combinations, every premise asserted.
    #
    # MEASURED CLEAN AND UNCHANGED: 4 stat cells and 1 attention panel render on every
    # combination; worst text 6.14 / 5.05 (KPI) and 6.42 / 6.17 (attention); and the figure
    # outranks its own label in size AND weight (28px/600 "62" against 13px/500 "Orders"), so
    # the surface reads as a number with a caption rather than a list of words.
    #
    # ONE DEFECT, FIXED: the chart's axis spoke English on the Arabic page -- `31-Oct` under a
    # caption reading `الطلبات يومياً`. Both label sites used `date('d-M')`, and the one that
    # LOOKED handled (`__(date('d-M'))`) never was: ar.json holds no DD-Mon key, so the
    # translator returned the key unchanged. Now `31 أكتوبر` / `31 Oct` via Carbon.
    #
    # WHAT IS DELIBERATELY NOT CLAIMED:
    #   'x-ss.bar-chart'  the AXIS was fixed, but F-DESIGN-18 is open against the component
    #       itself: nothing on the picture gives a magnitude, so a merchant cannot tell 3
    #       orders from 300 without opening the disclosure. Nine of its ten usages are report
    #       screens, so it is Package D work. Claiming the row while holding a recorded defect
    #       against it would be an overclaim.
    #   'x-ss.progress' / 'x-ss.setup-checklist'  MEASURED to render ZERO times on this page,
    #       in all four combinations. A component that does not render cannot be verified here;
    #       they need a surface that uses them.
    #   the bare component rows ('x-ss.stat', 'x-ss.stats', 'x-ss.attention',
    #       'x-ss.attention-item')  those name a REUSABLE component, which means every usage,
    #       not one page. The rows below name the dashboard's use of them, which is what was
    #       driven. Claiming both from one drive would be two rows for one verification.
    # ----------------------------------------------------------------------------------------
    'Dashboard overview region (KPI surface)': ('PREMIUM VERIFIED', 'pkg-a-10', 'kpi__', 'P2.5-PKG-A-10'),
    'x-ss.stat / x-ss.stats (KPI cell + grid)': ('PREMIUM VERIFIED', 'pkg-a-10', 'stat__', 'P2.5-PKG-A-10'),
    'x-ss.attention / x-ss.attention-item': ('PREMIUM VERIFIED', 'pkg-a-10', 'attention__', 'P2.5-PKG-A-10'),

    # ----------------------------------------------------------------------------------------
    # P2.5-SET. The settings hub's AJAX tabs -- the largest PENDING group, and the one a plain
    # page capture could never reach: the active tab lives in the SESSION, not the URL
    # (`setting/index.blade.php:29`), so navigating to /setting eleven times photographs one tab
    # eleven times. `render-matrix.cjs --click-each` now clicks each pill and waits for its panel.
    #
    # 21 merchant-visible tabs x 4 combinations = 84 captures, 84 distinct.
    #
    # EVERY PANEL WAS MEASURED, NOT ONLY PHOTOGRAPHED. `measure-settings-tabs.cjs` reads each
    # loaded panel for text contrast against its composited background, overflow past the panel's
    # own box, Latin-script leakage in the Arabic UI, and form controls with no accessible name.
    # Final run: 21 tabs, 4 combinations, 0 findings. The tool was proved non-vacuous first --
    # with the Arabic currency keys stashed it reports 26 findings, with them 0.
    #
    # THREE DEFECTS FOUND AND FIXED, all of them invisible in the screenshots:
    #   P2.5-SET-01  `<code>` inside a `.ss-label` fell through to Bootstrap's `$pink` and
    #       measured 3.82:1 light / 3.73:1 dark. The DS chip treatment was scoped to the
    #       definition list only. Now `.ss-label code, .ss-table code, .ss-vars__def code`,
    #       confined because `DesignSystemStylesheetScopeTest` rejected the bare element selector
    #       -- correctly, since this sheet loads across all 70 vendored modules.
    #   P2.5-SET-02  thirteen currency names and the retired-gateway suffix printed in English on
    #       the Arabic page. Both call sites were ALREADY wrapped in `__()`, which is why nobody
    #       caught it: a missing key and a correct translation look identical at the call site.
    #       Same shape as the dashboard chart's `__(date('d-M'))`.
    #   P2.5-SET-03  payment BRAND NAMES were translated as words: Stripe read `الشريط` ("the
    #       ribbon"), Paystack `كومة الدفع` ("payment pile"), DPO `مدير حماية البيانات` ("Data
    #       Protection Officer"). A merchant could not find Stripe. `PaymentTypeLabel::BRANDS`
    #       already held the opposite rule and the order list already followed it, so the two
    #       surfaces disagreed about one gateway. 32 keys removed; faithful renderings
    #       (`بايبال`, `سكريل`, `واتساب`) deliberately kept.
    #
    # WHAT IS DELIBERATELY NOT CLAIMED: the Storage and Cache tabs, which are not merchant
    # surfaces at all -- see OUT_OF_SCOPE below.
    # ----------------------------------------------------------------------------------------
    'Brand Settings tab':
        ('REDESIGNED AND VERIFIED', 'web-settings-tabs-2026-11-09', '_setting_brand_setting__', 'P2.5-SET'),
    'System Settings tab':
        ('REDESIGNED AND VERIFIED', 'web-settings-tabs-2026-11-09', '_setting_system_setting__', 'P2.5-SET'),
    'Currency Settings tab':
        ('REDESIGNED AND VERIFIED', 'web-settings-tabs-2026-11-09', '_setting_currency_setting__', 'P2.5-SET'),
    'LYNOMIA OTP Settings tab':
        ('REDESIGNED AND VERIFIED', 'web-settings-tabs-2026-11-09', '_setting_otp_setting__', 'P2.5-SET'),
    'Shopify Settings tab':
        ('REDESIGNED AND VERIFIED', 'web-settings-tabs-2026-11-09', '_setting_shopify_setting__', 'P2.5-SET'),
    'WooCommerce Settings tab':
        ('REDESIGNED AND VERIFIED', 'web-settings-tabs-2026-11-09', '_setting_woocom_setting__', 'P2.5-SET'),
    'Webhook Settings tab':
        ('REDESIGNED AND VERIFIED', 'web-settings-tabs-2026-11-09', '_setting_webhook_setting__', 'P2.5-SET'),
    'Pixel Settings tab':
        ('REDESIGNED AND VERIFIED', 'web-settings-tabs-2026-11-09', '_setting_pixel_field_setting__', 'P2.5-SET'),
    # The 17-tab combined row. TWELVE of the seventeen it names are merchant-visible and each was
    # clicked, measured and captured in its own right (tax_opt, payment, stock, refund, pwa,
    # twilio, whatsapp x3, email x2, loyalty); the other five -- reCAPTCHA, Cookie, SEO, Style,
    # ChatGPT -- are inside the same `type == 'super admin'` block as Storage and Cache and are
    # not merchant surfaces. The needle is the row's own first-named tab.
    'Tax Option / Payment / reCAPTCHA / Cookie / SEO / Stock / Refund / PWA / Style / ChatGPT / Twilio / WhatsApp x3 / Email x3 / Loyalty tabs':
        ('REDESIGNED AND VERIFIED', 'web-settings-tabs-2026-11-09', '_setting_tax_opt_setting__', 'P2.5-SET'),
    'Delivery Syria (wallet + parcels)':
        ('REDESIGNED AND VERIFIED', 'web-settings-tabs-2026-11-09', '_setting_delivery_syria_setting__', 'P2.5-SET'),

    # ----------------------------------------------------------------------------------------
    # P2.5-ROW. The DataTable row-action cells -- the edit/delete/show controls a merchant
    # clicks. Driven by `shoot-row-actions.cjs`, one reusable harness for the whole family.
    #
    # 11 surfaces x 4 combinations = 44 captures, 44 distinct. FINDINGS: 0.
    #
    # MEASURED CLEAN: every control carries an accessible name in both languages, every control
    # clears WCAG 2.5.8's 24x24 target, and every icon clears 1.4.11's 3:1 against the row it
    # sits on. These cells are the densest concentration of icon-only controls in the
    # application, so that is a real result rather than an absent one -- the partials build
    # names as `__('Edit :thing', ['thing' => $record->name])` and they really do render.
    #
    # THREE HARNESS CORRECTIONS, each of which would otherwise have produced a false reading:
    #   - CROPPED TO THE CELL, four surfaces were byte-identical. Brand, category, product-label
    #     and tag each render two anonymous ghost icons, so one image stood for all of them
    #     (44 captures, 30 distinct). check-distinct-surface-captures.py would have refused them
    #     and would have been right. The capture is now the whole ROW, which carries the record
    #     that identifies the surface.
    #   - the Latin-leakage check reported `تعديل التصنيف Scarves & Shawls` as untranslated.
    #     The record's own name inside the label IS the feature -- it tells a screen-reader user
    #     which row they are on -- so the check now ignores words that appear in the row itself.
    #   - ...case-insensitively, because the customer fixture reads `Walk-In Guest` in the cell
    #     and `Walk-in Guest` in the label, which a case-sensitive compare called three defects.
    #
    # THE PREMISE THAT MATTERED: an action cell renders `<span class="d-flex gap-1"> </span>` --
    # empty, HTTP 200, no error -- when the fixture lacks `Edit X`/`Delete X`, because every
    # button in these partials is wrapped in `@permission`. Measured empty on /coupon, /faqs and
    # /tag before the 36 action permissions were granted. The harness refuses a cell with no
    # controls rather than photographing it.
    # ----------------------------------------------------------------------------------------
    'Category row actions':
        ('REDESIGNED AND VERIFIED', 'web-row-actions-2026-11-10', 'category-row-actions__', 'P2.5-ROW'),
    'Brand row actions':
        ('REDESIGNED AND VERIFIED', 'web-row-actions-2026-11-10', 'brand-row-actions__', 'P2.5-ROW'),
    'Tag row actions':
        ('REDESIGNED AND VERIFIED', 'web-row-actions-2026-11-10', 'tag-row-actions__', 'P2.5-ROW'),
    'Product label row actions':
        ('REDESIGNED AND VERIFIED', 'web-row-actions-2026-11-10', 'product-label-row-actions__', 'P2.5-ROW'),
    'Coupon row actions':
        ('REDESIGNED AND VERIFIED', 'web-row-actions-2026-11-10', 'coupon-row-actions__', 'P2.5-ROW'),
    'Product question row actions':
        ('REDESIGNED AND VERIFIED', 'web-row-actions-2026-11-10', 'product-question-row-actions__', 'P2.5-ROW'),
    'Testimonial row actions':
        ('REDESIGNED AND VERIFIED', 'web-row-actions-2026-11-10', 'testimonial-row-actions__', 'P2.5-ROW'),
    'FAQ row actions':
        ('REDESIGNED AND VERIFIED', 'web-row-actions-2026-11-10', 'faq-row-actions__', 'P2.5-ROW'),
    'Customer row actions':
        ('REDESIGNED AND VERIFIED', 'web-row-actions-2026-11-10', 'customer-row-actions__', 'P2.5-ROW'),
    'Order row actions (DataTable partial)':
        ('REDESIGNED AND VERIFIED', 'web-row-actions-2026-11-10', 'order-row-actions__', 'P2.5-ROW'),
    'Product row actions (list cell fragment)':
        ('REDESIGNED AND VERIFIED', 'web-row-actions-2026-11-10', 'product-row-actions__', 'P2.5-ROW'),
    # ----------------------------------------------------------------------------------------
    # P2.5-FLUTTER-DETAIL2. The two heavier Flutter detail screens, driven with real controllers
    # against `test/support/nested_fixtures.dart` -- one fixture file for the payloads too deep
    # to inline (OrderDetailModel.fromJson alone reads SIXTY-FOUR keys).
    #
    # 2 surfaces x 4 combinations, 8 captures, plus two empty-payload premise tests.
    #
    # THREE DEFECTS FOUND IN THE ARABIC UI, all of them visible only once the screen was really
    # rendered in Arabic:
    #   the order's status printed the SERVER's English string. `order_status_text` is
    #       localised server-side, but the admin API never runs `SetLocate`
    #       (bootstrap/app.php:36 appends it to the `web` group only), so it arrives in the
    #       application default rather than the merchant's language. Both sites now translate
    #       the INTEGER through `StatusBadgeHelper.getOrderLabel`, which is locale-independent
    #       and sidesteps the server's own misspelling (`__('Comfirmed')`, Order.php:440) that
    #       `tests/Feature/ApiContract/OrderStatusTextTest` pins and which therefore must NOT be
    #       corrected on one surface alone.
    #   `"payment".tr` had NO KEY in either translation file, so the order-detail header
    #       printed the literal lowercase word `payment` in both languages.
    #   every DATE in the app rendered its month in English. `formatDateTime` built
    #       `DateFormat(format)` with no locale, so `MMM` always resolved en_US -- and a Latin
    #       date inside an RTL line reorders, which is how the coupon history read
    #       `Nov 2026 01`. Now `DateFormat(format, activeLocale)`, with
    #       `initializeDateFormatting()` added to `main()` and to the test harness because intl
    #       throws LocaleDataException for an unloaded locale. Arabic now reads `٠١ نوفمبر ٢٠٢٦`.
    #       This reaches EVERY date in the app, not only these two screens.
    # ----------------------------------------------------------------------------------------

    # ----------------------------------------------------------------------------------------
    # P2.5-MODAL. The AJAX create/edit modals, driven by shoot-modals.cjs.
    #
    # 16 surfaces captured x 4 combinations = 64 captures, 64 distinct. The eleven rows below are
    # the ones that were PENDING; `Category create (modal)` and `Brand create (modal)` are
    # ALREADY PREMIUM VERIFIED and are deliberately NOT claimed here, because a second dict entry
    # under the same name overwrites the first and would demote them.
    #
    # FOUR HARNESS DEFECTS and FOUR APPLICATION DEFECTS are described in
    # evidence/web-modals-2026-11-10/README.md. The two that generalise:
    #   - the shell's Quick Add dropdown owns three `a[data-ajax-popup]` on EVERY page, and
    #     clicking the first match timed out against an invisible element for 30s, recording
    #     "never loaded" for every create surface;
    #   - the flash-sale `[condition]` select a merchant sees is written by JAVASCRIPT, so an
    #     aria-label added to the blade's `Form::select` rendered and was then thrown away.
    #
    # FIVE SURFACES ARE NOT CLAIMED and stay PENDING with their measured reason:
    #   users-edit, flash-sale-edit  -- no record with an edit trigger in the fixture
    #   pixel-create, webhook-create -- the settings panel offers no create control of its own
    #   faq-edit                     -- the modal loads no form even with repeater-shaped data
    # ----------------------------------------------------------------------------------------
    'Category edit (modal)':
        ('REDESIGNED AND VERIFIED', 'web-modals-2026-11-10', 'category-edit__', 'P2.5-MODAL'),
    'Brand edit (modal)':
        ('REDESIGNED AND VERIFIED', 'web-modals-2026-11-10', 'brand-edit__', 'P2.5-MODAL'),
    'Tag create / edit (modals)':
        ('REDESIGNED AND VERIFIED', 'web-modals-2026-11-10', 'tag-create__', 'P2.5-MODAL'),
    'Product label create / edit (modals)':
        ('REDESIGNED AND VERIFIED', 'web-modals-2026-11-10', 'product-label-create__', 'P2.5-MODAL'),
    'Coupon create (modal)':
        ('REDESIGNED AND VERIFIED', 'web-modals-2026-11-10', 'coupon-create__', 'P2.5-MODAL'),
    'Coupon edit (modal)':
        ('REDESIGNED AND VERIFIED', 'web-modals-2026-11-10', 'coupon-edit__', 'P2.5-MODAL'),
    'FAQ create (modal)':
        ('REDESIGNED AND VERIFIED', 'web-modals-2026-11-10', 'faq-create__', 'P2.5-MODAL'),
    'Testimonial create / edit (modals)':
        ('REDESIGNED AND VERIFIED', 'web-modals-2026-11-10', 'testimonial-create__', 'P2.5-MODAL'),
    'Flash sale create (modal)':
        ('REDESIGNED AND VERIFIED', 'web-modals-2026-11-10', 'flash-sale-create__', 'P2.5-MODAL'),
    'Create staff user (modal)':
        ('REDESIGNED AND VERIFIED', 'web-modals-2026-11-10', 'users-create__', 'P2.5-MODAL'),
    'Product question reply (modal)':
        ('REDESIGNED AND VERIFIED', 'web-modals-2026-11-10', 'product-question-edit__', 'P2.5-MODAL'),

    # ----------------------------------------------------------------------------------------
    # P2.5-PARAM. The parameterised web routes, driven against real ids from the dev fixture.
    #
    # TWO of the eighteen are full PAGES and are claimed here. The rest split into two kinds,
    # neither of which a page capture can reach, and both recorded rather than claimed:
    #
    #   HTML FRAGMENTS, not pages: /user-info/{id}, /order-preview/{id},
    #       /orders/order_view/{id}, /order-receipt/{id}, /product-preview/{id}. Each answers
    #       200 with a body that has no `<html dir>` and no theme stylesheet, because it is
    #       injected into a panel or a modal by the parent page's JavaScript. render-matrix.cjs
    #       correctly fails its direction and theme premises on them -- they have neither. They
    #       need the parent page driven, the way shoot-modals.cjs drives a modal.
    #
    #   POST-ONLY ACTIONS: mark-read, archive customer, withdrawal approve/pay/reject, inline
    #       payment-status update. These are not screens at all.
    #
    # `/user-info/{id}` IS NOT A PERMISSION GAP, which is worth recording because it looks like
    # one. `UserController::userInfo` refuses unless the actor is the platform operator or is
    # the target themselves (`:626`), and its docblock records that an earlier version admitting
    # anyone whose `creatorId()` matched was a defect that was deliberately tightened. The
    # merchant refusing /user-info/1 (the super admin) and /user-info/3 (their own staff) is the
    # tenant check working; /user-info/2, their own record, answers 200.
    # ----------------------------------------------------------------------------------------
    'Product detail (read-only)':
        ('REDESIGNED AND VERIFIED', 'web-param-routes-2026-11-10', '_product_1__', 'P2.5-PARAM'),
    'Product editor — edit':
        ('REDESIGNED AND VERIFIED', 'web-param-routes-2026-11-10', '_product_1_edit__', 'P2.5-PARAM'),

    # ----------------------------------------------------------------------------------------
    # P2.5-FRAG. The panel-injected fragments, driven by shoot-fragments.cjs.
    #
    # 3 surfaces x 4 combinations = 12 captures, 12 distinct. Findings 46 -> 2.
    #
    # THESE ARE NOT PAGES. Each answers 200 with a body that has no `<html dir>` and no theme
    # stylesheet, because the parent page's JavaScript fetches it into a side panel
    # (`data-ss-panel-url`, store-syria-ds.js:273) or a modal (`data-ajax-popup`,
    # custom.js:138). Driven as routes they were unmeasurable -- not merely unphotographable,
    # UNMEASURABLE, because a fragment with no stylesheet has no colours to measure. Every
    # finding below was invisible until the fragment was driven in its real container.
    #
    # FOUR DEFECTS FOUND AND FIXED:
    #   the order-view modal's fourteen address labels at 2.3:1 IN DARK MODE. The cause is the
    #       recorded quirk that `.modal-content` stays WHITE under `data-ss-theme="dark"`: a
    #       previous fix (custom.css:5222) gave those labels the dark palette's light grey,
    #       correct on the PAGE and wrong in the modal, because the same blade renders in both.
    #   the `Payment Type` / delivery labels at 3.84:1, which are `<b class="shipping-text">`
    #       outside the address block and so were missed by the first selector.
    #   two WhatsApp links that rendered as EMPTY anchors pointing at `send?phone=&text=Hi`
    #       whenever the order had no phone -- no accessible name, no text, no destination.
    #       Guarded, with an em-dash placeholder that is itself legible.
    #   `Edit Order` translated as `ترتيب التحرير` ("the arrangement of editing"): the machine
    #       read `Order` as sequence and reversed the words. Now `تعديل الطلب`.
    #
    # THE PREVIEW PANELS' definition terms were raised from `--ss-fg-subtle` to `--ss-fg-muted`
    # (4.39:1 -> 5.88:1 on the sunken surface).
    #
    # ONE FINDING REMAINS, DELIBERATELY. `.btn-success` measures 2.5:1 in dark. Giving it a fill
    # and white text made it WORSE -- 1:1, green on green -- because
    # `store-syria-ds.css:1904` records that the host sets `--bs-btn-bg: var(--third-color)`,
    # which resolves TRANSPARENT: it is not a filled button, it is coloured text that happens to
    # be clickable, and the DS already moved its text colour and tracks the missing fill
    # separately. Restoring a real fill is a design-system change for every `.btn-success` in the
    # application, not a modal-scoped patch. The attempt and its measurement are recorded rather
    # than left as a silent partial fix.
    #
    # ALSO RECORDED: `custom.css` and `rtl-custom.css` are PARALLEL COPIES, not a base and a
    # supplement. After the modal fix landed in custom.css alone the labels were clean in
    # en-ltr-dark and still 2.3:1 in ar-rtl-dark. A contrast fix in one file is a fix in one
    # language.
    # ----------------------------------------------------------------------------------------
    'Order quick preview (side panel fragment)':
        ('REDESIGNED AND VERIFIED', 'web-fragments-2026-11-10', 'order-preview-panel__', 'P2.5-FRAG'),
    'Order AJAX modal (the eye / Show button)':
        ('REDESIGNED AND VERIFIED', 'web-fragments-2026-11-10', 'order-view-modal__', 'P2.5-FRAG'),
    'Product quick-preview panel body':
        ('REDESIGNED AND VERIFIED', 'web-fragments-2026-11-10', 'product-preview-panel__', 'P2.5-FRAG'),

    # P2.5-FLUTTER-PRODUCTDETAIL. The screen takes the product as a constructor argument and
    # fetches only its gallery, so the fixture is a ProductData map plus an images response.
    # Driving it found that `average_rating` -- a double(8,2) in the host schema -- was cast
    # `as int?`, which THROWS for any fractional rating and takes the product LIST down with it.
    'Product detail': [
        ('REDESIGNED AND VERIFIED', 'flutter-product-detail-2026-11-10',
         'flutter-product-detail_', 'P2.5-FLUTTER-PRODUCTDETAIL', 'ProductDetailScreen'),
    ],

    # P2.5-FLUTTER-EDITORS. The two screens a merchant types into. Driving them raised a
    # framework error that is SILENT at runtime: a SwitchListTile wrapped in a DecoratedBox
    # paints over its own ink splash, so the visibility toggle had no tap feedback.
    'Add rating / review':
        ('REDESIGNED AND VERIFIED', 'flutter-editors-2026-11-10',
         'flutter-add-rating_', 'P2.5-FLUTTER-EDITORS'),
    'Add / edit product':
        ('REDESIGNED AND VERIFIED', 'flutter-editors-2026-11-10',
         'flutter-product-editor_', 'P2.5-FLUTTER-EDITORS'),

    # TWO SURFACES SHARE THIS NAME -- the Flutter counter-sale screen (inventory :663,
    # `GetX /addOrder`) and the Laravel web screen (inventory :187, `GET order/create`). Each
    # entry carries its own route needle so one capture set can never mark the other verified;
    # a bare second dict entry under this name would silently replace the first.
    'Create order': [
        ('REDESIGNED AND VERIFIED', 'flutter-create-order-2026-11-10',
         'flutter-create-order_', 'P2.5-FLUTTER-CREATEORDER', 'CreateOrderScreen'),
        ('REDESIGNED AND VERIFIED', 'web-sweep-2026-11-10',
         '_order_create__', 'P2.5-WEB-SWEEP', 'order/create'),
    ],

    'Store payment methods':
        ('REDESIGNED AND VERIFIED', 'flutter-payment-methods-2026-11-10',
         'flutter-payment-methods_', 'P2.5-FLUTTER-DETAIL2'),
    'My plan':
        ('REDESIGNED AND VERIFIED', 'flutter-my-plan-2026-11-10', 'flutter-my-plan_',
         'P2.5-FLUTTER-DETAIL2'),

    # 'Attribute row actions' is DELIBERATELY NOT HERE. /product-attribute renders zero rows in
    # this fixture, so there is no cell to capture. Seeding one needs an attribute AND its
    # parent product's variant wiring, which is a fixture job of its own rather than a line in
    # seed-dev-catalogue-rows.php. It stays PENDING with that reason rather than being claimed
    # on a sibling's capture.

    # ---- P2.5-WEB-MODALS-2 (2026-11-10): the modals the route sweep left PENDING -------------
    #
    # `/product-attributes/create` answered 200 with no <html> in the sweep -- the 1/4
    # fingerprint of a modal fragment -- so it is driven from its list page. BOTH halves of the
    # row are captured this time: `attribute-create__` and `attribute-edit__`, four combinations
    # each, which is what the earlier wave was missing when it declined to claim this row.
    #
    # The edit capture is also what found the required-marker defect: inside `.ss-field`
    # (display:flex; flex-direction:column) a marker span written BESIDE its label is a second
    # flex item and gets a row of its own. Fixed by wrapping both in `.ss-label-line`; 15 sites.
    'Attribute create / edit (modals)':
        ('REDESIGNED AND VERIFIED', 'web-modals2-2026-11-10',
         'attribute-edit__', 'P2.5-WEB-MODALS-2'),

    # THE LANGUAGE SCREEN, with two corrections to its row.
    #
    # The inventory names `GET /languages`, which is NOT A REGISTERED ROUTE -- it answers 404.
    # The merchant's language screen is `GET manage-language/{lang?}/{module?}`
    # (routes/web.php:1382), and it is claimed here on four clean captures of
    # `/manage-language/en` (titles "Language" / "اللغة" in the two languages).
    #
    # TWO THINGS MEASURED WHILE GETTING THERE, neither of them a design issue:
    #
    #   * `GET /manage-language` with NO language segment answers HTTP 500 --
    #     `file_get_contents(resources/lang/General.json): No such file or directory`. Nothing
    #     links to it that way (every caller passes a language; header.blade.php:410 passes
    #     `auth()->user()->language`, and that column is NOT NULL defaulting to 'en'), so it is
    #     reachable only by typing the URL. Recorded, not fixed.
    #
    #   * The Create Language control is SUPER ADMIN ONLY. `language/index.blade.php:6` opens
    #     `@if (auth()->user()->type == 'super admin')` around the whole `action-button` section,
    #     so a merchant never sees it -- even though merchants DO hold `Manage Language`
    #     (Utility::addNewData() puts it in the `admin` role's list at :2908). The create modal at
    #     `/create-language` is therefore a Phase 06 surface, not a Phase 2.5 one, and the harness
    #     correctly reported "no visible create trigger" rather than a broken selector.
    'Languages':
        ('REDESIGNED AND VERIFIED', 'web-modals2-2026-11-10',
         '_manage_language_en__', 'P2.5-WEB-MODALS-2'),

    # ---- P2.5-P2 (2026-11-10): the loader, which was never on screen until this wave --------
    #
    # Was BLOCKED, and the block was honest at the time: there was no surface to photograph,
    # because `Loader.showLoader()` constructed `const LoadingWidget()` as an expression and
    # discarded it. Four live call sites showed nothing. Its sibling `hideLoader()` was an
    # unguarded `Get.back(closeOverlays: true)` that popped whatever was on top -- at
    # category_controller.dart:280 that ran BEFORE the status branches, with the category sheet
    # open, so a 403 or a 429 tore the sheet down and took the merchant's half-filled form with
    # it.
    #
    # The owner authorised the repair (Priority 2: "Fix confirmed UI/navigation defects with
    # tests proving that unrelated routes and dialogs cannot be popped"). `showLoader()` now
    # opens a real dialog -- which is what three of the four call sites were already written for,
    # each following it with an `if (Get.isDialogOpen) Get.back()` guard that had been correctly
    # doing nothing -- and `hideLoader()` closes ONLY what `showLoader()` opened. The sheet now
    # closes itself, on success only.
    #
    # Pinned by test/a11y/loader_does_not_pop_unrelated_routes_test.dart, which opens somebody
    # else's dialog and requires it to survive `hideLoader()`.
    'Loading indicator (Loader / LoadingWidget)':
        ('REDESIGNED AND VERIFIED', 'flutter-components2-2026-11-10',
         'flutter-component-loader_', 'P2.5-P2'),

    # ---- P2.5-P1-FLUTTER (2026-11-10): component galleries ----------------------------------
    #
    # Added to the existing component gallery, which renders each component ALONE in all four
    # combinations rather than inside a host screen. Tablet variants captured alongside.
    #
    # THE STAT TILE IS CAPTURED TWICE because `permitted: false` is not a styling variant: it
    # swaps the figure for an em dash, and the widget's own comment says why ("an empty tile
    # reads as a rendering failure"). A tile showing a number and a tile showing a dash are two
    # pictures and only one had ever been looked at.
    #
    # A FALSE POSITIVE WAS CAUGHT HERE AND IS WORTH RECORDING. The first capture showed
    # `this_month` and `store_qr` rendering as literal keys -- the signature of a missed GetX
    # lookup, and exactly the defect class the `final_price` repair closed earlier in this phase.
    # It was MY FIXTURE, not the product: the real dashboard passes
    # `stat_period_in_this_store` / `stat_period_all_time` (home_screen.dart:384, :416, :427) and
    # takes the QR title from `dashboardData.qrcode.name`, server data rather than a key. Both
    # real keys ARE defined -- in single quotes, which a double-quoted grep missed, the third
    # quoting/case false negative of this phase. The specimens now pass what the screen passes.
    'Home widgets (stat tiles, QR, profile row)':
        ('REDESIGNED AND VERIFIED', 'flutter-components2-2026-11-10',
         'flutter-component-home-widgets_', 'P2.5-P1-FLUTTER'),

    # THE PRODUCT CARD in the four states that take different code paths -- in stock, hidden
    # (status 0, which draws an overlay chip), out of stock, and on sale. One state proves only
    # that state. Given the exact box its real grid provides (220x290,
    # products_screen.dart:782-786); dropped into the gallery unbounded, its inner `Expanded`
    # threw, which was a harness mismatch rather than a defect in the card.
    #
    # The row also covers the FIELD partial, captured as `flutter-component-add-product-field_`
    # beside it: `AddProductTextField` is a separate class from the `AppTextField` the gallery
    # already held, and it is the one whose caret and focus ring were left at 1.09:1 on their own
    # dark fill until P2.5-FLUTTER-FOCUS. The two now sit in the same gallery, which is what
    # would have caught the divergence.
    'Product card / field partials':
        ('REDESIGNED AND VERIFIED', 'flutter-components2-2026-11-10',
         'flutter-component-product-card_', 'P2.5-P1-FLUTTER'),

    # ---- P2.5-P1-WEB (2026-11-10): the component rows the classification sweep called ------
    # ---- capturable, each clipped to its own box so the capture is evidence for the ---------
    # ---- component rather than for the page hosting it. -------------------------------------
    #
    # THE SWITCH CELL CARRIED A REAL DEFECT, in one state per theme. Measured off the running
    # page, because a 1px border is a few hundredths of a control's pixels and a sampler that
    # reports the dominant colour never sees it:
    #
    #   light, unchecked   white fill on a white cell = 1.00:1, leaving only the border,
    #                      rgba(0,0,0,.25) flattened to #BFBFBF = 1.84:1   -> below 3:1
    #   dark,  checked     #335DA8 on cell #292A33 = 2.22:1                -> below 3:1
    #
    # Repaired by giving the control a border that carries the boundary while the fill keeps
    # carrying the state -- 5.05:1 and 7.61:1 after, with the two states still 6.42:1 apart. The
    # obvious fix (re-colour the checked fill to the dark brand) was measured and REJECTED: it
    # would have dropped the state difference to 2.09:1, worse than before.
    # Ratchet: tools/measure-switch-boundary-live.cjs, 4 problems before and 0 after.
    'Customer status toggle':
        ('REDESIGNED AND VERIFIED', 'web-components-2026-11-10',
         'customer-status-toggle__', 'P2.5-P1-WEB'),

    # The DataTable component on its richest host (11 columns): toolbar, column chooser, density
    # control, search, sortable headers, status badges, the pinned trailing action column and the
    # info/pagination line. All of that is the component; the page around it is not.
    'x-datatable':
        ('REDESIGNED AND VERIFIED', 'web-components-2026-11-10', 'x-datatable__', 'P2.5-P1-WEB'),

    # Both filter bars, each captured with filters APPLIED as well as empty -- the applied state
    # renders a filter-summary receipt, carries the selected values and shows the Clear control,
    # so one state proves only that state. `product-filter-bar-applied__` and
    # `order-saved-views__` sit beside these in the same directory.
    'Product list filter bar':
        ('REDESIGNED AND VERIFIED', 'web-components-2026-11-10',
         'product-filter-bar__', 'P2.5-P1-WEB'),
    'Order filter bar + saved views':
        ('REDESIGNED AND VERIFIED', 'web-components-2026-11-10',
         'order-filter-bar__', 'P2.5-P1-WEB'),

    # The variant delete confirmation, captured OPEN and never confirmed -- confirming would
    # really delete the variant. The panel that owns the trigger fetches itself on
    # document-ready, which is why the harness now waits for an injected trigger instead of
    # reporting "0 matched" against a page that had simply not finished.
    'Variant delete confirmation dialog':
        ('REDESIGNED AND VERIFIED', 'web-components-2026-11-10',
         'variant-delete-dialog__', 'P2.5-P1-WEB'),

    # The attribute list's action cell. The harness clips the whole row, so the attribute name
    # identifies the image and it cannot collide with the sibling two-icon cells.
    'Attribute row actions':
        ('REDESIGNED AND VERIFIED', 'web-components-2026-11-10',
         'attribute-row-actions__', 'P2.5-P1-WEB'),

    # ---- P2.5-FLUTTER-SHEETS (2026-11-10): the four bottom-sheet editors --------------------
    # Category, customer, tax and shipping are edited in a `showModalBottomSheet`, so none of
    # them is in a route table and no capture wave that navigates could reach them. Driven as the
    # widgets the sheet actually builds, in all four combinations, CREATE and EDIT -- `isUpdate`
    # decides the title, the submit label and whether the fields arrive populated, so a capture
    # of the empty create state is not evidence for the edit state. Each row is claimed on its
    # `-edit_` prefix; the matching `-create_` set sits beside it in the same directory.
    #
    # The customer sheet is a TWO-STEP WIZARD and both steps are captured. Its create path has a
    # third surface the edit path does not: `customer_bottom_sheet.dart:423` opens
    # `if(!widget.isUpdate) ...[`, so the password field and the account-status switch exist only
    # when creating. Driving the edit path alone -- the obvious way to get a switch into the ON
    # state -- renders no switch at all, and a finder that matches nothing reads exactly like a
    # passing check. That is how the dark-mode defect below nearly went unmeasured twice.
    'Add / edit category (modal)':
        ('REDESIGNED AND VERIFIED', 'flutter-sheets-2026-11-10',
         'flutter-sheet-category-edit_', 'P2.5-FLUTTER-SHEETS'),
    'Add / edit customer (modal)':
        ('REDESIGNED AND VERIFIED', 'flutter-sheets-2026-11-10',
         'flutter-sheet-customer-edit_', 'P2.5-FLUTTER-SHEETS'),
    'Add / edit tax (modal)':
        ('REDESIGNED AND VERIFIED', 'flutter-sheets-2026-11-10',
         'flutter-sheet-tax-edit_', 'P2.5-FLUTTER-SHEETS'),
    'Add / edit shipping (modal)':
        ('REDESIGNED AND VERIFIED', 'flutter-sheets-2026-11-10',
         'flutter-sheet-shipping-edit_', 'P2.5-FLUTTER-SHEETS'),

    # ---- P2.5-WEB-FRAGMENTS (2026-11-10): the fragments, captured where they actually live ----
    # `shoot-fragments.cjs` drives the PARENT page, waits for the fragment to arrive, and
    # screenshots the container's own bounding box -- so each capture is evidence for the
    # fragment, not for the page hosting it. That distinction is what lets these be claimed
    # separately from the report screens below, which were captured whole.
    #
    # The brand body is claimed on captures taken AFTER `seed-dev-brand-assignments.php` gave the
    # ordered products a brand. Before that every card read $0.00 against an empty grid --
    # correct for a fixture where 0 of 32 products carried a brand_id, and worthless as evidence:
    # no bar, no legend, no thousands separator, and no way to see whether the chart reads
    # right-to-left. The claimed captures show 336.00 + 126.00 = 462.00, which also re-confirms
    # the D-42.3 aggregate-is-the-sum property.
    'Brand sales body (AJAX fragment)':
        ('REDESIGNED AND VERIFIED', 'web-fragments-2026-11-10', 'brand-sales-body__', 'P2.5-WEB-FRAGMENTS'),

    # The three stock row payloads are three DataTables inside one 3-tab shell. Each tab fetches
    # its own route, so each is its own fragment and each has its own four captures.
    'Low in Stock — row payload':
        ('REDESIGNED AND VERIFIED', 'web-fragments-2026-11-10', 'stock-low-rows__', 'P2.5-WEB-FRAGMENTS'),
    'Out Of Stock — row payload':
        ('REDESIGNED AND VERIFIED', 'web-fragments-2026-11-10', 'stock-out-rows__', 'P2.5-WEB-FRAGMENTS'),
    'Most Stocked — row payload':
        ('REDESIGNED AND VERIFIED', 'web-fragments-2026-11-10', 'stock-most-rows__', 'P2.5-WEB-FRAGMENTS'),

    # DELIBERATELY NOT CLAIMED: 'Category / Product sales bodies (AJAX fragments)'.
    #
    # That one inventory row names TWO fragments -- `GET category-order-reports` and
    # `GET product-order-reports`. The category half IS captured
    # (evidence/web-fragments-2026-11-10/after/category-sales-body__*.png, four combinations, and
    # the "All Categories" label is Arabic in them). The product half answers HTTP 500 on this
    # fixture because `order_product_reports` builds raw SQL with MONTH(), so it is blocked on a
    # MySQL dev fixture along with the other report routes.
    #
    # Claiming the row off the half that works would say "verified" about a surface nobody has
    # seen. It stays PENDING until both halves can be driven, which is one MySQL fixture away.

    # ---- P2.5-WEB-SWEEP (2026-11-10): the reports family, driven and photographed ------------
    # `render-matrix.cjs` drove 32 PENDING routes x 4 combinations against the dev fixture signed
    # in as a real merchant with a real store. These NINE answered 200 in all four, carried the
    # right `dir`, loaded the right theme sheet and printed their OWN page title in both
    # languages -- the last of those is what separates them from a `redirect()->back()` refusal,
    # which keeps the referrer's title and so passes a status check while photographing the
    # wrong screen. Raw table: evidence/web-sweep-2026-11-10/README.md.
    'Sales Brand Report':
        ('REDESIGNED AND VERIFIED', 'web-sweep-2026-11-10', '_Brand_order_sale_reports__', 'P2.5-WEB-SWEEP'),
    'Order Status Report':
        ('REDESIGNED AND VERIFIED', 'web-sweep-2026-11-10', '_Status_reports__', 'P2.5-WEB-SWEEP'),
    'Sales by Category':
        ('REDESIGNED AND VERIFIED', 'web-sweep-2026-11-10', '_category_order_sale_reports__', 'P2.5-WEB-SWEEP'),
    'Top Selling By Country Report':
        ('REDESIGNED AND VERIFIED', 'web-sweep-2026-11-10', '_order_country_reports__', 'P2.5-WEB-SWEEP'),
    'Sales Report (line)':
        ('REDESIGNED AND VERIFIED', 'web-sweep-2026-11-10', '_order_reports__', 'P2.5-WEB-SWEEP'),
    'Sales Report (radial/bar variant)':
        ('REDESIGNED AND VERIFIED', 'web-sweep-2026-11-10', '_order_reports_data__', 'P2.5-WEB-SWEEP'),
    'Sales by Product':
        ('REDESIGNED AND VERIFIED', 'web-sweep-2026-11-10', '_product_order_sale_reports__', 'P2.5-WEB-SWEEP'),
    'Customer Reports (reports hub)':
        ('REDESIGNED AND VERIFIED', 'web-sweep-2026-11-10', '_reports__', 'P2.5-WEB-SWEEP'),
    # `stock-reports` and `stock-report` are the SAME controller action registered on two URIs
    # (routes/web.php:1108-1109). Both were captured; this claims the screen ONCE, and the
    # inventory's second row for it is declared a duplicate in DUPLICATE_OF below rather than
    # claimed again from the other URI's photographs of the same page.
    'Stock Reports':
        ('REDESIGNED AND VERIFIED', 'web-sweep-2026-11-10', '_stock_reports__', 'P2.5-WEB-SWEEP'),

}

# Surfaces that are out of the Phase 2.5 merchant scope by the approved phase plan.
OUT_OF_SCOPE = {
    # ---- NOT A SCREEN: no picture of its own, in any combination ------------------------------
    #
    # Classified 2026-11-10 by a read-only sweep whose every "closes a row" verdict was then put
    # to an adversarial reviewer told to refute it and to default to refuting when uncertain.
    # All nine were upheld. These are NOT "nobody got round to them" -- each is a value object, a
    # stylesheet or a configuration table that paints nothing until some other widget or page
    # resolves it, so its effect is already inside every capture that uses it and it has no frame
    # of its own to photograph. Leaving them PENDING implies design work is owed that is not.
    'App root / shell':
        'not a screen: StoreGoApp (lib/store_go.dart) is GetBuilder -> ScreenUtilInit -> '
        'GetMaterialApp and sets only configuration -- initialRoute, bindings, getPages, theme, '
        'locale, delegates. No Scaffold, no AppBar, no background, no painting widget: it has no '
        'pixels of its own, only the current route\'s. Mounting it would photograph '
        'initialRoute /splash, which is the separate "Splash / Welcome" row already captured.',
    'Theme definition (the only ThemeData in the app)':
        'not a screen: theme_controller.dart builds one ThemeData (confirmed the only one -- 17 '
        'ThemeData hits in lib/, all in that file bar the pdf package\'s unrelated class in '
        'invoice.dart:149 and a doc comment). A ThemeData is a value object with no build '
        'method; each component theme it declares appears only inside another row\'s capture. '
        'golden_harness.dart:146 builds exactly this object in order to render other surfaces.',
    'Design tokens (colour)':
        'not a screen: utils/colors.dart is 95 lines of bare `static Color` fields (62 across '
        'AppColor, DarkThemeColor, LightThemeColor) plus the anchors in store_syria_brand.dart. '
        'No widget, no build method. Live rather than inert -- 612 `AppColor.` references across '
        '72 files -- so every token\'s effect is already inside the captures of the widgets that '
        'read it. No palette or style-guide screen exists in lib/ to photograph.',
    'Design tokens (typography)':
        'not a screen: utils/text_style.dart is `appFontFamily = Tajawal` and 32 top-level '
        'getters returning TextStyle only. A TextStyle paints nothing until a Text consumes it. '
        'Live -- 36 files import it -- so every step of the scale is already visible inside other '
        'rows\' captures, and no type specimen is rendered anywhere in the app.',
    'Routing table':
        'not a screen: routes/routes.dart is 29 path constants and a List<GetPage> of 17 entries, '
        'consumed as data at store_go.dart:37-39. It declares nothing visual at all -- no '
        'transition, transitionDuration, curve or custom page builder appears in the file, and '
        'GetMaterialApp sets no defaultTransition -- so it contributes neither chrome nor '
        'animation. Every screen it names is its own inventory row.',
    'Bindings / DI':
        'not a screen: lib/bindings/ is 11 Bindings subclasses whose dependencies() bodies are '
        'only Get.put / Get.lazyPut calls. A grep for Widget or transition across the directory '
        'returns zero hits -- no file there builds or configures a widget. Live (AppBinding is '
        'the initialBinding) but with nothing to render.',
    'Store Syria Design System stylesheet':
        'not a screen: a 7,305-line CSS file, linked from layouts/app.blade.php:274 and '
        'guest.blade.php:245 and never rendered. '
        'Tests\\Unit\\Ui\\DesignSystemStylesheetScopeTest already asserts that every selector in '
        'it is confined by an `.ss-` class or a `data-ss-` attribute -- i.e. the file only ever '
        'paints OTHER surfaces and owns no element of its own. No route serves it as a page.',
    'DS theme hook + opt-in partial':
        'not a screen, and it is two artefacts neither of which emits a painting element. The '
        'HOOK is an HTML attribute: layouts/app.blade.php:86 writes data-ss-theme onto <html> '
        'server-side and an inline script re-derives it with a MutationObserver; an attribute on '
        '<html> has no bounding box. The OPT-IN PARTIAL is '
        'layouts/includes/store-syria-ds.blade.php -- 52 lines of which 27 are a Blade comment '
        'and the rest one <link>, one <script defer> and an inline theme-sync script.',
    'custom.css F126 region':
        'not a screen: a ~100-line region of public/css/custom.css (and its mirror in '
        'rtl-custom.css) that re-colours text inside `html[data-ss-theme="dark"] .modal-content`. '
        'Pure CSS -- there is no markup and no element the region owns. Its effect is visible in '
        'every dark modal capture already taken, which is where it was measured and accepted.',

    # ---- THE FILE NO LONGER EXISTS ------------------------------------------------------------
    # `resources/views/partision/settingPopup.blade.php` is gone. The empty result is NOT a
    # spelling accident, which matters because this repository has twice been misled by one: a
    # case-insensitive repo-wide grep for `settingpopup` returns four live PROSE references to it
    # -- layouts/app.blade.php:767 carries the deletion record ("P2.5-PKG-A-02 --
    # `partision/settingPopup.blade.php` WAS DELETED, NOT MOVED"), store-syria-ds.css:2145, and
    # two tests, one of which holds it as `private const DEAD_MODAL`. So the symbol provably
    # existed and provably does not now.
    'Settings popup (vendored, inert)':
        'the file was deleted: resources/views/partision/settingPopup.blade.php does not exist. '
        'layouts/app.blade.php:767 carries the deletion record and '
        'tests/Unit/Ui/SidebarActiveStateAndIconographyTest holds the path as DEAD_MODAL. There '
        'is nothing left to render or photograph.',

    # NOT A SCREEN. `MerchantNotificationController::feed()` is declared `: JsonResponse` and every
    # return in it is `response()->json([...])` -- there is no view, in any branch, including the
    # 403. Its OUTPUT is already rendered and captured as the bell dropdown, so there is nothing
    # here that could be photographed separately that would not be reusing that evidence.
    #
    # Same category as the two rows the inventory-taxonomy proposal calls kind A: a route that
    # answers with JSON or an HTML fragment and can never render a page for a merchant to look at.
    # Counting it in a denominator of SCREENS is a category error, and leaving it PENDING implies
    # design work is owed that is not.
    'Notification bell feed (JSON)':
        'not a screen: MerchantNotificationController::feed() returns JsonResponse in every '
        'branch. The data it serves is rendered and captured as the notification bell dropdown, '
        'so a separate capture would reuse that evidence for a second surface.',

    # NOT A SCREEN ANY MORE, as of this branch. GET /profile/edit answered HTTP 200 with the
    # sidebar, header, breadcrumb and footer drawn correctly around an ENTIRELY EMPTY content
    # area, in all four combinations -- captures under evidence/web-sweep-2026-11-10/after/
    # _profile_edit__*.png, and the inventory already carried the diagnosis as the blocker
    # finding `profile-edit-renders-empty`.
    #
    # The cause: resources/views/profile/edit.blade.php is Breeze markup, so its body is a
    # `$slot`; App\View\Components\AppLayout::render() returns view('layouts.app'); and that
    # file is the WorkDo layout, which contains no `$slot` and emits content with
    # `@yield('content')` (:708). A layout that yields never prints the slot it was handed.
    #
    # ProfileController@edit now redirects to the product's own profile screen (GET /profile,
    # UserController@profile), so this URI renders no view of its own and cannot earn a screen
    # state. The Breeze form was deliberately NOT revived: it would have added a second profile
    # editor beside the shipped one and surfaced a Delete Account control for a bare
    # `$user->delete()` with no store, order or subscription cleanup -- a product and
    # data-integrity decision for the owner, recorded in PHASE-2.5-WEB-SWEEP-FINDINGS.md.
    # Pinned by tests/Feature/ProfileEditRendersSomethingTest.
    'Breeze profile (orphan)':
        'not a screen: GET /profile/edit used to render an empty page (the Breeze `$slot` was '
        'dropped by the WorkDo `@yield(\'content\')` layout) and now redirects to GET /profile, '
        'the profile screen the product actually ships. Fixed and pinned on this branch.',

    'Super Admin dashboard': 'Phase 06 (SaaS & Super Admin), not Phase 2.5 Merchant Experience',

    # THESE TWO ARE NOT MERCHANT SURFACES, which is a different thing from "not done yet".
    #
    # `setting/index.blade.php:75` opens `@if (auth()->user() && auth()->user()->type == 'super
    # admin')` and closes at :116. Seven tabs sit inside it -- Cookie, Cache, Storage, reCAPTCHA,
    # ChatGPT, Style and SEO -- so a merchant (`users.type == 'admin'`, which is the root of their
    # own tenant) never sees any of them. The settings hub renders 21 tabs for a merchant and all
    # 21 were captured; these are not among them because the application does not offer them.
    #
    # THE FIXTURE WAS NOT ELEVATED TO REACH THEM. Making the dev merchant a `super admin` would
    # have produced four screenshots per tab and they would have been evidence of nothing: a
    # platform-operator surface photographed while pretending to be a merchant. Operator UX is
    # Phase 06's scope by the approved six-phase plan, and that is where these belong.
    'Storage Settings tab':
        "super-admin only (setting/index.blade.php:75 gates it on users.type == 'super admin'); "
        'a merchant never sees this tab, so it is Phase 06 operator scope, not Phase 2.5',
    'Cache Settings tab':
        "super-admin only (setting/index.blade.php:75 gates it on users.type == 'super admin'); "
        'a merchant never sees this tab, so it is Phase 06 operator scope, not Phase 2.5',
}

# DECLARED BLOCKERS: a surface that CANNOT become a finished merchant screen, with the reason and
# the remediation, as the directive's four states require of BLOCKED.
#
# THIS DICT EXISTS BECAUSE THERE WAS NO WAY TO SAY THIS. Until now BLOCKED could only ARISE -- from
# a VERIFIED claim whose captures are missing, or from an audit that returned 'unknown'. A route
# that is registered but renders nothing, or whose handler body is literally `//`, fell through to
# PENDING, which reads as "not done yet" and invites the next person to go and do it. The owner's
# instruction is the opposite: "Registered but unimplemented routes must not be presented as
# working screens. Do not invent backend commerce functionality merely to increase the verified
# count. Record blockers accurately."
#
# A row here is a claim that WORK IS NOT POSSIBLE, so each carries the evidence for that, and each
# names what would unblock it. None of these is a defect to fix inside Phase 2.5.
BLOCKED_SURFACES = {
    # ---- BLOCKED ON A PLATFORM CHANNEL, measured rather than assumed --------------------------
    # `InvoicePdfViewScreen` builds a `PdfPreview` (invoice_pdf_view.dart:98) and the preview
    # rasterises through the `printing` plugin. Driven in all four combinations the screen throws
    # nothing and lays out its chrome, but the body is the `loadingWidget` shimmer and the whole
    # screen holds TWO Text widgets. Capturing it raises
    #
    #   MissingPluginException(No implementation found for method rasterPdf
    #                          on channel net.nfet.printing)
    #
    # so a capture is a picture of a placeholder. Four documenting captures are kept at
    # evidence/flutter-invoice-2026-11-10/after/flutter-invoice-chrome_*.png -- they are evidence
    # of the LIMITATION, not of the screen, and are deliberately not claimed as verification.
    #
    # THE TRIP WAS STILL WORTH IT. The chrome showed the page-format control reading "Letter" --
    # the US paper size -- to an Arabic merchant, while the screen's own loading placeholder is
    # built at `aspectRatio: 1 / 1.414` under the comment `// A4` twelve lines away. `PdfPreview`
    # had no `initialPageFormat`, so it took the package default. Fixed to A4 and pinned by
    # test/screens/invoice_viewer_capture_test.dart; an invoice printed at Letter is cropped or
    # wrongly margined on every A4 sheet a Syrian merchant feeds it.
    #
    # REMEDIATION: a device or emulator run. The layout, the format and the chrome are pinned by
    # tests meanwhile, so what is missing is the picture, not the behaviour.
    'Invoice (PDF build) / Invoice viewer':
        'the PDF body cannot be rendered under flutter_test: PdfPreview rasterises through the '
        'printing plugin and the capture raises MissingPluginException for `rasterPdf` on '
        'channel net.nfet.printing, leaving the loadingWidget shimmer. Measured in all four '
        'combinations; documenting captures at evidence/flutter-invoice-2026-11-10/. The page '
        'format was wrong while we were there -- it opened at US Letter and is now pinned to A4 '
        'by test/screens/invoice_viewer_capture_test.dart. REMEDIATION: capture on a device or '
        'emulator; behaviour is pinned by tests meanwhile.',

    # ---- DISABLED WHOLESALE, AND IT IS A WEB/FLUTTER PARITY GAP ------------------------------
    #
    # CORRECTED 2026-11-10, having first been recorded here as "built but unreachable". That was
    # understated, and the way it was wrong is the family this phase keeps meeting: a grep found
    # `class CODScreen` and I read it as a declaration. It is INSIDE A COMMENT.
    #
    # Measured by counting lines that are not comments and not blank:
    #
    #   cod_screen.dart               159 lines,   0 live
    #   stripe_screen.dart            169 lines,   0 live
    #   bank_transfer_screen.dart     152 lines,   0 live
    #   cod_controller.dart           129 lines,   0 live
    #   stripe_controller.dart        112 lines,   0 live
    #   bank_transfer_controller.dart  94 lines,   0 live
    #
    # 815 lines, none of it live. The first line of cod_screen.dart reads
    # `// // ignore_for_file: ...` -- a comment prefix applied over an existing comment, the
    # signature of a mechanical whole-file disabling rather than a considered removal. No reason
    # is recorded, and the only commit touching the file carries an unrelated message.
    #
    # SO THE ANSWER TO "intended, inactive, or deliberately restricted" IS: INACTIVE. Nothing
    # restricts these by permission; the code was written and then switched off.
    #
    # AND THE GAP IS REAL, because the web does offer this. `payment_setting` is a tab of the
    # merchant settings hub (resources/views/setting/index.blade.php:55) with
    # setting/payment_partials/payment_gateway.blade.php behind it, and it was captured in the
    # settings-tabs wave. The Flutter app's own live `PaymentSettingScreen` (245 lines, reachable
    # from store_setting_screen.dart:102) LISTS the configured methods read-only -- no switch, no
    # editor, no navigation out of it. So a merchant can configure COD, Stripe and bank transfer
    # on the web and only look at them in the app.
    #
    # NOT ACTIONED, and deliberately. Reviving 815 lines would expose financial configuration in
    # the app, which the owner's Priority 2 reserves: "Do not expose new financial functionality
    # without confirming existing permissions and approved product behavior." There is also no
    # design work owed until that decision is made -- there is nothing on screen to review.
    #
    # REMEDIATION: an owner decision on whether the app should edit payment gateways at all. If
    # yes, that is a feature revival with its own permission and contract review, not a Phase 2.5
    # design pass.
    'COD / Stripe / Bank-transfer gateway editors':
        'inactive, not merely unreachable: all six files are 100%% commented out -- 815 lines, 0 '
        'live (cod/stripe/bank_transfer screen and controller). The classes an earlier note '
        'cited were matched INSIDE comments. No reason is recorded and the only commit touching '
        'them carries an unrelated message. This is a WEB/FLUTTER PARITY GAP: the web offers '
        'gateway configuration as a merchant settings tab (setting/index.blade.php:55), while '
        "the app's live PaymentSettingScreen lists methods read-only. REMEDIATION: an owner "
        'decision on whether the app should edit payment gateways; reviving them would expose '
        'financial configuration, which Priority 2 reserves. No design work is owed meanwhile -- '
        'there is nothing on screen to review.',

    # ---- BLOCKED ON THE DEV FIXTURE'S DATABASE ENGINE, not on design ------------------------
    # These four answered HTTP 500 in ALL FOUR combinations in the 2026-11-10 sweep, and the
    # cause is the same every time: the controller builds raw SQL out of MySQL-only functions,
    # and the Phase 2.5 dev fixture is SQLite (database/p2dev.sqlite).
    #
    #   no such function: MONTH            ReportController::reports_chart (:202-216),
    #                                      order_reports_chart (:720),
    #                                      order_reports_chart_data (:985-1205)
    #   no such function: SUBSTRING_INDEX  ReportController::top_product (:2814, :2826) --
    #                                      the file's OWN comment at :2883 already says SQLite
    #                                      cannot run that join.
    #
    # THIS IS NOT A PRODUCT DEFECT AND MUST NOT BE REPORTED AS ONE: production is MySQL, where
    # these functions exist. It is also not a design verdict -- nobody has seen these screens.
    # REMEDIATION: a MySQL dev fixture. tests/Support/Security/bin/verify-sec13-migration-on-
    # mysql.php is the established pattern for standing one up (it creates and drops its own
    # throwaway database); the sweep harness would then drive these four unchanged.
    'Customer report body (AJAX fragment)':
        'HTTP 500 on the SQLite dev fixture in all four combinations: ReportController::'
        'reports_chart builds raw SQL with MONTH(), which SQLite does not implement. Production '
        'is MySQL and the screen is expected to work there. REMEDIATION: a MySQL dev fixture; '
        'no design work is owed until it can be rendered.',
    'Sales report body (AJAX fragment)':
        'HTTP 500 on the SQLite dev fixture in all four combinations: ReportController::'
        'order_reports_chart (:720) uses MONTH(). Production is MySQL. REMEDIATION: a MySQL dev '
        'fixture.',
    'Sales report bar body (AJAX fragment)':
        'HTTP 500 on the SQLite dev fixture in all four combinations: ReportController::'
        'order_reports_chart_data (:985-1205) uses MONTH() and YEAR(). Production is MySQL. '
        'REMEDIATION: a MySQL dev fixture.',
    'Top Sales Report':
        'HTTP 500 on the SQLite dev fixture in all four combinations: ReportController::'
        'top_product joins on SUBSTRING_INDEX (:2814, :2826), which SQLite does not implement -- '
        "the controller's own comment at :2883 records this. Production is MySQL. REMEDIATION: "
        'a MySQL dev fixture.',

    # ---- BLOCKED ON A THIRD-PARTY ACCOUNT ----------------------------------------------------
    # Both controllers gate on a live integration before they render anything:
    # ShopifyProductController::index requires `Manage Shopify Product`, the setting
    # `shopify_setting_enabled == 'on'`, a store URL and an access token, and then calls the
    # Shopify Admin API. The sweep got HTTP 500 for Shopify and an empty 200 for WooCommerce.
    # No merchant-visible layout exists to photograph without a connected store.
    'Shopify import screens (product, category, sub_category, coupon, customer)':
        'cannot be rendered without a connected Shopify store: ShopifyProductController::index '
        "requires the `shopify_setting_enabled` setting plus a store URL and access token, then "
        'calls the Shopify Admin API before returning a view. Measured HTTP 500 in all four '
        'combinations on the dev fixture. REMEDIATION: an owner-provided Shopify sandbox '
        'credential, or an explicit decision to leave these screens unverified in Phase 2.5.',
    'WooCommerce import screens (product, category, coupon, customer)':
        'cannot be rendered without a connected WooCommerce store: measured HTTP 200 with an '
        'empty body and no <html> element in all four combinations. REMEDIATION: an '
        'owner-provided WooCommerce sandbox credential, or an explicit decision to leave these '
        'screens unverified in Phase 2.5.',

    'Edit customer (registered, unimplemented)':
        'NOT a missing screen -- a WEB/FLUTTER PARITY GAP, and the inventory description is stale. '
        'The routes are NOT registered: routes/web.php:1191 is '
        "Route::resource('customer', ...)->except(['edit','update']), applied by P2.5-PKG-C (F-C-04) "
        'because CustomerController has neither method and GET /customer/1/edit answered HTTP 500. '
        'But a COMPLETE customer-update backend ships today and the Flutter admin already uses it: '
        'routes/api.php:267 POST update-customer -> DashboardController::updateCustomerInfo (:9113), '
        'writing first_name/last_name/email/mobile/status at :9193-9197, consumed by '
        'StoreSyria_app98 lib/utils/base_api.dart:122 through an edit bottom-sheet. '
        'REMEDIATION: an owner decision on whether the WEB screen should exist. Building it is new '
        'merchant functionality, which routes/web.php:1187-1189 records as unauthorised in Phase 2.5.',

    'Wishlist create / edit / store / update (registered, unimplemented)':
        'All four routes resolve and none renders a byte. WishlistController::create() (:34) and '
        '::store() (:45) have bodies that are literally `//`; ::edit() and ::update() carry P2.5-PKG-B '
        'security guards that REFUSE rather than render. No control on any merchant screen targets '
        'any of the four. '
        'REMEDIATION: owner decision on whether merchant-authored wishlists are a product feature at '
        'all. There is nothing to design until there is something to render.',

    'Customer filter result fragment':
        'GET customer-filter-data answers HTTP 200 and has NO CALLER. Its former parent was the '
        'customer grid, which no longer calls it (recorded in customer/grid.blade.php:38-39 during '
        'P2.5-R-07). It returns a JSON-encoded HTML string of customer/filter.blade.php, not a page. '
        'It cannot be made a screen without inventing a consumer for it. '
        'REMEDIATION: either a deliberate decision to retire the route, or a consumer that a '
        'merchant-facing feature actually needs. Neither is a design task.',

    'Customer filter field metadata':
        'GET customer-filter returns application/json and never a view, so it is not a screen and '
        'cannot earn a screen state. Its OUTPUT is already rendered -- by the customer filter bar '
        'redesigned and captured in P2.5-PKG-G -- so the merchant-visible half of this surface is '
        'verified THERE. Claiming it separately would mean reusing that evidence for a second '
        'surface, which the owner forbids by name. '
        'REMEDIATION: none needed; it is correctly covered as part of the filter bar.',
}

# ---------------------------------------------------------------------------------------------
# THE SAME SCREEN, INVENTORIED TWICE. A sixth state, and the reason the denominator moves.
#
# The directive says a screen must not silently disappear from the inventory, so a duplicate row
# is NOT deleted -- it stays visible, named, and pointed at the row that owns it. What it must
# not do is be CLAIMED twice: two verified rows for one screen would inflate the completion
# figure using one set of photographs, which is the overclaim the VERIFIED block exists to stop.
#
# Each pair below was confirmed to be one screen by route AND by controller action, not by a
# similar name:
#
#   'Notifications (full list)'      GET merchant-notifications   -- the SAME route as
#   "Notifications (the bell's ...)" GET /merchant-notifications     the row already claimed
#                                    PREMIUM VERIFIED by P2.5-WAVE-33 from `_merchant_
#                                    notifications__`. Two auditors reached the same screen from
#                                    different entry points and each wrote it down; their audit
#                                    columns even disagree (premium-already vs needs-polish).
#
#   'Stock Reports (3-tab shell)'    GET stock-reports  }  routes/web.php:1108-1109 register ONE
#   'Stock Reports'                  GET /stock-report  }  controller action on two URIs. Both
#                                    were photographed in the 2026-11-10 sweep and both sets show
#                                    the same page with the same title.
#
# SO THE INVENTORY HOLDS 295 ROWS DESCRIBING 293 DISTINCT SURFACES. Both numbers are reported.
# ---------------------------------------------------------------------------------------------
DUPLICATE_OF = {
    # The inventory carries this artefact TWICE -- once in the Wave A component section
    # (PHASE-2.5-SURFACE-INVENTORY.md:540) and once in the navigation-shell section (:99), both
    # with route `partial/component`. Both name the same file,
    # resources/views/layouts/includes/store-syria-ds.blade.php.
    'Design-system opt-in partial': (
        'DS theme hook + opt-in partial',
        'the same file -- resources/views/layouts/includes/store-syria-ds.blade.php -- which the '
        'other row already covers as its "opt-in partial" half. Two audit passes listed it in '
        'two sections.'),

    # The three stock row-payload rows are claimed on clipped captures of the very tables these
    # cells are rendered INTO (`#low-stock-table` and its two siblings). The cells and the
    # payload are the same pixels; claiming both would count one surface twice off one set of
    # photographs, which is the overclaim the VERIFIED block exists to prevent.
    'Stock report row cells (status + action)': (
        'Low in Stock \u2014 row payload',
        'the same pixels. shoot-fragments.cjs clips `#low-stock-table`, `#out-of-stock-table` '
        'and `#most-stocked-table` -- the tables the row payloads land in -- so the three '
        'payload rows are already claimed on captures OF these cells. A fourth claim on the '
        'same images would inflate the count.'),

    # ONE FLUTTER SCREEN, INVENTORIED TWICE. Both rows name the same GetX route and the same
    # widget: inventory :602 `Bottom-nav shell (DashBoardManagerScreen)` -> `/dashboard (GetX
    # named route)`, and :658 `Dashboard shell (bottom navigation)` -> `GetX /dashboard`. There is
    # one `DashBoardManagerScreen` in lib/ and one `/dashboard` route to reach it.
    'Dashboard shell (bottom navigation)': (
        'Bottom-nav shell (DashBoardManagerScreen)',
        'the same GetX route `/dashboard` and the same widget. Two audit passes recorded the '
        'bottom-navigation shell under two names; claiming both would count one screen twice.'),

    'Notifications (full list)': (
        "Notifications (the bell's destination)",
        'the same route, GET merchant-notifications. The owning row is already PREMIUM VERIFIED '
        '(P2.5-WAVE-33, `_merchant_notifications__`), so claiming this one from the 2026-11-10 '
        'sweep would count one screen twice.'),
    'Stock Reports (3-tab shell)': (
        'Stock Reports',
        'the same controller action. routes/web.php:1108-1109 register it as BOTH GET '
        'stock-reports (reports.stock_report) and GET /stock-report (stock.report); the '
        "inventory's own route column says so. Claimed once, on `_stock_reports__`."),
}

COMBOS = ['en_ltr_light', 'en_ltr_dark', 'ar_rtl_light', 'ar_rtl_dark']


def parse_inventory():
    rows, area = [], None
    for i, line in enumerate(open(INV, encoding='utf-8').read().splitlines(), 1):
        if line.startswith('## '):
            area = line[3:].split('—')[0].split('(')[0].split(' - ')[0].strip()[:58]
        if line.startswith('|') and line.count('|') >= 5:
            c = [x.strip() for x in line.strip('|').split('|')]
            if len(c) < 4 or set(c[0]) <= set('-: '):
                continue
            if c[-1].lower() in ('premium-already', 'needs-polish', 'deficient', 'unknown'):
                rows.append({'line': i, 'area': area, 'screen': c[0],
                             'route': c[1], 'audit': c[-1].lower()})
    return rows


def classify(row):
    """Audit state plus work done -> one of the directive's four, PENDING, or DUPLICATE."""
    # FIRST, ahead of everything including a VERIFIED claim. If a duplicate row ever acquires
    # captures, the captures are evidence for the screen its owner already holds -- not grounds
    # for a second verified row. Ordering this last would let a well-meaning claim re-inflate the
    # count, which is the whole failure this state exists to prevent.
    if row['screen'] in DUPLICATE_OF:
        owner, why = DUPLICATE_OF[row['screen']]
        return 'DUPLICATE', f'the same surface as **{owner}** — {why}'
    if row['screen'] in OUT_OF_SCOPE:
        return 'OUT OF SCOPE', OUT_OF_SCOPE[row['screen']]
    # A declared blocker outranks a VERIFIED claim deliberately: if someone later adds captures for
    # a surface that cannot exist, the captures are the thing to doubt, not the blocker.
    if row['screen'] in BLOCKED_SURFACES:
        return 'BLOCKED', BLOCKED_SURFACES[row['screen']]
    if row['screen'] in VERIFIED:
        # A screen NAME can repeat across areas -- "Order detail" is BOTH the Laravel web screen
        # (route order-view/{id}) and the Flutter screen (OrderDetailScreen). A verified entry
        # that carries a route needle claims only the row whose route contains it; the other
        # falls through to PENDING, so a web capture can never mark a Flutter surface verified.
        #
        # A LIST CLAIMS BOTH, each element with its own route needle. Before this, the only way
        # to claim the second row was to add a second dict entry under the same name -- and
        # Python silently KEEPS THE LAST ONE. That is not a hypothetical: adding the Flutter
        # `Order detail` and `Coupon detail` claims that way overwrote the web entries, which
        # demoted the web coupon detail from PREMIUM VERIFIED to REDESIGNED AND VERIFIED and
        # pointed both names at Flutter captures. The matrix counts moved by exactly one in each
        # direction and nothing failed. A list makes the two claims coexist instead of racing.
        entries = VERIFIED[row['screen']]
        if isinstance(entries, tuple):
            entries = [entries]

        for entry in entries:
            state, wave, prefix, pkg = entry[0], entry[1], entry[2], entry[3]
            route_needle = entry[4] if len(entry) > 4 else None
            if route_needle is not None and route_needle not in row['route']:
                continue
            base = os.path.join(ROOT, 'docs/development/phase-2.5/evidence', wave, 'after')
            missing = [c for c in COMBOS if not os.path.exists(os.path.join(base, f'{prefix}{c}.png'))]
            if missing:
                return 'BLOCKED', f'claimed {state} by {pkg} but these captures are absent: {", ".join(missing)}'
            return state, f'{pkg}; four captures under evidence/{wave}/'
    if row['audit'] == 'unknown':
        return 'BLOCKED', 'audit could not determine its state; it needs to be rendered and classified'
    return 'PENDING', f'audited {row["audit"]}; not yet redesigned or verified'


def build():
    rows = parse_inventory()
    if len(rows) != EXPECTED_TOTAL:
        sys.exit(f'REFUSING: the inventory parses to {len(rows)} surfaces, not {EXPECTED_TOTAL}. '
                 f'A surface was added or lost. Update EXPECTED_TOTAL deliberately, never silently.')

    # A VERIFIED KEY THAT MATCHES NO INVENTORY ROW IS A SILENT NO-OP, and silent is the problem:
    # the entry looks like a promotion, the tally does not move, and nobody reads the difference.
    # P2.5-WAVE-33 added 17 entries and checked this by hand -- which is exactly the check that
    # should not be by hand. A screen RENAMED in the inventory would otherwise quietly un-verify
    # itself, which is the failure mode this file's own header says it exists to prevent.
    names = {r['screen'] for r in rows}
    orphans = sorted(k for k in list(VERIFIED) + list(OUT_OF_SCOPE) + list(DUPLICATE_OF)
                     if k not in names)
    # A duplicate that points at a row which does not exist is the same silent no-op.
    lost = sorted(o for o, _ in DUPLICATE_OF.values() if o not in names)
    if lost:
        sys.exit('REFUSING: these DUPLICATE_OF owners match no inventory row:\n  '
                 + '\n  '.join(repr(o) for o in lost))
    if orphans:
        sys.exit('REFUSING: these VERIFIED/OUT_OF_SCOPE keys match no inventory row, so they claim '
                 'nothing and move no count:\n  ' + '\n  '.join(repr(o) for o in orphans))

    for r in rows:
        r['state'], r['why'] = classify(r)

    tally = collections.Counter(r['state'] for r in rows)
    by_area = collections.defaultdict(collections.Counter)
    for r in rows:
        by_area[r['area']][r['state']] += 1

    L = []
    L.append('# Phase 2.5 — cumulative coverage matrix')
    L.append('')
    L.append('**GENERATED. Do not hand-edit** — `docs/development/phase-2.5/tools/build-coverage-matrix.py`')
    L.append('rebuilds it from `PHASE-2.5-SURFACE-INVENTORY.md` and refuses to run if the surface count moves.')
    L.append('The directive requires that "a screen must not silently disappear from the inventory"; a')
    L.append('hand-maintained matrix is precisely how that happens.')
    L.append('')
    L.append('## Where Phase 2.5 stands')
    L.append('')
    L.append('| state | count | of 295 |')
    L.append('|---|---:|---:|')
    for s in ['PREMIUM VERIFIED', 'REDESIGNED AND VERIFIED', 'BLOCKED', 'OUT OF SCOPE', 'DUPLICATE', 'PENDING']:
        L.append(f'| {s} | {tally.get(s,0)} | {100*tally.get(s,0)/len(rows):.1f}% |')
    L.append(f'| **total** | **{len(rows)}** | |')
    L.append('')
    L.append('**PENDING is not one of the directive\'s four states, and that is the point.** The four')
    L.append('describe a finished surface. A cumulative matrix has to be able to say "not yet", and')
    L.append('saying it out loud is the opposite of claiming completion. **Phase 2.5 cannot be presented')
    L.append('for acceptance while PENDING is above zero.**')
    L.append('')
    L.append('**VERIFIED means rendered.** A surface earns a verified state only when the running')
    L.append('application was driven and captured in all four combinations. Inheriting a shared')
    L.append("component's repair is not verification — Wave 1's table work reaches roughly a hundred")
    L.append('screens and exactly three of them have been looked at. The other ~97 stay PENDING.')
    L.append('')
    L.append('## By merchant area')
    L.append('')
    L.append('| area | total | premium | redesigned | blocked | out of scope | pending |')
    L.append('|---|---:|---:|---:|---:|---:|---:|')
    for a in sorted(by_area, key=lambda k: -sum(by_area[k].values())):
        t = by_area[a]
        L.append(f'| {a} | {sum(t.values())} | {t.get("PREMIUM VERIFIED",0)} | '
                 f'{t.get("REDESIGNED AND VERIFIED",0)} | {t.get("BLOCKED",0)} | '
                 f'{t.get("OUT OF SCOPE",0)} | {t.get("PENDING",0)} |')
    L.append('')
    L.append('## Every surface')
    L.append('')
    L.append('`audit` is the state the Phase 2.5 audit recorded for the surface. `state` is what has')
    L.append('actually been done to it since. They are different questions and the matrix keeps both.')
    L.append('')
    for a in sorted(by_area, key=lambda k: -sum(by_area[k].values())):
        L.append(f'### {a}')
        L.append('')
        L.append('| surface | route / kind | audit | state | evidence or reason |')
        L.append('|---|---|---|---|---|')
        for r in [x for x in rows if x['area'] == a]:
            scr = r['screen'].replace('|', '\\|')
            rt = r['route'].replace('|', '\\|')[:62]
            L.append(f'| {scr} | `{rt}` | {r["audit"]} | **{r["state"]}** | {r["why"]} |')
        L.append('')
    return '\n'.join(L) + '\n', tally, len(rows)


def assert_no_shared_capture_sets():
    """No two surfaces may be claimed on the SAME capture set.

    `check-distinct-surface-captures.py` compares image CONTENT across the evidence tree, so it
    catches two different files that happen to be pixel-identical. It cannot catch two ROWS
    pointing at the SAME file -- there is only one file, and nothing collides.

    That gap is not hypothetical. Claiming the Flutter component rows, five surfaces were pointed
    at needles already used by five others -- `Shared app bar` and `Shared app bar (simpleAppBar)`
    are the same widget named twice in the inventory, as are the text field, the skeletons, the
    status indicators and the button family. Every guard passed. The owner's constraints forbid
    using one screenshot as evidence for two surfaces by name, so this closes the hole: the
    duplicates were re-pointed at a tablet capture set, and this refuses the next one.
    """
    seen = {}
    clashes = []

    for surface, claim in VERIFIED.items():
        if not isinstance(claim, tuple) or len(claim) < 3:
            continue
        state, wave, needle = claim[0], claim[1], claim[2]
        if state != 'REDESIGNED AND VERIFIED':
            continue
        key = (wave, needle)
        if key in seen:
            clashes.append(f'  {wave}/{needle}  claimed by BOTH "{seen[key]}" and "{surface}"')
        else:
            seen[key] = surface

    if clashes:
        sys.exit(
            'TWO SURFACES SHARE ONE CAPTURE SET:\n' + '\n'.join(clashes)
            + '\n\nOne picture may not be the evidence for two surfaces. Give the duplicate its '
              'own render -- a second width is usually the honest one -- or leave it PENDING with '
              'the duplication recorded.'
        )


if __name__ == '__main__':
    assert_no_shared_capture_sets()
    text, tally, total = build()
    if '--check' in sys.argv:
        cur = open(OUT, encoding='utf-8').read() if os.path.exists(OUT) else ''
        if cur != text:
            sys.exit('STALE: PHASE-2.5-COVERAGE-MATRIX.md does not match the inventory. Re-run without --check.')
        print(f'coverage matrix is current: {total} surfaces, {dict(tally)}')
    else:
        open(OUT, 'w', encoding='utf-8').write(text)
        print(f'wrote {OUT}')
        print(f'  {total} surfaces  ' + '  '.join(f'{k}={v}' for k, v in sorted(tally.items())))
