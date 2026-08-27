<?php

namespace Workdo\CountDownTimer\app\Providers;

use App\Models\User;
use Illuminate\Support\ServiceProvider;
use Workdo\CountDownTimer\app\Models\CountDownTimer;
use Carbon\Carbon;
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
        view()->composer(['AppSetting.index'], function ($view) {
            if (module_is_active('CountDownTimer')) {
                $setting    = $view->getData()['setting'] ?? null;
                if (isset($setting) && !empty($setting)) {
                    $countDownEntrys = CountDownTimer::where('store_id', getCurrentStore())->orderBy('id', 'desc')->get();
                    $app_setting_tab = session()->get('app_setting_tab');
                    if (empty($app_setting_tab)) {
                        $app_setting_tab = 'pills-home-tab';
                    }
                    $view->getFactory()->startPush('appSettingTab', view('count-down-timer::admin.countDownSettingTab', compact('setting', 'app_setting_tab')));
                    $view->getFactory()->startPush('appSettingTabForm', view('count-down-timer::admin.countDownSettingDetails', compact('setting', 'countDownEntrys', 'app_setting_tab')));
                }
            }
        });
        view()->composer(['front_end.layouts.app'], function ($view) {
            $store = $view->getData()['store'] ?? request()->route('storeSlug');
            if (module_is_active('CountDownTimer')) {
                if (isset($store) && !empty($store)) {
                    $countDown = CountDownTimer::where('store_id', $store->id)->where('status', 'on')->first();
                    $setting    = $view->getData()['setting'] ?? getAdminAllSetting($store->created_by, $store->id);
                    if (isset($countDown) && !empty($countDown)) {
                        $startDate          = Carbon::parse($countDown->start_date . ' ' . $countDown->start_time);
                        $now                = Carbon::now();
                        $differenceInDays   = $startDate->diffInDays($now);
                        $countdownDays = isset($setting['start_countdown_days']) ? $setting['start_countdown_days'] : 0;
                        if ($startDate->isPast($now) == false && $differenceInDays <= $countdownDays) {
                            $view->getFactory()->startPush('countDownPopup', view('count-down-timer::theme.theme_layout_' . $countDown->theme_layout, compact('countDown')));
                        }
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
