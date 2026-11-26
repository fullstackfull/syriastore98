#!/usr/bin/env php
<?php

/**
 * THE TWO REWRITTEN OPERATOR-DASHBOARD RANKINGS, ON A REAL MySQL-FAMILY SERVER WITH
 * `ONLY_FULL_GROUP_BY` ENABLED.
 * =================================================================================================
 *
 * WHY THIS CANNOT BE A TEST. The suite runs on SQLite `:memory:` (`phpunit.xml`), and SQLite has no
 * `ONLY_FULL_GROUP_BY` and does not reject a `GROUP BY` that selects a non-aggregated column. It
 * accepts both queries below whether or not they are legal on the server this ships to. CLAUDE.md
 * records that SQLite's permissiveness is how P01-SEC-13 survived; this is the same hazard in the
 * `GROUP BY` dimension.
 *
 * WHAT IS BEING CHECKED. `P06-DASH-CARDS` replaced two in-PHP rankings with database aggregation:
 *
 *   1. `HomeController::handleSuperAdmin()` -- `PlanCoupon::withCount('planUserCoupons')
 *      ->orderByDesc('plan_user_coupons_count')->orderBy('id')`. `withCount` emits a correlated
 *      subquery and then ORDERS BY ITS SELECT ALIAS, which is not something every server allows in
 *      every mode.
 *   2. `Plan::most_purchese_plan()` -- `selectRaw('plan_id, COUNT(*) as purchases, MIN(id) as
 *      first_order_id')->groupBy('plan_id')`. Under `ONLY_FULL_GROUP_BY` a selected column must be
 *      grouped or aggregated; `plan_id` is grouped and the other two are aggregates, so it should
 *      be legal -- but "should be" is the thing being tested.
 *
 * `ONLY_FULL_GROUP_BY` IS SET EXPLICITLY AND THAT IS THE POINT. MySQL 8 enables it by default;
 * MariaDB does not. A probe on a MariaDB default would pass without exercising the restriction and
 * would be worthless as evidence about production. The mode is set on the session and asserted
 * before any query runs.
 *
 * PRECONDITIONS, all checked below with a stated reason on failure:
 *   - a reachable MySQL-family server on 127.0.0.1:3306 with an account that may CREATE and DROP
 *     a database. Credentials come from `SS_PROBE_USER` / `SS_PROBE_PASS`, defaulting to
 *     `ssprobe`/`ssprobe`. NOT root: MariaDB's root authenticates over the unix socket, which PDO
 *     over TCP cannot use, so a root connection is refused with 1698 rather than working.
 *   - permission to CREATE and DROP a database. A throwaway database is created and dropped; no
 *     existing schema is read or touched.
 *
 * Usage:  php tests/Support/Security/bin/verify-dashboard-rankings-on-mysql.php
 * Exit:   0 = every check passed. 1 = a check failed, or a precondition was not met.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$failures = [];
$checks = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;
    if ($ok) {
        echo "  ok    {$what}\n";
        return;
    }
    echo "  FAIL  {$what}" . ($detail === '' ? '' : " -- {$detail}") . "\n";
    $failures[] = $what;
}

function fatal(string $why): never
{
    echo "\nPRECONDITION NOT MET: {$why}\n";
    exit(1);
}

$database = 'ss_probe_dash_' . bin2hex(random_bytes(4));

Config::set('cache.default', 'array');
Config::set('database.connections.probe', [
    'driver' => 'mysql',
    'host' => '127.0.0.1',
    'port' => 3306,
    'database' => null,
    'username' => getenv('SS_PROBE_USER') ?: 'ssprobe',
    'password' => getenv('SS_PROBE_PASS') ?: 'ssprobe',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'strict' => true,
]);

try {
    DB::connection('probe')->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4");
} catch (\Throwable $e) {
    fatal('could not create a throwaway database on 127.0.0.1:3306 as '
        . (getenv('SS_PROBE_USER') ?: 'ssprobe') . ' -- ' . $e->getMessage());
}

Config::set('database.connections.probe.database', $database);
DB::purge('probe');
Config::set('database.default', 'probe');

echo "Throwaway database: {$database}\n";

try {
    // MySQL 8's default; MariaDB's is not. Without this the probe does not exercise the
    // restriction it exists to exercise.
    DB::connection('probe')->statement(
        "SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'"
    );

    $mode = (string) DB::connection('probe')->selectOne('SELECT @@session.sql_mode AS m')->m;
    echo "Session sql_mode: {$mode}\n\n";

    check('ONLY_FULL_GROUP_BY is actually in force', str_contains($mode, 'ONLY_FULL_GROUP_BY'),
        'the probe would pass vacuously without it: ' . $mode);

    // The columns are the migrations' -- plan_coupons (name, code, discount, limit, description,
    // is_active), plan_user_coupons (user_id, coupon_id, `order`), and the plan_orders columns
    // `most_purchese_plan()` reads. `order` is reserved, hence the quoting Schema does for us.
    Schema::connection('probe')->create('plan_coupons', function ($t) {
        $t->id();
        $t->string('name', 100);
        $t->string('code', 100);
        $t->integer('discount');
        $t->string('limit')->nullable();
        $t->text('description')->nullable();
        $t->boolean('is_active')->default(1);
        $t->timestamps();
    });
    Schema::connection('probe')->create('plan_user_coupons', function ($t) {
        $t->id();
        $t->integer('user_id');
        $t->integer('coupon_id');
        $t->string('order');
        $t->timestamps();
    });
    Schema::connection('probe')->create('plans', function ($t) {
        $t->id();
        $t->string('name', 100)->unique();
        $t->float('price')->nullable();
        $t->text('description')->nullable();
        $t->string('duration')->nullable();
        $t->text('themes');
        $t->integer('max_stores')->default(0);
        $t->integer('max_products')->default(0);
        $t->integer('max_users')->default(0);
        $t->string('storage_limit')->nullable();
        $t->timestamps();
    });
    Schema::connection('probe')->create('plan_orders', function ($t) {
        $t->id();
        $t->string('order_id', 100)->unique();
        $t->string('plan_name', 100);
        $t->integer('plan_id');
        $t->float('price')->nullable();
        $t->string('price_currency', 10);
        $t->string('txn_id', 100);
        $t->string('payment_status', 100);
        $t->timestamps();
    });

    // ---- fixtures -------------------------------------------------------------------------
    // BUSY has 2 redemptions, TIE_A and TIE_B have 1 each (a tie on the lower id), QUIET has 0.
    DB::table('plan_coupons')->insert([
        ['id' => 1, 'name' => 'BUSY', 'code' => 'B', 'discount' => 5, 'limit' => '9'],
        ['id' => 2, 'name' => 'TIE_A', 'code' => 'A', 'discount' => 5, 'limit' => '9'],
        ['id' => 3, 'name' => 'TIE_B', 'code' => 'C', 'discount' => 5, 'limit' => '9'],
        ['id' => 4, 'name' => 'QUIET', 'code' => 'Q', 'discount' => 5, 'limit' => '9'],
    ]);
    DB::table('plan_user_coupons')->insert([
        ['coupon_id' => 1, 'user_id' => 1, 'order' => 'o1'],
        ['coupon_id' => 1, 'user_id' => 1, 'order' => 'o2'],
        ['coupon_id' => 2, 'user_id' => 1, 'order' => 'o3'],
        ['coupon_id' => 3, 'user_id' => 1, 'order' => 'o4'],
    ]);

    // SOLDFIRST and SOLDLATER each sell twice, interleaved, so the tie can only be resolved by
    // the earliest order id and not by plan id order.
    DB::table('plans')->insert([
        ['id' => 1, 'name' => 'SOLDFIRST', 'price' => 1, 'duration' => 'Lifetime', 'themes' => '[]'],
        ['id' => 2, 'name' => 'SOLDLATER', 'price' => 1, 'duration' => 'Lifetime', 'themes' => '[]'],
        ['id' => 3, 'name' => 'UNSOLD', 'price' => 1, 'duration' => 'Lifetime', 'themes' => '[]'],
    ]);
    foreach ([1, 2, 2, 1] as $i => $planId) {
        DB::table('plan_orders')->insert([
            'order_id' => 'PO' . $i, 'plan_name' => 'x', 'plan_id' => $planId, 'price' => 1,
            'price_currency' => 'USD', 'txn_id' => 't' . $i, 'payment_status' => 'succeeded',
        ]);
    }

    // ---- 1. the coupon ranking, exactly as handleSuperAdmin() runs it --------------------
    echo "\nTHE COUPON RANKING (GROUP BY on plan_user_coupons, ORDER BY an aggregate alias)\n";

    $ranked = null;
    $error = null;
    try {
        $ranked = \App\Models\PlanUserCoupon::query()
        ->selectRaw('coupon_id, COUNT(*) as redemptions')
        ->groupBy('coupon_id')
        ->orderByDesc('redemptions')
        ->orderBy('coupon_id')
        ->limit(1)
        ->toBase()
        ->first();
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }

    check('the query is accepted under ONLY_FULL_GROUP_BY', $error === null, (string) $error);
    check('the aggregate alias `redemptions` is populated',
        $ranked !== null && $ranked->redemptions !== null,
        'ORDER BY an aggregate alias did not survive, so the ranking has no count to order on');
    check('the most-redeemed coupon wins',
        \App\Models\PlanCoupon::find((int) ($ranked->coupon_id ?? 0))?->name === 'BUSY',
        'got coupon_id ' . var_export($ranked?->coupon_id, true));
    check('its count is the real one', (int) ($ranked->redemptions ?? -1) === 2,
        'got ' . var_export($ranked?->redemptions, true));

    /*
     * THE FLUSH IS LOAD-BEARING AND THE FIRST VERSION OF THIS SCRIPT DID NOT HAVE IT.
     *
     * `DB::table(...)->delete()` is a RAW write: it does not flush the model cache, so the
     * re-read below served the PRE-DELETE aggregate and two checks failed against correct code
     * -- reporting a tie-break and a zero-use defect that do not exist. Exactly CLAUDE.md's
     * recorded reading: "A raw write leaves Eloquent reads serving the pre-write row."
     *
     * IT ALSO FOUND A REAL DEFECT, in the first version of the fix rather than in this script.
     * That version was `PlanCoupon::withCount('planUserCoupons')`, whose aggregate caches under
     * `plan_coupons`; a redemption writes `plan_user_coupons` and does NOT invalidate it, so the
     * ranking kept naming the previous winner. The old `used_coupon()` was accidentally fresh
     * because its count was a query on `PlanUserCoupon` itself. The shipped ranking is therefore
     * grouped on `PlanUserCoupon` with `toBase()`, off the model cache entirely, and
     * `PlanCouponRankingFreshnessTest` pins it. The flushes stay here so these checks measure the
     * RANKING and not the cache.
     */
    (new \App\Models\PlanUserCoupon)->flushCache();
    (new \App\Models\PlanCoupon)->flushCache();

    // A tie goes to the lower id -- the original's `>` semantics. Measured by removing the
    // outright winner's rows so TIE_A and TIE_B are level at the top.
    DB::table('plan_user_coupons')->where('coupon_id', 1)->delete();
    (new \App\Models\PlanUserCoupon)->flushCache();
    (new \App\Models\PlanCoupon)->flushCache();
    $tie = \App\Models\PlanUserCoupon::query()
        ->selectRaw('coupon_id, COUNT(*) as redemptions')
        ->groupBy('coupon_id')
        ->orderByDesc('redemptions')
        ->orderBy('coupon_id')
        ->limit(1)
        ->toBase()
        ->first();
    check('a tie resolves to the lower coupon id',
        \App\Models\PlanCoupon::find((int) ($tie->coupon_id ?? 0))?->name === 'TIE_A',
        'got coupon_id ' . var_export($tie?->coupon_id, true));

    // Nobody redeemed anything: the handler must answer '' rather than naming a zero-use coupon.
    DB::table('plan_user_coupons')->delete();
    (new \App\Models\PlanUserCoupon)->flushCache();
    (new \App\Models\PlanCoupon)->flushCache();
    $none = \App\Models\PlanUserCoupon::query()
        ->selectRaw('coupon_id, COUNT(*) as redemptions')
        ->groupBy('coupon_id')
        ->orderByDesc('redemptions')
        ->orderBy('coupon_id')
        ->limit(1)
        ->toBase()
        ->first();
    $couponName = $none === null
        ? ''
        : (string) (\App\Models\PlanCoupon::find((int) $none->coupon_id)?->name ?? '');
    check('a coupon with zero redemptions is not named', $couponName === '',
        'got ' . var_export($couponName, true));

    // ---- 2. the plan ranking, through the real model method -----------------------------
    echo "\nTHE PLAN RANKING (GROUP BY with COUNT and MIN)\n";

    $name = null;
    $error = null;
    try {
        $name = \App\Models\Plan::most_purchese_plan();
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }

    check('the GROUP BY is accepted under ONLY_FULL_GROUP_BY', $error === null, (string) $error);
    check('a tie resolves to the plan sold first', $name === 'SOLDFIRST',
        'got ' . var_export($name, true));

    // An outright winner, so the tie-break is not what is carrying the previous check.
    DB::table('plan_orders')->insert([
        'order_id' => 'POX', 'plan_name' => 'x', 'plan_id' => 2, 'price' => 1,
        'price_currency' => 'USD', 'txn_id' => 'tx', 'payment_status' => 'succeeded',
    ]);
    check('the most-purchased plan wins outright', \App\Models\Plan::most_purchese_plan() === 'SOLDLATER',
        'got ' . var_export(\App\Models\Plan::most_purchese_plan(), true));

    DB::table('plan_orders')->delete();
    check('no plan orders answers an empty name', \App\Models\Plan::most_purchese_plan() === '',
        'got ' . var_export(\App\Models\Plan::most_purchese_plan(), true));

    // A plan row deleted after its orders: the `?->` must carry the null, not raise.
    DB::table('plan_orders')->insert([
        'order_id' => 'POY', 'plan_name' => 'gone', 'plan_id' => 999, 'price' => 1,
        'price_currency' => 'USD', 'txn_id' => 'ty', 'payment_status' => 'succeeded',
    ]);
    $orphan = null;
    $error = null;
    try {
        $orphan = \App\Models\Plan::most_purchese_plan();
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
    check('an order naming a deleted plan does not raise', $error === null, (string) $error);
    check('and answers an empty name', $orphan === '', 'got ' . var_export($orphan, true));
} finally {
    DB::purge('probe');
    Config::set('database.connections.probe.database', null);
    DB::connection('probe')->statement("DROP DATABASE IF EXISTS `{$database}`");
    echo "\nDropped {$database}\n";
}

echo "\n" . str_repeat('-', 60) . "\n";
printf("%d checks, %d failed\n", $checks, count($failures));
foreach ($failures as $f) {
    echo "  - {$f}\n";
}

exit($failures === [] ? 0 : 1);
