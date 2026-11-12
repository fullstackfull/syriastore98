<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\Utility;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;
use App\DataTables\PageDataTable;

class PageController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(PageDataTable $dataTable)
    {
        if(auth()->user() && auth()->user()->isAbleTo('Manage Page'))
        {
            return $dataTable->render('page.index');
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('page.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        if(auth()->user() && auth()->user()->isAbleTo('Create Page'))
        {
            $validator = \Validator::make(
                $request->all(),
                [
                    'page_name' => 'required',
                    'page_slug' => 'required'
                ]
            );

            if($validator->fails())
            {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', implode(' ', $messages->all()));
            }

            $page = new Page();
            $page->page_name = $request->page_name;
            $page->page_slug = $request->page_slug;
            $page->page_content = $request->page_content;
            $page->page_meta_title = $request->page_meta_title;
            $page->page_meta_description = $request->page_meta_description;
            $page->page_meta_keywords = implode(',', $request->page_meta_keywords);
            $page->store_id = getCurrentStore();
            $page->save();

            return redirect()->back()->with('success', __('Page successfully created.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Page $page)
    {
        // P2.5-PKG-B. AN EMPTY BODY IS STILL AN EXISTENCE ORACLE. Route model binding resolves the
        // row BEFORE this method runs, and `Page` is absent from `config/tenancy.php`'s
        // `scoped_models`, so it resolves any tenant's id: the response was an empty 200 for a row
        // that exists in ANY store and a 404 only for one that exists nowhere. That difference
        // answers "does row N exist on this platform" for every id, to any authenticated
        // principal. The guard makes the two indistinguishable. Nothing in the repository
        // references this route -- `pages.show` has no callers in resources/, app/, routes/ or
        // packages/ -- so the route is left registered rather than removed: refusing what it must
        // not serve is a smaller change than withdrawing a URL some client may hold.

        if (! $this->callerOwnsPage($page)) {
            abort(404);
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    /**
     * P2.5-PKG-B. Does the caller own this page?
     *
     * `Page` registers no global scope and is absent from `config/tenancy.php`'s `scoped_models`,
     * so route model binding resolves ANY id in the table -- the bound instance is not filtered by
     * tenant. MEASURED in `SettingsAndContentCrossTenantTest` before this guard existed: a
     * merchant holding `Edit Menu` rewrote another store's storefront page, TOOK it, and a
     * merchant holding `Delete Page` deleted one.
     *
     * Fails closed when either side is absent. No operator limb, as on
     * `ProductBrandController::callerOwnsBrand()`.
     *
     * WHY THAT IS SAFE, STATED PRECISELY, because an earlier version of this line was true in
     * effect and wrong in mechanism. It is NOT that `isAbleTo()` structurally refuses the
     * operator; it is that `$superadminNewPermission` in `app/Models/Utility.php` contains no Tax
     * or Page permission, so the seeded `super admin` role holds none of them. If an operator's
     * role is ever given one through the role editor, this helper DOES fire -- and
     * `getCurrentStore()` returns the hardcoded literal 1 for a super admin with an empty
     * `current_store` (app/Helper/helper.php:775), so the operator would be silently scoped to
     * store 1 rather than refused. Recorded because the shorter sentence decays into a wrong
     * belief.
     */
    private function callerOwnsPage(?Page $page): bool
    {
        $user = auth()->user();

        if ($user === null || $page === null) {
            return false;
        }

        $currentStore = getCurrentStore($user->id);

        return ! empty($currentStore)
            && ! empty($page->store_id)
            && (int) $page->store_id === (int) $currentStore;
    }

    public function edit(Page $page)
    {
        // P2.5-PKG-B. The form now refuses exactly when the submit would: `update()` checks
        // `Edit Menu` and this checked nothing.
        if (! auth()->user() || ! auth()->user()->isAbleTo('Edit Menu')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $page = Page::find($page->id);

        // P2.5-PKG-B. This read had no ownership check either, so a merchant could open another
        // store's page -- content, meta description and all. 404 for a foreign or absent row.
        if (! $this->callerOwnsPage($page)) {
            abort(404);
        }

        $page_meta_keywords = explode(',', $page->page_meta_keywords);
        return view('page.edit', compact('page', 'page_meta_keywords'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Page $page)
    {
        if(auth()->user() && auth()->user()->isAbleTo('Edit Menu'))
        {
            // P2.5-PKG-B. The permission was checked and the owner was not, and the assignment
            // further down made that a TAKEOVER rather than an unauthorised edit. Above the
            // validator so a cross-store attempt is indistinguishable from a refusal.
            if (! $this->callerOwnsPage($page)) {
                abort(404);
            }

            $validator = \Validator::make(
                $request->all(),
                [
                    'page_name' => 'required',
                    'page_slug' => 'required',
                ]
            );

            if($validator->fails())
            {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', implode(' ', $messages->all()));
            }
            $page = Page::find($page->id);
            $page->page_name = $request->page_name;
            $page->page_slug = $request->page_slug;
            $page->page_content = $request->page_content;
            $page->page_meta_title = $request->page_meta_title;
            $page->page_meta_description = $request->page_meta_description;
            $page->page_meta_keywords = $request->page_meta_keywords;
            // THE ASSIGNMENT THAT USED TO BE HERE IS GONE, not merely guarded.
            // `$page->store_id = getCurrentStore();` was the takeover primitive: a foreign page
            // reaching it changed hands, removing it from the victim's storefront.
            // callerOwnsPage() above now guarantees the two values are already equal, so the line
            // was a no-op -- but leaving it means any future weakening of the guard re-opens a
            // TRANSFER rather than an unauthorised edit. The `store()` stamp at :64 is untouched:
            // there it sets the owner of a NEW row, which is correct.
            $page->save();
            if ($page) {
                return redirect()->back()->with(['success' => __('Page updated successfully.')]);
            } else {
                return redirect()->back()->with(['error' => __('Someting went wrong.')]);
            }
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Page $page)
    {
        if(auth()->user() && auth()->user()->isAbleTo('Delete Page'))
        {
            // P2.5-PKG-B. Permission was checked, owner was not. The guard precedes the delete.
            if (! $this->callerOwnsPage($page)) {
                abort(404);
            }

            Page::findOrFail($page->id)->delete();
            return redirect()->route('pages.index')->with('success', __('Page deleted successfully.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * P2.5-PKG-B. THIS ENDPOINT HAD NO AUTHORIZATION OF ANY KIND.
     *
     * No permission check, no ownership check, no type check. `pageId` comes straight from the
     * request body and `Page` is absent from `config/tenancy.php`'s `scoped_models`, so
     * `findOrFail()` reached every tenant's rows. MEASURED in
     * `SettingsAndContentCrossTenantTest`: an attacker holding ZERO Laratrust permissions took the
     * victim's live storefront page offline, while the owner's own toggle kept working.
     *
     * OWNERSHIP ONLY, DELIBERATELY. `destroy()` requires `Delete Page` and `update()` requires
     * `Edit Menu`, but this endpoint has never required either, and `PageDataTable:26-34` renders
     * its toggle for every row with no permission wrapper -- so demanding a permission here would
     * take a working control away from whoever holds neither. Which permission it *should* require
     * is a business-rule question for the owner; closing the cross-tenant path is not.
     *
     * Raised by the tenant-authz review of the sibling fixes in this file, NOT by the census that
     * found them: the census filters on a list of method names that held `changeStatus` and not
     * `updateStatus`. The list has since been widened, which is the only reason the siblings
     * (`statusChange`, `toggle`, ...) are now looked at too.
     */
    public function updateStatus(Request $request)
    {
        $pageId = $request->input('pageId');
        $isActivated = $request->input('isActivated');
        $page = Page::find($pageId);

        // Above the write, and 404 for foreign-or-absent so the two are indistinguishable.
        // `find()` rather than `findOrFail()`: the guard must answer for a missing row the same
        // way it answers for someone else's, and findOrFail's 404 would arrive before the check.
        if (! $this->callerOwnsPage($page)) {
            abort(404);
        }

        if ($isActivated == 'true') {
            $page->page_status = 1;
        } else {
            $page->page_status = 0;
        }
        $page->save();

        $return['status'] = 'success';
        $return['message'] = __('Status change successfully');
        return response()->json($return);
    }
}
