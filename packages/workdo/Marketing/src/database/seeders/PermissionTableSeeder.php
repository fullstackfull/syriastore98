<?php

namespace Workdo\Marketing\Database\Seeders;

use App\Models\Role;
use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;


class PermissionTableSeeder extends Seeder
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
        $permission  = [
            'Edit Marketing Contact',
            'Delete Marketing Contact',
            'Manage Marketing Contact',
            'Delete Appointment',
            'Edit Appointment',
            'Manage Appointment',
            'Calendar Appointment',
            'Manage QR',
            'Manage Cookie',
            'Manage PWA',
            'Manage SEO',
            'Manage Domain',
            'Manage Block',
            'Manage Card Data',
            'Update Store Data',
            'Update Theme',
            'Update Domain Setting',
            'Update Block Setting',
            'Update SEO Setting',
            'Update PWA Setting',
            'Update Cookie Setting',
            'Update QR Setting',
            'business pixel settings'
        ];

        $company_role = Role::where('name','admin')->first();
        foreach ($permission as $key => $value)
        {
            $table = Permission::where('name',$value)->where('module','Marketing')->exists();
            if(!$table)
            {
                $permission = Permission::create(
                    [
                        'name' => $value,
                        'guard_name' => 'web',
                        'module' => 'Marketing',
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
