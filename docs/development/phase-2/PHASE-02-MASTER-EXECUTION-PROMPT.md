# Phase 02 — Master Execution Prompt (original, verbatim)

**Status:** permanent reference document. **Do not edit the quoted prompt.**

This file preserves the owner's Phase 02 Master Execution Prompt **as issued**, so that the
authoritative statement of scope is a fixed artifact rather than a paraphrase inside a ledger that
changes every slice.

| | |
|---|---|
| Received | 2026-10-25, in session `session_01DxJa42pTTS1npbsdSjGhXJ` |
| Product authority | **Master Product Plan / Decision Register v1.0, 2026-10-14** |
| Recorded on | `claude/store-syria-p02-merchant-experience` |

## How this file relates to the other Phase 02 documents

This document is the **requirement source**. It holds no status and no progress.

| Question | Document |
|---|---|
| What was asked for | **this file** (original) + §"Later approved clarifications" below |
| What the phase interprets that to mean | [`PHASE-02-SCOPE.md`](PHASE-02-SCOPE.md) |
| What already existed and was reused | [`PHASE-02-REUSE-INVENTORY.md`](PHASE-02-REUSE-INVENTORY.md) |
| What is done, in progress, blocked | [`PHASE-02-TASK-LEDGER.md`](PHASE-02-TASK-LEDGER.md) |
| What was actually executed, with output | [`PHASE-02-TEST-EVIDENCE.md`](PHASE-02-TEST-EVIDENCE.md) |
| Web ↔ Flutter parity | [`PHASE-02-WEB-FLUTTER-PARITY.md`](PHASE-02-WEB-FLUTTER-PARITY.md) |
| State for the next session | [`PHASE-02-HANDOFF.md`](PHASE-02-HANDOFF.md) |

**Rule for this file:** later instructions, clarifications and narrowings are recorded in the
"Later approved clarifications" section at the end, **dated and attributed, never merged into the
original text above it.** A requirement that is dropped or deferred is recorded as such in the
ledger with a reason — it is never deleted from this document.

---

# THE ORIGINAL PROMPT

> The text from here to the end of this section is the owner's prompt as issued. Headings inside it
> are preserved. Nothing is removed, reordered or reworded.

---

## STORE SYRIA — PHASE 02 MASTER EXECUTION PROMPT
## Merchant Experience — Web + Flutter
### Reuse Existing Work, Execute Autonomously, Preserve Approved Architecture

You are the Lead Engineering Architect, Senior Laravel Developer, Senior Flutter Developer, UI/UX Lead, and Quality Assurance Coordinator for the Store Syria project.

Your task is to **execute Phase 02 — Merchant Experience**, using all relevant existing work, designs, discovery documents, technical findings, and approved product decisions.

This is an implementation assignment, not a request for another lengthy planning exercise.

## 1. Repositories and Starting Point

**Primary Laravel repository:**
`fullstackfull/syriastore98`

**Flutter repository:**
`fullstackfull/StoreSyria_app98`

**Dedicated Phase 02 branch:**
`claude/store-syria-p02-merchant-experience`

**Phase 02 branch starting commit:**
`5c63bcea7ac13e2657cf0ec15539f58f096f6f72`

**Previously verified Phase 01 head:**
`8295ab079e131ff3e0c6f9f760ba66ace0b0b51f`

**Existing design foundation branch:**
`claude/store-syria-p01b-design-foundation`

Start by fetching the latest remote references and verifying the current repository state.

Do not assume that these historical SHAs remain the latest available commits.

The new Phase 02 branch already contains updated working instructions in `CLAUDE.md`.

Do not overwrite this branch with an older branch.

Do not merge experimental branches blindly.

## 2. Authoritative Product Plan

The approved Store Syria Master Product Plan / Decision Register v1.0, dated October 14, 2026, defines six phases:

- Phase 01 — Foundation
- Phase 02 — Merchant Experience
- Phase 03 — Storefront
- Phase 04 — Commerce
- Phase 05 — Marketing & Analytics
- Phase 06 — SaaS & Super Admin

Phase 02 must implement the approved merchant-facing experience for both web and Flutter.

It must not silently expand into Phase 03, 04, 05, or 06.

Where full product decision documentation is available, preserve its approved decision IDs and original meaning.

Especially preserve:

- UX-001 through UX-014
- PRD-001
- ORD-001 and ORD-009
- ARCH-001 through ARCH-011
- Applicable GOV and DATA decisions
- All applicable exclusions EX-001 through EX-012

Do not reinterpret an older technical roadmap as permission to change this approved six-phase product plan.

The owner has authorised isolated Phase 02 engineering, not production release.

## 3. Mandatory Discovery of Existing Work

Before writing new components, examine work that may already exist.

Read and inspect:

1. `CLAUDE.md`
2. `docs/development/HANDOFF-CURRENT.md`
3. `docs/development/phase-1/PHASE-01-CLOSURE-EXECUTION.md`
4. `docs/design-system/README.md`
5. `docs/design-system/ADMIN-LAYOUT-ARCHITECTURE.md`
6. `docs/design-system/gallery.html`
7. `docs/design-system/tools/`
8. `docs/discovery/06-UI-UX-ASSESSMENT.md`
9. `docs/discovery/09-DEVELOPMENT-ROADMAP.md`
10. Existing Phase 01 design and implementation evidence
11. Relevant Phase 02 branches, pull requests, commits, and design artifacts
12. Flutter UI and API implementation

Inspect these existing assets:

- `public/css/store-syria-ds.css`
- `resources/views/layouts/includes/store-syria-ds.blade.php`
- Existing Blade layouts, headers, sidebar, forms and tables
- Existing DataTables helpers
- Existing design-system components
- Existing test utilities and browser checks
- Existing RTL/LTR and dark-mode implementation

Specifically inspect the earlier design branch:

`claude/store-syria-p01b-design-foundation`

Compare it against the new Phase 02 branch using Git commit ancestry, commit differences, and relevant file differences.

**Required discovery output:**

Create a concise reuse inventory:

| Component / Screen | Existing Work | Reuse Decision | Missing Work | Risk |
|---|---|---|---|---|

Classify existing work as:

- Reuse unchanged
- Extend
- Adapt
- Superseded
- Not applicable

Do not rebuild components that already satisfy the approved requirements.

Do not merge branches merely because their names contain "design" or "phase".

Preserve unique approved modifications.

Where earlier design work already exists in the Phase 01 baseline, reuse the existing version rather than cherry-picking duplicate commits.

## 4. Execution Principles

Work efficiently and autonomously.

You are authorised to:

- Inspect both repositories and their relevant branches.
- Read existing documentation and designs.
- Implement approved Phase 02 features.
- Create focused commits on dedicated development branches.
- Run tests and code quality checks.
- Perform independent security and compatibility reviews.
- Fix regressions introduced by your changes.
- Push approved-scope development work to its Phase 02 branch.

Do not request approval for every small UI component, refactor, test, or commit.

Use professional engineering judgment.

Only escalate genuine blockers or high-impact decisions:

- Merging into `main`
- Production deployment
- Production database modifications
- Destructive data operations
- Credential rotation
- Material pricing, payment, inventory or order behavior changes
- Breaking Flutter or Lynomia Chat API changes
- Licensing conflicts
- Changes to the approved six-phase scope

Maintain three non-negotiable protections:

1. Preserve all approved modifications and never blindly merge experimental branches.
2. Preserve compatibility with Flutter and Lynomia Chat.
3. Never treat green CI as sufficient evidence of production readiness.

Keep the existing WorkDo commerce engine.

Do not build parallel product, stock, order, or pricing engines.

Avoid modifying commercial `packages/workdo/` modules. The first-party LynomiaChat module remains a separately reviewed exception.

Do not deploy to production, rewrite Git history, force-push, or run production migrations.

Existing Phase 01 release blockers remain tracked independently.

The publicly exposed production database dump incident, P1-SEC-40 / issue #120, remains an unresolved security issue. Do not reproduce customer data or claim that Phase 02 UI work resolves the incident.

## 5. Phase 02 Implementation Scope

### Workstream A — Design System Adoption

Implement the existing Store Syria Design System across merchant-facing pages.

Requirements:

- Consistent typography and spacing
- Consistent cards, forms, buttons, tables, badges and alerts
- Unified page headers and breadcrumbs
- Arabic RTL and English LTR
- Light and dark themes
- Responsive desktop, tablet and mobile layouts
- Keyboard navigation and accessibility
- Clear loading, empty, success, validation and error states

Use the existing `ss-*` CSS conventions.

Preserve semantic design tokens.

Use CSS logical properties.

Do not introduce a second competing design system.

Do not replace Laravel Blade with a new frontend framework.

### Workstream B — Merchant Workspace and Navigation

Build a cleaner merchant navigation structure.

Requirements:

- Workspace switcher with current-store visibility
- Clear navigation groups by merchant workflow
- Collapsible sidebar
- Improved header
- Quick actions
- Accessible search/navigation controls
- Persistent employee-level preferences
- Saved favorites and recent pages where appropriate
- Responsive mobile navigation

Preserve the existing `Menu::add()` entitlement and Laratrust permission enforcement.

Investigate the architecture documented in `ADMIN-LAYOUT-ARCHITECTURE.md`.

Where justified, convert PHP-generated HTML menu output into structured menu data rendered by Blade.

Do not bypass existing module visibility or permission checks.

Do not change the established semantics of `getCurrentStore()`.

### Workstream C — Merchant Dashboard

Develop a modern, useful merchant home dashboard.

Include, where verified data already exists:

- Store overview
- Sales and orders summary
- Recent orders
- Pending operational tasks
- Inventory alerts
- Shortcuts to common actions
- Important store notifications
- Clear date range and data state

Do not invent financial metrics.

Do not display unverified profitability.

Preserve differences between sales, payments, settlements, and profit.

Where required data does not exist, use an honest empty or unavailable state.

### Workstream D — Product Management Experience

Implement:

- Modern product list
- Fast search and filters
- Saved table preferences
- Clear product status and stock indicators
- Full-page product editor
- Basic and advanced field organization
- Images and media workflow
- Existing variants/options support
- Contextual help
- Validation summary and inline errors
- Sticky save controls
- Unsaved-change detection
- Concurrent-edit conflict detection
- Record preview side panel

Use existing product and inventory services.

Do not implement new Phase 04 product pricing or stock engines in Phase 02.

### Workstream E — Order Management Experience

Implement:

- Improved default order table
- Optional Kanban presentation
- Saved views and filters
- Quick order preview
- Full order detail page
- Distinct order, payment, fulfilment and shipping status presentation
- Permission-aware actions
- Clear manual order entry workflow where supported
- Operational "Needs Attention" center
- Better printing and export UI where already supported

Maintain the approved order rules:

- No editing order products or quantities after creation
- Only authorized delivery and internal note changes when allowed
- Product corrections through cancellation and linked replacement orders
- No duplication of payments or inventory movements

Any unsupported advanced order operations belong to Phase 04.

### Workstream F — Reusable Forms and Record Panels

Implement a consistent interaction architecture:

- Full-page editing for complex forms
- Side panels for quick previews and simple editing
- Dialogs for confirmations
- No confusing nested modal workflows
- Keyboard and focus management
- Unsaved-change protection
- Reliable field validation
- Concurrent edit/version conflict handling
- Accessible error messages

Preserve existing AJAX modal contracts during migration.

### Workstream G — Employee Preferences

Implement practical preference persistence:

- Saved table filters
- Visible columns and column order where supported
- Page size and sorting preferences
- Favorite pages
- Workspace UI preferences

Preferences must be scoped correctly by user and store where appropriate.

Avoid database migrations unless clearly necessary and separately approved.

Where persistence requires a schema change, prepare the migration and tests without executing against production.

### Workstream H — Unified Search and Notifications

Develop a unified search experience covering:

- Navigation
- Products
- Orders
- Customers
- Supported quick actions

Enforce tenant scope, permission checks and module entitlements on the server.

Build a notifications experience separating:

- Temporary action feedback
- Persistent operational notifications

Preserve existing notification mechanisms and reuse their data where appropriate.

If new database structures or APIs are required, document their rationale and integration contracts.

Do not pretend unsupported features are complete.

### Workstream I — Onboarding and Contextual Help

Implement:

- Merchant onboarding guidance
- Contextual field help
- Helpful empty states
- Short task-oriented instructions
- Accessible UI guidance
- Arabic and English translations

Keep onboarding lightweight.

Do not block experienced merchants with repeated tutorials.

### Workstream J — Other Merchant Screens

Apply the established components progressively to existing merchant screens, including:

- Customers
- Inventory views
- Categories and attributes
- Staff and permissions
- Reports already supported by the backend
- Store settings
- Integrations already supported by the backend

Do not discard functionality while simplifying the interface.

Avoid unnecessary changes to unrelated areas.

### Workstream K — Flutter Merchant Experience

This workstream is mandatory.

Inspect the separate Flutter repository.

Build a feature parity matrix comparing:

- Merchant web functionality
- Existing Flutter functionality
- Shared APIs
- Permission behavior
- Missing mobile workflows
- Features intentionally retained on web

Modernize the Flutter merchant/staff experience where necessary.

Priorities:

- Dashboard usability
- Orders and order details
- Products and inventory visibility
- Quick daily operations
- Search and filters
- Notifications
- Employee permissions
- Arabic/English support
- RTL/LTR
- Loading, empty and error states
- Secure authentication behavior

Use native mobile interaction patterns.

Do not create a shopper Flutter app.

Keep advanced complex editors on the web where appropriate.

Maintain API contract compatibility.

Remember that the existing Flutter app may clear its session on HTTP 401; do not introduce incompatible authorization response behavior.

Preserve Lynomia Chat integration behavior.

Run available Flutter analyze/test/build commands and report missing SDK or environment limitations accurately.

## 6. Testing and Quality Assurance

For each coherent implementation slice:

1. Inspect the existing behavior.
2. Implement the smallest maintainable solution.
3. Add targeted tests where appropriate.
4. Run relevant existing tests.
5. Check RTL/LTR, light/dark and responsive behavior.
6. Verify tenant isolation and permissions when touched.
7. Check Flutter and Lynomia Chat compatibility.
8. Commit and record the change.

At integration milestones, run the relevant broader Laravel, contract, browser and Flutter checks.

At final Phase 02 review, run the complete required gates where the environment supports them.

Do not weaken CI guards or delete tests to manufacture success.

Do not misrepresent accepted historical failures as new successes.

Compare actual frontend performance against a measured baseline.

Use browser screenshots and accessibility checks where supported.

Never claim a visual screen was tested if it was only inspected statically.

Never claim a payment or database behavior was verified in production based on SQLite tests alone.

## 7. Efficient Delivery Strategy

Do not spend the entire assignment writing plans.

Use a brief discovery and foundation pass, then implement.

Suggested delivery order:

**P2-00 — Discovery and Reuse Audit**
Inventory existing designs, branches, web and Flutter screens, and reusable components.

**P2-01 — Shared Layout and Core Components**
Adopt tokens, header, sidebar, page layout, forms, alerts, dialogs and side panels.

**P2-02 — Merchant Dashboard**
Modernize daily-use widgets and operational navigation.

**P2-03 — Products**
Deliver list, editing workflow, media UX, preferences and protection against losing edits.

**P2-04 — Orders**
Deliver tables, filters, Kanban if feasible, details, previews and operational workflows.

**P2-05 — Common Merchant Screens**
Customers, inventory UI, staff, settings and other existing merchant pages.

**P2-06 — Search, Notifications and Onboarding**
Complete authorized search, persistent notifications and contextual guidance.

**P2-07 — Flutter**
Implement the approved mobile merchant experience and verify contract compatibility.

**P2-08 — Integration and Final Acceptance**
Run the final validation matrix, review regressions, document residual risks, and prepare the owner acceptance report.

Workstreams may run in parallel if independent.

Do not introduce unnecessary handoff or approval gates between routine engineering slices.

Do not defer mandatory Phase 02 requirements simply because their implementation is difficult.

Report genuine external blockers distinctly from incomplete engineering.

## 8. Documentation and Progress Tracking

Create or maintain:

`docs/development/phase-2/`

Recommended documentation:

- `PHASE-02-SCOPE.md`
- `PHASE-02-REUSE-INVENTORY.md`
- `PHASE-02-TASK-LEDGER.md`
- `PHASE-02-WEB-FLUTTER-PARITY.md`
- `PHASE-02-TEST-EVIDENCE.md`
- `PHASE-02-HANDOFF.md`

Avoid excessive documentation duplication.

Use a single concise task ledger with:

- Task ID
- Decision IDs
- Owner/workstream
- Status
- Relevant files
- Tests
- Blocker, if any

Keep documentation updated as work happens rather than leaving it all for the end.

## 9. Git Branch and Integration Rules

Keep all approved changes.

Inspect branch ancestry and unique diffs before reusing work.

Do not merge arbitrary experimental branches.

Do not blindly cherry-pick changes already present.

Commit logically grouped and tested changes.

Push development commits to the dedicated Phase 02 branch.

Use additional short-lived sub-branches only when their independence makes integration safer or faster.

Never force-push.

Never rewrite `main`.

Do not merge the Phase 02 branch into `main` without owner authorization and release review.

Do not deploy.

Maintain compatibility with both project repositories.

## 10. Completion Criteria

Phase 02 engineering is complete only when:

- All approved Phase 02 requirements have an implementation or a clearly documented genuine external blocker.
- The merchant web experience is consistent and functional.
- Existing WorkDo functionality has not been lost.
- The Flutter merchant experience has been addressed and parity reviewed.
- Critical API and tenancy contracts are preserved.
- Relevant regression tests have been executed.
- RTL, LTR, responsive behavior and accessibility have been tested.
- Search, notifications, preferences, editing and onboarding meet their approved requirements.
- Remaining issues and risks are documented truthfully.
- No security-sensitive data has been exposed.
- The owner has received an evidence-based completion and acceptance report.

Engineering completion does not automatically mean production readiness.

Phase 01 unresolved release gates remain separately tracked.

## 11. Required Final Report

When Phase 02 engineering reaches its final review, provide:

1. What was already available before Phase 02
2. Which components were reused
3. What was implemented
4. What changed in Laravel
5. What changed in Flutter
6. Which tests actually ran and their results
7. What remains blocked or incomplete
8. Any compatibility or security risks
9. Development branch names and final commit SHAs
10. Readiness assessment and owner decisions required for integration/release

Report clearly and concisely in Arabic.

## 12. Start Now

Begin with the existing Phase 02 branch.

Read the project instructions and the relevant Discovery, Design System, Phase 01 handoff, and Flutter sources.

Check whether Phase 02 work already exists in other branches.

Build the reuse inventory.

Then proceed directly to the first safe implementation slice.

Do not stop after producing a plan.

Do not ask me to approve routine development steps.

Do not claim a feature is completed without verification.

Preserve approved functionality and keep implementation moving until Phase 02 engineering is completed or a genuine external blocker is reached.

**Execute Phase 02 — Merchant Experience.**

### Mandatory Visual Evidence — Phase 02

For every significant UI change, capture before-and-after screenshots using Playwright or an available browser automation tool.

Capture desktop and mobile layouts, Arabic RTL and English LTR, and light and dark modes where applicable.

Save screenshots under `docs/development/phase-2/screenshots/`, using clear filenames and organized folders.

Include visual comparisons in each implementation report or pull request.

Do not mark UI work as visually verified without actually rendering and testing it in a browser.

If browser testing is unavailable, report the limitation explicitly. Never fabricate screenshots or claim visual verification.

Avoid exposing real customer data, credentials, or production information in screenshots.

### Phase 02 — Design & Engineering Toolchain

Use the following tools selectively, based on the task:

**UI/UX**

* Frontend Design: primary visual design skill.
* UI/UX Pro Max: design research, patterns, typography, accessibility and usability.
* Existing Store Syria Design System: authoritative implementation foundation.
* Playwright: browser testing and before/after screenshots.

**Engineering**

* Superpowers: planning, TDD and debugging, reusing existing installed skills.
* Context7: current framework and library documentation.
* Graphify: codebase dependency analysis when necessary.

**Security**

* Security Review: review sensitive code changes.
* Strix: authorized isolated staging security assessments only.

**Optional**

* Claude-Mem: continuity across sessions after reviewing data collection and privacy settings.
* gstack: selected workflows only, avoiding duplicated review processes.
* 21st.dev: visual inspiration; adapt approved ideas to Blade.
* React Bits: inspiration only, not a reason to introduce React.
* GSAP/Lenis: use only where justified by performance, accessibility and actual UX value.

Never install every tool automatically.
Avoid conflicting hooks, duplicated skills, unnecessary dependencies and excessive agent overhead.
Preserve the approved Store Syria architecture, Flutter and Lynomia Chat contracts, and existing Design System.
Produce actual browser screenshots for major interface changes, including desktop/mobile, RTL/LTR and light/dark where applicable.

```
git switch claude/store-syria-p02-merchant-experience

npx skills add anthropics/claude-plugins-official \
  --skill frontend-design \
  --agent claude-code

npx ui-ux-pro-max-cli init --ai claude

# Verify installation
ls .claude/skills/frontend-design/SKILL.md
ls .claude/skills/ui-ux-pro-max/SKILL.md

# Review changes before committing
git status --short
git diff -- .claude
```

---

# END OF THE ORIGINAL PROMPT

---

## Coverage check against the Phase 02 trackers

Required by the instruction that accompanied this file: *"Compare the master prompt against the
existing Phase 02 scope and task ledger to ensure no requirements are missing."*

Performed 2026-10-25 against `PHASE-02-SCOPE.md` and `PHASE-02-TASK-LEDGER.md` at
`7f27c06c`. **Workstream → ledger slice** mapping, and whether the ledger carries a row for it:

| WS | Subject | Slice | Ledger rows exist | Gap found by this check |
|---|---|---|---|---|
| A | Design system adoption | P2-01 | yes (P2-01-1…13) | — |
| B | Workspace & navigation | P2-01 | yes (P2-01-10, -11, -13) | **preferences, favourites and recent pages** sit in WS B here but are tracked only under P2-06-4 / G. Cross-referenced rather than duplicated. |
| C | Merchant dashboard | P2-02 | yes (P2-02-1…12) | — |
| D | Products | P2-03 | **slice row only, no task rows** | every WS D bullet needs a row |
| E | Orders | P2-04 | yes (P2-04-1…8) | — |
| F | Forms & record panels | P2-01 | partial (P2-01-2…5) | **concurrent edit/version conflict** and **"preserve existing AJAX modal contracts"** have no row |
| G | Employee preferences | P2-06 | one row (P2-06-4) | the five sub-bullets are not separately tracked |
| H | Search & notifications | P2-06 | yes (P2-06-2, -3) | the **temporary vs persistent** split is not stated in the rows |
| I | Onboarding & help | P2-06 | yes (P2-06-1) | **contextual field help** has no row of its own |
| J | Other merchant screens | P2-05 | **slice row only, no task rows** | every WS J screen needs a row |
| K | Flutter | P2-07 | yes (P2-07-1…8) | — |

Also present in this prompt and **not** previously tracked as requirements anywhere:

- §6 *"Compare actual frontend performance against a measured baseline."* — no baseline exists.
- §10 *"No security-sensitive data has been exposed."* — a completion criterion, not a task.
- *Mandatory Visual Evidence* — a standing rule per UI change, now quoted here as the authority.

These gaps are **recorded in the ledger** as rows, not fixed by editing this document.

## Later approved clarifications

Recorded separately, dated, never merged into the original above.

### C1 — 2026-10-25 — Continuation priorities

Issued at the start of this session, before the master prompt was supplied. It **narrows the
starting order** without changing scope:

1. Restore Laravel runtime verification: retry `composer install` on the existing lockfile
   (the owner was configuring network access for `git.yoomoney.ru`); **do not remove YooKassa,
   change payment SDKs, or edit `composer.lock` to bypass installation**; then run Unit,
   `OperatorCheckCensusTest`, the real Blade menu parity tests, Contract and Feature, verify real
   DataTables behaviour, and run the security and tenant-isolation regressions, investigating
   every unexpected failure. *"Do not equate static previews with Laravel runtime verification."*
2. Address security risks before expanding exposure: fix ledger findings **F4/F5** (the
   customised sidebar's missing permission and module-entitlement enforcement, and its unescaped
   database-sourced titles) and **F12** (the state-changing GET store-switch), and enforce
   merchant ownership on the unscoped `Order::order_detail()` lookup **before** exposing any new
   order detail or preview surface. *"Never assume encrypted identifiers provide tenant
   authorization. Do not introduce new sensitive endpoints without server-side authorization
   tests."*
3. Complete P2-03 … P2-07. The onboarding component *"is deliberately disabled due to an
   unresolved RTL rendering defect. Diagnose it in the real application before enabling it."*
4. Visual and accessibility quality, including **F8** and **F9**, *"through minimal, accessible
   changes that preserve merchant branding. Escalate any change requiring a new global
   brand-color policy."*
5. Keep the four tracking documents accurate and reconcile the stale handoff statuses.

Plus: *"If a task is externally blocked, document it and proceed to other safe tasks."* and
*"Do not mark issue #120 resolved."*

### C2 — 2026-10-25 — This document

*"Save the original prompt in the repository as
`docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md`"*, preserving the complete original
scope, keeping status in the existing trackers, recording later clarifications separately,
referencing it from `CLAUDE.md` and the handoff, and pushing **to the Phase 02 branch only**.

### C3 — 2026-10-25 — Toolchain, as measured rather than as assumed

The prompt's toolchain section lists `npx` commands to install `frontend-design` and
`ui-ux-pro-max`. **They were not run, and running them would have been a no-op at best.** Measured
on this branch at `7f27c06c`:

```
.claude/skills/  →  banner-design  brand  design  design-system  frontend-design
                    slides  store-syria-laravel-tenancy  systematic-debugging
                    test-driven-development  ui-styling  ui-ux-pro-max
                    verification-before-completion
```

Both named skills are already committed project-local (`frontend-design` is hash-pinned in
`skills-lock.json`), which is strictly better than an account-level install: committed skills
travel with the repository, which is the finding #66 was opened about. An `npx … add` would have
re-fetched a dependency the repository already pins.

**Not adopted, and why:** `context7`, `graphify`, `strix`, `claude-mem`, `gstack`, `21st.dev`,
`React Bits`, `GSAP/Lenis`. `docs/tooling/SKILLS-MANIFEST.md` records a standing owner decision on
each — `gstack`, `claude-mem` and `strix` are **forbidden by owner decision**, and `context7` is
permitted only for public framework documentation with no repository identifier in the query. The
prompt's own instruction — *"Never install every tool automatically"* — is what is being followed.
Playwright **is** used: it is pre-installed in this container and drives `browser-checks-p02.cjs`.
