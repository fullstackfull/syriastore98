<?php

/**
 * GAP-179's POPULATION HAS A HOLE BY CONSTRUCTION. THIS ENUMERATES IT.
 * =================================================================================================
 *
 * GAP-179's surface list is derived from one middleware: `php artisan route:list --json` filtered on
 * `App\Http\Middleware\PlatformOperatorOnly`. That is a perfectly good way to find the OPERATOR's
 * screens and a structurally blind way to find anything else, and the blindness has now been
 * measured twice rather than argued:
 *
 *   `packages/workdo/LynomiaChat`'s MERCHANT pages carry `PlanModuleCheck` + `EnsureStoreOwnsLink`.
 *   Unmeasured. `merchant/commerce-automation.blade.php` held `a.nav-link.active` at 2.41:1 dark
 *   and `.btn-primary` at 2.58:1 dark -- both already repaired elsewhere by the same GAP.
 *
 *   The theme editor carries `theme.editor`. Unmeasured; GAP-113 recorded it as having "zero
 *   rendered evidence, authenticated or otherwise". 14 findings on first capture, of which TWELVE
 *   were `.btn-primary` at 2.58:1 and `badge bg-warning` at 1.68:1 -- the same two classes, at the
 *   same two ratios, as surfaces GAP-179 had already fixed.
 *
 * A CENSUS WHOSE POPULATION COMES FROM ONE MIDDLEWARE CANNOT SEE SURFACES BEHIND ANOTHER. So this
 * script does not look for new findings by looking harder; it replaces the population. It takes the
 * RESOLVED middleware stack of every route -- `gatherRouteMiddleware`, with all 70 modules loaded
 * through the production loader, via `tests/Support/Security/bin/route-census.php` -- and asks which
 * routes a signed-in panel user can GET at all. Then it attributes each route to the Blade files it
 * renders, follows the include graph so partials are attributed to their parents, and counts two
 * defect classes whose repair is already known and already shipped:
 *
 *     HOST PRIMARY FILL   `btn-primary` / `btn btn-sm btn-primary`
 *                         rgb(4,34,42) on rgb(51,93,168) = 2.58:1 in the dark admin theme.
 *                         Repair: `.ss-btn ss-btn--primary` -> 7.92:1 dark / 6.42:1 light.
 *
 *     HOST WARNING CHIP   `badge bg-warning` (with or without `text-dark`)
 *                         rgb(244,180,26) on rgb(253,244,221) = 1.68:1 in LIGHT, passes in dark.
 *                         Repair: `.ss-badge ss-badge--warning`, host utility REMOVED (it carries
 *                         `!important`) -> 8.83:1 light / 9.81:1 dark.
 *
 * WHAT THIS IS AND IS NOT. It is a SOURCE census: it counts occurrences of two class strings in
 * Blade files that a panel GET can reach. It is NOT a contrast measurement -- a class in a
 * `display:none` block or an unrendered branch paints nothing, which this project has measured in
 * both directions (`setting/index.blade.php:283` produced byte-identical captures). So every figure
 * here is an UPPER BOUND on findings and a count of exposure, and the script says so in its own
 * output rather than leaving a reader to assume otherwise. The live harnesses
 * (`measure-superadmin-premium-live.cjs`, `measure-lynomia-merchant-live.cjs`) remain the
 * instrument for ratios.
 *
 * Blade comments are stripped before counting. A census that reads comments can be satisfied -- or
 * broken -- by prose about the thing it measures, which this repository has hit eight times; the
 * repairs this slice shipped all carry comments naming the classes they removed.
 *
 *   php tests/Support/bin/census-host-utility-surfaces.php            # human-readable
 *   php tests/Support/bin/census-host-utility-surfaces.php --json     # machine-readable
 *
 * Exit 0 always: this is a measuring instrument, not a gate. `tests/Unit/Ui/HostUtilityCensusTest`
 * is the gate and holds the declared baseline.
 */

$root = dirname(__DIR__, 3);
$json = in_array('--json', $argv, true);

// -------------------------------------------------------------------------------------------------
// 1. the resolved route table, with every module loaded
// -------------------------------------------------------------------------------------------------

$censusScript = $root . '/tests/Support/Security/bin/route-census.php';

if (! is_file($censusScript)) {
    fwrite(STDERR, "missing {$censusScript}\n");
    exit(2);
}

$raw = shell_exec('php ' . escapeshellarg($censusScript) . ' 2>/dev/null');
$census = json_decode((string) $raw, true);

if (! is_array($census) || ! isset($census['routes'])) {
    fwrite(STDERR, "route-census.php produced no usable JSON\n");
    exit(2);
}

$routes = $census['routes'];

/**
 * THE TWO POPULATIONS.
 *
 * OLD -- GAP-179's own filter, reproduced rather than taken on trust. The register states "37
 * routes, 19 GET" for it; this reproduces the 19 independently, which is what makes the comparison
 * below a measurement rather than a rhetorical one.
 *
 * NEW -- every route a signed-in panel user can GET: authenticated, not under `api/`, and not a
 * storefront route. `authenticated` comes from route-census.php's own predicate (a route-level
 * authentication middleware is present), so the two scripts cannot drift on what "signed in" means.
 */
$isGet = static fn (array $r): bool => $r['method'] === 'GET';
$notApi = static fn (array $r): bool => ! str_starts_with($r['uri'], 'api/');
$hasOperatorOnly = static fn (array $r): bool => (bool) array_filter(
    $r['middleware'],
    static fn ($m) => str_contains((string) $m, 'PlatformOperatorOnly')
);

$oldPopulation = array_values(array_filter($routes, static fn ($r) => $isGet($r) && $notApi($r) && $hasOperatorOnly($r)));
$newPopulation = array_values(array_filter($routes, static fn ($r) => $isGet($r) && $notApi($r) && $r['authenticated']));

/*
 * THE OLD POPULATION BY NAME, NOT ONLY BY COUNT.
 *
 * A COUNT CANNOT TELL A ROUTE LEAVING THE OPERATOR GATE FROM ANOTHER ONE JOINING IT, and that is
 * not hypothetical: the population went 19 -> 26 between 2026-11-26 and 2026-11-27 as five Phase 06
 * operator screens landed (`platform/verification`, `platform/approvals`, `platform/incidents`,
 * `platform/operations`, `platform/stores`, two of them with a parameterised sibling), and the only
 * signal `HostUtilityCensusPopulationTest` could give was `26 !== 19`. Worse, nineteen routes
 * swapped for nineteen different ones would have passed. Emitted so the test can assert the SET.
 *
 * Two of the routes carry no name at all (`zid/{storeId}/orders`, `zid/{storeId}/webhooks`), so the
 * identifier falls back to the uri rather than to an empty string that would collide.
 *
 * ONE ENTRY PER ROUTE ROW, NOT DEDUPLICATED, so that `count(old_population_route_names)` is always
 * `old_population_routes`. Deduplicating would let two rows sharing one name read as a route that
 * had LEFT the population, which is the reading this list exists to make reliable; the test asserts
 * the two agree.
 */
$routeIdentity = static fn (array $r): string => ($r['name'] ?? '') !== ''
    ? (string) $r['name']
    : 'GET ' . $r['uri'];

$oldPopulationNames = array_map($routeIdentity, $oldPopulation);
sort($oldPopulationNames);

// -------------------------------------------------------------------------------------------------
// 2. route -> view attribution
// -------------------------------------------------------------------------------------------------

/**
 * The views a controller method names, by brace-matched extraction of its body.
 *
 * NOT a PHP parser, and the limitation is reported rather than hidden: a method that picks its view
 * name at runtime, or renders through a helper, resolves to nothing here and is counted in
 * `routes_unresolved`. A census that quietly dropped those would understate the population it is
 * trying to widen, which is the exact failure mode it exists to fix.
 */
function viewsNamedBy(string $root, string $action, array &$fileCache): array
{
    if (! str_contains($action, '@')) {
        return [];
    }

    [$class, $method] = explode('@', $action, 2);

    $relative = null;

    if (preg_match('/^App\\\\(.*)$/', $class, $m) === 1) {
        $relative = 'app/' . str_replace('\\', '/', $m[1]) . '.php';
    } elseif (preg_match('/^Workdo\\\\([A-Za-z0-9_]+)\\\\(.*)$/', $class, $m) === 1) {
        $relative = 'packages/workdo/' . $m[1] . '/src/' . str_replace('\\', '/', $m[2]) . '.php';
    }

    if ($relative === null) {
        return [];
    }

    $path = $root . '/' . $relative;

    if (! isset($fileCache[$path])) {
        $fileCache[$path] = is_file($path) ? (string) file_get_contents($path) : '';
    }

    $source = $fileCache[$path];

    if ($source === '') {
        return [];
    }

    $at = null;
    if (preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $source, $m, PREG_OFFSET_CAPTURE) === 1) {
        $at = $m[0][1];
    }

    if ($at === null) {
        return [];
    }

    // Brace-match the body from the first `{` after the signature.
    $open = strpos($source, '{', $at);
    if ($open === false) {
        return [];
    }

    $depth = 0;
    $end = strlen($source);
    for ($i = $open; $i < $end; $i++) {
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                $end = $i;
                break;
            }
        }
    }

    $body = substr($source, $open, $end - $open);

    preg_match_all('/\bview\s*\(\s*[\'"]([A-Za-z0-9_.:\-\/]+)[\'"]/', $body, $hits);

    return array_values(array_unique($hits[1]));
}

/** A view name -> the file on disk, honouring the module `namespace::dotted.path` form. */
function viewFile(string $root, string $name): ?string
{
    /**
     * THE NAMESPACE MAP IS READ FROM EACH MODULE'S OWN `loadViewsFrom()`, NOT GUESSED.
     *
     * The first version of this function derived the namespace by kebab-casing the directory name.
     * That is right for most modules and wrong for enough of them to matter: it resolved 292 of 853
     * routes while 499 of them name a literal view, so roughly 207 literals were being dropped --
     * a 40% hole in the very census that exists because another census had a hole. `Api` registers
     * `api`, `HubSpot` registers `hub-spot`, and a module whose provider registers something else
     * again would have been silently invisible.
     *
     * Reading the providers gives 69 namespaces and cannot drift from what Laravel actually
     * resolves, because it is the same call site.
     */
    static $namespaceDirs = null;

    if ($namespaceDirs === null) {
        $namespaceDirs = [];

        /*
         * THE PATH IS RESOLVED FROM THE PROVIDER'S OWN `__DIR__` EXPRESSION, not assumed to be
         * `src/resources/views`. Two earlier versions of this got it wrong in ways that each hid
         * about a hundred routes, and both were found by counting what did NOT resolve rather than
         * by reading the code again:
         *
         *   kebab-casing the directory name  -- `Api` registers `api`, `HubSpot` registers
         *                                       `hub-spot`; close, but not a rule.
         *   globbing `src/app/Providers/*`   -- `LandingPage`'s provider is at `src/Providers/`
         *                                       and its views at `src/Resources/views` (capital
         *                                       R). 97 routes named `landing-page::` and resolved
         *                                       to nothing.
         *
         * Reading `loadViewsFrom(__DIR__ . '<relative>', '<ns>')` and resolving that relative path
         * against the provider's directory is the same computation Laravel performs, so it cannot
         * drift from what the framework resolves.
         */
        foreach (glob($root . '/packages/workdo/*/src/{,*/,*/*/}Providers/*.php', GLOB_BRACE) ?: [] as $provider) {
            $src = (string) file_get_contents($provider);

            preg_match_all(
                "#loadViewsFrom\\(\\s*__DIR__\\s*\\.\\s*'([^']+)'\\s*,\\s*'([^']+)'\\s*\\)#",
                $src,
                $hits,
                PREG_SET_ORDER
            );

            foreach ($hits as $hit) {
                $dir = realpath(dirname($provider) . '/' . $hit[1]);

                if ($dir !== false && is_dir($dir)) {
                    $namespaceDirs[strtolower($hit[2])] = $dir;
                }
            }
        }
    }

    if (str_contains($name, '::')) {
        [$ns, $rest] = explode('::', $name, 2);
        $base = $namespaceDirs[strtolower($ns)] ?? null;

        if ($base === null) {
            return null;
        }

        $candidate = $base . '/' . str_replace('.', '/', $rest) . '.blade.php';

        return is_file($candidate) ? $candidate : null;
    }

    foreach ([$root . '/resources/views'] as $base) {
        $candidate = $base . '/' . str_replace('.', '/', $name) . '.blade.php';

        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * Transitively add every view a view pulls in.
 *
 * `_version-panel.blade.php` is the reason this exists: it is reached only through
 * `@includeWhen(...)` from `customize.blade.php`, and it held one of this slice's measured
 * findings. A census that attributed only controller-named views would have called it unreachable.
 */
function expandIncludes(string $root, string $file, array &$seen): void
{
    if (isset($seen[$file])) {
        return;
    }

    $seen[$file] = true;
    $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($file));

    preg_match_all(
        '/@(?:include|includeIf|includeWhen|includeUnless|includeFirst|extends|each)\s*\(\s*[\'"]([A-Za-z0-9_.:\-\/]+)[\'"]/',
        $source,
        $hits
    );
    preg_match_all('/@includeWhen\s*\([^,]+,\s*[\'"]([A-Za-z0-9_.:\-\/]+)[\'"]/', $source, $when);

    foreach (array_merge($hits[1], $when[1]) as $name) {
        $child = viewFile($root, $name);

        if ($child !== null) {
            expandIncludes($root, $child, $seen);
        }
    }
}

/**
 * WHY A ROUTE RESOLVED TO NO VIEW, classified rather than left as a residue.
 *
 * The first run of this census reported "561 routes named no view" out of 853 and that number was
 * unreadable: a reader cannot tell a resolver bug from a route that renders nothing by design, and
 * an unexplained two-thirds residue is exactly the shape of the hole this script exists to close.
 * Two resolver bugs really were hiding in it (see `viewFile`). What is left is classified, so the
 * population's coverage can be judged instead of trusted.
 */
function whyUnresolved(string $root, string $action, array &$fileCache): string
{
    if (! str_contains($action, '@')) {
        return 'closure or invokable';
    }

    [$class, $method] = explode('@', $action, 2);
    $relative = null;

    if (preg_match('/^App\\\\(.*)$/', $class, $m) === 1) {
        $relative = 'app/' . str_replace('\\', '/', $m[1]) . '.php';
    } elseif (preg_match('/^Workdo\\\\([A-Za-z0-9_]+)\\\\(.*)$/', $class, $m) === 1) {
        $relative = 'packages/workdo/' . $m[1] . '/src/' . str_replace('\\', '/', $m[2]) . '.php';
    }

    if ($relative === null || ! is_file($root . '/' . $relative)) {
        return 'controller file not found';
    }

    $source = $fileCache[$root . '/' . $relative] ?? (string) file_get_contents($root . '/' . $relative);

    if (preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $source) !== 1) {
        return 'method inherited, from a trait, or magic';
    }

    $named = viewsNamedBy($root, $action, $fileCache);

    if ($named !== []) {
        return 'names a view whose file does not exist (resource-controller scaffolding)';
    }

    return 'renders no view (redirect, response, json, or a stub)';
}

function viewSetFor(string $root, array $population, array &$fileCache, int &$unresolved, array &$why): array
{
    $seen = [];

    foreach ($population as $route) {
        $named = viewsNamedBy($root, (string) $route['action'], $fileCache);
        $resolvedAny = false;

        foreach ($named as $name) {
            $file = viewFile($root, $name);

            if ($file !== null) {
                $resolvedAny = true;
                expandIncludes($root, $file, $seen);
            }
        }

        if (! $resolvedAny) {
            $unresolved++;
            $reason = whyUnresolved($root, (string) $route['action'], $fileCache);
            $why[$reason] = ($why[$reason] ?? 0) + 1;
        }
    }

    return $seen;
}

$fileCache = [];
$oldUnresolved = 0;
$newUnresolved = 0;
$oldWhy = [];
$newWhy = [];
$oldViews = viewSetFor($root, $oldPopulation, $fileCache, $oldUnresolved, $oldWhy);
$newViews = viewSetFor($root, $newPopulation, $fileCache, $newUnresolved, $newWhy);
arsort($newWhy);

// -------------------------------------------------------------------------------------------------
// 3. the two defect classes
// -------------------------------------------------------------------------------------------------

/**
 * WORD-BOUNDED ON THE CLASS NAME, which is not pedantry: `btn-outline-primary` must NOT count. It
 * is a different control that MEASURED 6.42:1 light / 4.65:1 dark -- a pass -- and counting it
 * would inflate the number this census exists to give the owner.
 */
const CLASSES = [
    'host_primary_fill' => '/class="[^"]*(?<![-a-z])btn-primary(?![-a-z])[^"]*"/',
    'host_warning_chip' => '/class="[^"]*(?<![-a-z])bg-warning(?![-a-z])[^"]*"/',
];

function countClasses(string $file): array
{
    $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($file));
    $out = [];

    foreach (CLASSES as $key => $pattern) {
        $out[$key] = preg_match_all($pattern, $source);
    }

    return $out;
}

$findings = [];

foreach (array_keys($newViews) as $file) {
    $counts = countClasses($file);

    if (array_sum($counts) === 0) {
        continue;
    }

    $findings[] = [
        'view' => ltrim(str_replace($root, '', $file), '/'),
        'in_old_population' => isset($oldViews[$file]),
        'host_primary_fill' => $counts['host_primary_fill'],
        'host_warning_chip' => $counts['host_warning_chip'],
    ];
}

usort($findings, static fn ($a, $b) => [$b['host_primary_fill'] + $b['host_warning_chip'], $a['view']]
    <=> [$a['host_primary_fill'] + $a['host_warning_chip'], $b['view']]);

$sum = static function (array $rows, string $key, ?bool $inOld = null): int {
    $total = 0;

    foreach ($rows as $row) {
        if ($inOld !== null && $row['in_old_population'] !== $inOld) {
            continue;
        }

        $total += $row[$key];
    }

    return $total;
};

$report = [
    'routes_total_rows' => count($routes),
    'old_population_routes' => count($oldPopulation),
    'old_population_route_names' => $oldPopulationNames,
    'new_population_routes' => count($newPopulation),
    'old_population_views' => count($oldViews),
    'new_population_views' => count($newViews),
    'old_population_routes_unresolved' => $oldUnresolved,
    'new_population_routes_unresolved' => $newUnresolved,
    'views_with_findings' => count($findings),
    'views_with_findings_outside_old_population' => count(array_filter($findings, static fn ($f) => ! $f['in_old_population'])),
    'host_primary_fill_total' => $sum($findings, 'host_primary_fill'),
    'host_primary_fill_outside_old_population' => $sum($findings, 'host_primary_fill', false),
    'host_warning_chip_total' => $sum($findings, 'host_warning_chip'),
    'host_warning_chip_outside_old_population' => $sum($findings, 'host_warning_chip', false),
    'new_population_unresolved_reasons' => $newWhy,
    'findings' => $findings,
];

if ($json) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    exit(0);
}

printf("resolved route rows, all 70 modules loaded      %d\n", $report['routes_total_rows']);
printf("\nPOPULATIONS (GET, not api/)\n");
printf("  OLD  GAP-179: + PlatformOperatorOnly          %4d routes -> %4d views (%d routes named no view)\n",
    $report['old_population_routes'], $report['old_population_views'], $report['old_population_routes_unresolved']);
printf("  NEW  every authenticated panel GET            %4d routes -> %4d views (%d routes named no view)\n",
    $report['new_population_routes'], $report['new_population_views'], $report['new_population_routes_unresolved']);
printf("  the old population is %.1f%% of the new one by routes\n",
    100 * $report['old_population_routes'] / max(1, $report['new_population_routes']));

printf("\n  why the %d unresolved routes in the new population name no view:\n", $report['new_population_routes_unresolved']);
foreach ($newWhy as $reason => $n) {
    printf("      %4d  %s\n", $n, $reason);
}

printf("\nTWO ALREADY-REPAIRED DEFECT CLASSES, counted in the NEW population\n");
printf("  host primary fill  btn-primary       %4d occurrences, %4d of them OUTSIDE the old population\n",
    $report['host_primary_fill_total'], $report['host_primary_fill_outside_old_population']);
printf("  host warning chip  badge bg-warning  %4d occurrences, %4d of them OUTSIDE the old population\n",
    $report['host_warning_chip_total'], $report['host_warning_chip_outside_old_population']);
printf("  views affected                       %4d, of which %4d are outside the old population\n",
    $report['views_with_findings'], $report['views_with_findings_outside_old_population']);

printf("\nTHESE ARE SOURCE OCCURRENCES, NOT MEASURED RATIOS -- an upper bound on findings. A class in\n");
printf("a display:none block or an unrendered branch paints nothing; measured in this repository in\n");
printf("both directions. Use the live harnesses for ratios.\n");

printf("\nTOP VIEWS (o = reachable from the old population too)\n");
foreach (array_slice($findings, 0, 25) as $f) {
    printf("  %s %3d %3d  %s\n", $f['in_old_population'] ? 'o' : ' ',
        $f['host_primary_fill'], $f['host_warning_chip'], $f['view']);
}
