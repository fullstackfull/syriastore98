<?php

namespace Tests\Unit\Commerce;

use PHPUnit\Framework\TestCase;

/**
 * `INV-010` / `EX-004` -- BATCH/LOT AND EXPIRY TRACKING ARE OUT OF SCOPE, AND STAY OUT.
 * ====================================================================================
 *
 * The approved decision and the matching exclusion, verbatim from
 * `docs/development/master-plan/DECISION-ID-REGISTER.md`:
 *
 *     INV-010  استبعاد Batch/Lot وتواريخ الصلاحية حالياً.
 *              Batch/Lot and expiry dates excluded for now (see EX-004)
 *
 *     EX-004   Batch/Lot وتتبع الصلاحية ضمن النطاق الحالي
 *              Batch/Lot and expiry tracking, within the current scope
 *
 * -------------------------------------------------------------------------------------------------
 * A DECISION THAT SOMETHING IS ABSENT IS STILL A DECISION, AND IT NEEDS A TEST.
 *
 * `INV-010` is satisfied today because the machinery it excludes does not exist. That is a real
 * disposition and not a dodge -- but it is the one kind of disposition that can be falsified by a
 * commit nobody reviewed against it. A warehouse feature, a vendored module upgrade or an
 * enthusiastic schema change can introduce lot tracking in a single migration, and because nothing
 * BREAKS when it appears, the decision would quietly stop holding and the register would go on
 * saying it held.
 *
 * So this file converts "satisfied by absence" into "satisfied by absence, re-measured on every
 * run". It is the whole deliverable for `INV-010` and it is deliberately cheap.
 *
 * -------------------------------------------------------------------------------------------------
 * WHY THE CENSUS IS OVER THE SCHEMA, AND WHY THAT IS SUFFICIENT.
 *
 * `INV-010` excludes *tracking*, and tracking requires STORAGE. A lot number that is not persisted
 * tracks nothing: there is no way to know which lot a unit came from, no way to report on it and no
 * way to act on an expiry. So a census over table and column names is not a proxy for the real
 * question -- for host-owned schema it IS the question, and it has no blind spot that matters.
 *
 * -------------------------------------------------------------------------------------------------
 * IT ENUMERATES THE SAFE SET, NOT THE DANGEROUS ONE.
 *
 * CLAUDE.md §3 records what happens otherwise: a census keyed on a list of dangerous spellings is
 * always short by the one nobody thought of -- a raw-SQL census keyed on `'literal' . $var` reported
 * ONE dynamic site where there were fifteen, after a live cross-tenant injection had already been
 * found among them.
 *
 * So the pattern here is BROAD and every match must be declared. Anything matching `batch`, `lot`,
 * `expiry`, `expiration`, `best_before` or `shelf_life` as a whole snake_case word is a candidate
 * BY DEFAULT, and the test fails unless it is in {@see self::PERMITTED_TABLES} or
 * {@see self::PERMITTED_COLUMNS} with a reason somebody wrote down. A new `lot_number` column fails
 * whatever anyone calls it, and so does a spelling this docblock never imagined.
 *
 * -------------------------------------------------------------------------------------------------
 * AND IT READS CODE, NOT COMMENTS.
 *
 * `php_strip_whitespace()` removes comments before matching, for the reason CLAUDE.md §3 gives
 * eight times over: the repository is full of prose ABOUT batch and expiry -- this very docblock
 * contains `lot_number`, `shelf_life` and `best_before` -- and a census that could be satisfied or
 * broken by a comment passes forever. The trait `Tests\Support\StripsSourceComments` solves the same
 * problem for a single named file; this census walks 277 of them, so it strips inline.
 */
class Inv010BatchLotExpiryExclusionTest extends TestCase
{
    /**
     * Whole snake_case words that could name batch/lot/expiry storage.
     *
     * Word-boundaried on `_` and the ends of the name, so `slot`, `ballot`, `plot` and `pilot` do
     * not match while `lot_number`, `batch_id` and `expiry_date` do.
     */
    private const CANDIDATE = '/(^|_)(batch|batches|lot|lots|expiry|expiries|expiration|expirations|best_before|bestbefore|shelf_life|shelflife)(_|$)/i';

    /**
     * Tables whose name matches the pattern and which are NOT inventory lot tracking.
     *
     * Measured, one entry per real hit: the figure is 1 across 153 host and 124 vendored migrations.
     *
     * @var array<string, string> table => why it is not what `INV-010` excludes
     */
    private const PERMITTED_TABLES = [
        // Laravel's own queue batching (`Bus::batch()`). A batch of JOBS, not of goods.
        'job_batches' => "Laravel's queue batch registry, created by the framework's own jobs migration",
    ];

    /**
     * Columns whose name matches the pattern and which are NOT inventory lot tracking.
     *
     * @var array<string, string> column => why it is not what `INV-010` excludes
     */
    private const PERMITTED_COLUMNS = [
        // Cache TTL. `cache` and `model_cache` both carry it; it is a unix timestamp for a cache
        // entry, nothing to do with goods.
        'expiration' => 'cache entry TTL, in the framework cache and model-cache tables',

        // A COUPON's validity window. The expiry of a discount, not of a product.
        'coupon_expiry_date' => 'the date a discount coupon stops being redeemable',
    ];

    /**
     * The tables that actually carry stock, so a lot column would be most natural here.
     *
     * Asserted by name and separately from the census, because this is the specific thing
     * `INV-010` excludes and it should fail loudly rather than as one entry in a list.
     *
     * @var list<string>
     */
    private const STOCK_BEARING_TABLES = ['products', 'product_variants', 'stock_movements'];

    /** @return list<string> every host and vendored migration file */
    private function migrationFiles(): array
    {
        $root = dirname(__DIR__, 3);

        $files = array_merge(
            glob($root . '/database/migrations/*.php') ?: [],
            glob($root . '/packages/workdo/*/src/database/migrations/*.php') ?: [],
            glob($root . '/packages/workdo/*/src/Database/Migrations/*.php') ?: []
        );

        sort($files);

        return $files;
    }

    /**
     * THE PREMISE. A census over zero files is clean, and would read exactly like a pass.
     *
     * CLAUDE.md §3's standing rule: add a premise test for whichever half you depend on. Both
     * globs are asserted non-trivially so that a directory rename -- `database/migrations` ->
     * something else, or the vendored tree moving -- fails here and says so, rather than turning
     * every assertion below into a tautology.
     */
    public function test_the_census_reads_a_real_and_substantial_set_of_migrations(): void
    {
        $root = dirname(__DIR__, 3);

        $host = glob($root . '/database/migrations/*.php') ?: [];
        $vendored = array_merge(
            glob($root . '/packages/workdo/*/src/database/migrations/*.php') ?: [],
            glob($root . '/packages/workdo/*/src/Database/Migrations/*.php') ?: []
        );

        $this->assertGreaterThan(
            100,
            count($host),
            'The host migration directory should hold well over a hundred files; a near-empty '
            . 'result means the census is scanning the wrong path and every other assertion here '
            . 'is vacuous.'
        );

        $this->assertGreaterThan(
            50,
            count($vendored),
            'The vendored packages should contribute dozens of migrations.'
        );
    }

    /**
     * No table in either tree names batch, lot or expiry storage, bar the declared exceptions.
     */
    public function test_no_migration_creates_a_batch_lot_or_expiry_table(): void
    {
        $found = [];

        foreach ($this->migrationFiles() as $file) {
            $code = php_strip_whitespace($file);

            if (preg_match_all("/Schema::(?:create|table)\(\s*'([^']+)'/", $code, $matches) === 0) {
                continue;
            }

            foreach ($matches[1] as $table) {
                if (preg_match(self::CANDIDATE, $table) === 1
                    && ! array_key_exists($table, self::PERMITTED_TABLES)) {
                    $found[$table][] = basename($file);
                }
            }
        }

        $this->assertSame(
            [],
            $found,
            "INV-010 excludes Batch/Lot and expiry tracking (EX-004). A table whose name matches "
            . "appeared and is not declared in PERMITTED_TABLES. If this is genuinely not lot "
            . "tracking, declare it with a reason. If it IS, INV-010 has been violated and needs "
            . "an owner decision, not an allowlist entry:\n"
            . json_encode($found, JSON_PRETTY_PRINT)
        );
    }

    /**
     * No column in either tree names batch, lot or expiry storage, bar the declared exceptions.
     */
    public function test_no_migration_declares_a_batch_lot_or_expiry_column(): void
    {
        $found = [];

        foreach ($this->migrationFiles() as $file) {
            $code = php_strip_whitespace($file);

            // `$table->string('x')`, `->date('x')`, `->index('x')` and every other Blueprint call
            // whose first argument is a quoted name. Deliberately not restricted to a list of
            // column types: a new Blueprint method would otherwise be a blind spot.
            if (preg_match_all("/->\s*[a-zA-Z]+\(\s*'([^']+)'/", $code, $matches) === 0) {
                continue;
            }

            foreach ($matches[1] as $column) {
                if (preg_match(self::CANDIDATE, $column) === 1
                    && ! array_key_exists($column, self::PERMITTED_COLUMNS)) {
                    $found[$column][] = basename($file);
                }
            }
        }

        $this->assertSame(
            [],
            $found,
            "INV-010 excludes Batch/Lot and expiry tracking (EX-004). A column whose name matches "
            . "appeared and is not declared in PERMITTED_COLUMNS:\n"
            . json_encode($found, JSON_PRETTY_PRINT)
        );
    }

    /**
     * THE NARROW, LOUD ASSERTION: the tables that hold stock hold no lot or expiry column.
     *
     * The two censuses above would already catch this, but as one line in a list. Lot tracking on
     * `products`, `product_variants` or `stock_movements` is the specific thing `INV-010` names, so
     * it gets an assertion that says so.
     */
    public function test_the_stock_bearing_tables_carry_no_lot_or_expiry_column(): void
    {
        $offenders = [];

        foreach ($this->migrationFiles() as $file) {
            $code = php_strip_whitespace($file);

            foreach (self::STOCK_BEARING_TABLES as $table) {
                // Everything from this table's Schema block to the end of the file. Over-wide on
                // purpose: a false positive here is a declared exception away, a false negative is
                // a violated decision nobody sees.
                $position = strpos($code, "Schema::create('" . $table . "'");
                if ($position === false) {
                    $position = strpos($code, "Schema::table('" . $table . "'");
                }
                if ($position === false) {
                    continue;
                }

                $block = substr($code, $position);

                if (preg_match_all("/->\s*[a-zA-Z]+\(\s*'([^']+)'/", $block, $matches) === 0) {
                    continue;
                }

                foreach ($matches[1] as $column) {
                    if (preg_match(self::CANDIDATE, $column) === 1
                        && ! array_key_exists($column, self::PERMITTED_COLUMNS)) {
                        $offenders[$table][] = $column . ' (' . basename($file) . ')';
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'INV-010 / EX-004: a stock-bearing table gained a batch, lot or expiry column. '
            . "This is the exclusion itself, so it is an owner escalation and not an allowlist "
            . "entry:\n" . json_encode($offenders, JSON_PRETTY_PRINT)
        );
    }

    /**
     * The allowlists are MINIMAL: every declared exception still matches the pattern.
     *
     * Without this, the allowlist is a place to park a name that no longer matches -- and a stale
     * entry is how a future census quietly loses its teeth. An entry whose name stops matching
     * should be deleted, and this says so.
     */
    public function test_every_declared_exception_still_matches_the_pattern_it_excuses(): void
    {
        foreach (array_keys(self::PERMITTED_TABLES) as $table) {
            $this->assertSame(
                1,
                preg_match(self::CANDIDATE, $table),
                "PERMITTED_TABLES carries '$table', which no longer matches the census pattern. "
                . 'Delete the entry rather than leaving it.'
            );
        }

        foreach (array_keys(self::PERMITTED_COLUMNS) as $column) {
            $this->assertSame(
                1,
                preg_match(self::CANDIDATE, $column),
                "PERMITTED_COLUMNS carries '$column', which no longer matches the census pattern."
            );
        }
    }

    /**
     * The pattern does not match the English words that merely CONTAIN one of the roots.
     *
     * A census that fired on `slot`, `ballot` or `pilot_store` would be turned off within a week,
     * and a census somebody has turned off protects nothing. Asserted in both directions so that
     * widening the pattern later has to be deliberate.
     */
    public function test_the_pattern_is_word_boundaried_in_both_directions(): void
    {
        foreach (['slot', 'time_slot', 'ballot', 'pilot', 'plot_id', 'allotment', 'batched_jobs'] as $innocent) {
            $this->assertSame(
                0,
                preg_match(self::CANDIDATE, $innocent),
                "'$innocent' is not batch/lot/expiry storage and must not match."
            );
        }

        foreach ([
            'lot_number', 'lot_no', 'batch_number', 'batch_id', 'expiry_date',
            'expiration_date', 'best_before', 'shelf_life', 'product_lot', 'stock_batch',
        ] as $guilty) {
            $this->assertSame(
                1,
                preg_match(self::CANDIDATE, $guilty),
                "'$guilty' names exactly what INV-010 excludes and must match."
            );
        }
    }
}
