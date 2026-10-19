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
            /*
             * OWNERSHIP ON A ROUTE-MODEL-BOUND CATEGORY. P1-INV-08.
             *
             * The same shape P1-INV-01 closed on `ProductController::update`: a bound model on a
             * class no scope filters, with a permission check standing in for an ownership check.
             * Against another store's category this method rewrote `name`, `slug`, `parent_id`
             * (so a foreign category could be reparented under one of the caller's own),
             * `trending` and `status`; and on an image or icon upload it called
             * `Utility::changeStorageLimit()`, which UNLINKS the victim's existing image from
             * disk and debits the CALLER's storage quota, then wrote the replacement into
             * `uploads/' . getCurrentStore()` -- the caller's own media tree.
             *
             * Placed above the validator so a cross-store attempt cannot be distinguished from a
             * refusal by which error comes back, and above every write and every unlink.
             */
            if ($this->categoryIsOutsideCurrentStore($category)) {
                \Illuminate\Support\Facades\Log::warning(
                    'category update refused: the category belongs to another store',
                    [
                        'category_id' => $category->id,
                        'category_store_id' => $category->store_id ?? null,
                        'current_store' => getCurrentStore(),
                        'user_id' => optional(auth()->user())->id,
                    ]
                );

                return redirect()->back()->with('error', __('Permission denied.'));
            }

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
     * Does this category belong to someone other than the signed-in merchant? P1-INV-08.
     *
     * `Category` is absent from `config/tenancy.php`'s `scoped_models` and carries no
     * `BelongsToStore`, so `Category::find($id)` reaches every tenant and nothing in this
     * controller narrowed it. The shape is `ProductController`'s, deliberately: platform operator
     * exempt by `users.type`, then `getCurrentStore()` against the row's own `store_id`. Per
     * CLAUDE.md there is no super-admin bypass in Laratrust, so the operator has to be named
     * explicitly or this guard would lock them out of every store's categories.
     *
     * An unresolved tenant is treated as OUTSIDE. `StoreScope` fails open on a null tenant by
     * design; this predicate must not inherit that, because the whole point of it is to be the
     * thing that holds when the scope adds nothing.
     */
    private function categoryIsOutsideCurrentStore($category): bool
    {
        $actor = auth()->user();

        if ($actor !== null && $actor->type === \App\Policies\UserPolicy::PLATFORM_ADMIN) {
            return false;
        }

        $currentStore = getCurrentStore();

        return empty($category)
            || empty($currentStore)
            || empty($category->store_id)
            || (int) $category->store_id !== (int) $currentStore;
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {

        if(auth()->user() && auth()->user()->isAbleTo('Delete Product Category'))
        {
            $mainCategory = Category::find($id);
            $category = $mainCategory;
            if(!empty($category)) {
                /*
                 * OWNERSHIP BEFORE THE FIRST DESTRUCTIVE CALL. P1-INV-08.
                 *
                 * `isAbleTo('Delete Product Category')` answers *may this user delete
                 * categories* and never *may this user delete THIS category*. With no predicate
                 * here, a merchant posting another store's id reached
                 * `Product::productImageDelete()` for every product in that category, which
                 * UNLINKS FROM DISK every product image, the cover image, the downloadable
                 * product and the preview video, and deletes all `product_images` and all
                 * `product_variants` rows -- the stock with them. Disk unlinking is not
                 * recoverable from the application, which is why this check sits above
                 * `categoryImageDelete()`, the first call that touches a file.
                 */
                if ($this->categoryIsOutsideCurrentStore($category)) {
                    \Illuminate\Support\Facades\Log::warning(
                        'category delete refused: the category belongs to another store',
                        [
                            'category_id' => $category->id,
                            'category_store_id' => $category->store_id ?? null,
                            'current_store' => getCurrentStore(),
                            'user_id' => optional(auth()->user())->id,
                        ]
                    );

                    return redirect()->back()->with('error', __('Permission denied.'));
                }

                /*
                 * THE CHILD GUARD WAS ASKING THE WRONG STORE. P1-INV-08.
                 *
                 * This was the only `getCurrentStore()` in the method, and against a foreign
                 * category it made the attack MORE likely to proceed: it asked whether any child
                 * of the VICTIM's category belonged to the ATTACKER's store, which is false even
                 * when the victim's category does have children -- so the guard waved the
                 * deletion through and orphaned them. Scoped to the category's OWN store it asks
                 * the question it was written to ask. For an own-store category the two are the
                 * same value, so a merchant sees no change in behaviour.
                 */
                $hasChild = Category::where('parent_id', $category->id)->where('store_id', $category->store_id)->exists();
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
