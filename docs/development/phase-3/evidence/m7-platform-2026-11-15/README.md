# M7 — the public platform surfaces, measured

Captured 2026-11-15 against `php artisan serve` on `127.0.0.1:8899` with the `p2dev` fixture.

## What is here

| file | what it holds |
|---|---|
| `matrix.json` | 44 loads: 11 surfaces × {en, ar} × {light, dark}, each with `dir`, `lang`, `data-ss-theme`, computed body colours, content length, off-origin requests, console errors, and a premise verdict |
| `a11y.json` | the same 44 loads through axe-core, WCAG 2.0/2.1 A + AA and best-practice, with page weight |
| `journeys.json` | 3 click-through journeys, 64 internal links followed, 32 in-page anchors resolved, lab LCP and CLS per surface |
| `*.png` | 44 full-page screenshots at `deviceScaleFactor: 2`, named `<surface>--<locale>--<scheme>.png` |

Reproduce:

```bash
docs/development/phase-2.5/tools/serve-dev.sh &
```

```bash
node docs/development/phase-3/tools/platform-site-matrix.cjs --out docs/development/phase-3/evidence/m7-platform-2026-11-15
```

```bash
node docs/development/phase-3/tools/platform-site-a11y.cjs --out docs/development/phase-3/evidence/m7-platform-2026-11-15
```

```bash
node docs/development/phase-3/tools/platform-site-journeys.cjs --out docs/development/phase-3/evidence/m7-platform-2026-11-15
```

## The result

| | |
|---|---|
| loads | **44**, all four combinations |
| premises satisfied | **44 / 44** |
| off-origin requests | **0** |
| console errors | **0** |
| light/dark pairs whose background really differs | **22 / 22** |
| WCAG A/AA violation nodes | **0** |
| axe best-practice violation nodes | **0** |
| journeys completed | **3 / 3** |
| internal links followed | **64**, 0 broken |
| in-page anchors resolved | **32**, 0 broken |
| worst body contrast | **13.73:1** |
| worst lab LCP | **224 ms** |
| worst lab CLS | **0.0234** |
| heaviest page | **650 KB** transferred |

## What a row has to clear to count as evidence

Four of the five ways these captures can lie answer HTTP 200, so status is not the check:

- the page is Laravel's error page rather than the site;
- the content area is empty — the `@yield`/`$slot` mismatch CLAUDE.md records renders perfect
  chrome, 200, and nothing inside;
- `dir` disagrees with the locale asked for;
- the surface's own content marker is absent (a string that exists only inside that page's
  `@section('content')`, so the chrome cannot supply it);
- anything was fetched off-origin, or the console carried an error.

The light/dark pair is compared as a pair, because a run that produced the same background in both
columns would have photographed the light page twice and reported success.

## Two findings, both fixed before this capture

**`color-contrast`, 15 nodes.** `--ss-brand-accent` was used for small brand-coloured text — the
hero eyebrow, the current-page navigation link, the documentation sidebar's current item. In light
mode that token is `#138ba5`, and on `--ss-brand-subtle` (`#f4f5f8`) the measured ratio is
**3.64:1**, below the 4.5:1 AA needs for text that is not large (12px at weight 600 is not large).
All nine text uses moved to `--ss-brand`: **5.85:1** in light, **7.18:1** in dark.

**`landmark-complementary-is-top-level`, 12 nodes.** The documentation sidebar was an `<aside>`
inside `<main>`. It is not complementary content — `_nav.blade.php` renders a real `<nav
aria-label>` inside it, which is the landmark that belongs there — so the wrapper is a plain
`<div>`.

Both were invisible in the source. The token pair reads as deliberate in the stylesheet and the
colours look fine to an eye that is not measuring, which is why M7 renders the pages rather than
reading the CSS.

## Two harness defects worth recording, because each looked like a product defect

**A raw cookie is not the product's cookie.** The first run wrote `LANGUAGE` with
`context.addCookies()` and all 22 Arabic loads came back `dir="ltr"` in English — 22 red rows that
read as a broken language switch. `EncryptCookies` runs on the `web` group and this application
excepts nothing, so a raw cookie of the same name fails decryption and is discarded. The harness
now drives `/change-languages/{lang}`, which is both correct and the journey a visitor takes.

**A selector must be visibility-scoped where navigation is rendered twice.** The site renders its
navigation as a wide-screen sidebar and a narrow-screen `<details>` disclosure from shared markup,
so `locator(sel).first()` lands on the hidden copy and the click times out against an element that
is present, correct and invisible. The journeys use `:visible`.

## What these numbers do not say

- **Zero axe violations is not an accessibility pass.** axe automates roughly a third of WCAG.
  Keyboard order, focus management, whether a label *describes* its control, and whether the Arabic
  reads like Arabic are not measured here and are not claimed. A human review is outstanding, as it
  is for M2-E.
- **The vitals are lab numbers.** `artisan serve` on localhost: no network, no TLS, no CDN, no
  HTTP/2, no opcache, SQLite rather than MySQL. INP is a field metric and is not measured. They are
  a regression baseline, the same thing M2-E's numbers are, with the same caveat.
- **The heaviest page is 650,917 bytes over 11 resources, and it is the ARABIC one** — 365 KB
  design system, 28 KB platform stylesheet, **257 KB of self-hosted woff2** (four Arabic weights
  plus four Latin). English carries 79 KB of font instead, so an Arabic reader fetches about
  178 KB more. The stylesheet is uncompressed under `artisan serve` and gzipped in production;
  woff2 already is, so that part is real either way. Nothing comes from a font CDN, which is why
  the off-origin count is zero. Trimming either is a design-system question, not an M7 one.
