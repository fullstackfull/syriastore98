<?php

namespace App\Http\Middleware;

use App\Models\{Plan, User};
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Cookie;
use Session;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Artisan;

class SetLocate
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        if(file_exists(storage_path() . "/installed")) {
            $lang = Session::get('LANGUAGE');
            if (empty($lang)) {
                $lang =  Cookie::get('LANGUAGE') ?? null;
            }            

            if(auth()->check())
            {
                if (auth()->user() && auth()->user()->language && empty($lang)) {
                    $lang = auth()->user()->language;
                } elseif (empty($lang)) {
                    $lang = Cookie::get('CURRENT_LANGUAGE');
                    if (empty($lang)) {
                        $lang = auth()->user()->language;
                    }
                }
            } else {
                if (!Session::has('LANGUAGE') || empty($lang)) {
                    $superadmin = Cache::remember('super_admin_details', 3600, function () {
                        return User::where('type','super admin')->first();
                    });
                    $lang = !empty($superadmin->language) ? $superadmin->language : null;
                }
                
            }

            if (empty($lang)) {
                $lang = 'en';
            }

            if(auth()->user())
            {
                $user = auth()->user();
                if($user->type == 'admin')
                {
                    // if($plan)
                    // {
                        /*
                         * SAAS-002. ONE EXPIRY BOUNDARY FOR THE WHOLE PLATFORM. This site, the two
                         * in `Auth\RegisteredUserController` and the one in
                         * `Auth\AuthenticatedSessionController` each diffed the dates themselves
                         * and branched on `$days <= 0`, which is TRUE on the expiry date -- the
                         * last day of the paid period -- while `SaasRevenueFigures` and the
                         * merchant's own plan screen both read that same row as live. The outer
                         * `!empty()` guard here means `hasLapsed()` is the right predicate at this
                         * site specifically: the empty case never reaches it.
                         *
                         * THE `return redirect()` BELOW STAYS COMMENTED OUT, and that is a
                         * decision rather than an oversight. `SAAS-004` asks for NEW SELLING to be
                         * restricted after a grace period while existing orders, tracking and
                         * support are PRESERVED -- it does not ask for the merchant to be shut out
                         * of their own panel, which is what a redirect from the language middleware
                         * would do on every request. The restriction predicate is
                         * `SubscriptionLifecycle::restrictsNewSelling()`, and the grace period it
                         * needs is an owner decision recorded in `config/saas.php`.
                         */
                        if (isset($user->plan_expire_date) && !empty($user->plan_expire_date)) {
                            if (\App\Support\Saas\SubscriptionLifecycle::hasLapsed($user))
                            {
                                if (!isset($user->plan_id) || empty($user->plan_id)) {
                                    $plan = Plan::where('duration','Unlimited')->first();
                                    if ($plan) {
                                        $user->assignPlan($plan->id);
                                    }
                                }
    
                                // return redirect()->route('plan.index')->with('error', __('Your Plan is expired.'));
                            }
                        }
                        if($user->trial_expire_date != null)
                        {
                            if(\Auth::user()->trial_expire_date < date('Y-m-d'))
                            {
                                $user->assignPlan(1);
                                // return redirect()->route('plan.index')->with('error', __('Your Trial plan Expired.'));
                                // return redirect()->intended(RouteServiceProvider::HOME)->with('error', __('Your Trial plan Expired.'));
                            }
                        }
                    // }
                }
            }

            App::setLocale($lang);

            // Check the current route name
            $currentRouteName = $request->route()->getName();

            // Forget the session variable if not on the settings page
            if ($currentRouteName !== 'setting.index') {
                session()->forget('setting_tab');
            }
            if (!in_array($currentRouteName, ['app-setting.index', 'mobilescreen.content'])) {
                session()->forget('app_setting_tab');
            }
            if ($currentRouteName !== 'countries.index') {
                session()->forget('country_active_tab');
            }
        }

        return $next($request);
    }
}
