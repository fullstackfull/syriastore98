<?php

namespace Tests\Feature\Analytics;

use App\Models\Order;
use App\Support\Analytics\AnalyticsPeriod;
use App\Support\Metrics\MerchantAnalyticsFigures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Shetabit\Visitor\Models\Visit;
use Tests\Feature\Security\SecurityTestCase;

/**
 * ANA-004 / GAP-227(a) — CAMPAIGN TRAFFIC: THE TAGS WERE BEING STORED AND NOTHING READ THEM.
 * =============================================================================================
 *
 * MEASURED 2026-11-22. `utm_source` / `utm_medium` / `utm_campaign` appear 20+ times in this
 * repository and EVERY ONE is an outbound link in a vendored WorkDo view pointing at workdo.io.
 * Not one line of first-party code reads an INBOUND tag. Meanwhile `SanitisedVisitor::url()`
 * keeps the query string and redacts only credential-shaped parameter NAMES — `utm_*` matches
 * none of them, verified by running `redact()` on a tagged URL — so every tagged landing has been
 * recorded in `shetabit_visits.url` all along and no screen has ever shown it.
 *
 * So a merchant who tags a newsletter, an ad or a WhatsApp blast can see their total page views
 * go up and cannot see WHICH campaign did it. That is the gap.
 *
 * WHAT THIS DELIBERATELY DOES NOT CLAIM, and it is the substance of the design:
 *
 *   NO ATTRIBUTION, NO CAMPAIGN REVENUE. There is no link of any kind between a visit row and an
 *   order row — measured for the conversion tile and unchanged here. So "this campaign earned
 *   you X" is not derivable, and a figure shaped like it would be invented. The breakdown counts
 *   LANDINGS and says so.
 *
 *   IT COUNTS TAGGED PAGE VIEWS, NOT PEOPLE AND NOT SESSIONS. A shopper who arrives on a tagged
 *   link and then browses five untagged pages contributes ONE row here and six to the page-views
 *   tile. Those two numbers are therefore not comparable, which the note states rather than
 *   leaving a reader to assume.
 *
 * NOT AN OWNER DECISION: no threshold, no spend, no monetary rule, no new data collected. The
 * rows already exist; this reads them.
 */
class CampaignTrafficTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function visit($store, string $url, string $when = '2026-02-10 12:00:00'): void
    {
        $visit = new Visit();
        $visit->timestamps = false;
        $visit->store_id = $store->id;
        $visit->url = $url;
        $visit->created_at = now()->parse($when);
        $visit->updated_at = now()->parse($when);
        $visit->save();
    }

    private function campaigns($store): array
    {
        return MerchantAnalyticsFigures::forStore(
            $store->id,
            $store->currency ?? 'USD',
            null,
            AnalyticsPeriod::fromRequest([
                AnalyticsPeriod::KEY_PERIOD => AnalyticsPeriod::CUSTOM,
                AnalyticsPeriod::KEY_FROM => '2026-02-01',
                AnalyticsPeriod::KEY_TO => '2026-02-28',
            ], now()->parse('2026-02-28')->endOfDay())
        )['campaigns'];
    }

    /** @return array<string,string> label => count, so an assertion names the campaign rather than an index. */
    private function rowsByLabel(array $breakdown): array
    {
        $out = [];

        foreach ($breakdown['rows'] as $row) {
            // BOTH sides are stripped. The label is isolated too, because a visitor writes it --
            // see `campaigns()`. Stripping here keeps these assertions about the GROUPING; the
            // isolation itself is asserted separately, on the raw label.
            $out[str_replace(["\u{2066}", "\u{2069}"], '', $row['label'])]
                = str_replace(["\u{2066}", "\u{2069}"], '', $row['count']);
        }

        return $out;
    }

    public function test_tagged_landings_are_counted_per_campaign(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $this->visit($store, 'https://shop.test/?utm_source=newsletter&utm_campaign=spring');
        $this->visit($store, 'https://shop.test/x?utm_source=newsletter&utm_campaign=spring');
        $this->visit($store, 'https://shop.test/?utm_source=instagram&utm_campaign=spring');

        $rows = $this->rowsByLabel($this->campaigns($store));

        $this->assertSame('2', $rows['spring — newsletter'] ?? null,
            'Two landings on the same source and campaign were not counted together.');
        $this->assertSame('1', $rows['spring — instagram'] ?? null,
            'The same campaign on a second source was folded into the first, so the merchant '
            . 'cannot tell which channel delivered the traffic.');
    }

    /**
     * UNTAGGED TRAFFIC IS NOT A CAMPAIGN AND MUST NOT APPEAR. The breakdown answers "which
     * campaigns sent traffic"; filing ordinary browsing under a blank campaign row would make the
     * column sum to the page-views tile and thereby imply the two measure the same thing.
     *
     * WHERE THESE ARE ACTUALLY EXCLUDED, established by a mutation rather than assumed: at the
     * SQL pre-filter, not in the PHP loop. Neither `/products` nor `?ref=someone` contains the
     * letters `utm`, so neither is ever read. Gutting the PHP guard leaves this test green, and
     * that is correct -- the case this test covers is the common one, and the PHP guard is
     * covered by `test_the_letters_utm_elsewhere_in_a_url_are_not_a_campaign`, whose URLs DO
     * reach the loop. Both layers are needed and each has the test that can see it.
     */
    public function test_untagged_visits_do_not_appear(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $this->visit($store, 'https://shop.test/?utm_source=newsletter&utm_campaign=spring');
        $this->visit($store, 'https://shop.test/products');
        $this->visit($store, 'https://shop.test/?ref=someone');

        $rows = $this->rowsByLabel($this->campaigns($store));

        $this->assertSame(['spring — newsletter' => '1'], $rows,
            'An untagged visit was given a campaign row, so ordinary browsing reads as campaign traffic.');
    }

    /**
     * A SOURCE WITH NO CAMPAIGN NAME IS STILL A SOURCE. Half-tagged links are what people
     * actually paste, and dropping them would under-report the very traffic the merchant is
     * asking about.
     */
    public function test_a_tag_with_a_source_but_no_campaign_is_kept_and_labelled(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $this->visit($store, 'https://shop.test/?utm_source=whatsapp');

        $rows = $this->rowsByLabel($this->campaigns($store));

        $this->assertSame('1', $rows['whatsapp'] ?? null,
            'A link tagged with a source but no campaign name was dropped, so half-tagged links '
            . 'are invisible although they are the common case.');
    }

    /**
     * THE SUBSTRING PRE-FILTER MUST NOT DECIDE THE ANSWER. A cheap `LIKE '%utm%'` narrows the
     * read, but what counts as a tag is decided by PARSING the query string: a URL containing the
     * letters `utm` somewhere else is not campaign traffic.
     *
     * THIS IS THE ONLY TEST THAT REACHES THE PHP GUARD, because it is the only one whose URLs
     * survive the pre-filter. Two mutations are pinned here: skipping the parse, and bucketing an
     * unparsed row under a blank label.
     */
    public function test_the_letters_utm_elsewhere_in_a_url_are_not_a_campaign(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $this->visit($store, 'https://shop.test/products/autumn-outmoded-hat');
        $this->visit($store, 'https://shop.test/?sutm_source=nope');

        $breakdown = $this->campaigns($store);

        $this->assertSame([], $breakdown['rows'],
            'A URL merely containing the letters "utm" was reported as a campaign.');

        $this->assertStringContainsString('nothing to break down', $breakdown['hint'],
            'Rows that reached the loop and were rejected produced neither a row nor the '
            . 'empty-panel explanation, so the panel is blank with no reason given.');
    }

    /**
     * THE LABEL IS WRITTEN BY A VISITOR, AND IS THE ONLY ONE ON THIS SCREEN THAT IS.
     *
     * Every other breakdown labels its rows from something the platform derived -- a device name
     * from a detector, a HOST parsed out of a referer. A campaign tag is whatever text someone put
     * in the query string of a link, and it reaches a merchant's admin screen verbatim.
     *
     * Blade's `{{ }}` escapes the HTML, so this is not an injection. What `e()` does NOT
     * neutralise is a Unicode BIDI OVERRIDE (U+202E and its family): it is not markup, it survives
     * escaping intact, and it reorders the glyphs of whatever follows it. A crafted tag can
     * therefore make the rest of a row read as something other than what the data says.
     *
     * `ltr()` wraps the value in U+2066 ... U+2069, an ISOLATE, which confines any direction
     * change to the wrapped text. It is also the ordinary bilingual fix and would be worth doing
     * with no attacker in the picture: a Latin campaign name in an Arabic RTL table reorders
     * around its neighbouring digits without it.
     */
    public function test_the_campaign_label_is_direction_isolated(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $this->visit($store, 'https://shop.test/?utm_source=newsletter&utm_campaign=spring');

        $rows = $this->campaigns($store)['rows'];

        $this->assertCount(1, $rows, 'Premise: the fixture did not produce the single row this is about.');

        $this->assertStringStartsWith("\u{2066}", $rows[0]['label'],
            'The campaign label is not direction-isolated, so a tag carrying a bidi override '
            . 'reorders the text around it on the merchant\'s screen.');
        $this->assertStringEndsWith("\u{2069}", $rows[0]['label']);
    }

    /**
     * STORE-SCOPED. `Visit` is not in `config/tenancy.php`'s `scoped_models`, so no global scope
     * filters it for anyone and the predicate has to be written by hand. A single-tenant fixture
     * cannot see a missing one.
     */
    public function test_another_tenants_campaigns_are_not_shown(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $this->visit($storeB, 'https://other.test/?utm_source=rival&utm_campaign=theirs');
        $this->visit($storeA, 'https://shop.test/?utm_source=newsletter&utm_campaign=ours');

        $rows = $this->rowsByLabel($this->campaigns($storeA));

        $this->assertSame(['ours — newsletter' => '1'], $rows,
            "Another tenant's campaign names were shown on this store's analytics screen.");
    }

    /**
     * THE HINT IS PART OF THE FIGURE, not decoration. (`rows` / `period` / `hint` is the shape
     * `devices()` and `channels()` already return and the Blade loop already renders; a new
     * breakdown inventing its own key would simply not appear on the page.) Two specific claims must be refused in
     * writing, because the layout invites both: that these counts are comparable with the
     * page-views tile, and that a campaign can be credited with revenue.
     */
    public function test_the_breakdown_refuses_attribution_and_says_what_it_counts(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $this->visit($store, 'https://shop.test/?utm_source=newsletter&utm_campaign=spring');

        $hint = $this->campaigns($store)['hint'];

        $this->assertStringContainsString('landing', $hint,
            'The note does not say the figure counts landings, so it reads as visitors or sessions.');
        $this->assertStringContainsString('order', $hint,
            'Nothing on the figure refuses attribution, so a merchant may read it as campaign revenue.');
    }

    /**
     * EMPTY IS A STATED ANSWER, NOT A BLANK. A merchant who has never tagged a link must be told
     * that is why the panel is empty, rather than being left to wonder whether it is broken.
     */
    public function test_a_store_with_no_tagged_traffic_says_so(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $this->visit($store, 'https://shop.test/products');

        $breakdown = $this->campaigns($store);

        $this->assertSame([], $breakdown['rows']);

        // Named content, not merely "non-empty": the panel carries a second sentence telling the
        // merchant how to tag their links, so an `assertNotSame('', ...)` stayed green with the
        // explanation itself deleted. A mutation caught that.
        $this->assertStringContainsString('nothing to break down', $breakdown['hint'],
            'An empty campaign panel does not say WHY it is empty, so a merchant cannot tell '
            . 'whether they have no tagged traffic or the figure is broken.');
        $this->assertStringContainsString('utm_source', $breakdown['hint'],
            'The empty panel does not tell the merchant how to get traffic counted here.');
    }
}
