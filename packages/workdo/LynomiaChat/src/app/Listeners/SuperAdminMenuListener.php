<?php

namespace Workdo\LynomiaChat\app\Listeners;

use App\Events\SuperAdminMenuEvent;

/**
 * The Super Admin entry for the dedicated "Lynomia Chat Integration" page.
 *
 * ONE ENTRY, ONE PAGE. The approved rule is that integration settings are centralised rather
 * than scattered: everything administrative for this integration lives behind this single
 * link and becomes a tab inside that page, never a second menu item somewhere else.
 */
class SuperAdminMenuListener
{
    public function handle(SuperAdminMenuEvent $event): void
    {
        $event->menu->add([
            'title' => __('Lynomia Chat Integration'),
            'icon' => 'message-circle',
            'name' => 'lynomia-integration',
            'parent' => null,
            'order' => 41,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'lynomia.admin.index',
            'module' => 'LynomiaChat',
            'permission' => '',
        ]);
    }
}
