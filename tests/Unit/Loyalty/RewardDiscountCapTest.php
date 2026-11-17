<?php

namespace Tests\Unit\Loyalty;

use App\Support\Money\RewardPointDiscount;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * D5 — a reward discount larger than the basket wrote a NEGATIVE order total.
 * =================================================================================================
 *
 * MEASURED BEFORE THE REPAIR: a 20.00 basket less a 50.00 reward discount stored
 * `orders.final_price = -30.00`. Ten first-party call sites computed
 * `final_price = <base> - $saveRewardPrice` with no bound of any kind. The vendored module's own
 * `applyPoints()` clamps with `max(0, …)` and comments *"Ensure it doesn't go negative"*; no
 * first-party caller did.
 *
 * THE OWNER APPROVED "CAP APPLIED REWARDS WITHIN THE VERIFIED ELIGIBLE AMOUNT", AND THAT IS A
 * DIFFERENT FIX FROM `max(0, $base - $discount)`. Clamping the RESULT leaves `$saveRewardPrice`
 * holding 50.00 while only 20.00 was ever given, so the order, the ledger and any later refund
 * disagree about the discount — a second wrong number in place of the first. Capping the DISCOUNT
 * keeps the recorded reward equal to the reward actually applied, which is what
 * "preserve accurate discount, payment and ledger accounting" requires.
 *
 *     capped      = min(max(0, discount), max(0, eligible))
 *     final_price = eligible - capped        >= 0, by construction, for every input
 *
 * MUTATION RESULTS, six run, five reddened:
 *
 *   M1  cappedTo returns the discount uncapped            5 failed
 *   M2  drop the negative-discount floor                  4 failed
 *   M3  drop the negative-eligible floor                  3 failed
 *   M4  applyTo subtracts from the UNfloored eligible     2 failed
 *   M6  revert one call site to the bare subtraction      2 failed   <- the census guard earning its keep
 *   M5  `<` -> `<=` in the cap                            SURVIVED
 *
 * **M5 IS A PROVABLY EQUIVALENT MUTANT, NOT A MISSING TEST,** and the distinction is worth the
 * sentence because this repository has twice found a survivor that WAS a real hole. `$d < $e ? $d
 * : $e` and `$d <= $e ? $d : $e` can only diverge when `$d == $e` — and when they are equal both
 * arms hold the same value. Checked computationally over a 14-value grid including
 * `±PHP_FLOAT_EPSILON` and `±1e9`: **196 pairs, 0 differing.** No test can kill it, by
 * construction, so none is written for it.
 *
 * THE POINTS SPENT ARE A SEPARATE QUESTION AND ARE NOT DECIDED HERE. Capping the discount at the
 * basket does not by itself decide how many points the customer is debited — that is D4's
 * obligation model. This class answers only "how much money may this discount take off this
 * order", and deliberately answers nothing else.
 */
class RewardDiscountCapTest extends TestCase
{
    /**
     * THE DEFECT, as the measurement recorded it.
     */
    public function test_a_discount_larger_than_the_basket_is_capped_to_the_basket(): void
    {
        $capped = RewardPointDiscount::cappedTo(50.0, 20.0);

        $this->assertSame(
            20.0,
            $capped,
            'A 50.00 reward against a 20.00 basket may take off 20.00 and no more. The shipped '
            . 'code applied the whole 50.00 and stored final_price = -30.00.'
        );

        $this->assertSame(
            0.0,
            20.0 - $capped,
            'and the resulting order total is exactly zero, not negative.'
        );
    }

    /**
     * THE ORDINARY CASE MUST NOT MOVE BY A CENT. This is the licence for the change: every store
     * whose customers hold a balance smaller than their basket sees no difference at all.
     */
    public function test_a_discount_within_the_basket_is_unchanged(): void
    {
        $this->assertSame(5.0, RewardPointDiscount::cappedTo(5.0, 20.0));
        $this->assertSame(19.99, RewardPointDiscount::cappedTo(19.99, 20.0));
        $this->assertSame(0.0, RewardPointDiscount::cappedTo(0.0, 20.0));
    }

    public function test_a_discount_exactly_equal_to_the_basket_is_unchanged(): void
    {
        $this->assertSame(20.0, RewardPointDiscount::cappedTo(20.0, 20.0));
        $this->assertSame(0.0, 20.0 - RewardPointDiscount::cappedTo(20.0, 20.0));
    }

    /**
     * A ZERO-VALUE ORDER TAKES NO DISCOUNT. Reachable: a basket of entirely free items, or one
     * whose coupon already took it to zero.
     */
    public function test_a_zero_basket_takes_no_discount(): void
    {
        $this->assertSame(0.0, RewardPointDiscount::cappedTo(50.0, 0.0));
    }

    /**
     * A NEGATIVE ELIGIBLE AMOUNT YIELDS NO DISCOUNT RATHER THAN A NEGATIVE ONE.
     *
     * This is defensive and is deliberately NOT presented as unreachable: the whole reason this
     * class exists is that a negative total was reachable here. If some other defect hands this
     * method a negative base, the answer must be "no discount", never "a discount that makes it
     * worse".
     */
    public function test_a_negative_eligible_amount_yields_no_discount(): void
    {
        $this->assertSame(0.0, RewardPointDiscount::cappedTo(50.0, -30.0));
        $this->assertSame(0.0, RewardPointDiscount::cappedTo(-5.0, -30.0));
    }

    /**
     * A NEGATIVE DISCOUNT NEVER ADDS TO THE BILL.
     *
     * `RewardPointDiscount::for()` already refuses to return one, but this method is public and
     * takes a float from ten call sites. A negative discount here would make `eligible - capped`
     * LARGER than the basket — a reward that charges the customer more, which is the exact
     * failure mode an earlier review caught in this family's docblock.
     */
    public function test_a_negative_discount_never_increases_the_total(): void
    {
        $this->assertSame(0.0, RewardPointDiscount::cappedTo(-10.0, 20.0));
        $this->assertSame(20.0, 20.0 - RewardPointDiscount::cappedTo(-10.0, 20.0));
    }

    /**
     * `applyTo()` is what the twenty call sites actually use. It exists so the invariant lives in
     * ONE place rather than being re-spelled at each branch: twenty copies of
     * `$base - cappedTo($d, $base)` is twenty chances to write `$base` twice and get the second
     * one wrong, which is exactly the copy-paste failure this family already produced once.
     */
    public function test_apply_to_is_the_cap_and_the_subtraction_together(): void
    {
        $this->assertSame(0.0, RewardPointDiscount::applyTo(20.0, 50.0), 'the measured defect');
        $this->assertSame(15.0, RewardPointDiscount::applyTo(20.0, 5.0), 'the ordinary case');
        $this->assertSame(20.0, RewardPointDiscount::applyTo(20.0, 0.0), 'no discount');
        $this->assertSame(0.0, RewardPointDiscount::applyTo(20.0, 20.0), 'exactly to zero');
        $this->assertSame(20.0, RewardPointDiscount::applyTo(20.0, -10.0), 'never adds');
        $this->assertSame(0.0, RewardPointDiscount::applyTo(-30.0, 50.0), 'negative base floors');
    }

    /**
     * A CONSISTENCY CHECK, NOT AN ORACLE, and the docblock says so because an earlier version of
     * it claimed coverage it does not provide.
     *
     * The expectation below is computed with the implementation's OWN formula — the
     * `$eligible > 0.0 ? $eligible : 0.0` on the next line is line-for-line what `applyTo()`
     * does. So this cannot catch a wrong formula; it catches only `applyTo` and `cappedTo`
     * DRIFTING APART. Delete the eligible floor from `cappedTo` and this test still passes
     * (`0 - (-30) === 30`); only `test_a_negative_eligible_amount_yields_no_discount` kills that
     * mutant. Found by independent review, which also noted that one of the three failures
     * attributed to mutation M3 was this tautology rather than real coverage.
     */
    public function test_apply_to_agrees_with_cap_and_subtraction(): void
    {
        $amounts = [-30.0, 0.0, 0.01, 5.0, 19.99, 20.0, 50.0, 1000.0];

        foreach ($amounts as $eligible) {
            foreach ($amounts as $discount) {
                $floored = $eligible > 0.0 ? $eligible : 0.0;

                $this->assertSame(
                    $floored - RewardPointDiscount::cappedTo($discount, $eligible),
                    RewardPointDiscount::applyTo($eligible, $discount),
                    "applyTo diverged from cappedTo for eligible={$eligible} discount={$discount}"
                );
            }
        }
    }

    /**
     * THE INVARIANT, over a grid rather than a handful of chosen points: for every pair this
     * method can be handed, the order total it implies is non-negative and no larger than the
     * basket it started from.
     */
    public function test_the_resulting_total_is_never_negative_and_never_grows(): void
    {
        $amounts = [-30.0, -0.01, 0.0, 0.01, 1.0, 19.99, 20.0, 20.01, 50.0, 1000.0];

        foreach ($amounts as $discount) {
            foreach ($amounts as $eligible) {
                $capped = RewardPointDiscount::cappedTo($discount, $eligible);

                /*
                 * THROUGH `applyTo()`, NOT BY HAND. An earlier version of this test computed
                 * `$eligible - $capped` itself, so its name asserted an invariant about the
                 * production path while exercising arithmetic the production path does not
                 * perform -- reverting `applyTo()` to a bare subtraction left it green. Found by
                 * independent review. It now calls the method the twenty call sites call.
                 */
                $total = RewardPointDiscount::applyTo($eligible, $discount);

                $this->assertGreaterThanOrEqual(
                    0.0,
                    $capped,
                    "capped discount went negative for discount={$discount} eligible={$eligible}"
                );

                if ($eligible >= 0.0) {
                    $this->assertGreaterThanOrEqual(
                        0.0,
                        $total,
                        "order total went NEGATIVE for discount={$discount} eligible={$eligible}"
                    );

                    $this->assertLessThanOrEqual(
                        $eligible,
                        $total,
                        "order total GREW for discount={$discount} eligible={$eligible}"
                    );
                }
            }
        }
    }

    /**
     * A file's PHP with every comment removed.
     *
     * Both censuses below need this. The negative one needs it so NMI's prose quoting the old
     * expression cannot trip it; the positive one needs it for the mirror reason, and an earlier
     * version did NOT have it -- independent review predicted that a future comment quoting
     * `applyTo(` would push the count to 21 and redden CI, and that is exactly what happened
     * within minutes, from a comment added in the same change.
     */
    private static function codeWithoutComments(string $path): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        return $code;
    }

    /**
     * The repository root, computed from this file rather than from `base_path()`.
     *
     * This class extends PHPUnit's TestCase and boots no application on purpose -- it tests a
     * static method with no container, no database and no framework. Reaching for `base_path()`
     * would drag the whole app in just to resolve a directory, and it is not available here.
     */
    private static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function appPath(): string
    {
        return self::repoRoot() . '/app';
    }

    /**
     * THE CENSUS, so a twenty-first site cannot be written the old way in silence.
     *
     * `$saveRewardPrice` may appear in `app/` ONLY as an argument to `applyTo()` — never as the
     * right-hand side of a bare subtraction. This is the guard that actually protects the ten
     * handlers: the arithmetic tests above prove the helper is right, and this proves the helper
     * is the only thing doing the arithmetic.
     *
     * The one permitted exception is PROSE. `NMIPayController` carries a long comment explaining
     * why ITS shipping-on branch deliberately does not subtract at all (the discount is already
     * inside the figure the card was charged), and that comment necessarily quotes the old
     * expression. Comment text is stripped before the check rather than allowlisted by line
     * number, because a line number is exactly the kind of anchor this repository has watched rot
     * nine times.
     */
    public function test_no_site_subtracts_the_reward_discount_by_hand(): void
    {
        $offenders = [];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::appPath()));

        foreach ($iterator as $file) {
            if ($file->isDir() || ! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $path = $file->getPathname();

            if (str_ends_with($path, 'RewardPointDiscount.php')) {
                continue;   // the helper's own docblock quotes the defect it replaced
            }

            $code = self::codeWithoutComments($path);

            /*
             * TWO SHAPES, because the variable-name one has a known blind spot that independent
             * review named: `$total -= $saveRewardPrice` does not match (an `=` follows the `-`),
             * and ANY OTHER VARIABLE NAME is invisible to it. The second pattern closes the
             * second hole semantically -- `RewardPointDiscount::for()` is the only producer of a
             * reward discount in `app/`, so a site that calls it and then subtracts by hand is
             * caught whatever it calls the variable.
             */
            if (preg_match('/-=?\s*\$saveRewardPrice/', $code)
                || preg_match('/RewardPointDiscount::for\([^;]*;\s*[^;]*-=?\s*\$[A-Za-z_]\w*\s*;/', $code)) {
                $offenders[] = str_replace(self::repoRoot() . '/', '', $path);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A reward discount is being subtracted by hand instead of through "
            . "RewardPointDiscount::applyTo(), which is how a negative order total gets back in:\n  "
            . implode("\n  ", $offenders)
        );
    }

    /**
     * And the positive half: the helper really is in use at the expected number of sites, so this
     * census cannot pass by the call sites having quietly disappeared.
     */
    public function test_the_helper_is_used_at_every_site_that_applies_a_reward(): void
    {
        $uses = 0;

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::appPath()));

        foreach ($iterator as $file) {
            if ($file->isDir() || ! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $path = $file->getPathname();

            if (str_ends_with($path, 'RewardPointDiscount.php')) {
                continue;   // its own docblock quotes both method names
            }

            $uses += preg_match_all(
                '/RewardPointDiscount::applyTo\(/',
                self::codeWithoutComments($path)
            );
        }

        $this->assertSame(
            20,
            $uses,
            'Expected 20 applyTo() call sites across the nine order-writing handlers. A change in '
            . 'this number is either a new reward path (which needs its own review) or a removed '
            . 'one (which needs this count updated deliberately).'
        );
    }
}
