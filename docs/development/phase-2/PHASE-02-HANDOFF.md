# Phase 02 — handoff

**State: Phase 02 engineering is PARTIALLY COMPLETE.** Read §1 before anything else.

**Branch:** `claude/store-syria-p02-merchant-experience` in both repositories. Nothing merged to
`main`, nothing deployed, no production migration run.

| | |
|---|---|
| Laravel head | `e53d9fc8` (`fullstackfull/syriastore98`) |
| Flutter head | `fd76ac8` (`fullstackfull/StoreSyria_app98`) |
| Requirements | [`PHASE-02-MASTER-EXECUTION-PROMPT.md`](PHASE-02-MASTER-EXECUTION-PROMPT.md) — the owner's prompt, verbatim |
| Requirement coverage | [`PHASE-02-COVERAGE-AUDIT-2026-10-25.md`](PHASE-02-COVERAGE-AUDIT-2026-10-25.md) — which of the prompt's 104 workstream requirements have a ledger row, and which do not |
| Row-level status | [`PHASE-02-TASK-LEDGER.md`](PHASE-02-TASK-LEDGER.md) |
| Executed output | [`PHASE-02-TEST-EVIDENCE.md`](PHASE-02-TEST-EVIDENCE.md) |
| Owner acceptance report (Arabic) | [`PHASE-02-ACCEPTANCE-REPORT-AR.md`](PHASE-02-ACCEPTANCE-REPORT-AR.md) |

---

## 1. The honest state, in one table

| Slice | Workstream | State |
|---|---|---|
| P2-00 discovery and reuse audit | — | **done** |
| P2-01 shared layout and core components | A, F | **done** — components, panel, action bar, dirty guard, sidebar zones, workspace chip, collapsed rail. Concurrent-edit conflict handling arrived with P2-03. |
| P2-02 merchant dashboard | C | **done.** P2-02-9's two list cards were the last row, and they were already BUILT — what was missing was any data in them: the fixture left `orders.product_id` at `'0'` while the controller plucks that column, so the best-selling card had only ever been seen in its empty state. Fixed, pinned (4 tests / 16 assertions, bitten three ways) and shot with rows in it in 8 configurations |
| P2-03 products | D | **partial** — concurrent-edit conflict detection, the quick-preview panel, the variant column's missing translation and **the list filters** (status / stock / category / variants, with no JavaScript, 12 tests and 58 browser cases) are shipped and tested. Its **row actions** came with P2-04-4's sweep: the design system's quiet icon buttons, an `aria-label` on each (three had no accessible name at runtime), a `Form::close()` that was missing, an icon that had never painted a glyph, and the stock badges off white-on-brand (2.59:1) onto `ss-badge`. **P2-03-1 is now PARTIAL, not untouched**: the list's COLUMN SET and the reachability of its row actions are shipped, its DENSITY is not. The measurement reframed the slice — the table is a fixed 1250px (en) / 1168px (ar) at 1440, 1280, 992, 768 and 390 alike, the document never overflows, and with the pinned class removed a real click on Edit at the starting scroll position **navigated nowhere in all eight configurations, 1440 included**. So this was never a phone problem. The actions column is now sticky at the inline end and the already-bundled-but-unused `colvis` extension is wired into the shared helper, reaching all 68 tables with no `packages/workdo/` edit and persisting through the existing `stateSave`. 52 live cases, bitten five ways; the check itself was corrected twice by its own bites. Row density (55px everywhere, no control) is split out as **P2-03-10**. **P2-03-10 (row density) and P2-03-8 (basic/advanced field organisation) are now DONE.** The full-page editor is **PARTIAL**: its save affordances (validation summary, sticky save, unsaved-change guard) and its field organisation are in on both create and edit, and an end-to-end **UPDATE** is proven through the real UI — which required fixing **F16** (two libraries 404ing on every admin page, the real cause of the "environmental" blocker the last handoff recorded), **F59**/**F61** (three NULL-into-NOT-NULL writes in `update()`) and three fixture gaps. **CREATE is blocked by F58 and escalated** (§3a-bis). Media/variant UX, contextual help and the status/stock indicators are **not built**. |
| P2-04 orders | E | **partial** — the status breakdown is honest and reconciles, the **authorised quick preview** is shipped with its own guard entry and nine authorization tests, **F19 is fixed** (no more Unix epoch as a date), and **the list filters are shipped** (status, payment, date range; 14 tests, five controls bite-tested, 112 browser cases shared with the product list). **P2-04-4 is now done**: the status column off the vendored theme's classes (below AA in **44 of 56** readings, two of them unreadable at 1.03:1 and 1.12:1) onto one shared `OrderStatusTone` the dashboard reads too — the two surfaces had disagreed about four of the nine statuses — plus named, contrast-clean row actions and the reference pill fixed through F9's own `--ss-onbrand`. 84 live cases, bitten five ways. The independent review of that change then found a **stored XSS** on the line it edits (F42, fixed and pinned) and a preview harness that had been showing the opposite of production (F43). **P2-04-6 is PARTIAL**: its blocker was stale and is cleared, three blocks on that page had never held content (fixed in the fixture first), and the populated page then yielded four correctness defects — including **another tenant's downloadable file being linked** (F51, measured) — and six contrast failures, the worst being every address label and value at 1.10:1 in dark mode. 4 Feature tests and 112 live cases, all bitten. The design-system restyle of that page's shell is **not** done. Kanban and SAVED views are **not built**. |
| P2-05 common merchant screens | J | **started** — **P2-05-1** is shipped: four state-changing GETs converted to POST, **three of which were also unauthorised cross-tenant writes** (no permission check, no ownership check, no tenant predicate, on models that carry no scope), plus a ratchet so the set of state-changing GETs cannot grow. The design-system pass over customers, stock, categories, staff and settings is **not built**. |
| P2-06 search, notifications, preferences, onboarding | G, H, I | **done** — all four. **Global search** (per-section authorisation, per-query scoping, 11 tests); **onboarding** (the RTL hold settled at runtime); **persisted table preferences** across all 68 DataTables from the one shared helper; and **notifications**, against the same `store_notifications` rows the Flutter app already reads, authorised BY TYPE with 24 tests, two live defects and four weak tests of my own found by the independent review and fixed (F23–F28). No `packages/workdo/` file touched and no schema change in any of the four. |
| P2-07 Flutter | K | **partial** — RTL wiring, the vendor-host leak, accessible names, the parity matrix, the RTL mirroring pass and now the **status honesty pass** (P2-07-6: statuses 7 and 8 named instead of "unknown", nine Arabic-literal descriptions translated, the "Cancel" button word replaced by the order STATE, and three badge colour pairs raised above AA — one was at 1.85:1) are done. Broader a11y (P2-07-8) is not, and the API half of the status honesty pass is F29, escalated. |
| P2-08 integration and acceptance | — | this document and the evidence file |

**Do not read the passing suites as "Phase 02 is finished".** They say that what *was* built is
verified, and verified more strongly than before. What is NOT built is named in §3b and in the
ledger's own TODO rows — at the time of writing: P2-03's list DENSITY (P2-03-10), full-page editor and
media/variant UX; P2-04's Kanban and saved views; P2-05's design-system pass over five screens;
P2-07-8's broader Flutter accessibility; and P2-04-6, which is now unblocked but unstarted.
Read those rows rather than a count here, because a count in prose goes stale and these rows do
not.

## 2. What changed since the last handoff, and why it matters

### `[2026-10-26]` Four approved blockers closed, and a census that found ten copies of one

The owner approved F58, F46, F70 and F29 in writing. All four are fixed; the two that keep a
stated limit are in §3a-bis. Two things from this round are worth carrying forward:

**A fix keyed on the one file somebody read is not a fix.** F46 was recorded against
`PaymentService`. The same checkout block exists **byte for byte in nine files** — nine payment
gateways plus the service — so fixing the recorded one would have left the control bypassable by
choosing a different payment method, which is exactly what F50 records happening before. It is now
ratcheted rather than fixed a tenth time, because three rounds of review in this phase have each
found the previous round's siblings. Census before you fix, and enumerate your exemptions by name.

**A test that cannot fail is worse than no test, and both of mine looked fine.** The F46 driven
test passed with the fix reverted, because it drove a different handler; its replacement expected
`payment-sheet` to reach the right one, and that endpoint writes no order. Both were caught by a
bite and by an explicit premise assertion. Separately, two of the P2-03-8 tests from the previous
commit could not fail at all — see F69. The pattern across all four: **the assertion was satisfied
by something adjacent to what it meant to measure.**

Also now true and worth knowing before you touch the editor: **product CREATE works** (it was
impossible on any migration-built database until F58), **a validation error opens the collapsed
section it belongs to** (F70), and the dev fixture finally has attributes, variants and gallery
images — without them the media and variant halves of the editor cannot be driven at all.

### `[2026-10-26]` The product editor's "environmental" blocker was a 404, and the editor now saves

This is the most useful thing to read if you are picking the phase up. The previous handoff left
**P2-03-3's end-to-end save unverified and blamed the environment** — `Dropzone is not defined` and
`$(...).select2 is not a function`, present on the committed page too, so it looked like a missing
deployment asset.

**It was F16.** `partision/footerlink.blade.php` is *included by the admin layout*, and three of its
scripts were addressed `asset('public/...')`, which doubles the docroot segment and 404s. So two
libraries were missing on **every admin page**, and because `#submit-all`'s handler reaches for
`Dropzone` in its first statements, **the product editor could not save at all**. Corrected; the
editor now loads with zero page errors and zero failed asset requests, and an end-to-end UPDATE is
proven through the sticky bar.

Two lessons worth carrying:

- **"Environmental" was a hypothesis, not a finding.** It had been recorded as a conclusion. One
  measurement of the asset URLs would have settled it at any point.
- **Asking what a fix makes newly *reachable* paid for itself.** The independent review of that
  one-token change produced five more defects (F61–F65), the worst being **F62**: with select2
  loading, the layout's global `tags: true` makes the product tag field free-text, and two of the
  four `Tag` creation sites never set the NOT NULL `tags.slug`.

**CREATE is still blocked by F58** — `products.cost_price` is a GATE F column with no migration, so
`store()` cannot work on any migration-built database while production is fine. Applying the parked
draft is a persistent migration and is **escalated, not done**. See §3a-bis.

**P2-03-8 is done**: the editor's thirteen-control main card now puts six catalogue-level fields
behind a native `<details>`, with every field's name, value, validation and submit path asserted
unchanged (502/502) and the closed section's contents found in the real multipart body.


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

### 3a-bis. The escalations, and what the owner decided

**[2026-10-26] THE OWNER APPROVED FOUR BLOCKERS IN WRITING** — F58, F46, F70 and F29 — scoped to
engineering, fixing and testing inside isolated development and test environments, with existing
data preserved. Explicitly NOT approved: production deployment, running a migration against the
production database, merging to `main`, or rewriting Git history. All four are now fixed, with
their evidence in `PHASE-02-TEST-EVIDENCE.md` 15-17 and their limits stated in the ledger. The
two subsections below are kept because their reasoning is the record of why they were escalated
rather than done quietly, and because each carries a limit that still stands:

- **F58 is fixed but NOT CLOSED ON MySQL.** The migration's behaviour was proven on SQLite (fresh
  add, idempotent re-run, production-shaped no-op, harmless rollback) and by driving a product
  create end to end. `tests/Support/Security/bin/verify-cost-price-migration-on-mysql.php` is
  written and registered, and this container has no MySQL server, so it has **not been run**.
  SQLite reports any decimal as `numeric` and stops enforcing scale and NOT NULL.
- **F46 is fixed in ten places, and its reachability was not demonstrated.** The finding named one
  file; a census found the same block byte for byte in nine. All ten authenticated branches now
  take the id from the session. But whether the nine web/gateway copies were reachable with a
  hostile `customer_id` at all was NOT established — `ModuleStorefrontInputGuard` is per-route and
  whether it covers the gateway callbacks was not determined. Defence in depth of undemonstrated
  reachability, not a proven exploit closure.


**F58 — a product cannot be CREATED on any database built from the migrations.**
`ProductController::store()` validates `cost_price` as **required** and writes
`$Product->cost_price` unconditionally, but `products.cost_price` is a **GATE F** column: it exists
in production (`decimal(15,2) NOT NULL DEFAULT 0.00`, populated on all 45 products) and its
migration is parked at
`database/migrations/_gate_f_pending/0004_draft_add_cost_price_to_products_table.php.draft`.
Driven, not reasoned: a full create through the real UI with every required field and both images
answered `table products has no column named cost_price`, handed to the merchant as a raw SQL toast
by `store()`'s own try/catch.

**The suite is structurally blind to it.** No test creates a product through `store()` — every one
uses `Product::forceCreate([...])` without `cost_price` — and `GateFReconciliation.php:270` even
guards with `if (! $this->hasColumn('products','cost_price'))`. So a fresh install, CI and the dev
fixture all share a product editor whose Create cannot work, while production is unaffected.

**Why it is escalated:** the fix is to apply the parked draft, which is a **persistent migration**.
CLAUDE.md §2 and the owner's standing instruction of 2026-10-26 both put that behind explicit
approval. Nothing else in P2-03 needs it — UPDATE is proven end to end — so it blocks exactly one
acceptance criterion and is recorded with its evidence rather than worked around.


**F46 — the checkout path attributes an order from request input.**
`PaymentService.php:2689` builds `product_order_id` as `$request->customer_id . date('YmdHis')`
for a caller `auth('customers')->check()` has already authenticated, and `:2696` writes the same
raw value to `orders.customer_id`. The authenticated customer's own id is on the line above. The
usual objection — that the `unsignedBigInteger` insert would reject a string — does not hold:
`config/database.php:55` sets `'strict' => false` on the `mysql` connection, so MySQL coerces
that column to 0 and the `string` column keeps the value whole, which is how the order list's
reference cell became an injection vector.

**The rendering half is fixed and pinned** (`OrderListCellEscapingTest`, bites). **The input half
is not, deliberately:** it is a payment path, and material changes to payments are an owner
decision under this phase's constraints. The fix is one line on each of those two statements, and
it wants its own slice with checkout tests rather than a drive-by inside a contrast fix. What was
NOT measured: whether a crafted `customer_id` survives a full checkout request on production
MySQL. That was reasoned from the column type, the handler and the strict-mode setting — not
driven.

### 3b. Not blocked — simply not built

P2-03's list layout, editor and media UX (its filters are done, and its ROW ACTIONS came with
P2-04-4's sweep). P2-04's Kanban and saved views, plus the design-system restyle of the order
detail page's shell — **the list redesign is no longer on this list** (P2-04-4 is done), and
P2-04-6's "blocker" turned out to be stale (see below); its correctness, accessibility and
dark-mode legibility work is done, its restyle is not. **P2-05's design-system pass** over customers, stock, categories, staff and settings —
the largest untouched surface in the phase, now that P2-05-1 has dealt with what was dangerous
there. P2-07-8's broader accessibility work. None of these has a blocker; they have not been done.

**P2-04-6's blocker was stale, and the row is the only thing that was holding it.** It said
`order.view` reaches `Order::order_detail()` = `Order::find()` with no tenant predicate, on a
forgeable `Crypt`-encoded id, and that fixing it "needs runtime tests, which the Composer blocker
prevents". Both halves are overtaken: `OrderDocumentAccessGuard` is on the `web` group and lists
`order-view/{id}` in its `TARGETS`, and `OrderDocumentAccessTest` passes 49 tests / 77 assertions
with `'order detail (order.view)' => ['/order-view/']` among the cross-tenant data sets. The
Composer blocker is cleared, so those tests run. **Re-measured, not re-read.** Keep one nuance:
the guard keys on `$route->uri()` by design, so a NEW route reaching `order_detail()` is not
guarded by implication and needs its own `TARGETS` entry plus a case in that test — restyling
`order.view` in place needs neither.

**Saved views are now the only part of the filter story left, and most of the pieces exist.**
P2-06-4 put `stateSave` on every table (page length, sort, column visibility, per device).
P2-03-2 put real filters on the product list, carried in the url — which means a saved view is
already shareable and bookmarkable today by copying the address bar. What remains is NAMING a
set of filters and storing it, and that is where the schema question starts: an owner decision,
not an extension of either. **When it is built, build it once for both lists** — the orders list
needs the same bar, and `ProductDataTable::applyFilters()` is the shape to copy (store predicate
first, filters only narrow, unrecognised values ignored).

## 4. Defects found by running the real application

Every one was invisible to the preview harness, and that is the point: a renderer with no routing,
auth, tenancy or DataTables cannot answer these questions. Rows 12–15 were found by pointing the
existing live tools at a NEW screen before claiming it was finished, which is the cheapest of all
the lessons here. Row 17 is the most serious defect this phase found, and it was found while
fixing something else: a verb. Row 19 was found by opening a PNG and looking at it, which no
amount of green suite output would have done.

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
| 20 | **A field hint broke a filter row's alignment, twice.** `<x-ss.help>` inside a filter field rendered a bordered tinted callout two controls tall, which pushed that field onto its own line; swapping it for a quiet `.ss-field__hint` fixed the weight but not the geometry, because the bar aligns by `flex-end` and a field with an extra line puts its select higher than its neighbours'. It now sits on its own full-width line with `aria-describedby` keeping it attached to the control. **Neither version failed a single test.** | Opening the screenshots the browser check had just written |
| 19 | **The notification feed showed the OLDEST item at the top**, and its timestamp measured 4.13:1 on an unread row in light mode. Neither was reachable by any test that existed: the ordering only misbehaves when ids and timestamps disagree, and the contrast probe sampled whichever row came first — which happened to be the one on the lighter surface. | **Opening a screenshot and reading it.** Both are now pinned: the ordering test's fixture makes id order and time order disagree on purpose, and the probe measures the timestamp on both surfaces. |
| 18 | **The dev fixture was missing four permissions its own screens gate on**, so the first browser run of P2-05-1 reported six failures that were ALL fixture gaps — a 403 and a redirect that looked exactly like a lockout the tightening had introduced. Reading them as product defects would have produced a wrong fix. | Running the browser check and then checking which seeded role actually holds the permission |
| 16 | **The Flutter app said "unknown" about statuses 7 and 8**, had nine hardcoded Arabic descriptions with no `.tr` in a bilingual app, asked for the "Cancel" BUTTON word as an order's state, and carried **three badge colour pairs below AA — one at 1.85:1**. Hardcoded pairs inside the widget, so no theme check was ever going to reach them. | Writing a test that enumerated all NINE values the column holds, rather than the seven the switch handled |
| 15 | **A `<p>` in a new component is grey in dark mode, every time.** Not a new defect so much as the lesson from 13 and 14: `public/css/store-syria-ds.css` already carries a documented "host override defence" block for exactly this, and the cost of not knowing it was there is two contrast failures per new text class. **Add new text classes to that block, and measure.** | — |

## 4b. Six bite tests came back clean, and every time the TEST was wrong

This project's rule is stricter than TDD's: after the fix, revert it and confirm the test fails
again. Six reverts in this cycle produced **zero failures**. Writing that down matters more than
the fixes, because the tempting reading was wrong every single time.

| control reverted | what it looked like | what it was |
|---|---|---|
| `getSideMenu()`'s gate call site | the gate is redundant | every assertion drove the helpers directly; nothing drove the function |
| the iframe ordering fix | the ordering does not matter | `sidemenubuilder.iframe` does not resolve in a test run, so the row was dropped for the wrong reason |
| the search's three store predicates | all three are redundant | **two of them bite**; only `customers` does not, because `Customer` IS scoped — the written note had generalised from one measurement to three |
| `sanitizeTableState`, against the live browser check | the sanitiser does nothing | the check cleared the search and returned to page one BEFORE reloading, so the saved state held nothing to drop |
| the notification type filter and operator limb | both redundant | no case drove an operator at all, and the type filter was shadowed by the empty-types short-circuit |
| `customerStatus`'s ownership block | the predicate is redundant | `Customer`'s global scope covers the resolved-tenant case; only a principal who resolves NO tenant measures the handler's own limb |

**The pattern.** A clean bite test says one of two things: the control is redundant, or the test is
not measuring it. **It was the second one six times out of six.** A seventh case later added a
THIRD answer: there was nothing to measure, because the write-up had claimed a consequence the
change did not have (F40 — see §9n.5 of the evidence file). A clean bite always has to be
explained; it just does not always mean the test is weak. The way to tell them apart is to
ask what principal or state the control exists for and then write a case that puts the system in
it — a tenant-less principal, an operator holding no role, an unregistered type, a saved state
that still contains what should be dropped. Five of the six got such a case; the sixth got a
corrected sentence, because the claim rather than the code was wrong.

**And a bite test's written conclusion is not evidence.** One of these rows had been recorded as
"no endpoint test can fail on a single removal", which was false and sat in a test file and two
documents for a cycle. Re-running it refuted it. Reverting a control is evidence; what somebody
writes about the revert is a claim until it is re-run.

## 4c. The same census mistake, four times

| round | found | why the previous round missed it |
|---|---|---|
| F12 | `change-store` is a state-changing GET | — |
| F14 | `store/change-status`, one screen up the same controller | the F12 fix was keyed on the one route somebody was looking at |
| P2-05-1 | `customer-status`, and then `product-brand-status`/`-popular`, `product-label-status` | the F14 sweep was keyed on the two routes then known |
| P2-05-1 again | those three also had **no permission check and no tenant predicate** | and their **Flutter siblings had been fixed long before** — a sweep closed the API path and left the web path open |

**Key a census on the handler, never on the surface or the spelling.** The sweep that finally
worked walked the real router, kept host controllers, and read each handler method's own
comment-stripped source. It is now a ratchet (`StateChangingGetInventoryTest`) that fails in both
directions, and it declares its own limit: a write reached through a helper, a job or an event
listener is invisible to a source heuristic.

## 4d. A card can be built, screenshotted and signed off while it has never held a row

P2-02-9's two dashboard cards were finished code. `handleRegularUser()` passed both datasets, the
view rendered both, both were gated, both had empty states, both appear in the Phase 02
screenshots — and the **best-selling card had never once been seen with data in it**. The
development fixture left `orders.product_id` at its default `'0'` while `HomeController` plucks
that column, not `product_json`, so `topSellingProductIds` was `[0]`, nothing matched, and the
card rendered `No sales yet`. An empty state is indistinguishable from a healthy card that has
nothing to show, which is exactly what it is for, so nothing looked wrong at any point: not in the
screenshots, not in the browser checks, not in the ledger, where the row still read TODO.

**Two things it costs nothing to do, and this cycle paid for skipping both.**

- **Drive every block with a row in it before calling the screen done.** The empty state is the
  cheap half to verify and the half that proves nothing. Read the card, not the table count — a
  present-and-empty card and a present-and-populated card are both "the block renders".
- **Check which column the feature actually reads.** `product_json` is the field that looks right
  and `product_id` is the one the controller counts. A fixture filling the plausible one produces
  a screen that is wrong in a way no assertion in the repository mentioned: no test named
  `topSellingProducts` at all.

**And the first run of the test that pinned it passed for the wrong reason in a new way.** It
granted `Manage Product`, singular — a name that exists in no seeder, so `grantPermission()`
created a fresh row nothing reads, `$ssMayProducts` stayed false, the card never rendered, and
every "this product is absent" assertion held for free. The premise assertion (`the table was
found at all`) is what failed and said so. That is the same trap P2-02-13 fixed in the view
itself; it is worth knowing that a **fixture** can fall into it too, and that only a premise
assertion catches it.

## 4e. A screenshot is the only thing that found any of these

P2-04-4 began with one crop of one table row at 3x. It produced, in order: a status control with
no readable text (1.03:1), a button with no glyph in it at all, three controls with no accessible
name, and an unclosed `<form>`. **Not one of them was visible to any test in the repository**, and
three of them could not have been — the missing glyph is a font fact, the missing name only
appears after Bootstrap's tooltip plugin empties `title`, and the contrast is a composited pixel
value. The run that eventually proved the fixes (`check-row-actions-live.cjs`, 72 cases) exists
because of the crop, not the other way round.

**The cheap habit, worth repeating:** render the screen, crop one row or one card at 2–3x, and
count what you expect to see. Four actions, four glyphs. Two cards, two footers on the bottom
edge. Five products, five rows. Counting in the DOM answers a different question and has now
agreed with a broken page three times this phase — the empty best-selling card (§4d), the status
button with no text, and the preview button with no icon.

**And two near misses in the measuring itself, both caught before they were written down.** A
contrast probe that treated `rgba(…, 0.15)` as opaque returned `1.00:1` for a warning label and
`0 problems` for a card it had read twice; and a browser run reported `159/159` against fixtures
rendered before the change it was supposed to be checking, because the render script had exited
255 and the stale HTML was still on disk. A green number is a claim about what was actually
driven. Check that the thing under test is the thing the harness loaded.

## 4f. The independent review found the serious thing, and it was next to the change, not in it

The P2-04-4 slice was presentational: badges, button classes, aria-labels. The
`tenant-authz-reviewer` pass over it confirmed the change itself was clean — no predicate, gate,
route or middleware moved — and then reported a **stored XSS on the line the change edits**. The
status column was escaped correctly; the `customer_id` column three cases above it, in the same
`rawColumns()` list, printed a shopper-chosen name straight into the merchant's admin origin
(ledger F42). It also found that the **preview harness had been granting a permission production
does not have** since P2-02-13, so every dashboard screenshot showed the opposite of the
application while 159/159 checks passed (F43).

**The habit worth keeping:** run the review on the slice even when the slice is "just styling",
and brief it on the surrounding file rather than only the diff. Two of the last three slices got
a real finding from it, and both findings were in code the diff touched but did not change.

**And weigh what it returns rather than applying it.** Of its five suggested actions, three were
right and are fixed (F42, F43, F44), one was a real defect it had framed as an owner question and
is also fixed, and **one was wrong**: it reported that `$(this)` inside the `.return_request`
success callback is the ajax settings object, making a comment of mine inaccurate. It is not —
`order/index.blade.php:80` sets `context: this`, which is exactly what makes `$(this)` the
clicked element in every callback. The comment was correct and stayed. A reviewer's finding is a
claim to check, on the same terms as a bot's.

## 4g. A test can be poisoned by the request before it

`OrderListCellEscapingTest` needs the order list's AJAX leg, because yajra's `render()` branches
on `request()->ajax() && request()->wantsJson()` and only that leg builds any rows. The first
version of its helper did the obvious thing first — `$this->get('/order')->assertOk()` as a
liveness check — and then asked for the JSON. **It got the page back both times.**

`Yajra\DataTables\Utilities\Request` captures `app('request')` when the container first resolves
it and keeps it for the rest of the test method. So the liveness request fixed `ajax()` at FALSE
for everything after it, and all three cases then asserted against a 1,300-line HTML shell with
no customer cell in it — a state in which "the payload is absent" is perfectly true and means
nothing. Driving the same call as the *first* request in the method returns JSON and the test
bites.

**Two headers, not one:** `getJson()` supplies `Accept` and nothing supplies `X-Requested-With`,
so `wantsJson()` was true and `ajax()` false — which is also why the response looked like a
plausible 200 rather than an error. The premise assertion (`the body starts with {`) is what
caught both, and it is now the helper's own liveness check, so there is nothing left to put
before it.

## 4h. A bite test that passes may not have reverted anything

The reference-cell escaping fix was bitten with a one-line `sed`, the test passed, and that read
as the familiar result: a clean bite means the test is not measuring the control. It was not that
at all. **The `sed` pattern had not matched** — PHP string quoting inside the shell command — so
the file was unchanged and the test was correctly reporting a fix that was still there. Running
`grep` on the file first showed `e($item->product_order_id)` untouched; redoing the revert with
an exact-match replacement produced the failure.

**Confirm the revert landed before reading the result.** `git diff` or a `grep` for the thing you
removed costs one command and separates "the control is redundant", "the test is not measuring
it" and "nothing was reverted" — three states that produce the identical green output. §4b is a
whole section about the second of those; this is the third, and it is the only one of the three
where the green is simply correct.

## 4i. Three blocks, one page, and none of them had ever held a row

P2-04-6 began the way P2-02-9 did and that is the point. The order detail page has a shipping
card, a billing card and an order-notes panel; `order_billing_details`, `order_notes` and the
`countries`/`states`/`cities` those cards resolve through were **all empty** in the development
fixture. Both address cards rendered every field blank. The notes panel rendered nothing at all.
Neither looked broken, because neither is: an empty state is what a block does when there is
nothing to show.

**Getting one note on screen took three fixture fixes and each intermediate state read as
success.** Seeding five notes left the count at zero (they went on the oldest order; the check
opens the one the list shows first). Seeding them on every order left it at zero (the panel is
gated on `Manage Order Note`, which the fixture merchant's role did not hold — while `Show Order`
was true, which is why the page opened at all). Only the third attempt produced a note. Then,
with the page finally populated, it yielded **four correctness defects and six contrast
failures** in one sitting, including every address label and value at 1.10:1 and another tenant's
downloadable file being linked.

**So: seed the screen before you design it.** Not after, and not "it renders". The question is
not whether the block appears; it is whether anyone has ever seen it with a row in it. Both times
this phase, the answer was no, and both times the defects were waiting behind the empty state.

## 4j. A guard that cannot fire is worse than no guard

A backtick inside a probe's template literal ends the literal and the file stops parsing. It has
now happened three times in this phase, always in a comment inside the probe where a backtick is
the natural way to quote a column name, and Node's error points at the identifier *after* the
stray backtick so it reads like an unrelated typo.

The first version of `check-order-detail-live.cjs` answered that with a runtime check: if the
probe string contains a backtick, print a message and exit. **It can never fire.** If the
backtick is there the file does not parse, so no line in it runs — including that one. It looked
like protection, it was written as protection, and it protected nothing.

`node --check <file>` is the check that works, costs one command, and would have caught all three.
Run it before invoking a tool you have just edited. The lesson generalises past this file: a guard
placed downstream of the failure it is guarding against is decoration.

## 4k. A check that crashes instead of naming a case hides the thing it caught

`check-pinned-column-live.cjs` was bite-tested five ways and **two of its five bites found a
defect in the check rather than in the code**. Both are the same shape and neither shows up when
the check is passing.

The first: the bite that removes `ss-col-pinned` made `page.$eval` throw on the missing selector.
The regression *was* detected — a throw exits non-zero — but the output was a Playwright stack
trace naming no case, so the next person has to work out what broke before they can work out why.
Read with `page.$` and a null check, it prints `FAIL the actions column carries ss-col-pinned`.

The second is worse, because it is silence rather than noise. The per-configuration block did
`continue` when the pinned class was absent, to skip the sticky and colour cases that have nothing
to measure without it. The reachability case — *can a merchant click Edit* — was inside that
block. So the check's **most important claim went unreported at exactly the moment it stopped
holding**: six failures, and not one of them about whether the button could be reached. Lifting it
into its own function, called in both paths, made the same bite print
`clicked (1223,421) ... and the page did not navigate` — in a 390px-wide viewport, which is the
whole diagnosis in one line.

The rule both give: **bite-test for the message, not only for the exit code.** A red check that
does not say what is red costs the next person the investigation you already did.

## 4l. A deterministic failure can look like a flake if the observer's window is narrow

`check-order-detail-live.cjs` reported `500 /top-brand-category-chart` in one run of four. The
tempting reading is "flaky, re-run it". The log says otherwise: `HomeController::
top_brand_category_chart()` builds four queries on MySQL's `SUBSTRING_INDEX` and `CURDATE()`,
which SQLite does not have, so on the SQLite dev instance that endpoint **500s every single
time**. What varies is only whether its background XHR lands inside the window the check is
watching.

Two things follow. The endpoint is not evidence of a production defect — production is MySQL,
where both functions exist — and running it here is not evidence that it works. And "intermittent"
described the *observation*, never the behaviour. Recorded as F53; the double-counting the same
query does to `orders.final_price` is F54, with the half that is unverified marked as unverified.

## 4m. A design-system token is only correct against the surface it was solved for

The third time this phase has paid for the same lesson, and the first time it was the design
system's own scoping that was incomplete rather than a host colour being mis-assumed.

P2-05-4 put design-system components into the host's Bootstrap modal. The modal keeps a **white**
`.modal-content` in dark mode — measured `rgb(255, 255, 255)` while `data-ss-theme="dark"` sits on
`<html>` — so every token resolved for a dark surface was drawn on white. The hint text measured
**2.18:1**.

The fix was not a colour. The dark block has always been documented as working "on `<html>` or on
any container", and it does — **but only in one direction**: the light palette lived on `:root`
alone, which matches `<html>` and nothing else, so a subtree inside a dark page could never be
returned to light. A claim in a comment that is true one way and false the other, exactly the
failure mode CLAUDE.md records about the skills section being "wrong in both directions".

And measuring that turned up something larger that is **not** this slice's doing:
`custom.css:4482` carries `html[data-ss-theme="dark"] body label { color: #9DA3B4 !important }` —
a **bare element selector**, added for the sidebar and DataTables chrome, which catches every
label in the application. On the white modal that is **2.52:1**, and the previous
`<label class="form-label">` markup was matched by it just as the converted markup is. So the
conversion did not introduce it; the conversion is simply the first thing that measured it.
Roughly sixty other modal forms still carry it (ledger **F126**).

**The rule to carry forward:** before putting a design-system component on a surface the host
paints, measure that surface. `--ss-*` tokens follow `data-ss-theme`; the host's chrome does not,
and the two disagree inside modals, pinned table cells (see `components/datatable.blade.php`) and
anywhere else the host sets its own background.

## 5. What a successor should do first

1. **Read [`PHASE-02-MASTER-EXECUTION-PROMPT.md`](PHASE-02-MASTER-EXECUTION-PROMPT.md)**, not a
   summary of it. Its coverage check names four workstream requirements that have no ledger row.
2. **Bring the environment up before changing anything**, using §6. Everything in this phase that
   is worth trusting was measured there.
3. **Do not re-litigate the settled holds.** P2-06-1's RTL duplication was the preview harness,
   measured eight ways in the real application. B2 is cleared. F8 and F9 are resolved.
4. **Resume at P2-07-10 (products and inventory visibility in the app).** As of 2026-10-28,
   **P2-07-8, P2-07-9, P2-07-13 and P2-07-14 are DONE** and **P2-07-15 is PARTIAL** (the
   account-state half landed; token lifetime and the absent refresh path still need an owner
   decision). The remaining Flutter rows are **P2-07-10, P2-07-11, P2-07-12**, then the
   outstanding P2-01/P2-03/P2-04 work, the remaining P2-06 contextual guidance, and **P2-08
   last**. Two measurements already taken for P2-07-10, so they need not be repeated: the app's
   product list **already shows stock and stock status** (`products_screen.dart:665-666`), so the
   row is narrower than it reads; and `product_widget.dart:75` renders `stock: -` for **every
   variant product**, because the list API returns the parent's `product_stock` and the real
   quantities live in `product_variants` rows it does not send. That is the actual gap, and it is
   an API-shape question, not a widget question.
5. **Resume at P2-05-5 (staff and permissions).** P2-05-4 closed on 2026-10-27; P2-05-1 through
   P2-05-4 are DONE. The remaining order the owner set is **P2-05-5 → P2-05-8, then P2-06,
   P2-07 (Flutter), the rest of P2-01/P2-03/P2-04, and P2-08 last**. D-21 is delivered; **D-13
   remains NOT DONE with `$plan` suspended**, D-20 stays **PARTIAL**, and **D-22/F121** (a deleted
   product or variant 500s a payment return, with `PayUPaymentController:507` a known site) has
   not been started.
6. **F20 is the one sweep worth proposing to the owner.** `Utility::dateFormat(null)` renders the
   Unix epoch at 64 call sites, 28 of them vendored, and F22 says the same helper also prints
   English month names inside the Arabic admin. One line inside it fixes both — and changes output
   on screens across seventy modules, which is why it is a proposal and not a commit.

## 6. Bringing the environment up

```bash
# Laravel
composer install --no-interaction --prefer-dist --no-scripts   # ~20 min: git-clone fallback
php artisan key:generate --force                               # if .env is fresh

# The dev instance the screenshot harnesses drive (invented data only)
# ADD `DEBUGBAR_ENABLED=false` TO `.env` FIRST. The Laravel Debugbar is `position: fixed`
# with z-index 6000000 across the bottom of the viewport: it sat in every screenshot and it
# covered the product list's first-row actions at 390px, which cost an afternoon reading
# `elementsFromPoint` output before the overlay was the obvious answer.
rm -f database/p2dev.sqlite && touch database/p2dev.sqlite
DB_CONNECTION=sqlite DB_DATABASE=$PWD/database/p2dev.sqlite php artisan migrate --force
DB_CONNECTION=sqlite DB_DATABASE=$PWD/database/p2dev.sqlite CACHE_STORE=file \
    php artisan tinker --execute="require base_path('database/seed-p2dev.php');"

# THE MODULES THE HARNESSES NEED. This step was missing, and omitting it does not produce a
# vacuous pass -- `check-pinned-column-live.cjs` has a premise case that FAILS LOUDLY when
# BulkDelete's select-all column is absent ("NOT MEASURED -- no add_on_managers row for
# BulkDelete on this instance"), so following the procedure exactly gave 57/58 with the one
# failure being the harness correctly reporting an unmet premise. `seed-p2dev.php` creates no
# `add_on_managers` row; this enables the module through the same two rows production uses.
DB_CONNECTION=sqlite DB_DATABASE=$PWD/database/p2dev.sqlite CACHE_STORE=file \
    php artisan tinker --execute="require base_path('database/enable-p2dev-modules.php');"

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

**The Flutter SDK in the container is 3.47.7 / Dart 3.13.5**, not the 3.41.2 / 3.11.0 recorded
against P2-07-1 — the container was rebuilt. The app suite passes on it (1227 tests) and
`flutter analyze` still reports 246 info / 0 warning / 0 error, so the newer SDK changed nothing
that matters; the recorded version was simply stale.

**Do NOT run the Flutter suite while the Laravel Feature suite is running.** Measured twice: the
Feature suite stalled at 0% CPU with zero cputime, at two *different* tests
(`ModuleAdministrativeRouteGuardRulesTest` the first time, a settings-writer-route test the
second), on both occasions with `flutter test` running alongside. Run alone it completed normally
in 1158s. The symptom reads exactly like a hung test — a specific test name, frozen output — and
it is not one; chasing the named test is wasted effort. Run them one at a time.

## 7. Standing rules this phase did not touch

- **Issue #120 / P1-SEC-40 is NOT resolved.** The exposed production database dump is a separate
  critical incident. It is live on `main` (commit `3935dee8`, `storesyria_ecommercego.sql`) and
  deliberately **absent from this branch** — which is why this branch must never be brought up to
  date by merging `main` without removing it first. No Phase 02 work touches the incident.

  **MEASURED 2026-10-27, and the measurement settles the synchronisation question for good.**
  `git diff --name-only <merge-base> origin/main` returns **exactly one path**, and it is that
  dump. So of `main`'s 156 commits since the fork point, the entire net content difference is the
  dump: **`main` holds no code, test, config or document this branch lacks.** Three things follow:

  1. A **fast-forward is impossible** — this branch carries 56 commits of its own, so neither side
     is an ancestor of the other.
  2. A **merge is unsafe**: its only effect would be to import a 20,470,785-byte phpMyAdmin dump
     carrying **198 `CREATE TABLE` and 482 `INSERT INTO` statements** into this branch, which the
     standing limits forbid outright ("Never commit secrets, dumps, or real customer data").
  3. A merge is also **unnecessary**, which is the part that was not previously established. The
     branch is not behind on any work; it is behind on one file nobody wants.

  So the correct action on "sync the branch" is **do neither, and say why**. That remains true
  until the dump is removed from `main`'s tree and its history, which is incident work and not
  Phase 02's to perform.
- **Phase 01's release gates remain open and separately tracked** in
  `docs/development/phase-1/PHASE-01-CLOSURE-EXECUTION.md`.
- Green CI is necessary evidence, not proof of production readiness. The Feature suite's accepted
  failures and skips are declared in `.github/known-test-outcomes.json`; the gate asserts that set
  in both directions.

---

## 4n. A security fix scoped to the SCREEN instead of to the DATA closes nothing

P2-07-13 gated `/dashboard`: it stopped serving order counts, `final_price`, `discount_amount` and
`cost_price` to a staff member without the matching permission. Eleven tests, every one of them
bitten by reverting the thing it named. The slice looked finished.

Independent review then read the rest of the controller. **Four sibling handlers served the same
three money fields to the same token** — `Top_orders` (the dashboard's own widget, orders ranked
*by* `final_price`), `orderlist`, `productlist`, `couponlist` — each guarded by
`resolveOwnedStore()` and nothing else. That is a *tenant* control, and it admits any store in the
caller's tenant by design. So the staff member who could no longer read order money on the
dashboard read it by asking a different route, and `routes/api.php` declares no permission
middleware anywhere to catch them.

The slice was worse than incomplete. It also began **telling the client a permission list**, so an
app that hid the orders tile would have presented a UI stating that staff cannot see order money
while `Top_orders` handed it over on request. A visible control over an absent one.

**The rule this leaves:** when gating a field, grep for the field, not for the screen. `final_price`
appears in five handlers in one file; `cost_price` in two. The question is never "is this screen
protected" but "is this *datum* reachable", and the answer is a search over the column name across
every route the client can call. The same reasoning applied to analytics found `visitorchart` and
its twin `staticsdata` still serving 15 days of store traffic to a zero-permission token.

Two further things the same review found, both of which generalise:

- **An optional parameter with a plausible default is a trap with a delay on it.**
  `MerchantPermissions::names($user, $storeId = null)` defaulted to `users.current_store`. Correct
  in `dashboard()`, which also keys its data on `current_store` — and wrong in every other handler
  in the file, which keys its data on `?store_id=`. The defect would have arrived by *omission*,
  the first time someone added a gate without passing the store. The parameter is required now.
  A default that is right for the first caller and wrong for the next is not a convenience.
- **An appended attribute makes a string assertion lie.** `assertStringContainsString('final_price')`
  passed because `final_price` is in `Product::$appends`, so it appeared in the top-products block
  whether or not the `recent_order` block was served. Assert the **key**, in the block you mean.

## Resume point — 2026-10-28, after P2-04-9

**Branch:** `claude/store-syria-p02-continuation-smlpwi` (both repos). **Do not merge to `main`,
do not deploy, do not run any migration against production.**

**Last commits (Laravel), newest first:**

| commit | what | gate state |
|---|---|---|
| *this one* | P2-04-9 saved views | derived suites green; **full Feature gate not yet run over it** |
| `58edb0e2` | P2-04-6 order-note author + §3 PaymentWall integrity | derived suites green |
| `a336703b` | §2 wording + §4 F149 null-store refusal | derived suites green |
| `be901ad8` | evidence: the Feature gate PASSED over `427c2c03` | — |
| `427c2c03` | P2-04-11 order source + F146 | **full Feature gate PASSED** (6374 passed, 0 errors, outcome set matches the manifest exactly) |

**Three migrations exist and have run in DEV/TEST ONLY.** None has been run anywhere else and
none may be: `2026_10_28_000001` (`orders.order_source`), `2026_10_28_000002`
(`order_notes.created_by`), `2026_10_28_000003` (`saved_views`). The first carries a production
note stating that it must be applied before or atomically with its code, because sixteen writers
stamp the column unconditionally.

**Exact resume point.** The next required action is the **full Feature gate over the final tree**,
with the known-outcome assertion, then the remaining Phase 02 rows in this order: P2-04-6's
design-system restyle of the order detail shell, the orders Kanban, P2-04-12 printing/export,
then P2-03 (variants and media, product editor), then P2-01 (breadcrumbs, header, quick actions,
loading/success states, nested dialogs, keyboard focus), then the remaining P2-06/P2-07 partials,
then **P2-08**.

**What must not be re-done:** P2-04-10, P2-04-11, P2-04-13, P2-04-14, P2-04-15, P2-04-6's author
half, P2-04-9, P2-07-9…-13, P2-01-16, P2-05 (all eight rows), F146, F147, F149. The ledger rows
carry their evidence.

**Open and deliberately not closed:** D-13, D-20, E13, D-22/F121, Issue #120, token lifetime, and
**MySQL remains UNVERIFIED** — every figure in the evidence file is SQLite, and no claim of MySQL
coverage has been made. F150 (34 of 40 `payment_type` values written through the translator; 23 of
40 absent from the display map) is recorded for an owner decision with its analysis in
`docs/security/F146-POS-PAYMENT-TYPE-DATA-INVENTORY.md`; no backfill exists or may be run.
P2-04-12's invoice-preview clause is the one scope decision still open (D-24b).
