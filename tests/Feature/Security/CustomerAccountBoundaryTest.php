<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\DeliveryAddress;
use App\Models\Store;
use App\Models\SupportTicket;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * NEW-customer-account-routes-anonymous -- the status.cancel analogue sweep.
 *
 * Only `my-account` was ever inside ['auth:customers'] in routes/customer.php.
 * Every other AccountProfileController route sat in the outer storefront group,
 * whose middleware is [ActiveTheme, themelanguage] -- no authentication. Same
 * shape as status.cancel: storefront route, bare id off the request, no
 * ownership predicate, state change.
 *
 * THIS SUITE IS DELIBERATELY SYMMETRIC. Half of it proves the denial; the other
 * half proves the storefront and the signed-in customer still work. That is not
 * padding -- two "fixes" in this project have broken the storefront while the
 * board stayed green (a brace move behind `auth` killed newsletter signup on
 * every store; scoping Menu blanked the navigation). A one-directional
 * authorization suite cannot catch that.
 */
class CustomerAccountBoundaryTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function makeCustomerIn(Store $store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Cust',
            'last_name' => 'Omer',
            'email' => 'cust' . uniqid() . '@example.test',
            'password' => bcrypt('not-a-real-credential'),
            'store_id' => $store->id,
            'status' => 1,
            'billable_id' => $store->created_by,
            'billable_type' => \App\Models\User::class,
        ]);
    }

    private function makeAddressFor(Customer $customer): DeliveryAddress
    {
        // Real columns only. `delivery_addresses` has title/type, NOT
        // address_type (2023_12_19_..._create_delivery_addresses_table:15-35);
        // the first draft of this fixture invented address_type and errored.
        return DeliveryAddress::forceCreate([
            'customer_id' => $customer->id,
            'full_name' => 'Victim Person',
            'phone' => '0000000',
            'title' => 'home',
            'address' => '1 Victim Street',
            'postcode' => 12345,
        ]);
    }

    // ------------------------------------------------- the route table itself

    /**
     * The gate, asserted on the real router rather than by reading the file.
     *
     * Checked by MIDDLEWARE CLASS, not by route name, so that moving a route
     * out of the group is what fails -- adding it to some allowlist cannot make
     * this pass.
     */
    #[DataProvider('gatedRouteNameProvider')]
    public function test_the_customer_account_route_is_behind_the_customer_gate(string $name): void
    {
        $this->assertTrue(Route::has($name), "Route {$name} is missing.");

        $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();

        $this->assertContains(
            \App\Http\Middleware\EnsureCustomerAuthenticated::class,
            $middleware,
            "Route {$name} is reachable without a customer session. It is an "
            . 'AccountProfileController route that either destroys a row by bare id, '
            . 'renders another shopper\'s data, or dereferences the null guard user. '
            . 'Middleware was [' . implode(', ', array_map('strval', $middleware)) . '].'
        );
    }

    public static function gatedRouteNameProvider(): array
    {
        return array_map(fn ($n) => [$n], [
            // Destructive, no ownership predicate of any kind.
            'remove.address',
            'delete.addressbook',
            'delete.wishlist',
            'destroy.ticket',
            'update.support.ticket',
            'tickets.attachment.destroy',
            'ticket.reply',
            'save-address',
            'add.address',
            'update.addressbook.data',
            'order.refund.request',
            // IDOR reads.
            'address-form',
            'edit.address.form',
            'get.support.ticket',
            'reply.support.ticket',
            'order.refund',
            'order.return.list',
            'customer.order',
            'reward.list',
            // Null dereference for an anonymous caller, plus private data.
            'profile.update',
            'customer.password.change',
            'order.page.filter',
            'add.support.ticket',
            'support.ticket.store',
            'user.downloadable_prodcut',
        ]);
    }

    /**
     * THE CONVERSE, and the reason this suite exists in this shape.
     *
     * These storefront endpoints MUST stay anonymous. Asserted POSITIVELY
     * against the real router: the sweep in UnauthenticatedRouteBoundaryTest
     * SKIPS allowlisted names, so allowlisting a route proves nothing about
     * whether it is reachable. This does not skip.
     */
    #[DataProvider('publicStorefrontRouteProvider')]
    public function test_the_public_storefront_route_is_still_anonymous(string $name, string $why): void
    {
        $this->assertTrue(Route::has($name), "Route {$name} is missing.");

        $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();

        foreach (['auth', 'auth:customers', \App\Http\Middleware\EnsureCustomerAuthenticated::class] as $gate) {
            $this->assertNotContains(
                $gate,
                $middleware,
                "Route {$name} has been authenticated. {$why} Gating it breaks the "
                . 'storefront for every shopper, which is a defect, not a fix.'
            );
        }
    }

    public static function publicStorefrontRouteProvider(): array
    {
        return [
            'add.newsletter' => [
                'add.newsletter',
                'The footer subscribe form is anonymous by design and the handler '
                . 'already branches on auth()->user() to store user_id 0 for a guest '
                . '(AccountProfileController:421-425). This is the exact route a '
                . 'previous change swept behind `auth`, breaking signup on every store.',
            ],
            'states.list' => [
                'states.list',
                'Read-only country->state lookup wired into '
                . 'front_end/jQueryRoute.blade.php:154 and the checkout address form. '
                . 'A guest must be able to pick a state to check out.',
            ],
            'city.list' => [
                'city.list',
                'Read-only state->city lookup, jQueryRoute.blade.php:155.',
            ],
            'order' => [
                'order',
                'order_list:447-451 already branches on auth(\'customers\') and renders '
                . 'an empty list for a guest, so it leaks nothing.',
            ],
            'address' => [
                'address',
                'address:249-253 renders an empty list for a guest.',
            ],
            'support.ticket' => [
                'support.ticket',
                'support_ticket:561-565 renders an empty list for a guest.',
            ],
            // The checkout flow, re-asserted here so that this file alone is
            // enough to catch a storefront regression introduced by this change.
            'place.order' => ['place.order', 'Guest checkout submit.'],
            'status.cancel' => ['status.cancel', 'Guest order cancellation from the emailed link.'],
            'product.cart' => ['product.cart', 'Add-to-cart for an anonymous shopper.'],
            'checkout' => ['checkout', 'The checkout page itself.'],
        ];
    }

    /**
     * Route reordering hazard. This change moved six declarations, and
     * RouteCollection keys allRoutes by method+domain+uri, so a duplicate
     * declaration REPLACES the earlier one silently -- that is exactly how the
     * unauthenticated copy of order-view/{id} survived and how profile.edit and
     * contact.us are lost today.
     *
     * So: no method+URI pair inside routes/customer.php may be declared twice.
     */
    public function test_the_customer_route_file_declares_no_duplicate_method_and_uri(): void
    {
        $seen = [];
        $duplicates = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getAction('controller');

            if (!is_string($action) || !str_contains($action, 'AccountProfileController@')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                $key = $method . ' ' . $route->uri();

                if (isset($seen[$key])) {
                    $duplicates[] = $key . ' (' . $seen[$key] . ' and ' . $route->getName() . ')';
                }

                $seen[$key] = $route->getName() ?? '(unnamed)';
            }
        }

        $this->assertSame([], $duplicates,
            "Duplicate method+URI declarations silently replace each other:\n  "
            . implode("\n  ", $duplicates));
    }

    // ----------------------------------------------- denial, with DB evidence

    /**
     * The sharpest one. DeliveryAddress::where('id', $request->id)->delete()
     * with no auth and no ownership predicate: an anonymous caller walks the
     * integers and deletes every delivery address on the platform.
     *
     * Asserted on DATABASE STATE, not on the status code, because a redirect
     * that still performed the delete would pass a status-only assertion.
     */
    public function test_an_anonymous_caller_cannot_delete_a_delivery_address(): void
    {
        if (!Schema::hasTable('delivery_addresses')) {
            $this->markTestSkipped('BLOCKED: the `delivery_addresses` table is not declared by any migration.');
        }

        [, $store] = $this->makeTenant();
        $victim = $this->makeCustomerIn($store);
        $address = $this->makeAddressFor($victim);

        $this->post("/{$store->slug}/remove-address", ['id' => $address->id]);

        $this->assertDatabaseHas('delivery_addresses', ['id' => $address->id]);

        // The GET sibling, which is worse: a destructive GET is reachable by a
        // drive-by <img src>, with no CSRF token involved.
        $this->get("/{$store->slug}/delete-addressbook?id=" . $address->id);

        $this->assertDatabaseHas('delivery_addresses', ['id' => $address->id]);
    }

    public function test_an_anonymous_caller_cannot_delete_a_wishlist_row(): void
    {
        if (!Schema::hasTable('wishlists')) {
            $this->markTestSkipped('BLOCKED: the `wishlists` table is not declared by any migration.');
        }

        [, $store] = $this->makeTenant();
        $victim = $this->makeCustomerIn($store);

        // product_id carries a foreign key to products
        // (create_wishlists_table:24), so it is left null rather than pointed
        // at an id that does not exist.
        $row = Wishlist::forceCreate([
            'customer_id' => $victim->id,
            'product_id' => null,
            'store_id' => $store->id,
        ]);

        $this->get("/{$store->slug}/delete-wishlist?id=" . $row->id);

        $this->assertDatabaseHas('wishlists', ['id' => $row->id]);
    }

    /**
     * SupportTicket::where('id', $id)->delete() by GET, anonymous, across every
     * store. SupportTicket IS one of the six tenancy-enforced models, which is
     * precisely why this needed a route gate: StoreScope FAILS OPEN on a null
     * tenant in the storefront context by documented decision, and an anonymous
     * storefront request is a null tenant.
     */
    public function test_an_anonymous_caller_cannot_delete_a_support_ticket(): void
    {
        if (!Schema::hasTable('support_tickets')) {
            $this->markTestSkipped('BLOCKED: the `support_tickets` table is not declared by any migration.');
        }

        [, $store] = $this->makeTenant();
        $victim = $this->makeCustomerIn($store);

        // support_tickets.created_by is NOT nullable
        // (create_support_tickets_table:24), so it has to be supplied.
        $ticket = SupportTicket::forceCreate([
            'ticket_id' => 'T-' . uniqid(),
            'title' => 'Victim ticket',
            'description' => 'please do not delete me',
            'customer_id' => $victim->id,
            'status' => 'Open',
            'store_id' => $store->id,
            'created_by' => $store->created_by,
        ]);

        $this->get("/{$store->slug}/destroy-ticket/{$ticket->id}");

        $this->assertDatabaseHas('support_tickets', ['id' => $ticket->id]);

        // And must not be takeover-able: update_support_ticket overwrote
        // customer_id from the request body, reassigning another shopper's
        // ticket to the caller.
        $this->post("/{$store->slug}/update-support-ticket/{$ticket->id}", [
            'title' => 'stolen',
            'description' => 'stolen',
            'customer_id' => 424242,
        ]);

        $fresh = $ticket->fresh();
        $this->assertSame('Victim ticket', $fresh->title);
        $this->assertSame((int) $victim->id, (int) $fresh->customer_id);
    }

    /**
     * order_refund_request took order_id off the request, loaded the order with
     * Order::find() and then REWROTE product_json, product_price and
     * final_price. An anonymous caller rewriting the money on any order in any
     * store -- a strictly worse defect than status.cancel, which only changed a
     * status and stock.
     */
    public function test_an_anonymous_caller_cannot_rewrite_an_orders_totals(): void
    {
        [, $store] = $this->makeTenant();
        $victim = $this->makeCustomerIn($store);

        $order = $this->makeOrder($store, [
            'customer_id' => $victim->id,
            'final_price' => 100,
            'product_price' => 100,
            'product_json' => json_encode([
                ['product_id' => 1, 'variant_id' => 0, 'qty' => 2, 'final_price' => 100],
            ]),
        ]);

        $this->post("/{$store->slug}/order-refund-request/{$order->id}", [
            'product_refund_id' => [1],
            'order_id' => $order->id,
            'quantity' => [2],
            'return_price' => [100],
        ]);

        $this->assertSame(
            100.0,
            (float) $order->fresh()->final_price,
            'An anonymous caller rewrote the final price of an order.'
        );
    }

    /**
     * 403, NOT 401, on the AJAX endpoints -- the rule that protects the shipped
     * Flutter app from being logged out by an authorization denial, kept uniform
     * across web and API so a future shared client cannot trip over it.
     *
     * And NOT 500: several of these handlers dereferenced the null guard user,
     * so an anonymous call used to be a crash rather than a refusal.
     */
    #[DataProvider('ajaxDenialProvider')]
    public function test_an_anonymous_ajax_call_is_refused_with_403(string $method, string $path): void
    {
        [, $store] = $this->makeTenant();

        $response = $this->json($method, "/{$store->slug}{$path}");

        $this->assertSame(403, $response->getStatusCode(),
            "Expected 403. A 401 would be mapped by repo-B network.dart into a forced "
            . 'logout; a 500 would mean the handler is still dereferencing the null '
            . 'guard user instead of refusing.');
    }

    public static function ajaxDenialProvider(): array
    {
        return [
            'remove-address' => ['POST', '/remove-address'],
            'save-address' => ['POST', '/save-address'],
            'profile-update' => ['POST', '/profile-update'],
            'password-change' => ['POST', '/customer_password_change'],
            'reward-list' => ['GET', '/reward-list'],
            'order-return-list' => ['GET', '/order-return-list'],
            'order-filter' => ['GET', '/order-filter'],
            'delete-wishlist' => ['GET', '/delete-wishlist'],
        ];
    }

    // --------------------------------------- the legitimate flows still work

    /**
     * THE LEGITIMATE CUSTOMER WORKFLOW. A signed-in shopper must still be able
     * to use their own account area. Without this, the suite above would pass
     * just as happily if the gate rejected everybody.
     */
    public function test_a_signed_in_customer_can_still_reach_their_account_pages(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->makeCustomerIn($store);

        foreach ([
            "/{$store->slug}/reward-list",
            "/{$store->slug}/order-filter",
            "/{$store->slug}/order-return-list",
        ] as $uri) {
            $response = $this->actingAs($customer, 'customers')->get($uri);

            $this->assertNotSame(403, $response->getStatusCode(),
                "A signed-in customer was refused {$uri}. The gate must admit the "
                . 'customer guard, not merely reject everyone.');
            $this->assertNotSame(302, $response->getStatusCode(),
                "A signed-in customer was redirected away from {$uri}.");
        }
    }

    /**
     * And the shopper can still delete their OWN address -- the real flow
     * behind front_end/pages/address.blade.php.
     *
     * NOTE WHAT THIS DOES *NOT* CLAIM. It proves the route gate admits the
     * owner. It does NOT prove the handler checks ownership: it still does not
     * (AccountProfileController::remove_address:348 deletes by bare id), so one
     * signed-in customer can still delete another's address. That residual
     * defect needs a predicate inside AccountProfileController, which this
     * agent does not own, and it is reported as a cross-agent dependency rather
     * than asserted away here.
     */
    public function test_a_signed_in_customer_can_still_delete_their_own_address(): void
    {
        if (!Schema::hasTable('delivery_addresses')) {
            $this->markTestSkipped('BLOCKED: the `delivery_addresses` table is not declared by any migration.');
        }

        [, $store] = $this->makeTenant();
        $customer = $this->makeCustomerIn($store);
        $address = $this->makeAddressFor($customer);

        $this->actingAs($customer, 'customers')
            ->post("/{$store->slug}/remove-address", ['id' => $address->id]);

        $this->assertDatabaseMissing('delivery_addresses', ['id' => $address->id]);
    }

    /**
     * NEWSLETTER SIGNUP, END TO END, ANONYMOUSLY. Not just "the route has no
     * auth middleware" -- the row must actually land. This is the regression
     * that shipped once already.
     */
    public function test_an_anonymous_visitor_is_not_gated_out_of_newsletter_signup(): void
    {
        if (!Schema::hasTable('newsletters')) {
            $this->markTestSkipped('BLOCKED: the `newsletters` table is not declared by any migration.');
        }

        $this->makeTenant();

        $email = 'shopper' . uniqid() . '@example.test';

        $response = $this->post('/add-newsletter', ['email' => $email]);

        // THE AUTHORIZATION PROPERTY, which is what this change is responsible
        // for: the route must not have been swept behind a login.
        $this->assertNotSame(403, $response->getStatusCode(),
            'Anonymous newsletter signup was refused.');
        $this->assertNotSame(302, $response->getStatusCode(),
            'Anonymous newsletter signup was redirected to a login page.');

        // NEW-add-newsletter-undeclared-column -- AND THE ROW STILL DOES NOT
        // LAND, for a reason that has nothing to do with authorization.
        //
        // AccountProfileController::add_newsletter:421-427 writes
        // `$newsletter->user_id`, and the `newsletters` table HAS NO user_id
        // COLUMN. Measured, not inferred -- Schema::getColumnListing:
        //   id, customer_id, email, store_id, created_at, updated_at
        // and the insert raises
        //   SQLSTATE[HY000]: General error: 1 table newsletters has no column
        //   named user_id
        //   (insert into "newsletters" ("email", "user_id", ...))
        // so this endpoint 500s for EVERY caller, signed in or not. Same class
        // as orders.currency_code: a column written by code and declared by no
        // migration.
        //
        // PRE-EXISTING AND UNRELATED TO THIS CHANGE: the route's middleware is
        // untouched by it, and the failure is an insert error, not a redirect.
        // The handler lives in AccountProfileController, which this agent does
        // not own, so it is reported as a cross-agent dependency rather than
        // fixed here -- and the end-to-end half of this test is SKIPPED with
        // the reason named, not deleted, so it goes live the moment the column
        // or the handler is corrected.
        //
        // The storefront themes post to `newsletter.store`
        // (NewsletterController), a DIFFERENT endpoint, which is why footer
        // signup works in production despite this one being broken.
        if (!Schema::hasColumn('newsletters', 'user_id')) {
            $this->markTestSkipped(
                'BLOCKED: AccountProfileController::add_newsletter writes '
                . 'newsletters.user_id, which no migration declares, so the insert '
                . 'always fails (cross-agent dependency -- schema + the '
                . 'AccountProfileController owner). The authorization assertions '
                . 'above ran and passed before this skip.'
            );
        }

        $this->assertDatabaseHas('newsletters', ['email' => $email]);
    }

    /**
     * The checkout address lookups must still answer anonymously, or a guest
     * cannot complete the address form.
     */
    public function test_an_anonymous_visitor_can_still_use_the_checkout_location_lookups(): void
    {
        [, $store] = $this->makeTenant();

        foreach (['states-list', 'city-list'] as $path) {
            $response = $this->post("/{$store->slug}/{$path}", ['country_id' => 1, 'state_id' => 1]);

            $this->assertNotSame(403, $response->getStatusCode(),
                "The {$path} lookup was refused for a guest; guest checkout needs it.");
            $this->assertNotSame(302, $response->getStatusCode(),
                "The {$path} lookup redirected a guest to a login page.");
        }
    }
}
