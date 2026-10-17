# Store Syria — Web Design System

Phase 01 / Sprint 01 foundation. One stylesheet, opt-in per page, additive only.

- **Stylesheet:** `public/css/store-syria-ds.css`
- **Gallery:** `docs/design-system/gallery.html` — open it from a checkout; it loads the real
  stylesheet by relative path, and the two buttons at the top flip `dir` and `data-ss-theme`.
- **Forward architecture:** [`ADMIN-LAYOUT-ARCHITECTURE.md`](ADMIN-LAYOUT-ARCHITECTURE.md)

---

## How a page opts in

```blade
@push('css')
    @include('layouts.includes.store-syria-ds')
@endpush
```

The partial sits beside `layouts/includes/datatable-css.blade.php` and is pulled in the same way.
It links the stylesheet with a hand-set `?v=` (bump it when the CSS changes) and makes the page
follow the host's dark layout — see "Dark mode".

`resources/views/layouts/app.blade.php:176` already renders `@stack('css')`, and it is the
**last** stylesheet position in `<head>` — after every vendor sheet, after `assets/css/style.css`,
after `css/custom.css`, and after the inline per-store `storecss` block. So:

- No shared layout file has to be edited.
- A screen that does not opt in is byte-for-byte unaffected.
- Nothing needs `!important` to win the cascade.

Then wrap the page content in `.ss-scope`, which sets the font, colour and box-sizing context.

> **Why opt-in rather than global.** This is a live multi-tenant system. A foundation sprint
> must not be able to regress a screen it never touched. Pages adopt the system one at a time,
> and each adoption is independently reviewable and revertable.

### Why it cannot collide with the host

Every custom property is `--ss-*` and every class is `.ss-*`. **This stylesheet never styles a
bare element selector.** Adding the `<link>` to a page therefore cannot restyle existing markup —
only elements that explicitly carry an `ss-` class are affected. That is also what keeps it from
overriding a merchant's hand-written Custom CSS, even though it loads after it.

---

## Token layers

Three layers. Components only ever read layer 2.

| Layer | Example | Rule |
|---|---|---|
| 1 — primitives | `--ss-teal-800`, `--ss-grey-200` | Raw ramps. Components must not reference these. |
| 2 — semantic | `--ss-brand`, `--ss-fg-muted`, `--ss-border-strong` | The contract. Renaming one is a breaking change. |
| 3 — components | `.ss-btn`, `.ss-card`, `.ss-table` | Consume layer 2 only. |

### Colour

The brand ramp is derived from the merchant's **own logo mark** (hue 172°, sampled from
`uploads/2/*logoStore.svg`). No new brand identity was invented, and no logo was replaced.

Two steps are pinned by contrast rather than by taste:

| Token | Value | Why |
|---|---|---|
| `--ss-brand` | `#097465` (teal 800) | Lightest step clearing **4.5:1** against white, for button labels. |
| `--ss-focus-ring` | `#08917f` (teal 700) | Lightest step clearing **3:1**, for non-text contrast (WCAG 1.4.11). |
| `--ss-grey-400` | `#889392` | Lightest neutral clearing 3:1 on white — input borders. |
| `--ss-grey-500` | `#6b7675` | Lightest neutral clearing 4.5:1 on white — meta text. |

The host's default theme (`theme-3` = `#10B77F`) is already in the same green-teal family, so the
system sits naturally alongside the existing chrome.

**Every foreground/background pair is machine-verified.** 40 pairs across light and dark are
checked against WCAG 2.1 AA; see "Verification" below.

### Typography

`--ss-font-sans` inherits the host's own family via `var(--bs-body-font-family, "Open Sans")`, so
no second webfont is downloaded. Arabic families (`Noto Sans Arabic`, `Cairo`, `Tajawal`) follow
immediately, **before** any Latin fallback, so an Arabic glyph is never served out of a Latin
font's substitution table.

Inputs are `16px` (`--ss-text-md`) and must stay there: anything smaller makes iOS Safari zoom the
viewport on focus. Body text is `14px`, matching admin density.

---

## RTL

**There is no `-rtl` counterpart to this file and there must never be one.**

The host's approach is a full mirrored copy: `public/css/rtl-custom.css` is a 4,265-line duplicate
of `custom.css`, swapped server-side. Every change has to be made twice or the two directions
drift. This system instead uses **CSS logical properties** throughout — `margin-inline-*`,
`padding-inline-*`, `border-inline-*`, `inset-inline-*`, `text-align: start/end` — so the same
bytes lay out correctly in both directions.

Two host behaviours constrain anything written here:

1. **LTR emits `dir=""`, not `dir="ltr"`** (`app.blade.php:54`). A `[dir="ltr"]` selector will
   never match. Write the default rule for LTR and override under `[dir="rtl"]`.
2. **Bootstrap's `.ms-*` / `.me-*` / `.text-start` utilities are physical**, and are flipped by
   building a separate stylesheet — not logical. Do not mix them into `.ss-*` markup; use the
   system's own spacing utilities or logical properties.

Arabic and Hebrew force RTL regardless of the `SITE_RTL` setting (`app.blade.php:48-50`).

---

## Dark mode

An opted-in page follows the host's dark layout automatically. The host does not mark dark mode
with a class: its `cust_darklayout` setting swaps the stylesheet in `#main-style-link` to
`style-dark.css` or `rtl-style-dark.css`. The opt-in partial reads that same element in `<head>`,
before the body paints, and sets `data-ss-theme="dark"` on `<html>`. It reads the layout's own
signal, so the two cannot disagree, and no dark card ever flashes light.

Dark surfaces are tuned to the **host's** dark page (`#22242c`), not to `--ss-bg`: a card sits a
touch lighter than that page, or it reads as a hole. Text and borders were re-solved against them.

You can also set `data-ss-theme="dark"` on any container yourself, or `"auto"` to follow
`prefers-color-scheme`. The `auto` scope is a copy of the dark one — CSS cannot share one block
between the two selectors — so the contrast checker fails if the two ever differ.

This matters on the pilot in particular: under the dark layout, the original screen rendered its
option names white on white (1.00:1, measured). The redesign renders them at 11.10:1.

---

## Components

Buttons · form fields (input, select, textarea, label, hint, error) · card · badges and status
dots · table (sortable headers, stacked-on-mobile variant) · toolbar with search and filters ·
empty state · skeleton loading · alerts · tabs · dialog · page header · breadcrumbs · sortable
list · visually-hidden and skip-link utilities.

See the gallery for every variant and state rendered live.

### Conventions worth knowing

- **State comes from ARIA, not from classes.** Invalid fields key off `aria-invalid="true"`,
  busy buttons off `aria-busy="true"`, selected tabs off `aria-selected`, sorted columns off
  `aria-sort`. The visual state and the accessible state therefore cannot drift apart.
- **Focus uses `:focus-visible`**, so a pointer user never sees a ring they did not ask for and a
  keyboard user always does. The ring is 2px with a 2px offset.
- **Solid danger is reserved for the confirming action in a dialog.** Row-level deletes use
  `.ss-btn--danger-quiet`, so a long table is not a wall of red.
- **Icon-only buttons carry `.ss-visually-hidden` text naming the record** — "Delete Linen shirt",
  not "Delete".
- `prefers-reduced-motion`, `forced-colors` and `print` are all handled.

---

## Verification

Both of these are executed, not asserted. The scripts are committed under
[`tools/`](tools/README.md) and reproduce these numbers against the committed stylesheet.

**Contrast** — resolves `var()` chains and keeps the light and dark scopes separate, so the ratios
are the ones a browser actually computes; it also fails if the `auto` scope drifts from `dark`:

```
=== AUTO SCOPE vs DARK SCOPE ===
  identical (35 tokens)

ALL 40 PAIRS PASS (light + dark)
```

**Browser** — Chromium 1194 via Playwright, against the real Blade-rendered markup and the real
`public/` stylesheets: keyboard traversal, focus-ring presence and contrast, tab order, accessible
names, horizontal overflow at 7 widths × 4 page states × both directions, RTL mirroring, the
preserved-functionality contract, permission gating, the host's dark layout, and a before/after
comparison against the pre-sprint view:

```
59 PASSED, 0 FAILED
```

---

## Adding to the system

1. Add the semantic token first if the component needs a new colour; never let a component reach
   into a layer-1 primitive.
2. Add the new foreground/background pair to the contrast checker and re-run it.
3. Use logical properties only. If you type `left` or `right`, stop.
4. Render it in the gallery in all four combinations (LTR/RTL × light/dark) before committing.
