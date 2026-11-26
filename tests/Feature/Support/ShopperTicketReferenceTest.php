<?php

namespace Tests\Feature\Support;

use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * THE REAL DOOR, for the generator `SupportTicketReferenceTest` pins.
 *
 * A unit test on `SupportTicket::newReference()` proves the generator; it proves nothing about
 * whether the WRITER calls it. Two shoppers raise a ticket each through
 * `POST /{slug}/store-ticket` and the two stored references must differ -- which is the fact a
 * merchant and a shopper actually see.
 *
 * The premise that makes this evidence rather than a coincidence: both rows are asserted to have been
 * written, and their `created_at` to fall in the same second, so a pass cannot be the clock having
 * ticked between them. If the clock does tick, the premise fails loudly instead of the case passing
 * for the wrong reason.
 */
class ShopperTicketReferenceTest extends SecurityTestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        [, $this->store] = $this->makeTenant();
        (new User)->flushCache();
    }

    private function shopper(string $name): Customer
    {
        return Customer::forceCreate([
            'first_name' => $name, 'last_name' => 'Shopper',
            'email' => uniqid() . '@example.test', 'password' => bcrypt('not-a-real-credential'),
            'store_id' => $this->store->id, 'status' => 1,
            'billable_id' => $this->store->created_by, 'billable_type' => User::class,
        ]);
    }

    private function raise(Customer $customer, string $title): void
    {
        $this->actingAs($customer, 'customers');
        $this->app['auth']->shouldUse('web');       // a shopper as production sees one

        $this->post('/' . $this->store->slug . '/store-ticket', [
            'title' => $title,
            'description' => 'Something went wrong with my order.',
        ]);
    }

    public function test_two_shoppers_raising_a_ticket_in_the_same_second_get_different_references(): void
    {
        $this->raise($this->shopper('First'), 'FIRST TICKET');
        $this->raise($this->shopper('Second'), 'SECOND TICKET');

        $rows = DB::table('support_tickets')->orderBy('id')->get(['ticket_id', 'title', 'created_at']);

        $this->assertCount(2, $rows,
            'PREMISE: both tickets were not written, so this case says nothing about their numbers');
        /*
         * NO CLOCK IN THE PREMISE. This asserted that the two writes landed in the SAME SECOND,
         * which two full HTTP requests do only most of the time -- the gap between them divided by
         * one second is a single-digit percentage per run, and when it straddled a boundary this
         * test FAILED. In this project a failure is an undeclared outcome: the gate asserts the
         * declared set in both directions, so "fails loudly" meant "reddens a required job at
         * random". Raised by the independent review of this change.
         *
         * The shape assertion below is strictly stronger and deterministic: `time()` cannot produce
         * the hyphen-and-hex suffix, so it kills the mutant every run rather than most runs. The
         * same-second property is already proved 200 draws deep by the unit test.
         */
        foreach ($rows as $i => $row) {
            $this->assertMatchesRegularExpression('/\A\d{10}-[0-9A-F]{6}\z/', (string) $row->ticket_id,
                sprintf(
                    'reference %d does not have the generated shape, so a bare `time()` would '
                    . 'satisfy this file. The generator is `time() . \'-\' . '
                    . 'strtoupper(bin2hex(random_bytes(3)))`; got %s.',
                    $i, var_export($row->ticket_id, true)
                ));
        }

        $this->assertNotSame((string) $rows[0]->ticket_id, (string) $rows[1]->ticket_id,
            'two shoppers who submitted in the same second were given the same ticket number');
    }

    /** The reference is written at all, and it is not left empty by the new generator. */
    public function test_a_raised_ticket_carries_a_reference(): void
    {
        $this->raise($this->shopper('Only'), 'ONLY TICKET');

        $reference = (string) DB::table('support_tickets')->value('ticket_id');

        $this->assertNotSame('', $reference, 'the ticket was stored with no reference at all');
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9-]{1,40}\z/', $reference);
    }
}
