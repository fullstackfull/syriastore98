<?php

namespace App\Services\Theme;

use App\Models\ThemeCustomize;
use App\Models\ThemePublishedVersion;
use App\Models\ThemeVersion;
use App\Support\Theme\ThemeEditorGate;
use App\Support\Theme\ThemeFieldCatalog;
use App\Support\Theme\ThemePayload;
use App\Support\Theme\ThemeSettingValidator;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * PHASE 03 M3-C — THE ONE CONTROLLED PATH THAT WRITES A STORE'S THEME CONFIGURATION.
 * ================================================================================================
 *
 * WHY EVERYTHING FUNNELS THROUGH HERE
 * ------------------------------------------------------------------------------------------------
 * Before M3-C there were two writers and they disagreed about almost everything:
 *
 *   - `ThemeCustomizeController::themeCustomizeUpdate()` -- Eloquent `updateOrCreate` per key, its
 *     `isAbleTo('Edit Theme')` check COMMENTED OUT, no allowlist against `theme_json`, `theme_id`
 *     taken straight from the route and `name` from arbitrary request keys.
 *   - `Api\DashboardController` (the Flutter admin app, `POST api/admin/updateStoreOwnerInfo`) --
 *     a RAW `DB::table()->updateOrInsert()` of `footer_repeater`, which does not invalidate the
 *     `Cachable` model and so **never reaches the storefront**: measured on MariaDB with
 *     `CACHE_STORE=database`, the app reads the new social links back while the storefront footer
 *     keeps rendering the old ones.
 *
 * One service, one authorization boundary, one validator, one cache-invalidation rule.
 *
 * THE FOUR RULES THIS CLASS EXISTS TO ENFORCE
 * ------------------------------------------------------------------------------------------------
 * 1. **KEY-COMPLETENESS IS NOT THIS CLASS'S JOB AND MUST NOT BECOME IT.** `getThemeSetting()`
 *    merges the theme's `default_data/settings.php` UNDER the stored rows, and ~520 keys per theme
 *    are indexed by storefront views with no guard -- an absent key is `ErrorException: Undefined
 *    array key`, which Laravel turns into a 500. That is the M0 defect. The projection therefore
 *    holds the merchant's OVERRIDES and the merge supplies the rest. `writeSettings()` below is an
 *    UPSERT of the named keys and never a wholesale replacement, so it cannot produce a sparse
 *    projection. M3-D's publish has the harder version of this problem and inherits the rule.
 *
 * 2. **THE CACHE IS FLUSHED AFTER COMMIT.** Measured: an Eloquent `delete()` or `save()` flushes as
 *    part of its own lifecycle -- INSIDE the transaction. That is not sufficient. Between that
 *    flush and the commit a concurrent storefront request can re-populate the cache from the
 *    still-committed OLD rows and then serve them after the new ones land. `DB::afterCommit()` is
 *    used so the flush happens when the data is actually visible.
 *
 * 3. **STORED VALUES ARE WRITTEN DIRECTLY, NEVER RE-SUBMITTED THROUGH A WEB ROUTE.**
 *    `App\Http\Middleware\XSS` runs `htmlspecialchars_decode` over every request string, and that is
 *    NOT IDEMPOTENT: entities progressively unescape on each pass, so a publish implemented by
 *    re-POSTing a saved payload would mutate content nobody touched and drift its `payload_hash`.
 *    The same middleware deletes `javascript` and `alert` as substrings (S-09), which is why
 *    validation here is type-aware and does not delegate to it.
 *
 * 4. **NO TENANT PREDICATE IS EVER BUILT FROM REQUEST INPUT.** The store is resolved by
 *    `ThemeEditorGate` from an explicitly named id whose ownership is then proved; `getCurrentStore()`
 *    is not consulted, because it hands a platform operator the literal store 1, which belongs to a
 *    merchant.
 */
class ThemeConfigurationService
{
    private ThemeEditorGate $gate;
    private ThemeSettingValidator $validator;

    /** Field-level refusals from the last call, as `name => why`. */
    private array $rejected = [];

    /** Why a whole call was refused, when it was. */
    private ?string $refusal = null;

    public function __construct(?ThemeEditorGate $gate = null, ?ThemeSettingValidator $validator = null)
    {
        $this->gate = $gate ?? new ThemeEditorGate();
        $this->validator = $validator ?? new ThemeSettingValidator();
    }

    /** @return array<string,string> */
    public function rejected(): array
    {
        return $this->rejected;
    }

    public function refusal(): ?string
    {
        return $this->refusal;
    }

    /* ====================================================================== the projection write */

    /**
     * Upsert validated settings into the PUBLISHED PROJECTION for a store's theme.
     *
     * This is the operation both the merchant form and the Flutter endpoint now use. It writes the
     * live configuration directly -- there is no draft indirection -- because that is what both of
     * those surfaces already did and changing their semantics is M3-D's business, not a side effect
     * of routing them through one service.
     *
     * @param  array<string,mixed>  $settings  name => value, as posted
     * @return array<string,string>|null       the settings actually written, or null on refusal
     */
    public function writeSettings(
        mixed $storeId,
        string $themeId,
        array $settings,
        ?Authenticatable $user = null
    ): ?array {
        $this->rejected = [];
        $this->refusal = null;

        $user ??= Auth::user();
        $store = $this->gate->resolveStore($storeId, $user);

        if (! $store || ! $this->gate->allows($store->id, $user)) {
            $this->refusal = $this->gate->reason() ?? 'not permitted';

            return null;
        }

        $themeId = $this->resolveThemeId($themeId, (string) $store->theme_id);

        if ($themeId === null) {
            return null;
        }

        $accepted = $this->validator->validate($themeId, $settings);
        $this->rejected = $this->validator->errors();

        if ($accepted === []) {
            /*
             * Not an error in itself -- a form can legitimately post only unchanged values -- but a
             * call where EVERY field was rejected is reported so a caller does not treat a silent
             * no-op as a successful save.
             */
            if ($this->rejected !== []) {
                $this->refusal = 'every posted field was rejected';
            }

            return [];
        }

        $storeKey = (int) $store->id;

        DB::transaction(function () use ($accepted, $storeKey, $themeId) {
            foreach ($accepted as $name => $value) {
                /*
                 * Eloquent, not DB::table(): `ThemeCustomize` is `Cachable`, and a raw write leaves
                 * the storefront's own read serving the pre-write row. That is the measured defect
                 * on the Flutter path this service replaces.
                 *
                 * updateOrCreate on the triple the application already treats as a key. There is no
                 * UNIQUE index behind it (deliberately -- see migration 2026_11_13_000400), so a
                 * pre-existing duplicate group resolves to its FIRST row here while the storefront
                 * reader keeps the LAST. `report-theme-customize-duplicates.php` answers whether a
                 * given database has any.
                 */
                ThemeCustomize::updateOrCreate(
                    ['store_id' => $storeKey, 'theme_id' => $themeId, 'name' => $name],
                    ['value' => $value]
                );
            }
        });

        $this->flushProjectionCacheAfterCommit();

        return $accepted;
    }

    /* ============================================================================ draft versions */

    /**
     * Save a draft. This writes ONLY `theme_versions` and cannot change the live storefront, by
     * construction: no storefront path reads that table.
     *
     * @param  array<string,mixed>  $settings
     */
    public function saveDraft(
        mixed $storeId,
        string $themeId,
        array $settings,
        ?string $label = null,
        ?Authenticatable $user = null
    ): ?ThemeVersion {
        $this->rejected = [];
        $this->refusal = null;

        $user ??= Auth::user();
        $store = $this->gate->resolveStore($storeId, $user);

        if (! $store || ! $this->gate->allows($store->id, $user)) {
            $this->refusal = $this->gate->reason() ?? 'not permitted';

            return null;
        }

        $themeId = $this->resolveThemeId($themeId, (string) $store->theme_id);

        if ($themeId === null) {
            return null;
        }

        $accepted = $this->validator->validate($themeId, $settings);
        $this->rejected = $this->validator->errors();

        if ($this->rejected !== [] && $accepted === []) {
            $this->refusal = 'every posted field was rejected';

            return null;
        }

        $storeKey = (int) $store->id;
        $payload = ThemePayload::encode($accepted);
        $hash = hash('sha256', $payload);
        $actor = $user && isset($user->id) ? (int) $user->id : null;

        return DB::transaction(function () use ($storeKey, $themeId, $payload, $hash, $label, $actor) {
            /*
             * The version number is allocated inside the transaction, with a row lock on the
             * existing versions of this (store, theme) so two concurrent saves cannot both read
             * "max + 1". `tv_store_theme_version_unique` refuses the loser regardless -- the lock
             * turns a 500 into a correct second version. M3-E proves the race.
             */
            $next = 1 + (int) ThemeVersion::query()
                ->where('store_id', $storeKey)
                ->where('theme_id', $themeId)
                ->lockForUpdate()
                ->max('version_no');

            return ThemeVersion::create([
                'store_id'     => $storeKey,
                'theme_id'     => $themeId,
                'version_no'   => $next,
                'status'       => ThemeVersion::STATUS_DRAFT,
                'label'        => $label,
                'payload'      => $payload,
                'payload_hash' => $hash,
                'created_by'   => $actor,
            ]);
        });
    }

    /**
     * Delete a draft, with the SAME ownership predicates as every read.
     *
     * A bare `ThemeVersion::destroy($id)` would delete another tenant's draft, and `RESTRICT`
     * protects only the version a publish pointer references -- so a draft has no engine backstop
     * at all. This is the predicate that stands in for one.
     */
    public function deleteVersion(
        mixed $storeId,
        string $themeId,
        int $versionId,
        ?Authenticatable $user = null
    ): bool {
        $this->refusal = null;
        $user ??= Auth::user();

        $store = $this->gate->resolveStore($storeId, $user);

        if (! $store || ! $this->gate->allows($store->id, $user)) {
            $this->refusal = $this->gate->reason() ?? 'not permitted';

            return false;
        }

        $themeId = $this->resolveThemeId($themeId, (string) $store->theme_id);

        if ($themeId === null) {
            return false;
        }

        $version = ThemeVersion::ownedBy((int) $store->id, $themeId, $versionId);

        if (! $version) {
            $this->refusal = 'no such version for this store and theme';

            return false;
        }

        $live = ThemePublishedVersion::liveFor((int) $store->id, $themeId);

        if ($live && (int) $live->version_id === (int) $version->id) {
            /* The database would refuse this too (RESTRICT). Refusing here gives a usable message
             * instead of a 500, and keeps the two layers agreeing. */
            $this->refusal = 'that version is currently published; unpublish before deleting it';

            return false;
        }

        return (bool) $version->delete();
    }

    /* ================================================================================== helpers */

    /**
     * Which theme a write is for.
     *
     * The caller may name one, but it is VALIDATED against the theme catalog rather than trusted:
     * `makeActiveTheme()` writes `stores.theme_id` from `$request->theme_id` with nothing but
     * `isset()`, so neither the route parameter nor the stored column is a trustworthy source. An
     * unknown theme has no declared fields, so every write against it would be rejected anyway --
     * but it is refused here with a clear reason rather than as 300 field errors.
     *
     * Falls back to the store's own theme when the caller names none, which is what the Flutter
     * endpoint does.
     */
    private function resolveThemeId(string $requested, string $storeTheme): ?string
    {
        $candidate = $requested !== '' ? $requested : $storeTheme;

        if ($candidate === '') {
            $this->refusal = 'the store has no theme selected and none was named';

            return null;
        }

        if (! in_array($candidate, ThemeFieldCatalog::themes(), true)) {
            $this->refusal = "'{$candidate}' is not a theme this installation ships";

            return null;
        }

        return $candidate;
    }

    /**
     * RULE 2. Flush when the data is actually visible, not when the write was merely queued.
     *
     * Inside a transaction the Eloquent lifecycle has already flushed once -- `Cachable` fires
     * `flushCache()` on the model's `saved` event, synchronously, with the transaction still open.
     * That flush races a concurrent reader, which can re-cache the pre-commit state in the window
     * before the commit and then serve it indefinitely. `afterCommit` runs the callback once the
     * OUTERMOST transaction commits, and immediately when there is none.
     *
     * PROVED, in both directions, by
     * `tests/Support/Security/bin/verify-theme-cache-flush-after-commit-on-mysql.php`: two real
     * processes, a shared model cache, and a reader on a second connection that genuinely cannot
     * see the uncommitted row. Delete the `DB::afterCommit` below and its check 4 fails.
     *
     * WHEN THIS CONTROL IS LOAD-BEARING, AND WHEN IT IS NOT -- measured, because the answer is not
     * the obvious one and three earlier versions of that harness reported a false green.
     * `flushCache()` on a non-taggable store is `Cache::flush()`. On the DATABASE store that is
     * `DELETE FROM cache`, a statement which JOINS THE OPEN TRANSACTION: it is invisible to other
     * connections until the commit, and when it lands it also deletes whatever a concurrent reader
     * cached in the meantime. So with `CACHE_STORE=database` and `MODEL_CACHE_STORE` unset -- the
     * shipped production combination -- the in-transaction flush already behaves like a post-commit
     * flush and the race cannot occur.
     *
     * It occurs on EVERY OTHER STORE: file, redis, memcached, array. Those flushes are immediate
     * and outside the transaction, so the window is real. `config/laravel-model-caching.php` exists
     * precisely so that store is a recorded decision, and `MODEL_CACHE_STORE` may be set at any
     * time without touching this class. This flush is what keeps that a configuration choice rather
     * than a correctness one.
     */
    private function flushProjectionCacheAfterCommit(): void
    {
        DB::afterCommit(static function (): void {
            (new ThemeCustomize())->flushCache();
        });
    }
}
