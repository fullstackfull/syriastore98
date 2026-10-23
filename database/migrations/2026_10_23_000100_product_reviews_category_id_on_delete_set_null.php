<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `product_reviews.category_id` survives the deletion of the category it names.
 *
 * ============================================================================================
 *  P1-INV-24 (#117), UNDER OWNER DECISION 3.
 * ============================================================================================
 *
 * THE OWNER'S WORDS: "Implement the safest maintainable solution consistent with industry
 * practice. Preserve ProductReview records when categories are deleted or products are moved.
 * Prefer ON DELETE SET NULL for the stale category reference where the actual schema supports it.
 * Validate MySQL constraints and preserve existing data."
 *
 * THE DEFECT, and the reason it is worse than it sounds. THREE tables cascade off `categories`:
 * `products.category_id`, `testimonials.category_id` and -- unnamed anywhere until P1-INV-24 --
 * `product_reviews.category_id`, which
 * packages/workdo/ProductReview/src/database/migrations/2024_03_22_062715_create_product_reviews_table.php:25
 * declares with `->onDelete('cascade')`.
 *
 * `product_reviews.category_id` is a DENORMALISED COPY of the product's category, written once
 * from the review form and never re-pointed when the product moves. (Measured in P1-INV-24: all
 * seven `category_id` references in that module are review-form writes or display reads, and the
 * storefront form seeds it from a hidden `$product->category_id` input at
 * pages/product_review.blade.php:39.)
 *
 * So the D13 refusal's own remedy triggers the loss:
 *
 *   1. merchant deletes category X          -> refused, "N product(s) still use it, move them first"
 *   2. merchant moves the products to Y     -> products.category_id = Y, product_reviews.category_id STAYS X
 *   3. the D13 count for X falls to zero    -> the delete is now PERMITTED
 *   4. DELETE FROM categories WHERE id = X  -> the FK cascade destroys the reviews, below the application
 *
 * Exactly the loss D13 exists to prevent, on a table it does not name, reached by following its
 * own advice.
 *
 * WHY NOT A GUARD INSTEAD. P1-INV-12 deliberately did not widen its refusal onto this table, and
 * that call still stands: a stale review row pointing at a category the merchant has already
 * emptied is one they cannot see or clear from any screen, so refusing on it would make the
 * category PERMANENTLY undeletable. Widening a refusal onto data the user cannot reach is a dead
 * end, not a control. SET NULL keeps the review and drops only the stale pointer.
 *
 * ============================================================================================
 *  "WHERE THE ACTUAL SCHEMA SUPPORTS IT" -- IT DOES NOT, YET, AND THAT IS HALF THIS MIGRATION.
 * ============================================================================================
 *
 * The column is declared `unsignedBigInteger('category_id')->default(0)` -- NOT NULL. `ON DELETE
 * SET NULL` against a NOT NULL column is rejected by MySQL at constraint-creation time (errno
 * 1830 / 3780 depending on version), so the column has to become nullable first. That is step 1
 * below, and it is why this is an ALTER rather than a one-line constraint swap.
 *
 * AND THE `DEFAULT 0` IS ITSELF A LATENT VIOLATION. There is no `categories` row with id 0, so any
 * insert that omits `category_id` writes a value the foreign key cannot satisfy. Two things follow:
 * such rows may already exist (if the constraint was never created, or was created with
 * foreign_key_checks off, or the table predates it), and they would block the new constraint. Step
 * 2 converts `category_id = 0` to NULL, which is the same statement the new constraint would make
 * later anyway -- it says "this review names no category" instead of "this review names a category
 * that does not exist". NO REVIEW ROW IS DELETED, by this migration or by the constraint it
 * installs.
 *
 * ============================================================================================
 *  WHAT THIS MIGRATION REFUSES TO DO
 * ============================================================================================
 *
 * MySQL ONLY. SQLite cannot ALTER a foreign key at all -- it would require a table rebuild -- and
 * the suite runs on SQLite `:memory:` where this table does not exist in the first place (no
 * WorkDo module migration runs without an `add_on_managers` row). On any non-MySQL driver this is
 * a no-op, stated rather than silently skipped.
 *
 * TABLE-ABSENT IS A NO-OP. The table belongs to a vendored addon that may not be installed. The
 * `Schema::hasTable()` guard is what makes this safe to ship to an install without it.
 *
 * NOTHING UNDER packages/workdo/ IS EDITED. The module's own migration still declares CASCADE, so
 * a fresh install creates the cascade and this migration corrects it afterwards -- which is the
 * only shape available when the vendored file must not be touched. It is written to be re-runnable
 * for that reason: it reads the CURRENT constraint from information_schema and does nothing when
 * the rule is already SET NULL.
 *
 * APPLYING THIS TO PRODUCTION IS GATED. D15's prerequisites -- real MySQL schema evidence and a
 * verified backup restoration -- are an owner/environment matter, not an engineering one. This
 * file is the migration; running it against the live database is a separate authorised act.
 *
 * EVIDENCE: tests/Support/Security/bin/verify-product-review-category-set-null-on-mysql.php drives
 * the whole P1-INV-24 sequence on a throwaway MySQL and asserts the reviews survive.
 */
return new class extends Migration
{
    private const TABLE = 'product_reviews';
    private const COLUMN = 'category_id';

    public function up(): void
    {
        if (! $this->applicable()) {
            return;
        }

        // 1. NULLABLE FIRST. SET NULL on a NOT NULL column is rejected at constraint-creation time.
        //    Raw DDL rather than ->change(): doctrine/dbal is not installed, and a change() on a
        //    column this migration does not own would also rewrite its default and comment.
        DB::statement('ALTER TABLE `' . self::TABLE . '` MODIFY `' . self::COLUMN . '` BIGINT UNSIGNED NULL DEFAULT NULL');

        // 2. ZERO IS NOT A CATEGORY. `default(0)` wrote a value no `categories` row can satisfy, so
        //    those rows would block the constraint. They become NULL, which is what they always
        //    meant. No row is deleted.
        DB::table(self::TABLE)->where(self::COLUMN, 0)->update([self::COLUMN => null]);

        // 3. AND ANY POINTER TO A CATEGORY THAT NO LONGER EXISTS. If the constraint was absent (or
        //    was bypassed), orphans are possible, and MySQL refuses to create a foreign key over
        //    them. They are exactly the rows SET NULL would have produced had the rule been right
        //    from the start.
        DB::statement(
            'UPDATE `' . self::TABLE . '` r LEFT JOIN `categories` c ON c.`id` = r.`' . self::COLUMN . '`'
            . ' SET r.`' . self::COLUMN . '` = NULL'
            . ' WHERE r.`' . self::COLUMN . '` IS NOT NULL AND c.`id` IS NULL'
        );

        $this->dropForeignKeyIfPresent();

        DB::statement(
            'ALTER TABLE `' . self::TABLE . '` ADD CONSTRAINT `' . $this->constraintName() . '`'
            . ' FOREIGN KEY (`' . self::COLUMN . '`) REFERENCES `categories` (`id`) ON DELETE SET NULL'
        );
    }

    /**
     * Reverse to CASCADE, which is what the module's own migration declares.
     *
     * The NULLs this migration created are NOT converted back to 0: 0 was never a valid reference
     * and restoring it would reintroduce rows the cascade cannot represent. A down() that destroys
     * information to look symmetrical is worse than one that says what it does.
     */
    public function down(): void
    {
        if (! $this->applicable()) {
            return;
        }

        $this->dropForeignKeyIfPresent();

        // CASCADE cannot coexist with the NULL rows above on a NOT NULL column, so the column stays
        // nullable. Reverting the rule is the reversal; re-tightening the column is not.
        DB::statement(
            'ALTER TABLE `' . self::TABLE . '` ADD CONSTRAINT `' . $this->constraintName() . '`'
            . ' FOREIGN KEY (`' . self::COLUMN . '`) REFERENCES `categories` (`id`) ON DELETE CASCADE'
        );
    }

    /** MySQL, with both tables present. Anything else is a stated no-op. */
    private function applicable(): bool
    {
        return DB::connection()->getDriverName() === 'mysql'
            && Schema::hasTable(self::TABLE)
            && Schema::hasTable('categories')
            && Schema::hasColumn(self::TABLE, self::COLUMN);
    }

    private function constraintName(): string
    {
        return self::TABLE . '_' . self::COLUMN . '_foreign';
    }

    /**
     * Drop whatever foreign key currently sits on the column, whatever it is called.
     *
     * Read from information_schema rather than assumed: the constraint may carry the Laravel
     * default name, a name an earlier hand-written ALTER chose, or nothing at all on an install
     * whose table was created with checks disabled.
     */
    private function dropForeignKeyIfPresent(): void
    {
        $existing = DB::select(
            'SELECT k.CONSTRAINT_NAME AS name FROM information_schema.KEY_COLUMN_USAGE k'
            . ' WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.COLUMN_NAME = ?'
            . ' AND k.REFERENCED_TABLE_NAME IS NOT NULL',
            [self::TABLE, self::COLUMN]
        );

        foreach ($existing as $row) {
            DB::statement('ALTER TABLE `' . self::TABLE . '` DROP FOREIGN KEY `' . $row->name . '`');
        }
    }
};
