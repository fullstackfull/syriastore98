# P2.5 ORDERS repair — evidence index (findings #1–#12)

**Date:** 2026-11-09 · **Branch:** `claude/vibrant-bell-12tquy` · **Audit:** `wf_cd4c60b8-8cd`
(363 agents, 115 raised → 103 refuted → 12 confirmed).

The findings themselves, with file:line citations, the three refuter verdicts and a `RESOLVED`
paragraph each, are in [`../../PHASE-2.5-ORDERS-AUDIT-FINDINGS.md`](../../PHASE-2.5-ORDERS-AUDIT-FINDINGS.md).
This file is the index of *evidence that does not live in a test file*.

---

## 1. What proves each finding

| # | proof | kind |
|---|---|---|
| 12 | `tests/Feature/Merchant/OrderStatusChangeFalseNotificationTest.php` — 17 tests, 52 assertions | suite |
| 5, 10 | `tests/Feature/Merchant/OrderFindabilityTest.php` — 26 tests, 162 assertions | suite |
| 7, 9, 11 | `tests/Unit/Ui/OrderGridCardIdentityTest.php` — 10 tests | suite |
| 1, 8, 2 | `tests/Unit/Localisation/OrderArabicTerminologyTest.php` — 6 tests | suite |
| 3, 4, 6 | `tests/Unit/Ui/OrderListControlNamesAndContrastTest.php` — 15 tests | suite |
| 3 | `tests/Support/Security/bin/verify-bulk-select-checkbox-names.php` — 7 checks | **driven script** |
| 3 | `docs/development/phase-2.5/tools/probe-datatables-row-renderer.{html,cjs}` | **headless browser** |

## 2. Bite proof — every group was reverted and re-run

The project's rule is stricter than "write the failing test first": after the fix, revert it and
confirm the test fails again, because a test that passes for the wrong reason is the failure mode
this codebase actually produces.

| group | tests | fail on the reverted code |
|---|---:|---:|
| #12 notification | 17 | **11** (the 6 that pass are 3 premises, 2 success controls, 1 untouched-row) |
| #5 / #10 findability | 26 | **14** |
| #7 / #9 / #11 grid identity | 10 | **7** |
| #1 / #8 / #2 Arabic | 6 | **4** |

## 3. The two evidence artefacts, and why each exists

### `verify-bulk-select-checkbox-names.php` — because the suite cannot see this column at all

`bulkDeleteCloneCheckboxColumn()` sits entirely behind `module_is_active('BulkDelete')`, and
`module_is_active()` opens with `Module::has()`. No WorkDo module is registered in a test run unless
an `add_on_managers` row exists *before* the application boots, because the loader runs inside
`app->booted()`.

**Measured:** inside the suite, `/order` renders neither `select-all` nor `select-row`. So a Feature
test asserting the names are present would have failed, and one asserting they are absent would have
passed both before and after the repair — proving nothing either way. That is precisely the
"structurally blind to module code" hazard `CLAUDE.md` §1 records.

The script boots a subprocess against a throwaway sqlite file seeded by raw PDO before bootstrap
(the pattern `verify-backuprestore-module-gating.php` established, and for the reason it gives: a
service provider is registered once per process). Result, 2026-11-09:

```
ok    PREMISE  module_is_active(BulkDelete) is true in the probe
ok    PREMISE  bulkDeleteCloneCheckboxColumn() returned a column

select-all heading : <input type="checkbox" id="select-all" aria-label="Select all rows">
per-row renderer   : function(data,type,full,meta){return function() { return '<input type="checkbox"
                     class="select-row" aria-label="' + "Select row" + ' ' + this.id + '"
                     value="' + this.id + '">'; };}

ok    the select-all checkbox has a non-empty aria-label
ok    the per-row checkbox renderer emits an aria-label
ok    the per-row name is not a bare constant -- it names the row
ok    the renderer reads only `this.id` from the row
ok    the select-all name and the row name are different

7 checks, 0 failed
```

### `probe-datatables-row-renderer` — because "it probably works" was not good enough

Reading the output above, it is not obvious that the inner function ever runs, nor what `this` is
bound to when it does. `app/Helper/helper.php` relies on `this.id` for **both** the new accessible
name and bulk delete's existing `value` attribute, so if `this` were the `<td>` then `value` would
be empty and **bulk delete would have been broken all along** — a far more serious finding than the
one being repaired.

Measured in headless Chromium with the repository's own `public/js/jquery-3.6.0.min.js` and
`public/vendor/datatables/jquery.dataTables.min.js`:

```json
{
  "cells": [
    "<input type=\"checkbox\" class=\"select-row\" aria-label=\"Select row 4242\" value=\"4242\">",
    "<input type=\"checkbox\" class=\"select-row\" aria-label=\"Select row 77\" value=\"77\">"
  ],
  "inputs": [
    {"value": "4242", "aria": "Select row 4242"},
    {"value": "77",   "aria": "Select row 77"}
  ]
}
CONSOLE: []
```

**Conclusion:** `this` is the row-data object, `this.id` is the row's id, the renderer runs, bulk
delete's `value` was never broken, and the accessible name is unique per row rather than a constant.
No new finding. Reproduce with `node docs/development/phase-2.5/tools/probe-datatables-row-renderer.cjs`.

## 4. Contrast figures, computed rather than remembered

`OrderListControlNamesAndContrastTest` implements WCAG 2.x relative luminance and computes every
figure from the stylesheet, with two premise tests: that the arithmetic reproduces the audit's own
`#5b6665` on `#262b33` = **2.39:1**, and that the dark `--ss-surface` is still `#262b33`.

| what | before | after | floor |
|---|---:|---:|---:|
| `.ss-select` chevron, dark | 2.39:1 | **6.17:1** (`#a7abb2`) | 3:1 non-text |
| `.ss-select` chevron, dark hover surface `#2d323b` | — | 5.52:1 | 3:1 |
| `.ss-select` chevron, light | 5.18–5.42:1 (unchanged) | unchanged | 3:1 |
| Order Id cell, light | 2.63:1 (`#1a1d23` ink on a `#335da8` Bootstrap fill) | **the fill is gone**; `#1a1d23` on the table surface | 4.5:1 |
| Order Id cell, dark | ink `#f0f2f6` on a fill that did not follow the theme | **the fill is gone**; `#f0f2f6` on `#262b33` = 11.5:1 | 4.5:1 |

## 5. Three things the repair found that the audit had not

1. **`Walk-in Customer` (spaced) was wrong too** — `عميل متجول`, "an itinerant customer". The audit
   named only the hyphenated key. The spaced one is what the board card and `Order::order_detail()`
   render, i.e. what the detail page and the API answer with, so fixing one would have left three
   order screens disagreeing about who the buyer is.

2. **Four of this repair's own tests were measuring the wrong column, and the revert check caught
   it.** Fixtures referenced `FIND-ST-SHIPPED` and searched for "Shipped", so they were satisfied
   through `product_order_id` — the one column that was never broken — and passed against the
   unfixed code. References are neutral now and `assertSearchFinds()` asserts that property.
   Bite proof went 11 red → 14.

3. **Two container-pollution traps**, both of which produced a confident false reading first:
   resolving `OrderDataTable` in the test process before the request leaves yajra's
   `datatables.request` singleton bound to a stale empty request and the route answers the HTML page
   instead of JSON (measured both ways in one run); and two searches in one test method are both
   answered with the first keyword, which looked exactly like a status filter matching the wrong
   status. `forgetInstance('datatables.request')` plus a premise test for each now cover it.

## 6. Two census/inventory updates this change required — neither is a weakening

* `docs/security/P01-OBSERVABILITY-INVENTORY.md` — 6 `Log::` line pointers in
  `OrderController.php` moved. Re-pointed by
  `docs/development/phase-1/tools/resync-observability-inventory.py --write`
  (6 re-pointed, 214 unchanged, 220 of 220 documented). That tool **refuses to write** when a line
  was added rather than moved, which is what makes the run evidence rather than a rubber stamp.
* `GatewayReplayGuardCensusTest` — the customer-order write population moves **15 → 16** and
  `not-a-write` **1 → 2**, because `order_grid_view` now carries the same
  `(new OrderDataTable())->query(new Order())` seed the board already had. The parser sees a
  constructor; the method paginates and renders and persists nothing. The population grew by a
  read-only screen, with no new write site and nothing touching payment or stock.

## 7. What was deliberately NOT done

`packages/workdo/AuctionProduct/.../AuctionOrdersController.php:207` and
`packages/workdo/LotteryPrizes/.../LotteryOrderController.php:142` each carry their own copy of the
`Status Change` notification block in the same shape finding #12 describes — `sendEmailTemplate`
ahead of the success check. They are **vendored**, which the owner's standing constraints forbid
editing, so they are recorded here rather than repaired. Neither is reachable from the three order
screens this audit covered.

---

## 8. Real before/after captures — 24 images, driven, all four combinations

`before/` and `after/` each hold **12 PNGs**: `/order`, `/order-grid` and `/order-kanban` ×
`en-ltr` / `ar-rtl` × light / dark, at 1440×900.

Taken with `docs/development/phase-2.5/tools/render-matrix.cjs`, which signs in as the real
merchant, drives `/change-language/{lang}` and the preview-state tool between rows, and **asserts
the state it asked for is the state it got** — `12 rows, 0 not clean` in both runs, with the
observed document direction and the `data-ss-theme` attribute recorded in `render-matrix.json`
beside the images. A row whose premise fails is reported as `PREMISE-FAIL` and never as a reading;
this project has twice published "dark mode" screenshots that were not dark.

**The before set is the same tree with the ten production files reverted** (`git stash push` of
exactly the files this change touches, re-capture, `git stash pop`), on the same fixture, the same
server and the same minute — not an older commit, so nothing but this change differs. **All 12
pairs differ by content hash.**

Fixture: `database/seed-p2dev.php` + the two customer fixtures — store 1 *Damascus Textiles*,
`merchant@example.test`, 9 orders, 32 products, 4 customers, every value invented. The debug bar is
off in both sets (`APP_DEBUG=false`), so the two are comparable pixel for pixel.

### What the pairs show

| pair | before | after |
|---|---|---|
| `_order_grid__en_ltr_light` | cards 1 and 2 both read **`#P25CUSTFILTE…`**, both `Omar Khalil`, both `$150.00` — two different orders, one card | `#P25CUSTFILTER-2-1` and `#P25CUSTFILTER-2-2` in full, and the filter bar is on the screen |
| `_order_grid__*` (all four) | no filter, no search, no sort anywhere on the page | the list's own filter bar, posting back to `order.grid` |
| `_order__en_ltr_light` | the Order Id column is ten filled navy `btn btn-primary` pills, louder than `Create Order` | quiet ident text; `Create Order` is the only primary |
| `_order__ar_rtl_dark` | the Actions column reads **`العمل`** ("the work"); the four filter chevrons are barely visible; the navy pills are the only light-theme brand blue on a dark page | **`الإجراءات`**; the chevrons read at 6.17:1; the ident column follows the dark theme |

### One defect the captures found that the audit had not

In `before/_order__ar_rtl_dark.png` the list's Order Id reads **`P25CUSTFILTER-2-2#`** — the `#`
has moved to the far end of the Latin run, because `#` is bidi-neutral and a leading neutral in a
right-to-left paragraph resolves to the other side. The audit recorded that hazard for the BOARD
card (P2.5-PKG-M F-M-05) and for the grid, and it was live on the LIST too. `after/` reads
`#P25CUSTFILTER-2-2`: the `<bdi>` went in with the #6 markup change, which is the kind of thing
only a capture finds.

### Reproducing

```
docs/development/phase-2.5/tools/serve-dev.sh &
cd docs/development/phase-2.5/tools
DB_CONNECTION=sqlite DB_DATABASE=$PWD/../../../../database/p2dev.sqlite CACHE_STORE=file \
  node render-matrix.cjs --base http://127.0.0.1:8899 --out <dir> \
  --paths /order,/order-grid,/order-kanban
```

**Never `export` those variables into a shell that then runs the test suite** — a real environment
variable beats `phpunit.xml`, the suite migrates a clean schema over the fixture, and the fixture is
gitignored so `git status` says nothing. `tests/bootstrap.php` now aborts the run before any test
loads, but that guard is a floor, not a licence.

### The two CI evidence guards, on the whole corpus including these 24

```
check-distinct-surface-captures.py : scanned 1174 capture(s); 1050 distinct contents
                                     no two captures claiming different surfaces share content
detect-refusal-toast-captures.py   : no capture shows a refusal toast
```

---

## 9. The independent tenancy/authorization review, and the four things it found

`.claude/agents/tenant-authz-reviewer` was run read-only over the whole diff before the commit, and
the worktree was diffed before and after to confirm it changed nothing (it did not). Its verdict on
the four production changes: **no cross-tenant hole, no caller-supplied tenant predicate and no
privilege escalation is introduced.** It verified yajra's `applyFilterColumn()` from vendor source
rather than reasoning about it — `addNestedWhereQuery(..., 'or')` always parenthesises, so an
`orWhere` written at the wrong level inside a `filterColumn` closure *physically cannot* reach the
top level, and the store predicate from `query()` sits outside the group either way.

It also found four things worth having, three of them in this change:

| # | finding | what was done |
|---|---|---|
| 1 | `json_encode(JSON_HEX_QUOT)` protects the **JS literal**, not the **HTML attribute** — at runtime the string holds a literal `"`, which closes `aria-label="`. A `Select row` translation of `x" onfocus="alert(1)` would have put an event handler on an input, on 67 tables. The comment claimed an attribute control that did not exist. | **FIXED** — `e()` applied before `json_encode`, two layers for two contexts, and the comment corrected. Reachable only by a writer of `resources/lang/*.json`, i.e. `isAbleTo('Create Language')`, which is operator-only (`Utility.php:3318`, absent from `$companyNewPermission`, denylisted in `config/superadminaccess.php:9` and enforced by `RolePolicy::isGrantableBy()` on the write path) — so hardening, not a live hole. |
| 2 | `test_the_card_view_still_refuses_a_principal_with_no_store` **could not fail**: it seeded a store-owned order, and `WHERE store_id IS NULL` never matches one. The one test in the file whose subject is F149 was measuring nothing. | **FIXED** — the fixture is now a genuine NULL-store order, with a premise asserting that, and a second test pins the handler's refusal (redirect + "No store is selected") so a regression in that layer alone is still caught. |
| 3 | `hrefNear($grid, 'order')` was satisfied by the **board** link, which carries the filter independently. | **FIXED** — and narrowing to hrefs ending at `/order` was not enough either: measured, three do (breadcrumb, List View, and the filter bar's `?ss_clear=1`, which must *not* carry the filter). The controls are now found by accessible name, which is what tells these icon-only links apart. The Board View link is asserted too, as the control. |
| 4 | The webhook limb still selects `Utility::webhook($module, getCurrentStore())` while the payload is the ORDER's detail, so a platform **operator** can post store B's order detail to store A's merchant-owned URL. | **RECORDED, not fixed** — `docs/security/P01-SECURITY-WORK-QUEUE.md`, new **S3** row. It predates this change, needs a deliberate operator action, and closing it changes *which* store's webhook fires, which is a behaviour decision rather than a guard. Moving the block inside the success branch strictly reduces how often the sink fires. |

The review's own method note is worth repeating: it read `OrderPolicy`, `StoreScope`,
`config/tenancy.php`, `routes/web.php` and yajra's source, and it corrected a stale line citation in
this change's own comment (`Create Language` is at `Utility.php:3318`, not where the comment said).

