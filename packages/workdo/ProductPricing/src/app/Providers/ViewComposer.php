<?php

namespace Workdo\ProductPricing\app\Providers;

use Illuminate\Support\ServiceProvider;
use Workdo\ProductPricing\app\Models\ProductPricingRule;

class ViewComposer extends ServiceProvider
{
    public function boot()
    {
        // Admin Side
        view()->composer(['AppSetting.index'], function ($view) {
            if (module_is_active('ProductPricing')) {
                if (isset($view->getData()['plan'])) {
                    $setting    = getAdminAllSetting();
                    $app_setting_tab = session()->get('app_setting_tab');
                    if (empty($app_setting_tab)) {
                        $app_setting_tab = 'pills-home-tab';
                    }
                    $allRulesConditions     = ProductPricingRule::$allRulesConditions;
                    $ruleConditions         = ProductPricingRule::$ruleConditions;
                    $productPricingRules    = ProductPricingRule::where('store_id', getCurrentStore())->get();
                    $view->getFactory()->startPush('appSettingTab', view('product-pricing::admin.productPricingSettingTab', compact('setting', 'app_setting_tab')));
                    $view->getFactory()->startPush('appSettingTabForm', view('product-pricing::admin.productPricingSettingDetails', compact('setting', 'allRulesConditions', 'productPricingRules', 'ruleConditions', 'app_setting_tab')));
                }
            }
        });
    }
}
