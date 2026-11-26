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

import contextlib
import io
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
# It is listed separately so that running this file reports the caught mutations AND one DECLARED
# SURVIVOR rather than a clean sweep. A battery that prints a perfect score it has not earned is the
# thing CLAUDE.md records as the most dangerous result a harness can produce.
#
# AND IT IS RUN, NOT ASSERTED. `run_declared()` below applies it, measures that the totals test
# still passes with the gate call deleted, and fails if it is ever CAUGHT -- because then the limit
# has been fixed and the DECLARATION is the stale thing. "Re-runnable on request" made the weakest
# part of this evidence conversational; running it every time makes LIMIT 1 self-documenting.

    Mutation(
        '7. OrderCouponDetail stops being watched (the coupon-discount source)',
        CENSUS,
        "'OrderCouponDetail', 'OrderTaxDetail', 'OrderBillingDetail', 'Tax', 'Shipping',",
        "'OrderTaxDetail', 'OrderBillingDetail', 'Tax', 'Shipping',",
        TEST,
        'test_the_verdict_totals_are_exactly_these',
    ),
    Mutation(
        '8. a structural table is declared to have no store_id when its table does (false acquittal)',
        CENSUS,
        "'OrderBillingDetail' => 'order_billing_details',",
        "'Coupon' => 'coupons',",
        TEST,
        'test_the_structural_list_is_measured_against_the_live_schema_in_both_directions',
    ),
    Mutation(
        '9. the inline ownership gate stops being recognised (DeleteStore cascade)',
        CENSUS,
        "&& $this->resolvesToThePrincipal($owner[1], $bindings)) {",
        "&& false) {",
        TEST,
        'test_the_verdict_totals_are_exactly_these',
    ),
    Mutation(
        '10. the whole-assignment capture reverts to one line',
        CENSUS,
        "for ($i = $index + 1; ! str_contains($expression, ';') && $i < min($index + 12, count($numbered)); $i++) {",
        "for ($i = $index + 1; false && $i < min($index + 12, count($numbered)); $i++) {",
        TEST,
        'test_the_verdict_totals_are_exactly_these',
    ),
]

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


def run_declared(mutations: list[Mutation]) -> int:
    """
    RUN each declared survivor and assert it STILL survives. Returns a process exit code.

    WHY IT RUNS RATHER THAN PRINTS. The first version of this file listed the survivor and said it
    was "re-runnable on request", which made the weakest part of the evidence conversational: a
    reader had to take on trust that deleting one gate call still moves no number. Now the claim is
    executed every time the battery runs, so LIMIT 1 is self-documenting.

    IT REUSES `run()` RATHER THAN REIMPLEMENTING THE APPLY AND RESTORE. That function is the audited
    path: it refuses an anchor that does not occur exactly once, preserves the mtime so a reverted
    Blade cannot leave a compiled view newer than its source, restores from a copy in a `finally`,
    and refuses a filter that matched no test. CLAUDE.md records a harness that reported six
    mutations as surviving when its pattern had never matched, which is why none of that is rewritten
    here -- only the words and the EXPECTATION are inverted, by capturing `run()`'s own output.

    THE EXPECTATION IS INVERTED IN BOTH DIRECTIONS, which is the whole point of declaring a survivor
    rather than deleting the mutation:

      * STILL SURVIVES  -> the declaration holds. Reported, and NOT counted as a failure.
      * NOW CAUGHT      -> the limit has been FIXED, so the DECLARATION is stale and this fails.
                           That is the `.github/known-test-outcomes.json` rule applied to a
                           mutation: an undeclared failure and an unexpectedly-passing declared
                           failure both fail the gate. Good news still has to be acted on.
      * DID NOT APPLY   -> proves nothing and fails. A declared survivor whose anchor has moved is
                           the "reported as surviving when it never ran" trap wearing a label.
    """
    stale: list[str] = []
    broken: list[str] = []

    for m in mutations:
        captured = io.StringIO()

        with contextlib.redirect_stdout(captured):
            run([m])

        body = captured.getvalue()

        if '!!' in body:
            print(f'  BROKEN   {m.name}')
            print('           the mutation did not apply, so the declaration proves nothing:')
            print('           ' + ' '.join(line.strip() for line in body.splitlines() if '!!' in line))
            broken.append(m.name)

            continue

        measured = next((line.split('->')[-1].strip() for line in body.splitlines() if '->' in line), '(no summary)')

        if 'CAUGHT' in body:
            print(f'  NO LONGER A SURVIVOR  {m.name}  ->  {measured}')
            stale.append(m.name)

            continue

        print(f'  DECLARED SURVIVOR  {m.name}')
        print(f'           moves no census number -- see LIMIT 1 in AnalyticsTenancyCensus. Measured: {measured}')

    print()

    if broken:
        print('DECLARED SURVIVORS THAT DID NOT APPLY: ' + '; '.join(broken))
        print('A declaration whose anchor has moved proves nothing. Re-anchor it or delete it.')

    if stale:
        print('STALE DECLARATIONS -- THESE ARE NOW CAUGHT: ' + '; '.join(stale))
        print('That is good news and it still fails: the limit they declare has been fixed, so the')
        print('declaration is a claim about code that no longer behaves that way. Move the mutation')
        print('into MUTATIONS, delete it from DECLARED_SURVIVORS, and re-point LIMIT 1 and the two')
        print('`ownership_checked` dispositions that cite it.')

    if not broken and not stale:
        print('EVERY DECLARED SURVIVOR STILL SURVIVES, for the reason declared.')

    return 1 if (broken or stale) else 0


if __name__ == '__main__':
    code = run(MUTATIONS)

    print()
    print('DECLARED SURVIVORS -- RUN, not asserted, and NOT counted above:')
    declared = run_declared(DECLARED_SURVIVORS)

    sys.exit(code or declared)
