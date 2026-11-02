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

---

## 9. The owner's activation decision (2026-10-30) — what is now proven, and what the topology does and does not gate

The owner's instruction: *"commit to the Model Cache isolation option approved earlier. Verify the
nature and number of production servers, without entering production or changing its configuration.
Re-test the `Setting::updateOrCreate` scenario that erases the Replay Claim after activating the
isolation in an environment resembling production. Prepare an activation and rollback plan, and do
not execute it on production."*

### 9.1 The measurement that did not exist until now

§5 above verified the isolation **as a cache boundary**: a flush of the model store leaves the
payment keys. That is not the event that causes F211. The event is a **`Cachable` model save**, whose
flush laravel-model-caching routes through `Caching::cache()` — and whether that save still reaches
the claim after activation was never asked.

`tests/Support/Security/bin/verify-replay-claim-with-model-cache-isolation-on-mysql.php` runs the
same chain twice in one process against one MariaDB 10.11.14 database. **16 checks, 0 failed.**

| phase | configuration | result |
|---|---|---|
| **A** | `laravel-model-caching.store` **null** (today's shipped default) | the defect **reproduces**: claim taken → replay refused → one `Setting::updateOrCreate()` → **claim erased** → **same replay permitted** |
| **B** | `laravel-model-caching.store` = **`model_cache`** | **the claim SURVIVES** the identical save, and the replay is **still refused**. The other two payment key shapes survive too. |
| **C** | isolation on | the model cache serves the **new** value after a write — **no stale read**, which is D-28a's condition |
| **D** | isolation on | an explicit flush of the **application** store **still erases** the claim |

**Phase A is the premise and it is not decoration.** A before/after script whose "before" does not
reproduce proves nothing about its "after", and that is the most likely way this measurement could
have been vacuous. The two phases are the two real deployments rather than an approximation, because
`Caching::cache()` (`vendor/genealabs/laravel-model-caching/src/Traits/Caching.php:84-101`) resolves
the store on **every call** rather than once at boot.

**Phase D is the boundary, stated so activation is not over-read.** Isolating the model cache does
**not** make `cache:clear` safe, so it does not subsume **F215** — the anonymous `GET /` that ran
`package:seed`. F215 needed its own fix and has one. Anything that calls `cache:clear` still erases
every claim, and §7 above remains the honest list of what F194 does not buy.

### 9.2 The production topology — what the repository can and cannot establish

Asked without touching production, as instructed. **The repository cannot settle it**, and the
honest position is to say which way each signal points rather than to pick the convenient reading.

| signal | what it says |
|---|---|
| `deployment/deploy.sh` | runs **in place on one host**: `php artisan migrate --force`, `config:cache`, then `systemctl restart "$FPM_SERVICE"`. **No ssh or rsync fan-out, no per-node loop, no load-balancer step.** Single-server-shaped — but it could be run on each node of a cluster. |
| `SESSION_DRIVER=database`, `QUEUE_CONNECTION=database` | shared stores; compatible with either topology, so they decide nothing. |
| `REDIS_HOST=127.0.0.1` | localhost, which suggests a single host **if** Redis is used; it is not the default. |
| `FILESYSTEM_DISK=local` | the default puts uploads on local disk, which multi-node would break — **but** `Utility::upload_file()` selects `local` / `wasabi` / `s3` from the **runtime `storage_setting` row** (`app/Models/Utility.php:404-432`), so this is conditional on a production value I cannot read. |
| no `Dockerfile`, `docker-compose`, k8s, helm or ansible | no multi-node orchestration is described anywhere in the tree. |

**Conclusion: there is no positive evidence of a multi-server deployment, and several
single-server-shaped signals, but single-server is not proven.** Two facts would settle it and
neither requires production access — a person can answer both: *how many PHP-FPM hosts serve
`storesyria.com`, and what is `storage_setting` in production?*

### 9.3 AND THE DECIDING POINT: the chosen option makes the topology non-blocking

**This is the part worth reading before anything else.** The owner's chosen option is the one D-28a
approved — `MODEL_CACHE_STORE=model_cache`, a **`database` store on its own table**. That store is
**shared across every app server**, because it is a table in the same database they all use. So:

- **Activation does not depend on the server count.** One node or ten, every node reads and writes
  the same `model_cache` table, and a flush on one is a flush for all — which is exactly what the
  no-stale-read condition needs.
- **The topology question gates only §6's `model_cache_file` alternative**, which is per-server and
  which the owner has **not** chosen. §6 stays as a documented road not taken.
- **It does, separately, gate `CONSENT_STORE`.** F205's consent token defaults to the `file` store
  (`config/consent.php`), and that **is** per-server: on more than one node, a merchant can be
  issued a token on node A and refused on node B. **That residual is unchanged by this activation
  and is the one place the server count still matters.** It is not a security hole — the failure mode
  is a refused retry, not an accepted forgery — but it is a correctness question the same two facts
  above would answer.

### 9.4 Activation and rollback plan — prepared, NOT executed

**Nothing below has been run against production, and this session must not run it.**

**Go/no-go before step 1**

1. `php artisan migrate:status` shows the `model_cache` migration **pending** (not applied, not
   missing).
2. `SHOW TABLES LIKE 'model_cache%'` returns **nothing** yet.
3. `MODEL_CACHE_STORE` is **unset** in the environment, and `php artisan config:show cache.stores`
   lists a `model_cache` store (it ships in `config/cache.php`).
4. A database dump exists from **today**, by `deployment/deploy.sh`'s own dump step or equivalent.
   The two cache tables hold only cache entries, so the dump is for the migration, not for the
   switch.
5. A maintenance window is **not** required and should not be taken: steps 1 and 2 are additive and
   step 3 is a config-cache clear. Stating that so nobody schedules downtime they do not need.

**The activation, in the order §4 fixes** — the order is load-bearing and the reason is in §4: set
the variable before the table exists and **every model read fails**, because `DatabaseStore` has no
fallback and `Cachable` wraps ordinary Eloquent reads.

| step | command | verify before continuing |
|---|---|---|
| 1 | `php artisan migrate --force` (or just the `model_cache` migration) | `SHOW TABLES LIKE 'model_cache%'` returns **both** `model_cache` and `model_cache_locks` |
| 2 | set `MODEL_CACHE_STORE=model_cache` in `.env` | `grep MODEL_CACHE_STORE .env` |
| 3 | `php artisan config:clear` **then** `php artisan config:cache` | `php artisan config:show laravel-model-caching` shows `store => model_cache` |
| 4 | — | the four checks in 9.5 below |

**Rollback — one step, and it needs no migration**

1. Unset (or comment out) `MODEL_CACHE_STORE` in `.env`.
2. `php artisan config:clear && php artisan config:cache`.

The model cache returns to the default store and `model_cache` becomes an inert table. **No data is
lost in either direction** — both tables hold only cache entries — and the `model_cache` table may be
left in place, because an unset variable never reads it. Dropping it is optional cleanup, not part of
the rollback.

**Rollback triggers, named in advance so the decision is not improvised under pressure:**

- any page returning a 500 whose trace names `model_cache` or `Illuminate\Cache\DatabaseStore`
  (symptom of step 2 before step 1);
- a merchant reporting a saved change that does not appear (symptom of a stale read — Phase C says
  this should not happen, and if it does the premise of the whole option is wrong and rollback is
  immediate, not investigated first);
- `model_cache` growing without bound (it is a cache table and should be pruned like `cache`; if
  nothing prunes it, that is a reason to delay activation, not to roll back after).

### 9.5 Post-activation verification, on the real environment

1. `php artisan config:show laravel-model-caching` → `store => model_cache`.
2. `SELECT COUNT(*) FROM model_cache` → **non-zero** after a few page views. Zero means the variable
   is not being read, i.e. step 3 did not take effect, and the isolation is inert while looking
   active — the most dangerous of the failure modes, because nothing breaks.
3. Save any setting in the admin, then re-read the screen: the new value must appear (Phase C, on the
   real data).
4. `SELECT COUNT(*) FROM cache WHERE `key` LIKE '%gateway-return-claim%'` before and after a
   setting save: the count must **not** drop. That is F211's chain, asked of production without
   constructing a payment.

**What must NOT be concluded from a green activation:** that captured-payment-without-order is
solved. F186, F216's unreleased claim and §7's list are untouched by this. The activation closes
**one** route by which a replay claim disappears — the one that fires on every model save — and that
is worth doing on its own terms.
