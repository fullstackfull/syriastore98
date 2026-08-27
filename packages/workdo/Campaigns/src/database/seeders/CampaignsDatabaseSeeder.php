<?php

namespace Workdo\Campaigns\database\seeders;

use Illuminate\Database\Seeder;
class CampaignsDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // $this->call([]);
        $this->call(PermissionTableSeeder::class);
        if(module_is_active('SidebarCustomization'))
        {
            $this->call(AdminMenuTableSeeder::class);
            $this->call(SuperadminMenuTableSeeder::class);
        };
        if(module_is_active('LandingPage'))
        {
            $this->call(MarketPlaceSeederTableSeeder::class);
        };
    }
}
