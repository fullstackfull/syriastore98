#!/usr/bin/env php
<?php

/**
 * HOW MANY REGISTERED ROUTES REALLY NAME A VIEW THAT DOES NOT EXIST?
 * =================================================================================================
 *
 * WHY THIS SCRIPT EXISTS. A census of this shape was reported as finding ~151 authenticated routes
 * whose view is missing, and the first question about a number that large is how much of it is the
 * census's own artefact. This reproduces the funnel step by step so the answer is arithmetic rather
 * than assertion. AT THIS HEAD IT ENDS AT ONE route that is not already recorded.
 *
 * THE FUNNEL, every step measured (figures from this script plus the drive step below):
 *
 *     113   route+view pairs whose view is absent at `resources/views/<dotted>.blade.php`
 *            -- a FILE-PATH check, which is how such a census is usually built
 *      65   of those RESCUED by `view()->exists()`: the view resolves through a registered
 *            module namespace or an extra view path. 58% of the path-based count is artefact,
 *            and this is the single largest source of inflation.
 *      48   resolver-confirmed absent
 *       8   of those never reached: the action refuses first -- 5 redirect to `/` (a guard's
 *            `redirect()->back()`) and 3 to `/dashboard` (the module/permission gate). CLAUDE.md
 *            warns that an unreachable module answers 200 on the dashboard, which a status-code
 *            assertion photographs as the screen; here it also means the missing view is never
 *            rendered, so the route does not 500.
 *      40   genuinely answer HTTP 500 for a verified, provisioned merchant
 *      37   of those are the VENDORED `Workdo\LandingPage` module -- `landing-page::create`,
 *            `::show`, `::edit` and friends, which that package simply does not ship (it has
 *            `landingpage/footer.blade.php`, its controllers ask for
 *            `landingpage.footer.index`). Real, but `packages/workdo/` is not ours to edit.
 *       3   first-party
 *       2   of those ALREADY RECORDED and deliberately unfixed as owner-scope:
 *            `currency-rates` (KnownUnrenderableRoutesTest) and `otp-integration`
 *            (OtpOutboundHardeningTest, P1-SEC-55 §4.2).
 *       1   NOT PREVIOUSLY RECORDED: `POST products/sku_combination`.
 *
 * I COULD NOT REPRODUCE 151 UNDER ANY FRAMING I TRIED, and say so rather than round towards it:
 * path-based pairs 113, distinct routes 113, auth-only 109, and 187 if the 74 routes naming a
 * missing METHOD are added in (that is a different family, carried separately). The nearest
 * framings are 113 and 109. This HEAD is behind the tree the figure came from, so the route set
 * may genuinely differ -- but the artefact ratio is the transferable result, not the total.
 *
 * -------------------------------------------------------------------------------------------------
 * THE ONE NEW FINDING, AND WHY IT WAS MISSED BEFORE. `POST products/sku_combination` ->
 * `ProductController::sku_combination()` returns `view('product.sku_combinations')`, which does not
 * exist. Measured as a verified provisioned merchant: HTTP 500,
 * `InvalidArgumentException: View [product.sku_combinations] not found.`, and 0 references to
 * `sku_combination` anywhere in `resources/` or `public/`.
 *
 * IT WAS EXONERATED BY A CENSUS THAT ASKED THE WRONG QUESTION. `routes/web.php` records, in the
 * comment that removed this route's PUT sibling as P1-INV-10 (b):
 *
 *     "The POST sibling `products.sku_combination` -> `sku_combination()` is left alone: its
 *      method exists, so it does not 500"
 *
 * The method does exist. The VIEW does not, so it 500s anyway. That claim is the reason the route
 * was passed over, and it is false -- which is the same failure mode as the 65 rescues above, in
 * reverse: a question answered about the wrong object. The route itself is NOT changed here (see
 * the test).
 *
 * PRECONDITIONS: run from the repository root; needs `vendor/` and no database. The DRIVE step is
 * not re-run here because it needs an authenticated session and a migrated schema -- its figures
 * are recorded above and the one route they single out is pinned by
 * `Tests\Feature\Security\KnownUnrenderableRoutesTest`.
 *
 *     php tests/Support/Security/bin/census-routes-naming-missing-views.php
 */

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

/** Comments stripped with PHP's own tokenizer: a docblock quoting `view('x')` is not a call. */
function codeOnly(string $path): string
{
    $code = '';
    foreach (token_get_all((string) @file_get_contents($path)) as $t) {
        if (! is_array($t)) { $code .= $t; continue; }
        if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
            $code .= str_repeat("\n", substr_count($t[1], "\n"));
            continue;
        }
        $code .= $t[1];
    }

    return $code;
}

$pathMissing = [];
$resolverMissing = [];
$noMethod = 0;
$seen = [];

foreach (Illuminate\Support\Facades\Route::getRoutes() as $route) {
    $action = $route->getActionName();
    if (! str_contains($action, '@')) { continue; }
    [$class, $method] = explode('@', $action, 2);
    if (! class_exists($class)) { continue; }

    try { $rc = new ReflectionClass($class); } catch (Throwable $e) { continue; }
    if (! $rc->hasMethod($method)) { $noMethod++; continue; }
    if (! $rc->getFileName()) { continue; }

    $rm = $rc->getMethod($method);
    $lines = explode("\n", codeOnly($rc->getFileName()));
    $body = implode("\n", array_slice($lines, $rm->getStartLine() - 1, $rm->getEndLine() - $rm->getStartLine() + 1));

    preg_match_all("/view\(\s*'([^']+)'|view\(\s*\"([^\"]+)\"/", $body, $m, PREG_SET_ORDER);

    foreach ($m as $set) {
        $name = $set[1] !== '' ? $set[1] : ($set[2] ?? '');
        if ($name === '') { continue; }

        $key = $route->uri() . '|' . implode(',', $route->methods()) . '|' . $name;
        if (isset($seen[$key])) { continue; }
        $seen[$key] = true;

        /* The naive check: the default view root only. */
        $onDisk = $root . '/resources/views/' . str_replace('.', '/', $name) . '.blade.php';
        if (file_exists($onDisk)) { continue; }

        $row = sprintf('%-6s %-44s %-46s %s',
            implode(',', $route->methods()), $route->uri(), $name, $action);
        $pathMissing[] = $row;

        /* The authoritative check: every registered path and namespace. */
        if (! view()->exists($name)) { $resolverMissing[] = $row; }
    }
}

$vendored = array_values(array_filter($resolverMissing, static fn ($r) => str_contains($r, 'Workdo\\')));
$firstParty = array_values(array_filter($resolverMissing, static fn ($r) => ! str_contains($r, 'Workdo\\')));

echo "ROUTES NAMING A VIEW THAT DOES NOT EXIST\n", str_repeat('=', 86), "\n\n";
printf("  path-based (resources/views only) ....... %d route+view pairs\n", count($pathMissing));
printf("  resolver-based (view()->exists) ......... %d\n", count($resolverMissing));
printf("  RESCUED by a module namespace / path .... %d  (%.0f%% of the path-based count)\n",
    count($pathMissing) - count($resolverMissing),
    count($pathMissing) ? (count($pathMissing) - count($resolverMissing)) / count($pathMissing) * 100 : 0);
printf("    of the resolver-based: vendored ....... %d\n", count($vendored));
printf("    of the resolver-based: first-party .... %d\n", count($firstParty));
printf("\n  (separately: %d routes name a METHOD their controller does not have -- a different\n", $noMethod);
echo "   family, carried on its own row, not counted above)\n";

echo "\nFIRST-PARTY, every one of them:\n", str_repeat('-', 86), "\n";
sort($firstParty);
foreach ($firstParty as $r) { echo '  ', $r, "\n"; }

echo "\n  currency-rates and otp-integration are already recorded and deliberately unfixed as\n";
echo "  owner-scope. Anything else in that list is new -- at the time of writing, exactly one:\n";
echo "  POST products/sku_combination, pinned by KnownUnrenderableRoutesTest.\n";
