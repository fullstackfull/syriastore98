<?php

namespace App\Support\Metrics;

use App\Models\Product;
use App\Models\ProductVariant;

/**
 * STOCK ON HAND, VALUED AT COST AND AT RETAIL -- GAP-150 item 1, owner decision `P05-02`.
 * =================================================================================================
 *
 * `P05-02`'s requirement is that **no controller computes money inline**: the metric layer is the
 * single definition. `StoreFinanceController::index()` did not comply, and GAP-150 item 1 names it.
 * (Whether it was the LAST such surface is NOT established here and an earlier draft of this line
 * claimed it was; no census of the remaining controllers was run for this slice.) It read every
 * product of the store into memory and walked them in a nested PHP loop -- once per product, and for
 * a variant product once per variant -- accumulating six figures:
 *
 *   inventory units            how many sellable items the merchant is holding
 *   inventory cost             what those items cost the merchant                 (capital tied up)
 *   inventory retail value     what they would fetch at today's prices
 *   valued products            how many products contributed anything at all
 *   uncosted products          how many of those record no cost price
 *   uncosted retail            how much of the retail value rests on a cost nobody entered
 *
 * `potential profit` is retail minus cost and was computed on the same screen.
 *
 * -------------------------------------------------------------------------------------------------
 *  WHY THIS CLASS DOES NOT EXTEND `Metric`, AND WHY THAT IS A STATEMENT RATHER THAN A SHORTCUT
 * -------------------------------------------------------------------------------------------------
 *
 * `Metric` is the base of the three-record money layer, and `PHASE-05-METRIC-DEFINITIONS.md` §11
 * rule 1 is unambiguous about what a figure built on it must say: *"Every figure states whether it
 * measures settlement (`orders`), entitlement (`store_order_financials`) or movement
 * (`store_wallet_transactions`)."* `MetricResult::make()` enforces it -- it throws on any other
 * value -- and the base class's four table accessors reach exactly those three records plus
 * `store_wallets`.
 *
 * **INVENTORY IS NONE OF THE THREE.** It is not what a customer was asked for, not what the
 * merchant is owed, and not what moved. It is the catalogue: `products` and `product_variants`, two
 * tables no accessor on `Metric` can reach and which carry no order, no payment and no currency
 * column at all. Naming one of the three records to satisfy the constructor would put a false
 * statement into a `MetricResult` -- and a reader reconciling this figure against the ledger it
 * claimed to come from would find nothing, which is a worse failure than the inline loop.
 *
 * So the shape is the one this layer already uses for a figure that is not one of the three:
 * `FinanceLedgerFigures`, `WalletEntitlementFigure`, `LedgerCoverage`, `CouponRedemptionCoverage`,
 * `MerchantAnalyticsFigures`, `PlatformAnalyticsFigures` and `RetentionFigures` are all final
 * classes in this namespace that are not `Metric` subclasses. What is kept is the DISCIPLINE, not
 * the inheritance: this class takes its tenant predicate as a `MetricScope` it cannot be built
 * without, takes a `MetricWindow` and says what it did with it, carries `MetricCaveat` keys, and
 * exposes its own notes -- the four things §11 asks a figure to state that apply to it.
 *
 * THE DOCUMENTED CENSUS NOW COVERS THIS FIGURE, AND IT DID NOT WHEN THIS CLASS WAS WRITTEN. An
 * earlier version of this paragraph recorded the gap, and it was real: declaring no `definition()`
 * kept `MetricDefinitionsAreDocumentedTest` from requiring an inventory section, and the document
 * had none -- its numbered sections ran 0a, 0 and 1..15 with no stock figure among them. That
 * section exists as of 2026-11-25: `PHASE-05-METRIC-DEFINITIONS.md` **§16**, written from this
 * docblock and re-measured against this code, and the method below cites it.
 *
 * CITING IT COSTS THIS CLASS NONE OF THE REASONING ABOVE. The census pairs a section with whichever
 * class DECLARES a citation, not with whatever extends `Metric` -- so this class is cited while
 * remaining what it is, and §16 states in the document's own voice why inventory is not one of the
 * three records. Both directions are now enforced: a citation pointing at a missing section fails,
 * and a §16 whose `Computed by` line stopped naming this class fails the other way. The authority
 * for what these figures mean is §16 first, then this docblock and
 * `tests/Feature/Reports/StoreInventoryValuationMetricTest`.
 *
 * -------------------------------------------------------------------------------------------------
 *  THE TENANT PREDICATE IS THE ONLY ONE THERE IS
 * -------------------------------------------------------------------------------------------------
 *
 * `Product` and `Category` carry `StoreScope` **and are absent from `config/tenancy.php`'s
 * `scoped_models`**, so `StoreScope::isActiveFor()` short-circuits and the scope adds NO predicate
 * for any principal -- not a merchant, not the platform operator. `getGlobalScopes()` answers
 * `[StoreScope]` for both, which is why checking that asks the wrong question (CLAUDE.md).
 * `ProductVariant` carries no scope at all. `null_tenant => 'open'` means an unresolved tenant adds
 * nothing either. So `Product::query()` reaches every tenant's catalogue, and a forgotten predicate
 * here does not error and does not return nothing: it reports the whole platform's capital under
 * one merchant's heading.
 *
 * The predicate therefore comes from a `MetricScope`, which cannot be empty and cannot be built
 * from a store id the caller was handed by a request -- `StoreFinanceController::index()` resolves
 * it from the authenticated principal through `getCurrentStore()`, having already refused both an
 * unresolved store and an operator who has chosen no merchant. It is applied **twice** in the
 * variant branch, once on each side of the join, and the two sides are also required to AGREE:
 * `products.store_id` and `product_variants.store_id` are two independent columns and nothing in
 * the schema forces them to match, so a join written from `product_id` alone would value another
 * tenant's variant row into this merchant's capital. The loop this replaces excluded that row with
 * a second `where('store_id', $storeId)` on its per-product variant query; `whereColumn` preserves
 * that for one store and is strictly tighter for an operator scope naming several.
 *
 * -------------------------------------------------------------------------------------------------
 *  THE ARITHMETIC IS DONE BY THE DATABASE, IN TWO QUERIES INSTEAD OF 1 + N
 * -------------------------------------------------------------------------------------------------
 *
 * One query per branch -- simple products, then variant products joined to their variants -- and
 * the only arithmetic left in PHP is adding the two result rows together and rounding, which is
 * what the loop's own tail did. Nothing needs a GROUP BY: the two per-product quantities the loop
 * tracked (`$productUnits`, `$productRetail`) exist only to decide whether a product was valued and
 * to attribute its retail value to the uncosted disclosure, and both reduce to flat aggregates --
 * `COUNT(DISTINCT products.id)` and a conditional `SUM`. A product with at least one in-stock
 * variant is exactly a product the join produces a row for, so "valued" needs no subtotal.
 *
 * COUNTING IS DONE THROUGH ELOQUENT RATHER THAN `DB::table()` ON PURPOSE, which is the reasoning
 * `MerchantAnalyticsFigures` already states for the same decision: a raw query would have to be
 * declared in the three raw-query tenancy inventories, and an Eloquent read carrying its own
 * `where` needs no exception. Every fragment interpolated into `selectRaw` below is a literal
 * written in this file -- no column name, table name or value comes from a request -- so there is
 * no injection surface to argue about.
 *
 * `disableCache()` ON BOTH READS, AND IT IS LOAD-BEARING RATHER THAN TIDY. MEASURED: without it, a
 * product's `cost_price` saved through Eloquent -- which is how `ProductController::store()` and
 * `::update()` write it -- left the inventory COST figure at its old value. The write path was
 * driven as `$product->cost_price = …; $product->save()`, not through the form's HTTP route.
 * `Product` and `ProductVariant` are two of the 79 `Cachable` models, and
 * laravel-model-caching keys a query under the tag of the model it was built from -- so the variant
 * leg, rooted on `ProductVariant`, reads `products.cost_price` through a cache a `Product` save
 * does not flush. Driven on `CACHE_STORE=array`: `cost_price` 10 -> 20 on a product holding 3
 * units in one variant left `cost()` answering 30.00 where the table said 60.00, with the simple
 * leg correctly refreshed beside it. Rooting the query on `Product` instead only moves the defect,
 * because a VARIANT stock edit would then be the write the tag cannot see.
 *
 * IT IS NOT A SPECIAL CASE EITHER. Every other money read in this layer goes through `Metric`'s
 * four `DB::table()` accessors, which never touch the model cache at all; this class uses Eloquent
 * ONLY to stay out of the raw-query tenancy inventories, so opting out of the cache restores the
 * layer's own semantics rather than departing from them. `CustomerSegment::customers()`,
 * `RecoveryCandidates` and `PlanPurchaseSettlement` all do the same for the same trait, each for a
 * measured reason.
 *
 * WHETHER PRODUCTION WOULD HAVE SHOWN IT IS A SEPARATE QUESTION AND THE ANSWER IS PROBABLY NOT,
 * which is why it is recorded rather than left to be rediscovered: `config/laravel-model-caching.php`
 * leaves `store` unset, so the model cache uses the default store -- `database` in production, which
 * is not taggable, so the package falls back to flushing the WHOLE store on every model save. The
 * staleness is live wherever the model cache is taggable, which includes the test suite's
 * `CACHE_STORE=array`, and would become live in production the moment F194's isolation runbook
 * pointed the model cache at a taggable store.
 *
 * ONLY FUNCTIONS BOTH ENGINES HAVE. `SUM`, `COUNT`, `COUNT(DISTINCT ...)`, `CASE` and `COALESCE`
 * only. The suite runs on SQLite, which has no `MONTH()` or `YEAR()`; most of `ReportController`
 * answers HTTP 500 in a Feature test for exactly that reason, and a metric that could not be driven
 * by a test would be a figure nothing checks.
 *
 * `COALESCE(SUM(...), 0)` RATHER THAN `SUM(...)`: a `SUM` over no rows is NULL, not 0. The loop's
 * initialised zeros made an empty catalogue read `0.00` for free and aggregates do not, so an
 * unguarded null would reach `number_format()` on the Blade as a deprecation and flip the profit
 * tile's `>= 0` colour branch.
 *
 * BUT THE `COALESCE` IS NOT WHAT STOPS IT, AND SAYING SO MATTERS because an earlier draft of this
 * paragraph claimed it was. MEASURED BY MUTATION: removing every `COALESCE` reddens nothing, and
 * neither does also removing `readRow()`'s cast. PHP coerces null in arithmetic -- `null + null` is
 * 0 -- so the branch simply cannot be reached from here: a null is added before it is returned, and
 * the constructor's promoted properties are typed `int` and `float` besides, so an escape would be
 * a TypeError rather than a silent null. The `COALESCE` stays because an aggregate answering NULL
 * for "nothing" is wrong in the SQL whatever PHP then does with it, and `readRow()` is the single
 * place either engine's return type is normalised -- MySQL hands `SUM()` over a `DECIMAL` back as a
 * STRING, measured, and the view needs a float. What IS pinned, by
 * `StoreInventoryValuationMetricTest::test_a_store_with_no_products_reads_zero_rather_than_null`,
 * is the observable contract: an empty catalogue reaches the view as 0 and 0.00, of the right type.
 *
 * -------------------------------------------------------------------------------------------------
 *  WHAT THE FIGURES SAY ABOUT `cost_price` BEING ZERO, AND ABOUT PRECISION. BOTH MEASURED.
 * -------------------------------------------------------------------------------------------------
 *
 * **ZERO AND UNKNOWN ARE THE SAME STORED VALUE.** `products.cost_price` is
 * `decimal(15,2) NOT NULL DEFAULT 0.00` -- read off a restored production dump on 2026-10-24
 * (`docs/GATE-F-VERIFIED-SCHEMA.md`, 45 rows, 36 of them 0.00) and re-measured on MariaDB 10.11 for
 * this slice. The column has no way to say "nobody entered a cost": a merchant who never typed one
 * is stored identically to one whose item genuinely cost nothing. `2026_10_26_000100`'s own header
 * records that its draft argued for a nullable column for this exact reason and was overtaken by
 * the reconciliation, and that making it nullable is a change to a live money column reserved to
 * the owner.
 *
 * So the figure is NOT corrected -- it cannot be, the data is absent -- and it does not pretend to
 * be precise either. `MetricCaveat::COST_UNKNOWN` is attached **conditionally**, when at least one
 * valued product reads 0, which is §8's own rule for the same caveat on the same column's order-time
 * snapshot. It is measured and self-adjusting: it falls silent on its own as merchants fill cost in,
 * and `notes()` carries the count and the exact retail amount that rests on it, so a surface can say
 * how much the gap is worth rather than printing a fixed sentence. A product valued at 0 cost
 * contributes its WHOLE retail value to potential profit with nothing subtracted.
 *
 * TWO HONEST LIMITS OF THAT CAVEAT, stated because neither is obvious. Its message
 * ("...was not recorded for some orders, so margin is overstated for those rows") is worded for
 * §8's per-ORDER margin; it is the right machine key for this condition and the wrong noun for this
 * surface, and `MetricCaveat` is not this slice's to edit. `/finance` renders its own already
 * translated sentence from `uncostedProducts()` and `uncostedRetail()`, so no merchant is shown the
 * order-flavoured wording from here. And a `cost_price` of 0 that is DELIBERATE -- a genuinely free
 * item -- is disclosed as unknown, because the column cannot tell the two apart.
 *
 * **PRECISION: THE COST FIGURE IS DECIMAL, THE RETAIL FIGURES ARE NOT.** Measured, not read off a
 * migration, because `float()` in Laravel 11 emits a MySQL `double` and a caveat has already been
 * attached on this project on the strength of a migration's wording. Running the four declarations
 * through `Schema::create()` against MariaDB 10.11 and reading `SHOW CREATE TABLE` back:
 *
 *     products.cost_price                decimal(15,2) NOT NULL DEFAULT 0.00   -- exact
 *     products.price                     double NOT NULL DEFAULT 0
 *     products.sale_price                double NOT NULL DEFAULT 0
 *     product_variants.price             double NOT NULL
 *     product_variants.variation_price   double DEFAULT NULL
 *
 * -- note that `double('price', 10, 2)` in `create_product_variants_table` emits a BARE `double`,
 * with the precision arguments dropped. So the cost figure is summed from an exact column and
 * carries no precision caveat, while retail value, potential profit and uncosted retail all rest on
 * `double` columns and do. The caveat used for them is `MetricCaveat::MONEY_FLOAT_PRECISION` rather
 * than `FLOAT_PRECISION`, for the reason that constant was created: `FLOAT_PRECISION` says "This
 * figure", which cannot name which of several money figures in one row it means. `notes()` then names
 * them exactly -- `float_precision_figures` and `exact_figures` -- so the plural caveat is not the
 * last word. A caveat that is on everything distinguishes nothing, and attaching one to the cost
 * figure would be false besides.
 *
 * -------------------------------------------------------------------------------------------------
 *  CURRENCY, AND THE ONE PIECE OF THE OLD LOOP'S BEHAVIOUR THAT IS SUBTLE
 * -------------------------------------------------------------------------------------------------
 *
 * **NEITHER TABLE RECORDS A CURRENCY.** There is no `currency_code` on `products` or
 * `product_variants` -- the figures are denominated in whatever currency the merchant typed their
 * prices in, which is the store's own setting, and `/finance` prints them under that heading. So
 * there is no per-currency bucket to build and nothing to convert: §11 rule 3 forbids conversion
 * and nothing here converts. It is recorded in `notes()['currency_recorded'] = false` rather than
 * guessed, because a figure that silently adopts a unit it was never measured in is the defect that
 * rule exists to prevent.
 *
 * **THE TWO BRANCHES CHOOSE A SELLING PRICE BY DIFFERENT RULES, AND THAT IS DELIBERATE.** Both
 * rules are CARRIED OVER from the loop rather than re-decided here; the reasoning came with them,
 * because it is the kind that is lost in a move.
 *
 * A simple product uses its `sale_price` only when that is greater than zero. P5-10's finding was
 * that `$product->sale_price ?? $product->price ?? 0` could never reach its second operand: `??`
 * fires on NULL alone and `products.sale_price` is `NOT NULL DEFAULT 0`
 * (`create_products_table:32`), so a product simply not on sale was valued at 0 while still
 * contributing its full cost, and "Potential Profit" rendered as `0 - cost`, negative, in debit red
 * (`finance/index.blade.php:208`). `> 0` is this product's OWN convention for "is there a sale
 * price", not a new judgement invented for a metric: `Product.php:323` `!empty($product->sale_price)`,
 * `product_price.blade.php:4` the same, `preview.blade.php:59`
 * `(float) ($product->sale_price ?? 0) > 0`, and `ApiController` 499/781/804
 * `!empty(...) ? ... : 0`.
 *
 * A NEGATIVE `sale_price` therefore falls through to the list price, and that limb is load-bearing
 * rather than defensive: the four HOST validators do enforce `min:0`, but THREE MODULE WRITERS OF
 * THE SAME COLUMN DO NOT -- `ImportExport`'s `ProductsImportData:30` (`required|numeric`, no
 * `min:0`), `AuctionProduct`'s controller :118 and `DigitalProduct`'s :98 (`required` alone). A
 * census keyed on `app/` missed all three, which is the recurring census failure of this repository
 * and is why `FinanceInventoryValuationTest::test_a_negative_sale_price_falls_through_to_the_list_price`
 * exists.
 *
 * A variant uses `COALESCE(variation_price, price, 0)`, the faithful translation of
 * `$variant->variation_price ?? $variant->price ?? 0`, because `product_variants.variation_price`
 * IS nullable (measured above) and that fall-through works: a variant whose variation price is a
 * stored 0 is valued at 0, exactly as it was. One spelling, two column definitions; a repair that
 * "corrected" both would break the working half, which `FinanceInventoryValuationTest` pins in both
 * directions and which continues to drive these rules through `/finance` after the move.
 *
 * ONE AMBIGUITY IS NOT RESOLVED BY ANY OF THIS AND CANNOT BE, and it is the mirror of the
 * `cost_price` problem above: `min:0` admits a DELIBERATE `sale_price` of 0 ("free"), and the
 * column's default for "never set" is also 0, so the schema cannot tell the two apart. A genuinely
 * free product is valued at its list price in this figure. Resolving it needs a nullable column or
 * a separate flag -- a schema change, and not this slice's.
 *
 * NULL-TOLERANT WHERE THE LOOP WAS. Every `?? 0` in the loop is a `COALESCE` here, and the simple
 * branch's predicate is `variant_product IS NULL OR variant_product <> 1` rather than a bare
 * inequality. The migration declares that column NOT NULL, so the null limb is unreachable as
 * declared -- and it is kept for the two reasons `GrossMerchandiseValue::placedOrders()` keeps its
 * own: the migrations are not the schema, and in SQL `NULL <> 1` is NULL rather than true, so a null
 * would silently drop the product from the figure instead of failing. PHP's `== 1` sends it to the
 * simple branch; so does this.
 *
 * -------------------------------------------------------------------------------------------------
 *  WHAT THE MUTATION RUN FOUND, INCLUDING WHAT IT COULD NOT REACH
 * -------------------------------------------------------------------------------------------------
 *
 * Twenty-six mutations of this class were driven against
 * `tests/Feature/Reports/StoreInventoryValuationMetricTest`; TWENTY reddened it and six survived.
 * FIVE of the twenty only reddened it after the test grew, each addition made because a mutation
 * had survived without it: a stray variant row on a SIMPLE product, a variant product whose only
 * variant is out of stock, a two-store scope, a 0.30-minus-0.10 fixture, and a case that edits a
 * cost price and loads the screen AGAIN -- every other test reads it once, where a stale cache and
 * a fresh one are indistinguishable. The six survivors fall into three groups, and each is a claim
 * about what the test CANNOT see rather than a gap left unexamined:
 *
 *   * EITHER of the variant branch's two `whereIn`s, dropped ALONE, survives -- and the third
 *     predicate is why. `products.store_id IN (...)`, `product_variants.store_id IN (...)` and the
 *     join's store-agreement `whereColumn` are three constraints over two columns, so any two
 *     imply the third for the scopes a test can drive. Dropping the agreement ALONE reddens, under
 *     the two-store scope the test reads directly; dropping BOTH `whereIn`s reddens. All three are
 *     kept: it is the belt-and-braces pairing `MerchantWalletEntitlement::drift()` writes out for
 *     the same two independent columns, and no single removal that changes a figure survives.
 *   * dropping the `variant_product IS NULL` limb survives, and cannot do otherwise. The column is
 *     `tinyint(1) NOT NULL` on MariaDB and NOT NULL on the suite's SQLite -- both measured -- so no
 *     row can exercise it. It is kept for the reason `GrossMerchandiseValue` keeps its own
 *     unreachable `delivered_status` null limb, and this is said rather than left as a green tick.
 *   * dropping `COALESCE`, or `COALESCE` and the cast, or both and the `?? 0` as well, all survive,
 *     for the reason given above: PHP coerces the null away before it can be observed.
 *
 * NOTHING HERE WRITES, and the two reads are aggregates.
 */
final class StoreInventoryValuation
{
    /** A stable machine key. Never translated; a surface or an export may branch on it. */
    public const KEY = 'store_inventory_valuation';

    /**
     * What this figure is measured ON -- deliberately not one of `MetricResult`'s three records.
     *
     * See the docblock: inventory is the catalogue, not settlement, entitlement or movement. The
     * constant exists so a reader of a dumped figure can tell what it was taken from, and so that
     * nobody is tempted to borrow one of the three names for it.
     */
    public const SOURCE_CATALOGUE = 'catalogue';

    /** The retail figures rest on `double` columns; the cost figure does not. Measured. */
    private const FLOAT_PRECISION_FIGURES = ['retail_value', 'potential_profit', 'uncosted_retail'];

    private const EXACT_FIGURES = ['cost'];

    /**
     * @param  string[]  $caveats
     * @param  array<string, mixed>  $notes
     */
    private function __construct(
        private readonly MetricScope $scope,
        private readonly MetricWindow $window,
        private readonly int $units,
        private readonly float $cost,
        private readonly float $retailValue,
        private readonly int $valuedProducts,
        private readonly int $uncostedProducts,
        private readonly float $uncostedRetail,
        private readonly array $caveats,
        private readonly array $notes,
    ) {
    }

    /**
     * WHERE THESE FIGURES ARE DEFINED IN PROSE, in the form every other cited class uses.
     *
     * `MetricDefinitionsAreDocumentedTest` INVOKES this by reflection rather than regexing the
     * source for it -- deliberately, because reading the source with comments intact is the census
     * failure this repository keeps paying for, and a docblock carrying the declaration's shape
     * would otherwise supply a citation for a class declaring none. So the string below is the
     * whole claim, and it is checked in both directions against §16.
     */
    public function definition(): string
    {
        return 'PHASE-05-METRIC-DEFINITIONS.md §16';
    }

    /** The ordinary merchant case: one tenant, all of its stock as it stands right now. */
    public static function forStore(int $storeId): self
    {
        return self::for(MetricScope::store($storeId), MetricWindow::allTime());
    }

    /**
     * STOCK ON HAND IS A RUNNING QUANTITY, SO THE WINDOW IS IGNORED -- and says so.
     *
     * The same treatment §7 gives `store_wallets.available_balance`, for the same reason: there is
     * no date column to filter on. `products.updated_at` is not one -- applying a window to it
     * would report "the stock of products edited this month", a number that means nothing at all --
     * and no table in this schema records what stock USED to be, so a historical valuation is not
     * computable from here by any query. The window is accepted so a caller cannot pass one by
     * mistake and never find out: `notes()['window_ignored']` is true whenever one was.
     */
    public static function for(MetricScope $scope, MetricWindow $window): self
    {
        $storeIds = $scope->storeIds();

        $simple = self::simpleProducts($storeIds);
        $variants = self::variantProducts($storeIds);

        // `readRow()` is the single place these are typed; adding them needs no second cast.
        $units = $simple['units'] + $variants['units'];

        /*
         * ROUNDED EXACTLY WHERE THE LOOP ROUNDED. Cost and retail are rounded first and the profit
         * is then taken from the ROUNDED pair -- the loop's tail did
         * `round($retail, 2) - round($cost, 2)` by assignment order, and taking the difference at
         * full precision instead would move the last cent on some data.
         */
        $cost = round($simple['cost'] + $variants['cost'], 2);
        $retailValue = round($simple['retail'] + $variants['retail'], 2);
        $uncostedRetail = round($simple['uncosted_retail'] + $variants['uncosted_retail'], 2);

        $valuedProducts = $simple['valued'] + $variants['valued'];
        $uncostedProducts = $simple['uncosted'] + $variants['uncosted'];

        $caveats = [MetricCaveat::MONEY_FLOAT_PRECISION];

        if ($uncostedProducts > 0) {
            $caveats[] = MetricCaveat::COST_UNKNOWN;
        }

        return new self(
            $scope,
            $window,
            $units,
            $cost,
            $retailValue,
            $valuedProducts,
            $uncostedProducts,
            $uncostedRetail,
            $caveats,
            [
                'source' => self::SOURCE_CATALOGUE,
                'valued_products' => $valuedProducts,
                'uncosted_products' => $uncostedProducts,
                'uncosted_retail' => $uncostedRetail,
                'currency_recorded' => false,
                'float_precision_figures' => self::FLOAT_PRECISION_FIGURES,
                'exact_figures' => self::EXACT_FIGURES,
                'running_quantity' => true,
                'window_ignored' => ! $window->isAllTime(),
            ],
        );
    }

    /**
     * EVERY NON-VARIANT PRODUCT WITH STOCK, VALUED IN ONE QUERY.
     *
     * @param  int[]  $storeIds
     * @return array<string, float|int>
     */
    private static function simpleProducts(array $storeIds): array
    {
        $selling = '(CASE WHEN COALESCE(products.sale_price, 0) > 0 '
            . 'THEN COALESCE(products.sale_price, 0) '
            . 'ELSE COALESCE(products.price, 0) END)';

        $cost = 'COALESCE(products.cost_price, 0)';

        $row = Product::disableCache()
            ->whereIn('products.store_id', $storeIds)
            ->where(function ($notAVariant) {
                // See the docblock: `NULL <> 1` is NULL, not true, so the null limb is a predicate
                // rather than a precaution.
                $notAVariant->whereNull('products.variant_product')
                    ->orWhere('products.variant_product', '!=', 1);
            })
            ->where('products.product_stock', '>', 0)
            ->selectRaw(
                'COALESCE(SUM(products.product_stock), 0) as metric_units, '
                . 'COALESCE(SUM(' . $cost . ' * products.product_stock), 0) as metric_cost, '
                . 'COALESCE(SUM(' . $selling . ' * products.product_stock), 0) as metric_retail, '
                . 'COUNT(*) as metric_valued, '
                . 'COALESCE(SUM(CASE WHEN ' . $cost . ' <= 0 THEN 1 ELSE 0 END), 0) as metric_uncosted, '
                . 'COALESCE(SUM(CASE WHEN ' . $cost . ' <= 0 '
                . 'THEN ' . $selling . ' * products.product_stock ELSE 0 END), 0) as metric_uncosted_retail'
            )
            ->first();

        return self::readRow($row);
    }

    /**
     * EVERY VARIANT PRODUCT'S IN-STOCK VARIANTS, VALUED IN ONE QUERY.
     *
     * The parent row's own `price`, `sale_price` and `product_stock` are ignored by this branch
     * exactly as the loop ignored them -- a product declared `variant_product = 1` is valued from
     * its variants and from nothing else, so one carrying stock on its parent row and no variants at
     * all contributes nothing. Its `cost_price` IS used: cost lives on the product, not the variant,
     * which is why a variant product with no cost recorded discloses the whole of its variants'
     * retail value.
     *
     * @param  int[]  $storeIds
     * @return array<string, float|int>
     */
    private static function variantProducts(array $storeIds): array
    {
        // `??` fires on NULL alone, and `variation_price` IS nullable -- so COALESCE is the
        // faithful translation and a stored 0 is a price of 0, not a fall-through.
        $selling = 'COALESCE(product_variants.variation_price, product_variants.price, 0)';

        $cost = 'COALESCE(products.cost_price, 0)';

        $row = ProductVariant::disableCache()
            ->join('products', function ($join) {
                $join->on('product_variants.product_id', '=', 'products.id')
                    // The two store columns are independent. See the docblock: a join on
                    // `product_id` alone values a foreign tenant's variant row into this
                    // merchant's capital.
                    ->whereColumn('product_variants.store_id', '=', 'products.store_id');
            })
            ->whereIn('products.store_id', $storeIds)
            ->whereIn('product_variants.store_id', $storeIds)
            ->where('products.variant_product', 1)
            ->where('product_variants.stock', '>', 0)
            ->selectRaw(
                'COALESCE(SUM(product_variants.stock), 0) as metric_units, '
                . 'COALESCE(SUM(' . $cost . ' * product_variants.stock), 0) as metric_cost, '
                . 'COALESCE(SUM(' . $selling . ' * product_variants.stock), 0) as metric_retail, '
                // A product the join produced a row for is a product with in-stock variants, which
                // is exactly the loop's `$productUnits > 0`. No subtotal is needed for either count.
                . 'COUNT(DISTINCT products.id) as metric_valued, '
                . 'COUNT(DISTINCT CASE WHEN ' . $cost . ' <= 0 THEN products.id END) as metric_uncosted, '
                . 'COALESCE(SUM(CASE WHEN ' . $cost . ' <= 0 '
                . 'THEN ' . $selling . ' * product_variants.stock ELSE 0 END), 0) as metric_uncosted_retail'
            )
            ->first();

        return self::readRow($row);
    }

    /**
     * The six aggregates off one row, as numbers.
     *
     * `$row` is null only if the engine returned no row for an aggregate query, which neither
     * engine does -- but `first()` is typed as nullable and a null here would otherwise surface as
     * "property on null" on a merchant's finance page rather than as a zero.
     *
     * @return array<string, float|int>
     */
    private static function readRow(?object $row): array
    {
        return [
            'units' => (int) ($row->metric_units ?? 0),
            'cost' => (float) ($row->metric_cost ?? 0),
            'retail' => (float) ($row->metric_retail ?? 0),
            'valued' => (int) ($row->metric_valued ?? 0),
            'uncosted' => (int) ($row->metric_uncosted ?? 0),
            'uncosted_retail' => (float) ($row->metric_uncosted_retail ?? 0),
        ];
    }

    /** How many sellable items the store is holding. A count, not money. */
    public function units(): int
    {
        return $this->units;
    }

    /** What that stock cost the merchant. Summed from the DECIMAL column, so exact. */
    public function cost(): float
    {
        return $this->cost;
    }

    /** What it would fetch at today's prices. Summed from `double` columns. */
    public function retailValue(): float
    {
        return $this->retailValue;
    }

    /**
     * Retail value minus cost.
     *
     * NOT a forecast and not margin: it subtracts no shipping, no tax, no payment fee and no
     * discount a merchant has yet to give, and for any product whose cost reads 0 it counts the
     * whole retail value as profit -- which is what `COST_UNKNOWN` is attached for.
     */
    public function potentialProfit(): float
    {
        return round($this->retailValue - $this->cost, 2);
    }

    /** How many products contributed anything. An out-of-stock product is not one of them. */
    public function valuedProducts(): int
    {
        return $this->valuedProducts;
    }

    /** How many of those record no cost price -- which is also how many record a cost of zero. */
    public function uncostedProducts(): int
    {
        return $this->uncostedProducts;
    }

    /** How much of the retail value rests on a cost nobody entered. */
    public function uncostedRetail(): float
    {
        return $this->uncostedRetail;
    }

    /** @return string[] */
    public function caveats(): array
    {
        return array_values(array_unique($this->caveats));
    }

    public function hasCaveat(string $caveat): bool
    {
        return in_array($caveat, $this->caveats, true);
    }

    /** @return string[] the sentences a merchant should see beside these figures */
    public function caveatMessages(): array
    {
        return array_map(
            static fn (string $caveat): string => MetricCaveat::message($caveat),
            $this->caveats()
        );
    }

    /**
     * The choices these figures made that a reader could not otherwise infer.
     *
     * @return array<string, mixed>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    public function scope(): MetricScope
    {
        return $this->scope;
    }

    public function window(): MetricWindow
    {
        return $this->window;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'metric' => self::KEY,
            'source' => self::SOURCE_CATALOGUE,
            'dated_by' => null,
            'units' => $this->units,
            'cost' => $this->cost,
            'retail_value' => $this->retailValue,
            'potential_profit' => $this->potentialProfit(),
            'valued_products' => $this->valuedProducts,
            'uncosted_products' => $this->uncostedProducts,
            'uncosted_retail' => $this->uncostedRetail,
            'caveats' => $this->caveats(),
            'notes' => $this->notes,
            'scope' => $this->scope->describe(),
            'window' => $this->window->describe(),
        ];
    }
}
