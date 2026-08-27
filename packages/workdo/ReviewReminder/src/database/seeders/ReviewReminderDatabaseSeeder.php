<?php

namespace Workdo\ReviewReminder\database\seeders;

use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Model;

class ReviewReminderDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        
        Model::unguard();

        $this->call(PermissionTableSeeder::class);
        if(module_is_active('SidebarCustomization'))
        {
            $this->call(AdminMenuTableSeeder::class);
        };
        if(module_is_active('LandingPage'))
        {
            $this->call(MarketPlaceSeederTableSeeder::class);
        };
    }
}
