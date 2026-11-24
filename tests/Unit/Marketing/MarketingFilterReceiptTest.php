<?php

namespace Tests\Unit\Marketing;

use App\Services\TableFilterMemory;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * EVERY MARKETING FILTER MUST PRODUCE A RECEIPT. MKT-002.
 * =================================================================================================
 *
 * `TableFilterMemory::label()` is a HARD ALLOWLIST. A filter name absent from its `$heading` map
 * returns null, `describe()` then returns `[]`, and the filter bars render their Clear button on
 * `describe(...) !== []`. So an unlisted name does not merely lose its wording -- it takes the way
 * out with it. That method's own docblock records the consequence, found by independent review and
 * by no test:
 *
 *     "a merchant who filtered to Shipped got a sticky filter, no receipt, and no way out --
 *      exactly the 'my orders are gone' screen the restore is only safe if it prevents."
 *
 * AND THERE ARE TWO GATES, NOT ONE. An unmapped VALUE of a listed name dies at the second
 * (`isset($map[$name][$value])`), with the same result. So adding a Marketing screen to the
 * vocabulary is not finished until every name AND every legal value of it is mapped for that
 * screen.
 *
 * THIS TEST IS THE GUARD, and it is written before the vocabulary exists so it fails first. It
 * enumerates the legal values from the SAME place the implementation must read them -- the create
 * migrations and the first-party literals -- rather than from a second list that could drift:
 *
 *   coupon      ss_status 1|0 . ss_type percentage|fixed product discount|flat . ss_expiry
 *               expired|current . ss_from/ss_to
 *   newsletter  ss_source customer|guest . ss_from/ss_to
 *   flash-sale  ss_status 1|0 . ss_window running|upcoming|ended . ss_type percentage|flat
 *               . ss_from/ss_to
 *   wishlist    ss_status 1|0 . ss_from/ss_to
 *   abandon-cart ss_items one|several . ss_from/ss_to
 *
 * `ss_from` and `ss_to` need no value map -- `label()` echoes a date back deliberately -- so they
 * are asserted to produce a receipt and not to be in any value map.
 */
class MarketingFilterReceiptTest extends TestCase
{
    /** @return array<string, array<string, list<string>>> */
    private function expected(): array
    {
        return [
            'coupon' => [
                'ss_status' => ['1', '0'],
                'ss_type' => ['percentage', 'fixed product discount', 'flat'],
                'ss_expiry' => ['expired', 'current'],
            ],
            'newsletter' => [
                'ss_source' => ['customer', 'guest'],
            ],
            'flash-sale' => [
                'ss_status' => ['1', '0'],
                'ss_window' => ['running', 'upcoming', 'ended'],
                'ss_type' => ['percentage', 'flat'],
            ],
            'wishlist' => [
                'ss_status' => ['1', '0'],
            ],
            'abandon-cart' => [
                'ss_items' => ['one', 'several'],
            ],
        ];
    }

    public function test_every_marketing_screen_is_in_the_vocabulary(): void
    {
        foreach (array_keys($this->expected()) as $screen) {
            $this->assertNotSame(
                [],
                TableFilterMemory::vocabularyFor($screen),
                "the {$screen} screen has no filter vocabulary, so TableFilterMemory ignores every parameter it sets"
            );
        }
    }

    public function test_every_marketing_filter_value_produces_a_receipt(): void
    {
        $missing = [];

        foreach ($this->expected() as $screen => $filters) {
            foreach ($filters as $name => $values) {
                foreach ($values as $value) {
                    $described = TableFilterMemory::describe(
                        Request::create('/', 'GET', [$name => $value]),
                        $screen
                    );

                    if ($described === []) {
                        $missing[] = "{$screen}?{$name}={$value}";
                    }
                }
            }
        }

        $this->assertSame([], $missing, implode("\n", array_merge(
            ['These Marketing filters narrow the list and say NOTHING, so the Clear button does not render either:'],
            array_map(static fn (string $m): string => '  ' . $m, $missing),
        )));
    }

    /**
     * THE RECEIPT MUST BE THE SCREEN'S OWN WORDS, NOT THE COLUMN'S.
     *
     * ADDED BECAUSE A MUTATION SURVIVED. Deleting the coupon branch from `valueLabels()` left every
     * assertion above green: `ss_status` still reads "Active" from the default map, and `ss_type`
     * is then absent from the map entirely, so `label()` falls through to its DATE branch and
     * echoes the merchant's own input back. That is correct for a date and wrong for a vocabulary:
     * `ss_type=fixed product discount` would render as the raw column literal -- untranslated
     * English on an Arabic page, naming a storage value no merchant chose that wording for.
     *
     * So the discriminator is not "did it say something" but "did it say the LABEL". Only values
     * whose stored form differs from their wording can carry this check, which is why `ss_status`
     * is absent here: 1/0 has no English literal to leak.
     */
    public function test_a_receipt_uses_the_screens_own_wording_and_not_the_stored_literal(): void
    {
        $cases = [
            ['coupon', 'ss_type', 'fixed product discount', 'Fixed amount off a product'],
            ['coupon', 'ss_type', 'flat', 'Flat amount off the basket'],
            ['coupon', 'ss_expiry', 'current', 'Still valid'],
            ['newsletter', 'ss_source', 'guest', 'An e-mail only'],
            ['flash-sale', 'ss_window', 'upcoming', 'Not started yet'],
            ['flash-sale', 'ss_type', 'flat', 'Flat amount'],
            ['abandon-cart', 'ss_items', 'several', 'More than one item'],
        ];

        foreach ($cases as [$screen, $name, $value, $wording]) {
            $described = TableFilterMemory::describe(Request::create('/', 'GET', [$name => $value]), $screen);

            $this->assertSame(
                [__($heading = $this->headingFor($name)) . ': ' . __($wording)],
                $described,
                "{$screen}?{$name}={$value} must read \"{$heading}: {$wording}\", not the stored literal"
            );
        }
    }

    private function headingFor(string $name): string
    {
        return [
            'ss_type' => 'Type', 'ss_expiry' => 'Expiry',
            'ss_window' => 'When', 'ss_items' => 'Items', 'ss_source' => 'Source',
        ][$name];
    }

    public function test_a_date_filter_produces_a_receipt_on_every_marketing_screen(): void
    {
        foreach (array_keys($this->expected()) as $screen) {
            foreach (['ss_from', 'ss_to'] as $name) {
                $this->assertNotSame(
                    [],
                    TableFilterMemory::describe(Request::create('/', 'GET', [$name => '2026-11-01']), $screen),
                    "{$screen} drops its {$name} receipt"
                );
            }
        }
    }

    public function test_a_value_outside_the_vocabulary_still_says_nothing(): void
    {
        /* The silence is RESERVED for values that really narrow nothing, which is the rule
           `label()` states. A receipt for `ss_type=banana` would describe a filter not in force. */
        foreach (['coupon' => 'ss_type', 'flash-sale' => 'ss_window', 'abandon-cart' => 'ss_items'] as $screen => $name) {
            $this->assertSame(
                [],
                TableFilterMemory::describe(Request::create('/', 'GET', [$name => 'banana']), $screen),
                "{$screen} invents a receipt for a {$name} value that narrows nothing"
            );
        }
    }

    public function test_no_marketing_screen_borrows_another_screens_value_words(): void
    {
        /* The defect this file's header quotes was the PRODUCT status map applied to the ORDER
           screen: `/order?ss_status=1` listed DELIVERED orders under the words "Status: Active".
           Marketing's `status` and `is_active` columns really are 1/0 active/inactive, so "Active"
           is correct here -- but it must be correct BY DECLARATION for these screens, not by
           falling through to product's map. A screen whose own map is missing would still read
           "Active" today, so this asserts the declaration exists rather than the wording. */
        foreach (['coupon', 'flash-sale', 'wishlist'] as $screen) {
            $this->assertContains(
                'ss_status',
                TableFilterMemory::vocabularyFor($screen),
                "{$screen} does not declare ss_status, so its wording is whatever another screen's map happens to say"
            );
        }
    }
}
