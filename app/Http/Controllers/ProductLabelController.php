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
    public function create()
    {
        return view('product_label.create');
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
                return redirect()->back()->with('error', $messages->first());
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
    public function edit($id)
    {
        $productLabel = ProductLabel::find($id);
        return view('product_label.edit', compact('productLabel'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Edit Product Label')) {
            $productLabel = ProductLabel::find($id);
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



            $productLabel->name                = $request->name;
            if (isset($request->status)) {
                $productLabel->status              = $request->status;
            }
            $productLabel->store_id            = getCurrentStore();
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
