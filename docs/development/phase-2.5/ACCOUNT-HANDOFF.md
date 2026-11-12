# Store Syria — account handoff

**Written:** 2026-11-07 · **Reason:** the executing Claude Code account reached its usage limit.
**Audience:** the next Claude account, resuming from GitHub with no access to the prior conversation.

Everything below is read from the repository state at the SHAs named. Where a number is measured,
the command that produced it is given. **Nothing here claims work is complete that is not.**

---

## 1. Branches, SHAs, pull requests, CI

| | Laravel | Flutter |
|---|---|---|
| repository | `fullstackfull/syriastore98` | `fullstackfull/StoreSyria_app98` |
| branch | `claude/busy-edison-k0pjwa` | `claude/busy-edison-k0pjwa` |
| HEAD | `7ccf518850f148f452e59ca7d9a522dcc463e7d0` | `57f3ae79d126600b540f02b16d069e0984a67975` |
| pull request | [#130](https://github.com/fullstackfull/syriastore98/pull/130) (open, base `main`) | [#7](https://github.com/fullstackfull/StoreSyria_app98/pull/7) (open, base `main`) |
| worktree | clean | clean |
| unpushed commits | 0 | 0 |
| commits ahead of `main` | — | 21 |

**Both branches are fully pushed. Nothing is stranded locally.**

### CI on Laravel `7ccf5188` — 8 of 10 green

Green: `Tests (REQUIRED — clean install from composer.lock)`, `Tests (REQUIRED — PayHere excluded)`,
`Static checks (no vendor needed)`, `Merge-base regression gate (new failures only)`, `Secret scan`,
`Is the PayHere blocker still there? (report)`, `Security-sensitive diff: human approval`,
`Security-sensitive diff: push report`.

Red, **both expected and neither caused by this branch**:

1. **`Dependency advisories (composer audit)`** — 66 advisories against `AUDIT_CEILING: 58`.
   `composer.lock` is byte-identical to `main`'s; the public advisory database grew. Open owner
   decision (see §3). The ceiling must **not** be raised — a standing directive forbids it and it
   was deliberately lowered 70→58.
2. **`Security-sensitive diff: human checklist`** — red **by design**. It requires a human
   acknowledgement line in the PR description. **Do not write it.** Owner decision D-42.5 is
   explicit, and the gate's own header records that an agent once satisfied it by accident while
   writing a warning that it was *not* satisfied (P1-SEC-53).

Both already have detailed PR comments; the most recent says the reasoning is not to be
re-derived per push. **Do not add further comments for these two.**

---

## 2. Approved plan and current scope

The owner-approved **six-phase Master Product Plan / Decision Register v1.0 (2026-10-14)** defines
scope. Older differently-numbered roadmaps do not override it.

| Phase | Scope | State |
|---|---|---|
| 01 Foundation | security, CI, regression gates, design-system foundation | engineering complete (2026-10-24), **not release-ready or accepted** |
| 02 Merchant Experience | merchant dashboard, product/order UX, web + Flutter parity | engineering authorised; not authorised to merge/release |
| **02.5 Premium Merchant UI/UX** | **a polish stage over Phase 02's surfaces — this is the active phase** | **in progress** |
| 03 Storefront · 04 Commerce · 05 Marketing & Analytics · 06 SaaS & Super Admin | — | not started |

**Phase 2.5 completion criterion is the full 295-surface inventory, not a wave number.** Phase 2.5
cannot be presented for acceptance while `PENDING` is above zero.

Authoritative in-repo references: `CLAUDE.md` (counter-intuitive facts that have each produced a
real defect — **read it before writing any controller, policy, middleware or query scope**),
`docs/development/phase-2.5/PHASE-2.5-DECISION-REGISTER.md`,
`docs/development/phase-2.5/PHASE-2.5-TASK-LEDGER.md`,
`docs/development/phase-2/PHASE-02-MASTER-EXECUTION-PROMPT.md` (verbatim, not to be edited).

---

## 3. Binding owner decisions, and what is still unresolved

### Binding (recorded in `PHASE-2.5-DECISION-REGISTER.md`)

- **D-39** (2026-11-03) — Phase 2.5 implementation **is authorized and started; deployment is NOT.**
- **D-40** (2026-11-03) — **repair existing broken merchant functionality, within boundaries.** Each
  repair is gated on conditions listed in the register; it does not authorise new features.
- **D-41** (2026-11-03, corrected the same day) — **one brand across web and Flutter, taken from the
  real logo.**
- **D-42** (2026-11-04) — **seven follow-up repairs authorized for development only** (D-42.1 …
  D-42.7). **D-42.5: do NOT create or simulate a human security acknowledgement.**

### Standing prohibitions (from the owner execution directive and `CLAUDE.md`)

No merge to `main`. No production deployment. No security acknowledgement. Do not rewrite history
or force-push. Never commit secrets, dumps or real customer data. Do not edit vendored
`packages/workdo/` (except first-party `LynomiaChat`). Do not change payment logic, wallet balances,
tenant ownership, financial calculations or commercial rules for aesthetic reasons. Avoid
unnecessary push cycles that cancel CI.

### Unresolved — awaiting the owner

| # | Decision needed |
|---|---|
| 14 | Coupon code uniqueness is not store-scoped |
| 35 | `--ss-brand` resolves to cyan `#2AC3E5` in dark mode, so the primary action changes hue with the theme |
| 39 | Dependency-advisory sweep: proposal written and measured — 66 clears to 5, and the ceiling goes **down** 58→5. Changes `composer.lock`, which is owner-gated |
| 50 | WooCommerce/Shopify import screens need a sandbox credential or stubbed connection before they can be rendered at all |
| 57 | **Flutter rendering evidence** — three costed routes, see §5 |
| 80 | Six `edit()` methods check ownership but no permission |
| 83 | Abandoned carts show an item count but never a **value**. Data exists (`carts.qty`, `carts.price`) so it is a new aggregate, but it is a new money figure on a merchant screen and §4-D/§8 reserve financial changes |
| 84 | The abandoned-cart screen has no operator limb, so an operator with an empty `current_store` is served **store 1** by `getCurrentStore()`'s literal. The list leg has the identical property, so fixing only the show handler would make the two disagree |

---

## 4. The 295-surface coverage matrix, and where the evidence is

**Generated, not hand-written.** `docs/development/phase-2.5/tools/build-coverage-matrix.py` rebuilds
`PHASE-2.5-COVERAGE-MATRIX.md` from `PHASE-2.5-SURFACE-INVENTORY.md` and refuses to run if the
surface count moves. Verified current at this SHA:

```
python3 docs/development/phase-2.5/tools/build-coverage-matrix.py --check
  -> coverage matrix is current: 295 surfaces
```

| state | count | of 295 |
|---|---:|---:|
| PREMIUM VERIFIED | 24 | 8.1% |
| REDESIGNED AND VERIFIED | 30 | 10.2% |
| BLOCKED | 9 | 3.1% |
| OUT OF SCOPE | 1 | 0.3% |
| **PENDING** | **231** | **78.3%** |

**54 of 295 surfaces are verified. 231 are not.** `VERIFIED` means the running application was
driven and captured in **all four combinations** (en-LTR, ar-RTL × light, dark). Inheriting a shared
component's repair is *not* verification.

### Evidence

- **39 directories** under `docs/development/phase-2.5/evidence/`, **707 committed captures**
  (`git ls-files … | grep -c '\.png$'`).
- The matrix generator **checks the captures exist**: a `VERIFIED` entry whose four combination files
  are absent flips that surface to `BLOCKED` naming the missing combination. Proved by mutation
  (hide one capture, re-run, the surface flips).
- Two CI guards police the evidence itself:
  `tools/check-distinct-surface-captures.py` (two captures claiming different surfaces must not share
  content — **this failed on `3841696e` and caught a real defect of mine**, see §6) and
  `tools/detect-refusal-toast-captures.py`.
- Reproducible tooling is committed under `docs/development/phase-2.5/tools/` and
  `docs/development/phase-2/tools/` (notably `set-admin-preview-state.php`, which sets theme /
  direction / locale for a capture run).

---

## 5. Completed packages, outstanding Laravel work, Flutter blocker

### Completed and pushed

Waves 1–33; Package A (navigation shell + dashboard, slices 2–9); Package B in part (products and
catalogue); **Package C — the abandoned carts/wishlists family, 6 surfaces** (the most recent work,
see §6).

### Outstanding Laravel work (largest first)

| Area | Outstanding |
|---|---|
| Phase 2.5 Wave A | 30 pending |
| Staff / Roles / Permissions + Settings & Integrations | 31 pending (1 blocked) |
| Reports / Analytics / Notifications | 25 pending (1 blocked) |
| CATALOGUE taxonomy | 24 pending |
| PRODUCTS | 12 pending |
| Customers and customer management | 11 pending |
| ORDERS | 11 pending (3 blocked) |
| Stock / finance / wallet / payout | 10 pending |
| Dashboard and KPI presentation | 3 pending |
| Navigation shell | 2 pending |

Open engineering items also tracked: #40 (verify the advisory review's first-party defect claims),
#59 (soft buttons have no visible boundary — 92 of 130 renderings across 10 screens fail SC 1.4.11),
#61 (77 bare labels remain, 72 of them not a rename), #75 (give `x-ss.bar-chart` a magnitude
reference across all 10 usages), #86 (`show_toastr` discards its `title` argument, so **every toast
title in the admin is English** — `public/js/custom.js:342` calls `notifier.show('Error', …)` with a
hardcoded literal; shared JS reaching every merchant screen, so it needs its own slice),
#87 (**security** — `WishlistController::product_wishlist` reads any tenant's product by
caller-supplied id, and `wishlistCount` reads a caller-supplied customer id).

### Flutter — 75 surfaces, 0 verified

Two inventory areas: "Flutter merchant admin app" (51) and "Flutter merchant/employee admin app"
(24). **0 premium, 0 redesigned, 3 blocked, 72 pending.** 21 commits of accessibility, contrast,
tap-target, Arabic and brand work **are** on the branch, but none of it is verified by rendering.

**The blocker is verification infrastructure, not permission to abandon Flutter** (directive §5).
Stated precisely with three costed routes in the Flutter repo at
`docs/PHASE-2.5-FLUTTER-RENDERING-EVIDENCE-BLOCKER-AR.md`:

1. **Golden (reference-image) tests** — no new cost, no approval needed.
2. **Android emulator in CI** — no new cost.
3. **Real devices** — owner decision: approval + billing.

Route 1 or 2 can begin without an owner decision. **Flutter web is explicitly not device
verification** and must not be presented as such.

---

## 6. The newly discovered customer-filter defects — task #88

Found while opening the customer family. **Both measured, neither fixed.** These give the merchant
**wrong answers**, which is materially worse than a cosmetic defect.

### (a) The customer list filter returns wrong results — `app/DataTables/CustomerDataTable.php`

- **line 211:** `$requestData['selected_name'] === 'Equal' && $AOV = $text_field_float` — an
  **assignment**, not a comparison. PHP returns the assigned value, truthy for any non-zero input, so
  **"AOV Equal 500" matches every customer with a non-zero AOV.**
- **`No. of Orders` and `Total Spend`** each have **two branches keyed on `'Less Than'`**, the second
  testing `>`. So "Less Than 5" matches `<5` **and** `>5` — everything except exactly 5.
- **Neither field has a `'More Than'` branch at all**, so that operator returns **nothing**.

**Why it survived:** `tests/Feature/Merchant/CustomerListFilterApplyTest.php` has 13 tests but
exercises only `'Includes'` (4×) and `'After'` (3×). Measured:
`grep -o "'Less Than'\|'More Than'\|'Equal'" … | sort | uniq -c` → **zero**. Not one numeric
operator is driven.

### (b) The customer grid (card view) filter has never worked, by any path

Measured live on `/customer-grid`:

- The field select works and the Apply click **does** fire
  `GET /customer-filter-data?text_field=&selected_name=Includes&customer_field=Name`.
  (The inventory's recorded finding says "zero requests on click" — that was about the **old list**
  view, which has since been repaired. **Correct the finding when you fix this.**)
- `CustomFilterData` returns `response()->json()` of `customer/filter.blade.php`, which renders
  **`<tr>` table rows** — the *list's* fragment.
- The grid's JS injects it into `#service-filter-data`, which **does not exist in that view**
  (0 occurrences). Adding the container would not help: `<tr>` injected into a `div` is stripped.
- `customer_grid_view()` **ignores every filter param** — it only paginates — so there is no
  server-side path either.
- After P2.5-R-02 repaired the list to a plain GET form, **the grid is the only caller of
  `customer.filter.data`**.

---

## 7. Recommended repair strategy for #88, and the required review

1. **Fix the three operator defects first**, each with a test that **drives that operator** and fails
   before the fix. `'Equal'` → `==`; add the missing `'More Than'` branches; correct the duplicated
   `'Less Than'`. Treat the current behaviour as the bug, not the specification.
2. **Extract the filter** out of `CustomerDataTable::query()` into one reusable place so the list and
   the grid share a single semantics. **Keep `CustomerListFilterApplyTest` green throughout — that is
   the proof the extraction preserves behaviour.** Do not reimplement ~100 lines in the controller.
3. **Give the grid a real GET form** submitting to `customer.grid`, matching the list's
   already-approved **P2.5-R-02** pattern (a plain GET form; the filtered address becomes
   bookmarkable), and have `customer_grid_view()` apply the shared filter. The AJAX path and the
   `#service-filter-data` write then go away.
4. `customer.filter.data` and `customer/filter.blade.php` will then have **no caller**. **Record that
   rather than deleting them** — `CustomFilterData` is a guarded endpoint and removing routes is a
   separate, riskier change.
5. **Verify in all four combinations** and keep the captures distinct (see the guard in §4).

### Required review — not optional

This filters customer data from **request input**, so `CLAUDE.md` requires the
**`tenant-authz-reviewer`** subagent (`.claude/agents/tenant-authz-reviewer.md`) **before
committing**. On this branch that review has twice found real defects in the fix itself, including a
**live destructive cross-tenant write** nobody was looking for. Treat it as load-bearing, not a
formality. Give it the code and what you have ruled out — not your conclusion.

**Two disciplines that repeatedly paid off here, and should be kept:**

- **Write the failing test first, then revert the fix and confirm it fails again.** A test that
  passes for the wrong reason is this codebase's most common failure mode.
- **Mutate every guard you add.** On the most recent slice, **four** guards across three handlers
  looked tested and were not: `where('store_id', null)` compiles to `store_id IS NULL`, so with no
  null-store row in the fixture the handler refused for the *predicate's* reason while the test
  claimed to prove the *guard*. None of it was visible by reading the code.

---

## 8. Uncommitted changes, agents, jobs, local-only files

- **Uncommitted changes: none.** Both worktrees are clean (`git status --short` → 0 lines).
- **Unpushed commits: none** in either repository.
- **Active agents: none.** Two `tenant-authz-reviewer` subagents ran during the final slice and both
  completed; their findings are recorded in the commit messages and in
  `evidence/pkg-c-abandoned/README.md`.
- **Running processes: none.** The local dev server is not running.
- **Local-only, NOT in git, and lost with this container:**
  - `database/p2dev.sqlite` — the dev fixture (1.5 MB, gitignored via `database/.gitignore`).
    **Rebuildable:** the seeder is committed at
    `docs/development/phase-2.5/tools/seed-dev-interaction-fixtures.php` (and `--remove` reverses it;
    it is **additive**, so `--remove` before re-seeding if you need exact counts).
  - ~330 ad-hoc Playwright capture scripts in the session scratchpad. **Not needed** — the
    reproducible tooling is committed under the two `tools/` directories in §4.
- Three background Feature-suite runs were lost to a container restart mid-session. The suite was
  re-run afterwards on the pushed tree and its verdict is in §9.

---

## 9. Next executable task, tests to run, known risks

### Next executable task

**Task #88, per the strategy in §7.** It is fully scoped, the defects are measured, and it needs no
owner decision. Everything required is in §6–§7.

A second, independent option needing no owner decision: **Flutter route 1 or 2** from §5, which would
begin unblocking 75 surfaces that currently have **zero** rendering evidence.

### Tests

```bash
php artisan test --testsuite=Unit        # 1429 tests. CI RUNS THIS FIRST AND BLOCKS ON IT
```

```bash
php artisan test --testsuite=Contract    # 27 tests — the shipped Flutter app's API contract. Blocking
```

```bash
php artisan test --testsuite=Feature --log-junit storage/logs/feature-junit.xml --log-teamcity storage/logs/feature-teamcity.txt
```

```bash
php .github/scripts/assert-known-test-outcomes.php storage/logs/feature-junit.xml .github/known-test-outcomes.json storage/logs/feature-teamcity.txt
```

**Measured on this exact HEAD:** Unit **1429 passed**; Contract **27 passed**; Feature **8092 passed,
2 failed, 1 skipped**, and the gate reports *"the observed outcome set matches the manifest exactly —
2 declared failures, 1 declared skip, 0 errors, and nothing undeclared."*

**The Feature suite is not green and is not meant to be.** The two failures are declared by name in
`.github/known-test-outcomes.json` (`Tests\Feature\Auth\RegistrationTest::test_new_users_can_register`
and `Tests\Feature\ProfileTest::test_profile_page_is_displayed`). The gate asserts the set in **both
directions** — an undeclared failure *and* a declared failure that starts passing both fail it, so a
resolved entry must be **deleted**, never left.

Also run `/tenant-check` (`.claude/commands/tenant-check.md`) for the tenancy and security suites.

### Known risks

1. **Never edit files while a suite or a capture run is in flight.** Clearing compiled views under a
   running Feature suite produced a spurious *"4 failed"* on this branch. A number from an
   invalidated run is not evidence; stop the run and start again.
2. **A test can pass for the wrong reason, and in this codebase usually does.** `Customer`, `Cart`
   and `Product` behave differently under the tenancy scope (`CLAUDE.md` has the table — `Product`
   adds **no** predicate for anyone). Assert through `withoutGlobalScopes()`. Always include a
   **positive control**: a cross-tenant assertion that passes because *nothing rendered* has cost
   this project several false readings.
3. **79 of 95 models are `Cachable`.** A raw `DB::table()->update()` leaves Eloquent reads — route
   model binding included — serving the pre-write row. Write through Eloquent.
4. **SQLite hides real MySQL defects.** The suites run on SQLite `:memory:`; production is MySQL.
   For anything schema-sensitive use a real MySQL — see the pattern in
   `tests/Support/Security/bin/`.
5. **Laratrust, not spatie.** `@can('Some Permission')` is **always false**. Use
   `$user->isAbleTo(...)`, and **test the operator first** — there is no super-admin bypass.
6. **Successive pushes cancel in-flight CI.** Batch work; do not push to watch CI.
7. **Security incident P1-SEC-40 (#120)** is coordinated independently. UI work does not remediate
   the database-dump exposure.

---

## 10. The four mandatory design skills, and the premium quality bar

The owner directive requires **all four** skills be applied, and says: *"Do not invent skill usage."*
All four are committed in-repo, so they work in any environment:

| skill | use for |
|---|---|
| **`frontend-design`** | aesthetic direction that does not read as a templated default |
| **`ui-ux-pro-max`** | patterns, typography, accessibility |
| **`design-system`** | token architecture and component specification |
| **`ui-styling`** | component and styling discipline |

They **advise**; the **Store Syria Design System stays authoritative** —
`public/css/store-syria-ds.css` and `docs/design-system/`.

### What "premium" means here

- **A real visual transformation** — not contrast repairs, CSS tweaks or Bootstrap-class swaps.
  **When a layout is weak, redesign its composition** instead of applying another minor patch.
- **No emojis as interface icons.**
- **Preserve every merchant function, data field, permission and business workflow.**
- **Do not mark a screen premium because it renders, returns HTTP 200, or passes WCAG.** The most
  recent slice is the cautionary example: the abandoned-cart "Show Products" modal answered **200**
  with a correct permission and a complete UI, and had **never worked** — the route placeholder said
  `{cartId}` while the handler keyed on `customer_id`, so clicking it showed the sidebar. Only a
  **click** could find it.
- **Real screenshots in all four combinations** (AR RTL, EN LTR, light, dark). **Do not fabricate,
  duplicate or overclaim evidence** — and note that CI enforces this: the distinct-capture guard
  failed this branch when two captures of different surfaces were byte-identical.
- The stack is **Blade + the vendored Bootstrap 5 WorkDo theme + `public/css/store-syria-ds.css`** —
  **not Tailwind** (installed but never loaded; `@vite` appears in zero views).
- **Report to the owner in Arabic** after each substantial package. The most recent is
  `docs/development/phase-2.5/PHASE-2.5-PACKAGE-C-REPORT-AR.md`.

### One recorded design decision not to "fix"

`.modal-content` measures `rgb(255,255,255)` in dark mode. That is the **P2.5-A-01/A-02 decision**
(recorded at `public/css/store-syria-ds.css:3611` and `:5786`): the host keeps the modal a white
sheet and the design system repaired the **ink** on it. It is not a light surface that escaped the
theme. Any design-system component placed inside a modal must carry `data-ss-theme="light"`.

---

**Phase 2.5 is not complete and is not submitted for visual acceptance.** 231 of 295 surfaces are
PENDING and all 75 Flutter surfaces lack rendering evidence. No merge to `main`, no deployment, and
no security acknowledgement.
