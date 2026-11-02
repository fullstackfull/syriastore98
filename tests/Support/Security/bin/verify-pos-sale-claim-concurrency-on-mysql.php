<?php

/**
 * F269 -- the POS sale claim is decided by the ENGINE, verified on REAL MySQL/MariaDB.
 * =================================================================================================
 *
 * WHY THIS SCRIPT HAD TO EXIST. The owner's directive on this defect is explicit: "Implement a
 * narrowly scoped, reliable idempotency mechanism protecting against both sequential replay and
 * concurrent double submission. Do not merely uncomment the session-clearing line and assume
 * concurrency is solved."
 *
 * `tests/Feature/Commerce/PosSaleIdempotencyTest.php` proves the sequential half and the handler's
 * branch logic. It CANNOT prove the concurrent half, for two structural reasons:
 *
 *   - `phpunit.xml` sets `CACHE_STORE=array`, and `Illuminate\Cache\ArrayStore` has NO `add()` of
 *     its own, so `Cache::add()` there falls through to `Repository::add()` -- a `get()` then a
 *     `put()`, with a gap between them. Section E MEASURES that rather than asserting it.
 *   - PHPUnit is single-threaded, so even an atomic store would never actually be raced.
 *
 * Production runs `CACHE_STORE=database` (.env.example), where `DatabaseStore::add()` ends in
 *
 *     return $this->table()->insertOrIgnore(compact('key', 'value', 'expiration')) > 0;
 *
 * -- `INSERT IGNORE` against `cache`, whose `key` column is the PRIMARY KEY
 * (database/migrations/0001_01_01_000001_create_cache_table.php). The `get()` above it is a fast
 * path, not the control: correctness rests on the primary key refusing the second row. That is the
 * claim this script refuses to take from reading.
 *
 * AND ONE THING THIS SCRIPT MEASURES THAT ITS SIBLINGS DO NOT: the POS claim's VALUE changes
 * meaning over the life of the sale -- 'writing' while stock and the order are being written, then
 * an array carrying the order id once the order exists. Those two states drive two different
 * answers to a duplicate press (a visible 409 versus the first sale's own success), so a value
 * that did not survive a round trip through the engine would silently turn one into the other.
 * Section D drives that over the real store rather than over an in-memory array.
 *
 * HOW TO RUN. Needs a throwaway MySQL or MariaDB. It creates and drops its OWN database and
 * refuses a name without 'fresh' or 'verify' in it, like its siblings:
 *
 *     apt-get install -y mariadb-server && service mariadb start
 *     php tests/Support/Security/bin/verify-pos-sale-claim-concurrency-on-mysql.php
 *
 * NEVER point FRESH_MYSQL_DATABASE at the development or production server.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\PosController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

$database = getenv('FRESH_MYSQL_DATABASE') ?: 'pos_claim_verify';

if (!preg_match('/(fresh|verify)/i', $database)) {
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
    'strict' => false,
];

$bootstrap = $connection;
$bootstrap['database'] = null;
config(['database.connections.fresh_bootstrap' => $bootstrap]);
config(['database.connections.fresh_a' => $connection]);
config(['database.connections.fresh_b' => $connection]);

// Two cache stores of the shape config/cache.php defines, each pinned to its own connection so the
// two tills are genuinely two database sessions rather than one connection used twice.
foreach (['a', 'b'] as $side) {
    config(["cache.stores.till_{$side}" => [
        'driver' => 'database',
        'connection' => "fresh_{$side}",
        'table' => 'cache',
        'lock_connection' => "fresh_{$side}",
    ]]);
}

$checks = 0;
$failures = 0;

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

/** The handler's OWN fingerprint, reflected rather than reimplemented. */
function fingerprint(array $cart, int $storeId, int $userId, $price, $discount, $pressId = null): string
{
    $method = new ReflectionMethod(PosController::class, 'posSaleFingerprint');
    $method->setAccessible(true);

    return $method->invoke(new PosController, $cart, $storeId, $userId, $price, $discount, $pressId);
}

/**
 * ONE CART LINE IN THE SHAPE `ProductController::addToCart()` REALLY WRITES (:5035-5054).
 *
 * The first version of this helper synthesised a `price` key, which no real basket has — so
 * Section E read as though per-line price were in the hash when the handler was looking at a key
 * that was always absent. Found by independent review, together with the controller-side half.
 * Nothing in Section E depended on it alone, so nothing here was passing for the wrong reason;
 * the harness was simply staging something production does not produce, which is the failure mode
 * this project keeps meeting.
 */
function line(int $productId, int $quantity, float $price = 10.0, $variantId = null): array
{
    return [
        'id' => $productId,
        'product_id' => $productId,
        'quantity' => $quantity,
        'orignal_price' => $price,
        'variant_id' => $variantId,
        'total_orignal_price' => $price * $quantity,
        'originalquantity' => 50,
    ];
}

echo "F269 -- the POS sale claim under real concurrency, on MySQL/MariaDB\n";
echo str_repeat('-', 78) . "\n";

try {
    DB::connection('fresh_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
    DB::connection('fresh_bootstrap')->statement("CREATE DATABASE `{$database}`");
} catch (Throwable $e) {
    fwrite(STDERR, "Could not create the throwaway database: {$e->getMessage()}\n");
    exit(2);
}

try {
    $a = DB::connection('fresh_a');
    $b = DB::connection('fresh_b');

    /* PORTABLE ACROSS MySQL AND MariaDB. `@@transaction_isolation` is MySQL 5.7+ and MariaDB does
       not have it; `@@tx_isolation` is MariaDB's spelling and MySQL 8.0 removed it. Neither name
       works on both, so it is asked for by trial and reported for the record only -- the
       assertions below rest on InnoDB, not on the isolation level, so this figure must not be able
       to fail the run. */
    $server = $a->select('SELECT VERSION() AS v, @@default_storage_engine AS e')[0];
    $server->i = 'unknown';
    foreach (['transaction_isolation', 'tx_isolation'] as $isolationVariable) {
        try {
            $server->i = $a->select('SELECT @@' . $isolationVariable . ' AS i')[0]->i;
            break;
        } catch (Throwable $e) {
            // Try the other spelling; 'unknown' stands if neither exists.
        }
    }
    echo "server: {$server->v}  engine: {$server->e}  isolation: {$server->i}\n\n";

    // ---------------------------------------------------------------------------------------
    echo "A. the premise: the claim rests on a PRIMARY KEY, not on a PHP comparison\n";
    // ---------------------------------------------------------------------------------------

    check('InnoDB is the default engine (without row locks nothing below means anything)',
        strtolower($server->e) === 'innodb', "engine is {$server->e}");

    // The framework's own cache table, created exactly as the shipped migration creates it.
    $a->statement('CREATE TABLE `cache` (
        `key` VARCHAR(255) NOT NULL PRIMARY KEY,
        `value` MEDIUMTEXT NOT NULL,
        `expiration` INT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    $keyColumn = $a->select("SHOW KEYS FROM `cache` WHERE Key_name = 'PRIMARY'");

    check('cache.key is the PRIMARY KEY, so a duplicate is refused by the engine',
        count($keyColumn) === 1 && $keyColumn[0]->Column_name === 'key',
        'primary key columns: ' . json_encode(array_map(fn ($k) => $k->Column_name, $keyColumn)));

    check('DatabaseStore implements its own add(), so Cache::add() is NOT the Repository fallback',
        method_exists(\Illuminate\Cache\DatabaseStore::class, 'add'));

    /* THE KEY MUST FIT. `cache.key` is VARCHAR(255); a fingerprint longer than that would be
       TRUNCATED, and two different baskets truncating to the same 255 bytes would make one till
       refuse the other's sale. 'pos_sale:' + a sha256 hex digest is 73 bytes, measured here. */
    $sampleKey = fingerprint([line(1, 2)], 7, 11, 20.0, 0);
    check('the fingerprint fits cache.key without truncation (' . strlen($sampleKey) . ' of 255 bytes)',
        strlen($sampleKey) <= 200, "key is " . strlen($sampleKey) . " bytes: {$sampleKey}");

    // ---------------------------------------------------------------------------------------
    echo "\nB. the sequential claim: the second press is refused\n";
    // ---------------------------------------------------------------------------------------

    $tillA = Cache::store('till_a');
    $tillB = Cache::store('till_b');

    /* THE PREFIX IS NOT COSMETIC. Section C plants a row BY HAND on connection A -- the only way
       to hold an uncommitted insert while B races it -- and `Cache::add()` writes
       `cache.prefix . $key`. Planting the RAW key would put A and B on two DIFFERENT rows, and
       every check after the race would then pass for the wrong reason. Recorded because that is
       exactly how the sibling script first lied. */
    $prefix = (string) config('cache.prefix');
    echo "cache key prefix: '{$prefix}'\n";

    $basket = [line(41, 2)];
    $key = fingerprint($basket, 7, 11, 20.0, 0);

    check('the first press takes the claim', $tillA->add($key, 'writing', 120) === true);
    check('the SECOND press is refused the same claim', $tillB->add($key, 'writing', 120) === false);

    // ---------------------------------------------------------------------------------------
    echo "\nC. the CONCURRENT press -- the case the suite cannot stage\n";
    // ---------------------------------------------------------------------------------------
    /*
     * Two presses of Cash Payment arrive together. Neither has committed when the other reads,
     * which is precisely the state `session()->forget()` cannot help with: BOTH hold a valid copy
     * of the basket, because each loaded the session before the other saved it.
     *
     * A inserts its claim and does NOT commit. B then calls Cache::add on the same key. B's
     * non-locking read sees nothing -- A is uncommitted -- so B passes the fast path and reaches
     * the INSERT, where it must BLOCK on A's primary-key lock rather than decide on its own stale
     * read. B's lock wait is 1 second so the block is observable rather than a hang.
     *
     * If B returned true here, two till presses would both move stock and both write an order --
     * the exact defect, surviving the fix.
     */
    $raceBasket = [line(41, 2), line(42, 1)];
    $raceKey = fingerprint($raceBasket, 7, 11, 30.0, 0);

    $b->statement('SET SESSION innodb_lock_wait_timeout = 1');

    $a->beginTransaction();
    $a->table('cache')->insert([
        'key' => $prefix . $raceKey,
        'value' => serialize('writing'),
        'expiration' => time() + 120,
    ]);

    $blocked = false;
    $bResultWhileUncommitted = null;
    try {
        $bResultWhileUncommitted = $tillB->add($raceKey, 'writing', 120);
    } catch (Throwable $e) {
        $blocked = str_contains($e->getMessage(), '1205') || str_contains(strtolower($e->getMessage()), 'lock wait');
        if (! $blocked) {
            throw $e;
        }
    }

    check('B BLOCKS on the row lock instead of deciding on its stale read (error 1205)',
        $blocked,
        $blocked ? '' : 'B returned ' . var_export($bResultWhileUncommitted, true)
            . ' without waiting, so two simultaneous presses could both sell the basket');

    $a->commit();

    check('once A has committed, B is refused -- re-evaluated against the COMMITTED row',
        $tillB->add($raceKey, 'writing', 120) === false);

    check('and A, which won the race, really does hold the claim',
        (int) $a->table('cache')->where('key', $prefix . $raceKey)->count() === 1);

    // ---------------------------------------------------------------------------------------
    echo "\nD. the claim's VALUE is what tells a replay from a collision\n";
    // ---------------------------------------------------------------------------------------
    /*
     * The handler answers a duplicate press in two different ways, and the claim's value is the
     * only thing that distinguishes them:
     *
     *   'writing'                -> HTTP 409, visible refusal, nothing written
     *   ['order_id' => <id>]     -> the FIRST sale's own success, carrying the SAME order id
     *
     * So the value has to survive a round trip through the engine with its type intact. A value
     * that came back as a string would turn every completed sale's replay into a 409, and a
     * cashier would be refused their own receipt.
     */
    $finishedKey = fingerprint([line(41, 1)], 7, 11, 10.0, 0);

    check('a fresh claim starts as the writing sentinel',
        $tillA->add($finishedKey, 'writing', 120) === true
        && $tillA->get($finishedKey) === 'writing');

    $tillA->put($finishedKey, ['order_id' => 98765], 120);
    $held = $tillB->get($finishedKey);

    check('the completed claim round-trips as an ARRAY carrying the order id, read back over a '
        . 'DIFFERENT connection',
        is_array($held) && ($held['order_id'] ?? null) === 98765,
        'read back: ' . var_export($held, true));

    check('and the handler\'s own replay predicate is satisfied by it',
        is_array($held) && ! empty($held['order_id']));

    // ---------------------------------------------------------------------------------------
    echo "\nE. the claim separates what must be separated, and nothing more\n";
    // ---------------------------------------------------------------------------------------
    /*
     * A claim that refused too much would stop a shop trading, which is worse than the defect.
     * The fingerprint is built from the SERVER-RESOLVED store, the AUTHENTICATED cashier, the
     * price, the discount and a canonical form of the basket -- never caller input alone, which
     * this project's standing rule forbids and which would let one till evict another's claim.
     */
    check('two stores ringing up an IDENTICAL basket are separate claims',
        fingerprint($basket, 7, 11, 20.0, 0) !== fingerprint($basket, 8, 11, 20.0, 0));

    check('two cashiers at the same store ringing up an identical basket are separate claims',
        fingerprint($basket, 7, 11, 20.0, 0) !== fingerprint($basket, 7, 12, 20.0, 0));

    check('a different quantity is a different claim',
        fingerprint([line(41, 2)], 7, 11, 20.0, 0) !== fingerprint([line(41, 3)], 7, 11, 30.0, 0));

    check('a different discount is a different claim',
        fingerprint($basket, 7, 11, 20.0, 0) !== fingerprint($basket, 7, 11, 20.0, 5));

    check('a different variant of the same product is a different claim',
        fingerprint([line(41, 1, 10.0, 3)], 7, 11, 10.0, 0)
            !== fingerprint([line(41, 1, 10.0, 4)], 7, 11, 10.0, 0));

    /* AND THE SAME BASKET IS THE SAME CLAIM whichever order the lines were added in -- otherwise
       a double submit of a two-line basket could present itself as two different sales. */
    check('the same basket assembled in a different order is the SAME claim',
        fingerprint([line(41, 2), line(42, 1)], 7, 11, 30.0, 0)
            === fingerprint([line(42, 1), line(41, 2)], 7, 11, 30.0, 0));

    check('a different per-line price is a different claim (the component the cart really writes)',
        fingerprint([line(41, 1, 10.0)], 7, 11, 10.0, 0)
            !== fingerprint([line(41, 1, 12.0)], 7, 11, 10.0, 0));

    /*
     * AND THE PRESS ID SEPARATES TWO CUSTOMERS WITH THE SAME BASKET, which is the hole the first
     * version of this change left: content-addressed alone, the second walk-in buying the same one
     * unit at the same till was answered as a replay of the first -- cash taken, nothing recorded.
     * `new_pos_id_<store>` is written afresh each time the payment modal is opened.
     */
    check('two presses of the SAME payment modal are the same claim',
        fingerprint($basket, 7, 11, 20.0, 0, 1762000000)
            === fingerprint($basket, 7, 11, 20.0, 0, 1762000000));

    check('a NEW payment modal with an identical basket is a DIFFERENT claim',
        fingerprint($basket, 7, 11, 20.0, 0, 1762000000)
            !== fingerprint($basket, 7, 11, 20.0, 0, 1762000090),
        'two separate customers buying the same item at the same till would collide, and the '
            . 'second sale would be swallowed as a replay');

    check('a missing press id degrades to content-addressed rather than throwing',
        fingerprint($basket, 7, 11, 20.0, 0, null) === fingerprint($basket, 7, 11, 20.0, 0));

    /* A NON-SCALAR discount must not throw inside the fingerprint (`discount[]=1`). */
    $nonScalar = null;
    try {
        $nonScalar = fingerprint($basket, 7, 11, 20.0, ['1']);
    } catch (Throwable $e) {
        $nonScalar = null;
    }
    check('a non-scalar discount is absorbed rather than raising "Array to string conversion"',
        is_string($nonScalar) && $nonScalar !== '');

    // ---------------------------------------------------------------------------------------
    echo "\nF. the TTL means what the constant says: an expired claim is retakeable\n";
    // ---------------------------------------------------------------------------------------
    /*
     * POS_SALE_CLAIM_SECONDS is 120 -- long enough to cover a double-click and a retried submit,
     * short enough that two customers buying the same single item at the same till minutes apart
     * are not refused. A claim that could never be retaken would refuse that second customer and
     * would grow the cache table without bound.
     */
    $expiredKey = fingerprint([line(99, 1)], 7, 11, 10.0, 0);
    $a->table('cache')->insert([
        'key' => $prefix . $expiredKey,
        'value' => serialize('writing'),
        'expiration' => time() - 1,
    ]);

    check('an EXPIRED claim does not permanently block the basket',
        $tillA->add($expiredKey, 'writing', 120) === true,
        'an expired row refused a fresh claim, so a repeat purchase would be refused forever');

    $reflected = new ReflectionClass(PosController::class);
    $constants = $reflected->getConstants();

    check('POS_SALE_CLAIM_SECONDS is still the 120 this section reasons about',
        ($constants['POS_SALE_CLAIM_SECONDS'] ?? null) === 120,
        'constant is ' . var_export($constants['POS_SALE_CLAIM_SECONDS'] ?? null, true));

    // ---------------------------------------------------------------------------------------
    echo "\nG. what the SUITE is and is NOT evidence for\n";
    // ---------------------------------------------------------------------------------------
    /*
     * Measured rather than asserted in prose, because the whole point of this file is that a
     * claim about an engine has to be executed. phpunit.xml sets CACHE_STORE=array.
     */
    check('ArrayStore has NO add() of its own, so under CACHE_STORE=array Cache::add() is the '
        . 'Repository get-then-put fallback and is NOT atomic -- which is why sections B-C exist',
        ! method_exists(\Illuminate\Cache\ArrayStore::class, 'add'),
        'ArrayStore gained an add(); re-read whether the suite now proves more than it did');

    check('the production default really is the database store (.env.example)',
        str_contains((string) @file_get_contents(__DIR__ . '/../../../../.env.example'), 'CACHE_STORE=database'));

    /* AND THE SESSION IS NOT A CONTROL, which is the directive's point restated as a measurement:
       the shipped session driver has no compare-and-set and no lock around read-modify-write, so
       nothing built on `session()->forget()` can decide a race. */
    check('the session store exposes no atomic put-if-absent to have used instead',
        ! method_exists(\Illuminate\Session\Store::class, 'add'),
        'Session\Store gained an add(); reconsider whether the claim still needs the cache');
} finally {
    try {
        DB::connection('fresh_a')->disconnect();
        DB::connection('fresh_b')->disconnect();
        DB::connection('fresh_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
    } catch (Throwable $e) {
        fwrite(STDERR, "Could not drop the throwaway database: {$e->getMessage()}\n");
    }
}

echo "\n" . str_repeat('-', 78) . "\n";
echo "{$checks} checks, {$failures} failures\n";

exit($failures === 0 ? 0 : 1);
