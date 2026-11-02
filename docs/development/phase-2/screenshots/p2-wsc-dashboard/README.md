# WS C — merchant dashboard correctness, before and after

Mandatory Visual Evidence for the WS C dashboard slice, per the Phase 02 Master Execution Prompt.

| | |
|---|---|
| harness | `docs/development/phase-2/tools/shoot-wsc-dashboard-live.cjs` (Playwright, Chromium) |
| instance | the real application at `http://127.0.0.1:8899`, SQLite `database/p2dev.sqlite`, invented fixture data only |
| configurations | **8** — desktop 1440×900 and mobile 390×844 × **English LTR / Arabic RTL** × **light / dark** |
| before | the working tree at `6df1ea07` (slice 1) and at `39526569` (slice 2), the WS C edits stashed — a genuine, reproducible before-state in both cases |
| **slice 1 before** | **52 of 84 checks FAIL** — `before-report.txt` |
| **slice 2 before** | **48 of 156 checks FAIL** — `before-slice2-report.txt` |
| **after** | **164 of 164 checks pass** — `after-report.txt` |

> **The `before-*.png` set was shot before the theme control was fixed (F255), so its "dark"
> images are light.** `set-admin-preview-state.php` wrote `cust_darklayout` only when a row
> already existed and printed a success line regardless; a rebuilt `p2dev.sqlite` has no
> `settings` rows at all, so every "dark" run of every dashboard harness had been measuring the
> light theme and saying otherwise. The `before-slice2-*` and `after-*` sets are shot with the
> fix in place and are genuinely four light and four dark. Said here rather than quietly
> re-shooting, because the slice-1 before-state is the record of what the defects looked like and
> re-rendering it now would silently change what "before" meant.

Each configuration produces `*-full.png` (full page) plus crops of `*-attention.png`,
`*-storage.png` and `*-sales-cards.png`.

## What the pictures show

### 1. The Arabic attention rows had the wrong grammatical number

`before-desktop-ar-light-attention.png` → `after-desktop-ar-light-attention.png`

| | 1 pending order | 4 out of stock |
|---|---|---|
| before | `1 طلبات بانتظار التأكيد` — **plural**, for one | `4 منتج نفد من المخزون` — **singular**, for four |
| after | `1 طلب بانتظار التأكيد` | `4 منتجات نفدت من المخزون` |

`resources/lang/ar.json` stored these as two segments, `singular|plural`, which is the shape
*English* needs. `MessageSelector::getPluralIndex('ar', $n)` returns six indices, and `choose()`
falls back to `$segments[0]` for any index it cannot find — so **every count a merchant can see
was wrong**: 1 rendered the plural, and 2, 3, 11 and 300 all rendered the singular. The fix uses
Laravel's own explicit-range syntax (`{1} …|{2} …|[3,10] …|[11,99] …|[100,*] …`), which
`extract()` resolves *before* it ever asks for a plural index.

The harness reads the phrase out of the **painted row** and compares it against the form the
count requires, so it measures the page rather than the translation file.

### 2. The storage card stated a quota it did not have

`before-desktop-en-light-storage.png` → `after-desktop-en-light-storage.png`

Before: **`Storage Status (MB / -1MB)`** above a donut reading **`0% Used`** — an empty used
figure, a negative quota, and a percentage of nothing. `-1` is WorkDo's *unlimited* sentinel
(`Utility::updateStorageLimit()` refuses an upload when
`$plan->storage_limit <= $total_storage && $plan->storage_limit != -1`), and the card printed it
as a number.

After: three states, three sentences — `12MB / 500MB` with the donut when there is a quota,
`0 MB used of unlimited storage` with no donut when the plan is unlimited, and `0 MB used` when
no limit is recorded at all. A percentage of unlimited is not a number, so the donut does not
render for it.

### 3. The order list was on the page twice, and one copy had no gate

`before-*-full.png` → `after-*-full.png`

The overview's "Latest orders" and WorkDo's "Recent Orders" iterated the **same `$new_orders`
collection**. The overview wraps its copy in a `Manage Order` gate; the WorkDo copy had none, so
a staff member the overview had just decided must not see orders read the order list a few
hundred pixels lower. The duplicate is deleted. Measured in every configuration as
`overview copies=1, workdo copies=0`.

### 4. Six duplicate element ids, and ten tabs naming a panel that does not exist

Before, in every configuration:

- `pills-seo-tab` appeared **six** times (Week/Month/Year, in both tab lists)
- `modelCommanModelLabel` appeared **twice** — the shared layout's two modals both labelled
  their title with it, so the nested dialog's accessible name resolved to the *other* dialog's
  (empty) title
- **10 of 10** tabs named an `aria-controls` target that is not an element on the page
- each tab list reported **5 of 5** tabs as `aria-selected="true"`

After: no duplicate id anywhere on the page, every `aria-controls` resolves, exactly one selected
tab per list.

## What this harness cannot see, stated rather than implied

**The `/100` money fix is not visible here.** The twelve queries behind "Top Category — By
Sales" and "Top Brand — By Sales" use `SUBSTRING_INDEX` and `CURDATE`, which SQLite does not
have, so on this instance those two cards can only ever render *No Data Found* — which is why
`*-sales-cards.png` shows an empty card in both the before and after sets. The harness excludes
that one endpoint from its 500 check and names it explicitly, so a 500 anywhere else still fails
the run.

That figure's evidence is therefore elsewhere, on a real MariaDB —
`tests/Support/Security/bin/verify-dashboard-top-sales-scope-on-mysql.php`, 15 checks — and
**that script is why one of this slice's changes was reverted before it shipped.**

WS C first removed the `/100` in `order/brand_category_chart.blade.php`, on the reasoning that
`orders.final_price` is a major-unit decimal everywhere else it is displayed. The MariaDB run
refuted it: the twelve queries cross-join a 1..100 series with nothing bounding it to the number
of elements in `orders.product_id`, so a **single-product order joins one hundred times** and its
price is summed a hundred times. The division is a compensation for that fan-out, not a currency
conversion, and removing it would have multiplied every figure on both cards by 100. The division
is restored, and `DashboardFigureHonestyTest::test_the_sales_figure_keeps_its_compensation_for_the_query_fan_out`
is the ratchet that stops it being "corrected" again.

Worth stating plainly: **no amount of browser evidence could have caught that.** These cards
render empty on SQLite in all eight configurations, in the before set and the after set alike.
The residual defect — the compensation is exact only for single-product orders — is recorded as
**F242** and left for the owner, because fixing it changes a figure the cards report.

What the MariaDB script *does* prove about this slice is the tenant predicate (**F244**): a store
whose own revenue was 4,500 was being shown **14,400**, two thirds of it another tenant's money,
because the queries filtered `categories.store_id` and nothing else while `orders` carries no
global scope. The check fails if the predicate's presence and absence produce the same figure, so
it cannot pass against a fixture that cannot tell them apart.

**The quota branch of the storage card is not visible here either**: the dev fixture's plan
carries the unlimited sentinel, so this instance exercises the *unlimited* branch.
`DashboardFigureHonestyTest` covers all three branches.

## Slice 2 — the three regions the dashboard did not have

Workstream C lists eight things a merchant home dashboard should answer. Five were built in
P2-02. These are the three that were not.

### Inventory alerts — what is about to run out, not only what has

The attention block reported what had **run out** and nothing about what was **nearly** out,
while the product list has had an `?ss_stock=low` filter since P2-03-9. The count is
`ProductDataTable::scopeToLowStock()` — the list's own predicate, extracted rather than copied,
because this repository has already watched the stock badge and the stock filter drift apart at
the boundary. A count that disagrees with the list it links to is worse than no count, because
the merchant follows it.

Measured in Arabic: `5 منتجات على وشك النفاد`, with the link carrying `ss_stock=low`.

### Store overview — which store these figures are about

The page never said. A merchant with two stores read the numbers without being told whose they
were. It is a page-header subtitle, not a card, because the store's name already appears twice
further down and this slice deleted a duplicate order list for being a duplicate.
`@hasSection('page-subtitle')` means the other 333 screens render exactly the markup they
rendered before.

### Important store notifications — what has not been seen

Filled by **the same single fetch the bell already makes**, so there is one request and one
server-side authorization decision rather than two. Unread only, at most three, and **absent**
when there are none — the same rule the attention block follows, because a permanent "0
notifications" panel teaches merchants to stop looking.

Measured per configuration: it fills, becomes visible, shows at most three, announces a sentence
rather than a bare number through `role="status"`, and every colour in it meets AA from the
painted pixels — **title 16.06, rows 16.06, detail 5.94, link 5.68** in light; **11.1 / 11.1 /
6.03 / 7.75** in dark.

### Three defects this slice's own work produced, each caught by measurement

1. **The digest borrowed the attention block's classes.** The Arabic grammar census immediately
   reported three English notification titles as attention rows with unrecognised phrases. Two
   regions, two names now — and the census is scoped to `.ss-attention` as well.
2. **An English notification inside the Arabic card put its full stop at the start of the line.**
   Visible in `after-desktop-ar-dark-digest.png` before the fix. `dir="auto"` per row plus
   `unicode-bidi: isolate`, because a notification's text is written by whatever produced the
   event and a single row can be LTR inside an RTL page.
3. **`.ss-link` was a class that did nothing** (F256). The design system styles
   `a:not([class])`, so the obvious spelling silently opted out of the DS colour and the digest's
   link rendered at **1.68:1** against the dark card. Its missing focus ring was found by
   *biting* the region's hidden state, not by reading it.

## Reproducing

```bash
# the preview instance (see PHASE-02-HANDOFF.md §6)
php artisan serve --host=127.0.0.1 --port=8899 &

node docs/development/phase-2/tools/shoot-wsc-dashboard-live.cjs \
    http://127.0.0.1:8899 docs/development/phase-2/screenshots/p2-wsc-dashboard after

# the before-state, for as long as the slice is a single commit away
git stash push -- resources/views/dashboard.blade.php \
    resources/views/dashboard/overview.blade.php \
    resources/views/order/brand_category_chart.blade.php \
    resources/views/layouts/app.blade.php \
    resources/lang/ar.json resources/lang/en.json \
    app/Http/Controllers/HomeController.php
php artisan view:clear
node docs/development/phase-2/tools/shoot-wsc-dashboard-live.cjs \
    http://127.0.0.1:8899 docs/development/phase-2/screenshots/p2-wsc-dashboard before
git stash pop && php artisan view:clear
```

Once this slice is committed the before-state is reproduced by checking the seven files out at
`6df1ea07` instead of stashing them; the committed `before-*.png` and `before-report.txt` are the
record either way.

## One false reading caught in the harness itself

The first run's check for the duplicate order list looked for a heading matching
`/Recent Orders|الطلبات الأخيرة/`. The shipped Arabic translation is **`أوامر حديثة`**, so the
Arabic half of the before-run reported *zero* WorkDo copies against a page that had one — the
check passed for the wrong reason in four of eight configurations, and the before-run's failure
count was 32 rather than the true 36. Corrected by reading the value out of `ar.json` rather than
guessing it, and both sets were re-shot.
