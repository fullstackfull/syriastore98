<?php

namespace App\Support\Control;

use App\Models\PlatformControlChange;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * PHASE 06 `CTRL-003` — THE ONE DOOR EVERY PLATFORM-CONTROL CHANGE IS RECORDED THROUGH.
 * =================================================================================================
 *
 * *"سياسات عامة وإعلانات صيانة وسجل تغييرات"* — public policies, maintenance announcements and a
 * change log. `DECISION-ID-REGISTER.md:251`. This class is the third limb; the other two are named
 * in `database/migrations/2026_11_26_000200_create_platform_control_changes_table.php`'s docblock
 * and in `docs/development/phase-6/PHASE-06-FEATURE-CONTROL.md`.
 *
 * WHY IT TAKES AN OPTIONAL ACTOR INSTEAD OF READING `auth()`. `AuditLog::record()`'s own docblock
 * records the same decision for the same reason: a change made from a console command, a seeder or a
 * queued job has no authenticated user, and a recorder that could only see `auth()` would silently
 * log those as nobody. Passing the actor also lets a caller record the SUBJECT of a change where
 * that differs from the person making it — the divergence that produced the `assignPlan()` defect
 * in `PHASE-06-SAAS-LIFECYCLE-FINDINGS.md` §4.
 *
 * WHY IT NEVER THROWS. A change log that can refuse a change is a change log that can stop the
 * platform being controlled. `FeatureControl` writes the flag FIRST and records SECOND, so a
 * recorder failure costs a log entry and not an availability decision — and the failure is logged
 * at `warning` rather than swallowed, which is the balance `ModuleAvailability` struck for its own
 * fail-open branch after a review found it silent.
 *
 * THE COST OF THAT CHOICE, STATED: a change can therefore be made and not recorded. The alternative
 * — refusing the change — means a database hiccup could leave a feature switched on that an operator
 * is trying to switch off, and for a kill switch that is the worse failure. If the owner wants the
 * stricter contract, it is a one-line change here and an owner decision, not an engineering default.
 */
final class PlatformChangeLog
{
    public const SUBJECT_FEATURE_FLAG = 'feature_flag';
    public const SUBJECT_FEATURE_TARGET = 'feature_flag_target';
    public const SUBJECT_PILOT_GROUP = 'pilot_group';

    public const SUBJECTS = [
        self::SUBJECT_FEATURE_FLAG,
        self::SUBJECT_FEATURE_TARGET,
        self::SUBJECT_PILOT_GROUP,
    ];

    public const ACTION_DEFINED = 'defined';
    public const ACTION_ENABLED = 'enabled';
    public const ACTION_DISABLED = 'disabled';
    public const ACTION_ROLLOUT_CHANGED = 'rollout_changed';
    public const ACTION_TARGET_ADDED = 'target_added';
    public const ACTION_TARGET_WITHDRAWN = 'target_withdrawn';
    public const ACTION_MEMBER_ADDED = 'member_added';
    public const ACTION_MEMBER_REMOVED = 'member_removed';

    public const ACTIONS = [
        self::ACTION_DEFINED,
        self::ACTION_ENABLED,
        self::ACTION_DISABLED,
        self::ACTION_ROLLOUT_CHANGED,
        self::ACTION_TARGET_ADDED,
        self::ACTION_TARGET_WITHDRAWN,
        self::ACTION_MEMBER_ADDED,
        self::ACTION_MEMBER_REMOVED,
    ];

    /**
     * Record one change. Returns the entry, or null when it could not be written.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public static function record(
        string $subjectType,
        string $subjectKey,
        string $action,
        ?array $before = null,
        ?array $after = null,
        mixed $actor = null,
        ?string $note = null
    ): ?PlatformControlChange {
        try {
            /*
             * MEMBERSHIP IS GUARDED HERE AND NOT BY THE COLUMN. The migration's docblock records
             * why the columns are plain strings (an enum needs a migration to extend, and
             * production's non-strict sql_mode truncates rather than refuses). An unknown value
             * is still WRITTEN -- it is logged and kept, not dropped -- because losing the record
             * of a change is worse than recording one whose vocabulary a later reader has to
             * look up.
             */
            if (! in_array($subjectType, self::SUBJECTS, true)) {
                Log::warning('PlatformChangeLog recorded an unknown subject type.', [
                    'subject_type' => $subjectType,
                    'subject_key' => $subjectKey,
                    'action' => $action,
                ]);
            }

            if (! in_array($action, self::ACTIONS, true)) {
                Log::warning('PlatformChangeLog recorded an unknown action.', [
                    'subject_type' => $subjectType,
                    'subject_key' => $subjectKey,
                    'action' => $action,
                ]);
            }

            [$actorId, $actorName] = self::resolveActor($actor);

            return PlatformControlChange::create([
                'subject_type' => $subjectType,
                'subject_key' => $subjectKey,
                'action' => $action,
                'before' => $before,
                'after' => $after,
                'actor_id' => $actorId,
                'actor_name' => $actorName,
                'note' => $note === null ? null : mb_substr($note, 0, 255),
            ]);
        } catch (\Throwable $failure) {
            Log::warning('PlatformChangeLog could not record a platform control change.', [
                'subject_type' => $subjectType,
                'subject_key' => $subjectKey,
                'action' => $action,
                'exception' => $failure->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The entries for one subject, newest first.
     *
     * @return \Illuminate\Support\Collection<int, PlatformControlChange>
     */
    public static function forSubject(string $subjectType, string $subjectKey, int $limit = 50): \Illuminate\Support\Collection
    {
        return PlatformControlChange::query()
            ->where('subject_type', $subjectType)
            ->where('subject_key', $subjectKey)
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * The platform-wide feed, newest first.
     *
     * ORDERED BY `id` AND NOT BY `created_at`. Several changes made in one request share a
     * timestamp to the second, and `created_at DESC` would then return them in an order the
     * database chooses — so a screen would show "rollout changed to 50%" above "flag defined".
     * The index on `created_at` is still the right one for a date filter; it is the TIE that `id`
     * breaks.
     *
     * @return \Illuminate\Support\Collection<int, PlatformControlChange>
     */
    public static function recent(int $limit = 50): \Illuminate\Support\Collection
    {
        return PlatformControlChange::query()
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * @return array{0: int|null, 1: string|null}
     */
    private static function resolveActor(mixed $actor): array
    {
        if ($actor === null) {
            $actor = auth()->user();
        }

        if ($actor instanceof User) {
            return [(int) $actor->getKey(), self::nameOf($actor)];
        }

        /*
         * A BARE ID IS ACCEPTED, AND IS LOOKED UP RATHER THAN STORED NAMELESS. A console command
         * that knows only an id would otherwise write a row no human can read.
         */
        if (is_int($actor) || (is_string($actor) && ctype_digit($actor))) {
            $found = User::withoutGlobalScopes()->find((int) $actor);

            return [(int) $actor, $found instanceof User ? self::nameOf($found) : null];
        }

        /*
         * ANY OTHER PRINCIPAL -- a `Customer`, a `DeliveryBoy` -- IS RECORDED WITHOUT AN ID.
         * CLAUDE.md records that `users.type` and `customers.type` are different columns on
         * different tables, and that a `Customer` typed `super admin` passed a gate that read
         * `->type` without asking what class it was. Writing a `Customer`'s primary key into
         * `actor_id` would make the log say a User did it. The class name is kept instead, because
         * "a Customer reached this" is itself worth seeing in a change log.
         */
        if (is_object($actor)) {
            return [null, 'non-user principal: ' . $actor::class];
        }

        return [null, null];
    }

    private static function nameOf(User $user): ?string
    {
        $name = trim((string) ($user->getAttribute('name') ?? ''));

        if ($name !== '') {
            return mb_substr($name, 0, 191);
        }

        $email = trim((string) ($user->getAttribute('email') ?? ''));

        return $email === '' ? null : mb_substr($email, 0, 191);
    }
}
