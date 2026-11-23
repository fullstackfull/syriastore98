<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Tests\Support\StripsSourceComments;

/**
 * P5-R7 ITEM 3 -- THE TOP-5 CARDS COUNT CANCELLED AND RETURNED ORDERS AND NEVER SAID SO.
 * =================================================================================================
 *
 * `ReportController::top_product()` filters on `store_id` ALONE. There is no `delivered_status`
 * and no `payment_status` predicate in the handler, in `topSellingProducts()`, or in either of the
 * two category/brand join queries -- measured, not assumed. So all four panels count an order that
 * was cancelled, and one that was returned, exactly as they count a completed one.
 *
 * The four notes described the METRIC accurately ("the number of distinct orders containing one of
 * their products") and were silent about the INCLUSION RULE, which is the half a merchant acts on
 * when they read a ranking: a product that sells well and is returned constantly outranks one that
 * sells less and is kept.
 *
 * THIS IS A SOURCE PIN AND NOT A RENDER, for a reason that is specific to this screen. The category
 * and brand panels are built with `SUBSTRING_INDEX(...)` and a generated `numbers` table -- MySQL
 * constructs SQLite does not implement -- so driving `/top-5-reports` under this suite answers HTTP
 * 500 before any note is composed. That is CLAUDE.md's standing warning about `ReportController`,
 * and it is why executable evidence for this screen lives in
 * `tests/Support/Security/bin/verify-top-five-reports-on-mysql.php`.
 *
 * IT PINS BOTH DIRECTIONS, which is the point. If someone later adds a status predicate to the
 * handler, the disclosure becomes FALSE, and the second case below fails so that they come and
 * correct the sentence rather than leaving a note that overstates what is excluded.
 */
class TopFiveInclusionDisclosureTest extends TestCase
{
    use StripsSourceComments;

    private const VIEW = 'resources/views/reports/top_5_reports.blade.php';

    private const CONTROLLER = 'app/Http/Controllers/ReportController.php';

    /**
     * The four notes, verbatim, as the view states the metric. Listed rather than discovered so
     * that a FIFTH panel added later is not silently exempt: the count is asserted too.
     */
    private const NOTES = [
        'The five products appearing in the most orders. Counts orders, not quantity or value.',
        'Categories by the number of distinct orders containing one of their products.',
        'Brands by the number of distinct orders containing one of their products.',
        'The five methods used on the most orders. Spellings of the same method are counted together.',
    ];

    public function test_every_top_five_card_note_carries_the_inclusion_disclosure(): void
    {
        $source = $this->sourceWithoutComments(self::VIEW);

        foreach (self::NOTES as $note) {
            $expected = ":note=\"__('" . str_replace("'", "\\'", $note) . "') . ' ' . \$ssTopFiveInclusion\"";

            $this->assertStringContainsString($expected, $source,
                "The card whose note begins \"" . mb_substr($note, 0, 40) . "...\" states its metric "
                . 'but not that cancelled and returned orders are counted in it.');
        }

        $this->assertSame(
            count(self::NOTES),
            substr_count($source, '$ssTopFiveInclusion') - 1,
            'A panel was added or removed on this screen without its inclusion disclosure. The '
            . 'count excludes the one assignment of the variable itself.'
        );
    }

    public function test_the_disclosure_is_a_translated_sentence_and_not_concatenated_english(): void
    {
        $source = $this->sourceWithoutComments(self::VIEW);

        $this->assertStringContainsString(
            "\$ssTopFiveInclusion = __('Orders that were later cancelled or returned are counted here.');",
            $source,
            'The disclosure is not resolved through __(), so an Arabic merchant reads an English '
            . 'sentence appended to an Arabic note -- the exact bilingual defect P5-R7-B shipped.'
        );

        foreach (['resources/lang/en.json', 'resources/lang/ar.json'] as $catalogue) {
            $entries = json_decode(
                (string) file_get_contents(dirname(__DIR__, 3) . '/' . $catalogue),
                true
            );

            $this->assertIsArray($entries, "{$catalogue} is not valid JSON.");
            $this->assertArrayHasKey(
                'Orders that were later cancelled or returned are counted here.',
                $entries,
                "{$catalogue} has no entry for the disclosure, so it cannot resolve in that locale."
            );
        }
    }

    /**
     * THE PREMISE THE SENTENCE RESTS ON. A note claiming cancelled orders are counted is only
     * honest while the handler actually counts them.
     */
    public function test_the_handler_still_has_no_order_status_predicate_to_contradict_the_note(): void
    {
        $source = $this->sourceWithoutComments(self::CONTROLLER);

        $start = strpos($source, 'public function top_product(');
        $this->assertNotFalse($start, 'top_product() is gone; this pin no longer describes anything.');

        $end = strpos($source, 'public function ', $start + 10);
        $body = substr($source, $start, ($end === false ? strlen($source) : $end) - $start);

        foreach (['delivered_status', 'payment_status'] as $column) {
            $this->assertStringNotContainsString($column, $body,
                "top_product() now filters on `{$column}`, so the four card notes saying cancelled "
                . 'and returned orders are counted are no longer true. Correct the notes, then '
                . 'this assertion.');
        }
    }
}
