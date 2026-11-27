# `P04-P4-02` — Multi-warehouse inventory

**Branch:** `ws/warehouse` · **Date:** 2026-11-27 · **Status:** engineering slice complete; merchant
UI deliberately NOT in this slice; three clauses of §04-B remain owner decisions.

> This document records what was measured, what was built, and what was deliberately not built.
> It does **not** change `MASTER-PLAN-REQUIREMENT-TRACEABILITY.md` or any other `MASTER-PLAN-*`
> document — those dispositions are the integrator's to apply.

---

## 1. The plan's own words

`docs/development/master-plan/MASTER-PRODUCT-PLAN-v1.0-BASE.md` §**04-B — المخزون والمستودعات**
(identical in `-WITH-AMENDMENTS.md`), verbatim:

```
## 04-B — المخزون والمستودعات

القرارات: **INV-001 إلى INV-010**

- مستودع افتراضي واحد مع إمكانية تعدد المستودعات.
- مصدر موحد للكميات الفعلية والمحجوزة والمتاحة.
- حركات مخزنية وجرد وتحويلات وتسويات.
- منع البيع فوق المتاح افتراضياً.
- الحجز الآمن عند تأكيد الطلب، لا عند إضافته للسلة.
- الطلب المسبق الاختياري والصريح.
- اختيار مستودع التنفيذ تلقائياً مع تعديل مصرح به.
- توزيع التنفيذ على عدة مستودعات وشحنات جزئية.
- عدم اعتبار المخزون السالب متاحاً للبيع الفوري.
- استبعاد Batch/Lot وتواريخ الصلاحية حالياً.
```

**The matrix entry quotes none of this.** `MASTER-PLAN-REQUIREMENT-TRACEABILITY.md`'s
`### P04-P4-02` cites only a derived summary — *"04 Commerce | products, MULTI-WAREHOUSE STOCK,
pricing, fulfilment, COD, returns, channels"* — from `CLAUDE.md §4`, and records
`Owner decisions: None. Do not read the plan's silence as approval.` The plan was **not** silent:
ten decision texts exist and `DECISION-ID-REGISTER.md` pairs them as `INV-001`…`INV-010`.
Three of them are this requirement's subject (`INV-001`, `INV-007`, `INV-008`), and the ones that
bound it are `INV-002`, `INV-009`, `INV-010` and `ARCH-002`.

Also load-bearing, and quoted because they are the reason the design is a *decomposition* rather
than a second engine:

| id | verbatim | English |
|---|---|---|
| `ARCH-002` | عدم تكرار محركات المنتجات والمخزون والطلبات والتسعير. | No duplicate product, **inventory**, order or pricing engines |
| `EX-002` | إنشاء محرك تجارة موازٍ لـWorkDo | Creating a commerce engine parallel to WorkDo |
| `EX-004` | Batch/Lot وتتبع الصلاحية ضمن النطاق الحالي | Batch/Lot and expiry tracking, within the current scope |

---

## 2. What was measured first

### 2.1 Stock structures that exist

| thing | where | what it is |
|---|---|---|
| `products.product_stock` | `2023_12_26_092822_create_products_table.php` | `integer`, default 0. **The authoritative sellable total for a simple product.** |
| `products.stock_order_status` | same | `string`, nullable. `'not_allow'` is the only value that refuses an oversell. |
| `products.stock_status` | same | `string`, default `'0'`. The `out_of_stock` marker for the untracked limb. |
| `product_variants.stock` | `2024_01_01_104858_create_product_variants_table.php` | `integer`. The authoritative total per variant. |
| `product_variants.stock_order_status` | same | the per-variant oversell switch. |
| `ProductStockService` | `app/Services/ProductStockService.php` | the only order-path mutator. One conditional `UPDATE … WHERE product_stock >= ?`; `mustNotOversell()` is the real reader of `stock_order_status`. |
| `StockMovement` | `app/Services/StockMovement.php` | the evidence record. **Development and test only** — `available()` is false in production, and every answer is `UNKNOWN` by design. Five reasons, all order/refund events plus `manual`. |
| `ReturnInspection` | `app/Services/ReturnInspection.php` | `GAP-363`'s returned-goods condition gate. The precedent this slice follows for a raw-DB, model-free service. |
| `PreOrder` module | `packages/workdo/PreOrder/` | 4 migrations (`pre_order_settings`, `pre_orders`, `pre_order_histories`, `carts.order_type`). `INV-006`'s subject, not this one's. |
| `BackorderPolicy` | `app/Support/Commerce/BackorderPolicy.php` | `INV-004`/`INV-006` work already on the branch. |

### 2.2 Did any warehouse / location concept exist? **No.**

Searched `warehouse`, `location`, `branch`, `bin`, `depot`, `outlet`, `rack`, `shelf`, `مخزن`,
`مستودع`, `فرع` across `app/`, `database/migrations/`, `routes/`, `resources/`, `config/`, `tests/`
and **all 70 vendored modules** under `packages/workdo/`.

- **`warehouse` in vendored module PHP: 8 files, 0 of them a concept.** Six are prose in
  `LynomiaChat` docblocks and views (*"a warehouse packer and the owner arrived with identical
  rights"*); two are the literal Tabler icon name `ti-building-warehouse` in
  `SidebarCustomization`'s icon picker.
- **Table names matching a location pattern across all 285 host + vendored migrations: three**, and
  none is a warehouse:
  - `store_locators` (`packages/workdo/StoreLocator/`) — **one** address + lat/long + a Maps API key
    per store, for a storefront "find us" widget. No product column, no stock column, no
    multiplicity intended; the module's only `product`/`stock` hits are its own marketplace listing
    seeder and view.
  - `shipping_zones` — a **destination** zone (`country_id`, `state_id`, `shipping_method`), not a
    source location.
  - `stock_movements` — the evidence record above.
- **Column names matching: three**, all false positives — `store_locator_api_key`,
  `store_locator_enabled`, and `utf8mb4_bin` (a collation string).
- **Arabic hits: 8 files, 0 concepts.** `ar.json` translates *"Store"* (the shop) as `مخزن`;
  `BackorderPolicy` quotes this plan section's own heading; the rest is email-template prose.

**So there was no existing engine to extend or duplicate, and `ARCH-002` is not in play as a
conflict with a vendored module.** It *is* in play against `products.product_stock` — see §3.

### 2.3 Where stock is decremented and restored

**Decrement — 5 files, all routing through `ProductStockService`:**

| site | arm |
|---|---|
| `Concerns\AppliesCartStockChanges::decrementStockOrFail()` | the shared trait used by 11 checkout controllers |
| `Concerns\AppliesCartStockChanges` (forced arm) | post-capture, `$allowOversell = true` |
| `Services\PaymentService` (two arms) | the same two shapes |
| `OrderController::store()` | the manual merchant order, `$requireAvailability = true` |
| `Api\DashboardController` (two arms) | the Flutter admin order writer |
| `PosController` | the till |

**Restore — 6 call sites outside `ProductStockService`, matching `GAP-363`'s enumeration:**

| site | kind |
|---|---|
| `Order::performProductReturn()` | **post-delivery return door** |
| `OrderController::restoreReturnedStock()` | **post-delivery return door** |
| `OrderRefundController::RefundStock()` | **post-delivery return door** |
| `Concerns\RestoresCancelledOrderStock` | the cancel door |
| `Services\PaymentService` | compensation for a checkout that could not be written |
| `Concerns\AppliesCartStockChanges` | the same compensation |

---

## 3. The design, and why it is additive rather than parallel

```
Σ(breakdown quantities) === products.product_stock       (or product_variants.stock)
```

`products.product_stock` **remains the single authoritative sellable total**. A row in
`product_warehouse_stock` is an **allocation** — *"this many of that product's units sit at that
named warehouse"*. The DEFAULT warehouse's figure is **not stored**; it is the remainder:

```
default = total − Σ(allocations at this store's named warehouses)
```

`ARCH-002` is why. A table that also stored the default's figure would be a **second source of
truth for the sellable total**: it could disagree with `product_stock`, and something would then
have to reconcile them. Nothing reconciles anything here, because the breakdown sums to the total
**by construction**.

Five consequences, each asserted:

1. **No backfill, no data migration.** A store with no rows has its whole stock at its default
   warehouse by arithmetic — which is `INV-001`'s "one default warehouse" exactly.
2. **A merchant retyping `product_stock` cannot desynchronise anything.** The remainder absorbs it.
   `Inv005ReservationPointTest::DECLARED_STOCK_MUTATORS` already declares every file that mutates a
   stock column — `ProductStockService`, `ProductController`, `Api\DashboardController` and the two
   catalogue importers — and **not one of them needs to know this table exists.** (A figure is
   deliberately not quoted here: a raw grep for `->product_stock =` counts comment lines too, and
   that test is what asserts the set.)
3. **Oversell behaviour is untouched.** `ProductStockService`'s conditional
   `UPDATE … WHERE product_stock >= ?` is still the only sale gate and still names `product_stock`.
   Nothing in this slice is consulted to refuse a sale.
4. **A store with no warehouses behaves byte-for-byte as before** — every store on the platform
   today — and that is asserted *with a positive control beside it*.
5. **The six restore sites are unchanged.** With a derived default, no change is needed to stay
   consistent: a restore raises `product_stock`, which raises the remainder by the same amount. The
   goods land at the store's default warehouse, which is both arithmetically correct and what retail
   does with a return. Pinned, so the boundary is a decision rather than an omission.

### 3.1 No Eloquent model — two reasons that agree

- **Measured blocker.** `ClaudeMdCachableModelClaimTest::test_claude_md_states_the_measured_totals`
  asserts CLAUDE.md contains the literal `"<N> of the <M> models"` where M is
  `count(glob('app/Models/*.php'))`. **Any** new file there reddens a required CI test, and this
  workstream may not edit `CLAUDE.md`. A model in a subdirectory would evade that census, which is
  the one thing this project forbids doing to a ratchet. **Reported to the integrator as a standing
  constraint** — no workstream can add a model to `app/Models/` without a CLAUDE.md edit.
- **On the merits, the same answer.** Most models in `app/Models` are `Cachable`; this table is read
  on the checkout path *immediately after* a stock write, where a cached pre-write row is a real
  oversell. `StockMovement` and `ReturnInspection` are raw services for exactly this reason.

### 3.2 Tenancy

Neither table is in `config/tenancy.php`'s `scoped_models`, so **no global scope would supply a
predicate even if a model existed**, and `null_tenant => 'open'` means none is added when no tenant
resolves. Every one of the service's 19 statements names `store_id` at the call site. The store id
always arrives from an authenticated principal or **from the decremented row itself** — never from
request input, and never from `$evidence`, which is an array a caller composes.

---

## 4. What was built

| file | what |
|---|---|
| `database/migrations/2026_11_27_000100_create_warehouse_inventory_tables.php` | `warehouses` + `product_warehouse_stock`. Additive; `down()` is a documented no-op. |
| `app/Services/WarehouseInventory.php` | the dimension: reader, writer, `INV-009` clamp, `sourceFrom()`'s conditional decrement. Raw `DB::table()`, no model. |
| `app/Support/Commerce/WarehouseSelector.php` | `INV-007`: automatic selection (priority, single-source preferred, greedy fill) + the authorised override with three distinct refusal reasons. |
| `app/Services/ProductStockService.php` | **one new private method and two call sites.** Nothing else in the file changed. |

### 4.1 `INV-*` coverage, exactly

| id | state after this slice |
|---|---|
| `INV-001` one default warehouse, multi-warehouse capability | **BUILT** at the service layer. No merchant UI — see §6. |
| `INV-002` one source for actual / reserved / available | **STILL ONE THIRD BUILT, and the fraction did not move.** *Actual* exists and stays where it is. *Reserved* has no representation anywhere. *Available* is derived (`INV-009`) and stored nowhere, so it cannot drift. |
| `INV-003` movements, stocktakes, transfers, adjustments | **UNCHANGED.** A transfer is now *expressible* and is not *implemented*: no surface, no movement reason, nothing writing a pair of allocations as one event. |
| `INV-004` prevent selling above available by default | untouched (`BackorderPolicy`, a different row). |
| `INV-005` reserve at confirmation, not at cart | untouched; `Inv005ReservationPointTest` still holds. |
| `INV-006` optional explicit pre-order | untouched. |
| `INV-007` automatic warehouse selection + authorised override | **BUILT and tested.** The override has **no UI door** — see §6. |
| `INV-008` split fulfilment across warehouses + partial shipments | **NOT BUILT.** No `shipments` entity exists under any spelling. |
| `INV-009` negative stock is not available for immediate sale | **BUILT** for the new dimension: the real figure is reported and `available` is clamped at 0. |
| `INV-010` Batch/Lot and expiry excluded | **respected.** Nothing here touches either; `Inv010BatchLotExpiryExclusionTest` still holds. |

---

## 5. Evidence

| | |
|---|---|
| `tests/Unit/Commerce/WarehouseDimensionTest.php` | **21 tests.** The invariant, the derived default, `INV-009`'s two directions, the six write-side refusals, cross-tenant isolation in both directions on all four write paths, the default-deletion rule, the tables-absent production case. |
| `tests/Unit/Commerce/WarehouseSelectorTest.php` | **20 tests.** Priority ordering, single-source preference, greedy fill, inactive skipped, the override and its three refusals, the operator limb, and a `Customer` and a `DeliveryBoy` refused without raising. |
| `tests/Feature/Commerce/WarehouseStockSourcingTest.php` | **12 tests.** The byte-for-byte control **and its positive control**, the variant path, the hostile claimed-store case, both oversell directions, the restore boundary. |
| the three together | **53 tests, 219 assertions**, run against the final code. |
| `tests/Support/bin/mutate-p04-p4-02-warehouse.py` | **30 mutations, 27 caught.** Three survivors, each named with its argument. |
| `tests/Support/Security/bin/verify-warehouse-allocation-on-mysql.php` | **18 checks, 0 failures** on MariaDB 10.11.14. The row lock, the re-evaluated predicate, the UNSIGNED column under `strict => false`, the unique index's totality, and the concurrent-save race. |

### 5.1 What the MySQL script found that SQLite could not

- **The row lock is real.** A second sourcing of the last allocated unit blocks with error 1205
  rather than reading around the first; after the commit its predicate is re-evaluated against the
  committed value and it is refused. Ten concurrent sourcings against five units grant exactly five.
- **The UNSIGNED column ERRORS rather than clamping.** An unpredicated under-zero decrement raises
  `SQLSTATE[22003] … 1690 BIGINT UNSIGNED value is out of range` under production's
  `strict => false`, and the stored value is unchanged. **SQLite would have stored `-3`.** So the
  `quantity >= ?` predicate is the control and the column is a second line of defence that raises.
- **The migration header's own argument, measured rather than quoted.** A NULLABLE `variant_id`
  **does** let two rows for one simple product insert (both accepted, 2 rows), which is why the
  column is `NOT NULL DEFAULT 0`.
- **A defect in this slice's own first form.** `allocate()` used `updateOrInsert()`, which is
  SELECT-then-INSERT: both callers read `false` and the second INSERT raised
  `SQLSTATE[23000] 1062 Duplicate entry '3-11-0'`. The unique index contained the damage — one row,
  never two, so no stock figure was wrong — but the losing merchant's save became a 500. Replaced
  with a single `upsert()`; §F now measures **both** shapes, so the fix has something to be a fix of.

### 5.2 Mutation survivors, named

| # | mutation | why it survives |
|---|---|---|
| 12 | `sourceFrom()`'s **READ** loses its store predicate | **An equivalent-mutant pair with 13, and the first run's prediction about it was wrong.** The docstring expected 13 to survive, reasoning that the read refuses first. The run refuted it: with the read's predicate gone, the read finds the foreign row and computes a take, and the **decrement's** surviving predicate then affects zero rows. The decrement's predicate is the effective one. |
| 13 | `sourceFrom()`'s **DECREMENT** loses its store predicate | masked by 12's predicate, symmetrically. |
| 25 | the default may appear in an override plan line | `allocate()` refuses an allocation to the default (`REFUSED_DEFAULT_WAREHOUSE`), so no default can hold a row for the clamp to find. A second statement of a rule whose first statement is upstream. |

**Mutation 30 is what makes 12/13 meaningful:** it voids *both* predicates in one change, and it is
**CAUGHT**. So the pair is load-bearing defence in depth rather than dead code — which a
single-mutation harness could not have said.

### 5.3 A defect found by mutation *planning*, before any mutation ran

`allocate()` permitted allocating to the **default** warehouse. `breakdownFor()` computes the
default as `total − Σ(allocations)`, and that sum would have included the default's own row — so the
default's figure would be reduced by its own allocation and **the breakdown would no longer sum to
the total.** Measured: total 100, 30 allocated to the default, breakdown summed to **70**. The one
invariant the whole design rests on, broken by a merchant typing into the wrong box. Now
`REFUSED_DEFAULT_WAREHOUSE`, pinned in both directions with a control.

Fixing it also exposed **five test fixtures that had become vacuous**, because a single-warehouse
store's only warehouse *is* its default: one of them
(`test_allocating_zero_removes_the_row`) asserted `[] === allocationsFor()` after a *refused* first
allocation and would have passed whatever the code did. All five now assert their premise.

---

## 6. Deliberately not built, and why

| not built | why, and whose it is |
|---|---|
| **Merchant UI** (warehouse CRUD, per-product allocation screen) | **Measured:** `tests/Unit/Ui` holds **165 test files**, of which **128 reference `resources/views`** — so a new merchant screen is judged by most of them, on top of a bilingual `ar`/`en` render and a contrast measurement with `APP_DEBUG` off. That is a slice of its own, and a hurried version that reddens a dozen ratchets is worse than none. **Engineering's next slice, not an owner decision.** Until it exists a merchant cannot create a warehouse through the product, so `INV-001`'s capability is delivered at the service layer only. |
| **`INV-007`'s override has no door** | `$evidence['warehouse_id']` / `['warehouse_override_by']` are threaded through `ProductStockService` and refused correctly by `WarehouseSelector`, but **no shipped caller sets either.** The natural door is a fulfilment / picking surface, which is `ORD-008`'s subject (*Picking وPacking والباركود والملصقات والفواتير*) and does not exist. |
| **`INV-008` split fulfilment + partial shipments** | Needs a `shipments` entity — absent under every spelling, across all 285 migrations. A partial shipment also changes what `orders.delivered_status` means, which is `P04-P4-03`'s **open owner question `H4`**. **Owner decision.** |
| **`INV-003` stocktakes / transfers / adjustments** | A stocktake or an adjustment moves `products.product_stock` — the money-adjacent total. CLAUDE.md §2 reserves a stock business-rule change to the owner. A *transfer* moves only two allocations and would fit here; it is left with its family rather than delivered alone. |
| **`INV-002`'s reserved and available quantities** | A reservation ledger changes **when** stock becomes unbuyable. That is oversell behaviour on existing products, which this slice is forbidden to change and CLAUDE.md §2 reserves to the owner. **Owner decision.** |
| **Returning units to the warehouse they were sourced from** | Needs a per-order sourcing record this slice does not add, and `INV-003`'s transfer capability to correct afterwards. Today a return lands in the default's remainder, which is correct arithmetic and normal retail practice. **Owner question, not a defect.** |

---

## 7. Ratchets moved, each with its argument

| ratchet | movement |
|---|---|
| `InventoryQuantitySourceAndWarehouseTest` | **This is the ratchet designed to fire here** — its own docblock said *"the first commit that starts one of them fails here and has to update the disposition."* Disposition re-measured per `INV-*`; the now-false "there is no warehouse" paragraph is **quoted** before being superseded, so the date is recoverable. `test_the_schema_knows_nothing_of_reservations_warehouses_or_shipments` → `…_of_reservations_or_shipments` (the old name stated a falsehood); warehouse names **declared** rather than the predicate narrowed, plus two new cases asserting the other direction. |
| `ShipCod04DFamilyCensusTest` | bullet 3 is now **half built**: warehouses exist, partial shipments do not. Method renamed to drop the false clause; the two tables subtracted **by name** with the argument that they carry no carrier/tracking/cost/shipment column; a new case asserts they really exist so the subtraction cannot go stale. |
| `ServiceLayerRawQueryInventoryTest` | total 88 → **107** (+19). **Every one names `store_id`: `$scoped` 37 → 56, `$storeLess` UNCHANGED at 51** — which is the split that evidences no new cross-tenant exposure. |
| `RawQueryTenancyInventoryTest` | per-directory `app` 253 → **272**; per-table `products` 12 → **13** and `product_variants` 1 → **2** (the two halves of `totalFor()`, both store-predicated, both unscoped halves unchanged); dependent sums 298 → **317** and 217 → **219**. The other 17 statements name their tables through class constants and join the documented unattributable remainder, exactly as `ReturnInspection`'s three and `ModuleTrust`'s two already do. |
| `BuilderRawQueryVisibilityTest` | its cross-check premise 253 → **272**. Its message said *"Do NOT adjust this to match: find out which census changed"* — the live census changed, the reason is known, and the stale figure inside the message was corrected too. None of the 19 statements is builder-raw, so the blind-spot count does not move. |
| `SecurityLogInventoryTest` / `P01-OBSERVABILITY-INVENTORY.md` | `warning` 284 → **286** (two new lines), and **four existing rows re-pointed** by the 116 lines added above them in `ProductStockService` (measured: `git diff --numstat` is `116 0`) — the §6b cascade, re-pointed with `resync-observability-inventory.py --write`. A dated note records that both new lines report a *bookkeeping* write that did not happen, and that the degraded state they describe is **consistent**. |

---

## 7a. The gate run, and the two failures that were mine

`--testsuite=Feature`, 2026-11-27: **14 140 passed, 14 failed, 1 skipped** (3445 s). The declared
outcome set has 0 accepted failures, so all 14 are reported. **Two were mine and are fixed; twelve
are pre-existing on `int/checkpoint`.**

### Mine, and both fixed

1. **`SecurityLogInventoryTest::test_the_observability_inventory_matches_the_source` — I broke my own
   re-point.** I ran `resync-observability-inventory.py --write`, and then made one more edit to
   `ProductStockService` (six comment lines, the `filter_var` strictness change). Those six lines
   shifted three log citations below them and the inventory went stale again. **The §6b cascade bit
   twice in one slice, the second time because the re-point came before the last edit rather than
   after it.** Re-synced (3 rows re-pointed) and verified.
2. **`StoredFilePathSafetyTest::test_a_shared_file_at_the_repository_root_…` — an artefact of my
   worktree, not of the change.** Its premise asserts
   `packages/workdo/ImportExport/src/resources/assets/img/img.png` exists, and this worktree was
   created with a **sparse checkout** excluding `packages/workdo/*/src/[Rr]esources/assets/`, because
   the disk was at 99 % / 1.2 GB and a full checkout had already failed with ENOSPC. The exclusion
   was chosen as inert for the Unit suite and **it was not inert for the Feature suite** — exactly
   the "a test fails for a reason that is not the code" trap. Sparse checkout **disabled** once disk
   reached 12 GB; the tree is now complete at 2.8 GB.

Both verified together afterwards: `SecurityLogInventoryTest` + `StoredFilePathSafetyTest` =
**94 passed, 0 failed**.

### The other twelve, and how each was shown not to be mine

Every one was re-run **in isolation** on this worktree and still fails, and **not one references
`ProductStockService`, `WarehouseInventory`, `WarehouseSelector` or the observability inventory**
(measured: 0 hits per file). Each fails on a different subject:

| test | why it fails, and whose it is |
|---|---|
| `HistoricalOrderImportSalesExclusionTest::test_the_metric_layer_is_not_the_only_place_order_money_is_summed` | the `HomeController` line drift in §8.1 |
| `HostUtilityCensusPopulationTest::test_the_old_population_still_reproduces_the_registers_own_figure` | counts **routes** behind `PlatformOperatorOnly`: `GAP-179` recorded 19, the tree now has **26**. This slice adds **no route at all**. |
| `OrderActionPermissionCensusTest::test_the_census_covers_every_order_route` | `POST order-return-inspection` is uncensused — **`GAP-363`'s own route**, landed 2026-11-26 |
| `OperatorPlatformNavigationTest` (2) | `kyc.queue`, `platform.approvals`, `platform.incidents`, `platform.operations` are not in `SuperAdminMenuListener` (`ADM-001`) |
| `PlanCouponRankingFreshnessTest` (3) | coupon-ranking card premises (`BETACOUPON` / `ALPHACOUPON` not in the lead) |
| `OperatorDashboardCardLabelsTest` (2) | the same coupon card, and its tie-break |
| `StorelessMerchantDashboardTest::test_the_refusal_ends_the_session_itself` | a refusal returns 200 where a redirect is expected |
| `AuditAttributionTest::test_no_audit_reader_exists_without_an_authorization_gate` | an ungated audit reader |

**The full suite was NOT re-run after these two fixes**, so the honest figure for this branch is
"14 140 passed / 14 failed, of which 2 are fixed and verified green in isolation and 12 are
pre-existing". The integrator should expect **12 failures, all pre-existing**, on a clean re-run of
a complete checkout.

---

## 8. Found, not mine to fix

1. **`HistoricalOrderImportSalesExclusionTest::test_the_metric_layer_is_not_the_only_place_order_money_is_summed`
   is RED on `int/checkpoint` before this branch exists, and it is a stale absolute line citation —
   CLAUDE.md §3's own trap.**

   `AGGREGATION_SITES` (lines 106-107 of that test) declares
   `'app/Http/Controllers/HomeController.php:2123' => 'THE MERCHANT DASHBOARD gross-sale series'`
   and `':2138' => '…gross-sale total'`. **The two statements those labels describe are now at 2185
   and 2200** — read and confirmed:

   ```
   2185:  $GrossSaleTotal[] = Order::whereDate('order_date', $date)->where('store_id', getCurrentStore())->get()->sum('final_price');
   2200:  $TotalgrossSale += Order::whereDate('order_date', $date)->where('store_id', getCurrentStore())->get()->sum('final_price');
   ```

   So the content is unchanged and only the numbers drifted, by 62 lines, from
   `8cb288150 GAP-231` / the `ws/p6-adm` merge `d9bd97589`. **`HomeController.php` is untouched by
   this branch** (`git diff --name-only` is empty for it) and `git diff --numstat` shows this branch
   changes seven files, none of them that one.

   **The remedy is two numbers**, `2123 -> 2185` and `2138 -> 2200`, applied highest-first per
   CLAUDE.md §6b. **It is deliberately NOT applied here**: it is another workstream's inventory, and
   editing it on this branch would hand them a merge conflict in the file they are already fixing.
   The Feature suite's declared outcome set has **0 accepted failures**, so this reddens the gate
   for the integrator regardless of this branch.
2. **`RawQueryTenancyInventoryTest`'s per-table census is blind to a table named through a class
   constant.** It keys on a literal in `DB::table('…')`, so `StockMovement`'s `self::TABLE`,
   `ReturnInspection`'s and now `WarehouseInventory`'s `self::WAREHOUSES`/`self::ALLOCATIONS` land
   only in the entry-point total. Pre-existing and **already documented in that file's own notes** —
   recorded here because this slice exercises it, not as a new finding.
3. **`ServiceLayerRawQueryInventoryTest` does not reach `app/Services/` subdirectories**
   (`glob('app/Services/*.php')`), which its own notes already record for
   `app/Services/Commerce/PriceExplanation.php`. Whether it should is that file's owner's call.
4. **No workstream can add a file to `app/Models/`** without editing `CLAUDE.md`, because
   `ClaudeMdCachableModelClaimTest` asserts the file count against a sentence in it. That is working
   as designed, but it is a constraint worth stating: it forces a raw-DB service for any new table,
   which here was also the right answer, and might not always be.
