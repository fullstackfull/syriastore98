<?php

namespace Workdo\DigitalProduct\app\Http\Controllers;

use App\Models\Category;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductLabel;
use App\Models\Store;
use App\Models\Tag;
use App\Models\Tax;
use App\Models\User;
use App\Models\Utility;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Workdo\DigitalProduct\app\Models\DigitalProductImage;
use Illuminate\Support\Facades\File;
use Workdo\DigitalProduct\DataTables\DigitalProductData;

class DigitalProductController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index(Request $request, DigitalProductData $dataTable)
    {
        if (!auth()->user()) {
            return redirect()->route('login');
        }
        if (auth()->user()->isAbleTo('Manage Digital Product')) {
            $store_id = getStoreById(getCurrentStore());
            $products = Product::where('product_type', 'digital')->where('store_id', getCurrentStore())->get();
            $enable_digital_product = \App\Models\Utility::GetValueByName('enable_digital_product', $store_id->id);
            if (isset($enable_digital_product) && $enable_digital_product == 'on') {
                if ($request->id == 1) {
                    $msg = __('Product Successfully Created');
                    return $dataTable->render('digital-product::index', compact('products', 'msg'));
                } elseif ($request->id == 2) {
                    $msg = __('Product Successfully Updated');
                    return $dataTable->render('digital-product::index', compact('products', 'msg'));
                } else {
                    $msg = 0;
                    return $dataTable->render('digital-product::index', compact('products', 'msg'));
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
        $brands = ProductBrand::where('status', 1)->where('store_id', getCurrentStore())->pluck('name', 'id')->prepend('Select Brand', '');
        $labels = ProductLabel::where('status', 1)->where('store_id', getCurrentStore())->pluck('name', 'id')->prepend('Select Label', '');
        $tag = Tag::where('store_id', getCurrentStore())->get();
        $preview_type = [
            'Video File' => 'Video File',
            'Video Url' => 'Video Url',
            'iFrame' => 'iFrame'
        ];

        return view('digital-product::product.create', compact('link', 'Category', 'Tax', 'Tax_status', 'tag', 'brands', 'labels', 'preview_type'));
    }

    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return Renderable
     */
    public function store(Request $request)
    {
        if (auth()->user()->isAbleTo('Create Digital Product')) {
            try {
                $user = \Auth::user();
                $creator = User::find($user->creatorId());
                $total_products = $user->countProducts();
                $plan = Plan::find($creator->plan_id);
                $store_id = getStoreById(getCurrentStore());
    
                $rules = [
                    'name' => 'required',
                    'category_id' => 'required',
                    'cover_image' => 'required',
                    'price' => 'required',
                    'sale_price' => 'required',
                ];
    
                $validator = \Validator::make($request->all(), $rules);
                if ($validator->fails()) {
                    $messages = $validator->getMessageBag();
                    return redirect()->back()->with('error', $messages->first());
                }
    
                $totalImageSize = 0;
                if ($request->hasFile('cover_image')) {
                    $totalImageSize += $request->file('cover_image')->getSize();
                }
                if ($request->hasFile('preview_video')) {
                    $totalImageSize += $request->file('preview_video')->getSize();
                }
                if (!empty($request->sub_images)) {
                    foreach ($request->sub_images as $key => $image) {
                        $totalImageSize += File::size($image);
                    }
                }
    
                $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $totalImageSize);
                if ($result != 1) {
                    return redirect()->back()->with('error', $result);
                }
    
                $dir = 'uploads/' . getCurrentStore();
                if ($request->variant_product == 0) {
                    if ($total_products < $plan->max_products || $plan->max_products == -1) {
                        $input = $request->all();
    
                        $Product = new Product();
                        $Product->name = $request->name;
                        $Product->slug = !empty($request->slug) ? $request->slug : Product::slugs($request->name);
                        $Product->category_id = $request->category_id;
                        $Product->brand_id = $request->brand_id ?? null;
                        $Product->product_type = $request->product_type ?? null;
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
                            } else {
                                return redirect()->back()->with('error', __($result));
                            }
    
                            if ($path['flag'] == 0) {
                                return redirect()->back()->with('success', __($path['msg']));
                            } else {
                                $Product->cover_image_path = $path['url'] ?? null;
                                $Product->cover_image_url = $path['full_url'] ?? null;
                            }
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
                                    return redirect()->back()->with('error', __($path_video['msg']));
                                }
                            } else {
                                return redirect()->back()->with('error', __($result));
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
                        $Product->variant_product = 0;
                        $Product->trending = $request->trending;
                        $Product->status = $request->status;
                        $Product->description = $request->description;
                        $Product->specification = $request->specification;
                        $Product->detail = $request->detail;
                        $Product->price = $request->price;
                        $Product->sale_price = $request->sale_price;
    
                        if ($request->custom_field_status == '1') {
                            $Product->custom_field_status = '1';
                            $Product->custom_field = json_encode($request->custom_field_repeater_basic);
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
    
                        $Product->store_id = getCurrentStore();
                        $Product->created_by = \Auth::user()->id;
    
                        $Product->save();
    
                        if (module_is_active('ProductBarCode')) {
                            \Workdo\ProductBarCode\app\Models\ProductBarCode::saveData($Product);
                        }
    
                        if ($request->sub_images) {
                            foreach ($request->sub_images as $key => $image) {
                                if ($result == 1) {
                                    $fileName = rand(10, 100) . '_' . time() . "_" . $image->getClientOriginalName();
                                    $pathss = Utility::keyWiseUpload_file($request, 'sub_images', $fileName, $dir, $key, []);
                                } else {
                                    return redirect()->back()->with('success', __($result));
                                }
    
                                if (isset($pathss['url'])) {
                                    $ProductImage = new DigitalProductImage();
                                    $ProductImage->product_id = $Product->id;
                                    $ProductImage->image_path = $pathss['url'];
                                    $ProductImage->image_url = $pathss['full_url'];
                                    $ProductImage->store_id = getCurrentStore();
                                    $ProductImage->save();
                                }
                            }
                        }
                    } else {
                        return redirect()->back()->with('error', __('Your Product limit is over, Please upgrade plan'));
                    }
                }
    
                return redirect()->back()->with('success', __('Product created successfully.'));
            } catch (\Exception $e) {
                return redirect()->back()->with('error', __($e->getMessage()));
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
        return view('digital-product::show');
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
            $preview_type = [
                'Video File' => 'Video File',
                'Video Url' => 'Video Url',
                'iFrame' => 'iFrame'
            ];
            $product_image = DigitalProductImage::where('product_id', $product->id)->where('store_id', getCurrentStore())->get();
            $get_tax = explode(',', $product->tax_id);
            // $tag = Tag::where('store_id', getCurrentStore())->pluck('name', 'id');
             $tag = Tag::where('store_id', getCurrentStore())->get();
            $get_tags = explode(',', $product->tag_id);

            $brands = ProductBrand::where('status', 1)->where('store_id', getCurrentStore())->pluck('name', 'id')->prepend('Select Brand', '');
            $labels = ProductLabel::where('status', 1)->where('store_id', getCurrentStore())->pluck('name', 'id')->prepend('Select Label', '');

            $compact = ['link', 'product', 'Category', 'Tax', 'Tax_status', 'preview_type', 'product_image', 'get_tax', 'tag', 'get_tags', 'brands', 'labels'];

            return view('digital-product::product.edit', compact($compact));
        } else {
            return redirect()->back()->with('error', __('The Product is not found'));
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
        if ($product) {
            if (auth()->user()->isAbleTo('Edit Digital Product')) {
                $dir = 'uploads/' . getCurrentStore();

                $rules = [
                    'name' => 'required',
                    'category_id' => 'required',
                    'status' => 'required',
                    'brand_id' => 'nullable',
                    'label_id' => 'nullable',
                ];
                $validator = \Validator::make($request->all(), $rules);
                if ($validator->fails()) {
                    $messages = $validator->getMessageBag();
                    return redirect()->back()->with('error', $messages->first());
                }

                $totalImageSize = 0;
                if ($request->hasFile('cover_image')) {
                    $totalImageSize += $request->file('cover_image')->getSize();
                }
                if ($request->hasFile('preview_video')) {
                    $totalImageSize += $request->file('preview_video')->getSize();
                }
                if (!empty($request->sub_images)) {
                    foreach ($request->sub_images as $key => $image) {
                        $totalImageSize += File::size($image);
                    }
                }
                $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $totalImageSize);
                if ($result != 1) {
                    return redirect()->back()->with('error', $result);
                }

                if ($request->cover_image) {
                    $file_path = $product->cover_image_path;

                    if ($result == 1) {
                        Utility::changeStorageLimit(\Auth::user()->creatorId(), $file_path);

                        $fileName = rand(10, 100) . '_' . time() . "_" . $request->cover_image->getClientOriginalName();
                        $path = Utility::upload_file($request, 'cover_image', $fileName, $dir, []);
                        if (File::exists(base_path($product->cover_image_path))) {
                            File::delete(base_path($product->cover_image_path));
                        }
                    } else {
                        return redirect()->back()->with('error', __($result));
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

                    $dir_video = 'uploads/' . getCurrentStore() . '/preview_image';

                    $file_paths = $product->preview_video;
                    if ($result == 1) {
                        Utility::changeStorageLimit(\Auth::user()->creatorId(), $file_paths);
                        $path_video = Utility::upload_file($request, 'preview_video', $fileName, $dir_video, []);
                        if ($path_video['flag'] == 1) {
                            $url = $path_video['url'];
                        } else {
                            return redirect()->back()->with('error', __($path_video['msg'] ?? ''));
                        }
                    } else {
                        return redirect()->back()->with('error', __($result['msg'] ?? ''));
                    }

                    $product->preview_content = $path_video['url'];
                }

                if (!empty($request->preview_iframe)) {
                    $product->preview_content = $request->preview_iframe;
                }
                $product->variant_product = 0;
                $product->status = $request->status;
                $product->trending = $request->trending;


                if ($request->custom_field_status == '1') {
                    $product->custom_field_status = '1';
                    $product->custom_field = json_encode($request->custom_field_repeater_basic);
                } else {
                    $product->custom_field = NULL;
                }

                if (!empty($request->sub_images)) {
                    foreach ($request->sub_images as $key => $image) {
                        $theme_image = $image;
                        
                        if ($result == 1) {
                            $fileName = rand(10, 100) . '_' . time() . "_" . $image->getClientOriginalName();
                            $pathss = Utility::keyWiseUpload_file($request, 'sub_images', $fileName, $dir, $key, []);
                        } else {
                            return redirect()->back()->with('error', __($result['msg'] ?? ''));
                        }
                        if (isset($pathss['url'])) {
                            $ProductImage = new DigitalProductImage();
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
                    $input = $request->all();
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
                }
                if (module_is_active('ProductBarCode')) {
                    \Workdo\ProductBarCode\app\Models\ProductBarCode::saveData($product);
                }
                return redirect()->back()->with('success', __('Product Update Successfully.'));
            } else {
                return redirect()->back()->with('error', __('Permission denied.'));
            }
        } else {
            return redirect()->back()->with('error', __('The Product is not found'));
        }
    }

    /**
     * Remove the specified resource from storage.
     * @param int $id
     * @return Renderable
     */
    public function destroy($id)
    {
        if (auth()->user()->isAbleTo('Delete Digital Product')) {
            $product = Product::find($id);
            if ($product) {
                $ProductImages = DigitalProductImage::where('product_id', $product->id)->get();

                $Product = Product::find($product->id);
                $file_path1 = [];
                foreach ($ProductImages as $key => $ProductImage) {
                    $file_path1[] = $ProductImage->image_path;
                }
                $file_paths2[] = $Product->cover_image_path;
                $file_path = array_merge($file_path1, $file_paths2);
                Utility::changeproductStorageLimit(\Auth::user()->creatorId(), $file_path, $file_path1);
                if (!empty($ProductImages)) {
                    // image remove from product variant image
                    foreach ($ProductImages as $key => $ProductImage) {
                        if (File::exists(base_path($ProductImage->image_path))) {
                            File::delete(base_path($ProductImage->image_path));
                        }
                    }
                }

                DigitalProductImage::where('product_id', $product->id)->delete();

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
                return redirect()->back()->with('success', __('Product delete successfully.'));
            } else {
                return redirect()->back()->with('error', __('The Product is not found'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function file_delete($id)
    {
        $product_img_id = DigitalProductImage::find($id);
        if (File::exists(base_path($product_img_id->image_path))) {
            File::delete(base_path($product_img_id->image_path));
        }
        $product_img_id->delete();
        $msg['flag'] = 'success';
        $msg['msg'] = __('Image delete Successfully');
        return $msg;
    }
}
