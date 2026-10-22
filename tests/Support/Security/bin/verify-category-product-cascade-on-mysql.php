#!/usr/bin/env php
<?php

/**
 * P1-INV-08 — does deleting a category really delete other stores' PRODUCTS on MySQL?
 * =============================================================================================
 *
 * Found while testing P1-INV-08's product-loop predicate: the regression test asserted that a
 * foreign product sharing the caller's category kept its rows, and it FAILED — the row was gone.
 * `products.category_id` is declared
 *
 *     $table->foreign('category_id')->references('id')->on('categories')->onDelete('cascade');
 *
 * at `database/migrations/2023_12_25_*_create_products_table.php:55`, and
 * `product_variants.product_id` cascades again off `products`. So `$category->delete()` removes
 * every product row in the category and every variant under it, for every store, BELOW the
 * application — no controller predicate can reach it.
 *
 * That corrected a claim this project had already published: the first drafts of the S38-1 queue
 * entry and of GitHub issue #49 said "the `products` rows themselves survive; everything that
 * makes them a sellable catalogue does not". They do not survive.
 *
 * WHY THIS IS A STANDALONE SCRIPT AND NOT A TEST. The suite runs on SQLite, where
 * `foreign_key_constraints` defaults to true (`config/database.php:39`) — so SQLite agreeing
 * proves nothing about production, which is MySQL. CLAUDE.md records SQLite masking real MySQL
 * defects in both directions, and the live schema may not even carry the constraint (GATE F
 * records 15 tables that live code reads with no migration at all). This asks the engine
 * directly, and asks `information_schema` as well so the answer does not rest on observed
 * behaviour alone.
 *
 * It creates and drops its own throwaway database and refuses a name without 'verify' in it.
 *
 *   CASCADE_MYSQL_SOCKET=/var/run/mysqld/mysqld.sock \
 *   CASCADE_MYSQL_DATABASE=cascade_verify \
 *   CASCADE_MYSQL_USERNAME=root \
 *   CASCADE_MYSQL_PASSWORD= \
 *   php tests/Support/Security/bin/verify-category-product-cascade-on-mysql.php
 *
 * or CASCADE_MYSQL_HOST / CASCADE_MYSQL_PORT instead of a socket.
 *
 * WHAT A PASS MEANS: the cascade fires on MySQL, so the behaviour the regression test pins is
 * production behaviour and not a SQLite artefact. It does NOT assert anything about the live
 * Store Syria database, whose schema this script never touches and cannot see.
 */

$database = getenv('CASCADE_MYSQL_DATABASE') ?: 'cascade_verify';

if (! str_contains($database, 'verify')) {
    fwrite(STDERR, "REFUSING: the database name '{$database}' does not contain 'verify'.\n"
        . "This script issues DROP DATABASE and will only do so against a name that says it is\n"
        . "a throwaway. Pick another name.\n");
    exit(2);
}

$socket   = getenv('CASCADE_MYSQL_SOCKET') ?: '';
$host     = getenv('CASCADE_MYSQL_HOST') ?: '127.0.0.1';
$port     = getenv('CASCADE_MYSQL_PORT') ?: '3306';
$username = getenv('CASCADE_MYSQL_USERNAME') ?: 'root';
$password = getenv('CASCADE_MYSQL_PASSWORD');
$password = $password === false ? '' : $password;

$dsn = $socket !== ''
    ? "mysql:unix_socket={$socket}"
    : "mysql:host={$host};port={$port}";

try {
    $pdo = new PDO($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    fwrite(STDERR, "PRECONDITION NOT MET: cannot reach MySQL ({$e->getMessage()}).\n"
        . "This script needs a MySQL server it may create and drop a database on.\n");
    exit(3);
}

$version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();

$pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
$pdo->exec("CREATE DATABASE `{$database}`");
$pdo->exec("USE `{$database}`");

// The DDL the migrations declare, reduced to the columns this question needs.
$pdo->exec('CREATE TABLE categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(191) NULL,
    store_id BIGINT UNSIGNED NULL
) ENGINE=InnoDB');

$pdo->exec('CREATE TABLE products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(191) NULL,
    category_id BIGINT UNSIGNED NULL,
    store_id BIGINT UNSIGNED NULL,
    CONSTRAINT products_category_id_foreign FOREIGN KEY (category_id)
        REFERENCES categories (id) ON DELETE CASCADE
) ENGINE=InnoDB');

$pdo->exec('CREATE TABLE product_variants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    stock INT DEFAULT 0,
    CONSTRAINT product_variants_product_id_foreign FOREIGN KEY (product_id)
        REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB');

$pdo->exec("INSERT INTO categories (id, name, store_id) VALUES (1, 'store-A-category', 1)");
$pdo->exec("INSERT INTO products (id, name, category_id, store_id) VALUES
    (1, 'A-own-product', 1, 1),
    (2, 'B-foreign-product', 1, 2)");
$pdo->exec('INSERT INTO product_variants (id, product_id, stock) VALUES (1, 1, 50), (2, 2, 50)');

$count = static fn (string $table): int
    => (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    printf("%s  %s\n", $ok ? '[PASS]' : '[FAIL]', $label);
    if (! $ok) {
        $failures++;
    }
};

printf("MySQL %s, database `%s`\n\n", $version, $database);
printf("before: products=%d  product_variants=%d\n", $count('products'), $count('product_variants'));

$pdo->exec('DELETE FROM categories WHERE id = 1');

$products = $count('products');
$variants = $count('product_variants');
printf("after DELETE FROM categories: products=%d  product_variants=%d\n\n", $products, $variants);

$check(
    'the FK cascade deleted BOTH products, store B\'s among them -- a category delete is a '
    . 'product delete, across stores',
    $products === 0
);
$check(
    'the cascade chained on to product_variants, so the stock rows went with them',
    $variants === 0
);

$rule = (string) $pdo->query(
    "SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = '{$database}'
       AND CONSTRAINT_NAME = 'products_category_id_foreign'"
)->fetchColumn();

$check(
    "information_schema reports DELETE_RULE = CASCADE (got '{$rule}'), so the behaviour above is "
    . 'the declared constraint and not an artefact of how the rows were written',
    $rule === 'CASCADE'
);

// =============================================================================================
//  SECTION B -- OWNER DECISION D13: WHAT AN ID LIST HAS TO BE ASKED, AND WHAT IT MUST NOT BE
// =============================================================================================
//
// Added with the D13 refusal (P1-INV-12). The three refusals that now stand in front of this
// cascade all count `products` rows for a list of category ids, and two of them get that list
// from request input:
//
//   - CategoryController::destroy          -- one id, from the route parameter
//   - BulkDeleteTenantGuard                -- an array, from the posted `ids`
//   - Api\DashboardController::DeleteCategory -- one id, from the posted `category_id`
//
// The bulk path is the one that matters here, because the vendored controller passes the SAME
// list straight into `whereIn('id', $request->ids)->delete()`. If the guard's count and the
// controller's delete disagree about which rows a value names, the guard reads zero and the
// delete still cascades. So `BulkDeleteTenantGuard::scalarIds()` deliberately does NOT run
// FILTER_VALIDATE_INT -- it hands the raw strings to the same engine the delete will use.
//
// These two checks measure that the risk is real on MySQL rather than asserting it, and that the
// mitigation (asking the database, not PHP) actually answers it. SQLite cannot show either: its
// comparison is not MySQL's.

$pdo->exec("USE `{$database}`");

// Section A's cascade test ended with `DELETE FROM categories`, so a row has to be put back
// before anything can be asked about matching one. The first draft of this section asked against
// the emptied table and read 0 -- a FAIL that said nothing about coercion.
$pdo->exec("INSERT INTO categories (id, name, store_id) VALUES (1, 'coercion-probe', 1)");

$probeRows = (int) $pdo->query('SELECT COUNT(*) FROM categories WHERE id = 1')->fetchColumn();

$check(
    "the coercion probe row really exists (found {$probeRows}), so the two checks below are "
    . 'asked of a populated table rather than an empty one',
    $probeRows === 1
);

$coerced = $pdo->query("SELECT COUNT(*) FROM categories WHERE id IN ('1abc')")->fetchColumn();

$check(
    "MySQL coerces '1abc' to 1 inside WHERE id IN (...) (matched {$coerced} row(s)), so a guard "
    . 'that validated ids in PHP first would have counted products for a DIFFERENT set than the '
    . 'delete removes -- which is why scalarIds() passes the raw values through',
    (int) $coerced === 1
);

$phpValidated = filter_var('1abc', FILTER_VALIDATE_INT);

$check(
    'and FILTER_VALIDATE_INT rejects that same value in PHP, so the two layers genuinely '
    . 'disagree: the mismatch is not hypothetical',
    $phpValidated === false
);

$pdo->exec("DROP DATABASE `{$database}`");

printf("\n%d checks, %d failure(s) on MySQL %s\n", 6, $failures, $version);

if ($failures === 0) {
    echo "\nThe cascade is production behaviour, so an application refusal is the only control\n"
        . "available until the constraint itself is changed by an approved migration (D15).\n"
        . "The three refusals are pinned by MerchantCategoryOwnershipTest (controller),\n"
        . "BulkDeleteAuthorizationTest (the addon route) and AdminApiTenancyTest (the endpoint\n"
        . "the shipped admin app calls). Section B is why the bulk guard asks the DATABASE which\n"
        . "rows an id list names instead of validating the list in PHP.\n\n"
        . "This asserts nothing about the live Store Syria database, whose schema this script\n"
        . "never touches and cannot see.\n";
}

exit($failures === 0 ? 0 : 1);
