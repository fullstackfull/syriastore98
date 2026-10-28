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
        if ($refusal = $this->couponReadRefusal()) {
            return $refusal;
        }

        return $dataTable->render('coupon.index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        // F157: this form had NO permission check of any kind -- any authenticated principal could
        // open it. The submission was refused by `store()`, so nothing could be created, but the
        // form itself is a surface and the gate belongs on it too.
        if ($refusal = $this->couponWriteRefusal('Create Coupon')) {
            return $refusal;
        }

        $product = Product::where('store_id', getCurrentStore())->pluck('name', 'id')->toArray();
        $category = Category::where('store_id', getCurrentStore())->pluck('name', 'id')->toArray();

        return view('coupon.create', compact('product', 'category'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        if ($refusal = $this->refuseOperatorWrite('create')) {
            return $refusal;
        }

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
    /**
     * F157 — the coupon READ gate, shared by the list and the export so the two cannot disagree.
     * =============================================================================================
     *
     * **DECIDED 2026-10-28: the platform operator may READ and EXPORT a store's coupons, and may
     * not create, edit, delete or change their values.** Before that decision this controller held
     * the asymmetry F157 named: `fileExport` admitted the operator (it had been given the
     * operator-first gate when F156 closed the hole), while `index` was a bare `isAbleTo()` and so
     * refused them. The operator could export a coupon book they could not open.
     *
     * THE STORE MUST BE CHOSEN, NOT DEFAULTED, and this is the part that is a security fix rather
     * than a consistency one. `getCurrentStore()` hands an operator with an empty `current_store`
     * the **hardcoded literal 1** (`helper.php:636-637`). `getStoreById(1)` normally RESOLVES, so a
     * null-store guard passes and the operator is served *store 1's* coupon book — codes, limits,
     * discounts, expiry — for a store they never selected. That is exactly the
     * "default that could expose an unintended store" the owner's decision forbids, so an operator
     * with no `current_store` is refused here instead of being quietly pointed at the lowest id.
     *
     * @return \Illuminate\Http\RedirectResponse|null  a refusal, or null when the read may proceed
     */
    private function couponReadRefusal()
    {
        $user = auth()->user();

        if (! $user) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        // Operator-first: `isAbleTo()` alone refuses the platform operator, because on a database
        // built from source no role holds `Manage Coupon`.
        $isOperator = \App\Policies\UserPolicy::isOperator($user);

        if (! $isOperator && ! $user->isAbleTo('Manage Coupon')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if ($isOperator && empty($user->current_store)) {
            return redirect()->back()->with('error', __('No store is selected.'));
        }

        // And the store must RESOLVE -- P1-SEC-11 criterion 2, kept for every principal.
        if (getStoreById(getCurrentStore()) === null) {
            return redirect()->back()->with('error', __('No store is selected.'));
        }

        return null;
    }

    /**
     * F157 — the coupon WRITE gate. The operator is refused here EXPLICITLY, not by accident.
     *
     * These handlers were bare `isAbleTo()` calls, which refused the operator as a side effect of
     * Laratrust having no super-admin bypass. That produced the right answer for the wrong reason:
     * anyone adding an operator limb "for consistency" with the read paths would have silently
     * granted the operator the power to rewrite a merchant's coupons, which the owner's decision
     * withholds by name. Stating the refusal makes it a decision rather than a coincidence.
     *
     * @return \Illuminate\Http\RedirectResponse|null
     */
    private function couponWriteRefusal(string $permission)
    {
        $user = auth()->user();

        if (! $user) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if (\App\Policies\UserPolicy::isOperator($user)) {
            // READ AND EXPORT ONLY (owner decision, 2026-10-28). Not an oversight.
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if (! $user->isAbleTo($permission)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        return null;
    }

    /**
     * F157 — refuse the platform operator a coupon WRITE, explicitly.
     *
     * For `store()`, `update()` and `destroy()` the permission gate is already correct, so this
     * adds the one thing that was only true by accident. Those gates are bare `isAbleTo()` calls,
     * which refuse the operator because Laratrust has no super-admin bypass -- the right answer for
     * the wrong reason. Anyone later adding an operator limb "to match the read paths" would have
     * handed the operator the power to rewrite a merchant's coupons, which the owner's decision of
     * 2026-10-28 withholds by name. The refusal is stated so it survives that edit.
     *
     * @return \Illuminate\Http\RedirectResponse|null
     */
    private function refuseOperatorWrite(string $action)
    {
        $user = auth()->user();

        if ($user !== null && \App\Policies\UserPolicy::isOperator($user)) {
            \Illuminate\Support\Facades\Log::warning(
                'coupon ' . $action . ' refused: the platform operator has read and export rights only',
                ['user_id' => $user->id, 'current_store' => getCurrentStore()]
            );

            return redirect()->back()->with('error', __('Permission denied.'));
        }

        return null;
    }

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
        /*
         * F157: the TENANT check was here and the PERMISSION check was not, so a same-store staff
         * member holding no coupon right could open this form and read the coupon's code, discount
         * and limits. `update()` refused the submission, which is why nothing could be changed --
         * but reading a coupon book is the thing F156 was about, and a form is a read.
         */
        if ($refusal = $this->couponWriteRefusal('Edit Coupon')) {
            return $refusal;
        }

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
        if ($refusal = $this->refuseOperatorWrite('update')) {
            return $refusal;
        }

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
        if ($refusal = $this->refuseOperatorWrite('delete')) {
            return $refusal;
        }

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
        // F157: the SAME gate as `index()`, so the export can never allow what the read
        // forbids, nor the reverse -- which is the asymmetry this finding was.
        if ($refusal = $this->couponReadRefusal()) {
            return $refusal;
        }

        // The store-resolution check that used to sit here is inside `couponReadRefusal()` now,
        // together with the operator's explicit-store requirement, so the list and the export
        // cannot drift apart. The reasoning for it is recorded there.

        $fileName = 'Coupon.xlsx';

        return Excel::download(new CouponExport, $fileName);
    }
}
