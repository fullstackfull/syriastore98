<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use App\Facades\ModuleFacade as Module;

class PackageMigrate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'package:migrate {packageName?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate a specific package or all packages';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $packageName = $this->argument('packageName');

        if ($packageName) {
            // Check if the module exists
            $module = Module::find($packageName);
            if ($module) {
                // Migrate the specific package
                $this->migratePackage($module->name ?? $packageName);
            } else {
                $this->error("Module {$packageName} not found.");
            }
        } else {
            // Migrate all packages
            $this->migrateAllPackages();
        }
    }

    /**
     * Run migrations for a specific package.
     *
     * @param string $packageName
     */
    protected function migratePackage($packageName)
    {
        $migrationPath = base_path("packages/workdo/{$packageName}/src/database/migrations");

        if (File::exists($migrationPath)) {
            $this->info("Migrating package {$packageName}...");

            // Run migrations from the specified path
            Artisan::call('migrate', ['--path' => "packages/workdo/{$packageName}/src/database/migrations"]);

            $this->info("Migrations for package {$packageName} completed successfully.");
        } else {
            $this->error("Migration path for package {$packageName} not found.");
        }
    }

    /**
     * Run migrations for all packages.
     */
    protected function migrateAllPackages()
    {
        $packages = $this->getAllPackages();

        foreach ($packages as $package) {
            $this->migratePackage($package);
        }
    }

    /**
     * Get all packages in the 'packages/workdo' directory.
     *
     * @return array
     */
    protected function getAllPackages()
    {
        $packages = [];

        $vendorDir = base_path('packages/workdo');
        $directories = File::directories($vendorDir);

        foreach ($directories as $directory) {
            $packages[] = basename($directory);
        }

        return $packages;
    }
}
