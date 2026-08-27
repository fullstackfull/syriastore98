<?php

namespace Workdo\ThemeCustomizer\app\Listeners;
use App\Events\SuperAdminMenuEvent;

class SuperAdminMenuListener
{
    /**
     * Handle the event.
     */
    public function handle(SuperAdminMenuEvent $event): void
    {
        $module = 'ThemeCustomizer';
        $menu = $event->menu;
        $menu->add([
            'title' => 'ThemeCustomizer',
            'icon' => 'home',
            'name' => 'themecustomizer',
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
