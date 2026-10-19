<?php

/**
 * P1-INV-17 / S39-7 -- THE ONE CASE SQLITE CANNOT SHOW: the cross-tenant product UPDATE.
 *
 * WHY THIS IS A SCRIPT AND NOT A PHPUNIT TEST. Four of the five writes behind AuctionProduct's
 * `Route::resource` routes reproduce in the Feature suite and are covered by
 * tests/Feature/Security/ModuleResourceOwnershipTest. The fifth cannot, and the reason is the
 * engine:
 *
 *   `AuctionProductController::update` (:861) writes `products.trending` and
 *   `products.track_stock` from request fields the attacker does not have to post, so the UPDATE
 *   carries NULL for two NOT NULL columns. SQLite REFUSES the whole statement; production MySQL
 *   has `strict => false` (config/database.php:55) and COERCES the NULLs to 0, so the statement
 *   succeeds and ANOTHER STORE'S PRODUCT IS RENAMED.
 *
 * **Production is more exposed than the test database.** That is the same direction, and the same
 * class, as P1-INV-01 / S37-1, which CLAUDE.md records; a conditionally-skipped test would also
 * break the known-outcome gate's declared skip set. So the in-suite test asserts the REFUSAL
 * (a 403, which only the guard produces) and this script establishes the write.
 *
 * WHAT IT ESTABLISHES, by execution:
 *   1. that `strict => false` is what the application configures for MySQL (the premise);
 *   2. that a NULL into `products.trending` is REFUSED by SQLite and ACCEPTED by MySQL under that
 *      mode -- the engine difference, measured rather than asserted;
 *   3. that the UPDATE the handler issues therefore lands on another store's row;
 *   4. that the guard TARGETS the three `auctionproduct` methods against the HOST `products`
 *      table, and that the shared id parser rejects every value MySQL would coerce to a
 *      different row.
 *
 * WHAT IT DOES NOT ESTABLISH, stated because an earlier version of step 3 over-claimed it: the
 * store comparison itself is not exercised here. See the note at step 3 for where it is.
 *
 * HOW TO RUN. Points at any throwaway MySQL you control; creates and drops its own database and
 * refuses a database name without 'verify' in it:
 *
 *   MYSQL_SOCK=/var/run/mysqld/mysqld.sock MYSQL_USER_NAME=root \
 *       php tests/Support/Security/bin/verify-module-resource-ownership-on-mysql.php
 *
 * Exit 0 = every assertion held. NEVER point it at the development or production server.
 */

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';

$database = getenv('MYSQL_DATABASE') ?: 'p1inv17_verify';

if (! str_contains($database, 'verify')) {
    fwrite(STDERR, "REFUSING: the database name must contain 'verify'. Got: {$database}\n");
    exit(2);
}

$socket = getenv('MYSQL_SOCK') ?: '/var/run/mysqld/mysqld.sock';
$host = getenv('MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('MYSQL_PORT_NUM') ?: 3306);
$user = getenv('MYSQL_USER_NAME') ?: 'root';
$pass = getenv('MYSQL_PASS') ?: '';

$failed = false;

$out = function (string $label, bool $ok, string $detail = '') use (&$failed): void {
    printf("  [%s] %s%s\n", $ok ? ' OK ' : 'FAIL', $label, $detail === '' ? '' : "\n         " . $detail);

    if (! $ok) {
        $failed = true;
    }
};

// file_exists, NOT is_file: a unix socket is not a regular file.
$dsnBase = file_exists($socket)
    ? "mysql:unix_socket={$socket}"
    : "mysql:host={$host};port={$port}";

try {
    $pdo = new PDO($dsnBase, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) {
    fwrite(STDERR, "No MySQL reachable ({$dsnBase}): " . $e->getMessage() . "\n");
    exit(2);
}

/*
 * The application is booted FIRST. `config/database.php` calls database_path(), which needs a
 * bootstrapped container -- requiring the file directly is a fatal, which is how this was
 * discovered.
 */
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "STEP 0 -- the premise: what the application configures for MySQL.\n";
$strict = config('database.connections.mysql.strict');
$out("config/database.php sets mysql.strict = false", $strict === false,
    'actual: ' . var_export($strict, true)
    . ' -- if this is now true the coercion below does not happen in production and this '
    . "script's conclusion needs re-reading.");

echo "\nSTEP 1 -- a throwaway database, created and dropped by this script.\n";
$pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
$pdo->exec("CREATE DATABASE `{$database}`");
$pdo->exec("USE `{$database}`");
$out("database `{$database}` created", true);
printf("         server version: %s\n", $pdo->query('SELECT VERSION()')->fetchColumn());

// The two columns the handler writes NULL into, exactly as database/migrations declares them
// (2023_12_26_092822_create_products_table.php:26, :29): NOT NULL, one with a default and one
// without.
$pdo->exec('CREATE TABLE products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(191) NOT NULL,
    store_id BIGINT UNSIGNED NULL,
    trending INT NOT NULL DEFAULT 0,
    track_stock INT NOT NULL
)');
$pdo->exec("INSERT INTO products (id, name, store_id, trending, track_stock)
            VALUES (7, 'Victim product', 2, 0, 1)");
echo "         products: id 7 -> store 2 (the victim). The caller is in store 1.\n";

echo "\nSTEP 2 -- the engine difference, measured on both engines.\n";

// `strict => false` is what config/database.php sets; Laravel translates that to a session
// sql_mode without STRICT_TRANS_TABLES.
$pdo->exec("SET SESSION sql_mode = ''");

$update = "UPDATE products SET name = 'HIJACKED-BY-AUCTION', trending = ?, track_stock = ? WHERE id = 7";

$mysqlAccepted = true;
$mysqlError = '';

try {
    $pdo->prepare($update)->execute([null, null]);
} catch (Throwable $e) {
    $mysqlAccepted = false;
    $mysqlError = $e->getMessage();
}

$row = $pdo->query('SELECT name, store_id, trending, track_stock FROM products WHERE id = 7')
    ->fetch(PDO::FETCH_ASSOC);

$out('MySQL with strict=false ACCEPTS the NULLs and the UPDATE lands', $mysqlAccepted,
    $mysqlAccepted ? '' : 'error: ' . $mysqlError);
$out("another store's product was RENAMED", ($row['name'] ?? null) === 'HIJACKED-BY-AUCTION',
    'name is now ' . var_export($row['name'] ?? null, true)
    . ', store_id ' . var_export($row['store_id'] ?? null, true)
    . ' -- the row still belongs to store 2 and now carries the attacker\'s text.');
$out('the NULLs were COERCED to 0, not stored',
    (int) ($row['trending'] ?? -1) === 0 && (int) ($row['track_stock'] ?? -1) === 0,
    'trending=' . var_export($row['trending'] ?? null, true)
    . ' track_stock=' . var_export($row['track_stock'] ?? null, true));

// The same statement on SQLite, which is what the Feature suite runs.
$lite = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$lite->exec('CREATE TABLE products (
    id INTEGER PRIMARY KEY, name TEXT NOT NULL, store_id INTEGER,
    trending INTEGER NOT NULL DEFAULT 0, track_stock INTEGER NOT NULL
)');
$lite->exec("INSERT INTO products (id, name, store_id, trending, track_stock)
             VALUES (7, 'Victim product', 2, 0, 1)");

$liteRefused = false;
$liteError = '';

try {
    $lite->prepare($update)->execute([null, null]);
} catch (Throwable $e) {
    $liteRefused = true;
    $liteError = $e->getMessage();
}

$liteRow = $lite->query('SELECT name FROM products WHERE id = 7')->fetch(PDO::FETCH_ASSOC);

$out('SQLite REFUSES the same statement', $liteRefused,
    $liteRefused
        ? 'NOT NULL constraint failed -- which is why no test in the Feature suite can show this '
          . 'write, in either direction. ' . substr($liteError, 0, 120)
        : 'SQLite accepted it, so the engine difference this script rests on is gone.');
$out("SQLite leaves the victim's name unchanged", ($liteRow['name'] ?? null) === 'Victim product',
    'This is the measured reason the in-suite test asserts the 403 and not the row.');

echo "\nSTEP 3 -- the guard targets that UPDATE, and the shared id parser is strict.\n";
echo "         NOT the store comparison -- see the note below for why, and where that is covered.\n";

$reflection = new ReflectionClass(App\Http\Middleware\ModuleResourceOwnershipGuard::class);
$targets = $reflection->getConstant('TARGETS');

foreach (['PUT auctionproduct/{auctionproduct}', 'PATCH auctionproduct/{auctionproduct}',
          'DELETE auctionproduct/{auctionproduct}'] as $key) {
    $out("   `{$key}` is a guard target", array_key_exists($key, $targets));
    $out("   `{$key}` targets the HOST `products` table",
        ($targets[$key]['table'] ?? null) === 'products',
        'The two auction product mutations resolve App\Models\Product::find($id), not an auction '
        . 'table, which is what makes them a catalogue breach rather than a module one.');
}

/*
 * WHAT THIS STEP DOES AND DOES NOT ESTABLISH -- corrected after review.
 *
 * The first version of this block defined a probe class with a `compare()` method that was a
 * COPY of storeMatches()'s body rather than a call to it, under the heading "read off the guard's
 * own predicate". That is the vacuous-control shape: deleting the trait's `empty($rowStore)` limb
 * would have left every line here green. The copy is gone.
 *
 * What is exercised below is the trait's REAL `usableId()`, called through the trait, plus the
 * guard's TARGETS map above. `storeMatches()` itself is NOT exercised here and cannot sensibly be:
 * it resolves the caller's store through `getCurrentStore($user->id)`, which reads the `users`
 * table through a Cachable model, and standing that up in a throwaway schema would be testing the
 * fixture. It is covered where it belongs:
 *   - behaviourally by tests/Feature/Security/ModuleResourceOwnershipTest (the cross-store,
 *     storeless-caller and NULL-store cases, each with a measured removal check), and
 *   - by source assertion in tests/Feature/Tenancy/OwnedPathRawQueryInventoryTest, which pins the
 *     comparison, both fail-closed limbs and the explicit `getCurrentStore($user->id)`.
 *
 * This script's value is STEPS 0-2: the premise, the engine difference, and the cross-tenant
 * rename that actually lands on MySQL and cannot land on SQLite.
 */
$trait = new ReflectionClass(App\Http\Middleware\Concerns\ComparesRowOwnership::class);
$out('the ownership predicate is a shared trait, not a per-guard copy', $trait->isTrait()
    && in_array(App\Http\Middleware\Concerns\ComparesRowOwnership::class,
        class_uses(App\Http\Middleware\ModuleResourceOwnershipGuard::class) ?: [], true)
    && in_array(App\Http\Middleware\Concerns\ComparesRowOwnership::class,
        class_uses(App\Http\Middleware\ModuleOrderMutationGuard::class) ?: [], true),
    'Both guards must USE it. One that stopped would take its own copy of the predicate with it, '
    . 'which is the drift the shared trait exists to prevent.');

$probe = new class {
    use App\Http\Middleware\Concerns\ComparesRowOwnership;

    /** The REAL trait method, called -- not reimplemented. */
    public function parse(mixed $value): ?int
    {
        return $this->usableId($value);
    }
};

$out("   `'7abc'` is not a usable id", $probe->parse('7abc') === null,
    "MySQL coerces it to row 7 -- step 1 of verify-module-order-mutation-id-coercion-on-mysql.php "
    . 'measures exactly that -- so a guard that accepted it would authorise a row the handler then '
    . 'resolves differently.');
$out("   `'7'` is", $probe->parse('7') === 7);
$out('   a negative id is not', $probe->parse('-1') === null);
$out('   zero is not', $probe->parse('0') === null);
$out('   an array is not', $probe->parse(['7']) === null);

echo "\nSTEP 4 -- drop the throwaway database.\n";
$pdo->exec("DROP DATABASE `{$database}`");
$out("database `{$database}` dropped", true);

echo "\n" . ($failed ? "RESULT: FAILED\n" : "RESULT: ALL CHECKS PASSED\n");

exit($failed ? 1 : 0);
