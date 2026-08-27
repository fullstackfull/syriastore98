<?php

namespace Workdo\SpinToWinReward\app\Providers;

use App\Models\{Utility, Store, EmailTemplate, EmailTemplateLang};
use Illuminate\Support\ServiceProvider;
use Workdo\SpinToWinReward\app\Http\Controllers\SpinToWinRewardController;
use Illuminate\Support\Facades\Auth;
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
            if (module_is_active('SpinToWinReward')) {
                $slug      = request()->route('storeSlug') ?? null;
                $store = getStore($slug);
                if ($store) {
                    $enable_spin_to_win = Utility::GetValueByName('enable_spin_to_win', $store->id);
                    if (isset($enable_spin_to_win) && $enable_spin_to_win == 'on') {
                        $view->getFactory()->startPush('SpinwheelButton', view('spin-to-win-reward::theme.spin_button', compact('store')));
                    }
                }
            }
        });

        view()->composer(['AppSetting.index'], function ($view) {
            if (module_is_active('SpinToWinReward')) {
                $store = getStoreById(getCurrentStore());
                if (empty($store)) {
                    $store = $view->getData()['store'];
                }
                $setting = getAdminAllSetting($store->created_by, $store->id) ?? null;
                if (Auth::user()) {
                    SpinToWinRewardController::EmailTemplateDefaultData();
                    SpinToWinRewardController::userTemplateDefaultData(Auth::user()->id);
                }

                $view->getFactory()->startPush('appSettingEnableBtn', view('spin-to-win-reward::admin.spintowinbutton', compact('setting')));

                $user_id = Auth::user()->id ?? $store->id;
                $language = 'en';
                $languages = Utility::languages();
                $emailTemplate = EmailTemplate::where('name', 'Lucky Winner')->where('created_by', $user_id)->first();

                if ($store && $emailTemplate) {
                    $currEmailTempLang = EmailTemplateLang::where('parent_id', $emailTemplate->id)->where('is_default', '1')->first();
                    if (!isset($currEmailTempLang) || empty($currEmailTempLang)) {
                        $currEmailTempLang = EmailTemplateLang::where('parent_id', $emailTemplate->id)->where('language', 'en')->first();
                        $currEmailTempLang->language = $language;
                    }

                    $app_setting_tab = session()->get('app_setting_tab');
                    if (empty($app_setting_tab)) {
                        $app_setting_tab = 'pills-home-tab';
                    }
                    $enable_spin_to_win = Utility::GetValueByName('enable_spin_to_win', $store->id);
                    if (isset($enable_spin_to_win) && $enable_spin_to_win == 'on') {
                        $view->getFactory()->startPush('appSettingTab', view('spin-to-win-reward::admin.email_tab', compact('store', 'app_setting_tab')));
                        $view->getFactory()->startPush('appSettingTab', view('spin-to-win-reward::pages.tab', compact('store', 'app_setting_tab')));
                        $view->getFactory()->startPush('appSettingTabForm', view('spin-to-win-reward::admin.customerEmailSettingDetails', compact('setting', 'emailTemplate', 'languages', 'currEmailTempLang', 'language', 'app_setting_tab')));
                        $view->getFactory()->startPush('appSettingTabForm', view('spin-to-win-reward::pages.tab_form', compact('setting', 'emailTemplate', 'languages', 'currEmailTempLang', 'language', 'app_setting_tab')));
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
