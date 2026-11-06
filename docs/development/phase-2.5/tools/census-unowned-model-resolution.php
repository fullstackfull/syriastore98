<?php
/**
 * P2.5-PKG-B — WHICH OTHER CONTROLLERS HAVE THE SHAPE THAT PRODUCED F-SEC-01?
 * ================================================================================================
 *
 * F-SEC-01 was a merchant reading, renaming, TAKING and deleting another merchant's brands and
 * labels. The shape, measured in `tests/Feature/Security/BrandCrossTenantReadWriteTest`:
 *
 *   - a controller method resolves a model BY CALLER-SUPPLIED ID (`Model::find($id)`, or route
 *     model binding, which is NOT tenant-scoped in this codebase);
 *   - the model is absent from `config/tenancy.php`'s `scoped_models`, so `StoreScope` adds no
 *     predicate for ANY principal (`StoreScope::isActiveFor()` short-circuits);
 *   - the method checks a Laratrust PERMISSION, which says what the caller may do to a brand --
 *     never WHICH brand -- and then writes.
 *
 * The permission check is what made it look covered. `BrandLabelStatusAuthorizationTest` has
 * fifteen tests, all driving the two methods that WERE hardened.
 *
 * WHY THIS IS A SCRIPT AND NOT A GREP. My own ordering assertion in a sibling test used one `/s`
 * regex over a whole file and CROSSED METHOD BOUNDARIES -- I applied the exact mutant it was
 * written to catch and the test printed a tick. So this walks PHP's own token stream
 * (`token_get_all`), which gets strings, heredocs and comments right by construction, and matches
 * only within one method's braces.
 *
 * WHAT IT IS NOT. It is a census, so it reports SHAPES, not defects. Every row needs a driven
 * test before it is called a finding -- the brand hole was only real because a request measured it,
 * and three of the label "failures" turned out to be a fixture inventing a column. Read a row as
 * "look here", never as "this is exploitable".
 *
 * Usage:  php docs/development/phase-2.5/tools/census-unowned-model-resolution.php [--all]
 */

$root = dirname(__DIR__, 4);

// ---------------------------------------------------------------------------------------------
// The scoped-model allowlist, read from the real config rather than retyped. A model ON this list
// still only filters when it also registers the scope -- CLAUDE.md records Product as the case
// where the two answers differ -- but a model OFF it cannot possibly filter, which is the half
// this census needs.
// ---------------------------------------------------------------------------------------------
$scopedRaw = trim(shell_exec(
    'cd ' . escapeshellarg($root) . ' && DB_CONNECTION=sqlite DB_DATABASE=database/p2dev.sqlite '
    . 'CACHE_STORE=file SESSION_DRIVER=file php artisan tinker --execute='
    . escapeshellarg('foreach (config("tenancy.scoped_models") as $m) { echo class_basename($m) . "\n"; }')
    . ' 2>/dev/null'
));
$scoped = array_values(array_filter(array_map('trim', explode("\n", $scopedRaw))));
if (count($scoped) < 5) {
    fwrite(STDERR, "PREMISE FAILED: read only " . count($scoped) . " scoped models; expected ~11.\n");
    fwrite(STDERR, "Refusing to report -- an empty allowlist would mark every model unscoped.\n");
    exit(2);
}

// ---------------------------------------------------------------------------------------------
// AN OWNER MARKER IS A *CHECK* ONLY IN A COMPARISON, A QUERY PREDICATE, OR A DEDICATED GUARD.
//
// The first list here was bare substrings -- 'store_id', 'creatorId', 'getCurrentStore'. Two
// measurements against the proved-holed tree at d6d25a1b^ killed that:
//
//   1. `$brand->store_id = getCurrentStore();` -- an ASSIGNMENT, and the takeover primitive
//      itself -- read as protection, hiding both `update()` methods.
//   2. `ProductBrandController::destroy` matched on `\Auth::user()->creatorId()`, which is there
//      to bill a storage-quota decrement TO THE CALLER'S OWN ACCOUNT. The caller naming
//      themselves for a side effect is not a statement about who owns the row. That method let a
//      merchant delete another tenant's brand, proved by a driven request.
//
// Both misses have the same cause: a marker used as a bare VALUE is not a predicate. So these
// patterns require the marker to participate in a comparison, a query predicate, or a call whose
// whole job is to refuse. Each row still prints which pattern matched, so a human can audit the
// classification instead of trusting it.
// ---------------------------------------------------------------------------------------------
const OWNER_CHECK_PATTERNS = [
    'query-predicate'   => '/\b(where|whereIn|orWhere|firstWhere|whereNot|whereHas)\s*\(\s*[\'"](store_id|created_by)[\'"]/i',
    'compare-store_id'  => '/->\s*store_id\s*(===|==|!==|!=|<=>)/',
    'compare-to-store'  => '/(===|==|!==|!=)\s*[^;{}]{0,60}->\s*store_id/',
    'compare-helper'    => '/(getCurrentStore|ownedStoreIds|creatorId|currentStore)\s*\([^)]*\)\s*(===|==|!==|!=|<=>)/',
    'compare-to-helper' => '/(===|==|!==|!=)\s*[^;{}]{0,70}(getCurrentStore|ownedStoreIds|creatorId|currentStore)\s*\(/',
    'in_array-store'    => '/in_array\s*\([^;{}]{0,140}(store_id|getCurrentStore|ownedStoreIds|creatorId)/',
    'policy-gate'       => '/(\bauthorize\s*\(|\bGate::|->\s*denies\s*\(|\babort_unless\s*\(|\babort_if\s*\()/',
    // `->can('ability', $model)` -- TWO arguments -- is a policy call, and policies work here.
    // A one-argument `->can('Some Permission')` is NOT a check in this codebase at all:
    // `permissions_as_gates => false`, so a Laratrust permission name asked of the Gate is ALWAYS
    // FALSE. Requiring the comma is what separates the two, and the distinction is load-bearing:
    // crediting bare `can(` would credit a call that cannot pass, while refusing two-arg `can(`
    // reported `SavedViewController::destroy` as unguarded when it calls
    // `$user->can('delete', $view)` against a registered SavedViewPolicy -- first-party Phase 01
    // code that is correct.
    'policy-can'        => '/->\s*can\s*\(\s*[\'"][^\'"]+[\'"]\s*,/',
];
// NOTE: there is deliberately NO pattern matching guard-helper NAMES. An earlier revision had
// '/\b(callerOwns|resolveOwned|ownsRecord|...)/' -- the names I had happened to write -- and it
// reported `CategoryController::update` as unguarded. That method calls
// `$this->categoryIsOutsideCurrentStore($category)`, a real ownership guard closed as P1-INV-08,
// and an independent tenant-authz review had already traced the controller clean. The census
// contradicted a correct review because the guard was spelled a name my list did not hold, and a
// list of names can only ever recognise the guards its author already knew about.
// Same-class guard helpers are resolved transitively instead -- see effectiveChecks().
const PERMISSION_MARKERS = ['isAbleTo', 'hasPermission', 'hasRole', 'isOperator'];

/** Which owner-CHECK patterns does this body satisfy? Names, so a row can be audited by eye. */
function ownerChecks(string $body): array
{
    $hits = [];
    foreach (OWNER_CHECK_PATTERNS as $label => $pattern) {
        if (preg_match($pattern, $body)) { $hits[] = $label; }
    }
    return $hits;
}

/** Resolve a model by caller-supplied id. */
const RESOLUTION_PATTERNS = ['::find(', '::findOrFail(', '::where(', '::firstWhere('];

function methodBodies(string $path): array
{
    $tokens = token_get_all(file_get_contents($path));
    $out = [];
    $n = count($tokens);

    for ($i = 0; $i < $n; $i++) {
        if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
            continue;
        }
        // The name is the next T_STRING.
        $name = null;
        for ($j = $i + 1; $j < $n; $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) { $name = $tokens[$j][1]; break; }
            if ($tokens[$j] === '(') { break; } // closure
        }
        if ($name === null) { continue; }

        // Signature runs to the matching ')', then the body from the next '{' to its match.
        $depth = 0; $sig = ''; $k = $j;
        for (; $k < $n; $k++) {
            $t = is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
            if ($t === '(') { $depth++; }
            if ($depth > 0) { $sig .= $t; }
            if ($t === ')') { $depth--; if ($depth === 0) { $k++; break; } }
        }
        // Skip a return type.
        //
        // COMMENTS ARE EXCLUDED FROM THE BODY, and that is not tidiness. Run with them in, this
        // census reported `ProductBrandController::update` and `ProductLabelController::update` as
        // TAKEOVER shapes on the FIXED tree -- because the comment recording the deletion quotes
        // the deleted line verbatim:
        //
        //     // `$productBrand->store_id = getCurrentStore();` was the takeover primitive
        //
        // So the detector matched its own documentation of the fix and reported the fix as the
        // defect. That is the eighth time a detector in this phase has matched its author's prose,
        // including the project's own comment-nesting guard catching a glob in a doc. A scanner
        // that reads prose as code will always eventually read a description of a bug as the bug.
        $body = ''; $bdepth = 0; $started = false; $line = is_array($tokens[$i]) ? $tokens[$i][2] : 0;
        for (; $k < $n; $k++) {
            $isComment = is_array($tokens[$k])
                && in_array($tokens[$k][0], [T_COMMENT, T_DOC_COMMENT], true);
            $t = is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
            if ($t === ';' && ! $started) { break; } // abstract / interface
            if ($t === '{' && ! $isComment) { $bdepth++; $started = true; }
            if ($started && ! $isComment) { $body .= $t; }
            if ($t === '}' && ! $isComment) { $bdepth--; if ($bdepth === 0) { break; } }
        }
        if ($started) { $out[] = ['name' => $name, 'sig' => $sig, 'body' => $body, 'line' => $line]; }
        $i = $k;
    }

    return $out;
}

function hasAny(string $haystack, array $needles): array
{
    $hits = [];
    foreach ($needles as $needle) {
        if (stripos($haystack, $needle) !== false) { $hits[] = $needle; }
    }
    return $hits;
}

/**
 * AN OWNERSHIP *ASSIGNMENT* IS NOT AN OWNERSHIP *CHECK*, AND THE FIRST VERSION OF THIS SCRIPT
 * COULD NOT TELL THEM APART.
 *
 * Measured, not reasoned: run against the tree at d6d25a1b^ -- the two controllers whose holes are
 * proved by a driven test -- the census reported `ProductLabelController::destroy` and three
 * unguarded reads, and MISSED BOTH `update()` METHODS. Those are the two that carried
 *
 *     $productBrand->store_id = getCurrentStore();
 *
 * the takeover primitive: the sharpest edge of F-SEC-01, where a foreign row did not merely get
 * edited but CHANGED HANDS. Both `store_id` and `getCurrentStore` are owner markers, so the
 * assignment read as protection and the row was dropped.
 *
 * So the heuristic hid the worst variant of the very defect it was written to find. This strips
 * assignment statements before the owner scan, and reports the assignment separately -- it is a
 * red flag in its own right, not noise.
 */
function stripOwnershipAssignments(string $body): string
{
    // `$x->store_id = <anything>;`  -- not `==`, `===`, `=>`.
    $body = preg_replace('/\$[A-Za-z0-9_]+\s*->\s*store_id\s*=(?!=)[^;]*;/', ' ', $body);
    // `'store_id' => <anything>` inside a create()/update()/forceCreate() array.
    $body = preg_replace('/([\'\"])store_id\1\s*=>\s*[^,\)\]]*/', ' ', $body);

    return $body;
}

/** Does the body ASSIGN store ownership? That is the takeover shape, reported in its own column. */
function assignsOwnership(string $body): bool
{
    return (bool) preg_match('/\$[A-Za-z0-9_]+\s*->\s*store_id\s*=(?!=)/', $body);
}

/** Which model classes does this method resolve by id? */
function resolvedModels(string $body, string $sig): array
{
    $models = [];

    // Static resolution: Model::find($x) / ::findOrFail / ::where
    if (preg_match_all('/([A-Z][A-Za-z0-9_]*)\s*::\s*(find|findOrFail|where|firstWhere)\s*\(/', $body, $m)) {
        foreach ($m[1] as $cls) { $models[$cls] = 'static'; }
    }
    // Route model binding: a typed parameter whose type starts uppercase and is not a framework type.
    if (preg_match_all('/([A-Z][A-Za-z0-9_]*)\s+\$[a-zA-Z_]/', $sig, $m)) {
        foreach ($m[1] as $cls) {
            if (in_array($cls, ['Request', 'Response', 'Collection', 'Builder', 'DataTable', 'Validator'], true)) { continue; }
            if (str_ends_with($cls, 'DataTable') || str_ends_with($cls, 'Request')) { continue; }
            $models[$cls] = 'binding';
        }
    }

    return $models;
}

/**
 * Which same-class methods does this body call? `$this->foo(`, `self::foo(`, `static::foo(`.
 */
function sameClassCalls(string $body): array
{
    $names = [];
    if (preg_match_all('/(?:\$this\s*->|self\s*::|static\s*::)\s*([a-zA-Z_][A-Za-z0-9_]*)\s*\(/', $body, $m)) {
        foreach ($m[1] as $name) { $names[$name] = true; }
    }
    return array_keys($names);
}

/**
 * CREDIT A GUARD THAT LIVES IN A HELPER, WHATEVER IT IS CALLED.
 *
 * A method is guarded if it checks ownership itself OR calls a same-class method that does --
 * `$this->callerOwnsBrand($brand)`, `$this->categoryIsOutsideCurrentStore($category)`,
 * `$this->resolveOwnedBrand($id)`. Iterated to a fixpoint, so a guard reached through two hops
 * still counts. Under-crediting here is a FALSE POSITIVE -- a real guard reported as missing --
 * and this census already produced one of those against a controller a human review had cleared.
 *
 * THE TRADE THIS MAKES, stated so nobody has to rediscover it: crediting the CALL does not check
 * that its RESULT is acted on. A bare `$this->callerOwnsPage($page);` with no `if` and no
 * `abort()` would score as guarded here and is not guarded at all. The census accepts that
 * because the alternative -- demanding a recognised refusal shape -- is another list of spellings,
 * and a missing guard reported as present is the error that gets someone hurt. Every surviving row
 * is read by eye anyway; this one is a reason to read the dropped rows too when a surface matters.
 */
function effectiveChecks(array $methods): array
{
    $direct = [];
    $calls = [];
    foreach ($methods as $m) {
        $direct[$m['name']] = ownerChecks(stripOwnershipAssignments($m['body']));
        $calls[$m['name']] = sameClassCalls($m['body']);
    }

    $effective = $direct;
    for ($pass = 0; $pass < 10; $pass++) {
        $changed = false;
        foreach ($effective as $name => $checks) {
            foreach ($calls[$name] ?? [] as $callee) {
                foreach ($effective[$callee] ?? [] as $inherited) {
                    $label = $inherited . ' via ' . $callee . '()';
                    if (! in_array($inherited, $checks, true) && ! in_array($label, $effective[$name], true)) {
                        $effective[$name][] = $label;
                        $changed = true;
                    }
                }
            }
        }
        if (! $changed) { break; }
    }

    return $effective;
}

$showAll = in_array('--all', $argv, true);
$files = glob($root . '/app/Http/Controllers/*.php');
sort($files);

$rows = [];
$methodsSeen = 0;

foreach ($files as $file) {
    $controller = basename($file, '.php');
    $allMethods = methodBodies($file);
    $checksFor = effectiveChecks($allMethods);
    foreach ($allMethods as $m) {
        // Only the methods that act on ONE caller-named record.
        // THE LIST THAT MISSED A LIVE HOLE. It held `changeStatus` and not `updateStatus`, so
        // `PageController::updateStatus()` -- no permission, no ownership, no type check, any
        // authenticated principal able to unpublish any store's storefront page -- was never even
        // examined. `resolvedModels()` would have matched its `Page::findOrFail(` perfectly well;
        // only the name excluded it. Found by a human tenant-authz review of the fixes this census
        // produced, which is the argument for not trusting a census alone.
        //
        // A name list can only ever find the spellings its author thought of, so this one now
        // carries every status-write spelling actually present in the tree (measured, not guessed)
        // and anything taking a single record. It remains a list, and that remains its limit.
        if (! in_array($m['name'], ['show', 'edit', 'update', 'destroy', 'delete', 'changeStatus',
                                    'changePopular', 'duplicate', 'clone', 'restore',
                                    'updateStatus', 'statusChange', 'ChangeStatus', 'toggle',
                                    'changeOrderStatus', 'updateOrderStatus', 'approve', 'reject',
                                    'publish', 'unpublish', 'activate', 'deactivate'], true)) {
            continue;
        }
        $methodsSeen++;
        $models = resolvedModels($m['body'], $m['sig']);
        if ($models === []) { continue; }

        // The owner scan runs on the body with ownership ASSIGNMENTS removed -- see
        // stripOwnershipAssignments() for the measurement that forced this.
        $owner  = $checksFor[$m['name']] ?? [];
        $perm   = hasAny($m['body'], PERMISSION_MARKERS);
        $assign = assignsOwnership($m['body']);

        // Does it WRITE? save/update/delete/forceDelete -- a read-only method is a lower severity.
        $writes = (bool) preg_match('/->\s*(save|update|delete|forceDelete|increment|decrement)\s*\(/', $m['body']);

        foreach ($models as $cls => $how) {
            if (in_array($cls, $scoped, true)) { continue; }
            $rows[] = [
                'controller' => $controller,
                'method' => $m['name'],
                'line' => $m['line'],
                'model' => $cls,
                'how' => $how,
                'owner' => $owner,
                'perm' => $perm,
                'writes' => $writes,
                'assigns' => $assign,
            ];
        }
    }
}

$unowned = array_values(array_filter($rows, fn ($r) => $r['owner'] === []));
$writing = array_values(array_filter($unowned, fn ($r) => $r['writes']));

echo "CENSUS: single-record controller methods that resolve an UNSCOPED model by caller id\n";
echo str_repeat('=', 96) . "\n";
echo "scoped_models (" . count($scoped) . "): " . implode(', ', $scoped) . "\n";
echo "controllers scanned: " . count($files) . "   single-record methods: {$methodsSeen}\n";
echo "methods resolving an unscoped model: " . count($rows) . "\n";
echo "  ...of those, NO owner/tenant marker anywhere in the body: " . count($unowned) . "\n";
echo "  ...of those, which also WRITE: " . count($writing) . "   <-- the F-SEC-01 shape\n";
$takeover = array_values(array_filter($rows, fn ($r) => $r['assigns']));
echo "methods that ASSIGN \$x->store_id (takeover shape, guarded or not): " . count($takeover) . "\n";
echo str_repeat('=', 96) . "\n\n";

$print = function (array $set, string $heading) {
    if ($set === []) { echo "{$heading}: none\n\n"; return; }
    echo "{$heading} (" . count($set) . ")\n";
    printf("  %-32s %-14s %-23s %-7s %-10s %s\n",
        'CONTROLLER', 'METHOD', 'MODEL', 'WRITES?', 'ASSIGNS?', 'PERMISSION CHECK');
    foreach ($set as $r) {
        printf("  %-32s %-14s %-23s %-7s %-10s %s\n",
            $r['controller'], $r['method'] . ':' . $r['line'],
            $r['model'] . ' (' . $r['how'] . ')',
            $r['writes'] ? 'WRITES' : 'read',
            $r['assigns'] ? 'TAKEOVER' : '-',
            $r['perm'] === [] ? 'NONE' : implode(',', $r['perm']));
    }
    echo "\n";
};

$print($writing, 'WRITES an unscoped model with no owner check');
$print(array_values(array_filter($unowned, fn ($r) => ! $r['writes'])), 'READS an unscoped model with no owner check');

$print($takeover, 'ASSIGNS $x->store_id on a caller-named row (takeover shape -- check the guard above it)');

if ($showAll) {
    foreach (array_values(array_filter($rows, fn ($r) => $r['owner'] !== [])) as $r) {
        printf("  %-32s %-14s %-23s %-7s %s\n", $r['controller'], $r['method'] . ':' . $r['line'],
            $r['model'], $r['writes'] ? 'WRITES' : 'read', implode(',', $r['owner']));
    }
}

echo "EVERY ROW IS A SHAPE, NOT A FINDING. Drive it with a request before calling it anything.\n";
