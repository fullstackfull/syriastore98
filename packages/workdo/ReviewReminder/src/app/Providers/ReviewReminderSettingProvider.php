<?php

namespace workdo\ReviewReminder\app\Providers;

use App\Models\EmailTemplate;
use App\Models\EmailTemplateLang;
use App\Models\Utility;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;
use Workdo\ReviewReminder\app\Http\Controllers\ReviewReminderController;

class ReviewReminderSettingProvider extends ServiceProvider
{
    public function boot()
    {
        view()->composer(['AppSetting.index'], function ($view) {
   
            if (module_is_active('ReviewReminder')) {
                $setting = $view->getData()['setting'] ?? null;
                ReviewReminderController::EmailTemplateDefaultData();
                ReviewReminderController::userTemplateDefaultData(Auth::user()->id);

                $language = 'en';
                $languages = Utility::languages();
                $emailTemplate = EmailTemplate::where('name', 'Review Reminder')->where('created_by', Auth::user()->id)->first();
                if ($emailTemplate) {
                    $currEmailTempLang = EmailTemplateLang::where('parent_id', '=', $emailTemplate->id)->where('is_default', '1')->first();
                    if (!isset($currEmailTempLang) || empty($currEmailTempLang)) {
                        $currEmailTempLang = EmailTemplateLang::where('parent_id', '=', $emailTemplate->id)->where('language', 'en')->first();
                        $currEmailTempLang->language = $language;
                    }
                    $app_setting_tab = session()->get('app_setting_tab');
                    if (empty($app_setting_tab)) {
                        $app_setting_tab = 'pills-home-tab';
                    }
                    $view->getFactory()->startPush('appSettingTab', view('review-reminder::admin.reviewReminderSettingTab', compact('setting', 'app_setting_tab')));
                    $view->getFactory()->startPush('appSettingTabForm', view('review-reminder::admin.reviewReminderSettingDetails', compact('setting', 'emailTemplate', 'languages', 'currEmailTempLang', 'language', 'app_setting_tab')));
                }
            }
        });
    }

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
}
