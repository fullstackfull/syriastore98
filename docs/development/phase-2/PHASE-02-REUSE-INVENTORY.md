# Phase 02 — reuse inventory (P2-00)

Every row below was produced by reading or executing something, with the command named. The point of
this file is to stop Phase 02 rebuilding what Phase 01 already verified, and to stop it merging a
branch that would *delete* verified work.

---

## 1. Branch forensics — measured, before any merge decision

```
git fetch origin --prune                 # 43 remote branches
git rev-parse origin/main                                           -> 94c8b345
git rev-parse origin/claude/store-syria-p02-merchant-experience     -> 5c63bcea
git diff --name-status origin/main origin/claude/store-syria-p02-merchant-experience
    M  CLAUDE.md
    D  storesyria_ecommercego.sql
```

**Two facts, and they decide the integration posture.**

1. **The Phase 02 branch is `main` plus two deliberate changes**: the P2-GOV working rules, and the
   **deletion of the 20 MB production database dump** from the tree. `main` still carries that file.
   So `main` must never be merged *over* this branch, and the dump must never come back.
   (`P1-SEC-40` / #120 remains open: a `git rm` on this branch removes it from the tree, **not** from
   history, and purging the blob rewrites `main`, which is the owner's call. Phase 02 claims no
   remediation of that incident.)
2. **`claude/store-syria-p01b-design-foundation` must not be merged.** It is an ancestor-era branch:

```
git log --oneline p02..p01b-design-foundation        -> 180+ commits
git diff --stat p02 p01b-design-foundation
    693 files changed, 93107 insertions(+), 148041 deletions(-)
```

   Merging or cherry-picking from it would **delete ~148 k lines**, including the whole Phase 01 CI
   and security test apparatus. Its design-system content is **already here**, verified blob-for-blob:

| file | p01b blob == p02 blob |
|---|---|
| `public/css/store-syria-ds.css` | **same** |
| `resources/views/layouts/includes/store-syria-ds.blade.php` | **same** |
| `docs/design-system/README.md` | **same** |
| `docs/design-system/gallery.html` | **same** |
| `resources/views/attribute_option/index.blade.php` (pilot) | **same** |
| `docs/design-system/tools/browser-checks.cjs` | **differs — p02 is newer** |

   → **Reuse decision: reuse the Phase 02 baseline as-is. Merge nothing from p01b.**

3. **No Phase 02 implementation exists anywhere.** `git log --all --grep` for `P2-`/`Phase 02`
   returns one commit (`5c63bcea`, governance only), and no remote branch carries any file under
   `docs/development/phase-2/`. The field is clear; there is no prior Phase 02 work to preserve.

4. `claude/brave-cori-byyw4s` (the harness-assigned branch) carried **no unique commits** — it sat at
   `main`. Phase 02 work goes to `claude/store-syria-p02-merchant-experience`, and that branch is
   mirrored to the harness branch so the two stay identical.

---

## 2. Component and screen inventory

Classification: **Reuse unchanged · Extend · Adapt · Superseded · N/A**

### 2a. Web design system — the foundation to build on

| Component / Screen | Existing work | Reuse decision | Missing work | Risk |
|---|---|---|---|---|
| Token layers (3 layers, 111 custom properties) | `public/css/store-syria-ds.css`, `--ss-*` only, machine-verified 40 contrast pairs | **Reuse unchanged** | new semantic tokens for panel/stat/nav surfaces | low — adding a token is additive; renaming a layer-2 token is breaking |
| Opt-in mechanism | `layouts/includes/store-syria-ds.blade.php` + `@stack('css')` at `app.blade.php:176` (last sheet in `<head>`) | **Reuse unchanged** | — | low — a screen that does not opt in is byte-identical |
| Dark-mode following | the partial reads `#main-style-link` and sets `data-ss-theme`, with a `MutationObserver` for the live toggle | **Reuse unchanged** | — | low |
| RTL strategy | logical properties throughout; **no `-rtl` counterpart file and there must never be one** | **Reuse unchanged** | apply to every new component | medium — one physical `left`/`right` silently breaks Arabic |
| buttons, fields, card, badges, table, toolbar, empty, skeleton, alert, tabs, dialog, page header, breadcrumbs, sortable list, a11y utilities | ~80 `.ss-*` classes, rendered in `gallery.html` | **Reuse unchanged** | — | low |
| **side panel / sheet** | designed in `ADMIN-LAYOUT-ARCHITECTURE.md` §4, **not built** | **Build (extend)** | `.ss-panel`, focus trap, scroll lock, `data-surface="panel"` on the existing `data-ajax-popup` contract | medium — both modal id spellings (`#commanModel`, `#commonModal`) must keep working |
| **stat tile / KPI row** | none | **Build (extend)** | `.ss-stat` | low |
| **sticky action bar, dirty state, validation summary** | designed §5, **not built** | **Build (extend)** | `.ss-actionbar`, `beforeunload` guard, error summary linked to fields | medium — must not break existing form posts |
| **nav zones, workspace chip, collapsed rail** | designed §1–2, **not built** | **Build (extend)** | needs the menu-data change first | **high — see §3** |
| **command palette / global search** | designed §7, **not built** | **Build (extend)** | `.ss-command` + a server-side scoped endpoint | **high — a frontend-filtered search leaks cross-tenant record existence** |
| **notifications inbox** | designed §6, **not built** | **Build (extend)** | durable store distinct from `show_toastr` | medium — schema |
| pilot screen | `attribute_option/index.blade.php`, 210 lines, fully documented, preserves 3 permission gates + ajax-popup + DELETE form + jQuery-UI sortable | **Reuse unchanged as the pattern reference** | — | low |
| verification tooling | `check-contrast.py` (40 pairs), `render-preview.php`, `preview-server.php`, `browser-checks.cjs` (66 checks, Chromium 141) | **Reuse + extend** | new previews and checks per screen | low — and this is why "verified" can mean something here |

### 2b. Host chrome — what constrains Workstream B

| Thing | Measured fact | Consequence |
|---|---|---|
| sidebar markup | **not a Blade file and not a config array.** `partision/sidebar.blade.php:34-39` emits `{!! getSideMenu() !!}` / `{!! getMenu() !!}`; `generateMenu()` (`app/Helper/helper.php:64`) and `generateSideMenu()` (`:2151`) build the whole `<ul>` tree as a **hand-concatenated HTML string**; every class (`dash-item`, `dash-link`, `dash-mtext`, `dash-arrow`, `dash-submenu`) is a PHP string literal | navigation **cannot** be restyled from a view. This is Phase 02's #1 sequencing item |
| the two gates | `App\Classes\Menu::add()` (`app/Classes/Menu.php:19`) filters on `in_array($item['module'], ActivatedModule())` **and** `isAbleTo($item['permission'])` | **a security property.** Any new nav surface must register through `Menu::add()` or it bypasses both gates |
| header/sidebar hooks | **zero** `@yield`/`@stack` in `partision/header.blade.php` and `partision/sidebar.blade.php` | adding chrome requires editing those two files; there is no injectable hook |
| sidebar search | inline script at `app.blade.php:358-398` selects `.dash-navbar > .dash-item` with **direct-child** combinators and sets inline `display` | **wrapping a nav item in a new element breaks search with no error** |
| store context | `getCurrentStore()` (`helper.php:459`) is called from ~1,411 sites; switcher at `header.blade.php:131-160` | semantics **frozen**. Phase 02 changes how it is *displayed*, never how it resolves |
| super admin | same `layouts/app.blade.php`, differs by menu event and settings helper; `$setting` is **reassigned** to super-admin settings at `app.blade.php:415-417` | code added below that line reads the wrong tenant's settings |

### 2c. Merchant dashboard — what data actually exists

Read from `HomeController::handleRegularUser()` (`app/Http/Controllers/HomeController.php:191-312`).

| Available and verified | Decision |
|---|---|
| product count, order count, customer count, cancelled count | **Reuse** |
| `total_revenues` = `sum(final_price)` over orders excluding `delivered_status` 2/3 unless returned | **Reuse, relabelled.** Gross **order value** — not revenue, not collected cash, not profit |
| order counts by `delivered_status` (pending 0, delivered 1, cancel 2, return 3, complete 4, shipped 6) | **Reuse** |
| 5 top-selling products, 3 latest products, 4 newest orders | **Reuse** |
| `out_of_stock_threshold` per store (`Utility::GetValueByName`) | **Reuse** — this is what makes an honest inventory alert possible |
| weekly order chart (`getOrderChart(['duration' => 'week'])`) | **Reuse** |
| plan storage usage % | **Reuse** |
| **9 `*Per` "percentage" variables** | **Superseded — do not display.** `calculatePercentageToday($today, $all)` computes `(($today - $all) / $all) * 100`: *today against the all-time total*, which is a large negative number for any store with history, not a day-over-day change. Verified **unused** in any view: `grep -rn 'totalOrderPer\|…\|productPer' resources/ packages/ themes/` → **0 hits**. They are computed and discarded, and the 7 extra `COUNT` queries behind them are pure cost |
| profit / margin / settled cash | **does not exist anywhere.** Honest unavailable state, never a fabricated tile |

### 2d. Flutter app — measured, with the SDK actually running

Phase 0 recorded *"whether Repo B compiles at all"* as a blocked check (`06-UI-UX-ASSESSMENT.md`
§17 #4: no Flutter/Dart SDK). **That check is now closed.** Flutter **3.41.2** / Dart **3.11.0** was
installed in this container and run:

```
flutter --version   -> Flutter 3.41.2 • stable • Dart 3.11.0
flutter pub get     -> resolves (see the caveat below)
flutter analyze     -> 248 issues found (ran in 70.0s), exit 0
                       248 info · 0 warning · 0 error
```

Top rules: `prefer_const_constructors` 93 · `unnecessary_this` 91 · `deprecated_member_use` 23 ·
`use_super_parameters` 12. **Nothing above `info`** — the app is clean at error and warning level.

**Caveat, stated rather than hidden:** `flutter pub get --enforce-lockfile` **fails** on this SDK
(`Unable to satisfy pubspec.yaml using pubspec.lock`). A plain `pub get` succeeds by moving five
SDK-pinned packages (`intl`, `matcher`, `meta`, `test_api`, `vector_math`). Those pins come from the
bundled `flutter_test`, so this is an artefact of the SDK *version* available here, not a repository
defect — **and the resulting `pubspec.lock` change is deliberately not committed**, because committing
it would pin the repository to this container's SDK.

| Area | Existing work | Reuse decision | Missing work | Risk |
|---|---|---|---|---|
| shimmer loading | `lib/view/new_widgets/shimmer.dart`, 668 lines, theme-aware **and** RTL-aware, imported by 20 files | **Reuse unchanged** — the strongest UX artefact in either repository | — | low |
| empty / error states | `lib/view/widget/error_empty_state.dart`, imported by 15 files, `SliverFillRemaining` so pull-to-refresh survives an empty list | **Reuse unchanged** | — | low |
| pull-to-refresh | 17 files | **Reuse unchanged** | — | low |
| dark mode | `theme_controller.dart`, two full `ThemeData`s, persisted, animated transition | **Adapt** | `theme:`/`darkTheme:` get the **same object**, so the OS preference is ignored; colours live in **mutable statics** on `AppColor` | medium |
| translations | `en.dart` 640 keys / `ar.dart` 642, no en-key missing from ar | **Reuse unchanged** | — | low |
| **RTL wiring** | `flutter_localizations` is declared; `grep -rn "localizationsDelegates\|supportedLocales" lib` → **0** — still 0 today | **Build — P0** | ~10 lines + a visual pass. Arabic is the default locale while ambient `Directionality` is **LTR**, so 34 `*Directional` calls across 11 files are inert and Material pickers render English | **high — this is the app's product-defining UI bug** |
| **accessibility** | `grep -rn "Semantics(\|semanticLabel" lib` → **0** across 198 files | **Build** | labels on icon-only controls (5-tab bottom bar, 6-item popup menu, password toggle) | medium |
| **vendor avatar fallback** | `lib/utils/images.dart:82-84` still points at `apps.rajodiya.com` for profile, product and COD images | **Build** | bundle local assets | medium — third-party availability dependency plus a traffic/IP leak of the user base |
| debug logging | Phase 01 cut `print(` from 180 to **13**, and added 10 `kDebugMode`/`kReleaseMode` guards | **Reuse** (verify the remaining 13) | — | low |
| Syncfusion charts | `syncfusion_flutter_charts: ^34.1.32` still in `pubspec.yaml`, now imported by **one** screen (`statistics_screen_widget.dart`); `home_screen_widget.dart` no longer uses it; no `registerLicense` call anywhere | **Owner decision — commercial licence** | — | **blocks release of that screen if no licence is held.** Not an engineering item |
| 401 handling | the app clears its session on any 401 | **Preserve — contract** | — | **high — a new 401 on a called path logs merchants out** |
| Lynomia Chat | `lib/view/widget/powered_by_lynomia.dart` on the login screen | **Preserve behaviour**; placement is an owner call | — | low |

---

## 3. The one thing that gates Workstream B

`ADMIN-LAYOUT-ARCHITECTURE.md` already named it: *"the single highest-leverage backend change for
Phase 02 is to make `generateMenu()` / `generateSideMenu()` return a data structure that a Blade
partial renders, instead of a concatenated HTML string."*

The constraint that makes it delicate is the inline sidebar search's **direct-child** selector
(`.dash-navbar > .dash-item`) and the frozen class names. So the rule for that work is: **the two
gates stay exactly where they are, the emitted DOM stays selector-compatible, and only the rendering
moves.** It is verified by asserting the new renderer's output against the old string's output.

---

## 4. What Phase 02 inherits and must not disturb

- **No single derivation seam for an order total.** 16 files persist one; 3 reconcile, warn-only.
  `OrderTotalReconcilerCoverageTest` asserts that partition in both directions — do not delete it.
- **The reconciler's log is not quiet, and that is expected.** A 200.00 basket at 10% tax stores
  `product_price 242, tax_price 0, final_price 220`. The charge is right; the components cannot
  produce it. Pinned as known-bad. Phase 02 therefore never recomputes a total from its parts.
- **Promoting `warnIfInconsistent()` to `assertConsistent()` refuses orders** — a release decision
  with a revenue consequence. Not Phase 02's to take.
- **The Feature suite is not green by design**: 2 accepted failures and 17 accepted skips, declared
  in `.github/known-test-outcomes.json`. The gate asserts the outcome *set* in both directions.
