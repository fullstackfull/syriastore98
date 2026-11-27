<?php

namespace Tests\Feature\Control;

use App\Models\PlatformFeatureFlagTarget;
use App\Support\Control\FeatureControl;
use App\Support\Control\FeatureControlOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;

/**
 * WHAT THE OPERATOR'S SCREEN IS TOLD — `reach`, the one derived fact the whole page turns on.
 * =================================================================================================
 *
 * `CTRL-002` is "gradual rollout and safe disable", and the question an operator opens this screen
 * to answer is *how far has this got*. `reach` is that answer in one value, and it has SIX of them
 * because six are genuinely different situations. Five of the six were covered only indirectly
 * through the rendered page before this file: a surviving mutation that folded `nobody_yet` into
 * `off` changed nothing any test could see.
 *
 * THE DISTINCTION THAT MATTERS MOST, and the one a reasonable person would collapse: `off` and
 * `nobody_yet` both reach no store. They are not the same fact. `off` is a deliberate kill switch —
 * somebody decided; `nobody_yet` is a release that has not started. An operator looking for "which
 * features did we switch off during the incident" must not be shown features nobody ever released,
 * and an operator looking for "what is waiting to go out" must not be shown ones that were pulled.
 */
class FeatureControlOverviewTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const FEATURE = 'premium-insights';

    protected function setUp(): void
    {
        parent::setUp();

        FeatureControl::forget();
    }

    private function reachOf(string $key = self::FEATURE): string
    {
        $features = collect(FeatureControlOverview::features())->firstWhere('key', $key);

        $this->assertNotNull($features, "The overview does not carry the feature `{$key}` at all.");

        return (string) $features['reach'];
    }

    #[Test]
    public function test_a_switched_off_feature_reads_off(): void
    {
        FeatureControl::define(self::FEATURE, 'Premium insights');

        $this->assertSame('off', $this->reachOf(),
            'A newly declared feature is switched off, so its reach is `off`.');
    }

    #[Test]
    public function test_a_feature_on_for_everybody_reads_everyone(): void
    {
        FeatureControl::define(self::FEATURE, 'Premium insights');
        FeatureControl::releaseFully(self::FEATURE);

        $this->assertSame('everyone', $this->reachOf());
    }

    /**
     * THE DISTINCTION A MUTATION PROVED WAS UNTESTED: a release that has not started is not a
     * feature that was switched off.
     */
    #[Test]
    public function test_a_limited_feature_with_nothing_named_and_no_share_reads_nobody_yet(): void
    {
        FeatureControl::define(self::FEATURE, 'Premium insights');
        FeatureControl::release(self::FEATURE, 0);

        $this->assertSame(
            'nobody_yet',
            $this->reachOf(),
            'A limited feature at 0% with no named exception reads as `off`. It reaches no store, '
            . 'which is why the two are tempting to fold together -- but `off` is a decision '
            . 'somebody took and this is a release that has not started, and the screen exists to '
            . 'tell them apart.'
        );
    }

    #[Test]
    public function test_a_limited_feature_with_only_a_share_reads_share(): void
    {
        FeatureControl::define(self::FEATURE, 'Premium insights');
        FeatureControl::release(self::FEATURE, 25);

        $this->assertSame('share', $this->reachOf());
    }

    #[Test]
    public function test_a_limited_feature_with_only_named_exceptions_reads_targets_only(): void
    {
        $store = $this->makeStore($this->makeMerchant());

        FeatureControl::define(self::FEATURE, 'Premium insights');
        FeatureControl::release(self::FEATURE, 0);
        FeatureControl::allow(self::FEATURE, PlatformFeatureFlagTarget::TYPE_STORE, $store->id);

        $this->assertSame('targets_only', $this->reachOf());
    }

    #[Test]
    public function test_a_limited_feature_with_both_reads_share_and_targets(): void
    {
        $store = $this->makeStore($this->makeMerchant());

        FeatureControl::define(self::FEATURE, 'Premium insights');
        FeatureControl::release(self::FEATURE, 25);
        FeatureControl::allow(self::FEATURE, PlatformFeatureFlagTarget::TYPE_STORE, $store->id);

        $this->assertSame('share_and_targets', $this->reachOf());
    }

    /**
     * A DENY TARGET IS NOT AN ALLOW TARGET, and reading them as one would be the most natural
     * mistake in `reach()`: both are rows in `platform_feature_flag_targets`.
     *
     * A feature with a refusal and nothing else reaches nobody and has not started, so it must
     * still read `nobody_yet` — not `targets_only`, which would tell the operator that "only the
     * plans, stores and pilot groups named below have this feature" about a list containing one
     * refusal.
     */
    #[Test]
    public function test_a_refusal_alone_does_not_count_as_a_feature_being_reached(): void
    {
        $store = $this->makeStore($this->makeMerchant());

        FeatureControl::define(self::FEATURE, 'Premium insights');
        FeatureControl::release(self::FEATURE, 0);
        FeatureControl::deny(self::FEATURE, PlatformFeatureFlagTarget::TYPE_STORE, $store->id);

        $this->assertSame(
            'nobody_yet',
            $this->reachOf(),
            'A feature whose only named exception is a REFUSAL read as though somebody had been '
            . 'granted it. The screen would then say "only the plans, stores and pilot groups '
            . 'named below have this feature" above a list of one store that does not.'
        );
    }

    /**
     * THE COUNTS ARE PER EFFECT, and the overview reports them separately — "three stores are
     * refused this" and "two plans reach it" are different sentences and the screen prints both.
     */
    #[Test]
    public function test_the_counts_separate_what_reaches_a_feature_from_what_is_refused_it(): void
    {
        FeatureControl::define(self::FEATURE, 'Premium insights');
        FeatureControl::release(self::FEATURE, 0);
        FeatureControl::allow(self::FEATURE, PlatformFeatureFlagTarget::TYPE_PLAN, 1);
        FeatureControl::allow(self::FEATURE, PlatformFeatureFlagTarget::TYPE_PLAN, 2);
        FeatureControl::deny(self::FEATURE, PlatformFeatureFlagTarget::TYPE_STORE, 9);

        $feature = collect(FeatureControlOverview::features())->firstWhere('key', self::FEATURE);

        $this->assertSame(2, $feature['allowed']);
        $this->assertSame(1, $feature['refused']);
        $this->assertCount(3, $feature['targets']);
    }

    /**
     * EACH FEATURE CARRIES ITS OWN TARGETS AND NOBODY ELSE'S.
     *
     * The overview reads every flag's targets in ONE query and groups them in PHP, because a
     * per-flag query inside the loop is an N+1 whose N grows with every feature the platform ever
     * controls. The risk of grouping is that the grouping key is wrong — and with one flag in the
     * fixture that is invisible, which is why there are two here.
     */
    #[Test]
    public function test_two_features_do_not_share_each_others_targets(): void
    {
        FeatureControl::define('feature-one', 'One');
        FeatureControl::release('feature-one', 0);
        FeatureControl::allow('feature-one', PlatformFeatureFlagTarget::TYPE_PLAN, 1);

        FeatureControl::define('feature-two', 'Two');
        FeatureControl::release('feature-two', 0);
        FeatureControl::allow('feature-two', PlatformFeatureFlagTarget::TYPE_STORE, 7);
        FeatureControl::allow('feature-two', PlatformFeatureFlagTarget::TYPE_STORE, 8);

        $features = collect(FeatureControlOverview::features())->keyBy('key');

        $this->assertSame(
            [['type' => 'plan', 'value' => '1', 'effect' => 'allow']],
            $features['feature-one']['targets'],
            'The first feature carries targets that are not its own, so the grouping key is wrong.'
        );

        $this->assertCount(2, $features['feature-two']['targets']);
        $this->assertSame(1, $features['feature-one']['allowed']);
        $this->assertSame(2, $features['feature-two']['allowed']);
    }

    /**
     * THE CHANGE LOG'S PAYLOADS ARE FLATTENED TO `key=value`, NOT HANDED TO THE VIEW AS JSON.
     *
     * `{"state":"off","rollout_percentage":50}` on a screen is an operator reading JSON when they
     * wanted a sentence. This is the one piece of shaping the producer does to the log, and it is
     * asserted here rather than through the rendered page because the page would pass with the raw
     * JSON in it.
     */
    #[Test]
    public function test_a_change_log_entry_is_flattened_for_reading(): void
    {
        FeatureControl::define(self::FEATURE, 'Premium insights');
        FeatureControl::release(self::FEATURE, 10);

        $entry = collect(FeatureControlOverview::recentChanges())->firstWhere('action', 'rollout_changed');

        $this->assertNotNull($entry);
        $this->assertSame(['state=off', 'rollout_percentage=0'], $entry['before']);
        $this->assertSame(['state=limited', 'rollout_percentage=10'], $entry['after']);
    }

    /**
     * THE OVERVIEW SURVIVES AN ABSENT CONTROL PLANE, because the migrations are approved for
     * development and isolated testing only and first-party code may run without them. An empty
     * screen is the right answer; a 500 on the operator's dashboard link is not.
     */
    #[Test]
    public function test_the_overview_is_empty_rather_than_broken_without_the_tables(): void
    {
        FeatureControl::define(self::FEATURE, 'Premium insights');

        \Illuminate\Support\Facades\Schema::drop('platform_feature_flags');
        FeatureControl::forget();

        $this->assertSame([], FeatureControlOverview::features());
        $this->assertSame([], FeatureControlOverview::pilotGroups());
    }

    /** And the axes it offers a form are the model's, not a list retyped in a view. */
    #[Test]
    public function test_the_axes_offered_to_the_form_come_from_the_model(): void
    {
        $this->assertSame(PlatformFeatureFlagTarget::TARGET_TYPES, FeatureControlOverview::axes());
        $this->assertSame(PlatformFeatureFlagTarget::EFFECTS, FeatureControlOverview::effects());
    }

    /**
     * THE RAW STORED STATE IS CARRIED ALONGSIDE THE RESOLVED ONE.
     *
     * `resolvedState()` reports anything it does not recognise as `off`, which is correct for the
     * resolver and a confident lie on a screen. Production runs non-strict `sql_mode`, so a value
     * too long for `varchar(16)` is TRUNCATED rather than refused — this is the real shape.
     */
    #[Test]
    public function test_the_raw_stored_state_is_carried_so_the_screen_can_show_a_surprise(): void
    {
        FeatureControl::define(self::FEATURE, 'Premium insights');
        DB::table('platform_feature_flags')->where('key', self::FEATURE)->update(['state' => 'limite']);
        FeatureControl::forget();

        $feature = collect(FeatureControlOverview::features())->firstWhere('key', self::FEATURE);

        $this->assertSame('off', $feature['state'],
            'An unrecognised state must RESOLVE to off -- fail closed inside a declared flag.');
        $this->assertSame('limite', $feature['stored_state'],
            'The raw value is not carried, so the screen has nothing to show the operator and the '
            . 'feature appears to have been deliberately switched off.');
    }
}
