# Phase 03 — consolidated engineering report

**Branch:** `feature/phase-03-storefront`.
**State:** M0 through M7 engineering complete. **Not released. Not merged to `main`.**
**Date:** 2026-11-15.

This is the single report the Phase 03 master directive asks for, covering M4 through M7. M0–M3
are summarised for continuity; their own documents hold the detail.

---

## 1. What exists now that did not before

| | |
|---|---|
| **M0–M2** | storefront harness and two-tenant fixtures; a measured baseline across `stylique`, `techzonix`, `greentic`; dark mode, per-store appearance, **160-load cross-store matrix with 0 axe WCAG A/AA violations** |
| **M3** | the theme data model: atomic publish, rollback, preview, conflict detection, theme-isolated section ordering, four additive migrations with engine-enforced integrity, six two-process races proved on MariaDB |
| **M4** | the theme editor a merchant actually uses: sections, draft, private preview, publish, rollback, history, and **one authorization boundary on all eight theme routes, both verbs** |
| **M5** | the official Store Syria website — seven public pages under `/platform` |
| **M6** | the bilingual documentation centre — `/platform/docs`, 5 categories, 16 articles, both languages |
| **M7** | integration: 44 browser loads in four combinations, axe, click-through journeys, link and anchor integrity, lab vitals |

## 2. M4 — the theme editor

Closed as `fe555adc`. Nine packages, M4-0 through M4-9. The substance:

- **M4-0** found the four GET theme routes had **no test of any kind** — every existing theme test
  was a POST. Nine now drive them on rendered content, with three named false positives closed
  (an XSS-middleware redirect reading as authorization, an empty gallery answering 200, and the
  empty-content-area shape).
- **M4-1** moved the read path off `getCurrentStore()` — the last of eight methods using it — and
  contains `{theme}` and `{slug}` before any path is built.
- **M4-2**, on the owner's explicit decision, closed a measured **GET/POST inversion**:
  zero-permission staff could not open the editor yet could drive every write by direct POST, and
  `theme.file.get` had no authorization at all. Live writes now require the verified tenant owner,
  a same-tenant holder of `Manage Themes`, or the operator naming a store explicitly. A global
  permission alone never authorizes another tenant, and the operator never falls back to store 1.
- **M4-4** captures a store's **first version lazily**, on first editor open or draft save: M3
  built rollback and no store had anything to roll back to, and a backfill migration is a
  production write the owner reserves.
- **M4-5** gave the draft preview a **request-scoped seam** after three obvious ones were each
  measured closed, and renders through the real storefront handler rather than a second renderer.
- **M4-7** added a **third boundary**, `theme.draft` (membership), beside `theme.editor`
  (management) — inside the management group the draft route was unreachable for exactly the staff
  it exists for.
- **M4-9** removed a jQuery UI load **from the vendor's public demo site** into authenticated
  merchant pages, and validated `updateOrder()`'s previously unexamined input.
- **M4-8** brought the editor and all three themes' taxonomy to **100% Arabic coverage**, pinned by
  a test that walks the real theme JSON.

## 3. M5 — the official website

Seven pages: overview, features, pricing, getting started, FAQ, contact, legal, with a shared
responsive bilingual header and footer.

**The LandingPage foundation is kept, not replaced.** `/` still renders the module. These are
first-party routes because `composer.json` carries no PSR-4 entry for `Workdo\`, so
`class_exists('Workdo\LandingPage\Entities\LandingPageSetting')` is **false** until `loadAddons()`
runs — a view naming one of those classes is a fatal error in every test run — and the module's
other public surface stores its HTML in a database row, which would need a production seeder and
would put marketing copy outside git.

**Route precedence is load order, and it is proved.** Moving the identical platform group below
`require customer.php` makes `/platform/faq` resolve to `HomeController`. The mirror risk is closed
too: no store can take the slug `platform`.

**No price appears in any view.** `plans` is read, sorted in PHP (the column is a string), filtered
to enabled rows, and an installation with no plans says so. **`-1` is the unlimited sentinel, not
`0`** — measured in three places; a `0` permits zero.

**Capability copy has anchors.** 34 claims, each module-backed one rendered only while its package
directory exists, with the page tied to the filtered list in both directions.

**Contact and legal publish nothing invented**, and both are recorded as owner content items.

## 4. M6 — the documentation centre

`/platform/docs`, 42 Markdown files in `resources/docs/`, with search, breadcrumbs, a table of
contents, related articles, a pager and a script-free mobile nav. Not the vendored `/api-docs`.

**Search is PHP over a cached index, and a `LIKE` query would be worse**: MySQL's collation folds
Latin case and accents and does nothing about Arabic orthography. Eleven folding rules, each its
own named test, so a merchant typing `مسوده` finds `مسوّدة`; without them that search returns
nothing, silently. Emptying the map reddens 12 tests.

Markdown renders with `html_input => strip` and `allow_unsafe_links => false`. Heading ids are
post-processed so the **same** `slug()` builds the id and the contents link.

An untranslated article is served in English **with a visible notice**, never a 404 and never
silently — driven against a real gap the test creates and removes.

## 5. M7 — integration

44 loads: 11 surfaces × {en, ar} × {light, dark}.

| | |
|---|---|
| premises satisfied | **44 / 44** |
| off-origin requests | **0** |
| console errors | **0** |
| light/dark pairs whose background really differs | **22 / 22** |
| WCAG A/AA violation nodes | **0** |
| axe best-practice violation nodes | **0** |
| journeys completed | **3 / 3** |
| internal links / in-page anchors | **64 / 32**, none broken |
| worst body contrast | **13.73:1** |
| worst lab LCP / CLS | **224 ms** / **0.0234** |

Three real defects found by rendering rather than reading — a public site following the *merchant
admin's* dark-mode setting, a 3.64:1 brand text colour, and a landmark nested inside `<main>` — all
fixed. Two harness defects recorded because each looked like a product defect.

## 6. What an independent review found, and what changed

`tenant-authz-reviewer` reviewed M5 and reported the seven pages clean as an authorization surface
— no tenant, merchant or customer data read or printed, no `auth()` call, no request input, no
credential or payment path — and **five findings, all fixed**:

1. **Both route-name guards would have failed CI** on the group-level `->name()`. Both scanners now
   track group name and URI prefixes from the token stream; falsified in both directions.
2. **The site advertised plans the operator had disabled.** `plans.is_disable` is inverted; the
   unfiltered read was previously operator-only, so the public page was wider than a merchant's.
3. **The slug reservation guarded only the mint path.** Two more writers assign `stores.slug` from
   request input, both merchant-reachable; a store slugged `platform` would have served its
   customer login and registration forms at `/platform/login` and `/platform/register`. Now
   central, with each guard falsified separately.
4. **A caller-inventory comment was wrong in both directions**, corrected in the method and in the
   test that repeated it.
5. **`getSuperAdminAllSetting()` returns the raw settings bag**; both platform call sites now wrap
   it, though nothing printed it.

## 7. Engineering gates

| Gate | Result |
|---|---|
| Unit | **1845 passed, 1 skipped** (15,903 assertions) |
| Contract | **45 passed** (182 assertions) |
| Feature | **8,828 passed, 2 failed, 1 skipped** (45,015 assertions), 8,831 testcases |
| known-outcome gate | **PASS** — the observed set matches the manifest exactly: 2 declared failures, 1 declared skip, 0 errors, nothing undeclared |
| Platform and documentation suites | **169 passed** (881 assertions) |
| `guard-test-isolation` | PASS |
| `guard-no-env-files` | PASS |
| `guard-gate-f-containment` | PASS |
| `guard-no-workdo-changes` | PASS — `packages/workdo/` untouched |
| `guard-no-new-root-uploads` | PASS |
| `guard-composer-lock-integrity` | PASS — vendor matches the lock exactly |
| `scan-secrets` | PASS — no credential-shaped literal in 257 changed files |
| `platform-site-matrix` / `-a11y` / `-journeys` | PASS, §5 |

The two declared failures are `Auth\RegistrationTest::test_new_users_can_register` and
`ProfileTest::test_profile_page_is_displayed`, both long-standing `app/` defects with named root
causes in the manifest and neither owned by this work. Nothing was added to that file: as its own
README says, adding an entry to get green is the one use of it that is forbidden.

**One ratchet fired during M7 and that is the system working.** The known-outcome gate reported an
undeclared failure: `ResidualTenancyExposureTest`'s line-absolute raw-query census, because
`getRelatedSlugs()` moved when M5/M6 added lines above it. The census was **re-read, not
renumbered** — same method, same `DB::table()` call, no predicate added or removed — and the note
records both the move and what was checked.

## 8. Tests added in this stretch

All counts below were re-measured by running the suites named, not carried from a summary.

| | |
|---|---|
| M5 | **53** across 6 files (440 assertions) |
| M6 | **51** across 4 files (259 assertions) |
| `tests/Feature/Theme` | **188** (590 assertions) — M3 and M4 together; the directory does not separate them and no attempt is made here to attribute a share |
| M7 | 3 browser harnesses, 44 loads each |

**Every control was falsified by removing it**: **25** named falsifications across M5 and M6, each
re-run and each failing as predicted, listed individually in those two milestone documents. That is the project's standing rule — a green test that
cannot fail is not evidence — and it caught real problems repeatedly, most sharply when each of the
three reserved-slug guards had to fail **separately** to prove a one-writer fix would not pass.

## 9. Outstanding — owner items, not engineering gaps

| | Owner |
|---|---|
| `Security-sensitive diff: human checklist` | owner; **fails by design** until a person attests. It has not been marked satisfied and must not be |
| native Arabic review of the new strings and the 16 translated articles | owner |
| published contact details | owner content |
| approved legal text for the five documents | owner / legal |
| human accessibility review — keyboard order, focus management, label quality | owner |
| a production check for an existing `stores.slug = 'platform'` | owner / operations |
| the M4 reorder UI | deferred; waits on the storefront consuming saved ordering (Phase 04) |
| the vendored `ThemeCustomizer` module | no `verified`, no permission, no ownership, writes on `getCurrentStore()`; vendored, recorded |
| security incident P1-SEC-40 (#120) | tracked independently; UI work does not remediate it |
| merging Phase 03 to `main`, and release | owner |

## 10. What this report does not claim

- **Not production-ready.** Phase 01 acceptance criteria and the release gates in
  `phase-1/PHASE-01-CLOSURE-EXECUTION.md` are unchanged by this work.
- **Green CI is necessary evidence, not proof of release readiness** — the owner's own standing
  principle, repeated because this report is full of green numbers.
- **Zero axe violations is not an accessibility pass.** axe automates roughly a third of WCAG.
- **The vitals are lab numbers**, from `artisan serve` on localhost with no TLS, CDN, HTTP/2 or
  opcache, against SQLite rather than MySQL, with no INP at all.
- **No production deployment, migration, seed, key rotation or history rewrite was performed**, and
  none is implied.
