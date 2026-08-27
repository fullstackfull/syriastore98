<?php

namespace Workdo\SpinToWinReward\database\seeders;

use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Model;

class SpinToWinRewardDatabaseSeeder extends Seeder
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
        $this->call(SpinToWinLabelSeederDatabaseSeeder::class);
        if(module_is_active('LandingPage'))
        {
            $this->call(MarketPlaceSeederTableSeeder::class);
        };
    }
}
