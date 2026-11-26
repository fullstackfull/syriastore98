<?php

namespace Tests\Feature\Support;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Store;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * THE SHOPPER'S TICKET LIST AND THE TICKET FORM'S ORDER PICKER WERE THE TWO READS NOBODY BOUNDED.
 * =================================================================================================
 *
 * P1-SEC-12 gave every storefront ticket WRITER an ownership predicate -- `ownedTicket()` is
 * `withoutGlobalScopes()` + this id + this customer + (this store OR a NULL store) with the store
 * taken from the ROUTE slug -- and `ownedOrder()` the same for orders. Two pure reads were left on
 * `customer_id` alone:
 *
 *     support_ticket()        SupportTicket::where('customer_id', auth('customers')->user()->id)
 *     add_support_ticket()    Order::where('customer_id', ...)->where('is_guest', 0)
 *     edit_support_ticket()   the same order query again
 *
 * `StoreScope` adds NOTHING on a storefront request (`MODE_UNRESOLVED` -> `null_tenant => 'open'`),
 * and `Order` is not in `scoped_models` at all, so neither read had any store predicate from any
 * source. Two structural consequences, both recorded in the Phase 06 inventory as measured-by-
 * reading and neither previously driven:
 *
 *   (i)  the `customers` guard is ONE session across every storefront, so a shopper opening store
 *        B's support page was served their own store-A ticket titles and order numbers inside store
 *        B's chrome; and
 *   (ii) `customers.store_id` is NULLABLE and `shopper()` admits a NULL-store customer at EVERY
 *        store, so such a shopper's list genuinely spanned the stores they had raised tickets in.
 *
 * It is not a cross-CUSTOMER leak -- the `customer_id` predicate holds -- which is why it is a
 * defect about the DESK rather than a disclosure: the list offered rows and the picker offered
 * orders that the detail, reply, edit and delete doors beside them all refuse, so the screen
 * disagreed with every handler it links to. `test_the_list_offers_no_row_the_detail_door_refuses`
 * is the case that states that as one property.
 *
 * WHAT IS DELIBERATELY NOT CHANGED. A NULL `store_id` on the TICKET is still listed at any store, as
 * `ownedTicket()` admits it, and a NULL `store_id` on the CUSTOMER still passes `shopper()`:
 * P1-SEC-71 (f, 5) records that requiring a non-null customer store "is a behaviour change for
 * legacy rows", and this is a read, not the place to make it.
 */
class ShopperTicketDeskStoreBoundaryTest extends SecurityTestCase
{
    use RefreshDatabase;

    private Store $storeA;

    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        [, $this->storeA] = $this->makeTenant();
        [, $this->storeB] = $this->makeTenant();
        (new User)->flushCache();
    }

    private function shopperIn(?Store $store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Shop', 'last_name' => 'Per',
            'email' => uniqid() . '@example.test', 'password' => bcrypt('not-a-real-credential'),
            'store_id' => $store?->id, 'status' => 1,
            'billable_id' => $store?->created_by, 'billable_type' => User::class,
        ]);
    }

    private function ticketOf(Customer $customer, ?Store $store, string $title): SupportTicket
    {
        return SupportTicket::forceCreate([
            'ticket_id' => 'T-' . bin2hex(random_bytes(4)),
            'title' => $title, 'description' => 'private text', 'status' => 'open',
            'customer_id' => $customer->id, 'store_id' => $store?->id, 'created_by' => $customer->id,
        ]);
    }

    private function listPage(Store $store): string
    {
        return '/' . $store->slug . '/support-ticket';
    }

    /**
     * A shopper as PRODUCTION sees one: signed into the `customers` guard with the DEFAULT guard put
     * back to `web`.
     *
     * `actingAs($c, 'customers')` also calls `shouldUse('customers')`, which makes `auth()->user()`
     * -- read by `getCurrentStore()` and by `StoreScope::resolveTenant()` -- answer the CUSTOMER, so
     * a tenant resolves for a shopper and the global scope silently narrows the query the handler is
     * supposed to narrow itself. CLAUDE.md records that as a measured false reading
     * (`PlatformUploaderDoorsTest::asAShopper`).
     */
    private function asAShopper(Customer $customer): self
    {
        $this->actingAs($customer, 'customers');
        $this->app['auth']->shouldUse('web');

        return $this;
    }

    // -------------------------------------------------------------------------------------------
    //  THE LIST
    // -------------------------------------------------------------------------------------------

    public function test_a_shoppers_tickets_are_not_listed_on_another_stores_support_page(): void
    {
        $shopper = $this->shopperIn($this->storeA);
        $this->ticketOf($shopper, $this->storeA, 'A STORE A TICKET TITLE');

        // THE CONTROL FIRST, so that "absent at store B" cannot be satisfied by a page that shows
        // nothing at all, by a 404 or by a 500.
        $own = $this->asAShopper($shopper)->get($this->listPage($this->storeA));
        $own->assertOk();
        $this->assertStringContainsString('A STORE A TICKET TITLE', (string) $own->getContent(),
            'PREMISE: the shopper cannot see their own ticket on their own store page, so the '
            . 'assertion below would hold whatever the fix does');

        $other = $this->asAShopper($shopper)->get($this->listPage($this->storeB));

        $other->assertOk();
        $this->assertStringNotContainsString('A STORE A TICKET TITLE', (string) $other->getContent(),
            "a shopper's store-A ticket was listed inside store B's storefront");
    }

    /**
     * THE CASE THAT MEASURES THE STORE PREDICATE RATHER THAN `shopper()`.
     *
     * A customer whose `store_id` is NULL is admitted by `shopper()` at EVERY store, so the only
     * thing that can keep their store-A ticket off store B's page is the predicate on the TICKET's
     * store. Without this case, "refuse when the customer belongs to another store" would pass the
     * case above and leave (ii) wide open.
     */
    public function test_a_null_store_shoppers_list_does_not_span_stores(): void
    {
        $shopper = $this->shopperIn(null);
        $this->ticketOf($shopper, $this->storeA, 'RAISED AT STORE A');
        $this->ticketOf($shopper, $this->storeB, 'RAISED AT STORE B');

        $this->assertNull($shopper->store_id,
            'PREMISE: the fixture customer is bound to a store, so shopper() decides this case and '
            . 'the ticket predicate is not what is being measured.');

        $atB = $this->asAShopper($shopper)->get($this->listPage($this->storeB));
        $body = (string) $atB->getContent();

        $atB->assertOk();
        $this->assertStringContainsString('RAISED AT STORE B', $body,
            'PREMISE: the shopper cannot see the ticket they raised at this store, so the '
            . 'assertion below proves nothing.');
        $this->assertStringNotContainsString('RAISED AT STORE A', $body,
            "a NULL-store shopper's list still spans stores");
    }

    /** A legacy ticket with no store of its own stays visible, exactly as `ownedTicket()` admits it. */
    public function test_a_ticket_with_no_store_recorded_is_still_listed(): void
    {
        $shopper = $this->shopperIn($this->storeA);
        $this->ticketOf($shopper, null, 'A LEGACY TICKET WITH NO STORE');

        $response = $this->asAShopper($shopper)->get($this->listPage($this->storeA));

        $response->assertOk();
        $this->assertStringContainsString('A LEGACY TICKET WITH NO STORE', (string) $response->getContent(),
            'the bounding dropped the legacy rows ownedTicket() still opens, so the list and the '
            . 'detail door disagree in the other direction');
    }

    /**
     * THE LIST AND THE DOORS IT LINKS TO AGREE: every row the list prints can be opened.
     *
     * Stated as one property rather than as two separate assertions about two handlers, because the
     * defect was precisely that they disagreed -- the list printed an Edit and a Reply button for a
     * ticket both of those handlers answer "Ticket not found" for.
     */
    public function test_the_list_offers_no_row_the_detail_door_refuses(): void
    {
        $shopper = $this->shopperIn(null);
        $atA = $this->ticketOf($shopper, $this->storeA, 'RAISED AT STORE A');

        $refused = $this->asAShopper($shopper)
            ->getJson('/' . $this->storeB->slug . '/get-support-ticket/' . $atA->id);
        $this->assertSame(404, $refused->status(),
            'PREMISE: the detail door at store B opens a store-A ticket, so the list and the door '
            . 'do not disagree and this case measures nothing.');

        $body = (string) $this->asAShopper($shopper)->get($this->listPage($this->storeB))->getContent();

        $this->assertStringNotContainsString('/get-support-ticket/' . $atA->id, $body,
            'the list prints an Edit button for a ticket the edit door refuses');
        $this->assertStringNotContainsString('/reply-support-ticket/' . $atA->id, $body,
            'the list prints a Reply button for a ticket the reply door refuses');
    }

    /**
     * THE CASE THAT SEPARATES `shopper($store)` FROM "SOME CUSTOMER IS SIGNED IN", AND IT WAS ADDED
     * BECAUSE A MUTATION SURVIVED.
     *
     * Reverting the list's principal to `auth('customers')->user()` -- the presence test CLAUDE.md
     * names as the most productive defect shape here -- broke none of the cases above, because in
     * every one of them the predicate on the ROW already decided. The two readings only disagree for
     * an input where the row's store predicate ADMITS the row and the customer does not belong to the
     * store: a customer bound to store A, a ticket of theirs with a NULL `store_id` (which
     * `orWhereNull` admits at every store, deliberately, for the legacy rows), and store B's page.
     *
     * A presence test lists that ticket inside store B's storefront; `ownedTicket()` at store B goes
     * through `shopper()` and refuses to open it. So this is the list/door disagreement again, in the
     * one shape the row predicate cannot catch. CLAUDE.md trap 9: when a mutant survives, find the
     * input under which the two behaviours differ rather than weakening the mutant.
     */
    public function test_a_shopper_of_another_store_is_not_served_their_store_less_ticket_here(): void
    {
        $shopper = $this->shopperIn($this->storeA);
        $legacy = $this->ticketOf($shopper, null, 'A STORE-LESS TICKET OF A STORE A SHOPPER');

        // The premises: the row IS admitted by the store predicate, and the door at store B refuses it.
        $own = $this->asAShopper($shopper)->get($this->listPage($this->storeA));
        $this->assertStringContainsString('A STORE-LESS TICKET OF A STORE A SHOPPER', (string) $own->getContent(),
            'PREMISE: the store-less ticket is not listed even at its own shopper\'s store, so the '
            . 'row predicate is what refuses it below and this case does not reach the principal.');
        $refused = $this->asAShopper($shopper)
            ->getJson('/' . $this->storeB->slug . '/get-support-ticket/' . $legacy->id);
        $this->assertSame(404, $refused->status(),
            'PREMISE: the detail door at store B opens this ticket, so the list showing it would '
            . 'not be a disagreement.');

        $atB = $this->asAShopper($shopper)->get($this->listPage($this->storeB));

        $atB->assertOk();
        $this->assertStringNotContainsString('A STORE-LESS TICKET OF A STORE A SHOPPER', (string) $atB->getContent(),
            "a customer bound to store A was served their store-less ticket inside store B's "
            . 'storefront: the list asks whether SOME customer is signed in, not which');
    }

    /** The deliberately-unauthenticated guest page still renders, which is P1-SEC's own regression guard. */
    public function test_a_guest_still_gets_the_page(): void
    {
        $response = $this->get($this->listPage($this->storeA));

        $response->assertOk();
        $this->assertNull($response->headers->get('Location'),
            'the guest is redirected now; this route is deliberately unauthenticated and '
            . 'CustomerAccountBoundaryTest justifies it on the basis that it renders');
    }

    // -------------------------------------------------------------------------------------------
    //  THE ORDER PICKER ON THE TICKET FORM
    // -------------------------------------------------------------------------------------------

    private function orderIn(Store $store, Customer $customer, string $reference): Order
    {
        return $this->makeOrder($store, [
            'product_order_id' => $reference, 'customer_id' => $customer->id, 'is_guest' => 0,
        ]);
    }

    /**
     * The picker offers only orders `ownedOrder()` will accept.
     *
     * A NULL-store customer again, because they are the one principal who legitimately reaches both
     * stores' forms, so the store predicate is what decides and not `shopper()`.
     */
    public function test_the_new_ticket_form_offers_only_this_stores_orders(): void
    {
        $shopper = $this->shopperIn(null);
        $this->orderIn($this->storeA, $shopper, 'ORDER-AT-STORE-A');
        $this->orderIn($this->storeB, $shopper, 'ORDER-AT-STORE-B');

        $response = $this->asAShopper($shopper)->getJson('/' . $this->storeB->slug . '/add-ticket');
        $body = (string) $response->getContent();

        $this->assertStringContainsString('ORDER-AT-STORE-B', $body,
            'PREMISE: the picker does not offer this store\'s own order, so the assertion below '
            . 'holds whatever the fix does');
        $this->assertStringNotContainsString('ORDER-AT-STORE-A', $body,
            "the new-ticket form offered an order of another store, which the writer's ownedOrder() "
            . 'then refuses');
    }

    /**
     * AND THE SAME SEPARATION FOR THE PICKER'S PRINCIPAL -- also added because a mutation survived.
     *
     * `pickableOrders($store, auth('customers')->user())` passes every case above, because the store
     * predicate decides them. The disagreeing input is an order of this customer recorded IN THIS
     * STORE while the customer is bound to ANOTHER: the `customers` session is one session across
     * every storefront, so a store-A shopper can check out at store B and own such a row. A presence
     * test offers it; `support_ticket_store()` resolves the posted `order_id` through `ownedOrder()`,
     * whose customer comes from `shopper($store)` -- null here -- and answers "Order not found."
     */
    public function test_a_shopper_of_another_store_is_offered_no_order_on_this_stores_form(): void
    {
        $shopper = $this->shopperIn($this->storeA);
        $this->orderIn($this->storeB, $shopper, 'ORDER-PLACED-AT-STORE-B');

        // PREMISE: the writer refuses that order here, so offering it is a disagreement.
        $ticketsBefore = SupportTicket::withoutGlobalScopes()->count();
        $write = $this->asAShopper($shopper)->post('/' . $this->storeB->slug . '/store-ticket', [
            'title' => 'about my order', 'description' => 'text',
            'order_id' => Order::where('product_order_id', 'ORDER-PLACED-AT-STORE-B')->value('id'),
        ]);
        $write->assertRedirect();
        $this->assertSame($ticketsBefore, SupportTicket::withoutGlobalScopes()->count(),
            'PREMISE: the writer ACCEPTED that order, so the picker offering it is not a '
            . 'disagreement and this case measures nothing.');

        $body = (string) $this->asAShopper($shopper)
            ->getJson('/' . $this->storeB->slug . '/add-ticket')->getContent();

        $this->assertStringNotContainsString('ORDER-PLACED-AT-STORE-B', $body,
            'the form offered an order to a customer bound to another store, and the writer then '
            . 'refuses it: the picker asks whether SOME customer is signed in, not which');
    }

    /** The same picker is rebuilt on the EDIT form, and it was the same query. */
    public function test_the_edit_ticket_form_offers_only_this_stores_orders(): void
    {
        $shopper = $this->shopperIn(null);
        $this->orderIn($this->storeA, $shopper, 'ORDER-AT-STORE-A');
        $this->orderIn($this->storeB, $shopper, 'ORDER-AT-STORE-B');
        $ticket = $this->ticketOf($shopper, $this->storeB, 'A TICKET AT STORE B');

        $response = $this->asAShopper($shopper)
            ->getJson('/' . $this->storeB->slug . '/get-support-ticket/' . $ticket->id);
        $body = (string) $response->getContent();

        $response->assertOk();
        $this->assertStringContainsString('ORDER-AT-STORE-B', $body,
            'PREMISE: the edit form does not offer this store\'s own order.');
        $this->assertStringNotContainsString('ORDER-AT-STORE-A', $body,
            'the edit form offered an order of another store');
    }
}
