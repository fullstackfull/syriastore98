<?php

namespace Tests\Feature\Security;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * GAP-266 / GAP-020 / OWNER DECISION I17 -- A PLAN'S SEAT CASCADE DEACTIVATES THE ACCOUNT IT IS
 * BEING APPLIED TO, AND THE SUSPENSION GUARD COMPLETES THE LOCKOUT ONE REQUEST LATER.
 * =================================================================================================
 *
 * PINNED, NOT FIXED, AND THAT IS A DELIBERATE STOP RATHER THAN AN OMISSION.
 * `docs/development/OWNER-DECISION-PACKAGE.md` I17 puts this to the owner as two questions and says
 * of the first: *"the predicate should exclude the subject itself (a one-line fix engineering can
 * make on your word)"*. There is no dated `DECIDED` line on I17 -- only `H1`-`H5` carry one -- and
 * that file's own closing rule is that *"a recommendation in this file is a recommendation; only a
 * dated `DECIDED` line is an answer"*. So the word has not been given, and the predicate is left
 * exactly as it is. `app/Models/User.php` is byte-identical to `HEAD` in the change that added this
 * file, which also keeps the `:487-519` citations in the register, in I17 and in
 * `MerchantJourneyTest` pointing at the lines they name.
 *
 * THE MECHANISM. `User::assignPlan()` ends in a seat cascade (`app/Models/User.php:487-519`):
 *
 *     $users = User::where('created_by', '=', $this->id)->where('type','!=','super admin')->get();
 *     ... foreach: $userCount <= $plan->max_users ? is_active = 1 : is_active = 0
 *
 * The set is "the rows this subject created", so a row whose `created_by` equals its own `id`
 * SELECTS ITSELF. The existing exemption at :487 covers `type = 'super admin'` only, and a merchant
 * is type `admin`, so the uncovered row is a merchant who is its own creator -- which registration
 * makes the merchant holding **id 1**, because it hard-codes `created_by => 1`
 * (`Auth\RegisteredUserController:133` and `:286`).
 *
 * REACHABILITY, NOT OVERSTATED. Latent on a seeded install, where user 1 is the operator so no
 * merchant is id 1; live wherever the first `users` row is a merchant -- a from-zero database, a
 * restored install whose operator row was recreated, a tenant-only import. The seat half is live for
 * ANY plan carrying the column default: `plans.max_users` is `string` DEFAULT 0 and NOT nullable, so
 * a plan inserted or imported without that field holds `'0'`, and `1 <= '0'` is false.
 *
 * WHAT THIS FILE ADDS over the two places that already cover this, rather than repeating them:
 *
 *   - `MerchantJourneyTest::test_control_the_plan_seat_cascade_can_deactivate_the_subject_itself`
 *     pins the COLUMN (`is_active` becomes '0'). It records the panel lockout in prose and does not
 *     assert it. Nothing in the suite asserted that half, and it is the half that makes I17 urgent:
 *     a flag is a row, a sign-out is the customer losing the product they just paid for.
 *   - `Tests\Feature\Saas\PlanAssignmentCascadeSafetyTest` already holds the two controls -- the
 *     operator subject cascading over nobody (:88) and a merchant's own staff still being limited
 *     (:121) -- so they are NOT duplicated here. They are the cases a one-predicate fix must keep
 *     green, and they answer the second of I17's questions, which is the owner's alone.
 *
 * AND THE HTTP LEG HAS TWO PRECONDITIONS THAT COST A FALSE READING, recorded because they will cost
 * the next reader one too. A first version of this file asserted `auth('web')->check()` on a subject
 * built with `makeMerchant()`. It went red -- with NO plan applied at all, and `session('status')`
 * empty: a merchant with no store is signed out of `/dashboard` by design
 * (`HomeController:694`, flash `'You have been logged out.'`, pinned by
 * `StorelessMerchantDashboardTest`). A bare authentication assertion cannot tell this defect from
 * that one. So the subject here owns a store, `satisfyUpdaterGate()` is called, the pre-plan request
 * is ASSERTED to answer 200, and the post-plan assertion names the suspension guard's own flash
 * string rather than authentication state.
 *
 * WHEN I17 IS DECIDED, INVERT THIS FILE -- do not delete it. Both cases below are named
 * `test_MEASURED_*` by this repository's convention for a pin on open behaviour, and both fail if
 * the predicate changes, so neither answer can land silently.
 */
class PlanSeatCascadeSelfLockoutTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The exact string `SuspendedAccountGuard` flashes, and nothing else in the application does. */
    private const SUSPENSION_FLASH = 'Your Account is de-activate,please contact your Administrator.';

    /**
     * A plan whose seat allowance is `$maxUsers`.
     *
     * ZERO SEATS, NOT ONE, AND THAT IS A CORRECTION PAID FOR. With `max_users = 1` and two rows the
     * cascade has one seat to give and NO `orderBy`, so whether the subject keeps it is the engine's
     * row order -- measured: the subject came first and kept it, and the first version of the case
     * below PASSED while the defect stood. A zero-seat plan switches every row in the set off, so
     * the result no longer depends on an ordering nobody specified. Zero is also the column default,
     * so this is the reachable value rather than a contrived one.
     *
     * `max_stores` is left generous so the twin STORE cascade below the user one cannot be what
     * produces any result here.
     */
    private function planAllowing(int $maxUsers): Plan
    {
        return Plan::forceCreate([
            'name' => 'seat-' . $this->uniqueFixtureRef(),
            'price' => 0,
            'duration' => 'Unlimited',
            'max_users' => $maxUsers,
            'max_stores' => 99,
            'themes' => '[]',
            'storage_limit' => '100',
            'shipping_method' => 'off',
            'enable_chatgpt' => 0,
            'trial' => 0,
            'trial_days' => 0,
        ]);
    }

    /**
     * A merchant whose `created_by` equals its OWN id -- the precondition, built explicitly.
     *
     * A TENANT RATHER THAN A BARE MERCHANT, and the store is load-bearing: see the class docblock on
     * the storeless sign-out. Both columns are set directly rather than relying on insert order,
     * because a test that depends on "the first row gets id 1" is a test of the fixture.
     */
    private function selfCreatedMerchant(): User
    {
        [$merchant] = $this->makeTenant();

        DB::table('users')->where('id', $merchant->id)->update(['created_by' => $merchant->id]);

        /* THE MODEL CACHE, which would otherwise serve the pre-update row. `User` is one of the 79
           `Cachable` models and the write above is RAW, so Eloquent -- route-model binding included
           -- would go on answering the old `created_by` for the rest of the test. CLAUDE.md records
           three measured false readings from exactly this. */
        (new User)->flushCache();

        $fresh = User::find($merchant->id);

        $this->assertSame(
            (int) $fresh->id,
            (int) $fresh->created_by,
            'premise: this merchant is in its own seat cascade, which is the only way the subject '
            . 'can be among the rows assignPlan() switches off'
        );

        $this->assertSame('1', (string) $fresh->is_active, 'premise: the subject starts active');

        return $fresh;
    }

    private function isActive(int $userId): string
    {
        return (string) DB::table('users')->where('id', $userId)->value('is_active');
    }

    /**
     * THE COLUMN. Applying a zero-seat plan to its own subject switches that subject off.
     *
     * Inverts to `'1'` when I17's first question is answered.
     */
    #[Test]
    public function test_MEASURED_the_seat_cascade_deactivates_the_subject_of_its_own_plan(): void
    {
        $merchant = $this->selfCreatedMerchant();

        User::find($merchant->id)->assignPlan($this->planAllowing(0)->id);

        $this->assertSame(
            '0',
            $this->isActive($merchant->id),
            'THE CASCADE NO LONGER DEACTIVATES THE SUBJECT OF ITS OWN PLAN. That is the fix I17 '
            . 'question 1 describes, and it is an improvement -- so INVERT this case and its '
            . 'sibling rather than deleting them, and record the decision on I17. '
            . 'app/Models/User.php:487-519.'
        );
    }

    /**
     * THE CONSEQUENCE, WHICH NOTHING ELSE IN THE SUITE ASSERTS: the subject is locked out of the
     * panel on its very next request.
     *
     * `SuspendedAccountGuard` is appended to the whole `web` group (`bootstrap/app.php`) and calls
     * `auth($guard)->logout()` for any principal `AccountSuspension::reasonFor()` answers for -- so
     * the row the cascade wrote becomes a sign-out, with the operator's deactivation message, on an
     * account that has just been given a plan. On a paid path that is a customer who has just paid.
     */
    #[Test]
    public function test_MEASURED_the_subject_is_locked_out_of_the_panel_on_its_next_request(): void
    {
        $this->satisfyUpdaterGate();

        $merchant = $this->selfCreatedMerchant();

        /* THE INSTRUMENT, BEFORE THE PLAN. Without this the 302 below could be the storeless
           sign-out, the updater gate or any other diversion -- which is exactly what the first
           version of this file actually measured. */
        $before = $this->actingAs(User::find($merchant->id))->get('/dashboard');

        $this->assertSame(
            200,
            $before->getStatusCode(),
            'premise: this subject reaches its own dashboard BEFORE any plan is applied, so a '
            . 'refusal afterwards is attributable to the plan. Got ' . $before->getStatusCode()
            . ' -> ' . json_encode($before->headers->get('Location'))
            . ', session message ' . json_encode(session('message'))
        );

        User::find($merchant->id)->assignPlan($this->planAllowing(0)->id);

        $after = $this->actingAs(User::find($merchant->id))->get('/dashboard');

        $this->assertSame(
            302,
            $after->getStatusCode(),
            'THE SUBJECT IS NO LONGER LOCKED OUT after its own plan is applied. Invert this case '
            . 'together with its sibling and record the decision on I17.'
        );

        $this->assertSame(
            route('login'),
            $after->headers->get('Location'),
            'the lockout no longer sends the subject to the sign-in page; re-measure before '
            . 'inverting, because the cascade may still be writing the row'
        );

        /* KEYED ON THE GUARD'S OWN STRING rather than on `auth('web')->check()`: nothing else in the
           application flashes it, so this cannot be satisfied -- or broken -- by an unrelated
           sign-out, which is the trap recorded in the class docblock. */
        $this->assertSame(
            self::SUSPENSION_FLASH,
            (string) session('status'),
            'the subject was signed out, but not by `SuspendedAccountGuard` -- so this case is no '
            . 'longer measuring GAP-266 and the premise above needs re-reading. Status flash: '
            . json_encode(session('status'))
        );
    }
}
