<?php

namespace Tests\Feature\Support;

use App\Models\Store;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * THE SUPPORT-TICKET DOORS HAD NO TENANT CONTROL FOR A PRINCIPAL THAT RESOLVES NO TENANT.
 * =================================================================================================
 *
 * A CORRECTION TO MY OWN CLAIM, AND IT IS THE REASON THIS FILE EXISTS. Both
 * `SupportTicketController::ticket_status_change()`'s docblock and
 * `tests/Feature/Security/TicketStatusChangeAudienceTest.php` stated that the route "IS tenant-safe"
 * because `SupportTicket` is in `config/tenancy.php`'s `scoped_models` and registers `StoreScope`.
 * That is true only **for a principal that resolves a tenant**. Independent review challenged it and
 * execution settled it against me.
 *
 * `StoreScope` has two paths that add NO predicate at all:
 *
 *   - `MODE_SUPER_ADMIN` (`app/Scopes/StoreScope.php`) returns early. Deliberate, named, and
 *     documented: a platform operator administers every store.
 *   - `MODE_UNRESOLVED` falls to `applyNullTenantPolicy()`, and `config/tenancy.php`'s
 *     `null_tenant => 'open'` adds nothing. This is exactly the condition CLAUDE.md records --
 *     *"a handler that relies on the scope for isolation is unprotected for any principal that
 *     doesn't resolve a tenant. Never let the scope be your only tenant check."*
 *
 * A `web` principal resolves NO tenant when `getCurrentStore()` answers null: an empty
 * `current_store` **and** no store of their own. `UserController` copies the creator's
 * `current_store` onto a new sub-user, and the operator's own column is normally empty, so an
 * operator-created staff row starts exactly like that. Measured, before the fix, with a staff member
 * of tenant A holding the shipped permissions and `current_store = null`:
 *
 *     POST   /ticket-status-change/{B's ticket}   200  {"status":"success"}   status changed
 *     GET    /support_ticket/{B's ticket}/edit    200  leaked B's ticket TITLE and BODY
 *     DELETE /support_ticket/{B's ticket}         302  B's row was GONE
 *
 * So the gap was a cross-tenant READ, WRITE and DELETE -- not, as I had written, "a privilege
 * question inside a tenant". The scope was the only control and for this principal it was absent.
 *
 * WHAT THE FIX IS, AND WHAT IT IS NOT. Each door now resolves the acting principal's tenant from the
 * PRINCIPAL and refuses when it cannot. It is NOT `->where('store_id', getCurrentStore())`: that
 * helper returns the hardcoded `1` for the operator, which would silently confine the platform
 * operator to store 1 -- the precise failure `StoreScope`'s operator bypass exists to avoid. The
 * operator therefore keeps that bypass, explicitly and by name.
 *
 * `D-P6-14` -- whether this writer should have a permission gate at all -- is UNCHANGED and still
 * the owner's. This file closes a tenancy hole; it decides no audience.
 */
class SupportTicketTenantConfinementTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $ownStore;

    private User $victim;

    private Store $victimStore;

    private int $victimTicket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->makeMerchant();
        $this->ownStore = $this->makeStore($this->owner, ['default_language' => 'en']);

        [$victim, $victimStore] = $this->makeTenant();
        $this->victim = $victim;
        $this->victimStore = $victimStore;

        $this->victimTicket = $this->ticketFor((int) $this->victimStore->id);

        (new User)->flushCache();
    }

    private function ticketFor(int $storeId): int
    {
        return (int) DB::table('support_tickets')->insertGetId([
            'store_id' => $storeId,
            'ticket_id' => 'T-' . $this->uniqueFixtureRef(),
            'title' => 'VICTIM TICKET TITLE',
            'description' => 'VICTIM TICKET BODY',
            'status' => 'open',
            'created_by' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * A staff member of tenant A who resolves NO tenant.
     *
     * `makeStaff()` normally copies the owner's `current_store`, which is exactly why
     * `TicketStatusChangeAudienceTest` never reached this condition -- its staff always resolved a
     * tenant. `current_store = null` plus owning no store is what `getCurrentStore()` answers null
     * for, and the premise below asserts that rather than assuming it.
     */
    private function unresolvedStaff(array $permissions): User
    {
        $staff = $this->makeStaff($this->owner, ['current_store' => null]);

        foreach ($permissions as $permission) {
            $this->grantPermission(User::find($staff->id), $permission);
        }

        (new User)->flushCache();
        $staff = User::find($staff->id);

        $this->assertNull($staff->current_store,
            'PREMISE: the staff member resolves a tenant after all, so this case cannot reach the '
            . 'null-tenant path it exists to test.');
        $this->assertSame(0, Store::withoutGlobalScopes()->where('created_by', $staff->id)->count(),
            'PREMISE: the staff member owns a store, so their tenant resolves.');

        foreach ($permissions as $permission) {
            $this->assertTrue($staff->isAbleTo($permission),
                "PREMISE: the grant of '{$permission}' did not take, so a refusal below would not "
                . 'be evidence about tenancy.');
        }

        return $staff;
    }

    private function storedStatus(int $id): ?string
    {
        $row = SupportTicket::withoutGlobalScopes()->find($id);

        return $row === null ? null : $row->status;
    }

    // ---------------------------------------------------------------------------------------
    //  THE THREE DOORS, FOR A PRINCIPAL THAT RESOLVES NO TENANT
    // ---------------------------------------------------------------------------------------

    public function test_an_unresolved_principal_cannot_change_another_tenants_ticket_status(): void
    {
        $staff = $this->unresolvedStaff(['Replay Support Ticket']);

        $this->actingAs($staff)
            ->post("/ticket-status-change/{$this->victimTicket}", ['status' => 'Solved']);

        $this->assertSame('open', $this->storedStatus($this->victimTicket),
            "another tenant's ticket status was changed by a principal that resolves no tenant");
    }

    public function test_an_unresolved_principal_cannot_read_another_tenants_ticket(): void
    {
        $staff = $this->unresolvedStaff(['Replay Support Ticket']);

        $response = $this->actingAs($staff)->get("/support_ticket/{$this->victimTicket}/edit");

        $body = (string) $response->getContent();

        $this->assertStringNotContainsString('VICTIM TICKET TITLE', $body,
            "another tenant's ticket TITLE was rendered to a principal that resolves no tenant");
        $this->assertStringNotContainsString('VICTIM TICKET BODY', $body,
            "another tenant's ticket BODY was rendered to a principal that resolves no tenant");
    }

    public function test_an_unresolved_principal_cannot_delete_another_tenants_ticket(): void
    {
        $staff = $this->unresolvedStaff(['Delete Support Ticket']);

        $this->actingAs($staff)->delete("/support_ticket/{$this->victimTicket}");

        $this->assertTrue(
            SupportTicket::withoutGlobalScopes()->where('id', $this->victimTicket)->exists(),
            "another tenant's ticket row was DELETED by a principal that resolves no tenant");
    }

    /**
     * CORRECTED: THIS CASE USED TO POST A FIELD THE HANDLER DOES NOT READ, AND SO PASSED ON THE OPEN
     * DOOR.
     *
     * It posted `['description' => ...]`. `conversion_store()` validates **`reply_description`**, so
     * the request was refused by the validator, redirected back with an error, and never reached the
     * ticket lookup -- CLAUDE.md's trap 3, a precondition that was never met. The assertion held
     * whether or not the door was confined, and the door was NOT: unlike its three siblings,
     * `conversion_store()` resolved the ticket with a bare `SupportTicket::find()` and relied on a
     * scope that adds nothing for this principal. Driving the real field wrote a reply into another
     * tenant's thread.
     *
     * The field is corrected here and the door is covered in full, with the control that proves the
     * field is right and the case that pins where the row is filed, in
     * `SupportTicketReplyConfinementTest`.
     */
    public function test_an_unresolved_principal_cannot_reply_on_another_tenants_ticket(): void
    {
        $staff = $this->unresolvedStaff(['Replay Support Ticket']);

        $this->actingAs($staff)->post("/ticket/{$this->victimTicket}/conversion", [
            'reply_description' => 'A REPLY FROM ANOTHER TENANT',
        ]);

        $this->assertSame(0,
            DB::table('support_conversions')->where('ticket_id', $this->victimTicket)->count(),
            "a reply was written onto another tenant's ticket by a principal that resolves no tenant");
    }

    /**
     * A PRINCIPAL WITH NO TENANT ROOT AT ALL IS REFUSED -- the fail-closed branch.
     *
     * ADDED BECAUSE A MUTATION SURVIVED. Flipping `if (empty($root)) { return []; }` to `return
     * null` -- i.e. fail OPEN -- broke nothing, because every fixture above has a `created_by`
     * pointing at the tenant owner, so none of them reached that branch. `users.created_by` is
     * nullable, so a row in that state is constructible and this case constructs one.
     */
    public function test_a_principal_with_no_tenant_root_is_refused(): void
    {
        $rootless = $this->makeStaff($this->owner, ['current_store' => null]);
        $this->grantPermission(User::find($rootless->id), 'Replay Support Ticket');

        // No tenant root: creatorId() reads created_by for a non-admin type, and this row has none.
        DB::table('users')->where('id', $rootless->id)->update(['created_by' => null]);
        (new User)->flushCache();
        $rootless = User::find($rootless->id);

        $this->assertNull($rootless->creatorId(),
            'PREMISE: the principal still resolves a tenant root, so the fail-closed branch is '
            . 'not reached and this case proves nothing.');

        $this->actingAs($rootless)
            ->post("/ticket-status-change/{$this->victimTicket}", ['status' => 'Solved']);

        $this->assertSame('open', $this->storedStatus($this->victimTicket),
            'a principal with no tenant root at all was allowed to write; the resolver failed OPEN');
    }

    /**
     * A MERCHANT WHO DEMOTES THEIR OWN `type` DOES NOT INHERIT THE OPERATOR'S STORES.
     *
     * ALSO ADDED BECAUSE A MUTATION SURVIVED -- flipping the type-`admin` root test to fail open
     * broke nothing, and this is the case it exists for. CLAUDE.md records the shape as a real
     * account-takeover path, reachable through the product: every merchant has `created_by = 1`, and
     * a merchant can change their own `type` via `PUT /users/{self}` with a role they created. Once
     * they are no longer type-`admin`, `creatorId()` stops answering their own id and drops to
     * `created_by` -- the OPERATOR -- so "the stores this principal's tenant owns" would become the
     * operator's stores. P1-SEC-67b is the same rule in `StoreScope::ownedStoreIds()`; this is it at
     * the controller.
     */
    public function test_a_demoted_merchant_does_not_inherit_the_operators_tenant(): void
    {
        $operator = $this->makePlatformAdmin();
        $operatorStore = $this->makeStore($operator, ['default_language' => 'en']);
        $operatorTicket = $this->ticketFor((int) $operatorStore->id);

        /*
         * THE FIXTURE OWNS NO STORE, AND THAT IS THE WHOLE POINT -- the first version of this case
         * demoted `$this->owner`, who DOES own a store, so `getCurrentStore()` still resolved
         * through `Store::where('created_by', $user->id)` and `StoreScope` confined them anyway.
         * The case passed with the type-root rule REMOVED, which the mutation harness reported as a
         * survivor. A principal with no store of their own is the one for whom this rule, and
         * nothing else, decides.
         */
        $demoted = $this->makeMerchant(['created_by' => $operator->id, 'current_store' => null]);
        $demoted->type = 'staff';
        $demoted->save();
        $this->grantPermission(User::find($demoted->id), 'Replay Support Ticket');
        (new User)->flushCache();
        $demoted = User::find($demoted->id);

        $this->assertSame((int) $operator->id, (int) $demoted->creatorId(),
            'PREMISE: the demoted merchant does not resolve the operator as their root, so this '
            . 'case does not reach the rule it exists for.');
        $this->assertSame('super admin', strtolower(trim((string) User::find($operator->id)->type)),
            'PREMISE: the root is not the operator, so the type rule is not what decides.');
        $this->assertSame(0, Store::withoutGlobalScopes()->where('created_by', $demoted->id)->count(),
            'PREMISE: the principal owns a store, so StoreScope confines them and the type rule is '
            . 'not what decides this case.');

        $this->actingAs($demoted)
            ->post("/ticket-status-change/{$operatorTicket}", ['status' => 'Solved']);

        $this->assertSame('open', $this->storedStatus($operatorTicket),
            "a demoted merchant inherited the OPERATOR's tenant and wrote one of its tickets");
    }

    // ---------------------------------------------------------------------------------------
    //  THE FIX MUST NOT CLOSE THE DOOR ON THE PEOPLE WHO BELONG THERE
    // ---------------------------------------------------------------------------------------

    public function test_a_resolved_staff_member_still_works_their_own_tenants_ticket(): void
    {
        $own = $this->ticketFor((int) $this->ownStore->id);

        $staff = $this->makeStaff($this->owner, ['current_store' => $this->ownStore->id]);
        $this->grantPermission(User::find($staff->id), 'Replay Support Ticket');
        (new User)->flushCache();
        $staff = User::find($staff->id);

        $response = $this->actingAs($staff)->post("/ticket-status-change/{$own}", ['status' => 'Solved']);

        $response->assertOk();
        $response->assertJsonPath('status', 'success');
        $this->assertSame('Solved', $this->storedStatus($own),
            'the confinement refused a principal acting on their OWN tenant');
    }

    /**
     * A STAFF MEMBER WITH AN EMPTY `current_store` WHOSE TENANT DOES OWN STORES STILL WORKS.
     *
     * This is the case that makes the fix a confinement rather than a lockout: the tenant is derived
     * from the principal's root, not from `current_store`, so an empty column is not a refusal when
     * the tenant is knowable. Without this case, "refuse when `current_store` is empty" would pass
     * every hostile case above and quietly break a real staff member.
     */
    public function test_an_empty_current_store_still_resolves_through_the_tenant_root(): void
    {
        $own = $this->ticketFor((int) $this->ownStore->id);

        $staff = $this->makeStaff($this->owner, ['current_store' => null]);
        $this->grantPermission(User::find($staff->id), 'Replay Support Ticket');
        (new User)->flushCache();
        $staff = User::find($staff->id);

        $this->assertNull($staff->current_store, 'PREMISE: the fixture resolves the easy way.');

        $response = $this->actingAs($staff)->post("/ticket-status-change/{$own}", ['status' => 'Solved']);

        $response->assertOk();
        $this->assertSame('Solved', $this->storedStatus($own),
            'a staff member with an empty current_store can no longer work their own tenant, so the '
            . 'confinement is a lockout rather than a tenant check');
    }

    public function test_the_tenant_owner_still_works_their_own_ticket(): void
    {
        $own = $this->ticketFor((int) $this->ownStore->id);

        $owner = User::find($this->owner->id);
        $owner->current_store = $this->ownStore->id;
        $owner->save();
        (new User)->flushCache();

        $response = $this->actingAs(User::find($owner->id))
            ->post("/ticket-status-change/{$own}", ['status' => 'In Progress']);

        $response->assertOk();
        $this->assertSame('In Progress', $this->storedStatus($own));
    }

    // ---------------------------------------------------------------------------------------
    //  THE OPERATOR BYPASS IS DELIBERATE AND STAYS
    // ---------------------------------------------------------------------------------------

    /**
     * The platform operator keeps cross-store reach on this writer, and that is NOT an oversight.
     *
     * `StoreScope::resolveTenant()` returns `MODE_SUPER_ADMIN` and `apply()` deliberately adds no
     * predicate, because narrowing the operator to one store "would hide the platform behind what
     * looks like missing data" -- the scope's own words. Confining them here with
     * `getCurrentStore()` would pin them to the hardcoded store `1`, which is the failure that
     * bypass exists to prevent. Asserted so that a later change cannot remove the bypass by
     * accident while believing it is tightening tenancy.
     *
     * Whether the operator SHOULD reach this ungated writer at all is part of `D-P6-14`, recorded
     * on the handler. It is an audience question and not this file's to answer.
     */
    public function test_the_platform_operator_keeps_its_deliberate_cross_store_reach(): void
    {
        $operator = $this->makePlatformAdmin();
        (new User)->flushCache();

        $response = $this->actingAs(User::find($operator->id))
            ->post("/ticket-status-change/{$this->victimTicket}", ['status' => 'Solved']);

        $response->assertOk();
        $this->assertSame('Solved', $this->storedStatus($this->victimTicket),
            'the platform operator lost the cross-store reach StoreScope grants by design; if that '
            . 'is intended it is D-P6-14, and this test and the scope must be changed together');
    }
}
