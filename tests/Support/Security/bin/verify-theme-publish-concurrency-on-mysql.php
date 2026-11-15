#!/usr/bin/env php
<?php

/**
 * PHASE 03 M3-D — THE PUBLISHING RACES, ON A REAL ENGINE, WITH TWO REAL PROCESSES.
 * =================================================================================================
 *
 * WHY NONE OF THIS CAN BE A FEATURE TEST, and it is not a preference. Every claim below is about
 * what a SECOND principal sees while a FIRST one holds a transaction open. `RefreshDatabase` wraps
 * each test in one transaction on one connection, so a Feature test cannot have two. Worse, it
 * cannot even fail honestly: a single-connection reader sees its own uncommitted writes, so a
 * "the reader never sees half a publish" assertion passes whether or not the control exists. M3-C
 * measured three such false greens before finding the fourth explanation.
 *
 * So: two processes, file semaphores rather than sleeps (no timing to lose), and production's
 * `sql_mode`.
 *
 * RUN WITH PRODUCTION'S SQL MODE. `config/database.php:55` ships `'strict' => false`, so production
 * runs `NO_ENGINE_SUBSTITUTION` and silently coerces what a strict connection refuses. Measuring
 * strict behaviour would be measuring a server this project does not deploy.
 *
 * AND WITH A MODEL CACHE THAT IS NOT IN THE DATABASE. Measured at M3-C: `flushCache()` on a
 * non-taggable store is `Cache::flush()`, and on the DATABASE store that is `DELETE FROM cache` --
 * a statement which JOINS THE OPEN TRANSACTION, so the in-transaction flush accidentally behaves
 * like a post-commit one and the race cannot occur. `MODEL_CACHE_STORE=file` is the configuration
 * in which the control is load-bearing, which is the one worth testing.
 *
 * THE SCENARIOS, each the directive's words:
 *
 *   1. publisher vs publisher          -- one wins, one is told, and the history has exactly one row
 *   2. publisher vs storefront reader  -- the reader never sees a partially published configuration
 *   3. draft save vs publish           -- a draft save is not blocked by an open publish
 *   4. failed transaction rollback     -- projection, pointer and history survive a failure intact
 *   5. cache repopulation before commit-- the stale entry a concurrent reader stored is cleared
 *   6. publishing an old version       -- a rollback under contention is still serialised
 *
 * USAGE
 *   php tests/Support/Security/bin/verify-theme-publish-concurrency-on-mysql.php
 *       [--socket=/run/mysqld/mysqld.sock | --host= --port=] [--user=] [--pass=]
 *
 * EXIT 0 all passed | 1 a check failed | 2 could not inspect | 3 a premise did not hold
 */

$root = dirname(__DIR__, 4);

/* ============================================================================== the child process
 *
 * One process, one scenario, driven by argv. It opens a transaction, does its work, signals, waits
 * for the parent, and then either commits or rolls back.
 */
if (in_array('--child', $argv, true)) {
    require $root . '/vendor/autoload.php';

    foreach (json_decode(getenv('M3D_ENV'), true) as $k => $v) {
        putenv("{$k}={$v}");
        $_ENV[$k] = $v;
        $_SERVER[$k] = $v;
    }

    $app = require $root . '/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    $sem = getenv('M3D_SEM');
    $scenario = getenv('M3D_SCENARIO');
    $storeId = (int) getenv('M3D_STORE');
    $userId = (int) getenv('M3D_USER');
    $versionId = (int) getenv('M3D_VERSION');
    $expect = getenv('M3D_EXPECT');

    $user = App\Models\User::find($userId);
    $service = app(App\Services\Theme\ThemePublishingService::class);

    $waitFor = static function (string $file) use ($sem): bool {
        $waited = 0;
        while (! file_exists($sem . $file)) {
            usleep(20000);
            clearstatcache(true, $sem . $file);
            if (($waited += 20000) > 60000000) {
                return false;
            }
        }

        return true;
    };

    try {
        if ($scenario === 'publish-then-wait') {
            /*
             * The publish runs INSIDE an outer transaction the child controls, so the parent can
             * observe the world between the writes and the commit. That is the window every
             * control in `ThemePublishingService` exists for, and the only way to stand in it.
             */
            Illuminate\Support\Facades\DB::beginTransaction();

            $result = $service->publish($storeId, 'stylique', $versionId, $user,
                $expect === '' ? null : (int) $expect);

            file_put_contents($sem . '.result',
                $result === null ? 'REFUSED: ' . (string) $service->refusal() : 'ACCEPTED');

            touch($sem . '.ready');

            if (! $waitFor('.go')) {
                Illuminate\Support\Facades\DB::rollBack();
                file_put_contents($sem . '.done', 'TIMEOUT');
                exit(3);
            }

            if (file_exists($sem . '.abort')) {
                Illuminate\Support\Facades\DB::rollBack();
                file_put_contents($sem . '.done', 'ROLLED-BACK');
                exit(0);
            }

            Illuminate\Support\Facades\DB::commit();
            file_put_contents($sem . '.done', 'COMMITTED');
            exit(0);
        }

        if ($scenario === 'publish-racing') {
            /* No outer transaction and no waiting: both racers simply publish, and the engine plus
             * the service decide who wins. The parent is the other racer. */
            if (! $waitFor('.go')) {
                exit(3);
            }

            $result = $service->publish($storeId, 'stylique', $versionId, $user,
                $expect === '' ? null : (int) $expect);

            file_put_contents($sem . '.child-result',
                $result === null ? 'REFUSED: ' . (string) $service->refusal() : 'ACCEPTED');
            exit(0);
        }

        fwrite(STDERR, "unknown scenario {$scenario}\n");
        exit(2);
    } catch (Throwable $e) {
        file_put_contents($sem . '.child-result', 'THREW: ' . get_class($e) . ': ' . $e->getMessage());
        try { Illuminate\Support\Facades\DB::rollBack(); } catch (Throwable $x) {}
        file_put_contents($sem . '.done', 'THREW');
        exit(1);
    }
}

/* =========================================================================== the parent process */

require $root . '/vendor/autoload.php';

$opt = ['host' => '127.0.0.1', 'port' => '3306', 'user' => 'root', 'pass' => '', 'socket' => null];

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z]+)=(.*)$/', $arg, $m) && array_key_exists($m[1], $opt)) {
        $opt[$m[1]] = $m[2];
        continue;
    }
    fwrite(STDERR, "usage: php " . basename(__FILE__) . " [--socket=|--host= --port=] [--user=] [--pass=]\n");
    exit(2);
}

if ($opt['socket'] === null) {
    foreach (['/run/mysqld/mysqld.sock', '/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock'] as $c) {
        if (file_exists($c)) {
            $opt['socket'] = $c;
            echo "  using the unix socket at {$c}\n";
            break;
        }
    }
}

$database = 'p3m3d_publish_' . getmypid();

$checks = 0; $failures = 0; $inconclusive = 0;

function check(string $what, bool $ok, string $detail = ''): void {
    global $checks, $failures;
    $checks++;
    echo $ok ? "  ok    {$what}\n" : "  FAIL  {$what}" . ($detail !== '' ? "\n          -- {$detail}" : '') . "\n";
    if (! $ok) { $failures++; }
}

function premise(string $what, bool $ok, string $detail = ''): void {
    global $inconclusive;
    if ($ok) { echo "  ok    premise: {$what}\n"; return; }
    echo "  INCONCLUSIVE  premise failed: {$what}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
    $inconclusive++;
}

try {
    $dsn = $opt['socket'] !== null
        ? "mysql:unix_socket={$opt['socket']}"
        : "mysql:host={$opt['host']};port={$opt['port']}";
    $server = new PDO($dsn, $opt['user'], $opt['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) {
    fwrite(STDERR, "No MySQL/MariaDB server reachable: {$e->getMessage()}\n");
    exit(2);
}

$server->exec("DROP DATABASE IF EXISTS `{$database}`");
$server->exec("CREATE DATABASE `{$database}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$env = [
    'DB_CONNECTION' => 'mysql',
    'DB_HOST' => $opt['host'],
    'DB_PORT' => $opt['port'],
    'DB_SOCKET' => $opt['socket'] ?: '',
    'DB_DATABASE' => $database,
    'DB_USERNAME' => $opt['user'],
    'DB_PASSWORD' => $opt['pass'],
    'CACHE_STORE' => 'database',
    'DB_CACHE_TABLE' => 'cache',
    /* See the docblock: the DATABASE store's flush is transactional, so the race it is supposed to
     * close cannot occur there and the control cannot be tested. `file` is shared between processes
     * and not transactional. */
    'MODEL_CACHE_STORE' => 'file',
    'APP_ENV' => 'testing',
];

echo "  migrating {$database}\n";

$pairs = [];
foreach ($env as $k => $v) { $pairs[] = escapeshellarg("{$k}={$v}"); }

/* `env` is required: a shell treats a QUOTED `VAR=value` as a command name. Four sibling harnesses
 * record the same trap. */
exec('cd ' . escapeshellarg($root) . ' && env ' . implode(' ', $pairs)
    . ' php artisan migrate --force --no-interaction 2>&1', $out, $code);

if ($code !== 0) {
    fwrite(STDERR, implode("\n", array_slice($out, -14)) . "\n");
    fwrite(STDERR, "migrate failed\n");
    $server->exec("DROP DATABASE IF EXISTS `{$database}`");
    exit(2);
}

foreach ($env as $k => $v) { putenv("{$k}={$v}"); $_ENV[$k] = $v; $_SERVER[$k] = $v; }

$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Store;
use App\Models\ThemeCustomize;
use App\Models\ThemePublicationEvent;
use App\Models\ThemePublishedVersion;
use App\Models\ThemeVersion;
use App\Models\User;
use App\Services\Theme\ThemeConfigurationService;
use App\Services\Theme\ThemePublishingService;
use Illuminate\Support\Facades\DB;

printf("  sql_mode: %s\n", DB::selectOne('SELECT @@SESSION.sql_mode AS m')->m);
printf("  model cache store: %s\n", config('laravel-model-caching.store') ?: '(default)');

/*
 * THE MODEL CACHE IS EMPTIED FIRST, AND THIS HARNESS WAS WRONG WITHOUT IT.
 *
 * MEASURED: run twice back to back and the results ALTERNATED -- 2 failed, then 0 failed, with 607
 * files left in `storage/framework/cache`. `MODEL_CACHE_STORE=file` is shared between processes
 * (which is the point) and also between RUNS (which is not): the throwaway database name carries
 * the process id, but laravel-model-caching's keys are built from the model and the query, not from
 * the database. So a fresh run's first read was a HIT on a previous run's rows, from a database
 * that no longer exists.
 *
 * A harness that alternates is worse than one that fails, because half its runs look like evidence.
 * It could as easily have made a BROKEN control look fine -- the stale hit happened to carry the
 * value the assertion wanted.
 */
$cacheStore = Illuminate\Support\Facades\Cache::store(config('laravel-model-caching.store'));
$cacheStore->clear();

$leftovers = 0;
$cacheDir = storage_path('framework/cache/data');

if (is_dir($cacheDir)) {
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cacheDir, FilesystemIterator::SKIP_DOTS)) as $f) {
        /* `.gitkeep` is Laravel's directory placeholder, not a cache entry. Counting it made the
         * premise fail against a cache that was in fact empty -- the harness calling itself
         * contaminated. */
        if ($f->isFile() && ! str_starts_with($f->getFilename(), '.')) { $leftovers++; }
    }
}

printf("  model cache cleared; %d file(s) remain in storage/framework/cache/data\n\n", $leftovers);

premise('the model cache starts empty, so no previous run can be mistaken for this one',
    $leftovers === 0, "{$leftovers} file(s) survived the clear");

$merchant = User::forceCreate([
    'name' => 'D Publisher', 'email' => 'dpub@example.test', 'password' => bcrypt('x'),
    'type' => 'admin', 'created_by' => 1, 'email_verified_at' => now(),
]);

$store = Store::create([
    'name' => 'D Publish Store', 'email' => 'dps@example.test', 'slug' => 'd-publish-store',
    'theme_id' => 'stylique', 'created_by' => $merchant->id,
    'is_active' => 1, 'default_language' => 'en',
]);

$drafts = new ThemeConfigurationService();
$publisher = new ThemePublishingService();

$sem = sys_get_temp_dir() . '/m3d-publish-' . getmypid();

$clean = static function () use ($sem): void {
    foreach (['.ready', '.go', '.done', '.abort', '.result', '.child-result', '.log'] as $s) {
        @unlink($sem . $s);
    }
};

$spawn = static function (string $scenario, int $versionId, string $expect = '')
        use ($root, $env, $sem, $store, $merchant): void {
    $cmd = 'cd ' . escapeshellarg($root) . ' && env '
        . escapeshellarg('M3D_ENV=' . json_encode($env)) . ' '
        . escapeshellarg('M3D_SEM=' . $sem) . ' '
        . escapeshellarg('M3D_SCENARIO=' . $scenario) . ' '
        . escapeshellarg('M3D_STORE=' . $store->id) . ' '
        . escapeshellarg('M3D_USER=' . $merchant->id) . ' '
        . escapeshellarg('M3D_VERSION=' . $versionId) . ' '
        . escapeshellarg('M3D_EXPECT=' . $expect) . ' '
        . 'php ' . escapeshellarg(__FILE__) . ' --child > ' . escapeshellarg($sem . '.log') . ' 2>&1 &';

    proc_close(proc_open($cmd, [], $pipes));
};

$waitFor = static function (string $file) use ($sem): bool {
    $waited = 0;
    while (! file_exists($sem . $file)) {
        usleep(20000);
        clearstatcache(true, $sem . $file);
        if (($waited += 20000) > 60000000) {
            return false;
        }
    }

    return true;
};

$projection = static function () use ($store): array {
    return ThemeCustomize::withoutGlobalScopes()
        ->where('store_id', $store->id)->where('theme_id', 'stylique')
        ->pluck('value', 'name')->all();
};

$draft = static function (array $settings) use ($drafts, $store, $merchant): ThemeVersion {
    $v = $drafts->saveDraft($store->id, 'stylique', $settings, null, $merchant);

    if ($v === null) {
        fwrite(STDERR, "could not save a draft: " . var_export($drafts->refusal(), true) . "\n");
        exit(2);
    }

    return $v;
};

try {
    /* ================================================== 1. PUBLISHER vs PUBLISHER */

    echo "  --- 1. publisher vs publisher ---\n";

    $v1 = $draft(['footer_description' => 'racer one']);
    $v2 = $draft(['footer_description' => 'racer two']);

    $clean();

    /* Both believe NOTHING is published yet, which is the honest form of the race: two people press
     * Publish on a store that has never been published. */
    $spawn('publish-racing', $v2->id, '0');

    usleep(400000);                 // let the child reach its wait
    touch($sem . '.go');            // release both at once

    $parentResult = $publisher->publish($store->id, 'stylique', $v1->id, $merchant, 0);
    $parentSays = $parentResult === null ? 'REFUSED: ' . (string) $publisher->refusal() : 'ACCEPTED';

    $waitFor('.child-result');
    $childSays = file_exists($sem . '.child-result')
        ? (string) file_get_contents($sem . '.child-result') : 'MISSING';

    printf("    parent: %s\n    child : %s\n", $parentSays, substr($childSays, 0, 90));

    premise('both racers reported an outcome', $childSays !== 'MISSING');

    $accepted = (int) str_starts_with($parentSays, 'ACCEPTED') + (int) str_starts_with($childSays, 'ACCEPTED');

    check('EXACTLY ONE of two concurrent first-publishes is accepted', $accepted === 1,
        "accepted={$accepted}; parent={$parentSays}; child=" . substr($childSays, 0, 120));

    check('the loser is TOLD rather than answering 500',
        str_contains($parentSays, 'REFUSED') || str_contains($childSays, 'REFUSED'),
        "parent={$parentSays}; child=" . substr($childSays, 0, 120));

    check('neither racer threw', ! str_contains($childSays, 'THREW'),
        substr($childSays, 0, 160));

    $pointers = DB::table('theme_published_versions')
        ->where('store_id', $store->id)->where('theme_id', 'stylique')->count();

    check('the live pointer is a single row', (int) $pointers === 1, "found {$pointers}");

    $events = ThemePublicationEvent::historyFor($store->id, 'stylique')->count();

    check('the history records exactly one publication', (int) $events === 1, "found {$events}");

    $live = (int) ThemePublishedVersion::liveFor($store->id, 'stylique')->version_id;
    $proj = $projection()['footer_description'] ?? null;

    check('the projection matches the version the pointer names',
        ($live === $v1->id && $proj === 'racer one') || ($live === $v2->id && $proj === 'racer two'),
        "live={$live} projection=" . var_export($proj, true));

    /* ============================================ 2. PUBLISHER vs STOREFRONT READER */

    echo "\n  --- 2. publisher vs storefront reader ---\n";

    $liveBefore = (string) ($projection()['footer_description'] ?? '');
    $v3 = $draft(['footer_description' => 'mid-flight value']);

    $clean();
    $spawn('publish-then-wait', $v3->id);

    premise('the child reached its uncommitted state', $waitFor('.ready'));
    premise("the child's publish was accepted (" . trim((string) @file_get_contents($sem . '.result')) . ')',
        trim((string) @file_get_contents($sem . '.result')) === 'ACCEPTED');

    $duringRaw = DB::table('theme_customizes')->where('store_id', $store->id)
        ->where('theme_id', 'stylique')->where('name', 'footer_description')->value('value');

    check('a second connection cannot see the uncommitted projection write',
        $duringRaw === $liveBefore,
        "read " . var_export($duringRaw, true) . ", expected " . var_export($liveBefore, true));

    $duringPointer = (int) ThemePublishedVersion::liveFor($store->id, 'stylique')->version_id;

    check('and cannot see the uncommitted pointer move', $duringPointer !== $v3->id,
        "pointer already reads {$duringPointer}");

    $storefront = getThemeSetting($store->id, 'stylique')['footer_description'] ?? null;

    check('THE STOREFRONT NEVER SEES A PARTIALLY PUBLISHED CONFIGURATION',
        $storefront === $liveBefore,
        'the storefront read ' . var_export($storefront, true) . ' while the publish was still '
        . 'open, which is a configuration no version ever described');

    /* The concurrent reader has now cached the pre-commit state -- scenario 5's setup. */
    DB::flushQueryLog();
    DB::enableQueryLog();
    getThemeSetting($store->id, 'stylique');
    $replay = count(array_filter(DB::getQueryLog(),
        static fn (array $q): bool => str_contains($q['query'], 'theme_customizes')));
    DB::disableQueryLog();

    premise('the concurrent read was actually CACHED (a repeat issues no query; saw ' . $replay . ')',
        $replay === 0);

    touch($sem . '.go');
    premise('the child committed', $waitFor('.done')
        && trim((string) @file_get_contents($sem . '.done')) === 'COMMITTED',
        trim((string) @file_get_contents($sem . '.done')));

    check('after the commit the raw row holds the new value',
        DB::table('theme_customizes')->where('store_id', $store->id)->where('theme_id', 'stylique')
            ->where('name', 'footer_description')->value('value') === 'mid-flight value');

    /* ================================= 5. CACHE REPOPULATION BEFORE TRANSACTION COMPLETION */

    echo "\n  --- 5. cache repopulation before the transaction completed ---\n";

    check('the stale entry the concurrent reader stored BEFORE the commit is cleared after it',
        (getThemeSetting($store->id, 'stylique')['footer_description'] ?? null) === 'mid-flight value',
        'the storefront is still serving the entry it cached pre-commit. The in-transaction '
        . 'lifecycle flush is not sufficient; the post-commit flush is what closes this window');

    /* ============================================== 3. DRAFT SAVE vs PUBLISH */

    echo "\n  --- 3. draft save vs an open publish ---\n";

    $v4 = $draft(['footer_description' => 'the fourth']);

    $clean();
    $spawn('publish-then-wait', $v4->id, (string) $v3->id);

    premise('the child holds an open publish', $waitFor('.ready'));

    $started = microtime(true);
    $concurrentDraft = $drafts->saveDraft(
        $store->id, 'stylique', ['footer_description' => 'saved during a publish'], null, $merchant);
    $elapsed = microtime(true) - $started;

    check('a DRAFT SAVE is not blocked by an open publish', $concurrentDraft !== null,
        'refused: ' . var_export($drafts->refusal(), true));
    check('and it did not wait on a lock for more than a second', $elapsed < 1.0,
        sprintf('took %.2fs -- a draft save contending with a publish would make the editor feel '
            . 'broken even though it is correct', $elapsed));

    if ($concurrentDraft !== null) {
        check('the concurrent draft is a DRAFT and did not become live',
            $concurrentDraft->status === ThemeVersion::STATUS_DRAFT);
    }

    touch($sem . '.go');
    $waitFor('.done');

    /* ==================================== 4. A FAILED TRANSACTION CHANGES NOTHING */

    echo "\n  --- 4. a failed publish leaves projection, pointer and history intact ---\n";

    $projBefore = $projection();
    $pointerBefore = (int) ThemePublishedVersion::liveFor($store->id, 'stylique')->version_id;
    $historyBefore = ThemePublicationEvent::historyFor($store->id, 'stylique')->count();

    $v5 = $draft(['footer_description' => 'this publish will be abandoned']);

    $clean();
    touch($sem . '.abort');          // tells the child to roll back instead of committing
    $spawn('publish-then-wait', $v5->id, (string) $pointerBefore);

    premise('the child reached its uncommitted state', $waitFor('.ready'));
    premise('its publish was accepted before being abandoned',
        trim((string) @file_get_contents($sem . '.result')) === 'ACCEPTED');

    touch($sem . '.go');

    premise('the child rolled back', $waitFor('.done')
        && trim((string) @file_get_contents($sem . '.done')) === 'ROLLED-BACK',
        trim((string) @file_get_contents($sem . '.done')));

    (new ThemeCustomize())->flushCache();      // read the TABLE, not a cache, for this comparison

    check('the projection is byte-identical after the abandoned publish',
        $projection() === $projBefore,
        'before=' . json_encode($projBefore) . ' after=' . json_encode($projection()));

    check('the live pointer did not move',
        (int) ThemePublishedVersion::liveFor($store->id, 'stylique')->version_id === $pointerBefore);

    check('the history gained nothing',
        ThemePublicationEvent::historyFor($store->id, 'stylique')->count() === $historyBefore);

    check('the abandoned version is still a draft, not marked published',
        ThemeVersion::ownedBy($store->id, 'stylique', $v5->id)?->status === ThemeVersion::STATUS_DRAFT,
        'status is ' . var_export(ThemeVersion::ownedBy($store->id, 'stylique', $v5->id)?->status, true));

    /* ============================================ 6. PUBLISHING AN OLD VERSION, CONTENDED */

    echo "\n  --- 6. a rollback to an older version, against a concurrent publisher ---\n";

    $liveNow = (int) ThemePublishedVersion::liveFor($store->id, 'stylique')->version_id;

    $clean();
    $spawn('publish-racing', $v5->id, (string) $liveNow);

    usleep(400000);
    touch($sem . '.go');

    $rollback = $publisher->rollback($store->id, 'stylique', $v1->id, $merchant, $liveNow);
    $parentSays = $rollback === null ? 'REFUSED: ' . (string) $publisher->refusal() : 'ACCEPTED';

    $waitFor('.child-result');
    $childSays = (string) @file_get_contents($sem . '.child-result');

    printf("    parent rollback: %s\n    child publish  : %s\n",
        $parentSays, substr($childSays, 0, 90));

    $acceptedBoth = (int) str_starts_with($parentSays, 'ACCEPTED') + (int) str_starts_with($childSays, 'ACCEPTED');

    check('exactly one of a rollback and a concurrent publish is accepted', $acceptedBoth === 1,
        "accepted={$acceptedBoth}");

    check('the pointer and the projection still agree after the contended rollback',
        (function () use ($store, $projection): bool {
            $live = ThemePublishedVersion::liveFor($store->id, 'stylique');
            if ($live === null) { return false; }
            $version = $live->liveVersion();
            if ($version === null) { return false; }
            $expected = $version->settings()['footer_description'] ?? null;

            return ($projection()['footer_description'] ?? null) === $expected;
        })(),
        'the live pointer names a version whose payload does not match the published projection');
} catch (Throwable $e) {
    fwrite(STDERR, "\nABORTED: " . get_class($e) . ': ' . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    $failures++;
} finally {
    if (getenv('M3D_KEEP') === '1') {
        echo "\n  child log: {$sem}.log\n";
    } else {
        $clean();
    }

    try { $server->exec("DROP DATABASE IF EXISTS `{$database}`"); echo "\n  dropped {$database}\n"; }
    catch (Throwable $e) {}
}

printf("\n  %d checks, %d failed, %d inconclusive\n", $checks, $failures, $inconclusive);

exit($inconclusive > 0 ? 3 : ($failures > 0 ? 1 : 0));
