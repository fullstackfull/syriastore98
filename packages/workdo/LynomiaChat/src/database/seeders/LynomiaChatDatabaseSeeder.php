<?php

namespace Workdo\LynomiaChat\Database\Seeders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

class LynomiaChatDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Model::unguard();

        $this->call(PermissionTableSeeder::class);
        $this->call(MarketPlaceSeederTableSeeder::class);

        Model::reguard();
    }
}
