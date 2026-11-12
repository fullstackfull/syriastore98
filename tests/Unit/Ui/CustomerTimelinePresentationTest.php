<?php

namespace Tests\Unit\Ui;

use App\Support\TimelineMoment;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * P2.5-PKG-L — the six things the owner saw on the customer timeline.
 * ================================================================================================
 *
 * The owner reviewed the screen and named: excessive whitespace, event-card hierarchy, the two
 * date representations, Arabic localisation, Arabic labels and typography, and the 320/390
 * presentation. This file pins the half that is text and data; the layout half is a stylesheet and
 * is measured by capture (docs/development/phase-2.5/evidence/pkg-l-customer-timeline/).
 *
 * THE DATE OBSERVATION WAS TWO DEFECTS WEARING ONE COAT. Measured on the real page before the
 * change, on the registration card:
 *
 *     Register Date:-  2026-10-08 01:24:56      the customer's own registration moment, printed
 *                                               as a raw SQL string inside a green pill
 *     07 Nov 2026 01:24 AM                      the ACTIVITY LOG ROW's created_at, unlabelled
 *
 * Two dates, two formats, no labels, a month apart — and the second was IDENTICAL on all seven
 * cards, because that is when the rows were written. The timeline's own chronology was invisible
 * and the merchant had no way to tell which date meant "when this happened".
 *
 * NEITHER WAS LOCALISED. `created_at->format(...)` is `format()`, not `translatedFormat()`, so the
 * Arabic page printed `07 Nov 2026 01:24 AM` — a Latin month abbreviation and a Latin meridiem
 * inside a right-to-left line.
 *
 * NO STORED VALUE CHANGED. The owner's instruction is explicit that timestamps, amounts and
 * activity-log data must not be altered to improve appearance. Everything here is formatting and
 * labelling; `TimelineMoment` parses and prints, and the view passes it the same columns as before.
 */
class CustomerTimelinePresentationTest extends TestCase
{
    private const VIEW = 'resources/views/customer/timeline.blade.php';
    private const SHEET = 'public/css/store-syria-ds.css';

    // =============================================================================================
    //  the dates
    // =============================================================================================

    public function test_a_moment_is_localised_and_uses_the_levantine_month_names(): void
    {
        App::setLocale('en');
        $this->assertSame('08 Oct 2026, 1:24 AM', TimelineMoment::format('2026-10-08 01:24:56'));

        App::setLocale('ar');
        $arabic = TimelineMoment::format('2026-10-08 01:24:56');

        /*
         * `تشرين الأول`, NOT `أكتوبر`. Carbon's generic `ar` gives the transliterated Gregorian
         * names; `ar_SY` gives the ones a Syrian merchant writes. That choice is the only reason
         * `TimelineMoment::locale()` exists, so it is asserted rather than left to the helper.
         */
        $this->assertStringContainsString('تشرين الأول', $arabic);
        $this->assertStringNotContainsString('أكتوبر', $arabic);
        $this->assertStringContainsString('2026', $arabic);
        $this->assertStringContainsString('ص', $arabic, 'the meridiem is still Latin AM/PM');
        $this->assertStringNotContainsString('AM', $arabic);
    }

    public function test_the_locale_helper_asks_for_the_syrian_variant_only_for_arabic(): void
    {
        App::setLocale('ar');
        $this->assertSame('ar_SY', TimelineMoment::locale());

        App::setLocale('en');
        $this->assertSame('en', TimelineMoment::locale());
    }

    /** A date column that is empty, zeroed or unparseable prints NOTHING, never "Invalid date". */
    public function test_an_absent_or_broken_date_formats_to_an_empty_string(): void
    {
        App::setLocale('en');

        foreach (['', '   ', '0000-00-00 00:00:00', '0000-00-00', 'not a date', null, [], new \stdClass()] as $value) {
            $this->assertSame('', TimelineMoment::format($value),
                'an unusable date value produced output: ' . var_export($value, true));
        }
    }

    public function test_a_carbon_instance_is_formatted_without_being_reparsed(): void
    {
        App::setLocale('en');
        $moment = \Carbon\Carbon::parse('2026-11-05 13:07:00');

        $this->assertSame('05 Nov 2026, 1:07 PM', TimelineMoment::format($moment));
        $this->assertSame('05 Nov 2026', TimelineMoment::formatDate($moment));

        // and formatting must not mutate the instance's own locale for later callers
        $this->assertSame('2026-11-05 13:07:00', $moment->format('Y-m-d H:i:s'));
    }

    // =============================================================================================
    //  the view
    // =============================================================================================

    public function test_the_view_no_longer_prints_an_unlocalised_timestamp(): void
    {
        $this->assertStringNotContainsString("->format('d M Y h:i A')", $this->viewSource(),
            'a card still prints its timestamp with format() rather than a localised helper, so '
            . 'the Arabic page shows a Latin month and meridiem.');
    }

    public function test_every_card_labels_the_timestamp_it_prints(): void
    {
        $view = $this->viewSource();

        $recorded = substr_count($view, "__('Recorded')");
        $moments = substr_count($view, 'TimelineMoment::format($value->created_at)');

        $this->assertSame(7, $recorded,
            "expected all seven event cards to label their log timestamp, found {$recorded}");
        $this->assertSame(7, $moments,
            "expected all seven cards to format that timestamp through the helper, found {$moments}");
    }

    /**
     * `First Name:-` was the house style on this screen and on no other. The colon-hyphen is not
     * punctuation in either language, and in Arabic it rendered after a right-to-left label where
     * the hyphen reads as a stray mark.
     */
    public function test_no_label_carries_the_colon_hyphen(): void
    {
        $this->assertSame(0, substr_count($this->viewSource(), ":-')"),
            'a label still ends in `:-`');
    }

    /** The two in-card dates stopped being pills painted in the event's colour. */
    public function test_the_in_card_dates_are_values_and_not_event_coloured_pills(): void
    {
        $view = $this->viewSource();

        foreach (['register-timeline', 'last-login-timeline'] as $pill) {
            $this->assertStringNotContainsString($pill, $view,
                "a date is still painted with `{$pill}`, which makes a timestamp look like a status badge");
        }

        $this->assertSame(3, substr_count($view, 'ss-timeline__value'),
            'the registration, last-login and order dates should each render as a plain value');
    }

    public function test_the_view_carries_the_design_system_hook(): void
    {
        $this->assertStringContainsString('my-custom-timeline ss-timeline', $this->viewSource(),
            'without `ss-timeline` the redesign in store-syria-ds.css matches nothing, and the '
            . 'scope test forbids confining it any other way.');
    }

    // =============================================================================================
    //  the layout
    // =============================================================================================

    /**
     * The alternating two-column rail is what the owner saw as whitespace: `max-width: 50%` plus
     * `left: 50%` leaves the other half of the canvas empty beside every card.
     */
    public function test_the_rail_is_one_column_and_both_sides_resolve_to_it(): void
    {
        $sheet = $this->sheet();

        $this->assertMatchesRegularExpression(
            '/\.ss-timeline \.tl-container,\s*\n\s*\.ss-timeline \.tl-container\.left,\s*\n\s*\.ss-timeline \.tl-container\.right\s*\{[^}]*max-width:\s*100%/',
            $sheet,
            'the containers are no longer collapsed to one full-width column');
    }

    /**
     * The vendored phone block pins `text-align: left` on three selectors, which puts the ARABIC
     * cards against the direction of the page at 767px and below. The override has to be `start`.
     */
    public function test_the_phone_rules_use_logical_alignment(): void
    {
        $sheet = $this->sheet();

        $this->assertMatchesRegularExpression(
            '/\.ss-timeline \.tl-box\s*\{\s*\n\s*text-align:\s*start/',
            $sheet,
            'the phone override does not realign the card to the reading direction');

        $this->assertDoesNotMatchRegularExpression(
            '/\.ss-timeline[^{}]*\{[^}]*text-align:\s*(left|right)\s*;/',
            $sheet,
            'a timeline rule uses a physical alignment, which is wrong in one of the two directions');
    }

    /** One value chip, so a product name is not five colours down one page. */
    public function test_every_event_pill_resolves_to_one_neutral_value_chip(): void
    {
        $sheet = $this->sheet();

        foreach ([
            'register-timeline', 'last-login-timeline', 'wishlist-timeline', 'dlt-wishlist-timeline',
            'add-cart-timeline', 'dlt-cart-timeline', 'order-timeline',
        ] as $variant) {
            $this->assertStringContainsString('.ss-timeline .tl-box .end-date.' . $variant, $sheet,
                "`{$variant}` still paints its own colour inside the timeline, so the same value "
                . 'changes colour depending on which row it is on');
        }
    }

    private function viewSource(): string
    {
        return $this->read(self::VIEW);
    }

    private function sheet(): string
    {
        return $this->read(self::SHEET);
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
