<?php

namespace Tests\Feature\Merchant;

use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * P2-06-11 — every list has an empty state that says what to do next.
 * =================================================================================================
 *
 * THE GAP WAS MEASURED IN A BROWSER. `buildDataTable()` builds all 67 merchant tables and already
 * passes `->language([...])` — paginate, lengthMenu, searchPlaceholder, search, info — but it
 * omits the only two keys that matter when a list is empty. So every table fell back to
 * DataTables' own defaults, and reading them off a live page gave the same answer in both
 * languages:
 *
 *   EN  emptyTable "No data available in table"   zeroRecords "No matching records found"
 *   AR  emptyTable "No data available in table"   zeroRecords "No matching records found"
 *
 * Two defects in one line: the message says only that there is nothing, and on a bilingual admin
 * it is not translated at all.
 *
 * AND THE TWO CASES ARE NOT THE SAME MESSAGE, which is the design point of this row rather than a
 * detail. `emptyTable` means the table holds nothing; `zeroRecords` means a search or filter
 * matched nothing. Telling a merchant "add your first product" when they have 900 products and a
 * filter applied is worse than saying nothing: it suggests their catalogue is gone. So the
 * filtered case points at the way OUT — clear the filters — and never at creating a record.
 * `test_the_filtered_message_never_suggests_creating_a_record` is the guard on that.
 */
class EmptyStateTest extends TestCase
{
    /** PREMISE: the helper exists and the two keys are distinct. */
    public function test_premise_the_helper_returns_both_keys(): void
    {
        $state = dataTableEmptyState('product-table');

        $this->assertArrayHasKey('emptyTable', $state);
        $this->assertArrayHasKey('zeroRecords', $state);
        $this->assertNotSame($state['emptyTable'], $state['zeroRecords'],
            'the empty table and the filtered-empty cases share one message, so a merchant with '
            . 'a filter applied is told their list is empty');
    }

    /** Neither is DataTables' English default. */
    public function test_neither_message_is_the_datatables_default(): void
    {
        foreach (['product-table', 'order-table', 'unknown-table'] as $table) {
            $state = dataTableEmptyState($table);

            $this->assertStringNotContainsString('No data available in table', $state['emptyTable']);
            $this->assertStringNotContainsString('No matching records found', $state['zeroRecords']);
        }
    }

    /** It says what to do next, not only that there is nothing. */
    public function test_the_empty_message_says_what_to_do_next(): void
    {
        foreach (['product-table', 'order-table', 'customer-table', 'unknown-table'] as $table) {
            $state = dataTableEmptyState($table);

            // More than a bare label: there is guidance after the headline.
            $this->assertGreaterThan(30, mb_strlen(strip_tags($state['emptyTable'])),
                $table . ' has an empty state that states the absence without saying what to do');
        }
    }

    /**
     * THE ONE THAT MATTERS MOST. A filtered-empty list must point at the way out, never at
     * creating a record — a merchant with 900 products and a filter applied who is told to "add
     * your first product" will reasonably conclude their catalogue has been lost.
     */
    public function test_the_filtered_message_never_suggests_creating_a_record(): void
    {
        foreach (['product-table', 'order-table', 'customer-table', 'unknown-table'] as $table) {
            $zero = mb_strtolower(strip_tags(dataTableEmptyState($table)['zeroRecords']));

            foreach (['add your first', 'create your first', 'get started by'] as $phrase) {
                $this->assertStringNotContainsString($phrase, $zero,
                    $table . ' tells a merchant with a filter applied to create their first '
                    . 'record, which reads as "your data is gone"');
            }

            // And it names the way out.
            $this->assertMatchesRegularExpression('~clear|filter|search~i', $zero,
                $table . ' does not tell a filtered-empty merchant how to see everything again');
        }
    }

    /**
     * TRANSLATED, asserted by COMPARING the two locales rather than by looking for `__()` in the
     * source. `__()` falls back to the key, so a missing Arabic entry produces English text from a
     * correctly-written call — which is exactly the failure a source check cannot see.
     */
    public function test_the_messages_are_translated(): void
    {
        App::setLocale('en');
        $en = dataTableEmptyState('product-table');

        App::setLocale('ar');
        $ar = dataTableEmptyState('product-table');

        App::setLocale('en');

        $this->assertNotSame($en['emptyTable'], $ar['emptyTable'],
            'the Arabic empty state is identical to the English one, so the translation is '
            . 'missing and __() fell back to the key');
        $this->assertNotSame($en['zeroRecords'], $ar['zeroRecords'],
            'the Arabic filtered-empty state is identical to the English one');
        $this->assertMatchesRegularExpression('~\p{Arabic}~u', $ar['emptyTable'],
            'the Arabic empty state contains no Arabic script');
    }

    /** A known list gets a message about ITS records; an unknown one still gets something useful. */
    public function test_a_known_list_is_named_and_an_unknown_one_still_helps(): void
    {
        $product = dataTableEmptyState('product-table');
        $order = dataTableEmptyState('order-table');

        $this->assertNotSame($product['emptyTable'], $order['emptyTable'],
            'every list shares one generic empty state, so none of them says what to add');

        $unknown = dataTableEmptyState('something-nobody-mapped-table');
        $this->assertNotEmpty($unknown['emptyTable']);
        $this->assertNotEmpty($unknown['zeroRecords']);
    }

    /** And the builder actually passes them, which is what makes any of this reach a screen. */
    public function test_the_table_builder_passes_both_keys(): void
    {
        $helper = (string) file_get_contents(base_path('app/Helper/helper.php'));

        $start = strpos($helper, 'function buildDataTable(');
        $this->assertNotFalse($start, 'premise: buildDataTable was renamed');

        $body = substr($helper, $start, 4000);
        $body = (string) preg_replace('~/\*.*?\*/~s', '', $body);
        $body = (string) preg_replace('~^[ \t]*//.*$~m', '', $body);

        $this->assertStringContainsString('emptyTable', $body,
            'buildDataTable does not pass emptyTable, so all 67 tables keep the English default');
        $this->assertStringContainsString('zeroRecords', $body,
            'buildDataTable does not pass zeroRecords');
    }
}
