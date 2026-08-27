<?php

namespace Workdo\Campaigns\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Workdo\Campaigns\app\Models\Campaigns;
use Workdo\Campaigns\app\Models\CostSetting;
use App\Http\Controllers\CouponController;
use App\Models\Utility;
use App\Models\User;
use App\Models\Product;
use App\Models\Store;
use App\Models\PlanCoupon;
use App\Models\PlanUserCoupon;
use Session;
use Auth;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use App\Khalti\Khalti;
use Workdo\Campaigns\app\Services\CampaignsPaymentService;
use Workdo\Campaigns\DataTables\AllCampaignsData;

class CampaignsController extends Controller
{

    protected $CampaignsPaymentService;

    public function __construct(CampaignsPaymentService $CampaignsPaymentService)
    {
        $this->CampaignsPaymentService = $CampaignsPaymentService;
    }

    /**
     * Display a listing of the resource.
     */


    public function index(AllCampaignsData $dataTable, Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return redirect()->back()->with('error', __('Permission denied'));
        }
        if ($user && $user->type == 'super admin') {
            $catList = Store::pluck('name', 'id')->prepend('Select store', 0);
            $productList = Product::pluck('name', 'id')->prepend('Select product', 0);
            $userList = User::pluck('name', 'id')->prepend('Select User', 0);
        } else {
            $catList = Store::where('created_by', Auth::user()->creatorId())->get()->pluck('name', 'id')->prepend('Select store', 0);
            $productList = Product::where('created_by', Auth::user()->creatorId())->get()->pluck('name', 'id')->prepend('Select product', 0);
            $userList = User::get()->pluck('name', 'id')->prepend('Select User', 0);
        }
        return $dataTable->with('request', $request)->render('campaigns::index', compact('productList', 'catList', 'userList'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $store = Store::where('created_by', Auth::user()->creatorId())->get()->pluck('name', 'id')->prepend('Select store', 0);
        return view('campaigns::create', compact('store'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {

        $validator = \Validator::make(
            $request->all(),
            [
                'name' => 'required',
                'store' => 'required',
                'product' => 'required',
                'start_date' => 'required',
                'end_date' => 'required',
            ]
        );
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();

            return redirect()->back()->with('error', $messages->first());
        }
        $campaignData = Campaigns::where('product', $request->product)->where('created_by', \Auth::user()->creatorId())->get();

        if ($campaignData) {
            foreach ($campaignData as $key => $value) {
                if (
                    ($request->start_date >= $value->start_date && $request->start_date <= $value->end_date) ||
                    ($request->end_date >= $value->start_date && $request->end_date <= $value->end_date) ||
                    ($request->start_date <= $value->start_date && $request->end_date >= $value->end_date)
                ) {
                    return redirect()->back()->with('error', __('The Campaign dates overlap with an existing campaign for this product'));
                }
            }
        }
        $paymentMethod = $request->payment_type;

        try {
            $response = $this->CampaignsPaymentService->process($request);
            if (in_array($paymentMethod, ['Paystack', 'Razorpay', 'Paymentwall', 'Flutterwave', 'Paytm', 'Telegram', 'Paytr', 'Midtrans', 'Whatsapp', 'Payhere', 'Khalti', 'Authorizenet'])) {
                $viewHtml = $response->render();
                return $viewHtml;
            } elseif (in_array($paymentMethod, ['skrill', 'Sspay', 'Toyyibpay', 'Paytabs', 'cod', 'bank_transfer', 'Nepalste', 'Tap', 'Phonepe', 'Paddle', 'Paiementpro', 'Fedpay', 'Cinetpay', 'Senangpay', 'Ozow', 'Easebuzz', 'Paynow', 'Esewa', 'Dpopay', 'Myfatoorah'])) {
                return new RedirectResponse($response->getTargetUrl());
            } elseif ($paymentMethod == 'Coingate') {
                return new RedirectResponse($response->payment_url);
            } elseif (in_array($paymentMethod, ['Payfast', 'Cybersource', 'Nmi', 'Payu', 'Braintree', 'Powertranz', 'SSLCommerz'])) {
                return $response;
            } else {
                if (filter_var($response, FILTER_VALIDATE_URL)) {
                    return new RedirectResponse($response);
                } else {
                    throw new \Exception("Invalid response URL for payment method: $paymentMethod");
                }
            }
        } catch (\Exception $e) {
            return redirect()->back()->with('error', __('Somthing went wrong'));
        }
        return redirect()->back()->with('success', __('Campaigns successfully created'));
    }

    public function paymentSuccess(Request $request)
    {

        if ($request->return_type == 'cancel') {
            return redirect()->route('campaigns.index')->with('error', __('Something went wrong'));
        } else {
            $requestData = json_decode(urldecode($request->input('request_data')), true);
            if (empty($requestData)) {
                $requestData = Session::get('campaigns_request_data');
            }
            $orderID = strtoupper(str_replace('.', '', uniqid('', true)));
            if ($requestData['payment_type'] == 'Khalti') {
                $payload = $request->payload;
                $token = $payload['token'];
                $amount = $payload['amount'];
                $payment_setting = getSuperAdminAllSetting();
                $secret = !empty($payment_setting['khalti_secret_key']) ? $payment_setting['khalti_secret_key'] : '';
                $khalti = new Khalti();
                $response = $khalti->verifyPayment($secret, $token, $amount);
                if ($response['status_code'] != '200') {
                    return redirect()->back()->with('error', __('Transaction has been failed'));
                }
            }

            $objUser = \Auth::user();
            $campaignsData = new Campaigns();
            $campaignsData->user = \Auth::user()->id;
            $campaignsData->name = $requestData['name'];
            $campaignsData->store = $requestData['store'];
            $campaignsData->product = $requestData['product'];
            $campaignsData->total_days = $requestData['total_days'];
            $campaignsData->total_cost = $requestData['total_cost'];
            $campaignsData->start_date = $requestData['start_date'];
            $campaignsData->end_date = $requestData['end_date'];
            $campaignsData->payment_method = $requestData['payment_type'];
            $campaignsData->order_id = $orderID;

            $campaignsData->created_by = \Auth::user()->creatorId();
            $campaignsData->save();
            if ($requestData['coupon_id'] && $requestData['coupon_id'] != '') {

                if (!empty($requestData['coupon_id'])) {
                    $coupons = PlanCoupon::where('code', strtoupper($requestData['coupon_id']))->where('is_active', '1')->first();
                    $userCoupon = new PlanUserCoupon();
                    $userCoupon->user_id = $objUser->id;
                    $userCoupon->coupon_id = $coupons->id;
                    $userCoupon->order = $orderID;
                    $userCoupon->save();
                    $usedCoupun = $coupons->used_coupon();
                }
            }
            if ($requestData['payment_type'] == 'Khalti') {
                Session::put('campaigns_data', $campaignsData);
                $success = route('campaigns.index');
                return response()->json([
                    'status' => 'success',
                    'data' => [
                        'campagins_data' => $requestData,
                        'status_code' => $response['status_code'],
                        'redirect_url' => $success,
                    ]
                ]);
            }

            if ($requestData['payment_type'] == 'Braintree') {
                \Session::flash('success', __('Campaign created successfully'));
                return response()->json(['success' => true, 'return_url' => route('campaigns.index')]);
            }
            Session::forget('campaigns_request_data');

            return redirect()->route('campaigns.index')->with('success', __('Campaign created successfully'));
        }
    }
    public function applyCouponPromote(Request $request)
    {
        if ($request->coupon != '') {
            $original_price = self::formatPrice($request->total_cost);
            $coupons = PlanCoupon::where('code', strtoupper($request->coupon))->where('is_active', '1')->first();
            if (!empty($coupons)) {
                if (Carbon::now()->gt(Carbon::parse($coupons->expiry_date))) {
                    return response()->json([
                        'is_success' => false,
                        'final_price' => $original_price,
                        'price' => SetNumberFormat((int) $request->total_cost),
                        'message' => __('This coupon code has expired'),
                    ]);
                }
                $usedCoupun = $coupons->used_coupon();

                if ($coupons->limit == $usedCoupun) {
                    return response()->json([
                        'is_success' => false,
                        'final_price' => $original_price,
                        'price' => SetNumberFormat((int) $request->total_cost),
                        'message' => __('This coupon code has reached its overall usage limit'),
                    ]);
                }
                $user = \Auth::user();

                // Calculate discount
                $discount_value = ($request->total_cost / 100) * $coupons->discount;
                $promote_price = $request->total_cost - $discount_value;
                $price = self::formatPrice($promote_price);
                $discount_value = '-' . self::formatPrice($discount_value);

                return response()->json([
                    'is_success' => true,
                    'discount_price' => $discount_value,
                    'final_price' => $price,
                    'price' => $promote_price,
                    'message' => __('Coupon has been applied successfully'),
                ]);
            } else {
                return response()->json([
                    'is_success' => false,
                    'final_price' => $original_price,
                    'price' => $request->total_cost,
                    'message' => __('This coupon is invalid or has expired'),
                ]);
            }
        }
    }

    public function formatPrice($price)
    {
        $currency = Utility::GetValueByName('CURRENCY');
        return $currency . number_format($price, 2);
    }
    // for campaigns cost setting
    public function costData(Request $request)
    {
        $days = $request->total_days;
        $costData = CostSetting::where('min', '<=', $days)
            ->where('max', '>=', $days)
            ->first();

        return response()->json([
            'costData' => $costData
        ]);
    }
    public function campaignsSetup()
    {
        //campaigns system setup
        if (\Auth::user() && \Auth::user()->type == 'super admin') {
            $productDetails = Product::get();
            $costDetail = CostSetting::get();

            return view('campaigns::setting', compact('productDetails', 'costDetail'));
        } else {
            return redirect()->back()->with('error', __('Permission Denied'));
        }
    }

    public function campaignsCost(Request $request)
    {
        $costSettings = $request->input('store_group');

        $deletedIds = array_filter(explode(',', $request->input('deleted_ids')), function ($id) {
            return filter_var($id, FILTER_VALIDATE_INT) !== false;
        });
        $creatorId = \Auth::user()->creatorId();
        // Delete the cost settings marked for deletion
        if (!empty($deletedIds)) {
            CostSetting::whereIn('id', $deletedIds)->delete();
        }
        // Update or create cost settings
        if (!empty($costSettings)) {
            foreach ($costSettings as $costSetting) {
                $costSetting['created_by'] = $creatorId;
                if (isset($costSetting['id']) && $costSetting['id']) {
                    // Update existing cost setting
                    $existingCostSetting = CostSetting::find($costSetting['id']);
                    if ($existingCostSetting) {
                        $existingCostSetting->update($costSetting);
                    }
                } else {
                    // Create new cost setting
                    CostSetting::create($costSetting);
                }
            }
        }
        return redirect()->route('campaigns.setting')->with('success', __('Cost settings saved successfully'));
    }

    public function productData(Request $request)
    {
        //for get product
        if (!module_is_active('DigitalProduct')) {
            if (!module_is_active('AuctionProduct')) {
                $product = Product::where('created_by', Auth::user()->creatorId())
                ->where('store_id', $request->store_id)
                ->get()
                ->pluck('name', 'id')
                ->toArray();
            }else{
                $product = Product::where('created_by', Auth::user()->creatorId())
                    ->where('store_id', $request->store_id)
                    ->where('product_type', '!=', 'digital')
                    ->get()
                    ->pluck('name', 'id')
                    ->toArray();
            }
        }elseif (!module_is_active('AuctionProduct')) {
            if (!module_is_active('DigitalProduct')) {
                $product = Product::where('created_by', Auth::user()->creatorId())
                ->where('store_id', $request->store_id)
                ->get()
                ->pluck('name', 'id')
                ->toArray();
            }else{
                $product = Product::where('created_by', Auth::user()->creatorId())
                    ->where('store_id', $request->store_id)
                    ->where('product_type', '!=', 'auction')
                    ->get()
                    ->pluck('name', 'id')
                    ->toArray();
            }
        } else {
            $product = Product::where('created_by', Auth::user()->creatorId())
                ->where('store_id', $request->store_id)
                ->get()
                ->pluck('name', 'id')
                ->toArray();
        }
        return response()->json($product);
    }

    public function productAnalytics(Request $request, $id)
    {
        $campaign = Campaigns::findOrFail($id);
        $storeName = Store::where('id', $campaign->store)->value('name');

        $devicearray = [
            'label' => [$storeName],
            'total_days' => [$campaign->total_days],
            'total_cost' => [$campaign->total_cost]
        ];
        return view('campaigns::analytics', compact('campaign', 'devicearray'));
    }
    //for view modal approve/decline
    public function viewCampaigns($id)
    {
        $campaigns = Campaigns::find($id);
        $settings = getSuperAdminAllSetting();
        $productId = explode(',', $campaigns->product);
        $allproductId = [];
        $allproductId = array_merge($allproductId, $productId);

        $allproductId = array_unique(array_filter($allproductId));
        $productnames = Product::whereIn('id', $allproductId)->get(['id', 'name', 'slug'])->keyBy('id');
        return view('campaigns::view', compact('campaigns', 'productnames'));
    }

    //for change status
    public function ChangeStatus($id, $response)
    {
        $campaigns = Campaigns::find($id);
        $campaigns->approval = $response;
        if ($response == 2) {
            $campaigns->status = 3;
        }
        $campaigns->save();
        return redirect()->route('campaigns.index')->with('success', __('Campaigns status updated successfully '));
    }
    //campign enable
    public function campaignsEnable(Request $request)
    {
        $data = [];
        $campaigns = Campaigns::find($request->id);

        if ($request->is_disable == 1) {
            $campaigns->status = 1;
            $data['msg'] = __('campaigns is active');
            $data['is_success'] = true;
        } else {
            $campaigns->status = 2;
            $data['msg'] = __('Campaign is expired');
            $data['is_success'] = false;
        }
        $campaigns->save();

        return $data;
    }
}
