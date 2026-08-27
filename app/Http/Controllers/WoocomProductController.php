<?php

namespace App\Http\Controllers;

use App\Models\Coupon;
use App\Models\ProductImage;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Models\Plan;
use App\Models\Utility;
use App\Models\WoocommerceConection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Codexshaper\WooCommerce\Facades\Product;
use Codexshaper\WooCommerce\Facades\Variation;
use Codexshaper\WooCommerce\Facades\Category;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeOption;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Cache;
use Yajra\DataTables\Facades\DataTables;

class WoocomProductController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        if(auth()->user() && auth()->user()->isAbleTo('Manage Woocommerce Product'))
        {
        $setting = getAdminAllsetting();
        if(isset($setting['woocommerce_setting_enabled']) && $setting['woocommerce_setting_enabled'] == 'on')
        {
            try{               
                $woocommerce_store_url =Utility::GetValueByName('woocommerce_store_url',getCurrentStore());
                $woocommerce_consumer_secret =Utility::GetValueByName('woocommerce_consumer_secret',getCurrentStore());
                $woocommerce_consumer_key =Utility::GetValueByName('woocommerce_consumer_key',getCurrentStore());

                config(['woocommerce.store_url' => $woocommerce_store_url]);
                config(['woocommerce.consumer_key' => $woocommerce_consumer_key]);
                config(['woocommerce.consumer_secret' => $woocommerce_consumer_secret]);
                // Fetch all products using pagination
                $jsonData = collect(); // Initialize an empty collection
                $page = 1;
                $perPage = 100;
                do {
                    // Fetch products from WooCommerce API, with pagination
                    $products = Product::all(['per_page' => $perPage, 'page' => $page]);
                    $jsonData = $jsonData->merge($products); // Append the new page data to the collection
                    $page++;
                } while (count($products) > 0); // Continue fetching until no products are returned

                // Check if the request is for datatable
                if (request()->ajax()) {
                    $upddata = WoocommerceConection::where('store_id',getCurrentStore())->where('module','=','product')->get()->pluck('woocomerce_id')->toArray();
                    return DataTables::of($jsonData)
                        ->addColumn('cover_image', function ($data) {
                            $imgSrc = !empty($data->images) ? get_file($data->images['0']->src) : asset(Storage::url('uploads/woocommerce.png'));
                            return '<img src="' . $imgSrc . '" alt="" width="100" class="cover_img">';
                        })   
                        ->editColumn('category_name', function ($data) {
                            return $data->categories['0']->name ?? '-';
                        })      
                        ->addColumn('action', function ($customer) use ($upddata) {
                            return view('woocommerce.product_action', compact('customer','upddata'));
                        })
                        ->rawColumns(['cover_image','name','category_name','price','action'])
                        ->make(true);
                }
                return view('woocommerce.product');
            }
            catch(\Exception $e){
                return redirect()->back()->with('error' , 'Something went wrong.');
            }
        }else{
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show(Request $request, $id)
    {
        
        if(auth()->user() && auth()->user()->isAbleTo('Create Woocommerce Product'))
        {
            $user = auth()->user();
            $creator = User::find($user->creatorId());
            $total_products = $user->countProducts();
            $plan = Plan::find($creator->plan_id);
            if (($total_products >= $plan->max_products) && ($plan->max_products != -1)) {
                return redirect()->back()->with('error', __('Your Product limit is over, Please upgrade plan'));
            }
            
            $woocommerce_store_url =Utility::GetValueByName('woocommerce_store_url', getCurrentStore());
            $woocommerce_consumer_secret =Utility::GetValueByName('woocommerce_consumer_secret', getCurrentStore());
            $woocommerce_consumer_key =Utility::GetValueByName('woocommerce_consumer_key', getCurrentStore());

            config(['woocommerce.store_url' => $woocommerce_store_url]);
            config(['woocommerce.consumer_key' => $woocommerce_consumer_key]);
            config(['woocommerce.consumer_secret' => $woocommerce_consumer_secret]);
            $jsonData = Product::find($id);
            
            $variations = [];
            if (isset($jsonData['variations']) && count($jsonData['variations']) > 0) {
                
                foreach ($jsonData['variations'] as $variationId) {
                   $variations[] = Variation::find($jsonData['id'],$variationId);
                }
            }
           
            $woocommerceConectionQuery = WoocommerceConection::query();
            if (isset($jsonData['categories']) && count($jsonData['categories']) > 0) {
                $parent = $child= false;
                $categoryId = $subCatgoryId = 0;
                foreach ($jsonData['categories'] as $category) {
                   $category = Category::find($category->id);
                    if ($category['parent'] == 0) {
                        $exitsCategory = (clone $woocommerceConectionQuery)->where('store_id',getCurrentStore())->where('module','=','category')->where('woocomerce_id' , $category['id'])->first();
                        if($exitsCategory) {
                            $parent = true;
                            $categoryId = $exitsCategory->original_id;
                        }                            
                    } 
                }
                if(!$parent) {
                    return redirect()->back()->with('error', __('Add Woocommerce Product Category.'));
                }
            } else {
                return redirect()->back()->with('error', __('Add Woocommerce Product Category.'));
            }

            // Create Product Attribute
            $attribute = $this->createProductAttribute($jsonData['attributes'], $jsonData['default_attributes']);
            
            if(!empty($jsonData['regular_price']) && !empty($jsonData['sale_price']) ){
                $discount_amount =$jsonData['regular_price'] - $jsonData['sale_price'];
            }
            else{
                $discount_amount = 0;
            }

            if(!empty($jsonData['images'][0]->src)) {
                $url = $jsonData['images'][0]->src;

                $file_type = config('files_types');

                foreach($file_type as $f){
                    $name = basename($url, ".".$f);
                }
                $file_size = 0;
                try{
                    $file_url = str_replace("\0", '', $url);
                    $get_file = \Illuminate\Support\Facades\Http::head($file_url);
                    $file_size = $get_file->header('Content-Length');
                } catch(\Exception $e)
                {
                    return redirect()->back()->with('error', $e);
                }
                $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $file_size);
                if ($result != 1) {
                    return redirect()->back()->with('error', $result);
                }
                $file2 = rand(10,100).'_'.time() . "_" . $name;
                $path = 'uploads/' . getCurrentStore();
                $uplaod = Utility::upload_woo_file($url,$file2,$path);
            }
            else{

                $url    = asset(Storage::url('uploads/woocommerce.png'));
                $name   = 'woocommerce.png';
                $file2  = rand(10,100).'_'.time() . "_" . $name;
                $path   = 'uploads/' . getCurrentStore();
                $uplaod = Utility::upload_woo_file($url,$file2,$path);

            }

            if (!empty($jsonData)) {
                $product                        = new \App\Models\Product();
                $product->name                  = $jsonData['name'];
                $product->slug                  = $jsonData['slug'];
                $product->description           = strip_tags($jsonData['description']);
                $product->specification           = $jsonData['short_description'];
                
                $product->cover_image_path      = $uplaod['url'];
                $product->cover_image_url       = $uplaod['full_url'];
                $product->category_id       = $categoryId;
                $product->variant_product       = (count($variations) > 0) ? 1 : 0;
                $product->product_stock         = !empty($jsonData['stock_quantity']) ? $jsonData['stock_quantity'] : 0;
                $product->slug                  = str_replace(' ','_', strtolower($jsonData['name']));
                $product->price                 = $jsonData['price'];
                $product->attribute_id          = $attribute['id'] ?? null;
                $product->store_id              = getCurrentStore();
                $product->created_by            = auth()->user()->id;
                $product->save();

                $products                    = new WoocommerceConection();
                $products->store_id          = getCurrentStore();
                $products->module            = 'product';
                $products->woocomerce_id     = $jsonData['id'];
                $products->original_id       = $product->id;
                $products->save();

                if(empty($jsonData['images'][1])){
                    $url  = asset(Storage::url('uploads/woocommerce.png'));
                    $name = 'woocommerce.png';
                    $file2 = rand(10,100).'_'.time() . "_" . $name;
                    $path = 'uploads/' . getCurrentStore();
                    $ulpaod =Utility::upload_woo_file($url,$file2,$path);

                    $ProductImage = new ProductImage();
                    $ProductImage->product_id = $product->id;
                   
                    $ProductImage->image_path = $ulpaod['url'];
                    $ProductImage->image_url  = $ulpaod['full_url'];
                    $ProductImage->store_id   = getCurrentStore();
                    $ProductImage->save();
                }else{
                    for ($i = 1; $i < count($jsonData['images']); $i++) {
                        $image = $jsonData['images'][$i];
                        $id = $image->id;
                        $dateCreated = $image->date_created;
                        $src = $image->src;

                        $url = $src;

                        $file_type = config('files_types');

                        foreach($file_type as $f){
                            $name = basename($url, ".".$f);
                        }
                        $file_size = 0;
                        try{
                            $file_url = str_replace("\0", '', $url);
                            $get_file = \Illuminate\Support\Facades\Http::head($file_url);
                            $file_size = $get_file->header('Content-Length');
                        } catch(\Exception $e)
                        {
                            return redirect()->back()->with('error', $e);
                        }
                        $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $file_size);
                        if ($result != 1) {
                            return redirect()->back()->with('error', $result);
                        }
                        $file2 = rand(10,100).'_'.time() . "_" . $name;
                        $path = 'uploads/' . getCurrentStore();
                        $subimg =Utility::upload_woo_file($url,$file2,$path);

                        $ProductImage = new ProductImage();
                        $ProductImage->product_id = $product->id;
                       
                        $ProductImage->image_path = $subimg['url'];
                        $ProductImage->image_url  = $subimg['full_url'];
                        $ProductImage->store_id   = getCurrentStore();
                        $ProductImage->save();
                    }

                }

                if (isset($attribute['variant']) && !empty($attribute['variant'])) {
                    // Create Product Variations
                    $this->createProductVariant($product, $variations, $attribute['variant'] ?? null);

                }
                return redirect()->back()->with('success', __('Product successfully Add.'));

            } else {
                return redirect()->back()->with('error', __('Product Not Found.'));
            }

        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        
        if(auth()->user() && auth()->user()->isAbleTo('Edit Woocommerce Product'))
        {
            $woocommerce_store_url = Utility::GetValueByName('woocommerce_store_url',getCurrentStore());
            $woocommerce_consumer_secret = Utility::GetValueByName('woocommerce_consumer_secret',getCurrentStore());
            $woocommerce_consumer_key = Utility::GetValueByName('woocommerce_consumer_key',getCurrentStore());

            config(['woocommerce.store_url' => $woocommerce_store_url]);
            config(['woocommerce.consumer_key' => $woocommerce_consumer_key]);
            config(['woocommerce.consumer_secret' => $woocommerce_consumer_secret]);
            $jsonData = Product::find($id);
            
            $variations = [];
            if (isset($jsonData['variations']) && count($jsonData['variations']) > 0) {
                
                foreach ($jsonData['variations'] as $variationId) {
                   $variations[] = Variation::find($jsonData['id'],$variationId);
                }
            }

            $woocommerceConectionQuery = WoocommerceConection::query();
            if (isset($jsonData['categories']) && count($jsonData['categories']) > 0) {
                $parent = $child= false;
                $categoryId = $subCatgoryId = 0;
                foreach ($jsonData['categories'] as $category) {
                   $category = Category::find($category->id);
                    if ($category['parent'] == 0) {
                        $exitsCategory = (clone $woocommerceConectionQuery)->where('store_id',getCurrentStore())->where('module','=','category')->where('woocomerce_id' , $category['id'])->first();
                        if($exitsCategory) {
                            $parent = true;
                            $categoryId = $exitsCategory->original_id;
                        }                            
                    }
                }
                if(!$parent) {
                    return redirect()->back()->with('error', __('Add Woocommerce Product Category.'));
                }
            } else {
                return redirect()->back()->with('error', __('Add Woocommerce Product Category.'));
            }

            // Create Product Attribute
            $attribute = $this->createProductAttribute($jsonData['attributes'], $jsonData['default_attributes']);
            
            if(!empty($jsonData['images'][0]->src)) {
                $url = $jsonData['images'][0]->src;
                $file_type = config('files_types');

                foreach($file_type as $f){
                    $name = basename($url, ".".$f);
                }
                $file_size = 0;
                try{
                    $file_url = str_replace("\0", '', $url);
                    $get_file = \Illuminate\Support\Facades\Http::head($file_url);
                    $file_size = $get_file->header('Content-Length');
                } catch(\Exception $e)
                {
                    return redirect()->back()->with('error', $e);
                }
                $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $file_size);
                if ($result != 1) {
                    return redirect()->back()->with('error', $result);
                }
                $file2 = rand(10,100).'_'.time() . "_" . $name;
                $path = 'uploads/' . getCurrentStore();
                $uplaod =Utility::upload_woo_file($url,$file2,$path);


            }
            $woocommerceProduct = (clone $woocommerceConectionQuery)->where('module', 'product')->where('woocomerce_id', $jsonData['id'])->first();
            $original_id = $woocommerceProduct->original_id;
            $product = \App\Models\Product::find($original_id);
            $discount_amount = (!empty($jsonData['regular_price']) ? $jsonData['regular_price'] : 0) - (!empty($jsonData['sale_price']) ? $jsonData['sale_price'] : 0);

           
            if (!empty($jsonData)) {
                $product->name                  = $jsonData['name'];
                $product->slug                  = $jsonData['slug'];
                $product->description           = strip_tags($jsonData['description']);
                $product->specification           = $jsonData['short_description'];
                
                $product->cover_image_path      = $uplaod['url'];
                $product->cover_image_url       = $uplaod['full_url'];
                $product->category_id       = $categoryId;
                $product->variant_product       = (count($variations) > 0) ? 1 : 0;
                $product->product_stock         = !empty($jsonData['stock_quantity']) ? $jsonData['stock_quantity'] : 0;
                $product->slug                  = str_replace(' ','_', strtolower($jsonData['name']));
                $product->price                 = $jsonData['price'];
                $product->attribute_id          = $attribute['id'] ?? null;
                $product->store_id              = getCurrentStore();
                $product->created_by            = auth()->user()->id;

                $product->save();

                if (isset($attribute['variant']) && !empty($attribute['variant'])) {
                    // Create Product Variations
                    $this->createProductVariant($product, $variations, $attribute['variant'] ?? null);
                }
                return redirect()->back()->with('success', __('Product successfully Updated.'));
            }
            else{
                return redirect()->back()->with('error', __('Product Not Found.'));

            }

        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }

    private function createProductVariant($product, $variants, $variant_name) {
        $default_variant_id = 0;
        $is_in_stock = false;
        $variantQuery = ProductVariant::query();
        foreach ($variants as $item) {
            $product_stock = [];
                $existVariant = (clone $variantQuery)->where('product_id', $product->id)->where('variant', $variant_name)->first();
                
                $product_stock['product_id'] = $product->id;
               
                $product_stock['variant'] = $variant_name;
                $product_stock['sku'] = $item['sku'];
                $product_stock['downloadable_product'] = $item['downloadable'] ?? null;
                $product_stock['variation_price'] = $item['regular_price'] ?? null;
                $product_stock['weight'] = $item['weight'] ?? null;
                if ($item['stock_status'] == 'instock' && $item['stock_status'] == 'in_stock') {
                    $status  = 'in_stock';
                }
                if ($item['stock_status'] == 'outofstock' || $item['stock_status'] == 'out_of_stock') {
                    $status  = 'out_of_stock';
                }
                $product_stock['stock_status'] = $status ?? null;
                $product_stock['price'] = $item['price'] ?? 0;
                $product_stock['low_stock_threshold'] = $item['low_stock_amount'] ?? null;
                $product_stock['description'] = $item['description'] ?? null;
                $product_stock['stock'] = $item['stock_quantity'] ?? 0;
                
                $product_stock['store_id'] = getCurrentStore();
               
                if (!$existVariant) {
                    $existVariant = (clone $variantQuery)->create($product_stock);
                } else {
                    $existVariant->update($product_stock);
                }
             
               
                if ($existVariant->stock_status == 'in_stock' || $existVariant->stock_status == 'instock') {
                    $is_in_stock = true;
                }
        }
        if (!$is_in_stock) {
            $product->stock_status = 'out_of_stock';
        } else {
            $product->stock_status = 'in_stock';
        }
        $product->save();
    }

    private function createProductAttribute($attributes, $defaultAttribute) {
        $attributeQuery  = ProductAttribute::query();
        $optionQuery = ProductAttributeOption::query();
        foreach ($attributes as $attribute) {
            $existAttribute = (clone $attributeQuery)->where('name', $attribute->name)->where('store_id', getCurrentStore())->first();
            if (!$existAttribute) {
                $newAttribute = (clone $attributeQuery)->create([
                    'name' => $attribute->name,
                    'store_id' => getCurrentStore(),
                ]);
                if (count($attribute->options) > 0) {
                    foreach ($attribute->options as $option) {
                        (clone $optionQuery)->create([
                            'attribute_id' => $newAttribute->id,
                            'terms' => $option,
                            'order' => 0,
                            'store_id' => getCurrentStore(),
                        ]);
                        
                    }
                }
            } else {
                if (count($attribute->options) > 0) {
                    foreach ($attribute->options as $key => $option) {
                        $existOption= (clone $optionQuery)->where('attribute_id', $existAttribute->id)->where('terms', $option)->where('store_id', getCurrentStore())->first();
                        if (!$existOption) {
                            (clone $optionQuery)->create([
                                'attribute_id' => $existAttribute->id,
                                'terms' => $option,
                                'order' => 0,
                                'store_id' => getCurrentStore(),
                            ]);
                        }                        
                    }
                }
            }
        }

        $return = [];
        foreach ($defaultAttribute as $default) {
            $existAttribute = (clone $attributeQuery)->where('name', $default->name)->where('store_id', getCurrentStore())->first();
            if ($existAttribute) {
                $existOption= (clone $optionQuery)->where('attribute_id', $existAttribute->id)->where('terms', $default->option)->where('store_id', getCurrentStore())->first();
                $return['id'] = $existAttribute->id;
                $return['variant'] = $default->option;
            }
        }
        return $return;
    }
}
