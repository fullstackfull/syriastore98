<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ProductLabel;
use App\DataTables\ProductLabelDataTable;

class ProductLabelController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(ProductLabelDataTable $dataTable)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Product Label')) {
            return  $dataTable->render('product_label.index');
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    /**
     * P2.5-PKG-B. The form now refuses exactly when the submit would: `store()` checks
     * `Create Product Label` and this checked nothing.
     */
    public function create()
    {
        if (! auth()->user() || ! auth()->user()->isAbleTo('Create Product Label')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        return view('product_label.create');
    }

    /**
     * P2.5-PKG-B. Does the caller own this label?
     *
     * `ProductLabel` registers no global scope and is absent from `config/tenancy.php`'s
     * `scoped_models`, so `ProductLabel::find($id)` reaches every tenant's rows. MEASURED in
     * `BrandCrossTenantReadWriteTest` before this guard existed: a second merchant opened, renamed,
     * TOOK and deleted another tenant's label.
     *
     * Fails closed on either side being absent -- a row with a NULL `store_id` is invisible to
     * every store predicate in the application and must not become visible here.
     *
     * No operator limb, for the reason recorded on `ProductBrandController::callerOwnsBrand()`:
     * these are the editor, owner decision F206 says the operator may read merchant catalogues but
     * not edit them, and all three methods already refuse the operator because `isAbleTo()` alone
     * does. This only ever removes access.
     */
    private function callerOwnsLabel(?ProductLabel $label): bool
    {
        $user = auth()->user();

        if ($user === null || $label === null) {
            return false;
        }

        $currentStore = getCurrentStore($user->id);

        return ! empty($currentStore)
            && ! empty($label->store_id)
            && (int) $label->store_id === (int) $currentStore;
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Create Product Label')) {
            $validator = \Validator::make(
                $request->all(),
                [
                    'name' => 'required'
                ]
            );
            if($validator->fails())
            {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', implode(' ', $messages->all()));
            }
            $slug = ProductLabel::slugs($request->name);

            $productLabel                      = new ProductLabel();
            $productLabel->name                = $request->name;
            $productLabel->slug                = $slug;
            $productLabel->status              = $request->status;
            $productLabel->store_id            = getCurrentStore();
            $productLabel->created_by          = auth()->user()->id;
            $productLabel->save();
            return redirect()->back()->with('success', __('Product Label successfully created.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    /**
     * P2.5-PKG-B. This had NO permission check and NO ownership check -- so any authenticated web
     * principal, with zero label permissions, could read another store's label.
     *
     * 404 for a foreign or absent row, so the two are indistinguishable and the response is not an
     * existence oracle.
     */
    public function edit($id)
    {
        if (! auth()->user() || ! auth()->user()->isAbleTo('Edit Product Label')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $productLabel = ProductLabel::find($id);

        if (! $this->callerOwnsLabel($productLabel)) {
            abort(404);
        }

        return view('product_label.edit', compact('productLabel'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Edit Product Label')) {
            $productLabel = ProductLabel::find($id);

            // P2.5-PKG-B. The permission was checked and the owner was not, and the assignment
            // further down made that a TAKEOVER rather than an unauthorised edit.
            if (! $this->callerOwnsLabel($productLabel)) {
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



            $productLabel->name                = $request->name;
            if (isset($request->status)) {
                $productLabel->status              = $request->status;
            }
            // THE ASSIGNMENT THAT USED TO BE HERE IS GONE, not merely guarded.
            // `$productLabel->store_id = getCurrentStore();` was the takeover primitive: a foreign
            // row reaching it did not just get edited, it CHANGED HANDS. callerOwnsLabel() above
            // now guarantees the two values are already equal, so the line was a no-op -- but
            // leaving it means any future weakening of the guard re-opens a TRANSFER rather than
            // an unauthorised edit. Raised by the tenant-authz review. The Admin API sibling has
            // never done this.
            $productLabel->save();

            return redirect()->back()->with('success', __('Product Label successfully updated.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Delete Product Label')) {
            $productLabel = ProductLabel::find($id);

            // P2.5-PKG-B. Permission was checked, owner was not. The guard precedes the delete.
            if (! $this->callerOwnsLabel($productLabel)) {
                abort(404);
            }

            $productLabel->delete();
            return redirect()->back()->with('success', __('Product Label delete successfully.'));

        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Responds with a welcome message with instructions
     *
     * @return \Illuminate\Http\Response
     */
    /**
     * P2-05-1. THE LABEL TOGGLE, with the controls it never had.
     *
     * WHAT WAS HERE: `ProductLabel::find($request->id)`, then a write. No permission check, no
     * ownership check, no tenant predicate -- and `ProductLabel` is **not in
     * `config/tenancy.php`'s `scoped_models`**, carries no `BelongsToStore` and registers no
     * `StoreScope`, so `find()` reached every tenant's rows for every principal. It was also a
     * GET, so it was reachable from a third-party `<img src>` and by link prefetching.
     *
     * `Api\DashboardController::LableChangeStatus` -- the Flutter sibling -- was already fixed
     * with `whereIn('store_id', $this->ownedStoreIds())`. This path was left open.
     *
     * The shape is deliberately identical to `ProductBrandController::resolveOwnedBrand()`:
     * operator first (Laratrust has no super-admin bypass, so `isAbleTo()` alone refuses the
     * operator and reversing the limbs is a lockout), `filter_var` rather than `is_numeric`
     * (MySQL coerces '5abc' to row 5), an explicit store predicate that fails closed on either
     * side being absent, and 404 for a foreign or absent row so the response is not an
     * existence oracle.
     *
     * `Edit Product Label` is what `edit()` and `update()` on this row already use.
     */
    public function changeStatus(Request $request)
    {
        $user = auth()->user();

        $isOperator = $user !== null && \App\Policies\UserPolicy::isOperator($user);

        if ($user === null || (! $isOperator && ! $user->isAbleTo('Edit Product Label'))) {
            return response()->json([
                'status' => 'error',
                'message' => __('Permission denied.'),
            ], 403);
        }

        $id = filter_var($request->input('id'), FILTER_VALIDATE_INT);

        $notFound = response()->json([
            'status' => 'error',
            'message' => __('Something went wrong!!'),
        ], 404);

        if ($id === false || $id <= 0) {
            return $notFound;
        }

        $productLabel = ProductLabel::find($id);

        if ($productLabel === null) {
            return $notFound;
        }

        if (! $isOperator) {
            $currentStore = getCurrentStore($user->id);

            if (empty($currentStore) || empty($productLabel->store_id)
                || (int) $productLabel->store_id !== (int) $currentStore) {
                return $notFound;
            }
        }

        $productLabel->status = $request->input('status');
        $productLabel->save();

        return response()->json([
            'status' => 'success',
            'message' => __('Status change successfully.'),
        ]);
    }
}
