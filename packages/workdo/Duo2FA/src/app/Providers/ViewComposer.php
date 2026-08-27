<?php

namespace Workdo\Duo2FA\app\Providers;
use App\Models\Store;
use App\Models\Plan;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Cache;

class ViewComposer extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public $settings;


    public function boot(){
        view()->composer(['AppSetting.index'], function ($view) {
            if (module_is_active('Duo2FA')) {
                $store = getStoreById(getCurrentStore());
                $user = auth()->user();
                if ($store && $user) {
                    $plan = Plan::where('id', $user->plan_id)->first();
                    $view->getFactory()->startPush('appSettingTab', view('duo2fa::pages.tab'));
                    $view->getFactory()->startPush('appSettingTabForm', view('duo2fa::pages.tab_form', compact('store')));
                }
            }
        });
    }

}
