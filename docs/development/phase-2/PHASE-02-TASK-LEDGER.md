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
| P2-01-8 | Phase 02 browser check suite | — | **DONE** | `docs/development/phase-2/tools/browser-checks-p02.cjs` | **75 PASSED, 0 FAILED**, Chromium 153; bite-tested twice | — |
| P2-01-9 | Drive the panel, action bar and dirty guard in a browser | — | **TODO** | — | — | — |
| P2-01-10 | Menu registry returns data, not an HTML string | ADMIN-LAYOUT §0 | **TODO** | `app/Helper/helper.php` | — | — |
| P2-01-11 | Sidebar zones, workspace chip, collapsed rail | UX | **TODO** (blocked by P2-01-10) | — | — | — |

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
| P2-07-7 | Accessibility: `Semantics` on icon-only controls (0 today, 198 files) | — | **TODO** | — | — | — |

## Slices P2-03 … P2-06 — not started

| ID | Slice | Workstream | Status |
|---|---|---|---|
| P2-03 | Products experience | D | **NOT STARTED** |
| P2-04 | Orders experience | E | **NOT STARTED** |
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
| F1 | `HomeController::calculatePercentageToday()` computes today against the **all-time total**, not a day-over-day change, and all nine `*Per` variables it feeds are **unused by every view in the repository**. | The dashboard never displayed them, so this is dead computation rather than a visible defect. Removing it also removes 7 `COUNT` queries per dashboard load — worth doing, but it is a controller change with no user-visible effect and belongs in its own commit. |
| F2 | `resources/lang/ar.json` contains **17 duplicated keys** (2,874 entries, 2,857 distinct); `en.json` has none. | PHP's `json_decode` keeps the last occurrence, so behaviour is well-defined today. De-duplicating rewrites 155 lines of a translation file and risks changing a string nobody asked to change. Reported for an owner decision. |
| F3 | Host-chrome controls outside `.ss-scope` (e.g. the layout's breadcrumb links) have **no focus ring**. | The design system never styles a bare element selector, so an opt-in stylesheet cannot fix it. It is a host-chrome change, not a Phase 02 one. |
