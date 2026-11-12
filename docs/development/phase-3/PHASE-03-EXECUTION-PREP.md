# Store Syria — Phase 03 preparation (PLAN ONLY)

**Status:** Prepared for owner review; implementation NOT started. **Expanded 2026-11-11** from an outline into a measured inventory, a dependency map and milestones — see *Measured inventory*, which is what changed the plan's shape. **Baseline:** the approved six-phase Master Product Plan. Phase 03 follows the Phase 2.5 *engineering implementation* milestone; it does not waive Phase 01 release gates, the outstanding owner visual approval, or the security exposure on the test host.

## Purpose and boundaries

Phase 03 delivers the customer-facing storefront, controlled theme customisation, the official bilingual Store Syria website and its bilingual documentation centre, using Laravel + WorkDo as the **canonical** business engine. Flutter remains a **merchant/staff app** (not a customer storefront). Preserve all approved merchant Phase 02/2.5 functionality, tenant boundaries, commerce logic and financial security rules. No duplicate product, pricing, cart, inventory, order or payment engines.

## Measured inventory — what already exists, before anything is planned against it

Everything in this section was measured in the repository on 2026-11-11, not inferred from the
brief. Where something is absent, it says so plainly, because an absent foundation is a cost and a
plan that assumes one is worse than no plan.

### The three storefront themes are real, and they are not what the merchant admin is

| theme | views | blade files | RTL | dark mode |
|---|---|---|---|---|
| **stylique** | `themes/stylique/views` | 13 | `dir` on `<html>`, 20 `[dir="rtl"]` rules | **none** |
| **techzonix** | `themes/techzonix/views` | 15 | `dir`, 20 `[dir="rtl"]` rules | **none** (it declares `darkMode: ["class"]` in an inline tailwind config that nothing consumes — 0 `dark:` utilities, 0 `.dark` rules) |
| **greentic** | `themes/greentic/views` | 16 | `dir`, 18 `[dir="rtl"]` rules | **none** |

`stylique` is the default for both `config/theme.php` `active` and `parent`, and
`Api/DashboardController.php:7374` hardcodes it for new stores.

**How a theme is selected:** `stores.theme_id`, a varchar holding the theme *directory name*.
Three pieces of code resolve it — `ActiveTheme` middleware (`:21`, `:37`, `:41-43`),
`ViewServiceProvider::registerViewFinder()` (eight branches), and a `View::composer`. `Theme::set()`
calls `prependLocation()`, which flips the view-path order so the theme directory wins.

**Two traps worth knowing before the first commit:**

1. **A store with an empty `theme_id` skips `Theme::set()` entirely** and falls back to the base
   layout `resources/views/front_end/layouts/app.blade.php`, which has **no `dir` attribute** and in
   Arabic links `themes//assets/css/rtl-main-style.css` — a path that exists in no theme. Store id 1
   in the production dump has exactly that empty `theme_id`.
2. **`themes/stylique/views/layouts/app.blade.php` shadows the WorkDo admin layout** once
   `Theme::set('stylique')` has run, and it is a scaffold stub whose `<body>` is the literal comment
   `// ... existing code ...`. Latent only because all 32 storefront views extend
   `front_end.layouts.app` instead.

### The theme editor is 60–65% built as data, and 0% built as the three things 03-B names

**The storefront theme editor is first-party app code, not a module:**
`app/Http/Controllers/ThemeCustomizeController.php` (190 lines, 7 actions) plus
`resources/views/theme_customize/`. A separate `packages/workdo/ThemeCustomizer` module (45 files)
and `packages/workdo/LandingPage` (773 files) also exist.

**Already built, and must not be rebuilt:** the section taxonomy is declarative JSON on disk —
`themes/{theme}/theme_json/*.json` in a `{sections:[{key, settings:[{label, key, type, value}]}]}`
shape. Measured: 3 themes × 25/28/29 pages, roughly 82–85 sections and **959 declared fields**.

**Absent, and this is the real Phase 03 work:**

> `theme_customizes` carries exactly `id, name, value, store_id, theme_id, timestamps`. **No
> `status`, no `is_draft`, no `version`, no `published_at`.** No `*_versions`, `*_revisions` or
> `*_history` table exists anywhere in `database/migrations/`. `landing_page_settings.name` is
> **UNIQUE**, which structurally forbids a second (draft) row for the same key.

So preview, publish and rollback are net-new, and the uniqueness constraint means the data model
has to change before the UI can. **`theme_customizes` is also not in `config/tenancy.php`
`scoped_models`** — only `ThemeCustomizeOrder` is — so every editor, preview and publish path needs
its own explicit store predicate.

### Documentation and search start from nothing

| | measured |
|---|---|
| locales | **16** |
| translation keys | **8,389** total; `en.json` 3,527, `ar.json` 3,523 |
| Arabic gaps | 35 English keys with no Arabic row; 28 Arabic values byte-identical to their key; 31 containing no Arabic-script character |
| existing docs surface | **one page, and it is vendored** — `GET /api-docs` from `packages/workdo/Api`, behind `auth` + `PlanModuleCheck:Api` |
| search infrastructure | **none of any kind.** No scout, meilisearch, algolia, elasticsearch, typesense, tntsearch or spatie/laravel-searchable, in `composer.json`, `composer.lock` or `vendor/` |

A bilingual documentation centre with navigation and search is therefore **entirely new
construction**, including whatever indexes it.

### The quality harness does not reach the storefront — eight measured gaps

This is the most important finding for planning, because Phase 03's acceptance criteria assume the
Phase 2.5 evidence standard and **the harness cannot currently produce it for a customer-facing
page.**

| # | gap |
|---|---|
| 1 | **The storefront has no dark mode at all.** `grep -rln "cust_darklayout\|data-ss-theme\|style-dark" themes/` returns **zero files** across all three themes. The four-combination matrix does not exist for the storefront. |
| 2 | **The state control does not drive the storefront.** `render-matrix.cjs` writes `settings.cust_darklayout`, `settings.SITE_RTL` and `users.language`. The storefront reads none of them. |
| 3 | **Every premise check in `render-matrix.cjs` is merchant-admin shaped** — the theme premise tests `#main-style-link` for `style-dark.css`, which no storefront page has. Storefront rows would be premise-fail or, worse, false. |
| 4 | **No harness can sign in as a customer.** All 112 `.cjs` harnesses POST to the merchant `/login` (`web` guard). Not one drives `{slug}/login`. |
| 5 | **The fixture cannot render a credible storefront home page.** `theme_customizes` has **zero rows**, so `getThemeSetting()` falls through to 511 keys of WorkDo demo content. |
| 6 | **The fixture has almost no imagery** — 2 of 32 products have a `product_images` row; 0 of 4 categories have an image; 0 of 3 brands have a logo. Fatal for *visual* acceptance specifically. |
| 7 | **Store 2 is empty.** All 32 products belong to `damascus-textiles`; `aleppo-soap` has none. No cross-tenant storefront comparison is possible. |
| 8 | **Storefront RTL is unverified and may be unimplemented.** `themes/stylique/assets/css/{rtl,ltr}.css` exist on disk but the front-end layout links **neither**. |

**Performance:** one real tool exists — `docs/development/phase-2/tools/measure-performance-baseline.cjs`
(Playwright + `PerformanceObserver`) — but it is **merchant-only, has no INP and no Lighthouse**.

### What this inventory means for the plan

Three consequences that change the shape of Phase 03, and all three are cost, not blockers:

1. **A storefront evidence harness is a prerequisite, not a deliverable.** Gaps 1–8 must be closed
   before any storefront surface can be captured to the Phase 2.5 standard. That is a workstream of
   its own and it comes first, or every later acceptance claim is unbacked.
2. **Storefront dark mode is a product decision before it is an implementation.** It does not exist
   today. Either Phase 03 introduces it across three themes, or the storefront acceptance matrix is
   explicitly two combinations rather than four. Deciding that late would invalidate captures.
3. **03-B is a data-model change before it is a UI.** The unique constraint on
   `landing_page_settings.name` and the absence of any version column mean draft/publish/rollback
   needs migrations first. The 959 declared fields and the section taxonomy are reusable as-is.

---

## Workstreams

### 03-A — Existing storefront themes
- Inventory the **three existing storefront themes**, templates, routes and current configurability.
- Deliver a consistent, premium responsive storefront experience for each, without replacing working WorkDo controllers or commerce paths.
- Validate product discovery, collections, product detail, cart and checkout UX against the existing engine.
- Design Arabic RTL and English LTR, light/dark where supported, mobile/tablet/desktop, accessibility, keyboard operation and performance.

### 03-B — Controlled visual theme editor
- Provide a *section-based* visual editor with an explicit allowlist of configurable blocks, theme tokens and settings; no arbitrary executable HTML/JS and no bypass of existing permissions.
- Separate preview/draft from publish and preserve backwards compatibility for existing storefront configurations.
- Enforce store ownership and policy checks on every editor, preview and publish API.
- Version configuration safely and provide rollback for drafts/published themes without mutating product or checkout business data.

### 03-C — Official website
- Build on the **existing WorkDo LandingPage** foundation for the official Store Syria site; avoid a second landing-page engine.
- Include the approved bilingual marketing, product explanations, merchant onboarding, pricing representation from canonical plan data, help/contact and privacy/legal links as approved.
- Content should not promise unsupported functionality or bypass plan/tenant permissions.

### 03-D — Arabic/English documentation centre
- Build bilingual, searchable, discoverable docs with categories, role-based how-tos, onboarding, troubleshooting and merchant/employee workflows.
- Preserve correct Arabic typography, RTL layout, navigation, code samples and English LTR reading direction.
- Establish editorial ownership, version labels, link checking, accessibility and a content release process.

### 03-E — Shared design and quality
- Reuse the Phase 2.5 approved design-system components, existing page routes and test/screenshot harnesses.
- Set measurable targets for LCP/CLS/INP, accessibility and rendering correctness **after baseline measurements**; do not invent pass thresholds as existing facts.
- Run actual browser captures for Arabic/English, RTL/LTR and relevant viewports, plus route-auth, tenant boundary and regression tests.
- Ensure plan gating, storefront session behavior and existing merchant-to-store integration stay intact.

## Dependency map — what must precede what, and why

```
  03-0  PREREQUISITES (gate)
        ├── storefront evidence harness .......... blocks ALL capture-backed acceptance
        │     ├── customer sign-in in the harness        (gap 4)
        │     ├── storefront-shaped premise checks       (gaps 2, 3)
        │     └── a storefront-credible fixture          (gaps 5, 6, 7)
        ├── DECISION: storefront dark mode?  ..... blocks the capture MATRIX SHAPE (gap 1)
        └── DECISION: storefront RTL scope?  ..... blocks 03-A acceptance criteria (gap 8)
                 │
                 ▼
  03-A  STOREFRONT THEMES ──────────────┐
        (needs 03-0's harness to prove  │
         anything; needs the dark-mode  │
         decision before any capture)   │
                                        ▼
  03-B  THEME EDITOR                 03-E  SHARED QUALITY
        ├── migrations FIRST:              (runs alongside both; its
        │   draft/version columns,          perf baseline needs 03-0's
        │   and the UNIQUE constraint       harness to reach a storefront
        │   on landing_page_settings.name   page at all)
        │   must be resolved
        ├── then preview
        ├── then publish
        └── then rollback
                 │
                 ▼
  03-C  OFFICIAL WEBSITE  ....... depends on 03-B only if it reuses the
        (LandingPage module)      section/publish machinery; independent if not.
                                  DECIDE THIS EARLY — it changes the critical path.
                 │
                 ▼
  03-D  DOCUMENTATION CENTRE .... depends on nothing above except 03-E's
        (entirely new)            bilingual quality bar. Can start in parallel.
                                  Needs a SEARCH decision first — there is no
                                  search infrastructure of any kind today.
```

**The three decisions on that map are not engineering choices and should be taken before the first
commit, not during 03-A:**

1. **Storefront dark mode: in or out?** It does not exist. Either Phase 03 introduces it across
   three themes, or storefront acceptance is explicitly two combinations rather than four. Taking
   this late invalidates every capture made before it.
2. **Does the official website reuse the theme editor's publish machinery?** If yes, 03-C waits on
   03-B and the critical path lengthens materially. If no, they are independent.
3. **What indexes the documentation search?** Database full-text, a package, or a hosted service —
   each has a different dependency, cost and operational story, and there is nothing to extend.

---

## Milestones

Sequenced so each one ends in something checkable, rather than in a status update. No dates: the
owner has not authorised a start, and inventing a schedule for unstarted work would be the kind of
claim this project avoids.

| # | milestone | done when | depends on |
|---|---|---|---|
| **M0** | **Prerequisites gate** | The harness signs in as a customer, drives a storefront route, and produces a capture whose premise checks are storefront-shaped. The fixture renders a credible storefront home, product grid and product page with real imagery in **two** stores. The three decisions above are recorded. | — |
| **M1** | **Storefront baseline, measured** | Every customer-journey route in the inventory is captured in the agreed combinations, with **measured** LCP/CLS/INP rather than targets invented in advance. A written list of what is actually wrong. | M0 |
| **M2** | **03-A themes** | The agreed storefront improvements land across all three themes; before/after captures exist because M1's baseline was captured first; no commerce controller is duplicated. | M1 |
| **M3** | **03-B data model** | Migrations for draft/version state; the `landing_page_settings.name` UNIQUE constraint resolved; `theme_customizes` given an explicit store predicate everywhere it is read or written. **No UI yet.** | M0 |
| **M4** | **03-B editor** | Section-based editing over the existing 959 declared fields, with preview, publish and rollback, and negative cross-tenant tests on every editor, preview and publish path. | M3 |
| **M5** | **03-C official site** | The official bilingual site on the LandingPage foundation, with pricing read from canonical plan data rather than retyped. | M0 (+ M4 if decision 2 says reuse) |
| **M6** | **03-D documentation centre** | Bilingual docs with navigation and working search; the Arabic gaps measured in the inventory closed or explicitly accepted. | M0, decision 3 |
| **M7** | **Phase 03 closeout** | Matrix, evidence, suites and an acceptance package in the Phase 2.5 shape. Release readiness assessed separately, as always. | M2, M4, M5, M6 |

---

## Acceptance criteria

These extend the Phase 2.5 criteria rather than replacing them. The additions are the ones the
inventory showed are **not** currently satisfiable, so they are stated as work rather than assumed.

### Evidence

- Every storefront claim rests on a capture of **that route**, driven as a **customer**, never a
  merchant session standing in for one.
- Capture combinations are whatever decision 1 settles — and the matrix says which, rather than
  implying four and shipping two.
- **No capture may be a redirect, a refusal toast, a reused file or a blank page claimed as a
  screen.** The three Phase 2.5 integrity gates run unchanged over storefront evidence.
- Performance targets are set **after** M1's baseline. A target invented before a measurement is a
  wish.

### Correctness and isolation

- Prices, discounts, taxes, stock and orders continue to resolve through the existing WorkDo rules.
  **No second commerce engine**, no recomputation of a price the engine already computed.
- Every editor, preview, publish and rollback path carries a **negative cross-tenant test**,
  including the null/unresolved store case. `theme_customizes` is **not** in `scoped_models`, so
  each one needs its own explicit predicate.
- The three existing themes keep rendering for stores already configured against them, including
  the store whose `theme_id` is empty.

### Bilingual

- Arabic and English both correct in logical and visual order; the inventory's measured gaps — 35
  English keys with no Arabic row, 28 Arabic values identical to their key, 31 with no Arabic
  script — are closed or explicitly accepted with a reason.
- Documentation describes only shipped behaviour.

### Honesty

- Known failures and skips reported as they are; **no ratchet widened to force green**.
- Anything blocked gets a disposition, not a silent omission.

---

## Branches and review structure

| branch | scope |
|---|---|
| `feature/phase-03-storefront` | integration branch for Phase 03 |
| `feature/p03-harness` | **M0 only** — the storefront evidence harness and fixture. First, and separately reviewable, because everything downstream cites it |
| `feature/p03-storefront-themes` | 03-A |
| `feature/p03-theme-editor-schema` | **M3** — migrations only, reviewed on its own because it changes a constraint |
| `feature/p03-theme-editor` | 03-B UI |
| `feature/p03-official-site` | 03-C |
| `feature/p03-docs-center` | 03-D |

Small PRs per workstream. Flutter changes only where a merchant contract genuinely requires one —
**no consumer Flutter app**. No merge to `main` without the required reviews and checks; no deploy
as part of planning.

---

## Explicitly deferred, and not fixed by this plan

- **The exposed `.git` and historical credentials on the test host remain OWNER-DEFERRED and
  UNRESOLVED.** Phase 03 does not remediate them and must not be read as doing so. No secret value
  belongs in this plan, an issue, or a commit message.
- The Phase 2.5 outstanding items stand: owner visual acceptance of the 24 surfaces, the ten blocked
  dispositions, and `P1-SEC-61`.
- The Phase 01 release gates are untouched.

**Owner activation is still required.** This document prepares Phase 03; it does not start it.
