<?php

/**
 * `CUST-003` / `GAP-190` — THE STORED ARABIC FOLD, AGAINST A REAL MySQL/MariaDB SERVER.
 *
 * WHY THIS IS A SCRIPT AND NOT A PHPUNIT TEST. The suite runs on SQLite `:memory:`; production is
 * MySQL. Three of the four questions below are answerable only by an engine with real collations,
 * and a conditionally-skipped test would break `.github/known-test-outcomes.json`'s declared skip
 * set. Convention set by `verify-sec13-migration-on-mysql.php` beside this file: evidence that
 * cannot live in the suite lives in an executable script that states its own preconditions.
 *
 * -------------------------------------------------------------------------------------------------
 * WHY COLLATION IS A REAL QUESTION HERE AND NOT A FORMALITY
 * -------------------------------------------------------------------------------------------------
 *
 * `App\Support\Search\ProductNameIndex::applyNameFilter()` emits
 *
 *     ( name LIKE '%<term>%'  OR  name_normalised LIKE '%<fold(term)>%' )
 *
 * and the slice is forbidden to change what a LATIN-SCRIPT query returns. The argument that it
 * cannot is: *the fold of a Latin name is its own lower case, and `LIKE` is case-insensitive under
 * the default collation, so the second limb's matches are a subset of the first's.* **That argument
 * is only true for a case-insensitive collation.** Under a BINARY or `_bin` collation,
 * `name LIKE '%Blue%'` would not match `blue cotton SHIRT` while `name_normalised LIKE '%blue%'`
 * would — and the union would gain a row. An earlier workstream in this repository measured the
 * mirror image of exactly this: *a lower-cased `IN()` matches a stored capitalised value on MariaDB
 * and not on SQLite.* So the claim is executed here rather than reasoned about.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT IT ESTABLISHES, BY EXECUTION
 * -------------------------------------------------------------------------------------------------
 *
 *   1. the migration adds `products.name_normalised` on MySQL, as a nullable `text` whose collation
 *      is the SAME as `products.name` — which is what makes the two limbs comparable;
 *   2. `text` was the right choice: a 255-character name whose fold is LONGER than 255 characters
 *      round-trips without truncation, where a mirror `varchar(255)` would silently cut it under
 *      `strict => false`;
 *   3. the feature works on the real engine: a shopper's plainly-typed Arabic term reaches a
 *      correctly-spelled product, in BOTH directions;
 *   4. and the Latin control holds under the production collation: for every Latin term, the union
 *      the new predicate returns is EXACTLY the set the old single-limb predicate returned.
 *
 * It also reports, without failing on it, whether the server's own collation folds anything the
 * PHP fold does not — information, not a verdict: such a difference can only widen the second limb,
 * and the Latin control in (4) is what decides whether that matters.
 *
 * HOW TO RUN. Point it at any throwaway server you control. It creates and drops its own database
 * and touches nothing else:
 *
 *   FOLD_MYSQL_SOCKET=/path/to/mysqld.sock \
 *   FOLD_MYSQL_DATABASE=fold_verify \
 *   SS_PROBE_USER=ssprobe SS_PROBE_PASS=ssprobe \
 *   php tests/Support/Security/bin/verify-product-name-fold-on-mysql.php
 *
 * or with FOLD_MYSQL_HOST / FOLD_MYSQL_PORT instead of a socket. Add FOLD_MYSQL_COLLATION to run
 * the whole thing under a different collation; `utf8mb4_bin` is the interesting one, because it is
 * the case in which the Latin control is EXPECTED to fail and the script should say so rather than
 * be silent.
 *
 * NEVER POINT IT AT THE DEVELOPMENT OR PRODUCTION SERVER. It issues CREATE DATABASE and
 * DROP DATABASE, and refuses a database name containing neither 'fold' nor 'verify'.
 *
 * Exit code 0 means every assertion held.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\Search\ProductNameIndex;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$failures = [];
$checks = 0;

function ok(string $what): void
{
    global $checks;
    $checks++;
    echo "  PASS  {$what}\n";
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

function note(string $what): void
{
    echo "  NOTE  {$what}\n";
}

// --------------------------------------------------------------------------------- preconditions

$database = getenv('FOLD_MYSQL_DATABASE') ?: 'fold_verify';

if (! str_contains($database, 'fold') && ! str_contains($database, 'verify')) {
    fwrite(STDERR, "REFUSING: the database name '{$database}' contains neither 'fold' nor 'verify'.\n"
        . "This script issues DROP DATABASE. Name the throwaway database accordingly.\n");
    exit(2);
}

$socket = getenv('FOLD_MYSQL_SOCKET') ?: '';
$host = getenv('FOLD_MYSQL_HOST') ?: '127.0.0.1';
$port = getenv('FOLD_MYSQL_PORT') ?: '3306';
/*
 * `ssprobe`/`ssprobe` IS THIS REPOSITORY'S CONVENTION FOR THESE SCRIPTS, and not root:
 * MariaDB's root authenticates over the unix socket, which PDO cannot do.
 * `verify-dashboard-rankings-on-mysql.php` states it and `run-mysql-verifications.sh` carries the
 * one-line CREATE USER. `SS_PROBE_USER`/`SS_PROBE_PASS` are honoured so the shared runner works.
 */
$username = getenv('FOLD_MYSQL_USERNAME') ?: (getenv('SS_PROBE_USER') ?: 'ssprobe');
$password = getenv('FOLD_MYSQL_PASSWORD');
$password = $password === false ? (getenv('SS_PROBE_PASS') ?: 'ssprobe') : $password;
$collation = getenv('FOLD_MYSQL_COLLATION') ?: 'utf8mb4_unicode_ci';

$shared = [
    'driver' => 'mysql',
    'host' => $host,
    'port' => $port,
    'unix_socket' => $socket,
    'username' => $username,
    'password' => $password,
    'charset' => 'utf8mb4',
    'collation' => $collation,
    'prefix' => '',
    'prefix_indexes' => true,
    /*
     * `strict => false` MIRRORS PRODUCTION, and it is the setting under which a too-narrow column
     * TRUNCATES instead of erroring -- which is the whole point of check 2.
     */
    'strict' => false,
    'engine' => null,
];

config([
    'database.connections.fold' => $shared + ['database' => $database],
    'database.connections.fold_root' => $shared + ['database' => null],
]);

echo "CUST-003 STORED ARABIC FOLD, AGAINST A REAL MySQL/MariaDB SERVER\n";
echo str_repeat('=', 86) . "\n";

try {
    $version = DB::connection('fold_root')->selectOne('SELECT VERSION() AS v')->v;
} catch (\Throwable $e) {
    fwrite(STDERR, "\nCould not reach MySQL: " . $e->getMessage() . "\n"
        . "Set FOLD_MYSQL_SOCKET (or FOLD_MYSQL_HOST/PORT) to a throwaway server.\n");
    exit(3);
}

echo "server:    {$version}\n";
echo "database:  {$database} (created and dropped by this script)\n";
echo "collation: {$collation}\n\n";

DB::connection('fold_root')->statement("DROP DATABASE IF EXISTS `{$database}`");
DB::connection('fold_root')->statement(
    "CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE {$collation}");

$exit = 0;

try {
    DB::setDefaultConnection('fold');
    Schema::connection('fold');

    /*
     * A MINIMAL `products` TABLE, not the real migration set: this script is about two columns and
     * one predicate, and running 100+ migrations would make a failure here hard to attribute.
     * `name` is declared exactly as `2023_12_26_092822_create_products_table.php` declares it.
     */
    DB::connection('fold')->statement("
        CREATE TABLE products (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            store_id INT NULL,
            status INT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE={$collation}
    ");

    // ------------------------------------------------------------------ 1 — the migration on MySQL

    echo "1. THE MIGRATION ADDS THE COLUMN, WITH `name`'s OWN COLLATION\n";

    $migration = require __DIR__
        . '/../../../../database/migrations/2026_11_27_000100_add_name_normalised_to_products_table.php';
    $migration->up();

    check(Schema::connection('fold')->hasColumn('products', 'name_normalised'),
        'products.name_normalised exists after up()');

    $columns = DB::connection('fold')->select("
        SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLLATION_NAME, CHARACTER_MAXIMUM_LENGTH
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'products' AND COLUMN_NAME IN ('name','name_normalised')
    ", [$database]);

    $byName = [];
    foreach ($columns as $c) {
        $byName[$c->COLUMN_NAME] = $c;
    }

    check(($byName['name_normalised']->DATA_TYPE ?? '') === 'text',
        'it is a TEXT column', 'got ' . ($byName['name_normalised']->DATA_TYPE ?? 'nothing'));

    check(($byName['name_normalised']->IS_NULLABLE ?? '') === 'YES',
        'it is NULLABLE, so "nobody has folded this row" is expressible');

    check(($byName['name_normalised']->COLLATION_NAME ?? 'a')
        === ($byName['name']->COLLATION_NAME ?? 'b'),
        'it carries the SAME collation as products.name',
        sprintf('name=%s name_normalised=%s',
            $byName['name']->COLLATION_NAME ?? '?', $byName['name_normalised']->COLLATION_NAME ?? '?'));

    /* `down()` is a documented no-op; that is a property worth executing, not just asserting. */
    $migration->down();
    check(Schema::connection('fold')->hasColumn('products', 'name_normalised'),
        'down() is a no-op and does NOT drop the column (the cost_price rollback lesson)');

    // --------------------------------------------------- 2 — TEXT rather than a mirror varchar(255)

    echo "\n2. `text` RATHER THAN `varchar(255)`: A FOLD CAN BE LONGER THAN ITS INPUT\n";

    /* 'İ' (U+0130) lower-cases to TWO characters in PHP. 255 of them fold to 510. */
    $long = str_repeat("\u{0130}", 255);
    $foldedLong = ProductNameIndex::normalise($long);

    check(mb_strlen($long) === 255, 'the fixture name is exactly 255 characters');
    check(mb_strlen($foldedLong) > 255,
        'its fold is LONGER than 255 characters, so a mirror varchar(255) would truncate it',
        'fold is ' . mb_strlen($foldedLong) . ' characters');

    DB::connection('fold')->table('products')->insert([
        'name' => $long, 'name_normalised' => $foldedLong, 'store_id' => 1, 'status' => 1,
    ]);

    $readBack = DB::connection('fold')->table('products')
        ->where('name', $long)->value('name_normalised');

    check($readBack === $foldedLong,
        'the TEXT column round-trips the over-long fold with no truncation',
        sprintf('stored %d characters, read back %d',
            mb_strlen($foldedLong), mb_strlen((string) $readBack)));

    /* And the counter-factual, so the choice is shown rather than asserted. */
    DB::connection('fold')->statement(
        "ALTER TABLE products ADD COLUMN fold_varchar VARCHAR(255) NULL COLLATE {$collation}");
    DB::connection('fold')->table('products')->where('name', $long)
        ->update(['fold_varchar' => $foldedLong]);
    $truncated = DB::connection('fold')->table('products')->where('name', $long)->value('fold_varchar');

    check(mb_strlen((string) $truncated) < mb_strlen($foldedLong),
        'a mirror VARCHAR(255) DID truncate the same value, which is why the column is TEXT',
        sprintf('varchar kept %d of %d characters',
            mb_strlen((string) $truncated), mb_strlen($foldedLong)));

    DB::connection('fold')->statement('ALTER TABLE products DROP COLUMN fold_varchar');
    DB::connection('fold')->table('products')->truncate();

    // --------------------------------------------------------------- 3 — the feature on the engine

    echo "\n3. THE FEATURE WORKS ON THE REAL ENGINE, IN BOTH DIRECTIONS\n";

    $insert = function (string $name, int $store = 1, int $status = 1) {
        DB::connection('fold')->table('products')->insert([
            'name' => $name,
            'name_normalised' => ProductNameIndex::normalise($name),
            'store_id' => $store, 'status' => $status,
        ]);

        return (int) DB::connection('fold')->getPdo()->lastInsertId();
    };

    $written = $insert('شاشة ذكية');            // correct orthography, store 1
    $plain = $insert('شاشه ذكيه');              // plain spelling, store 1
    $foreign = $insert('شاشة ذكية', 2);         // ANOTHER store
    $unpublished = $insert('شاشة ذكية', 1, 0);  // unpublished

    $search = function (string $term): array {
        $query = DB::connection('fold')->table('products')
            ->where('store_id', 1)->where('status', 1);

        ProductNameIndex::applyNameFilter($query, $term);

        return array_map('intval', $query->orderBy('id')->pluck('id')->all());
    };

    $typedHits = $search('شاشه ذكيه');
    check(in_array($written, $typedHits, true),
        'a plainly TYPED term reaches the correctly-spelled product');
    check(in_array($plain, $typedHits, true),
        'and still reaches the plainly-stored one (premise: the raw limb works)');

    $writtenHits = $search('شاشة ذكية');
    check(in_array($plain, $writtenHits, true),
        'THE OTHER DIRECTION: a correctly-spelled term reaches the plainly-stored product');

    check(! in_array($foreign, $typedHits, true) && ! in_array($foreign, $writtenHits, true),
        'the OR is grouped: another store\'s product is not returned');
    check(! in_array($unpublished, $typedHits, true) && ! in_array($unpublished, $writtenHits, true),
        'the OR is grouped: an unpublished product is not returned (GAP-351 holds)');

    /* The degradation path, on the real engine. */
    DB::connection('fold')->table('products')->where('id', $written)
        ->update(['name_normalised' => null]);
    check(in_array($written, $search('شاشة ذكية'), true),
        'a product whose fold is NULL is still found by its exact name');

    DB::connection('fold')->table('products')->truncate();

    // ------------------------------------------------------------------ 4 — THE LATIN CONTROL

    echo "\n4. THE LATIN CONTROL, UNDER THIS SERVER'S COLLATION\n";

    foreach ([
        'Blue Cotton Shirt', 'blue cotton SHIRT', 'Sony — WH-1000', 'Sony - WH-1000',
        'Café Noir', 'Cafe Noir', '50% Linen Mix', 'ACME-500/XL', 'STRASSE', 'strasse',
    ] as $name) {
        $insert($name);
    }

    $latinProblems = [];

    foreach (['Blue', 'blue cotton', 'SHIRT', 'Sony -', 'Sony —', 'Caf', '50%', '%Linen',
        'acme-500', 'STRASSE', 'strasse'] as $term) {
        /* The predicate the doors ran BEFORE this change. */
        $before = array_map('intval', DB::connection('fold')->table('products')
            ->where('store_id', 1)->where('status', 1)
            ->where('name', 'LIKE', '%' . $term . '%')
            ->orderBy('id')->pluck('id')->all());

        $after = $search($term);

        if ($before !== $after) {
            $latinProblems[] = sprintf('"%s": was [%s], now [%s]',
                $term, implode(',', $before), implode(',', $after));
        }
    }

    check($latinProblems === [],
        'every Latin-script term returns EXACTLY the rows the old single-limb predicate returned',
        implode(' | ', $latinProblems));

    // ---------------------------------------------------- information: what the SERVER itself folds

    echo "\n5. INFORMATION, NOT A VERDICT: WHAT THIS COLLATION FOLDS THAT THE PHP FOLD DOES NOT\n";

    foreach ([
        ['ا', 'أ', 'alef / hamza-on-alef'],
        ['ه', 'ة', 'heh / teh marbuta'],
        ['ي', 'ى', 'yeh / alef maqsura'],
        ['e', 'é', 'e / e-acute'],
        ['a', 'A', 'a / A'],
    ] as [$left, $right, $label]) {
        $equal = (bool) DB::connection('fold')
            ->selectOne('SELECT ? = ? AS eq', [$left, $right])->eq;

        $phpEqual = ProductNameIndex::normalise($left) === ProductNameIndex::normalise($right);

        note(sprintf('%-24s engine says %-3s, the PHP fold says %s%s',
            $label, $equal ? 'YES' : 'no', $phpEqual ? 'YES' : 'no',
            $equal && ! $phpEqual ? '   <- the engine is wider here, which can only ADD matches' : ''));
    }
} catch (\Throwable $e) {
    bad('the script ran to completion', get_class($e) . ': ' . $e->getMessage());
    $exit = 1;
} finally {
    DB::connection('fold')->disconnect();
    DB::connection('fold_root')->statement("DROP DATABASE IF EXISTS `{$database}`");
    echo "\n(throwaway database dropped)\n";
}

echo "\n" . str_repeat('=', 86) . "\n";
echo sprintf("%d checks, %d failed\n", $checks, count($failures));

foreach ($failures as $f) {
    echo "  FAILED: {$f}\n";
}

exit($failures === [] ? $exit : 1);
