<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Product;
use App\Models\Category;
use App\Models\Shipping;
use App\Models\Tax;
use App\Models\Tag;
use App\Models\Setting;
use App\Models\User;
use App\Models\Plan;
use App\Models\Utility;
use App\Models\Store;
use App\Models\ProductAttributeOption;
use App\Models\ProductAttribute;
use App\Models\ProductVariant;
use App\Models\ProductImage;
use App\Models\ProductBrand;
use App\Models\ProductLabel;
use Illuminate\Support\Facades\File;
use App\Models\NotifyUser;
use App\Models\ShopifyConection;
use App\Models\WoocommerceConection;
use App\DataTables\ProductDataTable;
use App\Models\FlashSale;
use Illuminate\Support\Facades\Cache;
use App\Models\TaxOption;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request,ProductDataTable $dataTable)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Products')) {
            $products = Product::where('product_type', null)->where('store_id', getCurrentStore())->orderBy('id', 'desc')->get();
            $settings = Setting::where('store_id', getCurrentStore())->pluck('value', 'name')->toArray();

            if ($request->id == 1) {
                $msg = __('Product Successfully Created');
                return $dataTable->render('product.index', compact('products', 'settings', 'msg'));
            } elseif ($request->id == 2) {
                $msg = __('Product Successfully Updated');
                return $dataTable->render('product.index', compact('products', 'settings', 'msg'));
            } else {
                $msg = 0;
                return $dataTable->render('product.index', compact('products', 'settings', 'msg'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $store = getStoreById(getCurrentStore());
        $link = env('APP_URL') . ($store->slug ? ('/'.$store->slug) : '').'/product/';
        $categories = Category::where('store_id',getCurrentStore())->get();
        $categoryTree = buildCategoryTree($categories);
        $Tax = Tax::where('store_id', getCurrentStore())->pluck('name', 'id');
        $Tax_status = Tax::Taxstatus();

        $Shipping = Shipping::where('store_id', getCurrentStore())->pluck('name', 'id')->prepend('Select Shipping', '');
        $brands = ProductBrand::where('status', 1)->where('store_id', getCurrentStore())->pluck('name', 'id')->prepend('Select Brand', '');
        $labels = ProductLabel::where('status', 1)->where('store_id', getCurrentStore())->pluck('name', 'id')->prepend('Select Label', '');

        $preview_type = [
            'Video File' => 'Video File',
            'Video Url' => 'Video Url',
            'iFrame' => 'iFrame'
        ];
        $tag = Tag::where('store_id', getCurrentStore())->pluck('name', 'id');
        $ProductAttribute = ProductAttribute::where('store_id', getCurrentStore())->pluck('name', 'id');
        
        
        return view('product.create', compact('link', 'categoryTree', 'ProductAttribute', 'Tax', 'Tax_status', 'Shipping', 'preview_type', 'tag', 'brands', 'labels'));
    }









  public function store(Request $request)
    {
        
        if (auth()->user() && auth()->user()->isAbleTo('Create Product')) {
            try {
                $user = \Auth::user();
                $creator = User::find($user->creatorId());
                $total_products = $user->countProducts();
                $plan = Plan::find($creator->plan_id);
                $store_id = getStoreById(getCurrentStore());

                if ($request->variant_product == 0) {
                    $rules = [
                        'name' => 'required',
                        'category_id' => 'required',
                        'cover_image' => 'required',
                        'product_image' => 'required',
                        'status' => 'required',
                        'variant_product' => 'required',
                    'price' => 'required|numeric|min:0',
'sale_price' => 'nullable|numeric|min:0|lt:price',
'cost_price' => 'required|numeric|min:0|lte:price',
                        'brand_id' => 'nullable',
                        'label_id' => 'nullable',

                    ];
                } else {
                    $rules = [
                        'name' => 'required',
                        'category_id' => 'required',
                        'cover_image' => 'required',
                        'product_image' => 'required',
                        'status' => 'required',
                        'variant_product' => 'required',
                        'brand_id' => 'nullable',
                        'label_id' => 'nullable',
                    ];
                }

                $validator = \Validator::make($request->all(), $rules,[
                    'sale_price.lt' => __('The sale price must be less than the regular price.'),
    'cost_price.required' => __('Cost price is required.'),
    'cost_price.lte' => __('Cost price must not be greater than the regular price.')
                ]);
                $request->sale_price = $request->sale_price ? $request->sale_price : 0;
                if ($validator->fails()) {
                    $messages = $validator->getMessageBag();
                    $msg['flag'] = 'error';
                    $msg['msg'] = $messages->first();

                    return $msg;
                }

                $totalImageSize = 0;
                if ($request->hasFile('cover_image')) {
                    $totalImageSize += $request->file('cover_image')->getSize();
                }
                if ($request->hasFile('preview_video')) {
                    $totalImageSize += $request->file('preview_video')->getSize();
                }
                if ($request->hasFile('downloadable_product')) {
                    $totalImageSize += $request->file('downloadable_product')->getSize();
                }
                if (!empty($request->product_image)) {
                    foreach ($request->product_image as $key => $image) {
                        $totalImageSize += File::size($image);
                    }
                }
                $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $totalImageSize);
                if ($result != 1) {
                    $msg['flag'] = 'error';
                    $msg['msg'] = $result;

                    return $msg;
                }
                $dir = 'uploads/' . $store_id->id;
                if ($request->variant_product == 0) {
                    if ($total_products < $plan->max_products || $plan->max_products == -1) {
                        $input = $request->all();
                        $input['attribute_options'] = [];
                        if ($request->has('attribute_no')) {
                            foreach ($request->attribute_no as $key => $no) {
                                $str = 'attribute_options_' . $no;
                                $enable_option = $input['visible_attribute_' . $no];
                                $variation_option = $input['for_variation_' . $no];
                                $item['attribute_id'] = $no;

                                if (!empty($request[$str])) {
                                    $item['values'] = explode(',', implode('|', $request[$str]));
                                } else {
                                    $item['values'] = [];
                                }

                                $item['visible_attribute_' . $no] = $enable_option;
                                $item['for_variation_' . $no] = $variation_option;
                                array_push($input['attribute_options'], $item);
                            }
                        }



                        if (!empty($request->attribute_no)) {
                            $input['product_attributes'] = implode(',', $request->attribute_no);
                        } else {
                            $input['product_attributes'] = 0;
                        }
                        $input['attribute_options'] = json_encode($input['attribute_options']);
                        $Product = new Product();
                        $Product->name = $request->name;
                        if (isset($request->slug) && !empty($request->slug)) {
                            $Product->slug = $request->slug;
                        } else {
                            $Product->slug = Product::slugs($request->name);
                        }

                        $Product->category_id = $request->category_id;
                        $Product->brand_id = $request->brand_id ?? null;
                        $Product->label_id = $request->label_id ?? null;
                        if (!empty($request->tax_id)) {
                            $Product->tax_id = implode(',', $request->tax_id);
                        } else {
                            $tax = Tax::where('store_id', getCurrentStore())->first();
                            if (isset($tax)) {
                                $Product->tax_id = $tax->id;
                            }
                        }

                        if ($request->cover_image) {

                            if ($result == 1) {
                                $fileName = rand(10, 100) . '_' . time() . "_" . $request->cover_image->getClientOriginalName();
                                $path = Utility::upload_file($request, 'cover_image', $fileName, $dir, []);
                                if ($path['flag'] == 0) {
                                    $msg['flag'] = 'error';
                                    $msg['msg'] =  $path['msg'];

                                    return $msg;
                                }
                            } else {

                                $msg['flag'] = 'error';
                                $msg['msg'] = $result;

                                return $msg;
                            }

                            $Product->cover_image_path = $path['url'] ?? null;
                            $Product->cover_image_url = $path['full_url'] ?? null;
                        }

                        $Product->preview_type = $request->preview_type;

                        if (!empty($request->preview_video)) {

                            $ext = $request->file('preview_video')->getClientOriginalExtension();
                            $fileName = 'video_' . time() . rand() . '.' . $ext;
                            $dir_video = 'uploads/' . $store_id->id. '/video';
                            if ($result == 1) {
                                $path_video = Utility::upload_file($request, 'preview_video', $fileName, $dir_video, []);
                                if ($path_video['flag'] == 1) {
                                    $url = $path_video['url'] ?? '';
                                } else {
                                    $msg['flag'] = 'error';
                                    $msg['msg'] = $path_video['msg'];

                                    return $msg;
                                }
                            } else {

                                $msg['flag'] = 'error';
                                $msg['msg'] =  $result;

                                return $msg;
                            }
                            $Product->preview_content = $path_video['url'] ?? null;
                        }
                        if (!empty($request->video_url)) {
                            $Product->preview_content = $request->video_url;
                        }
                        if (!empty($request->preview_iframe)) {
                            $Product->preview_content = $request->preview_iframe;
                        }
                        $Product->tax_status = $request->tax_status;
                        $Product->shipping_id = $request->shipping_id;
                        $Product->product_weight = $request->product_weight;
                        $Product->variant_product = $request->variant_product;
                        $Product->trending = $request->trending;
                        $Product->status = $request->status;
                        $Product->description = $request->description;
                        $Product->specification = $request->specification;
                        $Product->detail = $request->detail;
                        $Product->price = $request->price;
                        $Product->sale_price = $request->sale_price;
$Product->cost_price = $request->cost_price;
                        $Product->stock_status = $request->stock_status;
                        $Product->attribute_id = $input['product_attributes'] ?? null;
                        $Product->product_attribute = $input['attribute_options'];

                        $Product->product_stock = !empty($request->product_stock) ? $request->product_stock : 0;
                        if ($request->track_stock == 1) {
                            $Product->track_stock = $request->track_stock ?? 0;
                            $Product->stock_order_status = $request->stock_order_status;
                            $Product->low_stock_threshold = !empty($request->low_stock_threshold) ? $request->low_stock_threshold :  '';
                        } else {
                            $Product->track_stock = !empty($request->track_stock) ? $request->track_stock : 0;
                            $Product->stock_order_status = '';
                            $Product->low_stock_threshold = !empty($request->low_stock_threshold) ? $request->low_stock_threshold :  '';
                        }
                       

                        if (!empty($request->downloadable_product)) {

                            if ($result == 1) {
                                $fileName = rand(10, 100) . '_' . time() . "_" . $request->downloadable_product->getClientOriginalName();
                                $path = Utility::upload_file($request, 'downloadable_product', $fileName, $dir, []);
                                if ($path['flag'] == 0) {
                                    $msg['flag'] = 'error';
                                    $msg['msg'] =  $path['msg'];

                                    return $msg;
                                }
                            } else {
                                $msg['flag'] = 'error';
                                $msg['msg'] =  $result;

                                return $msg;
                            }
                            $Product->downloadable_product = $path['url'];
                        }

                        $tag_data_id = [];
                        $tag_ids = [];

                        if (isset($request->tag_id)) {

                            foreach ($request->tag_id as $tag) {
                                $tags = Tag::where('id', $tag)->where('store_id', getCurrentStore())->first();
                                if (!empty($tags)) {
                                    $tag_id = $tags->id;
                                    $tag_ids[] = $tag_id;
                                } else {
                                    $tag_id = 0;
                                }
                                if ($tag_id != $tag) {
                                    $tag_data = new Tag();
                                    $tag_data->name = $tag;
                                    $tag_data->slug = Tag::slugs($tag);
                                    $tag_data->store_id = getCurrentStore();
                                    $tag_data->created_by = \Auth::user()->id;
                                    $tag_data->save();

                                    $tag_data_id[] = $tag_data->id;
                                }
                            }
                        }
                        $tag_product_id = array_merge($tag_data_id, $tag_ids);
                        if (!empty($tag_product_id)) {

                            $Product->tag_id =  implode(',', $tag_product_id);
                        }
                        $Product->store_id = getCurrentStore();
                        $Product->created_by = \Auth::user()->id;
                        if (module_is_active('CartQuantityControl')) {
                            \Workdo\CartQuantityControl\app\Models\CartQuantityControl::saveData($Product, $request->minimum_quantity, $request->maximum_quantity);
                        }

                        $Product->save();
                        if (module_is_active('PartialPayments')) {
                            \Workdo\PartialPayments\app\Models\PartialPayments::saveData($Product,$request->all());
                        }
                        if (module_is_active('SizeGuideline')) {
                            \Workdo\SizeGuideline\app\Models\SizeGuideline::saveData($Product, $request->size_chart_title, $request->size_chart_information,$request->all());
                        }
                        if (module_is_active('WholesaleProduct')) {
                            \Workdo\WholesaleProduct\app\Models\WholesaleProduct::saveData($Product, $request->wholesale_repeater_basic);
                        }
                        if (module_is_active('ProductBarCode')) {
                            \Workdo\ProductBarCode\app\Models\ProductBarCode::saveData($Product);
                        }

                        if (module_is_active('HubSpot')) {
                            $setting = getAdminAllSetting(auth()->user()->id, getCurrentStore());
                            if (isset($setting['hubspot_is_enable']) && $setting['hubspot_is_enable'] == 'on') {
                                \Workdo\HubSpot\app\Models\HubSpotProductService::saveData($Product, $request->all());
                            }
                        }

                       
                        if (module_is_active('AdditionalCustomFields')) {
                            $settingController = new \Workdo\AdditionalCustomFields\app\Http\Controllers\SettingController();
                            $settingController->saveData($Product, $request->all());
                        }
                       
                        if (!empty($Product))
                        {
                            //webhook
                            $module = 'New Product';
                            $webhook =  Utility::webhook($module, $store_id->id);
                            if ($webhook) {
                                $parameter = json_encode($Product);
                                // 1 parameter is  URL , 2 parameter is data , 3 parameter is method
                                $status = Utility::WebhookCall($webhook['url'], $parameter, $webhook['method']);

                                if ($status != true) {
                                    $msgs  = 'Webhook call failed.';
                                }

                                $msg['flag'] = 'success';
                                $msg['msg']  = __('Product Successfully Created') . ((isset($msgs)) ? '<br> <span class="text-danger">' . $msgs . '</span>' : '');
                                // $msg['msg']  = __('Product Successfully Created');
                            }
                        }
                        foreach ($request->product_image as $key => $image) {
                            $theme_image = $image;

                            if ($result == 1) {
                                $fileName = rand(10, 100) . '_' . time() . "_" . $image->getClientOriginalName();
                                $pathss = Utility::keyWiseUpload_file($request, 'product_image', $fileName, $dir, $key, []);
                            } else {
                                $msg['flag'] = 'error';
                                $msg['msg'] =  $result;

                                return $msg;
                            }

                            if (isset($pathss['url'])) {
                                $ProductImage = new ProductImage();
                                $ProductImage->product_id = $Product->id;
                                $ProductImage->image_path = $pathss['url'];
                                $ProductImage->image_url  = $pathss['full_url'];
                                $ProductImage->store_id   = getCurrentStore();
                                $ProductImage->save();
                            }
                        }
                    } else {
                        $msg['flag'] = 'error';
                        $msg['msg'] =   __('Your Product limit is over, Please upgrade plan');

                        return $msg;
                    }
                } else {

                    $input = $request->all();

                    $input['choice_options'] = [];
                    $input['attribute_options'] = [];
                    if ($request->has('choice_no')) {
                        foreach ($request->choice_no as $key => $no) {
                            $str = 'choice_options_' . $no;

                            $item['attribute_id'] = $no;
                            $item['values'] = explode(',', implode('|', $request[$str]));
                            array_push($input['choice_options'], $item);
                        }
                    }

                    if (!empty($request->choice_no)) {
                        $input['attributes'] = json_encode($request->choice_no);
                    } else {
                        $input['attributes'] = json_encode([]);
                    }

                    $input['choice_options'] = json_encode($input['choice_options']);
                    $input['slug'] = $input['name'];

                    if ($request->has('attribute_no')) {
                        foreach ($request->attribute_no as $key => $no) {
                            $str = 'attribute_options_' . $no;
                            $enable_option = $input['visible_attribute_' . $no];
                            $variation_option = $input['for_variation_' . $no];

                            $item['attribute_id'] = $no;
                            if (!empty($request[$str])) {
                                $item['values'] = explode(',', implode('|', $request[$str]));
                            } else {
                                $item['values'] = [];
                            }
                            $item['visible_attribute_' . $no] = $enable_option;
                            $item['for_variation_' . $no] = $variation_option;

                            array_push($input['attribute_options'], $item);
                        }
                    }
                    if (!empty($request->attribute_no)) {
                        $input['product_attributes'] = implode(',', $request->attribute_no);
                    }
                    $input['attribute_options'] = json_encode($input['attribute_options']);

                    $theme_name = APP_THEME();
                    if ($request->cover_image) {

                        if ($result == 1) {
                            $fileName = rand(10, 100) . '_' . time() . "_" . $request->cover_image->getClientOriginalName();
                            $path = Utility::upload_file($request, 'cover_image', $fileName, $dir, []);
                            if ($path['flag'] == 0) {
                                $msg['flag'] = 'error';
                                $msg['msg'] =  $path['msg'];

                                return $msg;
                            }
                        } else {
                            $msg['flag'] = 'error';
                            $msg['msg'] =  $result;

                            return $msg;
                        }
                    }
                    if ($total_products < $plan->max_products || $plan->max_products == -1) {


                        $product = new Product();
                        $product->name = $request->name;
                        if (isset($request->slug) && !empty($request->slug)) {
                            $product->slug = $request->slug;
                        } else {
                            $product->slug = Product::slugs($request->name);
                        }
                        $product->category_id = $request->category_id;
                        if (!empty($request->tax_id)) {
                            $product->tax_id = implode(',', $request->tax_id);
                        } else {
                            $tax = Tax::where('store_id', getCurrentStore())->first();
                            if (isset($tax)) {
                                $product->tax_id = $tax->id;
                            }
                        }
                        $product->tax_status = $request->tax_status;
                        $product->shipping_id = $request->shipping_id;
                        $product->status = $request->status;
                        $product->description = $request->description;
                        $product->specification = $request->specification;
                        $product->detail = $request->detail;
                        $tag_id = $request->tag;
                        $product->cover_image_path = $path['url'] ?? null;
                        $product->cover_image_url = $path['full_url'] ?? null;
                        $product->attribute_id = $input['product_attributes'] ?? null;
                        $product->product_attribute = $input['attribute_options'];


                        $product->preview_type = $request->preview_type;
                        if (!empty($request->video_url)) {
                            $product->preview_content = $request->video_url;
                        }
                        if (!empty($request->preview_video)) {
                            $ext = $request->file('preview_video')->getClientOriginalExtension();
                            $fileName = 'video_' . time() . rand() . '.' . $ext;

                            $dir_video = 'uploads/' .getCurrentStore() . '/video';

                            if ($result == 1) {
                                $path_video = Utility::upload_file($request, 'preview_video', $fileName, $dir_video, []);
                                if ($path_video['flag'] == 1) {
                                    $url = $path_video['url'];
                                } else {
                                    $msg['flag'] = 'error';
                                    $msg['msg'] =  $path_video['msg'];

                                    return $msg;
                                }
                            } else {
                                $msg['flag'] = 'error';
                                $msg['msg'] =  $result;

                                return $msg;
                            }
                            $product->preview_content = $path_video['url'];
                        }
                        if (!empty($request->preview_iframe)) {
                            $product->preview_content = $request->preview_iframe;
                        }
                        if (!empty($request->downloadable_product)) {

                            if ($result == 1) {
                                $fileName = rand(10, 100) . '_' . time() . "_" . $request->downloadable_product->getClientOriginalName();
                                $path = Utility::upload_file($request, 'downloadable_product', $fileName, $dir, []);
                                if ($path['flag'] == 0) {
                                    $msg['flag'] = 'error';
                                    $msg['msg'] =  $path['msg'];

                                    return $msg;
                                }
                            } else {
                                $msg['flag'] = 'error';
                                $msg['msg'] =  $result;

                                return $msg;
                            }
                            $product->downloadable_product = $path['url'];
                        }
                        $product->product_stock = !empty($request->product_stock) ? $request->product_stock : 0;
                        $product->variant_product = $request->variant_product;
                        $product->trending = $request->trending;
                        if (isset($request->track_stock) && $request->track_stock == 1) {
                            $product->track_stock = $request->track_stock;
                            $product->stock_order_status = $request->stock_order_status;
                            $product->low_stock_threshold = !empty($request->low_stock_threshold) ? $request->low_stock_threshold : 0;
                        } else {
                            $product->track_stock = isset($request->track_stock) ? $request->track_stock : 0;
                            $product->stock_order_status = '';
                            $product->low_stock_threshold = !empty($request->low_stock_threshold) ? $request->low_stock_threshold : 0;
                        }


                        $product->attribute_id = $input['product_attributes'] ?? null;
                        $product->product_attribute = $input['attribute_options'];
                        // $product->product_option = json_encode($option_array);
                        // $product->product_option_api = json_encode($option_array_api);
                        $product->shipping_id = $request->shipping_id;
                        $product->store_id = getCurrentStore();
                        $product->created_by = \Auth::user()->id;
                        if ($request->custom_field_status == '1') {
                            $product->custom_field_status = '1';
                            $product->custom_field = json_encode($request->custom_field_repeater_basic);
                        }
                        $tag_data_id = [];
                        $tag_ids = [];

                        if (isset($request->tag_id)) {
                            foreach ($request->tag_id as $tag) {
                                $tags = Tag::where('id', $tag)->where('store_id', getCurrentStore())->first();
                                if (!empty($tags)) {
                                    $tag_id = $tags->id;
                                    $tag_ids[] = $tag_id;
                                } else {
                                    $tag_id = 0;
                                }
                                if ($tag_id != $tag) {
                                    $tag_data = new Tag();
                                    $tag_data->name = $tag;
                                    $tag_data->store_id = getCurrentStore();
                                    $tag_data->created_by = \Auth::user()->id;
                                    $tag_data->save();

                                    $tag_data_id[] = $tag_data->id;
                                }
                            }
                        }
                        $tag_product_id = array_merge($tag_data_id, $tag_ids);
                        if (!empty($tag_product_id)) {

                            $product->tag_id =  implode(',', $tag_product_id);
                        }
                        $product->save();
                        if (module_is_active('PartialPayments')) {
                            \Workdo\PartialPayments\app\Models\PartialPayments::saveData($product,$request->all());
                        }
                        if (module_is_active('SizeGuideline')) {

                            \Workdo\SizeGuideline\app\Models\SizeGuideline::saveData($product, $request->size_chart_title,$request->size_chart_information,$request->all());
                        }
                        if (module_is_active('WholesaleProduct')) {
                            \Workdo\WholesaleProduct\app\Models\WholesaleProduct::saveData($product, $request->wholesale_repeater_basic);
                        }
                        if (module_is_active('ProductBarCode')) {

                            \Workdo\ProductBarCode\app\Models\ProductBarCode::saveData($product);
                        }
                  
                        if (module_is_active('AdditionalCustomFields')) {
                            $settingController = new \Workdo\AdditionalCustomFields\app\Http\Controllers\SettingController();
                            $settingController->saveData($product, $request->all());
                        }
                  
                
                        if (!empty($product))
                        {
                            //webhook
                            $module = 'New Product';
                            $webhook =  Utility::webhook($module, $store_id->id);
                            if ($webhook) {
                            $parameter = json_encode($product);
                            // 1 parameter is  URL , 2 parameter is data , 3 parameter is method
                            $status = Utility::WebhookCall($webhook['url'], $parameter, $webhook['method']);

                            if ($status != true) {
                                $msgs  = 'Webhook call failed.';
                            }

                            $msg['flag'] = 'success';
                                    // $msg['msg']  = __('Product Successfully Created') . ((isset($msgs)) ? '<br> <span class="text-danger">' . $msgs . '</span>' : '');
                                    $msg['msg']  = __('Product Successfully Created');
                            }
                        } else {
                            $msg['flag'] = 'error';
                            $msg['msg']  = __('Product Created Failed');

                            return redirect()->back()->with($msg['flag'], $msg['msg']);
                        }

                        foreach ($request->product_image as $key => $image) {
                            $theme_image = $image;

                            if ($result == 1) {
                                $fileName = rand(10, 100) . '_' . time() . "_" . $image->getClientOriginalName();
                                $pathss = Utility::keyWiseUpload_file($request, 'product_image', $fileName, $dir, $key, []);
                            } else {
                                $msg['flag'] = 'error';
                                $msg['msg'] =  $result;

                                return $msg;
                            }

                            if (isset($pathss['url'])) {
                                $ProductImage = new ProductImage();
                                $ProductImage->product_id = $product->id;
                                $ProductImage->image_path = $pathss['url'];
                                $ProductImage->image_url  = $pathss['full_url'];
                                $ProductImage->store_id   = getCurrentStore();
                                $ProductImage->save();
                            }
                        }

                        $options = [];
                        $a_option = [];
                        if ($request->has('choice_no')) {
                            foreach ($request->choice_no as $key => $no) {
                                $name = 'choice_options_' . $no;
                                $my_str = implode('|', $request[$name]);
                                array_push($options, explode(',', $my_str));
                            }
                        }

                        $combinations = $this->combinations($options);
                        foreach ($request->attribute_no as $key => $no) {
                            $forVariationName = 'for_variation_' . $no;
                            if ($request->has($forVariationName) && $request->input($forVariationName) == 1) {
                                $name = 'attribute_options_' . $no;
                                $options = 'options';
                                $for_variation = isset($request->{'for_variation_' . $no}) ? $request->{'for_variation_' . $no} : 0;
                                if ($for_variation == 1) {
                                    if ($request->has($options) && is_array($request[$options])) {
                                        $my_str = $request[$options];
                                        $optionValues = [];

                                        foreach ($request[$options] as $term) {
                                            $optionValues[] = $term;
                                        }
                                        array_push($a_option, $my_str);
                                    }
                                }
                            }
                        }

                        $default_variant_id = 0;
                        if (!empty($a_option[0])) {
                            if (count($a_option[0]) > 0) {
                                $product->variant_product = 1;
                                $is_in_stock = false;
                                foreach ($a_option as $key => $com) {
                                    $str = '';
                                    foreach ($com as $key => $item) {

                                        $str = $item;

                                        $product_stock = ProductVariant::where('product_id', $product->id)->where('variant', $str)->first();
                                        if ($product_stock == null) {
                                            $product_stock = new ProductVariant;
                                            $product_stock->product_id = $product->id;
                                        }

                                        $theme_name = APP_THEME();
                                        if ($request['downloadable_product_' . str_replace('.', '_', $str)]) {
                                            $fileName = rand(10, 100) . '_' . time() . "_" . $request->file('downloadable_product_' . $str)->getClientOriginalName();

                                            $path1 = Utility::upload_file($request, 'downloadable_product_' . $str, $fileName, $dir, []);
                                            if ($path1['flag'] == 0) {
                                                $msg['flag'] = 'error';
                                                $msg['msg'] =  $path1['msg'];
            
                                                return $msg;
                                            }
                                            $product_stock->downloadable_product = $path1['url'];
                                        }


                                        $var_option = "";
                                        $variation_option = !empty($request['variation_option_' . str_replace('.', '_', $str)]) ? $request['variation_option_' . str_replace('.', '_', $str)] : '';
                                        if (is_array($variation_option)) {
                                            foreach ($variation_option as $option) {
                                                $var_option .= $option . ",";
                                            }
                                        }

                                        $sku = str_replace(' ', '_', $request->name) . $request['product_sku_' . str_replace('.', '_', $str)];


                                        $product_stock->variant = $str;

                                        $product_stock->variation_option = $var_option;

                                        $product_stock->price = !empty($request['product_sale_price_' . str_replace('.', '_', $str)]) ? $request['product_sale_price_' . str_replace('.', '_', $str)] : 0;

                                        $product_stock->variation_price = !empty($request['product_variation_price_' . str_replace('.', '_', $str)]) ? $request['product_variation_price_' . str_replace('.', '_', $str)] : 0;
                                        $product_stock->sku = $sku;

                                        $product_stock->stock_status = !empty($request['stock_status_' . str_replace('.', '_', $str)]) ? $request['stock_status_' . str_replace('.', '_', $str)] : '';
                                        if ($variation_option) {
                                            if (in_array('manage_stock', $variation_option)) {
                                                $product_stock->stock_order_status = !empty($request['stock_order_status_' . str_replace('.', '_', $str)]) ? $request['stock_order_status_' . str_replace('.', '_', $str)] : 0;

                                                $product_stock->stock = !empty($request['product_stock_' . str_replace('.', '_', $str)]) ? $request['product_stock_' . str_replace('.', '_', $str)] : 0;
                                                $product_stock->low_stock_threshold = !empty($request['low_stock_threshold_' . str_replace('.', '_', $str)]) ? $request['low_stock_threshold_' . str_replace('.', '_', $str)] : 0;
                                            } else {

                                                $product_stock->stock = 0;
                                                $product_stock->stock_order_status = '';
                                                $product_stock->low_stock_threshold = !empty($request['low_stock_threshold_' . str_replace('.', '_', $str)]) ? $request['low_stock_threshold_' . str_replace('.', '_', $str)] : 0;
                                            }
                                        }
                                        $product_stock->weight = !empty($request['product_weight_' . str_replace('.', '_', $str)]) ? $request['product_weight_' . str_replace('.', '_', $str)] : 0;

                                        $product_stock->description = !empty($request['product_description_' . str_replace('.', '_', $str)]) ? $request['product_description_' . str_replace('.', '_', $str)] : '';

                                        $product_stock->shipping = !empty($request['shipping_id_' . str_replace('.', '_', $str)]) ? $request['shipping_id_' . str_replace('.', '_', $str)] : 'same_as_parent';

                                        $product_stock->store_id = getCurrentStore();

                                        if (module_is_active('CartQuantityControl')) {
                                            \Workdo\CartQuantityControl\app\Models\CartQuantityControl::variantSaveData($request, $product_stock, $str);
                                        }

                                        $product_stock->save();

                                        if ($request->default_variant ==  '-' . $str) {
                                            $product_update = Product::find($product->id);
                                            $product_update->default_variant_id = $product_stock->id;
                                            $product_update->save();
                                        }
                                        if ($product_stock->stock == 'in_stock') {
                                            $is_in_stock = true;
                                        }
                                    }
                                }
                                if (!$is_in_stock) {
                                    $product->stock = 'out_of_stock';
                                }
                            } else {
                                $product->variant_product = 0;
                            }
                        } else {
                            $product->variant_product = 0;
                        }
                    } else {
                        $msg['flag'] = 'error';
                        $msg['msg'] =  __('Your Product limit is over, Please upgrade plan');

                        return $msg;
                    }
                }

                $msg['flag'] = 'success';
                $msg['msg'] =  __('Product saved successfully.');
                return $msg;
            } catch (\Exception $e) {
                \Log::info(['error' => $e]);
                $msg['flag'] = 'error';
                $msg['msg'] = $e->getMessage();
                return $msg;
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }





















    /**
     * Display the specified resource.
     */
    public function show(Product $product)
    {
        /*
         * OWNERSHIP ON THE BINDING. P1-INV-01 / S37-1.
         *
         * `show()` is reached with a route-model-bound `Product $product` and had no ownership
         * check at all, so another store's product rendered into this merchant's form -- name,
         * prices, cost, stock and variant rows. A read rather than a write, and the same binding
         * the write used. Same shape as `destroy()` and `update()`: platform operator exempt,
         * then `getCurrentStore()` against the product's own `store_id`.
         */
        $actor = auth()->user();

        if ($actor === null || $actor->type !== \App\Policies\UserPolicy::PLATFORM_ADMIN) {
            $currentStore = getCurrentStore();

            if (
                empty($currentStore)
                || empty($product->store_id)
                || (int) $product->store_id !== (int) $currentStore
            ) {
                return redirect()->back()->with('error', __('Permission denied.'));
            }
        }

        $store = getStoreById(getCurrentStore());
        $link = env('APP_URL') . ($store->slug ? ('/'.$store->slug) : '').'/product/';
        $Category = Category::where('store_id', getCurrentStore())->pluck('name', 'id')->prepend('Select Category', '');
        $Tax = Tax::where('store_id', getCurrentStore())->pluck('name', 'id');
        $Tax_status = Tax::Taxstatus();
        $Shipping = Shipping::where('store_id', getCurrentStore())->pluck('name', 'id')->prepend('Select Shipping', '');
        $preview_type = [
            'Video File' => 'Video File',
            'Video Url' => 'Video Url',
            'iFrame' => 'iFrame'
        ];
        $ProductAttribute = ProductAttribute::where('store_id', getCurrentStore())->pluck('name', 'id');
        $product_image = ProductImage::where('product_id', $product->id)->where('store_id', getCurrentStore())->get();
        $get_tax = explode(',', $product->tax_id);
        $get_datas = explode(',', $product->attribute_id);
        $tag = Tag::where('store_id', getCurrentStore())->pluck('name', 'id');
        $get_tags = explode(',', $product->tag_id);

        $brands = ProductBrand::where('status', 1)->where('store_id', getCurrentStore())->pluck('name', 'id')->prepend('Select Brand', '');
        $labels = ProductLabel::where('status', 1)->where('store_id', getCurrentStore())->pluck('name', 'id')->prepend('Select Label', '');

        $compact = ['link', 'product', 'Category', 'Tax', 'Tax_status', 'Shipping', 'preview_type', 'ProductAttribute', 'product_image', 'get_tax', 'get_datas', 'tag', 'get_tags', 'brands', 'labels'];
        return view('product.show', compact($compact));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Product $product)
    {
        /*
         * OWNERSHIP ON THE BINDING. P1-INV-01 / S37-1.
         *
         * `edit()` is reached with a route-model-bound `Product $product` and had no ownership
         * check at all, so another store's product rendered into this merchant's form -- name,
         * prices, cost, stock and variant rows. A read rather than a write, and the same binding
         * the write used. Same shape as `destroy()` and `update()`: platform operator exempt,
         * then `getCurrentStore()` against the product's own `store_id`.
         */
        $actor = auth()->user();

        if ($actor === null || $actor->type !== \App\Policies\UserPolicy::PLATFORM_ADMIN) {
            $currentStore = getCurrentStore();

            if (
                empty($currentStore)
                || empty($product->store_id)
                || (int) $product->store_id !== (int) $currentStore
            ) {
                return redirect()->back()->with('error', __('Permission denied.'));
            }
        }

        $store = getStoreById(getCurrentStore());
        $link = env('APP_URL') . ($store->slug ? ('/'.$store->slug) : '').'/product/';
        $categories = Category::where('store_id', getCurrentStore())->get();
        $categoryTree = buildCategoryTree($categories);
        $Tax = Tax::where('store_id', getCurrentStore())->pluck('name', 'id');
        $Tax_status = Tax::Taxstatus();
        $Shipping = Shipping::where('store_id', getCurrentStore())->pluck('name', 'id')->prepend('Select Shipping', '');
        $preview_type = [
            'Video File' => 'Video File',
            'Video Url' => 'Video Url',
            'iFrame' => 'iFrame'
        ];
        $ProductAttribute = ProductAttribute::where('store_id', getCurrentStore())->pluck('name', 'id');
        $product_image = ProductImage::where('product_id', $product->id)->where('store_id', getCurrentStore())->get();
        $get_tax = explode(',', $product->tax_id);
        $get_datas = explode(',', $product->attribute_id);
        $tag = Tag::where('store_id', getCurrentStore())->pluck('name', 'id');
        $get_tags = explode(',', $product->tag_id);

        $brands = ProductBrand::where('status', 1)->where('store_id', getCurrentStore())->pluck('name', 'id')->prepend('Select Brand', '');
        $labels = ProductLabel::where('status', 1)->where('store_id', getCurrentStore())->pluck('name', 'id')->prepend('Select Label', '');
       
        $compact = ['link', 'product', 'categories', 'categoryTree', 'Tax', 'Tax_status', 'Shipping', 'preview_type', 'ProductAttribute', 'product_image', 'get_tax', 'get_datas', 'tag', 'get_tags', 'brands', 'labels'];
        return view('product.edit', compact($compact));
    }



























 public function update(Request $request, Product $product)
    {
       
        if (auth()->user() && auth()->user()->isAbleTo('Edit Products')) {
            $actor = auth()->user();

            /*
             * OWNERSHIP, BEFORE ANYTHING IS WRITTEN. P1-INV-01 / S37-1.
             *
             * `isAbleTo('Edit Products')` answers "may this user edit products" and never "may
             * this user edit THIS product". The target is a route-model-bound `Product $product`,
             * `App\Models\Product` is not in config/tenancy.php's `scoped_models` and registers
             * no effective global scope (CLAUDE.md), so the binding resolves across every tenant.
             *
             * REPRODUCED BY EXECUTION before this guard was written: a store-A merchant with
             * `Edit Products` on their own store sent `PUT /product/{store B id}` and renamed the
             * victim's product and set its `product_stock` 50 -> 7.
             *
             * The shape is `destroy()`'s, deliberately — same method, same order, same message —
             * rather than a second idiom for one question: `isAbleTo` first, the platform operator
             * exempt via UserPolicy::PLATFORM_ADMIN (matching OrderPolicy::allow), then
             * `getCurrentStore()` against the product's own `store_id`. An empty current store or
             * an unowned product is refused: `getCurrentStore()` returns null for a user whose
             * `current_store` is empty and who owns no store, and a null store must never read as
             * "matches".
             */
            if ($actor->type !== \App\Policies\UserPolicy::PLATFORM_ADMIN) {
                $currentStore = getCurrentStore();

                if (
                    empty($currentStore)
                    || empty($product->store_id)
                    || (int) $product->store_id !== (int) $currentStore
                ) {
                    return redirect()->back()->with('error', __('Permission denied.'));
                }
            }

            $dir        = 'uploads/' .getCurrentStore();
            if ($request->variant_product == 0) {
                $rules = [
                    'name' => 'required',
                    'category_id' => 'required',
                    'status' => 'required',
                    'variant_product' => 'required',
                    'brand_id' => 'nullable',
                    'label_id' => 'nullable',
                  'price' => 'required|numeric|min:0',
'sale_price' => 'nullable|numeric|min:0|lt:price',
'cost_price' => 'required|numeric|min:0|lte:price',                ];
            }else
            {
                $rules = [
                    'name' => 'required',
                    'category_id' => 'required',
                    'status' => 'required',
                    'variant_product' => 'required',
                    'brand_id' => 'nullable',
                    'label_id' => 'nullable',
                ];
            }

            $validator = \Validator::make($request->all(), $rules,[
                 'sale_price.lt' => __('The sale price must be less than the regular price.'),
    'cost_price.required' => __('Cost price is required.'),
    'cost_price.lte' => __('Cost price must not be greater than the regular price.')
            ]);
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                $msg['flag'] = 'error';
                $msg['msg'] =  $messages->first();
                return $msg;
            }
            $request->sale_price = $request->sale_price ? $request->sale_price : 0;
            $totalImageSize = 0;
            if ($request->hasFile('cover_image')) {
                $totalImageSize += $request->file('cover_image')->getSize();
            }
            if ($request->hasFile('preview_video')) {
                $totalImageSize += $request->file('preview_video')->getSize();
            }
            if ($request->hasFile('downloadable_product')) {
                $totalImageSize += $request->file('downloadable_product')->getSize();
            }
            if (!empty($request->product_image)) {
                foreach ($request->product_image as $key => $image) {
                    $totalImageSize += File::size($image);
                }
            }
            $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $totalImageSize);
            if ($result != 1) {
                $msg['flag'] = 'error';
                $msg['msg'] = $result;

                return $msg;
            }

            if ($request->cover_image) {
                $file_path =  $product->cover_image_path;

                if ($result == 1) {
                    Utility::changeStorageLimit(\Auth::user()->creatorId(), $file_path);

                    $fileName = rand(10, 100) . '_' . time() . "_" . $request->cover_image->getClientOriginalName();
                    $path = Utility::upload_file($request, 'cover_image', $fileName, $dir, []);
                    if ($path['flag'] == 0) {
                        $msg['flag'] = 'error';
                        $msg['msg'] =  $path['msg'];

                        return $msg;
                    }
                    \App\Services\StoredUpload::delete($product->cover_image_path, \Auth::user()->creatorId());
                } else {
                    $msg['flag'] = 'error';
                    $msg['msg'] = $result;

                    return $msg;
                }
                $product->cover_image_path = $path['url'];
                $product->cover_image_url = $path['full_url'];
            }



            $product->name = $request->name;
            if (isset($request->slug) && !empty($request->slug)) {
                $product->slug = $request->slug;
            } else {
                $product->slug = Product::slugs($request->name);
            }
            $product->description = $request->description;
            $product->specification = $request->specification;
            $product->detail = $request->detail;
            $product->stock_status = $request->stock_status;
            $product->product_weight = $request->product_weight;
            $tag_id = $request->tag;
            $product->category_id = $request->category_id;
            if ($request->brand_id) {
                $product->brand_id = $request->brand_id ?? null;
            }
            if ($request->label_id) {
                $product->label_id = $request->label_id ?? null;
            }

            $product->tax_status = $request->tax_status;

            if (!empty($request->tax_id)) {
                $product->tax_id = implode(',', $request->tax_id);
            } elseif(empty($request->tax_id)){
                $product->tax_id = null;
            }else {
                $tax = Tax::where('store_id', getCurrentStore())->first();
                if (isset($tax)) {
                    $product->tax_id = $tax->id;
                }
            }
            $product->preview_type = $request->preview_type;
            if (!empty($request->video_url)) {
                $product->preview_content = $request->video_url;
            }
            if (!empty($request->preview_video)) {
                $ext = $request->file('preview_video')->getClientOriginalExtension();
                $fileName = 'video_' . time() . rand() . '.' . $ext;

                $dir_video = 'uploads/' .getCurrentStore() . '/video';

                $file_paths = $product->preview_video;
                if ($result == 1) {
                    Utility::changeStorageLimit(\Auth::user()->creatorId(), $file_paths);
                    $path_video = Utility::upload_file($request, 'preview_video', $fileName, $dir_video, []);
                    if ($path_video['flag'] == 1) {
                        $url = $path_video['url'];
                    } else {
                        $msg['flag'] = 'error';
                        $msg['msg'] = $path_video['msg'];
                        return $msg;
                    }
                } else {
                    $msg['flag'] = 'error';
                    $msg['msg'] = $result;
                    return $msg;
                }



                $product->preview_content = $path_video['url'];
            }

            if (!empty($request->preview_iframe)) {
                $product->preview_content = $request->preview_iframe;
            }
            $product->variant_product = $request->variant_product;
            $product->shipping_id = $request->shipping_id;
            $product->status = $request->status;
            $product->trending = $request->trending;


            if ($request->track_stock == 1) {
                $product->track_stock = $request->track_stock;
                $product->stock_order_status = $request->stock_order_status;
                $product->low_stock_threshold = !empty($request->low_stock_threshold) ? $request->low_stock_threshold : 0;
            } else {
                $product->track_stock = $request->track_stock;
                $product->stock_order_status = '';
                $product->low_stock_threshold = !empty($request->low_stock_threshold) ? $request->low_stock_threshold : 0;
            }

            if ($request->custom_field_status == '1') {
                $product->custom_field_status = '1';
                if (isset($request->custom_field_repeater_basic)) {
                    // Create a new array to hold the filtered data
                    $filteredCustomData = [];

                    // Iterate through the 'custom_field_repeater_basic' array
                    foreach ($request->custom_field_repeater_basic as $info) {
                        // Check for the 'custom_field' and 'custom_value' keys and if they are null
                        if (!(array_key_exists('custom_field', $info) && $info['custom_field'] === null && array_key_exists('custom_value', $info) && $info['custom_value'] === null)) {
                            // Add the info to the filtered data array
                            $filteredCustomData[] = $info;
                        }
                    }

                    if (count($filteredCustomData) > 0) {
                        $product->custom_field = json_encode($filteredCustomData);
                    } else {
                        $product->custom_field = null;
                    }

                } else {
                    $product->custom_field = null;
                    $product->custom_field_status = '0';
                }
            } else {
                $product->custom_field = NULL;
            }

            if (!empty($request->downloadable_product)) {
                $file_paths = $product->downloadable_product;

                if ($result == 1) {
                    Utility::changeStorageLimit(\Auth::user()->creatorId(), $file_paths);

                    $fileName = rand(10, 100) . '_' . time() . "_" . $request->downloadable_product->getClientOriginalName();
                    $path = Utility::upload_file($request, 'downloadable_product', $fileName, $dir, []);
                    if ($path['flag'] == 0) {
                        $msg['flag'] = 'error';
                        $msg['msg'] =  $path['msg'];

                        return $msg;
                    }
                    \App\Services\StoredUpload::delete($product->downloadable_product, \Auth::user()->creatorId());
                } else {

                    $msg['flag'] = 'error';
                    $msg['msg'] = $result;
                    return $msg;
                }
                $product->downloadable_product = $path['url'];
            }


            if (!empty($request->product_image)) {

                foreach ($request->product_image as $key => $image) {
                    $theme_image = $image;

                    if ($result == 1) {
                        $fileName = rand(10, 100) . '_' . time() . "_" . $image->getClientOriginalName();
                        $pathss = Utility::keyWiseUpload_file($request, 'product_image', $fileName, $dir, $key, []);
                    } else {
                        $msg['flag'] = 'error';
                        $msg['msg'] =  $result;

                        return $msg;
                    }

                    if (isset($pathss['url'])) {
                        $ProductImage = new ProductImage();
                        $ProductImage->product_id = $product->id;
                        $ProductImage->image_path = $pathss['url'];
                        $ProductImage->image_url  = $pathss['full_url'];
                        $ProductImage->store_id   = getCurrentStore();
                        $ProductImage->save();
                    }
                }
            }

            if ($request->variant_product == 0) {


                $product->price = $request->price;
                $product->sale_price = $request->sale_price;


                if ($request->track_stock == 0) {
                    $product->product_stock = 0;
                } else {
                    $product->product_stock = $request->product_stock;
                }


                $input = $request->all();

                $input['attribute_options'] = [];
                if ($request->has('attribute_no')) {
                    foreach ($request->attribute_no as $key => $no) {
                        $str = 'attribute_options_' . $no;
                        $enable_option = $input['visible_attribute_' . $no];
                        $variation_option = $input['for_variation_' . $no];

                        $item['attribute_id'] = $no;

                        $optionValues = [];
                        if (isset($request[$str])) {
                            foreach ($request[$str] as $fValue) {
                                $option = ProductAttributeOption::where('terms', $fValue)->first();
                                if ($option) {
                                    $optionValues[] = $option->id;
                                }
                            }
                        }

                        $item['values'] = explode(',', implode('|', $optionValues));
                        $item['visible_attribute_' . $no] = $enable_option;
                        $item['for_variation_' . $no] = $variation_option;
                        array_push($input['attribute_options'], $item);
                    }
                }

                if (!empty($request->attribute_no)) {
                    $input['product_attributes'] = implode(',', $request->attribute_no);
                } else {
                    $input['product_attributes'] = 0;
                }
                $input['attribute_options'] = json_encode($input['attribute_options']);
                $product->attribute_id = $input['product_attributes'];
                $product->product_attribute = $input['attribute_options'];
                $tag_data_id = [];
                $tag_ids = [];

                if (isset($request->tag_id)) {

                    foreach ($request->tag_id as $tag) {

                        $tags = Tag::where('id', $tag)->where('store_id', getCurrentStore())->first();

                        if (!empty($tags)) {
                            $tag_id = $tags->id;
                            $tag_ids[] = $tag_id;
                        } else {
                            $tag_id = 0;
                        }
                        if ($tag_id != $tag) {
                            $tag_data = new Tag();
                            $tag_data->name = $tag;
                            $tag_data->slug = Tag::slugs($tag);
                            $tag_data->store_id = getCurrentStore();
                            $tag_data->created_by = \Auth::user()->id;
                            $tag_data->save();

                            $tag_data_id[] = $tag_data->id;
                        }
                    }
                    $tag_product_id = array_merge($tag_data_id, $tag_ids);

                    if (!empty($tag_product_id)) {
                        $product->tag_id =  implode(',', $tag_product_id);
                    }
                }

                if (module_is_active('CartQuantityControl')) {
                    \Workdo\CartQuantityControl\app\Models\CartQuantityControl::saveData($product, $request->minimum_quantity, $request->maximum_quantity);
                }
                $product->save();
            } else {

                $input = $request->all();
                $input['choice_options'] = [];
                $input['attribute_options'] = [];
                if ($request->has('choice_no')) {
                    foreach ($request->choice_no as $key => $no) {
                        $str = 'choice_options_' . $no;

                        $item['attribute_id'] = $no;
                        $item['values'] = explode(',', implode('|', $request[$str]));
                        array_push($input['choice_options'], $item);
                    }
                }

                if (!empty($request->choice_no)) {
                    $input['attributes'] = json_encode($request->choice_no);
                } else {
                    $input['attributes'] = json_encode([]);
                }

                $input['choice_options'] = json_encode($input['choice_options']);
                $input['slug'] = $input['name'];

                if ($request->has('attribute_no')) {
                    foreach ($request->attribute_no as $key => $no) {
                        $str = 'attribute_options_' . $no;
                        $enable_option = $input['visible_attribute_' . $no];
                        $variation_option = $input['for_variation_' . $no];

                        $item['attribute_id'] = $no;
                        $optionValues = [];
                        if (isset($request[$str])) {
                            foreach ($request[$str] as $fValue) {
                                $option = ProductAttributeOption::where('terms', $fValue)->first();
                                if ($option) {
                                    $optionValues[] = $option->id;
                                }
                            }
                        }
                        $item['values'] = explode(',', implode('|', $optionValues));
                        $item['visible_attribute_' . $no] = $enable_option;
                        $item['for_variation_' . $no] = $variation_option;
                        array_push($input['attribute_options'], $item);
                    }
                }

                if (!empty($request->attribute_no)) {
                    $input['product_attributes'] = implode(',', $request->attribute_no);
                } else {
                    $input['product_attributes'] = json_encode([]);
                }
                $input['attribute_options'] = json_encode($input['attribute_options']);

                $product->price = 0;
                $product->product_stock = 0;
                $product->attribute_id = $input['product_attributes'] ?? null;
                $product->product_attribute = $input['attribute_options'];

                $product->preview_type = $request->preview_type;
                if (!empty($request->video_url)) {
                    $product->preview_content = $request->video_url;
                }
                if (!empty($request->preview_video)) {
                    $ext = $request->file('preview_video')->getClientOriginalExtension();
                    $fileName = 'video_' . time() . rand() . '.' . $ext;

                    $dir_video = 'uploads/' .getCurrentStore() . '/video';
                    $file_paths = $product->preview_video;
                    if ($result == 1) {
                        $path_video = Utility::upload_file($request, 'preview_video', $fileName, $dir_video, []);
                        if ($path_video['flag'] == 1) {
                            $url = $path_video['url'];
                        } else {
                            return redirect()->back()->with('error', __($path_video['msg']));
                        }
                    } else {
                        return redirect()->back()->with('error', $result);
                    }
                    $product->preview_content = $path_video['url'];
                }
                if (!empty($request->preview_iframe)) {
                    $product->preview_content = $request->preview_iframe;
                }
                $product->shipping_id = $request->shipping_id;
                if ($request->custom_field_status == '1') {
                    $product->custom_field = json_encode($request->custom_field_repeater_basic);
                } else {
                    $product->custom_field = NULL;
                }
                $product->save();

                $options = [];
                if ($request->has('choice_no')) {
                    foreach ($request->choice_no as $key => $no) {
                        $name = 'choice_options_' . $no;
                        $my_str = implode('|', $request[$name]);
                        array_push($options, explode(',', $my_str));
                    }
                }

                $sku_array = [];
                $total_stock = 0;
                $combinations = $this->combinations($options);
                if (count($combinations[0]) > 0) {
                    $product->variant_product = 1;
                    foreach ($combinations as $key => $combination) {
                        $str = '';
                        foreach ($combination as $key => $item) {
                            if ($key > 0) {
                                $str .= '-' . str_replace(' ', '', $item);
                            } else {
                                $str .= str_replace(' ', '', $item);
                            }
                        }

                        $product_stock = ProductVariant::where('product_id', $product->id)->where('variant', $str)->first();
                        if ($product_stock == null) {
                            $product_stock = new ProductVariant;
                            $product_stock->product_id = $product->id;
                        }
                        array_push($sku_array, $str);

                        $sku = str_replace(' ', '_', $request->name) . $request['sku_' . str_replace('.', '_', $str)];
                        $total_stock += $request['stock_' . str_replace('.', '_', $str)];
                        $product_stock->variant = $str;
                        $product_stock->price = $request['price_' . str_replace('.', '_', $str)];
                        $product_stock->sku = $sku;
                        $product_stock->stock = $request['stock_' . str_replace('.', '_', $str)];
                        
                        $product_stock->save();


                        if ($request->default_variant == '-' . $str) {
                            $product_update = Product::find($product->id);
                            $product_update->default_variant_id = $product_stock->id;
                            $product_update->save();
                        }
                    }
                    ProductVariant::where('product_id', $product->id)->whereNotIn('variant', $sku_array)->delete();
                    $product->product_stock = $total_stock;
                    $tag_data_id = [];
                    $tag_ids = [];
                    if (isset($request->tag_id)) {

                        foreach ($request->tag_id as $tag) {
                            $tags = Tag::where('id', $tag)->where('store_id', getCurrentStore())->first();
                            if (!empty($tags)) {
                                $tag_id = $tags->id;
                                $tag_ids[] = $tag_id;
                            } else {
                                $tag_id = 0;
                            }
                            if ($tag_id != $tag) {
                                $tag_data = new Tag();
                                $tag_data->name = $tag;
                                $tag_data->store_id = getCurrentStore();
                                $tag_data->created_by = \Auth::user()->id;
                                $tag_data->save();

                                $tag_data_id[] = $tag_data->id;
                            }
                        }
                    }
                    $tag_product_id = array_merge($tag_data_id, $tag_ids);
                    if (!empty($tag_product_id)) {

                        $product->tag_id =  implode(',', $tag_product_id);
                    }
                    $product->save();
                } else {
                    $product->variant_product = 0;
                }

                $attribute_option = [];
                if ($request->attribute_no) {
                    foreach ($request->attribute_no as $key => $no) {
                        $forVariationName = 'for_variation_' . $no;
                        if ($request->has($forVariationName) && $request->input($forVariationName) == 1) {
                            $name = 'attribute_options_' . $no;
                            $options_data = 'options_datas';
                            $for_variation = isset($request->{'for_variation_' . $no}) ? $request->{'for_variation_' . $no} : 0;
                            if ($for_variation == 1) {
                                if ($request->has($options_data) && is_array($request[$options_data])) {
                                    $my_str = $request[$options_data];
                                    $optionValues = [];

                                    foreach ($request[$options_data] as $term) {

                                        $optionValues[] = $term;
                                    }

                                    array_push($attribute_option, $my_str);
                                }
                            }
                        }
                    }
                }
                if ($attribute_option) {
                    if (count($attribute_option[0]) > 0) {
                        $product->variant_product = 1;
                        $is_in_stock = false;
                        foreach ($attribute_option as $key => $com) {
                            $str = '';
                            foreach ($com as $key => $item) {
                                $str = $item;

                                $product_stock = ProductVariant::where('product_id', $product->id)->where('variant', $str)->first();
                                if ($product_stock == null) {
                                    $product_stock = new ProductVariant;
                                    $product_stock->product_id = $product->id;
                                }

                                $theme_name = APP_THEME();
                                if ($request['downloadable_product_' . str_replace('.', '_', $str)]) {
                                    $fileName = rand(10, 100) . '_' . time() . "_" . $request->file('downloadable_product_' . $str)->getClientOriginalName();

                                    $path1 = Utility::upload_file($request, 'downloadable_product_' . $str, $fileName, $dir, []);
                                    if ($path1['flag'] == 0) {
                                        $msg['flag'] = 'error';
                                        $msg['msg'] =  $path1['msg'];
    
                                        return $msg;
                                    }
                                    $product_stock->downloadable_product = $path1['url'];
                                }


                                $var_option = "";
                                $variation_option = !empty($request['variation_option_' . str_replace('.', '_', $str)]) ? $request['variation_option_' . str_replace('.', '_', $str)] : '';

                                if (is_array($variation_option)) {
                                    foreach ($variation_option as $option) {
                                        $var_option .= $option . ",";
                                    }
                                }

                                $sku = str_replace(' ', '_', $request->name) . $request['product_sku_' . str_replace('.', '_', $str)];
                                $product_stock->variant = $str;

                                if ($product_stock->track_stock == 1) {
                                    $product_stock->stock_status = '';
                                } else {

                                    $product_stock->stock_status = !empty($request['stock_status_' . str_replace('.', '_', $str)]) ? $request['stock_status_' . str_replace('.', '_', $str)] : '';
                                }

                                $product_stock->variation_option = $var_option;

                                $product_stock->price = !empty($request['product_sale_price_' . str_replace('.', '_', $str)]) ? $request['product_sale_price_' . str_replace('.', '_', $str)] : 0;

                                $product_stock->variation_price = !empty($request['product_variation_price_' . str_replace('.', '_', $str)]) ? $request['product_variation_price_' . str_replace('.', '_', $str)] : 0;
                                $product_stock->sku = $sku;

                                $product_stock->stock = !empty($request['product_stock_' . str_replace('.', '_', $str)]) ? $request['product_stock_' . str_replace('.', '_', $str)] : 0;

                                $product_stock->low_stock_threshold = !empty($request['low_stock_threshold_' . str_replace('.', '_', $str)]) ? $request['low_stock_threshold_' . str_replace('.', '_', $str)] : 0;

                                $product_stock->weight = !empty($request['product_weight_' . str_replace('.', '_', $str)]) ? $request['product_weight_' . str_replace('.', '_', $str)] : 0;

                                $product_stock->stock_order_status = !empty($request['stock_order_status_' . str_replace('.', '_', $str)]) ? $request['stock_order_status_' . str_replace('.', '_', $str)] : 0;

                                $product_stock->description = !empty($request['product_description_' . str_replace('.', '_', $str)]) ? $request['product_description_' . str_replace('.', '_', $str)] : '';

                                $product_stock->shipping = !empty($request['shipping_id_' . str_replace('.', '_', $str)]) ? $request['shipping_id_' . str_replace('.', '_', $str)] : 'same_as_parent';

                                $product_stock->store_id = getCurrentStore();

                                if (module_is_active('CartQuantityControl')) {
                                    \Workdo\CartQuantityControl\app\Models\CartQuantityControl::variantSaveData($request, $product_stock, $str);
                                }

                                $product_stock->save();

                                if ($request->default_variant ==  '-' . $str) {
                                    $product_update = Product::find($product->id);
                                    $product_update->default_variant_id = $product_stock->id;
                                    $product_update->save();
                                }

                                if ($product_stock->stock == 'in_stock') {
                                    $is_in_stock = true;
                                }
                            }
                            ProductVariant::where('product_id', $product->id)
                                ->whereNotIn('variant', $com)
                                ->delete();
                        }
                        if (!$is_in_stock) {
                            $product->stock = 'out_of_stock';
                        }
                    } else {
                        $product->variant_product = 0;
                    }
                }
            }


            $firebase_enabled = Utility::GetValueByName('firebase_enabled');
            if (!empty($firebase_enabled) && $firebase_enabled == 'on') {
                $fcm_Key = Utility::GetValueByName('fcm_Key');
                if (!empty($fcm_Key)) {
                    $NotifyUsers = NotifyUser::where('product_id', $product->id)->get();
                    if (!empty($NotifyUsers)) {
                        foreach ($NotifyUsers as $key => $value) {
                            $User_data = User::find($value->user_id);
                            if (!empty($User_data->firebase_token)) {
                                $device_id = $User_data->firebase_token;
                                $message = 'now ' . $product->name . ' is available in stock';
                                Utility::sendFCM($device_id, $fcm_Key, $message);
                                NotifyUser::where('product_id', $product->id)->where('user_id', $User_data->id)->delete();
                            }
                        }
                    }
                }
            }
            if (module_is_active('SizeGuideline')) {
                \Workdo\SizeGuideline\app\Models\SizeGuideline::saveUpdateData($product, $request->size_chart_title,$request->size_chart_information,$request->all());
            }
            if (module_is_active('WholesaleProduct')) {
                \Workdo\WholesaleProduct\app\Models\WholesaleProduct::saveUpdateData($product, $request->wholesale_repeater_basic);
            }
            if (module_is_active('ProductBarCode')) {
                \Workdo\ProductBarCode\app\Models\ProductBarCode::saveData($product);
            }
            if (module_is_active('PartialPayments')) {
                \Workdo\PartialPayments\app\Models\PartialPayments::saveData($product,$request->all());
            }
            if (module_is_active('AdditionalCustomFields')) {
                $settingController = new \Workdo\AdditionalCustomFields\app\Http\Controllers\SettingController();
                $settingController->saveData($product, $request->all());
            }
            
            $msg['flag'] = 'success';
            $msg['msg'] =__('Product update successfully.');
            return $msg;
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    /**
     * R4-04 cross-tenant IDOR exemplar.
     *
     * The permission check here was already correct -- 'Delete Products' IS
     * created by Utility::addNewData() (verified, double-quoted at Utility.php
     * :2680, which is why a single-quoted grep misses it) -- but it was the ONLY
     * check. The parameter is route-model bound, so Laravel resolved any product
     * id in the table, and holding 'Delete Products' in your own store was
     * enough to destroy a product in someone else's.
     *
     * That made it worse than a database-only IDOR: before any row is deleted
     * this method unlinks files from disk (File::delete on each ProductImage
     * image_path, the cover image, the downloadable product and a video
     * preview), so a cross-tenant call destroyed another merchant's uploaded
     * media irreversibly, and called Utility::changeproductStorageLimit against
     * the CALLER's creatorId(), mis-accounting the victim's storage against the
     * attacker.
     *
     * The ownership check is therefore placed before any deletion or unlink.
     */
    public function destroy(Product $product)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Delete Products')) {
            $actor = auth()->user();

            // Ownership, before anything is removed from the database or disk.
            // A super admin is exempt, matching OrderPolicy::allow.
            if ($actor->type !== \App\Policies\UserPolicy::PLATFORM_ADMIN) {
                $currentStore = getCurrentStore();

                if (
                    empty($currentStore)
                    || empty($product->store_id)
                    || (int) $product->store_id !== (int) $currentStore
                ) {
                    return redirect()->back()->with('error', __('Permission denied.'));
                }
            }

            $ProductImages = ProductImage::where('product_id', $product->id)->get();

            $Product = Product::find($product->id);
            $file_path1 = [];
            foreach ($ProductImages as $key => $ProductImage) {
                $file_path1[] =  $ProductImage->image_path;
            }
            $file_paths2[] = $Product->cover_image_path;
            if (!empty($Product->downloadable_product)) {
                $file_paths2[] = $Product->downloadable_product;
            }
            if ($Product->preview_type == "Video File" && !empty($Product->preview_content) && \File::exists(base_path($Product->preview_content))) {
                $file_paths2[] = $Product->preview_content;
            }
            $file_path = array_merge($file_path1, $file_paths2);
            Utility::changeproductStorageLimit(\Auth::user()->creatorId(), $file_path, $file_path1);
            if (!empty($ProductImages)) {
                // image remove from product variant image
                foreach ($ProductImages as $key => $ProductImage) {
                    \App\Services\StoredUpload::delete($ProductImage->image_path, \Auth::user()->creatorId());
                }
            }

            ProductImage::where('product_id', $product->id)->delete();



            if (module_is_active('HubSpot')) {
                $setting = getAdminAllSetting(auth()->user()->id, getCurrentStore());
                if (isset($setting['hubspot_is_enable']) && $setting['hubspot_is_enable'] == 'on') {
                    \Workdo\HubSpot\app\Models\HubSpotProductService::where('product_service_id', $Product->id)->delete();
                }
            }
            ProductVariant::where('product_id', $product->id)->delete();

            Cart::where('product_id', $product->id)->delete();

            $Product = Product::find($product->id);
            if (!empty($Product)) {
                // image remove from description json
                $description_json = $Product->other_description_api;
                if (!empty($description_json)) {
                    $description_json = json_decode($Product->other_description_api, true);
                    foreach ($description_json['product-other-description'] as $key => $value) {
                        if ($value['field_type'] == 'photo upload') {
                            \App\Services\StoredUpload::delete($value['value'], \Auth::user()->creatorId());
                        }
                    }
                }

                // image remove from cover image
                \App\Services\StoredUpload::delete($Product->cover_image_path, \Auth::user()->creatorId());

                WoocommerceConection::where('module', 'product')->where('original_id', $product->id)->delete();

                ShopifyConection::where('module', 'product')->where('original_id', $product->id)->delete();

                Product::where('id', $product->id)->delete();
            }
            return redirect()->back()->with('success', __('Product delete successfully.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function get_slug(Request $request)
    {
        $result = Product::slugs($request->value);
        return response()->json(['result' => $result]);
    }

    public function attribute_option(Request $request)
    {
        $Attribute_option = ProductAttributeOption::where('attribute_id', $request->attribute_id)->where('store_id', getCurrentStore())
            ->get()->pluck('terms', 'id')->toArray();

        return response()->json($Attribute_option);
    }

    public function attribute_combination(Request $request)
    {

        $options = array();
        $unit_price = !empty($request->price) ? $request->price : 0;
        $product_name = !empty($request->sku) ? $request->sku : '';
        $stock = !empty($request->product_stock) ? $request->product_stock : 0;
        $input = $request->all();

        foreach ($request->attribute_no as $key => $no) {
            $forVariationName = 'for_variation_' . $no;
            $for_variation = isset($request->{'for_variation_' . $no}) ? $request->{'for_variation_' . $no} : 0;

            if ($request->has($forVariationName) && $request->input($forVariationName) == 1) {
                $name = 'attribute_options_' . $no;
                $value = 'options_' . $no;
                if ($for_variation == 1) {
                    if ($request->has($name) && is_array($request[$name])) {
                        $my_str = $request[$name];
                        $optionValues = [];

                        foreach ($request[$name] as $id) {
                            $option = ProductAttributeOption::where('id', $id)->first();

                            if ($option) {
                                $optionValues[] = $option->terms;
                            }
                        }

                        array_push($options, $optionValues);
                    }
                }
            }
        }

        $combinations = $this->combination($options);

        $Shipping = Shipping::where('store_id', getCurrentStore())->pluck('name', 'id')->prepend('Same as Parent', '');

        return view('product.attribute_combinations', compact('combinations', 'input', 'unit_price', 'product_name', 'stock', 'Shipping'));
    }

    public function sku_combination(Request $request)
    {
        $options = array();
        $unit_price = !empty($request->price) ? $request->price : 0;
        $product_name = !empty($request->sku) ? $request->sku : '';
        $stock = !empty($request->product_stock) ? $request->product_stock : 0;

        if ($request->has('choice_no')) {
            foreach ($request->choice_no as $key => $no) {
                $name = 'choice_options_' . $no;
                $my_str = implode('', $request[$name]);
                array_push($options, explode(',', $my_str));
            }
        }
        $combinations = $this->combinations($options);
        return view('product.sku_combinations', compact('combinations', 'unit_price', 'product_name', 'stock'));
    }

    public function attribute_combination_data(Request $request)
    {
        $product_stock = ProductVariant::where('product_id', $request->id)->where('store_id', getCurrentStore())
            ->get();
        $Shipping = Shipping::where('store_id', getCurrentStore())->pluck('name', 'id')->prepend('Same as parent', '');
        return view('product.attribute_combinations_data', compact('product_stock', 'Shipping'));
    }

    public function combinations($arrays)
    {

        $result = array(array());
        foreach ($arrays as $property => $property_values) {
            $tmp = array();
            foreach ($result as $result_item) {
                foreach ($property_values as $property_value) {
                    $tmp[] = array_merge($result_item, array($property => $property_value));
                }
            }
            $result = $tmp;
        }
        return $result;
    }

    public function combination($arrays)
    {
        $result = array(array());
        foreach ($arrays as $property => $property_values) {
            $tmp = array();
            foreach ($result as $result_item) {
                foreach ($property_values as $property_value) {
                    $tmp[] = array_merge($result_item, array($property => $property_value));
                }
            }
            $result = $tmp;
        }
        return $result;
    }

    /**
     * Is this product outside the store the signed-in user is working in?
     *
     * P1-INV-07. The ownership decision `update()`, `edit()`, `show()` and `destroy()` each make
     * inline, extracted once for the three sites this task guards rather than copied a fifth,
     * sixth and seventh time. The four existing copies are left alone deliberately: changing them
     * would be a refactor of working, removal-checked code, and this task is a security fix.
     *
     * A MISSING PRODUCT IS OUTSIDE. `ProductImage` and `ProductVariant` both carry a nullable
     * `store_id` of their own, and neither is in config/tenancy.php's `scoped_models`, so the
     * authoritative tie is through the PRODUCT -- the same reasoning as S35/S36: scoping the
     * product does not scope the variant, and the variant's own column cannot be trusted to carry
     * the answer. If the product cannot be resolved there is nothing to own, and refusing is the
     * fail-closed direction.
     *
     * THE PLATFORM OPERATOR IS EXEMPT, matching `destroy()` and `OrderPolicy::allow`. Note that in
     * methods wrapped in `isAbleTo(...)` that exemption cannot fire at all -- Laratrust has no
     * super-admin bypass (CLAUDE.md), measured in
     * tests/Support/Security/bin/verify-product-ownership-on-mysql.php. These three methods have
     * no permission wrapper, so here it is live.
     */
    private function productIsOutsideCurrentStore($product): bool
    {
        $actor = auth()->user();

        if ($actor !== null && $actor->type === \App\Policies\UserPolicy::PLATFORM_ADMIN) {
            return false;
        }

        $currentStore = getCurrentStore();

        return empty($product)
            || empty($currentStore)
            || empty($product->store_id)
            || (int) $product->store_id !== (int) $currentStore;
    }

    /**
     * Is this path inside the application's own uploads directory?
     *
     * P1-INV-07. `file_delete()` passes a DATABASE STRING to `File::delete(base_path(...))`. The
     * ownership check above is what stops another store's row being chosen, but a stored path is
     * still untrusted input to the filesystem: a value containing `..` would resolve outside the
     * uploads tree, and `File::delete()` would follow it. Ownership and path safety are two
     * different questions and the owner's instruction asks for both.
     *
     * `realpath()` resolves `..` and symlinks before the comparison, and returns false for a path
     * that does not exist -- so a missing file is "not inside" and simply is not unlinked. The
     * trailing separator on the root matters: without it, `/app/uploads-evil` would pass a plain
     * prefix test against `/app/uploads`.
     */
    private function isInsideUploadsRoot(string $absolutePath): bool
    {
        $root = realpath(base_path('uploads'));
        $real = realpath($absolutePath);

        if ($root === false || $real === false) {
            return false;
        }

        return $real === $root || str_starts_with($real, $root . DIRECTORY_SEPARATOR);
    }

    public function file_delete($id)
    {

        $product_img_id = ProductImage::find($id);
        if ($product_img_id) {
            /*
             * OWNERSHIP BEFORE ANYTHING LEAVES THE DISK. P1-INV-07.
             *
             * This method had NO permission check and NO ownership check, and it unlinks a file:
             * `ProductImage::find($id)` took any id, and `File::delete(base_path(...))` removed
             * another store's uploaded media IRREVERSIBLY. Reported live by an adversarial review
             * of P1-INV-01, which asked whether that fix had guarded one door and left another
             * open. It had.
             *
             * The tie is through the product, not through `product_images.store_id`: that column
             * is nullable and the model is not in `scoped_models`, so it cannot be trusted to
             * carry the answer.
             */
            $owner = Product::find($product_img_id->product_id);

            if ($this->productIsOutsideCurrentStore($owner)) {
                \Illuminate\Support\Facades\Log::warning(
                    'product image delete refused: the image belongs to another store',
                    [
                        'image_id' => $product_img_id->id,
                        'product_id' => $product_img_id->product_id,
                        'product_store_id' => $owner->store_id ?? null,
                        'current_store' => getCurrentStore(),
                        'user_id' => optional(auth()->user())->id,
                    ]
                );

                /*
                 * 403, not a 200 with an error body. The caller
                 * (resources/views/product/edit.blade.php:851-856) hides the thumbnail inside its
                 * `success` handler REGARDLESS of the payload, so a 200 would tell the user the
                 * image was deleted when it was not. No 401 is introduced -- that is the code the
                 * shipped Flutter app force-logs-out on, and this is a web route besides.
                 */
                return response()->json([
                    'flag' => 'error',
                    'msg' => __('Permission denied.'),
                ], 403);
            }

            /*
             * AND THE PATH MUST BE INSIDE THE UPLOADS TREE. `image_path` is a database string
             * handed to the filesystem; ownership says WHOSE row this is, not WHERE the string
             * points. A path containing `..` would resolve outside the uploads directory and
             * `File::delete()` would follow it. See isInsideUploadsRoot(): a path that does not
             * resolve, or resolves outside, is not unlinked -- the row is still removed, which is
             * the user's actual intent, and nothing outside `uploads/` is ever touched.
             */
            $absolute = base_path((string) $product_img_id->image_path);

            if ($this->isInsideUploadsRoot($absolute)) {
                File::delete(realpath($absolute));
            } elseif (! empty($product_img_id->image_path)) {
                \Illuminate\Support\Facades\Log::warning(
                    'product image row deleted but the file was not unlinked: the stored path is'
                    . ' outside the uploads directory',
                    [
                        'image_id' => $product_img_id->id,
                        'image_path' => (string) $product_img_id->image_path,
                    ]
                );
            }

            $product_img_id->delete();
        }

        $msg['flag'] = 'success';
        $msg['msg'] = __('Image delete Successfully');
        return $msg;
    }

    public function attribute_combination_edit(Request $request)
    {
        $product = Product::find($request->id);

        /*
         * OWNERSHIP ON A PRODUCT NAMED BY REQUEST INPUT. P1-INV-07.
         *
         * `$request->id` -- not a route-model binding -- so P1-INV-01's guard on `update()`,
         * `edit()` and `show()` does not apply here, and nothing else did: the foreign product was
         * passed straight into the view. The same read leak those three closed, through a
         * different parameter.
         */
        if ($this->productIsOutsideCurrentStore($product)) {
            \Illuminate\Support\Facades\Log::warning(
                'attribute combination edit refused: the product belongs to another store',
                [
                    'product_id' => $request->id,
                    'product_store_id' => $product->store_id ?? null,
                    'current_store' => getCurrentStore(),
                    'user_id' => optional(auth()->user())->id,
                ]
            );

            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $options = array();
        $product_name = !empty($request->sku) ? $request->sku : '';
        $unit_price = !empty($request->price) ? $request->price : 0;

        foreach ($request->attribute_no as $key => $no) {
            $forVariationName = 'for_variation_' . $no;
            $for_variation = isset($request->{'for_variation_' . $no}) ? $request->{'for_variation_' . $no} : 0;
            if ($for_variation == 1) {
                if ($request->has($forVariationName) && $request->input($forVariationName) == 1) {
                    $name = 'attribute_options_' . $no;

                    if ($request->has($name) && is_array($request[$name])) {
                        $my_str = $request[$name];
                        $optionValues = [];
                        array_push($options, $my_str);
                    }
                }
            }
        }

        $combinations = $this->combination($options);
        $Shipping = Shipping::where('store_id', getCurrentStore())->pluck('name', 'id')->prepend('Same as parent', '');
        return view('product.attribute_combinations_edit', compact('combinations', 'unit_price', 'product_name', 'product', 'Shipping'));
    }

    public function product_attribute_delete($id)
    {
        $attribute = ProductVariant::findOrFail($id);

        /*
         * OWNERSHIP BEFORE A DESTRUCTIVE WRITE. P1-INV-07.
         *
         * This method had no permission check and no ownership check: `findOrFail($id)` took any
         * variant id and deleted it -- another store's variant row AND the stock it carries.
         * `ProductVariant` carries no StoreScope and is not in `scoped_models`, so nothing
         * narrowed it. `findOrFail` is kept so a genuinely missing id still answers 404 rather
         * than being reported as a permission problem.
         *
         * Resolved through the variant's PRODUCT, for the reason S36 recorded: the variant's own
         * nullable `store_id` is not the authority on who owns it.
         */
        $owner = Product::find($attribute->product_id);

        if ($this->productIsOutsideCurrentStore($owner)) {
            \Illuminate\Support\Facades\Log::warning(
                'product variant delete refused: the variant belongs to another store',
                [
                    'variant_id' => $attribute->id,
                    'product_id' => $attribute->product_id,
                    'product_store_id' => $owner->store_id ?? null,
                    'current_store' => getCurrentStore(),
                    'user_id' => optional(auth()->user())->id,
                ]
            );

            /*
             * 403 rather than a body. BOTH callers
             * (resources/views/product/attribute_combinations_edit.blade.php:319-324 and
             * resources/views/product/attribute_combinations_data.blade.php:389-394) announce
             * "Variant Deleted Successfully!" and remove the row from the DOM on ANY 2xx, without
             * reading the response, so returning "false" with a 200 would lie to the user.
             *
             * Line numbers and the second caller corrected by an adversarial review of this fix:
             * the first version cited one caller at the wrong lines.
             */
            return response()->json(['message' => __('Permission denied.')], 403);
        }

        $attribute->delete();

        return "true";
    }

    public function collectionAll($storeSlug, Request $request, $list)
    {
    }

    public function product_price(Request $request, $slug)
    {
        $store = getStore($slug);
       
        $settings = Setting::where('store_id', $store->id)->pluck('value', 'name')->toArray();

        $varint = $request->varint;
        $qty = $request->qty ?? $request->quantity;
        $product_id = $request->product_id;
        $return['qty'] = $qty;
        $return['variant_id'] = 0;
        $product = Product::find($product_id);
        $return['variant_product'] = $product->variant_product ?? 0;
        if (!empty($product)) {
            if (module_is_active('WholesaleProduct')) {
                if (!module_is_active('SizeGuideline')) {
                    if (module_is_active('WholesaleProduct')) {
                        $return = \Workdo\WholesaleProduct\app\Models\WholesaleProduct::Wholesale_Product_price($product, $request->all(),$store);
                    }else{
                        if ($product->variant_product == 0) {
                            // no varint
                            if (isset($settings['out_of_stock_threshold']) && ($product->product_stock < $settings['out_of_stock_threshold']) && $product->stock_order_status == 'not_allow') {
                                $return['status'] = 'error';
                                $return['stock_status'] = 'out_of_stock';
                                $return['stock_message'] = __('Out Of Stock');
                                $return['message'] = __('Product has been reached max quantity.');
                            } else {
                                $product_original_price = $product->original_price * $qty;
                                $price = FlashSale::saleDiscount($store, $product, $product_id, 0, ($product->sale_price ?? $product->final_price));
                                $product_final_price = ($price) * $qty;
                                // $product_final_price = ($product->sale_price ?? $product->final_price) * $qty;

                                $data['store_id'] = $store->id;
                                $data['product_tax_id'] = $product->tax_id;
                                $data['sub_total'] = $product_final_price;
                                $data['product_original_price'] = $product_original_price;
                                $cart_array  = Tax::TaxCount($data);

                                $return['sub_total'] = $product_final_price;
                                $return['product_original_price'] = $product_original_price;
                                $return['variant_id'] = 0;
                                $return['original_price'] = $cart_array['original_price'];
                                $return['final_price'] = $cart_array['final_price'];
                                $return['final_price_text'] = currency_format_with_sym($cart_array['final_price'],  $store->id);
                                $return['currency_name'] = $cart_array['currency_name'];
                                $return['currency'] = $cart_array['currency'];
                                $return['stock_message'] = __('In Stock');
                                $return['total_tax_price'] = $cart_array['total_tax_price'];
                                return response()->json($return);
                            }
                        } elseif ($product->variant_product == 1) {
                            // has varint
                            if (is_array($varint)) {
                                $variant_name = implode('-', $varint);
                            } else {
                                $variant_name = $varint;
                            }

                            $product->setAttribute('variantName', $variant_name);
                            $ProductStock = ProductVariant::where('product_id', $product_id)
                                ->whereRaw("REPLACE(variant, ' ', '') LIKE ?", [str_replace(' ', '', trim($variant_name))])
                                ->first();
                                if ($ProductStock)
                                {
                                    $stock = !empty($ProductStock->stock) ? $ProductStock->stock : $product->product_stock;
                                    $variationOptions = explode(',', $ProductStock->variation_option);
                                    $variant_enable = in_array('enabled', $variationOptions);
                                    $option = in_array('manage_stock', $variationOptions);

                                    if ($option == true) {
                                        $stock_status = $ProductStock->stock_order_status;
                                    } else {
                                        $stock_status = $product->stock_order_status;
                                    }

                                    if (($variant_enable == false) || ($stock < $qty && $stock_status == 'not_allow')) {
                                        $return['status'] = 'error';
                                        $return['variant_id'] = $ProductStock->id;
                                        if($variant_enable == false){
                                            $return['stock_status'] = 'not_available';
                                            $return['stock_message'] = __('Not Available');
                                            $return['message'] = __('Product variant is not available.');
                                        }else{
                                            $return['stock_status'] = 'out_of_stock';
                                            $return['stock_message'] = __('Out Of Stock');
                                            $return['message'] = __('Product has been reached max quantity.');
                                        }
                                    } else {
                                        $sale_price = !empty($ProductStock->price) ? $ProductStock->price : $ProductStock->variation_price;

                                        $variation_price = !empty($ProductStock->variation_price) ? $ProductStock->variation_price : $ProductStock->price;

                                        $var_price = !empty($sale_price) ? $sale_price : 0;

                                        $product_original_price = $variation_price * $qty;
                                        $price = FlashSale::saleDiscount($store, $product, $product_id, $ProductStock->id, $sale_price);
                                        $product_final_price = $price * $qty;
                                        // $product_final_price = $sale_price * $qty;

                                        if ($option == true) {
                                            $variat_stock = !empty($ProductStock->stock) ? $ProductStock->stock : 0;
                                        }else{
                                            $variat_stock = !empty($ProductStock->stock) ? $ProductStock->stock : $product->product_stock;
                                        }
                                        $data['store_id'] = $store->id;
                                        $data['product_tax_id'] = $product->tax_id;
                                        $data['sub_total'] = $product_final_price;
                                        $data['product_original_price'] = $product_original_price;
                                        $cart_array  = Tax::TaxCount($data);

                                        $return['sub_total'] = $product_final_price;
                                        $return['product_original_price'] = $product_original_price;
                                        $return['variant_id'] = $ProductStock->id;
                                        $return['original_price'] = $cart_array['original_price'];
                                        $return['final_price'] = $cart_array['final_price'];
                                        $return['final_price_text'] = currency_format_with_sym($cart_array['final_price'],  $store->id);
                                        $return['currency_name'] = $cart_array['currency_name'];
                                        $return['currency'] = $cart_array['currency'];
                                        $return['total_tax_price'] = $cart_array['total_tax_price'];
                                        $return['enable_option_data'] = !empty($option) ? $option : '';
                                        $return['stock'] = !empty($variat_stock) ? $variat_stock : 0;
                                        $return['stock_status'] = !empty($ProductStock->stock_status) ? $ProductStock->stock_status : '';
                                        $return['stock_message'] = __('In Stock');
                                        $return['description'] = !empty($ProductStock->description) ? $ProductStock->description : $product->descripion;
                                        $return['variant_name'] = !empty($variant_name) ? $variant_name : '';
                                        if(module_is_active('PreOrder')){
                                            if(auth('customers')->check()){
                                                $return['customer_login'] = auth('customers')->check() ?? null;
                                            }
                                            $pre_order_detail = \Workdo\PreOrder\app\Models\PreOrder::where('store_id', $store->id)->first();
                                            if($return['stock'] <= 0 && isset($pre_order_detail) && $pre_order_detail->enable_pre_order == 'on'){
                                                $return['enable_pre_order'] = 'on';
                                                $return['pre_order_name'] = $pre_order_detail->name ?? 'Pre Order';
                                                $return['pre_order_message'] = $pre_order_detail->message ?? '';
                                            }
                                        }
                                        return response()->json($return);
                                    }
                                }


                        } else {
                        }
                    }
                } else {
                    if (module_is_active('WholesaleProduct')) {
                        $return = \Workdo\WholesaleProduct\app\Models\WholesaleProduct::Wholesale_Product_price($product, $request->all(),$store);
                    } elseif (module_is_active('SizeGuideline')) {
                        $return = \Workdo\SizeGuideline\app\Models\SizeGuideline::Size_Product_price($product, $request->all(),$store);
                    }
                }
            } elseif (module_is_active('SizeGuideline')) {
                if (module_is_active('SizeGuideline')) {
                    $return = \Workdo\SizeGuideline\app\Models\SizeGuideline::Size_Product_price($product, $request->all(),$store);
                }
                else{
                    if ($product->variant_product == 0) {
                        // no varint
                        if (isset($settings['out_of_stock_threshold']) && ($product->product_stock < $settings['out_of_stock_threshold']) && $product->stock_order_status == 'not_allow') {
                            $return['status'] = 'error';
                            $return['stock_status'] = 'out_of_stock';
                            $return['stock_message'] = __('Out Of Stock');
                            $return['message'] = __('Product has been reached max quantity.');
                        } else {
                            $product_original_price = $product->original_price * $qty;
                            $price = FlashSale::saleDiscount($store, $product, $product_id, 0, ($product->sale_price ?? $product->final_price));
                            $product_final_price = ($price) * $qty;
                            // $product_final_price = ($product->sale_price ?? $product->final_price) * $qty;

                            $data['store_id'] = $store->id;
                            $data['product_tax_id'] = $product->tax_id;
                            $data['sub_total'] = $product_final_price;
                            $data['product_original_price'] = $product_original_price;
                            $cart_array  = Tax::TaxCount($data);

                            $return['sub_total'] = $product_final_price;
                            $return['product_original_price'] = $product_original_price;
                            $return['variant_id'] = 0;
                            $return['original_price'] = $cart_array['original_price'];
                            $return['final_price'] = $cart_array['final_price'];
                            $return['final_price_text'] = currency_format_with_sym($cart_array['final_price'],  $store->id);
                            $return['currency_name'] = $cart_array['currency_name'];
                            $return['currency'] = $cart_array['currency'];
                            $return['stock_message'] = __('In Stock');
                            $return['total_tax_price'] = $cart_array['total_tax_price'];
                            return response()->json($return);
                        }
                    } elseif ($product->variant_product == 1) {
                        // has varint
                        if (is_array($varint)) {
                            $variant_name = implode('-', $varint);
                        } else {
                            $variant_name = $varint;
                        }

                        $product->setAttribute('variantName', $variant_name);
                        $ProductStock = ProductVariant::where('product_id', $product_id)
                            ->whereRaw("REPLACE(variant, ' ', '') LIKE ?", [str_replace(' ', '', trim($variant_name))])
                            ->first();
                            if ($ProductStock)
                            {
                                $stock = !empty($ProductStock->stock) ? $ProductStock->stock : $product->product_stock;
                                $variationOptions = explode(',', $ProductStock->variation_option);
                                $variant_enable = in_array('enabled', $variationOptions);
                                $option = in_array('manage_stock', $variationOptions);

                                if ($option == true) {
                                    $stock_status = $ProductStock->stock_order_status;
                                } else {
                                    $stock_status = $product->stock_order_status;
                                }

                                if (($variant_enable == false) || ($stock < $qty && $stock_status == 'not_allow')) {
                                    $return['status'] = 'error';
                                    $return['variant_id'] = $ProductStock->id;
                                    if($variant_enable == false){
                                        $return['stock_status'] = 'not_available';
                                        $return['stock_message'] = __('Not Available');
                                        $return['message'] = __('Product variant is not available.');
                                    }else{
                                        $return['stock_status'] = 'out_of_stock';
                                        $return['stock_message'] = __('Out Of Stock');
                                        $return['message'] = __('Product has been reached max quantity.');
                                    }
                                } else {
                                    $sale_price = !empty($ProductStock->price) ? $ProductStock->price : $ProductStock->variation_price;

                                    $variation_price = !empty($ProductStock->variation_price) ? $ProductStock->variation_price : $ProductStock->price;

                                    $var_price = !empty($sale_price) ? $sale_price : 0;

                                    $product_original_price = $variation_price * $qty;
                                    $price = FlashSale::saleDiscount($store, $product, $product_id, $ProductStock->id, $sale_price);
                                    $product_final_price = ($price) * $qty;
                                    // $product_final_price = ($sale_price) * $qty;

                                    // $product_original_price = $variation_price * $qty;
                                    // $product_final_price = $var_price * $qty;
                                    if ($option == true) {
                                        $variat_stock = !empty($ProductStock->stock) ? $ProductStock->stock : 0;
                                    }else{
                                        $variat_stock = !empty($ProductStock->stock) ? $ProductStock->stock : $product->product_stock;
                                    }

                                    $data['store_id'] = $store->id;
                                    $data['product_tax_id'] = $product->tax_id;
                                    $data['sub_total'] = $product_final_price;
                                    $data['product_original_price'] = $product_original_price;
                                    $cart_array  = Tax::TaxCount($data);

                                    $return['sub_total'] = $product_final_price;
                                    $return['product_original_price'] = $product_original_price;
                                    $return['variant_id'] = $ProductStock->id;
                                    $return['original_price'] = $cart_array['original_price'];
                                    $return['final_price'] = $cart_array['final_price'];
                                    $return['final_price_text'] = currency_format_with_sym($cart_array['final_price'],  $store->id);
                                    $return['currency_name'] = $cart_array['currency_name'];
                                    $return['currency'] = $cart_array['currency'];
                                    $return['total_tax_price'] = $cart_array['total_tax_price'];
                                    $return['enable_option_data'] = !empty($option) ? $option : '';
                                    $return['stock'] = !empty($variat_stock) ? $variat_stock : 0;
                                    $return['stock_status'] = !empty($ProductStock->stock_status) ? $ProductStock->stock_status : '';
                                    $return['stock_message'] = __('In Stock');
                                    $return['description'] = !empty($ProductStock->description) ? $ProductStock->description : $product->descripion;
                                    $return['variant_name'] = !empty($variant_name) ? $variant_name : '';
                                    if(module_is_active('PreOrder')){
                                        if(auth('customers')->check()){
                                            $return['customer_login'] = auth('customers')->check() ?? null;
                                        }
                                        $pre_order_detail = \Workdo\PreOrder\app\Models\PreOrder::where('store_id', $store->id)->first();
                                        if($return['stock'] <= 0 && isset($pre_order_detail) && $pre_order_detail->enable_pre_order == 'on'){
                                            $return['enable_pre_order'] = 'on';
                                            $return['pre_order_name'] = $pre_order_detail->name ?? 'Pre Order';
                                            $return['pre_order_message'] = $pre_order_detail->message ?? '';
                                        }
                                    }
                                    return response()->json($return);
                                }
                            }


                    } else {
                    }
                }
            } else {
                if ($product->variant_product == 0) {
                    // no varint
                    if (isset($settings['out_of_stock_threshold']) && ($product->product_stock < $settings['out_of_stock_threshold']) && $product->stock_order_status == 'not_allow') {
                        $return['status'] = 'error';
                        $return['stock_status'] = 'out_of_stock';
                        $return['stock_message'] = __('Out Of Stock');
                        $return['message'] = __('Product has been reached max quantity.');
                    } else {
                        $product_original_price = $product->original_price * $qty;

                        $price = FlashSale::saleDiscount($store, $product, $product_id, 0, ($product->sale_price ?? $product->final_price));
                        $product_final_price = ($price) * $qty;
                        // $product_final_price = ($product->sale_price ?? $product->final_price) * $qty;

                        $data['store_id'] = $store->id;
                        $data['product_tax_id'] = $product->tax_id;
                        $data['sub_total'] = $product_final_price;
                        $data['product_original_price'] = $product_original_price;
                        $cart_array  = Tax::TaxCount($data);

                        $return['sub_total'] = $product_final_price;
                        $return['product_original_price'] = $product_original_price;
                        $return['variant_id'] = 0;
                        $return['original_price'] = $cart_array['original_price'];
                        $return['final_price'] = $cart_array['final_price'];
                        $return['final_price_text'] = currency_format_with_sym($cart_array['final_price'],  $store->id);
                        $return['currency_name'] = $cart_array['currency_name'];
                        $return['currency'] = $cart_array['currency'];
                        $return['stock_message'] = __('In Stock');
                        $return['total_tax_price'] = $cart_array['total_tax_price'];
                        return response()->json($return);
                    }
                } elseif ($product->variant_product == 1) {
                    // has varint
                    if (is_array($varint)) {
                        $variant_name = implode('-', $varint);
                    } else {
                        $variant_name = $varint;
                    }

                    $product->setAttribute('variantName', $variant_name);
                    $ProductStock = ProductVariant::where('product_id', $product_id)
                        ->whereRaw("REPLACE(variant, ' ', '') LIKE ?", [str_replace(' ', '', trim($variant_name))])
                        ->first();
                    if ($ProductStock) {
                        $stock = !empty($ProductStock->stock) ? $ProductStock->stock : $product->product_stock;
                        $variationOptions = explode(',', $ProductStock->variation_option);
                        $variant_enable = in_array('enabled', $variationOptions);
                        $option = in_array('manage_stock', $variationOptions);

                        if ($option == true) {
                            $stock_status = $ProductStock->stock_order_status;
                        } else {
                            $stock_status = $product->stock_order_status;
                        }

                        if (($variant_enable == false) || ($stock < $qty && $stock_status == 'not_allow')) {
                            $return['status'] = 'error';
                            $return['variant_id'] = $ProductStock->id;
                            if($variant_enable == false){
                                $return['stock_status'] = 'not_available';
                                $return['stock_message'] = __('Not Available');
                                $return['message'] = __('Product variant is not available.');
                            }else{
                                $return['stock_status'] = 'out_of_stock';
                                $return['stock_message'] = __('Out Of Stock');
                                $return['message'] = __('Product has been reached max quantity.');
                            }
                        } else {
                            $sale_price = !empty($ProductStock->price) ? $ProductStock->price : $ProductStock->variation_price;

                            $variation_price = !empty($ProductStock->variation_price) ? $ProductStock->variation_price : $ProductStock->price;

                            $var_price = !empty($sale_price) ? $sale_price : 0;

                            $product_original_price = $variation_price * $qty;

                            $price = FlashSale::saleDiscount($store, $product, $product_id, $ProductStock->id, $sale_price);

                            $product_final_price = ($price) * $qty;
                            // $product_final_price = ($sale_price) * $qty;

                            // $product_original_price = $variation_price * $qty;
                            // $product_final_price = $var_price * $qty;
                            if ($option == true) {
                                $variat_stock = !empty($ProductStock->stock) ? $ProductStock->stock : 0;
                            } else {
                                $variat_stock = !empty($ProductStock->stock) ? $ProductStock->stock : $product->product_stock;
                            }
                            $data['store_id'] = $store->id;
                            $data['product_tax_id'] = $product->tax_id;
                            $data['sub_total'] = $product_final_price;
                            $data['product_original_price'] = $product_original_price;
                            $cart_array  = Tax::TaxCount($data);

                            $return['sub_total'] = $product_final_price;
                            $return['product_original_price'] = $product_original_price;
                            $return['variant_id'] = $ProductStock->id;
                            $return['original_price'] = $cart_array['original_price'];
                            $return['final_price'] = $cart_array['final_price'];
                            $return['final_price_text'] = currency_format_with_sym($cart_array['final_price'],  $store->id);
                            $return['currency_name'] = $cart_array['currency_name'];
                            $return['currency'] = $cart_array['currency'];
                            $return['total_tax_price'] = $cart_array['total_tax_price'];
                            $return['enable_option_data'] = !empty($option) ? $option : '';
                            $return['stock'] = !empty($variat_stock) ? $variat_stock : 0;
                            $return['stock_status'] = !empty($ProductStock->stock_status) ? $ProductStock->stock_status : '';
                            $return['stock_message'] = __('In Stock');
                            $return['description'] = !empty($ProductStock->description) ? $ProductStock->description : $product->descripion;
                            $return['variant_name'] = !empty($variant_name) ? $variant_name : '';
                            if(module_is_active('PreOrder')){
                                if(auth('customers')->check()){
                                    $return['customer_login'] = auth('customers')->check() ?? null;
                                }
                                $pre_order_detail = \Workdo\PreOrder\app\Models\PreOrder::where('store_id', $store->id)->first();
                                if($return['stock'] <= 0 && isset($pre_order_detail) && $pre_order_detail->enable_pre_order == 'on'){
                                    $return['enable_pre_order'] = 'on';
                                    $return['pre_order_name'] = $pre_order_detail->name ?? 'Pre Order';
                                    $return['pre_order_message'] = $pre_order_detail->message ?? '';
                                }
                            }
                            return response()->json($return);
                        }
                    }
                } else {
                }
            }
        } else {
            $return['status'] = 'error';
            $return['message'] = __('Whoops! Something went wrong.');
        }
        return response()->json($return);
    }

    public function searchProducts(Request $request)
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
                    $products = Product::where('name', 'LIKE', "%{$request->search}%")->where('store_id', getCurrentStore())->get();
                } else {
                    $products = Product::where('name', 'LIKE', "%{$request->search}%")->where('store_id', getCurrentStore())->Where('category_id', $request->cat_id)->get();
                }
            }
            $productId = null;
            if (count($products) > 0) {
                foreach ($products as $key => $product) {
                    $productId = $product->id;
                    
                    // Handle digital product images
                    if ($product->product_type === 'digital' && module_is_active('DigitalProduct')) {
                        if (class_exists('\Workdo\DigitalProduct\app\Models\DigitalProductImage')) {
                            $digitalImage = \Workdo\DigitalProduct\app\Models\DigitalProductImage::where('product_id', $product->id)->first();
                            if ($digitalImage && !empty($digitalImage->image_path)) {
                                $image_url = get_file($digitalImage->image_path);
                            } else {
                                $image_url = !empty($product->cover_image_path) ? get_file($product->cover_image_path) : get_file('uploads/cover_image_path/default.jpg');
                            }
                        } else {
                            $image_url = !empty($product->cover_image_path) ? get_file($product->cover_image_path) : get_file('uploads/cover_image_path/default.jpg');
                        }
                    } else {
                        $image_url = !empty($product->cover_image_path) ? get_file($product->cover_image_path) : get_file('uploads/cover_image_path/default.jpg');
                    }

                    if ($product->variant_product != '1') {
                        if ($product->track_stock == 0) {
                            $quantity = $product->stock_status;
                            if ($product->stock_status == 'in_stock') {
                                $quantity = 'In Stock';
                            } elseif ($product->stock_status == 'on_backorder') {
                                $quantity = 'On Backorder';
                            } else {
                                $quantity = 'Out of Stock';
                            }
                        } else {
                            $quantity = $product->product_stock . ' Qty';
                        }

                        if ($request->session_key == 'purchases') {
                            $productprice = getProductActualPrice($product);
                        } else if ($request->session_key == 'pos_' . getCurrentStore()) {
                            $productprice = getProductActualPrice($product);
                        } else {
                            $productprice = getProductActualPrice($product);
                        }

                        $productprice = currency_format_with_sym($productprice, $store_id->id);
                        $output .= ' <div class="col-xl-4 col-lg-6 col-sm-6 col-xs-6 col-12">
                                    <div class="tab-pane fade show active toacart w-100" data-url="' . url('/addToCart/' . $product->id . '/' . $lastsegment) . '">
                                        <div class="btn-primary position-relative card">
                                            <img alt="Image placeholder" src="' . asset($image_url) . '" class="card-image avatar hover-shadow-lg" style=" height: 6rem; width: 100%;">
                                            <div class="p-0 custom-card-body card-body d-flex ">
                                                <div class="card-body my-2 p-2 text-center card-bottom-content">
                                                <h6 class="mb-2 text-dark product-title-name">' . $product->name . '</h6>
                                                <p class="mb-2 text-dark product-title-name small">' . $product->slug . '</p>
                                                <small class="text-primary">' . $productprice . '</small>
                                                <small class="top-badge p-2 badge-danger mb-0">' . $quantity . '</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div> ';
                    } else {

                        $output .= ' <div class="col-xl-4 col-lg-6 col-sm-6 col-xs-6 col-12">
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
                //return Response($output);
            } else {
                $output = '<div class="card card-body col-12 text-center">
                    <h5>' . __("No Product Available") . '</h5>
                    </div>';
               // return Response($output);
            }

            $in_cart = false;
            $cart = session()->get('pos_' . getCurrentStore());
            if (!empty($productId) && isset($cart[$productId])) {
                $in_cart = true;
            }
            return response()->json(
                [
                    'code' => 200,
                    'status' => 'Success',
                    'success' => __('Product get successfully!'),
                    'in_cart' => $in_cart,
                    'html' => $output,
                ]
            );
        }
    }

    public function searchProductsSku(Request $request)
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
                    $products = Product::where('slug', 'LIKE', "%{$request->search}%")->where('store_id', getCurrentStore())->get();
                } else {
                    $products = Product::where('slug', 'LIKE', "%{$request->search}%")->where('store_id', getCurrentStore())->Where('category_id', $request->cat_id)->get();
                }
            }
            $productId = null;
            if (count($products) > 0) {
                foreach ($products as $key => $product) {
                    $productId = $product->id;
                    
                    // Handle digital product images
                    if ($product->product_type === 'digital' && module_is_active('DigitalProduct')) {
                        if (class_exists('\Workdo\DigitalProduct\app\Models\DigitalProductImage')) {
                            $digitalImage = \Workdo\DigitalProduct\app\Models\DigitalProductImage::where('product_id', $product->id)->first();
                            if ($digitalImage && !empty($digitalImage->image_path)) {
                                $image_url = get_file($digitalImage->image_path);
                            } else {
                                $image_url = !empty($product->cover_image_path) ? get_file($product->cover_image_path) : get_file('uploads/cover_image_path/default.jpg');
                            }
                        } else {
                            $image_url = !empty($product->cover_image_path) ? get_file($product->cover_image_path) : get_file('uploads/cover_image_path/default.jpg');
                        }
                    } else {
                        $image_url = !empty($product->cover_image_path) ? get_file($product->cover_image_path) : get_file('uploads/cover_image_path/default.jpg');
                    }

                    if ($product->variant_product != '1') {
                        if ($product->track_stock == 0) {
                            $quantity = $product->stock_status;
                            if ($product->stock_status == 'in_stock') {
                                $quantity = 'In Stock';
                            } elseif ($product->stock_status == 'on_backorder') {
                                $quantity = 'On Backorder';
                            } else {
                                $quantity = 'Out of Stock';
                            }
                        } else {
                            $quantity = $product->product_stock . ' Qty';
                        }

                        if ($request->session_key == 'purchases') {
                            $productprice = getProductActualPrice($product);
                        } else if ($request->session_key == 'pos_' . getCurrentStore()) {
                            $productprice = getProductActualPrice($product);
                        } else {
                            $productprice = getProductActualPrice($product);
                        }

                        $productprice = currency_format_with_sym($productprice, $store_id->id);
                        $output .= ' <div class="col-xl-4 col-lg-6 col-sm-6 col-xs-6 col-12">
                                    <div class="tab-pane fade show active toacart w-100" data-url="' . url('/addToCart/' . $product->id . '/' . $lastsegment) . '">
                                        <div class="btn-primary position-relative card">
                                            <img alt="Image placeholder" src="' . asset($image_url) . '" class="card-image avatar hover-shadow-lg" style=" height: 6rem; width: 100%;">
                                            <div class="p-0 custom-card-body card-body d-flex ">
                                                <div class="card-body my-2 p-2 text-center card-bottom-content">
                                                <h6 class="mb-2 text-dark product-title-name">' . $product->name . '</h6>
                                                <p class="mb-2 text-dark product-title-name small">' . $product->slug . '</p>
                                                <small class="text-primary">' . $productprice . '</small>
                                                <small class="top-badge badge p-2 badge-danger mb-0">' . $quantity . '</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div> ';
                    } else {

                        $output .= ' <div class="col-xl-4 col-lg-6 col-sm-6 col-xs-6 col-12">
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
                // return Response($output);
            } else {
                $output = '<div class="card card-body col-12 text-center">
                    <h5>' . __("No Product Available") . '</h5>
                    </div>';
               // return Response($output);
            }

            $in_cart = false;
            $cart = session()->get('pos_' . getCurrentStore());
            if (!empty($productId) && isset($cart[$productId])) {
                $in_cart = true;
            }
            return response()->json(
                [
                    'code' => 200,
                    'status' => 'Success',
                    'success' => __('Product get successfully!'),
                    'in_cart' => $in_cart,
                    'html' => $output,
                ]
            );
        }
    }

    public function addToCart(Request $request, $id, $session_key, $variant_id = 0)
    {
        $store = $store_id = getStoreById(getCurrentStore());

        $product = Product::find($id);
        $settings = Utility::Setting($store->id);
        if (!$product) {
            return response()->json(
                [
                    'code' => 404,
                    'status' => 'Error',
                    'error' => __('This product is not found!'),
                ],
                404
            );
        }

        $productname = $product->name;

        $variant = null;
        $productquantity = $productprice = 0;
        if (isset($request->variants)) {
            $variant = ProductVariant::where('product_id', $id)->where('variant', $request->variants)->first();
            if ($variant) {
                $productquantity = $variant->stock;
                if ($session_key ==  'pos_' . getCurrentStore() && $productquantity <= 0) {
                    return response()->json(
                        [
                            'code' => 404,
                            'status' => 'Error',
                            'error' => __('This product is out of stock!'),
                        ],
                        404
                    );
                }

                if ($session_key == 'pos_' . getCurrentStore()) {

                    $productprice = getProductActualPrice($product, $variant);
                } else {
                    $productprice = 0;
                }
            }
        } else {
            if (
                $product->track_stock == 0 && $product->stock_status == 'out_of_stock' ||
                ($product->track_stock != 0 && isset($settings['out_of_stock_threshold']) && ($product->product_stock < $settings['out_of_stock_threshold']) && $product->stock_order_status == 'not_allow') ||
                (!$product || ($session_key != 'pos_' . getCurrentStore()))
            ) {
                return response()->json(
                    [
                        'code' => 404,
                        'status' => 'Error',
                        'error' => __('This product is out of stock!'),
                    ],
                    404
                );
            }

            $productquantity = $product->product_stock;
            if ($session_key == 'pos_' . getCurrentStore()) {

                $productprice = getProductActualPrice($product);
            } else {
                $productprice = 0;
            }
        }

        $originalquantity = (int) $productquantity;

        $tax_option = TaxOption::where('store_id', $store_id->id)
		->pluck('value', 'name')->toArray();

        $Tax = Tax::where('id', $product->tax_id)->where('store_id', $store_id->id)->first();
        $tax_price = 0;
        $product_tax = '';
        if((isset($tax_option['price_type']) && $tax_option['price_type'] != 'inclusive') && (isset($tax_option['shop_price']) && $tax_option['shop_price'] != 'including'))
        {
            if ($Tax && count($Tax->tax_methods()) > 0) {
                foreach ($Tax->tax_methods() as $mkey => $method) {
                    $amount = $method->tax_rate * $productprice / 100;
                    $tax_price += $amount;
                    $cart_array['tax_info'][$mkey]["tax_name"] = $method->name;
                    $cart_array['tax_info'][$mkey]["tax_type"] = $method->tax_rate;
                    $cart_array['tax_info'][$mkey]["tax_amount"] = $amount;
                    $product_tax .= !empty($method) ? "<span class='badge bg-primary'>" . $method->name . ' (' . $method->tax_rate . '%)' . "</span><br>" : '';
                    $cart_array['tax_info'][$mkey]["id"] = $method->id;
                    $cart_array['tax_info'][$mkey]["tax_price"] = SetNumber($amount);
                }
            }
        }
        $subtotal = $productprice + $tax_price;
        $cart            = session()->get($session_key);
        // Handle digital product images
        if ($product->product_type === 'digital' && module_is_active('DigitalProduct')) {
            if (class_exists('\Workdo\DigitalProduct\app\Models\DigitalProductImage')) {
                $digitalImage = \Workdo\DigitalProduct\app\Models\DigitalProductImage::where('product_id', $product->id)->first();
                if ($digitalImage && !empty($digitalImage->image_path)) {
                    $image_url = get_file($digitalImage->image_path);
                } else {
                    $image_url = !empty($product->cover_image_path) ? get_file($product->cover_image_path) : ('uploads/is_cover_image') . '/default.jpg';
                }
            } else {
                $image_url = !empty($product->cover_image_path) ? get_file($product->cover_image_path) : ('uploads/is_cover_image') . '/default.jpg';
            }
        } else {
            if (!empty($product->cover_image_path)) {
                $image_url = get_file($product->cover_image_path);
            } else {
                $image_url = ('uploads/is_cover_image') . '/default.jpg';
            }
        }

        $model_delete_id = 'delete-form-' . $id;

        $carthtml = '';

        $carthtml .= '<tr data-product-id="' . $id . '" id="product-id-' . $id . '">
                        <td class="cart-images">
                            <img alt="Image placeholder" src="' . ($image_url) . '" class="card-image avatar shadow hover-shadow-lg">
                        </td>

                        <td class="name">' . $productname . '</td>

                        <td class="">
                                <span class="quantity buttons_added">
                                        <input type="button" value="-" class="minus bg-primary">
                                        <input type="number" step="1" min="1" max="" name="quantity" title="' . __('Quantity') . '" class="input-number" size="4" data-url="' . url('update-cart/') . '" data-id="' . $id . '" style="width:50px;">
                                        <input type="button" value="+" class="plus bg-primary">
                                </span>
                        </td>
                        <td class="tax">' . $product_tax . '</td>

                        <td class="price">' . (currency_format_with_sym($productprice, $store_id->id) ?? SetNumberFormat($productprice)) . '</td>

                        <td class="total_orignal_price">' . (currency_format_with_sym($subtotal, $store_id->id) ?? SetNumberFormat($subtotal)) . '</td>

                        <td class="">
                            <form method="post" class="mb-0" action="' . route('remove-from-cart') . '"  accept-charset="UTF-8" id="' . $model_delete_id . '">
                            <button type="button" class="show_confirm btn btn-sm btn-danger" data-confirm="' . __('Are You Sure?') . '" data-text="' . __('This action can not be undone. Do you want to continue?') . '">
                            <span class=""><i class="ti ti-trash"></i></span>
                            </button>
                                <input name="_method" type="hidden" value="DELETE">
                                <input name="_token" type="hidden" value="' . csrf_token() . '">
                                <input type="hidden" name="session_key" value="' . $session_key . '">
                                <input type="hidden" name="id" value="' . $id . '">
                            </form>
                        </td>
                    </td>';
        // if cart is empty then this the first product

        // Get the appropriate image path for cart storage
        $cart_image_path = $product->cover_image_path;
        if ($product->product_type === 'digital' && module_is_active('DigitalProduct')) {
            if (class_exists('\Workdo\DigitalProduct\app\Models\DigitalProductImage')) {
                $digitalImage = \Workdo\DigitalProduct\app\Models\DigitalProductImage::where('product_id', $product->id)->first();
                if ($digitalImage && !empty($digitalImage->image_path)) {
                    $cart_image_path = $digitalImage->image_path;
                }
            }
        }

        if (!$cart) {
            $cart = [
                $id => [
                    "product_id" => $product->id,
                    "name" => $productname,
                    "image" => $cart_image_path,
                    "quantity" => 1,
                    "orignal_price" => $productprice,
                    "per_product_discount_price" => $product->discount_amount,
                    "discount_price" => $product->discount_amount,
                    "final_price" => $subtotal,
                    "id" => $id,
                    "tax" => $tax_price,
                    "total_orignal_price" => $subtotal,
                    "originalquantity" => $originalquantity,
                    'variant_id' => $variant->id ?? 0,
                    "variant_name" => $product->variant_attribute,
                    "return" => 0,
                ],
            ];

            if ((($product->track_stock != 0 && $originalquantity < $cart[$id]['quantity']) || ($product->track_stock == 0 && $product->stock_status == 'out_of_stock')) && $session_key != 'pos_' . getCurrentStore()) {
                return response()->json(
                    [
                        'code' => 404,
                        'status' => 'Error',
                        'error' => __('This product is out of stock!'),
                    ],
                    404
                );
            }

            session()->put($session_key, $cart);

            return response()->json(
                [
                    'code' => 200,
                    'status' => 'Success',
                    'success' => $productname . __(' added to cart successfully!'),
                    'product' => $cart[$id],
                    'carthtml' => $carthtml,
                ]
            );
        }

        // if cart not empty then check if this product exist then increment quantity
        if (isset($cart[$id])) {
            $cartProduct = Product::find($id);
            $cart[$id]['quantity']++;
            $cart[$id]['id'] = $id;

            $subtotal = $cart[$id]["orignal_price"] * $cart[$id]["quantity"];
            $tax = 0;
            $taxes            = !empty($cart[$id]["tax"]) ? $cart[$id]["tax"] : '';

            $Tax = Tax::where('id', $cartProduct->tax_id)->where('store_id', $store_id->id)->first();
            $tax_price = 0;
            $product_tax = '';
            $price = $cart[$id]["orignal_price"] * $cart[$id]["quantity"];
            if((isset($tax_option['price_type']) && $tax_option['price_type'] != 'inclusive') && (isset($tax_option['shop_price']) && $tax_option['shop_price'] != 'including'))
            {
                if ($Tax && count($Tax->tax_methods()) > 0) {
                    foreach ($Tax->tax_methods() as $mkey => $method) {
                        $amount = $method->tax_rate * $price / 100;
                        $tax_price += $amount;
                        $cart_array['tax_info'][$mkey]["tax_name"] = $method->name;
                        $cart_array['tax_info'][$mkey]["tax_type"] = $method->tax_rate;
                        $cart_array['tax_info'][$mkey]["tax_amount"] = $amount;
                        $product_tax .= !empty($method) ? "<span class='badge bg-primary'>" . $method->name . ' (' . $method->tax_rate . '%)' . "</span><br>" : '';
                        $cart_array['tax_info'][$mkey]["id"] = $method->id;
                        $cart_array['tax_info'][$mkey]["tax_price"] = SetNumber($amount);
                    }
                }
            }

            if (!empty($taxes)) {
                $productprice          = $cart[$id]["orignal_price"] *  (float)$cart[$id]["quantity"];
                $subtotal = $productprice +  $tax_price;
            } else {

                $productprice          = $cart[$id]["orignal_price"] ?? 0;
                $subtotal = $productprice  *  (float)$cart[$id]["quantity"];
            }
            $cart[$id]["total_orignal_price"] = $subtotal;

            $cart[$id]["total_orignal_price"]         = $subtotal + $tax;
            $cart[$id]["originalquantity"] = $originalquantity;
            $cart[$id]["tax"]      = $tax_price;
            if ((($product->track_stock != 0 && $originalquantity < $cart[$id]['quantity']) || ($product->track_stock == 0 && $product->stock_status == 'out_of_stock')) && $session_key != 'pos_' . getCurrentStore()) {
                return response()->json(
                    [
                        'code' => 404,
                        'status' => 'Error',
                        'error' => __('This product is out of stock!'),
                    ],
                    404
                );
            }

            session()->put($session_key, $cart);

            return response()->json(
                [
                    'code' => 200,
                    'status' => 'Success',
                    'success' => $productname . __(' added to cart successfully!'),
                    'product' => $cart[$id],
                    'carttotal' => $cart,
                ]
            );
        }

        // if item not exist in cart then add to cart with quantity = 1
        $cart[$id] = [
            "product_id" => $product->id,
            "name" => $productname,
            "image" => $cart_image_path,
            "quantity" => 1,
            "orignal_price" => $productprice,
            "per_product_discount_price" => $product->discount_amount,
            "discount_price" => $product->discount_amount,
            "final_price" => $subtotal,
            "id" => $id,
            "tax" => $tax_price,
            "total_orignal_price" => $subtotal,
            "originalquantity" => $originalquantity,
            'variant_id' => $variant->id ?? 0,
            "variant_name" => $product->variant_attribute,
            "return" => 0,
        ];
        if ((($product->track_stock != 0 && $originalquantity < $cart[$id]['quantity']) || ($product->track_stock == 0 && $product->stock_status == 'out_of_stock')) && $session_key != 'pos_' . getCurrentStore()) {
            return response()->json(
                [
                    'code' => 404,
                    'status' => 'Error',
                    'error' => __('This product is out of stock!'),
                ],
                404
            );
        }

        session()->put($session_key, $cart);
        return response()->json(
            [
                'code' => 200,
                'status' => 'Success',
                'success' => $productname . __(' added to cart successfully!'),
                'product' => $cart[$id],
                'carthtml' => $carthtml,
                'carttotal' => $cart,
            ]
        );
    }

    public function updateCart(Request $request)
    {
        $id          = $request->id;
        $quantity    = $request->quantity;
        $discount    = $request->discount;
        $session_key = $request->session_key;
        $store_id = getStoreById(getCurrentStore());

        if ($request->ajax() && isset($id) && !empty($id) && isset($session_key) && !empty($session_key)) {
            $cart = session()->get($session_key);

            if (isset($cart[$id]) && $quantity == 0) {
                unset($cart[$id]);
            }

            if ($quantity && !empty($quantity)) {

                $cart[$id]["quantity"] = $quantity;
                $taxes            = !empty($cart[$id]["tax"]) ? $cart[$id]["tax"] : '';

                $price = ($cart[$id]["orignal_price"] ?? 0) * $quantity;

                $tax_option = TaxOption::where('store_id', $store_id->id)
                            ->pluck('value', 'name')->toArray();

                $product = Product::where('id', $id)->first();
                $Tax = Tax::where('store_id', $store_id->id)->where('id', $product->tax_id)->first();
                $tax_price = 0;
                $product_tax = '';
                if((isset($tax_option['price_type']) && $tax_option['price_type'] != 'inclusive') && (isset($tax_option['shop_price']) && $tax_option['shop_price'] != 'including'))
                {
                    if ($Tax) {
                        if (count($Tax->tax_methods()) > 0) {
                            foreach ($Tax->tax_methods() as $mkey => $method) {
                                $tax_price += $method->tax_rate * $price / 100;
                                $product_tax .= !empty($Tax) ? "<span class='badge bg-primary'>" . $method->name . ' (' . $method->tax_rate . '%)' . "</span><br>" : '';
                            }
                        }
                    }
                }

                $subtotal = $price + $tax_price;
                $cart[$id]["tax"] = $tax_price;
                $producttax = 0;
                if (!empty($taxes)) {
                    $productprice          = $cart[$id]["orignal_price"] *  (float)$quantity;
                    $subtotal = $productprice +  $tax_price;
                } else {
                    $productprice          = $cart[$id]["orignal_price"] ?? 0;
                    $subtotal = ($productprice  *  (float) $quantity) + $tax_price;
                }

                $cart[$id]["total_orignal_price"] = $subtotal;
            }

            if (isset($cart[$id]) && isset($cart[$id]["originalquantity"]) < $cart[$id]['quantity'] && $session_key == 'pos_' . getCurrentStore()) {
                return response()->json(
                    [
                        'code' => 404,
                        'status' => 'Error',
                        'error' => __('This product is out of stock!'),
                    ],
                    404
                );
            }

            $subtotal = array_sum(array_column($cart, 'total_orignal_price'));
            $discount = $request->discount;
            $total = $subtotal - (float)$discount;
            $totalDiscount = currency_format_with_sym($total, $store_id->id) ?? SetNumberFormat($total);
            $discount = $totalDiscount;

            session()->put($session_key, $cart);
            return response()->json(
                [
                    'code' => 200,
                    'success' => __('Cart updated successfully!'),
                    'product' => $cart,
                    'discount' => $discount,
                ]
            );
        } else {
            return response()->json(
                [
                    'code' => 404,
                    'status' => 'Error',
                    'error' => __('This Product is not found!'),
                ],
                404
            );
        }
    }

    public function removeFromCart(Request $request)
    {
        $id          = $request->id;
        $session_key = $request->session_key;
        if (isset($id) && !empty($id) && isset($session_key) && !empty($session_key)) {
            $cart = session()->get($session_key);
            if (isset($cart[$id])) {
                unset($cart[$id]);
                session()->put($session_key, $cart);
            }
            return redirect()->back()->with('success', __('Product removed from cart!'));
        } else {
            return redirect()->back()->with('error', __('This Product is not found!'));
        }
    }

    public function emptyCart(Request $request)
    {
        $session_key = $request->session_key;

        if (isset($session_key) && !empty($session_key)) {
            $cart = session()->get($session_key);
            if (isset($cart) && count($cart) > 0) {
                session()->forget($session_key);
            }

            return redirect()->back()->with('success', __('Cart is empty!'));
        } else {
            return redirect()->back()->with('error', __('Cart cannot be empty!.'));
        }
    }

    public function productVariant(Request $request, $id, $session_key)
    {
        $product = Product::where('id', $id)->first();
        $product_variant_names = ProductVariant::where('product_id', $product->id)->get();

        return view('pos.product_variant', compact('product', 'product_variant_names', 'session_key'));
    }

    public function getProductsVariantQuantity(Request $request)
    {
        $session_key = $request->session_key;
        $cart = session()->get($session_key);
        $status = false;
        $quantity = $variant_id = 0;
        $quantityHTML = '<strong>' . __('Please select variants to get available quantity.') . '</strong>';
        $priceHTML = '';
        $product = Product::find($request->product_id);
        $price = currency_format_with_sym($product->price, getCurrentStore());
        $status = false;

        if ($product && $request->variants != '') {
            $variant = ProductVariant::where('product_id', $product['id'])->where('variant', $request->variants)->first();
            if ($variant) {
                $status = true;
                $quantity = $variant->stock - (isset($cart[$variant->id]['quantity']) ? $cart[$variant->id]['quantity'] : 0);
                $store = getStoreById(getCurrentStore());
                $price = currency_format_with_sym(Product::ProductPrice($store->slug, $product->id,$variant->id));
                $variant_id = $variant->id;
            }
        }

        return response()->json(
            [
                'status' => $status,
                'price' => $price,
                'quantity' => $quantity,
                'variant_id' => $variant_id
            ]
        );
    }

    public function VariantDelete(Request $request, $id, $product_id)
    {
        if (auth()->user() && auth()->user()->can('Delete Variants')) {
            $product = Product::find($product_id);
            if (!empty($product->variants_json) && ProductVariantOption::find($id)->exists()) {
                $var_json = json_decode($product->variants_json, true);

                $i = 0;
                foreach ($var_json[0] as $key => $value) {
                    $var_ops = explode(' : ', ProductVariantOption::find($id)->name);
                    $count = ProductVariantOption::where('product_id', $product->id)->where('name', 'LIKE', '%' . $var_ops[0] . '%')->count();
                    if ($count == 1 && $i == 0) {
                        $unsetIndex = array_search($var_ops[0], $var_json[0]['variant_options'], true);
                        unset($var_json[0]['variant_options'][$unsetIndex]);
                    }
                    $i++;
                }
                $variants = ProductVariantOption::where('product_id', $product->id)->count();
                if ($variants == 1) {
                    $product->variants_json = '{}';
                    $product->update();
                } else {
                    $product->variants_json = json_encode($var_json);
                    $product->update();
                }
            }
            ProductVariantOption::find($id)->delete();
            return redirect()->back()->with('success', __('Variant successfully deleted.'));
        } else {
            return redirect()->back()->with('error', 'Permission denied.');
        }
    }

    public function list(Request $request)
    {
        if(auth()->user() && auth()->user()->isAbleTo('Manage Products')) {
            $products = Product::where('product_type', null)->where('store_id', getCurrentStore())->orderBy('id', 'desc')->paginate(11);
            $settings = Setting::where('store_id', getCurrentStore())->pluck('value', 'name')->toArray();

            if ($request->id == 1) {
                $msg = __('Product Successfully Created');
                return view('product.list', compact('products', 'settings', 'msg'));
            } elseif ($request->id == 2) {
                $msg = __('Product Successfully Updated');
                return view('product.list', compact('products', 'settings', 'msg'));
            } else {
                $msg = 0;
                return view('product.list', compact('products', 'settings', 'msg'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function formatPrice(Request $request)
    {
        $price = $request->price;
        $store_id = $request->store_id;
        
        return response()->json([
            'formatted_price' => currency_format_with_sym($price, $store_id)
        ]);
    }
}