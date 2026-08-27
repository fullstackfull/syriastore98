<?php

namespace Workdo\Donation\app\Http\Controllers;

use App\Models\Country;
use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use App\Models\Utility;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Workdo\Donation\app\Services\DonationPaymentService;
use Qirolab\Theme\Theme;
use Illuminate\Http\RedirectResponse;
use Session;
use App\Khalti\Khalti;
use Workdo\Donation\app\Models\CustomerDonation;
use Workdo\Donation\app\Models\Donation;
use Workdo\Donation\app\Models\DonationSetting;
use Illuminate\Support\Facades\Cache;

class CustomerDonationController extends Controller
{
    protected $DonationpaymentService;

    public function __construct(DonationPaymentService $DonationpaymentService)
    {
        $this->DonationpaymentService = $DonationpaymentService;
    }
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index()
    {
        return view('donation::index');
    }

    /**
     * Show the form for creating a new resource.
     * @return Renderable
     */
    public function create($slug)
    {
        $store = getStore($slug);
        if (!$store) {
            return redirect()->back()->with('error', __('Store not found.'));
        }
        $donation = Donation::where('store_id', $store->id)->pluck('title', 'id')->prepend('Select Donation', '');
        $country_option = Country::orderBy('name', 'ASC')->pluck('name', 'id')->prepend('Select Country', 0);
            $themeSettings = getThemeSetting($store->id, $store->theme_id);

        return view('donation::theme.customer.create', compact('donation', 'store', 'country_option','themeSettings', 'slug'));
    }

    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return Renderable
     */
    public function store(Request $request, $slug)
    {
        $validator = \Validator::make(
            $request->all(),
            [
                'name' => 'required',
                'email' => 'required',
                'number' => 'required',
                'address' => 'required',
                'country_id' => 'required',
                'state_id' => 'required',
                'city_id' => 'required',
                'donation' => 'required',
                'amount' => 'required',
                'donationpayment_method' => 'required'
            ]
        );
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', $messages->first());
        }
        $paymentMethod = $request->donationpayment_method;
        if ($request->amount == 'other') {
            $donation_amount = $request->custom_amount;
        } else {
            $donation_amount = $request->amount;
        }
        try {
            $response = $this->DonationpaymentService->process($request->all(), $paymentMethod, $slug, $donation_amount);
            if (in_array($paymentMethod, ['Paystack', 'Razorpay', 'Paymentwall', 'Flutterwave', 'Paytm', 'Telegram', 'Paytr', 'Midtrans', 'Whatsapp', 'Payhere', 'Khalti', 'Authorizenet'])) {
                $viewHtml = $response->render();
                return $viewHtml;
            } elseif (in_array($paymentMethod, ['Skrill', 'Sspay', 'Toyyibpay', 'Paytabs', 'cod', 'bank_transfer', 'Nepalste', 'Tap', 'Phonepe', 'Paddle', 'Paiementpro', 'Fedpay', 'Cinetpay'])) {
                return new RedirectResponse($response->getTargetUrl());
            } elseif ($paymentMethod == 'Coingate') {
                return new RedirectResponse($response->payment_url);
            } elseif ($paymentMethod == 'Payfast') {
                return $response;
            } else {
                if (filter_var($response, FILTER_VALIDATE_URL)) {
                    return new RedirectResponse($response);
                } else {
                    throw new \Exception("Invalid response URL for payment method: $paymentMethod");
                }
            }
        } catch (\Exception $e) {
            return redirect()->back()->with('error', __('Incorrect currency of payment.'));
        }
    }

    /**
     * Show the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function show($id)
    {
        return view('donation::show');
    }

    /**
     * Show the form for editing the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function edit($id)
    {
        return view('donation::edit');
    }

    /**
     * Update the specified resource in storage.
     * @param Request $request
     * @param int $id
     * @return Renderable
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     * @param int $id
     * @return Renderable
     */
    public function destroy($id)
    {
        //
    }

    public function get_donation_amount(Request $request, $slug)
    {
        $id = $request->id;
        $value = $request->val;
        $donation = Donation::find($id);
        if (!$donation || !$donation->amount) {
            return response()->json([
                'status' => false,
                'message' => __('Invalid donation or no amounts available'),
            ]);
        }
        $option = '<option value="">' . __('Select Amount') . '</option>';
        $amounts = json_decode($donation->amount, true);
        if (!is_array($amounts)) {
            return response()->json([
                'status' => false,
                'message' => __('Invalid amount data'),
            ]);
        }

        foreach ($amounts as $amount) {
            $amountValue = $amount['amount'];
            $select = $value == $amountValue ? 'selected' : '';
            $option .= '<option value="' . $amountValue . '" ' . $select . '>' . $amountValue . '</option>';
        }

        $option .= '<option value="other">' . __('Other') . '</option>';

        $select = '<select class="form-control" data-role="tagsinput" id="amount" name="amount">' . $option . '</select>';

        $store = getStore($slug);
        $paymentMethods = [];
        if ($store) {
            $user = User::find($store->created_by);
            $donations = DonationSetting::where('created_by', $user->id)
                ->where('store_id', $store->id)
                ->pluck('value', 'name')->toArray();
            $paymentMethods = isset($donations['payment_methods']) ? json_decode($donations['payment_methods'], true) : [];
        }

        return response()->json([
            'status' => true,
            'html' => $select,
            'paymentMethods' => $paymentMethods,
        ]);
    }

    public function getWhatsappUrl(Request $request, $slug)
    {
        $store = getStore($slug);
        $theme_id = $store->theme_id;
        $requests_data = Session::get('donation_request_data');
        $telegram_access_token = \App\Models\Utility::GetValueByName('telegram_access_token', $store->id);
        $telegram_chat_id = \App\Models\Utility::GetValueByName('telegram_chat_id', $store->id);
        $whatsapp_number = $requests_data['number'];

        $settings = getAdminAllSetting();
        $resp = 'Thank You!';
        if ($requests_data['donationpayment_method'] == 'Telegram') {
            $msg = $resp;
            // Set your Bot ID and Chat ID.
            $telegrambot = $telegram_access_token;
            $telegramchatid = $telegram_chat_id;

            // Function call with your own text or variable
            $url = 'https://api.telegram.org/bot' . $telegrambot . '/sendMessage';
            $data = array(
                'chat_id' => $telegramchatid,
                'text' => $msg,
            );
            $options = array(
                'http' => array(
                    'method' => 'POST',
                    'header' => "Content-Type:application/x-www-form-urlencoded\r\n",
                    'content' => http_build_query($data),
                ),
            );
            $context = stream_context_create($options);
            $url = $url;
        } else {
            $url = 'https://api.whatsapp.com/send?phone=' . $whatsapp_number . '&text=' . urlencode($resp);
        }
        return  $url;
    }

    public function StoreDonationPayment(Request $request, $slug)
    {
        $requests_data = (Session::get('donation_request_data'));
        if ($requests_data == null) {
            $requests_data = $request->all();
        }
        if ($requests_data['amount'] == 'other') {
            $donation_amount = $requests_data['custom_amount'];
        } else {
            $donation_amount = $requests_data['amount'];
        }
        if ($requests_data['donationpayment_method'] == 'Paytr') {
            $donation_amount = sprintf("%01.2f", round(($donation_amount / 100), 2));
        } elseif ($requests_data['donationpayment_method'] == 'Midtrans') {
            $response = json_decode($request->json, true);
            if (isset($response['status_code']) && $response['status_code'] != 200) {
                return redirect()->back()->with('error', $response['status_message']);
            }
        } elseif ($requests_data['donationpayment_method'] == 'Khalti') {
            $store_data = (Session::get('store_request_data'));
            $payload = $request->payload;
            $token = $payload['token'];
            $amount = $payload['amount'];
            $admin_payment_setting = getAdminAllSetting($store_data->created_by, $store_data->id);
            $secret = !empty($admin_payment_setting['khalti_secret_key']) ? $admin_payment_setting['khalti_secret_key'] : '';
            $khalti = new Khalti();
            $response = $khalti->verifyPayment($secret, $token, $amount);
            if ($response['status_code'] != '200') {
                return redirect()->back()->with('error', __('Transaction has been failed.'));
            }
        }

        $store = getStore($slug);
        $donation_data = new CustomerDonation();
        $donation_data->name = $requests_data['name'];
        $donation_data->email = $requests_data['email'];
        $donation_data->number = $requests_data['number'];
        $donation_data->address = $requests_data['address'];
        $donation_data->country_id = $requests_data['country_id'];
        $donation_data->state_id = $requests_data['state_id'];
        $donation_data->city_id = $requests_data['city_id'];
        $donation_data->donation_id = $requests_data['donation'];
        $donation_data->customer_id = auth('customers')->user()->id ??  0;
        $donation_data->amount = $donation_amount;
        $donation_data->payment_type = $requests_data['donationpayment_method'];
        $donation_data->store_id = $store->id;
        $donation_data->theme_id = $store->theme_id;
        $donation_data->save();

        if ($requests_data['donationpayment_method'] == 'Telegram' || $requests_data['donationpayment_method'] == 'Whatsapp') {
            Session::put('donation_data', $donation_data);
            $successUrl = route('donation.customer.success', $store->slug);
            return response()->json([
                'status' => 'success',
                'data' => [
                    'donation_data' => $donation_data,
                    'store' => $store,
                    'slug' => $slug,
                    'redirect_url' => $successUrl
                ]
            ]);
        } elseif ($requests_data['donationpayment_method'] == 'Khalti') {
            Session::put('donation_data', $donation_data);
            $successs = route('donation.customer.success', $store->slug);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'donation_data' => $donation_data,
                    'status_code'  => $response['status_code'],
                    'store' => $store,
                    'slug' => $slug,
                    'redirect_url' => $successs
                ]
            ]);
        } else {
            return view('donation::theme.payment_success', compact('donation_data', 'store', 'slug'));
        }
    }

    public function showSuccessPage(Request $request, $slug)
    {
        $requests_data = (Session::get('donation_request_data'));
        $donation_data = (Session::get('donation_data'));
        $store = getStore($slug);
        
        return view('donation::theme.payment_success', compact('store', 'slug', 'donation_data'));
    }
}
