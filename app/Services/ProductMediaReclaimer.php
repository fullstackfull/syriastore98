<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Utility;

/**
 * FREEING A PRODUCT'S FILES AND CORRECTING THE MERCHANT'S USED-STORAGE FIGURE.
 * =================================================================================================
 *
 * P1-INV-25. This is lifted verbatim out of `ProductController::destroy()` -- same calls, same
 * order, same primitives -- for one reason: **two callers need it and only one of them may hold the
 * `Delete Products` permission.**
 *
 * THE DEFECT THIS EXISTS TO CLOSE HAD TWO INDEPENDENT CAUSES, and the task row named only the
 * first. Both had to be fixed; neither alone changed anything observable.
 *
 *   1. ORDERING. The two store-wide wipes ran `Category::where('store_id', ...)->delete()` BEFORE
 *      `$products = Product::where('store_id', ...)->get()`. `products.category_id` is
 *      ON DELETE CASCADE against `categories.id` (the same FK P1-INV-12/D13 was built around), so
 *      the cascade emptied the collection the destroy loop iterates and the loop did nothing.
 *
 *   2. THE PERMISSION GATE, found by driving it rather than by reading. `destroy()` opens with
 *      `if (auth()->user() && auth()->user()->isAbleTo('Delete Products'))` and SILENTLY DOES
 *      NOTHING otherwise -- no exception, no log. Laratrust has no admin bypass here, so a store
 *      owner deleting their own store does not necessarily hold that permission. The wipe was
 *      therefore delegating its media cleanup to a handler that could refuse it, on a permission
 *      the wipe itself never checks and does not need: both wipes have already proved the caller
 *      OWNS the store (`created_by` plus a last-store refusal in the admin API,
 *      `resolveOwnedStore()` plus a store count on the web) and deleting a store you own is
 *      entitled to take its media with it.
 *
 * MEASURED: granting `Delete Products` to the test merchant made the reordered wipe pass, which is
 * what identified cause 2 as a separate cause rather than a detail of cause 1.
 *
 * WHY A SERVICE RATHER THAN A SECOND COPY, or a change to the gate. Duplicating the block would
 * leave two media-freeing paths to keep in step -- the mistake this project has already recorded
 * for Paymera's two unrouted copies. And loosening `destroy()`'s own permission check would widen
 * an authorization gate to fix a cleanup bug, which is the wrong direction entirely: the HTTP
 * handler keeps its gate, and the callers that are authorized by other means call this directly.
 *
 * IT IS IDEMPOTENT, which acceptance criterion 4 requires -- and the reason is narrower than the
 * first version of this docblock claimed. A second pass sums 0 bytes, but by TWO different
 * mechanisms: the GALLERY is zero because the `product_images` ROWS are gone, while the cover
 * image, downloadable and preview are zero only because the FILES are gone. So if an unlink fails
 * -- a read-only mount, a permissions problem -- while the row delete succeeds, the cover image is
 * sized and debited a SECOND time. Narrow, inherited in kind from the block this replaced, and
 * stated rather than implied because the first draft asserted a single clean reason. That is what
 * lets both wipes call this AND keep their existing `destroy()` loop.
 *
 * `$ownerId` IS THE STORE'S OWNER, NOT THE CALLER, and that distinction is a defect the review of
 * this package found. Both wipes first passed `$user->creatorId()`. `StoreController`'s
 * `resolveOwnedStore()` drops its `created_by` predicate for a `super admin` (:681-683), so the
 * platform operator can wipe ANY merchant's store -- and `changeproductStorageLimit()` debits
 * `User::find($company_id)` (Utility.php:872), i.e. it would have charged the correction to the
 * OPERATOR while leaving the merchant charged for ever. That is precisely the harm this task
 * exists to remove, reintroduced on the operator path. Both callers now pass
 * `$store->created_by`.
 *
 * It was LATENT rather than live: the web wipe's last-store count asks about the CALLER's own
 * stores (`Store::where('created_by', $user->id)`), and no store writer ever sets `created_by` to
 * a super admin, so an operator owns 0 stores and is refused before reaching it. The admin API
 * twin cannot reach it at all -- its own predicate is `created_by == $user->id`. Fixed anyway: a
 * money figure debited to the wrong user should not be one data change away.
 *
 * ONE CONSEQUENCE, STATED: for `reclaimStore()` the ownership limb of `mayReclaim()` is now
 * tautological, because the owner id IS the store's `created_by`. That is acceptable -- the
 * caller's authorization is established above it -- and the guard still bites for the direct
 * `reclaim()` entry point, which is what the cross-tenant test pins.
 *
 * IT CARRIES ITS OWN OWNERSHIP CHECK, AND THE FIRST VERSION DID NOT. That version said ownership
 * of the row was the caller's business -- the line `StoredUpload` uses about itself -- reasoning
 * that rule 3 refuses a path in another tenant's store folder anyway. A test written to PIN that
 * reasoning REFUTED it: StoredUpload guards the FILE and not the ROW, so asked directly with a
 * foreign product this service happily deleted another tenant's `product_images` rows. It also
 * explains a mutant that appeared to survive -- removing `reclaimStore()`'s store predicate failed
 * no test, which read as an equivalent mutant and was in fact a gap in the TEST, not in the code.
 * So the check is here, and that mutant now bites.
 *
 * THE OPERATOR IS EXEMPT, matching both callers rather than inventing a third rule:
 * `ProductController::destroy()` exempts `UserPolicy::PLATFORM_ADMIN` from its own ownership check,
 * and `StoredUpload::mayTouchStoreFolder()` exempts a `super admin` from rule 3. A guard that
 * refused the operator would be a lockout rather than a tightening -- the ordering mistake this
 * project's skill records for authorization limbs.
 */
final class ProductMediaReclaimer
{
    /**
     * Unlink one product's files and decrement the owner's used-storage figure by their size.
     *
     * @param  int|string|null  $ownerId  the tenant owner's user id (User::creatorId())
     */
    public static function reclaim(Product $product, $ownerId): void
    {
        $stored = Product::find($product->id);

        if (empty($stored) || ! self::mayReclaim($stored, $ownerId)) {
            return;
        }

        $productImages = ProductImage::where('product_id', $product->id)->get();

        $galleryPaths = [];
        foreach ($productImages as $productImage) {
            $galleryPaths[] = $productImage->image_path;
        }

        $otherPaths = [$stored->cover_image_path];

        if (! empty($stored->downloadable_product)) {
            $otherPaths[] = $stored->downloadable_product;
        }

        // `preview_content` holds a path only for a video FILE; a video URL lives in the same
        // column, which is why the type is tested rather than the value.
        if (
            $stored->preview_type === 'Video File'
            && ! empty($stored->preview_content)
            && \File::exists(base_path($stored->preview_content))
        ) {
            $otherPaths[] = $stored->preview_content;
        }

        /*
         * THIS ONE CALL BOTH SIZES AND UNLINKS. `changeproductStorageLimit()` resolves each path
         * through `StoredUpload::resolveAll($path, $ownerId)` -- so rule 3 still refuses another
         * tenant's folder -- sums what it resolved, decrements the figure, and then DELETES every
         * file it resolved (Utility.php:877-882).
         *
         * THE BLOCK THIS REPLACES THEN LOOPED `StoredUpload::delete()` OVER THE GALLERY AGAIN,
         * which was DEAD CODE: those paths are in the merged array above, so they had already been
         * unlinked. A mutant removing that loop SURVIVED, which is what proved it dead rather than
         * merely redundant-looking, and the harness rule is to remove equivalent code instead of
         * excusing it. Inherited from the inline block, not introduced here.
         */
        Utility::changeproductStorageLimit($ownerId, array_merge($galleryPaths, $otherPaths));

        /*
         * The ROWS still have to go explicitly: `product_images` declares NO foreign key to
         * `products`, so unlike wishlists, notify_users, product_questions and product_variants
         * these are not cascaded away when the product is deleted. Without this they survive as
         * orphans pointing at files that no longer exist.
         */
        ProductImage::where('product_id', $product->id)->delete();
    }

    /**
     * Every product of one store, for the store-wide wipes.
     *
     * Read with an explicit `store_id` predicate rather than through the global scope, because
     * `Product` is NOT in `config/tenancy.php`'s `scoped_models` -- the scope adds no predicate for
     * any principal, so the filter here is the control of record, not defence in depth.
     *
     * @param  int|string  $storeId
     * @param  int|string|null  $ownerId
     */
    public static function reclaimStore($storeId, $ownerId): void
    {
        Product::where('store_id', $storeId)->get()->each(
            fn (Product $product) => self::reclaim($product, $ownerId)
        );
    }

    /**
     * May this owner free this product's media and delete its image rows?
     *
     * The operator limb is FIRST, deliberately. Reversing the two is a lockout, not a tightening:
     * `creatorId()` returns the operator's own id, which owns no merchant store, so an
     * ownership-first order would refuse the platform operator on every product -- and
     * `ProductController::destroy()` exempts them explicitly, so this would contradict its caller.
     *
     * @param  int|string|null  $ownerId
     */
    private static function mayReclaim(Product $product, $ownerId): bool
    {
        if ($ownerId === null || $ownerId === '') {
            return false;
        }

        $owner = \App\Models\User::find($ownerId);

        if ($owner !== null && strtolower(trim((string) $owner->type)) === 'super admin') {
            return true;
        }

        if (empty($product->store_id)) {
            // An unowned product has no owner to compare against, so nobody but the operator may
            // reclaim it -- the same direction StoredUpload takes for an unowned path.
            return false;
        }

        return \App\Models\Store::where('id', $product->store_id)
            ->where('created_by', $ownerId)
            ->exists();
    }
}
