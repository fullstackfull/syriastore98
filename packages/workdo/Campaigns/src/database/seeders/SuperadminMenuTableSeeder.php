<?php

namespace Workdo\Campaigns\Database\Seeders;

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
                'title' =>  __('Campaigns'),
                'icon' => '3d-cube-sphere',
                'menu_type' => 'submenu',
                'link_type' => 'route',
                'name' => 'campaigns',
                'parent' => 'landing-page',
                'order' => 3,
                'route' => 'campaigns.index',
                'module' => 'Campaigns',
                'permission' => 'Manage Campaigns',
            ],
            [
                'title' =>  __('System Setup'),
                'icon' => '3d-cube-sphere',
                'menu_type' => 'submenu',
                'link_type' => 'route',
                'name' => 'System Setup',
                'parent' => 'landing-page',
                'order' => 4,
                'route' => 'campaigns.setting',
                'module' => 'Campaigns',
                'permission' => 'Manage Campaigns Settings',
            ],
        ];

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
