#!/usr/bin/env php
<?php
/**
 * GAP-154 / GAP-171 -- THE MERCHANT ANALYTICS SCREEN'S `GROUP BY` READS, ON THE PRODUCTION ENGINE.
 * =================================================================================================
 *
 * WHY THIS EXISTS. `GAP-154` asks for "a MySQL harness for the screen's GROUP BY reads" and
 * `GAP-171` lists "the analytics screen's GROUP BY breakdowns" among five items with no MySQL
 * evidence and no recorded decision that none is needed. `/analytics` has three grouped reads --
 * `orders.order_source`, and `shetabit_visits.device` / `.referer` / `.url` through
 * `groupedVisits()` -- and the Feature suite can only ever execute them on SQLite, whose collation
 * is BINARY and whose `COUNT(*)` type differs. Three of the four questions below cannot be asked
 * there at all.
 *
 * WHAT IT FOUND ON ITS FIRST RUN, and the reason this file is not a formality. MariaDB 10.11,
 * `ONLY_FULL_GROUP_BY` on, three orders whose `order_source` reads `POS`, `pos`, `pos`:
 *
 *     engine:  [{"bucket":"POS","total":3}]                       ONE case-insensitive group
 *     screen:  "POS (not a source this version knows)"  x3        every POS order relabelled
 *
 * One row carrying a spelling the writers never produce captured the whole group, and the merchant
 * was told three point-of-sale orders came from a source the product does not recognise -- with the
 * count still correct, so the breakdown summed to the order total and looked healthy. SQLite shows
 * two groups and mislabels only the odd row, so the suite could not see it. `sources()` now folds
 * the bucket key before the label lookup; `OrderSourceBreakdownFoldsSpellingsTest` pins the fix on
 * SQLite and section A below pins it here.
 *
 * WHAT IT ALSO MEASURES, so that the next reader does not have to guess:
 *
 *   B. The device breakdown's ROW COUNT is engine-dependent and its labels are not. `WebKit` and
 *      `webkit` are ONE group here and TWO on SQLite. There is no mislabel, because the label IS
 *      the value, so this is recorded rather than changed: the values come from the detector
 *      library's own rule keys, and deciding that two of its spellings are one device is adjacent
 *      to the device-CLASS question `GAP-227(b)(1)` reserves to the owner.
 *
 *   C. The referring-page breakdown agrees across engines BECAUSE of a PHP fold, not by luck.
 *      `https://Google.com/` and `https://google.com/` are one group here and two on SQLite;
 *      `channels()` reduces both to the host `google.com`, so the figure is the same either way.
 *      That is the `MoneyText::buckets()` pattern -- normalise AFTER the engine has grouped -- and
 *      this is the evidence for it.
 *
 *   D. A long URL is NOT truncated into a false group. `max_sort_length` (1024 by default) applies
 *      to sorting BLOB/TEXT values, and the campaign read groups a `mediumText` url column, so the
 *      worry was that two 1,100-byte URLs differing only at the end would collapse into one bucket
 *      here and stay two in the suite. MEASURED: they stay two. Recorded so the question is settled
 *      by a run rather than re-derived from the manual.
 *
 *   E. `COUNT(*)` comes back as an INTEGER through this driver, not a string -- the opposite of
 *      `SUM()` over `DECIMAL(15,2)`, which `verify-gap222-wallet-currency-on-mysql.php` measured as
 *      a string. So `number_format()` over `$row->total` is safe, and that is a measurement rather
 *      than an assumption.
 *
 *   F. Both grouped reads survive `ONLY_FULL_GROUP_BY`, which this script forces ON even though
 *      `config/database.php` sets `strict => false` for mysql. Testing under the stricter mode is
 *      the only direction safe to be wrong in: a query that passes here cannot be broken by a
 *      server whose mode is stricter than ours.
 *
 *   G. The store predicate holds on the real engine. `Order` and `Visit` are both absent from
 *      `config/tenancy.php`'s `scoped_models`, so nothing but the written `where('store_id', ...)`
 *      confines either read -- for any principal, operator included. A second store's orders and
 *      visits are seeded for exactly this.
 *
 * SAFETY. It creates and drops its own database and refuses a name without `verify` in it. Never
 * point it at a development or production server.
 *
 * USAGE
 *   SESSION_DRIVER=array php tests/Support/Security/bin/verify-analytics-breakdowns-on-mysql.php
 *
 * `SESSION_DRIVER=array` is needed only in an environment with no `.env`: `config/session.php`
 * defaults to the `database` driver, and a script that boots the framework with the default
 * connection still pointing at sqlite can touch the session table before this one takes over.
 * The runner passes the same.
 *
 * Exit: 0 = every check held · 1 = a check failed · 2 = a precondition was not met.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\Metrics\MerchantAnalyticsFigures;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$database = getenv('BREAKDOWN_MYSQL_DATABASE') ?: ('analytics_breakdowns_verify_' . getmypid());

if (! preg_match('/verify/i', $database)) {
    fwrite(STDERR, "Refusing a database whose name does not say it is throwaway: {$database}\n");
    exit(2);
}

$socket = getenv('BREAKDOWN_MYSQL_SOCKET');
if ($socket === false) {
    foreach (['/run/mysqld/mysqld.sock', '/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock'] as $candidate) {
        if (file_exists($candidate)) {
            $socket = $candidate;
            break;
        }
    }
}

$connection = [
    'driver' => 'mysql',
    'host' => getenv('BREAKDOWN_MYSQL_HOST') ?: '127.0.0.1',
    'port' => getenv('BREAKDOWN_MYSQL_PORT') ?: '3306',
    'unix_socket' => $socket ?: '',
    'database' => $database,
    'username' => getenv('BREAKDOWN_MYSQL_USERNAME') ?: 'root',
    'password' => getenv('BREAKDOWN_MYSQL_PASSWORD') ?: '',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'strict' => false,
];

$bootstrapConnection = $connection;
$bootstrapConnection['database'] = null;

config(['database.connections.bd_bootstrap' => $bootstrapConnection]);
config(['database.connections.bd' => $connection]);

$checks = 0;
$failures = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $checks, $failures;
    $checks++;

    if ($ok) {
        echo "  ok    {$what}\n";

        return;
    }

    $failures++;
    echo "  FAIL  {$what}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
}

try {
    DB::connection('bd_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
    DB::connection('bd_bootstrap')->statement(
        "CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
} catch (\Throwable $e) {
    fwrite(STDERR, "PRECONDITION NOT MET: cannot create a throwaway database -- {$e->getMessage()}\n");
    exit(2);
}

$exit = 0;

try {
    config(['database.default' => 'bd']);
    DB::purge('bd');

    echo 'Server: ' . DB::connection('bd')->selectOne('SELECT VERSION() v')->v . "\n";
    echo "Database: {$database}\n";

    /* F. The stricter mode, forced. See the docblock: safe to be wrong in only one direction. */
    DB::connection('bd')->statement("SET SESSION sql_mode = CONCAT(@@sql_mode, ',ONLY_FULL_GROUP_BY')");
    $mode = DB::connection('bd')->selectOne('SELECT @@sql_mode m')->m;
    echo "sql_mode: {$mode}\n\n";

    echo "Running migrations...\n";
    Artisan::call('migrate', ['--database' => 'bd', '--force' => true]);
    echo "  done\n\n";

    $ownerId = DB::table('users')->insertGetId([
        'name' => 'Breakdown owner', 'email' => 'breakdowns@example.test',
        'password' => bcrypt('x'), 'type' => 'admin',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach ([1, 2] as $id) {
        DB::table('stores')->insert([
            'id' => $id, 'name' => 'Breakdown store ' . $id, 'slug' => 'breakdown-store-' . $id,
            'email' => 'bd' . $id . '@example.test', 'theme_id' => 'stylique',
            'created_by' => $ownerId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $orderSeq = 0;
    $order = function (int $storeId, $source) use (&$orderSeq): void {
        $orderSeq++;
        DB::table('orders')->insert([
            'product_order_id' => 'BD-' . $orderSeq, 'product_json' => '[]',
            'product_price' => 0.0, 'delivery_price' => 0.0, 'tax_price' => 0.0, 'coupon_price' => 0.0,
            'final_price' => 10.0, 'currency_code' => 'USD',
            'payment_type' => 'cod', 'payment_status' => 'Paid', 'delivered_status' => 1,
            'order_source' => $source,
            'store_id' => $storeId, 'order_date' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    $visit = function (int $storeId, string $referer, string $device, ?string $url = null): void {
        DB::table(config('visitor.table_name'))->insert([
            'store_id' => $storeId, 'method' => 'GET',
            'url' => $url ?? 'https://shop.test/', 'referer' => $referer,
            'device' => $device, 'platform' => 'Linux', 'browser' => 'Chrome',
            'ip' => '127.0.0.1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    /*
     * THE UPPER-CASE ROW IS INSERTED FIRST, DELIBERATELY. The engine represents a group by the row
     * it reached first, so inserting `POS` first is what makes the defect this script found
     * reproducible -- with `pos` first the group is represented by a spelling the label map knows
     * and the mislabel hides.
     */
    foreach (['POS', 'pos', 'pos'] as $spelling) {
        $order(1, $spelling);
    }

    $order(1, null);          // the absence
    $order(1, '');            // the other absence: two SQL groups, one real meaning
    $order(1, 'storefront');  // a recognised value, untouched
    $order(2, 'pos');         // G: another tenant

    foreach (['https://Google.com/', 'https://google.com/', 'https://google.com/ '] as $referer) {
        $visit(1, $referer, 'WebKit');
    }

    $visit(1, 'https://bing.com/', 'webkit');   // B: the second spelling of one device
    $visit(2, 'https://Google.com/', 'WebKit'); // G: another tenant

    /* D. Two URLs identical for their first 1,100 bytes. */
    $longUrl = 'https://shop.test/?utm_source=' . str_repeat('a', 1100);
    $visit(1, '', 'WebKit', $longUrl . 'ONE');
    $visit(1, '', 'WebKit', $longUrl . 'TWO');

    foreach ([\App\Models\Order::class] as $model) {
        if (method_exists($model, 'flushCache')) {
            (new $model())->flushCache();
        }
    }

    $visitsTable = config('visitor.table_name');

    $grouped = function (string $sql) {
        return DB::connection('bd')->select($sql);
    };

    // ------------------------------------------------------------------ A. order_source

    echo "A. orders.order_source -- 'POS', 'pos', 'pos' plus two absences and one storefront\n";

    $engineSources = $grouped(
        'SELECT order_source AS bucket, COUNT(*) AS total FROM orders WHERE store_id = 1 GROUP BY order_source'
    );

    echo '   engine: ' . json_encode(array_map(fn ($r) => [$r->bucket, $r->total], $engineSources)) . "\n";

    $posGroups = array_values(array_filter(
        $engineSources,
        fn ($r) => is_string($r->bucket) && strtolower(trim($r->bucket)) === 'pos'
    ));

    check('PREMISE: the three spellings of pos are ONE group on this engine (SQLite says two)',
        count($posGroups) === 1 && (int) $posGroups[0]->total === 3,
        json_encode(array_map(fn ($r) => [$r->bucket, $r->total], $posGroups)));

    check('E: COUNT(*) comes back as an integer through this driver, not a string',
        gettype($engineSources[0]->total) === 'integer',
        gettype($engineSources[0]->total));

    $figures = MerchantAnalyticsFigures::forStore(1, 'USD');

    $digits = static fn (string $value): string => (string) preg_replace('/[^0-9]/u', '', $value);

    $sourceRows = [];
    foreach ($figures['sources']['rows'] as $row) {
        $sourceRows[$row['label']] = $digits($row['count']);
    }

    echo '   screen: ' . json_encode($sourceRows, JSON_UNESCAPED_UNICODE) . "\n";

    check('the three point-of-sale orders are ONE row named "Point of sale", with 3',
        ($sourceRows[__('Point of sale')] ?? null) === '3',
        json_encode($sourceRows, JSON_UNESCAPED_UNICODE)
        . ' -- before the fold this read "POS (not a source this version knows)": one hand-written '
        . 'row relabelled every point-of-sale order, and the count stayed right');

    check('no row claims the source is unrecognised',
        ! array_key_exists(__(':value (not a source this version knows)', ['value' => 'POS']), $sourceRows)
        && ! array_key_exists(__(':value (not a source this version knows)', ['value' => 'pos']), $sourceRows),
        implode(' | ', array_keys($sourceRows)));

    check('NULL and the empty string fold into ONE absence row, with 2',
        ($sourceRows[__('Source not recorded (placed before this was tracked)')] ?? null) === '2',
        json_encode($sourceRows, JSON_UNESCAPED_UNICODE));

    check('G: the other tenant\'s order is not in this store\'s channel breakdown',
        ($sourceRows[__('Storefront')] ?? null) === '1' && array_sum(array_map('intval', $sourceRows)) === 6,
        'rows sum to ' . array_sum(array_map('intval', $sourceRows)) . ', store 1 placed 6 orders');

    // ------------------------------------------------------------------ B. device

    echo "\nB. shetabit_visits.device -- 'WebKit' and 'webkit'\n";

    $engineDevices = $grouped(
        'SELECT device AS bucket, COUNT(*) AS total FROM ' . $visitsTable . ' WHERE store_id = 1 GROUP BY device'
    );

    echo '   engine: ' . json_encode(array_map(fn ($r) => [$r->bucket, $r->total], $engineDevices)) . "\n";

    check('the two spellings are ONE group here, where SQLite gives two -- recorded, not changed',
        count($engineDevices) === 1 && (int) $engineDevices[0]->total === 6,
        json_encode(array_map(fn ($r) => [$r->bucket, $r->total], $engineDevices)));

    $deviceRows = [];
    foreach ($figures['devices']['rows'] as $row) {
        $deviceRows[$row['label']] = $digits($row['count']);
    }

    echo '   screen: ' . json_encode($deviceRows, JSON_UNESCAPED_UNICODE) . "\n";

    check('the breakdown names the device by its own value and loses no visit',
        array_sum(array_map('intval', $deviceRows)) === 6,
        json_encode($deviceRows, JSON_UNESCAPED_UNICODE) . ' -- store 1 logged 6 visits');

    // ------------------------------------------------------------------ C. referer

    echo "\nC. shetabit_visits.referer -- three spellings of one host\n";

    $engineReferers = $grouped(
        'SELECT referer AS bucket, COUNT(*) AS total FROM ' . $visitsTable . ' WHERE store_id = 1 GROUP BY referer'
    );

    echo '   engine: ' . json_encode(array_map(fn ($r) => [$r->bucket, $r->total], $engineReferers)) . "\n";

    $googleGroups = array_values(array_filter(
        $engineReferers,
        fn ($r) => is_string($r->bucket) && stripos($r->bucket, 'google') !== false
    ));

    check('PREMISE: the three spellings of one referring host are ONE group here',
        count($googleGroups) === 1 && (int) $googleGroups[0]->total === 3,
        json_encode(array_map(fn ($r) => [$r->bucket, $r->total], $googleGroups)));

    $channelRows = [];
    foreach ($figures['channels']['rows'] as $row) {
        $channelRows[$digits($row['label']) === '' ? $row['label'] : $row['label']] = $digits($row['count']);
    }

    echo '   screen: ' . json_encode($channelRows, JSON_UNESCAPED_UNICODE) . "\n";

    $googleLabel = null;
    foreach (array_keys($channelRows) as $label) {
        if (stripos($label, 'google') !== false) {
            $googleLabel = $label;
        }
    }

    check('the screen reduces them to ONE host row with 3, which is what makes the two engines agree',
        $googleLabel !== null && $channelRows[$googleLabel] === '3',
        json_encode($channelRows, JSON_UNESCAPED_UNICODE));

    check('G: the other tenant\'s visit is not in this store\'s referring-page breakdown',
        array_sum(array_map('intval', $channelRows)) === 6,
        'rows sum to ' . array_sum(array_map('intval', $channelRows)) . ', store 1 logged 6 visits');

    // ------------------------------------------------------------------ D. long urls

    echo "\nD. shetabit_visits.url -- two 1,100-byte URLs differing only at the end\n";

    $engineUrls = $grouped(
        'SELECT url AS bucket, COUNT(*) AS total FROM ' . $visitsTable . ' WHERE store_id = 1 GROUP BY url'
    );

    $longGroups = array_values(array_filter(
        $engineUrls,
        fn ($r) => is_string($r->bucket) && strlen($r->bucket) > 1000
    ));

    echo '   engine: ' . count($longGroups) . " long group(s), lengths "
        . json_encode(array_map(fn ($r) => [strlen($r->bucket), $r->total], $longGroups)) . "\n";

    check('GROUP BY does NOT truncate a long TEXT value into a false group (max_sort_length)',
        count($longGroups) === 2,
        'the two 1,100-byte URLs collapsed into ' . count($longGroups) . ' group(s), so the '
        . 'campaign breakdown would merge distinct landing pages on this engine and not in the suite');

    echo "\n{$checks} checks, {$failures} failed\n";
    $exit = $failures > 0 ? 1 : 0;
} catch (\Throwable $e) {
    fwrite(STDERR, "\nUNEXPECTED: " . get_class($e) . ': ' . $e->getMessage() . "\n");
    fwrite(STDERR, substr($e->getTraceAsString(), 0, 1800) . "\n");
    $exit = 1;
} finally {
    try {
        DB::purge('bd');
        DB::connection('bd_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
        echo "Dropped {$database}\n";
    } catch (\Throwable $e) {
        fwrite(STDERR, "WARNING: could not drop {$database} -- {$e->getMessage()}\n");
    }
}

exit($exit);
