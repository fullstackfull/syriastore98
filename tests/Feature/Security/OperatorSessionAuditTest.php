<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Utility;
use App\Support\Iam\OperatorSessionAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * `IAM-002` / `IAM-004` — THE PLATFORM OPERATOR'S SESSIONS REACH THE CENTRAL AUDIT LOG.
 *
 * Recovered decision texts (`docs/development/master-plan/DECISION-ID-REGISTER.md:238`, `:240`):
 * *"مصادقة قوية وإدارة الجلسات."* and *"سجل تدقيق مركزي واستجابة للحوادث."* Neither id appears in
 * `MASTER-PLAN-REQUIREMENT-TRACEABILITY.md` or in `MASTER-PLAN-GAP-REGISTER.md` — measured by
 * grep over both files.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *  WHAT WAS MEASURED BEFORE THIS SLICE
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `AuthenticatedSessionController` carried **zero** references to `AuditLog`. So `audit_logs`
 * recorded what the operator DID and never recorded that they signed in, from where, or that
 * somebody tried and failed. `IAM-004`'s load-bearing word is *"مركزي"* — central — and a trail
 * that cannot answer "who held the operator session when this happened" cannot support the
 * incident response the same decision asks for.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *  THE THREE PROPERTIES THESE CASES PIN, AND WHY EACH IS HERE
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 *   1. **THE ROW EXISTS, WITH THE RIGHT ACTOR.** A sign-in row attributed to nobody, or a failed
 *      attempt attributed to the victim as though they had acted, are both worse than no row.
 *   2. **NOTHING A VISITOR SEES CHANGED.** A sign-in path is the worst place in the product to
 *      take a risk, so a case drives a wrong password and asserts the response is still the
 *      ordinary refusal and the visitor is still not authenticated.
 *   3. **THE VOLUME CLAIM IS TRUE.** The recorder is scoped to the operator because an
 *      `Illuminate\Auth\Events\Login` listener would write a row per Sanctum-authenticated API
 *      request (`CustomAuth:110-117` calls `auth()->login()`, on 189 route declarations). A
 *      merchant sign-in writing a row would falsify that, so a case asserts it writes none.
 *
 * READ THROUGH `DB::table('audit_logs')` WHEREVER THE QUESTION IS "WHAT IS STORED". `AuditLog` is
 * one of the 16 models that is NOT `Cachable`, so an Eloquent read is safe here — but the raw read
 * is what answers "what is in the column", which is the question for the no-caller-text case.
 */
class OperatorSessionAuditTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'iam002-operator-password';

    // ────────────────────────────────────────────────────────────────────────────────────────
    //  SUCCESSFUL SIGN-IN
    // ────────────────────────────────────────────────────────────────────────────────────────

    #[Test]
    public function test_an_operator_sign_in_is_recorded_in_the_central_audit_log(): void
    {
        $operator = $this->signableOperator();

        $response = $this->post(route('login'), [
            'email' => $operator->email,
            'password' => self::PASSWORD,
        ]);

        $this->assertTrue(
            in_array($response->getStatusCode(), [200, 302], true),
            'premise: the login route answered. Status ' . $response->getStatusCode()
        );
        $this->assertAuthenticated('web');

        $row = $this->soleRow(OperatorSessionAudit::SIGNED_IN);

        $this->assertSame(
            (int) $operator->id,
            (int) $row->user_id,
            'the sign-in row does not name the operator as the actor, so the trail cannot say who '
            . 'held the session.'
        );
        $this->assertSame(User::class, $row->subject_type);
        $this->assertSame((int) $operator->id, (int) $row->subject_id);

        $this->assertNotNull($row->ip_address, 'no address was recorded, which is what incident response reads.');
    }

    /**
     * THE VOLUME CLAIM. A merchant sign-in writes nothing — that is why the recorder is at the
     * controller and scoped to `users.type` rather than on the `Login` event.
     */
    #[Test]
    public function test_a_merchant_sign_in_writes_no_row(): void
    {
        [$merchant] = $this->makeTenant();
        $merchant->forceFill(['password' => bcrypt(self::PASSWORD)])->save();
        Utility::addNewData();

        $this->post(route('login'), [
            'email' => $merchant->email,
            'password' => self::PASSWORD,
        ]);

        $this->assertAuthenticated('web');

        $this->assertSame(
            0,
            (int) DB::table('audit_logs')
                ->whereIn('action', [
                    OperatorSessionAudit::SIGNED_IN,
                    OperatorSessionAudit::SIGN_IN_FAILED,
                ])
                ->count(),
            'a merchant sign-in wrote an operator session row. The recorder is scoped to the '
            . 'operator precisely so that this table does not grow with ordinary traffic.'
        );
    }

    // ────────────────────────────────────────────────────────────────────────────────────────
    //  FAILED SIGN-IN — the most valuable row, and the only one an attacker can cause.
    // ────────────────────────────────────────────────────────────────────────────────────────

    /**
     * A FAILED ATTEMPT AGAINST THE OPERATOR IS RECORDED WITH **NO ACTOR**.
     *
     * Nobody is authenticated, so an actor would be a false statement in a compliance record. The
     * targeted account is the SUBJECT. Asserting `user_id` is NULL is the half that matters: a row
     * naming the operator as the actor would read, to anybody auditing it later, as the operator
     * having done something.
     */
    #[Test]
    public function test_a_failed_sign_in_against_the_operator_is_recorded_with_no_actor(): void
    {
        $operator = $this->signableOperator();

        $response = $this->post(route('login'), [
            'email' => $operator->email,
            'password' => 'not-the-password',
        ]);

        $this->assertGuest('web', 'premise: a wrong password must not sign anybody in.');

        $row = $this->soleRow(OperatorSessionAudit::SIGN_IN_FAILED);

        $this->assertNull(
            $row->user_id,
            'the failed attempt was attributed to the operator as the ACTOR. Nobody was '
            . 'authenticated, so that is a false statement in the audit trail.'
        );
        $this->assertSame(User::class, $row->subject_type);
        $this->assertSame(
            (int) $operator->id,
            (int) $row->subject_id,
            'the row does not name which account was targeted, which is the only thing that makes '
            . 'it useful.'
        );

        // Property 2: nothing a visitor sees changed.
        $this->assertSame(302, $response->getStatusCode());
        $response->assertSessionHasErrors('email');
        $this->assertSame(
            0,
            (int) DB::table('audit_logs')->where('action', OperatorSessionAudit::SIGNED_IN)->count(),
            'a failed attempt also wrote a SIGNED IN row.'
        );
    }

    /**
     * NO CALLER-SUPPLIED TEXT REACHES THE ROW.
     *
     * The attempted address is used only to look a row up. A trail an attacker can write prose
     * into is one that prints their prose on the operator's own screens — the shape of P1-SEC-79
     * and P1-SEC-80 — so this asserts the hostile string appears NOWHERE in the stored row.
     */
    #[Test]
    public function test_no_caller_supplied_text_reaches_the_row(): void
    {
        $operator = $this->signableOperator();

        // A hostile address that still resolves to the operator is impossible by construction --
        // the lookup is an exact match -- so the hostile value is driven as the PASSWORD, which is
        // the other caller-supplied field on this form.
        $needle = '<img src=x onerror=alert(1)>';

        $this->post(route('login'), [
            'email' => $operator->email,
            'password' => $needle,
        ]);

        $row = $this->soleRow(OperatorSessionAudit::SIGN_IN_FAILED);

        $stored = json_encode([
            $row->old_values,
            $row->new_values,
            $row->action,
            $row->subject_type,
        ]);

        $this->assertStringNotContainsString(
            'onerror',
            (string) $stored,
            'caller-supplied text reached the audit row.'
        );
    }

    /**
     * A FAILED ATTEMPT AGAINST A NON-OPERATOR WRITES NOTHING — including against an address that
     * exists nowhere, so this cannot be used to probe which addresses exist.
     */
    #[Test]
    public function test_a_failed_sign_in_against_a_non_operator_writes_no_row(): void
    {
        [$merchant] = $this->makeTenant();
        $merchant->forceFill(['password' => bcrypt(self::PASSWORD)])->save();
        Utility::addNewData();

        foreach ([$merchant->email, 'nobody-' . uniqid() . '@example.test'] as $email) {
            $this->post(route('login'), ['email' => $email, 'password' => 'wrong']);
        }

        $this->assertSame(
            0,
            (int) DB::table('audit_logs')
                ->where('action', OperatorSessionAudit::SIGN_IN_FAILED)
                ->count(),
            'a failed attempt against a non-operator, or against an address that exists nowhere, '
            . 'wrote a row. That makes the table an existence oracle and lets anybody fill it.'
        );
    }

    // ────────────────────────────────────────────────────────────────────────────────────────
    //  SIGN-OUT
    // ────────────────────────────────────────────────────────────────────────────────────────

    #[Test]
    public function test_an_operator_sign_out_is_recorded(): void
    {
        $operator = $this->makePlatformAdmin();

        $response = $this->actingAs($operator)->post(route('logout'));

        $this->assertNotSame(404, $response->getStatusCode(), 'premise: the logout route exists.');
        $this->assertGuest('web');

        $row = $this->soleRow(OperatorSessionAudit::SIGNED_OUT);

        $this->assertSame(
            (int) $operator->id,
            (int) $row->user_id,
            'the sign-out row must name the operator -- it is recorded BEFORE logout() precisely '
            . 'because afterwards there is no principal to name.'
        );
        $this->assertSame((int) $operator->id, (int) $row->subject_id);
    }

    #[Test]
    public function test_a_merchant_sign_out_writes_no_row(): void
    {
        [$merchant] = $this->makeTenant();

        $this->actingAs($merchant)->post(route('logout'));

        $this->assertSame(
            0,
            (int) DB::table('audit_logs')->where('action', OperatorSessionAudit::SIGNED_OUT)->count()
        );
    }

    // ────────────────────────────────────────────────────────────────────────────────────────
    //  THE RECORDER'S OWN PRINCIPAL TEST, driven directly.
    // ────────────────────────────────────────────────────────────────────────────────────────

    /**
     * `instanceof User` FIRST, THEN `isOperator()` — the `GAP-307` shape, asked of this recorder.
     *
     * A `Customer` row carrying the word `super admin` must write nothing, and neither must a
     * bare object with a `type` property. Driven DIRECTLY, because no HTTP path puts either in
     * front of this code and a route-level test would therefore prove nothing.
     */
    #[Test]
    public function test_the_recorder_refuses_every_principal_that_is_not_an_operator_user(): void
    {
        $merchant = $this->makeMerchant();

        $impostors = [
            'null' => null,
            'a string' => 'super admin',
            'an anonymous object carrying the word' => (object) ['id' => 1, 'type' => 'super admin'],
            'a Customer carrying the word' => \App\Models\Customer::create([
                'name' => 'Impostor',
                'email' => 'impostor-' . uniqid() . '@example.test',
                'password' => bcrypt('x'),
                'type' => 'super admin',
                'store_id' => $this->makeStore($merchant)->id,
            ]),
            'a merchant' => $merchant,
        ];

        foreach ($impostors as $label => $principal) {
            OperatorSessionAudit::signedIn($principal);
            OperatorSessionAudit::signedOut($principal);
            OperatorSessionAudit::signInFailed($principal);

            $this->assertSame(
                0,
                (int) DB::table('audit_logs')
                    ->whereIn('action', [
                        OperatorSessionAudit::SIGNED_IN,
                        OperatorSessionAudit::SIGNED_OUT,
                        OperatorSessionAudit::SIGN_IN_FAILED,
                    ])
                    ->count(),
                $label . ' was recorded as a platform operator session.'
            );
        }

        // CONTROL: the recorder can write at all, so the zeros above are a refusal and not a
        // recorder that does nothing for anybody.
        OperatorSessionAudit::signedIn($this->makePlatformAdmin());

        $this->assertSame(
            1,
            (int) DB::table('audit_logs')->where('action', OperatorSessionAudit::SIGNED_IN)->count(),
            'the recorder wrote nothing even for a real operator, so every refusal above is vacuous.'
        );
    }

    // ────────────────────────────────────────────────────────────────────────────────────────
    //  FIXTURES
    // ────────────────────────────────────────────────────────────────────────────────────────

    /**
     * An operator who can actually sign in.
     *
     * `addNewData()` is run first because `store()` reaches it and it seeds the permission list on
     * its FIRST run — so without this the case under test would be the first-ever login on a
     * deployment rather than the ordinary one (`LoginDoesNotFlushPaymentCacheTest` records the
     * same premise for the same reason).
     */
    private function signableOperator(): User
    {
        $operator = $this->makePlatformAdmin();
        $operator->forceFill(['password' => bcrypt(self::PASSWORD)])->save();

        Utility::addNewData();

        return $operator;
    }

    /** Exactly one row of that action, or a failure naming what was found instead. */
    private function soleRow(string $action): AuditLog
    {
        $rows = AuditLog::where('action', $action)->get();

        $this->assertCount(
            1,
            $rows,
            'expected exactly one `' . $action . '` audit row, found ' . $rows->count()
            . '. All actions present: '
            . DB::table('audit_logs')->pluck('action')->implode(', ')
        );

        return $rows->first();
    }
}
