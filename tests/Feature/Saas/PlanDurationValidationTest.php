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
     * Historical values: refused, but never rewritten
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_plan_already_carrying_an_unapproved_duration_cannot_be_re_saved_with_it(): void
    {
        // The row exists -- an import, a direct SQL edit, or a POST made before this rule. The
        // model has no validation of its own, which is exactly how such a row gets there.
        $plan = $this->makePlan(['duration' => self::UNAPPROVED]);

        $this->assertSame(self::UNAPPROVED, $this->storedDuration($plan->id), 'premise');

        $this->actingAs($this->operator())
            ->put(route('plan.update', $plan->id), $this->updatePayload($plan, [
                'duration' => self::UNAPPROVED,
            ]));

        $this->assertNotNull($this->flashedError(),
            'resubmitting the stored unapproved value was accepted; the row is still saveable as it '
            . 'stands, which is what decision H1 forbids');
    }

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
     * THE EDIT FORM CANNOT SHOW A VALUE THAT IS NOT ONE OF ITS OPTIONS, so it silently offers the
     * first one.
     *
     * `plans/edit.blade.php` renders `Form::select('duration', $arrDuration, null, ...)` inside a
     * `Form::model($plan, ...)`, so the selected value comes from the bound row. A stored `Yearly`
     * matches none of the three options, no `selected` attribute is emitted, and a browser then
     * shows and submits the FIRST option -- `Unlimited`. An operator who opens this form to change
     * an unrelated field and saves therefore replaces the legacy value with `Unlimited`, and the
     * new rule accepts it because `Unlimited` is approved.
     *
     * THAT IS A CONSEQUENCE FOR THE OWNER TO ACCEPT OR REJECT, not something to work around here:
     * pre-selecting the legacy value would mean rendering a fourth option and re-offering a word
     * the decision withdrew, and auto-correcting it on save is the silent rewrite the decision
     * forbids. This case exists so the behaviour is measured rather than assumed, and so that it
     * cannot change without somebody reading this.
     */
    public function test_the_edit_form_shows_no_selected_option_for_a_legacy_duration(): void
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

        $html = $this->actingAs($this->operator())
            ->get(route('plan.edit', $plan->id))
            ->assertOk()
            ->getContent();

        $select = $this->between($html, '<select', '</select>', 'name="duration"');

        $this->assertNotSame('', $select, 'premise: the edit form renders a `duration` select');
        $this->assertStringNotContainsString(self::UNAPPROVED, $select,
            'the select now offers the legacy word as a fourth option, which re-introduces a '
            . 'billing period the decision withdrew');
        $this->assertStringNotContainsString('selected', $select,
            'an option is pre-selected for a stored duration that is not one of the three. If that '
            . 'is deliberate, this case and the docblock above it must be rewritten to say which '
            . 'option and why.');
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
