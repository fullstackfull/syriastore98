<?php

namespace Tests\Feature\Control;

use App\Models\PlatformFeatureFlag;
use App\Models\PlatformFeatureFlagTarget;
use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use App\Support\Control\FeatureControl;
use App\Support\Control\FeatureDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;

/**
 * `CTRL-001` — FEATURE ENABLEMENT BY PLAN, BY STORE, AND BY PILOT GROUP.
 * =================================================================================================
 *
 * *"تفعيل الميزات حسب الباقات والمتاجر والمجموعات التجريبية"* —
 * `docs/development/master-plan/DECISION-ID-REGISTER.md:249`, Phase 06 §06-H.
 *
 * MEASURED BEFORE THIS WORK, and the measurement is why the whole family is MISSING FUNCTIONALITY
 * rather than missing verification:
 *
 * | axis | mechanism | verdict |
 * |---|---|---|
 * | the whole platform | `add_on_managers.is_enable` | exists, and is not one of the three |
 * | **by plan** | `plans.modules` → `user_active_modules` via `User::assignPlan()` | **existed** |
 * | **by merchant** | `user_active_modules.user_id`, the OWNER | exists, coarser than asked |
 * | **by store** | nothing | **absent** |
 * | **by pilot group** | nothing | **absent** |
 *
 * No migration in this repository creates a table carrying both a `store_id` and a module or
 * feature column, and `pilot`/`rollout`/`canary` appear nowhere in `app/`, `routes/`,
 * `database/migrations/` or `config/` outside prose. So one of the three axes existed, in a
 * different shape, and two did not exist at all. `CTRL-001` is not satisfied by any one of them.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT THIS FILE ASSERTS: the five-step resolution order, in BOTH directions for every step.
 *
 * The order is the requirement and not an implementation detail — "safe disable" is literally "step
 * 2 comes before step 3", and a test that only drove the happy path would be satisfied by an
 * implementation that read the allow-list first.
 */
class FeatureControlResolutionTest extends SecurityTestCase
{
    use RefreshDatabase;
    use \Tests\Support\StripsSourceComments;

    private const FEATURE = 'premium-insights';

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * THE RESOLVER MEMOISES PER UNIT OF WORK, IN THE CONTAINER. `RefreshDatabase` rebuilds the
         * application between tests so the bag is already fresh, but a fixture written straight
         * through Eloquent rather than through `FeatureControl`'s own writers would not clear it --
         * so the tests below go through the writers, and this is belt and braces for the two that
         * do not.
         */
        FeatureControl::forget();

        $this->desynchroniseTheIdSequences();
    }

    /**
     * A FIXTURE MUST NOT BE ABLE TO MAKE A WRONG LOOKUP LOOK RIGHT, AND THIS ONE COULD.
     *
     * FOUND BY A SURVIVING MUTATION, not by reading. Replacing the plan axis's owner lookup
     *
     *     $ownerId = DB::table('stores')->where('id', $storeId)->value('created_by');
     *
     * with the plainly wrong `$ownerId = $storeId` SURVIVED `test_a_feature_can_be_enabled_for_
     * every_store_on_a_plan`. The reason is arithmetic: `RefreshDatabase` restarts every
     * auto-increment, the test created merchant 1 then store 1 and merchant 2 then store 2, so
     * `created_by` and the store id were EQUAL for both subjects and reading the wrong column gave
     * the right answer.
     *
     * CLAUDE.md records the same class of artefact twice -- `PlanAssignmentCascadeSafetyTest`'s
     * note that a lone merchant has `created_by === $this->id` in a fresh database, and the fixture
     * reference that must not be able to spell `1970`. The rule it states is the one applied here:
     * a fixture must not be able to cause the thing the test hunts for.
     *
     * So the two sequences are pushed apart before every test: a platform operator (which is also
     * production's shape, where user 1 is the operator and no store belongs to them) plus two
     * store-less merchants. User ids therefore run three ahead of store ids, and `storeOnPlan()`
     * asserts the gap rather than trusting it.
     */
    private function desynchroniseTheIdSequences(): void
    {
        $this->makePlatformAdmin();
        $this->makeMerchant();
        $this->makeMerchant();
    }

    // ---------------------------------------------------------------------------------------------
    // fixtures
    // ---------------------------------------------------------------------------------------------

    /**
     * A merchant on a plan, with a store. Returns the store id.
     *
     * THE PLAN IS REACHED THROUGH THE STORE'S OWNER, which is the chain the resolver walks:
     * `stores.created_by` → `users.plan_id`. The fixture builds it that way round so that a change
     * to either link fails the test rather than being papered over by a convenience column.
     */
    private function storeOnPlan(?Plan $plan = null): array
    {
        $merchant = $this->makeMerchant();

        if ($plan instanceof Plan) {
            $merchant->plan_id = $plan->id;
            $merchant->save();
        }

        $store = $this->makeStore($merchant);

        $this->assertSame(
            $merchant->id,
            (int) DB::table('stores')->where('id', $store->id)->value('created_by'),
            'The fixture store is not owned by the fixture merchant, so the plan axis cannot be '
            . 'resolved and every plan assertion below would be vacuous.'
        );

        /*
         * THE GAP IS ASSERTED, NOT ASSUMED. See `desynchroniseTheIdSequences()`: with the two
         * sequences aligned, reading `users.plan_id` by the STORE id gives the right answer by
         * accident and a surviving mutation proved it.
         */
        $this->assertNotSame(
            $merchant->id,
            $store->id,
            'The fixture store id equals its owner\'s user id, so a plan lookup keyed on the wrong '
            . 'one cannot be distinguished from the right one. desynchroniseTheIdSequences() is '
            . 'supposed to prevent exactly this.'
        );

        return [$merchant, $store];
    }

    private function plan(string $name): Plan
    {
        return Plan::create([
            'name' => $name,
            'price' => 5,
            'duration' => 'Month',
            'max_stores' => 5,
            'max_products' => 100,
            'max_users' => 5,
            'modules' => 'Marketing',
            // The three NOT NULL columns with no default, read off PRAGMA table_info(plans).
            'themes' => 'stylique',
            'storage_limit' => '1024',
        ]);
    }

    // ---------------------------------------------------------------------------------------------
    // step 1 -- no flag at all
    // ---------------------------------------------------------------------------------------------

    /**
     * THE PROPERTY THAT MAKES THE WHOLE PLANE SAFE TO ADOPT ONE SURFACE AT A TIME.
     *
     * If an undeclared key answered `Denied`, retrofitting a flag call onto an existing surface
     * would hide that surface on every database where nobody had inserted a row yet — and CLAUDE.md
     * records what that failure looks like here: a redirect to the dashboard, HTTP 200, "which a
     * status-code assertion photographs as the screen".
     */
    #[Test]
    public function test_an_undeclared_feature_is_not_controlled_rather_than_off(): void
    {
        [, $store] = $this->storeOnPlan();

        $this->assertSame(
            FeatureDecision::NotControlled,
            FeatureControl::decide('nothing-declares-this', $store->id),
            'An undeclared feature key must answer NotControlled. Denied would make every surface '
            . 'that adopts a flag disappear until somebody remembered to insert a row; Allowed '
            . 'would make a typo enable a feature.'
        );

        $this->assertFalse(
            FeatureControl::enabled('nothing-declares-this', $store->id),
            'enabled() with no explicit default must fall back to false.'
        );

        $this->assertTrue(
            FeatureControl::enabled('nothing-declares-this', $store->id, true),
            'enabled() must honour the default the CALLER states for an uncontrolled key. A '
            . 'surface being retrofitted passes true so that nothing changes until a flag exists.'
        );
    }

    // ---------------------------------------------------------------------------------------------
    // step 2 -- off beats everything. CTRL-002's safe disable, at the resolution level.
    // ---------------------------------------------------------------------------------------------

    /**
     * `CTRL-002`'s SAFE DISABLE IS AN ORDERING CLAIM, so it is tested as one.
     *
     * The flag is switched off while an allow-target, a pilot membership and a 100% rollout all
     * still point at the store. Every one of those would make it `Allowed` if the state were read
     * after them. A disable that could be undone by a forgotten allow-list is not a kill switch.
     */
    #[Test]
    public function test_off_refuses_a_store_that_every_other_rule_would_admit(): void
    {
        $plan = $this->plan('Targeted plan');
        [, $store] = $this->storeOnPlan($plan);

        FeatureControl::define(self::FEATURE, 'Premium insights');
        FeatureControl::release(self::FEATURE, 100);
        FeatureControl::allow(self::FEATURE, PlatformFeatureFlagTarget::TYPE_STORE, $store->id);
        FeatureControl::allow(self::FEATURE, PlatformFeatureFlagTarget::TYPE_PLAN, $plan->id);
        FeatureControl::addToPilotGroup('early-access', $store->id);
        FeatureControl::allow(self::FEATURE, PlatformFeatureFlagTarget::TYPE_PILOT_GROUP, 'early-access');

        // THE PREMISE: with all of that in place and the feature not yet off, the store is in.
        $this->assertSame(
            FeatureDecision::Allowed,
            FeatureControl::decide(self::FEATURE, $store->id),
            'The fixture does not reach this store at all, so switching the feature off below '
            . 'would prove nothing about ordering.'
        );

        FeatureControl::disable(self::FEATURE);

        $this->assertSame(
            FeatureDecision::Denied,
            FeatureControl::decide(self::FEATURE, $store->id),
            'A disabled feature was still reachable. CTRL-002 asks for a SAFE disable, which means '
            . 'the off state is read BEFORE any allow-target, any pilot membership and any rollout '
            . 'percentage -- not after them.'
        );
    }

    /**
     * AN UNRECOGNISED `state` READS AS OFF. Production runs non-strict `sql_mode`, so a value too
     * long for `varchar(16)` is TRUNCATED rather than refused — and a truncated state must not read
     * as "on".
     *
     * Written with a raw `update`, because the writers cannot produce this value; the memo is
     * cleared explicitly for the reason CLAUDE.md gives about raw writes and `Cachable` reads
     * (`PlatformFeatureFlag` is deliberately not `Cachable`, but the resolver's own memo is a cache
     * all the same).
     */
    #[Test]
    public function test_a_state_the_code_does_not_recognise_is_treated_as_off(): void
    {
        [, $store] = $this->storeOnPlan();

        FeatureControl::define(self::FEATURE, 'Premium insights');
        FeatureControl::releaseFully(self::FEATURE);

        $this->assertSame(
            FeatureDecision::Allowed,
            FeatureControl::decide(self::FEATURE, $store->id),
            'The premise fails: the feature is not on, so corrupting the state below proves '
            . 'nothing.'
        );

        DB::table('platform_feature_flags')->where('key', self::FEATURE)->update(['state' => 'o']);
        FeatureControl::forget();

        $this->assertSame(
            FeatureDecision::Denied,
            FeatureControl::decide(self::FEATURE, $store->id),
            'A state value the code does not recognise was treated as something other than off. '
            . 'On production MySQL a non-strict sql_mode truncates rather than refuses, so this is '
            . 'the shape a too-long value really takes.'
        );
    }

    // ---------------------------------------------------------------------------------------------
    // step 3 -- deny beats on
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function test_a_denied_store_is_refused_even_while_the_feature_is_on_for_everyone(): void
    {
        [, $included] = $this->storeOnPlan();
        [, $excluded] = $this->storeOnPlan();

        FeatureControl::define(self::FEATURE, 'Premium insights');
        FeatureControl::releaseFully(self::FEATURE);
        FeatureControl::deny(self::FEATURE, PlatformFeatureFlagTarget::TYPE_STORE, $excluded->id);

        $this->assertSame(
            FeatureDecision::Allowed,
            FeatureControl::decide(self::FEATURE, $included->id),
            'A feature that is on for everyone must still reach a store nobody excluded. If this '
            . 'is Denied the deny-target is matching the wrong store.'
        );

        $this->assertSame(
            FeatureDecision::Denied,
            FeatureControl::decide(self::FEATURE, $excluded->id),
            '"Everyone except this store" is the operator need a deny-target exists for. Without '
            . 'it the only way to exclude one store is to take the feature from everybody.'
        );
    }

    /** A deny also beats an allow for the SAME subject, which is the contradictory-rules case. */
    #[Test]
    public function test_deny_beats_allow_for_the_same_store(): void
    {
        [, $store] = $this->storeOnPlan();

        FeatureControl::define(self::FEATURE, 'Premium insights');
        FeatureControl::release(self::FEATURE, 0);
        FeatureControl::allow(self::FEATURE, PlatformFeatureFlagTarget::TYPE_STORE, $store->id);

        $this->assertSame(
            FeatureDecision::Allowed,
            FeatureControl::decide(self::FEATURE, $store->id),
            'The allow-target is not reaching the store, so the deny below proves nothing.'
        );

        FeatureControl::deny(self::FEATURE, PlatformFeatureFlagTarget::TYPE_PLAN, 'nothing');
        FeatureControl::addToPilotGroup('blocked', $store->id);
        FeatureControl::deny(self::FEATURE, PlatformFeatureFlagTarget::TYPE_PILOT_GROUP, 'blocked');

        $this->assertSame(
            FeatureDecision::Denied,
            FeatureControl::decide(self::FEATURE, $store->id),
            'A store that an allow-target admits and a deny-target refuses must be refused. The '
            . 'safe direction for contradictory operator instructions is the restrictive one.'
        );
    }

    /**
     * RE-TARGETING THE SAME SUBJECT REPLACES THE RULE RATHER THAN STACKING A SECOND ONE.
     *
     * Without this, `allow` then `deny` on one store leaves two contradictory rows and the answer
     * depends on which the database returns first — a control plane whose behaviour is a race.
     * The unique index enforces it in the engine; this asserts the writer does not hand the caller
     * an integrity-constraint exception instead of the row.
     */
    #[Test]
    public function test_retargeting_one_subject_replaces_its_rule(): void
    {
        [, $store] = $this->storeOnPlan();

        FeatureControl::define(self::FEATURE, 'Premium insights');
        FeatureControl::releaseFully(self::FEATURE);

        FeatureControl::deny(self::FEATURE, PlatformFeatureFlagTarget::TYPE_STORE, $store->id);
        $this->assertSame(FeatureDecision::Denied, FeatureControl::decide(self::FEATURE, $store->id));

        FeatureControl::allow(self::FEATURE, PlatformFeatureFlagTarget::TYPE_STORE, $store->id);

        $this->assertSame(
            1,
            (int) DB::table('platform_feature_flag_targets')
                ->where('target_type', PlatformFeatureFlagTarget::TYPE_STORE)
                ->where('target_key', (string) $store->id)
                ->count(),
            'Re-targeting the same store stacked a second row. Two contradictory rows make the '
            . 'resolver\'s answer depend on row order.'
        );

        $this->assertSame(
            FeatureDecision::Allowed,
            FeatureControl::decide(self::FEATURE, $store->id),
            'Changing a deny into an allow did not take effect, so the earlier row is still being '
            . 'read.'
        );
    }

    // ---------------------------------------------------------------------------------------------
    // step 5 -- the three axes CTRL-001 names
    // ---------------------------------------------------------------------------------------------

    /** `CTRL-001`, axis 2: BY STORE. The axis that did not exist before this work. */
    #[Test]
    public function test_a_feature_can_be_enabled_for_one_store_and_not_its_sibling(): void
    {
        $merchant = $this->makeMerchant();
        $chosen = $this->makeStore($merchant);
        $sibling = $this->makeStore($merchant);

        FeatureControl::define(self::FEATURE, 'Premium insights');
        FeatureControl::release(self::FEATURE, 0);
        FeatureControl::allow(self::FEATURE, PlatformFeatureFlagTarget::TYPE_STORE, $chosen->id);

        $this->assertSame(
            FeatureDecision::Allowed,
            FeatureControl::decide(self::FEATURE, $chosen->id),
            'CTRL-001 asks for enablement BY STORE and the chosen store did not get it.'
        );

        $this->assertSame(
            FeatureDecision::Denied,
            FeatureControl::decide(self::FEATURE, $sibling->id),
            'TWO STORES OF THE SAME MERCHANT, and this is the whole reason the store axis needs a '
            . 'new table. `user_active_modules` is keyed on the OWNER, so it cannot tell these two '
            . 'apart: enabling for one would enable for both.'
        );
    }

    /** `CTRL-001`, axis 1: BY PLAN. Resolved through `stores.created_by` → `users.plan_id`. */
    #[Test]
    public function test_a_feature_can_be_enabled_for_every_store_on_a_plan(): void
    {
        $premium = $this->plan('Premium');
        $basic = $this->plan('Basic');

        [, $onPremium] = $this->storeOnPlan($premium);
        [, $onBasic] = $this->storeOnPlan($basic);

        FeatureControl::define(self::FEATURE, 'Premium insights');
        FeatureControl::release(self::FEATURE, 0);
        FeatureControl::allow(self::FEATURE, PlatformFeatureFlagTarget::TYPE_PLAN, $premium->id);

        $this->assertSame(
            FeatureDecision::Allowed,
            FeatureControl::decide(self::FEATURE, $onPremium->id),
            'A plan-targeted feature did not reach a store whose owner holds that plan. The chain '
            . 'is stores.created_by -> users.plan_id; check both links before changing the rule.'
        );

        $this->assertSame(
            FeatureDecision::Denied,
            FeatureControl::decide(self::FEATURE, $onBasic->id),
            'A plan-targeted feature reached a store on a DIFFERENT plan, so the plan is not '
            . 'actually being compared.'
        );
    }

    /**
     * A STORE WHOSE OWNER HOLDS NO PLAN MATCHES NO PLAN TARGET — including one keyed on `0`.
     *
     * `users.plan_id` IS NULL for a merchant who has never bought one. Measured: the column is
     * `nullable()` in both branches of `2014_10_12_000000_create_users_table.php` and NO first-party
     * code writes a literal `0` to it — every writer passes a real `$plan->id`, and the seeder
     * writes `'1'` or null. So this test drives the real default, and the `'0'`/`''` half of the
     * guard gets its own test below for the reason stated there.
     */
    #[Test]
    public function test_a_store_whose_owner_holds_no_plan_matches_no_plan_target(): void
    {
        [$merchant, $store] = $this->storeOnPlan();

        $this->assertNull(
            $merchant->fresh()->plan_id,
            'The fixture merchant already holds a plan, so this test is not measuring the no-plan '
            . 'case. Current value: ' . var_export($merchant->fresh()->plan_id, true)
        );

        FeatureControl::define(self::FEATURE, 'Premium insights');
        FeatureControl::release(self::FEATURE, 0);
        FeatureControl::allow(self::FEATURE, PlatformFeatureFlagTarget::TYPE_PLAN, 0);
        FeatureControl::allow(self::FEATURE, PlatformFeatureFlagTarget::TYPE_PLAN, 1);

        $this->assertSame(
            FeatureDecision::Denied,
            FeatureControl::decide(self::FEATURE, $store->id),
            'A plan target reached a merchant who holds NO plan. Every merchant who has never '
            . 'bought one would be swept in by a single target.'
        );
    }

    /**
     * AND THE SAME FOR A STORED `'0'` OR `''`, WHICH IS A DEFENSIVE GUARD AND IS SAID TO BE ONE.
     *
     * FOUND BY A SURVIVING MUTATION. Weakening the resolver's three-way check to a bare
     * `if ($planId !== null)` survived the test above — because the real default IS null, so the
     * `'0'` and `''` limbs were never reached by any test and the guard could not be distinguished
     * from its absence. CLAUDE.md's standing rule is that such code should be removed rather than
     * left to rot; it is kept here instead, for one measured reason, and then tested.
     *
     * THE REASON: `users.plan_id` has a TYPE DRIFT this repository already tracks —
     * `MigrationHygieneTest::test_plan_id_type_drift_remains_the_only_root_instance` exists for it,
     * and the two branches of the users migration declare it `unsignedBigInteger` and `string`
     * respectively. On the `string` branch `'0'` and `''` are storable values that mean "no plan",
     * and a resolver reporting `'0'` as a plan would let ONE target keyed `0` reach every such
     * merchant at once. Written raw, because no first-party writer produces it — which is exactly
     * why it needed a test rather than a comment.
     */
    #[Test]
    public function test_a_plan_id_stored_as_zero_or_blank_is_not_a_plan(): void
    {
        FeatureControl::define(self::FEATURE, 'Premium insights');
        FeatureControl::release(self::FEATURE, 0);
        FeatureControl::allow(self::FEATURE, PlatformFeatureFlagTarget::TYPE_PLAN, 0);
        FeatureControl::allow(self::FEATURE, PlatformFeatureFlagTarget::TYPE_PLAN, '');

        foreach (['0', ''] as $stored) {
            [$merchant, $store] = $this->storeOnPlan();

            DB::table('users')->where('id', $merchant->id)->update(['plan_id' => $stored]);

            $this->assertSame(
                $stored,
                (string) DB::table('users')->where('id', $merchant->id)->value('plan_id'),
                'The fixture value was not stored as written, so this iteration is not measuring '
                . 'the case it names. SQLite affinity may have coerced it.'
            );

            FeatureControl::forget();

            $this->assertSame(
                FeatureDecision::Denied,
                FeatureControl::decide(self::FEATURE, $store->id),
                "A merchant whose plan_id is stored as '" . $stored . "' matched a plan target. "
                . 'That value means "no plan" on the varchar branch of the measured plan_id type '
                . 'drift, so one target would reach every merchant carrying it.'
            );
        }
    }

    /** `CTRL-001`, axis 3: BY PILOT GROUP. The other axis that did not exist. */
    #[Test]
    public function test_a_feature_can_be_enabled_for_a_pilot_group(): void
    {
        [, $inPilot] = $this->storeOnPlan();
        [, $outside] = $this->storeOnPlan();

        FeatureControl::addToPilotGroup('early-access', $inPilot->id);

        FeatureControl::define(self::FEATURE, 'Premium insights');
        FeatureControl::release(self::FEATURE, 0);
        FeatureControl::allow(self::FEATURE, PlatformFeatureFlagTarget::TYPE_PILOT_GROUP, 'early-access');

        $this->assertSame(
            FeatureDecision::Allowed,
            FeatureControl::decide(self::FEATURE, $inPilot->id),
            'CTRL-001 asks for enablement BY PILOT GROUP and a member did not get the feature.'
        );

        $this->assertSame(
            FeatureDecision::Denied,
            FeatureControl::decide(self::FEATURE, $outside->id),
            'A store outside the pilot group received the feature, so membership is not being '
            . 'compared.'
        );

        $this->assertSame(
            [$inPilot->id],
            FeatureControl::pilotGroupMembers('early-access'),
            'The membership the operator screen reads back does not match what was written.'
        );
    }

    /**
     * A PILOT GROUP'S SLUG IS CASE-INSENSITIVE, ON BOTH SIDES.
     *
     * MySQL's default collation is case-insensitive and SQLite's `=` is not, so a slug normalised on
     * only one side would make `Early-Access` and `early-access` ONE group in production and TWO
     * under the suite. CLAUDE.md records that SQLite's affinity hiding a real MySQL difference is
     * how P01-SEC-13 survived, so the normalisation is at the single write door and this asserts
     * both directions agree.
     */
    #[Test]
    public function test_a_pilot_groups_slug_is_matched_without_regard_to_case(): void
    {
        [, $store] = $this->storeOnPlan();

        FeatureControl::addToPilotGroup('  Early-Access  ', $store->id);

        $this->assertSame(
            'early-access',
            (string) DB::table('platform_pilot_group_members')->where('store_id', $store->id)->value('pilot_group'),
            'The slug was stored without normalisation, so production MySQL and the SQLite suite '
            . 'would disagree about whether two spellings are one group.'
        );

        FeatureControl::define(self::FEATURE, 'Premium insights');
        FeatureControl::release(self::FEATURE, 0);
        FeatureControl::allow(self::FEATURE, PlatformFeatureFlagTarget::TYPE_PILOT_GROUP, 'EARLY-ACCESS');

        $this->assertSame(
            FeatureDecision::Allowed,
            FeatureControl::decide(self::FEATURE, $store->id),
            'A target spelled in a different case did not match a membership written in another. '
            . 'Both sides go through the same normalisation for exactly this reason.'
        );
    }

    /** The feature key itself is case-insensitive and whitespace-tolerant, for the same reason. */
    #[Test]
    public function test_a_feature_key_is_matched_without_regard_to_case_or_padding(): void
    {
        [, $store] = $this->storeOnPlan();

        FeatureControl::define('  Premium-Insights ', 'Premium insights');
        FeatureControl::releaseFully('PREMIUM-INSIGHTS');

        $this->assertSame(
            'premium-insights',
            (string) DB::table('platform_feature_flags')->value('key'),
            'The key was stored without normalisation.'
        );

        $this->assertSame(
            1,
            (int) DB::table('platform_feature_flags')->count(),
            'Two spellings of one key produced two flags. An availability control with two rows '
            . 'for one feature answers whichever the database returns first.'
        );

        $this->assertSame(
            FeatureDecision::Allowed,
            FeatureControl::decide('Premium-Insights', $store->id)
        );
    }

    /**
     * THE ROLLOUT BOUNDARY IS EXCLUSIVE, PINNED THROUGH `decide()` AND NOT THROUGH `bucket()`.
     *
     * `GradualRolloutBucketTest` asserts that 0% reaches nobody — but it does so with its OWN
     * `bucket(...) < percentage` comparison, so it cannot see the resolver's. A `<=` inside
     * `decide()` would make "nobody" mean "every store in bucket 0", i.e. about 1% of the platform,
     * and would survive that whole file. Measured as a gap while writing the mutation list, and
     * closed here where the resolver is the thing being driven.
     *
     * The store's own bucket is read rather than assumed, so the test works whatever ids the
     * fixtures happen to take.
     */
    #[Test]
    public function test_the_rollout_boundary_is_exclusive_through_the_resolver(): void
    {
        [, $store] = $this->storeOnPlan();

        FeatureControl::define(self::FEATURE, 'Premium insights');

        $bucket = FeatureControl::bucket(self::FEATURE, $store->id);

        FeatureControl::release(self::FEATURE, $bucket);

        $this->assertSame(
            FeatureDecision::Denied,
            FeatureControl::decide(self::FEATURE, $store->id),
            "The store is in bucket {$bucket} and a {$bucket}% rollout admitted it. The comparison "
            . 'must be `bucket < percentage`: with `<=`, a 0% rollout reaches every store in '
            . 'bucket 0 -- about 1% of the platform -- and "nobody" stops meaning nobody.'
        );

        FeatureControl::release(self::FEATURE, $bucket + 1);

        $this->assertSame(
            FeatureDecision::Allowed,
            FeatureControl::decide(self::FEATURE, $store->id),
            "The store is in bucket {$bucket} and did not join at " . ($bucket + 1) . '%. The '
            . 'boundary is off by one in the other direction, so a store can never be reached by '
            . 'the percentage that is supposed to include it.'
        );
    }

    /**
     * A `target_type` THE CODE DOES NOT RECOGNISE MUST DO NOTHING — in BOTH directions.
     *
     * The writer refuses an unknown axis at the door, so this value can only arrive by a hand-
     * edited row or a truncating column. It must not become an allow (a feature nobody granted) and
     * it must not become a deny (a silent platform-wide refusal, which CLAUDE.md records as the
     * failure that photographs as a working screen). Written raw, because the writer will not
     * produce it.
     */
    #[Test]
    public function test_a_target_type_the_code_does_not_recognise_neither_allows_nor_denies(): void
    {
        [, $store] = $this->storeOnPlan();

        FeatureControl::define(self::FEATURE, 'Premium insights');
        FeatureControl::release(self::FEATURE, 0);

        $flagId = (int) DB::table('platform_feature_flags')->where('key', self::FEATURE)->value('id');

        DB::table('platform_feature_flag_targets')->insert([
            'feature_flag_id' => $flagId,
            'target_type' => 'country',
            'target_key' => (string) $store->id,
            'effect' => PlatformFeatureFlagTarget::EFFECT_ALLOW,
        ]);
        FeatureControl::forget();

        $this->assertSame(
            FeatureDecision::Denied,
            FeatureControl::decide(self::FEATURE, $store->id),
            'A target on an axis the code does not recognise GRANTED the feature. An unknown type '
            . 'must match nothing -- otherwise a truncated or hand-edited row enables a feature '
            . 'nobody targeted.'
        );

        FeatureControl::releaseFully(self::FEATURE);

        DB::table('platform_feature_flag_targets')->where('target_type', 'country')
            ->update(['effect' => PlatformFeatureFlagTarget::EFFECT_DENY]);
        FeatureControl::forget();

        $this->assertSame(
            FeatureDecision::Allowed,
            FeatureControl::decide(self::FEATURE, $store->id),
            'A target on an unknown axis REFUSED the feature. That is the more dangerous '
            . 'direction: a silently denied surface answers HTTP 200 on a redirect and a '
            . 'status-code assertion photographs it as the screen.'
        );
    }

    /**
     * EACH FLAG ANSWERS FOR ITSELF.
     *
     * A resolver that ignored the key — `->first()` instead of `->where('key', $key)->first()` —
     * would pass every single-flag test in this file. Two flags in opposite states is the only
     * shape that can see it, and this codebase will carry dozens at once.
     */
    #[Test]
    public function test_each_flag_answers_for_itself(): void
    {
        [, $store] = $this->storeOnPlan();

        FeatureControl::define('feature-on', 'On for everybody');
        FeatureControl::releaseFully('feature-on');

        FeatureControl::define('feature-off', 'Switched off');
        FeatureControl::disable('feature-off');

        $this->assertSame(
            [
                'feature-on' => FeatureDecision::Allowed,
                'feature-off' => FeatureDecision::Denied,
            ],
            [
                'feature-on' => FeatureControl::decide('feature-on', $store->id),
                'feature-off' => FeatureControl::decide('feature-off', $store->id),
            ],
            'Two flags in opposite states did not answer independently. A resolver that reads the '
            . 'first row rather than the row for the key passes every single-flag test there is.'
        );

        /*
         * AND THEIR TARGETS DO NOT CROSS EITHER. A `targets()` query missing its
         * `feature_flag_id` predicate reads every flag's targets, so a deny meant for one feature
         * would refuse another.
         */
        FeatureControl::deny('feature-off', PlatformFeatureFlagTarget::TYPE_STORE, $store->id);

        $this->assertSame(
            FeatureDecision::Allowed,
            FeatureControl::decide('feature-on', $store->id),
            'A deny-target belonging to ANOTHER feature refused this one. The targets query is '
            . 'missing its feature_flag_id predicate.'
        );
    }

    /** A percentage outside 0-100 is clamped rather than refused, and stored clamped. */
    #[Test]
    public function test_a_percentage_outside_the_range_is_clamped(): void
    {
        FeatureControl::define(self::FEATURE, 'Premium insights');

        FeatureControl::release(self::FEATURE, 150);

        $this->assertSame(
            100,
            (int) DB::table('platform_feature_flags')->where('key', self::FEATURE)->value('rollout_percentage'),
            'A percentage above 100 was stored unclamped. `unsignedTinyInteger` holds 150 happily, '
            . 'and on production\'s non-strict sql_mode a larger value is truncated to 255 rather '
            . 'than refused -- so the column is not the guard. An exception is the wrong answer '
            . 'here: a screen posting 150 means "everybody", and an availability control that '
            . 'throws is worse than one that does the obvious thing.'
        );

        FeatureControl::release(self::FEATURE, -20);

        $this->assertSame(
            0,
            (int) DB::table('platform_feature_flags')->where('key', self::FEATURE)->value('rollout_percentage'),
            'A negative percentage was stored unclamped. `unsignedTinyInteger` would wrap or '
            . 'refuse it depending on sql_mode, and a negative width compared with `<` reaches '
            . 'nobody by accident rather than by design.'
        );
    }

    // ---------------------------------------------------------------------------------------------
    // a caller with no store
    // ---------------------------------------------------------------------------------------------

    /**
     * NO SUBJECT IS NOT A COIN FLIP.
     *
     * The operator's own screens, a console command, a queued job and a merchant with no store at
     * all (`getCurrentStore()` really does return null for one — CLAUDE.md records it as a supported
     * product state) all ask without a store. A percentage of nothing must be `Denied`, not a 50/50.
     */
    #[Test]
    public function test_a_percentage_rollout_denies_a_caller_that_names_no_store(): void
    {
        FeatureControl::define(self::FEATURE, 'Premium insights');
        FeatureControl::release(self::FEATURE, 100);

        $this->assertSame(
            FeatureDecision::Denied,
            FeatureControl::decide(self::FEATURE, null),
            'A 100% rollout answered for a caller with no store. A percentage needs a subject to '
            . 'bucket; without one the honest answer is Denied, not a guess.'
        );

        // `on` is a different question and DOES answer without a store.
        FeatureControl::releaseFully(self::FEATURE);

        $this->assertSame(
            FeatureDecision::Allowed,
            FeatureControl::decide(self::FEATURE, null),
            'A feature that is on for everyone must answer for a caller with no store too -- '
            . 'otherwise an operator screen could never show its own feature as enabled.'
        );
    }

    /**
     * "NO SUBJECT" MEANS THE SAME THING TO ALL FOUR LIMBS, INCLUDING A STORE ID OF ZERO.
     *
     * FOUND BY READING, not by a mutation, and it was a real inconsistency: `subject()` treats a
     * non-positive store id as no subject, so `decide($key, 0)` matched no store, plan or pilot
     * target — while the rollout percentage was guarded by `!== null` and `bucket($key, 0)` is a
     * perfectly good number. Store 0 was therefore refused by every NAMED rule and admitted by the
     * unnamed one, for any flag whose share happened to cover its bucket.
     *
     * A caller reaches it by casting. `getCurrentStore()` answers null for a merchant with no store
     * — CLAUDE.md records that as a supported product state with its own fixture — and `(int) null`
     * is 0. The rollout is at 100% here so the case cannot pass by luck of the bucket.
     */
    #[Test]
    public function test_a_store_id_of_zero_is_no_subject_to_the_percentage_as_well(): void
    {
        FeatureControl::define(self::FEATURE, 'Premium insights');
        FeatureControl::release(self::FEATURE, 100);

        foreach ([0, -1] as $storeId) {
            $this->assertSame(
                FeatureDecision::Denied,
                FeatureControl::decide(self::FEATURE, $storeId),
                "A 100% rollout admitted store id {$storeId}. `subject()` treats a non-positive id "
                . 'as no subject, so every named rule already refuses it -- the percentage must '
                . 'agree, or "no subject" means two different things inside one decision.'
            );
        }

        /* CONTROL: a real store at 100% IS admitted, so the assertion above is not vacuous. */
        $store = $this->makeStore($this->makeMerchant());

        $this->assertSame(
            FeatureDecision::Allowed,
            FeatureControl::decide(self::FEATURE, $store->id),
            'A 100% rollout reached no real store either, so the case above proves nothing.'
        );
    }

    // ---------------------------------------------------------------------------------------------
    // the premise that the plane changes nothing that existed before it
    // ---------------------------------------------------------------------------------------------

    /**
     * THE EXISTING THREE MECHANISMS ARE UNTOUCHED, and that is asserted rather than asserted-in-prose.
     *
     * The whole adoption argument rests on it: `module_is_active()`, `ActivatedModule()`,
     * `add_on_managers` and `user_active_modules` must behave exactly as they did, so a surface can
     * be migrated to the new plane one at a time instead of all at once. A census over the source
     * is the only check that can see a future edit wiring this class into that path by accident.
     */
    #[Test]
    public function test_the_control_plane_is_not_wired_into_the_existing_module_gate(): void
    {
        /*
         * READ WITHOUT COMMENTS. CLAUDE.md records this census failing EIGHT times because prose
         * about the thing being measured satisfied or broke it -- and the two helpers this change
         * adds at the bottom of helper.php have docblocks that name FeatureControl repeatedly.
         */
        $code = $this->sourceWithoutComments('app/Helper/helper.php');

        $this->assertStringContainsString('function module_is_active', $code,
            'The gate this census measures is not in the file any more, so the assertions below '
            . 'would pass over nothing. Find where module_is_active() moved to.');

        $moduleGate = substr($code, (int) strpos($code, 'function module_is_active'));
        $moduleGate = substr($moduleGate, 0, (int) strpos($moduleGate, 'function activated_modules_for_owner'));

        $this->assertStringNotContainsString(
            'FeatureControl',
            $moduleGate,
            '`module_is_active()` now consults the feature control plane. That is a behaviour '
            . 'change across hundreds of call sites and it is not what CTRL-001 asks for: the '
            . 'plane ADDS the store and pilot-group axes, it does not replace the module gate. '
            . 'If this is intended it needs its own measurement of what every module gate then '
            . 'answers, not a one-line call.'
        );

        $this->assertStringNotContainsString(
            'platform_feature_enabled',
            $moduleGate,
            'See above: the module gate must not consult the new plane.'
        );
    }

    /**
     * AND THE MODEL CONSTANTS ARE THE THREE AXES `CTRL-001` NAMES, NO MORE AND NO FEWER.
     *
     * A fourth axis is a scope change: the approved text names plans, stores and pilot groups.
     * Adding "by country" or "by merchant" would be inventing a requirement, and removing one would
     * be silently reducing approved scope — which CLAUDE.md §4.1 forbids outright.
     */
    #[Test]
    public function test_the_target_axes_are_exactly_the_three_the_decision_names(): void
    {
        $this->assertSame(
            ['pilot_group', 'plan', 'store'],
            collect(PlatformFeatureFlagTarget::TARGET_TYPES)->sort()->values()->all(),
            'CTRL-001 names exactly three axes: "حسب الباقات والمتاجر والمجموعات التجريبية" -- by '
            . 'plan, by store, by pilot group. A fourth invents a requirement; a missing one '
            . 'reduces approved scope.'
        );

        $this->assertSame(
            ['limited', 'off', 'on'],
            collect(PlatformFeatureFlag::STATES)->sort()->values()->all(),
            'The three states are what makes "on for a pilot group only" expressible at all. '
            . 'Collapsing them to a boolean would remove CTRL-001; adding a fourth needs a reason '
            . 'the decision gives.'
        );
    }

    /**
     * A WRITE TO A DATABASE WITH NO CONTROL PLANE REFUSES; A READ ANSWERS `NotControlled`.
     *
     * The migrations are approved for development and isolated testing only, so first-party code
     * really may run against a database without them. The asymmetry is the only safe pair: a read
     * has to work everywhere, and a write that returned quietly would tell an operator their kill
     * switch is armed when nothing was stored.
     */
    #[Test]
    public function test_a_read_survives_an_absent_control_plane_and_a_write_refuses(): void
    {
        [, $store] = $this->storeOnPlan();

        FeatureControl::define(self::FEATURE, 'Premium insights');
        FeatureControl::releaseFully(self::FEATURE);

        \Illuminate\Support\Facades\Schema::drop('platform_feature_flags');
        FeatureControl::forget();

        $this->assertSame(
            FeatureDecision::NotControlled,
            FeatureControl::decide(self::FEATURE, $store->id),
            'A read against a database with no control plane must answer NotControlled, so every '
            . 'caller falls back to its own default rather than 500ing.'
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('platform feature control tables are not present');

        FeatureControl::disable(self::FEATURE);
    }
}
