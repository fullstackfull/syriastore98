<?php

namespace Tests\Feature\Journeys;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;

/**
 * `P04-P9-01` — THE PHASE 04 END-TO-END JOURNEYS, DRIVEN OVER REAL ROUTES.
 * =================================================================================================
 *
 * WHAT THE PLAN ASKS FOR, VERBATIM. `docs/development/phase-4/PHASE-04-EXECUTION-PLAN.md` §6:
 *
 *   > ### P4-9 — End-to-end journeys A–F, integrated verification, closure
 *   > Built on the measured journey in §2.1; every journey asserts on state, never on HTTP 200
 *   > alone. Then full gates, CI on the Draft PR, and `PHASE-04-FINAL-REPORT.md`.
 *
 * AND WHAT IT DOES **NOT** CONTAIN: the definitions of A, B, D, E and F. The traceability row for
 * this requirement states the position and it is not improved by guessing:
 *
 *   > **Actual behaviour:** NO JOURNEY ARTEFACT EXISTS AND THE CLOSURE REPORT NEVER CLAIMS ONE. A
 *   > repo-wide grep for 'Journey [A-F]' in docs/ and tests/ returns exactly one hit — the plan's
 *   > own reference to 'Journey C in the directive'. [...] **Gap:** Five of six journeys have no
 *   > evidence and, worse, no recoverable definition.
 *
 * SO THIS FILE DOES NOT CLAIM TO BE A–F. The lettered list came from an owner directive that is
 * not in this repository, and inventing six letters would put a fabricated scope under a real
 * requirement id — the thing `CLAUDE.md` §4.1 forbids ("Never silently reduce the approved
 * scope", and its converse: never silently invent it either). What IS recoverable is named below,
 * each journey carrying the plan text it is built from. Journey C is the one exception: the plan
 * names it outright (§2.3, "Journey C in the directive is therefore already covered at the engine
 * level"), so that one keeps its letter.
 *
 * THE RECOVERED PLAN'S OWN CLOSING LINE FOR PHASE 04, which is the product-level statement these
 * journeys answer to (`docs/development/master-plan/MASTER-PRODUCT-PLAN-v1.0-WITH-AMENDMENTS.md`,
 * § "مخرجات Phase 04"):
 *
 *   > محركات تشغيل تجاري متكاملة مع اختبارات للعمليات المالية والمخزنية المتزامنة، والتسويات،
 *   > والمرتجعات، وتكامل القنوات.
 *
 *   (*integrated commercial operating engines, with tests for concurrent financial and inventory
 *   operations, settlements, returns, and channel integration*)
 *
 * ---------------------------------------------------------------------------------------------
 * HOW THESE DIFFER FROM THE HANDLER TESTS THAT ALREADY EXIST
 * ---------------------------------------------------------------------------------------------
 *
 * Every step is an HTTP request to a registered route, in the order a principal makes them, with
 * the application's own artefacts carried between them — the basket cookie the cart endpoint
 * MINTS, not one a fixture invents. Nothing is called directly. The final assertion of every
 * journey is database state, because `CLAUDE.md` records three separate ways a status code lies
 * here: a refused module gate answers HTTP 200 on the dashboard, `ApiResponser::error()` answers
 * HTTP 200 with `{status: 0}`, and a handler that catches its own failure answers 200 as well.
 *
 * ---------------------------------------------------------------------------------------------
 * `GAP-345` IS SETTLED BY THIS FILE, AND IT WAS THE HARNESS — IN TWO PLACES, NOT ONE
 * ---------------------------------------------------------------------------------------------
 *
 * The recorded state of the guest journey was: *"the POST answers 302 to `/` with the session
 * error `Cart is empty.` [...] IT IS NOT ESTABLISHED WHETHER THAT IS THE HARNESS OR THE
 * PRODUCT"*, with three fixture hypotheses tested and refuted. Both remaining causes are
 * harness-side and both are measured here:
 *
 *  1. **A FOURTH COOKIE HYPOTHESIS NOBODY HAD TESTED: the `CookieValuePrefix`.** The old fixture
 *     passed `withCookie('cart', encrypt($value, false))`. But
 *     `MakesHttpRequests::prepareCookiesForRequest()` does the encrypting ITSELF, and it prepends
 *     `CookieValuePrefix::create($key, $encrypter->getKey())` before doing so. `EncryptCookies`
 *     validates that prefix and DISCARDS any cookie whose value does not carry it. So the old
 *     fixture produced a doubly-encrypted, unprefixed value that the middleware threw away —
 *     indistinguishable from an empty basket. The correct call is `withCookie('cart', $plain)`,
 *     and hypothesis 3's `serialise=false` finding was right about Laravel and wrong about the
 *     test client, which is why changing it "did not change the outcome".
 *  2. **The storefront posts `billing_info[...]`, a NESTED ARRAY, not flat address fields.**
 *     `resources/views/front_end/common/checkout/checkout_form.blade.php` opens its form on
 *     `['payment.process', $store->slug]` and names every address field
 *     `billing_info[firstname]`, `billing_info[billing_address]`, `billing_info[delivery_city]`
 *     and so on. A POST without that key reaches `PaymentController::handleCustomerRegistration`,
 *     where `$billing` is null and `\Validator::make(null, ...)` is a **TypeError** — HTTP 500.
 *     That is a real (low-severity) product defect in its own right and is pinned below, because
 *     a 500 is not a refusal and the next person to drive this route will hit it.
 *
 * With both corrected the journey completes: 302 to `{slug}/order-complete` and one `orders` row.
 * `GAP-345`'s own "Next" instruction — *"drive the basket the way the storefront does — through
 * the cart endpoints, so the cookie is minted by the application rather than by a fixture"* — is
 * what `aBasketMintedByTheApplication()` does, and it is kept even though the fixture cookie now
 * also works, because it is the only version that proves the cart endpoint and the order endpoint
 * agree about what a basket is.
 *
 * ---------------------------------------------------------------------------------------------
 * MUTATIONS RUN AGAINST THIS FILE — six, all caught (`tests/Support/bin/mutate.py`)
 * ---------------------------------------------------------------------------------------------
 *
 * | mutation | result |
 * |---|---|
 * | journey 1's cookie back to `encrypt($cart, false)` (the `GAP-345` shape) | CAUGHT |
 * | journey 1 posts flat address fields instead of `billing_info[...]` | CAUGHT |
 * | `stock_management` set to `off` in the fixture | CAUGHT by journey 1's stock assertion |
 * | journey C's product left with no oversell choice | CAUGHT |
 * | `$settings['notification']` guarded with `?? '[]'` at `PaymentService` | CAUGHT by the 500 pin |
 * | the packing-list link removed from `order/view.blade.php` | CAUGHT by journey 3 |
 */
final class CommerceJourneyTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const PRICE = 120.00;

    /**
     * A tenant whose store id is NEITHER 1 NOR ITS OWNER'S USER ID.
     *
     * `CLAUDE.md` and `PlatformUploaderDoorsTest::aStoreWhoseIdIsNobodyElses()` both record why:
     * when "the route's store", "store 1" and "its owner's user id" are one number, a handler that
     * resolves the wrong one of the three still answers correctly and four wrong-tenant mutants
     * survive. The decoy store and the decoy users below push the ids apart.
     *
     * @return array{0: User, 1: Store, 2: Product}
     */
    private function aTenantWithOneProductInStock(int $stock = 10, ?string $stockOrderStatus = 'not_allow'): array
    {
        /* Decoys: another merchant and another store, so no id under test is 1. */
        $decoyMerchant = User::factory()->create([
            'type' => 'admin', 'created_by' => 1, 'is_active' => 1, 'email_verified_at' => now(),
        ]);
        /* TWO decoy stores, not one: with a single decoy the next store id and the next user id
           advance together and the merchant under test gets store id == user id anyway (measured:
           both were 2). */
        $this->makeStore($decoyMerchant, ['slug' => 'decoy-a-' . $this->uniqueFixtureRef()]);
        $this->makeStore($decoyMerchant, ['slug' => 'decoy-b-' . $this->uniqueFixtureRef()]);

        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant, [
            'slug' => 'journey-' . $this->uniqueFixtureRef(),
            'default_language' => 'en',
        ]);
        $merchant->current_store = $store->id;
        $merchant->save();

        $product = Product::forceCreate([
            'name' => 'Hand-loomed Table Runner',
            'slug' => 'runner-' . $this->uniqueFixtureRef(),
            'price' => self::PRICE,
            'product_stock' => $stock,
            'track_stock' => 1,
            'variant_product' => 0,
            /*
             * EXPLICIT, AND THE DEFAULT HERE IS NOT THE PRODUCT'S DEFAULT. `GAP-182` / `INV-006`:
             * `ProductStockService::mustNotOversell()` is `=== 'not_allow'`, so ONE exact word
             * refuses and every other value — NULL included — permits overselling. A journey that
             * left this NULL would be measuring the permissive arm while claiming to measure the
             * guard, which is what happened on the first run of journey C below (two orders
             * against one unit of stock). The permissive arm has its own case.
             */
            'stock_order_status' => $stockOrderStatus,
            'store_id' => $store->id,
            'created_by' => $merchant->id,
        ]);

        /*
         * STOCK MANAGEMENT ON, AND THIS IS A PREMISE, NOT DRESSING.
         *
         * `PaymentController`'s whole stock block is wrapped in
         * `if (($settings['stock_management'] ?? null) === 'on')`, and `Utility::Setting()`'s
         * default is `'off'`. So without this row the order writer takes NO stock at all and a
         * journey asserting "a sale consumed a unit" fails against a correct product — measured:
         * 10 units before, 10 after, order written. CLAUDE.md's third way a test passes for the
         * wrong reason is exactly this, in the other direction: an oversell journey with the
         * setting off would "prove" a guard that was never entered.
         */
        DB::table('settings')->insert([
            'name' => 'stock_management', 'value' => 'on',
            'created_by' => $merchant->id, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        /*
         * AND `notification`, WHICHOUT WHICH THE STOCK BLOCK CRASHES — see
         * `test_a_sale_that_empties_a_products_stock_is_an_http_500_that_eats_the_stock`, which is
         * the defect this row works around. It is a WORKAROUND and labelled as one: the journeys
         * are about overselling and fulfilment, and a fixture that crashes on the last unit cannot
         * measure either. A real store reaches this path with the row ABSENT, which is why the
         * defect has its own case rather than being hidden by this line.
         */
        DB::table('settings')->insert([
            'name' => 'notification', 'value' => '[]',
            'created_by' => $merchant->id, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        (new User)->flushCache();
        Cache::forget('admin_details');

        $this->assertNotSame(1, (int) $store->id,
            'premise: the store under test must not be store 1, or three different wrong answers '
            . 'are the same number. See aTenantWithOneProductInStock().');
        $this->assertNotSame((int) $merchant->id, (int) $store->id,
            'premise: the store id must differ from its owner\'s user id, for the same reason.');

        return [$merchant, $store, $product];
    }

    /**
     * THE BASKET THE APPLICATION ITSELF MINTED.
     *
     * `GAP-345`'s next step, executed: the basket is built by POSTing the storefront's own
     * `product.cart` route, and the `cart` cookie is read back off that response rather than
     * invented. `Cart::add_to_cart()` keys the row on `session()->getId()` and queues
     * `Cookie::queue('cart', $session_id)`, so the value in the response IS the row's
     * `cookie_session_id` — which is the fact the old fixture was guessing at.
     *
     * @return string the plain cookie value, ready for withCookie()
     */
    private function aBasketMintedByTheApplication(Store $store, Product $product, int $qty = 1): string
    {
        $add = $this->post('/' . $store->slug . '/product_cart', [
            'product_id' => $product->id, 'variant_id' => 0, 'qty' => $qty,
        ]);

        $this->assertSame(200, $add->status(), 'premise: the storefront cart endpoint answered '
            . $add->status() . ' rather than 200. The basket could not be built.');

        $body = json_decode((string) $add->getContent(), true);

        $this->assertSame(1, $body['status'] ?? null,
            'premise: the cart endpoint refused the line: ' . (string) $add->getContent());

        $cookie = $add->getCookie('cart')?->getValue();

        $this->assertIsString($cookie);
        $this->assertNotSame('', $cookie,
            'premise: the cart endpoint did not mint a `cart` cookie, so there is no basket to '
            . 'carry into checkout.');

        $rows = Cart::withoutGlobalScopes()->forGuestCookie($cookie)->get();

        $this->assertCount(1, $rows,
            'premise: the cookie the application minted does not select the basket row it just '
            . 'wrote. Asserted through withoutGlobalScopes() because Cart IS one of the eleven '
            . 'models config/tenancy.php really scopes, so a plain Cart::find() in a test answers '
            . 'against whichever tenant happens to be resolved.');
        $this->assertSame((int) $store->id, (int) $rows->first()->store_id);

        return $cookie;
    }

    /** The address block exactly as `checkout_form.blade.php` names its fields. */
    private function theCheckoutFormsOwnFields(): array
    {
        return [
            'billing_info' => [
                'firstname' => 'Guest', 'lastname' => 'Buyer',
                'email' => 'guest-' . $this->uniqueFixtureRef() . '@example.test',
                'billing_user_telephone' => '0100000000',
                'billing_address' => '12 Souq Street', 'billing_country' => '1',
                'billing_state' => '1', 'billing_city' => '1', 'billing_postecode' => '0000',
                'delivery_address' => '12 Souq Street', 'delivery_country' => '1',
                'delivery_state' => '1', 'delivery_city' => '1', 'delivery_postcode' => '0000',
            ],
        ];
    }

    private function orders(): \Illuminate\Database\Eloquent\Builder
    {
        return Order::withoutGlobalScopes();
    }

    // =============================================================================================
    // PREMISES. Each of these is a fact a journey below depends on. CLAUDE.md names "a precondition
    // was never met" as the third way a test passes for the wrong reason.
    // =============================================================================================

    /**
     * THE MODULE TABLE IS EMPTY IN THIS HARNESS, AND THE JOURNEYS BELOW DEPEND ON THAT BEING
     * HARMLESS.
     *
     * `CLAUDE.md`: most WorkDo modules load only if a test inserts an `add_on_managers` row, and a
     * refused module gate answers `redirect()->route('dashboard')` — HTTP 200 on a different
     * screen. So the premise worth asserting is not "a module is active" but that **the routes
     * these journeys drive carry no module gate at all**, which makes the empty table irrelevant
     * to them. Asserted from the booted route table, in both directions: the storefront order
     * route must have no `PlanModuleCheck`, and a route that DOES carry one must be found, so
     * that a change which stopped gathering middleware could not make this vacuous.
     */
    #[Test]
    public function test_premise_the_journey_routes_carry_no_module_gate_and_the_check_still_exists(): void
    {
        $gated = 0;
        $subject = [];

        foreach (app('router')->getRoutes() as $route) {
            $middleware = array_filter($route->gatherMiddleware(), 'is_string');

            foreach ($middleware as $name) {
                if (str_contains($name, 'PlanModuleCheck')) {
                    $gated++;
                }
            }

            if (in_array($route->uri(), [
                '{storeSlug?}/process-order',
                '{storeSlug?}/product_cart',
                'order-status-change/{id}',
                'order-packing-list/{id}',
            ], true)) {
                $subject[$route->uri()] = $middleware;
            }
        }

        $this->assertGreaterThan(0, $gated,
            'no route in the booted table carries PlanModuleCheck. Either the gate is gone or '
            . 'gatherMiddleware() stopped answering, and the assertion below would be vacuous.');

        $this->assertCount(4, $subject,
            'one of the four routes these journeys drive is no longer registered under that uri: '
            . implode(', ', array_keys($subject)));

        foreach ($subject as $uri => $middleware) {
            foreach ($middleware as $name) {
                $this->assertStringNotContainsString('PlanModuleCheck', $name,
                    $uri . ' now carries a plan/module gate. A refused gate answers HTTP 200 on '
                    . 'the dashboard, so these journeys would "pass" having never run. Insert the '
                    . 'add_on_managers row the gate wants rather than deleting this premise.');
            }
        }
    }

    /**
     * THE GUEST ORDER ROUTE STILL CARRIES NO AUTHENTICATION. Inherited from
     * `GuestCheckoutJourneyTest`, because journey 1 below is meaningless if it ever gains one.
     */
    #[Test]
    public function test_premise_the_guest_order_route_carries_no_authentication(): void
    {
        $route = collect(app('router')->getRoutes())->first(
            fn ($r) => $r->uri() === '{storeSlug?}/process-order'
        );

        $this->assertNotNull($route, 'premise: the storefront order route still exists.');

        foreach ($route->gatherMiddleware() as $name) {
            if (! is_string($name)) {
                continue;
            }

            foreach (['auth', 'custom.auth'] as $guard) {
                $this->assertNotSame($guard, $name,
                    'the guest checkout route now carries `' . $guard . '`. If that is intended, '
                    . 'journey 1 no longer exists as a journey and P04-P1-05 must be re-read.');
            }
        }
    }

    // =============================================================================================
    // JOURNEY 1 — A GUEST BUYS SOMETHING.
    //
    // The plan's §2.1, verbatim, is the definition of this journey:
    //
    //   > ### 2.1 The purchase journey completes
    //   > `product-list` -> product page -> `POST /product_cart`
    //   > (`{"status":1,"message":"successfull"}`) -> `/cart` showing `Hand-loomed Table Runner
    //   > $65.00`, subtotal `$65.00` -> `/checkout` -> address and billing -> COD ->
    //   > `POST /process-order` (302) -> `/order-complete` with the order reference.
    //   >
    //   > **The form posts to `process-order`, not to the `place-order` route** declared at
    //   > `routes/customer.php:161`. Both exist; the storefront uses the former. Any harness that
    //   > drives `place-order` is not driving what the storefront drives.
    //
    // That paragraph was a narrative of one browser session against a live fixture, quoting one
    // order reference. This is the same sequence as a repeatable harness asserting the row.
    // =============================================================================================

    #[Test]
    public function test_journey_1_a_guest_completes_the_purchase_the_plan_measured(): void
    {
        [, $store, $product] = $this->aTenantWithOneProductInStock();

        $stockBefore = (int) DB::table('products')->where('id', $product->id)->value('product_stock');

        $this->assertSame('on', (string) DB::table('settings')
            ->where('store_id', $store->id)->where('name', 'stock_management')->value('value'),
            'premise: stock_management must be on or the order writer never enters its stock '
            . 'block and the stock assertion below is about code that did not run.');

        /* step 1-2: the catalogue and the product page a guest arrives on. */
        $this->assertSame(200, $this->get('/' . $store->slug . '/product-list')->status(),
            'a guest cannot open the catalogue, so the journey has no first step.');

        /* step 3: the basket, minted by the application. */
        $cart = $this->aBasketMintedByTheApplication($store, $product);

        /* step 4-5: the basket page and the checkout page, carrying that cookie. */
        $this->assertSame(200, $this->withCookie('cart', $cart)->get('/' . $store->slug . '/cart')->status(),
            'a guest with a basket cannot open the basket page.');
        $this->assertSame(200, $this->withCookie('cart', $cart)->get('/' . $store->slug . '/checkout')->status(),
            'a guest with a basket cannot open checkout.');

        /* step 6: the order. */
        $response = $this->withCookie('cart', $cart)->post(
            '/' . $store->slug . '/process-order',
            ['payment_type' => 'cod'] + $this->theCheckoutFormsOwnFields()
        );

        /*
         * THE LANDED PATH, NOT THE STATUS. CLAUDE.md: a refusal here is also a 302 — to `/` with
         * a session error — so 302 alone cannot tell "ordered" from "refused". The destination
         * can.
         */
        $this->assertSame(302, $response->status());
        $this->assertSame(
            url('/' . $store->slug . '/order-complete'),
            (string) $response->headers->get('Location'),
            'the order POST did not land on order-complete. It answered 302 to '
            . (string) $response->headers->get('Location')
            . ' with session error: ' . json_encode(session()->get('error'))
        );

        /* ---------------- the state, which is the point of the journey ---------------- */

        $orders = $this->orders()->get();

        $this->assertCount(1, $orders, 'the journey completed and wrote no order row.');

        $order = $orders->first();

        $this->assertSame((int) $store->id, (int) $order->store_id,
            'the order was written against another tenant.');
        $this->assertSame(1, (int) $order->is_guest, 'a guest order must be recorded as one.');
        /*
         * A GUEST ORDER DOES CARRY A `customers` ROW, AND THAT IS THE PRODUCT'S CONTRACT RATHER
         * THAN A DEFECT — measured here, having first asserted the opposite and been refuted.
         * `PaymentController::handleCustomerRegistration()`'s guest arm looks the address up and
         * creates the row when it is new; `is_guest` records that no account LOGIN was used.
         *
         * So the assertion worth making is the one the owner safeguard of 2026-11-07 installed:
         * that the row belongs to THIS store. Its own note records why nothing else protects it —
         * *"a guest checkout is ANONYMOUS, `StoreScope::resolveTenant()` returns `MODE_UNRESOLVED`,
         * and `null_tenant => 'open'` means the scope adds NOTHING. The one path where the shopper
         * is not signed in is the one path the scope does not cover."* Read through
         * `withoutGlobalScopes()`, because `Customer` IS one of the eleven models that really
         * filter and a scoped read would answer this question against a resolved tenant instead
         * of against the table.
         */
        $this->assertGreaterThan(0, (int) $order->customer_id,
            'the guest order was attached to no customers row at all.');

        $attached = Customer::withoutGlobalScopes()->find((int) $order->customer_id);

        $this->assertNotNull($attached, 'the order names a customer row that does not exist.');
        $this->assertSame((int) $store->id, (int) $attached->store_id,
            "the guest order was attached to ANOTHER store's customer row, which is the "
            . 'cross-tenant write the 2026-11-07 owner safeguard closed.');
        $this->assertSame('cod', (string) $order->payment_type);
        $this->assertEqualsWithDelta(self::PRICE, (float) $order->final_price, 0.001,
            'the order total is not the basket total.');
        $this->assertNotSame('', (string) $order->product_order_id,
            'the order has no reference, so the order-complete page has nothing to show.');

        $lines = json_decode((string) $order->product_json, true);

        $this->assertIsArray($lines);
        $this->assertCount(1, $lines, 'the order recorded the wrong number of lines.');
        $this->assertSame((int) $product->id, (int) $lines[0]['product_id']);
        $this->assertSame(1, (int) $lines[0]['qty']);

        /*
         * THE BILLING ROW, read raw. The order-complete and order-detail screens print the
         * billing block, and a journey that wrote an order with no address is not a completed
         * purchase.
         */
        $this->assertSame(1, DB::table('order_billing_details')->where('order_id', $order->id)->count(),
            'the order has no billing detail row, so the shopper gave an address and it was lost.');

        /* THE STOCK MOVED. 04-B: `منع البيع فوق المتاح افتراضياً` is meaningless if a sale does
           not consume stock in the first place. Read raw, because Product is Cachable. */
        $this->assertSame($stockBefore - 1,
            (int) DB::table('products')->where('id', $product->id)->value('product_stock'),
            'the sale did not consume a unit of stock.');

        /* AND THE BASKET IS GONE, so a refresh cannot buy it twice. */
        $this->assertSame(0, Cart::withoutGlobalScopes()->forGuestCookie($cart)->count(),
            'the basket survived the order, so reloading checkout would place it again.');
    }

    /**
     * A GUEST POST WITH NO `billing_info` IS AN HTTP 500, NOT A REFUSAL — AND THAT IS A DEFECT.
     *
     * Found while settling `GAP-345`: `PaymentController::handleCustomerRegistration()` reads
     * `$billing = is_string($request->billing_info) ? (array) json_decode(...) : $request->billing_info;`
     * and hands it to `\Validator::make($billing, [...])`. With the key absent that is
     * `\Validator::make(null, ...)`, whose signature is `make(array $data, ...)` — a TypeError, so
     * the storefront's own order endpoint answers **500** to a request that is merely incomplete.
     *
     * IT IS PINNED RATHER THAN FIXED, deliberately. `PaymentController` is the order writer; a
     * change to its intake is a payments change, and `CLAUDE.md` §2 reserves "material changes to
     * prices/payments" for the owner. What a journey workstream can do is stop it being
     * invisible: this case fails the moment somebody turns the 500 into a refusal, and the
     * message says to update the case rather than the product.
     *
     * NOTE WHAT IS ASSERTED: that NO order row is written. A 500 before any write is a crash; a
     * 500 after a commit would be a half-written order, and CLAUDE.md records exactly that shape
     * as a finding somebody once wrote up as "not an exploit because the request 500'd".
     */
    #[Test]
    public function test_the_order_endpoint_answers_500_rather_than_refusing_a_missing_address(): void
    {
        [, $store, $product] = $this->aTenantWithOneProductInStock();
        $cart = $this->aBasketMintedByTheApplication($store, $product);

        $response = $this->withCookie('cart', $cart)
            ->post('/' . $store->slug . '/process-order', ['payment_type' => 'cod']);

        $this->assertSame(500, $response->status(),
            'the order endpoint no longer answers 500 to a POST with no `billing_info`. If it now '
            . 'refuses properly, that is a fix: assert the refusal here and remove this case\'s '
            . 'docblock claim. Measured answer was ' . $response->status() . '.');

        $this->assertSame(0, $this->orders()->count(),
            'the crash happened AFTER an order row was written, which is a half-written order '
            . 'rather than a crash. That is a materially worse finding than the one this case '
            . 'was written for.');

        $this->assertSame(1, Cart::withoutGlobalScopes()->forGuestCookie($cart)->count(),
            'the basket was consumed by a request that wrote no order.');
    }

    // =============================================================================================
    // JOURNEY 2 — A SIGNED-IN SHOPPER BUYS SOMETHING.
    //
    // 04-A/04-E: `الحساب النهائي على الخادم وحفظ تفاصيل السعر الأصلي في الطلب` (*the final
    // calculation is on the server and the original price detail is saved in the order*). The
    // journey that matters for that is the signed-in one, because it is the only one where the
    // principal and the basket can disagree.
    //
    // IT USES `asAShopper()`, AND THAT IS THE WHOLE REASON THIS IS A SEPARATE JOURNEY. CLAUDE.md:
    // `actingAs($c, 'customers')` also calls `shouldUse('customers')`, so `auth()->user()` — the
    // DEFAULT guard, which `getCurrentStore()` reads — starts answering with the Customer, and a
    // handler that reads the default guard is measured under a tighter scope than production ever
    // supplies. Putting the default guard back is what makes this a shopper rather than a fixture.
    // =============================================================================================

    #[Test]
    public function test_journey_2_a_signed_in_shopper_completes_the_purchase(): void
    {
        [, $store, $product] = $this->aTenantWithOneProductInStock();

        $customer = Customer::forceCreate([
            'first_name' => 'Signed', 'last_name' => 'Shopper',
            'email' => 'shopper-' . $this->uniqueFixtureRef() . '@example.test',
            'password' => bcrypt('x'),
            'store_id' => $store->id,
            'status' => 1,
        ]);

        $this->asAShopper($customer);

        /*
         * THE PREMISE THAT MAKES THIS A PRODUCTION SHAPE. With the default guard back on `web`
         * and nobody in it, `auth()->user()` is null exactly as it is for a real browser request
         * — and it is `auth('customers')` that holds the shopper.
         */
        $this->assertNull(auth()->user(),
            'premise: the DEFAULT guard must be empty, or this test runs under a tenant scope no '
            . 'production request has. See asAShopper().');
        $this->assertSame((int) $customer->id, (int) auth('customers')->id());

        /*
         * A SIGNED-IN BASKET IS NOT A COOKIE BASKET, and that is why this journey has its own
         * basket step rather than reusing `aBasketMintedByTheApplication()`. `CartController`
         * routes a signed-in shopper to the customer-keyed writer, which queues NO `cart` cookie
         * at all -- measured: `getCookie('cart')` was null and the guest helper's own premise
         * caught it. `Cart::scopeForCustomer` is the reader for this half.
         */
        $add = $this->post('/' . $store->slug . '/product_cart', [
            'product_id' => $product->id, 'variant_id' => 0, 'qty' => 1,
        ]);

        $this->assertSame(1, json_decode((string) $add->getContent(), true)['status'] ?? null,
            'premise: the cart endpoint refused the signed-in line: ' . (string) $add->getContent());

        $rows = Cart::withoutGlobalScopes()->where('customer_id', $customer->id)->get();

        $this->assertCount(1, $rows,
            'premise: the signed-in basket is not keyed on the customer, so the order writer '
            . 'below would find nothing and the journey would measure an empty basket.');
        $this->assertSame((int) $store->id, (int) $rows->first()->store_id);

        $response = $this->post(
            '/' . $store->slug . '/process-order',
            ['payment_type' => 'cod'] + $this->theCheckoutFormsOwnFields()
        );

        $this->assertSame(
            url('/' . $store->slug . '/order-complete'),
            (string) $response->headers->get('Location'),
            'the signed-in order POST did not land on order-complete. Location was '
            . (string) $response->headers->get('Location')
            . ', session error: ' . json_encode(session()->get('error'))
        );

        $order = $this->orders()->firstOrFail();

        $this->assertSame((int) $store->id, (int) $order->store_id);
        $this->assertSame(0, (int) $order->is_guest,
            'a signed-in purchase was recorded as a guest order.');
        $this->assertSame((int) $customer->id, (int) $order->customer_id,
            'the order was attached to a different customer than the signed-in principal. The '
            . 'customer must come from the PRINCIPAL, never from request input — CLAUDE.md '
            . 'records that pattern as the most productive source of defects in this codebase.');
        $this->assertEqualsWithDelta(self::PRICE, (float) $order->final_price, 0.001,
            'the server-side total is not the basket total, so 04-E\'s "final calculation on the '
            . 'server" did not happen.');

        /* THE PRICE DETAIL IS IN THE ORDER, which is the other half of 04-E. */
        $lines = json_decode((string) $order->product_json, true);
        $this->assertIsArray($lines);
        $this->assertArrayHasKey('final_price', $lines[0],
            'the order line records no price of its own, so the original price detail was not '
            . 'saved with the order.');
    }

    // =============================================================================================
    // JOURNEY 3 — THE MERCHANT FULFILS THE ORDER.
    //
    // 04-C, verbatim from the recovered plan:
    //   > - الفصل بين حالة الطلب والدفع والتجهيز والشحن.
    //   >   (*separation of order, payment, preparation and shipping status*)
    //   > - Picking وPacking والباركود والملصقات والفواتير.
    //   >   (*picking and packing, barcodes, labels and invoices*)
    //
    // The journey drives the merchant's own screens — list, detail, the four fulfilment
    // transitions, then the two documents — and asserts the SEPARATION: `delivered_status` walks
    // 0 -> 4 -> 5 -> 6 -> 1 while `payment_status` is never written. That is the owner's Option A
    // ("`orders.payment_status` represents the customer's actual payment"), and it is a state
    // claim no status code can carry.
    // =============================================================================================

    #[Test]
    public function test_journey_3_the_merchant_fulfils_the_order_without_touching_payment(): void
    {
        [$merchant, $store, $product] = $this->aTenantWithOneProductInStock();
        $cart = $this->aBasketMintedByTheApplication($store, $product);

        $this->withCookie('cart', $cart)->post(
            '/' . $store->slug . '/process-order',
            ['payment_type' => 'cod'] + $this->theCheckoutFormsOwnFields()
        );

        $order = $this->orders()->firstOrFail();
        $paymentStatusAtCheckout = (string) $order->payment_status;

        $this->assertSame(0, (int) $order->delivered_status,
            'premise: a new COD order starts Pending, or the transitions below are not the ones '
            . 'a merchant makes.');

        /* The merchant signs in. `Manage Order` is what `OrderPolicy::updateStatus()` requires. */
        $this->grantPermission(User::find($merchant->id), 'Manage Order');
        $this->grantPermission(User::find($merchant->id), 'Show Order');
        $merchant = User::find($merchant->id);

        /*
         * BOTH NAMES, AND THEY ARE DIFFERENT GATES. `OrderPolicy::updateStatus()` requires
         * `Manage Order`; `OrderController::order_view()` asks `mayUseOrderScreen('Show Order')`.
         * Granting only the first answered HTTP 403 on the detail screen -- measured -- and a
         * journey that stopped there would have reported the fulfilment transitions untested.
         */
        $this->assertTrue($merchant->isAbleTo('Manage Order'),
            'premise: the merchant does not hold Manage Order, so every transition below would be '
            . 'refused by the policy and the journey would measure the refusal instead.');
        $this->assertTrue($merchant->isAbleTo('Show Order'),
            'premise: the merchant does not hold Show Order, so the detail screen answers 403.');

        /* --- the screens a merchant actually opens --- */
        $this->assertSame(200, $this->actingAs($merchant)->get('/order')->status(),
            'the merchant cannot open the order list.');

        /*
         * THE SEGMENT IS A CIPHERTEXT, not the id. `order_view()` calls `Crypt::decrypt($id)` and
         * `OrderDocumentAccessGuard` resolves the row from the DECRYPTED value, so a raw id is
         * refused 403 by the guard -- measured, and it looks exactly like a permission failure.
         * `Crypt::encrypt()` is an ENCODING in this codebase and not a control (APP_KEY is in git
         * history, owner decision D7); the guard is what decides the row.
         */
        $token = \Illuminate\Support\Facades\Crypt::encrypt($order->id);

        $detail = $this->actingAs($merchant)->get('/order-view/' . $token);

        $this->assertSame(200, $detail->status(), 'the merchant cannot open the order detail.');
        $this->assertStringContainsString((string) $order->product_order_id,
            preg_replace('/\s+/', ' ', (string) $detail->getContent()),
            'the order detail screen does not show the order reference. Asserted against the raw '
            . 'body with whitespace collapsed, because strip_tags() eats the merchant layout\'s '
            . 'inline scripts and silently inverts an absence assertion (CLAUDE.md).');

        /* --- the four fulfilment transitions, each over its real route --- */
        foreach ([
            'confirmed' => 4,
            'pickedup' => 5,
            'shipped' => 6,
            'delivered' => 1,
        ] as $word => $expected) {
            /*
             * `postJson`, BECAUSE THE SCREEN'S OWN CALLER DOES. `statusChangeResponse()` branches
             * on `$request->expectsJson()`: the detail screen posts by ajax and reads
             * `data.status`, while a form post gets `redirect()->back()`. Driving the form shape
             * answered 302 and the assertion on a status code could not tell the refusal arm
             * from the success arm -- the exact trap CLAUDE.md names, since both are 302.
             */
            $move = $this->actingAs($merchant)
                ->postJson('/order-status-change/' . $order->id, ['delivered' => $word]);

            $this->assertSame(200, $move->status(),
                'the ' . $word . ' transition answered HTTP ' . $move->status() . '.');

            /* THE REASON, not only the status: this writer answers `status: false` with its own
               message for an illegal transition, and that is still HTTP 200. */
            $this->assertNotSame(false, $move->json('status'),
                'the ' . $word . ' transition was refused: ' . (string) $move->json('message'));

            $this->assertSame($expected,
                (int) DB::table('orders')->where('id', $order->id)->value('delivered_status'),
                'the order did not move to ' . $word . '. Read raw: Order is Cachable, so an '
                . 'Eloquent read can serve the pre-write row.');
        }

        /*
         * THE SEPARATION. Four fulfilment transitions, including delivery, and payment is where
         * the shopper left it.
         */
        $this->assertSame($paymentStatusAtCheckout,
            (string) DB::table('orders')->where('id', $order->id)->value('payment_status'),
            '04-C asks for the separation of order, payment, preparation and shipping status, and '
            . 'the owner\'s Option A says `orders.payment_status` represents the customer\'s '
            . 'ACTUAL payment. Walking the order to delivered rewrote it from "'
            . $paymentStatusAtCheckout . '".');

        /*
         * --- PICKING, PACKING AND INVOICES: the two documents 04-C names. ---
         *
         * Both take a `Crypt::encrypt()`ed id, which is an ENCODING here and not a control
         * (APP_KEY is in git history); `OrderDocumentAccessGuard` owns the ownership question for
         * both, keyed on the uri.
         */
        $receipt = $this->actingAs($merchant)->get('/order-receipt/' . $token);
        $this->assertSame(200, $receipt->status(), 'the merchant cannot print the invoice.');

        $packing = $this->actingAs($merchant)->get('/order-packing-list/' . $token);
        $this->assertSame(200, $packing->status(),
            'the merchant cannot print the packing list, which 04-C names explicitly.');
        $this->assertStringContainsString($product->name,
            (string) $packing->getContent(),
            'the packing list renders without the line it is a packing list for.');

        /*
         * --- AND THE SCREEN OFFERS THEM, which is the half that was missing. ---
         *
         * `order.packing_list` had a route, a handler, a view, its own `OrderDocumentAccessGuard`
         * entry and `OrderPackingListScreenTest` driving all three of its answers -- and NO screen
         * in the application named it, so a merchant could only reach the picking list by typing
         * the uri. Found by `Tests\Feature\Ui\DeliveredScreenReachabilityTest`; the link is
         * added in the same change as this assertion.
         *
         * ASSERTED ON THE RENDERED HREF of the screen the merchant is already on, not on the
         * route table: the owner's standard is that a feature is reachable "through its intended
         * user journey", and the route existing is what was already true.
         */
        $body = preg_replace('/\s+/', ' ', (string) $detail->getContent());

        /*
         * THE PATH, NOT THE WHOLE HREF, AND THAT IS NOT A WEAKENING. `Crypt::encrypt()` uses a
         * random IV, so it answers a DIFFERENT ciphertext on every call: comparing against
         * `route($name, Crypt::encrypt($id))` built here can never match the page's own href, and
         * the first version of this assertion failed with all three links present on the page.
         * What identifies the link is the route's path; the segment after it is the order.
         *
         * THE PATHS COME FROM THE ROUTE TABLE, so a uri change reddens this instead of silently
         * passing: `route($name, ['id' => '@'])` renders the real uri with a sentinel segment,
         * and the sentinel is cut off again.
         */
        foreach ([
            'order.receipt' => 'the invoice',
            'order.packing_list' => 'the picking and packing list',
            'shippinglabel.pdf' => 'the shipping label',
        ] as $document => $what) {
            $path = substr(route($document, ['id' => '@']), 0, -1);

            $this->assertStringEndsWith('/', $path,
                'premise: ' . $document . ' no longer takes its id as a trailing segment, so the '
                . 'prefix below is not its path.');

            $this->assertStringContainsString('href="' . $path, $body,
                '04-C names picking, packing, labels and invoices. The order detail screen does '
                . 'not offer ' . $what . ' (' . $document . ', expected a link beginning '
                . $path . '), so a merchant can only reach it by typing the uri. Asserted against '
                . 'the raw body with whitespace collapsed (strip_tags() eats this layout\'s '
                . 'inline scripts).');
        }
    }

    // =============================================================================================
    // JOURNEY C — OVERSELLING IS REFUSED AT THE ORDER ROUTE.
    //
    // This one keeps its letter because the plan names it, §2.3, verbatim:
    //
    //   > `tests/Support/Security/bin/verify-stock-oversell-concurrency-on-mysql.php` runs here
    //   > and passes **14/14** on real MariaDB, including the control that the unguarded
    //   > read-modify-write it replaced *does* oversell [...]
    //   > **Journey C in the directive is therefore already covered at the engine level.** P4-7
    //   > extends it to the paths that harness does not drive, rather than rebuilding it.
    //
    // THIS IS THE PATH THAT HARNESS DOES NOT DRIVE. The harness measures the atomic UPDATE under
    // real concurrency; it never issues an HTTP request. What is unmeasured is whether the
    // storefront's own order route carries the refusal through to the shopper — and `CLAUDE.md`
    // warns why that cannot be read off a status code: `ApiResponser::error()` answers **HTTP 200**
    // with `{status: 0}`, which is what `PaymentController` returns for a stock failure. So the
    // assertions are the row count and the stock column.
    // =============================================================================================

    #[Test]
    public function test_journey_c_the_last_unit_cannot_be_sold_twice(): void
    {
        [, $store, $product] = $this->aTenantWithOneProductInStock(1, 'not_allow');

        $this->assertSame('not_allow',
            (string) DB::table('products')->where('id', $product->id)->value('stock_order_status'),
            'premise: only the exact word `not_allow` makes `ProductStockService` refuse, so '
            . 'without it this case measures the backorder arm and not the guard.');

        /* The first shopper takes the only unit. */
        $firstCart = $this->aBasketMintedByTheApplication($store, $product);
        $this->withCookie('cart', $firstCart)->post(
            '/' . $store->slug . '/process-order',
            ['payment_type' => 'cod'] + $this->theCheckoutFormsOwnFields()
        );

        $this->assertSame(1, $this->orders()->count(), 'premise: the first purchase must succeed.');
        $this->assertSame(0,
            (int) DB::table('products')->where('id', $product->id)->value('product_stock'),
            'premise: the first purchase must have taken the last unit, or the second one below '
            . 'is refused for having no basket rather than for having no stock.');

        /*
         * The second shopper's basket is minted AFTER the stock is gone, which is the ordinary
         * case: the cart endpoint does not reserve. 04-B is explicit that reservation happens at
         * order confirmation, not on adding to the basket —
         * `الحجز الآمن عند تأكيد الطلب، لا عند إضافته للسلة`.
         */
        /*
         * A SECOND GUEST, AND ITS BASKET IS INSERTED RATHER THAN MINTED — for a measured reason.
         *
         * `Cart::addtocart_cookie()` keys the row on `session()->getId()`, and within ONE PHPUnit
         * process that id does not change between requests: measured, both "shoppers" received
         * the identical cookie `4kWrbR9S...` and the journey degenerated into one guest retrying,
         * which is a weaker claim than two buyers racing. `flushSession()` plus
         * `Cookie::flushQueuedCookies()` did NOT change it, so it is not the cookie jar.
         *
         * So the second guest gets a row of its own with a cookie value nothing else holds. That
         * is safe here precisely because journey 1 has already proved, over HTTP, that a
         * cart-endpoint-minted cookie and an inserted one are the same thing to this code path —
         * which is the fact `GAP-345` could not establish and is why its own fixture was
         * suspected of being the problem.
         */
        $secondCart = 'second-guest-' . $this->uniqueFixtureRef();

        Cart::forceCreate([
            'cookie_session_id' => $secondCart, 'customer_id' => 0,
            'product_id' => $product->id, 'variant_id' => 0, 'qty' => 1,
            'price' => self::PRICE, 'store_id' => $store->id,
        ]);

        $this->assertNotSame($firstCart, $secondCart,
            'premise: the two shoppers must hold different baskets, or this measures one guest '
            . 'retrying rather than two buyers racing for the last unit.');
        $this->assertCount(1, Cart::withoutGlobalScopes()->forGuestCookie($secondCart)->get(),
            'premise: the second guest\'s basket must be selectable by its own cookie.');

        $second = $this->withCookie('cart', $secondCart)->post(
            '/' . $store->slug . '/process-order',
            ['payment_type' => 'cod'] + $this->theCheckoutFormsOwnFields()
        );

        /* ---- the state, because the status cannot answer this ---- */

        $this->assertSame(1, $this->orders()->count(),
            'the last unit was sold twice: a second orders row exists. HTTP answer was '
            . $second->status() . ' and the stock column reads '
            . (string) DB::table('products')->where('id', $product->id)->value('product_stock'));

        $this->assertSame(0,
            (int) DB::table('products')->where('id', $product->id)->value('product_stock'),
            'the refused order still moved the stock column, so a decrement was applied and not '
            . 'given back.');

        $this->assertGreaterThanOrEqual(0,
            (int) DB::table('products')->where('id', $product->id)->value('product_stock'),
            '04-B: `عدم اعتبار المخزون السالب متاحاً للبيع الفوري` — stock went negative.');

        /*
         * AND THE REFUSAL SAYS SO. A refusal asserted without its reason is the fourteenth way
         * CLAUDE.md records a test passing for the wrong reason: "nothing was written" is also
         * what a crash, an empty basket or an earlier rule produces. The stock failure comes back
         * through `ApiResponser::error()`, so it is HTTP 200 with `status: 0` and a message —
         * and the second basket must still be there, because a refused order must not consume it.
         */
        $this->assertSame(1, Cart::withoutGlobalScopes()->forGuestCookie($secondCart)->count(),
            'the refused order consumed the shopper\'s basket, so retrying after a restock is '
            . 'impossible and the refusal silently destroyed their selection.');
    }

    /**
     * A SHOPPER SIGNED IN THE WAY A BROWSER SIGNS THEM IN.
     *
     * `PlatformUploaderDoorsTest::asAShopper()`, same two lines and the same reason:
     * `actingAs($c, 'customers')` calls `shouldUse('customers')`, which makes the customer the
     * DEFAULT guard's principal — something no production request does — and `getCurrentStore()`
     * then answers for whichever store has a `created_by` equal to the customer's id.
     */
    private function asAShopper(Customer $customer): self
    {
        $this->actingAs($customer, 'customers');
        $this->app['auth']->shouldUse('web');

        return $this;
    }

    /**
     * AND BY DEFAULT IT IS **NOT** REFUSED — 04-B's `افتراضياً` is unsatisfied.
     *
     * The recovered plan's 04-B list, verbatim: `منع البيع فوق المتاح افتراضياً` — *prevent
     * selling above available **by default***. Journey C above proves the guard WORKS; this case
     * measures what happens when nobody has chosen it, which is the state every product the
     * shipped Flutter app has ever created is in (`GAP-182` / `INV-006`: `AddProduct()` and
     * `UpdateProduct()` write the column nowhere, so it holds NULL).
     *
     * `ProductStockService::mustNotOversell()` is `($model->stock_order_status ?? null) ===
     * 'not_allow'`: one exact word refuses, everything else permits. So the plan's word
     * `افتراضياً` is the one part of INV-004 the platform does not do.
     *
     * THIS IS THE RECORDED GAP, NOT A NEW FINDING, and it is driven here because `GAP-182`
     * measured it against `ProductStockService` directly and nothing had driven it over the
     * storefront's own order route to a second `orders` ROW. Two orders for one unit is a
     * different-sized claim from "the reader permits it".
     *
     * WHEN INV-006 CLOSES THIS CASE GOES RED, and that is deliberate: the assertion is on the
     * CURRENT behaviour with its reason attached, so a fix cannot land silently.
     */
    #[Test]
    public function test_a_product_with_no_oversell_choice_sells_its_last_unit_twice(): void
    {
        [, $store, $product] = $this->aTenantWithOneProductInStock(1, null);

        $this->assertNull(DB::table('products')->where('id', $product->id)->value('stock_order_status'),
            'premise: the column must be NULL, which is the state the shipped Flutter admin app '
            . 'leaves every product it creates in.');

        $firstCart = $this->aBasketMintedByTheApplication($store, $product);
        $this->withCookie('cart', $firstCart)->post(
            '/' . $store->slug . '/process-order',
            ['payment_type' => 'cod'] + $this->theCheckoutFormsOwnFields()
        );

        $this->assertSame(1, $this->orders()->count(), 'premise: the first purchase must succeed.');

        $secondCart = 'second-guest-' . $this->uniqueFixtureRef();

        Cart::forceCreate([
            'cookie_session_id' => $secondCart, 'customer_id' => 0,
            'product_id' => $product->id, 'variant_id' => 0, 'qty' => 1,
            'price' => self::PRICE, 'store_id' => $store->id,
        ]);

        $this->withCookie('cart', $secondCart)->post(
            '/' . $store->slug . '/process-order',
            ['payment_type' => 'cod'] + $this->theCheckoutFormsOwnFields()
        );

        $this->assertSame(2, $this->orders()->count(),
            'the second order was refused. If `mustNotOversell()` now defaults to refusing, '
            . 'INV-006 has been closed — rewrite this case to assert the refusal and say so in '
            . 'MASTER-PLAN-GAP-REGISTER. Do not simply delete it.');

        $this->assertSame(-1,
            (int) DB::table('products')->where('id', $product->id)->value('product_stock'),
            'the stock column did not go negative, so the two orders were served from somewhere '
            . 'else and this case no longer measures what it claims.');
    }

    /**
     * A SALE THAT EMPTIES A PRODUCT'S STOCK IS AN HTTP 500 THAT **EATS THE STOCK**.
     * =============================================================================================
     *
     * FOUND BY DRIVING JOURNEY C, not by reading. The first version of that journey used a store
     * with `stock_management = on` and no other settings row — which is an ordinary new store —
     * and the FIRST purchase answered HTTP 500.
     *
     * THE MECHANISM, measured through `withoutExceptionHandling()`:
     *
     *   `PaymentService::getProductStatus()` (the COD and bank-transfer order writer) builds its
     *   settings at `PaymentService.php:2765` as
     *   `Setting::where('store_id', $store->id)->pluck('value', 'name')->toArray()` — a RAW read
     *   of the `settings` table, NOT `Utility::Setting()`, so **no defaults are merged**. Its
     *   stock block then reads `$settings['notification']` with no `??`, inside
     *   `if ($Product->product_stock <= $Product->low_stock_threshold)`. `low_stock_threshold` is
     *   `0` for a product nobody has configured — the column ships `DEFAULT 0`, measured, having
     *   first been written up here as NULL — so the comparison is `0 <= 0`, the branch is taken
     *   the moment stock reaches zero, and `Undefined array key "notification"` is thrown as an
     *   ErrorException.
     *
     * WHY IT LOSES THE STOCK. The decrement has already happened: `decrementStockOrFail()` runs
     * at `:2894`, while `DB::beginTransaction()` is at `:3186` and the `catch` that calls
     * `restoreStockDecrements()` belongs to THAT try. A throw in the stock loop is outside it, so
     * nothing gives the units back. Measured end state, from this case:
     *
     *   HTTP 500 · 0 `orders` rows · `products.product_stock` = 0 · the basket still present
     *
     * i.e. the merchant's stock says the unit is gone, no order says where it went, and the buyer
     * sees an error page. `CLAUDE.md` is explicit that a 500 leaving a half-written state is a
     * finding and not a refusal, and that a test which "sees a 500 and shrugs" is hiding one.
     *
     * NOT FIXED HERE, AND THE REASON IS A RULE RATHER THAN A PREFERENCE. `PaymentService` is the
     * order and money writer; CLAUDE.md §2 reserves "material changes to prices/payments/stock/
     * business rules" for the owner, and the obvious one-character repair (`?? '[]'`) appears
     * **36 times unguarded across three files** — `PaymentService` (12), `Api/ApiController` (12)
     * and `PayUPaymentController` (12), counted over comment-stripped source. Only
     * `PaymentService`'s are measured as live: `ApiController`'s nearest `$settings` assignment
     * before its three stock sites is `Utility::Setting($storeId)` at `:3570`, which DOES merge
     * the `'notification' => '[]'` default, so those are stated as NOT measured rather than
     * claimed safe.
     *
     * SCOPE OF THE DEFECT: any store with `stock_management = on` and no `notification` settings
     * row, on the first sale that takes a product to or below its low-stock threshold. For a
     * product whose threshold is unset that is every sale that empties it.
     */
    #[Test]
    public function test_a_sale_that_empties_a_products_stock_is_an_http_500_that_eats_the_stock(): void
    {
        [, $store, $product] = $this->aTenantWithOneProductInStock(1, 'not_allow');

        /* An ordinary new store: stock management on, notification preferences never saved. */
        DB::table('settings')
            ->where('store_id', $store->id)->where('name', 'notification')->delete();

        $this->assertNull(DB::table('settings')
            ->where('store_id', $store->id)->where('name', 'notification')->value('value'),
            'premise: the store must have NO notification row, which is the state of a store '
            . 'whose owner has not visited the notification settings tab.');
        $this->assertSame(0, (int) DB::table('products')->where('id', $product->id)->value('low_stock_threshold'),
            'premise: the product must have no low-stock threshold set — the column ships '
            . 'DEFAULT 0 (measured: 0, not NULL) — so `0 <= 0` takes the branch the moment the '
            . 'last unit goes. A configured threshold moves WHEN this fires, not WHETHER.');

        $cart = $this->aBasketMintedByTheApplication($store, $product);

        $response = $this->withCookie('cart', $cart)->post(
            '/' . $store->slug . '/process-order',
            ['payment_type' => 'cod'] + $this->theCheckoutFormsOwnFields()
        );

        $this->assertSame(500, $response->status(),
            'the sale no longer answers 500. If `$settings[\'notification\']` has been guarded, '
            . 'this defect is fixed: rewrite this case to assert the completed order and remove '
            . 'the `notification` workaround from aTenantWithOneProductInStock(). Answer was '
            . $response->status() . '.');

        $this->assertSame(0, $this->orders()->count(),
            'an order row was written as well, so the crash is after the commit and the finding '
            . 'is a half-written order rather than a lost unit.');

        $this->assertSame(0,
            (int) DB::table('products')->where('id', $product->id)->value('product_stock'),
            'the stock was given back, so the decrement is inside the transaction after all and '
            . 'this case\'s account of PaymentService:2894 vs :3186 is wrong — correct the '
            . 'docblock rather than the assertion.');

        $this->assertSame(1, Cart::withoutGlobalScopes()->forGuestCookie($cart)->count(),
            'the basket was consumed by a request that wrote no order.');
    }
}
