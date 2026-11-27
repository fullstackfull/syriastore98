#!/usr/bin/env python3
"""
`P04-P4-02` — MUTATIONS FOR THE MULTI-WAREHOUSE INVENTORY DIMENSION.

Twenty-nine one-change mutations, each applied alone, run, and reverted. They attack the claims the
slice makes, because a control that cannot fail is not a control. Grouped by the claim they attack:

  THE INVARIANT (1-2).      `Σ(breakdown) === products.product_stock`, because the default
                            warehouse's figure is DERIVED. (1) gives the default 0 instead of the
                            remainder. (2) treats the default as a named warehouse, which is the
                            "store the default too" design the whole class argues against.
  `INV-009` (3-4).          A negative figure is not available for immediate sale. (3) drops the
                            clamp. (4) is the subtler one: `abs()` is still non-negative, so a test
                            keyed on "not negative" would miss it and one keyed on the VALUE does
                            not.
  WRITE-SIDE RULES (5-10).  The five refusals and the set-not-accumulate rule. (6) drops the
                            `unset` that excludes a warehouse's own current figure from the
                            beyond-total sum, which turns an edit into an accumulation.
  SOURCING (11-13).         The clamp that keeps the invariant, and the two store predicates.
  DEFAULTS (14-15).         One default per store, and the first warehouse becoming it unasked.
  ORDERING (16).            `INV-007` selects by priority, not by insertion order.
  THE TOTAL (17-18).        `INV-002`: the authoritative figure is read under a store predicate.
  THE OVERRIDE (19-21,26).  The permission, the ownership-before-permission ORDER, the reported
                            basis, and the clamp.
  SELECTION (22-25).        Inactive skipped, single-source preferred, greedy fill, default never
                            in a plan line.
  THE ORDER PATH (27-29).   The two call sites, and the one that matters most: (29) takes the store
                            from `$evidence` instead of from the decremented ROW, which is
                            CLAUDE.md §1's "a tenant predicate built from caller-supplied input is
                            not a tenant check" in its exact shape.

MUTATIONS 12, 13 AND 25 SURVIVE. 25 was predicted; **12 AND 13 ARE AN EQUIVALENT-MUTANT PAIR AND
THE FIRST RUN'S PREDICTION ABOUT THEM WAS WRONG, WHICH IS RECORDED HERE RATHER THAN TIDIED AWAY.**
The docstring originally expected 13 alone to survive, on the reasoning that `sourceFrom()`'s READ
refuses a foreign row first and the DECREMENT's predicate is therefore redundant. The run refuted
it in the opposite direction: with the READ's predicate removed (12) the read finds the foreign
row, computes a take — and the DECREMENT's surviving predicate then affects zero rows, so the
method still answers 0 and the test still passes. **The DECREMENT's predicate is the effective one
and the READ's is the redundant one.** Each mutation alone is masked by the other, so NEITHER is
individually observable by any test, and a single-mutation harness cannot say whether the pair
protects anything at all.

MUTATION 30 IS WHAT ANSWERS THAT. It makes BOTH predicates vacuous in one change, by overwriting
the caller's `$storeId` with whatever store actually owns the row. If the pair protects, 30 is
caught; if it does not, 30 survives and the cross-tenant test is vacuous. It is CAUGHT — so the
guard is real and 12/13 are a genuine defence-in-depth pair rather than dead code.

Mutations are kept when they survive, with their argument, rather than deleted — deleting a
mutation because it survives is how a mutation report becomes a formality. See the bottom of this
docstring.

NOT MUTATED, AND WHY: the `available()` guard in `ProductStockService::sourceFromWarehouse()`
cannot be attacked under the suite, because `RefreshDatabase` always creates both tables and the
guard is only observable when they are absent — which is production. That path is driven instead by
`WarehouseDimensionTest::test_with_the_tables_absent_every_entry_point_answers_empty_and_nothing_throws`,
which drops the tables inside the test.

EXPECTED SURVIVORS, with the argument for each:

  12 + 13. The two store predicates in `sourceFrom()`, which mask each other — see above. Neither
      is individually observable; mutation 30 proves the pair is load-bearing. Both are kept: the
      READ's predicate costs one indexed comparison and removes a reasoning dependency on the
      decrement's staying where it is, and the measured lesson is that the one a reader would call
      redundant was not the one that actually refuses.
  25. The `is_default` exclusion in `overrideLines()`. `allocate()` refuses an allocation to the
      default warehouse (`REFUSED_DEFAULT_WAREHOUSE`), so no default can ever hold a row for the
      clamp to find — the override of a default therefore plans nothing either way. Same posture:
      a second statement of a rule whose first statement is upstream.

Run: `python3 tests/Support/bin/mutate-p04-p4-02-warehouse.py` from the repository root.
"""

from __future__ import annotations

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from mutate import Mutation, run  # noqa: E402

SERVICE = 'app/Services/WarehouseInventory.php'
SELECTOR = 'app/Support/Commerce/WarehouseSelector.php'
STOCK = 'app/Services/ProductStockService.php'

DIMENSION = 'tests/Unit/Commerce/WarehouseDimensionTest.php'
SELECT = 'tests/Unit/Commerce/WarehouseSelectorTest.php'
SOURCING = 'tests/Feature/Commerce/WarehouseStockSourcingTest.php'

MUTATIONS = [
    # ---- THE INVARIANT ---------------------------------------------------------------------- #
    Mutation(
        '01 the default gets 0 instead of the remainder',
        SERVICE, '? $total - $allocated', '? 0',
        DIMENSION, 'test_the_breakdown_always_sums_to_the_authoritative_total',
    ),
    Mutation(
        '02 the default is read as a named warehouse (the "store it too" design)',
        SERVICE, "$quantity = $warehouse['is_default']", '$quantity = false',
        DIMENSION, 'test_the_breakdown_always_sums_to_the_authoritative_total',
    ),

    # ---- INV-009 ---------------------------------------------------------------------------- #
    Mutation(
        '03 INV-009 clamp dropped: a negative figure is offered for sale',
        SERVICE, 'return max(0, $quantity);', 'return $quantity;',
        DIMENSION, 'test_a_negative_remainder_is_reported_but_is_not_available_for_sale',
    ),
    Mutation(
        '04 INV-009 clamp becomes abs(): still non-negative, and wrong',
        SERVICE, 'return max(0, $quantity);', 'return abs($quantity);',
        DIMENSION, 'test_a_negative_remainder_is_reported_but_is_not_available_for_sale',
    ),

    # ---- WRITE-SIDE RULES ------------------------------------------------------------------- #
    Mutation(
        '05 allocate() may exceed the authoritative total',
        SERVICE, 'if (array_sum($allocations) + $quantity > $total) {', 'if (false) {',
        DIMENSION, 'test_an_allocation_beyond_the_total_is_refused_with_its_reason',
    ),
    Mutation(
        '06 allocate() accumulates instead of setting (the unset is dropped)',
        SERVICE, 'unset($allocations[$warehouseId]);', '/* mutation: unset dropped */',
        DIMENSION, 'test_allocating_again_replaces_that_warehouses_figure',
    ),
    Mutation(
        "07 allocate() accepts another store's warehouse",
        SERVICE,
        'if (! self::ownsWarehouse($storeId, $warehouseId)) {\n'
        '            return self::REFUSED_FOREIGN_WAREHOUSE;',
        'if (false) {\n'
        '            return self::REFUSED_FOREIGN_WAREHOUSE;',
        DIMENSION, 'test_one_stores_dimension_is_invisible_to_another',
    ),
    Mutation(
        '08 allocate() accepts the DEFAULT warehouse (breaks the invariant)',
        SERVICE, 'if (self::defaultWarehouseId($storeId) === $warehouseId) {', 'if (false) {',
        DIMENSION, 'test_allocating_to_the_default_warehouse_is_refused',
    ),
    Mutation(
        "09 allocate() accepts another store's product",
        SERVICE,
        'if ($total === null) {\n            return self::REFUSED_FOREIGN_PRODUCT;',
        'if (false) {\n            return self::REFUSED_FOREIGN_PRODUCT;',
        DIMENSION, 'test_one_stores_dimension_is_invisible_to_another',
    ),
    Mutation(
        '10 allocate() accepts a negative figure (mints units at a place)',
        SERVICE, 'if ($quantity < 0) {', 'if (false) {',
        DIMENSION, 'test_a_negative_allocation_is_refused',
    ),

    # ---- SOURCING --------------------------------------------------------------------------- #
    Mutation(
        '11 sourceFrom() clamp dropped: takes more than the warehouse holds',
        SERVICE, '$take = min($quantity, (int) $held);', '$take = $quantity;',
        DIMENSION, 'test_sourcing_takes_units_off_a_named_allocation_and_clamps',
    ),
    Mutation(
        "12 sourceFrom()'s READ loses its store predicate",
        SERVICE,
        "$held = DB::table(self::ALLOCATIONS)\n                ->where('store_id', $storeId)",
        "$held = DB::table(self::ALLOCATIONS)\n                ->where('store_id', '>', 0)",
        DIMENSION, 'test_sourcing_cannot_reach_another_stores_allocation',
    ),
    Mutation(
        "13 sourceFrom()'s DECREMENT loses its store predicate [EXPECTED SURVIVOR]",
        SERVICE,
        "$affected = DB::table(self::ALLOCATIONS)\n                ->where('store_id', $storeId)",
        "$affected = DB::table(self::ALLOCATIONS)\n                ->where('store_id', '>', 0)",
        DIMENSION, 'test_sourcing_cannot_reach_another_stores_allocation',
    ),

    # ---- DEFAULTS --------------------------------------------------------------------------- #
    Mutation(
        '14 createWarehouse() no longer demotes the previous default: a store gets two',
        SERVICE,
        'return (int) DB::transaction(function () use ($storeId, $attributes, $wantsDefault) {\n'
        '            if ($wantsDefault) {\n'
        '                self::demoteDefaults($storeId);\n'
        '            }',
        'return (int) DB::transaction(function () use ($storeId, $attributes, $wantsDefault) {',
        DIMENSION, 'test_exactly_one_warehouse_is_the_default_for_a_store',
    ),
    Mutation(
        '15 the first warehouse is no longer the default unasked',
        SERVICE,
        "$wantsDefault = $isFirst || ! empty($attributes['is_default']);",
        "$wantsDefault = ! empty($attributes['is_default']);",
        DIMENSION, 'test_the_first_warehouse_a_store_creates_is_its_default_unasked',
    ),

    # ---- ORDERING --------------------------------------------------------------------------- #
    Mutation(
        '16 warehousesFor() orders by id instead of priority (INV-007 selection order)',
        SERVICE,
        "->orderBy('priority')\n            ->orderBy('id')",
        "->orderBy('id')\n            ->orderBy('id')",
        SELECT, 'test_priority_and_not_insertion_order_decides',
    ),

    # ---- THE AUTHORITATIVE TOTAL ------------------------------------------------------------ #
    Mutation(
        "17 totalFor() loses the store predicate on products",
        SERVICE,
        "$total = DB::table('products')\n            ->where('store_id', $storeId)",
        "$total = DB::table('products')\n            ->where('id', '>', 0)",
        DIMENSION, 'test_one_stores_dimension_is_invisible_to_another',
    ),
    Mutation(
        '18 totalFor() loses the store predicate on product_variants',
        SERVICE,
        "$total = DB::table('product_variants')\n                ->where('store_id', $storeId)",
        "$total = DB::table('product_variants')\n                ->where('id', '>', 0)",
        SOURCING, 'test_a_variant_with_no_store_sources_nothing_and_could_hold_nothing',
    ),

    # ---- THE OVERRIDE ----------------------------------------------------------------------- #
    Mutation(
        '19 the override permission is no longer required',
        SELECTOR,
        'if (! MerchantPermissions::allows($user, self::OVERRIDE_PERMISSION, $storeId)) {',
        'if (false) {',
        SELECT, 'test_an_unauthorised_principal_is_refused_and_the_automatic_plan_stands',
    ),
    Mutation(
        '20 ownership is no longer asked BEFORE permission (the refusal reason changes)',
        SELECTOR,
        'if (! WarehouseInventory::ownsWarehouse($storeId, $overrideId)) {',
        'if (false) {',
        SELECT, 'test_another_stores_warehouse_is_refused_even_for_an_authorised_principal',
    ),
    Mutation(
        '21 a refused override is reported as the automatic plan',
        SELECTOR, "'basis' => self::OVERRIDE_REFUSED,", "'basis' => self::AUTOMATIC,",
        SELECT, 'test_an_unauthorised_principal_is_refused_and_the_automatic_plan_stands',
    ),
    Mutation(
        '26 an honoured override is no longer clamped at what the warehouse holds',
        SELECTOR,
        '$take = min($quantity, WarehouseInventory::availableUnits($held));',
        '$take = $quantity;',
        SELECT, 'test_an_honoured_override_is_clamped_at_what_that_warehouse_holds',
    ),

    # ---- SELECTION -------------------------------------------------------------------------- #
    Mutation(
        '22 an inactive warehouse is selected for fulfilment',
        SELECTOR,
        "if ($warehouse['is_default'] || ! $warehouse['is_active']) {",
        "if ($warehouse['is_default']) {",
        SELECT, 'test_an_inactive_warehouse_is_skipped_and_keeps_its_units',
    ),
    Mutation(
        '23 the single-source preference is dropped (always splits)',
        SELECTOR, 'if ($held >= $quantity) {', 'if (false) {',
        SELECT, 'test_a_warehouse_that_cannot_cover_the_line_is_passed_over',
    ),
    Mutation(
        '24 the greedy fill stops after one line',
        SELECTOR, '$remaining -= $take;', '$remaining = 0;',
        SELECT, 'test_when_no_single_warehouse_covers_the_line_the_plan_fills_greedily',
    ),
    Mutation(
        '25 the default may appear in an override plan line [EXPECTED SURVIVOR]',
        SELECTOR,
        "if ($warehouse === null || $warehouse['is_default']) {",
        'if ($warehouse === null) {',
        SELECT, 'test_an_override_to_the_default_warehouse_plans_nothing',
    ),

    # ---- THE ORDER PATH --------------------------------------------------------------------- #
    Mutation(
        '27 the product decrement no longer sources from a warehouse',
        STOCK,
        '$this->sourceFromWarehouse($product, false, $quantity, $evidence);',
        '/* mutation: sourcing call removed */',
        SOURCING, 'test_the_control_a_store_with_a_warehouse_really_does_move_an_allocation',
    ),
    Mutation(
        '28 the variant decrement no longer sources from a warehouse',
        STOCK,
        '$this->sourceFromWarehouse($variant, true, $quantity, $evidence);',
        '/* mutation: sourcing call removed */',
        SOURCING, 'test_a_variant_decrement_sources_the_variants_own_allocation',
    ),
    Mutation(
        '30 BOTH store predicates in sourceFrom() become vacuous at once',
        SERVICE,
        '$variantId = max(0, $variantId);\n\n        try {',
        "$variantId = max(0, $variantId);\n"
        "        $storeId = (int) DB::table(self::ALLOCATIONS)\n"
        "            ->where('warehouse_id', $warehouseId)->value('store_id');\n\n        try {",
        DIMENSION, 'test_sourcing_cannot_reach_another_stores_allocation',
    ),

    Mutation(
        '29 the store is taken from $evidence instead of from the decremented ROW',
        STOCK,
        '$storeId = (int) ($model->store_id ?? 0);',
        "$storeId = (int) ($evidence['store_id'] ?? $model->store_id ?? 0);",
        SOURCING, 'test_a_claimed_store_cannot_reach_another_tenants_allocation',
    ),
]

if __name__ == '__main__':
    print(__doc__)
    sys.exit(run(MUTATIONS))
