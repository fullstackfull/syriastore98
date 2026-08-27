<?php

namespace Workdo\FreeShippingPopup\database\seeders;

use Illuminate\Database\Seeder;

class FreeShippingPopupDatabaseSeeder extends Seeder
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
