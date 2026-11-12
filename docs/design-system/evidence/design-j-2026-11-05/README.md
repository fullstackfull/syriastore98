# P2.5-DESIGN-J — Bootstrap's tooltip destroys the accessible name it looks like it provides

**29 unnamed controls → 0**, in English and in Arabic.

There are no before/after screenshots in this package, and that is the point: **nothing here is
visible.** The evidence is a census of the running application and a ratchet over the source.

## 1. The mechanism, measured on the running admin

```
class="btn btn-sm btn-primary btn-icon"   title=""   data-bs-original-title="Add Customer"
                                          aria-label=null   textContent=""
```

`Tooltip` moves `title` into `data-bs-original-title` and **leaves `title` empty**, so the one
attribute carrying the name is gone the moment the plugin initialises. The icon is an icon *font*
— `<i class="ti ti-plus">` — which contributes no text. Some sites skip the first step and write
`data-bs-original-title` directly, so they never had a name at all.

`buildDataTable`'s `drawCallback` constructs a `Tooltip` for every `[data-bs-toggle=tooltip]` **on
every draw**, so this happens again after each sort, filter and page change.

**The pattern was already in this codebase** — `partision/header.blade.php:258` carries
`title=X aria-label=X` together, and 21 controls follow it. 51 did not. `aria-label` survives
because the plugin does not touch it.

## 2. Measured, in the running application

`docs/development/phase-2.5/tools/census-nameless-controls.cjs`, over `/customer /order
/product-list /dashboard /finance`:

| | unnamed | components |
|---|---:|---:|
| before | **29** | 11 |
| after, `ltr`/en | **0** | 0 |
| after, `rtl`/ar | **0** | 0 |

Reported separately, not counted: **3 `placeholder-only` search inputs**. `placeholder` *is* a
name under the accessible-name algorithm, so they are announced — but `ui-ux-pro-max`
`input-labels` asks for a visible label, so they are a real finding of a different kind. Recorded,
not fixed.

## 3. Two instruments, and one of them was wrong first

The census's first draft asked Playwright's `accessibility.snapshot` for each control's name and
reported **196** unnamed controls — including `<button class="btn btn-primary">Request
Withdrawal</button>`, whose name is its own visible text. **The instrument was wrong, not the
pages.** It now computes the name the way `audit-merchant-screens.cjs` does, and treats
`placeholder` as the name it really is: 196 → 32 → 29.

The source ratchet made the same class of error in the other direction: it *deleted* Blade echoes
before asking whether a control had text, so every `<i></i> {{ __('Edit') }}` looked icon-only and
it reported **110** sites where there are 51. An echo is text; it now becomes a marker. After the
correction the PHP scan and an independent Python sweep agreed on **51** exactly.

## 4. What was changed, and where the name had to go

| | |
|---|---|
| **51 Blade controls** in 31 merchant view files | `aria-label` mirroring the existing `title`, so the tooltip and the name say the same thing |
| **3 card-option menus** (`product/list`, `deliveryboy/index`, `store/index`) | `aria-label="{{ __('More actions') }}"` — one Blade fix covering **11 rendered instances**, because the product one is inside a loop |
| **`bulkDeleteForm()`, reset, reload** (`helper.php`) | the tooltip sits on the `<i>` *inside* the button, so the focusable element never had a name |
| **the pagination arrows** (`helper.php:3063`) | a visually hidden `Previous`/`Next` |
| **`dashboard.blade.php`** | an empty `<a>` that rendered as a **4×4** control, plus `alt="images"` |

### `init`, not `attr`, and that is measured

`attr` is DataTables Buttons' documented hook for attributes on the generated element, and **this
build ignores it**: with `'attr' => ['aria-label' => …]` the option really was in the page's
config — grepped out of the live HTML, `configHasAttr: true` — and the rendered
`a.bulk-delete` still had `aria-label: null`. `init` receives the node after it is built, and this
file already proves raw function strings survive serialisation because `action` is one.

### `Previous`/`Next`, not `Left`/`Right`

DataTables puts `previous` on the inline-start side in **both** directions, so the semantic word is
correct in Arabic even where the glyph is not. A hidden span rather than an attribute, because
DataTables writes these as the link's **content** and rebuilds the element on every draw — content
survives that; an attribute set afterwards would have to be reapplied.

### The 4×4 link

The `@if` sat *inside* the anchor, so a product with no cover image rendered an **empty link**: two
2px borders and nothing between them, with no name — a blue dot nobody can explain and a tab stop
that announces nothing. The `alt` was the string `"images"`, which names no particular product.

## 5. The storefront is Phase 03 and is not absorbed

One pre-existing control under `front_end/` carries `title="Print"` and `aria-label="Print"`, both
bare English, so an Arabic shopper gets an English tooltip **and** an English screen-reader name —
and `"Print": "طباعة"` is already in `resources/lang/ar.json`, so the fix is two translator calls.
It is **recorded, not taken**, because the owner's scope precedence puts the storefront in a later
phase. **The merchant twin of the same defect, in `support/edit.blade.php`, was fixed here.**

A test asserts the exemption stays one screen wide, so it cannot become somewhere to put things.

## 6. The Arabic ratchet caught the new strings

`ArabicUiCoverageTest` reddened on `More actions` the moment it was introduced — working exactly as
intended. `More actions` and `Share store link` went into `ar.json` **and** `en.json`: the orphan
ratchet in `TranslationFileIntegrityTest` pins keys-in-Arabic-without-English at 31, and adding to
one file alone took it to 33 and reddened three further tests.

## 7. Tests

`IconOnlyControlNameTest` — 4 cases. **Four bites, each reddening exactly one:** an `aria-label`
removed; one made bare English; the scan pointed at a directory with no views; and a second
storefront control with an untranslated label. A fifth check confirms a *translated* storefront
addition does **not** redden the exemption.

Full Unit suite: **1154 passed, 9812 assertions.**

## 8. Not verified

Five screens, one viewport, headless Chromium. No screen reader was run: the census computes the
accessible name by the DOM algorithm, which is an approximation of what NVDA or VoiceOver would
announce. Views outside those five were fixed by the source ratchet but not re-measured in a
browser.

## 9. Skills and principles applied

- **`ui-ux-pro-max`** — priority 1 *Accessibility*: *`aria-labels`* ("aria-label for icon-only
  buttons") is the rule this package serves end to end, and *`icon-context`* is why the fix is a
  name on the control rather than on the icon inside it. *`input-labels`* is why the three
  placeholder-only inputs are reported rather than quietly counted as passing.
- **`frontend-design`** — "name things by what users will understand in simple language", and "an
  action keeps the same name through the whole flow": each `aria-label` mirrors the tooltip beside
  it, so the control is called one thing whether you see it or hear it.
