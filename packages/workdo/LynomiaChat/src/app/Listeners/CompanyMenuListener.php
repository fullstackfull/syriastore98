<?php

namespace Workdo\LynomiaChat\app\Listeners;

use App\Events\CompanyMenuEvent;

/**
 * The merchant navigation entry: "Communication & Marketing Center".
 *
 * App\Classes\Menu::add() renders an item only when its `module` is in ActivatedModule() AND
 * the user holds `permission`, so plan-gating and role-gating of the whole section are free
 * here -- the same two checks the routes enforce server-side. The menu is a convenience, not
 * a control: hiding a link is never what keeps a merchant out, PlanModuleCheck and
 * EnsureStoreOwnsLink are.
 *
 * PHASE 1 ADDS THE PARENT AND ONE CHILD. The approved navigation has sixteen sections. They
 * appear as their phases land; a menu entry that routes nowhere is worse than no entry.
 */
class CompanyMenuListener
{
    public function handle(CompanyMenuEvent $event): void
    {
        $module = 'LynomiaChat';
        $menu = $event->menu;

        $menu->add([
            'title' => __('Communication & Marketing'),
            'icon' => 'message-circle',
            'name' => 'lynomia',
            'parent' => null,
            'order' => 41,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'lynomia.overview',
            'module' => $module,
            'permission' => 'Manage Lynomia Chat',
        ]);

        $menu->add([
            'title' => __('Overview'),
            'icon' => '',
            'name' => 'lynomia-overview',
            'parent' => 'lynomia',
            'order' => 10,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'lynomia.overview',
            'module' => $module,
            'permission' => 'Manage Lynomia Chat',
        ]);
    }
}
