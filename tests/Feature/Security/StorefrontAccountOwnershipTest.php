<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\DeliveryAddress;
use App\Models\DeliveryBoy;
use App\Models\Store;
use App\Models\SupportTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * P1-SEC-12 -- the storefront account area had a login gate and no ownership check.
 * =============================================================================================
 *
 * `EnsureCustomerAuthenticated` asks only whether SOME customer is signed in, and registration is free. Behind it, AccountProfileController
 * deleted, rendered, overwrote and re-parented addresses and support tickets by a bare id from the request. `SupportTicket` is in
 * `scoped_models`, but the scope adds no predicate for a `customers`-guard principal (null tenant). Executed in the ledger: a store-A shopper
 * deleted a store-B shopper's address (rows_left=0), read their street through the address form, deleted their ticket.
 *
 * Every handler now derives the row from the signed-in shopper: this id AND this customer (and this store where the row records one), and
 * refuses anything else uniformly -- a 404/redirect-with-error, never a 401 (the shipped clients log the user out on a 401). A ticket may name
 * only one of the shopper's own orders in this store. Each negative is driven for a shopper of ANOTHER store and for a SIBLING in the same
 * store, and for a merchant and a driver principal, because a customer-only probe cannot see the guard-class trap.
 */
class StorefrontAccountOwnershipTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function shopperIn(Store $store, string $name): Customer
    {
        return Customer::forceCreate([
            'first_name' => $name, 'last_name' => 'Shopper', 'email' => strtolower($name) . uniqid() . '@example.test',
            'password' => bcrypt('not-a-real-credential'), 'store_id' => $store->id, 'status' => 1,
            'billable_id' => $store->created_by, 'billable_type' => \App\Models\User::class,
        ]);
    }

    private function addressOf(Customer $customer, ?int $storeId = null): DeliveryAddress
    {
        return DeliveryAddress::forceCreate(['customer_id' => $customer->id, 'full_name' => 'Owner ' . $customer->first_name, 'phone' => '0000000', 'title' => 'home',
            'address' => '1 ' . $customer->first_name . ' Street', 'postcode' => 12345, 'store_id' => $storeId]);
    }

    private function ticketOf(Customer $customer, Store $store, array $extra = []): SupportTicket
    {
        return SupportTicket::forceCreate(array_merge(['ticket_id' => 'T-' . uniqid(), 'title' => 'Ticket of ' . $customer->first_name, 'description' => 'private text',
            'customer_id' => $customer->id, 'status' => 'Open', 'store_id' => $store->id, 'created_by' => $customer->id], $extra));
    }

    /** @return array{0: Store, 1: Store, 2: Customer, 3: Customer, 4: Customer} store A, store B, the attacker (A), a sibling (A), a stranger (B) */
    private function world(): array
    {
        // delivery_addresses has foreign keys to these (country 1 / state 1 / city 1 are what the posted forms name)
        DB::table('countries')->insert(['id' => 1, 'name' => 'Land', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('states')->insert(['id' => 1, 'name' => 'State', 'country_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cities')->insert(['id' => 1, 'name' => 'City', 'country_id' => 1, 'state_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        return [$storeA, $storeB, $this->shopperIn($storeA, 'Mallory'), $this->shopperIn($storeA, 'Sibling'), $this->shopperIn($storeB, 'Stranger')];
    }

    /** the victims every negative is driven against: a sibling in the same store and a shopper of another store */
    private function victims(Store $storeA, Customer $sibling, Store $storeB, Customer $stranger): array
    {
        return ['sibling' => [$sibling, $storeA], 'stranger' => [$stranger, $storeB]];
    }

    // ---------------------------------------------------------------------------------------------
    // addresses
    // ---------------------------------------------------------------------------------------------

    public function test_a_shopper_cannot_delete_another_shoppers_address_by_post_or_by_get(): void
    {
        [$a, $b, $mallory, $sibling, $stranger] = $this->world();

        foreach ($this->victims($a, $sibling, $b, $stranger) as $who => [$victim, $victimStore]) {
            $address = $this->addressOf($victim, $victimStore->id);

            $post = $this->actingAs($mallory, 'customers')->post("/{$a->slug}/remove-address", ['id' => $address->id]);
            $this->actingAs($mallory, 'customers')->get("/{$a->slug}/delete-addressbook?id=" . $address->id);

            $this->assertDatabaseHas('delivery_addresses', ['id' => $address->id]);
            $this->assertSame(404, $post->status(), "{$who}: the refusal is a uniform 404 (never a 401)");
        }
    }

    public function test_a_shopper_cannot_read_another_shoppers_address_through_the_address_form(): void
    {
        [$a, $b, $mallory, $sibling, $stranger] = $this->world();

        foreach ($this->victims($a, $sibling, $b, $stranger) as $who => [$victim, $victimStore]) {
            $address = $this->addressOf($victim, $victimStore->id);

            $response = $this->actingAs($mallory, 'customers')->getJson("/{$a->slug}/adress-form/{$address->id}");

            $this->assertSame(404, $response->status(), $who);
            $this->assertStringNotContainsString($victim->first_name . ' Street', (string) $response->getContent(), "{$who}'s street leaked");
        }
    }

    /**
     * The SECOND address-read handler, and the one the live UI actually calls.
     *
     * `adress-form` (tested above) and `edit-address-form` are two different routes on two different
     * methods -- `addressForm` and `edit_address_form`. The original P1-SEC-12 sweep fixed six
     * handlers and `ApiController::update_address`, and left this one untouched, because it keyed on
     * the handler NAMES somebody had already listed rather than on the routed actions. This is
     * census-failure mode 4 from the project skill: "an exemption scoped to the methods somebody
     * happened to look at".
     *
     * It is not a dormant surface. `front_end/sections/addressbook.blade.php` links the Edit control
     * on every row of the shipped address book to `route('edit.address.form')`, so this is the read
     * path a signed-in shopper exercises normally, and it rendered any address row on the platform
     * by bare id: no customer predicate, no store predicate, and `DeliveryAddress` carries no global
     * scope and is absent from `config/tenancy.php`'s `scoped_models`, so nothing else supplied one.
     */
    public function test_a_shopper_cannot_read_another_shoppers_address_through_the_edit_address_form(): void
    {
        [$a, $b, $mallory, $sibling, $stranger] = $this->world();

        foreach ($this->victims($a, $sibling, $b, $stranger) as $who => [$victim, $victimStore]) {
            $address = $this->addressOf($victim, $victimStore->id);

            $response = $this->actingAs($mallory, 'customers')->get("/{$a->slug}/edit-address-form?id={$address->id}");

            // premise: the route exists and the guard let this shopper through -- a 404 here would
            // satisfy the leak assertion for the wrong reason (census-failure mode 2).
            $this->assertNotSame(404, $response->status(), "{$who}: the route is missing, so this test proves nothing");

            $this->assertStringNotContainsString($victim->first_name . ' Street', (string) $response->getContent(), "{$who}'s street leaked");
            $this->assertStringNotContainsString('Owner ' . $victim->first_name, (string) $response->getContent(), "{$who}'s name leaked");
        }
    }

    /** The positive control for the same route: the fix must not break the shipped address book for its owner. */
    public function test_a_shopper_can_still_read_their_own_address_through_the_edit_address_form(): void
    {
        [$a, , $mallory] = $this->world();
        $own = $this->addressOf($mallory, $a->id);

        $response = $this->actingAs($mallory, 'customers')->get("/{$a->slug}/edit-address-form?id={$own->id}");

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('1 Mallory Street', (string) $response->getContent(), 'the owner can no longer see their own address');
    }

    /**
     * The guard-class trap on this route. `EnsureCustomerAuthenticated` is a `customers`-guard check,
     * so a merchant session does not reach the handler -- but the handler's own `auth('customers')`
     * presence test is what the fix replaces, and a presence test is what the whole finding is about.
     * Driving a non-customer principal records that the refusal does not depend on which limb fires.
     */
    public function test_a_merchant_session_cannot_read_an_address_through_the_edit_address_form(): void
    {
        [$a, , $mallory] = $this->world();
        $own = $this->addressOf($mallory, $a->id);
        $merchant = \App\Models\User::find($a->created_by);

        $response = $this->actingAs($merchant, 'web')->get("/{$a->slug}/edit-address-form?id={$own->id}");

        $this->assertStringNotContainsString('1 Mallory Street', (string) $response->getContent(), 'a merchant session read a shopper address');
    }

    public function test_a_shopper_cannot_overwrite_or_reparent_another_shoppers_address(): void
    {
        [$a, $b, $mallory, $sibling, $stranger] = $this->world();

        foreach ($this->victims($a, $sibling, $b, $stranger) as $who => [$victim, $victimStore]) {
            $address = $this->addressOf($victim, $victimStore->id);

            $response = $this->actingAs($mallory, 'customers')->postJson("/{$a->slug}/save-address", [
                'id' => $address->id, 'customer_id' => $mallory->id, 'store_id' => $a->id, 'address_type' => 'home', 'full_name' => 'OVERWRITTEN', 'phone' => '1',
                'address' => 'OVERWRITTEN', 'city_id' => 1, 'state_id' => 1, 'postcode' => 1, 'country_id' => 1,
            ]);

            $row = DB::table('delivery_addresses')->where('id', $address->id)->first();
            $this->assertSame('Owner ' . $victim->first_name, $row->full_name, "{$who}: the address was overwritten");
            $this->assertSame((int) $victim->id, (int) $row->customer_id, "{$who}: the address was re-parented");
            $this->assertFalse($response->json('status'), $who);
        }
    }

    public function test_a_new_address_cannot_be_planted_on_another_customer_or_store_by_the_post(): void
    {
        [$a, $b, $mallory, , $stranger] = $this->world();

        $this->actingAs($mallory, 'customers')->postJson("/{$a->slug}/save-address", [
            'customer_id' => $stranger->id, 'store_id' => $b->id, 'address_type' => 'home', 'full_name' => 'Planted', 'phone' => '1', 'address' => 'x',
            'city_id' => 1, 'state_id' => 1, 'postcode' => 1, 'country_id' => 1,
        ]);

        $row = DB::table('delivery_addresses')->where('full_name', 'Planted')->first();
        $this->assertNotNull($row, 'Premise: the shopper\'s own address must be created.');
        $this->assertSame((int) $mallory->id, (int) $row->customer_id, 'The owner of a new address came from the post.');
        $this->assertSame((int) $a->id, (int) $row->store_id, 'The store of a new address came from the post.');
    }

    public function test_a_shopper_still_manages_their_own_addresses_including_legacy_rows_with_no_store(): void
    {
        [$a, , $mallory] = $this->world();
        $own = $this->addressOf($mallory, null);                    // a legacy row: no store_id recorded
        $withStore = $this->addressOf($mallory, $a->id);

        $form = $this->actingAs($mallory, 'customers')->getJson("/{$a->slug}/adress-form/{$withStore->id}");
        $this->assertSame(200, $form->status());
        $this->assertStringContainsString('Mallory Street', (string) $form->json('html'));

        $save = $this->actingAs($mallory, 'customers')->postJson("/{$a->slug}/save-address", [
            'id' => $own->id, 'address_type' => 'home', 'full_name' => 'Renamed', 'phone' => '1', 'address' => 'new street', 'city_id' => 1, 'state_id' => 1,
            'postcode' => 1, 'country_id' => 1,
        ]);
        $this->assertTrue($save->json('status'), (string) $save->getContent());
        $this->assertSame('Renamed', DB::table('delivery_addresses')->where('id', $own->id)->value('full_name'));

        $this->actingAs($mallory, 'customers')->post("/{$a->slug}/remove-address", ['id' => $withStore->id]);
        $this->assertDatabaseMissing('delivery_addresses', ['id' => $withStore->id]);
    }

    /** the row must also belong to THIS store, and the session must be this store's shopper: a customer's own rows are not reachable through another store's slug */
    public function test_a_shoppers_own_rows_are_not_reachable_through_another_stores_slug_or_with_a_foreign_store_recorded(): void
    {
        [$a, $b, $mallory] = $this->world();
        $own = $this->addressOf($mallory, $a->id);
        $inconsistent = $this->addressOf($mallory, $b->id);   // customer_id is hers, store_id is another store's

        $this->actingAs($mallory, 'customers')->post("/{$b->slug}/remove-address", ['id' => $own->id]);
        $this->assertDatabaseHas('delivery_addresses', ['id' => $own->id]);

        $this->actingAs($mallory, 'customers')->post("/{$a->slug}/remove-address", ['id' => $inconsistent->id]);
        $this->assertDatabaseHas('delivery_addresses', ['id' => $inconsistent->id]);

        // a LEGACY row (no store recorded) is hers in any store the row predicate looks at, so the session check must be what refuses another store's slug
        $legacy = $this->addressOf($mallory, null);
        $this->actingAs($mallory, 'customers')->post("/{$b->slug}/remove-address", ['id' => $legacy->id]);
        $this->assertDatabaseHas('delivery_addresses', ['id' => $legacy->id]);

        $before = DB::table('delivery_addresses')->count();
        $save = $this->actingAs($mallory, 'customers')->postJson("/{$b->slug}/save-address", ['address_type' => 'home', 'full_name' => 'X-store', 'phone' => '1',
            'address' => 'x', 'city_id' => 1, 'state_id' => 1, 'postcode' => 1, 'country_id' => 1]);
        $this->assertSame(403, $save->status(), 'A shopper of store A saved an address through store B\'s storefront.');
        $this->assertSame($before, DB::table('delivery_addresses')->count());
    }

    public function test_a_ticket_recorded_against_another_store_is_not_reachable_even_when_the_customer_id_matches(): void
    {
        [$a, $b, $mallory] = $this->world();
        $ticket = $this->ticketOf($mallory, $b);            // customer_id is hers, store_id is store B's

        $this->actingAs($mallory, 'customers')->get("/{$a->slug}/destroy-ticket/{$ticket->id}");

        $this->assertDatabaseHas('support_tickets', ['id' => $ticket->id]);
    }

    // ---------------------------------------------------------------------------------------------
    // support tickets
    // ---------------------------------------------------------------------------------------------

    public function test_a_shopper_cannot_delete_read_reply_to_or_edit_another_shoppers_ticket(): void
    {
        [$a, $b, $mallory, $sibling, $stranger] = $this->world();

        foreach ($this->victims($a, $sibling, $b, $stranger) as $who => [$victim, $victimStore]) {
            $ticket = $this->ticketOf($victim, $victimStore);

            $this->actingAs($mallory, 'customers')->get("/{$a->slug}/destroy-ticket/{$ticket->id}");
            $this->assertDatabaseHas('support_tickets', ['id' => $ticket->id]);

            $edit = $this->actingAs($mallory, 'customers')->getJson("/{$a->slug}/get-support-ticket/{$ticket->id}");
            $this->assertSame(404, $edit->status(), $who);
            $this->assertStringNotContainsString('private text', (string) $edit->getContent());

            $reply = $this->actingAs($mallory, 'customers')->getJson("/{$a->slug}/reply-support-ticket/{$ticket->id}");
            $this->assertStringNotContainsString('private text', (string) $reply->getContent());

            $this->actingAs($mallory, 'customers')->post("/{$a->slug}/update-support-ticket/{$ticket->id}", ['title' => 'stolen', 'description' => 'stolen', 'customer_id' => $mallory->id]);
            $row = DB::table('support_tickets')->where('id', $ticket->id)->first();
            $this->assertSame('Ticket of ' . $victim->first_name, $row->title, "{$who}: the ticket was edited");
            $this->assertSame((int) $victim->id, (int) $row->customer_id, "{$who}: the ticket was re-parented");

            $this->actingAs($mallory, 'customers')->post("/{$a->slug}/support-ticket/{$ticket->id}", ['reply_description' => 'injected reply']);
            $this->assertSame(0, DB::table('support_conversions')->where('ticket_id', $ticket->id)->count(), "{$who}: a reply was added to the ticket");
        }
    }

    public function test_a_shopper_cannot_delete_another_shoppers_ticket_attachment(): void
    {
        [$a, $b, $mallory, $sibling, $stranger] = $this->world();

        foreach ($this->victims($a, $sibling, $b, $stranger) as $who => [$victim, $victimStore]) {
            $ticket = $this->ticketOf($victim, $victimStore, ['attachment' => json_encode(['uploads/x/tickets/a.png', 'uploads/x/tickets/b.png'])]);

            $this->actingAs($mallory, 'customers')->delete("/{$a->slug}/ticket-attachment/{$ticket->id}/destroy/0");

            $this->assertSame(['uploads/x/tickets/a.png', 'uploads/x/tickets/b.png'], json_decode(DB::table('support_tickets')->where('id', $ticket->id)->value('attachment'), true), $who);
        }
    }

    public function test_a_ticket_may_name_only_one_of_the_shoppers_own_orders(): void
    {
        [$a, $b, $mallory, , $stranger] = $this->world();
        $theirs = $this->makeOrder($b, ['customer_id' => $stranger->id, 'is_guest' => 0]);
        $mine = $this->makeOrder($a, ['customer_id' => $mallory->id, 'is_guest' => 0]);

        $this->actingAs($mallory, 'customers')->post("/{$a->slug}/store-ticket", ['title' => 'about their order', 'description' => 'd', 'order_id' => $theirs->id]);
        $this->assertSame(0, SupportTicket::where('title', 'about their order')->count(), 'A ticket was created naming another shopper\'s order.');

        $this->actingAs($mallory, 'customers')->post("/{$a->slug}/store-ticket", ['title' => 'about my order', 'description' => 'd', 'order_id' => $mine->id]);
        $this->assertSame(1, SupportTicket::where('title', 'about my order')->where('customer_id', $mallory->id)->where('store_id', $a->id)->count(), 'Premise: a ticket about the shopper\'s own order must be created.');

        $this->actingAs($mallory, 'customers')->post("/{$a->slug}/store-ticket", ['title' => 'no order', 'description' => 'd']);
        $this->assertSame(1, SupportTicket::where('title', 'no order')->count(), 'A ticket with no order must still be created.');
    }

    public function test_a_ticket_cannot_be_pointed_at_a_foreign_order_by_an_edit(): void
    {
        [$a, $b, $mallory, , $stranger] = $this->world();
        $theirs = $this->makeOrder($b, ['customer_id' => $stranger->id, 'is_guest' => 0]);
        $ticket = $this->ticketOf($mallory, $a);

        $this->actingAs($mallory, 'customers')->post("/{$a->slug}/update-support-ticket/{$ticket->id}", ['title' => 'retitled', 'description' => 'd', 'order_id' => $theirs->id]);

        $this->assertNotSame((string) $theirs->id, (string) DB::table('support_tickets')->where('id', $ticket->id)->value('order_id'));
    }

    public function test_a_shopper_still_manages_their_own_tickets(): void
    {
        [$a, , $mallory] = $this->world();
        $ticket = $this->ticketOf($mallory, $a, ['attachment' => json_encode(['uploads/x/tickets/a.png', 'uploads/x/tickets/b.png'])]);

        $edit = $this->actingAs($mallory, 'customers')->getJson("/{$a->slug}/get-support-ticket/{$ticket->id}");
        $this->assertSame(200, $edit->status());
        $this->assertStringContainsString('Ticket of Mallory', (string) $edit->json('html'));

        $reply = $this->actingAs($mallory, 'customers')->getJson("/{$a->slug}/reply-support-ticket/{$ticket->id}");
        $this->assertSame(200, $reply->status());

        $this->actingAs($mallory, 'customers')->post("/{$a->slug}/support-ticket/{$ticket->id}", ['reply_description' => 'my own reply']);
        $this->assertSame(1, DB::table('support_conversions')->where('ticket_id', $ticket->id)->count(), 'Premise: the owner\'s reply must be stored.');

        $this->actingAs($mallory, 'customers')->post("/{$a->slug}/update-support-ticket/{$ticket->id}", ['title' => 'My new title', 'description' => 'd']);
        $this->assertSame('My new title', DB::table('support_tickets')->where('id', $ticket->id)->value('title'));
        $this->assertSame((int) $mallory->id, (int) DB::table('support_tickets')->where('id', $ticket->id)->value('customer_id'));
        $this->assertSame(['uploads/x/tickets/a.png', 'uploads/x/tickets/b.png'], json_decode(DB::table('support_tickets')->where('id', $ticket->id)->value('attachment'), true),
            'Editing a ticket wiped its attachments.');

        $this->actingAs($mallory, 'customers')->delete("/{$a->slug}/ticket-attachment/{$ticket->id}/destroy/0");
        $this->assertSame(['uploads/x/tickets/b.png'], json_decode(DB::table('support_tickets')->where('id', $ticket->id)->value('attachment'), true), 'Premise: the owner can still remove an attachment.');

        $this->actingAs($mallory, 'customers')->get("/{$a->slug}/destroy-ticket/{$ticket->id}");
        $this->assertDatabaseMissing('support_tickets', ['id' => $ticket->id]);
    }

    // ---------------------------------------------------------------------------------------------
    // the other principals: a merchant session and a driver session are not a shopper
    // ---------------------------------------------------------------------------------------------

    public function test_a_merchant_or_driver_session_cannot_reach_a_shoppers_address_or_ticket(): void
    {
        [$a, , , $sibling] = $this->world();
        [$merchant] = $this->makeTenant();
        $driver = DeliveryBoy::forceCreate(['name' => 'Driver', 'email' => 'driver' . uniqid() . '@example.test', 'password' => bcrypt('x'), 'contact' => '0', 'created_by' => $a->created_by, 'store_id' => $a->id]);
        $address = $this->addressOf($sibling, $a->id);
        $ticket = $this->ticketOf($sibling, $a, ['attachment' => json_encode(['uploads/x/tickets/a.png'])]);

        foreach ([['web', $merchant], ['deliveryboy', $driver]] as [$guard, $principal]) {
            $this->actingAs($principal, $guard)->post("/{$a->slug}/remove-address", ['id' => $address->id]);
            $this->actingAs($principal, $guard)->get("/{$a->slug}/delete-addressbook?id=" . $address->id);
            $this->actingAs($principal, $guard)->get("/{$a->slug}/destroy-ticket/{$ticket->id}");
            $this->actingAs($principal, $guard)->delete("/{$a->slug}/ticket-attachment/{$ticket->id}/destroy/0");
            $this->actingAs($principal, $guard)->post("/{$a->slug}/support-ticket/{$ticket->id}", ['reply_description' => 'x']);

            $this->assertDatabaseHas('delivery_addresses', ['id' => $address->id]);
            $this->assertDatabaseHas('support_tickets', ['id' => $ticket->id]);
            $this->assertSame(0, DB::table('support_conversions')->where('ticket_id', $ticket->id)->count(), "{$guard}: a reply was added");
            $this->assertSame(['uploads/x/tickets/a.png'], json_decode(DB::table('support_tickets')->where('id', $ticket->id)->value('attachment'), true), "{$guard}: an attachment was removed");
        }
    }

    /**
     * the census: no handler of this controller reads or deletes an address or a ticket by a bare request id any more.
     *
     * WHY THIS LIST GREW. As first written it forbade five `DeliveryAddress::where('id', ...)` spellings
     * and `SupportTicket::find(` -- and not `DeliveryAddress::find(`, which is what the one handler the
     * sweep missed actually used. So the ratchet was green over a live cross-tenant read for as long as
     * that handler existed. The lesson is the project skill's: a census keyed on the spellings somebody
     * happened to write down is not a census. It now forbids the bare-id CONSTRUCTOR on both models and
     * asserts its own reachability, so a rename of either model cannot make it pass vacuously.
     *
     * Comments are stripped before the grep. The docblocks in that controller now quote
     * `DeliveryAddress::find($request->id)` to explain the finding, and a ratchet that a sentence of
     * prose can fail -- or satisfy -- is measuring the wrong file.
     */
    public function test_no_address_or_ticket_handler_resolves_a_row_by_a_bare_id(): void
    {
        $raw = (string) file_get_contents(app_path('Http/Controllers/AccountProfileController.php'));

        $source = '';
        foreach (token_get_all($raw) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $source .= is_array($token) ? $token[1] : $token;
        }

        // reachability: if the live code no longer mentions either model, every assertion below is vacuous
        $this->assertStringContainsString('DeliveryAddress::', $source, 'the comment-stripped source no longer names DeliveryAddress -- this census is vacuous');
        $this->assertStringContainsString('SupportTicket::', $source, 'the comment-stripped source no longer names SupportTicket -- this census is vacuous');

        foreach (['SupportTicket::find(', 'DeliveryAddress::find(', "SupportTicket::where('id', \$id)->delete", "SupportTicket::where('id', '=', \$id)",
                  "DeliveryAddress::where('id', \$request->id)", "DeliveryAddress::where('id',\$request->get('id'))", "DeliveryAddress::where('id', \$id)->first()"] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, 'A handler resolves a row by a bare id: ' . $forbidden);
        }
        $this->assertSame(12, preg_match_all('/\$this->owned(?:Address|Ticket)\(/', $source), 'The number of ownership-checked handlers changed.');
    }
}
