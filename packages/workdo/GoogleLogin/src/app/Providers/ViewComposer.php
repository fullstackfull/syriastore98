<?php

namespace Workdo\GoogleLogin\app\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Plan;
use App\Models\User;
use App\Models\Store;
use Illuminate\Support\Facades\Cache;

class ViewComposer extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        //
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [];
    }

    public function boot() {
        view()->composer(['front_end.Auth.login', 'front_end.Auth.register'], function ($view) {
            if (module_is_active('GoogleLogin')) {                
                $slug = request()->route('storeSlug') ?? $view->getData()['slug'];
                $store = getStore($slug);
                if ($store) {
                    $setting = getAdminAllSetting($store->created_by,$store->id);
                    if (isset($setting['google_login_setting_enabled']) && $setting['google_login_setting_enabled'] === 'on'){
                        $view->getFactory()->startPush('storeSigInButton', view('google-login::pages.button',  compact('store')));
                    }
                }
            }
        });

        view()->composer(['setting.index'], function ($view) {
            if (module_is_active('GoogleLogin')) {
                $activeTab = session()->get('setting_tab');
                $view->getFactory()->startPush('settingTab', view('google-login::pages.tab', compact('activeTab')));
            }
        });
    }
}

