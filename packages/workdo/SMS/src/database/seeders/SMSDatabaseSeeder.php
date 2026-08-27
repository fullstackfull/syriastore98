<?php

namespace Workdo\SMS\database\seeders;

use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Model;

class SMSDatabaseSeeder extends Seeder
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
        $this->call(NotificationsTableSeeder::class);
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