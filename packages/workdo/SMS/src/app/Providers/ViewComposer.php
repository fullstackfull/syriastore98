<?php

namespace Workdo\SMS\app\Providers;

use Illuminate\Support\ServiceProvider;

class ViewComposer extends ServiceProvider
{
    public $settings;

    public function boot()
    {
        view()->composer(['setting.index'], function ($view) {
            if (module_is_active('SMS')) {
                $activeTab = session()->get('setting_tab');
                $view->getFactory()->startPush('settingTab', view('sms::pages.tab', compact('activeTab')));
            }
        });
    }
}
