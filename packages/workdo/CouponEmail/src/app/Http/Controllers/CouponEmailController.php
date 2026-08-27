<?php

namespace Workdo\CouponEmail\app\Http\Controllers;

use App\Models\Coupon;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Workdo\CouponEmail\app\Models\CouponEmail;

class CouponEmailController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */

    public function saveCouponEmailSettings(Request $request)
    {
        session()->put(['setting_tab' => 'coupon_email_setting']);
        session()->put(['couponemail_setting' => $request->couponemail_setting]);
        $post['couponemail_setting_enabled'] = $request->couponemail_setting_enabled;
            if ($request->couponemail_setting == "0") {
                $validator = \Validator::make(
                    $request->all(),
                    [
                        'selected_coupon' => 'required',
                        'email_subject' => 'required',
                        'email_content' => 'required',
                    ]
                );
            } elseif ($request->couponemail_setting == "1") {
                $validator = \Validator::make(
                    $request->all(),
                    [
                        'first_selected_coupon' => 'required',
                        'first_email_subject' => 'required',
                        'first_email_content' => 'required',

                    ]
                );
            } elseif ($request->couponemail_setting == "2") {
                $validator = \Validator::make(
                    $request->all(),
                    [
                        'order_order_thresholds' => 'required',
                        'order_email_subject' => 'required',
                        'order_email_content' => 'required',

                    ]
                );
            }
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }
            unset($post['_token']);
            $post = $request->all();

            $couponEmailQuery = CouponEmail::query();
            foreach ($post as $key => $data) {
                (clone $couponEmailQuery)->updateOrCreate(
                    [
                        'name' => $key,
                        'store_id' => getCurrentStore()
                    ],
                    [
                        'value'         => $data,
                        'name'          => $key,
                        'store_id'      => getCurrentStore(),
                        'created_by'    => auth()->user()->id,
                    ]
                );
            }
            return redirect()->back()->with('success', __('Coupon Email Setting successfully updated.'));
    }

    public function getCouponEmailFields(Request $request)
    {
        $couponemail_setting = $request->couponemail_setting;
        $selectCoupons = Coupon::where('store_id', getCurrentStore())->get()->pluck('coupon_code', 'id');
        $couponEmail = CouponEmail::where('store_id', getCurrentStore())->pluck('value', 'name')->toArray();
        $returnHTML = view('coupon-email::pages.input', compact('couponemail_setting', 'selectCoupons', 'couponEmail'))->render();
        $response = [
            'is_success' => true,
            'message' => '',
            'html' => $returnHTML,
        ];
        return response()->json($response);
    }

    public function settingForm(Request $request) {
		try {
		    $couponemail = [
                'User Registration',
                'First Purchase',
                'Specific Order Threshold'
            ];
            $couponEmails = CouponEmail::where('store_id', getCurrentStore())->pluck('value', 'name')->toArray();
		    $html = view('coupon-email::pages.tab_form', compact('couponemail', 'couponEmails'))->render();

		    return response()->json([
		        'is_success' => true,
		        "msg" => __( "Coupon email form get successfully."),
		        "data" => ['content' => $html]
		    ]);
		} catch (\Exception $e) {
		    return response()->json([
		        'is_success' => false,
		        "msg" => __('Something went wrong!'),
		        "data" => ['content' => null]
		    ]);
		}
	}
}
