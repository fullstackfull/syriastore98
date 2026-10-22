<?php

namespace Tests\Support\Security;

use App\Models\AddOnManager;
use Illuminate\Support\Facades\Cache;

/**
 * Enables WorkDo modules THROUGH THE PRODUCTION LOADER (PackageServiceProvider::loadAddons) and runs their own migrations, for tests that
 * drive module routes. No module loads in a test run unless a row is inserted, so a test that "passes" against a module surface may never
 * have reached it: every user of this trait asserts a premise (the route or table it needs) after calling it.
 *
 * Migrations that fail are collected and returned, not swallowed: a module whose migration needs a table the host does not create would
 * otherwise leave a later assertion to fail for the wrong reason.
 */
trait EnablesWorkdoModules
{
    /**
     * @param list<string> $modules
     * @return list<string> the migration files that threw, as "Module/file: message"
     */
    protected function enableWorkdoModules(array $modules): array
    {
        foreach ($modules as $module) {
            $meta = json_decode((string) file_get_contents(base_path("packages/workdo/{$module}/module.json")), true) ?: [];

            AddOnManager::firstOrCreate(['module' => $module], [
                'name' => $meta['name'] ?? $module,
                'monthly_price' => 0,
                'yearly_price' => 0,
                'is_enable' => 1,
                'package_name' => $meta['package_name'] ?? strtolower($module),
            ]);
        }

        Cache::flush();

        $provider = new \App\Providers\PackageServiceProvider($this->app);
        $loadAddons = new \ReflectionMethod($provider, 'loadAddons');
        $loadAddons->setAccessible(true);
        $loadAddons->invoke($provider, $modules);

        $failed = [];

        foreach ($modules as $module) {
            $files = glob(base_path("packages/workdo/{$module}/src/database/migrations/*.php")) ?: [];
            sort($files);

            foreach ($files as $file) {
                try {
                    (require $file)->up();
                } catch (\Throwable $e) {
                    $failed[] = $module . '/' . basename($file) . ': ' . substr($e->getMessage(), 0, 120);
                }
            }
        }

        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->app['router']->getRoutes()->refreshActionLookups();

        return $failed;
    }
}
