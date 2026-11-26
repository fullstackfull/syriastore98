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

        /*
         * `IAM-002` / `IAM-004` — THE OPERATOR'S INTERACTIVE SESSION REACHES THE CENTRAL AUDIT LOG.
         *
         * This file carried ZERO references to `AuditLog` before this change, so the trail
         * recorded what the operator DID and never that they had signed in, from where, or that
         * somebody had tried and failed. `IAM-004`'s load-bearing word is "مركزي" (central), and
         * `IAM-002` is "مصادقة قوية وإدارة الجلسات" (strong authentication and session
         * management).
         *
         * THE FAILURE IS CAUGHT AND RE-THROWN UNCHANGED. `LoginRequest::authenticate()` throws
         * `ValidationException` both for bad credentials and for the rate limiter, and both are
         * failures worth a row. Re-throwing the SAME exception keeps the response, the status, the
         * error bag and the throttle behaviour byte-for-byte what they were: nothing a visitor
         * sees changes, which is the requirement for touching a sign-in path at all.
         *
         * `App\Support\Iam\OperatorSessionAudit` is a NO-OP for every principal but the platform
         * operator, and its docblock gives the measured reason (an `Illuminate\Auth\Events\Login`
         * listener would write one row per Sanctum-authenticated API request, because
         * `CustomAuth:110-117` calls `auth()->login()` on 189 route declarations).
         */
        try {
            $request->authenticate();
        } catch (\Throwable $e) {
            \App\Support\Iam\OperatorSessionAudit::signInFailed($user);

            throw $e;
        }

        $request->session()->regenerate();

        /*
         * AFTER `regenerate()`, so the row belongs to the session the operator actually holds
         * rather than to the pre-authentication one. The principal is read from the `web` guard
         * explicitly and not from `$user` above: `$user` came from a lookup on request input,
         * while the guard holds whoever was actually authenticated, and those are the same row
         * here only because `authenticate()` succeeded. Taking it from the guard means this
         * cannot drift into recording an identity that was not signed in.
         */
        \App\Support\Iam\OperatorSessionAudit::signedIn(Auth::guard('web')->user());
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
                    /*
                     * SAAS-002. THE EXPIRY DATE IS THE LAST DAY OF SERVICE, and the expression that used
                     * to stand here treated it as the first day after. `$datetime2->diff($datetime1)
                     * ->format('%r%a')` is 0 on the expiry date, so `$days <= 0` was true and a merchant
                     * who logged in on a day they had paid for was downgraded to plan 1: expiry nulled,
                     * modules cut to plan 1's, and every store and staff seat past plan 1's limits
                     * switched off. Meanwhile `SaasRevenueFigures` counted that same row as `live` and the
                     * merchant's own plan screen did not say `Expired` -- three surfaces, two answers.
                     *
                     * One predicate now, for all three auth sites and the revenue classifier's own
                     * boundary. An empty `plan_expire_date` on a recurring plan still downgrades, exactly
                     * as `new \DateTime('')` made it: see the predicate's docblock for why that half is
                     * preserved rather than quietly changed. Driven by
                     * `tests/Feature/Saas/LoginExpiryBoundaryTest.php`.
                     */
                    if (\App\Support\Saas\SubscriptionLifecycle::recurringSubscriptionNeedsDowngrade($user))
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
        /*
         * `IAM-004` — RECORDED BEFORE `logout()`, because afterwards there is no principal to
         * name. The recorder is a no-op for everyone but the platform operator; see its docblock.
         */
        \App\Support\Iam\OperatorSessionAudit::signedOut(Auth::guard('web')->user());

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
