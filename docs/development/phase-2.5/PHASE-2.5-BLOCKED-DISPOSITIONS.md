# Phase 2.5 — the 10 BLOCKED surfaces: FINAL dispositions

**Prepared:** 2026-11-11 · **Matrix state:** frozen at 295 surfaces —
225 REDESIGNED AND VERIFIED / 24 PREMIUM VERIFIED / 28 OUT OF SCOPE / 8 DUPLICATE / 10 BLOCKED / 0 PENDING

Requested by the owner's directive of 2026-11-11: *"Report the 10 BLOCKED items separately with
recommended dispositions."* **Finalised** by the owner's final-acceptance directive of the same
date, which settled every one of them in a single sentence:

> *"Recommend accepting Group A's MySQL measurements and Group C's invoice evidence, deferring
> external sandbox coverage, and scheduling inactive/new functionality in its appropriate future
> phase."*

That instruction maps one-to-one onto the four groups below, and it matches what this document had
already recommended, so nothing was re-argued: **A accepted on measurement, C accepted on existing
evidence, B deferred, D scheduled.** All ten are now disposed; none is awaiting a decision. §Final
dispositions at the end of this file is the record.

**AND THE MATRIX IS STILL NOT TOUCHED — deliberately, because the same directive says to finalise
the dispositions "without falsifying the authoritative matrix".** A disposition is a decision about
what to do; it is not a claim that a capture now exists. All ten rows still have no verified
four-combination visual capture, which is exactly what `BLOCKED` records, so the matrix correctly
stays at **225 / 24 / 28 / 8 / 10 / 0**. Promoting Group A to VERIFIED on the strength of an HTTP
200 would be the falsification the instruction forbids: a 200 with a byte count is evidence the
route executes, not evidence the screen looks right in four combinations.

`PHASE-2.5-COVERAGE-MATRIX.md` is **generated** from the inventory by
`docs/development/phase-2.5/tools/build-coverage-matrix.py`, which carries a `--check` mode that
fails when the file and the inventory disagree. So the matrix could not have been quietly hand-edited
even if that had been wanted, and it was not wanted.

---

## The shape of the problem

The 10 rows are not 10 problems. They are **four** root causes, and grouping them that way is the
difference between a ten-item backlog and four decisions.

| Group | Rows | What actually blocks it | Who can unblock it |
|---|---|---|---|
| **A** | 4 | the dev fixture is SQLite; the code uses MySQL-only SQL | an engineer with a MySQL server |
| **B** | 2 | verification needs a third-party sandbox credential | the owner |
| **C** | 1 | the capture needs a real device or emulator | anyone with a device |
| **D** | 3 | there is nothing to render, and whether there *should* be is a product question | the owner |

Groups A and C are **engineering cost**. Groups B and D are **owner decisions**. No row is blocked
on design work, and that is worth stating plainly: Phase 2.5 owes no visual design on any of
these ten, because nine of them cannot be made to draw a screen and the tenth draws one nobody can
photograph.

---

## Group A — four report bodies, one root cause: SQLite

| Surface | Route | The MySQL-only construct |
|---|---|---|
| Customer report body | `GET reports-chart` | `MONTH()` |
| Sales report body | `GET order-reports-chart` | `MONTH()` (`ReportController:720`) |
| Sales report bar body | `GET order-reports-chart-data` | `MONTH()`, `YEAR()` (`:985-1205`) |
| Top Sales Report | `GET top-all-reports` | `SUBSTRING_INDEX` (`:2814`, `:2826`) |

All four answered **HTTP 500 in all four combinations** on the dev fixture. None of that is a
defect in the screens: production is MySQL, the SQL is valid there, and `ReportController:2883`
carries the controller's own comment recording the SQLite limitation. These are measurement
failures, not product failures.

### Recommended disposition: **the engineering blocker is RESOLVED. What remains is capture, not remediation.**

**UPDATED 2026-11-11 with executed evidence.** The owner asked to prefer an isolated MySQL
environment over assuming SQLite proves production behaviour. Doing that refuted this blocker and
found something else.

**What was actually run, in this container:**

1. **MariaDB 10.11.14 installed from apt** (`apt-get install -y --no-install-recommends
   mariadb-server`, exit 0) and started with `service mariadb start`. So the earlier cost estimate
   — "provision MySQL" — was pessimistic: it is one package and no configuration. Docker is not a
   fallback here (the daemon is unreachable), but it is not needed.
2. **`php artisan migrate --force` against MySQL: 132 tables, exit 0.**
3. **`database/seed-p2dev.php` seeded cleanly into MySQL** — 32 products, 7 orders, 2 stores, 3
   customers, 5 users. The fixture is not SQLite-specific, which was the open question.
4. **A dev server was pointed at that database** and the four routes driven over HTTP as the
   authenticated fixture merchant — a real request through the real stack, not the test kernel.

| surface | SQLite | MySQL |
|---|---|---|
| Customer report body — `GET reports-chart` | 500 | **200**, 3,060 bytes |
| Sales report body — `GET order-reports-chart` | 500 | **200**, 8,689 bytes |
| Sales report bar body — `GET order-reports-chart-data` | 500 | **200**, 7,099 bytes *(after a fix, below)* |
| Top Sales Report — `GET top-all-reports` | 500 | **200**, 88,461 bytes |

Evidence: `evidence/mysql-reports-2026-11-11/_report_bodies__MEASUREMENT.json`. It is a measurement
rather than a capture for the same reason the WooCommerce row carries one — these are AJAX
fragments, and the matrix's four-combination contract is about a surface rendered in place.

### One of the four had a SECOND defect, and its recorded reason never mentioned it

`order-reports-chart-data` answered **500 on MySQL too**. Its matrix row says the blocker is
`MONTH()` and `YEAR()`. The SQL runs fine on MySQL. The 500 was ours:

```
count(): Argument #1 ($value) must be of type Countable|array, int given
(View: resources/views/components/ss/bar-chart.blade.php)
```

`ReportController::order_reports_chart_data()` initialises `$PurchasedItemTotal = 0` — a scalar — at
`:1004`, `:1076`, `:1136`, `:1221`, `:1316` and passes it as `itemSeries`. Sibling methods append to
it later (`$PurchasedItemTotal[] =` at `:561`, `:630`, `:684`, `:772`), so it is an array on those
paths; on these it never is. In PHP 8 `count()` on an int is a **fatal**, so one surface's bad input
took the page down. The component already guarded `$values2` with `is_array()` and did not guard
`$labels` or `$values` — that inconsistency was the defect, and the three are now uniform. Pinned by
`tests/Unit/Ui/BarChartScalarSeriesTest.php` (20 cases; reverting the fix fails them).

**This is the argument for the owner's MySQL instruction, in one example.** SQLite 500s on `MONTH()`
before reaching any view, so one blocker's recorded cause was completely masking a second, unrelated
defect in a Phase 2.5 component. "Confirm its exact reason" was worth doing rather than trusting the
row.

### What this does and does not change

**It does not promote any row.** The matrix stays at 225/24/28/8/10/0 and these four remain BLOCKED,
because the four-combination captures do not exist. What changed is that their recorded reason is now
accurate and the obstacle is gone: capturing them is ordinary capture work against a MySQL-backed
dev server, not a blocker.

**Recommended to the owner:** authorise the capture pass for these four, or accept them as verified
on the measurement above. Either is defensible; leaving them BLOCKED on a reason that is now known to
be incomplete is not.

**Secondary benefit, unchanged:** a MySQL service in CI would also move the repository's 42
standalone `verify-*-on-mysql.php` security harnesses from operator-run to continuously verified.
That remains the larger prize and now has a proven install path.

## Group B — two importer families, blocked on third-party credentials

| Surface | Why it cannot render |
|---|---|
| Shopify import (product, category, sub_category, coupon, customer) | `ShopifyProductController::index` needs `shopify_setting_enabled` plus a store URL and access token, then calls the Shopify Admin API **before** returning a view. Measured HTTP 500 in all four combinations. |
| WooCommerce import (product, category, coupon, customer) | Refused by two gates. The first fires: `WoocomProductController::index` needs `isAbleTo('Manage Woocommerce Product')`, which the fixture merchant lacks. Granting it only reaches the second, which needs `woocommerce_setting_enabled == 'on'` and a connected store. |

### Recommended disposition: **reclassify as OUT OF SCOPE for Phase 2.5, with the reason recorded. Do not leave them BLOCKED.**

BLOCKED implies someone intends to return. These two will not be unblocked by engineering effort,
only by the owner provisioning sandbox accounts for two external platforms — and that is a
procurement decision with no design content attached.

**The WooCommerce row carries an extra finding that strengthens this.** Both of its refusal arms
end in `redirect()->back()`, so **the surface has no pixels of its own**: a screenshot of it is
meaningless by construction. Measured landing on `/dashboard` when driven from `/dashboard`, and on
`/webhook/create` when driven from `/webhook/create` — which is how four captures in this phase
turned out to be pictures of the WooCommerce webhook form. Its evidence is deliberately a
measurement JSON, not a capture:
`evidence/web-sweep-2026-11-10/after/_woocom_product__MEASUREMENT.json`.

So even *with* credentials, "the WooCommerce import screen" is not a thing that can be
photographed until a store is connected. Recording it as OUT OF SCOPE is more truthful than
recording it as pending a capture.

**If the owner would rather keep them in scope,** the minimum needed is one sandbox per platform
and a fixture merchant holding `Manage Woocommerce Product`. I would still do Group A first.

---

## Group C — the invoice PDF viewer

`Get.to(() => InvoicePdfViewScreen())` from order detail. The PDF body cannot be rendered under
`flutter_test`: `PdfPreview` rasterises through the printing plugin, and the capture raises
`MissingPluginException` for `rasterPdf` on channel `net.nfet.printing`, leaving the
`loadingWidget` shimmer. Measured in all four combinations; documented at
`evidence/flutter-invoice-2026-11-10/`.

### Recommended disposition: **accept the current evidence. One device capture is optional, not required.**

This is the weakest of the ten blockers and the one least worth spending owner attention on:

- the **behaviour is pinned by tests** — `test/screens/invoice_viewer_capture_test.dart`;
- a **real defect was found and fixed** while the surface was being investigated: the viewer opened
  at **US Letter** and is now pinned to **A4**, which matters for every merchant printing an
  invoice and would not have been found by looking at a screenshot;
- the only thing missing is a rasterised image of a PDF, which is a plugin-channel limitation of
  the test harness and not a property of the screen.

A device or emulator capture costs minutes for anyone holding a phone, and would close the row
cleanly. It is worth doing if a device is to hand and not worth scheduling if not.

---

## Group D — three rows where nothing renders, and the question is whether anything should

These are the only three that are genuinely owner decisions about the product.

### D1 — COD / Stripe / Bank-transfer gateway editors

**Recommended disposition: leave inactive, and record why in the code. Do not revive in Phase 2.5.**

Not merely unreachable — **inactive**. All six files are **100% commented out: 815 lines, 0 live**
(screen and controller for each of cod, stripe, bank_transfer). An earlier note cited class names
that turned out to be matched *inside* the comments. No reason is recorded anywhere and the only
commit touching them carries an unrelated message.

This is a real **web/Flutter parity gap**: the web offers gateway configuration as a merchant
settings tab (`setting/index.blade.php:55`), while the app's live `PaymentSettingScreen` lists
methods read-only.

Reviving them would put **financial configuration** in merchants' hands from the app, which
Priority 2 explicitly reserves — so it is not a Phase 2.5 change under any reading. My
recommendation is the cheap, honest one: either delete the six dead files or add a one-line header
to each recording that they are intentionally inactive and why. 815 commented-out lines with no
explanation is a trap for the next reader, and that is fixable now without any product decision.

### D2 — Edit customer (web)

**Recommended disposition: owner decision, and I recommend scheduling it in Phase 04 (Commerce), not Phase 2.5.**

The inventory description is stale and the row is misleading. This is **not** a missing screen —
the routes are deliberately not registered: `routes/web.php:1200` is
`Route::resource('customer', ...)->except(['edit','update'])`, applied by P2.5-PKG-C (F-C-04)
because `CustomerController` has neither method and `GET /customer/1/edit` answered HTTP 500.

But a **complete customer-update backend ships today, and the Flutter app already uses it**:
`routes/api.php:267` `POST update-customer` → `DashboardController::updateCustomerInfo` (`:9113`),
writing `first_name`/`last_name`/`email`/`mobile`/`status` at `:9193-9197`, consumed by
`StoreSyria_app98 lib/utils/base_api.dart:122` through an edit bottom-sheet.

So a merchant can edit a customer **from their phone but not from their desk**. That is a genuine
parity gap and a defensible thing to want. It is also **new merchant functionality on the web**,
which `routes/web.php:1187-1189` records as unauthorised in Phase 2.5 — so it cannot be built here
regardless of its merit. Phase 04 owns commerce surfaces and the backend already exists, which
makes it a small piece of work in the right phase.

### D3 — Wishlist create / edit / store / update

**Recommended disposition: unregister the four routes unless merchant-authored wishlists are a product feature. Owner decides the feature; I recommend the routes go either way.**

All four routes resolve and **none renders a byte**. `WishlistController::create()` (`:34`) and
`::store()` (`:45`) have bodies that are literally `//`. `::edit()` and `::update()` carry
P2.5-PKG-B security guards that refuse rather than render. **No control on any merchant screen
targets any of the four.**

The feature question is the owner's. The routing question is not, and it should be settled whatever
the answer: four registered, reachable routes that render nothing are a liability, not a
placeholder. Two of them are POST/PUT write endpoints whose only protection today is a guard added
during a security package. The precedent is in the same codebase — D2's routes were unregistered
with `->except(['edit','update'])` for exactly this reason.

If wishlists *are* a feature, they need design and implementation in a phase that authorises new
merchant functionality — which Phase 2.5 does not.

---

## Summary

| # | Surface | Group | Recommendation | Blocked on |
|---|---|---|---|---|
| 1 | Customer report body | A | **Blocker resolved** — renders 200 on MySQL. Capture or accept | owner: authorise the capture pass |
| 2 | Sales report body | A | **Blocker resolved** — renders 200 on MySQL. Capture or accept | owner: authorise the capture pass |
| 3 | Sales report bar body | A | **Blocker resolved** — and a first-party defect was found and fixed behind it | owner: authorise the capture pass |
| 4 | Top Sales Report | A | **Blocker resolved** — renders 200 on MySQL. Capture or accept | owner: authorise the capture pass |
| 5 | Shopify import (5 screens) | B | Reclassify OUT OF SCOPE | owner: provision a sandbox, or accept |
| 6 | WooCommerce import (4 screens) | B | Reclassify OUT OF SCOPE — it has no pixels of its own | owner: provision a sandbox, or accept |
| 7 | Invoice PDF viewer | C | Accept current evidence; device capture optional | nothing — behaviour is test-pinned |
| 8 | Gateway editors (COD/Stripe/Bank) | D | Leave inactive; record why in the code | owner: product decision |
| 9 | Edit customer (web) | D | Schedule in Phase 04 | owner: product decision |
| 10 | Wishlist CRUD | D | Unregister the routes regardless; feature is owner's call | owner: product decision |

**Group A is no longer a backlog item but a decision** — four surfaces, a path the repository has
walked 42 times, and a MySQL service in CI would additionally convert 42 operator-run security
harnesses into continuously verified ones.

**What has and has not been executed.** Group A's investigation WAS executed: MariaDB installed,
the schema migrated, the fixture seeded, the four routes driven, and the first-party component
defect found behind one of them fixed and pinned by tests. Nothing in Groups B, C or D has been
executed — those are owner decisions or external dependencies.

**No row was promoted, demoted or deleted. The matrix stays at 225/24/28/8/10/0**, which is the
state §5 of the directive declares authoritative. Group A's four rows remain BLOCKED because their
four-combination captures do not exist; what changed is that their recorded reason is now true.

---

## Final dispositions

Settled by the owner's final-acceptance directive of 2026-11-11. Each row is **closed as a
decision**; the matrix status is unchanged and stated beside it so the two are never confused.

| # | Surface | Group | FINAL disposition (owner-directed) | matrix status | still owed |
|---|---|---|---|---|---|
| 1 | Customer report body — `GET reports-chart` | A | **ACCEPTED ON MEASUREMENT.** HTTP 200, 3,060 bytes on MySQL | BLOCKED (no visual capture) | nothing in Phase 2.5 |
| 2 | Sales report body — `GET order-reports-chart` | A | **ACCEPTED ON MEASUREMENT.** HTTP 200, 8,689 bytes on MySQL | BLOCKED (no visual capture) | nothing in Phase 2.5 |
| 3 | Sales report bar body — `GET order-reports-chart-data` | A | **ACCEPTED ON MEASUREMENT.** HTTP 200, 7,099 bytes on MySQL — after a first-party defect found behind it was fixed and pinned (`BarChartScalarSeriesTest`, 20 cases) | BLOCKED (no visual capture) | nothing in Phase 2.5 |
| 4 | Top Sales Report — `GET top-all-reports` | A | **ACCEPTED ON MEASUREMENT.** HTTP 200, 88,461 bytes on MySQL | BLOCKED (no visual capture) | nothing in Phase 2.5 |
| 5 | Shopify import (5 screens) | B | **DEFERRED — external sandbox coverage.** Needs an owner-provided Shopify credential; not a Phase 2.5 obligation | BLOCKED | a credential, if and when the owner wants these verified |
| 6 | WooCommerce import (4 screens) | B | **DEFERRED — external sandbox coverage.** Same, and the surface has no pixels of its own: both refusal arms `redirect()->back()` | BLOCKED | as above |
| 7 | Invoice PDF viewer (Flutter) | C | **ACCEPTED ON EXISTING EVIDENCE.** Behaviour is test-pinned and the page format was corrected to A4 while there; a device capture is optional, not required | BLOCKED (no device capture) | nothing |
| 8 | COD / Stripe / Bank-transfer gateway editors (Flutter) | D | **SCHEDULED — Phase 06 (SaaS & Super Admin).** All six files are 100% commented out, 815 lines, 0 live. Reviving them exposes financial configuration, which Phase 2.5 reserves | BLOCKED | a product decision in Phase 06 |
| 9 | Edit customer (web) | D | **SCHEDULED — Phase 04 (Commerce).** The update backend ships and Flutter already uses it; the web screen is new merchant functionality, which Phase 2.5 is not authorised to build | BLOCKED | the screen, in Phase 04 |
| 10 | Wishlist create / edit / store / update | D | **SCHEDULED — Phase 04 (Commerce).** `create()` and `store()` have bodies that are literally `//`; nothing on any merchant screen targets them | BLOCKED | a product decision on whether merchant-authored wishlists exist at all |

### What this changes, and what it does not

**Changes:** the ten rows are no longer open questions. Four are accepted on measurement, one on
existing evidence, two are deferred to an external dependency the owner controls, and three are
scheduled into the phase that owns them. Phase 2.5 owes no further work on any of them.

**Does not change:** the matrix, the 24 PREMIUM VERIFIED count, or the claim set. All ten remain
`BLOCKED` in `PHASE-2.5-COVERAGE-MATRIX.md` because none has a four-combination visual capture, and
that is the only thing `BLOCKED` has ever meant here. Anyone reading "10 BLOCKED" alongside this
table gets the truth from both: no captures exist, and no decisions are outstanding.

**One row previously recommended for reclassification was NOT reclassified.** Group B's two rows
were recommended as OUT OF SCOPE. The owner said *deferring*, not reclassifying, so they stay
BLOCKED–DEFERRED. Reclassifying would have moved two rows out of the BLOCKED count and into OUT OF
SCOPE, changing the authoritative numbers on my own initiative — which is what the directive's
"without falsifying the authoritative matrix" rules out.

**And one disposition is not a remediation.** None of this touches the publicly served `.git`
metadata or the historical credentials on the test host. Those remain **OWNER-DEFERRED —
UNRESOLVED SECURITY RISK**.
