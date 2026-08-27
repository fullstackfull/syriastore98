<?php

namespace Workdo\Subscribe\app\Providers;

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Cache;

class ViewComposer extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */

    public function boot()
    {

        view()->composer(['front_end.layouts.app'], function ($view) {
            if (module_is_active('Subscribe')) {
                $slug = $view->getData()['slug'] ?? request()->route('storeSlug');
                $store = getStore($slug);

                $user = Cache::remember('user_details_' . $store->created_by, 3600, function () use ($store) {
                    return User::find($store->created_by);
                });

                $enable_subscribe_popup = \App\Models\Utility::GetValueByName('enable_subscribe_popup', $store->id);
                if (isset($enable_subscribe_popup) && $enable_subscribe_popup == 'on') {
                    $view->getFactory()->startPush('subscribeStorePopup', view('subscribe::pages.model_popup', compact('store', 'slug')));
                }
            }
        });

        view()->composer(['AppSetting.index'], function ($view) {
            if (module_is_active('Subscribe')) {
                $store = getStoreById(getCurrentStore());
                if (!$store && \Auth::user()) {
                    $store = Store::find(\Auth::user()->current_store);
                }
                $setting = $view->getData()['setting'] ?? null;
                $view->getFactory()->startPush('appSettingEnableBtn', view('subscribe::pages.subscribebutton', compact('setting')));
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
