<?php

namespace App\Http\Controllers;

use App\Models\PlatformFeatureFlag;
use App\Models\PlatformFeatureFlagTarget;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Support\Control\FeatureControl;
use App\Support\Control\FeatureControlOverview;
use Illuminate\Http\Request;

/**
 * PHASE 06 `CTRL-001` / `CTRL-002` / `CTRL-003` — THE OPERATOR'S FEATURE CONTROL SCREEN.
 * =================================================================================================
 *
 * A CONTROL PLANE NOBODY CAN REACH IS NOT DELIVERED. `OperatorDashboardPlatformLinksTest`'s own
 * docblock records the shape this avoids: three platform screens that shipped reachable only by
 * typing their URLs. `FeatureControl` is callable from code, but `CTRL-001` and `CTRL-002` are
 * about what the PLATFORM OPERATOR can do — enable by plan, store and pilot group; widen a release;
 * switch it off safely — and none of that is a capability until a person can do it.
 *
 * -------------------------------------------------------------------------------------------------
 * THE GATE IS IDENTITY, NOT A PERMISSION, and the reasoning is `PlatformAnalyticsController`'s and
 * `SuperAdminResourcesController`'s verbatim because the situation is identical: a merchant creates
 * their own roles through the product, so "holds permission X" is substantially self-assignable,
 * while `users.type` is not. CLAUDE.md records that `isAbleTo()` alone REFUSES the platform
 * operator — there is no super-admin bypass in Laratrust here — so a permission gate would be wrong
 * in both directions at once. The subject of this screen is which features reach WHICH TENANTS, so
 * operator-first collapses to operator-only.
 *
 * Every route carries `PlatformOperatorOnly`, and every action repeats the test. Not a second
 * spelling of it: `refuseAnyoneButTheOperator()` is copied character-for-character from
 * `SuperAdminResourcesController`, because a second SPELLING of an operator test is how
 * `PlatformOperatorOnly` came to admit `' super admin'` while every other check refused it.
 * `instanceof User` comes FIRST and the order is the control — `customers` has a `type` column with
 * `'type'` in `Customer::$fillable`, and CLAUDE.md records a `Customer` typed `super admin`
 * reaching a handler and answering 200.
 *
 * -------------------------------------------------------------------------------------------------
 * NO STORE IS RESOLVED FROM THE SESSION, ANYWHERE IN THIS FILE.
 *
 * `getCurrentStore()` answers the hardcoded literal 1 for a `super admin`, so a handler that asked
 * would silently attribute one tenant's situation to the platform — the same trap
 * `SuperAdminResourcesController` records. The store a target names arrives as an explicit integer
 * from the form and is used as a VALUE, never dereferenced: `FeatureControl` compares
 * `target_key` against the subject's own store id and never loads the row. So a form naming a store
 * that does not exist creates a target that matches nothing, which is inert rather than a
 * cross-tenant read. That is why there is no existence check here and no route-model binding: there
 * is nothing to bind, and CLAUDE.md records that `Store` is NOT in `config/tenancy.php`'s
 * `scoped_models`, so a bound `Store` would be a cross-tenant lookup with no scope to stop it.
 *
 * -------------------------------------------------------------------------------------------------
 * THE THREE WRITE ROUTES DROP `xss`, AND THE ANALYSIS IS IN `routes/web.php` BESIDE THEM.
 *
 * The short form, because it is a real defect this change had and fixed rather than a preference:
 * `XSS` deletes `['&lt;', '&gt;', 'javascript', 'alert']` from every string it sees, so
 * `stock-alerts` ARRIVES as `stock-s`. An operator would declare a flag, be told it worked, and the
 * code asking for the key they typed would answer `NotControlled` for ever. Nothing is given up:
 * `key`, `value` and `group` are validated against `^[A-Za-z0-9._-]+$`, which cannot express
 * markup; the only prose is `label` and `description`, printed in one place through Blade `{{ }}`,
 * and a test proves that escaping rather than asserting it.
 *
 * THE SCREEN STILL DOES NOT COLLECT THE CHANGE LOG'S FREE-TEXT NOTE, and that remains a decision.
 * `FeatureControl`'s writers all accept a `$note` and the change log stores it; this screen passes
 * none. A note is a sentence an operator writes under pressure during an incident, and the right
 * input for one is a textarea on a form that also shows them what they are about to switch off —
 * a screen this slice does not have. The log records who, what, before and after, which is the
 * audit content; a code caller can still supply a note. Adding the field is a follow-on, not a
 * middleware question any more.
 *
 * -------------------------------------------------------------------------------------------------
 * EVERY ACTION REDIRECTS BACK WITH A STATUS AND NEVER RENDERS. One read route, three write routes,
 * and the writes are POSTs so a crawled link or a stale bookmark cannot switch a feature off.
 */
class PlatformFeatureControlController extends Controller
{
    public function index(Request $request)
    {
        $this->refuseAnyoneButTheOperator();

        return view('platform.feature_control', [
            'features' => FeatureControlOverview::features(),
            'pilotGroups' => FeatureControlOverview::pilotGroups(),
            'changes' => FeatureControlOverview::recentChanges(),
            'axes' => FeatureControlOverview::axes(),
            'effects' => FeatureControlOverview::effects(),
        ]);
    }

    /**
     * Declare a feature, or move its availability.
     *
     * `declare` IS A SEPARATE ACTION FROM THE THREE TRANSITIONS even though `FeatureControl`'s
     * writers would create the flag anyway. A screen on which "set the rollout to 10%" silently
     * invents a feature whose key the operator mistyped is a screen that accumulates dead flags, so
     * the three transitions require the key to exist and say so when it does not.
     */
    public function state(Request $request)
    {
        $this->refuseAnyoneButTheOperator();

        $validated = $request->validate([
            'action' => 'required|string|in:declare,disable,release,release_fully',
            /*
             * THE KEY'S CHARACTER SET IS NARROWED, and not for storage safety -- Eloquent binds,
             * and the column is a plain varchar. It is narrowed because the key is an IDENTIFIER
             * that first-party code types as a literal: a key containing a space, a quote or an
             * Arabic letter is one somebody will mistype at the call site for ever, and a flag
             * nothing can ask for is a flag that reaches nobody while LOOKING configured. 64 is the
             * column's own width.
             */
            'key' => 'required|string|max:64|regex:/^[A-Za-z0-9._-]+$/',
            'label' => 'nullable|string|max:120',
            'description' => 'nullable|string|max:2000',
            /*
             * `integer` AND `between`, with the service clamping as well. Belt and braces for a
             * measured reason: `unsignedTinyInteger` holds 150 happily, and production runs
             * non-strict `sql_mode`, where a larger value is TRUNCATED to 255 rather than refused.
             * The column is not the guard.
             */
            'percentage' => 'nullable|integer|between:0,100',
        ]);

        $key = (string) $validated['key'];
        $action = (string) $validated['action'];
        $actor = auth()->user();

        if ($action === 'declare') {
            /*
             * ASKED BEFORE THE WRITE, BECAUSE THE TWO OUTCOMES DESERVE DIFFERENT SENTENCES.
             *
             * `FeatureControl::define()` is idempotent and deliberately does NOT reset a live
             * feature's state -- a deploy step that re-declares every flag must not switch the
             * platform off. So re-declaring an already-released feature refreshes its name and
             * nothing else, and telling the operator it is "switched off until it is released"
             * would be a small lie about a feature that is live right now. Small lies on an
             * operator screen are how somebody comes to believe a kill switch is armed.
             */
            $alreadyDeclared = $this->isDeclared($key);

            FeatureControl::define(
                $key,
                /*
                 * THE LABEL FALLS BACK TO THE KEY, not to a placeholder. An unnamed feature with a
                 * readable key is usable; one labelled "Untitled" is a row somebody has to open to
                 * identify.
                 */
                trim((string) ($validated['label'] ?? '')) !== '' ? (string) $validated['label'] : $key,
                $validated['description'] ?? null,
                $actor
            );

            return redirect()->route('platform.feature_control.index')->with(
                'success',
                $alreadyDeclared
                    ? __('That feature was already declared, so its name and description are updated and its availability is left exactly as it was.')
                    : __('The feature is declared and switched off until it is released.')
            );
        }

        if (! $this->isDeclared($key)) {
            return redirect()->route('platform.feature_control.index')
                ->with('error', __('No feature carries that key. Declare it before releasing it.'));
        }

        if ($action === 'disable') {
            FeatureControl::disable($key, $actor);

            return redirect()->route('platform.feature_control.index')
                ->with('success', __('The feature is switched off. Its pilot groups, named exceptions and rollout share are kept, so resuming it restores the same reach.'));
        }

        if ($action === 'release_fully') {
            FeatureControl::releaseFully($key, $actor);

            return redirect()->route('platform.feature_control.index')
                ->with('success', __('The feature now reaches every store, except any that are explicitly refused.'));
        }

        FeatureControl::release($key, (int) ($validated['percentage'] ?? 0), $actor);

        return redirect()->route('platform.feature_control.index')
            ->with('success', __('The rollout share is updated. Widening it later only adds stores; none of the stores that already have the feature lose it.'));
    }

    /** Add, change or withdraw a named exception on one of `CTRL-001`'s three axes. */
    public function target(Request $request)
    {
        $this->refuseAnyoneButTheOperator();

        $validated = $request->validate([
            'action' => 'required|string|in:add,withdraw',
            'key' => 'required|string|max:64|regex:/^[A-Za-z0-9._-]+$/',
            /*
             * THE AXIS AND THE EFFECT ARE VALIDATED AGAINST THE MODEL'S OWN CONSTANTS, not against
             * a list retyped here. `CTRL-001` names exactly three axes and a fourth would be an
             * invented requirement; keeping the vocabulary in one place is what makes
             * `FeatureControlResolutionTest::test_the_target_axes_are_exactly_the_three_the_
             * decision_names` a check on the whole application rather than on one file.
             */
            'axis' => 'required|string|in:' . implode(',', PlatformFeatureFlagTarget::TARGET_TYPES),
            'effect' => 'required|string|in:' . implode(',', PlatformFeatureFlagTarget::EFFECTS),
            'value' => 'required|string|max:64|regex:/^[A-Za-z0-9._-]+$/',
        ]);

        $key = (string) $validated['key'];
        $actor = auth()->user();

        if (! $this->isDeclared($key)) {
            return redirect()->route('platform.feature_control.index')
                ->with('error', __('No feature carries that key. Declare it before releasing it.'));
        }

        if ($validated['action'] === 'withdraw') {
            $removed = FeatureControl::withdraw($key, (string) $validated['axis'], (string) $validated['value'], $actor);

            return redirect()->route('platform.feature_control.index')->with(
                $removed ? 'success' : 'error',
                $removed
                    ? __('The named exception is withdrawn.')
                    : __('There is no such named exception to withdraw.')
            );
        }

        if ($validated['effect'] === PlatformFeatureFlagTarget::EFFECT_DENY) {
            FeatureControl::deny($key, (string) $validated['axis'], (string) $validated['value'], $actor);

            return redirect()->route('platform.feature_control.index')
                ->with('success', __('That plan, store or pilot group is now refused this feature, whatever its availability says.'));
        }

        FeatureControl::allow($key, (string) $validated['axis'], (string) $validated['value'], $actor);

        return redirect()->route('platform.feature_control.index')
            ->with('success', __('That plan, store or pilot group now reaches this feature while it is limited.'));
    }

    /** `CTRL-001`'s third axis: who is in a pilot group. */
    public function pilot(Request $request)
    {
        $this->refuseAnyoneButTheOperator();

        $validated = $request->validate([
            'action' => 'required|string|in:add,remove',
            'group' => 'required|string|max:64|regex:/^[A-Za-z0-9._-]+$/',
            /*
             * `min:1`, SO `0` IS REFUSED RATHER THAN STORED. A membership row for store 0 matches
             * no subject -- `subject()` returns a null store for a non-positive id -- so it would
             * sit in the table looking like a configured pilot member and reach nobody.
             */
            'store_id' => 'required|integer|min:1',
        ]);

        $group = (string) $validated['group'];
        $storeId = (int) $validated['store_id'];
        $actor = auth()->user();

        if ($validated['action'] === 'remove') {
            $removed = FeatureControl::removeFromPilotGroup($group, $storeId, $actor);

            return redirect()->route('platform.feature_control.index')->with(
                $removed ? 'success' : 'error',
                $removed
                    ? __('The store is no longer in that pilot group.')
                    : __('That store is not in that pilot group.')
            );
        }

        FeatureControl::addToPilotGroup($group, $storeId, $actor);

        return redirect()->route('platform.feature_control.index')
            ->with('success', __('The store is in that pilot group.'));
    }

    private function isDeclared(string $key): bool
    {
        return PlatformFeatureFlag::query()
            ->where('key', strtolower(trim($key)))
            ->exists();
    }

    /**
     * DEFENCE IN DEPTH, AND NOT A SECOND SPELLING OF THE ROUTE GATE.
     *
     * Copied character-for-character from `SuperAdminResourcesController::refuseAnyoneButTheOperator()`
     * and `PlatformContentController`'s, deliberately. `RouteCollection::addToCollections()` keys on
     * method+domain+uri, so a later declaration of the same verb and URI REPLACES the earlier one
     * and silently drops its middleware — `GAP-268` found a real duplicated declaration in
     * `routes/web.php`. Without this method, that day is a merchant switching platform features
     * on and off rather than a caught regression.
     *
     * `instanceof User` FIRST, and the ORDER is the control: `UserPolicy::isOperator()` takes an
     * untyped `$actor` and reads `$actor->type`, and `customers` HAS a `type` column with `'type'`
     * in `Customer::$fillable`. `instanceof` short-circuits, so the helper never reads `->type` off
     * a non-`User` at all.
     */
    private function refuseAnyoneButTheOperator(): void
    {
        $user = auth()->user();

        if (! $user instanceof User || ! UserPolicy::isOperator($user)) {
            abort(403);
        }
    }
}
