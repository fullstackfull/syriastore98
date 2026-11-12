<?php

/**
 * SEED ONE SAVED VIEW, so the order filter bar's chip group has something to render.
 * =================================================================================================
 *
 * WHY. The `Order filter bar + saved views` inventory row covers two things: the save box and the
 * CHIP GROUP of views already saved, each chip carrying an X remove control. With `saved_views`
 * empty the bar renders only the save box -- measured: the captured strip is 32 characters of
 * text, the "save these filters as" label and its button, and no chip at all. Capturing that and
 * claiming the row would be evidence for half a surface.
 *
 * `SavedView` is one of the sixteen models that are NOT `Cachable` (CLAUDE.md §1), so a raw
 * insert here needs no cache flush -- unlike the catalogue seeder beside it, which does.
 *
 * OWNERSHIP IS SET EXPLICITLY, not mass-assigned. The model's `$fillable` deliberately omits
 * `store_id` and `created_by` so that no request can create a view owned by somebody else; this
 * tool writes them directly, as the controller does, from the fixture merchant.
 *
 * DEV FIXTURE ONLY, IDEMPOTENT, REVERSIBLE. The row is tagged `P25PROBE` in its name:
 *
 *     php seed-dev-saved-views.php --remove
 *
 * usage:
 *   DB_CONNECTION=sqlite DB_DATABASE=<repo>/database/p2dev.sqlite CACHE_STORE=file \
 *     php docs/development/phase-2.5/tools/seed-dev-saved-views.php [--remove]
 */

require __DIR__ . '/../../../../vendor/autoload.php';

if (! class_exists(\Tests\Support\DevFixtureDatabaseGuard::class)) {
    fwrite(STDERR, "REFUSING TO RUN: Tests\\Support\\DevFixtureDatabaseGuard is not autoloadable, "
        . "which means this is a --no-dev install. Development fixture tools do not run here.\n");
    exit(1);
}

try {
    \Tests\Support\DevFixtureDatabaseGuard::assertCurrentEnvironmentIsADevFixture();
} catch (\RuntimeException $e) {
    fwrite(STDERR, "\n" . $e->getMessage() . "\n\n");
    exit(1);
}

$app = require_once __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const TAG = 'P25PROBE';

$remove = in_array('--remove', $argv, true);

if (! Schema::hasTable('saved_views')) {
    fwrite(STDERR, "REFUSING: saved_views is missing from this fixture.\n");
    exit(1);
}

$merchant = DB::table('users')->where('email', 'merchant@example.test')->first();

if (! $merchant) {
    fwrite(STDERR, "REFUSING: no merchant@example.test in the fixture.\n");
    exit(1);
}

if ($remove) {
    $n = DB::table('saved_views')->where('name', 'LIKE', TAG . '%')->delete();
    printf("CLEANED: %d saved view(s) removed\n", $n);
} else {
    $existing = DB::table('saved_views')->where('name', 'LIKE', TAG . '%')->count();

    if ($existing > 0) {
        printf("already seeded: %d saved view(s) tagged %s\n", $existing, TAG);
    } else {
        $now = date('Y-m-d H:i:s');

        // Two chips, not one: a chip group with a single member cannot show its own spacing, and
        // the second name is deliberately longer so the group's wrapping is visible.
        foreach ([
            ['name' => TAG . ' Unpaid this week', 'filters' => ['ss_status' => '1']],
            ['name' => TAG . ' Awaiting pickup, Damascus', 'filters' => ['ss_status' => '4']],
        ] as $row) {
            DB::table('saved_views')->insert([
                'store_id' => 1,
                'created_by' => $merchant->id,
                'screen' => 'order',
                'name' => $row['name'],
                'filters' => json_encode($row['filters']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        printf("SEEDED: 2 saved view(s) for screen 'order', store 1, created_by %d\n", $merchant->id);
    }
}

printf("\nsaved_views now: %d\n", DB::table('saved_views')->count());
