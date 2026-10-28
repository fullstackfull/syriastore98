<?php

namespace App\Http\Controllers;

use App\Models\SavedView;
use App\Policies\SavedViewPolicy;
use App\Services\TableFilterMemory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * P2-04-9 / WS E3 — saving, restoring and deleting a merchant employee's named filter views.
 *
 * EVERY HANDLER DERIVES OWNERSHIP FROM THE AUTHENTICATED PRINCIPAL AND NEVER FROM REQUEST INPUT.
 * The screen comes from the request because it names which list the view belongs to, but it is
 * matched against `TableFilterMemory::screens()` before anything else, and the store and the
 * employee come from `getCurrentStore()` and `Auth::id()`. CLAUDE.md names a tenant predicate
 * built from caller input as this codebase's most productive defect class, so there is none here.
 *
 * REFUSALS ARE REDIRECTS WITH A MESSAGE, matching every other merchant screen. No 401: these are
 * ordinary commercial refusals and a 401 would clear the Flutter app's session.
 */
class SavedViewController extends Controller
{
    /**
     * The screen a request is talking about, or null.
     *
     * VALIDATED AGAINST THE VOCABULARY'S OWN KEYS, so an unknown screen cannot reach the policy
     * (which would answer false) or the database (which would store an unusable row).
     */
    private function screenFrom(Request $request): ?string
    {
        $screen = $request->input('screen');

        if (! is_string($screen) || ! in_array($screen, TableFilterMemory::screens(), true)) {
            return null;
        }

        return $screen;
    }

    public function store(Request $request)
    {
        $screen = $this->screenFrom($request);
        $user = Auth::user();
        $policy = new SavedViewPolicy();

        if ($screen === null || $user === null || ! $policy->create($user, $screen)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $validator = \Validator::make($request->all(), [
            // 60 is the column width. A name is a label, not a note.
            'name' => 'required|string|max:60',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->getMessageBag()->first());
        }

        /*
         * THE FILTERS ARE TAKEN FROM THE SCREEN'S VOCABULARY, not from whatever the form posted.
         * `sanitiseFilters()` keeps only the keys that screen recognises and drops arrays,
         * objects, booleans and empty values -- so a hand-crafted post cannot store a `?page=` or
         * a `?redirect=` that a later restore would replay into the URL.
         */
        $filters = SavedView::sanitiseFilters($screen, (array) $request->input('filters', []));

        if ($filters === []) {
            return redirect()->back()->with('error', __('Select at least one filter before saving a view.'));
        }

        $name = trim((string) $request->input('name'));
        $storeId = getCurrentStore();

        // One name per employee per screen, enforced by a unique index. Re-saving the same name
        // UPDATES rather than failing: the merchant's intent is "this is what that view means now".
        $existing = SavedView::forCurrentPrincipal($screen)->where('name', $name)->first();

        if ($existing !== null) {
            if (! $user->can('update', $existing)) {
                return redirect()->back()->with('error', __('Permission denied.'));
            }

            $existing->filters = $filters;
            $existing->save();

            return redirect()->back()->with('success', __('Saved view updated.'));
        }

        $view = new SavedView();
        $view->screen = $screen;
        $view->name = $name;
        $view->filters = $filters;
        // OWNERSHIP, set as properties from the principal. Neither column is fillable, so no
        // mass assignment can place this row in another store or under another employee.
        $view->store_id = $storeId;
        $view->created_by = Auth::id();
        $view->save();

        return redirect()->back()->with('success', __('Saved view created.'));
    }

    /**
     * Apply a saved view: redirect to its screen carrying its filters as the URL parameters the
     * list already reads. The view supplies only VALUES; the store predicate stays with the
     * authenticated principal, which is what makes a shared link safe.
     */
    public function apply(Request $request, $id)
    {
        $user = Auth::user();
        $view = SavedView::find($id);

        if ($user === null || $view === null || ! $user->can('view', $view)) {
            // The same answer for "not yours" and "does not exist", so an id probe learns nothing.
            Log::warning('Saved view apply refused: not the owner or no such view', [
                'saved_view_id' => $id,
                'user_id' => $user?->id,
            ]);

            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $route = $view->screen === 'order' ? 'order.index' : 'product.index';

        return redirect()->route($route, $view->appliedFilters() + [TableFilterMemory::APPLY => 1]);
    }

    public function destroy(Request $request, $id)
    {
        $user = Auth::user();
        $view = SavedView::find($id);

        if ($user === null || $view === null || ! $user->can('delete', $view)) {
            Log::warning('Saved view delete refused: not the owner or no such view', [
                'saved_view_id' => $id,
                'user_id' => $user?->id,
            ]);

            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $view->delete();

        return redirect()->back()->with('success', __('Saved view deleted.'));
    }
}
