<?php

namespace Tests\Unit\Loyalty;

use App\Support\Money\RewardPointDiscount;
use PHPUnit\Framework\TestCase;

/**
 * D6 — THE REDEMPTION THRESHOLDS ARE ENFORCED IN BLADE ONLY, SO THE SERVER HONOURS A FLAG IT
 * NEVER CHECKS THE ELIGIBILITY FOR.
 * =================================================================================================
 *
 * OWNER-APPROVED 2026-11-17, D6: *"Enforce reward redemption thresholds and eligibility
 * server-side. Client-submitted values must not bypass them."*
 *
 * WHAT THE PRODUCT ACTUALLY GATES ON. Both module partials carry the identical condition —
 * `clubPointApply.blade.php:1-9` and `clubPointPriceShow.blade.php:1-10`:
 *
 *     isset order_point_price, order_points, reward_points, reward_point_price,
 *           checkout_points, checkout_price
 *     AND   $customer->total_club_point     >= $setting['checkout_points']
 *     AND   $response->data->final_price    >= $setting['checkout_price']
 *
 * WHAT THE SERVER CHECKS. `isset($requests_data['club_point_is_active']) && … == 'on'`, at all
 * ELEVEN sites that apply a reward discount. That is the caller's own posted string compared with
 * itself. No balance, no threshold, no settings — so a hand-crafted POST redeems below both
 * thresholds, and `RewardPointDiscount::for()` happily converts the whole balance.
 *
 * AND THERE IS A THIRD GAP NEITHER THE UI NOR THE SERVER CLOSES: `club_point_enabled`.
 * `RewardClubPointController:45` writes it as `$request->club_point_enabled ?? 'off'` and it is
 * NOT in the validator's required list (`:26-31` is the six above), so a store can save all six
 * settings with the programme switched OFF. The UI gate does not test it. The server does not test
 * it. But the vendored listener's `:35` does — `$setting['club_point_enabled'] == 'on'` guards the
 * entire debit block. So in that configuration the discount is applied and **the points are never
 * debited and no ledger row is written**: free money, recurring, with no record. This class treats
 * `club_point_enabled` as part of eligibility for exactly that reason.
 *
 * TWO DELIBERATE DIVERGENCES FROM THE BLADE GATE, both measured rather than assumed:
 *
 *   1. THE THRESHOLDS MUST BE NUMERIC. Measured on PHP 8.3 with the strings a `settings` row
 *      actually stores: `100 >= "abc"` is FALSE (the int is cast to string, so junk already fails
 *      closed and this changes nothing) but **`100 >= "" ` is TRUE**, and `isset('')` is true — so
 *      an empty-string threshold reads as "no threshold at all" in Blade. Requiring numeric makes
 *      that fail closed instead. It is not reachable through the settings form, whose `required`
 *      rule rejects `''`, but `Setting::updateOrCreate` is reachable from elsewhere and a money
 *      gate should not depend on a validator two layers away.
 *   2. `club_point_enabled` IS REQUIRED, per the paragraph above. Strictly tighter than Blade,
 *      and the only reading under which a granted discount is always matched by a debit.
 *
 * EVERYTHING ELSE MATCHES BLADE EXACTLY, including `>=` rather than `>`, and including that a
 * threshold of `0` is satisfied by a balance of `0` — measured: `0 >= "0"` is true. A store that
 * configures both thresholds at `0` has opted every logged-in customer in, and `required` passes
 * `0`, so that is a reachable configuration and not a defect in this class.
 *
 * WHAT THIS CLASS DOES NOT DECIDE. How many points are debited, and whether the debit matches the
 * discount. The module spends the WHOLE balance whenever it redeems at all, and the only writer is
 * vendored — see `docs/security/P05-VENDOR-DEFECTS-REWARDCLUBPOINT.md`. D4's obligation record is
 * where that belongs.
 */
class RewardRedemptionEligibilityTest extends TestCase
{
    /** The six settings the module's own partials require, in the shape a `settings` row stores. */
    private function configured(array $overrides = []): array
    {
        return array_merge([
            'club_point_enabled' => 'on',
            'order_point_price' => '10',
            'order_points' => '1',
            'reward_points' => '1',
            'reward_point_price' => '0.5',
            'checkout_points' => '100',
            'checkout_price' => '20',
        ], $overrides);
    }

    public function test_a_fully_eligible_customer_may_redeem(): void
    {
        $this->assertTrue(RewardPointDiscount::isRedeemable(100.0, 20.0, $this->configured()));
        $this->assertTrue(RewardPointDiscount::isRedeemable(250.0, 99.0, $this->configured()));
    }

    public function test_a_balance_below_checkout_points_may_not(): void
    {
        $this->assertFalse(RewardPointDiscount::isRedeemable(99.99, 20.0, $this->configured()));
        $this->assertFalse(RewardPointDiscount::isRedeemable(0.0, 20.0, $this->configured()));
    }

    public function test_an_order_below_checkout_price_may_not(): void
    {
        $this->assertFalse(RewardPointDiscount::isRedeemable(100.0, 19.99, $this->configured()));
        $this->assertFalse(RewardPointDiscount::isRedeemable(100.0, 0.0, $this->configured()));
    }

    public function test_the_boundary_is_inclusive_exactly_as_blade_has_it(): void
    {
        // `>=` in both partials. Equality on either threshold is eligible.
        $this->assertTrue(RewardPointDiscount::isRedeemable(100.0, 20.0, $this->configured()));
        $this->assertFalse(RewardPointDiscount::isRedeemable(100.0, 19.999999, $this->configured()));
        $this->assertFalse(RewardPointDiscount::isRedeemable(99.999999, 20.0, $this->configured()));
    }

    public function test_thresholds_of_zero_opt_everyone_in_because_that_is_a_savable_configuration(): void
    {
        $open = $this->configured(['checkout_points' => '0', 'checkout_price' => '0']);

        $this->assertTrue(RewardPointDiscount::isRedeemable(0.0, 0.0, $open));
    }

    public function test_the_programme_being_switched_off_refuses_redemption(): void
    {
        // The decisive case: the UI would still offer it and the server used to honour it, while
        // the vendored listener refuses to debit -- a discount with no matching debit.
        foreach (['off', '', null, '0', 'ON'] as $value) {
            $settings = $this->configured(['club_point_enabled' => $value]);

            $this->assertFalse(
                RewardPointDiscount::isRedeemable(100.0, 20.0, $settings),
                sprintf('club_point_enabled = %s must refuse', var_export($value, true))
            );
        }

        unset($settings);
        $absent = $this->configured();
        unset($absent['club_point_enabled']);
        $this->assertFalse(RewardPointDiscount::isRedeemable(100.0, 20.0, $absent));
    }

    public function test_every_one_of_the_six_settings_is_required(): void
    {
        foreach (['order_point_price', 'order_points', 'reward_points', 'reward_point_price', 'checkout_points', 'checkout_price'] as $key) {
            $missing = $this->configured();
            unset($missing[$key]);

            $this->assertFalse(
                RewardPointDiscount::isRedeemable(100.0, 20.0, $missing),
                $key.' is in both Blade gates, so its absence must refuse here too'
            );
        }
    }

    public function test_a_non_numeric_threshold_fails_closed(): void
    {
        $this->assertFalse(RewardPointDiscount::isRedeemable(100.0, 20.0, $this->configured(['checkout_points' => 'abc'])));
        $this->assertFalse(RewardPointDiscount::isRedeemable(100.0, 20.0, $this->configured(['checkout_price' => 'abc'])));
    }

    public function test_an_empty_string_threshold_fails_closed_which_is_the_one_divergence_from_blade(): void
    {
        // MEASURED: `100 >= ""` is TRUE on PHP 8.3 and `isset('')` is TRUE, so Blade reads an empty
        // threshold as "no threshold". Here it reads as "not configured".
        $this->assertTrue(100 >= '', 'premise: PHP 8 compares an int against the empty string as greater');

        $this->assertFalse(RewardPointDiscount::isRedeemable(100.0, 20.0, $this->configured(['checkout_points' => ''])));
        $this->assertFalse(RewardPointDiscount::isRedeemable(100.0, 20.0, $this->configured(['checkout_price' => ''])));
    }

    public function test_an_empty_settings_array_refuses(): void
    {
        $this->assertFalse(RewardPointDiscount::isRedeemable(100.0, 20.0, []));
    }

    public function test_a_negative_balance_or_order_value_refuses(): void
    {
        $open = $this->configured(['checkout_points' => '0', 'checkout_price' => '0']);

        // Defensive, and NOT claimed unreachable: `orders.final_price` was measured negative in
        // this very family before D5 capped it.
        $this->assertFalse(RewardPointDiscount::isRedeemable(-1.0, 20.0, $open));
        $this->assertFalse(RewardPointDiscount::isRedeemable(100.0, -1.0, $open));
    }

    public function test_a_negative_threshold_does_not_let_a_negative_value_through(): void
    {
        /*
         * THE CASE THAT MAKES THE FLOOR LOAD-BEARING, AND MUTATION IS WHAT FOUND IT.
         *
         * Deleting the `$balance < 0.0 || $orderValue < 0.0` guard left the whole suite green,
         * because the test above pins thresholds of `0` and `-1.0 >= 0.0` already refuses. The
         * floor only bites against a NEGATIVE threshold — and a negative threshold is savable:
         * `RewardClubPointController:26-31` validates all six with a bare `required`, no `numeric`
         * and no `min:0`, exactly the hole that made `> 0.0` load-bearing in `for()` after a review
         * found three module writers of `sale_price` with no `min:0` either.
         *
         * Without the floor, `-1.0 >= -5.0` is TRUE: a customer with a negative balance would be
         * eligible, and `for()` would then be asked to convert it.
         */
        $negative = $this->configured(['checkout_points' => '-5', 'checkout_price' => '-5']);

        $this->assertTrue(-1.0 >= -5.0, 'premise: without the floor this comparison admits a negative balance');

        $this->assertFalse(RewardPointDiscount::isRedeemable(-1.0, 20.0, $negative));
        $this->assertFalse(RewardPointDiscount::isRedeemable(100.0, -1.0, $negative));

        // And a non-negative value against a negative threshold is still eligible, so the floor
        // refuses only what it is meant to.
        $this->assertTrue(RewardPointDiscount::isRedeemable(0.0, 0.0, $negative));
    }

    public function test_the_master_switch_comparison_is_a_provably_equivalent_mutant(): void
    {
        /*
         * `(… ?? null) != 'on'` vs `!== 'on'` SURVIVES MUTATION, and this is the proof that it is
         * equivalent rather than untested — the distinction this project insists on, because it has
         * twice had a survivor that was a real hole.
         *
         * The two differ only where a value is loosely equal to `'on'` without being identical to
         * it. Over everything a `settings` row can hold — the table stores strings, and
         * `getAdminAllSetting()` returns them unchanged — there is no such value. Exhaustively:
         */
        $reachable = ['on', 'off', 'ON', 'On', '', '0', '1', 'yes', 'true', 'no', null];
        $differing = [];

        foreach ($reachable as $v) {
            if ((($v ?? null) != 'on') !== (($v ?? null) !== 'on')) {
                $differing[] = var_export($v, true);
            }
        }

        $this->assertSame([], $differing, 'loose and strict disagree on: '.implode(', ', $differing));

        // The one value that DOES differ is boolean true -- `true == 'on'` is true in PHP 8 -- and
        // it is not reachable from a settings row. Recorded so the equivalence claim is bounded by
        // its premise rather than stated absolutely.
        $this->assertTrue(true == 'on');
        $this->assertTrue(true !== 'on');
    }

    public function test_forOrder_returns_the_discount_only_when_eligible(): void
    {
        $settings = $this->configured();

        // 100 points at 0.5 per point over 1 point = 50.00, the same figure `for()` produces.
        $this->assertSame(
            RewardPointDiscount::for(100.0, $settings),
            RewardPointDiscount::forOrder(100.0, 20.0, $settings)
        );

        // Ineligible by each gate in turn -> no discount at all, not a reduced one.
        $this->assertSame(0.0, RewardPointDiscount::forOrder(99.0, 20.0, $settings));
        $this->assertSame(0.0, RewardPointDiscount::forOrder(100.0, 19.0, $settings));
        $this->assertSame(0.0, RewardPointDiscount::forOrder(100.0, 20.0, $this->configured(['club_point_enabled' => 'off'])));
    }

    public function test_it_is_not_wired_yet_and_that_is_deliberate(): void
    {
        /*
         * THE POINT OF THIS TEST IS THAT IT WILL FAIL, and that failing is how the next person
         * finds the paragraph below rather than discovering it in production.
         *
         * D6 is NOT closed by this class. Adding `isRedeemable()` to the eleven sites that apply a
         * discount, and nothing else, produces a WORSE defect than the one it closes: the server
         * declines the discount while the client's `club_point_is_active=on` still rides into
         * `AddRewardClubPoint`, so the vendored listener spends the customer's whole balance
         * anyway. Points debited, no discount given — against the customer.
         *
         * A refusing site must therefore ALSO `unset($requests_data['club_point_is_active'])`, so
         * the vendored listener takes its accrual branch, which `NormaliseRewardRedemptionFlag`'s
         * tests already prove it does on an absent flag. Both halves land in one slice, with the
         * negative case tested, or neither does.
         */
        $roots = [dirname(__DIR__, 3).'/app', dirname(__DIR__, 3).'/packages'];
        $sites = [];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                // Comments stripped: this class's own docblock names both methods, and an
                // un-stripped census would be satisfied by that prose. Same trap this family has
                // now produced three times.
                $code = '';
                foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                    if (is_array($token)) {
                        if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                            $code .= str_repeat("\n", substr_count($token[1], "\n"));

                            continue;
                        }
                        $code .= $token[1];
                    } else {
                        $code .= $token;
                    }
                }
                foreach (explode("\n", $code) as $n => $line) {
                    // CLASS-QUALIFIED, and that is not laziness. A bare `/\bforOrder\s*\(/`
                    // matches `StockMovement::forOrder($storeId, $orderId)` at
                    // app/Services/StockMovement.php:343 -- an unrelated, pre-existing method
                    // whose arguments mean something else entirely. Measured: that is the only
                    // collision in the tree today. Every call site in this codebase spells the
                    // class (`RewardPointDiscount::for(`, `::applyTo(`), so this matches the house
                    // style; the blind spot is an aliased or imported bare call, which would not
                    // be caught.
                    if (preg_match('/RewardPointDiscount::\s*(isRedeemable|forOrder)\s*\(/', $line)) {
                        $sites[] = str_replace(dirname(__DIR__, 3).'/', '', $file->getPathname()).':'.($n + 1);
                    }
                }
            }
        }

        // The declarations themselves are in the helper and are not call sites.
        $sites = array_values(array_filter($sites, static fn (string $s) => ! str_starts_with($s, 'app/Support/Money/RewardPointDiscount.php')));

        $this->assertSame(
            [],
            $sites,
            "D6's eligibility gate now has call sites. Read this test's docblock BEFORE deleting it:\n"
            ."a refusing site must also unset club_point_is_active, or the vendored listener debits\n"
            ."the balance with no discount given. Sites found:\n  ".implode("\n  ", $sites)
        );
    }

    public function test_forOrder_never_exceeds_what_for_would_give(): void
    {
        // forOrder is a GATE over for(), never a second arithmetic. If this ever diverges, the two
        // have drifted and the figure on the order would stop matching the figure in the module.
        $settings = $this->configured(['checkout_points' => '0', 'checkout_price' => '0']);

        foreach ([0.0, 1.0, 37.5, 100.0, 1e6] as $balance) {
            $this->assertSame(
                RewardPointDiscount::for($balance, $settings),
                RewardPointDiscount::forOrder($balance, 500.0, $settings),
                'forOrder must return exactly for() when eligible, never a recomputation'
            );
        }
    }
}
