<?php

namespace App\Http\Controllers;

use App\Models\ProductAttributeOption;
use App\Models\ProductAttribute;
use Illuminate\Http\Request;

class ProductAttributeOptionController extends Controller
{
    /**
     * CLAUDE B FINDING / P01B-SEC-1. Every method in this controller except index() was missing
     * a tenant predicate, and four were missing a permission check as well. Neither
     * ProductAttributeOption nor ProductAttribute carries the tenancy trait or an entry in
     * config/tenancy.php's `scoped_models` allowlist, so no global scope supplied one -- the
     * predicate had to be written here or it did not exist.
     *
     * MEASURED STATE BEFORE THIS CHANGE:
     *   index()    'Manage Attributes Option' + where('store_id', getCurrentStore())  -- correct
     *   create()   no permission, no predicate
     *   store()    'Create Attributes Option', wrote store_id, never checked the ATTRIBUTE
     *   show()     NO permission, NO predicate -- cross-tenant READ   (reported by Claude B)
     *   edit()     NO permission, NO predicate -- cross-tenant READ
     *   update()   'Edit Attributes Option', NO predicate  -- cross-tenant WRITE
     *   destroy()  'Delete Attributes Option', NO predicate -- cross-tenant DELETE
     *   option()   NO permission, NO predicate -- cross-tenant REORDER WRITE  (reported by B)
     *
     * Claude B reported show() and option(). edit(), update() and destroy() are the same defect
     * in the same file, and two of them MUTATE, so fixing only the reported pair would have left
     * a cross-tenant delete open while reporting the finding closed.
     *
     * THE PERMISSION NAMES ARE THE ONES ALREADY USED HERE, and all four are seeded by
     * Utility::addNewData() AND granted to the merchant `admin` role in $companyNewPermission
     * (verified by counting both quote styles in app/Models/Utility.php). No new name is
     * introduced: an unseeded permission can never be granted and would 403 every legitimate
     * user for ever, which is the failure mode CustomerController::destroy()'s docblock records.
     */

    /**
     * An attribute option inside the caller's own store, or null.
     *
     * Raw predicate rather than findOrFail() + comparison so that "belongs to another tenant" and
     * "does not exist" are the same answer and the id space cannot be enumerated. Fails closed
     * when the caller has no store context: getCurrentStore() returns null for a merchant with no
     * current_store and no owned Store, and `where('store_id', null)` would match nothing, so the
     * empty check is explicit rather than relying on that.
     */
    private function ownedOption($id): ?ProductAttributeOption
    {
        $store = getCurrentStore();

        if (empty($store) || empty($id)) {
            return null;
        }

        return ProductAttributeOption::where('id', $id)->where('store_id', $store)->first();
    }

    /** The parent attribute inside the caller's own store, or null. Same reasoning. */
    private function ownedAttribute($id): ?ProductAttribute
    {
        $store = getCurrentStore();

        if (empty($store) || empty($id)) {
            return null;
        }

        return ProductAttribute::where('id', $id)->where('store_id', $store)->first();
    }

    /** The one denial this controller already used, kept identical so the UX does not change. */
    private function denied()
    {
        return redirect()->back()->with('error', __('Permission denied.'));
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Attributes Option')) {

        $ProductAttributes = ProductAttributeOption::where('store_id',getCurrentStore())->get();
        return view('attribute.index', compact('ProductAttributes'));

        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create($id)
    {
        if (!auth()->user() || !auth()->user()->isAbleTo('Create Attributes Option')) {
            return $this->denied();
        }

        // $id is the parent attribute. Rendering the form for another tenant's attribute is how
        // a cross-tenant store() call gets composed, so it is refused here too.
        if (!$this->ownedAttribute($id)) {
            return $this->denied();
        }

        return view('attribute_option.create',compact('id'));


    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request ,$id)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Create Attributes Option')) {

            $validator = \Validator::make(
                $request->all(),
                [
                    'terms' => 'required',
                ]
            );
            if($validator->fails())
            {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }
            // The permission was already checked above, but the ATTRIBUTE never was: store()
            // stamped store_id from the caller while accepting any attribute_id, so a merchant
            // could attach an option to another tenant's attribute and have it stored under their
            // own store_id -- a row whose two owners disagree.
            if (!$this->ownedAttribute($id)) {
                return $this->denied();
            }

            $attribute_option                      = new ProductAttributeOption();
            $attribute_option->attribute_id        = $id;
            $attribute_option->terms                = $request->terms;
            $attribute_option->store_id            = getCurrentStore();

            $attribute_option->save();

            return redirect()->back()->with('success', __('Attribute option successfully created.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(ProductAttributeOption $productAttributeOption , $id)
    {
        if (!auth()->user() || !auth()->user()->isAbleTo('Manage Attributes Option')) {
            return $this->denied();
        }

        // The route parameter is an ATTRIBUTE id, not an option id, despite the method signature.
        $attribute = $this->ownedAttribute($id);

        if (!$attribute) {
            return $this->denied();
        }

        // Scoped on store_id as well as attribute_id. Scoping on attribute_id alone would already
        // be correct once the attribute is known to be ours, but the options table carries its own
        // store_id and a row whose two disagree must not surface here.
        $attribute_option = ProductAttributeOption::where('attribute_id', $id)
            ->where('store_id', getCurrentStore())
            ->orderBy('order','asc')->get();

        return view('attribute_option.index', compact('productAttributeOption','attribute_option','attribute'));

    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        if (!auth()->user() || !auth()->user()->isAbleTo('Edit Attributes Option')) {
            return $this->denied();
        }

        $productAttributeOption = $this->ownedOption($id);

        if (!$productAttributeOption) {
            return $this->denied();
        }

        return view('attribute_option.edit', compact('productAttributeOption'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ProductAttributeOption $productAttributeoption,$id)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Edit Attributes Option')) {

            $validator = \Validator::make(
                $request->all(),
                [
                    'terms' => 'required',
                ]
            );
            if($validator->fails())
            {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }
            $productAttributeoption = $this->ownedOption($id);

            // Also closes a null dereference: the previous find($id) was written to without a
            // null check, so a nonexistent id raised "Attempt to assign property on null".
            if (!$productAttributeoption) {
                return $this->denied();
            }

            $productAttributeoption->terms       = $request->terms;
            $productAttributeoption->save();
            return redirect()->back()->with('success', __('Attribute Option successfully updated.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }
    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ProductAttributeOption $productAttributeOption ,$id)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Delete Attributes Option')) {

            $productAttributeOption = $this->ownedOption($id);

            if (!$productAttributeOption) {
                return $this->denied();
            }

            $productAttributeOption->delete();

            return redirect()->back()->with('success', __('Attribute option delete successfully.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Reorder options by drag and drop. A WRITE, reached by POST product-attribute/option.
     *
     * Reported by Claude B. It had no permission check and no tenant predicate, so any
     * authenticated user could reorder ANY tenant's attribute options by posting their ids; and
     * `$status->order = $key` was written without a null check, so an id that does not exist
     * raised "Attempt to assign property on null" -- a 500 rather than a refusal.
     *
     * Gated on 'Edit Attributes Option', the permission update() already uses, because reordering
     * is an edit. ALL-OR-NOTHING: if any posted id is not ours the whole request is refused rather
     * than partially applied, so a payload mixing our ids with a foreign one cannot reorder ours
     * as a side effect of being rejected.
     */
    public function option(Request $request)
    {
        if (!auth()->user() || !auth()->user()->isAbleTo('Edit Attributes Option')) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        $terms = $request->input('terms');

        if (!is_array($terms) || $terms === []) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        $options = [];

        foreach ($terms as $key => $item) {
            $option = $this->ownedOption($item);

            if (!$option) {
                return response()->json(['error' => __('Permission denied.')], 403);
            }

            $options[$key] = $option;
        }

        foreach ($options as $key => $option) {
            $option->order = $key;
            $option->save();
        }

        return response()->json(['success' => true]);
    }
}
