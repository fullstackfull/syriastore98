<?php

namespace Workdo\SMS\app\Listeners;

use App\Events\SuperAdminMenuEvent;

class SuperAdminMenuListener
{
    public function handle(SuperAdminMenuEvent $event): void
    {
        $module = 'SMS';
        $menu = $event->menu;
        $menu->add([
            'title' => __('Notification Template'),
            'icon' => 'notification',
            'name' => 'notification',
            'parent' => null,
            'order' => 170,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'notification-template.index',
            'module' => $module,
            'permission' => ''
        ]);
      
    }
}
