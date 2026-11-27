#!/usr/bin/env python3
"""
NAV-004 -- PROVE EVERY RATCHET BITES.

This project's TDD rule is stricter than the vendored skill's: after a fix, revert it and confirm
the test fails again. The skill's rule catches a test that never ran; ours catches a test that
passes for the wrong reason, which is the failure mode that has actually bitten here.

TWENTY-ONE MUTATIONS. The two the brief names explicitly are 1-4 (REMOVE EACH ENTRY) and 5 (ADD A
PERMISSION NAME) -- and 5 is the one that has already survived once, against the FIRST draft of
`OperatorPlatformNavigationTest`: `Menu::permits()` DROPS an entry it refuses, so an assertion that
iterates what is PRESENT cannot see what is ABSENT. That is why the test records the declarations
at the seam with a `Menu` subclass whose `add()` keeps everything, and 5 is run against the
declarations case BY NAME to show it now bites there and not only next door.

    group                                             mutations
    the four navigation entries, removed               1, 2, 3, 4
    a permission name added to an entry                5, 6
    the entry flattened out of the group               7
    an entry pointing at the wrong route               8
    the icon ratchet                                   9, 10, 11
    the label / Arabic ratchet                        12, 13, 14, 15
    the dashboard panel                               16, 17
    the bilingual + refusal feature arms              18, 19, 20, 21

Run from the repository root:

    python3 tests/Support/bin/mutate-nav004.py

`mutate.py` asserts each anchor occurs exactly once, rejects a run whose `--filter` matched no
test, and restores each file's mtime plus `view:clear` after a Blade change -- a stale compiled
view serves the MUTATION after the revert, measured 2026-11-21 in both directions.
"""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from mutate import Mutation, run  # noqa: E402

LISTENER = 'app/Listeners/SuperAdminMenuListener.php'
DASHBOARD = 'resources/views/superadmin/dashboard.blade.php'
LANG_AR = 'resources/lang/ar.json'
STYLESHEET = 'public/assets/fonts/tabler-icons.min.css'

NAV = 'tests/Feature/Saas/OperatorPlatformNavigationTest.php'
ICONS = 'tests/Unit/Ui/NavigationIconGlyphsResolveTest.php'
LABELS = 'tests/Unit/Saas/OperatorPlatformGroupLabelsTest.php'
PANEL = 'tests/Feature/Saas/OperatorDashboardPlatformLinksTest.php'
BILINGUAL = 'tests/Feature/Saas/OperatorPlatformScreensAreBilingualTest.php'


def entry(name, route, icon, order, title):
    """The exact text of one child entry, for a removal mutation."""
    return (
        "        $menu->add([\n"
        "            'title' => __('%s'),\n"
        "            'icon' => '%s',\n"
        "            'name' => '%s',\n"
        "            'parent' => 'platform-command',\n"
        "            'order' => %d,\n"
        "            'ignore_if' => [],\n"
        "            'depend_on' => [],\n"
        "            'route' => '%s',\n"
        "            'module' => $module,\n"
        "            'permission' => ''\n"
        "        ]);\n" % (title, icon, name, order, route)
    )


MUTATIONS = [
    # ---- REMOVE EACH ENTRY. The brief's first named mutation, one per screen. ------------------
    Mutation(
        name='1. the verification-queue entry is removed from the sidebar',
        path=LISTENER,
        old=entry('platform-verification', 'kyc.queue', 'certificate', 20, 'Verification queue'),
        new='',
        test_file=NAV,
        filter='test_every_platform_wide_operator_screen_is_in_the_operators_navigation',
    ),
    Mutation(
        name='2. the approval-queue entry is removed from the sidebar',
        path=LISTENER,
        old=entry('platform-approvals', 'platform.approvals.index', 'shield-check', 30,
                  'Dangerous action approvals'),
        new='',
        test_file=NAV,
        filter='test_every_platform_wide_operator_screen_is_in_the_operators_navigation',
    ),
    Mutation(
        name='3. the operations entry is removed from the sidebar',
        path=LISTENER,
        old=entry('platform-operations', 'platform.operations', 'database', 70,
                  'Platform operations'),
        new='',
        test_file=NAV,
        filter='test_every_platform_wide_operator_screen_is_in_the_operators_navigation',
    ),
    Mutation(
        name='4. the incident-log entry is removed from the sidebar',
        path=LISTENER,
        old=entry('platform-incidents', 'platform.incidents.index', 'alert-triangle', 80,
                  'Platform incidents'),
        new='',
        test_file=NAV,
        filter='test_every_platform_wide_operator_screen_is_in_the_operators_navigation',
    ),

    # ---- ADD A PERMISSION NAME. The brief's second named mutation, and the one that SURVIVED ---
    # the first draft of the navigation test: a refused entry is DROPPED by `Menu::permits()`, so
    # a case that iterates the surviving menu cannot see it. Run against the DECLARATIONS case by
    # name, which is the case written for exactly this.
    Mutation(
        name='5. a permission name is added to the verification-queue entry (declarations case)',
        path=LISTENER,
        old="            'route' => 'kyc.queue',\n"
            "            'module' => $module,\n"
            "            'permission' => ''",
        new="            'route' => 'kyc.queue',\n"
            "            'module' => $module,\n"
            "            'permission' => 'Manage Verification'",
        test_file=NAV,
        filter='test_the_platform_entries_carry_no_permission_name',
    ),
    Mutation(
        name='6. a permission name is added to the approvals entry (declarations case)',
        path=LISTENER,
        old="            'route' => 'platform.approvals.index',\n"
            "            'module' => $module,\n"
            "            'permission' => ''",
        new="            'route' => 'platform.approvals.index',\n"
            "            'module' => $module,\n"
            "            'permission' => 'Manage Approvals'",
        test_file=NAV,
        filter='test_the_platform_entries_carry_no_permission_name',
    ),

    # ---- THE ENTRY IS THERE BUT NOT IN THE GROUP ----------------------------------------------
    # `'parent' => null` keeps every other assertion green: the route is still named, still
    # permission-less, still absent from a merchant's menu. What changes is that the entry becomes
    # a flat top-level item instead of a child, and only the RENDERED-SIDEBAR case, scoped to the
    # group's own block, can see it. CLAUDE.md's measured example of the same shape: a mutation
    # dropping one component's 44px target survived because another component had the same rule.
    Mutation(
        name='7. the operations entry is flattened out of the group (parent => null)',
        path=LISTENER,
        old="            'name' => 'platform-operations',\n"
            "            'parent' => 'platform-command',",
        new="            'name' => 'platform-operations',\n"
            "            'parent' => null,",
        test_file=NAV,
        filter='test_the_group_is_in_the_rendered_sidebar_and_not_only_in_the_menu_object',
    ),

    # ---- AN ENTRY POINTING AT A ROUTE THE ROUTER DOES NOT KNOW --------------------------------
    # `menuTree()` calls `route()` while building the sidebar, so this throws inside the layout on
    # EVERY operator screen at once, not only the wrong one.
    Mutation(
        name='8. the incident-log entry names a route that does not exist',
        path=LISTENER,
        old="            'route' => 'platform.incidents.index',",
        new="            'route' => 'platform.incidents.list',",
        test_file=NAV,
        filter='test_every_operator_navigation_entry_names_a_registered_route',
    ),

    # ---- THE ICON RATCHET ---------------------------------------------------------------------
    Mutation(
        name='9. the group header goes back to the undefined `layout-dashboard` glyph',
        path=LISTENER,
        old="            'icon' => 'dashboard',",
        new="            'icon' => 'layout-dashboard',",
        test_file=ICONS,
        filter='test_every_navigation_icon_names_a_glyph_the_stylesheet_defines',
    ),
    Mutation(
        name='10. a NEW entry names an icon that does not exist',
        path=LISTENER,
        old="            'icon' => 'alert-triangle',",
        new="            'icon' => 'alert-triangle-filled',",
        test_file=ICONS,
        filter='test_every_navigation_icon_names_a_glyph_the_stylesheet_defines',
    ),
    Mutation(
        name='11. a top-level entry loses its icon entirely',
        path=LISTENER,
        old="            'title' => __('Across the platform'),",
        new="            'title' => __('Across the platform'),\n            'icon' => '',",
        test_file=ICONS,
        filter='test_every_top_level_entry_carries_an_icon',
    ),

    # ---- THE LABEL / ARABIC RATCHET -----------------------------------------------------------
    Mutation(
        name='12. an entry title is no longer passed through __()',
        path=LISTENER,
        old="            'title' => __('Dangerous action approvals'),",
        new="            'title' => 'Dangerous action approvals',",
        test_file=LABELS,
        filter='test_premise_the_census_finds_the_group_and_the_panel',
    ),
    Mutation(
        name='13. an entry title is an undeclared string',
        path=LISTENER,
        old="            'title' => __('Verification queue'),",
        new="            'title' => __('Verification review queue'),",
        test_file=LABELS,
        filter='test_every_group_label_and_panel_sentence_is_declared_in_english_and_arabic',
    ),
    Mutation(
        name='14. the Arabic for a label is the English sentence copied across',
        path=LANG_AR,
        old='"Platform incidents": "حوادث المنصة"',
        new='"Platform incidents": "Platform incidents"',
        test_file=LABELS,
        filter='test_every_group_label_and_panel_sentence_really_carries_arabic',
    ),
    Mutation(
        name='15. two entries in the group share one Arabic label',
        path=LANG_AR,
        old='"Platform operations": "تشغيل المنصة"',
        new='"Platform operations": "موارد المنصة"',
        test_file=LABELS,
        filter='test_the_group_labels_are_distinct_in_both_languages',
    ),

    # ---- THE DASHBOARD PANEL ------------------------------------------------------------------
    Mutation(
        name='16. the verification-queue link is removed from the dashboard panel',
        path=DASHBOARD,
        old='<a class="ss-link" href="{{ route(\'kyc.queue\') }}">{{ __(\'Verification queue\') }}</a>',
        new='',
        test_file=PANEL,
        filter='test_the_operator_dashboard_links_to_every_platform_screen_in_the_panel',
    ),
    Mutation(
        name='17. the approvals link loses the sentence that tells it from verification',
        path=DASHBOARD,
        old="{{ __('Platform actions one operator has requested and a different operator must "
            "approve before they take effect. Nobody can approve their own request.') }}",
        new="{{ __('Awaiting review.') }}",
        test_file=PANEL,
        filter='test_each_link_says_what_its_screen_measures',
    ),

    # ---- THE BILINGUAL AND REFUSAL ARMS -------------------------------------------------------
    # A view-level mutation, so `mutate.py`'s `view:clear` is load-bearing on these two.
    Mutation(
        name='18. the operations screen prints an UNDECLARED sentence (English on the Arabic page)',
        path='resources/views/superadmin/platform_operations.blade.php',
        old="queues and integrations report about themselves, read at the moment this page was "
            "built.",
        new="queues and integrations report about themselves, read at the moment this page was "
            "rendered.",
        test_file=BILINGUAL,
        filter='test_the_screen_renders_its_own_content_in_arabic_and_right_to_left',
    ),
    Mutation(
        name='19. the incident log hard-codes a left margin (physical, not logical)',
        path='resources/views/superadmin/platform_incidents/index.blade.php',
        old="""<h2 class="h5 mb-1">{{ __('What this log is for') }}</h2>""",
        new="""<h2 class="h5 mb-1 ml-3">{{ __('What this log is for') }}</h2>""",
        test_file=BILINGUAL,
        filter='test_the_four_views_use_logical_and_not_physical_spacing',
    ),
]

# =================================================================================================
# THE REFUSAL ARM NEEDS A DIFFERENT KIND OF MUTATION, AND FINDING THAT OUT IS THE POINT.
# =================================================================================================
#
# Mutations 20 and 21 were first written as single edits -- take `PlatformOperatorOnly` off the
# approvals route, take it off the KYC queue route -- and BOTH SURVIVED. That is not a weak test:
# it is TWO INDEPENDENT GUARDS, measured rather than assumed. Each screen refuses a merchant twice
# over:
#
#   the route         `->middleware(App\Http\Middleware\PlatformOperatorOnly::class)`
#   the handler       `OperatorApprovalController::refuseAnyoneButTheOperator()` and
#                     `KycVerificationController::queue()`'s `KycAccess::permitsAnyStore()`
#
# So no single-guard mutation can be caught by an assertion that asks "is the merchant refused",
# and reporting one as a survivor would read as a hole where there are two walls.
#
# THIS IS ALSO THE ONLY WAY TO SEE THE HANDLER CHECK AT ALL. CLAUDE.md records `GAP-307`: a
# handler-level check behind an `abort()`ing middleware is INVISIBLE to an HTTP test, because the
# middleware answers first and the handler is never reached -- `PlanController::planDisable`
# admitted a `Customer` typed `super admin` behind a gate whose HTTP tests were green. Removing the
# middleware and watching the refusal survive is the measurement that shows the handler's check is
# real and reachable, which reading it cannot establish.
#
# So the refusal evidence is three readings per screen, and each is asserted in the direction it
# is expected to go:
#
#   A. middleware removed  -> STILL REFUSED   (the handler holds)
#   B. handler check removed -> STILL REFUSED (the middleware holds)
#   C. both removed        -> CAUGHT          (the test really is what stands behind them)
#
# Written out here rather than pushed into `mutate.py`, which deliberately has one semantics
# ("a survivor is a failure") and should keep it.

ROUTES = 'routes/web.php'

APPROVALS_ROUTE = (
    "    Route::get('platform/approvals', [\\App\\Http\\Controllers\\OperatorApprovalController::class, 'index'])\n"
    "        ->middleware(\\App\\Http\\Middleware\\PlatformOperatorOnly::class)\n"
    "        ->name('platform.approvals.index');"
)
APPROVALS_ROUTE_UNGUARDED = (
    "    Route::get('platform/approvals', [\\App\\Http\\Controllers\\OperatorApprovalController::class, 'index'])\n"
    "        ->name('platform.approvals.index');"
)

KYC_ROUTE = (
    "  Route::get('platform/verification', [\\App\\Http\\Controllers\\KycVerificationController::class, 'queue'])\n"
    "      ->middleware(\\App\\Http\\Middleware\\PlatformOperatorOnly::class)\n"
    "      ->name('kyc.queue');"
)
KYC_ROUTE_UNGUARDED = (
    "  Route::get('platform/verification', [\\App\\Http\\Controllers\\KycVerificationController::class, 'queue'])\n"
    "      ->name('kyc.queue');"
)

APPROVALS_HANDLER = 'app/Http/Controllers/OperatorApprovalController.php'
APPROVALS_GUARD = (
    "        if (! $user instanceof User || ! UserPolicy::isOperator($user)) {\n"
    "            abort(403, __('Permission denied.'));"
)
APPROVALS_GUARD_OFF = (
    "        if (false) {\n"
    "            abort(403, __('Permission denied.'));"
)

KYC_HANDLER = 'app/Http/Controllers/KycVerificationController.php'
KYC_GUARD = (
    "        if (! KycAccess::permitsAnyStore($user)) {\n"
    "            abort(403);\n"
    "        }\n"
    "\n"
    "        $query = KycVerification::query()"
)
KYC_GUARD_OFF = (
    "        if (false) {\n"
    "            abort(403);\n"
    "        }\n"
    "\n"
    "        $query = KycVerification::query()"
)

# (label, [(path, old, new), ...], expect_caught)
LAYERED = [
    ('20A. approvals: route middleware removed, handler left alone',
     [(ROUTES, APPROVALS_ROUTE, APPROVALS_ROUTE_UNGUARDED)], False),
    ('20B. approvals: handler check removed, route middleware left alone',
     [(APPROVALS_HANDLER, APPROVALS_GUARD, APPROVALS_GUARD_OFF)], False),
    ('20C. approvals: BOTH guards removed',
     [(ROUTES, APPROVALS_ROUTE, APPROVALS_ROUTE_UNGUARDED),
      (APPROVALS_HANDLER, APPROVALS_GUARD, APPROVALS_GUARD_OFF)], True),
    ('21A. KYC queue: route middleware removed, handler left alone',
     [(ROUTES, KYC_ROUTE, KYC_ROUTE_UNGUARDED)], False),
    ('21B. KYC queue: handler check removed, route middleware left alone',
     [(KYC_HANDLER, KYC_GUARD, KYC_GUARD_OFF)], False),
    ('21C. KYC queue: BOTH guards removed',
     [(ROUTES, KYC_ROUTE, KYC_ROUTE_UNGUARDED),
      (KYC_HANDLER, KYC_GUARD, KYC_GUARD_OFF)], True),
]


def run_layered(cases, test_file, test_filter, timeout=900):
    """Apply several edits at once and assert the run went the way the case says it should."""
    import os as _os
    import shutil as _shutil
    import subprocess as _subprocess

    wrong = []

    for label, edits, expect_caught in cases:
        backups = []
        applied = True

        for path, old, new in edits:
            src = open(path).read()
            n = src.count(old)

            if n != 1:
                print(f'  !! {label}: anchor in {path} appears {n} times -- NOT APPLIED')
                wrong.append(f'{label} (anchor x{n})')
                applied = False
                break

            times = (_os.stat(path).st_atime, _os.stat(path).st_mtime)
            backup = path + '.layered-backup'
            _shutil.copy2(path, backup)
            backups.append((path, backup, times))

            with open(path, 'w') as handle:
                handle.write(src.replace(old, new, 1))

        try:
            if not applied:
                continue

            result = _subprocess.run(
                ['php', 'artisan', 'test', test_file, '--filter', test_filter],
                capture_output=True, text=True, timeout=timeout,
            )
        finally:
            for path, backup, times in backups:
                _shutil.move(backup, path)
                _os.utime(path, times)

            _subprocess.run(['php', 'artisan', 'view:clear'],
                            capture_output=True, text=True, timeout=120)

        summary = [line for line in result.stdout.splitlines() if 'Tests:' in line]

        if not summary or 'No tests' in result.stdout:
            print(f'  !! {label}: the filter matched NO TEST, so this run proves nothing')
            wrong.append(f'{label} (filter matched nothing)')
            continue

        caught = result.returncode != 0
        verdict = 'CAUGHT  ' if caught else 'SURVIVED'
        wanted = 'CAUGHT' if expect_caught else 'SURVIVED'
        ok = caught == expect_caught

        print(f'  {verdict} {label}  ->  {summary[0].strip()}   [expected {wanted}: '
              f'{"as expected" if ok else "WRONG"}]')

        if not ok:
            wrong.append(f'{label} (expected {wanted})')

    print()
    print('LAYERED: all as expected' if not wrong else 'LAYERED UNEXPECTED: ' + '; '.join(wrong))

    return 1 if wrong else 0


if __name__ == '__main__':
    code = run(MUTATIONS)
    print()
    print('=== the refusal arm: two independent guards, three readings each ===')
    code |= run_layered(LAYERED, BILINGUAL, 'test_a_merchant_is_refused_each_screen')
    sys.exit(code)
