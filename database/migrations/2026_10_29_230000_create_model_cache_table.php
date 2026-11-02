<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F194 / D-28a — the model cache's own table, so a model save cannot erase a payment key.
 *
 * WHY A SECOND TABLE RATHER THAN A SECOND STORE NAME. `DatabaseStore::flush()` is
 * `$this->table()->delete()` — it deletes the whole table and ignores any key prefix. So two store
 * names over one table are one store for the purpose of a flush, which is exactly the owner's
 * condition on this decision: *different store names are not sufficient if they still share a
 * physical backend or namespace that a flush erases globally.* The boundary has to be the table.
 *
 * WHAT CREATES THE NEED. `Setting`, `User` and `Order` all `use Cachable`, and laravel-model-caching
 * flushes after every create, save and delete. `Caching::cache()` tags that flush only when the
 * store is taggable — `ArrayStore` is, `DatabaseStore` is not — so on production's `database` store
 * the flush is `Cache::flush()` over everything, including `gateway-return-claim:*`,
 * `paymera_pending_*`, `paymera_claim_*` and `paymera_lock_*`. Measured on MariaDB 10.11.14: one
 * `Setting::updateOrCreate()` erased 4 of 4 seeded payment keys.
 *
 * SHAPE COPIED FROM LARAVEL'S OWN `0001_01_01_000001_create_cache_table`, deliberately and without
 * improvement: the `database` cache driver reads exactly `key`, `value` and `expiration`, and the
 * lock store exactly `key`, `owner` and `expiration`. A "better" schema here would simply not work.
 *
 * THIS MIGRATION ALONE CHANGES NOTHING. `MODEL_CACHE_STORE` is unset by default, so the model cache
 * stays on the default store until an operator sets it — see
 * `docs/development/phase-2/F194-MODEL-CACHE-ISOLATION-RUNBOOK.md` for the activation order, which
 * matters: setting the variable before this migration has run makes every model read fail.
 *
 * `down()` drops both tables. They hold only cache entries, so nothing is lost either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('model_cache')) {
            Schema::create('model_cache', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration');
            });
        }

        if (! Schema::hasTable('model_cache_locks')) {
            Schema::create('model_cache_locks', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->string('owner');
                $table->integer('expiration');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('model_cache');
        Schema::dropIfExists('model_cache_locks');
    }
};
