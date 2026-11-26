#!/usr/bin/env python3
"""
`IAM-001` — MUTATIONS FOR THE OPERATOR-GRANULARITY MEASUREMENT.

This file's tests MEASURE a gap rather than implement a feature, which makes them unusually easy to
pass for the wrong reason: "a merchant was refused" is also what a broken fixture, an empty
permission table and a renamed route produce. Five mutations, each applied alone:

  1.  THE GATE STOPS BEING BINARY in the permissive direction: it also admits a permission holder.
      The all-permissions merchant then reaches every operator route, which is what `IAM-001` being
      implemented would look like -- so this proves the test would NOTICE that rather than
      silently blessing it.
  2.  THE GATE STOPS BEING BINARY in the restrictive direction: the operator-first ordering is
      reversed so `isAbleTo()` is asked first. CLAUDE.md names this a LOCKOUT rather than a
      tightening and records `FinancePermission` having done it once; direction two must catch it.
  3.  THE FIXTURE GRANTS NOTHING. If "holds every permission" quietly became "holds none", the
      refusal in direction one would say nothing about permissions at all -- the skill's lesson 9,
      a test that cannot tell two readings apart. The fixture's own premise assertions must fail.
  4.  THE RESERVED LIST CHANGES SIZE. A premise attack: the count is quoted in prose in two files,
      and asserting it is the only thing that keeps the prose honest.
  5.  THE STALE PROSE COMES BACK. Restoring the "15 names" sentence this slice corrected must
      redden the comment census -- otherwise that census is decoration and the correction could
      silently un-correct itself.

Run: `python3 tests/Support/bin/mutate-iam001.py` from the repository root.
"""

from __future__ import annotations

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from mutate import Mutation, run  # noqa: E402

GATE = 'app/Http/Middleware/PlatformOperatorOnly.php'
CONFIG = 'config/superadminaccess.php'
ANALYTICS = 'app/Http/Controllers/PlatformAnalyticsController.php'
TEST = 'tests/Feature/Security/OperatorStaffPermissionGranularityTest.php'

MUTATIONS = [
    Mutation(
        name='1. the gate also admits a permission holder (IAM-001 half-built)',
        path=GATE,
        old="        if ($user instanceof \\App\\Models\\User && \\App\\Policies\\UserPolicy::isOperator($user)) {",
        new="        if ($user instanceof \\App\\Models\\User && (\\App\\Policies\\UserPolicy::isOperator($user) || $user->isAbleTo('Create Plan'))) {",
        test_file=TEST,
        filter='test_a_user_holding_every_permission_is_still_refused_every_operator_route',
    ),
    Mutation(
        name='2. the operator-first ordering is reversed (the lockout)',
        path=GATE,
        old="        if ($user instanceof \\App\\Models\\User && \\App\\Policies\\UserPolicy::isOperator($user)) {",
        new="        if ($user instanceof \\App\\Models\\User && \\App\\Policies\\UserPolicy::isOperator($user) && $user->isAbleTo('Create Plan')) {",
        test_file=TEST,
        filter='test_an_operator_holding_no_permission_at_all_is_admitted_everywhere',
    ),
    Mutation(
        name='3. the fixture grants no permission at all (vacuity check)',
        path=TEST,
        old="""        foreach ($names as $name) {
            $this->grantPermission($merchant, $name);
        }""",
        new="""        foreach (array_slice($names, 0, 0) as $name) {
            $this->grantPermission($merchant, $name);
        }""",
        test_file=TEST,
        filter='test_a_user_holding_every_permission_is_still_refused_every_operator_route',
    ),
    Mutation(
        name='4. the reserved list gains a name (premise attack on the quoted count)',
        path=CONFIG,
        old="    'Delete Language'\n];",
        new="    'Delete Language',\n    'Manage Plan'\n];",
        test_file=TEST,
        filter='test_the_operator_reserved_name_list_is_still_thirteen_names',
    ),
    Mutation(
        name='5. the stale "15 names" prose returns',
        path=ANALYTICS,
        old=" * `config/superadminaccess.php` does hold 13 names a merchant may NOT grant themselves, filtered",
        new=" * `config/superadminaccess.php` does hold 15 names a merchant may NOT grant themselves, filtered",
        test_file=TEST,
        filter='test_no_comment_in_app_states_a_different_count_for_the_reserved_list',
    ),
]

if __name__ == '__main__':
    sys.exit(run(MUTATIONS))
