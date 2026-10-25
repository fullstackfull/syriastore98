# Phase 02 — handoff

**State: Phase 02 engineering is PARTIALLY COMPLETE.** Read §1 before anything else.

**Branch:** `claude/store-syria-p02-merchant-experience` in both repositories. Nothing merged to
`main`, nothing deployed, no production migration run.

| | |
|---|---|
| Laravel head | `d1bb94ee` (`fullstackfull/syriastore98`) |
| Flutter head | `2774a76d` (`fullstackfull/StoreSyria_app98`) |
| Requirements | [`PHASE-02-MASTER-EXECUTION-PROMPT.md`](PHASE-02-MASTER-EXECUTION-PROMPT.md) — the owner's prompt, verbatim |
| Row-level status | [`PHASE-02-TASK-LEDGER.md`](PHASE-02-TASK-LEDGER.md) |
| Executed output | [`PHASE-02-TEST-EVIDENCE.md`](PHASE-02-TEST-EVIDENCE.md) |
| Owner acceptance report (Arabic) | [`PHASE-02-ACCEPTANCE-REPORT-AR.md`](PHASE-02-ACCEPTANCE-REPORT-AR.md) |

---

## 1. The honest state, in one table

| Slice | Workstream | State |
|---|---|---|
| P2-00 discovery and reuse audit | — | **done** |
| P2-01 shared layout and core components | A, F | **done** — components, panel, action bar, dirty guard, sidebar zones, workspace chip, collapsed rail. Concurrent-edit conflict handling arrived with P2-03. |
| P2-02 merchant dashboard | C | **done except** the recent-orders / top-products blocks (P2-02-9) |
| P2-03 products | D | **partial** — concurrent-edit conflict detection, the quick-preview panel and the variant column's missing translation are shipped and tested. List, filters, full-page editor and media/variant UX are **not built**. |
| P2-04 orders | E | **partial** — the status breakdown is honest and reconciles, the **authorised quick preview** is shipped with its own guard entry and nine authorization tests, and **F19 is fixed**: the list no longer renders the Unix epoch as a date. Order list redesign, detail page, Kanban and saved views are **not built**. |
| P2-05 common merchant screens | J | **started** — **P2-05-1** is shipped: four state-changing GETs converted to POST, **three of which were also unauthorised cross-tenant writes** (no permission check, no ownership check, no tenant predicate, on models that carry no scope), plus a ratchet so the set of state-changing GETs cannot grow. The design-system pass over customers, stock, categories, staff and settings is **not built**. |
| P2-06 search, notifications, preferences, onboarding | G, H, I | **done** — all four. **Global search** (per-section authorisation, per-query scoping, 11 tests); **onboarding** (the RTL hold settled at runtime); **persisted table preferences** across all 68 DataTables from the one shared helper; and **notifications**, against the same `store_notifications` rows the Flutter app already reads, authorised BY TYPE with 17 tests. No `packages/workdo/` file touched and no schema change in any of the four. |
| P2-07 Flutter | K | **partial** — RTL wiring, the vendor-host leak, accessible names, the parity matrix, the RTL mirroring pass and now the **status honesty pass** (P2-07-6: statuses 7 and 8 named instead of "unknown", nine Arabic-literal descriptions translated, the "Cancel" button word replaced by the order STATE, and three badge colour pairs raised above AA — one was at 1.85:1) are done. Broader a11y (P2-07-8) is not, and the API half of the status honesty pass is F29, escalated. |
| P2-08 integration and acceptance | — | this document and the evidence file |

**Do not read the passing suites as "Phase 02 is finished".** They say that what *was* built is
verified, and verified more strongly than before — but three workstreams are untouched and two are
half-built.

## 2. What changed since the last handoff, and why it matters

The previous handoff was written under a blocker that no longer exists, and almost every claim in
it was carrying that caveat.

**`composer install` COMPLETES.** The owner opened `git.yoomoney.ru`; the install runs from the
existing lockfile with YooKassa intact and `composer.lock` untouched. (GitHub *archive* downloads
still 403 at the proxy, so Composer falls back to git clones — slow, ~20 minutes, but complete and
lock-faithful.) **Blocker B1 is cleared**, and with it the entire basis of the old document: the
Unit, Feature and Contract suites, the known-outcome gate, the DataTables stack, routing and
runtime tenancy had never run once. They all run now.

**Blocker B2 is cleared.** `flutter pub get --enforce-lockfile` succeeds on Flutter 3.47.8 with
`pubspec.lock` unchanged — it was an artefact of 3.41.2, exactly as B2 suspected.

**Running the real application found eight defects no static check could see.** Every one is
listed in §4. Two of them had shipped and were invisible because nobody had rendered the one
configuration that exposed them.

## 3. What blocks the rest, and what merely remains

### 3a. Genuine external blockers

| # | Blocker | What it stops | What would clear it |
|---|---|---|---|
| **B3** | Syncfusion charts licence — `syncfusion_flutter_charts` is used by one Flutter screen with no `registerLicense` call anywhere. | Release of the statistics screen. Not engineering. | An owner/commercial decision. |
| **B4** | **`flutter build web` fails**, and not on this app's code: `firebase_messaging_web` 3.5.18, a transitive pin, does not compile under Dart 3.13.7 (`dartify` against its own `MessagePayload`). **Zero files under `lib/` appear in the failure.** | Screenshots of Flutter screens. There is still no device and no emulator, and the web target was the remaining route to a rendering surface. | Raising that pin — a dependency change on the push-notification path, so an owner decision, not a test fixture. **RTL LAYOUT is no longer blocked by this**: widget tests lay out and paint through the real RenderObject tree, which is what "does it mirror" actually asks. |
| **B5** | **MySQL-only SQL in `HomeController::top_brand_category_chart`** (`SUBSTRING_INDEX`, `CURDATE`). | That one dashboard AJAX call answers 500 against SQLite, so it cannot be exercised in the test environment or the screenshot harness. **Not a product defect** — production is MySQL, and the dashboard page itself answers 200. | Nothing. Recorded so the 500 is not mistaken for a regression; the harness excludes that one url **by name**, so a 500 anywhere else still fails. |

**B1 and B2 are cleared and are kept here only so the change is visible.**

### 3b. Not blocked — simply not built

P2-03's list, filters, editor and media UX. P2-04's list redesign, detail page, Kanban and saved
views. **P2-05's design-system pass** over customers, stock, categories, staff and settings — the
largest untouched surface in the phase, now that P2-05-1 has dealt with what was dangerous there.
P2-07-8's broader accessibility work. None of these has a blocker; they have not been done.

P2-03's filters and P2-04's saved views want the **same** mechanism, and **half of it now exists**:
P2-06-4 put `stateSave` on every table through `buildDataTable()`, with
`StoreSyriaDS.sanitizeTableState` deciding what is a preference and what is not. A saved VIEW is
that plus a named, server-stored set — which is where the schema question starts, and it is an
owner decision, not an extension of this one. Build the naming once, for both lists, rather than
twice.

## 4. Defects found by running the real application

Every one was invisible to the preview harness, and that is the point: a renderer with no routing,
auth, tenancy or DataTables cannot answer these questions. Rows 12–15 were found by pointing the
existing live tools at a NEW screen before claiming it was finished, which is the cheapest of all
the lessons here. Row 17 is the most serious defect this phase found, and it was found while
fixing something else: a verb.

| # | Defect | How it was found |
|---|---|---|
| 1 | **Arabic + dark mode loaded NO stylesheet.** `asset('public/assets/css/rtl-style-dark.css')` resolves to `/public/...` and 404s; the docroot already is `public/`. An Arabic merchant with dark mode on got the admin with no layout CSS at all. Three sibling branches used the correct form. | A screenshot of the one combination nobody had rendered |
| 2 | **`isAbleTo('Manage Product')` — singular — exists NOWHERE.** Not seeded, not in the live table. Laratrust answers false for a name nothing holds, so the out-of-stock link, the top-products list and the product onboarding step were hidden from **every** principal including the tenant owner, silently. | Driving `/dashboard` as a real merchant |
| 3 | **`COD` was translated as "سبب الوفاة"** — *cause of death*, a machine translation of the medical abbreviation — on every Arabic order row. `Payment` was missing from `ar.json` entirely. | Reading an Arabic screenshot |
| 4 | **The side panel never loaded.** `data-ss-panel-url` was documented on the component from the start and never implemented, and the behaviour file was in an opt-in partial the order list does not include. | Eight screenshot combinations all reporting an empty panel body |
| 5 | **Tax and Delivery rows read `0,00`** on orders that had neither — `!empty()` on a `SetNumber()` result is true for `"0,00"`. | A screenshot of the preview panel |
| 6 | **A dropped sidebar row left a dangling unclosed `<li>`**, and a parent whose children were all dropped kept its expand arrow over an empty submenu. | My own new tests, then the independent review |
| 7 | **`store/change-status` is a state-changing GET** writing `stores.is_active` from query input — an `<img src>` on any page a signed-in merchant visits takes their storefront offline. The sweep that found the same defect on `change-store` had missed it. | The independent review of that fix |
| 8 | **My own "dark mode" screenshots were not dark.** The harness set a client attribute; the layout picks its stylesheet server-side. Four of eight shots were of the light stylesheet. | Asserting which stylesheet the server actually sent |
| 9 | **The concurrent-edit refusal produced a BLANK error toast.** It returned `redirect()->back()->withInput(...)`, but `product/edit.blade.php` submits that route with `$.ajax` in both branches and reads `data.flag`/`data.msg`. jQuery follows the redirect, `flag` comes back undefined, and `show_toastr('Error', undefined)` tells the merchant nothing — by the mechanism built to tell them. `withInput()` was inert too, since nothing reloads. The tests passed because they asserted the **session**, which a 302 populates. | The independent review, reading the view that posts the route |
| 10 | **`?q[]=a` was a server error on the global search.** `trim((string) $request->query('q'))` on an array raises "Array to string conversion", which Laravel's handler turns into an exception — from a query string, on a route every authenticated merchant can reach. | The independent review |
| 11 | **The product list's variant column read ENGLISH in Arabic.** `ProductDataTable::dataTable()` returned the bare literals `'has variant'`/`'no variant'` with no `__()`. Caught in the same pass as `"Stock": "الأسهم"` — Arabic for financial *shares*, not inventory. | Reading an Arabic screenshot of the product list |
| 12 | **The order list rendered `Jan 1, 1970`** as a delivery date. `Utility::dateFormat()` is `date($fmt, strtotime($date))`, `strtotime(null)` is FALSE, and `date($fmt, false)` is the epoch — a fabricated date in the same type as the real ones beside it. | A live screenshot of the order list |
| 13 | **The notification bell's unread badge measured 1.91:1 in dark mode.** The rule used `--ss-danger-fg` as a *background*; it is a foreground token, pale red in dark, and white on it is unreadable. | Extending the live contrast tool to the new screen BEFORE calling it done |
| 14 | **Every line of text in the notification feed measured 3.42–3.85:1 in dark mode.** `style-dark.css` forces `p { color: #808191 !important }` on a selector list shared with the sidebar, so a `<p>` anywhere in a new component is grey whatever the component says — the same rule F8 fought, met again on a new surface. | The same contrast run |
| 17 | **Three merchant toggles were unauthorised cross-tenant writes.** `ProductBrandController::changeStatus`/`changePopular` and `ProductLabelController::changeStatus` did `find($request->id)` then wrote, with no permission check, no ownership check and no tenant predicate — on models that are in no `scoped_models` list. Any authenticated web caller could disable or promote any brand in any store. **The Flutter siblings had been fixed long before**; a sweep closed the API path and left the web path open. | Censusing GET routes by their HANDLER's source rather than by uri words, while fixing a verb |
| 18 | **The dev fixture was missing four permissions its own screens gate on**, so the first browser run of P2-05-1 reported six failures that were ALL fixture gaps — a 403 and a redirect that looked exactly like a lockout the tightening had introduced. Reading them as product defects would have produced a wrong fix. | Running the browser check and then checking which seeded role actually holds the permission |
| 16 | **The Flutter app said "unknown" about statuses 7 and 8**, had nine hardcoded Arabic descriptions with no `.tr` in a bilingual app, asked for the "Cancel" BUTTON word as an order's state, and carried **three badge colour pairs below AA — one at 1.85:1**. Hardcoded pairs inside the widget, so no theme check was ever going to reach them. | Writing a test that enumerated all NINE values the column holds, rather than the seven the switch handled |
| 15 | **A `<p>` in a new component is grey in dark mode, every time.** Not a new defect so much as the lesson from 13 and 14: `public/css/store-syria-ds.css` already carries a documented "host override defence" block for exactly this, and the cost of not knowing it was there is two contrast failures per new text class. **Add new text classes to that block, and measure.** | — |

## 5. What a successor should do first

1. **Read [`PHASE-02-MASTER-EXECUTION-PROMPT.md`](PHASE-02-MASTER-EXECUTION-PROMPT.md)**, not a
   summary of it. Its coverage check names four workstream requirements that have no ledger row.
2. **Bring the environment up before changing anything**, using §6. Everything in this phase that
   is worth trusting was measured there.
3. **Do not re-litigate the settled holds.** P2-06-1's RTL duplication was the preview harness,
   measured eight ways in the real application. B2 is cleared. F8 and F9 are resolved.
4. **Start with P2-03's list and editor**, which is the largest untouched surface and the one
   whose absence is most visible to a merchant. After that, **all of P2-05** is still at zero.
5. **F20 is the one sweep worth proposing to the owner.** `Utility::dateFormat(null)` renders the
   Unix epoch at 64 call sites, 28 of them vendored, and F22 says the same helper also prints
   English month names inside the Arabic admin. One line inside it fixes both — and changes output
   on screens across seventy modules, which is why it is a proposal and not a commit.

## 6. Bringing the environment up

```bash
# Laravel
composer install --no-interaction --prefer-dist --no-scripts   # ~20 min: git-clone fallback
php artisan key:generate --force                               # if .env is fresh

# The dev instance the screenshot harnesses drive (invented data only)
rm -f database/p2dev.sqlite && touch database/p2dev.sqlite
DB_CONNECTION=sqlite DB_DATABASE=$PWD/database/p2dev.sqlite php artisan migrate --force
DB_CONNECTION=sqlite DB_DATABASE=$PWD/database/p2dev.sqlite CACHE_STORE=file \
    php artisan tinker --execute="require base_path('database/seed-p2dev.php');"
DB_CONNECTION=sqlite DB_DATABASE=$PWD/database/p2dev.sqlite CACHE_STORE=file \
    SESSION_DRIVER=file php artisan serve --host=127.0.0.1 --port=8899 &

# The preview fixtures BOTH browser suites need (41 files; neither docblock lists them all)
bash docs/development/phase-2/tools/render-all-fixtures.sh /tmp/p2fixtures
SS_PREVIEW_DIR=/tmp/p2fixtures php -S 127.0.0.1:8099 -t public \
    docs/design-system/tools/preview-server.php &

# Flutter
export PATH="/opt/flutter/bin:$PATH"     # or install 3.47.x stable
flutter pub get --enforce-lockfile       # succeeds; do NOT commit a lock change
git checkout analysis_options.yaml       # pub get rewrites it and narrows `analyze`
```

**`SESSION_DRIVER=file` matters**: the default database driver needs a `sessions` table the
migrations do not create, and every page 500s without it.

## 7. Standing rules this phase did not touch

- **Issue #120 / P1-SEC-40 is NOT resolved.** The exposed production database dump is a separate
  critical incident. It is live on `main` (commit `3935dee8`, `storesyria_ecommercego.sql`) and
  deliberately **absent from this branch** — which is why this branch must never be brought up to
  date by merging `main` without removing it first. No Phase 02 work touches the incident.
- **Phase 01's release gates remain open and separately tracked** in
  `docs/development/phase-1/PHASE-01-CLOSURE-EXECUTION.md`.
- Green CI is necessary evidence, not proof of production readiness. The Feature suite's accepted
  failures and skips are declared in `.github/known-test-outcomes.json`; the gate asserts that set
  in both directions.
