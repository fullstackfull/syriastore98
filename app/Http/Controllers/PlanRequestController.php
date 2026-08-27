<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Plan;
use App\Models\User;
use App\Models\PlanOrder;
use App\Models\PlanRequest;
use App\DataTables\PlanRequestDataTable;

class PlanRequestController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(PlanRequestDataTable $dataTable)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Plan Request')) {
            return  $dataTable->render('plan_request.index');
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function userRequest($plan_id)
    {
        $objUser = auth()->user();
        if ($objUser->requested_plan == 0) {
            $planID = \Illuminate\Support\Facades\Crypt::decrypt($plan_id);
            $plan = Plan::find($planID);

            if (!empty($planID)) {
                PlanRequest::create([
                    'user_id' => $objUser->id,
                    'plan_id' => $planID,
                    'duration' => $plan->duration,

                ]);

                // Update User Table
                //$objUser->update(['requested_plan' => $planID]);
                $objUser['requested_plan'] = $planID;
                $objUser->update();

                return redirect()->back()->with('success', __('Request Send Successfully.'));
            } else {
                return redirect()->back()->with('error', __('Something went wrong.'));
            }
        } else {
            return redirect()->back()->with('error', __('You already send request to another plan.'));
        }
    }
    public function cancelRequest($id)
    {

        $user = User::find($id);
        $user['requested_plan'] = '0';
        $user->update();
        PlanRequest::where('user_id', $id)->delete();

        return redirect()->back()->with('success', __('Request Canceled Successfully.'));
    }

    public function acceptRequest($id, $response)
    {
        if (auth()->user() && auth()->user()->type == 'super admin') {
            $plan_request = PlanRequest::find($id);
            $setting = getSuperAdminAllSetting();
            if (!empty($plan_request)) {
                $user = User::find($plan_request->user_id);

                if ($response == 1) {
                    $user->requested_plan = $plan_request->plan_id;
                    $user->plan_id = $plan_request->plan_id;
                    $user->	requested_plan = '0';
                    $user->save();

                    $plan = Plan::find($plan_request->plan_id);
                    $assignPlan = $user->assignPlan($plan_request->plan_id, $plan_request->duration);
                    $price = $plan->{$plan_request->duration . '_price'};

                    if ($assignPlan['is_success'] == true && !empty($plan)) {

                        $orderID = strtoupper(str_replace('.', '', uniqid('', true)));
                        PlanOrder::create(
                            [
                                'order_id' => $orderID,
                                'name' => null,
                                'email' => null,
                                'card_number' => null,
                                'card_exp_month' => null,
                                'card_exp_year' => null,
                                'plan_name' => $plan->name,
                                'plan_id' => $plan->id,
                                'price' => $plan->price,
                                'coupon' => null,
                                'coupon_json' => null,
                                'discount_price' => null,
                                'store_id' => null,
                                'price_currency' => !empty($setting['CURRENCY_NAME']) ? $setting['CURRENCY_NAME'] : 'usd',
                                'txn_id' => '',
                                'payment_type' => __('Manually'),
                                'payment_status' => 'succeeded',
                                'receipt' => null,
                                'user_id' => $user->id,
                                // 'store_id' => $user->current_store,
                                'store_id' =>1,

                            ]
                        );

                        $plan_request->delete();

                        return redirect()->back()->with('success', __('Plan successfully upgraded.'));
                    } else {
                        return redirect()->back()->with('error', __('Plan fail to upgrade.'));
                    }
                } else {
                    $user['requested_plan'] = '0';
                    $user->update();

                    $plan_request->delete();

                    return redirect()->back()->with('success', __('Request Rejected Successfully.'));
                }
            } else {
                return redirect()->back()->with('error', __('Something went wrong.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

    }

}
