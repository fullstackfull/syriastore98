<?php

namespace App\Http\Controllers;

use App\Models\PlatformMaintenanceAnnouncement;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Support\Control\MaintenanceAnnouncements;
use App\Support\Control\PlatformChangeLog;
use Illuminate\Http\Request;

/**
 * PHASE 06 `CTRL-003` — THE OPERATOR WRITES, SCHEDULES AND WITHDRAWS A MAINTENANCE ANNOUNCEMENT.
 * =================================================================================================
 *
 * `CTRL-003`'s second limb. The table's docblock carries the schema decisions,
 * `MaintenanceAnnouncements` the resolution, `x-ss.maintenance-notice` the merchant's side, and
 * this is the operator's.
 *
 * TWO ROUTES, NOT SEVEN. One GET that lists everything and carries the form, one POST that takes
 * an `action`. A resource controller would put `create`, `edit` and `destroy` on the gate for no
 * gain: there is nothing to create on a separate page (the form is three fields and two dates),
 * and there is **no destroy at all** — `MaintenanceAnnouncements::withdraw()` unpublishes, because
 * the words the platform published and the times it published them are the record somebody asks
 * for after an incident. That is `CTRL-004`'s shape applied to this surface, and the absence of a
 * delete is asserted rather than left to be noticed.
 *
 * THE GATE IS IDENTITY, NOT A PERMISSION — `PlatformOperatorOnly` on the routes and the same test
 * again in both actions. The reasoning is `PlatformFeatureControlController`'s verbatim, and the
 * predicate is copied character-for-character from it for the reason that file states: a second
 * SPELLING of an operator test is how `PlatformOperatorOnly` came to admit `' super admin'` while
 * every other check refused it. `instanceof User` comes FIRST and the order is the control.
 *
 * NO STORE IS RESOLVED ANYWHERE IN THIS FILE. An announcement reaches every tenant, so there is no
 * subject to resolve — and `getCurrentStore()` answers the hardcoded literal 1 for a `super
 * admin`, so a handler that asked would silently attribute a platform announcement to one tenant.
 *
 * THE WRITE ROUTE DROPS `xss`, AND HERE IT IS THE `platform/site-content` CASE EXACTLY. This route's
 * entire purpose is to carry PROSE the operator typed about tonight's maintenance, and
 * `App\Http\Middleware\XSS` deletes `['&lt;', '&gt;', 'javascript', 'alert']` from every string it
 * sees — so "we are fixing the javascript error on the checkout page" would publish as "we are
 * fixing the  error on the checkout page", to every merchant on the platform, with nothing to say
 * it had happened. What stops markup reaching a reader is Blade's `{{ }}` in the component and on
 * this screen, and `MaintenanceAnnouncementTest` proves that escaping rather than asserting it.
 */
class PlatformMaintenanceController extends Controller
{
    public function index(Request $request)
    {
        $this->refuseAnyoneButTheOperator();

        return view('platform.maintenance', [
            'announcements' => MaintenanceAnnouncements::all(),
            'severities' => PlatformMaintenanceAnnouncement::SEVERITIES,
            /*
             * THIS SUBJECT'S CHANGES AND NOT THE WHOLE FEED. `PlatformChangeLog::recent()` would
             * put feature-flag rollouts on an announcements screen, where every row an operator
             * reads is one they have to decide is irrelevant. `forSubjectType()` exists for this
             * and takes no key, because an announcement has no name -- its `subject_key` is its id.
             */
            'changes' => PlatformChangeLog::forSubjectType(PlatformChangeLog::SUBJECT_ANNOUNCEMENT, 15),
        ]);
    }

    public function apply(Request $request)
    {
        $this->refuseAnyoneButTheOperator();

        $validated = $request->validate([
            'action' => 'required|string|in:save,publish,withdraw',
            'id' => 'nullable|integer|min:1',
            'title_en' => 'nullable|string|max:160',
            'title_ar' => 'nullable|string|max:160',
            'body_en' => 'nullable|string|max:20000',
            'body_ar' => 'nullable|string|max:20000',
            'severity' => 'nullable|string|in:' . implode(',', PlatformMaintenanceAnnouncement::SEVERITIES),
            /*
             * `date` AND NOT `date_format`, because the control is `datetime-local` and browsers
             * post `Y-m-d\TH:i` -- sometimes with seconds, sometimes without. A `date_format` rule
             * pinned to one of those shapes refuses the other browser, which is a refusal nobody
             * can reproduce. `after_or_equal` is the only ORDERING rule: an end before a start is
             * a window that can never open, and the operator should be told at the form rather
             * than discover it when no merchant sees the notice.
             */
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
        ]);

        $actor = auth()->user();
        $action = (string) $validated['action'];
        $id = isset($validated['id']) ? (int) $validated['id'] : null;

        if ($action === 'save') {
            /*
             * AT LEAST ONE TITLE, IN EITHER LANGUAGE, OR THERE IS NOTHING TO ANNOUNCE.
             *
             * Not a `required` rule on both: the resolver falls back from one language to the
             * other on purpose, so demanding both would refuse the 2am announcement this surface
             * exists for. But an announcement with no title in EITHER language is skipped by
             * `live()` entirely -- it would be a published row that reaches nobody and looks
             * configured, which is the shape this whole workstream keeps refusing at the door.
             */
            if (trim((string) ($validated['title_en'] ?? '')) === ''
                && trim((string) ($validated['title_ar'] ?? '')) === '') {
                return redirect()->route('platform.maintenance.index')
                    ->with('error', __('An announcement needs a title in at least one language, or no merchant can see it.'));
            }

            MaintenanceAnnouncements::save($validated, $actor, $id);

            return redirect()->route('platform.maintenance.index')->with(
                'success',
                $id === null
                    ? __('The announcement is saved as a draft. No merchant sees it until it is published.')
                    : __('The announcement is updated.')
            );
        }

        if ($id === null) {
            return redirect()->route('platform.maintenance.index')
                ->with('error', __('That announcement no longer exists.'));
        }

        if ($action === 'publish') {
            $done = MaintenanceAnnouncements::publish($id, $actor);

            return redirect()->route('platform.maintenance.index')->with(
                $done ? 'success' : 'error',
                $done
                    ? __('The announcement is published. Merchants see it while it is inside its window.')
                    : __('That announcement no longer exists.')
            );
        }

        $done = MaintenanceAnnouncements::withdraw($id, $actor);

        return redirect()->route('platform.maintenance.index')->with(
            $done ? 'success' : 'error',
            $done
                ? __('The announcement is withdrawn. Its wording and its dates are kept, so it can be published again.')
                : __('That announcement no longer exists.')
        );
    }

    /**
     * DEFENCE IN DEPTH, AND NOT A SECOND SPELLING OF THE ROUTE GATE.
     *
     * Copied character-for-character from `PlatformFeatureControlController` and
     * `SuperAdminResourcesController`. `RouteCollection::addToCollections()` keys on
     * method+domain+uri, so a later declaration of the same verb and URI REPLACES the earlier one
     * and silently drops its middleware — `GAP-268` found a real instance in `routes/web.php`.
     *
     * `instanceof User` FIRST, and the ORDER is the control: `UserPolicy::isOperator()` takes an
     * untyped `$actor` and reads `$actor->type`, and `customers` HAS a `type` column with `'type'`
     * in `Customer::$fillable`.
     */
    private function refuseAnyoneButTheOperator(): void
    {
        $user = auth()->user();

        if (! $user instanceof User || ! UserPolicy::isOperator($user)) {
            abort(403);
        }
    }
}
