<?php

/**
 * GAP-121 / `P04-EV-01` (2 of 4) -- TAX BANDING, measured on real MySQL/MariaDB.
 * =================================================================================================
 *
 * THIS SCRIPT MEASURES AND DOES NOT FIX. The double-applied band is an OPEN OWNER DECISION:
 * `Gap143ShippingTaxOrderTotalsTest::test_the_charged_total_applies_the_band_twice_which_is_an_owner_decision`
 * pins the charged total at **267.00** where a single application of the merchant's own 10% band
 * would charge **245.00**, and it is written so that a FIX fails it with the new figure rather than
 * passing unnoticed. CLAUDE.md §2 reserves "material changes to prices/payments" to the owner, and
 * the gap register's next action for `GAP-121` asks for EVIDENCE, not a correction. So nothing here
 * changes an amount; §C makes the 22.00 concrete on the engine the money is really stored on, and
 * §D reports a second, separate finding rather than repairing it.
 *
 * WHAT IS GENUINELY ENGINE-SPECIFIC, AND WHAT IS NOT.
 *
 *   NOT engine-specific: the double application itself. It is PHP arithmetic in
 *   `Product::ProductPrice()` and `PaymentService`, and the SQLite suite is the right instrument
 *   for it -- which is why the case named above exists and stays.
 *
 *   ENGINE-SPECIFIC, and each measured below:
 *     (a) §B. The five money columns are `$table->float()`, which this engine emits as `double`.
 *         A plausible excuse for a 22.00 discrepancy is "binary floating point", and §B RETIRES
 *         that excuse by round-tripping both figures exactly. The gap is arithmetic, not storage.
 *     (b) §D. `tax_methods.priority` and `tax_methods.tax_rate` are **VARCHAR**, and
 *         `Product::ProductPrice()` picks the band with
 *         `TaxMethod::where(...)->orderBy('priority', 'asc')->first()`. Ordering a VARCHAR is a
 *         LEXICOGRAPHIC sort performed BY THE SERVER, so `'10'` sorts before `'9'` and the band
 *         charged is not the numerically-first one. Which row `first()` returns is the engine's
 *         answer, not PHP's, and the suite cannot see it.
 *     (c) §E. MySQL's default collation is case- and accent-insensitive where SQLite's is binary,
 *         so a `where('name', ...)` over tax-method names admits rows on this engine that SQLite
 *         refuses -- the P1-SEC-70/78 family, here on a money table. Asked under BOTH
 *         `utf8mb4_general_ci` and `utf8mb4_unicode_ci`, because the measured production dump
 *         carries a MIX and this script must not pick one and call it the answer.
 *
 * NO TRANSACTIONS ARE OPENED: the pricing readers run outside one, and wrapping a harness in a
 * transaction the code does not have is a true fact about the engine and a false model of the code.
 *
 * HOW TO RUN. Needs a throwaway MySQL or MariaDB. It creates and drops its OWN database and refuses
 * a name that does not say it is throwaway:
 *
 *     php tests/Support/Security/bin/verify-tax-band-totals-on-mysql.php
 *
 * NEVER point FRESH_MYSQL_DATABASE at the development or production server. Exits 2 -- not 0 and
 * not 1 -- when a precondition is unmet, because a verification that could not run has neither
 * passed nor failed.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\TaxMethod;
use Illuminate\Support\Facades\DB;

$checks = 0;
$failures = 0;

/**
 * The CODE this script is about, read from source with comments stripped.
 *
 * WHY THIS IS HERE. Some sections below reproduce a production expression rather than calling it,
 * because calling it needs a store, theme, plan, customer, cart, billing, coupon, tax-method and
 * payment-settings fixture -- the same reason `OrderWriteTransactionTest`'s own docblock gives for
 * being structural. A reproduction that drifts from its original measures nothing, so each one is
 * tied to the source line it reproduces. Comments are stripped, because a docblock quoting the
 * expression would otherwise satisfy the tie (CLAUDE.md records that trap eight times).
 */
function sourceCodeOf(string $relativePath): string
{
    $absolute = __DIR__ . '/../../../../' . $relativePath;

    if (! is_file($absolute)) {
        fwrite(STDERR, "PRECONDITION NOT MET: {$relativePath} is absent, so this script cannot tell\n");
        fwrite(STDERR, "whether the expressions it reproduces still match production.\n");
        exit(2);
    }

    $code = '';
    foreach (token_get_all((string) file_get_contents($absolute)) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            $code .= $token[1];
        } else {
            $code .= $token;
        }
    }

    return $code;
}

function check(string $what, bool $ok, string $detail = ''): void
{
    global $checks, $failures;
    $checks++;
    if ($ok) {
        echo "  ok    {$what}\n";
    } else {
        $failures++;
        echo "  FAIL  {$what}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
    }
}

/* ---------------------------------------------------------------- preconditions */

$database = getenv('FRESH_MYSQL_DATABASE') ?: 'tax_band_totals_verify';

if (! preg_match('/(fresh|verify)/i', $database)) {
    fwrite(STDERR, "Refusing to run against a database whose name says nothing about being throwaway: {$database}\n");
    exit(2);
}

$socket = getenv('FRESH_MYSQL_SOCKET');
if ($socket === false) {
    foreach (['/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock', '/var/lib/mysql/mysql.sock'] as $candidate) {
        if (file_exists($candidate)) {
            $socket = $candidate;
            echo "using the unix socket at {$candidate} (set FRESH_MYSQL_SOCKET='' to force TCP)\n";
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
    /* Production's setting (config/database.php). Strict mode refuses writes production accepts, so
       a harness in strict mode measures a server this application never runs on. */
    'strict' => false,
];

$bootstrap = $connection;
$bootstrap['database'] = null;
config(['database.connections.fresh_bootstrap' => $bootstrap]);
config(['database.connections.fresh' => $connection]);
config(['database.default' => 'fresh']);

/* Production's cache store, not the suite's `array`. `TaxMethod` is `Cachable`, and a cache serving
   a pre-write band would make §D read a row this script did not store. */
config(['cache.default' => 'database']);
config(['cache.stores.database.connection' => 'fresh']);

try {
    DB::connection('fresh_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
    DB::connection('fresh_bootstrap')->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4");
} catch (Throwable $e) {
    fwrite(STDERR, "PRECONDITION NOT MET: could not create a throwaway database -- {$e->getMessage()}\n");
    exit(2);
}

$server = DB::connection('fresh')->selectOne('SELECT VERSION() AS v')->v;
echo "server  : {$server}\n";
echo "database: {$database}\n\n";

/* The figures GAP-143 measured, derived here rather than copied, so a reader can check the
   arithmetic without leaving the file. */
const GOODS = 200.00;       // 2 x 100.00
const RATE = 10.0;          // the merchant's configured band, per cent
const SHIPPING = 25.00;

$singleBand = GOODS * RATE / 100;                                   // 20.00
$correctTotal = GOODS + $singleBand + SHIPPING;                     // 245.00
$bandedGoods = GOODS + $singleBand;                                 // 220.00  (ProductPrice())
$secondBand = $bandedGoods * RATE / 100;                            // 22.00   (applied AGAIN)
$subTotal = $bandedGoods + $secondBand;                             // 242.00
$chargedTotal = $subTotal + SHIPPING;                               // 267.00

try {
    /* The production column types, from the migrations: the five order money columns are
       `$table->float()`, and tax_methods.tax_rate / priority are `$table->string()` -- which is
       the whole of §D. Deliberately NOT `migrate:fresh`. */
    DB::connection('fresh')->statement('
        CREATE TABLE orders (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            store_id BIGINT UNSIGNED NULL,
            product_price DOUBLE NULL DEFAULT 0,
            coupon_price DOUBLE NULL DEFAULT 0,
            delivery_price DOUBLE NULL DEFAULT 0,
            tax_price DOUBLE NULL DEFAULT 0,
            final_price DOUBLE NULL DEFAULT 0,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL
        ) ENGINE=InnoDB
    ');
    DB::connection('fresh')->statement('
        CREATE TABLE tax_methods (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(191) NOT NULL,
            tax_rate VARCHAR(191) NOT NULL DEFAULT "0",
            tax_id BIGINT UNSIGNED NULL,
            country_id BIGINT UNSIGNED NULL,
            state_id BIGINT UNSIGNED NULL,
            city_id BIGINT UNSIGNED NULL,
            priority VARCHAR(191) NOT NULL DEFAULT "0",
            enable_shipping VARCHAR(191) NULL,
            store_id BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL
        ) ENGINE=InnoDB
    ');
    DB::connection('fresh')->statement('
        CREATE TABLE cache (
            `key` VARCHAR(255) NOT NULL PRIMARY KEY,
            `value` MEDIUMTEXT NOT NULL,
            expiration INT NOT NULL
        ) ENGINE=InnoDB
    ');

    // ─────────────────────────── [A] the premise: what the engine really stores
    echo "[A] THE PREMISE -- the column types this path's money actually lives in\n";

    $orderCols = [];
    foreach (DB::connection('fresh')->select('SHOW COLUMNS FROM orders') as $c) {
        $orderCols[$c->Field] = strtolower($c->Type);
    }
    $taxCols = [];
    foreach (DB::connection('fresh')->select('SHOW COLUMNS FROM tax_methods') as $c) {
        $taxCols[$c->Field] = strtolower($c->Type);
    }

    $money = ['product_price', 'coupon_price', 'delivery_price', 'tax_price', 'final_price'];
    $allDouble = true;
    foreach ($money as $m) {
        $allDouble = $allDouble && str_starts_with($orderCols[$m] ?? '', 'double');
    }
    check(
        'all five orders money columns are doubles, as $table->float() emits here',
        $allDouble,
        json_encode(array_intersect_key($orderCols, array_flip($money)))
    );
    check(
        'tax_methods.priority is a VARCHAR, so ORDER BY on it is a text sort',
        str_starts_with($taxCols['priority'] ?? '', 'varchar'),
        'type=' . ($taxCols['priority'] ?? 'absent')
    );
    check(
        'tax_methods.tax_rate is a VARCHAR, so the band is a string multiplied in PHP',
        str_starts_with($taxCols['tax_rate'] ?? '', 'varchar'),
        'type=' . ($taxCols['tax_rate'] ?? 'absent')
    );

    $sqlMode = DB::connection('fresh')->selectOne('SELECT @@SESSION.sql_mode AS m')->m;
    check(
        'the session is NOT in strict mode, matching config/database.php',
        ! str_contains(strtoupper((string) $sqlMode), 'STRICT_'),
        'sql_mode=' . var_export($sqlMode, true)
    );

    // ─────────────────────────── [B] the instrument, and an excuse retired
    echo "\n[B] THE INSTRUMENT -- the 22.00 gap is NOT a floating-point artefact\n";

    DB::connection('fresh')->table('orders')->insert([
        'store_id' => 1,
        'product_price' => $bandedGoods,
        'coupon_price' => 0,
        'delivery_price' => SHIPPING,
        'tax_price' => $secondBand,
        'final_price' => $chargedTotal,
    ]);
    DB::connection('fresh')->table('orders')->insert([
        'store_id' => 1,
        'product_price' => GOODS,
        'coupon_price' => 0,
        'delivery_price' => SHIPPING,
        'tax_price' => $singleBand,
        'final_price' => $correctTotal,
    ]);

    $rows = DB::connection('fresh')->table('orders')->orderBy('id')->get();
    $stored = (float) $rows[0]->final_price;
    $storedCorrect = (float) $rows[1]->final_price;

    check(
        'the double-banded 267.00 round-trips through a double EXACTLY',
        $stored === $chargedTotal,
        'stored=' . var_export($stored, true) . ' computed=' . var_export($chargedTotal, true)
    );
    check(
        'the single-banded 245.00 round-trips through a double EXACTLY',
        $storedCorrect === $correctTotal,
        'stored=' . var_export($storedCorrect, true) . ' computed=' . var_export($correctTotal, true)
    );
    check(
        'and the harness can tell the two figures apart, so §C is not vacuous',
        abs($stored - $storedCorrect) > 0.0001,
        'difference=' . number_format($stored - $storedCorrect, 2)
    );
    printf("        the engine distinguishes %s from %s by exactly %s\n",
        number_format($stored, 2), number_format($storedCorrect, 2),
        number_format($stored - $storedCorrect, 2));

    // ─────────────────────────── [C] the measurement: it reconciles while being wrong
    echo "\n[C] THE MEASUREMENT -- the stored row ADDS UP and is still 22.00 too much\n";

    $r = $rows[0];
    $componentSum = (float) $r->product_price + (float) $r->tax_price
        + (float) $r->delivery_price - (float) $r->coupon_price;

    check(
        'the stored components add up to the stored final_price',
        abs($componentSum - (float) $r->final_price) < 0.0001,
        'components=' . number_format($componentSum, 2)
        . ' final_price=' . number_format((float) $r->final_price, 2)
    );
    check(
        'and that reconciling total is 22.00 more than a single application of the band',
        abs(((float) $r->final_price - $correctTotal) - $secondBand) < 0.0001,
        'excess=' . number_format((float) $r->final_price - $correctTotal, 2)
        . ' second band=' . number_format($secondBand, 2)
    );
    printf(
        "        goods %s | band once %s | band again %s | carriage %s\n",
        number_format(GOODS, 2), number_format($singleBand, 2),
        number_format($secondBand, 2), number_format(SHIPPING, 2)
    );
    printf(
        "        CHARGED %s   CORRECT %s   EXCESS %s  <-- owner decision, not fixed here\n",
        number_format((float) $r->final_price, 2), number_format($correctTotal, 2),
        number_format((float) $r->final_price - $correctTotal, 2)
    );
    check(
        'every "adds up" pin in the suite would pass on this row, which is why the excess is invisible to them',
        abs($componentSum - (float) $r->final_price) < 0.0001,
        'that is the same assertion as the line above, restated as the reason this script exists'
    );

    // ─────────────────────────── [D] a SECOND finding, engine-side, reported not fixed
    echo "\n[D] WHICH BAND IS CHOSEN -- ORDER BY on a VARCHAR priority, decided by the server\n";

    DB::connection('fresh')->table('tax_methods')->truncate();
    (new TaxMethod)->flushCache();
    DB::connection('fresh')->table('tax_methods')->insert([
        ['name' => 'nine', 'tax_rate' => '25', 'tax_id' => 1, 'priority' => '9', 'store_id' => 1],
        ['name' => 'ten', 'tax_rate' => '10', 'tax_id' => 1, 'priority' => '10', 'store_id' => 1],
    ]);

    /* THE EXPRESSION IS TIED TO ITS ORIGINAL, so a reproduction cannot drift into measuring a
       query production no longer runs. */
    check(
        "Product::ProductPrice() still picks the band with orderBy('priority', 'asc')",
        str_contains(sourceCodeOf('app/Models/Product.php'), "orderBy('priority', 'asc')"),
        'the selection has changed, so §D below reproduces a query this application no longer runs '
        . '-- re-read Product::ProductPrice() before trusting anything in this section'
    );

    /* The real selection expression from `Product::ProductPrice()`, not a paraphrase. */
    $chosen = TaxMethod::on('fresh')
        ->where('tax_id', 1)
        ->where('store_id', 1)
        ->orderBy('priority', 'asc')
        ->first();

    $lexicographic = $chosen !== null && $chosen->priority === '10';

    check(
        'the engine orders the VARCHAR priority LEXICOGRAPHICALLY, so "10" precedes "9"',
        $lexicographic,
        'chosen priority=' . var_export($chosen->priority ?? null, true)
        . ' rate=' . var_export($chosen->tax_rate ?? null, true)
        . ' -- if this ever reads "9" the column has become numeric and the finding below is stale'
    );
    printf(
        "        priorities 9 and 10 present; the server returned priority %s at rate %s%%\n",
        var_export($chosen->priority ?? null, true), var_export($chosen->tax_rate ?? null, true)
    );
    printf(
        "        so the band charged is %s%% where the merchant's lowest priority is 9 at 25%%\n",
        var_export($chosen->tax_rate ?? null, true)
    );

    /* THE CONSEQUENCE, PRICED, so the report is a figure and not an adjective. REPORTED, NOT
       FIXED: casting the ORDER BY would change which band every multi-method store charges, which
       is the same class of change as §C and the same owner's to make. */
    $chosenRate = (float) ($chosen->tax_rate ?? 0);
    $intendedRate = 25.0;
    printf(
        "        on %s of goods that is %s of tax instead of %s -- a %s difference per order\n",
        number_format(GOODS, 2),
        number_format(GOODS * $chosenRate / 100, 2),
        number_format(GOODS * $intendedRate / 100, 2),
        number_format(abs(GOODS * $intendedRate / 100 - GOODS * $chosenRate / 100), 2)
    );

    /* AND IT IS THE STRING SORT AND NOT A ROW-ORDER ACCIDENT. Re-inserted in the opposite order:
       if the answer were insertion order, it would change; if it is the sort, it will not. */
    DB::connection('fresh')->table('tax_methods')->truncate();
    (new TaxMethod)->flushCache();
    DB::connection('fresh')->table('tax_methods')->insert([
        ['name' => 'ten', 'tax_rate' => '10', 'tax_id' => 1, 'priority' => '10', 'store_id' => 1],
        ['name' => 'nine', 'tax_rate' => '25', 'tax_id' => 1, 'priority' => '9', 'store_id' => 1],
    ]);
    $again = TaxMethod::on('fresh')
        ->where('tax_id', 1)->where('store_id', 1)->orderBy('priority', 'asc')->first();

    check(
        'and the answer does not depend on insertion order, so it is the sort and not an accident',
        ($again->priority ?? null) === ($chosen->priority ?? null),
        'first pass=' . var_export($chosen->priority ?? null, true)
        . ' reversed insert=' . var_export($again->priority ?? null, true)
    );

    // ─────────────────────────── [E] the collation, on a money table -- BOTH of them
    echo "\n[E] COLLATION -- a case/accent-insensitive match over tax-method names\n";

    /*
     * BOTH COLLATIONS ARE ASKED, AND THE FIRST DRAFT OF THIS SECTION GOT IT WRONG IN A WAY WORTH
     * RECORDING. It created `tax_methods` with raw SQL and no COLLATE clause, so the column
     * inherited the SERVER default -- measured `utf8mb4_general_ci` -- while the prose beside it
     * claimed `utf8mb4_unicode_ci`, the connection's. The assertion passed and the sentence was
     * false, which is exactly the shape CLAUDE.md names: "Ask under the COLUMN's collation, not the
     * connection's", and "the measured production dump is MariaDB 11.4.9 with `audit_logs` in
     * `general_ci` while the application default is `unicode_ci`: do not assume one collation."
     *
     * So the table now carries one column in EACH, both are measured, and both are printed. A rule
     * keyed on a tax-method name has to hold under whichever the production table really uses, and
     * this script no longer guesses which that is.
     */
    DB::connection('fresh')->statement('
        CREATE TABLE tax_method_names (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name_general VARCHAR(191) COLLATE utf8mb4_general_ci NULL,
            name_unicode VARCHAR(191) COLLATE utf8mb4_unicode_ci NULL
        ) ENGINE=InnoDB
    ');
    DB::connection('fresh')->table('tax_method_names')->insert([
        'name_general' => 'Standard', 'name_unicode' => 'Standard',
    ]);

    foreach (['name_general' => 'utf8mb4_general_ci', 'name_unicode' => 'utf8mb4_unicode_ci'] as $col => $want) {
        $actual = DB::connection('fresh')->selectOne(
            "SELECT COLLATION_NAME c FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'tax_method_names' AND COLUMN_NAME = ?",
            [$database, $col]
        )->c;

        check(
            "the {$col} column really is {$want}, so the two cases below are not the same question",
            $actual === $want,
            "actual={$actual}"
        );

        $byCase = DB::connection('fresh')->table('tax_method_names')->where($col, 'standard')->count();
        $byAccent = DB::connection('fresh')->table('tax_method_names')->where($col, 'stāndard')->count();

        check(
            "{$want}: a lower-case name matches the stored \"Standard\"",
            $byCase === 1,
            "matched={$byCase} -- SQLite's BINARY default matches 0, so a name-keyed rule behaves "
            . 'differently on the two engines'
        );
        check(
            "{$want}: and so does an ACCENTED variant",
            $byAccent === 1,
            "matched={$byAccent} -- the P1-SEC-70/78 family, here on a table that decides tax"
        );
        printf("        %s: case-insensitive=%s accent-insensitive=%s\n",
            $want, $byCase === 1 ? 'yes' : 'no', $byAccent === 1 ? 'yes' : 'no');
    }

    /* AND WHAT THE REAL TABLE IN THIS HARNESS INHERITED, printed rather than assumed, because that
       is the value a reader will compare against a production `SHOW CREATE TABLE`. */
    $inherited = DB::connection('fresh')->selectOne(
        "SELECT COLLATION_NAME c FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'tax_methods' AND COLUMN_NAME = 'name'",
        [$database]
    )->c;
    printf("        tax_methods.name inherited the SERVER default: %s\n", $inherited);
} finally {
    try {
        DB::connection('fresh')->disconnect();
        DB::connection('fresh_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
        echo "\n(dropped throwaway database {$database})\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "Could not drop the throwaway database: {$e->getMessage()}\n");
    }
}

echo "\n" . str_repeat('-', 78) . "\n";
echo "{$checks} checks, {$failures} failures\n";
echo "NOTHING WAS CHANGED. §C's excess and §D's band selection are both reported for an owner\n";
echo "decision; this script exists so the figures stop being invisible.\n";

exit($failures === 0 ? 0 : 1);
