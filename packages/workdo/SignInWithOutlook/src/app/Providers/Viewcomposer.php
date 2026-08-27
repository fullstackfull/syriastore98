<?php

namespace Workdo\SignInWithOutlook\app\Providers;

use Illuminate\Support\ServiceProvider;

class Viewcomposer extends ServiceProvider
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
        // for theme side

        view()->composer(['front_end.Auth.login', 'front_end.Auth.register'], function ($view)
        {
            if (module_is_active('SignInWithOutlook')) 
            {
                $slug = request()->route('storeSlug') ?? $view->getData()['slug'];
                $store = getStore($slug);
                if ($store) {
                    $setting = getAdminAllSetting($store->created_by,$store->id);
                    if (isset($setting['outlook_setting_enabled']) && $setting['outlook_setting_enabled'] === 'on'){
                        $view->getFactory()->startPush('storeSigInButton', view('sign-in-with-outlook::pages.button',  compact('store')));
                    }
                }
            }
        });
        view()->composer(['setting.index'], function ($view) {
            if (module_is_active('SignInWithOutlook')) {
                $activeTab = session()->get('setting_tab');
                $view->getFactory()->startPush('settingTab', view('sign-in-with-outlook::pages.tab', compact('activeTab')));
            }
        });
        
    }
}
