<?php

namespace workdo\CouponList\app\Providers;

use Illuminate\Support\ServiceProvider;

class ViewComposer extends ServiceProvider
{
    public function boot()
    {
        // Admin AppSetting Enable Button
        view()->composer(['AppSetting.index'], function ($view) {
            if (module_is_active('CouponList')) {
                $setting = $view->getData()['setting'] ?? '';
                $view->getFactory()->startPush(
                    'appSettingEnableBtn',
                    view('coupon-list::buttons.couponlistenable', compact('setting'))
                );
            }
        });

        // Frontend Coupon List Button
        view()->composer(['front_end.layouts.app'], function ($view) {
            if (module_is_active('CouponList')) {
                $slug = $view->getData()['slug'] ?? null;

                if ($slug) {
                    $store = getStore($slug);

                    if ($store) {
                        $setting = getAdminAllSetting($store->created_by, $store->id);

                        if (!empty($setting['coupon_list_enable']) && $setting['coupon_list_enable'] === 'on') {
                            $view->getFactory()->startPush(
                                'couponListButton',
                                view('coupon-list::buttons.couponlistbutton', compact('setting', 'slug'))
                            );
                        }
                    }
                }
            }
        });
    }
}
