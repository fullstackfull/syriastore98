<?php

namespace App\Listeners;

use App\Events\SuperAdminMenuEvent;
use PHPUnit\TextUI\Configuration\Php;

class SuperAdminMenuListener
{

    /**
     * Handle the event.
     */
    public function handle(SuperAdminMenuEvent $event): void
    {
        $module = 'Base';
        $menu = $event->menu;
        $menu->add([
            'title' => __('Dashboard'),
            'icon' => 'home',
            'name' => 'dashboard',
            'parent' => null,
            'order' => 1,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'dashboard',
            'module' => $module,
            'permission' => 'Manage Dashboard'
        ]);
        $menu->add([
            'title' => __('Add-on Manager'),
            'icon' => 'layout-2',
            'name' => 'add-on-manager',
            'parent' => null,
            'order' => 60,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'module.index',
            'module' => $module,
            'permission' => ''
        ]);
        /*
         * ADM-001 / GAP-231 — THE PLATFORM-WIDE INSTRUMENTS, IN THE OPERATOR'S OWN NAVIGATION.
         * =========================================================================================
         *
         * The recovered decision is `لوحة قيادة شاملة للمنصة` — a COMPREHENSIVE platform command
         * dashboard. Whether the dashboard that exists is the one the plan describes is a product
         * judgement and is `GAP-231`'s question for the owner. What is not a judgement is this:
         * *comprehensive* cannot be true of a command surface whose instruments are not on it, and
         * MEASURED, none of them was on it: this listener declared TEN entries -- dashboard,
         * add-on-manager, users, coupons, plan, plan request, email templates, settings, country,
         * currency -- and not one was a `platform/*` screen. The FIVE that already existed
         * (analytics, revenue, resources, documentation, site-content) were reachable only from a
         * link panel inside the dashboard body, which `OperatorDashboardPlatformLinksTest` added
         * precisely because this file "does not belong to that workstream in this phase" and
         * recorded a sidebar entry as a CROSS-AGENT DEPENDENCY. ADM-001 is the requirement it was
         * deferred against, so it is paid here. The sixth, Store 360, is ADM-002 and arrives in the
         * same change -- it is listed below as a child like the others, not counted among the five
         * that were unreachable.
         *
         * A PARENT WITH `'route' => ''` AND SIX CHILDREN, which is `CompanyMenuListener`'s own
         * shape for a group (its `admin_dashboard` entry does exactly this -- cited by name
         * rather than by line, which CLAUDE.md records going stale four times in one task).
         * `menuTree()` turns
         * an entry with children into a `dash-submenu`, and an empty `route` renders `href="#!"`
         * so the parent toggles rather than navigating. `MerchantPageDirectory` skips an entry with
         * no route, so the group header does not become a favouritable "page" either.
         *
         * `'permission' => ''` ON EVERY ONE OF THESE, AND IT IS THE CORRECT GATE RATHER THAN A
         * SHORTCUT. `Menu::permits()` admits an entry when its module is active AND the permission
         * is empty OR `isAbleTo()` answers true. These seven screens are gated by IDENTITY —
         * `PlatformOperatorOnly`, a string comparison on `users.type` — and NOT by a permission,
         * for two reasons that both matter here:
         *
         *   1. There is no super-admin bypass in this application's Laratrust setup
         *      (`permissions_as_gates => false`), so `isAbleTo()` REFUSES the platform operator for
         *      any name they have not been granted. A permission name on these entries would
         *      therefore be capable of HIDING a screen the operator may certainly open — the same
         *      defect this group fixes, in a subtler form.
         *   2. A merchant creates their own roles through the product, so "holds permission X" is
         *      substantially self-assignable — the wrong KIND of gate for a screen that reads every
         *      tenant at once.
         *
         * IT IS SAFE BECAUSE THE EVENT IS THE GATE, not the permission: `getMenu()` fires
         * `SuperAdminMenuEvent` only for `type == 'super admin'` and `MerchantPageDirectory` only
         * for `UserPolicy::isOperator()`. `add-on-manager` below already ships this way, and
         * `OperatorPlatformNavigationTest` asserts the consequence in both directions rather than
         * trusting this argument — that none of these routes can appear in a merchant's menu.
         *
         * THE SET IS NOT MAINTAINED BY HAND. `OperatorPlatformNavigationTest` computes it from the
         * booted route table — every GET route carrying `PlatformOperatorOnly` whose uri is
         * `platform/<one segment>` with no parameter — so the NEXT operator screen of that shape
         * reddens that test until it is added here, instead of shipping unreachable as these six
         * did.
         */
        $menu->add([
            'title' => __('Across the platform'),
            'icon' => 'layout-dashboard',
            'name' => 'platform-command',
            'parent' => null,
            'order' => 20,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => '',
            'module' => $module,
            'permission' => ''
        ]);

        /* Store 360 first: it is the only one of the six that answers "which store", and the
           operator's question usually starts there. ADM-002. */
        $menu->add([
            'title' => __('Store 360'),
            'icon' => 'building-store',
            'name' => 'platform-stores',
            'parent' => 'platform-command',
            'order' => 1,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'platform.stores.index',
            'module' => $module,
            'permission' => ''
        ]);

        /* Revenue and analytics are adjacent ON PURPOSE and in this order: SAAS-007 is the
           separation of platform revenue (what merchants pay Store Syria) from merchant sales
           (what shoppers pay merchants). Naming them one after the other is where an operator
           chooses between them, so the distinction has to be legible at the point of choosing. */
        $menu->add([
            'title' => __('Platform revenue'),
            'icon' => 'receipt',
            'name' => 'platform-revenue',
            'parent' => 'platform-command',
            'order' => 2,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'platform.revenue',
            'module' => $module,
            'permission' => ''
        ]);

        $menu->add([
            'title' => __('Platform analytics'),
            'icon' => 'chart-histogram',
            'name' => 'platform-analytics',
            'parent' => 'platform-command',
            'order' => 3,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'platform.analytics',
            'module' => $module,
            'permission' => ''
        ]);

        $menu->add([
            'title' => __('Platform resources'),
            'icon' => 'server',
            'name' => 'platform-resources',
            'parent' => 'platform-command',
            'order' => 4,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'platform.resources',
            'module' => $module,
            'permission' => ''
        ]);

        $menu->add([
            'title' => __('Official website content'),
            'icon' => 'world',
            'name' => 'platform-site-content',
            'parent' => 'platform-command',
            'order' => 5,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'platform.site_content.index',
            'module' => $module,
            'permission' => ''
        ]);

        $menu->add([
            'title' => __('Documentation Center'),
            'icon' => 'book',
            'name' => 'platform-documentation',
            'parent' => 'platform-command',
            'order' => 6,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'platform.documentation.index',
            'module' => $module,
            'permission' => ''
        ]);

        /*
         * PHASE 06 `CTRL-001`/`002`/`003`, AND THIS IS A CROSS-AGENT DEPENDENCY BEING DISCHARGED
         * RATHER THAN A DECISION BEING REVISITED.
         *
         * `ws/p6-ctrl` linked both screens from the operator's dashboard and deliberately added
         * nothing here, recording in `superadmin/dashboard.blade.php` that "THE OPERATOR'S SIDEBAR
         * IS SOMEBODY ELSE'S FILE ... a sidebar entry is recorded as a cross-agent dependency".
         * That was the right call on its own base. It is not sufficient any more:
         * `OperatorPlatformNavigationTest::test_every_platform_wide_operator_screen_is_in_the_operators_navigation`
         * landed since, and it asserts that EVERY platform-wide operator route is named here --
         * "a built screen nobody can find is not delivered" (ADM-001). So the deferral is
         * discharged at the merge, which is where the two halves of it finally meet.
         *
         * `ORDER 110 AND 120, NOT 7 AND 8.` This file's `platform-command` orders are 1..6 on this
         * branch and 10..100 on `int/checkpoint`, where `NAV-004` renumbered them while adding the
         * four screens that were missing for the same reason. 110 and 120 are free in BOTH, so
         * these two entries compose with that change as a union instead of colliding with it --
         * which is the whole lesson of the merge this commit belongs to.
         *
         * `toggle-right` AND `tool`: the subject, not a state. Neither glyph may read as "a
         * feature is on" or "the platform is down right now" -- the label carries the meaning,
         * for the reason `NAV-004` gives about `alert-triangle` on the incident log.
         */
        $menu->add([
            'title' => __('Feature control'),
            'icon' => 'toggle-right',
            'name' => 'platform-feature-control',
            'parent' => 'platform-command',
            'order' => 110,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'platform.feature_control.index',
            'module' => $module,
            'permission' => ''
        ]);

        $menu->add([
            'title' => __('Maintenance announcements'),
            'icon' => 'tool',
            'name' => 'platform-maintenance',
            'parent' => 'platform-command',
            'order' => 120,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'platform.maintenance.index',
            'module' => $module,
            'permission' => ''
        ]);

        $menu->add([
            'title' => __('Users'),
            'icon' => 'user',
            'name' => 'users',
            'parent' => null,
            'order' => 80,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'stores.index',
            'module' => $module,
            'permission' => 'Manage User'
        ]);



        $menu->add([
            'title' => __('Coupons'),
            'icon' => 'gift',
            'name' => 'coupon',
            'parent' => null,
            'order' => 100,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'plan-coupon.index',
            'module' => $module,
            'permission' => 'Manage Coupon'
        ]);

        $menu->add([
            'title' => __('Plan'),
            'icon' => 'trophy',
            'name' => 'plan',
            'parent' => null,
            'order' => 120,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'plan.index',
            'module' => $module,
            'permission' => 'Manage Plan'
        ]);

        $menu->add([
            'title' => __('Plan Request'),
            'icon' => 'arrow-up-right-circle',
            'name' => 'planrequest',
            'parent' => null,
            'order' => 140,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'plan-request.index',
            'module' => $module,
            'permission' => 'Manage Plan Request'
        ]);

        $menu->add([
            'title' => __('Email Templates'),
            'icon' => 'mail',
            'name' => 'email-templates',
            'parent' => null,
            'order' => 160,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'email_template',
            'module' => $module,
            'permission' => 'Manage Email Template'
        ]);
        
        $menu->add([
            'title' => __('Settings'),
            'icon' => 'settings',
            'name' => 'settings',
            'parent' => null,
            'order' => 300,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'setting.index',
            'module' => $module,
            'permission' => 'Manage Setting'
        ]);

        $menu->add([
            'title' => __('Country'),
            'icon' => 'current-location',
            'name' => 'country',
            'parent' => null,
            'order' => 200,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'countries.index',
            'module' => $module,
            'permission' => 'Manage Country'
        ]);
        
        $menu->add([
            'title' => __('Currency'),
            'icon' => 'currency-dollar',
            'name' => 'currency',
            'parent' => null,
            'order' => 210,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'currency.index',
            'module' => $module,
            'permission' => 'Manage currency'
        ]);
    }
}
