<?php

use App\Support\Database\ThemeSchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 06 `CTRL-001` / `CTRL-002` — THE PLATFORM'S FEATURE CONTROL PLANE.
 * =================================================================================================
 *
 * APPROVED FOR DEVELOPMENT AND ISOLATED DATABASE TESTING. **Not applied to production, and applying
 * it needs the owner's separate authorisation** — the same posture as the three D8 consent tables,
 * the GAP-219 nudge ledger and `analytics_report_schedules`, because CLAUDE.md reserves a persistent
 * migration to the owner.
 *
 * `CTRL-001` — *"تفعيل الميزات حسب الباقات والمتاجر والمجموعات التجريبية"* — feature enablement by
 * **plan**, by **store**, and by **pilot group**.
 * `CTRL-002` — *"إطلاق تدريجي وإيقاف آمن"* — **gradual rollout** and **safe disable**.
 * `docs/development/master-plan/DECISION-ID-REGISTER.md:249-250`, Phase 06 §06-H.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT ALREADY EXISTS, MEASURED, AND WHY IT IS NOT THESE TABLES.
 *
 * | axis | mechanism today | keyed on |
 * |---|---|---|
 * | the whole platform | `add_on_managers.is_enable` — one row per application | the module name |
 * | a **plan** | `plans.modules`, a comma-joined list | the plan |
 * | a **merchant** | `user_active_modules` + `users.active_module` | the OWNER's user id |
 * | a **store** | **nothing** | — |
 * | a **pilot group** | **nothing** | — |
 *
 * The store row is not an oversight in this reading of it: *measured* — no migration in this
 * repository creates a table carrying both a `store_id` and a module or feature column. A merchant
 * may own several stores, so the existing entitlement is strictly coarser than `CTRL-001` asks for.
 *
 * AND "PILOT GROUP" HAS NO SPELLING AT ALL. Measured over `app/`, `config/`,
 * `database/migrations/`, `routes/` and `resources/views/` at this branch's own base commit, so the
 * figures cannot be inflated by this change's files:
 *
 *   `pilot_group`, `pilot group`   **0** hits
 *   `canary`                       **1** hit — `Atlantic/Canary` in `config/timezones.php`
 *   `rollout`                      **4** hits — two in `TokenInventoryReport` about a token-expiry
 *                                  rollout, two in `StoreScope` about a "blanket rollout" of a
 *                                  trait. None is a feature rollout.
 *   `feature_flag` / `featureFlag` **9** hits, ALL in `AppSettingController`, and all of them the
 *                                  same five merchant-owned store-setting checkboxes
 *                                  (`PANEL_OWNED_FEATURE_FLAGS`). Those are per-store and real —
 *                                  but they are the MERCHANT's switches, written into `settings`,
 *                                  with no plan, pilot or rollout dimension and no operator
 *                                  control. They are not what `CTRL-001` asks the operator for.
 *
 * The full per-axis measurement is `docs/development/phase-6/PHASE-06-FEATURE-CONTROL.md` §1.
 *
 * SO THESE TABLES ADD THE TWO MISSING AXES AND LEAVE THE EXISTING THREE ALONE. Nothing here writes,
 * reads or supersedes `add_on_managers`, `plans.modules` or `user_active_modules`; a flag with no
 * row answers `NotControlled` and every existing gate behaves exactly as it did. That is deliberate:
 * CLAUDE.md records that `module_is_active()` is consulted from hundreds of places and that a
 * merchant with an empty `add_on_managers` is already redirected to a dashboard that returns HTTP
 * 200, so widening or narrowing that answer as a side effect of a new table would be invisible.
 *
 * -------------------------------------------------------------------------------------------------
 * THE THREE TABLES.
 *
 * `platform_feature_flags`         one row per controlled feature: its state and its rollout width.
 * `platform_feature_flag_targets`  the named exceptions: allow or deny, by plan, store or group.
 * `platform_pilot_group_members`   which stores are in a pilot group.
 *
 * WHY `state` IS A STRING AND NOT A BOOLEAN. A boolean cannot express "on for a pilot group only",
 * which is the whole requirement. The three values are `off`, `limited` and `on`, and `off` is the
 * kill switch: `App\Support\Control\FeatureControl` refuses `off` BEFORE it looks at any target, so
 * switching a feature off cannot be partially undone by an allow-list somebody forgot. That
 * ordering is `CTRL-002`'s "safe disable" and it is asserted in both directions.
 *
 * WHY A STRING AND NOT AN ENUM COLUMN. `theme_publication_events.action` records the reason and it
 * applies unchanged: an enum needs a migration to extend, and production runs NON-STRICT
 * `sql_mode`, where an over-long or unknown value is TRUNCATED rather than refused. So the resolver
 * treats any value it does not recognise as `off` — fail closed inside a declared flag — rather
 * than trusting the column.
 *
 * `rollout_percentage` IS SURVIVED BY A DISABLE, AND THAT IS THE POINT. `disable()` writes `state`
 * and touches nothing else, so the targets and the width are still there when the feature is
 * resumed and the reach comes back EXACTLY as it was. A disable that cleared them would be a
 * disable that destroyed configuration, which is `CTRL-004` ("do not delete a feature's data merely
 * because its availability was disabled") applied to the control plane itself.
 *
 * `target_key` IS A STRING FOR ALL THREE TARGET TYPES, holding a plan id, a store id or a pilot
 * group's slug. One column rather than three nullable ones, because the unique key below has to
 * cover the identity of a target whatever its type, and a three-column identity with two NULLs in
 * it is not unique in MySQL (NULLs do not collide in a unique index). Measured hazard, not style:
 * `settings` has no unique index at all and P1-PLT-07 is the duplicate-row defect that followed.
 *
 * NO FOREIGN KEYS, ON PURPOSE, and the reason is the same one `theme_publication_events` records:
 * a plan or a store that is deleted must not silently take an operator's targeting decision with
 * it, because the decision is the record of what the operator intended. A target naming a row that
 * no longer exists simply never matches — `FeatureControl` compares values, it does not dereference
 * — so the failure direction is "this target has no effect", not "this query breaks".
 *
 * NOT `Cachable`, AND THAT IS A DELIBERATE DEPARTURE from 79 of the 95 models in `app/Models/`.
 * Two measured reasons. (1) An availability control must answer current truth: CLAUDE.md records
 * `activated_modules_for_owner`'s own hazard, that an owner whose plan lapsed mid-run kept passing
 * a gate until the worker restarted — for a kill switch that is the failure mode that matters most.
 * (2) `PlanController::update`'s docblock records that on production's non-taggable `database` cache
 * store, laravel-model-caching's flush degrades to a `Cache::flush()` over EVERYTHING, so every
 * flag edit would erase the whole application cache. The resolver memoises per request instead, in
 * the container rather than in a `static`, and every write clears the memo.
 */
return new class extends Migration
{
    public function up(): void
    {
        ThemeSchemaGuard::assertCompatible(
            'platform_feature_flags',
            ['key', 'label', 'description', 'state', 'rollout_percentage', 'created_at', 'updated_at'],
            ['pff_key_unique'],
            []
        );

        if (! Schema::hasTable('platform_feature_flags')) {
            Schema::create('platform_feature_flags', function (Blueprint $table) {
                $table->bigIncrements('id');

                /* The identifier first-party code asks for. 64 characters, because the longest
                   plausible key is a module name plus a qualifier and the existing module names
                   reach 20 (`CheckoutAttachment`, `ProductImageZoom`). */
                $table->string('key', 64);

                /* What the operator reads on the control screen. Separate from `key` so renaming
                   the label never changes what the code asks for -- the mistake that makes a flag
                   silently stop matching. */
                $table->string('label', 120);

                /* Why the flag exists, in the operator's own words. Nullable: a flag defined from
                   code has nobody to write it yet. */
                $table->text('description')->nullable();

                /* `off` | `limited` | `on`. See the class docblock for why it is not a boolean and
                   not an enum, and why an unrecognised value reads as `off`. */
                $table->string('state', 16)->default('off');

                /* 0-100. Read ONLY in `limited`, and only for a caller that names a store -- a
                   percentage of nothing is not a coin flip, it is "no subject", which the resolver
                   answers as Denied rather than guessing. */
                $table->unsignedTinyInteger('rollout_percentage')->default(0);

                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();

                /* ONE ROW PER KEY. Without this, two rows for one key make the resolver's answer
                   depend on row order, and a second row created by a double-submitted form would
                   be an availability control nobody can see. */
                $table->unique('key', 'pff_key_unique');
            });
        }

        ThemeSchemaGuard::assertCompatible(
            'platform_feature_flag_targets',
            ['feature_flag_id', 'target_type', 'target_key', 'effect', 'created_at', 'updated_at'],
            ['pfft_identity_unique', 'pfft_flag_effect'],
            []
        );

        if (! Schema::hasTable('platform_feature_flag_targets')) {
            Schema::create('platform_feature_flag_targets', function (Blueprint $table) {
                $table->bigIncrements('id');

                $table->unsignedBigInteger('feature_flag_id');

                /* `plan` | `store` | `pilot_group` -- the three axes `CTRL-001` names, and no
                   others. An unrecognised type never matches, so a typo removes a target's effect
                   rather than widening it. */
                $table->string('target_type', 16);

                /* A plan id, a store id, or a pilot group's slug. One column; see the docblock. */
                $table->string('target_key', 64);

                /* `allow` | `deny`. DENY BEATS ALLOW and beats `state = on`, so a store that must
                   not have a feature can be excluded without taking it away from everyone. An
                   unrecognised effect is treated as neither, which is the safe direction: it
                   cannot accidentally become an allow. */
                $table->string('effect', 8)->default('allow');

                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();

                /* ONE TARGET PER (FLAG, TYPE, KEY). Re-adding the same target updates it rather
                   than stacking a contradictory second row, which is what makes "deny beats allow"
                   a decidable rule instead of a race. */
                $table->unique(['feature_flag_id', 'target_type', 'target_key'], 'pfft_identity_unique');

                /* The resolver's only query shape: this flag's targets, read in one go. */
                $table->index(['feature_flag_id', 'effect'], 'pfft_flag_effect');
            });
        }

        ThemeSchemaGuard::assertCompatible(
            'platform_pilot_group_members',
            ['pilot_group', 'store_id', 'created_at', 'updated_at'],
            ['ppgm_identity_unique', 'ppgm_store'],
            []
        );

        if (! Schema::hasTable('platform_pilot_group_members')) {
            Schema::create('platform_pilot_group_members', function (Blueprint $table) {
                $table->bigIncrements('id');

                /* A slug the operator chooses, e.g. `early-access`. NOT a foreign key to a groups
                   table: a pilot group IS its membership, and a separate parent table would add a
                   row whose only content is a name the slug already carries. If a group ever needs
                   a description or an owner, that is an additive table of its own. */
                $table->string('pilot_group', 64);

                $table->unsignedBigInteger('store_id');

                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();

                $table->unique(['pilot_group', 'store_id'], 'ppgm_identity_unique');

                /* THE RESOLVER'S QUERY: "which groups is THIS store in". Indexed on `store_id`
                   first because that is the direction every request reads, while the operator
                   screen's "who is in this group" is the rarer one and is served by the unique
                   key above. */
                $table->index('store_id', 'ppgm_store');
            });
        }
    }

    /**
     * A DOCUMENTED NO-OP, the same posture as every GATE F migration.
     *
     * Dropping these would destroy the operator's targeting decisions — which stores are in which
     * pilot, which plan carries which feature, how wide a rollout had reached. CLAUDE.md records
     * that two GATE F drafts dropped a column *because* it existed and a rollback destroyed 45
     * `cost_price` values. Removing a control plane is a deliberate operator action, not a
     * rollback.
     */
    public function down(): void
    {
        // Intentionally empty. See the method docblock.
    }
};
