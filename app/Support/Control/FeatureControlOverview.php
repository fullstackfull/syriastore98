<?php

namespace App\Support\Control;

use App\Models\PlatformFeatureFlag;
use App\Models\PlatformFeatureFlagTarget;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 06 `CTRL-001` / `CTRL-002` / `CTRL-003` — WHAT THE OPERATOR'S CONTROL SCREEN IS SHOWN.
 * =================================================================================================
 *
 * PURE DATA, NO SENTENCES. Every value this class returns is a scalar, an array or an enum-ish
 * string key; not one of them is human-readable text. That is deliberate and it is asserted:
 * `FeatureControlMessagesAreDeclaredTest` declares this class a SILENT surface, so the day a label
 * appears here the census fails. The screen's labels belong in the view and its outcomes in the
 * controller's own flash messages — both places `__()` can be seen by the same census that checks
 * each key is declared in BOTH `en.json` and `ar.json`. CLAUDE.md records that `__()` returns its
 * own key when nothing is declared, so an undeclared sentence reads correctly in English by
 * accident and reads IN ENGLISH on the Arabic page.
 *
 * (`SaasPlatformResources` does the opposite and carries its sentences, for a reason that does not
 * apply here: its strings are measurement CAVEATS that belong beside the measurement. These are
 * plain UI labels.)
 *
 * WHY A CLASS AND NOT A FEW LINES IN THE CONTROLLER. So the composition can be tested without an
 * HTTP request — and, more to the point, so the controller stays a dispatch layer. A handler that
 * both composes and renders is one where a reviewer cannot tell which half a defect is in.
 *
 * THE DERIVED FIELDS, AND WHY EACH EXISTS RATHER THAN BEING COMPUTED IN BLADE:
 *
 *   `reach`            one of `off` | `everyone` | `targets_only` | `share` | `share_and_targets`
 *                      — the single statement a reader needs, resolved from the state, the width
 *                      and whether any allow-target exists. In Blade this would be a nested
 *                      ternary nobody could test.
 *   `allowed`/`refused` the counts, so the screen can say "three stores are refused this" without
 *                      the view iterating the targets twice.
 *   `targets`          flattened to scalars, grouped by axis, so the view never dereferences a
 *                      model and cannot accidentally print something it was not given.
 */
final class FeatureControlOverview
{
    /**
     * Every declared feature, with its reach and its named exceptions.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function features(): array
    {
        if (! self::tablesExist()) {
            return [];
        }

        $flags = PlatformFeatureFlag::query()->orderBy('key')->get();

        if ($flags->isEmpty()) {
            return [];
        }

        /*
         * ONE QUERY FOR EVERY FLAG'S TARGETS, grouped in PHP. A per-flag query inside the loop is
         * the N+1 this screen would otherwise ship with -- and with a flag per feature the platform
         * ever controls, N grows for ever.
         */
        $targets = PlatformFeatureFlagTarget::query()
            ->whereIn('feature_flag_id', $flags->pluck('id')->all())
            ->orderBy('target_type')
            ->orderBy('target_key')
            ->get()
            ->groupBy('feature_flag_id');

        return $flags->map(function (PlatformFeatureFlag $flag) use ($targets): array {
            $own = $targets->get($flag->getKey(), collect());

            $allowed = $own->where('effect', PlatformFeatureFlagTarget::EFFECT_ALLOW);
            $refused = $own->where('effect', PlatformFeatureFlagTarget::EFFECT_DENY);

            return [
                'key' => (string) $flag->key,
                'label' => (string) $flag->label,
                'description' => $flag->description === null ? null : (string) $flag->description,
                'state' => $flag->resolvedState(),
                'percentage' => $flag->resolvedPercentage(),
                'reach' => self::reach($flag, $allowed->isNotEmpty()),
                'allowed' => $allowed->count(),
                'refused' => $refused->count(),
                'targets' => $own->map(fn (PlatformFeatureFlagTarget $t): array => [
                    'type' => (string) $t->target_type,
                    'value' => (string) $t->target_key,
                    'effect' => (string) $t->effect,
                ])->values()->all(),
                /*
                 * THE STORED STATE AS WELL AS THE RESOLVED ONE. `resolvedState()` reports anything
                 * it does not recognise as `off`, which is the right answer for the RESOLVER and
                 * the wrong one for a screen: an operator debugging a feature that is refusing
                 * everybody needs to see that the column holds something unexpected, not a
                 * confident "Switched off". The view prints the raw value only when the two differ.
                 */
                'stored_state' => (string) $flag->getAttribute('state'),
            ];
        })->values()->all();
    }

    /**
     * `pilot_group => member count`, plus the stores in each, for the membership table.
     *
     * @return array<int, array{group: string, stores: array<int, int>}>
     */
    public static function pilotGroups(): array
    {
        $groups = [];

        foreach (array_keys(FeatureControl::pilotGroups()) as $group) {
            $groups[] = ['group' => $group, 'stores' => FeatureControl::pilotGroupMembers($group)];
        }

        return $groups;
    }

    /**
     * The change log feed, flattened to scalars.
     *
     * `before` and `after` are rendered as `key=value` pairs rather than raw JSON, because the only
     * two shapes this table ever holds are a flag snapshot (`state`, `rollout_percentage`) and a
     * target (`target_type`, `target_key`, `effect`) — and `{"state":"off","rollout_percentage":50}`
     * on a screen is an operator reading JSON when they wanted a sentence.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function recentChanges(int $limit = 25): array
    {
        if (! Schema::hasTable('platform_control_changes')) {
            return [];
        }

        return PlatformChangeLog::recent($limit)->map(fn ($entry): array => [
            'at' => $entry->created_at,
            'subject_type' => (string) $entry->subject_type,
            'subject_key' => (string) $entry->subject_key,
            'action' => (string) $entry->action,
            'before' => self::flatten($entry->before),
            'after' => self::flatten($entry->after),
            'actor_name' => $entry->actor_name === null ? null : (string) $entry->actor_name,
            'note' => $entry->note === null ? null : (string) $entry->note,
        ])->all();
    }

    /** The axes and effects a form may offer — read from the model, never retyped in Blade. */
    public static function axes(): array
    {
        return PlatformFeatureFlagTarget::TARGET_TYPES;
    }

    public static function effects(): array
    {
        return PlatformFeatureFlagTarget::EFFECTS;
    }

    private static function reach(PlatformFeatureFlag $flag, bool $hasAllowTargets): string
    {
        $state = $flag->resolvedState();

        if ($state === PlatformFeatureFlag::STATE_OFF) {
            return 'off';
        }

        if ($state === PlatformFeatureFlag::STATE_ON) {
            return 'everyone';
        }

        $share = $flag->resolvedPercentage() > 0;

        return match (true) {
            $share && $hasAllowTargets => 'share_and_targets',
            $share => 'share',
            $hasAllowTargets => 'targets_only',
            /*
             * `limited`, 0%, NO ALLOW TARGET -- which reaches nobody, and is a state an operator
             * arrives at by declaring a feature and then setting a width before adding anybody.
             * It is reported as its own value rather than folded into `off`, because the two are
             * different facts: `off` is a deliberate kill switch and this is a release that has
             * not started. Folding them would hide the difference on the one screen that exists
             * to show it.
             */
            default => 'nobody_yet',
        };
    }

    /** @return array<int, string> `key=value`, in a stable order */
    private static function flatten(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $pairs = [];

        foreach ($payload as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $pairs[] = $key . '=' . ($value === null ? '-' : (string) $value);
            }
        }

        return $pairs;
    }

    private static function tablesExist(): bool
    {
        return Schema::hasTable('platform_feature_flags')
            && Schema::hasTable('platform_feature_flag_targets');
    }
}
