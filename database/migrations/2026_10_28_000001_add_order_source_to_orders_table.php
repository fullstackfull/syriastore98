<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2-04-11 / WS E8 -- `orders.order_source`: WHICH PATH CREATED THIS ORDER.
 *
 * OWNER-APPROVED, 2026-10-28, with four standing conditions quoted in the Decision Register
 * (D-25): additive and non-destructive; old rows stay UNATTRIBUTED unless trustworthy evidence
 * exists to classify them; payment and stock logic unchanged; and PREPARED AND TESTED IN AN
 * ISOLATED ENVIRONMENT ONLY -- this migration must not be run against production by this work.
 *
 * WHY A NEW COLUMN, AND WHY NOTHING ALREADY IN THE TABLE ANSWERS IT.
 *   The acceptance for P2-04-11 is that a manually entered order is distinguishable from a
 *   storefront order in the list and on the detail page. Three existing columns look like they
 *   might already say so, and none of them does:
 *     - `payment_type` is a PAYMENT METHOD, not a provenance. A storefront COD order and a
 *       merchant-typed cash order both read 'cod'. It is also the WRONG KIND of value: POS
 *       writes it through the translator (`PosController.php:294`, `__('POS')`), so the stored
 *       discriminator depends on the UI language at write time. It survives today only because
 *       `resources/lang/ar.json` happens to map "POS" to the identical string "POS"; three
 *       payment-shaping branches key on `payment_type == 'POS'` (app/Models/Order.php:301, :374,
 *       :446) and all three feed the API array the Flutter apps read, so a one-word edit to a
 *       LANGUAGE FILE would silently change an API contract. Recorded as F146 and fixed at the
 *       write site in the same change; the column does not depend on that fix.
 *     - `created_by` is non-NULL only on the merchant web form (`OrderController.php:369`) and
 *       was itself added on 2026-10-12, so it is NULL for every historical row AND for the POS
 *       and admin-API paths. It cannot separate "storefront" from "before the column existed".
 *     - `is_guest` says whether a CUSTOMER was signed in, which is orthogonal: a merchant-typed
 *       order for a known customer and a storefront order by that same customer both read 0.
 *
 * COLUMN SHAPE, and why this one.
 *   `string(20)`, NULLABLE, **NO DEFAULT**, matching the `string` nullable idiom the table
 *   already uses for its other vocabulary columns (`payment_type`, `payment_status`,
 *   `product_order_status`). Not an enum: Phase 04 has "channels" in its approved scope, so the
 *   vocabulary is expected to widen, and widening an application-level allowlist is additive
 *   where widening a MySQL enum is a schema change. 20 characters against a longest current
 *   value of 10 ('storefront') is margin, not a guess at a measured production width.
 *
 *   NO DEFAULT IS THE LOAD-BEARING PART, and it is the owner's condition expressed in DDL. A
 *   default of 'storefront' would apply to new inserts too, so ANY writer this change failed to
 *   reach would silently CLAIM a provenance nobody established -- the precise fabrication the
 *   approval forbids. With no default, an unstamped writer leaves NULL, which reads honestly as
 *   "not recorded". `OrderSourceWriterCensusTest` then makes that gap impossible to leave open
 *   by accident: it fails if any host-`Order` writer in `app/` stops stamping the column.
 *
 * NO BACKFILL, DELIBERATELY. Every pre-migration row keeps NULL. The provenance of a historical
 * order is not recoverable from the schema (see the three columns above), so any UPDATE here
 * would be invention. The UI renders NULL as "Not recorded" rather than as a source, and a test
 * asserts that a legacy row is NOT presented as a storefront order.
 *
 * INDEX: a COMPOSITE `(store_id, order_source)`, not a single-column index on `order_source`.
 *   The only query that filters it is the merchant order list, which is always store-scoped, so
 *   the real predicate is `WHERE store_id = ? AND order_source = ?`. A standalone index on a
 *   three-value column would be near-useless to the planner; the composite is selective on its
 *   leading column and serves the list's existing `store_id`-only filter too.
 *   PRODUCTION NOTE, for whoever is authorised to run this: on a large `orders` table this is an
 *   index build, so it wants an online DDL path (`ALGORITHM=INPLACE, LOCK=NONE`, or
 *   pt-online-schema-change). The column add itself is instant on MySQL 8. Neither statement
 *   rewrites or reads existing row VALUES.
 *
 * SAFETY. `hasTable`/`hasColumn`/`hasIndex` guarded and applied one object at a time, so
 * re-running against a database that already has some or all of it is a NO-OP. Purely additive
 * and nullable: no existing row changes and no existing query result changes.
 *
 * FLUTTER AND LYNOMIA IMPACT: none. An additive nullable column cannot change any JSON key's
 * presence or type for a client that reads named keys, and no API response in either repository
 * is widened by this migration. The admin app's order list and detail screens are unchanged by
 * it; `order_source` reaches them only if a later slice adds it to a response on purpose.
 */
return new class extends Migration
{
    private const INDEX = 'orders_store_id_order_source_index';

    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        if (! Schema::hasColumn('orders', 'order_source')) {
            Schema::table('orders', function (Blueprint $table) {
                // No ->default(): see "NO DEFAULT IS THE LOAD-BEARING PART" above.
                $table->string('order_source', 20)->nullable()->after('store_id');
            });
        }

        if (! $this->hasIndex()) {
            Schema::table('orders', function (Blueprint $table) {
                $table->index(['store_id', 'order_source'], self::INDEX);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        if ($this->hasIndex()) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropIndex(self::INDEX);
            });
        }

        if (Schema::hasColumn('orders', 'order_source')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('order_source');
            });
        }
    }

    /**
     * `Schema::hasIndex()` exists only from Laravel 11.x's newer point releases on some drivers,
     * so the index is probed through the doctrine-free introspection the framework exposes on
     * every supported driver here. Falls back to "absent" rather than throwing, which keeps the
     * guard conservative: a false "absent" is caught by the create failing loudly, where a false
     * "present" would silently skip the index.
     */
    private function hasIndex(): bool
    {
        try {
            foreach (Schema::getIndexes('orders') as $index) {
                if (($index['name'] ?? null) === self::INDEX) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }
};
