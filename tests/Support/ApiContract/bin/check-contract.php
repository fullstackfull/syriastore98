#!/usr/bin/env php
<?php

/**
 * Cross-repo API contract check: does every endpoint the shipped Flutter admin app
 * calls still resolve to a route this server registers?
 *
 * RUNS WITH NOTHING BUT `php`. No Composer autoloader, no vendor/, no database, no
 * network. That is the whole point: `composer install` cannot complete on a stock
 * runner because three dependencies are served from hosts the build network does not
 * reach (see the Phase 1B Agent 5 report), so a contract check that needed vendor/
 * would never run. This one runs today.
 *
 * USAGE
 *   php tests/Support/ApiContract/bin/check-contract.php [--app-repo=PATH] [--json]
 *
 *   --app-repo=PATH  Path to the StoreSyria_app98 Flutter checkout. When omitted, the
 *                    committed manifest at manifests/flutter-endpoints.json is used, so
 *                    the check still works in a server-only CI job.
 *   --json           Emit the full result as JSON instead of a human report.
 *
 * EXIT CODES
 *   0  every app endpoint resolves
 *   1  at least one app endpoint has no server route  (CONTRACT BROKEN)
 *   2  the check could not run (missing input)
 */

declare(strict_types=1);

$root = dirname(__DIR__, 4);

require __DIR__ . '/../FlutterEndpointExtractor.php';
require __DIR__ . '/../RouteFileParser.php';
require __DIR__ . '/../ContractChecker.php';

use Tests\Support\ApiContract\ContractChecker;
use Tests\Support\ApiContract\FlutterEndpointExtractor;
use Tests\Support\ApiContract\RouteFileParser;

$options  = getopt('', ['app-repo::', 'json', 'help']) ?: [];
$asJson   = array_key_exists('json', $options);
$manifest = __DIR__ . '/../manifests/flutter-endpoints.json';

if (array_key_exists('help', $options)) {
    fwrite(STDOUT, "See the header of this file.\n");
    exit(0);
}

/** Fatal, with a reason. Never a silent pass. */
$abort = static function (string $why): never {
    fwrite(STDERR, "API CONTRACT CHECK COULD NOT RUN: {$why}\n");
    exit(2);
};

// ---------------------------------------------------------------- app endpoints
$appRepo = $options['app-repo'] ?? null;
$dart    = null;

if (is_string($appRepo) && $appRepo !== '') {
    $dartPath = rtrim($appRepo, '/') . '/lib/utils/base_api.dart';

    if (!is_readable($dartPath)) {
        $abort("--app-repo was given but {$dartPath} is not readable.");
    }

    $dart = (string) file_get_contents($dartPath);
}

if ($dart !== null) {
    $extracted = FlutterEndpointExtractor::extract($dart);
    $source    = 'live Dart source';
} else {
    if (!is_readable($manifest)) {
        $abort("no --app-repo given and the committed manifest {$manifest} is missing.");
    }

    $decoded = json_decode((string) file_get_contents($manifest), true);

    if (!is_array($decoded) || !isset($decoded['base_path'], $decoded['endpoints'])) {
        $abort("the committed manifest {$manifest} is not a valid endpoint manifest.");
    }

    $extracted = $decoded;
    $source    = 'committed manifest';
}

// ---------------------------------------------------------------- server routes
$routeFile = $root . '/routes/api.php';

if (!is_readable($routeFile)) {
    $abort("{$routeFile} is not readable, so no server route set can be derived.");
}

$parsed     = RouteFileParser::parse((string) file_get_contents($routeFile));
$serverUris = array_values(array_unique(array_map(
    static fn (array $r): string => $r['uri'],
    $parsed['routes']
)));

// Module route files register further API routes (for example the LandingPage addon
// registers /api/landingpage). They are added so a module-served endpoint is not
// reported as missing.
//
// BOTH CASINGS, AND THE LOWERCASE ONE IS THE COMMON CASE. This glob matched only
// `src/Routes/api.php` -- capital R -- which on a case-sensitive filesystem is exactly
// ONE module (LandingPage). The other 68 module route files are at `src/routes/api.php`
// and were invisible to this check, so a module-served endpoint the app calls would have
// been reported as missing from the server. Harmless while they are all vendor stubs;
// wrong the moment one is not. Same class of defect as the mis-cased
// `loadMigrationsFrom()` path that stranded six migrations and 500'd /login (register
// row C23): a path that resolves on a developer's macOS checkout and resolves to nothing
// on Linux. array_unique() because on a case-INSENSITIVE filesystem both globs return
// the same files.
$moduleRouteFiles = array_unique(array_merge(
    glob($root . '/packages/workdo/*/src/routes/api.php') ?: [],
    glob($root . '/packages/workdo/*/src/Routes/api.php') ?: []
));

foreach ($moduleRouteFiles as $moduleRoutes) {
    $moduleParsed = RouteFileParser::parse((string) file_get_contents($moduleRoutes));

    foreach ($moduleParsed['routes'] as $r) {
        $serverUris[] = $r['uri'];
    }
}

$serverUris = array_values(array_unique($serverUris));

$result = ContractChecker::check($extracted['base_path'], $extracted['endpoints'], $serverUris);

// ---------------------------------------------------------------- report
if ($asJson) {
    fwrite(STDOUT, json_encode([
        'source'      => $source,
        'base_path'   => $extracted['base_path'],
        'unsupported' => $parsed['unsupported'],
        'result'      => $result,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

    exit($result['missing'] === [] ? 0 : 1);
}

$c = $result['counts'];

fwrite(STDOUT, "API CONTRACT CHECK  (app endpoints from {$source})\n");
fwrite(STDOUT, str_repeat('-', 70) . "\n");
fwrite(STDOUT, sprintf("app base path        : %s\n", $extracted['base_path']));
fwrite(STDOUT, sprintf("app endpoints        : %d\n", $c['app_endpoints']));
$pathId = count(array_filter($result['matched'], static fn (array $m): bool => $m['kind'] === 'path-id'));

fwrite(STDOUT, sprintf("  resolved           : %d\n", $c['matched']));
fwrite(STDOUT, sprintf("    exact match      : %d\n", $c['matched'] - $pathId));
fwrite(STDOUT, sprintf("    trailing path id : %d (app appends a record id)\n", $pathId));
fwrite(STDOUT, sprintf("  commented out      : %d (not called by the app)\n", $c['skipped']));
fwrite(STDOUT, sprintf("  UNRESOLVED         : %d\n", $c['missing']));
fwrite(STDOUT, sprintf("server routes parsed : %d\n", $c['server_routes']));

if ($parsed['unsupported'] !== []) {
    fwrite(STDOUT, "\nROUTE CONSTRUCTS THIS PARSER DOES NOT MODEL (coverage gap, not a failure):\n");

    foreach ($parsed['unsupported'] as $u) {
        fwrite(STDOUT, sprintf("  routes/api.php:%d  %s\n", $u['line'], $u['snippet']));
    }
}

if ($result['missing'] !== []) {
    fwrite(STDOUT, "\n" . str_repeat('=', 70) . "\n");
    fwrite(STDOUT, "CONTRACT BROKEN. The shipped app calls endpoints this server does not\n");
    fwrite(STDOUT, "register. Every installed copy of the app gets a 404 on these:\n\n");

    foreach ($result['missing'] as $m) {
        fwrite(STDOUT, sprintf("  API.%-28s -> %s\n", $m['field'], $m['full']));
    }

    fwrite(STDOUT, "\nIf an endpoint was renamed, keep the old path as an alias to the new\n");
    fwrite(STDOUT, "handler. Do NOT just update base_api.dart: phones already have the old\n");
    fwrite(STDOUT, "binary, and a store update does not reach them retroactively.\n");
    fwrite(STDOUT, str_repeat('=', 70) . "\n");

    exit(1);
}

fwrite(STDOUT, "\nOK: every endpoint the app calls resolves to a registered route.\n");

exit(0);
