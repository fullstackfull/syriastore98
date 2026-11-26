<?php

namespace Tests\Feature\Analytics;

use App\Models\Order;
use App\Support\Metrics\MerchantAnalyticsFigures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * ANA-004 / GAP-227(a)(1) — ONE ORDER SPELLED `POS` RELABELLED EVERY POINT-OF-SALE ORDER AS
 * UNRECOGNISED, AND ONLY THE PRODUCTION ENGINE COULD SHOW IT.
 * =================================================================================================
 *
 * FOUND BY RUNNING THE SCREEN'S `GROUP BY` READS AGAINST A REAL MariaDB, which is the MySQL
 * harness `GAP-154` and `GAP-171` both asked for:
 * `tests/Support/Security/bin/verify-analytics-breakdowns-on-mysql.php`.
 *
 * MEASURED on MariaDB 10.11 with `ONLY_FULL_GROUP_BY` on, three orders whose `order_source` reads
 * `POS`, `pos`, `pos`:
 *
 *     engine:  [{"bucket":"POS","total":3}]            <- ONE case-insensitive group,
 *                                                         represented by the row it reached first
 *     screen:  "POS (not a source this version knows)" x3
 *
 * So a single row carrying a spelling the writers never produce — a restored dump, a hand edit, a
 * future writer — captures the whole group, and all three point-of-sale orders are reported to the
 * merchant as a source the product does not recognise. The count stays right and the LABEL goes
 * wrong, which is the harder failure to notice: the breakdown still sums to the order total.
 *
 * WHY THE SUITE COULD NOT SEE IT, AND WHY IT CAN NOW. SQLite's collation is BINARY, so `pos` and
 * `POS` are TWO groups there and the mislabel only touches the one odd row. The FIX, however, is
 * observable on both engines: folding the bucket key before the label lookup merges SQLite's two
 * groups into one correctly-named row. That is what this test asserts, and the harness asserts the
 * same figure on the engine where the groups arrive already merged.
 *
 * THE FOLD IS NOT A NEW RULE AND INVENTS NOTHING. `orders.order_source` has a closed vocabulary of
 * three values, all lower-case constants on `Order`, and `Order::orderSourceLabel()` is a `match`
 * on exactly those. `sources()` already folds this bucket on `trim()` for the same reason — a
 * leading or trailing space must not read as a second channel — and the payment-method chart on the
 * Top Sales report already groups case-insensitively with the same argument recorded in its own
 * comment. This extends the existing normalisation by one function call.
 *
 * AN UNRECOGNISED VALUE IS STILL SHOWN VERBATIM, which is a promise `sources()` makes in writing
 * ("shown with the stored value rather than left out of the count"). The fold applies to the
 * LOOKUP, not to the display: the bucket keeps the first spelling it was seen under, so a genuinely
 * unknown `TikTok` still reads `TikTok` and not `tiktok`.
 */
class OrderSourceBreakdownFoldsSpellingsTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** @return array{0: \App\Models\Store, 1: array<string, mixed>} */
    private function storeWithSources(array $spellings): array
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);

        foreach ($spellings as $spelling) {
            $order = $this->makeOrder($store, ['final_price' => 10.00]);

            /* RAW, because `order_source` is on `$fillable` but the fixture helper does not pass
               it; and flushed because `Order` is `Cachable` and the suite runs under
               `CACHE_STORE=array`, where the breakdown's read could serve the pre-write row. */
            DB::table('orders')->where('id', $order->id)->update(['order_source' => $spelling]);
        }

        (new Order)->flushCache();

        return [$store, MerchantAnalyticsFigures::forStore($store->id, 'USD')['sources']];
    }

    /** @return array<string, string> label => count */
    private function rows(array $block): array
    {
        $out = [];

        foreach ($block['rows'] as $row) {
            /* The counts are wrapped in U+2066/U+2069 so a number lays out left-to-right inside an
               Arabic paragraph. Compare the digits, not the isolation marks. */
            $out[$row['label']] = preg_replace('/[^0-9,]/u', '', $row['count']);
        }

        return $out;
    }

    public function test_two_spellings_of_one_source_are_one_correctly_named_row(): void
    {
        [, $block] = $this->storeWithSources(['POS', 'pos', 'pos']);

        $rows = $this->rows($block);

        $this->assertSame([__('Point of sale') => '3'], $rows,
            'The channel breakdown reported ' . json_encode($rows, JSON_UNESCAPED_UNICODE) . '. On '
            . 'the production engine `GROUP BY order_source` is case-insensitive, so ONE row '
            . 'spelled `POS` carries the whole group and every point-of-sale order is relabelled '
            . '"not a source this version knows" -- measured on MariaDB 10.11 by '
            . 'verify-analytics-breakdowns-on-mysql.php.');
    }

    public function test_the_fold_survives_leading_and_trailing_space_as_well_as_case(): void
    {
        [, $block] = $this->storeWithSources([' POS ', 'pos', "\tpos"]);

        $this->assertSame([__('Point of sale') => '3'], $this->rows($block),
            'A spelling differing only in whitespace and case must not open a second channel. '
            . 'MySQL is PAD SPACE and folds a TRAILING space already; a LEADING one is a different '
            . 'value on both engines, which is why the trim is PHP\'s job and not the engine\'s.');
    }

    /**
     * A VALUE THE VOCABULARY DOES NOT KNOW IS STILL SHOWN, AND STILL SHOWN AS IT WAS STORED.
     *
     * The fold must not quietly become a mechanism for hiding or rewriting an unknown source:
     * `sources()` promises such a row is "shown with the stored value rather than left out of the
     * count", and a lower-cased display would break that promise in a way nothing else would
     * catch.
     */
    public function test_an_unrecognised_source_keeps_its_stored_spelling_and_is_marked(): void
    {
        [, $block] = $this->storeWithSources(['TikTok', 'pos']);

        $rows = $this->rows($block);

        $this->assertArrayHasKey(__(':value (not a source this version knows)', ['value' => 'TikTok']), $rows,
            'An unrecognised source is missing, renamed or lower-cased: '
            . json_encode(array_keys($rows), JSON_UNESCAPED_UNICODE));

        $this->assertArrayHasKey(__('Point of sale'), $rows,
            'the recognised row went missing while the unknown one was handled');
    }

    /**
     * TWO SPELLINGS OF AN UNRECOGNISED SOURCE ARE ONE ROW, under the first spelling seen.
     *
     * The engine would hand MySQL's representative spelling and SQLite two rows; the figure a
     * merchant reads must not depend on which. "First seen" is deterministic here because
     * `groupedVisits`'s sibling query returns the groups in a defined order and PHP's
     * insertion-ordered array keeps it.
     */
    public function test_two_spellings_of_an_unknown_source_are_one_row(): void
    {
        [, $block] = $this->storeWithSources(['TikTok', 'tiktok']);

        $rows = $this->rows($block);

        $this->assertCount(1, $rows,
            'one unknown source produced two rows: ' . json_encode(array_keys($rows), JSON_UNESCAPED_UNICODE));

        $this->assertSame('2', array_values($rows)[0],
            'the two spellings were not counted together');
    }

    /** The absence bucket is untouched by the fold: NULL and '' are still one row, named. */
    public function test_an_unrecorded_source_is_still_one_absence_row(): void
    {
        [, $block] = $this->storeWithSources([null, '', 'pos']);

        $rows = $this->rows($block);

        $this->assertSame('2', $rows[__('Source not recorded (placed before this was tracked)')] ?? null,
            'NULL and the empty string are two SQL groups and must fold into ONE absence row: '
            . json_encode($rows, JSON_UNESCAPED_UNICODE));
    }
}
