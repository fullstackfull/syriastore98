<?php

namespace App\Support\Help;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 06 `SUPPORT-002` — «وإعلانات للويب وFlutter» : THE ANNOUNCEMENT, AS THE MERCHANT SEES IT.
 * =================================================================================================
 *
 * `SUPPORT-002` is *"مساعدة سياقية وإعلانات للويب وFlutter"* — contextual help and announcements, for
 * the web **and** Flutter (`DECISION-ID-REGISTER.md` §2, the SUPPORT table, row 2). `ContextualHelp`
 * is the first clause; this is the second.
 *
 * -------------------------------------------------------------------------------------------------
 * THIS IS NOT A SECOND ANNOUNCEMENT SYSTEM. IT IS THE SECOND READER OF `CTRL-003`'s.
 *
 * `MASTER-PLAN-REQUIREMENT-TRACEABILITY.md` recorded `SUPPORT-002` as NOT IMPLEMENTED with "no
 * announcement table, model, route or view". That was true when measured and is now PART stale:
 * `CTRL-003` shipped platform maintenance announcements — the table
 * `platform_maintenance_announcements`, `App\Support\Control\MaintenanceAnnouncements`, the operator
 * screen `platform/maintenance`, and `<x-ss.maintenance-notice />` included from the merchant
 * layout.
 *
 * So the division of ownership, stated once here because every other file in this slice assumes it:
 *
 *     CTRL-003  owns the STORE, the operator's WRITES, and the transient in-panel BANNER.
 *     SUPPORT-002 owns DELIVERY TO THE MERCHANT on both clients: a durable list in the panel and
 *                 `GET api/admin/announcements` for the Flutter admin app.
 *
 * `ARCH-002` forbids a parallel engine, and a second table would have been exactly that. **This
 * class adds no table, no model and no write path.** It is a read over `CTRL-003`'s table, and it is
 * the ONE read both merchant clients go through — which is what stops the web and the app from
 * drifting, the half of the requirement that names Flutter explicitly.
 *
 * NO MODEL CLASS, AND THE REASON IS A TEST RATHER THAN A PREFERENCE. `CTRL-003`'s
 * `PlatformMaintenanceAnnouncement` lives in `app/Models/`, and
 * `tests/Unit/Tenancy/ClaudeMdCachableModelClaimTest` re-measures the file count and the
 * `Cachable` exception list in that directory against CLAUDE.md's prose on every run. A workstream
 * that may not edit CLAUDE.md therefore may not add a file to `app/Models/` either. The query
 * builder has no such coupling, and this class holds exactly ONE `DB::table()` statement for that
 * reason — `RawQueryTenancyInventoryTest` counts them.
 *
 * NO AUDIENCE, AND THAT IS A READING OF THE DECISION RATHER THAN A SHORTCUT. `CTRL-003`'s migration
 * declined to invent plan/store/pilot targeting because *"إعلانات صيانة"* names none.
 * `SUPPORT-002`'s text names none either — it names the two CLIENTS, not an audience. So "announce
 * to one plan first" stays an owner question; building it here would be inventing a requirement
 * twice over.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT A MERCHANT MUST NEVER SEE. This is the whole design of the two methods below.
 *
 * `CTRL-003` keeps the operator's INTENT (`is_published`) apart from the WINDOW (`starts_at`,
 * `ends_at`), which yields four states. A banner only ever shows one of them. A durable LIST is the
 * new surface, so it has to decide about the rest:
 *
 *     draft      NEVER — a half-typed notice must not reach anybody.
 *     scheduled  NEVER — `CTRL-003` built this state so an announcement could be written on Monday
 *                and appear on Thursday; showing it on Monday throws that away.
 *     live       both methods.
 *     ended      `feed()` only, flagged `is_live => false`.
 *
 * AND `ended` IS THE ONLY REASON THE LIST EXISTS BESIDE THE BANNER: a merchant who was away during
 * the window has no other way to learn what the platform said. A banner is transient by
 * construction, and "the merchant did not happen to be logged in" is not a reason for the platform's
 * own record of an outage to be unreachable.
 *
 * -------------------------------------------------------------------------------------------------
 * `now()` IS TAKEN ONCE. Two separate `now()` calls can straddle a second, so a window of exactly
 * `[t, t]` could be both "not started" and "already ended" in one query — `CTRL-003` records the
 * same decision, and a one-second difference between two bound comparisons is the kind of thing
 * that makes a test flaky once a month and is then blamed on the test.
 *
 * THE WINDOW IS IN SQL AND THE SEVERITY ORDER IS IN PHP. `pma_live` indexes
 * `(is_published, starts_at, ends_at)` exactly, and the alternative to a PHP sort is an
 * `orderByRaw()` with a constant concatenated into it — a sixteenth dynamic-raw-SQL site for a sort
 * over at most a hundred rows, which CLAUDE.md's census makes the wrong trade. `usort` is stable on
 * PHP 8, so the `id DESC` the database applied survives inside each severity group.
 *
 * READS FAIL OPEN TO "NOTHING TO SAY". `CTRL-003`'s migration is approved for development and
 * isolated database testing only, so first-party code genuinely runs against databases without the
 * table — and a merchant panel that 500s over an announcement is a worse outcome than one that
 * misses it. There is no write path here at all, so there is no asymmetry to state: the operator's
 * screen is `CTRL-003`'s and refuses on such a database itself.
 *
 * NOTHING HERE IS TENANT DATA. The table has no `store_id`, the announcement is the platform's, and
 * the payload deliberately drops `created_by` — who wrote it is the operator's business.
 * `MerchantAnnouncementFeedTest` asserts the absent column and the absent keys rather than arguing
 * it, and the API test requires two different merchants to receive byte-identical payloads.
 */
final class MerchantAnnouncements
{
    private const TABLE = 'platform_maintenance_announcements';

    /**
     * The two severities `CTRL-003` stores.
     *
     * `PlatformMaintenanceAnnouncement::SEVERITIES` is the owner of this vocabulary and is on
     * `CTRL-003`'s branch, not this one, so it cannot be referenced from here yet. The pair is
     * restated rather than guessed: the migration gives `severity` a column DEFAULT of `notice`,
     * which `MerchantAnnouncementFeedTest` reads off the real schema.
     *
     * ANYTHING UNRECOGNISED READS AS `notice`, the quieter of the two, and that direction is
     * deliberate: a truncated or corrupted value shouting "urgent" at every merchant on the
     * platform is the worse outcome, and a severity has no security meaning to fail closed over.
     */
    public const SEVERITY_NOTICE = 'notice';

    public const SEVERITY_URGENT = 'urgent';

    public const SEVERITIES = [self::SEVERITY_NOTICE, self::SEVERITY_URGENT];

    /**
     * HOW MANY ROWS A CALLER CAN ASK FOR AT MOST.
     *
     * The Flutter app sends a page size and `SUPPORT-002`'s endpoint is on `custom.auth` like every
     * other `api/admin` route, so the number is caller-supplied. Capped rather than trusted: this
     * is not a tenancy question — there is no tenant column to leak across — it is the ordinary one
     * of a merchant asking for every announcement the platform has ever made in one response.
     */
    private const MAX_LIMIT = 100;

    /**
     * What a merchant should be shown RIGHT NOW: published, started, not ended. Urgent first.
     *
     * @return list<array<string, mixed>>
     */
    public static function live(?string $locale = null): array
    {
        return self::resolve(self::rows(true, self::MAX_LIMIT), $locale);
    }

    /**
     * The merchant's durable list: everything published whose window has OPENED, newest first.
     *
     * Includes the ended ones, flagged. Never a draft and never a future window — see the class
     * docblock.
     *
     * @return list<array<string, mixed>>
     */
    public static function feed(?string $locale = null, int $limit = self::MAX_LIMIT): array
    {
        return self::resolve(self::rows(false, $limit), $locale);
    }

    // =============================================================================================
    // internals
    // =============================================================================================

    /**
     * THE ONLY QUERY IN THIS CLASS, and deliberately the only one — see the class docblock on
     * `RawQueryTenancyInventoryTest`.
     *
     * @return list<object>
     */
    private static function rows(bool $onlyLive, int $limit): array
    {
        if (! self::tableExists()) {
            return [];
        }

        /* ONE `now()`, EVERY BOUND. See the class docblock. */
        $now   = Carbon::now();
        $limit = max(1, min($limit, self::MAX_LIMIT));

        try {
            return DB::table(self::TABLE)
                ->where('is_published', true)
                ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                ->when(
                    $onlyLive,
                    fn ($q) => $q->where(
                        fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>=', $now)
                    )
                )
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->all();
        } catch (\Throwable $failure) {
            /* LOGGED RATHER THAN SWALLOWED, and then answered as "nothing to say". */
            Log::warning('MerchantAnnouncements could not read the announcement feed.', [
                'exception' => $failure->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param  list<object>  $rows
     * @return list<array<string, mixed>>
     */
    private static function resolve(array $rows, ?string $locale): array
    {
        $locale = ContextualHelp::normaliseLocale($locale);
        $now    = Carbon::now();

        /*
         * URGENT BEFORE QUIET, AND THE ALPHABET IS THE WRONG WAY ROUND: `notice` sorts BEFORE
         * `urgent`, so neither a severity collation nor `id DESC` puts the urgent one on top. A
         * merchant with two live announcements reads the first; if the quiet one is there because it
         * is newer, the urgent one is the one they miss.
         */
        usort(
            $rows,
            fn (object $a, object $b): int => self::severityRank($a) <=> self::severityRank($b)
        );

        $out = [];

        foreach ($rows as $row) {
            $title = self::textFor($row, 'title', $locale);

            if ($title === '') {
                /* Nothing to show. The one case where showing nothing is right. */
                continue;
            }

            $endsAt = self::timestamp($row->ends_at ?? null);

            $out[] = [
                'id' => (int) $row->id,
                'title' => $title,
                'body' => self::textFor($row, 'body', $locale),
                'severity' => self::resolvedSeverity($row),
                'starts_at' => self::timestamp($row->starts_at ?? null)?->toDateTimeString(),
                'ends_at' => $endsAt?->toDateTimeString(),
                /* `live` IS NOT THE SAME QUESTION AS "is it in this list". `feed()` carries the
                   ended ones so a merchant who was away can read them, and a merchant who cannot
                   tell last night's outage from tonight's has been told nothing useful. */
                'is_live' => $endsAt === null || $endsAt->greaterThanOrEqualTo($now),
            ];
        }

        return $out;
    }

    /** 0 for urgent, 1 for everything else. */
    private static function severityRank(object $row): int
    {
        return self::resolvedSeverity($row) === self::SEVERITY_URGENT ? 0 : 1;
    }

    private static function resolvedSeverity(object $row): string
    {
        $severity = strtolower(trim((string) ($row->severity ?? '')));

        return in_array($severity, self::SEVERITIES, true) ? $severity : self::SEVERITY_NOTICE;
    }

    /**
     * The reader's language, or the other one when the reader's half is empty.
     *
     * An Arabic merchant seeing NOTHING about tonight's outage is worse than seeing it in English,
     * and an operator writing at 2am will not always write both halves. `CTRL-003` makes the same
     * choice for the banner; `MerchantAnnouncementFeedTest` drives BOTH directions, because a
     * resolver that always returned English would pass a test that only checked the Arabic-missing
     * case.
     *
     * @param  string  $field  `title` or `body`
     */
    private static function textFor(object $row, string $field, string $locale): string
    {
        $preferred = trim((string) ($row->{$field . '_' . $locale} ?? ''));

        if ($preferred !== '') {
            return $preferred;
        }

        $other = $locale === 'ar' ? 'en' : 'ar';

        return trim((string) ($row->{$field . '_' . $other} ?? ''));
    }

    /**
     * A stored timestamp as a `Carbon`, or null.
     *
     * The query builder hands back whatever the driver stored — a string on both MySQL and SQLite,
     * and the column is nullable at both ends because a null bound means "no bound there".
     */
    private static function timestamp(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            /* An unparseable stored timestamp must not take the panel down over an announcement. */
            return null;
        }
    }

    private static function tableExists(): bool
    {
        try {
            return Schema::hasTable(self::TABLE);
        } catch (\Throwable) {
            return false;
        }
    }
}
