<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\Security\EnablesWorkdoModules;

/**
 * D6, STAGE 1 -- THE REDEMPTION GATE, DRIVEN THROUGH THE REAL CHECKOUT.  MKT-005 / MKT-006.
 * =================================================================================================
 *
 * OWNER DECISION D6: "enforce redemption thresholds / eligibility server-side". Before this slice the
 * thresholds (`checkout_points`, `checkout_price`) lived in two Blade partials and nowhere else; the server
 * tested `isset($requests_data['club_point_is_active']) && == 'on'`, which compares the caller's own posted
 * string with itself. So a hand-built `club_point_is_active=on` redeemed below both thresholds, with the
 * programme switched off, and as a guest.
 *
 * WHAT STAGE 1 COVERS, AND WHAT IT DELIBERATELY DOES NOT. The redemption decision is made ONCE, at initiation,
 * from the signed-in customer's own balance (`PaymentController::processRewardPoints`), and written back onto
 * the request as `'on'` or `'off'` -- an OVERRIDE, never a removal, because a query-string copy of the flag
 * survives a removal. The two order writers that decide a total themselves (COD / bank transfer, and WhatsApp /
 * Telegram) then re-check with the same gate and refuse in the same way. The gateway RETURN handlers are not
 * touched -- see docs/development/phase-5/PHASE-05-D6-WIRING-MEASUREMENT.md for why that is an owner decision.
 *
 * WHY A REFUSAL HAS TO REACH THE EVENT AS WELL AS THE TOTAL. `AddRewardClubPoint` is dispatched after the order
 * is written, and the vendored listener's redemption branch REPLACES the customer's balance with the order's own
 * accrual. Skipping the discount while leaving the flag on would therefore take the customer's whole balance and
 * give nothing for it -- the mirror image of the leak this slice closes. Every refusal test below asserts the
 * balance as well as the total, and the `...is_not_debited_for_nothing` tests exist for exactly that.
 *
 * THE FIXTURE, in numbers (reward_points 10, reward_point_price 1: ten points buy one unit of currency):
 *   checkout_points 100, checkout_price 50. A 500-point balance is worth 50.00. A 100.00 basket earns 10 points.
 *   Redeeming therefore turns (balance 500, basket 100.00) into (total 50.00, balance 10) -- the vendored
 *   listener REPLACES the balance with this order's accrual, which is the `used_points` it records.
 */
class RewardRedemptionCheckoutGateTest extends SecurityTestCase
{
    use RefreshDatabase;
    use EnablesWorkdoModules;

    private const LINE = 100.00;

    /** The response to the most recent checkout / write the drivers below made, for the tests that assert on a refusal. */
    private ?\Illuminate\Testing\TestResponse $lastResponse = null;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    /**
     * @param  array<string,string>  $rewardSettings  overrides for the module's settings rows
     * @return array{0: \App\Models\User, 1: \App\Models\Store}
     */
    private function tenant(array $rewardSettings = []): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'rwd-' . uniqid(), 'price' => 0, 'themes' => '[]', 'storage_limit' => '100',
            'shipping_method' => 'off', 'modules' => 'RewardClubPoint',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->save();
        Cache::forget('admin_details');

        $failed = $this->enableWorkdoModules(['RewardClubPoint']);
        $this->assertSame([], $failed, 'a RewardClubPoint migration threw, so the fixture is not the module it claims to be');

        $settings = array_merge([
            'stock_management' => 'on', 'out_of_stock_threshold' => '0', 'notification' => '[]',
            'club_point_enabled' => 'on',
            'order_point_price' => '10', 'order_points' => '1',
            'reward_points' => '10', 'reward_point_price' => '1',
            'checkout_points' => '100', 'checkout_price' => '50',
        ], $rewardSettings);

        foreach ($settings as $name => $value) {
            if ($value === null) {
                continue;
            }
            DB::table('settings')->insert(['name' => $name, 'value' => $value, 'created_by' => $merchant->id,
                'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        (new \App\Models\Setting)->flushCache();

        DB::table('countries')->insertOrIgnore(['id' => 101, 'name' => 'S', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('states')->insertOrIgnore(['id' => 1, 'name' => 'D', 'country_id' => 101, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cities')->insertOrIgnore(['id' => 1, 'name' => 'D', 'country_id' => 101, 'state_id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return [$merchant, $store];
    }

    private function productIn($store, float $price): Product
    {
        return Product::forceCreate([
            'name' => 'p', 'slug' => 'p-' . uniqid(), 'product_stock' => 50, 'track_stock' => 0,
            'variant_product' => 0, 'price' => $price, 'sale_price' => $price,
            'status' => 1, 'stock_status' => 'in_stock',
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    private function shopper($store, float $points): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'A', 'last_name' => 'B', 'email' => 'rwd-' . uniqid() . '@e.test',
            'mobile' => '555' . random_int(100000, 999999), 'password' => bcrypt('x'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
            'total_club_point' => $points,
        ]);
    }

    private function basketFor($store, Customer $customer, float $price = self::LINE): void
    {
        $product = $this->productIn($store, $price);
        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => $price, 'store_id' => $store->id,
        ]);
    }

    private function billing(Customer $customer): array
    {
        return [
            'firstname' => 'A', 'lastname' => 'B', 'email' => $customer->email,
            'billing_user_telephone' => '123', 'billing_address' => 'x', 'billing_postecode' => '1',
            'billing_country' => '101', 'billing_state' => '1', 'billing_city' => '1',
            'billing_company_name' => '', 'delivery_address' => 'x', 'delivery_postcode' => '1',
            'delivery_country' => '101', 'delivery_state' => '1', 'delivery_city' => '1',
        ];
    }

    /** A COD checkout as the signed-in shopper, redeeming (or not) as `$flag` says. */
    private function codCheckout($store, Customer $customer, ?string $flag = 'on', array $extra = []): ?Order
    {
        $payload = array_merge([
            'slug' => $store->slug, 'store_id' => $store->id, 'customer_id' => $customer->id,
            'payment_type' => 'cod', 'billing_info' => $this->billing($customer),
        ], $extra);

        if ($flag !== null) {
            $payload['club_point_is_active'] = $flag;
        }

        $this->lastResponse = $this->actingAs($customer, 'customers')->post('/' . $store->slug . '/process-order', $payload);

        return Order::withoutGlobalScopes()->where('store_id', $store->id)->latest('id')->first();
    }

    private function balanceOf(Customer $customer): float
    {
        return (float) DB::table('customers')->where('id', $customer->id)->value('total_club_point');
    }

    /** @return object|null the vendored ledger row for the order */
    private function ledgerRow(Order $order): ?object
    {
        return DB::table('reward_club_points')->where('order_id', $order->id)->first();
    }

    /**
     * An INITIATION: the shopper submits the checkout form with a payment type whose arm only stores the
     * request and returns -- WhatsApp -- so what comes back is the decision the quote made, as the stored state
     * every later request reads. Nothing here writes an order.
     *
     * @return array<string,mixed> the stored `request_data`
     */
    private function initiate($store, Customer $customer, ?string $flag, string $uri = '', array $extra = []): array
    {
        $payload = array_merge([
            'slug' => $store->slug, 'store_id' => $store->id, 'customer_id' => $customer->id,
            'payment_type' => 'whatsapp', 'billing_info' => $this->billing($customer),
        ], $extra);

        if ($flag !== null) {
            $payload['club_point_is_active'] = $flag;
        }

        $response = $this->actingAs($customer, 'customers')->post('/' . $store->slug . '/process-order' . $uri, $payload);

        $state = session('request_data');
        $this->assertIsArray($state, 'Premise: the WhatsApp arm stored the checkout state (HTTP ' . $response->getStatusCode()
            . '). Without it nothing below is about the quote. Body: ' . substr((string) $response->getContent(), 0, 200));

        return $state;
    }

    /** The WhatsApp / Telegram WRITER, seeded with a stored state exactly as an initiation would have left it. */
    private function whatsappWrite($store, Customer $customer, ?string $flag, array $stateOverrides = [], array $post = []): ?Order
    {
        $state = array_merge([
            'slug' => $store->slug, 'store_id' => $store->id, 'customer_id' => $customer->id,
            'payment_type' => 'whatsapp', 'shipping_final_price' => 0, 'tax_price' => 0,
            'cartlist_final_price' => self::LINE,
            'billing_info' => $this->billing($customer),
        ], $stateOverrides);

        if ($flag !== null) {
            $state['club_point_is_active'] = $flag;
        }

        $this->lastResponse = $this->actingAs($customer, 'customers')
            ->withSession(['request_data' => $state])
            ->post('/' . $store->slug . '/whatsapp', array_merge(['wts_number' => '123456789'], $post));

        return Order::withoutGlobalScopes()->where('store_id', $store->id)->latest('id')->first();
    }

    // ---------------------------------------------------------------------------------------------
    // THE PREMISE. Without it every assertion below could hold for the wrong reason: the module not
    // loaded, the listener not registered, the settings not read.
    // ---------------------------------------------------------------------------------------------

    public function test_premise_an_eligible_redemption_takes_the_discount_and_spends_the_balance(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->shopper($store, 500);
        $this->basketFor($store, $customer);

        $order = $this->codCheckout($store, $customer);

        $this->assertNotNull($order, 'Premise: the COD checkout wrote an order.');
        $this->assertEqualsWithDelta(50.0, (float) $order->final_price, 0.005,
            'Premise: 500 points at 1 unit per 10 points is 50.00 off a 100.00 basket. If this fails the module is not being reached.');
        $this->assertEqualsWithDelta(10.0, $this->balanceOf($customer), 0.005,
            'Premise: the vendored listener ran and replaced the balance with this order\'s own accrual (100.00 x 1 / 10).');

        $row = $this->ledgerRow($order);
        $this->assertNotNull($row, 'Premise: the vendored listener wrote its ledger row.');
        $this->assertEqualsWithDelta(500.0, (float) $row->used_points, 0.005);
    }

    public function test_premise_no_flag_means_no_discount_and_the_order_earns_points(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->shopper($store, 500);
        $this->basketFor($store, $customer);

        $order = $this->codCheckout($store, $customer, null);

        $this->assertEqualsWithDelta(100.0, (float) $order->final_price, 0.005);
        $this->assertEqualsWithDelta(510.0, $this->balanceOf($customer), 0.005, 'accrual: 500 + 10');
        $this->assertEqualsWithDelta(0.0, (float) $this->ledgerRow($order)->used_points, 0.005);
    }

    // ---------------------------------------------------------------------------------------------
    // THE DEFECT: each of these redeemed before the gate existed.
    // ---------------------------------------------------------------------------------------------

    public function test_a_balance_below_the_points_threshold_cannot_redeem(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->shopper($store, 50);          // threshold 100
        $this->basketFor($store, $customer);

        $order = $this->codCheckout($store, $customer);

        $this->assertEqualsWithDelta(100.0, (float) $order->final_price, 0.005,
            'A 50-point balance is below checkout_points (100) and was still given 5.00 off.');
    }

    public function test_a_refused_redemption_does_not_spend_the_balance_for_nothing(): void
    {
        // THE MIRROR IMAGE. Refusing the discount while the flag still rides into the event would take the
        // whole balance and give nothing for it. The vendored listener's redemption branch REPLACES the balance
        // with the order's accrual; the accrual branch ADDS to it. Which one runs is decided by the flag alone.
        [, $store] = $this->tenant();
        $customer = $this->shopper($store, 50);
        $this->basketFor($store, $customer);

        $order = $this->codCheckout($store, $customer);

        $this->assertEqualsWithDelta(60.0, $this->balanceOf($customer), 0.005,
            'Refused, so the order must EARN its 10 points on top of the 50 -- not replace the balance with them.');
        $row = $this->ledgerRow($order);
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(0.0, (float) $row->used_points, 0.005, 'nothing was redeemed, so nothing may be recorded as used');
        $this->assertEqualsWithDelta(0.0, (float) $row->reward_price, 0.005);
    }

    public function test_a_basket_below_the_order_value_threshold_cannot_redeem(): void
    {
        // 500 points are worth 50.00, and the basket is 40.00: the discount used to be capped at the
        // basket, so the order was FREE -- 40.00 of goods for points the shop never agreed to take at
        // that size of order (checkout_price 50).
        [, $store] = $this->tenant();
        $customer = $this->shopper($store, 500);
        $this->basketFor($store, $customer, 40.00);

        $order = $this->codCheckout($store, $customer);

        $this->assertEqualsWithDelta(40.0, (float) $order->final_price, 0.005,
            'A 40.00 basket is below checkout_price (50) and was given away entirely.');
        $this->assertEqualsWithDelta(504.0, $this->balanceOf($customer), 0.005, 'refused: the balance keeps its 500 and earns 4');
    }

    public function test_a_programme_that_is_switched_off_cannot_be_redeemed_against(): void
    {
        // `club_point_enabled` is not in the module's settings validator and neither Blade partial tests it,
        // so a store can save all six numbers with the programme OFF. The vendored listener guards its whole
        // body on it -- so the discount used to be granted while no points were debited and no ledger row was
        // written at all: free money, recurring, unrecorded.
        [, $store] = $this->tenant(['club_point_enabled' => 'off']);
        $customer = $this->shopper($store, 500);
        $this->basketFor($store, $customer);

        $order = $this->codCheckout($store, $customer);

        $this->assertEqualsWithDelta(100.0, (float) $order->final_price, 0.005,
            'The programme is off and the order was still discounted.');
        $this->assertEqualsWithDelta(500.0, $this->balanceOf($customer), 0.005, 'and the listener correctly does nothing');
    }

    public function test_a_setting_that_is_absent_cannot_be_redeemed_against(): void
    {
        [, $store] = $this->tenant(['checkout_points' => null]);   // the row is never written
        $customer = $this->shopper($store, 500);
        $this->basketFor($store, $customer);

        $order = $this->codCheckout($store, $customer);

        $this->assertEqualsWithDelta(100.0, (float) $order->final_price, 0.005,
            'With no checkout_points row the Blade gate hides the control entirely; the server must agree.');
    }

    // ---------------------------------------------------------------------------------------------
    // WHAT MUST NOT CHANGE.
    // ---------------------------------------------------------------------------------------------

    public function test_a_balance_exactly_at_the_threshold_still_redeems(): void
    {
        // The Blade gate is `>=` on both thresholds, and so is the server's. A boundary test, because the
        // easy way to write this gate is `>`.
        [, $store] = $this->tenant();
        $customer = $this->shopper($store, 100);         // == checkout_points
        $this->basketFor($store, $customer, 50.00);      // == checkout_price

        $order = $this->codCheckout($store, $customer);

        $this->assertEqualsWithDelta(40.0, (float) $order->final_price, 0.005, '100 points = 10.00 off 50.00');
    }

    public function test_a_posted_flag_other_than_on_is_not_a_redemption(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->shopper($store, 500);
        $this->basketFor($store, $customer);

        $order = $this->codCheckout($store, $customer, 'off');

        $this->assertEqualsWithDelta(100.0, (float) $order->final_price, 0.005);
        $this->assertEqualsWithDelta(510.0, $this->balanceOf($customer), 0.005);
    }
    // ---------------------------------------------------------------------------------------------
    // THE QUOTE'S DECISION, AS THE STORED STATE CARRIES IT. This is the only place the quote can be seen on
    // its own: an initiation writes no order, so no writer can mask a missing or mistaken override.
    // ---------------------------------------------------------------------------------------------

    public function test_an_accepted_redemption_is_stored_as_on_and_charged_at_the_discounted_figure(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->shopper($store, 500);
        $this->basketFor($store, $customer);

        $state = $this->initiate($store, $customer, 'on');

        $this->assertSame('on', $state['club_point_is_active'] ?? null);
        $this->assertEqualsWithDelta(50.0, (float) ($state['cartlist_final_price'] ?? -1), 0.005,
            'Premise: the quote is what a gateway would CHARGE. 500 points are worth 50.00 off a 100.00 basket.');
    }

    public function test_an_accepted_redemption_is_canonicalised_to_the_string_on(): void
    {
        // Every first-party site compares `== 'on'` LOOSELY, and `true == 'on'` is true in PHP 8, so an API client
        // that posts JSON `true` redeems. The stored state should carry the decision as the one string everything
        // downstream compares, not whatever shape the caller happened to send.
        [, $store] = $this->tenant();
        $customer = $this->shopper($store, 500);
        $this->basketFor($store, $customer);

        $this->actingAs($customer, 'customers')->postJson('/' . $store->slug . '/process-order', [
            'slug' => $store->slug, 'store_id' => $store->id, 'customer_id' => $customer->id,
            'payment_type' => 'whatsapp', 'billing_info' => $this->billing($customer),
            'club_point_is_active' => true,
        ]);

        $state = session('request_data');
        $this->assertIsArray($state, 'Premise: the JSON initiation stored a state.');
        $this->assertSame('on', $state['club_point_is_active'] ?? null,
            'A JSON `true` was stored as a boolean; downstream sites would repeat the loose comparison.');
        $this->assertEqualsWithDelta(50.0, (float) ($state['cartlist_final_price'] ?? -1), 0.005, 'and it was honoured as a redemption');
    }

    public function test_a_refused_redemption_is_stored_as_off_and_charged_in_full(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->shopper($store, 50);              // below checkout_points
        $this->basketFor($store, $customer);

        $state = $this->initiate($store, $customer, 'on');

        $this->assertSame('off', $state['club_point_is_active'] ?? null,
            'The refusal must be WRITTEN onto the request. Skipping the discount while the flag stays on stores a redemption '
            . 'for a later request to act on.');
        $this->assertEqualsWithDelta(100.0, (float) ($state['cartlist_final_price'] ?? -1), 0.005);
    }

    public function test_a_refusal_overrides_a_copy_of_the_flag_in_the_query_string(): void
    {
        // WHY THE OVERRIDE IS A MERGE AND NOT A REMOVAL. `Request::input()` is `body + query`, so removing the key
        // from the body leaves `?club_point_is_active=on` to be read straight back. Measured on this PHP 8.3 build.
        [, $store] = $this->tenant();
        $customer = $this->shopper($store, 50);
        $this->basketFor($store, $customer);

        $state = $this->initiate($store, $customer, null, '?club_point_is_active=on');

        $this->assertSame('off', $state['club_point_is_active'] ?? null,
            'A query-string copy of the flag survived the refusal.');
    }

    public function test_a_guest_cannot_carry_a_redemption_into_the_stored_state(): void
    {
        // THE ATTACK THIS CLOSES. A guest checkout adopts whichever store customer owns the e-mail typed into the
        // billing form -- no proof of ownership -- and stores that customer's id. The guest's own posted flag was
        // never examined (the quote is only reached on the signed-in arm), so it rode into the stored state beside
        // the victim's id, for a gateway return to act on.
        [, $store] = $this->tenant();
        $victim = $this->shopper($store, 500);

        $cookie = 'rwd-guest-' . uniqid();
        $product = $this->productIn($store, self::LINE);
        Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => self::LINE, 'store_id' => $store->id, 'cookie_session_id' => $cookie,
        ]);

        $response = $this->withCookie('cart', $cookie)->post('/' . $store->slug . '/process-order', [
            'slug' => $store->slug, 'store_id' => $store->id,
            'payment_type' => 'whatsapp', 'club_point_is_active' => 'on',
            'billing_info' => $this->billing($victim),                 // the VICTIM's e-mail, typed by a stranger
        ]);

        $state = session('request_data');
        $this->assertIsArray($state, 'Premise: the guest initiation stored a state (HTTP ' . $response->getStatusCode() . ').');
        $this->assertSame($victim->id, (int) ($state['customer_id'] ?? 0),
            'Premise: the guest was bound to the customer whose e-mail they typed. This is the exposure.');
        $this->assertSame('off', $state['club_point_is_active'] ?? null,
            'A guest\'s redemption flag reached the stored state, next to a victim\'s id.');
    }

    public function test_an_ordinary_guest_checkout_stores_no_flag_it_was_not_sent(): void
    {
        // The override is conditional on the key being present, so a guest who never sent it keeps exactly the
        // stored-state shape they had before this slice.
        [, $store] = $this->tenant();
        $customer = $this->shopper($store, 500);

        $cookie = 'rwd-guest-' . uniqid();
        $product = $this->productIn($store, self::LINE);
        Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => self::LINE, 'store_id' => $store->id, 'cookie_session_id' => $cookie,
        ]);

        $this->withCookie('cart', $cookie)->post('/' . $store->slug . '/process-order', [
            'slug' => $store->slug, 'store_id' => $store->id,
            'payment_type' => 'whatsapp', 'billing_info' => $this->billing($customer),
        ]);

        $this->assertIsArray(session('request_data'), 'Premise: a state was stored.');
        $this->assertArrayNotHasKey('club_point_is_active', session('request_data'));
    }

    // ---------------------------------------------------------------------------------------------
    // THE WHATSAPP / TELEGRAM WRITER, which is reachable without the quote and so checks for itself.
    // ---------------------------------------------------------------------------------------------

    public function test_premise_whatsapp_writer_an_eligible_redemption_takes_the_discount_and_spends_the_balance(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->shopper($store, 500);
        $this->basketFor($store, $customer);

        $order = $this->whatsappWrite($store, $customer, 'on');

        $this->assertNotNull($order, 'Premise: the WhatsApp writer wrote an order.');
        $this->assertEqualsWithDelta(50.0, (float) $order->final_price, 0.005);
        $this->assertEqualsWithDelta(10.0, $this->balanceOf($customer), 0.005);
        $this->assertEqualsWithDelta(500.0, (float) $this->ledgerRow($order)->used_points, 0.005);
    }

    public function test_the_whatsapp_writer_refuses_a_balance_below_the_threshold_and_spends_nothing(): void
    {
        // A STATE THE QUOTE NEVER VETTED -- a session that outlived a deploy, or a direct POST with no state. The
        // flag is on, the balance is 50 against a threshold of 100.
        [, $store] = $this->tenant();
        $customer = $this->shopper($store, 50);
        $this->basketFor($store, $customer);

        $order = $this->whatsappWrite($store, $customer, 'on');

        $this->assertNotNull($order);
        $this->assertEqualsWithDelta(100.0, (float) $order->final_price, 0.005, 'A 50-point balance was given 5.00 off.');
        $this->assertEqualsWithDelta(60.0, $this->balanceOf($customer), 0.005,
            'Refused, so the order EARNS its 10 points. If the flag still reached the event the balance would be replaced instead.');
        $this->assertEqualsWithDelta(0.0, (float) $this->ledgerRow($order)->used_points, 0.005);
    }

    public function test_the_whatsapp_writer_refuses_a_basket_below_the_order_value_threshold(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->shopper($store, 500);
        $this->basketFor($store, $customer, 40.00);

        $order = $this->whatsappWrite($store, $customer, 'on', ['cartlist_final_price' => 40.00]);

        $this->assertNotNull($order);
        $this->assertEqualsWithDelta(40.0, (float) $order->final_price, 0.005, 'A 40.00 basket was given away free.');
        $this->assertEqualsWithDelta(504.0, $this->balanceOf($customer), 0.005);
    }

    public function test_the_whatsapp_writer_refuses_when_the_programme_is_off(): void
    {
        [, $store] = $this->tenant(['club_point_enabled' => 'off']);
        $customer = $this->shopper($store, 500);
        $this->basketFor($store, $customer);

        $order = $this->whatsappWrite($store, $customer, 'on');

        $this->assertNotNull($order);
        $this->assertEqualsWithDelta(100.0, (float) $order->final_price, 0.005);
        $this->assertEqualsWithDelta(500.0, $this->balanceOf($customer), 0.005);
    }

    public function test_the_whatsapp_writer_reads_the_signed_in_customers_balance_and_not_the_stored_ids(): void
    {
        // A REGRESSION GUARD FOR A PREMISE THE GATE RELIES ON, NOT A TEST OF THIS CHANGE: it passes with the D6 hunk in
        // `whatsapp()` reverted, because that method already derived `$customer_id` from the signed-in customer before D6
        // (found by the independent review, which also noted none of the mutants targets it). The stored `customer_id` is a
        // guest-chosen value for a shopper who started as a guest; here it names a customer with a 500-point balance while the
        // signed-in customer has none. The discount must be priced from -- and debited against -- the same person, and that
        // person is whoever is signed in. If someone ever switches the derivation to the stored id, this is the test that fails.
        [, $store] = $this->tenant();
        $victim = $this->shopper($store, 500);
        $me = $this->shopper($store, 0);
        $this->basketFor($store, $me);

        $order = $this->whatsappWrite($store, $me, 'on', ['customer_id' => $victim->id]);

        $this->assertNotNull($order);
        $this->assertEqualsWithDelta(100.0, (float) $order->final_price, 0.005,
            'A discount was priced from ANOTHER customer\'s balance and granted to the signed-in one.');
        $this->assertEqualsWithDelta(500.0, $this->balanceOf($victim), 0.005, 'the victim\'s balance must not move');
    }

    // ---------------------------------------------------------------------------------------------
    // A FILE PART NAMED `customer_id`, FOUND BY THE INDEPENDENT REVIEW OF THIS SLICE.
    // ---------------------------------------------------------------------------------------------
    //
    // `Request::all()` is `array_replace_recursive(input, files)`, so a multipart FILE part named `customer_id` replaces the
    // principal id the intake merged -- for every reader of `$request->customer_id` and `$requests_data['customer_id']`.
    // Three things follow, MEASURED here:
    //
    //   1. whoever prices the discount from the REQUEST BAG reads an uploaded file and finds nobody: no discount;
    //   2. whoever prices it from the PRINCIPAL still grants it; and
    //   3. the post-commit tail (the three `OrderNote::order_note_data()` calls, whose `customer_id` is a foreign key)
    //      then fails on the file's temporary path -- AFTER the order is committed and BEFORE the event that debits the
    //      points. So an eligible shopper could be given a discount and never charged for it, as often as they liked.
    //
    // The first version of this slice read the balance from the principal at the COD writer (a consistency fix) and so
    // turned (1) into (2): it made the leak newly reachable on COD / bank transfer. The WhatsApp writer already priced
    // from the principal, so there it was reachable before D6 (for ANY shopper, below every threshold; the gate narrowed
    // it to eligible ones). The trigger is closed at the intake instead: a request whose bag does not agree with the
    // principal about WHO THE CUSTOMER IS is refused before any order is written.

    public function test_a_file_part_named_customer_id_is_refused_before_an_order_is_written_on_cod(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->shopper($store, 500);
        $this->basketFor($store, $customer);

        $order = $this->codCheckout($store, $customer, 'on', ['customer_id' => UploadedFile::fake()->create('c.txt', 1)]);

        $this->assertNull($order, 'An order was committed for a request whose customer id the intake could not trust.');
        $this->lastResponse->assertRedirect();
        $this->lastResponse->assertSessionHas('error');
        $this->assertEqualsWithDelta(500.0, $this->balanceOf($customer), 0.005, 'and the balance was touched');
        $this->assertSame(1, DB::table('carts')->where('customer_id', $customer->id)->count(), 'the basket was emptied by a refused request');
    }

    public function test_a_file_part_named_customer_id_is_refused_before_an_order_is_written_on_whatsapp(): void
    {
        // The leak, in its original home: priced from the principal, so the discount was granted -- and the note insert
        // then failed on the file's path before the event could debit it. Executed against the code as it stood.
        [, $store] = $this->tenant();
        $customer = $this->shopper($store, 500);
        $this->basketFor($store, $customer);

        \Illuminate\Support\Facades\Log::spy();

        $order = $this->whatsappWrite($store, $customer, 'on', [], ['customer_id' => UploadedFile::fake()->create('c.txt', 1)]);

        $this->assertNull($order, 'An order was committed with a discount and no debit: the request could not be trusted to name its customer.');
        $this->assertEqualsWithDelta(500.0, $this->balanceOf($customer), 0.005);
        $this->assertSame(0, DB::table('reward_club_points')->count(), 'a ledger row exists for a refused request');

        // The refusal is the JSON envelope every other refusal in this method returns -- its only browser caller is AJAX that reads
        // `status` -- and not a redirect it would follow into the storefront home page.
        $this->lastResponse->assertOk()->assertJsonPath('status', 0);
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->withArgs(
            fn ($message) => is_string($message) && str_contains($message, 'Checkout refused: the request named a customer the intake did not resolve')
        )->once();
    }

    public function test_a_file_array_named_customer_id_is_refused_and_not_a_500_from_the_guard_itself(): void
    {
        // `customer_id[]` makes the bag value an ARRAY, and `(string) []` is an "Array to string conversion" -- which this
        // application's error handler throws. The first version of the guard cast, so it answered HTTP 500 from the guard.
        [, $store] = $this->tenant();
        $customer = $this->shopper($store, 500);
        $this->basketFor($store, $customer);

        $order = $this->codCheckout($store, $customer, 'on', ['customer_id' => [UploadedFile::fake()->create('c.txt', 1)]]);

        $this->assertNull($order);
        $this->assertNotSame(500, $this->lastResponse->getStatusCode(), 'the guard itself threw');
        $this->lastResponse->assertRedirect();
        $this->lastResponse->assertSessionHas('error');
        $this->assertEqualsWithDelta(500.0, $this->balanceOf($customer), 0.005);
    }

    public function test_an_errored_upload_named_customer_id_is_not_the_guests_empty_id(): void
    {
        // An upload over `upload_max_filesize` has an EMPTY temporary name, so its string form is `''` -- exactly the id of a
        // guest who has no customer. The first version compared string casts and let it through for a guest on the WhatsApp
        // writer. A file is an object, not an id: it is never compared.
        [, $store] = $this->tenant();
        $customer = $this->shopper($store, 0);
        $cookie = 'rwd-guest-' . uniqid();
        $product = $this->productIn($store, self::LINE);
        Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => self::LINE, 'store_id' => $store->id, 'cookie_session_id' => $cookie,
        ]);

        $errored = new UploadedFile('', 'big.bin', 'application/octet-stream', UPLOAD_ERR_INI_SIZE, true);
        $state = [
            'slug' => $store->slug, 'store_id' => $store->id, 'customer_id' => '',
            'payment_type' => 'whatsapp', 'shipping_final_price' => 0, 'tax_price' => 0,
            'cartlist_final_price' => self::LINE, 'billing_info' => $this->billing($customer),
        ];

        $orders = (int) DB::table('orders')->count();
        $this->lastResponse = $this->withCookie('cart', $cookie)
            ->withSession(['request_data' => $state])
            ->post('/' . $store->slug . '/whatsapp', ['wts_number' => '123456789', 'customer_id' => $errored]);

        $this->assertSame($orders, (int) DB::table('orders')->count(), 'a guest order was written for a request whose customer id was an errored upload');
        $this->lastResponse->assertOk()->assertJsonPath('status', 0);
    }

    public function test_an_ordinary_request_is_not_mistaken_for_a_tampered_one(): void
    {
        // The guard compares the bag with the principal; it must say nothing about a request that agrees (every other
        // test in this file is one, but the point deserves a name: the refusal is for the DISAGREEMENT, not for the field).
        [, $store] = $this->tenant();
        $customer = $this->shopper($store, 500);
        $this->basketFor($store, $customer);

        $order = $this->codCheckout($store, $customer, 'on', ['customer_id' => (string) $customer->id]);

        $this->assertNotNull($order);
        $this->assertEqualsWithDelta(50.0, (float) $order->final_price, 0.005);
        $this->assertEqualsWithDelta(10.0, $this->balanceOf($customer), 0.005);
    }

    // ---------------------------------------------------------------------------------------------
    // WHAT ELSE A FLAG CAN LOOK LIKE, and "no line executes for a store without the module".
    // ---------------------------------------------------------------------------------------------

    /** @return array<string, array{0: mixed}> */
    public static function notTheStringOn(): array
    {
        return [
            'the digit one' => ['1'],
            'upper case' => ['ON'],
            'yes' => ['yes'],
            'true as text' => ['true'],
            'an array' => [['on']],
        ];
    }

    /** @dataProvider notTheStringOn */
    public function test_a_flag_that_is_not_the_string_on_is_not_a_redemption(mixed $flag): void
    {
        // PHP 8 compares loosely: `1 == 'on'` and `'ON' == 'on'` are false, `true == 'on'` is true (and is covered above).
        // None of these may redeem, and none may spend the balance: the accrual branch must run.
        [, $store] = $this->tenant();
        $customer = $this->shopper($store, 500);
        $this->basketFor($store, $customer);

        $payload = ['club_point_is_active' => $flag];
        $this->lastResponse = $this->actingAs($customer, 'customers')->post('/' . $store->slug . '/process-order', array_merge([
            'slug' => $store->slug, 'store_id' => $store->id, 'customer_id' => $customer->id,
            'payment_type' => 'cod', 'billing_info' => $this->billing($customer),
        ], $payload));
        $order = Order::withoutGlobalScopes()->where('store_id', $store->id)->latest('id')->first();

        $this->assertNotNull($order, 'Premise: the checkout wrote an order.');
        $this->assertEqualsWithDelta(100.0, (float) $order->final_price, 0.005, 'a flag that is not "on" was given a discount');
        $this->assertEqualsWithDelta(510.0, $this->balanceOf($customer), 0.005, 'and the balance must accrue, not be replaced');
    }

    public function test_an_eligible_redemption_is_not_spent_when_the_order_is_priced_by_partial_payments(): void
    {
        // FOUND BY THE INDEPENDENT REVIEW. When PartialPayments is active for the store and `enable_partial_payment` is on,
        // the COD / bank writer takes the PartialPayments arm: the order total is the module's deposit and the reward branch
        // is never evaluated -- so NO discount is in the total -- yet an eligible shopper's `'on'` still reached the event, and
        // the vendored listener REPLACED the balance. Points spent, nothing received: the mirror image of the leak D6 exists
        // to close. The refusal has to reach the event from this arm too.
        [, $store] = $this->partialPaymentsTenant();

        $customer = $this->shopper($store, 500);
        $this->basketFor($store, $customer);

        $order = $this->codCheckout($store, $customer);

        $this->assertNotNull($order, 'Premise: the checkout wrote an order (HTTP ' . $this->lastResponse->getStatusCode() . ').');
        $this->assertEqualsWithDelta(10.0, (float) $order->final_price, 0.005,
            'Premise: the PartialPayments arm priced this order (a 10% deposit on 100.00), so the reward discount is not in the total.');
        $this->assertEqualsWithDelta(510.0, $this->balanceOf($customer), 0.005,
            'No discount was given, so no points may be spent: the balance must ACCRUE, not be replaced by this order\'s earnings.');
    }

    /** A store whose owner's plan carries BOTH modules and whose partial-payment setting is on, so the deposit arm is the one taken. */
    private function partialPaymentsTenant(): array
    {
        [$merchant, $store] = $this->tenant();
        DB::table('plans')->where('id', $merchant->plan_id)->update(['modules' => 'RewardClubPoint,PartialPayments']);
        $this->assertSame([], $this->enableWorkdoModules(['PartialPayments']), 'a PartialPayments migration threw');
        foreach (['enable_partial_payment' => 'on', 'deposit_selection' => 'pay_deposit', 'deposit_type' => 'percent',
            'deposit_amount' => '10', 'force_deposit' => 'on'] as $name => $value) {
            DB::table('settings')->insert(['name' => $name, 'value' => $value, 'created_by' => $merchant->id,
                'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        (new \App\Models\Setting)->flushCache();
        Cache::flush();

        return [$merchant, $store];
    }

    public function test_a_partial_payments_checkout_that_sent_no_flag_stores_none(): void
    {
        // The withdrawal is conditional on the caller having sent the key, so an ordinary checkout's stored state keeps its shape.
        [, $store] = $this->partialPaymentsTenant();
        $customer = $this->shopper($store, 500);
        $this->basketFor($store, $customer);

        $state = $this->initiate($store, $customer, null);

        $this->assertEqualsWithDelta(10.0, (float) ($state['cartlist_final_price'] ?? -1), 0.005, 'Premise: the deposit arm priced it.');
        $this->assertArrayNotHasKey('club_point_is_active', $state);
    }

    public function test_the_partial_payments_initiation_withdraws_the_redemption_from_the_stored_state(): void
    {
        // THE SAME DEFECT AT INITIATION, where it also reaches a gateway return that the owner holds: the quote stores 'on',
        // `prepareFinalRequestData()` then replaces the charge with the module's deposit -- so the discount is in no figure --
        // and the held generic return's PartialPayments arm replaced the balance for it. The decision is withdrawn where it is
        // STORED, so no writer has to be touched.
        [, $store] = $this->partialPaymentsTenant();

        $customer = $this->shopper($store, 500);
        $this->basketFor($store, $customer);

        $state = $this->initiate($store, $customer, 'on');

        $this->assertEqualsWithDelta(10.0, (float) ($state['cartlist_final_price'] ?? -1), 0.005,
            'Premise: the PartialPayments limb replaced the charge with the 10% deposit, so no reward discount is in it.');
        $this->assertSame('off', $state['club_point_is_active'] ?? null,
            'The redemption survived into the stored state of a checkout the module priced; a gateway return would spend the points for it.');
    }

    public function test_for_a_store_without_the_module_the_guest_override_does_not_run(): void
    {
        // "No line of this change executes for a store whose RewardClubPoint module is not active" was FALSE of the guest
        // override until the review caught it: it ran unconditionally and rewrote a guest's key in the stored state. It is
        // inert in effect (every reader of the flag is module-gated) but the claim is now true, and pinned.
        [$merchant, $store] = $this->tenant();
        DB::table('plans')->where('id', $merchant->plan_id)->update(['modules' => '']);
        Cache::flush();

        $customer = $this->shopper($store, 500);
        $cookie = 'rwd-guest-' . uniqid();
        $product = $this->productIn($store, self::LINE);
        Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => self::LINE, 'store_id' => $store->id, 'cookie_session_id' => $cookie,
        ]);

        $this->withCookie('cart', $cookie)->post('/' . $store->slug . '/process-order', [
            'slug' => $store->slug, 'store_id' => $store->id,
            'payment_type' => 'whatsapp', 'club_point_is_active' => 'on', 'billing_info' => $this->billing($customer),
        ]);

        $state = session('request_data');
        $this->assertIsArray($state, 'Premise: the guest initiation stored a state.');
        $this->assertSame('on', $state['club_point_is_active'] ?? null, 'the override ran for a store that does not have the module');
    }
}
