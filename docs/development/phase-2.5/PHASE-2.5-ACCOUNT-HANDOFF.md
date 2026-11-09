# Phase 2.5 — account handoff

**Written:** 2026-11-09 · **Reason:** the owner is moving execution to a new Claude Code account
because the phase is running late. **Audience:** that account, resuming from GitHub with no access
to this conversation.

> **WE ARE IN PHASE 2.5, NOT PHASE 02.** Phase 02 (Merchant Experience) is closed. Phase 2.5 —
> *Premium Merchant Experience & Visual Excellence* — is an approved extra station **between Phase
> 02 and Phase 03** in the owner's six-phase Master Product Plan. **Do not re-open or re-execute
> Phase 02.** The six official phases and their order are unchanged.

Everything below is read from the repository at the SHAs named. Where a number is measured, the
command that produced it is given. **Nothing here claims work is complete that is not.**

There is an earlier handoff, `ACCOUNT-HANDOFF.md`, written 2026-11-07 for the previous branch
(`claude/busy-edison-k0pjwa`). It is still accurate for the work it covers; this document supersedes
it for branch, SHA and status.

---

## 1. Repositories, branches, SHAs, pull requests

| | Laravel (merchant web) | Flutter (merchant admin app) |
|---|---|---|
| repository | `fullstackfull/syriastore98` | `fullstackfull/StoreSyria_app98` |
| **branch** | **`claude/gracious-volta-duiynj`** | **`claude/gracious-volta-duiynj`** |
| **HEAD (pushed)** | **`77da951b`** | **`e8a26c3`** |
| unpushed commits | **0** | **0** |
| worktree | **clean** | **clean** |
| pull request | [#132](https://github.com/fullstackfull/syriastore98/pull/132) — **draft**, base `claude/busy-edison-k0pjwa` | [#8](https://github.com/fullstackfull/StoreSyria_app98/pull/8) — **draft**, base `claude/busy-edison-k0pjwa` |
| parent PR to `main` | [#130](https://github.com/fullstackfull/syriastore98/pull/130) — open | [#7](https://github.com/fullstackfull/StoreSyria_app98/pull/7) — open |

**Both #132 and #8 must stay DRAFT.** They exist to run CI, not to merge. No merge to `main`, no
force push, no history rewrite — standing owner instructions.

`claude/gracious-volta-duiynj` carries **281 commits** beyond `origin/main`
(`git log --oneline origin/main..HEAD | wc -l`), branching at `53971d4e`.

---

## 2. Test and CI status — measured, not assumed

### Laravel, measured at `cb01b7de` (the last commit that changed code; `77da951b` adds only these handoff documents)

| | result |
|---|---|
| `php artisan test --testsuite=Unit` | **1505 passed** (11,581 assertions) |
| `php artisan test --testsuite=Contract` | **32 passed** (111 assertions) |
| `php artisan test --testsuite=Feature` | **8281 passed, 2 failed, 1 skipped** (42,986 assertions) |
| known-outcome gate | **OK — the observed set matches the manifest exactly** |

**The Feature suite is not green and is not meant to be.** `.github/known-test-outcomes.json`
declares exactly two failures and one skip; the gate asserts that **set in both directions**, so an
undeclared failure *and* an unexpectedly-passing declared failure both fail it. The two declared
failures are `Auth\RegistrationTest::test_new_users_can_register` and
`ProfileTest::test_profile_page_is_displayed`. **Do not add anything to that file to get green.**

Run it exactly this way, or the gate cannot read the skip reasons:

```bash
php artisan test --testsuite=Feature \
  --log-junit storage/logs/feature-junit.xml \
  --log-teamcity storage/logs/feature-teamcity.txt
php .github/scripts/assert-known-test-outcomes.php \
  storage/logs/feature-junit.xml .github/known-test-outcomes.json \
  storage/logs/feature-teamcity.txt
```

### CI on `cb01b7de` — complete

| | check |
|---|---|
| ✅ | Tests (REQUIRED — real suites, PayHere excluded and NOT fixed) |
| ✅ | Tests (REQUIRED — real suites, clean install from composer.lock) |
| ✅ | Merge-base regression gate (new failures only) |
| ✅ | Static checks · Secret scan · PayHere blocker report · Security-sensitive diff: human approval · push report |
| ❌ | **Dependency advisories (composer audit)** |
| ❌ | **Security-sensitive diff: human checklist** |

**Both red checks are deliberate and must stay red until a human acts.**

* *Dependency advisories* reports real upstream CVEs (symfony/routing, symfony/yaml, and three
  abandoned packages). The owner's instruction is to **preserve the gate — do not raise the
  ceiling or suppress advisories**.
* *Security-sensitive diff: human checklist* is the human security acknowledgement. The owner's
  instruction is explicit: **an agent must not create, impersonate, sign or auto-insert it.** It
  requires genuine human review.

### Flutter, measured at `4382202` (`e8a26c3` adds only the handoff pointer)

| | check |
|---|---|
| ✅ | Analyze and test |
| ✅ | Secrets and suppression hygiene |
| ⊘ | `goldens (capture for human review)` — **skipped by design**; `workflow_dispatch` only |

`flutter` is **not installed** in the execution container, so Flutter tests cannot be run locally
and golden baselines cannot be regenerated there. CI is the only verification path.

---

## 3. Phase 2.5 — the standing scope

The goal is a **Premium experience across every merchant screen and the Flutter app**, not an audit
of one area. It covers:

* the design skills the owner committed — `frontend-design`, `ui-ux-pro-max`, `design-system`,
  `ui-styling`, `design`, `brand`, `banner-design`, `slides`
* **every screen reviewed**, not a sample
* **Arabic (RTL) and English (LTR)**, **light and dark**, **desktop / tablet / phone**
* **real screenshots** from the running application — not CSS diffs
* **visual and functional repairs, proved**
* **regression tests** that bite

The authoritative, generated status is `PHASE-2.5-COVERAGE-MATRIX.md`, rebuilt by
`tools/build-coverage-matrix.py` from `PHASE-2.5-SURFACE-INVENTORY.md`. It refuses to run if the
surface count moves, because "a screen must not silently disappear from the inventory".

### Where the phase stands — 295 surfaces

| state | count | of 295 |
|---|---:|---:|
| PREMIUM VERIFIED | 24 | 8.1% |
| REDESIGNED AND VERIFIED | 32 | 10.8% |
| BLOCKED | 13 | 4.4% |
| OUT OF SCOPE | 1 | 0.3% |
| **PENDING** | **225** | **76.3%** |

**Phase 2.5 cannot be presented for acceptance while PENDING is above zero.** It is 225.

---

## 4. What is NOT done — by area

Read `PHASE-2.5-REMAINING-AREAS-WORKLIST.md` for the leads, and treat them as **leads requiring
fresh verification, not guaranteed defects** — the ORDERS audit refuted 90% of what its own finders
raised.

| area | total | PENDING | BLOCKED | done |
|---|---:|---:|---:|---:|
| **Flutter merchant admin app** | 51 | **51** | 0 | **0** |
| CATALOGUE taxonomy | 38 | 24 | 0 | 14 |
| Staff / Roles / Permissions + Merchant Settings & Integrations | 38 | **31** | 1 | 6 |
| Phase 2.5 Wave A | 30 | **30** | 0 | **0** |
| Reports / Analytics, Notifications, communication centre | 26 | 25 | 1 | 0 |
| **Flutter merchant/employee admin app** | 24 | **21** | 3 | **0** |
| Customers and customer management | 20 | 5 | 4 | **11** |
| **ORDERS** | 17 | 11 | 3 | 3 |
| PRODUCTS | 16 | 12 | 1 | 3 |
| Merchant admin NAVIGATION SHELL | 13 | 2 | 0 | **11** |
| Stock / inventory reporting + finance / wallet / payout | 13 | 10 | 0 | 3 |
| Merchant dashboard and KPI presentation | 9 | 3 | 0 | 5 |

**The two Flutter areas are 75 surfaces with ZERO done.** That is the largest single gap in the
phase, and it is the one the current container could not work on, because `flutter` is not
installed. The next account should check whether its environment has Flutter before planning
around it.

### The 13 BLOCKED, and a caution about them

`INVENTORY-TAXONOMY-PROPOSAL.md` (a **proposal**, nothing applied) records the finding that
**BLOCKED currently holds three different claims**:

* **2 rows are not screens at all** — a JSON endpoint and an HTML fragment with no consumer.
* **2 rows are genuinely blocked merchant functionality** awaiting an owner decision — the web
  *Edit customer* screen (a **web/Flutter parity gap**, not missing functionality: the backend
  ships and Flutter already uses it) and *Wishlist create/edit*.
* **9 rows mean "the audit could not determine its state"** — i.e. **nobody has looked**. The
  matrix prints that identically to "we looked and cannot proceed". Those are opposite claims.

**The matrix was deliberately left unchanged** (24 / 32 / 225 / 13 / 1). The owner asked for a
proposal, and changing the denominator is the owner's call.

---

## 5. What was completed in Phase 2.5 — not only ORDERS

The branch carries 280 commits. The packages whose evidence is committed under
`docs/development/phase-2.5/evidence/` (56 directories) include:

### Areas taken to a verified state

* **Merchant navigation shell** — 11 of 13 surfaces done.
* **Customers and customer management** — 11 of 20 done, including the whole PKG-G→PKG-L arc:
  the customer filter bar, the grid card, the status switch, the required-field marker, the
  customer timeline (twice), the abandoned-cart and abandoned-wishlist reports.
* **Merchant dashboard / KPI** — 5 of 9.
* **CATALOGUE taxonomy** — 14 of 38.

### Security and correctness repairs made inside the phase

* **Cross-tenant product-name leak on the customer timeline** (`ActivityLog::products/variants`)
  — six fixes, fifteen tests, each bite-proved.
* **Stored XSS on both abandoned-* reports** — `rawColumns()` with `e()` applied per value.
* **Storefront wishlist product tenancy** — product store predicate and customer↔store binding.
* **AddCustomer permission gap.**
* **`DashboardController::store()`** — proved dead four ways, removed.
* **Customer email uniqueness** — application-level policy, verified on real MariaDB 11.4.9.
* **P2.5-PKG-K** — the abandoned-basket WhatsApp nudge: the recipient was the customer's whole
  record serialised to JSON (a personal-data disclosure to graph.facebook.com), `SendMsgs()`
  returns nothing so the UI reported failure 100% of the time, and the reason was a hardcoded
  "Invalid Auth access token" identical for every cause.

### Accessibility, contrast and Arabic repairs

* **P2.5-PKG-J** — every confirmation dialog in the dark admin: the title was **1.00:1
  (invisible)**, the cancel button **1.04:1**, the confirm button **2.50:1**. Now 16.88 / 7.30 /
  7.82. Reaches **124 triggers across 107 views**, including vendored modules, without editing
  `packages/workdo/` — proved by driving BulkDelete's own dialog.
* **P2.5-PKG-L** — the customer timeline: 1807px → 1234px (−32%), the two unlabelled dates, Arabic
  terminology (`الخط الزمني للزبون`, `الزبون`), **`ar_SY` Levantine month names**, and
  `text-align: left` that was mirroring Arabic cards the wrong way below 767px.
* **P2.5-PKG-M** — `Utility::dateFormat()` used PHP's `date()`, which is **not locale-aware at
  all**. English output is **byte-identical** (unit-asserted and pixel-asserted); Arabic now reads
  `تشرين الثاني 6, 2026`.
* **`نعم ..` / `لا ..`** — a bare trailing double dot on two keys used at ~120 call sites.
* **Nineteen merchant DataTables** — the empty state was English and unstyled.

### Infrastructure built for the phase

* `tools/render-matrix.cjs` — drives the real admin, four combinations, premise-checked.
* `tools/capture-interactions.cjs` — clicks the real control on the real parent screen.
* `tools/audit-dialog-contrast.cjs` — opens the real dialog and composites every translucent layer.
* `tools/shoot-customer-timeline.cjs`, `tools/build-coverage-matrix.py`,
  `tools/check-distinct-surface-captures.py`, `tools/detect-refusal-toast-captures.py` —
  **two CI-blocking evidence guards**: no two captures claiming different surfaces may share
  content, and no capture may show a permission-refusal toast.
* `tools/serve-dev.sh` — the dev server the harnesses need, with the three environment variables
  that are **not optional** and the warning about what exporting them into a test shell does.

### Flutter

* Golden-test infrastructure (route 1), eight baselines in four combinations for `customer-card`
  and `delivery-boy-card`, with capture gated behind a **manual** `workflow_dispatch` so a human
  reviews the pixels — a baseline generated and committed by its own author records whatever the
  code did, defects included, and then asserts it for ever.
* The customer card's missing e-mail, and four defects found in the candidate baselines.
* **`4382202`** records a measured finding: the baselines show a card **stretched to 800px**, which
  the app never renders. 98.2% / 98.3% of each image is the card's own fill; the scaffold
  background is absent. The one-line fix is written out in
  `StoreSyria_app98/docs/design/GOLDEN-BASELINE-FRAMING.md` and **deliberately not applied**,
  because it invalidates all eight baselines and `flutter` is not installed here.

---

## 6. The ORDERS audit

Full detail, with file paths, line numbers, reproduction commands and the three refuter verdicts
for every surviving finding: **`PHASE-2.5-ORDERS-AUDIT-FINDINGS.md`**, beside this file.

`363 agents, 0 errors` · **115 raised → 103 refuted (90%) → 12 confirmed**.

**All twelve are `NOT STARTED`**, each re-verified by hand against `cb01b7de`.

| # | screen | finding | status |
|---|---|---|---|
| **12** | board | **Pressing *Move* with no status chosen e-mails and SMSes the customer a status-change notification and writes an order note, for a change that did not happen** | NOT STARTED |
| 5 | list | the table's search returns nothing for customer name, payment type, status and date — `filterColumn` count is **0** | NOT STARTED |
| 7 | grid | the order reference truncates so **two different orders render identical cards** | NOT STARTED |
| 9 | grid | the only tooltip the truncated title can show is hardcoded to the words "Invoice ID" | NOT STARTED |
| 11 | grid | the delete dialog does not name the order — a destructive action confirmed against an indistinguishable target | NOT STARTED |
| 10 | grid | no search, no filter, no sort, and it silently discards the filter the merchant arrived with | NOT STARTED |
| 1 | list | `Walk-in-customer` → `المشي في العميل` — "the walking inside the customer" | NOT STARTED |
| 8 | grid | the same key, same screen family | NOT STARTED |
| 2 | list | the Actions column is headed `العمل` ("the work / the labour") | NOT STARTED |
| 3 | list | every bulk-select checkbox has no accessible name | NOT STARTED |
| 4 | list | the `.ss-select` chevron is a hardcoded hex inside a data URI → **2.39:1** in dark | NOT STARTED |
| 6 | list | the Order Id cell is vendored `btn btn-primary` colliding with the design system → **2.63:1** | NOT STARTED |

**#12 is not a design defect.** It sends a real message to a real customer about something that
did not happen. It should be first.

---

## 7. How to work in this repository

`CLAUDE.md` at the root is the short version of what will bite you. The parts that matter most:

* **Laratrust, not spatie.** `@can()` is **always false**. There is **no super-admin bypass** —
  test the operator first by string-comparing `users.type`.
* **`null_tenant => 'open'`** — when no tenant resolves the global scope adds **no predicate**.
  `Product` is **not** in `scoped_models`; `Customer` and `Cart` are.
* **`actingAs($x, '<guard>')` makes the principal the DEFAULT-guard user**, so a test can resolve a
  tenant production never resolves. Documented at `config/tenancy.php:338-350`.
* **79 of 95 models are `Cachable`.** A raw `DB::table()->update()` leaves Eloquent reads —
  route-model binding included — serving the pre-write row.
* **`php -l` does not expand Blade.** Compile through `app('blade.compiler')->compileString()`.
* **Tabler icon names must be grepped** against `public/assets/fonts/tabler-icons.min.css` before
  use. Roughly one in three guessed names does not exist.
* **`--ss-fg` is theme-switching**; `--ss-grey-900` is the fixed dark ink for surfaces that stay
  light in dark mode. Getting this wrong produces a *worse* defect that looks like a fix.
* **`store-syria-ds.css` forbids physical edges** (`left`, `right`, `margin-left`) and requires
  every selector to be confined by an `.ss-` class or `data-ss-` attribute.
  `DesignSystemStylesheetScopeTest` enforces both.
* **Three inventories pin line numbers in `app/Models/Utility.php`.** Any edit to that file moves
  them and three Feature tests fail mechanically. `SecurityLogInventoryTest` has its own resync
  tool (`docs/development/phase-1/tools/resync-observability-inventory.py --write`) that **refuses
  to write when a line was added rather than moved**.

**Run the full Feature suite before pushing anything wider than a view.** This was learned the
expensive way inside this phase: `f56e5bce` was pushed on green Unit and Contract alone, and a
one-key translation fix broke a test in a module that had been *working around* that very defect.
Both required CI suites went red.

### Dev environment

```bash
docs/development/phase-2.5/tools/serve-dev.sh      # 127.0.0.1:8899
```

The fixture is `database/p2dev.sqlite` (gitignored). Known-good counts: `users=5 orders=64
products=32 customers=3`. Sign in as `merchant@example.test` / `password`.

**Never `export` the fixture's `DB_DATABASE` into a shell that then runs the test suite.** It has
emptied the fixture twice. `tests/bootstrap.php` now aborts the run before any test loads, but the
guard is a floor, not a licence.

---

## 8. First task for the new account

**Fix ORDERS finding #12.** It is the only confirmed finding that causes something irreversible to
leave the system — an e-mail and an SMS to a customer about a status change that did not happen,
plus an order note reading `Order status changed to .`.

The evidence, the exact lines and both halves of the proposed fix (a client-side guard that cannot
be the only one, and a server-side refusal before the notification block) are in
`PHASE-2.5-ORDERS-AUDIT-FINDINGS.md` under **#12**.

Before editing: `OrderController.php:1737-1813` is an order-status write path. The owner reserves
**material changes to business rules, payments, stock and public API compatibility**. Refusing an
empty status is a guard, not a rule change — but read the handler first and say which it is.

Then, in order: #5 and #10 (a merchant cannot find an order), then the #7 / #9 / #11 chain (a
destructive action against an indistinguishable target), then #1 / #8 / #2 (Arabic), then #3 / #4 /
#6 (accessibility and contrast).

After ORDERS, the largest gap is **Flutter: 75 surfaces, zero done** — check whether the new
environment has `flutter` before planning around it.

---

## 9. Standing owner constraints — still in force

* **No merge to `main`. No force push. No history rewrite.** PRs #132 and #8 stay **draft**.
* **No production access, migration or deployment.**
* **Never modify production or persistent customer data.**
* **Do not create, impersonate, sign or auto-insert the human security acknowledgement.**
* **Do not weaken the Composer advisory gate** — no raised ceiling, no suppressed advisories.
* **Do not weaken Phase 01 guards**, and do not add to `.github/known-test-outcomes.json` to get
  green.
* **Do not edit `packages/workdo/`** (first-party `LynomiaChat` excepted).
* **Never commit secrets, database dumps or real customer data.**
* **Return 403, not 401**, for an authenticated user lacking permission — the shipped Flutter app
  forces a logout on any 401.
* **Registered but unimplemented routes must not be presented as working screens**, and no backend
  commerce functionality may be invented to raise the verified count.
* **Do not reuse one screenshot as evidence for two surfaces** — a CI guard enforces this.
* **Security reviewers and audit agents are explicitly READ-ONLY**, and the worktree must be
  checked after each review. This is not ceremony: an agent in an earlier round edited a Blade file
  and then a file in the *other* repository, because the prohibition named only one path.
* **No PREMIUM VERIFIED promotion without the owner's visual acceptance.** The customer timeline is
  repaired and **remains unpromoted** for exactly this reason.

---

## 10. Nothing is in flight

* No background agents, workflows, servers or watchers are running. The ORDERS audit
  (`wf_cd4c60b8-8cd`) finished before this handoff was written; no new audit cycle was started.
* Both worktrees are clean; both branches are fully pushed (0 ahead, 0 behind).
* No secrets, environment values or customer data are in either repository.
