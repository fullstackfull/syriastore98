<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Order;
use App\Models\Plan;
use App\Models\PlanOrder;
use App\Models\PlanCoupon;
use App\Models\Coupon;
use App\Models\PlanUserCoupon;
use Illuminate\Http\Request;
use App\Http\Controllers\Auth;
use App\Http\Controllers\Concerns\GuardsPlanActivation;

class CyberSourceController extends Controller
{
    use GuardsPlanActivation;

    /**
     * THE LAST FOUR DIGITS OF A CARD NUMBER, AND NOTHING ELSE.
     *
     * Used instead of persisting the PAN the browser posts. Non-digits are stripped first so
     * that a number typed with spaces or dashes ("4111 1111 1111 1111") still yields the four
     * digits a receipt needs rather than four characters of formatting.
     *
     * Returns null rather than 0 for an absent or unusable value. The column is nullable, and 0
     * would read like a card ending 0000; the previous expression recorded exactly that for a
     * missing cardNumber. A value shorter than four digits is not a card number, so it is
     * dropped rather than padded -- there is no case where storing part of a too-short number
     * helps anyone.
     */
    private function lastFourDigits($cardNumber): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $cardNumber);

        if ($digits === null || strlen($digits) < 4) {
            return null;
        }

        return substr($digits, -4);
    }

    public function planPayWithCyberSource(Request $request)
    {

        $payment_setting = getSuperAdminAllSetting();
        $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
        $planID = \Illuminate\Support\Facades\Crypt::decrypt($request->plan_id);

        $plan = Plan::find($planID);
        $orderID = strtoupper(str_replace('.', '', uniqid('', true)));
        $user = \Auth::user();
        if ($plan) {
            try {
                $price = $plan->price;
                if (!empty($request->coupon))
                {
                    $coupons = PlanCoupon::whereRaw('BINARY `code` = ?', [$request->coupon])->where('is_active', '1')->first();
                    if ($coupons) {
                        $coupon_code = $coupons->code;
                        $usedCoupun     = $coupons->used_coupon();
                        if ($coupons->limit == $usedCoupun) {
                            $res_data['error'] = __('This coupon code has expired.');
                        } else {
                            $discount_value = ($plan->price / 100) * $coupons->discount;
                            $price  = $price - $discount_value;
                            if ($price < 0) {
                                $price = $plan->price;
                            }
                            $coupon_id = $coupons->id;
                        }
                    }else {
                        return redirect()->back()->with('error', __('This coupon code is invalid or has expired.'));
                    }
                }
                $name = \Auth::user()->name;
                $callback_url = 'plan.get.cybersource.status';
                $data = [
                    'id' =>  $plan->id,
                    'amount' =>  $price,
                    'duration' =>  $plan->duration,
                    'coupon_code' =>  $request->coupon,
                ];
                $data  =    json_encode($data);
                try {
                    return view('plans.cybersourcerequest', compact('callback_url', 'price', 'data','name'));
                } catch (\Exception $e) {
                    \Log::error($e->getMessage());
                }
            } catch (\Exception $e) {
                return redirect()->route('plan.index')->with('error', __($e->getMessage()));
            }
        } else {
            return redirect()->route('plan.index')->with('error', __('Plan is deleted.'));
        }
    }

    public function planPayWithCyberSourceData(Request $request)
    {
        $input          = $request->all();
        $admin_settings = getSuperAdminAllSetting();
        $data           = json_decode($input['data'], true);
        $duration       =  $data['duration'];
        $coupon_code    = $data['coupon_code'];

        $plan           = Plan::find($data['id']);

        /*
         * F230 -- A PLAN THAT NAMES NOTHING MUST NOT REACH THE CARD NETWORK.
         *
         * `$data` is `json_decode()` of ONE PLAINTEXT, UNSIGNED hidden field
         * (`plans/cybersourcerequest.blade.php:35`), so `$data['id']` is whatever the caller sends.
         * An id matching no row left `$plan` null, and the handler then dereferenced `$plan->id`
         * while building the order -- i.e. a fatal AFTER the card had already been charged. There
         * is no correct amount to charge for a plan that does not exist, so the refusal belongs
         * ahead of the outbound call.
         *
         * 403 and not 401: `lib/network/network.dart` turns any 401 into a forced logout.
         */
        if (empty($plan)) {
            \Illuminate\Support\Facades\Log::warning('CyberSource plan purchase refused: the posted plan id matches no plan.', [
                'user_id' => optional(\Auth::user())->id,
                'controller' => static::class,
            ]);

            abort(403, __('This payment method is not available for subscription purchases.'));
        }

        /*
         * F230 -- THE CHARGE IS THE SERVER'S FIGURE, NOT THE CALLER'S. THIS IS THE DEFECT.
         *
         * `$amount` used to be `$data['amount']`, read from that same unsigned field, and it was
         * sent outbound as `orderInformation.amountDetails.totalAmount`. The handler then recorded
         * `serverOwnedPlanPrice()` further down -- so the CHARGE was the caller's and the RECORD
         * was the server's, decoupled by construction. Paying 1 for a 499 plan wrote 499 to
         * `plan_orders`, and the underpayment left no trace anywhere.
         *
         * NOT THE F229 UNIT QUESTION, and the distinction is why this needs no owner decision.
         * D-31 forbids guessing a provider's minor/major convention when COMPARING an amount the
         * provider reported. Nothing is compared here: the handler is deciding what to SEND, and
         * it already computes the right figure for the row it writes. Using one value for both
         * involves no provider convention at all. It is the rule D-17 settled for Paystack and the
         * rule F229's own row restates -- never read the payable amount from the caller.
         *
         * The same expression is reused at the recording site, so the two cannot drift apart.
         */
        $amount         = $this->serverOwnedPlanPrice($plan, $coupon_code);
        $authuser       = \Auth::user();
        $orderID        = strtoupper(str_replace('.', '', uniqid('', true)));
        $admin_currancy = !empty($admin_settings['defult_currancy']) ? $admin_settings['defult_currancy'] : 'USD';
        $apiKey = isset($admin_settings['cybersource_pay_api_key']) ? $admin_settings['cybersource_pay_api_key'] : '';
        $merchantId = isset($admin_settings['cybersource_pay_merchant_id']) ? $admin_settings['cybersource_pay_merchant_id'] : '';
        $apiSecret = isset($admin_settings['cybersource_pay_secret_key']) ? $admin_settings['cybersource_pay_secret_key'] : '';

        try {

            $apiEndpoint = 'https://apitest.cybersource.com/pts/v2/payments';

            $requestData = [
                "clientReferenceInformation" => [
                    "code" => time(),
                ],
                "paymentInformation" => [
                    "card" => [
                        "number" => !empty($input['cardNumber']) ? $input['cardNumber'] : 0,
                        "expirationMonth" => !empty($input['month']) ? $input['month'] : 0,
                        "expirationYear" => !empty($input['year']) ? $input['year'] : 0,
                    ],
                ],
                "orderInformation" => [
                    "amountDetails" => [
                        "totalAmount" => $amount,
                        "currency" => $admin_currancy,
                    ],

                    "billTo" => [
                        "firstName" => $authuser->name,
                        "lastName" => $request->last_name,
                        "address1" => $request->address,
                        "locality" => $request->locality,
                        "administrativeArea" => $request->administrativearea,
                        "postalCode" => $request->postalcode,
                        "country" => $request->country,
                        "email" => $authuser->email,
                        "phoneNumber" => $request->phone_no
                    ],
                ],
            ];

            $vCDate = gmdate('D, d M Y H:i:s T');
            $digest = 'SHA-256=' . base64_encode(hash('sha256', json_encode($requestData), true));
            $signatureString = '(request-target): post /pts/v2/payments' . "\n" .
                'host: apitest.cybersource.com' . "\n" .
                'digest: ' . $digest . "\n" .
                'v-c-merchant-id: ' . $merchantId;
            $signature = base64_encode(hash_hmac('sha256', $signatureString, base64_decode(trim($apiSecret)), true));

            // Headers
            $headers = [
                'host' => 'apitest.cybersource.com',
                'signature' => 'keyid="' . trim($apiKey) . '", algorithm="HmacSHA256", headers="(request-target) host digest v-c-merchant-id", signature="' . trim($signature) . '"',
                'digest' => $digest,
                'v-c-merchant-id' => trim($merchantId),
                'v-c-date' => $vCDate,
                'Content-Type' => 'application/json',
            ];

            // Make the API request
            $response = \Http::withHeaders($headers)->post($apiEndpoint, $requestData);

            if ($response->successful()) {
                $result = $response->json();
                if ($result['status'] == "AUTHORIZED") {

                    $order = PlanOrder::create([
                        'order_id'          => $orderID,
                        'name'              => !empty($input['owner']) ? $input['owner'] : 0,
                        'email'             => null,
                        // LAST FOUR DIGITS ONLY. This was the raw PAN the browser posted
                        // (`$input['cardNumber']`), written into a column declared
                        // `$table->string('card_number', 10)` at
                        // database/migrations/2023_12_11_102124_create_plan_orders_table.php:19
                        // -- so a 16-digit PAN errors outright under MySQL strict mode and is
                        // SILENTLY TRUNCATED to its first 10 digits otherwise. Truncation is the
                        // worse of the two: the leading digits are the issuer identification
                        // number and the start of the account, and the row is still cardholder
                        // data at rest with none of the obligations that come with holding it.
                        //
                        // The last four is what a receipt needs and what the column can hold --
                        // the same thing StripePaymentController:140 already records. The
                        // OUTBOUND call at :102 is untouched: CyberSource must receive the full
                        // number, and that request is this provider's verification (it requires
                        // $result['status'] == "AUTHORIZED" above), so nothing about the payment
                        // architecture changes here. Nothing reads card_number except the
                        // receipt views.
                        'card_number'       => $this->lastFourDigits($input['cardNumber'] ?? null),
                        'card_exp_month'    => !empty($input['month']) ? $input['month'] : 0,
                        'card_exp_year'     => !empty($input['year']) ? $input['year'] : 0,
                        'plan_name'         => !empty($plan->name) ? $plan->name : 'Basic Package',
                        'plan_id'           => $plan->id,
                        // Server-owned: was `$amount`, taken from
                        // json_decode($input['data'])['amount'] -- i.e. from the request body.
                        //
                        // CyberSource IS verified on this path: the handler posts to
                        // apitest.cybersource.com/pts/v2/payments and requires
                        // $result['status'] == "AUTHORIZED" above, so a forged return cannot
                        // activate a plan and this provider is NOT on the config/payments.php
                        // map. Only the RECORDED price changes, which is why no
                        // amount-mismatch refusal is added -- see GuardsPlanActivation.
                        //
                        // This handler was also missing from the plan-purchase census, because
                        // it writes through PlanOrder::create([...]) rather than $order->price.
                        'price'             => $this->serverOwnedPlanPrice($plan, $coupon_code),
                        'price_currency'    => $admin_currancy,
                        'txn_id'            => time(),
                        'payment_type'      => __('Cybersource'),
                        'payment_status'    => 'Succeeded',
                        'receipt'           => null,
                        'user_id'           => $authuser->id,
                    ]);

                    if (isset($coupon_code) && !empty($coupon_code)) {

                        $coupons = PlanCoupon::whereRaw('BINARY `code` = ?', [$coupon_code])->where('is_active', '1')->first();
                        if (!empty($coupons)) {
                            $userCoupon         = new PlanUserCoupon();
                            $userCoupon->user_id   = $authuser->id;
                            $userCoupon->coupon_id = $coupons->id;
                            $userCoupon->order  = $order->order_id;
                            $userCoupon->save();
                            $usedCoupun = $coupons->used_coupon();
                            if ($coupons->limit <= $usedCoupun) {
                                $coupons->is_active = 0;
                                $coupons->save();
                            }
                        }
                    }

                    $assignPlan = $authuser->assignPlan($plan->id);

                    if ($assignPlan['is_success']) {
                        return redirect()->route('plan.index')->with('success', __('Plan activated Successfully!'));
                    } else {
                        return redirect()->route('plan.index')->with('error', __($assignPlan['error']));
                    }
                }
            } else {
                return redirect()->route('plan.index')->with('error', __('Your Transaction is fail please try again'));
            }
        } catch (\Exception $e) {
            return redirect()->route('plan.index')->with('error', __('something Went wrong!'));
        }
    }

    public function storePayWithCyberSourceData(Request $request)
    {
        $input          = $request->all();
        $data           = json_decode($input['data'], true);
        $amount         =  $data['amount'];
        $slug = $data['slug'];
        $authuser       = $data['user'];
        $id         =  $data['store_id'];
        $admin_currancy  = !empty(\App\Models\Utility::GetValueByName('defult_currancy',$id)) ?  \App\Models\Utility::GetValueByName('defult_currancy',$id) : '$';
        $apiKey        = ( \App\Models\Utility::GetValueByName('cybersource_api_key',$id)) ?  \App\Models\Utility::GetValueByName('cybersource_api_key',$id) : '';
        $merchantId     = ( \App\Models\Utility::GetValueByName('cybersource_merchant_id',$id)) ?  \App\Models\Utility::GetValueByName('cybersource_merchant_id',$id) : '';
        $apiSecret  =  ( \App\Models\Utility::GetValueByName('cybersource_secret_key',$id)) ?  \App\Models\Utility::GetValueByName('cybersource_secret_key',$id) : 'sandbox';


        try {

            $apiEndpoint = 'https://apitest.cybersource.com/pts/v2/payments';

            $requestData = [
                "clientReferenceInformation" => [
                    "code" => time(),
                ],
                "paymentInformation" => [
                    "card" => [
                        "number" => !empty($input['cardNumber']) ? $input['cardNumber'] : 0,
                        "expirationMonth" => !empty($input['month']) ? $input['month'] : 0,
                        "expirationYear" => !empty($input['year']) ? $input['year'] : 0,
                    ],
                ],
                "orderInformation" => [
                    "amountDetails" => [
                        "totalAmount" => $amount,
                        "currency" => $admin_currancy,
                    ],

                    "billTo" => [
                        "firstName" => $authuser['name'],
                        "lastName" => $request->last_name,
                        "address1" => $request->address,
                        "locality" => $request->locality,
                        "administrativeArea" => $request->administrativearea,
                        "postalCode" => $request->postalcode,
                        "country" => $request->country,
                        "email" => $authuser['email'],
                        "phoneNumber" => $request->phone_no
                    ],
                ],
            ];

            $vCDate = gmdate('D, d M Y H:i:s T');
            $digest = 'SHA-256=' . base64_encode(hash('sha256', json_encode($requestData), true));
            $signatureString = '(request-target): post /pts/v2/payments' . "\n" .
                'host: apitest.cybersource.com' . "\n" .
                'digest: ' . $digest . "\n" .
                'v-c-merchant-id: ' . $merchantId;
            $signature = base64_encode(hash_hmac('sha256', $signatureString, base64_decode($apiSecret), true));

            // Headers
            $headers = [
                'host' => 'apitest.cybersource.com',
                'signature' => 'keyid="' . $apiKey . '", algorithm="HmacSHA256", headers="(request-target) host digest v-c-merchant-id", signature="' . $signature . '"',
                'digest' => $digest,
                'v-c-merchant-id' => $merchantId,
                'v-c-date' => $vCDate,
                'Content-Type' => 'application/json',
            ];

            // Make the API request
            $response = \Http::withHeaders($headers)->post($apiEndpoint, $requestData);
            return redirect()->route('store.payment.status',$slug);
        } catch (\Exception $e) {
            return redirect()->route('checkout',$slug)->with('error', __('something Went wrong!'));
        }
    }
}
