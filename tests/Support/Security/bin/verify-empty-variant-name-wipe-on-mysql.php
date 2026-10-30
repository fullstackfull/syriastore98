<?php

/**
 * F199 — AN ALL-EMPTY CHOICE PAYLOAD WIPED EVERY VARIANT ON PRODUCTION'S CONNECTION, AND THE
 * SQLite SUITE COULD NOT SHOW IT.
 * =================================================================================================
 *
 * THE DEFECT, pre-existing and inherited by F196's extraction (`git show
 * ba34fce8:app/Http/Controllers/ProductController.php` carries the same `if ($ssName !== '')`
 * filter). `ProductController::update()`'s choice rebuild and its D-20 removal guard disagreed
 * about one payload:
 *
 *   choice_options_1[] = ''   ->  implode('|', ['']) = ''  ->  explode(',', '') = ['']
 *                             ->  $options = [['']]        ->  combinations() = [[0 => '']]
 *
 *   the GUARD built the combination name '', DROPPED it (the filter), and saw an EMPTY covered
 *   set -- which it reads as "nothing was posted, nothing to check", because that is exactly how
 *   an ordinary save that posts no `choice_no` is skipped;
 *
 *   the DELETE is gated on `count($combinations[0]) > 0`, computed from the UNFILTERED build, so
 *   1 -- and it ran `whereNotIn('variant', [''])`, which matches every real row.
 *
 * THE MEASURED OUTCOME, pre-fix: HTTP 200, `{"flag":"success","msg":"Product update successfully."}`,
 * and the product's four variants -- prices, SKUs and stock -- replaced by one row named ''.
 *
 * A HYPOTHESIS ABOUT THE MECHANISM WAS WRONG, AND SECTION A IS KEPT SO IT IS NOT RE-RUN. The first
 * reading was that the wipe needed production's `'strict' => false` (`config/database.php:55`) to
 * coerce a NULL `price` into the NOT NULL `double`
 * (`database/migrations/2024_01_01_104858_create_product_variants_table.php:19`), because on SQLite
 * a payload carrying no price key dies on that constraint before the delete. **That is false, and
 * this script measures it being false:** MariaDB refuses an explicit NULL into a NOT NULL column as
 * error 1048 on BOTH settings. Non-strict mode coerces out-of-range values and invalid dates; an
 * explicit NULL is not one of those.
 *
 * The real mechanism needs no engine quirk. The payload also supplies the CHOICE path's own key
 * names -- `sku_`, `price_`, `stock_`, whose suffix for an empty combination name is empty -- so the
 * insert succeeds normally and the delete follows. It reproduces on SQLite too, and the end-to-end
 * HTTP proof therefore lives in the Feature suite
 * (`tests/Feature/Merchant/ProductUpdateRefusalPersistsNothingTest.php`), not here.
 *
 * SO WHAT THIS SCRIPT IS FOR: confirming on the production ENGINE that the delete takes every row
 * (section B) and that the guard's own arithmetic skipped it before the fix and refuses after it
 * (section C) -- plus section A, which closes off the wrong explanation.
 *
 * USAGE
 *   F199_MYSQL_SOCKET=/var/run/mysqld/mysqld.sock \
 *   F199_MYSQL_USERNAME=root \
 *   php tests/Support/Security/bin/verify-empty-variant-name-wipe-on-mysql.php
 *
 * or with F199_MYSQL_HOST / F199_MYSQL_PORT instead of a socket. The script creates and DROPS its
 * own database and refuses a name containing neither 'f199' nor 'verify'. Never point it at a
 * development or production server. Exit 0 means every assertion held.
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

// -------------------------------------------------------------------------------------------
// PRECONDITIONS
// -------------------------------------------------------------------------------------------

$database = getenv('F199_MYSQL_DATABASE') ?: 'f199_verify';

if (! str_contains($database, 'f199') && ! str_contains($database, 'verify')) {
    fwrite(STDERR, "REFUSING: the database name '{$database}' contains neither 'f199' nor 'verify'.\n"
        . "This script issues DROP DATABASE. Name the throwaway database accordingly.\n");
    exit(2);
}

$socket = getenv('F199_MYSQL_SOCKET') ?: '';
$host = getenv('F199_MYSQL_HOST') ?: '127.0.0.1';
$port = getenv('F199_MYSQL_PORT') ?: '3306';
$username = getenv('F199_MYSQL_USERNAME') ?: 'root';
$password = getenv('F199_MYSQL_PASSWORD');
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
    'engine' => null,
];

config([
    // 'strict' => false IS THE POINT: it is what config/database.php:55 gives production.
    'database.connections.f199_lax' => $base + ['database' => $database, 'url' => null, 'strict' => false],
    // the same server, strict, so the difference is attributable to the setting and not to the server
    'database.connections.f199_strict' => $base + ['database' => $database, 'url' => null, 'strict' => true],
    'database.connections.f199_root' => $base + ['database' => null, 'strict' => false],
]);

echo "F199 — THE ALL-EMPTY CHOICE PAYLOAD, AGAINST A REAL MySQL/MariaDB SERVER\n";
echo str_repeat('=', 90) . "\n";

try {
    $root = DB::connection('f199_root');
    $root->statement("DROP DATABASE IF EXISTS `{$database}`");
    $root->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "server  : " . $root->selectOne('SELECT VERSION() AS v')->v . "\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "Could not reach the server or create '{$database}': " . $e->getMessage() . "\n");
    exit(2);
}

$lax = DB::connection('f199_lax');
$strict = DB::connection('f199_strict');

try {
    echo "\n-- migrate (the real schema, not a hand-built one) --\n";
    Artisan::call('migrate', ['--force' => true, '--database' => 'f199_lax']);

    check(Schema::connection('f199_lax')->hasTable('product_variants'),
        'the real migrations created product_variants');

    $priceCol = $lax->selectOne(
        'SELECT IS_NULLABLE, COLUMN_DEFAULT, DATA_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$database, 'product_variants', 'price']
    );

    check($priceCol !== null && $priceCol->IS_NULLABLE === 'NO' && $priceCol->COLUMN_DEFAULT === null,
        'premise: product_variants.price is NOT NULL with no default',
        $priceCol === null ? 'column absent' : json_encode($priceCol));

    // ---------------------------------------------------------------------------------------
    // A. CLOSING OFF THE WRONG EXPLANATION — and showing the right one needs no engine quirk.
    // ---------------------------------------------------------------------------------------
    echo "\n-- A. an explicit NULL into price, on the two settings --\n";

    /* `product_variants_product_id_foreign` is a real FK (ON DELETE CASCADE), so the parent row
       has to exist before any variant does. The first run of this script omitted it and the
       failure read as a finding; recorded, because that is exactly how a script lies. */
    $lax->table('products')->insert([
        'id' => 7, 'name' => 'F199 Scarf', 'slug' => 'f199-scarf', 'price' => 10,
        'product_stock' => 7, 'status' => 1, 'variant_product' => 1, 'store_id' => 3,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    echo "   lax session sql_mode  : " . $lax->selectOne('SELECT @@session.sql_mode AS m')->m . "\n";
    echo "   strict session sql_mode: " . $strict->selectOne('SELECT @@session.sql_mode AS m')->m . "\n";

    $laxInsert = null;
    try {
        $lax->table('product_variants')->insert([
            'product_id' => 7, 'variant' => '', 'sku' => 'F199-EMPTY',
            'price' => null, 'stock' => 0, 'store_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $laxInsert = 'accepted';
    } catch (\Throwable $e) {
        $laxInsert = 'refused: ' . $e->getMessage();
    }

    check(is_string($laxInsert) && str_contains($laxInsert, 'cannot be null'),
        "THE REFUTATION: on 'strict' => false — production's own setting — an explicit NULL into"
        . ' product_variants.price is STILL refused (error 1048). Non-strict mode does not coerce'
        . ' explicit NULLs, so the NULL route is NOT how the wipe is reached',
        (string) $laxInsert);

    $strictInsert = null;
    try {
        $strict->table('product_variants')->insert([
            'product_id' => 7, 'variant' => 'strict', 'sku' => 'F199-STRICT',
            'price' => null, 'stock' => 0, 'store_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $strictInsert = 'accepted';
    } catch (\Throwable $e) {
        $strictInsert = 'refused';
    }

    check($strictInsert === 'refused',
        "and on 'strict' => true likewise — the two settings agree here, which is what makes the"
        . ' first explanation wrong rather than merely unproven',
        (string) $strictInsert);

    $laxAccepted = null;
    try {
        $lax->table('product_variants')->insert([
            'product_id' => 7, 'variant' => '', 'sku' => 'F199-REAL',
            'price' => 5, 'stock' => 1, 'store_id' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $laxAccepted = 'accepted';
    } catch (\Throwable $e) {
        $laxAccepted = 'refused: ' . $e->getMessage();
    }

    check($laxAccepted === 'accepted',
        "THE REAL ROUTE: the same ''-named row WITH a price and stock — which the payload supplies"
        . " through the choice path's own `price_` and `stock_` keys — inserts normally, on this"
        . ' engine, with no quirk involved',
        (string) $laxAccepted);

    // ---------------------------------------------------------------------------------------
    // B. THE DELETE FACT — what whereNotIn('variant', ['']) does to real rows.
    // ---------------------------------------------------------------------------------------
    echo "\n-- B. whereNotIn('variant', ['']) against four real variants --\n";

    $lax->table('product_variants')->delete();

    foreach ([['S-Red', 101, 201, 31], ['S-Blue', 102, 202, 32], ['M-Red', 103, 203, 33], ['M-Blue', 104, 204, 34]] as $i => [$name, $vp, $p, $st]) {
        $lax->table('product_variants')->insert([
            'product_id' => 7, 'variant' => $name, 'sku' => sprintf('F199-%04d', $i),
            'price' => $p, 'variation_price' => $vp, 'stock' => $st, 'store_id' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    check($lax->table('product_variants')->where('product_id', 7)->count() === 4,
        'premise: four variants with prices, SKUs and stock exist');

    $deleted = $lax->table('product_variants')
        ->where('product_id', 7)
        ->whereNotIn('variant', [''])
        ->delete();

    check($deleted === 4 && $lax->table('product_variants')->where('product_id', 7)->count() === 0,
        "the rebuild's own delete removes EVERY row when \$sku_array is ['']",
        "deleted {$deleted}");

    // ---------------------------------------------------------------------------------------
    // C. THE GUARD'S OWN ARITHMETIC, through the real private methods.
    // ---------------------------------------------------------------------------------------
    echo "\n-- C. the covered set the guard computes for choice_options_1 = [''] --\n";

    $controller = (new \ReflectionClass(\App\Http\Controllers\ProductController::class))
        ->newInstanceWithoutConstructor();

    $covered = (new \ReflectionMethod($controller, 'ssChoiceCombinationNames'));
    $covered->setAccessible(true);

    $request = \Illuminate\Http\Request::create('/x', 'PUT', [
        'variant_product' => 1,
        'choice_no' => [1],
        'choice_options_1' => [''],
    ]);

    $names = $covered->invoke($controller, $request);

    check($names === [''],
        "the covered set is [''] — the empty name is KEPT, so it mirrors \$sku_array exactly",
        json_encode($names));

    // the pre-fix answer, computed with the filter the fix removed, from the same combinations()
    $combinations = (new \ReflectionMethod($controller, 'combinations'));
    $combinations->setAccessible(true);
    $preFix = [];
    foreach ($combinations->invoke($controller, [['']]) as $combination) {
        $n = '';
        foreach ((array) $combination as $index => $item) {
            $n .= ($index > 0 ? '-' : '') . str_replace(' ', '', (string) $item);
        }
        if ($n !== '') {                 // the filter the fix removed
            $preFix[] = $n;
        }
    }

    check($preFix === [],
        'and the pre-fix filter made it EMPTY, which the guard reads as "nothing posted" and skips'
        . " — while \$sku_array was still ['']",
        json_encode($preFix));

    echo "\n-- C2. and the refusal the fixed covered set produces, on this server --\n";

    foreach ([['S-Red', 201], ['S-Blue', 202], ['M-Red', 203], ['M-Blue', 204]] as $i => [$name, $p]) {
        $lax->table('product_variants')->insert([
            'product_id' => 7, 'variant' => $name, 'sku' => sprintf('F199-%04d', $i),
            'price' => $p, 'stock' => 30 + $i, 'store_id' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    DB::setDefaultConnection('f199_lax');

    $product = \App\Models\Product::withoutGlobalScopes()->find(7);
    check($product !== null && (int) $product->store_id === 3, 'premise: the product row is readable');

    $being = (new \ReflectionMethod($controller, 'ssVariantsBeingRemoved'));
    $being->setAccessible(true);
    $removed = $being->invoke($controller, $product, $names);

    check($removed->count() === 4,
        "the removal set for the [''] covered set is all FOUR rows — the same rows the delete would"
        . ' have taken',
        'count ' . $removed->count());

    $refuse = (new \ReflectionMethod($controller, 'ssRefuseVariantRemoval'));
    $refuse->setAccessible(true);
    $refusal = $refuse->invoke($controller, $product, $removed, false);

    check(is_array($refusal) && ($refusal['flag'] ?? null) === 'error',
        'and the guard REFUSES, naming them, instead of letting the delete run',
        json_encode($refusal));

    $preFixRemoved = $being->invoke($controller, $product, $preFix);
    $preFixRefusal = $refuse->invoke($controller, $product, $preFixRemoved, false);

    check($preFixRemoved->count() === 4 && is_array($preFixRefusal),
        'the pre-fix set would have refused too IF the guard had been reached — it was the EMPTY'
        . ' covered set that skipped it, not the reference checks',
        'count ' . $preFixRemoved->count());

    echo "\n-- D. what this script does NOT show --\n";
    echo "   The full HTTP request was not driven against MySQL. tests/Support/TestDatabaseGuard.php\n";
    echo "   aborts any PHPUnit run whose connection is not sqlite, and driving the route here would\n";
    echo "   need the whole session, tenant, permission and attribute fixture. A + B + C compose to\n";
    echo "   the conclusion instead: the ''-named row inserts normally once the payload supplies a\n";
    echo "   price and a stock (A), the delete then takes every other row (B), and the guard was\n";
    echo "   skipped because its covered set was empty (C). The fix is asserted end to end over HTTP\n";
    echo "   by\n";
    echo "   tests/Feature/Merchant/ProductUpdateRefusalPersistsNothingTest.php, where the refusal\n";
    echo "   now precedes the insert on either engine.\n";
} finally {
    try {
        DB::setDefaultConnection(config('database.default'));
        DB::connection('f199_root')->statement("DROP DATABASE IF EXISTS `{$database}`");
        echo "\ncleanup : dropped `{$database}`\n";
    } catch (\Throwable $e) {
        fwrite(STDERR, "WARNING: could not drop `{$database}`: " . $e->getMessage() . "\n");
    }
}

echo "\n" . str_repeat('=', 90) . "\n";
echo "checks  : {$checks}\n";
echo "failures: " . count($failures) . "\n";

foreach ($failures as $f) {
    echo "  - {$f}\n";
}

exit($failures === [] ? 0 : 1);
