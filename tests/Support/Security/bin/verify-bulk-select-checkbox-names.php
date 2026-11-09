#!/usr/bin/env php
<?php

/**
 * P2.5-ORD-3 — DRIVEN proof that both bulk-select checkboxes now carry an accessible name.
 * =================================================================================================
 *
 * WHY THIS IS A SCRIPT AND NOT A TEST. The column is built by
 * `bulkDeleteCloneCheckboxColumn()` (app/Helper/helper.php), whose whole body sits behind
 * `module_is_active('BulkDelete')`. `module_is_active()` begins with `Module::has($moduleName)`,
 * and NO WorkDo module is registered in a test run unless an `add_on_managers` row exists before
 * the application boots — the loader runs inside `app->booted()`. So inside the suite the column
 * does not exist at all, and a Feature test driving `/order` renders no checkbox: MEASURED, the
 * rendered page contains neither `select-all` nor `select-row`. A test that asserted the names
 * were present there would have failed; one that asserted they were ABSENT would have passed
 * before and after the repair, proving nothing.
 *
 * `Tests\Unit\Ui\OrderListControlNamesAndContrastTest` is the ratchet on the source, and it is
 * honest about being a source scan. This is the half it cannot do: it boots the application with
 * the module really active and reads what the function really returns.
 *
 * A SUBPROCESS, for the reason `verify-backuprestore-module-gating.php` gives: a service provider
 * is registered once per process, so toggling the row and re-booting in-process would return
 * whatever the first boot decided.
 *
 * WHAT IT ASSERTS
 *   1. the module really is active in the probe — the premise, without which 2 and 3 are vacuous;
 *   2. the select-all heading carries a non-empty `aria-label`;
 *   3. the per-row renderer carries a non-empty `aria-label` and names the row;
 *   4. the row name is built from `this.id` and from no other row field, because the name is
 *      concatenated into an HTML attribute and any shopper-written field there is an attribute
 *      injection on the merchant's own origin;
 *   5. the two names are different, so a screen reader can tell the select-all from a row.
 *
 * RUN
 *     php tests/Support/Security/bin/verify-bulk-select-checkbox-names.php
 *
 * It creates and deletes its own throwaway sqlite file and touches nothing in the repository.
 * Exit 0 = every check passed; exit 1 = a named check failed.
 */

$root = dirname(__DIR__, 4);

if (! is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "composer install first: {$root}/vendor/autoload.php is missing\n");
    exit(1);
}

$failures = [];
$checks = 0;

$check = static function (string $name, bool $ok, string $detail = '') use (&$failures, &$checks): void {
    $checks++;
    printf("  %s  %s%s\n", $ok ? 'ok  ' : 'FAIL', $name, $detail === '' ? '' : "  ({$detail})");

    if (! $ok) {
        $failures[] = $name . ($detail === '' ? '' : ': ' . $detail);
    }
};

/* ---------------------------------------------------------------------------------------------
 * Boot a subprocess with the BulkDelete row in place and ask the helper what it builds.
 * ------------------------------------------------------------------------------------------- */

$sqlite = tempnam(sys_get_temp_dir(), 'bulkselect') . '.sqlite';
touch($sqlite);

/*
 * SEEDED WITH RAW PDO BEFORE THE APP BOOTS. Seeding through Schema/DB would need the application
 * booted first, which is the chicken-and-egg the BackupRestore script records falling into
 * ("A facade root has not been set").
 */
$pdo = new PDO('sqlite:' . $sqlite, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE add_on_managers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    module VARCHAR(191) NULL,
    name VARCHAR(191) NULL,
    is_enable TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NULL,
    updated_at DATETIME NULL
)');
$pdo->exec("INSERT INTO add_on_managers (module, name, is_enable)
            VALUES ('BulkDelete', 'BulkDelete', 1)");

/*
 * `module_is_active()` with no store slug in the route reads `Auth::user()` and short-circuits
 * `true` for `type == 'super admin'`, so the probe signs in as one rather than building a plan and
 * an ActivatedModule chain. That is the operator's own real path through this helper.
 */
$pdo->exec('CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(191) NULL,
    email VARCHAR(191) NULL,
    password VARCHAR(191) NULL,
    type VARCHAR(191) NULL,
    lang VARCHAR(191) NULL,
    language VARCHAR(191) NULL,
    created_by INTEGER NULL,
    current_store INTEGER NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    remember_token VARCHAR(100) NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL
)');
$pdo->exec("INSERT INTO users (name, email, password, type, is_active)
            VALUES ('Operator', 'operator@example.test', 'x', 'super admin', 1)");
$pdo = null;

$probe = <<<'PROBE'
<?php
$root = getenv('BSC_ROOT');
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';

/* loadDynamicAddons() runs in app->booted(); bootstrapping the console kernel reaches it, and the
   add_on_managers row is already in the sqlite file by now. */
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$out = ['module_is_active' => null, 'title' => null, 'render' => null, 'error' => null];

try {
    Illuminate\Support\Facades\Auth::login(App\Models\User::where('type', 'super admin')->first());

    $out['module_is_active'] = (bool) module_is_active('BulkDelete');

    $column = bulkDeleteCloneCheckboxColumn();

    if ($column !== null) {
        $array = $column->toArray();
        $out['title'] = $array['title'] ?? null;
        $out['render'] = is_string($array['render'] ?? null) ? $array['render'] : null;
    }
} catch (\Throwable $e) {
    $out['error'] = get_class($e) . ': ' . $e->getMessage();
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
PROBE;

$probeFile = tempnam(sys_get_temp_dir(), 'bscprobe') . '.php';
file_put_contents($probeFile, $probe);

$env = [
    'BSC_ROOT' => $root,
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => $sqlite,
    'APP_KEY' => getenv('APP_KEY') ?: 'base64:' . base64_encode(random_bytes(32)),
    'APP_ENV' => 'testing',
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
];

$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$process = proc_open(['php', $probeFile], $descriptors, $pipes, $root, $env);

$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exit = proc_close($process);

@unlink($probeFile);
@unlink($sqlite);

echo "P2.5-ORD-3 -- bulk-select checkbox accessible names, with BulkDelete really active\n";
echo str_repeat('-', 94), "\n";

$result = json_decode($stdout, true);

if (! is_array($result)) {
    fwrite(STDERR, "the probe did not answer JSON (exit {$exit}).\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}\n");
    exit(1);
}

if ($result['error'] !== null) {
    fwrite(STDERR, "the probe threw: {$result['error']}\n");
    exit(1);
}

/* ---- 1. the premise ---------------------------------------------------------------------- */

$check('PREMISE  module_is_active(BulkDelete) is true in the probe',
    $result['module_is_active'] === true,
    'module_is_active returned ' . var_export($result['module_is_active'], true));

$check('PREMISE  bulkDeleteCloneCheckboxColumn() returned a column',
    is_string($result['title']) && is_string($result['render']));

if ($failures !== []) {
    echo "\nThe premises failed, so nothing below was measured.\n";
    exit(1);
}

$title = (string) $result['title'];
$render = (string) $result['render'];

echo "\n  select-all heading : {$title}\n";
echo "  per-row renderer   : " . trim(preg_replace('/\s+/', ' ', $render)) . "\n\n";

/* ---- 2/3. both inputs are named ----------------------------------------------------------- */

$nameIn = static function (string $markup): ?string {
    return preg_match('/aria-label="([^"]*)"/', $markup, $m) === 1 ? $m[1] : null;
};

$headingName = $nameIn($title);

$check('the select-all checkbox has a non-empty aria-label',
    $headingName !== null && trim($headingName) !== '',
    'aria-label = ' . var_export($headingName, true));

$check('the per-row checkbox renderer emits an aria-label',
    str_contains($render, 'aria-label="'),
    'renderer: ' . trim(preg_replace('/\s+/', ' ', $render)));

$check('the per-row name is not a bare constant -- it names the row',
    str_contains($render, 'aria-label="\' + ') && str_contains($render, 'this.id'),
    'the row name must vary per row, or ten checkboxes are announced identically');

/* ---- 4. the name cannot be injected into the attribute ----------------------------------- */

preg_match_all('/this\.([A-Za-z_][A-Za-z0-9_]*)/', $render, $fields);
$used = array_values(array_unique($fields[1]));

$check('the renderer reads only `this.id` from the row',
    $used === ['id'],
    'reads: ' . implode(', ', $used)
    . ' -- a shopper-written field concatenated into an HTML attribute is an injection on the '
    . 'merchant origin');

/* ---- 5. the two names are distinguishable ------------------------------------------------- */

$rowLabel = preg_match('/aria-label="\' \+ "([^"]*)"/', $render, $m) === 1
    ? $m[1]
    : (preg_match("/aria-label=\"' \\+ '([^']*)'/", $render, $m2) === 1 ? $m2[1] : null);

$check('the select-all name and the row name are different',
    $rowLabel === null || $headingName !== $rowLabel,
    'heading = ' . var_export($headingName, true) . ', row = ' . var_export($rowLabel, true));

/* ---- verdict ------------------------------------------------------------------------------ */

echo "\n", str_repeat('-', 94), "\n";
printf("%d checks, %d failed\n", $checks, count($failures));

if ($failures !== []) {
    echo "\nFAILED:\n";
    foreach ($failures as $failure) {
        echo "  - {$failure}\n";
    }
    exit(1);
}

echo "\nBoth bulk-select checkboxes carry an accessible name, measured with the vendored module\n";
echo "really registered and really enabled -- not inferred from the source.\n";
exit(0);
