<?php

namespace Tests\Feature\Control;

use App\Models\PlatformFeatureFlagTarget;
use App\Models\Plan;
use App\Support\Control\FeatureControl;
use App\Support\Control\FeatureDecision;
use App\Support\Control\PlatformChangeLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;

/**
 * `CTRL-002` — "إيقاف آمن" : WHAT MAKES A DISABLE *SAFE*, AS FOUR SEPARATE CLAIMS.
 * =================================================================================================
 *
 * *"إطلاق تدريجي وإيقاف آمن"* — gradual rollout and safe disable.
 * `docs/development/master-plan/DECISION-ID-REGISTER.md:250`.
 *
 * "Safe" is not self-evident, so it is written down before it is asserted. A disable is safe when:
 *
 *  1. **IT IS TOTAL.** Nothing can partially undo it — not an allow-list, not a pilot group that
 *     still has members, not a rollout percentage left at 100. Asserted at the resolution level in
 *     `FeatureControlResolutionTest::test_off_refuses_a_store_that_every_other_rule_would_admit`,
 *     and here in the one place it could still leak: the memo.
 *  2. **IT TAKES EFFECT AT ONCE.** The resolver memoises per unit of work, so a decision already
 *     made in this request must not keep answering `Allowed` after the switch is thrown. CLAUDE.md
 *     records the live version of this hazard: `activated_modules_for_owner` kept letting an owner
 *     whose plan had lapsed through a gate until the queue worker restarted.
 *  3. **IT IS REVERSIBLE WITHOUT RECONSTRUCTION.** Resuming restores the EXACT reach, for every
 *     store, with no re-targeting work. An operator who has to rebuild a pilot group by hand after
 *     an incident will not use the switch during one — and a switch nobody uses in an incident is
 *     not a safe disable.
 *  4. **IT DELETES NOTHING.** Which is `CTRL-004` applied to the control plane itself, and the
 *     reason this file asserts row counts as well as decisions.
 */
class FeatureControlSafeDisableTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const FEATURE = 'premium-insights';

    protected function setUp(): void
    {
        parent::setUp();

        FeatureControl::forget();
    }

    private function plan(string $name): Plan
    {
        return Plan::create([
            'name' => $name, 'price' => 5, 'duration' => 'Month',
            'max_stores' => 5, 'max_products' => 100, 'max_users' => 5,
            'modules' => 'Marketing',
            'themes' => 'stylique', 'storage_limit' => '1024',
        ]);
    }

    /**
     * A rollout with all three axes in play plus a percentage, so "the exact reach" below is a
     * real claim and not one rule in isolation.
     *
     * @return array{plan: Plan, stores: array<string, int>}
     */
    private function aRolloutInProgress(): array
    {
        $plan = $this->plan('Premium');

        $onPlan = $this->makeMerchant();
        $onPlan->plan_id = $plan->id;
        $onPlan->save();

        $stores = [
            'by_plan' => $this->makeStore($onPlan)->id,
            'by_store' => $this->makeStore($this->makeMerchant())->id,
            'by_pilot' => $this->makeStore($this->makeMerchant())->id,
            'by_percentage' => null,
            'denied' => $this->makeStore($this->makeMerchant())->id,
            'outside' => null,
        ];

        FeatureControl::define(self::FEATURE, 'Premium insights');
        FeatureControl::release(self::FEATURE, 50);
        FeatureControl::allow(self::FEATURE, PlatformFeatureFlagTarget::TYPE_PLAN, $plan->id);
        FeatureControl::allow(self::FEATURE, PlatformFeatureFlagTarget::TYPE_STORE, $stores['by_store']);
        FeatureControl::addToPilotGroup('early-access', $stores['by_pilot']);
        FeatureControl::allow(self::FEATURE, PlatformFeatureFlagTarget::TYPE_PILOT_GROUP, 'early-access');
        FeatureControl::deny(self::FEATURE, PlatformFeatureFlagTarget::TYPE_STORE, $stores['denied']);

        /*
         * TWO STORES CHOSEN FOR WHICH SIDE OF THE 50% BUCKET THEY FALL ON, so the percentage limb
         * of "the exact reach" is really exercised. The buckets are pinned in
         * `tests/Unit/Control/GradualRolloutBucketTest`, so these are lookups rather than guesses:
         * store 2 buckets at 40 (inside 50%) and store 3 at 58 (outside).
         *
         * THE IDS ARE NOT ASSUMED. `RefreshDatabase` restarts the auto-increment, but other
         * fixtures above have already consumed ids -- so the stores are created and then SEARCHED
         * for a pair that straddles the threshold. CLAUDE.md records that a fixture must not be
         * able to cause the thing the test hunts for, and an assumed id is exactly that.
         */
        $owner = $this->makeMerchant();

        for ($i = 0; $i < 12 && ($stores['by_percentage'] === null || $stores['outside'] === null); $i++) {
            $candidate = $this->makeStore($owner)->id;
            $inside = FeatureControl::bucket(self::FEATURE, $candidate) < 50;

            if ($inside && $stores['by_percentage'] === null) {
                $stores['by_percentage'] = $candidate;
            } elseif (! $inside && $stores['outside'] === null) {
                $stores['outside'] = $candidate;
            }
        }

        $this->assertNotNull($stores['by_percentage'],
            'No fixture store fell INSIDE the 50% rollout in twelve tries, which is a 1-in-4096 '
            . 'accident or a broken bucket. Check GradualRolloutBucketTest first.');
        $this->assertNotNull($stores['outside'],
            'No fixture store fell OUTSIDE the 50% rollout in twelve tries. Same diagnosis.');

        return ['plan' => $plan, 'stores' => $stores];
    }

    /** @param array<string, int> $stores */
    private function reach(array $stores): array
    {
        $reach = [];

        foreach ($stores as $label => $storeId) {
            $reach[$label] = FeatureControl::decide(self::FEATURE, $storeId)->value;
        }

        return $reach;
    }

    // ---------------------------------------------------------------------------------------------
    // claim 2 -- it takes effect at once
    // ---------------------------------------------------------------------------------------------

    /**
     * THE MEMO IS THE ONE PLACE A TOTAL SWITCH CAN STILL LEAK.
     *
     * The decision is taken FIRST, so the memo is warm, and only then is the feature switched off.
     * Without the `forget()` inside `transition()` the second call answers out of the bag and the
     * kill switch does nothing for the rest of the request — which under PHP-FPM is one page and
     * in a queue worker is the whole job.
     */
    #[Test]
    public function test_a_disable_takes_effect_on_the_very_next_decision_in_the_same_request(): void
    {
        $fixture = $this->aRolloutInProgress();
        $store = $fixture['stores']['by_store'];

        $this->assertSame(
            FeatureDecision::Allowed,
            FeatureControl::decide(self::FEATURE, $store),
            'The premise fails: the store does not have the feature, so switching it off proves '
            . 'nothing about the memo.'
        );

        FeatureControl::disable(self::FEATURE);

        $this->assertSame(
            FeatureDecision::Denied,
            FeatureControl::decide(self::FEATURE, $store),
            'A decision taken BEFORE the disable was still being served afterwards. The resolver '
            . 'memoises per unit of work, so every write has to clear it -- otherwise the kill '
            . 'switch does nothing until the process restarts, which is the live hazard CLAUDE.md '
            . 'records for activated_modules_for_owner.'
        );
    }

    /** And the same for the subject memo: a pilot membership added mid-request is seen. */
    #[Test]
    public function test_a_pilot_membership_added_mid_request_is_seen_at_once(): void
    {
        $store = $this->makeStore($this->makeMerchant())->id;

        FeatureControl::define(self::FEATURE, 'Premium insights');
        FeatureControl::release(self::FEATURE, 0);
        FeatureControl::allow(self::FEATURE, PlatformFeatureFlagTarget::TYPE_PILOT_GROUP, 'early-access');

        $this->assertSame(
            FeatureDecision::Denied,
            FeatureControl::decide(self::FEATURE, $store),
            'The store already has the feature before being added to the pilot, so the assertion '
            . 'below cannot distinguish a working memo from a broken one.'
        );

        FeatureControl::addToPilotGroup('early-access', $store);

        $this->assertSame(
            FeatureDecision::Allowed,
            FeatureControl::decide(self::FEATURE, $store),
            'A pilot membership written in this request was not visible to the next decision. The '
            . 'SUBJECT is memoised per store as well as the flag per key, so a membership write '
            . 'has to clear the bag too.'
        );
    }

    // ---------------------------------------------------------------------------------------------
    // claim 3 -- reversible without reconstruction
    // ---------------------------------------------------------------------------------------------

    /**
     * THE CENTRAL CLAIM: disable, resume, and every store is exactly where it was.
     *
     * Six subjects, one per way a store can be reached or refused — by plan, by store, by pilot
     * group, by the rollout percentage, by an explicit deny, and by nothing at all. The reach is
     * captured as a map, the feature is switched off, the map is asserted to be all-denied, the
     * feature is resumed at the same percentage, and the ORIGINAL map must come back identical.
     *
     * Compared as whole maps rather than per store, deliberately: a per-store loop with a
     * `foreach` of assertions passes for five of six and reports only the first failure, while an
     * `assertSame` of two arrays prints the whole diff — which is what a person debugging a
     * mis-resumed rollout actually needs.
     */
    #[Test]
    public function test_a_disable_and_a_resume_restore_the_identical_reach(): void
    {
        $fixture = $this->aRolloutInProgress();
        $stores = $fixture['stores'];

        $before = $this->reach($stores);

        // THE PREMISE: the fixture really does exercise both answers.
        $this->assertContains('allowed', $before, 'No fixture store has the feature.');
        $this->assertContains('denied', $before, 'No fixture store is refused the feature.');

        FeatureControl::disable(self::FEATURE, null, 'An incident; switching it off.');

        $this->assertSame(
            array_fill_keys(array_keys($stores), 'denied'),
            $this->reach($stores),
            'A disabled feature still reached a store. Claim 1: a safe disable is TOTAL -- no '
            . 'allow-target, pilot membership or rollout percentage may partially undo it.'
        );

        FeatureControl::release(self::FEATURE, 50, null, 'Incident over; resuming where we were.');

        $this->assertSame(
            $before,
            $this->reach($stores),
            'Resuming the rollout did not restore the reach it had. Claim 3: an operator who has '
            . 'to rebuild a pilot group by hand after an incident will not use the switch DURING '
            . 'one, and a switch nobody uses in an incident is not a safe disable.'
        );
    }

    // ---------------------------------------------------------------------------------------------
    // claim 4 -- it deletes nothing
    // ---------------------------------------------------------------------------------------------

    /**
     * `CTRL-004` APPLIED TO THE CONTROL PLANE ITSELF.
     *
     * The reach-restoration test above would also pass if `disable()` deleted the targets and
     * `release()` happened to recreate them — it does not, but the test cannot tell. So the rows
     * are counted either side, and the percentage is read back off the table rather than inferred
     * from a decision.
     */
    #[Test]
    public function test_a_disable_writes_one_column_and_preserves_every_target_and_the_width(): void
    {
        $this->aRolloutInProgress();

        $targetsBefore = (int) DB::table('platform_feature_flag_targets')->count();
        $membersBefore = (int) DB::table('platform_pilot_group_members')->count();

        $this->assertGreaterThan(0, $targetsBefore, 'The fixture created no targets to preserve.');
        $this->assertGreaterThan(0, $membersBefore, 'The fixture created no pilot members to preserve.');

        FeatureControl::disable(self::FEATURE);

        $row = DB::table('platform_feature_flags')->where('key', self::FEATURE)->first();

        $this->assertSame('off', (string) $row->state, 'The disable did not switch the feature off.');

        $this->assertSame(
            50,
            (int) $row->rollout_percentage,
            'The disable cleared the rollout width. The width is the record of how far the rollout '
            . 'had got, and clearing it means the operator has to remember a number to resume -- '
            . 'which is configuration destroyed by an availability change.'
        );

        $this->assertSame(
            $targetsBefore,
            (int) DB::table('platform_feature_flag_targets')->count(),
            'The disable deleted targeting rows. CTRL-004 forbids deleting a feature\'s data '
            . 'because its availability was switched off, and the control plane is not exempt from '
            . 'its own decision.'
        );

        $this->assertSame(
            $membersBefore,
            (int) DB::table('platform_pilot_group_members')->count(),
            'The disable emptied a pilot group. Rebuilding one by hand is exactly the cost that '
            . 'stops an operator using the switch during an incident.'
        );
    }

    /** Switching an already-off feature off again changes nothing and is not an error. */
    #[Test]
    public function test_disabling_twice_is_idempotent(): void
    {
        $this->aRolloutInProgress();

        FeatureControl::disable(self::FEATURE);
        $first = DB::table('platform_feature_flags')->where('key', self::FEATURE)->first();

        FeatureControl::disable(self::FEATURE);
        $second = DB::table('platform_feature_flags')->where('key', self::FEATURE)->first();

        $this->assertSame((string) $first->state, (string) $second->state);
        $this->assertSame((int) $first->rollout_percentage, (int) $second->rollout_percentage);
    }

    /**
     * RE-DECLARING A FLAG DOES NOT SWITCH A LIVE FEATURE OFF.
     *
     * `define()` is idempotent on purpose, because a seeder, a deploy step or a console command may
     * run it again — and a redeploy that silently reset every flag to `off` would be an outage
     * caused by shipping. Only the label and description are refreshed.
     */
    #[Test]
    public function test_redeclaring_a_live_feature_does_not_switch_it_off(): void
    {
        $fixture = $this->aRolloutInProgress();
        $store = $fixture['stores']['by_store'];

        $this->assertSame(FeatureDecision::Allowed, FeatureControl::decide(self::FEATURE, $store));

        FeatureControl::define(self::FEATURE, 'Premium insights (renamed)', 'A new description.');

        $this->assertSame(
            FeatureDecision::Allowed,
            FeatureControl::decide(self::FEATURE, $store),
            'Re-declaring an existing flag reset its state. A deploy step that runs define() would '
            . 'then switch every live feature off on every release.'
        );

        $row = DB::table('platform_feature_flags')->where('key', self::FEATURE)->first();

        $this->assertSame('Premium insights (renamed)', (string) $row->label,
            'Re-declaring did not refresh the label, which is the one thing it is for.');
        $this->assertSame(50, (int) $row->rollout_percentage,
            'Re-declaring moved the rollout width.');
    }

    // ---------------------------------------------------------------------------------------------
    // the disable is on the record
    // ---------------------------------------------------------------------------------------------

    /**
     * A SWITCH NOBODY CAN ACCOUNT FOR AFTERWARDS IS NOT A SAFE SWITCH, which is why `CTRL-003`'s
     * change log sits in the same section of the plan as `CTRL-002`'s disable.
     */
    #[Test]
    public function test_a_disable_records_who_did_it_and_what_the_state_was(): void
    {
        $operator = $this->makePlatformAdmin(['name' => 'Platform Operator']);
        $this->aRolloutInProgress();

        FeatureControl::disable(self::FEATURE, $operator, 'Checkout errors spiked at 14:05.');

        $entry = PlatformChangeLog::forSubject(PlatformChangeLog::SUBJECT_FEATURE_FLAG, self::FEATURE)
            ->firstWhere('action', PlatformChangeLog::ACTION_DISABLED);

        $this->assertNotNull($entry, 'The disable was not recorded in the change log at all.');

        $this->assertSame(
            ['state' => 'limited', 'rollout_percentage' => 50],
            $entry->before,
            'The entry does not say what the feature was BEFORE it was switched off, so an '
            . 'operator resuming after an incident cannot read the width off the record.'
        );

        $this->assertSame(['state' => 'off', 'rollout_percentage' => 50], $entry->after);
        $this->assertSame($operator->id, $entry->actor_id);
        $this->assertSame('Platform Operator', $entry->actor_name);
        $this->assertSame('Checkout errors spiked at 14:05.', $entry->note);
    }
}
