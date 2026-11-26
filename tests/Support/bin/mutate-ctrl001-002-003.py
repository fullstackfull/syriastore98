#!/usr/bin/env python3
"""
CTRL-001 / CTRL-002 / CTRL-003's mutation run.

The control plane is new code, so each test below was written failing-first against an absent
implementation. These mutations are the SECOND check this project requires: that each test fails for
the RIGHT reason, and in particular that the five-step resolution ORDER -- which is where "safe
disable" actually lives -- is pinned rather than incidentally satisfied.

Run from the repository root:  python3 tests/Support/bin/mutate-ctrl001-002-003.py
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from mutate import Mutation, run  # noqa: E402

RESOLUTION = 'tests/Feature/Control/FeatureControlResolutionTest.php'
DISABLE = 'tests/Feature/Control/FeatureControlSafeDisableTest.php'
LOG = 'tests/Feature/Control/PlatformChangeLogTest.php'
BUCKET = 'tests/Unit/Control/GradualRolloutBucketTest.php'

CONTROL = 'app/Support/Control/FeatureControl.php'
CHANGELOG = 'app/Support/Control/PlatformChangeLog.php'
ENTRY = 'app/Models/PlatformControlChange.php'

MUTATIONS = [
    # =============================================================================================
    # THE RESOLUTION ORDER. CTRL-002's "safe disable" IS steps 2-and-3-before-4-and-5, so each
    # reordering is a separate mutation.
    # =============================================================================================
    Mutation(
        name='step 2 moves AFTER the allow-list, so off is undone by a forgotten target',
        path=CONTROL,
        old="""        if ($state === PlatformFeatureFlag::STATE_OFF) {
            return FeatureDecision::Denied;                  // step 2 -- the kill switch
        }

        $subject = self::subject($storeId);""",
        new="""        $subject = self::subject($storeId);""",
        test_file=RESOLUTION,
        filter='test_off_refuses_a_store_that_every_other_rule_would_admit',
    ),
    Mutation(
        name='step 4 moves BEFORE step 3, so a deny cannot exclude one store from an on feature',
        path=CONTROL,
        old="""        if (self::matches($targets, PlatformFeatureFlagTarget::EFFECT_DENY, $subject)) {
            return FeatureDecision::Denied;                  // step 3 -- deny beats on
        }

        if ($state === PlatformFeatureFlag::STATE_ON) {
            return FeatureDecision::Allowed;                 // step 4
        }""",
        new="""        if ($state === PlatformFeatureFlag::STATE_ON) {
            return FeatureDecision::Allowed;                 // step 4
        }

        if (self::matches($targets, PlatformFeatureFlagTarget::EFFECT_DENY, $subject)) {
            return FeatureDecision::Denied;                  // step 3
        }""",
        test_file=RESOLUTION,
        filter='test_a_denied_store_is_refused_even_while_the_feature_is_on_for_everyone',
    ),
    Mutation(
        name='an allow beats a deny for the same subject',
        path=CONTROL,
        old="""        if (self::matches($targets, PlatformFeatureFlagTarget::EFFECT_DENY, $subject)) {
            return FeatureDecision::Denied;                  // step 3 -- deny beats on
        }""",
        new="""        if (self::matches($targets, PlatformFeatureFlagTarget::EFFECT_ALLOW, $subject)) {
            return FeatureDecision::Allowed;
        }

        if (self::matches($targets, PlatformFeatureFlagTarget::EFFECT_DENY, $subject)) {
            return FeatureDecision::Denied;
        }""",
        test_file=RESOLUTION,
        filter='test_deny_beats_allow_for_the_same_store',
    ),
    Mutation(
        name='an undeclared feature answers Denied instead of NotControlled',
        path=CONTROL,
        old="""        if ($flag === null) {
            return FeatureDecision::NotControlled;          // step 1""",
        new="""        if ($flag === null) {
            return FeatureDecision::Denied;""",
        test_file=RESOLUTION,
        filter='test_an_undeclared_feature_is_not_controlled_rather_than_off',
    ),
    Mutation(
        name='an unrecognised state falls through instead of reading as off',
        path='app/Models/PlatformFeatureFlag.php',
        old="""        return in_array($state, self::STATES, true) ? $state : self::STATE_OFF;""",
        new="""        return in_array($state, self::STATES, true) ? $state : self::STATE_ON;""",
        test_file=RESOLUTION,
        filter='test_a_state_the_code_does_not_recognise_is_treated_as_off',
    ),

    # =============================================================================================
    # CTRL-001's THREE AXES. One mutation each, so a broken axis cannot hide behind a working one.
    # =============================================================================================
    Mutation(
        name='the store axis stops comparing the store',
        path=CONTROL,
        old="""                PlatformFeatureFlagTarget::TYPE_STORE
                    => $subject['store'] !== null && $value === (string) $subject['store'],""",
        new="""                PlatformFeatureFlagTarget::TYPE_STORE
                    => $subject['store'] !== null,""",
        test_file=RESOLUTION,
        filter='test_a_feature_can_be_enabled_for_one_store_and_not_its_sibling',
    ),
    Mutation(
        name='the plan axis is resolved from the store id rather than the owner',
        path=CONTROL,
        old="""                $ownerId = DB::table('stores')->where('id', $storeId)->value('created_by');""",
        new="""                $ownerId = $storeId;""",
        test_file=RESOLUTION,
        filter='test_a_feature_can_be_enabled_for_every_store_on_a_plan',
    ),
    Mutation(
        name='a merchant holding no plan reports plan 0, sweeping every such merchant into one target',
        path=CONTROL,
        old="""                    if ($planId !== null && (string) $planId !== '' && (string) $planId !== '0') {""",
        new="""                    if ($planId !== null) {""",
        test_file=RESOLUTION,
        filter='test_a_plan_id_stored_as_zero_or_blank_is_not_a_plan',
    ),
    Mutation(
        name='the pilot-group axis stops reading membership',
        path=CONTROL,
        old="""                    $groups = DB::table('platform_pilot_group_members')
                        ->where('store_id', $storeId)""",
        new="""                    $groups = DB::table('platform_pilot_group_members')
                        ->where('store_id', 0)""",
        test_file=RESOLUTION,
        filter='test_a_feature_can_be_enabled_for_a_pilot_group',
    ),
    Mutation(
        name='a pilot group slug is no longer normalised, so MySQL and SQLite disagree',
        path=CONTROL,
        old="""        return strtolower(trim($value));""",
        new="""        return trim($value);""",
        test_file=RESOLUTION,
        filter='test_a_pilot_groups_slug_is_matched_without_regard_to_case',
    ),
    Mutation(
        name='an unrecognised target type becomes a match, in both directions',
        path=CONTROL,
        old="""                default => false,
            };""",
        new="""                default => true,
            };""",
        test_file=RESOLUTION,
        filter='test_a_target_type_the_code_does_not_recognise_neither_allows_nor_denies',
    ),
    Mutation(
        name='the flag lookup ignores the key',
        path=CONTROL,
        old="""                return PlatformFeatureFlag::query()->where('key', $key)->first();""",
        new="""                return PlatformFeatureFlag::query()->first();""",
        test_file=RESOLUTION,
        filter='test_each_flag_answers_for_itself',
    ),
    Mutation(
        name='the targets query reads every flag\'s targets',
        path=CONTROL,
        old="""                return PlatformFeatureFlagTarget::query()
                    ->where('feature_flag_id', $flagId)
                    ->get()""",
        new="""                return PlatformFeatureFlagTarget::query()
                    ->get()""",
        test_file=RESOLUTION,
        filter='test_each_flag_answers_for_itself',
    ),

    # =============================================================================================
    # CTRL-002's GRADUAL ROLLOUT.
    # =============================================================================================
    Mutation(
        name='the rollout boundary becomes inclusive, so 0% reaches bucket 0',
        path=CONTROL,
        old="""        if ($storeId !== null && $percentage > 0 && self::bucket($key, $storeId) < $percentage) {""",
        new="""        if ($storeId !== null && self::bucket($key, $storeId) <= $percentage) {""",
        test_file=RESOLUTION,
        filter='test_the_rollout_boundary_is_exclusive_through_the_resolver',
    ),
    Mutation(
        name='a percentage-only rollout answers for a caller with no store',
        path=CONTROL,
        old="""        if ($storeId !== null && $percentage > 0 && self::bucket($key, $storeId) < $percentage) {""",
        new="""        if ($percentage > 0 && self::bucket($key, (int) $storeId) < $percentage) {""",
        test_file=RESOLUTION,
        filter='test_a_percentage_rollout_denies_a_caller_that_names_no_store',
    ),
    Mutation(
        name='the percentage is stored unclamped',
        path=CONTROL,
        old="""            max(0, min(100, $percentage)),""",
        new="""            $percentage,""",
        test_file=RESOLUTION,
        filter='test_a_percentage_outside_the_range_is_clamped',
    ),
    Mutation(
        name='the bucket drops the flag-key salt, so every rollout picks the same stores',
        path=CONTROL,
        old="""        return (int) hexdec(substr(md5(self::normaliseKey($key) . ':' . $storeId, false), 0, 6)) % 100;""",
        new="""        return (int) hexdec(substr(md5((string) $storeId, false), 0, 6)) % 100;""",
        test_file=BUCKET,
        filter='test_two_features_at_the_same_percentage_select_different_stores',
    ),
    Mutation(
        name='the bucket becomes store_id % 100 -- correlated AND sequential',
        path=CONTROL,
        old="""        return (int) hexdec(substr(md5(self::normaliseKey($key) . ':' . $storeId, false), 0, 6)) % 100;""",
        new="""        return $storeId % 100;""",
        test_file=BUCKET,
        filter='test_two_features_at_the_same_percentage_select_different_stores',
    ),
    Mutation(
        name='the bucket is drawn at random, so it is not sticky',
        path=CONTROL,
        old="""        return (int) hexdec(substr(md5(self::normaliseKey($key) . ':' . $storeId, false), 0, 6)) % 100;""",
        new="""        return random_int(0, 99);""",
        test_file=BUCKET,
        filter='test_a_stores_bucket_is_the_same_every_time_it_is_asked',
    ),
    Mutation(
        name='the bucket uses crc32, which is NEGATIVE on a 32-bit build',
        path=CONTROL,
        old="""        return (int) hexdec(substr(md5(self::normaliseKey($key) . ':' . $storeId, false), 0, 6)) % 100;""",
        new="""        return crc32(self::normaliseKey($key) . ':' . $storeId) % 100;""",
        test_file=BUCKET,
        filter='test_the_bucketing_function_is_pinned_so_a_change_cannot_be_silent',
    ),
    Mutation(
        name='the bucket stops normalising the key, so one flag has two rollouts',
        path=CONTROL,
        old="""        return (int) hexdec(substr(md5(self::normaliseKey($key) . ':' . $storeId, false), 0, 6)) % 100;""",
        new="""        return (int) hexdec(substr(md5($key . ':' . $storeId, false), 0, 6)) % 100;""",
        test_file=BUCKET,
        filter='test_the_bucket_does_not_depend_on_how_the_key_was_spelled',
    ),

    # =============================================================================================
    # CTRL-002's SAFE DISABLE.
    # =============================================================================================
    Mutation(
        name='the disable also clears the rollout width',
        path=CONTROL,
        old="""        return self::transition($key, PlatformFeatureFlag::STATE_OFF, null, PlatformChangeLog::ACTION_DISABLED, $actor, $note);""",
        new="""        return self::transition($key, PlatformFeatureFlag::STATE_OFF, 0, PlatformChangeLog::ACTION_DISABLED, $actor, $note);""",
        test_file=DISABLE,
        filter='test_a_disable_writes_one_column_and_preserves_every_target_and_the_width',
    ),
    Mutation(
        name='the disable tidies up the feature\'s targets',
        path=CONTROL,
        old="""        $flag->state = $state;

        if ($percentage !== null) {""",
        new="""        $flag->state = $state;

        if ($state === PlatformFeatureFlag::STATE_OFF) {
            PlatformFeatureFlagTarget::query()->where('feature_flag_id', $flag->getKey())->delete();
        }

        if ($percentage !== null) {""",
        test_file=DISABLE,
        filter='test_a_disable_and_a_resume_restore_the_identical_reach',
    ),
    Mutation(
        name='a state transition stops clearing the memo, so the kill switch waits for a restart',
        path=CONTROL,
        old="""        self::forget();

        PlatformChangeLog::record(
            PlatformChangeLog::SUBJECT_FEATURE_FLAG,
            $key,
            $action,""",
        new="""        PlatformChangeLog::record(
            PlatformChangeLog::SUBJECT_FEATURE_FLAG,
            $key,
            $action,""",
        test_file=DISABLE,
        filter='test_a_disable_takes_effect_on_the_very_next_decision_in_the_same_request',
    ),
    Mutation(
        name='a pilot membership write stops clearing the memo',
        path=CONTROL,
        old="""        $member = PlatformPilotGroupMember::create(['pilot_group' => $group, 'store_id' => $storeId]);
        self::forget();""",
        new="""        $member = PlatformPilotGroupMember::create(['pilot_group' => $group, 'store_id' => $storeId]);""",
        test_file=DISABLE,
        filter='test_a_pilot_membership_added_mid_request_is_seen_at_once',
    ),
    Mutation(
        name='re-declaring a flag resets its state, so a redeploy switches every feature off',
        path=CONTROL,
        old="""            $flag->fill(['label' => $label, 'description' => $description])->save();""",
        new="""            $flag->fill([
                'label' => $label,
                'description' => $description,
                'state' => PlatformFeatureFlag::STATE_OFF,
                'rollout_percentage' => 0,
            ])->save();""",
        test_file=DISABLE,
        filter='test_redeclaring_a_live_feature_does_not_switch_it_off',
    ),
    Mutation(
        name='the disable snapshot is taken AFTER the write, so the log cannot say what it was',
        path=CONTROL,
        old="""        $before = self::snapshot($flag);

        $flag->state = $state;""",
        new="""        $flag->state = $state;
        $before = self::snapshot($flag);""",
        test_file=DISABLE,
        filter='test_a_disable_records_who_did_it_and_what_the_state_was',
    ),
    Mutation(
        name='a failed log write takes the switch down with it',
        path=CHANGELOG,
        old="""        } catch (\\Throwable $failure) {
            Log::warning('PlatformChangeLog could not record a platform control change.', [""",
        new="""        } catch (\\Throwable $failure) {
            throw $failure;
            Log::warning('PlatformChangeLog could not record a platform control change.', [""",
        test_file=LOG,
        filter='test_a_change_still_lands_when_the_log_cannot_be_written',
    ),

    # =============================================================================================
    # CTRL-003's CHANGE LOG.
    # =============================================================================================
    Mutation(
        name='every change is recorded under one action',
        path=CONTROL,
        old="""            PlatformChangeLog::ACTION_TARGET_ADDED,
            $before,
            ['target_type' => $targetType, 'target_key' => $targetKey, 'effect' => $effect],""",
        new="""            PlatformChangeLog::ACTION_DEFINED,
            $before,
            ['target_type' => $targetType, 'target_key' => $targetKey, 'effect' => $effect],""",
        test_file=LOG,
        filter='test_the_whole_life_of_a_feature_is_on_the_record',
    ),
    Mutation(
        name='the actor name is read live instead of snapshotted',
        path=CHANGELOG,
        old="""                'actor_name' => $actorName,""",
        new="""                'actor_name' => null,""",
        test_file=LOG,
        filter='test_the_actors_name_is_a_snapshot_and_survives_a_rename',
    ),
    Mutation(
        name='a non-User principal\'s primary key is written into actor_id',
        path=CHANGELOG,
        old="""        if (is_object($actor)) {
            return [null, 'non-user principal: ' . $actor::class];
        }""",
        new="""        if (is_object($actor)) {
            return [(int) $actor->getKey(), 'non-user principal: ' . $actor::class];
        }""",
        test_file=LOG,
        filter='test_a_non_user_principal_is_recorded_as_one_rather_than_as_a_user_id',
    ),
    Mutation(
        name='the append-only guard moves to `saving`, so nothing is ever logged',
        path=ENTRY,
        old="""        static::updating(function (self $row) {""",
        new="""        static::saving(function (self $row) {""",
        test_file=LOG,
        filter='test_the_append_only_guard_does_not_stop_an_entry_being_written',
    ),
    Mutation(
        name='the append-only guard is dropped and an entry can be rewritten',
        path=ENTRY,
        old="""        static::updating(function (self $row) {
            throw new RuntimeException(""",
        new="""        static::updating(function (self $row) {
            if (false) throw new RuntimeException(""",
        test_file=LOG,
        filter='test_an_entry_cannot_be_rewritten',
    ),
    Mutation(
        name='the feed orders by created_at, so same-second changes come back shuffled',
        path=CHANGELOG,
        old="""        return PlatformControlChange::query()
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get();""",
        new="""        return PlatformControlChange::query()
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();""",
        test_file=LOG,
        filter='test_the_feed_breaks_a_shared_timestamp_by_insertion_order',
    ),
    Mutation(
        name='a subject\'s history returns every subject\'s',
        path=CHANGELOG,
        old="""            ->where('subject_type', $subjectType)
            ->where('subject_key', $subjectKey)
            ->orderByDesc('id')""",
        new="""            ->where('subject_type', $subjectType)
            ->orderByDesc('id')""",
        test_file=LOG,
        filter='test_a_subjects_history_is_only_that_subjects',
    ),

    # =============================================================================================
    # THE ADOPTION GUARANTEE, and the write/read asymmetry.
    # =============================================================================================
    Mutation(
        name='a write to an absent control plane returns quietly instead of refusing',
        path=CONTROL,
        old="""        throw new \\RuntimeException(
            'The platform feature control tables are not present, so this change cannot be stored. '""",
        new="""        return;
        throw new \\RuntimeException(
            'The platform feature control tables are not present, so this change cannot be stored. '""",
        test_file=RESOLUTION,
        filter='test_a_read_survives_an_absent_control_plane_and_a_write_refuses',
    ),
    Mutation(
        name='the module gate starts consulting the control plane',
        path='app/Helper/helper.php',
        old="""if (!function_exists('module_is_active')) {
    function module_is_active($moduleName, $user_id = null)
    {""",
        new="""if (!function_exists('module_is_active')) {
    function module_is_active($moduleName, $user_id = null)
    {
        if (\\App\\Support\\Control\\FeatureControl::decide($moduleName)->isDenied()) {
            return false;
        }""",
        test_file=RESOLUTION,
        filter='test_the_control_plane_is_not_wired_into_the_existing_module_gate',
    ),
]

if __name__ == '__main__':
    sys.exit(run(MUTATIONS))
