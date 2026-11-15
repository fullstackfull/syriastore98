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
 * THE TENANT-OWNERSHIP MAP FOR ALL 93 MODELS IN app/Models.
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
 *   57 models  -- table exists AND has a `store_id` column          -> TENANT-DISCRIMINATED
 *   25 models  -- table exists and has NO `store_id` column         -> PLATFORM-GLOBAL
 *   14 models  -- NO table at all                                   -> see GATE F below
 *   --
 *   93 total, and the partition is asserted to be exhaustive.
 *
 * TENANT-DISCRIMINATED IS NOT THE SAME AS SCOPABLE. 57 models carry the column; only 6 are
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
            // P3-M3-C. The three theme-versioning models. Their tables all carry `store_id`
            // and every read and write in App\Services\Theme\ThemeConfigurationService and in the
            // models' own ownedBy()/liveFor()/historyFor() helpers carries an EXPLICIT
            // `where('store_id', ...)` -- derived from App\Support\Theme\ThemeEditorGate, which
            // requires the store to be named and then proves ownership, never from request input
            // and never from getCurrentStore() (which hands an operator the literal store 1).
            //
            // They are left out of `scoped_models` DELIBERATELY rather than by omission, and the
            // reasoning is the one already recorded for FavouritePages below: `null_tenant =>
            // 'open'` means the global scope adds NO predicate when no tenant resolves, so a second
            // implicit predicate would mask a missing explicit one rather than back it up. It is
            // also inert for a customer or driver Sanctum token and for staff with an empty
            // `current_store`, and StoreScope::resolveTenant() returns MODE_SUPER_ADMIN with no
            // predicate at all -- which is exactly the principal whose store resolution is the
            // residual risk M3-D has to close. Defence in depth against a merchant; not the control
            // of record.
            //
            // The control of record is engine-level: `tv_identity_unique` plus the COMPOSITE
            // foreign key `(store_id, theme_id, version_id)` on theme_published_versions, which
            // makes publishing another tenant's version impossible rather than merely unlikely
            // (measured: ERROR 1452). theme_publication_events deliberately has no foreign keys at
            // all, so its references are scoped by the same explicit triple in resolveVersion().
            \App\Models\ThemeVersion::class => 'P3-M3-C: explicit (store_id, theme_id, id) predicates plus engine-level composite FK; the scope would add only a weaker implicit half.',
            \App\Models\ThemePublishedVersion::class => 'P3-M3-C: UNIQUE(store_id, theme_id) and a composite FK to theme_versions already enforce ownership in the engine.',
            \App\Models\ThemePublicationEvent::class => 'P3-M3-C: append-only audit with no FKs by design; references are resolved through the explicit triple, never a bare id.',

            // app/Models/Utility.php:289 and :896 both run `Setting::where('store_id', 1)` with a
            // HARDCODED literal while configuring file storage. A current-store scope compiles
            // that to `store_id = 1 AND store_id = <current>` -> zero rows -> storage
            // configuration collapses for every tenant that is not store 1.
            \App\Models\Setting::class => 'Utility.php:289/:896 filter on a hardcoded store_id of 1.',

            // roles.store_id is NULLABLE (laratrust_setup_tables.php:25). Platform-level roles
            // carry NULL, and `store_id = ?` matches no NULL row, so scoping Role would hide
            // every platform role and break Laratrust permission resolution outright.
            \App\Models\Role::class => 'roles.store_id is nullable; platform roles are NULL and a predicate would hide them.',

            // P2-06-7. Every read and write in App\Services\FavouritePages carries its own
            // `where('store_id', ...)` AND `where('user_id', ...)`, derived from `auth()` and
            // `getCurrentStore()` -- never from request input. It is left out of `scoped_models`
            // deliberately rather than by omission: `null_tenant => 'open'` means the scope adds
            // NO predicate when no tenant resolves, so a second implicit predicate could mask a
            // missing explicit one, and these rows are read by a principal whose tenant is
            // already the thing being filtered on. The explicit pair is the control of record,
            // and reverting either one fails a dedicated test.
            \App\Models\MerchantFavouritePage::class => 'FavouritePages filters on store_id AND user_id explicitly; the scope would be a second, maskable predicate.',
            // P2-04-9, and the SAME reasoning as MerchantFavouritePage above, which is why it sits
            // beside it. `SavedView::forCurrentPrincipal()` filters on store_id AND created_by
            // explicitly, and the global scope would add only the FIRST of those -- so a saved
            // view scoped by the framework alone would still be visible to every colleague in the
            // store. It would also be WEAKER where it matters most: `null_tenant => 'open'` means
            // the scope adds no predicate at all when no tenant resolves, whereas this model fails
            // CLOSED with `whereRaw('1 = 0')` (F149's lesson). An implicit predicate that is
            // narrower than the explicit one, and absent exactly when the explicit one matters, is
            // not defence in depth -- it is something for a future reader to mistake for the
            // control. The explicit pair is the control of record and reverting either one fails
            // a dedicated test in SavedViewIsolationTest.
            \App\Models\SavedView::class => 'SavedView filters on store_id AND created_by explicitly, and fails closed with no tenant; the scope would add only store_id.',
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

        // 93, DOWN FROM 95. Commit 1bd56358 ("retire the legacy Chatwoot integration") deleted
        // App\Models\ChatwootIntegration and App\Models\ChatwootNotificationTemplate, and
        // nothing else in app/Models changed on this branch -- verified by diffing the directory
        // against the merge base 0d2ca628.
        //
        // NEITHER DELETION WEAKENS ANYTHING. Both models were in the withoutTable partition
        // (confirmed by dumping that set at the merge base), which is the category this suite
        // describes as one where "enforcement cannot be proven, so it must not be claimed".
        // Removing a model that had no table removes nothing enforceable; it shrinks the set of
        // classes carrying an unprovable classification.
        // 94, UP FROM 93. P2-06-7 added App\Models\MerchantFavouritePage -- per-employee
        // favourite navigation pages. It is classified in
        // `doNotScopeDespiteHavingTheColumn()` above with its reason, which is the review this
        // assertion exists to force; it is NOT allowlisted for enforcement.
        // 95, UP FROM 94. P2-04-9 added App\Models\SavedView -- per-employee named filter sets.
        // Same category and same reason as MerchantFavouritePage: its table carries `store_id`,
        // its reads carry `store_id` AND `created_by`, and it is NOT allowlisted for enforcement
        // because the scope would supply only the weaker half of that pair. This assertion
        // existing is what forced the classification to be written rather than assumed.
        // 98, UP FROM 95. P3-M3-C added the three theme-versioning models:
        // App\Models\ThemeVersion, App\Models\ThemePublishedVersion and
        // App\Models\ThemePublicationEvent. All three tables carry `store_id`, so all three are
        // tenant-discriminated, and all three are recorded in doNotScopeDespiteHavingTheColumn()
        // with their reason -- they are NOT allowlisted for scope enforcement. That classification
        // is the review this assertion exists to force, and it was written before the count moved.
        $this->assertSame(98, $total, 'The model count changed. Re-run the classification and '
            . 'review the new model before anything is allowlisted: ' . implode(', ', $this->eloquentModels()));
    }

    public function test_tenant_discriminated_models_are_the_measured_fifty_eight(): void
    {
        // 57, UP FROM 56: `merchant_favourite_pages` carries `store_id` (P2-06-7). The column is
        // real and the rows are tenant-owned; what changed is the count, not the classification
        // rule.
        // 58, UP FROM 57: `saved_views` carries `store_id` (P2-04-9). Again the count changed and
        // the rule did not -- the rows are tenant-owned AND employee-owned, which is why the
        // model is in `doNotScopeDespiteHavingTheColumn()` rather than in the enforcement
        // allowlist.
        //
        // 65, UP FROM 58 -- GATE F CLOSED ON 2026-11-02. Seven models' tables now exist and carry
        // `store_id`, so they move out of the withoutTable partition and into this one:
        // StoreWallet, StoreWalletTransaction, StoreWithdrawal, CurrencyRate, OtpIntegration,
        // ZidStore and ZidEvent. The classification RULE did not change: this partition is "the
        // table carries a store_id column", and it now can be answered for them.
        //
        // TWO OF THE SEVEN CARRY A COLUMN THAT IS NOT THIS PLATFORM'S TENANT DISCRIMINATOR, and
        // recording it is the review this assertion exists to force. `zid_stores.store_id` is
        // varchar(255) and `zid_orders.store_id` is varchar(191), both holding ZID'S OWN merchant
        // identifier rather than `stores.id` -- measured, docs/GATE-F-VERIFIED-SCHEMA.md section 2,
        // and the reason neither table has a foreign key to `stores`.
        //
        // So `ZidStore` and `ZidEvent` land in this partition because the census asks "does the
        // table have a column called store_id", and for them the honest answer to "is it the
        // tenant discriminator" is NO. They are NOT in `doNotScopeDespiteHavingTheColumn()`,
        // because that list is for BelongsToStore CARRIERS and neither carries the trait (checked:
        // `grep BelongsToStore app/Models/ZidStore.php` and `ZidEvent.php` are both empty). The
        // hazard is recorded HERE instead, where the count that includes them is asserted:
        // allowlisting either would compare a Zid merchant string against an integer tenant id --
        // a predicate that matches nothing, or matches by coincidence, while looking like
        // isolation. `StoreScope::isActiveFor()` would also short-circuit for them anyway, since
        // they are not in config/tenancy.php's `scoped_models`.
        // 68, UP FROM 65: theme_versions, theme_published_versions and theme_publication_events all
        // carry `store_id` (P3-M3-C). The column is real and the rows are tenant-owned; what
        // changed is the count, not the classification rule.
        $this->assertCount(
            68,
            $this->tenantDiscriminated,
            "The set of models whose table carries a `store_id` column changed.\nMeasured now:\n  "
            . implode("\n  ", $this->tenantDiscriminated)
        );
    }

    public function test_platform_global_models_are_the_measured_twenty_eight(): void
    {
        // 28, UP FROM 25 -- GATE F CLOSED ON 2026-11-02. Three models' tables now exist and have
        // NO `store_id` column, which is a finding rather than a bookkeeping change:
        // DeliveryBoyDevice, DeliveryBoyNotification and DeliveryEarning are reached through
        // `delivery_boy_id` and `order_id` only, so a driver's devices, notifications and
        // earnings are NOT tenant-discriminated at the row level. Their isolation rests entirely
        // on the DeliveryBoy they hang off -- which IS enforced (config/tenancy.php) -- and on the
        // handler filtering by that driver. Measured, docs/GATE-F-VERIFIED-SCHEMA.md section 2:
        // all three foreign-key to `delivery_boys` (and `orders`) with ON DELETE CASCADE and
        // none to `stores`.
        $this->assertCount(
            28,
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
     * R4-06 / GATE F, INVERTED ON 2026-11-02 BECAUSE GATE F CLOSED.
     *
     * This was `test_gate_f_money_models_still_have_no_table`, asserting the tables were ABSENT,
     * with the message *"GATE F table `<table>` now EXISTS. <class> must be classified and audited
     * before it is enforced, and the GATE F skips elsewhere in this suite must be revisited rather
     * than left skipped."* That assertion fired, and this is the audit it demanded.
     *
     * The direction is reversed rather than the test deleted, so the file keeps a live property: a
     * reverted migration fails here. And the second half of the old test is KEPT UNCHANGED and is
     * the important half -- none of these models may be allowlisted for enforcement on the strength
     * of its table merely existing. A table is not an audit.
     *
     * WHY NONE IS ALLOWLISTED. `StoreScope` adds a `store_id` predicate, and enforcing it on a
     * money model changes which rows a merchant and the operator can see. That is the class of
     * change this codebase has been bitten by twice, and it needs its own evidence package: a
     * full-suite run with the model allowlisted, plus a count of legacy rows whose `store_id` is
     * NULL -- which `null_tenant => 'open'` would leave visible to everybody anyway. The tables
     * arriving does not supply that evidence; it only makes collecting it possible.
     */
    public function test_the_gate_f_money_tables_exist_and_none_is_allowlisted_on_that_basis(): void
    {
        $gateF = [
            \App\Models\StoreWallet::class => 'store_wallets',
            \App\Models\StoreWalletTransaction::class => 'store_wallet_transactions',
            \App\Models\StoreWithdrawal::class => 'store_withdrawals',
            \App\Models\CurrencyRate::class => 'currency_rates',
            \App\Models\DeliveryEarning::class => 'delivery_earnings',
        ];

        foreach ($gateF as $class => $table) {
            $this->assertTrue(
                Schema::hasTable($table),
                "GATE F table `{$table}` is MISSING from a database built by this repository's "
                . 'migrations. GATE F closed on 2026-11-02; check that the migration has not been '
                . 'reverted or renamed, because a fresh environment then lacks a money table that '
                . 'production has.'
            );

            $this->assertFalse(
                StoreScope::isAllowlisted($class),
                $class . ' has been allowlisted for tenant enforcement. The table existing is NOT '
                . 'the audit that justifies that: enforcing StoreScope changes which rows a '
                . 'merchant and the operator can see on a MONEY model, which needs a full-suite '
                . 'run with the model allowlisted and a count of legacy NULL-store_id rows first.'
            );
        }

        // The sixth GATE F money table has no model at all, so it is asserted directly.
        $this->assertTrue(
            Schema::hasTable('store_order_financials'),
            'store_order_financials is missing. OrderFinancialService::createForOrder() writes it '
            . 'through DB::table(), so without it every order financial write fails.'
        );
    }

    public function test_models_without_a_table_are_the_measured_two(): void
    {
        // 2, DOWN FROM 12 -- GATE F CLOSED ON 2026-11-02. Ten of the twelve gained a table: seven
        // moved into the tenant-discriminated partition (StoreWallet, StoreWalletTransaction,
        // StoreWithdrawal, CurrencyRate, OtpIntegration, ZidStore, ZidEvent) and three into
        // platform-global (DeliveryBoyDevice, DeliveryBoyNotification, DeliveryEarning).
        //
        // THAT IS THE WHOLE POINT OF THIS PARTITION SHRINKING. This suite describes the
        // withoutTable category as one where "enforcement cannot be proven, so it must not be
        // claimed" -- ten models have just left it, which means ten classifications that were
        // unprovable are now answerable. None of them is allowlisted on that basis; see
        // test_the_gate_f_money_tables_exist_and_none_is_allowlisted_on_that_basis.
        //
        // The historical note below is kept because it records a different movement.
        // 12, DOWN FROM 14, and the two that left are exactly the two models 1bd56358 deleted:
        // App\Models\ChatwootIntegration and App\Models\ChatwootNotificationTemplate. The set
        // was dumped at the merge base and is identical to this one plus those two.
        //
        // The TABLES those models named were deliberately kept by that commit (they hold the
        // store-to-account mapping the replacement migrates from), so nothing was dropped from
        // the database here -- only the two PHP classes went, and in this environment they had
        // no table to be scoped against in the first place.
        $this->assertCount(
            2,
            $this->withoutTable,
            "The set of models with no table changed.\nMeasured now:\n  "
            . implode("\n  ", $this->withoutTable)
        );
    }

    // -------------------------------------------------------------------------------------
    //  P1-PLT-03: EVERY TRAIT-CARRIER THAT IS NOT ENFORCED IS ACCOUNTED FOR
    // -------------------------------------------------------------------------------------

    /**
     * Trait-carrying models that are not enforced and are not yet CLASSIFIED either.
     *
     * P1-PLT-03 asked for "a documented inventory of which models are scoped and which are
     * deliberately not", and the audit that answered it found exactly one gap. Seventeen models
     * carry BelongsToStore; ten are enforced; six of the remaining seven are accounted for by
     * publicStorefrontModels() (Product, Category, Shipping) or by the GATE F tableless set
     * (StoreWallet, StoreWalletTransaction, StoreWithdrawal).
     *
     * `DeliveryBoy` was the seventh, named in BelongsToStore's docblock as one of the seven and
     * explained NOWHERE. An unexplained exclusion is a hole in an inventory, so it was measured
     * rather than left:
     *
     *   - `delivery_boys.store_id` EXISTS and is `nullable()->index()`.
     *   - It is NOT the `Role` nullable trap. Both writers set the column --
     *     DeliveryBoyApiController:160 (`'store_id' => $store_id`) and DeliveryBoyController:71
     *     (`= getCurrentStore()`) -- so this codebase creates no driver with a NULL store. LEGACY
     *     rows are the one precondition an operator must check first, and that is a single count.
     *   - Enforcing it would NOT break driver login: a driver authenticates on the `deliveryboy`
     *     guard, which `auth()->user()` on `web` cannot see, so the lookup resolves
     *     MODE_UNRESOLVED and `null_tenant => 'open'` adds no predicate.
     *   - It WOULD add protection: of 26 `DeliveryBoy::` query sites in app/, only 4 carry a store
     *     filter.
     *
     * So it is an ENFORCEMENT CANDIDATE rather than a deliberate exclusion. It is not enforced
     * here, deliberately: that changes the visibility of an AUTHENTICATION PROVIDER, which is the
     * class of change that has twice broken this codebase, and the evidence it needs is a
     * full-suite run with the model allowlisted -- a package of its own.
     *
     * @return array<class-string, string> class => why it is still a candidate rather than settled
     */
    public static function enforcementCandidates(): array
    {
        /*
         * EMPTY, and that is the point of P1-PLT-08.
         *
         * `DeliveryBoy` was the one entry: store_id set by both writers, driver login resolving no
         * web tenant, 22 of 26 read sites unfiltered -- enforcement looked safe and was held back
         * only because it changes an AUTHENTICATION PROVIDER's visibility, which needed its own
         * full-suite evidence rather than a reading. That evidence exists now
         * (tests/Feature/Tenancy/DeliveryBoyScopeTest plus the batch-2 isolation matrix), so the
         * model is allowlisted in config/tenancy.php and is no longer a candidate.
         *
         * The list stays, and the exhaustiveness assertion below still consults it, because the
         * next trait-carrier added to a model without an allowlist entry has to land somewhere
         * reasoned rather than pass unnoticed -- which is exactly how DeliveryBoy sat unexplained.
         */
        return [];
    }

    /**
     * THE EXHAUSTIVENESS ASSERTION. A trait-carrier with no recorded reason fails here.
     *
     * This is what stops the P1-PLT-03 hole reopening. Attaching BelongsToStore to a model without
     * allowlisting it is behaviour-neutral, so it is an easy thing to do and an easy thing to
     * leave unexplained for months -- which is precisely what happened to `DeliveryBoy`. Every
     * carrier must now be either enforced or named in one of the four reasoned lists.
     */
    public function test_every_trait_carrier_is_either_enforced_or_explained(): void
    {
        $carriers = [];

        foreach ($this->eloquentModels() as $class) {
            if (in_array(BelongsToStore::class, class_uses_recursive($class), true)) {
                $carriers[] = $class;
            }
        }

        $this->assertGreaterThan(10, count($carriers),
            'Almost no model carries the trait, so the partition below would be vacuous. '
            . 'Found: ' . count($carriers));

        $explained = array_merge(
            array_keys(self::publicStorefrontModels()),
            array_keys(self::doNotScopeDespiteHavingTheColumn()),
            array_keys(self::enforcementCandidates()),
            [
                \App\Models\StoreWallet::class,
                \App\Models\StoreWalletTransaction::class,
                \App\Models\StoreWithdrawal::class,
            ]
        );

        $unexplained = [];

        foreach ($carriers as $class) {
            if (StoreScope::isAllowlisted($class)) {
                continue;
            }

            if (! in_array($class, $explained, true)) {
                $unexplained[] = $class;
            }
        }

        $this->assertSame([], $unexplained,
            "These models carry BelongsToStore, are NOT enforced, and no list records WHY. "
            . "Attaching the trait without allowlisting is behaviour-neutral, so an unexplained "
            . "carrier can sit here for months -- which is what happened to DeliveryBoy and what "
            . "P1-PLT-03 closed. Add each to publicStorefrontModels(), "
            . "doNotScopeDespiteHavingTheColumn(), enforcementCandidates(), or the GATE F "
            . "tableless set, with its reason:\n  " . implode("\n  ", $unexplained));
    }
}
