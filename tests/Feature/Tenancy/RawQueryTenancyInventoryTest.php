<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Security\SecurityTestCase;

/**
 * R4-05, THE RAW-QUERY HALF -- the inventory the brief asks to be continued.
 *
 * NO ELOQUENT GLOBAL SCOPE CAN EVER REACH THESE SITES. `DB::table()` and friends build their own
 * query from nothing, so `App\Scopes\StoreScope` is not consulted -- proven by execution rather
 * than assumed in EnforcedModelIsolationTest::test_raw_db_table_queries_bypass_the_scope_entirely.
 * The only possible remedy at such a site is an explicit `store_id` predicate written there, and
 * the files those sites live in belong to other workstreams, so THE INVENTORY IS THE DELIVERABLE.
 *
 * ResidualTenancyExposureTest already inventories the raw sites in THIS workstream's own files,
 * at file:line precision. This suite covers the rest of the codebase -- app/ and packages/ -- at
 * PER-TABLE precision, which is the right granularity for a set this size: a file:line ratchet
 * over 136 sites in other agents' files would go red on every unrelated edit above them and
 * would be deleted within a phase.
 *
 * ============================================================================================
 *  WHAT IS MEASURED, AND THE TWO LIMITS OF THE MEASUREMENT, STATED UP FRONT.
 * ============================================================================================
 *
 * For each `DB::table('literal')` site, the whole STATEMENT is read -- the matched line plus
 * following lines up to the first `;`, capped at 25 lines -- and classified by whether the word
 * `store_id` appears anywhere in it. Comment and docblock lines are skipped so documentation
 * cannot satisfy its own ratchet.
 *
 * LIMIT 1. `store_id` appearing in the statement does NOT prove the predicate is CORRECT. It
 * proves only that the author was aware of the discriminator. A site that filters on a store id
 * taken from the request without an ownership check still has an IDOR, and this inventory counts
 * it as "aware". So the `no-store_id` column is a LOWER BOUND on the exposure, never an upper one.
 *
 * LIMIT 2. Only `DB::table('literal')` is attributable to a table. A site built from a variable
 * (`DB::table($table)`) cannot be, and those are counted in the overall raw-entry-point total
 * below but not in the per-table breakdown. The slug-uniqueness helper is exactly that shape and
 * is already inventoried at file:line in ResidualTenancyExposureTest.
 *
 * Neither limit is a reason not to measure. An exact, reproducible lower bound that moves when
 * the code moves is worth more than an estimate, and both limits are recorded so no reader can
 * mistake this for a clean bill of health.
 */
class RawQueryTenancyInventoryTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * Every raw query ENTRY POINT in app/ and packages/, regardless of whether its table is a
     * literal. `DB::raw()` is excluded on purpose: it is a select EXPRESSION inside an Eloquent
     * builder, and the surrounding query still carries its scopes, so conflating the two would
     * overstate this inventory.
     */
    public function test_the_raw_entry_point_totals_are_the_measured_one_hundred_and_fifty_six(): void
    {
        $perDir = [];

        foreach (['app', 'packages'] as $dir) {
            $perDir[$dir] = count($this->rawEntryPoints($dir));
        }

        $this->assertSame(['app' => 111, 'packages' => 45], $perDir,
            'The raw query-builder entry-point count moved. Every one of these is invisible to '
            . 'every Eloquent global scope, so a NEW site needs an explicit `store_id` predicate '
            . 'written at the call site -- there is no other remedy. A REMOVED one probably means '
            . 'a raw query became Eloquent, which is an improvement worth recording here.');

        $this->assertSame(156, array_sum($perDir));
    }

    /**
     * THE EXPOSURE, PER TABLE. One row per table reached by a literal `DB::table('x')`, with the
     * number of statements that never mention `store_id`.
     *
     * This is the ratchet that matters: if a table's `no-store_id` count GOES UP, a raw
     * cross-tenant query was just added somewhere no scope can see it.
     */
    public function test_the_per_table_raw_query_exposure_is_exactly_this(): void
    {
        $actual = [];

        foreach ($this->literalTableSites() as $table => $info) {
            $actual[$table] = [count($info['all']), count($info['unscoped'])];
        }

        $this->assertSame([
            // table                         [ total sites, sites with no `store_id` anywhere ]
            'coupon_requests' => [3, 3],
            'coupons' => [2, 0],
            'currency' => [3, 3],
            'customers' => [5, 3],
            'migrations' => [1, 1],
            'notify_users' => [2, 1],
            'order_billing_details' => [2, 1],
            'owner_menu_items' => [1, 1],
            'owner_menu_settings' => [1, 1],
            'owner_menus' => [1, 1],
            'personal_access_tokens' => [2, 2],
            'settings' => [15, 9],
            'shetabit_visits' => [16, 0],
            'side_menus_option' => [28, 0],
            'store_devices' => [2, 1],
            'store_order_financials' => [7, 3],
            'stores' => [18, 17],
            'theme_customizes' => [2, 0],
            'users' => [5, 5],
            'zid_events' => [2, 2],
            'zid_orders' => [11, 11],
            'zid_stores' => [7, 2],
        ], $actual, 'The per-table raw-query inventory changed. If a `no-store_id` count went UP, '
            . 'a raw cross-tenant query was added where no global scope can reach it.');

        $this->assertSame(136, array_sum(array_map(fn ($r) => $r[0], $actual)),
            '136 literal-table sites of the 156 entry points; the other 20 build their table name '
            . 'from a variable and so cannot be attributed (see LIMIT 2 in this class docblock).');
        $this->assertSame(67, array_sum(array_map(fn ($r) => $r[1], $actual)));
    }

    /**
     * WHICH OF THOSE TABLES REALLY ARE TENANT-DISCRIMINATED, read from the LIVE MIGRATED SCHEMA
     * rather than from a name or a model's $fillable.
     *
     * This is what separates a finding from a number. `users` has FIVE statements with no
     * `store_id` and that is correct -- `users` has no such column; membership is
     * `users.current_store` plus `users.created_by`. `migrations` and `personal_access_tokens`
     * are platform tables. Counting those as exposure would have inflated the finding by eight
     * sites, which is the kind of error this project has already made in both directions.
     */
    public function test_only_these_tables_are_both_tenant_discriminated_and_rawly_unscoped(): void
    {
        $discriminated = [];
        $notDiscriminated = [];
        $noSuchTable = [];

        foreach ($this->literalTableSites() as $table => $info) {
            if ($info['unscoped'] === []) {
                continue;
            }

            if (! Schema::hasTable($table)) {
                // ABSENT IS NOT THE SAME AS "no store_id", and must not be reported as either
                // safe or exposed. These are the undeclared-schema families: GATE F's six money
                // tables, the Zid tables, and the LandingPage module's owner_menu* tables whose
                // provider registers the wrong directory case (NEW-ADDON-SCHEMA-02).
                $noSuchTable[] = $table;

                continue;
            }

            if (Schema::hasColumn($table, 'store_id')) {
                $discriminated[$table] = count($info['unscoped']);
            } else {
                $notDiscriminated[] = $table;
            }
        }

        ksort($discriminated);
        sort($notDiscriminated);
        sort($noSuchTable);

        $this->assertSame([
            // THE REAL RAW-SQL TENANCY EXPOSURE, by table and statement count.
            //
            // `customers` is the one that matters most and the one this increment makes
            // visible: Customer is now an ALLOWLISTED Eloquent model, so its Eloquent reads are
            // constrained -- and these three raw statements are not, and cannot be. Enabling the
            // scope does not touch them. Reporting them together is the only honest account of
            // what "Customer is enforced" means.
            // (Alphabetical, because the list is ksort()ed before comparison.)
            'customers' => 3,
            // `notify_users` IS an allowlisted Eloquent model (NotifyUser), so the same caveat
            // applies to it as to `customers`: the scope governs its Eloquent reads and cannot
            // touch a raw statement.
            //
            // AND IT IS A FALSE POSITIVE, which is why LIMIT 1 in this class docblock cuts both
            // ways and is worth reading before acting on any number here. The one statement
            // counted is the INSERT at Api/ApiController.php:6524,
            // `DB::table('notify_users')->insert($values)`. `$values['store_id']` is assigned
            // six lines earlier at :6518, outside the statement this inventory reads, so the
            // write really does carry the discriminator. The paired READ at :6522 does filter on
            // `store_id` and is correctly counted as aware. Kept in the list rather than
            // special-cased, because an inventory with hand-carved exceptions stops being
            // reproducible -- the exception is recorded here instead.
            'notify_users' => 1,
            // `settings` is the widest: read on every page render through getAdminAllSetting()
            // (app/Helper/helper.php:350-382). It is also why Setting is NOT allowlisted.
            'settings' => 9,
            'store_devices' => 1,
        ], $discriminated, 'The set of tenant-discriminated tables reached by raw SQL without a '
            . '`store_id` predicate changed. Each statement here is an unconstrained cross-tenant '
            . 'query that NO global scope can reach; the remedy is an explicit predicate at the '
            . 'call site, in files this workstream does not own.');

        $this->assertSame([
            // MEASURED, not assumed from the name. `currency` and `order_billing_details` both
            // have a migration in database/migrations and NEITHER declares `store_id`, so their
            // unscoped statements are not tenancy findings -- which is exactly the distinction
            // that stops this inventory inflating the exposure. `order_billing_details` is
            // reached through the order it belongs to; `currency` is a platform rate table.
            'currency',
            'migrations',              // platform
            'order_billing_details',
            'personal_access_tokens',  // platform; tokens belong to a user, not a store
            'stores',                  // the tenant REGISTRY itself -- ownership, not scoping
            'users',                   // no store_id column at all; see the DO-NOT-SCOPE list
        ], $notDiscriminated, 'A table moved between "has store_id" and "does not". That changes '
            . 'what the 67 unscoped statements mean, so re-read the classification above.');

        $this->assertSame([
            // `coupon_requests` is declared only by a PACKAGE module
            // (packages/workdo/PDFVouchers/src/database/migrations/..._create_coupon_requests_table.php),
            // and PackageServiceProvider loads a module's migrations only when it is listed in
            // `add_on_managers`, which is empty in a fresh database (NEW-ADDON-SCHEMA-03). So the
            // table is absent HERE while existing in production -- its three unscoped statements
            // are unclassified rather than cleared.
            'coupon_requests',
            'owner_menu_items',
            'owner_menu_settings',
            'owner_menus',
            'store_order_financials',  // GATE F
            'zid_events',
            'zid_orders',
            'zid_stores',
        ], $noSuchTable, 'A table appeared or disappeared from the undeclared-schema set. If one '
            . 'now EXISTS, re-run this suite: its unscoped statements must move into one of the '
            . 'two classified lists above and may be a newly visible tenancy finding.');
    }

    // -------------------------------------------------------------------------------------
    //  Measurement
    // -------------------------------------------------------------------------------------

    private const RAW_ENTRY_POINT_PATTERN = '/\\\\?DB::(table|select|insert|update|delete|statement|unprepared)\s*\(/';

    private const LITERAL_TABLE_PATTERN = '/\\\\?DB::table\s*\(\s*[\'"]([a-zA-Z0-9_]+)[\'"]/';

    /** @return array<int, string> */
    private function rawEntryPoints(string $dir): array
    {
        $sites = [];

        foreach ($this->phpFiles($dir) as $file) {
            foreach ($this->codeLines($file) as $number => $line) {
                if (preg_match(self::RAW_ENTRY_POINT_PATTERN, $line)) {
                    $sites[] = $file . ':' . $number;
                }
            }
        }

        return $sites;
    }

    /**
     * @return array<string, array{all: array<int, string>, unscoped: array<int, string>}>
     */
    private function literalTableSites(): array
    {
        $byTable = [];

        foreach (['app', 'packages'] as $dir) {
            foreach ($this->phpFiles($dir) as $file) {
                $lines = file(base_path($file)) ?: [];

                foreach ($this->codeLines($file) as $number => $line) {
                    if (! preg_match(self::LITERAL_TABLE_PATTERN, $line, $match)) {
                        continue;
                    }

                    $table = $match[1];
                    $site = $file . ':' . $number;

                    $byTable[$table]['all'][] = $site;

                    if (! str_contains($this->statementAt($lines, $number - 1), 'store_id')) {
                        $byTable[$table]['unscoped'][] = $site;
                    }
                }
            }
        }

        foreach ($byTable as $table => $info) {
            $byTable[$table]['unscoped'] = $info['unscoped'] ?? [];
        }

        ksort($byTable);

        return $byTable;
    }

    /**
     * The whole statement starting at $index: that line plus following lines up to the first `;`.
     * Capped at 25 lines so a file without a semicolon cannot read to the end of itself.
     *
     * @param  array<int, string>  $lines
     */
    private function statementAt(array $lines, int $index): string
    {
        $statement = $lines[$index] ?? '';

        for ($i = $index + 1; $i < min($index + 25, count($lines)); $i++) {
            if (str_contains($statement, ';')) {
                break;
            }

            $statement .= $lines[$i];
        }

        return $statement;
    }

    /**
     * Executable lines of a file, keyed by 1-based line number. Comment and docblock lines are
     * skipped so that a docblock mentioning `DB::table()` -- including this very class's -- is
     * documentation rather than a counted query.
     *
     * @return array<int, string>
     */
    private function codeLines(string $relative): array
    {
        $lines = file(base_path($relative)) ?: [];
        $code = [];

        foreach ($lines as $index => $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//')
                || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '#')) {
                continue;
            }

            $code[$index + 1] = $line;
        }

        return $code;
    }

    /** @return array<int, string> relative paths */
    private function phpFiles(string $dir): array
    {
        $base = base_path($dir);

        if (! is_dir($base)) {
            return [];
        }

        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = ltrim(str_replace(base_path(), '', $file->getPathname()), '/');
            }
        }

        sort($files);

        return $files;
    }
}
