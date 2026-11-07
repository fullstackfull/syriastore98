# Store Syria — Web Design System

Phase 01 / Sprint 01 foundation, rebranded onto the real logo in Phase 2.5. One global stylesheet,
additive only.

- **Stylesheet:** `public/css/store-syria-ds.css`
- **Gallery:** `docs/design-system/gallery.html` — open it from a checkout; it loads the real
  stylesheet by relative path, and the two buttons at the top flip `dir` and `data-ss-theme`.
- **Forward architecture:** [`ADMIN-LAYOUT-ARCHITECTURE.md`](ADMIN-LAYOUT-ARCHITECTURE.md)

---

## How a page gets the system — it already has it

**The stylesheet is GLOBAL.** `resources/views/layouts/app.blade.php:225` links it for every page
that uses the admin layout, and `public/js/store-syria-ds.js` follows it at `:231`. Using the
system is therefore just putting `.ss-*` classes on markup; there is nothing to include.

Then wrap the page content in `.ss-scope`, which sets the font, colour and box-sizing context.

```blade
{{-- Only for a screen OUTSIDE the admin layout (guest, theme_layout). --}}
@push('css')
    @include('layouts.includes.store-syria-ds')
@endpush
```

The per-screen include still exists and still works, but inside the admin layout it is a **no-op
duplicate link** — it is kept only so a screen that does not use that layout can still opt in.

> **Corrected 2026-11-03 (Phase 2.5).** This section used to describe opt-in as *the* mechanism,
> under a heading "Why opt-in rather than global" arguing that a foundation sprint must not be
> able to regress a screen it never touched. **That argument was accepted and then overtaken by
> P2-04-5**, which promoted both the stylesheet and the behaviour into the layout: the order
> list's quick-preview panel rendered with an empty body and never fetched, across eight measured
> screenshot combinations, precisely because that screen had not opted in. The reasoning is kept
> here because it is the reason the original design was right at the time; the mechanism it
> describes is no longer what the code does.
>
> One practical consequence for anyone auditing: **grepping views for `store-syria-ds` no longer
> tells you which screens use the design system.** It returns the handful of files that still
> carry the include, and reads as "almost nothing adopted it", which is wrong. Grep for `ss-`
> classes instead.

### Where it sits in the cascade, measured

`@stack('css')` is at `app.blade.php:328`; the design system is linked earlier, at `:225`.

> **Corrected 2026-11-03 (Phase 2.5).** This section used to claim `@stack('css')` was
> "the **last** stylesheet position in `<head>` — after every vendor sheet" and concluded
> "**Nothing needs `!important` to win the cascade.**" **Measured in the running admin
> (`/category`, merchant session): the page loads 25 stylesheets and this one is 15th.** Ten load
> after it — `flatpickr`, `summernote-bs4`, `emojionearea`, `calendar.css`, two DataTables sheets,
> a blocked cross-origin Leaflet sheet, and **three inline `<style>` blocks**.
>
> So the conclusion does not hold in general. It holds where it matters most — every `--ss-*`
> property and every `.ss-*` class is unique to this system, so none of those later sheets names
> them — but an `!important` host declaration on a *shared* selector still wins, and that is a
> real mechanism rather than a hypothetical: it is what the P2.5-A-01/A-02 rules in `custom.css`
> exist to beat, and what drives the order grid and refund findings in the Phase 2.5 audit.
> **Do not assume a `.ss-*` rule wins over the host on an element that also carries a host class.**

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

The brand ramp is built on the **Store Syria logo**, a blue-to-cyan gradient. The authoritative
artwork is committed at `docs/design-system/brand/store-syria-logo-reference.png`, and the numbers
below are measured from its 30,235 chromatic pixels rather than chosen:

| | |
|---|---|
| hue span | **189.4° – 218.9°**, in two dense bands around 191° and 217° |
| `#335DA8` | hue 218.5° — the single most common chromatic colour, 3,644 pixels |
| `#0EAED1` | hue 190.8° — the centre of the cyan band; 27% of pixels within 3° |

Both reproduce the owner's own sampled values. `App\Support\Branding\StoreSyriaBrand` is the one
place those two constants live, and the admin layout, this stylesheet and the Flutter admin all
cite it. `tests/Unit/Ui/StoreSyriaBrandTokensTest.php` **opens that PNG at run time** and fails if a
brand token leaves the gradient — the check that did not exist the first time round.

**Blue is primary and cyan is the accent** because only the blue can carry a white label: at hue
218° a step light enough to still feel like the brand clears 4.5:1 on white (`#335da8` measures
6.42:1), while the cyan does not reach 4.5:1 until it has darkened to a petrol. Cyan therefore does
the work its surface suits — accents, the dark-mode brand, the gradient.

Steps pinned by contrast rather than by taste:

| Token | Value | Why |
|---|---|---|
| `--ss-brand` | `#335da8` (blue 600) | The logo's own blue. **6.42:1** on white, for button labels. |
| `--ss-focus-ring` | `#4f77c0` (blue 500) | **4.44:1** — clears the 3:1 non-text threshold (WCAG 1.4.11). |
| `--ss-brand-accent` | `#138ba5` (cyan 700) | **3.99:1**. cyan-600 measures 2.60:1 and fails, exactly as teal-600 did. |
| dark `--ss-brand` | `#2ac3e5` (cyan 500) | **7.40:1** on the host's dark page. Derived, not the light hex re-used. |
| `--ss-grey-400` | `#888c93` | Input borders — 3.38:1 on white. |
| `--ss-grey-500` | `#6b6f76` | Meta text — 5.05:1 on white. |

**The neutrals are blue-biased, and were re-biased with the brand.** Every grey sat at hue 168–180°
to sit with the old teal; a grey tuned to a teal brand reads green beside a blue one. They moved to
218° at unchanged HSL lightness and saturation, which also brought them into the same family as the
host's own dark page (`#22242c`, hue 228°) that the dark cards float on.

**Informational moved off blue.** It sat at hue 208.5°, ten degrees from the new brand — two
saturated blues nobody could tell apart. It is now indigo (`#3f3a8f`), 40° clear of the brand and
70°+ clear of every other status, which is what the owner's "keep status colours semantically
distinct" requires once the brand lands on blue.

> **Corrected 2026-11-03 (Phase 2.5).** This section used to say the ramp came from "the merchant's
> **own logo mark** (`uploads/2/*logoStore.svg`, a 168-197° teal-green gradient with a median hue
> of 177°)" at hue 172°. The derivation was sound and the source was wrong: that path is an upload
> under **store id 2** — a tenant's own file, whose embedded artwork is a teal-green *recolour* of
> this same mark (measured 167.2–197.5°, median 177.3°). The owner's correction of 2026-11-03 names
> the blue-cyan original as the identity. The retired teal primitives are kept in the stylesheet,
> consumed by nothing, so a per-store override or a merchant's Custom CSS naming one still resolves.
>
> The claim that `--ss-brand` and `--ss-focus-ring` were the *lightest* steps clearing their
> thresholds was true on the teal hue and is **not** true on blue — they clear them with margin
> instead. The ramp's spacing is what the rest of the system is built on, so the steps were kept and
> the claim restated rather than the colours re-solved.

**The host chrome follows the same brand.** A store that has chosen no colour of its own used to get
`theme-3` (`#10B77F`, hue 160° — 29.5° outside the gradient), so design-system components painted
blue while the sidebar and every `.btn-primary` beside them stayed green: two identities on one
page, measured in the running admin. The layout's fallback is now `custom-color` plus
`StoreSyriaBrand::CHROME_DEFAULT`, which is the host's **own** mechanism — `public/css/custom-color.css`
drives 39 selectors off `--color-customColor`, the active sidebar item among them — so nothing in
`packages/workdo/` or `assets/css/` is edited. A store that *has* chosen a colour still wins;
white-labelling is unchanged.

`docs/development/phase-2.5/tools/find-offbrand-colours.cjs` walks a rendered screen and reports
every computed colour outside the gradient that is not a semantic status, which is how "the brand is
applied" is checked here rather than by looking at a screenshot.

**Every foreground/background pair is machine-verified.** **58** pairs across light and dark are
checked against WCAG 2.1 AA; see "Verification" below.

> *Corrected 2026-11-03.* This said **40**, and the quoted output below said `ALL 40 PAIRS PASS`
> over `identical (35 tokens)`. The checker has reported **58 pairs** and **37 tokens** since the
> suite grew past Sprint 01, so both numbers had been stale for some time — a transcript pasted
> once and never re-run. Found by an adversarial reviewer that cited the README as corroboration
> for a finding and then discovered the README was the thing out of date. A quoted figure with no
> date is a claim about a command nobody has executed lately.

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

1. **It depends which layout the page uses, and the two disagree.**

   | layout | serves | LTR emits |
   |---|---|---|
   | `layouts/app.blade.php:68` | the **merchant admin** | `dir="ltr"` |
   | `layouts/main.blade.php:6` | the **storefront / guest** pages | `dir=""` |

   So inside the admin — which is what this design system is for — **`[dir="ltr"]` does match**,
   and `:not([dir="rtl"])` is not needed to reach LTR. On a guest or theme page that opts the
   stylesheet in, it does not.

   *Corrected 2026-11-03 (Phase 2.5), twice. This first said LTR emits `dir=""` and that a
   `[dir="ltr"]` selector "will never match", citing line 54. That was measured against the
   running admin and replaced with the `app.blade.php:68` behaviour — and the replacement was
   **also** incomplete: `dir=""` is real, in `main.blade.php:6`, so the original sentence was
   right about the wrong layout. Both lines are quoted above rather than summarised.
   `check-dark-form-surfaces-live.cjs` asserts the admin's value on every run, in both
   directions; nothing asserts the storefront's.*

   The guidance that followed from it is unchanged and still right, for a better reason:
   **write the default rule for LTR and override under `[dir="rtl"]`.** Not because the LTR
   selector cannot match, but because one unqualified rule plus one override is less to keep in
   step than two mirrored rules — and because logical properties make most overrides unnecessary.
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
signal, so the two cannot disagree, and no dark card ever flashes light. It also follows the
brand-settings page, which swaps that stylesheet live when the dark-layout switch is toggled, and
it never removes a `data-ss-theme` a page set for itself.

The host's dark stylesheets also force every `<p>` to `#808191` with `!important`. On a dark card
that is 3.42:1, so the system's secondary-text classes restate their colour with `!important`
(see "Host override defence" in the stylesheet); they measure 6.03:1.

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
  identical (37 tokens)

ALL 58 PAIRS PASS (light + dark)
```

**Browser** — Chromium 141 (Playwright's browser revision 1194), against the real
Blade-rendered markup and the real `public/` stylesheets: keyboard traversal, focus-ring presence
and contrast, tab order, accessible names read from Chromium's accessibility tree, 28 overflow
checks (7 widths × English and Arabic × with and without records), RTL mirroring, the
preserved-functionality contract, permission gating, the host's dark layout including its forced
paragraph colour and its runtime toggle, and a before/after comparison against the pre-sprint view:

```
66 PASSED, 0 FAILED
```

---

## Adding to the system

1. Add the semantic token first if the component needs a new colour; never let a component reach
   into a layer-1 primitive.
2. Add the new foreground/background pair to the contrast checker and re-run it.
3. Use logical properties only. If you type `left` or `right`, stop.
4. Render it in the gallery in all four combinations (LTR/RTL × light/dark) before committing.
