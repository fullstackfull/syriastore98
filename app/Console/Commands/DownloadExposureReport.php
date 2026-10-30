<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * F217 — WHICH PAID FILES ARE STILL REACHABLE WITHOUT AN ORDER, AND WHERE THEY ARE.
 * =================================================================================================
 *
 * The owner's decision, 2026-10-30: *"prevent unauthorised direct access to the old paid download
 * files"*, and *"adding a new route alone does not close F217 if the old public link still works."*
 *
 * `PaidDownloadController` closes the path for every download served FROM NOW ON. It cannot close
 * the files that already exist, and the reason is structural rather than a lack of effort: paid
 * downloadables and ordinary product images are written to the **same directory** —
 * `uploads/{store_id}/` — by the same `Utility::upload_file()` call, so there is no path pattern
 * that denies one without denying the other, and the owner requires authorised public media to keep
 * working. Containing them means **moving** the files and rewriting their database paths, which is a
 * production data write and is forbidden to this session.
 *
 * SO THIS COMMAND MAKES THE REMAINING WORK PRECISE INSTEAD OF APPROXIMATE. It is **read-only**: it
 * writes no file, moves nothing, and changes no row. It reads `products.downloadable_product` and
 * `product_variants.downloadable_product`, reports which of those files exist on disk and which are
 * reachable through the `public/uploads` symlink, and prints the move plan an operator would
 * follow. Running it on production is a read and is safe; acting on its output is the operator's
 * decision and is documented in the F217 runbook section.
 *
 * WHY A COMMAND AND NOT A TEST. A test can assert the exposure exists — and one does
 * (`PaidDownloadEntitlementTest::test_the_old_public_path_is_still_reachable_…`). What a test cannot
 * do is tell the owner HOW MANY files and WHICH, on a database the test does not have. That number
 * is what decides whether the containment is an afternoon or a project, and it is the one fact
 * nobody could supply from this container.
 */
class DownloadExposureReport extends Command
{
    protected $signature = 'downloads:exposure-report {--json : machine-readable output}';

    protected $description = 'F217: list paid download files that are still reachable without an order (read-only)';

    public function handle(): int
    {
        $rows = [];

        foreach ([['products', 'id'], ['product_variants', 'id']] as [$table, $key]) {
            if (! \Illuminate\Support\Facades\Schema::hasColumn($table, 'downloadable_product')) {
                $this->warn("{$table} has no downloadable_product column; skipped.");
                continue;
            }

            foreach (DB::table($table)
                ->whereNotNull('downloadable_product')
                ->where('downloadable_product', '!=', '')
                ->get([$key, 'store_id', 'downloadable_product']) as $row) {
                $path = (string) $row->downloadable_product;

                $rows[] = [
                    'table' => $table,
                    'id' => $row->{$key},
                    'store_id' => $row->store_id,
                    'path' => $path,
                    'on_disk' => is_file(base_path(ltrim($path, '/'))),
                    'publicly_reachable' => $this->publiclyReachable($path),
                ];
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $exposed = array_values(array_filter($rows, fn ($r) => $r['publicly_reachable']));

        $this->info('F217 paid-download exposure report (read-only)');
        $this->line(str_repeat('-', 78));
        $this->line('rows with a downloadable file : ' . count($rows));
        $this->line('present on disk               : ' . count(array_filter($rows, fn ($r) => $r['on_disk'])));
        $this->line('PUBLICLY REACHABLE            : ' . count($exposed));
        $this->line('');

        if ($rows === []) {
            /*
             * NO ROWS AT ALL PROVES NOTHING ABOUT CONTAINMENT, and saying otherwise is exactly the
             * conflation this project keeps meeting: "the scan found nothing" and "there is
             * nothing to find" are different statements. A development database with no digital
             * products reports zero here while production may hold many.
             */
            $this->warn('NO ROW IN THIS DATABASE HAS A DOWNLOADABLE FILE.');
            $this->line('');
            $this->line('That is NOT evidence that F217 is contained. It is evidence that this');
            $this->line('database has no digital products -- which is expected in development and');
            $this->line('says nothing about production. Run this against the real database to get');
            $this->line('the number that decides how large the move in step 2 below actually is.');

            return self::SUCCESS;
        }

        if ($exposed === []) {
            $this->info('No paid file is reachable through the public document root,');
            $this->info('and ' . count($rows) . ' row(s) DO carry one -- so this is a real negative.');
            $this->line('If that is because the files were moved, F217 can move to closed and');
            $this->line('PaidDownloadEntitlementTest\'s residual case should be replaced by its inverse.');

            return self::SUCCESS;
        }

        $this->warn('Each of these is downloadable by anyone who guesses or is forwarded its URL,');
        $this->warn('with no order and no account. The stored name is');
        $this->warn('rand(10,100) . "_" . time() . "_" . $originalName, i.e. 91 prefixes x a');
        $this->warn('one-second timestamp x a filename the product page often reveals.');
        $this->line('');

        foreach ($exposed as $r) {
            $this->line(sprintf('  store %-6s %s:%-6s %s', $r['store_id'], $r['table'], $r['id'], $r['path']));
        }

        $this->line('');
        $this->info('THE MOVE PLAN — for an operator, not for this command.');
        $this->line('  1. Create a NON-public root, outside the document root, e.g.');
        $this->line('     storage/app/private/paid-downloads/{store_id}/');
        $this->line('  2. Move each file above into it, preserving the store subdirectory.');
        $this->line('  3. Update the row\'s downloadable_product to the new relative path.');
        $this->line('  4. PaidDownloadController confines the resolved path to base_path(\'uploads\'),');
        $this->line('     so step 3 requires widening that root to the new location IN THE SAME');
        $this->line('     CHANGE, or every moved file 404s for its rightful buyer.');
        $this->line('  5. Re-run this report: PUBLICLY REACHABLE must be 0.');
        $this->line('');
        $this->warn('Steps 2 and 3 are production data writes. They are an owner decision and are');
        $this->warn('NOT performed by this command, which only reads.');

        return self::SUCCESS;
    }

    /**
     * Reachable through the document root?
     *
     * `public/uploads` is a symlink to `../uploads`, so a path under `uploads/` is served by the
     * web server directly. Resolved with `realpath()` on both sides so a symlink or `..` cannot
     * make the answer wrong in either direction.
     */
    private function publiclyReachable(string $path): bool
    {
        $publicRoot = realpath(public_path());
        $candidate = realpath(base_path(ltrim($path, '/')));

        if ($publicRoot === false || $candidate === false) {
            return false;
        }

        if (str_starts_with($candidate, $publicRoot . DIRECTORY_SEPARATOR)) {
            return true;
        }

        /* The symlink case, which is the one that matters here: the file lives outside public/ but
           public/uploads points into its tree. */
        $linked = realpath(public_path('uploads'));

        return $linked !== false && str_starts_with($candidate, $linked . DIRECTORY_SEPARATOR);
    }
}
