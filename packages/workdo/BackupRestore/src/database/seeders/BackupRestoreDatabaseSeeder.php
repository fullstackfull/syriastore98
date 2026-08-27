<?php

namespace Workdo\BackupRestore\database\seeders;

use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Model;

class BackupRestoreDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Model::unguard();

        $this->call(PermissionTableSeeder::class);
        if(module_is_active('SidebarCustomization'))
        {
            $this->call(SuperadminMenuTableSeeder::class);
        };
        if(module_is_active('LandingPage'))
        {
            $this->call(MarketPlaceSeederTableSeeder::class);
        };
    }
}