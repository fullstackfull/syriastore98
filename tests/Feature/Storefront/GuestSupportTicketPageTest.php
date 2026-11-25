<?php

namespace Tests\Feature\Storefront;

use App\Models\Customer;
use App\Models\Store;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * THE STOREFRONT SUPPORT-TICKET PAGE WAS AN HTTP 500 FOR EVERY ANONYMOUS VISITOR.
 * =================================================================================================
 *
 * `GET {storeSlug?}/support-ticket` (`routes/customer.php`) is declared OUTSIDE the
 * `EnsureCustomerAuthenticated` group that opens on the next line, so an anonymous visitor reaches
 * the handler. `AccountProfileController::support_ticket()` then branches:
 *
 *     a signed-in shopper  ->  SupportTicket::where(...)->paginate(10)   a LengthAwarePaginator
 *     a guest              ->  collect()                                a bare Collection
 *
 * and `front_end/pages/support_ticket.blade.php` calls `$tickets->onFirstPage()`,
 * `->currentPage()` and `->lastPage()` with no guard. `Illuminate\Support\Collection` has none of
 * them, so the view raises `BadMethodCallException`.
 *
 * MEASURED, by driving the real route on a real fixture store with no session:
 *
 *     GET /{slug}/support-ticket   500
 *     GET /{slug}/order            200     <- the sibling, fine
 *     GET /{slug}/address          200     <- the sibling, fine
 *
 * THE TWO SIBLINGS WERE CHECKED AND ARE NOT AFFECTED, which is worth stating because they carry the
 * same shape of justification and I expected to find three defects. `address()` uses `->get()` on
 * BOTH branches, so its view never sees a paginator; `order_list()` likewise answers 200. The extent
 * is one route.
 *
 * AND IT FALSIFIED A JUSTIFICATION THIS REPOSITORY RELIES ON. `CustomerAccountBoundaryTest` lists
 * `support.ticket` among the deliberately-unauthenticated storefront routes with the reason
 * *"support_ticket:561-565 renders an empty list for a guest"*. It does not render an empty list --
 * it throws. The security conclusion still held (it fails before printing anything, so nothing
 * leaks) but the stated premise was false, and the line citation had drifted: the handler is at
 * :736-748. Both are corrected there, and this fix is what finally makes that sentence true.
 *
 * THE FIX IS THE EMPTY PAGINATOR, not a guard in the view. The view is written for a paginator and
 * three separate call sites would each need guarding; one correct empty paginator satisfies all of
 * them and makes the guest page render the empty list the boundary test always claimed it did.
 */
class GuestSupportTicketPageTest extends SecurityTestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = $this->makeMerchant();
        $this->store = $this->makeStore($owner, ['default_language' => 'en']);
        (new User)->flushCache();
    }

    private function url(): string
    {
        return '/' . $this->store->slug . '/support-ticket';
    }

    public function test_an_anonymous_visitor_gets_the_page_rather_than_a_500(): void
    {
        $response = $this->get($this->url());

        $response->assertOk();
    }

    /**
     * The page renders the EMPTY LIST the boundary test's justification claims, not just any 200.
     *
     * Asserted separately from the status code because a handler that returned an early redirect
     * would also stop being a 500 while silently changing a route this project has deliberately
     * left unauthenticated -- and `CustomerAccountBoundaryTest` would then be justifying a route
     * that no longer behaves as described.
     */
    public function test_the_anonymous_page_is_the_account_area_and_not_a_redirect(): void
    {
        $response = $this->get($this->url());

        $response->assertOk();
        $this->assertNull($response->headers->get('Location'),
            'the guest is redirected now; this route is deliberately unauthenticated and '
            . 'CustomerAccountBoundaryTest justifies it on the basis that it renders');
    }

    /** Nothing of any tenant's is disclosed to the guest -- the property the boundary test asserts. */
    public function test_the_anonymous_page_discloses_no_tickets(): void
    {
        $customer = Customer::forceCreate([
            'first_name' => 'C', 'last_name' => 'Shopper',
            'email' => uniqid() . '@example.test', 'password' => bcrypt('x'),
            'store_id' => $this->store->id,
        ]);

        SupportTicket::forceCreate([
            'ticket_id' => 'T-' . bin2hex(random_bytes(4)),
            'title' => 'SOMEBODY ELSES TICKET TITLE',
            'description' => 'SOMEBODY ELSES TICKET BODY',
            'status' => 'open',
            'customer_id' => $customer->id,
            'store_id' => $this->store->id,
            'created_by' => 0,
        ]);

        $body = (string) $this->get($this->url())->getContent();

        $this->assertStringNotContainsString('SOMEBODY ELSES TICKET TITLE', $body);
        $this->assertStringNotContainsString('SOMEBODY ELSES TICKET BODY', $body);
    }

    /**
     * A SECOND 500 ON THE SAME PAGE, FOR THE TICKET'S OWNER -- and it had no case of its own.
     *
     * It surfaced only because the regression guard below failed for a reason that was not the
     * guest defect: `Call to a member function order_detail() on null`. The view did
     * `Order::find($ticket->order_id)` and dereferenced the result, and
     * `support_tickets.order_id` is a NULLABLE string -- so a general support ticket, one not about
     * an order, made a signed-in shopper's own ticket list a 500. A defect found through another
     * test's failure needs its own case, or the next person to change that line has nothing
     * pinning it.
     *
     * The ticket here deliberately carries NO `order_id`, which is the state the shipped form
     * produces when the shopper does not pick an order.
     */
    public function test_a_shopper_with_an_order_less_ticket_can_still_open_the_page(): void
    {
        $customer = Customer::forceCreate([
            'first_name' => 'C', 'last_name' => 'Shopper',
            'email' => uniqid() . '@example.test', 'password' => bcrypt('x'),
            'store_id' => $this->store->id,
        ]);

        $ticket = SupportTicket::forceCreate([
            'ticket_id' => 'T-' . bin2hex(random_bytes(4)),
            'title' => 'A GENERAL QUESTION',
            'description' => 'Not about an order.',
            'status' => 'open',
            'customer_id' => $customer->id,
            'store_id' => $this->store->id,
            'created_by' => 0,
        ]);

        $this->assertTrue(empty($ticket->order_id),
            'PREMISE: the fixture ticket names an order, so it does not reach the null lookup.');

        $response = $this->actingAs($customer, 'customers')->get($this->url());

        $response->assertOk();
        $this->assertStringContainsString('A GENERAL QUESTION', (string) $response->getContent(),
            'the order-less ticket is not listed, so the page renders without the row it exists '
            . 'to show');
    }

    /**
     * A SIGNED-IN SHOPPER STILL GETS THEIR OWN PAGINATED LIST.
     *
     * The guard against "fixed the guest, broke the customer": the authenticated branch is the one
     * that always worked, and `asAShopper()` is CLAUDE.md's required helper -- `actingAs($c,
     * 'customers')` makes `customers` the DEFAULT guard and hides exactly this kind of defect.
     */
    public function test_a_signed_in_shopper_still_sees_their_own_ticket(): void
    {
        $customer = Customer::forceCreate([
            'first_name' => 'C', 'last_name' => 'Shopper',
            'email' => uniqid() . '@example.test', 'password' => bcrypt('x'),
            'store_id' => $this->store->id,
        ]);

        SupportTicket::forceCreate([
            'ticket_id' => 'T-' . bin2hex(random_bytes(4)),
            'title' => 'MY OWN TICKET TITLE',
            'description' => 'x',
            'status' => 'open',
            'customer_id' => $customer->id,
            'store_id' => $this->store->id,
            'created_by' => 0,
        ]);

        $response = $this->actingAs($customer, 'customers')->get($this->url());

        $response->assertOk();
        $this->assertStringContainsString('MY OWN TICKET TITLE', (string) $response->getContent(),
            "the signed-in shopper's own ticket is gone, so the guest fix broke the customer path");
    }
}
