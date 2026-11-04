# Live verification of the last four repairs — 2026-11-04

Owner priority 2 of the Phase 2.5 continuation directive: *"Complete real Chromium visual
verification of the affected Laravel screens in Arabic RTL, English LTR, light and dark modes.
Capture actual screenshots and test the relevant interactions."*

This is the record of that run. Everything below was executed against the live application on
`127.0.0.1:8899` (`tools/serve-dev.sh`, the `database/p2dev.sqlite` fixture) on 2026-11-04. The
numbers are Chromium's, read back from the page; none is inferred from source.

---

## 1. What this supersedes, and why the earlier evidence was weaker

Two tools produced the first pass, and both carried a limitation that came from running four repair
chains concurrently:

| tool | limitation | consequence |
|---|---|---|
| `measure-finance-money-contrast-live.cjs` | substituted the theme by **intercepting** the request for `#main-style-link`, and **injected** the ledger markup into the real `<tbody>` | real cascade and real surfaces, but not the app's own theme selection and not the real page |
| the staff-card CDP measurement | measured **Arabic only** | the English accessible names were never read, because writing the shared preference state was forbidden while siblings were running |

Nothing is concurrent now. `tools/verify-money-and-staff-live.cjs` drives the **real** preference
state through `phase-2/tools/set-admin-preview-state.php` and measures what the application itself
renders, in all four combinations, with no interception and no injected markup.

Two fixture gaps had to close first, because a money screen with no money rows cannot be measured.
`tools/seed-dev-interaction-fixtures.php` now also seeds one credit and one debit wallet
transaction and gives two probe orders' financial rows a profit and a loss. Three facts found while
doing it, each of which broke a first attempt:

* `store_order_financials.order_id` carries a **FOREIGN KEY to `orders(id)`**. A reserved-id band
  does not insert — measured, `SQLSTATE[23000]` on `order_id` 9250001.
* `OrderObserver` → `OrderFinancialService` **already writes one financial row per order**, so the
  55 probe orders had 55 rows, every money column `0`. The seeder therefore **updates two** rather
  than inserting; it never had to create what the application creates.
* A column of zeros renders only the credit branch (`0 >= 0`), so the debit tone had nothing to
  paint. That is exactly the gap that made the first measurement inject its own markup.

---

## 2. The money tones, measured natively in all four combinations

`/finance`, real rows, the app's own sheet selection. Premises asserted per combination: the
direction the layout emitted and the sheet `#main-style-link` really requested.

| combination | dir | `#main-style-link` | credit | debit |
|---|---|---|---|---|
| en / ltr / light | ltr | `style.css` | **7.82:1** | **9.12:1** |
| en / ltr / dark | ltr | `style-dark.css` | **8.16:1** | **7.48:1** |
| ar / rtl / light | rtl | `style-rtl.css` | **7.82:1** | **9.12:1** |
| ar / rtl / dark | rtl | `rtl-style-dark.css` | **8.16:1** | **7.48:1** |

Against what it replaced, measured in the same place: credit **1.80:1** light (below even the 3:1
floor WCAG allows for non-text) and **7.93:1** dark; debit **4.00:1** light and **3.56:1** dark.
All four combinations now pass AA, and the KPI figure — reported by the probe as
`[inside a heading]` — carries the tone in dark mode too, which is the half that was a silent
no-op until the tone moved onto a `<span>` inside the `<h4>`.

Screenshots: `finance-{en,ar}-{light,dark}.png`.

## 3. The staff card's accessible names, from Chromium's own tree, in both languages

`/users` over CDP. **This closes the gap the first pass left open** — the English names had never
been read.

| | English | Arabic |
|---|---|---|
| kebab | `"Actions for Rami Haddad"` len=23 **from=attribute:aria-label** | `"إجراءات Rami Haddad"` len=19 **from=attribute:aria-label** |
| avatar `<img>` | `"Profile photo of Rami Haddad"` len=28 **from=attribute:alt** | `"الصورة الشخصية لـ Rami Haddad"` len=29 **from=attribute:alt** |
| its link | same text, **from=contents** | same text, **from=contents** |
| outline | `h2 h3 h3 h3 h3 h3 h3 h3 h3 h3` — no skip, no reversal | identical |

What it replaced: the kebab computed `name="U+E89A" len=1` — one Private-Use codepoint from
`feather.css:278`, which is **not empty**, so an "is the name non-empty" check passed while the
control was unusable. The probe now fails on any Private-Use codepoint in a name.

**A reading this pass produced and had to throw away.** Filtering the full AX tree for
`role === 'image'` returned five nameless images and made the avatar repair look like it had
failed. The nameless ones are chrome — the header logo carries a deliberate `alt=""`. A per-node
`DOM.querySelectorAll('img.img-user')` → `Accessibility.getPartialAXTree` proved all three avatars
named `from=attribute:alt`. The tool now asks about the element that was repaired, and the mistake
is recorded in it.

## 4. The interactions — 18 checks, 0 failed

`tools/probe-p25-interactions.cjs`. These are the repairs no render matrix can prove.

* **The two withdrawal confirmations.** Native `confirm()` fired and read
  `"هل تريد الموافقة على طلب السحب هذا؟"` in Arabic and `"Approve this withdrawal request?"` in
  English. **The dialog is still synchronous, by owner instruction** — the probe is written so that
  finding *no* dialog is a FAIL, because that would mean the financial confirmation had been
  converted to an async SweetAlert without the separate approval that requires.
* **The board column cap.** Pending header count **55**, cards rendered **exactly 50**, overflow
  link `"See all 55 in the list"` / `"عرض الكل (55) في القائمة"` → `/order?ss_status=0`. Negative
  control: **8 uncapped columns, none with a link.**
* **The skip link.** First `Tab` from the document start reaches `<a class="ss-skip-link">`; `Enter`
  leaves `document.activeElement` on `<div id="ss-main" tabindex="-1">` — it **moves focus**, not
  just scrolls, in both directions.
* **The repaired kebab from the keyboard.** Focus, `Enter` → `aria-expanded=true`, menu shown, 4
  items, named. A name is worth nothing on a control that cannot be opened.

**A reading this probe produced and had to throw away**, recorded in the tool: counting
`[class*="board__card"]` is a *substring* match that also catches `.ss-board__card-id` and the two
`.ss-board__card-meta` children, so a correctly capped column of 50 reported **251** and the cap
looked broken. The card root is `article.ss-board__card`.

## 5. Render matrix over the six affected screens — 24 rows, 0 not clean

`/finance`, `/finance/withdrawals`, `/users`, `/order-kanban`,
`/category-order-sale-reports`, `/Brand-order-sale-reports` × four combinations. Every row HTTP
200, with the requested direction and the requested theme sheet.

---

## 6. Three findings this pass surfaced. None is caused by these repairs; none is repaired here.

Recording rather than fixing is deliberate: the regression gate was already running against this
tree, and adding a fifth change would have invalidated it.

**(a) A dead ApexCharts initialiser throws on five report screens.** Five views
(`reports/sale_by_category`, `order_report`, `sale_by_brand`, `sale_by_product`,
`bar_chart_order_report`) run `new ApexCharts(document.querySelector(".traffic-chart"), options)`.
Each view mentions `traffic-chart` **exactly once — that line.** There is no container: the chart
was replaced by `<x-ss.bar-chart>`, which uses no ApexCharts at all (`grep -c -i apex` = **0**),
and the initialiser was left behind. `querySelector` returns null and ApexCharts throws
`Element not found`, measured as an uncaught `pageerror` on every load.

**Severity: low, and measured rather than assumed.** The throw is inside an IIFE that is the last
statement of the AJAX `success` callback, and `$('#loader').fadeOut()` runs at the **top** of that
callback — so nothing user-visible is lost. It is console noise and dead code, not a broken screen.

**(b) Four chart fragments request a 404.** `reports/order_category_chart.blade.php:37`,
`order_brand_chart`, `order_product_chart` and `layouts/theme_layout.blade.php:157` load
`asset('public/assets/js/plugins/apexcharts.min.js')`. The document root **is** `public/`, so the
served path is `/assets/...`; the page requests both and the `public/`-prefixed one 404s. Since
`<x-ss.bar-chart>` needs no ApexCharts, the tag is vestigial.

**(c) `array_map('intval', ...)` destroys the money figures — independently confirmed.** Reported
by the report-guard chain on the seven-day window; confirmed here on a different window
(this-year), where the accessible table's first row reads `يناير | 1`. The wrapper sits in the
*callers* (`order_category_chart:28`, `order_brand_chart:28`, `order_product_chart:42`,
`order_chart_data:27`, `order_bar_chart_data:43`) while `bar-chart.blade.php:67` already casts to
int, so it is **redundant and destructive**: every fractional amount is truncated.

This one touches **displayed money**, so it is flagged for the owner rather than taken: repairing a
display truncation is not a change of financial policy, but on a money figure the distinction is
the owner's to confirm.

---

## 7. What this does not claim

* Chromium against the **sqlite dev fixture**, not production, and the **merchant** principal only.
* The withdrawal dialog is **read and dismissed**. No payout is approved, so the handler behind it
  is not exercised and this says nothing about it.
* `public/storage` in this container is a **flattened symlink** (a 62-byte git-tracked file holding
  a vendor production path), so every `/storage` asset 404s and the avatars render as broken
  images. The `alt` text still names them — which is what was measured — but the screenshots show
  placeholder boxes where a correct deploy shows photographs.
* The two report screens are verified as **rendering**; their ApexCharts defect above is reported,
  not repaired.
