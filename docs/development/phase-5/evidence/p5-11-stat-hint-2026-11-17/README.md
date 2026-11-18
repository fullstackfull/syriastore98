# P5-11 — the stat caveat, made visible and measured

Captured 2026-11-17. `<x-ss.stat>` rendered its `hint` inside `<span class="ss-visually-hidden">`,
which `public/css/store-syria-ds.css:2238-2246` clips to 1×1 with `clip: rect(0,0,0,0)` and **no
`:hover`, `:focus` or `:focus-within` rule anywhere** that reveals it. Ten call sites pass a hint
and nine of them exist to stop a figure being misread, so the Phase 05 honesty work — including the
wording corrected under the owner's third recommendation — rendered to nobody who could see it.

## What is here

| file | what it holds |
|---|---|
| `stats--<scheme>--<dir>--<lang>.png` | the `x-ss.stats` specimen: a three-tile grid carrying two REAL shipped hints (copied verbatim from `reports/_sales_stats.blade.php:17` and `:32`) plus one tile with none, so unequal-height behaviour is visible |
| `stat--<scheme>--<dir>--<lang>.png` | the single tile, full width |
| `measure-stat-hint.cjs` | the probe that produced the contrast and geometry table below |

Four combinations: `{light, dark}` × `{en-LTR, ar-RTL}`.

## Measured, in Chromium, against the composited background

A computed-style read is wrong on these surfaces and this project has already recorded why:
`.dash-content`'s `background-color` is `rgba(0, 0, 0, 0)`, so a naive probe compares every colour
against BLACK and reports alarming, false numbers (see
`tests/Unit/Ui/StatGridHairlineContrastTest`). The probe here walks up to the first **opaque**
ancestor before computing the ratio.

| | light | dark |
|---|---|---|
| hint contrast | **5.98:1** | **7.18:1** |
| `visible` / `clip` / `position` | true / `auto` / `static` | true / `auto` / `static` |
| `aria-describedby` → hint `id` | matches, unique per tile | matches, unique per tile |
| font size | 12px | 12px |

Both pass WCAG AA (4.5:1); dark also clears AAA (7:1). Zero clipped spans remain in any of the four
rendered documents.

## The cost, recorded rather than hidden

A visible caveat makes stat rows taller, and CSS grid equalises row height so every tile in the row
grows together — there are no ragged tiles, but the whole row moves.

| grid | before | after |
|---|---|---|
| 3 tiles at 1180px (380px cells) | 107px | 169px (+58%) |
| **6 tiles at 1180px (190px cells) — the real `_sales_stats` shape** | **123px** | **257px (+109%)** |

A 160-character sentence wraps to about seven lines in a 190px cell. This was **not** traded away by
clamping the text to N lines: a clamped caveat re-hides the thing the requirement exists to show. If
it needs to be tighter, the cheapest lever is `.ss-stats`' `minmax(10rem, 1fr)` — widening the
minimum trades columns for fewer wrapped lines. That is a tuning decision with these numbers in
hand, not a blocker.

## Reproduce

```bash
DB_CONNECTION=sqlite DB_DATABASE=$PWD/database/p2dev.sqlite CACHE_STORE=file php tests/Support/DesignSystem/bin/render-ds-gallery.php <out-dir> light ltr en
```

```bash
node tests/Support/DesignSystem/bin/shoot-ds-gallery.cjs <out-dir> 760
```

```bash
node docs/development/phase-5/evidence/p5-11-stat-hint-2026-11-17/measure-stat-hint.cjs <parent-dir-of-combination-dirs>
```

## What this evidence does and does not cover

**Covers:** the component, in all four language/theme combinations, through the real Blade compiler
and the real `store-syria-ds.css`.

**Does not cover:** an end-to-end render of each of the six host pages
(`reports/_sales_stats`, `order_brand_chart`, `order_category_chart`, `order_product_chart`,
`dashboard/overview`, `customer/timeline`), which need an authenticated merchant and seeded data.
The component emits identical markup on every one of them, and
`StatHintIsVisibleTest::test_twelve_hints_on_this_component_across_seven_files` pins which surfaces use
it — but the per-page claim here is inference from one component, not six captures, and is labelled
as such. The one thing that genuinely differs per surface is how many tiles share a row, which
drives row height; that is why the six-tile case above was measured separately rather than assumed
from the three-tile one.
