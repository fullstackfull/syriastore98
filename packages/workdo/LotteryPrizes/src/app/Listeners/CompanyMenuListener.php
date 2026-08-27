<?php

namespace Workdo\LotteryPrizes\app\Listeners;

use App\Events\CompanyMenuEvent;

class CompanyMenuListener
{
    /**
     * Handle the event.
     */
    public function handle(CompanyMenuEvent $event): void
    {
        if (module_is_active('LotteryPrizes')) {
            $module = 'LotteryPrizes';
            $menu = $event->menu;
            $menu->add([
                'title' => __('Lottery Prizes'),
                'icon' => 'ti ti-id',
                'name' => 'manage_lottery',
                'parent' => null,
                'order' => 160,
                'ignore_if' => [],
                'depend_on' => [],
                'route' => '',
                'module' => $module,
                'permission' => ''
            ]);

            $menu->add([
                'title' => __('Lottery Setting'),
                'icon' => 'home',
                'name' => 'lottery_setting',
                'parent' => 'manage_lottery',
                'order' => 1,
                'ignore_if' => [],
                'depend_on' => [],
                'route' => 'lotterysetting.index',
                'module' => $module,
                'permission' => ''
            ]);
            $menu->add([
                'title' => __('Lottery Mail Setting'),
                'icon' => 'home',
                'name' => 'lottery_mail_setting',
                'parent' => 'manage_lottery',
                'order' => 2,
                'ignore_if' => [],
                'depend_on' => [],
                'route' => 'lottery_email_template',
                'module' => $module,
                'permission' => ''
            ]);

            $menu->add([
                'title' => __('Lottery History'),
                'icon' => 'home',
                'name' => 'lottery_history',
                'parent' => 'manage_lottery',
                'order' => 3,
                'ignore_if' => [],
                'depend_on' => [],
                'route' => 'lotteryresult.index',
                'module' => $module,
                'permission' => ''
            ]);

            $menu->add([
                'title' => __('Lottery Order'),
                'icon' => 'home',
                'name' => 'lottery_order',
                'parent' => 'manage_lottery',
                'order' => 4,
                'ignore_if' => [],
                'depend_on' => [],
                'route' => 'lotteryorder.index',
                'module' => $module,
                'permission' => ''
            ]);
        }
    }
}
