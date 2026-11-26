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
            // GAP-193. See foreignRowRefusal() at the end of this class.
            if ($refusal = $this->foreignRowRefusal($request)) {
                return redirect()->back()->with('error', $refusal);
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
     * (`TestimonialDataTable::query()`), so ids are not listed to an attacker -- but they are small
     * sequential integers, which is the same enumeration argument the `category_id` leak rests on.
     *
     * `destroy()` was the sharpest: it called
     * `Utility::changeStorageLimit(\Auth::user()->creatorId(), $Testimonial->avatar)` BEFORE the
     * delete, so deleting a victim's testimonial debited the ATTACKER'S OWN storage quota for the
     * victim's file.
     *
     * Fails closed when either side is absent. No operator limb, as on
     * `ProductBrandController::callerOwnsBrand()` -- and note `getCurrentStore()` returns the
     * literal 1 for a super admin with an empty `current_store` (`getCurrentStore()`), so an
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

            // GAP-193. The row guard above proves the row is the caller's; this proves the PRODUCT
            // the caller is repointing it at is too. See foreignRowRefusal().
            if ($refusal = $this->foreignRowRefusal($request)) {
                return redirect()->back()->with('error', $refusal);
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
     * `routes/web.php` registers it as `POST get-product` (route name `get.product`) inside the
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
            $option .= '<option value="' . $Category->id . '" '.$select.'>' . e($Category->name) . '</option>';
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

    /**
     * GAP-193 / `CUST-005`. IS THE PRODUCT (AND CATEGORY) THIS TESTIMONIAL NAMES ACTUALLY OURS?
     *
     * WHAT WAS WRONG. `store()` and `update()` wrote `product_id` and `category_id` STRAIGHT FROM
     * REQUEST INPUT while stamping `store_id = getCurrentStore()`, so a merchant could save a row
     * that says *"store A's review, of store B's product"*. `update()`'s row-ownership guard cannot
     * see it: the row really IS the caller's, and only the product it points at is foreign.
     *
     * AND THE ROW IS SERVED ON THE VICTIM'S STOREFRONT, which is what makes it a finding rather
     * than untidy data. `Api\ApiController::product_detail():5834` and
     * `::product_detail_guest():5991` read `Testimonial::where('product_id', $id)->get()` with NO
     * store predicate, and their shared docblock states the reason: the review loop sits inside
     * `if (!empty($product))`, so the reviews are *"dependent lookups on a store-verified parent"*.
     * That argument is sound AND IT RESTS ON A PREMISE THIS WRITER DID NOT GUARANTEE -- the same
     * shape `GAP-279` records for `MerchantPermissions`' NULL-store arm. `Testimonial` carries no
     * `StoreScope` and is deliberately absent from `config/tenancy.php`'s `scoped_models`, so
     * nothing underneath contains it either.
     *
     * MEASURED through the real routes before this existed
     * (`Gap193ReviewPurchaseGateAndTenancyTest`): `POST /testimonial` as merchant A with store B's
     * ids wrote `store_id=1, product_id=2` where product 2 belongs to store 2, and
     * `POST api/{slug}/product-detail` for store B served that review to B's own shopper.
     * `PUT /testimonial/{id}` repointed an existing own review from product 1 to product 2.
     * `Testimonial::AvregeRating()` has the same unpredicated shape and WRITES
     * `products.average_rating`, so the planted score is folded into the victim's average as soon
     * as anyone rates that product.
     *
     * FIXED AT THE WRITERS, AND NOT AT THOSE TWO READERS, for two reasons: the readers live in a
     * file this workstream must not edit; and their own docblock argues against a second predicate
     * on a NULLABLE `store_id` column, citing a measured case of `product_variants.store_id`
     * drifting (`ProductController:2873-2895`, where an operator's no-op save stamped store 1 on a
     * store-7 product's variants) -- such a predicate would silently refuse a store its own reviews.
     *
     * **A THIRD REASON USED TO BE GIVEN HERE AND IT WAS FALSE. WITHDRAWN.** It said "restoring the
     * premise is what makes every existing reader correct at once, including `AvregeRating()`", on
     * the premise that there were FOUR writers and this change fixed the two that needed it. The
     * independent review of that change found a SIXTH: `Api\DashboardController::UpdateReview`
     * scoped the review ROW and not the `product_id` COLUMN, so one request by a merchant acting on
     * their own store and their own review repointed it at another tenant's product and
     * `AvregeRating()` then wrote that tenant's `products.average_rating` -- measured, 5 -> 1, HTTP
     * 200 `{"status":1}`. The claim is only true once EVERY writer holds the premise, which is why
     * the predicate now lives in `App\Support\Commerce\ReviewTargetOwnership` and is SHARED with
     * that door rather than copied into it. The census that was built to bound the writer set is
     * what missed the writer -- recorded in the test file, because it is the same shape as the
     * finding.
     *
     * THE PREDICATE ITSELF LIVES IN `App\Support\Commerce\ReviewTargetOwnership`, NOT HERE, and
     * this method is a one-line delegation. Read that class for why the ids are validated rather
     * than derived, why `filter_var` is strict, why `Product` and `Category` need an explicit
     * `store_id` clause (both carry `StoreScope` and both are ABSENT from `scoped_models`, so the
     * global scope adds nothing for any principal), and for the two recorded residuals on its
     * `exists()` reads. It is shared because F1 above proves what a copy costs.
     *
     * WHO ELSE THIS NARROWS, NAMED RATHER THAN LEFT OUT. `store()` has no row to own, so before
     * this guard NOTHING scoped the PLATFORM OPERATOR there: they could name any store's product
     * and the row was stamped `store_id = getCurrentStore()`, the hardcoded literal **1** for an
     * operator with an empty `current_store`. A store-1 review of a store-7 product IS the defect
     * above, so narrowing it is the fix -- but it is a narrowing, and
     * `test_the_operator_is_scoped_to_store_one_here_as_on_every_sibling_method` drives it BOTH
     * ways (admitted for store 1's own product, refused across stores) rather than leaving the
     * claim in this comment. It also makes `store()` agree with its four siblings, whose shared
     * `callerOwnsTestimonial()` already records that *"an operator is scoped to store 1 here rather
     * than refused"*.
     *
     * AND IT COSTS THE OPERATOR NO WORKFLOW, which the first version of this paragraph left out --
     * an omission in the REASSURING direction, and the exact mirror of the `GAP-279` mistake where
     * the refusal was permanent and the draft claimed recovery. `StoreController::changeStore()`
     * SKIPS the ownership predicate entirely for the operator, so they may point `current_store` at
     * any store and then write that store's testimonials for that store's products. The only thing
     * they lose is filing a store-1 review of a store-7 product, which IS the defect.
     *
     * THE PERMISSION DOOR IN FRONT OF THIS GUARD HAS NO OPERATOR LIMB, and this guard does not fix
     * that. `index()`, `store()`, `update()` and `destroy()` gate on a bare `isAbleTo()`, which
     * CLAUDE.md records as always refusing the platform operator -- so an operator holding no role
     * never reaches here at all. Pre-existing, outside this finding, and NOT papered over: the
     * operator case in the test grants `Create Testimonial` before driving the door, so what it
     * proves is that THE GUARD is not an operator lockout, and it is silent about the door.
     *
     * @return string|null the message to flash, or null when every named row is this store's
     */
    private function foreignRowRefusal(Request $request): ?string
    {
        return \App\Support\Commerce\ReviewTargetOwnership::refusalFor(
            getCurrentStore(),
            $request->input('product_id'),
            $request->input('category_id')
        );
    }
}
