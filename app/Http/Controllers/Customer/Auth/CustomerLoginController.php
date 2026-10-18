<?php

namespace App\Http\Controllers\Customer\Auth;

use App\Events\CreateCustomer;
use App\Models\Blog;
use App\Http\Controllers\Controller;
use App\Models\PageOption;
use App\Models\Store;
use App\Models\Student;
use App\Models\User;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Newsletter;
use App\Models\Utility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Qirolab\Theme\Theme;
use Illuminate\Support\Facades\Password;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Cookie;
use Illuminate\Support\Facades\Cache;
use App\Notifications\ResetPasswordNotification;
use App\Models\OtpIntegration;
use Illuminate\Support\Facades\Http;


class CustomerLoginController extends Controller
{
    /**
     * Wrong OTP answers allowed per issued code on the web reset flow, after which the code is
     * destroyed. Matches App\Services\CustomerPasswordResetService::MAX_ATTEMPTS, which bounds
     * the API flow, so the two surfaces of one feature do not disagree about what is allowed.
     */
    public const MAX_OTP_ATTEMPTS = 5;















    private function validator(Request $request, $slug)
    {
        $store    = getStore($slug);
        $storeId = $store->id;
        //custom validation error messages.
        $messages = [
            'email.exists' => __('These credentials do not match our records.'),
        ];
        $validate = Validator::make(
            $request->all(),
            [
                'email' => [
                    'required',
                    'string',
                    'email',
                    'min:5',
                    'max:191',
                    function ($attribute, $value, $fail) use ($storeId) {
                        $customerExists = \DB::table('customers')
                            ->where('email', $value)
                            ->where('store_id', $storeId)
                            ->exists();

                        if (!$customerExists) {
                            $fail(__("These credentials do not match our records."));
                        }
                    },
                ],
                'password' => [
                    'required',
                    'string',
                    'min:4',
                    'max:255',
                ],
            ]
        );

        $vali     = Customer::where('email', $request->email)->where('store_id', $store->id)->count();
        if ($validate->fails()) {

            $message = $validate->getMessageBag();
            return false;
        } elseif ($vali > 0) {
            return true;
        } else {
            return false;
        }
    }











public function sendPasswordOtp(Request $request, $storeSlug)
{
    $request->validate([
        'mobile' => ['required', 'string'],
    ]);

    $store = getStore($storeSlug);

    if (!$store) {
        return redirect()->back()->with('error', __('Store not available.'));
    }

$customer = Customer::where('mobile', $request->mobile)
    ->where('store_id', $store->id)
    ->first();

if (!$customer) {
    return redirect()->back()->withErrors([
        'mobile' => __('No account found with that mobile number.')
    ]);
}

$phone = $this->normalizePhone($customer->mobile);

    $integration = OtpIntegration::where('store_id', $store->id)
        ->where('is_active', true)
        ->first();

    if (!$integration) {
        return redirect()->back()->with(
            'error',
            __('OTP service is not configured for this store.')
        );
    }

    if (empty($integration->api_secret)) {
        return redirect()->back()->with(
            'error',
            __('OTP API Secret is missing.')
        );
    }

    $otp = random_int(100000, 999999);

    // A new code starts a new attempt budget; a stale counter from a previous round must not
    // lock the customer out of the code they just asked for.
    session()->forget('password_otp_attempts_' . $customer->id);

    // نخزن OTP مشفر
    $customer->password_otp = Hash::make((string) $otp);
    $customer->password_otp_datetime = now();
    $customer->save();

    $message = "Your verification code is: {$otp}";

    $baseUrl = rtrim($integration->base_url, '/');

    try {

        /*
        |--------------------------------------------------------------------------
        | SMS
        |--------------------------------------------------------------------------
        */

        if ($integration->otp_channel === 'sms') {

            if (!in_array($integration->sms_mode, ['devices', 'credits'])) {
                return redirect()->back()->with(
                    'error',
                    __('Invalid SMS mode.')
                );
            }

            $data = [
                [
                    'name' => 'secret',
                    'contents' => $integration->api_secret,
                ],
                [
                    'name' => 'mode',
                    'contents' => $integration->sms_mode,
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

            if ($integration->sms_mode === 'devices') {

                if (empty($integration->sms_device)) {
                    return redirect()->back()->with(
                        'error',
                        __('SMS Device ID is missing.')
                    );
                }

                $data[] = [
                    'name' => 'device',
                    'contents' => $integration->sms_device,
                ];

                if (!empty($integration->sms_sim)) {
                    $data[] = [
                        'name' => 'sim',
                        'contents' => $integration->sms_sim,
                    ];
                }

                $data[] = [
                    'name' => 'priority',
                    'contents' => $integration->sms_priority ?? 0,
                ];
            }

            if ($integration->sms_mode === 'credits') {

                if (empty($integration->sms_gateway)) {
                    return redirect()->back()->with(
                        'error',
                        __('SMS Gateway ID is missing.')
                    );
                }

                $data[] = [
                    'name' => 'gateway',
                    'contents' => $integration->sms_gateway,
                ];
            }

            $response = Http::asMultipart()
                ->timeout(20)
                ->post(
                    $baseUrl . '/api/send/sms',
                    $data
                );
        }

        /*
        |--------------------------------------------------------------------------
        | WhatsApp
        |--------------------------------------------------------------------------
        */

        elseif ($integration->otp_channel === 'whatsapp') {

            if (empty($integration->whatsapp_account)) {
                return redirect()->back()->with(
                    'error',
                    __('WhatsApp Account ID is missing.')
                );
            }

            $response = Http::asMultipart()
                ->timeout(20)
                ->post(
                    $baseUrl . '/api/send/whatsapp',
                    [
                        [
                            'name' => 'secret',
                            'contents' => $integration->api_secret,
                        ],
                        [
                            'name' => 'account',
                            'contents' => $integration->whatsapp_account,
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
        }

        else {
            return redirect()->back()->with(
                'error',
                __('Invalid OTP channel.')
            );
        }

        $result = $response->json();

        if (!$response->successful() || ($result['status'] ?? null) != 200) {

            return redirect()->back()->with(
                'error',
                $result['message'] ?? __('Unable to send OTP.')
            );
        }

        /*
        |--------------------------------------------------------------------------
        | حفظ بيانات مؤقتة بالـ Session
        |--------------------------------------------------------------------------
        */

        session([
            'password_otp_customer_id' => $customer->id,
            'password_otp_store_id' => $store->id,
            'password_otp_store_slug' => $storeSlug,
        ]);

        return redirect()->route(
            'customer.password.otp.form',
            $storeSlug
        )->with(
            'success',
            __('Verification code sent successfully.')
        );

    } catch (\Exception $e) {

        return redirect()->back()->with(
            'error',
            $e->getMessage()
        );
    }
}
public function passwordOtpForm($storeSlug)
{
    $store = getStore($storeSlug);

    if (!$store) {
        return redirect()->route('customer.password.request', $storeSlug)
            ->with('error', __('Store not available.'));
    }

    $customerId = session('password_otp_customer_id');
    $storeId = session('password_otp_store_id');

    if (!$customerId || !$storeId || (int) $storeId !== (int) $store->id) {
        return redirect()->route('customer.password.request', $storeSlug)
            ->with('error', __('OTP session expired. Please request a new code.'));
    }

    return view('front_end.Auth.password-otp', compact('store', 'storeSlug'));
}
public function verifyPasswordOtp(Request $request, $storeSlug)
{
    $request->validate([
        'otp' => ['required', 'digits:6'],
    ]);

    $store = getStore($storeSlug);

    if (!$store) {
        return redirect()->route('customer.password.request', $storeSlug)
            ->with('error', __('Store not available.'));
    }

    $customerId = session('password_otp_customer_id');
    $storeId = session('password_otp_store_id');

    if (!$customerId || !$storeId || (int) $storeId !== (int) $store->id) {
        return redirect()->route('customer.password.request', $storeSlug)
            ->with('error', __('OTP session expired. Please request a new code.'));
    }

    $customer = Customer::where('id', $customerId)
        ->where('store_id', $store->id)
        ->first();

    if (!$customer) {
        return redirect()->route('customer.password.request', $storeSlug)
            ->with('error', __('Customer not found.'));
    }

    if (
        empty($customer->password_otp) ||
        empty($customer->password_otp_datetime)
    ) {
        return back()->withErrors([
            'otp' => __('No active verification code found.')
        ]);
    }

    $expiresAt = \Carbon\Carbon::parse(
        $customer->password_otp_datetime
    )->addMinutes(5);

    if (now()->greaterThan($expiresAt)) {
        $customer->password_otp = null;
        $customer->password_otp_datetime = null;
        $customer->save();

        return back()->withErrors([
            'otp' => __('The verification code has expired.')
        ]);
    }

    // THE ATTEMPT LIMIT. The 5-minute expiry above was the only bound on guessing: this route
    // (routes/customer.php:128-131) carried no throttle, unlike the sibling login route at :102,
    // and nothing counted wrong answers. A six-digit code guessed as fast as the server answers,
    // with `customer.password.otp.send` free to mint a fresh one, is brute-forceable.
    //
    // It is unreachable TODAY only because `customers.password_otp` is an INTEGER column and no
    // OTP can verify at all on MySQL -- see P01-SEC-13 and
    // database/migrations/_sec13_pending/. Fixing that column without this limit would turn a
    // dead flow into an exploitable one, which is why the two belong in one release.
    //
    // Counted in the session, which is where this flow already keeps its customer and store
    // binding (:319-322), so the limit needs no schema and cannot be reset by re-requesting the
    // page. Exhausting it destroys the code rather than leaving the attacker guessing against a
    // counter they cannot see.
    $attemptKey = 'password_otp_attempts_' . $customer->id;

    if ((int) session($attemptKey, 0) >= self::MAX_OTP_ATTEMPTS) {
        $customer->password_otp = null;
        $customer->password_otp_datetime = null;
        $customer->save();

        session()->forget($attemptKey);

        return back()->withErrors([
            'otp' => __('Too many incorrect attempts. Please request a new code.')
        ]);
    }

    if (!Hash::check((string) $request->otp, $customer->password_otp)) {
        session([$attemptKey => (int) session($attemptKey, 0) + 1]);

        return back()->withErrors([
            'otp' => __('The verification code is incorrect.')
        ]);
    }

    session()->forget($attemptKey);

    session([
        'password_otp_verified' => true,
        'password_otp_verified_customer_id' => $customer->id,
        'password_otp_verified_store_id' => $store->id,
    ]);

    return redirect()->route(
        'customer.password.otp.reset.form',
        $storeSlug
    );
}
public function resetPasswordOtpForm($storeSlug)
{
    $store = getStore($storeSlug);

    if (!$store) {
        return redirect()->route('customer.password.request', $storeSlug)
            ->with('error', __('Store not available.'));
    }

    $verified = session('password_otp_verified');
    $customerId = session('password_otp_verified_customer_id');
    $storeId = session('password_otp_verified_store_id');

    if (
        !$verified ||
        !$customerId ||
        !$storeId ||
        (int) $storeId !== (int) $store->id
    ) {
        return redirect()->route('customer.password.request', $storeSlug)
            ->with('error', __('OTP verification is required.'));
    }

    return view(
        'front_end.Auth.reset-password-otp',
        compact('store', 'storeSlug')
    );
}

public function resetPasswordWithOtp(Request $request, $storeSlug)
{
    $request->validate([
        'password' => [
            'required',
            'confirmed',
            Rules\Password::defaults(),
        ],
    ]);

    $store = getStore($storeSlug);

    if (!$store) {
        return redirect()->route('customer.password.request', $storeSlug)
            ->with('error', __('Store not available.'));
    }

    $verified = session('password_otp_verified');
    $customerId = session('password_otp_verified_customer_id');
    $storeId = session('password_otp_verified_store_id');

    if (
        !$verified ||
        !$customerId ||
        !$storeId ||
        (int) $storeId !== (int) $store->id
    ) {
        return redirect()->route('customer.password.request', $storeSlug)
            ->with('error', __('OTP verification is required.'));
    }

    $customer = Customer::where('id', $customerId)
        ->where('store_id', $store->id)
        ->first();

    if (!$customer) {
        return redirect()->route('customer.password.request', $storeSlug)
            ->with('error', __('Customer not found.'));
    }

    $customer->forceFill([
        'password' => Hash::make($request->password),
        'remember_token' => Str::random(60),

        // تنظيف OTP بعد الاستخدام
        'password_otp' => null,
        'password_otp_datetime' => null,
    ])->save();

    // AND THE API SESSIONS. This path rotated remember_token but left every Sanctum token alive,
    // so a customer recovering their account on the WEB -- the only recovery route they can
    // actually reach in a browser -- did not evict an attacker holding an API token. This project
    // configures no Sanctum expiry, so that token never lapses on its own. The API recovery path
    // (Api\AuthController::forgot_password_save) revokes them; this one must too, and here there
    // is no in-flight client session to preserve, so all of them go.
    $customer->tokens()->delete();

    event(new PasswordReset($customer));

    session()->forget([
        'password_otp_customer_id',
        'password_otp_store_id',
        'password_otp_store_slug',

        'password_otp_verified',
        'password_otp_verified_customer_id',
        'password_otp_verified_store_id',
    ]);

    return redirect()
        ->route('customer.login', $storeSlug)
        ->with(
            'success',
            __('Your password has been reset successfully. You can now login.')
        );
}



private function normalizePhone($phone)
{
    $phone = preg_replace('/\s+/', '', $phone);

    // إذا الرقم يبدأ بـ +
    if (str_starts_with($phone, '+')) {
        return $phone;
    }

    // مثال سوري: 09XXXXXXXX
    if (preg_match('/^09\d{8}$/', $phone)) {
        return '+963' . substr($phone, 1);
    }

    // إذا مكتوب 963 بدون +
    if (str_starts_with($phone, '963')) {
        return '+' . $phone;
    }

    return $phone;
}



    public function showLoginForm($slug)
    {
        $store = getStore($slug);
        if (empty($store)) {
            return redirect()->back()->with('error', __('Store not available'));
        }

        if (Utility::CustomerAuthCheck($slug) == true) {
            return redirect()->route('landing_page', $slug);
        } else {
            return view('front_end.Auth.login', compact('store', 'slug'));
        }
    }

    public function login(Request $request, $slug, $cart = 0)
    {
        if ($this->validator($request, $slug) === true) {
            $store = Store::where(['slug' => $slug])->first();

            $credentials = [
                'email' => $request->email,
                'password' => $request->password,
            ];

            if (!is_null($store)) {
                $credentials['store_id'] = $store->id;
            } else {
                $credentials['store_id'] = 0;
            }

            // Retrieve the user based on the email address
            $customer = Customer::where('email', $credentials['email'])->where('store_id',$credentials['store_id'])->first();

            try {
                // If a user with the given email exists and the password matches using Bcrypt
                if ($customer && $customer->status == 1 && Hash::check($credentials['password'], $customer->password)) {
                    auth()->guard('customers')->login($customer, $request->filled('remember'));
                    if (module_is_active('ProductAffiliate')) {
                        do {
                            $code = rand(100000, 999999);
                        } while (Customer::where('affiliate_code', $code)->exists());

                        if (empty($customer->affiliate_code)) {
                            $customer->update(['affiliate_code' => $code]);
                        }
                    }
                    $request->merge([
                        'customer_id' => auth('customers')->user()->id,
                        'store_id' => $store->id,
                        'slug' => $store->slug
                    ]);
                    $cookie_session_id = Cookie::get('cart');
                    $cartItems = Cart::where('cookie_session_id', $cookie_session_id)->get();

                    foreach ($cartItems as $cartItem) {
                        $cartItem->customer_id =  auth('customers')->user()->id;
                        $cartItem->cookie_session_id = null; 
                        $cartItem->save(); 
                    }

                    return redirect()->route('landing_page', $slug);
                } else {
                    if ($customer && $customer->status == 0) {
                        return redirect()->back()->with('error', __('Your Account is de-activate,please contact your Administrator'));
                    }
                    return redirect()->back()->with('error', __('These credentials do not match our records'));
                }
            }
            catch(\Exception $e)
            {
                return redirect()->back()->with('error', __('These credentials do not match our records'));
            }
        } else {
            return redirect()->back()->with('error', __('These credentials do not match our records'));
        }
    }

    public function logout($slug)
    {
        $store = getStore($slug);
        if (empty($store)) {
            return redirect()->back()->with('error', __('Store not available'));
        }
        Auth::guard('customers')->logout();

        return redirect()->route('landing_page', $slug);
    }

    public function register($slug, $ref = null)
    {
        $store = getStore($slug);
        if (empty($store)) {
            return redirect()->back()->with('error', __('Store not available'));
        }
        
        $topNavItems = [];

        if($ref == '')
        {
            $ref = 0;
        }
        if (module_is_active('ProductAffiliate')) {
            $refCode = Customer::where('affiliate_code' , '=', $ref)->first();
            if(isset($refCode) && $refCode->affiliate_code != $ref)
            {
                return redirect()->route('customer.register',$slug);
            }
        }
        return view('front_end.Auth.register', compact('store', 'ref'));
    }

    protected function registerData($storeSlug, Request $request)
    {
        $store = getStore($storeSlug);
        $storeId = $store->id;
        if (empty($store)) {
            return redirect()->back()->with('error', __('Store not available'));
        }
        $validate = \Validator::make(
            $request->all(),
            [
                'first_name' => [
                    'required',
                    'string',
                    'max:255',
                ],
                'email' => [
                    'required',
                    'string',
                    'email',
                    'max:255',
                    function ($attribute, $value, $fail) use ($storeId) {
                        $customerExists = \DB::table('customers')
                            ->where('email', $value)
                            ->where('store_id', $storeId)
                            ->exists();

                        if ($customerExists) {
                            $fail(__("This Email already exist, please login."));
                        }
                    },
                ],
                'password' => [
                    'required',
                    'string',
                    'min:8',
                    'confirmed',
                ],
            ]
        );
        if ($validate->fails()) {
            $messages = $validate->getMessageBag();
            return redirect()->back()->with('error', $messages->first());
        }
        if (module_is_active('ProductAffiliate')) {
            do {
                $code = rand(100000, 999999);
            } while (Customer::where('affiliate_code', $code)->exists());
        }
        $customer = new Customer();
        $customer->first_name = $request->first_name;
        $customer->last_name = $request->last_name;
        $customer->email = $request->email;
        $customer->mobile = $request->mobile;
        $customer->password = Hash::make($request->password);
        $customer->type = 'customer';
        $customer->profile_image = 'avatar.png';
        if (module_is_active('ProductAffiliate')) {
            $customer->affiliate_code = $code;
            $customer->used_affiliate_code = $request->ref_code ?? '';
        }
        $customer->regiester_date = date('Y-m-d');
        $customer->last_active = date('Y-m-d');
        $customer->store_id = $store->id;
        $customer->save();

        if($request->subscribe == 'on')
        {
            try{
                $newsletters=new Newsletter();
                $newsletters->customer_id=$customer->id;
                $newsletters->email = $request->email;
                $newsletters->store_id = $store->id;
                $newsletters->save();
            }
            catch(\Exception $e)
            {
                return redirect()->back()->with('error', __($e));
            }
        }

        $slug = $storeSlug;
        $email = $request->email;
        $password = $request->password;
        $store_id = $store->id;
        event(new CreateCustomer($customer));

        if (Auth::guard('customers')->attempt($request->only(['email' => $email, 'password' => $password, 'store_id' => $store_id]), $request->filled('remember'))) {
            $cart = session()->get($store->slug);
            return redirect()->route('customer.login',$slug);
        }

        return redirect()->route('customer.login',$slug);
    }

    public function forgotPasswordForm($slug)
    {
        $store = getStore($slug);
        if (empty($store)) {
            return redirect()->back()->with('error', __('Store not available'));
        }
       
     
        if (Utility::CustomerAuthCheck($slug) == true) {
            return redirect()->route('landing_page', $slug);
        } else {
            return view('front_end.Auth.forgot-password', compact('store', 'slug'));
        }
    }

    public function forgotPassword(Request $request, $storeSlug)
    {
        try {
            $request->validate([
                'email' => ['required', 'email'],
            ]);

            $store = getStore($storeSlug);
            $settings = Utility::Setting($store->id);
            config(
                [
                    'mail.driver' => $settings['MAIL_DRIVER'],
                    'mail.host' => $settings['MAIL_HOST'],
                    'mail.port' => $settings['MAIL_PORT'],
                    'mail.encryption' => $settings['MAIL_ENCRYPTION'],
                    'mail.username' => $settings['MAIL_USERNAME'],
                    'mail.password' => $settings['MAIL_PASSWORD'],
                    'mail.from.address' => $settings['MAIL_FROM_ADDRESS'],
                    'mail.from.name' => $settings['MAIL_FROM_NAME'],
                ]
            );

            $customer = Customer::where('email', $request->email)->where('store_id', $store->id)->first();

            if (!$customer) {
                return redirect()->back()->withErrors(['email' => __('No account found with that email address.')]);
            }
            $customer->storeSlug = $storeSlug; // Set the storeSlug property
            $broker = Password::broker('customers');

            // STORE-SCOPED RESOLUTION. The existence check above is scoped to this store, but the
            // broker then resolved the account from the e-mail ALONE: Laravel's
            // EloquentUserProvider::retrieveByCredentials ANDs every credential key except the
            // password ones into the query, so ['email' => ...] matched across every tenant and
            // ->first() returned the LOWEST-ID customer holding that address.
            //
            // Measured before the fix: with the same address registered in store A (id 1) and
            // store B (id 2), a reset requested through STORE B's storefront minted the token for
            // the STORE A customer, and the subsequent reset wrote store A's password. Customer
            // registration only rejects a duplicate address within one store and never consults
            // other stores (:728-737), so two such rows are ordinary rather than contrived.
            //
            // That mattered beyond correctness: this handler mails through PER-STORE SMTP that the
            // merchant sets themselves (:826-837), so a merchant could register a customer in
            // their own store carrying another store's customer's address, request a reset, read
            // the token off their own relay and take over that other tenant's customer.
            //
            // Passing store_id makes the broker resolve the customer this storefront's request is
            // actually about. The reset side does the same.
            $response = $broker->sendResetLink(
                ['email' => $request->email, 'store_id' => $store->id],
                function ($user, $token) use ($customer) {
                    $customer->sendPasswordResetNotification($token);
                }
            );

            if ($response == Password::RESET_LINK_SENT) {
                return redirect()->back()->with(['success' => __('A password reset link has been sent to your email address.')]);
            } else {
                return redirect()->back()->with(['error' => __('Unable to send password reset link. Please try again.')]);
            }
        } catch (\Exception $e) {
            return redirect()->back()->with(['error' => __('Unable to send password reset link. Please try again.')]);
        }
    }

    public function resetPasswordForm($slug)
    {
        $store = getStore($slug);
        if (empty($store)) {
            return redirect()->back()->with('error', __('Store not available'));
        }
       
        
        if (Utility::CustomerAuthCheck($slug) == true) {
            return redirect()->route('landing_page', $slug);
        } else {
            return view('front_end.Auth.reset-password', compact('store', 'slug'));
        }
    }

    public function resetPassword(Request $request, $storeSlug)
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $store = getStore($storeSlug);
        // Check if the user with the provided email exists in the customers table
        $customer = Customer::where('email', $request->email)->where('store_id', $store->id)->first();

        if (!$customer) {
            // User not found, handle the error accordingly (e.g., show an error message)
            return back()->withInput($request->only('email'))
                ->withErrors(['email' => __('passwords.user')]);
        }

        // Here we will attempt to reset the user's password. If it is successful we
        // will update the password on an actual user model and persist it to the
        // database. Otherwise we will parse the error and return the response.
        // Scoped for the same reason as the mint side above -- without store_id the broker resolves
        // the address across every tenant and resets the lowest-id customer holding it, which may
        // not be the account this storefront's link was issued for.
        $status = Password::broker('customers')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token')
                + ['store_id' => $store->id],
            function ($user) use ($request) {
                $user->forceFill([
                    'password' => Hash::make($request->password),
                    'remember_token' => Str::random(60),
                ])->save();

                // The API sessions too -- same reason as the OTP path above. A link-based reset
                // that leaves a never-expiring Sanctum token alive has not recovered the account
                // from whoever was using it.
                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        // If the password was successfully reset, we will redirect the user back to
        // the application's home authenticated view. If there is an error we can
        // redirect them back to where they came from with their error message.
        return $status == Password::PASSWORD_RESET
            ? redirect()->route('customer.login', $storeSlug)->with('status', __($status))
            : back()->withInput($request->only('email'))
            ->withErrors(['email' => __($status)]);
    }
}