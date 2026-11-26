#!/usr/bin/env python3
"""
The operator feature-control screen's mutation run.

CLAUDE.md records two traps this list exists to prove the tests avoid:

 1. A HANDLER-LEVEL CHECK BEHIND AN `abort()`ING MIDDLEWARE IS INVISIBLE TO AN HTTP TEST. The
    route answers 403 whether or not the handler checks anything, so the handler's guard is driven
    with the route gate removed -- and the mutations below have to confirm that the test really
    sees the guard rather than the gate.
 2. ASSERT THE LANDED PATH, NOT THE STATUS. Every write redirects with a 302 on success AND on
    refusal, so a status assertion cannot tell them apart; the tests read the stored row.

Run from the repository root:  python3 tests/Support/bin/mutate-ctrl-operator-screen.py
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from mutate import Mutation, run  # noqa: E402

SCREEN = 'tests/Feature/Control/PlatformFeatureControlScreenTest.php'
OVERVIEW_TEST = 'tests/Feature/Control/FeatureControlOverviewTest.php'
LANG = 'tests/Unit/Control/FeatureControlMessagesAreDeclaredTest.php'

CONTROLLER = 'app/Http/Controllers/PlatformFeatureControlController.php'
VIEW = 'resources/views/platform/feature_control.blade.php'
OVERVIEW = 'app/Support/Control/FeatureControlOverview.php'
ROUTES = 'routes/web.php'

MUTATIONS = [
    # ---------------------------------------------------------------------------------------------
    # the handler's own guard -- the half the route gate hides
    # ---------------------------------------------------------------------------------------------
    Mutation(
        name='the handler stops checking the principal\'s CLASS, so a Customer typed super admin gets in',
        path=CONTROLLER,
        old="""        if (! $user instanceof User || ! UserPolicy::isOperator($user)) {
            abort(403);
        }""",
        new="""        if (! \\App\\Policies\\UserPolicy::isOperator($user)) {
            abort(403);
        }""",
        test_file=SCREEN,
        filter='test_a_customer_typed_super_admin_is_refused_by_the_handler',
    ),
    Mutation(
        name='the handler\'s guard is removed entirely',
        path=CONTROLLER,
        old="""        if (! $user instanceof User || ! UserPolicy::isOperator($user)) {
            abort(403);
        }""",
        new="""        // mutation: the handler relies on the route gate alone""",
        test_file=SCREEN,
        filter='test_a_customer_typed_super_admin_is_refused_by_the_handler',
    ),
    Mutation(
        name='the handler refuses everybody, so the refusal tests pass on a dead screen',
        path=CONTROLLER,
        old="""        if (! $user instanceof User || ! UserPolicy::isOperator($user)) {
            abort(403);
        }""",
        new="""        abort(403);""",
        test_file=SCREEN,
        filter='test_control_the_operator_is_still_admitted_without_the_route_gate',
    ),
    Mutation(
        name='the read route loses the operator gate',
        path=ROUTES,
        old="""    Route::get('platform/feature-control', [\\App\\Http\\Controllers\\PlatformFeatureControlController::class, 'index'])
        ->middleware(\\App\\Http\\Middleware\\PlatformOperatorOnly::class)
        ->name('platform.feature_control.index');""",
        new="""    Route::get('platform/feature-control', [\\App\\Http\\Controllers\\PlatformFeatureControlController::class, 'index'])
        ->name('platform.feature_control.index');""",
        test_file=SCREEN,
        filter='test_all_four_routes_resolve_to_this_controller_and_carry_the_operator_gate',
    ),
    Mutation(
        name='a write route becomes a GET, so a crawler can switch a feature off',
        path=ROUTES,
        old="""    Route::post('platform/feature-control/state', [\\App\\Http\\Controllers\\PlatformFeatureControlController::class, 'state'])""",
        new="""    Route::get('platform/feature-control/state', [\\App\\Http\\Controllers\\PlatformFeatureControlController::class, 'state'])""",
        test_file=SCREEN,
        filter='test_the_three_writes_cannot_be_driven_with_a_get',
    ),

    # ---------------------------------------------------------------------------------------------
    # what the screen refuses
    # ---------------------------------------------------------------------------------------------
    Mutation(
        name='a transition on an undeclared key invents the flag',
        path=CONTROLLER,
        old="""        if (! $this->isDeclared($key)) {
            return redirect()->route('platform.feature_control.index')
                ->with('error', __('No feature carries that key. Declare it before releasing it.'));
        }

        if ($action === 'disable') {""",
        new="""        if ($action === 'disable') {""",
        test_file=SCREEN,
        filter='test_releasing_a_key_nobody_declared_is_refused_and_creates_nothing',
    ),
    Mutation(
        name='the key\'s character set is no longer validated',
        path=CONTROLLER,
        old="""            'key' => 'required|string|max:64|regex:/^[A-Za-z0-9._-]+$/',
            'label' => 'nullable|string|max:120',""",
        new="""            'key' => 'required|string|max:64',
            'label' => 'nullable|string|max:120',""",
        test_file=SCREEN,
        filter='test_a_key_with_a_space_is_refused',
    ),
    Mutation(
        name='the rollout percentage is no longer range-validated',
        path=CONTROLLER,
        old="""            'percentage' => 'nullable|integer|between:0,100',""",
        new="""            'percentage' => 'nullable|integer',""",
        test_file=SCREEN,
        filter='test_a_percentage_outside_the_range_is_refused_by_validation',
    ),
    Mutation(
        name='the targeting axis is no longer validated against the decision\'s three',
        path=CONTROLLER,
        old="""            'axis' => 'required|string|in:' . implode(',', PlatformFeatureFlagTarget::TARGET_TYPES),""",
        new="""            'axis' => 'required|string',""",
        test_file=SCREEN,
        filter='test_an_axis_the_decision_does_not_name_is_refused',
    ),
    Mutation(
        name='a pilot membership for store 0 is accepted and sits there inert',
        path=CONTROLLER,
        old="""            'store_id' => 'required|integer|min:1',""",
        new="""            'store_id' => 'required|integer',""",
        test_file=SCREEN,
        filter='test_a_pilot_membership_for_store_zero_is_refused',
    ),

    # ---------------------------------------------------------------------------------------------
    # the capability
    # ---------------------------------------------------------------------------------------------
    Mutation(
        name='a newly declared feature arrives switched ON',
        path='app/Support/Control/FeatureControl.php',
        old="""            'state' => PlatformFeatureFlag::STATE_OFF,
            'rollout_percentage' => 0,
        ]);""",
        new="""            'state' => PlatformFeatureFlag::STATE_ON,
            'rollout_percentage' => 0,
        ]);""",
        test_file=SCREEN,
        filter='test_the_operator_can_declare_a_feature_and_it_starts_switched_off',
    ),
    Mutation(
        name='re-declaring a live feature reports that it is switched off',
        path=CONTROLLER,
        old="""            $alreadyDeclared = $this->isDeclared($key);""",
        new="""            $alreadyDeclared = false;""",
        test_file=SCREEN,
        filter='test_redeclaring_a_live_feature_does_not_claim_it_is_switched_off',
    ),
    Mutation(
        name='an unlabelled feature is given a placeholder nobody can identify',
        path=CONTROLLER,
        old="""                trim((string) ($validated['label'] ?? '')) !== '' ? (string) $validated['label'] : $key,""",
        new="""                trim((string) ($validated['label'] ?? '')) !== '' ? (string) $validated['label'] : 'Untitled',""",
        test_file=SCREEN,
        filter='test_declaring_without_a_label_uses_the_key',
    ),
    Mutation(
        name='the pilot form adds nobody',
        path=CONTROLLER,
        old="""        FeatureControl::addToPilotGroup($group, $storeId, $actor);""",
        new="""        // mutation: the pilot form does nothing""",
        test_file=SCREEN,
        filter='test_the_operator_can_put_a_store_in_a_pilot_group_and_take_it_out',
    ),
    Mutation(
        name='the withdraw form is ignored and the target stays',
        path=CONTROLLER,
        old="""            $removed = FeatureControl::withdraw($key, (string) $validated['axis'], (string) $validated['value'], $actor);""",
        new="""            $removed = true;""",
        test_file=SCREEN,
        filter='test_the_operator_can_run_a_whole_rollout_and_switch_it_off',
    ),

    # ---------------------------------------------------------------------------------------------
    # what the screen shows
    # ---------------------------------------------------------------------------------------------
    Mutation(
        name='the screen stops printing the key, so a row cannot be checked by hand',
        path=VIEW,
        old="""                                        <code class="ss-code">{{ $feature['key'] }}</code>""",
        new="""                                        <code class="ss-code">&mdash;</code>""",
        test_file=SCREEN,
        filter='test_the_screen_shows_the_feature_its_reach_and_the_change_log',
    ),
    Mutation(
        name='the screen stops stating the reach sentence',
        path=VIEW,
        old="""                                    <td>{{ $reachSentences[$feature['reach']] ?? $feature['reach'] }}</td>""",
        new="""                                    <td>{{ $feature['reach'] }}</td>""",
        test_file=SCREEN,
        filter='test_the_screen_shows_the_feature_its_reach_and_the_change_log',
    ),
    Mutation(
        name='the unrecognised-state notice is dropped, so a truncated value reads as a deliberate off',
        path=VIEW,
        old="""                                        @if ($feature['stored_state'] !== $feature['state'])""",
        new="""                                        @if (false)""",
        test_file=SCREEN,
        filter='test_an_unrecognised_stored_state_is_shown_rather_than_reported_as_off',
    ),
    Mutation(
        name='the view uses the host theme\'s button, a measured contrast failure in this admin',
        path=VIEW,
        old="""                                                <button type="submit" class="ss-btn ss-btn--danger-quiet ss-btn--sm">""",
        new="""                                                <button type="submit" class="btn btn-danger">""",
        test_file=SCREEN,
        filter='test_the_view_declares_no_colour_and_uses_no_host_colour_class',
    ),
    Mutation(
        name='the view pins a side, so it is wrong in one of the two languages',
        path=VIEW,
        old='''                                        <br>
                                        <code class="ss-code">{{ $feature['key'] }}</code>''',
        new='''                                        <br class="ml-2">
                                        <code class="ss-code">{{ $feature['key'] }}</code>''',
        test_file=SCREEN,
        filter='test_the_view_pins_no_direction_of_its_own',
    ),
    Mutation(
        name='the availability badge loses its text label, leaving colour as the only signal',
        path=VIEW,
        old="""                                        <x-ss.status-badge :tone="$availabilityTones[$feature['state']] ?? 'neutral'"
                                                           :label="$availabilityLabels[$feature['state']] ?? $feature['state']" />""",
        new="""                                        <span class="ss-badge"></span>""",
        test_file=SCREEN,
        filter='test_the_view_declares_no_colour_and_uses_no_host_colour_class',
    ),

    # ---------------------------------------------------------------------------------------------
    # the derived fact the screen turns on
    # ---------------------------------------------------------------------------------------------
    Mutation(
        name='a release that has not started is folded into "switched off"',
        path=OVERVIEW,
        old="""            default => 'nobody_yet',""",
        new="""            default => 'off',""",
        test_file=OVERVIEW_TEST,
        filter='test_a_limited_feature_with_nothing_named_and_no_share_reads_nobody_yet',
    ),
    Mutation(
        name='a REFUSAL counts as somebody reaching the feature',
        path=OVERVIEW,
        old="""            return [
                'key' => (string) $flag->key,""",
        new="""            $allowed = $own;

            return [
                'key' => (string) $flag->key,""",
        test_file=OVERVIEW_TEST,
        filter='test_a_refusal_alone_does_not_count_as_a_feature_being_reached',
    ),
    Mutation(
        name='the targets grouping key is wrong, so one feature carries another\'s rules',
        path=OVERVIEW,
        old="""            $own = $targets->get($flag->getKey(), collect());""",
        new="""            $own = $targets->flatten();""",
        test_file=OVERVIEW_TEST,
        filter='test_two_features_do_not_share_each_others_targets',
    ),
    Mutation(
        name='the change log payload is handed to the view as raw JSON',
        path=OVERVIEW,
        old="""            $pairs[] = $key . '=' . ($value === null ? '-' : (string) $value);""",
        new="""            $pairs[] = (string) $value;""",
        test_file=OVERVIEW_TEST,
        filter='test_a_change_log_entry_is_flattened_for_reading',
    ),
    Mutation(
        name='the raw stored state is no longer carried, so a surprise is hidden',
        path=OVERVIEW,
        old="""                'stored_state' => (string) $flag->getAttribute('state'),""",
        new="""                'stored_state' => $flag->resolvedState(),""",
        test_file=OVERVIEW_TEST,
        filter='test_the_raw_stored_state_is_carried_so_the_screen_can_show_a_surprise',
    ),

    # ---------------------------------------------------------------------------------------------
    # the `xss` exemption, both halves. §6.1 of PHASE-06-FEATURE-CONTROL.md is why these exist:
    # the defect was found by self-review AFTER the first mutation list was written, so no mutant in
    # that list could have found it. These two are the pair that pins the fix.
    # ---------------------------------------------------------------------------------------------
    Mutation(
        name='`xss` is put back, so a key containing `alert` is silently mangled on the way in',
        path=ROUTES,
        old="""    Route::post('platform/feature-control/state', [\\App\\Http\\Controllers\\PlatformFeatureControlController::class, 'state'])
        ->middleware(\\App\\Http\\Middleware\\PlatformOperatorOnly::class)
        ->withoutMiddleware('xss')""",
        new="""    Route::post('platform/feature-control/state', [\\App\\Http\\Controllers\\PlatformFeatureControlController::class, 'state'])
        ->middleware(\\App\\Http\\Middleware\\PlatformOperatorOnly::class)""",
        test_file=SCREEN,
        filter='test_a_key_containing_a_stripped_substring_is_stored_as_typed',
    ),
    Mutation(
        name='the label is printed raw, which is the hole dropping `xss` would have opened',
        path=VIEW,
        old="""                                        {{ $feature['label'] }}""",
        new="""                                        {!! $feature['label'] !!}""",
        test_file=SCREEN,
        filter='test_markup_in_a_label_is_escaped_rather_than_rendered',
    ),

    # ---------------------------------------------------------------------------------------------
    # bilingual
    # ---------------------------------------------------------------------------------------------
    Mutation(
        name='the Arabic declaration is replaced by the English sentence',
        path='resources/lang/ar.json',
        old='    "Feature control": "التحكم بالميزات"',
        new='    "Feature control": "Feature control"',
        test_file=LANG,
        filter='test_the_arabic_declarations_are_not_the_english_sentence',
    ),
    Mutation(
        name='an Arabic declaration is removed, so the Arabic page renders English',
        path='resources/lang/ar.json',
        old='''    "Pilot groups": ''',
        new='''    "Pilot groups REMOVED": ''',
        test_file=LANG,
        filter='test_every_key_this_surface_prints_is_declared_in_english_and_arabic',
    ),
    Mutation(
        name='an Arabic declaration is removed and the rendered Arabic page shows it',
        path='resources/lang/ar.json',
        old='''    "Change log": ''',
        new='''    "Change log REMOVED": ''',
        test_file=SCREEN,
        filter='test_the_screen_renders_in_arabic',
    ),
]

if __name__ == '__main__':
    sys.exit(run(MUTATIONS))
