<?php

/*
 * PUBLISHED FOR F194, so the model cache's store is a decision recorded in this repository rather
 * than an unset environment variable nobody can see.
 *
 * The package's own default (vendor/genealabs/laravel-model-caching/config/laravel-model-caching.php)
 * is identical except for the commentary. Publishing it changes nothing on its own.
 *
 * WHY `store` IS THE WHOLE POINT. `Caching::cache()` reads this key and, when it is set, calls
 * `$cache->store($config)`. Left null, the model cache uses the DEFAULT store -- which in production
 * is `database`, and which also holds `gateway-return-claim:*`, `paymera_pending_*`,
 * `paymera_claim_*` and `paymera_lock_*`. Because `DatabaseStore` is not taggable, the flush
 * laravel-model-caching performs after every model save is `Cache::flush()` over that whole store.
 * Measured on MariaDB 10.11.14: one `Setting::updateOrCreate()` erased 4 of 4 seeded payment keys.
 *
 * SET IT TO `model_cache` TO ISOLATE, *after* the `model_cache` table exists. It is deliberately
 * left unset here so that merging this file changes no behaviour and so that no environment can
 * break by pointing the model cache at a table that has not been migrated yet. The activation
 * order, and the single-server `model_cache_file` alternative, are in
 * `docs/development/phase-2/F194-MODEL-CACHE-ISOLATION-RUNBOOK.md`.
 *
 * `enabled` IS NOT THE LEVER HERE. Turning model caching off would also stop the flushes, but the
 * owner's decision D-28a names that as explicitly NOT the approved option, and it would cost
 * whatever the caching buys -- which is unmeasured.
 */

return [
    'cache-prefix' => '',

    'enabled' => env('MODEL_CACHE_ENABLED', true),

    'use-database-keying' => env('MODEL_CACHE_USE_DATABASE_KEYING', true),

    'store' => env('MODEL_CACHE_STORE'),
];
