<?php

namespace App\Http\Controllers;

use App\Models\OtpIntegration;
use App\Policies\UserPolicy;
use App\Services\OtpTransport;
use Illuminate\Http\Request;

class OtpIntegrationController extends Controller
{
    /**
     * MAY THIS PRINCIPAL MANAGE THIS STORE'S OTP INTEGRATION? Owner policy, 2026-11-02, Decision 2:
     * "Only authorized store administrators and staff with an explicitly granted OTP-integration
     * management permission may save or test OTP integration settings."
     *
     * WHY THIS IS NEEDED. Measured before this change: a user of `type === 'staff'` holding NO
     * permission at all could POST /otp-integration/test with a `base_url` of their choosing and
     * receive HTTP 200 while the STORED `api_secret` was transmitted to that host -- defeating the
     * settings view, which renders that field as `type="password"` with no `value` precisely so it
     * cannot be read back. `grep -nE "isAbleTo|authorize\(|->can\(|@can|middleware\("` on this
     * controller exited 1: zero hits. The routes carried only
     * ['auth','xss','setlocate','verified'].
     *
     * NO NEW PERMISSION NAME IS INVENTED, per the owner's instruction. `Manage Store Setting`
     * already exists and is already seeded -- measured in this repository: id 19, held by the
     * store-owner role, NOT held by the staff role -- so nothing is granted to ordinary staff by
     * this change, and an owner can grant it to a specific staff member explicitly.
     *
     * THE ORDER OF THE THREE CLAUSES IS THE POINT, and CLAUDE.md §1 is why:
     *
     *   1. OPERATOR FIRST. `config/laratrust.php` sets `permissions_as_gates => false` and this
     *      codebase has NO super-admin bypass, so `isAbleTo()` ALONE REFUSES THE PLATFORM
     *      OPERATOR. Measured here: for `users.type = 'super admin'`,
     *      `isAbleTo('Manage Store Setting')` is FALSE. `UserPolicy::isOperator()` is this
     *      project's single definition of that test and rtrims SPACE only, to agree with MySQL's
     *      PAD SPACE -- a trailing space is equal to the engine, a leading one is not.
     *   2. THE STORE ADMINISTRATOR. `users.type === 'admin'` is the root of its own tenant
     *      (`User::creatorId()` returns `$this->id` for that type), and the owner's policy admits
     *      "authorized store administrators" by name. The same SPACE-only rtrim, so the two
     *      clauses cannot disagree about a value MySQL considers equal.
     *   3. EVERYONE ELSE needs the permission, explicitly granted.
     *
     * TENANT ISOLATION IS NOT THIS GATE'S JOB and is unchanged: `save()` and `test()` both resolve
     * the row through `getCurrentStore()`, which derives from the caller's own store and takes no
     * request input, so this gate decides WHETHER, never WHOSE.
     */
    private function mayManageOtpIntegration(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        if (UserPolicy::isOperator($user)) {
            return true;
        }

        if (strtolower(rtrim((string) ($user->type ?? ''), ' ')) === 'admin') {
            return true;
        }

        return $user->isAbleTo('Manage Store Setting');
    }

    public function index()
    {
        $storeId = getCurrentStore();

        $integration = OtpIntegration::where('store_id', $storeId)->first();

        return view('otp.integration', compact('integration'));
    }



public function save(Request $request)
{
    // Owner policy, Decision 2. Refused BEFORE validation and before any write, so an
    // unauthorised caller cannot change the OTP destination at all. The redirect-with-error
    // shape is this controller's existing refusal idiom for `save()`, so no response contract
    // changes for the settings page.
    if (! $this->mayManageOtpIntegration()) {
        return redirect()->back()->with(
            'error',
            __('You do not have permission to manage OTP integration settings.')
        );
    }

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
    /*
     * Owner policy, Decision 2. Refused BEFORE the row is read, so an unauthorised caller cannot
     * cause transmission of the stored credential -- which is the actual harm here, since this
     * method sends `$integration->api_secret` and the settings view deliberately never renders
     * it back.
     *
     * HTTP 200 WITH success:false IS DELIBERATE, and it preserves an existing contract rather
     * than inventing one. Every other refusal in this method answers that shape, the settings
     * page's JavaScript reads `{success, message}`, and CLAUDE.md records that a NEW non-200 on a
     * path a shipped client calls is a user-visible regression. This is a web route, not
     * `api/admin/*`, so no Flutter surface is involved either way.
     */
    if (! $this->mayManageOtpIntegration()) {
        return response()->json([
            'success' => false,
            'message' => __('You do not have permission to manage OTP integration settings.'),
        ]);
    }

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

    /*
     * P1-SEC-45 -- THE OUTBOUND HOST IS JUDGED BEFORE THE STORE'S OTP SECRET IS SENT TO IT.
     *
     * `save()` validates `base_url` as `required|url`. THIS method validated only `phone`, and
     * then took `$request->base_url` in preference to the stored row -- so a caller chose the
     * host, and the body carried `secret => $apiSecret`, which defaults to the STORED
     * `$integration->api_secret`.
     *
     * MEASURED before the fix, as a `staff` user holding no permission at all:
     *     POST /otp-integration/test  base_url=http://169.254.169.254  phone=...
     *     -> HTTP 200 {"success":true,"message":"Test SMS OTP sent successfully."}
     *     -> outbound POST http://169.254.169.254/latest/meta-data/api/send/sms
     *     -> with the store's api_secret in the body
     * The handler also reflects the remote body (`$result['message']`) and `$e->getMessage()`,
     * which makes it a semi-blind SSRF with error reflection on top of the credential leak.
     *
     * WHY IT IS WAVE 5. `routes/web.php` records that anonymously this route used to answer 500
     * because `otp_integrations` was undeclared -- one of GATE F's fifteen. The fix taken then was
     * to add `auth`; the caller-chosen-URL half was never closed, and the table now exists in
     * every environment, so the handler reaches the HTTP call.
     *
     * THE GUARD IS THE ONE THIS CODEBASE ALREADY HAS, not a new one. `SafeWebhook::allows()` is
     * the control P1-SEC-33 built for exactly this shape -- a merchant-configured URL becoming a
     * network request -- and `WebhookController` already calls it on save. It judges through
     * `SafeRemoteFetch::check`: http/https only, no credentials in the URL, no
     * private/loopback/link-local/metadata address on ANY of the host's records, no numeric host
     * spelling.
     *
     * The override is KEPT rather than removed, so a merchant can still test a URL before saving
     * it; what changes is that an unroutable or internal one is refused before anything is sent.
     */
    /*
     * [2026-11-02, P1-SEC-55] THE `SafeWebhook::allows()` PRE-CHECK THAT USED TO SIT HERE IS GONE,
     * AND ITS REMOVAL IS THE FIX RATHER THAN A RELAXATION.
     *
     * `allows()` is VERDICT-ONLY -- it is `plan() !== null`, a bool. It judged this host and then
     * returned, pinning nothing and handing this method no pinned URL; the send below then issued
     * its own `Http::asMultipart()->post()` with no `CURLOPT_RESOLVE` and no
     * `allow_redirects => false`. MEASURED in the booted application: a first hop answering
     * `307` was FOLLOWED, delivering the identical multipart body -- INCLUDING the `secret`
     * field -- to a second hop that `allows()` would itself have refused. DNS rebinding reached
     * the same end by resolving twice.
     *
     * Every send now goes through `App\Services\OtpTransport`, which calls
     * `SafeWebhook::send()` -- the half that judges AND pins AND refuses redirects -- and which
     * judges the COMPLETE url, path included, so what is judged is what is requested. There is
     * therefore ONE decision about the destination, in one place, instead of a verdict here and
     * an unprotected request there. A refusal is reported below, where the outcome is read.
     */

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

            $sent = OtpTransport::post($baseUrl, '/api/send/sms', $data);

            if ($sent['outcome'] === OtpTransport::REFUSED) {
                \Log::warning('OTP test refused: the provider address is not a permitted outbound host.', [
                    'store_id' => $storeId,
                    'base_url' => $baseUrl,
                    'came_from_request' => $request->filled('base_url'),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => __('That OTP provider address is not allowed. Use a public http or https URL.'),
                ]);
            }

            /*
             * ONE DELIBERATE DIFFERENCE FROM THE PREVIOUS CONDITION, stated rather than buried.
             * It was `$response->successful() && ($result['status'] ?? null) == 200` -- the HTTP
             * status AND the provider's own status field. `SafeWebhook::send()` returns the body
             * only, so the HTTP code is not observable through it, and the provider's `status`
             * field -- which IS its documented contract -- decides alone.
             *
             * The two differ in exactly one case: a provider answering an HTTP error while its
             * body claims `status: 200` now succeeds where it previously failed. Every other
             * case is identical or stricter (a non-JSON body still fails, and a refused or
             * failed transport cannot reach here as a success). If the owner wants the HTTP code
             * back, the change is to `SafeWebhook`, not here -- and it was left untouched on
             * purpose, because the direction was to use the existing implementation.
             */
            if ($sent['outcome'] === OtpTransport::ANSWERED && $sent['status'] === 200) {
                return response()->json([
                    'success' => true,
                    'message' => __('Test SMS OTP sent successfully.'),
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => $sent['provider_message'] ?? __('Unable to send SMS OTP.'),
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

            $sent = OtpTransport::post(
                $baseUrl,
                '/api/send/whatsapp',
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

            if ($sent['outcome'] === OtpTransport::REFUSED) {
                \Log::warning('OTP test refused: the provider address is not a permitted outbound host.', [
                    'store_id' => $storeId,
                    'base_url' => $baseUrl,
                    'came_from_request' => $request->filled('base_url'),
                    'channel' => 'whatsapp',
                ]);

                return response()->json([
                    'success' => false,
                    'message' => __('That OTP provider address is not allowed. Use a public http or https URL.'),
                ]);
            }

            if ($sent['outcome'] === OtpTransport::ANSWERED && $sent['status'] === 200) {
                return response()->json([
                    'success' => true,
                    'message' => __('Test WhatsApp OTP sent successfully.'),
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => $sent['provider_message'] ?? __('Unable to send WhatsApp OTP.'),
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

        /*
         * THE EXCEPTION TEXT IS NO LONGER RETURNED. It used to be, and it is how this endpoint
         * became a service oracle: a connection refusal names the host and the port
         * ("cURL error 7: ... port 6379 ... Connection refused"), and a remote banner arrives the
         * same way. It is logged where an operator can read it and NOT handed to the caller.
         *
         * `OtpTransport` does not throw for a network failure -- it answers `FAILED` with no
         * detail -- so this path is now reached only by a genuine programming fault, which is
         * exactly what belongs in a log and not in a response.
         */
        \Log::warning('OTP test failed with an exception.', [
            'store_id' => $storeId ?? null,
            'exception' => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => __('Unable to send the test OTP. Check the provider settings and try again.'),
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