<?php

namespace App\Http\Controllers;

use App\Exports\CouponExport;
use App\Models\Coupon;
use App\Models\Category;
use App\Models\Product;
use App\Models\UserCoupon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\ShopifyConection;
use App\Models\WoocommerceConection;
use App\DataTables\CouponDataTable;
use App\DataTables\UserCouponDataTable;

class CouponController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(CouponDataTable $dataTable)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Coupon')) {
            return $dataTable->render('coupon.index');
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $product = Product::where('store_id', getCurrentStore())->pluck('name', 'id')->toArray();
        $category = Category::where('store_id', getCurrentStore())->pluck('name', 'id')->toArray();

        return view('coupon.create', compact('product', 'category'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Create Coupon')) {
            if ($request->coupon_type !== 'percentage' && isset($request->minimum_spend)) {
                $discountAmount = $request->discount_amount;
                $minimumSpend = $request->minimum_spend;
                $maxSpend = $request->maximum_spend;
                if ($discountAmount >= $minimumSpend) {
                    return redirect()->back()->with('error', __('Discount amount is bigger than Minimum spend'));
                } elseif ($maxSpend <= $discountAmount) {
                    return redirect()->back()->with('error', __('Discount amount is bigger than Maximum spend'));
                }
            }

            if ($request->coupon_type == 'percentage') {
                $validator = \Validator::make($request->all(), [
                    'discount_amount' => 'required|numeric|min:1|max:100',
                ]);
                if ($validator->fails()) {
                    $messages = $validator->getMessageBag();

                    return redirect()->back()->with('error', $messages->first());
                }
            }

            $validator = \Validator::make(
                $request->all(),
                [
                    'coupon_name' => 'required|unique:coupons,coupon_name',
                    'coupon_type' => 'required',
                    'discount_amount' => 'required',
                    'coupon_limit' => 'required',
                    'coupon_expiry_date' => 'required',
                    'coupon_code' => 'required|unique:coupons,coupon_code',
                ]
            );
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();

                return redirect()->back()->with('error', $messages->first());
            }

            $coupon = new Coupon();
            $coupon->coupon_name = $request->coupon_name;
            $coupon->coupon_type = $request->coupon_type;
            if ($request->coupon_type == 'fixed product discount') {
                $coupon->applied_product = implode(',', ! empty($request->applied_product) ? (array) $request->applied_product : []);
                $coupon->exclude_product = implode(',', ! empty($request->exclude_product) ? (array) $request->exclude_product : []);

                $coupon->applied_categories = implode(',', ! empty($request->applied_categories) ? (array) $request->applied_categories : []);
                $coupon->exclude_categories = implode(',', ! empty($request->exclude_categories) ? (array) $request->exclude_categories : []);
            }
            $coupon->minimum_spend = $request->minimum_spend;
            $coupon->maximum_spend = $request->maximum_spend;
            $coupon->discount_amount = $request->discount_amount;
            $coupon->coupon_limit = $request->coupon_limit;
            $coupon->coupon_limit_user = $request->coupon_limit_user;
            $coupon->coupon_limit_x_item = $request->coupon_limit_x_item;
            $coupon->coupon_expiry_date = $request->coupon_expiry_date;
            $coupon->coupon_code = trim($request->coupon_code);
            $coupon->status = $request->status;
            $coupon->sale_items = $request->sale_items;
            $coupon->free_shipping_coupon = $request->free_shipping_coupon;
            $coupon->store_id = getCurrentStore();
            $coupon->save();

            return redirect()->back()->with('success', __('Coupon successfully created.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }


    /**
     * Whether this coupon belongs to a store other than the caller's.
     *
     * CROSS-TENANT COUPON ACCESS, one half of it destructive. `App\Models\Coupon` uses
     * `HasFactory, Cachable` and NOTHING ELSE (Coupon.php:11): no `BelongsToStore`, and it is not
     * in `config/tenancy.php`'s `scoped_models` — so it adds **no** global predicate for any
     * principal, and the open-tenant rule does not even come into it. These four handlers take a
     * route-model-bound `Coupon $coupon` resolved from a bare id and compared it with nothing.
     *
     * MEASURED by driving a store-A merchant holding the coupon permissions at a store-B coupon:
     *
     *   GET    coupon/{id}/edit  -> 200            another store's code and discount terms
     *   GET    coupon/{id}       -> 200            another store's redemption list
     *   DELETE coupon/{id}       -> 302, ROW GONE  a store-A merchant DELETED a store-B coupon
     *
     * The delete was confirmed against the database rather than against a status code, because
     * both outcomes are 302.
     *
     * NOT an "any authenticated user" hole: a staff member with no permission got 404, so the
     * boundary that was missing is the one BETWEEN MERCHANTS. An independent sweep claimed the
     * wider shape; driving it narrowed the claim.
     *
     * THE CODEBASE ALREADY KNEW THIS SHAPE. `ImportMappingOwnershipTest:23` records F1 as
     * "`WoocomCouponController::edit` ... never compared the coupon with the caller's store (Coupon
     * carries no [scope])" — the same defect, found and fixed in the WOOCOMMERCE import controller
     * and left standing in this one.
     *
     * The predicate is `CategoryController::categoryIsOutsideCurrentStore()` verbatim, including
     * its exact-case operator test and its reason: it FAILS SAFE, so a hypothetical `'Super Admin'`
     * row is treated as a merchant and refused rather than exempted.
     */
    private function couponIsOutsideCurrentStore($coupon): bool
    {
        $actor = auth()->user();

        if ($actor !== null && $actor->type === \App\Policies\UserPolicy::PLATFORM_ADMIN) {
            return false;
        }

        $currentStore = getCurrentStore();

        return empty($coupon)
            || empty($currentStore)
            || empty($coupon->store_id)
            || (int) $coupon->store_id !== (int) $currentStore;
    }

    /** The refusal, logged once and worded identically wherever it fires. */
    private function refuseForeignCoupon($coupon, string $action)
    {
        \Illuminate\Support\Facades\Log::warning(
            'coupon ' . $action . ' refused: the coupon belongs to another store',
            [
                'coupon_id' => $coupon->id ?? null,
                'coupon_store_id' => $coupon->store_id ?? null,
                'current_store' => getCurrentStore(),
                'user_id' => optional(auth()->user())->id,
            ]
        );

        return redirect()->back()->with('error', __('Permission denied.'));
    }

    /**
     * Display the specified resource.
     */
    public function show(UserCouponDataTable $dataTable, Coupon $coupon)
    {
        if ($this->couponIsOutsideCurrentStore($coupon)) {
            return $this->refuseForeignCoupon($coupon, 'view');
        }

        if (auth()->user() && auth()->user()->isAbleTo('Create Coupon')) {
            // Set the Coupon ID for filtering
            $dataTable->setCouponId($coupon->id);
            return $dataTable->render('coupon.show');
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Coupon $coupon)
    {
        if ($this->couponIsOutsideCurrentStore($coupon)) {
            return $this->refuseForeignCoupon($coupon, 'edit');
        }

        $product = Product::where('store_id', getCurrentStore())->pluck('name', 'id')->toArray();
        $category = Category::where('store_id', getCurrentStore())->pluck('name', 'id')->toArray();
        $applied_product = explode(',', $coupon->applied_product);
        $exclude_product = explode(',', $coupon->exclude_product);
        $applied_categories = explode(',', $coupon->applied_categories);
        $exclude_categories = explode(',', $coupon->exclude_categories);

        return view('coupon.edit', compact('coupon', 'product', 'category', 'applied_product', 'exclude_product', 'applied_categories', 'exclude_categories'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Coupon $coupon)
    {
        /*
         * ABOVE THE VALIDATOR, deliberately and for two reasons. A cross-store attempt must not be
         * distinguishable from a refusal by which error comes back; and the spend validation below
         * answers before any permission or ownership test, so an ungated caller could otherwise
         * probe this handler's rules against a coupon they cannot see.
         */
        if ($this->couponIsOutsideCurrentStore($coupon)) {
            return $this->refuseForeignCoupon($coupon, 'update');
        }

        if ($request->coupon_type !== 'percentage' && isset($request->minimum_spend)) {
            $discountAmount = $request->discount_amount;
            $minimumSpend = $request->minimum_spend;
            $maxSpend = $request->maximum_spend;
            if ($discountAmount >= $minimumSpend) {
                return redirect()->back()->with('error', __('Discount amount is bigger than Minimum spend'));
            } elseif ($maxSpend <= $discountAmount) {
                return redirect()->back()->with('error', __('Discount amount is bigger than Maximum spend'));
            }
        }

        if ($request->coupon_type == 'percentage') {
            $validator = \Validator::make($request->all(), [
                'discount_amount' => 'required|numeric|min:1|max:100',
            ]);
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();

                return redirect()->back()->with('error', $messages->first());
            }
        }
        if (auth()->user() && auth()->user()->isAbleTo('Edit Coupon')) {
            $validator = \Validator::make(
                $request->all(),
                [
                    'coupon_name' => [
                        'required',
                        Rule::unique('coupons')->ignore($coupon->id),
                    ],
                    'discount_amount' => 'required',
                    'coupon_limit' => 'required',
                    'coupon_expiry_date' => 'required',
                    'coupon_code' => [
                        'required',
                        Rule::unique('coupons')->ignore($coupon->id),
                    ],
                ]
            );
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();

                return redirect()->back()->with('error', $messages->first());
            }

            $coupon->coupon_name = $request->coupon_name;
            $coupon->coupon_type = $request->coupon_type;

            if ($request->coupon_type == 'fixed product discount') {
                $coupon->applied_product = implode(',', ! empty($request->applied_product) ? (array) $request->applied_product : []);
                $coupon->exclude_product = implode(',', ! empty($request->exclude_product) ? (array) $request->exclude_product : []);

                $coupon->applied_categories = implode(',', ! empty($request->applied_categories) ? (array) $request->applied_categories : []);
                $coupon->exclude_categories = implode(',', ! empty($request->exclude_categories) ? (array) $request->exclude_categories : []);
            }
            $coupon->minimum_spend = $request->minimum_spend;
            $coupon->maximum_spend = $request->maximum_spend;
            $coupon->discount_amount = $request->discount_amount;
            $coupon->coupon_limit = $request->coupon_limit;
            $coupon->coupon_expiry_date = $request->coupon_expiry_date;
            $coupon->coupon_limit_user = $request->coupon_limit_user;
            $coupon->coupon_limit_x_item = $request->coupon_limit_x_item;
            $coupon->coupon_code = trim($request->coupon_code);
            $coupon->status = $request->status;
            $coupon->sale_items = $request->sale_items;
            $coupon->free_shipping_coupon = $request->free_shipping_coupon;
            $coupon->save();

            return redirect()->back()->with('success', __('Coupon successfully updated.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Coupon $coupon)
    {
        // Above the delete and above the two connection-table deletes it cascades into.
        if ($this->couponIsOutsideCurrentStore($coupon)) {
            return $this->refuseForeignCoupon($coupon, 'delete');
        }

        if (auth()->user() && auth()->user()->isAbleTo('Delete Coupon')) {
            WoocommerceConection::where('module', 'coupon')->where('original_id', $coupon->id)->delete();

            ShopifyConection::where('module', 'coupon')->where('original_id', $coupon->id)->delete();

            $coupon->delete();

            return redirect()->back()->with('success', __('Coupon delete successfully.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function fileExport()
    {
        /*
         * THIS METHOD HAD NO PERMISSION CHECK AT ALL. Found by the independent tenancy/authz
         * review of the P2-04-12 order-export change, in the sibling it did not touch.
         *
         * `index()` -- the coupon list this file is the export OF -- requires `Manage Coupon`
         * (`:25`). `fileExport` required nothing beyond the route group's `auth`, so any
         * authenticated principal, including a staff member of any merchant holding no coupon
         * permission at all, could GET `coupon/export` and download the store's entire coupon
         * book: codes, limits, discount amounts and expiry dates. It is the same file-shaped hole
         * in the permission boundary that P1-SEC-11 closed on `order/export`, left open on its
         * twin.
         *
         * IT IS NOT, AND MUST NOT BE REPORTED AS, A CROSS-TENANT LEAK. `CouponExport::collection()`
         * has always filtered on `getStoreById(getCurrentStore())`, so the rows were the caller's
         * own store's. The defect is the missing permission and the refusal is stated as that --
         * the overstatement corrected once already for the order export.
         *
         * OPERATOR-FIRST, because `isAbleTo()` alone refuses the platform operator: on a database
         * built from source no role holds `Manage Coupon`. NOTE THE INCONSISTENCY THIS EXPOSES
         * rather than hides: `index()`, `:48`, `:191`, `:255` and `:317` are bare `isAbleTo()`
         * calls with no operator limb, so they DO refuse the operator. Recorded as F157 and left
         * alone, because changing it alters five handlers' behaviour for the platform operator and
         * belongs in a slice that can measure that.
         *
         * `redirect()->back()` and NOT a 401: this is a web route and an ordinary commercial
         * refusal, and the shipped Flutter app forces a logout on any 401.
         */
        $user = auth()->user();
        $isOperator = \App\Policies\UserPolicy::isOperator($user);

        if (!$user || (!$isOperator && !$user->isAbleTo('Manage Coupon'))) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        /*
         * And the store must RESOLVE, not merely be non-empty -- P1-SEC-11 criterion 2.
         * `getCurrentStore()` hands back a hardcoded 1 for an operator with no `current_store`, so
         * `empty()` is not the question; `getStoreById()` returning null is. Without this,
         * `CouponExport::collection()` dereferenced `$store->id` and answered a stack trace.
         */
        if (getStoreById(getCurrentStore()) === null) {
            return redirect()->back()->with('error', __('No store is selected.'));
        }

        $fileName = 'Coupon.xlsx';

        return Excel::download(new CouponExport, $fileName);
    }
}
