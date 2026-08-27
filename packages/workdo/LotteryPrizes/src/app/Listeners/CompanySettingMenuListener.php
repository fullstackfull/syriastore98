<?php

namespace Workdo\LotteryPrizes\app\Listeners;

use App\Events\CompanySettingMenuEvent;

class CompanySettingMenuListener
{
    /**
     * Handle the event.
     */
    public function handle(CompanySettingMenuEvent $event): void
    {
        $module = 'LotteryPrizes';
        $menu = $event->menu;
        $menu->add([
            'title' => 'LotteryPrizes',
            'name' => 'lotteryprizes',
            'order' => 100,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'home',
            'navigation' => 'sidenav',
            'module' => $module,
            'permission' => 'manage-dashboard'
        ]);
    }
}
