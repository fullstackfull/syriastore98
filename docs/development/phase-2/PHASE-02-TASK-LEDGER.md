# Phase 02 — task ledger

One row per task. **Status values:** `DONE` (implemented and verified by something executed) ·
`IN PROGRESS` · `TODO` · `BLOCKED` (a genuine external blocker, named) · `NOT STARTED`.

`DONE` never means "written". It means a command was run and its output is recorded in
[`PHASE-02-TEST-EVIDENCE.md`](PHASE-02-TEST-EVIDENCE.md).

Decision IDs follow the Master Product Plan / Decision Register v1.0 (2026-10-14). Where a task
implements a design decision this repository made rather than one the register fixes, the design
document is cited instead — inventing a register ID would be worse than citing nothing.

---

## Slice P2-00 — discovery and reuse audit

| ID | Task | Decisions | Status | Files | Verified by | Blocker |
|---|---|---|---|---|---|---|
| P2-00-1 | Branch forensics: p02 vs main vs p01b-design-foundation | GOV | **DONE** | — | `git diff --stat` recorded in REUSE-INVENTORY §1 | — |
| P2-00-2 | Confirm no Phase 02 work exists on any branch | GOV | **DONE** | — | `git log --all --grep`, per-branch `ls-tree` | — |
| P2-00-3 | Component and screen reuse inventory | UX-001…014 | **DONE** | `PHASE-02-REUSE-INVENTORY.md` | reading + the commands named in it | — |
| P2-00-4 | Measure the Flutter app with a real SDK | — | **DONE** | — | Flutter 3.41.2 / Dart 3.11.0; `flutter analyze` → 248 info, 0 warning, 0 error | — |
| P2-00-5 | Design pass for the shared layer and dashboard | UX-001…014 | **DONE** | `design/P2-DESIGN-FOUNDATION.md` | written before implementation, as required | — |

## Slice P2-01 — shared layout and core components (Workstreams A, F)

| ID | Task | Decisions | Status | Files | Verified by | Blocker |
|---|---|---|---|---|---|---|
| P2-01-1 | Extend the design system with the Phase 02 components | UX-001 | **DONE** | `public/css/store-syria-ds.css` | contrast checker: **58 pairs pass** (was 40); pre-existing suite **66/0** | — |
| P2-01-2 | `.ss-panel` side-panel surface + focus/scroll behaviour | UX (ADMIN-LAYOUT §4) | **DONE (built)** | ditto, `public/js/store-syria-ds.js` | contrast + unit of the CSS; **the panel's focus trap is not yet driven in a browser** — see P2-01-9 | — |
| P2-01-3 | Sticky action bar with the focus-obscured offset | WCAG 2.2 AA | **DONE (built)** | ditto | `scroll-padding-block-end` ships in `.ss-has-actionbar`; not yet driven | — |
| P2-01-4 | Dirty-state tracking + `beforeunload` guard | UX | **DONE (built)** | `public/js/store-syria-ds.js` | `node --check`; not yet driven | — |
| P2-01-5 | Focusable validation summary | WCAG | **DONE (built)** | `components/ss/error-summary.blade.php` | not yet driven | — |
| P2-01-6 | Blade component layer (`<x-ss.*>`, 15 components) | UX-001 | **DONE** | `resources/views/components/ss/*` | rendered in a real browser; 70 Phase 02 checks pass | — |
| P2-01-7 | Preview harness: component support + per-screen fixtures | — | **DONE** | `docs/design-system/tools/render-preview.php` | pilot output byte-identical after whitespace normalisation (proved against `git show HEAD:`) | — |
| P2-01-8 | Phase 02 browser check suite | — | **DONE** | `docs/development/phase-2/tools/browser-checks-p02.cjs` | **100 PASSED, 0 FAILED**, Chromium 153; bite-tested three times | — |
| P2-01-9 | Drive the panel, action bar and dirty guard in a browser | — | **DONE** | `tools/previews/components.blade.php`, `browser-checks-p02.cjs` | 21 interactive checks: focus trap (8/8), Esc, scroll lock, focus return to trigger, dirty guard armed by typing and **not** by focus, summary focus move, RTL panel edge | — |
| P2-01-12 | Fix the wrong-sense Arabic in the merchant surface | — | **DONE** | `resources/lang/ar.json` | 8 entries changed, 0 removed; re-rendered and re-shot | **owner: needs a native reviewer before release** |
| P2-01-10 | Menu registry returns data, not an HTML string | ADMIN-LAYOUT §0 | **DONE** | `app/Helper/helper.php`, `resources/views/partision/menu-tree.blade.php` | `verify-menu-render-parity.php` — 17 checks, DOM-tree comparison + selector assertions; **bites twice** (a wrapper div and a dropped `.dash-mtext` each produce 6 failures). Reviewed by `tenant-authz-reviewer`: gates unmoved, no tenant read, no request input. Rendered in Chromium at 1440x900 and 390x760, en/ar x light/dark — 7 screenshots in `screenshots/p2-01-sidebar-nav/`, **reviewed, and the review found a harness bug** (the preview took the non-default `cust_theme_bg` branch; see F8) | — |
| P2-01-11 | Sidebar zones, workspace chip, collapsed rail | UX | **TODO** — now UNBLOCKED (P2-01-10 is done) | — | — | — |
| P2-01-13 | Sidebar navigation legible below 1025px | WCAG AA | **DONE** | `public/css/custom.css`, `public/css/rtl-custom.css` | 10 browser checks across 5 widths x 2 languages, **bite-tested** (revert → exactly 8 failures, 1440px pair still passes); active/hover verified to keep `#fff` at 390px and 1440px; pre-existing DS suite still **66/0** | — |

## Slice P2-02 — merchant dashboard (Workstream C)

| ID | Task | Decisions | Status | Files | Verified by | Blocker |
|---|---|---|---|---|---|---|
| P2-02-1 | Honest stat grid, every figure carrying its period | PRD-001 | **DONE** | `resources/views/dashboard/overview.blade.php` | browser check asserts every stat has a period | — |
| P2-02-2 | "Needs attention" worklist; absent when empty | UX | **DONE** | ditto | browser check asserts the block is absent in the empty state | — |
| P2-02-3 | Out-of-stock count, reusing the product list's own rule | — | **DONE** | `app/Http/Controllers/HomeController.php` | `php -l`; predicate copied from `ProductDataTable::dataTable()`; tenant-reviewed | — |
| P2-02-4 | Orders-by-status breakdown, each count a link | — | **DONE** | `overview.blade.php` | rendered; tab order checked | — |
| P2-02-5 | Refuse to display revenue/profit; label order value honestly | PRD-001 | **DONE** | ditto | browser checks assert no "revenue"/"profit" claim and that the disclaimer is present | — |
| P2-02-6 | Arabic copy for every new string, at ship time | — | **DONE** | `resources/lang/{ar,en}.json` | renderer reports "all strings translated" for all 12 variants; **+22 keys, 0 changed, 0 removed** | — |
| P2-02-7 | Before/after visual evidence, 4 combinations + mobile | — | **DONE** | `screenshots/p2-02-dashboard/` | 16 screenshots, real Chromium 153, reviewed and three defects fixed | — |
| P2-02-10 | Gate the order-value figure on `Manage Order` | ORD / DATA | **DONE** | `overview.blade.php` | found by the tenant/authz review, verified against `git show HEAD:`, bite-tested (2 failures when reverted) | — |
| P2-02-11 | Drop links and chip interactivity the controller would refuse | — | **DONE** | `overview.blade.php`, `components/ss/chip.blade.php` | browser check: ungated staff are given 0 links and 6 static chips | — |
| P2-02-12 | Stop the order chart serving platform data to merchant staff | DATA | **DONE** | `HomeController::getOrderChart()` | `verify-order-chart-operator-branch.php` — 9 checks asserting the BRANCH (PlanOrder only in the operator arm), **bitten three ways**: the real pre-fix file from `5c63bcea` → 3 failures; the discriminator inverted → 1 failure; the equivalent `if ($isOperator)` rewrite → still passes, so it cannot false-alarm on correct code. Plus `php -l`; route name, `scoped_models` and `UserController::store`'s type assignment read directly. Reviewed twice by `tenant-authz-reviewer`. **Still not runtime-verified** — needs the composer blocker lifted | — |
| P2-02-8 | Weekly orders chart with a table fallback | chart a11y | **TODO** | — | — | — |
| P2-02-9 | Recent orders / top products in the new system | — | **TODO** | — | — | — |

## Slice P2-07 — Flutter merchant experience (Workstream K)

| ID | Task | Decisions | Status | Files | Verified by | Blocker |
|---|---|---|---|---|---|---|
| P2-07-1 | Install a working Flutter SDK and measure the app | — | **DONE** | — | Flutter 3.41.2 / Dart 3.11.0; closes Phase 0 blocked check #4 | — |
| P2-07-2 | Wire `flutter_localizations` — the P0 RTL defect | — | **DONE** | `lib/store_go.dart`, `lib/translations/translation_service.dart` | 1186 tests pass; 2 new test files; **both bite** (3 and 2 failures when reverted) | — |
| P2-07-3 | Remove the vendor-host avatar fallback | — | **DONE** | `lib/utils/images.dart` + 2 screens | host-level guard test, red first; analyze 246 info / 0 warning / 0 error | — |
| P2-07-4 | Web ↔ Flutter parity matrix | — | **DONE** | `PHASE-02-WEB-FLUTTER-PARITY.md` | 91 endpoints read from `lib/utils/base_api.dart` | — |
| P2-07-5 | **RTL visual pass over the app's screens** | — | **BLOCKED** | — | — | **No device, no emulator, no Flutter rendering in this container.** G1's fix is verified; the look of each mirrored screen is not. |
| P2-07-6 | Dashboard honesty pass in the app (periods, money gate) | PRD-001 | **TODO** | — | — | partly an API change → escalation |
| P2-07-7 | Accessibility: accessible names on icon-only controls | — | **DONE** | 11 Flutter files, `test/a11y/icon_button_names_test.dart` | 14 controls named; 1189 tests pass; analyze 246 info / 0 warning / 0 error; **bites** (names file:line) | — |
| P2-07-8 | Broader a11y: decorative icons hidden, reading order, state announcements | — | **TODO** | — | — | no screen reader in this container |

## Slice P2-04 — orders (Workstream E)

| ID | Task | Decisions | Status | Files | Verified by | Blocker |
|---|---|---|---|---|---|---|
| P2-04-1 | Make the order-status breakdown **partition** the total | ORD | **DONE** | `HomeController.php`, `dashboard/overview.blade.php` | browser checks read the rendered chips and the total off the page and compare; **bites** (old behaviour shows 1,272 against a total of 1,284) | — |
| P2-04-2 | Name the two values that are not fulfilment states | ORD | **DONE** | `overview.blade.php` | browser check asserts the wording and both labels | — |
| P2-04-3 | Status constants on `HomeController`, with the stale migration comment recorded | — | **DONE** | `HomeController.php` | 9 values confirmed live by grep across `app/` and `resources/` | — |
| P2-04-4 | Order list: design-system adoption | UX-001 | **TODO** | — | — | — |
| P2-04-5 | Quick order preview in a side panel | UX | **TODO** | — | — | needs a read-only preview route → authz review |
| P2-04-6 | Order detail page | UX | **TODO** | — | — | — |
| P2-04-7 | Kanban presentation | UX | **TODO** | — | — | — |
| P2-04-8 | Saved views and filters | UX, G | **TODO** | — | — | — |

## Slices P2-03, P2-05, P2-06 — not started

| ID | Slice | Workstream | Status |
|---|---|---|---|
| P2-03 | Products experience | D | **NOT STARTED** |
| P2-05 | Common merchant screens | J | **NOT STARTED** |
| P2-06 | Search, notifications, preferences, onboarding | G, H, I | **NOT STARTED** |


## Blockers — environment, not engineering

| # | Blocker | Effect | Evidence |
|---|---|---|---|
| B1 | **`composer install` cannot complete.** The environment's network policy denies `git.yoomoney.ru` (403 at the proxy), and `yoomoney/yookassa-sdk-php` + `yoomoney/yookassa-sdk-validator` are production dependencies in `composer.json`. | **No `vendor/`, so no PHPUnit.** The Unit, Feature and Contract suites and the known-outcome gate **cannot be run in this container**. Not run, and therefore not claimed. | `Failed to execute git clone --mirror -- https://git.yoomoney.ru/...: CONNECT tunnel failed, response 403` |
| B2 | `flutter pub get --enforce-lockfile` fails on Flutter 3.41.2 | A plain `pub get` resolves by moving five SDK-pinned packages (`intl`, `matcher`, `meta`, `test_api`, `vector_math`). An SDK-version artefact, not a repository defect. **The resulting `pubspec.lock` change is deliberately not committed.** | `Unable to satisfy pubspec.yaml using pubspec.lock` |
| B3 | Syncfusion charts licence | `syncfusion_flutter_charts ^34.1.32` is used by one Flutter screen with no `registerLicense` call anywhere. **An owner/commercial decision, not an engineering task.** | `grep -rn "registerLicense" lib` → 0 |

B1 is the one that matters for acceptance: everything claimed in this phase so far is verified by a
contrast checker, a real browser, `php -l` and `node --check` — **never by the PHPUnit suites**, which
have not run here at all.

## Findings recorded, not acted on

| # | Finding | Why it is only recorded |
|---|---|---|
| F0 | **`orders.delivered_status` is one integer column carrying four different concepts.** 0–6 are fulfilment states, **7 is Partially Paid (a payment state)** and **8 is Pre Order (an order type)**. The column's own migration comment still reads `0=>pending/1=>diliver/2=>cancel/3=>return` and has been stale for a long time. | Phase 02 surfaces this honestly rather than hiding it — the dashboard now labels 7 and 8 as "recorded in the same field, but not fulfilment states" — but **splitting the column is a schema and commerce change, which is Phase 04 and an owner decision**. Recorded here so the next phase inherits the fact rather than rediscovering it. |
| F1 | `HomeController::calculatePercentageToday()` computes today against the **all-time total**, not a day-over-day change, and all nine `*Per` variables it feeds are **unused by every view in the repository**. | The dashboard never displayed them, so this is dead computation rather than a visible defect. Removing it also removes 7 `COUNT` queries per dashboard load — worth doing, but it is a controller change with no user-visible effect and belongs in its own commit. |
| F2 | `resources/lang/ar.json` contains **17 duplicated keys** (2,874 entries, 2,857 distinct); `en.json` has none. | PHP's `json_decode` keeps the last occurrence, so behaviour is well-defined today. De-duplicating rewrites 155 lines of a translation file and risks changing a string nobody asked to change. Reported for an owner decision. |
| F3 | Host-chrome controls outside `.ss-scope` (e.g. the layout's breadcrumb links) have **no focus ring**. | The design system never styles a bare element selector, so an opt-in stylesheet cannot fix it. It is a host-chrome change, not a Phase 02 one. |
| **F4** | **`generateSideMenu()`'s database-sourced rows bypass BOTH `Menu::add()` gates.** `getSideMenu()` (`helper.php:2170-2188`) pushes `SideMenuOption` rows straight onto `$menu->menu`, carrying `module` and `permission` keys that **nothing ever checks** — `Menu::add()` is not called, and `generateSideMenu()` (`:2193-2284`) contains no `isAbleTo` and no `ActivatedModule`. The platform-level rows (`created_by = 0`, `store_id = null`, selected at `:2147`) therefore render for **every merchant**. | Found by the review of P2-01-10. Only reachable when the `SidebarCustomization` module is active. **Pre-existing, in a function Phase 02 did not change, and a real authorization hole** — it wants its own change with a test, not a line in a refactor commit. |
| **F5** | **`generateSideMenu()` renders database-sourced titles unescaped** (`helper.php:2267`, `__($item['title']) . '</span>'`). Combined with F4's editable, platform-level rows this is a stored-XSS shape. | Same function as F4 and the same reasoning: `generateMenu()` (which Phase 02 converted) only ever receives hardcoded listener titles, so the escaping added there is hardening. This path needs the same treatment. |
| **F6** | **The dashboard order chart built platform data for merchant staff — latently.** `getOrderChart()` branched on `type == 'admin'`, but a staff user's `type` is **their role name** (`UserController::store`: `$user->type = $role_r->name`). Every staff member therefore took the else-branch and got `PlanOrder` counts — platform-wide SaaS subscription orders with **no `store_id` predicate at all** (`PlanOrder` is not in `scoped_models` and registers no `StoreScope`). **LATENT, not live:** `$chartData` is rendered only by `superadmin/dashboard.blade.php` and `theme_analytic.blade.php`; the merchant dashboard references it **0 times**, so the counts were computed and discarded. My first write-up said staff "were shown" it — an overclaim, caught in review and corrected. | **FIXED in P2-02-12.** Fixed now rather than later because this inventory marks the chart "Reuse" — Phase 02 intends to render it, which is when latent becomes live. The discriminator now asks `UserPolicy::isOperator()`. The reviewer also established the fix is **monotone**: the new PlanOrder set (`isOperator`) is a strict subset of the old (`type !== 'admin'`), so no principal can newly receive that data. |
| **F7** | **Menu titles are translated twice.** `CompanyMenuListener` passes `'title' => __('Dashboard')` (already translated) and `menuTree()` — like the string builder before it — calls `__()` on that result again. It works only because the second lookup misses and returns its input. | Harmless today and pre-existing on both sides of the refactor. Fixing it means deciding which layer owns translation across every module listener — wider than this phase. |
| **F8** | **Sidebar navigation was illegible on every phone and tablet, on default settings — and two false readings stood between the screenshot and that sentence.** `transprent-bg` (which the default `cust_theme_bg = 'on'` puts on the nav) is acted on only inside `@media (min-width: 1025px)` in `assets/css/style.css`. At 1024px and below the nav falls back to `.dash-sidebar { background: #1c232f }` while `custom.css` keeps `color: #333333`. Measured in Chromium at 360/390/768/1024px, en and ar: **label 1.25:1, icon ~1.07:1 (`#060606`), sidebar-search input text ~1.30:1 (`#293240`)**. The same page at 1440px measures **12.01:1**, which is why desktop screenshots and a desktop probe both called it clean. **False reading 1:** a probe re-pointed at `file://` returned `rgb(0,0,238)` on white and passed all six variants at 9.40:1 — browser-default link blue on a bare page, because `file://` resolves `/assets/css/…` at the filesystem root and **no stylesheet loaded**. **False reading 2:** my preview hard-coded sidebar.blade.php's `else` branch, so the first 1.25:1 I saw was a non-default configuration and I nearly filed it as this slice's defect. | **FIXED** for light mode below 1025px, in `public/css/custom.css` and `public/css/rtl-custom.css`: below the breakpoint the sidebar *is* dark by the host's own design, so the labels, icons, arrows and search field are made light rather than the background being changed. Scoped `@media (max-width: 1024px)` at specificity (0,3,0) — beats the (0,2,0) base rule, **loses** to `.active`/`:hover`/`:focus` at (0,4,0), which keep their `#fff` (verified: active stays `rgb(255,255,255)` at 390px and 1440px). No `!important`, so `style-dark.css`'s `color: #808191 !important` still wins and **dark mode is deliberately unchanged**. `#808191`, the host's own dark-sidebar grey, was **not** reused: on `#1c232f` it reaches only 4.11:1, under the 4.5:1 that 15px/500 needs. Guarded by 10 new checks in `browser-checks-p02.cjs` across 5 widths x 2 languages; **bite-tested** — reverting the two CSS files returns exactly 8 failures and the 1440px pair still passes. **Still open, tracked here:** dark mode is 4.03:1 (`on`) and 3.71:1 (`off`), and `cust_theme_bg = 'off'` in light is still 1.25:1 above 1025px (screenshot `07-…-custthemebg-off.png`). Those need `public/assets/css/style-dark.css`, where the value is `!important` on a selector list **shared with `p` and `.dataTable-info`** — admin-wide across 70 modules, and **with Composer blocked the Feature suite cannot run**, so that change has no regression evidence available. Own slice. |
| **F9** | **The selected navigation item fails AA at every width, desktop included.** `.dash-item.active > .dash-link` is `#fff` on `var(--theme-color)`; on theme-3's `#10B77F` that measures **2.59:1**. So the merchant's current location is the least legible label on the screen, and `@media (min-width: 1025px)` has nothing to do with it. | Not fixed, and not fixable from this slice. `--theme-color` is the **merchant's own brand colour** — ten preset themes plus a custom colour picker (`app.blade.php:25-40`) — so there is no single safe value: a correct fix either picks the label colour from the chosen colour's computed luminance or darkens the active background, for all ten presets and any custom value. That is a theming decision with a visible brand consequence, which makes it an owner call rather than a drive-by in a navigation refactor. The new browser checks deliberately measure the **default** state only, and say so in the code, so their pass cannot be read as "the sidebar is accessible". |
| **F10** | **Two operator checks in one file now disagree about who the operator is.** `HomeController::index():139` dispatches to the Super Admin dashboard on the exact literal `$user->type == 'super admin'`, while `getOrderChart():530` now normalises through `UserPolicy::isOperator()` (`strtolower(rtrim(...))`). For a hand-edited `'Super Admin'` row the two answer differently: `index()` renders the merchant dashboard while `getOrderChart` builds `PlanOrder` counts. | Found by the second independent review, which judged it **a ledger line rather than a code change**, and that is accepted. It is doubly latent — the merchant view discards `$chartData` (F6), and the role path cannot produce the casing because `RolePolicy::RESERVED_NAMES` blocks `admin` and every `super admin` spelling, so it takes a hand-edited row. But it is one more instance of the pattern P1-SEC-11 exists to end, in a file `OperatorCheckCensusTest`'s construct assertion does **not** cover (that names only `OrderPolicy`, `OrderDocumentAccessGuard` and `OrderController`). Changing `index()`'s dispatch changes **which dashboard a principal is shown**, so it is a behaviour change needing its own test, not a drive-by in a chart fix. |
