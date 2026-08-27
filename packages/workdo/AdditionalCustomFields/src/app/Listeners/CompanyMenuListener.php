<?php

namespace Workdo\AdditionalCustomFields\app\Listeners;


use App\Events\CompanyMenuEvent;

class CompanyMenuListener
{
    /**
     * Handle the event.
     */
    public function handle(CompanyMenuEvent $event): void
    {
        $module = 'AdditionalCustomFields';
        $menu = $event->menu;
        $menu->add([
            'title' => 'Additional Custom-Fields',
            'icon' => 'message-circle',
            'name' => 'additionalcustomfields',
            'parent' => 'products',
            'order' => 40,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'additionalcustomfields.index',
            'module' => $module,
            'permission' => 'Manage AdditionalCustomFields'
        ]);
    }
}

