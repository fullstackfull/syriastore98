<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Model;
use App\Payments\RetiredGateways;

class Setting extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'name', 'value',  'created_by', 'store_id'
    ];

    public static function paymentList($slug) 
    {
        $store = getStore($slug);
        if (!$store) {
            abort(404);
        }
        // COD
        $is_cod_enabled = Utility::GetValueByName('is_cod_enabled', $store->id);
        $cod_info = Utility::GetValueByName('cod_info', $store->id);
        $cod_image = Utility::GetValueByName('cod_image', $store->id);
        if (empty($cod_image)) {
            $cod_images = asset(Storage::url('uploads/payment/cod.png'));
        }
        $Setting_array[0]['status'] = (!empty($is_cod_enabled) && $is_cod_enabled == 'on') ? 'on' : 'off';
        $Setting_array[0]['name_string'] = __('COD');
        $Setting_array[0]['name'] = 'cod';
        if (!empty($cod_images)) {
            $Setting_array[0]['image'] = $cod_images;
        } else {
            $Setting_array[0]['image'] = $cod_image;
        }
        $Setting_array[0]['detail'] = $cod_info;

        // Bank Transfer
        $bank_transfer_info = Utility::GetValueByName('bank_transfer', $store->id);
        $is_bank_transfer_enabled = Utility::GetValueByName('is_bank_transfer_enabled', $store->id);
        $bank_transfer_image = Utility::GetValueByName('bank_transfer_image', $store->id);
        if (empty($bank_transfer_image)) {
            $bank_transfer_images = asset(Storage::url('uploads/payment/bank.png'));
        }
        $Setting_array[1]['status'] = (!empty($is_bank_transfer_enabled) && $is_bank_transfer_enabled == 'on') ? 'on' : 'off';
        $Setting_array[1]['name_string'] = __('Bank Transfer');
        $Setting_array[1]['name'] = 'bank_transfer';
        if (!empty($bank_transfer_images)) {
            $Setting_array[1]['image'] = $bank_transfer_images;
        } else {
            $Setting_array[1]['image'] = $bank_transfer_image;
        }
        $Setting_array[1]['detail'] = !empty($bank_transfer_info) ? $bank_transfer_info : '';

        $Setting_array[2]['status'] = 'off';
        $Setting_array[2]['name_string'] = __('Other Payment');
        $Setting_array[2]['name'] = 'other_payment';
        $Setting_array[2]['image'] = '';
        $Setting_array[2]['detail'] = '';

        // Stripe ( Creadit card )
        $is_Stripe_enabled = Utility::GetValueByName('is_stripe_enabled', $store->id);
        $publishable_key = Utility::GetValueByName('publishable_key', $store->id);
        $stripe_secret = Utility::GetValueByName('stripe_secret', $store->id);
        $Stripe_image = Utility::GetValueByName('stripe_image', $store->id);
        if (empty($Stripe_image)) {
            $Stripe_image = asset(Storage::url('uploads/payment/stripe.png'));
        }
        $stripe_unfo = Utility::GetValueByName('stripe_unfo', $store->id);

        $Setting_array[3]['status'] = !empty($is_Stripe_enabled) ? $is_Stripe_enabled : 'off';
        $Setting_array[3]['name_string'] = __('Stripe');
        $Setting_array[3]['name'] = 'stripe';
        $Setting_array[3]['detail'] = $stripe_unfo;
        $Setting_array[3]['image'] = $Stripe_image;
        $Setting_array[3]['stripe_publishable_key'] = $publishable_key;
        $Setting_array[3]['stripe_secret_key'] = $stripe_secret;

        // Paystack
        $is_paystack_enabled = Utility::GetValueByName('is_paystack_enabled', $store->id);
        $paystack_public_key = Utility::GetValueByName('paystack_public_key', $store->id);
        $paystack_secret = Utility::GetValueByName('paystack_secret', $store->id);
        $paystack_image = Utility::GetValueByName('paystack_image', $store->id);
        if (empty($paystack_image)) {
            $paystack_image = asset(Storage::url('uploads/payment/paystack.png'));
        }
        $paystack_unfo = Utility::GetValueByName('paystack_unfo', $store->id);

        $Setting_array[4]['status'] = !empty($is_paystack_enabled) ? $is_paystack_enabled : 'off';
        $Setting_array[4]['name_string'] = __('Paystack');
        $Setting_array[4]['name'] = 'paystack';
        $Setting_array[4]['detail'] = $paystack_unfo;
        $Setting_array[4]['image'] = $paystack_image;
        $Setting_array[4]['paystack_public_key'] = $paystack_public_key;
        $Setting_array[4]['paystack_secret'] = $paystack_secret;

        // Mercado Pago
        $is_mercado_enabled = Utility::GetValueByName('is_mercado_enabled', $store->id);
        $mercado_mode = Utility::GetValueByName('mercado_mode', $store->id);
        $mercado_access_token = Utility::GetValueByName('mercado_access_token', $store->id);
        $mercado_image = Utility::GetValueByName('mercado_image', $store->id);
        if (empty($mercado_image)) {
            $mercado_image = asset(Storage::url('uploads/payment/mercado.png'));
        }
        $mercado_unfo = Utility::GetValueByName('mercado_unfo', $store->id);

        $Setting_array[5]['status'] = !empty($is_mercado_enabled) ? $is_mercado_enabled : 'off';
        $Setting_array[5]['name_string'] = __('Mercado Pago');
        $Setting_array[5]['name'] = 'mercado';
        $Setting_array[5]['detail'] = $mercado_unfo;
        $Setting_array[5]['image'] = $mercado_image;
        $Setting_array[5]['mercado_mode'] = $mercado_mode;
        $Setting_array[5]['mercado_access_token'] = $mercado_access_token;

        // Skrill
        $is_skrill_enabled = Utility::GetValueByName('is_skrill_enabled', $store->id);
        $skrill_email = Utility::GetValueByName('skrill_email', $store->id);
        $skrill_image = Utility::GetValueByName('skrill_image', $store->id);
        if (empty($skrill_image)) {
            $skrill_image = asset(Storage::url('uploads/payment/skrill.png'));
        }
        $skrill_unfo = Utility::GetValueByName('skrill_unfo');

        $Setting_array[6]['status'] = !empty($is_skrill_enabled) ? $is_skrill_enabled : 'off';
        $Setting_array[6]['name_string'] = __('Skrill');
        $Setting_array[6]['name'] = 'skrill';
        $Setting_array[6]['detail'] = $skrill_unfo;
        $Setting_array[6]['image'] = $skrill_image;
        $Setting_array[6]['skrill_email'] = $skrill_email;
        // PaymentWall
        $is_paymentwall_enabled = Utility::GetValueByName('is_paymentwall_enabled', $store->id);
        $paymentwall_public_key = Utility::GetValueByName('paymentwall_public_key', $store->id);
        $paymentwall_private_key = Utility::GetValueByName('paymentwall_private_key', $store->id);
        $paymentwall_image = Utility::GetValueByName('paymentwall_image', $store->id);
        if (empty($paymentwall_image)) {
            $paymentwall_image = asset(Storage::url('uploads/payment/paymentwall.png'));
        }
        $paymentwall_unfo = Utility::GetValueByName('paymentwall_unfo', $store->id);

        $Setting_array[7]['status'] = !empty($is_paymentwall_enabled) ? $is_paymentwall_enabled : 'off';
        $Setting_array[7]['name_string'] = __('PaymentWall');
        $Setting_array[7]['name'] = 'paymentwall';
        $Setting_array[7]['detail'] = $paymentwall_unfo;
        $Setting_array[7]['image'] = $paymentwall_image;
        $Setting_array[7]['paymentwall_public_key'] = $paymentwall_public_key;
        $Setting_array[7]['paymentwall_private_key'] = $paymentwall_private_key;

        // Razorpay
        $is_razorpay_enabled = \App\Models\Utility::GetValueByName('is_razorpay_enabled', $store->id);
        $razorpay_public_key = \App\Models\Utility::GetValueByName('razorpay_public_key', $store->id);
        $razorpay_secret_key = \App\Models\Utility::GetValueByName('razorpay_secret_key', $store->id);
        $razorpay_image = \App\Models\Utility::GetValueByName('razorpay_image', $store->id);

        if (empty($razorpay_image)) {
            $razorpay_image = asset(Storage::url('uploads/payment/razorpay.png'));
        }
        $razorpay_unfo = Utility::GetValueByName('razorpay_unfo', $store->id);

        $Setting_array[8]['status'] = !empty($is_razorpay_enabled) ? $is_razorpay_enabled : 'off';
        $Setting_array[8]['name_string'] = __('Razorpay');
        $Setting_array[8]['name'] = 'Razorpay';
        $Setting_array[8]['detail'] = $razorpay_unfo;
        $Setting_array[8]['image'] = $razorpay_image;
        $Setting_array[8]['razorpay_public_key'] = $razorpay_public_key;
        $Setting_array[8]['razorpay_secret_key'] = $razorpay_secret_key;

        //paypal
        $is_paypal_enabled = Utility::GetValueByName('is_paypal_enabled', $store->id);
        $paypal_secret = Utility::GetValueByName('paypal_secret', $store->id);
        $paypal_client_id = Utility::GetValueByName('paypal_client_id', $store->id);
        $paypal_mode = Utility::GetValueByName('paypal_mode', $store->id);
        $paypal_description = Utility::GetValueByName('paypal_unfo', $store->id);
        $paypal_image = Utility::GetValueByName('paypal_image', $store->id);

        if (empty($paypal_image)) {
            $paypal_image = asset(Storage::url('uploads/payment/paypal.png'));
        }

        $Setting_array[9]['status'] = !empty($is_paypal_enabled) ? $is_paypal_enabled : 'off';
        $Setting_array[9]['name_string'] = __('Paypal');
        $Setting_array[9]['name'] = 'paypal';
        $Setting_array[9]['detail'] = $paypal_description;
        $Setting_array[9]['image'] = $paypal_image;
        $Setting_array[9]['paypal_secret'] = $paypal_secret;
        $Setting_array[9]['paypal_client_id'] = $paypal_client_id;
        $Setting_array[9]['paypal_mode'] = $paypal_mode;

        //flutterwave
        $is_flutterwave_enabled = \App\Models\Utility::GetValueByName('is_flutterwave_enabled', $store->id);
        $public_key = \App\Models\Utility::GetValueByName('public_key', $store->id);
        $flutterwave_secret = \App\Models\Utility::GetValueByName('flutterwave_secret', $store->id);
        $flutterwave_description = Utility::GetValueByName('flutterwave_unfo', $store->id);
        $flutterwave_image = \App\Models\Utility::GetValueByName('flutterwave_image', $store->id);

        if (empty($flutterwave_image)) {
            $flutterwave_image = asset(Storage::url('uploads/payment/flutterwave.png'));
        }

        $Setting_array[10]['status'] = !empty($is_flutterwave_enabled) ? $is_flutterwave_enabled : 'off';
        $Setting_array[10]['name_string'] = __('Flutterwave');
        $Setting_array[10]['name'] = 'flutterwave';
        $Setting_array[10]['detail'] = $flutterwave_description;
        $Setting_array[10]['image'] = $flutterwave_image;
        $Setting_array[10]['public_key'] = $public_key;
        $Setting_array[10]['flutterwave_secret'] = $flutterwave_secret;
        $Setting_array[10]['flutterwave_image'] = $flutterwave_image;

        //paytm
        $is_paytm_enabled = Utility::GetValueByName('is_paytm_enabled', $store->id);
        $paytm_merchant_id = Utility::GetValueByName('paytm_merchant_id', $store->id);
        $paytm_merchant_key = Utility::GetValueByName('paytm_merchant_key', $store->id);
        $paytm_industry_type = Utility::GetValueByName('paytm_industry_type', $store->id);
        $paytm_mode = Utility::GetValueByName('paytm_mode', $store->id);
        $payptm_description = Utility::GetValueByName('paytm_unfo', $store->id);
        $paytm_image = Utility::GetValueByName('paytm_image', $store->id);

        if (empty($paytm_image)) {
            $paytm_image = asset(Storage::url('uploads/payment/paytm.png'));
        }

        $Setting_array[11]['status'] = !empty($is_paytm_enabled) ? $is_paytm_enabled : 'off';
        $Setting_array[11]['name_string'] = __('Paytm');
        $Setting_array[11]['name'] = 'paytm';
        $Setting_array[11]['detail'] = $payptm_description;
        $Setting_array[11]['image'] = $paytm_image;
        $Setting_array[11]['paytm_merchant_id'] = $paytm_merchant_id;
        $Setting_array[11]['paytm_merchant_key'] = $paytm_merchant_key;
        $Setting_array[11]['paytm_industry_type'] = $paytm_industry_type;
        $Setting_array[11]['paytm_mode'] = $paytm_mode;

        //mollie
        $is_mollie_enabled = Utility::GetValueByName('is_mollie_enabled', $store->id);
        $mollie_api_key = Utility::GetValueByName('mollie_api_key', $store->id);
        $mollie_profile_id = Utility::GetValueByName('mollie_profile_id', $store->id);
        $mollie_partner_id = Utility::GetValueByName('mollie_partner_id', $store->id);
        $mollie_unfo = Utility::GetValueByName('mollie_unfo', $store->id);
        $mollie_image = Utility::GetValueByName('mollie_image', $store->id);

        if (empty($mollie_image)) {
            $mollie_image = asset(Storage::url('uploads/payment/mollie.png'));
        }

        $Setting_array[12]['status'] = !empty($is_mollie_enabled) ? $is_mollie_enabled : 'off';
        $Setting_array[12]['name_string'] = __('Mollie');
        $Setting_array[12]['name'] = 'mollie';
        $Setting_array[12]['detail'] = $mollie_unfo;
        $Setting_array[12]['image'] = $mollie_image;
        $Setting_array[12]['mollie_api_key'] = $mollie_api_key;
        $Setting_array[12]['mollie_profile_id'] = $mollie_profile_id;
        $Setting_array[12]['mollie_partner_id'] = $mollie_partner_id;

        //coingate
        $is_coingate_enabled = Utility::GetValueByName('is_coingate_enabled', $store->id);
        $coingate_mode = Utility::GetValueByName('coingate_mode', $store->id);
        $coingate_auth_token = Utility::GetValueByName('coingate_auth_token', $store->id);
        $coingate_image = Utility::GetValueByName('coingate_image', $store->id);
        $coingate_unfo = Utility::GetValueByName('coingate_unfo', $store->id);

        if (empty($coingate_image)) {
            $coingate_image = asset(Storage::url('uploads/payment/coingate.png'));
        }

        $Setting_array[13]['status'] = !empty($is_coingate_enabled) ? $is_coingate_enabled : 'off';
        $Setting_array[13]['name_string'] = __('Coingate');
        $Setting_array[13]['name'] = 'coingate';
        $Setting_array[13]['detail'] = $coingate_unfo;
        $Setting_array[13]['image'] = $coingate_image;
        $Setting_array[13]['coingate_mode'] = $coingate_mode;
        $Setting_array[13]['coingate_auth_token'] = $coingate_auth_token;

        //sspay
        $is_sspay_enabled = Utility::GetValueByName('is_sspay_enabled', $store->id);
        $categoryCode = Utility::GetValueByName('sspay_category_code', $store->id);
        $secretKey = Utility::GetValueByName('is_sspay_enabled', $store->id);
        $sspay_image = Utility::GetValueByName('sspay_image', $store->id);
        $sspay_unfo = Utility::GetValueByName('sspay_unfo', $store->id);

        if (empty($sspay_image)) {
            $sspay_image = asset(Storage::url('uploads/payment/sspay.png'));
        }

        $Setting_array[14]['status'] = !empty($is_sspay_enabled) ? $is_sspay_enabled : 'off';
        $Setting_array[14]['name_string'] = __('Sspay');
        $Setting_array[14]['name'] = 'Sspay';
        $Setting_array[14]['detail'] = $sspay_unfo;
        $Setting_array[14]['image'] = $sspay_image;
        $Setting_array[14]['categoryCode'] = $categoryCode;
        $Setting_array[14]['secretKey'] = $secretKey;

        //toyyibpay
        $is_toyyibpay_enabled = Utility::GetValueByName('is_toyyibpay_enabled', $store->id);
        $categoryCode = Utility::GetValueByName('toyyibpay_category_code', $store->id);
        $secretKey = Utility::GetValueByName('is_toyyibpay_enabled', $store->id);
        $toyyibpay_image = Utility::GetValueByName('toyyibpay_image', $store->id);
        $toyyibpay_unfo = Utility::GetValueByName('toyyibpay_unfo', $store->id);

        if (empty($toyyibpay_image)) {
            $toyyibpay_image = asset(Storage::url('uploads/payment/toyyibpay.png'));
        }

        $Setting_array[15]['status'] = !empty($is_toyyibpay_enabled) ? $is_toyyibpay_enabled : 'off';
        $Setting_array[15]['name_string'] = __('Toyyibpay');
        $Setting_array[15]['name'] = 'toyyibpay';
        $Setting_array[15]['detail'] = $toyyibpay_unfo;
        $Setting_array[15]['image'] = $toyyibpay_image;
        $Setting_array[15]['categoryCode'] = $categoryCode;
        $Setting_array[15]['secretKey'] = $secretKey;

        //paytabs
        $is_paytabs_enabled = Utility::GetValueByName('is_paytabs_enabled', $store->id);
        $Profile_id = Utility::GetValueByName('paytabs_profile_id', $store->id);
        $Serverkey = Utility::GetValueByName('paytabs_server_key', $store->id);
        $Region = Utility::GetValueByName('paytabs_region', $store->id);
        $paytabs_image = Utility::GetValueByName('paytabs_image', $store->id);
        $paytabs_unfo = Utility::GetValueByName('paytabs_unfo', $store->id);

        if (empty($paytabs_image)) {
            $paytabs_image = asset(Storage::url('uploads/payment/paytabs.png'));
        }

        $Setting_array[16]['status'] = !empty($is_paytabs_enabled) ? $is_paytabs_enabled : 'off';
        $Setting_array[16]['name_string'] = __('Paytab');
        $Setting_array[16]['name'] = 'Paytabs';
        $Setting_array[16]['detail'] = $paytabs_unfo;
        $Setting_array[16]['image'] = $paytabs_image;
        $Setting_array[16]['paytabs_profile_id'] = $Profile_id;
        $Setting_array[16]['paytabs_server_key'] = $Serverkey;
        $Setting_array[16]['paytabs_region'] = $Region;

        //Iyzipay
        $is_iyzipay_enabled = Utility::GetValueByName('is_iyzipay_enabled', $store->id);
        $iyzipay_mode = Utility::GetValueByName('iyzipay_mode', $store->id);
        $iyzipay_secret_key = Utility::GetValueByName('iyzipay_secret_key', $store->id);
        $iyzipay_private_key = Utility::GetValueByName('iyzipay_private_key', $store->id);
        $iyzipay_image = Utility::GetValueByName('iyzipay_image', $store->id);
        $iyzipay_unfo = Utility::GetValueByName('iyzipay_unfo', $store->id);

        if (empty($iyzipay_image)) {
            $iyzipay_image = asset(Storage::url('uploads/payment/iyzipay.png'));
        }

        $Setting_array[17]['status'] = !empty($is_iyzipay_enabled) ? $is_iyzipay_enabled : 'off';
        $Setting_array[17]['name_string'] = __('IyziPay');
        $Setting_array[17]['name'] = 'iyzipay';
        $Setting_array[17]['detail'] = $iyzipay_unfo;
        $Setting_array[17]['image'] = $iyzipay_image;
        $Setting_array[17]['iyzipay_mode'] = $iyzipay_mode;
        $Setting_array[17]['iyzipay_secret_key'] = $iyzipay_secret_key;
        $Setting_array[17]['iyzipay_private_key'] = $iyzipay_private_key;

        //payfast
        $is_payfast_enabled = Utility::GetValueByName('is_payfast_enabled', $store->id);
        $payfast_mode = Utility::GetValueByName('payfast_mode', $store->id);
        $payfast_merchant_id = Utility::GetValueByName('payfast_merchant_id', $store->id);
        $payfast_salt_passphrase = Utility::GetValueByName('payfast_salt_passphrase', $store->id);
        $payfast_merchant_key = Utility::GetValueByName('payfast_merchant_key', $store->id);
        $payfast_image = Utility::GetValueByName('payfast_image', $store->id);
        $payfast_unfo = Utility::GetValueByName('payfast_unfo', $store->id);

        if (empty($payfast_image)) {
            $payfast_image = asset(Storage::url('uploads/payment/payfast.png'));
        }

        $Setting_array[18]['status'] = !empty($is_payfast_enabled) ? $is_payfast_enabled : 'off';
        $Setting_array[18]['name_string'] = __('PayFast');
        $Setting_array[18]['name'] = 'payfast';
        $Setting_array[18]['detail'] = $payfast_unfo;
        $Setting_array[18]['image'] = $payfast_image;
        $Setting_array[18]['payfast_mode'] = $payfast_mode;
        $Setting_array[18]['payfast_merchant_id'] = $payfast_merchant_id;
        $Setting_array[18]['payfast_salt_passphrase'] = $payfast_salt_passphrase;
        $Setting_array[18]['payfast_merchant_key'] = $payfast_merchant_key;

        //Benefit
        $is_benefit_enabled = Utility::GetValueByName('is_benefit_enabled', $store->id);
        $benefit_mode = Utility::GetValueByName('benefit_mode', $store->id);
        $benefit_secret_key = Utility::GetValueByName('benefit_secret_key', $store->id);
        $benefit_private_key = Utility::GetValueByName('benefit_private_key', $store->id);
        $benefit_image = Utility::GetValueByName('benefit_image', $store->id);
        $benefit_unfo = Utility::GetValueByName('benefit_unfo', $store->id);

        if (empty($benefit_image)) {
            $benefit_image = asset(Storage::url('uploads/payment/benefit.png'));
        }

        $Setting_array[19]['status'] = !empty($is_benefit_enabled) ? $is_benefit_enabled : 'off';
        $Setting_array[19]['name_string'] = __('Benefit');
        $Setting_array[19]['name'] = 'benefit';
        $Setting_array[19]['detail'] = $benefit_unfo;
        $Setting_array[19]['image'] = $benefit_image;
        $Setting_array[19]['benefit_mode'] = $benefit_mode;
        $Setting_array[19]['benefit_secret_key'] = $benefit_secret_key;
        $Setting_array[19]['benefit_private_key'] = $benefit_private_key;

        //Cashfree
        $is_cashfree_enabled = Utility::GetValueByName('is_cashfree_enabled', $store->id);
        $cashfree_secret_key = Utility::GetValueByName('cashfree_secret_key', $store->id);
        $cashfree_key = Utility::GetValueByName('cashfree_key', $store->id);
        $cashfree_image = Utility::GetValueByName('cashfree_image', $store->id);
        $cashfree_unfo = Utility::GetValueByName('cashfree_unfo', $store->id);

        if (empty($cashfree_image)) {
            $cashfree_image = asset(Storage::url('uploads/payment/cashfree.png'));
        }

        $Setting_array[20]['status'] = !empty($is_cashfree_enabled) ? $is_cashfree_enabled : 'off';
        $Setting_array[20]['name_string'] = __('Cashfree');
        $Setting_array[20]['name'] = 'cashfree';
        $Setting_array[20]['detail'] = $cashfree_unfo;
        $Setting_array[20]['image'] = $cashfree_image;
        $Setting_array[20]['cashfree_secret_key'] = $cashfree_secret_key;
        $Setting_array[20]['cashfree_key'] = $cashfree_key;

        //Aamarpay
        $is_aamarpay_enabled = Utility::GetValueByName('is_aamarpay_enabled', $store->id);
        $aamarpay_signature_key = Utility::GetValueByName('aamarpay_signature_key', $store->id);
        $aamarpay_description = Utility::GetValueByName('aamarpay_description', $store->id);
        $aamarpay_store_id = Utility::GetValueByName('aamarpay_store_id', $store->id);
        $aamarpay_image = Utility::GetValueByName('aamarpay_image', $store->id);
        $aamarpay_unfo = Utility::GetValueByName('aamarpay_unfo', $store->id);

        if (empty($aamarpay_image)) {
            $aamarpay_image = asset(Storage::url('uploads/payment/aamarpay.png'));
        }

        $Setting_array[21]['status'] = !empty($is_aamarpay_enabled) ? $is_aamarpay_enabled : 'off';
        $Setting_array[21]['name_string'] = __('Aamarpay');
        $Setting_array[21]['name'] = 'aamarpay';
        $Setting_array[21]['detail'] = $aamarpay_unfo;
        $Setting_array[21]['image'] = $aamarpay_image;
        $Setting_array[21]['aamarpay_signature_key'] = $aamarpay_signature_key;
        $Setting_array[21]['aamarpay_description'] = $aamarpay_description;
        $Setting_array[21]['aamarpay_store_id'] = $aamarpay_store_id;

        //Telegram
        $is_telegram_enabled = Utility::GetValueByName('is_telegram_enabled', $store->id);
        $telegram_access_token = Utility::GetValueByName('telegram_access_token', $store->id);
        $telegram_chat_id = Utility::GetValueByName('telegram_chat_id', $store->id);
        $telegram_image = Utility::GetValueByName('telegram_image', $store->id);
        $telegram_unfo = Utility::GetValueByName('telegram_unfo', $store->id);

        if (empty($telegram_image)) {
            $telegram_image = asset(Storage::url('uploads/payment/telegram.png'));
        }

        $Setting_array[22]['status'] = !empty($is_telegram_enabled) ? $is_telegram_enabled : 'off';
        $Setting_array[22]['name_string'] = __('Telegram');
        $Setting_array[22]['name'] = 'telegram';
        $Setting_array[22]['detail'] = $telegram_unfo;
        $Setting_array[22]['image'] = $telegram_image;
        $Setting_array[22]['telegram_access_token'] = $telegram_access_token;
        $Setting_array[22]['telegram_chat_id'] = $telegram_chat_id;

        //Whatsapp
        $is_whatsapp_enabled = Utility::GetValueByName('is_whatsapp_enabled', $store->id);
        $whatsapp_number = Utility::GetValueByName('whatsapp_number', $store->id);
        $whatsapp_image = Utility::GetValueByName('whatsapp_image', $store->id);
        $whatsapp_unfo = Utility::GetValueByName('whatsapp_unfo', $store->id);

        if (empty($whatsapp_image)) {
            $whatsapp_image = asset(Storage::url('uploads/payment/whatsapp.png'));
        }

        $Setting_array[23]['status'] = !empty($is_whatsapp_enabled) ? $is_whatsapp_enabled : 'off';
        $Setting_array[23]['name_string'] = __('Whatsapp');
        $Setting_array[23]['name'] = 'whatsapp';
        $Setting_array[23]['detail'] = $whatsapp_unfo;
        $Setting_array[23]['image'] = $whatsapp_image;
        $Setting_array[23]['whatsapp_number'] = $whatsapp_number;

        //Pay TR
        $is_paytr_enabled = Utility::GetValueByName('is_paytr_enabled', $store->id);
        $paytr_merchant_id = Utility::GetValueByName('paytr_merchant_id', $store->id);
        $paytr_merchant_key = Utility::GetValueByName('paytr_merchant_key', $store->id);
        $paytr_salt_key = Utility::GetValueByName('paytr_salt_key', $store->id);
        $paytr_image = Utility::GetValueByName('paytr_image', $store->id);
        $paytr_unfo = Utility::GetValueByName('paytr_unfo', $store->id);

        if (empty($paytr_image)) {
            $paytr_image = asset(Storage::url('uploads/payment/paytr.png'));
        }

        $Setting_array[24]['status'] = !empty($is_paytr_enabled) ? $is_paytr_enabled : 'off';
        $Setting_array[24]['name_string'] = __('PayTR');
        $Setting_array[24]['name'] = 'paytr';
        $Setting_array[24]['detail'] = $paytr_unfo;
        $Setting_array[24]['image'] = $paytr_image;
        $Setting_array[24]['paytr_merchant_id'] = $paytr_merchant_id;
        $Setting_array[24]['paytr_merchant_key'] = $paytr_merchant_key;
        $Setting_array[24]['paytr_salt_key'] = $paytr_salt_key;

        //Yookassa
        $is_yookassa_enabled = Utility::GetValueByName('is_yookassa_enabled', $store->id);
        $yookassa_shop_id_key = Utility::GetValueByName('yookassa_shop_id_key', $store->id);
        $yookassa_secret_key = Utility::GetValueByName('yookassa_secret_key', $store->id);
        $yookassa_image = Utility::GetValueByName('yookassa_image', $store->id);
        $yookassa_unfo = Utility::GetValueByName('yookassa_unfo', $store->id);

        if (empty($yookassa_image)) {
            $yookassa_image = asset(Storage::url('uploads/payment/yookassa.png'));
        }

        // RETIRED (RetiredGateways::PROVIDERS): published 'off' whatever the tenant's row says.
        $Setting_array[25]['status'] = RetiredGateways::status('yookassa', $is_yookassa_enabled);
        $Setting_array[25]['name_string'] = __('Yookassa');
        $Setting_array[25]['name'] = 'yookassa';
        $Setting_array[25]['detail'] = $yookassa_unfo;
        $Setting_array[25]['image'] = $yookassa_image;
        $Setting_array[25]['yookassa_shop_id_key'] = $yookassa_shop_id_key;
        $Setting_array[25]['yookassa_secret_key'] = $yookassa_secret_key;

        //Xendit
        $is_Xendit_enabled = Utility::GetValueByName('is_Xendit_enabled', $store->id);
        $Xendit_api_key = Utility::GetValueByName('Xendit_api_key', $store->id);
        $Xendit_token_key = Utility::GetValueByName('Xendit_token_key', $store->id);
        $Xendit_image = Utility::GetValueByName('Xendit_image', $store->id);
        $Xendit_unfo = Utility::GetValueByName('Xendit_unfo', $store->id);

        if (empty($Xendit_image)) {
            $Xendit_image = asset(Storage::url('uploads/payment/xendit.png'));
        }
        $Setting_array[26]['status'] = !empty($is_Xendit_enabled) ? $is_Xendit_enabled : 'off';
        $Setting_array[26]['name_string'] = __('Xendit');
        $Setting_array[26]['name'] = 'Xendit';
        $Setting_array[26]['detail'] = $Xendit_unfo;
        $Setting_array[26]['image'] = $Xendit_image;
        $Setting_array[26]['Xendit_api_key'] = $Xendit_api_key;
        $Setting_array[26]['Xendit_token_key'] = $Xendit_token_key;

        //Midtrans
        $is_midtrans_enabled = Utility::GetValueByName('is_midtrans_enabled', $store->id);
        $midtrans_secret_key = Utility::GetValueByName('midtrans_secret_key', $store->id);
        $midtrans_image = Utility::GetValueByName('midtrans_image', $store->id);
        $midtrans_unfo = Utility::GetValueByName('midtrans_unfo', $store->id);

        if (empty($midtrans_image)) {
            $midtrans_image = asset(Storage::url('uploads/payment/midtrans.png'));
        }

        $Setting_array[27]['status'] = !empty($is_midtrans_enabled) ? $is_midtrans_enabled : 'off';
        $Setting_array[27]['name_string'] = __('Midtrans');
        $Setting_array[27]['name'] = 'midtrans';
        $Setting_array[27]['detail'] = $midtrans_unfo;
        $Setting_array[27]['image'] = $midtrans_image;
        $Setting_array[27]['midtrans_secret_key'] = $midtrans_secret_key;

        //Nepalste
        $is_nepalste_enabled = Utility::GetValueByName('is_nepalste_enabled', $store->id);
        $nepalste_secret_key = Utility::GetValueByName('nepalste_secret_key', $store->id);
        $nepalste_public_key = Utility::GetValueByName('nepalste_public_key', $store->id);
        $nepalste_image = Utility::GetValueByName('nepalste_image', $store->id);
        $nepalste_unfo = Utility::GetValueByName('nepalste_unfo', $store->id);

        if (empty($nepalste_image)) {
            $nepalste_image = asset(Storage::url('uploads/payment/nepalste.png'));
        }

        $Setting_array[28]['status'] = !empty($is_nepalste_enabled) ? $is_nepalste_enabled : 'off';
        $Setting_array[28]['name_string'] = __('Nepalste');
        $Setting_array[28]['name'] = 'Nepalste';
        $Setting_array[28]['detail'] = $nepalste_unfo;
        $Setting_array[28]['image'] = $nepalste_image;
        $Setting_array[28]['nepalste_secret_key'] = $nepalste_secret_key;
        $Setting_array[28]['nepalste_public_key'] = $nepalste_public_key;

        //Khalti
        $is_khalti_enabled = Utility::GetValueByName('is_khalti_enabled', $store->id);
        $khalti_secret_key = Utility::GetValueByName('khalti_secret_key', $store->id);
        $khalti_public_key = Utility::GetValueByName('khalti_public_key', $store->id);
        $khalti_image = Utility::GetValueByName('khalti_image', $store->id);
        $khalti_unfo = Utility::GetValueByName('khalti_unfo', $store->id);

        if (empty($khalti_image)) {
            $khalti_image = asset(Storage::url('uploads/payment/khalti.png'));
        }

        $Setting_array[29]['status'] = !empty($is_khalti_enabled) ? $is_khalti_enabled : 'off';
        $Setting_array[29]['name_string'] = __('Khalti');
        $Setting_array[29]['name'] = 'khalti';
        $Setting_array[29]['detail'] = $khalti_unfo;
        $Setting_array[29]['image'] = $khalti_image;
        $Setting_array[29]['khalti_secret_key'] = $khalti_secret_key;
        $Setting_array[29]['khalti_public_key'] = $khalti_public_key;

        //PayHere
        $is_payhere_enabled = Utility::GetValueByName('is_payhere_enabled', $store->id);
        $payhere_mode = Utility::GetValueByName('payhere_mode', $store->id);
        $payhere_merchant_id = Utility::GetValueByName('payhere_merchant_id', $store->id);
        $payhere_merchant_secret = Utility::GetValueByName('payhere_merchant_secret', $store->id);
        $payhere_app_id = Utility::GetValueByName('payhere_app_id', $store->id);
        $payhere_app_secret = Utility::GetValueByName('payhere_app_secret', $store->id);
        $payhere_image = Utility::GetValueByName('payhere_image', $store->id);
        $payhere_unfo = Utility::GetValueByName('payhere_unfo', $store->id);

        if (empty($payhere_image)) {
            $payhere_image = asset(Storage::url('uploads/payment/payhere.png'));
        }

        // RETIRED (RetiredGateways::PROVIDERS): published 'off' whatever the tenant's row says.
        $Setting_array[30]['status'] = RetiredGateways::status('payhere', $is_payhere_enabled);
        $Setting_array[30]['name_string'] = __('PayHere');
        $Setting_array[30]['name'] = 'PayHere';
        $Setting_array[30]['detail'] = $payhere_unfo;
        $Setting_array[30]['image'] = $payhere_image;
        $Setting_array[30]['payhere_mode'] = $payhere_mode;
        $Setting_array[30]['payhere_merchant_id'] = $payhere_merchant_id;
        $Setting_array[30]['payhere_merchant_secret'] = $payhere_merchant_secret;
        $Setting_array[30]['payhere_app_id'] = $payhere_app_id;
        $Setting_array[30]['payhere_app_secret'] = $payhere_app_secret;

        //AuthorizeNet
        $is_authorizenet_enabled = Utility::GetValueByName('is_authorizenet_enabled', $store->id);
        $authorizenet_mode = Utility::GetValueByName('authorizenet_mode', $store->id);
        $authorizenet_login_id = Utility::GetValueByName('authorizenet_login_id', $store->id);
        $authorizenet_transaction_key = Utility::GetValueByName('authorizenet_transaction_key', $store->id);
        $authorizenet_image = Utility::GetValueByName('authorizenet_image', $store->id);
        $authorizenet_unfo = Utility::GetValueByName('authorizenet_unfo', $store->id);

        if (empty($authorizenet_image)) {
            $authorizenet_image = asset(Storage::url('uploads/payment/authorizenet.png'));
        }

        $Setting_array[31]['status'] = !empty($is_authorizenet_enabled) ? $is_authorizenet_enabled : 'off';
        $Setting_array[31]['name_string'] = __('AuthorizeNet');
        $Setting_array[31]['name'] = 'AuthorizeNet';
        $Setting_array[31]['detail'] = $authorizenet_unfo;
        $Setting_array[31]['image'] = $authorizenet_image;
        $Setting_array[31]['authorizenet_mode'] = $authorizenet_mode;
        $Setting_array[31]['authorizenet_login_id'] = $authorizenet_login_id;
        $Setting_array[31]['authorizenet_transaction_key'] = $authorizenet_transaction_key;

        //Tap
        $is_tap_enabled = Utility::GetValueByName('is_tap_enabled', $store->id);
        $tap_secret_key = Utility::GetValueByName('tap_secret_key', $store->id);
        $tap_image = Utility::GetValueByName('tap_image', $store->id);
        $tap_unfo = Utility::GetValueByName('tap_unfo', $store->id);

        if (empty($tap_image)) {
            $tap_image = asset(Storage::url('uploads/payment/tap.png'));
        }

        $Setting_array[32]['status'] = !empty($is_tap_enabled) ? $is_tap_enabled : 'off';
        $Setting_array[32]['name_string'] = __('Tap');
        $Setting_array[32]['name'] = 'Tap';
        $Setting_array[32]['detail'] = $tap_unfo;
        $Setting_array[32]['image'] = $tap_image;
        $Setting_array[32]['tap_secret_key'] = $tap_secret_key;

        //PhonePe
        $is_phonepe_enabled = Utility::GetValueByName('is_phonepe_enabled', $store->id);
        $phonepe_mode = Utility::GetValueByName('phonepe_mode', $store->id);
        $phonepe_image = Utility::GetValueByName('phonepe_image', $store->id);
        $phonepe_unfo = Utility::GetValueByName('phonepe_unfo', $store->id);
        $phonepe_client_id = Utility::GetValueByName('phonepe_client_id', $store->id);
        $phonepe_client_secret = Utility::GetValueByName('phonepe_client_secret', $store->id);
        $phonepe_client_version = Utility::GetValueByName('phonepe_client_version', $store->id);

        if (empty($phonepe_image)) {
            $phonepe_image = asset(Storage::url('uploads/payment/phonepe.png'));
        }

        $Setting_array[33]['status'] = !empty($is_phonepe_enabled) ? $is_phonepe_enabled : 'off';
        $Setting_array[33]['name_string'] = __('PhonePe');
        $Setting_array[33]['name'] = 'PhonePe';
        $Setting_array[33]['detail'] = $phonepe_unfo;
        $Setting_array[33]['image'] = $phonepe_image;
        $Setting_array[33]['phonepe_mode'] = $phonepe_mode;
        $Setting_array[33]['phonepe_client_id'] = $phonepe_client_id;
        $Setting_array[33]['phonepe_client_secret'] = $phonepe_client_secret;
        $Setting_array[33]['phonepe_client_version'] = $phonepe_client_version;

        //Paddle
        $is_paddle_enabled = Utility::GetValueByName('is_paddle_enabled', $store->id);
        $paddle_mode = Utility::GetValueByName('paddle_mode', $store->id);
        $paddle_image = Utility::GetValueByName('paddle_image', $store->id);
        $paddle_unfo = Utility::GetValueByName('paddle_unfo', $store->id);
        $paddle_vendor_id = Utility::GetValueByName('paddle_vendor_id', $store->id);
        $paddle_vendor_auth_code = Utility::GetValueByName('paddle_vendor_auth_code', $store->id);
        $paddle_public_key = Utility::GetValueByName('paddle_public_key', $store->id);

        if (empty($paddle_image)) {
            $paddle_image = asset(Storage::url('uploads/payment/paddle.png'));
        }

        $Setting_array[34]['status'] = !empty($is_paddle_enabled) ? $is_paddle_enabled : 'off';
        $Setting_array[34]['name_string'] = __('Paddle');
        $Setting_array[34]['name'] = 'Paddle';
        $Setting_array[34]['detail'] = $paddle_unfo;
        $Setting_array[34]['image'] = $paddle_image;
        $Setting_array[34]['paddle_mode'] = $paddle_mode;
        $Setting_array[34]['paddle_vendor_id'] = $paddle_vendor_id;
        $Setting_array[34]['paddle_vendor_auth_code'] = $paddle_vendor_auth_code;
        $Setting_array[34]['paddle_public_key'] = $paddle_public_key;

        //Paiementpro
        $is_paiementpro_enabled = Utility::GetValueByName('is_paiementpro_enabled', $store->id);
        $paiementpro_image = Utility::GetValueByName('paiementpro_image', $store->id);
        $paiementpro_unfo = Utility::GetValueByName('paiementpro_unfo', $store->id);
        $paiementpro_merchant_id = Utility::GetValueByName('paiementpro_merchant_id', $store->id);

        if (empty($paiementpro_image)) {
            $paiementpro_image = asset(Storage::url('uploads/payment/paiementpro.png'));
        }

        $Setting_array[35]['status'] = !empty($is_paiementpro_enabled) ? $is_paiementpro_enabled : 'off';
        $Setting_array[35]['name_string'] = __('PaiementPro');
        $Setting_array[35]['name'] = 'Paiementpro';
        $Setting_array[35]['detail'] = $paiementpro_unfo;
        $Setting_array[35]['image'] = $paiementpro_image;
        $Setting_array[35]['paiementpro_merchant_id'] = $paiementpro_merchant_id;

        //FedPay
        $is_fedpay_enabled = Utility::GetValueByName('is_fedpay_enabled', $store->id);
        $fedpay_image = Utility::GetValueByName('fedpay_image', $store->id);
        $fedpay_unfo = Utility::GetValueByName('fedpay_unfo', $store->id);
        $fedpay_secret_key = Utility::GetValueByName('fedpay_secret_key', $store->id);
        $fedpay_public_key = Utility::GetValueByName('fedpay_public_key', $store->id);

        if (empty($fedpay_image)) {
            $fedpay_image = asset(Storage::url('uploads/payment/fedpay.png'));
        }

        $Setting_array[36]['status'] = !empty($is_fedpay_enabled) ? $is_fedpay_enabled : 'off';
        $Setting_array[36]['name_string'] = __('FedaPay');
        $Setting_array[36]['name'] = 'FedPay';
        $Setting_array[36]['detail'] = $fedpay_unfo;
        $Setting_array[36]['image'] = $fedpay_image;
        $Setting_array[36]['fedpay_public_key'] = $fedpay_public_key;
        $Setting_array[36]['fedpay_secret_key'] = $fedpay_secret_key;

        //CinetPay
        $is_cinetpay_enabled = Utility::GetValueByName('is_cinetpay_enabled', $store->id);
        $cinet_pay_image = Utility::GetValueByName('cinet_pay_image', $store->id);
        $cinet_pay_unfo = Utility::GetValueByName('cinet_pay_unfo', $store->id);
        $cinet_pay_site_id = Utility::GetValueByName('cinet_pay_site_id', $store->id);
        $cinet_pay_api_key = Utility::GetValueByName('cinet_pay_api_key', $store->id);

        if (empty($cinet_pay_image)) {
            $cinet_pay_image = asset(Storage::url('uploads/payment/cinet.png'));
        }

        $Setting_array[37]['status'] = !empty($is_cinetpay_enabled) ? $is_cinetpay_enabled : 'off';
        $Setting_array[37]['name_string'] = __('CinetPay');
        $Setting_array[37]['name'] = 'CinetPay';
        $Setting_array[37]['detail'] = $cinet_pay_unfo;
        $Setting_array[37]['image'] = $cinet_pay_image;
        $Setting_array[37]['cinet_pay_site_id'] = $cinet_pay_site_id;
        $Setting_array[37]['cinet_pay_api_key'] = $cinet_pay_api_key;

        //senagepay
        $is_Senangpay_enabled = Utility::GetValueByName('is_Senangpay_enabled', $store->id);
        $senang_pay_image = Utility::GetValueByName('senang_pay_image', $store->id);
        $senang_pay_unfo = Utility::GetValueByName('senang_pay_unfo', $store->id);
        $Senangpay_mode = Utility::GetValueByName('Senangpay_mode', $store->id);
        $senang_pay_merchant_id = Utility::GetValueByName('senang_pay_merchant_id', $store->id);
        $senang_pay_secret_key = Utility::GetValueByName('senang_pay_secret_key', $store->id);

        if (empty($senang_pay_image)) {
            $senang_pay_image = asset(Storage::url('uploads/payment/senang.png'));
        }

        $Setting_array[38]['status'] = !empty($is_Senangpay_enabled) ? $is_Senangpay_enabled : 'off';
        $Setting_array[38]['name_string'] = __('SenangPay');
        $Setting_array[38]['name'] = 'SenagePay';
        $Setting_array[38]['detail'] = $senang_pay_unfo;
        $Setting_array[38]['image'] = $senang_pay_image;
        $Setting_array[38]['Senangpay_mode'] = $Senangpay_mode;
        $Setting_array[38]['senang_pay_merchant_id'] = $senang_pay_merchant_id;
        $Setting_array[38]['senang_pay_secret_key'] = $senang_pay_secret_key;

        //cybersource
        $is_cybersource_enabled = Utility::GetValueByName('is_cybersource_enabled', $store->id);
        $cybersource_pay_image = Utility::GetValueByName('cybersource_pay_image', $store->id);
        $cybersource_pay_unfo = Utility::GetValueByName('cybersource_pay_unfo', $store->id);
        $cybersource_pay_merchant_id = Utility::GetValueByName('cybersource_pay_merchant_id', $store->id);
        $cybersource_pay_secret_key = Utility::GetValueByName('cybersource_pay_secret_key', $store->id);
        $cybersource_pay_api_key = Utility::GetValueByName('cybersource_pay_api_key', $store->id);

        if (empty($cybersource_pay_image)) {
            $cybersource_pay_image = asset(Storage::url('uploads/payment/cybersource.png'));
        }

        $Setting_array[39]['status'] = !empty($is_cybersource_enabled) ? $is_cybersource_enabled : 'off';
        $Setting_array[39]['name_string'] = __('CyberSource');
        $Setting_array[39]['name'] = 'CyberSource';
        $Setting_array[39]['detail'] = $cybersource_pay_unfo;
        $Setting_array[39]['image'] = $cybersource_pay_image;
        $Setting_array[39]['cybersource_pay_merchant_id'] = $cybersource_pay_merchant_id;
        $Setting_array[39]['cybersource_pay_secret_key'] = $cybersource_pay_secret_key;
        $Setting_array[39]['cybersource_pay_api_key'] = $cybersource_pay_api_key;

        //ozow
        $is_ozow_enabled = Utility::GetValueByName('is_ozow_enabled', $store->id);
        $ozow_pay_image = Utility::GetValueByName('ozow_pay_image', $store->id);
        $ozow_pay_unfo = Utility::GetValueByName('ozow_pay_unfo', $store->id);
        $ozow_mode = Utility::GetValueByName('ozow_mode', $store->id);
        $ozow_pay_Site_key = Utility::GetValueByName('ozow_pay_Site_key', $store->id);
        $ozow_pay_private_key = Utility::GetValueByName('ozow_pay_private_key', $store->id);
        $ozow_pay_api_key = Utility::GetValueByName('ozow_pay_api_key', $store->id);

        if (empty($ozow_pay_image)) {
            $ozow_pay_image = asset(Storage::url('uploads/payment/ozow.png'));
        }

        $Setting_array[40]['status'] = !empty($is_ozow_enabled) ? $is_ozow_enabled : 'off';
        $Setting_array[40]['name_string'] = __('Ozow');
        $Setting_array[40]['name'] = 'Ozow';
        $Setting_array[40]['detail'] = $ozow_pay_unfo;
        $Setting_array[40]['image'] = $ozow_pay_image;
        $Setting_array[40]['ozow_mode'] = $ozow_mode;
        $Setting_array[40]['ozow_pay_Site_key'] = $ozow_pay_Site_key;
        $Setting_array[40]['ozow_pay_private_key'] = $ozow_pay_private_key;
        $Setting_array[40]['ozow_pay_api_key'] = $ozow_pay_api_key;

        //Easebuzz
        $is_easebuzz_enabled = Utility::GetValueByName( 'is_easebuzz_enabled' , $store->id);
        $easebuzz_image = Utility::GetValueByName( 'easebuzz_image' , $store->id);
        $easebuzz_unfo = Utility::GetValueByName( 'easebuzz_unfo' , $store->id);
        $easebuzz_merchant_key = Utility::GetValueByName( 'easebuzz_merchant_key' , $store->id);
        $easebuzz_salt_key = Utility::GetValueByName( 'easebuzz_salt_key' , $store->id);
        $easebuzz_enviroment_name = Utility::GetValueByName( 'easebuzz_enviroment_name' , $store->id);

        if ( empty( $easebuzz_image ) ) {
            $easebuzz_image = asset( Storage::url( 'uploads/payment/easebuzz.png' ) );
        }

        $Setting_array[ 41 ][ 'status' ] = !empty( $is_easebuzz_enabled ) ? $is_easebuzz_enabled : 'off';
        $Setting_array[ 41 ][ 'name_string' ] = __('Easebuzz');
        $Setting_array[ 41 ][ 'name' ] = 'easebuzz';
        $Setting_array[ 41 ][ 'detail' ] = $easebuzz_unfo;
        $Setting_array[ 41 ][ 'image' ] = $easebuzz_image;
        $Setting_array[ 41 ][ 'easebuzz_merchant_key' ] = $easebuzz_merchant_key;
        $Setting_array[ 41 ][ 'easebuzz_salt_key' ] = $easebuzz_salt_key;
        $Setting_array[ 41 ][ 'easebuzz_enviroment_name' ] = $easebuzz_enviroment_name;

        //NMI
        $is_nmi_enabled = Utility::GetValueByName( 'is_nmi_enabled' , $store->id);
        $nmi_image = Utility::GetValueByName( 'nmi_image' , $store->id);
        $nmi_unfo = Utility::GetValueByName( 'nmi_unfo' , $store->id);
        $nmi_api_private_key = Utility::GetValueByName( 'nmi_api_private_key' , $store->id);

        if ( empty( $nmi_image ) ) {
            $nmi_image = asset( Storage::url( 'uploads/payment/nmi.png' ) );
        }

        $Setting_array[ 42 ][ 'status' ] = !empty( $is_nmi_enabled ) ? $is_nmi_enabled : 'off';
        $Setting_array[ 42 ][ 'name_string' ] = __('NMI');
        $Setting_array[ 42 ][ 'name' ] = 'NMI';
        $Setting_array[ 42 ][ 'detail' ] = $nmi_unfo;
        $Setting_array[ 42 ][ 'image' ] = $nmi_image;
        $Setting_array[ 42 ][ 'nmi_api_private_key' ] = $nmi_api_private_key;

        //PayU
        $is_payu_enabled = Utility::GetValueByName( 'is_payu_enabled', $store->id );
        $payu_mode = Utility::GetValueByName( 'payu_mode' , $store->id);
        $payu_merchant_key = Utility::GetValueByName( 'payu_merchant_key' , $store->id);
        $payu_salt_key = Utility::GetValueByName( 'payu_salt_key' , $store->id);
        $payu_image = Utility::GetValueByName( 'payu_image', $store->id );
        $payu_unfo = Utility::GetValueByName( 'payu_unfo' , $store->id);

        if ( empty( $payu_image ) ) {
            $payu_image = asset( Storage::url( 'uploads/payment/payu.png' ) );
        }

        $Setting_array[ 43 ][ 'status' ] = !empty( $is_payu_enabled ) ? $is_payu_enabled : 'off';
        $Setting_array[ 43 ][ 'name_string' ] = __('PayU');
        $Setting_array[ 43 ][ 'name' ] = 'payu';
        $Setting_array[ 43 ][ 'detail' ] = $payu_unfo;
        $Setting_array[ 43 ][ 'image' ] = $payu_image;
        $Setting_array[ 43 ][ 'payu_mode' ] = $payu_mode;
        $Setting_array[ 43 ][ 'payu_merchant_key' ] = $payu_merchant_key;
        $Setting_array[ 43 ][ 'payu_salt_key' ] = $payu_salt_key;

        // Sofort
        $is_sofort_enabled = Utility::GetValueByName('is_sofort_enabled', $store->id);
        $sofort_publishable_key = Utility::GetValueByName('sofort_publishable_key', $store->id);
        $sofort_secret_key = Utility::GetValueByName('sofort_secret_key', $store->id);
        $sofort_image = Utility::GetValueByName('sofort_image', $store->id);
        if (empty($sofort_image)) {
            $sofort_image = asset(Storage::url('uploads/payment/sofort.png'));
        }
        $sofort_unfo = Utility::GetValueByName('sofort_unfo', $store->id);

        $Setting_array[44]['status'] = !empty($is_sofort_enabled) ? $is_sofort_enabled : 'off';
        $Setting_array[44]['name_string'] = __('Sofort');
        $Setting_array[44]['name'] = 'sofort';
        $Setting_array[44]['detail'] = $sofort_unfo;
        $Setting_array[44]['image'] = $sofort_image;
        $Setting_array[44]['sofort_publishable_key'] = $sofort_publishable_key;
        $Setting_array[44]['sofort_secret_key_key'] = $sofort_secret_key;

        // ESewa
        $is_esewa_enabled = Utility::GetValueByName('is_esewa_enabled', $store->id);
        $esewa_merchant_key = Utility::GetValueByName('esewa_merchant_key', $store->id);
        $esewa_mode = Utility::GetValueByName('esewa_mode', $store->id);
        $esewa_image = Utility::GetValueByName('esewa_image', $store->id);
        if (empty($esewa_image)) {
            $esewa_image = asset(Storage::url('uploads/payment/esewa.png'));
        }
        $esewa_unfo = Utility::GetValueByName('esewa_unfo', $store->id);

        $Setting_array[45]['status'] = !empty($is_esewa_enabled) ? $is_esewa_enabled : 'off';
        $Setting_array[45]['name_string'] = __('ESewa');
        $Setting_array[45]['name'] = 'esewa';
        $Setting_array[45]['detail'] = $esewa_unfo;
        $Setting_array[45]['image'] = $esewa_image;
        $Setting_array[45]['esewa_merchant_key'] = $esewa_merchant_key;
        $Setting_array[45]['esewa_mode_key'] = $esewa_mode;

        //MyFatoorah
        $is_myfatoorah_enabled = Utility::GetValueByName( 'is_myfatoorah_enabled', $store->id );
        $myfatoorah_pay_image = Utility::GetValueByName( 'myfatoorah_pay_image', $store->id );
        $myfatoorah_pay_unfo = Utility::GetValueByName( 'myfatoorah_pay_unfo', $store->id );
        $myfatoorah_mode = Utility::GetValueByName( 'myfatoorah_mode' );
        $myfatoorah_pay_country_iso = Utility::GetValueByName( 'myfatoorah_pay_country_iso', $store->id);
        $myfatoorah_pay_api_key = Utility::GetValueByName( 'myfatoorah_pay_api_key', $store->id );

        if ( empty( $myfatoorah_pay_image ) ) {
            $myfatoorah_pay_image = asset( Storage::url( 'uploads/payment/myfatoorah.png' ) );
        }

        $Setting_array[ 46 ][ 'status' ] = !empty( $is_myfatoorah_enabled ) ? $is_myfatoorah_enabled : 'off';
        $Setting_array[ 46 ][ 'name_string' ] = __('MyFatoorah');
        $Setting_array[ 46 ][ 'name' ] = 'MyFatoorah';
        $Setting_array[ 46 ][ 'detail' ] = $myfatoorah_pay_unfo;
        $Setting_array[ 46 ][ 'image' ] = $myfatoorah_pay_image;
        $Setting_array[ 46 ][ 'myfatoorah_mode' ] = $myfatoorah_mode;
        $Setting_array[ 46 ][ 'myfatoorah_pay_country_iso' ] = $myfatoorah_pay_country_iso;
        $Setting_array[ 46 ][ 'myfatoorah_pay_api_key' ] = $myfatoorah_pay_api_key;

        //Paynow
        $is_paynow_enabled = Utility::GetValueByName( 'is_paynow_enabled', $store->id );
        $paynow_pay_image = Utility::GetValueByName( 'paynow_pay_image', $store->id );
        $paynow_pay_unfo = Utility::GetValueByName( 'paynow_pay_unfo', $store->id );
        $paynow_mode = Utility::GetValueByName( 'paynow_mode', $store->id );
        $paynow_pay_integration_id = Utility::GetValueByName( 'paynow_pay_integration_id', $store->id );
        $paynow_pay_integration_key = Utility::GetValueByName( 'paynow_pay_integration_key', $store->id );
        $paynow_pay_merchant_email = Utility::GetValueByName( 'paynow_pay_merchant_email', $store->id );

        if ( empty( $paynow_pay_image ) ) {
            $paynow_pay_image = asset( Storage::url( 'uploads/payment/paynow.png' ) );
        }

        $Setting_array[ 47 ][ 'status' ] = !empty( $is_paynow_enabled ) ? $is_paynow_enabled : 'off';
        $Setting_array[ 47 ][ 'name_string' ] = __('Paynow');
        $Setting_array[ 47 ][ 'name' ] = 'Paynow';
        $Setting_array[ 47 ][ 'detail' ] = $paynow_pay_unfo;
        $Setting_array[ 47 ][ 'image' ] = $paynow_pay_image;
        $Setting_array[ 47 ][ 'paynow_mode' ] = $paynow_mode;
        $Setting_array[ 47 ][ 'paynow_pay_integration_id' ] = $paynow_pay_integration_id;
        $Setting_array[ 47 ][ 'paynow_pay_integration_key' ] = $paynow_pay_integration_key;
        $Setting_array[ 47 ][ 'paynow_pay_merchant_email' ] = $paynow_pay_merchant_email;

        //DPO Pay
        $is_dpopay_enabled = Utility::GetValueByName( 'is_dpopay_enabled', $store->id );
        $dpo_pay_image = Utility::GetValueByName( 'dpo_pay_image', $store->id );
        $dpo_pay_unfo = Utility::GetValueByName( 'dpo_pay_unfo', $store->id );
        $dpo_pay_Company_Token = Utility::GetValueByName( 'dpo_pay_Company_Token', $store->id );
        $dpo_pay_Service_Type = Utility::GetValueByName( 'dpo_pay_Service_Type', $store->id );

        if ( empty( $dpo_pay_image ) ) {
            $dpo_pay_image = asset( Storage::url( 'uploads/payment/dpo.png' ) );
        }

        $Setting_array[ 48 ][ 'name_string' ] = __('DPO Pay');
        $Setting_array[ 48 ][ 'status' ] = !empty( $is_dpopay_enabled ) ? $is_dpopay_enabled : 'off';
        $Setting_array[ 48 ][ 'name' ] = 'DPO';
        $Setting_array[ 48 ][ 'detail' ] = $dpo_pay_unfo;
        $Setting_array[ 48 ][ 'image' ] = $dpo_pay_image;
        $Setting_array[ 48 ][ 'dpo_pay_Company_Token' ] = $dpo_pay_Company_Token;
        $Setting_array[ 48 ][ 'dpo_pay_Service_Type' ] = $dpo_pay_Service_Type;


        //Braintree
        $is_braintree_enabled = Utility::GetValueByName( 'is_braintree_enabled', $store->id );
        $braintree_pay_image = Utility::GetValueByName( 'braintree_pay_image', $store->id );
        $braintree_pay_unfo = Utility::GetValueByName( 'braintree_pay_unfo', $store->id );
        $braintree_mode = Utility::GetValueByName( 'braintree_mode', $store->id );
        $braintree_pay_merchant_id = Utility::GetValueByName( 'braintree_pay_merchant_id', $store->id );
        $braintree_pay_public_key = Utility::GetValueByName( 'braintree_pay_public_key' , $store->id);
        $braintree_pay_private_key = Utility::GetValueByName( 'braintree_pay_private_key' , $store->id);

        if ( empty( $braintree_pay_image ) ) {
            $braintree_pay_image = asset( Storage::url( 'uploads/payment/braintree.png' ) );
        }

        $Setting_array[ 49 ][ 'status' ] = !empty( $is_braintree_enabled ) ? $is_braintree_enabled : 'off';
        $Setting_array[ 49 ][ 'name_string' ] = __('Braintree');
        $Setting_array[ 49 ][ 'name' ] = 'Braintree';
        $Setting_array[ 49 ][ 'detail' ] = $braintree_pay_unfo;
        $Setting_array[ 49 ][ 'image' ] = $braintree_pay_image;
        $Setting_array[ 49 ][ 'braintree_mode' ] = $braintree_mode;
        $Setting_array[ 49 ][ 'braintree_pay_merchant_id' ] = $braintree_pay_merchant_id;
        $Setting_array[ 49 ][ 'braintree_pay_public_key' ] = $braintree_pay_public_key;
        $Setting_array[ 49 ][ 'braintree_pay_private_key' ] = $braintree_pay_private_key;

        //PowerTranz
        $is_powertranz_enabled = Utility::GetValueByName( 'is_powertranz_enabled', $store->id );
        $powertranz_pay_image = Utility::GetValueByName( 'powertranz_pay_image', $store->id );
        $powertranz_pay_unfo = Utility::GetValueByName( 'powertranz_pay_unfo', $store->id );
        $powertranz_mode = Utility::GetValueByName( 'powertranz_mode', $store->id );
        $powertranz_pay_production_url = Utility::GetValueByName( 'powertranz_pay_production_url', $store->id );
        $powertranz_pay_merchant_id = Utility::GetValueByName( 'powertranz_pay_merchant_id', $store->id );
        $powertranz_pay_processing_password = Utility::GetValueByName( 'powertranz_pay_processing_password', $store->id );

        if ( empty( $powertranz_pay_image ) ) {
            $powertranz_pay_image = asset( Storage::url( 'uploads/payment/powertranz.png' ) );
        }

        $Setting_array[ 50 ][ 'name_string' ] = __('PowerTranz');
        $Setting_array[ 50 ][ 'status' ] = !empty( $is_powertranz_enabled ) ? $is_powertranz_enabled : 'off';
        $Setting_array[ 50 ][ 'name' ] = 'PowerTranz';
        $Setting_array[ 50 ][ 'detail' ] = $powertranz_pay_unfo;
        $Setting_array[ 50 ][ 'image' ] = $powertranz_pay_image;
        $Setting_array[ 50 ][ 'powertranz_mode' ] = $powertranz_mode;
        $Setting_array[ 50 ][ 'powertranz_pay_production_url' ] = $powertranz_pay_production_url;
        $Setting_array[ 50 ][ 'powertranz_pay_merchant_id' ] = $powertranz_pay_merchant_id;
        $Setting_array[ 50 ][ 'powertranz_pay_processing_password' ] = $powertranz_pay_processing_password;

        // SSLCommerz
        $is_sslcommerz_enabled = Utility::GetValueByName( 'is_sslcommerz_enabled', $store->id );
        $sslcommerz_pay_image = Utility::GetValueByName( 'sslcommerz_pay_image', $store->id );
        $sslcommerz_pay_unfo = Utility::GetValueByName( 'sslcommerz_pay_unfo', $store->id );
        $sslcommerz_mode = Utility::GetValueByName( 'sslcommerz_mode', $store->id );
        $sslcommerz_pay_store_id = Utility::GetValueByName( 'sslcommerz_pay_store_id', $store->id );
        $sslcommerz_pay_secret_key = Utility::GetValueByName( 'sslcommerz_pay_secret_key' , $store->id);

        if ( empty( $sslcommerz_pay_image ) ) {
            $sslcommerz_pay_image = asset( Storage::url( 'uploads/payment/sslcommerz.png' ) );
        }

        $Setting_array[ 51 ][ 'name_string' ] = __('SSLCommerz');
        $Setting_array[ 51 ][ 'status' ] = !empty( $is_sslcommerz_enabled ) ? $is_sslcommerz_enabled : 'off';
        $Setting_array[ 51 ][ 'name' ] = 'SSLCommerz';
        $Setting_array[ 51 ][ 'detail' ] = $sslcommerz_pay_unfo;
        $Setting_array[ 51 ][ 'image' ] = $sslcommerz_pay_image;
        $Setting_array[ 51 ][ 'sslcommerz_mode' ] = $sslcommerz_mode;
        $Setting_array[ 51 ][ 'sslcommerz_pay_store_id' ] = $sslcommerz_pay_store_id;
        $Setting_array[ 51 ][ 'sslcommerz_pay_secret_key' ] = $sslcommerz_pay_secret_key;
        
        // if (module_is_active('PartialPayments')) {
        //     $user = User::find($store->created_by);
        //     $plan = Plan::find($user->plan_id);
        //     $enable_partial_payment = Utility::GetValueByName( 'enable_partial_payment', $store->id );
        //     $settings = Setting::where('store_id', $store->id)->pluck('value', 'name')->toArray();
        //     if($plan && strpos($plan->modules, 'PartialPayments') !== false && \Auth::guard('customers')->user() && !isset($request->type) && $request->type != 'pending_amount' && isset($enable_partial_payment) && $enable_partial_payment == 'on'  && (isset($settings['enable_partial_payment']) && $settings['enable_partial_payment'] == 'on'))
        //     {
        //        $Setting_array = \Workdo\PartialPayments\app\Http\Controllers\PartialPaymentsController::PaymentList($Setting_array,$store);
        //     }
        // }


$is_paymera_enabled = Utility::GetValueByName('is_Paymera_enabled', $store->id);
        $paymera_image = Utility::GetValueByName('paymera_image', $store->id);
        $paymera_unfo = Utility::GetValueByName('Paymera_unfo', $store->id);
        
        // تعديل الأسماء لتطابق قاعدة البيانات تماماً
        $paymera_terminal_id = Utility::GetValueByName('PAYMERA_TERMINAL_ID', $store->id);
        $paymera_username = Utility::GetValueByName('PAYMERA_USERNAME', $store->id);

        if (empty($paymera_image)) {
            $paymera_image = asset(Storage::url('uploads/payment/paymera.png'));
        }

        $Setting_array[52]['status'] = !empty($is_paymera_enabled) ? $is_paymera_enabled : 'off';
        $Setting_array[52]['name_string'] = __('Paymera');
        $Setting_array[52]['name'] = 'paymera';
        $Setting_array[52]['detail'] = $paymera_unfo;
        $Setting_array[52]['image'] = $paymera_image;
        
        // إضافة المتغيرات الخاصة ببوابة الدفع
        $Setting_array[52]['PAYMERA_TERMINAL_ID'] = $paymera_terminal_id;
        $Setting_array[52]['PAYMERA_USERNAME'] = $paymera_username;


        if (!empty($Setting_array)) {
            return $Setting_array;
        } else {
            return [];
        }
    }
}