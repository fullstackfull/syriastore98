# P5-R8 — the two Phase 05 analytics screens in four combinations

**Captured 2026-11-24** against `database/p2dev.sqlite` at `http://127.0.0.1:8899`, viewport
1440×900, `deviceScaleFactor: 2`, full page.

| file | surface | principal | language / direction / theme |
|---|---|---|---|
| `analytics_en_ltr_light.png` | `/analytics` | `merchant@example.test` (type `admin`) | en / ltr / light |
| `analytics_ar_rtl_light.png` | `/analytics` | merchant | ar / rtl / light |
| `analytics_en_ltr_dark.png` | `/analytics` | merchant | en / ltr / dark |
| `analytics_ar_rtl_dark.png` | `/analytics` | merchant | ar / rtl / dark |
| `platform-analytics_en_ltr_light.png` | `/platform/analytics` | `operator@example.test` (type `super admin`) | en / ltr / light |
| `platform-analytics_ar_rtl_light.png` | `/platform/analytics` | operator | ar / rtl / light |
| `platform-analytics_en_ltr_dark.png` | `/platform/analytics` | operator | en / ltr / dark |
| `platform-analytics_ar_rtl_dark.png` | `/platform/analytics` | operator | ar / rtl / dark |

**Per-shot premise, printed by the capture run rather than assumed.** Each shot recorded its own
HTTP status, the rendered `documentElement.dir` and the computed `body` background:

```
analytics            en/ltr/light  HTTP 200  dir=ltr  bg=rgb(249, 250, 251)
analytics            ar/rtl/light  HTTP 200  dir=rtl  bg=rgb(249, 250, 251)
analytics            en/ltr/dark   HTTP 200  dir=ltr  bg=rgb(34, 36, 44)
analytics            ar/rtl/dark   HTTP 200  dir=rtl  bg=rgb(34, 36, 44)
platform-analytics   en/ltr/light  HTTP 200  dir=ltr  bg=rgb(249, 250, 251)
platform-analytics   ar/rtl/light  HTTP 200  dir=rtl  bg=rgb(249, 250, 251)
platform-analytics   en/ltr/dark   HTTP 200  dir=ltr  bg=rgb(34, 36, 44)
platform-analytics   ar/rtl/dark   HTTP 200  dir=rtl  bg=rgb(34, 36, 44)
```

## THE FIRST RUN OF THIS CAPTURE WAS WRONG, AND ITS OWN PREMISE LINE IS WHAT CAUGHT IT

The operator's four images came out **byte-identical in light/dark pairs** — `567411` bytes twice
and `571512` bytes twice — and every one reported `bg=rgb(249, 250, 251)`. Four pictures were about
to be committed as eight states. That is the defect
`docs/development/phase-2.5/tools/check-distinct-surface-captures.py` exists to catch, and the note
inside `set-admin-preview-state.php` records the same failure happening once before for a different
reason: *"every 'dark' run of every dashboard harness silently measured the LIGHT theme and
reported it as dark: eight configurations that were really four."*

**The cause, measured rather than guessed:**

- `layouts/app.blade.php:2-7` takes `getAdminAllSetting()` for a type-`admin` principal and
  `getSuperAdminAllSetting()` for everyone else.
- `helper.php:753` — `getSuperAdminAllSetting()` reads
  `Setting::where('created_by', $operator->id)->where('store_id', $operator->current_store)`.
- The fixture operator's `current_store` is **NULL**, and Laravel compiles `where('store_id', null)`
  to `"store_id" is null` — confirmed with `->toSql()`, not assumed.
- `set-admin-preview-state.php` wrote its row as `(store_id = 1, created_by = <first type-admin>)`,
  which that query cannot see. So the operator's layout found no `cust_darklayout` at all and the
  `== ''` fallback left it light.

**AND IT IS NOT A PRODUCT DEFECT, which was checked before anything was reported.** An
operator-owned row was inserted by hand — `created_by = 1`, `store_id = NULL`,
`cust_darklayout = 'on'` — and both operator screens then rendered `data-ss-theme="dark"`,
`data-ss-theme-source="host"`, `bg=rgb(34, 36, 44)`. The operator CAN have dark mode; the dev
fixture simply had no operator-owned settings row, and the harness could not create one. The
probe row was then deleted so that the fixed tool had to create it itself, which it did.

`set-admin-preview-state.php` now derives the row's owner and store from the principal whose chrome
it is setting. A merchant's behaviour is unchanged by construction: the old owner was "the first
type-`admin` user", which for a merchant principal is that merchant, and `$storeId` is still the
caller's argument. Every pre-existing harness passes a merchant e-mail.

## What the measurement says about the two screens

`audit-merchant-screens.cjs` over the same four combinations, which composites contrast against the
first opaque ancestor and excludes clipped visually-hidden text:

| surface | overflow | broken images | nameless controls | low contrast | small targets |
|---|---|---|---|---|---|
| `/analytics` | 0 | 0 | 0 | **0** | 16 |
| `/platform/analytics` | 0 | 0 | 0 | **0** | 3 |

Identical in all four combinations, so nothing here is direction- or theme-dependent.

**Every small target belongs to the SHARED CHROME, not to either screen.** The harness now names
them (it counted them and never said which, which is a number nobody can act on):

```
<input class="form-control">      188x39   "Search here..."     the sidebar search
<button class="ss-rail-toggle">    30x30   "Collapse menu"      the rail toggle
<a class="ss-breadcrumb__link">    35x20   "Home"               the breadcrumb
```

`/platform/analytics` has exactly those three and nothing else. `/analytics` has the same three
plus thirteen `<a class="dash-link"> 224x43` sidebar entries and the filter form's own inputs —
again chrome and shared form components, not figures.

**Those three are NOT changed here, and that is deliberate.** They are in the merchant layout that
every surface renders, and Phase 2.5's 24 PREMIUM VERIFIED surfaces went through owner visual
acceptance with that chrome as it is. Raising the rail toggle from 30×30 to a 44×44 target changes
the look of accepted chrome on every screen in the product, which is not engineering's call to make
inside a Phase 05 evidence task. Recorded as a finding; the decision is the owner's.

## Capture integrity

- `check-distinct-surface-captures.py` over this directory: **8 captures scanned, 8 distinct
  contents, 0 groups of identical files.**
- `detect-refusal-toast-captures.py` reports no capture showing a permission-refusal toast — but
  note that it scans the set the coverage matrix CITES, and this directory is not cited by that
  matrix, so read its clean result as covering the Phase 2.5 set rather than these eight.
- Both tools state in their own output that a clean result does not prove a capture shows the right
  screen. The per-shot HTTP/`dir`/background lines above are what carries that, one shot at a time.

## What this does NOT establish

- **No contrast measurement beyond the harness's own.** It found zero failures on both screens in
  all four combinations, which is the check `CLAUDE.md` requires; it is not a full WCAG audit.
- **The fixture is a dev fixture.** Two paying tenants, 10 orders, 3 `store_order_financials` rows
  and 12,219 `shetabit_visits` rows. Figures on these screens are therefore small and some
  disclosures ("no store has such a period yet") are the empty-state wording rather than the
  populated one. The screens' EMPTY and SMALL states are what these eight images show.
- **No owner acceptance.** These are measurements and pictures, not an approval of anything.
