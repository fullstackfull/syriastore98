<?php

namespace Workdo\RewardClubPoint\app\Providers;

use App\Facades\ModuleFacade as Module;
use Illuminate\Support\ServiceProvider;
use Workdo\RewardClubPoint\app\Models\RewardClubPoint;


class ViewComposer extends ServiceProvider
{
    public function boot(){

        view()->composer(['AppSetting.index','customer.index','front_end.pages.checkout-cart-list','front_end.pages.checkout','front_end.pages.order-detail','order.view'], function ($view) {
            if (module_is_active('RewardClubPoint')) {
                $setting = $view->getData()['setting'] ?? null;
                if (isset($setting) && !empty($setting)) {
                    $app_setting_tab = session()->get('app_setting_tab');
                    if (empty($app_setting_tab)) {
                        $app_setting_tab = 'pills-home-tab';
                    }
                    $view->getFactory()->startPush('appSettingTab', view('reward-club-point::admin.clubPointSettingTab', compact('setting','app_setting_tab')));
                    $view->getFactory()->startPush('appSettingTabForm', view('reward-club-point::admin.clubPointSettingDetails', compact('setting','app_setting_tab')));
                }
                $customer = auth('customers')->user() ?? null;
                if (isset($customer)) {
                    if (isset($view->getData()['store']) && isset($view->getData()['response'])) {
                        $response   = $view->getData()['response'] ?? null;
                        $store      = $view->getData()['store'] ?? null;
                        $setting    = getAdminAllSetting($store['created_by'],$store['id']) ?? null;
                        $view->getFactory()->startPush('clubPointApply', view('reward-club-point::theme.clubPointApply', compact('customer','setting','store','response')));
                        $view->getFactory()->startPush('clubPointPriceShow', view('reward-club-point::theme.clubPointPriceShow', compact('customer','setting','response')));
                    }
                    $view->getFactory()->startPush('clubPointFormField', view('reward-club-point::theme.clubPointFormField'));
                }
                if (isset($view->getData()['order'])) {
                    $order = $view->getData()['order'] ?? null;
                    $rewardHistoryDetail = RewardClubPoint::where('order_id',$order['id'])->first();
                    $view->getFactory()->startPush('savePriceShowOrderPage', view('reward-club-point::theme.savePriceShowOrderPage', compact('rewardHistoryDetail')));

                    // Admin Order Page
                    $view->getFactory()->startPush('savePriceShowAdminOrder', view('reward-club-point::admin.savePriceShowAdminOrder', compact('rewardHistoryDetail')));
                }
            }
        });

    }
}
