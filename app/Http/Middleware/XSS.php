<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use App\Models\User;
use App\Facades\ModuleFacade as Module;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;

class XSS
{
    use \RachidLaasri\LaravelInstaller\Helpers\MigrationsHelper;
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        if (Auth::check() && Auth::user() && Auth::user()->type == 'super admin') {
            $ranMigrations = DB::table('migrations')->pluck('migration');
            $modules = Module::allModules();
        
            $migrationFiles = collect(File::glob(database_path('migrations/*.php')))->map(fn ($path) => File::name($path));
        
            foreach ($modules as $module) {
                $packageMigration = ["packages/workdo/{$module->name}/src/Database/Migrations", "packages/workdo/{$module->name}/src/database/migrations"];
                foreach ($packageMigration as $dir) {
                    if (File::exists($dir)) {
                        $files = collect(File::glob("{$dir}/*.php"))->map(fn ($path) => File::name($path));
                        $migrationFiles = $migrationFiles->merge($files);
                    }
                }
            }

           
            $pendingMigrations = $migrationFiles->diff($ranMigrations);
        
            if (count($pendingMigrations) > 0) {
                return redirect()->route('LaravelUpdater::welcome');
            }
        }

        $input = $request->all();

        array_walk_recursive($input, function (&$value) {
            if (is_string($value)) {
                $value = htmlspecialchars_decode($value);
                //$value = preg_replace('/<\s*script\b[^>]*>(.*?)<\s*\/\s*script\s*>/is', '', $value);
                $value = str_replace(['&lt;', '&gt;', 'javascript', 'alert'], '', $value);
            }
        });
        $request->merge($input);
        return $next($request);
    }
}
