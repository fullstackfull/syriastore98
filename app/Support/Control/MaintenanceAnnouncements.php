<?php

namespace App\Support\Control;

use App\Models\PlatformMaintenanceAnnouncement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 06 `CTRL-003` — WHAT THE PLATFORM IS TELLING ITS MERCHANTS RIGHT NOW.
 * =================================================================================================
 *
 * `CTRL-003`'s second limb: *"إعلانات صيانة"*, maintenance announcements. The table's docblock
 * carries the schema decisions; this class is the two questions asked of it — *what is live* (every
 * merchant request) and *what exists* (the operator's screen) — plus the writes, each of which
 * records a change-log entry.
 *
 * -------------------------------------------------------------------------------------------------
 * THE WINDOW IS COMPUTED IN SQL, NOT IN PHP, and that is not an optimisation.
 *
 * A PHP filter over `all()` would read every announcement the platform has ever made on every
 * merchant request. The predicate is `is_published` AND (`starts_at` is null OR `starts_at` <= now)
 * AND (`ends_at` is null OR `ends_at` >= now), which `pma_live` indexes exactly.
 *
 * `now()` IS TAKEN ONCE, at the top, and passed into both bounds. Two separate `now()` calls can
 * straddle a second, so an announcement whose window is exactly `[t, t]` could be both "not started"
 * and "already ended" in one query. A one-second window is contrived; a one-second difference
 * between two bound comparisons is the kind of thing that makes a test flaky once a month and is
 * then blamed on the test.
 *
 * -------------------------------------------------------------------------------------------------
 * THE LOCALE FALLS BACK, AND THE FALLBACK IS THE POINT.
 *
 * `textFor()` prefers the reader's language and uses the other one when the reader's is empty. An
 * Arabic merchant seeing NOTHING about tonight's outage is worse than seeing it in English, and an
 * operator writing at 2am will not always write both. `MaintenanceAnnouncementTest` asserts both
 * directions — the preference AND the fallback — because a resolver that always returned English
 * would pass a test that only checked "something was shown".
 *
 * An announcement with no title in EITHER language is skipped entirely rather than rendered as an
 * empty banner. That is the one case where showing nothing is right: there is nothing to show.
 *
 * -------------------------------------------------------------------------------------------------
 * READS FAIL OPEN TO "NOTHING TO SAY", AND WRITES REFUSE.
 *
 * The same asymmetry `FeatureControl` uses and for the same reason: the migration is approved for
 * development and isolated testing only, so first-party code may run against a database without the
 * table, and a banner component on every merchant page must not be able to 500 the panel. A WRITE
 * on such a database has not happened and says so.
 */
final class MaintenanceAnnouncements
{
    /**
     * The announcements a merchant should be shown now, newest first, resolved into one language.
     *
     * @param  string|null  $locale  the reader's language; the application's current one if null
     * @return array<int, array<string, mixed>>
     */
    public static function live(?string $locale = null): array
    {
        if (! self::tableExists()) {
            return [];
        }

        $locale = self::normaliseLocale($locale);

        try {
            /* ONE `now()`, BOTH BOUNDS. See the class docblock. */
            $now = Carbon::now();

            $rows = PlatformMaintenanceAnnouncement::query()
                ->where('is_published', true)
                ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
                ->orderByDesc('id')
                ->get();
        } catch (\Throwable $failure) {
            Log::warning('MaintenanceAnnouncements could not read the live announcements.', [
                'exception' => $failure->getMessage(),
            ]);

            return [];
        }

        /*
         * URGENT FIRST, THEN NEWEST, SORTED IN PHP AND NOT IN SQL.
         *
         * A merchant with two live announcements reads the top one; if the quiet one is on top
         * because it is newer, the urgent one is the one they miss. `severity` is a string and
         * `notice` sorts BEFORE `urgent` alphabetically -- the wrong way round -- so the order has
         * to be stated rather than inherited from a collation.
         *
         * THE FIRST VERSION STATED IT IN `orderByRaw()`, with the constant concatenated into the
         * SQL. It was safe -- a class constant, not input -- and it is gone anyway: CLAUDE.md
         * records a LIVE cross-tenant injection found among this codebase's dynamic raw-SQL sites,
         * and a census that whitelists static strings and treats everything else as dynamic BY
         * DEFAULT, precisely so that an unthought-of spelling is caught. Adding a sixteenth site
         * that a reader has to verify by hand, for an ordering over at most a handful of rows, is
         * the wrong trade. There is now no raw SQL in this class at all.
         *
         * `usort` IS STABLE ON PHP 8, so the `id DESC` the database applied survives inside each
         * severity group and only the grouping is decided here.
         */
        $rows = $rows->all();

        usort(
            $rows,
            fn (PlatformMaintenanceAnnouncement $a, PlatformMaintenanceAnnouncement $b): int
                => self::severityRank($a) <=> self::severityRank($b)
        );

        $live = [];

        foreach ($rows as $row) {
            $title = self::textFor($row, 'title', $locale);

            if ($title === '') {
                continue;   // nothing to show; see the class docblock
            }

            $live[] = [
                'id' => (int) $row->getKey(),
                'title' => $title,
                'body' => self::textFor($row, 'body', $locale),
                'severity' => $row->resolvedSeverity(),
                'starts_at' => $row->starts_at,
                'ends_at' => $row->ends_at,
            ];
        }

        return $live;
    }

    /**
     * Every announcement, for the operator's screen, newest first — drafts and expired ones too.
     *
     * BOTH LANGUAGES ARE RETURNED SEPARATELY here, unlike `live()`. The operator's job on this
     * screen is to see which half is missing, so resolving a fallback for them would hide exactly
     * what they came to check.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(int $limit = 50): array
    {
        if (! self::tableExists()) {
            return [];
        }

        $now = Carbon::now();

        return PlatformMaintenanceAnnouncement::query()
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (PlatformMaintenanceAnnouncement $row): array => [
                'id' => (int) $row->getKey(),
                'title_en' => (string) ($row->title_en ?? ''),
                'title_ar' => (string) ($row->title_ar ?? ''),
                'body_en' => (string) ($row->body_en ?? ''),
                'body_ar' => (string) ($row->body_ar ?? ''),
                'severity' => $row->resolvedSeverity(),
                'starts_at' => $row->starts_at,
                'ends_at' => $row->ends_at,
                'is_published' => (bool) $row->is_published,
                /*
                 * THE COMPUTED STATE, so the operator does not have to hold a published flag, two
                 * nullable timestamps and the current time in their head and work out whether a
                 * merchant can see this. The screen prints this word; the window is printed beside
                 * it, which is the other half of the same answer.
                 */
                'state' => self::stateOf($row, $now),
            ])
            ->all();
    }

    /** Create or replace an announcement. Returns the row. A new one is a DRAFT. */
    public static function save(array $attributes, mixed $actor = null, ?int $id = null): PlatformMaintenanceAnnouncement
    {
        self::assertWritable();

        $clean = [
            'title_en' => self::trimOrNull($attributes['title_en'] ?? null, 160),
            'title_ar' => self::trimOrNull($attributes['title_ar'] ?? null, 160),
            'body_en' => self::trimOrNull($attributes['body_en'] ?? null, 20000),
            'body_ar' => self::trimOrNull($attributes['body_ar'] ?? null, 20000),
            'severity' => in_array($attributes['severity'] ?? null, PlatformMaintenanceAnnouncement::SEVERITIES, true)
                ? $attributes['severity']
                : PlatformMaintenanceAnnouncement::SEVERITY_NOTICE,
            'starts_at' => $attributes['starts_at'] ?? null,
            'ends_at' => $attributes['ends_at'] ?? null,
        ];

        $row = $id === null
            ? null
            : PlatformMaintenanceAnnouncement::query()->find($id);

        if ($row instanceof PlatformMaintenanceAnnouncement) {
            $before = self::snapshot($row);
            $row->fill($clean)->save();
            $after = self::snapshot($row);
        } else {
            $before = null;
            /*
             * `is_published` IS NOT IN `$clean`, SO A NEW ROW TAKES THE COLUMN DEFAULT: false. An
             * announcement is written and THEN published, deliberately -- a save that published
             * would mean a half-typed notice reaching every merchant the moment the operator
             * tabbed away.
             */
            $row = PlatformMaintenanceAnnouncement::create($clean + [
                'created_by' => self::actorId($actor),
            ]);
            $after = self::snapshot($row);
        }

        PlatformChangeLog::record(
            PlatformChangeLog::SUBJECT_ANNOUNCEMENT,
            (string) $row->getKey(),
            PlatformChangeLog::ACTION_DEFINED,
            $before,
            $after,
            $actor
        );

        return $row;
    }

    /** Publish an announcement, so merchants inside its window see it. */
    public static function publish(int $id, mixed $actor = null): bool
    {
        return self::setPublished($id, true, PlatformChangeLog::ACTION_PUBLISHED, $actor);
    }

    /**
     * Withdraw an announcement. The row and both languages are kept.
     *
     * NOT A DELETE, and that is `CTRL-004`'s shape applied here: withdrawing an announcement is
     * availability, and the words the platform published -- and when -- are the record somebody
     * asks for after an incident. The operator screen offers no delete at all for the same reason.
     */
    public static function withdraw(int $id, mixed $actor = null): bool
    {
        return self::setPublished($id, false, PlatformChangeLog::ACTION_WITHDRAWN, $actor);
    }

    // =============================================================================================
    // internals
    // =============================================================================================

    private static function setPublished(int $id, bool $published, string $action, mixed $actor): bool
    {
        self::assertWritable();

        $row = PlatformMaintenanceAnnouncement::query()->find($id);

        if (! $row instanceof PlatformMaintenanceAnnouncement) {
            return false;
        }

        $before = self::snapshot($row);

        $row->is_published = $published;
        $row->save();

        PlatformChangeLog::record(
            PlatformChangeLog::SUBJECT_ANNOUNCEMENT,
            (string) $row->getKey(),
            $action,
            $before,
            self::snapshot($row),
            $actor
        );

        return true;
    }

    /**
     * `draft` | `scheduled` | `live` | `ended`.
     *
     * FOUR WORDS BECAUSE THERE ARE FOUR SITUATIONS, and three of them look identical on a screen
     * that prints only `is_published`: an unpublished draft, a published announcement whose window
     * has not opened, and a published one whose window has closed all show nothing to a merchant.
     * An operator asking "why can't they see it" needs to be told which.
     */
    /** 0 for urgent, 1 for everything else — see the sort in `live()`. */
    private static function severityRank(PlatformMaintenanceAnnouncement $row): int
    {
        return $row->resolvedSeverity() === PlatformMaintenanceAnnouncement::SEVERITY_URGENT ? 0 : 1;
    }

    private static function stateOf(PlatformMaintenanceAnnouncement $row, Carbon $now): string
    {
        if (! $row->is_published) {
            return 'draft';
        }

        if ($row->starts_at !== null && $row->starts_at->greaterThan($now)) {
            return 'scheduled';
        }

        if ($row->ends_at !== null && $row->ends_at->lessThan($now)) {
            return 'ended';
        }

        return 'live';
    }

    /**
     * The reader's language, or the other one when the reader's is empty.
     *
     * @param  string  $field  `title` or `body`
     */
    private static function textFor(PlatformMaintenanceAnnouncement $row, string $field, string $locale): string
    {
        $preferred = trim((string) ($row->getAttribute($field . '_' . $locale) ?? ''));

        if ($preferred !== '') {
            return $preferred;
        }

        $other = $locale === 'ar' ? 'en' : 'ar';

        return trim((string) ($row->getAttribute($field . '_' . $other) ?? ''));
    }

    /**
     * `ar` or `en`, and nothing else.
     *
     * The table has two language columns, so a reader whose application locale is `fr` -- and
     * `resources/lang/` carries several -- must resolve to one of them rather than to
     * `title_fr`, which would be a null attribute and an empty banner. English is the fallback
     * because it is the column every other locale's reader is most likely to be able to read, and
     * because `PlatformContent::LOCALES` makes the same two-language choice for the official site.
     */
    private static function normaliseLocale(?string $locale): string
    {
        $locale = strtolower(trim((string) ($locale ?? app()->getLocale())));

        return str_starts_with($locale, 'ar') ? 'ar' : 'en';
    }

    /** @return array<string, mixed> */
    private static function snapshot(PlatformMaintenanceAnnouncement $row): array
    {
        return [
            'severity' => $row->resolvedSeverity(),
            'is_published' => (bool) $row->is_published,
            'starts_at' => $row->starts_at?->toDateTimeString(),
            'ends_at' => $row->ends_at?->toDateTimeString(),
        ];
    }

    private static function trimOrNull(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private static function actorId(mixed $actor): ?int
    {
        if ($actor === null) {
            $actor = auth()->user();
        }

        /*
         * `User` AND ONLY `User`. `customers` has a `type` column with `'type'` in
         * `Customer::$fillable`, and CLAUDE.md records a `Customer` typed `super admin` reaching a
         * real handler -- writing its primary key into `created_by` would make this row name a
         * `User` who had nothing to do with it. `PlatformChangeLog` makes the same distinction and
         * keeps the class name instead.
         */
        return $actor instanceof \App\Models\User ? (int) $actor->getKey() : null;
    }

    private static function assertWritable(): void
    {
        if (self::tableExists()) {
            return;
        }

        throw new \RuntimeException(
            'platform_maintenance_announcements is not present, so this announcement cannot be '
            . 'stored. Apply 2026_11_26_000300_create_platform_maintenance_announcements_table. '
            . 'Reads answer "nothing to say" on such a database by design -- the merchant banner '
            . 'must not be able to 500 the panel -- but a write refuses rather than reporting an '
            . 'announcement it did not store.'
        );
    }

    private static function tableExists(): bool
    {
        try {
            return Schema::hasTable('platform_maintenance_announcements');
        } catch (\Throwable) {
            return false;
        }
    }
}
