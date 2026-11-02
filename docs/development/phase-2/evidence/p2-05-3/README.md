# P2-05-3 — the stock report on the design system

Twelve screenshots of `/stock-report`, taken against the running application by
`docs/development/phase-2/tools/shoot-stock-report-live.cjs`: the low-stock tab in all eight
configurations (Arabic RTL and English LTR × light and dark × 1440 and 390) and the out-of-stock
tab at 1440 in all four. Invented fixture data only (`database/seed-p2dev.php`): no real
customer, order or credential appears in any of them.

Ledger **F106** records three measured defects on this one screen and **F107** a fourth. These
are the after.

## What to look for

**The STOCK STATUS column, on both tabs.** It was empty on five of five low-stock rows and ten of
ten most-stocked rows, and a raw Bootstrap `bg-light-danger` pill on the out-of-stock tab. The
partials gated on `stock_order_status` — which is the merchant's **backorder policy**, not a stock
level — so the dev products' NULL rendered nothing, and had that column held `allow` the
**low-stock report would have shown a badge reading "In stock"**. The tone now comes from
`ProductDataTable::trackedStockState()`, the same predicate the catalogue's own badge and filter
use, so the three tabs cannot disagree with the product list. Low reads `مخزون منخفض`
(`ss-badge--warning`), out reads `ss-badge--danger`, most-stocked `ss-badge--success`.

**The ACTION column, pinned at the trailing edge** — the left in Arabic, the right in English —
with a one-pixel separator where the rest of the row scrolls under it. Two separate things were
wrong with it and both had to be fixed:

- it had **no accessible name**: the `title` sat on the inner `<i>`, where it cannot name the
  parent link, and Bootstrap then moved it to `data-bs-original-title` and blanked it. Measured:
  five links, accessible name empty on all five. It now carries an `aria-label` naming the
  product (`تعديل Hand-loomed Table Runner`) with `aria-hidden` on the icon.
- it was **not reachable** (F107). The table is 905px against an 803px wrapper at 1440 and a
  320px one at 390, and the action column is 99px — so at the starting scroll position it sat
  entirely outside the visible box, on all three tabs. This is the same defect P2-03-1 measured
  on the product list and it is fixed the same way, with `ss-col-pinned`, rather than a second
  way.

**The ACTIVE TAB PILL.** It measured 2.59:1 — white on the host brand green `#10B77F` — in all
four configurations, against WCAG's 4.5:1 for text.

## The numbers behind them

Every row below is from the same run that produced the screenshots.

| configuration | active pill | low-stock badges | out-of-stock badges | actions named |
|---|---|---|---|---|
| ltr / light | 5.36:1 | 5 × warning | 4 × danger | 5/5, 4/4 |
| ltr / dark  | 5.36:1 | 5 × warning | 4 × danger | 5/5, 4/4 |
| rtl / light | 5.36:1 | 5 × warning | 4 × danger | 5/5, 4/4 |
| rtl / dark  | 5.36:1 | 5 × warning | 4 × danger | 5/5, 4/4 |

Reachability, measured separately by a **real click** at scroll position 0 — the geometry alone
is not the claim, because a cell can be inside the wrapper and still have something painted over
it:

| | before (`ss-col-pinned` removed) | after |
|---|---|---|
| ltr/light, rtl/light, ltr/dark, rtl/dark @ 1440 | outside the wrapper, no hit | navigates |
| the same four @ 390 | outside the wrapper, no hit | navigates |

Table 905px, wrapper 803px at 1440 (102px hidden) and 320px at 390 (585px hidden), in all four
configurations.

## What these do not show, and one thing they deliberately leave behind

The tab rail's markup is shared by **four** views — `reports/stock_report`, `module/index`,
`country/index` and `language/index` — and three of them belong to other slices. The AA fix is
therefore a new `ss-tabrail` class on **this screen's rail only**, styled with the existing
`--ss-green-700` primitive. The other three screens still carry the same 2.59:1 active pill and
are recorded as such in ledger F106; they are not fixed here and must not be assumed fixed. The
host's brand green is untouched — darkening it globally would restyle the whole product to
correct one pill's text.

The most-stocked tab is asserted by `tests/Feature/Merchant/StockReportPredicateTest.php` rather
than screenshotted, because the badge tone per tab is a data claim and the test checks both
halves of it (the right label present **and** the two wrong ones absent, since a view rendering
every tone would satisfy a presence-only check).
