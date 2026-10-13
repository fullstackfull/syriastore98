<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create `audit_logs` — row 7 of SCHEMA-01's "tables live code reads and writes that no
 * migration creates".
 *
 * WHY THIS ONE IS NOT HELD BEHIND GATE F while the 6 money tables are.
 * GATE F exists because a provisional money-table definition with a GUESSED column type is a
 * silent trap: `Schema::hasTable()` makes the later correct migration a no-op, so the guess
 * becomes permanent and invisible. That reasoning is about types that are NOT derivable.
 * This table is different on both counts:
 *
 *   1. It is not money-bearing. SCHEMA-01 classifies it "no (compliance)".
 *   2. Its column list and types ARE fully derivable from the model, with no guessing:
 *      App\Models\AuditLog declares $fillable at :11-21 (user_id, store_id, action,
 *      subject_type, subject_id, old_values, new_values, ip_address, user_agent) and
 *      $casts at :23-26 ('old_values' => 'array', 'new_values' => 'array'), which fixes
 *      those two as JSON. Nothing is inferred beyond that.
 *
 * It is also a live FUNCTIONAL break, not a latent one: App\Models\AuditLog::record() is
 * called from 8 sites in app/Http/Controllers/UserController.php (:131, :246, :283, :333,
 * :410, :426, :511, :569) and now from StoreController as well. Every one of those calls
 * throws "no such table: audit_logs" on a from-source database today. Verified MISSING after
 * a clean `migrate` from zero on sqlite before this migration was added.
 *
 * NULLABILITY is taken from the call site, not assumed. ::record() at :28-48 passes
 * `auth()->id()` for user_id and `getCurrentStore()` for store_id, and BOTH are null in the
 * contexts R4-02 names (queued job, console command, storefront, customer/deliveryboy guard).
 * `subject` is an optional parameter defaulting to null, so subject_type/subject_id are
 * nullable too. `action` is a non-nullable string parameter, so that column is NOT NULL.
 * old_values/new_values are written as `$oldValues ?: null`, i.e. explicitly nullable.
 *
 * NO FOREIGN KEYS, deliberately. An audit record must outlive the user or store it refers to;
 * a cascade or a restrict would either destroy the compliance trail or block the deletion that
 * the trail is meant to record. user_id and store_id are plain indexed integers.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('audit_logs')) {
            return;
        }

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Nullable: see the NULLABILITY note above. Indexed because every read of this
            // table is "what did this user do" or "what happened in this store".
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('store_id')->nullable()->index();

            $table->string('action')->index();

            // Matches the model's subject_type / subject_id pair and adds the composite index
            // that a "history of this record" lookup needs.
            $table->nullableMorphs('subject');

            // $casts declares these 'array'. json() is the portable choice: Laravel 11 maps it
            // to native JSON on MySQL/MariaDB and to TEXT on SQLite, so the same migration runs
            // on the production driver and under test.
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            // 45 chars holds a full IPv6 address including an IPv4-mapped form.
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
