<?php

/**
 * P01-SEC-2 -- ROUTE-CACHE VERIFICATION FOR App\Http\Middleware\OrderDocumentAccessGuard.
 *
 * NOT A TEST, AND DELIBERATELY NOT IN A TESTSUITE. `tests/Support` is not one of the three
 * directories phpunit.xml collects, so this never runs as part of a suite. It cannot be a
 * PHPUnit test: `php artisan route:cache` writes bootstrap/cache/routes-v7.php and the claim
 * under test is about what a LATER, SEPARATE process does with that file, which an in-process
 * assertion cannot observe.
 *
 * THE CLAIM. The guard is registered with `appendToGroup('web', ...)` rather than on the routes
 * it protects, because those routes live in a vendored module route file that CI forbids editing
 * and that is required from `$this->app->booted()` -- after routes/web.php -- so a host
 * re-declaration loses the method+uri slot again. A group-appended middleware is expected to
 * survive `route:cache` because the cache stores declared middleware NAMES unexpanded and the
 * group is resolved per request from bootstrap/app.php. That is an expectation about framework
 * internals, so it is measured here rather than asserted in a comment.
 *
 * NEVER TOUCHES A REAL DATABASE. Pass DB_CONNECTION/DB_DATABASE in the ENVIRONMENT -- Laravel's
 * Dotenv is immutable, so .env cannot win them back. Step 1 asserts the override took effect and
 * aborts before writing anything if it did not.
 *
 *   DB_CONNECTION=sqlite DB_DATABASE=/tmp/rc.sqlite \
 *       php tests/Support/Security/bin/verify-order-document-route-cache.php
 *
 * Exit 0 = every check passed. Exit 1 = a check failed, or the environment was not safe.
 * The transcript of a passing run is docs/security/P01-SEC-2-ROUTE-CACHE-VERIFICATION.md.
 */

$root = dirname(__DIR__, 4);

require $root . '/vendor/autoload.php';

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

echo "\nSTEP 2 -- enable AutomaticOrderPrinting through the production loader's own table.\n";
Illuminate\Support\Facades\Schema::dropIfExists('add_on_managers');
Illuminate\Support\Facades\Schema::create('add_on_managers', function ($table) {
    $table->id();
    $table->string('name')->nullable();
    $table->string('module')->nullable();
    $table->string('image')->nullable();
    $table->decimal('monthly_price', 15, 2)->default(0);
    $table->decimal('yearly_price', 15, 2)->default(0);
    $table->integer('is_enable')->default(1);
    $table->timestamps();
});
Illuminate\Support\Facades\DB::table('add_on_managers')->insert([
    'name' => 'AutomaticOrderPrinting',
    'module' => 'AutomaticOrderPrinting',
    'is_enable' => 1,
]);
$out('add_on_managers holds the row', Illuminate\Support\Facades\DB::table('add_on_managers')
    ->where('module', 'AutomaticOrderPrinting')->exists());

echo "\nSTEP 3 -- php artisan route:cache (a fresh application boot, in its own process).\n";
$cachePath = $root . '/bootstrap/cache/routes-v7.php';
@unlink($cachePath);

$env = 'DB_CONNECTION=' . escapeshellarg('sqlite') . ' DB_DATABASE=' . escapeshellarg($sqlitePath);
exec('cd ' . escapeshellarg($root) . " && {$env} php artisan route:cache 2>&1", $lines, $rc);
echo '         ' . implode("\n         ", $lines) . "\n";
$out('route:cache exited 0', $rc === 0);
$out('bootstrap/cache/routes-v7.php exists', is_file($cachePath));

$cache = (string) @file_get_contents($cachePath);

echo "\nSTEP 4 -- WHAT THE CACHE FILE ITSELF CONTAINS.\n";
$out('the cache does NOT name OrderDocumentAccessGuard', ! str_contains($cache, 'OrderDocumentAccessGuard'),
    'This is the mechanism: the cache stores declared middleware NAMES ("web") unexpanded, so '
    . "the group's members are resolved per request from bootstrap/app.php instead of frozen here.");
$out('the cache DOES name the group "web" as a declared middleware',
    str_contains($cache, '"web"') || str_contains($cache, "'web'"),
    'The declared name is what is frozen; its members are not.');
$out('the cache does NOT name BulkDeleteTenantGuard either', ! str_contains($cache, 'BulkDeleteTenantGuard'),
    'The pre-existing group-appended guard behaves identically, which is why this placement was '
    . 'chosen for the new one.');

echo "\nSTEP 5 -- A SEPARATE PROCESS, booting FROM the cache, resolves the stack.\n";
$probePath = sys_get_temp_dir() . '/p01sec2-route-cache-probe-' . getmypid() . '.php';
file_put_contents($probePath, <<<PROBE
<?php
require '{$root}/vendor/autoload.php';
\$app = require '{$root}/bootstrap/app.php';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\$router = \$app->make('router');
\$report = ['cached' => \$app->routesAreCached(), 'routes' => []];
foreach (\$router->getRoutes() as \$r) {
    if (in_array(\$r->uri(), [
        'shippinglabel/pdf/{id}',
        'shippinglabel/{id?}/{template?}/{color?}',
        'printlabel/{id?}/{template?}/{color?}',
        'invoices/preview/{template}/{color}',
    ], true) && in_array('GET', \$r->methods(), true)) {
        \$report['routes'][\$r->uri()] = [
            'action' => \$r->getActionName(),
            'declared' => array_values(\$r->gatherMiddleware()),
            'resolved' => array_values(\$router->gatherRouteMiddleware(\$r)),
        ];
    }
}
echo json_encode(\$report);
PROBE);

exec('cd ' . escapeshellarg($root) . " && {$env} php " . escapeshellarg($probePath) . ' 2>&1', $probeLines, $probeRc);
$report = json_decode((string) end($probeLines), true);
@unlink($probePath);

$out('the probe process booted FROM the route cache', (bool) ($report['cached'] ?? false),
    $report === null ? 'probe output: ' . implode(' | ', $probeLines) : '');

$guard = App\Http\Middleware\OrderDocumentAccessGuard::class;

foreach ($report['routes'] ?? [] as $uri => $info) {
    echo "\n         GET {$uri}\n";
    echo "           action   {$info['action']}\n";
    echo '           declared [' . implode(', ', $info['declared']) . "]\n";
    echo '           resolved [' . implode(', ', array_map('class_basename', $info['resolved'])) . "]\n";
    $out("   {$uri} carries the guard under route:cache", in_array($guard, $info['resolved'], true));
}

$out('all four target routes were present in the cached collection', count($report['routes'] ?? []) === 4,
    'found: ' . count($report['routes'] ?? []));

echo "\nSTEP 6 -- clean up, so nothing is left behind.\n";
exec('cd ' . escapeshellarg($root) . ' && php artisan route:clear 2>&1', $clearLines, $clearRc);
$out('route:clear exited 0', $clearRc === 0);
clearstatcache(true, $cachePath);
$out('bootstrap/cache/routes-v7.php is gone', ! is_file($cachePath));

echo "\n" . ($failed ? "RESULT: FAILED\n" : "RESULT: ALL CHECKS PASSED\n");

exit($failed ? 1 : 0);
