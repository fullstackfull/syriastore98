<?php

namespace App\Console\Commands;

use App\Models\AddOnManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Filesystem\Filesystem;

class CreatePackage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'package:make {name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new package with the specified folder structure';

    /**
     * Execute the console command.
     */

    protected $files;
    public $LowerName;
    public $UpperName;
    public $packageName;

    public function __construct(Filesystem $files)
    {
        parent::__construct();
        $this->files = $files;
    }

    public function handle()
    {
        $name = $this->argument('name');
        $this->LowerName = strtolower($name);
        $this->UpperName = $name;
        $this->packageName = $this->camelToKebab($name);

        $packagePath = base_path("packages/workdo/{$name}");

        if (File::exists($packagePath)) {
            $this->error("Package {$name} already exists!");
            return;
        }

        File::makeDirectory($packagePath, 0777, true);

        $folders = [
            'src/database/migrations',
            'src/database/seeders',
            'src/app/Models',
            'src/app/Events',
            'src/app/Http/Controllers/Company',
            'src/app/Http/Controllers/SuperAdmin',
            'src/app/Listeners',
            'src/app/Providers',
            'src/resources/lang',
            'src/resources/views/company/settings',
            'src/resources/views/layouts',
            'src/resources/views/marketplace',
            'src/resources/views/super-admin/settings',
            'src/routes',
            'src/DataTables'
        ];

        foreach ($folders as $folder) {
            File::makeDirectory("{$packagePath}/{$folder}", 0777, true);
        }

        $this->createStubFiles($packagePath);

        $this->createFiles();

        $addon = AddOnManager::where('module',$this->UpperName)->first();
        if(empty($addon))
        {
            $addon = new AddOnManager;
            $addon->module = $this->UpperName;
            $addon->name = $this->UpperName;
            $addon->monthly_price = 0;
            $addon->yearly_price = 0;
            $addon->is_enable = 0;
            $addon->package_name = $this->packageName;
            $addon->save();
        }


        $this->info("Package {$name} created successfully!");
    }

    function camelToKebab($name)
    {
        $packageName = preg_replace('/([a-z])([A-Z])/', '$1-$2', $name);
        return strtolower($packageName);
    }

    protected function getComposerJsonStub()
    {
        $name = "workdo/{$this->packageName}";
        $description = "Description for {$this->packageName} package";
        $namespace = "Workdo\\\\{$this->UpperName}\\\\app\\\\Providers\\\\{$this->UpperName}ServiceProvider";

        return <<<EOT
        {
            "name": "{$name}",
            "description": "{$description}",
            "type": "library",
            "license": "MIT",
            "require": {},
            "autoload": {
                "psr-4": {
                    "Workdo\\\\{$this->UpperName}\\\\": "src/"
                }
            },
            "authors": [
                {
                    "name": "WorkDo",
                    "email": "support@workdo.io"
                }
            ],
            "extra": {
                "laravel": {
                    "providers": [
                        "{$namespace}"
                    ]
                }
            }
        }
        EOT;
    }

    protected function getModuleJsonStub()
    {
        return <<<EOT
        {
            "name": "{$this->UpperName}",
            "alias": "{$this->UpperName}",
            "description": "",
            "priority": 0,
            "version":1.0,
            "monthly_price": 0,
            "yearly_price": 0,
            "package_name":"{$this->packageName}"
        }
        EOT;
    }

    protected function createStubFiles($packagePath)
    {
        $composerJson = $this->getComposerJsonStub();
        $this->files->put($packagePath . "/composer.json", $composerJson);

        $moduleJson = $this->getModuleJsonStub();
        $this->files->put($packagePath . "/module.json", $moduleJson);

        $serviceProviderStub = $this->getServiceProviderStub();
        $this->files->put($packagePath . "/src/app/Providers/{$this->UpperName}ServiceProvider.php", $serviceProviderStub);

        $seederStub = $this->getSeederStub();
        $this->files->put($packagePath."/src/database/seeders/{$this->UpperName}DatabaseSeeder.php",$seederStub);
    }

    protected function createFiles()
    {
        $files = [
            'listener/companymenu.stub' => 'src/app/Listeners/CompanyMenuListener.php',
            'listener/companysetting.stub' => 'src/app/Listeners/CompanySettingListener.php',
            'listener/companysettingmenu.stub' => 'src/app/Listeners/CompanySettingMenuListener.php',
            'listener/superadminmenu.stub' => 'src/app/Listeners/SuperAdminMenuListener.php',
            'listener/superadminsetting.stub' => 'src/app/Listeners/SuperAdminSettingListener.php',
            'listener/superadminsettingmenu.stub' => 'src/app/Listeners/SuperAdminSettingMenuListener.php',
            'http/controllers/company/settingscontroller.stub' => 'src/app/Http/Controllers/Company/SettingsController.php',
            'http/controllers/superadmin/settingscontroller.stub' => 'src/app/Http/Controllers/SuperAdmin/SettingsController.php',
            'routes/web.stub'=>'src/routes/web.php',
            'routes/api.stub'=>'src/routes/api.php',
            'seeders/MarketPlaceSeederTableSeeder.stub'=>'src/database/seeders/MarketPlaceSeederTableSeeder.php',
            'seeders/PermissionTableSeeder.stub'=>'src/database/seeders/PermissionTableSeeder.php',
            'views/company/settings/index.stub'=>'src/resources/views/company/settings/index.blade.php',
            'views/super-admin/settings/index.stub'=>'src/resources/views/super-admin/settings/index.blade.php',
            'views/marketplace/index.stub'=>'src/resources/views/marketplace/index.blade.php',
            'views/index.stub'=>'src/resources/views/index.blade.php',
            'providers/eventserviceprovider.stub' => 'src/app/Providers/EventServiceProvider.php',
            'providers/routeserviceprovider.stub' => 'src/app/Providers/RouteServiceProvider.php'

        ];

        foreach ($files as $stubFile => $phpFile) {
            $stubPath = base_path('stubs/workdo-stubs/'.$stubFile);
            $stub = File::get($stubPath);

            $stub = str_replace('$STUDLY_NAME$', $this->UpperName, $stub);
            $stub = str_replace('$LOWER_NAME$', $this->LowerName, $stub);
            $stub = str_replace('$PACKAGE_NAME$', $this->packageName, $stub);

            $filePath = base_path("packages/workdo/{$this->UpperName}/".$phpFile);

            if (!File::exists(dirname($filePath))) {
                File::makeDirectory(dirname($filePath), 0777, true);
            }
            $this->files->put($filePath, $stub);
        }
    }

    protected function getServiceProviderStub()
    {
        return <<<EOT
        <?php

        namespace Workdo\\{$this->UpperName}\\app\\Providers;

        use Illuminate\Support\ServiceProvider;
        // use Workdo\\{$this->UpperName}\\app\\Providers\EventServiceProvider;
        // use Workdo\\{$this->UpperName}\\app\\Providers\RouteServiceProvider;

        class {$this->UpperName}ServiceProvider extends ServiceProvider
        {

            protected \$moduleName = '{$this->UpperName}';
            protected \$moduleNameLower = '{$this->LowerName}';

            public function register()
            {
                \$this->app->register(RouteServiceProvider::class);
                \$this->app->register(EventServiceProvider::class);
            }

            public function boot()
            {
                \$this->loadRoutesFrom(__DIR__ . '/../../routes/web.php');
                \$this->loadViewsFrom(__DIR__ . '/../../resources/views', '{$this->packageName}');
                \$this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
                \$this->registerTranslations();
            }

            /**
             * Register translations.
             *
             * @return void
             */
            public function registerTranslations()
            {
                \$langPath = resource_path('lang/modules/' . \$this->moduleNameLower);

                if (is_dir(\$langPath)) {
                    \$this->loadTranslationsFrom(\$langPath, \$this->moduleNameLower);
                    \$this->loadJsonTranslationsFrom(\$langPath);
                } else {
                    \$this->loadTranslationsFrom(__DIR__.'/../../resources/lang', \$this->moduleNameLower);
                    \$this->loadJsonTranslationsFrom(__DIR__.'/../../resources/lang');
                }
            }
        }
        EOT;
    }

    protected function getSeederStub()
    {
        return <<<EOT
        <?php

        namespace Workdo\\{$this->UpperName}\\database\seeders;

        use Illuminate\Database\Seeder;
        use Illuminate\Database\Eloquent\Model;

        class {$this->UpperName}DatabaseSeeder extends Seeder
        {
            /**
             * Run the database seeds.
             *
             * @return void
             */
            public function run()
            {
                Model::unguard();

                \$this->call(PermissionTableSeeder::class);
                if(module_is_active('LandingPage'))
                {
                    \$this->call(MarketPlaceSeederTableSeeder::class);
                };
            }
        }
        EOT;
    }
}
