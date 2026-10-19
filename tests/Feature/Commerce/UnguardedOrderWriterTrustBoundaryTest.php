<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use App\Services\NMIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P01-COMMERCE-SEC-02 -- the two order writers the gateway-return package could not close.
 * =============================================================================================
 *
 * S32's census found NINE order writers, not six. Seven were closed by P01-COMMERCE-SEC-01. The
 * remaining two are here, and they are different from each other and from the seven:
 *
 *   NMIPayController::getNMIProductStatus  (ANY {slug}/get-payment-NMI)
 *     The second CARD CHARGE. The card number, expiry and CVV arrive in this request and the
 *     handler posts the `sale` itself, so what a caller could choose was not only what the ledger
 *     recorded but what the card was CHARGED. It had no guard of any kind: no stored-state
 *     requirement, no quantity bound, no store predicate on the product lookup, a literal 'Paid',
 *     and -- the shape none of the other eight has -- IT READ THE WHOLE BASKET BACK OUT OF A
 *     HIDDEN FORM FIELD. `json_decode($request->input('response_data'), true)` supplied the
 *     product ids, the line quantities and the totals, on the SUCCESS path, with a perfectly
 *     valid session sitting unread.
 *
 *   PaymentController::whatsapp  (POST {slug}/whatsapp, {slug}/telegram)
 *     Both a return and a direct order-placement entry, which is why it KEEPS its
 *     `$request->all()` fallback -- two checkout views post to it without passing through
 *     processOrder(), so there is no state for them to read. Its money was already server-derived
 *     by P01-SEC-14; what was still the caller's was WHOSE order it is and in WHICH store.
 *
 * WHAT THESE TESTS PROVE, AND WHAT THEY DO NOT.
 *
 *   - They prove the WRITER's trust boundary: the basket, the totals, the charged amount, the
 *     order's owner, the store and the stock movements are the server's, and an unusable
 *     quantity is refused before any of it.
 *   - They do NOT prove that NMI verified a payment, and no test here sends a card number
 *     anywhere. NMIService::chargeSale() is substituted so the gateway's ANSWER can be supplied;
 *     that is a seam for the order writer underneath it. Claiming otherwise would be exactly the
 *     "simulated validation mistaken for real gateway verification" the brief forbids.
 *
 * Every assertion below was re-measured with its own guard reverted; the results are recorded in
 * the package report.
 */
class UnguardedOrderWriterTrustBoundaryTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** GuardsGatewayReturn::gatewayReturnRefusal()'s text. */
    private const REFUSAL = 'We could not confirm your checkout details';

    /** GuardsGatewayReturn::gatewayReturnQuantityRefusal()'s text. */
    private const QUANTITY_REFUSAL = 'Your cart contains an invalid quantity';

    /**
     * A merchant, their store, and the rows every one of these handlers needs before it can be
     * measured at all.
     *
     * Deliberately the same fixture shape as GatewayReturnTrustBoundaryTest::tenant(), including
     * the two reasons it seeds what it seeds:
     *
     *  - `out_of_stock_threshold` / `notification`: the raw settings pluck these handlers use
     *    supplied no defaults, so a store without them raised "Undefined array key" and 500d
     *    EVERY legitimate order. That crash is FIXED in this package and has its own test below
     *    (test_a_store_without_the_stock_notification_settings_still_completes_its_order), which
     *    is why this fixture may keep seeding them: the other tests must measure the trust
     *    boundary, not the crash.
     *  - `countries`/`states`/`cities`: these writers INSERT a city from billing_info, so without
     *    the FK parents they raise a FOREIGN KEY violation and 500 BEFORE the order write -- and
     *    every assertion about the order would then pass vacuously.
     *
     * @param  string  $shippingMethod  the plan's `shipping_method`; 'on' reaches the branch that
     *                                  read an unassigned variable for the order total.
     * @return array{0: \App\Models\User, 1: \App\Models\Store}
     */
    private function tenant(string $shippingMethod = 'off', array $omitSettings = []): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'uow-plan-' . uniqid(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => $shippingMethod,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->save();
        Cache::forget('admin_details');

        $settings = [
            'stock_management' => 'on',
            'out_of_stock_threshold' => '0',
            'notification' => '[]',
        ];

        foreach ($settings as $name => $value) {
            if (in_array($name, $omitSettings, true)) {
                continue;
            }

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

        return [$merchant, $store];
    }

    private function customerIn($store, string $label): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Uow', 'last_name' => $label,
            'email' => 'uow-' . $label . '-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    private function productIn($store, float $price = 100.00, int $stock = 50): Product
    {
        return Product::forceCreate([
            'name' => 'uow-' . uniqid(), 'slug' => 'uow-' . uniqid(),
            'product_stock' => $stock, 'track_stock' => 1, 'variant_product' => 0,
            'price' => $price, 'sale_price' => $price,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    /** A guest cart row plus the cookie value that reaches it. */
    private function guestCart($store, Product $product, $qty = 1, float $price = 100.00): string
    {
        $sid = 'uow-cookie-' . uniqid();

        Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => $qty, 'price' => $price, 'store_id' => $store->id,
            'cookie_session_id' => $sid,
        ]);

        return $sid;
    }

    private function billing(): array
    {
        return [
            'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.test',
            'billing_user_telephone' => '123', 'billing_address' => 'x',
            'billing_postecode' => '1', 'billing_country' => '101',
            'billing_state' => '1', 'billing_city' => '1', 'billing_company_name' => '',
            'delivery_address' => 'x', 'delivery_postcode' => '1',
            'delivery_country' => '101', 'delivery_state' => '1', 'delivery_city' => '1',
        ];
    }

    /**
     * The state PaymentService::getNMIProductview() stores, with the `cartlist` the NMI handler
     * now reads instead of the hidden field.
     *
     * `cartlist` is the server's priced cart: processOrder() -> prepareFinalRequestData()
     * (PaymentController.php:1716-1758) merges it, alongside `cartlist_final_price`, which is
     * products + server shipping + server tax -- the figure the card is charged.
     */
    private function nmiCheckoutState($store, Product $product, array $overrides = []): array
    {
        return array_merge([
            'slug' => $store->slug,
            'store_id' => $store->id,
            'customer_id' => '',
            'payment_type' => 'nmi',
            'shipping_final_price' => 0,
            'tax_price' => 0,
            'cartlist_final_price' => 100.00,
            'coupon_info' => null,
            'billing_info' => $this->billing(),
            'cartlist' => [
                'final_price' => 100.00,
                'total_final_price' => 100.00,
                'total_sub_price' => 100.00,
                'total_tax_price' => 0,
                'product_list' => [
                    [
                        'product_id' => $product->id,
                        'variant_id' => 0,
                        'qty' => 1,
                        'price' => 100.00,
                        'name' => $product->name,
                    ],
                ],
            ],
        ], $overrides);
    }

    /**
     * Substitute NMI's ANSWER, and record what the controller asked it to charge.
     *
     * NOT a gateway double in the sense of pretending to verify anything: the only thing this
     * supplies is the `response` code the real handler's order write has always been nested
     * inside. What it makes observable is the `amount` the controller chose -- the one field on
     * this path that decides what a real card would be debited.
     */
    private function fakeNmi(array $answer = ['response' => 1, 'transactionid' => 'T-1', 'amount' => '100.00'])
    {
        $fake = new class($answer) extends NMIService {
            /** @var array<int, array<string, mixed>> */
            public array $calls = [];

            /** @var array<string, mixed> */
            private array $answer;

            public function __construct(array $answer)
            {
                parent::__construct();
                $this->answer = $answer;
            }

            public function chargeSale(array $formParams): array
            {
                $this->calls[] = $formParams;

                return $this->answer;
            }
        };

        $this->app->instance(NMIService::class, $fake);

        return $fake;
    }

    private static function orderCount(): int
    {
        return (int) DB::table('orders')->count();
    }

    private static function stockOf(Product $product): int
    {
        return (int) Product::withoutGlobalScopes()->find($product->id)->product_stock;
    }

    private function nmiCardFields(): array
    {
        return [
            'nmi_card_number' => '4111111111111111',
            'nmi_expiration_date' => '2030-12',
            'nmi_cvv_code' => '123',
            'nmi_cardholder_name' => 'A B',
        ];
    }

    // =====================================================================================
    //  NMI -- THE POSITIVE CONTROL FIRST, so nothing below can be satisfied by a handler
    //  that simply refuses everything.
    // =====================================================================================

    public function test_a_legitimate_nmi_charge_still_writes_its_order(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store, 100.00, 50);
        $sid = $this->guestCart($store, $product);
        $gateway = $this->fakeNmi();

        $before = self::orderCount();

        $response = $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $this->nmiCheckoutState($store, $product)])
            ->post('/' . $store->slug . '/get-payment-NMI', $this->nmiCardFields());

        $this->assertCount(1, $gateway->calls,
            'The handler never reached the gateway, so this is not a positive control. Response: '
            . $response->getStatusCode());

        $this->assertSame($before + 1, self::orderCount(),
            'A legitimate NMI charge wrote no order, so every refusal asserted in this file could '
            . 'be satisfied by a handler that refuses everything.');

        $order = DB::table('orders')->orderByDesc('id')->first();
        $this->assertSame(0, (int) $order->customer_id, 'A guest order acquired an owner.');
        $this->assertSame(1, (int) $order->is_guest, 'A guest order was not marked as one.');
        $this->assertSame(100.00, round((float) $order->final_price, 2),
            "The order did not carry the server's price for the guest's own basket.");
        $this->assertSame('Paid', $order->payment_status,
            'The derived status changed the behaviour of a verified NMI capture.');
        $this->assertSame(49, self::stockOf($product), 'A completed order did not consume its stock.');
    }

    /**
     * THE CARD IS CHARGED THE SERVER'S FIGURE.
     *
     * The old line was `$cartlist_final_price = $requests_data['cartlist_final_price']` taken raw,
     * and with no stored state at all that key came from the caller. Here the state is present
     * and correct and the POST carries the attacker's figures for every money field the handler
     * reads -- the amount handed to the gateway must still be the state's 100.00.
     */
    public function test_the_amount_charged_to_the_card_is_the_servers(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store, 100.00, 50);
        $sid = $this->guestCart($store, $product);
        $gateway = $this->fakeNmi();

        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $this->nmiCheckoutState($store, $product)])
            ->post('/' . $store->slug . '/get-payment-NMI', array_merge($this->nmiCardFields(), [
                'cartlist_final_price' => 1,
                'final_price' => 1,
                'amount' => 1,
                'shipping_final_price' => -500,
                'tax_price' => -500,
            ]));

        $this->assertCount(1, $gateway->calls, 'The charge never happened, so this proves nothing.');
        $this->assertSame(100.00, round((float) $gateway->calls[0]['amount'], 2),
            'The browser chose what the card was charged.');

        $order = DB::table('orders')->orderByDesc('id')->first();
        $this->assertSame(100.00, round((float) $order->final_price, 2),
            "The posted money reached the order's total.");
    }

    /**
     * THE HIDDEN FIELD CANNOT REWRITE THE BASKET -- the defect unique to this handler.
     *
     * `response_data` is the cart the server printed into the payment form
     * (resources/views/front_end/payment/nmi-payment.blade.php:98) and read back on the way in.
     * Here it names ANOTHER MERCHANT'S product, seven units of it, and totals of the caller's
     * choosing, with a completely legitimate session state for this store's one 100.00 line.
     *
     * Nothing about the foreign store may move, and the order must be the server's basket.
     */
    public function test_the_hidden_cart_field_cannot_rewrite_the_basket(): void
    {
        [, $storeA] = $this->tenant();
        [, $storeB] = $this->tenant();
        $mine = $this->productIn($storeA, 100.00, 50);
        $foreign = $this->productIn($storeB, 100.00, 90);
        $sid = $this->guestCart($storeA, $mine);
        $gateway = $this->fakeNmi();

        $forgedCart = [
            'final_price' => 1,
            'total_final_price' => 1,
            'total_sub_price' => 1,
            'product_list' => [
                ['product_id' => $foreign->id, 'variant_id' => 0, 'qty' => 7, 'price' => 1],
            ],
        ];

        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $this->nmiCheckoutState($storeA, $mine)])
            ->post('/' . $storeA->slug . '/get-payment-NMI', array_merge($this->nmiCardFields(), [
                'response_data' => json_encode($forgedCart),
            ]));

        $this->assertCount(1, $gateway->calls, 'The charge never happened, so this proves nothing.');

        $this->assertSame(90, self::stockOf($foreign),
            "Another merchant's stock moved from this store's NMI checkout.");
        $this->assertSame(49, self::stockOf($mine),
            "The server's own basket was not the one that moved stock.");

        $order = DB::table('orders')->orderByDesc('id')->first();
        $this->assertSame(100.00, round((float) $order->final_price, 2),
            'The hidden field rewrote the order total.');
        $this->assertSame((string) $mine->id, (string) $order->product_id,
            'The hidden field rewrote which product the order is for.');
        $this->assertSame((int) $storeA->id, (int) $order->store_id,
            'The order was written to the wrong store.');
    }

    /**
     * A FORGED CHARGE WITH NO STORED STATE DOES NOT EVEN REACH THE GATEWAY.
     *
     * The old opening was `Session::get('request_data')` with a caller fallback, so the whole
     * checkout was the body's. The refusal now happens before the card is touched, which is the
     * stronger ordering: a caller cannot make this endpoint charge a card at all.
     */
    public function test_a_forged_nmi_charge_with_no_stored_state_writes_no_order(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store, 100.00, 50);
        $sid = $this->guestCart($store, $product);
        $gateway = $this->fakeNmi();

        $before = self::orderCount();
        session()->forget('error');

        $this->withCookie('cart', $sid)
            ->post('/' . $store->slug . '/get-payment-NMI', array_merge($this->nmiCardFields(), [
                'slug' => $store->slug,
                'store_id' => $store->id,
                'customer_id' => '',
                'payment_type' => 'nmi',
                'cartlist_final_price' => 1,
                'billing_info' => $this->billing(),
                /*
                 * A COMPLETE, PLAUSIBLE CHECKOUT in the body -- `cartlist` included. That matters
                 * for what this test proves: a body WITHOUT a cartlist is refused by the
                 * empty-basket check a line later, so the state guard would not be isolated and
                 * this test would pass with the old `$request->all()` fallback restored. MEASURED:
                 * it did. With the cartlist present, only the state guard can produce this
                 * refusal.
                 */
                'cartlist' => [
                    'final_price' => 1, 'total_final_price' => 1, 'total_sub_price' => 1,
                    'product_list' => [
                        ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 1, 'price' => 1],
                    ],
                ],
                'response_data' => json_encode([
                    'final_price' => 1, 'total_final_price' => 1, 'total_sub_price' => 1,
                    'product_list' => [
                        ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 1, 'price' => 1],
                    ],
                ]),
            ]));

        $this->assertStringContainsString(self::REFUSAL, (string) session('error'),
            'The state guard did not fire; its refusal is the only observable it alone produces.');
        $this->assertCount(0, $gateway->calls, 'A forged return reached the card gateway.');
        $this->assertSame($before, self::orderCount(), 'A forged NMI return wrote an order.');
        $this->assertSame(50, self::stockOf($product), 'A forged NMI return moved stock.');
    }

    /**
     * A STATE WITH NO USABLE TOTAL REFUSES RATHER THAN CHARGING A GUESS.
     *
     * This is what serverPayableAmount() contributes BEYOND the state guard, and the distinction
     * was measured rather than assumed: with a well-formed state, removing that call changes
     * nothing, because `$requests_data['cartlist_final_price']` is the same number it returns.
     * MEASURED -- all other tests in this file passed with it reverted.
     *
     * What it alone decides is the malformed case: a stored state whose total is absent,
     * non-numeric or not positive. The old line read the key raw and handed whatever it found to
     * the gateway as the `amount` -- including null, '', 'free' or a negative.
     *
     * @dataProvider unusableTotals
     */
    public function test_a_state_with_no_usable_total_charges_nothing($total): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store, 100.00, 50);
        $sid = $this->guestCart($store, $product);
        $gateway = $this->fakeNmi();

        $state = $this->nmiCheckoutState($store, $product);

        if ($total === '__absent__') {
            unset($state['cartlist_final_price']);
        } else {
            $state['cartlist_final_price'] = $total;
        }

        $before = self::orderCount();
        session()->forget('error');

        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $state])
            ->post('/' . $store->slug . '/get-payment-NMI', $this->nmiCardFields());

        $this->assertCount(0, $gateway->calls,
            'A card was charged from a state with no usable total: ' . var_export($total, true));
        $this->assertStringContainsString(self::REFUSAL, (string) session('error'),
            'The payable guard did not refuse a total of ' . var_export($total, true));
        $this->assertSame($before, self::orderCount(), 'An order was written with no usable total.');
        $this->assertSame(50, self::stockOf($product), 'Stock moved with no usable total.');
    }

    public static function unusableTotals(): array
    {
        return [
            'absent' => ['__absent__'],
            'empty string' => [''],
            'not a number' => ['free'],
            'zero' => [0],
            'negative' => [-100],
        ];
    }

    /**
     * AN UNUSABLE LINE QUANTITY IS REFUSED BEFORE THE CARD IS CHARGED.
     *
     * Both bounds, because they are different attacks on the same column: a negative quantity
     * ADDS stock on a `stock - qty` decrement, and an absurd one drives it as far negative as the
     * column goes. Neither is reachable through this platform's own cart UI -- `carts.qty` is
     * written straight from the request by two vendored module routes under plain `web` (S30).
     *
     * The ordering matters and is asserted: refusing AFTER a capture would leave a shopper
     * charged with no order.
     *
     * @dataProvider unusableQuantities
     */
    public function test_an_unusable_quantity_is_refused_before_the_nmi_card_is_charged($qty): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store, 100.00, 50);
        $sid = $this->guestCart($store, $product, $qty);
        $gateway = $this->fakeNmi();

        $state = $this->nmiCheckoutState($store, $product);
        $state['cartlist']['product_list'][0]['qty'] = $qty;

        $before = self::orderCount();
        session()->forget('error');

        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $state])
            ->post('/' . $store->slug . '/get-payment-NMI', $this->nmiCardFields());

        $this->assertStringContainsString(self::QUANTITY_REFUSAL, (string) session('error'),
            'The quantity guard did not fire for qty ' . var_export($qty, true));
        $this->assertCount(0, $gateway->calls,
            'A card was charged for a basket the server then refused. qty ' . var_export($qty, true));
        $this->assertSame($before, self::orderCount(), 'An unusable quantity wrote an order.');
        $this->assertSame(50, self::stockOf($product),
            'An unusable quantity moved stock. qty ' . var_export($qty, true));
    }

    public static function unusableQuantities(): array
    {
        return [
            'negative' => [-5],
            'fractional' => ['-0.5'],
            'absurd' => [2147483647],
            'not a number' => ['many'],
        ];
    }


    /**
     * A REPLAYED NMI RETURN CANNOT CHARGE THE CARD TWICE.
     *
     * The handler never consumed its state, so the same POST could be repeated -- and on THIS
     * handler a repeat is not merely a duplicate order, it is a fresh sale posted to the gateway.
     * There is still no idempotency key (S32); single-use state is the whole of the control and
     * this test says exactly that.
     */
    public function test_a_replayed_nmi_return_cannot_charge_or_order_twice(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store, 100.00, 50);
        $sid = $this->guestCart($store, $product);
        $gateway = $this->fakeNmi();

        $before = self::orderCount();
        $state = $this->nmiCheckoutState($store, $product);

        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $state])
            ->post('/' . $store->slug . '/get-payment-NMI', $this->nmiCardFields());

        $this->assertSame($before + 1, self::orderCount(), 'The first charge wrote no order.');
        $this->assertCount(1, $gateway->calls, 'The first charge never reached the gateway.');

        // The replay: the same request again, with the state already consumed by the first.
        $this->withCookie('cart', $sid)
            ->post('/' . $store->slug . '/get-payment-NMI', $this->nmiCardFields());

        $this->assertSame($before + 1, self::orderCount(), 'A replayed NMI return wrote a second order.');
        $this->assertCount(1, $gateway->calls, 'A replayed NMI return charged the card a second time.');
    }

    /**
     * A GUEST CANNOT CLAIM AN NMI ORDER FOR ANOTHER CUSTOMER.
     *
     * `$order->customer_id` read `$request->customer_id`, and the merge that reconciles the two
     * ran on the authenticated arm only. Same family as 0eee8524 on the gateway returns.
     */
    public function test_a_guest_cannot_claim_an_nmi_order_for_another_customer(): void
    {
        [, $store] = $this->tenant();
        $victim = $this->customerIn($store, 'victim');
        $product = $this->productIn($store, 100.00, 50);
        $sid = $this->guestCart($store, $product);
        $this->fakeNmi();

        $victimCart = Cart::forceCreate([
            'customer_id' => $victim->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 3, 'price' => 100.00, 'store_id' => $store->id,
        ]);

        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $this->nmiCheckoutState($store, $product)])
            ->post('/' . $store->slug . '/get-payment-NMI', array_merge($this->nmiCardFields(), [
                'customer_id' => $victim->id,
            ]));

        $order = DB::table('orders')->orderByDesc('id')->first();
        $this->assertNotNull($order, 'No order was written, so the ownership assertion is vacuous.');
        $this->assertSame(0, (int) $order->customer_id,
            "A guest's posted customer_id became the order's owner.");

        $this->assertNotNull(
            Cart::withoutGlobalScopes()->find($victimCart->id),
            "A guest's posted customer_id emptied another customer's basket."
        );
    }

    /**
     * THE ORDER RECORDS WHAT THE CARD WAS CHARGED, on the shipping-enabled branch.
     *
     * `$order->final_price = $final_sub_total_price` -- a variable this method never assigned.
     * PHP 8 warns and evaluates it as null, so every such order recorded 0.00 while the card was
     * charged the real amount. This is a financial-integrity defect rather than a trust-boundary
     * one, and it is only reachable when the plan has shipping ON.
     */
    public function test_a_shipping_enabled_nmi_order_records_the_amount_charged(): void
    {
        [, $store] = $this->tenant('on');
        $product = $this->productIn($store, 100.00, 50);
        $sid = $this->guestCart($store, $product);
        $gateway = $this->fakeNmi();

        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $this->nmiCheckoutState($store, $product)])
            ->post('/' . $store->slug . '/get-payment-NMI', $this->nmiCardFields());

        $this->assertCount(1, $gateway->calls, 'The charge never happened, so this proves nothing.');

        $order = DB::table('orders')->orderByDesc('id')->first();
        $this->assertNotNull($order, 'No order was written on the shipping-enabled branch.');
        $this->assertSame(
            round((float) $gateway->calls[0]['amount'], 2),
            round((float) $order->final_price, 2),
            'The order total does not match the amount the card was charged.'
        );
        $this->assertGreaterThan(0, (float) $order->final_price,
            'The order recorded 0.00 for a charged card.');
    }

    /**
     * THE PRE-EXISTING SETTINGS CRASH: a store that has never saved its stock-notification
     * settings can complete an order.
     *
     * `$settings['out_of_stock_threshold']` and `json_decode($settings['notification'])` were
     * read raw from a `pluck()` that supplies no defaults, so in such a store EVERY legitimate
     * order 500d -- after the stock had already moved. The same defect P01-SEC-14 C3 fixed in
     * `ordersave`, swept here across the writers that still had it.
     */
    public function test_a_store_without_the_stock_notification_settings_still_completes_its_order(): void
    {
        [, $store] = $this->tenant('off', ['out_of_stock_threshold', 'notification']);
        $product = $this->productIn($store, 100.00, 50);
        $sid = $this->guestCart($store, $product);
        $gateway = $this->fakeNmi();

        $before = self::orderCount();

        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $this->nmiCheckoutState($store, $product)])
            ->post('/' . $store->slug . '/get-payment-NMI', $this->nmiCardFields());

        $this->assertCount(1, $gateway->calls, 'The charge never happened, so this proves nothing.');
        $this->assertSame($before + 1, self::orderCount(),
            'A store without the stock-notification settings could not complete an order.');
        $this->assertSame(49, self::stockOf($product),
            'The order completed but its stock did not move.');
    }

    /**
     * ANOTHER MERCHANT'S STOCK CANNOT BE DECREMENTED THROUGH THIS STORE'S NMI CHECKOUT -- AND
     * THE LINES ALREADY TAKEN ARE GIVEN BACK.
     *
     * TWO guards in one journey, because the second only exists because of the first.
     *
     *   1. THE STORE PREDICATE. `Product` is NOT in config/tenancy.php's `scoped_models`, so
     *      StoreScope adds no predicate for anybody and `Product::find($id)` reached every
     *      tenant's catalogue. The handler resolved its cart lines that way and decremented
     *      whatever came back. Same family as the S19/S32 cross-tenant stock drain.
     *
     *      THE FOREIGN LINE IS IN THE SERVER'S OWN STORED STATE here, not posted -- which is the
     *      case that matters and the one the hidden-field test above cannot reach. `carts.qty`
     *      and `carts.product_id` are both written straight from the request by two vendored
     *      module routes under plain `web` (BoostSales, FrequentlyProduct -- S30), so a foreign
     *      product id can still enter this store's cart there and be priced into the state by an
     *      otherwise ordinary checkout.
     *
     *   2. THE GIVE-BACK. Refusing the foreign line means the handler returns mid-loop -- and the
     *      OWN line before it has already been decremented. The pre-existing code had no
     *      give-back path at all (`$appliedStockChanges` appeared zero times), so on a card
     *      charge the merchant lost that stock with no order to account for it. The own product's
     *      stock must come back to 50.
     *
     * The line ORDER is deliberate: own product first, so there is something to give back.
     */
    public function test_a_foreign_product_in_the_state_cannot_drain_its_merchants_stock(): void
    {
        [, $storeA] = $this->tenant();
        [, $storeB] = $this->tenant();
        $mine = $this->productIn($storeA, 100.00, 50);
        $foreign = $this->productIn($storeB, 100.00, 90);
        $sid = $this->guestCart($storeA, $mine);
        $gateway = $this->fakeNmi();

        $state = $this->nmiCheckoutState($storeA, $mine);
        $state['cartlist']['product_list'][] = [
            'product_id' => $foreign->id, 'variant_id' => 0, 'qty' => 7, 'price' => 100.00,
        ];

        $before = self::orderCount();

        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $state])
            ->post('/' . $storeA->slug . '/get-payment-NMI', $this->nmiCardFields());

        $this->assertCount(1, $gateway->calls,
            'The charge never happened, so the stock assertions below would be vacuous.');

        $this->assertSame(90, self::stockOf($foreign),
            "Another merchant's stock was decremented through this store's NMI checkout.");

        $this->assertSame(50, self::stockOf($mine),
            'The abandoned checkout kept the stock it had already taken: the give-back did not run.');

        $this->assertSame($before, self::orderCount(),
            'An order was written for a basket holding another merchant\'s product.');
    }
}
