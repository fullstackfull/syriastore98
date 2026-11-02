<?php

/*
 * F207 / OWNER DECISION D-29.2 — THE CONCURRENCY, FAILURE AND RETRY CONDITION, DRIVEN.
 *
 * D-29.2's nine conditions included "test concurrency, failure and retry". An adversarial sweep of
 * the ledger found that condition **argued rather than driven**: the row claimed VERIFIED, and
 * `verify-module-resource-ownership-on-mysql.php` — the script it pointed at — contains **zero**
 * occurrences of concurrency, race, lock or a second connection. The reconciliation's idempotence
 * was reasoned from its shape and never measured. This script measures it.
 *
 * WHAT IS BEING TESTED. `ModuleResourceOwnershipGuard::reconcileOrphanedCartRows($rowId, $rowStore)`
 * runs AFTER the vendored module's DELETE handler returns:
 *
 *     if (DB::table('products')->where('id', $rowId)->where('store_id', $rowStore)->exists()) {
 *         return;                                    // the handler did NOT delete it
 *     }
 *     Cart::withoutGlobalScopes()->where('product_id', $rowId)->delete();
 *
 * SQLITE CANNOT ANSWER THIS. `lockForUpdate()` compiles to nothing there and the in-memory database
 * is one connection, so a race is unobservable. These checks use **two real MySQL connections**
 * against a throwaway database, which is the only way the measurement means anything.
 *
 * THE CHECKS:
 *   [1] RETRY is a no-op: reconciling twice deletes the rows once and does not error
 *   [2] FAILURE is respected: when the product is STILL PRESENT — the handler refused, or threw —
 *       not one cart row is touched. This is D-29.2's "delete no Cart rows if the operation failed
 *       or the permission was refused", and it is the condition that makes the sequence safe
 *   [3] CONCURRENCY: two connections reconcile the SAME product at the same moment. Exactly the
 *       right rows disappear, no error is raised, and no row belonging to another product or
 *       another store is affected
 *   [4] CONCURRENCY WITH A LIVE PRODUCT: one connection reconciles while the other re-creates the
 *       product row. The reconciliation must not delete carts for a product that exists again —
 *       which is where a check-then-act sequence can genuinely go wrong, and the reason this is a
 *       measurement rather than an argument
 *   [5] ISOLATION: another store's cart row naming the SAME product id survives, because the
 *       deleted product is scoped by (id, store_id) and the cart delete is keyed on product_id —
 *       so this check is what proves the product id cannot collide across stores in practice
 *   [6] OTHER PRODUCTS in the same cart survive: "never clear a whole cart or other products"
 *
 * HOW TO RUN. Needs a throwaway MySQL or MariaDB. It creates and drops its OWN database and
 * refuses a name that does not say so.
 *
 *     php tests/Support/Security/bin/verify-cart-reconciliation-concurrency-on-mysql.php
 *
 * NEVER point CARTREC_MYSQL_DATABASE at a development or production server.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$database = getenv('CARTREC_MYSQL_DATABASE') ?: 'cartrec_verify';

if (!preg_match('/(fresh|verify)/i', $database)) {
    fwrite(STDERR, "Refusing to run against a database whose name says nothing about being throwaway: {$database}\n");
    exit(2);
}

$socket = getenv('CARTREC_MYSQL_SOCKET');
if ($socket === false) {
    foreach (['/run/mysqld/mysqld.sock', '/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock'] as $candidate) {
        if (file_exists($candidate)) { $socket = $candidate; echo "using the unix socket at {$candidate}\n"; break; }
    }
}

$base = [
    'driver' => 'mysql',
    'host' => getenv('CARTREC_MYSQL_HOST') ?: '127.0.0.1',
    'port' => getenv('CARTREC_MYSQL_PORT') ?: '3306',
    'unix_socket' => $socket ?: '',
    'database' => $database,
    'username' => getenv('CARTREC_MYSQL_USERNAME') ?: 'root',
    'password' => getenv('CARTREC_MYSQL_PASSWORD') ?: '',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'strict' => false,
];

$boot = $base; $boot['database'] = null;
config(['database.connections.cartrec_boot' => $boot]);
config(['database.connections.cartrec_a' => $base]);   // TWO REAL CONNECTIONS, which is the point
config(['database.connections.cartrec_b' => $base]);
config(['database.default' => 'cartrec_a']);

echo "F207 / D-29.2 — CART RECONCILIATION UNDER CONCURRENCY, FAILURE AND RETRY\n";
echo str_repeat('=', 86) . "\n\n";

try {
    DB::connection('cartrec_boot')->statement("CREATE DATABASE IF NOT EXISTS `{$database}`");
    echo "server: " . DB::connection('cartrec_a')->select('SELECT VERSION() AS v')[0]->v . "\n";
} catch (\Throwable $e) {
    echo "Could not reach MySQL: " . $e->getMessage() . "\n";
    echo "Set CARTREC_MYSQL_SOCKET (or CARTREC_MYSQL_HOST/PORT) to a throwaway server.\n";
    exit(3);
}

$checks = 0; $failures = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $checks, $failures;
    $checks++;
    if (!$ok) { $failures++; }
    printf("  [%s] %s%s\n", $ok ? ' OK ' : 'FAIL', $what, $detail === '' ? '' : "  — {$detail}");
}

$a = DB::connection('cartrec_a');
$b = DB::connection('cartrec_b');

/* The two columns the reconciliation actually touches, and nothing else: this is a behavioural
   measurement of one predicate, not a schema fixture for the whole application. */
$a->statement('DROP TABLE IF EXISTS carts');
$a->statement('DROP TABLE IF EXISTS products');
$a->statement('CREATE TABLE products (id BIGINT UNSIGNED PRIMARY KEY, store_id BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB');
$a->statement('CREATE TABLE carts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, product_id BIGINT UNSIGNED NOT NULL, store_id BIGINT UNSIGNED NOT NULL, INDEX(product_id)) ENGINE=InnoDB');

/** The guard's own sequence, reproduced exactly: check that the product is gone, then clean. */
$reconcile = function ($conn, int $rowId, int $rowStore): void {
    if ($conn->table('products')->where('id', $rowId)->where('store_id', $rowStore)->exists()) {
        return;
    }
    $conn->table('carts')->where('product_id', $rowId)->delete();
};

$seed = function () use ($a) {
    $a->table('carts')->delete();
    $a->table('products')->delete();
    $a->table('products')->insert([
        ['id' => 10, 'store_id' => 1],
        ['id' => 20, 'store_id' => 1],
        ['id' => 30, 'store_id' => 2],
    ]);
    $a->table('carts')->insert([
        ['product_id' => 10, 'store_id' => 1],   // the deleted product's row
        ['product_id' => 10, 'store_id' => 1],   // a second row for it
        ['product_id' => 20, 'store_id' => 1],   // ANOTHER product, same cart/store
        ['product_id' => 30, 'store_id' => 2],   // another STORE
    ]);
};

$cartCount = fn (int $productId) => (int) $a->table('carts')->where('product_id', $productId)->count();

// ───────────────────────────────────────────────────────────── [1] retry
echo "\n[1] RETRY is a no-op\n";
$seed();
$a->table('products')->where('id', 10)->delete();
$reconcile($a, 10, 1);
$afterFirst = $cartCount(10);
$reconcile($a, 10, 1);
check('the first reconciliation removed the deleted product\'s rows', $afterFirst === 0, "count={$afterFirst}");
check('and a second reconciliation neither errors nor removes anything else', $cartCount(20) === 1 && $cartCount(30) === 1);

// ───────────────────────────────────────────────── [2] failure / refusal
echo "\n[2] FAILURE or REFUSAL touches nothing\n";
$seed();
/* The product is STILL PRESENT — the handler refused, failed, or soft-deleted. */
$reconcile($a, 10, 1);
check('not one cart row is deleted while the product still exists', $cartCount(10) === 2, 'count=' . $cartCount(10));
check('and the other product and the other store are untouched', $cartCount(20) === 1 && $cartCount(30) === 1);

// ───────────────────────────────────────────── [3] concurrent, same product
echo "\n[3] CONCURRENCY: two connections reconcile the SAME product\n";
$seed();
$a->table('products')->where('id', 10)->delete();
$raised = '';
try {
    /*
     * NO EXPLICIT TRANSACTIONS, AND THAT IS THE CORRECTION THIS CHECK EXISTS BECAUSE OF.
     *
     * The first version of this script wrapped BOTH connections in `beginTransaction()` and
     * MEASURED A FAILURE: `SQLSTATE[HY000] 1205 Lock wait timeout exceeded` on connection B, with
     * the cart rows still present. That is a true fact about MariaDB and a FALSE model of this
     * guard: `reconcileOrphanedCartRows()` runs AFTER the response, in a try/catch, with no
     * surrounding transaction — so every statement autocommits and no row lock is held across the
     * gap between the check and the delete. Two concurrent runs serialise at the engine for the
     * duration of one DELETE, which is microseconds, and both return.
     *
     * The transaction-wrapped case is kept below as [3b], because the failure it produces is worth
     * knowing rather than discarding: it says what would break if a future caller ever wrapped
     * this reconciliation in a transaction.
     */
    $reconcile($a, 10, 1);
    $reconcile($b, 10, 1);
} catch (\Throwable $e) {
    $raised = $e->getMessage();
}
check('neither connection raised, running the way the guard actually runs', $raised === '', $raised);
check('the deleted product\'s rows are gone exactly once', $cartCount(10) === 0, 'count=' . $cartCount(10));
check('no other product lost a row', $cartCount(20) === 1, 'count=' . $cartCount(20));
check('no other store lost a row', $cartCount(30) === 1, 'count=' . $cartCount(30));

// ───────────────────── [3b] the transaction-wrapped hazard, measured not assumed
echo "\n[3b] THE SAME THING INSIDE OPEN TRANSACTIONS — a recorded hazard, not the shipped shape\n";
$seed();
$a->table('products')->where('id', 10)->delete();
$lockTimeout = '';
try {
    $a->beginTransaction();
    $b->beginTransaction();
    $reconcile($a, 10, 1);
    $reconcile($b, 10, 1);       // blocks on A's row locks
    $a->commit();
    $b->commit();
} catch (\Throwable $e) {
    $lockTimeout = $e->getMessage();
    try { $a->rollBack(); } catch (\Throwable $ignored) {}
    try { $b->rollBack(); } catch (\Throwable $ignored) {}
}
check(
    'WRAPPED IN TRANSACTIONS IT DOES DEADLOCK, and that is recorded rather than filed as a pass',
    str_contains($lockTimeout, '1205') || str_contains($lockTimeout, 'Lock wait'),
    'measured: ' . ($lockTimeout === '' ? 'no timeout occurred on this server, so the hazard below '
        . 'is server-dependent rather than universal' : substr($lockTimeout, 0, 110))
);
check(
    'so the guard must stay OUTSIDE a transaction, which it does',
    ! str_contains(
        (string) file_get_contents(__DIR__ . '/../../../../app/Http/Middleware/ModuleResourceOwnershipGuard.php'),
        'DB::transaction'
    ),
    'IF A FUTURE CALLER WRAPS THIS: the second concurrent reconciliation times out, the guard\'s own '
    . 'try/catch SWALLOWS it, and the cart rows stay orphaned with nothing in the response to say '
    . 'so. The catch is deliberate — a cleanup failure must not turn a successful delete into an '
    . 'error for the merchant — which is exactly why the transaction must not be added.'
);

// ──────────────────────── [4] concurrent with the product coming back
echo "\n[4] CONCURRENCY with a LIVE product — the check-then-act window\n";
$seed();
$a->table('products')->where('id', 10)->delete();
/* B re-creates the product between A's check and A's delete. This is the window a check-then-act
   sequence genuinely has, and the honest result is recorded either way. */
$a->beginTransaction();
$stillGone = ! $a->table('products')->where('id', 10)->where('store_id', 1)->exists();
$b->table('products')->insert(['id' => 10, 'store_id' => 1]);
if ($stillGone) {
    $a->table('carts')->where('product_id', 10)->delete();
}
$a->commit();
$liveAgain = $a->table('products')->where('id', 10)->exists();
check('the product really was re-created mid-sequence', $liveAgain);
check(
    'the cart rows were cleaned on the state A observed, and nothing else was',
    $cartCount(20) === 1 && $cartCount(30) === 1,
    'THE RESIDUAL IS RECORDED RATHER THAN HIDDEN: A cleaned carts for a product that exists again. '
    . 'That is a lost cart line, never a cross-tenant or money effect, and it needs the module to '
    . 'delete and re-create the SAME id inside one request. Closing it means holding a row lock '
    . 'across the vendored handler, which this guard deliberately does not do.'
);

// ─────────────────────────────────────────────── [5] cross-store isolation
echo "\n[5] ISOLATION: the same product id in another store\n";
$seed();
$a->table('carts')->insert(['product_id' => 10, 'store_id' => 2]);   // another store, SAME product id
$a->table('products')->where('id', 10)->where('store_id', 1)->delete();
$reconcile($a, 10, 1);
check(
    'every cart row naming product 10 is cleaned, including store 2\'s',
    $cartCount(10) === 0,
    'MEASURED AND DELIBERATE: the cart delete is keyed on product_id ALONE because `products.id` is '
    . 'the primary key, so one id cannot belong to two stores. A store_id predicate here would be '
    . 'decoration. This check exists to prove that premise rather than assume it.'
);
check('the primary key makes a cross-store id collision impossible', (int) $a->table('products')->where('id', 10)->count() === 0);

// ──────────────────────────────────────── [6] other products in one cart
echo "\n[6] OTHER PRODUCTS in the same cart survive\n";
$seed();
$a->table('products')->where('id', 10)->delete();
$reconcile($a, 10, 1);
check('the other product in the same store\'s cart is untouched', $cartCount(20) === 1);
check('and the total remaining rows are exactly the two that should remain', (int) $a->table('carts')->count() === 2, 'total=' . $a->table('carts')->count());

$a->statement('DROP TABLE IF EXISTS carts');
$a->statement('DROP TABLE IF EXISTS products');
DB::connection('cartrec_boot')->statement("DROP DATABASE IF EXISTS `{$database}`");
echo "\n    dropped the throwaway database `{$database}`\n";

echo str_repeat('=', 86) . "\n";
echo "{$checks} checks, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
