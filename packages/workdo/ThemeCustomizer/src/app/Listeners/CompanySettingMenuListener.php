<?php

namespace Workdo\ThemeCustomizer\app\Listeners;

use App\Events\CompanySettingMenuEvent;

class CompanySettingMenuListener
{
    /**
     * Handle the event.
     */
    public function handle(CompanySettingMenuEvent $event): void
    {
        $module = 'ThemeCustomizer';
        $menu = $event->menu;
        $menu->add([
            'title' => 'ThemeCustomizer',
            'name' => 'themecustomizer',
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
