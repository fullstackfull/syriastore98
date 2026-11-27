#!/usr/bin/env python3
"""
CTRL-004's mutation run: prove that deleting a feature's data on a disable breaks the test.

`CTRL-004` ("do not delete a feature's data merely because its availability was disabled") was
measured as ALREADY HELD, so there is no red run to start from -- which means the only evidence that
`DisablingAvailabilityNeverDeletesDataTest` is watching anything is to break the invariant on
purpose and watch it fail. Each mutation below is a plausible "tidy-up" a later reader could write.

Run from the repository root:  python3 tests/Support/bin/mutate-ctrl004.py
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from mutate import Mutation, run  # noqa: E402

TEST = 'tests/Feature/Control/DisablingAvailabilityNeverDeletesDataTest.php'

MUTATIONS = [
    # ---------------------------------------------------------------------------------------------
    # the disable primitive
    # ---------------------------------------------------------------------------------------------
    Mutation(
        name='disable() also deletes the merchants settings for the application',
        path='app/Classes/Module.php',
        old="""            $this->addon->is_enable = 0;
            $this->addon->save();""",
        new="""            $this->addon->is_enable = 0;
            $this->addon->save();
            \\Illuminate\\Support\\Facades\\DB::table('settings')->delete();""",
        test_file=TEST,
        filter='test_the_disable_primitive_writes_one_column_and_deletes_nothing',
    ),
    Mutation(
        name='disable() also deletes the catalogue the application decorated',
        path='app/Classes/Module.php',
        old="""            $this->addon->is_enable = 0;
            $this->addon->save();""",
        new="""            $this->addon->is_enable = 0;
            $this->addon->save();
            \\Illuminate\\Support\\Facades\\DB::table('products')->delete();""",
        test_file=TEST,
        filter='test_the_disable_primitive_writes_one_column_and_deletes_nothing',
    ),
    Mutation(
        name='disable() becomes a no-op (the premise half)',
        path='app/Classes/Module.php',
        old="""            $this->addon->is_enable = 0;
            $this->addon->save();""",
        new="""            // mutation: the disable does nothing at all""",
        test_file=TEST,
        filter='test_the_disable_primitive_writes_one_column_and_deletes_nothing',
    ),

    # ---------------------------------------------------------------------------------------------
    # the route that drives it, and the add-on row that switches it back on
    # ---------------------------------------------------------------------------------------------
    Mutation(
        name='switching off through the route deletes the add-on row',
        path='app/Classes/Module.php',
        old="""            $this->addon->is_enable = 0;
            $this->addon->save();""",
        new="""            $this->addon->is_enable = 0;
            $this->addon->save();
            $this->addon->delete();""",
        test_file=TEST,
        filter='test_disabling_an_application_only_flips_the_flag',
    ),
    Mutation(
        name='switching off through the route drops the merchants configuration',
        path='app/Classes/Module.php',
        old="""            $this->addon->is_enable = 0;
            $this->addon->save();""",
        new="""            $this->addon->is_enable = 0;
            $this->addon->save();
            \\Illuminate\\Support\\Facades\\DB::table('settings')
                ->where('name', 'product_review_auto_publish')->delete();""",
        test_file=TEST,
        filter='test_a_disabled_applications_merchant_settings_survive_by_name_and_value',
    ),

    # ---------------------------------------------------------------------------------------------
    # the plan paths
    # ---------------------------------------------------------------------------------------------
    Mutation(
        name='a downgrade deletes the products the smaller plan no longer allows',
        path='app/Models/User.php',
        old="""                    userActiveModule::where('user_id', $this->id)->where('module', $moduleName)->delete();""",
        new="""                    userActiveModule::where('user_id', $this->id)->where('module', $moduleName)->delete();
                    \\Illuminate\\Support\\Facades\\DB::table('products')->where('created_by', $this->id)->delete();""",
        test_file=TEST,
        filter='test_a_plan_downgrade_withdraws_entitlement_without_deleting_content',
    ),
    Mutation(
        name='a downgrade withdraws nothing (the premise half)',
        path='app/Models/User.php',
        old="""                    userActiveModule::where('user_id', $this->id)->where('module', $moduleName)->delete();""",
        new="""                    // mutation: the downgrade withdraws no entitlement at all""",
        test_file=TEST,
        filter='test_a_plan_downgrade_withdraws_entitlement_without_deleting_content',
    ),
    Mutation(
        name='a downgrade past the store limit DELETES the over-limit store',
        path='app/Models/User.php',
        old="""                        $store->is_active = 0;""",
        new="""                        $store->delete();
                        continue;""",
        test_file=TEST,
        filter='test_a_downgrade_past_the_store_limit_deactivates_rather_than_deletes',
    ),
    Mutation(
        name='editing an application out of a plan prunes its holders settings',
        path='app/Http/Controllers/PlanController.php',
        old="""                        $post['modules'] = implode(',', $request->modules);
                        $user_ids = User::where('plan_id', $plan->id)->pluck('id')->toArray();""",
        new="""                        $post['modules'] = implode(',', $request->modules);
                        $user_ids = User::where('plan_id', $plan->id)->pluck('id')->toArray();
                        \\Illuminate\\Support\\Facades\\DB::table('settings')
                            ->whereIn('created_by', $user_ids)->delete();""",
        test_file=TEST,
        filter='test_editing_an_application_out_of_a_plan_does_not_touch_its_holders_content',
    ),

    # ---------------------------------------------------------------------------------------------
    # THE INSTRUMENT ITSELF. If the comparator cannot see a deletion, every row above is theatre.
    # Mutating the test file is the point here, not a shortcut.
    # ---------------------------------------------------------------------------------------------
    Mutation(
        name='the comparator stops reporting shrinking tables',
        path=TEST,
        old="""            if ($now < $rows) {""",
        new="""            if (false) {""",
        test_file=TEST,
        filter='test_the_comparator_reports_a_deletion_it_is_shown',
    ),
    Mutation(
        name='the snapshot returns nothing, so there is nothing to compare',
        path=TEST,
        old="""        ksort($counts);

        return $counts;""",
        new="""        ksort($counts);

        return [];""",
        test_file=TEST,
        filter='test_the_comparator_reports_a_deletion_it_is_shown',
    ),
    Mutation(
        name='the safe set is widened to swallow a real deletion',
        path=TEST,
        old="""        'add_on_managers',
        'user_active_modules',
        'permission_role',
    ];""",
        new="""        'add_on_managers',
        'user_active_modules',
        'permission_role',
        'products',
    ];""",
        test_file=TEST,
        filter='test_the_comparator_reports_a_deletion_it_is_shown',
    ),
]

if __name__ == '__main__':
    sys.exit(run(MUTATIONS))
