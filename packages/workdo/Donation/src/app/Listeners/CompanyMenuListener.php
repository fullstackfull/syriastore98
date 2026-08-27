<?php

namespace Workdo\Donation\app\Listeners;

use App\Events\CompanyMenuEvent;
use App\Models\Store;
use Workdo\Donation\app\Models\DonationSetting;

class CompanyMenuListener
{
    /**
     * Handle the event.
     */
    public function handle(CompanyMenuEvent $event): void
    {
        if (module_is_active('Donation')) {
            $user = auth()->user();
            if ($user) {
                $store = Store::find($user->current_store);
                if ($store) {
                    $donations = [];
                    $donations = DonationSetting::where('created_by', $user->id)->where('store_id', $store->id)->pluck('value', 'name')->toArray();
                    if (isset($donations['enable_donation']) && ($donations['enable_donation'] == 'on')) {
                        $module = 'Donation';
                        $menu = $event->menu;
                        $menu->add([
                            'title' => __('Donation'),
                            'icon' => 'coin',
                            'name' => 'manage_donation',
                            'parent' => null,
                            'order' => 145,
                            'ignore_if' => [],
                            'depend_on' => [],
                            'route' => '',
                            'module' => $module,
                            'permission' => 'Manage Donation'
                        ]);

                        $menu->add([
                            'title' => __('Donation Setting'),
                            'icon' => 'home',
                            'name' => 'donation_setting',
                            'parent' => 'manage_donation',
                            'order' => 1,
                            'ignore_if' => [],
                            'depend_on' => [],
                            'route' => 'donation.index',
                            'module' => $module,
                            'permission' => 'Manage Donation'
                        ]);

                        $menu->add([
                            'title' => __('Donation History'),
                            'icon' => 'home',
                            'name' => 'donation_histroy',
                            'parent' => 'manage_donation',
                            'order' => 2,
                            'ignore_if' => [],
                            'depend_on' => [],
                            'route' => 'donationhistory.index',
                            'module' => $module,
                            'permission' => 'Manage Donation'
                        ]);
                    }
                }
            }
        }
    }
}
