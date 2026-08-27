<?php

namespace Workdo\AuctionProduct\app\Http\Controllers;

use App\Models\{
    Store,
    Product,
    Category,
    Tax,
    ProductLabel,
    Shipping,
    ProductBrand,
    ProductAttribute,
    Tag,
    User,
    Plan,
    Utility,
    ProductAttributeOption,
    ProductVariant,
    ProductImage,
    NotifyUser,
    Testimonial,
    ProductQuestion
};
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\File;
use Qirolab\Theme\Theme;
use Workdo\AuctionProduct\app\Models\AuctionProductBid;
use Workdo\AuctionProduct\DataTables\AuctionProductData;
use Illuminate\Support\Facades\Cache;

class AuctionProductController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index(Request $request, AuctionProductData $dataTable)
    {
        if (!auth()->user()) {
            return redirect()->route('login');
        }
        if (auth()->user()->isAbleTo('Manage Auction Product')) {
            $store_id = getStoreById(getCurrentStore());
            $products = Product::where('product_type', 'auction')->where('store_id', getCurrentStore())->get();
            $enable_auction_product = \App\Models\Utility::GetValueByName('enable_auction_product', $store_id->id);
            if (isset($enable_auction_product) && $enable_auction_product == 'on') {
                if ($request->id == 1) {
                    $msg = __('Auction Product Created Successfully');
                    return $dataTable->render('auction-product::index', compact('products', 'msg'));
                } elseif ($request->id == 2) {
                    $msg = __('Auction Product Updated Successfully');
                    return $dataTable->render('auction-product::index', compact('products', 'msg'));

                } else {
                    $msg = 0;
                    return $dataTable->render('auction-product::index', compact('products', 'msg'));
                }
            } else {
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
        $link = env('APP_URL') . '/product/';
        $Category = Category::where('store_id', getCurrentStore())->pluck('name', 'id')->prepend('Select Category', '');
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
        return view('auction-product::pages.create', compact('link', 'Category', 'ProductAttribute', 'Tax', 'Tax_status', 'Shipping', 'preview_type', 'tag', 'brands', 'labels'));
    }

    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return Renderable
     */
    public function store(Request $request)
    {
        if (auth()->user()->isAbleTo('Create Auction Product')) {
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
                        'price' => 'required',
                        'sale_price' => 'required',
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

                $validator = \Validator::make($request->all(), $rules);
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

                $dir = 'uploads/' . getCurrentStore();
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
                            $dir_video = 'uploads/' . getCurrentStore() . '/preview_image';
                            
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
                                $msg['msg'] = $result;

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
                        $Product->stock_status = $request->stock_status;
                        $Product->attribute_id = $input['product_attributes'];
                        $Product->product_attribute = $input['attribute_options'];

                        $Product->product_stock = !empty($request->product_stock) ? $request->product_stock : 0;
                        if ($request->track_stock == 1) {
                            $Product->track_stock = $request->track_stock;
                            $Product->stock_order_status = $request->stock_order_status;
                            $Product->low_stock_threshold = !empty($request->low_stock_threshold) ? $request->low_stock_threshold : '';
                        } else {
                            $Product->track_stock = !empty($request->track_stock) ? $request->track_stock : 0;
                            $Product->stock_order_status = '';
                            $Product->low_stock_threshold = !empty($request->low_stock_threshold) ? $request->low_stock_threshold : '';
                        }
                        if ($request->custom_field_status == '1') {
                            $Product->custom_field_status = '1';
                            if (isset($request->custom_field_repeater_basic)) {
                                $filteredCustomData = [];

                                foreach ($request->custom_field_repeater_basic as $info) {
                                    if (!(array_key_exists('custom_field', $info) && $info['custom_field'] === null && array_key_exists('custom_value', $info) && $info['custom_value'] === null)) {
                                        $filteredCustomData[] = $info;
                                    }
                                }

                                if (count($filteredCustomData) > 0) {
                                    $Product->custom_field = json_encode($filteredCustomData);
                                } else {
                                    $Product->custom_field = null;
                                }

                            } else {
                                $Product->custom_field = null;
                                $Product->custom_field_status = '0';
                            }
                        } else {
                            $Product->custom_field = null;
                            $Product->custom_field_status = '0';
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
                                $msg['msg'] = $result;

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
                                    $tag_data->store_id = getCurrentStore();
                                    $tag_data->created_by = \Auth::user()->id;
                                    $tag_data->save();

                                    $tag_data_id[] = $tag_data->id;

                                }
                            }
                        }
                        $tag_product_id = array_merge($tag_data_id, $tag_ids);
                        if (!empty($tag_product_id)) {

                            $Product->tag_id = implode(',', $tag_product_id);
                        }
                        $Product->product_type = 'auction';
                        $Product->store_id = getCurrentStore();
                        $Product->created_by = \Auth::user()->id;

                        $Product->save();
                        if (module_is_active('SizeGuideline')) {
                            \Workdo\SizeGuideline\app\Models\SizeGuideline::saveData($Product, $request->size_chart_title, $request->size_chart_information, $request->all());
                        }
                        if (module_is_active('WholesaleProduct')) {
                            \Workdo\WholesaleProduct\app\Models\WholesaleProduct::saveData($Product, $request->wholesale_repeater_basic);
                        }
                        if (module_is_active('ProductBarCode')) {
                            \Workdo\ProductBarCode\app\Models\ProductBarCode::saveData($Product);
                        }
                        foreach ($request->product_image as $key => $image) {
                            $theme_image = $image;

                            if ($result == 1) {
                                $fileName = rand(10, 100) . '_' . time() . "_" . $image->getClientOriginalName();
                                $pathss = Utility::keyWiseUpload_file($request, 'product_image', $fileName, $dir, $key, []);

                            } else {
                                $msg['flag'] = 'error';
                                $msg['msg'] = $result;

                                return $msg;
                            }

                            if (isset($pathss['url'])) {
                                $ProductImage = new ProductImage();
                                $ProductImage->product_id = $Product->id;
                                $ProductImage->image_path = $pathss['url'];
                                $ProductImage->image_url = $pathss['full_url'];
                                $ProductImage->store_id = getCurrentStore();
                                $ProductImage->save();
                            }
                        }
                    } else {
                        $msg['flag'] = 'error';
                        $msg['msg'] = __('Your Product limit is over, Please upgrade plan');

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
                            $msg['msg'] = $result;

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
                        $product->attribute_id = $input['product_attributes'];
                        $product->product_attribute = $input['attribute_options'];


                        $product->preview_type = $request->preview_type;
                        if (!empty($request->video_url)) {
                            $product->preview_content = $request->video_url;
                        }
                        if (!empty($request->preview_video)) {
                            $ext = $request->file('preview_video')->getClientOriginalExtension();
                            $fileName = 'video_' . time() . rand() . '.' . $ext;

                            $dir_video = 'uploads/' . getCurrentStore() . '/preview_image';

                            if ($result == 1) {
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
                                $msg['msg'] = $result;

                                return $msg;
                            }
                            $product->downloadable_product = $path['url'];
                        }
                        $product->product_stock = !empty($request->product_stock) ? $request->product_stock : 0;
                        $product->variant_product = $request->variant_product;
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


                        $product->attribute_id = $input['product_attributes'];
                        $product->product_attribute = $input['attribute_options'];
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

                            $product->tag_id = implode(',', $tag_product_id);
                        }
                        $product->product_type = 'auction';
                        $product->save();
                        if (module_is_active('SizeGuideline')) {

                            \Workdo\SizeGuideline\app\Models\SizeGuideline::saveData($product, $request->size_chart_title, $request->size_chart_information, $request->all());
                        }
                        if (module_is_active('WholesaleProduct')) {
                            \Workdo\WholesaleProduct\app\Models\WholesaleProduct::saveData($product, $request->wholesale_repeater_basic);
                        }
                        if (module_is_active('ProductBarCode')) {

                            \Workdo\ProductBarCode\app\Models\ProductBarCode::saveData($product);
                        }
                        foreach ($request->product_image as $key => $image) {
                            $theme_image = $image;

                            if ($result == 1) {
                                $fileName = rand(10, 100) . '_' . time() . "_" . $image->getClientOriginalName();
                                $pathss = Utility::keyWiseUpload_file($request, 'product_image', $fileName, $dir, $key, []);
                            } else {
                                $msg['flag'] = 'error';
                                $msg['msg'] = $result;

                                return $msg;
                            }

                            if (isset($pathss['url'])) {
                                $ProductImage = new ProductImage();
                                $ProductImage->product_id = $product->id;
                                $ProductImage->image_path = $pathss['url'];
                                $ProductImage->image_url = $pathss['full_url'];
                                $ProductImage->store_id = getCurrentStore();
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
                                        $product_stock->save();

                                        if ($request->default_variant == '-' . $str) {
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
                        $msg['msg'] = __('Your Product limit is over, Please upgrade plan');

                        return $msg;
                    }
                }
                $msg = [];
                $msg['flag'] = 'success';
                $msg['msg'] = __('Product saved successfully.');
                return $msg;
            } catch (\Exception $e) {
                $msg['flag'] = 'error';
                $msg['msg'] = $e->getMessage();
                return $msg;
            }
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
        return view('auction-product::show');
    }

    /**
     * Show the form for editing the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function edit($id)
    {
        $product = Product::find($id);
        if ($product) {
            $link = env('APP_URL') . '/product/';
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
            return view('auction-product::pages.edit', compact($compact));
        } else {
            return redirect()->back()->with('error', __('The Auction Product detail is not found.'));
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
        $product = Product::find($id);
        if (auth()->user()->isAbleTo('Edit Auction Product')) {
            try {
                $dir = 'uploads/' . getCurrentStore();

                $rules = [
                    'name' => 'required',
                    'category_id' => 'required',
                    'status' => 'required',
                    'variant_product' => 'required',
                    'brand_id' => 'nullable',
                    'label_id' => 'nullable',
                ];
                $validator = \Validator::make($request->all(), $rules);
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

                if ($request->cover_image) {
                    $file_path = $product->cover_image_path;

                    if ($result == 1) {
                        Utility::changeStorageLimit(\Auth::user()->creatorId(), $file_path);

                        $fileName = rand(10, 100) . '_' . time() . "_" . $request->cover_image->getClientOriginalName();
                        $path = Utility::upload_file($request, 'cover_image', $fileName, $dir, []);
                        if ($path['flag'] == 0) {
                            $msg['flag'] = 'error';
                            $msg['msg'] =  $path['msg'];

                            return $msg;
                        }
                        if (File::exists(base_path($product->cover_image_path))) {
                            File::delete(base_path($product->cover_image_path));
                        }
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
                } else {

                    $product->tax_id = null;
                }
                $product->preview_type = $request->preview_type;
                if (!empty($request->video_url)) {
                    $product->preview_content = $request->video_url;
                }
                if (!empty($request->preview_video)) {
                    $ext = $request->file('preview_video')->getClientOriginalExtension();
                    $fileName = 'video_' . time() . rand() . '.' . $ext;

                    $dir_video = 'uploads/' . getCurrentStore() . '/preview_image';

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
                        $filteredCustomData = [];

                        foreach ($request->custom_field_repeater_basic as $info) {
                            if (!(array_key_exists('custom_field', $info) && $info['custom_field'] === null && array_key_exists('custom_value', $info) && $info['custom_value'] === null)) {
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
                        if (File::exists(base_path($product->downloadable_product))) {
                            File::delete(base_path($product->downloadable_product));
                        }

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
                            $msg['msg'] = $result;

                            return $msg;
                        }

                        if (isset($pathss['url'])) {
                            $ProductImage = new ProductImage();
                            $ProductImage->product_id = $product->id;
                            $ProductImage->image_path = $pathss['url'];
                            $ProductImage->image_url = $pathss['full_url'];
                            $ProductImage->store_id = getCurrentStore();
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
                                    $id = ProductAttributeOption::where('terms', $fValue)->first()->toArray();
                                    $optionValues[] = $id['id'];
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
                                $tag_data->store_id = getCurrentStore();
                                $tag_data->created_by = \Auth::user()->id;
                                $tag_data->save();

                                $tag_data_id[] = $tag_data->id;

                            }
                        }
                        $tag_product_id = array_merge($tag_data_id, $tag_ids);

                        if (!empty($tag_product_id)) {
                            $product->tag_id = implode(',', $tag_product_id);

                        }
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
                                    $id = ProductAttributeOption::where('terms', $fValue)->first()->toArray();
                                    $optionValues[] = $id['id'];
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
                    $product->attribute_id = $input['product_attributes'];
                    $product->product_attribute = $input['attribute_options'];

                    $product->preview_type = $request->preview_type;
                    if (!empty($request->video_url)) {
                        $product->preview_content = $request->video_url;
                    }
                    if (!empty($request->preview_video)) {
                        $ext = $request->file('preview_video')->getClientOriginalExtension();
                        $fileName = 'video_' . time() . rand() . '.' . $ext;

                        $dir_video = 'uploads/' . getCurrentStore() . '/preview_image';
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

                            $product->tag_id = implode(',', $tag_product_id);
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
                                        if ($path['flag'] == 0) {
                                            $msg['flag'] = 'error';
                                            $msg['msg'] =  $path['msg'];
        
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
                                    $product_stock->save();

                                    if ($request->default_variant == '-' . $str) {
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

                    \Workdo\SizeGuideline\app\Models\SizeGuideline::saveData($product, $request->size_chart_title, $request->size_chart_information, $request->all());
                }
                if (module_is_active('WholesaleProduct')) {
                    \Workdo\WholesaleProduct\app\Models\WholesaleProduct::saveUpdateData($product, $request->wholesale_repeater_basic);
                }
                if (module_is_active('ProductBarCode')) {
                    \Workdo\ProductBarCode\app\Models\ProductBarCode::saveData($product);
                }
                $msg['flag'] = 'success';
                $msg['msg'] = __('Product update successfully.');
                return $msg;
            } catch (\Exception $e) {
                $msg['flag'] = 'error';
                $msg['msg'] = $e->getMessage();
                return $msg;
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
        if (auth()->user()->isAbleTo('Delete Auction Product')) {
            $product = Product::find($id);
            if ($product) {
                $ProductImages = ProductImage::where('product_id', $product->id)->get();

                $Product = Product::find($product->id);
                $file_path1 = [];
                foreach ($ProductImages as $key => $ProductImage) {
                    $file_path1[] = $ProductImage->image_path;
                }
                $file_paths2[] = $Product->cover_image_path;
                $file_path = array_merge($file_path1, $file_paths2);
                Utility::changeproductStorageLimit(\Auth::user()->creatorId(), $file_path, $file_path1);
                if (!empty($ProductImages)) {
                    foreach ($ProductImages as $key => $ProductImage) {
                        if (File::exists(base_path($ProductImage->image_path))) {
                            File::delete(base_path($ProductImage->image_path));
                        }
                    }
                }

                ProductImage::where('product_id', $product->id)->delete();
                ProductVariant::where('product_id', $product->id)->delete();
                $Product = Product::find($product->id);
                if (!empty($Product)) {
                    // image remove from description json
                    $description_json = $Product->other_description_api;
                    if (!empty($description_json)) {
                        $description_json = json_decode($Product->other_description_api, true);
                        foreach ($description_json['product-other-description'] as $key => $value) {
                            if ($value['field_type'] == 'photo upload') {
                                if (File::exists(base_path($value['value']))) {
                                    File::delete(base_path($value['value']));
                                }
                            }
                        }
                    }
                    // image remove from cover image
                    if (File::exists(base_path($Product->cover_image_path))) {
                        File::delete(base_path($Product->cover_image_path));
                    }
                    Product::where('id', $product->id)->delete();
                }
                return redirect()->back()->with('success', __('Auction Product delete successfully.'));
            } else {
                return redirect()->back()->with('error', __('The Autction Product detail is not found.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function bidingForm(Request $request, $slug, $product_slug)
    {
        $store = getStore($slug);
        $product = Product::where('slug', $product_slug)->first();
     
        $maxBidAmount = AuctionProductBid::where('product_id', $product->id)
            ->max('bid_amount');
        $maxprice = $product->sale_price;
        $bid_amount = $maxBidAmount ? $maxBidAmount : $maxprice;
        $customer = auth('customers')->user();


           
        $return['html'] = view('auction-product::pages.place_bid', compact('slug', 'product', 'store', 'customer', 'bid_amount'))->render();
        return response()->json($return);
    }

    public function addProductbidamount(Request $request, $storeSlug)
    {
        if (!auth('customers')->user()) {
            return redirect()->route('customer.login', $storeSlug);
        }
        $product = Product::find($request->product_id);
        $validator = \Validator::make(
            $request->all(),
            [
                'product_id' => 'nullable',
                'bid_amount' => 'required',
                'customer_id' => 'nullable',
            ]
        );
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', $messages->first());
        }

        $product_bid = new AuctionProductBid();
        $product_bid->product_id = $request->product_id ?? null;
        $product_bid->bid_amount = $request->bid_amount ?? null;
        $product_bid->customer_id = $request->customer_id ?? null;
        $product_bid->store_id = $request->store_id;
        $product_bid->save();

        return redirect()->back()->with('success', __('Your Bid place successfully.'));

    }

    public function product_detail(Request $request, $storeSlug, $product_slug)
    {
        $store = getStore($storeSlug);
        if (!$store) {
            abort(404);
        }
        $store_id = $store->id;
        $slug = $store->slug;
       
        $storeId = $store->id;
        $product = Product::where('slug', $product_slug)->where('store_id', $store_id)->first();
        if (!$product) {
            abort(404);
        }
        $id = $product->id;
        $MainCategoryList = Category::where('status', 1)->where('store_id', $store_id)->get();
       
        $question = ProductQuestion::where('product_id', $id)->where('store_id', $storeId)->get();
        $category_options = Category::where('store_id', $store->id)
            ->where('status', 1)
            ->pluck('name', 'id')
            ->prepend('All Products', '0');
        $reviews = Testimonial::where('status', 1)->where('store_id', $storeId)->get();
        
        $customer = auth('customers')->user();
        $maxBidAmount = AuctionProductBid::where('product_id', $product->id)
            ->max('bid_amount');
        $customer_bid = [];
        if ($customer) {
            $customer_bid = AuctionProductBid::where('product_id', $product->id)
                ->where('customer_id', $customer->id)->latest()->limit(1)->first();
        }
        $maxprice = $product->sale_price;
        $bid_amount = $maxBidAmount ? $maxBidAmount : $maxprice;
        return view('auction-product::pages.auction_product_detail', compact('slug', 'product', 'MainCategoryList', 'store', 'question', 'category_options', 'store_id', 'reviews', 'customer', 'bid_amount', 'customer_bid') );

    }
}
