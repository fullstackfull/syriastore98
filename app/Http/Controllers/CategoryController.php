<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\Category;
use App\Models\Utility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Auth;
use App\Models\ShopifyConection;
use App\Models\WoocommerceConection;
use App\DataTables\CategoryDataTable;
use App\Models\Product;
use Illuminate\Support\Facades\Storage;
class CategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */

    public function index(CategoryDataTable $dataTable)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Product Category'))
        {
            return  $dataTable->render('category.index');
        }else{
            return redirect()->back()->with('error',__('Permission Denied.'));
        }
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $categories = Category::where('store_id',getCurrentStore())->get();
        $categoryTree = buildCategoryTree($categories);
        return view('category.create', compact('categoryTree'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        if(auth()->user() && auth()->user()->isAbleTo('Create Product Category'))
        {
            $validator = \Validator::make(
                $request->all(), [
                                   'name' => 'required',
                                ]
            );

            if($validator->fails())
            {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }

            $dir        = 'uploads/' . getCurrentStore();
            $totalImageSize = 0;
            if ($request->hasFile('image')) {
                $totalImageSize += $request->file('image')->getSize();
            }
            if ($request->hasFile('icon_image')) {
                $totalImageSize += $request->file('icon_image')->getSize();
            }
            $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $totalImageSize);
            if ($result != 1) {
                return redirect()->back()->with('error', $result);
            }
            if($request->image) {
                if ($result == 1)
                {
                    $fileName = rand(10,100).'_'.time() . "_" . $request->image->getClientOriginalName();
                    $path = Utility::upload_file($request,'image',$fileName,$dir,[]);
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
                $path['full_url'] = asset(Storage::url('uploads/default.jpg'));
                $path['url'] = Storage::url('uploads/default.jpg');
            }

            if($request->icon_image) {
                if ($result == 1)
                {
                    $fileName = rand(10,100).'_'.time() . "_" . $request->icon_image->getClientOriginalName();
                    $paths = Utility::upload_file($request,'icon_image',$fileName,$dir,[]);
                    if ($paths['flag'] == 1) {
                        $url = $paths['url'];
                    } else {
                        return redirect()->back()->with('error', __($paths['msg']));
                    }
                }
                else{
                    return redirect()->back()->with('error', $result);
                }
            }else{
                $paths['url'] = Storage::url('uploads/default.jpg');
            }

            $Category = new Category();
            $Category->name         = $request->name;
            $Category->slug         = 'collections/' . strtolower(preg_replace("/[^\w]+/", "-", $request->name));
            $Category->image_url    = $path['full_url'];
            $Category->image_path   = $path['url'];
            $Category->icon_path    = $paths['url'];
            $Category->parent_id    = $request->parent_id ?? 0;
            $Category->trending     = $request->trending;
            $Category->status       = $request->status;
            $Category->store_id     = getCurrentStore();

            $Category->save();

            return redirect()->back()->with('success', __('Category successfully created.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Category  $category
     * @return \Illuminate\Http\Response
     */
    public function show(Category $category)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Category  $category
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $query = Category::where('store_id',getCurrentStore());
        $category = (clone $query)->find($id);
        if (!$category) {
            return response()->json(['status' => 'error', 'message' => __('Category Not Found!')]);
        }
        $categories = $query->get();
        $categoryTree = buildCategoryTree($categories);
        return view('category.edit', compact('category', 'categoryTree'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Category  $category
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Category $category)
    {
        if(auth()->user() && auth()->user()->isAbleTo('Edit Product Category'))
        {
            $validator = \Validator::make(
                $request->all(), [
                                   'name' => 'required',
                               ]
            );
            if($validator->fails())
            {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }
            $dir        = 'uploads/' . getCurrentStore();

            $Category = $category;
            $Category->name = $request->name;

            $totalImageSize = 0;
            if ($request->hasFile('image')) {
                $totalImageSize += $request->file('image')->getSize();
            }
            if ($request->hasFile('icon_image')) {
                $totalImageSize += $request->file('icon_image')->getSize();
            }
            $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $totalImageSize);
            if ($result != 1) {
                return redirect()->back()->with('error', $result);
            }
            if(!empty($request->image)) {
                $file_path =  $category->image_path;
                
                if ($result == 1)
                {
                    if (!empty($file_path) && $file_path != '/storage/uploads/default.jpg' && \File::exists(base_path($file_path))) {
                        Utility::changeStorageLimit(\Auth::user()->creatorId(), $file_path);
                    }

                    $fileName = rand(10,100).'_'.time() . "_" . $request->image->getClientOriginalName();
                    $path = Utility::upload_file($request,'image',$fileName,$dir,[]);
                    if ($path['flag'] == 1) {
                        $Category->image_url    = $path['full_url'];
                        $Category->image_path   = $path['url'];
                    } else {
                        return redirect()->back()->with('error', __($path['msg']));
                    }
                }
                else{
                    return redirect()->back()->with('error', $result);
                }
            }else{
                $path['full_url'] = asset(Storage::url('uploads/default.jpg'));
                $path['url'] = Storage::url('uploads/default.jpg');
            }
            if (!empty($request->icon_image)) {
                $file_path = $category->icon_path;

                if ($result == 1) {
                    if (!empty($file_path) && $file_path != '/storage/uploads/default.jpg' && \File::exists(base_path($file_path))) {
                        Utility::changeStorageLimit(\Auth::user()->creatorId(), $file_path);
                    }

                    $fileName = rand(10, 100) . '_' . time() . "_" . $request->icon_image->getClientOriginalName();
                    $paths = Utility::upload_file($request, 'icon_image', $fileName, $dir, []);
                    if ($paths['flag'] == 1) {
                        $category->icon_path = $paths['url'];
                    } else {
                        return redirect()->back()->with('error', __($paths['msg']));
                    }
                } else {
                    return redirect()->back()->with('error', $result);
                }
            }else{
                $paths['url'] = Storage::url('uploads/default.jpg');
            }

            $Category->slug         =  'collections/' . strtolower(preg_replace("/[^\w]+/", "-", $request->name));
            $Category->parent_id    = $request->parent_id ?? 0;
            $Category->trending     = $request->trending;
            $Category->status       = $request->status;
            $Category->save();

            return redirect()->back()->with('success', __('Category successfully updated.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Category  $category
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {

        if(auth()->user() && auth()->user()->isAbleTo('Delete Product Category'))
        {
            $mainCategory = Category::find($id);
            $category = $mainCategory;
            if(!empty($category)) {
                $hasChild = Category::where('parent_id', $category->id)->where('store_id', getCurrentStore())->exists();
                if ($hasChild) {
                    return redirect()->back()->with('error', __('This category has child categories and cannot be deleted.'));
                }
                
                Category::categoryImageDelete($category);

                $products = $mainCategory->product_details;
                foreach ($products as $product) {
                    Product::productImageDelete($product);
                }

                WoocommerceConection::where('module', 'category')->where('original_id', $category->id)->delete();

                ShopifyConection::where('module', 'category')->where('original_id', $category->id)->delete();

                $category->delete();
            }
            return redirect()->back()->with('success', __('Category delete successfully.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function getProductCategories()
    {
        $store_id = Store::where('id', getCurrentStore())->first();
        $productCategory = Category::where('store_id',getCurrentStore())->get();
        $html = '<div class="col-xxl-2 col-lg-3  col-sm-4 zoom-in ">
                    <div class="cat-active overflow-hidden" data-id="0">
                    <div class="category-select h-100" data-cat-id="0">
                        <button type="button" class="btn h-100 w-100 btn-primary btn-sm active pos-product-text">'.__("All Categories").'</button>
                    </div>
                    </div>
                </div>';
        foreach($productCategory as $key => $cat){
            $dcls = 'category-select';
            $html .= ' <div class="col-xxl-2 col-lg-3  col-sm-4 zoom-in cat-list-btn">
            <div class="overflow-hidden" data-id="'.$cat->id.'">
               <div class="h-100 '.$dcls.'" data-cat-id="'.$cat->id.'">
                  <button type="button" class="btn h-100 w-100  btn-sm pos-product-text">'.$cat->name.'</button>
               </div>
            </div>
         </div>';

        }
        return response()->json(
            [
                'code' => 200,
                'status' => 'Success',
                'success' => __('Product get successfully!'),
                'html' => $html,
            ]
        );
        //return Response($html);
    }
}
