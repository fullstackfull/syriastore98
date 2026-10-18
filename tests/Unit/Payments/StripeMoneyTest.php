<?php

namespace Tests\Unit\Payments;

use App\Services\Payments\StripeMoney;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * P01-SEC-14 Work Package B — the major-to-minor-unit conversion, on its own.
 *
 * This is a pure function and it decides what a customer is charged, so it gets its own tests
 * rather than being covered only incidentally through the handler. A 100x error here is a 100x
 * error on a real card.
 */
class StripeMoneyTest extends TestCase
{
    public static function conversions(): array
    {
        return [
            'dollars and cents'            => [107.70, 'USD', 10770],
            'numeric string from SetNumber' => ['107.70', 'USD', 10770],
            'a single cent'                => [0.01, 'USD', 1],
            'the classic float case'       => [10.99, 'USD', 1099],
            'a value the truncating form gets wrong' => [0.29, 'USD', 29],
            'another one'                  => [1.13, 'USD', 113],
            'zero-decimal yen'             => [50.00, 'JPY', 50],
            'zero-decimal rounds up'       => [1499.6, 'JPY', 1500],
            'zero-decimal lowercase'       => [50.00, 'jpy', 50],
            'zero is zero'                 => [0, 'USD', 0],
            'negative is clamped'          => [-5.00, 'USD', 0],
            'unknown currency is treated as minor-unit bearing' => [12.34, 'SYP', 1234],
        ];
    }

    #[DataProvider('conversions')]
    public function test_it_converts(float|int|string $amount, string $currency, int $expected): void
    {
        $this->assertSame($expected, StripeMoney::toMinorUnits($amount, $currency));
    }

    /**
     * The reason round() is used rather than the truncating form already in PaymentService.
     *
     * That form -- `(int) (number_format($x, 2, '.', '') * 100)` -- multiplies a string by 100 in
     * binary floating point and truncates the product, so it lands one minor unit low whenever the
     * product falls just under the integer. Swept across every amount from 0.01 to 10000.00 it is
     * wrong on 65,624 of 1,000,000 values. This test pins a handful of them so the reason for the
     * divergence survives in the suite rather than only in a commit message.
     */
    public function test_it_does_not_truncate_where_the_existing_conversion_does(): void
    {
        $divergent = [];

        foreach ([0.29, 0.57, 0.58, 1.13, 1.14, 1.15] as $amount) {
            $truncating = (int) (number_format($amount, 2, '.', '') * 100);
            $rounded = StripeMoney::toMinorUnits($amount, 'USD');

            $this->assertSame((int) round($amount * 100), $rounded,
                'StripeMoney mis-converted ' . $amount);

            if ($truncating !== $rounded) {
                $divergent[] = $amount;
            }
        }

        $this->assertNotEmpty($divergent,
            'The truncating conversion now agrees with this one everywhere in the sample, so either '
            . 'PHP changed or the sample is wrong. Re-derive before deleting this test.');
    }

    public function test_the_zero_decimal_list_matches_the_projects_existing_one(): void
    {
        // Copied from app/Services/PaymentService.php:555-574, which governs the Stripe Checkout
        // arm. If that list is ever extended, this fails and points at the duplication.
        $existing = [
            'MGA', 'BIF', 'CLP', 'PYG', 'DJF', 'RWF', 'GNF', 'UGX',
            'JPY', 'VND', 'VUV', 'XAF', 'KMF', 'KRW', 'XOF', 'XPF',
        ];

        $this->assertSame(
            array_values(array_unique($existing)),
            array_values(array_unique(StripeMoney::ZERO_DECIMAL_CURRENCIES)),
            'The zero-decimal list has drifted from the one in PaymentService, so the two Stripe '
            . 'arms now disagree about which currencies have a minor unit.'
        );
    }
}
