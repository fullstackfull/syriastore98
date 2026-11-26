#!/usr/bin/env python3
"""
GAP-179 / P06-06 slice 3 -- PROVE EVERY RATCHET BITES.

This project's TDD rule is stricter than the vendored skill's: after a fix, revert it and confirm
the test fails again. Twelve mutations, AT LEAST ONE PER REPAIRED GROUP, plus two that attack the
test file's own claims rather than the views -- because a ratchet whose premise is false and a
census whose exemption list has gone stale both read exactly like a finished repair.

    group                                    findings closed   mutations
    /plan-coupon/1 grid tools                      16          1, 2, 3, 4, 4b
    /plan-coupon/1 order-reference cell             2          5, 6
    the two "Generate" controls                     4          7, 8, 9
    /plan-coupon edit's "Update"                    2          10, 11

Run from the repository root:

    python3 tests/Support/bin/mutate-gap179-slice3.py

`mutate.py` restores each file's mtime and runs `view:clear` after any Blade change, because a
stale compiled view serves the MUTATION after the revert -- measured 2026-11-21, in both
directions.
"""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from mutate import Mutation, run  # noqa: E402

TEST = 'tests/Unit/Ui/PlanCouponScreenLegibilityTest.php'
SHOW = 'resources/views/plan-coupon/show.blade.php'
CREATE = 'resources/views/plan-coupon/create.blade.php'
EDIT = 'resources/views/plan-coupon/edit.blade.php'
COMPONENT = 'resources/views/components/datatable.blade.php'
DATATABLE = 'app/DataTables/PlanCouponDetailDataTable.php'

MUTATIONS = [
    # ---- group: the /plan-coupon/1 grid tools, 16 findings -------------------------------------
    Mutation(
        name='1. show.blade.php loses the grid scope again (the whole defect, restored)',
        path=SHOW,
        old='card-body table-border-style ss-dt-card-body',
        new='card-body table-border-style',
        test_file=TEST,
        filter='test_the_coupon_detail_grid_carries_the_design_systems_grid_scope',
    ),
    Mutation(
        # The SAME revert, seen by the census instead -- which is what stops "declared
        # hand-rolled" becoming a way of not fixing something.
        name='2. the same revert, seen by the CENSUS rather than by the named test',
        path=SHOW,
        old='card-body table-border-style ss-dt-card-body',
        new='card-body table-border-style',
        test_file=TEST,
        filter='test_no_view_hand_rolls_a_datatable_except_the_ones_declared_with_a_reason',
    ),
    Mutation(
        # THE WITHDRAWN REPAIR, taken anyway. This is the one a later reader is most likely to
        # make as an obvious cleanup, and it would switch on bulkDelete() on an operator screen.
        name='3. show.blade.php is "tidied" into <x-datatable>, switching on bulk delete',
        path=SHOW,
        old="{{ $dataTable->table(['width' => '100%']) }}",
        new='<x-datatable :dataTable="$dataTable" />',
        test_file=TEST,
        filter='test_the_coupon_detail_screen_deliberately_does_not_render_the_shared_component',
    ),
    Mutation(
        # A STALE / WRONG EXEMPTION. The census is asserted in BOTH directions on purpose: a view
        # left in the declared list that no longer belongs is how a census stops covering the
        # thing it names.
        #
        # THE FIRST VERSION OF THIS MUTATION SURVIVED, and it was the mutation's fault: it added a
        # constant the test never reads, so nothing changed. `mutate.py`'s first guard is about
        # exactly this -- a change that changes nothing proves nothing -- and it only checks that
        # the ANCHOR matched, not that the anchor mattered. Recorded rather than quietly replaced.
        name='4. the census gains an exemption for a view that hand-rolls nothing',
        path=TEST,
        old="        'resources/views/country/tab.blade.php'\n"
            "            => 'unrouted: POST /country-tab has no caller, so its findings are not in the product',",
        new="        'resources/views/country/tab.blade.php'\n"
            "            => 'unrouted: POST /country-tab has no caller, so its findings are not in the product',\n"
            "        'resources/views/plan-coupon/index.blade.php' => 'unrouted: not really',",
        test_file=TEST,
        filter='test_no_view_hand_rolls_a_datatable_except_the_ones_declared_with_a_reason',
    ),
    Mutation(
        # AND THE WEAKNESS THAT MUTATION FOUND, attacked directly: emptying the reachable list
        # made the repair assertion a no-op while the set comparison still passed, so the whole
        # check could be switched off in one line. The two lists are now tied together.
        name='4b. the reachable list is emptied, switching the repair assertion off',
        path=TEST,
        old="    private const HAND_ROLLED_AND_REACHABLE = [\n"
            "        'resources/views/plan-coupon/show.blade.php',\n"
            "    ];",
        new="    private const HAND_ROLLED_AND_REACHABLE = [\n"
            "    ];",
        test_file=TEST,
        filter='test_no_view_hand_rolls_a_datatable_except_the_ones_declared_with_a_reason',
    ),

    # ---- group: the /plan-coupon/1 order-reference cell, 2 findings ----------------------------
    Mutation(
        name="5. the DataTable builds the host primary fill in PHP again (2.58:1 dark)",
        path=DATATABLE,
        old="return '<span class=\"ss-code\">#' . e($row->order) . '</span>';",
        new="return '<div class=\"btn btn-primary btn-sm text-sm\">"
            "<span class=\"btn-inner--text\">#' . e($row->order) . '</span></div>';",
        test_file=TEST,
        filter='test_the_order_reference_cell_is_an_identifier_and_not_a_host_primary_fill',
    ),
    Mutation(
        # THE ESCAPE, attacked on its own. `order_id` is in `rawColumns`, so a repair that dropped
        # `e()` while fixing a colour would trade a contrast finding for a stored-XSS sink.
        name="6. the repaired cell drops e() -- a colour fix that opens a raw sink",
        path=DATATABLE,
        old="return '<span class=\"ss-code\">#' . e($row->order) . '</span>';",
        new="return '<span class=\"ss-code\">#' . $row->order . '</span>';",
        test_file=TEST,
        filter='test_the_order_reference_cell_is_an_identifier_and_not_a_host_primary_fill',
    ),

    # ---- group: the two "Generate" controls, 4 findings ----------------------------------------
    Mutation(
        name='7. create.blade.php takes the host btn-outline-primary back (3.07:1 dark)',
        path=CREATE,
        old='<button class="ss-btn ss-btn--secondary" type="button" id="code-generate">',
        new='<button class="btn btn-outline-primary" type="button" id="code-generate">',
        test_file=TEST,
        filter='test_neither_coupon_fragment_keeps_the_hosts_outline_primary_generate_control',
    ),
    Mutation(
        # ONE OF A BYTE-SIMILAR PAIR. This is the exact shape that let `plans/edit.blade.php` keep
        # slice 1's defect, and the shape three of slice 2's ten mutations exposed in its tests:
        # an assertion satisfied by finding the repaired shape SOMEWHERE in the file set.
        name='8. only EDIT reverts -- the half-repair that survived twice before',
        path=EDIT,
        old='<button class="ss-btn ss-btn--secondary" type="button" id="code-generate">',
        new='<button class="btn btn-outline-primary" type="button" id="code-generate">',
        test_file=TEST,
        filter='test_neither_coupon_fragment_keeps_the_hosts_outline_primary_generate_control',
    ),
    Mutation(
        # THE INERT-REPAIR TRAP, slice 2's own finding. Without the light scope the design-system
        # class is still ON the element and still reads as a finished repair, while its DARK token
        # values resolve onto the white modal sheet.
        name='9. the light scope goes, leaving the class inert on a white sheet',
        path=CREATE,
        old='<div data-ss-theme="light">',
        new='<div>',
        test_file=TEST,
        filter='test_both_coupon_dialog_fragments_scope_their_tokens_to_the_white_sheet',
    ),

    # ---- group: /plan-coupon edit's "Update", 2 findings ---------------------------------------
    Mutation(
        name="10. edit's submit takes the host's dark primary fill back (2.58:1)",
        path=EDIT,
        old='class="ss-btn ss-btn--primary mx-1"',
        new='class="btn btn-badge btn-primary mx-1"',
        test_file=TEST,
        filter='test_the_coupon_edit_submit_no_longer_takes_the_hosts_dark_primary_fill',
    ),
    Mutation(
        name='11. the same revert, seen by the PAIR comparison instead',
        path=EDIT,
        old='class="ss-btn ss-btn--primary mx-1"',
        new='class="btn btn-badge btn-primary mx-1"',
        test_file=TEST,
        filter='test_the_two_fragments_carry_the_same_control_classes_so_neither_can_be_repaired_alone',
    ),
]

if __name__ == '__main__':
    sys.exit(run(MUTATIONS))
