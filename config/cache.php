<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Cache Store
    |--------------------------------------------------------------------------
    |
    | This option controls the default cache store that will be used by the
    | framework. This connection is utilized if another isn't explicitly
    | specified when running a cache operation inside the application.
    |
    */

    'default' => env('CACHE_STORE', 'file'),

    /*
    |--------------------------------------------------------------------------
    | Cache Stores
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the cache "stores" for your application as
    | well as their drivers. You may even define multiple stores for the
    | same cache driver to group types of items stored in your caches.
    |
    | Supported drivers: "array", "database", "file", "memcached",
    |                    "redis", "dynamodb", "octane", "null"
    |
    */

    'stores' => [

        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_CACHE_CONNECTION'),
            'table' => env('DB_CACHE_TABLE', 'cache'),
            'lock_connection' => env('DB_CACHE_LOCK_CONNECTION'),
            'lock_table' => env('DB_CACHE_LOCK_TABLE'),
        ],

        /*
        |----------------------------------------------------------------------
        | model_cache -- F194. THE MODEL CACHE'S OWN STORE, WITH ITS OWN TABLE.
        |----------------------------------------------------------------------
        |
        | WHY THIS EXISTS. `App\Models\Setting`, `App\Models\User` and
        | `App\Models\Order` all `use Cachable`, and laravel-model-caching
        | flushes after every create, save and delete. `Caching::cache()` tags
        | that flush ONLY when the store is taggable:
        |
        |     if (is_subclass_of($cache->getStore(), TaggableStore::class)) {
        |         $cache = $cache->tags($tags);
        |     }
        |
        | `Illuminate\Cache\ArrayStore` EXTENDS `TaggableStore`, so under
        | `phpunit.xml`'s `CACHE_STORE=array` the flush is scoped and the whole
        | suite is blind to this. `Illuminate\Cache\DatabaseStore` does NOT,
        | and production is `CACHE_STORE=database` -- so there the flush is
        | `Cache::flush()` over the entire store. MEASURED on MariaDB 10.11.14:
        | one `Setting::updateOrCreate()` erased 4 of 4 seeded payment keys,
        | and the identical write on the array store erased none.
        |
        | A DIFFERENT STORE NAME WOULD NOT BE ENOUGH, which is the owner's
        | explicit condition on this decision (D-28a). `DatabaseStore::flush()`
        | is `$this->table()->delete()` -- it deletes the whole TABLE and
        | ignores any key prefix. So the isolation boundary has to be the table,
        | which is why this store names its own.
        |
        | HOW IT IS ACTIVATED. `config/laravel-model-caching.php` reads
        | `MODEL_CACHE_STORE`, which is UNSET by default, so merging this
        | changes nothing: the model cache stays on the default store until the
        | `model_cache` table exists and the variable is set. See
        | `docs/development/phase-2/F194-MODEL-CACHE-ISOLATION-RUNBOOK.md`.
        |
        */
        'model_cache' => [
            'driver' => 'database',
            'connection' => env('MODEL_CACHE_DB_CONNECTION', env('DB_CACHE_CONNECTION')),
            'table' => env('MODEL_CACHE_DB_TABLE', 'model_cache'),
            'lock_connection' => env('MODEL_CACHE_DB_LOCK_CONNECTION', env('DB_CACHE_LOCK_CONNECTION')),
            'lock_table' => env('MODEL_CACHE_DB_LOCK_TABLE', 'model_cache_locks'),
        ],

        /*
        |----------------------------------------------------------------------
        | model_cache_file -- the alternative, for a SINGLE-SERVER deployment.
        |----------------------------------------------------------------------
        |
        | Needs no migration: `FileStore::flush()` clears its own directory and
        | nothing else, so the isolation is physical. The reason it is not the
        | default is that a file store is PER SERVER -- behind more than one app
        | server, a model flush on one would leave the others serving stale
        | rows, which is the one thing D-28a says must not happen. Offered here
        | so the owner can choose it knowingly if the deployment is single-node.
        |
        */
        'model_cache_file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/model'),
            'lock_path' => storage_path('framework/cache/model'),
        ],

        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
            'lock_path' => storage_path('framework/cache/data'),
        ],

        'memcached' => [
            'driver' => 'memcached',
            'persistent_id' => env('MEMCACHED_PERSISTENT_ID'),
            'sasl' => [
                env('MEMCACHED_USERNAME'),
                env('MEMCACHED_PASSWORD'),
            ],
            'options' => [
                // Memcached::OPT_CONNECT_TIMEOUT => 2000,
            ],
            'servers' => [
                [
                    'host' => env('MEMCACHED_HOST', '127.0.0.1'),
                    'port' => env('MEMCACHED_PORT', 11211),
                    'weight' => 100,
                ],
            ],
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_CACHE_CONNECTION', 'cache'),
            'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),
        ],

        'dynamodb' => [
            'driver' => 'dynamodb',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'table' => env('DYNAMODB_CACHE_TABLE', 'cache'),
            'endpoint' => env('DYNAMODB_ENDPOINT'),
        ],

        'octane' => [
            'driver' => 'octane',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | When utilizing the APC, database, memcached, Redis, and DynamoDB cache
    | stores, there might be other applications using the same cache. For
    | that reason, you may prefix every cache key to avoid collisions.
    |
    */

    'prefix' => env('CACHE_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_cache_'),

];
