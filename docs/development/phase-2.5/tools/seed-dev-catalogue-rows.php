<?php

/**
 * SEED ONE ROW IN EACH EMPTY CATALOGUE TABLE, so the row-action cells have something to render.
 * =================================================================================================
 *
 * WHY. The row-action matrix rows name the ACTION CELL inside a DataTable row -- the edit/delete
 * controls a merchant clicks. An empty table renders an empty-state placeholder instead of a row,
 * so there is no cell to capture or measure. Measured in this fixture:
 *
 *     tags 3, product_labels 3, product_attributes 2, product_brands 3   -- already have rows
 *     coupons 0, faqs 0, testimonials 0, product_questions 0             -- render the empty state
 *
 * An earlier wave's evidence (evidence/pkg-b-pages/after/faqs__en_ltr_light.png) shows a seeded
 * row titled "P25PROBE How do refunds work?", so those rows existed once and the fixture has been
 * regenerated since. This tool is how they come back, reproducibly, instead of by hand.
 *
 * DEV FIXTURE ONLY, IDEMPOTENT, REVERSIBLE. Every row is tagged `P25PROBE` in its own text field,
 * which is what makes it both findable and removable:
 *
 *     php seed-dev-catalogue-rows.php --remove
 *
 * It never touches a table that already holds rows this tool did not create, so a fixture someone
 * has populated by hand is left alone rather than added to.
 *
 * usage:
 *   DB_CONNECTION=sqlite DB_DATABASE=<repo>/database/p2dev.sqlite CACHE_STORE=file \
 *     php docs/development/phase-2.5/tools/seed-dev-catalogue-rows.php [--remove]
 */

require __DIR__ . '/../../../../vendor/autoload.php';

/**
 * P2.5-SAFETY-W. FAIL CLOSED BEFORE THE APPLICATION BOOTS. Same control as
 * seed-dev-interaction-fixtures.php, and for the same reason: this tool WRITES, and run against a
 * real database it would insert rows into a live catalogue and print success.
 *
 * `Tests\` is mapped by composer's autoload-dev, so on a production install the guard class is
 * simply absent and this tool cannot start.
 */
if (! class_exists(\Tests\Support\DevFixtureDatabaseGuard::class)) {
    fwrite(STDERR, "REFUSING TO RUN: Tests\\Support\\DevFixtureDatabaseGuard is not autoloadable, "
        . "which means this is a --no-dev install. Development fixture tools do not run here.\n");
    exit(1);
}

try {
    \Tests\Support\DevFixtureDatabaseGuard::assertCurrentEnvironmentIsADevFixture();
} catch (\RuntimeException $e) {
    // Caught so the refusal reads as a refusal: an uncaught throw exits 255 with a stack trace,
    // which a caller reads as "broken" and re-runs with different arguments until it works.
    fwrite(STDERR, "\n" . $e->getMessage() . "\n\n");
    exit(1);
}

$app = require_once __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const TAG = 'P25PROBE';
const STORE_ID = 1;

$remove = in_array('--remove', $argv, true);

/**
 * The four empty tables, each with the column its tag goes in and the row to insert.
 *
 * Written as raw column maps rather than through the Eloquent models on purpose: several of these
 * models are `Cachable` and a couple carry global scopes, so a model write here and a model read
 * in the harness can disagree about what exists. A raw insert plus the explicit flush below is the
 * combination this repository has already found to be honest -- see CLAUDE.md on the three
 * measured false readings that came from mixing the two.
 */
$tables = [
    'coupons' => [
        'tag_column' => 'coupon_name',
        'row' => [
            'coupon_name' => TAG . ' Launch discount',
            'coupon_code' => 'P25PROBE10',
            'coupon_type' => 'percentage',
            'discount_amount' => 10,
            'coupon_limit' => 100,
            'coupon_expiry_date' => '2027-12-31',
            'status' => 1,
            'store_id' => STORE_ID,
        ],
    ],
    /*
     * `description` IS A JSON ARRAY, NOT PROSE, and that is not obvious from the column.
     *
     * `faq/edit.blade.php:12` does `foreach (json_decode($faq->description, true) as $item)` and
     * reads `$item['question']` -- the FAQ form is a REPEATER, so one row holds a topic and a
     * list of questions under it. Seeded with a plain sentence, `json_decode` returns null, the
     * foreach fails, and the edit modal renders with NO FORM AT ALL. The create modal was
     * unaffected, which is what made it look like an application defect in the edit path rather
     * than a wrong fixture: measured as "modal holds no <form>" on faq-edit in all four
     * combinations while faq-create captured cleanly.
     */
    'faqs' => [
        'tag_column' => 'topic',
        'row' => [
            'topic' => TAG . ' How do refunds work?',
            'description' => '[{"question":"How long does a refund take?"},'
                . '{"question":"Can I return an item after 14 days?"}]',
            'store_id' => STORE_ID,
        ],
    ],
    /*
     * `category_id` DEFAULTS TO '0' AND CARRIES A FOREIGN KEY to categories(id), so the column's
     * own default violates its own constraint -- the first insert here failed on exactly that.
     * A real category id is resolved below; if the fixture has none the row is skipped rather
     * than inserted with a dangling reference.
     */
    'testimonials' => [
        'tag_column' => 'title',
        'needs' => ['category_id' => 'categories'],
        'row' => [
            'title' => TAG . ' Fast delivery',
            'description' => 'The order arrived in two days and the packaging was intact.',
            'username' => 'Layla Haddad',
            'rating_no' => 5,
            'status' => 1,
            'store_id' => STORE_ID,
        ],
    ],
    // Three foreign keys, all nullable in the schema but all pointed at a real row so the
    // list page renders the question WITH its product and asker rather than with blanks.
    'product_questions' => [
        'tag_column' => 'question',
        'needs' => ['product_id' => 'products', 'customer_id' => 'customers', 'created_by' => 'users'],
        'row' => [
            'question' => TAG . ' Is this available in other colours?',
            'answers' => 'Yes, it is stocked in three colours.',
            'store_id' => STORE_ID,
        ],
    ],
];

$now = date('Y-m-d H:i:s');
$done = [];

foreach ($tables as $table => $spec) {
    if (! Schema::hasTable($table)) {
        $done[] = [$table, 'no such table'];
        continue;
    }

    $column = $spec['tag_column'];
    $mine = DB::table($table)->where($column, 'like', TAG . '%');

    if ($remove) {
        $n = $mine->count();
        $mine->delete();
        $done[] = [$table, 'removed ' . $n];
        continue;
    }

    if ($mine->count() > 0) {
        $done[] = [$table, 'already seeded'];
        continue;
    }

    /*
     * DO NOT ADD TO A TABLE SOMEBODY ELSE POPULATED. If the table holds rows that are not this
     * tool's, the fixture is in a state this tool did not create and does not understand -- adding
     * to it would make the removal step incomplete and could mask a real row behind a probe row.
     */
    if (DB::table($table)->count() > 0) {
        $done[] = [$table, 'skipped: holds rows this tool did not create'];
        continue;
    }

    /*
     * RESOLVE FOREIGN KEYS AGAINST THE FIXTURE rather than assuming id 1 exists. A dangling
     * reference either fails the insert (SQLite with foreign_keys on, which is how this surfaced)
     * or succeeds and renders a row whose related record is missing -- the second being worse,
     * because it photographs as a real row with blank columns.
     */
    $resolved = [];
    foreach (($spec['needs'] ?? []) as $column => $referenced) {
        if (! Schema::hasColumn($table, $column) || ! Schema::hasTable($referenced)) {
            continue;
        }
        $id = DB::table($referenced)->value('id');
        if ($id === null) {
            $done[] = [$table, 'skipped: ' . $referenced . ' is empty, so ' . $column . ' has nothing to point at'];
            continue 2;
        }
        $resolved[$column] = $id;
    }

    // Only columns that really exist, so a schema that differs from this fixture degrades to a
    // narrower insert rather than a fatal error.
    $row = array_intersect_key($spec['row'] + $resolved, array_flip(Schema::getColumnListing($table)));
    if (Schema::hasColumn($table, 'created_at')) {
        $row['created_at'] = $now;
    }
    if (Schema::hasColumn($table, 'updated_at')) {
        $row['updated_at'] = $now;
    }

    DB::table($table)->insert($row);
    $done[] = [$table, 'seeded 1'];
}

/*
 * FLUSH. 79 of the 95 models are `Cachable`, and a raw write leaves Eloquent reads -- route-model
 * binding included -- serving the pre-write row. Under the harness's CACHE_STORE=file that staleness
 * outlives this process, so the browser would load a page built from the cached, empty result.
 */
foreach (['App\Models\Coupon', 'App\Models\Faq', 'App\Models\Testimonial', 'App\Models\ProductQuestion'] as $class) {
    if (class_exists($class) && method_exists($class, 'flushCache')) {
        (new $class)->flushCache();
    }
}

printf("%s the dev catalogue fixture (store %d)\n\n", $remove ? 'CLEANED' : 'SEEDED', STORE_ID);
foreach ($done as [$table, $what]) {
    printf("  %-20s %s\n", $table, $what);
}

echo "\ncounts now:\n";
foreach (array_keys($tables) as $table) {
    if (Schema::hasTable($table)) {
        printf("  %-20s %d\n", $table, DB::table($table)->count());
    }
}
