<?php

namespace Tests\Unit\Support;

use App\Support\Help\MerchantAnnouncements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 06 `SUPPORT-002` — «وإعلانات للويب وFlutter» : THE ANNOUNCEMENT REACHING THE MERCHANT.
 * =================================================================================================
 *
 * `SUPPORT-002`'s second clause is announcements, on the web **and** in Flutter. The traceability
 * row recorded the whole requirement as NOT IMPLEMENTED, and that measurement is now PART stale:
 * `CTRL-003` shipped platform maintenance announcements — a table, an operator screen at
 * `platform/maintenance`, and `<x-ss.maintenance-notice />` in the merchant layout.
 *
 * -------------------------------------------------------------------------------------------------
 * SO THIS IS NOT A SECOND ANNOUNCEMENT SYSTEM, AND `ARCH-002` IS THE REASON.
 *
 * `CTRL-003` owns the STORE (`platform_maintenance_announcements`), the operator's WRITES and the
 * transient banner. `SUPPORT-002` owns DELIVERY TO THE MERCHANT on both clients. One store, one
 * writer, and this is the merchant-facing reader that the web list page and
 * `GET api/admin/announcements` both go through — so the two clients cannot drift from each other,
 * which is the half of the requirement that names Flutter explicitly.
 *
 * `SUPPORT-002`'s recovered text names NO AUDIENCE, exactly as `CTRL-003`'s does not. Adding a
 * plan/store audience here would be inventing a requirement — the same thing `CTRL-003`'s migration
 * docblock declined to do, for the same reason. It is reported as an owner question instead.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT A MERCHANT MUST NEVER SEE IS THE LOAD-BEARING PART OF THIS FILE.
 *
 * `CTRL-003` keeps the operator's INTENT (`is_published`) apart from the WINDOW (`starts_at`,
 * `ends_at`), which gives four states — draft, scheduled, live, ended. A banner shows `live`. A
 * merchant-facing LIST is the new surface and it has to decide about the other three:
 *
 *   draft      NEVER. A half-typed notice must not reach anybody.
 *   scheduled  NEVER. Tomorrow's outage announced today, still being edited, is not news yet —
 *              and `CTRL-003` built `scheduled` precisely so an announcement could be written on
 *              Monday and appear on Thursday without somebody being awake.
 *   ended      YES, in the archive, flagged `is_live => false`. This is the ONLY reason the list
 *              exists beside the banner: a merchant who was away during the window has no other
 *              way to find out what the platform said. A banner is transient by construction.
 *
 * All four states are driven. `draft` and `scheduled` are asserted ABSENT, because "it is not in
 * the list" and "the list is empty" look identical to an assertion that only counts.
 *
 * -------------------------------------------------------------------------------------------------
 * THERE IS NO TENANT DATA IN THIS FEED AT ALL, AND THAT IS ASSERTED RATHER THAN ASSUMED.
 *
 * The table carries no `store_id` and the announcement is the platform's. `two_merchants_are_shown_
 * the_same_announcements` is the whole isolation argument: if every principal gets the identical
 * payload there is no per-tenant channel to confuse, and CLAUDE.md's `null_tenant => 'open'` trap
 * cannot apply to a query with no tenant predicate to lose.
 *
 * READS FAIL OPEN TO "NOTHING TO SAY". `CTRL-003`'s migration is approved for development and
 * isolated testing only, so first-party code runs against databases without the table; a feed that
 * threw would take the merchant panel down over an announcement.
 */
class MerchantAnnouncementFeedTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = 'platform_maintenance_announcements';

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function announcement(array $overrides = []): int
    {
        return (int) DB::table(self::TABLE)->insertGetId(array_merge([
            'title_en' => 'Scheduled maintenance',
            'title_ar' => 'صيانة مجدولة',
            'body_en' => 'The panel will be read-only.',
            'body_ar' => 'ستكون اللوحة للقراءة فقط.',
            'severity' => 'notice',
            'starts_at' => null,
            'ends_at' => null,
            'is_published' => 1,
            'created_by' => null,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ], $overrides));
    }

    #[Test]
    public function the_table_ctrl_003_owns_is_present_so_the_rest_of_this_file_means_something(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasTable(self::TABLE),
            'The premise of every other case here. `MerchantAnnouncements` answers [] when the '
            . 'table is absent -- by design -- so without this assertion a migration that stopped '
            . 'running would turn this whole file green against an empty feed.'
        );
    }

    #[Test]
    public function a_published_announcement_inside_its_window_reaches_the_merchant(): void
    {
        $this->announcement([
            'starts_at' => Carbon::now()->subHour(),
            'ends_at' => Carbon::now()->addHour(),
        ]);

        $live = MerchantAnnouncements::live('en');

        $this->assertCount(1, $live);
        $this->assertSame('Scheduled maintenance', $live[0]['title']);
        $this->assertTrue($live[0]['is_live']);
    }

    #[Test]
    public function a_null_window_at_either_end_means_no_bound_at_that_end(): void
    {
        $this->announcement(['starts_at' => null, 'ends_at' => null]);
        $this->announcement(['starts_at' => Carbon::now()->subDay(), 'ends_at' => null]);
        $this->announcement(['starts_at' => null, 'ends_at' => Carbon::now()->addDay()]);

        $this->assertCount(3, MerchantAnnouncements::live('en'));
    }

    /**
     * THE THREE THINGS A MERCHANT MUST NOT SEE, each asserted by ABSENCE OF ITS OWN TITLE rather
     * than by a count — see the class docblock.
     */
    #[Test]
    public function a_draft_never_reaches_the_merchant_on_either_surface(): void
    {
        $this->announcement(['is_published' => 0, 'title_en' => 'Half typed draft']);
        $this->announcement(['title_en' => 'Published notice']);

        foreach ([MerchantAnnouncements::live('en'), MerchantAnnouncements::feed('en')] as $set) {
            $titles = array_column($set, 'title');

            $this->assertContains('Published notice', $titles);
            $this->assertNotContains(
                'Half typed draft',
                $titles,
                'An unpublished announcement reached a merchant. `is_published` is the operator\'s '
                . 'intent and a draft is a notice they are still writing.'
            );
        }
    }

    #[Test]
    public function an_announcement_whose_window_has_not_opened_never_reaches_the_merchant(): void
    {
        $this->announcement([
            'title_en' => 'Next Thursday',
            'starts_at' => Carbon::now()->addDays(3),
            'ends_at' => Carbon::now()->addDays(4),
        ]);
        $this->announcement(['title_en' => 'Happening now']);

        foreach ([MerchantAnnouncements::live('en'), MerchantAnnouncements::feed('en')] as $set) {
            $titles = array_column($set, 'title');

            $this->assertContains('Happening now', $titles);
            $this->assertNotContains(
                'Next Thursday',
                $titles,
                'A scheduled announcement whose window has not opened reached a merchant. '
                . '`CTRL-003` separated intent from window exactly so this could be written early '
                . 'and appear on the day.'
            );
        }
    }

    /**
     * THE ONE REASON THE LIST EXISTS BESIDE THE BANNER. An ended announcement is out of `live()`
     * and IN `feed()`, flagged.
     */
    #[Test]
    public function an_ended_announcement_leaves_the_banner_and_stays_in_the_archive(): void
    {
        $this->announcement([
            'title_en' => 'Last night',
            'starts_at' => Carbon::now()->subDays(2),
            'ends_at' => Carbon::now()->subDay(),
        ]);

        $this->assertSame([], MerchantAnnouncements::live('en'));

        $feed = MerchantAnnouncements::feed('en');

        $this->assertCount(1, $feed);
        $this->assertSame('Last night', $feed[0]['title']);
        $this->assertFalse(
            $feed[0]['is_live'],
            'An expired announcement is in the archive but is not flagged, so the merchant cannot '
            . 'tell last night\'s outage from tonight\'s.'
        );
    }

    /**
     * URGENT BEFORE QUIET, AND THE ALPHABET IS THE WRONG WAY ROUND.
     *
     * `notice` sorts BEFORE `urgent`, so the quiet one is written SECOND and gets the higher id:
     * both a plain `ORDER BY severity` and a plain `ORDER BY id DESC` would put it on top, and only
     * a real severity ordering passes. This is `CTRL-003`'s own reasoning applied to the second
     * reader, because a merchant reads the top one.
     */
    #[Test]
    public function the_urgent_one_is_first_even_though_it_was_written_first(): void
    {
        $this->announcement(['title_en' => 'Urgent outage', 'severity' => 'urgent']);
        $this->announcement(['title_en' => 'Quiet notice', 'severity' => 'notice']);

        $this->assertSame(
            ['Urgent outage', 'Quiet notice'],
            array_column(MerchantAnnouncements::live('en'), 'title')
        );
    }

    #[Test]
    public function an_unrecognised_severity_reads_as_the_quieter_one(): void
    {
        $this->announcement(['severity' => 'CATASTROPHE']);

        $this->assertSame('notice', MerchantAnnouncements::live('en')[0]['severity']);
    }

    /**
     * THE LOCALE, IN BOTH DIRECTIONS. A resolver that always returned English would pass a test
     * that only drove the Arabic-missing case, which is `CTRL-003`'s stated reason for driving both.
     */
    #[Test]
    public function the_reader_gets_their_own_language(): void
    {
        $this->announcement();

        $this->assertSame('Scheduled maintenance', MerchantAnnouncements::live('en')[0]['title']);
        $this->assertSame('صيانة مجدولة', MerchantAnnouncements::live('ar')[0]['title']);
    }

    #[Test]
    public function an_arabic_reader_whose_half_is_empty_gets_the_english_one(): void
    {
        $this->announcement(['title_ar' => null, 'body_ar' => null]);

        $this->assertSame('Scheduled maintenance', MerchantAnnouncements::live('ar')[0]['title']);
    }

    #[Test]
    public function an_english_reader_whose_half_is_empty_gets_the_arabic_one(): void
    {
        $this->announcement(['title_en' => null, 'body_en' => null]);

        $this->assertSame('صيانة مجدولة', MerchantAnnouncements::live('en')[0]['title']);
    }

    #[Test]
    public function an_announcement_with_no_title_in_either_language_is_skipped_entirely(): void
    {
        $this->announcement(['title_en' => null, 'title_ar' => null]);

        $this->assertSame(
            [],
            MerchantAnnouncements::live('en'),
            'An announcement with nothing to say must be skipped, not rendered as an empty row.'
        );
    }

    /**
     * THE WHOLE TENANT-ISOLATION ARGUMENT, and it is an equality rather than a filter.
     */
    #[Test]
    public function the_feed_carries_no_tenant_column_so_every_reader_gets_the_same_payload(): void
    {
        $this->announcement();

        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn(self::TABLE, 'store_id'),
            'The announcement table has grown a `store_id`. If announcements ever become '
            . 'per-tenant, this feed needs a tenant predicate derived from the PRINCIPAL -- never '
            . 'from request input -- and these assertions stop being sufficient.'
        );

        foreach (MerchantAnnouncements::live('en') as $row) {
            foreach (['store_id', 'created_by', 'user_id'] as $forbidden) {
                $this->assertArrayNotHasKey(
                    $forbidden,
                    $row,
                    'The merchant-facing payload carries `' . $forbidden . '`. Nothing about who '
                    . 'wrote an announcement, or for whom, belongs on a merchant\'s screen.'
                );
            }
        }
    }

    #[Test]
    public function the_newest_comes_first_within_one_severity(): void
    {
        $this->announcement(['title_en' => 'Older']);
        $this->announcement(['title_en' => 'Newer']);

        $this->assertSame(['Newer', 'Older'], array_column(MerchantAnnouncements::feed('en'), 'title'));
    }

    #[Test]
    public function the_feed_respects_a_limit_and_refuses_a_nonsense_one(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->announcement(['title_en' => 'Notice ' . $i]);
        }

        $this->assertCount(2, MerchantAnnouncements::feed('en', 2));
        $this->assertCount(1, MerchantAnnouncements::feed('en', 0));
        $this->assertCount(1, MerchantAnnouncements::feed('en', -7));
        $this->assertCount(
            5,
            MerchantAnnouncements::feed('en', 10_000),
            'A caller-supplied limit must be capped rather than trusted; the cap is above the row '
            . 'count here so this asserts the cap does not truncate a legitimate read.'
        );
    }

    /**
     * THE CAP ITSELF, AND THIS CASE EXISTS BECAUSE A MUTATION FOUND THE GAP.
     *
     * The case above drives `feed('en', 10_000)` against FIVE rows, so it returns 5 whether or not
     * the cap is there — removing `min($limit, self::MAX_LIMIT)` left it green, and the sibling
     * Feature case (`<= 100` against one row) too. A cap is only observable above the cap, so this
     * writes 105 rows and asserts exactly 100. It is the slowest case in the file for that reason
     * and it is the only one that measures the thing.
     */
    #[Test]
    public function a_caller_asking_for_everything_is_capped_at_a_hundred(): void
    {
        for ($i = 0; $i < 105; $i++) {
            $this->announcement(['title_en' => 'Notice ' . $i]);
        }

        $this->assertCount(
            100,
            MerchantAnnouncements::feed('en', 10_000),
            'A caller-supplied page size reached the database uncapped. There is no tenant column '
            . 'to leak across here, so this is the ordinary question of a merchant asking for every '
            . 'announcement the platform has ever made in one response.'
        );

        /* AND `live()` TAKES THE SAME CAP, which `feed()`'s own argument cannot prove: `live()`
           passes the constant itself, so a cap removed from `rows()` is invisible from here unless
           the live path is driven too. */
        $this->assertCount(100, MerchantAnnouncements::live('en'));
    }

    /**
     * READS FAIL OPEN. Driven by dropping the table, because `Schema::hasTable()` is the real
     * guard and a mocked one would prove nothing about it.
     */
    #[Test]
    public function a_database_without_the_table_answers_nothing_to_say_instead_of_throwing(): void
    {
        \Illuminate\Support\Facades\Schema::drop(self::TABLE);

        $this->assertSame([], MerchantAnnouncements::live('en'));
        $this->assertSame([], MerchantAnnouncements::feed('en'));
    }
}
