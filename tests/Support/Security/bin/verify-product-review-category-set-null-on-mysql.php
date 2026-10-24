<?php

/**
 * P1-INV-24 (#117) — THE CATEGORY CASCADE THAT DESTROYS REVIEWS, AND THE MIGRATION THAT STOPS IT,
 * BOTH MEASURED ON REAL MySQL.
 *
 * WHY THIS IS A SCRIPT AND NOT A PHPUNIT TEST. The whole finding is a FOREIGN KEY DELETE RULE.
 * SQLite does not enforce foreign keys unless `PRAGMA foreign_keys = ON` is set per connection, it
 * cannot ALTER a constraint at all, and the suite runs on SQLite `:memory:` where this vendored
 * module's table does not exist in the first place. A test needing MySQL would have to skip where
 * there is none, and `.github/known-test-outcomes.json` declares the exact skip set, so a
 * conditionally-skipped test would turn the known-outcome gate red.
 *
 * WHAT IS ESTABLISHED, by execution, in order:
 *
 *   STEP 1  the module's own schema, created exactly as its migration declares it -- including
 *           `category_id` NOT NULL DEFAULT 0 and ON DELETE CASCADE.
 *   STEP 2  THE DEFECT: the full P1-INV-24 sequence. A category with products is emptied (which is
 *           what D13's own refusal message tells the merchant to do), the category is then
 *           deleted, and the reviews pointing at it are DESTROYED by the cascade.
 *   STEP 3  why SET NULL cannot simply be declared: MySQL REFUSES the constraint while the column
 *           is NOT NULL. This is the "where the actual schema supports it" clause, measured.
 *   STEP 4  the shipped migration is applied through Laravel's own migrator against this database.
 *   STEP 5  the constraint's DELETE_RULE is read back from information_schema: SET NULL.
 *   STEP 6  the SAME sequence is driven again and the reviews SURVIVE, with category_id NULL.
 *   STEP 7  data preservation: a pre-existing `category_id = 0` row and a pre-existing ORPHAN row
 *           both survive the migration as NULL rather than being deleted.
 *   STEP 8  re-runnability: applying the migration twice is a no-op, not an error.
 *
 * HOW TO RUN. Points at any throwaway MySQL you control; creates and drops its own database and
 * refuses a database name without 'verify' in it:
 *
 *   MYSQL_SOCK=/var/run/mysqld/mysqld.sock MYSQL_USER_NAME=root \
 *       php tests/Support/Security/bin/verify-product-review-category-set-null-on-mysql.php
 *
 * Exit 0 = every assertion held. NEVER point it at the development or production server.
 */

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';

$database = getenv('MYSQL_DATABASE') ?: 'p1inv24_verify';

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

$dsnBase = file_exists($socket) ? "mysql:unix_socket={$socket}" : "mysql:host={$host};port={$port}";

try {
    $pdo = new PDO($dsnBase, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) {
    fwrite(STDERR, "No MySQL reachable ({$dsnBase}): " . $e->getMessage() . "\n");
    fwrite(STDERR, "This script states its own precondition and does nothing without a server.\n");
    exit(2);
}

echo "STEP 0 -- a throwaway database, created and dropped by this script.\n";
$pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
$pdo->exec("CREATE DATABASE `{$database}`");
$pdo->exec("USE `{$database}`");
$out("database `{$database}` created", true);
printf("         server version: %s\n", $pdo->query('SELECT VERSION()')->fetchColumn());
$pdo->exec("SET SESSION sql_mode = ''");   // config/database.php's production setting

$createSchema = function (PDO $pdo): void {
    $pdo->exec('DROP TABLE IF EXISTS product_reviews');
    $pdo->exec('DROP TABLE IF EXISTS products');
    $pdo->exec('DROP TABLE IF EXISTS categories');
    $pdo->exec('CREATE TABLE categories (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NULL, store_id BIGINT UNSIGNED NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE products (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NULL,'
        . ' category_id BIGINT UNSIGNED NULL, store_id BIGINT UNSIGNED NULL,'
        . ' CONSTRAINT products_category_id_foreign FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE CASCADE) ENGINE=InnoDB');
    // Column for column from the module's own migration, CASCADE included.
    $pdo->exec('CREATE TABLE product_reviews (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,'
        . ' category_id BIGINT UNSIGNED NOT NULL DEFAULT 0,'
        . ' product_id BIGINT UNSIGNED NOT NULL,'
        . ' rating_no INT NOT NULL DEFAULT 0,'
        . ' title VARCHAR(255) NULL, description TEXT NULL,'
        . ' status INT NOT NULL DEFAULT 1, store_id BIGINT UNSIGNED NULL,'
        . ' created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,'
        . ' CONSTRAINT product_reviews_category_id_foreign FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE CASCADE) ENGINE=InnoDB');
};

$deleteRule = function (PDO $pdo): ?string {
    $row = $pdo->query(
        "SELECT r.DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS r"
        . " WHERE r.CONSTRAINT_SCHEMA = DATABASE() AND r.TABLE_NAME = 'product_reviews'"
    )->fetch(PDO::FETCH_ASSOC);

    return $row === false ? null : $row['DELETE_RULE'];
};

echo "\nSTEP 1 -- the module's own schema, as its migration declares it.\n";
$createSchema($pdo);
$out("product_reviews.category_id delete rule is CASCADE", $deleteRule($pdo) === 'CASCADE',
    'Read from information_schema.REFERENTIAL_CONSTRAINTS. Actual: ' . var_export($deleteRule($pdo), true));

$nullable = $pdo->query("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()"
    . " AND TABLE_NAME = 'product_reviews' AND COLUMN_NAME = 'category_id'")->fetchColumn();
$out('and the column is NOT NULL, which is what blocks SET NULL', $nullable === 'NO',
    'Actual IS_NULLABLE: ' . var_export($nullable, true));

$seed = function (PDO $pdo): void {
    $pdo->exec("INSERT INTO categories (id, name, store_id) VALUES (10, 'Shoes', 1)");
    $pdo->exec("INSERT INTO categories (id, name, store_id) VALUES (20, 'Boots', 1)");
    $pdo->exec("INSERT INTO products (id, name, category_id, store_id) VALUES (100, 'Runner', 10, 1)");
    $pdo->exec("INSERT INTO product_reviews (id, category_id, product_id, rating_no, title, store_id) VALUES (1, 10, 100, 5, 'Great', 1)");
    $pdo->exec("INSERT INTO product_reviews (id, category_id, product_id, rating_no, title, store_id) VALUES (2, 10, 100, 4, 'Good', 1)");
};

echo "\nSTEP 2 -- THE DEFECT: the D13 remedy itself destroys the reviews.\n";
$seed($pdo);
echo "         category 10 holds product 100, which has 2 reviews pointing at category 10\n";
echo "         the merchant is refused, so they MOVE the product to category 20 (D13's own advice)\n";
$pdo->exec('UPDATE products SET category_id = 20 WHERE id = 100');

$stillHeld = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE category_id = 10')->fetchColumn();
$out('the D13 product count for category 10 is now 0, so the delete is PERMITTED', $stillHeld === 0);

$pdo->exec('DELETE FROM categories WHERE id = 10');
$surviving = (int) $pdo->query('SELECT COUNT(*) FROM product_reviews')->fetchColumn();
$out('and the cascade DESTROYED both reviews: 2 -> ' . $surviving, $surviving === 0,
    'This is P1-INV-24. The merchant followed the refusal message and lost the reviews, below the '
    . 'application, with no screen that could have warned them.');

echo "\nSTEP 3 -- WHY IT CANNOT SIMPLY BE DECLARED: MySQL refuses SET NULL on a NOT NULL column.\n";
$createSchema($pdo);
$pdo->exec('ALTER TABLE product_reviews DROP FOREIGN KEY product_reviews_category_id_foreign');
$refused = false;
$message = '';

try {
    $pdo->exec('ALTER TABLE product_reviews ADD CONSTRAINT product_reviews_category_id_foreign'
        . ' FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL');
} catch (Throwable $e) {
    $refused = true;
    $message = $e->getMessage();
}

$out('MySQL refuses the SET NULL constraint while category_id is NOT NULL', $refused,
    $refused ? substr($message, 0, 160) : 'It was ACCEPTED, which contradicts the migration\'s stated reason for altering the column first.');

echo "\nSTEP 4 -- the SHIPPED migration, run through Laravel's own migrator against this database.\n";
$createSchema($pdo);
$seed($pdo);
// Two rows the migration must PRESERVE rather than delete: the `default(0)` case and an orphan.
/*
 * BOTH INSERTS NEED FOREIGN_KEY_CHECKS OFF, AND THE FIRST ONE IS A FINDING IN ITSELF. The column's
 * own `default(0)` cannot satisfy its own foreign key -- there is no `categories` row with id 0 --
 * so with the constraint present, an insert that omits `category_id` is REFUSED outright:
 *
 *     SQLSTATE[23000]: 1452 Cannot add or update a child row: a foreign key constraint fails
 *
 * Measured here the hard way: the first draft of this script seeded review 3 with checks ON and
 * died on exactly that. Which means rows of this shape can only exist on an install where the
 * constraint was never created, was created later with checks disabled, or where the table predates
 * it -- and those are precisely the installs whose data the migration must not destroy. Seeding
 * them with checks off reproduces that history rather than inventing it.
 */
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$pdo->exec("INSERT INTO product_reviews (id, category_id, product_id, rating_no, title, store_id) VALUES (3, 0, 100, 3, 'No category', 1)");
$pdo->exec("INSERT INTO product_reviews (id, category_id, product_id, rating_no, title, store_id) VALUES (4, 999, 100, 2, 'Orphan', 1)");
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
echo "         plus review 3 (category_id = 0, the column default) and review 4 (orphan, category 999)\n";
echo "         both seeded with FOREIGN_KEY_CHECKS off, because the live constraint refuses them --\n";
echo "         see the note above: the column's own default cannot satisfy its own foreign key.\n";

$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config([
    'database.default' => 'verify_mysql',
    'database.connections.verify_mysql' => array_merge(
        config('database.connections.mysql'),
        [
            'driver' => 'mysql', 'database' => $database, 'username' => $user, 'password' => $pass,
            'host' => $host, 'port' => $port, 'unix_socket' => file_exists($socket) ? $socket : '',
        ]
    ),
]);
Illuminate\Support\Facades\DB::purge();

$migrationFile = $root . '/database/migrations/2026_10_23_000100_product_reviews_category_id_on_delete_set_null.php';
$out('the migration file exists', is_file($migrationFile), $migrationFile);

$migration = require $migrationFile;
$migration->up();
$out('migration up() ran without error', true);

echo "\nSTEP 5 -- the constraint, read back from information_schema.\n";
$out('DELETE_RULE is now SET NULL', $deleteRule($pdo) === 'SET NULL',
    'Actual: ' . var_export($deleteRule($pdo), true));

$nullable = $pdo->query("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()"
    . " AND TABLE_NAME = 'product_reviews' AND COLUMN_NAME = 'category_id'")->fetchColumn();
$out('and the column is nullable, which is what makes the rule legal', $nullable === 'YES',
    'Actual IS_NULLABLE: ' . var_export($nullable, true));

echo "\nSTEP 7 -- DATA PRESERVATION: no review row was deleted, and the two unreferencable ones are NULL.\n";
$total = (int) $pdo->query('SELECT COUNT(*) FROM product_reviews')->fetchColumn();
$out('all four reviews are still present after the migration', $total === 4, 'Actual: ' . $total);

$zeroRow = $pdo->query('SELECT category_id FROM product_reviews WHERE id = 3')->fetch(PDO::FETCH_ASSOC);
$out("the `category_id = 0` row became NULL rather than being deleted", $zeroRow['category_id'] === null,
    'Actual: ' . var_export($zeroRow['category_id'], true));

$orphanRow = $pdo->query('SELECT category_id FROM product_reviews WHERE id = 4')->fetch(PDO::FETCH_ASSOC);
$out('the ORPHAN row became NULL rather than blocking the constraint', $orphanRow['category_id'] === null,
    'Actual: ' . var_export($orphanRow['category_id'], true));

echo "\nSTEP 6 -- THE SAME SEQUENCE AGAIN: the reviews now SURVIVE.\n";
$pdo->exec('UPDATE products SET category_id = 20 WHERE id = 100');
$pdo->exec('DELETE FROM categories WHERE id = 10');

$surviving = (int) $pdo->query('SELECT COUNT(*) FROM product_reviews')->fetchColumn();
$out('every review survived the category deletion: 4 -> ' . $surviving, $surviving === 4,
    'This is the whole point of the change. Actual: ' . $surviving);

$nulled = (int) $pdo->query('SELECT COUNT(*) FROM product_reviews WHERE category_id IS NULL')->fetchColumn();
$out('and the two that pointed at the deleted category now point at nothing', $nulled === 4,
    'Rows 1 and 2 were SET NULL by the constraint; 3 and 4 were already NULL. Actual: ' . $nulled);

$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$stillReferences = (int) $pdo->query('SELECT COUNT(*) FROM product_reviews r LEFT JOIN categories c'
    . ' ON c.id = r.category_id WHERE r.category_id IS NOT NULL AND c.id IS NULL')->fetchColumn();
$out('no review points at a category that does not exist', $stillReferences === 0, 'Actual: ' . $stillReferences);

echo "\nSTEP 8 -- RE-RUNNABILITY: applying it again is a no-op, not an error.\n";
$again = true;
$againMessage = '';

try {
    $migration2 = require $migrationFile;
    $migration2->up();
} catch (Throwable $e) {
    $again = false;
    $againMessage = $e->getMessage();
}

$out('a second up() succeeds', $again, $againMessage);
$out('and the rule is still SET NULL', $deleteRule($pdo) === 'SET NULL',
    'Actual: ' . var_export($deleteRule($pdo), true));

echo "\nSTEP 9 -- down() restores the module's declared CASCADE.\n";
$migration3 = require $migrationFile;
$migration3->down();
$out('DELETE_RULE is CASCADE again', $deleteRule($pdo) === 'CASCADE',
    'Actual: ' . var_export($deleteRule($pdo), true));

echo "\nSTEP 10 -- the throwaway database is dropped.\n";
Illuminate\Support\Facades\DB::disconnect();
$pdo->exec("DROP DATABASE `{$database}`");
$out("database `{$database}` dropped", true);

echo "\n" . ($failed ? "RESULT: FAILED -- at least one assertion did not hold.\n" : "RESULT: every assertion held.\n");

exit($failed ? 1 : 0);
