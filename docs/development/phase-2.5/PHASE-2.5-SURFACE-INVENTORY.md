# Phase 2.5 — the merchant surface inventory

**Generated from the Phase 2.5 audit, 2026-11-03.** Twelve surface areas were read by
independent agents working only from the code, then every claimed deficiency was put to an
adversarial verifier instructed to REFUTE it and to default to refuted when it could not
confirm. This file is the inventory half; the slices actually built from it are in
[`PHASE-2.5-TASK-LEDGER.md`](PHASE-2.5-TASK-LEDGER.md).

| | |
|---|---|
| screens inventoried | **295** |
| findings raised | **290** |
| by severity | **19 blocker**, 82 high, 140 medium, 49 low |
| by screen quality | 91 already premium, 116 needs polish, 78 deficient, 10 unknown |
| adversarially verified so far | 25 — **19 upheld, 6 refuted** |

**A quarter of the first findings were refuted.** That is the verification doing its job, and
it is why no finding here should be treated as actionable until it carries a verdict. The
verification pass was still running when this file was generated.

## Findings by category

| category | count |
|---|---|
| a11y | 63 |
| consistency | 39 |
| state-missing | 30 |
| form-ux | 25 |
| feedback | 24 |
| other | 23 |
| contrast | 22 |
| dark-mode | 15 |
| layout | 12 |
| rtl | 10 |
| table-ux | 10 |
| typography | 9 |
| responsive | 8 |

Accessibility is the largest single category at 63, and consistency the second at 39.
Both are what Phase 2.5 exists to address.

---

## Merchant dashboard and KPI presentation — GET /dashboard (merchant/employee branch) plus the POST partial that fills its two "By Sales" cards, and the nine x-ss.* KPI/stat/chart/attention/checklist components behind them.

**9 screens · 20 findings**

| screen | route | design system | quality |
|---|---|---|---|
| Merchant dashboard (page shell) | `GET /dashboard` | partial | needs-polish |
| Dashboard overview region (KPI surface) | `partial/component (included by GET /dashboard)` | yes | premium-already |
| Top Category / Top Brand "By Sales" body | `POST /top-brand-category-chart (routes/web.php:414)` | no | deficient |
| x-ss.stat / x-ss.stats (KPI cell + grid) | `partial/component` | yes | needs-polish |
| x-ss.bar-chart | `partial/component` | yes | needs-polish |
| x-ss.attention / x-ss.attention-item | `partial/component` | yes | premium-already |
| x-ss.setup-checklist | `partial/component` | yes | premium-already |
| x-ss.progress | `partial/component` | yes | needs-polish |
| Super Admin dashboard | `GET /dashboard (type='super admin' branch)` | n/a | unknown |

### Blocker and high findings

| id | severity | category | what | verdict |
|---|---|---|---|---|
| `ss-period-dark-contrast` | high | dark-mode | The design system's "Host override defence" list — the block that restates a colour with !important because the host's dark sheets force every paragraph grey — omits three classes the dashboard puts on <p> elements: .ss- | **REFUTED** |
| `bysales-ajax-loader-stuck` | blocker | feedback | fetchOrderDetails() raises the global blocking overlay on every tab change (:681, :692) but its error handler only does `console.error(error)`. It never calls `$('#loader').fadeOut()` and never shows the merchant anythin | upheld |
| `status-chips-unfiltered` | high | consistency | `$ssStatusHref = $ssMayOrders ? route('order.index') : null;` is a single unfiltered URL reused by all nine status chips (:424, :438), while the card's own note at :418 reads 'All time. Select a status to open that list. | upheld |

### Already meeting the standard

- dashboard/overview.blade.php is the strongest merchant surface I read. It is fully inside .ss-scope, uses ten x-ss.* components and no ad-hoc markup, and every figure states its own reporting period because x-ss.stat makes `period` a required prop with no defa
- Honest absence is distinguished from zero in three separate places, deliberately: x-ss.stat renders a distinct 'Not available' state for value===null (stat.blade.php:30-34, styled at store-syria-ds.css:1619-1623) rather than a 0; x-ss.bar-chart renders x-ss.em
- Permission gating is answered in exactly one place for both files that render /dashboard. App\Support\MerchantDashboardAbilities is operator-first (so isAbleTo() alone cannot hide orders from the platform operator), uses the real seeded names including the 'Ma
- The bar chart's accessible representation is a real <table>, not a role="img" summary — bars are aria-hidden, the data lives in a <details> with a caption naming the series and its period and `scope` on both header axes, plus a <tfoot> total (bar-chart.blade.p
- RTL is handled by construction rather than by a mirrored stylesheet. Every directional rule in the components I audited uses logical properties — border-inline-end on .ss-stat, inline-size on .ss-setup__mark and the progress fill, block-size on .ss-chart__bar,
- Bidi isolation is applied where Arabic actually breaks numbers: unicode-bidi: isolate on .ss-num (:291), .ss-stat__value (:1595), .ss-attention__count (:1588) and .ss-chart__label (:2478), with the reasoning recorded — a currency symbol beside a Latin-digit ru
- Status is never carried by colour alone. x-ss.status-badge renders a dot plus the text label (status-badge.blade.php:14-15), the order-status tone comes from one shared source (App\Support\OrderStatusTone) so the breakdown chips and the latest-orders rows cann
- Arabic string coverage for this area is complete, not partial. I checked 45 dashboard strings against resources/lang/ar.json (3,364 keys) — including the new Phase-02 ones ('Getting started', 'Needs attention', 'Order value', 'Orders by status', 'Store totals'

---

## Merchant admin NAVIGATION SHELL — resources/views/layouts/app.blade.php, resources/views/partision/* (sidebar, menu-zones, menu-tree, workspace-chip, header, command-palette, footerlink, head, settingPopup, footer), resources/views/layouts/includes/*, and the shell components resources/views/components/ss/{page-header,breadcrumb}.blade.php, plus the host CSS/JS they depend on (public/assets/css/style*.css, public/css/custom.css, public/css/store-syria-ds.css, public/css/store-syria-a11y.css, public/assets/js/dash.js, public/js/store-syria-nav.js, public/js/store-syria-ds.js).

**13 screens · 17 findings**

| screen | route | design system | quality |
|---|---|---|---|
| Merchant admin layout (the shell itself) | `partial/component — the layout for the 85 merchant views that set @section('page-title')` | yes | needs-polish |
| Sidebar (navigation rail) | `partial/component` | yes | needs-polish |
| Sidebar nav tree (items + submenus) | `partial/component (recursive @include of itself)` | no | deficient |
| Sidebar navigation zones (group headings) | `partial/component` | yes | premium-already |
| Workspace chip (store switcher, sidebar) | `partial/component; switching posts to POST /change-store/{id}` | yes | premium-already |
| Header / topbar | `partial/component` | partial | deficient |
| Command palette (global search) | `partial/component; data from GET /merchant-search and POST /merchant-pages/favourite` | yes | needs-polish |
| Breadcrumb component | `partial/component (rendered by the layout on every screen except dashboard)` | yes | premium-already |
| Page-header component | `partial/component` | yes | needs-polish |
| Notifications (the bell's destination) | `GET /merchant-notifications` | yes | needs-polish |
| Settings popup (vendored, inert) | `partial/component — included by layouts/app.blade.php:573 on every merchant page` | no | deficient |
| Footer + script bundle | `partial/component` | n/a | needs-polish |
| Design-system opt-in partial | `partial/component` | yes | premium-already |

### Blocker and high findings

| id | severity | category | what | verdict |
|---|---|---|---|---|
| `shell-skip-link-after-chrome` | high | a11y | The skip link is rendered AFTER @include('partision.sidebar') (:450) and @include('partision.header') (:452), so in DOM order — and therefore tab order, since nothing in the shell uses a positive tabindex — it comes afte | upheld |
| `offcanvas-sidebar-stays-focusable` | high | responsive | At 1024px, 768px and 390px the sidebar is moved entirely off-screen with `left:-255px` on a `position:fixed; width:255px` element. Nothing applies `visibility:hidden`, `display:none` or `inert` to the closed drawer — I g | upheld |
| `header-icononly-name-lost-by-tooltip` | high | a11y | Each of these is an icon-only <a> whose only accessible-name source is title= plus data-bs-toggle="tooltip". The vendored Bootstrap 5.1.3 (public/assets/js/plugins/bootstrap.min.js) runs _fixTitle() from _setListeners() | upheld |
| `header-triggers-nameless-below-992` | high | a11y | Both triggers derive their accessible name solely from a visible <span class="hide-mob"> — "Hi, {name}!" at :62 and the store name at :231. public/assets/css/style.css:17193 sets `.dash-header .dash-h-item .hide-mob { di | upheld |
| `nav-no-current-page-state` | high | state-missing | No sidebar item carries aria-current. The only current-location cue is an `active` class added client-side by dash.js, which marks an item only when `elem[l].href == window.location.href.split(/[?#]/)[0]` — an exact stri | upheld |
| `sidebar-desktop-dark-bg-contrast` | high | contrast | The P2-01 sidebar legibility fix is gated `@media (max-width: 1024px)`, but the dark background it compensates for is not confined to those widths. style.css:16355 only makes the sidebar transparent for `.dash-sidebar.li | _not yet verified_ |
| `nav-filter-hijacked-by-palette` | high | form-ux | One input, #sideBarsearch, has two incompatible jobs. layouts/app.blade.php:701 binds a keyup handler that filters the sidebar navigation; store-syria-ds.js:1170-1174 binds a document click handler that opens the command | _not yet verified_ |

### Already meeting the standard

- RTL discipline in the design-system layer is genuinely complete, not aspirational. public/css/store-syria-ds.css contains ZERO physical `left:`, `right:`, `margin-left/right:` or `padding-left/right:` declarations (measured by grep) and 12 `[dir="rtl"]` overri
- Every shell contrast pair I could compute passes AA, and the numbers are measured rather than asserted. Workspace-chip meta (12px): #6b7675 on #ffffff = 4.69:1 desktop-light, #95a09e on #263331 = 4.87:1 in the dark/mobile token set. Zone heading .ss-navgroup:
- The selected-navigation-item colour is solved by arithmetic rather than by a palette guess. layouts/app.blade.php:382-448 computes WCAG relative luminance for the merchant's own --theme-color at runtime and publishes --ss-onbrand (white or #11181c, whichever w
- The focus ring is restored system-wide for keyboard users only. public/css/store-syria-a11y.css:42-56 re-adds :focus-visible after the host theme's `*:focus { outline:none }` (style.css:15685, present identically in all four sheets), using the design system's
- The workspace chip is the best-built control in the shell. It is a real role=listbox with aria-selected as the single source of current-store truth (not a parallel is-current class), full Home/End/Arrow/Escape/Tab handling with an explicit empty-options guard
- Navigation information architecture is sound and defensible. config/merchant-navigation.php groups 21 top-level entries into six job-shaped zones (Sell, Catalogue, Grow, Storefront, Channels, Administration) with an explicit zones_enabled kill switch, an 'othe
- The grouping was implemented without breaking the host contract: menu-zones.blade.php emits the heading as a SIBLING .dash-item rather than a wrapper, because layouts/app.blade.php:711 selects `.dash-navbar > .dash-item` with a direct-child combinator and a wr
- The breadcrumb component fixes a real defect at the right level rather than per screen: --bs-breadcrumb-item-active-color and --bs-breadcrumb-divider-color are byte-identical in style.css and style-dark.css, so .ss-breadcrumb redefines its own tokens per theme

---

## PRODUCTS — merchant product list (table + grid), full-page create/edit editor, media (gallery, cover, per-variant image), variants (attribute combinations), attribute options, and the read-only product detail screen. Laravel repo /home/user/syriastore98.

**16 screens · 23 findings**

| screen | route | design system | quality |
|---|---|---|---|
| Product list (table view) | `GET /product → product.index` | partial | premium-already |
| Product list (grid view) | `GET /product-list → product.list` | no | deficient |
| Product editor — create | `GET /product/create → product.create` | partial | needs-polish |
| Product editor — edit | `GET /product/{product}/edit → product.edit` | partial | needs-polish |
| Product detail (read-only) | `GET /product/{product} → product.show` | no | deficient |
| Product row actions (list cell fragment) | `partial/component — rendered by ProductDataTable::dataTable()` | yes | premium-already |
| Product list filter bar | `partial/component — included by product/index.blade.php:51` | yes | premium-already |
| Product quick-preview panel body | `GET /product-preview/{id} → product.preview` | yes | premium-already |
| Variant panels — create side | `POST /products/attribute_combination → products.attribute_combination` | no | deficient |
| Variant panels — edit/show side (saved variants) | `ANY /products/attribute_combination_data/edit → products.attribute_combination_data` | partial | needs-polish |
| Variant panels — rebuild after attribute change | `PUT /products/attribute_combination/edit → products.attribute_combination_edit` | partial | needs-polish |
| Variant delete confirmation dialog | `partial/component — included by edit, show, attribute_combinations_data and attribute_combinations_edit` | yes | premium-already |
| SKU combination table | `POST /products/sku_combination → products.sku_combination` | no | deficient |
| Attribute-option picker fragment | `POST /products/attrinbute_option → products.attribute_option` | no | unknown |
| Attribute options list (DS pilot) | `GET /attribute-option/{id?} → product-attribute-option.show` | yes | premium-already |
| Attribute option create / edit (modal) | `GET /add-attribute-option/{id?} → product-attribute-option.create; GET /edit-attribute-option/{id?} → product-attribute-option.edit` | yes | premium-already |

### Blocker and high findings

| id | severity | category | what | verdict |
|---|---|---|---|---|
| `product-grid-view-500` | blocker | other | ProductController@list() passes compact('products', 'settings', 'msg', 'filterCategories') in all three branches, but $filterCategories is never defined in list() — it is only built in index(). The "Grid View" button on | _not yet verified_ |
| `create-save-double-submit` | high | feedback | The create editor's only re-entrancy guard is $('#submit-all').attr('disabled', true) on a control that is an <a href="#"> (create.blade.php:17). Nothing stops a second click, so an impatient double-click posts the whole | _not yet verified_ |
| `gallery-image-actions-unnamed` | high | a11y | Each existing gallery thumbnail carries two icon-only controls with no accessible name: the Download anchor has only data-original-title="Download" and an <i class="ti ti-download">, and the Delete anchor has only <i cla | _not yet verified_ |
| `variant-field-labels-orphan` | high | a11y | Twenty-eight Form::label('', …) calls across the three live variant renderers emit <label for="">, and the inputs they sit beside get no id either — so SKU, Weight, Variation Price, Sale Price, Stock Status, Description, | _not yet verified_ |
| `grid-view-badge-contrast` | high | contrast | The grid view still paints the category pill and all four stock states with raw Bootstrap bg-* badge classes. In this theme those resolve to tinted backgrounds with same-hue text and to white-on-brand, all below AA. | _not yet verified_ |
| `show-screen-readonly-noop` | high | state-missing | The read-only product screen simulates read-only with $('input').attr('readonly', true); $('select').attr('readonly', true). readonly is not a valid attribute on <select>, <input type=checkbox>, <input type=radio> or <in | _not yet verified_ |

### Already meeting the standard

- The product list's table infrastructure is genuinely premium and reaches all 68 merchant tables from one helper without touching packages/workdo/: the action column is both ss-col-locked (kept out of the column-visibility menu, so a merchant cannot hide the on
- Empty and filtered-empty states are distinct, translated, and per-table: dataTableEmptyState() (app/Helper/helper.php:3225-3260) gives the product list "No products yet / Add your first product with the button above." for emptyTable and a different "Nothing ma
- Row density and column visibility are real persisted preferences, not per-visit toggles: stateSave with stateDuration 0 (no expiry), the saved state sanitised in both directions so a stale search term or page number is never restored, density restored on state
- The filter bar is the right shape for the job: a plain GET form with no JavaScript at all (the AJAX leg inherits the query string because minifiedAjax() sets ajax.url to the page url), every control has a real <label for>, the stock hint is attached by aria-de
- The stock indicator and the stock filter share one implementation (ProductDataTable::trackedStockState / stockFallbackThreshold / scopeToLowStock), so the badge and the predicate agree by construction on the tracked path; the three states provably partition tr
- Row actions are correctly named and correctly coloured: product/action.blade.php gives each of the three controls an explicit aria-label (because Bootstrap moves title into data-bs-original-title, which this codebase has been bitten by), uses ss-btn--ghost / -
- The editor's save affordances are well built: a sticky x-ss.actionbar that is role="region" with a label (so it is a landmark a keyboard user can jump to), ss-has-actionbar on the scrolling form so the bar cannot cover a focused field (WCAG 2.2 Focus Not Obscu
- Concurrent editing is handled honestly: edit.blade.php:60 carries the updated_at the form loaded as ss_last_seen_at (->toJSON() so precision survives the round trip), ProductController@update:1833-1848 compares with ->eq() so a formatting difference is never r

---

## ORDERS — merchant/employee order list, grid, board (kanban), detail, quick-preview, AJAX modal, create-order form, refund-request list, refund decision screen, printable receipt and shipping label. Files: /home/user/syriastore98/resources/views/order/**, app/Http/Controllers/OrderController.php, app/Http/Controllers/OrderRefundController.php, app/DataTables/OrderDataTable.php, app/DataTables/OrderRefundDataTable.php

**17 screens · 36 findings**

| screen | route | design system | quality |
|---|---|---|---|
| Order list | `GET order (order.index)` | yes | premium-already |
| Order board (Kanban) | `GET order-kanban (order.kanban)` | yes | needs-polish |
| Order detail | `GET order-view/{id} (order.view)` | partial | needs-polish |
| Order quick preview (side panel fragment) | `GET order-preview/{id} (order.preview)` | yes | premium-already |
| Order AJAX modal (the eye / Show button) | `GET orders/order_view/{id} (order.order_view)` | no | deficient |
| Order grid (card view) | `GET order-grid (order.grid)` | partial | deficient |
| Order row actions (DataTable partial) | `partial/component` | yes | premium-already |
| Order filter bar + saved views | `partial/component` | yes | premium-already |
| Create order | `GET order/create (order.create); POST order (order.store)` | partial | needs-polish |
| Refund request list | `GET refund-request (refund-request.index)` | partial | deficient |
| Refund detail / decision screen | `GET refund-request/{id} (refund-request.show)` | partial | deficient |
| Printable receipt | `GET order-receipt/{id} (order.receipt)` | n/a | premium-already |
| Printable shipping label | `GET shippinglabel/pdf/{id} (shippinglabel.pdf)` | n/a | premium-already |
| Order note author (metadata partial) | `partial/component` | n/a | premium-already |
| Plan-order bank-transfer review modal (OUT OF SCOPE — Phase 06) | `GET plan/orders/show/{id} (plan.order.show)` | no | unknown |
| Top brand/category chart fragment (OUT OF SCOPE — Phase 05) | `partial/component` | no | unknown |
| order/create.blade.php and order/edit.blade.php (DEAD) | `partial/component` | no | unknown |

### Blocker and high findings

| id | severity | category | what | verdict |
|---|---|---|---|---|
| `grid-text-dark-invisible` | blocker | dark-mode | The customer name, the customer phone and the order PRICE on every grid card are wrapped in <span class="text-dark text-md">. In dark mode .text-dark resolves to rgb(28,35,47) (#1C232F) and the card it sits on is #292A33 | _not yet verified_ |
| `grid-card-icons-black` | high | contrast | .order-card .btn:hover i, .order-card .btn i { color: #000000; } forces every icon on every order-grid card button to pure black, with no theme guard. On the dark card (#292A33) the Show (ti-eye) and Edit (ti-pencil) ico | _not yet verified_ |
| `grid-status-icon-colour-only` | high | a11y | A grid card's order status is rendered only as a coloured icon inside <a class="dropdown-item" href="#" data-value="confirmed">. Three different statuses share the same glyph and differ only in hue: Cancel is `fa fa-chec | _not yet verified_ |
| `grid-fallthrough-pending` | high | state-missing | The status chain has no branch for delivered_status 7 (Partially Paid) or 8 (Pre Order), and none for 3 with return_status 0. $title is initialised to __('Pending') at line 35, so all of those orders are presented on the | _not yet verified_ |
| `grid-row-actions-unnamed` | high | a11y | All four icon-only row actions on a grid card (return Approve, return Cancel, Show, Edit, Delete) carry data-bs-toggle="tooltip" and title but NO aria-label, so after the tooltip initialises each is an unnamed control wr | _not yet verified_ |
| `kanban-move-wrong-request-key` | blocker | other | The board's only write control posts <select name="order_status">, but OrderController::order_status_change reads $data['order_status'] = $request->delivered (OrderController.php:1657). The posted value never reaches the | _not yet verified_ |
| `kanban-move-returns-raw-json` | blocker | feedback | The move control is a native <form method="POST"> with no JavaScript handler (grep finds no .ss-board__card-move binding in public/js/store-syria-ds.js or public/js/custom.js), while order_status_change returns response( | _not yet verified_ |
| `detail-note-form-labels-unassociated` | high | a11y | Both visible labels on the add-note form are Form::label('', ...), which renders <label for="">. The textarea at line 662 has name="note" and no id; the note_type select at line 667 has no id either. So the two controls | _not yet verified_ |
| `order-show-delivered-no-confirm-no-feedback` | high | feedback | The modal's footer offers <a href="#" class="btn btn-sm btn-info delivered-button-status">Delivered</a>: a one-click status write from a read-only panel with no confirmation dialog, no busy state (so a double click posts | _not yet verified_ |
| `refund-status-badges-below-aa` | high | contrast | The refund-request list's status column builds the vendored theme's soft badge: bg-light-success (default/Accept), bg-light-danger (Cancel), bg-light-info (Processing), bg-light-warning (Refunded). All four are below AA, | _not yet verified_ |
| `refund-action-button` | high | contrast | The refund list's only row action is an icon-only <a class="btn btn-sm btn-warning"> with data-bs-toggle="tooltip" and title but no aria-label. Two defects in five lines: the ti-eye icon measures 1.68:1 in light mode, an | _not yet verified_ |
| `refund-localstorage-global-gate` | blocker | state-missing | Which refund controls are available is decided from browser localStorage under keys that carry no refund id: localStorage.getItem('manageButtonClicked') hides #manage-button, and localStorage.getItem('refundedClicked') h | _not yet verified_ |
| `refund-four-identical-buttons` | high | feedback | The refund decision screen's four actions — Accept Request, Cancel Request, Manage Stock and Refund Amount — are all class="btn btn-primary", identical in weight and colour. There is no primary/secondary/destructive hier | _not yet verified_ |
| `refund-hardcoded-light-card` | high | dark-mode | The 'Order Refund Information' card body carries style="background: #f5f5f5" — a hardcoded light surface with no theme guard. In dark mode the surface stays #f5f5f5 while the host's dark chrome keeps painting the text on | _not yet verified_ |
| `refund-silent-ajax-failures` | high | feedback | The #manage-button and #refund-amount AJAX calls have no error: callback at all, so a 403 (the handler legitimately refuses — OrderRefundController::refundDenied, line 97), a 419 on an expired CSRF token or a 500 leaves | _not yet verified_ |

### Already meeting the standard

- THE PHASE 02 STATUS-COLUMN CONTRAST DEFECT IS FIXED, and I verified the numbers rather than taking the note on trust. app/DataTables/OrderDataTable.php:228-284 no longer emits btn-soft-* buttons; it calls App\Support\OrderStatusTone::badge() -> App\Support\DsB
- The status column also no longer lies about dates or swallows unknown values: OrderDataTable.php:275-283 returns 'Status not set' for a null delivered_status rather than casting it to 0 and printing 'Pending', prints 'Status :code' for an out-of-range value in
- resources/views/order/action.blade.php is the best-executed surface in the area. Every one of the six row controls is an ss-btn ss-btn--icon with a theme-aware tone, every <i> is aria-hidden, and every control carries an aria-label that names the ORDER as well
- resources/views/order/partials/preview.blade.php is exemplary. It presents orders.delivered_status as THREE separate readings (fulfilment, payment, payment method, shipping method) instead of collapsing ledger F0's three concepts into one badge; the line items
- resources/views/order/partials/filters.blade.php is a correct filter bar. Every control has a real <label class="ss-label" for="…"> against a matching id; the status options are built from the HomeController::ORDER_STATUS_* constants so a tenth status cannot b
- SAVED VIEWS ARE BUILT, contrary to the Phase 02 note that only DataTables stateSave plus url-carried filters existed. There is a SavedView model with sanitiseFilters(), a SavedViewController with POST store / POST apply / DELETE destroy (routes/web.php:1229-12
- THE ORDER BOARD IS BUILT, contrary to the Phase 02 note. kanban.blade.php and partials/kanban-card.blade.php are 194 lines of design-system markup: seven fulfilment columns with role=list / role=listitem and per-column counts in the accessible name, ss-badge h
- THE ORDER DETAIL PAGE'S DESIGN-SYSTEM RESTYLE IS DONE, contrary to the Phase 02 note. resources/views/order/view.blade.php carries ss-card / ss-card__header / ss-card__body, ss-deflist for both address blocks, ss-badge on the status control taking its tone fro

---

## Customers and customer management (merchant admin): customer list / grid / create / orders / timeline / filters / status toggle / archive, plus the merchant-facing abandoned-cart and abandoned-wishlist surfaces and their e-mail and message send actions.

**20 screens · 25 findings**

| screen | route | design system | quality |
|---|---|---|---|
| Customer list | `GET customer (customer.index)` | partial | needs-polish |
| Customer grid (card view) | `GET customer-grid (customer.grid)` | no | deficient |
| Customer row actions | `partial/component (rendered into the DataTable 'action' column)` | yes | premium-already |
| Customer status toggle | `partial/component (DataTable 'status' column) + POST customer-status (update.customer.status)` | no | needs-polish |
| Customer filter result fragment | `GET customer-filter-data (customer.filter.data)` | no | deficient |
| Customer filter field metadata | `GET customer-filter (customer.filter)` | n/a | needs-polish |
| Create customer | `GET customer/create (customer.create) + POST customer (customer.store)` | partial | needs-polish |
| Customer orders (reached by the cart icon) | `GET customer/{id} (customer.show)` | no | deficient |
| Customer activity timeline | `GET customer-timeline/{id} (customer.timeline)` | no | deficient |
| Archive customer | `DELETE customer/{customer} (customer.destroy)` | yes | premium-already |
| Edit customer (registered, unimplemented) | `GET customer/{customer}/edit (customer.edit) + PUT/PATCH customer/{customer} (customer.update)` | n/a | deficient |
| Abandoned carts list | `GET abandon-carts-handled (abandon.carts.handled)` | partial | needs-polish |
| Abandoned-cart product cell | `partial/component (DataTable 'product' column)` | no | deficient |
| Abandoned-cart products modal | `GET abandon-carts-show/{customerId} (carts.show)` | no | deficient |
| Abandoned-cart row actions (send mail / send message / delete) | `partial/component; POST abandon-carts-emailsend (carts.emailsend), POST abandon-carts-messagesend (carts.messagesend), DELETE abandon-carts-destroy/{customerId} (carts.destroy)` | yes | deficient |
| Abandoned wishlists list | `GET wishlist (wishlist.index)` | partial | needs-polish |
| Abandoned-wishlist product cell | `partial/component (DataTable 'product' column)` | no | deficient |
| Abandoned-wishlist products modal | `GET wishlist/{wishlist} (wishlist.show)` | no | deficient |
| Abandoned-wishlist row actions (send mail / send message / delete) | `partial/component; POST abandon-wish-emailsend (wish.emailsend), POST abandon-wishlist-message-send (wishlist.message.send), DELETE wishlist/{customer_id} (wishlist.destroy)` | yes | deficient |
| Wishlist create / edit / store / update (registered, unimplemented) | `GET wishlist/create, GET wishlist/{wishlist}/edit, POST wishlist, PUT wishlist/{wishlist} (routes/web.php:1127)` | no | deficient |

### Blocker and high findings

| id | severity | category | what | verdict |
|---|---|---|---|---|
| `cart-wishlist-svg-global-white-fill` | blocker | contrast | The inline WhatsApp SVG for the "Send Message" row action contains `<style>svg{fill:#ffffff}</style>`. The button around it is `.ss-btn--ghost`, which is `background: transparent` (public/css/store-syria-ds.css:579), so | **REFUTED on the running page (P2.5-PKG-C).** Neither `cart/action.blade.php` nor `wishlist/action.blade.php` contains a `<style>` block or a `#ffffff` fill; both SVGs carry `fill="currentColor"`, so the icon takes the button's own colour in both themes. Already repaired before this package; recorded here so it is not re-solved. |
| `customer-list-apply-filter-dead` | blocker | form-ux | The Apply control carries `id="applyfilter"`, but the only click handler in the page binds `$('#apply-button')`. `#applyfilter` appears exactly once in the whole repository — as this id — and nothing binds it (grepped re | _not yet verified_ |
| `customer-filter-target-missing` | blocker | feedback | `applyFilter()` GETs customer.filter.data and writes the response into `$('#service-filter-data')`. That id exists nowhere in any Blade view, any controller, or any file under public/ — only in these two write sites. So | _not yet verified_ |
| `customer-orders-status-chip-contrast` | high | contrast | Nine order-status chips are rendered as `<button type="button" class="btn btn-sm btn-* badge-same">`. Composited against the white card, the vendored soft variants measure: btn-warning 'Confirmed' #f4b41a on rgba(244,180 | _not yet verified_ |
| `customer-orders-export-dead-cdn` | high | state-missing | The Export action loads table2excel from `https://rawgit.com/unconditional/jquery-table2excel/master/src/jquery.table2excel.js`. rawgit.com was retired in October 2019, so the script 404s and `table2excel` is undefined. | _not yet verified_ |
| `customer-grid-text-dark-dark-mode` | high | dark-mode | The three data lines on every customer card (Total Order, e-mail, Last Active) use `.text-dark`. public/assets/css/style-dark.css keeps `.text-dark { color: rgba(var(--bs-dark-rgb), 1) !important }` with `--bs-dark-rgb: | _not yet verified_ |
| `timeline-white-box-dark-mode` | high | dark-mode | `.my-custom-timeline .tl-box { … background: #ffffff; }` is a hard-coded white surface in custom.css, which layouts/app.blade.php:153 loads in both light and dark, and there is no `html[data-ss-theme="dark"]` override fo | _not yet verified_ |
| `abandoned-send-no-pending-no-error` | high | feedback | All four recovery actions (cart e-mail, cart message, wishlist e-mail, wishlist message) declare only a `success` callback — there is no `error` handler in either file. The controllers deliberately answer HTTP 403 for a | **CONFIRMED and CLOSED (P2.5-PKG-C), both halves.** *no-error*: all four actions now carry an `error` leg, relaying the controller's own wording for a 4xx and a generic translated string otherwise -- driven against a real 404 in all four combinations, body contrast 6.17-6.42. Adding it is also what exposed a 500 in the wishlist handlers that was printing `Attempt to read property "customer_id" on null` into the interface. *no-pending*: measured through a 1500ms in-flight request, `#loader` stayed `display: none` and the button was neither disabled nor `aria-busy`, so a second click sent a SECOND message to the shopper; all four now set `aria-busy` + `disabled` in `beforeSend` and clear them in `complete`, re-measured 0 defects. |

### Already meeting the standard

- The customer list's table chrome is genuinely premium and should not be re-solved. Because resources/views/customer/index.blade.php renders through <x-datatable>, 'customer-table' inherits everything buildDataTable() built: a bespoke, localised empty state ('N
- resources/views/customer/action.blade.php is the best file in the area and the P2-05-2 pass on it is real. Every row action carries an aria-label that names both the verb and the customer ('Activity timeline for :name'), which is the correct fix rather than re
- The destructive-action confirmation is correct and honest. customer/action.blade.php:72 tells the merchant what will actually happen — 'Are you sure you want to delete this customer? This will archive the customer data' — which matches destroy()'s soft delete
- The create-customer form has sound bones. Every one of the six fields has a real <label> bound by name (never placeholder-as-label — the placeholders are genuine examples alongside labels), is-invalid is applied per field from $errors, and x-ss.error-summary s
- The customer list's output escaping is careful and documented. CustomerDataTable:65-69 applies e() to each shopper-written value individually rather than to the finished cell (which would double-escape the file's own <a> and <br> into visible tag soup), and 'e
- Breadcrumbs are consistent and theme-correct on all six full-page screens in the area. Each view supplies only its own trail through ss-breadcrumb__item / ss-breadcrumb__link and the layout renders it through x-ss.breadcrumb, whose tokens are redefined per the
- The status write is correctly shaped after P2-05-1: POST rather than GET (routes/web.php:1177), CSRF from the global $.ajaxSetup, and — the part that matters for durability — both callers were found by censusing the route NAME across every view rather than fix
- Almost every fix this audit proposes is adoption rather than new component work. x-ss.status-badge (with a non-colour dot, so hue is not the only signal), x-ss.switch (visible label plus a real <label for>), x-ss.empty, x-ss.stat/x-ss.stats, x-ss.field-help, x

---

## CATALOGUE taxonomy — categories, brands, attributes, attribute options, tags, product labels, coupons, flash sales, product questions, testimonials, FAQs (merchant admin, Laravel repo /home/user/syriastore98)

**38 screens · 33 findings**

| screen | route | design system | quality |
|---|---|---|---|
| Category list | `GET /category` | partial | premium-already |
| Category create (modal) | `GET /category/create` | yes | premium-already |
| Category edit (modal) | `GET /category/{category}/edit` | yes | premium-already |
| Category row actions | `partial/component` | yes | premium-already |
| Brand list | `GET /product-brand` | partial | needs-polish |
| Brand create (modal) | `GET /product-brand/create` | no | deficient |
| Brand edit (modal) | `GET /product-brand/{productBrand}/edit` | no | deficient |
| Brand row actions | `partial/component` | partial | needs-polish |
| Attributes list | `GET /product-attributes` | partial | premium-already |
| Attribute create / edit (modals) | `GET /product-attributes/create, GET /product-attributes/{id}/edit` | yes | premium-already |
| Attribute row actions | `partial/component` | yes | premium-already |
| Attribute options (DS pilot screen) | `GET /attribute-option/{id?}` | yes | premium-already |
| Attribute option create / edit (modals) | `GET /add-attribute-option/{id?}, GET /edit-attribute-option/{id?}` | yes | premium-already |
| Tag list | `GET /tag` | partial | needs-polish |
| Tag create / edit (modals) | `GET /tag/create, GET /tag/{tag}/edit` | no | needs-polish |
| Tag row actions | `partial/component` | yes | premium-already |
| Product label list | `GET /product-label` | partial | needs-polish |
| Product label create / edit (modals) | `GET /product-label/create, GET /product-label/{id}/edit` | no | needs-polish |
| Product label row actions | `partial/component` | partial | needs-polish |
| Coupon list | `GET /coupon` | partial | deficient |
| Coupon detail | `GET /coupon/{coupon}` | partial | deficient |
| Coupon create (modal) | `GET /coupon/create` | no | deficient |
| Coupon edit (modal) | `GET /coupon/{coupon}/edit` | no | deficient |
| Coupon row actions | `partial/component` | yes | needs-polish |
| Flash sale list | `GET /flash-sale` | partial | needs-polish |
| Flash sale status cell | `partial/component` | no | deficient |
| Flash sale create (modal) | `GET /flash-sale/create` | no | deficient |
| Flash sale edit (modal) | `GET /flash-sale/{id}/edit` | no | deficient |
| Product question list | `GET /product-question` | partial | needs-polish |
| Product question reply (modal) | `GET /product-question/{id}/edit` | no | deficient |
| Product question row actions | `partial/component` | partial | needs-polish |
| Testimonial list | `GET /testimonial` | partial | deficient |
| Testimonial create / edit (modals) | `GET /testimonial/create, GET /testimonial/{Testimonial}/edit` | no | deficient |
| Testimonial row actions | `partial/component` | partial | needs-polish |
| FAQ list | `GET /faqs` | partial | needs-polish |
| FAQ create (modal) | `GET /faqs/create` | no | deficient |
| FAQ edit (modal, repeater) | `GET /faqs/{faq}/edit` | no | deficient |
| FAQ row actions | `partial/component` | yes | premium-already |

### Blocker and high findings

| id | severity | category | what | verdict |
|---|---|---|---|---|
| `catalogue-unassociated-labels` | high | a11y | 53 real occurrences of Form::label('', __('…'), […]) across 13 views in this area render <label for="">. Census (comment mentions excluded): product_brand/create 13,31,39; product_brand/edit 5,12,30,42; product_label/cre | _not yet verified_ |
| `switch-no-accessible-name` | high | a11y | The status/popularity switches pair a Form::label('', …) caption with an EMPTY <label class="form-check-label" for="status"></label>, so the checkbox has no accessible name from any source. Same shape at product_brand/ed | _not yet verified_ |
| `coupon-duplicate-switch-id` | high | form-ux | Two different checkboxes in the same form are given id="customCheckdef1": 'status' at :125 and 'sale_items' at :133. coupon/edit.blade.php:93 and :101 repeat it. Both of their for="customCheckdef1" labels therefore point | _not yet verified_ |
| `coupon-code-placeholder-as-label` | high | form-ux | The coupon_code input has no <label> anywhere; its only text is placeholder="{{ __('Generate Code') }}". coupon/edit.blade.php:82 is identical. It is the single most important field on the form — the string the customer | _not yet verified_ |
| `unnamed-generate-code-button` | high | a11y | <a href="#" class="btn btn-primary" id="code-generate"><i class="ti ti-history"></i></a> — no text content, no title, no aria-label. coupon/edit.blade.php:85 is identical. This is the control that fills the code field. | _not yet verified_ |
| `unnamed-faq-add-row-button` | high | a11y | <a href="javascript:;" data-repeater-create class="btn btn-light-primary"><i class="ti ti-plus"></i></a> — the only way to add a question/answer pair to a FAQ, with no text, no title and no aria-label. Its sibling delete | _not yet verified_ |
| `primary-action-named-only-by-title` | high | a11y | Seven list screens' primary actions are icon-only anchors whose only name is a title that also carries data-bs-toggle="tooltip": product_brand/index.blade.php:16, tag/index.blade.php:9, product_label/index.blade.php:16, | _not yet verified_ |
| `flash-sale-delete-row-unnamed` | high | a11y | The condition-row delete button has no accessible name: the <button> is empty apart from <i class="ti ti-trash" data-bs-toggle="tooltip" title="Delete">, so the title sits on the icon rather than the control — and is bla | _not yet verified_ |
| `upload-not-keyboard-reachable` | high | a11y | Every image/icon upload in this area hides its file input with d-none and relies on a click on the wrapping <label> to open the picker: category/create.blade.php:85,106; category/edit.blade.php:87,108; product_brand/crea | _not yet verified_ |
| `validation-errors-lose-the-modal` | high | form-ux | Every create/update handler in this area answers a validation failure with redirect()->back()->with('error', $messages->first()) — CategoryController:65, ProductBrandController:48, and the same shape in TagController, Pr | _not yet verified_ |
| `coupon-detail-jspdf-throws` | high | other | An unconditional @push('custom-script') block runs on every load of the Coupon Detail screen: var doc = new jsPDF(); … doc.fromHTML(source, 15, 15, {…}); doc.output("dataurlnewwindow"); | _not yet verified_ |

### Already meeting the standard

- resources/views/attribute_option/index.blade.php is the strongest screen in this area and should be the reference for the rest of it. It is fully on the design system (ss-scope, ss-card, ss-empty, ss-sort-list), it is the only taxonomy screen with a real empty
- The category trio (index, create, edit, action) is already at the standard Phase 2.5 is aiming for: ss-field/ss-label/ss-input throughout, a visible label on the primary action instead of a tooltip-only name (index:22-28), every label associated with its input
- CategoryDataTable.php:34-51 renders Status through App\Support\DsBadge rather than the vendored bg-success/bg-danger family, so it gets theme-aware tokens AND the ss-badge__dot second channel — colour is not the only carrier of meaning. The helper also escapes
- The shared DataTable chrome (app/Helper/helper.php:2861-3350 and resources/views/components/datatable.blade.php) is genuinely premium and reaches all eleven lists here without a packages/workdo/ edit: the actions column is pinned to the trailing edge with inse
- Ledger finding F126 as described in the audit brief is already fixed in this repository and should not be re-reported: public/css/custom.css:4594-4615 (P2.5-A-01) scopes labels, .form-label, .col-form-label, .ss-label and every heading inside .modal-content to
- The two inline status toggles that this area got right are permission-aware rather than merely decorative: ProductBrandDataTable.php:33-56 and ProductLabelDataTable.php:33-45 each wrap the checkbox in a label carrying an ss-visually-hidden name, set disabled a
- Nine of the eleven row-action partials carry an explicit aria-label and aria-hidden="true" on the icon, so they are named even though Bootstrap blanks their title — and category/action.blade.php and attributes/action.blade.php go further and name the record, n
- The two status-changing ajax routes in this area are POST with the global CSRF header rather than state-changing GETs, with the reasoning and the measurement recorded inline (resources/views/product_brand/index.blade.php:43-53, product_label/index.blade.php:43

---

## Stock/inventory reporting + finance/wallet/payout screens (resources/views/reports/{stock_report,low\|out\|most_stock_status\|action}.blade.php, resources/views/setting/stock_setting.blade.php, resources/views/finance/*)

**13 screens · 25 findings**

| screen | route | design system | quality |
|---|---|---|---|
| Stock Reports (3-tab shell) | `GET stock-reports (reports.stock_report) and the duplicate GET /stock-report (stock.report) — routes/web.php:1108-1109` | partial | needs-polish |
| Low in Stock — row payload | `GET /stock-report/low-stock (stockReport.lowStock) — routes/web.php:462` | yes | premium-already |
| Out Of Stock — row payload | `GET /stock-report/out-stock (stockReport.outOfStock) — routes/web.php:463` | yes | premium-already |
| Most Stocked — row payload | `GET /stock-report/most-stocked (stockReport.mostStocked) — routes/web.php:464` | yes | premium-already |
| Stock Settings panel | `POST stock-settings (stock.settings) — routes/web.php:842 (rendered as a panel inside the settings page)` | yes | needs-polish |
| Finance dashboard | `GET /finance (finance.index) — routes/web.php:208` | no | deficient |
| Add Balance modal | `POST /finance/add-balance (finance.add.balance) — routes/web.php:212` | no | deficient |
| Deduct Balance modal | `POST /finance/deduct-balance (finance.deduct.balance) — routes/web.php:216` | no | deficient |
| Request Withdrawal modal | `POST /finance/withdrawal/request (finance.withdrawal.request) — routes/web.php:221` | no | deficient |
| Withdrawal queue | `GET /finance/withdrawals (finance.withdrawals) — routes/web.php:226` | no | deficient |
| Withdrawal approve / pay / reject | `POST /finance/withdrawals/{id}/approve\|pay\|reject — routes/web.php:275,279,283` | no | deficient |
| Order payment-status update (inline) | `POST /finance/order-financials/{id}/payment-status (finance.order-financial.status) — routes/web.php:288` | no | needs-polish |
| Payments Awaiting Review (unreconciled captures) | `GET /finance/unreconciled-captures (finance.unreconciled.captures) — routes/web.php:237` | partial | needs-polish |

### Blocker and high findings

| id | severity | category | what | verdict |
|---|---|---|---|---|
| `finance-status-badges-below-aa` | blocker | contrast | Every status pill in the finance area is a raw Bootstrap .badge bg-* utility, and the vendored theme redefines those utilities with BOTH a background and a foreground, so they land far below AA. Measured against the real | _not yet verified_ |
| `finance-credit-amount-1-8-to-1` | blocker | contrast | The credit amount in the wallet ledger renders as `<span class="text-success fw-bold">+{{ number_format($transaction->amount, 2) }}</span>`. The theme sets --bs-success-rgb: 111, 217, 67 (public/assets/css/style.css:43/5 | _not yet verified_ |
| `finance-validation-failure-is-silent` | blocker | form-ux | All three money modals post to handlers that call $request->validate() (StoreFinanceController:671 addBalance, :791 deductBalance, :916 requestWithdrawal). A ValidationException redirects back with messages in the `error | _not yet verified_ |
| `finance-money-without-currency` | high | other | Not one money figure outside the transactions table carries a currency. The five wallet stat cards, the three inventory money cards, all seven money columns of the order-financials table, both modal balance alerts, the w | _not yet verified_ |
| `finance-hyphen-means-both-nothing-and-minus` | high | table-ux | In the Credit column a non-credit row prints a literal `-` as the no-value marker (:341); in the Debit column a debit prints `-123.00` (:346). The same glyph therefore means 'not applicable' in one cell and 'minus' in th | _not yet verified_ |
| `withdrawal-native-confirm` | high | feedback | Approve and Mark-as-Paid — the two irreversible money-moving actions in the area — are guarded by `onclick="return confirm('{{ __('Approve this withdrawal request?') }}')"` and `confirm('{{ __('Confirm that this withdraw | _not yet verified_ |
| `captures-undefined-ss-classes` | high | state-missing | Three elements on this screen carry ss-* class names that are defined nowhere. `ss-empty-state` (line 62) occurs exactly once in the entire repository — this line — and appears in no file under public/css/. `ss-text-mute | _not yet verified_ |
| `finance-push-styles-never-renders` | high | layout | The whole <style> block is inside `@push('styles')`. layouts/app.blade.php declares only four stacks — @stack('css') at :328, @stack('scripts') at :610, @stack('custom-script') at :611, @stack('custom-script1') at :612. | _not yet verified_ |
| `finance-controls-have-no-labels` | high | a11y | Fifteen form controls across this area have a visible <label> that is not associated with them: no `for` on the label, no `id` on the control, and the control is not nested inside the label. Confirmed on the filter form | _not yet verified_ |
| `finance-text-danger-3-45-to-1` | high | contrast | .text-danger resolves to rgba(--bs-danger-rgb) = rgb(255,58,110) (public/assets/css/style.css:46/54), i.e. #FF3A6E, which is 3.45:1 on the white table. It carries the debit amount (:346), the discount (:406), a negative | _not yet verified_ |

### Already meeting the standard

- MONEY SCALE IS CORRECT EVERYWHERE, and I checked this specifically because the brief flagged it. Every money column these screens read is decimal(15,2): store_wallets.available_balance/pending_balance/withdrawal_pending_balance/total_income/total_withdrawn (mi
- RTL IS HANDLED BY MECHANISM, NOT BY MIRRORING, IN THE PARTS THAT ARE CONVERTED. public/css/store-syria-ds.css uses CSS logical properties throughout and states the rule in its own header ('There is no -rtl counterpart to this file and there must never be one')
- THE DARK-MODE MODAL CONTRAST DEFECT THE BRIEF NAMES IS ALREADY FIXED, so the four modals in this area are not affected. public/css/custom.css ~:4476-4483 does still end a long selector list with the bare `html[data-ss-theme="dark"] body label`, but P2.5-A-01 a
- THE THREE STOCK-REPORT ROW PARTIALS ARE GENUINELY PREMIUM and should be left alone. low/out/most_stock_status.blade.php all derive the badge from one shared rule (ProductDataTable::trackedStockState() via ReportController::withStockState:3201), so the three ta
- resources/views/setting/stock_setting.blade.php IS A CLEAN DESIGN-SYSTEM CONVERSION. ss-card/__header/__body/__footer, ss-field, ss-label, ss-input, ss-choice, x-ss.switch and ss-btn--primary throughout; every label has a matching for/id; the notification pair
- THE STOCK REPORT'S TAB RAIL IS CORRECT AND ALREADY MEASURED. stock_report.blade.php:137-154 drives both the `active` class and `aria-selected` from one $ssActive comparison per tab, so a tablist can no longer claim three selected tabs, and ss-tabrail scopes th
- resources/views/finance/unreconciled-captures.blade.php IS RIGHT ABOUT THE THINGS THAT ARE HARD. It has no action column at all, deliberately, and its header says why. It distinguishes 'the record is not installed on this environment, so an empty list is not e
- THE PERMISSION STORY ON THE FINANCE MUTATORS IS SOUND and I found nothing to add. All seven finance routes carry finance.permission:<name>, approve/reject/pay require BOTH names via a parameter list built from StoreFinanceController::WITHDRAWAL_REVIEW_PERMISSI

---

## Reports/Analytics presentation, Notifications, and the contextual-help / onboarding components (resources/views/reports/**, resources/views/notification/**, components/ss/{report-filter,help,field-help,setup-checklist,attention,attention-item,bar-chart}.blade.php)

**26 screens · 19 findings**

| screen | route | design system | quality |
|---|---|---|---|
| Customer Reports (reports hub) | `GET reports` | yes | needs-polish |
| Customer report body (AJAX fragment) | `GET reports-chart` | yes | premium-already |
| Sales Report (line) | `GET order-reports` | partial | deficient |
| Sales report body (AJAX fragment) | `GET order-reports-chart` | yes | premium-already |
| Sales Report (radial/bar variant) | `GET order-reports-data` | partial | deficient |
| Sales report bar body (AJAX fragment) | `GET order-reports-chart-data` | yes | premium-already |
| Stock Reports | `GET stock-reports (also registered as GET /stock-report, same action)` | partial | needs-polish |
| Stock report row cells (status + action) | `partial/component — rendered into GET stockReport.lowStock / outOfStock / mostStocked` | yes | premium-already |
| Sales by Product | `GET product-order-sale-reports` | partial | deficient |
| Sales by Category | `GET category-order-sale-reports` | partial | deficient |
| Sales Brand Report | `GET Brand-order-sale-reports` | partial | deficient |
| Brand sales body (AJAX fragment) | `GET brand-order-reports` | yes | needs-polish |
| Category / Product sales bodies (AJAX fragments) | `GET category-order-reports, GET product-order-reports` | yes | needs-polish |
| Sales Downloadable Product | `GET order-downloadable-reports` | partial | unknown |
| Top Sales Report | `GET top-all-reports` | yes | premium-already |
| Order Status Report | `GET Status-reports` | yes | premium-already |
| Top Selling By Country Report | `GET order-country-reports` | yes | needs-polish |
| Notifications (full list) | `GET merchant-notifications` | yes | premium-already |
| Notification bell feed (JSON) | `GET merchant-notifications/feed` | yes | premium-already |
| Mark read / mark all read | `POST merchant-notifications/{id}/read, POST merchant-notifications/read-all` | yes | needs-polish |
| Report period filter (shared component) | `partial/component` | yes | premium-already |
| Chart (shared component) | `partial/component` | yes | needs-polish |
| Contextual help (shared component) | `partial/component` | yes | needs-polish |
| Field help (shared component) | `partial/component` | yes | premium-already |
| Setup checklist (onboarding component) | `partial/component` | yes | premium-already |
| Needs-attention block (components) | `partial/component` | yes | premium-already |

### Blocker and high findings

| id | severity | category | what | verdict |
|---|---|---|---|---|
| `reports-ajax-no-loading-state` | high | state-missing | No AJAX report screen has any visible loading state. Every handler calls only $('#loader').fadeOut() — never fadeIn/show — and #loader is rendered with an inline style="display: none;" in resources/views/layouts/app.blad | _not yet verified_ |
| `reports-ajax-no-live-region` | high | a11y | Five of the six AJAX report screens replace their entire body with $('.chart_data').html(data.html) into a region with no live-region markup: <div class="chart_data pc-dt-export"> and nothing else. Only reports/index.bla | _not yet verified_ |
| `reports-export-no-failure-feedback` | high | feedback | All six CSV export handlers are $.ajax calls with a success: branch and NO error: handler (each file contains exactly one error: function, and it belongs to the chart fetch, not the export). The only handling of an unusa | _not yet verified_ |
| `reports-csv-header-untranslated` | high | other | Five export handlers build the CSV with a hardcoded English header row and no UTF-8 BOM, e.g. "data:text/csv;charset=utf-8,Duration,Brand Name,Total Net Sale\n". reports/index.blade.php:116-124 is the corrected exemplar: | _not yet verified_ |

### Already meeting the standard

- components/ss/bar-chart.blade.php is the strongest thing in this area and should be the model for the rest. The bars, legend and label row are aria-hidden and the accessible representation is a real <table> in a <details> — with a <caption> naming series and p
- Chart colour is not the only encoding and the pair was chosen deliberately. --ss-chart-series-1 points at --ss-brand (so it re-themes rather than drifting) and series 2 is the gold side of a teal/gold pair differing in both hue and lightness, with the dark the
- bar-chart distinguishes empty from zero, which is the distinction most dashboards lose. An unbuildable series renders x-ss.empty; a series of seven zeroes renders seven flat baselines, because 'no orders this week' is a real and different answer. The percentag
- Every figure carries its period, and the component enforces it. :period is a required prop on both x-ss.bar-chart and x-ss.stat with no default, so a series cannot be rendered without stating the window it covers — and the labels come from ReportController::ch
- The figures say what they actually measure. reports/chart_data.blade.php:27-30 titles the series 'Orders placed' with the note 'Counts every order placed in the period, whatever its status or value. Not revenue.'; _sales_stats.blade.php renders counts without
- components/ss/report-filter.blade.php replaced six hand-written copies of the period bar and fixed the same four defects once: a role="tablist" whose buttons were not tabs, a selection recorded only in a CSS class, <div>s as direct children of a <ul>, and an u
- notification/index.blade.php is a model for empty states. Three genuinely different causes get three different answers — no store selected, no readable notification type, and no notifications yet — each with its own icon and a sentence saying what to do. The c
- The notification feed's read/unread distinction does not rest on colour. An unread row gets a sunken background AND a brand-coloured inline-start bar AND a textual 'Unread' x-ss.status-badge, and the marker channel is a transparent border on read rows so marki

---

## Staff/Roles/Permissions + Merchant Settings & Integrations (resources/views/{users,roles,setting,profile,shipping*,taxes*,payment,email_templates,webhook,language,theme_customize,menu,delivery-syria,deliveryboy,shopify,woocommerce,pixel,support,newsletter,contact,page,blog*}/)

**38 screens · 27 findings**

| screen | route | design system | quality |
|---|---|---|---|
| Settings hub (26 AJAX tabs) | `GET /setting` | partial | deficient |
| Brand Settings tab | `partial/component (POST /business-settings)` | yes | premium-already |
| System Settings tab | `partial/component (POST /setting-form)` | yes | needs-polish |
| Storage Settings tab | `partial/component (POST /storage-settings)` | yes | premium-already |
| Currency Settings tab | `partial/component (POST /setting-form)` | yes | premium-already |
| Cache Settings tab | `partial/component (GET /config-cache to clear)` | yes | needs-polish |
| LYNOMIA OTP Settings tab | `partial/component (POST otp.integration.save)` | yes | needs-polish |
| Shopify Settings tab | `partial/component (POST /shopify-settings)` | yes | needs-polish |
| WooCommerce Settings tab | `partial/component (POST /woocommerce-settings)` | yes | needs-polish |
| Webhook Settings tab | `partial/component` | yes | needs-polish |
| Pixel Settings tab | `partial/component (resource pixel-setting)` | yes | needs-polish |
| Tax Option / Payment / reCAPTCHA / Cookie / SEO / Stock / Refund / PWA / Style / ChatGPT / Twilio / WhatsApp x3 / Email x3 / Loyalty tabs | `partial/component (POST /setting-form + per-panel save routes)` | yes | needs-polish |
| Delivery Syria (wallet + parcels) | `GET /setting (tab) and POST /delivery-syria/update` | partial | needs-polish |
| Users / staff list (card grid) | `GET /users` | partial | needs-polish |
| Users / staff list (table view) | `GET /user-list` | yes | premium-already |
| Create staff user (modal) | `GET /users/create -> POST /users` | yes | needs-polish |
| Edit staff user (modal) | `GET /users/{id}/edit -> PUT /users/{id}` | yes | needs-polish |
| Reset staff password (modal) | `POST users.resetpassword` | yes | premium-already |
| Staff detail / user info | `GET /user-info/{id}` | partial | needs-polish |
| Own profile (WorkDo) | `GET /profile` | yes | premium-already |
| Breeze profile (orphan) | `GET /profile/edit` | no | deficient |
| Roles list | `GET /roles` | yes | premium-already |
| Create role (xl modal, permission matrix) | `GET /roles/create -> POST /roles` | no | deficient |
| Edit role (xl modal, permission matrix) | `GET /roles/{id}/edit -> PUT /roles/{id}` | no | deficient |
| Shipping classes / zones / methods | `GET /shipping, /shipping-zone, /shipping-method (+ free-shipping and local-shipping edit)` | partial | needs-polish |
| Taxes and tax methods | `GET /taxes, /taxes/{id}, /taxes-method/create/{tax_option_id}` | partial | needs-polish |
| Webhooks (create/edit modals) | `GET /webhook/create, /webhook/{id}/edit -> POST\|PUT /webhook` | no | deficient |
| Delivery boys | `GET /deliveryboy, /deliveryboy-list, /deliveryboy/reset-password/{id}` | partial | needs-polish |
| Shopify import screens (product, category, sub_category, coupon, customer) | `GET /shopify_product, /shopify_category, /shopify_sub_category, /shopify_coupon, /shopify_customer` | no | deficient |
| WooCommerce import screens (product, category, coupon, customer) | `GET /woocom_product, /woocom_category, /woocom_coupon, /woocom_customer` | no | deficient |
| Pixel create/update (modals) | `GET /pixel-setting/create, /pixel-setting/{id}/edit` | no | deficient |
| Email templates | `GET /email_template_lang/{lang?} and /email_template_lang/{id}/{lang?}` | partial | needs-polish |
| Languages | `GET /languages (LanguageController) + GET /create-language` | partial | needs-polish |
| Menus | `GET /menus, /menus/{id}/edit` | partial | needs-polish |
| Support tickets | `GET /support_ticket, /ticket-view/{id}` | partial | needs-polish |
| Pages / Blog / Blog categories / Contact / Newsletter | `GET /pages, /blog, /blog-category, /contacts, /newsletter` | partial | needs-polish |
| Payment gateway checkout pages (plan purchase) | `various POST /plan-pay-with-*` | no | deficient |
| Theme customize / storefront visual editor | `GET /theme-customize (+ /customize-settings)` | partial | unknown |

### Blocker and high findings

| id | severity | category | what | verdict |
|---|---|---|---|---|
| `profile-edit-renders-empty` | blocker | layout | GET /profile/edit is a registered, authenticated route whose view is Breeze Tailwind markup inside <x-app-layout>. App\View\Components\AppLayout::render() returns view('layouts.app'), and resources/views/layouts/app.blad | _not yet verified_ |
| `settings-tabs-not-keyboard-reachable` | blocker | a11y | All 28 settings tabs are <a class="nav-link" ... type="button" role="tab"> with NO href attribute (verified: 28 nav-link occurrences, 0 with href). An <a> without href is not focusable, is not in the tab order, and has n | _not yet verified_ |
| `settings-aria-controls-dangling` | high | a11y | Every tab declares aria-controls pointing at an id that does not exist anywhere in the document — 26 distinct values (pills-home, pills-brand, pills-payment, ...) against a document whose only panel is id="pills-tabConte | _not yet verified_ |
| `settings-no-error-or-loading-state` | high | state-missing | getSettingForm() is a $.ajax with a success callback only — no error callback, no beforeSend, no loading state. SettingController@settingForm answers 403 {'msg':'Permission denied.','data':{'content':null}} (SettingContr | _not yet verified_ |
| `settings-search-labels-only` | high | form-ux | The settings search reads only item.querySelector('.nav-link').textContent — the 26 tab titles. None of the ~200 individual settings inside the panels are indexed (the panel bodies are not even in the DOM until their tab | _not yet verified_ |
| `area-wide-no-withinput-witherrors` | high | form-ux | Validation failures in this area are returned as redirect()->back()->with('error', $messages->first()). Measured across the area's 12 controllers: 256 redirect()->back() calls, 2 withInput(), 0 withErrors(). Consequences | _not yet verified_ |
| `form-label-case-mismatch` | high | a11y | Form::label('Module', ...) renders <label for="Module"> while the control is <select id="module">; same for 'Method'/'Url'. HTML id matching is case-sensitive, so the label names nothing. Verified real (not comment text) | _not yet verified_ |
| `icon-only-actions-unnamed` | high | a11y | Row actions here are <a class="btn btn-sm btn-info" data-bs-toggle="tooltip" title="..."><i class="ti ti-refresh"></i></a> — named only by title, with no text node, no aria-label and no aria-hidden on the icon. Bootstrap | _not yet verified_ |
| `shopify-import-no-empty-state` | high | state-missing | The import tables are a bare @foreach ($products as $product_data) with no @forelse/@empty. With no products — the normal state for a merchant who has just enabled Shopify, and also what a wrong token produces — the merc | _not yet verified_ |
| `woocommerce-physical-css-rtl` | high | rtl | The per-view <style> block uses physical properties inside the mobile breakpoint: `.ecom-data-table div.dataTables_wrapper div.dataTables_length { text-align: left; }` (line 32) and `.ecom-data-table .data-tab-btn { padd | _not yet verified_ |

### Already meeting the standard

- The whole of resources/views/setting/ is converted: 34 of 34 files carry .ss- classes. This is the only directory in the area with complete coverage, and it includes the panels a merchant touches most (brand, system, storage, currency, payment, SEO, stock, tax
- The design system is loaded globally now, not per screen — layouts/app.blade.php:225 and partision/head.blade.php:87 link store-syria-ds.css on every admin page, with the reasoning and the guard recorded in a long comment at app.blade.php:170-224 and asserted
- F126 and F126-c are already fixed, and fixed the right way. public/css/custom.css:4596-4620 names the SURFACE (.modal-content) rather than the element, lifting modal labels, .form-label, .col-form-label, .ss-label and h1-h6/.modal-title to --ss-grey-900 (16.06
- RTL/LTR parity of those dark-mode fixes is complete, which is the thing most likely to have been missed. layouts/app.blade.php:140-154 serves rtl-custom.css instead of custom.css for Arabic, and diffing every html[data-ss-theme...] selector between the two fil
- <x-ss.secret-field> (resources/views/components/ss/secret-field.blade.php) is a genuinely premium credential pattern and the best component in the area: it never emits the stored value, no length and no partial mask (the comment explains why a prefix leaks mor
- The payment-settings secret flow goes further than masking: setting/payment_partials/payment_gateway.blade.php branches on a per-field 'secret' flag from SettingController::paymentSettingArray(), and removal is a server-confirmed two-step through payment.secre
- resources/views/setting/currency_setting.blade.php is the model panel for the whole area: @forelse/@empty with <x-ss.empty> for the zero state, <x-ss.status-badge tone="success"> for the pinned USD row, <x-ss.switch> per row, a hidden 0 before each checkbox so
- resources/views/setting/brand_setting.blade.php is the model form: real @error blocks that set both aria-describedby and aria-invalid="true" and render <p class="ss-field__error" role="alert">, a keyboard-navigable radio group with visually-hidden names and @c

---

## Phase 2.5 Wave A — the shared component layer: public/css/store-syria-ds.css (3,804 lines), the 24 x-ss.* Blade components in resources/views/components/ss/, resources/views/components/datatable.blade.php, the F126 region of public/css/custom.css (lines ~4400-4720), and the two design-system docs

**30 screens · 13 findings**

| screen | route | design system | quality |
|---|---|---|---|
| Store Syria Design System stylesheet | `partial/component — linked globally at resources/views/layouts/app.blade.php:225 (?v=p02-7), behaviour at :234` | yes | premium-already |
| DS theme hook + opt-in partial | `partial/component` | yes | premium-already |
| x-ss.page-header | `partial/component` | yes | premium-already |
| x-ss.card | `partial/component` | yes | needs-polish |
| x-ss.stat | `partial/component` | yes | needs-polish |
| x-ss.stats | `partial/component` | yes | premium-already |
| x-ss.status-badge | `partial/component` | yes | premium-already |
| x-ss.empty | `partial/component` | yes | needs-polish |
| x-ss.panel (side sheet / drawer) | `partial/component` | yes | premium-already |
| x-ss.confirm-dialog | `partial/component` | yes | premium-already |
| x-ss.actionbar | `partial/component` | yes | premium-already |
| x-ss.breadcrumb | `partial/component` | yes | premium-already |
| x-ss.chip | `partial/component` | yes | premium-already |
| x-ss.switch | `partial/component` | yes | deficient |
| x-ss.progress | `partial/component` | yes | needs-polish |
| x-ss.help | `partial/component` | yes | premium-already |
| x-ss.field-help | `partial/component` | yes | premium-already |
| x-ss.error-summary | `partial/component` | yes | needs-polish |
| x-ss.filter-summary | `partial/component` | yes | premium-already |
| x-ss.report-filter | `partial/component` | yes | premium-already |
| x-ss.secret-field | `partial/component` | yes | premium-already |
| x-ss.setup-checklist | `partial/component` | yes | premium-already |
| x-ss.attention | `partial/component` | yes | premium-already |
| x-ss.attention-item | `partial/component` | yes | premium-already |
| x-ss.bar-chart | `partial/component` | yes | premium-already |
| x-ss.advanced-fields | `partial/component` | yes | premium-already |
| x-ss.choice-group | `partial/component` | yes | premium-already |
| x-datatable | `partial/component — used by 39 host views; 66 of 68 DataTables render through it` | partial | needs-polish |
| custom.css F126 region | `partial/component — public/css/custom.css, loaded on every admin page in both themes` | partial | premium-already |
| Legacy Breeze/Tailwind component set (co-resident) | `partial/component` | no | deficient |

### Blocker and high findings

| id | severity | category | what | verdict |
|---|---|---|---|---|
| `ds-switch-knob-dark-16` | high | dark-mode | The switch knob is `background: var(--ss-grey-0)` and the OFF track is `background: var(--ss-grey-400)` — both LAYER-1 primitives, which are declared once on :root and never re-themed. So in dark mode the knob stays pure | _not yet verified_ |
| `ds-no-field-component` | high | state-missing | The stylesheet defines a complete field contract — `.ss-field`, `.ss-label`, `.ss-label__required`, `.ss-input`, `.ss-select`, `.ss-textarea`, `.ss-field__hint`, `.ss-field__error`, and the invalid state keyed off `aria- | _not yet verified_ |
| `ds-aria-disabled-focus-ring` | high | a11y | `.ss-btn[disabled], .ss-btn[aria-disabled="true"] { opacity: 0.55; cursor: not-allowed; pointer-events: none; }` applies the same treatment to both. `opacity` composites the element's OUTLINE as well as its fill, and an | _not yet verified_ |
| `ds-loading-states-unused` | high | state-missing | The loading tier is built and essentially unadopted. `.ss-skeleton` has exactly two variants — `--text` (0.75rem) and `--line` (1rem) — and **0 occurrences in resources/views**. `.ss-avatar` is 0, `aria-busy` is 4. There | _not yet verified_ |
| `toast-errors-autodismiss` | high | feedback | `show_toastr()` calls `notifier.show(..., 4000)` on BOTH branches, so an error toast disappears after 4 seconds exactly like a success. 109 files call it. The accessibility half is done well — `ss_announce_transient()` a | _not yet verified_ |

### Already meeting the standard

- The physical-vs-logical discipline holds. I grepped the full 3,804 lines for `left`, `right`, `margin-left/right`, `padding-left/right`, `border-left/right`, `text-align: left/right`, `float` and the four physical corner radii: every hit is inside a comment ex
- The dark token set was genuinely re-solved, and the one pair I expected to catch it out does not. Every layer-2 token that needs a dark value has one: 35 tokens redefined under `[data-ss-theme="dark"]` (:297-340) and byte-identically under `[data-ss-theme="aut
- The ARIA-drives-appearance convention is real, not aspirational, and it is what makes most of these components premium. Invalid keys off `aria-invalid="true"` (:689), busy off `aria-busy="true"` (:614), selected tabs off `aria-selected`, chips off `aria-presse
- prefers-reduced-motion, forced-colors and print are each handled by enumerating selectors by name rather than by a blanket `* { animation: none }`, and the file documents why: the reduced-motion block (:2783-2806) keeps the panel's closed transform ('a panel t
- F126 is already correctly fixed, by the architecture the brief did not expect. P2.5-A-01 (custom.css:4612-4639) names the SURFACE — `.modal-content`, the one selector every Bootstrap modal in the host and all seventy vendored modules shares — rather than the e
- The spacing and type scales are real and are used. 11 spacing steps (0/4/8/12/16/20/24/32/40/48/64px) and 7 type steps (12/13/14/16/18/22/28px), each with its px equivalent in a comment, plus 5 radii, 4 shadows, 4 weights, 3 line-heights and 2 durations. Grepp
- The component docblocks are evidence, not description, and several encode a decision worth keeping. x-ss.attention deliberately has no empty state, because 'a permanent 0 items need attention panel teaches merchants to stop looking at this part of the screen'.

---

## Flutter merchant/employee admin app (StoreSyria_app98) — app shell, theme and design tokens: lib/config/ (theme_light/, repository/), lib/main.dart, lib/store_go.dart, lib/bindings/, lib/routes/, lib/view/widget/, lib/view/new_widgets/, plus lib/utils/colors.dart and lib/utils/text_style.dart which are where the real tokens live.

**24 screens · 20 findings**

| screen | route | design system | quality |
|---|---|---|---|
| App root / shell | `n/a — widget root, wraps every route` | partial | needs-polish |
| Theme definition (the only ThemeData in the app) | `partial/component` | partial | needs-polish |
| Design tokens (colour) | `partial/component` | no | deficient |
| Design tokens (typography) | `partial/component` | no | deficient |
| Bottom-nav shell (DashBoardManagerScreen) | `/dashboard (GetX named route)` | no | needs-polish |
| Nav destination 1 — Home/dashboard | `/dashboard tab 0` | no | unknown |
| Nav destination 2 — Orders | `/dashboard tab 1` | no | unknown |
| Nav destination 3 — Products | `/dashboard tab 2` | no | unknown |
| Nav destination 4 — Categories | `/dashboard tab 3` | no | needs-polish |
| Nav destination 5 — More/Settings | `/dashboard tab 4` | no | premium-already |
| Shared app bar (simpleAppBar) | `partial/component — 28 live screens` | no | deficient |
| Shared text field (AppTextField) | `partial/component — 45 live call sites` | partial | needs-polish |
| Shared dropdown (commonDropdownButtonWidget) | `partial/component — 2 call sites (add_shipping, add_tax bottom sheets)` | partial | deficient |
| Shared buttons (CommonButton family) | `partial/component — 2 live CommonButton + 11 live CommonIconButton sites` | no | deficient |
| Shared button (AppButton, inside app_dialog.dart) | `partial/component — used by AppDialog` | partial | needs-polish |
| Shared confirmation dialog (AppDialog) | `partial/component — 12 live call sites` | partial | needs-polish |
| Shared action feedback (AppSnackBar) | `partial/component` | partial | needs-polish |
| Shared empty / error / stale states | `partial/component — EmptyState and ErrorState each adopted in 18 files, StaleDataNotice in 4` | partial | needs-polish |
| Shared skeletons (AppShimmer) | `partial/component — adopted in 22 files` | partial | premium-already |
| Shared status indicators | `partial/component — statusPill 5 live sites; StatusBadgeHelper used by payment_status_bottom_sheet` | no | needs-polish |
| Theme switch animation | `partial/component — wraps the whole app via GetMaterialApp builder` | n/a | needs-polish |
| Loading indicator (Loader / LoadingWidget) | `partial/component — Loader.showLoader() live at 4 sites` | n/a | deficient |
| Routing table | `partial/component` | n/a | needs-polish |
| Bindings / DI | `partial/component` | n/a | needs-polish |

### Blocker and high findings

| id | severity | category | what | verdict |
|---|---|---|---|---|
| `flutter-emptystate-light-contrast` | blocker | contrast | EmptyState paints both its 72r icon and its 16.sp message in `Colors.grey.shade400` (#BDBDBD) whenever the theme is light. Measured against the light scaffold (`AppColor.cBackGround` = #FFFFFF in light mode, set at theme | _not yet verified_ |
| `flutter-field-focus-ring-invisible-dark` | blocker | dark-mode | `static const Color navyBlue = Color(0xFF172C4E)` is hardcoded and used unconditionally for BOTH `cursorColor:` (line 167) and `focusedBorder: border(navyBlue, 1.5)` (line 209) — in both themes. In dark mode the field's | _not yet verified_ |
| `flutter-statuspill-tint-contrast` | high | contrast | statusPill draws its label in the same colour as its background tint: foreground `color` (#00B894 active / #FF5C5C inactive) on `color.withValues(alpha: .09)`. Over a white card that composites to #E8F9F5 and #FFF0F0, gi | _not yet verified_ |
| `flutter-dropdown-hint-white-on-white` | high | contrast | `final hintColor = isDark ? Colors.grey.shade500 : AppColor.cHintFont;` — and `AppColor.cHintFont` is `Color(0xffFFFFFF)` (lib/utils/colors.dart:27). It is one of the five AppColor fields that NEITHER AppLightTheme.light | _not yet verified_ |
| `flutter-no-single-source-of-design-truth` | high | consistency | There is no single source of design truth. Four parallel systems coexist, measured: (1) theme_controller.dart:21 builds a genuinely thorough per-brightness ThemeData — and the app's own widgets almost never read it: of 7 | _not yet verified_ |
| `flutter-nav-labels-stale-after-language-switch` | high | other | `RxList naviBarItemList = [{'icon': …, 'title': 'Home'.tr}, …]` is an INSTANCE FIELD INITIALISER, so `.tr` is resolved exactly once — when `Get.put(DashboardManagerController())` runs in DashboardBinding at the first /da | _not yet verified_ |
| `flutter-touch-targets-below-44` | high | a11y | The shared app bar's back affordance is a `Container(height: 30.h, width: 40.w)` wrapped in a bare GestureDetector — a 30x40 tap target, with no padding outside it to enlarge the hit area. AppDialog's cancel and confirm | _not yet verified_ |
| `flutter-brand-is-not-the-store-syria-teal` | high | consistency | The authoritative Store Syria brand is teal at hue 172 — public/css/store-syria-ds.css:73-83 declares the ramp with provenance ('Brand — hue 172°, inside the Store Syria logo's 168-197° gradient'), `--ss-teal-600 #0bb19b | _not yet verified_ |

### Already meeting the standard

- RTL discipline in the Flutter widget tree is genuinely strong, and measured rather than assumed. A multi-line scan of every `EdgeInsets.only(…)` carrying left/right found 10 instances and exactly ONE asymmetric (order_detail_screen.dart:1703) — every other phy
- The root localization wiring is correct and carries its own reasoning. store_go.dart:48-65 and translations/translation_service.dart:16-35 both document why `localizationsDelegates` and `supportedLocales` must be passed: without them MaterialApp falls back to
- order_status_badge.dart is the best-engineered component in the app and the model the rest should follow. Every colour pair is measured against its own tint rather than against white, with the three original failures named in the comment (status 0 Pending #EF6
- The empty/error/stale state layer is real, broadly adopted and semantically announced. EmptyState and ErrorState are each used in 18 files and StaleDataNotice in 4, and all three wrap their content in `Semantics(liveRegion: true, container: true)` so that swap
- The skeleton system is premium already. shimmer.dart is a 668-line, theme-aware library: `ShimmerColors.of(context)` per brightness, then ten preset shapes (ShimmerBox, Line, Circle, Card, ListTile, List, Grid, Details, Dashboard, Table) so a loading screen ca
- Icon-only control naming is complete, which is unusual. All 14 Material `IconButton` sites in lib/ carry an accessible name — every one has `tooltip:`, several with the comment 'Accessible name: this control has no visible text' (error_empty_state.dart:164, ap
- AppSnackBar is well-constructed apart from the missing announcement: a 44x44 close target with an explicit `constraints: BoxConstraints(minWidth: 44, minHeight: 44)`, a fully per-theme palette (surface, border, title, message and close each branching on bright
- AppDialog is a real confirmation component, not a decorated alert. Twelve live call sites share one implementation with an actual state machine: the confirm button awaits `onConfirm()`, shows `isLoading` while it runs, pops only on success when `popOnConfirm`,

---

## Flutter merchant admin app (StoreSyria_app98) — every merchant-facing screen under lib/view/screen/, the shared widgets under lib/view/widget/ and lib/view/new_widgets/, the controllers behind them under lib/core/controller/, the theme in lib/config/theme_light/ + lib/utils/colors.dart, and the whole of test/

**51 screens · 32 findings**

| screen | route | design system | quality |
|---|---|---|---|
| Splash / Welcome | `GetX /splash (initialRoute)` | n/a | premium-already |
| Onboarding | `GetX /onboarding` | n/a | needs-polish |
| Login | `GetX /login` | n/a | needs-polish |
| Dashboard shell (bottom navigation) | `GetX /dashboard` | n/a | needs-polish |
| Home / Overview (tab 0) | `tab 0 of /dashboard` | n/a | premium-already |
| Home widgets (stat tiles, QR, profile row) | `partial/component` | n/a | premium-already |
| Orders list (tab 1) | `tab 1 of /dashboard` | n/a | premium-already |
| Order detail | `Get.to(() => OrderDetailScreen()) from orders, notifications, customer detail (6 sites)` | n/a | needs-polish |
| Create order | `GetX /addOrder, and Get.to(() => CreateOrderScreen()) from orders` | n/a | needs-polish |
| Invoice (PDF build) / Invoice viewer | `Get.to(() => InvoicePdfViewScreen()) from order detail` | n/a | premium-already |
| Products list (tab 2) | `tab 2 of /dashboard` | n/a | premium-already |
| Product detail | `Get.to(() => ProductDetailScreen()) from products list` | n/a | needs-polish |
| Add / edit product | `Get.to(() => AddNewProductScreen(isUpdate:)) from products list and product detail` | n/a | needs-polish |
| Add rating / review | `Get.to(() => AddRatingScreen()) from product detail` | n/a | needs-polish |
| Product card / field partials | `partial/component` | n/a | needs-polish |
| Categories (tab 3) | `tab 3 of /dashboard` | n/a | premium-already |
| Add / edit category (modal) | `showModalBottomSheet from categories` | n/a | needs-polish |
| More / Settings grid (tab 4) | `tab 4 of /dashboard` | n/a | premium-already |
| App settings | `GetX /appSettings` | n/a | needs-polish |
| Store settings grid | `Get.to(() => StoreSettingScreen()) from the More grid` | n/a | deficient |
| Store information | `GetX /storeInfo` | n/a | deficient |
| Store language & currencies | `GetX /lang` | n/a | needs-polish |
| Store email settings | `Get.to(() => EmailSettingScreen()) from store settings` | n/a | deficient |
| Loyalty programme | `Get.to(() => LoyaltyProgramScreen()) from store settings` | n/a | deficient |
| Store payment methods | `Get.to(() => PaymentSettingScreen()) from store settings` | n/a | deficient |
| COD / Stripe / Bank-transfer gateway editors | `unreachable — no Get.to, no AppRoutes entry anywhere in lib/` | n/a | deficient |
| Tax | `GetX /tax` | n/a | needs-polish |
| Add / edit tax (modal) | `showModalBottomSheet from tax` | n/a | deficient |
| Shipping | `GetX /shipping` | n/a | deficient |
| Add / edit shipping (modal) | `showModalBottomSheet from shipping` | n/a | needs-polish |
| Customers list | `GetX /customers` | n/a | premium-already |
| Customer detail | `Get.to(() => CustomerDetailsScreen()) from customers list` | n/a | needs-polish |
| Add / edit customer (modal) | `showModalBottomSheet from customers list` | n/a | needs-polish |
| Delivery men (staff) list | `GetX /delivery_boys` | n/a | premium-already |
| Coupons list | `GetX /coupon` | n/a | needs-polish |
| Coupon detail | `Get.to(() => CouponDetailScreen()) from coupons list` | n/a | premium-already |
| Notifications | `GetX /notifications, and Get.to(() => NotificationScreen()) from the dashboard bell` | n/a | needs-polish |
| Analytics / statistics | `GetX /statistics` | n/a | needs-polish |
| Statistics widgets | `partial/component` | n/a | needs-polish |
| Wallet | `GetX /wallet` | n/a | needs-polish |
| My plan | `GetX /myPlan` | n/a | needs-polish |
| Profile / My account | `Get.to(() => ProfileScreen()) from the dashboard profile row` | n/a | deficient |
| Shared app bar | `partial/component` | n/a | deficient |
| Shared empty / error / stale states | `partial/component` | n/a | needs-polish |
| Shared skeletons | `partial/component` | n/a | needs-polish |
| Shared text field | `partial/component` | n/a | deficient |
| Shared toast (live) | `partial/component` | n/a | needs-polish |
| Shared toast (duplicate class) | `partial/component` | n/a | deficient |
| Shared confirmation dialog | `partial/component` | n/a | needs-polish |
| Shared status badge / pill | `partial/component` | n/a | needs-polish |
| Shared buttons / loader / search / filter / popup menu | `partial/component` | n/a | needs-polish |

### Blocker and high findings

| id | severity | category | what | verdict |
|---|---|---|---|---|
| `empty-state-message-contrast` | blocker | contrast | EmptyState's message text is `isDark ? Colors.white70 : Colors.grey.shade400`. In light mode that is #BDBDBD at 16.sp. Measured against the two scaffold backgrounds these screens actually use — #FFFFFF (AppColor.cBackGro | _not yet verified_ |
| `three-screens-untranslated-english` | blocker | other | 81 user-facing strings are hardcoded English literals with no `.tr` at all. Three whole merchant screens are affected end-to-end: Store Information (34 strings, including the app-bar title, every field label and hint, an | _not yet verified_ |
| `field-focus-and-caret-invisible-dark` | blocker | dark-mode | AppTextField hardcodes `static const Color navyBlue = Color(0xFF172C4E)` and uses it for both `cursorColor: navyBlue` and `focusedBorder: border(navyBlue, 1.5)` in both themes. In dark mode the field's own fill is #18263 | _not yet verified_ |
| `field-label-not-programmatically-associated` | high | a11y | The visible label is rendered as a sibling `Text(title!)` in the enclosing Column, while the TextFormField receives only `hintText`. There is no `labelText`, no `InputDecoration.label`, no `MergeSemantics` and no `Semant | _not yet verified_ |
| `shipping-screen-has-no-states` | high | state-missing | Shipping is a paginated collection screen — infinite scroll on a ScrollController, Slidable edit/delete rows, a server-backed list — but it renders a bare `Obx(() => Expanded(child: ListView.builder(itemCount: shippingCo | _not yet verified_ |
| `appbar-back-control-tiny-and-unnamed` | high | a11y | simpleAppBar's back control is a bare `GestureDetector` wrapping a `Container(height: 30.h, width: 40.w)` with an `Icon(Icons.arrow_back)` inside. It has no tooltip, no Semantics, no InkWell and no button role. | _not yet verified_ |
| `appbar-overflows-on-tall-devices` | high | responsive | simpleAppBar declares `preferredSize: const Size.fromHeight(70)` — a fixed 70dp — but pads its content with `top: Get.height * 0.05`, a proportion of screen height, and the back control inside is `30.h`, which flutter_sc | _not yet verified_ |
| `toast-never-announced` | high | a11y | The app's toast is a hand-rolled `OverlayEntry`, and the file contains zero occurrences of `Semantics`, `liveRegion` or any announcement call — verified by grep over the whole file. | _not yet verified_ |
| `duplicate-snackbar-with-prefix-palette` | high | contrast | There are two classes named `AppSnackBar`. The live one (lib/view/widget/common_snak_bar_widget.dart) carries the per-theme semantic palette installed for ledger F277 and is guarded by test/a11y/toast_type_contrast_test. | _not yet verified_ |
| `customer-contact-shows-raw-keys` | high | feedback | `AppSnackBar.info(title: 'error', message: 'could_not_open')` — both arguments are bare translation keys with no `.tr`. | _not yet verified_ |
| `status-pill-contrast-and-size` | high | contrast | `statusPill` paints its label in #00B894 (active) or #FF5C5C (inactive) on a 9%-alpha fill of the same colour, at 9.sp, and reads no theme at all. Over the light surfaces it is used on, that is 2.33:1 for active and 2.74 | _not yet verified_ |
| `loader-show-is-a-no-op` | high | state-missing | `static showLoader() { const LoadingWidget(); }` — it constructs a widget as a bare expression statement and discards it. Nothing is ever inserted into the tree, so the call shows no loading indicator whatsoever. `hideLo | _not yet verified_ |
| `login-grey-text-contrast` | high | contrast | The login card's sub-heading ("please login to continue to your store!", 15.sp) and the social divider ("or continue with", 13.sp) are both `color: Colors.grey` — #9E9E9E. On the card, which is `Colors.white` in light mo | _not yet verified_ |
| `bottom-nav-inactive-label-contrast` | high | contrast | `navInactiveColor = isDark ? Colors.white54 : AppColor.cDarkGreyFont` — #717887 in light — is used for the four unselected bottom-bar labels and icons, against the bar's #172C4E navy background. Measured: 3.15:1, at 10.s | _not yet verified_ |

### Already meeting the standard

- Three-state handling is genuinely adopted, not aspirational. 15 of the 16 collection screens render a purpose-built skeleton, an ErrorState with a working onRetry and an EmptyState, in that order — error checked before empty, with the reason written down (a fa
- StaleDataNotice closes the state gap most apps leave open. ErrorState answers 'the failure left nothing to show'; StaleDataNotice (error_empty_state.dart) answers the other half — the refresh failed and the previous rows are still on screen. It is an inline, n
- The skeleton system is the best-built part of the app. 668 lines, per-theme base/highlight colours resolved from context, ten composable shapes (Box, Line, Circle, Card, ListTile, List, Grid, Details, Dashboard, Table), AlignmentDirectional throughout, and — u
- The order and payment status badge palette has had a real contrast pass. All nine values of orders.delivered_status are handled, every background/text pair was measured, and the three that failed were corrected with the hue preserved and only the text darkened
- The live toast palette is per-theme and measured. common_snak_bar_widget.dart documents why one colour per type could never work — the icon sits on a 10% tint of itself, so a mid-tone is too close to its own tint over white and over a dark surface in opposite
- State changes are announced where the work was done. EmptyState, ErrorState and StaleDataNotice each carry Semantics(liveRegion: true, container: true), placed on the shared widget rather than at sixty call sites, with the duplicate-announcement trap documente
- RTL is correctly wired at the root and ratcheted at the leaves. store_go.dart passes localizationsDelegates and supportedLocales with a long comment on why their absence forced TextDirection.ltr for the whole app through DefaultWidgetsLocalizations; test/local
- The invoice PDF handles direction explicitly, and has to. invoice.dart branches on isArabic for pw.TextAlign and pw.Alignment at lines 338, 488, 499, 530, 557–567 and 587, because the pdf package's pw.* widgets inherit no ambient Directionality from the Flutte

