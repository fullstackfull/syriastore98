# P2.5-WAVE-6 — the work-list re-anchored against the tree before any edit

`PHASE-2.5-REMAINING-AREAS-WORKLIST.md` says in its own preamble: *"Any work-list row quoted straight
into an editor will land on the wrong line."* This is that check, run over the WAVE-6 rows
(customers, delivery boys, stores — 25 findings, ~14 surfaces) **before** a single file was opened
for editing. Everything below is measured, not read off the list.

## Three findings are already CLOSED — the list is stale on them

| row | claim | measured |
|---|---|---|
| icon-coherence | `store/user-create.blade.php:6` ships `fas fa-robot` | **gone.** The line is `ti ti-wand`. `fa-robot` survives only in `plans/create.blade.php:24` and `plans/edit.blade.php:23`, which are outside this area |
| icon-coherence | `store/view-storelinks.blade.php:27` ships `far fa-copy` | **gone.** The line is `ti ti-copy`, and `fa-copy` appears **nowhere** in `resources/views/` |
| table-not-wrapped #7 | `customer/view.blade.php:47` is outside Wave 1's `.ss-dt-card-body` scope | **false.** Line 45 reads `<div class="card-body table-border-style ss-dt-card-body">` — the table is already wrapped |

**Measured across the whole area:** all 16 files of customers/stores/delivery-boys contain **zero**
Font Awesome icons. So two of the three icon-coherence findings are closed and the area is already
FA-free; only the third — the hand-drawn SVGs — remains.

## Four citations are WRONG and must be corrected before use

| cited | correct |
|---|---|
| `customer/timeline.blade.php:507` (the vendored `h2` status note) | **:516** |
| `customer/timeline.blade.php:74` (the inline SVG block) | **:83** |
| `store-syria-ds.css:4747-4748` (the scoped uppercase undo) | **:5251-5252** — 4747 is a dropzone dark-mode rule, nothing to do with case |
| "**seven** `fadeOut`-without-`fadeIn` sites" | **six.** `customer/grid` has 2, not 3 — I removed the third myself in P2.5-R-07 when the dead AJAX toolbar became a GET form |

**How the third one hid.** A text search for `text-transform: none` in `store-syria-ds.css` returns
lines 1428, 1437 and 1449 — **all three inside a comment block** documenting a rule P2.5-WAVE-6
deleted. The work-list's own preamble warns about exactly this ("the stylesheet carries long
rationale blocks that quote the rules it removed"). Found by blanking comments while preserving line
structure and searching only live CSS: **five** live `text-transform` declarations in the file, and
the one that matters is

```css
/* Column headers: the case transform goes, weight and colour take over. Both render in Arabic. */
.ss-dt-card-body table.dataTable > thead > tr > th {
    text-transform: none !important;      /* :5252 */
```

So the load-bearing claim **stands** — the undo really is scoped to `.ss-dt-card-body`, and wrapping
a table fixes the Arabic uppercase asymmetry as a side effect — but the pointer was 500 lines out.

## What remains, verified verbatim

**Three tables, not four** (#7 is already wrapped): `store/view-storelinks.blade.php:2`
(`.table-responsive.ecom-data-table`, no `.card-body` at all), `store/custom_domain.blade.php:39`
and `store/subdomain.blade.php:38` (both `.card-body table-border-style` at :36, no
`ss-dt-card-body`). The vendored uppercase that reaches Arabic merchants is
`public/assets/css/style.css:27493-27498` — `.table thead th { ... text-transform: uppercase; }`,
carried identically by `style-rtl.css`. **Do not edit the vendored sheet.**

**The type-scale group, each read at its line:**

| site | markup | consequence |
|---|---|---|
| `store/custom_domain.blade.php:37` | `<h6 class="text-muted text-sm my-2">` on a ~300-character cPanel/Plesk instruction | the screen's only instructions render at 12px. `x-ss.help` exists (`components/ss/help.blade.php`) |
| `customer/view.blade.php:72` | `class="h6 text-sm font-weight-bold mb-0"` on the order Date cell | **measured:** `.text-sm { font-size: 0.75rem !important }` at `style.css:15112` beats `.h6 { font-size: 0.9375rem }` at `:263`, so `.h6` is inert and the column renders 12px where 15px was intended |
| `store/plan.blade.php:7` | `badge f-12 p-2 d-block h6 mb-0 bg-warning` | two size mechanisms on the plan name |
| `store/plan.blade.php:17` | `d-block h6 mb-0 f-14` | a third, on the quota labels |
| `customer/timeline.blade.php:20-21` | hand-rolled `h6` label + `h3` value | `x-ss.stat` exists (`components/ss/stat.blade.php`) and is not used |

**The SVG group, measured:** `customer/timeline.blade.php` carries **14 inline `<svg>` elements**,
**42 `fill="black"`**, on **six distinct viewBoxes** (`0 0 360 513`, `0 0 369 522`, `0 0 458 446`,
`0 0 460 460`, `0 0 480 454`, `0 0 512 480`). Every one renders at `width="13" height="14"`, so what
differs between them is internal scale and apparent stroke weight, not box size.

**The empty-state group:** `@forelse`/`@empty` appears **zero times** in the entire
customers/stores/delivery-boys area — confirmed, as claimed.

## Net effect on the package

**25 findings → 22.** Three closed, four citations corrected, one count corrected. None of this
required opening a file for editing, and all of it would have produced a wrong edit if the rows had
been quoted straight into an editor.
