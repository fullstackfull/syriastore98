<?php

namespace Workdo\Signinwithgithub\app\Providers;

use Illuminate\Support\ServiceProvider;

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
        view()->composer(['front_end.Auth.login', 'front_end.Auth.register'], function ($view)
        {
            if (module_is_active('Signinwithgithub'))
            {
                $slug = request()->route('storeSlug') ?? $view->getData()['slug'];
                $store = getStore($slug);
                if ($store) {
                    $setting = getAdminAllSetting($store->created_by,$store->id);
                    if (isset($setting['github_signin_setting_enabled']) && $setting['github_signin_setting_enabled'] === 'on'){
                        $view->getFactory()->startPush('storeSigInButton', view('signinwithgithub::pages.button',  compact('store','setting')));
                    }
                }
            }
        });
        view()->composer(['setting.index'], function ($view) {
            if (module_is_active('Signinwithgithub')) {
                $activeTab = session()->get('setting_tab');
                $view->getFactory()->startPush('settingTab', view('signinwithgithub::pages.tab', compact('activeTab')));
            }
        });
    }
}

