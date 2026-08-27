<?php

namespace Workdo\Api\app\Listeners;

use App\Events\CompanyMenuEvent;

class CompanyMenuListener
{
    /**
     * Handle the event.
     */
    public function handle(CompanyMenuEvent $event): void
    {
        $module = 'Api';
        $menu = $event->menu;
        $menu->add([
            'title' => __('Api Docs'),
            'icon' => 'vector-triangle',
            'name' => 'api_docs',
            'parent' => null,
            'order' => 335,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => '',
            'module' => $module,
            'permission' => 'Manage Api'
        ]);

        $menu->add([
            'title' => __('Api Setting'),
            'icon' => 'home',
            'name' => 'api_setting',
            'parent' => 'api_docs',
            'order' => 1,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'api.setting',
            'module' => $module,
            'permission' => 'Manage Api'
        ]);

        $menu->add([
            'title' => __('Api Docs'),
            'icon' => 'home',
            'name' => 'api_docss',
            'parent' => 'api_docs',
            'order' => 2,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'api.docs',
            'module' => $module,
            'permission' => 'Manage Api'
        ]);
    }
}
