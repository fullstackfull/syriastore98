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

    /**
     * A product that really IS a variant product, plus one variant row.
     *
     * `productIn()` hardcodes `variant_product => 0`, and that is what made the fourth
     * post-charge crash below invisible: the OrderNote loop branches on
     * `$product_data->variant_product == 0` (NMIPayController:1315), so with every fixture
     * product non-variant the `else` arm was never entered by ANY test in this file. The
     * existing 'variant line whose variant row is gone' case sets the LINE's `variant_id` but
     * leaves the product non-variant, and it is refused before the OrderNote loop is reached --
     * so it does not cover this either.
     *
     * `manage_stock` is in `variation_option` so the stock loop's variant branch really moves
     * stock (NMIPayController:757-773); without it the line succeeds but moves nothing, which
     * would make the stock assertion vacuous.
     *
     * @return array{0:Product,1:int} the product and its variant row id
     */
    private function variantProductIn($store, float $price = 100.00, int $stock = 50): array
    {
        $product = Product::forceCreate([
            'name' => 'uow-var-' . uniqid(), 'slug' => 'uow-var-' . uniqid(),
            'product_stock' => 0, 'track_stock' => 1, 'variant_product' => 1,
            'price' => $price, 'sale_price' => $price,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);

        $variantId = (int) DB::table('product_variants')->insertGetId([
            'product_id' => $product->id,
            'variant' => 'S-Red',
            'variation_price' => $price,
            'price' => $price,
            'stock' => $stock,
            'low_stock_threshold' => 0,
            'variation_option' => 'manage_stock',
            'sku' => 'UOW-' . uniqid(),
            'store_id' => $store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$product, $variantId];
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
            /*
             * P1-PAY-02. THE FIXTURE CARRIES THE NONCE BECAUSE WITHOUT IT ONE TEST HERE CANNOT
             * BITE. PaymentService::process() stamps `checkout_nonce` on every real checkout, and
             * GuardsGatewayReturn::claimGatewayReturn() consumes it. A state WITHOUT one takes the
             * documented fail-open branch -- there is nothing to claim -- so
             * test_a_declined_card_leaves_the_checkout_retryable below would pass whether or not
             * the handler releases the claim when it puts the state back. It did pass, against a
             * version of this change that burned the claim on a decline and made every retry
             * impossible; an independent review found that, not this suite. With the nonce here,
             * removing the release fails the test.
             */
            'checkout_nonce' => 'nonce-' . uniqid('', true),
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
                /*
                 * `tax_id` IS NOT OPTIONAL IN THIS FIXTURE, and leaving it out cost a real false
                 * reading. `Cart::cart_list_cookie()` always sets it (app/Models/Cart.php:633,
                 * `$cart_array['tax_id'] = $tax_id ?? null`), so a production state always
                 * carries the key -- but the handler reads it bare, and Laravel promotes the
                 * resulting "Undefined array key" warning to an ErrorException. MEASURED: the
                 * positive control below was answering **HTTP 500** while every one of its
                 * database assertions passed, because the crash lands AFTER `$order->save()`.
                 * The fixture now matches what the cart reader produces, the handler's read is
                 * `!empty()`, and both positive controls assert the response as well as the row.
                 */
                'tax_id' => null,
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
        return $this->fakeNmiSequence([$answer]);
    }

    /**
     * The same double, answering a DIFFERENT thing on each successive charge.
     *
     * WHY A QUEUE AND NOT A SECOND `fakeNmi()` CALL, recorded because it cost a real debugging
     * session and the failure mode is silent. `Illuminate\Routing\Route::getController()`
     * CACHES its controller instance:
     *
     *     if (! $this->controller) { $this->controller = $this->container->make($class); }
     *
     * and the RouteCollection lives for the whole test, not for one request. So the controller
     * -- and the NMIService injected into its constructor -- is built once, on the FIRST request
     * to that route. A later `$this->app->instance(NMIService::class, $other)` rebinds the
     * container and changes nothing the handler sees: `app(NMIService::class)` returns the new
     * double while the route keeps charging the old one. MEASURED: the retry in
     * test_a_declined_card_leaves_the_checkout_retryable incremented the FIRST double's call
     * count, and the test failed claiming the retry never reached the gateway.
     *
     * Anything in this file that needs two different gateway answers in one test must therefore
     * queue them on one instance. The last answer repeats once the queue is exhausted.
     *
     * @param  array<int, array<string, mixed>>  $answers
     */
    private function fakeNmiSequence(array $answers)
    {
        $fake = new class($answers) extends NMIService {
            /** @var array<int, array<string, mixed>> */
            public array $calls = [];

            /** @var array<int, array<string, mixed>> */
            private array $answers;

            public function __construct(array $answers)
            {
                parent::__construct();
                $this->answers = array_values($answers);
            }

            public function chargeSale(array $formParams): array
            {
                $this->calls[] = $formParams;

                $index = min(count($this->calls), count($this->answers)) - 1;

                return $this->answers[$index] ?? [];
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

        /*
         * THE RESPONSE, NOT ONLY THE ROW. Without this assertion this control passed while the
         * route answered 500 -- the order committed and the journey then died on a later line. A
         * control that tolerates a 500 cannot support the twenty-three refusal assertions that
         * lean on it, which is this project's "a test must bite" rule applied to the control
         * itself.
         */
        $response->assertRedirect(route('order.complete', $store->slug));

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
     *
     * A REGRESSION FENCE, NOT PROOF OF A GUARD, and the difference was measured: with a
     * well-formed state present, reverting serverPayableAmount() to the raw read leaves this
     * test green, because the two return the same number and nothing on this path consults
     * `$request` for money at all. What that helper alone decides is the MALFORMED state --
     * see test_a_state_with_no_usable_total_charges_nothing, which does fail when it goes.
     * Kept because this structure is what a future edit could break silently.
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
     * WHICH ASSERTION CARRIES THE WEIGHT, PER DATA SET -- stated because three of the four
     * stock assertions here are STRUCTURALLY DEAD and an adversarial review was right to say
     * so. `ProductStockService::normaliseQuantity()` returns null for anything that is not a
     * positive integer, so for `negative`, `fractional` and `not a number` the decrement is
     * never issued and `assertSame(50, …)` cannot move whether or not this guard exists. Only
     * `absurd` (2147483647) would really move stock without it. The load-bearing assertions for
     * the other three are the refusal message, `assertCount(0, $gateway->calls)` and the order
     * count. This is the same per-data-set vacuity S32 finding 7 records for the previous
     * package, disclosed here rather than left implied.
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
     * The same quantities MINUS the non-numeric one, for the handlers that price the cart from
     * the DATABASE before any guard can look at it.
     *
     * NOT A CONVENIENCE -- a measured pre-existing defect, recorded here rather than papered
     * over. `whatsapp()` reads its basket through `Cart::cart_list_cookie()`, and that model
     * multiplies `$cart_value->qty` with no cast (app/Models/Cart.php:285, :288, :294 and nine
     * more sites). A `carts.qty` of 'many' therefore raises
     *
     *     TypeError: Unsupported operand types: int * string
     *
     * inside the cart read at PaymentController.php:3171, BEFORE the quantity guard this package
     * added. MEASURED with withoutExceptionHandling() against the real route.
     *
     * It FAILS CLOSED -- 500, no order, no stock movement -- so it is an availability defect in a
     * shared storefront model rather than a trust-boundary hole, and fixing it means casting
     * every one of those twelve sites in a model that every cart read on the platform goes
     * through. That is a different change from this package and is reported, not attempted. The
     * NMI case above DOES drive this quantity, because that handler's guard reads the stored
     * state's cartlist rather than the database rows.
     */
    public static function unusableQuantitiesReachingTheCartRead(): array
    {
        $quantities = self::unusableQuantities();
        unset($quantities['not a number']);

        return $quantities;
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

        /*
         * AND THE REFUSAL NAMES ITSELF. Without this the test asserts only that nothing
         * happened, which a 500 would satisfy just as well as the guard does -- the trap this
         * project's own records call "tests that pass merely because the route crashes".
         */
        $this->assertStringContainsString(self::REFUSAL, (string) session('error'),
            'The replay was not refused by the state guard; something else stopped it.');
    }

    /**
     * A GUEST CANNOT CLAIM AN NMI ORDER FOR ANOTHER CUSTOMER BY POSTING THE ID ON THE RETURN.
     *
     * `$order->customer_id` read `$request->customer_id` and this handler had no merge at all,
     * so the posted value became the order's owner and the subject of the cart delete. Same
     * family as 0eee8524 on the gateway returns.
     *
     * THE TITLE IS NARROWED ON PURPOSE, as S32 finding 1 already forced the previous package to
     * narrow its equivalent: a guest can still have an order attributed to a victim through the
     * E-MAIL, at initiation -- `handleCustomerRegistration` does
     * `Customer::where('email', $billing['email'])->first()` (PaymentController.php:1310),
     * find-or-create by a posted address with no proof of control, and that id becomes the
     * state's `customer_id`, which adoptStoredCheckoutState() then faithfully restores. That is
     * an owner decision recorded in S32, not something this test covers. What it proves is
     * exactly its name.
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
     * THE IDENTITY PIN COVERS A KEY THE STATE OMITS -- the case `merge()` cannot reach.
     *
     * WHY THIS TEST EXISTS, and it is the same reason GatewayReturnTrustBoundaryTest needed its
     * equivalent. Every state built by `nmiCheckoutState()` SETS `customer_id` (to `''`), so a
     * plain `$request->merge($state)` already overwrites a posted victim id and the test above
     * passes with `adoptStoredCheckoutState()` reduced to that bare merge. `Request::merge()`
     * can only overwrite a key the array actually carries -- and a guest state is precisely the
     * case that omits it, because there was no customer to record.
     *
     * So this is the only fixture in the file that isolates the PIN rather than the merge, and
     * without it the pin would re-enter the record as an unproven guard. An adversarial review
     * of this package caught that it was missing.
     */
    public function test_the_nmi_identity_pin_covers_a_key_the_state_omits(): void
    {
        [, $store] = $this->tenant();
        $victim = $this->customerIn($store, 'pin-victim');
        $product = $this->productIn($store, 100.00, 50);
        $sid = $this->guestCart($store, $product);
        $gateway = $this->fakeNmi();

        $state = $this->nmiCheckoutState($store, $product);
        unset($state['customer_id']);

        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $state])
            ->post('/' . $store->slug . '/get-payment-NMI', array_merge($this->nmiCardFields(), [
                'customer_id' => $victim->id,
            ]));

        $this->assertCount(1, $gateway->calls, 'The charge never happened, so this proves nothing.');

        $order = DB::table('orders')->orderByDesc('id')->first();
        $this->assertNotNull($order, 'No order was written, so the ownership assertion is vacuous.');
        $this->assertSame(0, (int) $order->customer_id,
            'With `customer_id` absent from the stored state, the POSTED id became the order\'s '
            . 'owner -- which is exactly what a bare merge cannot prevent.');
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
     * THREE WAYS A CHARGED CARD USED TO END IN A 500, and the reason they are one test each.
     *
     * All three share a shape that makes them worse than an ordinary crash: they throw AFTER
     * `chargeSale()` has captured the money. The shopper is debited and sees a stack trace, and
     * on two of the three the order row does not exist either. Laravel promotes every PHP
     * warning to an `ErrorException`, so "Undefined array key" and "Attempt to read property on
     * null" are 500s in this application, not silent nulls.
     *
     * None of these is an attack. They are reachability defects in state a real shopper can
     * have, and each was found by a guard-removal check reporting that the fix it was supposed
     * to prove failed NOTHING -- i.e. the fixture was too tidy to reach it.
     *
     * @dataProvider postChargeCrashCases
     */
    public function test_a_charged_card_does_not_end_in_a_crash(string $case): void
    {
        [$merchant, $store] = $this->tenant();

        /*
         * F89. This case needs a product whose `variant_product` is NOT 0, because the branch it
         * reaches is selected on that column and nothing else in this file has one.
         */
        $variantCase = in_array($case, [
            'successful order for a real variant product',
            'variant order that trips the low-stock notification',
        ], true);
        $variantId = 0;

        if ($variantCase) {
            [$product, $variantId] = $this->variantProductIn($store, 100.00, 50);
        } else {
            $product = $this->productIn($store, 100.00, 50);
        }

        $sid = $this->guestCart($store, $product);
        $gateway = $this->fakeNmi();

        $state = $this->nmiCheckoutState($store, $product);

        if ($variantCase) {
            $state['cartlist']['product_list'][0]['variant_id'] = $variantId;
        }

        if ($case === 'variant order that trips the low-stock notification') {
            /*
             * F99. The four sites at NMIPayController:781, :792, :817 and :828 pass the ARRAY cart
             * line into `Utility::variant_low_stock_threshold()` / `variant_out_of_stock()`, whose
             * FIRST statement -- outside their own try/catch -- is
             * `Product::find($product->product_id)`. Object syntax on an array again, same
             * migration miss as F89, but these land BEFORE `new Order()`: the card is captured
             * (:327), the stock is decremented (:773), and then the request dies with no order row
             * and the checkout claim already consumed, so the retry is refused too.
             *
             * Three merchant settings have to line up, which is exactly why no existing case here
             * reached it: stock management on (the shared fixture already sets it),
             * `enable_low_stock` in `notification`, and `twilio_setting_enabled` on. Plus a
             * threshold the decrement actually trips -- 50 units going to 49 against a threshold
             * of 100.
             */
            DB::table('settings')->where('store_id', $store->id)
                ->where('name', 'notification')->update(['value' => '["enable_low_stock"]']);
            DB::table('settings')->insert([
                'name' => 'twilio_setting_enabled', 'value' => 'on',
                'created_by' => $merchant->id, 'store_id' => $store->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('product_variants')->where('id', $variantId)
                ->update(['low_stock_threshold' => 100]);
        }

        if ($case === 'state carries no tax_id') {
            /*
             * `if ($response['tax_id'])` was a bare index. Production states always carry the key
             * (Cart.php:633 writes `$tax_id ?? null`), so this is robustness rather than a live
             * path -- but it is the exact omission that was MEASURED hiding an HTTP 500 behind
             * this file's own positive control, which is why it is pinned.
             */
            unset($state['cartlist']['tax_id']);
        }

        if ($case === 'variant line whose variant row is gone') {
            /*
             * A cart row naming a variant that no longer exists for its product. The handler did
             * `explode(',', $ProductStock->variation_option)` BEFORE `if (!empty($ProductStock))`,
             * so this was a null property read rather than the handler's own "Product not found"
             * path -- and the five sibling gateway controllers still have it that way round.
             */
            $state['cartlist']['product_list'][0]['variant_id'] =
                ((int) DB::table('product_variants')->max('id')) + 500;
        }

        if ($case === 'platform has no plan to read shipping from') {
            /*
             * `$plan` was only assigned inside `if ($user->type == 'admin')` and
             * `$plan->shipping_method` was then read four times. A platform whose admin row is
             * `super admin`, or whose `plan_id` no longer resolves, died on a null property read
             * -- after the capture. The sibling `whatsapp()` was guarded for this in the same
             * package; this handler was not until the removal check said the guard proved
             * nothing.
             */
            $merchant->plan_id = ((int) DB::table('plans')->max('id')) + 500;
            $merchant->save();
            Cache::forget('admin_details');
        }

        $before = self::orderCount();

        $response = $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $state])
            ->post('/' . $store->slug . '/get-payment-NMI', $this->nmiCardFields());

        $this->assertCount(1, $gateway->calls,
            'The charge never happened, so this case says nothing about a charged card. Case: ' . $case);

        $this->assertNotSame(500, $response->getStatusCode(),
            'A captured card ended in a 500. Case: ' . $case);

        if ($case === 'variant line whose variant row is gone') {
            /*
             * This one is REFUSED rather than completed, and that is the correct outcome: the
             * line cannot be fulfilled. What matters is that it is the handler's own refusal
             * instead of a crash, and that the stock it had already taken comes back.
             */
            $this->assertSame($before, self::orderCount(),
                'An order was written for a line whose variant does not exist.');
            $this->assertSame(50, self::stockOf($product),
                'The abandoned line kept the stock it had taken.');

            return;
        }

        $this->assertSame($before + 1, self::orderCount(),
            'A legitimate charge wrote no order. Case: ' . $case);

        if ($variantCase) {
            /*
             * F89. A VARIANT product keeps its quantity on `product_variants.stock`, not on
             * `products.product_stock` -- which this fixture sets to 0 -- so the shared
             * assertion below would be vacuous here. The variant row is the one that must move.
             */
            $this->assertSame(49, (int) DB::table('product_variants')->where('id', $variantId)->value('stock'),
                'The variant order completed but the variant stock did not move.');

            /*
             * AND THE NOTE THE FIX PRESERVES IS REALLY WRITTEN. Without this, deleting the whole
             * `else` arm -- or any future change that made `$variant_data` always null -- would
             * still pass every assertion above, because they only prove the request did not
             * crash. Raised by the independent review of the F89 fix, which is the sort of gap
             * that review exists to find.
             */
            $orderId = (int) DB::table('orders')->orderByDesc('id')->value('id');
            $this->assertDatabaseHas('order_notes', [
                'order_id' => $orderId,
                'status' => 'Stock Manage',
            ]);

            return;
        }

        $this->assertSame(49, self::stockOf($product),
            'The order completed but its stock did not move. Case: ' . $case);
    }

    public static function postChargeCrashCases(): array
    {
        return [
            'no tax_id' => ['state carries no tax_id'],
            'missing variant row' => ['variant line whose variant row is gone'],
            'no plan' => ['platform has no plan to read shipping from'],
            // F89. The fourth, and the one the other three could not reach -- see
            // variantProductIn() for why every fixture here was blind to it.
            'variant product order' => ['successful order for a real variant product'],
            // F99. The fifth. Same array-vs-object migration as F89, four more call sites, and
            // they land BEFORE the order row exists -- so the card is charged, the stock is gone,
            // and there is no order at all. Reachable only with the merchant's notification
            // settings on, which is why no case here had reached it.
            'low stock notification' => ['variant order that trips the low-stock notification'],
        ];
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
     * ANOTHER MERCHANT'S PRODUCT IS REFUSED BEFORE THE CARD IS CHARGED -- on both of the stock
     * loop's branches.
     *
     * WHY THIS TEST CHANGED SHAPE, recorded rather than quietly rewritten. Its first version
     * drove the refusal from INSIDE the stock loop, where a store-scoped `Product::where()`
     * returns null, and asserted "card charged, no order, stock given back" as the correct
     * outcome. Two independent adversarial reviews said that is the wrong outcome and they were
     * right on both counts:
     *
     *   1. The capture had already happened, so the fix produced a shopper who was charged, had
     *      no order, and read "Product not found". The package's own docblock forbids exactly
     *      that ordering three paragraphs above the guard that caused it.
     *   2. The per-line check covered one of the loop's TWO branches. The variant branch
     *      resolves its row with `ProductVariant::where('id', $variant_id)->where('product_id',
     *      $product_id)` -- a predicate built from the caller's own two values -- and
     *      `ProductVariant` carries no StoreScope and is not in config/tenancy.php's
     *      `scoped_models`. So for a line naming another merchant's product AND their matching
     *      variant, that merchant's `product_variants.stock` moved anyway. EVERY fixture in this
     *      file hard-coded `variant_id => 0`, so no test could see it.
     *
     * The guard is now `basketHasForeignProduct()`, before the charge, and this test drives both
     * branches: `variant_id => 0` reaches the product leg, a real variant row reaches the one
     * that was never covered. Nothing may move on either, and the gateway must not be called at
     * all.
     *
     * @dataProvider foreignProductLines
     */
    public function test_a_foreign_product_in_the_state_is_refused_before_the_charge(bool $withVariant): void
    {
        [, $storeA] = $this->tenant();
        [, $storeB] = $this->tenant();
        $mine = $this->productIn($storeA, 100.00, 50);
        $foreign = $this->productIn($storeB, 100.00, 90);
        $sid = $this->guestCart($storeA, $mine);
        $gateway = $this->fakeNmi();

        $foreignVariantId = 0;

        if ($withVariant) {
            $foreignVariantId = DB::table('product_variants')->insertGetId([
                'product_id' => $foreign->id,
                'variant' => 'red',
                'sku' => 'uow-' . uniqid(),
                'price' => 100.00,
                'stock' => 90,
                'store_id' => $storeB->id,
                'variation_option' => 'manage_stock',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $state = $this->nmiCheckoutState($storeA, $mine);
        $state['cartlist']['product_list'][] = [
            'product_id' => $foreign->id, 'variant_id' => $foreignVariantId,
            'qty' => 7, 'price' => 100.00,
        ];

        $before = self::orderCount();
        session()->forget('error');

        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $state])
            ->post('/' . $storeA->slug . '/get-payment-NMI', $this->nmiCardFields());

        $this->assertCount(0, $gateway->calls,
            'A card was charged for a basket naming another merchant\'s product, which the '
            . 'handler then refused.');

        $this->assertStringContainsString('item this store does not sell', (string) session('error'),
            'The foreign-product guard did not fire; its own refusal is the only observable that '
            . 'distinguishes it from the state and quantity guards.');

        $this->assertSame(90, self::stockOf($foreign),
            "Another merchant's product stock moved through this store's NMI checkout.");

        if ($withVariant) {
            $this->assertSame(90, (int) DB::table('product_variants')->where('id', $foreignVariantId)->value('stock'),
                "Another merchant's VARIANT stock moved -- the branch the per-line product "
                . 'predicate could not see.');
        }

        $this->assertSame(50, self::stockOf($mine),
            "This store's own stock moved for a basket that was refused.");

        $this->assertSame($before, self::orderCount(),
            'An order was written for a basket holding another merchant\'s product.');
    }

    public static function foreignProductLines(): array
    {
        return [
            'plain product line' => [false],
            'variant line' => [true],
        ];
    }

    /**
     * THE GIVE-BACK STILL WORKS, measured on the one path that can still abandon the loop.
     *
     * The foreign-product guard above now runs before the charge, which removes the most likely
     * mid-loop abandonment -- so without this test the give-back added to this handler would be
     * carried by nothing. A product id that resolves to NO ROW AT ALL is deliberately left to
     * the loop (`basketHasForeignProduct()` refuses only a PROVABLE mismatch, and a missing row
     * proves nothing), and the loop's own "Product not found" path is what it reaches.
     *
     * Line order is deliberate: the real product first, so there is a decrement to give back.
     */
    public function test_an_abandoned_nmi_loop_gives_back_the_stock_it_took(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store, 100.00, 50);
        $sid = $this->guestCart($store, $product);
        $gateway = $this->fakeNmi();

        $missingProductId = ((int) DB::table('products')->max('id')) + 500;

        $state = $this->nmiCheckoutState($store, $product);
        $state['cartlist']['product_list'][] = [
            'product_id' => $missingProductId, 'variant_id' => 0, 'qty' => 2, 'price' => 100.00,
        ];

        $before = self::orderCount();

        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $state])
            ->post('/' . $store->slug . '/get-payment-NMI', $this->nmiCardFields());

        $this->assertCount(1, $gateway->calls,
            'The charge never happened, so the give-back assertion below would be vacuous: the '
            . 'loop is only reached after the capture.');

        $this->assertSame($before, self::orderCount(),
            'An order was written for a basket holding a product that does not exist.');

        $this->assertSame(50, self::stockOf($product),
            'The abandoned checkout kept the stock it had already taken: the give-back did not run.');
    }

    /**
     * A DECLINED CARD DOES NOT DESTROY THE CHECKOUT.
     *
     * A PRESERVE-FIRST REGRESSION THIS PACKAGE INTRODUCED AND AN ADVERSARIAL REVIEW CAUGHT.
     * `Session::forget()` was placed at the top of the handler, above every guard and above the
     * charge, so a shopper who mistyped a CVV -- a routine few percent of card traffic -- had
     * their stored checkout destroyed by the attempt. Their next submit met
     * "We could not confirm your checkout details ... please contact the store", which is a dead
     * checkout for an ordinary event.
     *
     * The state is now consumed immediately before the charge and PUT BACK on every path that
     * took no money. Both halves are asserted: the decline must not write an order, and the
     * retry must succeed.
     */
    /**
     * TWO OVERLAPPING NMI RETURNS CHARGE THE CARD ONCE.
     *
     * P1-PAY-02, and NMI is the handler where a replay costs the most: the return does not
     * confirm a payment the shopper already made, it MAKES the charge
     * (`$this->nmiService->chargeSale(...)`). A second pass is a second capture on the card, not
     * merely a duplicate order row.
     *
     * `Session::forget()` immediately above the charge stops a SEQUENTIAL replay. It cannot stop
     * two returns that OVERLAP -- a double submit on the card form, a browser retry -- because
     * neither has written the forget when the other reads. Both therefore hold a valid copy of
     * the state, which is what the second request carries here.
     *
     * THE GATEWAY CALL COUNT IS THE ASSERTION THAT MATTERS. An order count alone would be
     * satisfied by a handler that charged twice and failed to write the second row.
     *
     * NMI is driven here rather than in GatewayReturnTrustBoundaryTest because only this file
     * fakes the NMI service; without that the handler never reaches its charge at all.
     */
    public function test_two_overlapping_nmi_returns_charge_the_card_once(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store, 100.00, 50);
        $sid = $this->guestCart($store, $product);
        $gateway = $this->fakeNmiSequence([
            ['response' => 1, 'transactionid' => 'T-1', 'amount' => '100.00'],
            ['response' => 1, 'transactionid' => 'T-2', 'amount' => '100.00'],
        ]);

        $before = self::orderCount();
        $state = $this->nmiCheckoutState($store, $product);

        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $state])
            ->post('/' . $store->slug . '/get-payment-NMI', $this->nmiCardFields());

        $this->assertCount(1, $gateway->calls,
            'The first return never charged, so "charged once" below would hold whatever the '
            . 'claim did. Session error: ' . var_export(session('error'), true));
        $this->assertSame($before + 1, self::orderCount(), 'The first return wrote no order.');

        // The overlapping second reader: the same state, still present, as a concurrent request
        // would have found it.
        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $state])
            ->post('/' . $store->slug . '/get-payment-NMI', $this->nmiCardFields());

        $this->assertCount(1, $gateway->calls,
            'A second return holding a valid copy of the same checkout state CHARGED THE CARD '
            . 'AGAIN. Session::forget() cannot close this -- both readers have the state.');
        $this->assertSame($before + 1, self::orderCount(),
            'A second return wrote a second order for one checkout.');
    }

    /**
     * A REQUEST THAT NEVER REACHES THE GATEWAY CANNOT KILL THE CHECKOUT EITHER.
     *
     * P1-PAY-02, AND THIS IS A REGRESSION THE FIRST VERSION OF THAT CHANGE INTRODUCED AND AN
     * INDEPENDENT REVIEW CAUGHT. The idempotency claim was taken at the top of the handler,
     * above the card-expiry check -- which returns EARLY and deliberately leaves the stored state
     * in place, because nothing has been charged. So any request that got that far burned the
     * claim and left the checkout alive but unusable: the shopper's real submit then met
     * "This payment has already been recorded against an order" for an order that never existed.
     *
     * IT IS NOT ONLY THE SHOPPER'S OWN MISTAKE. `routes/customer.php:360` declares this route
     * with `Route::any`, so GET is routed, Laravel's CSRF middleware does not verify reads, and
     * the route sits outside the authenticated group. One cross-site navigation while a checkout
     * is live was therefore enough to kill it -- harmless before the claim existed, fatal after.
     * The request here is exactly that: a GET with no card fields at all.
     *
     * The claim now sits immediately above the charge, below every refusal that takes no money,
     * so this request claims nothing and the genuine attempt that follows still works.
     */
    public function test_a_request_that_never_charges_does_not_consume_the_checkout(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store, 100.00, 50);
        $sid = $this->guestCart($store, $product);
        $gateway = $this->fakeNmiSequence([
            ['response' => 1, 'transactionid' => 'T-1', 'amount' => '100.00'],
        ]);

        $before = self::orderCount();
        $state = $this->nmiCheckoutState($store, $product);

        // The drive-by: routed, unauthenticated, no CSRF on a read, and no card fields.
        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $state])
            ->get('/' . $store->slug . '/get-payment-NMI');

        $this->assertCount(0, $gateway->calls,
            'The empty request reached the gateway, so it is not the no-charge path this test '
            . 'means to drive.');
        $this->assertSame($before, self::orderCount(), 'The empty request wrote an order.');

        // The shopper's genuine attempt, on the state that survived.
        $real = $this->withCookie('cart', $sid)
            ->post('/' . $store->slug . '/get-payment-NMI', $this->nmiCardFields());

        $this->assertCount(1, $gateway->calls,
            'The genuine attempt never reached the gateway. A request that charged nothing had '
            . 'consumed the checkout. Answered HTTP ' . $real->getStatusCode()
            . ' with error ' . var_export(session('error'), true));
        $this->assertSame($before + 1, self::orderCount(),
            'The genuine attempt wrote no order after a request that took no money.');
    }

    public function test_a_declined_card_leaves_the_checkout_retryable(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store, 100.00, 50);
        $sid = $this->guestCart($store, $product);
        $gateway = $this->fakeNmiSequence([
            ['response' => 2, 'responsetext' => 'DECLINE'],
            ['response' => 1, 'transactionid' => 'T-2', 'amount' => '100.00'],
        ]);

        $before = self::orderCount();
        $state = $this->nmiCheckoutState($store, $product);

        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $state])
            ->post('/' . $store->slug . '/get-payment-NMI', $this->nmiCardFields());

        $this->assertCount(1, $gateway->calls, 'The declined attempt never reached the gateway.');
        $this->assertSame($before, self::orderCount(), 'A declined card wrote an order.');
        $this->assertSame(50, self::stockOf($product), 'A declined card moved stock.');
        $this->assertStringNotContainsString(self::REFUSAL, (string) session('error'),
            'A declined card was reported as a missing checkout state.');

        // The retry, on the state the server put back, with a card that now succeeds.
        $retry = $this->withCookie('cart', $sid)
            ->post('/' . $store->slug . '/get-payment-NMI', $this->nmiCardFields());

        $this->assertCount(2, $gateway->calls,
            'The retry after a decline never reached the gateway, so the declined attempt had '
            . 'destroyed the stored checkout state. Retry answered HTTP '
            . $retry->getStatusCode() . ' with error ' . var_export(session('error'), true));
        $this->assertSame($before + 1, self::orderCount(),
            'The retry after a decline wrote no order.');
    }

    // =====================================================================================
    //  WHATSAPP / TELEGRAM
    // =====================================================================================

    /** The state the WhatsApp view's own return carries. */
    private function whatsappState($store, array $overrides = []): array
    {
        return array_merge([
            'slug' => $store->slug,
            'store_id' => $store->id,
            'customer_id' => '',
            'payment_type' => 'whatsapp',
            'shipping_final_price' => 0,
            'tax_price' => 0,
            'cartlist_final_price' => 100.00,
            'billing_info' => $this->billing(),
        ], $overrides);
    }

    public function test_a_legitimate_whatsapp_order_is_still_written(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store, 100.00, 50);
        $sid = $this->guestCart($store, $product);

        $before = self::orderCount();

        $response = $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $this->whatsappState($store)])
            ->post('/' . $store->slug . '/whatsapp', ['wts_number' => '123456789']);

        $this->assertSame($before + 1, self::orderCount(),
            'A legitimate WhatsApp order was not written (HTTP ' . $response->getStatusCode() . '), '
            . 'so every refusal asserted below could be satisfied by a handler that refuses '
            . 'everything. Body: ' . substr((string) $response->getContent(), 0, 400));

        /*
         * THE RESPONSE, NOT ONLY THE ROW -- for the same reason as the NMI control, where the
         * omission was MEASURED to be hiding an HTTP 500 after a committed order. This handler
         * answers its AJAX caller with a `status: success` envelope rather than a redirect.
         */
        $response->assertOk();
        $this->assertStringContainsString('success', (string) $response->getContent(),
            'The WhatsApp order committed but the journey did not complete. Body: '
            . substr((string) $response->getContent(), 0, 400));

        $order = DB::table('orders')->orderByDesc('id')->first();
        $this->assertSame(0, (int) $order->customer_id, 'A guest order acquired an owner.');
        $this->assertSame((int) $store->id, (int) $order->store_id, 'The order went to the wrong store.');
        $this->assertSame(49, self::stockOf($product), 'A completed order did not consume its stock.');
    }

    /**
     * P1-ORD-08, THE PRODUCER HALF. The WhatsApp/Telegram thank-you redirect is bound to the SESSION
     * THAT PLACED THE ORDER.
     *
     * `resources/views/payment/{whatsapp,telegram}.blade.php` forwards this handler's success payload
     * (`data => ['order_id' => $order->id]` -- a SEQUENTIAL PRIMARY KEY) to
     * `order-complete?data[order_id]=N`, and `OrderController::orderComplete()` used to resolve that
     * with a bare `Order::find()`, so any visitor could read any order by typing an integer. It now
     * honours the id only when it is in the `order_complete_ids` session list, which THIS method
     * writes -- so the buyer's own redirect keeps working and nobody else's does.
     *
     * Driven END TO END through the real handler rather than by seeding the session key, because the
     * consumer-side tests in StorefrontOrderCompletionDisclosureTest seed it by hand and so cannot
     * tell whether anything real ever writes it. A binding no producer sets would pass every one of
     * them while breaking every WhatsApp checkout.
     */
    public function test_the_whatsapp_order_is_bound_to_the_session_that_placed_it(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store, 100.00, 50);
        $sid = $this->guestCart($store, $product);

        $before = self::orderCount();

        $response = $this->withCookie('cart', $sid)
            ->post('/' . $store->slug . '/whatsapp', [
                'wts_number' => '123456789',
                'payment_type' => 'whatsapp',
                'billing_info' => $this->billing(),
            ]);

        $this->assertSame($before + 1, self::orderCount(),
            'No order was written, so the binding assertions below would be vacuous.');

        $order = DB::table('orders')->orderByDesc('id')->first();

        $response->assertSessionHas('order_complete_ids', function ($ids) use ($order) {
            return is_array($ids) && in_array((int) $order->id, $ids, true);
        });

        // THE BUYER'S OWN REDIRECT: the session the POST produced, then the page the blade sends them to.
        //
        // The query is built from the HANDLER'S RESPONSE, as the blade does (`$.param({data: data.data})`),
        // and not from the database row (review D-3). Built from the row, this test stayed green if the
        // payload key were renamed or carried the order NUMBER -- while every real WhatsApp/Telegram
        // thank-you redirect landed on the home page.
        $this->assertSame((int) $order->id, (int) $response->json('data.order_id'),
            'The success payload no longer carries the order\'s primary key under data.order_id, which is '
            . 'what the WhatsApp and Telegram blades forward to order-complete.');
        $query = '?' . http_build_query(['data' => $response->json('data')]);

        $landing = $this->withSession(['order_complete_ids' => session('order_complete_ids')])
            ->get('/' . $store->slug . '/order-complete' . $query);

        $landing->assertOk();
        $this->assertStringContainsString((string) $order->product_order_id, (string) $landing->getContent(),
            'The buyer\'s own WhatsApp thank-you page no longer renders their order.');

        // A STRANGER typing the same URL, with no such session.
        //
        // flushSession() IS LOAD-BEARING, AND LEAVING IT OUT PRODUCED A FALSE FAILURE. The test
        // client reuses one in-memory session Store across every request in a test, and
        // Store::loadSession() does `array_merge($this->attributes, <handler data>)` -- it never
        // clears. So the buyer's `order_complete_ids` from the two requests above silently rode
        // along on this one, the "stranger" was not session-less, and the page rendered. That was
        // the harness, not the fix; it is also why every other test in this area makes ONE request.
        $this->flushSession();

        $stranger = $this->get('/' . $store->slug . '/order-complete' . $query);

        $stranger->assertRedirect();
        $this->assertStringNotContainsString((string) $order->product_order_id, (string) $stranger->getContent(),
            'A visitor with no session read the order by its primary key.');
    }

    /**
     * A GUEST CANNOT CLAIM A WHATSAPP ORDER FOR ANOTHER CUSTOMER.
     *
     * The live defect: `$request->merge($requests_data)` sits on the authenticated arm only, so
     * for a guest every later `$request->customer_id` was the POSTED value -- the order's owner,
     * the `product_order_id`, and the `customer_id` of every row queued for deletion from the
     * cart.
     */
    public function test_a_guest_cannot_claim_a_whatsapp_order_for_another_customer(): void
    {
        [, $store] = $this->tenant();
        $victim = $this->customerIn($store, 'wa-victim');
        $product = $this->productIn($store, 100.00, 50);
        $sid = $this->guestCart($store, $product);

        $victimCart = Cart::forceCreate([
            'customer_id' => $victim->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 3, 'price' => 100.00, 'store_id' => $store->id,
        ]);

        $before = self::orderCount();

        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $this->whatsappState($store)])
            ->post('/' . $store->slug . '/whatsapp', [
                'wts_number' => '123456789',
                'customer_id' => $victim->id,
            ]);

        $this->assertSame($before + 1, self::orderCount(),
            'No order was written, so the ownership assertion would be vacuous.');

        $order = DB::table('orders')->orderByDesc('id')->first();
        $this->assertSame(0, (int) $order->customer_id,
            "A guest's posted customer_id became the WhatsApp order's owner.");

        $this->assertNotNull(
            Cart::withoutGlobalScopes()->find($victimCart->id),
            "A guest's posted customer_id emptied another customer's basket."
        );
    }

    /**
     * ON THE DIRECT-POST PATH, THE STORE IS THE URL'S AND NOT THE BODY'S.
     *
     * WHICH PATH THIS DRIVES, AND WHY IT HAD TO CHANGE. My first version of this test posted
     * `slug=<other store>` alongside a valid stored state and passed with the guard REVERTED --
     * because with stored state present `$requests_data` IS that state, so the body's slug never
     * reaches the slug read at all. Reverting the precedence failed nothing. MEASURED: 19 of 19
     * still green. The test was vacuous and is rewritten here rather than kept.
     *
     * The precedence only decides anything on the FALLBACK path -- the direct post from the two
     * checkout views, with no server state -- so that is what this drives: no session, a guest
     * cart in store A, posted to store A's URL, with store B's slug and id in the body.
     *
     * WHAT THE HARM IS, STATED HONESTLY. This is NOT a cross-tenant breach and is not recorded as
     * one: anyone may post to `/{storeB}/whatsapp` directly, so writing a store-B order through
     * store-B's own slug gains an attacker nothing. What it produces is a handler that disagrees
     * with its own URL -- ActiveTheme, themelanguage and every log line say store A while the
     * settings read, the city rows, `orders.store_id` and the cart deletes go to store B. The fix
     * is kept because it is correct and costs nothing, and it is reported as a consistency fix
     * with a bite rather than as a closed vulnerability.
     */
    public function test_on_the_direct_post_path_the_store_is_the_urls_and_not_the_bodys(): void
    {
        [, $storeA] = $this->tenant();
        [, $storeB] = $this->tenant();
        $product = $this->productIn($storeA, 100.00, 50);
        $sid = $this->guestCart($storeA, $product);

        $before = self::orderCount();

        // No withSession(): this is the checkout-page post that never passed through processOrder.
        $this->withCookie('cart', $sid)
            ->post('/' . $storeA->slug . '/whatsapp', [
                'wts_number' => '123456789',
                'slug' => $storeB->slug,
                'store_id' => $storeB->id,
                'payment_type' => 'whatsapp',
                'billing_info' => $this->billing(),
            ]);

        $this->assertSame($before + 1, self::orderCount(),
            'The direct-post path wrote no order at all, so the store assertion would be vacuous. '
            . 'With the body preferred this is exactly what happened: the handler resolved store B, '
            . "found no cart for this cookie there, and answered 'Cart is empty.'");

        $order = DB::table('orders')->orderByDesc('id')->first();
        $this->assertSame((int) $storeA->id, (int) $order->store_id,
            "A posted slug wrote the order into a store other than the one whose URL was posted to.");
        $this->assertSame(49, self::stockOf($product),
            "The order was written but store A's stock did not move, so the stores still disagree.");
    }

    /**
     * A POSTED SHIPPING FIGURE DOES NOT MOVE `orders.product_price` ON THE DIRECT-POST PATH.
     *
     * TRACED BY AN ADVERSARIAL REVIEW OF THIS PACKAGE, then driven here. The chain is four hops
     * and every one of them is in a different file, which is why neither this package's first
     * pass nor the one before it saw it:
     *
     *     $requests_data = $request->all()                       (no stored state)
     *       -> Cart::cart_list_cookie($requests_data, $store->id)
     *       -> app/Models/Cart.php:199   $shipping_price = (int) ($data['shipping_final_price'] ?? 0)
     *       -> Cart.php:621              $final_total = ... + $shipping_price + ...
     *       -> Cart.php:635              $cart_array['total_sub_price'] = SetNumber($final_total)
     *       -> $final_sub_total_price    -> $order->product_price
     *
     * `orders.final_price` is NOT affected -- it is built from `total_final_price`, which
     * excludes the shipping term -- so this is a recorded-total defect and not a payable one.
     * It is still one of the fields the package brief names, and it was live.
     *
     * Both directions, because they are different attacks: inflate (a merchant's reporting shows
     * revenue nobody paid) and deflate (the reverse). `SetNumber()` takes the absolute value of
     * a negative, so the deflating case does not even arrive as a negative -- which is a second
     * reason to assert the figure rather than its sign.
     *
     * @dataProvider postedShippingFigures
     */
    public function test_a_posted_shipping_figure_does_not_move_the_whatsapp_product_price($posted): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store, 100.00, 50);
        $sid = $this->guestCart($store, $product);

        $before = self::orderCount();

        // No withSession(): the direct checkout post, which is the arm that keeps the fallback.
        $this->withCookie('cart', $sid)
            ->post('/' . $store->slug . '/whatsapp', [
                'wts_number' => '123456789',
                'payment_type' => 'whatsapp',
                'billing_info' => $this->billing(),
                'shipping_final_price' => $posted,
            ]);

        $this->assertSame($before + 1, self::orderCount(),
            'No order was written, so the total assertion would be vacuous. Posted: '
            . var_export($posted, true));

        $order = DB::table('orders')->orderByDesc('id')->first();
        $this->assertSame(100.00, round((float) $order->product_price, 2),
            'A posted shipping figure moved the order\'s recorded product price. Posted: '
            . var_export($posted, true));
    }

    public static function postedShippingFigures(): array
    {
        return [
            'inflated' => [500],
            'negative' => [-9999],
            'string' => ['500'],
        ];
    }

    /**
     * AN UNUSABLE LINE QUANTITY IS REFUSED ON THE WHATSAPP ORDER TOO.
     *
     * The non-numeric quantity is excluded and the reason is itself a finding -- see
     * unusableQuantitiesReachingTheCartRead().
     *
     * SAME PER-DATA-SET VACUITY AS THE NMI TEST ABOVE: for `negative` and `fractional` the
     * stock assertion cannot move, because normaliseQuantity() refuses the decrement either
     * way. What carries the weight is the refusal message and the order count -- and those are
     * genuinely carried by this guard: the pre-flight `assertCartAvailable()` does NOT
     * short-circuit first, because `assertStockAvailable()` returns null both for
     * `$quantity <= 0` and for any product whose `stock_order_status` is not 'not_allow', which
     * this fixture never sets (the column is nullable with no default).
     *
     * @dataProvider unusableQuantitiesReachingTheCartRead
     */
    public function test_an_unusable_quantity_is_refused_on_the_whatsapp_order($qty): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store, 100.00, 50);
        $sid = $this->guestCart($store, $product, $qty);

        $before = self::orderCount();

        $response = $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $this->whatsappState($store)])
            ->post('/' . $store->slug . '/whatsapp', ['wts_number' => '123456789']);

        $this->assertStringContainsString(
            self::QUANTITY_REFUSAL,
            (string) $response->getContent(),
            'The quantity guard did not fire for qty ' . var_export($qty, true)
        );
        $this->assertSame($before, self::orderCount(),
            'An unusable quantity wrote a WhatsApp order. qty ' . var_export($qty, true));
        $this->assertSame(50, self::stockOf($product),
            'An unusable quantity moved stock. qty ' . var_export($qty, true));
    }
}
