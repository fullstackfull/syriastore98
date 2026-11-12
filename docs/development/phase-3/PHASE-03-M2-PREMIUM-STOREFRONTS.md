# Phase 03 M2 — premium storefronts, light and dark, across three themes

**Branch:** `feature/phase-03-storefront` · **Measured:** 2026-11-12

M2-A built the appearance foundation. M2-B is the first theme, and it changed what the rest of M2
has to do.

---

## 1. M2-A — the foundation (complete, `a84540e7`)

Per-viewer browser storage scoped to the individual store, as the owner approved.

| | |
|---|---|
| key | `store-syria:storefront:appearance:s<store id>` |
| states | `auto` (default, follows the device), `light`, `dark` |
| verified | **40 browser checks, 0 failures** (`tools/verify-appearance.cjs`) |
| cascade safety | **1,134 computed-style comparisons, 0 differences** when the design system is loaded (`tools/verify-no-cascade-regression.cjs`) |

The store id, not the slug: the slug is changeable through `updateStoreOwnerInfo`, so keying on it
would silently discard a shopper's choice when a merchant renames their shop.

---

## 2. M2-B — THE FINDING: a tokenised stylesheet is not enough

**Tokenising a theme's `main-style.css` is necessary and NOT sufficient**, and establishing that is
most of this package.

The storefront themes load `tailwind.js` — the runtime JIT CDN — and their Blade markup carries
colours as **utility classes**: `bg-white`, `text-black`, `text-gray-600`, `border-gray-200`. Those
are generated with fixed palette values and injected **after** the theme stylesheet, so they win on
source order and no amount of `:root` token work reaches them.

Measured on stylique with its stylesheet **fully tokenised**:

| failure | ratio | min |
|---|---|---|
| `bg-white` panels staying `#ffffff` while text became `#e8eaed` | **1.21:1** | 4.5 |
| `text-black` on the dark page `#1b1f25` | **1.27:1** | 4.5 |
| `text-gray-600` on the dark page | **2.19:1** | 4.5 |
| `bg-gray-50` panels staying `#f9fafb` | **1.15:1** | 4.5 |

The page re-themed and the components did not. That is what "dark mode does not work" looks like
when only the stylesheet was tokenised.

`public/css/storefront-appearance.css` maps the utilities to design-system tokens. **Shared, not
per-theme**: the mapping is utility→token, contains nothing stylique-specific, and all three themes
load the same `tailwind.js`. M2-C and M2-D inherit it.

Three details that are easy to get wrong:

- **Tailwind's grey ramp must be INVERTED, not mapped across.** `text-gray-900` is the strongest ink
  in light mode and must stay strongest in dark — which is the *lightest* value. Mapping it straight
  makes the most important text the faintest.
- **`color-scheme` is the one declaration no class mapping can replace.** After every utility was
  mapped, two `<input>` elements were still `#ffffff` on a dark page — painted by the *browser's*
  stylesheet from the `Field` system colour until the document declares its scheme. It also
  re-themes scrollbars, the canvas behind a short page and the autofill tint.
- **Every dark rule needs an `auto` arm.** `auto` is the default state, so a rule written only for
  `[data-ss-theme="dark"]` misses most visitors. The stylique page background made exactly that
  mistake first: dark surfaces and a white page.

### Contrast, measured

| | failures |
|---|---|
| before M2-B, both appearances, 4 routes | **42** |
| after | **4** |
| dark mode specifically | **18 → 0** |

All four remaining appear in the before list, so none was introduced — verified by re-running the
probe against the original stylesheet with the new layer blocked, not by reasoning about it.

Still pre-existing and deliberately untouched: coral used as *text* (`text-primary`, 2.53–2.64:1)
and `text-white` on a near-white hero section.

### One deliberate change to stylique's identity

White ink on the coral `.btn-primary` measures **2.64:1** at 16px/500 — a real pre-existing AA
failure on "Shop Now", "Login" and "Apply Filters", and worse on the 10px cart badge. **All five**
original `--white-color` sites turned out to sit on the coral, including an svg stroke inside
`.btn-primary` at 2.64:1 — below even the 3:1 minimum for a graphical object. Dark ink on the same
coral gives **6.39:1**.

**The coral is kept and the ink changes.** The brand colour is what makes the theme recognisable;
the ink on it is not. Darkening the coral enough for white to pass would need a luminance below
0.18 against its current 0.33 — a different colour, not a tweak.

### Three invalid declarations, found by the test rather than by reading

| property | sites | effect |
|---|---|---|
| `--primary-color-rgb` | `:696`, `:702` | used, never declared → both box-shadows never rendered |
| `--white` | `:715` | declared nowhere; resolves to `""` → invalid at computed-value time, the icon silently inherited |
| `--black` | `:828` | same |

All three repaired. The progressbar icon's step is filled with `--primary-color` (verified), so it
takes the brand ink; the countdown digits take body text.

### Light mode: exactly four differences, and nothing else

710 computed-style comparisons against the pre-M2-B stylesheet. **Zero** changes to type, spacing,
layout, radius or shadow.

| count | change | why |
|---|---|---|
| 416 | `color` `rgb(0,0,0)` → `rgb(26,29,35)` | `body` had **no** text colour, so everything inherited the browser's pure black. 21:1 → 16.88:1, both far above AA, and required for dark mode to be possible at all |
| 33 | `borderColor` `#d3d3d3` → `#dddfe3` | the design system's border token |
| 8 | `backgroundColor` transparent → `#ffffff` | `body` is now explicitly white — **visually identical** over the canvas |

### The capture

The harness now carries an **appearance** dimension, so the matrix is four combinations rather than
two. Each is driven the way a shopper produces it — the context's `colorScheme` plus the
store-scoped `localStorage` key — not by forcing the attribute, which would test the stylesheet
while skipping the preference mechanism. A row whose *resolved* appearance does not match what it
claims is a premise failure.

| locale | appearance | rows | premise-fail | images | broken | overflow | page background |
|---|---|---|---|---|---|---|---|
| en | light | 21 | **0** | 513 | **0** | **0** | `rgb(255,255,255)` |
| en | dark | 21 | **0** | 513 | **0** | **0** | `rgb(27,31,37)` |
| ar | light | 21 | **0** | 513 | **0** | **0** | `rgb(255,255,255)` |
| ar | dark | 21 | **0** | 513 | **0** | **0** | `rgb(27,31,37)` |
| | | **84** | **0** | **2,052** | **0** | **0** | |

`evidence/m2b-stylique-2026-11-12/` — **84 captures, 84 distinct contents.** The M1 baseline is
preserved untouched as the before evidence.

### The cross-store gate refused to report clean, correctly

`check-cross-store-captures.py` answered *"PREMISE FAILED: parsed 0 captures, so a pass would mean
nothing"* — its filename regex did not know the new appearance segment, and it refuses to bless
files it cannot parse. Fixed, and the fix proven by re-running it over M1's 210 old-format captures
(still clean).

It then refused a second time, also correctly: **one store cannot answer a cross-store question.**
That check becomes meaningful at M2-E, when all stores are captured together.

---

## 3. What M2-B does NOT claim

- No performance or accessibility instrumentation yet (Core Web Vitals are M2-E).
- `techzonix` and `greentic` are untouched — M2-C and M2-D.
- Coral-as-text and the near-white hero remain open, pre-existing.
- Nothing is merged to `main`.
