#!/usr/bin/env php
<?php

/**
 * WHAT THE OPERATOR'S `Popular Coupon` CARD NAMES UNDER EACH CANDIDATE RULE, ON BOTH ENGINES.
 * =================================================================================================
 *
 * THIS SCRIPT DECIDES NOTHING. It exists so that an owner question about a reported figure can be
 * answered from numbers rather than from an argument. `GAP-231` narrowed the ranking behind that
 * card to settled purchases only; whether that is the metric's approved meaning is not a question
 * engineering can read out of this repository (see `docs/development/phase-6/
 * PHASE-06-POPULAR-COUPON-METRIC-RECONCILIATION.md`). So all three candidate rules are run over
 * ONE fixture and their answers printed side by side.
 *
 * THE THREE RULES
 * -------------------------------------------------------------------------------------------------
 *
 *   A — AS SHIPPED BEFORE `GAP-231`: every `plan_user_coupons` row counts.
 *       `GROUP BY coupon_id ORDER BY COUNT(*) DESC, coupon_id`.
 *
 *   B — AS SHIPPED TODAY: only a redemption whose `order` string matches a `plan_orders.order_id`
 *       whose `payment_status` is settled. The settled spellings come from
 *       `App\Support\Operator\SettledPlanOrders::spellingsInUse()`, which reads
 *       `DISTINCT payment_status` and classifies each value in PHP, so it is engine-independent by
 *       construction. This script calls that method rather than restating its answer.
 *
 *   C — THE NAIVE PREDICATE NOBODY SHIPPED, run only to measure the collation claim that rules it
 *       out: `payment_status IN ('success','succeeded','approved')`, i.e.
 *       `App\Services\SaasRevenueFigures::SETTLED_PLAN_STATUSES` used directly in SQL.
 *
 * WHY IT IS A SCRIPT AND NOT A TEST, for two independent reasons.
 *
 *   1. Rule C's whole point is that it answers DIFFERENTLY on the two engines, and the suite has
 *      only one of them. A conditionally-skipped test would also break the declared outcome set
 *      (`.github/known-test-outcomes.json`), which CLAUDE.md forbids.
 *   2. A test asserting what any one rule yields would pin the very definition that is being put to
 *      the owner. Pinning it is the thing this workstream was told not to do.
 *
 * PRECONDITIONS, stated so a failure is never mistaken for a finding:
 *
 *   - a reachable MySQL-family server on 127.0.0.1:3306 reachable over TCP, with an account that
 *     may CREATE and DROP a database. Credentials come from `SS_PROBE_USER` / `SS_PROBE_PASS`,
 *     defaulting to `ssprobe`/`ssprobe`. NOT root: MariaDB's root authenticates over the unix
 *     socket, which PDO over TCP cannot use, so a root connection is refused with 1698 rather than
 *     working. (Same precondition, same reason, as
 *     `verify-dashboard-rankings-on-mysql.php` beside it.)
 *   - the connection is opened with the collation `config/database.php` declares for production,
 *     `utf8mb4_unicode_ci`, and the script asserts that before measuring anything. A probe on a
 *     different collation would say nothing about production.
 *
 * It creates its own throwaway database and drops it again, and reads no existing schema. The
 * SQLite half is `:memory:` and touches no file.
 *
 * Usage:  php tests/Support/Security/bin/measure-popular-coupon-ranking-rules.php
 * Exit:   0 = every check passed. 1 = a check failed, or a precondition was not met.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\PlanOrder;
use App\Models\PlanUserCoupon;
use App\Services\SaasRevenueFigures;
use App\Support\Operator\SettledPlanOrders;
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

/*
 * THE FIXTURE. Five coupons, 30 redemptions, one plan.
 *
 * The spellings are chosen so that the engine-dependence of rule C is exercised rather than
 * asserted: two coupons' settled orders are recorded in the CAPITALISED spellings the application
 * really writes, which `SaasRevenueFigures::SETTLED_PLAN_STATUSES` does not contain as stored.
 * `'Approved'` is what the operator's own bank-transfer approval writes.
 *
 * `order_id` is UNIQUE on `plan_orders`, so one order carries at most one redemption here; the
 * dangling coupon's redemptions deliberately name no order row at all.
 *
 * @var array<string, array{settled: array<string, int>, unsettled: array<string, int>, dangling: int}>
 */
$fixture = [
    // Twelve redemptions, three of them paid. The old rule's winner.
    'LAUNCH20' => ['settled' => ['success' => 3], 'unsettled' => ['pending' => 5, 'failed' => 4], 'dangling' => 0],
    // Five redemptions, all paid. The shipped rule's winner.
    'LOYAL10' => ['settled' => ['succeeded' => 5], 'unsettled' => [], 'dangling' => 0],
    // Four redemptions, all paid, all recorded with the operator's own capitalised spelling.
    'BANKWIRE' => ['settled' => ['Approved' => 4], 'unsettled' => [], 'dangling' => 0],
    // Two redemptions, both paid, capitalised the other way.
    'MIXEDCASE' => ['settled' => ['Succeeded' => 2], 'unsettled' => [], 'dangling' => 0],
    // Seven redemptions naming orders that do not exist.
    'GHOST5' => ['settled' => [], 'unsettled' => [], 'dangling' => 7],
];

$connection = null;

/** Build the three tables and the fixture on whichever connection is named. */
$build = function (string $connection) use ($fixture): void {
    $schema = Schema::connection($connection);

    $schema->dropIfExists('plan_user_coupons');
    $schema->dropIfExists('plan_orders');
    $schema->dropIfExists('plan_coupons');

    /*
     * THE COLUMNS ARE THE MIGRATIONS', not a convenient subset: `plan_coupons` is
     * (name, code, discount, limit, description, is_active); `plan_user_coupons` is
     * (user_id, coupon_id, `order`) with `order` a NOT NULL STRING and no foreign key to
     * `plan_orders`; `plan_orders.order_id` is a UNIQUE string. The `users` foreign keys the two
     * migrations declare are omitted, because no `users` table is created here and the join this
     * measures does not involve one.
     */
    $schema->create('plan_coupons', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('code');
        $table->float('discount')->default(0);
        $table->string('limit')->default('0');
        $table->integer('is_active')->default(1);
        $table->timestamps();
    });

    $schema->create('plan_orders', function ($table) {
        $table->id();
        $table->string('order_id', 100)->unique();
        $table->string('plan_name', 100);
        $table->integer('plan_id');
        $table->float('price')->nullable();
        $table->string('price_currency', 10);
        $table->string('txn_id', 100);
        $table->string('payment_status', 100);
        $table->unsignedBigInteger('user_id')->default(0);
        $table->unsignedBigInteger('store_id')->nullable()->index();
        $table->timestamps();
    });

    $schema->create('plan_user_coupons', function ($table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('coupon_id');
        $table->string('order');
        $table->timestamps();
    });

    $now = date('Y-m-d H:i:s');
    $orderSeq = 0;

    foreach ($fixture as $name => $plan) {
        $couponId = DB::connection($connection)->table('plan_coupons')->insertGetId([
            'name' => $name, 'code' => substr($name, 0, 6), 'discount' => 10,
            'limit' => '999', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $write = function (string $status, int $times) use (
            &$orderSeq, $connection, $couponId, $now
        ): void {
            for ($i = 0; $i < $times; $i++) {
                $orderId = 'PO-' . str_pad((string) ++$orderSeq, 5, '0', STR_PAD_LEFT);

                DB::connection($connection)->table('plan_orders')->insert([
                    'order_id' => $orderId, 'plan_name' => 'Growth', 'plan_id' => 1,
                    'price' => 49, 'price_currency' => 'USD', 'txn_id' => 'TX-' . $orderId,
                    'payment_status' => $status, 'user_id' => 1, 'store_id' => 1,
                    'created_at' => $now, 'updated_at' => $now,
                ]);

                DB::connection($connection)->table('plan_user_coupons')->insert([
                    'user_id' => 1, 'coupon_id' => $couponId, 'order' => $orderId,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        };

        foreach ($plan['settled'] as $status => $times) {
            $write($status, $times);
        }
        foreach ($plan['unsettled'] as $status => $times) {
            $write($status, $times);
        }

        for ($i = 0; $i < $plan['dangling']; $i++) {
            DB::connection($connection)->table('plan_user_coupons')->insert([
                'user_id' => 1, 'coupon_id' => $couponId,
                'order' => 'NO-SUCH-ORDER-' . $couponId . '-' . $i,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
};

/**
 * The three rankings on one connection, as `[rule => [couponName => redemptions]]`, highest first.
 *
 * RULE B IS THE SHIPPED QUERY, not a copy of it: the `whereExists` closure is
 * `SettledPlanOrders::redeemedAgainstSettledOrder()`, the same callable
 * `HomeController::handleSuperAdmin()` passes, so a change to the predicate changes this
 * measurement too. `PlanUserCoupon` resolves the DEFAULT connection, which is why the caller sets
 * it rather than passing a connection name here -- and why the model cache is flushed first: the
 * two engines hold different rows under the same cache tag.
 *
 * @return array<string, array<string, int>>
 */
$measure = function (string $connection) use ($fixture): array {
    Config::set('database.default', $connection);
    (new PlanUserCoupon)->flushCache();
    (new PlanOrder)->flushCache();

    $names = DB::connection($connection)->table('plan_coupons')->pluck('name', 'id')->all();

    $rank = function ($query) use ($names): array {
        $rows = $query
            ->selectRaw('coupon_id, COUNT(*) as redemptions')
            ->groupBy('coupon_id')
            ->orderByDesc('redemptions')
            ->orderBy('coupon_id')
            ->toBase()
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) ($names[(int) $row->coupon_id] ?? '?')] = (int) $row->redemptions;
        }

        return $out;
    };

    $ruleA = $rank(PlanUserCoupon::query());

    $ruleB = $rank(PlanUserCoupon::query()->whereExists(
        fn ($query) => SettledPlanOrders::redeemedAgainstSettledOrder($query)
    ));

    $ruleC = $rank(PlanUserCoupon::query()->whereExists(
        fn ($query) => $query
            ->from('plan_orders')
            ->whereColumn('plan_orders.order_id', 'plan_user_coupons.order')
            ->whereIn('plan_orders.payment_status', SaasRevenueFigures::SETTLED_PLAN_STATUSES)
    ));

    return [
        'A old (every redemption)' => $ruleA,
        'B shipped (settled only)' => $ruleB,
        'C naive lower-cased IN()' => $ruleC,
        'spellings' => array_fill_keys(SettledPlanOrders::spellingsInUse(), 1),
    ];
};

$report = function (string $engine, array $measured) use ($fixture): void {
    echo "\n  {$engine}\n";
    echo '  ' . str_repeat('-', 76) . "\n";
    printf("  %-26s %s\n", 'rule', 'ranking, highest first');
    foreach ($measured as $rule => $ranking) {
        if ($rule === 'spellings') {
            continue;
        }
        $parts = [];
        foreach ($ranking as $name => $count) {
            $parts[] = "{$name}={$count}";
        }
        printf("  %-26s %s\n", $rule, $parts === [] ? '(nothing ranks)' : implode('  ', $parts));
    }
    printf("  %-26s %s\n", 'winner, rule A', array_key_first($measured['A old (every redemption)']) ?? '(none)');
    printf("  %-26s %s\n", 'winner, rule B', array_key_first($measured['B shipped (settled only)']) ?? '(none)');
    printf("  %-26s %s\n", 'winner, rule C', array_key_first($measured['C naive lower-cased IN()']) ?? '(none)');
    printf("  %-26s %s\n", 'settled spellings read', implode(', ', array_keys($measured['spellings'])));
};

// ----------------------------------------------------------------------------------------------
//  MySQL / MariaDB
// ----------------------------------------------------------------------------------------------

$database = 'ss_probe_coupon_' . bin2hex(random_bytes(4));
$user = getenv('SS_PROBE_USER') ?: 'ssprobe';
$pass = getenv('SS_PROBE_PASS') ?: 'ssprobe';

Config::set('cache.default', 'array');
Config::set('database.connections.probe', [
    'driver' => 'mysql',
    'host' => '127.0.0.1',
    'port' => 3306,
    'database' => null,
    'username' => $user,
    'password' => $pass,
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'strict' => true,
    'engine' => null,
]);

try {
    DB::connection('probe')->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (\Throwable $e) {
    fatal('cannot create a throwaway database as ' . $user . ': ' . $e->getMessage());
}

Config::set('database.connections.probe.database', $database);
DB::purge('probe');

$originalDefault = config('database.default');

$cleanup = function () use ($database, $originalDefault): void {
    Config::set('database.default', $originalDefault);
    try {
        Config::set('database.connections.probe.database', null);
        DB::purge('probe');
        DB::connection('probe')->statement("DROP DATABASE IF EXISTS `{$database}`");
    } catch (\Throwable $e) {
        echo "  (could not drop {$database}: {$e->getMessage()})\n";
    }
};

try {
    echo "\nPREMISES\n";

    $collation = (string) DB::connection('probe')->selectOne('SELECT @@collation_database AS c')->c;
    check('the probe database carries the collation config/database.php declares',
        $collation === 'utf8mb4_unicode_ci', "got {$collation}");

    check('`App\Support\Operator\SettledPlanOrders::isSettled()` classifies the capitalised spellings as settled',
        SettledPlanOrders::isSettled('Approved') && SettledPlanOrders::isSettled('Succeeded'));

    check('and refuses the three unsettled spellings in the fixture',
        ! SettledPlanOrders::isSettled('pending')
        && ! SettledPlanOrders::isSettled('failed')
        && ! SettledPlanOrders::isSettled('rejected'));

    check('the lower-cased constant does NOT literally contain the capitalised spellings',
        ! in_array('Approved', SaasRevenueFigures::SETTLED_PLAN_STATUSES, true)
        && ! in_array('Succeeded', SaasRevenueFigures::SETTLED_PLAN_STATUSES, true),
        'if it did, rule C would be rule B and this script would measure nothing');

    $build('probe');

    $redemptions = (int) DB::connection('probe')->table('plan_user_coupons')->count();
    check('the fixture wrote 30 redemptions', $redemptions === 30, "got {$redemptions}");

    $mysql = $measure('probe');
    $report('MariaDB/MySQL, utf8mb4_unicode_ci', $mysql);

    // ------------------------------------------------------------------------------------------
    //  SQLite :memory: -- the engine the test suite runs on
    // ------------------------------------------------------------------------------------------

    Config::set('database.connections.probe_sqlite', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    $build('probe_sqlite');
    $sqlite = $measure('probe_sqlite');
    $report('SQLite :memory: (the suite engine)', $sqlite);

    echo "\nFINDINGS\n";

    check('RULE A AND RULE B NAME DIFFERENT COUPONS -- the card\'s value changes',
        array_key_first($mysql['A old (every redemption)']) === 'LAUNCH20'
        && array_key_first($mysql['B shipped (settled only)']) === 'LOYAL10',
        'A=' . array_key_first($mysql['A old (every redemption)'])
        . ' B=' . array_key_first($mysql['B shipped (settled only)']));

    check('the leader\'s own number falls from 12 to 5',
        ($mysql['A old (every redemption)']['LAUNCH20'] ?? null) === 12
        && ($mysql['B shipped (settled only)']['LOYAL10'] ?? null) === 5);

    check('rule B drops GHOST5 from the ranking entirely (7 redemptions, no order row)',
        ($mysql['A old (every redemption)']['GHOST5'] ?? null) === 7
        && ! array_key_exists('GHOST5', $mysql['B shipped (settled only)']));

    check('RULE B IS ENGINE-INDEPENDENT: identical per-coupon numbers on both engines',
        $mysql['B shipped (settled only)'] === $sqlite['B shipped (settled only)'],
        'mysql=' . json_encode($mysql['B shipped (settled only)'])
        . ' sqlite=' . json_encode($sqlite['B shipped (settled only)']));

    check('rule A is engine-independent too',
        $mysql['A old (every redemption)'] === $sqlite['A old (every redemption)']);

    check('RULE C IS NOT: the naive predicate answers DIFFERENTLY on the two engines',
        $mysql['C naive lower-cased IN()'] !== $sqlite['C naive lower-cased IN()'],
        'this is the collation claim GAP-231 rests on');

    check('on MariaDB the naive predicate matches the capitalised spellings (case-insensitive)',
        ($mysql['C naive lower-cased IN()']['BANKWIRE'] ?? null) === 4
        && ($mysql['C naive lower-cased IN()']['MIXEDCASE'] ?? null) === 2);

    check('on SQLite it matches neither, so two paid coupons vanish from the ranking',
        ! array_key_exists('BANKWIRE', $sqlite['C naive lower-cased IN()'])
        && ! array_key_exists('MIXEDCASE', $sqlite['C naive lower-cased IN()']));

    check('so on MariaDB rule C happens to agree with rule B on this fixture',
        $mysql['C naive lower-cased IN()'] === $mysql['B shipped (settled only)']);

    /*
     * A CLAIM THIS SCRIPT SET OUT TO CONFIRM AND INSTEAD REFUTED, on its first run.
     *
     * `SettledPlanOrders::spellingsInUse()`'s docblock says the derivation "cannot be short by a
     * casing nobody imagined, because it reads the column" and "behaves identically on both
     * engines, because the comparison that decides anything happens in PHP". MEASURED: it returns
     * FOUR spellings on SQLite and THREE on MariaDB. `DISTINCT` is COLLATION-AWARE, and
     * `utf8mb4_unicode_ci` is case-insensitive, so on production's engine `'succeeded'` and
     * `'Succeeded'` are ONE value and the server returns whichever casing it saw first.
     *
     * Isolated outside this script to be sure it is the collation and not the fixture:
     * `SELECT DISTINCT s` over `('succeeded','Succeeded','Approved','success')` in a
     * `utf8mb4_unicode_ci` column returns 3 rows, and `COUNT(DISTINCT s)` is 3.
     *
     * NO RANKING IS WRONG BECAUSE OF IT, which is why this is a check and not a defect: the
     * `IN()` the shorter list builds is folded by the SAME collation, so it still matches the
     * casing the list omitted -- rule B's per-coupon numbers above are identical on both engines.
     * But the SAFETY on MariaDB comes from the engine's case-insensitivity, which is the exact
     * behaviour the derivation was written not to depend on. Recorded rather than fixed.
     */
    check('MEASURED, NOT ASSUMED: `spellingsInUse()` reads 4 spellings on SQLite and 3 on MariaDB',
        array_keys($sqlite['spellings']) === ['Approved', 'Succeeded', 'succeeded', 'success']
        && array_keys($mysql['spellings']) === ['Approved', 'succeeded', 'success'],
        'mysql=' . implode(',', array_keys($mysql['spellings']))
        . ' sqlite=' . implode(',', array_keys($sqlite['spellings'])));

    check('and the shorter MariaDB list still ranks identically, because its IN() is folded too',
        $mysql['B shipped (settled only)'] === $sqlite['B shipped (settled only)']
        && ($mysql['B shipped (settled only)']['MIXEDCASE'] ?? null) === 2,
        'MIXEDCASE is stored `Succeeded`, the casing the MariaDB list omits');
} finally {
    $cleanup();
}

echo "\n{$checks} checks, " . count($failures) . " failed\n";

exit($failures === [] ? 0 : 1);
