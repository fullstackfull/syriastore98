<?php

/**
 * P01-E3 / P1-INV-05 / GAP-016 -- concurrent checkout of the LAST UNIT cannot oversell,
 * verified on REAL MySQL/MariaDB with genuinely simultaneous processes.
 * =================================================================================================
 *
 * WHY THIS SCRIPT HAD TO EXIST. `14-PHASE-01-ACCEPTANCE-CRITERIA.md` §E row E3 does not say
 * "concurrent checkout cannot oversell". It says it is shown by "`P1-INV-05`, **on MySQL** (SQLite
 * cannot show it)". That parenthesis is the whole requirement: the criterion names the engine.
 *
 * `tests/Feature/Commerce/OrderStockConcurrencyTest` proves the property on SQLite and proves the
 * MECHANISM from the query log. It cannot prove the criterion, for two structural reasons that no
 * amount of care inside PHPUnit removes:
 *
 *   - `phpunit.xml` runs SQLite `:memory:`. An in-memory SQLite database is private to one
 *     connection, so there is no second session to race against -- the suite simulates ten callers
 *     by handing them all a stale read and then issuing ten sequential statements.
 *   - PHPUnit is single-threaded. Even against a server, ten "concurrent" checkouts in one process
 *     are ten statements in a row. A row lock that is taken and released between them is never
 *     actually contended, so "the lock works" is asserted and not measured.
 *
 * So the gap register's next action for `GAP-016` reads: "Add a verify-last-unit-oversell-on-mysql
 * .php to the existing harness and run it on real MySQL." This is that script.
 *
 * WHAT MAKES IT DIFFERENT FROM ITS SIBLINGS. The other concurrency verifiers in this directory
 * (`verify-cart-reconciliation-concurrency-on-mysql.php`,
 * `verify-pos-sale-claim-concurrency-on-mysql.php`) open two NAMED CONNECTIONS in one process.
 * That makes two real database sessions, which is the right instrument for a check-then-act window
 * you want to step through deliberately. It is the wrong instrument here, because stepping through
 * it is exactly what the criterion forbids: it would again serialise the statements by hand.
 *
 * This script therefore FORKS. Ten child processes, each with its own PDO connection, each blocking
 * on a shared barrier until all ten are connected and ready, then all firing their guarded UPDATE
 * with no coordination whatever. The engine -- not this script -- decides the order. §C below
 * measures that they really did overlap rather than taking it on trust.
 *
 * WHAT IS BEING VERIFIED. `App\Services\ProductStockService` is called through its real public
 * methods; nothing is reimplemented. Its own docblock states the claim this script tests:
 *
 *     UPDATE products SET product_stock = product_stock - ? WHERE id = ? AND product_stock >= ?
 *
 *     "The row lock is taken and released by that statement, and 'zero rows affected' is the
 *      authoritative answer that stock was insufficient."
 *
 * §B proves the harness can SEE the defect by racing the read-subtract-write that the service
 * replaced -- a script that only ever observes a pass cannot distinguish a working guard from a
 * broken instrument. §D proves a row lock is genuinely taken, by contending one. §E proves the
 * backorder behaviour the service deliberately preserves is still preserved, so this script cannot
 * be read as having quietly tightened a merchant-facing feature.
 *
 * HOW TO RUN. Needs a throwaway MySQL or MariaDB. It creates and drops its OWN database and
 * refuses a name that does not say it is throwaway, like its siblings:
 *
 *     apt-get install -y mariadb-server && service mariadb start
 *     php tests/Support/Security/bin/verify-last-unit-oversell-on-mysql.php
 *
 * NEVER point FRESH_MYSQL_DATABASE at the development or production server.
 *
 * PRECONDITIONS, stated rather than assumed: a reachable server, and the `pcntl` extension. Both
 * are checked below and the script exits 2 -- not 0 and not 1 -- if either is missing, because a
 * verification that could not run has neither passed nor failed.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ProductStockService;
use Illuminate\Support\Facades\DB;

$checks = 0;
$failures = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $checks, $failures;
    $checks++;
    if ($ok) {
        echo "  ok    {$what}\n";
    } else {
        $failures++;
        echo "  FAIL  {$what}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
    }
}

/* ---------------------------------------------------------------- preconditions */

if (! function_exists('pcntl_fork')) {
    fwrite(STDERR, "PRECONDITION NOT MET: the pcntl extension is absent, so this script cannot\n");
    fwrite(STDERR, "create genuinely simultaneous processes. That is the one thing it exists to do,\n");
    fwrite(STDERR, "and a same-process approximation would be the SQLite test again with a MySQL\n");
    fwrite(STDERR, "connection string. Install php-pcntl (CLI only) and re-run.\n");
    exit(2);
}

$database = getenv('FRESH_MYSQL_DATABASE') ?: 'last_unit_oversell_verify';

if (! preg_match('/(fresh|verify)/i', $database)) {
    fwrite(STDERR, "Refusing to run against a database whose name says nothing about being throwaway: {$database}\n");
    exit(2);
}

$socket = getenv('FRESH_MYSQL_SOCKET');
if ($socket === false) {
    foreach (['/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock', '/var/lib/mysql/mysql.sock'] as $candidate) {
        if (file_exists($candidate)) {
            $socket = $candidate;
            echo "using the unix socket at {$candidate} (set FRESH_MYSQL_SOCKET='' to force TCP)\n";
            break;
        }
    }
}

$connection = [
    'driver' => 'mysql',
    'host' => getenv('FRESH_MYSQL_HOST') ?: '127.0.0.1',
    'port' => getenv('FRESH_MYSQL_PORT') ?: '3306',
    'unix_socket' => $socket ?: '',
    'database' => $database,
    'username' => getenv('FRESH_MYSQL_USERNAME') ?: 'root',
    'password' => getenv('FRESH_MYSQL_PASSWORD') ?: '',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    /* `strict => false` MATCHES PRODUCTION (config/database.php). It is deliberate here and not an
       oversight: CLAUDE.md records that strict mode REFUSES writes production accepts, so a
       harness in strict mode would be measuring a server this application never runs on. */
    'strict' => false,
];

$bootstrap = $connection;
$bootstrap['database'] = null;
config(['database.connections.fresh_bootstrap' => $bootstrap]);
config(['database.connections.fresh' => $connection]);
config(['database.connections.fresh_b' => $connection]);
config(['database.default' => 'fresh']);

/* THE MODEL CACHE IS POINTED AT THE DATABASE STORE, WHICH IS PRODUCTION'S (.env.example says
   CACHE_STORE=database), NOT the suite's `array`. 79 of the 95 models are `Cachable`, this one
   included, and a cache that served a pre-decrement `product_stock` would make the remaining-stock
   assertions below read the wrong number. The suite's array store cannot be shared between forked
   children at all, so using it here would have silently given every child its own empty cache --
   an artefact of the instrument, which is the class of false reading this directory exists to
   avoid. */
config(['cache.default' => 'database']);
config(['cache.stores.database.connection' => 'fresh']);

try {
    DB::connection('fresh_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
    DB::connection('fresh_bootstrap')->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4");
} catch (Throwable $e) {
    fwrite(STDERR, "PRECONDITION NOT MET: could not create a throwaway database -- {$e->getMessage()}\n");
    exit(2);
}

$server = DB::connection('fresh')->selectOne('SELECT VERSION() AS v')->v;
echo "server : {$server}\n";
echo "database: {$database}\n\n";

try {
    /* The narrowest schema that the real service touches. Deliberately NOT `migrate:fresh`: the
       full migration set drags in the whole application and the columns under test would be buried
       in it, while the point of this script is that these four columns and the engine are the
       entire mechanism. `product_stock` is `INT` SIGNED exactly as the real schema has it -- the
       service's docblock names that signedness as half the original defect, so a harness that made
       it UNSIGNED would have the engine refuse the overshoot that §B must be able to produce. */
    DB::connection('fresh')->statement('
        CREATE TABLE products (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            store_id BIGINT UNSIGNED NULL,
            name VARCHAR(191) NULL,
            product_stock INT NOT NULL DEFAULT 0,
            stock_order_status VARCHAR(32) NULL,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL
        ) ENGINE=InnoDB
    ');
    DB::connection('fresh')->statement('
        CREATE TABLE product_variants (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            product_id BIGINT UNSIGNED NULL,
            store_id BIGINT UNSIGNED NULL,
            name VARCHAR(191) NULL,
            stock INT NOT NULL DEFAULT 0,
            stock_order_status VARCHAR(32) NULL,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL
        ) ENGINE=InnoDB
    ');
    DB::connection('fresh')->statement('
        CREATE TABLE cache (
            `key` VARCHAR(255) NOT NULL PRIMARY KEY,
            `value` MEDIUMTEXT NOT NULL,
            expiration INT NOT NULL
        ) ENGINE=InnoDB
    ');
    DB::connection('fresh')->statement('
        CREATE TABLE cache_locks (
            `key` VARCHAR(255) NOT NULL PRIMARY KEY,
            owner VARCHAR(255) NOT NULL,
            expiration INT NOT NULL
        ) ENGINE=InnoDB
    ');

    $resetProduct = function (int $stock, string $status = 'not_allow'): int {
        DB::connection('fresh')->table('products')->truncate();
        DB::connection('fresh')->table('cache')->truncate();

        return (int) DB::connection('fresh')->table('products')->insertGetId([
            'store_id' => 1,
            'name' => 'The last unit',
            'product_stock' => $stock,
            'stock_order_status' => $status,
        ]);
    };

    $stockOf = fn (int $id): ?int => DB::connection('fresh')->table('products')->where('id', $id)->value('product_stock');

    // ───────────────────────────────────────────────────── [A] premise
    echo "[A] PREMISE -- the fixture is the case the criterion names\n";

    $productId = $resetProduct(5);
    check('the product holds exactly 5 units', $stockOf($productId) === 5, 'stock=' . var_export($stockOf($productId), true));
    check(
        "the product FORBIDS ordering below stock (stock_order_status = 'not_allow')",
        DB::connection('fresh')->table('products')->where('id', $productId)->value('stock_order_status') === 'not_allow',
        'without this the service permits a backorder BY DESIGN and the whole oversell question is moot'
    );
    check(
        'product_stock is a SIGNED integer on this server, so an overshoot CAN be stored',
        str_contains(
            strtolower((string) DB::connection('fresh')->selectOne(
                'SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$database, 'products', 'product_stock']
            )->t),
            'int'
        ) && ! str_contains(
            strtolower((string) DB::connection('fresh')->selectOne(
                'SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$database, 'products', 'product_stock']
            )->t),
            'unsigned'
        ),
        'if the engine refused a negative value, §B could not demonstrate the defect and a pass in '
        . '§C would be the column doing the work rather than the guard'
    );
    check(
        'the table is InnoDB, so it has row-level locking at all',
        strtolower((string) DB::connection('fresh')->selectOne(
            'SELECT ENGINE AS e FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$database, 'products']
        )->e) === 'innodb'
    );

    /* ────────────────────────────────────────── the barrier the children share
     *
     * A file barrier, not a database one. Each child writes a ready file, the parent waits for all
     * ten, then writes GO. Doing this through the database would make the barrier itself contend
     * for the engine the measurement is about -- the children would be queueing on `cache_locks`
     * at the exact moment they are supposed to be firing at `products`. */
    $barrierDir = sys_get_temp_dir() . '/last-unit-oversell-' . getmypid();

    /**
     * Fork $n children. Each runs $work(), which returns a short string recorded as that child's
     * outcome. Children connect AFTER forking and block on the barrier before doing anything.
     *
     * @return array{outcomes: string[], firedAt: float[]}
     */
    $race = function (int $n, callable $work) use ($barrierDir): array {
        @mkdir($barrierDir, 0700, true);
        array_map('unlink', glob($barrierDir . '/*') ?: []);

        $pids = [];
        for ($i = 0; $i < $n; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                fwrite(STDERR, "pcntl_fork() failed on child {$i}\n");
                exit(2);
            }

            if ($pid === 0) {
                /* CHILD. The parent's PDO handle was inherited across the fork and MUST NOT be
                   used: two processes writing to one MySQL socket desynchronise the wire protocol
                   and produce "packets out of order" rather than a stock figure. Purging forces a
                   brand-new connection, which is also what makes each child a real session. */
                DB::purge('fresh');
                DB::purge('fresh_b');

                $outcome = 'child-raised';
                try {
                    DB::connection('fresh')->getPdo();            // connect BEFORE the barrier
                    file_put_contents($barrierDir . '/ready-' . $i, '1');

                    $waited = 0;
                    while (! file_exists($barrierDir . '/GO')) {
                        usleep(200);
                        if (++$waited > 150000) {                  // 30s ceiling
                            break;
                        }
                    }

                    $firedAt = microtime(true);
                    $outcome = (string) $work($i);
                    file_put_contents($barrierDir . '/fired-' . $i, (string) $firedAt);
                } catch (Throwable $e) {
                    $outcome = 'child-raised: ' . substr($e->getMessage(), 0, 120);
                }

                file_put_contents($barrierDir . '/out-' . $i, $outcome);
                exit(0);
            }

            $pids[] = $pid;
        }

        // PARENT. Wait for every child to be connected and parked, then release them together.
        $spins = 0;
        while (count(glob($barrierDir . '/ready-*') ?: []) < $n && $spins++ < 150000) {
            usleep(200);
        }
        file_put_contents($barrierDir . '/GO', '1');

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $outcomes = [];
        $firedAt = [];
        for ($i = 0; $i < $n; $i++) {
            $outcomes[] = (string) @file_get_contents($barrierDir . '/out-' . $i);
            $at = @file_get_contents($barrierDir . '/fired-' . $i);
            if ($at !== false && $at !== '') {
                $firedAt[] = (float) $at;
            }
        }

        return ['outcomes' => $outcomes, 'firedAt' => $firedAt];
    };

    // ──────────────────────────────── [B] the defect, so the instrument is trusted
    echo "\n[B] THE DEFECT THE SERVICE REPLACED, RACED -- proving this harness can SEE an oversell\n";
    echo "    (read-subtract-write, which is what every order writer did before ProductStockService)\n";

    $productId = $resetProduct(5);
    $naive = $race(10, function (int $i) use ($productId) {
        /* Verbatim the shape ProductStockService's docblock quotes as the original:
               $remain = $Product->product_stock - $qty;  $Product->product_stock = $remain;  save() */
        $stock = (int) DB::connection('fresh')->table('products')->where('id', $productId)->value('product_stock');
        usleep(20000);   // the gap between the read and the write, which is the whole defect
        DB::connection('fresh')->table('products')->where('id', $productId)->update(['product_stock' => $stock - 1]);

        return 'wrote:' . ($stock - 1);
    });

    $naiveRemaining = $stockOf($productId);
    check(
        'all ten children ran',
        count(array_filter($naive['outcomes'], fn ($o) => str_starts_with($o, 'wrote:'))) === 10,
        implode(' | ', array_slice($naive['outcomes'], 0, 4))
    );
    check(
        'THE LOST UPDATE IS REAL ON THIS SERVER: ten lost-update checkouts of five units leave MORE '
        . 'than zero, so stock was sold that the shop did not have',
        $naiveRemaining !== null && $naiveRemaining > 0,
        'remaining=' . var_export($naiveRemaining, true) . ' -- if this is 0 the children did not '
        . 'actually overlap and §C proves nothing; re-check the barrier before reading any pass below'
    );
    echo "        measured: ten concurrent read-subtract-write checkouts of 5 units left "
        . var_export($naiveRemaining, true) . " in stock instead of 0.\n";

    // ─────────────────────────────── [C] the shipped guard, ten simultaneous processes
    echo "\n[C] THE SHIPPED GUARD -- ten TRULY SIMULTANEOUS processes take the last five units\n";

    $productId = $resetProduct(5);
    $guarded = $race(10, function (int $i) use ($productId) {
        /* The REAL service, through its real public method. Nothing is reimplemented, and no
           transaction is opened -- the order writers call it outside one, and §D records what
           happens when a caller does wrap it. */
        $product = Product::query()->whereKey($productId)->first();
        $result = (new ProductStockService)->decrementProductStock($product, 1);

        return $result['reason'];
    });

    $applied = count(array_filter($guarded['outcomes'], fn ($o) => $o === ProductStockService::APPLIED));
    $refused = count(array_filter($guarded['outcomes'], fn ($o) => $o === ProductStockService::INSUFFICIENT));
    $raised = array_values(array_filter($guarded['outcomes'], fn ($o) => str_starts_with($o, 'child-raised')));
    $remaining = $stockOf($productId);

    check('no child raised', $raised === [], implode(' | ', array_slice($raised, 0, 2)));
    check("exactly 5 checkouts APPLIED", $applied === 5, "applied={$applied}");
    check("exactly 5 checkouts were REFUSED as insufficient", $refused === 5, "refused={$refused}");
    check('the five refusals are the service\'s own reason, not an exception', $applied + $refused === 10,
        'outcomes: ' . implode(' | ', array_unique($guarded['outcomes'])));
    check('stock finishes at exactly 0', $remaining === 0, 'remaining=' . var_export($remaining, true));
    check('stock never went NEGATIVE', $remaining !== null && $remaining >= 0, 'remaining=' . var_export($remaining, true));

    /* THE OVERLAP IS CORROBORATED HERE, AND PROVEN IN §B.
     *
     * If the barrier had failed and the children had run one after another, every assertion above
     * would still pass -- and would be the SQLite test wearing a MySQL connection string. So
     * simultaneity has to be established, not hoped for.
     *
     * §B IS THE PROOF, and it is a hard assertion already: ten sequential read-subtract-write
     * checkouts of five units leave exactly 0, while ten SIMULTANEOUS ones lose updates and leave
     * more. §B requires the oversell, so a barrier that silently serialised the children would
     * fail §B before §C was ever reached.
     *
     * This check is corroboration with a deliberately GENEROUS bound, and the bound is generous
     * because of a measurement: the clean run spread 0.5ms, but the mutated run -- where all ten
     * children win their UPDATE instead of five being refused immediately -- spread 250.3ms on the
     * same machine, because ten children doing real work contend for CPU and for the row. A
     * threshold tight enough to flake on that would make this script's own verdict unreliable,
     * which is a worse defect than the looser bound: an evidence script that cries wolf gets
     * re-run until it is green, and that habit is what this directory exists to prevent. 1500ms
     * still separates "released together" from "ran one after another" by more than an order of
     * magnitude, and the measured value is printed either way so a reader never has to infer it. */
    $spread = $guarded['firedAt'] === [] ? null : (max($guarded['firedAt']) - min($guarded['firedAt'])) * 1000;
    check(
        'the ten children really were released together (the barrier worked)',
        $spread !== null && $spread < 1500,
        'spread=' . ($spread === null ? 'unknown' : round($spread, 2) . 'ms') . ' between the first and '
        . 'last child firing. A spread this large means they were NOT simultaneous and §C has not '
        . 'tested what it claims -- but read §B first: if §B still shows an oversell, the children '
        . 'did overlap and this is scheduling noise rather than a serialised barrier.'
    );
    echo "        measured: " . count($guarded['firedAt']) . " children fired within "
        . ($spread === null ? '?' : round($spread, 2)) . "ms of each other.\n";

    // ───────────────────────────────────────── [D] the row lock is genuinely taken
    echo "\n[D] THE ROW LOCK -- contended, so 'the UPDATE takes a lock' is measured and not asserted\n";

    $productId = $resetProduct(1);
    $a = DB::connection('fresh');
    $b = DB::connection('fresh_b');
    $b->statement('SET SESSION innodb_lock_wait_timeout = 2');

    $lockMessage = '';
    $a->beginTransaction();
    try {
        $a->table('products')->where('id', $productId)->where('product_stock', '>=', 1)->decrement('product_stock', 1);
        try {
            $b->table('products')->where('id', $productId)->where('product_stock', '>=', 1)->decrement('product_stock', 1);
        } catch (Throwable $e) {
            $lockMessage = $e->getMessage();
        }
    } finally {
        $a->commit();
    }

    check(
        'a second session BLOCKS on the first\'s row lock while its transaction is open',
        str_contains($lockMessage, '1205') || stripos($lockMessage, 'lock wait') !== false,
        $lockMessage === ''
            ? 'NO LOCK WAIT OCCURRED. The second UPDATE went through while the first transaction was '
              . 'still open, which would mean this engine is not taking the row lock the service\'s '
              . 'docblock relies on. Recorded as a failure rather than explained away.'
            : 'measured: ' . substr($lockMessage, 0, 110)
    );
    check(
        'and the blocked statement applied NOTHING, so the lock refused rather than queued-and-sold',
        $stockOf($productId) === 0,
        'remaining=' . var_export($stockOf($productId), true)
    );

    // ──────────────────────────────────── [E] the backorder behaviour is preserved
    echo "\n[E] BACKORDERS ARE STILL PERMITTED -- this script must not read as having tightened them\n";

    $productId = $resetProduct(1, 'notify_customer');
    $backorder = $race(5, function (int $i) use ($productId) {
        $product = Product::query()->whereKey($productId)->first();

        return (new ProductStockService)->decrementProductStock($product, 1)['reason'];
    });

    $boApplied = count(array_filter($backorder['outcomes'], fn ($o) => $o === ProductStockService::APPLIED));
    $boRemaining = $stockOf($productId);

    check(
        'a product configured for BACKORDER still decrements for every caller',
        $boApplied === 5,
        "applied={$boApplied} of 5 -- the service's docblock is explicit that tightening this would "
        . 'silently remove a merchant-facing feature, so a 5-of-5 here is the REQUIRED result'
    );
    check(
        'and it is allowed to go negative, exactly as before',
        $boRemaining === -4,
        'remaining=' . var_export($boRemaining, true) . ' (expected -4: one unit, five takers)'
    );

    // ──────────────────────────────────────────────── [F] the variant path
    echo "\n[F] THE VARIANT PATH -- the same property on product_variants.stock\n";

    DB::connection('fresh')->table('product_variants')->truncate();
    DB::connection('fresh')->table('cache')->truncate();
    $variantId = (int) DB::connection('fresh')->table('product_variants')->insertGetId([
        'product_id' => 1,
        'store_id' => 1,
        'name' => 'last variant',
        'stock' => 5,
        'stock_order_status' => 'not_allow',
    ]);

    $variantRace = $race(10, function (int $i) use ($variantId) {
        $variant = ProductVariant::query()->whereKey($variantId)->first();

        return (new ProductStockService)->decrementVariantStock($variant, 1)['reason'];
    });

    $vApplied = count(array_filter($variantRace['outcomes'], fn ($o) => $o === ProductStockService::APPLIED));
    $vRefused = count(array_filter($variantRace['outcomes'], fn ($o) => $o === ProductStockService::INSUFFICIENT));
    $vRemaining = DB::connection('fresh')->table('product_variants')->where('id', $variantId)->value('stock');

    check("exactly 5 variant checkouts APPLIED", $vApplied === 5, "applied={$vApplied}");
    check("exactly 5 variant checkouts were REFUSED", $vRefused === 5, "refused={$vRefused}");
    check('variant stock finishes at exactly 0', (int) $vRemaining === 0, 'remaining=' . var_export($vRemaining, true));

    // ─────────────────────────── [G] a tampered quantity cannot mint inventory
    echo "\n[G] A TAMPERED QUANTITY CANNOT MINT INVENTORY -- normaliseQuantity(), under concurrency\n";

    $productId = $resetProduct(5);
    $minting = $race(6, function (int $i) use ($productId) {
        $product = Product::query()->whereKey($productId)->first();
        // Alternate the three values that must all be refused outright.
        $qty = [-5, 0, '-3', 'abc', -1, '0'][$i];

        return (new ProductStockService)->decrementProductStock($product, $qty)['reason'];
    });

    $skipped = count(array_filter($minting['outcomes'], fn ($o) => $o === ProductStockService::SKIPPED));
    check(
        'every negative, zero or non-numeric quantity is SKIPPED rather than applied',
        $skipped === 6,
        "skipped={$skipped} of 6 -- outcomes: " . implode(' | ', array_unique($minting['outcomes']))
    );
    check(
        'and stock is untouched at 5, so no caller INCREASED it',
        $stockOf($productId) === 5,
        'remaining=' . var_export($stockOf($productId), true) . ' -- a negative quantity reaching '
        . 'decrement() would have ADDED stock, which is how a tampered cart could mint inventory'
    );

    @array_map('unlink', glob($barrierDir . '/*') ?: []);
    @rmdir($barrierDir);
} finally {
    try {
        DB::connection('fresh')->disconnect();
        DB::connection('fresh_b')->disconnect();
        DB::connection('fresh_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
        echo "\n(dropped throwaway database {$database})\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "Could not drop the throwaway database: {$e->getMessage()}\n");
    }
}

echo "\n" . str_repeat('-', 78) . "\n";
echo "{$checks} checks, {$failures} failures\n";

exit($failures === 0 ? 0 : 1);
