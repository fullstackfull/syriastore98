<?php

namespace Workdo\CountDownTimer\app\Listeners;
use App\Events\SuperAdminMenuEvent;

class SuperAdminMenuListener
{
    /**
     * Handle the event.
     */
    public function handle(SuperAdminMenuEvent $event): void
    {
        $module = 'CountDownTimer';
        $menu = $event->menu;
        $menu->add([
            'title' => __('CountDownTimer'),
            'icon' => 'home',
            'name' => 'countdowntimer',
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
