# Phase 2.5 — HTML fragments, captured in place (2026-11-10)

The 2026-11-10 route sweep identified ten routes that answered 200 with **no `<html>` element**.
Driven as URLs they score exactly 1/4 — `en-ltr-light` passes by coincidence, because it is the
one combination that asks neither for `dir="rtl"` nor for the dark stylesheet. That is a positive
identification of a document fragment, not a partial failure.

`shoot-fragments.cjs` captures them the way a merchant meets them: it drives the **parent page**,
waits for the fragment to arrive, and screenshots the container's **own bounding box**. The
capture is therefore evidence for the fragment, not for the page hosting it — which is what lets
these be claimed separately from the report screens, captured whole in `web-sweep-2026-11-10/`.

## Captured — 5 surfaces × 4 combinations = 20

| slug | parent page | container | how it is triggered |
|---|---|---|---|
| `brand-sales-body` | `/Brand-order-sale-reports` | `.chart_data` | none — `fetchData()` runs on document-ready (`sale_by_brand.blade.php:175`) |
| `category-sales-body` | `/category-order-sale-reports` | `.chart_data` | none — same shape (`sale_by_category.blade.php:159`) |
| `stock-low-rows` | `/stock-reports` | `#low-stock-table` | the pill `a[data-bs-target="#pills-low-stock"]` |
| `stock-out-rows` | `/stock-reports` | `#out-of-stock-table` | `#pills-out-of-stock` |
| `stock-most-rows` | `/stock-reports` | `#most-stocked-table` | `#pills-most-stocked` |

**Two harness changes were needed and both are recorded in the tool.** `trigger` is now optional,
because the chart bodies fetch themselves and requiring a click reported them as "no visible
trigger" — true, and silent about the fragment. And the DataTable row-wait is skipped for a
surface that declares `waitForRows: false`, because the chart pages have no table and that wait
was a swallowed 15-second timeout, which reads like a working wait to the next person.

## What the captures found

### A hardcoded English label in an Arabic report — FIXED

`.chart_data` in the Arabic captures read **"All Brands"**.

`ReportController` builds `$NetSalesofbrand` as a map keyed by brand name and appends the
aggregate under the literal key `'All Brands'` (:2401, :2405, :2471, :2475).
`order_brand_chart.blade.php:12-13` iterates it and passes `:label="$name"` straight through — so
**the array key is the display label**, and a key is not a translatable string. `All Categories`
was *already* translated in `resources/lang/ar.json`; nothing had ever called `__()` on it.

Fixed with `App\Support\Reports\AggregateSeriesLabel`, which translates **only** the two literals
the application itself authored and passes everything else through byte-identical. Not
`__($name)`: every other key in that map is a merchant record name, and `__()` returns its input
on a miss, so a blanket call looks harmless until a merchant's brand collides with one of the
3,508 keys in `ar.json` — a brand called "Save" would be shown to an Arabic merchant as "حفظ".
The key itself stays English, because `ReportController` reads `$NetSalesofbrand['All Brands']`
back by that literal; same stable-identifier-versus-display-label split F154 drew for order
statuses.

Re-measured after the fix: "All Brands" is gone from the Arabic captures. The six Latin strings
that remain are all merchant catalogue data — `Soaps`, `Homeware`, `Scarves & Shawls`,
`Damascene Silk`, `Aleppo Weave`, `Homs Cotton` — and are correctly untouched. Pinned by
`tests/Unit/Reports/AggregateSeriesLabelTest` (4 cases, including a merchant record literally
named "Save").

### A report of zeroes is coverage, not evidence

The first brand captures showed `$0.00` on every card above an empty chart grid. That was **not**
a reporting defect — measured, `product_brands` held 3 rows, `products` held 32, and **0 of them
carried a `brand_id`**. The report was telling the truth about an unattributed catalogue.

A screenshot of four zeroes exercises no bar, no legend, no number formatting and no thousands
separator, and cannot show whether the chart reads right-to-left.
`tools/seed-dev-brand-assignments.php` attributes the existing brands to the products that
actually appear in an order — inventing no product, no order and no money — and the claimed
captures now show `336.00 + 126.00 = 462.00`. That sum is also a free re-confirmation of the
D-42.3 property that the aggregate is the sum and never double.

## Not captured

`Category / Product sales bodies (AJAX fragments)` is **one inventory row naming two fragments**.
`category-order-reports` is captured here; `product-order-reports` answers HTTP 500 on this
fixture because `order_product_reports` builds raw SQL with `MONTH()`, which SQLite does not
implement. The row stays **PENDING** rather than being claimed off the half that works — see
`../../PHASE-2.5-WEB-SWEEP-FINDINGS.md` §2 for the five routes blocked on a MySQL dev fixture.

The remaining four of the ten 1/4 routes are **modal** fragments
(`/create-language`, `/pixel-setting/create`, `/product-attributes/create`, `/webhook/create`),
which belong to `shoot-modals.cjs`. Two of them were already driven and recorded as uncapturable
in `../web-modals-2026-11-10/README.md`: the settings panel offers no create control, so whether
a merchant can create a pixel or a webhook from that hub at all is a product question rather than
a capture problem.
