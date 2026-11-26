<?php

namespace App\Support\Control;

use App\Models\PlatformFeatureFlag;
use App\Models\PlatformFeatureFlagTarget;
use App\Models\PlatformPilotGroupMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 06 `CTRL-001` / `CTRL-002` — WHO HAS THIS FEATURE, AND HOW IT IS SWITCHED OFF SAFELY.
 * =================================================================================================
 *
 * `CTRL-001` *"تفعيل الميزات حسب الباقات والمتاجر والمجموعات التجريبية"* — enablement by **plan**,
 * by **store**, by **pilot group**.
 * `CTRL-002` *"إطلاق تدريجي وإيقاف آمن"* — **gradual rollout** and **safe disable**.
 * `DECISION-ID-REGISTER.md:249-250`.
 *
 * -------------------------------------------------------------------------------------------------
 * THE RESOLUTION ORDER. Five steps, and the ORDER is the requirement rather than an implementation
 * detail — each step is pinned in both directions by `FeatureControlResolutionTest`.
 *
 *   1. **No flag row** → `NotControlled`. See `FeatureDecision`'s docblock for why this is not
 *      `false`. It is what makes the plane safe to adopt one surface at a time.
 *   2. **`state = off`** → `Denied`, before any target is read. THIS IS `CTRL-002`'s "safe
 *      disable": switching a feature off cannot be partially undone by an allow-list somebody
 *      forgot, by a pilot group that still has members, or by a rollout percentage left at 100.
 *   3. **A matching `deny` target** → `Denied`, even when the state is `on`. "Everyone except this
 *      store" is a real operator need and the alternative is taking the feature from everybody.
 *   4. **`state = on`** → `Allowed`.
 *   5. **`state = limited`** → `Allowed` if an `allow` target matches the subject's store, one of
 *      its pilot groups, or its plan; or if the store falls inside the rollout percentage.
 *      Otherwise `Denied`.
 *
 * AN UNRECOGNISED `state` IS TREATED AS `off`. Production runs non-strict `sql_mode`, so a value
 * too long for `varchar(16)` is TRUNCATED rather than refused — and a truncated state must not read
 * as "on". Fail closed INSIDE a declared flag; fail NotControlled when no flag is declared. Those
 * are different failures and they get different answers on purpose.
 *
 * -------------------------------------------------------------------------------------------------
 * `CTRL-002`'s "GRADUAL ROLLOUT", AND THE THREE PROPERTIES A NAIVE VERSION GETS WRONG.
 *
 * A percentage is useless unless the same subject gets the same answer every time, so the bucket is
 * a hash and not a random draw:
 *
 *   bucket(key, store) = hexdec(substr(md5("<key>:<store>"), 0, 6)) % 100     included iff < pct
 *
 *  - **STICKY.** `rand()` or `shuffle()` would flip a shopper between page loads — half a rendered
 *    page from the new code and half from the old.
 *  - **MONOTONIC.** Widening 10% → 50% only ADDS stores; nobody who had the feature loses it. That
 *    falls out of `bucket < percentage` with a bucket that does not depend on the percentage, and it
 *    is the property that makes a staged rollout safe to continue rather than a reshuffle.
 *  - **DECORRELATED ACROSS FLAGS.** `store_id % 100` would put the same unlucky stores in the first
 *    10% of EVERY rollout for ever. Salting the hash with the flag key means each feature's 10% is
 *    a different 10%.
 *
 * WHY `md5` AND NOT `crc32`. On a 32-bit build `crc32()` returns a NEGATIVE int, so `% 100` yields a
 * negative bucket and `bucket < percentage` is then true for everybody — a 1% rollout that reaches
 * the whole platform, on one architecture only. Six hex digits of md5 is at most `0xFFFFFF`
 * (16,777,215), which is a safe integer on every build, and md5 is stable across processes and
 * versions. This is NOT a security hash and is not used as one.
 *
 * A ROLLOUT PERCENTAGE NEEDS A SUBJECT. A caller that names no store (the operator's own screens, a
 * console command, a merchant with no store — `getCurrentStore()` really does return null for one,
 * and CLAUDE.md records it as a supported product state) gets `Denied` from a percentage-only
 * `limited` flag rather than a coin flip. Stated, and asserted.
 *
 * -------------------------------------------------------------------------------------------------
 * THE MEMO. Container-scoped, cleared by every write.
 *
 * `app()->scoped()` and NOT a function `static`, for the two reasons CLAUDE.md records at
 * `activated_modules_for_owner`: a `static` outlives the application instance, and the ids it would
 * be keyed on are not stable across one (a test suite rebuilds the app and `RefreshDatabase`
 * restarts the auto-increment, so store id 1 in one test is a different store in the next). The
 * container is rebuilt exactly when the identity space is. And `scoped()` rather than `instance()`
 * so that a queue worker's `forgetScopedInstances()` bounds the memo to one job — an availability
 * answer must not outlive the unit of work that asked for it.
 *
 * ONE BINDING HOLDING ONE ARRAY, so `forget()` is a single `forgetInstance()` and is provably
 * total. The coarse invalidation is the safe direction for a control plane: one write clears every
 * memoised answer rather than leaving the operator guessing which one is stale.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT THIS CLASS DOES NOT DO.
 *
 * It does not change `module_is_active()`, `ActivatedModule()`, `add_on_managers` or
 * `user_active_modules`. Nothing that worked before this class existed behaves differently because
 * it exists: a key with no flag row answers `NotControlled` and no caller is obliged to ask. The
 * three existing axes are measured in the migration's docblock and are left exactly as they are.
 */
final class FeatureControl
{
    /** The single container binding that holds every memoised answer for this unit of work. */
    private const MEMO = 'storesyria.control.feature.memo';

    // =============================================================================================
    // reading
    // =============================================================================================

    /**
     * Does `$storeId` have `$key`?
     *
     * @param  int|null  $storeId  the subject store, or null for a caller with no store in hand
     */
    public static function decide(string $key, ?int $storeId = null): FeatureDecision
    {
        $key = self::normaliseKey($key);

        if ($key === '') {
            return FeatureDecision::NotControlled;
        }

        $flag = self::flag($key);

        if ($flag === null) {
            return FeatureDecision::NotControlled;          // step 1
        }

        $state = $flag->resolvedState();

        if ($state === PlatformFeatureFlag::STATE_OFF) {
            return FeatureDecision::Denied;                  // step 2 -- the kill switch
        }

        $subject = self::subject($storeId);
        $targets = self::targets((int) $flag->getKey());

        if (self::matches($targets, PlatformFeatureFlagTarget::EFFECT_DENY, $subject)) {
            return FeatureDecision::Denied;                  // step 3 -- deny beats on
        }

        if ($state === PlatformFeatureFlag::STATE_ON) {
            return FeatureDecision::Allowed;                 // step 4
        }

        // step 5 -- limited
        if (self::matches($targets, PlatformFeatureFlagTarget::EFFECT_ALLOW, $subject)) {
            return FeatureDecision::Allowed;
        }

        $percentage = $flag->resolvedPercentage();

        if ($storeId !== null && $percentage > 0 && self::bucket($key, $storeId) < $percentage) {
            return FeatureDecision::Allowed;
        }

        return FeatureDecision::Denied;
    }

    /**
     * The boolean form, for a caller that has a default in mind for "no flag governs this".
     *
     * THE DEFAULT IS AN ARGUMENT AND NOT A CHOICE MADE HERE. See `FeatureDecision`'s docblock: both
     * possible choices are wrong for some caller, so the caller states theirs.
     */
    public static function enabled(string $key, ?int $storeId = null, bool $default = false): bool
    {
        $decision = self::decide($key, $storeId);

        return $decision->isControlled() ? $decision->isAllowed() : $default;
    }

    /**
     * Which of the 100 rollout buckets `$storeId` falls in for `$key`. 0-99.
     *
     * PUBLIC so a test and the operator screen can read it rather than reimplement it — the same
     * reason `AppSettingController::PANEL_OWNED_FEATURE_FLAGS` is public. A screen that tells the
     * operator "this store is in bucket 73, so it joins at 74%" is the difference between a
     * percentage they can reason about and a number they have to trust.
     */
    public static function bucket(string $key, int $storeId): int
    {
        return (int) hexdec(substr(md5(self::normaliseKey($key) . ':' . $storeId, false), 0, 6)) % 100;
    }

    /**
     * The stores in a pilot group.
     *
     * @return array<int, int>
     */
    public static function pilotGroupMembers(string $group): array
    {
        $group = self::normaliseKey($group);

        if ($group === '' || ! self::tablesExist()) {
            return [];
        }

        return PlatformPilotGroupMember::query()
            ->where('pilot_group', $group)
            ->orderBy('store_id')
            ->pluck('store_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Every pilot group that has at least one member, with its size.
     *
     * @return array<string, int>
     */
    public static function pilotGroups(): array
    {
        if (! self::tablesExist()) {
            return [];
        }

        return PlatformPilotGroupMember::query()
            ->select('pilot_group', DB::raw('count(*) as member_count'))
            ->groupBy('pilot_group')
            ->orderBy('pilot_group')
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->pilot_group => (int) $row->member_count])
            ->all();
    }

    /** Drop every memoised answer. Called by every write below; public for a test's own fixtures. */
    public static function forget(): void
    {
        app()->forgetInstance(self::MEMO);
    }

    // =============================================================================================
    // writing -- every one of these records a change and clears the memo
    // =============================================================================================

    /**
     * Declare a feature. A NEW FLAG IS `off`: a feature is switched on deliberately, never by
     * being mentioned.
     */
    public static function define(
        string $key,
        string $label,
        ?string $description = null,
        mixed $actor = null,
        ?string $note = null
    ): PlatformFeatureFlag {
        $key = self::normaliseKey($key);

        self::assertWritable();

        $flag = PlatformFeatureFlag::query()->where('key', $key)->first();

        if ($flag instanceof PlatformFeatureFlag) {
            /*
             * IDEMPOTENT, AND IT DOES NOT RESET THE STATE. Re-running a seeder, a deploy step or a
             * console command must not switch a live feature off -- that would be an outage caused
             * by a redeploy. Only the human-readable fields are refreshed.
             */
            $flag->fill(['label' => $label, 'description' => $description])->save();
            self::forget();

            return $flag;
        }

        $flag = PlatformFeatureFlag::create([
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'state' => PlatformFeatureFlag::STATE_OFF,
            'rollout_percentage' => 0,
        ]);

        self::forget();

        PlatformChangeLog::record(
            PlatformChangeLog::SUBJECT_FEATURE_FLAG,
            $key,
            PlatformChangeLog::ACTION_DEFINED,
            null,
            self::snapshot($flag),
            $actor,
            $note
        );

        return $flag;
    }

    /** Switch a feature on for every store, except those a `deny` target names. */
    public static function releaseFully(string $key, mixed $actor = null, ?string $note = null): PlatformFeatureFlag
    {
        return self::transition($key, PlatformFeatureFlag::STATE_ON, null, PlatformChangeLog::ACTION_ENABLED, $actor, $note);
    }

    /**
     * `CTRL-002`'s gradual rollout: switch a feature on for `$percentage` of stores plus whatever
     * the `allow` targets name.
     *
     * The percentage is CLAMPED rather than refused. A screen that posts 150 means "everybody", and
     * an exception thrown from an availability control is a worse answer than the obvious one.
     */
    public static function release(
        string $key,
        int $percentage,
        mixed $actor = null,
        ?string $note = null
    ): PlatformFeatureFlag {
        return self::transition(
            $key,
            PlatformFeatureFlag::STATE_LIMITED,
            max(0, min(100, $percentage)),
            PlatformChangeLog::ACTION_ROLLOUT_CHANGED,
            $actor,
            $note
        );
    }

    /**
     * `CTRL-002`'s SAFE DISABLE.
     *
     * Writes `state` and NOTHING ELSE. The rollout width and every target survive, so resuming the
     * feature restores the exact reach it had — which is `CTRL-004` ("do not delete a feature's
     * data merely because its availability was disabled") applied to the control plane itself, and
     * is asserted by
     * `FeatureControlSafeDisableTest::test_a_disable_and_a_resume_restore_the_identical_reach`.
     *
     * The memo is cleared BEFORE the change is recorded, so a recorder failure cannot leave a
     * disabled feature still answering `Allowed` for the rest of the request.
     */
    public static function disable(string $key, mixed $actor = null, ?string $note = null): PlatformFeatureFlag
    {
        return self::transition($key, PlatformFeatureFlag::STATE_OFF, null, PlatformChangeLog::ACTION_DISABLED, $actor, $note);
    }

    /** Reach this plan / store / pilot group even while the feature is `limited`. */
    public static function allow(
        string $key,
        string $targetType,
        string|int $targetKey,
        mixed $actor = null,
        ?string $note = null
    ): ?PlatformFeatureFlagTarget {
        return self::target($key, $targetType, $targetKey, PlatformFeatureFlagTarget::EFFECT_ALLOW, $actor, $note);
    }

    /** Refuse this plan / store / pilot group whatever the state says, `on` included. */
    public static function deny(
        string $key,
        string $targetType,
        string|int $targetKey,
        mixed $actor = null,
        ?string $note = null
    ): ?PlatformFeatureFlagTarget {
        return self::target($key, $targetType, $targetKey, PlatformFeatureFlagTarget::EFFECT_DENY, $actor, $note);
    }

    /** Remove a target, whichever effect it carried. */
    public static function withdraw(
        string $key,
        string $targetType,
        string|int $targetKey,
        mixed $actor = null,
        ?string $note = null
    ): bool {
        $key = self::normaliseKey($key);
        $flag = self::flag($key);

        if ($flag === null) {
            return false;
        }

        $targetType = self::normaliseKey($targetType);
        $targetKey = trim((string) $targetKey);

        $existing = PlatformFeatureFlagTarget::query()
            ->where('feature_flag_id', $flag->getKey())
            ->where('target_type', $targetType)
            ->where('target_key', $targetKey)
            ->first();

        if (! $existing instanceof PlatformFeatureFlagTarget) {
            return false;
        }

        $before = [
            'target_type' => $targetType,
            'target_key' => $targetKey,
            'effect' => (string) $existing->effect,
        ];

        $existing->delete();
        self::forget();

        PlatformChangeLog::record(
            PlatformChangeLog::SUBJECT_FEATURE_TARGET,
            $key,
            PlatformChangeLog::ACTION_TARGET_WITHDRAWN,
            $before,
            null,
            $actor,
            $note
        );

        return true;
    }

    /** Put a store in a pilot group. Idempotent. */
    public static function addToPilotGroup(
        string $group,
        int $storeId,
        mixed $actor = null,
        ?string $note = null
    ): ?PlatformPilotGroupMember {
        $group = self::normaliseKey($group);

        self::assertWritable();

        if ($group === '' || $storeId <= 0) {
            return null;
        }

        $existing = PlatformPilotGroupMember::query()
            ->where('pilot_group', $group)
            ->where('store_id', $storeId)
            ->first();

        if ($existing instanceof PlatformPilotGroupMember) {
            return $existing;
        }

        $member = PlatformPilotGroupMember::create(['pilot_group' => $group, 'store_id' => $storeId]);
        self::forget();

        PlatformChangeLog::record(
            PlatformChangeLog::SUBJECT_PILOT_GROUP,
            $group,
            PlatformChangeLog::ACTION_MEMBER_ADDED,
            null,
            ['store_id' => $storeId],
            $actor,
            $note
        );

        return $member;
    }

    /**
     * Take a store out of a pilot group.
     *
     * THIS IS NOT A `CTRL-004` VIOLATION, and the distinction is worth stating because the row
     * really is deleted. A membership row IS the targeting decision, not a feature's data: removing
     * a store from a pilot is the operator changing who the pilot reaches, which is the capability
     * `CTRL-001` asks for. What `CTRL-004` forbids is losing the store's own content, settings or
     * history when its availability changes — and nothing here touches any of those. The change log
     * keeps the record that the membership existed.
     */
    public static function removeFromPilotGroup(
        string $group,
        int $storeId,
        mixed $actor = null,
        ?string $note = null
    ): bool {
        $group = self::normaliseKey($group);

        if ($group === '' || ! self::tablesExist()) {
            return false;
        }

        $member = PlatformPilotGroupMember::query()
            ->where('pilot_group', $group)
            ->where('store_id', $storeId)
            ->first();

        if (! $member instanceof PlatformPilotGroupMember) {
            return false;
        }

        $member->delete();
        self::forget();

        PlatformChangeLog::record(
            PlatformChangeLog::SUBJECT_PILOT_GROUP,
            $group,
            PlatformChangeLog::ACTION_MEMBER_REMOVED,
            ['store_id' => $storeId],
            null,
            $actor,
            $note
        );

        return true;
    }

    // =============================================================================================
    // internals
    // =============================================================================================

    /**
     * One state transition, recorded. `$percentage` null means "leave the width alone", which is
     * what makes `disable()` safe.
     */
    private static function transition(
        string $key,
        string $state,
        ?int $percentage,
        string $action,
        mixed $actor,
        ?string $note
    ): PlatformFeatureFlag {
        $key = self::normaliseKey($key);

        self::assertWritable();

        $flag = PlatformFeatureFlag::query()->where('key', $key)->first();

        if (! $flag instanceof PlatformFeatureFlag) {
            /*
             * A TRANSITION ON AN UNDECLARED FLAG DECLARES IT, rather than throwing. The operator
             * screen always has a row, but a console command or a deploy step may not -- and
             * refusing would mean "the kill switch is unavailable for a feature nobody has declared
             * yet", which is backwards. The label falls back to the key and the `defined` entry is
             * recorded first, so the log still reads in order.
             */
            $flag = self::define($key, $key, null, $actor, $note);
        }

        $before = self::snapshot($flag);

        $flag->state = $state;

        if ($percentage !== null) {
            $flag->rollout_percentage = $percentage;
        }

        $flag->save();

        /*
         * THE MEMO IS CLEARED BEFORE THE CHANGE IS RECORDED. `PlatformChangeLog::record()` never
         * throws by contract, but it does touch the database -- and if that ever changed, a
         * recorder failure must not leave a disabled feature still answering Allowed for the rest
         * of the request. Availability first, bookkeeping second.
         */
        self::forget();

        PlatformChangeLog::record(
            PlatformChangeLog::SUBJECT_FEATURE_FLAG,
            $key,
            $action,
            $before,
            self::snapshot($flag),
            $actor,
            $note
        );

        return $flag;
    }

    private static function target(
        string $key,
        string $targetType,
        string|int $targetKey,
        string $effect,
        mixed $actor,
        ?string $note
    ): ?PlatformFeatureFlagTarget {
        $key = self::normaliseKey($key);
        $targetType = self::normaliseKey($targetType);
        $targetKey = trim((string) $targetKey);

        self::assertWritable();

        /*
         * THE TYPE IS GUARDED HERE RATHER THAN LEFT TO NEVER MATCH. An unrecognised type stored in
         * the table is inert -- which is the safe direction -- but it is also invisible: the
         * operator sees a target they believe is working. `CTRL-001` names exactly three axes, so
         * anything else is a programming error and is refused at the door.
         */
        if (! in_array($targetType, PlatformFeatureFlagTarget::TARGET_TYPES, true) || $targetKey === '') {
            Log::warning('FeatureControl refused a target with an unknown type or an empty key.', [
                'feature' => $key,
                'target_type' => $targetType,
            ]);

            return null;
        }

        $flag = PlatformFeatureFlag::query()->where('key', $key)->first();

        if (! $flag instanceof PlatformFeatureFlag) {
            $flag = self::define($key, $key, null, $actor, $note);
        }

        $existing = PlatformFeatureFlagTarget::query()
            ->where('feature_flag_id', $flag->getKey())
            ->where('target_type', $targetType)
            ->where('target_key', $targetKey)
            ->first();

        $before = $existing instanceof PlatformFeatureFlagTarget
            ? ['target_type' => $targetType, 'target_key' => $targetKey, 'effect' => (string) $existing->effect]
            : null;

        if ($existing instanceof PlatformFeatureFlagTarget) {
            /*
             * THE SAME (FLAG, TYPE, KEY) IS UPDATED, NOT STACKED. The unique index enforces it in
             * the engine; doing it here too means the caller gets the row rather than an
             * integrity-constraint exception, and it is what makes "deny beats allow" a decidable
             * rule instead of a race between two contradictory rows.
             */
            $existing->effect = $effect;
            $existing->save();
            $target = $existing;
        } else {
            $target = PlatformFeatureFlagTarget::create([
                'feature_flag_id' => $flag->getKey(),
                'target_type' => $targetType,
                'target_key' => $targetKey,
                'effect' => $effect,
            ]);
        }

        self::forget();

        PlatformChangeLog::record(
            PlatformChangeLog::SUBJECT_FEATURE_TARGET,
            $key,
            PlatformChangeLog::ACTION_TARGET_ADDED,
            $before,
            ['target_type' => $targetType, 'target_key' => $targetKey, 'effect' => $effect],
            $actor,
            $note
        );

        return $target;
    }

    /** @return array<string, mixed> */
    private static function snapshot(PlatformFeatureFlag $flag): array
    {
        return [
            'state' => $flag->resolvedState(),
            'rollout_percentage' => $flag->resolvedPercentage(),
        ];
    }

    /**
     * @param  array<int, PlatformFeatureFlagTarget>  $targets
     * @param  array{store: int|null, plan: string|null, groups: array<int, string>}  $subject
     */
    private static function matches(array $targets, string $effect, array $subject): bool
    {
        foreach ($targets as $target) {
            if ((string) $target->effect !== $effect) {
                continue;
            }

            $type = (string) $target->target_type;
            $value = (string) $target->target_key;

            $hit = match ($type) {
                PlatformFeatureFlagTarget::TYPE_STORE
                    => $subject['store'] !== null && $value === (string) $subject['store'],
                PlatformFeatureFlagTarget::TYPE_PLAN
                    => $subject['plan'] !== null && $value === $subject['plan'],
                PlatformFeatureFlagTarget::TYPE_PILOT_GROUP
                    => in_array(self::normaliseKey($value), $subject['groups'], true),
                /*
                 * AN UNRECOGNISED TYPE NEVER MATCHES, in EITHER direction. It cannot allow and it
                 * cannot deny: a corrupted or truncated `target_type` must not become a refusal any
                 * more than it may become a grant, because a silent platform-wide denial is the
                 * failure CLAUDE.md records as "HTTP 200 on the dashboard, which a status-code
                 * assertion photographs as the screen".
                 */
                default => false,
            };

            if ($hit) {
                return true;
            }
        }

        return false;
    }

    /**
     * What we know about the subject: its store, its plan, and the pilot groups it is in.
     *
     * THE PLAN IS RESOLVED THROUGH THE STORE'S OWNER, and that chain is written out because it is
     * the one a reader will get wrong: `stores.created_by` is the merchant who created the store
     * (`StoreController` writes `$user->id`), and `users.plan_id` is that merchant's plan.
     * `user_active_modules` is keyed on the same owner, which is exactly why `CTRL-001`'s store
     * axis needs this table rather than that one — a merchant may own several stores and the
     * existing entitlement cannot tell them apart.
     *
     * RAW QUERIES, WITH THE PREDICATE WRITTEN OUT. CLAUDE.md: `Store` and `User` are NOT in
     * `config/tenancy.php`'s `scoped_models`, and `null_tenant => 'open'` means a global scope adds
     * no predicate anyway — so there is nothing to lean on and nothing to accidentally lean on.
     * `DB::table()` rather than Eloquent so the answer cannot be served from a `Cachable` model's
     * memo, which CLAUDE.md records as three measured false readings.
     *
     * @return array{store: int|null, plan: string|null, groups: array<int, string>}
     */
    private static function subject(?int $storeId): array
    {
        if ($storeId === null || $storeId <= 0) {
            return ['store' => null, 'plan' => null, 'groups' => []];
        }

        return self::memo('subject.' . $storeId, static function () use ($storeId): array {
            $plan = null;
            $groups = [];

            try {
                $ownerId = DB::table('stores')->where('id', $storeId)->value('created_by');

                if ($ownerId !== null) {
                    $planId = DB::table('users')->where('id', $ownerId)->value('plan_id');

                    /*
                     * COMPARED AS A STRING, because `target_key` is a string column and
                     * `users.plan_id` has a measured TYPE DRIFT in this schema -- CLAUDE.md's
                     * `MigrationHygieneTest::test_plan_id_type_drift_remains_the_only_root_instance`
                     * exists for it. `(string) 3` and `'3'` agree; `3 == '3'` would too, but
                     * `in_array` with loose comparison is how a `0` matches `'free'`, so every
                     * comparison in this class is strict on strings.
                     */
                    if ($planId !== null && (string) $planId !== '' && (string) $planId !== '0') {
                        $plan = (string) $planId;
                    }
                }

                if (self::tablesExist()) {
                    $groups = DB::table('platform_pilot_group_members')
                        ->where('store_id', $storeId)
                        ->pluck('pilot_group')
                        ->map(fn ($g) => self::normaliseKey((string) $g))
                        ->all();
                }
            } catch (\Throwable $failure) {
                /*
                 * FAIL WITHOUT A SUBJECT RATHER THAN WITHOUT AN ANSWER. A database that cannot be
                 * read leaves the plan and groups unknown, so plan- and group-targeted rules simply
                 * do not match -- the feature stays at whatever its state says. Refusing outright
                 * would make an unreachable table switch every controlled feature off; granting
                 * would make it switch every one on. Narrowing the subject is the only direction
                 * that changes nothing the operator did not ask for. `ModuleAvailability` records
                 * the same balance, and logs rather than swallows for the same reason.
                 */
                Log::warning('FeatureControl could not resolve a subject; plan and pilot targeting will not match.', [
                    'store_id' => $storeId,
                    'exception' => $failure->getMessage(),
                ]);
            }

            return ['store' => $storeId, 'plan' => $plan, 'groups' => array_values(array_unique($groups))];
        });
    }

    private static function flag(string $key): ?PlatformFeatureFlag
    {
        if (! self::tablesExist()) {
            return null;
        }

        return self::memo('flag.' . $key, static function () use ($key): ?PlatformFeatureFlag {
            try {
                return PlatformFeatureFlag::query()->where('key', $key)->first();
            } catch (\Throwable $failure) {
                /*
                 * NO FLAG, SO `NotControlled`, SO EVERY CALLER'S OWN DEFAULT APPLIES. That is the
                 * same fail-open posture `ModuleAvailability` takes and for the same stated reason:
                 * a guard that cannot reach its own flag must not refuse the whole surface.
                 */
                Log::warning('FeatureControl could not read a feature flag; treating it as not controlled.', [
                    'feature' => $key,
                    'exception' => $failure->getMessage(),
                ]);

                return null;
            }
        });
    }

    /** @return array<int, PlatformFeatureFlagTarget> */
    private static function targets(int $flagId): array
    {
        return self::memo('targets.' . $flagId, static function () use ($flagId): array {
            try {
                return PlatformFeatureFlagTarget::query()
                    ->where('feature_flag_id', $flagId)
                    ->get()
                    ->all();
            } catch (\Throwable $failure) {
                Log::warning('FeatureControl could not read a feature flag\'s targets.', [
                    'feature_flag_id' => $flagId,
                    'exception' => $failure->getMessage(),
                ]);

                return [];
            }
        });
    }

    /**
     * A WRITE TO AN ABSENT CONTROL PLANE THROWS, WHERE A READ ANSWERS `NotControlled`.
     *
     * The asymmetry is deliberate and is the only safe pair. A READ must survive a database with no
     * control plane, because the migrations are "approved for development and isolated database
     * testing" and are not applied without the owner's authorisation — so every caller has to work
     * on both. A WRITE on such a database has not happened, and returning quietly would tell an
     * operator their kill switch is armed when nothing was stored. The message names the migration
     * rather than the table, because "run this" is the actionable half.
     */
    private static function assertWritable(): void
    {
        if (self::tablesExist()) {
            return;
        }

        throw new \RuntimeException(
            'The platform feature control tables are not present, so this change cannot be stored. '
            . 'Apply 2026_11_26_000100_create_platform_feature_control_tables (and '
            . '2026_11_26_000200_create_platform_control_changes_table for the change log). '
            . 'Reads answer FeatureDecision::NotControlled on such a database by design; a write '
            . 'refuses rather than reporting a switch it did not store.'
        );
    }

    /**
     * THE TABLES MAY NOT EXIST, and that is a supported state rather than a broken install.
     *
     * The migrations are "approved for development and isolated database testing" and are NOT
     * applied to production without the owner's separate authorisation -- CLAUDE.md reserves a
     * persistent migration to the owner. So first-party code may call this class on a database that
     * has no control plane, and the answer must be `NotControlled` everywhere rather than a 500.
     * `PackageServiceProvider:44` and `ModuleAvailability` guard the same way for the same reason.
     *
     * Memoised: `Schema::hasTable()` is a query, and this is on the hot path of every decision.
     */
    private static function tablesExist(): bool
    {
        return (bool) self::memo('schema', static function (): bool {
            try {
                return Schema::hasTable('platform_feature_flags')
                    && Schema::hasTable('platform_feature_flag_targets')
                    && Schema::hasTable('platform_pilot_group_members');
            } catch (\Throwable) {
                return false;
            }
        });
    }

    /**
     * Lower case, trimmed. Applied to every key, target type and pilot group on BOTH the read and
     * the write side.
     *
     * ONE NORMALISATION, TWO SIDES, because a key normalised on only one of them is worse than no
     * normalisation at all: MySQL's default collation is case-insensitive and SQLite's `=` is not,
     * so `Early-Access` and `early-access` would be ONE group in production and TWO under the
     * suite. CLAUDE.md records that SQLite's affinity hiding a real MySQL difference is how
     * P01-SEC-13 survived.
     */
    private static function normaliseKey(string $value): string
    {
        return strtolower(trim($value));
    }

    /**
     * The container-scoped memo. See the class docblock for why it is the container and not a
     * `static`, and why it is `scoped()` and not `instance()`.
     */
    private static function memo(string $slot, \Closure $resolve): mixed
    {
        if (! app()->bound(self::MEMO)) {
            /*
             * `bound()` GUARDS THE REGISTRATION, NOT THE VALUE -- `activated_modules_for_owner`
             * records the same mechanic. After a scope flush the binding is still bound while its
             * resolved instance is gone, so `app()` re-runs this closure and we get a fresh bag.
             */
            app()->scoped(self::MEMO, fn () => new \ArrayObject());
        }

        /** @var \ArrayObject<string, mixed> $bag */
        $bag = app(self::MEMO);

        if (! $bag->offsetExists($slot)) {
            $bag->offsetSet($slot, $resolve());
        }

        return $bag->offsetGet($slot);
    }
}
