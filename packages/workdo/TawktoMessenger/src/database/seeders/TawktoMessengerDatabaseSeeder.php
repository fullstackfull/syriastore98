<?php

namespace Workdo\TawktoMessenger\database\seeders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

class TawktoMessengerDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Model::unguard();
        $this->call(MarketPlaceSeederTableSeeder::class);
    }
}
