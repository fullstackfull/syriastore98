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

$pdo->exec("DROP DATABASE `{$database}`");

printf("\n%d checks, %d failure(s) on MySQL %s\n", 3, $failures, $version);

if ($failures === 0) {
    echo "\nThe cascade is production behaviour. The regression test in\n"
        . "tests/Feature/Security/MerchantCategoryOwnershipTest.php pins it deliberately; the\n"
        . "cross-tenant path into it is closed by P1-INV-08's ownership guard, but the cascade\n"
        . "itself is tracked separately -- no controller predicate can reach it.\n";
}

exit($failures === 0 ? 0 : 1);
