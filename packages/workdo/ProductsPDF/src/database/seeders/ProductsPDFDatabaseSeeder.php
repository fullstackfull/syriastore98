<?php

namespace Workdo\ProductsPDF\database\seeders;

use Illuminate\Database\Seeder;

class ProductsPDFDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if(module_is_active('LandingPage'))
        {
            $this->call(MarketPlaceSeederTableSeeder::class);
        };
    }
}
