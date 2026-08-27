<?php

namespace Workdo\FrequentlyProduct\database\seeders;

use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Model;
use Workdo\FrequentlyProduct\database\seeders\RoleTableSeeder;
use Workdo\FrequentlyProduct\database\seeders\PermissionTableSeeder;

class FrequentlyProductDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // $this->call([]);
        Model::unguard();

        $this->call(PermissionTableSeeder::class);
        $this->call(RoleTableSeeder::class);


        if (module_is_active('LandingPage')) {
            $this->call(MarketPlaceSeederTableSeeder::class);
        }
        ;
    }
}
