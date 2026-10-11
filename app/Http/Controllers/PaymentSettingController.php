<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Setting;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\File;

class PaymentSettingController extends Controller
{
    protected $store_id;
    protected $post = [];

    /**
     * Process payment settings form submission
     */
    public function update(Request $request)
    {
        session()->put(['setting_tab' => 'payment_setting']);
        $this->store_id = !empty(getCurrentStore()) ? getCurrentStore() : '1';

        // Handle currency settings
        $this->handleBasicSettings($request);

        // Process all payment gateways
        $gateways = $this->getPaymentGateways();
        
        foreach ($gateways as $gateway) {
            $this->processGateway($request, $gateway);
        }

        // Save all settings at once
        $this->saveSettings();

        return redirect()->back()->with('success', __('Setting successfully updated.'));
    }

    /**
     * Handle basic currency settings
     */
    private function handleBasicSettings(Request $request)
    {
        if (isset($request->CURRENCY)) {
            $this->post['CURRENCY'] = $request->CURRENCY;
        }
        if (isset($request->CURRENCY_NAME)) {
            $this->post['CURRENCY_NAME'] = $request->CURRENCY_NAME;
        }
    }

    /**
     * Process a single payment gateway
     */
    private function processGateway(Request $request, $gateway)
    {
        $id = $gateway['id'];
        $enableKey = "is_{$id}_enabled";
        $imageKey = "{$id}_image";
        $infoKey = "{$id}_unfo";
        
        // Set default status to off
        $this->post[$enableKey] = 'off';
        
        // Process only if gateway is enabled
        if ($request->$enableKey == 'on') {
            // Handle image upload if provided
            if (!empty($request->$imageKey)) {
                $this->handleImageUpload($request->$imageKey, $imageKey);
            }
            
            // Validate required fields if specified
            if (!empty($gateway['required_fields'])) {
                $this->validateGatewayFields($request, $gateway);
            }
            
            // Set gateway as enabled
            $this->post[$enableKey] = 'on';
            
            // Store gateway fields
            foreach ($gateway['fields'] as $field) {
                $this->post[$field] = !empty($request->$field) ? $request->$field : '';
            }
            
            // Store description/info
            if (isset($request->$infoKey)) {
                $this->post[$infoKey] = $request->$infoKey;
            }
        }
    }

    /**
     * Handle image upload for payment gateway
     */
    private function handleImageUpload($image, $imageKey)
    {
        $uploadResult = upload_theme_image($image);
        
        if ($uploadResult['status'] == false) {
            return redirect()->back()->with('error', $uploadResult['message']);
        }
        
        $where = ['name' => $imageKey, 'store_id' => $this->store_id];
        $setting = Setting::where($where)->first();

        if (!empty($setting) && !empty($setting->value) && File::exists(base_path($setting->value))) {
            Utility::changeStorageLimit(\Auth::user()->creatorId(), $setting->value);
        }
        
        $this->post[$imageKey] = $uploadResult['image_path'];
    }

    /**
     * Validate required fields for a gateway
     */
    private function validateGatewayFields(Request $request, $gateway)
    {
        $rules = array_merge(
            ['is_' . $gateway['id'] . '_enabled' => 'required'],
            array_fill_keys($gateway['required_fields'], 'required')
        );
        
        $validator = Validator::make($request->all(), $rules);
        
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', $messages->first());
        }
    }

    /**
     * Save all settings to database
     */
    private function saveSettings()
    {
        $settingQuery = Setting::query();
        
        foreach ($this->post as $key => $value) {
            (clone $settingQuery)->updateOrCreate(
                [
                    'name' => $key,
                    'store_id' => $this->store_id
                ],
                [
                    'value' => $value,
                    'name' => $key,
                    'store_id' => $this->store_id,
                    'created_by' => auth()->user()->id,
                ]
            );
        }
    }

    /**
     * Get configuration for all payment gateways
     */
    public function getPaymentGateways()
    {
        return [
            [
                'id' => 'cod',
                'fields' => ['is_cod_enabled', 'cod_info'],
                'required_fields' => []
            ],
            [
                'id' => 'bank_transfer',
                'fields' => ['is_bank_transfer_enabled', 'bank_transfer'],
                'required_fields' => []
            ],
            [
                'id' => 'stripe',
                'fields' => ['is_stripe_enabled', 'stripe_publishable_key', 'stripe_secret_key', 'stripe_unfo'],
                'required_fields' => ['stripe_publishable_key', 'stripe_secret_key']
            ],
     [
                'id' => 'Paymera',
                'fields' => ['is_Paymera_enabled', 'PAYMERA_TERMINAL_ID', 'PAYMERA_USERNAME', 'Paymera_unfo'],
                'required_fields' => ['PAYMERA_TERMINAL_ID', 'PAYMERA_USERNAME']
            ],

            [
                'id' => 'paystack',
                'fields' => ['is_paystack_enabled', 'paystack_public_key', 'paystack_secret_key', 'paystack_unfo'],
                'required_fields' => ['paystack_public_key', 'paystack_secret_key']
            ],
            [
                'id' => 'razorpay',
                'fields' => ['is_razorpay_enabled', 'razorpay_public_key', 'razorpay_secret_key', 'razorpay_unfo'],
                'required_fields' => ['razorpay_public_key', 'razorpay_secret_key']
            ],
            [
                'id' => 'mercado',
                'fields' => ['is_mercado_enabled', 'mercado_access_token', 'mercado_mode', 'mercado_unfo'],
                'required_fields' => ['mercado_access_token', 'mercado_mode']
            ],
            [
                'id' => 'skrill',
                'fields' => ['is_skrill_enabled', 'skrill_email', 'skrill_unfo'],
                'required_fields' => ['skrill_email']
            ],
            [
                'id' => 'paymentwall',
                'fields' => ['is_paymentwall_enabled', 'paymentwall_public_key', 'paymentwall_private_key', 'paymentwall_unfo'],
                'required_fields' => ['paymentwall_public_key', 'paymentwall_private_key']
            ],
            [
                'id' => 'paypal',
                'fields' => ['is_paypal_enabled', 'paypal_client_id', 'paypal_secret_key', 'paypal_mode', 'paypal_unfo'],
                'required_fields' => ['paypal_client_id', 'paypal_secret_key', 'paypal_mode']
            ],
            [
                'id' => 'flutterwave',
                'fields' => ['is_flutterwave_enabled', 'flutterwave_public_key', 'flutterwave_secret_key', 'flutterwave_unfo'],
                'required_fields' => ['flutterwave_public_key', 'flutterwave_secret_key']
            ],
            [
                'id' => 'paytm',
                'fields' => ['is_paytm_enabled', 'paytm_merchant_id', 'paytm_merchant_key', 'paytm_industry_type', 'paytm_mode', 'paytm_unfo'],
                'required_fields' => ['paytm_merchant_id', 'paytm_merchant_key', 'paytm_industry_type', 'paytm_mode']
            ],
            [
                'id' => 'mollie',
                'fields' => ['is_mollie_enabled', 'mollie_api_key', 'mollie_profile_id', 'mollie_partner_id', 'mollie_unfo'],
                'required_fields' => ['mollie_api_key', 'mollie_profile_id', 'mollie_partner_id']
            ],
            [
                'id' => 'coingate',
                'fields' => ['is_coingate_enabled', 'coingate_auth_token', 'coingate_mode', 'coingate_unfo'],
                'required_fields' => ['coingate_auth_token', 'coingate_mode']
            ],
            [
                'id' => 'sspay',
                'fields' => ['is_sspay_enabled', 'sspay_secret_key', 'sspay_category_code', 'sspay_unfo'],
                'required_fields' => ['sspay_secret_key', 'sspay_category_code']
            ],
            [
                'id' => 'toyyibpay',
                'fields' => ['is_toyyibpay_enabled', 'toyyibpay_secret_key', 'toyyibpay_category_code', 'toyyibpay_unfo'],
                'required_fields' => ['toyyibpay_secret_key', 'toyyibpay_category_code']
            ],
            [
                'id' => 'paytabs',
                'fields' => ['is_paytabs_enabled', 'paytabs_profile_id', 'paytabs_server_key', 'paytabs_region', 'paytabs_unfo'],
                'required_fields' => ['paytabs_profile_id', 'paytabs_server_key', 'paytabs_region']
            ],
            [
                'id' => 'iyzipay',
                'fields' => ['is_iyzipay_enabled', 'iyzipay_private_key', 'iyzipay_secret_key', 'iyzipay_mode', 'iyzipay_unfo'],
                'required_fields' => ['iyzipay_private_key', 'iyzipay_secret_key', 'iyzipay_mode']
            ],
            [
                'id' => 'payfast',
                'fields' => ['is_payfast_enabled', 'payfast_merchant_id', 'payfast_merchant_key', 'payfast_salt_passphrase', 'payfast_mode', 'payfast_unfo'],
                'required_fields' => ['payfast_merchant_id', 'payfast_merchant_key', 'payfast_salt_passphrase', 'payfast_mode']
            ],
            [
                'id' => 'benefit',
                'fields' => ['is_benefit_enabled', 'benefit_secret_key', 'benefit_private_key', 'benefit_unfo'],
                'required_fields' => ['benefit_secret_key', 'benefit_private_key']
            ],
            [
                'id' => 'cashfree',
                'fields' => ['is_cashfree_enabled', 'cashfree_key', 'cashfree_secret_key', 'cashfree_unfo'],
                'required_fields' => ['cashfree_key', 'cashfree_secret_key']
            ],
            [
                'id' => 'aamarpay',
                'fields' => ['is_aamarpay_enabled', 'aamarpay_store_id', 'aamarpay_signature_key', 'aamarpay_description', 'aamarpay_unfo'],
                'required_fields' => ['aamarpay_store_id', 'aamarpay_signature_key', 'aamarpay_description']
            ],
            [
                'id' => 'telegram',
                'fields' => ['is_telegram_enabled', 'telegram_access_token', 'telegram_chat_id', 'telegram_unfo'],
                'required_fields' => ['telegram_access_token', 'telegram_chat_id']
            ],
            [
                'id' => 'whatsapp',
                'fields' => ['is_whatsapp_enabled', 'whatsapp_number', 'whatsapp_unfo'],
                'required_fields' => ['whatsapp_number']
            ],
            [
                'id' => 'paytr',
                'fields' => ['is_paytr_enabled', 'paytr_merchant_id', 'paytr_merchant_key', 'paytr_salt_key', 'paytr_unfo'],
                'required_fields' => ['paytr_merchant_id', 'paytr_merchant_key', 'paytr_salt_key']
            ],
            [
                'id' => 'yookassa',
                'fields' => ['is_yookassa_enabled', 'yookassa_shop_id_key', 'yookassa_secret_key', 'yookassa_unfo'],
                'required_fields' => ['yookassa_shop_id_key', 'yookassa_secret_key']
            ],
            [
                'id' => 'Xendit',
                'fields' => ['is_Xendit_enabled', 'Xendit_api_key', 'Xendit_token_key', 'Xendit_unfo'],
                'required_fields' => ['Xendit_api_key', 'Xendit_token_key']
            ],
            [
                'id' => 'midtrans',
                'fields' => ['is_midtrans_enabled', 'midtrans_secret_key', 'midtrans_unfo'],
                'required_fields' => ['midtrans_secret_key']
            ],
            [
                'id' => 'nepalste',
                'fields' => ['is_nepalste_enabled', 'nepalste_public_key', 'nepalste_secret_key', 'nepalste_mode', 'nepalste_unfo'],
                'required_fields' => ['nepalste_public_key', 'nepalste_secret_key', 'nepalste_mode']
            ],
            [
                'id' => 'payhere',
                'fields' => ['is_payhere_enabled', 'payhere_merchant_id', 'payhere_merchant_secret', 'payhere_app_id', 'payhere_app_secret', 'payhere_mode', 'payhere_unfo'],
                'required_fields' => ['payhere_merchant_id', 'payhere_merchant_secret', 'payhere_app_id', 'payhere_app_secret', 'payhere_mode']
            ],
            [
                'id' => 'khalti',
                'fields' => ['is_khalti_enabled', 'khalti_public_key', 'khalti_secret_key', 'khalti_unfo'],
                'required_fields' => ['khalti_public_key', 'khalti_secret_key']
            ],
            [
                'id' => 'authorizenet',
                'fields' => ['is_authorizenet_enabled', 'authorizenet_login_id', 'authorizenet_transaction_key', 'authorizenet_mode', 'authorizenet_unfo'],
                'required_fields' => ['authorizenet_login_id', 'authorizenet_transaction_key', 'authorizenet_mode']
            ],
            [
                'id' => 'tap',
                'fields' => ['is_tap_enabled', 'tap_secret_key', 'tap_unfo'],
                'required_fields' => ['tap_secret_key']
            ],
            [
                'id' => 'phonepe',
                'fields' => ['is_phonepe_enabled', 'phonepe_client_id', 'phonepe_client_secret', 'phonepe_client_version', 'phonepe_mode', 'phonepe_unfo'],
                'required_fields' => ['phonepe_client_id', 'phonepe_client_secret', 'phonepe_client_version', 'phonepe_mode']
            ],
            [
                'id' => 'paddle',
                'fields' => ['is_paddle_enabled', 'paddle_vendor_id', 'paddle_vendor_auth_code', 'paddle_public_key', 'paddle_mode', 'paddle_unfo'],
                'required_fields' => ['paddle_vendor_id', 'paddle_vendor_auth_code', 'paddle_public_key', 'paddle_mode']
            ],
            [
                'id' => 'paiementpro',
                'fields' => ['is_paiementpro_enabled', 'paiementpro_merchant_id', 'paiementpro_mode', 'paiementpro_unfo'],
                'required_fields' => ['paiementpro_merchant_id']
            ],
            [
                'id' => 'fedpay',
                'fields' => ['is_fedpay_enabled', 'fedpay_public_key', 'fedpay_secret_key', 'fedpay_mode', 'fedpay_unfo'],
                'required_fields' => ['fedpay_public_key', 'fedpay_secret_key', 'fedpay_mode']
            ],
            [
                'id' => 'cinetpay',
                'fields' => ['is_cinetpay_enabled', 'cinet_pay_site_id', 'cinet_pay_api_key', 'cinet_pay_secret_key', 'cinet_pay_unfo'],
                'required_fields' => ['cinet_pay_site_id', 'cinet_pay_api_key', 'cinet_pay_secret_key']
            ],
            [
                'id' => 'Senangpay',
                'fields' => ['is_Senangpay_enabled', 'senang_pay_merchant_id', 'senang_pay_secret_key', 'Senangpay_mode', 'senang_pay_unfo'],
                'required_fields' => ['senang_pay_merchant_id', 'senang_pay_secret_key', 'Senangpay_mode']
            ],
            [
                'id' => 'cybersource',
                'fields' => ['is_cybersource_enabled', 'cybersource_pay_merchant_id', 'cybersource_pay_secret_key', 'cybersource_pay_api_key', 'cybersource_pay_unfo'],
                'required_fields' => ['cybersource_pay_merchant_id', 'cybersource_pay_secret_key', 'cybersource_pay_api_key']
            ],
            [
                'id' => 'ozow',
                'fields' => ['is_ozow_enabled', 'ozow_pay_Site_key', 'ozow_pay_private_key', 'ozow_pay_api_key', 'ozow_mode', 'ozow_pay_unfo'],
                'required_fields' => ['ozow_pay_Site_key', 'ozow_pay_private_key', 'ozow_pay_api_key', 'ozow_mode']
            ],
            [
                'id' => 'myfatoorah',
                'fields' => ['is_myfatoorah_enabled', 'myfatoorah_pay_country_iso', 'myfatoorah_pay_api_key', 'myfatoorah_mode', 'myfatoorah_pay_unfo'],
                'required_fields' => ['myfatoorah_pay_country_iso', 'myfatoorah_pay_api_key', 'myfatoorah_mode']
            ],
            [
                'id' => 'easebuzz',
                'fields' => ['is_easebuzz_enabled', 'easebuzz_merchant_key', 'easebuzz_salt_key', 'easebuzz_enviroment_name', 'easebuzz_unfo'],
                'required_fields' => ['easebuzz_merchant_key', 'easebuzz_salt_key', 'easebuzz_enviroment_name']
            ],
            [
                'id' => 'nmi',
                'fields' => ['is_nmi_enabled', 'nmi_api_private_key', 'nmi_unfo'],
                'required_fields' => ['nmi_api_private_key']
            ],
            [
                'id' => 'payu',
                'fields' => ['is_payu_enabled', 'payu_merchant_key', 'payu_salt_key', 'payu_mode', 'payu_unfo'],
                'required_fields' => ['payu_merchant_key', 'payu_salt_key', 'payu_mode']
            ],
            [
                'id' => 'paynow',
                'fields' => ['is_paynow_enabled', 'paynow_pay_integration_id', 'paynow_pay_integration_key', 'paynow_pay_merchant_email', 'paynow_mode', 'paynow_pay_unfo'],
                'required_fields' => ['paynow_pay_integration_id', 'paynow_pay_integration_key', 'paynow_pay_merchant_email', 'paynow_mode']
            ],
            [
                'id' => 'sofort',
                'fields' => ['is_sofort_enabled', 'sofort_publishable_key', 'sofort_secret_key', 'sofort_unfo'],
                'required_fields' => ['sofort_publishable_key', 'sofort_secret_key']
            ],
            [
                'id' => 'esewa',
                'fields' => ['is_esewa_enabled', 'esewa_merchant_key', 'esewa_mode', 'esewa_unfo'],
                'required_fields' => ['esewa_merchant_key', 'esewa_mode']
            ],
            [
                'id' => 'dpopay',
                'fields' => ['is_dpopay_enabled', 'dpo_pay_Company_Token', 'dpo_pay_Service_Type', 'dpo_pay_unfo'],
                'required_fields' => ['dpo_pay_Company_Token', 'dpo_pay_Service_Type']
            ],
            [
                'id' => 'braintree',
                'fields' => ['is_braintree_enabled', 'braintree_pay_merchant_id', 'braintree_pay_public_key', 'braintree_pay_private_key', 'braintree_mode', 'braintree_pay_unfo'],
                'required_fields' => []
            ],
            [
                'id' => 'powertranz',
                'fields' => ['is_powertranz_enabled', 'powertranz_pay_merchant_id', 'powertranz_pay_processing_password', 'powertranz_pay_production_url', 'powertranz_mode', 'powertranz_pay_unfo'],
                'required_fields' => []
            ],
            [
                'id' => 'sslcommerz',
                'fields' => ['is_sslcommerz_enabled', 'sslcommerz_pay_store_id', 'sslcommerz_pay_secret_key', 'sslcommerz_mode', 'sslcommerz_pay_unfo'],
                'required_fields' => []
            ]
        ];
    }
}