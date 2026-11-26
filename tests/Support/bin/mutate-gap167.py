#!/usr/bin/env python3
"""
GAP-167 / P05-19 — MUTATIONS FOR THE ANALYTICS TENANCY CENSUS.

WHY THIS FILE EXISTS. `AnalyticsTenancyCensus`' own docblock describes a mutant that deleted
`->where('store_id', $storeId)` from `MerchantAnalyticsFigures::customers()` and "survived with a
perfect score" -- twice, each time exposing a different defect in the census's builder-chain
widener -- and that is the strongest claim the census makes about itself. An independent review
pointed out that the mutation was committed NOWHERE: `mutate-gap152.py` is a different slice's and
names none of these files. A claim about a mutation that nobody can re-run is prose.

So the two that matter are here, re-runnable:

  1. THE PREDICATE DISAPPEARS from a producer. The census must notice: `parameter` 14 -> 13 and
     `none` 77 -> 78, and the disposition table gains an undeclared shape. This is the mutation that
     survived twice. The first widener took any line CONTAINING the builder variable, so
     `$returning = $buyers === [] ? 0 : Order::query()` handed its own predicate to the site above
     it; the second matched the variable followed by `->` OR `=`, which `$buyers === []` satisfies.

  2. THE OWNERSHIP GATE DISAPPEARS from `ReportController` -- AND THIS ONE SURVIVES, DECLARED AND
     MEASURED at the bottom of this file rather than retired. It is the census's own LIMIT 1 with
     teeth: `order_brand_reports` holds FOUR gate calls in one method, so removing one leaves three
     and the handler-level check still credits every site in it. An independent review predicted it.

  3. THE FULLY-QUALIFIED SPELLING GOES BLIND AGAIN. One character in the lookbehind hid 34 reads,
     31 of them in the file the census claims to read whole, and the premise test could not see it
     because the unqualified spelling satisfied it. The pattern self-test must catch the
     restoration of that character.

  4. A WATCHED MODEL STOPS BEING WATCHED. `ProductBrand` was `Category`'s identical twin, read
     seventeen times with ten bare `find()`s, and entirely uncounted until a review asked. Removing
     it must redden the totals rather than quietly shrink them.

Each mutation is applied alone, its one test is run, and the file is restored -- `mutate.py`
verifies the anchor occurs exactly once and checksums before and after, because CLAUDE.md records a
harness that reported six mutations as surviving when the pattern had never matched at all.

Run: python3 tests/Support/bin/mutate-gap167.py   (from the repository root)
"""

from __future__ import annotations

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from mutate import Mutation, run  # noqa: E402

MERCHANT = 'app/Support/Metrics/MerchantAnalyticsFigures.php'
REPORTS = 'app/Http/Controllers/ReportController.php'
CENSUS = 'tests/Support/Analytics/AnalyticsTenancyCensus.php'

TEST = 'tests/Feature/Tenancy/AnalyticsEloquentTenancyCensusTest.php'

MUTATIONS = [
    Mutation(
        '1. the store predicate disappears from customers() -- SURVIVED TWICE, see the docstring',
        MERCHANT,
        """        $buyers = Order::query()
            ->where('store_id', $storeId)""",
        """        $buyers = Order::query()
            ->where('id', '>', 0)""",
        TEST,
        'test_the_verdict_totals_are_exactly_these',
    ),
    Mutation(
        '2. the same, against the disposition table rather than the totals',
        MERCHANT,
        """        $buyers = Order::query()
            ->where('store_id', $storeId)""",
        """        $buyers = Order::query()
            ->where('id', '>', 0)""",
        TEST,
        'test_every_non_principal_site_shape_is_dispositioned_with_its_count',
    ),
    Mutation(
        '4. the lookbehind excludes a backslash again, hiding every fully-qualified read',
        CENSUS,
        "$pattern = '/(?<![\\w$>])(' . implode('|', self::UNSCOPED_MODELS) . ')::(\\w+)\\s*\\(/';",
        "$pattern = '/(?<![\\w\\\\\\\\$>])(' . implode('|', self::UNSCOPED_MODELS) . ')::(\\w+)\\s*\\(/';",
        TEST,
        'test_the_premise_that_the_pattern_sees_every_spelling_and_refuses_the_rest',
    ),
    Mutation(
        '5. ProductBrand stops being watched (17 reads, 10 of them bare find())',
        CENSUS,
        "'Order', 'Visit', 'Store', 'User', 'Product', 'Category', 'ProductBrand',",
        "'Order', 'Visit', 'Store', 'User', 'Product', 'Category',",
        TEST,
        'test_the_verdict_totals_are_exactly_these',
    ),
    Mutation(
        '6. and the completeness test must notice the same removal',
        CENSUS,
        "'Order', 'Visit', 'Store', 'User', 'Product', 'Category', 'ProductBrand',",
        "'Order', 'Visit', 'Store', 'User', 'Product', 'Category',",
        TEST,
        'test_the_watch_list_covers_every_unscoped_model_these_files_read',
    ),
]


# ===================================================================================================
#  ONE DECLARED SURVIVOR, MEASURED RATHER THAN RETIRED.
# ===================================================================================================
#
# Removing ONE `reportSelectionWithinCurrentStore(ProductBrand::class, ...)` call from
# `ReportController` moves no census number: measured, the ten `ProductBrand::find|ownership_checked`
# sites and the eleven `Category::find|ownership_checked` sites are unchanged.
#
# WHY, and it is the census's own LIMIT 1 with teeth rather than a bug in the mutation.
# `classify()` looks for a gate token in the ENCLOSING FUNCTION, and `order_brand_reports` -- a
# single ~555-line method -- contains FOUR of those calls, one per `chart_data` arm. Removing one
# leaves three in the same body, so the handler-level check still finds a token and still credits
# every site in the method, including the ones in the arm that just lost its gate.
#
# So the verdict `ownership_checked` means "a gate is present in this handler", which is what LIMIT 1
# says it means and NOT "this read goes through one". An independent review predicted this exact
# survivor before it was run. Attributing a gate to an ARM rather than a method needs branch-aware
# scoping, which is a slice of its own; until then the limit is quantified here instead of asserted
# in prose, and the dispositions for those twenty-one sites say the same.
#
# It is listed separately so that running this file reports FIVE CAUGHT AND ONE DECLARED SURVIVOR
# rather than a clean sweep. A battery that prints a perfect score it has not earned is the thing
# CLAUDE.md records as the most dangerous result a harness can produce.

DECLARED_SURVIVORS = [
    Mutation(
        '3. one ownership gate disappears from the brand report',
        REPORTS,
        """        $selectedBrand = $this->reportSelectionWithinCurrentStore(ProductBrand::class, $request->selectedBrand);
        $year = date('Y');
        $monthList = $this->yearMonth();
        $NetSaleTotal = [];
        $NetSalesofbrand = []; // Initialize the variable here""",
        """        $selectedBrand = $request->selectedBrand;
        $year = date('Y');
        $monthList = $this->yearMonth();
        $NetSaleTotal = [];
        $NetSalesofbrand = []; // Initialize the variable here""",
        TEST,
        'test_the_verdict_totals_are_exactly_these',
    ),
]


if __name__ == '__main__':
    code = run(MUTATIONS)

    print()
    print('DECLARED SURVIVOR, re-runnable on request and NOT counted above:')

    for m in DECLARED_SURVIVORS:
        print(f'  -- {m.name}: moves no census number. See the block above this list for why.')

    sys.exit(code)
