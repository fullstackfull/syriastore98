<?php

namespace Tests\Feature\Support;

use App\Models\AuditLog;
use App\Models\Store;
use App\Models\SupportAccessGrant;
use App\Models\User;
use App\Support\SupportAccess\AccessKind;
use App\Support\SupportAccess\SupportAccessAudit;
use App\Support\SupportAccess\SupportAccessGrants;
use App\Support\SupportAccess\SupportAccessScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\Feature\Security\SecurityTestCase;

/**
 * THE LIVE DOOR — `GET store/{id}/login-with-admin` — CONSULTING THE GRANT MECHANISM.
 * =================================================================================================
 *
 * `SUPPORT-004`. Before this slice the only controls on "log in as this merchant" were
 * `UserPolicy::impersonate` (operator only, not self, not another operator) and the audit row
 * `App\Support\Audit\RecordImpersonation` writes. That is the AUDIT clause and none of the other
 * three: the operator self-authorised, nothing expired, and there was no way to ask for less than
 * everything.
 *
 * -------------------------------------------------------------------------------------------------
 *  BOTH CONFIG MODES ARE DRIVEN, AND THAT IS THE POINT OF THIS FILE
 * -------------------------------------------------------------------------------------------------
 *
 * `config('support_access.enforce')` ships `false` (owner question 1) because `true` with an empty
 * grant table refuses every support login on the first deploy. A mechanism whose enforcing path is
 * never executed is dormant code, so every case here runs in the mode it names and the two modes
 * are asserted to produce OPPOSITE outcomes on identical fixtures.
 *
 * -------------------------------------------------------------------------------------------------
 *  THE REFUSAL IS KEYED ON ITS REASON, NEVER ON THE STATUS
 * -------------------------------------------------------------------------------------------------
 *
 * CLAUDE.md, twice over: "a gateless handler's own 'not found' branch is also a 302, so a 302 from a
 * missing row and a 302 from a gate are the same status and opposite facts"; and the module trap
 * where a refused gate lands on the dashboard with HTTP 200. Both apply here — the enforced refusal
 * is `redirect()->back()` and so is `take()`'s own failure branch, and the ADMITTED path is
 * `redirect('dashboard')`. So:
 *
 *   • the admitted case asserts `assertRedirect('dashboard')` — the LANDED PATH;
 *   • the refused case asserts the session error MESSAGE, so a refusal for the wrong reason fails;
 *   • and both assert whether the `web` guard now holds the MERCHANT, which is the thing that
 *     actually happened or did not. A status code cannot tell those apart and the guard can.
 */
class SupportAccessEnforcementTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $operator;

    private User $merchant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = $this->makePlatformAdmin();
        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        (new User)->flushCache();

        config()->set('support_access.max_duration_minutes', 1440);
        config()->set('support_access.emergency.max_duration_minutes', 240);
        config()->set('support_access.emergency.required_approvals', 2);

        // PREMISE: the policy admits this pairing, so anything this file measures is the GRANT
        // mechanism and not `UserPolicy::impersonate`. Without this, an enforced refusal and a
        // policy refusal would be indistinguishable -- both `redirect()->back()`.
        $this->assertTrue($this->operator->canImpersonate(), 'PREMISE: the operator cannot impersonate.');
        $this->assertTrue($this->merchant->canBeImpersonated(), 'PREMISE: the merchant is not impersonable.');

        $this->requireTable('support_access_grants');
    }

    private function takeRoute(User $target): string
    {
        return route('login.with.admin', Crypt::encrypt($target->id));
    }

    private function consentedFullGrant(?Store $store = null): SupportAccessGrant
    {
        $store = $store ?? $this->store;

        $grant = SupportAccessGrants::request(
            $this->operator,
            (int) $store->id,
            AccessKind::SUPPORT,
            SupportAccessScope::FULL,
            'The merchant asked for help with their checkout.',
            60
        );

        return SupportAccessGrants::consent($grant, $this->merchant);
    }

    private function actions(): array
    {
        return AuditLog::query()->withoutGlobalScopes()->orderBy('id')->pluck('action')->all();
    }

    // =============================================================================================
    //  ENFORCED
    // =============================================================================================

    /**
     * THE CORE ASSERTION OF THIS FILE: with enforcement on and no consent, the operator does NOT
     * become the merchant.
     */
    public function test_enforced_and_ungranted_the_operator_is_refused_and_does_not_become_the_merchant(): void
    {
        config()->set('support_access.enforce', true);

        $response = $this->actingAs($this->operator)->get($this->takeRoute($this->merchant));

        // The REASON, not the status: a `redirect()->back()` is also what `take()` answers on
        // failure, and what the policy would answer.
        $response->assertSessionHas('error');
        $this->assertStringContainsString(
            'needs their consent',
            (string) session('error'),
            'the request was refused for some other reason than the missing grant'
        );

        // AND THE FACT ITSELF. `Impersonate::take()` would have signed the merchant into the `web`
        // guard; if it ran, this is the merchant.
        $this->assertSame(
            (int) $this->operator->id,
            (int) auth('web')->id(),
            'the operator became the merchant although support access was refused'
        );

        $this->assertNotContains(
            'impersonation_started',
            $this->actions(),
            'impersonation was taken although the grant gate refused it'
        );
        $this->assertContains(
            SupportAccessAudit::REFUSED,
            $this->actions(),
            'the refusal was not recorded in the audit trail'
        );
    }

    /**
     * POSITIVE CONTROL FOR THE WHOLE FILE. With enforcement on AND a consented grant, the access
     * proceeds. Without this, every refusal above would hold just as well if the door were broken.
     */
    public function test_enforced_with_a_consented_grant_the_access_proceeds_and_is_counted(): void
    {
        config()->set('support_access.enforce', true);
        $grant = $this->consentedFullGrant();

        $this->actingAs($this->operator)
            ->get($this->takeRoute($this->merchant))
            ->assertRedirect('dashboard');

        $this->assertSame(
            (int) $this->merchant->id,
            (int) auth('web')->id(),
            'CONTROL: a consented grant did not admit the operator, so the refusals in this file '
            . 'prove nothing'
        );

        $fresh = SupportAccessGrant::query()->find($grant->id);
        $this->assertSame(1, (int) $fresh->uses, 'the use was not counted against the grant');

        $actions = $this->actions();
        $this->assertContains(SupportAccessAudit::USED, $actions);
        $this->assertContains('impersonation_started', $actions,
            'the pre-existing impersonation audit row was lost');
        $this->assertNotContains(SupportAccessAudit::REFUSED, $actions);
        $this->assertNotContains(SupportAccessAudit::UNGRANTED, $actions);
    }

    /** An expired grant is no grant. The temporary clause, measured at the real door. */
    public function test_enforced_an_expired_grant_does_not_admit_the_operator(): void
    {
        config()->set('support_access.enforce', true);
        $grant = $this->consentedFullGrant();
        $grant->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->actingAs($this->operator)->get($this->takeRoute($this->merchant));

        $this->assertStringContainsString('needs their consent', (string) session('error'));
        $this->assertSame((int) $this->operator->id, (int) auth('web')->id(),
            'an expired grant admitted the operator at the live door');
    }

    /** Revocation is effective at the real door, not only in the predicate. */
    public function test_enforced_a_revoked_grant_does_not_admit_the_operator(): void
    {
        config()->set('support_access.enforce', true);
        $grant = $this->consentedFullGrant();
        SupportAccessGrants::revoke($grant, $this->merchant, 'I withdraw.');

        $this->actingAs($this->operator)->get($this->takeRoute($this->merchant));

        $this->assertSame((int) $this->operator->id, (int) auth('web')->id(),
            'a revoked grant admitted the operator at the live door');
    }

    /**
     * A NARROW GRANT DOES NOT OPEN THE IMPERSONATION DOOR.
     *
     * The scoped clause, at the door rather than in the predicate. `orders` is a real, live,
     * consented grant — it just is not full access, and impersonation is.
     */
    public function test_enforced_a_narrow_grant_does_not_open_the_impersonation_door(): void
    {
        config()->set('support_access.enforce', true);

        $grant = SupportAccessGrants::request(
            $this->operator,
            (int) $this->store->id,
            AccessKind::SUPPORT,
            SupportAccessScope::ORDERS,
            'Only the orders list, please.',
            60
        );
        SupportAccessGrants::consent($grant, $this->merchant);

        $this->actingAs($this->operator)->get($this->takeRoute($this->merchant));

        $this->assertSame(
            (int) $this->operator->id,
            (int) auth('web')->id(),
            'an orders-only grant let the operator sign in AS the merchant, which reaches everything'
        );
    }

    /**
     * IMPERSONATION REACHES EVERY STORE THE MERCHANT OWNS, SO IT NEEDS A GRANT FOR EACH.
     *
     * The merchant here owns two stores and has consented for one. Admitting that would hand over
     * the second store, which the merchant never consented to — an impersonated session may switch
     * between any store with `created_by = <merchant>` using the merchant's own ordinary ability.
     */
    public function test_enforced_a_grant_for_one_of_two_stores_does_not_admit_impersonation(): void
    {
        config()->set('support_access.enforce', true);

        $second = $this->makeStore($this->merchant, ['default_language' => 'en']);
        (new User)->flushCache();
        $this->consentedFullGrant($this->store);

        $this->actingAs($this->operator)->get($this->takeRoute($this->merchant));

        $this->assertSame(
            (int) $this->operator->id,
            (int) auth('web')->id(),
            'a grant covering one of the merchant\'s two stores admitted impersonation, which '
            . 'reaches both'
        );

        // CONTROL: consenting for the second store too admits it.
        $this->consentedFullGrant($second);
        $this->actingAs($this->operator)
            ->get($this->takeRoute($this->merchant))
            ->assertRedirect('dashboard');
        $this->assertSame((int) $this->merchant->id, (int) auth('web')->id(),
            'CONTROL: grants for both stores were still refused');
    }

    /**
     * A MERCHANT WHO OWNS NO STORE IS REFUSED, NOT WAVED THROUGH.
     *
     * ADDED AFTER A SURVIVING MUTATION. Flipping `impersonationDecision()`'s empty-store branch to
     * `allowed => true` SURVIVED all 52 tests: the behaviour was documented in the gate's docblock
     * and asserted nowhere, because every fixture in both files gives its merchant a store.
     *
     * The refusal looks odd and is deliberate: with no store there is no `store_id` for a grant to
     * name, so no consent CAN exist — and admitting the case would make "owns no store" a bypass of
     * the entire mechanism, reachable by an operator who deletes a merchant's last store first.
     * It fails closed.
     */
    public function test_enforced_a_merchant_with_no_store_is_refused_rather_than_waved_through(): void
    {
        config()->set('support_access.enforce', true);

        $storeless = $this->makeMerchant();
        (new User)->flushCache();

        $this->assertSame(
            0,
            Store::withoutGlobalScopes()->where('created_by', $storeless->id)->count(),
            'PREMISE: the fixture merchant owns a store, so this case cannot reach the empty branch'
        );
        $this->assertTrue($storeless->canBeImpersonated(),
            'PREMISE: the policy already refuses this merchant, so the grant gate is not what is '
            . 'being measured');

        $this->actingAs($this->operator)->get($this->takeRoute($storeless));

        $this->assertSame(
            (int) $this->operator->id,
            (int) auth('web')->id(),
            'a merchant who owns no store was impersonated with no grant at all -- "owns no store" '
            . 'is a bypass of the whole consent mechanism, since no consent can exist without a '
            . 'store for a grant to name'
        );
        $this->assertStringContainsString('needs their consent', (string) session('error'));
    }

    // =============================================================================================
    //  NOT ENFORCED — the shipped default
    // =============================================================================================

    /**
     * THE SHIPPED DEFAULT CHANGES NO BEHAVIOUR, AND IS NOT SILENT.
     *
     * Two assertions, and the second is why `enforce => false` is not "the mechanism does nothing":
     * the access proceeds exactly as it did before this slice, AND a `support_access_ungranted` row
     * is written. That row makes the gap `SUPPORT-004` names COUNTABLE before the owner has to
     * decide whether to switch enforcement on — the count of ungranted support logins is the best
     * input to that decision and nobody has it today.
     */
    public function test_unenforced_the_access_proceeds_exactly_as_before_but_is_recorded_as_ungranted(): void
    {
        config()->set('support_access.enforce', false);

        $this->actingAs($this->operator)
            ->get($this->takeRoute($this->merchant))
            ->assertRedirect('dashboard');

        $this->assertSame(
            (int) $this->merchant->id,
            (int) auth('web')->id(),
            'the unenforced mode broke the existing support login, which it must not do'
        );

        $actions = $this->actions();
        $this->assertContains(
            SupportAccessAudit::UNGRANTED,
            $actions,
            'an ungranted support access left no record, so the gap SUPPORT-004 names stays '
            . 'unmeasurable'
        );
        $this->assertContains('impersonation_started', $actions,
            'the pre-existing impersonation audit row was lost');
        $this->assertNotContains(SupportAccessAudit::REFUSED, $actions);
    }

    /** The ungranted row names WHOSE data was reached, which is the only thing a merchant cares about. */
    public function test_the_ungranted_row_names_the_merchant_and_the_operator(): void
    {
        config()->set('support_access.enforce', false);

        $this->actingAs($this->operator)->get($this->takeRoute($this->merchant));

        $row = AuditLog::query()->withoutGlobalScopes()
            ->where('action', SupportAccessAudit::UNGRANTED)->first();

        $this->assertNotNull($row, 'no ungranted row was written');
        $this->assertSame(User::class, $row->subject_type);
        $this->assertSame((int) $this->merchant->id, (int) $row->subject_id,
            'the row does not say which merchant was reached');

        // THE ACTOR MUST BE THE OPERATOR. This row is written BEFORE `take()`, so the guard still
        // holds the operator here -- but the actor is passed explicitly anyway, for the reason
        // `RecordImpersonation` records: after `take()` the guard holds the merchant, and a future
        // edit moving this call below it must not silently start naming the merchant.
        $this->assertSame((int) $this->operator->id, (int) $row->user_id,
            'the ungranted row does not name the operator as the actor');
    }

    /** With a live grant, the unenforced mode records a USE and not an UNGRANTED. */
    public function test_unenforced_with_a_consented_grant_the_use_is_recorded_not_the_gap(): void
    {
        config()->set('support_access.enforce', false);
        $this->consentedFullGrant();

        $this->actingAs($this->operator)
            ->get($this->takeRoute($this->merchant))
            ->assertRedirect('dashboard');

        $actions = $this->actions();
        $this->assertContains(SupportAccessAudit::USED, $actions);
        $this->assertNotContains(
            SupportAccessAudit::UNGRANTED,
            $actions,
            'a consented access was recorded as ungranted, which would make the gap count useless'
        );
    }

    // =============================================================================================
    //  THE EXISTING CONTROLS ARE UNCHANGED
    // =============================================================================================

    /**
     * THE GRANT MECHANISM IS STRICTLY NARROWING: a grant must never admit someone
     * `UserPolicy::impersonate` refuses. Driven with enforcement OFF and a merchant acting, which
     * is the combination in which a mistake here would be invisible.
     */
    public function test_a_merchant_still_cannot_impersonate_whatever_grants_exist(): void
    {
        config()->set('support_access.enforce', false);

        $victim = $this->makeMerchant();
        $this->makeStore($victim, ['default_language' => 'en']);
        (new User)->flushCache();

        $this->actingAs($this->merchant)
            ->get($this->takeRoute($victim))
            ->assertForbidden();

        $this->assertSame((int) $this->merchant->id, (int) auth('web')->id(),
            'a merchant impersonated another merchant');
    }

    /** Another platform operator is still not impersonable. */
    public function test_an_operator_still_cannot_impersonate_another_operator(): void
    {
        config()->set('support_access.enforce', false);
        $other = $this->makePlatformAdmin();
        (new User)->flushCache();

        $this->actingAs($this->operator)
            ->get($this->takeRoute($other))
            ->assertForbidden();
    }
}
