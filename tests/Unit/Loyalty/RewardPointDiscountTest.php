<?php

namespace Tests\Unit\Loyalty;

use App\Support\Money\RewardPointDiscount;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * MKT-005/MKT-006. The reward-point discount, which eleven first-party payment paths each
 * computed with their own copy of one expression.
 *
 * THE SHIPPED EXPRESSION IS REPRODUCED VERBATIM IN self::shipped() and the first test asserts
 * the helper AGREES WITH IT wherever it produces a number at all. That is the whole licence for
 * this change: it is not a pricing decision, it is the same arithmetic with the fatal removed.
 */
class RewardPointDiscountTest extends TestCase
{
    /**
     * The expression as shipped at all 11 first-party sites. Operands, grouping and order are
     * exact; the `float` parameter and return type coerce, so this does not reproduce the
     * original's int/float coercion. Immaterial to the value, stated because "verbatim" was an
     * overclaim (review).
     */
    private static function shipped(float $balance, array $s): float
    {
        return ($balance * $s['reward_point_price'] ?? 0) / ($s['reward_points'] ?? 0);
    }

    public static function configuredCases(): array
    {
        return [
            '100 pts, 0.5 per point, 10 points per unit' => [100.0, ['reward_point_price' => 0.5, 'reward_points' => 10]],
            'one point per unit'                        => [100.0, ['reward_point_price' => 0.5, 'reward_points' => 1]],
            'fractional rate'                           => [37.0, ['reward_point_price' => 0.03, 'reward_points' => 7]],
            'numeric strings, as settings are stored'   => [100.0, ['reward_point_price' => '0.5', 'reward_points' => '10']],
            'zero balance'                              => [0.0, ['reward_point_price' => 0.5, 'reward_points' => 10]],
        ];
    }

    /**
     * THE JUSTIFYING PROPERTY. Wherever the shipped expression yields a number, this helper
     * yields THE SAME number. No configured store's discount moves by one cent.
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('configuredCases')]
    public function it_returns_exactly_what_the_shipped_expression_returned(float $balance, array $settings): void
    {
        $this->assertSame(
            self::shipped($balance, $settings),
            RewardPointDiscount::for($balance, $settings),
            'A configured store\'s discount must not move.'
        );
    }

    #[Test]
    public function the_shipped_expression_is_fatal_when_no_rate_is_configured(): void
    {
        // PREMISE TEST: proves the defect this helper exists to remove is real, so that a
        // future reader cannot mistake the guard below for defensive decoration.
        $this->expectException(\DivisionByZeroError::class);
        self::shipped(100.0, ['reward_point_price' => 0.5]);
    }

    #[Test]
    public function the_shipped_expression_was_also_fatal_on_an_absent_price(): void
    {
        // SECOND PREMISE. Not a warning: HandleExceptions::bootstrap() sets error_reporting(-1)
        // and handleError() throws, so inside the booted app an undefined key here was an HTTP
        // 500 -- `ErrorException: Undefined array key "reward_point_price"`. Measured in the app
        // after a review caught that the first measurement had been taken OUTSIDE it, where PHP
        // merely warns. The exception CLASS is not pinned, because the test runner may wrap a
        // raised warning differently from the HTTP kernel; that it throws at all is the premise.
        $thrown = null;
        try {
            self::shipped(100.0, ['reward_points' => 10]);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'The shipped expression no longer throws on an absent price, so this premise is stale.');
        $this->assertSame(0.0, RewardPointDiscount::for(100.0, ['reward_points' => 10]), 'The helper must answer where the old expression died.');
    }

    public static function unconfiguredCases(): array
    {
        return [
            'reward_points absent'                   => [['reward_point_price' => 0.5]],
            'reward_points zero'                     => [['reward_point_price' => 0.5, 'reward_points' => 0]],
            'reward_points "0" (passes `required`)'  => [['reward_point_price' => 0.5, 'reward_points' => '0']],
            'reward_points negative'                 => [['reward_point_price' => 0.5, 'reward_points' => -10]],
            'reward_points non-numeric'              => [['reward_point_price' => 0.5, 'reward_points' => 'ten']],
            // PINS is_numeric() SPECIFICALLY. `(float) '5abc'` is 5.0, so a cast-and-compare
            // guard alone would divide by 5 and quietly grant a discount from a junk setting.
            // Found by a mutation that dropped is_numeric() and stayed green.
            'reward_points leading-numeric junk'     => [['reward_point_price' => 0.5, 'reward_points' => '5abc']],
            'reward_points trailing units'           => [['reward_point_price' => 0.5, 'reward_points' => '10 points']],
            'price leading-numeric junk'             => [['reward_point_price' => '2xyz', 'reward_points' => 10]],
            'reward_points empty string'             => [['reward_point_price' => 0.5, 'reward_points' => '']],
            'reward_points null'                     => [['reward_point_price' => 0.5, 'reward_points' => null]],
            'settings row entirely absent'           => [[]],
            'price absent, points configured'        => [['reward_points' => 10]],
            'price non-numeric'                      => [['reward_point_price' => 'free', 'reward_points' => 10]],
            'price null'                             => [['reward_point_price' => null, 'reward_points' => 10]],
        ];
    }

    /**
     * NO RATE CONFIGURED MEANS NO DISCOUNT -- and deliberately NOT the module's own fallback.
     * `RewardClubPointController::applyPoints()` substitutes a divisor of 1, which turns a
     * misconfiguration into the LARGEST discount the balance can buy: measured, a 100-point
     * balance at 0.5 took 50.00 off a 20.00 basket. A missing rate is not a rate of one.
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('unconfiguredCases')]
    public function an_unconfigured_rate_yields_no_discount_rather_than_a_maximal_one(array $settings): void
    {
        $this->assertSame(0.0, RewardPointDiscount::for(100.0, $settings));
    }

    #[Test]
    public function it_never_returns_a_negative_discount(): void
    {
        // A negative discount would ADD to the order total.
        $this->assertSame(0.0, RewardPointDiscount::for(-100.0, ['reward_point_price' => 0.5, 'reward_points' => 10]));
        $this->assertSame(0.0, RewardPointDiscount::for(100.0, ['reward_point_price' => -0.5, 'reward_points' => 10]));
    }

    #[Test]
    public function it_does_not_warn_on_an_absent_key(): void
    {
        // The shipped `($a * $b ?? 0)` is mis-bound -- `*` binds tighter than `??` -- so an
        // absent price emits "Undefined array key" and the `?? 0` never defaults anything.
        set_error_handler(static function (int $n, string $s): bool {
            throw new \ErrorException($s, 0, $n);
        });
        try {
            // Each key absent in turn, and both at once -- one per `?? null` read in the class.
            $this->assertSame(0.0, RewardPointDiscount::for(100.0, ['reward_points' => 10]));
            $this->assertSame(0.0, RewardPointDiscount::for(100.0, ['reward_point_price' => 0.5]));
            $this->assertSame(0.0, RewardPointDiscount::for(100.0, []));
        } finally {
            restore_error_handler();
        }
    }
}
