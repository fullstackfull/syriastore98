# Phase 2.5 — task ledger

**Authority:** `D-39` in [`PHASE-2.5-DECISION-REGISTER.md`](PHASE-2.5-DECISION-REGISTER.md).
**Baselines:** Laravel `53971d4e`, Flutter `94cdcdb0` — both re-read from the remote, not assumed.
**Branch:** `claude/busy-edison-k0pjwa` in both repositories. **No merge to `main`.**

This ledger follows the Phase 02 conventions: one row per slice, status is what was *executed*, and
a claim without a command behind it is not a status. It does not introduce a second planning tree —
Phase 02's ledger stays the authority for Phase 02 rows, and rows here cross-reference them.

---

## Standing scope rule, from `D-39` and the owner's directive

> Visual polish, usability corrections and accessible presentation are Phase 2.5.
> **Unbuilt functionality is not.** Kanban behaviour, saved-view persistence and any other
> feature Phase 02 did not build stay Phase 02 rows under their original identifiers and must
> not be reported as Phase 2.5 work.

Anything found in that second category is recorded in §3 below, with its original identifier, and
escalated rather than absorbed.

---

## 1. Wave A — design foundation and shared components

| id | slice | status | evidence |
|---|---|---|---|
| **P2.5-A-01** | **The white modal surface in dark mode** — labels, modal titles and secondary text on `.modal-content`, which the host keeps `#ffffff` while the admin is dark. Closes Phase 02 ledger **F126** and the owner's named priority item. | **DONE [2026-11-03]** | `public/css/custom.css`, `public/css/rtl-custom.css`, `tests/Unit/Ui/DarkModalSurfaceContrastTest.php` (new, 11 tests / 58 assertions), `docs/development/phase-2.5/tools/measure-f126-cascade.cjs` (new), `docs/development/phase-2.5/tools/check-dark-form-surfaces-live.cjs` (new), `docs/development/phase-2.5/evidence/p2-5-a-01/` (4 screenshots) |
| **P2.5-A-02** | **Every text input, select and textarea in the dark admin.** Found while measuring A-01 and larger than it: the host gives every form control `#808191` on `#292A33` (3.71:1) and its placeholder `#6c757d` (3.04:1), on every screen, in every modal, in both directions. | **DONE [2026-11-03]** | same files; covered by the same test class and both harnesses |

### What was measured, before and after

| | cascade harness (4 layout stylesheets, 26 surfaces) | live application (real modal, both directions) |
|---|---|---|
| **before** | **7 below AA** | **modal title 1.00:1 — white on white** |
| **after** | **1 below AA** (F152, out of slice, see §3) | **20 checks, 0 failed** |

Headline readings, each from the run named above:

| surface | before | after |
|---|---|---|
| modal `.modal-title` (live) | **1.00:1** | **16.06:1** |
| modal `.form-label` / bare `<label>` / `.ss-label` | 2.52:1 | 16.06:1 |
| modal `<p>` | 3.84:1 | 5.94:1 |
| form control value, every surface | 3.71:1 | 12.55:1 |
| form control placeholder | 3.04:1 | 5.66:1 |
| sidebar, DataTables chrome, breadcrumb (**must not move**) | 5.66–6.14:1 | **5.66–6.14:1, unchanged** |

### The three things this slice got wrong first, each caught by measurement

1. **The handoff's diagnosis was incomplete, and narrowing the rule it named would not have
   worked.** `F126` attributes the 2.52:1 to custom.css's bare `html[data-ss-theme="dark"] body
   label`. The host's own dark theme carries a bare `label { color:#808191 !important }` at
   `style-dark.css:29465`. Narrowing ours uncovers the host's, which is 3.84:1 on white — still
   below AA. **The surface had to be named, not the element.**
2. **The worst defect on that surface was not in the handoff at all.**
   `style-dark.css:29442` forces `h1…h6` to `var(--bs-white) !important`, so **every modal title
   in the dark admin was white on white, 1.00:1, invisible.** The cascade harness read it as
   3.84:1; only the running application read it as 1.00:1, because the application loads
   **25 stylesheets** — six of them, plus three inline `<style>` blocks, *after*
   `store-syria-ds.css`.
3. **The first version of A-02 regressed the very views it was meant to help.** Pointing the host
   field at the design system's *semantic* tokens so it would match `.ss-input` took the ten views
   carrying `data-ss-theme="light"` from 3.71:1 to **1.13:1**: a semantic token re-themes with the
   nearest scope and the host field's background does not. **Match the token layer to the surface,
   not to the component.** The ratchet now forbids that token in these blocks by name.

### The gates, with the run's own output

| gate | result |
|---|---|
| design-system contrast | **58 pairs pass**, light + dark; auto scope identical to dark across 36 tokens |
| `DarkModalSurfaceContrastTest` (new) | **12 passed, 128 assertions** |
| `ClaudeConfigurationTest` | **76 passed, 618 assertions** |
| Unit | **732 passed, 4,608 assertions** — was 721 before this slice; the 11 new tests are the whole difference |
| Feature | **7,519 passed, 2 failed, 1 skipped, 0 errors**, 37,351 assertions, 2,283s |
| **known-outcome gate** | **PASS** — `the observed outcome set matches the manifest exactly -- 2 declared failures, 1 declared skips, 0 errors, and nothing undeclared` |

**That is the integrated-`main` baseline the directive cites, figure for figure** (7,519 / 2 / 1 / 0).
It was re-measured rather than copied, as the directive required. **Nothing was added to the
manifest** — the gate asserts the set in both directions, so an entry added to buy a pass would
have failed it on the other side.

### Bite tests — every one failed exactly as designed, then passed again

| mutation | result |
|---|---|
| both blocks removed | 4 of 11 tests fail |
| A-02 reverted to the re-theming semantic token | 3 fail, including the rule that names it |
| the Arabic copy alone loses the modal-title rule | 2 fail (drift + selector) |
| the host makes the modal dark, so the premise is gone | the premise test fails and says to re-measure |
| **and the ratchet's own first draft** | a bite that renamed the selector to `.modal-title-DROPPED` **passed** it — `[^{]*` ate the suffix. Anchored on `,` or `{`, re-bitten, now caught. |

---

## 1b. Wave A / B — the second pair of slices

| id | slice | status | evidence |
|---|---|---|---|
| **P2.5-A-03** | **The merchant's own name in the header, in an Arabic admin.** The greeting rendered `Amina Haddad!مرحبًا` — the `!` on the wrong side of the name, and no separator at all. On every page. | **DONE [2026-11-03]** | `resources/views/partision/header.blade.php`, `resources/lang/ar.json`, `tests/Unit/Ui/HeaderGreetingBidiTest.php` (new, 4 tests), `evidence/p2-5-a-03/` |
| **P2.5-B-01** | **The merchant's own card on the dashboard, at the one width it breaks.** `.user-info` collapsed to **87px** at 1440px — nine lines of one or two words each, on the merchant's home screen. | **DONE [2026-11-03]** | `public/css/custom.css`, `public/css/rtl-custom.css`, `evidence/p2-5-b-01/` (4 screenshots) |

### A-03 — measured by rendered position, not by source order

Read off the running Arabic admin from each child node's client rect, because the source order was
never in question and the **visual** order was:

| | visual order, left to right |
|---|---|
| before | `["Amina Haddad", "!مرحبًا"]` |
| after | `["!", "Amina Haddad", "مرحباً،"]` |

Right-to-left, that second reading is the greeting, then the name, then the exclamation mark last —
which is where it belongs. **Two independent defects, not one.** The `!` is a bidi-**neutral**
character, so between a Latin run and the paragraph end it resolves against the **paragraph**
direction; `<bdi>` gives the name its own isolated embedding level. And `ar.json` mapped the key
`'Hi, '` — comma *and* trailing space — to `"مرحباً"` with **neither**, so the greeting ran straight
into the name. The replacement uses the Arabic comma `،` (U+060C), not the Latin `,`.

The premise is asserted from Unicode's own bidirectional class via `IntlChar::charDirection('!')`,
so the reasoning survives a rewrite of the comments rather than living only in prose.

### B-01 — a desktop-only defect that four of five widths hide

| viewport | `.dashboard-card` | `.user-info` | the greeting paragraph |
|---|---|---|---|
| **1440px** | 366px | **87px → 332px** | **9 lines → 3** |
| 1280px | 985px | 673px | 1 line — unchanged |
| 1024px | 984px | 673px | 1 line — unchanged |
| 768px | 728px | 449px | 2 lines — unchanged |
| 390px | 350px | 316px | 3 lines — unchanged |

`col-xxl-4` engages at ≥1400px and splits the row into thirds, so the card is **366px on the
widest screens** and ~985px on a 1280px laptop. Inside it the markup asks for the stacked layout
with `flex-column flex-sm-row` — but **Bootstrap breakpoints are viewport-based**, the viewport is
1440px, and so the row layout wins while the container is 332px. Nobody testing at 1280px would
ever see this.

**The condition is "wide viewport, narrow card", and that is what a container query says.** A
`min-inline-size` would also clear the measurement, but it is a constant tuned to today's grid:
change `col-xxl-4` to `col-xxl-3` and it silently breaks again. A browser without `@container`
renders exactly what it renders today, so the change is additive.

### Two drafts of B-01 were wrong, and the measurement endorsed the first one anyway

1. **`container-type` on the flex row itself.** The paragraph went 9 lines → 3, so it *looked*
   correct — but **`flex-direction: column` never applied**: a container query cannot restyle its
   own container. The whole improvement came from an incidental `> *` rule. **A rule that
   half-fires is worse than one that does not, because the number endorses it.**
2. **Containment moved to the card, and then nothing fired at all.** The container was correctly
   registered (`inline-size`, 366px, query matching — confirmed by reading it off the element),
   and the declaration simply **lost**: every Bootstrap flex utility carries `!important`, so
   `.flex-sm-row` is `flex-direction: row !important`. Matching that weight is what finally moved
   `flexDirection` to `column` in the running page.

Both were caught by reading the computed value off the element rather than trusting that a rule
with the right selector had taken effect.

### One thing in the evidence that is an ENVIRONMENT artefact, said plainly

The first `before` screenshots show broken avatar and product images. That is **not** a product
defect: `public/storage` is committed as a **regular file** containing the original developer's
absolute path (`/var/www/html/Product/EcommerceGo/main_file/storage/app/public`) — a symlink
checked out as text. The `after` screenshots were retaken with a correct local symlink and real
assets; the tracked file was restored unchanged, and the committed diff does not touch it.

---

## 1c. Wave D — the Flutter app's first slice

| id | slice | status | evidence |
|---|---|---|---|
| **P2.5-D-01** | **Every shared button in the merchant app was under the minimum tap target.** | **DONE [2026-11-03]** | Flutter `claude/busy-edison-k0pjwa` **`e74164c4`** — `lib/view/widget/common_button.dart`, `test/a11y/tap_target_size_test.dart` (new, 6 tests) |
| **P2.5-D-02** | **The caret and focus ring were invisible on every dark text field** — `1.09:1`. The Flutter counterpart of `P2.5-A-02`, and found by the audit rather than by analogy. | **DONE [2026-11-03]** | Flutter **`881e2b5`** — `lib/view/new_widgets/app_txt_field.dart`, `test/a11y/field_focus_contrast_test.dart` (new, 4 tests) |

### D-02 — measured in a real render, then fixed without choosing a brand

`AppTextField` used one hardcoded `navyBlue` for **both** `cursorColor` and `focusedBorder`:

| pairing | ratio | |
|---|---|---|
| `#172C4E` on `cSurface` `#213252` | **1.09:1** | the dark field |
| `#172C4E` on `cCardBackground` `#18263F` | **1.09:1** | |
| `#172C4E` on `cBackGround` `#0F1A2E` | **1.25:1** | |
| `#172C4E` on white | 13.94:1 | light mode — correct, and unchanged |

A caret and a focus indicator are **non-text UI components**, so WCAG 1.4.11 asks 3:1; focus
visibility is also **WCAG 2.4.7**, the criterion that makes a form keyboard-operable at all.

**The fix introduces no new colour, and that restraint is the point.** Both values already exist
in the app's own themes — `LightThemeColor.cPrimary` is the same navy, `DarkThemeColor.cPrimary`
is `#10AFC0` at 4.82:1 on the dark field. The widget now **reads the theme instead of a
constant**, so light mode is byte-identical. The app's brand is an open question reserved to the
owner; this reads one rather than deciding one — and the third bite test exists specifically to
fail if a later change lets this slice touch light mode.

| gate | result |
|---|---|
| `flutter analyze` | **247 issues, 0 warnings, 0 errors** — back to the exact pre-slice baseline; two `prefer_const` lints introduced by the new test were fixed rather than left to raise it |
| `flutter test` | **1,377 passed** (4 new), run alone |

Measured by **rendering each widget**, not by reading the literals: what a thumb meets is the box
after `flutter_screenutil` has scaled it against this app's 360×800 design size, so the only
honest number comes from `tester.getSize`.

| line | widget | before | after |
|---|---|---|---|
| 45 | `CommonButton` | **42dp** | 48dp |
| 107 | `CommonIconButton` | **43dp** | 48dp |
| 163 | `SmallCommonIconButton` | **35dp** | **35dp painted, 48dp tap target** |
| 226 | `CommonBorderButton` | **43dp** | 48dp |
| 277 | `CommonIconBorderButton` | **43dp** | 48dp |
| 334 | `CommonSocialButton` | **45dp** | 48dp |

All six were below Material's `kMinInteractiveDimension`; five were also below Apple HIG's 44.
The newer `AppButton` already uses 48, so this is an **internal inconsistency** as much as an
accessibility gap. `SmallCommonIconButton` keeps its size and gains its target — the opposite
decision from the other five, deliberately: extending the **hit area** fixes the defect and
changes nothing a merchant sees.

### The census was wrong twice before it was right

1. The first pass reported *"three bad defaults, and no call site passes an explicit height"*.
   **Both halves were wrong.** The height grep required the class name and `height:` on **one
   line**, which almost never happens. Re-counted by balancing parens over each call: **8 of 15**
   `CommonIconButton` sites and **6 of 16** `AppButton` sites do pass explicit heights, several
   below 48 (`35`, `40.h`, `44.h`, `45.h`).
2. A replace guarded by `count == 1` **refused**, because `height: height ?? 43` occurs **three**
   times — which is how the other two under-sized widgets were found at all.

**Raising the defaults does not reach the call sites that override them.** Recorded as the
follow-up rather than quietly folded in.

### A bite test embarrassed the first version of the test file

Removing `HitTestBehavior.opaque` from the widget left **every assertion passing**. The `SizedBox`
still measures 48, so a size assertion cannot tell a real hit area from a transparent one — the
fix would have looked identical on screen *and in CI* while doing nothing. A test that **taps the
margin** (inside the 48dp target, outside the 35dp painted box) and asserts the callback fired now
catches it. Three bites, all failing as designed.

### Writing the test found a second defect — recorded, not fixed

`CommonIconButton`, `SmallCommonIconButton` and `CommonIconBorderButton` each declare
`final String? iconData` and then do `iconData!` in build. **The signature says optional; the
widget throws** `Null check operator used on a null value` when it is omitted, and the analyzer is
silent because the type is nullable. The first report is a runtime crash in front of a merchant.
Making it `required` is a signature change rather than accessible presentation, so it is a finding
(**P2.5-F-06**).

| gate | result |
|---|---|
| `flutter analyze` | **247 issues, 0 warnings, 0 errors** — unchanged from the pre-slice baseline |
| `flutter test` | **1,373 passed** (6 new), run **alone**, after the Laravel Feature suite had finished, per the documented stall |

**No brand colour, no business logic and no API contract is touched.**

---

## 2. Documentation corrected because it was measured and found wrong

Both are in files the owner's directive names as authoritative, so a wrong sentence in them
propagates into every later slice.

| file | what it said | what is true, measured |
|---|---|---|
| `docs/design-system/README.md` | "LTR emits `dir=""`, not `dir="ltr"`" and "a `[dir="ltr"]` selector will never match" (`app.blade.php:54`) | `app.blade.php:**68**` is `dir="{{ … ? 'rtl' : 'ltr' }}"` — a ternary with **no empty branch**. LTR emits `dir="ltr"` and `[dir="ltr"]` **does** match. Read off the running admin; `check-dark-form-surfaces-live.cjs` now asserts it on every run, in both directions. Corrected in place with the correction dated and attributed. |
| `docs/design-system/README.md` | `@stack('css')` is "the **last** stylesheet position in `<head>` … Nothing needs `!important` to win the cascade" | **Measured in the running admin: 25 stylesheets load, and `store-syria-ds.css` is 15th.** flatpickr, summernote, emojionearea, `calendar.css`, two DataTables sheets and **three inline `<style>` blocks** all load after it. Recorded in §3 as a finding rather than corrected in this slice, because the fix is a cascade decision and not a sentence. |

---

## 3. Found, NOT in this slice, and not silently absorbed

| id | what | why it is not here |
|---|---|---|
| **F152** (Phase 02) | a plain host `<p>` on the host's dark card measures **3.71:1**, from `style-dark.css:29454`. Still open. | Fixing it lightens paragraph text on every dark surface across seventy modules. It needs its own measurement pass over every light-surfaced container in dark mode — this modal was only the first one found. An existing harness, `check-dark-paragraphs-live.cjs`, already covers it. |
| **P2.5-F-01** (new) | `store-syria-ds.css` is **not** the last stylesheet. Six sheets and three inline blocks load after it, so the README's "nothing needs `!important`" is false for anything they touch. | A cascade-order decision affecting the whole design system. Recorded with the measurement; the fix belongs in a Wave A slice of its own, not inside a contrast fix. |
| **P2.5-F-02** (new) | `docs/tooling/SKILL-USAGE-POLICY.md` lines 107–108 state `frontend-design` is "install-required" and `ui-ux-pro-max` is "**absent** — never in the repository". **Both are committed** (`git ls-files .claude/skills/`), as are `design-system`, `ui-styling`, `design`, `brand`, `banner-design` and `slides`. `ClaudeConfigurationTest::test_claude_md_does_not_depend_on_an_uncommitted_skill` still lists `ui-ux-pro-max` as absent and passes **vacuously**, because its regex searches the whole of `CLAUDE.md` for the word "absent" rather than the sentence about that skill. | A tooling-policy correction plus a ratchet repair. It is exactly the §10 pattern — "a control keyed on an absence will eventually pass because the world moved" — and `D-39` makes the four design skills mandatory, so the policy must be accurate before Wave B. |
| **P2.5-F-03** (new) — **and this one is a FUNCTIONAL BREAK, not a design deficiency** | **`GET /product-list` returns HTTP 500.** The product **grid view** — one of the two product list surfaces a merchant can choose — is unreachable. `ProductController::list()` passes `compact('products', 'settings', 'msg', 'filterCategories')` on **three** branches (`:527`, `:530`, `:533`) and **never assigns `$filterCategories`**; its sibling `index()` does, at `:521`. Under PHP 8.3 `compact()` on an undefined name raises `E_WARNING`, which Laravel's `HandleExceptions` promotes to `ErrorException`. **Verified by me in the running application, not taken from the audit:** `/product` → **HTTP 200**, `/product-list` → **HTTP 500**, same session, same merchant. | **Not fixed in this slice, and deliberately not bundled into a contrast commit.** `D-39` reserves *unbuilt functionality* to Phase 02 and authorises *visual polish and usability corrections* here; a 500 is neither — it is a correctness defect on a shipped route. It is also a **prerequisite**: a screen that does not render cannot be visually polished, so Wave B cannot cover the grid view until this is decided. **Escalated to the owner.** |
| **P2.5-F-04** (new) — **also a functional break** | **The order board's "Move" control does nothing, and shows the merchant raw JSON.** `resources/views/order/partials/kanban-card.blade.php:58` posts `name="order_status"`; `OrderController::order_status_change()` reads **`$request->delivered`** (`:1657`) and nothing reads `order_status`. The handler also answers with `response()->json(...)` (`:1638`), while the card submits a plain `<form method="POST">` — so the browser *navigates* to the JSON. **Verified by reading both sides**, not taken from the audit. | Kanban *behaviour* is a **Phase 02** row under `D-39`, so this is not Phase 2.5 work and is not fixed here. It is recorded because the Phase 02 handoff lists Kanban as **unbuilt**, and it is in fact **built and broken** — a materially different state, and one the owner should decide on. **Escalated.** |
| **P2.5-F-05** (new) — **functional break** | **The customer list's "Apply" filter button does nothing, and its target does not exist.** The control is `id="applyfilter"` (`resources/views/customer/index.blade.php:64`); the only click handler in the page binds **`$('#apply-button')`** (`:199`). `applyfilter` appears **exactly once in the entire repository** — as that id — so nothing is listening. And even if it fired: `applyFilter()` writes the response into `$('#service-filter-data')` (`:223-224`, twin at `customer/grid.blade.php:271-272`), an id that **exists nowhere** in any view, controller or `public/` file — only in those four write statements. **Verified by me by grepping the whole repository**, not taken from the audit. | Same class as `F-03`/`F-04`: broken shipped behaviour, not missing polish. `D-39` authorises *usability corrections*, and a dead button is arguably one — but three independent functional breaks found in the first five audited areas is a **pattern**, and the owner should decide the boundary once rather than have me decide it three times. **Escalated as a group.** |
| Kanban behaviour, saved-view persistence | Phase 02 rows **P2-04** | `D-39` reserves unbuilt functionality to Phase 02. Both **exist**: `resources/views/order/kanban.blade.php` (126 lines) with `.ss-kanban`/`.ss-board`, and a saved-views filter bar the audit rates `premium-already`. The handoff listing them as unbuilt is stale. What is wrong with the board is `P2.5-F-04` above, and it is a Phase 02 defect, not a Phase 2.5 slice. |

---

---

## 3b. The handoff's "unbuilt" table is stale, measured against the current code

`PHASE-2.5-HANDOFF.md` §2 lists what Phase 02 left unbuilt, and the directive warned in as many
words not to assume those defects still exist. Checked:

| handoff row | what is actually there |
|---|---|
| P2-03 "the status/stock indicators" | **BUILT.** `P2-03-9` is `DONE [2026-10-26]`; `ProductDataTable.php:75-138` renders a four-state stock badge whose boundary is shared with the filter predicate through `trackedStockState()` so the two cannot drift. What is genuinely missing is narrower: no **status** (Active/Inactive) column on the table surface, and the **grid** surface still carries pre-conversion `bg-*` badges, one measuring **1.68:1**. |
| P2-03 "media/variant UX" | **PARTIAL**, which is what the ledger already said at `:655` — the handoff summarised it as absent. Present: the Dropzone gallery with server-derived limits and translated rejection text, cover/downloadable/preview inputs, the existing-image grid, the full D-26 per-variant image bound to the variant id, the three-screen variant-delete confirmation with a server-issued consent token, and the `ss_last_seen_at` optimistic lock. Missing: no empty state on the variant panel, no confirmation or success message on gallery-image delete, no upload rules or accessible name on the variant image control, 28 orphan `<label for="">` across the three variant renderers, and the create-side variant trash discards typed work silently. |
| P2-04 "Kanban" | `resources/views/order/kanban.blade.php` **exists** (126 lines), as do `.ss-kanban` and `.ss-board` in the design system. What remains is being established; whatever it is will be recorded under its **Phase 02** identifier. |
| the two blockers the ledger still cited against these rows | **F126 is closed by `P2.5-A-01`** and **F76 is closed** at `store-syria-ds.css:3477-3503`. Neither should be carried forward. |

---

## 3c. Wave D's governing finding, measured before any Flutter code is written

The owner's directive is explicit that **"the design must feel like one professional product
across web and mobile"** and that one platform must not be polished while the other is left
inconsistent. Measured, the two platforms do not currently share a brand colour.

| | hex | hue | on white |
|---|---|---|---|
| **web** `--ss-brand` (`store-syria-ds.css`) | `#097465` | **172°** | 5.68:1 — pinned to clear AA, by design |
| **Flutter** `LightThemeColor.cPrimary` | `#172C4E` | **217°** (navy) | 13.94:1 |
| **Flutter** `LightThemeColor.cAccent` | `#00B4D8` | **190°** (cyan) | 2.46:1 |
| **Flutter** `DarkThemeColor.cPrimary` | `#10AFC0` | 186° | 2.65:1 |

The design-system README records the logo mark as a **168–197° gradient with a median of 177°**,
and pins the web ramp at **172°** — inside that gradient, at its green end. Flutter's accent sits
at **190°**, inside the same gradient but at its *blue* end, 18° away; Flutter's primary is
**navy at 217°**, outside the gradient altogether. They read as two products.

**Two caveats, so this is not overstated.** The "on white" column is only meaningful for a colour
intended as text or a fill *on white* — `--ss-teal-500` and the Flutter dark tokens are dark-surface
colours and are not failing anything by appearing low there. And the logo file the README derives
the gradient from is a live upload (`uploads/2/*logoStore.svg`), **not in the repository**; the two
marks that are committed in the Flutter app are monochrome (`logoStore.svg` carries no fill at all)
or navy-and-green (`s_logo.svg`: `#172C4E` + `#6FD943`). So the README's derivation cannot be
re-measured here and is taken as the record — which is also what `D-39` and the owner's directive
instruct, naming `store-syria-ds.css` and the README as the authoritative foundation.

**The scale of the reconciliation, measured:** `lib/utils/colors.dart` holds 47 colour literals;
**293 more are hardcoded across 49 other files**. 58 of 200 Dart files read `Theme.of(context)`,
70 read the mutable `AppColor.*` statics, and the rest hardcode — **three parallel colour sources**.
A real `ThemeData` does exist (`lib/core/controller/setting_controller/theme_controller.dart`,
`_buildTheme()`), so this is a convergence job, not a rebuild.

**Not started, and not decided here.** Changing a brand colour is a material change the owner
reserves. Wave D's first slice is to put the question, with these numbers, rather than to pick.

---

## 4. Not yet started

Wave B beyond the dashboard card, Wave C (remaining merchant screens), the rest of Wave D, and
Wave E (cross-platform quality). The screen-by-screen inventory that scopes them is
[`PHASE-2.5-VERIFICATION-MATRIX.md`](PHASE-2.5-VERIFICATION-MATRIX.md).
