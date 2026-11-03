<?php

namespace App\Http\Controllers;

use App\Models\OtpIntegration;
use App\Policies\UserPolicy;
use App\Services\OtpTransport;
use App\Services\StoreSettingsAccess;
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
     * ==========================================================================================
     * THIS DELEGATES TO `StoreSettingsAccess::permits()`, AND MY FIRST DRAFT DID NOT. THAT WAS A
     * DEFECT, FOUND BY THE `tenant-authz-reviewer` BEFORE THIS WAS COMMITTED.
     * ==========================================================================================
     * The first draft hand-wrote three clauses ending in `isAbleTo('Manage Store Setting')`. Two
     * things were wrong with it, and the repository had already written both down:
     *
     *   1. WRONG SCREEN'S PERMISSION. The OTP tab is rendered by `SettingController`'s
     *      `otp_setting` branch (:2143) -- i.e. the `setting.index` screen, whose permission is
     *      `Manage Setting` (`CompanyMenuListener:964`). `Manage Store Setting` belongs to the
     *      SEPARATE "Store Setting" entry, route `app-setting.index` (`CompanyMenuListener:88`).
     *      `StoreSettingsAccess`'s own docblock says so in as many words: *"NOT `Manage Store
     *      Setting`: that belongs to the separate 'Store Setting' entry (route
     *      app-setting.index), and a role holding only it must not be handed the SMTP password."*
     *      And `api_secret` is a restricted name under that same service --
     *      `GatewaySecretRedactor`'s pattern is `/secret|private|salt|.../i` -- so a staff member
     *      holding only `Manage Store Setting` is refused the store's SMTP password by
     *      `StoreSettingsAccess` and would have been handed its OTP secret by my draft.
     *
     *   2. A RE-IMPLEMENTED STRING COMPARISON FOR THE TENANT OWNER.
     *      `strtolower(rtrim($type, ' ')) === 'admin'` admits `'Admin'` and `'admin '`, which
     *      `User::creatorId()` (a `==` on the exact value) calls STAFF. The same docblock warns
     *      against exactly this: *"deliberately the application's own definition of 'root of a
     *      tenant' and not a re-implemented string comparison: a staff row whose type merely
     *      NORMALISES to 'admin' ('Admin', ' admin') is a staff member for creatorId() and for
     *      this gate."* Not tenant-reachable (`RolePolicy::isReservedName()` normalises the same
     *      way and refuses those names at both writers of `users.type`), but it fails WIDE.
     *
     * THE SAME MISTAKE IS RECORDED IN THIS REPOSITORY'S OWN QUEUE, one row up:
     * `P01-SECURITY-WORK-QUEUE.md`'s F5 row reads *"fixed: `StoreSettingsAccess` (operator ->
     * owner -> `Manage Setting`); 403, never 401. **First draft used the wrong permission
     * (`Manage Store Setting` is the Store Setting screen)**"*. So this is the second time, and
     * the answer is to stop hand-writing the gate and call the service that already encodes it.
     *
     * `StoreSettingsAccess::permits()` IS operator -> tenant owner (`creatorId() === id`) ->
     * `isAbleTo('Manage Setting')`, in that order and for the reasons CLAUDE.md gives: there is
     * NO super-admin bypass here (`permissions_as_gates => false`), so `isAbleTo()` alone refuses
     * the platform operator and the operator limb must come first. It is already covered by
     * `tests/Feature/Security/SettingsRestrictedWriteTest`, so this change REMOVES a gate that
     * needed its own tests rather than adding one.
     *
     * NO PERMISSION NAME IS INVENTED, per the owner's instruction: `Manage Setting` is the
     * Settings menu's own permission, is what `StoreSettingsAccess` has shipped with since the F5
     * fix, and is not held by the staff role -- so nothing is granted to ordinary staff.
     *
     * ------------------------------------------------------------------------------------------
     * AND ONE THING THE GATE CANNOT DECIDE: `getCurrentStore()` DEFAULTS THE OPERATOR TO STORE 1.
     * ------------------------------------------------------------------------------------------
     * An earlier version of this docblock said `getCurrentStore()` "derives from the caller's own
     * store and takes no request input, so this gate decides WHETHER, never WHOSE". The second
     * half was false for one of the three principals. `app/Helper/helper.php:774-775` returns the
     * LITERAL `1` for `users.type == 'super admin'` with an empty `current_store`, so an operator
     * in that state would read and overwrite STORE 1's `base_url` and `api_secret` and transmit
     * store 1's secret -- wrong-tenant exposure, not escalation, since the operator is entitled to
     * everything. `CouponController:171-177`, `StoreFinanceController:332-335` (F157),
     * `TaxMethodController:107-108` and `FlashSaleController:66-69` all refuse exactly this and
     * say why; `assertStoreResolved()` below is the same pattern.
     *
     * The request-input half of that claim IS true, and was checked: `getCurrentStore()` has one
     * definition repo-wide and reads `users.current_store`, never a query string, header or
     * caller-set session value.
     */
    private function mayManageOtpIntegration(): bool
    {
        return StoreSettingsAccess::permits(auth()->user());
    }

    /**
     * THE OPERATOR MUST NOT INHERIT STORE 1 BY DEFAULT. See the note above.
     *
     * Returns the resolved store id, or null when the caller is the platform operator with no
     * `current_store` -- in which case `getCurrentStore()` would answer the hardcoded `1` and
     * every write here would land on whichever tenant holds that id. Each caller turns a null
     * into its own refusal shape, because `save()` redirects and `test()` answers JSON.
     *
     * A tenant principal is unaffected: `current_store` is set for them, and the two writers of
     * that column are both constrained to their own tenant, so this returns their own store id
     * exactly as before.
     */
    /**
     * A provider address reduced to what is safe to log: scheme and host, nothing else.
     *
     * F4. `$baseUrl` is caller-supplied (`$request->base_url ?: $integration->base_url`), and one
     * of the shapes `SafeRemoteFetch::check` REFUSES is credentials in the URL -- the suite drives
     * `https://user:pass@sms.example.test`. So the exact input that reaches the refusal log is the
     * one that would write a credential into `storage/logs/`. Given the still-open incident #120,
     * that is not a trade worth making for a diagnostic.
     *
     * Userinfo, path, query and fragment are all dropped. An unparseable value yields
     * `'(unparseable)'` rather than being echoed back.
     */
    private static function loggableHost(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || empty($parts['host'])) {
            return '(unparseable)';
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

        return $scheme . $parts['host'] . $port;
    }

    private function resolvedStoreId(): ?int
    {
        $user = auth()->user();

        if ($user !== null
            && UserPolicy::isOperator($user)
            && empty($user->current_store)) {
            return null;
        }

        $storeId = getCurrentStore();

        return $storeId === null ? null : (int) $storeId;
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

    // F2: null means the platform operator has no `current_store`, and `getCurrentStore()` would
    // answer the hardcoded 1. Refuse rather than write another tenant's credential.
    $storeId = $this->resolvedStoreId();

    if ($storeId === null) {
        return redirect()->back()->with(
            'error',
            __('No store is selected. Choose a store before changing its OTP integration.')
        );
    }

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




    /**
     * A SECOND, COMPLETE WRITER OF THE OTP DESTINATION AND SECRET -- and it is UNROUTED.
     *
     * [2026-11-02, P1-SEC-55 follow-up] Found by finishing the writer census Decision 2 implies
     * rather than only the ROUTE census. Measured:
     *
     *   - `grep -rn 'save1' app/ routes/ resources/ packages/ tests/` finds ONE hit: this
     *     declaration. No route registers it, no view posts to it, nothing calls it.
     *   - `routes/web.php` registers exactly three: `index` (:349), `save` (:352), `test` (:346).
     *   - The settings form posts to `route('otp.integration.save')` and the test button hits
     *     `route('otp.integration.test')` (resources/views/setting/otp_setting.blade.php:27, :388),
     *     so the live UI never reaches here.
     *   - It is otherwise a near-duplicate of `save()`: it writes `base_url`, keeps the stored
     *     `api_secret` unless a new one is supplied, and saves the same row.
     *
     * SO WHY GATE DEAD CODE. Because the gate belongs to the ACTION, not to the route list, and
     * `save` and `save1` differ by one character. A later route registration -- the easiest
     * possible mistake here -- would hand an unauthorised staff member a full rewrite of the OTP
     * destination, and `CLAUDE.md` records that a later `Route::` declaration silently replaces an
     * earlier one for the same method+URI, dropping its middleware with it. Gating costs nothing
     * (nothing can reach this today) and removes that whole class of regression.
     *
     * DELETING IT WOULD BE BETTER STILL, and that is deliberately NOT done here: removing a
     * method is a scope decision for the owner, and this change is meant to be the authorization
     * one. `OtpIntegrationController::index()` is in the same condition for a different reason --
     * it renders `view('otp.integration')`, and `view()->exists('otp.integration')` is FALSE, so
     * the registered `GET /otp-integration` route throws. Both are recorded in
     * docs/security/P1-SEC-55-OUTBOUND-SENDER-CENSUS.md rather than quietly tidied.
     */
    public function save1(Request $request)
    {
        if (! $this->mayManageOtpIntegration()) {
            return redirect()->back()->with(
                'error',
                __('You do not have permission to manage OTP integration settings.')
            );
        }

        // F2, same as save(). See resolvedStoreId().
        $storeId = $this->resolvedStoreId();

        if ($storeId === null) {
            return redirect()->back()->with(
                'error',
                __('No store is selected. Choose a store before changing its OTP integration.')
            );
        }

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

    /*
     * F2: refuse before the row is read, so an operator with no `current_store` cannot cause
     * STORE 1's `api_secret` to be transmitted. Same HTTP 200 + success:false shape as every
     * other refusal in this method, so the settings page's JavaScript contract is unchanged.
     */
    $storeId = $this->resolvedStoreId();

    if ($storeId === null) {
        return response()->json([
            'success' => false,
            'message' => __('No store is selected. Choose a store before testing its OTP integration.'),
        ]);
    }

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
                    // F4: scheme+host only. One of the shapes SafeRemoteFetch REFUSES is
                    // userinfo-in-URL (`https://user:pass@host`), so logging $baseUrl whole would
                    // put a credential in storage/logs on exactly the input that reaches here.
                    'base_url' => self::loggableHost($baseUrl),
                    'came_from_request' => $request->filled('base_url'),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => __('That OTP provider address is not allowed. Use a public http or https URL.'),
                ]);
            }

            /*
             * [CORRECTED] BOTH HALVES OF THE ORIGINAL CONDITION, which an earlier version of this
             * change dropped one of.
             *
             * It was `$response->successful() && ($result['status'] ?? null) == 200`. Moving the
             * send onto `SafeWebhook::send()` kept the provider's `status` field and lost the HTTP
             * status, because that method answers the body alone -- so a provider replying with an
             * HTTP error while its body claimed `status: 200` began to SUCCEED. The owner caught
             * it and asked for the dual condition back without weakening the pinning, the
             * redirect refusal or the authorization.
             *
             * `OtpTransport::succeeded()` is that condition, in one place rather than copied into
             * each branch, and it reads the HTTP status through the opt-in
             * `SafeWebhook::sendWithStatus()` -- the same single `plan()`, so every protection is
             * unchanged.
             */
            if (OtpTransport::succeeded($sent)) {
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
                    // F4: scheme+host only. One of the shapes SafeRemoteFetch REFUSES is
                    // userinfo-in-URL (`https://user:pass@host`), so logging $baseUrl whole would
                    // put a credential in storage/logs on exactly the input that reaches here.
                    'base_url' => self::loggableHost($baseUrl),
                    'came_from_request' => $request->filled('base_url'),
                    'channel' => 'whatsapp',
                ]);

                return response()->json([
                    'success' => false,
                    'message' => __('That OTP provider address is not allowed. Use a public http or https URL.'),
                ]);
            }

            // Both halves, same helper as the SMS branch above. See the note there.
            if (OtpTransport::succeeded($sent)) {
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