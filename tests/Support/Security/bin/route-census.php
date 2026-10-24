<?php

/**
 * P1-SEC-04 -- the resolved route table WITH EVERY MODULE LOADED, as JSON on stdout.
 *
 * Why a separate process: the test suite is structurally blind to module routes (no WorkDo module loads unless a test inserts an
 * add_on_managers row), and loading modules in-process pollutes the process-global Composer ClassLoader for every later test
 * (PackageServiceProvider::loadAddons adds PSR-4 prefixes; ResidualTenancyExposureTest's count moved from 23 to 21 once). This script
 * boots its own application on an in-memory SQLite database, migrates the host schema, loads all modules through the production loader
 * and prints one row per route+verb. tests/Feature/Security/RouteCensusGateTest runs it and judges the rows.
 *
 * Precondition (stated, not assumed): `php` with pdo_sqlite. It touches no real database and no network; it refuses any
 * non-sqlite-memory connection.
 *
 *   php tests/Support/Security/bin/route-census.php > census.json
 *
 * Row: method, uri, action, module (null for a host route), middleware (class names / aliases as resolved), authenticated (a route-level
 * authentication middleware is present), name.
 */

$root = dirname(__DIR__, 4);

foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array'] as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
    fwrite(STDERR, "REFUSED: this script only runs against sqlite :memory:.\n");
    exit(2);
}

Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);

$modules = array_map('basename', glob($root . '/packages/workdo/*', GLOB_ONLYDIR));
sort($modules);

$provider = new App\Providers\PackageServiceProvider($app);
$loadAddons = new ReflectionMethod($provider, 'loadAddons');
$loadAddons->setAccessible(true);

$failed = [];

foreach ($modules as $module) {
    try {
        $loadAddons->invoke($provider, [$module]);
    } catch (Throwable $e) {
        $failed[] = $module . ': ' . substr($e->getMessage(), 0, 120);
    }
}

$authMiddleware = static function (string $name): bool {
    return in_array($name, ['auth', 'custom.auth'], true)
        || str_starts_with($name, 'auth:')
        || $name === Illuminate\Auth\Middleware\Authenticate::class
        || str_starts_with($name, Illuminate\Auth\Middleware\Authenticate::class . ':')
        || str_ends_with($name, '\\CustomAuth')
        || str_ends_with($name, '\\EnsureCustomerAuthenticated');
};

$rows = [];

foreach (Illuminate\Support\Facades\Route::getRoutes() as $route) {
    $middleware = array_map(
        static fn ($m) => is_string($m) ? $m : (is_object($m) ? get_class($m) : 'closure'),
        app('router')->gatherRouteMiddleware($route)
    );
    $action = $route->getActionName();
    $module = preg_match('/^Workdo\\\\([A-Za-z0-9_]+)\\\\/', $action, $m) === 1 ? $m[1] : null;

    foreach ($route->methods() as $method) {
        if ($method === 'HEAD') {
            continue;
        }

        $rows[] = [
            'method' => $method,
            'uri' => $route->uri(),
            'action' => $action,
            'module' => $module,
            'name' => $route->getName(),
            'authenticated' => count(array_filter($middleware, $authMiddleware)) > 0,
            'middleware' => $middleware,
        ];
    }
}

usort($rows, static fn ($a, $b) => [$a['uri'], $a['method'], $a['action']] <=> [$b['uri'], $b['method'], $b['action']]);

echo json_encode(['modules' => count($modules), 'load_failures' => $failed, 'routes' => $rows], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
