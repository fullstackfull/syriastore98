<?php

namespace Workdo\PDFVouchers\app\Providers;
use App\Models\Store;
use Illuminate\Support\ServiceProvider;
use Workdo\PDFVouchers\app\Models\MailSetting;
use Illuminate\Support\Facades\Cache;

class viewcomposer extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public $settings;


    public function boot()
    {
        view()->composer(['AppSetting.index'], function ($view) {
            if (module_is_active('PDFVouchers')) {
                $store = getStoreById(getCurrentStore());
                $slug = $store->slug;
                if ($store) {
                    $app_setting_tab = session()->get('app_setting_tab');
                    $setting = MailSetting::where('store_id', $store->id)->first();
                    $view->getFactory()->startPush('appSettingTab', view('pdfvouchers::mail_setting.tab',compact('app_setting_tab')));
                    $view->getFactory()->startPush('appSettingTabForm', view('pdfvouchers::mail_setting.create', compact('setting','app_setting_tab')));
                }
            }

        });

        view()->composer(['front_end.hooks.footer_link'], function ($view) {
            if (module_is_active('PDFVouchers')) {
                $store = Store::where('slug', request()->segment(1))->first();
                if (!$store) {
                    $slug = $view->getData()['slug'] ?? request()->route('storeSlug');
                    $store = getStore($slug);
                } else {
                    $slug = request()->segment(1);
                    $store = Store::where('slug', request()->segment(1))->first();
                }

                if ($store) {
                    $slug = $store->slug ?? ($view->getData()['slug'] ?? null);
                    $view->getFactory()->startPush('couponRequestbutton', view('pdfvouchers::coupon.tab', compact('store', 'slug')));
                }
            }
        });
    }

}
