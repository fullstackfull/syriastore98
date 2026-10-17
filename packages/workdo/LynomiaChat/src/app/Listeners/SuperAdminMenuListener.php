<?php

namespace Workdo\LynomiaChat\app\Listeners;

use App\Events\SuperAdminMenuEvent;

/**
 * The Super Admin entry for the dedicated "Lynomia Chat Integration" page.
 *
 * ONE ENTRY, ONE PAGE. The approved rule is that integration settings are centralised rather
 * than scattered: everything administrative for this integration lives behind this single
 * link and becomes a tab inside that page, never a second menu item somewhere else.
 *
 * `module` IS 'Base', NOT 'LynomiaChat', AND THE DIFFERENCE IS WHY THE LINK APPEARS AT ALL.
 * App\Classes\Menu::add() keeps an item only when its `module` is in the signed-in principal's
 * module list. For a merchant that list is their tenant's entitlements, which is the right gate
 * for the company-side menu (see CompanyMenuListener, which keeps 'LynomiaChat' on purpose so a
 * plan without the module hides the section). For a SUPER ADMIN the list is
 * ActivatedModule() -> Module::getByStatus(1): the add-ons registered and enabled in
 * `add_on_managers`. Lynomia Chat is a bundled platform integration, not a purchasable add-on,
 * so it has no enabled row there and 'LynomiaChat' was filtered out -- the Super Admin never saw
 * this page. 'Base' is the core module Menu always appends to every principal's list, so the
 * entry is shown to the Super Admin unconditionally, which is the intent of a centralised
 * integration-admin page.
 *
 * THIS DOES NOT WIDEN ANYTHING. SuperAdminMenuEvent is dispatched only for a super admin
 * (App\Helper\getMenu / getSideMenu branch on $user->type == 'super admin'); a merchant's menu
 * is built from CompanyMenuEvent, which this listener does not handle. So 'Base' here cannot
 * leak the Super Admin page into a merchant's navigation, and it leaves every merchant
 * subscription and permission gate on the company side untouched.
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
            'module' => 'Base',
            'permission' => '',
        ]);
    }
}
