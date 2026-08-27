<?php

namespace Workdo\SidebarCustomization\database\seeders;

use App\Classes\Module;
use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;
use App\Facades\ModuleFacade as Modules;

class SidebarCustomizationDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        ini_set('max_execution_time', 600);
        Model::unguard();

        $module = Modules::find('SidebarCustomization');
        $module->enable();

        $module = new Module();
        $enabledModules = $module->allEnabled();

        $enabledModules = array_filter($enabledModules, function ($moduleName) {
            return $moduleName !== 'SidebarCustomization';
        });
        
        $processedModules = [];
        
        foreach ($enabledModules as $moduleName) {
            if (isset($moduleName) && !in_array($moduleName, $processedModules)) {
                $processedModules[] = $moduleName;
        
                $superadminSeederPath = base_path('packages/workdo/' . $moduleName . '/src/database/seeders/SuperadminMenuTableSeeder.php');
                $adminSeederPath = base_path('packages/workdo/' . $moduleName . '/src/database/seeders/AdminMenuTableSeeder.php');
                
                if (File::exists($superadminSeederPath)) {
                    try {
                        Artisan::call('package:seed ' . $moduleName);
                    } catch (\Exception $e) {
                    }
                }
                if (File::exists($adminSeederPath)) {
                    try {
                        Artisan::call('package:seed ' . $moduleName);
                    } catch (\Exception $e) {
                    }
                }
                try {
                    Modules::moduleCacheForget($moduleName);
                } catch (\Exception $e) {
                   
                }
            }
        }
        
        $this->call(PermissionTableSeeder::class);
        $this->call(SuperadminMenuTableSeeder::class);
        $this->call(AdminMenuTableSeeder::class);

        if (module_is_active('LandingPage')) {
            $this->call(MarketPlaceSeederTableSeeder::class);
        }
    }
}
