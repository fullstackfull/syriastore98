<?php

namespace Tests\Feature\Journeys;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Security\SecurityTestCase;

/**
 * GATE P01-G2 / GAP-022 — THE END-TO-END CUSTOMER JOURNEY, AS ONE EXERCISE.
 * =================================================================================================
 *
 * `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md` §G step 2 asks for:
 *
 *     "End-to-end customer journey: browse -> cart -> checkout (COD and one online gateway) ->
 *      order history -> return request"
 *
 * and §G's preamble says it must be "Run as one exercise, not as a sum of task tests". That last
 * clause is why this file exists at all: every leg below already has task tests somewhere in
 * `tests/Feature/Commerce` and `tests/Feature/Security`, and the register's own words for this row
 * were "No end-to-end customer journey exercise". A sum of task tests cannot show that the state
 * one leg leaves behind is the state the next leg can actually use, which is the only thing a
 * journey proves.
 *
 * SO `test_the_customer_journey_runs_end_to_end_as_one_exercise` IS THE DELIVERABLE. It is one
 * method, one HTTP session, six legs, in order, with no fixture surgery between them: whatever
 * `process-order` writes is what the order-history leg reads, and whatever the fulfilment leg
 * leaves on `orders.delivered_status` is what makes the return request eligible.
 *
 * -------------------------------------------------------------------------------------------------
 * THE ONLINE-GATEWAY LEG IS BLOCKED, AND THAT IS RECORDED HERE RATHER THAN PAPERED OVER
 * -------------------------------------------------------------------------------------------------
 * §G step 2 names TWO payment paths, "COD and one online gateway". COD is exercised end to end
 * below. The online gateway is NOT, and cannot be from this environment: every gateway in
 * `PaymentService` reaches a provider over the network with merchant credentials, and this
 * repository holds none — by design (§H lists "the Authorize.Net SDK and the Stripe keys are
 * unreachable" among the controls that cannot be proven here).
 *
 * `test_the_online_gateway_leg_is_blocked_for_a_named_and_checkable_reason` MEASURES that absence
 * instead of asserting around it, so this file cannot quietly start claiming the gateway leg if a
 * credential ever lands in the environment: the moment one does, that test fails and a human reads
 * it. What it does NOT do is stand in for the leg. There is no faked capture anywhere in this file
 * and no gateway callback is driven. See the evidence document for the exact credential needed.
 *
 * -------------------------------------------------------------------------------------------------
 * HOW THE LEGS ARE KEPT HONEST — the four ways a journey passes for the wrong reason here
 * -------------------------------------------------------------------------------------------------
 *  1. A 302 IS NOT SUCCESS. `PaymentController::processOrder` refuses for about a dozen unrelated
 *     reasons and every one of them is `redirect()->back()->with('error', ...)` — a 302 that looks
 *     exactly like the success redirect. So the checkout leg reads the session's `error` key and
 *     fails naming it, and then asserts the ROW.
 *  2. A 200 IS NOT SUCCESS EITHER on the jQuery endpoints: `Utility::error()` answers HTTP 200
 *     with a status flag, the same shape `ApiResponser::error()` uses. The cart leg reads the flag.
 *  3. AN ELOQUENT READ IS NOT A TABLE READ. `Cart` and `Customer` are two of the eleven models
 *     whose `StoreScope` really filters, and 79 of the 95 models are `Cachable`, so every
 *     state assertion in this file goes through `DB::table()` or `withoutGlobalScopes()`.
 *  4. A LEG THAT NEVER RAN proves nothing, so each leg records itself in `$this->trail` and the
 *     failure message carries the whole trail. A reader of a red run sees where the journey died.
 *
 * THE NEGATIVE CONTROLS ARE NOT DECORATION. Six of them, one per leg, each breaking exactly that
 * leg through the product's own doors (an unknown slug, a zero quantity, an empty basket, another
 * shopper's session, an undelivered order, a second refund request) and asserting the journey's own
 * assertion goes the other way. Without them "the order is visible in the shopper's history" is
 * satisfied by a page that shows every order on the platform, and "a refund row exists" is
 * satisfied by a handler that writes one for anybody.
 */
final class CustomerJourneyTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const OPENING_STOCK = 40;
    private const UNIT_PRICE = 60.00;
    private const BASKET_UNITS = 2;

    /** The legs this exercise has completed, in order, for the failure message. */
    private array $trail = [];

    protected function setUp(): void
    {
        parent::setUp();

        /* The COD writer mails the shopper and the store owner, and the status-change leg mails
           again. Without this the journey dies in the mailer rather than in the application. */
        Mail::fake();
    }

    // =============================================================================================
    //  THE JOURNEY
    // =============================================================================================

    /**
     * ONE EXERCISE: browse -> cart -> checkout (COD) -> order history -> return request.
     *
     * Every leg is driven over HTTP against the real routes a shopper's browser uses. Nothing is
     * inserted between legs except the merchant-side fulfilment move the return leg's eligibility
     * rule requires — and that move is itself made through the merchant's own route, because
     * `order_refund_request` refuses an order that is not delivered and the product is right to.
     */
    public function test_the_customer_journey_runs_end_to_end_as_one_exercise(): void
    {
        $world = $this->storefront();
        [$merchant, $store, $shopper, $product, $shippingId] = $world;

        // ---------------------------------------------------------------- LEG 1 — BROWSE
        $home = $this->get('/' . $store->slug . '/home');
        $this->leg('browse: landing page', $home->getStatusCode(), 200);

        $list = $this->get('/' . $store->slug . '/product-list');
        $this->leg('browse: product list', $list->getStatusCode(), 200);

        $detail = $this->get('/' . $store->slug . '/product/' . $product->slug);
        $this->leg('browse: product detail', $detail->getStatusCode(), 200);
        $this->assertStringContainsString(
            $product->name,
            (string) $detail->getContent(),
            $this->why('the product detail page does not name the product the shopper asked for, so '
                . 'the browse leg reached a page about something else')
        );

        // ---------------------------------------------------------------- LEG 2 — CART
        $added = $this->asAShopper($shopper)->post('/' . $store->slug . '/product_cart', [
            'product_id' => $product->id,
            'variant_id' => 0,
            'qty' => self::BASKET_UNITS,
        ]);

        $this->leg('cart: add to cart', $added->getStatusCode(), 200);
        $this->assertNotSame(
            0,
            (int) ($added->json('status') ?? 1),
            $this->why('add-to-cart answered with the error shape, which is HTTP 200 here: '
                . $this->excerpt($added))
        );

        $line = DB::table('carts')
            ->where('customer_id', $shopper->id)
            ->where('store_id', $store->id)
            ->first();

        $this->assertNotNull($line, $this->why('no carts row exists for this shopper in this store'));
        $this->assertSame(self::BASKET_UNITS, (int) $line->qty,
            $this->why('the cart line does not hold the quantity the shopper asked for'));
        $this->assertSame((int) $product->id, (int) $line->product_id,
            $this->why('the cart line names a different product'));
        $this->trail[] = 'cart: carts row #' . $line->id . ' qty ' . (int) $line->qty;

        $cartPage = $this->asAShopper($shopper)->get('/' . $store->slug . '/cart');
        $this->leg('cart: cart page', $cartPage->getStatusCode(), 200);

        // ---------------------------------------------------------------- LEG 3 — CHECKOUT (COD)
        $checkoutPage = $this->asAShopper($shopper)->get('/' . $store->slug . '/checkout');
        $this->leg('checkout: checkout page', $checkoutPage->getStatusCode(), 200);

        $before = $this->stockOf($product);
        $this->assertSame(self::OPENING_STOCK, $before,
            $this->why('the opening stock is not what the fixture set, so the decrement below '
                . 'cannot be attributed to this order'));

        $placed = $this->codCheckout($store, $shopper, $shippingId);

        /* THE REFUSALS ARE 302s TOO. Read the flash before trusting the status code. */
        $this->assertNull(
            $this->flashedError(),
            $this->why('the COD checkout was REFUSED: ' . (string) $this->flashedError())
        );
        $this->leg('checkout: process-order (cod)', $placed->getStatusCode(), 302);

        $order = DB::table('orders')->where('store_id', $store->id)->orderByDesc('id')->first();
        $this->assertNotNull($order, $this->why('the COD checkout wrote no orders row'));
        $this->trail[] = 'checkout: orders row #' . $order->id . ' ' . $order->product_order_id;

        $this->assertSame('cod', strtolower((string) $order->payment_type),
            $this->why('the order was not written by the COD path'));
        $this->assertSame(
            self::UNIT_PRICE * self::BASKET_UNITS,
            (float) $order->product_price,
            $this->why('the order total is not the basket the shopper built')
        );
        $this->assertSame((int) $shopper->id, (int) $order->customer_id,
            $this->why('the order was not attributed to the shopper who placed it'));

        /*
         * COD IS NOT PAID AT ORDER TIME — owner decision D-06 plus the 2026-10-24 withdrawal of
         * the Delivered-based settlement trigger. Asserted here because an order that counts as
         * paid before the cash is collected is the defect `CodStateMachineTest` was written for,
         * and a journey that did not look would be the obvious place for it to come back.
         */
        $this->assertNotSame('paid', strtolower((string) $order->payment_status),
            $this->why('a cash-on-delivery order was recorded PAID at checkout time, before any '
                . 'cash was collected (owner decision D-06)'));
        $this->trail[] = 'checkout: payment_status ' . $order->payment_status;

        $this->assertSame(
            self::OPENING_STOCK - self::BASKET_UNITS,
            $this->stockOf($product),
            $this->why('the order did not move stock, so the writer was not reached')
        );

        $this->assertSame(
            0,
            (int) DB::table('carts')->where('customer_id', $shopper->id)->count(),
            $this->why('the basket survived the checkout, so a reload would buy it again')
        );

        // ---------------------------------------------------------------- LEG 4 — ORDER HISTORY
        $history = $this->asAShopper($shopper)->get('/' . $store->slug . '/order');
        $this->leg('history: order page', $history->getStatusCode(), 200);

        /*
         * THE ORDER PAGE IS A SHELL, AND MISSING THAT WAS THIS FILE'S SECOND RED RUN.
         *
         * `front_end/pages/order.blade.php` renders the account chrome and then fetches the list
         * over XHR; `front_end/pages/order_list_filter.blade.php` -- the partial that actually
         * prints `#{{ $order->product_order_id }}` at :11 -- is rendered by
         * `AccountProfileController::order_page_filter`, i.e. `GET {slug}/order-filter`. Asserting
         * the reference on the SHELL failed against a working application. A journey has to drive
         * the request the browser really makes, so both are driven here.
         */
        $historyList = $this->asAShopper($shopper)
            ->get('/' . $store->slug . '/order-filter?filter_order=all');
        $this->leg('history: order list (xhr)', $historyList->getStatusCode(), 200);
        $this->assertStringContainsString(
            (string) $order->product_order_id,
            (string) $historyList->getContent(),
            $this->why('the shopper\'s own order history does not name the order they just placed')
        );

        $detailPage = $this->asAShopper($shopper)
            ->get('/' . $store->slug . '/customerorder/' . \Illuminate\Support\Facades\Crypt::encrypt($order->id));
        $this->leg('history: order detail', $detailPage->getStatusCode(), 200);

        // --------------------------------------------- LEG 5 — THE ORDER IS VISIBLE TO BOTH SIDES
        /* §G step 2 ends at the return request, but a shopper's order that the merchant cannot see
           is not an order. The merchant half of the same row is driven in full by
           MerchantJourneyTest; this is the one assertion that belongs to BOTH journeys. */
        $merchantList = $this->onTheAdminSurface()->actingAs($merchant)->get('/order');
        $this->leg('both sides: merchant order list', $merchantList->getStatusCode(), 200);

        $merchantView = $this->onTheAdminSurface()->actingAs($merchant)
            ->get('/order-view/' . \Illuminate\Support\Facades\Crypt::encrypt($order->id));
        $this->leg('both sides: merchant order detail', $merchantView->getStatusCode(), 200);
        $this->assertStringContainsString(
            (string) $order->product_order_id,
            (string) $merchantView->getContent(),
            $this->why('the merchant\'s own order screen does not name the shopper\'s order')
        );

        // ------------------------------------------------- LEG 6 — RETURN REQUEST (needs delivery)
        $delivered = $this->markDelivered($merchant, (int) $order->id);
        $this->leg('return: merchant marks delivered', $delivered->getStatusCode(), 200);
        $this->assertSame('success', (string) $delivered->json('status'), $this->why(
            'the merchant could not move the order to delivered: ' . $this->excerpt($delivered)
        ));

        $this->assertSame(
            1,
            (int) DB::table('orders')->where('id', $order->id)->value('delivered_status'),
            $this->why('the order is not delivered, so the return request below would be refused '
                . 'for a reason that has nothing to do with the leg being exercised')
        );

        $refundForm = $this->asAShopper($shopper)
            ->get('/' . $store->slug . '/order-refund/' . $order->id . '?refund=true');
        $this->leg('return: refund form', $refundForm->getStatusCode(), 200);

        $requested = $this->refundRequest($store, $shopper, (int) $order->id, $product);

        $this->assertNull(
            $this->flashedError(),
            $this->why('the return request was REFUSED: ' . (string) $this->flashedError())
        );
        $this->leg('return: refund request', $requested->getStatusCode(), 302);

        $refund = DB::table('order_refunds')->where('order_id', $order->id)->first();
        $this->assertNotNull($refund, $this->why('the return request wrote no order_refunds row'));
        $this->assertSame((int) $store->id, (int) $refund->store_id,
            $this->why('the refund row was filed against another store'));
        $this->trail[] = 'return: order_refunds row #' . $refund->id . ' ' . $refund->refund_status;

        $returnList = $this->asAShopper($shopper)->get('/' . $store->slug . '/order-return-list');
        $this->leg('return: shopper return list', $returnList->getStatusCode(), 200);

        /* AND THE MERCHANT SEES THE RETURN. The refund request is worthless to a shopper if the
           only person who can act on it never learns of it. */
        $this->grantPermission($merchant, 'Manage Order Refund Request');
        $merchantRefunds = $this->onTheAdminSurface()->actingAs($merchant)->get('/refund-request');
        $this->leg('return: merchant refund queue', $merchantRefunds->getStatusCode(), 200);

        $this->assertGreaterThanOrEqual(
            7,
            count($this->trail),
            'the journey recorded fewer legs than it has: ' . implode(' | ', $this->trail)
        );
    }

    /**
     * THE GATEWAY LEG, MEASURED AS BLOCKED.
     *
     * §G step 2 wants "COD and one online gateway". This test is the second half's honest status:
     * it asserts that no gateway on this platform has a usable credential in this environment, so
     * nobody — including a later reader of this file — can conclude the gateway leg was run.
     *
     * IT FAILS IF A CREDENTIAL APPEARS, which is the point. A sandbox credential is exactly what
     * `GAP-022` says is needed, so the arrival of one must be loud rather than silent.
     *
     * WHY NOT A SKIPPED TEST. `.github/known-test-outcomes.json` declares the Feature suite's
     * outcome SET in both directions and a conditional skip would change it. A test that measures
     * the blockage keeps the declared set intact and still records the blockage.
     */
    public function test_the_online_gateway_leg_is_blocked_for_a_named_and_checkable_reason(): void
    {
        /* POSITIVE CONTROL FIRST, because the first version of this test was VACUOUS and that is
           worth recording: it read `config('services.stripe.secret')` and `config('services.paypal.
           client_id')`, and `config/services.php` DECLARES NEITHER -- `git grep -n stripe
           config/services.php` is empty -- so the probe compared null to null and would have gone
           on passing with a live key in the database. Gateway credentials in this platform are
           PER-STORE `settings` rows, named in `PaymentSettingController::getPaymentGateways()`,
           which is where this looks now. */
        [, $store] = $this->makeTenant();

        DB::table('settings')->insert([
            'name' => 'paypal_client_id', 'value' => 'probe-self-check',
            'created_by' => $store->created_by, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(
            ['paypal_client_id'],
            $this->gatewayCredentialsPresent(),
            'premise: the probe cannot see a gateway credential that IS there, so its empty answer '
            . 'below would mean nothing'
        );

        DB::table('settings')->where('name', 'paypal_client_id')->delete();

        $this->assertSame(
            [],
            $this->gatewayCredentialsPresent(),
            'A GATEWAY CREDENTIAL IS PRESENT IN `settings`. P01-G2 / GAP-022 records the '
            . 'online-gateway leg of the customer journey as blocked for want of a provider '
            . 'sandbox. If that is no longer true, this test is the signal to RUN that leg and '
            . 'close the gap, not to widen this allowance: add the gateway leg to '
            . 'test_the_customer_journey_runs_end_to_end_as_one_exercise and update '
            . 'docs/development/phase-1/PHASE-01-G1-G2-JOURNEY-EVIDENCE.md.'
        );

        /* AND THE ONE CONFIG-FILE GATEWAY KEY THAT DOES EXIST. Paymera is the single provider with
           `config/services.php` entries, and its `status_base_url` already DEFAULTS to Paymera's
           test gateway -- so the host is not what blocks it. What blocks it is the callback
           signature scheme and secret, which is owner item E1 in
           `docs/security/P01-ORD-13-GATEWAY-TEST-REQUIREMENTS.md` §7.1 and is not in this
           repository. Asserted so that a Paymera credential landing in config is as loud as one
           landing in `settings`. */
        foreach (['services.paymera.username', 'services.paymera.terminal_id', 'services.paymera.signature_secret'] as $key) {
            $this->assertEmpty(
                config($key),
                'config(' . $key . ') now carries a value; see the message above'
            );
        }
    }

    /**
     * Which of the platform's real gateway-credential settings hold a value.
     *
     * THE NAMES ARE THE APPLICATION'S OWN, taken from
     * `app/Http/Controllers/PaymentSettingController.php::getPaymentGateways()` -- the one
     * declarative gateway-to-field map in the codebase, and the same `settings.name` values every
     * handler reads through `Utility::GetValueByName()` or `getAdminAllSetting()`. A SECRET half
     * only: a publishable key alone cannot drive a capture, so listing one would make this probe
     * fire on a harmless row.
     *
     * @return list<string>
     */
    private function gatewayCredentialsPresent(): array
    {
        $secretKeys = [
            'stripe_secret_key', 'paypal_client_id', 'paypal_secret_key', 'razorpay_secret_key',
            'paystack_secret_key', 'mollie_api_key', 'flutterwave_secret_key',
            'iyzipay_private_key', 'iyzipay_secret_key', 'mercado_access_token',
            'authorizenet_login_id', 'authorizenet_transaction_key', 'khalti_secret_key',
            'coingate_auth_token', 'PAYMERA_TERMINAL_ID', 'PAYMERA_USERNAME',
            'paymentwall_private_key', 'benefit_secret_key', 'benefit_private_key',
            'sspay_secret_key', 'cinet_pay_secret_key', 'midtrans_secret_key',
            'nmi_api_private_key', 'cashfree_secret_key', 'tap_secret_key',
        ];

        return DB::table('settings')
            ->whereIn('name', $secretKeys)
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->orderBy('name')
            ->pluck('name')
            ->unique()
            ->values()
            ->all();
    }

    // =============================================================================================
    //  NEGATIVE CONTROLS — one per leg. Each breaks that leg and asserts the journey's own
    //  assertion goes the other way, so the green journey above is discriminating.
    // =============================================================================================

    /** LEG 1. The browse assertion is not "every slug answers 200". */
    public function test_control_an_unknown_store_slug_does_not_render_a_storefront(): void
    {
        $this->storefront();

        $response = $this->get('/no-such-store-' . uniqid() . '/product-list');

        $this->assertNotSame(200, $response->getStatusCode(),
            'an unknown store slug rendered a storefront, so the browse leg\'s 200 proves nothing');
    }

    /** LEG 2. The cart assertion is not "the endpoint always writes a row". */
    public function test_control_a_zero_quantity_writes_no_cart_line(): void
    {
        [, $store, $shopper, $product] = $this->storefront();

        $response = $this->asAShopper($shopper)->post('/' . $store->slug . '/product_cart', [
            'product_id' => $product->id,
            'variant_id' => 0,
            'qty' => 0,
        ]);

        $this->assertSame(0, (int) ($response->json('status') ?? 1),
            'a zero quantity was ACCEPTED by add-to-cart');
        $this->assertSame(0, (int) DB::table('carts')->where('customer_id', $shopper->id)->count(),
            'a zero quantity wrote a cart line, so the cart leg\'s row assertion is not '
            . 'discriminating');
    }

    /** LEG 3. The checkout assertion is not "process-order always writes an order". */
    public function test_control_a_checkout_with_no_basket_writes_no_order(): void
    {
        [, $store, $shopper, $product, $shippingId] = $this->storefront();

        /* The fixture seeds no basket, so this is already the empty case; the delete states it
           rather than relying on the fixture staying that way. */
        DB::table('carts')->where('customer_id', $shopper->id)->delete();
        (new Cart)->flushCache();

        $response = $this->codCheckout($store, $shopper, $shippingId);

        $this->assertSame(0, (int) DB::table('orders')->where('store_id', $store->id)->count(),
            'an empty basket wrote an order, so the checkout leg\'s row assertion is satisfied by '
            . 'a handler that writes one for anything');
        $this->assertNotNull($this->flashedError(),
            'an empty basket was not refused with a message the shopper can read');
        $this->assertSame(self::OPENING_STOCK, $this->stockOf($product),
            'an empty basket moved stock');
    }

    /** LEG 4. The history assertion is not "the page lists every order". */
    public function test_control_another_shoppers_order_is_not_in_this_shoppers_history(): void
    {
        [, $store, $shopper, $product, $shippingId] = $this->storefront();

        $this->fillBasket($store, $shopper, $product);
        $this->codCheckout($store, $shopper, $shippingId);
        $order = DB::table('orders')->where('store_id', $store->id)->orderByDesc('id')->first();
        $this->assertNotNull($order, 'premise: the control\'s own checkout wrote an order');

        $stranger = $this->aCustomerOf($store, 'stranger');

        $history = $this->asAShopper($stranger)
            ->get('/' . $store->slug . '/order-filter?filter_order=all');

        $this->assertSame(200, $history->getStatusCode(), 'the history list did not render');
        $this->assertStringNotContainsString(
            (string) $order->product_order_id,
            (string) $history->getContent(),
            'one shopper\'s order appears in another shopper\'s order history, so the history '
            . 'leg\'s assertion is satisfied by a page that lists everything'
        );
    }

    /** LEG 6a. The return assertion is not "the endpoint always writes a refund". */
    public function test_control_a_return_request_on_an_undelivered_order_writes_nothing(): void
    {
        [, $store, $shopper, $product, $shippingId] = $this->storefront();

        $this->fillBasket($store, $shopper, $product);
        $this->codCheckout($store, $shopper, $shippingId);
        $order = DB::table('orders')->where('store_id', $store->id)->orderByDesc('id')->first();
        $this->assertNotNull($order, 'premise: the control\'s own checkout wrote an order');
        $this->assertSame(0, (int) $order->delivered_status, 'premise: the order is not delivered');

        $response = $this->refundRequest($store, $shopper, (int) $order->id, $product);

        $this->assertSame(0, (int) DB::table('order_refunds')->count(),
            'a refund was accepted on an order that was never delivered, so the return leg\'s '
            . 'row assertion is not discriminating');
        $this->assertNotNull($this->flashedError(),
            'the refusal carried no message for the shopper');
    }

    /** LEG 6b. One refund per delivered order, ever — the rule the journey's single row relies on. */
    public function test_control_a_second_return_request_writes_no_second_refund(): void
    {
        [$merchant, $store, $shopper, $product, $shippingId] = $this->storefront();

        $this->fillBasket($store, $shopper, $product);
        $this->codCheckout($store, $shopper, $shippingId);
        $order = DB::table('orders')->where('store_id', $store->id)->orderByDesc('id')->first();

        $this->markDelivered($merchant, (int) $order->id);

        $this->refundRequest($store, $shopper, (int) $order->id, $product);
        $this->assertSame(1, (int) DB::table('order_refunds')->count(),
            'premise: the first return request wrote exactly one row');

        $second = $this->refundRequest($store, $shopper, (int) $order->id, $product);

        $this->assertSame(1, (int) DB::table('order_refunds')->count(),
            'a second return request wrote a SECOND order_refunds row. `Order::refund()` is a '
            . 'hasOne, so two rows make the merchant screen, the shopper list and the eligibility '
            . 'branch each read an arbitrary one.');
        $this->assertNotNull($this->flashedError(),
            'the duplicate was refused silently');
    }


    // =============================================================================================
    //  THE OTHER DIRECTION — does the exercise NOTICE when the application breaks?
    // =============================================================================================

    /*
     * The reasoning, and why the break is applied through a model event rather than by editing
     * source, is recorded once on `MerchantJourneyTest` above this same pair of cases.
     */

    /** The cart leg really reads the row, not the endpoint's own answer. */
    public function test_the_exercise_notices_when_the_cart_stops_recording_the_quantity(): void
    {
        Cart::saving(static function (Cart $line): void {
            $line->qty = 1;
        });

        $failure = $this->runTheJourneyExpectingAFailure();

        $this->assertStringContainsString(
            'does not hold the quantity the shopper asked for',
            $failure,
            'THE JOURNEY DID NOT NOTICE that every cart line came out with qty 1 whatever the '
            . 'shopper asked for. Its cart leg is therefore not evidence for the "cart" step of '
            . 'P01-G2. What it failed on instead: ' . $failure
        );
    }

    /**
     * The checkout leg really holds the COD rule — owner decision D-06 plus the 2026-10-24
     * withdrawal of the Delivered-based settlement trigger.
     *
     * This is the one mutation worth having above all the others: "a cash-on-delivery order counts
     * as paid before the cash is collected" is a defect this codebase has ALREADY had once, and the
     * journey is the obvious place for it to come back unnoticed.
     */
    public function test_the_exercise_notices_when_a_cod_order_is_recorded_paid_at_checkout(): void
    {
        \App\Models\Order::saving(static function (\App\Models\Order $order): void {
            $order->payment_status = 'Paid';
        });

        $failure = $this->runTheJourneyExpectingAFailure();

        $this->assertStringContainsString(
            'was recorded PAID at checkout time',
            $failure,
            'THE JOURNEY DID NOT NOTICE a cash-on-delivery order being written Paid before any '
            . 'cash was collected. What it failed on instead: ' . $failure
        );
    }

    /**
     * Run the exercise and return the assertion message it died on.
     *
     * `AssertionFailedError` and not `Throwable`: a `TypeError` or a query exception is the mutation
     * breaking the fixture rather than the exercise noticing something, and those propagate.
     */
    private function runTheJourneyExpectingAFailure(): string
    {
        try {
            $this->test_the_customer_journey_runs_end_to_end_as_one_exercise();
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            return $e->getMessage();
        }

        $this->fail(
            'THE MUTATION SURVIVED: the customer journey passed with the behaviour above broken, '
            . 'so it is not evidence for that leg. Legs it recorded: ' . implode(' | ', $this->trail)
        );
    }


    // =============================================================================================
    //  PREMISES ABOUT THE HARNESS ITSELF
    // =============================================================================================

    /**
     * THE THEME VIEW ROOT LEAKS BETWEEN REQUESTS IN ONE PROCESS, AND THE MERCHANT PANEL'S LAYOUT
     * NAME IS SHADOWED BY A STOREFRONT FILE. Measured, and pinned here because the journeys above
     * depend on knowing it.
     * ---------------------------------------------------------------------------------------------
     * `ActiveTheme::handle()` calls `Theme::set($store->theme_id)`, which prepends
     * `themes/<theme>/views` to the view finder's path list, and `FileViewFinder` memoises
     * name -> path. One FPM process serves one request, so production is unaffected; a test process
     * serves every request in the file.
     *
     * `themes/stylique/views/layouts/app.blade.php` exists, prints `$store->name`, and answers to
     * `layouts.app` — the MERCHANT PANEL's layout name. So the first admin request after a
     * storefront request renders the storefront layout with no `$store` and answers 500.
     *
     * WHY PIN IT. Without this test the only trace of the finding would be a private helper whose
     * removal makes a journey fail with "Undefined variable $store (View: themes/stylique/views/
     * layouts/app.blade.php)" — a message that points at a file neither journey mentions. It is
     * also the half of the finding that could become a PRODUCTION defect: if any admin surface ever
     * resolves a theme inside its own request, it renders that file.
     *
     * `ThemeDraftPreview`'s own docblock records the same class of leak in the same words, which is
     * why this is stated as a harness fact rather than reported as a defect.
     */
    public function test_premise_the_admin_layout_name_is_shadowed_by_the_stylique_theme(): void
    {
        $this->assertFileExists(
            base_path('themes/stylique/views/layouts/app.blade.php'),
            'the shadowing file is gone, so the reset in onTheAdminSurface() may no longer be '
            . 'needed — re-measure before deleting it'
        );

        [$merchant, $store] = $this->storefront();

        /* A storefront request, which is what registers the theme's view root. */
        $this->get('/' . $store->slug . '/home')->assertOk();

        $this->assertNotNull(
            \Qirolab\Theme\Theme::active(),
            'premise: the storefront request did not activate a theme, so nothing is shadowed and '
            . 'this case is measuring the wrong thing'
        );

        /* WITHOUT the reset: the panel resolves the theme's copy of `layouts.app`. */
        $polluted = $this->actingAs($merchant)->get('/order');

        /* WITH the reset: the panel resolves its own. */
        $clean = $this->onTheAdminSurface()->actingAs($merchant)->get('/order');

        $this->assertSame(200, $clean->getStatusCode(),
            'the merchant order screen does not render even with the theme root cleared, so the '
            . 'reset is not what this case thinks it is');

        $this->assertSame(500, $polluted->getStatusCode(),
            'THE LEAK IS GONE. A panel request after a storefront request in the same process now '
            . 'renders correctly, so `onTheAdminSurface()` is no longer load-bearing and both '
            . 'journeys can drop it. That is an improvement — delete this test and the helper, and '
            . 'say so in docs/development/phase-1/PHASE-01-G1-G2-JOURNEY-EVIDENCE.md.');
    }

    // =============================================================================================
    //  THE FIXTURE
    // =============================================================================================

    /**
     * A provisioned tenant, a shopper, a stocked product, a shipping row and the geography the web
     * checkout needs.
     *
     * `makeTenant()` AND NOT `makeMerchant()`: a storeless merchant is signed OUT of `/dashboard`
     * by `HomeController::index()` (the storeless guard), so the merchant legs of this journey
     * would die at the first screen against a merchant with no store.
     *
     * @return array{0: \App\Models\User, 1: \App\Models\Store, 2: Customer, 3: Product, 4: int}
     */
    private function storefront(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'journey-plan-' . uniqid(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'off', 'max_products' => -1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->save();

        /* Several handlers read the platform admin through `Cache::remember('admin_details', ...)`,
           so without this they see the pre-fixture `User` and `$plan->shipping_method` throws
           before the leg under exercise is reached. */
        Cache::forget('admin_details');
        Cache::forget('super_admin_details');

        foreach ([
            'stock_management' => 'on',
            'out_of_stock_threshold' => '0',
            'notification' => '[]',
        ] as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => $value,
                'created_by' => $merchant->id, 'store_id' => $store->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('countries')->insertOrIgnore([
            'id' => 101, 'name' => 'Syria', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('states')->insertOrIgnore([
            'id' => 1, 'name' => 'Damascus', 'country_id' => 101,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('cities')->insertOrIgnore([
            'id' => 1, 'name' => 'Damascus', 'country_id' => 101, 'state_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $shippingId = (int) DB::table('shippings')->insertGetId([
            'name' => 'standard', 'slug' => 'standard-' . uniqid(),
            'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $shopper = $this->aCustomerOf($store, 'journey');

        $categoryId = (int) DB::table('categories')->insertGetId([
            'name' => 'Textiles', 'slug' => 'textiles-' . uniqid(),
            'trending' => 0, 'status' => 1, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $product = Product::forceCreate([
            'name' => 'Journey Widget', 'slug' => 'journey-widget-' . uniqid(),
            'description' => 'A thing a shopper can buy.',
            'category_id' => $categoryId,
            'product_stock' => self::OPENING_STOCK, 'track_stock' => 1, 'variant_product' => 0,
            'price' => self::UNIT_PRICE, 'sale_price' => self::UNIT_PRICE,
            'stock_status' => 'in_stock', 'status' => 1, 'trending' => 0,
            'store_id' => $store->id, 'created_by' => $merchant->id,
        ]);

        /*
         * NO CART ROW IS SEEDED HERE, and that is deliberate. The journey's cart leg BUILDS the
         * basket through `POST {slug}/product_cart`, so a pre-seeded row would have made that leg
         * measure an increment instead of a creation — the first run of this file seeded one and
         * the leg read qty 4 for a 2-unit add. The controls that start past the cart leg call
         * `fillBasket()` explicitly.
         */

        /* The merchant legs read `isAbleTo()`, and `makeMerchant()` creates a user with no role at
           all — production hands every self-registered merchant the global `admin` role. */
        $this->grantPermission($merchant, 'Manage Order');
        $this->grantPermission($merchant, 'Show Order');

        return [$merchant->fresh(), $store, $shopper, $product, $shippingId];
    }

    /**
     * A basket, for the controls that start PAST the cart leg.
     *
     * The journey itself never calls this: it builds its basket through the storefront's own
     * add-to-cart route, which is the leg §G asks for.
     */
    private function fillBasket($store, Customer $shopper, Product $product): void
    {
        DB::table('carts')->where('customer_id', $shopper->id)->delete();

        Cart::forceCreate([
            'customer_id' => $shopper->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => self::BASKET_UNITS, 'price' => self::UNIT_PRICE * self::BASKET_UNITS,
            'store_id' => $store->id,
        ]);
        (new Cart)->flushCache();
    }

    private function aCustomerOf($store, string $marker): Customer
    {
        return Customer::forceCreate([
            'first_name' => ucfirst($marker), 'last_name' => 'Shopper',
            'email' => $marker . '-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    /**
     * LEAVE THE STOREFRONT BEFORE ENTERING THE PANEL — a harness fact a cross-surface journey
     * cannot do without, and the third red run of this file.
     * ---------------------------------------------------------------------------------------------
     * `ActiveTheme::handle()` calls `Theme::set($store->theme_id)`, which `prependLocation()`s
     * `themes/<theme>/views` onto the view finder's path list, and `FileViewFinder` MEMOISES
     * name -> path in `$views`. In production that lasts one FPM process, i.e. one request. In a
     * test ONE application instance serves every request, so the theme root stays prepended into
     * the next one.
     *
     * `themes/stylique/views/layouts/app.blade.php` EXISTS and is a storefront layout that prints
     * `$store->name` at line 8 — and `layouts.app` is the name the MERCHANT PANEL's layout answers
     * to. So the first admin request after a storefront request resolved the panel's layout name to
     * the THEME's file and died on `Undefined variable $store`. MEASURED: `GET /order` answered
     * HTTP 500 for a merchant who, in a process that had made no storefront request, got 200.
     *
     * `Theme::clear()` removes exactly the paths `Theme::set()` added (`ThemeViewFinder::
     * clearThemes()`); the `flush()` is needed as well because `prependLocation()` does not clear
     * the resolved-name cache, so `layouts.app` would keep answering with the theme's file.
     *
     * THIS IS A HARNESS CORRECTION, NOT A PRODUCTION FIX, and the distinction is stated because
     * `ThemeDraftPreview`'s docblock already records the same class of leak in the same words. The
     * shadowed layout NAME is a standing hazard all the same: any admin surface that ever resolves
     * a theme inside its own request would render that file and 500.
     */
    private function onTheAdminSurface(): self
    {
        \Qirolab\Theme\Theme::clear();
        $this->app['view']->getFinder()->flush();

        return $this;
    }

    /**
     * A SIGNED-IN SHOPPER AS PRODUCTION SEES ONE.
     *
     * `actingAs($c, 'customers')` also makes `customers` the DEFAULT guard, so `auth()->user()`
     * and `getCurrentStore()` start answering for a customer id as if it were a merchant's — which
     * hides exactly the class of defect P1-SEC-71 was. The shape here is
     * `PlatformUploaderDoorsTest::asAShopper()`'s.
     */
    private function asAShopper(Customer $customer): self
    {
        $this->actingAs($customer, 'customers');
        $this->app['auth']->shouldUse('web');

        return $this;
    }

    /**
     * The storefront WEB checkout — `PaymentController::processOrder`, payment type `cod`.
     *
     * The billing fields go BOTH nested under `billing_info` and flat, because
     * `processBillingInfo()` and `validateBillingInfo()` read them from different places; the geo
     * values are numeric ids because the web path resolves them against `countries`/`states`/
     * `cities` rows. Shape taken from `WebCheckoutIntakeIdempotencyTest::webCheckout()`.
     */
    private function codCheckout($store, Customer $shopper, int $shippingId)
    {
        $billing = [
            'firstname' => 'Journey', 'lastname' => 'Shopper',
            'email' => 'journey-shopper@example.test',
            'billing_user_telephone' => '555111222',
            'billing_address' => 'Street', 'billing_postecode' => '11111',
            'billing_country' => '101', 'billing_state' => '1', 'billing_city' => '1',
            'billing_company_name' => '',
            'delivery_address' => 'Street', 'delivery_postcode' => '11111',
            'delivery_country' => '101', 'delivery_state' => '1', 'delivery_city' => '1',
        ];

        return $this->asAShopper($shopper)
            ->post('/' . $store->slug . '/process-order', [
                'payment_type' => 'cod',
                'delivery_id' => $shippingId,
                'method_id' => $shippingId,
                'billing_info' => $billing,
            ] + $billing);
    }

    /**
     * The merchant moves the order to `delivered`, THROUGH THE SHIPPED CALLER'S SHAPE.
     *
     * `OrderController::order_status_change` answers `expectsJson()` with JSON and a plain form
     * POST with `redirect()->back()` -- so a plain post is a 302 whether it SUCCEEDED or was
     * refused, and reading the status code alone proves nothing. The order detail page
     * (`resources/views/order/view.blade.php`) posts this by AJAX and reads `data.status`, which
     * is the contract this drives.
     */
    private function markDelivered($merchant, int $orderId)
    {
        return $this->onTheAdminSurface()->actingAs($merchant)
            ->postJson('/order-status-change/' . $orderId, [
                'delivered' => 'delivered',
                'id' => $orderId,
            ]);
    }

    /** What the storefront's refund form posts for a full return of the one line. */
    private function refundRequest($store, Customer $shopper, int $orderId, Product $product)
    {
        return $this->asAShopper($shopper)
            ->post('/' . $store->slug . '/order-refund-request/' . $orderId, [
                'order_id' => $orderId,
                'product_refund_id' => [$product->id],
                'quantity' => [self::BASKET_UNITS],
                'return_price' => [self::UNIT_PRICE * self::BASKET_UNITS],
                'product_sub_total' => self::UNIT_PRICE * self::BASKET_UNITS,
                'refund_reason' => 'Other',
                'custom_refund_reason' => 'it did not fit',
            ]);
    }

    /** Read through the table: `Product` is `Cachable`. */
    private function stockOf(Product $product): int
    {
        return (int) DB::table('products')->where('id', $product->id)->value('product_stock');
    }

    /**
     * The flashed `error` of the request just made.
     *
     * NOT `$response->getSession()`: that reads the `RedirectResponse`'s own session reference,
     * which is null for a redirect built by `redirect()->back()` in this stack — measured on the
     * first run of this file, as "Call to a member function get() on null". The session STORE is
     * where the flash actually lands.
     */
    private function flashedError(): ?string
    {
        $error = $this->app['session.store']->get('error');

        return $error === null ? null : (string) $error;
    }

    private function leg(string $name, int $actual, int $expected): void
    {
        $this->assertSame($expected, $actual, $this->why(
            'LEG FAILED — ' . $name . ' answered HTTP ' . $actual . ', expected ' . $expected
        ));

        $this->trail[] = $name . ' -> ' . $actual;
    }

    private function why(string $message): string
    {
        return $message . "\n    legs completed: "
            . ($this->trail === [] ? '(none)' : implode(' | ', $this->trail));
    }

    private function excerpt($response): string
    {
        return substr(preg_replace('/\s+/', ' ', (string) $response->getContent()), 0, 400);
    }
}
