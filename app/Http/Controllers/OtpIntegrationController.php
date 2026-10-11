<?php

namespace App\Http\Controllers;

use App\Models\OtpIntegration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
class OtpIntegrationController extends Controller
{
    public function index()
    {
        $storeId = getCurrentStore();

        $integration = OtpIntegration::where('store_id', $storeId)->first();

        return view('otp.integration', compact('integration'));
    }



public function save(Request $request)
{
    $storeId = getCurrentStore();

    $request->validate([
        'base_url' => 'required|url',
        'api_secret' => 'nullable|string',
        'otp_channel' => 'required|in:sms,whatsapp',
        'sms_mode' => 'nullable|in:devices,credits',
        'sms_sim' => 'nullable|in:1,2',
        'sms_priority' => 'nullable|in:0,1,2',
    ]);

    $integration = OtpIntegration::firstOrNew([
        'store_id' => $storeId
    ]);

    $integration->base_url = rtrim($request->base_url, '/');

    if ($request->filled('api_secret')) {
        $integration->api_secret = $request->api_secret;
    }

    $integration->otp_channel = $request->otp_channel;

    $integration->sms_mode = $request->sms_mode;
    $integration->sms_device = $request->sms_device;
    $integration->sms_gateway = $request->sms_gateway;
    $integration->sms_sim = $request->sms_sim;
    $integration->sms_priority = $request->sms_priority ?? 0;

    $integration->whatsapp_account = $request->whatsapp_account;

    $integration->is_active = $request->boolean('is_active');

    $integration->save();

    session()->put([
        'setting_tab' => 'otp_setting'
    ]);

    return redirect()->back()->with(
        'success',
        __('LYNOMIA OTP settings saved successfully.')
    );
}




    public function save1(Request $request)
    {
        $storeId = getCurrentStore();

     $request->validate([
    'base_url' => 'required|url',
    'api_secret' => 'nullable|string',
    'otp_channel' => 'required|in:sms,whatsapp',
    'sms_mode' => 'nullable|in:devices,credits',
    'sms_sim' => 'nullable|in:1,2',
    'sms_priority' => 'nullable|in:0,1,2',
]);
        $integration = OtpIntegration::firstOrNew([
            'store_id' => $storeId,
        ]);

        $integration->base_url = rtrim($request->base_url, '/');

        // إذا ترك المستخدم الـ secret فارغ، لا نمسح القديم
        if ($request->filled('api_secret')) {
            $integration->api_secret = $request->api_secret;
        }

        $integration->sms_enabled = $request->boolean('sms_enabled');
        $integration->sms_mode = $request->sms_mode;
        $integration->sms_device = $request->sms_device;
        $integration->sms_gateway = $request->sms_gateway;
        $integration->sms_sim = $request->sms_sim;
        $integration->sms_priority = $request->sms_priority ?? 0;
$integration->otp_channel = $request->otp_channel;
        $integration->whatsapp_enabled = $request->boolean('whatsapp_enabled');
        $integration->whatsapp_account = $request->whatsapp_account;

        $integration->is_active = $request->boolean('is_active');

        $integration->save();

        return redirect()->back()->with('success', __('OTP integration settings saved successfully.'));
    }


public function test(Request $request)
{
    $storeId = getCurrentStore();

    $request->validate([
        'phone' => ['required', 'string'],

        ]);

    $integration = OtpIntegration::where('store_id', $storeId)->first();

    if (!$integration) {
        return response()->json([
            'success' => false,
            'message' => __('Please save OTP settings first.'),
        ]);
    }

    $apiSecret = $request->filled('api_secret')
        ? $request->api_secret
        : $integration->api_secret;

    if (empty($apiSecret)) {
        return response()->json([
            'success' => false,
            'message' => __('API Secret is missing.'),
        ]);
    }

    $baseUrl = rtrim(
        $request->base_url ?: $integration->base_url,
        '/'
    );

    $otp = random_int(100000, 999999);


$phone = $this->normalizePhone($request->phone);


    $message = "Your verification code is: {$otp}";

    try {

        /*
        |--------------------------------------------------------------------------
        | SMS
        |--------------------------------------------------------------------------
        */

     if ($integration->otp_channel === 'sms') {

            $smsMode = $request->sms_mode ?: $integration->sms_mode;

            if (!in_array($smsMode, ['devices', 'credits'])) {
                return response()->json([
                    'success' => false,
                    'message' => __('Invalid SMS mode.'),
                ]);
            }

            $data = [
                [
                    'name' => 'secret',
                    'contents' => $apiSecret,
                ],
                [
                    'name' => 'mode',
                    'contents' => $smsMode,
                ],
                [
                    'name' => 'phone',
    'contents' => $phone,
                ],
                [
                    'name' => 'message',
                    'contents' => $message,
                ],
            ];

            /*
            |--------------------------------------------------------------------------
            | Devices Mode
            |--------------------------------------------------------------------------
            */

            if ($smsMode === 'devices') {

                $device = $request->sms_device ?: $integration->sms_device;
                $sim = $request->sms_sim ?: $integration->sms_sim;
                $priority = $request->sms_priority ?? $integration->sms_priority ?? 0;

                if (empty($device)) {
                    return response()->json([
                        'success' => false,
                        'message' => __('Device ID is missing.'),
                    ]);
                }

                $data[] = [
                    'name' => 'device',
                    'contents' => $device,
                ];

                if (!empty($sim)) {
                    $data[] = [
                        'name' => 'sim',
                        'contents' => $sim,
                    ];
                }

                $data[] = [
                    'name' => 'priority',
                    'contents' => $priority,
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | Credits Mode
            |--------------------------------------------------------------------------
            */

            if ($smsMode === 'credits') {

                $gateway = $request->sms_gateway ?: $integration->sms_gateway;

                if (empty($gateway)) {
                    return response()->json([
                        'success' => false,
                        'message' => __('Gateway ID is missing.'),
                    ]);
                }

                $data[] = [
                    'name' => 'gateway',
                    'contents' => $gateway,
                ];
            }

            $response = Http::asMultipart()
                ->timeout(20)
                ->post(
                    $baseUrl . '/api/send/sms',
                    $data
                );

            $result = $response->json();

            if ($response->successful() && ($result['status'] ?? null) == 200) {
                return response()->json([
                    'success' => true,
                    'message' => __('Test SMS OTP sent successfully.'),
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? __('Unable to send SMS OTP.'),
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | WhatsApp
        |--------------------------------------------------------------------------
        */

     elseif ($integration->otp_channel === 'whatsapp') {

            $account = trim(
                $request->whatsapp_account ?: $integration->whatsapp_account
            );

            if (empty($account)) {
                return response()->json([
                    'success' => false,
                    'message' => __('WhatsApp Account ID is missing.'),
                ]);
            }

            $response = Http::asMultipart()
                ->timeout(20)
                ->post(
                    $baseUrl . '/api/send/whatsapp',
                    [
                        [
                            'name' => 'secret',
                            'contents' => $apiSecret,
                        ],
                        [
                            'name' => 'account',
                            'contents' => $account,
                        ],
                        [
                            'name' => 'recipient',
                        'contents' => $phone,

                        ],
                        [
                            'name' => 'type',
                            'contents' => 'text',
                        ],
                        [
                            'name' => 'message',
                            'contents' => $message,
                        ],
                        [
                            'name' => 'priority',
                            'contents' => 1,
                        ],
                    ]
                );

            $result = $response->json();

            if ($response->successful() && ($result['status'] ?? null) == 200) {
                return response()->json([
                    'success' => true,
                    'message' => __('Test WhatsApp OTP sent successfully.'),
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? __('Unable to send WhatsApp OTP.'),
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Invalid Channel
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'success' => false,
            'message' => __('Invalid OTP channel.'),
        ]);

    } catch (\Exception $e) {

        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }
}



private function normalizePhone($phone)
{
    $phone = trim($phone);

    // إزالة المسافات والشرطات والأقواس
    $phone = preg_replace('/[\s\-\(\)]+/', '', $phone);

    // الرقم أصلاً دولي
    if (str_starts_with($phone, '+')) {
        return $phone;
    }

    // رقم سوري محلي مثل 0936807754
    if (preg_match('/^09\d{8}$/', $phone)) {
        return '+963' . substr($phone, 1);
    }

    // رقم يبدأ 963 بدون +
    if (str_starts_with($phone, '963')) {
        return '+' . $phone;
    }

    return $phone;
}


}