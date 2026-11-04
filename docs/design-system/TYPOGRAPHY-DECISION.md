# Typography decision — IBM Plex Sans Arabic, self-hosted

Owner decision of 2026-11-04 (premium typography, the first of the Premium Design work packages).
`public/css/store-syria-ds.css`'s `P2.5-TYPE` block implements it; this document is the evidence it
cites.

> **Typography is the FIRST design improvement, not the Premium Design deliverable.** Layout,
> spacing, tables, forms, charts, navigation and micro-interactions across the eight merchant areas
> are separate work packages and are not claimed here.

---

## 1. The state before, and a correction to my own report

**I told the owner the merchant admin "loads no webfont at all". That was false.**
`public/assets/css/style.css:16` — and the identical line in `style-dark.css`, `style-rtl.css` and
`rtl-style-dark.css` — carried:

```css
@import url("https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;500;600;700&display=swap");
```

My greps covered the Blade layout chain and the two main sheets and missed a vendored `@import`. I
claimed before measuring. Corrected state, measured in Chromium against the live admin:

| | delivered? | evidence |
|---|---|---|
| **Latin** | **yes** — Open Sans 400/500/600/700 | but by a **runtime** request: 5 external calls to Google per page load (3 CSS, 2 woff2) |
| **Arabic** | **no** | the stack named `"Noto Sans Arabic"`, `"Cairo"`, `"Tajawal"`; each measured **489.2px** for a test string, **identical to a deliberately bogus family name** |

The decisive Arabic measurement, because the width test is the one that cannot be fooled:

```
Arabic string in "Open Sans"      279.8 px
Arabic string in "__bogus__"      279.0 px   <- 0.3% apart: the same thing
Latin  string in "Open Sans"      391.8 px
Latin  string in "__bogus__"      327.7 px   <- 19.6% apart: a real typeface
```

So the Arabic half of a bilingual product had **no typeface**, and the Latin half depended on a
third party at runtime. The correction makes the finding stronger than the claim it replaced: the
owner's "no external Google Fonts at runtime" requirement was already violated, in four files.

**One of my own tests gave the wrong verdict** and is recorded so the number is not trusted twice:
it compared 279.8 to 279.0 with strict equality and printed *"Open Sans is supplying Arabic
glyphs."* 0.8px out of 280 is sub-pixel noise, not coverage.

---

## 2. The two candidates, measured

Both were installed self-hosted and driven side by side against real admin strings — screen titles,
nav, a money column, and a status table — in Arabic and English, light and dark, desktop and mobile.
Harness: `public/fonts/_candidates/compare.html` (removed after the decision; the four rendered
comparisons are in `evidence/typography-2026-11-04/candidates-*.png`).

| | files / requests | bytes | KB | digits equal **by default** | both scripts, one family |
|---|---|---|---|---|---|
| **IBM Plex Sans Arabic** | 8 static | 257,368 | **251.3** | **yes** — spread `0` at 40px | **yes** |
| Noto Sans Arabic + Inter | 2 variable | 214,216 | **209.2** | **no** — spread `9.563px` | no |

Other measured properties (40px specimen):

| | x-height | cap-height | Arabic render width |
|---|---|---|---|
| IBM Plex Sans Arabic | 52 | 70 | 253.6 |
| Noto Sans Arabic + Inter | 55 | 73 | 260.9 |
| *monospace fallback only* | — | — | *385.3* |

Both candidates genuinely render Arabic: 253.6 and 260.9 against 385.3 for the fallback.

**A measurement error of mine, caught before it reached the comparison.** Noto+Inter first costed at
**836.8 KB**. Four "weights" were md5-identical because both are **variable** fonts served from one
URL per family. Corrected to 2 files / 209.2 KB — which is the number the decision below is weighed
against, and it is the number that makes Noto+Inter the *cheaper* option.

### Why IBM Plex Sans Arabic won anyway

1. **Equal-width digits by default.** Inter's `tabular-nums` does fix its 9.563px spread (→ `0`),
   but only where somebody applies it. This admin is money columns, order ids, quantities and stock
   counts; the family that is right by default beats the one that is right by discipline.
2. **One family, both scripts, drawn as companions** — Latin and Arabic cannot drift apart in
   x-height or cap-height, and there is no second family's metrics to harmonise.
3. **42 KB is the price of those two properties**, and per page it is narrower than the total
   suggests: `unicode-range` means an English session fetches only the four Latin files, **77 KB**.

---

## 3. How it is wired

* **`@font-face` lives in `public/css/store-syria-ds.css`**, which `layouts/app.blade.php:242`
  links **unconditionally** — so one declaration reaches all 110 merchant views.
  *A false finding was caught here:* only **6 of 110** views carry the opt-in
  `@include('layouts.includes.store-syria-ds')`, and reading that as the delivery path would say the
  design system reaches 6 screens. It does not; the include is an additional mechanism.
* **Eight faces, two per weight**, split by `unicode-range` so a page downloads only the scripts it
  renders. Dropping the ranges would make every English screen pay for 178 KB of unused Arabic.
* **`--ss-font-sans`** names the family; **`--bs-body-font-family`** aliases that token rather than
  restating it, which is how the family reaches everything Bootstrap styles and not only design-system
  components. Primitive → semantic → host alias, per the `design-system` skill's three layers.
* **`font-display: swap`** on every face. The fallback chain after the family is still a real chain:
  if a woff2 fails, Arabic has `"Noto Sans Arabic"`/`"Cairo"` to try before a system font.
* **The four Google `@import` lines are removed**, each replaced by a comment saying what stood there
  and why it went. That is what actually satisfies the no-runtime-Google requirement.

**Licence:** SIL OFL 1.1, preserved **byte-identical** at `public/fonts/store-syria/OFL.txt`
(`Copyright © 2017 IBM Corp. with Reserved Font Name "Plex"`), CRLF included — `.gitattributes`
carries `-text` for that one path, because `* text=auto eol=lf` would otherwise rewrite a licence
file in passing. **257,368 bytes** shipped, counted.

---

## 4. Verification

### Live, after the change

```
external Google font requests : NONE
self-hosted woff2 served      : all 8
body font-family              : "IBM Plex Sans Arabic", "Noto Sans Arabic", Cairo, … (chain intact)
Arabic vs no-font             : 253.6 vs 279.0  ->  9.1% apart   (was 0.3%)
Latin  vs no-font             : 370.7 vs 327.7  -> 13.1% apart
digit width spread at 40px    : 0
```

### Before/after, every screen

14 screens × 4 combinations (en/ltr + ar/rtl, light + dark) captured before and after:
**52 pairs, mean 13.92% of pixels changed, every pair above 1%, none unchanged.** Per-screen table
in `evidence/typography-2026-11-04/pixel-diff.md`; side-by-side composites for eight screens in the
same folder. The render matrix asserts the direction and the stylesheet per row, so a pair is never
light-vs-light or ltr-vs-ltr by accident.

### What is NOT verified

* **No device or real-browser-matrix testing.** This is headless Chromium on one machine. Windows
  and macOS clients may still have their own Open Sans installed, which changes nothing about what
  is now *delivered* but does change what those clients saw *before*.
* **`/app-setting` is HTTP 500 in all four combinations, before and after.** Pre-existing, not this
  change: `AppSettingController:32/43/58` read `$_SERVER['SERVER_ADDR']`, which `artisan serve` does
  not set. It is one of the 4-not-clean rows in both matrices.
* **No Arabic native-reader review.** Readability here is measured structurally (delivery, metrics,
  width, digit alignment), not judged editorially.

---

## 5. Skills applied, and what each one actually changed

| skill | what it contributed |
|---|---|
| `frontend-design` | its *review-the-plan-against-the-brief* step is what rejected three of `ui-ux-pro-max`'s four recommendations below, rather than adopting a generated palette |
| `ui-ux-pro-max` | its CLI was run (`--design-system --density 8 --variance 4 --motion 3`). **Rejected:** the slate+green palette (collides with D-41's blue/cyan logo identity), Glassmorphism (its own output rates it `accessibility risk: conditional`, and `frontend-design` names it a default), Fira Code headings (a mono display face with **no Arabic coverage** — disqualifying here). **Kept:** dashboard density, subtle motion, status-colour discipline, tabular figures |
| `design-system` | the three-layer token discipline described in §3: faces declare, semantic token names, host variable aliases |
| `ui-styling` | **only partly transfers** — it is shadcn + Tailwind, and this admin is Blade + vendored Bootstrap 5 with the Tailwind toolchain installed and never loaded. Its theming, dark-mode and accessibility discipline applied; its component library did not |
