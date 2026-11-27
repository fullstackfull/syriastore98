<?php

namespace App\Observers;

use App\Models\Product;
use App\Support\Search\ProductNameIndex;

/**
 * WHERE THE ARABIC FOLD OF A PRODUCT NAME IS WRITTEN: AT THE MOMENT THE NAME IS PERSISTED.
 * =================================================================================================
 * `CUST-003` / `GAP-190` / owner decision `J38`.
 *
 * -------------------------------------------------------------------------------------------------
 * THE CHOICE, AND THE CENSUS THAT DECIDED IT
 * -------------------------------------------------------------------------------------------------
 *
 * `J38` says *"a hook on every product writer"*. Counted rather than assumed, `products.name` is
 * assigned at **fifteen** sites in **seven** files -- counted, and this said "eight" until the
 * coverage test pinned the figure:
 *
 *   app/Http/Controllers/ProductController.php              3  panel create (two plan branches) + update
 *   app/Http/Controllers/Api/DashboardController.php        2  admin API create + update
 *   app/Http/Controllers/ShopifyProductController.php       2  Shopify import, two sync paths
 *   app/Http/Controllers/WoocomProductController.php        2  WooCommerce import, two sync paths
 *   packages/workdo/AuctionProduct/.../AuctionProductController.php   3   VENDORED
 *   packages/workdo/DigitalProduct/.../DigitalProductController.php   2   VENDORED
 *   packages/workdo/ImportExport/src/app/Imports/ProductsImportData.php  1  VENDORED — mass-assigns
 *                                                                           `'name' => $row['product_name']`
 *                                                                           straight from a spreadsheet cell
 *
 * **Six of the fifteen are inside `packages/workdo/`, which must not be edited** (`CLAUDE.md` §2;
 * enforced by a `PreToolUse` hook locally and `guard-no-workdo-changes.sh` in CI). So "hook every
 * writer" is not available as literally stated — a per-writer approach would leave the auction
 * module, the digital-product module and the entire CSV importer writing names with no fold, and
 * those rows would silently never be findable in Arabic.
 *
 * **There is nothing else to cover.** Measured with the inverted census `CLAUDE.md` §3 prescribes —
 * enumerate the SAFE set rather than hunt for dangerous spellings — every occurrence of
 * `DB::table('products')` in `app/` and `packages/` is a READ (`select`, `where`, `exists`,
 * `count`, `pluck`, `join`); there is no `Product::create`, `::insert`, `::updateOrCreate`,
 * `::upsert` or `Product::where(…)->update(…)` anywhere, and no raw write to the table at all. So
 * **every writer of `products.name` goes through Eloquent**, and a `saving` hook sees all fifteen
 * in one place. `ProductNameIndexCoverageTest` re-runs that census so a raw writer added later
 * fails a test instead of quietly producing stale folds — and even then the damage is bounded,
 * because `ProductNameIndex::applyNameFilter()` keeps the raw `name LIKE` limb as the authority.
 *
 * **AN OBSERVER, NOT `Product::booted()`.** `app/Models/Product.php` is named by
 * `ResidualTenancyExposureTest`'s raw-query inventory at a FILE:LINE (`CLAUDE.md` §6b), so adding
 * lines inside its `booted()` would move an unrelated security inventory row for a change that has
 * nothing to do with it. `ProductImageVariantObserver` is beside this one for the same reason and
 * records the same argument. **NOT in `app/Listeners`**: event discovery is on and registers
 * everything there with a typed `handle()` automatically, under a `Class@handle` key, which
 * `CLAUDE.md` records as a source of double registration and of tests that cannot see what is
 * registered.
 *
 * -------------------------------------------------------------------------------------------------
 * `saving`, NOT `saved`
 * -------------------------------------------------------------------------------------------------
 *
 * On `saving` the value joins the INSERT or UPDATE already in flight: no second statement, no
 * second cache flush, and no window in which a row exists with a name and no fold. A `saved` hook
 * would need its own write and would have to deal with `wasChanged()` being FALSE on a freshly
 * inserted row — the trap `ProductImageVariantObserver`'s docblock measured.
 *
 * `StoredPathPolicy::product()` already runs on `Product::saving` from the model's own `booted()`.
 * Both run; neither reads the other's column.
 *
 * -------------------------------------------------------------------------------------------------
 * IT NEVER TURNS A NO-OP SAVE INTO A WRITE
 * -------------------------------------------------------------------------------------------------
 *
 * Laravel's `save()` issues no UPDATE when nothing is dirty. If this hook set an attribute
 * unconditionally, every `$product->save()` on a row with a NULL fold would become a real UPDATE
 * **and bump `updated_at`** across the catalogue — visible in "recently modified" ordering and in
 * anything keyed on that column. So the fold is filled when the name changed, when the row is new,
 * or when the row is **already** being written for some other reason and has no fold yet (a free
 * incremental backfill), and in no other case. `ProductNameIndexObserverTest` drives all four.
 */
final class ProductNameIndexObserver
{
    /**
     * Fill `products.name_normalised` for this write, if this write should fill it.
     *
     * Silent when the column does not exist: production has no such column until the owner runs
     * `2026_11_27_000100_add_name_normalised_to_products_table.php`, and setting an attribute for a
     * column that is not there makes **every product save on the platform** throw. That is the one
     * way this slice could break writing rather than merely fail to improve searching, so the probe
     * comes first and `ProductNameIndex::isStored()` answers `false` on any error reading the
     * schema.
     */
    public function saving(Product $product): void
    {
        if (! ProductNameIndex::isStored()) {
            return;
        }

        if ($product->exists && ! $product->isDirty('name')) {
            $alreadyFolded = $product->getAttribute(ProductNameIndex::COLUMN) !== null;

            /* Nothing to do, or nothing may be done: see the docblock's no-op rule. */
            if ($alreadyFolded || ! $product->isDirty()) {
                return;
            }
        }

        $name = (string) $product->getAttribute('name');

        /*
         * An empty name folds to NULL rather than to `''`, so "no name was ever stored" and "the
         * name folds to the empty string" are not spelled the same way — the convention
         * `Order::SOURCES` states: NULL is the ABSENCE of a record, not another category.
         */
        $product->setAttribute(
            ProductNameIndex::COLUMN,
            $name === '' ? null : ProductNameIndex::normalise($name)
        );
    }
}
