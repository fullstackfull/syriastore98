<?php

namespace Workdo\Campaigns\app\Listeners;
use App\Events\SuperAdminMenuEvent;

class SuperAdminMenuListener
{
    /**
     * Handle the event.
     */
    public function handle(SuperAdminMenuEvent $event): void
    {    
        $module = 'Campaigns';
        $menu = $event->menu;
        $menu->add([
            'category' => 'Operations',
            'title' => __('Campaigns'),
            'icon' => '3d-cube-sphere',
            'name' => 'campaigns',
            'parent' => 'landing-page',
            'order' => 3,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'campaigns.index',
            'module' => $module,
            'permission' => 'Manage Campaigns'
        ]);

        $menu->add([
            'category' => 'Operations',
            'title' => __('System Setup'),
            'icon' => '3d-cube-sphere',
            'name' => 'System Setup',
            'parent' => 'landing-page',
            'order' => 4,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'campaigns.setting',
            'module' => $module,
            'permission' => 'Manage Campaigns Settings'
        ]);
    }
}
