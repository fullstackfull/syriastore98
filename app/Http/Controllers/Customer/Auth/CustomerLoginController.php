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

class CustomerLoginController extends Controller
{

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

            // This will generate a token and send a reset link to the customer's email
            $response = $broker->sendResetLink(
                ['email' => $request->email],
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
        $status = Password::broker('customers')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) use ($request) {
                $user->forceFill([
                    'password' => Hash::make($request->password),
                    'remember_token' => Str::random(60),
                ])->save();

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
