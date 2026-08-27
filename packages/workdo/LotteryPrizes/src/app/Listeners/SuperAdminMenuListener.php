<?php

namespace Workdo\LotteryPrizes\app\Listeners;
use App\Events\SuperAdminMenuEvent;

class SuperAdminMenuListener
{
    /**
     * Handle the event.
     */
    public function handle(SuperAdminMenuEvent $event): void
    {
        $module = 'LotteryPrizes';
        $menu = $event->menu;
        $menu->add([
            'title' => 'LotteryPrizes',
            'icon' => 'home',
            'name' => 'lotteryprizes',
            'parent' => null,
            'order' => 2,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'home',
            'module' => $module,
            'permission' => 'manage-dashboard'
        ]);
    }
}
