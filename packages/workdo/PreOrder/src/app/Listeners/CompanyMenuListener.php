<?php

namespace Workdo\PreOrder\app\Listeners;

use App\Events\CompanyMenuEvent;

class CompanyMenuListener
{
    /**
     * Handle the event.
     */
    public function handle(CompanyMenuEvent $event): void
    {
        $module = 'PreOrder';
        $menu = $event->menu;
        $menu->add([
            'title' => __('Pre Order'),
            'icon' => 'home',
            'name' => 'preorder',
            'parent' => 'orders',
            'order' => 50,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => '',
            'module' => $module,
            'permission' => 'Manage Pre Order'
        ]);
        $menu->add([
            'title' => __('Pre Orders'),
            'icon' => 'user',
            'name' => 'pre-orders',
            'parent' => 'preorder',
            'order' => 1,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'preorders.index',
            'module' => $module,
            'permission' => 'Manage Pre Order'
        ]);
        $menu->add([
            'title' => __('Pre Order Setting'),
            'icon' => 'user',
            'name' => 'pre-order-setting',
            'parent' => 'preorder',
            'order' => 2,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'preorder.setting',
            'module' => $module,
            'permission' => 'Manage Pre Order Setting'
        ]);
    }
}
