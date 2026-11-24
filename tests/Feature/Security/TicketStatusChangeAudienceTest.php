<?php

namespace Tests\Feature\Security;

use App\Models\SupportTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * `POST /ticket-status-change/{id}` IS TENANT-SAFE AND HAS NO PERMISSION GATE. Both measured.
 * =================================================================================================
 *
 * WHY THIS FILE EXISTS. A Phase 06 support inventory listed "ticket_status_change has no permission
 * gate" among the questions for the owner (`D-P6-14`). Before that sentence could be published it
 * had to be settled whether the missing gate is a privilege question INSIDE a tenant or a
 * cross-tenant write, because those are a question and a defect respectively, and the second must
 * not be described in a public repository before it is fixed.
 *
 * **It is the first.** The route carries `web`, `Authenticate`, `XSS`, `SetLocate` and
 * `EmailIsVerified` and no permission check -- read from the router -- and the handler passes
 * `$request->id` to `SupportTicket::ticket_status_change()`, which does a bare
 * `SupportTicket::find($ticket_id)`. That looked like the shape CLAUDE.md warns about. But
 * `SupportTicket` is one of the ELEVEN models in `config/tenancy.php`'s `scoped_models` AND it
 * registers `StoreScope`, so the find really does filter: a merchant acting on another tenant's
 * ticket id is told "Ticket not found." and the row does not move. Measured, below.
 *
 * SO THE OPEN QUESTION IS NARROW, and the test says what it is: within one tenant, a principal who
 * holds none of the three support permissions can still change a ticket's status. Whether that is
 * the intended audience is the owner's call -- adding a gate is a behaviour REDUCTION for whoever
 * relies on it, and this project has already had one such "fix" refused because the gate turned
 * away the store owner that two tests encoded as the shipped contract.
 *
 * THIS TEST TAKES NO SIDE ON THAT. It pins the tenant boundary, which is not in question, and it
 * pins today's audience as a MEASUREMENT rather than as an approval, so that an answer either way
 * starts from a recorded fact.
 */
class TicketStatusChangeAudienceTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function ticketFor(int $storeId, string $status = 'open'): int
    {
        return (int) DB::table('support_tickets')->insertGetId([
            'store_id' => $storeId,
            'ticket_id' => 'T-' . $this->uniqueFixtureRef(),
            'title' => 'A question',
            'description' => 'text',
            'status' => $status,
            'created_by' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_merchant_cannot_change_another_tenants_ticket_status(): void
    {
        [$attacker] = $this->makeTenant();
        [$victim, $victimStore] = $this->makeTenant();

        $ticketId = $this->ticketFor($victimStore->id, 'open');

        $this->assertSame('open', (string) DB::table('support_tickets')->where('id', $ticketId)->value('status'),
            'PREMISE: the fixture ticket is not in the state this probe starts from.');

        $response = $this->actingAs($attacker)
            ->post("/ticket-status-change/{$ticketId}", ['status' => 'In Progress']);

        $after = (string) DB::table('support_tickets')->where('id', $ticketId)->value('status');

        $this->assertSame('open', $after,
            'CROSS-TENANT WRITE: a merchant changed another tenant\'s support-ticket status. The '
            . 'route has no permission gate, so StoreScope on SupportTicket is the only thing '
            . 'standing here -- if this fails, the model left config/tenancy.php\'s scoped_models '
            . 'or stopped registering the scope.');

        $this->assertStringContainsString('Ticket not found', (string) $response->getContent(),
            'The refusal no longer comes from the scoped lookup, so this test may be passing for a '
            . 'different reason than the one it documents.');
    }

    /**
     * TODAY'S AUDIENCE, RECORDED AND NOT ENDORSED. `D-P6-14` is the owner question this measures.
     */
    public function test_a_same_tenant_principal_without_any_support_permission_can_still_change_a_status(): void
    {
        [$owner, $store] = $this->makeTenant();

        $staff = $this->makeStaff($owner, ['current_store' => $store->id]);

        foreach (['Manage Support Ticket', 'Replay Support Ticket', 'Delete Support Ticket'] as $permission) {
            $this->assertFalse($staff->isAbleTo($permission),
                "PREMISE: the staff fixture holds '{$permission}', so this case no longer measures a "
                . 'principal without the support permissions.');
        }

        $ticketId = $this->ticketFor($store->id, 'open');

        $this->actingAs($staff)->post("/ticket-status-change/{$ticketId}", ['status' => 'In Progress']);

        $this->assertSame(
            'In Progress',
            (string) DB::table('support_tickets')->where('id', $ticketId)->value('status'),
            'A principal holding none of the three support permissions can no longer change a '
            . "ticket's status. That may be the RIGHT behaviour -- but it is `D-P6-14`'s answer, and "
            . 'this test records the state the question was asked about. If a gate was added '
            . 'deliberately, record the decision and re-point this test at the new contract.'
        );
    }
}
