<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2-04-9 / WS E3 — `saved_views`: a merchant's named filter sets.
 *
 * OWNER-APPROVED (§1, 2026-10-28; re-authorised 2026-10-28 §2): *"named filter sets per employee
 * and per store, with isolation and permissions guaranteed. Sharing a filter combination stays a
 * safe LINK and must not become unrestricted sharing of tenant data."* Conditions in D-24d.
 * **PREPARED AND TESTED IN DEV/TEST ONLY — not run against production by this work.**
 *
 * WHY A TABLE AND NOT ONE OF THE FOUR CHEAPER OPTIONS. `TableFilterMemory`'s docblock already
 * ruled each of them out by measurement rather than preference, and the reasons apply verbatim
 * here because this is the same per-employee-per-store value with a name attached:
 *   - `settings` CANNOT hold a per-employee value: `SettingOwnership` rewrites `created_by` to
 *     `$user->creatorId()`, so two employees of one merchant collapse onto one row — the exact
 *     opposite of the acceptance.
 *   - `localStorage` cannot be per-employee without putting a user id in the DOM, which re-opens
 *     P2-06-4's disclosure argument.
 *   - a COOKIE name is a browser-global slot, so the second employee at a shared terminal
 *     overwrites the first.
 *   - the SESSION is what `TableFilterMemory` already uses, and its short life is the point
 *     there. A saved view must outlive the working day, which is the whole feature.
 *
 * THE OWNERSHIP SHAPE IS THE ISOLATION. `(store_id, created_by)` together, never either alone:
 * `created_by` is the EMPLOYEE (not `creatorId()`, which would collapse a tenant's staff onto the
 * owner), and `store_id` is the tenant. A merchant who switches store does not see the other
 * store's views, and two employees of one merchant do not see each other's. Reads always carry
 * both predicates; `SavedViewPolicy` is the single place that decides it.
 *
 * `screen` SCOPES A VIEW TO THE LIST IT BELONGS TO, so an order view can never be restored onto
 * the product list, where its `ss_payment` would mean nothing and `ss_status` would mean something
 * ELSE (`1 = Active` on products, `1 = Delivered` on orders). The stored `filters` are validated
 * against `TableFilterMemory::vocabularyFor($screen)` on write AND on read — a key outside the
 * vocabulary is dropped, so a row that somehow holds one cannot inject it into a query later.
 *
 * NO FOREIGN KEYS, consistent with this schema's convention (`orders` and `order_notes` both
 * carry `store_id` with no constraint) and with the `users` decision recorded in
 * `2026_10_12_000102`: that table is the highest-fan-in object in the schema and a cascade from it
 * deletes merchant data to tidy a user row. An ORPHANED view here is harmless and unreachable by
 * construction, because every read filters `created_by = auth()->id()`: a departed employee's
 * private views are visible to nobody.
 *
 * UNIQUE `(store_id, created_by, screen, name)` so one employee cannot hold two views of the same
 * name on the same screen — the rename-or-replace question is answered by the database rather
 * than by a race between two tabs.
 *
 * FLUTTER AND LYNOMIA IMPACT: none. A new table reaches no existing response, and no API endpoint
 * is added by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('saved_views')) {
            return;
        }

        Schema::create('saved_views', function (Blueprint $table) {
            $table->id();

            // The tenant and the employee, together. Neither alone is the owner.
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('created_by');

            // 'order' | 'product' -- the keys of TableFilterMemory's vocabulary.
            $table->string('screen', 32);
            $table->string('name', 60);

            // The filter set, as the screen's own `ss_*` keys. Validated against the screen's
            // vocabulary on write and again on read.
            $table->text('filters');

            $table->timestamps();

            $table->unique(['store_id', 'created_by', 'screen', 'name'], 'saved_views_owner_name_unique');
            $table->index(['store_id', 'created_by', 'screen'], 'saved_views_owner_screen_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_views');
    }
};
