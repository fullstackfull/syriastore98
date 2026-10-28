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
 *   **THE DEPENDENCY RUNS ONE WAY AND IT IS NOT GUARDED IN CODE.** Sixteen writers in `app/`
 *   stamp this column UNCONDITIONALLY, so this migration must be applied before, or atomically
 *   with, the code that accompanies it: deploying that code alone makes every order INSERT fail
 *   with "Unknown column 'order_source'" -- on storefront checkout, at the till and in the admin
 *   app alike. `Schema::hasColumn` guards at those sixteen sites were considered and REJECTED:
 *   they would reinstate exactly the silent unstamped writer that the no-default DDL and
 *   `OrderSourceWriterCensusTest` exist to make impossible, make provenance depend on the
 *   environment, and still leave the list filter and the detail read broken. This is the same
 *   shape as `2026_10_12_000102`'s currency pair, whose standing instruction is likewise "DO NOT
 *   TOUCH THE HOOK" -- sequence the deploy, do not weaken the writers.
 *
 * SAFETY. `hasTable`/`hasColumn`/`hasIndex` guarded and applied one object at a time, so
 * re-running against a database that already has some or all of it is a NO-OP. Purely additive
 * and nullable: no existing row changes and no existing query result changes.
 *
 * FLUTTER AND LYNOMIA IMPACT: NO BREAK, BUT THREE RESPONSES ARE WIDENED BY ONE KEY.
 *
 * AN EARLIER VERSION OF THIS PARAGRAPH SAID "none", AND THAT WAS WRONG -- caught by independent
 * review. `$appends` is not the only route into a payload: three handlers serialise WHOLE `Order`
 * models rather than an explicit key list, so each gains `order_source` per order the moment the
 * column exists:
 *   - `GET  api/admin/orderlist`                  -- `DashboardController.php` `$orders->items()`
 *   - `POST api/admin/searchdata` (type=order)    -- `DashboardController.php` `->get()->toArray()`
 *   - `POST api/{slug}/loyality-program-json`     -- `ApiController.php`, inside
 *                                                    `data.reward_history.data[]`
 * (`DashboardController::OrderList_backup` does the same and has no route, so it stays latent.)
 *
 * IT IS A WIDENING, NOT A BREAK: an added key cannot change the presence or type of any key a
 * named-key reader already looks for, no `assertExactJson` exists anywhere in `tests/`, and the
 * value is NULL on every pre-migration row -- so a consumer must tolerate null, which a client
 * reading named keys already does for absent ones.
 *
 * THE HANDLERS ARE DELIBERATELY NOT NARROWED. Restricting those three to explicit key lists, or
 * adding `order_source` to `$hidden`, would REMOVE keys from live list responses the Flutter app
 * reads by name -- which is a real break, where this is not. The defect was the claim, not the
 * behaviour.
 *
 * What the original paragraph got right and still holds: `order_source_label` is kept OUT of
 * `$appends`, so no accessor is serialised anywhere, and `OrderController::order_view` reads the
 * source itself rather than through `Order::order_detail()`, which also builds the order-detail
 * API response. Parity gap G8 records what the admin app can and cannot yet DISPLAY.
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
