<?php

namespace Tests\Unit\Control;

use App\Support\Control\FeatureControl;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `CTRL-002` — "إطلاق تدريجي" : THE THREE PROPERTIES A NAIVE GRADUAL ROLLOUT GETS WRONG.
 * =================================================================================================
 *
 * *"إطلاق تدريجي وإيقاف آمن"* — gradual rollout and safe disable.
 * `docs/development/master-plan/DECISION-ID-REGISTER.md:250`, Phase 06 §06-H.
 *
 * MEASURED BEFORE THIS WORK: nothing in `app/`, `routes/`, `database/migrations/` or `config/`
 * implements a staged rollout of anything. `rollout` occurs twice, both of them in
 * `app/Scopes/StoreScope.php` comments about a different subject; `canary` and `pilot` occur zero
 * times. So "a percentage of stores" did not exist — a boolean per merchant is not a gradual
 * rollout.
 *
 * -------------------------------------------------------------------------------------------------
 * A PERCENTAGE IS THE EASY HALF. These three are the ones that bite.
 *
 *  1. **STICKY.** `rand()` or `shuffle()` would flip a store between calls, so one rendered page
 *     could come half from the new code and half from the old, and a shopper reloading would see
 *     the feature appear and disappear. The bucket must be a function of (flag, store) and nothing
 *     else.
 *
 *  2. **MONOTONIC.** Widening 10% → 50% must only ADD stores. A store that already had the feature
 *     losing it when the rollout WIDENS is the failure that makes operators stop trusting the
 *     control and release to 100% instead — which is the opposite of what `CTRL-002` asks for.
 *
 *  3. **DECORRELATED ACROSS FLAGS.** `store_id % 100` would put the same unlucky stores in the
 *     first 10% of every rollout for ever: one merchant would be the guinea pig for every feature
 *     the platform ever ships. The hash is salted with the flag key so each feature's 10% is a
 *     different 10%.
 *
 * NO DATABASE. `FeatureControl::bucket()` is a pure function of its two arguments, so this file
 * lives in the blocking Unit suite where it costs almost nothing, and the resolution rules that
 * need rows live in `tests/Feature/Control/`.
 */
class GradualRolloutBucketTest extends TestCase
{
    private const FEATURE = 'premium-insights';
    private const OTHER = 'another-feature';

    /** How many consecutive store ids the distribution checks sweep. */
    private const SWEEP = 1000;

    /**
     * @return array<int, int> the store ids inside `$percentage` for `$key`
     */
    private function included(string $key, int $percentage, int $upTo = self::SWEEP): array
    {
        $in = [];

        for ($storeId = 1; $storeId <= $upTo; $storeId++) {
            if (FeatureControl::bucket($key, $storeId) < $percentage) {
                $in[] = $storeId;
            }
        }

        return $in;
    }

    // ---------------------------------------------------------------------------------------------
    // property 1 -- sticky
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function test_a_stores_bucket_is_the_same_every_time_it_is_asked(): void
    {
        $first = [];

        for ($storeId = 1; $storeId <= 50; $storeId++) {
            $first[$storeId] = FeatureControl::bucket(self::FEATURE, $storeId);
        }

        for ($round = 0; $round < 5; $round++) {
            foreach ($first as $storeId => $bucket) {
                $this->assertSame(
                    $bucket,
                    FeatureControl::bucket(self::FEATURE, $storeId),
                    "Store {$storeId} moved bucket between calls. A rollout that is not sticky "
                    . 'renders half a page from the new code and half from the old, and a shopper '
                    . 'reloading watches the feature appear and disappear.'
                );
            }
        }
    }

    /**
     * EVERY BUCKET IS IN RANGE. Out of range is not a cosmetic fault: a NEGATIVE bucket is `< `
     * every percentage, so one would make a 1% rollout reach the whole platform.
     *
     * THIS IS WHY THE HASH IS `md5` AND NOT `crc32`. On a 32-bit build `crc32()` returns a negative
     * int and `% 100` keeps the sign — so the obvious implementation is a platform-wide release on
     * one architecture only, and a 64-bit test suite cannot see it. Six hex digits of md5 is at
     * most `0xFFFFFF` (16,777,215), a safe integer on every build.
     */
    #[Test]
    public function test_every_bucket_is_between_zero_and_ninety_nine(): void
    {
        for ($storeId = 1; $storeId <= self::SWEEP; $storeId++) {
            $bucket = FeatureControl::bucket(self::FEATURE, $storeId);

            $this->assertGreaterThanOrEqual(0, $bucket,
                "Store {$storeId} has a NEGATIVE bucket, which is less than every percentage -- so "
                . 'a 1% rollout would reach it. This is the crc32-on-32-bit failure.');
            $this->assertLessThan(100, $bucket,
                "Store {$storeId} has a bucket of {$bucket}, which no percentage can reach, so "
                . 'that store can never join a staged rollout at all.');
        }
    }

    /**
     * A FIXED PIN ON THE FUNCTION ITSELF.
     *
     * Changing the bucketing function RESHUFFLES EVERY LIVE ROLLOUT: a store that has a feature at
     * 10% today can be outside the new 10% tomorrow and LOSE it, which is property 2 broken across
     * a deploy rather than across a percentage. The distribution checks below cannot see that —
     * any sane hash gives roughly the right proportion — so the function is pinned by value, and
     * changing it is a deliberate decision that reddens this test instead of silently withdrawing
     * a feature from live stores.
     *
     * These seven numbers were measured from the implementation, not predicted.
     */
    #[Test]
    public function test_the_bucketing_function_is_pinned_so_a_change_cannot_be_silent(): void
    {
        $this->assertSame(
            [1 => 11, 2 => 40, 3 => 58, 7 => 13, 42 => 24, 99 => 59, 1000 => 92],
            [
                1 => FeatureControl::bucket(self::FEATURE, 1),
                2 => FeatureControl::bucket(self::FEATURE, 2),
                3 => FeatureControl::bucket(self::FEATURE, 3),
                7 => FeatureControl::bucket(self::FEATURE, 7),
                42 => FeatureControl::bucket(self::FEATURE, 42),
                99 => FeatureControl::bucket(self::FEATURE, 99),
                1000 => FeatureControl::bucket(self::FEATURE, 1000),
            ],
            'The bucketing function changed. Every live gradual rollout is now a DIFFERENT set of '
            . 'stores, so a store inside a 10% rollout yesterday may be outside it today and lose '
            . 'the feature. If that is intended, say so in the change that does it and update '
            . 'these numbers deliberately -- do not re-measure them to make a red run green.'
        );
    }

    // ---------------------------------------------------------------------------------------------
    // property 2 -- monotonic
    // ---------------------------------------------------------------------------------------------

    /**
     * WIDENING A ROLLOUT ONLY ADDS. Asserted as a subset relation at every step from 0 to 100, so
     * the claim is about the whole ladder and not about one convenient pair.
     */
    #[Test]
    public function test_widening_a_rollout_never_removes_a_store_that_already_had_the_feature(): void
    {
        $previous = [];

        for ($percentage = 0; $percentage <= 100; $percentage += 5) {
            $current = $this->included(self::FEATURE, $percentage);

            $lost = array_diff($previous, $current);

            $this->assertSame(
                [],
                array_values($lost),
                "Widening the rollout to {$percentage}% REMOVED stores that already had the "
                . 'feature: ' . implode(', ', array_slice(array_values($lost), 0, 10)) . '. A '
                . 'gradual rollout that takes a feature away as it widens is worse than no '
                . 'rollout: the operator cannot continue it without breaking somebody.'
            );

            $previous = $current;
        }
    }

    #[Test]
    public function test_zero_percent_reaches_nobody_and_one_hundred_reaches_everybody(): void
    {
        $this->assertSame(
            [],
            $this->included(self::FEATURE, 0),
            'A 0% rollout reached a store. `bucket < percentage` with a bucket of 0 and a '
            . 'percentage of 0 must be false; a `<=` here would make "nobody" mean "1% of stores".'
        );

        $this->assertCount(
            self::SWEEP,
            $this->included(self::FEATURE, 100),
            'A 100% rollout did not reach every store. The highest bucket is 99, so 100 must '
            . 'include all of them -- otherwise an operator can never finish a rollout and has to '
            . 'switch the state to `on` to be sure, which loses the record of how it got there.'
        );
    }

    // ---------------------------------------------------------------------------------------------
    // property 3 -- decorrelated across flags
    // ---------------------------------------------------------------------------------------------

    /**
     * TWO FEATURES AT THE SAME PERCENTAGE MUST NOT PICK THE SAME STORES.
     *
     * The failure this guards is specific and nasty: with `store_id % 100`, or with any bucket that
     * ignores the flag key, the first 10% of EVERY rollout is the same 10% of merchants. Those
     * merchants become the platform's permanent test subjects — every half-finished feature, every
     * time — and nobody notices because each individual rollout looks fine.
     *
     * The threshold is deliberately loose. Two independent 10% samples of 1,000 stores overlap by
     * about 1% of them by chance, and the measured overlap is 16 stores; a shared-bucket
     * implementation would overlap by 95 or 122 — i.e. completely. So "fewer than half the smaller
     * set" separates the two cases by a wide margin and cannot flake, md5 being deterministic.
     */
    #[Test]
    public function test_two_features_at_the_same_percentage_select_different_stores(): void
    {
        $first = $this->included(self::FEATURE, 10);
        $second = $this->included(self::OTHER, 10);

        $this->assertNotEmpty($first, 'The first feature reached nobody, so there is nothing to compare.');
        $this->assertNotEmpty($second, 'The second feature reached nobody, so there is nothing to compare.');

        $overlap = count(array_intersect($first, $second));
        $smaller = min(count($first), count($second));

        $this->assertLessThan(
            $smaller / 2,
            $overlap,
            "Two different features at 10% selected substantially the same stores ({$overlap} of "
            . "{$smaller} shared). The bucket is not salted with the flag key, so the same "
            . 'merchants are the guinea pigs for every feature the platform ever ships.'
        );

        $this->assertNotSame(
            $first,
            $second,
            'Two different features selected the IDENTICAL store set, which is the unsalted '
            . 'bucket in its purest form.'
        );
    }

    // ---------------------------------------------------------------------------------------------
    // the percentage means roughly what it says
    // ---------------------------------------------------------------------------------------------

    /**
     * A PERCENTAGE AN OPERATOR CAN REASON ABOUT.
     *
     * NOT A STATISTICAL TEST AND CANNOT FLAKE: md5 is deterministic, so the four counts below are
     * fixed values of a fixed function over a fixed input range. The measured proportions over
     * 1,000 consecutive store ids are 1.2%, 12.2%, 28.7% and 52.7%; over 100,000 they are 1.038%,
     * 9.874% and 49.851%, which is what an unbiased hash looks like once the sample is big enough
     * to see it. The 5-point band is what the 1,000-id sweep supports, and the sweep is kept small
     * because this file is in the blocking Unit suite.
     */
    #[Test]
    public function test_a_percentage_reaches_roughly_that_share_of_stores(): void
    {
        foreach ([1, 10, 25, 50] as $percentage) {
            $share = count($this->included(self::FEATURE, $percentage)) / self::SWEEP * 100;

            $this->assertLessThanOrEqual(
                5.0,
                abs($share - $percentage),
                sprintf(
                    'A %d%% rollout reached %.1f%% of stores, which is more than 5 points out. '
                    . 'The percentage is the only number an operator sets, so it has to mean '
                    . 'roughly what it says.',
                    $percentage,
                    $share
                )
            );
        }
    }

    /**
     * THE KEY IS NORMALISED ON THE BUCKETING PATH TOO, not only on the storage path.
     *
     * If `bucket()` did not normalise, `Premium-Insights` and `premium-insights` would be ONE flag
     * in the table and TWO different rollouts in the resolver — so the same store would be inside
     * the rollout from one call site and outside it from another.
     */
    #[Test]
    public function test_the_bucket_does_not_depend_on_how_the_key_was_spelled(): void
    {
        for ($storeId = 1; $storeId <= 25; $storeId++) {
            $this->assertSame(
                FeatureControl::bucket(self::FEATURE, $storeId),
                FeatureControl::bucket('  Premium-INSIGHTS ', $storeId),
                "Store {$storeId} buckets differently for two spellings of one key. The table "
                . 'holds one flag, so the resolver must see one rollout.'
            );
        }
    }
}
