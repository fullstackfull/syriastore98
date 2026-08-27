<?php

namespace Workdo\PartialPayments\app\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\EmailTemplate;
use App\Models\EmailTemplateLang;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Utility;
use Illuminate\Support\Facades\Auth;
use DB;
use Workdo\PartialPayments\app\Http\Controllers\PartialPaymentsController;
use Workdo\PartialPayments\app\Models\PartialPayments;
class ViewComposer extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */

    public function boot()
    {
        view()->composer(['AppSetting.index', 'product.create', 'product.edit', 'order.view'], function ($view) {
            if (module_is_active('PartialPayments')) {
                $setting = $view->getData()['setting'] ?? null;
                $slug      = $view->getData()['slug'] ?? null;
                $store      = getStore($slug);
                if (empty($store)) {
                    $user = Auth::user();
                    if ($user) {
                        $store = Store::find($user->current_store);
                    }
                }
                $user = Auth::user();

                $app_setting_tab = session()->get('app_setting_tab');
                if (empty($app_setting_tab)) {
                    $app_setting_tab = 'pills-home-tab';
                }
                $settings = [];
                $settings = Setting::where('store_id', $store->id)->pluck('value', 'name')->toArray();
                $option = Utility::GetValueByName('set_order_status', $store->id);
                $options = !empty(json_decode($option)) ? json_decode($option) : [];
                $Payments = DB::table('settings')
                    ->where('value', 'on')
                    ->where('name', 'like', 'is_%_enabled')
                    ->where('store_id', $store->id)
                    ->distinct()
                    ->pluck('name', 'id');

                $Payments = $Payments->map(function ($name) {
                    return ucfirst(str_replace(['is_', '_enabled'], '', $name));
                })->unique();

                $enablePayment = $settings['partial_payment_methods'] ?? [];
                if (is_string($enablePayment)) {
                    $enablePayment = str_replace(['[', ']', '"'], '', $enablePayment);
                    $enablePayment = explode(',', $enablePayment);
                    $enablePayment = array_map('trim', $enablePayment);
                }

                $enablePaymentdata = $Payments->filter(function ($value, $key) use ($enablePayment) {
                    return in_array($value, $enablePayment);
                })->keys()->toArray();

                $deposit_enablePayment = $settings['deposite_payment_methods'] ?? [];
                if (is_string($deposit_enablePayment)) {
                    $deposit_enablePayment = str_replace(['[', ']', '"'], '', $deposit_enablePayment);
                    $deposit_enablePayment = explode(',', $deposit_enablePayment);
                    $deposit_enablePayment = array_map('trim', $deposit_enablePayment);
                }


                $deposit_enablePaymentdata = $Payments->filter(function ($value, $key) use ($deposit_enablePayment) {
                    return in_array($value, $deposit_enablePayment);
                })->keys()->toArray();
                if (Auth::user()) {
                    PartialPaymentsController::EmailTemplateDefaultData();
                    PartialPaymentsController::userTemplateDefaultData(Auth::user()->id);
                } elseif (isset($user)) {
                    PartialPaymentsController::EmailTemplateDefaultData();
                    PartialPaymentsController::userTemplateDefaultData($user->id);
                }
                $user_id = Auth::user()->id ?? $user->id;
                $language = 'en';
                $languages = Utility::languages();
                $emailTemplate = EmailTemplate::where('name', 'Payment Reminder')->where('created_by', $user_id)->first();

                if ($emailTemplate) {
                    $currEmailTempLang = EmailTemplateLang::where('parent_id', $emailTemplate->id)->where('is_default', '1')->first();
                    if (!isset($currEmailTempLang) || empty($currEmailTempLang)) {
                        $currEmailTempLang = EmailTemplateLang::where('parent_id', $emailTemplate->id)->where('language', 'en')->first();
                        $currEmailTempLang->language = $language;
                    }
                }
                $view->getFactory()->startPush('appSettingTab', view('partial-payments::admin.partialpayments-tab', compact('store', 'app_setting_tab')));
                $view->getFactory()->startPush('appSettingTabForm', view('partial-payments::admin.partialpayments-form', compact('store', 'settings', 'app_setting_tab', 'Payments', 'enablePaymentdata', 'emailTemplate', 'languages', 'currEmailTempLang', 'language', 'options', 'deposit_enablePaymentdata')));

                $product = $view->getData()['product'] ?? null;

                if ($product) {
                    $partial_payment = PartialPayments::where('product_id', $product->id)->where('store_id', $store->id)->first();
                } else {
                    $partial_payment = [];
                }
                $view->getFactory()->startPush('ProductPageSetting', view('partial-payments::admin.add-payment-setting', compact('store')));
                $view->getFactory()->startPush('EditProductPageSetting', view('partial-payments::admin.edit-payment-setting', compact('store', 'partial_payment')));
                $view->getFactory()->startPush('AddOrderStatus', view('partial-payments::admin.order-status', compact('store')));
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
