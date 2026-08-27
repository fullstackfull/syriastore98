<?php

namespace Workdo\ProductBadgeManagement\database\seeders;

use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Model;

class ProductBadgeManagementDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Model::unguard();
        if(module_is_active('LandingPage'))
        {
   
            $this->call(MarketPlaceSeederTableSeeder::class);
        }
    }

}


