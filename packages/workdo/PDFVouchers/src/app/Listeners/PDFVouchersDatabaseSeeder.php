<?php

namespace Workdo\PDFVouchers\database\seeders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;


class PDFVouchersDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // $this->call([]);
        Model::unguard();
        if(module_is_active('LandingPage'))
        {
            $this->call(MarketPlaceSeederTableSeeder::class);
        }
    }
}
