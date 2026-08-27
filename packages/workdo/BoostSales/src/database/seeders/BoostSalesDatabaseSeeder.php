<?php

namespace Workdo\BoostSales\database\seeders;

use Illuminate\Database\Seeder;
use Workdo\BoostSales\database\seeders\MarketPlaceSeederTableSeeder;


class BoostSalesDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (module_is_active('LandingPage')) {
            $this->call(MarketPlaceSeederTableSeeder::class);
        }
        ;
    }
}
