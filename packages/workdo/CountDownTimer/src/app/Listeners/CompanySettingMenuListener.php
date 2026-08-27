<?php

namespace Workdo\CountDownTimer\app\Listeners;

use App\Events\CompanySettingMenuEvent;

class CompanySettingMenuListener
{
    /**
     * Handle the event.
     */
    public function handle(CompanySettingMenuEvent $event): void
    {
        $module = 'CountDownTimer';
        $menu = $event->menu;
        $menu->add([
            'title' => __('CountDownTimer'),
            'name' => 'countdowntimer',
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
