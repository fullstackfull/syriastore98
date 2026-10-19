<?php

/**
 * P1-INV-14 -- ROUTE-CACHE VERIFICATION FOR App\Http\Middleware\ModuleOrderMutationGuard.
 *
 * NOT A TEST, AND DELIBERATELY NOT IN A TESTSUITE. `tests/Support` is not one of the three
 * directories phpunit.xml collects, so this never runs as part of a suite -- which also means it
 * cannot disturb the declared outcome set in .github/known-test-outcomes.json. It could not be a
 * PHPUnit test in any case: `php artisan route:cache` writes bootstrap/cache/routes-v7.php and
 * the claim under test is about what a LATER, SEPARATE process does with that file, which an
 * in-process assertion cannot observe.
 *
 * THE CLAIM, IN TWO PARTS.
 *
 *   (a) The guard survives `route:cache`. It is registered with `appendToGroup('web', ...)`
 *       rather than on the six routes it protects, because those routes live in vendored module
 *       route files that CI forbids editing and that are required from `$this->app->booted()` --
 *       after routes/web.php -- so a host re-declaration loses the method+uri slot again. A
 *       group-appended middleware is EXPECTED to survive route caching because the cache stores
 *       declared middleware NAMES unexpanded and the group is resolved per request from
 *       bootstrap/app.php. That is an expectation about framework internals, so it is measured.
 *
 *   (b) It actually REFUSES. Stack presence is not refusal. The probe dispatches a real
 *       anonymous POST to each of the six uris through the HTTP kernel of a process booted FROM
 *       the cache and reports the status code. 403 on all six, from a process that read its
 *       routes off disk, is the end-to-end claim.
 *
 * The sibling script verify-order-document-route-cache.php establishes (a) for the READ guard;
 * this one repeats it for the WRITE guard because the two are registered independently and a
 * future change to one says nothing about the other.
 *
 * NEVER TOUCHES A REAL DATABASE. Pass DB_CONNECTION/DB_DATABASE in the ENVIRONMENT -- Laravel's
 * Dotenv is immutable, so .env cannot win them back. Step 1 asserts the override took effect and
 * aborts before writing anything if it did not.
 *
 *   DB_CONNECTION=sqlite DB_DATABASE=/tmp/p1inv14.sqlite \
 *       php tests/Support/Security/bin/verify-module-order-mutation-route-cache.php
 *
 * Exit 0 = every check passed. Exit 1 = a check failed, or the environment was not safe.
 */

$root = dirname(__DIR__, 4);

require $root . '/vendor/autoload.php';

/** The six uris, and the request key each handler reads. */
const TARGETS = [
    'lottery-order-status-change/{id}' => 'id',
    'lottery-order-return' => 'order_id',
    'auction-order-status-change/{id}' => 'id',
    'auction-order-return' => 'order_id',
    'auction-order-assign' => 'order_id',
    'update-auction-status' => 'auctionId',
];

$failed = false;

$out = function (string $label, bool $ok, string $detail = '') use (&$failed): void {
    printf("  [%s] %s%s\n", $ok ? ' OK ' : 'FAIL', $label, $detail === '' ? '' : "\n         " . $detail);

    if (! $ok) {
        $failed = true;
    }
};

$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "STEP 1 -- the database is a throwaway sqlite file, not a real one.\n";
$out('config(database.default) === sqlite', config('database.default') === 'sqlite',
    'actual: ' . var_export(config('database.default'), true));

$sqlitePath = (string) config('database.connections.sqlite.database');
$out('the sqlite file is outside the repository', ! str_starts_with($sqlitePath, $root),
    'actual: ' . var_export($sqlitePath, true));

if ($failed) {
    echo "\nABORTING before any cache write: the environment override did not take.\n";
    exit(1);
}

echo "\nSTEP 2 -- build the HOST schema in the throwaway file, then enable both modules.\n";

/*
 * THE SUBPROCESS ENVIRONMENT, SET ONCE AND USED FOR EVERY CHILD PROCESS BELOW.
 *
 * SESSION_DRIVER AND CACHE_STORE ARE FORCED TO `array`, and that is not cosmetic. The repo's
 * .env uses the database driver for both; the `sessions` and `cache` tables do exist after the
 * migrate below, but the array drivers keep each dispatch independent so one request cannot
 * carry state into the next and a refusal cannot be explained by a cached answer.
 *
 * A THROWAWAY APP_KEY, GENERATED HERE. The container this runs in may have no APP_KEY at all, in
 * which case EncryptCookies raises MissingAppKeyException and every dispatch 500s before the
 * guard ever runs. This one is random per run and exists only in the subprocess environment:
 * the repository's own key is never read, printed, or relied on -- CLAUDE.md records that it is
 * in git history and unrotated, so nothing here should depend on it.
 *
 * APP_DEBUG is left as the repo configures it; the probe surfaces the throwable itself.
 */
$appKey = 'base64:' . base64_encode(random_bytes(32));

$env = 'DB_CONNECTION=' . escapeshellarg('sqlite') . ' DB_DATABASE=' . escapeshellarg($sqlitePath)
    . ' SESSION_DRIVER=array CACHE_STORE=array APP_KEY=' . escapeshellarg($appKey);

echo "         php artisan migrate --force against the throwaway sqlite file. The host schema is\n";
echo "         needed because middleware AHEAD of the guard in the `web` group reads it --\n";
echo "         SetLocate resolves the platform admin from `users` -- so without it every\n";
echo "         dispatch below would 500 before reaching the guard and prove nothing.\n";
exec('cd ' . escapeshellarg($root) . " && {$env} php artisan migrate --force 2>&1", $migrateLines, $migrateRc);
$out('migrate exited 0', $migrateRc === 0,
    $migrateRc === 0 ? '' : implode(' | ', array_slice($migrateLines, -6)));

echo "\n         PackageServiceProvider::loadDynamicAddons() does AddOnManager::pluck('module')\n";
echo "         with NO is_enable predicate, so row existence alone registers the routes.\n";

foreach (['LotteryPrizes', 'AuctionProduct'] as $module) {
    Illuminate\Support\Facades\DB::table('add_on_managers')->updateOrInsert(
        ['module' => $module],
        ['name' => $module, 'is_enable' => 1]
    );
}

$out('add_on_managers holds both rows', Illuminate\Support\Facades\DB::table('add_on_managers')
    ->whereIn('module', ['LotteryPrizes', 'AuctionProduct'])->count() === 2);

echo "\nSTEP 3 -- php artisan route:cache (a fresh application boot, in its own process).\n";
$cachePath = $root . '/bootstrap/cache/routes-v7.php';
$preExisting = is_file($cachePath);
$out('no route cache was present before this run', ! $preExisting,
    $preExisting ? 'One existed and will be removed in step 6; a developer who had cached '
        . 'routes will need to re-run route:cache afterwards.' : '');
@unlink($cachePath);

exec('cd ' . escapeshellarg($root) . " && {$env} php artisan route:cache 2>&1", $lines, $rc);
echo '         ' . implode("\n         ", $lines) . "\n";
$out('route:cache exited 0', $rc === 0);
$out('bootstrap/cache/routes-v7.php exists', is_file($cachePath));

$cache = (string) @file_get_contents($cachePath);

echo "\nSTEP 4 -- WHAT THE CACHE FILE ITSELF CONTAINS.\n";
$out('the cache does NOT name ModuleOrderMutationGuard', ! str_contains($cache, 'ModuleOrderMutationGuard'),
    'This is the mechanism: the cache stores declared middleware NAMES ("web") unexpanded, so '
    . "the group's members are resolved per request from bootstrap/app.php instead of frozen here.");
$out('the cache DOES name the group "web" as a declared middleware',
    str_contains($cache, '"web"') || str_contains($cache, "'web'"),
    'The declared name is what is frozen; its members are not.');
$out('the cache holds all six target uris',
    count(array_filter(array_keys(TARGETS), fn ($uri) => str_contains($cache, $uri))) === 6,
    'present: ' . implode(', ', array_filter(array_keys(TARGETS), fn ($uri) => str_contains($cache, $uri))));

echo "\nSTEP 5 -- A SEPARATE PROCESS, booting FROM the cache, resolves the stack AND dispatches.\n";
$probePath = sys_get_temp_dir() . '/p1inv14-route-cache-probe-' . getmypid() . '.php';
$targetsJson = json_encode(TARGETS);
file_put_contents($probePath, <<<PROBE
<?php
require '{$root}/vendor/autoload.php';
\$targets = json_decode('{$targetsJson}', true);

\$app = require '{$root}/bootstrap/app.php';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\$router = \$app->make('router');

\$report = ['cached' => \$app->routesAreCached(), 'routes' => []];

foreach (\$router->getRoutes() as \$r) {
    if (array_key_exists(\$r->uri(), \$targets) && in_array('POST', \$r->methods(), true)) {
        \$report['routes'][\$r->uri()] = [
            'action' => \$r->getActionName(),
            'declared' => array_values(\$r->gatherMiddleware()),
            'resolved' => array_values(\$router->gatherRouteMiddleware(\$r)),
        ];
    }
}

// (b) -- REFUSAL, not just presence. A real anonymous POST through the HTTP kernel of this
// cached-boot process. The guard denies at its anonymous limb before any table read, so no
// application table needs to exist for this to be meaningful.
\$kernel = \$app->make(Illuminate\Contracts\Http\Kernel::class);

/*
 * CSRF IS DELIBERATELY REMOVED FOR THIS STEP, AND THAT MAKES THE CHECK STRICTER, NOT WEAKER.
 * ValidateCsrfToken sits ahead of the guard in the `web` group and skips itself only when
 * runningUnitTests() -- false in this process -- so a tokenless POST would answer 419 and MASK
 * whatever the guard did. Replacing it with a pass-through means a 403 below can have come from
 * nothing but App\Http\Middleware\ModuleOrderMutationGuard. CSRF remains fully in force in the
 * application; it is bypassed only inside this probe process.
 */
\$app->instance(
    Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    new class {
        public function handle(\$request, \Closure \$next) { return \$next(\$request); }
    }
);

foreach (\$targets as \$uri => \$key) {
    \$path = str_replace('{id}', '1', \$uri);
    \$request = Illuminate\Http\Request::create('/' . \$path, 'POST', [\$key => 1, 'delivered' => 'cancel']);
    \$request->headers->set('Accept', 'application/json');
    \$request->headers->set('X-Requested-With', 'XMLHttpRequest');

    try {
        \$response = \$kernel->handle(\$request);
        \$report['dispatch'][\$uri] = \$response->getStatusCode();
        if (\$response->getStatusCode() !== 403) {
            // The rendered page says only "Server Error" when APP_DEBUG is off, which would
            // leave a failure undiagnosable. Re-dispatch with the exception handler removed so
            // the real throwable surfaces.
            try {
                \$app->instance(
                    Illuminate\Contracts\Debug\ExceptionHandler::class,
                    new class implements Illuminate\Contracts\Debug\ExceptionHandler {
                        public function report(\Throwable \$e) {}
                        public function shouldReport(\Throwable \$e) { return false; }
                        public function render(\$request, \Throwable \$e) { throw \$e; }
                        public function renderForConsole(\$output, \Throwable \$e) { throw \$e; }
                    }
                );
                \$kernel->handle(Illuminate\Http\Request::create('/' . \$path, 'POST', [\$key => 1, 'delivered' => 'cancel']));
                \$report['body'][\$uri] = 'no throwable surfaced';
            } catch (\Throwable \$inner) {
                \$report['body'][\$uri] = get_class(\$inner) . ': ' . \$inner->getMessage();
            }
        }
    } catch (\Throwable \$e) {
        \$report['dispatch'][\$uri] = 'threw: ' . get_class(\$e) . ': ' . \$e->getMessage();
    }
}

echo json_encode(\$report);
PROBE);

exec('cd ' . escapeshellarg($root) . " && {$env} php " . escapeshellarg($probePath) . ' 2>&1', $probeLines, $probeRc);
$report = json_decode((string) end($probeLines), true);
@unlink($probePath);

$out('the probe process booted FROM the route cache', (bool) ($report['cached'] ?? false),
    $report === null ? 'probe output: ' . implode(' | ', $probeLines) : '');

$guard = App\Http\Middleware\ModuleOrderMutationGuard::class;

foreach ($report['routes'] ?? [] as $uri => $info) {
    echo "\n         POST {$uri}\n";
    echo "           action   {$info['action']}\n";
    echo '           declared [' . implode(', ', $info['declared']) . "]\n";
    echo '           resolved [' . implode(', ', array_map('class_basename', $info['resolved'])) . "]\n";
    $out("   {$uri} carries the guard under route:cache", in_array($guard, $info['resolved'], true));
    $out("   {$uri} declares NO Authenticate of its own",
        ! in_array(Illuminate\Auth\Middleware\Authenticate::class, $info['resolved'], true),
        'If this fails the exposure is gone upstream -- good news, but re-read the guard docblock '
        . 'before trusting it.');
}

$out('all six target routes were present in the cached collection',
    count($report['routes'] ?? []) === 6, 'found: ' . count($report['routes'] ?? []));

echo "\nSTEP 5b -- the anonymous POST is REFUSED by the cached-boot process.\n";

foreach (TARGETS as $uri => $key) {
    $status = $report['dispatch'][$uri] ?? 'MISSING';
    $out(sprintf('   POST %-34s -> %s', $uri, var_export($status, true)), $status === 403,
        $status === 403 ? '' : trim(preg_replace('/\s+/', ' ', (string) ($report['body'][$uri] ?? ''))));
}

echo "\nSTEP 6 -- clean up, so nothing is left behind.\n";
exec('cd ' . escapeshellarg($root) . ' && php artisan route:clear 2>&1', $clearLines, $clearRc);
$out('route:clear exited 0', $clearRc === 0);
clearstatcache(true, $cachePath);
$out('bootstrap/cache/routes-v7.php is gone', ! is_file($cachePath));

echo "\n" . ($failed ? "RESULT: FAILED\n" : "RESULT: ALL CHECKS PASSED\n");

exit($failed ? 1 : 0);
