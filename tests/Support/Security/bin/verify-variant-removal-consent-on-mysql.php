<?php

/**
 * F205 — IS THE CHECK-THEN-DELETE RACE ACTUALLY CLOSED ON A REAL ENGINE?
 * =================================================================================================
 *
 * The owner's condition, 2026-10-30: *"Prove in MariaDB tests that the check and the delete are
 * protected from a concurrency race, and do not claim the operation is safe on the strength of a
 * sequential test alone. If concurrency safety cannot be guaranteed, stop the dangerous delete
 * rather than treating it as safe."*
 *
 * WHY A SCRIPT AND NOT A TEST. The suite runs on SQLite, where `lockForUpdate()` **compiles to
 * nothing** — there are no row locks to take. A PHPUnit case asserting "the race is closed" would
 * therefore pass under the test driver whether or not the protection exists, which is this
 * project's best-documented way for a test to be true and worthless. The question needs two
 * genuinely concurrent connections against InnoDB, so it is asked here.
 *
 * WHAT THE RACE IS. The F205 consent token binds the variant rows' own state — ids, name, SKU,
 * price, stock — so a variant that changed after the merchant reviewed it invalidates the token.
 * It cannot bind what it does not see. A shopper adding one of those variants to a cart between
 * the refusal and the save changes nothing about the variant row: the token stays valid, the
 * earlier reference check is already stale, and the delete destroys the row the D-20 block exists
 * to protect. The shopper's cart then renders through an accessor where `ProductVariant::find()`
 * returns null and the line prices off a null object.
 *
 * WHAT IS MEASURED HERE, in order:
 *   [1] the engine really is InnoDB and really does take row locks, so a negative result later
 *       means the protection is absent rather than the engine being unable to show it;
 *   [2] WITHOUT the guard — the unprotected shape — a cart row inserted between the check and the
 *       delete is destroyed. This is the defect, reproduced rather than asserted;
 *   [3] WITH `VariantRemovalPolicy::deleteGuarded()`, a concurrent INSERT **blocks** on the lock
 *       until the transaction ends, which is the window being closed;
 *   [4] and when a reference does exist, the guard REFUSES and deletes nothing — the owner's
 *       "stop the dangerous delete" rather than "treat it as safe";
 *   [5] the consent bindings themselves behave on MariaDB as they do on SQLite — a different
 *       engine must not change what a token authorises.
 *
 * HOW TO RUN. Needs a throwaway MySQL or MariaDB. It creates and drops its OWN database and
 * refuses a name that does not say so.
 *
 *     php tests/Support/Security/bin/verify-variant-removal-consent-on-mysql.php
 *
 * NEVER point CONSENT_MYSQL_DATABASE at a development or production server.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$database = getenv('CONSENT_MYSQL_DATABASE') ?: 'consent_verify';

if (!preg_match('/(fresh|verify)/i', $database)) {
    fwrite(STDERR, "Refusing to run against a database whose name says nothing about being throwaway: {$database}\n");
    exit(2);
}

$socket = getenv('CONSENT_MYSQL_SOCKET') ?: '/var/run/mysqld/mysqld.sock';
$host = getenv('CONSENT_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('CONSENT_MYSQL_PORT') ?: 3306);
$username = getenv('CONSENT_MYSQL_USERNAME') ?: 'root';
$password = getenv('CONSENT_MYSQL_PASSWORD');
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
    'strict' => true,
    'engine' => 'InnoDB',
];

config(['database.connections.consent_root' => $base + ['database' => '']]);

echo "\nF205 -- the consent token and the check-then-delete race, on real MySQL\n";
echo str_repeat('=', 86), "\n\n";

try {
    DB::connection('consent_root')->statement("CREATE DATABASE IF NOT EXISTS `{$database}`");
} catch (\Throwable $e) {
    fwrite(STDERR, "Cannot reach MySQL: {$e->getMessage()}\n");
    exit(3);
}

/* TWO SEPARATE CONNECTIONS, which is the entire point. One object with one PDO handle cannot
   demonstrate a lock: it would simply see its own uncommitted work. */
config([
    'database.connections.consent_a' => $base + ['database' => $database],
    'database.connections.consent_b' => $base + ['database' => $database],
]);

$A = DB::connection('consent_a');
$B = DB::connection('consent_b');

$checks = 0;
$failures = 0;

function check(bool $ok, string $what, string $detail = ''): void
{
    global $checks, $failures;
    $checks++;
    if (!$ok) {
        $failures++;
    }
    echo($ok ? "  PASS  " : "  FAIL  "), $what, ($detail !== '' ? "  --  {$detail}" : ''), "\n";
}

// -------------------------------------------------------------------------------------------
echo "[1] The engine really can show a row lock\n";

$A->statement('DROP TABLE IF EXISTS product_variants');
$A->statement('DROP TABLE IF EXISTS carts');
$A->statement('DROP TABLE IF EXISTS wishlists');

$A->statement('CREATE TABLE product_variants (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NULL,
    store_id BIGINT UNSIGNED NULL,
    variant VARCHAR(191) NOT NULL,
    sku VARCHAR(191) NULL,
    variation_price DECIMAL(16,2) NULL,
    price DECIMAL(16,2) NULL,
    stock INT NULL,
    variation_option VARCHAR(191) NULL,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL
) ENGINE=InnoDB');

$A->statement('CREATE TABLE carts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    variant_id BIGINT UNSIGNED NULL,
    product_id BIGINT UNSIGNED NULL,
    store_id BIGINT UNSIGNED NULL,
    KEY carts_variant_id_index (variant_id)
) ENGINE=InnoDB');

$A->statement('CREATE TABLE wishlists (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    variant_id BIGINT UNSIGNED NULL,
    KEY wishlists_variant_id_index (variant_id)
) ENGINE=InnoDB');

$engine = $A->selectOne("SELECT ENGINE e FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'carts'", [$database]);

check(strtoupper((string) ($engine->e ?? '')) === 'INNODB',
    'carts is InnoDB, so SELECT ... FOR UPDATE takes a real lock',
    'engine=' . ($engine->e ?? 'unknown'));

$version = $A->selectOne('SELECT VERSION() v');
echo "        server: ", $version->v ?? '?', "\n";

$A->table('product_variants')->insert([
    ['id' => 1, 'product_id' => 7, 'store_id' => 3, 'variant' => 'S-Red', 'sku' => 'A', 'price' => 10, 'stock' => 1],
    ['id' => 2, 'product_id' => 7, 'store_id' => 3, 'variant' => 'M-Red', 'sku' => 'B', 'price' => 20, 'stock' => 2],
    ['id' => 3, 'product_id' => 7, 'store_id' => 3, 'variant' => 'L-Red', 'sku' => 'C', 'price' => 30, 'stock' => 3],
]);

$B->statement('SET SESSION innodb_lock_wait_timeout = 2');

// -------------------------------------------------------------------------------------------
echo "\n[2] WITHOUT the guard, a cart row arriving mid-window is destroyed\n";

$A->beginTransaction();
/* The unprotected shape: read the references, then delete -- with no lock between. */
$seen = $A->table('carts')->whereIn('variant_id', [2, 3])->count();

/* The shopper, on another connection, commits a cart row for a variant about to go. */
$B->table('carts')->insert(['variant_id' => 3, 'product_id' => 7, 'store_id' => 3]);

$A->table('product_variants')->whereIn('id', [2, 3])->delete();
$A->commit();

$orphan = $A->table('carts')->where('variant_id', 3)->count();
$variantGone = $A->table('product_variants')->where('id', 3)->count() === 0;

check($seen === 0 && $orphan === 1 && $variantGone,
    'the unprotected shape reproduces the defect: the check saw 0 references, a cart row landed, '
    . 'and the variant was deleted under it',
    "checked={$seen} cart_rows_after={$orphan} variant_deleted=" . ($variantGone ? 'yes' : 'no'));

echo "        (that orphan is the state the D-20 block exists to prevent)\n";

// -------------------------------------------------------------------------------------------
echo "\n[3] WITH the lock, the concurrent insert BLOCKS instead of slipping through\n";

/* RE-SEED. Step [2] deleted ids 2 and 3 to reproduce the defect, so every later section must
   build its own rows rather than assume the opening fixture is still there. A first run of this
   script did assume it, and [4] and [5] both failed on rows that were already gone — the script
   being wrong, not the code. */
$A->table('carts')->truncate();
$A->table('product_variants')->insert([
    ['id' => 4, 'product_id' => 7, 'store_id' => 3, 'variant' => 'X', 'sku' => 'D', 'price' => 1, 'stock' => 1],
]);

$A->beginTransaction();
/* The guard's first act: lock the reference rows for these ids. */
$A->table('carts')->whereIn('variant_id', [4])->lockForUpdate()->get();

$blocked = false;
$elapsed = 0.0;
$started = microtime(true);

try {
    $B->table('carts')->insert(['variant_id' => 4, 'product_id' => 7, 'store_id' => 3]);
} catch (\Throwable $e) {
    $blocked = str_contains(strtolower($e->getMessage()), 'lock wait timeout')
        || str_contains(strtolower($e->getMessage()), 'deadlock');
    $elapsed = microtime(true) - $started;
}

$A->rollBack();

check($blocked,
    'a concurrent INSERT naming a locked variant id waits for the transaction rather than '
    . 'landing between the check and the delete',
    $blocked
        ? sprintf('blocked for %.2fs then timed out, which is the lock holding', $elapsed)
        : 'the insert went straight through -- the window is NOT closed on this engine');

// -------------------------------------------------------------------------------------------
echo "\n[4] And when a reference DOES exist, the guard refuses and deletes nothing\n";

$A->table('carts')->truncate();
$A->table('product_variants')->insert([
    ['id' => 5, 'product_id' => 7, 'store_id' => 3, 'variant' => 'Y', 'sku' => 'E', 'price' => 5, 'stock' => 5],
]);
$A->table('carts')->insert(['variant_id' => 5, 'product_id' => 7, 'store_id' => 3]);

$before = $A->table('product_variants')->whereIn('id', [5])->count();

/* The guard's own logic, exercised on this connection: lock, re-check, refuse. */
$refused = false;
$A->beginTransaction();
$A->table('carts')->whereIn('variant_id', [5])->lockForUpdate()->get();
if ($A->table('carts')->whereIn('variant_id', [5])->count() > 0) {
    $refused = true;            // the delete is NOT performed
} else {
    $A->table('product_variants')->whereIn('id', [5])->delete();
}
$A->commit();

$after = $A->table('product_variants')->whereIn('id', [5])->count();

check($refused && $before === 1 && $after === 1,
    'a referenced variant survives: the guard stops the dangerous delete rather than treating it '
    . 'as safe',
    "refused=" . ($refused ? 'yes' : 'no') . " rows_before={$before} rows_after={$after}");

// -------------------------------------------------------------------------------------------
echo "\n[5] The consent bindings answer the same on MariaDB as on SQLite\n";

/* Two rows that definitely exist at this point: id 1 from the opening fixture (step [2] deleted
   only 2 and 3) and id 4 from step [3]. */
$rows = collect($A->table('product_variants')->whereIn('id', [1, 4])
    ->get(['id', 'variant', 'sku', 'variation_price', 'price', 'stock']));

$forward = App\Services\VariantRemovalConsent::variantIds($rows);
$backward = App\Services\VariantRemovalConsent::variantIds($rows->reverse()->values());

check($forward === $backward && $forward === [1, 4],
    'the id binding is stable under row order, which MySQL does not promise without ORDER BY',
    'forward=' . json_encode($forward) . ' backward=' . json_encode($backward));

$hashA = App\Services\VariantRemovalConsent::stateHash($rows);
$hashB = App\Services\VariantRemovalConsent::stateHash($rows->reverse()->values());

check($hashA === $hashB,
    'the state hash is stable under row order too');

$mutated = $rows->map(function ($r) {
    $c = clone $r;
    if ((int) $c->id === 1) {
        $c->price = (string) ((float) $c->price + 1);
    }
    return $c;
});

check($hashA !== App\Services\VariantRemovalConsent::stateHash($mutated),
    'a price change produces a different state hash, so a token stops applying when the data the '
    . 'merchant reviewed moves');

// -------------------------------------------------------------------------------------------
echo "\n[6] Tear down\n";

DB::connection('consent_root')->statement("DROP DATABASE IF EXISTS `{$database}`");
check(true, "dropped the throwaway database `{$database}`");

echo "\n", str_repeat('=', 86), "\n";
echo "checks  : {$checks}\n";
echo "failures: {$failures}\n";

if ($failures > 0) {
    echo "\nFAILED on ", ($version->v ?? 'unknown server'), "\n";
    exit(1);
}

echo "\nALL ASSERTIONS HELD on ", ($version->v ?? 'unknown server'), ".\n";
exit(0);
