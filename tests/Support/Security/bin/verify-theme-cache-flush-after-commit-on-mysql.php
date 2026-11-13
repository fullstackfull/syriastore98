<?php

/**
 * PHASE 03 M3-C, REQUIREMENT 4 -- THE POST-COMMIT CACHE FLUSH, PROVED WITH TWO REAL PROCESSES.
 * =================================================================================================
 *
 * THE REQUIREMENT, verbatim: "Invalidate the theme model cache after transaction commit, never as
 * the only invalidation inside the transaction."
 *
 * WHY THIS CANNOT BE A FEATURE TEST, and it is not a preference. The guarantee is about two
 * principals observing one transaction, so a single-process test is structurally unable to see it:
 *
 *   - `ThemeCustomize` is `Cachable`, so an Eloquent `updateOrCreate()` flushes the model cache as
 *     part of its own save lifecycle -- INSIDE the transaction, before the commit.
 *   - A reader on the SAME connection sees the uncommitted row, so when it re-populates the cache it
 *     stores the NEW value. The stale state is unreachable and the post-commit flush is invisible:
 *     deleting it leaves every single-process assertion GREEN. Measured, and recorded in
 *     `tests/Feature/Theme/ThemeConfigurationServiceTest` where that test's claim was narrowed.
 *   - Only a reader on a DIFFERENT connection cannot see the uncommitted row. That reader caches the
 *     OLD value in the window between the lifecycle flush and the commit -- and then serves it
 *     forever, because nothing flushes again.
 *
 * So: two processes, a shared `database` cache store (production's `CACHE_STORE`, not the suite's
 * `array`, which is per-process and would make the whole measurement impossible), and file
 * semaphores rather than sleeps, so there is no timing race to lose.
 *
 *       parent                                  child
 *       ------                                  -----
 *       seed blog_title = OLD, commit
 *       getThemeSetting()  -> caches OLD
 *       spawn child ----------------------->    BEGIN
 *                                               writeSettings(NEW)   <- lifecycle flush, in-txn
 *       wait for READY     <-----------------   touch READY
 *       getThemeSetting()  -> caches OLD            (the concurrent reader; cannot see the
 *                                                    uncommitted NEW on its own connection)
 *       touch GO           ----------------->   wait for GO
 *                                               COMMIT               <- afterCommit flush fires
 *       wait for DONE      <-----------------   touch DONE
 *       getThemeSetting()  -> MUST be NEW
 *
 * WITH the post-commit flush the final read is NEW. WITHOUT it the parent serves its own stale OLD
 * entry indefinitely, which is the Flutter defect M3-C set out to fix, one layer deeper: the write
 * lands, the app confirms it, and the storefront never changes.
 *
 * HOW TO RUN. Needs a throwaway MySQL or MariaDB. It creates and drops its OWN database.
 *
 *     php tests/Support/Security/bin/verify-theme-cache-flush-after-commit-on-mysql.php
 *
 * NEVER point FRESH_MYSQL_DATABASE at a development or production server.
 *
 * TO FALSIFY IT, delete the `DB::afterCommit(...)` call in
 * `App\Services\Theme\ThemeConfigurationService::flushProjectionCacheAfterCommit()` and run again:
 * check 4 must fail. That is recorded in the Phase 03 M3 document as a performed measurement, not
 * as an expectation.
 */

$root = dirname(__DIR__, 4);

/* ============================================================================ the child process */

if (in_array('--child', $argv, true)) {
    require $root . '/vendor/autoload.php';

    foreach (json_decode(getenv('M3C_ENV'), true) as $k => $v) {
        putenv("{$k}={$v}");
        $_ENV[$k] = $v;
        $_SERVER[$k] = $v;
    }

    $app = require $root . '/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    $sem = getenv('M3C_SEM');
    $storeId = (int) getenv('M3C_STORE');
    $userId = (int) getenv('M3C_USER');

    $user = App\Models\User::find($userId);

    /*
     * The OUTER transaction is deliberate: it is the shape `Api\DashboardController` really uses
     * (`\DB::beginTransaction()` around the whole update, `\DB::commit()` at the end). The service's
     * own `DB::transaction()` nests as a SAVEPOINT, and `DB::afterCommit` defers to the OUTERMOST
     * commit -- which is the behaviour under test.
     */
    Illuminate\Support\Facades\DB::beginTransaction();

    $written = app(App\Services\Theme\ThemeConfigurationService::class)
        ->writeSettings($storeId, 'stylique', ['blog_title' => 'NEW'], $user);

    file_put_contents($sem . '.written', $written === null ? 'REFUSED' : json_encode($written));
    touch($sem . '.ready');

    $waited = 0;
    while (! file_exists($sem . '.go')) {
        usleep(20000);
        clearstatcache(true, $sem . '.go');
        if (($waited += 20000) > 30000000) {
            Illuminate\Support\Facades\DB::rollBack();
            file_put_contents($sem . '.done', 'TIMEOUT');
            exit(3);
        }
    }

    Illuminate\Support\Facades\DB::commit();

    touch($sem . '.done');
    exit(0);
}

/* =========================================================================== the parent process */

require $root . '/vendor/autoload.php';

$database = getenv('FRESH_MYSQL_DATABASE') ?: 'themecache_verify';

if (! preg_match('/(fresh|verify)/i', $database)) {
    fwrite(STDERR, "Refusing a database whose name says nothing about being throwaway: {$database}\n");
    exit(2);
}

$socket = getenv('FRESH_MYSQL_SOCKET') ?: null;
if ($socket === null) {
    foreach (['/run/mysqld/mysqld.sock', '/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock'] as $c) {
        if (file_exists($c)) { $socket = $c; break; }
    }
}

$username = getenv('FRESH_MYSQL_USERNAME') ?: 'root';
$password = getenv('FRESH_MYSQL_PASSWORD') ?: '';
$host = getenv('FRESH_MYSQL_HOST') ?: '127.0.0.1';
$port = getenv('FRESH_MYSQL_PORT') ?: '3306';

$checks = 0; $failures = 0; $inconclusive = 0;

function check(string $what, bool $ok, string $detail = ''): void {
    global $checks, $failures;
    $checks++;
    echo $ok ? "  ok    {$what}\n" : "  FAIL  {$what}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
    if (! $ok) { $failures++; }
}

/* A premise that does not hold makes every later check meaningless, so it is reported as
 * INCONCLUSIVE rather than as a pass or a failure. A sibling harness produced one false refusal and
 * one false acceptance before this distinction was added. */
function premise(string $what, bool $ok): void {
    global $inconclusive;
    if ($ok) { echo "  ok    premise: {$what}\n"; return; }
    echo "  INCONCLUSIVE  premise failed: {$what}\n";
    $inconclusive++;
}

try {
    $dsn = $socket ? "mysql:unix_socket={$socket}" : "mysql:host={$host};port={$port}";
    $pdo = new PDO($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
    $pdo->exec("CREATE DATABASE `{$database}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (Throwable $e) {
    fwrite(STDERR, "Could not create the throwaway database: {$e->getMessage()}\n");
    exit(2);
}

$env = [
    'DB_CONNECTION' => 'mysql',
    'DB_HOST' => $host,
    'DB_PORT' => $port,
    'DB_SOCKET' => $socket ?: '',
    'DB_DATABASE' => $database,
    'DB_USERNAME' => $username,
    'DB_PASSWORD' => $password,
    /* PRODUCTION'S CACHE STORE. `array` is per-process and would make this unmeasurable. */
    'CACHE_STORE' => 'database',
    'DB_CACHE_TABLE' => 'cache',
    /*
     * THE MODEL CACHE MUST NOT LIVE IN THE DATABASE FOR THIS MEASUREMENT, and the reason is the
     * finding that cost this harness three wrong answers.
     *
     * `flushCache()` on a non-taggable store is `Cache::flush()`, and on the DATABASE store that is
     * `DELETE FROM cache` -- a statement that joins whatever transaction is open. So the child's
     * in-transaction flush is not visible to the parent until the child COMMITS, and when it does
     * it deletes the rows the parent cached in the meantime. The in-transaction flush accidentally
     * behaves like a post-commit flush, the race cannot occur, and the requirement's own control
     * can be deleted from the service with every check still green. MEASURED: 5 cache rows before
     * the child's commit, 0 after, with `DB::afterCommit` removed.
     *
     * That is a property of the database store alone. On ANY other store -- file, redis, memcached,
     * array -- the flush is immediate and outside the transaction, the window between it and the
     * commit is real, and a concurrent reader fills it. `file` is used here because it is the one
     * such store that is shared between processes with no extra service to run.
     */
    'MODEL_CACHE_STORE' => getenv('M3C_MODEL_STORE') ?: 'file',
    'APP_ENV' => 'testing',
];

echo "  migrating {$database}\n";

$pairs = [];
foreach ($env as $k => $v) { $pairs[] = escapeshellarg("{$k}={$v}"); }

/* `env` is required: a shell treats a QUOTED `VAR=value` as a command name. */
exec('cd ' . escapeshellarg($root) . ' && env ' . implode(' ', $pairs)
    . ' php artisan migrate --force --no-interaction 2>&1', $out, $code);

if ($code !== 0) {
    fwrite(STDERR, implode("\n", array_slice($out, -12)) . "\n");
    fwrite(STDERR, "migrate failed\n");
    exit(2);
}

foreach ($env as $k => $v) { putenv("{$k}={$v}"); $_ENV[$k] = $v; $_SERVER[$k] = $v; }

$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "\n  --- the post-commit flush, across two processes ---\n";

$user = App\Models\User::forceCreate([
    'name' => 'Cache Probe', 'email' => 'cacheprobe@example.test',
    'password' => bcrypt('x'), 'type' => 'admin', 'created_by' => 1,
    'email_verified_at' => now(),
]);

$store = App\Models\Store::create([
    'name' => 'Cache Store', 'email' => 'cachestore@example.test', 'slug' => 'cache-store',
    'theme_id' => 'stylique', 'created_by' => $user->id, 'is_active' => 1, 'default_language' => 'en',
]);

App\Models\ThemeCustomize::create([
    'store_id' => $store->id, 'theme_id' => 'stylique', 'name' => 'blog_title', 'value' => 'OLD',
]);

/* A second key, so the cached pluck covers more than one row -- the realistic shape. */
App\Models\ThemeCustomize::create([
    'store_id' => $store->id, 'theme_id' => 'stylique', 'name' => 'footer_description', 'value' => 'SEED',
]);

premise('the seeded value is readable', getThemeSetting($store->id, 'stylique')['blog_title'] === 'OLD');

$sem = sys_get_temp_dir() . '/m3c-cacheflush-' . getmypid();
foreach (['.ready', '.go', '.done', '.written'] as $s) { @unlink($sem . $s); }

$childEnv = $env;
$cmd = 'cd ' . escapeshellarg($root) . ' && env '
    . escapeshellarg('M3C_ENV=' . json_encode($childEnv)) . ' '
    . escapeshellarg('M3C_SEM=' . $sem) . ' '
    . escapeshellarg('M3C_STORE=' . $store->id) . ' '
    . escapeshellarg('M3C_USER=' . $user->id) . ' '
    . 'php ' . escapeshellarg(__FILE__) . ' --child > ' . escapeshellarg($sem . '.log') . ' 2>&1 &';

proc_close(proc_open($cmd, [], $pipes));

$waited = 0;
while (! file_exists($sem . '.ready')) {
    usleep(20000);
    clearstatcache(true, $sem . '.ready');
    if (($waited += 20000) > 60000000) { break; }
}

premise('the child reached its uncommitted state', file_exists($sem . '.ready'));

$written = file_exists($sem . '.written') ? file_get_contents($sem . '.written') : 'MISSING';
premise('the child\'s write was accepted by the gate (not ' . $written . ')',
    $written !== 'REFUSED' && $written !== 'MISSING');

/* CHECK 1. The parent is a genuinely separate reader: it must NOT see the uncommitted row. */
$duringRaw = DB::table('theme_customizes')->where('store_id', $store->id)
    ->where('name', 'blog_title')->value('value');

check('a second connection cannot see the uncommitted write', $duringRaw === 'OLD',
    "read '{$duringRaw}' -- if this is NEW the child is not isolated and nothing below is a race");

/* CHECK 2. THE CONCURRENT READER re-populates the cache with the pre-commit value. This is the
 * window the post-commit flush exists to close. */
$duringCached = getThemeSetting($store->id, 'stylique')['blog_title'] ?? null;

check('the concurrent reader reads the pre-commit value', $duringCached === 'OLD',
    "read '{$duringCached}'");

/*
 * PREMISE, AND THE ONE THIS HARNESS FIRST GOT WRONG -- TWICE, in two different ways.
 *
 * Reading 'OLD' above proves only that the child's write is invisible. It does NOT prove the reader
 * CACHED it, and with nothing cached there is no stale entry for the post-commit flush to clear, so
 * every check below passes whether or not the flush exists. The first version of this file asserted
 * the requirement without this premise and reported 4 checks, 0 failed with the post-commit flush
 * DELETED from the service.
 *
 * The SECOND attempt proved it by raw-updating a different key and re-reading, on the theory that a
 * different row could not collide with the child's locks. That was wrong: `updateOrCreate` takes
 * next-key locks over the index range it scans, the parent's UPDATE blocked, the child hit its own
 * 30s timeout and ROLLED BACK, and the harness then reported two failures describing a cache
 * problem that had not happened. A harness that touches the table the child has open is measuring
 * the lock manager, not the cache.
 *
 * This version touches nothing. It asks the only question that matters -- did the read come from
 * the cache? -- by COUNTING QUERIES: a repeat of the same read must issue no `theme_customizes`
 * SELECT at all.
 */
DB::flushQueryLog();
DB::enableQueryLog();

getThemeSetting($store->id, 'stylique');

$replayQueries = count(array_filter(DB::getQueryLog(),
    static fn (array $q): bool => str_contains($q['query'], 'theme_customizes')));

DB::disableQueryLog();
DB::flushQueryLog();

premise('the concurrent read was actually CACHED (a repeat issues no query; saw '
    . $replayQueries . ')', $replayQueries === 0);

touch($sem . '.go');

$waited = 0;
while (! file_exists($sem . '.done')) {
    usleep(20000);
    clearstatcache(true, $sem . '.done');
    if (($waited += 20000) > 60000000) { break; }
}

premise('the child committed', file_exists($sem . '.done')
    && file_get_contents($sem . '.done') !== 'TIMEOUT');

/* CHECK 3. The row really is committed now -- so a stale answer below is the CACHE, not the row. */
$afterRaw = DB::table('theme_customizes')->where('store_id', $store->id)
    ->where('name', 'blog_title')->value('value');

check('the committed row holds the new value', $afterRaw === 'NEW', "read '{$afterRaw}'");

/* CHECK 4. THE REQUIREMENT. */
$afterCached = getThemeSetting($store->id, 'stylique')['blog_title'] ?? null;

check('REQUIREMENT 4: the storefront read sees the new value after commit', $afterCached === 'NEW',
    "read '{$afterCached}' -- the cache entry this process stored before the commit survived it. "
    . 'The in-transaction lifecycle flush is not sufficient; the post-commit flush is what closes '
    . 'this window');

if (getenv('M3C_KEEP') === '1') { echo "\n  child log: {$sem}.log\n"; }
else { foreach (['.ready', '.go', '.done', '.written', '.log'] as $s) { @unlink($sem . $s); } }

try { $pdo->exec("DROP DATABASE IF EXISTS `{$database}`"); } catch (Throwable $e) {}

printf("\n  %d checks, %d failed, %d inconclusive\n", $checks, $failures, $inconclusive);

exit($inconclusive > 0 ? 3 : ($failures > 0 ? 1 : 0));
