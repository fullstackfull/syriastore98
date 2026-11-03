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

Wave B (core merchant screens), Wave C (remaining merchant screens), Wave D (Flutter), Wave E
(cross-platform quality). The screen-by-screen inventory that scopes them is
[`PHASE-2.5-VERIFICATION-MATRIX.md`](PHASE-2.5-VERIFICATION-MATRIX.md).
