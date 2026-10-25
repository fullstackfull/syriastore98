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
    public function create()
    {
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
                return redirect()->back()->with('error', $messages->first());
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
    public function edit(ProductBrand $productBrand)
    {
        return view('product_brand.edit', compact('productBrand'));

    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ProductBrand $productBrand)
    {

        if (auth()->user() && auth()->user()->isAbleTo('Edit Product Brand')) {

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
            $productBrand->store_id            = getCurrentStore();
            $productBrand->save();

            return redirect()->back()->with('success', __('Product Brand successfully updated.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ProductBrand $productBrand)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Delete Product Brand')) {
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
