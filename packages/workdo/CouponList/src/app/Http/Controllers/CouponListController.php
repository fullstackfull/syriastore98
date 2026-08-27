<?php

namespace workdo\CouponList\app\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\User;
use App\Models\Plan;
use App\Models\Category;
use App\Models\Store;
use Qirolab\Theme\Theme;
use App\Models\Utility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class CouponListController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function couponList($slug)
    {
        $store = getStore($slug);
        if (!$store) {
            abort(403);
        }
        $couponListEnable = Utility::GetValueByName('coupon_list_enable',$store->id);
        $user      = User::find($store->created_by);
        if ($user) {
            $plan = Plan::find($user->plan_id);
            if (isset($plan) && strpos($plan->modules, 'CouponList') === false) {
                abort(403);
            }
        } else {
            abort(403);
        }
        
        if (isset($couponListEnable) && $couponListEnable != 'off') {
            $slug = $store->slug;
            $currency_icon = Utility::GetValueByName('CURRENCY', $store->id);
            $coupons = Coupon::where('store_id',$store->id)->get();
            $themeSettings = getThemeSetting($store->id, $store->theme_id);

            return view('coupon-list::couponlistpage', compact('coupons', 'store' ,'currency_icon','slug','themeSettings'));
        } else {
            return redirect()->route('landing_page', $slug);
        }
    }
    public function index()
    {
        return view('coupon-list::index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('coupon-list::create');
    }
}
