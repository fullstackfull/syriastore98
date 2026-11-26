<?php

/**
 * GAP-121 / `P04-EV-01` (1 of 4) -- COUPON ALLOCATION, measured on real MySQL/MariaDB.
 * =================================================================================================
 *
 * WHY THIS SCRIPT EXISTS. `GAP-121`'s row reads: "Coupon allocation, tax banding, refund totals and
 * the new transaction boundary have no MySQL verification; production is MySQL with
 * `CACHE_STORE=database`." This is the coupon-allocation quarter of that. It drives the REAL
 * `App\Services\Commerce\CouponBasketAllocator` through its public API; nothing is reimplemented.
 *
 * WHAT IS GENUINELY ENGINE-SPECIFIC HERE, AND WHAT IS NOT -- stated first, because padding a
 * harness with properties that do not depend on the engine is how a directory of evidence stops
 * being evidence.
 *
 *   NOT engine-specific: the float arithmetic itself. SQLite's `REAL` and MySQL's `double` are both
 *   IEEE-754 binary64, so a pro-rata share that round-trips on one round-trips on the other. This
 *   script therefore does NOT claim to be verifying the arithmetic. `OrderTotalCouponTest` and
 *   `MultiLineCouponArithmeticTest` already own that on SQLite and they are the right instrument.
 *
 *   ENGINE-SPECIFIC, and each measured below:
 *     (a) `carts.qty` is `integer` and production runs `'strict' => false`. MySQL ACCEPTS writes
 *         SQLite refuses or stores differently, so the malformed basket the allocator's own
 *         docblock records -- a line at `qty = -5` -- is reachable on the engine in a shape a
 *         SQLite fixture cannot reproduce faithfully. §B and §C turn on exactly that row.
 *     (b) `Cart::getCouponTotalAmount()` -- which `forBasket()` calls to size a flat discount --
 *         runs a real `whereDate('coupon_expiry_date', '>=', ...)` against the engine. The gate
 *         that decides whether a coupon discounts AT ALL is therefore a server-side date
 *         comparison, not PHP. §D drives it on both sides of today.
 *     (c) MySQL coerces `WHERE id = '5abc'` to row 5; SQLite matches nothing. Cart rows carry
 *         `product_id`, so a non-numeric product id is a different basket on the two engines.
 *         §E measures what this engine does.
 *
 * THE INSTRUMENT IS PROVED BEFORE THE GUARD (§B). A script that only ever observes a pass cannot
 * tell a working guard from a blind harness. §B computes the UNBOUNDED pro-rata -- the shape this
 * class had before the `flatRemaining` budget was added -- against the same engine rows, and
 * REQUIRES it to overshoot the discount. If §B ever stops overshooting, §C's pass means nothing and
 * the script says so rather than reporting success.
 *
 * NO TRANSACTIONS ARE OPENED. The allocator is called by cart and checkout readers outside any
 * transaction, and wrapping a harness in one it does not have is a true fact about the engine and a
 * false model of the code (the lesson a sibling concurrency script records after measuring
 * `SQLSTATE 1205` for a guard that runs unwrapped).
 *
 * HOW TO RUN. Needs a throwaway MySQL or MariaDB. It creates and drops its OWN database and
 * refuses a name that does not say it is throwaway:
 *
 *     php tests/Support/Security/bin/verify-coupon-allocation-on-mysql.php
 *
 * NEVER point FRESH_MYSQL_DATABASE at the development or production server.
 *
 * PRECONDITIONS, stated rather than assumed: a reachable server. Checked below; the script exits
 * 2 -- not 0 and not 1 -- if it is absent, because a verification that could not run has neither
 * passed nor failed.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Coupon;
use App\Services\Commerce\CouponBasketAllocator;
use Illuminate\Support\Facades\DB;

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

/* ---------------------------------------------------------------- preconditions */

$database = getenv('FRESH_MYSQL_DATABASE') ?: 'coupon_allocation_verify';

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
    /* `strict => false` MATCHES PRODUCTION (config/database.php) and is the whole point of (a)
       above: CLAUDE.md records that strict mode REFUSES writes production accepts, so a harness in
       strict mode would be measuring a server this application never runs on. */
    'strict' => false,
];

$bootstrap = $connection;
$bootstrap['database'] = null;
config(['database.connections.fresh_bootstrap' => $bootstrap]);
config(['database.connections.fresh' => $connection]);
config(['database.default' => 'fresh']);

/* THE MODEL CACHE GOES TO THE DATABASE STORE, WHICH IS PRODUCTION'S (.env.example: CACHE_STORE
   =database) and not the suite's `array`. `Coupon` is one of the 79 `Cachable` models, and a cache
   serving a pre-write coupon row would make the expiry gate in §D read the wrong row -- an artefact
   of the instrument, which is the class of false reading this directory exists to avoid. */
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

try {
    /* The narrowest schema the real code touches, with the PRODUCTION column types taken from the
       migrations and not guessed: carts.qty `integer`, every money column `float` (which this
       engine emits as `double` -- §A measures that rather than trusting it), coupons.minimum_spend
       and maximum_spend `integer`, coupon_expiry_date `date`. Deliberately NOT `migrate:fresh`:
       the full set drags in the whole application and buries the four columns under test. */
    DB::connection('fresh')->statement('
        CREATE TABLE coupons (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            coupon_name VARCHAR(191) NULL,
            coupon_code VARCHAR(191) NULL,
            coupon_type VARCHAR(191) NOT NULL DEFAULT "percentage",
            applied_product TEXT NULL,
            exclude_product TEXT NULL,
            applied_categories TEXT NULL,
            exclude_categories TEXT NULL,
            minimum_spend INT NULL,
            maximum_spend INT NULL,
            coupon_limit_user INT NULL,
            coupon_limit_x_item INT NULL,
            coupon_limit INT NOT NULL DEFAULT 0,
            coupon_expiry_date DATE NULL,
            discount_amount DOUBLE NOT NULL DEFAULT 0,
            sale_items INT NOT NULL DEFAULT 0,
            status INT NOT NULL DEFAULT 1,
            store_id BIGINT UNSIGNED NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL
        ) ENGINE=InnoDB
    ');
    DB::connection('fresh')->statement('
        CREATE TABLE carts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            product_id BIGINT UNSIGNED NULL,
            variant_id BIGINT UNSIGNED NULL,
            qty INT NOT NULL DEFAULT 0,
            customer_id BIGINT UNSIGNED NULL,
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
    /* `user_coupons`, NOT a name I chose: `Cart::getCouponTotalAmount()` reaches
       `Coupon::UsesCouponCount()` (`app/Models/Coupon.php:213`) which counts `UserCoupon` rows, and
       the first draft of this script guessed `coupon_usages` and got
       "Base table or view not found: 1146". The usage LIMITS are part of the gate that decides
       whether a coupon discounts, so the table has to be real for §D to mean anything. */
    DB::connection('fresh')->statement('
        CREATE TABLE user_coupons (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            coupon_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            amount DOUBLE NOT NULL DEFAULT 0,
            order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            store_id BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL
        ) ENGINE=InnoDB
    ');

    /** Write a flat coupon and hand back the real model, read through Eloquent like the callers. */
    $flatCoupon = function (float $amount, ?string $expiry = null, $min = null, $max = null) {
        DB::connection('fresh')->table('coupons')->truncate();
        (new Coupon)->flushCache();

        $id = DB::connection('fresh')->table('coupons')->insertGetId([
            'coupon_name' => 'gap121',
            'coupon_code' => 'GAP121FLAT',
            'coupon_type' => 'flat',
            'discount_amount' => $amount,
            'coupon_expiry_date' => $expiry ?? now()->addYear()->toDateString(),
            'minimum_spend' => $min,
            'maximum_spend' => $max,
            'coupon_limit' => 0,
            'status' => 1,
            'store_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Coupon::on('fresh')->whereKey($id)->first();
    };

    // ─────────────────────────── [A] the premise: what the engine really stores
    echo "[A] THE PREMISE -- the column types this path's money actually lives in\n";

    $cols = [];
    foreach (DB::connection('fresh')->select('SHOW COLUMNS FROM coupons') as $c) {
        $cols[$c->Field] = $c->Type;
    }
    foreach (DB::connection('fresh')->select('SHOW COLUMNS FROM carts') as $c) {
        $cols['carts.' . $c->Field] = $c->Type;
    }

    check(
        'coupons.discount_amount is a double, as $table->float() emits on this engine',
        str_starts_with(strtolower($cols['discount_amount'] ?? ''), 'double'),
        'type=' . ($cols['discount_amount'] ?? 'absent')
    );
    check(
        'carts.qty is a SIGNED int, so a negative quantity is storable rather than refused',
        (strtolower($cols['carts.qty'] ?? '') === 'int(11)' || strtolower($cols['carts.qty'] ?? '') === 'int'),
        'type=' . ($cols['carts.qty'] ?? 'absent')
        . ' -- an UNSIGNED column would make the engine refuse the row §B and §C turn on, and a pass '
        . 'below would then be the column doing the work rather than the allocator'
    );

    /* THE PRODUCTION SETTING, ASSERTED AND NOT ASSUMED. If this harness were running strict, every
       coercion case below would measure a server this application never runs on. */
    $sqlMode = DB::connection('fresh')->selectOne('SELECT @@SESSION.sql_mode AS m')->m;
    check(
        'the session is NOT in strict mode, matching config/database.php',
        ! str_contains(strtoupper((string) $sqlMode), 'STRICT_'),
        'sql_mode=' . var_export($sqlMode, true)
    );

    // ─────────────────────────── [B] prove the instrument
    echo "\n[B] THE INSTRUMENT -- the UNBOUNDED pro-rata must overshoot, or §C proves nothing\n";

    /* The malformed basket the allocator's own docblock records as measured: one 1000.00 line and
       one 50.00 product at qty = -5, i.e. a -250.00 line. Subtotal 750.00. The rows are written to
       the ENGINE and the line totals read back out of it, so the figures below come from MySQL's
       own columns rather than PHP literals. */
    DB::connection('fresh')->table('carts')->truncate();
    DB::connection('fresh')->table('carts')->insert([
        ['product_id' => 1, 'variant_id' => 0, 'qty' => 1, 'customer_id' => 1, 'store_id' => 1],
        ['product_id' => 2, 'variant_id' => 0, 'qty' => -5, 'customer_id' => 1, 'store_id' => 1],
    ]);

    $storedQty = DB::connection('fresh')->table('carts')->orderBy('id')->pluck('qty')->all();
    check(
        'the engine stored the negative quantity verbatim under strict => false',
        array_map('intval', $storedQty) === [1, -5],
        'stored=' . json_encode($storedQty)
        . ' -- if the engine clamped or refused it, the malformed basket is not reproducible here'
    );

    $unitPrices = [1 => 1000.00, 2 => 50.00];
    $lines = [];
    foreach (DB::connection('fresh')->table('carts')->orderBy('id')->get() as $row) {
        $lines[] = $unitPrices[(int) $row->product_id] * (int) $row->qty;
    }
    $subtotal = array_sum($lines);

    check(
        'the basket subtotal computed from engine rows is 750.00',
        abs($subtotal - 750.00) < 0.0001,
        'subtotal=' . var_export($subtotal, true)
    );

    $coupon = $flatCoupon(700.00);
    $allocator = CouponBasketAllocator::forBasket($coupon, (float) $subtotal);

    /* The pre-guard shape, written out here rather than called: `D x lineTotal / subtotal` with no
       running budget. This is what the class did before `flatRemaining` existed. */
    $unbounded = 0.0;
    foreach ($lines as $lineTotal) {
        if ($lineTotal <= 0.0) {
            continue;
        }
        $unbounded += min($lineTotal, 700.00 * $lineTotal / $subtotal);
    }

    check(
        'the UNBOUNDED pro-rata overshoots the 700.00 discount on this basket',
        $unbounded > 700.0001,
        'unbounded=' . number_format($unbounded, 2)
        . ' -- the harness cannot see the defect, so no pass in §C is meaningful'
    );
    printf("        measured unbounded allocation: %s on a %s discount\n",
        number_format($unbounded, 2), number_format(700.00, 2));

    // ─────────────────────────── [C] the guard, on the same engine rows
    echo "\n[C] THE GUARD -- the real allocator's shares are bounded by the discount\n";

    $allocated = 0.0;
    foreach ($lines as $lineTotal) {
        $allocated += $allocator->discountForLine((float) $lineTotal, null, null);
    }

    check(
        'the real allocator never allocates more than the flat discount',
        $allocated <= 700.0001,
        'allocated=' . number_format($allocated, 2) . ' of a 700.00 discount'
    );
    printf("        measured bounded allocation  : %s\n", number_format($allocated, 2));

    /* AND THE WELL-FORMED BASKET IS UNCHANGED, so this script cannot be read as having proved a
       guard that quietly broke ordinary baskets. Three 40.00 lines, a 30.00 flat coupon: the shares
       must sum to exactly the discount. */
    DB::connection('fresh')->table('carts')->truncate();
    DB::connection('fresh')->table('carts')->insert([
        ['product_id' => 3, 'variant_id' => 0, 'qty' => 1, 'customer_id' => 1, 'store_id' => 1],
        ['product_id' => 3, 'variant_id' => 0, 'qty' => 1, 'customer_id' => 1, 'store_id' => 1],
        ['product_id' => 3, 'variant_id' => 0, 'qty' => 1, 'customer_id' => 1, 'store_id' => 1],
    ]);
    $wellFormed = array_fill(0, DB::connection('fresh')->table('carts')->count(), 40.00);
    $sub = array_sum($wellFormed);

    $coupon = $flatCoupon(30.00);
    $a2 = CouponBasketAllocator::forBasket($coupon, (float) $sub);
    $sum2 = 0.0;
    foreach ($wellFormed as $lineTotal) {
        $sum2 += $a2->discountForLine($lineTotal, null, null);
    }

    check(
        'a well-formed basket still allocates the discount in full',
        abs($sum2 - 30.00) < 0.0001,
        'allocated=' . var_export($sum2, true) . ' of 30.00'
    );

    // ─────────────────────────── [D] the expiry gate is a SERVER-SIDE date comparison
    echo "\n[D] THE EXPIRY GATE -- whereDate() against the engine decides whether money moves\n";

    $coupon = $flatCoupon(30.00, now()->addDay()->toDateString());
    $live = CouponBasketAllocator::forBasket($coupon, 120.00);
    check(
        'a coupon expiring TOMORROW discounts',
        $live->discountForLine(40.00, null, null) > 0.0,
        'share=' . var_export($live->discountForLine(40.00, null, null), true)
    );

    $coupon = $flatCoupon(30.00, now()->subDay()->toDateString());
    $dead = CouponBasketAllocator::forBasket($coupon, 120.00);
    check(
        'a coupon that expired YESTERDAY allocates nothing',
        $dead->discountForLine(40.00, null, null) === 0.0,
        'share=' . var_export($dead->discountForLine(40.00, null, null), true)
        . ' -- the gate is the engine\'s own DATE() comparison, not PHP'
    );

    /* TODAY IS THE BOUNDARY AND `>=` INCLUDES IT. Driven because an off-by-one here is a coupon
       that stops working a day early, or keeps working a day late, and the comparison happens on
       the server where the suite cannot see it. */
    $coupon = $flatCoupon(30.00, now()->toDateString());
    $today = CouponBasketAllocator::forBasket($coupon, 120.00);
    check(
        'a coupon expiring TODAY still discounts, so the boundary is inclusive',
        $today->discountForLine(40.00, null, null) > 0.0,
        'share=' . var_export($today->discountForLine(40.00, null, null), true)
    );

    // ─────────────────────────── [E] the id coercion CLAUDE.md names, measured here
    echo "\n[E] IDENTIFIER COERCION -- 'WHERE id = \"5abc\"' on this engine\n";

    $coupon = $flatCoupon(30.00);
    $coerced = DB::connection('fresh')->table('coupons')->where('id', (string) $coupon->id . 'abc')->first();

    check(
        'the engine coerces a trailing-garbage id to the numeric row, as CLAUDE.md records',
        $coerced !== null && (int) $coerced->id === (int) $coupon->id,
        'row=' . ($coerced === null ? 'none' : (string) $coerced->id)
        . ' -- SQLite matches nothing here, so any coupon lookup keyed on request input resolves '
        . 'differently on the two engines and the suite cannot see it'
    );

    /* The reading that matters for this path: the coercion is the ENGINE's, so a guard that wants
       to refuse such an id has to do it in PHP. `filter_var(..., FILTER_VALIDATE_INT)` is what
       CLAUDE.md prescribes, and it refuses exactly what the engine would have accepted. */
    check(
        'and FILTER_VALIDATE_INT refuses the same string the engine accepted',
        filter_var((string) $coupon->id . 'abc', FILTER_VALIDATE_INT) === false,
        'a PHP-side parse is the only thing that can refuse it'
    );
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

exit($failures === 0 ? 0 : 1);
