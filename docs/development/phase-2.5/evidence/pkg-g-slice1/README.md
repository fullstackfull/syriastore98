# P2.5-PKG-G Slice 1 — finding a customer, and seeing what you found

Captured from the **running merchant admin** by `docs/development/phase-2.5/tools/render-matrix.cjs`
against `serve-dev.sh` on the `p2dev.sqlite` fixture, signed in as `merchant@example.test`.

| set | what it shows |
|---|---|
| `after/` | `/customer` and `/customer-grid`, unfiltered, four combinations |
| `after-filtered/` | the same two with `?field_name=No.+of+Orders&selected_name=More+Than&text_field=1` |
| `before-filtered/` | the same filtered request against the views **as they were at HEAD** |
| `BEFORE-AFTER.png` | the filter bar, before and after, Arabic·dark and English·light |

**24 rows, 0 not clean.** Every row asserts its own premise: HTTP 200, the document direction the
layout really emitted, and the `data-ss-theme` the design-system partial really set. A row whose
premise fails is reported as PREMISE-FAIL and is never read as a measurement.

The filtered request narrows the fixture's three customers to two (Omar 2 orders, Layla 55, both
> 1), so the captures show the filter doing its job rather than an empty screen.

---

## The thesis this slice acts on

> *This area has been repaired eleven times and designed as an area zero times.*

The generated matrix carries the proof: **20 customer surfaces — 11 REDESIGNED AND VERIFIED, 0
PREMIUM, 9 PENDING** — the largest merchant area in the matrix with no premium surface at all.
Each earlier wave fixed the element it was sent to fix and stopped at that element's boundary, and
the boundaries are where the area fails. The filter is the clearest case: `P2.5-R-02` and
`P2.5-R-07` were **correctness** repairs that deliberately touched mechanics only, so the one
control a merchant uses to find a customer was the only thing in the area never brought onto the
design system.

## What was measured before the change, and verified by hand

| claim | measured |
|---|---|
| `ss-` classes in the whole list view | **1** |
| `error:` arms against `success:` arms in the two views | **0 against 4** |
| operator and field words missing from `ar.json` entirely | **8** — `Includes`, `Excludes`, `Less Than`, `More Than`, `Equal`, `Before`, `After`, `No. of Orders` |
| `min-w-0` occurrences in the grid card, and its definition in either stylesheet | **3 used, 0 defined** |

The three words that *do* have Arabic entries — `Total Spend`, `Last active`, `Email` — still
rendered Latin, because no `__()` call ever reached them.

## What changed

1. **The bar is the design system's.** `.card`/`.card-body` and the dead `id="multiCollapseExample1"`
   are gone (that id appears in three views and nothing toggles any of them); the existing `<form>`
   carries `.ss-scope .ss-filterbar`, as `product/partials/filters.blade.php` already does.
2. **It says what is filtering.** `<x-ss.filter-summary>` built inline — deliberately *not* through
   `TableFilterMemory::describe()`, which only recognises `ss_`-prefixed names, while this screen's
   three keys are `field_name` / `selected_name` / `text_field` and must stay byte-identical.
3. **Every control has a visible label** — Field / Condition / Value. The two JS-injected controls
   had no id, no label and no `aria-label` at all.
4. **The words are translated, the values are not.** Option *text* goes through `__()`; the
   submitted *value* stays the English key `CustomerListFilter::apply()` matches on. Eight keys
   added to both language files.
5. **The hint the handler already sent is rendered.** `CustomFilter()` returned a `message`
   describing what the value control expects and the page discarded it.
6. **Clear appears only when something is on**, and is a text button rather than a red
   `ti-trash-off` for an action that deletes nothing.
7. **The grid got an `@empty` arm** — it had none, so a filter that matched nothing rendered a
   blank page. It reuses `dataTableEmptyState('customer-table')`'s strings so the list and the grid
   cannot drift into saying different things about the same situation.
8. **The grid prints its total.** `layouts/global-pagination.blade.php` gates its whole body on
   `hasPages()`, so a narrowed result — exactly when a merchant wants the count — printed none.

## Three defects found while doing it

* **`__()` on an array-valued parameter is a 500.** `?field_name[]=x` crashed the new summary.
  `CustomerGridFilterTest`'s three array-valued cases caught it immediately; every request read in
  the bar is now scalar-coerced first.
* **The hint was wrong for half the fields.** `CustomFilter()` gave Total Spend, No. of Orders and
  AOV the *last-active* message, and three of its four strings read "search **filed**". Visible in
  `before-filtered/` once the bar started rendering the message nobody had read before.
* **One extra `</div>` per view.** Removing three opening wrappers and two closers left each file
  closing an element its parent opened — caught by `BladeDivBalanceTest`, not by eye.

## One guard adapted rather than loosened

`CustomerGridFilterTest`'s Reset assertion is `/<a\s+href="[^"]*\/customer-grid"[^>]*id="clearfilter"/`
— it pins the attribute *order*. The new markup put `class` first. Rather than widen somebody
else's regex to suit new markup, the anchor puts `href` first. The guard is unchanged.

## Verified

`Unit` 1459 passed · the 7 test files covering these views 67 passed · `Contract` 32 passed ·
Blade compile check on both views (`php -l` does **not** expand Blade directives and reported
"no syntax errors" on a file that could not compile) · 24 premise-clean capture rows.

**No surface changes state in the inventory on this slice alone.** The four combinations are
captured for two surfaces, but PREMIUM is a judgement about a finished surface and Slices 2–5 still
touch both of these.
