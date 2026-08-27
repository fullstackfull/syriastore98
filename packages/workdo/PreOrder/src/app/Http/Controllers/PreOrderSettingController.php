<?php

namespace Workdo\PreOrder\app\Http\Controllers;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Workdo\PreOrder\app\Models\PreOrderSetting;

class PreOrderSettingController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index()
    {
        return view('pre-order::index');
    }

    /**
     * Show the form for creating a new resource.
     * @return Renderable
     */
    public function create()
    {
        if (auth()->user() && auth()->user()->isAbleTo('Create Pre Order Setting'))
        {
            $product_details = Product::where('store_id', getCurrentStore())
                                ->get();

            $out_of_stock_products = collect();
            $settings = getAdminAllSetting();
    
            foreach ($product_details as $product) {
                if ($product->variant_product == 0) {
                    if ($product->track_stock == 1 && isset($settings['out_of_stock_threshold']) && $product->product_stock <= $settings['out_of_stock_threshold']) {
                        $out_of_stock_product = [
                            'name' => $product->name,
                            'stock_status' => $product->stock_order_status,
                            'stock' => $product->product_stock,
                            'category' => $product->ProductData->name,
                            'id' => $product->id,
                        ];
                        $out_of_stock_products->push($out_of_stock_product);
                    }elseif($product->track_stock == 0 || $product->product_stock <= 0){
                        $out_of_stock_product = [
                            'name' => $product->name,
                            'stock_status' => $product->stock_order_status,
                            'stock' => $product->product_stock,
                            'category' => $product->ProductData->name,
                            'id' => $product->id,
                        ];
                        $out_of_stock_products->push($out_of_stock_product);
                    }
                } else {
                    $product_stocks = ProductVariant::where('product_id', $product->id)->get();
    
                    foreach ($product_stocks as $stock) {
                        $variationOptions = explode(',', $stock->variation_option);
                        if (in_array('manage_stock', $variationOptions) && isset($settings['out_of_stock_threshold']) && $stock->stock <= $settings['out_of_stock_threshold']) {
                            $out_of_stock_product = [
                                'name' => $product->name,
                                'stock_status' => $stock->stock_order_status,
                                'stock' => $stock->stock,
                                'category' => $product->ProductData->name,
                                'id' => $product->id,
                            ];
                            $out_of_stock_products->push($out_of_stock_product);
                        }
                    }
                }
            }
            $products = $out_of_stock_products->pluck('name', 'id');

            return view('pre-order::setting.create',compact('products'));
        } else {
            return response()->json(['error' => __('Permission denied.')], 401);
        }
    }

    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return Renderable
     */
    public function store(Request $request)
    {
        if(auth()->user() && auth()->user()->isAbleTo('Create Pre Order Setting'))
        {
            $validator = \Validator::make(
                $request->all(),
                [
                    'product_id' => 'required',
                    'qty' => 'required|numeric',
                    'available_date' => 'required',
                ]
            );
            if($validator->fails())
            {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }

            $preorder_setting                 = new PreOrderSetting();
            $preorder_setting->product_id     = $request->product_id;
            $preorder_setting->variant_id     = $request->variant_id ?? 0;
            $preorder_setting->qty            = $request->qty;
            $preorder_setting->available_date = $request->available_date;
            $preorder_setting->status         = $request->status ?? '0';
            $preorder_setting->created_by     = auth()->user()->creatorId();
            $preorder_setting->store_id       = getCurrentStore();
            $preorder_setting->save();

            session()->put('pre_order_active_tab', 'pills-pre-order-setting-tab');

            return redirect()->back()->with('success', __('Pre Order setting successfully created.'));
        }
        else
        {
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
        return view('pre-order::show');
    }

    /**
     * Show the form for editing the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function edit($id)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Edit Pre Order Setting'))
        {
            $preorder_setting = PreOrderSetting::find($id);
            
            $product_details = Product::where('store_id', getCurrentStore())
                    ->get();

            $out_of_stock_products = collect();
            $settings = getAdminAllSetting();
    
            foreach ($product_details as $product) {
                if ($product->variant_product == 0) {
                    if ($product->track_stock == 1 && isset($settings['out_of_stock_threshold']) && $product->product_stock <= $settings['out_of_stock_threshold']) {
                        $out_of_stock_product = [
                            'name' => $product->name,
                            'stock_status' => $product->stock_order_status,
                            'stock' => $product->product_stock,
                            'category' => $product->ProductData->name,
                            'id' => $product->id,
                        ];
                        $out_of_stock_products->push($out_of_stock_product);
                    }elseif($product->track_stock == 0 || $product->product_stock <= 0){
                        $out_of_stock_product = [
                            'name' => $product->name,
                            'stock_status' => $product->stock_order_status,
                            'stock' => $product->product_stock,
                            'category' => $product->ProductData->name,
                            'id' => $product->id,
                        ];
                        $out_of_stock_products->push($out_of_stock_product);
                    }
                } else {
                    $product_stocks = ProductVariant::where('product_id', $product->id)->get();
    
                    foreach ($product_stocks as $stock) {
                        $variationOptions = explode(',', $stock->variation_option);
                        if (in_array('manage_stock', $variationOptions) && isset($settings['out_of_stock_threshold']) && $stock->stock <= $settings['out_of_stock_threshold']) {
                            $out_of_stock_product = [
                                'name' => $product->name,
                                'stock_status' => $stock->stock_order_status,
                                'stock' => $stock->stock,
                                'category' => $product->ProductData->name,
                                'id' => $product->id,
                            ];
                            $out_of_stock_products->push($out_of_stock_product);
                        }
                    }
                }
            }
            $products = $out_of_stock_products->pluck('name', 'id');

            $product_variants = ProductVariant::where('product_id',$preorder_setting->product_id)
                                ->where('store_id', getCurrentStore())
                                ->pluck('variant', 'id');

            return view('pre-order::setting.edit', compact('preorder_setting', 'products', 'product_variants'));
        } else {
            return response()->json(['error' => __('Permission denied.')], 401);
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
        if(auth()->user() && auth()->user()->isAbleTo('Edit Pre Order Setting'))
        {
            $validator = \Validator::make(
                $request->all(),
                [
                    'product_id' => 'required',
                    'qty' => 'required|numeric',
                    'available_date' => 'required',
                ]
            );
            if($validator->fails())
            {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }

            $preorder_setting = PreOrderSetting::find($id);

            if($preorder_setting){
                $preorder_setting->product_id     = $request->product_id;
                $preorder_setting->variant_id     = $request->variant_id ?? 0;
                $preorder_setting->qty            = $request->qty;
                $preorder_setting->available_date = $request->available_date;
                $preorder_setting->status         = $request->status ?? '0';
                $preorder_setting->created_by     = auth()->user()->creatorId();
                $preorder_setting->store_id       = getCurrentStore();
                $preorder_setting->save();

                session()->put('pre_order_active_tab', 'pills-pre-order-setting-tab');

                return redirect()->back()->with('success', __('Pre Order setting successfully updated.'));
            }else{
                return redirect()->back()->with('error', __('Pre Order setting not found'));
            }
        }
        else
        {
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
        if(auth()->user() && auth()->user()->isAbleTo('Delete Pre Order Setting'))
        {
            $preorder_setting = PreOrderSetting::find($id);
            if($preorder_setting){
                $preorder_setting->delete();

                session()->put('pre_order_active_tab', 'pills-pre-order-setting-tab');

                return redirect()->back()->with('success', __('Pre Order setting delete successfully.'));
            }else{
                return redirect()->back()->with('error', __('Pre Order setting not found'));
            }
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function getVariants($id) {
        $product = Product::find($id);
        if ($product->variant_product == 0) {
            $product_variants = [];
        } elseif ($product->variant_product == 1) {
            $product_variants = ProductVariant::where('product_id', $product->id)->get();
        }
        return response()->json($product_variants);
    }

    public function getOutOfStockProductIds(Request $request, $storeSlug)
    {

        $store = getStore($storeSlug);
        if (!$store) {
            return redirect()->back()->with('error', __('Something went wrong!'));
        }

        $customer = auth('customers')->user() ?? null;
        $pre_order_detail = \Workdo\PreOrder\app\Models\PreOrder::where('store_id', $store->id)->first();
        if(isset($customer) && isset($pre_order_detail) && $pre_order_detail->enable_pre_order == 'on'){
            $outOfStockProductIds = Product::where('variant_product', 0)->where('product_stock', '<=' , 0)->where('store_id', $store->id)->pluck('id');
        }else{
            $outOfStockProductIds = [];
        }
        $button_name = $pre_order_detail->name ?? 'Pre Order';
        $message = $pre_order_detail->message ?? '';

        return response()->json([
            'outOfStockProductIds' => $outOfStockProductIds,
            'buttonName' => $button_name,
            'message' => $message
        ]);
    }

}
