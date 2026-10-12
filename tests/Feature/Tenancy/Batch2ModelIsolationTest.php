<?php

namespace Tests\Feature\Tenancy;

use App\Models\Concerns\BelongsToStore;
use App\Models\User;
use App\Scopes\StoreScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * BATCH 2 -- THE FOUR MODELS ENFORCED BY THIS INCREMENT, PROVEN ONE BY ONE.
 *
 * OrderRefund, OrderNote, Customer and Cart. The audit behind each entry -- which call sites the
 * scope closes, which writer populates `store_id`, and why it is not storefront-public content --
 * is recorded against that model in config/tenancy.php, so the reasoning lives next to the switch
 * rather than only here.
 *
 * EVERYTHING HERE RUNS AGAINST THE SHIPPED CONFIGURATION. Nothing in this file sets
 * `StoreScope::$enabled` and nothing overrides `config('tenancy.*')`, so a pass is a statement
 * about how the application behaves as delivered. That distinction is the whole point: the older
 * CrossTenantModelIsolationTest proves the mechanism CAN work by flipping a static by hand, which
 * is machinery with the power off.
 *
 * TWO INDEPENDENT MERCHANTS AND TWO INDEPENDENT STORES, as the brief requires -- not one user
 * switching `current_store`. A single user with two stores cannot distinguish "the scope isolates
 * tenants" from "the scope follows whatever `current_store` says", and under the owned-stores
 * predicate those are genuinely different properties: merchant A owns store A and NOT store B, so
 * store B is inadmissible to A no matter what A's `current_store` is set to.
 *
 * FOR EVERY MODEL, SEVEN FACTS ARE ASSERTED:
 *   - A cannot READ B's row by list query;
 *   - A cannot READ B's row by primary key;
 *   - A cannot UPDATE B's row, and B's row is verified byte-unchanged afterwards;
 *   - A cannot DELETE B's row, and B's row is verified still present afterwards;
 *   - A CAN read, update and delete its OWN row;
 *   - the BulkDeleteController.php:115 call shape cannot cross the tenant boundary;
 *   - a super admin still sees both stores' rows.
 *
 * EVERY NEGATIVE IS READ BACK THROUGH RAW `DB::table()`. A scope that fails to PREVENT a write
 * but succeeds in HIDING the result would satisfy an Eloquent-only assertion, so the Eloquent
 * assertion alone is worthless for update and delete. Fixtures go in raw for the mirror-image
 * reason: the control under test must not get to shape the data it is judged against.
 */
class Batch2ModelIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const STORE_A = 411;
    private const STORE_B = 422;

    private User $merchantA;
    private User $merchantB;

    protected function setUp(): void
    {
        parent::setUp();

        StoreScope::resetSuppression();
        StoreScope::forgetOwnedStoreIds();

        // 78 of the 95 models use laravel-model-caching and the test store is `array`, which IS
        // taggable, so the model cache is genuinely live here. Raw fixture inserts fire no
        // Eloquent events and so do not flush it; without this a warmed empty result from an
        // earlier test could satisfy a later assertion for the wrong reason.
        try {
            cache()->flush();
        } catch (\Throwable $e) {
            // A non-flushable store is not a reason to fail the run.
        }

        $this->merchantA = $this->makeMerchant('a', self::STORE_A);
        $this->merchantB = $this->makeMerchant('b', self::STORE_B);

        $this->makeStore(self::STORE_A, $this->merchantA->id);
        $this->makeStore(self::STORE_B, $this->merchantB->id);

        StoreScope::forgetOwnedStoreIds();
    }

    /**
     * BATCH 2. Each row is one enforced model plus a column this test can safely mutate.
     *
     * @return array<string, array{0: class-string, 1: string, 2: string}>
     */
    public static function batchTwoModels(): array
    {
        return [
            // name            model class                        table            mutable column
            'order refunds' => [\App\Models\OrderRefund::class, 'order_refunds', 'refund_status'],
            'order notes' => [\App\Models\OrderNote::class, 'order_notes', 'notes'],
            'customers' => [\App\Models\Customer::class, 'customers', 'first_name'],
            'abandoned carts' => [\App\Models\Cart::class, 'carts', 'qty'],
        ];
    }

    // -------------------------------------------------------------------------------------
    //  Preconditions -- a model that is not really wired up would make every test below vacuous
    // -------------------------------------------------------------------------------------

    #[DataProvider('batchTwoModels')]
    public function test_the_model_is_allowlisted_carries_the_trait_and_has_the_column(string $class, string $table, string $column): void
    {
        $this->assertTrue(
            StoreScope::isAllowlisted($class),
            $class . ' is tested here as enforced but is absent from config/tenancy.php '
            . '`scoped_models`, which would make every assertion in this file vacuous.'
        );

        // Allowlisting a model that does not use the trait achieves NOTHING, because the trait is
        // what registers the scope at all. That is a silent failure, so it is asserted.
        $this->assertContains(
            BelongsToStore::class,
            class_uses_recursive($class),
            $class . ' is allowlisted but does not use BelongsToStore, so no scope is registered.'
        );

        $this->assertTrue(Schema::hasTable($table), $table . ' does not exist.');
        $this->assertTrue(Schema::hasColumn($table, 'store_id'), $table . ' has no `store_id` column.');
        $this->assertTrue(Schema::hasColumn($table, $column), $table . ' has no `' . $column . '` column.');
    }

    #[DataProvider('batchTwoModels')]
    public function test_the_predicate_is_qualified_so_the_join_sites_stay_valid(string $class, string $table, string $column): void
    {
        $this->actingAs($this->merchantA);

        // There are 39 join sites in the codebase. A bare `store_id = ?` raises
        // "Column 'store_id' in where clause is ambiguous" on every one of them.
        $this->assertStringContainsString(
            '"' . $table . '"."store_id"',
            $class::query()->toSql(),
            'The predicate must be written through qualifyColumn().'
        );
    }

    // -------------------------------------------------------------------------------------
    //  The negatives -- store A must not reach store B
    // -------------------------------------------------------------------------------------

    #[DataProvider('batchTwoModels')]
    public function test_store_a_cannot_read_store_bs_row(string $class, string $table, string $column): void
    {
        $idB = $this->seedRow($table, self::STORE_B);
        $idA = $this->seedRow($table, self::STORE_A);

        $this->actingAs($this->merchantA);

        $visible = $class::pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains($idA, $visible, 'Store A must still see its OWN row.');
        $this->assertNotContains($idB, $visible, 'Store A read store B\'s row in a list query.');

        // By primary key, which is the R4-04 route-model-binding shape.
        $this->assertNull(
            $class::find($idB),
            'Store A resolved store B\'s row by bare primary key -- the implicit route-model '
            . 'binding IDOR this scope exists to close.'
        );
        $this->assertNotNull($class::find($idA), 'Store A must still resolve its own row by id.');
    }

    #[DataProvider('batchTwoModels')]
    public function test_store_a_cannot_update_store_bs_row(string $class, string $table, string $column): void
    {
        $idB = $this->seedRow($table, self::STORE_B);
        $before = DB::table($table)->where('id', $idB)->value($column);

        $this->actingAs($this->merchantA);

        $affected = $class::where('id', $idB)->update([$column => $this->mutatedValue($column)]);

        $this->assertSame(0, $affected, 'The UPDATE reported affected rows across the tenant boundary.');

        // RAW read-back. An Eloquent re-read would be filtered by the very scope under test, so a
        // scope that permitted the write but hid the row would pass. This cannot.
        $this->assertSame(
            $before,
            DB::table($table)->where('id', $idB)->value($column),
            'Raw read-back: store B\'s `' . $column . '` was MODIFIED by store A.'
        );
    }

    #[DataProvider('batchTwoModels')]
    public function test_store_a_cannot_delete_store_bs_row(string $class, string $table, string $column): void
    {
        $idB = $this->seedRow($table, self::STORE_B);

        $this->actingAs($this->merchantA);

        $class::where('id', $idB)->delete();

        $this->assertRowIntact($table, $idB, 'Raw read-back: store B\'s row was DELETED by store A.');
    }

    #[DataProvider('batchTwoModels')]
    public function test_the_bulk_delete_call_shape_cannot_cross_the_tenant_boundary(string $class, string $table, string $column): void
    {
        // packages/workdo/BulkDelete/src/app/Http/Controllers/BulkDeleteController.php:115 runs
        //     $model::whereIn('id', $request->ids)->delete();
        // over a ~40-model map with NO tenant predicate, and packages/ cannot be edited by this
        // workstream. The only lever is which models are scoped, so this reproduces that exact
        // call shape rather than trusting that Builder::delete() carries global scopes.
        $idB = $this->seedRow($table, self::STORE_B);
        $idA = $this->seedRow($table, self::STORE_A);

        $this->actingAs($this->merchantA);

        $class::whereIn('id', [$idA, $idB])->delete();

        $this->assertRowIntact(
            $table,
            $idB,
            'Raw read-back: the bulk delete reached store B. Builder::delete() goes through '
            . 'toBase() and so must carry the global scope.'
        );
        $this->assertRowGone(
            $table,
            $idA,
            'The bulk delete must still work on the caller\'s OWN rows -- a control that breaks '
            . 'the merchant\'s own bulk delete is a defect, not a fix.'
        );
    }

    // -------------------------------------------------------------------------------------
    //  The positives -- store A's own workflows must still work
    // -------------------------------------------------------------------------------------

    #[DataProvider('batchTwoModels')]
    public function test_store_a_can_still_update_its_own_row(string $class, string $table, string $column): void
    {
        $idA = $this->seedRow($table, self::STORE_A);
        $new = $this->mutatedValue($column);

        $this->actingAs($this->merchantA);

        $affected = $class::where('id', $idA)->update([$column => $new]);

        $this->assertSame(1, $affected);
        $this->assertSame(
            $new,
            $this->normalise($column, DB::table($table)->where('id', $idA)->value($column)),
            'Raw read-back: store A could not update its own row.'
        );
    }

    #[DataProvider('batchTwoModels')]
    public function test_store_a_can_still_delete_its_own_row(string $class, string $table, string $column): void
    {
        $idA = $this->seedRow($table, self::STORE_A);

        $this->actingAs($this->merchantA);

        $class::where('id', $idA)->delete();

        $this->assertRowGone($table, $idA, 'Raw read-back: store A could not delete its own row.');
    }

    #[DataProvider('batchTwoModels')]
    public function test_each_merchant_sees_exactly_their_own_row(string $class, string $table, string $column): void
    {
        $idA = $this->seedRow($table, self::STORE_A);
        $idB = $this->seedRow($table, self::STORE_B);

        // The symmetry matters: a scope that happened to filter to store A for everybody would
        // pass every A-side assertion above.
        $this->actingAs($this->merchantA);
        $this->assertSame([$idA], $class::pluck('id')->map(fn ($id) => (int) $id)->all());

        $this->actingAs($this->merchantB);
        StoreScope::forgetOwnedStoreIds();
        $this->assertSame([$idB], $class::pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    // -------------------------------------------------------------------------------------
    //  Super admin and the storefront -- the two ways a scope breaks a platform
    // -------------------------------------------------------------------------------------

    #[DataProvider('batchTwoModels')]
    public function test_a_super_admin_still_sees_both_stores(string $class, string $table, string $column): void
    {
        $idA = $this->seedRow($table, self::STORE_A);
        $idB = $this->seedRow($table, self::STORE_B);

        $superAdmin = $this->makeMerchant('super', self::STORE_A, 'super admin');
        $this->actingAs($superAdmin);
        StoreScope::forgetOwnedStoreIds();

        $visible = $class::pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains($idA, $visible);
        $this->assertContains($idB, $visible, 'A super admin administers every store; narrowing '
            . 'them to one hides the platform behind what looks like missing data.');
        $this->assertStringNotContainsString('store_id', $class::query()->toSql());
    }

    #[DataProvider('batchTwoModels')]
    public function test_an_anonymous_storefront_request_is_not_filtered(string $class, string $table, string $column): void
    {
        // THE REASON THESE FOUR MODELS COULD BE ENABLED AND Product/Category COULD NOT. All four
        // are read on the storefront by an anonymous visitor or on the `customers` guard, neither
        // of which has a web-guard principal, so the scope adds no predicate there and cannot
        // blank the shop. Asserted rather than assumed, because getting this wrong is the exact
        // failure that blanked the storefront navigation when `Menu` was scoped.
        $idA = $this->seedRow($table, self::STORE_A);
        $idB = $this->seedRow($table, self::STORE_B);

        // No actingAs: this is the anonymous storefront / queued job / customers-guard context.
        $this->assertSame(StoreScope::MODE_UNRESOLVED, StoreScope::tenantMode());
        $this->assertStringNotContainsString('store_id', $class::query()->toSql());

        $visible = $class::pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains($idA, $visible);
        $this->assertContains($idB, $visible, 'The scope filtered an anonymous read. That empties '
            . 'the storefront and buys no confidentiality, because an anonymous visitor resolves '
            . 'no tenant and was never filtered before.');
    }

    #[DataProvider('batchTwoModels')]
    public function test_the_closed_null_tenant_policy_empties_these_models_for_the_storefront(string $class, string $table, string $column): void
    {
        // THE BRIEF ASKS WHETHER FAIL-OPEN SHOULD BE REVISITED NOW THAT MORE MODELS ARE SCOPED.
        // This measures the answer instead of arguing it. Enabling batch 2 makes 'closed' MORE
        // damaging, not less, because all four of these models are read in the null-tenant
        // contexts by the users they exist for:
        //   - Cart        -> the shopper's basket on every storefront page and in checkout;
        //   - Customer    -> `retrieveByCredentials` during storefront login and registration;
        //   - OrderRefund -> the customer's own returns list;
        //   - OrderNote   -> written by the payment controllers on the anonymous gateway callback.
        // So 'open' stays the default, and this test is the cost of the alternative.
        $this->seedRow($table, self::STORE_A);
        $this->seedRow($table, self::STORE_B);

        config(['tenancy.null_tenant' => 'closed']);

        // No actingAs: the anonymous storefront context.
        $this->assertSame(StoreScope::MODE_UNRESOLVED, StoreScope::tenantMode());
        $this->assertSame(
            [],
            $class::pluck('id')->all(),
            'Under the `closed` policy an anonymous storefront read of ' . $class . ' must return '
            . 'nothing. If it returns rows, `closed` is not actually closing and the trade this '
            . 'configuration describes is not real.'
        );

        // ... and the rows are genuinely there, so the emptiness above is the policy and not an
        // absent fixture.
        $this->assertSame(2, DB::table($table)->count());
    }

    #[DataProvider('batchTwoModels')]
    public function test_the_escape_hatches_still_work(string $class, string $table, string $column): void
    {
        $idB = $this->seedRow($table, self::STORE_B);

        $this->actingAs($this->merchantA);

        $this->assertNotNull(
            $class::withoutStoreScope()->find($idB),
            'withoutStoreScope() must remain a working, greppable cross-tenant hatch -- it is what '
            . 'app/Helper/helper.php needs to keep the storefront menu rendering.'
        );

        $this->assertNotNull(
            StoreScope::allowCrossTenant(fn () => $class::find($idB)),
            'allowCrossTenant() must suppress the scope for deliberate platform-wide work.'
        );

        // And the suppression must be restored afterwards, or one report leaks tenancy for the
        // rest of the process.
        $this->assertFalse(StoreScope::isSuppressed());
        $this->assertNull($class::find($idB));
    }

    // -------------------------------------------------------------------------------------
    //  Fixtures
    // -------------------------------------------------------------------------------------

    /**
     * "This row survived the delete", read back through RAW SQL.
     *
     * SOFT DELETES MATTER HERE AND THE DISTINCTION IS NOT COSMETIC. `Customer` uses
     * `Illuminate\Database\Eloquent\SoftDeletes` (app/Models/Customer.php), so `delete()` on it
     * is an UPDATE that stamps `deleted_at`, not a DELETE. A row-count assertion therefore passes
     * for a soft-deleting model WHETHER OR NOT the tenant boundary held -- the row is still
     * physically present either way -- which would make the most important assertion in this file
     * vacuous for the most sensitive model in the batch. Found by this test failing on exactly
     * that, not by reading the model.
     *
     * So: present AND not stamped for a soft-deleting model, present for a hard-deleting one.
     */
    private function assertRowIntact(string $table, int $id, string $message): void
    {
        $row = DB::table($table)->where('id', $id)->first();

        $this->assertNotNull($row, $message);

        if (Schema::hasColumn($table, 'deleted_at')) {
            $this->assertNull(
                $row->deleted_at,
                $message . ' (the row is present but `deleted_at` was stamped, which for a '
                . 'soft-deleting model IS the deletion.)'
            );
        }
    }

    /**
     * "This row was really deleted", read back through RAW SQL -- gone, or stamped if the model
     * soft-deletes.
     */
    private function assertRowGone(string $table, int $id, string $message): void
    {
        $row = DB::table($table)->where('id', $id)->first();

        if ($row === null) {
            $this->assertNull($row);

            return;
        }

        $this->assertTrue(
            Schema::hasColumn($table, 'deleted_at'),
            $message . ' (the row is still present and the table has no `deleted_at`, so this '
            . 'was not a soft delete.)'
        );

        $this->assertNotNull($row->deleted_at, $message);
    }

    private function makeMerchant(string $tag, int $currentStore, string $type = 'admin'): User
    {
        $user = new User();
        $user->forceFill([
            'name' => 'Merchant ' . $tag,
            'email' => 'merchant-' . $tag . '-' . uniqid() . '@example.test',
            'password' => bcrypt('not-a-real-credential'),
            'type' => $type,
            'current_store' => $currentStore,
        ])->save();

        return $user;
    }

    private function makeStore(int $storeId, int $createdBy): void
    {
        if (DB::table('stores')->where('id', $storeId)->exists()) {
            return;
        }

        $row = $this->requiredColumnStubs('stores');
        $row['id'] = $storeId;
        $row['created_by'] = $createdBy;

        DB::table('stores')->insert($row);
    }

    /**
     * Insert one row of $table owned by $storeId, raw, and return its id.
     *
     * `store_id` is NEVER stubbed by requiredColumnStubs(): it is the thing under test and is
     * always the value this method puts there.
     */
    private function seedRow(string $table, int $storeId): int
    {
        $row = $this->requiredColumnStubs($table);
        $row['store_id'] = $storeId;

        return (int) DB::table($table)->insertGetId($row);
    }

    private function mutatedValue(string $column): string|int
    {
        return $column === 'qty' ? 97 : 'mutated-' . $column;
    }

    private function normalise(string $column, mixed $value): string|int
    {
        return $column === 'qty' ? (int) $value : (string) $value;
    }

    /**
     * Placeholder values for every NOT NULL column of $table that has no default, read from the
     * LIVE SCHEMA rather than hand-listed.
     *
     * A hand-maintained column list is wrong the moment a migration adds a NOT NULL column, and
     * it fails as a confusing fixture error rather than as a tenancy finding. Foreign keys are
     * resolved to a row that genuinely exists, because sqlite enforces them under
     * RefreshDatabase.
     *
     * @return array<string, mixed>
     */
    private function requiredColumnStubs(string $table): array
    {
        $row = [];

        $foreignKeys = [];

        foreach (DB::select('pragma foreign_key_list(' . $table . ')') as $fk) {
            $foreignKeys[$fk->from] = [$fk->table, $fk->to];
        }

        foreach (DB::select('pragma table_info(' . $table . ')') as $column) {
            $name = $column->name;

            if ($name === 'id' || $name === 'store_id') {
                continue;
            }

            if ((int) $column->notnull === 0 || $column->dflt_value !== null) {
                // Still fill the timestamps, which several tables declare nullable but which the
                // assertions read back.
                if (in_array($name, ['created_at', 'updated_at'], true)) {
                    $row[$name] = now();
                }

                continue;
            }

            if (isset($foreignKeys[$name])) {
                $row[$name] = $this->mintForeignRow($foreignKeys[$name][0], $foreignKeys[$name][1]);

                continue;
            }

            $type = strtolower((string) $column->type);

            if (str_contains($type, 'int')) {
                $row[$name] = 1;
            } elseif (str_contains($type, 'date') || str_contains($type, 'time')) {
                $row[$name] = now();
            } elseif (str_contains($type, 'dec') || str_contains($type, 'float') || str_contains($type, 'double')) {
                $row[$name] = 0;
            } else {
                $row[$name] = 'fixture-' . $name;
            }
        }

        $row['created_at'] = $row['created_at'] ?? now();
        $row['updated_at'] = $row['updated_at'] ?? now();

        return $row;
    }

    /**
     * Mint a row in a parent table so a real foreign key can be satisfied.
     *
     * Each call mints a DISTINCT parent row, which is what lets the update assertions compare two
     * different valid values rather than two coerced zeroes.
     */
    private function mintForeignRow(string $foreignTable, string $foreignColumn): int
    {
        if ($foreignTable === 'users') {
            return $this->merchantA->id;
        }

        if ($foreignTable === 'stores') {
            return self::STORE_A;
        }

        $parent = $this->requiredColumnStubs($foreignTable);

        if (Schema::hasColumn($foreignTable, 'store_id')) {
            $parent['store_id'] = self::STORE_A;
        }

        return (int) DB::table($foreignTable)->insertGetId($parent);
    }
}
