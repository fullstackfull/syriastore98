<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\PlanCoupon;
use App\Models\Plan;
use App\Models\PlanUserCoupon;
use App\Models\Utility;
use Illuminate\Validation\Rule;
use App\DataTables\PlanCouponDataTable;
use App\DataTables\PlanCouponDetailDataTable;

/**
 * NEW-PLAN-COUPON-MERCHANT-PERM-01 -- PLATFORM SaaS PLAN COUPONS GATED ON MERCHANT STORE-COUPON
 * PERMISSIONS.
 *
 * Every method in this controller operates on `plan_coupons`: the discount codes the PLATFORM
 * issues against its own subscription Plans (PlanCoupon is consumed at
 * BankTransferController::planPayWithbank:57 and by the 15 plan-purchase gateways, all of which
 * price a Plan, not a product). The rows are platform revenue configuration and have no
 * store_id at all.
 *
 * But the gates read, before this fix:
 *
 *     index()    isAbleTo('Manage Coupon')
 *     create()   isAbleTo('Create Coupon')
 *     store()    isAbleTo('Create Coupon')
 *     edit()     isAbleTo('Edit Coupon')
 *     update()   isAbleTo('Edit Coupon')
 *     destroy()  isAbleTo('Delete Coupon')
 *     show()     NOTHING AT ALL
 *
 * and those five permission names are MERCHANT permissions for STORE coupons. Measured, from the
 * only mechanism in this application that creates permission rows:
 *
 *     app/Models/Utility.php:3018-3021   granted to the merchant role (`admin`)
 *     app/Models/Utility.php:3126-3129   granted to `super admin`
 *
 * They are granted to BOTH, so any merchant holding ordinary store-coupon rights -- the rights
 * needed to run a discount in their own shop, i.e. the common case -- passed every one of these
 * checks and could read, create, edit and DELETE the platform's subscription coupons.
 * CouponController (store coupons) gates on the same names, which is where they belong; the
 * collision is that this controller reused them for a different, platform-owned resource.
 *
 * THE BULK PATH FOR THIS SAME MODEL IS ALREADY REFUSED. `plan-coupon` and `plan-coupon-detail`
 * are both in BulkDeleteTenantGuard::PLATFORM_GLOBAL (app/Http/Middleware/
 * BulkDeleteTenantGuard.php:213-214), whose docblock calls them "SaaS billing/subscription
 * records belonging to the platform operator". That guard only inspects the BulkDelete addon
 * endpoint, so the single-row paths in this controller were the way around it.
 *
 * THE FIX IS THE EXISTING PLATFORM GATE, NOT A NEW PERMISSION NAME. PlatformOperatorOnly is
 * already this project's route-level platform-operator control (order.approve, order.reject,
 * plan.order.refund). Inventing a plan-coupon permission name would repeat the 'Edit Order'
 * defect: Utility::addNewData() is the only thing that creates Permission rows, so a name absent
 * from it can never be granted and the route becomes a permanent 403 for everyone, the operator
 * included.
 *
 * WHERE THE GATE IS ATTACHED, and why it is not in this class. It is on the resource route --
 * routes/web.php, `Route::resource('plan-coupon', ...)->middleware(PlatformOperatorOnly::class)`
 * -- which is both this project's established shape for that control (order.approve,
 * order.reject and plan.order.refund are all gated the same way) and the only shape that works
 * here: App\Http\Controllers\Controller extends the legacy Illuminate\Routing\Controller, which
 * declares a NON-STATIC `middleware()` method, so implementing Laravel 11's
 * Illuminate\Routing\Controllers\HasMiddleware on this class is a fatal error --
 * "Cannot make non static method Illuminate\Routing\Controller::middleware() static". Measured,
 * not assumed: `php -l` ACCEPTS the HasMiddleware version and every request then dies at class
 * load, so this was caught by running the suite rather than by linting. Declaring the gate on the
 * route also keeps applyCoupon exempt for free, because `apply.coupon` is a separate declaration
 * outside the resource.
 *
 * THE EXISTING isAbleTo() CHECKS ARE LEFT IN PLACE AND ARE NOT WEAKENED. They now sit BEHIND the
 * operator gate, so they can only ever narrow the operator's own access, never widen a
 * merchant's -- defence in depth, and a strictly smaller change than rewriting seven gates.
 * 'Manage Coupon' .. 'Delete Coupon' are granted to the `super admin` role by
 * Utility.php:3126-3130, so the operator keeps working.
 *
 * applyCoupon() IS DELIBERATELY EXEMPT. It is the AJAX price preview on the plan-purchase page
 * (`apply.coupon`, routes/web.php:535) and its caller is the MERCHANT who is buying a plan. It
 * takes an encrypted plan id and a coupon code, writes nothing, and returns only a recomputed
 * price for the plan the buyer is already looking at. Gating it would break plan purchase for
 * every merchant on the platform -- the exact "tightened a predicate without checking who it
 * excludes" failure this phase has already caused twice. formatPrice() is a pure helper with no
 * route.
 */
class PlanCouponController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(PlanCouponDataTable $dataTable)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Coupon'))
        {
            return  $dataTable->render('plan-coupon.index');
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        if (auth()->user() && auth()->user()->isAbleTo('Create Coupon'))
        {
            return view('plan-coupon.create');
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {        
        if (auth()->user() && auth()->user()->isAbleTo('Create Coupon'))
        {
            $validator = Validator::make(
                $request->all(), [
                                   'name' => 'required|unique:plan_coupons,name',
                                   'discount' => [
                                        'required',
                                        'numeric',
                                        function ($attribute, $value, $fail) {
                                            if ($value >= 100) {
                                                $fail('The ' . $attribute . ' must be below 100.');
                                            }
                                        },
                                    ],
                                   'limit' => 'required|numeric',
                                   'code' => 'required|unique:plan_coupons,code',
                                   ]
                                );
            if($validator->fails())
            {
                $messages = $validator->getMessageBag();

                return redirect()->back()->with('error', $messages->first());
            }
            $plancoupon           = new PlanCoupon();
            $plancoupon->name     = $request->name;
            $plancoupon->discount = $request->discount;
            $plancoupon->limit    = $request->limit;
            $plancoupon->code     = strtoupper($request->code);
            $plancoupon->save();

            return redirect()->back()->with('success', __('Coupon successfully created.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(PlanCouponDetailDataTable $dataTable, string $id)
    {
        $planCoupon = PlanCoupon::find($id);
        if ($planCoupon) {
            // Set the PlanCoupon ID for filtering
            $dataTable->setPlanCouponId($planCoupon->id);
            return $dataTable->render('plan-coupon.show');
        } else {
            return redirect()->back()->with('error', __('Something went wrong.'));
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(PlanCoupon $planCoupon)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Edit Coupon'))
        {
            return view('plan-coupon.edit', compact('planCoupon'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request,  PlanCoupon $planCoupon)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Edit Coupon'))
        {
            $validator = Validator::make(
                $request->all(), [
                                   'name' => [
                                                'required',
                                                Rule::unique('plan_coupons')->ignore($planCoupon->id),
                                            ],
                                   'discount' => [
                                        'required',
                                        'numeric',
                                        function ($attribute, $value, $fail) {
                                            if ($value >= 100) {
                                                $fail('The ' . $attribute . ' must be below 100.');
                                            }
                                        },
                                    ],
                                   'limit' => 'required|numeric',
                                   'code' =>  [
                                                'required',
                                                Rule::unique('plan_coupons')->ignore($planCoupon->id),
                                            ],
                               ]
            );
            if($validator->fails())
            {
                $messages = $validator->getMessageBag();

                return redirect()->back()->with('error', $messages->first());
            }

            $plancoupon           = PlanCoupon::find($planCoupon->id);
            $plancoupon->name     = $request->name;
            $plancoupon->discount = $request->discount;
            $plancoupon->limit    = $request->limit;
            $plancoupon->code     = $request->code;

            $plancoupon->save();

            return redirect()->back()->with('success', __('Coupon successfully updated.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(PlanCoupon $planCoupon)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Delete Coupon'))
        {
            $planCoupon->delete();

            return redirect()->back()->with('success', __('Coupon successfully deleted.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function applyCoupon(Request $request)
    {
        $plan = Plan::find(\Illuminate\Support\Facades\Crypt::decrypt($request->plan_id));
        if($plan && $request->coupon != '')
        {
            $original_price = self::formatPrice($plan->price);
            $coupons        = PlanCoupon::whereRaw('BINARY `code` = ?', [$request->coupon])->where('is_active', '1')->first();

            if(!empty($coupons))
            {
                $usedCoupun = $coupons->used_coupon();
                if($coupons->limit == $usedCoupun)
                {
                    return response()->json(
                        [
                            'is_success' => false,
                            'final_price' => $original_price,
                            'price' => number_format($plan->price),
                            'message' => __('This coupon code has expired.'),
                        ]
                    );
                }
                else
                {
                    $discount_value = ($plan->price / 100) * $coupons->discount;
                    $plan_price     = $plan->price - $discount_value;
                    $price          = self::formatPrice($plan->price - $discount_value);
                    $discount_value = '-' . self::formatPrice($discount_value);

                    return response()->json(
                        [
                            'is_success' => true,
                            'discount_price' => $discount_value,
                            'final_price' => $price,
                            'price' => number_format($plan_price, 2),
                            'message' => __('Coupon code has applied successfully.'),
                        ]
                    );
                }
            }
            else
            {
                return response()->json(
                    [
                        'is_success' => false,
                        'final_price' => $original_price,
                        'price' => number_format($plan->price, 2),
                        'message' => __('This coupon code is invalid or has expired.'),
                    ]
                );
            }
        }
    }

    public function formatPrice($price){
        $currency = Utility::GetValueByName('CURRENCY');
        return $currency . number_format($price, 2);
    }
}
