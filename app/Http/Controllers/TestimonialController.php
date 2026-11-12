<?php

namespace App\Http\Controllers;

use App\Models\Store;
use Illuminate\Support\Facades\Auth;
use App\Models\Category;
use App\Models\Product;
use App\Models\Testimonial;
use App\Models\User;
use App\Models\Utility;
use Illuminate\Http\Request;
use App\DataTables\TestimonialDataTable;

class TestimonialController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */

    public function index(TestimonialDataTable $dataTable)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Testimonial'))
        {
            return  $dataTable->render('testimonial.index');
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
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
        return view('testimonial.create', compact( 'categoryTree'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Create Testimonial'))
        {
            $validator = \Validator::make(
                $request->all(), [
                    //'category_id' => 'required',
                    //'product_id' => 'required',
                    'rating_no' => 'required',
                    'title' => 'required',
                    'description' => 'required',
                    'status' => 'in:0,1',
                ]
            );
            if($validator->fails())
            {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', implode(' ', $messages->all()));
            }

            $Testimonial = new Testimonial();

            $dir        = 'uploads/' . getCurrentStore() . '/testimonial';
            $totalImageSize = 0;
            if ($request->hasFile('avatar')) {
                $totalImageSize += $request->file('avatar')->getSize();
            }
            
            $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $totalImageSize);
            if ($result != 1) {
                return redirect()->back()->with('error', $result);
            }
            if ($request->avatar) {
                if ($result == 1) {
                    $fileName = rand(10,100).'_'.time() . "_" . $request->avatar->getClientOriginalName();
                    $path = Utility::upload_file($request, 'avatar', $fileName, $dir, []);
                    if ($path['flag'] == 1) {
                        $Testimonial->avatar = $path['url'];
                    } else {
                        return redirect()->back()->with('error', __($path['msg']));
                    }
                } else {
                    return redirect()->back()->with('error', $result);
                }
            }

            $Testimonial->category_id = $request->category_id;
            $Testimonial->product_id = $request->product_id;
            $Testimonial->rating_no = $request->rating_no;
            $Testimonial->title = $request->title;
            $Testimonial->description = $request->description;
            $Testimonial->status = $request->status;
            $Testimonial->username = $request->username ?? null;
            $Testimonial->store_id = getCurrentStore();
            $Testimonial->save();

            // Testimonial::AvregeRating($request->product_id);

            return redirect()->back()->with('success', __('Testimonial create successfully.'));

        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Testimonial  $Testimonial
     * @return \Illuminate\Http\Response
     */
    public function show(Testimonial $Testimonial)
    {
        // P2.5-PKG-B. AN EMPTY BODY IS STILL AN EXISTENCE ORACLE: route model binding resolves
        // the row BEFORE this method runs and `Testimonial` is absent from `scoped_models`, so the
        // response was an empty 200 for a row in ANY store and 404 only for one existing nowhere.
        // Eleventh instance of a shape closed five times in F-SEC-02, once in F-SEC-05 and three
        // times in F-SEC-07; `OwnedResourceMethodsAreGuardedTest` now makes a twelfth fail CI.
        if (! $this->callerOwnsTestimonial($Testimonial)) {
            abort(404);
        }
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Testimonial  $Testimonial
     * @return \Illuminate\Http\Response
     */
    /**
     * P2.5-PKG-B. Does the caller own this testimonial?
     *
     * `Testimonial` uses only `HasFactory, Cachable` -- no `BelongsToStore`, no `StoreScope` --
     * and is deliberately absent from `config/tenancy.php`'s `scoped_models`
     * (`tests/Feature/Tenancy/ResidualTenancyExposureTest` records that choice). So
     * `Route::resource('testimonial')`'s implicit binding resolves ANY row in the table by bare
     * primary key.
     *
     * MEASURED in `TestimonialProductLookupCrossTenantTest` before this guard existed: a merchant
     * opened, rewrote and deleted another store's testimonial. The index DataTable IS scoped
     * (`TestimonialDataTable:80`), so ids are not listed to an attacker -- but they are small
     * sequential integers, which is the same enumeration argument the `category_id` leak rests on.
     *
     * `destroy()` was the sharpest: it called
     * `Utility::changeStorageLimit(\Auth::user()->creatorId(), $Testimonial->avatar)` BEFORE the
     * delete, so deleting a victim's testimonial debited the ATTACKER'S OWN storage quota for the
     * victim's file.
     *
     * Fails closed when either side is absent. No operator limb, as on
     * `ProductBrandController::callerOwnsBrand()` -- and note `getCurrentStore()` returns the
     * literal 1 for a super admin with an empty `current_store` (app/Helper/helper.php:775), so an
     * operator is scoped to store 1 here rather than refused.
     */
    private function callerOwnsTestimonial(?Testimonial $testimonial): bool
    {
        $user = auth()->user();

        if ($user === null || $testimonial === null) {
            return false;
        }

        $currentStore = getCurrentStore($user->id);

        return ! empty($currentStore)
            && ! empty($testimonial->store_id)
            && (int) $testimonial->store_id === (int) $currentStore;
    }

    public function edit(Testimonial $Testimonial)
    {
        // P2.5-PKG-B. This read had no permission check AND no ownership check, so any
        // authenticated principal could open another store's testimonial. 404 for foreign-or-absent
        // so the two are indistinguishable and the response is not an existence oracle.
        if (! $this->callerOwnsTestimonial($Testimonial)) {
            abort(404);
        }

        $categories = Category::where('store_id', getCurrentStore())->get();
        $categoryTree = buildCategoryTree($categories);
        $product = Product::where('category_id',$Testimonial->category_id)->where('store_id',getCurrentStore())->pluck('name', 'id')->prepend('Select Product', '');
        return view('testimonial.edit', compact( 'categoryTree', 'Testimonial', 'product'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Testimonial  $Testimonial
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Testimonial $Testimonial)
    {

        if (auth()->user() && auth()->user()->isAbleTo('Edit Testimonial'))
        {
            // P2.5-PKG-B. The permission was checked and the owner was not, so this wrote
            // category_id, product_id, rating_no, title, description, status and username onto
            // ANOTHER STORE'S row. Above the validator, so a cross-store attempt cannot be told
            // apart from a refusal by which error comes back.
            if (! $this->callerOwnsTestimonial($Testimonial)) {
                abort(404);
            }

            $validator = \Validator::make(
                $request->all(), [
                    //'category_id' => 'required',
                    //'product_id' => 'required',
                    'rating_no' => 'required',
                    'title' => 'required',
                    'description' => 'required',
                    'status' => 'in:0,1',
                ]
            );
            if($validator->fails())
            {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', implode(' ', $messages->all()));
            }

            $dir        = 'uploads/' . getCurrentStore() . '/testimonial';
            $totalImageSize = 0;
            if ($request->hasFile('avatar')) {
                $totalImageSize += $request->file('avatar')->getSize();
            }
            
            $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $totalImageSize);
            if ($result != 1) {
                return redirect()->back()->with('error', $result);
            }
            if ($request->avatar) {
                if ($result == 1) {
                    if (!empty($Testimonial->avatar) && $Testimonial->avatar != '/storage/uploads/default.jpg' && \File::exists(base_path($Testimonial->avatar))) {
                        Utility::changeStorageLimit(\Auth::user()->creatorId(), $Testimonial->avatar);
                    }
                    $fileName = rand(10,100).'_'.time() . "_" . $request->avatar->getClientOriginalName();
                    $path = Utility::upload_file($request, 'avatar', $fileName, $dir, []);
                    if ($path['flag'] == 1) {
                        $Testimonial->avatar = $path['url'];
                    } else {
                        return redirect()->back()->with('error', __($path['msg']));
                    }
                } else {
                    return redirect()->back()->with('error', $result);
                }
            }

            $Testimonial->category_id = $request->category_id;
            $Testimonial->product_id = $request->product_id;
            $Testimonial->rating_no = $request->rating_no;
            $Testimonial->title = $request->title;
            $Testimonial->description = $request->description;
            $Testimonial->status = $request->status;
            $Testimonial->username = $request->username;
            $Testimonial->save();

            // Testimonial::AvregeRating($request->product_id);

            return redirect()->back()->with('success', __('Testimonial update successfully.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Testimonial  $Testimonial
     * @return \Illuminate\Http\Response
     */
    public function destroy(Testimonial $Testimonial)
    {

        if (auth()->user() && auth()->user()->isAbleTo('Delete Testimonial'))
        {
            // P2.5-PKG-B. The guard precedes BOTH the storage accounting and the delete, and the
            // order matters: `changeStorageLimit(\Auth::user()->creatorId(), ...)` below debits
            // the CALLER'S OWN quota for the file it is given, so deleting a victim's testimonial
            // charged the attacker's account for the victim's avatar.
            if (! $this->callerOwnsTestimonial($Testimonial)) {
                abort(404);
            }

            if (!empty($Testimonial->avatar) && $Testimonial->avatar != '/storage/uploads/default.jpg' && \File::exists(base_path($Testimonial->avatar))) {
                Utility::changeStorageLimit(\Auth::user()->creatorId(), $Testimonial->avatar);
            }
            $Testimonial->delete();
            return redirect()->back()->with('success', __('Testimonial delete successfully.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * P2.5-PKG-B. THIS RETURNED EVERY TENANT'S PRODUCT NAMES AND IDS.
     *
     * It fills the product dropdown on the testimonial form once a category is chosen, and its
     * only filter was `category_id` -- taken straight from the request. `Product` is absent from
     * `config/tenancy.php`'s `scoped_models`, so `StoreScope::isActiveFor()` short-circuits and
     * the global scope adds NO predicate for any principal; CLAUDE.md records this property and
     * records that it already produced a live cross-tenant stock drain (S19).
     *
     * MEASURED in `TestimonialProductLookupCrossTenantTest` before this guard existed: 2 failed,
     * 1 passed -- the pass being the positive control, so the fixture was sound. A merchant posted
     * another store's category id and read back that store's product names and ids. Category ids
     * are small per-store integers, so this is enumeration rather than guesswork, and the ids are
     * then usable against any other handler that takes a product id.
     *
     * `routes/web.php:944` registers it as `POST get-product` inside the
     * ['auth','xss','setlocate','verified'] group -- a SEPARATE declaration, not part of
     * `Route::resource('testimonial')`, which is exactly why a census keyed on resource-controller
     * method names never examined it.
     *
     * A STORE PREDICATE AND NOT A PERMISSION, deliberately -- and for a different reason than the
     * one first written here. The first version of this docblock said "the form that calls it is
     * already gated". THAT IS FALSE, and the tenant-authz review caught it: `create()` below has
     * no permission check at all, so `GET /testimonial/create` renders for any authenticated web
     * principal including staff holding nothing.
     *
     * The real reason is that this endpoint is SHARED by three unrelated features --
     * `resources/views/testimonial/create.blade.php`, `resources/views/wishlist/index.blade.php`
     * and the ProductReview module's `product-review/index.blade.php` -- so any single permission
     * name picked here would break the dropdown for the other two. Which permission it should
     * require, if any, is a business-rule question recorded for the owner. Closing the
     * cross-tenant read is not.
     *
     * THE SERVER-RENDERED TWIN ALREADY HAD THIS PREDICATE: `edit()` below builds the same list
     * with `->where('store_id', getCurrentStore())`, and so does the ProductReview module's
     * controller. The AJAX path was simply missing what both rendered paths had, which is the
     * strongest evidence that this restores intended behaviour rather than inventing it.
     *
     * Fails closed: with no current store `getCurrentStore()` returns null, `where('store_id', null)`
     * compiles to `store_id is null`, and `products.store_id` is nullable -- so the null case is
     * refused explicitly rather than left to match orphan rows.
     */
    public function get_product(Request $request)
    {
        $id = $request->id;
        $value = $request->val;

        $currentStore = getCurrentStore();

        $Product = empty($currentStore)
            ? collect()
            : Product::where('category_id', $id)->where('store_id', $currentStore)->get();
        $option = '<option value="">' . __('Select Product') . '</option>';
        foreach ($Product as $key => $Category) {
            $select = $value == $Category->id ? 'selected' : '';
            $option .= '<option value="' . $Category->id . '" '.$select.'>' . $Category->name . '</option>';
        }

        $select =  '<select class="form-control" data-role="tagsinput" id="product_id" name="product_id">'.$option.'</select>';
        $return['status'] = true;
        $return['html'] = $select;
        return response()->json($return);
    }

    public function terms(Request $request)
    {
        return view('other_page.terms');
    }

    public function return_policy(Request $request)
    {
        return view('other_page.privacy');
    }

    public function contact_us(Request $request)
    {
        return view('other_page.contact_us');
    }
}
