<?php

namespace Workdo\Donation\app\Providers;

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\ServiceProvider;
use Workdo\Donation\app\Models\DonationSetting;
use DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class ViewComposer extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */

     public function boot(){
        view()->composer(['AppSetting.index'], function ($view) {
            if (module_is_active('Donation')) {
                $setting = $view->getData()['setting'] ?? null;
                $slug      = $view->getData()['slug'] ?? null;
                $store = Store::find(getCurrentStore());
                if(empty($store))
                {
                    $user = Auth::user();
                    if($user)
                    {
                        $store = Store::find($user->current_store);
                    }
                }
                $user = Auth::user();

                $donations = [];
                $Payments = [];
                $donations = DonationSetting::where('created_by',$user->id)->where('store_id',$store->id)->pluck('value', 'name')->toArray();
                $Payments = DB::table('settings')
                        ->where('value', 'on')
                        ->where('name', 'like', 'is_%_enabled')
                        ->where('store_id',$store->id)
                        ->pluck('name','id')
                        ->map(function ($name) {
                            return ucfirst(str_replace(['is_', '_enabled'], '', $name));
                        })
                        ->reject(function ($name) {
                            return in_array(strtolower($name), ['cod', 'bank_transfer']);
                        });
                $enablePayment = $donations ? ($donations['payment_methods'] ?? []) : [];
                if (is_string($enablePayment)) {
                    $enablePayment = str_replace(['[', ']', '"'], '', $enablePayment);
                    $enablePayment = explode(',', $enablePayment);
                }
                if (!is_array($enablePayment)) {
                    $enablePayment = [];
                }
                $app_setting_tab = session()->get('app_setting_tab');
                if (empty($app_setting_tab)) {
                    $app_setting_tab = 'pills-home-tab';
                }
                $enablePaymentdata = [];
                if (isset($Payments) && is_object($Payments) && method_exists($Payments, 'filter')) {
                    $enablePaymentdata = $Payments->filter(function ($value, $key) use ($enablePayment) {
                        return in_array($value, $enablePayment);
                    })->keys()->toArray();
                }
                $view->getFactory()->startPush('appSettingTab', view('donation::admin.donation-button',compact('store','app_setting_tab')));
                $view->getFactory()->startPush('appSettingTabForm', view('donation::admin.donation-details', compact('donations','store','Payments','enablePaymentdata','app_setting_tab')));
            }
        });

        view()->composer(['front_end.partison.header'], function ($view) {
            if (module_is_active('Donation')) {
                $setting = $view->getData()['setting'] ?? null;
                $slug      = $view->getData()['slug'] ?? null;
                $store = getStore($slug);
                if(empty($store))
                {
                    $user = Auth::user();
                    if($user)
                    {
                        $store = Store::where('id',$user->current_store)->first();
                    }
                }
                if(empty($store))
                {
                    $store = $view->getData()['store'] ?? '';
                }
                if($store)
                {
                    $user      = User::find($store->created_by);
                    $donations = [];
                    $donations = DonationSetting::where('created_by',$user->id)->where('store_id',$store->id)->pluck('value', 'name')->toArray();
                    if($donations['enable_donation'] ?? '' == 'on')
                    {
                        $view->getFactory()->startPush('DonationFormButton', view('donation::theme.donation_button',compact('donations','store')));
                    }
                }

            }
        });

    }

    public function register()
    {
        //
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [];
    }
}
