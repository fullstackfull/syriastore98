<?php

namespace Workdo\Helpdesk\app\Listeners;
use App\Events\SuperAdminMenuEvent;

class SuperAdminMenuListener
{
    /**
     * Handle the event.
     */
    public function handle(SuperAdminMenuEvent $event): void
    {
        $module = 'Helpdesk';
        $menu = $event->menu;
        $menu->add([
            'title' => __('Helpdesk'),
            'icon' => 'headphones',
            'name' => 'helpdesk',
            'parent' => null,
            'order' => 290,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => '',
            'module' => $module,
            'permission' => 'Manage Helpdesk'
        ]);
        $menu->add([
            'title' => __('Tickets'),
            'icon' => '',
            'name' => 'tickets',
            'parent' => 'helpdesk',
            'order' => 5,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'helpdesk.ticket.index',
            'module' => $module,
            'permission' => 'Manage Helpdesk Ticket'
        ]);
        $menu->add([
            'title' => __('System Setup'),
            'icon' => '',
            'name' => 'systemsetup',
            'parent' => 'helpdesk',
            'order' => 10,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'helpdesk.category',
            'module' => $module,
            'permission' => 'Manage SystemSetup'
        ]);
    }
}
