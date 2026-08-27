<?php

namespace Workdo\ThemeCustomizer\Database\Seeders;

use App\Models\Role;
use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class PermissionTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        //Model::unguard();
        Artisan::call('cache:clear');
        $permission  = [
            'Manage Theme Customizer',
            'Edit Theme Customizer',
        ];

        $company_role = Role::where('name','admin')->first();
        foreach ($permission as $key => $value)
        {
            $table = Permission::where('name',$value)->where('module','ThemeCustomizer')->exists();
            if(!$table)
            {
                $permission = Permission::create(
                    [
                        'name' => $value,
                        'guard_name' => 'web',
                        'module' => 'ThemeCustomizer',
                        'created_by' => 0,
                        "created_at" => date('Y-m-d H:i:s'),
                        "updated_at" => date('Y-m-d H:i:s')
                    ]
                );
                if(!$company_role->hasPermission($value))
                {
                    $company_role->givePermission($permission);
                }
            }
        }

    }

}
