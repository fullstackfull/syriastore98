# P2.5-WAVE-6a — the merchant DataTable's two empty states

Eight captures, `deviceScaleFactor: 2`, produced by `tests/Browser/p25-datatable-empty.mjs` against
pages rendered by `tests/Browser/render-datatable-empty.php`.

| | light | dark |
|---|---|---|
| **nothing yet** (table holds no rows) | `custom-domain-en-light.png` · `custom-domain-ar-light.png` | `custom-domain-en-dark.png` · `custom-domain-ar-dark.png` |
| **no results** (a search matched nothing) | `no-results-en-light.png` · `no-results-ar-light.png` | `no-results-en-dark.png` · `no-results-ar-dark.png` |

---

## What was wrong

`simple-datatables` writes its own empty row — `<tr><td class="dataTables-empty" colspan="N">` —
and the colspan was always right. Two things about it were not, both measured:

* **the label was the library's hard-coded English.** `noRows: "No Data Found"` and
  `noResults: "No results match your search query"` were overridden **nowhere** in this repository,
  so an Arabic merchant read English on every empty table in the product. Measured: **17
  `.dataTable` tables across 14 first-party views, plus two in vendored modules** that render
  inside the same layout — 19 in all, every one constructed at a single site,
  `public/js/custom.js:12-18`. `new simpleDatatables.DataTable` appears nowhere else in
  `resources/views/` or `packages/workdo/`.
* **`td.dataTables-empty` was styled in neither first-party sheet**, so the cell inherited the row
  treatment: body-size text, start-aligned, body colour.

## Why the fix is not `@forelse`/`@empty`

That is what the WAVE-6 work-list row ("seven headers over an empty body",
`PHASE-2.5-REMAINING-AREAS-WORKLIST.md` §3 WAVE-6) implies, and it is **wrong about the symptom** —
the four tables it names are not bodiless; the library writes a message into them. It would also
have been actively wrong as a fix: `custom.js` initialises every `.dataTable`, and the library
**rebuilds the tbody from the rows it parsed**, so a hand-written empty row becomes a *data* row —
counted in "Showing 1 to 1 of 1 entries", sortable and searchable.

The library already had the mechanism. It needed words and a treatment.

## What changed

| file | change |
|---|---|
| `resources/views/layouts/app.blade.php` | seven `data-ss-dt-*` attributes on `<body>`, carrying the labels through `__()` — the same bridge `data-ss-label-table-options` on that tag and the four `data-ss-*-text` strings in `command-palette.blade.php` already use |
| `public/js/custom.js` | `ssDataTableLabels()` reads them and passes them at **all three** construction blocks (`.dataTable`, `.dataTable-5`, `.dataTable-6`); the empty-state label is the project's own `.ss-empty` markup, which the library injects as HTML |
| `public/css/store-syria-ds.css` | the cell, confined by `:has(.ss-empty)` |
| `resources/lang/ar.json`, `en.json` | five new strings, both files |

## A fourth defect, found by an independent review of this change

The first version used `<meta name="dt-*">` in `<head>`, reasoning from `base-url`. **That is the
wrong precedent** — `base-url` and `csrf-token` are transport facts, not user-facing copy — and
this codebase had already answered the localised-copy question twice, both times with a
`data-ss-*` attribute on the consuming element: `data-ss-label-table-options` on the same `<body>`
tag (read at `store-syria-ds.js:1683`) and four `data-ss-*-text` strings at
`command-palette.blade.php:35-38`. Worse, the change made an existing comment **false** — the one
at `app.blade.php` calling `data-ss-label-table-options` *"the one localised string the
design-system script needs"*. The labels now sit on `<body>` and that comment is corrected.

**This did not fix the test collision and was never going to.** Any in-document bridge puts the
copy in the document, so the Kanban guard's whole-document substring check collided either way.
The guard itself had to be the fix; see below.

## The three defects this capture caught that the tests did not

Each survived a passing Unit test **and** a passing DOM probe. This is the entry worth reading.

1. **`padding: 0` did not win.** The plugin's `.dataTable-table > tbody > tr > td` is specificity
   0-1-3 against `td.dataTables-empty`'s 0-1-1, so the cell computed `8px 10px` while the sheet
   declared `0` — and the Unit test asserting the declaration passed. The browser check now asserts
   the **computed** value, and the Unit test asserts the selector carries the prefix that makes it
   win.
2. **"Search..." and "entries per page" were still English on the Arabic page**, directly above a
   now-Arabic empty state. The probe reported the empty state correct and said nothing about the
   furniture around it. Found by looking at `custom-domain-ar-dark.png`.
3. **`ti ti-search-off` does not exist in the bundled Tabler font**, so the no-results state drew an
   **empty circle**. The `<i>` was present with the right class — a class matching no `:before`
   rule is invisible, not absent — so every assertion passed. Now `ti-zoom-cancel`, and a Unit test
   asserts every icon name the builder uses resolves in the shipped font, while the browser check
   asserts the glyph has a non-zero width.

## One defect this harness manufactured, and then stopped manufacturing

The first version of the harness page loaded only the plugin sheet and the design system, and the
search box rendered **white on the dark card**. That looked exactly like a dark-mode defect until it
was traced: `custom.css:4823` carries a dark arm for `.dataTable-input`, and the harness simply was
not loading `custom.css`. `render-datatable-empty.php` now mirrors the layout's real four-way
stylesheet branch (`layouts/app.blade.php:201-215`). **An evidence harness that omits a stylesheet
does not reveal a defect, it invents one** — and a reported defect that is not real costs the same
review time as a real one.

## What is real in these captures, and what is not

**Real:** the table markup (extracted verbatim from `resources/views/store/custom_domain.blade.php`
and compiled by the real Blade compiler), the label strings (the real translator, in the locale
being rendered), and the scripts and stylesheets (the shipped files, the same bytes a merchant
gets).

**A stand-in:** the dashboard chrome — sidebar, header, signed-in merchant — which needs a database
and an authenticated principal. The page carries `<html dir>` and `data-ss-theme`, which is what the
real layout publishes and what every `--ss-*` token and RTL rule reads, so the table and its empty
state resolve exactly as they do in the product. **It is a table on a page, not a screenshot of the
whole admin**, and it should not be read as one.

## Bite

Both browser checks were proved by reverting the fix:

```
icon back to ti-search-off   -> FAIL ... has zero width — that glyph is not in the bundled font
padding back to td.dataTables-empty -> FAIL ... computed 14.4px 12px, not 0px
```

Six mutations against the Unit test were all caught; see the commit message.
