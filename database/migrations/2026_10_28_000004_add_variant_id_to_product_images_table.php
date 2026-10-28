<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-26 — `product_images.variant_id`: WHICH VARIANT THIS IMAGE BELONGS TO.
 *
 * OWNER-APPROVED, 2026-10-28: one image per Product Variant, *"reusing the existing media model,
 * Gallery Upload and `UploadLimits`"*, bound to the correct variant id and the correct store,
 * without losing a variant's price, stock or SKU and without changing a variant's identity — by
 * *"the smallest additive migration"*, and **in DEV and TEST only**. Nothing here has been run
 * against production by this work, and this file does not authorise that.
 *
 * THE COLUMN GOES ON `product_images`, NOT ON `product_variants`, and that is a safety property
 * rather than a preference. `App\Models\ProductImage` already carries a `saving` hook calling
 * `App\Services\StoredPathPolicy::productImage()` (P1-SEC-28), so path containment is inherited
 * rather than re-implemented; it already carries `store_id`; and the gallery upload loop already
 * validates through `Utility::keyWiseUpload_file()` and reports refusals by name (F75). A column
 * on `product_variants` would instead have put an image path in the same row as a variant's
 * price, stock and SKU — the three values D-26 says must not be lost — on a table whose rebuild
 * path has already destroyed financial values twice (F82, F105). Keeping media off that table is
 * the point.
 *
 * NULLABLE, AND THAT IS THE WHOLE COMPATIBILITY STORY. Every existing row is a product-level
 * gallery image and must stay one: `variant_id IS NULL` means "belongs to the product", which is
 * what all of them are today. No row changes, no existing query result changes, and the three
 * gallery writers in `ProductController` that do not set it keep working unaltered.
 *
 * NO UNIQUE INDEX ON `(product_id, variant_id)`, even though Phase 02 wants one image per
 * variant. The handler enforces that by replacing. An index would have to be DROPPED again for
 * Phase 03's gallery reorder (D-06) or any later multi-image-per-variant, and dropping an index
 * from a live table is a migration this project may not run. A constraint that is cheap to add
 * and expensive to remove is not cheap.
 *
 * NO FOREIGN KEY TO `product_variants`, for the reason `order_notes.created_by` records for
 * `users`: a cascade would DELETE a merchant's uploaded media as a side effect of a variant row
 * going away, and media deletion in this codebase is deliberately routed through
 * `ProductController::file_delete()`, which unlinks the file from disk under
 * `StoredUpload`'s owner-folder rule (P1-INV-09). A database cascade would remove the ROW and
 * orphan the FILE — the worst of both. A variant_id pointing at a vanished variant renders as a
 * product-level image, which is the same absence the column already models.
 *
 * INDEXED because the edit screen resolves media per variant while rendering the combination
 * panel.
 *
 * NO BACKFILL, and there is nothing to backfill from: no existing column, log or naming
 * convention ties a gallery image to a variant. Inventing an association would be a fabrication
 * of exactly the kind the owner's standing conditions forbid.
 *
 * SAFETY. `hasTable`/`hasColumn` guarded, so re-running is a no-op — and that is not caution for
 * its own sake. CLAUDE.md records that *"the migrations are not the schema"*: GATE F found 15
 * tables that live code reads and writes with no migration at all, and this file's own
 * predecessor design doc had to be corrected after a production dump refuted a claim about
 * missing `delivery_boys` columns. I cannot establish from here whether production's
 * `product_images` already carries such a column, and no production access is permitted, so this
 * adds it only if absent and is correct in both worlds.
 *
 * FLUTTER AND LYNOMIA IMPACT: none. `ProductImage` is serialised into product payloads, and an
 * additive nullable column changes no existing key's presence or type. No response is widened
 * here on purpose; the storefront and app continue to see the same gallery they see today,
 * because a per-variant image is simply another row whose `variant_id` they ignore.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_images')) {
            return;
        }

        if (! Schema::hasColumn('product_images', 'variant_id')) {
            Schema::table('product_images', function (Blueprint $table) {
                // No ->default(), no ->foreign(): see the docblock above.
                $table->unsignedBigInteger('variant_id')->nullable()->index()->after('product_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('product_images') && Schema::hasColumn('product_images', 'variant_id')) {
            Schema::table('product_images', function (Blueprint $table) {
                $table->dropColumn('variant_id');
            });
        }
    }
};
