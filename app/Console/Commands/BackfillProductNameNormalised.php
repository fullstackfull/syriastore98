<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Support\Search\ProductNameIndex;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * THE BACKFILL FOR `products.name_normalised`.  `CUST-003` / `GAP-190` / owner decision `J38`.
 * =================================================================================================
 *
 * -------------------------------------------------------------------------------------------------
 * WHY THIS IS A COMMAND AND NOT PART OF THE MIGRATION, AND WHY IT HAS NOT BEEN RUN
 * -------------------------------------------------------------------------------------------------
 *
 * Writing a column across **every tenant's catalogue** is a bulk write to live merchant data.
 * `CLAUDE.md` §2 puts "persistent migrations or destructive live-data operations" among the few
 * things engineering escalates rather than decides, so this slice **designs** the backfill and does
 * not perform it. It has been executed only against this repository's own throwaway test fixtures
 * (`ProductNameIndexBackfillTest`). No environment outside the test suite has been touched.
 *
 * **Nothing depends on it having been run.** `ProductNameIndex::applyNameFilter()` keeps the raw
 * `name LIKE` limb first, so a row with a NULL fold is found exactly as it is found today, and
 * `ProductNameIndexObserver` fills a missing fold the next time the row is saved for any reason.
 * This command exists to make the improvement arrive for a catalogue nobody has edited since,
 * rather than to make the feature work.
 *
 * -------------------------------------------------------------------------------------------------
 * DRY RUN IS THE DEFAULT. WRITING NEEDS `--apply`
 * -------------------------------------------------------------------------------------------------
 *
 *     php artisan products:backfill-name-normalised               # counts only, writes nothing
 *     php artisan products:backfill-name-normalised --store=7     # counts for one store
 *     php artisan products:backfill-name-normalised --apply       # writes
 *
 * A flag whose ABSENCE is destructive is the wrong way round: somebody who forgets `--dry-run` has
 * already written. `--dry-run` is accepted so the intent can be stated explicitly, and it is
 * refused together with `--apply` rather than one silently winning.
 *
 * -------------------------------------------------------------------------------------------------
 * HOW IT WRITES, AND THE THREE TRAPS IT AVOIDS
 * -------------------------------------------------------------------------------------------------
 *
 * 1. **THROUGH ELOQUENT, NOT `DB::table()->update()`.** `Product` is `Cachable`. A raw write leaves
 *    every Eloquent read — route-model binding included — serving the pre-write row, which
 *    `CLAUDE.md` records as three measured false readings. A raw write would also add a row to
 *    `ResidualTenancyExposureTest`'s raw-query inventory for a command that has no business there.
 *
 * 2. **`timestamps = false` PER ROW.** Letting the backfill bump `updated_at` on every product on
 *    the platform would be a visible change to merchant data — "recently modified" ordering, and
 *    anything keyed on that column — caused by a search index. Asserted by the test, not assumed.
 *
 * 3. **`chunkById`, NOT `chunk`.** The loop writes the rows it is reading. `chunk()` paginates with
 *    OFFSET and would skip rows as the result set shifts; `chunkById` walks the primary key.
 *
 * `saveQuietly()` is deliberately NOT used. It wraps the save in `withoutEvents`, which would skip
 * the model-cache flush as well as the observer, so the rows would be written and then read stale.
 * The observer computes the value; this command sets it anyway, so the write is correct whether or
 * not the observer is registered.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT IT COUNTS
 * -------------------------------------------------------------------------------------------------
 *
 *     examined   rows read
 *     missing    fold is NULL
 *     stale      fold is present and disagrees with the fold of the current name
 *     correct    fold is present and agrees
 *     written    rows actually saved (always 0 in a dry run)
 *
 * `stale` exists because the column can only disagree if something wrote the name without going
 * through Eloquent. Nothing in the codebase does today — `ProductNameIndexObserver`'s docblock
 * carries that census and `ProductNameIndexCoverageTest` re-runs it — so a non-zero `stale` on a
 * real environment is itself a finding and the command says so.
 */
final class BackfillProductNameNormalised extends Command
{
    protected $signature = 'products:backfill-name-normalised
                            {--apply : actually write. Without it this command only reports counts}
                            {--dry-run : state the default explicitly; refused together with --apply}
                            {--store= : limit to one store_id}
                            {--chunk=500 : rows per batch}';

    protected $description = 'Fill products.name_normalised (CUST-003 Arabic search). Dry run unless --apply.';

    public function handle(): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('--apply and --dry-run contradict each other. Pass one or neither.');

            return self::INVALID;
        }

        $apply = (bool) $this->option('apply');

        /* The memo may predate a migration run in the same process. */
        ProductNameIndex::forgetStored();

        if (! Schema::hasTable(ProductNameIndex::TABLE)
            || ! Schema::hasColumn(ProductNameIndex::TABLE, ProductNameIndex::COLUMN)) {
            $this->error(sprintf(
                '%s.%s does not exist on this connection. Run '
                . '2026_11_27_000100_add_name_normalised_to_products_table.php first; it adds an '
                . 'empty nullable column and changes no row.',
                ProductNameIndex::TABLE, ProductNameIndex::COLUMN
            ));

            return self::FAILURE;
        }

        $store = $this->option('store');
        $chunk = max(1, (int) $this->option('chunk'));

        if ($store !== null && filter_var($store, FILTER_VALIDATE_INT) === false) {
            /* `is_numeric` accepts '5.9' and MySQL coerces '5abc' to 5; the skill's rule. */
            $this->error('--store must be an integer store id.');

            return self::INVALID;
        }

        $counts = ['examined' => 0, 'missing' => 0, 'stale' => 0, 'correct' => 0, 'written' => 0];

        /*
         * `withoutGlobalScopes()` is belt and braces: `Product` is absent from
         * `config/tenancy.php`'s `scoped_models` so `StoreScope` adds nothing for any principal —
         * but a console command has no principal at all, and a backfill that silently covered one
         * tenant because a scope resolved would be the worst possible kind of partial success.
         * The store limit, when given, comes from the OPERATOR'S OWN ARGUMENT, which is the one
         * context in which that is not "a predicate built from caller input".
         */
        $query = Product::query()->withoutGlobalScopes()->orderBy('id');

        if ($store !== null) {
            $query->where('store_id', (int) $store);
        }

        $query->chunkById($chunk, function ($products) use ($apply, &$counts): void {
            foreach ($products as $product) {
                $counts['examined']++;

                $name = (string) $product->getAttribute('name');
                $want = $name === '' ? null : ProductNameIndex::normalise($name);
                $have = $product->getAttribute(ProductNameIndex::COLUMN);

                if ($have === $want) {
                    $counts['correct']++;

                    continue;
                }

                $counts[$have === null ? 'missing' : 'stale']++;

                if (! $apply) {
                    continue;
                }

                $product->setAttribute(ProductNameIndex::COLUMN, $want);

                /* A search index must not restate when a merchant last edited their product. */
                $product->timestamps = false;
                $product->save();

                $counts['written']++;
            }
        });

        $this->table(array_keys($counts), [array_values($counts)]);

        if (! $apply) {
            $this->line(sprintf(
                'DRY RUN — nothing was written. %d row(s) would be filled and %d rewritten. '
                . 'Re-run with --apply to write.',
                $counts['missing'], $counts['stale']
            ));
        }

        if ($counts['stale'] > 0) {
            $this->warn(sprintf(
                '%d row(s) hold a fold that disagrees with the current name. Nothing in this '
                . 'codebase writes products.name outside Eloquent, so a non-zero figure here means '
                . 'something does — worth finding before trusting the column.',
                $counts['stale']
            ));
        }

        return self::SUCCESS;
    }
}
