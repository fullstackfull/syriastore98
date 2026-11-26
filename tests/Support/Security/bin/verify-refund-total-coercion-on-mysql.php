<?php

/**
 * GAP-121 / `P04-EV-01` (3 of 4) -- REFUND TOTALS, measured on real MySQL/MariaDB.
 * =================================================================================================
 *
 * WHAT THIS VERIFIES. `App\Services\RefundIntegrityService::cappedRefundAmount()` is the server-side
 * ceiling on a refund: the merchant types a figure, and the service caps it at the order's own
 * persisted total. The real service is called through its public API; nothing is reimplemented. The
 * ceiling's second argument is `$order->final_price` -- a value that comes OUT OF A DATABASE COLUMN
 * -- so what that column can hold, and what it hands back, is part of the control.
 *
 * WHAT IS GENUINELY ENGINE-SPECIFIC, AND WHAT IS NOT.
 *
 *   NOT engine-specific: the cap arithmetic and the sign refusal. Both are PHP, and
 *   `RefundIntegrityTest` owns them on SQLite. §D drives the sign refusal anyway, as the control
 *   that stops this script reading as "everything is capped to zero" -- see below.
 *
 *   ENGINE-SPECIFIC, and each measured:
 *     (a) §B. `orders.final_price` is `$table->float()->nullable()` -- a NULLABLE double. A NULL
 *         reaches `toAmount()`, which answers 0.0 for anything non-numeric, so the ceiling becomes
 *         ZERO and EVERY refund on that order is refused. Whether a NULL is reachable is a question
 *         about the engine and `'strict' => false`, not about PHP.
 *     (b) §C. Under `'strict' => false` -- production's setting -- this engine ACCEPTS a
 *         non-numeric string into a numeric column and silently coerces it. SQLite's type affinity
 *         stores the text instead. So what `final_price` hands the ceiling after a sloppy write
 *         differs by engine, and the suite cannot see it.
 *     (c) §E. `OrderRefundController` passes `$request->refundId` straight into a `where('id', ...)`
 *         with no integer parse. MySQL coerces `'5abc'` to row 5; SQLite matches nothing. The row a
 *         refund mutation resolves is therefore engine-dependent.
 *
 * NO TRANSACTIONS ARE OPENED: `cappedRefundAmount()` is called outside one.
 *
 * NOTHING HERE CHANGES AN AMOUNT. Where a measurement shows a figure moving -- §B's zero ceiling --
 * it is reported, because a refund ceiling is money and CLAUDE.md §2 reserves that to the owner.
 *
 * HOW TO RUN. Needs a throwaway MySQL or MariaDB. It creates and drops its OWN database and refuses
 * a name that does not say it is throwaway:
 *
 *     php tests/Support/Security/bin/verify-refund-total-coercion-on-mysql.php
 *
 * NEVER point FRESH_MYSQL_DATABASE at the development or production server. Exits 2 -- not 0 and
 * not 1 -- when a precondition is unmet, because a verification that could not run has neither
 * passed nor failed.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\RefundIntegrityService;
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

$database = getenv('FRESH_MYSQL_DATABASE') ?: 'refund_total_coercion_verify';

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
    /* Production's setting, and the subject of §C: strict mode REFUSES the write §C depends on, so
       a harness in strict mode would be measuring a server this application never runs on. */
    'strict' => false,
];

$bootstrap = $connection;
$bootstrap['database'] = null;
config(['database.connections.fresh_bootstrap' => $bootstrap]);
config(['database.connections.fresh' => $connection]);
config(['database.default' => 'fresh']);

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
    /* The production column types from the migrations: orders money columns `float()->nullable()`,
       order_refunds.refund_amount `float()` NOT NULL default 0, product_refund_id a STRING. */
    DB::connection('fresh')->statement('
        CREATE TABLE orders (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            store_id BIGINT UNSIGNED NULL,
            payment_type VARCHAR(191) NULL,
            final_price DOUBLE NULL DEFAULT 0,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL
        ) ENGINE=InnoDB
    ');
    DB::connection('fresh')->statement('
        CREATE TABLE order_refunds (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NULL,
            refund_status VARCHAR(191) NULL,
            refund_reason VARCHAR(191) NULL,
            product_refund_id VARCHAR(191) NULL,
            refund_amount DOUBLE NOT NULL DEFAULT 0,
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

    $service = new RefundIntegrityService();

    /** Write an order and hand back the figure the engine stores in final_price. */
    $orderWith = function ($finalPrice) {
        DB::connection('fresh')->table('orders')->truncate();
        DB::connection('fresh')->table('orders')->insert([
            'store_id' => 1, 'payment_type' => 'cod', 'final_price' => $finalPrice,
        ]);

        return DB::connection('fresh')->table('orders')->orderBy('id')->first();
    };

    // ─────────────────────────── [A] the premise
    echo "[A] THE PREMISE -- the columns a refund ceiling is read from and written to\n";

    $cols = [];
    foreach (DB::connection('fresh')->select('SHOW COLUMNS FROM orders') as $c) {
        $cols['orders.' . $c->Field] = ['type' => strtolower($c->Type), 'null' => $c->Null];
    }
    foreach (DB::connection('fresh')->select('SHOW COLUMNS FROM order_refunds') as $c) {
        $cols['order_refunds.' . $c->Field] = ['type' => strtolower($c->Type), 'null' => $c->Null];
    }

    check(
        'orders.final_price is a double and NULLABLE, which is what makes §B possible',
        str_starts_with($cols['orders.final_price']['type'] ?? '', 'double')
            && $cols['orders.final_price']['null'] === 'YES',
        'type=' . ($cols['orders.final_price']['type'] ?? 'absent')
        . ' nullable=' . ($cols['orders.final_price']['null'] ?? '?')
    );
    check(
        'order_refunds.refund_amount is a double',
        str_starts_with($cols['order_refunds.refund_amount']['type'] ?? '', 'double'),
        'type=' . ($cols['order_refunds.refund_amount']['type'] ?? 'absent')
    );

    $sqlMode = DB::connection('fresh')->selectOne('SELECT @@SESSION.sql_mode AS m')->m;
    check(
        'the session is NOT in strict mode, matching config/database.php',
        ! str_contains(strtoupper((string) $sqlMode), 'STRICT_'),
        'sql_mode=' . var_export($sqlMode, true)
    );

    // ─────────────────────────── [B] the instrument, and a finding
    echo "\n[B] THE CEILING -- and what a NULL final_price does to it\n";

    /* THE INSTRUMENT FIRST: an ordinary order caps an over-large request AT the order total. If
       this stopped working, every "refused" result below would be the service refusing everything
       and the script would be measuring nothing. */
    $order = $orderWith(267.00);
    $ok = $service->cappedRefundAmount('500', $order->final_price, 'gap121');

    check(
        'the instrument: a 500.00 request on a 267.00 order is capped AT 267.00',
        $ok['capped'] === true && abs($ok['amount'] - 267.00) < 0.0001,
        'amount=' . var_export($ok['amount'], true) . ' capped=' . var_export($ok['capped'], true)
    );
    check(
        'and a request inside the total is NOT capped, so the cap is not a blanket refusal',
        $service->cappedRefundAmount('100', $order->final_price, 'gap121')['capped'] === false,
        'a cap that always bit would make every assertion below meaningless'
    );

    /* THE FINDING. An explicit NULL is accepted by the nullable column, `toAmount(null)` is not
       numeric, so the ceiling is 0.00 and every refund on that order is refused. REPORTED, NOT
       FIXED: raising a zero ceiling changes what can be refunded, which is money. */
    $nullOrder = $orderWith(null);

    check(
        'the engine really stored a NULL final_price',
        $nullOrder->final_price === null,
        'stored=' . var_export($nullOrder->final_price, true)
    );

    $nulled = $service->cappedRefundAmount('100', $nullOrder->final_price, 'gap121');

    check(
        'a NULL final_price gives a ZERO ceiling, so every refund on that order is capped to 0.00',
        abs($nulled['amount']) < 0.0001 && abs($nulled['limit']) < 0.0001,
        'amount=' . var_export($nulled['amount'], true) . ' limit=' . var_export($nulled['limit'], true)
    );
    printf(
        "        a 100.00 request against a NULL total yields %s (limit %s) -- the controller then\n",
        number_format($nulled['amount'], 2), number_format($nulled['limit'], 2)
    );
    printf("        refuses it 422 with \"must be a positive number\". REPORTED, not changed.\n");

    // ─────────────────────────── [C] strict => false coercion into a money column
    echo "\n[C] COERCION -- what this engine accepts into a money column that SQLite would not\n";

    $sloppy = $orderWith('267.00abc');

    check(
        'the engine ACCEPTED a non-numeric string into the double column and coerced it',
        $sloppy->final_price !== null && abs((float) $sloppy->final_price - 267.00) < 0.0001,
        'stored=' . var_export($sloppy->final_price, true)
        . " -- SQLite's affinity stores the TEXT instead, so the ceiling differs by engine"
    );
    printf("        wrote '267.00abc', the engine stored %s\n", var_export($sloppy->final_price, true));

    $fromSloppy = $service->cappedRefundAmount('300', $sloppy->final_price, 'gap121');
    check(
        'and the ceiling built from that coerced value is the numeric prefix, not zero',
        abs($fromSloppy['amount'] - 267.00) < 0.0001,
        'amount=' . var_export($fromSloppy['amount'], true)
    );

    /* THE SAME QUESTION ON THE REFUND'S OWN COLUMN, because that is where the figure is STORED. */
    DB::connection('fresh')->table('order_refunds')->insert([
        'order_id' => $sloppy->id, 'refund_status' => 'Accept',
        'product_refund_id' => '[]', 'refund_amount' => '150abc', 'store_id' => 1,
    ]);
    $storedRefund = DB::connection('fresh')->table('order_refunds')->orderByDesc('id')->first();
    check(
        'a non-numeric refund_amount is likewise coerced rather than refused',
        abs((float) $storedRefund->refund_amount - 150.00) < 0.0001,
        'stored=' . var_export($storedRefund->refund_amount, true)
    );

    // ─────────────────────────── [D] the control: the sign refusal still refuses
    echo "\n[D] THE CONTROL -- the sign refusal, so §B/§C cannot read as \"all refunds refused\"\n";

    $order = $orderWith(267.00);
    $signed = $service->cappedRefundAmount('-500', $order->final_price, 'gap121');

    check(
        'a signed request is refused outright rather than becoming a positive refund',
        abs($signed['amount']) < 0.0001,
        'amount=' . var_export($signed['amount'], true)
    );

    /* AND THE CONTROLLER'S OWN NORMALISATION DOES THE OPPOSITE, which is why the service diverges.
       Driven here, on the same input, so the divergence is a measurement and not a claim. */
    $controllerShape = (float) preg_replace('/[^0-9.]/', '', '-500');
    check(
        'the controller regex would have turned the same -500 into a POSITIVE 500',
        abs($controllerShape - 500.0) < 0.0001,
        'controller normalisation=' . var_export($controllerShape, true)
        . ' -- the service refuses where the regex would pay out'
    );

    // ─────────────────────────── [E] the id coercion on the refund lookup
    echo "\n[E] IDENTIFIER COERCION -- which refund row a non-integer id resolves\n";

    DB::connection('fresh')->table('order_refunds')->truncate();
    $refundId = DB::connection('fresh')->table('order_refunds')->insertGetId([
        'order_id' => $order->id, 'refund_status' => 'Accept',
        'product_refund_id' => '[]', 'refund_amount' => 0, 'store_id' => 1,
    ]);

    /* The controller's own expression shape: `where('id', $request->refundId)->where('store_id', ...)`,
       with no integer parse anywhere between the request and the query. */
    $resolved = DB::connection('fresh')->table('order_refunds')
        ->where('id', (string) $refundId . 'abc')
        ->where('store_id', 1)
        ->first();

    check(
        'the engine resolves a trailing-garbage refund id to the numeric row',
        $resolved !== null && (int) $resolved->id === (int) $refundId,
        'resolved=' . ($resolved === null ? 'none' : (string) $resolved->id)
        . ' -- SQLite matches nothing, so a refund mutation keyed on request input resolves '
        . 'differently on the two engines'
    );
    check(
        'and FILTER_VALIDATE_INT refuses the same string, which is the only place it can be refused',
        filter_var((string) $refundId . 'abc', FILTER_VALIDATE_INT) === false,
        'as CLAUDE.md prescribes: is_numeric is not enough and the engine will not do it'
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
echo "NOTHING WAS CHANGED. §B's zero ceiling on a NULL total is reported for an owner decision.\n";

exit($failures === 0 ? 0 : 1);
