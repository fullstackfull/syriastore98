# P2.5-CLOSURE — AJAX fragments, row cells and the finance modals

`tools/shoot-fragments.cjs`, **29 surfaces × 4 combinations = 116 captures**. Eleven of them are new
in this wave and each closes a coverage-matrix row that had been PENDING.

Final state: every surface captured in all four combinations, **0 contrast findings and 0 nameless
findings**. The only remaining findings are `latin` on the Arabic runs, and they are the fixture's
own product data — variant names `S-Natural`, `M-Indigo` and the option `Indigo` are genuinely
Latin because that is what the fixture's attributes are called. Those are not defects.

**Two honest limits on the paragraph above.**

1. **It said 26 surfaces.** The directory holds 116 PNGs = 29 distinct prefixes, and the harness
   declares 29 slugs. 26 matched nothing; the number is 29.
2. **`after/measurements.json` records 6 surfaces, not 29.** It is written per run, and the last
   runs here were filtered (`--only finance`, `--only variant-panels`), so the earlier surfaces'
   findings were overwritten. The pictures are all present and each surface's run was read when it
   was taken, but the machine-readable record is not preserved for 23 of them. Treat "0 findings" as
   **evidenced** for the 6 in that file and **attested but not preserved** for the rest. A single
   unfiltered re-run would fix it and has not been done.

---

## 1. Every one of these rows was PENDING for a mechanical reason

| surface | the gap | how it was closed |
|---|---|---|
| `flash-sale-status-cell` | **no selector.** `text-center` is shared and DataTables adds no per-column class, so a component capture had nothing to clip to. | `FlashSaleDataTable` gained `ss-flashsale-status-cell`, a capture hook with **no CSS rule anywhere**, so it cannot change how the column looks. |
| `order-note-author` | **no data.** All 56 fixture `order_notes` carried `created_by = NULL`, and the partial renders an author if and only if one was recorded — so it rendered nothing on every row. | three notes seeded covering the partial's three non-empty outcomes. |
| `product-sales-body` | **wrong classification.** It was nearly filed OUT OF SCOPE because both report handlers `return response()->json($return)` and never a view. | that reading was wrong: `$return['html']` comes from `view(...)->render()` (`ReportController:1822`, `:2294`), so the JSON *carries* the fragment and `.chart_data` puts it on screen. |
| 3 × `variant-panels-*` | **no journey.** These are reached by a 2–4 step sequence, and the last control does not exist until the previous step's response renders it. | the harness gained `steps:`. |
| 5 × `finance-*` | **no data.** `store_order_financials` 0 rows, `store_withdrawals` 0 rows, wallet balances all 0 — so three modals announced "Available balance: 0.00", one surface had no form in the DOM at all and two had no rows. | seeded: a real balance, one unpaid + one paid financial, one pending + one approved withdrawal. |

The three authored notes are worth naming, because they photograph the partial's whole contract
rather than its happy path: a **named** tenant author, a **withheld** platform-operator author (the
partial refuses to name an author outside the viewer's tenant — the tenancy limb, and the one worth
a picture), and an author **no user row holds** (no foreign key exists on that column, deliberately,
so the state is reachable in production). The 56 unauthored system notes already there supply the
fourth case.

## 2. Defects found by driving, and fixed

**The variant panel rendered NOTHING when empty — in all three views.** Measured on
`/product/1/edit`: tick "use for variation" and the container goes to `root display=block
height=0`, holding only `META`, `SCRIPT`, `TEMPLATE`, `SCRIPT`, `SCRIPT` — **17,186 characters of
`textContent` and an `innerText` of zero**. A merchant with a variant table on screen ticks a box
and the table is replaced by blank space, with nothing saying why. The cause is one defect in three
places: a top-level `@if` with no `@else` in `attribute_combinations.blade.php:1`,
`attribute_combinations_data.blade.php:1` and `attribute_combinations_edit.blade.php:2`. The
condition being false is an ordinary intermediate state, not an error. A shared empty state now
renders for all three.

**Six icon-only delete anchors had no accessible name.** Their only content is
`<i class="ti ti-trash">`, so a screen reader announced "link" for the control that destroys a
variant — 8 per combination on the saved panel. Each now names *which* variant, through the same
`VariantRemovalPolicy::consentToken()` the dialog and the request already carry.

**Four unnamed close buttons** on the finance modals (`.btn-close` paints its glyph with a
background image and carries no text), labelled the way `layouts/app.blade.php:720` already does.

**Three contrast failures, each measured in the running application:**

| element | before | after |
|---|---|---|
| `.alert-info` on the finance modals | `#257980` on `#d8f4f7` → **4.41:1** | `#24757c` → 4.655:1 |
| `.alert-warning` on the finance modals | `#996111` on `#ffecd2` → **4.46:1** | `#965f11` → 4.612:1 |
| the withdrawal reject submit, **dark** | `rgb(232,234,237)` on `rgba(255,174,189,0.41)` → **1.04:1** | `ss-btn--danger`, whose fg/bg flip together |

Dark mode measured **identical to light** for the two alerts, because `.modal-content` stays white
in dark on these screens (the F126 condition). So one value repairs both and a dark-only override
would have been dead code.

The 1.04:1 is the interesting one. `.btn-danger`'s own `--bs-btn-color` is `#d80027`, and the red
never reached the text because **that modal is nested inside a `<td>`** (`withdrawals.blade.php:244`)
and the dark-mode table cascade (`rgb(157,163,180)` down the whole ancestor chain) beat it. The
sibling `.btn-danger` in `/finance`'s deduct modal, outside any table, measured `rgb(26,29,35)` on
the same pink and passes — same class, same theme, different answer, which is why it was measured
rather than reasoned about.

**A dark-mode rule that had never matched the page it was written for.** The P2.5-PKG-B variant
heading fix was scoped to `.attribute_option_data`, which on `/product/create` is a class on the
attribute `<select>` — and a `<select>` has no accordion inside it. So the fix was live on the saved
panel and absent on the create panel, where the closure capture measured the untouched 4.03:1. The
selector now also names `.attribute_options_datas`, the class the accordion *item* carries in all
three views.

## 3. A harness defect that reported evidence it had not produced

`page.screenshot({ clip }).catch(() => {})` with the tally running regardless. The three
variant-panel surfaces reported `combos=4` across several runs while
`find evidence -name '*variant-panels*'` returned **zero files**. The clip sat at `y: 2025` against
a 900px viewport — the panel is ~2,000px down a long product form — and Playwright refused it:
*"Clipped area is either empty or outside the resulting image"*.

Two fixes, and the first matters more than the second:

1. **a refused screenshot is now a premise finding** and the combination is not counted, so the
   summary line and the directory agree. The same swallow was fixed in `shoot-modals.cjs` and
   `shoot-row-actions.cjs`.
2. **the container is scrolled into view before measuring**, since the clip is viewport-relative.
   Scrolling rather than `fullPage` keeps the box small and is what a merchant does to reach the
   panel.

**No coverage claim was ever falsified by this**, and that is worth stating plainly: the matrix
generator's gate is the filesystem, not the harness summary — `build-coverage-matrix.py` refuses any
claim whose four files are absent, and `--check` passes with all 249 verified rows' captures
present. The harness could lie to a reader; it could not lie to the matrix.

## 4. Reproducing

```bash
docs/development/phase-2.5/tools/serve-dev.sh
DB_CONNECTION=sqlite DB_DATABASE=$PWD/database/p2dev.sqlite CACHE_STORE=file SESSION_DRIVER=file \
  php docs/development/phase-2.5/tools/grant-dev-finance-permissions.php
DB_CONNECTION=sqlite DB_DATABASE=$PWD/database/p2dev.sqlite CACHE_STORE=file SESSION_DRIVER=file \
  php docs/development/phase-2.5/tools/seed-dev-closure-rows.php
node docs/development/phase-2.5/tools/shoot-fragments.cjs --out <dir>
```

Never `export` those variables — `serve-dev.sh` records that exporting them pointed the whole test
suite at the fixture file twice. Prefix the single command instead.
