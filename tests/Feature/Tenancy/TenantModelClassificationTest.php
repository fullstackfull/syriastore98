<?php

namespace Tests\Feature\Tenancy;

use App\Models\Concerns\BelongsToStore;
use App\Scopes\StoreScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

/**
 * THE TENANT-OWNERSHIP MAP FOR ALL 95 MODELS IN app/Models.
 *
 * This is the brief's item 1, turned into a durable test instead of a document that drifts. It
 * classifies every model as TENANT-OWNED or PLATFORM-GLOBAL, and the evidence is read from the
 * LIVE MIGRATED SCHEMA at runtime -- `Schema::hasColumn($table, 'store_id')` -- not from the
 * model's `$fillable`, not from its name, and not from a grep. Several models declare `store_id`
 * in `$fillable` for a table that does not have the column, and at least one has no table at all,
 * so `$fillable` is not evidence of anything.
 *
 * WHY THIS MATTERS MORE THAN IT LOOKS. Getting a PLATFORM-GLOBAL model scoped does not produce an
 * error; it produces MISSING ROWS. The super admin's plan list goes empty, the country dropdown
 * goes empty, the permission check finds no role. The failure looks like absent data, so it is
 * found late and diagnosed slowly. These assertions exist so that a future change that drags a
 * platform-global model onto the allowlist fails here, loudly, with the reason attached.
 *
 * MEASURED PARTITION, 2026-10-12, against `migrate:fresh` on sqlite :memory:
 *   56 models  -- table exists AND has a `store_id` column          -> TENANT-DISCRIMINATED
 *   25 models  -- table exists and has NO `store_id` column         -> PLATFORM-GLOBAL
 *   14 models  -- NO table at all                                   -> see GATE F below
 *   --
 *   95 total, and the partition is asserted to be exhaustive.
 *
 * TENANT-DISCRIMINATED IS NOT THE SAME AS SCOPABLE. 56 models carry the column; only 6 are
 * enforced. `config/tenancy.php` holds the allowlist and the audit behind each entry, and
 * `doNotScopeDespiteHavingTheColumn()` below records the models that have `store_id` and must
 * STILL never be scoped, each with its own reason.
 *
 * THREE CORRECTIONS TO EARLIER DOCUMENTATION, with evidence, as the honesty rules require.
 * The DO-NOT-SCOPE list in App\Models\Concerns\BelongsToStore's docblock states that `Role`,
 * `Permission`, `EmailTemplate`, `Template`, `Addon`, `Faq` and `Testimonial` are platform-global
 * and that "they have no `store_id`". That is right about the conclusion for some of them and
 * WRONG about the stated reason for three:
 *   - `roles.store_id` EXISTS. It is declared at
 *     database/migrations/2023_12_08_100022_laratrust_setup_tables.php:25 as
 *     `unsignedBigInteger('store_id')->nullable()->index()`.
 *   - `faqs.store_id` and `testimonials.store_id` EXIST too, per the live schema.
 * `Permission`, `EmailTemplate`, `Template` and `Addon` genuinely have no such column.
 * The conclusion "do not scope Role" still holds, but for a STRONGER reason than the one given:
 * the column is NULLABLE, so platform-level roles carry `store_id IS NULL`, and a
 * `store_id = ?` predicate matches no NULL in SQL. Scoping `Role` would therefore hide every
 * platform role and take the permission system down. That is recorded in
 * doNotScopeDespiteHavingTheColumn() so the reason travels with the decision.
 */
class TenantModelClassificationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<class-string, string> model class => table */
    private array $tables = [];

    /** @var array<int, class-string> */
    private array $tenantDiscriminated = [];

    /** @var array<int, class-string> */
    private array $platformGlobal = [];

    /** @var array<int, class-string> */
    private array $withoutTable = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ($this->eloquentModels() as $class) {
            /** @var Model $instance */
            $instance = new $class();
            $table = $instance->getTable();
            $this->tables[$class] = $table;

            if (! Schema::hasTable($table)) {
                $this->withoutTable[] = $class;

                continue;
            }

            if (Schema::hasColumn($table, 'store_id')) {
                $this->tenantDiscriminated[] = $class;
            } else {
                $this->platformGlobal[] = $class;
            }
        }
    }

    /**
     * Every concrete Eloquent model under app/Models.
     *
     * Discovered from disk rather than listed, so a model added tomorrow is classified by this
     * test instead of quietly escaping it.
     *
     * @return array<int, class-string<Model>>
     */
    private function eloquentModels(): array
    {
        $models = [];

        foreach (glob(base_path('app/Models/*.php')) as $file) {
            $class = 'App\\Models\\' . basename($file, '.php');

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            $models[] = $class;
        }

        sort($models);

        return $models;
    }

    /**
     * Models that DO carry a `store_id` column and must STILL never be globally scoped.
     *
     * @return array<class-string, string> class => the reason, which is the point of this list
     */
    public static function doNotScopeDespiteHavingTheColumn(): array
    {
        return [
            // app/Models/Utility.php:289 and :896 both run `Setting::where('store_id', 1)` with a
            // HARDCODED literal while configuring file storage. A current-store scope compiles
            // that to `store_id = 1 AND store_id = <current>` -> zero rows -> storage
            // configuration collapses for every tenant that is not store 1.
            \App\Models\Setting::class => 'Utility.php:289/:896 filter on a hardcoded store_id of 1.',

            // roles.store_id is NULLABLE (laratrust_setup_tables.php:25). Platform-level roles
            // carry NULL, and `store_id = ?` matches no NULL row, so scoping Role would hide
            // every platform role and break Laratrust permission resolution outright.
            \App\Models\Role::class => 'roles.store_id is nullable; platform roles are NULL and a predicate would hide them.',
        ];
    }

    // -------------------------------------------------------------------------------------
    //  The partition itself
    // -------------------------------------------------------------------------------------

    public function test_the_classification_covers_every_model_exactly_once(): void
    {
        $total = count($this->eloquentModels());

        $this->assertSame(
            $total,
            count($this->tenantDiscriminated) + count($this->platformGlobal) + count($this->withoutTable),
            'The three categories must partition the models exactly. If this fails, a model was '
            . 'counted twice or not at all and the map below cannot be trusted.'
        );

        $this->assertSame(95, $total, 'The model count changed. Re-run the classification and '
            . 'review the new model before anything is allowlisted: ' . implode(', ', $this->eloquentModels()));
    }

    public function test_tenant_discriminated_models_are_the_measured_fifty_six(): void
    {
        $this->assertCount(
            56,
            $this->tenantDiscriminated,
            "The set of models whose table carries a `store_id` column changed.\nMeasured now:\n  "
            . implode("\n  ", $this->tenantDiscriminated)
        );
    }

    public function test_platform_global_models_are_the_measured_twenty_five(): void
    {
        $this->assertCount(
            25,
            $this->platformGlobal,
            "The set of models whose table has NO `store_id` column changed.\nMeasured now:\n  "
            . implode("\n  ", $this->platformGlobal)
        );
    }

    /**
     * The brief names these as platform-global models that must not end up scoped, because
     * scoping one would hide platform data from the super admin and break the product.
     *
     * `Language` is in that list and has NO model in app/Models -- `ls app/Models | grep -i lang`
     * returns only `EmailTemplateLang`, whose table has no `store_id` either. It is therefore not
     * assertable here and is reported rather than silently dropped.
     */
    public function test_the_named_platform_global_models_have_no_store_id_column(): void
    {
        $named = [
            \App\Models\Plan::class,
            \App\Models\Country::class,
            \App\Models\State::class,
            \App\Models\City::class,
            \App\Models\Currency::class,
            \App\Models\User::class,
            \App\Models\Permission::class,
            \App\Models\Addon::class,
            \App\Models\Template::class,
            \App\Models\EmailTemplate::class,
            // Platform-level coupons, as distinct from the tenant-level `Coupon`, which DOES
            // carry store_id. This is the "Coupon-at-platform-level" distinction in the brief.
            \App\Models\PlanCoupon::class,
            \App\Models\UserCoupon::class,
            \App\Models\PlanUserCoupon::class,
        ];

        foreach ($named as $class) {
            $this->assertContains(
                $class,
                $this->platformGlobal,
                $class . ' is named as platform-global but its table now has a `store_id` column. '
                . 'Re-classify it deliberately before anything depends on this.'
            );
        }
    }

    /**
     * The tenant-level `Coupon` is the counterpart of the assertion above: it DOES carry the
     * column, so "coupons are platform-global" would be wrong as a blanket statement. Pinning
     * both halves is what keeps the distinction from being flattened later.
     */
    public function test_tenant_level_coupon_does_carry_the_column(): void
    {
        $this->assertContains(\App\Models\Coupon::class, $this->tenantDiscriminated);
    }

    // -------------------------------------------------------------------------------------
    //  The invariants that actually protect the platform
    // -------------------------------------------------------------------------------------

    /**
     * THE MOST IMPORTANT ASSERTION IN THIS FILE. A model with no `store_id` column can never be
     * scoped: the predicate would reference a column that does not exist and every query against
     * it would throw. Allowlisting one is not a subtle mistake, it is an outage.
     */
    public function test_no_platform_global_model_is_allowlisted_for_enforcement(): void
    {
        foreach ($this->platformGlobal as $class) {
            $this->assertFalse(
                StoreScope::isAllowlisted($class),
                $class . ' has NO `store_id` column (table `' . $this->tables[$class] . '`) but is '
                . 'named in config/tenancy.php `scoped_models`. Every query against it would fail '
                . 'on an unknown column. Remove it.'
            );
        }
    }

    public function test_no_platform_global_model_carries_the_trait(): void
    {
        foreach ($this->platformGlobal as $class) {
            $this->assertNotContains(
                BelongsToStore::class,
                class_uses_recursive($class),
                $class . ' uses BelongsToStore but its table `' . $this->tables[$class] . '` has no '
                . '`store_id` column.'
            );
        }
    }

    /**
     * The converse: anything enforced must genuinely have the column to filter on.
     */
    public function test_every_allowlisted_model_has_a_store_id_column(): void
    {
        foreach (StoreScope::allowlistedModels() as $class) {
            $this->assertTrue(
                class_exists($class),
                'config/tenancy.php names a model that does not exist: ' . $class
            );

            $table = (new $class())->getTable();

            $this->assertTrue(
                Schema::hasTable($table),
                $class . ' is allowlisted but its table `' . $table . '` does not exist.'
            );

            $this->assertTrue(
                Schema::hasColumn($table, 'store_id'),
                $class . ' is allowlisted but `' . $table . '` has no `store_id` column.'
            );
        }
    }

    /**
     * Models that have the column but whose own call sites make a predicate unsafe. Each carries
     * its reason, and the reason is asserted to still be recorded.
     */
    public function test_models_excluded_despite_having_the_column_stay_excluded(): void
    {
        foreach (self::doNotScopeDespiteHavingTheColumn() as $class => $reason) {
            $this->assertContains(
                $class,
                $this->tenantDiscriminated,
                $class . ' no longer has a `store_id` column, so this exclusion needs rewriting.'
            );

            $this->assertFalse(
                StoreScope::isAllowlisted($class),
                $class . ' must NOT be enforced. Reason on record: ' . $reason
            );

            $this->assertNotContains(
                BelongsToStore::class,
                class_uses_recursive($class),
                $class . ' must not carry the trait. Reason on record: ' . $reason
            );
        }
    }

    // -------------------------------------------------------------------------------------
    //  GATE F -- the six money tables with no migration
    // -------------------------------------------------------------------------------------

    /**
     * R4-06 / GATE F. These models are read and written by live code but their tables are created
     * by NO migration, and the draft DDL in database/migrations/_gate_f_pending/*.php.draft is
     * verified not to run. Inventing their schema is forbidden, so their isolation cannot be
     * tested and this reports the gap instead of pretending otherwise.
     *
     * This test asserts the tables are ABSENT. If one appears, a migration was added and these
     * models must be classified and audited for real -- at which point this test fails and says so.
     */
    public function test_gate_f_money_models_still_have_no_table(): void
    {
        $gateF = [
            \App\Models\StoreWallet::class => 'store_wallets',
            \App\Models\StoreWalletTransaction::class => 'store_wallet_transactions',
            \App\Models\StoreWithdrawal::class => 'store_withdrawals',
            \App\Models\CurrencyRate::class => 'currency_rates',
            \App\Models\DeliveryEarning::class => 'delivery_earnings',
        ];

        foreach ($gateF as $class => $table) {
            $this->assertFalse(
                Schema::hasTable($table),
                "GATE F table `{$table}` now EXISTS. {$class} must be classified and audited "
                . 'before it is enforced, and the GATE F skips elsewhere in this suite must be '
                . 'revisited rather than left skipped.'
            );

            $this->assertFalse(
                StoreScope::isAllowlisted($class),
                $class . ' is allowlisted but has no table. Enforcement cannot be proven, so it '
                . 'must not be claimed.'
            );
        }

        // The sixth GATE F table has no model at all, so it is asserted directly.
        $this->assertFalse(
            Schema::hasTable('store_order_financials'),
            'store_order_financials now exists; GATE F has moved and this suite needs revisiting.'
        );
    }

    public function test_models_without_a_table_are_the_measured_fourteen(): void
    {
        $this->assertCount(
            14,
            $this->withoutTable,
            "The set of models with no table changed.\nMeasured now:\n  "
            . implode("\n  ", $this->withoutTable)
        );
    }
}
