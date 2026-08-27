<?php

namespace Workdo\Wizzchat\app\Listeners;
use App\Events\SuperAdminMenuEvent;

class SuperAdminMenuListener
{
    /**
     * Handle the event.
     */
    public function handle(SuperAdminMenuEvent $event): void
    {
        $module = 'Wizzchat';
        $menu = $event->menu;
        $menu->add([
            'title' => 'Wizzchat',
            'icon' => 'home',
            'name' => 'wizzchat',
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
