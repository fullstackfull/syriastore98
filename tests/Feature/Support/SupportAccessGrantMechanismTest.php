<?php

namespace Tests\Feature\Support;

use App\Models\AuditLog;
use App\Models\Store;
use App\Models\SupportAccessApproval;
use App\Models\SupportAccessGrant;
use App\Models\User;
use App\Support\SupportAccess\AccessKind;
use App\Support\SupportAccess\SupportAccessAudit;
use App\Support\SupportAccess\SupportAccessGate;
use App\Support\SupportAccess\SupportAccessGrants;
use App\Support\SupportAccess\SupportAccessScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\Feature\Security\SecurityTestCase;

/**
 * `SUPPORT-004` AND `SUPPORT-005` — EVERY CLAUSE, ASSERTED IN BOTH DIRECTIONS.
 * =================================================================================================
 *
 * The owner approved, verbatim from `docs/development/master-plan/DECISION-ID-REGISTER.md`:
 *
 *   `SUPPORT-004`  وصول دعم مؤقت ومحدد بموافقة التاجر وسجل تدقيق.
 *   `SUPPORT-005`  وصول طوارئ منفصل وبصلاحيات وموافقات أعلى.
 *
 * Measured on this branch before any of this was written: of `SUPPORT-004`'s four clauses, ONE was
 * implemented (the audit log, via `App\Support\Audit\RecordImpersonation`) and three were absent —
 * `grep -rl "support_access\|SupportAccess\|EmergencyAccess\|break.glass" app/ routes/ database/`
 * returned nothing at all. `SUPPORT-005` was absent entirely.
 *
 * EVERY REFUSAL HERE IS KEYED ON ITS REASON, not on a boolean. CLAUDE.md records why: "a gateless
 * handler's own 'not found' branch is also a 302, so a 302 from a missing row and a 302 from a gate
 * are the same status and opposite facts." The same hazard applies to a predicate — `false` from
 * "the scope does not cover it" and `false` from "the grant query found nothing because the model
 * is accidentally scoped" are the same boolean. So each refusal case here builds a grant that is
 * live in every respect EXCEPT the clause under test, and carries a POSITIVE CONTROL asserting that
 * the same grant with that one clause repaired is admitted. A test that only ever sees `false`
 * cannot tell a working gate from a broken query.
 */
class SupportAccessGrantMechanismTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $operator;

    private User $secondOperator;

    private User $thirdOperator;

    private User $merchant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = $this->makePlatformAdmin();
        $this->secondOperator = $this->makePlatformAdmin();
        $this->thirdOperator = $this->makePlatformAdmin();
        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        (new User)->flushCache();

        config()->set('support_access.enforce', true);
        config()->set('support_access.max_duration_minutes', 1440);
        config()->set('support_access.emergency.max_duration_minutes', 240);
        config()->set('support_access.emergency.required_approvals', 2);
        config()->set('support_access.emergency.approver_permission', null);

        $this->requireTable('support_access_grants');
        $this->requireTable('support_access_approvals');
    }

    /**
     * A consented, unexpired, full-scope support grant. The baseline every refusal case mutates one
     * clause of.
     */
    private function liveSupportGrant(string $scope = SupportAccessScope::FULL): SupportAccessGrant
    {
        $grant = SupportAccessGrants::request(
            $this->operator,
            (int) $this->store->id,
            AccessKind::SUPPORT,
            $scope,
            'The merchant cannot see their own orders.',
            60
        );

        return SupportAccessGrants::consent($grant, $this->merchant);
    }

    private function emergencyGrant(string $scope = SupportAccessScope::FULL): SupportAccessGrant
    {
        return SupportAccessGrants::request(
            $this->operator,
            (int) $this->store->id,
            AccessKind::EMERGENCY,
            $scope,
            'Checkout is down for this store and the merchant is unreachable.',
            60
        );
    }

    private function auditActions(): array
    {
        return AuditLog::query()->withoutGlobalScopes()->orderBy('id')->pluck('action')->all();
    }

    // =============================================================================================
    //  THE PREMISE — a requested grant authorises NOTHING
    // =============================================================================================

    /**
     * REQUESTING IS NOT GRANTING, and this is the premise every other case rests on. If a bare
     * request were already active, every "the clause refuses" assertion below would be measuring
     * the clause against an already-admitted grant and could pass for the wrong reason.
     */
    public function test_a_requested_support_grant_authorises_nothing_until_the_merchant_consents(): void
    {
        $grant = SupportAccessGrants::request(
            $this->operator,
            (int) $this->store->id,
            AccessKind::SUPPORT,
            SupportAccessScope::FULL,
            'Investigating a failed payout.',
            60
        );

        $this->assertFalse(
            SupportAccessGate::allows($this->operator, (int) $this->store->id, SupportAccessScope::FULL),
            'a support grant with no merchant consent was treated as live -- SUPPORT-004\'s consent '
            . 'clause is not enforced'
        );

        // POSITIVE CONTROL: the same grant, consented, is admitted. Without this the assertion
        // above would hold just as well if the gate refused everything.
        SupportAccessGrants::consent($grant, $this->merchant);

        $this->assertTrue(
            SupportAccessGate::allows($this->operator, (int) $this->store->id, SupportAccessScope::FULL),
            'CONTROL: a consented, unexpired, full grant was refused -- the gate refuses everything '
            . 'and the refusal assertions above prove nothing'
        );
    }

    // =============================================================================================
    //  "مؤقت" — TEMPORARY
    // =============================================================================================

    public function test_an_expired_grant_is_refused(): void
    {
        $grant = $this->liveSupportGrant();

        $this->assertTrue(
            SupportAccessGate::allows($this->operator, (int) $this->store->id, SupportAccessScope::FULL),
            'CONTROL: the grant was not live before being expired'
        );

        $grant->forceFill(['expires_at' => Carbon::now()->subMinute()])->save();

        $this->assertFalse(
            SupportAccessGate::allows($this->operator, (int) $this->store->id, SupportAccessScope::FULL),
            'an expired grant still authorised access -- SUPPORT-004\'s temporary clause is not enforced'
        );
    }

    /**
     * A NULL EXPIRY MEANS EXPIRED, NOT "NEVER EXPIRES".
     *
     * THE DATABASE WILL NOT PRODUCE THIS STATE, AND THAT WAS MEASURED HERE RATHER THAN ASSUMED.
     * The first version of this case wrote `expires_at = null` with `DB::table()->update()` and the
     * test ERRORED: `SQLSTATE[23000] NOT NULL constraint failed: support_access_grants.expires_at`.
     * So the column really is NOT NULL on the suite's SQLite as well as on MySQL, and a permanent
     * grant cannot be written through the table at all — which is a stronger result than the
     * assertion was reaching for, and is recorded here because it is the schema half of the
     * temporary clause.
     *
     * What remains reachable is a grant OBJECT with no expiry — a model built in memory, or one
     * hydrated from a future query that does not select the column. `hasExpired()` is therefore
     * asserted directly, on the unit that can actually be in that state. The DIRECTION is the whole
     * point: read the other way, an absent expiry becomes "no opinion" and then "forever".
     */
    public function test_a_grant_object_with_no_expiry_reads_as_expired_rather_than_permanent(): void
    {
        $withoutExpiry = new SupportAccessGrant([
            'kind' => AccessKind::SUPPORT,
            'scope' => SupportAccessScope::FULL,
        ]);

        $this->assertTrue(
            $withoutExpiry->hasExpired(),
            'a grant with no expiry at all read as unexpired -- an absent bound must mean EXPIRED, '
            . 'or one missing value becomes permanent support access'
        );

        $this->assertFalse(
            SupportAccessGate::grantIsActive($withoutExpiry, SupportAccessScope::FULL),
            'the gate treated an expiry-less grant as live'
        );

        // CONTROL: the same object with a future expiry and consent IS live, so the assertions
        // above are measuring the expiry and not some other missing field.
        $withoutExpiry->expires_at = Carbon::now()->addHour();
        $withoutExpiry->consented_at = Carbon::now();

        $this->assertFalse($withoutExpiry->hasExpired());
        $this->assertTrue(
            SupportAccessGate::grantIsActive($withoutExpiry, SupportAccessScope::FULL),
            'CONTROL: a consented, unexpired, full grant object was still refused'
        );
    }

    /**
     * THE SCHEMA ITSELF REFUSES A PERMANENT GRANT. Separated from the case above because it is a
     * claim about the TABLE, and because the first draft discovered it by accident: an assertion
     * that meant to set up a null expiry got a NOT NULL violation instead. Asserted deliberately
     * now, so that a future migration relaxing the column fails here and says why.
     */
    public function test_the_table_refuses_a_grant_with_no_expiry(): void
    {
        $grant = $this->liveSupportGrant();

        try {
            \DB::table('support_access_grants')->where('id', $grant->id)->update(['expires_at' => null]);
            $this->fail(
                'support_access_grants.expires_at accepted NULL -- the temporary clause of '
                . 'SUPPORT-004 is no longer enforced by the schema, and a permanent support grant '
                . 'can now be written with one UPDATE'
            );
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('NOT NULL', $e->getMessage());
        }
    }

    public function test_a_grant_longer_than_the_configured_ceiling_is_refused_at_creation(): void
    {
        config()->set('support_access.max_duration_minutes', 60);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must run between 1 and 60 minutes');

        SupportAccessGrants::request(
            $this->operator,
            (int) $this->store->id,
            AccessKind::SUPPORT,
            SupportAccessScope::FULL,
            'A week would be convenient.',
            61
        );
    }

    /**
     * AN UNUSABLE CEILING PRODUCES NO GRANTS, NOT UNBOUNDED ONES.
     *
     * `(int) null` is 0. If a deleted config key were read as "no limit", removing one line from a
     * config file would turn every grant permanent — the exact inversion the NOT NULL column and
     * `AccessKind::maxDurationMinutes()`'s `max(0, ...)` floor exist to prevent.
     */
    public function test_a_missing_duration_ceiling_refuses_rather_than_unbounds(): void
    {
        config()->set('support_access.max_duration_minutes', null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No usable maximum duration');

        SupportAccessGrants::request(
            $this->operator,
            (int) $this->store->id,
            AccessKind::SUPPORT,
            SupportAccessScope::FULL,
            'Config key deleted.',
            30
        );
    }

    // =============================================================================================
    //  "بموافقة التاجر" — MERCHANT CONSENT
    // =============================================================================================

    /**
     * THE OPERATOR CANNOT CONSENT ON THE MERCHANT'S BEHALF.
     *
     * This is the clause that was entirely absent before: the operator self-authorised. If this
     * assertion fails, the mechanism is theatre — a consent column the beneficiary can fill in.
     */
    public function test_the_operator_cannot_consent_on_the_merchants_behalf(): void
    {
        $grant = SupportAccessGrants::request(
            $this->operator,
            (int) $this->store->id,
            AccessKind::SUPPORT,
            SupportAccessScope::FULL,
            'I will consent for them.',
            60
        );

        try {
            SupportAccessGrants::consent($grant, $this->operator);
            $this->fail('the platform operator consented to their own support access');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Only the merchant', $e->getMessage());
        }

        $this->assertFalse(
            SupportAccessGate::allows($this->operator, (int) $this->store->id, SupportAccessScope::FULL),
            'the grant became live although consent was refused'
        );

        // POSITIVE CONTROL: the real merchant can.
        SupportAccessGrants::consent($grant, $this->merchant);
        $this->assertTrue(
            SupportAccessGate::allows($this->operator, (int) $this->store->id, SupportAccessScope::FULL),
            'CONTROL: the merchant\'s own consent did not work either'
        );
    }

    /** Another merchant's consent is not this merchant's consent. */
    public function test_a_different_merchant_cannot_consent_to_this_stores_grant(): void
    {
        $stranger = $this->makeMerchant();
        $this->makeStore($stranger, ['default_language' => 'en']);
        (new User)->flushCache();

        $grant = SupportAccessGrants::request(
            $this->operator,
            (int) $this->store->id,
            AccessKind::SUPPORT,
            SupportAccessScope::FULL,
            'Cross-tenant consent attempt.',
            60
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only the merchant');

        SupportAccessGrants::consent($grant, $stranger);
    }

    public function test_a_revoked_grant_is_refused_and_the_merchant_may_revoke(): void
    {
        $grant = $this->liveSupportGrant();

        $this->assertTrue(
            SupportAccessGate::allows($this->operator, (int) $this->store->id, SupportAccessScope::FULL),
            'CONTROL: the grant was not live before revocation'
        );

        SupportAccessGrants::revoke($grant, $this->merchant, 'Changed my mind.');

        $this->assertFalse(
            SupportAccessGate::allows($this->operator, (int) $this->store->id, SupportAccessScope::FULL),
            'a revoked grant still authorised access -- consent that cannot be withdrawn is not consent'
        );
    }

    // =============================================================================================
    //  "محدد" — SCOPED
    // =============================================================================================

    /**
     * A NARROW GRANT DOES NOT AUTHORISE IMPERSONATION, and this is what stops `scope` being
     * decorative. Impersonation signs the operator in AS the merchant, so there is no filter in
     * that path that could confine the session to orders — a grant for `orders` that admitted it
     * would deliver everything with "orders" written on the consent prompt.
     */
    public function test_a_narrow_grant_does_not_authorise_impersonation(): void
    {
        foreach ([SupportAccessScope::ORDERS, SupportAccessScope::CATALOGUE,
                  SupportAccessScope::CUSTOMERS, SupportAccessScope::SETTINGS] as $scope) {
            $grant = $this->liveSupportGrant($scope);

            $this->assertTrue(
                SupportAccessGate::allows($this->operator, (int) $this->store->id, $scope),
                "CONTROL: the live {$scope} grant did not even authorise its own scope"
            );

            $this->assertFalse(
                SupportAccessGate::allowsImpersonation($this->operator, (int) $this->store->id),
                "a {$scope} grant authorised impersonation, which is full access"
            );

            SupportAccessGrants::revoke($grant, $this->merchant, 'next case');
        }

        // POSITIVE CONTROL: `full` does.
        $this->liveSupportGrant(SupportAccessScope::FULL);
        $this->assertTrue(
            SupportAccessGate::allowsImpersonation($this->operator, (int) $this->store->id),
            'CONTROL: a full grant did not authorise impersonation either, so the four refusals '
            . 'above prove nothing'
        );
    }

    /** A grant for one scope does not satisfy a request for another. */
    public function test_a_grant_does_not_cover_a_different_scope(): void
    {
        $this->liveSupportGrant(SupportAccessScope::ORDERS);

        $this->assertTrue(
            SupportAccessGate::allows($this->operator, (int) $this->store->id, SupportAccessScope::ORDERS),
            'CONTROL: the orders grant does not cover orders'
        );
        $this->assertFalse(
            SupportAccessGate::allows($this->operator, (int) $this->store->id, SupportAccessScope::SETTINGS),
            'an orders grant covered settings -- there is deliberately no scope hierarchy'
        );
    }

    /**
     * THE STORE PREDICATE IS REAL. A grant for store A must not authorise store B — and because
     * `SupportAccessGrant` is deliberately NOT in `scoped_models`, nothing but the explicit
     * `where('store_id', ...)` in `activeGrant()` prevents it.
     */
    public function test_a_grant_for_one_store_does_not_authorise_another(): void
    {
        $otherStore = $this->makeStore($this->merchant, ['default_language' => 'en']);
        (new User)->flushCache();

        $this->liveSupportGrant(SupportAccessScope::FULL);

        $this->assertTrue(
            SupportAccessGate::allows($this->operator, (int) $this->store->id, SupportAccessScope::FULL),
            'CONTROL: the grant does not cover its own store'
        );
        $this->assertFalse(
            SupportAccessGate::allows($this->operator, (int) $otherStore->id, SupportAccessScope::FULL),
            'a grant for one store authorised a second store the merchant owns'
        );
    }

    /** A grant naming another operator is not this operator's grant. */
    public function test_a_grant_for_one_operator_does_not_authorise_another(): void
    {
        $this->liveSupportGrant(SupportAccessScope::FULL);

        $this->assertFalse(
            SupportAccessGate::allows($this->secondOperator, (int) $this->store->id, SupportAccessScope::FULL),
            'a grant issued to one support agent authorised a different one -- a grant is personal '
            . 'so the audit trail names a person'
        );
    }

    /**
     * A REVOKED GRANT MUST NOT SHADOW A LIVE ONE.
     *
     * `activeGrant()` walks candidates newest-first and returns the first ACTIVE one. A naive
     * implementation taking only the newest row would be refused here, and a naive one taking only
     * the oldest would be admitted in the previous case. Both directions matter, so this asserts
     * the live-behind-dead ordering explicitly.
     */
    public function test_a_live_grant_is_found_behind_a_newer_revoked_one(): void
    {
        $live = $this->liveSupportGrant(SupportAccessScope::FULL);

        $newer = SupportAccessGrants::request(
            $this->operator,
            (int) $this->store->id,
            AccessKind::SUPPORT,
            SupportAccessScope::FULL,
            'A second request, later revoked.',
            60
        );
        SupportAccessGrants::consent($newer, $this->merchant);
        SupportAccessGrants::revoke($newer, $this->merchant, 'not this one');

        $found = SupportAccessGate::activeGrant(
            $this->operator,
            (int) $this->store->id,
            SupportAccessScope::FULL
        );

        $this->assertNotNull($found, 'the live grant was hidden by a newer revoked one');
        $this->assertSame((int) $live->id, (int) $found->id);
    }

    // =============================================================================================
    //  `SUPPORT-005` — THE SEPARATE EMERGENCY PATH
    // =============================================================================================

    /**
     * THE TWO PATHS ARE SATISFIED BY DISJOINT PREDICATES — "منفصل".
     *
     * Both directions, because "separate" is a claim about both: an emergency grant must not be
     * satisfiable by consent, and a support grant must not be satisfiable by approvals. If either
     * held, the break-glass route would be the ordinary route with a flag flipped, which is the
     * shape `SUPPORT-005` forbids.
     */
    public function test_merchant_consent_cannot_authorise_an_emergency_grant(): void
    {
        $grant = $this->emergencyGrant();

        try {
            SupportAccessGrants::consent($grant, $this->merchant);
            $this->fail('an emergency grant was authorised by merchant consent, collapsing the two paths');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('approvals, not by merchant consent', $e->getMessage());
        }

        $this->assertFalse(
            SupportAccessGate::allows($this->operator, (int) $this->store->id, SupportAccessScope::FULL)
        );
    }

    public function test_approvals_cannot_authorise_an_ordinary_support_grant(): void
    {
        $grant = SupportAccessGrants::request(
            $this->operator,
            (int) $this->store->id,
            AccessKind::SUPPORT,
            SupportAccessScope::FULL,
            'Trying to approve my way past the merchant.',
            60
        );

        try {
            SupportAccessGrants::decide($grant, $this->secondOperator, SupportAccessApproval::APPROVED);
            $this->fail('a support grant accepted an approval -- the two paths are not separate');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Only emergency access is approved', $e->getMessage());
        }

        $this->assertFalse(
            SupportAccessGate::allows($this->operator, (int) $this->store->id, SupportAccessScope::FULL),
            'a support grant became live without the merchant'
        );
    }

    /**
     * THE GATE'S OWN DISJOINTNESS, WITH THE CROSSED STATE PLANTED DIRECTLY.
     * =============================================================================================
     *
     * ADDED AFTER A SURVIVING MUTATION, and it is the most useful finding in this file.
     *
     * The two cases above (`test_merchant_consent_cannot_authorise_an_emergency_grant` and
     * `test_approvals_cannot_authorise_an_ordinary_support_grant`) measure the WRITER: `consent()`
     * and `decide()` throw, so the crossed state is never created and the GATE is never asked about
     * it. Collapsing the gate's own predicate to
     *
     *     return $grant->isConsented() || self::emergencyIsApproved($grant);
     *
     * therefore SURVIVED all 52 tests — the paths were separate only because one layer refused to
     * create the state, and nothing asserted the other layer at all. CLAUDE.md's "a test that
     * passes for the wrong reason", exactly.
     *
     * So both crossed states are now planted past the writer — a raw write for the consent column,
     * a direct `create()` for the approval rows — and the gate is asked. This is the only way to
     * reach the branch, and it is legitimate precisely because the writer is not the control being
     * measured here.
     */
    public function test_the_gate_refuses_an_emergency_grant_that_carries_only_consent(): void
    {
        $grant = $this->emergencyGrant();

        // Planted past `consent()`, which refuses this on an emergency grant.
        \DB::table('support_access_grants')->where('id', $grant->id)->update([
            'consented_at' => Carbon::now()->toDateTimeString(),
            'consented_by' => (int) $this->merchant->id,
        ]);

        $this->assertNotNull(
            SupportAccessGrant::query()->find($grant->id)->consented_at,
            'PREMISE: the crossed state was not planted, so this case asserts nothing'
        );

        $this->assertFalse(
            SupportAccessGate::allows($this->operator, (int) $this->store->id, SupportAccessScope::FULL),
            'an EMERGENCY grant was authorised by merchant consent at the gate -- the break-glass '
            . 'path is the ordinary path with a flag flipped, which SUPPORT-005\'s "منفصل" forbids'
        );

        // CONTROL: the same grant, approved properly, IS live -- so the refusal above is the
        // consent being ignored and not the grant being broken by the raw write.
        SupportAccessGrants::decide($grant, $this->secondOperator, SupportAccessApproval::APPROVED);
        SupportAccessGrants::decide($grant, $this->thirdOperator, SupportAccessApproval::APPROVED);
        $this->assertTrue(
            SupportAccessGate::allows($this->operator, (int) $this->store->id, SupportAccessScope::FULL),
            'CONTROL: two approvals did not make the planted grant live either'
        );
    }

    /** The other direction: approvals on a SUPPORT grant must not substitute for the merchant. */
    public function test_the_gate_refuses_a_support_grant_that_carries_only_approvals(): void
    {
        $grant = SupportAccessGrants::request(
            $this->operator,
            (int) $this->store->id,
            AccessKind::SUPPORT,
            SupportAccessScope::FULL,
            'Approving my way past the merchant.',
            60
        );

        // Planted past `decide()`, which refuses an approval on a support grant.
        foreach ([$this->secondOperator, $this->thirdOperator] as $approver) {
            SupportAccessApproval::create([
                'grant_id' => (int) $grant->id,
                'approver_id' => (int) $approver->id,
                'decision' => SupportAccessApproval::APPROVED,
                'decided_at' => Carbon::now(),
            ]);
        }

        $this->assertSame(
            2,
            SupportAccessApproval::query()->where('grant_id', $grant->id)->count(),
            'PREMISE: the approvals were not planted, so this case asserts nothing'
        );

        $this->assertFalse(
            SupportAccessGate::allows($this->operator, (int) $this->store->id, SupportAccessScope::FULL),
            'two operator approvals authorised an ORDINARY support grant at the gate -- support '
            . 'staff can approve their way past the merchant, and SUPPORT-004\'s consent clause is '
            . 'bypassable by using the emergency path\'s evidence'
        );

        // CONTROL: the merchant's consent does make it live.
        SupportAccessGrants::consent($grant, $this->merchant);
        $this->assertTrue(
            SupportAccessGate::allows($this->operator, (int) $this->store->id, SupportAccessScope::FULL),
            'CONTROL: the merchant\'s consent did not work either'
        );
    }

    /** N approvals, and N-1 is not N. */
    public function test_an_emergency_grant_needs_the_configured_number_of_approvals(): void
    {
        $grant = $this->emergencyGrant();

        SupportAccessGrants::decide($grant, $this->secondOperator, SupportAccessApproval::APPROVED);

        $this->assertFalse(
            SupportAccessGate::allows($this->operator, (int) $this->store->id, SupportAccessScope::FULL),
            'one approval satisfied a two-approval rule'
        );

        SupportAccessGrants::decide($grant, $this->thirdOperator, SupportAccessApproval::APPROVED);

        $this->assertTrue(
            SupportAccessGate::allows($this->operator, (int) $this->store->id, SupportAccessScope::FULL),
            'CONTROL: two approvals did not satisfy a two-approval rule'
        );
    }

    /**
     * THE GRANTEE'S OWN APPROVAL IS NOT AN APPROVAL — the property that makes "موافقات أعلى" mean
     * more than one person. Two layers are asserted: the writer refuses it, AND the gate ignores
     * such a row if one is planted directly in the table.
     */
    public function test_an_operator_cannot_approve_their_own_emergency_access(): void
    {
        $grant = $this->emergencyGrant();

        try {
            SupportAccessGrants::decide($grant, $this->operator, SupportAccessApproval::APPROVED);
            $this->fail('an operator approved their own emergency access');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('may not approve their own', $e->getMessage());
        }

        // AND the gate ignores a planted self-approval, so the writer is not the only layer.
        SupportAccessApproval::create([
            'grant_id' => (int) $grant->id,
            'approver_id' => (int) $this->operator->id,
            'decision' => SupportAccessApproval::APPROVED,
            'decided_at' => Carbon::now(),
        ]);
        SupportAccessGrants::decide($grant, $this->secondOperator, SupportAccessApproval::APPROVED);

        $this->assertFalse(
            SupportAccessGate::allows($this->operator, (int) $this->store->id, SupportAccessScope::FULL),
            'a planted self-approval counted towards the two-approver rule, so one person can '
            . 'authorise their own emergency access'
        );

        // POSITIVE CONTROL: a genuine second approver completes it.
        SupportAccessGrants::decide($grant, $this->thirdOperator, SupportAccessApproval::APPROVED);
        $this->assertTrue(
            SupportAccessGate::allows($this->operator, (int) $this->store->id, SupportAccessScope::FULL),
            'CONTROL: two genuine approvals were still refused'
        );
    }

    /** One approver, one decision. Without this a single person satisfies any N-approver rule. */
    public function test_one_approver_cannot_approve_twice(): void
    {
        $grant = $this->emergencyGrant();

        SupportAccessGrants::decide($grant, $this->secondOperator, SupportAccessApproval::APPROVED);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already decided');

        SupportAccessGrants::decide($grant, $this->secondOperator, SupportAccessApproval::APPROVED);
    }

    /** A single decline is fatal however many approvals sit beside it. */
    public function test_one_decline_defeats_any_number_of_approvals(): void
    {
        $grant = $this->emergencyGrant();

        SupportAccessGrants::decide($grant, $this->secondOperator, SupportAccessApproval::APPROVED);
        SupportAccessGrants::decide($grant, $this->thirdOperator, SupportAccessApproval::APPROVED);

        $this->assertTrue(
            SupportAccessGate::allows($this->operator, (int) $this->store->id, SupportAccessScope::FULL),
            'CONTROL: the grant was not live before the decline'
        );

        $fourth = $this->makePlatformAdmin();
        (new User)->flushCache();
        SupportAccessGrants::decide($grant, $fourth, SupportAccessApproval::DECLINED, 'No.');

        $this->assertFalse(
            SupportAccessGate::allows($this->operator, (int) $this->store->id, SupportAccessScope::FULL),
            'a recorded decline was outvoted -- there is deliberately no majority rule'
        );
    }

    /** A non-operator may not approve emergency access to a merchant's data. */
    public function test_a_merchant_cannot_approve_emergency_access(): void
    {
        $grant = $this->emergencyGrant();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('may not approve');

        SupportAccessGrants::decide($grant, $this->merchant, SupportAccessApproval::APPROVED);
    }

    /**
     * A ZERO APPROVAL REQUIREMENT REFUSES RATHER THAN SELF-APPROVES.
     *
     * `(int) null` is 0, so a deleted config key must not make every emergency grant live with no
     * approvals at all — an unset value turning into unlimited access.
     */
    public function test_a_zero_approval_requirement_refuses(): void
    {
        config()->set('support_access.emergency.required_approvals', 0);

        $grant = $this->emergencyGrant();

        $this->assertFalse(
            SupportAccessGate::allows($this->operator, (int) $this->store->id, SupportAccessScope::FULL),
            'a zero-approval configuration made an unapproved emergency grant live'
        );

        // CONTROL: restoring the requirement and approving properly works.
        config()->set('support_access.emergency.required_approvals', 2);
        SupportAccessGrants::decide($grant, $this->secondOperator, SupportAccessApproval::APPROVED);
        SupportAccessGrants::decide($grant, $this->thirdOperator, SupportAccessApproval::APPROVED);
        $this->assertTrue(
            SupportAccessGate::allows($this->operator, (int) $this->store->id, SupportAccessScope::FULL)
        );
    }

    /**
     * EMERGENCY ACCESS HAS ITS OWN, SHORTER CEILING, and it is read from its own key. If both kinds
     * read one key the `SUPPORT-005` requirement that emergency be more tightly bounded could not
     * be expressed.
     */
    public function test_emergency_access_is_bounded_by_its_own_ceiling(): void
    {
        config()->set('support_access.max_duration_minutes', 1440);
        config()->set('support_access.emergency.max_duration_minutes', 30);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must run between 1 and 30 minutes');

        SupportAccessGrants::request(
            $this->operator,
            (int) $this->store->id,
            AccessKind::EMERGENCY,
            SupportAccessScope::FULL,
            'Longer than emergency allows.',
            120
        );
    }

    // =============================================================================================
    //  "سجل تدقيق" — THE AUDIT TRAIL
    // =============================================================================================

    /**
     * EVERY LIFECYCLE EVENT LANDS IN `audit_logs`, WITH THE GRANT AS THE SUBJECT.
     *
     * The subject matters as much as the action: one grant's whole life must read as rows sharing
     * one subject id, or a merchant asking "who saw my data" cannot join them.
     */
    public function test_the_grant_lifecycle_is_recorded_against_the_grant(): void
    {
        $grant = $this->liveSupportGrant();
        SupportAccessGrants::recordUse($grant, $this->operator, ['door' => 'test']);
        SupportAccessGrants::revoke($grant, $this->merchant, 'done');

        $rows = AuditLog::query()->withoutGlobalScopes()
            ->where('subject_type', SupportAccessGrant::class)
            ->where('subject_id', $grant->id)
            ->orderBy('id')->get();

        $this->assertSame(
            [
                SupportAccessAudit::REQUESTED,
                SupportAccessAudit::CONSENTED,
                SupportAccessAudit::USED,
                SupportAccessAudit::REVOKED,
            ],
            $rows->pluck('action')->all(),
            'the grant lifecycle is not fully recorded in audit_logs'
        );

        $this->assertSame(
            (int) $this->store->id,
            (int) $rows->first()->store_id,
            'the audit row is not attributed to the store the grant concerns -- AuditAttribution '
            . 'should read the subject grant\'s own store_id'
        );
    }

    /** A use advances the counters, so "consented and never used" is distinguishable. */
    public function test_a_use_is_counted_on_the_grant(): void
    {
        $grant = $this->liveSupportGrant();

        $this->assertSame(0, (int) $grant->uses);
        $this->assertNull($grant->first_used_at);

        SupportAccessGrants::recordUse($grant, $this->operator);
        SupportAccessGrants::recordUse($grant, $this->operator);

        $fresh = SupportAccessGrant::query()->find($grant->id);

        $this->assertSame(2, (int) $fresh->uses, 'uses were not counted');
        $this->assertNotNull($fresh->first_used_at, 'first_used_at was never stamped');
    }

    // =============================================================================================
    //  CREATION GUARDS
    // =============================================================================================

    /** A grant names a platform operator. A merchant-held grant would be a cross-tenant licence. */
    public function test_a_grant_cannot_be_issued_to_a_merchant(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('names a platform operator');

        SupportAccessGrants::request(
            $this->operator,
            (int) $this->store->id,
            AccessKind::SUPPORT,
            SupportAccessScope::FULL,
            'Give a merchant support powers.',
            60,
            $this->merchant
        );
    }

    /**
     * THE MERCHANT IS DERIVED FROM THE STORE, NEVER FROM A PARAMETER.
     *
     * CLAUDE.md: "a tenant predicate built from caller-supplied input is not a tenant check." There
     * is deliberately no `merchant_id` parameter on `request()`, so this asserts the derivation
     * landed on the store's real owner — the value `consent()` then checks against.
     */
    public function test_the_merchant_on_the_grant_comes_from_the_store(): void
    {
        $grant = SupportAccessGrants::request(
            $this->operator,
            (int) $this->store->id,
            AccessKind::SUPPORT,
            SupportAccessScope::FULL,
            'Derivation check.',
            60
        );

        $this->assertSame(
            (int) $this->merchant->id,
            (int) $grant->merchant_id,
            'the grant does not name the store\'s own owner as the consenting merchant'
        );
    }

    /**
     * A STRANGER CANNOT WRITE A ROW INTO ANOTHER STORE'S GRANT LIST.
     *
     * Found by applying the `tenant-authz-reviewer` checklist's shape 2 to this family's own code.
     * `$storeId` is caller-supplied, which is correct for a request — the grant authorises nothing
     * until the store's merchant consents, and `merchant_id` comes from `$store->created_by`. But a
     * ROW is still written into the list a merchant reads to see who has asked for their data, and
     * an unconstrained requester makes that a phishing surface aimed at the merchant through their
     * own panel ("support needs access, please approve").
     *
     * LATENT: no route calls `request()` yet, so this was not reachable. Closed anyway, because
     * "the controller will check" is how shape 2 produced four separate defects in this repository.
     */
    public function test_a_stranger_cannot_request_access_to_someone_elses_store(): void
    {
        $stranger = $this->makeMerchant();
        $this->makeStore($stranger, ['default_language' => 'en']);
        (new User)->flushCache();

        try {
            SupportAccessGrants::request(
                $stranger,
                (int) $this->store->id,
                AccessKind::SUPPORT,
                SupportAccessScope::FULL,
                'Please approve this, it is definitely support.',
                60,
                $this->operator
            );
            $this->fail('a merchant wrote a support access request into another store\'s grant list');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('own merchant may request', $e->getMessage());
        }

        $this->assertSame(
            0,
            SupportAccessGrant::query()->where('store_id', $this->store->id)->count(),
            'the row was written anyway'
        );
    }

    /**
     * THE MERCHANT'S OWN REQUEST IS ALLOWED — the control for the case above.
     *
     * A merchant asking for help with their own store is a real product flow, and a merchant who
     * both requests and consents gives away nothing but their own data. Without this control, the
     * refusal above would hold just as well if `request()` refused everyone but the operator, and
     * that would be a quiet reduction of scope.
     */
    public function test_a_merchant_may_request_support_access_to_their_own_store(): void
    {
        $grant = SupportAccessGrants::request(
            $this->merchant,
            (int) $this->store->id,
            AccessKind::SUPPORT,
            SupportAccessScope::ORDERS,
            'I cannot see my own orders, please look.',
            60,
            $this->operator
        );

        $this->assertSame((int) $this->merchant->id, (int) $grant->requested_by);
        $this->assertSame((int) $this->operator->id, (int) $grant->grantee_id);

        // And it still authorises nothing until consent, merchant-initiated or not.
        $this->assertFalse(
            SupportAccessGate::allows($this->operator, (int) $this->store->id, SupportAccessScope::ORDERS),
            'a merchant-initiated request was live without the consent step'
        );

        SupportAccessGrants::consent($grant, $this->merchant);
        $this->assertTrue(
            SupportAccessGate::allows($this->operator, (int) $this->store->id, SupportAccessScope::ORDERS)
        );
    }

    public function test_a_reasonless_request_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('stated reason');

        SupportAccessGrants::request(
            $this->operator,
            (int) $this->store->id,
            AccessKind::SUPPORT,
            SupportAccessScope::FULL,
            '   ',
            60
        );
    }

    public function test_an_unknown_scope_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown support access scope');

        SupportAccessGrants::request(
            $this->operator,
            (int) $this->store->id,
            AccessKind::SUPPORT,
            'everything_forever',
            'Typo scope.',
            60
        );
    }

    /**
     * A GRANT NAMING NO STORE AUTHORISES NOTHING, and `store_id <= 0` is refused before any query.
     * `getCurrentStore()` can answer null, and `(int) null` is 0.
     *
     * A GRANT ROW WITH `store_id = 0` IS PLANTED, AND WITHOUT IT THIS CASE PASSED FOR THE WRONG
     * REASON. Measured: deleting the `$storeId === null || $storeId <= 0` guard from
     * `activeGrant()` SURVIVED all 52 tests, because with no such row in the table
     * `where('store_id', 0)` simply matches nothing — so the assertion held whether the guard
     * existed or not. The guard only does work when a row with that id exists, so one is created.
     * `store_id` is a plain indexed integer with no foreign key (see the migration), so 0 is
     * storable, which is exactly why the guard is not redundant.
     */
    public function test_an_unidentified_store_is_refused_by_the_gate(): void
    {
        $this->liveSupportGrant();

        $planted = SupportAccessGrant::create([
            'store_id' => 0,
            'merchant_id' => (int) $this->merchant->id,
            'grantee_id' => (int) $this->operator->id,
            'kind' => AccessKind::SUPPORT,
            'scope' => SupportAccessScope::FULL,
            'reason' => 'A grant naming no store.',
            'requested_by' => (int) $this->operator->id,
            'requested_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addHour(),
        ]);
        $planted->forceFill(['consented_at' => Carbon::now()])->save();

        $this->assertSame(
            0,
            (int) SupportAccessGrant::query()->find($planted->id)->store_id,
            'PREMISE: a store_id of 0 was not storable, so this case cannot distinguish the guard '
            . 'from an empty result set'
        );

        $this->assertFalse(
            SupportAccessGate::allows($this->operator, 0, SupportAccessScope::FULL),
            'a grant naming store 0 authorised access -- getCurrentStore() can answer null and '
            . '(int) null is 0, so an unidentified store would become a real authorisation'
        );
        $this->assertFalse(
            SupportAccessGate::allows($this->operator, null, SupportAccessScope::FULL),
            'a null store id was admitted'
        );
        $this->assertFalse(
            SupportAccessGate::allows($this->operator, -1, SupportAccessScope::FULL),
            'a negative store id was admitted'
        );
    }

    /**
     * `coversImpersonation()` IS A SECOND LAYER, AND IT IS ASSERTED AS A UNIT BECAUSE AT THE DOOR IT
     * IS REDUNDANT.
     *
     * Measured: widening it to `self::isKnown($granted)` SURVIVED all 52 tests. The reason is that
     * `allowsImpersonation()` and `impersonationDecision()` both resolve the grant through
     * `activeGrant($actor, $storeId, SupportAccessScope::FULL)` first, and `covers()` already
     * admits only `full` for a `full` request — so the second check can never see a narrow grant.
     *
     * That makes it defence in depth rather than the control, which is worth having (a future door
     * that resolves a grant some other way would still be refused) and worth being HONEST about.
     * It is asserted here directly, on the unit whose contract it is, rather than left to a door
     * test that cannot distinguish it.
     */
    public function test_only_the_full_scope_covers_impersonation(): void
    {
        $this->assertTrue(SupportAccessScope::coversImpersonation(SupportAccessScope::FULL));

        foreach ([SupportAccessScope::ORDERS, SupportAccessScope::CATALOGUE,
                  SupportAccessScope::CUSTOMERS, SupportAccessScope::SETTINGS, null, 'nonsense'] as $scope) {
            $this->assertFalse(
                SupportAccessScope::coversImpersonation($scope),
                var_export($scope, true) . ' was treated as covering impersonation. Impersonation '
                . 'signs the operator in AS the merchant, so there is no filter in that path that '
                . 'could confine the session to one scope -- a narrow grant admitting it would show '
                . 'the merchant a narrow consent prompt and deliver full access.'
            );
        }
    }

    /** A non-User principal holds no grant. A `Customer` is not a `User`. */
    public function test_a_non_user_principal_holds_no_grant(): void
    {
        $this->liveSupportGrant();

        $this->assertFalse(
            SupportAccessGate::allows(null, (int) $this->store->id, SupportAccessScope::FULL)
        );
        $this->assertFalse(
            SupportAccessGate::allows(new \App\Models\Customer, (int) $this->store->id, SupportAccessScope::FULL)
        );
    }
}
