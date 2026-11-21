<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ThemeAnalyticController extends Controller
{
    public function index()
    {
        $user  = auth()->user();

        /*
         * THE PERMISSION WAS ENFORCED ONLY BY THE SIDEBAR MENU, which is the exact sentence
         * `ReportPermission`'s docblock uses to describe the P1-SEC-09 defect it was created to fix.
         *
         * MEASURED 2026-11-21. `CompanyMenuListener:50-60` publishes this screen as "Store
         * Analytics" with `'permission' => 'Manage Store Analytics'`, and `Menu::permits()` honours
         * that — so the entry is hidden from a staff member who does not hold it. But the route
         * (`routes/web.php:471`, `themean-alytic`) carries only
         * `web, Authenticate, XSS, SetLocate, EmailIsVerified`: NO permission middleware. And the
         * controller's only check was `$user->type != 'super admin'`, which is a BRANCH deciding
         * which data path to run, not a refusal. So any verified user reached this store's traffic
         * analytics — page-view counts by URL, device, browser and platform — by typing the URL.
         * Store-scoped (`getCurrentStore()` / `current_store`), so within one tenant: vertical, not
         * cross-tenant, and the same class P1-SEC-09 closed for `ReportController`'s 29 methods.
         *
         * THE RULE IS NOT INVENTED, it is the one this product already enforces for the SAME screen
         * on the API side: `Api\DashboardController` refuses without
         * `['Manage Store Analytics', 'Manage Dashboard']` (:1278, :3966) through the same
         * `MerchantPermissions::allowsAny()` helper, and its comment at :1273 states why. The web
         * screen simply never got it. Matching the API exactly is what keeps the two from
         * disagreeing about who may read one figure.
         *
         * `allowsAny()` RATHER THAN `isAbleTo()`, per CLAUDE.md and the Phase 05 plan: it answers
         * `[WILDCARD]` for the platform operator (`UserPolicy::isOperator`), because Laratrust has
         * no super-admin bypass and `isAbleTo()` alone would refuse the one principal who reads
         * every store. It also scopes roles to THIS store, which `allPermissions()` does not.
         *
         * BEFORE THE WORK IT GATES. P1-SEC-22 (f) found this ordering wrong in
         * `CartController::abandon_carts_messsend` — the lookup and relation load ran before the
         * permission check, so a staff member without it still drove the queries. The refusal is a
         * redirect with an error, never a 401: these are web routes, but the shipped Flutter app
         * force-logs-out on any 401 and the rule is applied uniformly.
         */
        $storeId = getCurrentStore();

        if (! \App\Services\MerchantPermissions::allowsAny(
            $user,
            ['Manage Store Analytics', 'Manage Dashboard'],
            $storeId
        )) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if($user->type != 'super admin')
        {
            
            $theme     = APP_THEME();     
            $chartData = $this->getStoreOrderChart(['duration' => 'month'], $theme, $user, $storeId);
    
            $visitor_url   = \DB::table('shetabit_visits')
                ->selectRaw("count('*') as total, url")
                ->where('store_id', $user->current_store)
                ->where('method', 'GET')
                ->where(function($query) {
                    $query->where('url', 'like', '%/product-list%')
                          ->orWhere('url', 'like', '%/product/%')
                          ->orWhere('url', 'like', '%/cart%')
                          ->orWhere('url', 'like', '%/home%')
                          ->orWhere('url', 'like', '%/blog%')
                          ->orWhere('url', 'like', '%/article/%')
                          ->orWhere('url', 'like', '%/about%')
                          ->orWhere('url', 'like', '%/contact-us%')
                          ->orWhere('url', 'like', '%/faq%')
                          ->orWhere('url', 'like', '%/collections%')
                          ->orWhere('url', 'like', '%/brand/%')
                          ->orWhere('url', 'like', '%/privacy-policy%')
                          ->orWhere('url', 'like', '%/track-order%')
                          ->orWhere('url', 'like', '%/page/%')
                          ->orWhere('url', 'like', '%/wishlist%')
                          ->orWhere('url', 'like', '%/search-product%')
                          ->orWhere('url', 'like', '%/checkout%')
                          ->orWhere('url', 'like', '%/login%')
                          ->orWhere('url', 'like', '%/register%')
                          ->orWhere('url', 'like', '%/my-account%')
                          ->orWhere('url', 'like', '%/order%')
                          ->orWhere('url', 'regexp', '.*/[^/]+/?$'); // Store homepage
                })
                ->whereNotLike('url', '%/admin/%')
                ->whereNotLike('url', '%/api/%')
                ->whereNotLike('url', '%/dashboard%')
                ->whereNotLike('url', '%/dashboard%')
                ->whereNotLike('url', '%/additionalnote%')                
                ->whereNotLike('url', '%/preordersetting/out-of-stock-products%')
                ->whereNotLike('url', '%/product-filter%')
                ->whereNotLike('url', '%/cart-list-sidebar%')
                ->whereNotLike('url', '%/blogs/filter/view%')
                ->whereNotLike('url', '%/order-filter%')
                ->whereNotLike('url', '%/get-shipping-data%')
                ->whereNotLike('url', '%/shipping-method%')
                ->whereNotLike('url', '%/get-tax-data%')
                ->groupBy('url')
                ->orderBy('total', 'DESC')
                ->limit(20)
                ->get();
            $user_device   = \DB::table('shetabit_visits')->selectRaw("count('*') as total, device")->where('store_id', $user->current_store)->groupBy('device')->orderBy('device', 'DESC')->get();
            $user_browser  = \DB::table('shetabit_visits')->selectRaw("count('*') as total, browser")->where('store_id', $user->current_store)->groupBy('browser')->orderBy('browser', 'DESC')->get();
            $user_platform = \DB::table('shetabit_visits')->selectRaw("count('*') as total, platform")->where('store_id', $user->current_store)->groupBy('platform')->orderBy('platform', 'DESC')->get();
    
            $devicearray          = [];
            $devicearray['label'] = [];
            $devicearray['data']  = [];
    
            foreach($user_device as $name => $device)
            {
                if(!empty($device->device))
                {
                    $devicearray['label'][] = $device->device;
                }
                else
                {
                    $devicearray['label'][] = 'Other';
                }
                $devicearray['data'][] = (int)$device->total;
            }
    
            $browserarray          = [];
            $browserarray['label'] = [];
            $browserarray['data']  = [];
    
            foreach($user_browser as $name => $browser)
            {
                $browserarray['label'][] = $browser->browser;
                $browserarray['data'][]  = (int)$browser->total;
            }
            $platformarray          = [];
            $platformarray['label'] = [];
            $platformarray['data']  = [];
    
            foreach($user_platform as $name => $platform)
            {
                $platformarray['label'][] = $platform->platform;
                $platformarray['data'][]  = $platform->total;
            }
            return view('theme_analytic',compact('chartData','visitor_url','devicearray','browserarray','platformarray','theme'));
        }
        else{
            return redirect()->route('dashboard')->with('error', __('Permission Denied'));
        }
    }

    public function getStoreOrderChart($arrParam, $theme, $user, $storeId = null)
    {
        if($user->type != 'super admin')
        {    
            $arrDuration = [];
            if($arrParam['duration'])
            {
                
                if($arrParam['duration'] == 'month')
                {
                    $previous_month = strtotime("-2 week +2 day");
                    for($i = 0; $i < 15; $i++)
                    {
                        $arrDuration[date('Y-m-d', $previous_month)] = date('d-M', $previous_month);
                        $previous_month                              = strtotime(date('Y-m-d', $previous_month) . " +1 day");
                    }
                }
            }
            $arrTask          = [];
            $arrTask['label'] = [];
            $arrTask['data']  = [];
    
            foreach($arrDuration as $date => $label)
            {
                //$data['visitor'] = \DB::table('shetabit_visits')->select(\DB::raw('count(*) as total'))->where('store_id', $storeId)->whereDate('created_at', '=', $date)->first();
                $data['visitor'] = \DB::table('shetabit_visits')->select(\DB::raw('count(*) as total'))->where('store_id', $storeId)->first();
                //$uniq            = \DB::table('shetabit_visits')->select('ip')->distinct()->where('store_id',$storeId)->whereDate('created_at', '=', $date)->get();
                $uniq            = \DB::table('shetabit_visits')->select('ip')->distinct()->where('store_id',$storeId)->get();
    
                $data['unique']           = $uniq->count();
                $arrTask['label'][]       = $label;
                $arrTask['data'][]        = $data['visitor']->total;
                $arrTask['unique_data'][] = $data['unique'];
            }
    
            return $arrTask;
        }
        else{
            return redirect()->route('dashboard')->with('error', __('Permission Denied'));
        }

    }
}
