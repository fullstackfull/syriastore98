<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ProductBrand;
use App\Models\Utility;
use App\DataTables\ProductBrandDataTable;
use Illuminate\Support\Facades\Storage;

class ProductBrandController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(ProductBrandDataTable $dataTable)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Product Brand')) {
            return $dataTable->render('product_brand.index');
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    /**
     * P2.5-PKG-B. THE FORM NOW REFUSES EXACTLY WHEN THE SUBMIT WOULD.
     *
     * This method had NO permission check at all, while `store()` -- the only thing this form can
     * submit to -- checks `Create Product Brand`. The index view already hides the button behind
     * `@permission('Create Product Brand')` (product_brand/index.blade.php:6), so the gate existed
     * everywhere except the handler.
     *
     * No operator limb, deliberately: `store()` has none either, and adding one here would let the
     * platform operator compose a write the submit then refuses.
     */
    public function create()
    {
        if (! auth()->user() || ! auth()->user()->isAbleTo('Create Product Brand')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        return view('product_brand.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Create Product Brand')) {
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
            $slug = ProductBrand::slugs($request->name);

            $url = null;
            if($request->logo) {
                $dir        = 'uploads/' . getCurrentStore();
                $image_size = $request->file('logo')->getSize();
                $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $image_size);
                if ($result == 1)
                {
                    $fileName = rand(10,100).'_'.time() . "_" . $request->logo->getClientOriginalName();
                    $path = Utility::upload_file($request,'logo',$fileName,$dir,[]);
                    if ($path['flag'] == 1) {
                        $url = $path['url'];
                    } else {
                        return redirect()->back()->with('error', __($path['msg']));
                    }
                }
                else{
                    return redirect()->back()->with('error', $result);
                }
            }else{
                $url = Storage::url('uploads/default.jpg');
            }

            $productBrand                      = new ProductBrand();
            $productBrand->name                = $request->name;
            $productBrand->logo                = $url;
            $productBrand->slug                = $slug;
            $productBrand->status              = $request->status;
            $productBrand->is_popular          = $request->is_popular;
            $productBrand->store_id            = getCurrentStore();
            $productBrand->created_by          = auth()->user()->id;
            $productBrand->save();
            return redirect()->back()->with('success', __('Product Brand successfully created.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(ProductBrand $productBrand)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    /**
     * P2.5-PKG-B. THE OWNERSHIP HALF OF `resolveOwnedBrand()`, for the route-model-bound methods.
     *
     * `resolveOwnedBrand()` below reads an id out of a Request, which suits the two POST toggles
     * and suits nothing else. `edit()`, `update()` and `destroy()` receive an already-bound
     * `ProductBrand`, and ROUTE MODEL BINDING IS NOT TENANT SCOPED: `ProductBrand` registers no
     * global scope and is absent from `config/tenancy.php`'s `scoped_models`, so the bound
     * instance can be any row in the table.
     *
     * FAIL CLOSED ON EITHER SIDE BEING ABSENT, exactly as the sibling does. A row with a NULL
     * `store_id` is invisible to every store predicate in the application and must not become
     * visible here.
     *
     * NO OPERATOR LIMB, AND THAT IS A DECISION RATHER THAN AN OVERSIGHT. `resolveOwnedBrand()`
     * gives the operator a pass for the status toggles. These three are the editor, and owner
     * decision F206 (`app/Services/OperatorCatalogueWrite.php:8-15`) says the platform operator
     * may read merchant catalogues but may not edit them. The three methods already refuse the
     * operator today, because `isAbleTo()` alone does; adding a limb here would widen what F206
     * narrows. This change only ever REMOVES access.
     */
    private function callerOwnsBrand(?ProductBrand $brand): bool
    {
        $user = auth()->user();

        if ($user === null || $brand === null) {
            return false;
        }

        $currentStore = getCurrentStore($user->id);

        return ! empty($currentStore)
            && ! empty($brand->store_id)
            && (int) $brand->store_id === (int) $currentStore;
    }

    /**
     * P2.5-PKG-B. THIS HAD NO PERMISSION CHECK AND NO OWNERSHIP CHECK.
     *
     * MEASURED, not inferred: `BrandCrossTenantReadWriteTest` drove a second merchant at a
     * foreign brand's edit form and it answered 200 carrying that brand's name.
     *
     * `Edit Product Brand` is the permission `update()` already uses on this same row, so the
     * form now refuses exactly when the submit would. 404 for a foreign or absent row, because a
     * row outside the caller's store must be indistinguishable from one that does not exist --
     * the same non-oracle property `resolveOwnedBrand()` documents.
     */
    public function edit(ProductBrand $productBrand)
    {
        if (! auth()->user() || ! auth()->user()->isAbleTo('Edit Product Brand')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if (! $this->callerOwnsBrand($productBrand)) {
            abort(404);
        }

        return view('product_brand.edit', compact('productBrand'));

    }

    /**
     * Update the specified resource in storage.
     */
    /**
     * P2.5-PKG-B. THE PERMISSION WAS CHECKED AND THE OWNER WAS NOT, AND THE CONSEQUENCE IS WORSE
     * THAN AN UNAUTHORISED EDIT.
     *
     * Further down, this method does `$productBrand->store_id = getCurrentStore();`. That is not
     * an ownership CHECK, it is an ownership REASSIGNMENT -- so a foreign row reaching it was not
     * merely edited, it CHANGED HANDS into the caller's store.
     *
     * MEASURED before the fix, in `BrandCrossTenantReadWriteTest`: a second merchant holding
     * `Edit Product Brand` renamed another tenant's brand AND moved it into their own store.
     */
    public function update(Request $request, ProductBrand $productBrand)
    {

        if (auth()->user() && auth()->user()->isAbleTo('Edit Product Brand')) {

            if (! $this->callerOwnsBrand($productBrand)) {
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

            $url = null;
            if($request->logo) {
                $file_path = $productBrand->logo;
                $dir        = 'uploads/' . getCurrentStore();
                $image_size = $request->file('logo')->getSize();
                $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $image_size);
                if ($result == 1)
                {
                    Utility::changeStorageLimit(\Auth::user()->creatorId(), $file_path);
                    $fileName = rand(10,100).'_'.time() . "_" . $request->logo->getClientOriginalName();
                    $path = Utility::upload_file($request,'logo',$fileName,$dir,[]);
                    if ($path['flag'] == 1) {
                        $url = $path['url'];
                    } else {
                        return redirect()->back()->with('error', __($path['msg']));
                    }
                }
                else{
                    return redirect()->back()->with('error', $result);
                }
            }

            $productBrand->name                = $request->name;
            if ($url) {
                $productBrand->logo                = $url;
            }
            if (isset($request->status)) {
                $productBrand->status              = $request->status;
            }
            if (isset($request->is_popular)) {
                $productBrand->is_popular          = $request->is_popular;
            }
            // THE ASSIGNMENT THAT USED TO BE HERE IS GONE, not merely guarded.
            // `$productBrand->store_id = getCurrentStore();` was the takeover primitive: a foreign
            // row reaching it did not just get edited, it CHANGED HANDS into the caller's store.
            // callerOwnsBrand() above now guarantees the two values are already equal, so the line
            // was a no-op -- but leaving it means any future weakening of the guard re-opens a
            // TRANSFER rather than an unauthorised edit. Raised by the tenant-authz review, which
            // also noted the Admin API sibling (Api\DashboardController) has never done this.
            //
            // `store()` at :95 keeps ITS assignment: that one STAMPS a brand-new row with the
            // creator's store, which is where the value has to come from.
            $productBrand->save();

            return redirect()->back()->with('success', __('Product Brand successfully updated.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    /**
     * P2.5-PKG-B. The permission was checked and the owner was not.
     *
     * MEASURED before the fix: a second merchant holding `Delete Product Brand` deleted another
     * tenant's brand. The ownership check goes BEFORE the storage-limit accounting below, so a
     * refused call also leaves the victim's storage figures untouched.
     */
    public function destroy(ProductBrand $productBrand)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Delete Product Brand')) {
            if (! $this->callerOwnsBrand($productBrand)) {
                abort(404);
            }

            if ($productBrand->logo !== '/storage/uploads/default.jpg' && \File::exists(base_path($productBrand->logo))) {
                Utility::changeStorageLimit(\Auth::user()->creatorId(), $productBrand->logo );
            }

            $productBrand->delete();
            return redirect()->back()->with('success', __('Product Brand delete successfully.'));

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
     * THE SHARED GUARD for the two toggles below. P2-05-1.
     *
     * WHAT WAS HERE: `ProductBrand::find($request->id)`, then a write. No permission check, no
     * ownership check, no tenant predicate -- and `ProductBrand` is **not in
     * `config/tenancy.php`'s `scoped_models`**, carries no `BelongsToStore` and registers no
     * `StoreScope`, so `find()` reached every tenant's rows for every principal. Any
     * authenticated web caller could disable any brand in any store by walking the id, and
     * `status` is the column the storefront filters on.
     *
     * THE API SIBLINGS WERE ALREADY FIXED -- `Api\DashboardController::ChangeStatus` and
     * `ChangePopular` both carry `whereIn('store_id', $this->ownedStoreIds())`. The Flutter path
     * was closed and this one was left open, which is a census keyed on the surface somebody
     * happened to be looking at rather than on the model.
     *
     * OPERATOR FIRST. Laratrust has no super-admin bypass, so `isAbleTo()` alone refuses the
     * platform operator; reversing these two limbs is a lockout, not a tightening.
     *
     * `Edit Product Brand` is the permission `edit()` and `update()` on this same row already
     * use, so the toggle asks the question the editor asks. It is seeded, so this is not the
     * F15 trap of gating on a name no row exists for.
     *
     * 404 FOR A FOREIGN OR ABSENT ROW, 403 for a permission refusal: a row outside the caller's
     * store is indistinguishable from one that does not exist, so the response is not an
     * existence oracle.
     *
     * @return array{0: null, 1: \App\Models\ProductBrand}|array{0: \Illuminate\Http\JsonResponse, 1: null}
     */
    private function resolveOwnedBrand(Request $request): array
    {
        $user = auth()->user();

        $isOperator = $user !== null && \App\Policies\UserPolicy::isOperator($user);

        if ($user === null || (! $isOperator && ! $user->isAbleTo('Edit Product Brand'))) {
            return [response()->json(['status' => 'error', 'message' => __('Permission denied.')], 403), null];
        }

        // `filter_var`, not `is_numeric`: is_numeric accepts '5.9' and rejects '5abc', while
        // MySQL coerces '5abc' to row 5 in a `WHERE id = ?` comparison.
        $id = filter_var($request->input('id'), FILTER_VALIDATE_INT);

        $notFound = [response()->json(['status' => 'error', 'message' => __('Something went wrong!!')], 404), null];

        if ($id === false || $id <= 0) {
            return $notFound;
        }

        $brand = ProductBrand::find($id);

        if ($brand === null) {
            return $notFound;
        }

        if (! $isOperator) {
            $currentStore = getCurrentStore($user->id);

            // Fail closed on either side being absent. A row with a NULL store_id is invisible
            // to every store predicate in the application and must not become visible here.
            if (empty($currentStore) || empty($brand->store_id)
                || (int) $brand->store_id !== (int) $currentStore) {
                return $notFound;
            }
        }

        return [null, $brand];
    }

    public function changeStatus(Request $request)
    {
        [$refusal, $productBrand] = $this->resolveOwnedBrand($request);

        if ($refusal !== null) {
            return $refusal;
        }

        $productBrand->status = $request->input('status');
        $productBrand->save();

        return response()->json([
            'status' => 'success',
            'message' => __('Status change successfully.'),
        ]);
    }

    /**
     * Responds with a welcome message with instructions
     *
     * @return \Illuminate\Http\Response
     */
    public function changePopular(Request $request)
    {
        // THE SAME GUARD as changeStatus. `is_popular` promotes a brand on the storefront, so
        // it is the same question about the same row -- a second, looser idiom here is how two
        // handlers on one model drift apart.
        [$refusal, $productBrand] = $this->resolveOwnedBrand($request);

        if ($refusal !== null) {
            return $refusal;
        }

        $productBrand->is_popular = $request->input('is_popular');
        $productBrand->save();

        return response()->json([
            'status' => 'success',
            'message' => __('Status change successfully.'),
        ]);
    }
}
