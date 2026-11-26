<?php

namespace Tests\Feature\Saas;

use App\Models\Plan;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\StripsSourceComments;

/**
 * DECISION H1 (option A) — `plans.duration` MAY ONLY BE WRITTEN AS ONE OF THE THREE APPROVED WORDS.
 * =================================================================================================
 *
 * THE WRITE SIDE. `plans.duration` is `$table->string('duration')->nullable()` -- free text --
 * and `PlanController`'s validation rules covered `name`, `price`, `max_stores`, `max_products`,
 * `max_users` and `storage_limit` and did not mention `duration` at all. The form is a
 * `Form::select` over `Plan::$arrDuration`, which constrains the browser and not the request.
 * `App\Services\SaasRevenueFigures` reads this column to compute the platform's MRR, and before
 * the READ side was hardened a plan priced 1200 a year whose duration read `Yearly` reported MRR
 * 1200 instead of 100 -- a twelvefold overstatement. The read side now sends an unrecognised
 * duration to a named `Duration not recognised` bucket; these cases are the write side.
 *
 * THE VOCABULARY HAS ONE DEFINITION. Every case below derives the approved set from
 * `array_keys(Plan::$arrDuration)` rather than spelling the three words out, so a test cannot
 * silently disagree with the model the controller validates against. `test_the_approved_set_is_the
 * _models_own_keys` is the premise: it fails if that property ever stops being the three words,
 * which would otherwise make every other case here vacuous.
 *
 * HISTORICAL VALUES ARE NOT REWRITTEN. The owner's instruction is to enforce the vocabulary going
 * forward and to leave rows that already carry something else alone. So:
 *
 *   - a submitted `Yearly` is REFUSED on create and on update
 *     (`test_an_unapproved_duration_is_refused_on_*`);
 *   - a stored `Yearly` SURVIVES a refused update, byte-identical
 *     (`test_a_refused_update_leaves_the_stored_historical_value_exactly_as_it_was`);
 *   - and an edit that carries no `duration` key at all still saves, leaving the historical value
 *     untouched (`test_an_edit_that_carries_no_duration_at_all_still_saves_*`). That is why the
 *     update rule is `sometimes|required|in:...` and not a plain `required`: see the two cases
 *     under "why update is not a plain required", which MEASURE both reasons.
 *
 * EACH METHOD'S OWN FAILURE SHAPE. `store()` validates through `$request->validate()`, so a
 * refusal is a redirect carrying an errors bag; `update()` validates through `\Validator::make`
 * and answers `redirect()->back()->with('error', $messages->first())`. The cases assert the shape
 * each method already had, and in both cases the database state as well -- the status code is not
 * the control, the absence of the write is.
 */
final class PlanDurationValidationTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    private const CONTROLLER = 'app/Http/Controllers/PlanController.php';

    /** A word that is not in the approved set, and the exact one the MRR defect was measured on. */
    private const UNAPPROVED = 'Yearly';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        // SetLocate and several handlers memoise the platform admin for an hour. Under
        // CACHE_STORE=array that lives for the rest of the test, so a fixture created after the
        // first request would be invisible.
        Cache::forget('super_admin_details');
        Cache::forget('admin_details');
    }

    /** @return list<string> */
    private function approvedDurations(): array
    {
        return array_keys(Plan::$arrDuration);
    }

    /**
     * An operator holding the two permissions `PlanController::store`/`update` test for.
     *
     * No authorization is asserted or changed here -- `Create Plan` and `Edit Plan` are the
     * grants the handlers already require, and `grantPermission()` supplies what the seeded
     * `super admin` role carries in production.
     */
    private function operator(): \App\Models\User
    {
        $admin = $this->makePlatformAdmin();

        $this->grantPermission($admin, 'Create Plan');
        $this->grantPermission($admin, 'Edit Plan');

        return $admin->fresh();
    }

    /** A complete, otherwise-valid create payload. `themes` and `storage_limit` are NOT NULL. */
    private function createPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'H1 plan ' . $this->uniqueFixtureRef(),
            'price' => 1200,
            'duration' => 'Month',
            'max_stores' => 1,
            'max_products' => 10,
            'max_users' => 2,
            'storage_limit' => 100,
            'themes' => ['stylique'],
            'description' => 'Decision H1 fixture.',
        ], $overrides);
    }

    /**
     * A persisted plan. Written through Eloquent, not `DB::table()`: `App\Models\Plan` is
     * `Cachable`, so a raw insert would leave Eloquent reads -- route-model binding included --
     * serving the pre-write row for the rest of the test.
     */
    private function makePlan(array $attributes = []): Plan
    {
        return Plan::create(array_merge([
            'name' => 'H1 stored ' . $this->uniqueFixtureRef(),
            'price' => 1200,
            'duration' => 'Month',
            'max_stores' => 1,
            'max_products' => 10,
            'max_users' => 2,
            'storage_limit' => 100,
            'themes' => 'stylique',
            'trial' => 0,
            'trial_days' => 0,
        ], $attributes));
    }

    /** An otherwise-valid update payload for `$plan`, matching what the edit form submits. */
    private function updatePayload(Plan $plan, array $overrides = []): array
    {
        return array_merge([
            'name' => $plan->name,
            'price' => $plan->price,
            'duration' => 'Month',
            'max_stores' => 3,
            'max_products' => 30,
            'max_users' => 3,
            'storage_limit' => 100,
            'themes' => ['stylique'],
        ], $overrides);
    }

    /**
     * The `error` flash `update()` sets on a validation failure, or null.
     *
     * `update()` answers `redirect()->back()->with('error', $messages->first())` rather than
     * throwing, so there is no errors bag to assert on -- the flash IS the failure shape, and the
     * cases below assert the database state beside it.
     */
    private function flashedError(): ?string
    {
        $error = session('error');

        return $error === null ? null : (string) $error;
    }

    /**
     * The column as the DATABASE holds it.
     *
     * A raw read, deliberately: the assertion is about the row, and `Plan` is `Cachable`.
     * `Plan` is not in `config/tenancy.php`'s `scoped_models`, so no global scope narrows this.
     */
    private function storedDuration(int $planId): ?string
    {
        $value = DB::table('plans')->where('id', $planId)->value('duration');

        return $value === null ? null : (string) $value;
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The premise
     * ---------------------------------------------------------------------------------------------
     */

    public function test_the_approved_set_is_the_models_own_keys(): void
    {
        $this->assertSame(
            ['Unlimited', 'Month', 'Year'],
            $this->approvedDurations(),
            'Plan::$arrDuration no longer carries exactly the three approved words, so every case '
            . 'in this file is measuring something other than decision H1. Introducing a new '
            . 'billing period is an owner decision, not a model edit.'
        );

        $this->assertNotContains(
            self::UNAPPROVED,
            $this->approvedDurations(),
            'the word this file drives as unapproved has become approved; pick another'
        );
    }

    public function test_the_controller_derives_the_allowed_set_from_the_model(): void
    {
        $code = $this->sourceWithoutComments(self::CONTROLLER);

        $this->assertStringContainsString(
            'Plan::$arrDuration',
            $code,
            'PlanController must derive the allowed durations from Plan::$arrDuration so the '
            . 'vocabulary has ONE definition. A second hardcoded list drifts from the model the '
            . 'form renders and the revenue figures read.'
        );

        // Comments are stripped first: this file and the controller both discuss `Yearly` and the
        // three approved words in prose, and a census that reads comments can be satisfied -- or
        // broken -- by prose about the thing it measures.
        $this->assertDoesNotMatchRegularExpression(
            '/in:\s*Unlimited\s*,\s*Month\s*,\s*Year/i',
            $code,
            'the three words are spelled out as a literal rule string in PlanController, which is '
            . 'a second definition of the vocabulary'
        );
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * Create — POSITIVE CONTROL first, so a refusal cannot be passing because nothing ever saves
     * ---------------------------------------------------------------------------------------------
     */

    public function test_every_approved_duration_still_saves_on_create(): void
    {
        foreach ($this->approvedDurations() as $duration) {
            $payload = $this->createPayload(['duration' => $duration]);

            $response = $this->actingAs($this->operator())
                ->post(route('plan.store'), $payload);

            $response->assertSessionHasNoErrors();

            $id = (int) DB::table('plans')->where('name', $payload['name'])->value('id');

            $this->assertNotSame(0, $id,
                "the approved duration `{$duration}` was refused on create: no plan row exists");
            $this->assertSame($duration, $this->storedDuration($id),
                "the approved duration `{$duration}` did not reach the column on create");
        }
    }

    public function test_an_unapproved_duration_is_refused_on_create(): void
    {
        $payload = $this->createPayload(['duration' => self::UNAPPROVED]);

        $response = $this->actingAs($this->operator())
            ->post(route('plan.store'), $payload);

        $response->assertSessionHasErrors('duration');

        $this->assertSame(
            0,
            DB::table('plans')->where('name', $payload['name'])->count(),
            'A PLAN WAS CREATED CARRYING `' . self::UNAPPROVED . '`. SaasRevenueFigures cannot '
            . 'read how often that is paid, so this plan\'s subscribers land in the '
            . '`Duration not recognised` bucket and the platform\'s MRR silently omits them -- or, '
            . 'before the read side was hardened, counted a yearly price as a monthly one.'
        );
    }

    public function test_a_missing_duration_is_refused_on_create(): void
    {
        $payload = $this->createPayload();
        unset($payload['duration']);

        $response = $this->actingAs($this->operator())
            ->post(route('plan.store'), $payload);

        $response->assertSessionHasErrors('duration');

        $this->assertSame(
            0,
            DB::table('plans')->where('name', $payload['name'])->count(),
            'a plan was created with NO duration at all. The column is nullable, so the row '
            . 'persists with null and every subscriber of it is uncountable.'
        );
    }

    public function test_a_blank_duration_is_refused_on_create(): void
    {
        $payload = $this->createPayload(['duration' => '']);

        $response = $this->actingAs($this->operator())
            ->post(route('plan.store'), $payload);

        $response->assertSessionHasErrors('duration');

        $this->assertSame(
            0,
            DB::table('plans')->where('name', $payload['name'])->count(),
            'a plan was created with an EMPTY duration. The blank arrives as null -- '
            . '`ConvertEmptyStringsToNull` is in the global middleware stack -- and the column is '
            . 'nullable, so the row persists and its subscribers are uncountable.'
        );
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * Update — POSITIVE CONTROL first
     * ---------------------------------------------------------------------------------------------
     */

    public function test_every_approved_duration_still_saves_on_update(): void
    {
        foreach ($this->approvedDurations() as $duration) {
            $plan = $this->makePlan();

            $this->actingAs($this->operator())
                ->put(route('plan.update', $plan->id), $this->updatePayload($plan, [
                    'duration' => $duration,
                ]));

            $this->assertNull($this->flashedError(),
                "the approved duration `{$duration}` was refused on update: "
                . (string) $this->flashedError());
            $this->assertSame($duration, $this->storedDuration($plan->id),
                "the approved duration `{$duration}` did not reach the column on update");
        }
    }

    public function test_an_unapproved_duration_is_refused_on_update(): void
    {
        $plan = $this->makePlan(['duration' => 'Month']);

        $this->actingAs($this->operator())
            ->put(route('plan.update', $plan->id), $this->updatePayload($plan, [
                'duration' => self::UNAPPROVED,
                'max_stores' => 99,
            ]));

        $this->assertNotNull($this->flashedError(),
            'update() answers a redirect carrying an `error` flash on validation failure; none was '
            . 'set, so the request was not refused');

        $this->assertSame('Month', $this->storedDuration($plan->id),
            'an unapproved duration was written over a valid one by the update handler');

        $this->assertSame('1', (string) DB::table('plans')->where('id', $plan->id)->value('max_stores'),
            'the refusal must be total: no field of the request may be applied. `max_stores` moved, '
            . 'so the handler validated and then saved anyway.');
    }

    public function test_a_blank_duration_is_refused_on_update(): void
    {
        $plan = $this->makePlan(['duration' => 'Year']);

        $this->actingAs($this->operator())
            ->put(route('plan.update', $plan->id), $this->updatePayload($plan, ['duration' => '']));

        $this->assertSame(
            'Year',
            $this->storedDuration($plan->id),
            'A BLANK DURATION WAS WRITTEN OVER A VALID ONE. Measured on the unvalidated handler: '
            . '`ConvertEmptyStringsToNull` turns the empty select into null, and '
            . '`$plan->update($request->all())` carried that null straight into the column, '
            . 'leaving the plan with no duration at all.'
        );
    }

    /**
     * THE SAME BLANK, WITH THE GLOBAL MIDDLEWARE OUT OF THE WAY — this is what `required` buys.
     *
     * The case above is refused by the `in` rule alone, because `ConvertEmptyStringsToNull` has
     * already turned the empty select into a PRESENT null and `in` runs on a present attribute.
     * Measured: removing `required` from the update rule leaves that case green. So without this
     * case `required` would be an unkilled mutant, and the comment in `PlanController` claiming it
     * makes the refusal independent of a global middleware would be an untested claim.
     *
     * The skip is registered for this request only and flushed in a `finally`, because
     * `ConvertEmptyStringsToNull::$skipCallbacks` is STATIC and would otherwise leak into every
     * later test in the same process.
     */
    public function test_a_blank_duration_is_refused_even_if_empty_strings_are_not_converted(): void
    {
        $plan = $this->makePlan(['duration' => 'Year']);

        ConvertEmptyStringsToNull::skipWhen(static fn () => true);

        try {
            $this->actingAs($this->operator())
                ->put(route('plan.update', $plan->id), $this->updatePayload($plan, ['duration' => '']));
        } finally {
            ConvertEmptyStringsToNull::flushState();
        }

        $this->assertSame(
            'Year',
            $this->storedDuration($plan->id),
            'with the empty-string conversion out of the stack, an empty `duration` reached the '
            . 'column and blanked a valid value. `in` is not an implicit rule, so the validator '
            . "skips it for '' -- `required` behind `sometimes` is the only thing refusing this."
        );
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * Historical values: never rewritten, and never a route to a new billing period
     *
     * A CASE WAS DELETED FROM THIS SECTION AND THE REASON BELONGS HERE RATHER THAN IN A COMMIT
     * MESSAGE. `test_a_plan_already_carrying_an_unapproved_duration_cannot_be_re_saved_with_it`
     * asserted that re-submitting the word a row already holds is REFUSED, which is the literal
     * reading of "enforce the three values on update". It was removed, not weakened, because the
     * two clauses of decision H1 cannot both hold under it: with the edit form fixed to show the
     * row its own value, that refusal blocks every edit to every other field of a legacy plan,
     * and the only way out for an operator is to change a live plan's billing period -- which is a
     * material change to payments and, per the project's own escalation rules, an owner decision
     * rather than a side effect of editing `max_products`. Leaving the form unfixed instead meant
     * the browser submitting `Unlimited`, which is the silent rewrite the decision forbids by name.
     *
     * So the implemented reading is: a row may send BACK the word it already carries, and nothing
     * else unapproved -- enforced per row, from the stored column, never from the request. The
     * cases below drive both halves. The interpretation is recorded under H1 in
     * `docs/development/OWNER-DECISION-PACKAGE.md`; if the owner meant the literal reading, the
     * deleted case is one `Rule::in` argument away from coming back.
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_refused_update_leaves_the_stored_historical_value_exactly_as_it_was(): void
    {
        $plan = $this->makePlan(['duration' => self::UNAPPROVED]);

        $this->actingAs($this->operator())
            ->put(route('plan.update', $plan->id), $this->updatePayload($plan, [
                'duration' => 'Quarterly',
            ]));

        $this->assertSame(
            self::UNAPPROVED,
            $this->storedDuration($plan->id),
            'the historical value was rewritten. The owner\'s instruction is to refuse new writes, '
            . 'NOT to correct rows that already exist -- an auto-correction here would be the '
            . 'silent rewrite that was explicitly forbidden.'
        );
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * Why update is not a plain `required` — both reasons, measured
     * ---------------------------------------------------------------------------------------------
     */

    /**
     * REASON ONE: the edit form does not render a `duration` field for plan id 1.
     *
     * `resources/views/plans/edit.blade.php` wraps the price AND duration fields in
     * `@if ($plan->id != 1)`, so an edit of the Free Plan -- present in every installation, and
     * the downgrade target `PlanController::refund()` and `assignPlan(1)` both depend on --
     * submits no `duration` key at all. A plain `required` on update would make that plan
     * permanently un-editable through its own form.
     */
    public function test_an_edit_that_carries_no_duration_at_all_still_saves_the_other_fields(): void
    {
        $plan = $this->makePlan(['duration' => 'Year']);

        $payload = $this->updatePayload($plan, ['name' => 'H1 renamed ' . $this->uniqueFixtureRef()]);
        unset($payload['duration']);

        $this->actingAs($this->operator())
            ->put(route('plan.update', $plan->id), $payload);

        $this->assertNull($this->flashedError(),
            'an edit that submits no duration was refused: ' . (string) $this->flashedError()
            . ' -- plans/edit.blade.php renders no duration field for plan id 1, so this refuses '
            . 'every edit of the Free Plan');

        $this->assertSame($payload['name'], (string) DB::table('plans')->where('id', $plan->id)->value('name'),
            'the unrelated field was not saved');

        $this->assertSame('Year', $this->storedDuration($plan->id),
            'an absent duration key must leave the column untouched, not blank it');
    }

    /**
     * REASON TWO, and the one that matters for historical rows: an absent `duration` cannot
     * introduce a bad value, because `$plan->update($request->all())` never touches a key the
     * request did not carry. So `required` on update would buy no protection and would cost the
     * ability to edit an unrelated field on a row whose duration is already unapproved.
     */
    public function test_an_unrelated_edit_to_a_plan_with_an_invalid_stored_duration_is_not_blocked(): void
    {
        $plan = $this->makePlan(['duration' => self::UNAPPROVED]);

        $payload = $this->updatePayload($plan, ['max_products' => 77]);
        unset($payload['duration']);

        $this->actingAs($this->operator())
            ->put(route('plan.update', $plan->id), $payload);

        $this->assertNull($this->flashedError(),
            'editing an unrelated field on a plan with a legacy duration was blocked: '
            . (string) $this->flashedError());

        $this->assertSame('77', (string) DB::table('plans')->where('id', $plan->id)->value('max_products'),
            'the unrelated field was not saved');

        $this->assertSame(
            self::UNAPPROVED,
            $this->storedDuration($plan->id),
            'the legacy duration was changed by an edit that never mentioned it'
        );
    }

    /**
     * The same plan, now edited through the form the operator actually uses -- which DOES carry
     * `duration`. The legacy value is only ever replaced by one the operator explicitly supplies.
     */
    public function test_a_legacy_duration_is_replaced_only_by_an_explicitly_submitted_approved_one(): void
    {
        $plan = $this->makePlan(['duration' => self::UNAPPROVED]);

        $this->actingAs($this->operator())
            ->put(route('plan.update', $plan->id), $this->updatePayload($plan, ['duration' => 'Year']));

        $this->assertSame('Year', $this->storedDuration($plan->id),
            'an operator who explicitly picks an approved duration must be able to correct the row');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * Both update branches. `update()` builds TWO rule sets -- one for `price > 0`, one for a free
     * plan -- and a rule added to only one of them is a hole wide enough to drive the whole defect
     * back through.
     * ---------------------------------------------------------------------------------------------
     */

    public function test_the_free_plan_branch_refuses_an_unapproved_duration_too(): void
    {
        $plan = $this->makePlan(['price' => 0, 'duration' => 'Unlimited']);

        $this->assertSame('0', (string) DB::table('plans')->where('id', $plan->id)->value('price'),
            'premise: this fixture must take update()\'s `price == 0` branch');

        $this->actingAs($this->operator())
            ->put(route('plan.update', $plan->id), $this->updatePayload($plan, [
                'price' => 0,
                'duration' => self::UNAPPROVED,
            ]));

        $this->assertSame(
            'Unlimited',
            $this->storedDuration($plan->id),
            'the free-plan rule set in update() does not validate `duration`. There are TWO rule '
            . 'arrays in that method and the rule must be in both.'
        );
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * What the FORM does with a legacy value — a pre-existing property of the view, recorded here
     * because it decides what an operator actually experiences and it is not something validation
     * can fix. It is NOT changed by this work.
     * ---------------------------------------------------------------------------------------------
     */

    /**
     * THE EDIT FORM SHOWS THE ROW ITS OWN STORED VALUE, SELECTED AND MARKED AS WITHDRAWN.
     *
     * THIS CASE USED TO ASSERT THE OPPOSITE, AND WHAT IT MEASURED WAS A SILENT REWRITE.
     * `plans/edit.blade.php` renders `Form::select('duration', $arrDuration, null, ...)` inside a
     * `Form::model($plan, ...)`, so the selected value comes from the bound row. With only the
     * three options bound, a stored `Yearly` matched none of them, NO `selected` attribute was
     * emitted at all, and a browser then showed and submitted the FIRST option -- `Unlimited`. An
     * operator opening this form to change `max_products` and pressing Save therefore replaced the
     * historical value with `Unlimited`, and the H1 rule ACCEPTED it, because `Unlimited` is
     * approved. The change meant to stop silent rewrites was performing one.
     *
     * The alternatives were worse and were rejected for the reasons the decision gives. Offering
     * the legacy word to every plan re-introduces a withdrawn billing period. Auto-correcting it on
     * save is the silent rewrite the decision forbids by name. Refusing the submission blocks an
     * edit to an unrelated field. So the row is shown its own value, labelled, and may send that
     * one value back -- which writes nothing new.
     */
    public function test_the_edit_form_offers_the_rows_own_legacy_duration_selected_and_labelled(): void
    {
        // A FILLER PLAN FIRST, so the one under test is not id 1. Under RefreshDatabase the first
        // `Plan::create()` of a test takes id 1, and `plans/edit.blade.php` wraps the price AND
        // duration fields in `@if ($plan->id != 1)` -- so without this the page renders no
        // duration field at all and the premise below would fail for the wrong reason. Measured:
        // that is exactly how the first version of this case failed.
        $this->makePlan();

        $plan = $this->makePlan(['duration' => self::UNAPPROVED]);

        $this->assertNotSame(1, (int) $plan->id,
            'premise: the plan under test must not be id 1, whose edit form renders no duration field');

        $select = $this->durationSelect($plan);

        $this->assertNotSame('', $select, 'premise: the edit form renders a `duration` select');

        $this->assertMatchesRegularExpression(
            '/<option value="' . preg_quote(self::UNAPPROVED, '/') . '"[^>]*\bselected\b/',
            $select,
            'THE STORED VALUE IS NOT THE SELECTED OPTION. With no option matching the row, a '
            . 'browser shows and submits the FIRST one -- so an operator editing an unrelated '
            . 'field on this plan silently replaces `' . self::UNAPPROVED . '` with `Unlimited`, '
            . 'and the H1 rule accepts it because `Unlimited` is approved. Selected option set: '
            . $select
        );

        $this->assertStringNotContainsString(self::UNAPPROVED . '</option>', $select,
            'the legacy word is offered unlabelled, so it reads as a billing period this product '
            . 'still sells. It must carry the withdrawn label from `resources/lang/*.json`.');
    }

    /**
     * AND THE LABEL IS A TRANSLATION KEY, not concatenation -- so the Arabic panel reads right.
     *
     * Asserted by driving the Arabic locale rather than by reading the source, because a key that
     * exists in `en.json` and not in `ar.json` falls back to the English text and no source census
     * can see the difference.
     */
    public function test_the_withdrawn_label_is_translated_for_the_arabic_panel(): void
    {
        $this->makePlan();

        $plan = $this->makePlan(['duration' => self::UNAPPROVED]);

        $english = __(':duration (no longer offered)', ['duration' => self::UNAPPROVED], 'en');
        $arabic = __(':duration (no longer offered)', ['duration' => self::UNAPPROVED], 'ar');

        $this->assertStringContainsString(self::UNAPPROVED, $english,
            'premise: the label interpolates the stored word rather than dropping it');

        $this->assertNotSame($english, $arabic,
            'the withdrawn label is not translated: `ar.json` has no entry for it, so the Arabic '
            . 'panel renders the English sentence. The key is `:duration (no longer offered)`.');

        $this->assertStringContainsString(self::UNAPPROVED, $arabic,
            'the Arabic label drops the stored word, so an operator cannot see which value the row '
            . 'actually holds');
    }

    /**
     * THE OPTION IS PER-ROW. A plan whose duration IS approved gets exactly the three.
     *
     * This is the half that separates "show this row its own value" from "add a fourth billing
     * period", and it is the one a careless implementation loses: appending the legacy word to the
     * shared `Plan::$arrDuration`, or to the array `create()` renders from, would offer `Yearly`
     * to every plan in the installation and to every new one.
     */
    public function test_a_plan_whose_duration_is_approved_is_offered_only_the_three(): void
    {
        $this->makePlan(['duration' => self::UNAPPROVED]);

        $plan = $this->makePlan(['duration' => 'Month']);

        $this->assertNotSame(1, (int) $plan->id, 'premise: not the Free Plan, which renders no field');

        $select = $this->durationSelect($plan);

        $this->assertNotSame('', $select, 'premise: the edit form renders a `duration` select');

        $this->assertSame(
            count($this->approvedDurations()),
            preg_match_all('/<option /', $select),
            'this plan is offered more than the three approved durations. Another row\'s legacy '
            . 'word has leaked into the shared option list, which re-introduces a withdrawn '
            . 'billing period for every plan. Rendered: ' . $select
        );

        $this->assertStringNotContainsString(self::UNAPPROVED, $select,
            'a legacy word from a different row is offered here');
    }

    /**
     * AND THE CREATE FORM IS NEVER WIDENED. There is no row, so there is no historical value.
     */
    public function test_the_create_form_offers_only_the_three(): void
    {
        $this->makePlan(['duration' => self::UNAPPROVED]);

        $html = $this->actingAs($this->operator())
            ->get(route('plan.create'))
            ->assertOk()
            ->getContent();

        $select = $this->between($html, '<select', '</select>', 'name="duration"');

        $this->assertNotSame('', $select, 'premise: the create form renders a `duration` select');

        $this->assertStringNotContainsString(self::UNAPPROVED, $select,
            'the CREATE form offers a legacy word, so a brand-new plan can be sold on a billing '
            . 'period the decision withdrew. Whatever an existing row holds, a new one may only '
            . 'ever be one of the three.');
    }

    /**
     * THE ROW MAY SEND ITS OWN WORD BACK, and that is not a rewrite -- it writes what is already
     * there.
     *
     * Without this the form above would be a trap: it would show `Yearly` selected, the browser
     * would submit `Yearly`, and the rule would refuse the whole edit. The allowance is read from
     * the STORED ROW and never from the request, so it widens nothing: the next case drives the
     * same word at a plan that does not carry it.
     */
    public function test_a_plan_may_resubmit_the_legacy_word_it_already_carries(): void
    {
        $plan = $this->makePlan(['duration' => self::UNAPPROVED]);

        $this->assertSame(self::UNAPPROVED, $this->storedDuration($plan->id), 'premise');

        $this->actingAs($this->operator())->put(
            route('plan.update', $plan->id),
            $this->updatePayload($plan, ['duration' => self::UNAPPROVED, 'max_products' => 77])
        );

        $this->assertNull($this->flashedError(),
            'the edit was refused, so an operator cannot change any other field on this plan '
            . 'through its own form -- the form shows `' . self::UNAPPROVED . '` selected and the '
            . 'browser submits it. Refused with: ' . (string) $this->flashedError());

        $this->assertSame(self::UNAPPROVED, $this->storedDuration($plan->id),
            'the historical value did not survive its own resubmission');

        $this->assertSame(77, (int) DB::table('plans')->where('id', $plan->id)->value('max_products'),
            'the unrelated field was not saved, so the edit did not go through');
    }

    /**
     * AND NOT ANOTHER ROW'S. The allowance is per-plan or it is a fourth billing period.
     *
     * This is the case that proves the widening comes from the stored column rather than from a
     * set that grew. A plan stored as `Month` must refuse `Yearly` even while another plan in the
     * same installation legitimately carries it.
     */
    public function test_a_plan_that_does_not_carry_the_legacy_word_still_refuses_it(): void
    {
        $this->makePlan(['duration' => self::UNAPPROVED]);

        $plan = $this->makePlan(['duration' => 'Month']);

        $this->actingAs($this->operator())->put(
            route('plan.update', $plan->id),
            $this->updatePayload($plan, ['duration' => self::UNAPPROVED])
        );

        $this->assertNotNull($this->flashedError(),
            'A PLAN WAS MOVED ONTO `' . self::UNAPPROVED . '`, which is not an approved duration. '
            . 'The allowance is meant to be the row\'s OWN stored value, so that it can be sent '
            . 'back unchanged; if it is instead a set that another row widened, the decision\'s '
            . 'vocabulary is not enforced at all.');

        $this->assertSame('Month', $this->storedDuration($plan->id),
            'the refused value reached the column');
    }

    /**
     * AND A ROW CARRYING ONE LEGACY WORD DOES NOT ACCEPT A DIFFERENT ONE.
     *
     * The allowance is that exact stored value, not "anything unapproved once you already hold
     * something unapproved".
     */
    public function test_a_row_carrying_a_legacy_word_refuses_a_different_unapproved_one(): void
    {
        $plan = $this->makePlan(['duration' => self::UNAPPROVED]);

        $this->actingAs($this->operator())->put(
            route('plan.update', $plan->id),
            $this->updatePayload($plan, ['duration' => 'Quarterly'])
        );

        $this->assertNotNull($this->flashedError(),
            'a row already carrying an unapproved duration accepted a DIFFERENT unapproved one, so '
            . 'the allowance is a hole rather than a per-value exception');

        $this->assertSame(self::UNAPPROVED, $this->storedDuration($plan->id),
            'the stored historical value was replaced by another unapproved word');
    }

    /**
     * AND CREATE IS UNAFFECTED, whatever any existing row holds.
     */
    public function test_an_existing_legacy_row_does_not_let_a_new_plan_be_created_with_that_word(): void
    {
        $this->makePlan(['duration' => self::UNAPPROVED]);

        $payload = $this->createPayload(['duration' => self::UNAPPROVED]);

        $this->actingAs($this->operator())->post(route('plan.store'), $payload);

        $this->assertNull(
            DB::table('plans')->where('name', $payload['name'])->value('id'),
            'A NEW PLAN WAS CREATED CARRYING `' . self::UNAPPROVED . '` because another row '
            . 'already held it. The per-row allowance is for editing history, not for selling a '
            . 'withdrawn billing period again.'
        );
    }

    /**
     * The `duration` select of one plan's edit page, scoped to that element.
     */
    private function durationSelect(Plan $plan): string
    {
        $html = $this->actingAs($this->operator())
            ->get(route('plan.edit', $plan->id))
            ->assertOk()
            ->getContent();

        return $this->between($html, '<select', '</select>', 'name="duration"');
    }

    /**
     * The slice of `$html` for the element whose opening tag contains `$needle`, from `$open` to
     * the first `$close` after it. SCOPED, because an assertion over a whole rendered page answers
     * "does this string appear anywhere" -- and this page prints `selected` in several other
     * selects (themes, modules) and the plan's own name.
     */
    private function between(string $html, string $open, string $close, string $needle): string
    {
        $from = 0;

        while (($from = strpos($html, $open, $from)) !== false) {
            $to = strpos($html, $close, $from);

            if ($to === false) {
                return '';
            }

            $slice = substr($html, $from, $to - $from);

            if (str_contains($slice, $needle)) {
                return $slice;
            }

            $from = $to;
        }

        return '';
    }
}
