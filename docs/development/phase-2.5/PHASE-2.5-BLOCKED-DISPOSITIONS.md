# Phase 2.5 — the 10 BLOCKED surfaces, with a recommended disposition for each

**Prepared:** 2026-11-11 · **Matrix state:** frozen at 295 surfaces —
225 REDESIGNED AND VERIFIED / 24 PREMIUM VERIFIED / 28 OUT OF SCOPE / 8 DUPLICATE / 10 BLOCKED / 0 PENDING

Requested by the owner's directive of 2026-11-11: *"Report the 10 BLOCKED items separately with
recommended dispositions."*

**Nothing here changes the matrix.** The same directive says to keep it frozen and not to reopen
the 295-surface inventory, so every row below stays BLOCKED until the owner decides otherwise.
Three of these dispositions are things I could have executed; they are left undone deliberately,
and the table says which.

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

### Recommended disposition: **remediate, as a small scoped follow-up. Do not accept as unverified.**

This is the group I would fix first, because the cost is low and the path is already built. The
repository holds **42** standalone MySQL verification harnesses in
`tests/Support/Security/bin/`, plus `run-mysql-verifications.sh` to drive them, each creating and
dropping its own throwaway database. Rendering four routes against MySQL is one more script in a
well-worn shape.

**The honest cost, because it is not zero.** Neither this container nor CI has a MySQL server —
`.github/workflows/ci.yml` declares no `services:` block, so those 42 harnesses are operator-run
today, not continuously verified. So the work is: provision MySQL (a CI service container, or a
local server for a one-off run), then write the script. Modest, not free.

**Why not just accept them.** Accepting four surfaces as verified on the strength of "it should
work on MySQL" is exactly the reasoning that produced the capture-integrity failures this phase
already had to repair — six surfaces marked PREMIUM VERIFIED on photographs of permission
refusals. An unrendered screen has not been reviewed, and recording it as reviewed would make the
matrix assert something nobody checked.

**Secondary benefit worth noting.** A MySQL service in CI would also move those 42 security
harnesses from operator-run to continuously verified. That is a larger win than the four report
bodies and argues for doing this properly rather than with a local one-off.

---

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
| 1 | Customer report body | A | Remediate via a MySQL harness | engineering + a MySQL server |
| 2 | Sales report body | A | Remediate via a MySQL harness | engineering + a MySQL server |
| 3 | Sales report bar body | A | Remediate via a MySQL harness | engineering + a MySQL server |
| 4 | Top Sales Report | A | Remediate via a MySQL harness | engineering + a MySQL server |
| 5 | Shopify import (5 screens) | B | Reclassify OUT OF SCOPE | owner: provision a sandbox, or accept |
| 6 | WooCommerce import (4 screens) | B | Reclassify OUT OF SCOPE — it has no pixels of its own | owner: provision a sandbox, or accept |
| 7 | Invoice PDF viewer | C | Accept current evidence; device capture optional | nothing — behaviour is test-pinned |
| 8 | Gateway editors (COD/Stripe/Bank) | D | Leave inactive; record why in the code | owner: product decision |
| 9 | Edit customer (web) | D | Schedule in Phase 04 | owner: product decision |
| 10 | Wishlist CRUD | D | Unregister the routes regardless; feature is owner's call | owner: product decision |

**If only one of these is actioned, make it Group A** — four surfaces, a path the repository has
walked 42 times, and a MySQL service in CI would additionally convert 42 operator-run security
harnesses into continuously verified ones.

**Nothing above has been executed.** The matrix stays at 225/24/28/8/10/0.
