<?php

namespace Workdo\EMailBox\app\Listeners;

use App\Events\CompanyMenuEvent;

class CompanyMenuListener
{
    /**
     * Handle the event.
     */
    public function handle(CompanyMenuEvent $event): void
    {
        $module = 'EMailBox';
        $menu = $event->menu;
        
        $menu->add([
            'title' => __('EMail Box'),
            'icon' => 'mail',
            'name' => 'email-box',
            'parent' => null,
            'order' => 85,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => '',
            'module' => $module,
            'permission' => 'Manage Emailbox'
        ]);

        $menu->add([
            'title' => __('EMail Box'),
            'icon' => 'home',
            'name' => 'emailbox',
            'parent' => 'email-box',
            'order' => 1,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'emailbox.index',
            'module' => $module,
            'permission' => 'Manage Emailbox'
        ]);

        $menu->add([
            'title' => __('Setting'),
            'icon' => '',
            'name' => 'email-box-setting',
            'parent' => 'email-box',
            'order' => 2,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'emailbox.setting',
            'module' => $module,
            'permission' => 'Manage Emailbox Setting'
        ]);
    }
}
