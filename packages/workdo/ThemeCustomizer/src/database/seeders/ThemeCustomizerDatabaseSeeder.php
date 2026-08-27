<?php

namespace Workdo\ThemeCustomizer\database\seeders;

use Illuminate\Database\Seeder;

class ThemeCustomizerDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call(PermissionTableSeeder::class);

        if(module_is_active('LandingPage'))
        {
            $this->call(MarketPlaceSeederTableSeeder::class);
        };
    }
}
