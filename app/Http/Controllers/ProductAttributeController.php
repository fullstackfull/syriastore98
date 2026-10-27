<?php

namespace App\Http\Controllers;

use App\Models\ProductAttribute;
use App\Models\User;
use Illuminate\Http\Request;
use App\DataTables\ProductAttributeDataTable;

class ProductAttributeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(ProductAttributeDataTable $dataTable)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Attributes')) {
                return  $dataTable->render('attributes.index');
         } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('attributes.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Create Attributes')) {
            $validator = \Validator::make(
                $request->all(),
                [
                    'name' => 'required',
                ]
            );
            if($validator->fails())
            {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }
            $slug = ProductAttribute::slugs($request->name);
            $attribute                      = new ProductAttribute();
            $attribute->name                = $request->name;
            $attribute->slug                = $slug;
            $attribute->store_id            = getCurrentStore();
            $attribute->save();
            return redirect()->back()->with('success', __('Attribute successfully created.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(ProductAttribute $productAttribute)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */

    /**
     * The attribute inside the CALLER'S OWN store, or null.
     * =============================================================================================
     *
     * P2-05-4. `edit`, `update` and `destroy` took a route-model-bound `ProductAttribute` and
     * applied NO ownership predicate, so a merchant holding the ordinary `Edit Attributes` /
     * `Delete Attributes` permissions could rename or delete ANOTHER STORE'S attribute by posting
     * its id. Those permissions are granted to every tenant owner's role by default
     * (`Utility.php:3093-3096`), so the attacker is any merchant.
     *
     * NOTHING ELSE WAS STOPPING IT, and each of those was checked rather than assumed:
     * `ProductAttribute` registers no global scope and is NOT in `config/tenancy.php`'s
     * `scoped_models`, so `StoreScope::isActiveFor()` short-circuits and adds no predicate for any
     * principal; the resource route sits in a group carrying only `auth`, `xss`, `setlocate` and
     * `verified`; and `ModuleResourceOwnershipGuard`'s TARGETS map has no attribute entry.
     *
     * THE CHILD AND THE NEIGHBOUR WERE BOTH ALREADY FIXED and this one was missed:
     * `ProductAttributeOptionController::ownedAttribute()` is this same helper, and
     * `CategoryController` carries `categoryIsOutsideCurrentStore()` at its own write and unlink
     * sites (P1-INV-08). This brings the parent into line with them rather than inventing a
     * third shape.
     *
     * An empty store resolves to null rather than to "everything": with `null_tenant => 'open'`
     * a missing tenant adds no predicate, so it must be refused here explicitly.
     */
    private function ownedAttribute($id): ?ProductAttribute
    {
        $store = getCurrentStore();

        if (empty($store) || empty($id)) {
            return null;
        }

        return ProductAttribute::where('id', $id)->where('store_id', $store)->first();
    }

    /**
     * Gated as well as scoped: this method had NEITHER, so any authenticated user could read any
     * store's attribute name. The permission matches the one `update()` enforces, because the
     * form this returns posts straight to it.
     */
    public function edit($id)
    {
        if (! auth()->user() || ! auth()->user()->isAbleTo('Edit Attributes')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $productAttribute = $this->ownedAttribute($id);

        if (! $productAttribute) {
            return redirect()->back()->with('error', __('Attribute not found.'));
        }

        return view('attributes.edit', compact('productAttribute'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Edit Attributes')) {

            /* The ownership predicate the permission check alone does not give. */
            $productAttribute = $this->ownedAttribute($id);

            if (! $productAttribute) {
                return redirect()->back()->with('error', __('Attribute not found.'));
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
                return redirect()->back()->with('error', $messages->first());
            }
            $productAttribute->name       = $request->name;
            $productAttribute->save();

            return redirect()->back()->with('success', __('Attribute successfully updated.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Delete Attributes')) {

            $productAttribute = $this->ownedAttribute($id);

            if (! $productAttribute) {
                return redirect()->back()->with('error', __('Attribute not found.'));
            }

            $productAttribute->delete();
            return redirect()->back()->with('success', __('Attribute delete successfully.'));

        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }
}
