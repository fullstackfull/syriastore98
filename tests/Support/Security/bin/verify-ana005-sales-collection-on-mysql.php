#!/usr/bin/env php
<?php

/**
 * ANA-005 -- `Payments received` ON THE SALES REPORT, DRIVEN ON A REAL MySQL.
 * =================================================================================================
 *
 * WHY THIS IS NOT A FEATURE TEST. The two handlers that render `reports/_sales_stats` are
 * `ReportController::order_reports_chart` (five `MONTH(`, one `YEAR(`) and
 * `order_reports_chart_data` (twelve and four). SQLite has neither function, so a Feature request to
 * either answers HTTP 500 before any assertion about a tile can be made -- which is exactly how
 * 3,660 lines of report code with no payment-status predicates survived 9,112 tests. The suite
 * covers the ASSEMBLER (`SalesReportCollectionFigureTest`) and the PARTIAL and the handlers' source
 * (`SalesStatsPassesItsCollectionFigureTest`); the one link neither can reach is the handler
 * actually running its own SQL and rendering the tile. That is what this script executes.
 *
 * WHAT IS CHECKED, against a seeded two-tenant ledger:
 *
 *   1. the premise: SQLite really lacks MONTH(), so this script is not redundant
 *   2. `order_reports_chart` returns HTTP 200 and its HTML carries the `Payments received` tile
 *   3. the figure is the COLLECTED amount for the chosen period, not order value and not all time
 *   4. the other tenant's collection never appears
 *   5. the tile carries its own period, and when the ledger cannot vouch for the whole of the
 *      requested window the period names the narrower span rather than the requested one
 *   6. a window entirely before the ledger is REFUSED with a reason -- never rendered as 0.00
 *   7. `order_reports_chart_data`, the second handler sharing the same partial, does all of this too
 *
 * PRECONDITIONS, stated so a failure is not mistaken for a defect:
 *   - a reachable MySQL/MariaDB with an account that may CREATE and DROP a database;
 *   - credentials from FRESH_MYSQL_{HOST,PORT,SOCKET,USERNAME,PASSWORD}, defaulting to root on
 *     127.0.0.1:3306 with no password and a discovered socket.
 *
 * WHAT THIS DOES NOT PROVE, stated so it is not cited for more than it shows:
 *   * NOTHING ABOUT `ReportPermission`. It calls `(new ReportController())->{$handler}($request)`
 *     directly, so the controller's constructor middleware never runs. The permission gate is
 *     covered by `ReportAuthorizationTest`, not here.
 *   * nothing about the operator limb in `salesCollectionTile()`: the fixture owner is type
 *     `admin` with `current_store` set, so the `super admin` branch is never driven. That limb is
 *     covered by `SalesReportCollectionFigureTest`.
 *
 * It creates and DROPS its own throwaway database, refuses any database name that does not say
 * `verify`, and must never be pointed at the development or production server.
 *
 * Exit 0 = every check held. Exit 1 = a check failed. Exit 2 = a precondition was not met.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
/*
 * THE SESSION DRIVER IS SET BEFORE THE FRAMEWORK BOOTS, AND THAT ORDER IS THE WHOLE FIX.
 * `config/session.php` reads `env('SESSION_DRIVER', 'database')` while the config is loaded, so a
 * `config([...])` call AFTER `bootstrap()` is too late -- measured: it changed nothing and the
 * script still exited 1.
 */
putenv('SESSION_DRIVER=array');
$_ENV['SESSION_DRIVER'] = 'array';
$_SERVER['SESSION_DRIVER'] = 'array';

$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

/*
 * THE SESSION STORE IS FORCED TO `array`, AND THAT IS A DEFECT REPAIR RATHER THAN A SETTING.
 *
 * MEASURED 2026-11-26, while executing the whole Phase 05 real-MySQL evidence set for the P05-24
 * report: this script exited 1 -- "a check FAILED" -- with
 *
 *     the script threw: Illuminate\Database\QueryException -- Database file at path
 *     [.../database/database.sqlite] does not exist (Connection: sqlite,
 *     SQL: delete from "sessions" where "id" = ...)
 *
 * `config/session.php` defaults to the `database` driver, so booting the framework in an
 * environment with no `.env` lets the DEFAULT connection -- still sqlite -- touch the session
 * table before this script points anything at MySQL. The sibling
 * `verify-analytics-breakdowns-on-mysql.php` documents the remedy in its USAGE line
 * (`SESSION_DRIVER=array`), and with it this script is 22 checks, 0 failed.
 *
 * SO THE PRODUCT WAS SOUND AND THE SCRIPT WAS WRONG ABOUT ITS OWN RESULT. Its stated exit contract
 * reserves 2 for "a precondition was not met"; reporting an absent sqlite file as a failed CHECK
 * tells a reader of the Phase 05 evidence that a money figure is broken when nothing is. An
 * evidence script that cries wolf makes the whole evidence set less trustworthy, which is the
 * reporting-honesty failure P05-24 exists to close.
 *
 * It is forced here rather than documented because a precondition nobody can forget is better than
 * one they must remember: the session store is irrelevant to everything this script measures (it
 * drives HTTP requests and reads money figures), and `array` is what the whole suite runs on.
 */

use App\Http\Controllers\ReportController;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$database = getenv('FRESH_MYSQL_DATABASE') ?: ('ana005_collection_verify_' . getmypid());

if (! preg_match('/verify/i', $database)) {
    fwrite(STDERR, "Refusing a database whose name does not say it is throwaway: {$database}\n");
    exit(2);
}

$socket = getenv('FRESH_MYSQL_SOCKET');
if ($socket === false) {
    foreach (['/run/mysqld/mysqld.sock', '/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock'] as $candidate) {
        if (file_exists($candidate)) { $socket = $candidate; break; }
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

config(['database.connections.ana005_bootstrap' => $bootstrap]);
config(['database.connections.ana005_fresh' => $connection]);

$checks = 0;
$failures = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $checks, $failures;
    $checks++;
    if ($ok) { echo "  ok    {$what}\n"; return; }
    $failures++;
    echo "  FAIL  {$what}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
}

/** The isolates `FinanceLedgerFigures` wraps every amount in, so values compare as people read them. */
function plain(string $html): string
{
    return str_replace(["\u{2066}", "\u{2069}"], '', $html);
}

/**
 * The `Payments received` tile out of a rendered card, by its LABEL.
 *
 * Read off the tile that carries the label and never off the whole document: P5-10 had a test that
 * passed because `1,200.00` in an adjacent tile contained the string `200.00`.
 *
 * @return array{value: string, period: string, hint: string}|null
 */
function paymentsTile(string $html): ?array
{
    $html = plain($html);

    preg_match_all(
        '/<span class="ss-stat__label">\s*(.*?)\s*<\/span>\s*'
        . '<span class="ss-stat__value"[^>]*>\s*(.*?)\s*<\/span>\s*'
        . '<span class="ss-stat__period">\s*(.*?)\s*<\/span>'
        . '(?:\s*<p class="ss-stat__hint"[^>]*>\s*(.*?)\s*<\/p>)?/s',
        $html,
        $matches,
        PREG_SET_ORDER
    );

    foreach ($matches as $tile) {
        $label = trim(preg_replace('/\s+/', ' ', strip_tags($tile[1])));

        if ($label === 'Payments received') {
            return [
                'value' => trim(preg_replace('/\s+/', ' ', strip_tags($tile[2]))),
                'period' => trim(preg_replace('/\s+/', ' ', strip_tags($tile[3]))),
                'hint' => trim(preg_replace('/\s+/', ' ', strip_tags($tile[4] ?? ''))),
            ];
        }
    }

    return null;
}

try {
    DB::connection('ana005_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
    DB::connection('ana005_bootstrap')->statement(
        "CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
} catch (\Throwable $e) {
    fwrite(STDERR, "PRECONDITION NOT MET: cannot create a throwaway database -- {$e->getMessage()}\n");
    exit(2);
}

$exit = 0;

try {
    config(['database.default' => 'ana005_fresh']);
    DB::purge('ana005_fresh');

    echo "ANA-005 -- Payments received on the sales report\n";
    echo "===============================================\n";
    echo "Server:   " . DB::connection('ana005_fresh')->selectOne('SELECT VERSION() v')->v . "\n";
    echo "Database: {$database}\n\n";

    echo "Running migrations...\n";
    Artisan::call('migrate', ['--database' => 'ana005_fresh', '--force' => true]);
    echo "  done\n\n";

    // ------------------------------------------------------------------ 1. the premise

    echo "1. Why this cannot be a Feature test\n";

    $sqlite = new PDO('sqlite::memory:');
    $monthMissing = false;
    try {
        $sqlite->query("SELECT MONTH('2026-11-30')");
    } catch (\Throwable $e) {
        $monthMissing = str_contains($e->getMessage(), 'no such function');
    }
    check('SQLite has no MONTH(), so both handlers answer 500 under the suite', $monthMissing);

    /* COMMENTS STRIPPED BEFORE COUNTING. CLAUDE.md records four occasions in one day where a census
       that reads comments was satisfied -- or broken -- by prose ABOUT the thing it measures, and this
       file's own docblock contains the literal `MONTH(`. `token_get_all` is used rather than a regex
       because a block comment opener inside a string literal would defeat one -- and writing that
       delimiter out in full HERE terminated this very comment on the first attempt, which is the
       same nesting trap in its smallest form. */
    $code = file_get_contents(base_path('app/Http/Controllers/ReportController.php'));
    $codeOnly = '';
    foreach (token_get_all($code) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $codeOnly .= is_array($token) ? $token[1] : $token;
    }

    $monthInCode = preg_match_all('/[^A-Z_]MONTH\(/', $codeOnly);
    $monthInProse = preg_match_all('/[^A-Z_]MONTH\(/', $code) - $monthInCode;

    check(
        "ReportController still calls MONTH() in CODE ({$monthInCode} sites; {$monthInProse} more occur only in comments and are not counted)",
        $monthInCode > 0
    );

    // ------------------------------------------------------------------ fixture

    echo "\n2. Fixture: two tenants, one ledger each\n";

    $owner = new User();
    $owner->name = 'ANA-005 owner';
    $owner->email = 'ana005-owner@example.test';
    $owner->password = bcrypt('x');
    $owner->type = 'admin';
    $owner->save();

    foreach ([
        [1, 'Decoy store', 'decoy-store', 'decoy@example.test'],
        [2, 'Store under test', 'store-under-test', 'under-test@example.test'],
    ] as [$id, $name, $slug, $email]) {
        DB::table('stores')->insert([
            'id' => $id, 'name' => $name, 'slug' => $slug, 'email' => $email,
            'theme_id' => 'stylique', 'created_by' => $owner->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('settings')->insert([
            'store_id' => $id, 'name' => 'defult_currancy', 'value' => 'USD',
            'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // THE TENANT UNDER TEST IS STORE 2. Store 1 is the id getCurrentStore() falls back to and the one
    // a careless forStore(1, ...) would hard-code, so its figures must never appear.
    $owner->current_store = 2;
    $owner->save();

    $seq = 0;

    /** An order WITH a ledger row, created through Eloquent so OrderObserver writes one. */
    $ledgerOrder = function (int $storeId, Carbon $placed, float $amount, ?Carbon $paidOn, float $paid) use (&$seq): int {
        $seq++;

        $order = Order::create([
            'product_order_id' => 'ANA005-' . $seq,
            'product_json' => '[]',
            'product_price' => $amount, 'delivery_price' => 0.0, 'tax_price' => 0.0,
            'coupon_price' => 0.0, 'final_price' => $amount,
            'customer_id' => 0, 'is_guest' => 1,
            'payment_type' => 'cod', 'payment_status' => 'Paid', 'delivered_status' => 1,
            'store_id' => $storeId,
            'order_date' => $placed->format('Y-m-d H:i:s'),
        ]);

        $updated = DB::table('store_order_financials')
            ->where('order_id', $order->id)
            ->where('store_id', $storeId)
            ->update([
                'payment_status' => 'paid',
                'paid_amount' => $paid,
                'paid_at' => $paidOn?->format('Y-m-d H:i:s'),
                'currency_code' => 'USD',
                'product_cost' => 0.0,
                'gross_profit' => $paid,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new RuntimeException("OrderObserver wrote no ledger row for order {$order->id}; the fixture measures nothing");
        }

        return $order->id;
    };

    /** An order with NO ledger row, which is what forces a coverage start. */
    $preLedgerOrder = function (int $storeId, Carbon $placed, float $amount) use (&$seq): void {
        $seq++;

        $order = Order::create([
            'product_order_id' => 'ANA005-PRE-' . $seq,
            'product_json' => '[]',
            'product_price' => $amount, 'delivery_price' => 0.0, 'tax_price' => 0.0,
            'coupon_price' => 0.0, 'final_price' => $amount,
            'customer_id' => 0, 'is_guest' => 1,
            'payment_type' => 'cod', 'payment_status' => 'Unpaid', 'delivered_status' => 0,
            'store_id' => $storeId,
            'order_date' => $placed->format('Y-m-d H:i:s'),
        ]);

        DB::table('store_order_financials')->where('order_id', $order->id)->delete();
    };

    $monthStart = Carbon::now()->startOfMonth();
    $today = Carbon::now();

    // Store 2, THIS MONTH: 140.00 collected inside the month.
    $ledgerOrder(2, $monthStart->copy()->addDays(2), 500.00, $monthStart->copy()->addDays(3), 140.00);

    // Store 2, LAST MONTH: 999.00 collected, which must NOT be in this month's figure.
    $ledgerOrder(2, Carbon::now()->subMonthNoOverflow()->startOfMonth()->addDays(5), 999.00,
        Carbon::now()->subMonthNoOverflow()->startOfMonth()->addDays(6), 999.00);

    // The DECOY. Store 1's collection, inside this month, which must never appear on store 2's card.
    $ledgerOrder(1, $monthStart->copy()->addDays(2), 31337.31, $monthStart->copy()->addDays(3), 31337.31);

    echo "   store 2 collected this month : 140.00\n";
    echo "   store 2 collected last month : 999.00\n";
    echo "   store 1 (decoy) this month   : 31337.31\n";

    // ------------------------------------------------------------------ 3. the handler

    $render = function (string $handler, array $query) use ($owner): array {
        Auth::login($owner);

        $request = Request::create('/order-reports-chart', 'GET', $query);
        app()->instance('request', $request);

        $response = (new ReportController())->{$handler}($request);
        $payload = json_decode($response->getContent(), true);

        return [
            'status' => $response->getStatusCode(),
            'html' => is_array($payload) ? (string) ($payload['html'] ?? '') : '',
        ];
    };

    foreach (['order_reports_chart', 'order_reports_chart_data'] as $handler) {
        echo "\n3. {$handler} -- this month\n";

        $answer = $render($handler, ['chart_data' => 'this-month']);

        check("{$handler} answered HTTP 200 on MySQL", $answer['status'] === 200, "status {$answer['status']}");

        $tile = paymentsTile($answer['html']);

        if ($tile === null) {
            check("{$handler} rendered a Payments received tile", false, 'no tile with that label in the HTML');
            continue;
        }

        check("{$handler} rendered a Payments received tile", true);
        check(
            "the figure is this month's collection (140.00 USD), not order value and not all time",
            $tile['value'] === '140.00 USD',
            "got '{$tile['value']}'"
        );
        check(
            "last month's 999.00 is not in it",
            ! str_contains($tile['value'], '999'),
            "got '{$tile['value']}'"
        );
        check(
            "the decoy tenant's 31,337.31 is nowhere on the card",
            ! str_contains(plain($answer['html']), '31,337.31')
        );
        check(
            'the tile states a period of its own',
            $tile['period'] !== '',
            "got '{$tile['period']}'"
        );
        check(
            'the tile states what the figure is and is not',
            str_contains($tile['hint'], 'not order value'),
            "got '" . substr($tile['hint'], 0, 90) . "'"
        );
    }

    // ------------------------------------------------------------------ 4. the clamp

    echo "\n4. A window the ledger cannot vouch for the whole of\n";

    /* 64.00 collected TODAY, which is inside the span the ledger will still be able to vouch for,
       and 140.00 collected earlier this month, which is not. An unrowed order YESTERDAY then moves
       the verified start to today, so the requested month can no longer be answered in full.

       THE ZERO THIS SCENARIO ORIGINALLY MEASURED WAS CORRECT, and the assertion was wrong. With no
       payment inside the clamped span the honest answer IS 0.00 -- over the NARROWER period, which
       the tile names. D1 Option A forbids printing a zero for a period that was not read, not
       printing a true zero for one that was. So the scenario now seeds money inside the clamp and
       checks the figure is that money: a tile that ignored the clamp would read 204.00, one that
       dropped the window entirely would read 1,203.00, and one that clamped correctly reads 64.00. */
    $ledgerOrder(2, $today->copy(), 300.00, $today->copy(), 64.00);
    $preLedgerOrder(2, $today->copy()->subDay(), 10.00);

    $answer = $render('order_reports_chart', ['chart_data' => 'this-month']);
    $tile = paymentsTile($answer['html']);

    if ($tile === null) {
        check('the clamped case still rendered a tile', false, 'no tile at all');
    } else {
        $requestedLabel = 'This month (' . Carbon::now()->format('F Y') . ')';
        $clampedFrom = $today->copy()->locale('en')->translatedFormat('j F Y');

        check(
            'the tile is NOT labelled with the requested month it cannot cover',
            $tile['period'] !== $requestedLabel,
            "got '{$tile['period']}'"
        );
        check(
            'the tile names the clamped start, so the figure is attributable',
            str_contains($tile['period'], $clampedFrom),
            "expected '{$clampedFrom}' in '{$tile['period']}'"
        );
        check(
            'the figure is the money inside the clamped span only (64.00 USD)',
            $tile['value'] === '64.00 USD',
            "got '{$tile['value']}'"
        );
        check(
            "the 140.00 collected before the clamp is excluded",
            ! str_contains($tile['value'], '204') && ! str_contains($tile['value'], '140'),
            "got '{$tile['value']}'"
        );

        echo "   period: {$tile['period']}\n";
        echo "   value : {$tile['value']}\n";
        echo "   hint  : " . substr($tile['hint'], 0, 120) . "\n";
    }

    // ------------------------------------------------------------------ 5. no overlap at all

    echo "\n5. A window entirely before the ledger\n";

    $longAgo = Carbon::now()->subYears(3);
    $answer = $render('order_reports_chart', [
        'Date' => $longAgo->copy()->startOfMonth()->toDateString() . ' to ' . $longAgo->copy()->endOfMonth()->toDateString(),
    ]);
    $tile = paymentsTile($answer['html']);

    if ($tile === null) {
        check('a window before the ledger still rendered a tile rather than vanishing', false, 'no tile');
    } else {
        /* ONE LIMB, NOT TWO. The first draft also accepted `=== ''`, which `<x-ss.stat>` never
           renders -- a null value becomes the translated "Not available" string. A disjunction with
           an unreachable limb is an assertion that would also pass for a tile that failed to render
           at all, which is the opposite of what this checks. */
        check(
            'it is refused, not answered with 0.00',
            $tile['value'] === 'Not available',
            "got '{$tile['value']}'"
        );
        check(
            'the refusal says when the records begin',
            str_contains($tile['hint'], 'begin') || str_contains($tile['hint'], 'financial record'),
            "got '" . substr($tile['hint'], 0, 120) . "'"
        );

        echo "   value : {$tile['value']}\n";
        echo "   hint  : " . substr($tile['hint'], 0, 160) . "\n";
    }
} catch (\Throwable $e) {
    $failures++;
    echo "\n  FAIL  the script threw: " . get_class($e) . ' -- ' . $e->getMessage() . "\n";
    echo '        ' . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    try {
        DB::connection('ana005_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
        echo "\nthrowaway database dropped\n";
    } catch (\Throwable $e) {
        echo "\nWARNING: could not drop {$database} -- {$e->getMessage()}\n";
    }
}

echo "\n{$checks} checks, {$failures} failed\n";

exit($failures > 0 ? 1 : 0);
