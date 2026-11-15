<?php

namespace App\Services\Theme;

use App\Models\ThemeCustomize;
use App\Models\ThemePublicationEvent;
use App\Models\ThemePublishedVersion;
use App\Models\ThemeVersion;
use App\Support\Theme\ThemeEditorGate;
use App\Support\Theme\ThemeFieldCatalog;
use App\Support\Theme\ThemeSettingValidator;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * PHASE 03 M3-D — ATOMIC PUBLISH, ROLLBACK AND PREVIEW.
 * ================================================================================================
 *
 * THE FOUR TABLES, AND WHAT EACH IS FOR. M3-B built them and M3-C filled the first; nothing here
 * changes their shape.
 *
 *   `theme_customizes`          the PUBLISHED PROJECTION -- the only one the storefront reads
 *   `theme_versions`            immutable snapshots, drafts included. No storefront path reads it,
 *                               which is what makes a draft structurally unable to leak.
 *   `theme_published_versions`  the live pointer, UNIQUE(store_id, theme_id)
 *   `theme_publication_events`  append-only history
 *
 * ---------------------------------------------------------------------------------------------
 * RULE 1. PUBLISHING UPSERTS THE SNAPSHOT'S KEYS AND DELETES NOTHING.
 * ---------------------------------------------------------------------------------------------
 * The owner's requirement: *"Do not overwrite unrelated configuration keys when publishing an
 * editor snapshot. In particular, retain independently managed settings such as Flutter-written
 * social links and `footer_repeater`."*
 *
 * So a publish is an UPSERT of exactly the names the version carries. A key the snapshot does not
 * mention keeps whatever value it has. That is not a shortcut -- it is the only behaviour that lets
 * two surfaces own different keys of one row set, which this product already does: the Flutter app
 * writes `footer_repeater` from `POST api/admin/updateStoreOwnerInfo` and the merchant's editor
 * never touches it.
 *
 * THE CONSEQUENCE IS REPORTED RATHER THAN HIDDEN. Rolling back to a version that predates a key
 * does not remove that key -- there is nothing in the older snapshot to restore it to. So every
 * result carries `keysNotInSnapshot`: the published names the applied version does not mention. A
 * caller that wants a true "revert everything" has to decide what to do about them, with the list
 * in hand, rather than discovering the gap later.
 *
 * ---------------------------------------------------------------------------------------------
 * RULE 2. ALL OR NOTHING, AND NEVER A PARTIAL PROJECTION.
 * ---------------------------------------------------------------------------------------------
 * *"Never expose a partially published configuration."* Three things enforce that:
 *
 *   - every write is inside ONE transaction, so a failure leaves the projection, the pointer and
 *     the history exactly as they were;
 *   - re-validation happens BEFORE any write, and a single rejected key REFUSES THE WHOLE PUBLISH
 *     rather than publishing the rest. A snapshot is a unit;
 *   - the model cache is flushed AFTER COMMIT, so a concurrent reader cannot cache half-applied
 *     state and keep serving it. M3-C measured that this matters on every cache store except the
 *     database one, where the flush is itself transactional.
 *
 * ---------------------------------------------------------------------------------------------
 * RULE 3. CONCURRENT PUBLISHERS ARE SERIALISED, AND A STALE CALLER IS TOLD.
 * ---------------------------------------------------------------------------------------------
 * `lockForUpdate()` on the live pointer for `(store_id, theme_id)` serialises two publishers. It
 * works even when no pointer row exists yet: under REPEATABLE READ InnoDB takes a gap lock on the
 * unique index range, so the second transaction waits rather than racing the insert. The
 * `tpv_store_theme_unique` index is the backstop if it ever does not, and a duplicate-key violation
 * is translated into a refusal instead of a 500.
 *
 * Serialisation alone still lets a publisher overwrite a change it never saw, so there is OPTIMISTIC
 * control too: pass `$expectedLiveVersionId` and the publish is refused unless that is what is
 * actually live. `0` means "expect nothing published yet"; `null` means "do not check", which is
 * the right default for the first publish of a store and the wrong one for a UI that has been
 * showing the merchant a version id.
 *
 * ---------------------------------------------------------------------------------------------
 * RULE 4. HISTORY IS APPENDED TO, NEVER REWRITTEN.
 * ---------------------------------------------------------------------------------------------
 * *"Rollback should create a new publication event referencing the restored version rather than
 * silently rewriting history."* A rollback is a publish with `action = 'rollback'`, whose
 * `version_id` is the restored version and whose `from_version_id` is the one that was live. The
 * superseded version is marked `archived` and KEPT -- nothing in this class deletes a version.
 */
class ThemePublishingService
{
    private ThemeEditorGate $gate;
    private ThemeSettingValidator $validator;

    /** Why the last call was refused, when it was. */
    private ?string $refusal = null;

    /** Field-level refusals from the last call, as `name => why`. */
    private array $rejected = [];

    public function __construct(?ThemeEditorGate $gate = null, ?ThemeSettingValidator $validator = null)
    {
        $this->gate = $gate ?? new ThemeEditorGate();
        $this->validator = $validator ?? new ThemeSettingValidator();
    }

    public function refusal(): ?string
    {
        return $this->refusal;
    }

    /** @return array<string,string> */
    public function rejected(): array
    {
        return $this->rejected;
    }

    /* ======================================================================== publish / rollback */

    /**
     * Publish a version: make it the live configuration for a store's theme, atomically.
     *
     * @param  int|null  $expectedLiveVersionId  null = no check, 0 = expect nothing live, N = expect N
     * @return array{event: ThemePublicationEvent, written: array<string,string>, keysNotInSnapshot: array<int,string>}|null
     */
    public function publish(
        mixed $storeId,
        string $themeId,
        int $versionId,
        ?Authenticatable $user = null,
        ?int $expectedLiveVersionId = null
    ): ?array {
        return $this->apply($storeId, $themeId, $versionId, $user,
            ThemePublicationEvent::ACTION_PUBLISH, $expectedLiveVersionId);
    }

    /**
     * Restore an earlier version. Mechanically a publish; recorded as a distinct action so the
     * history says which it was.
     *
     * @return array{event: ThemePublicationEvent, written: array<string,string>, keysNotInSnapshot: array<int,string>}|null
     */
    public function rollback(
        mixed $storeId,
        string $themeId,
        int $toVersionId,
        ?Authenticatable $user = null,
        ?int $expectedLiveVersionId = null
    ): ?array {
        return $this->apply($storeId, $themeId, $toVersionId, $user,
            ThemePublicationEvent::ACTION_ROLLBACK, $expectedLiveVersionId);
    }

    /* ==================================================================================== preview */

    /**
     * The settings a version WOULD produce, key-complete, without touching anything.
     *
     * KEY-COMPLETE IS NOT OPTIONAL, and this is the M0 defect restated: ~520 keys per theme are
     * indexed directly by storefront views with no guard, and an absent key is an
     * `ErrorException: Undefined array key` that Laravel turns into a 500. So a preview merges the
     * theme's own defaults UNDER the version's overrides, exactly as `getThemeSetting()` does for
     * the live projection. A preview renderer can hand this straight to a view.
     *
     * AND IT CANNOT LEAK. This reads `theme_versions` and writes nothing, and no storefront path
     * reads that table, so a draft has no route to the public storefront whatever this returns.
     * The authorization check is still here: a draft is unpublished merchant content, and letting
     * another tenant READ it would be a disclosure even though it changes nothing.
     *
     * @return array<string,mixed>|null
     */
    public function preview(
        mixed $storeId,
        string $themeId,
        int $versionId,
        ?Authenticatable $user = null
    ): ?array {
        $this->refusal = null;
        $this->rejected = [];

        $resolved = $this->resolve($storeId, $themeId, $user);

        if ($resolved === null) {
            return null;
        }

        [$store, $theme] = $resolved;

        $version = ThemeVersion::ownedBy((int) $store->id, $theme, $versionId);

        if ($version === null) {
            $this->refusal = 'no such version for this store and theme';

            return null;
        }

        if (! $version->hashIsIntact()) {
            $this->refusal = 'the stored payload does not match its hash; refusing to preview it';

            return null;
        }

        return $this->mergeOverDefaults($theme, $version->settings());
    }

    /* ==================================================================================== reads */

    /** The version currently live for a store's theme, or null. Scoped, never by bare id. */
    public function liveVersion(mixed $storeId, string $themeId, ?Authenticatable $user = null): ?ThemeVersion
    {
        $this->refusal = null;

        $resolved = $this->resolve($storeId, $themeId, $user);

        if ($resolved === null) {
            return null;
        }

        [$store, $theme] = $resolved;

        return ThemePublishedVersion::liveFor((int) $store->id, $theme)?->liveVersion();
    }

    /**
     * The publication history, newest first.
     *
     * @return array<int,ThemePublicationEvent>
     */
    public function history(mixed $storeId, string $themeId, ?Authenticatable $user = null): array
    {
        $this->refusal = null;

        $resolved = $this->resolve($storeId, $themeId, $user);

        if ($resolved === null) {
            return [];
        }

        [$store, $theme] = $resolved;

        return ThemePublicationEvent::historyFor((int) $store->id, $theme)->get()->all();
    }

    /* ================================================================================= the engine */

    /**
     * @return array{event: ThemePublicationEvent, written: array<string,string>, keysNotInSnapshot: array<int,string>}|null
     */
    private function apply(
        mixed $storeId,
        string $themeId,
        int $versionId,
        ?Authenticatable $user,
        string $action,
        ?int $expectedLiveVersionId
    ): ?array {
        $this->refusal = null;
        $this->rejected = [];

        $user ??= Auth::user();
        $resolved = $this->resolve($storeId, $themeId, $user);

        if ($resolved === null) {
            return null;
        }

        [$store, $theme] = $resolved;
        $storeKey = (int) $store->id;
        $actor = $user && isset($user->id) ? (int) $user->id : null;

        if (! in_array($action, ThemePublicationEvent::ACTIONS, true)) {
            /* Not reachable from the two public methods; refused rather than written, because a
             * `varchar(16)` would TRUNCATE an unknown action silently in production's non-strict
             * mode -- the same trap `ThemeVersion::booted()` guards for `status`. */
            $this->refusal = "'{$action}' is not a publication action";

            return null;
        }

        try {
            $result = DB::transaction(function () use (
                $storeKey, $theme, $versionId, $action, $actor, $expectedLiveVersionId
            ): ?array {
                /*
                 * THE LOCK THAT SERIALISES PUBLISHERS, and it is taken FIRST so that everything
                 * read afterwards is read under it. `lockForUpdate()` on a row that does not exist
                 * still gap-locks the unique index range under REPEATABLE READ, so a concurrent
                 * first publish waits here instead of racing the insert.
                 */
                $pointer = ThemePublishedVersion::query()
                    ->where('store_id', $storeKey)
                    ->where('theme_id', $theme)
                    ->lockForUpdate()
                    ->first();

                $liveNow = $pointer !== null ? (int) $pointer->version_id : 0;

                /* OPTIMISTIC CONTROL. The caller says what it believed was live; if the world moved
                 * underneath it, refuse rather than overwrite a change it never saw. */
                if ($expectedLiveVersionId !== null && $liveNow !== $expectedLiveVersionId) {
                    $this->refusal = sprintf(
                        'the live version changed underneath this request: expected %s, found %s',
                        $expectedLiveVersionId === 0 ? 'nothing published' : "version {$expectedLiveVersionId}",
                        $liveNow === 0 ? 'nothing published' : "version {$liveNow}"
                    );

                    return null;
                }

                $version = ThemeVersion::ownedBy($storeKey, $theme, $versionId);

                if ($version === null) {
                    $this->refusal = 'no such version for this store and theme';

                    return null;
                }

                /*
                 * IMMUTABILITY EVIDENCE, CHECKED BEFORE THE PROJECTION IS TOUCHED. A payload whose
                 * hash no longer matches has been altered outside this service -- by an import, a
                 * restore, or a raw UPDATE -- and publishing it would put unverified content on a
                 * live storefront under a version number that claims to vouch for it.
                 */
                if (! $version->hashIsIntact()) {
                    $this->refusal = 'the stored payload does not match its hash; refusing to publish it';

                    return null;
                }

                $settings = $version->settings();

                /*
                 * RE-VALIDATED AT PUBLISH, AND ONE FAILURE REFUSES THE WHOLE SNAPSHOT.
                 *
                 * The draft was validated when it was saved, but by the validator of that day: a
                 * theme can be removed, a field can stop being declared, and the rules themselves
                 * change (M3-C tightened the URL-scheme rule twice). Publishing the valid half of a
                 * snapshot would be the "partially published configuration" the directive forbids,
                 * so the whole thing is refused with the offending names reported.
                 */
                $accepted = $this->validator->validate($theme, $settings);
                $this->rejected = $this->validator->errors();

                if ($this->rejected !== []) {
                    $this->refusal = 'the snapshot contains values this theme no longer accepts: '
                        . implode(', ', array_keys($this->rejected));

                    return null;
                }

                if ($accepted === []) {
                    $this->refusal = 'the snapshot is empty; there is nothing to publish';

                    return null;
                }

                /* What is live now but absent from this snapshot -- reported, not deleted. */
                $keysNotInSnapshot = array_values(array_diff(
                    ThemeCustomize::query()
                        ->where('store_id', $storeKey)
                        ->where('theme_id', $theme)
                        ->pluck('name')
                        ->all(),
                    array_keys($accepted)
                ));

                /*
                 * RULE 1: UPSERT, NEVER REPLACE. Eloquent rather than `DB::table()` because
                 * `ThemeCustomize` is `Cachable` and a raw write leaves the storefront's own read
                 * serving the pre-write row -- the measured Flutter defect M3-C fixed.
                 */
                foreach ($accepted as $name => $value) {
                    ThemeCustomize::updateOrCreate(
                        ['store_id' => $storeKey, 'theme_id' => $theme, 'name' => $name],
                        ['value' => $value]
                    );
                }

                /* The previously live version is ARCHIVED, never deleted: history is preserved. */
                if ($liveNow !== 0 && $liveNow !== (int) $version->id) {
                    ThemeVersion::query()
                        ->where('store_id', $storeKey)
                        ->where('theme_id', $theme)
                        ->where('id', $liveNow)
                        ->update(['status' => ThemeVersion::STATUS_ARCHIVED]);
                }

                $version->status = ThemeVersion::STATUS_PUBLISHED;
                $version->published_by = $actor;
                $version->published_at = now();
                $version->save();

                ThemePublishedVersion::updateOrCreate(
                    ['store_id' => $storeKey, 'theme_id' => $theme],
                    ['version_id' => (int) $version->id, 'published_by' => $actor, 'published_at' => now()]
                );

                /* RULE 4: appended, never rewritten. */
                $event = ThemePublicationEvent::create([
                    'store_id'        => $storeKey,
                    'theme_id'        => $theme,
                    'version_id'      => (int) $version->id,
                    'from_version_id' => $liveNow !== 0 ? $liveNow : null,
                    'action'          => $action,
                    'payload_hash'    => (string) $version->payload_hash,
                    'actor_id'        => $actor,
                ]);

                return [
                    'event' => $event,
                    'written' => $accepted,
                    'keysNotInSnapshot' => $keysNotInSnapshot,
                ];
            });
        } catch (QueryException $e) {
            /*
             * The unique index on `(store_id, theme_id)` is the backstop behind the gap lock. If two
             * first-publishes ever do get past it, the loser arrives here and is told so, rather
             * than answering 500 to a merchant who pressed Publish at the same moment as a
             * colleague.
             */
            if ($this->isUniqueViolation($e)) {
                $this->refusal = 'another publish for this store and theme committed first; '
                    . 'read the live version again and retry';

                return null;
            }

            throw $e;
        }

        if ($result === null) {
            return null;
        }

        /*
         * RULE 2: AFTER COMMIT. The `Cachable` lifecycle has already flushed once, inside the
         * transaction, and that flush races a concurrent reader which can re-cache the pre-commit
         * state and then serve it. Measured in both directions by
         * `verify-theme-cache-flush-after-commit-on-mysql.php`.
         */
        DB::afterCommit(static function (): void {
            (new ThemeCustomize())->flushCache();
        });

        return $result;
    }

    /* ================================================================================== helpers */

    /**
     * Gate, then theme. Returns `[$store, $themeId]` or null with a reason set.
     *
     * @return array{0: \App\Models\Store, 1: string}|null
     */
    private function resolve(mixed $storeId, string $themeId, ?Authenticatable $user = null): ?array
    {
        $user ??= Auth::user();
        $store = $this->gate->resolveStore($storeId, $user);

        if (! $store || ! $this->gate->allows($store->id, $user)) {
            $this->refusal = $this->gate->reason() ?? 'not permitted';

            return null;
        }

        $candidate = $themeId !== '' ? $themeId : (string) $store->theme_id;

        if ($candidate === '') {
            $this->refusal = 'the store has no theme selected and none was named';

            return null;
        }

        /*
         * Validated against the shipped catalog rather than trusted: `makeActiveTheme()` writes
         * `stores.theme_id` from request input, so neither the argument nor the stored column is a
         * trustworthy source. (M3-D gated that handler too, but a column written before then can
         * still hold anything.)
         */
        if (! in_array($candidate, ThemeFieldCatalog::themes(), true)) {
            $this->refusal = "'{$candidate}' is not a theme this installation ships";

            return null;
        }

        return [$store, $candidate];
    }

    /** The theme's own defaults with the overrides laid over them, matching getThemeSetting(). */
    private function mergeOverDefaults(string $theme, array $overrides): array
    {
        $path = base_path('themes/' . $theme . '/default_data/settings.php');
        $defaults = is_file($path) ? (array) include $path : [];

        $merged = [];

        foreach ($defaults as $key => $value) {
            $merged[$key] = array_key_exists($key, $overrides) ? $overrides[$key] : $value;
        }

        foreach ($overrides as $key => $value) {
            if (! array_key_exists($key, $merged)) {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        /* 23000 covers both engines' integrity-constraint class; the driver code distinguishes a
         * duplicate key (1062 on MySQL, 19 on SQLite) from other integrity failures. */
        return ($e->errorInfo[0] ?? null) === '23000'
            && in_array((int) ($e->errorInfo[1] ?? 0), [1062, 19], true);
    }
}
