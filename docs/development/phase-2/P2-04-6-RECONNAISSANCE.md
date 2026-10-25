# P2-04-6 — order detail page: what the reconnaissance found

**Read-only.** Nothing in this file has been changed in the code yet; it is the measurement that
the slice starts from. Everything below is either read from a named file and line, or measured in
the running application and shown with its number. Where something was reasoned rather than
driven, it says so.

Read-only pass over `resources/views/order/view.blade.php` (791 lines),
`OrderController::order_view()`, `Order::order_detail()` and `OrderNoteController`.

## Blocker status
CLEARED. `OrderDocumentAccessGuard` is on the `web` group and lists `order-view/{id}` in
TARGETS; `OrderDocumentAccessTest` passes 49/77 with that uri among the cross-tenant data sets.
NUANCE: the guard keys on `$route->uri()`, so any NEW route reaching `order_detail()` needs its
own TARGETS entry plus a case in that test. Restyling in place needs neither.

## Correctness defects found (fix before/with the restyle)

1. **Every order note is attributed to whoever is LOOKING at the page.**
   `{{ $note->created_at->format(...) }}{{ __(' by ') }}{{ Auth::user()->name }}` — five times.
   `order_notes` has NO author column (only `customer_id`, a FK to customers). So there is no
   honest name to show: drop the attribution, keep the timestamp. Adding `created_by` is a
   migration and therefore an escalation, not a drive-by.

2. **The items table can render fewer cells than its header.**
   Header adds `<th>Downloadable Product</th>` when `order_status == 1`. The body adds the
   matching `<td>` only when `($product || $variant)` AND status == 1. A deleted product makes
   both null → 3 cells against 4 headers → Total shifts under the wrong column.

3. **N+1 inside the items loop, on an UNSCOPED model.** `ProductVariant::where(...)->first()` and
   `Product::where('id', $item['product_id'])->first()` per item. `Product` carries no StoreScope
   and is absent from `scoped_models`, so a tampered `product_json` resolves a foreign product —
   and the only thing read from it is `downloadable_product`, which becomes a download link.
   Add the store predicate and hoist the lookups out of the loop.

4. **`<a href="#">{{ $item['name'] }}</a>`** — a link to nowhere on every line item.

5. **Four `target="_blank"` with no `rel="noopener noreferrer"`** (WhatsApp links, receipt,
   shipping label). Zero `rel="noopener"` in the file.

## Design-system work
- Bootstrap `card` → `x-ss.card`; hard-coded `#E8F8FF` / `#002332` / `rgba(255,174,189,.41)`
  inline styles → tokens.
- The status control uses `btn-{info,danger,success,warning,secondary,dark}` — the same family
  measured at 1.03–2.59:1 in P2-04-4. Use `ss-badge` for the state + a DS menu for the actions.
- The five near-identical 20-line note blocks differ only by one CSS class → one loop + a map.
- The totals `<ul>` → a DS description list or table, right-aligned, RTL-safe.

## JS hooks that MUST survive
`.order_status` (click), `.order_status_btn` (its text is replaced), `#payment_status` (change),
`#delivery_boy` (change), `#printableArea` (html2pdf), `.order_return`, `.download-btn`,
`.show_confirm`, `.order-not-dlt`.

## Order of work
1. The five correctness defects + tests.
2. The DS restyle of the page shell.
3. Screenshots (4 configs x desktop/mobile) + a live check keyed on the cells, not the cards.

## The five note tints, all hard-coded and none dark-aware
`public/css/custom.css:2012-2031` (and the rtl twin):
```
stock-mange-note    #b1b1b194
order-create-note   #3ec9d652
order-status-note   #E9FFDF
admin-private-note  rgba(255,174,189,0.31)
customer-note       rgba(244,180,26,0.15)
```
All five are LIGHT fills with no dark-mode variant, under `style-dark.css`'s
`p { color: #808191 !important }` — the same rule F8 and F37 both met. Measure these in dark mode
before restyling: the expectation is a light panel with grey text on it.

## Measured on the running page (not assumed)

```
ltr/dark   status button   1.09:1   fg #1C232F on #272A32   <- invisible
ltr/dark   grand total     1.10:1   fg #293240 on #292A33   <- the MONEY figure, invisible
ltr/light  item name link  2.59:1   brand green on white    <- F9 again, a third surface
ltr/light  shipping h5     4.69:1   borderline
```

The status button is the `btn-{info,danger,success,warning,secondary,dark}` family P2-04-4 just
removed from the list; it is still here. `--ss-brand-ontext` (F9's own mechanism) is the fix for
the item link, exactly as it was for the breadcrumb.

**The note tints are NOW measured, and it took three fixture fixes to get there** — which is
itself the finding. First `order_billing_details`, `order_notes`, `countries`, `states` and
`cities` were all EMPTY. Then the five notes were seeded on the oldest order while the check
opened the newest, so the panel was still empty and the run still said `notes=0`. Then, with 35
notes in the table, the panel STILL did not render: it is gated on `Manage Order Note`, and the
fixture merchant's role did not hold it (`isAbleTo('Manage Order Note')` false, `Show Order`
true — which is why the page opened at all). All three are seeded now.

```
ltr/dark   customer-note       1.24:1   fg #293240 on composited #473F2F
ltr/dark   order-create-note   1.77:1   fg #293240 on composited #305D67
ltr/dark   admin-private-note  1.86:1   fg #293240 on composited #6B535E
ltr/dark   stock-mange-note    2.95:1   fg #293240 on composited #78787C
ltr/dark   order-status-note  12.21:1   the one opaque tint, and the only one that passes
ltr/light  all five            8.53 – 12.21:1
```

**Four of the five are below AA in dark mode and the fifth passes by accident** — it is the only
tint written as an opaque hex (`#E9FFDF`); the other four are `rgba()` over a dark surface, so
they composite to mid-greys while the text stays `#293240`.

**And the false attribution is visible in the rendered text:**
`"25 Oct 2026 07:06 PMبواسطةAmina Haddad"` — the name of the account doing the looking. The
missing space around `بواسطة` is a second, smaller thing: the key is `__(' by ')` with spaces,
and the Arabic value has none.

**`$0,00` as the grand total on the detail page is the KNOWN fixture artifact**, already recorded
in `database/seed-p2dev.php`: `order_detail()` recomputes the totals from `product_json`, and the
seed's `final_price` does not reach them. Not a product defect; do not "fix" it in the view.

## And one string that is not translated at all
`OrderController.php:535` — `->prepend('Assign to delivery boy', "")`. A bare PHP string, no
`__()`, no key in `ar.json`. It renders in ENGLISH on the Arabic order detail page, which the
dark screenshot shows plainly. One-line fix plus one key in each language file.

## What the dark screenshot shows beyond the numbers
- The two section icons sit on hard-coded light tints (`#E8F8FF`, `rgba(255,174,189,.41)`) that
  read as bright patches on a dark page.
- Shipping and billing values are empty — the dev fixture writes no `delivery_informations`.
  Seed them, or this page gets reviewed with every field blank.
- Line-item names are brand-green links to `#`.

## Confirmed by reading, not assumed
- `order_notes` has exactly ONE migration (`2024_01_26_100237_create_order_notes_table.php`) and
  no author column anywhere — not in a later migration, not in `_gate_f_pending/`, not in any
  module's migrations. So "drop the attribution" is the only honest option without a schema
  change.
- `OrderController::mayUseOrderScreen()` puts the operator limb FIRST, and
  `OrderDocumentAccessGuard` admits the operator, so the operator really does reach this page —
  which is why the item lookups must scope to the ORDER's store, not the caller's.
- `OrderReadFamilyTest` already drives `order_view` end to end with victim billing PII. P2-04-6's
  correctness tests belong in a new `tests/Feature/Merchant/OrderDetailTest.php`, not there.

## SEED FIRST — three blocks on this page have never been seen with content

Measured against `database/seed-p2dev.php`:

| block | seeded? | consequence |
|---|---|---|
| line items | YES (`product_json`, :186) | the items table renders, as the screenshots show |
| shipping / billing information | **NO** | both address cards render every field blank |
| order notes (all five kinds) | **NO** (`OrderNote` appears 0 times) | the whole notes panel is empty |

This is P2-02-9's lesson on a second page: a block that renders its empty state looks healthy.
Seed one order with full delivery/billing details and one note of EACH of the five kinds BEFORE
restyling any of them, or the restyle will be reviewed against blank panels and shipped without
anyone having seen it populated.

## `text-white` forced onto four icons, and it defeats the brand-text fix
- `view.blade.php:323` — the downloadable-product button's icon is `ti ti-download text-white`
  inside a `btn-primary`. `--ss-onbrand` sets the BUTTON's colour; `text-white` overrides it on
  the icon, so that one icon stays white on the merchant's brand (2.59:1 on the default) while
  every other `btn-primary` on the page is now correct. Drop the `text-white`.
- `:507`, `:530`, `:553` — the note delete icons are `ti ti-trash text-white py-1`, on LIGHT note
  tints, i.e. white on `#E9FFDF` / `rgba(255,174,189,.31)` / `rgba(244,180,26,.15)`. The other
  two note blocks' trash icons have no `text-white` at all. That asymmetry is the five-way
  copy-paste showing: three branches were edited and two were not.

## The "copy link" button (view.blade.php:35-39) — four things, one of them production-only

1. **`env('APP_URL')` in a Blade view.** `env()` returns **null** once `php artisan config:cache`
   has run, which is the normal production posture. So on a cached deployment the copied link is
   `/<slug>/order/<cipher>` with **no host** — a broken link, and broken only in production,
   which is why nobody has seen it. `config('app.url')` is the correct call. **29 `env()` calls
   exist across host Blade views**; this slice fixes the one on this page and the rest wants its
   own sweep (key it on the handler, not on this file).
2. **A full URL stored in an `id` attribute**, read back by `copyToClipboard(element)` as
   `element.id`. Invalid HTML and the wrong attribute; `data-ss-copy` with `dataset.ssCopy`.
3. **`title="Copy Link"` is a bare English string** — no `__()`, no key. The Arabic page shows it
   in English, like `'Assign to delivery boy'`.
4. `document.execCommand('copy')` is deprecated; `navigator.clipboard.writeText()` with the
   existing path as the fallback.

### The `env()`-in-views sweep, bounded
29 calls across host Blade views, and only three distinct variables:
```
12  env('IS_MOBILE')     — a feature switch; null under config:cache means "always desktop"
11  env('APP_URL')       — every one of these produces a HOST-LESS url in production
 4  env('APP_NAME')      — a blank application name in titles and mail subjects
```
All three fail the same way and only in production (`config:cache`), which is why none has been
noticed. `config('app.url')`, `config('app.name')` and a config entry for the mobile switch are
the fixes. Small, mechanical, and NOT part of P2-04-6 — it crosses many screens, so it is its own
slice with a census keyed on the call, not on a file list.

---

# P2-04-6 step 1 — the items table

## Defect A: the row can have fewer cells than the header
Header (view.blade.php:270-276):
```
<th>Item</th><th>Quantity</th>
@if ($order['order_status'] == 1) <th>Downloadable Product</th> @endif
<th>Total</th>
```
Body:
```
<td>name</td><td>qty</td>
@if ($product || $variant)            <-- BOTH null for a deleted product
  @if ($order['order_status'] == 1)
     ... <td>button</td> or <td>-</td>
  @endif
@endif
<td>total</td>
```
So a delivered order containing a product that has since been deleted renders 3 cells against 4
headers and Total slides under "Downloadable Product".

FIX: make the `@if` on the CELL identical to the one on the HEADER, and put the
`$product || $variant` test inside it:
```
@if ($order['order_status'] == 1)
    <td>
        @if (($product || $variant) && (!empty($variant->downloadable_product) || !empty($product->downloadable_product)))
            ... the download button ...
        @else
            -
        @endif
    </td>
@endif
```
One condition decides both, so they cannot drift.

## Defect B: N+1 on an unscoped model, inside the loop
```
$variant = ProductVariant::where('id', $item['variant_id'])->first();
$product = Product::where('id', $item['product_id'])->first();
```
Two queries per line item, and `Product` registers no StoreScope and is absent from
`config/tenancy.php`'s `scoped_models`, so this reaches every tenant's catalogue. The only field
read is `downloadable_product`, which becomes a download link. `product_json` is written at
checkout from the cart, and S19 established that a cart CAN carry a foreign product id.

FIX: hoist both lookups above the loop, keyed by id, and add the store predicate:
```
@php
    $ssProductIds = collect($order['product'])->pluck('product_id')->filter()->unique();
    $ssVariantIds = collect($order['product'])->pluck('variant_id')->filter()->unique();
    $ssProducts = \App\Models\Product::whereIn('id', $ssProductIds)
        ->where('store_id', $order['store_id'] ?? getCurrentStore())->get()->keyBy('id');
    $ssVariants = \App\Models\ProductVariant::whereIn('id', $ssVariantIds)->get()->keyBy('id');
@endphp
```
CHECK FIRST: does `ProductVariant` carry a store column? If not, scope it through its product.
CHECK FIRST: is `$order['store_id']` present in the `order_detail()` array? If not use the order
row. Do NOT use `getCurrentStore()` alone for the operator, who legitimately views any store.

## Defect C: `<a href="#">` on every line item name
Either link to `product.edit` (gated on the product permission, and only when the product still
exists and belongs to this store) or render plain text. Plain text is the honest default.

## Answers to the two "CHECK FIRST" questions

- **`ProductVariant` carries `store_id`** (`app/Models/ProductVariant.php:19`), so it can take the
  same predicate as `Product`. Neither model is in `scoped_models`, so BOTH need it explicitly.
- **`order_detail()` does NOT return `store_id`** — `$order_array` has `id`, `order_id`,
  `order_status`, `product`, … but no store (`app/Models/Order.php:334-399`). Add
  `$order_array['store_id'] = $order->store_id;` there. It is a read-only presentation array and
  the view needs the ORDER's store, not the caller's.
- **Do NOT use the controller's `$store`.** `OrderController::order_view()` sets
  `$store = getStoreById(getCurrentStore())` — the CALLER's store. For a merchant they coincide;
  for the platform operator, who legitimately opens another store's order, they do not, and
  scoping the product lookup to the caller's store would blank the line items for exactly the
  principal the guard admits.
- **The operator really can reach this page**, so the point above is not theoretical:
  `OrderController::mayUseOrderScreen()` (`:523`) is `UserPolicy::isOperator($user) ||
  $user->isAbleTo($permission)`, operator first, and `OrderDocumentAccessGuard` admits the
  operator too.

---

# P2-04-6 — the order status control

## What it is now
A Bootstrap `btn-group` whose button carries one of
`btn-{info,danger,success,warning,secondary,dark}` chosen by an inline `@php` chain
(view.blade.php:40-53), plus a `dropdown-menu` of `<a class="dropdown-item order_status">`
entries whose visibility is decided by five overlapping `@if (in_array($order['order_status'], …))`
tests.

Measured on the running page: **1.09:1 in dark mode**. It is the same vendored family P2-04-4
removed from the list, still here.

## What it should be
- The STATE is a label → `x-ss.status-badge` with `OrderStatusTone::for()`, which is already the
  shared answer and already measured at AA on both themes.
- The ACTIONS are a menu → the DS's own menu, or the existing dropdown markup restyled, keeping
  `.order_status` and `data-value` exactly.

## The coupling that must not break
`order/view.blade.php`'s own script does, on success:
```
$('.order_status_btn').text('{{ __('Status') }} : ' + newStatusText);
```
It REPLACES the button's entire text content. If the state becomes a badge with a dot `<span>`
inside it, `.text(...)` wipes the dot and leaves a bare string in a badge-shaped box.

So either:
  (a) keep a dedicated `.order_status_btn` text node separate from the badge, and point the
      script at that; or
  (b) change the script to rewrite only the label span, and give that span its own class.

(b) is better and is a two-line change in the same file. Whichever is chosen, the AJAX success
path must be driven in a browser afterwards — this is exactly the kind of coupling that a
server-rendered test cannot see, because the defect only appears after the XHR returns.

## The five visibility tests
`0,8 -> confirmed`; `0,4,7,8 -> pickedup`; `0,4,5,7,8 -> shipped`; `0,4,5,6,7,8 -> delivered`;
`0,4,5,7,8 -> cancel`; `1 && !is_guest -> return`. Transcribe them EXACTLY; they are the only
record of which transitions the server will accept, and `OrderController::order_status_change`
should be read to confirm the server agrees before any of them is widened or narrowed.

## And the server does not enforce those transitions at all
`OrderController::order_status_change()` (`:1209`) is well guarded for WHO — route-parameter id,
`$this->authorize('updateStatus', $order)` via `OrderPolicy::allow` (operator first, then
`Manage Order`, then same store), after P1-ORD-04. But it takes `$request->delivered` and passes
it straight to `Order::order_status_change()` with **no check that the transition is one the UI
offers**. The five `in_array()` lists in the view are presentation, not a control.

That is not a privilege defect — the caller already owns the order and holds `Manage Order`, and
the policy says so. It is worth knowing before restyling, for two reasons: (1) do not describe
those lists as "the allowed transitions" in any comment, because the server allows more; and
(2) if the restyle changes which items appear, nothing server-side will catch a mistake.
