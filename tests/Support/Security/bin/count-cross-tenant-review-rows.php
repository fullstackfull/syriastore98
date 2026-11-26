#!/usr/bin/env php
<?php

/**
 * HOW MANY REVIEW ROWS NAME A PRODUCT OF A DIFFERENT STORE? A READ-ONLY COUNT.  GAP-193 / F2.
 * =================================================================================================
 *
 * WHY THIS EXISTS. The GAP-193 fix is FORWARD-ONLY: it stops the five panel and admin-API writers
 * from saving a review whose `product_id` belongs to another store, and it repairs NOTHING already
 * stored. A row planted before the fix stays, and `Api\ApiController::product_detail()` keeps
 * serving it on the victim's product page, because that reader looks reviews up by `product_id`
 * alone.
 *
 * WHAT THE INDEPENDENT REVIEW ESTABLISHED ABOUT THE BLAST RADIUS, and it is narrower than it first
 * looks: both panel `Testimonial::AvregeRating()` call sites are COMMENTED OUT
 * (`TestimonialController` store() and update()), so a planted row moves
 * `products.average_rating` only on the NEXT rating of the victim's product -- and `AvregeRating()`
 * recomputes from scratch over every row for that `product_id`, so deleting a planted row
 * self-corrects the average, but again only on a further rating.
 *
 * SO THIS SCRIPT COUNTS AND PRINTS. It writes nothing, migrates nothing and deletes nothing.
 * Whether to delete, re-point or keep such rows -- and whether to force a recompute -- is an
 * OPERATOR decision about live customer-visible content, not an engineering one.
 *
 * WHY A SCRIPT AND NOT A TEST. It has to run against a REAL database to mean anything: on the
 * suite's SQLite `:memory:` the answer is always 0, and CLAUDE.md records that a conditionally
 * skipped test would break the declared outcome set.
 *
 * PRECONDITIONS, stated so a run that proves nothing is recognisable as one:
 *   - a `.env` pointing at the database you mean to inspect;
 *   - read access to `testimonials` and `products`;
 *   - nothing else. It takes no arguments and needs no write permission.
 *
 * USAGE
 *     php tests/Support/Security/bin/count-cross-tenant-review-rows.php
 *
 * EXIT CODE: 0 when it ran, whatever it found -- the count is the output, not the verdict, and a
 * non-zero exit would make it look like a gate. 1 only when it could not look.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function line(string $text = ''): void
{
    fwrite(STDOUT, $text . PHP_EOL);
}

line('GAP-193 / F2 -- review rows whose store disagrees with their product\'s store');
line('=========================================================================');
line('Connection: ' . config('database.default')
    . '   Database: ' . (string) config('database.connections.' . config('database.default') . '.database'));
line();

/*
 * THE CONNECTIVITY CHECK IS WRAPPED, because the first version of this script did not wrap it and
 * then BROKE ITS OWN DOCUMENTED CONTRACT -- measured: pointed at a `.env` whose sqlite file does
 * not exist, `Schema::hasTable()` threw an uncaught QueryException and the script exited 0 with a
 * stack trace, where it says above that it exits 1 only when it could not look. A script whose exit
 * code lies is worse than no script.
 */
try {
    foreach (['testimonials', 'products'] as $table) {
        if (! Schema::hasTable($table)) {
            line("COULD NOT LOOK: the `{$table}` table does not exist on this connection.");
            exit(1);
        }
    }
} catch (\Throwable $e) {
    line('COULD NOT LOOK: the database could not be read.');
    line('  ' . $e->getMessage());
    line();
    line('Point `.env` at the database you mean to inspect. This script needs READ access to');
    line('`testimonials` and `products` and nothing else.');
    exit(1);
}

/*
 * BOTH `store_id` COLUMNS ARE NULLABLE, so a row with a NULL on either side is NOT a disagreement
 * -- it is an unknown, and counting it as a finding would overstate the result. They are reported
 * separately instead. This is the same distinction `TestimonialController::callerOwnsTestimonial()`
 * draws when it requires `! empty($testimonial->store_id)` before comparing.
 */
$mismatched = DB::table('testimonials')
    ->join('products', 'products.id', '=', 'testimonials.product_id')
    ->whereNotNull('testimonials.store_id')
    ->whereNotNull('products.store_id')
    ->whereColumn('testimonials.store_id', '<>', 'products.store_id')
    ->count();

$unknownSide = DB::table('testimonials')
    ->join('products', 'products.id', '=', 'testimonials.product_id')
    ->where(function ($q) {
        $q->whereNull('testimonials.store_id')->orWhereNull('products.store_id');
    })
    ->count();

$orphanProduct = DB::table('testimonials')
    ->leftJoin('products', 'products.id', '=', 'testimonials.product_id')
    ->whereNotNull('testimonials.product_id')
    ->where('testimonials.product_id', '<>', 0)
    ->whereNull('products.id')
    ->count();

$total = DB::table('testimonials')->count();

line('testimonials rows, total .................. ' . $total);
line('CROSS-TENANT (both store_ids known, differ)  ' . $mismatched);
line('one side NULL (unknown, not a finding) ....  ' . $unknownSide);
line('product_id names no products row ..........  ' . $orphanProduct);
line();

if ($mismatched === 0) {
    line('NOTHING TO DECIDE: no stored review names a product of a different store.');
    line('That does not mean none ever existed -- this is a point-in-time count, and the');
    line('writers were open until the GAP-193 fix.');
    exit(0);
}

line('THE AFFECTED ROWS (review id, its store, the product, the product\'s store):');

DB::table('testimonials')
    ->join('products', 'products.id', '=', 'testimonials.product_id')
    ->whereNotNull('testimonials.store_id')
    ->whereNotNull('products.store_id')
    ->whereColumn('testimonials.store_id', '<>', 'products.store_id')
    ->orderBy('testimonials.id')
    ->select([
        'testimonials.id as review_id',
        'testimonials.store_id as review_store',
        'testimonials.product_id as product_id',
        'products.store_id as product_store',
        'testimonials.rating_no as rating',
        'testimonials.status as status',
    ])
    ->limit(500)
    ->get()
    ->each(static function ($row): void {
        /* NO REVIEW TEXT IS PRINTED. The title and description are customer-authored content and
           this script is meant to be runnable, and its output pasteable, without handling any. */
        line(sprintf(
            '  review %-8s store %-6s -> product %-8s store %-6s  rating %-3s status %s',
            $row->review_id, $row->review_store, $row->product_id,
            $row->product_store, $row->rating, $row->status
        ));
    });

line();
line('WHAT THIS DOES NOT DO, deliberately: nothing. No delete, no re-point, no recompute of');
line('`products.average_rating`. Each of those changes customer-visible content or a displayed');
line('figure, which is the owner\'s call. Note that `AvregeRating()` recomputes from scratch, so');
line('removing a row self-corrects that store\'s average on the next rating of the product.');

exit(0);
