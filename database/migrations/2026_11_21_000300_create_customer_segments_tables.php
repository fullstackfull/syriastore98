<?php

use App\Support\Database\ThemeSchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 05 MKT-004 — STATIC AND DYNAMIC CUSTOMER SEGMENTS, FIRST-PARTY.
 * =================================================================================================
 *
 * APPROVED FOR DEVELOPMENT AND ISOLATED DATABASE TESTING. **Not applied to production, and applying
 * it needs the owner's separate authorisation** — the same posture as `analytics_report_schedules`,
 * the three D8 consent tables and the GAP-219 nudge ledger, because CLAUDE.md reserves a persistent
 * migration to the owner.
 *
 * WHAT WAS MEASURED, AND WHY THIS IS FIRST-PARTY. MKT-004's traceability row: neither half is a
 * usable segment feature. The DYNAMIC half exists only in LynomiaChat Audiences, which ships behind
 * an engineering kill switch — `LYNOMIA_INTEGRATION_ENABLED` defaults false and both env files set
 * it false — so **no store can reach it as shipped**, and its `audience_fields` live in a remote
 * system this repository does not own. The STATIC half had no table: the measuring pass said it
 * existed nowhere and a review lens refuted that only in spirit, finding that vendored
 * `ProductPricing` persists a merchant-picked customer list per pricing rule — a static membership
 * set in all but name, but welded to a pricing rule and unreachable as a segment.
 *
 * So a segment is built here, over data this application already holds, rather than by waiting on a
 * kill switch somebody else controls or by editing `packages/workdo/`.
 *
 * TWO TABLES AND ONE `kind` COLUMN, not two features. A static segment's membership is the rows in
 * `customer_segment_members`; a dynamic segment's membership is whatever its `definition` matches
 * when it is read. Both are a named set of this store's customers, both are listed on the same
 * screen, and both are addressable by whatever later sends to a segment — which is exactly why they
 * are one table. A second table for "audiences" is how a product ends up with two half-features
 * that disagree about what a segment is.
 *
 * `definition` HOLDS A PREDICATE IN AN ALLOWLISTED VOCABULARY, NEVER SQL AND NEVER A CALLABLE. It
 * is re-sanitised on READ against the vocabulary the code currently knows (`SegmentDefinition`),
 * because the row outlives the code that wrote it: a rule that was valid last year must not reach a
 * query builder this year just because it is in the table. The same discipline `SavedView`
 * already applies to a saved filter, and for the same reason. **Nothing in this column is ever
 * interpolated into SQL**, and `APP_KEY` being in git history (so `Crypt` is an encoding, not a
 * control) is the reason a `decrypt()` or `unserialize()` over stored bytes is not an option here.
 *
 * `store_id` IS ALWAYS EXPLICIT, ON BOTH TABLES. Neither is in `config/tenancy.php`'s
 * `scoped_models`, and `null_tenant => 'open'` means a global scope adds **no predicate at all**
 * when no tenant resolves — so a console command or a delegated principal that leaned on the scope
 * would read every store's segments. `customer_segment_members` carries `store_id` as well as
 * `segment_id`, denormalised on purpose: "which segments is this customer in" must be answerable
 * with a tenant predicate and no join, because a join whose predicate sits on the far table is
 * precisely the shape this codebase keeps getting wrong.
 *
 * WHAT IS DELIBERATELY ABSENT: a SPEND rule. "Customers who spent more than X" is currency-
 * sensitive, and the metric layer refuses a mixed-currency total rather than summing one — correct
 * for a figure, but a segment cannot "refuse": it would quietly EXCLUDE every customer whose orders
 * are in another currency, and the merchant would never see it. That needs its own decision about
 * which currency a spend rule is denominated in, so it is named here as absent rather than shipped
 * half-right. The rules that ARE offered are counts and dates, which are currency-free.
 */
return new class extends Migration
{
    public function up(): void
    {
        ThemeSchemaGuard::assertCompatible(
            'customer_segments',
            ['store_id', 'created_by', 'name', 'kind', 'definition', 'created_at', 'updated_at'],
            ['cseg_store_name_unique'],
            []
        );

        if (! Schema::hasTable('customer_segments')) {
            Schema::create('customer_segments', function (Blueprint $table) {
                $table->bigIncrements('id');

                $table->unsignedBigInteger('store_id');

                /*
                 * PROVENANCE ONLY, and that is the difference from `SavedView` and
                 * `analytics_report_schedules`, where `created_by` is part of the identity. A saved
                 * period is one employee's working preference; a SEGMENT is a shared marketing
                 * object — "VIP customers" means the same thing to everyone in the store — so the
                 * unique key below is per STORE, not per employee. Getting this backwards gives
                 * every colleague their own private "VIP customers" and they diverge.
                 */
                $table->unsignedBigInteger('created_by');

                $table->string('name', 60);

                /* `static` | `dynamic`. See the class docblock for why it is one column. */
                $table->string('kind', 16);

                /*
                 * The dynamic predicate, as JSON in an allowlisted vocabulary. NULL for a static
                 * segment, whose membership is rows rather than a rule. `text` and not `json`: the
                 * column is read through `SegmentDefinition`, which re-sanitises it, so the database
                 * is not the thing that validates it — and a `json` column would make MySQL reject
                 * a row this application should refuse with a message a merchant can read.
                 */
                $table->text('definition')->nullable();

                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();

                /* One name per store, so re-saving a name UPDATES rather than duplicating. */
                $table->unique(['store_id', 'name'], 'cseg_store_name_unique');
            });
        }

        ThemeSchemaGuard::assertCompatible(
            'customer_segment_members',
            ['segment_id', 'store_id', 'customer_id', 'added_by', 'created_at'],
            ['csegm_unique', 'csegm_store_customer'],
            []
        );

        if (Schema::hasTable('customer_segment_members')) {
            return;
        }

        Schema::create('customer_segment_members', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('segment_id');

            /* Denormalised deliberately — see the class docblock's tenancy paragraph. */
            $table->unsignedBigInteger('store_id');

            $table->unsignedBigInteger('customer_id');

            /* Who added them, so a merchant can see how a list came to be what it is. */
            $table->unsignedBigInteger('added_by')->nullable();

            /*
             * `created_at` ONLY. A membership is added or removed; there is nothing about it to
             * edit, so an `updated_at` would always equal `created_at` and would invite a future
             * reader to believe a membership can change in place.
             */
            $table->timestamp('created_at')->nullable();

            /*
             * THE SAME CUSTOMER CANNOT BE IN THE SAME SEGMENT TWICE. Without this a double-submit
             * puts them in twice, every count over the segment is wrong, and anything that later
             * sends to a segment sends to them twice — which, for a paid message, is a cost the
             * merchant did not ask for.
             */
            $table->unique(['segment_id', 'customer_id'], 'csegm_unique');

            /* "Which of this store's segments is this customer in", with the tenant predicate. */
            $table->index(['store_id', 'customer_id'], 'csegm_store_customer');
        });
    }

    /**
     * A DOCUMENTED NO-OP, the same posture as every GATE F migration and the two tables above.
     *
     * Dropping these would destroy merchant-curated lists that cannot be recomputed — a static
     * segment IS the rows, so there is nothing to rebuild it from. Two GATE F drafts dropped a
     * column *because* it existed and a rollback destroyed 45 `cost_price` values; removing these
     * tables is a deliberate operator action, not a rollback.
     */
    public function down(): void
    {
        // Intentionally empty. See the method docblock.
    }
};
