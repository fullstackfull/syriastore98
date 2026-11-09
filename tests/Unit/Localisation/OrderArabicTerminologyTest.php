<?php

namespace Tests\Unit\Localisation;

use PHPUnit\Framework\TestCase;

/**
 * P2.5-ORD-1 / ORD-8 / ORD-2 — three Arabic strings on the order screens that are not Arabic.
 * =================================================================================================
 *
 * These are the three translation findings the ORDERS audit confirmed (115 raised → 103 refuted →
 * 12 confirmed). They are value changes in `resources/lang/ar.json`, so no coverage test could ever
 * have caught them: every key resolved, and resolving is all a coverage test can measure. Only
 * reading the Arabic finds them.
 *
 * ORD-1 / ORD-8 — `Walk-in-customer` was `المشي في العميل`.
 *   `المشي` is the gerund "walking" (the physical act) and `في العميل` is "inside the customer".
 *   The English compound was split on its hyphens and each piece translated on its own, so the
 *   result names nobody. It is the buyer of every till / counter order, printed on the order list
 *   (app/DataTables/OrderDataTable.php:96) and on the order card (resources/views/order/
 *   grid.blade.php:155).
 *
 *   AND THE AUDIT NAMED ONLY HALF OF IT. There are TWO keys for one idea:
 *   `Walk-in-customer` (hyphenated) on the list and the card, and `Walk-in Customer` (spaced) on
 *   the BOARD card (resources/views/order/partials/kanban-card.blade.php:37) and in
 *   `Order::order_detail()` (app/Models/Order.php:534-535), which is what the order detail page and
 *   the API answer with. The second was `عميل متجول` — "an itinerant / travelling customer", a
 *   pedlar: wrong in a different way and on a third surface. Repairing one key would have left the
 *   three order views disagreeing about who the buyer is, which is why both are asserted here.
 *
 *   THE TERM CHOSEN IS `زبون عابر` — "a passing customer", which is what a Syrian shop calls
 *   someone who walks in off the street. `زبون` (not `عميل`) is the register P2.5-PKG-L settled the
 *   customer timeline on. `عميل مباشر` was rejected: ORD-1 warns it reads as a direct-sales
 *   CHANNEL rather than a person.
 *
 * ORD-2 — `Action` was `العمل`.
 *   `العمل` is the noun for work or employment — the dictionary's first hit for "action" and the
 *   wrong sense entirely. `app/Helper/helper.php` puts `__('Action')` on the last column of EVERY
 *   admin table, so an Arabic merchant read a column headed "Work" over the edit and delete
 *   buttons on all 67 of them. `الإجراءات` is the word, and the file already used exactly that for
 *   the plural key `Actions`, so the two now agree instead of contradicting each other one key
 *   apart.
 *
 * WHY A UNIT TEST ON THE JSON AND NOT A RENDERED PAGE. The defect is the string, and the string is
 * shared by three views, one DataTable, one helper used by 67 tables and one model method. A test
 * per surface would assert the same byte six times and still miss the seventh caller; the
 * surfaces' own tests already prove they render `__()` of these keys.
 */
class OrderArabicTerminologyTest extends TestCase
{
    /** The Arabic a reader must never see again on these screens. */
    private const REJECTED_WALK_IN = [
        'المشي' => 'the gerund "walking" — the English compound translated piece by piece',
        'متجول' => 'itinerant / travelling — a pedlar, not someone who walked into the shop',
    ];

    /** @return array<string,string> */
    private function arabic(): array
    {
        $path = dirname(__DIR__, 3) . '/resources/lang/ar.json';

        $this->assertFileExists($path, 'premise: the Arabic translation file');

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded,
            'ar.json did not decode: ' . json_last_error_msg()
            . ' — a malformed file makes every key below silently absent');

        return $decoded;
    }

    public function test_premise_every_key_this_test_is_about_exists(): void
    {
        /* If a key were absent, `__()` would echo the English and each assertion below would be
         * asserting about a string the application never looks up. Both spellings are live callers
         * in the repository, which is why both must be present and not merely one of them. */
        $ar = $this->arabic();

        foreach (['Walk-in-customer', 'Walk-in Customer', 'Action', 'Actions'] as $key) {
            $this->assertArrayHasKey($key, $ar, 'ar.json lost the key "' . $key . '"');
            $this->assertNotSame('', trim((string) $ar[$key]), '"' . $key . '" is empty');
        }
    }

    public function test_the_counter_customer_is_named_in_arabic_on_every_order_surface(): void
    {
        $ar = $this->arabic();

        foreach (['Walk-in-customer', 'Walk-in Customer'] as $key) {
            $value = (string) $ar[$key];

            foreach (self::REJECTED_WALK_IN as $fragment => $why) {
                $this->assertStringNotContainsString($fragment, $value,
                    '"' . $key . '" still reads "' . $value . '", which contains "' . $fragment
                    . '" — ' . $why);
            }

            $this->assertStringContainsString('زبون', $value,
                '"' . $key . '" reads "' . $value . '" and does not name a customer at all. The '
                . 'register the customer area settled on is زبون.');
        }
    }

    public function test_the_two_spellings_of_the_same_idea_agree(): void
    {
        /* They render on three order screens — list, card and board — plus the detail page and the
         * API. One key repaired and the other left is how a merchant comes to see two different
         * names for the same buyer on two screens of the same product. */
        $ar = $this->arabic();

        $this->assertSame($ar['Walk-in Customer'], $ar['Walk-in-customer'],
            'the hyphenated key renders on /order and /order-grid, the spaced one on the board and '
            . 'through Order::order_detail(); they are the same person and must read the same: '
            . '"' . $ar['Walk-in-customer'] . '" vs "' . $ar['Walk-in Customer'] . '"');
    }

    public function test_the_actions_column_is_not_headed_the_word_for_employment(): void
    {
        $ar = $this->arabic();

        $this->assertNotSame('العمل', (string) $ar['Action'],
            '"Action" is still العمل — "the work / the labour". It heads the row-actions column of '
            . 'every admin table.');

        $this->assertStringContainsString('إجراء', (string) $ar['Action'],
            '"Action" reads "' . $ar['Action'] . '", which is not a form of إجراء');
    }

    public function test_the_singular_and_plural_action_keys_agree_on_the_root(): void
    {
        /* `Actions` was already `الإجراءات` while `Action` was `العمل`. Two keys one apart in the
         * same file giving two unrelated words for the same concept is how the wrong one survives
         * being read. */
        $ar = $this->arabic();

        foreach (['Action', 'Actions'] as $key) {
            $this->assertStringContainsString('إجراء', (string) $ar[$key],
                '"' . $key . '" reads "' . $ar[$key] . '"');
        }
    }

    public function test_no_order_surface_translation_is_left_as_english(): void
    {
        /* A translation that is byte-identical to its key is an untranslated string wearing a
         * translation's clothes — it passes a coverage test and shows English to an Arabic
         * merchant. Asserted for exactly the keys this slice touches. */
        $ar = $this->arabic();

        foreach (['Walk-in-customer', 'Walk-in Customer', 'Action'] as $key) {
            $this->assertNotSame($key, (string) $ar[$key], '"' . $key . '" is still English');
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', (string) $ar[$key],
                '"' . $key . '" contains no Arabic letter at all: "' . $ar[$key] . '"');
        }
    }
}
