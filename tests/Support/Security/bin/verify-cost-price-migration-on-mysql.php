<?php

/**
 * F58 — VERIFY `products.cost_price` ON A REAL MySQL SERVER. Creates and drops its OWN database.
 * =============================================================================================
 *
 * NOT EXECUTED IN THE SESSION THAT WROTE IT. The container has no mysqld, no mariadbd and no
 * mysql client, so the evidence below is UNCOLLECTED. The migration's behaviour was proven on
 * SQLite (fresh add, idempotent re-run, production-shaped no-op with the column already present,
 * and a no-op rollback that destroyed nothing) and by driving a product create end to end through
 * the real UI. That is not a substitute for this: the project has been bitten twice by SQLite's
 * type affinity hiding a real MySQL defect (P01-SEC-13's INTEGER column silently accepting a
 * 60-char bcrypt hash, and NULLs into NOT NULL columns), and a `decimal(15,2)` is exactly the
 * kind of declaration SQLite reduces to `numeric` and stops checking. Run this where a server
 * exists before anyone calls F58 closed on MySQL.
 *
 * WHAT IT ESTABLISHES, each of which SQLite cannot answer:
 *
 *   1. The migration creates `decimal(15,2) NOT NULL DEFAULT 0.00` -- the EXACT production type
 *      recorded at docs/GATE-F-VERIFIED-SCHEMA.md:382 -- and not a float, a double, or a decimal
 *      with different precision. SQLite reports the column as `numeric` whatever was asked for.
 *   2. The type really enforces its scale: 12.345 stores as 12.35 (MySQL rounds a decimal),
 *      where SQLite would keep 12.345 verbatim.
 *   3. NOT NULL is really enforced: an explicit NULL on INSERT is ER_BAD_NULL_ERROR (1048)
 *      regardless of sql_mode, which is the asymmetry F61's comments turn on.
 *   4. THE PRODUCTION-SHAPED NO-OP. With the column already present as production has it and the
 *      migration row absent, `up()` issues no ALTER and the stored values are untouched. This is
 *      the property the owner's approval depends on -- "مع الحفاظ على البيانات الحالية" -- and the
 *      one that makes the migration safe to carry on a branch that may later reach production.
 *   5. `down()` destroys nothing. An earlier draft of this migration dropped the column whenever
 *      it was present, and against a restored copy of production that rollback took 30.28ms and
 *      destroyed all 45 merchant cost values.
 *
 * usage, against a THROWAWAY server (it issues DROP DATABASE):
 *
 *   COSTPRICE_MYSQL_SOCKET=/path/to/mysqld.sock \
 *   COSTPRICE_MYSQL_DATABASE=costprice_verify \
 *   COSTPRICE_MYSQL_USERNAME=root \
 *   COSTPRICE_MYSQL_PASSWORD= \
 *     php tests/Support/Security/bin/verify-cost-price-migration-on-mysql.php
 *
 * or with COSTPRICE_MYSQL_HOST / COSTPRICE_MYSQL_PORT instead of a socket.
 */

require __DIR__ . '/../../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$checks = 0;
$failures = [];

function ok(string $what): void
{
    global $checks;
    $checks++;
    echo "  ok    {$what}\n";
}

function bad(string $what, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;
    $failures[] = $what . ($detail !== '' ? " -- {$detail}" : '');
    echo "  FAIL  {$what}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
}

function check(bool $condition, string $what, string $detail = ''): void
{
    $condition ? ok($what) : bad($what, $detail);
}

// ---------------------------------------------------------------------------------------
// PRECONDITIONS
// ---------------------------------------------------------------------------------------

$database = getenv('COSTPRICE_MYSQL_DATABASE') ?: 'costprice_verify';

if (! str_contains($database, 'costprice') && ! str_contains($database, 'verify')) {
    fwrite(STDERR, "REFUSING: the database name '{$database}' contains neither 'costprice' nor 'verify'.\n"
        . "This script issues DROP DATABASE. Name the throwaway database accordingly.\n");
    exit(2);
}

$socket = getenv('COSTPRICE_MYSQL_SOCKET') ?: '';
$host = getenv('COSTPRICE_MYSQL_HOST') ?: '127.0.0.1';
$port = getenv('COSTPRICE_MYSQL_PORT') ?: '3306';
$username = getenv('COSTPRICE_MYSQL_USERNAME') ?: 'root';
$password = getenv('COSTPRICE_MYSQL_PASSWORD');
$password = $password === false ? '' : $password;

$base = [
    'driver' => 'mysql',
    'host' => $host,
    'port' => $port,
    'unix_socket' => $socket,
    'username' => $username,
    'password' => $password,
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'prefix_indexes' => true,
    // STRICT, deliberately: check 3 below asserts that an explicit NULL is refused, and that is
    // the behaviour a correctly configured server has. The application's own connection sets
    // 'strict' => false, which is what makes an UPDATE coerce instead of erroring -- a separate
    // fact, recorded with F59/F61, and not what this file is measuring.
    'strict' => true,
    'engine' => null,
];

config([
    'database.connections.costprice' => $base + ['database' => $database, 'url' => null],
    // the same credentials with no database selected, so the database can be created
    'database.connections.costprice_root' => $base + ['database' => null],
]);

echo "F58 — products.cost_price MIGRATION VALIDATION, AGAINST A REAL MySQL SERVER\n";
echo str_repeat('=', 86) . "\n";

$root = DB::connection('costprice_root');

try {
    $root->statement("DROP DATABASE IF EXISTS `{$database}`");
    $root->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (\Throwable $e) {
    fwrite(STDERR, "Could not reach the server or create '{$database}': " . $e->getMessage() . "\n");
    exit(2);
}

$conn = DB::connection('costprice');

try {
    // -----------------------------------------------------------------------------------
    // 1 and 2. A FRESH ENVIRONMENT: the migration creates production's own type.
    // -----------------------------------------------------------------------------------
    echo "\n-- fresh migrate --\n";
    Artisan::call('migrate', ['--force' => true, '--database' => 'costprice']);

    check(Schema::connection('costprice')->hasColumn('products', 'cost_price'),
        'the migration added products.cost_price');

    $col = $conn->selectOne(
        'SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, NUMERIC_PRECISION, NUMERIC_SCALE
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$database, 'products', 'cost_price']
    );

    check($col !== null, 'information_schema reports the column');
    if ($col) {
        check(strtolower((string) $col->COLUMN_TYPE) === 'decimal(15,2)',
            'the type is exactly decimal(15,2)', 'got ' . $col->COLUMN_TYPE);
        check(strtoupper((string) $col->IS_NULLABLE) === 'NO',
            'the column is NOT NULL', 'IS_NULLABLE=' . $col->IS_NULLABLE);
        check((float) $col->COLUMN_DEFAULT === 0.0,
            'the default is 0.00', 'COLUMN_DEFAULT=' . var_export($col->COLUMN_DEFAULT, true));
        check((int) $col->NUMERIC_PRECISION === 15 && (int) $col->NUMERIC_SCALE === 2,
            'precision 15, scale 2', $col->NUMERIC_PRECISION . ',' . $col->NUMERIC_SCALE);
    }

    // scale is really enforced -- SQLite would keep 12.345 verbatim
    $conn->table('products')->insert([
        'name' => 'scale probe', 'slug' => 'scale-probe-' . uniqid(), 'price' => 10,
        'cost_price' => 12.345, 'track_stock' => 0, 'trending' => 0, 'status' => 1,
        'variant_product' => 0, 'stock_status' => 'in_stock', 'product_stock' => 0,
        'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $stored = (string) $conn->table('products')->where('name', 'scale probe')->value('cost_price');
    check($stored === '12.35' || $stored === '12.34',
        'the decimal scale is enforced by the engine, not by the application',
        'stored ' . $stored . ' for 12.345');

    // -----------------------------------------------------------------------------------
    // 3. NOT NULL is really enforced on INSERT, whatever the sql_mode.
    // -----------------------------------------------------------------------------------
    echo "\n-- NOT NULL on INSERT --\n";
    $refused = false;
    $code = null;
    try {
        $conn->table('products')->insert([
            'name' => 'null probe', 'slug' => 'null-probe-' . uniqid(), 'price' => 10,
            'cost_price' => null, 'track_stock' => 0, 'trending' => 0, 'status' => 1,
            'variant_product' => 0, 'stock_status' => 'in_stock', 'product_stock' => 0,
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    } catch (\Illuminate\Database\QueryException $e) {
        $refused = true;
        $code = $e->errorInfo[1] ?? null;
    }
    check($refused, 'an explicit NULL into cost_price is refused on INSERT');
    check($code === 1048, 'and the error is ER_BAD_NULL_ERROR (1048)', 'got ' . var_export($code, true));

    // -----------------------------------------------------------------------------------
    // 4. THE PRODUCTION-SHAPED NO-OP. Column present, migration row absent.
    // -----------------------------------------------------------------------------------
    echo "\n-- production-shaped no-op --\n";
    $conn->table('products')->insert([
        'name' => 'kept', 'slug' => 'kept-' . uniqid(), 'price' => 99,
        'cost_price' => 41.75, 'track_stock' => 0, 'trending' => 0, 'status' => 1,
        'variant_product' => 0, 'stock_status' => 'in_stock', 'product_stock' => 0,
        'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $conn->table('migrations')
        ->where('migration', '2026_10_26_000100_add_cost_price_to_products_table')->delete();

    $typeBefore = $conn->selectOne(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$database, 'products', 'cost_price']
    )->COLUMN_TYPE;

    Artisan::call('migrate', ['--force' => true, '--database' => 'costprice']);

    $typeAfter = $conn->selectOne(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$database, 'products', 'cost_price']
    )->COLUMN_TYPE;

    check($typeBefore === $typeAfter, 'running up() over an existing column changed no type',
        $typeBefore . ' -> ' . $typeAfter);
    check((string) $conn->table('products')->where('name', 'kept')->value('cost_price') === '41.75',
        'and it preserved the stored value',
        (string) $conn->table('products')->where('name', 'kept')->value('cost_price'));

    // -----------------------------------------------------------------------------------
    // 5. down() destroys nothing.
    // -----------------------------------------------------------------------------------
    echo "\n-- rollback --\n";
    Artisan::call('migrate:rollback', ['--force' => true, '--database' => 'costprice', '--step' => 1]);

    check(Schema::connection('costprice')->hasColumn('products', 'cost_price'),
        'the column still exists after a rollback');
    check((string) $conn->table('products')->where('name', 'kept')->value('cost_price') === '41.75',
        'and the money data survived the rollback');
} finally {
    try { $root->statement("DROP DATABASE IF EXISTS `{$database}`"); } catch (\Throwable $e) { /* best effort */ }
}

echo "\n" . str_repeat('=', 86) . "\n";

if ($failures) {
    echo 'FAIL ' . count($failures) . ' of ' . $checks . "\n";
    foreach ($failures as $f) { echo '   - ' . $f . "\n"; }
    exit(1);
}

echo 'PASS ' . $checks . '/' . $checks . "\n";
exit(0);
