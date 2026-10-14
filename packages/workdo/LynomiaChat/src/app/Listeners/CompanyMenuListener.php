<?php

namespace Workdo\LynomiaChat\app\Listeners;

use App\Events\CompanyMenuEvent;

/**
 * The merchant navigation entry: "Communication & Marketing Center".
 *
 * App\Classes\Menu::add() renders an item only when its `module` is in ActivatedModule() AND
 * the user holds `permission`, so the section hides itself for a plan that lacks the module
 * and for a staff role that lacks `Manage Lynomia Chat`.
 *
 * THAT IS COSMETIC AND NOTHING HERE IS A CONTROL. An earlier version of this comment claimed
 * these were "the same two checks the routes enforce server-side". The plan half was true;
 * the permission half was not -- the routes carried no role check at all, so a staff member
 * who could not see this menu item reached the same pages by typing the path, and
 * /lynomia/open signed them into the merchant's workspace as its administrator. The routes
 * now carry Laratrust's permission middleware for exactly this permission
 * (packages/workdo/LynomiaChat/src/routes/web.php), which is what keeps them out. Hiding a
 * link never did.
 *
 * ENTRIES APPEAR AS THEIR PHASES LAND. The approved navigation has sixteen sections; a menu
 * entry that routes nowhere is worse than no entry, so Flow Builder, Campaigns and the rest are
 * absent rather than present and inert. Phase 2 adds Inbox, which routes to a working page.
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

        $menu->add([
            'title' => __('Inbox'),
            'icon' => '',
            'name' => 'lynomia-inbox',
            'parent' => 'lynomia',
            'order' => 20,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'lynomia.inbox',
            'module' => $module,
            'permission' => 'Manage Lynomia Chat',
        ]);
    }
}
