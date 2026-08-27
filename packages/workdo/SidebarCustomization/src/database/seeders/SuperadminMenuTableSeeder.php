<?php

namespace Workdo\SidebarCustomization\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\Facades\ModuleFacade as Module;
use Illuminate\Support\Facades\Schema;

class SuperadminMenuTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Model::unguard();
        Artisan::call('cache:clear');
        $module = 'Base';
        $all = Module::getOrdered();
        $list = [];
        foreach ($all as $module) {
            if (!isset($module->display) || $module->display) {
                array_push($list, $module->name);
            }
        }
        $menuItems = [
            [
                'title' => __('Dashboard'),
                'icon' => 'home',
                'menu_type' => 'mainmenu',
                'link_type' => 'route',
                'name' => 'dashboard',
                'order' => 1,
                'route' => 'dashboard',
                'module' => $module,
                'permission' => 'Manage Dashboard',
            ],
            [
                'title' => __('Add-on Manager'),
                'icon' => 'layout-2',
                'menu_type' => 'mainmenu',
                'link_type' => 'route',
                'name' => 'add-on-manager',
                'order' => 60,
                'route' => 'module.index',
                'module' => $module,
                'permission' => '',
            ],
            [
                'title' => __('Users'),
                'icon' => 'user',
                'menu_type' => 'mainmenu',
                'link_type' => 'route',
                'name' => 'users',
                'order' => 80,
                'route' => 'stores.index',
                'module' => $module,
                'permission' => 'Manage User',
            ],
            [
                'title' => __('Coupons'),
                'icon' => 'gift',
                'menu_type' => 'mainmenu',
                'link_type' => 'route',
                'name' => 'coupon',
                'order' => 100,
                'route' => 'plan-coupon.index',
                'module' => $module,
                'permission' => 'Manage Coupon',
            ],
            [
                'title' => __('Plan'),
                'icon' => 'trophy',
                'menu_type' => 'mainmenu',
                'link_type' => 'route',
                'name' => 'plan',
                'order' => 120,
                'route' => 'plan.index',
                'module' => $module,
                'permission' => 'Manage Plan',
            ],
            [
                'title' => __('Plan Request'),
                'icon' => 'arrow-up-right-circle',
                'menu_type' => 'mainmenu',
                'link_type' => 'route',
                'name' => 'planrequest',
                'order' => 140,
                'route' => 'plan-request.index',
                'module' => $module,
                'permission' => 'Manage Plan Request',
            ],
            [
                'title' => __('Email Templates'),
                'icon' => 'mail',
                'menu_type' => 'mainmenu',
                'link_type' => 'route',
                'name' => 'email-templates',
                'order' => 160,
                'route' => 'email_template',
                'module' => $module,
                'permission' => 'Manage Email Template',
            ],
            [
                'title' => __('Settings'),
                'icon' => 'settings',
                'menu_type' => 'mainmenu',
                'link_type' => 'route',
                'name' => 'settings',
                'order' => 300,
                'route' => 'setting.index',
                'module' => $module,
                'permission' => 'Manage Setting',
            ],
            [
                'title' => __('Country'),
                'icon' => 'current-location',
                'menu_type' => 'mainmenu',
                'link_type' => 'route',
                'name' => 'country',
                'order' => 200,
                'route' => 'countries.index',
                'module' => $module,
                'permission' => 'Manage Country',
            ],
            [
                'title' => __('Currency'),
                'icon' => 'currency-dollar',
                'menu_type' => 'mainmenu',
                'link_type' => 'route',
                'name' => 'currency',
                'order' => 210,
                'route' => 'currency.index',
                'module' => $module,
                'permission' => 'Manage Currency',
            ],
            [
                'title' => __('Side Bar Customization'),
                'icon' => 'circle-plus',
                'menu_type' => 'mainmenu',
                'link_type' => 'route',
                'name' => 'sidebarcustomization',
                'order' => 230,
                'route' => 'sidemenu.index',
                'module' => 'SidebarCustomization',
                'permission' => 'Manage Sidebar Menus',
            ],
            [
                'title' => __('CMS'),
                'icon' => 'package',
                'menu_type' => 'mainmenu',
                'link_type' => 'route',
                'name' => 'landing-page',
                'parent' => null,
                'order' => 220,
                'route' => '',
                'module' => 'LandingPage',
                'permission' => 'Manage CMS',
            ],
            [
                'title' => __('Landing Page'),
                'icon' => 'settings',
                'menu_type' => 'submenu',
                'link_type' => 'route',
                'name' => 'landingpages',
                'parent' => 'landing-page',
                'order' => 1,
                'route' => 'landingpage.index',
                'module' => 'LandingPage',
                'permission' => '',
            ],
            [
                'title' => __('Menus'),
                'icon' => 'settings',
                'menu_type' => 'submenu',
                'link_type' => 'route',
                'name' => 'menus',
                'parent' => 'landing-page',
                'order' => 3,
                'route' => 'ownermenus.index',
                'module' => 'LandingPage',
                'permission' => '',
            ],
            [
                'title' => __('Custom Page'),
                'icon' => 'settings',
                'menu_type' => 'submenu',
                'link_type' => 'route',
                'name' => 'custompage',
                'parent' => 'landing-page',
                'order' => 4,
                'route' => 'menu-pages.index',
                'module' => 'LandingPage',
                'permission' => '',
            ],


        ];
        if (is_array($list) && count($list) > 0) {
            $menuItems[] = [
                'title' => __('Marketplace'),
                'icon' => 'settings',
                'menu_type' => 'submenu',
                'link_type' => 'route',
                'name' => '',
                'parent' => 'landing-page',
                'order' => 2,
                'route' => 'marketplace.index',
                'module' => 'LandingPage',
                'permission' => '',
            ];
        }
        $superAdmin = User::where('type', 'super admin')->first();
        foreach ($menuItems as $item) {
            if (Schema::hasTable('side_menus_option')) {
                DB::table('side_menus_option')->updateOrInsert(
                    ['name' => $item['name'], 'type' => 'super admin'],
                    [
                        'title' => $item['title'],
                        'menu_type' => $item['menu_type'],
                        'link_type' => $item['link_type'],
                        'type' => 'super admin',
                        'icon' => $item['icon'],
                        'link' => $item['route'],
                        'position' => $item['order'],
                        'parent_id' => $item['parent'] ?? null,
                        'order' => $item['order'],
                        'window_type' => null,
                        'is_enable' => 'on',
                        'store_id' => $superAdmin->current_store,
                        'created_by' => $superAdmin->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }
}
