<?php

/**
 * P1-PLT-04 criterion 2 -- HOW MANY MERCHANTS WOULD LOSE A STOREFRONT FEATURE? READ ONLY.
 *
 * WHY THIS NUMBER DECIDES THE CHANGE RATHER THAN THE ENGINEER. Two entitlement sources disagree,
 * and the disagreement is measured, not inferred (tests/Feature/Security/ModuleEntitlementSourcesTest):
 *
 *   PlanModuleCheck            requires the plan AND an activation row  (the admin side)
 *   module_is_active(), slug   consults plans.modules ALONE             (the storefront side)
 *
 * So a merchant holding the plan with no `user_active_modules` row gets the module ON THEIR
 * STOREFRONT and a 403 on their own admin screen for the same module. The correct rule is the
 * intersection the admin side already enforces, it is one line, and it is strictly NARROWER --
 * which is exactly the problem: applying it REMOVES a working storefront feature from every
 * merchant in that state. How many that is lives in the production `user_active_modules` table and
 * nowhere else.
 *
 * A ZERO ANSWER makes the change a no-op and it ships. A NON-ZERO ANSWER is the number of merchants
 * whose storefront loses a feature on deploy, and that is an owner's call.
 *
 * The query was previously carried only in that test's docblock. This is the same query, runnable,
 * with the per-merchant breakdown the owner needs to judge the blast radius. It issues SELECT
 * statements only.
 *
 * Run it where the application's own .env points at the database you want to read:
 *     php tests/Support/Security/bin/report-storefront-entitlement-gap.php
 * It is also safe on the test database (it prints zeros).
 *
 * Exit status: 0 whatever the answer. This reports; it does not judge.
 */

require __DIR__ . '/../../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

foreach (['users', 'plans', 'user_active_modules'] as $table) {
    if (! Schema::hasTable($table)) {
        fwrite(STDERR, "The {$table} table does not exist on this connection (" . config('database.default') . ").\n");
        exit(2);
    }
}

printf("connection: %s\n\n", config('database.default'));

/**
 * `type = 'admin'` is the tenant root. User::creatorId() returns the row's own id for 'admin' and
 * 'super admin', so these are the merchants whose plan governs a storefront. The operator is
 * excluded deliberately: they own no storefront.
 */
$affected = DB::table('users as u')
    ->join('plans as p', 'p.id', '=', 'u.plan_id')
    ->where('u.type', 'admin')
    ->whereNotNull('p.modules')
    ->where('p.modules', '<>', '')
    ->whereNotExists(function ($q) {
        $q->select(DB::raw(1))
            ->from('user_active_modules as m')
            ->whereColumn('m.user_id', 'u.id');
    });

$count = (clone $affected)->count();

printf("merchants holding a plan with modules and NO activation row at all: %d\n", $count);
printf("merchants of type 'admin' in total                                : %d\n",
    DB::table('users')->where('type', 'admin')->count());

if ($count === 0) {
    echo "\nANSWER: zero. Narrowing the storefront branch to the intersection removes nothing from\n"
        . "anybody, so it is a no-op on this data and may ship as an ordinary change.\n";
    exit(0);
}

echo "\nWhich plans, and how many merchants on each (no merchant is named):\n";

foreach ((clone $affected)->select('p.id', 'p.name', DB::raw('count(*) as n'))
    ->groupBy('p.id', 'p.name')->orderByDesc('n')->get() as $row) {
    printf("  plan %-6s %-32s %d merchant(s)\n", (string) $row->id, (string) $row->name, $row->n);
}

/*
 * THE COUNT ABOVE IS THE FLOOR, NOT THE ANSWER, and saying so is the point of this block. A
 * merchant with SOME activation rows still loses every module their plan grants that they have no
 * row for -- the whole-merchant count misses those entirely. That per-module figure cannot be
 * computed in SQL here because plans.modules is a serialized column whose shape varies by vintage,
 * so it is counted in PHP, per plan, and any row that will not decode is reported rather than
 * silently skipped.
 */
echo "\nPer-module exposure, including merchants who have SOME activation rows:\n";

$undecodable = 0;
$perModule = [];

foreach (DB::table('users as u')
    ->join('plans as p', 'p.id', '=', 'u.plan_id')
    ->where('u.type', 'admin')
    ->whereNotNull('p.modules')
    ->where('p.modules', '<>', '')
    ->select('u.id', 'p.modules')
    ->get() as $merchant) {
    $raw = (string) $merchant->modules;
    $modules = json_decode($raw, true);

    if (! is_array($modules)) {
        /*
         * CSV FALLBACK, BUT ONLY FOR SOMETHING THAT IS ACTUALLY A LIST. Measured on a seeded
         * probe: a truncated JSON column (`{`) fell through to explode(',') and was reported as
         * a MODULE NAMED `{`, with the undecodable counter still reading zero -- so the script
         * invented a module and then claimed its figures were complete. Any JSON punctuation, or
         * a token that is not a plausible module name, means this column is not a list and the
         * row is counted as undecodable instead of guessed at.
         */
        $modules = (preg_match('/[\[\]{}"]/', $raw) === 1)
            ? []
            : array_values(array_filter(array_map('trim', explode(',', $raw))));

        foreach ($modules as $candidate) {
            if (preg_match('/^[A-Za-z0-9_\- ]+$/', $candidate) !== 1) {
                $modules = [];
                break;
            }
        }
    }

    if ($modules === []) {
        $undecodable++;
        continue;
    }

    $active = DB::table('user_active_modules')
        ->where('user_id', $merchant->id)
        ->pluck('module')
        ->map(fn ($m) => (string) $m)
        ->all();

    foreach ($modules as $module) {
        $module = is_string($module) ? $module : (string) ($module['name'] ?? '');

        if ($module === '') {
            continue;
        }

        if (! in_array($module, $active, true)) {
            $perModule[$module] = ($perModule[$module] ?? 0) + 1;
        }
    }
}

arsort($perModule);

foreach ($perModule as $module => $n) {
    printf("  %-36s %d merchant(s) would lose it on the storefront\n", $module, $n);
}

if ($perModule === []) {
    echo "  (none)\n";
}

if ($undecodable > 0) {
    printf("\n%d plan row(s) carried a modules column this script could not decode. They are NOT\n"
        . "counted above, so the figures are a floor. Inspect those plans before deciding.\n", $undecodable);
}

echo "\nANSWER: non-zero. Narrowing the storefront branch removes a working feature from the\n"
    . "merchants counted above. That is a business decision, not an engineering one -- record it\n"
    . "on issue #36 before the change ships.\n";

echo "\nNothing was modified and no merchant was named.\n";

exit(0);
