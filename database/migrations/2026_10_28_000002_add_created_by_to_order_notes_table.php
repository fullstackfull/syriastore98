<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2-04-6 / WS E — `order_notes.created_by`: WHO WROTE THIS NOTE.
 *
 * OWNER-APPROVED, 2026-10-28 (§1): *"an **optional** author on NEW notes; appropriate
 * permissions, honest display, and **old notes must not be attributed** to the current employee
 * or to a presumed author"*. Conditions recorded in D-24d. Additive and non-destructive.
 * **PREPARED AND TESTED IN AN ISOLATED ENVIRONMENT ONLY** — not run against production by this
 * work.
 *
 * THE DEFECT THIS CLOSES IS ALREADY WRITTEN DOWN IN THE VIEW IT AFFECTS.
 * `resources/views/order/view.blade.php` rendered `{{ __(' by ') }}{{ Auth::user()->name }}`
 * beside every note — the name of whoever was LOOKING at the page — so one note read "by Amina"
 * to Amina and "by Khalid" to Khalid. That was removed in the P2-04-6 correctness pass, and the
 * comment left in its place states why nothing honest could replace it: *"`order_notes` has NO
 * author column (one migration, no later one, nothing in `_gate_f_pending/`, nothing in any
 * module), and `customer_id` is a FK to the SHOPPER while these notes are written by staff.
 * Adding `created_by` is a migration and therefore an owner decision."* That decision is now
 * taken. `OrderDetailTest::test_a_note_is_not_attributed_to_the_person_reading_the_page` is the
 * standing guard and must keep passing after this: naming the AUTHOR is not naming the READER.
 *
 * WHY NOT `customer_id`, WHICH ALREADY EXISTS. It is a foreign key to `customers` with
 * `ON DELETE CASCADE` (`2024_01_26_100237_create_order_notes_table.php:26`) — the shopper, not
 * the staff member. Reusing it for a `users.id` would be a cross-table id collision in a column
 * the database enforces, so the insert would simply fail.
 *
 * COLUMN SHAPE: `unsignedBigInteger` nullable, indexed, **no default, no foreign key** — exactly
 * the shape `orders.created_by` took in `2026_10_12_000102`, and for the same two reasons.
 *   - NULLABLE AND NO DEFAULT is the owner's condition in DDL. Three kinds of row must stay
 *     unattributed and each is a real case: (a) every note written before this column existed;
 *     (b) SYSTEM notes — `OrderNote::order_note_data()` composes "Stock levels reduced…" and
 *     "Successfully created order of…" from event data at 30+ call sites, most of them inside
 *     payment-gateway callbacks where there is no authenticated user at all; (c) the two
 *     "Order created" notes the manual-order writers emit, whose text is generated rather than
 *     typed. A default, or a `?? auth()->id()` anywhere, would attribute all three to somebody.
 *   - NO FOREIGN KEY TO `users`: that table is the highest-fan-in object in the schema (14 FKs
 *     reference it) and adding a 15th with cascade semantics is a behaviour change, not a schema
 *     catch-up. It also matters here specifically — a CASCADE would DELETE a merchant's order
 *     notes when an employee's account is removed, destroying commercial records to tidy up a
 *     user row. The display handles an unresolvable id instead.
 *
 * INDEXED because the author is resolved per note when the detail page renders its list.
 *
 * NO BACKFILL. There is no evidence to backfill FROM: nothing in the table, the orders table or
 * the activity log ties an existing note to a user. Inventing one is precisely what the owner's
 * condition forbids.
 *
 * SAFETY. `hasTable`/`hasColumn` guarded, so re-running is a no-op. Purely additive and
 * nullable: no existing row changes and no existing query result changes.
 *
 * FLUTTER AND LYNOMIA IMPACT: none from this migration. `OrderNote` is serialised by the admin
 * app's note endpoints; an additive nullable column cannot change any existing key's presence or
 * type, and no response is widened here on purpose.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('order_notes')) {
            return;
        }

        if (! Schema::hasColumn('order_notes', 'created_by')) {
            Schema::table('order_notes', function (Blueprint $table) {
                // No ->default() and no ->foreign(): see the docblock above.
                $table->unsignedBigInteger('created_by')->nullable()->index()->after('store_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('order_notes') && Schema::hasColumn('order_notes', 'created_by')) {
            Schema::table('order_notes', function (Blueprint $table) {
                $table->dropColumn('created_by');
            });
        }
    }
};
