<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Providers\RouteServiceProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use App\Models\User;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use App\Events\VerifyReCaptchaToken;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Cache;

class AuthenticatedSessionController extends Controller
{
    public function __construct(Request $request)
    {
        if (!file_exists(storage_path() . "/installed")) {
            header('location:install');
            die;
        }
    }

    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request)
    {
        $settings = \App\Models\Utility::Setting();

        $validation = [];
        if (isset($settings['RECAPTCHA_MODULE']) && $settings['RECAPTCHA_MODULE'] == 'yes') {
            if ($settings['NOCAPTCHA_VERSON'] == 'v2') {
                $validation['g-recaptcha-response'] = 'required';
            } elseif ($settings['NOCAPTCHA_VERSON'] == 'v3') {
                $result = event(new VerifyReCaptchaToken($request));

                if (!isset($result[0]['status']) || $result[0]['status'] != true) {
                    $key = 'g-recaptcha-response';
                    $request->merge([$key => null]); // Set the key to null

                    $validation['g-recaptcha-response'] = 'required';
                }
            }
        }
        $this->validate($request, $validation);


        $user = User::where('email', $request->email)->first();
        if ($user != null) {
            $companyUser = User::where('id', $user->created_by)->first();
        }

        if ($user != null && $user->is_active == 0 && $user->type != 'super admin') {
            return redirect()->back()->with('status', __('Your Account is de-activate,please contact your Administrator.'));
        }

        if ((($user != null && ($user->is_enable_login == 0) && $user->type != 'super admin') || ((isset($companyUser) && $companyUser != null) && $companyUser->is_enable_login == 0))) {
            return redirect()->back()->with('status', __('Your Account is disable,please contact your Administrator.'));
        }

        $request->authenticate();
        $request->session()->regenerate();
        if (isset($settings['email_verification']) && $settings['email_verification'] == "on") {
            if ($user != null && $user->type == 'admin' && empty($user->email_verified_at)) {
                return redirect()->route('verify-email')->with('status', __('Your email is not verified,please verfiy email then you can access dashboard.'));
            }
        }
        \App\Models\Utility::addNewData();

        if($user->type == 'admin')
        {
            $plan = Plan::find($user->plan_id);
            if($plan)
            {
                if($plan->duration != 'Unlimited')
                {
                    $datetime1 = new \DateTime($user->plan_expire_date);
                    $datetime2 = new \DateTime(date('Y-m-d'));
                    $interval = $datetime2->diff($datetime1);
                    $days     = $interval->format('%r%a');
                    if($days <= 0)
                    {
                        $user->assignPlan(1);
                        return redirect()->intended(RouteServiceProvider::HOME)->with('error', __('Your Plan is expired.'));
                    }
                }

                if($user->trial_expire_date != null)
                {
                    if($user->trial_expire_date < date('Y-m-d'))
                    {
                        $user->assignPlan(1);
                        return redirect()->intended(RouteServiceProvider::HOME)->with('error', __('Your Trial plan Expired.'));
                    }
                }
            } elseif (empty($plan)) {
                $user->assignPlan(1);
                $user->save();
                if (isset($planId) && $planId == 1) {
                    return redirect()->intended(RouteServiceProvider::HOME);
                }                   
            }
        }
        
        if (module_is_active('GoogleAuthentication') && isset($user->google2fa_enable) && !empty($user->google2fa_enable)) {
            return redirect()->route('2fa.dashboard');
        }
        elseif (module_is_active('Duo2FA')) {
            return redirect()->route('duo2fa.form');
        }

        return redirect()->intended(RouteServiceProvider::HOME);
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        // Clear the language cookie
        Cookie::queue(Cookie::forget('LANGUAGE'));
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }

    public function showCustomerLoginForm($lang = '')
    {

        return view('auth.customer_login', compact('lang'));
    }
}
