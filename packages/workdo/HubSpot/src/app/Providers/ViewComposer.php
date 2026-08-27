<?php

namespace Workdo\HubSpot\app\Providers;

use Illuminate\Support\ServiceProvider;

class ViewComposer extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
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

    public function boot() {
        view()->composer(['setting.index'], function ($view) {
            if (module_is_active('HubSpot')) {
                $activeTab = session()->get('setting_tab');
                $view->getFactory()->startPush('settingTab', view('hub-spot::pages.tab', compact('activeTab')));
            }
        });
    }
}
