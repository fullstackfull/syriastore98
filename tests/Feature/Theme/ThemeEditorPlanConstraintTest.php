<?php

namespace Tests\Feature\Theme;

use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GAP-114 / P03-E-04 — DOES A PLAN'S THEME LIST STILL CONSTRAIN WHAT THE EDITOR OFFERS?
 * =============================================================================================
 *
 * The register carried this as IMPLEMENTED - VERIFICATION INCOMPLETE: the constraint is written
 * (`ThemeCustomizeController::index` explodes `plans.themes`), but **no test pinned it**, and
 * Phase 03 M4 rewrote the authorization around it. A constraint nothing asserts is a constraint
 * that survives only as long as nobody refactors near it, and M4 already demonstrated the hazard
 * in the adjacent gate — its own docblock records a defence-in-depth check that was STRICTER than
 * the middleware and locked the store owner out of their own editor.
 *
 * ---------------------------------------------------------------------------------------------
 * WHAT THIS PINS, AND THE ONE THING IT DELIBERATELY DOES NOT CHANGE.
 *
 * The handler builds the list as:
 *
 *     $plan = Plan::find($user->plan_id);
 *     $themes = !empty($plan->themes) ? explode(',', $plan->themes)
 *                                     : ['stylique', 'greentic', 'techzonix'];
 *     if ($currentTheme) { array_unshift($themes, $currentTheme); }
 *     $themes = array_unique($themes);
 *
 * So the plan restricts the list — **and the store's CURRENT theme is added back regardless of the
 * plan.** A store that was on `techzonix` before a downgrade to a `stylique`-only plan is still
 * offered `techzonix`.
 *
 * THAT IS MEASURED AND PINNED AS THE CURRENT BEHAVIOUR, NOT CORRECTED. It is defensible — hiding
 * the theme a merchant is actually running would make the editor unable to edit the live site, and
 * the alternative (forcing a downgraded store off its theme) is a commercial decision about what a
 * plan downgrade does to an existing store, which engineering must not take. A test that pins it
 * is what turns it from an accident into a decision somebody can look at; the register row records
 * the question.
 *
 * `array_unique` keeps FIRST occurrences, so the current theme appears once even when the plan
 * lists it too.
 *
 * ---------------------------------------------------------------------------------------------
 * THE ASSERTIONS READ THE VIEW DATA, not the rendered HTML. The editor's page renders theme cards
 * through a Blade that also mentions theme names in copy and asset paths, so a `assertSee` would
 * pass on a name the handler never offered. `viewData('themes')` is the handler's actual answer.
 */
class ThemeEditorPlanConstraintTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = User::forceCreate([
            'name' => 'Plan Merchant',
            'email' => 'plan-merchant@example.test',
            'password' => bcrypt('x'),
            'type' => 'admin',
            'created_by' => 1,
            'email_verified_at' => now(),
        ]);

        $this->store = Store::create([
            'name' => 'Plan Store',
            'email' => 'plan-store@example.test',
            'slug' => 'plan-store',
            'theme_id' => 'stylique',
            'created_by' => $this->merchant->id,
            'is_active' => 1,
            'default_language' => 'en',
        ]);

        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();
    }

    /**
     * A plan whose `themes` column lists exactly these, assigned to the merchant.
     *
     * `$themes` IS A STRING AND NEVER NULL, because `plans.themes` is `text` **NOT NULL** — read
     * from the migration after an insert failed on it. So the "plan does not restrict themes" state
     * that reaches the handler's fallback is an EMPTY STRING, not a null, which is exactly what
     * `!empty($plan->themes)` tests. `storage_limit` is NOT NULL with no default either.
     */
    private function planOffering(string $themes): void
    {
        $planId = DB::table('plans')->insertGetId([
            'name' => 'Plan ' . uniqid(),
            'price' => 0,
            'duration' => 'Monthly',
            'max_users' => 5,
            'max_stores' => 1,
            'storage_limit' => 100,
            'themes' => $themes,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->merchant->plan_id = $planId;
        $this->merchant->save();

        /* `User` is Cachable, so an Eloquent read would serve the pre-write row for the rest of
           this test -- CLAUDE.md records three measured false readings from exactly this. */
        (new User())->flushCache();

        $this->assertSame(
            $themes,
            DB::table('plans')->where('id', $planId)->value('themes'),
            'premise: the plan did not store the theme list this test is about'
        );
    }

    /** @return list<string> the themes the handler actually offered */
    private function offeredThemes(): array
    {
        $response = $this->actingAs(User::find($this->merchant->id))->get('/theme-customize');

        $this->assertSame(200, $response->status(),
            'the editor refused the store\'s own owner, so every assertion about the OFFER below '
            . 'would be vacuous -- that is the M4 regression this file sits next to');

        return array_values((array) $response->viewData('themes'));
    }

    /** THE ROW'S OWN QUESTION: a plan-restricted merchant is offered only their plan's themes. */
    public function test_a_plan_restricted_merchant_is_offered_only_their_plans_themes(): void
    {
        /* The store is on `stylique` and the plan lists `stylique` only, so the current-theme
           exception below cannot widen the answer and this case measures the plan alone. */
        $this->planOffering('stylique');

        $offered = $this->offeredThemes();

        $this->assertSame(['stylique'], $offered,
            'the plan lists one theme and the editor offered ' . json_encode($offered)
            . '. The plan constraint is not being applied.');

        foreach (['greentic', 'techzonix'] as $outsidePlan) {
            $this->assertNotContains($outsidePlan, $offered,
                $outsidePlan . ' is outside this merchant\'s plan and was offered anyway');
        }
    }

    /** Two of three: the list follows the plan rather than being a fixed set. */
    public function test_the_offer_follows_the_plan_rather_than_a_fixed_list(): void
    {
        $this->planOffering('stylique,greentic');

        $offered = $this->offeredThemes();

        sort($offered);
        $this->assertSame(['greentic', 'stylique'], $offered);
        $this->assertNotContains('techzonix', $offered);
    }

    /**
     * AN EMPTY theme list falls back to the documented default three, not to nothing.
     *
     * Empty STRING and not null: `plans.themes` is NOT NULL, so a null plan list is unreachable
     * through the column. The handler's `!empty()` is therefore the right test for the right state,
     * which is worth pinning because `!empty()` and `!== null` would behave differently here and a
     * future tightening to the latter would silently empty the editor for every such plan.
     */
    public function test_a_plan_with_an_empty_theme_list_falls_back_to_the_default_three(): void
    {
        $this->planOffering('');

        $offered = $this->offeredThemes();

        sort($offered);
        $this->assertSame(['greentic', 'stylique', 'techzonix'], $offered,
            'a plan with an empty theme list must fall back to the default three, not to an empty '
            . 'editor -- a merchant with no plan row would otherwise be unable to edit anything');
    }

    /**
     * THE CURRENT THEME IS OFFERED EVEN WHEN THE PLAN DOES NOT LIST IT — measured and pinned as the
     * behaviour, NOT corrected.
     *
     * A store on `techzonix` whose plan allows only `stylique` — the shape a plan DOWNGRADE
     * produces — is still offered `techzonix`. Defensible: hiding the theme the merchant is
     * actually running would leave them unable to edit their live site. Forcing a downgraded store
     * off its theme is a commercial decision about what a downgrade does to an existing store, and
     * engineering must not take it. This test exists so the behaviour is a decision somebody can
     * look at rather than an accident, and `GAP-114`'s row carries the question.
     */
    public function test_the_stores_current_theme_is_offered_even_when_the_plan_excludes_it(): void
    {
        $this->store->theme_id = 'techzonix';
        $this->store->save();
        (new Store())->flushCache();

        $this->planOffering('stylique');

        $offered = $this->offeredThemes();

        $this->assertContains('stylique', $offered, 'the plan\'s own theme was dropped');

        /*
         * A HARD ASSERTION AND NOT A SKIP, and the first version of this case got that wrong.
         *
         * It read `if (! in_array('techzonix', $offered)) { markTestSkipped(...) }` on the
         * reasoning that `APP_THEME()` might resolve from the config default rather than the
         * store. Measured: removing the `array_unshift` that implements this exception made the
         * case SKIP instead of FAIL -- a survived mutation hiding behind a skip, which is worse
         * than having no test, because it reads as covered.
         *
         * It is also a manifest hazard: `.github/known-test-outcomes.json` declares the accepted
         * outcome SET and the gate asserts it in both directions, so a test that can skip on an
         * environment difference would redden the build as an UNDECLARED skip.
         *
         * `APP_THEME()` does resolve to the store's `theme_id` in this harness -- measured -- so
         * the premise is assertable. If it ever stops, this fails loudly with the reason, which is
         * the right outcome for a premise nobody can rely on any more.
         */
        $this->assertContains('techzonix', $offered,
            'the store\'s current theme was not offered. Either the current-theme exception is '
            . 'gone, or APP_THEME() no longer resolves to the store\'s theme_id -- read which '
            . 'before changing this test, because only the first is a defect.');
        $this->assertSame(
            count($offered),
            count(array_unique($offered)),
            'the current theme was added to a list that already contained it, so the editor offers '
            . 'a duplicate card'
        );
    }
}
