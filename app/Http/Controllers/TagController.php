<?php

namespace App\Http\Controllers;
use App\Models\Tag;
use Illuminate\Http\Request;
use App\DataTables\TagDataTable;

class TagController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(TagDataTable $dataTable)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Tag')) {
            return $dataTable->render('tag.index');
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('tag.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Create Tag')) {
            $validator = \Validator::make(
                $request->all(),
                [
                    'name' => 'required',
                ]
            );
            if($validator->fails())
            {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', implode(' ', $messages->all()));
            }
            $slug = Tag::slugs($request->name);
            $tag = new Tag();
            $tag->name = $request->name;
            $tag->slug = $slug;
            $tag->store_id   = getCurrentStore();
            $tag->created_by = \Auth::user()->id;

            $tag->save();
            return redirect()->back()->with('success', __('Tag Created Successfully'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */

    /**
     * P2.5-PKG-B. Does the caller own this tag?
     *
     * `Tag` is absent from `config/tenancy.php`'s `scoped_models`, so
     * `StoreScope::isActiveFor()` short-circuits and the global scope adds NO predicate for any
     * principal -- `Tag::find($id)` reaches every tenant's rows.
     *
     * MEASURED in `ContentFamilyCrossTenantTest` before this guard existed: all seven controllers
     * of this shape failed their cross-tenant cases while all seven positive controls passed, so
     * the fixtures were sound and the holes were real.
     *
     * Fails closed when either side is absent -- a row with a NULL `store_id` matches no store
     * predicate anywhere in the application and must not become visible here. No operator limb,
     * as on `ProductBrandController::callerOwnsBrand()`.
     */
    private function callerOwnsTag(?Tag $tag): bool
    {
        $user = auth()->user();

        if ($user === null || $tag === null) {
            return false;
        }

        $currentStore = getCurrentStore($user->id);

        return ! empty($currentStore)
            && ! empty($tag->store_id)
            && (int) $tag->store_id === (int) $currentStore;
    }

    public function edit(string $id)
    {
       $tag = Tag::find($id);


        // P2.5-PKG-B. This read was unguarded.
        if (! $this->callerOwnsTag($tag)) {
            abort(404);
        }
       return view('tag.edit' ,compact('tag'));

    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {        
        if (auth()->user() && auth()->user()->isAbleTo('Edit Tag')) {

            $tag = Tag::find($id);
            // P2.5-PKG-B. Permission was checked, owner was not. The guard precedes the delete.
            if (! $this->callerOwnsTag($tag)) {
                abort(404);
            }

            $validator = \Validator::make(
                $request->all(),
                [
                    'name' => 'required',
                ]
            );
            if($validator->fails())
            {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', implode(' ', $messages->all()));
            }
            $tag->name  = $request->name;
            $tag->save();
            return redirect()->back()->with('success', __('Tag  Successfully Upadated'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }


    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        
        if (auth()->user() && auth()->user()->isAbleTo('Delete Tag')) {
            $tag = Tag::find($id);
            // P2.5-PKG-B. Permission was checked, owner was not. The guard precedes the delete.
            if (! $this->callerOwnsTag($tag)) {
                abort(404);
            }

            $tag->delete();
            return redirect()->back()->with('success',__('Tag Deleted Successfully'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }
}
