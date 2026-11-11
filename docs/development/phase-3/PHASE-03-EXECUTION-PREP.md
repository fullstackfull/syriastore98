# Store Syria — Phase 03 preparation (PLAN ONLY)

**Status:** Prepared for owner review; implementation NOT started. **Baseline:** the approved six-phase Master Product Plan. Phase 03 follows the Phase 2.5 *engineering implementation* milestone; it does not waive Phase 01 release gates, the outstanding owner visual approval, or the security exposure on the test host.

## Purpose and boundaries

Phase 03 delivers the customer-facing storefront, controlled theme customisation, the official bilingual Store Syria website and its bilingual documentation centre, using Laravel + WorkDo as the **canonical** business engine. Flutter remains a **merchant/staff app** (not a customer storefront). Preserve all approved merchant Phase 02/2.5 functionality, tenant boundaries, commerce logic and financial security rules. No duplicate product, pricing, cart, inventory, order or payment engines.

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

## Suggested execution order

1. **Gate 03-0 / prerequisites**: confirm Phase 2.5 evidence pack and owner visual decisions; document the 10 blocked-surface dispositions; record security incident deferrals explicitly. Keep any public/production rollout disabled until mandatory security controls actually pass.
2. **03-A baseline**: existing theme/route inventory and user-journey evidence; identify the smallest safe, backwards-compatible extensions.
3. **03-A + 03-E implementation**: improve customer storefront themes and essential journeys, with contract/accessibility checks.
4. **03-B implementation**: controlled theme editor with preview, ownership, publishing/rollback and negative security tests.
5. **03-C official site**: evolve WorkDo LandingPage, bilingual and accurate to actual product behavior.
6. **03-D docs centre**: bilingual docs, navigation, search, editorial workflow and link/accessibility tests.
7. **03 closeout**: real evidence, matrix, tests, owner acceptance, release readiness evaluated separately.

## Acceptance criteria (all workstreams)

- Both locales operate correctly and maintain RTL/LTR logical and visual ordering.
- Existing storefront features, prices, discounts, taxes, orders and checkout behavior continue to use WorkDo canonical rules.
- Authorization is verified with **negative cross-tenant** tests, including preview/publish, IDs passed in routes, and null/unresolved store contexts.
- Three existing themes retain backward-compatible rendering and merchant configuration.
- Browser captures show each actual route/component, never redirects, refusal toasts, reused screenshots or blank content claimed as a screen.
- Responsive and accessibility behavior are tested in real DOM/painted output.
- Documentation describes only shipped functionality; unsupported features are not presented as active.
- Test suites and evidence are reported with honest known-failure/skip status; no widening of security ratchets to force green.

## Recommended branches and review structure

- Laravel Phase 03 integration: `feature/phase-03-storefront`
- Workstreams: `feature/p03-storefront-themes`, `feature/p03-theme-editor`, `feature/p03-official-site`, `feature/p03-docs-center`
- Keep Flutter changes separate and **only** where merchant app contracts/settings/preview require them. Do not build a consumer Flutter app.

Use small PRs for isolated workstreams. No merge into main without required reviews and checks. No live-host deployment as part of planning.

## Explicitly deferred, not fixed by this plan

- Owner instructed **no key rotation and no change to live-host public access at this time**. The known public `/.git/` and historical-secret exposure remains **unresolved** and cannot be called secure or release-ready; no secret values should ever be copied into this plan or issue discussions.
- Composer same-major security updates are staged separately; verify their eventual CI and review status.
- The vendor AuctionProduct cross-tenant bid-write concern requires an upgrade-safe fix or feature disablement before enabling affected workflows.
- Owner has not visually accepted the 24 PREMIUM VERIFIED surfaces; 10 blocked Phase 2.5 evidence items retain explicit dispositions.

**Owner activation needed:** approval to start actual Phase 03 development; this document only prepares the plan.
