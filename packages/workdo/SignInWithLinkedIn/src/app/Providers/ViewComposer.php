<?php

namespace Workdo\SignInWithLinkedIn\app\Providers;

use Illuminate\Support\ServiceProvider;

class ViewComposer extends ServiceProvider
{

    public $settings;

    public function boot()
    {
        view()->composer(['setting.index'], function ($view) {
            if (module_is_active('SignInWithLinkedIn')) {
                $activeTab = session()->get('setting_tab');
                $view->getFactory()->startPush('settingTab', view('sign-in-with-linked-in::pages.tab', compact('activeTab')));
            }
        });

        // for login through linkedin -- theme side
        view()->composer(['front_end.Auth.login', 'front_end.Auth.register'], function ($view)
        {
            if (module_is_active('SignInWithLinkedIn')) {

                $slug = request()->route('storeSlug') ?? $view->getData()['slug'];
                $store = getStore($slug);

                if ($store) {
                    $setting = getAdminAllSetting($store->created_by, $store->id);
                    if (isset($setting['linkedin_setting_enabled']) && $setting['linkedin_setting_enabled'] === 'on') {
                        $view->getFactory()->startPush('storeSigInButton', view('sign-in-with-linked-in::pages.button', compact('store','setting','slug')));
                    }
                }
            }
        });

    }
}
