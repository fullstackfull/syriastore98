<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\OrderCouponDetail;
use App\Models\Product;
use App\Models\UserCoupon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P4-F026 — THE STOREFRONT ORDER WRITE IS NOT ATOMIC, DRIVEN RATHER THAN READ.
 * =================================================================================================
 *
 * `OrderWriteTransactionTest` states the property structurally: `Api\ApiController::place_order`
 * and `::ordersave` open no transaction, so the Order row and its four child rows
 * (OrderBillingDetail, OrderCouponDetail, UserCoupon, OrderTaxDetail) stand or fall one at a time.
 * MEASURED on d227b7c0: `DB::beginTransaction|DB::commit|DB::rollBack|DB::transaction` matches
 * NOTHING in either method's range (ApiController.php:3459-4426 and :8905-9973), and the handler's
 * own comment at :4143-4147 says so in words ("NO TRANSACTION ON THIS PATH, stated because the
 * first version of this comment claimed one and independent review found it false").
 *
 * EVERY `ApiController` LINE NUMBER BELOW IS AS AT d227b7c0, and nothing here depends on one:
 * the premises are taken from model events and the measurements from the tables. The numbers are
 * for a reader locating the code, and this branch moves them -- a concurrent change in the same
 * working tree already shifted `place_order` by +2 and `ordersave` by +23 while this file was
 * being written, without touching anything it asserts.
 *
 * THIS FILE PROVES THE WINDOW REALLY OPENS, AND WHAT FALLS THROUGH IT.
 * -------------------------------------------------------------------------------------------------
 * The write order is OrderCouponDetail (:4240) and THEN UserCoupon (:4252), so the REACHABLE
 * half-write is "the discount was granted and recorded, the redemption was not". That is not an
 * audit gap. `user_coupons` is the only table either coupon cap counts:
 *
 *   - `Coupon::usesByCustomer()`      app/Models/Coupon.php:115-136 — max() of a
 *                                     `user_coupons.user_id` count and an e-mail limb;
 *   - `Coupon::PerUsesCouponCount()`  :148-158 — the e-mail limb, which ITSELF starts from
 *                                     `UserCoupon::where('coupon_id', ...)->get()`;
 *   - `Coupon::UsesCouponCount()`     :145-147 — `hasMany(UserCoupon::class)->count()`, which is
 *                                     what `applyUsageLimit()` compares `coupon_limit` against.
 *
 * So a missing UserCoupon row hides the redemption from the PER-USER cap and from the TOTAL limit
 * at once, while the order keeps its money off. `test_a_broken_write_lets_one_use_coupon_pay_out_twice`
 * is that sentence as a measurement.
 *
 * THE IN-CODE NOTE AT :4245-4247 MUST NOT BE READ AS CLEARING THIS. It says the handler no longer
 * attributes `user_coupons.user_id` from request input; it is about the VALUE of that column, not
 * the EXISTENCE of the row.
 *
 * REACHABILITY, STATED HONESTLY AND NOT OVERSTATED. CLAUDE.md records that the storefront API
 * (`api/{slug}/...`, outside the `admin` prefix) is called by no client in either repository, while
 * `ApiOrderWriterStockCompensationTest`:45-47 calls these "the writers every mobile and storefront
 * client actually calls". That disagreement is unsettled and this file does not settle it. What it
 * does establish is that the route is reachable: `routes/api.php:140` carries `custom.auth`, every
 * Sanctum token in this codebase is non-expiring and carries `['*']`, and `custom.auth` admits a
 * merchant's and a driver's token as well as a shopper's.
 *
 * WHY THE TRIGGER NEEDS NO INJECTION, which is the part worth knowing before reading the fix.
 * `$Coupon = Coupon::find($coupon_data['coupon_id'])` (:4227) and
 * `$OrderCouponDetail->coupon_id = $coupon_data['coupon_id']` (:4232) both read the CALLER'S
 * `coupon_info.coupon_id`, while the discount itself comes from the server's own `apply_coupon()`
 * run on the caller's `coupon_info.coupon_code` (:3620-3678). Post a real CODE and a nonexistent
 * ID and the server grants the real discount, writes the order, writes the detail row, and then
 * dereferences `$Coupon->id` on null at :4250 — inside the window, by request input alone.
 * `test_an_injected_failure_at_the_redemption_write_...` makes the same measurement WITHOUT that
 * trigger, so the finding does not rest on it: a null guard would close the trigger and leave the
 * window exactly as open.
 *
 * THE ASSERTION IS ONE-DIRECTIONAL, AND THAT IS NOT TIMIDITY. It says "no discount survives
 * unrecorded" (`granted <= recorded`), not "granted == recorded", because the OTHER direction is a
 * separate, already-live behaviour this file is not scoped to change: the coupon block at
 * ApiController:4225 is gated on `!empty($request->coupon_info)` and NOT on whether
 * `apply_coupon()` accepted anything, so a REFUSED coupon still writes both the detail row and the
 * `user_coupons` row. MEASURED while falsifying this suite -- two well-formed checkouts against a
 * one-use coupon gave 1 discounted order and 2 recorded redemptions: the second was correctly
 * charged full price and still burned a redemption, and `order_coupon_details` recorded a 50.00
 * discount against it. Pinning equality here would silently pin that too.
 *
 * HOW THE PREMISES ARE TAKEN, and this is deliberate. A premise read from the database AFTER the
 * request cannot distinguish "the handler never got there" from "it got there and rolled back",
 * which is precisely the state a fix produces. So the two in-window premises are captured by model
 * events that fire DURING the request — a rollback undoes rows, not PHP variables — and the
 * post-state is used only for the conservation assertion.
 */
final class StorefrontOrderWriteAtomicityTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const COUPON_CODE = 'SAVE50';
    private const DISCOUNT = 50.00;
    private const UNIT_PRICE = 100.00;
    private const BASKET_UNITS = 2;

    /** An id no `coupons` row can hold in a from-zero test database. */
    private const NONEXISTENT_COUPON_ID = 999999;

    protected function setUp(): void
    {
        parent::setUp();

        // Both writers mail the shopper and the store owner on success.
        Mail::fake();
    }

    // ============================================================================ positive controls

    /**
     * POSITIVE CONTROL 1 — the fixture really drives the coupon branch, end to end.
     *
     * Without this every measurement below is ambiguous: "no redemption row" and "no coupon was
     * ever applied" are the same observation. This asserts the whole chain in one request —
     * the server grants the discount, the order carries it, the detail row lands AND the
     * redemption row lands — so the failing cases are about the WINDOW and not about a fixture
     * that never reached it.
     */
    public function test_premise_a_well_formed_request_writes_the_order_the_detail_and_the_redemption(): void
    {
        [$store, $shopper, $couponId] = $this->storeWithOneUseCoupon();
        $this->fillBasket($store, $shopper);

        $response = $this->placeOrder($store, $shopper, $this->couponInfo($couponId));

        /* `ApiResponser::error()` returns HTTP 200 with `{status: 0}`, so a 200 is never evidence
           on its own -- CLAUDE.md names this as a way tests pass for the wrong reason. The status
           flag is read as well. */
        $this->assertSame(200, $response->getStatusCode(),
            'place-order did not complete: ' . $this->excerpt($response));
        $this->assertSame(1, (int) $response->json('status'),
            'place-order answered with the error shape, which is HTTP 200 here: '
            . $this->excerpt($response));

        $order = $this->latestOrder($store);
        $this->assertNotNull($order, 'premise: an order row was written');

        $this->assertSame(self::DISCOUNT, (float) $order->coupon_price,
            'premise: the server granted the real discount, so the coupon branch ran');
        $this->assertSame(
            self::UNIT_PRICE * self::BASKET_UNITS - self::DISCOUNT,
            (float) $order->final_price,
            'premise: the discount reached the charged total'
        );

        $this->assertSame(1, $this->detailRowsFor($order->id),
            'premise: the order_coupon_details row was written');
        $this->assertSame(1, $this->redemptionsOf($couponId),
            'premise: the user_coupons row was written. If this is 0 on the HAPPY path the '
            . 'redemption write is broken outright and every other case in this file is vacuous.');
    }

    /**
     * POSITIVE CONTROL 2 — the caps really bind on `user_coupons`, and nothing else.
     *
     * This is what makes "no redemption row" cost money rather than paperwork. With
     * `coupon_limit = 1` and `coupon_limit_user = 1`, ONE recorded redemption must exhaust the
     * coupon: the second basket is charged full price. If this ever passes without the first
     * request having written a `user_coupons` row, the caps have acquired a second source and the
     * reasoning in this file needs re-deriving.
     */
    public function test_premise_one_recorded_redemption_exhausts_a_one_use_coupon(): void
    {
        [$store, $shopper, $couponId] = $this->storeWithOneUseCoupon();

        $this->fillBasket($store, $shopper);
        $this->placeOrder($store, $shopper, $this->couponInfo($couponId));

        $this->assertSame(1, $this->redemptionsOf($couponId),
            'premise: the first order recorded its redemption');

        $this->fillBasket($store, $shopper);
        $this->placeOrder($store, $shopper, $this->couponInfo($couponId));

        $second = $this->latestOrder($store);
        $this->assertNotNull($second, 'premise: a second order row was written');

        $this->assertSame(0.0, (float) $second->coupon_price,
            'a one-use coupon was applied a second time although its redemption WAS recorded. '
            . 'The cap is not reading `user_coupons`, so the measurements in this file no longer '
            . 'mean what they say -- re-derive them from Coupon::usesByCustomer() and '
            . 'Coupon::applyUsageLimit() before trusting anything below.');
        $this->assertSame(
            self::UNIT_PRICE * self::BASKET_UNITS,
            (float) $second->final_price,
            'premise: the exhausted coupon left the second basket at full price'
        );
    }

    // ================================================================================ the defect

    /**
     * THE TRIGGER, NOW CLOSED — and this case was rewritten exactly as its own earlier version
     * instructed, rather than repaired to keep passing. P4-F135.
     *
     * WHAT IT USED TO MEASURE. Post `coupon_info.coupon_code` = the real code (so `apply_coupon()`
     * grants the real discount) and `coupon_info.coupon_id` = an id no coupon holds. The handler
     * resolved `Coupon::find($posted_id)` to null, wrote the order and the detail row, and then
     * threw on `$Coupon->id` BETWEEN the two coupon rows: a discount that stood with no redemption
     * recorded against it, at a point of the caller's choosing. The previous version of this
     * docblock said that if this case ever failed on its first premise it was to be read rather
     * than repaired, because that would mean the handler no longer reaches the detail write on this
     * input. That is what happened, and for a better reason than the one anticipated: the posted
     * `coupon_id` is not read AT ALL any more. `App\Services\CouponRedemptionRecorder` resolves the
     * coupon by CODE within the request's store, so there is no caller-named id to be null.
     *
     * SO THE CASE IS INVERTED RATHER THAN DELETED. Deleting it would lose the only guard against
     * the trigger coming back, and this input — a real code beside a junk id — is precisely the
     * shape a future edit that "restores" the id lookup would reintroduce.
     *
     * THE CONSERVATION LAW IS STILL ASSERTED, unchanged in meaning: every discount that survives
     * must have a redemption recorded against it. It now holds the strong way (1 and 1) rather than
     * by rolling back (0 and 0). The WINDOW itself — a failure between the two writes, from any
     * cause — is still measured without this trigger by
     * `test_an_injected_failure_at_the_redemption_write_leaves_the_discount_unrecorded`.
     */
    public function test_a_caller_chosen_coupon_id_no_longer_breaks_the_write_or_steers_it(): void
    {
        [$store, $shopper, $couponId] = $this->storeWithOneUseCoupon();
        $this->fillBasket($store, $shopper);

        $seen = $this->watchTheCouponRows();

        $response = $this->placeOrder(
            $store,
            $shopper,
            $this->couponInfo($couponId, self::NONEXISTENT_COUPON_ID)
        );

        /* PREMISE — the checkout COMPLETED. The old trigger was an uncaught throw, so "no half
           write" would also be satisfied by a request that died before the order; this says it
           did not. */
        $this->assertNotNull($this->latestOrder($store),
            'the checkout wrote no order at all, so the assertions below cannot tell a closed '
            . 'trigger from a broken handler. Response: ' . $this->excerpt($response));

        /* Both coupon rows ran, in that order — the far edge of the old window was reached. */
        $this->assertTrue($seen->detail, 'no order_coupon_details write ran for an accepted coupon');
        $this->assertTrue($seen->redemption,
            'the order_coupon_details row was written and the `user_coupons` row was not — the '
            . 'half-write this case exists to forbid, now reachable some other way');

        $granted = $this->discountedOrders($store);
        $recorded = $this->redemptionsOf($couponId);

        $this->assertLessThanOrEqual($recorded, $granted,
            'A DISCOUNT STANDS WITH NO REDEMPTION RECORDED AGAINST IT. ' . $granted
            . ' discounted order(s) against ' . $recorded . ' row(s) in `user_coupons`, '
            . 'which is the only table `Coupon::usesByCustomer()`, `PerUsesCouponCount()`, '
            . '`coupon_uses()` and `UsesCouponCount()` count. The order and its redemption must '
            . 'stand or fall together.');

        /* AND THE POSTED ID STEERED NOTHING: the redemption is against the coupon whose CODE the
           server resolved, and no row exists for the id that was posted. */
        $this->assertSame(1, $recorded,
            'exactly one redemption of the real coupon is expected for one discounted order');
        $this->assertSame(0, $this->redemptionsOf(self::NONEXISTENT_COUPON_ID),
            'a redemption was recorded against the coupon id the CALLER posted, so request input '
            . 'still decides which coupon is spent');
    }

    /**
     * THE SAME WINDOW, PRICED: a one-use coupon pays out twice.
     *
     * Request 1 breaks in the window; request 2 is an ordinary, well-formed checkout with the
     * correct `coupon_id`. Today both are discounted and only the second is recorded, so the
     * merchant funds two redemptions of a coupon they limited to one. Under a transaction the
     * first rolls back and the second is the one allowed redemption -- one discount, one record.
     *
     * The second request needs its own basket because the first deletes the cart rows before the
     * order write (ApiController:4023), which is also why the intake claim does not refuse it:
     * new `carts.id` values are a different fingerprint (:3407-3440).
     */
    public function test_a_broken_write_lets_a_one_use_coupon_pay_out_twice(): void
    {
        [$store, $shopper, $couponId] = $this->storeWithOneUseCoupon();

        $this->fillBasket($store, $shopper);
        $seen = $this->watchTheCouponRows();
        $this->placeOrderExpectingAThrow(
            $store,
            $shopper,
            $this->couponInfo($couponId, self::NONEXISTENT_COUPON_ID)
        );

        $this->assertTrue($seen->detail,
            'premise: the first request entered the window between the two coupon rows');

        $this->fillBasket($store, $shopper);
        $response = $this->placeOrder($store, $shopper, $this->couponInfo($couponId));

        $this->assertSame(1, (int) $response->json('status'),
            'premise: the second, well-formed checkout completed: ' . $this->excerpt($response));

        $granted = $this->discountedOrders($store);
        $recorded = $this->redemptionsOf($couponId);

        $this->assertLessThanOrEqual($recorded, $granted,
            'A COUPON LIMITED TO ONE USE PAID OUT ' . $granted . ' TIME(S) AGAINST ' . $recorded
            . ' RECORDED REDEMPTION(S). The first checkout kept its discount while the write that '
            . 'records the redemption never ran, so the cap saw a fresh coupon on the second. '
            . 'With the order and its `user_coupons` row in one transaction the first would roll '
            . 'back and the second would be the single redemption the merchant allowed.');
    }

    /**
     * THE SAME MEASUREMENT WITHOUT THE CALLER-CONTROLLED TRIGGER.
     *
     * This one IS an injection, and its limit is stated rather than implied: it proves nothing
     * about how a failure arrives in production, only that ANY failure landing on the redemption
     * write leaves the discount granted and unrecorded. That is the property a transaction fixes
     * and a null guard does not. The request is otherwise ordinary and well formed -- the correct
     * `coupon_id`, the correct code -- so nothing about the input is doing the work.
     *
     * The window is not narrow in production terms: between `$OrderCouponDetail->save()` and
     * `$UserCoupon->save()` sit an Eloquent insert, two `Cachable` cache flushes and, on MySQL,
     * a lock wait that can time out.
     */
    public function test_an_injected_failure_at_the_redemption_write_leaves_the_discount_unrecorded(): void
    {
        [$store, $shopper, $couponId] = $this->storeWithOneUseCoupon();
        $this->fillBasket($store, $shopper);

        $seen = $this->watchTheCouponRows();

        UserCoupon::creating(static function (): void {
            throw new \RuntimeException('simulated failure at the coupon redemption write');
        });

        $threw = $this->placeOrderExpectingAThrow($store, $shopper, $this->couponInfo($couponId));

        $this->assertTrue($seen->detail,
            'premise: the detail row was written, so the failure landed inside the window');
        $this->assertNotNull($threw, 'premise: the request threw');
        $this->assertSame('simulated failure at the coupon redemption write', $threw->getMessage(),
            'something OTHER than the injected failure threw, so this is measuring a different '
            . 'window: ' . $threw->getMessage());

        $granted = $this->discountedOrders($store);
        $recorded = $this->redemptionsOf($couponId);

        $this->assertLessThanOrEqual($recorded, $granted,
            'A FAILURE AT THE REDEMPTION WRITE LEFT ' . $granted . ' DISCOUNTED ORDER(S) AND '
            . $recorded . ' RECORDED REDEMPTION(S). The input here is well formed, so this is the '
            . 'transaction boundary and nothing else: any throw, deadlock or lock-wait timeout '
            . 'between ApiController:4240 and :4252 has this effect.');
    }

    // ====================================================================== negative regression

    /**
     * THE NEGATIVE REGRESSION. A basket with no coupon must be entirely unaffected -- by the
     * defect and by its fix.
     *
     * A transaction around the order write touches every storefront checkout, coupon or not, and
     * the three cases above would all still pass against a handler that refused coupon-bearing
     * orders outright. This is what stops that.
     */
    public function test_a_checkout_without_a_coupon_writes_its_order_and_records_no_redemption(): void
    {
        [$store, $shopper, $couponId] = $this->storeWithOneUseCoupon();
        $this->fillBasket($store, $shopper);

        $response = $this->placeOrder($store, $shopper, null);

        $this->assertSame(200, $response->getStatusCode(), $this->excerpt($response));
        $this->assertSame(1, (int) $response->json('status'),
            'a plain COD checkout was refused: ' . $this->excerpt($response));

        $order = $this->latestOrder($store);
        $this->assertNotNull($order, 'a checkout with no coupon wrote no order');

        $this->assertSame(0.0, (float) $order->coupon_price, 'premise: no coupon was applied');
        $this->assertSame(
            self::UNIT_PRICE * self::BASKET_UNITS,
            (float) $order->final_price,
            'a basket with no coupon was not charged its own subtotal'
        );
        $this->assertSame(0, $this->redemptionsOf($couponId),
            'a checkout that used no coupon consumed one');
        $this->assertSame(0, $this->detailRowsFor($order->id),
            'a checkout that used no coupon wrote a coupon detail row');
    }

    // ==================================================================================== fixture

    /**
     * A tenant, a shopper and a flat coupon capped at ONE use in total and ONE per shopper.
     *
     * The cap matters: `coupon_limit` 0 or null reads as "no total limit"
     * (`Coupon::applyUsageLimit()`), and `coupon_limit_user` is gated on `!empty()`, so the
     * default fixture of the sibling suites cannot demonstrate a cap being evaded at all.
     *
     * @return array{0: \App\Models\Store, 1: Customer, 2: int}
     */
    private function storeWithOneUseCoupon(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'f026-plan-' . uniqid(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'off',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->save();

        /* Several handlers read the platform admin through `Cache::remember('admin_details', ...)`,
           so without this they see the pre-fixture User and `$plan->shipping_method` throws before
           the window this file is about. */
        Cache::forget('admin_details');

        foreach (['out_of_stock_threshold' => '0', 'notification' => '[]'] as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => $value,
                'created_by' => $merchant->id, 'store_id' => $store->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('countries')->insertOrIgnore(['id' => 101, 'name' => 'Syria', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('states')->insertOrIgnore(['id' => 1, 'name' => 'Damascus', 'country_id' => 101, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cities')->insertOrIgnore(['id' => 1, 'name' => 'Damascus', 'country_id' => 101, 'state_id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $shopper = Customer::forceCreate([
            'first_name' => 'F026', 'last_name' => 'Shopper',
            'email' => 'f026-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);

        $couponId = (int) DB::table('coupons')->insertGetId([
            'coupon_name' => 'Flat ' . self::DISCOUNT,
            'coupon_code' => self::COUPON_CODE,
            'coupon_type' => 'flat',
            'discount_amount' => self::DISCOUNT,
            'coupon_limit' => 1,
            'coupon_limit_user' => 1,
            'coupon_expiry_date' => now()->addYear()->toDateString(),
            'status' => 1, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertNull(
            DB::table('coupons')->where('id', self::NONEXISTENT_COUPON_ID)->first(),
            'premise: coupon id ' . self::NONEXISTENT_COUPON_ID . ' does not exist, which is what '
            . 'makes Coupon::find() return null in the trigger case'
        );

        return [$store, $shopper, $couponId];
    }

    /** A fresh basket. Each order deletes its cart rows, so a second checkout needs a new one. */
    private function fillBasket($store, Customer $shopper): Product
    {
        $product = Product::withoutEvents(fn () => Product::forceCreate([
            'name' => 'Widget', 'slug' => 'f026-' . uniqid(),
            'price' => self::UNIT_PRICE, 'sale_price' => self::UNIT_PRICE,
            'product_stock' => 50, 'track_stock' => 0, 'variant_product' => 0,
            'status' => 1, 'trending' => 0, 'stock_status' => 'in_stock',
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]));

        Cart::forceCreate([
            'customer_id' => $shopper->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => self::BASKET_UNITS, 'price' => self::UNIT_PRICE, 'store_id' => $store->id,
        ]);

        return $product;
    }

    /**
     * `billing_postecode` is misspelled in the handler and every `delivery_*` key is required;
     * `billing_state` / `billing_country` are IDS despite the string columns. Copied from
     * `OrderTotalCouponTest::billing()`, whose docblock carries the evidence for both.
     */
    private function billing(): array
    {
        return [
            'firstname' => 'F026', 'lastname' => 'Shopper', 'email' => 'f026@example.test',
            'billing_user_telephone' => '555111222',
            'billing_address' => 'Street', 'billing_postecode' => '11111',
            'billing_country' => 101, 'billing_state' => 1,
            'billing_city' => 'Damascus', 'billing_company_name' => '',
            'delivery_address' => 'Street', 'delivery_postcode' => '11111',
            'delivery_country' => 101, 'delivery_state' => 1,
            'delivery_city' => 'Damascus',
        ];
    }

    /**
     * The `coupon_info` block a client echoes back from `cart-list`.
     *
     * `$couponIdOverride` is the whole trigger: the handler reads the ID from here (:4227, :4232)
     * and the DISCOUNT from the server's own `apply_coupon()` run on the CODE (:3620-3678), so the
     * two can be made to disagree by a caller.
     */
    private function couponInfo(int $couponId, ?int $couponIdOverride = null): array
    {
        return [
            'coupon_id' => $couponIdOverride ?? $couponId,
            'coupon_name' => 'Flat ' . self::DISCOUNT,
            'coupon_code' => self::COUPON_CODE,
            'coupon_discount_type' => 'flat',
            'coupon_discount_number' => self::DISCOUNT,
            'coupon_discount_amount' => self::DISCOUNT,
            'coupon_final_amount' => 0,
        ];
    }

    private function placeOrder($store, Customer $shopper, ?array $couponInfo)
    {
        $body = [
            'customer_id' => $shopper->id,
            'billing_info' => $this->billing(),
            'payment_type' => 'cod',
        ];

        if ($couponInfo !== null) {
            $body['coupon_info'] = $couponInfo;
        }

        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $shopper->createToken('f026')->plainTextToken,
        ])->postJson('/api/' . $store->slug . '/place-order', $body);
    }

    /**
     * Drive the route with the framework handler OFF, so the failure arrives as the exception the
     * handler threw rather than as a rendered 500 whose body says nothing about where it broke.
     */
    private function placeOrderExpectingAThrow($store, Customer $shopper, array $couponInfo): ?\Throwable
    {
        $this->withoutExceptionHandling();

        try {
            $this->placeOrder($store, $shopper, $couponInfo);
        } catch (\Throwable $e) {
            $this->withExceptionHandling();

            return $e;
        }

        $this->withExceptionHandling();

        return null;
    }

    /**
     * Watch the two coupon writes AS THEY HAPPEN.
     *
     * A `created` listener fires inside the request and sets a PHP variable, which a rollback
     * cannot undo -- so these two premises read the same before and after a transaction is added,
     * which a post-request table read could not.
     *
     * A mutable OBJECT, not two closures: an arrow function captures by VALUE at the moment it is
     * created, so `fn () => $detail` would have reported the pre-request `false` forever. That is
     * how the first version of this file failed its own premise on all three cases.
     *
     * @return object{detail: bool, redemption: bool}
     */
    private function watchTheCouponRows(): object
    {
        $seen = new class {
            public bool $detail = false;

            public bool $redemption = false;
        };

        OrderCouponDetail::created(static function () use ($seen): void {
            $seen->detail = true;
        });

        UserCoupon::created(static function () use ($seen): void {
            $seen->redemption = true;
        });

        return $seen;
    }

    /**
     * Orders in this store that SURVIVED carrying a discount.
     *
     * Read through the table: `Order` is `Cachable` and the handler writes through Eloquent, and
     * `Order` is NOT in `config/tenancy.php`'s `scoped_models`, so an Eloquent read here answers
     * against whichever tenant happens to be resolved rather than against the row set meant.
     */
    private function discountedOrders($store): int
    {
        return (int) DB::table('orders')
            ->where('store_id', $store->id)
            ->where('coupon_price', '>', 0)
            ->count();
    }

    /** Redemptions recorded for this coupon -- the only figure either cap counts. */
    private function redemptionsOf(int $couponId): int
    {
        return (int) DB::table('user_coupons')->where('coupon_id', $couponId)->count();
    }

    private function detailRowsFor($orderId): int
    {
        return (int) DB::table('order_coupon_details')->where('order_id', $orderId)->count();
    }

    private function latestOrder($store)
    {
        return DB::table('orders')->where('store_id', $store->id)->orderByDesc('id')->first();
    }

    private function excerpt($response): string
    {
        return substr((string) $response->getContent(), 0, 400);
    }
}
