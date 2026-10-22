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

            // P1-INV-11. The parent is validated BEFORE any upload or write.
            $parentId = $this->resolveParentId($request->input('parent_id'), null);
            if ($parentId === null) {
                return redirect()->back()->with('error', __('The parent category is not valid.'));
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
            $Category->parent_id    = $parentId;
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
            // P1-INV-11. The parent is validated BEFORE any upload or write.
            $parentId = $this->resolveParentId($request->input('parent_id'), $category);
            if ($parentId === null) {
                return redirect()->back()->with('error', __('The parent category is not valid.'));
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
            $Category->parent_id    = $parentId;
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
     * The parent a request may give a category, or null when the request's value is not acceptable. P1-INV-11.
     *
     * 0 / absent / empty means top level. Anything else must be a plain positive integer (a string such as '5abc' is read as 5 by MySQL in a
     * comparison, so it is refused here rather than coerced) naming a category in the caller's OWN store - the same predicate as
     * categoryIsOutsideCurrentStore(), so the platform operator is handled as in P1-INV-08 - and, on an update, neither the category itself
     * nor one of its descendants (either would store a cycle, and buildCategoryTree() would recurse on it).
     */
    private function resolveParentId($value, $self): ?int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return 0;
        }

        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || ! ctype_digit($value) || strlen($value) > 18) {
            return null;
        }

        $parentId = (int) $value;

        if ($parentId === 0) {
            return 0;
        }

        $parent = Category::find($parentId);

        if ($parent === null || $this->categoryIsOutsideCurrentStore($parent)) {
            return null;
        }

        if ($self !== null) {
            // walk UP from the proposed parent: reaching the category itself means the parent is the category or one of its descendants
            $seen = [];
            $cursor = $parent;

            while ($cursor !== null && ! isset($seen[$cursor->id])) {
                if ((int) $cursor->id === (int) $self->id) {
                    return null;
                }

                $seen[$cursor->id] = true;
                $cursor = empty($cursor->parent_id) ? null : Category::find($cursor->parent_id);
            }
        }

        return $parentId;
    }

    /**
     * Does this category belong to someone other than the signed-in merchant? P1-INV-08.
     *
     * `Category` DOES carry `BelongsToStore` (`app/Models/Category.php:14`) but is ABSENT from
     * `config/tenancy.php`'s `scoped_models`, and absence from the allowlist is the half that
     * decides: `StoreScope::isActiveFor()` short-circuits for a model that is not listed, so the
     * trait adds no predicate for any principal and `Category::find($id)` reaches every tenant.
     * CLAUDE.md insists on both halves of that check for exactly this reason -- an earlier draft
     * of this docblock said the model carries no trait, which is false and would have sent the
     * next reader to a model that contradicts the comment.
     *
     * The shape is `ProductController`'s, deliberately: platform operator
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

        /*
         * Exact-case, matching `ProductController`'s predicate rather than
         * `StoreController::resolveOwnedStore`, which normalises with `strtolower(trim(...))`.
         * Kept exact here so the two catalogue controllers read identically, and it FAILS SAFE:
         * a hypothetical `'Super Admin'` row is treated as a merchant and refused, never
         * exempted. Named so the next copier inherits the choice knowingly, not silently.
         */
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
                 * The `Delete Product Category` permission answers *may this user delete
                 * categories* and never *may this user delete THIS category*. With no predicate
                 * here, a merchant posting another store's id reached
                 * `Product::productImageDelete()` for every product in that category, which
                 * UNLINKS FROM DISK every product image, the cover image, the downloadable
                 * product and the preview video, and deletes all `product_images` and all
                 * `product_variants` rows -- the stock with them. Disk unlinking is not
                 * recoverable from the application, which is why this check sits above
                 * `categoryImageDelete()`, the first call that touches a file.
                 *
                 * THE PERMISSION NAME IS WRITTEN WITHOUT ITS CALL SYNTAX ON PURPOSE.
                 * BulkDeleteAuthorizationTest greps the first 1200 bytes after `function
                 * destroy` for the literal permission check, and the first draft of this comment
                 * quoted it verbatim -- which MEASURABLY DEGRADED that ratchet: with the real
                 * check at :333 deleted, the suite still passed 23/23 on the comment alone.
                 * Do not quote a permission check inside this window.
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
                
                /*
                 * OWNER DECISION D13 -- A CATEGORY WITH PRODUCTS IN IT IS NOT DELETABLE. P1-INV-12.
                 *
                 * `products.category_id` carries `onDelete('cascade')` to `categories.id`
                 * (2023_12_26_092822_create_products_table.php:55), so `$category->delete()` below
                 * removes every product in the category IN THE DATABASE, beneath the application:
                 * no model event, no log line, no confirmation. The merchant asked to delete a
                 * label and lost a catalogue.
                 *
                 * WHY THE REFUSAL SITS HERE AND NOT ABOVE `$category->delete()`. By the time
                 * control reaches that call the damage is already done and is NOT recoverable from
                 * the application: `categoryImageDelete()` unlinks the category icon and cover,
                 * and the loop below calls `Product::productImageDelete()` for every product,
                 * which unlinks each product's images, cover, downloadable file and preview video
                 * FROM DISK and deletes its `product_images` and `product_variants` rows. So the
                 * guard must precede the first call that touches a file.
                 *
                 * ASKED OF THE TABLE, NOT OF THE RELATION. Three deliberate differences from the
                 * product loop twenty lines down:
                 *
                 *   - NO STORE PREDICATE. The loop narrows to `$category->store_id` because it is
                 *     choosing what to unlink, and narrowing is the safe direction for an
                 *     irreversible disk write. This guard is choosing whether to proceed at all,
                 *     and the FOREIGN KEY does not read `store_id` -- a `products` row carrying
                 *     this `category_id` and some other store's `store_id` is cascade-deleted just
                 *     the same. Widening is the safe direction for a refusal.
                 *
                 *   - RAW QUERY. `Product` is `Cachable`, and a stale count here would read zero
                 *     against a populated category and wave the deletion through. The cache cannot
                 *     make this guard refuse a delete it should allow, only allow one it should
                 *     refuse, so the raw count is the conservative read.
                 *
                 *   - NOT A PERMISSION CHECK, so it applies to the platform operator too. D13 is a
                 *     data-integrity rule: cascading a catalogue away is wrong whoever asked for
                 *     it, and an operator has no more need of it than a merchant.
                 *
                 * REMAINING WORK, recorded rather than implied: D13 also calls for "a safe
                 * reassignment option before deletion". There is no bulk re-category UI yet, so
                 * today the merchant moves the products one at a time from the product edit form.
                 * The message says so instead of leaving them at a dead end. See P1-INV-12.
                 *
                 * TWO MORE TABLES CASCADE OFF `categories`, and NEITHER is guarded here.
                 *
                 *   - `testimonials.category_id`
                 *     (2023_12_26_095726_create_testimonials_table.php:27)
                 *   - `product_reviews.category_id`, where the ProductReview addon is installed
                 *     (packages/workdo/ProductReview/.../create_product_reviews_table.php:25)
                 *
                 * The second one was found by the independent review of this change and is worse
                 * than the first, because THIS REFUSAL'S OWN ADVICE TRIGGERS IT.
                 * `product_reviews.category_id` is a denormalised COPY of the product's category,
                 * written once from the review form and never re-pointed when the product moves
                 * (verified: all seven writes in that module are review-form writes, and no
                 * product-side path touches it). So: the merchant is refused, told to move the
                 * products to another category, does so, the count here falls to zero, the delete
                 * is permitted -- and the reviews cascade away below the application. Exactly the
                 * loss D13 exists to prevent, on a table it does not name.
                 *
                 * DELIBERATELY NOT GUARDED, for the same reason testimonials is not: a stale
                 * review row pointing at this category is one the merchant cannot see or clear
                 * from any screen, so refusing on it would make the category permanently
                 * undeletable. Widening a refusal onto data the user cannot reach is not a
                 * control, it is a dead end. Recorded as its own task instead -- the real fix is
                 * either to re-point the copy when a product moves or to change the constraint,
                 * and both live in vendored module territory or in a migration (D15).
                 */
                $productsInCategory = \Illuminate\Support\Facades\DB::table('products')
                    ->where('category_id', $category->id)
                    ->count();

                if ($productsInCategory > 0) {
                    \Illuminate\Support\Facades\Log::warning(
                        'category delete refused: products would be cascade-deleted with it',
                        [
                            'category_id' => $category->id,
                            'category_store_id' => $category->store_id ?? null,
                            'products_in_category' => $productsInCategory,
                            'user_id' => optional(auth()->user())->id,
                        ]
                    );

                    return redirect()->back()->with(
                        'error',
                        __('This category still contains :count product(s) and cannot be deleted. Move those products to another category first.', ['count' => $productsInCategory])
                    );
                }

                Category::categoryImageDelete($category);

                /*
                 * THE PRODUCT LOOP CARRIES ITS OWN PREDICATE. P1-INV-08, raised by review.
                 *
                 * `product_details` is `hasMany(Product::class, 'category_id')` on a model
                 * `config/tenancy.php` does not allowlist, so it filters by category only. After
                 * the guard above the CATEGORY is the caller's; a `products` row with
                 * `store_id = B` and `category_id = <the caller's category>` would still have its
                 * media unlinked from disk by an entirely legitimate delete.
                 *
                 * LATENT, NOT A HOLE -- stated precisely because the distinction matters: every
                 * product write path checked sets `store_id` from a resolved store or carries the
                 * P1-INV-01/07 ownership guard. The one path that can re-tenant a product is
                 * `WoocomProductController::edit`, which resolves `WoocommerceConection` with no
                 * store predicate and rewrites a foreign product's `store_id` to the caller's --
                 * an OPEN task (P1-INV-04, #30). This predicate is one line and removes the
                 * dependency on that fix landing correctly, which is worth more than the
                 * dependency.
                 *
                 * A NULL-store product sharing the category is deliberately NOT cleaned up here:
                 * narrowing is the safe direction for an irreversible disk operation, and such a
                 * row is already an anomaly rather than something a merchant created.
                 *
                 * THIS LOOP IS NOW UNREACHABLE-BY-DESIGN, AND IS KEPT ON PURPOSE. The D13 guard
                 * above refuses whenever ANY `products` row carries this `category_id`, so by the
                 * time control arrives here the set is always empty. It is retained as the last
                 * line of defence for an irreversible disk operation: if the guard is ever
                 * narrowed, moved or removed, this predicate is what still keeps another store's
                 * media off the unlink path. The cost is one query returning no rows. A removal
                 * check for the guard's own width is in MerchantCategoryOwnershipTest
                 * (`test_a_foreign_product_in_the_callers_category_blocks_the_delete`).
                 */
                $products = $mainCategory->product_details()
                    ->where('store_id', $category->store_id)
                    ->get();
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
