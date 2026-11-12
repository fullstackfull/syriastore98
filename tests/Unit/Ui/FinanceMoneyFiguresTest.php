<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * THE MONEY COMPONENT EXISTS, IS CORRECT, AND REACHED TWO CELLS.  P2.5-WAVE-7
 * =================================================================================================
 *
 * `.ss-money { font-variant-numeric: tabular-nums; }` has been in the design system since the
 * P2.5-MONEY slice, along with `--credit` and `--debit` tones whose contrast was measured and
 * fought for against the host theme. Measured today: it is used in **one view** --
 * `finance/index.blade.php` -- and within that view on only the Credit and Debit spans.
 *
 * So on the merchant's own finance screens:
 *
 *     Credit, Debit                      tabular        (ss-money)
 *     Balance Before, Balance After      PROPORTIONAL   same table, same column group, same row
 *     Withdrawals -> Amount              PROPORTIONAL
 *     Unreconciled captures -> Amount    PROPORTIONAL
 *
 * Proportional digits down a column of money means the decimal points do not line up, so a
 * merchant cannot compare two balances by looking at them -- which is the entire reason a money
 * column is a column. Worse, Balance Before and Balance After sit in the SAME ROW as Credit and
 * Debit, so one row shows both conventions side by side.
 *
 * THIS ADOPTS WHAT EXISTS RATHER THAN ADDING ANYTHING. No new class, no new token, no new rule --
 * the component was already correct and already measured. The defect was reach.
 *
 * `--credit` AND `--debit` ARE NOT APPLIED HERE. A balance is not a credit or a debit; it is a
 * running total that can be either. Colouring it green or red would state something the data does
 * not. Only the tabular figures are adopted.
 */
class FinanceMoneyFiguresTest extends TestCase
{
    private const VIEWS = [
        'resources/views/finance/index.blade.php',
        'resources/views/finance/withdrawals.blade.php',
        'resources/views/finance/unreconciled-captures.blade.php',
    ];

    private function read(string $rel): string
    {
        $path = dirname(__DIR__, 3) . '/' . $rel;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** PREMISE. The component must still be defined, or adopting it achieves nothing. */
    public function test_the_money_component_still_carries_tabular_figures(): void
    {
        $css = $this->read('public/css/store-syria-ds.css');

        $this->assertMatchesRegularExpression(
            '/\.ss-money\s*\{[^}]*font-variant-numeric:\s*tabular-nums/s',
            $css,
            '.ss-money no longer sets tabular figures, so every adoption below is decorative'
        );
    }

    /** Every money figure on the three finance screens uses it. */
    public function test_every_finance_money_figure_uses_the_money_component(): void
    {
        $index = $this->read(self::VIEWS[0]);

        foreach (['balance_before', 'balance_after'] as $field) {
            $this->assertMatchesRegularExpression(
                '/ss-money[^>]*>[^<]*\{\{\s*number_format\(\$transaction->' . $field . '/s',
                $index,
                "finance/index renders {$field} without .ss-money, so it uses proportional digits "
                    . 'in the same row as the tabular Credit and Debit beside it'
            );
        }

        // THE SECOND TABLE ON THE SAME SCREEN, which the first version of this test missed
        // entirely. /finance renders a wallet-transaction table AND an order-financials table;
        // the financials one has TEN money columns and exactly one of them (discount) carried
        // the component. Measuring the live page is what surfaced the other seven -- reading the
        // markup I had just edited would not have.
        foreach ([
            'product_amount',
            'tax_amount',
            'shipping_amount',
            'customer_total',
            'product_cost',
            'paid_amount',
            'remaining_amount',
        ] as $field) {
            $this->assertMatchesRegularExpression(
                '/ss-money[^>]*>[^<]*\{\{\s*number_format\(\$financial->' . $field . '/s',
                $index,
                "finance/index renders {$field} without .ss-money, so one money column in the "
                    . 'order-financials table uses proportional digits while the ones beside it do not'
            );
        }

        $this->assertStringContainsString(
            'ss-money',
            $this->read(self::VIEWS[1]),
            'finance/withdrawals renders its Amount column with proportional digits'
        );

        $this->assertStringContainsString(
            'ss-money',
            $this->read(self::VIEWS[2]),
            'finance/unreconciled-captures renders its Amount column with proportional digits'
        );
    }

    /**
     * A BALANCE IS NOT A CREDIT OR A DEBIT. It is a running total that can be either, so the
     * semantic tones must not be applied to it: that would state something the data does not.
     */
    public function test_the_balance_columns_take_no_credit_or_debit_tone(): void
    {
        $index = $this->read(self::VIEWS[0]);

        foreach (['balance_before', 'balance_after'] as $field) {
            $at = strpos($index, '$transaction->' . $field);
            $this->assertNotFalse($at);
            // Look back over the cell that renders it.
            $cellStart = strrpos(substr($index, 0, $at), '<td');
            $cell = substr($index, $cellStart, $at - $cellStart);

            $this->assertStringNotContainsString(
                'ss-money--credit',
                $cell,
                "{$field} is coloured as a credit, but a balance is neither a credit nor a debit"
            );
            $this->assertStringNotContainsString(
                'ss-money--debit',
                $cell,
                "{$field} is coloured as a debit, but a balance is neither a credit nor a debit"
            );
        }
    }
}
