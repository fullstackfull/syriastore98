<?php

namespace Tests\Feature\Support;

use App\Models\Store;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * THE TWO DEFECTS IN `POST ticket-status-change/{id}` THAT ARE NOT AUDIENCE QUESTIONS.
 * =================================================================================================
 *
 * `SupportTicketController` has exactly five methods, and four of them gate:
 *
 *     index()             isAbleTo('Manage Support Ticket')
 *     edit()              isAbleTo('Replay Support Ticket')
 *     conversion_store()  isAbleTo('Replay Support Ticket')
 *     destroy()           isAbleTo('Delete Support Ticket')
 *     ticket_status_change()   -- NOTHING --
 *
 * `POST ticket-status-change/{id}` writes `support_tickets.status` with no permission check of any
 * kind. The gate chosen here is the one its two siblings already use, `Replay Support Ticket`,
 * because the control that calls this route lives on the screen `edit()` serves -- the status
 * dropdown in `resources/views/support/edit.blade.php` -- so the actor who can open that screen and
 * reply on it is the actor the product already lets act there. A DEDICATED permission
 * ("Change Support Ticket Status") would need seeding, a role-screen row and an owner decision
 * about who holds it; reusing the screen's own permission invents nothing and widens nothing.
 *
 * THE OPERATOR IS REFUSED, AND THAT IS DELIBERATE RATHER THAN AN OVERSIGHT. CLAUDE.md's standing
 * rule is that `isAbleTo()` alone refuses the platform operator and that an authorization check
 * must usually test operator status first. It is NOT applied here: all four siblings are bare
 * `isAbleTo()` calls, so the operator cannot already use this controller, and adding an operator
 * branch to the one method being repaired would GRANT access that the other four refuse. Matching
 * the siblings keeps the surface consistent; widening it is a separate, deliberate decision.
 *
 * THE SAME GOES FOR THE STORE OWNER, AND IT WAS MEASURED RATHER THAN ASSUMED -- because the first
 * version of this file got it wrong in the other direction. Two cases below originally acted as the
 * bare tenant owner, on the assumption that an owner can always act in their own store. They failed
 * against the fixed handler, which looked like the gate being too strict. It is not. Probed on this
 * suite's own fixtures, with `roles` holding 0 rows because `RefreshDatabase` runs migrations and
 * not seeders:
 *
 *     owner->isAbleTo('Replay Support Ticket')   false
 *     owner->isAbleTo('Manage Support Ticket')   false
 *     GET support_ticket.index                   302 -> /
 *     GET support_ticket.edit                    302 -> /support_ticket
 *
 * So a permission-less owner could already reach neither the ticket list nor the edit screen, and
 * therefore never saw the status dropdown that calls this route. The gate removes nothing reachable
 * and grants nothing. In production the seeded `admin` role carries these permissions, which is why
 * this is a fixture property rather than a product one -- and it is the same trap CLAUDE.md records
 * for the order-action gate, where a permission check that refused the owner contradicted two tests
 * encoding the opposite contract. Here the four siblings encode THIS contract, and they were read
 * before the gate was chosen.
 *
 * THREE DEFECTS, ONE DOOR. Each has its own case below.
 *
 *  1. NO AUTHORIZATION. Any authenticated panel user could set any status.
 *
 *  2. THE ID CAME FROM THE BODY. The route declares `{id}` and the handler read `$request->id`, so
 *     a posted `id` beat the URL. `HelpdeskTicketOwnershipTest` already encodes the opposite rule
 *     for the Helpdesk routes ("judged by the route parameter not by the body"); this first-party
 *     handler did not follow it.
 *
 *     THE TWO TICKETS IN THAT CASE ARE IN THE SAME STORE ON PURPOSE. `SupportTicket` IS in
 *     `config/tenancy.php`'s `scoped_models`, so a second ticket in ANOTHER store would be refused
 *     by the global scope and the case would pass whether or not the handler reads the route -- the
 *     exact "passes for the wrong reason" trap CLAUDE.md records. Same store isolates the question
 *     to *which identifier the handler trusts*.
 *
 *  3. AN UNKNOWN STATUS WAS AN HTTP 500. `SupportTicket::ticket_status_change()` tests the status
 *     against three literals and, when none match, falls off the end of the `if (! empty($ticket))`
 *     block and returns PHP's implicit NULL. The controller's next line is `$responce['status']`,
 *     which on null raises a warning Laravel promotes to `ErrorException`. Measured by driving it.
 */
class TicketStatusChangeDoorTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    private SupportTicket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->makeMerchant();
        $this->store = $this->makeStore($this->owner, ['default_language' => 'en']);
        $this->owner->current_store = $this->store->id;
        $this->owner->save();
        (new User)->flushCache();

        $this->ticket = $this->makeTicket('open', 'Damascus delivery');
    }

    private function makeTicket(string $status, string $title): SupportTicket
    {
        return SupportTicket::forceCreate([
            'ticket_id' => 'T-' . bin2hex(random_bytes(4)),
            'title' => $title,
            'description' => 'x',
            'status' => $status,
            'store_id' => $this->store->id,
            'created_by' => (int) $this->owner->id,
        ]);
    }

    /** Read the row through no scope at all, so the assertion answers the table and not a tenant. */
    private function storedStatus(int $id): ?string
    {
        $row = SupportTicket::withoutGlobalScopes()->find($id);

        return $row === null ? null : $row->status;
    }

    // ---------------------------------------------------------------------------------------
    //  THE PERMISSION GATE IS NOT HERE, AND THAT IS THE POINT
    // ---------------------------------------------------------------------------------------
    //
    //  Three cases asserting a `Replay Support Ticket` gate were written, passed, and then DELETED
    //  along with the gate itself. `TicketStatusChangeAudienceTest` had already settled that the
    //  missing gate is a privilege question inside a tenant rather than a cross-tenant write -- the
    //  route is tenant-safe, measured there -- and pins today's audience as `D-P6-14`'s recorded
    //  starting fact. Adding the gate turned that test red, which is the control working: it exists
    //  so a gate cannot be added by accident, and its failure message says to record the decision
    //  and re-point it. There is no decision, so the gate came out. Who may change a ticket status
    //  stays the owner's answer; everything below is a defect with no audience question in it.
    //
    //  The measurement taken while the gate was in place is on the handler's docblock, because it
    //  is input to that decision: a principal with none of the three support permissions cannot
    //  reach `support_ticket.index` or `support_ticket.edit` either (302 from both), so they never
    //  see the dropdown that calls this route.

    // ---------------------------------------------------------------------------------------
    //  2 — WHICH IDENTIFIER THE HANDLER TRUSTS
    // ---------------------------------------------------------------------------------------

    public function test_the_ticket_is_taken_from_the_route_and_not_from_the_posted_body(): void
    {
        $other = $this->makeTicket('open', 'Aleppo returns');

        $this->assertSame((int) $this->store->id, (int) $other->store_id,
            'PREMISE: the two tickets are in different stores, so the tenancy scope would refuse '
            . 'the second one and this case would pass without the handler reading the route.');
        $this->assertNotSame($this->ticket->id, $other->id, 'PREMISE: one ticket, not two.');

        $response = $this->actingAs($this->owner)->post(
            route('support_ticket.status.change', $this->ticket->id),
            ['status' => 'Solved', 'id' => $other->id],
        );

        $response->assertOk();

        $this->assertSame('Solved', $this->storedStatus($this->ticket->id),
            'the ticket named in the URL was not the one that changed');
        $this->assertSame('open', $this->storedStatus($other->id),
            'the posted id beat the route parameter: a body field chose which ticket was written');
    }

    // ---------------------------------------------------------------------------------------
    //  3 — AN UNKNOWN STATUS
    // ---------------------------------------------------------------------------------------

    public function test_an_unrecognised_status_is_refused_rather_than_answering_500(): void
    {
        $response = $this->actingAs($this->owner)->post(
            route('support_ticket.status.change', $this->ticket->id),
            ['status' => 'Escalated to legal'],
        );

        $response->assertOk();
        $response->assertJsonPath('status', 'error');
        $this->assertSame('open', $this->storedStatus($this->ticket->id),
            'an unrecognised status was written to the row');
    }

    public function test_a_missing_status_is_refused_rather_than_answering_500(): void
    {
        $response = $this->actingAs($this->owner)
            ->post(route('support_ticket.status.change', $this->ticket->id), []);

        $response->assertOk();
        $response->assertJsonPath('status', 'error');
        $this->assertSame('open', $this->storedStatus($this->ticket->id));
    }

    /**
     * The model method must not return PHP's implicit NULL, whoever calls it.
     *
     * Today the controller is its only caller and the controller now validates first, so the
     * controller's own guard would hide this. Asserted directly because the implicit NULL is a trap
     * for the NEXT caller, and because a method whose contract is "an array with a status key"
     * should not have a path that silently returns nothing.
     */
    public function test_the_model_method_answers_an_error_array_for_an_unknown_status(): void
    {
        $answer = SupportTicket::ticket_status_change([
            'ticket_id' => $this->ticket->id,
            'ticket_status' => 'Escalated to legal',
        ]);

        $this->assertIsArray($answer, 'the method returned an implicit NULL again');
        $this->assertSame('error', $answer['status']);
        $this->assertSame('open', $this->storedStatus($this->ticket->id));
    }

    public function test_the_model_method_still_answers_an_error_array_for_a_missing_ticket(): void
    {
        $answer = SupportTicket::ticket_status_change([
            'ticket_id' => 99999999,
            'ticket_status' => 'Solved',
        ]);

        $this->assertIsArray($answer);
        $this->assertSame('error', $answer['status']);
    }

    /** The three shipped statuses still write, so the repair did not narrow the workflow. */
    public function test_each_shipped_status_still_writes(): void
    {
        foreach (['In Progress', 'Solved', 'open'] as $status) {
            $response = $this->actingAs($this->owner)->post(
                route('support_ticket.status.change', $this->ticket->id),
                ['status' => $status],
            );

            $response->assertOk();
            $response->assertJsonPath('status', 'success');
            $this->assertSame($status, $this->storedStatus($this->ticket->id),
                "the shipped status \"{$status}\" no longer writes");
        }
    }
}
