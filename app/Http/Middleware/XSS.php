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
     * Request keys this middleware must pass through untouched.
     *
     * Pinned rather than pattern-matched on `*password*`, so adding one is a deliberate act and
     * so a field merely NAMED like a password cannot opt itself out of sanitising.
     * Guarded by tests/Feature/Security/CredentialSanitisationTest.
     */
    /**
     * The substrings the sweep strips from every value it is allowed to touch.
     *
     * A CONSTANT RATHER THAN AN INLINE LITERAL, because the test that proves the exemption works
     * has to measure THIS list and not a copy of it. `CredentialSanitisationTest`'s premise --
     * "the chosen password really would have been mangled" -- re-declared these four strings, so
     * if the sweep ever changed, the premise would stay green while the cases it guards silently
     * started passing because there was nothing left to mangle. Found by independent review.
     */
    public const STRIPPED_SUBSTRINGS = ['&lt;', '&gt;', 'javascript', 'alert'];

    public const CREDENTIAL_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'new_password_confirmation',
        'old_password',
        'token',
        '_token',
    ];
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

        /*
         * P0-PROFILE. A CREDENTIAL IS NEVER REWRITTEN.
         *
         * The sweep below deletes the substrings `javascript` and `alert` from EVERY string in
         * EVERY request, and `password` was not exempt. MEASURED: `my alert pass` reaches the
         * handler as `my  pass`. Two consequences, and the second is the worse one:
         *
         *   VERIFYING  `current_password` on DELETE /profile compares the MANGLED string against
         *              the stored hash, so a merchant whose password contains `alert` could not
         *              confirm it -- and two different passwords can mangle to the same value.
         *   SETTING    a registration or reset stores the mangled value, so the account's real
         *              password silently differs from what was typed. A credential the user has
         *              never seen.
         *
         * The sanitiser's purpose is to stop markup reaching a view. A password is never
         * rendered, so exempting it removes no protection -- it removes a corruption.
         *
         * `_token` is here as belt and braces rather than as a live control, and the stated
         * reason used to be wrong: CSRF is NOT verified after this runs. `VerifyCsrfToken` is in
         * the `web` group and `xss` is route middleware on the group at routes/web.php:411, so
         * the token has already been compared by the time `$request->merge($input)` happens
         * below. Exempting it is harmless and keeps the list "every credential-shaped key",
         * which is the rule a reader can apply; it is not what protects the CSRF check.
         */
        $neverRewrite = self::CREDENTIAL_KEYS;

        array_walk_recursive($input, function (&$value, $key) use ($neverRewrite) {
            if (in_array($key, $neverRewrite, true)) {
                return;
            }

            if (is_string($value)) {
                $value = htmlspecialchars_decode($value);
                //$value = preg_replace('/<\s*script\b[^>]*>(.*?)<\s*\/\s*script\s*>/is', '', $value);
                $value = str_replace(self::STRIPPED_SUBSTRINGS, '', $value);
            }
        });
        $request->merge($input);
        return $next($request);
    }
}
