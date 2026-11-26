<?php

namespace Tests\Feature\Support;

use App\Models\Store;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * THE MERCHANT REPLY DOOR WAS THE ONE `SupportTicketController` METHOD LEFT OUT OF THE CONFINEMENT,
 * AND THE TEST THAT CLAIMED TO COVER IT POSTED A FIELD THE HANDLER DOES NOT READ.
 * =================================================================================================
 *
 * `SupportTicketTenantConfinementTest` closed `edit()`, `destroy()` and `ticket_status_change()` by
 * resolving the acting principal's tenant from the PRINCIPAL (`actingTenantStoreIds()`), because
 * `StoreScope` adds no predicate on `MODE_UNRESOLVED` (`null_tenant => 'open'`). It carries a fifth
 * case, `test_an_unresolved_principal_cannot_reply_on_another_tenants_ticket`, which posted
 * `['description' => ...]`.
 *
 * `conversion_store()` validates `reply_description`, not `description`. So that request was refused
 * by the VALIDATOR, redirected back with an error, and never reached the ticket lookup at all -- its
 * assertion ("no conversation row exists") held whether or not the door was confined. It is
 * CLAUDE.md's trap 3 (a precondition that was never met) in its purest form: the case passed on the
 * unfixed handler, and would have passed with every tenant check deleted.
 *
 * `conversion_store()` resolved the ticket with a bare `SupportTicket::find($ticket_id)` and relied
 * on the scope. Measured on this branch before the fix, as a staff member of tenant A holding
 * `Replay Support Ticket` with `current_store = null` (the state `UserController` leaves an
 * operator-created staff row in), against tenant B's ticket:
 *
 *     POST /ticket/{B's ticket}/conversion   reply_description=...   302  row WRITTEN
 *
 * -- a cross-tenant WRITE into another merchant's support thread, read by that merchant and by the
 * shopper who raised the ticket.
 *
 * A SECOND DEFECT AT THE SAME LINE, AND IT IS NOT AN AUTHORIZATION ONE. The reply was filed with
 * `store_id = getCurrentStore()`, which answers the ACTOR's store: `null` for the unresolved staff
 * member above and the hardcoded `1` for the platform operator, who keeps a deliberate cross-store
 * reach here. `SupportConversion` is in `scoped_models` and really filters, so a reply the operator
 * wrote on tenant B's ticket was filed under store 1 -- invisible to B's merchant reading
 * `$ticket->conversions()`, and visible to store 1's merchant under a ticket they cannot open. The
 * reply now takes its store from the RESOLVED TICKET, which is the only store the row can mean.
 *
 * `D-P6-14` (whether `ticket_status_change` should carry a permission gate) is untouched: every case
 * here grants `Replay Support Ticket`, which `conversion_store()` has always required.
 */
class SupportTicketReplyConfinementTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $ownStore;

    private Store $victimStore;

    private int $victimTicket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->makeMerchant();
        $this->ownStore = $this->makeStore($this->owner, ['default_language' => 'en']);

        [, $victimStore] = $this->makeTenant();
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
     * A staff member of tenant A who resolves NO tenant -- the principal for whom the scope adds
     * nothing. The premises are asserted rather than assumed, because `makeStaff()` normally copies
     * the owner's `current_store` and a fixture that resolves a tenant cannot reach this path.
     */
    private function unresolvedStaff(): User
    {
        $staff = $this->makeStaff($this->owner, ['current_store' => null]);
        $this->grantPermission(User::find($staff->id), 'Replay Support Ticket');
        (new User)->flushCache();
        $staff = User::find($staff->id);

        $this->assertNull($staff->current_store,
            'PREMISE: the staff member resolves a tenant after all, so this case cannot reach the '
            . 'null-tenant path it exists to test.');
        $this->assertSame(0, Store::withoutGlobalScopes()->where('created_by', $staff->id)->count(),
            'PREMISE: the staff member owns a store, so their tenant resolves.');
        $this->assertTrue($staff->isAbleTo('Replay Support Ticket'),
            "PREMISE: the grant of 'Replay Support Ticket' did not take, so a refusal below would "
            . 'be the permission gate talking and not the tenant check.');

        return $staff;
    }

    private function repliesOn(int $ticketId): int
    {
        return DB::table('support_conversions')->where('ticket_id', $ticketId)->count();
    }

    // -------------------------------------------------------------------------------------------
    //  THE DOOR
    // -------------------------------------------------------------------------------------------

    public function test_an_unresolved_principal_cannot_reply_on_another_tenants_ticket(): void
    {
        $staff = $this->unresolvedStaff();

        $this->actingAs($staff)->post("/ticket/{$this->victimTicket}/conversion", [
            'reply_description' => 'A REPLY FROM ANOTHER TENANT',
        ]);

        $this->assertSame(0, $this->repliesOn($this->victimTicket),
            "a reply was written onto another tenant's ticket by a principal that resolves no tenant");
        $this->assertSame(0,
            DB::table('support_conversions')->where('description', 'A REPLY FROM ANOTHER TENANT')->count(),
            'the reply was written somewhere, under some other ticket id');
    }

    /**
     * THE CONTROL THAT MAKES THE CASE ABOVE EVIDENCE, AND THE PROOF THAT THE OLD CASE WAS VACUOUS.
     *
     * Same principal shape, same field, their OWN tenant's ticket: the reply must land. Without
     * this, "no row was written" is also what a refused validator, a 404 and a 500 produce -- and
     * the whole reason this file exists is that exactly that happened to the field name.
     */
    public function test_the_field_the_handler_reads_is_reply_description_and_a_resolved_staff_member_can_use_it(): void
    {
        $own = $this->ticketFor((int) $this->ownStore->id);

        $staff = $this->makeStaff($this->owner, ['current_store' => $this->ownStore->id]);
        $this->grantPermission(User::find($staff->id), 'Replay Support Ticket');
        (new User)->flushCache();
        $staff = User::find($staff->id);

        // The spelling the old case used. The validator refuses it, so NOTHING is written -- which
        // is why that assertion could not tell a confined door from an open one.
        $this->actingAs($staff)->post("/ticket/{$own}/conversion", ['description' => 'wrong field']);
        $this->assertSame(0, $this->repliesOn($own),
            "PREMISE: `description` reached the writer, so the old case was not vacuous after all "
            . 'and this file is built on a false reading.');

        $this->actingAs($staff)->post("/ticket/{$own}/conversion", ['reply_description' => 'a real reply']);
        $this->assertSame(1, $this->repliesOn($own),
            'the confinement refused a staff member replying on their OWN tenant: this is a lockout, '
            . 'not a tenant check');
    }

    public function test_a_principal_with_no_tenant_root_cannot_reply(): void
    {
        $rootless = $this->makeStaff($this->owner, ['current_store' => null]);
        $this->grantPermission(User::find($rootless->id), 'Replay Support Ticket');

        DB::table('users')->where('id', $rootless->id)->update(['created_by' => null]);
        (new User)->flushCache();
        $rootless = User::find($rootless->id);

        $this->assertNull($rootless->creatorId(),
            'PREMISE: the principal still resolves a tenant root, so the fail-closed branch is not '
            . 'reached and this case proves nothing.');

        $this->actingAs($rootless)->post("/ticket/{$this->victimTicket}/conversion", [
            'reply_description' => 'A REPLY FROM NOBODY',
        ]);

        $this->assertSame(0, $this->repliesOn($this->victimTicket),
            'a principal with no tenant root at all wrote a reply; the resolver failed OPEN');
    }

    /**
     * A DEMOTED MERCHANT DOES NOT INHERIT THE OPERATOR'S TENANT AT THIS DOOR EITHER.
     *
     * The same account-takeover shape CLAUDE.md records: every merchant has `created_by = 1`, and a
     * merchant who changes their own `type` drops `creatorId()` to the operator. The fixture owns no
     * store, so `StoreScope` does not confine it and this rule is what decides.
     */
    public function test_a_demoted_merchant_does_not_inherit_the_operators_tenant(): void
    {
        $operator = $this->makePlatformAdmin();
        $operatorStore = $this->makeStore($operator, ['default_language' => 'en']);
        $operatorTicket = $this->ticketFor((int) $operatorStore->id);

        $demoted = $this->makeMerchant(['created_by' => $operator->id, 'current_store' => null]);
        $demoted->type = 'staff';
        $demoted->save();
        $this->grantPermission(User::find($demoted->id), 'Replay Support Ticket');
        (new User)->flushCache();
        $demoted = User::find($demoted->id);

        $this->assertSame((int) $operator->id, (int) $demoted->creatorId(),
            'PREMISE: the demoted merchant does not resolve the operator as their root.');
        $this->assertSame(0, Store::withoutGlobalScopes()->where('created_by', $demoted->id)->count(),
            'PREMISE: the principal owns a store, so StoreScope confines them and the type rule is '
            . 'not what decides this case.');

        $this->actingAs($demoted)->post("/ticket/{$operatorTicket}/conversion", [
            'reply_description' => 'A REPLY FROM A DEMOTED MERCHANT',
        ]);

        $this->assertSame(0, $this->repliesOn($operatorTicket),
            "a demoted merchant inherited the OPERATOR's tenant and replied on one of its tickets");
    }

    // -------------------------------------------------------------------------------------------
    //  WHERE THE ROW IS FILED
    // -------------------------------------------------------------------------------------------

    /**
     * THE OPERATOR KEEPS ITS CROSS-STORE REACH -- and the row it writes is filed under the TICKET'S
     * store, not under `getCurrentStore()`'s hardcoded `1`.
     *
     * Both halves are asserted in one case on purpose: "the operator can reply" and "the reply is
     * findable by the tenant whose thread it is" are the same user-visible fact, and asserting only
     * the first is what let the mis-filing stand.
     */
    public function test_an_operators_reply_is_filed_under_the_tickets_own_store(): void
    {
        $operator = $this->makePlatformAdmin();
        $this->grantPermission(User::find($operator->id), 'Replay Support Ticket');
        (new User)->flushCache();
        $operator = User::find($operator->id);

        $this->assertSame(1, (int) getCurrentStore($operator->id),
            'PREMISE: getCurrentStore() no longer answers the hardcoded 1 for the operator, so this '
            . 'case no longer separates the ticket\'s store from the actor\'s.');
        $this->assertNotSame(1, (int) $this->victimStore->id,
            'PREMISE: the victim store IS store 1, so the two readings agree and the case cannot '
            . 'tell them apart.');

        $this->actingAs($operator)->post("/ticket/{$this->victimTicket}/conversion", [
            'reply_description' => 'AN OPERATOR REPLY',
        ]);

        $row = DB::table('support_conversions')->where('ticket_id', $this->victimTicket)->first();

        $this->assertNotNull($row,
            'the platform operator lost the cross-store reach StoreScope grants by design; if that '
            . 'is intended it is D-P6-14, and this test and the scope must be changed together');
        $this->assertSame((int) $this->victimStore->id, (int) $row->store_id,
            "the reply was filed under the actor's store rather than the ticket's, so the merchant "
            . 'whose thread it is cannot see it (SupportConversion is scoped)');
    }

    /**
     * AND THE ATTACHMENT GOES TO THE TICKET'S STORE FOLDER -- the merchant-side twin of P1-SEC-71.
     *
     * The folder was `uploads/<getCurrentStore()>/reply_tickets`, so for the operator every reply
     * attachment on every tenant's ticket landed in store `1`'s folder, and for the unresolved staff
     * member in `uploads//reply_tickets` -- "a folder no store's id is part of", which is the exact
     * wording of the storefront defect P1-SEC-71 fixed and did not reach this path.
     *
     * The operator is the principal used here because it is the only one that can legitimately reply
     * on another tenant's ticket, so the ticket's store and the actor's store differ for a reason
     * rather than by a fixture accident.
     */
    public function test_a_reply_attachment_is_stored_in_the_tickets_store_folder(): void
    {
        \Illuminate\Support\Facades\Storage::fake('theme');

        $operator = $this->makePlatformAdmin();
        $this->grantPermission(User::find($operator->id), 'Replay Support Ticket');
        (new User)->flushCache();

        $this->assertSame(1, (int) getCurrentStore($operator->id),
            'PREMISE: the actor no longer resolves store 1, so this case cannot separate the two.');
        $this->assertNotSame(1, (int) $this->victimStore->id, 'PREMISE: the two stores are the same store.');

        $bytes = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFklEQVQImWM8ISfHwMDAxMDAwMDAAAANBAEIG3S2BgAAAABJRU5ErkJggg==');
        $temporary = (string) tempnam(sys_get_temp_dir(), 'ssrc');
        file_put_contents($temporary, $bytes);

        try {
            $this->actingAs(User::find($operator->id))->post("/ticket/{$this->victimTicket}/conversion", [
                'reply_description' => 'see the attachment',
                'reply_attachments' => [new \Illuminate\Http\UploadedFile($temporary, 'proof.png', null, null, true)],
            ]);
        } finally {
            @unlink($temporary);
        }

        $stored = \Illuminate\Support\Facades\Storage::disk('theme')->allFiles();

        $this->assertCount(1, $stored,
            'the reply attachment was not stored at all, so this case says nothing about the folder');
        $this->assertStringContainsString('/' . $this->victimStore->id . '/reply_tickets/', $stored[0],
            "the reply attachment was stored outside the ticket's own store folder: " . $stored[0]);
    }

    /** A staff member's own reply is filed under their own store, which is also the ticket's. */
    public function test_a_staff_members_reply_is_filed_under_the_tickets_store(): void
    {
        $own = $this->ticketFor((int) $this->ownStore->id);

        $staff = $this->makeStaff($this->owner, ['current_store' => $this->ownStore->id]);
        $this->grantPermission(User::find($staff->id), 'Replay Support Ticket');
        (new User)->flushCache();

        $this->actingAs(User::find($staff->id))->post("/ticket/{$own}/conversion", [
            'reply_description' => 'a real reply',
        ]);

        $row = DB::table('support_conversions')->where('ticket_id', $own)->first();

        $this->assertNotNull($row, 'the reply was not written at all');
        $this->assertSame((int) $this->ownStore->id, (int) $row->store_id);
    }
    /**
     * THE REFUSAL ITSELF ANSWERS A DENIAL, NOT AN HTTP 500 — and until this case it answered a 500.
     *
     * RAISED BY THE INDEPENDENT REVIEW OF THIS CHANGE, and it is the sharpest kind of finding: the
     * confinement above made `return view('403')` the NORMAL cross-tenant refusal, and there is no
     * `403.blade.php` anywhere in `resources/views` or in any theme — verified, one call site in the
     * whole repository. Laravel's own bare template lives in the `errors::` namespace, so
     * `view('403')` raises `InvalidArgumentException: View [403] not found` and the merchant gets a
     * server error where a denial belongs.
     *
     * WHY EVERY OTHER CASE IN THIS FILE IS BLIND TO IT: they assert that no conversation row was
     * written, and a 500 satisfies that perfectly. The tests were still valid — the open door really
     * did write the row, so they could fail — but "nothing was written" and "the refusal is
     * readable" are two different claims and only one of them was being made.
     *
     * Asserted as a redirect carrying the `error` flash, which is what the three already-confined
     * siblings answer. NOT a 401: this is a web route, and CLAUDE.md records that the shipped
     * Flutter app force-logs-out on any 401 — though that app does not read this route, answering
     * one here would still be the wrong shape for a panel screen.
     */
    public function test_the_cross_tenant_refusal_is_a_denial_and_not_a_server_error(): void
    {
        $staff = $this->unresolvedStaff();

        $response = $this->actingAs($staff)->post(
            route('conversion.store', $this->victimTicket),
            ['reply_description' => 'a reply that must be refused readably']
        );

        $this->assertNotSame(500, $response->status(),
            'the cross-tenant refusal is an HTTP 500. `view(\'403\')` names a template that exists '
            . 'nowhere in this repository, and the confinement above made that line the ordinary '
            . 'refusal path rather than a rarity. A merchant gets a server error where a denial '
            . 'belongs, and every other case here is blind to it because they assert only that no '
            . 'row was written — which a 500 satisfies.');

        $response->assertRedirect();

        $this->assertNotNull(session('error'),
            'the refusal carries no `error` flash, so the merchant is redirected with nothing to '
            . 'read. The three already-confined siblings all answer with one.');

        $this->assertSame(0, $this->repliesOn($this->victimTicket),
            'premise: the refusal still refused — this case is about the ANSWER, not the write, so '
            . 'it must not pass on a door that let the reply through with a redirect.');
    }

}
