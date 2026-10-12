#!/usr/bin/env php
<?php

/**
 * Fails when an /api/admin/* route carries no authentication middleware.
 *
 * WHY THIS EXISTS, with evidence. On the Phase 1A baseline (b4917c5b), 12 of the 124
 * /api/admin/* routes carry no auth middleware at all. Agent 5 confirmed empirically, in
 * a real PHPUnit run against that baseline, that this is not theoretical:
 *
 *   GET  /api/admin/categorylist?store_id=1   -> 200 {"status":1,"message":"successfull"}
 *   GET  /api/admin/couponlist?store_id=1     -> 200 {"status":1,"message":"successfull"}
 *   GET  /api/admin/taxlist?store_id=1        -> 200 {"status":1,"message":"successfull"}
 *   GET  /api/admin/shippinglist?store_id=1   -> 200 {"status":1,"message":"successfull"}
 *   GET  /api/admin/variantlist?store_id=1    -> 200 {"status":1,"message":"successfull"}
 *   POST /api/admin/orders/notes/create       -> 200, and order_notes went 0 rows -> 1
 *
 * all with NO credentials of any kind. The last one is an anonymous WRITE of a
 * `to_customer` order note.
 *
 * The baseline is recorded in manifests/admin-auth-coverage-baseline.json. This check
 * fails if any route NOT in that baseline appears without auth middleware -- so the
 * known gap does not block the build while Agent 1 repairs it, but no NEW unprotected
 * admin route can be added without the build going red. As routes are fixed, remove them
 * from the baseline; the check then also fails if a fixed route regresses.
 *
 * RUNS WITH NOTHING BUT `php`. No vendor/, no database, no network.
 *
 * USAGE
 *   php tests/Support/ApiContract/bin/check-auth-coverage.php [--update-baseline] [--json]
 *
 * EXIT CODES
 *   0  no unprotected admin route outside the recorded baseline
 *   1  at least one new or regressed unprotected admin route
 *   2  the check could not run
 */

declare(strict_types=1);

require __DIR__ . '/../RouteFileParser.php';

use Tests\Support\ApiContract\RouteFileParser;

$root     = dirname(__DIR__, 4);
$options  = getopt('', ['update-baseline', 'json']) ?: [];
$baseline = __DIR__ . '/../manifests/admin-auth-coverage-baseline.json';

/**
 * Middleware names that constitute an authentication check on this codebase.
 * `AdminApiLog` and `APILog` are LOGGING middleware and deliberately excluded -- a route
 * that only logs is not a route that authenticates, and conflating the two is exactly
 * how these 12 routes came to look protected.
 */
$authMiddleware = ['custom.auth', 'auth', 'auth:api', 'auth:sanctum', 'auth.basic', 'CustomAuth'];

$routeFile = $root . '/routes/api.php';

if (!is_readable($routeFile)) {
    fwrite(STDERR, "AUTH COVERAGE CHECK COULD NOT RUN: {$routeFile} is not readable.\n");
    exit(2);
}

$parsed = RouteFileParser::parse((string) file_get_contents($routeFile));

$isAuth = static function (array $middleware) use ($authMiddleware): bool {
    foreach ($middleware as $name) {
        foreach ($authMiddleware as $auth) {
            if (strcasecmp($name, $auth) === 0 || str_starts_with(strtolower($name), 'auth:')) {
                return true;
            }
        }
    }

    return false;
};

$unprotected = [];

foreach ($parsed['routes'] as $route) {
    if (!str_starts_with($route['uri'], '/api/admin/')) {
        continue;
    }

    if ($isAuth($route['middleware'])) {
        continue;
    }

    $unprotected[$route['uri']] = [
        'uri'        => $route['uri'],
        'methods'    => $route['methods'],
        'line'       => $route['line'],
        'middleware' => $route['middleware'],
    ];
}

ksort($unprotected);

if (array_key_exists('update-baseline', $options)) {
    file_put_contents($baseline, json_encode([
        '_comment' => 'Recorded baseline of /api/admin/* routes with NO authentication '
            . 'middleware. Shrink this list as Agent 1 repairs each route; never grow it. '
            . 'check-auth-coverage.php fails on anything not listed here.',
        '_source'  => 'routes/api.php',
        '_warning' => 'Every entry here is an endpoint reachable with no credentials.',
        'count'    => count($unprotected),
        'routes'   => array_keys($unprotected),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

    fwrite(STDOUT, sprintf("Baseline updated: %d unprotected admin routes recorded.\n", count($unprotected)));
    exit(0);
}

$known = [];

if (is_readable($baseline)) {
    $decoded = json_decode((string) file_get_contents($baseline), true);
    $known   = is_array($decoded['routes'] ?? null) ? $decoded['routes'] : [];
}

$new = array_values(array_diff(array_keys($unprotected), $known));
$fixed = array_values(array_diff($known, array_keys($unprotected)));

if (array_key_exists('json', $options)) {
    fwrite(STDOUT, json_encode([
        'unprotected' => array_values($unprotected),
        'baseline'    => $known,
        'new'         => $new,
        'now_fixed'   => $fixed,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

    exit($new === [] ? 0 : 1);
}

fwrite(STDOUT, "ADMIN API AUTH COVERAGE\n");
fwrite(STDOUT, str_repeat('-', 70) . "\n");
fwrite(STDOUT, sprintf("/api/admin/* routes with no auth middleware : %d\n", count($unprotected)));
fwrite(STDOUT, sprintf("recorded in baseline                       : %d\n", count($known)));

if ($fixed !== []) {
    fwrite(STDOUT, "\nNOW PROTECTED (remove these from the baseline):\n");

    foreach ($fixed as $uri) {
        fwrite(STDOUT, "  {$uri}\n");
    }
}

if ($new !== []) {
    fwrite(STDOUT, "\n" . str_repeat('=', 70) . "\n");
    fwrite(STDOUT, "NEW UNPROTECTED ADMIN ROUTE(S). These are reachable with no\n");
    fwrite(STDOUT, "credentials and are not in the recorded baseline:\n\n");

    foreach ($new as $uri) {
        $r = $unprotected[$uri];
        fwrite(STDOUT, sprintf(
            "  %-7s %-45s routes/api.php:%d  middleware=%s\n",
            implode('/', $r['methods']),
            $uri,
            $r['line'],
            $r['middleware'] === [] ? '(none)' : implode(',', $r['middleware'])
        ));
    }

    fwrite(STDOUT, "\nNote: AdminApiLog and APILog are LOGGING middleware. A route that\n");
    fwrite(STDOUT, "only logs is not a route that authenticates.\n");
    fwrite(STDOUT, str_repeat('=', 70) . "\n");

    exit(1);
}

fwrite(STDOUT, "\nOK: no unprotected admin route outside the recorded baseline.\n");

exit(0);
