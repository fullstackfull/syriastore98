<?php

namespace Tests\Feature\Control;

use App\Models\Customer;
use App\Models\PlatformControlChange;
use App\Models\PlatformFeatureFlagTarget;
use App\Support\Control\FeatureControl;
use App\Support\Control\PlatformChangeLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;

/**
 * `CTRL-003`, LIMB THREE — "سجل تغييرات" : THE PLATFORM'S CHANGE LOG.
 * =================================================================================================
 *
 * *"سياسات عامة وإعلانات صيانة وسجل تغييرات"* — public policies, maintenance announcements and a
 * change log. `docs/development/master-plan/DECISION-ID-REGISTER.md:251`, Phase 06 §06-H.
 *
 * `CTRL-003` SPLITS THREE WAYS AND ONLY ONE OF THEM IS THIS FILE, which is stated here so nobody
 * reads a green run as the whole decision:
 *
 *  - **public policies** — the MECHANISM exists and was measured, not built here:
 *    `App\Support\Platform\PlatformContent::LEGAL_DOCUMENTS` carries five documents (terms of
 *    service, privacy notice, acceptable use, subscription/billing/refunds, customer-data
 *    handling), each a `markdown` field marked `owed => true`, i.e. awaiting the owner's own words.
 *    `PlatformContentWriteGuard` already restricts writing them to the platform operator. **The
 *    TEXT is the owner's and is not invented.**
 *  - **maintenance announcements** — a separate surface, measured ABSENT: `announcement` occurs
 *    nowhere in `app/`, `routes/` or `database/migrations/` in that sense, and Laravel's own
 *    `PreventRequestsDuringMaintenance` is `php artisan down` — an outage, not an announcement, and
 *    not reachable from any operator screen.
 *  - **a change log** — this.
 *
 * WHY NOT `audit_logs`, which exists and already has a `record()` helper: it is `IAM-004`'s central
 * audit log for incident response, per-tenant and per-actor, with `store_id`, `ip_address` and
 * `user_agent`. `CTRL-003`'s change log is in the *platform control* section beside the feature
 * flags, and its subject is the platform's own configuration. The migration's docblock carries the
 * full argument.
 */
class PlatformChangeLogTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const FEATURE = 'premium-insights';

    protected function setUp(): void
    {
        parent::setUp();

        FeatureControl::forget();
    }

    // ---------------------------------------------------------------------------------------------
    // every control change is on the record
    // ---------------------------------------------------------------------------------------------

    /**
     * ONE ENTRY PER CHANGE, IN THE ORDER THE CHANGES HAPPENED, WITH THE RIGHT ACTION EACH TIME.
     *
     * Asserted as a whole sequence rather than one entry at a time. A per-change assertion is
     * satisfied by an implementation that records every change as `defined`, and by one that
     * records some changes twice; the sequence sees both.
     */
    #[Test]
    public function test_the_whole_life_of_a_feature_is_on_the_record(): void
    {
        $store = $this->makeStore($this->makeMerchant())->id;

        FeatureControl::define(self::FEATURE, 'Premium insights');
        FeatureControl::release(self::FEATURE, 10);
        FeatureControl::allow(self::FEATURE, PlatformFeatureFlagTarget::TYPE_STORE, $store);
        FeatureControl::release(self::FEATURE, 50);
        FeatureControl::withdraw(self::FEATURE, PlatformFeatureFlagTarget::TYPE_STORE, $store);
        FeatureControl::releaseFully(self::FEATURE);
        FeatureControl::disable(self::FEATURE);

        $actions = PlatformControlChange::query()->orderBy('id')->pluck('action')->all();

        $this->assertSame(
            [
                PlatformChangeLog::ACTION_DEFINED,
                PlatformChangeLog::ACTION_ROLLOUT_CHANGED,
                PlatformChangeLog::ACTION_TARGET_ADDED,
                PlatformChangeLog::ACTION_ROLLOUT_CHANGED,
                PlatformChangeLog::ACTION_TARGET_WITHDRAWN,
                PlatformChangeLog::ACTION_ENABLED,
                PlatformChangeLog::ACTION_DISABLED,
            ],
            $actions,
            'The change log does not read as the life of the feature. CTRL-003 asks for a change '
            . 'log; a log that cannot be read back as a sequence of what the operator did is a '
            . 'table of rows, not a log.'
        );
    }

    /**
     * A ROLLOUT WIDENING SAYS WHAT IT WIDENED FROM.
     *
     * The whole value of `before`/`after` on this table: "the rollout went from 10% to 50% on
     * Tuesday" has to be readable off ONE row, because that is the question asked after an
     * incident.
     */
    #[Test]
    public function test_a_rollout_change_records_both_widths(): void
    {
        FeatureControl::define(self::FEATURE, 'Premium insights');
        FeatureControl::release(self::FEATURE, 10);
        FeatureControl::release(self::FEATURE, 50);

        $entries = PlatformChangeLog::forSubject(PlatformChangeLog::SUBJECT_FEATURE_FLAG, self::FEATURE)
            ->where('action', PlatformChangeLog::ACTION_ROLLOUT_CHANGED)
            ->values();

        $this->assertCount(2, $entries, 'Two rollout changes were made and this is not two entries.');

        // `forSubject()` is newest-first.
        $this->assertSame(['state' => 'limited', 'rollout_percentage' => 10], $entries[0]->before);
        $this->assertSame(['state' => 'limited', 'rollout_percentage' => 50], $entries[0]->after);

        $this->assertSame(['state' => 'off', 'rollout_percentage' => 0], $entries[1]->before,
            'The FIRST release should say it came from a freshly-defined flag: off, 0%. If this '
            . 'reads limited/10 the before-snapshot is being taken after the write.');
    }

    /** A target change records which axis and which key, so "who did we let in" is answerable. */
    #[Test]
    public function test_a_target_change_records_the_axis_and_the_key(): void
    {
        FeatureControl::define(self::FEATURE, 'Premium insights');
        FeatureControl::allow(self::FEATURE, PlatformFeatureFlagTarget::TYPE_PILOT_GROUP, 'Early-Access');

        $entry = PlatformChangeLog::forSubject(PlatformChangeLog::SUBJECT_FEATURE_TARGET, self::FEATURE)->first();

        $this->assertNotNull($entry);
        $this->assertNull($entry->before, 'A newly added target has no before-state.');
        $this->assertSame(
            ['target_type' => 'pilot_group', 'target_key' => 'Early-Access', 'effect' => 'allow'],
            $entry->after
        );

        // Changing the effect records the previous one, which is the audit question that matters.
        FeatureControl::deny(self::FEATURE, PlatformFeatureFlagTarget::TYPE_PILOT_GROUP, 'Early-Access');

        $latest = PlatformChangeLog::forSubject(PlatformChangeLog::SUBJECT_FEATURE_TARGET, self::FEATURE)->first();

        $this->assertSame('allow', $latest->before['effect'] ?? null,
            'Flipping an allow into a deny did not record that it had been an allow.');
        $this->assertSame('deny', $latest->after['effect'] ?? null);
    }

    /** Pilot membership is recorded both ways, so a removal leaves a trace of the membership. */
    #[Test]
    public function test_pilot_membership_is_recorded_in_both_directions(): void
    {
        $store = $this->makeStore($this->makeMerchant())->id;

        FeatureControl::addToPilotGroup('early-access', $store);
        FeatureControl::removeFromPilotGroup('early-access', $store);

        $entries = PlatformChangeLog::forSubject(PlatformChangeLog::SUBJECT_PILOT_GROUP, 'early-access')->values();

        $this->assertSame(
            [PlatformChangeLog::ACTION_MEMBER_REMOVED, PlatformChangeLog::ACTION_MEMBER_ADDED],
            $entries->pluck('action')->all(),
            'A pilot membership that was added and then removed must leave two entries. The '
            . 'membership ROW is gone -- that is the operator changing who the pilot reaches, not '
            . 'a CTRL-004 deletion -- so the log is the only remaining evidence it existed.'
        );

        $this->assertSame(['store_id' => $store], $entries[0]->before);
        $this->assertNull($entries[0]->after);
    }

    // ---------------------------------------------------------------------------------------------
    // the actor
    // ---------------------------------------------------------------------------------------------

    /** A snapshot of the name, so the entry still reads correctly after a rename. */
    #[Test]
    public function test_the_actors_name_is_a_snapshot_and_survives_a_rename(): void
    {
        $operator = $this->makePlatformAdmin(['name' => 'First Operator']);

        FeatureControl::define(self::FEATURE, 'Premium insights', null, $operator);

        $operator->name = 'Someone Else Entirely';
        $operator->save();

        $entry = PlatformControlChange::query()->first();

        $this->assertSame(
            'First Operator',
            $entry->actor_name,
            'The change log shows the actor\'s CURRENT name rather than the one they had when they '
            . 'made the change. A log that rewrites itself when an account is renamed is not a '
            . 'record of what happened -- the same reason OrderCurrencyStamp snapshots money.'
        );

        $this->assertSame($operator->id, $entry->actor_id);
    }

    /**
     * A CHANGE WITH NO ACTOR PASSED FALLS BACK TO THE SIGNED-IN PRINCIPAL, so the operator screens
     * need not thread it through every call — and a console command, with nobody signed in, records
     * a null rather than guessing.
     */
    #[Test]
    public function test_an_omitted_actor_falls_back_to_the_signed_in_user_and_otherwise_to_nobody(): void
    {
        FeatureControl::define('from-the-console', 'From the console');

        $this->assertNull(
            PlatformControlChange::query()->orderBy('id')->first()->actor_id,
            'A change made with nobody signed in recorded an actor. A console command, a seeder or '
            . 'a queued job has no user, and inventing one would make the log lie.'
        );

        $operator = $this->makePlatformAdmin(['name' => 'Signed In Operator']);
        $this->actingAs($operator);

        FeatureControl::define('from-the-screen', 'From the screen');

        $entry = PlatformControlChange::query()->where('subject_key', 'from-the-screen')->first();

        $this->assertSame($operator->id, $entry->actor_id);
        $this->assertSame('Signed In Operator', $entry->actor_name);
    }

    /**
     * A NON-`User` PRINCIPAL IS RECORDED WITHOUT AN ID, AND THAT IS NOT PEDANTRY.
     *
     * CLAUDE.md records a measured defect of exactly this shape: `customers` HAS a `type` column
     * with `'type'` in `Customer::$fillable`, so a `Customer` row typed `super admin` passed a gate
     * that read `->type` without asking what CLASS the principal was — 200, the handler's own JSON,
     * measured 2026-11-26. Writing a `Customer`'s primary key into `actor_id` would make this log
     * say that User #7 switched the platform's features, when User #7 may be somebody else
     * entirely.
     */
    #[Test]
    public function test_a_non_user_principal_is_recorded_as_one_rather_than_as_a_user_id(): void
    {
        /*
         * `first_name`/`last_name` AND NOT `name` -- `customers` has no `name` column; the model's
         * `name` is an appended accessor. Measured from the migration after a first draft failed
         * with "table customers has no column named name", which is also why `type` is set
         * explicitly: its column DEFAULT is the misspelled `'cutsomer'`.
         */
        $customer = Customer::create([
            'first_name' => 'A',
            'last_name' => 'Shopper',
            'email' => 'shopper@example.test',
            'password' => bcrypt('not-a-real-credential'),
            'type' => 'super admin',
            'status' => 1,
            'store_id' => $this->makeStore($this->makeMerchant())->id,
        ]);

        $this->assertSame(
            'super admin',
            (string) $customer->fresh()->type,
            'The fixture Customer is not typed `super admin`, so it is not the measured shape this '
            . 'test is about -- CLAUDE.md records a Customer carrying that exact value passing a '
            . 'real operator gate.'
        );

        FeatureControl::define(self::FEATURE, 'Premium insights', null, $customer);

        $entry = PlatformControlChange::query()->first();

        $this->assertNull(
            $entry->actor_id,
            'A Customer\'s primary key was written into actor_id. The change log would then name a '
            . 'User who had nothing to do with it -- and CLAUDE.md records a Customer typed '
            . '"super admin" passing a real gate, so this is a measured shape and not a '
            . 'hypothetical.'
        );

        $this->assertStringContainsString(
            'Customer',
            (string) $entry->actor_name,
            'A non-user principal must still be VISIBLE in the log. "A Customer reached this" is '
            . 'itself worth seeing.'
        );
    }

    // ---------------------------------------------------------------------------------------------
    // append-only
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function test_an_entry_cannot_be_rewritten(): void
    {
        FeatureControl::define(self::FEATURE, 'Premium insights');

        $entry = PlatformControlChange::query()->first();
        $entry->note = 'Actually it was fine all along.';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('append-only');

        $entry->save();
    }

    #[Test]
    public function test_an_entry_cannot_be_deleted(): void
    {
        FeatureControl::define(self::FEATURE, 'Premium insights');

        $entry = PlatformControlChange::query()->first();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('append-only');

        $entry->delete();
    }

    /**
     * THE GUARD IS ON `updating` AND NOT ON `saving`, AND THE DIFFERENCE IS THE WHOLE TABLE.
     *
     * `saving` fires for an INSERT too, so guarding there makes the log unwritable — and it fails
     * in the direction that LOOKS like the feature working: no exception anybody sees, just an
     * empty change log. This asserts the insert path still works, which is the half a
     * `saving`-guard would break.
     */
    #[Test]
    public function test_the_append_only_guard_does_not_stop_an_entry_being_written(): void
    {
        $entry = PlatformChangeLog::record(
            PlatformChangeLog::SUBJECT_FEATURE_FLAG,
            'written-directly',
            PlatformChangeLog::ACTION_DEFINED
        );

        $this->assertNotNull($entry, 'A new entry could not be written at all.');
        $this->assertSame(1, (int) DB::table('platform_control_changes')->count());
    }

    /**
     * HOW FAR THE APPEND-ONLY CLAIM GOES, ASSERTED SO THE DOCBLOCK CANNOT OVERSTATE IT.
     *
     * Nothing in the database refuses a `DELETE`. The guard is at the Eloquent boundary — the door
     * every first-party writer passes through — and calling that "immutable" would be the kind of
     * claim this project keeps having to withdraw. `ThemePublicationEvent`'s docblock makes the
     * same admission for the same table shape.
     */
    #[Test]
    public function test_the_append_only_guard_is_a_model_boundary_and_not_a_database_constraint(): void
    {
        FeatureControl::define(self::FEATURE, 'Premium insights');

        $this->assertSame(1, (int) DB::table('platform_control_changes')->count());

        DB::table('platform_control_changes')->delete();

        $this->assertSame(
            0,
            (int) DB::table('platform_control_changes')->count(),
            'A raw delete was refused, which means the database DOES carry a constraint. That '
            . 'would be better than the documented behaviour -- update the model and migration '
            . 'docblocks, which currently say the guarantee stops at the Eloquent boundary.'
        );
    }

    // ---------------------------------------------------------------------------------------------
    // reading it back
    // ---------------------------------------------------------------------------------------------

    /**
     * THE FEED IS ORDERED BY `id`, NOT BY `created_at`, AND THAT IS NOT INTERCHANGEABLE.
     *
     * Several changes made in one request share a timestamp to the second, so `created_at DESC`
     * returns them in whatever order the database chooses — and the screen then shows
     * "rollout changed to 50%" ABOVE "feature defined". Written with identical timestamps on
     * purpose, because that is the real case and not a contrived one.
     */
    #[Test]
    public function test_the_feed_breaks_a_shared_timestamp_by_insertion_order(): void
    {
        FeatureControl::define(self::FEATURE, 'Premium insights');
        FeatureControl::release(self::FEATURE, 10);
        FeatureControl::releaseFully(self::FEATURE);

        $stamp = '2026-11-26 09:00:00';
        DB::table('platform_control_changes')->update(['created_at' => $stamp]);

        $this->assertSame(
            1,
            DB::table('platform_control_changes')->distinct()->count('created_at'),
            'The fixture did not actually give every entry the same timestamp, so the tie-break '
            . 'is not being tested.'
        );

        $this->assertSame(
            [
                PlatformChangeLog::ACTION_ENABLED,
                PlatformChangeLog::ACTION_ROLLOUT_CHANGED,
                PlatformChangeLog::ACTION_DEFINED,
            ],
            PlatformChangeLog::recent()->pluck('action')->all(),
            'Entries sharing a timestamp came back in the wrong order. An operator reading the '
            . 'feed after an incident needs the sequence, and a same-second tie is the normal case '
            . 'rather than the edge one -- every change in one form submission shares it.'
        );
    }

    #[Test]
    public function test_a_subjects_history_is_only_that_subjects(): void
    {
        FeatureControl::define('feature-one', 'One');
        FeatureControl::releaseFully('feature-one');
        FeatureControl::define('feature-two', 'Two');

        $this->assertSame(
            ['feature-one', 'feature-one'],
            PlatformChangeLog::forSubject(PlatformChangeLog::SUBJECT_FEATURE_FLAG, 'feature-one')
                ->pluck('subject_key')->all(),
            'One feature\'s history carried another feature\'s entries.'
        );
    }

    // ---------------------------------------------------------------------------------------------
    // the recorder's own failure mode
    // ---------------------------------------------------------------------------------------------

    /**
     * A RECORDER THAT CANNOT WRITE MUST NOT STOP THE SWITCH BEING THROWN.
     *
     * The stated contract, and the stated cost: a change can therefore be made and NOT recorded.
     * The alternative is that a database hiccup leaves a feature switched on that an operator is
     * trying to switch off, and for a kill switch that is the worse failure. Driven by removing the
     * log table, which is also the real state of a database where only the first of the two
     * migrations has been applied.
     */
    #[Test]
    public function test_a_change_still_lands_when_the_log_cannot_be_written(): void
    {
        $store = $this->makeStore($this->makeMerchant())->id;

        FeatureControl::define(self::FEATURE, 'Premium insights');
        FeatureControl::releaseFully(self::FEATURE);

        Schema::drop('platform_control_changes');

        FeatureControl::disable(self::FEATURE, null, 'The log is gone; the switch must still work.');

        $this->assertSame(
            'off',
            (string) DB::table('platform_feature_flags')->where('key', self::FEATURE)->value('state'),
            'The disable did not land because the change log could not be written. The kill switch '
            . 'must not depend on the bookkeeping: availability first, record second.'
        );

        $this->assertTrue(
            FeatureControl::decide(self::FEATURE, $store)->isDenied(),
            'The feature is still reachable after a disable whose log write failed.'
        );
    }

    /**
     * THE VOCABULARY IS PINNED. `subject_type` and `action` are plain `varchar` columns, for the
     * reason the migration gives (an enum needs a migration to extend, and production's non-strict
     * `sql_mode` truncates rather than refuses) — so the only thing keeping them meaningful is this
     * constant list, and a screen or a report keyed on a value that quietly changed would read
     * empty.
     */
    #[Test]
    public function test_the_change_log_vocabulary_is_the_declared_set(): void
    {
        $this->assertSame(
            ['feature_flag', 'feature_flag_target', 'pilot_group'],
            collect(PlatformChangeLog::SUBJECTS)->sort()->values()->all()
        );

        $this->assertSame(
            [
                'defined', 'disabled', 'enabled', 'member_added', 'member_removed',
                'rollout_changed', 'target_added', 'target_withdrawn',
            ],
            collect(PlatformChangeLog::ACTIONS)->sort()->values()->all(),
            'The change log\'s action vocabulary changed. Both columns are plain strings, so '
            . 'nothing but this list keeps them meaningful -- a screen keyed on an old value reads '
            . 'empty rather than erroring.'
        );
    }
}
