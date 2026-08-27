<?php

namespace Workdo\PartialPayments\app\Providers;

use App\Models\Order;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\ServiceProvider;
use Workdo\PartialPayments\app\Models\OrderPartialPayments;
use Illuminate\Support\Facades\Cache;

class CheckoutViewComposer extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */

    public function boot()
    {
        view()->composer(['front_end.sections.pages.checkout-cart-list', 'front_end.sections.order_detail', 'order.view', 'front_end.sections.order_view', 'front_end.sections.pages.checkout-amount'], function ($view) {
            if (module_is_active('PartialPayments')) {
                $store = Store::where('slug', request()->segment(1))->first();
                $slug      = $view->getData()['slug'] ?? null;
                $store = getStore($slug);
                if (empty($store)) {
                    $user = auth('customers')->user();
                    if ($user) {
                        $store = Store::find($user->store_id);
                    } else {
                        $store = $view->getData()['store'] ?? null;
                    }
                }
                $setting = $view->getData()['setting'] ?? null;
                $user = Cache::remember('user_details_'.$store->created_by, 3600, function () use ($store) {
                    return User::find($store->created_by);
                });
                $plan = Plan::find($user->plan_id);
                $response = $view->getData()['response'] ?? null;

                $order = $view->getData()['order'] ?? null;
                $order_data = Order::find($order['id'] ?? 0);

                $partial_payment = OrderPartialPayments::where('order_id', $order_data->product_order_id ?? 0)->where('store_id', $store->id)->get();
                $settings = Setting::where('store_id', $store->id)->pluck('value', 'name')->toArray();
                if (auth('customers')->user() && (isset($settings['enable_partial_payment']) && $settings['enable_partial_payment'] == 'on')) {
                    $view->getFactory()->startPush('ManageDepositAmount', view('partial-payments::theme.deposit-amount', compact('store', 'plan', 'response')));
                    $view->getFactory()->startPush('ManageDepositAmountValue', view('partial-payments::theme.deposit-checkout-amount', compact('store', 'order', 'response')));
                }
                $view->getFactory()->startPush('OrderPartialPaymentView', view('partial-payments::theme.partial-payment-history', compact('store', 'order', 'partial_payment')));
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
