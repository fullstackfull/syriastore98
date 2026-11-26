<?php

namespace App\Support\Platform;

use App\Models\Setting;
use App\Models\User;

/**
 * GAP-104 (P03-C-05) — WHERE THE OFFICIAL SITE'S OPERATOR-SUPPLIED CONTENT IS KEPT.
 * =================================================================================================
 *
 * WHY THE DATABASE AND NOT REPOSITORY FILES, which is the first question to answer because the
 * Documentation Centre next door made the opposite choice and said so at length.
 *
 * `DocumentationStatus` keeps the help corpus as Markdown in `resources/docs/` precisely because a
 * WEB WRITE lands on a container filesystem that the next deploy replaces, so an approval made
 * through a button would vanish with no trace. That reasoning is sound, and it is the reasoning
 * AGAINST files here: the whole requirement of this row is that the operator can change the
 * official site's content WITHOUT a deploy. Writing to `resources/views/platform/*.blade.php` from
 * a browser would satisfy the screen and lose the content on the next release — the worst of both
 * designs. The documentation centre can keep files because its state transitions are reviewed
 * commits; marketing copy, contact details and approved legal text are not, and must not be
 * (a published privacy notice that needs a developer is the knock-on GAP-104 names).
 *
 * WHY NO MIGRATION. `settings` already exists in production — base install,
 * `2023_12_11_100148_create_settings_table.php`, `name` / `value` (longText, nullable) /
 * `created_by` / `store_id` (nullable) — and it is already the platform operator's own key/value
 * store: `getSuperAdminAllSetting()` (`app/Helper/helper.php:743`) reads exactly the rows this
 * class writes, keyed on the operator. So the capability needs no schema change at all, which
 * matters because a persistent migration is an owner decision and would otherwise leave this
 * screen inert until somebody applied it. The cost is disclosed below.
 *
 * -------------------------------------------------------------------------------------------------
 * THE ROW SHAPE, AND THE THREE PREDICATES THAT MAKE IT THE PLATFORM'S.
 *
 *   name       `platform_site_content_en` / `platform_site_content_ar` — ONE row per locale,
 *              holding a JSON map of registry key => text.
 *   store_id   NULL. The official website is not a store's. This is also what keeps the content
 *              out of `getSuperAdminAllSetting()`'s bag, which filters on the operator's
 *              `current_store` and is plucked on public pages.
 *   created_by the platform operator's id.
 *
 * ONE ROW PER LOCALE RATHER THAN ONE PER FIELD, for three measured reasons: a save is atomic;
 * `settings` has NO unique index, so the duplicate-row hazard P1-PLT-07 documents applies to two
 * names instead of fifty-one; and the readers' "newest row wins" rule then has one row to pick
 * from per locale rather than one per string.
 *
 * WHY THE READ AND THE WRITE SHARE EXACTLY ONE PREDICATE, AND WHY `Setting::putForStore()` IS NOT
 * USED FOR IT. That helper resolves the newest row by (name, store_id) and deliberately omits
 * `created_by` (P1-PLT-07's reasoning, which is right for a store's settings). This reader REQUIRES
 * `created_by` = the operator, so a row of that name owned by somebody else would absorb the
 * operator's save through the helper and then be ignored on read — the operator's edit silently
 * lost. The predicate is therefore written once, here, and used by both sides.
 *
 * CAN A MERCHANT WRITE THIS ROW? It is a real question, not a theoretical one, because
 * `PaymentSettingController::saveSettings()` writes one `settings` row per key it is POSTed. Two
 * independent reasons it cannot, both measured and both pinned by
 * `PlatformContentPublishingTest::test_a_merchants_own_settings_row_cannot_become_the_platforms_content`:
 *
 *   1. that writer pins `store_id` to `getCurrentStore() ?: '1'` (`PaymentSettingController:35`),
 *      which is never NULL, so its row cannot collide with this one's (name, NULL) identity; and
 *   2. `SettingOwnership::keep()` re-stamps a merchant's CREATE to their own merchant tenant root
 *      and refuses to let an UPDATE change `created_by` at all, so no merchant-reachable path can
 *      produce a row stamped with the operator's id.
 *
 * Were either ever to change, the failure is SAFE rather than exploitable: a row this reader
 * rejects means the built-in copy renders, not that a merchant's text is published.
 *
 * WHAT THIS DESIGN COSTS, stated rather than discovered later:
 *   - the content is in the database, so it is NOT in a git diff and no review sees it change.
 *     That is the trade this row asks for; the editor records nothing about who changed what, and
 *     an audit trail is named as outstanding in the evidence document rather than implied here.
 *   - two extra rows are read on each official-site request. Memoised per request below, and
 *     `Setting` is `Cachable`, so the steady state is a cache hit.
 */
final class PlatformContentStore
{
    /** The `settings.name` prefix. One row per locale. */
    public const NAME_PREFIX = 'platform_site_content_';

    /** Per-request memo: locale => [key => text]. Null means "not read yet". */
    private static ?array $memo = null;

    /** Per-request memo of the owner lookup. `false` means "looked, found none". */
    private static int|false|null $owner = null;

    /**
     * Drop the per-request memo.
     *
     * Needed by any test that writes and then reads in the same process, and by the editor between
     * a save and the redirect. `Setting` is `Cachable` on top of this, and an Eloquent write
     * flushes that — which is why every write below goes through Eloquent and never through
     * `DB::table()`.
     */
    public static function forget(): void
    {
        self::$memo  = null;
        self::$owner = null;
    }

    /**
     * The platform operator who owns the official site's content rows.
     *
     * THE SAME ROW `getSuperAdminAllSetting()` RESOLVES — `User::where('type', 'super admin')` —
     * so the two readers of the operator's settings agree about who the operator is. `orderBy('id')`
     * makes it deterministic where an installation has more than one such row, which matters
     * because the reader and the writer must pick the same one.
     *
     * NOT `Cache::remember('super_admin_details', ...)`, which is what the helper uses: that key is
     * shared, lives an hour, and CLAUDE.md records a measured false reading from a handler seeing
     * the pre-fixture `User` through a sibling of it. `User` is `Cachable` anyway, so the query is
     * already served from the model cache and flushed by any Eloquent write to `users`.
     */
    public static function ownerId(): ?int
    {
        if (self::$owner === null) {
            /*
             * SWALLOWED ON PURPOSE, AND THE PRECEDENT IS NEXT DOOR. `contact.blade.php` wraps its
             * own settings read in exactly this shape, for exactly this reason: the official site
             * is a PUBLIC page, and a page that renders its built-in copy is one a visitor can
             * read while a 500 on the company's own front door is not. Nothing is hidden by it —
             * the same request already reads `plans`, so a real database outage is visible there.
             *
             * It also makes the registry resolvable with no database at all, which is what lets
             * `PlatformContentRegistryTest` be a Unit test rather than dragging the schema into
             * the suite CI blocks on.
             */
            try {
                $id = User::query()
                    ->where('type', 'super admin')
                    ->orderBy('id')
                    ->value('id');
            } catch (\Throwable $e) {
                $id = null;
            }

            self::$owner = $id === null ? false : (int) $id;
        }

        return self::$owner === false ? null : self::$owner;
    }

    /**
     * Every supplied value, by locale.
     *
     * @return array<string, array<string, string>>
     */
    public static function all(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $empty = array_fill_keys(PlatformContent::LOCALES, []);

        $ownerId = self::ownerId();

        if ($ownerId === null) {
            // No platform operator row, so nothing can be owned by one. The site renders its
            // built-in copy, which is the correct answer rather than a failure.
            return self::$memo = $empty;
        }

        $names = [];
        foreach (PlatformContent::LOCALES as $locale) {
            $names[self::NAME_PREFIX . $locale] = $locale;
        }

        /*
         * `withoutGlobalScopes()` even though `Setting` is NOT in `config/tenancy.php`'s
         * `scoped_models` today: CLAUDE.md's rule is that `getGlobalScopes()` is not the same
         * question as "does it filter", and the one thing that must never happen here is the
         * official site's content disappearing because `Setting` was allowlisted later. The
         * predicate below is the ownership check; it does not need, and must not get, a tenant one.
         *
         * `orderBy('id')` ASCENDING and then overwrite: `settings` has no unique index, so a name
         * can carry duplicates, and every reader in this codebase takes the NEWEST (a `pluck()`
         * lets a later key overwrite an earlier one). This agrees with them.
         */
        try {
            $rows = Setting::withoutGlobalScopes()
                ->whereNull('store_id')
                ->where('created_by', (string) $ownerId)
                ->whereIn('name', array_keys($names))
                ->orderBy('id')
                ->get(['name', 'value']);
        } catch (\Throwable $e) {
            // Same reasoning as `ownerId()`: the built-in copy is a readable page.
            return self::$memo = $empty;
        }

        $out = $empty;

        foreach ($rows as $row) {
            $locale = $names[$row->name] ?? null;

            if ($locale === null) {
                continue;
            }

            $out[$locale] = self::decode($row->value);
        }

        return self::$memo = $out;
    }

    /** One supplied value, or null when the operator has supplied nothing for it. */
    public static function value(string $locale, string $key): ?string
    {
        if (! in_array($locale, PlatformContent::LOCALES, true)) {
            return null;
        }

        $v = self::all()[$locale][$key] ?? null;

        return is_string($v) && trim($v) !== '' ? $v : null;
    }

    /**
     * Replace one locale's whole map.
     *
     * The caller (the editor) merges before calling, so that a form covering one page cannot blank
     * the fields of another. Keys are filtered against the registry here as well, because this is
     * the last place before the row and a second filter costs nothing.
     *
     * @param array<string, string> $values
     */
    public static function put(string $locale, array $values): void
    {
        if (! in_array($locale, PlatformContent::LOCALES, true)) {
            return;
        }

        /*
         * A WRITE DOES NOT TRUST A READ-PATH MEMO, and that is not tidiness: `ownerId()` caches a
         * NEGATIVE answer as well as a positive one, so a process that resolved "there is no
         * platform operator" before one existed would then refuse to write for the rest of its
         * life. Under PHP-FPM a request is a process and this cannot happen; under a long-running
         * worker, and in a test that reads a public page before creating the operator, it does --
         * measured, as a silent no-op save, by
         * `PlatformContentPublishingTest::test_contact_details_are_absent_until_supplied_and_then_published`.
         * One query on a save is the right price for a save that cannot vanish.
         */
        self::forget();

        $ownerId = self::ownerId();

        if ($ownerId === null) {
            /* Unreachable through the editor, whose gate admits only a `type = 'super admin'`
               `User` -- so a row the canonical lookup must find. Left as a refusal rather than a
               guess, because a content row with no owner is one no reader would ever serve. */
            return;
        }

        $clean = [];

        foreach ($values as $key => $value) {
            if (! PlatformContent::has((string) $key)) {
                continue;
            }

            $value = PlatformContent::sanitise((string) $key, (string) $value);

            if ($value !== '') {
                $clean[(string) $key] = $value;
            }
        }

        /*
         * REGISTRY ORDER, so a saved row diffs readably and two saves of the same content produce
         * the same bytes.
         *
         * A SORT OVER THE CLEAN KEYS AND NOT A REBUILD FROM THE REGISTRY, which is the shape this
         * started as and is a trap worth recording. Iterating `fields()` and keeping the keys that
         * appear in `$clean` orders AND filters in one loop — so the `has()` guard above became an
         * equivalent mutant: a mutation run removed it and the test still passed, because the
         * rebuild silently dropped the undeclared key anyway. Two controls where one is invisible
         * is worse than one, because the invisible one is what a later "simplification" deletes.
         * Now `has()` is the only filter, it is pinned by
         * `PlatformContentPublishingTest::test_the_store_refuses_a_key_the_registry_does_not_declare`,
         * and ordering is only ordering.
         */
        $position = array_flip(array_keys(PlatformContent::fields()));

        uksort($clean, static fn (string $a, string $b) => ($position[$a] ?? PHP_INT_MAX) <=> ($position[$b] ?? PHP_INT_MAX));

        $name = self::NAME_PREFIX . $locale;
        $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        /* THE SAME PREDICATE AS THE READER. See the class docblock for why this is not
           `Setting::putForStore()`, whose lookup omits `created_by`. */
        $row = Setting::withoutGlobalScopes()
            ->where('name', $name)
            ->whereNull('store_id')
            ->where('created_by', (string) $ownerId)
            ->orderByDesc('id')
            ->first();

        if ($row !== null) {
            $row->value = $json;
            $row->save();
        } else {
            Setting::create([
                'name'       => $name,
                'value'      => $json,
                'store_id'   => null,
                'created_by' => (string) $ownerId,
            ]);
        }

        self::forget();
    }

    /**
     * @return array<string, string>
     *
     * A value that is not a JSON object of strings is treated as EMPTY rather than as an error.
     * The consequence of a corrupt row is then "the site shows its built-in copy", which is a page
     * a visitor can read; the alternative is a 500 on the company's own front page.
     */
    private static function decode(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        if (! is_array($decoded)) {
            return [];
        }

        $out = [];

        foreach ($decoded as $key => $v) {
            if (is_string($key) && is_string($v)) {
                $out[$key] = $v;
            }
        }

        return $out;
    }
}
