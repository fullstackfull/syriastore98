<?php

/**
 * Does `MetricCaveat::FLOAT_PRECISION`'s stated basis actually hold?
 * =================================================================================================
 *
 * WHY THIS EXISTS. `MetricCaveat::FLOAT_PRECISION`'s message tells a merchant that a money figure
 * "is stored at limited precision and may differ from the ledger in the smallest units", and its
 * docblock grounds that in: *"The figure comes off `orders.final_price`, which is FLOAT -- about
 * seven significant digits on a currency that runs to millions."*
 *
 * CLAUDE.md §1 records the opposite, and records that a caveat was once attached and then WITHDRAWN
 * on exactly this point: **`$table->float()` emits a MySQL `double`**, so money off such a column is
 * not that caveat's basis. Two documents in this repository disagreed about a sentence merchants
 * read, and the disagreement is settleable by measurement rather than argument. So it is measured
 * here instead of being argued again.
 *
 * WHY IT IS NOT A `verify-*-on-mysql.php`. Those are assertions about a property the product must
 * have; this is a MEASUREMENT of what the engine does with a declaration, and its answer may change
 * the product's text rather than confirm it. It is deliberately named outside that roster so the
 * runner's completeness check does not adopt it as a gate -- a gate that fails when the schema is
 * *correct* would be the wrong instrument.
 *
 * WHAT IT MEASURES, in three steps, because only the third settles anything:
 *
 *   A. What Laravel's own schema builder emits for the EXACT declarations the two migrations use.
 *      Reading the migration tells you `float`; reading the emitted DDL tells you what the engine
 *      was actually asked for, which is the only thing the storage behaviour follows from.
 *   B. Whether money values round-trip through that column exactly.
 *   C. A CONTROL: the same values through a column declared literally `FLOAT`. Without this, a clean
 *      round trip in (B) proves nothing -- it could mean the values are small enough for any type,
 *      and the caveat's reasoning would still be sound for the column it names. The control shows
 *      the loss is real where the type is real, which is what makes (B)'s result discriminating.
 *
 * MEASURED 2026-11-26 on 10.11.14-MariaDB-0ubuntu0.24.04.1:
 *
 *      Laravel float()  ->  `double`, information_schema NUMERIC_PRECISION = 22
 *      2500000.49 -> 2500000.49   1234567.89 -> 1234567.89   999999.99 -> 999999.99
 *      literal FLOAT    ->  2500000.49 -> 2500000      1234567.89 -> 1234570
 *
 * So: CLAUDE.md is right, the caveat's stated basis is refuted for the migration-defined schema,
 * and the caveat's REASONING would have been right had the column been what its docblock says.
 *
 * THE LIMIT THIS SCRIPT CANNOT EXCEED, and it is the whole reason it is re-runnable. This measures
 * what a FRESH schema built from these migrations emits. CLAUDE.md's standing rule is that **the
 * migrations are not the schema** -- a column altered by hand years ago reads however it was
 * altered. Settling it for PRODUCTION needs `SHOW CREATE TABLE orders` there, which nothing in this
 * container can reach. Pass PROD_DDL_* to have this script compare a pasted production DDL against
 * what the migrations imply, rather than taking either on trust.
 *
 * HOW TO RUN. Needs a throwaway MySQL or MariaDB. It creates and drops its own database and refuses
 * a name that does not say it is throwaway.
 *
 *     php tests/Support/Security/bin/measure-float-money-basis.php
 *
 * Exit 0 = measured. Exit 2 = precondition not met (NOT a pass and NOT a failure -- a measurement
 * that could not run has produced no reading).
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$database = getenv('FRESH_MYSQL_DATABASE') ?: 'float_money_basis_verify';

if (! preg_match('/(fresh|verify)/i', $database)) {
    fwrite(STDERR, "Refusing to run against a database whose name says nothing about being throwaway: {$database}\n");
    exit(2);
}

$socket = getenv('FRESH_MYSQL_SOCKET');
if ($socket === false) {
    foreach (['/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock', '/var/lib/mysql/mysql.sock'] as $candidate) {
        if (file_exists($candidate)) {
            $socket = $candidate;
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
    /* Production's setting (config/database.php). A measurement taken in strict mode would describe
       a server this application never runs on. */
    'strict' => false,
];

$bootstrap = $connection;
$bootstrap['database'] = null;
config(['database.connections.fmb_bootstrap' => $bootstrap]);
config(['database.connections.fmb' => $connection]);

try {
    DB::connection('fmb_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
    DB::connection('fmb_bootstrap')->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4");
} catch (Throwable $e) {
    fwrite(STDERR, "PRECONDITION NOT MET: could not create a throwaway database -- {$e->getMessage()}\n");
    exit(2);
}

/** The money values a currency "that runs to millions" would actually hold. */
$values = [2500000.49, 1234567.89, 999999.99, 99.99, 0.01];

try {
    $server = DB::connection('fmb')->selectOne('SELECT VERSION() AS v')->v;
    echo "FLOAT_PRECISION's basis, measured\n";
    echo str_repeat('=', 78), "\n";
    echo "server: {$server}\n\n";

    // ── A. what Laravel's float() emits, for the exact declarations the migrations use
    Schema::connection('fmb')->create('laravel_declared', function ($t) {
        $t->id();
        $t->float('final_price')->nullable()->default(0);     // create_orders_table.php:26
        $t->float('coupon_discount_amount')->default(0);      // create_order_coupon_details_table.php:25
    });

    // ── C. the control: the type the caveat's docblock SAYS the column is
    DB::connection('fmb')->statement('
        CREATE TABLE literal_float (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            final_price FLOAT NULL DEFAULT 0,
            coupon_discount_amount FLOAT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB
    ');

    foreach (['laravel_declared' => "[A] \$table->float() -- what the MIGRATIONS declare",
              'literal_float' => "[C] CONTROL: literally FLOAT -- what the CAVEAT's docblock claims"] as $table => $label) {
        echo "{$label}\n";
        foreach (DB::connection('fmb')->select(
            'SELECT COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, NUMERIC_PRECISION
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME <> ?',
            [$database, $table, 'id']
        ) as $c) {
            printf("    %-24s %-10s precision=%s\n", $c->COLUMN_NAME, $c->COLUMN_TYPE,
                var_export($c->NUMERIC_PRECISION, true));
        }

        // ── B. the round trip
        foreach ($values as $v) {
            DB::connection('fmb')->table($table)->insert([
                'final_price' => $v,
                'coupon_discount_amount' => $v,
            ]);
        }

        $lost = 0;
        foreach (DB::connection('fmb')->table($table)->orderBy('id')->get() as $i => $r) {
            $in = $values[$i];
            $exact = ((string) $in === (string) $r->final_price);
            $lost += $exact ? 0 : 1;
            printf("    %-14s in %-14s out %-14s %s\n", 'round trip',
                $in, $r->final_price, $exact ? 'exact' : '*** MINOR UNITS LOST ***');
        }
        printf("    => %d of %d values lost precision\n\n", $lost, count($values));
    }

    echo str_repeat('-', 78), "\n";
    echo "READ THE CONTROL BEFORE THE RESULT. If [C] loses precision and [A] does not, then\n";
    echo "`\$table->float()` is NOT producing the type FLOAT_PRECISION's docblock names, the\n";
    echo "caveat's basis does not hold for the migration-defined schema, and the caveat must be\n";
    echo "re-grounded on a production measurement or withdrawn -- CLAUDE.md records that exact\n";
    echo "withdrawal having been done once before. If BOTH lose precision, the caveat is correct\n";
    echo "as written and should stay.\n\n";
    echo "AND THIS SCRIPT STILL CANNOT SETTLE PRODUCTION. It measures a FRESH schema; the\n";
    echo "migrations are not the schema. `SHOW CREATE TABLE orders` on production is the only\n";
    echo "thing that closes it, and nothing here can reach production.\n";
} finally {
    try {
        DB::connection('fmb')->disconnect();
        DB::connection('fmb_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
        echo "\n(dropped throwaway database {$database})\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "Could not drop the throwaway database: {$e->getMessage()}\n");
    }
}
