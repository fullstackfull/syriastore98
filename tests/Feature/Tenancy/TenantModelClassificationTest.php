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
    //  THE THIRD CATEGORY: PUBLIC STOREFRONT CONTENT
    // -------------------------------------------------------------------------------------

    /**
     * Models whose rows are rendered to an ANONYMOUS visitor as part of a store's public pages.
     *
     * WHY THIS IS A SEPARATE CATEGORY AND NOT A SUBSET OF "TENANT-OWNED". These rows ARE
     * tenant-owned -- they carry a real `store_id` and belong to exactly one merchant -- so the
     * two-way partition above says "scopable" about every one of them, and that is the trap.
     *
     * The global scope cannot distinguish a merchant reading their OWN admin panel from the same
     * merchant browsing a shop they do NOT own: both are web-guard principals with the same
     * `current_store`. Scoping public content therefore blanks the catalogue, the category menu,
     * the blog, the checkout shipping and tax options for a signed-in merchant browsing any other
     * store -- silently, with no error, because an empty result set is not an exception. And it
     * buys NO confidentiality in exchange, because the anonymous visitor those pages exist for
     * resolves no tenant (MODE_UNRESOLVED) and was never filtered in the first place.
     *
     * This is not hypothetical. It has already happened here twice: scoping `Menu` blanked the
     * storefront navigation, and a brace move behind `auth` killed newsletter signup on every
     * store. The cost of getting it wrong is a broken shop; the benefit of getting it right is
     * nothing. So the default for this category is DO NOT SCOPE.
     *
     * @return array<class-string, string> class => why an anonymous visitor renders it
     */
    public static function publicStorefrontModels(): array
    {
        return [
            \App\Models\Product::class => 'the catalogue itself, on every shop page',
            \App\Models\Category::class => 'the category navigation and listing pages',
            \App\Models\ProductImage::class => 'product gallery images',
            \App\Models\ProductVariant::class => 'variant pickers on the product detail page',
            \App\Models\ProductAttribute::class => 'variant attribute names',
            \App\Models\ProductAttributeOption::class => 'variant attribute values',
            \App\Models\ProductBrand::class => 'brand filters and brand pages',
            \App\Models\ProductLabel::class => 'the "new"/"sale" badges on product cards',
            \App\Models\ProductQuestion::class => 'the public Q&A block on product detail',
            \App\Models\Tag::class => 'product tag links',
            \App\Models\Blog::class => 'the public blog index and posts',
            \App\Models\BlogCategory::class => 'blog category navigation',
            \App\Models\Page::class => 'CMS pages such as terms and privacy',
            \App\Models\Faq::class => 'the public FAQ section',
            \App\Models\Testimonial::class => 'the public testimonial section',
            \App\Models\FlashSale::class => 'the flash-sale banner and countdown',
            \App\Models\Coupon::class => 'coupon validation during an anonymous checkout',
            \App\Models\Shipping::class => 'shipping options at checkout',
            \App\Models\ShippingMethod::class => 'shipping method options at checkout',
            \App\Models\ShippingZone::class => 'shipping zone resolution at checkout',
            \App\Models\Tax::class => 'tax lines on the cart and checkout totals',
            \App\Models\TaxMethod::class => 'tax method resolution at checkout',
            \App\Models\TaxOption::class => 'tax option resolution at checkout',
            \App\Models\ThemeCustomize::class => 'the theme the storefront renders with',
            \App\Models\PixelField::class => 'tracking pixels injected into storefront pages',
            \App\Models\Setting::class => 'currency, logo and every other storefront setting',
            \App\Models\Newsletter::class => 'written by the anonymous storefront signup form',
            \App\Models\Contact::class => 'written by the anonymous contact form',
        ];
    }

    /**
     * THE ASSERTION THAT PROTECTS THE STOREFRONT. None of the above may be enforced.
     *
     * `Menu` is the ONE documented exception and is excluded below rather than silently omitted,
     * because an unexplained gap in this list is how the next one gets added.
     */
    public function test_no_public_storefront_model_is_enforced(): void
    {
        foreach (self::publicStorefrontModels() as $class => $why) {
            $this->assertFalse(
                StoreScope::isAllowlisted($class),
                $class . ' is PUBLIC STOREFRONT CONTENT (' . $why . ') and has been allowlisted '
                . 'for tenant enforcement. That blanks it for any signed-in merchant browsing '
                . 'another store, with no error, and buys no confidentiality because an anonymous '
                . 'visitor resolves no tenant and was never filtered. If this is intentional, the '
                . 'storefront read sites need a withoutGlobalScope escape FIRST -- see '
                . 'app/Helper/helper.php:2364 for how Menu had to be handled -- and this list '
                . 'needs updating with the evidence.'
            );
        }
    }

    public function test_every_public_storefront_model_really_is_tenant_discriminated(): void
    {
        // Keeps the list honest in the other direction: a model with no `store_id` could never
        // have been scoped anyway, so listing it here would pad the guard with entries that
        // cannot fail and hide a real one going missing.
        foreach (array_keys(self::publicStorefrontModels()) as $class) {
            $this->assertContains(
                $class,
                $this->tenantDiscriminated,
                $class . ' is listed as public storefront content but its table has no `store_id` '
                . 'column, so it belongs in the platform-global category instead.'
            );
        }
    }

    /**
     * MENU: THE ONE ENFORCED MODEL THAT IS ALSO PUBLIC STOREFRONT CONTENT.
     *
     * Enforcing it was only safe because the storefront read was given an explicit escape. That
     * escape is the single thing standing between this configuration and a blank navigation bar on
     * every store, so its continued existence is asserted rather than trusted.
     */
    public function test_the_enforced_menu_model_still_has_its_storefront_escape_hatch(): void
    {
        $this->assertTrue(
            StoreScope::isAllowlisted(\App\Models\Menu::class),
            'Menu is expected to be enforced; if that changed, this test and the exception '
            . 'recorded in publicStorefrontModels() both need revisiting.'
        );

        $helper = (string) file_get_contents(base_path('app/Helper/helper.php'));

        $this->assertStringContainsString(
            'Menu::withoutGlobalScope(\App\Scopes\StoreScope::class)',
            $helper,
            'The storefront menu read in app/Helper/helper.php no longer bypasses the tenant '
            . 'scope. Menu IS enforced, and the storefront resolves its store by SLUG with no '
            . 'web-guard principal -- but a signed-in merchant browsing another store DOES have '
            . 'one, and without this escape that store\'s navigation renders empty. This is the '
            . 'defect that has already shipped once.'
        );
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
