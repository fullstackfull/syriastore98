<?php

namespace Workdo\BundleProduct\app\Http\Controllers;

use App\Models\Product;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Workdo\BundleProduct\app\Models\BundleProduct;
use Workdo\BundleProduct\DataTables\BundleProductData;

class BundleProductController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index(BundleProductData $dataTable)
    {
        if (!auth()->user()) {
            return redirect()->route('login');
        }
        if (auth()->user()->isAbleTo('Manage Bundle Product')) {
            $products = BundleProduct::where('store_id', getCurrentStore())->get();
            return $dataTable->render('bundle-product::index', compact('products'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for creating a new resource.
     * @return Renderable
     */
    public function create()
    {
        $ids = BundleProduct::where('store_id', getCurrentStore())->pluck('product_id')->toArray();
        $products = Product::where('store_id', getCurrentStore())->whereNotIn('id', $ids)->pluck('name', 'id')->prepend('Select Product', '');

        $bundle_products = Product::where('store_id', getCurrentStore())->pluck('name', 'id');
        return view('bundle-product::create', compact('products', 'bundle_products'));
    }

    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return Renderable
     */
    public function store(Request $request)
    {
        $validator = \Validator::make(
            $request->all(),
            [
                'product_id' => 'required',
                'bundle_products' => 'required',
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', $messages->first());
        }
        if (auth()->user() && auth()->user()->isAbleTo('Create Bundle Product')) {
            $bundle_product = new BundleProduct();
            $bundle_product->product_id = $request->product_id;
            $bundle_product->bundle_products = json_encode($request->bundle_products);
            $bundle_product->created_by = \Auth::user()->id;
            $bundle_product->store_id = getCurrentStore();
            $bundle_product->save();

            return redirect()->back()->with('success', __('Bundle Product successfully created.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function show($id)
    {
        return view('bundle-product::show');
    }

    /**
     * Show the form for editing the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function edit($id)
    {
        $bundle_product = BundleProduct::find($id);
        if ($bundle_product) {
            $product = Product::where('store_id', getCurrentStore())->pluck('name', 'id')->toArray();
            return view('bundle-product::edit', compact('bundle_product', 'product'));
        } else {
            return redirect()->back()->with('error', __('The Bundle Product is not found.'));
        }

    }

    /**
     * Update the specified resource in storage.
     * @param Request $request
     * @param int $id
     * @return Renderable
     */
    public function update(Request $request, $id)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Edit Bundle Product')) {
            $validator = \Validator::make(
                $request->all(),
                [
                    'product_id' => 'required',
                    'bundle_products' => 'required',
                ]
            );

            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }

            $bundle_product = BundleProduct::find($id);
            if ($bundle_product) {
                $bundle_product->product_id = $request->product_id;
                $bundle_product->bundle_products = json_encode($request->bundle_products);
                $bundle_product->save();

                return redirect()->back()->with('success', __('Bundle Product successfully updated.'));
            } else {
                return redirect()->back()->with('error', __('The Bundle Product is not found.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Remove the specified resource from storage.
     * @param int $id
     * @return Renderable
     */
    public function destroy($id)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Delete Bundle Product')) {
            $bundle_product = BundleProduct::find($id);
            if ($bundle_product) {
                $bundle_product->delete();
                return redirect()->back()->with('success', __('Bundle Product delete successfully.'));
            } else {
                return redirect()->back()->with('error', __('The Bundle Product is not found.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }
}
