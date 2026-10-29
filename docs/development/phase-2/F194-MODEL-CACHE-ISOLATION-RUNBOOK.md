# F194 — model cache isolation: what ships, and the production activation this session must NOT perform

**Owner decision: D-28a, OPTION A APPROVED (2026-10-29).** Real isolation of the model cache from
the cache that holds payment replay claims, pending payment records and idempotency locks.

**This document is a runbook, not a change log.** The owner's §2.5 permits code-level configuration
on the Phase 02 branch and preparing a production activation plan; it forbids editing production
`.env`, changing production Redis or database cache, clearing production cache, and running
production migrations. So the mechanism ships **inert** and this file is the activation.

---

## 1. The defect, measured

`App\Models\Setting`, `App\Models\User` and `App\Models\Order` all `use Cachable`.
laravel-model-caching flushes after every create, save and delete
(`ModelCaching.php:66-76` → `Caching::checkCooldownAndFlushAfterPersisting()` → `flushCache()`), and
`Caching::cache()` tags that flush **only when the store is taggable**:

```php
// vendor/genealabs/laravel-model-caching/src/Traits/Caching.php:95
if (is_subclass_of($cache->getStore(), TaggableStore::class)) { $cache = $cache->tags($tags); }
```

`Illuminate\Cache\ArrayStore` **extends** `TaggableStore`. `Illuminate\Cache\DatabaseStore` does
**not**. `phpunit.xml` sets `CACHE_STORE=array`; production is `CACHE_STORE=database`.

**Measured on MariaDB 10.11.14 with the database store** — one `Setting::updateOrCreate()` erased
**4 of 4** seeded payment keys; the identical write on the array store erased **none**. Evidence:
`tests/Support/Security/bin/verify-model-cache-flush-scope-on-mysql.php`.

## 2. Why the isolation boundary is the TABLE and not the store name

This is the owner's explicit condition — *different store names are not sufficient if they still
share a physical backend or namespace that a flush erases globally* — and it is correct:

```php
// vendor/laravel/framework/src/Illuminate/Cache/DatabaseStore.php:420
public function flush() { $this->table()->delete(); return true; }
```

`flush()` deletes the **whole table** and ignores any key prefix. Two store names over one table are
one store for this purpose. So the shipped `model_cache` store names its own table.

## 3. What ships, inert

| | |
|---|---|
| `config/cache.php` | a `model_cache` store — `database` driver, `model_cache` table, `model_cache_locks` lock table — and a `model_cache_file` alternative, see §6 |
| `config/laravel-model-caching.php` | published, with `'store' => env('MODEL_CACHE_STORE')` **unset by default** |
| `database/migrations/…_create_model_cache_table.php` | creates `model_cache` and `model_cache_locks`, mirroring Laravel's own cache-table shape |
| `.env.example` | documents `MODEL_CACHE_STORE=model_cache`, commented out, with the ordering warning |

**Merging this changes no behaviour.** With `MODEL_CACHE_STORE` unset, `Caching::cache()` takes its
`if ($config)` branch as false and the model cache stays on the default store exactly as today.

## 4. The activation, in order — and the order is load-bearing

> **If the variable is set before the table exists, every model read fails.** `DatabaseStore` has no
> fallback: the first query against a missing table throws, and because `Cachable` wraps ordinary
> Eloquent reads that is every page.

1. **Run the migration** on the target database so `model_cache` and `model_cache_locks` exist.
   Verify with `SHOW TABLES LIKE 'model_cache%'` before continuing.
2. **Set `MODEL_CACHE_STORE=model_cache`** in that environment.
3. **Clear the config cache** so the new value is read (`php artisan config:clear`, or the
   deployment's equivalent). Note this is a *compiled-config* clear, not `cache:clear`.
4. **Verify** — the checks in §5.

**Rollback** is step 2 in reverse: unset `MODEL_CACHE_STORE`, clear the config cache. The model cache
returns to the default store and the `model_cache` table becomes inert. No data is lost either way:
both tables hold only cache entries.

## 5. How to verify the activation worked

Run `tests/Support/Security/bin/verify-model-cache-isolation-on-mysql.php` against a throwaway
MariaDB. It creates and drops its own database and asserts:

- the two store classes really do differ in taggability (the mechanism);
- the application store and the model store are the **same driver** and **different tables**;
- a **full flush of the model store** leaves all five seeded payment keys;
- four kinds of `Setting` write, plus an explicit `flushCache()`, leave them;
- the model cache still serves a **new** value after a write and a flush — D-28a's no-stale-reads
  condition;
- and, deliberately, that an explicit flush of the **application** store still erases them.

That last one is not a failure. It is the boundary of what F194 buys, and §7 below is about it.

## 6. The single-server alternative, offered rather than chosen

`model_cache_file` (the `file` driver, `storage/framework/cache/model`) needs **no migration**:
`FileStore::flush()` clears its own directory and nothing else, so the isolation is physical.

**It is not the default because a file store is per server.** Behind more than one app server, a
model flush on one node leaves the others serving stale rows — which is precisely the stale-read
failure D-28a forbids. Choose it knowingly if and only if the deployment is single-node.

## 7. What F194 does NOT fix — and must not be read as fixing

The isolation moves the **model** flush. It does nothing about an explicit flush of the application
store, and three of those are reachable:

| path | reach | status |
|---|---|---|
| `AuthenticatedSessionController:83` → `Utility::addNewData():2725` → `cache:clear` | **every successful login** | **F197** |
| `GET /config-cache` (`routes/web.php:776`) → `cache:clear` | any authenticated merchant, no permission check | **F197** |
| `ModuleController` → `sideMenuCacheForget('all')` → `Cache::flush()` | operator, behind the module permission | **F188** |

The owner's §2.3 is explicit that F194 may not be declared complete while a reachable flush erases
the same controls. **It is not.** F197 and F188 are tracked separately, and F197's own consequence is
worse than F194's: it makes the gateway-return replay control's real lifetime "until the next login
by anybody" rather than the 48 hours two docblocks on this branch assert.

## 8. What is explicitly not done here

No production `.env` edit, no production migration, no production cache clear, no production
infrastructure change, and no change to Redis or the production database cache. The mechanism and
this runbook are the deliverable; the activation is the owner's.
