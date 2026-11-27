<?php

namespace App\Observers;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\ImageVariants;

/**
 * WHERE RESPONSIVE VARIANTS ARE GENERATED: AT THE MOMENT THE PATH IS PERSISTED.  GAP-274.
 * =================================================================================================
 *
 * THE CHOICE, AND WHAT WAS REJECTED.
 *
 * `on demand` -- a route that resizes when a variant is first requested -- needs a public route, a
 * cache, a cap on the sizes a stranger may ask for (or an anonymous visitor can make the server
 * resize a 40-megapixel image at 400 arbitrary widths), and an answer for what happens when the
 * cache is cold during a traffic spike. That is a bigger decision than this slice, and it adds an
 * attack surface where this adds none.
 *
 * `in the uploader` -- inside `Utility::upload_file()` / `keyWiseUpload_file()` /
 * `multi_upload_file()` -- would resize EVERY upload the platform takes: payment receipts, KYC
 * documents, invoice marks, ticket attachments, PDFs. It is also four functions and they are not
 * the only writers.
 *
 * SO: THE MODEL, NOT THE MECHANISM. The two columns a storefront product image is ever stored in
 * are `products.cover_image_path` and `product_images.image_path`, and every writer goes through
 * Eloquent to put a value there -- the panel (`ProductController::store/update`), the admin API
 * (`Api\DashboardController`), the WooCommerce/Shopify importer (`Utility::upload_woo_file()`) and
 * the ImportExport CSV importer, which writes the column from a spreadsheet cell with no uploader
 * at all. Keying on the DATA rather than on the upload path covers all of them in two hooks, and
 * covers the next writer somebody adds without it having to know this exists.
 *
 * IT IS THE SAME PLACE THE PATH IS ALREADY JUDGED. `StoredPathPolicy::product()` and
 * `::productImage()` run on `saving`; this runs on `saved`, so the value has already been held to
 * the stored-path rule by the time anything is generated from it.
 *
 * COST. Only when the column CHANGES (`wasChanged()`), so the stock decrement on every checkout --
 * which saves the product -- generates nothing. A changed cover image costs up to five `gd`
 * resizes; `ImageVariants::generate()` skips a rung that is already on disk, is capped at
 * 40 megapixels of source, and cannot throw. A CSV import of N products with new images therefore
 * costs N times that, which is the one case worth watching: it is bounded work per row, done once.
 *
 * THE STORE COMES FROM THE ROW, NEVER FROM THE REQUEST. `ImageVariants` refuses to write outside
 * the store folder it is given, and the value handed to it is `$product->store_id` as persisted --
 * not `$request->store_id`, which CLAUDE.md §1 records as the shape of four separate defects.
 */
final class ProductImageVariantObserver
{
    /**
     * A ROW WAS INSERTED, so whatever path it carries is new.
     *
     * `created` and `saved` are BOTH needed, and `wasRecentlyCreated` is not a substitute for
     * either -- MEASURED while writing the test that pins the cost guarantee. After an insert
     * Laravel's `performInsert()` never calls `syncChanges()`, so `wasChanged()` is FALSE on a
     * freshly created row and a `saved`-only hook would generate nothing for a new product. And
     * `wasRecentlyCreated` stays true on that model INSTANCE for the rest of its life, so
     * `wasChanged(...) || wasRecentlyCreated` fires on every later save of the same object --
     * `ProductController::store()` saves the product several times in one request, and the stock
     * decrement at checkout saves it again. The test caught exactly that.
     */
    public function created(Product|ProductImage $model): void
    {
        $this->generateFor($model);
    }

    /**
     * A row was UPDATED. Only a changed path is interesting: `Product::saved` fires on every save,
     * the stock decrement on every checkout among them, and re-running up to five `gd` resizes per
     * order would be invisible from the outside because it produces the same files.
     *
     * ONE METHOD NAME PER EVENT FOR BOTH MODELS, because an observer's method name IS the event
     * name: a class registered for two models answers `saved` for both, and two differently-named
     * methods would mean one of them was never called by anything.
     */
    public function saved(Product|ProductImage $model): void
    {
        $column = $model instanceof Product ? 'cover_image_path' : 'image_path';

        if ($model->wasChanged($column)) {
            $this->generateFor($model);
        }
    }

    private function generateFor(Product|ProductImage $model): void
    {
        if ($model instanceof Product) {
            ImageVariants::generate($model->cover_image_path, self::storeOf($model->store_id));

            return;
        }

        /*
         * A gallery row's own store. `ProductImage` carries `store_id`, but the admin API's update
         * path creates rows without it (`Api\DashboardController` sets only `product_id`,
         * `image_path` and `image_url` on one of its two branches), so the product's own store is
         * the fallback -- read the way `StoredPathPolicy::productImage()` reads it, WITHOUT the
         * global scopes, because `Product` is not in `config/tenancy.php`'s `scoped_models` and a
         * scope that filters nothing must not be mistaken for one that does.
         */
        $store = self::storeOf($model->store_id)
            ?? self::storeOf(Product::withoutGlobalScopes()->whereKey($model->product_id)->value('store_id'));

        ImageVariants::generate($model->image_path, $store);
    }

    /** A store id that is actually a store id, or null -- `generate()` treats null as "do not check the folder's owner". */
    private static function storeOf($value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($id) && $id > 0 ? $id : null;
    }
}
