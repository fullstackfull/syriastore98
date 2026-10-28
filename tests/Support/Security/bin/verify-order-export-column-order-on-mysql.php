#!/usr/bin/env php
<?php
/**
 * F154 / F153 — the order export's HEADING-TO-VALUE ALIGNMENT, verified on a real MySQL server.
 *
 * WHY THIS SCRIPT EXISTS AND THE FEATURE TEST IS NOT ENOUGH. The owner's directive asks for
 * "export column order" among the things to be proved on MySQL/MariaDB rather than on SQLite, and
 * the reason is the one CLAUDE.md states: SQLite's type affinity hides real MySQL defects, and it
 * hid P01-SEC-13 for exactly that reason. The Feature suite's
 * `OrderExportColumnAlignmentTest` drives the same subject on SQLite `:memory:`, where every
 * `orders` column accepts every value. Here the columns have their declared MySQL types, so a value
 * that lands under the wrong heading because of a coercion, or a numeric column that returns a
 * string, shows up.
 *
 * WHAT F154 WAS. `OrderExport::headings()` and the row builder were two independent lists, so a
 * column added to one and not the other shifted every value after it by one position: a merchant's
 * spreadsheet showed the delivery price under "Tax Price" and so on, silently. The class now drives
 * BOTH from one `COLUMNS` constant, which is the fix this script pins against a real engine.
 *
 * WHAT F153 WAS. "Reward Points" was a computed branch returning the literal `1` for every order.
 * It now reads `orders.reward_points`. On MySQL that column has a declared type, so this is also
 * where "the stored value survives the round trip" means something.
 *
 * usage, against a THROWAWAY server (it issues DROP DATABASE):
 *
 *   EXPORTORDER_MYSQL_SOCKET=/var/run/mysqld/mysqld.sock \
 *   EXPORTORDER_MYSQL_DATABASE=exportorder_verify \
 *   EXPORTORDER_MYSQL_USERNAME=root \
 *   EXPORTORDER_MYSQL_PASSWORD= \
 *     php tests/Support/Security/bin/verify-order-export-column-order-on-mysql.php
 */

require __DIR__ . '/../../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Exports\OrderExport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$database = getenv('EXPORTORDER_MYSQL_DATABASE') ?: 'exportorder_verify';

if (! str_contains($database, 'verify')) {
    fwrite(STDERR, "REFUSING: database name [{$database}] does not contain 'verify'.\n");
    fwrite(STDERR, "This script creates and drops the database it is given. Name it unmistakably.\n");
    exit(2);
}

/* The socket is tried before TCP for the reason the coupon verifier records: a stock Ubuntu MySQL
   authenticates `root` through auth_socket, so TCP is refused even with the right credentials. */
$socket = getenv('EXPORTORDER_MYSQL_SOCKET');

if ($socket === false) {
    foreach (['/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock', '/var/lib/mysql/mysql.sock'] as $candidate) {
        if (file_exists($candidate)) {
            $socket = $candidate;
            echo "using the unix socket at {$candidate}\n";
            break;
        }
    }
}

$connection = [
    'driver' => 'mysql',
    'host' => getenv('EXPORTORDER_MYSQL_HOST') ?: '127.0.0.1',
    'port' => getenv('EXPORTORDER_MYSQL_PORT') ?: '3306',
    'unix_socket' => $socket ?: '',
    'database' => $database,
    'username' => getenv('EXPORTORDER_MYSQL_USERNAME') ?: 'root',
    'password' => getenv('EXPORTORDER_MYSQL_PASSWORD') ?: '',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    /* `strict => false` is PRODUCTION's setting (config/database.php). Running strict here would
       make this script kinder than production and therefore useless as evidence: the whole class of
       defect it is looking for is a value COERCED into a column rather than refused by it. */
    'strict' => false,
];

$bootstrap = $connection;
$bootstrap['database'] = null;
config(['database.connections.exportorder_bootstrap' => $bootstrap]);
config(['database.connections.exportorder' => $connection]);

/* `App\Models\Order` is Cachable (CLAUDE.md), so the export's own read would go through the
   configured cache store -- `database`, resolving against the DEFAULT sqlite connection, which has
   no `cache` table. That is what made the coupon verifier report a harness failure as a defect. */
config(['cache.default' => 'array']);

/* AND THE SESSION, for the same reason one step later. `Auth::login()` regenerates the session, and
   the `database` session driver writes to a `sessions` table on the DEFAULT connection -- so the
   second run of this script died on "no such table: sessions (Connection: sqlite)" after the order
   row had been written. Two harness failures in a row, each reported as a FAIL by a script whose
   subject was fine: worth the two lines of comment, because that is how a harness problem gets
   mistaken for a defect. */
config(['session.driver' => 'array']);
config(['database.default' => 'exportorder']);

$checks = 0;
$failures = [];

function ok(string $what): void
{
    global $checks;
    $checks++;
    echo "  ok    {$what}\n";
}

function bad(string $what, string $detail = ''): void
{
    global $checks, $failures;
    $checks++;
    $failures[] = $what;
    echo "  FAIL  {$what}" . ($detail !== '' ? "\n        {$detail}" : '') . "\n";
}

function note(string $what): void
{
    echo "  note  {$what}\n";
}

echo "F154/F153 -- order export column alignment, verified on MySQL\n";
echo str_repeat('-', 78) . "\n";

try {
    DB::connection('exportorder_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
    DB::connection('exportorder_bootstrap')->statement(
        "CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );

    $server = DB::connection('exportorder')->select('SELECT VERSION() AS v')[0];
    echo "  MySQL {$server->v}, database `{$database}`, strict=false (production's setting)\n\n";

    echo "A. the schema this export reads\n";
    Artisan::call('migrate', ['--force' => true, '--database' => 'exportorder']);

    if (! Schema::connection('exportorder')->hasTable('orders')) {
        bad('the orders table exists after migrate', 'nothing below can run');
        throw new RuntimeException('no orders table');
    }
    ok('migrate built the orders table on MySQL');

    /* THE PREMISE OF THE WHOLE FILE: every attribute COLUMNS maps to must be a real column on
       MySQL. A heading mapped to an attribute that is not a column would read null forever and the
       alignment assertions below would pass while the cell was empty. */
    $reflection = new ReflectionClass(OrderExport::class);
    $columns = $reflection->getConstant('COLUMNS');

    if (! is_array($columns) || $columns === []) {
        bad('OrderExport::COLUMNS is readable and non-empty');
        throw new RuntimeException('no COLUMNS');
    }
    ok('OrderExport::COLUMNS is the single source for headings and values (' . count($columns) . ' columns)');

    $missing = [];
    foreach ($columns as $heading => $attribute) {
        if ($attribute === null) {
            continue;   // computed in map(), asserted separately below
        }
        if (! Schema::connection('exportorder')->hasColumn('orders', $attribute)) {
            $missing[] = "{$heading} -> {$attribute}";
        }
    }

    if ($missing === []) {
        ok('every mapped attribute is a real orders column on MySQL');
    } else {
        /* NOT a failure of the export: GATE F records 15 tables that live code reads and writes
           with no migration, so a column the application uses can legitimately be absent from a
           from-zero migrate. It is reported because an absent column reads null and would make the
           value assertions below vacuous for that heading. */
        note('mapped attributes with no column after a from-zero migrate (GATE F territory, '
            . 'reported rather than asserted): ' . implode(', ', $missing));
    }

    echo "\nB. a real order, exported\n";

    /* THE USER FIRST, AND THAT ORDER IS ITSELF A MySQL-ONLY FACT. The first run of this script
       inserted the store first, with `created_by => 1`, and MySQL refused it:

         1452 Cannot add or update a child row: a foreign key constraint fails
              (`stores`, CONSTRAINT `stores_created_by_foreign` FOREIGN KEY (`created_by`)
               REFERENCES `users`(`id`) ON DELETE CASCADE)

       SQLite does not enforce foreign keys unless `PRAGMA foreign_keys=ON` is issued, and the
       Feature suite does not issue it -- so every fixture in this repository can invent a
       `created_by` that references nothing, and the same insert that works there is refused here.
       That is precisely the class of difference this file exists to surface, so it is recorded
       rather than quietly worked around. */
    $userId = DB::connection('exportorder')->table('users')->insertGetId([
        'name' => 'Export Verify Merchant',
        'email' => 'export-verify@example.test',
        'password' => bcrypt('not-a-real-password'),
        'type' => 'admin',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $storeId = DB::connection('exportorder')->table('stores')->insertGetId([
        'name' => 'Export Verify Store',
        'slug' => 'export-verify-store',
        'created_by' => $userId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::connection('exportorder')->table('users')->where('id', $userId)
        ->update(['current_store' => $storeId]);

    $customerId = DB::connection('exportorder')->table('customers')->insertGetId([
        'first_name' => 'Nadia', 'last_name' => 'Exporter',
        'email' => 'nadia-export@example.test',
        'mobile' => '555000111',
        'password' => bcrypt('not-a-real-password'),
        'store_id' => $storeId,
        'status' => 1,
        'register_type' => 'email',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $productId = DB::connection('exportorder')->table('products')->insertGetId([
        'name' => 'Export Verify Product',
        'slug' => 'export-verify-product',
        'product_stock' => 10,
        'price' => 250.00,
        'store_id' => $storeId,
        'created_by' => $userId,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    /* DISTINCT, RECOGNISABLE VALUES IN EVERY MONEY COLUMN. The defect F154 was is a SHIFT, and a
       shift is invisible when two adjacent columns hold the same number -- which is why these are
       deliberately all different and none is a round figure shared with its neighbour. */
    $money = [
        'product_price'  => '111.11',
        'coupon_price'   => '222.22',
        'delivery_price' => '333.33',
        'tax_price'      => '444.44',
        'final_price'    => '555.55',
        'return_price'   => '666.66',
    ];

    $orderRow = array_merge($money, [
        'product_order_id' => 'EXP-ALIGN-1',
        'order_date' => '2026-10-28 11:22:33',
        'customer_id' => $customerId,
        'product_id' => (string) $productId,
        'payment_type' => 'bank_transfer',
        'payment_status' => 'Paid',
        /* AN INTEGER, because the column is one: `integer ... comment('0=>pending/1=>diliver/
           2=>cancel/3=>return')`. The first draft of this script wrote the word 'Delivered' here
           and MySQL returned 0 -- see section G, which pins that coercion deliberately now that it
           is understood. */
        'delivered_status' => 1,
        'reward_points' => 7,            // F153: the stored value, not the literal 1
        'additional_note' => 'align-note',
        'store_id' => $storeId,
        'created_by' => $userId,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Only columns the migrated schema actually has, so a GATE-F absence cannot abort the insert.
    $orderRow = array_filter(
        $orderRow,
        fn ($k) => Schema::connection('exportorder')->hasColumn('orders', $k),
        ARRAY_FILTER_USE_KEY
    );

    DB::connection('exportorder')->table('orders')->insert($orderRow);
    ok('an order row was written with a distinct value in every money column');

    /* The export resolves its store from the AUTHENTICATED principal via getCurrentStore(), so the
       principal is logged in rather than the store being passed -- that is the path a merchant's
       download actually takes, and P1-SEC-11 criterion 2 is about exactly this resolution. */
    $merchant = App\Models\User::on('exportorder')->find($userId);

    /* `setUser()`, NOT `login()`. `Auth::login()` regenerates the session, and the session driver
       resolved before this script could reconfigure it, so it wrote to a `sessions` table on the
       default sqlite connection and died there -- twice, with the order row already written and the
       script reporting its own harness as a FAIL. `setUser()` puts the principal on the guard in
       memory and touches no session, which is all `getCurrentStore()` needs: it reads
       `auth()->user()`. */
    Illuminate\Support\Facades\Auth::guard('web')->setUser($merchant);

    $export = new OrderExport();
    $headings = $export->headings();
    $rows = $export->collection();

    if (count($rows) !== 1) {
        bad('the export returned exactly the one order of this store', 'got ' . count($rows));
        throw new RuntimeException('wrong row count');
    }
    ok('the export returned exactly this store\'s one order');

    $values = $export->map($rows->first());

    echo "\nC. heading-to-value ALIGNMENT, which is what F154 broke\n";

    if (count($headings) === count($values)) {
        ok('the heading row and the data row have the same width (' . count($headings) . ')');
    } else {
        bad('the heading row and the data row have the same width',
            'headings=' . count($headings) . ' values=' . count($values));
    }

    $byHeading = [];
    foreach ($headings as $i => $heading) {
        $byHeading[$heading] = $values[$i] ?? null;
    }

    /* EACH VALUE UNDER ITS OWN HEADING, by name. A width check alone passes a wholesale shift. */
    $expected = [
        'Order Id' => 'EXP-ALIGN-1',
        'Product Price' => '111.11',
        'Coupon Price' => '222.22',
        'Delivery Price' => '333.33',
        'Tax Price' => '444.44',
        'Final Price' => '555.55',
        'Return Price' => '666.66',
        'Payment Type' => 'bank_transfer',
        'Payment status' => 'Paid',
        'Delivered status' => 1,
        'Additional Note' => 'align-note',
    ];

    foreach ($expected as $heading => $want) {
        if (! array_key_exists($heading, $byHeading)) {
            bad("the export still has a `{$heading}` column");
            continue;
        }

        $got = $byHeading[$heading];

        // Compared numerically where both sides are numeric: MySQL returns DECIMAL as a string and
        // the point of the assertion is WHICH column the value came from, not its PHP type.
        $same = (is_numeric($want) && is_numeric($got))
            ? abs((float) $want - (float) $got) < 0.00001
            : (string) $want === (string) $got;

        $same
            ? ok("`{$heading}` holds its own value ({$got})")
            : bad("`{$heading}` holds its own value", "expected {$want}, got " . var_export($got, true));
    }

    echo "\nD. F153 -- Reward Points is the STORED value on MySQL too\n";

    if (array_key_exists('Reward Points', $byHeading)) {
        $points = $byHeading['Reward Points'];

        (int) $points === 7
            ? ok('Reward Points exports the stored 7')
            : bad('Reward Points exports the stored 7', 'got ' . var_export($points, true));

        (int) $points !== 1 || 7 === 1
            ? ok('and it is not the literal 1 the computed branch used to return')
            : bad('and it is not the literal 1 the computed branch used to return');
    } else {
        bad('the export still has a `Reward Points` column');
    }

    echo "\nE. the computed columns, which map() resolves rather than reading\n";

    foreach (['User Name' => 'Nadia', 'product Name' => 'Export Verify Product'] as $heading => $fragment) {
        $got = (string) ($byHeading[$heading] ?? '');

        str_contains($got, $fragment)
            ? ok("`{$heading}` resolved to the related row ({$got})")
            : bad("`{$heading}` resolved to the related row", "expected to contain {$fragment}, got " . var_export($got, true));
    }

    echo "\nF. tenant isolation on the export, on a real engine\n";

    $otherStore = DB::connection('exportorder')->table('stores')->insertGetId([
        'name' => 'Other Verify Store', 'slug' => 'other-verify-store',
        'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $otherRow = $orderRow;
    $otherRow['product_order_id'] = 'EXP-ALIGN-OTHER';
    $otherRow['store_id'] = $otherStore;
    DB::connection('exportorder')->table('orders')->insert($otherRow);

    $again = (new OrderExport())->collection();
    $numbers = $again->pluck('product_order_id')->all();

    (count($again) === 1 && $numbers === ['EXP-ALIGN-1'])
        ? ok('a second store\'s order is not exported')
        : bad('a second store\'s order is not exported', 'exported: ' . implode(', ', $numbers));
    echo "\nG. the SQLite-versus-MySQL coercion this script tripped over, pinned\n";

    /* WHAT HAPPENED, AND WHY IT IS AN ASSERTION RATHER THAN A FOOTNOTE. The first draft wrote the
       string 'Delivered' into `orders.delivered_status` and the export read back 0. That is not a
       defect in the export: the column is declared `integer` with
       `comment('0=>pending/1=>diliver/2=>cancel/3=>return')`, every writer in `app/` writes an
       integer, and so does every writer in `packages/workdo/` -- checked, including
       PartialPayments (7) and AuctionProduct. My fixture was wrong, not the code.

       But the COERCION is real and it is exactly the class of difference this file exists to
       record. Production runs `strict => false` (config/database.php), so MySQL silently turns a
       word into 0 and the order reads as "pending"; SQLite's type affinity would store the word
       verbatim and a Feature test would see it. If any writer ever did put a word there, the
       application would be wrong in production and right in the test suite -- the direction that
       hides defects rather than inventing them. It is latent today and asserted so that it stays
       visible. */
    $probe = 'delivered_status_probe';
    DB::connection('exportorder')->table('orders')
        ->where('product_order_id', 'EXP-ALIGN-1')
        ->update(['delivered_status' => DB::raw("'Delivered'")]);

    $coerced = DB::connection('exportorder')->table('orders')
        ->where('product_order_id', 'EXP-ALIGN-1')->value('delivered_status');

    (int) $coerced === 0
        ? ok('MySQL with strict=false coerces a word in delivered_status to 0 (SQLite would keep the word)')
        : note('this server did NOT coerce a word to 0 in delivered_status: got '
            . var_export($coerced, true) . ' -- the premise of the note above has changed');
} catch (Throwable $e) {
    bad('uncaught: ' . get_class($e), $e->getMessage());
} finally {
    try {
        DB::connection('exportorder_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
        echo "\n  cleaned up: dropped `{$database}`\n";
    } catch (Throwable $e) {
        echo "\n  WARNING: could not drop `{$database}`: {$e->getMessage()}\n";
    }
}

echo str_repeat('-', 78) . "\n";
echo $checks . ' checks, ' . count($failures) . " failure(s)\n";

exit($failures === [] ? 0 : 1);
