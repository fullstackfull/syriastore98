<?php

namespace Workdo\ProductCatelog\database\seeders;

use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Model;

class ProductCatelogDatabaseSeeder extends Seeder
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
