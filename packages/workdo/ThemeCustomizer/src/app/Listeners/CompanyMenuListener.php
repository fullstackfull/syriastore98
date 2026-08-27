<?php

namespace Workdo\ThemeCustomizer\app\Listeners;

use App\Events\CompanyMenuEvent;

class CompanyMenuListener
{
    /**
     * Handle the event.
     */
    public function handle(CompanyMenuEvent $event): void
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
