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
         * A PARENT WITH `'route' => ''` AND A CHILD PER SCREEN, which is `CompanyMenuListener`'s own
         * shape for a group (its `admin_dashboard` entry does exactly this -- cited by name
         * rather than by line, which CLAUDE.md records going stale four times in one task).
         * `menuTree()` turns
         * an entry with children into a `dash-submenu`, and an empty `route` renders `href="#!"`
         * so the parent toggles rather than navigating. `MerchantPageDirectory` skips an entry with
         * no route, so the group header does not become a favouritable "page" either.
         *
         * `'permission' => ''` ON EVERY ONE OF THESE, AND IT IS THE CORRECT GATE RATHER THAN A
         * SHORTCUT. `Menu::permits()` admits an entry when its module is active AND the permission
         * is empty OR `isAbleTo()` answers true. Every screen in this group is gated by IDENTITY —
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
         * reddens that test until it is added here, instead of shipping unreachable as all ten of
         * these did. FOUR MORE WERE ADDED ON 2026-11-27 (NAV-004) and the mechanism is why: the
         * verification queue, the approval queue, the incident log and the operations screen were
         * each built by a different workstream, all four shipped with no navigation entry, and the
         * computed census named all four at once. A COUNT IN THIS PROSE IS NOT MAINTAINED -- the
         * dashboard panel beside it had to correct its own counted noun twice, so it stopped
         * carrying one; the numbers above are historical readings and say the date they were taken.
         */
        $menu->add([
            'title' => __('Across the platform'),
            /*
             * `dashboard`, NOT `layout-dashboard`, AND THE DIFFERENCE IS A VISIBLE HOLE IN THE
             * SIDEBAR. `menu-tree.blade.php` renders `<i class="ti ti-{{ $node['icon'] }}">` for
             * every TOP-LEVEL entry, and the only icon stylesheet `layouts/app.blade.php` loads is
             * `public/assets/fonts/tabler-icons.min.css` -- a pinned build of the webfont that has
             * no `.ti-layout-dashboard` rule at all, so this group header rendered with NO GLYPH
             * while every sibling top-level item had one. Measured by grepping that stylesheet:
             * of the 33 distinct icon names the two host listeners declare, exactly two were
             * absent from it, and both arrived with this group (`layout-dashboard` here,
             * `chart-histogram` on the analytics child below). An icon name is not checkable by
             * eye -- Tabler's own documentation lists both -- so
             * `OperatorPlatformNavigationIconsTest` now asks the stylesheet, in both directions.
             */
            'icon' => 'dashboard',
            'name' => 'platform-command',
            'parent' => null,
            'order' => 20,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => '',
            'module' => $module,
            'permission' => ''
        ]);

        /* Store 360 first: it is the only entry in this group that answers "which store", and the
           operator's question usually starts there. ADM-002.

           THE CHILDREN ARE NUMBERED IN TENS from here down (NAV-004). They were 1..6 and four more
           had to be placed INSIDE that run rather than after it -- a work queue below the
           documentation centre is a work queue nobody visits -- so renumbering was the alternative
           to a fractional `order`, which `menuTree()`'s comparator (`$a['order'] - $b['order']`,
           returned to `usort`) would cast to int and read as a tie. Nothing pins these values:
           grepped, no test and no view names one. */
        $menu->add([
            'title' => __('Store 360'),
            'icon' => 'building-store',
            'name' => 'platform-stores',
            'parent' => 'platform-command',
            'order' => 10,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'platform.stores.index',
            'module' => $module,
            'permission' => ''
        ]);

        /*
         * NAV-004 -- THE TWO WORK QUEUES, SECOND AND THIRD, because they are the only entries in
         * this group that can be WAITING FOR THE OPERATOR.
         *
         * Everything else here is something to go and read. These two hold rows that do not move
         * until an operator decides: a merchant cannot trade until their identity papers are
         * reviewed, and a dangerous platform action does not take effect until a second operator
         * approves it. A queue an operator has to remember to visit is a queue that stalls, so
         * they sit directly under "which store" and above the reporting screens.
         *
         * BOTH WERE REACHABLE ONLY BY TYPING THE URI. `kyc.queue` (`platform/verification`) and
         * `platform.approvals.index` were built, gated and tested by two different workstreams,
         * and neither added a navigation entry -- so `OperatorPlatformNavigationTest`, whose
         * subject set is computed from the booted route table, was red on four screens at once.
         */
        $menu->add([
            'title' => __('Verification queue'),
            'icon' => 'certificate',
            'name' => 'platform-verification',
            'parent' => 'platform-command',
            'order' => 20,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'kyc.queue',
            'module' => $module,
            'permission' => ''
        ]);

        $menu->add([
            'title' => __('Dangerous action approvals'),
            'icon' => 'shield-check',
            'name' => 'platform-approvals',
            'parent' => 'platform-command',
            'order' => 30,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'platform.approvals.index',
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
            'order' => 40,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'platform.revenue',
            'module' => $module,
            'permission' => ''
        ]);

        $menu->add([
            'title' => __('Platform analytics'),
            /* `chart-bar`: `chart-histogram` has no rule in the pinned webfont either -- see the
               note on the group header above. Latent rather than visible, because a CHILD entry
               renders no `<i>` in `menu-tree.blade.php`; the value was still wrong, and
               `MerchantPageDirectory` hands this same key to anything that lists a page by icon. */
            'icon' => 'chart-bar',
            'name' => 'platform-analytics',
            'parent' => 'platform-command',
            'order' => 50,
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
            'order' => 60,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'platform.resources',
            'module' => $module,
            'permission' => ''
        ]);

        /*
         * NAV-004 -- THE SHARED INFRASTRUCTURE AND ITS HISTORY, directly after the one host's own
         * load, because the three are read together and are one word apart in their labels.
         * "Platform resources" is THIS machine (load, memory, disk); "Platform operations" is the
         * shared database, the queues and which integrations are configured or failing;
         * "Platform incidents" is what went wrong and what was done about it. An operator
         * diagnosing a slow platform wants all three, in that order, and each sentence on the
         * dashboard panel names the word that tells it from its neighbours.
         *
         * `platform.operations` and `platform.incidents.index` shipped with no navigation entry
         * for the same reason as the two queues above: a different workstream built each one.
         *
         * `alert-triangle` ON THE INCIDENT LOG IS THE SUBJECT, NOT A SEVERITY. Nothing on this
         * platform alerts anybody -- the screen itself says so, and a test asserts it -- so the
         * glyph must not be read as "there is something wrong right now". The label carries the
         * meaning; the icon is the category.
         */
        $menu->add([
            'title' => __('Platform operations'),
            'icon' => 'database',
            'name' => 'platform-operations',
            'parent' => 'platform-command',
            'order' => 70,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'platform.operations',
            'module' => $module,
            'permission' => ''
        ]);

        $menu->add([
            'title' => __('Platform incidents'),
            'icon' => 'alert-triangle',
            'name' => 'platform-incidents',
            'parent' => 'platform-command',
            'order' => 80,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'platform.incidents.index',
            'module' => $module,
            'permission' => ''
        ]);

        $menu->add([
            'title' => __('Official website content'),
            'icon' => 'world',
            'name' => 'platform-site-content',
            'parent' => 'platform-command',
            'order' => 90,
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
            'order' => 100,
            'ignore_if' => [],
            'depend_on' => [],
            'route' => 'platform.documentation.index',
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
