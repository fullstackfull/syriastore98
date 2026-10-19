<?php

/**
 * P1-OWN-01 / S1 — WHAT "THE BackupRestore MODULE IS DISABLED" ACTUALLY PROTECTS.
 * =============================================================================================
 *
 * The owner confirmed on 2026-10-19 that BackupRestore is DISABLED on the Store Syria server.
 * That confirmation is about the server's state; this script asks what the CODE does with each
 * meaning of "disabled", because the answers differ and only one of them is safe.
 *
 * NOT A TEST, AND DELIBERATELY NOT IN A TESTSUITE. `tests/Support` is not one of the three
 * directories phpunit.xml collects. It cannot be a PHPUnit test for the reason CLAUDE.md records
 * and `CredentialBoundaryTest:504-511` learned the hard way: NO WorkDo MODULE LOADS IN ANY TEST
 * RUN unless the test inserts an `add_on_managers` row, because
 * `PackageServiceProvider::loadDynamicAddons()` runs inside `$this->app->booted()`. An in-process
 * assertion that "the backuprestore routes are absent" is therefore VACUOUS — it passes whatever
 * production does. The claim needs a fresh application boot per module state, which is what this
 * script does: three boots, in three subprocesses.
 *
 * NEVER TOUCHES A REAL DATABASE, AND NEVER ENABLES ANYTHING ON A SERVER. Each boot runs against
 * a throwaway sqlite file passed in the ENVIRONMENT (Laravel's Dotenv is immutable, so .env
 * cannot win it back), created and deleted here. It reads the module's route table; it does not
 * run a backup, write an artefact, or change any configuration.
 *
 *   php tests/Support/Security/bin/verify-backuprestore-module-gating.php
 *
 * Exit 0 = every check passed. Exit 1 = a check failed.
 *
 * WHAT IT ESTABLISHES, and why each matters to the owner's decision:
 *
 *   [1] NO `add_on_managers` ROW  ->  the routes do not exist. This is the safe state, and the
 *       only one that is safe by construction.
 *
 *   [2] A ROW WITH `is_enable = 0`  ->  THE ROUTES EXIST. `loadDynamicAddons()` does
 *       `AddOnManager::pluck('module')` with NO `is_enable` predicate
 *       (app/Providers/PackageServiceProvider.php:47), so a row's mere EXISTENCE loads the
 *       module's service provider and registers its routes. `is_enable` gates nothing here.
 *       So "disabled" is only protective if it means NO ROW; if it means the flag is off, the
 *       attack surface is present and only request-time middleware stands in front of it.
 *
 *   [3] `is_enable` DOES gate `module_is_active()`  ->  `App\Classes\Module::isEnabled()` is
 *       `AddOnManager::where('module',$m)->where('is_enable',1)->exists()`
 *       (app/Classes/Module.php:138-144). So the two sources DISAGREE by design: the loader
 *       says loaded, the activation check says inactive.
 *
 * AND THE THING NEITHER STATE PROTECTS, which is why S1's title is imprecise. S1 is headed
 * "unauthenticated route to the whole database and `.env`". The ROUTE is not unauthenticated:
 * `packages/workdo/BackupRestore/src/routes/web.php:17` carries `['auth','web',
 * 'PlanModuleCheck:BackupRestore']`, and the controller additionally requires
 * `isAbleTo('backup&restore manage|create|delete')`. What is unauthenticated is the ARTEFACT: a
 * backup already generated sits in `storage/uploads/{Files_Backup,Database_Backup}` INSIDE the
 * document root at 0777, and `.htaccess` serves any real file unrewritten. That file is
 * downloadable by anyone who knows the name, and disabling the module does not remove it.
 * Containment is therefore a HOST change (deny the paths, move the artefacts), not a module
 * change — which also means it does not touch `packages/workdo/`.
 */

$root = dirname(__DIR__, 4);

require $root . '/vendor/autoload.php';

$failures = 0;

$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    printf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail === '' ? '' : "\n         " . $detail);
    if (! $ok) {
        $failures++;
    }
};

/**
 * Boot the application in a SUBPROCESS against a throwaway sqlite file seeded with the given
 * add_on_managers rows, and return the registered URIs matching a needle.
 *
 * A subprocess because a service provider is registered once per process: toggling the row and
 * re-booting in-process would return whatever the first boot decided.
 */
$routesWithRow = static function (?int $isEnable) use ($root): array {
    $sqlite = tempnam(sys_get_temp_dir(), 'brgating') . '.sqlite';
    touch($sqlite);

    // SEEDED WITH RAW PDO, BEFORE THE APP BOOTS. The loader runs inside app->booted(), so the
    // row has to exist before bootstrap -- and seeding through Schema/DB would need the app
    // booted first, which is the chicken-and-egg the first version of this script fell into
    // ("A facade root has not been set").
    $pdo = new PDO('sqlite:' . $sqlite, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE add_on_managers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        module VARCHAR(191) NULL,
        name VARCHAR(191) NULL,
        is_enable TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NULL,
        updated_at DATETIME NULL
    )');
    if ($isEnable !== null) {
        $pdo->exec("INSERT INTO add_on_managers (module, name, is_enable)
                    VALUES ('BackupRestore', 'BackupRestore', {$isEnable})");
    }
    $pdo = null;

    $probe = <<<'PROBE'
<?php
$root = getenv('BR_ROOT');
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';

// loadDynamicAddons() runs in app->booted(); bootstrapping the console kernel reaches it, and
// the add_on_managers row is already in the sqlite file by now.
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$uris = [];
foreach (Illuminate\Support\Facades\Route::getRoutes() as $r) {
    if (str_contains($r->uri(), 'backuprestore')) {
        $uris[] = $r->methods()[0] . ' ' . $r->uri()
            . ' [' . implode(',', $r->gatherMiddleware()) . ']';
    }
}
sort($uris);

$active = null;
try {
    $active = function_exists('module_is_active') ? (bool) module_is_active('BackupRestore') : null;
} catch (\Throwable $e) {
    $active = 'threw: ' . $e->getMessage();
}

echo json_encode([
    'uris' => array_values(array_unique($uris)),
    'module_is_active' => $active,
], JSON_PRETTY_PRINT);
PROBE;

    $probePath = tempnam(sys_get_temp_dir(), 'brprobe') . '.php';
    file_put_contents($probePath, $probe);

    $cmd = 'BR_ROOT=' . escapeshellarg($root)
        . ' DB_CONNECTION=sqlite DB_DATABASE=' . escapeshellarg($sqlite)
        . ' CACHE_STORE=array'
        . ' php ' . escapeshellarg($probePath) . ' 2>&1';

    exec($cmd, $lines, $rc);

    @unlink($probePath);
    @unlink($sqlite);

    $raw = implode("\n", $lines);
    $decoded = json_decode($raw, true);

    if (! is_array($decoded)) {
        fwrite(STDERR, "PROBE DID NOT RETURN JSON (exit {$rc}):\n{$raw}\n");

        return ['uris' => null, 'module_is_active' => null, 'raw' => $raw];
    }

    return $decoded;
};

echo "P1-OWN-01 -- what 'the BackupRestore module is disabled' actually protects\n";
echo str_repeat('=', 78) . "\n\n";

echo "STATE 1 -- no add_on_managers row at all (the safe state).\n";
$none = $routesWithRow(null);
printf("  routes matching 'backuprestore': %s\n",
    $none['uris'] === null ? 'PROBE FAILED' : (count($none['uris']) === 0 ? 'none' : "\n    - " . implode("\n    - ", $none['uris'])));
$check(
    'with NO row, the module registers NO backuprestore route -- the exposure does not exist',
    is_array($none['uris']) && $none['uris'] === []
);
echo "\n";

echo "STATE 2 -- a row with is_enable = 0 ('disabled' in the flag sense).\n";
$off = $routesWithRow(0);
printf("  routes matching 'backuprestore': %s\n",
    $off['uris'] === null ? 'PROBE FAILED' : (count($off['uris']) === 0 ? 'none' : "\n    - " . implode("\n    - ", $off['uris'])));
$check(
    'with is_enable = 0 the ROUTES ARE STILL REGISTERED -- loadDynamicAddons() has no is_enable '
    . 'predicate, so a row\'s existence alone loads the module',
    is_array($off['uris']) && $off['uris'] !== []
);
$check(
    'and module_is_active() reports FALSE in that same state -- the loader and the activation '
    . 'check disagree by design, which is the finding',
    $off['module_is_active'] === false,
    'module_is_active(BackupRestore) = ' . var_export($off['module_is_active'], true)
);
echo "\n";

echo "STATE 3 -- a row with is_enable = 1 (fully enabled).\n";
$on = $routesWithRow(1);
printf("  routes matching 'backuprestore': %s\n",
    $on['uris'] === null ? 'PROBE FAILED' : (count($on['uris']) === 0 ? 'none' : "\n    - " . implode("\n    - ", $on['uris'])));
$check(
    'with is_enable = 1 the routes are registered',
    is_array($on['uris']) && $on['uris'] !== []
);
$check(
    'module_is_active() is PRINCIPAL-DEPENDENT, not just flag-dependent: with no authenticated '
    . 'user it answers FALSE even at is_enable = 1',
    $on['module_is_active'] === false,
    'module_is_active(BackupRestore) = ' . var_export($on['module_is_active'], true)
        . "\n         This is the THIRD disagreeing source, and it is read rather than measured"
        . "\n         here because seeding a principal needs the whole users/roles/plans schema:"
        . "\n           app/Helper/helper.php:1319-1338 -- with no route slug it requires"
        . "\n           Auth::check() or an explicit \$user_id, and RETURNS FALSE when neither is"
        . "\n           present (:1338). For a principal it then answers TRUE unconditionally for"
        . "\n           a 'super admin' (:1328-1330) and otherwise consults ActivatedModule()."
);
$check(
    'STATE 2 and STATE 3 register the SAME routes -- so the flag changes nothing about what is '
    . 'reachable, only what module_is_active() answers',
    is_array($off['uris']) && is_array($on['uris']) && $off['uris'] === $on['uris']
);

echo "\n" . str_repeat('=', 78) . "\n";
printf("%d failure(s)\n", $failures);

if ($failures === 0) {
    echo <<<'SUMMARY'

WHAT THIS MEANS FOR THE OWNER'S CONFIRMATION
--------------------------------------------
"Disabled" is protective ONLY if it means there is no add_on_managers row for BackupRestore.
If it means the row exists with is_enable = 0, the module's routes ARE registered and the only
things in front of them are `auth`, `PlanModuleCheck:BackupRestore` and the controller's own
`isAbleTo('backup&restore ...')` checks.

THE THREE SOURCES THAT DISAGREE, which is what P1-OWN-01 asked to be documented:
  1. add_on_managers ROW EXISTENCE -- gates the LOADER, and therefore the route table.
     PackageServiceProvider.php:47, `AddOnManager::pluck('module')`, no is_enable predicate.
     MEASURED above: this is the only source that changes what is reachable.
  2. add_on_managers.is_enable -- gates App\Classes\Module::isEnabled() only
     (app/Classes/Module.php:138-144). MEASURED above: it changes nothing about the route table.
  3. module_is_active($name) -- gates PlanModuleCheck for non-operators, and is
     PRINCIPAL-DEPENDENT on top of (2): false with no principal, true unconditionally for a
     'super admin', otherwise ActivatedModule(). app/Helper/helper.php:1277-1343.

Two further facts, read rather than measured here, that bear on that middle state:
  - PlanModuleCheck::handle opens `if (auth()->user() && auth()->user()->type != 'super admin')`
    and falls through to `return $next($request)`, so for the PLATFORM OPERATOR the module check
    is a NO-OP regardless of module state. The operator is then stopped only by the controller's
    isAbleTo -- a Laratrust permission check, which per CLAUDE.md does NOT exempt the operator,
    so it refuses them unless the permission was granted.
  - Disabling the module does NOT remove artefacts already generated. Those sit at 0777 in
    storage/uploads/{Files_Backup,Database_Backup}, inside the document root, and .htaccess
    serves any real file unrewritten. THAT is the unauthenticated exposure in S1, and it is a
    host-level containment problem, not a module one.

WHAT TO CHECK ON THE SERVER (read-only; nothing here changes anything):
  1. SELECT module, is_enable FROM add_on_managers WHERE module = 'BackupRestore';
     -- no row is the safe answer. A row with is_enable = 0 means the routes exist.
  2. ls -la storage/uploads/Database_Backup storage/uploads/Files_Backup
     -- any file here is downloadable by anyone who can guess the name.
  3. Whether the document root is the repository root (S1 records that it is) and whether
     .htaccess denies storage/ and *_Backup.
Nothing above requires enabling the module or reading a backup's contents.
SUMMARY;
    echo "\n";
}

exit($failures === 0 ? 0 : 1);
