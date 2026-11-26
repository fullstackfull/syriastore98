# P05-16 / GAP-164 — the six Phase 05 surfaces in four combinations

**Captured 2026-11-26.** 24 loads: six surfaces × {en-LTR, ar-RTL} × {light, dark}. Every row
clean; nothing is dispositioned away.

This is the evidence the per-slice acceptance condition in
`../../PHASE-05-BILINGUAL-APPEARANCE-GATE.md` points at, and
`tests/Unit/Governance/Phase05BilingualAppearanceGateTest` reads `render-matrix.json` directly
rather than this prose.

## How it was produced

```
php artisan serve --host=127.0.0.1 --port=8917          # against a COPY of the dev fixture
node docs/development/phase-2.5/tools/render-matrix.cjs \
  --base http://127.0.0.1:8917 --out <dir>/merchant \
  --paths /analytics,/finance,/finance-reports,/dashboard --email merchant@example.test
node docs/development/phase-2.5/tools/render-matrix.cjs \
  --base http://127.0.0.1:8917 --out <dir>/operator \
  --paths /platform/analytics,/dashboard --email operator@example.test
```

Viewport 1440×900, `deviceScaleFactor: 1`, `APP_DEBUG=false` so no debug bar sits over the page
(the first run of this capture carried one, and it covered the foot of every image).

**The run used a COPY of `database/p2dev.sqlite`, not the fixture itself**, because
`set-admin-preview-state.php` rewrites the principal's theme, direction and language between rows
and another session was serving the real fixture on port 8899 at the time. A harness that flips a
shared fixture under a concurrent run produces a false reading for somebody else. The copy also
carried `CACHE_STORE=database`, so the file cache the other session reads was never touched.

`grant-dev-finance-permissions.php` was run against the copy first: without it `/finance` and
`/finance-reports` answer **403** in all four combinations, which is correct behaviour and not a
defect — `Utility::addNewData()` creates no `Finance` permission rows, so a database built from
source has none. Both screens were captured as 403 before that, and those eight rows are not kept.

## Two principals, two directories, and why they are not one

`/dashboard` is in BOTH runs and is **two different screens**: `HomeController@index` dispatches on
`users.type` to `resources/views/dashboard.blade.php` for a merchant and
`resources/views/superadmin/dashboard.blade.php` for the operator. The screenshots would collide by
name in one directory, and the capture record carries no principal field — so the directory is what
says whose session a row is. Keep them separate.

## Per-shot premise, from the harness's own record

Every row in `render-matrix.json` carries its HTTP status, the direction the layout really emitted
against the one asked for, the `data-ss-theme` attribute, the stylesheet that actually loaded, the
page title and `h1`, and any console error. All 24: **HTTP 200, `dir` as asked, no console error,
no premise failure.**

The stylesheet is a four-way discriminator on this admin, which is what makes "the run really
changed state" checkable rather than asserted:

| | light | dark |
|---|---|---|
| en / ltr | `style.css` | `style-dark.css` |
| ar / rtl | `style-rtl.css` | `rtl-style-dark.css` |

And the heading is translated on every Arabic shot — `التحليلات`, `المالية`, `تقرير مالي`,
`لوحة التحكم`, `تحليلات المنصّة` — which is the one check that distinguishes a bilingual screen
from a screen that merely renders under `dir="rtl"`. The gate asserts it: an Arabic `h1` equal to
the English one fails.

All 24 PNGs are **distinct by SHA-1**. That is not pedantry: the first P5-R8 run produced the
operator's four images byte-identical in light/dark pairs — four pictures about to be committed as
eight states.

## What this does NOT establish

- **It is not a contrast audit.** `render-matrix.cjs` records what rendered; it computes no
  ratios. `audit-merchant-screens.cjs` is the tool that does, and the P5-R8 run of it over
  `/analytics` and `/platform/analytics` found **zero** contrast failures in all four combinations
  (`../p5-r8-four-combinations-2026-11-24/`). `/finance`, `/finance-reports` and the two dashboards
  were **not** re-audited for contrast here, and this directory does not claim they were.
- **It is not human review.** Whether the Arabic reads like Arabic, and whether a label describes
  its control, are not measured and not claimed — the same limit M7 records for the storefront.
- **It is the dev fixture**, two tenants and a handful of orders, so several figures show their
  empty or small state. A populated-state capture is not claimed.
- **The three shared-chrome tap targets** recorded on 2026-11-24 (sidebar search 188×39, rail
  toggle 30×30, breadcrumb link 35×20) are unchanged and are still an owner item. They belong to
  the layout every accepted Phase 2.5 surface renders, not to any Phase 05 screen.
