<?php

/**
 * F58 / P1-MIG-03 / #40 — `products.cost_price`, promoted from GATE F on owner approval.
 * =============================================================================================
 *
 * PROMOTED 2026-10-26 from `database/migrations/_gate_f_pending/0004_draft_add_cost_price_to_products_table.php.draft`
 * after the owner approved F58 in writing, scoped to "بيئات التطوير والاختبار المعزولة" —
 * isolated development and test environments — with existing data preserved and no production
 * migration run. That scope is honoured by construction, not by a flag: see `up()`.
 *
 * WHAT F58 WAS. `ProductController::store():131` validates `cost_price` as **required** and
 * `:303` writes `$Product->cost_price` unconditionally, but the column was declared by no
 * migration. So on any database built from this repository — a fresh install, CI, and the dev
 * fixture — CREATING A PRODUCT WAS IMPOSSIBLE. Driven through the real UI with every required
 * field and both images, it answered:
 *
 *     {"flag":"error","msg":"SQLSTATE[HY000]: ... table products has no column named cost_price"}
 *
 * handed to the merchant as a raw SQL toast by store()'s own try/catch. Production was unaffected,
 * because production has the column. The suite was structurally blind to it: no test creates a
 * product through `store()` — every one uses `Product::forceCreate([...])` without `cost_price`.
 *
 * TYPE, MEASURED NOT GUESSED. The column ALREADY EXISTS in production as
 * `decimal(15,2) NOT NULL DEFAULT 0.00`, read from a restored copy on 2026-10-24 and recorded at
 * `docs/GATE-F-VERIFIED-SCHEMA.md:382`, holding 45 rows of merchant cost data — 9 of them
 * non-zero, summing to 1170.65. That is what is declared below. The repository's former standing
 * `decimal(18,4)` decision does not apply to a column production already owns; the dump wins.
 *
 * THE DRAFT CONTRADICTED ITSELF AND THAT IS CORRECTED HERE. Its header argued for
 * "NULLABLE, DEFAULT NULL — NOT `default(0)`", on the good ground that `NOT NULL DEFAULT 0.00`
 * gives the schema no way to say "cost unknown": a merchant who never entered a cost is stored
 * identically to one whose cost is genuinely zero, and in the same dump 36 of 45 products read
 * 0.00 while 89 of 92 `store_order_financials` rows carry `product_cost = 0` and a `gross_profit`
 * equal to full revenue. That argument was then overtaken by the 2026-10-24 reconciliation, which
 * found the column already in production as NOT NULL DEFAULT 0.00 — so the draft's CODE used
 * `->default(0)` while its prose still advocated nullable. Both readings cannot be right. The
 * reconciled type is what ships, because this migration's job is to bring a fresh environment
 * INTO LINE with production, not to redesign a live column. Making it nullable to separate
 * "unknown" from "zero" is a change to a live money column and remains the owner's call; it is
 * recorded in GATE-F-VERIFIED-SCHEMA.md and belongs with `store_order_financials`, not here.
 *
 * WHY THIS IS SAFE TO PROMOTE WHILE THE REST OF GATE F STAYS PARKED. `_gate_f_pending/README.md`
 * draws the line itself: a guarded migration "is safe when the definition is right and a silent
 * trap when it is a guess". The other drafts are guesses — their types are not derivable from
 * `$fillable`. This one was read off the production schema, so the guard is a no-op rather than a
 * trap, and promoting it changes nothing in production while unblocking every other environment.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * IDEMPOTENT, AND THAT IS WHAT KEEPS IT INSIDE THE OWNER'S SCOPE. Where the column already
     * exists — production — this does nothing at all: no ALTER is issued, no data is touched, no
     * type is changed. So the migration cannot alter production even if it were run there, and on
     * a fresh environment it adds the column with production's own type and default.
     */
    public function up(): void
    {
        if (! Schema::hasTable('products') || Schema::hasColumn('products', 'cost_price')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            // decimal(15,2) NOT NULL DEFAULT 0.00 — production's own type, read from the dump.
            $table->decimal('cost_price', 15, 2)->default(0)->after('price');
        });
    }

    /**
     * DELIBERATELY A NO-OP. This is not an oversight and it must not be "fixed".
     *
     * MEASURED 2026-10-24: an earlier version of this method dropped the column whenever it was
     * PRESENT. Run against a restored copy of production, `migrate:rollback` executed it in
     * 30.28ms and DESTROYED all 45 merchant cost values. An `up()` that creates nothing paired
     * with a `down()` that drops regardless is the asymmetry, and it is the whole reason this body
     * is empty.
     *
     * THE ROLLBACK PLAN, therefore, is "there is nothing to roll back": `up()` either added a
     * column to an environment that had none, or did nothing. A fresh environment is left with a
     * spare zero-default column after a rollback, which is a cosmetic wart. Dropping it by hand is
     * safe ONLY where you have established the column holds no data you want:
     *
     *     SELECT COUNT(*) FROM products WHERE cost_price <> 0;   -- must be 0 before you drop
     *
     * The alternative — a conditional drop — is what lost the 45 values, and no guard expressible
     * here can tell a fresh environment's spare column from production's populated one.
     */
    public function down(): void
    {
    }
};
