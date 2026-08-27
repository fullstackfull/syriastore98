<?php

namespace Workdo\RecentlyViewedProducts\database\seeders;

use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Model;

class RecentlyViewedProductsDatabaseSeeder extends Seeder
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
            $this->call(AdminMenuTableSeeder::class);
        };
        if(module_is_active('LandingPage'))
        {
            $this->call(MarketPlaceSeederTableSeeder::class);
        };
    }
}