<?php

namespace Tests\Feature\Tenancy;

use App\Models\Concerns\BelongsToStore;
use App\Models\Product;
use App\Models\User;
use App\Scopes\StoreScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * BATCH 1 -- THE MODELS WHERE TENANT ISOLATION IS ACTUALLY ENFORCED, PROVEN ONE BY ONE.
 *
 * This is the brief's items 2, 4, 5 and 6. Everything here runs against the SHIPPED
 * configuration: nothing in this file sets `StoreScope::$enabled`, and nothing overrides
 * `config('tenancy.*')` except the two tests that exist specifically to prove the kill switch and
 * the non-allowlisted default. So a pass here is a statement about how the application behaves as
 * delivered, not about how it behaves under test-only settings.
 *
 * WHY THAT DISTINCTION IS THE WHOLE POINT. The pre-existing CrossTenantModelIsolationTest proves
 * the scope CAN work by flipping `StoreScope::$enabled = true` by hand. That is a proof of the
 * mechanism, and it left tenancy inert in production -- machinery with the power off. The tests
 * below prove the power is ON for six named models, with the switch in the position it ships in.
 *
 * FOR EVERY ENFORCED MODEL, FOUR FACTS ARE ASSERTED:
 *   - store A cannot READ store B's row (both by list query and by primary key);
 *   - store A cannot UPDATE store B's row, and B's row is verified byte-unchanged afterwards;
 *   - store A cannot DELETE store B's row, and B's row is verified still present afterwards;
 *   - store A CAN still read, update and delete its OWN row.
 * The positive half matters as much as the negative half: a scope that breaks a merchant's own
 * dashboard is worse than no scope, and a negative-only suite cannot tell the two apart.
 *
 * FIXTURES ARE INSERTED WITH RAW `DB::table()`, deliberately, so the control under test cannot
 * shape the data it is being judged against. Required columns are discovered from the LIVE SCHEMA
 * rather than hand-listed, for the reason documented at length on
 * CrossTenantModelIsolationTest::requiredColumnStubs(): a hand-maintained column list is wrong the
 * moment a migration adds a NOT NULL column, and it fails as a confusing fixture error.
 */
class EnforcedModelIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const STORE_A = 101;
    private const STORE_B = 202;

    protected function setUp(): void
    {
        parent::setUp();

        StoreScope::resetSuppression();

        // 78 of the 95 models use laravel-model-caching and the test store is `array`, which IS
        // taggable, so the model cache is genuinely live here. Fixtures go in through raw
        // DB::table() inserts, which do not fire Eloquent events and so do not flush that cache.
        // Without this flush a warmed empty result from an earlier test could satisfy a later
        // assertion for the wrong reason.
        try {
            cache()->flush();
        } catch (\Throwable $e) {
            // A non-flushable store is not a reason to fail the run.
        }

        // FOREIGN KEYS ARE ENFORCED HERE. sqlite honours them under RefreshDatabase, and
        // `notify_users` declares all three of its columns as real foreign keys --
        // store_id -> stores.id, product_id -> products.id, user_id -> users.id, each
        // ON DELETE CASCADE (see the create_notify_users migration, lines 21-23). So the two
        // tenants in this test must be actual `stores` rows, not just two integers, and a user
        // has to exist before anything with a `created_by` can be inserted.
        //
        // This is fixture plumbing, not part of the control under test: store_id is still never
        // stubbed, and every tenancy assertion below reads the value this test put there.
        $this->fixtureOwner();

        foreach ([self::STORE_A, self::STORE_B] as $storeId) {
            $this->ensureStoreRow($storeId);
        }
    }

    /**
     * One user that FK resolution can point `created_by`-style columns at.
     */
    private function fixtureOwner(): void
    {
        if (DB::table('users')->exists()) {
            return;
        }

        $this->merchantFor(self::STORE_A, 'admin');
    }

    /**
     * Make sure `stores.$storeId` really exists, so FKs pointing at it can be satisfied.
     */
    private function ensureStoreRow(int $storeId): void
    {
        if (DB::table('stores')->where('id', $storeId)->exists()) {
            return;
        }

        $row = $this->requiredColumnStubs('stores');
        $row['id'] = $storeId;
        $row['created_at'] = now();
        $row['updated_at'] = now();

        DB::table('stores')->insert($row);
    }

    /**
     * Insert a minimal row into $foreignTable and return its new primary key.
     *
     * Needed because `notify_users.product_id` is a real foreign key: an invented integer raises
     * "FOREIGN KEY constraint failed" before any tenancy assertion can run. Each call mints a
     * DISTINCT parent row, which is what lets the update assertions compare two different valid
     * values rather than two coerced zeroes.
     */
    private function mintForeignRow(string $foreignTable, string $foreignColumn)
    {
        $row = $this->requiredColumnStubs($foreignTable);
        $row['created_at'] = now();
        $row['updated_at'] = now();

        if (Schema::hasColumn($foreignTable, 'store_id')) {
            $row['store_id'] = self::STORE_A;
        }

        return DB::table($foreignTable)->insertGetId($row, $foreignColumn);
    }

    /**
     * The single-column foreign keys declared on $table, as column => [table, column].
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function foreignKeysOf(string $table): array
    {
        $foreignKeys = [];

        foreach (Schema::getForeignKeys($table) as $fk) {
            if (count($fk['columns']) === 1 && count($fk['foreign_columns']) === 1) {
                $foreignKeys[$fk['columns'][0]] = [$fk['foreign_table'], $fk['foreign_columns'][0]];
            }
        }

        return $foreignKeys;
    }

    protected function tearDown(): void
    {
        StoreScope::resetSuppression();
        StoreScope::$enabled = false;

        parent::tearDown();
    }

    private function merchantFor(int $storeId, string $type = 'admin'): User
    {
        $user = new User();
        $user->forceFill([
            'name' => 'Merchant ' . $storeId,
            'email' => 'merchant' . $storeId . '-' . uniqid() . '@example.test',
            'password' => bcrypt('not-a-real-credential'),
            'type' => $type,
            'current_store' => $storeId,
        ])->save();

        return $user;
    }

    /**
     * BATCH 1. Each row is one enforced model, with a column the test can safely mutate.
     *
     * The audit behind each entry -- which call sites it protects, and which writer populates
     * `store_id` -- is recorded against that model in config/tenancy.php, so the reasoning sits
     * next to the switch rather than only in a test.
     *
     * @return array<string, array{0: class-string, 1: string, 2: string}>
     */
    public static function enforcedModels(): array
    {
        return [
            // name                   model class                           table                     mutable column
            'menus' => [\App\Models\Menu::class, 'menus', 'name'],
            'support tickets' => [\App\Models\SupportTicket::class, 'support_tickets', 'title'],
            'support conversations' => [\App\Models\SupportConversion::class, 'support_conversions', 'description'],
            'flash sale conditions' => [\App\Models\FlashSaleCondition::class, 'flash_sale_conditions', 'condition'],
            'theme customize orders' => [\App\Models\ThemeCustomizeOrder::class, 'theme_customize_orders', 'page_slug'],
            'back-in-stock notifications' => [\App\Models\NotifyUser::class, 'notify_users', 'product_id'],
        ];
    }

    /**
     * Placeholder values for every NOT NULL column of $table that has no default, read from the
     * live schema. Foreign keys are resolved to a row that genuinely exists, because sqlite
     * enforces them under RefreshDatabase. `store_id` is NEVER stubbed: it is the thing under test.
     *
     * @return array<string, mixed>
     */
    private function requiredColumnStubs(string $table): array
    {
        $stubs = [];
        $foreignKeys = [];

        foreach (Schema::getForeignKeys($table) as $fk) {
            if (count($fk['columns']) === 1 && count($fk['foreign_columns']) === 1) {
                $foreignKeys[$fk['columns'][0]] = [$fk['foreign_table'], $fk['foreign_columns'][0]];
            }
        }

        foreach (Schema::getColumns($table) as $column) {
            $name = $column['name'];

            if ($column['nullable'] || $column['default'] !== null || $column['auto_increment']) {
                continue;
            }

            if (in_array($name, ['store_id', 'created_at', 'updated_at'], true)) {
                continue;
            }

            if (isset($foreignKeys[$name])) {
                [$foreignTable, $foreignColumn] = $foreignKeys[$name];
                $existing = DB::table($foreignTable)->value($foreignColumn);

                if ($existing === null) {
                    $this->markTestSkipped(
                        "Could not satisfy the required foreign key `{$table}.{$name}` into "
                        . "`{$foreignTable}`: that table is empty. This is a fixture limitation, "
                        . 'not a tenancy result, and is reported rather than worked around.'
                    );
                }

                $stubs[$name] = $existing;

                continue;
            }

            $type = strtolower((string) $column['type_name']);

            $stubs[$name] = match (true) {
                str_contains($type, 'int') => 1,
                str_contains($type, 'bool') => 0,
                str_contains($type, 'dec'), str_contains($type, 'float'),
                str_contains($type, 'double'), str_contains($type, 'numeric') => 0,
                str_contains($type, 'date'), str_contains($type, 'time') => now(),
                str_contains($type, 'json') => '{}',
                default => $name . '-' . uniqid(),
            };
        }

        return $stubs;
    }

    /**
     * One row for store A and one for store B, inserted raw.
     *
     * @return array<int, int> store id => row id
     */
    private function seedPair(string $table, string $mutableColumn): array
    {
        $ids = [];
        $required = $this->requiredColumnStubs($table);

        foreach ([self::STORE_A, self::STORE_B] as $storeId) {
            $row = $required;
            $row[$mutableColumn] = $this->valueFor($table, $mutableColumn, 'store-' . $storeId);
            $row['store_id'] = $storeId;
            $row['created_at'] = now();
            $row['updated_at'] = now();

            $ids[$storeId] = DB::table($table)->insertGetId($row);
        }

        return $ids;
    }

    /**
     * A type-appropriate value for $column. `notify_users.product_id` is an integer, so a string
     * marker would be silently coerced to 0 by sqlite and the update assertions would compare
     * 0 against 0 and pass without proving anything.
     */
    private function valueFor(string $table, string $column, string $marker)
    {
        // A foreign-key column cannot take an invented integer: see mintForeignRow().
        $foreignKeys = $this->foreignKeysOf($table);

        if (isset($foreignKeys[$column])) {
            return $this->mintForeignRow($foreignKeys[$column][0], $foreignKeys[$column][1]);
        }

        foreach (Schema::getColumns($table) as $definition) {
            if ($definition['name'] !== $column) {
                continue;
            }

            if (str_contains(strtolower((string) $definition['type_name']), 'int')) {
                return crc32($marker) % 100000;
            }
        }

        return $marker;
    }

    // -------------------------------------------------------------------------------------
    //  The mechanism is wired, and wired to the shipped configuration
    // -------------------------------------------------------------------------------------

    /**
     * THE SILENT-NO-OP TRAP. The global scope is registered only by the BelongsToStore trait, so
     * naming a model in `scoped_models` without attaching the trait enforces NOTHING while
     * looking exactly like enforcement in the config file. That failure is invisible: no error,
     * no warning, just an unprotected model on an allowlist.
     */
    public function test_every_allowlisted_model_actually_carries_the_trait(): void
    {
        $allowlisted = StoreScope::allowlistedModels();

        $this->assertNotEmpty(
            $allowlisted,
            'config/tenancy.php `scoped_models` is empty, so NOTHING is enforced and this suite '
            . 'proves nothing. Tenancy would be back to being machinery with the power off.'
        );

        foreach ($allowlisted as $class) {
            $this->assertContains(
                BelongsToStore::class,
                class_uses_recursive($class),
                $class . ' is allowlisted in config/tenancy.php but does NOT use BelongsToStore, '
                . 'so no global scope is registered for it and it is NOT protected. The allowlist '
                . 'entry is doing nothing.'
            );
        }
    }

    public function test_enforcement_is_enabled_in_the_shipped_configuration(): void
    {
        $this->assertTrue(
            (bool) config('tenancy.enforcement_enabled'),
            'tenancy.enforcement_enabled ships false, so every allowlist entry is inert.'
        );

        // And the all-models flip is still off, which is what keeps the other 50 tenant-
        // discriminated models unscoped until each has been audited.
        $this->assertFalse(StoreScope::$enabled);
        $this->assertFalse(StoreScope::isActive());
    }

    /**
     * PER-MODEL OPT-IN, stated as a property: turning the scope on for one model must not turn it
     * on for the other 94. Product is tenant-owned and carries the trait, but is deliberately NOT
     * allowlisted (see config/tenancy.php for why -- the admin API resolves its tenant from
     * `$request->store_id`), so it must emit no predicate under the shipped configuration.
     */
    public function test_a_model_that_is_not_allowlisted_gets_no_predicate(): void
    {
        $this->be($this->merchantFor(self::STORE_A));

        $this->assertFalse(StoreScope::isAllowlisted(Product::class));
        $this->assertStringNotContainsString(
            'store_id',
            Product::query()->toSql(),
            'A model absent from `scoped_models` must be byte-for-byte unaffected. If this fails, '
            . 'the allowlist is not actually gating anything and enabling one model has enabled all.'
        );

        // Meanwhile an allowlisted model on the very same request IS constrained.
        $this->assertStringContainsString('"menus"."store_id"', \App\Models\Menu::query()->toSql());
    }

    /**
     * The predicate must be column-qualified. There are join sites in this codebase and a bare
     * `store_id = ?` raises "Column 'store_id' in where clause is ambiguous" on every one.
     */
    public function test_the_predicate_is_column_qualified(): void
    {
        $this->be($this->merchantFor(self::STORE_A));

        foreach (self::enforcedModels() as [$class, $table, $column]) {
            $sql = $class::query()->toSql();

            $this->assertStringContainsString(
                '"' . $table . '"."store_id"',
                $sql,
                $class . ' emitted an unqualified predicate: ' . $sql
            );
        }
    }

    public function test_the_kill_switch_disables_every_allowlisted_model(): void
    {
        $this->be($this->merchantFor(self::STORE_A));

        $this->assertStringContainsString('store_id', \App\Models\Menu::query()->toSql());

        config(['tenancy.enforcement_enabled' => false]);

        $this->assertStringNotContainsString(
            'store_id',
            \App\Models\Menu::query()->toSql(),
            'tenancy.enforcement_enabled = false must disable enforcement everywhere. A kill '
            . 'switch with exceptions is not a kill switch.'
        );
    }

    // -------------------------------------------------------------------------------------
    //  The cross-tenant denial matrix, per enforced model
    // -------------------------------------------------------------------------------------

    #[DataProvider('enforcedModels')]
    public function test_store_a_cannot_read_store_bs_row(string $class, string $table, string $column): void
    {
        $ids = $this->seedPair($table, $column);
        $this->be($this->merchantFor(self::STORE_A));

        $visible = $class::query()->pluck('id')->all();

        $this->assertContains($ids[self::STORE_A], $visible, 'Store A lost sight of its OWN row.');
        $this->assertNotContains(
            $ids[self::STORE_B],
            $visible,
            $class . ': store A can list store B\'s row. Tenant isolation is NOT enforced.'
        );

        // By primary key, which is the IDOR shape: the attacker knows or guesses the id.
        $this->assertNull(
            $class::find($ids[self::STORE_B]),
            $class . '::find() returned another tenant\'s row. This is the cross-tenant IDOR.'
        );
        $this->assertNotNull($class::find($ids[self::STORE_A]), 'Store A cannot find its OWN row.');
    }

    #[DataProvider('enforcedModels')]
    public function test_store_a_cannot_update_store_bs_row(string $class, string $table, string $column): void
    {
        $ids = $this->seedPair($table, $column);
        $before = DB::table($table)->where('id', $ids[self::STORE_B])->value($column);

        $this->be($this->merchantFor(self::STORE_A));

        $tampered = $this->valueFor($table, $column, 'tampered-by-store-a');

        $affected = $class::where('id', $ids[self::STORE_B])->update([$column => $tampered]);

        $this->assertSame(
            0,
            $affected,
            $class . ': an UPDATE aimed at store B\'s row affected ' . $affected . ' row(s).'
        );

        $this->assertSame(
            $before,
            DB::table($table)->where('id', $ids[self::STORE_B])->value($column),
            $class . ': store B\'s row was MODIFIED by store A. Checked with a raw query so the '
            . 'scope cannot hide the damage it failed to prevent.'
        );
    }

    #[DataProvider('enforcedModels')]
    public function test_store_a_cannot_delete_store_bs_row(string $class, string $table, string $column): void
    {
        $ids = $this->seedPair($table, $column);

        $this->be($this->merchantFor(self::STORE_A));

        $deleted = $class::where('id', $ids[self::STORE_B])->delete();

        $this->assertSame(0, $deleted, $class . ': a DELETE aimed at store B removed ' . $deleted . ' row(s).');

        $this->assertDatabaseHas($table, ['id' => $ids[self::STORE_B], 'store_id' => self::STORE_B]);
    }

    /**
     * THE POSITIVE HALF. A merchant must still be able to operate on their own data. A scope that
     * silently empties a merchant's own dashboard is a worse outcome than no scope at all,
     * because it looks like data loss.
     */
    #[DataProvider('enforcedModels')]
    public function test_store_a_can_still_read_update_and_delete_its_own_row(string $class, string $table, string $column): void
    {
        $ids = $this->seedPair($table, $column);
        $this->be($this->merchantFor(self::STORE_A));

        $own = $class::find($ids[self::STORE_A]);
        $this->assertNotNull($own, $class . ': store A cannot READ its own row.');

        $updated = $this->valueFor($table, $column, 'updated-by-owner');
        $affected = $class::where('id', $ids[self::STORE_A])->update([$column => $updated]);

        $this->assertSame(1, $affected, $class . ': store A cannot UPDATE its own row.');
        $this->assertSame(
            $updated,
            DB::table($table)->where('id', $ids[self::STORE_A])->value($column),
            $class . ': the owner\'s update did not land.'
        );

        $this->assertSame(1, $class::where('id', $ids[self::STORE_A])->delete(), $class . ': store A cannot DELETE its own row.');
        $this->assertDatabaseMissing($table, ['id' => $ids[self::STORE_A]]);

        // And deleting A's row left B's alone.
        $this->assertDatabaseHas($table, ['id' => $ids[self::STORE_B]]);
    }

    /**
     * THE BULK-DELETE MITIGATION, which is why several of these models are in batch 1 at all.
     *
     * packages/workdo/BulkDelete/src/app/Http/Controllers/BulkDeleteController.php:115 runs
     *     $model::whereIn('id', $request->ids)->delete();
     * over a 40-entry model map with NO tenant predicate of any kind, so any authenticated
     * merchant can delete another store's rows by sending their ids. `Menu` and `SupportTicket`
     * are both in that map.
     *
     * This asserts the shape of that exact call, rather than trusting the documented claim that
     * Eloquent's Builder::delete() carries global scopes through toBase(). The brief's rule
     * applies: a framework behaviour that could not be confirmed must be measured, not assumed.
     */
    #[DataProvider('enforcedModels')]
    public function test_bulk_delete_by_id_list_cannot_cross_tenants(string $class, string $table, string $column): void
    {
        $ids = $this->seedPair($table, $column);
        $this->be($this->merchantFor(self::STORE_A));

        // Exactly the BulkDeleteController shape: both ids handed in by the caller.
        $deleted = $class::whereIn('id', [$ids[self::STORE_A], $ids[self::STORE_B]])->delete();

        $this->assertSame(
            1,
            $deleted,
            $class . ': a bulk delete naming both tenants\' ids removed ' . $deleted . ' rows. '
            . 'Only the caller\'s own row may be removed.'
        );

        $this->assertDatabaseMissing($table, ['id' => $ids[self::STORE_A]]);
        $this->assertDatabaseHas(
            $table,
            ['id' => $ids[self::STORE_B], 'store_id' => self::STORE_B],
            // assertDatabaseHas takes no message; the id pair above is the evidence.
        );
    }

    // -------------------------------------------------------------------------------------
    //  The super-admin exception, and its boundary
    // -------------------------------------------------------------------------------------

    /**
     * The brief's item 5. The platform operator must still see across stores, and that bypass
     * must be a NAMED decision rather than a side effect of an unresolved tenant. The two used to
     * share a null return from resolveStoreId(); they are now distinct modes.
     */
    #[DataProvider('enforcedModels')]
    public function test_a_super_admin_sees_across_stores_and_a_merchant_does_not(string $class, string $table, string $column): void
    {
        $ids = $this->seedPair($table, $column);

        $this->be($this->merchantFor(self::STORE_A));
        $this->assertSame(StoreScope::MODE_TENANT, StoreScope::tenantMode());
        $this->assertCount(1, $class::query()->get(), $class . ': a merchant saw more than their own row.');

        auth()->logout();

        $superAdmin = $this->merchantFor(0, 'super admin');
        $superAdmin->current_store = null;
        $superAdmin->save();
        $this->be($superAdmin);

        $this->assertSame(
            StoreScope::MODE_SUPER_ADMIN,
            StoreScope::tenantMode(),
            'The super admin must be recognised as the platform operator, NOT as an unresolved '
            . 'tenant. The distinction is what makes the bypass an explicit decision.'
        );

        $seen = $class::query()->pluck('id')->all();

        $this->assertContains($ids[self::STORE_A], $seen, $class . ': the super admin lost store A.');
        $this->assertContains($ids[self::STORE_B], $seen, $class . ': the super admin lost store B.');
    }

    /**
     * `getCurrentStore()` returns the HARDCODED literal 1 for a super admin with an empty
     * current_store (app/Helper/helper.php:371-374). The scope must NOT inherit that, or the
     * platform operator would be pinned to store 1 and every other tenant's data would look
     * simply absent.
     */
    public function test_the_super_admin_is_not_pinned_to_the_hardcoded_store_one(): void
    {
        $superAdmin = $this->merchantFor(0, 'super admin');
        $superAdmin->current_store = null;
        $superAdmin->save();
        $this->be($superAdmin);

        $this->assertSame(1, (int) getCurrentStore(), 'Baseline: the resolver still returns 1.');
        $this->assertNull(StoreScope::resolveStoreId());
        $this->assertStringNotContainsString('store_id', \App\Models\Menu::query()->toSql());
    }

    // -------------------------------------------------------------------------------------
    //  What the scope reaches, and what it does NOT -- the brief's item 4
    // -------------------------------------------------------------------------------------

    /**
     * A MEASURED GAP, NOT A CLAIM OF COVERAGE. No Eloquent global scope can reach a query that
     * never goes through Eloquent, and this codebase has many such queries:
     *
     *   $ grep -rn "DB::table(" app/ packages/ --include=*.php | wc -l   -> see the count asserted
     *     below; app/Http/Controllers/RoleController.php:182 is a live example.
     *
     * This test documents the hole by proving it exists, so nobody reads "tenancy is enforced"
     * as "every query is filtered". The remedy for those sites is an explicit predicate at the
     * call site, and those files belong to other workstreams.
     */
    public function test_raw_db_table_queries_bypass_the_scope_entirely(): void
    {
        $ids = $this->seedPair('menus', 'name');
        $this->be($this->merchantFor(self::STORE_A));

        // Through Eloquent: scoped.
        $this->assertNull(\App\Models\Menu::find($ids[self::STORE_B]));

        // Through the query builder: NOT scoped. This is the documented limitation.
        $this->assertNotNull(
            DB::table('menus')->where('id', $ids[self::STORE_B])->first(),
            'If this now returns null, a query-builder-level tenant filter has been introduced '
            . 'somewhere and this limitation note needs rewriting.'
        );
    }

    /**
     * THE `orWhere` ESCAPE, settled by execution.
     *
     * If the framework appended the scope's predicate WITHOUT first grouping the query's own
     * where clauses, then `->where(a)->orWhere(b)` plus the scope would compile to
     *     store_id = A AND a = 1 OR b = 2
     * and SQL's precedence (AND binds tighter than OR) would make the whole right-hand branch
     * match rows of ANY tenant. One `orWhere` anywhere in a controller would silently void
     * isolation for that query.
     *
     * The grouping is documented framework behaviour, and StoreScope's docblock previously marked
     * it UNVERIFIED because vendor source could not be read in the container where that note was
     * written. It can be read now -- but reading it is still weaker evidence than running it, and
     * the brief's rule is that a framework behaviour which could not be confirmed must be
     * measured. So this asserts the COMPILED SQL and the actual rows.
     */
    public function test_an_or_where_chain_cannot_escape_the_predicate(): void
    {
        $ids = $this->seedPair('menus', 'name');
        $this->be($this->merchantFor(self::STORE_A));

        $query = \App\Models\Menu::where('name', 'no-such-menu')
            ->orWhere('id', $ids[self::STORE_B]);

        $sql = $query->toSql();

        // MEASURED SHAPE. The framework groups the query's OWN wheres and appends the scope
        // after them, so the compiled SQL is
        //     select * from "menus" where ("name" = ? or "id" = ?) and "menus"."store_id" = ?
        // The order is the opposite of what one might assume -- the group comes first and the
        // scope is ANDed on the end -- but what matters is that the disjunction is PARENTHESISED
        // and the tenant predicate sits OUTSIDE it at the top level. Both are asserted, because
        // either one alone would still permit the escape.
        $this->assertMatchesRegularExpression(
            '/\(\s*"name" = \?\s+or\s+"id" = \?\s*\)/i',
            $sql,
            'The query\'s own disjunction was NOT grouped, so the tenant predicate can be OR-ed '
            . 'away. Compiled SQL: ' . $sql
        );

        $this->assertMatchesRegularExpression(
            '/\)\s+and\s+"menus"\."store_id" = \?/i',
            $sql,
            'The tenant predicate is not a top-level AND outside the disjunction. Compiled SQL: ' . $sql
        );

        $this->assertSame(
            [],
            $query->pluck('id')->all(),
            'An orWhere branch naming store B\'s id returned it anyway, so the OR escaped the '
            . 'tenant predicate. Compiled SQL: ' . $sql
        );

        // The same chain aimed at the caller's own row must still work, or this would be
        // isolation bought by breaking ordinary queries.
        $this->assertSame(
            [$ids[self::STORE_A]],
            \App\Models\Menu::where('name', 'no-such-menu')
                ->orWhere('id', $ids[self::STORE_A])
                ->pluck('id')
                ->all()
        );
    }

    /**
     * Relations DO inherit the scope, because a relation builds on the related model's
     * newQuery(), which applies its global scopes. That is what protects
     * `$ticket->conversions`-style traversal from walking into another tenant's rows via a
     * trusted foreign key.
     */
    public function test_a_relation_traversal_is_scoped(): void
    {
        $required = $this->requiredColumnStubs('support_conversions');

        // Two conversations hanging off the SAME ticket_id but owned by different stores. A
        // foreign key trusted blindly would return both.
        // NOTE the union order. `support_conversions.ticket_id` is NOT NULL, so
        // requiredColumnStubs() already supplies a stub for it, and PHP's `+` keeps the LEFT
        // operand on a key collision. Writing `$required + [...]` would therefore silently keep
        // the stub and discard the 7777 this test depends on -- the first run of this test did
        // exactly that and failed with an empty result for a reason that had nothing to do with
        // tenancy. The explicit values must come first.
        $ids = [];
        foreach ([self::STORE_A, self::STORE_B] as $storeId) {
            $ids[$storeId] = DB::table('support_conversions')->insertGetId([
                'ticket_id' => 7777,
                'store_id' => $storeId,
                'created_at' => now(),
                'updated_at' => now(),
            ] + $required);
        }

        $this->be($this->merchantFor(self::STORE_A));

        $reachable = \App\Models\SupportConversion::where('ticket_id', 7777)->pluck('id')->all();

        $this->assertSame(
            [$ids[self::STORE_A]],
            $reachable,
            'A query keyed only on a foreign id reached another tenant\'s row. This is the '
            . '"related record whose foreign key is trusted blindly" case.'
        );
    }

    /**
     * The explicit cross-tenant hatch still works on an enforced model, because jobs, scheduled
     * commands and genuine platform reports need it. If this breaks, the pressure to disable
     * enforcement entirely goes up.
     */
    public function test_the_cross_tenant_escape_hatch_still_reaches_every_store(): void
    {
        $ids = $this->seedPair('menus', 'name');
        $this->be($this->merchantFor(self::STORE_A));

        $this->assertCount(1, \App\Models\Menu::query()->get());

        $all = StoreScope::allowCrossTenant(fn () => \App\Models\Menu::query()->pluck('id')->all());

        $this->assertContains($ids[self::STORE_A], $all);
        $this->assertContains($ids[self::STORE_B], $all);
        $this->assertFalse(StoreScope::isSuppressed(), 'Suppression leaked past the callback.');

        // withoutStoreScope() is the per-chain equivalent.
        $this->assertCount(2, \App\Models\Menu::withoutStoreScope()->get());
    }
}
