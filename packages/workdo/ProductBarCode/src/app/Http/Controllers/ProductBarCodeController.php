<?php

namespace Workdo\ProductBarCode\app\Http\Controllers;

use App\Models\Setting;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Workdo\ProductBarCode\DataTables\ProductBarCodeData;

class ProductBarCodeController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index(ProductBarCodeData $dataTable)
    {
        if (!auth()->user()) {
			return redirect()->route('login');
		}
        if (auth()->user()->isAbleTo('Manage Product Barcode')) {
            $store_id = getStoreById(getCurrentStore());
            $products = Product::where('store_id', getCurrentStore())->whereNotNull('product_code')->orderBy('id', 'desc')->get();
            $settings = Setting::where('store_id', getCurrentStore())
                    ->pluck('value', 'name')
                    ->toArray();
            $enable_product_barcode = \App\Models\Utility::GetValueByName('enable_product_barcode', $store_id->theme_id, $store_id->id);
            if(isset($enable_product_barcode) && $enable_product_barcode == 'on')
            {
                $barcode_type = isset($settings['barcode_type']) ? $settings['barcode_type'] : 'PHARMA';
                $barcode_color = isset($settings['barcode_color']) ? $settings['barcode_color'] : 'black';
                return  $dataTable->render('product-bar-code::index', compact('products','barcode_type','barcode_color'));
            }else{
                return redirect()->route('dashboard')->with('error', __('Permission denied.'));
            }
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
        return view('product-bar-code::create');
    }

    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return Renderable
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Show the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function show($id)
    {
        return view('product-bar-code::show');
    }

    /**
     * Show the form for editing the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function edit($id)
    {
        return view('product-bar-code::edit');
    }

    /**
     * Update the specified resource in storage.
     * @param Request $request
     * @param int $id
     * @return Renderable
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     * @param int $id
     * @return Renderable
     */
    public function destroy($id)
    {
        //
    }

    public function BarCodeSettings(Request $request)
    {
        $theme_id = !empty(APP_THEME()) ? APP_THEME() : '';
        $validator = \Validator::make(
            $request->all(),
            [
                'barcode_type' => 'required',
                'barcode_color' => 'required',
            ]
        );
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', $messages->first());
        }

        $post['barcode_type'] = $request->barcode_type;
        $post['barcode_color'] = $request->barcode_color;
        $settingQuery = Setting::query();
        foreach ($post as $key => $data) {
            $status = (clone $settingQuery)->updateOrCreate(
                ['name' => $key,'created_by'=>auth()->user()->id],
                [
                    'value'         => $data,
                    'name'          => $key,
                    'store_id'      => getCurrentStore(),
                    'created_by'    => auth()->user()->id,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]
            );
        }
        if (isset($status)) {
            return redirect()->back()->with('success', __('BarCode setting successfully updated.'));
        } else {
            return redirect()->back()->with('error', __('Something is wrong'));
        }
    }

    public function BarCodeType(Request $request)
    {
        $settings = getAdminAllSetting();
        return view('product-bar-code::pages.barcode-setting',compact('settings'));
    }

    public function searchProductsBarcode(Request $request)
    {
        $lastsegment = $request->session_key;
        $store_id = getStoreById(getCurrentStore());
        if ($request->ajax() && isset($lastsegment) && !empty($lastsegment)) {
            $output = "";
            if ($request->cat_id !== '' && $request->search == '') {
                if ($request->cat_id == '0') {
                    $products = Product::where('store_id', getCurrentStore())->get();
                } else {
                    $products = Product::where('category_id', $request->cat_id)->where('store_id', getCurrentStore())->get();
                }
            } else {
                if ($request->cat_id == '0') {
                    $products = Product::where('product_code', 'LIKE', "%{$request->search}%")->where('store_id', getCurrentStore())->get();
                } else {
                    $products = Product::where('product_code', 'LIKE', "%{$request->search}%")->where('store_id', getCurrentStore())->Where('category_id', $request->cat_id)->get();
                }
            }
            if (count($products) > 0) {
                foreach ($products as $key => $product) {
                    if (!empty($product->cover_image_path)) {
                        $image_url = get_file($product->cover_image_path);
                    } else {
                        $image_url = ('uploads/cover_image_path') . '/default.jpg';
                    }

                    if ($product->variant_product != '1' ) {
                        if($product->track_stock == 0) {
                            $quantity = $product->stock_status;
                            if($product->stock_status == 'in_stock'){
                                $quantity = 'In Stock';
                            }elseif($product->stock_status == 'on_backorder'){
                                $quantity = 'On Backorder';
                            }else{
                                $quantity = 'Out of Stock';
                            }
                        } else {
                            $quantity = $product->product_stock . ' Qty';
                        }

                        if ($request->session_key == 'purchases') {
                            $productprice = $product->price != 0 ? $product->price : 0;
                        } else if ($request->session_key == 'pos_'.getCurrentStore()) {
                            $productprice = $product->price != 0 ? $product->price : 0;
                        } else {
                            $productprice = $product->price != 0 ? $product->price : $product->price;
                        }

                        $productprice = currency_format_with_sym($productprice, $store_id->id, ($store_id->theme_id ?? APP_THEME()));
                        $output .= ' <div class="col-xxl-3 col-xl-3 col-xl-4 col-lg-6 col-sm-6 col-xs-6 col-12">
                                    <div class="tab-pane fade show active toacart w-100" data-url="' . url('/addToCart/' . $product->id . '/' . $lastsegment) . '">
                                        <div class="btn-primary position-relative card">
                                            <img alt="Image placeholder" src="' . asset($image_url) . '" class="card-image avatar hover-shadow-lg" style=" height: 6rem; width: 100%;">
                                            <div class="p-0 custom-card-body card-body d-flex ">
                                                <div class="card-body my-2 p-2 text-center card-bottom-content">
                                                <h6 class="mb-2 text-dark product-title-name">' . $product->name . '</h6>
                                                <p class="mb-2 text-dark product-title-name small">' . $product->slug . '</p>
                                                <small class="text-primary">' . $productprice . '</small>
                                                <small class="top-badge badge badge-danger mb-0">' . $quantity . '</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div> ';
                    } else {

                        $output .= ' <div class="col-xxl-3 col-xl-3 col-xl-4 col-lg-6 col-sm-6 col-xs-6 col-12">
                                <div class="tab-pane fade show active toacart w-100" data-url="' . url('/pos/product-variant/' . $product->id . '/' . $lastsegment) . '" data-ajax-popup="true" data-size="lg" data-align="centered" data-title="Product Variant">
                                    <div class="btn-primary position-relative card">
                                        <img alt="Image placeholder" src="' . asset($image_url) . '" class="card-image avatar hover-shadow-lg" style=" height: 6rem; width: 100%;">
                                        <div class="p-0 custom-card-body card-body d-flex ">
                                            <div class="card-body my-2 p-2 text-center card-bottom-content">
                                                <h6 class="mb-2 text-dark product-title-name">' . $product->name . '</h6>
                                                <p class="mb-2 text-dark product-title-name small">' . $product->slug . '</p>
                                                <small class="text-primary">In Variant</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div> ';
                    }

                }
                return Response($output);
            } else {
                $output = '<div class="card card-body col-12 text-center">
                    <h5>' . __("No Product Available") . '</h5>
                    </div>';
                return Response($output);
            }
        }
    }
}
