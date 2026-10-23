<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Security\SecurityTestCase;
use App\Models\AddOnManager;

/**
 * THE SHOPPER DECIDED WHAT THEY OWED.  P1-ORD-02 criterion 2, the `pending_payment` limb.
 * =================================================================================================
 *
 * WHAT WAS WRONG. `PaymentController::prepareFinalRequestData()` resolves the payable of a
 * PartialPayments checkout through the module's own server-side `ManageDeposit()` -- except on one
 * branch:
 *
 *     } else {
 *         $new_array['cartlist_final_price'] = $request->payment_amount ?? 0;
 *     }
 *
 * That `else` is reached when the body carries `partial_payment_type = pending_payment`, which is the
 * form the module prints for a shopper settling the OUTSTANDING BALANCE of an earlier order
 * (packages/workdo/PartialPayments/src/resources/views/theme/payment-view.blade.php:110). The amount
 * owed is a server fact -- it is the `payment_amount` of the `order_partial_payments` row the module
 * wrote when the deposit was taken -- and the view prints it into a hidden field, which the handler
 * then believed.
 *
 * `cartlist_final_price` IS THE CHARGE, not merely the record. Every gateway arm in
 * `App\Services\PaymentService` reads that one key for the amount it hands the provider (:447, :554,
 * :841, :963, :1037 and twenty more), and `GuardsGatewayReturn::serverPayableAmount()` reads the same
 * key to decide what AuthorizeNet captures. So the posted figure was the money, not a label on it.
 *
 * THE ROW IS ALSO NOT CHECKED FOR OWNERSHIP ANYWHERE. `partial_payment_id` travels with the body into
 * the stored checkout state and, on the way back, straight into
 * `PartialPayments::ManagePartialPayment()` (PaymentController:2183, PaymentService:2189), which does
 * `OrderPartialPayments::find($request_data['partial_payment_id'])` and flips that row's
 * `payment_status` to `On Hold` with no `store_id` and no `customer_id` predicate -- `find()` on a
 * model that is in no `scoped_models` allowlist, so no global scope narrows it either. The amount and
 * the identity are the same question and are answered together here.
 *
 * AND THE BRANCH WAS REACHABLE BY ANNOUNCEMENT. Both return sites gate on
 *
 *     if ((isset($request->partial_payment_type) || $request->partial_payment_type == 'pending_payment'))
 *
 * -- `||`, where every reading of the intent says `&&`. `isset()` alone is true for ANY value of a
 * caller-supplied key, so posting `partial_payment_type=x` on an ordinary checkout diverted the
 * gateway return into the settle-a-balance branch, where `find(null)` is NULL and the next line is a
 * fatal. The shopper is charged and no order is ever written: a charge with no record, which is the
 * exact failure the owner's instruction names.
 *
 * WHAT IS MEASURED HERE. The storefront route a real shopper posts -- `POST {slug}/process-order`,
 * signed in, module active through the store owner's plan, `enable_partial_payment = on` -- and the
 * observable is the one key that is both the charge and the record. Four values are distinguishable
 * by construction, so no assertion below can hold for the wrong reason:
 *
 *     1.00    the figure the attacker posted        (the defect)
 *    40.00    the pending row's own payment_amount  (the fix)
 *    10.00    the module's 10% deposit limb         (the OTHER branch, if the else is not reached)
 *   100.00    the plain cart total                  (no partial-payments branch at all)
 */
class PendingPaymentAmountSourceTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const BASKET = 100.00;
    private const OUTSTANDING = 40.00;      // what the server says is still owed
    private const FORGED = 1.00;            // what the attacker posts instead
    private const DEPOSIT_RULE_ANSWER = 10.00;

    /** The module, enabled through the PRODUCTION loader -- an add_on_managers row, not a harness poke. */
    private function enablePartialPayments(): void
    {
        AddOnManager::create([
            'module' => 'PartialPayments', 'name' => 'Partial Payments',
            'monthly_price' => 0, 'yearly_price' => 0, 'is_enable' => 1,
            'package_name' => 'partial-payments',
        ]);

        Cache::flush();

        $provider = new \App\Providers\PackageServiceProvider($this->app);
        $loadAddons = new \ReflectionMethod($provider, 'loadAddons');
        $loadAddons->setAccessible(true);
        $loadAddons->invoke($provider, ['PartialPayments']);

        $this->assertTrue(\App\Facades\ModuleFacade::has('PartialPayments'),
            'Premise: the module is registered with the loader. Without the add_on_managers row no '
            . 'WorkDo module loads in a test run at all, so the branch under test would not exist.');
    }

    /** The module's own tables, which RefreshDatabase does not create. */
    private function migrateModuleTables(): void
    {
        foreach (glob(base_path('packages/workdo/PartialPayments/src/database/migrations/*.php')) ?: [] as $file) {
            $migration = require $file;
            if (is_object($migration) && method_exists($migration, 'up')) {
                $migration->up();
            }
        }
    }

    private function tenant(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'pp-' . uniqid(), 'price' => 0, 'themes' => '[]', 'storage_limit' => '100',
            'shipping_method' => 'off',
            // module_is_active() resolves through the STORE OWNER'S PLAN on any route carrying a slug
            // (app/Helper/helper.php:1359-1381). Without this the branch is unreachable.
            'modules' => 'PartialPayments',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->save();
        Cache::forget('admin_details');

        foreach ([
            'stock_management' => 'on',
            'out_of_stock_threshold' => '0',
            'notification' => '[]',
            'enable_partial_payment' => 'on',   // the setting the branch is gated on
            'deposit_selection' => 'pay_deposit',
            'deposit_type' => 'percent',
            'deposit_amount' => '10',
            'force_deposit' => 'on',
        ] as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => $value, 'created_by' => $merchant->id,
                'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        (new \App\Models\Setting)->flushCache();

        DB::table('countries')->insertOrIgnore(['id' => 101, 'name' => 'S', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('states')->insertOrIgnore(['id' => 1, 'name' => 'D', 'country_id' => 101, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cities')->insertOrIgnore(['id' => 1, 'name' => 'D', 'country_id' => 101, 'state_id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return [$merchant, $store];
    }

    private function shopperWithBasket($store): Customer
    {
        $customer = Customer::forceCreate([
            'first_name' => 'A', 'last_name' => 'B', 'email' => 'pp-' . uniqid() . '@e.test',
            'mobile' => '555' . random_int(100000, 999999), 'password' => bcrypt('x'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);

        $product = Product::forceCreate([
            'name' => 'p', 'slug' => 'p-' . uniqid(), 'product_stock' => 50, 'track_stock' => 1,
            'variant_product' => 0, 'price' => self::BASKET, 'sale_price' => self::BASKET,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);

        // A 10% per-product deposit rule, so the OTHER branch of the `if` answers 10.00 rather than
        // echoing the subtotal. Without it the deposit limb and the plain-cart limb are the same
        // number and two of the four outcomes above stop being distinguishable.
        DB::table('partial_payments')->insert([
            'product_id' => $product->id, 'enable_deposit' => 'on', 'force_deposit' => 'on',
            'deposit_type' => 'percent', 'deposit_amount' => 10, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Cachable: a raw insert leaves the module's own read serving the pre-write (empty) answer.
        (new \Workdo\PartialPayments\app\Models\PartialPayments)->flushCache();

        Cart::forceCreate(['customer_id' => $customer->id, 'product_id' => $product->id,
            'variant_id' => 0, 'qty' => 1, 'price' => self::BASKET, 'store_id' => $store->id]);

        return $customer;
    }

    /** The row the module writes for the balance still owed on an earlier order. */
    private function pendingRow($store, $customerId, array $overrides = []): int
    {
        return DB::table('order_partial_payments')->insertGetId(array_merge([
            'order_id' => 'ORD-' . uniqid(),
            'customer_id' => $customerId,
            'payment' => 'Second Payment',
            'payment_type' => '-',
            'order_status' => 'Pending',
            'payment_amount' => self::OUTSTANDING,
            'payment_status' => 'Pending payment',
            'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    private function billing(): array
    {
        return ['firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.test',
            'billing_user_telephone' => '123', 'billing_address' => 'x', 'billing_postecode' => '1',
            'billing_country' => '101', 'billing_state' => '1', 'billing_city' => '1',
            'billing_company_name' => '', 'delivery_address' => 'x', 'delivery_postcode' => '1',
            'delivery_country' => '101', 'delivery_state' => '1', 'delivery_city' => '1'];
    }

    /**
     * Drive a real checkout initiation and hand back the stored state.
     *
     * `easebuzz` because its arm stores the state BEFORE it tries to reach the provider
     * (PaymentService:4375), so the key under test is observable with no network and no stub.
     */
    private function initiate($store, Customer $customer, array $extra): ?array
    {
        $this->actingAs($customer, 'customers')->post('/' . $store->slug . '/process-order', array_merge([
            'slug' => $store->slug, 'store_id' => $store->id, 'customer_id' => $customer->id,
            'payment_type' => 'easebuzz', 'billing_info' => $this->billing(),
        ], $extra));

        $state = session('easebuzz_request_data');

        return is_array($state) ? $state : null;
    }

    private function setUpStore(): array
    {
        $this->enablePartialPayments();
        $this->migrateModuleTables();

        [, $store] = $this->tenant();

        return [$store, $this->shopperWithBasket($store)];
    }

    /**
     * THE PREMISE, MEASURED BEFORE ANY CLAIM ABOUT THE DEFECT.
     *
     * Three things have to be true or every assertion below holds for the wrong reason: the module's
     * table exists, the module branch is entered at all, and the `pending_payment` limb is a
     * DIFFERENT limb from the deposit one. The last is what the two figures establish -- an ordinary
     * partial-payments checkout answers with the module's 10.00 deposit, so a `pending_payment`
     * checkout answering with anything else proves the else limb is the one that ran.
     */
    public function test_the_pending_payment_limb_is_a_distinct_reachable_branch(): void
    {
        [$store, $customer] = $this->setUpStore();

        $this->assertTrue(Schema::hasTable('order_partial_payments'),
            'Premise: the module table exists, so a read of it is not swallowed.');

        $state = $this->initiate($store, $customer, []);

        $this->assertNotNull($state, 'Premise: an ordinary initiation stored a checkout state.');
        $this->assertEqualsWithDelta(self::DEPOSIT_RULE_ANSWER, (float) ($state['cartlist_final_price'] ?? 0), 0.005,
            'Premise: with no partial_payment_type the module\'s own ManageDeposit() priced the '
            . 'checkout at the 10% deposit. If this is 100.00 the module branch was never entered '
            . 'and nothing in this file measures the limb it claims to.');
    }

    /**
     * THE PAYABLE IS THE RECORDED BALANCE, AND NOTHING IN THE BODY SUPPLIES IT.
     *
     * The body here posts NO `payment_amount` at all, which is the sharpest form of the question:
     * before the fix the limb read `$request->payment_amount ?? 0` and answered 0.00, so the figure
     * below can only have come from the row.
     */
    public function test_the_payable_comes_from_the_recorded_balance(): void
    {
        [$store, $customer] = $this->setUpStore();
        $rowId = $this->pendingRow($store, $customer->id);

        $state = $this->initiate($store, $customer, [
            'partial_payment_type' => 'pending_payment',
            'partial_payment_id' => $rowId,
        ]);

        $this->assertNotNull($state,
            'Premise: the initiation reached a gateway arm and stored a checkout state. A null here '
            . 'would satisfy the amount assertion below for the wrong reason.');

        $this->assertEqualsWithDelta(self::OUTSTANDING, (float) ($state['cartlist_final_price'] ?? 0), 0.005,
            'the payable of a pending partial payment did not come from the recorded balance. '
            . 'cartlist_final_price is the figure every gateway arm charges, so this is the money, '
            . 'not a label on it.');
    }

    /**
     * AND THE SERVER FIGURE IS NOT A COINCIDENCE OF THE FIXTURE.
     *
     * A second row with a different outstanding balance: the payable tracks the ROW, not a constant
     * and not the cart.
     */
    public function test_the_payable_tracks_the_row_not_the_fixture(): void
    {
        [$store, $customer] = $this->setUpStore();
        $rowId = $this->pendingRow($store, $customer->id, ['payment_amount' => 73.50]);

        $state = $this->initiate($store, $customer, [
            'partial_payment_type' => 'pending_payment',
            'partial_payment_id' => $rowId,
        ]);

        $this->assertNotNull($state, 'Premise: a checkout state was stored.');
        $this->assertEqualsWithDelta(73.50, (float) ($state['cartlist_final_price'] ?? 0), 0.005,
            'the payable must be the outstanding balance recorded on the row named by the request.');
    }

    /**
     * THE DEFECT: the payable of a pending balance was whatever the browser said it was.
     *
     * MEASURED before the fix, on this exact body: `cartlist_final_price` was stored -- and therefore
     * charged -- as 1.00 against a server-recorded outstanding balance of 40.00.
     *
     * REFUSED, NOT SILENTLY CORRECTED. The owner's instruction is "reject mismatches safely", and
     * that is also the kinder answer: the module's own view posts the row's figure, so an honest body
     * always agrees. A disagreement is tampering or a page left open while the balance moved, and
     * charging a different amount from the one on screen would be a surprise either way. So the
     * observable is that NO state was stored: the handler never reached a gateway arm, so nothing was
     * charged and nothing was recorded.
     */
    public function test_a_posted_payment_amount_cannot_set_the_payable(): void
    {
        [$store, $customer] = $this->setUpStore();
        $rowId = $this->pendingRow($store, $customer->id);

        $state = $this->initiate($store, $customer, [
            'partial_payment_type' => 'pending_payment',
            'partial_payment_id' => $rowId,
            'payment_amount' => self::FORGED,
        ]);

        $this->assertNull($state,
            'a checkout whose posted payment_amount disagrees with the recorded balance reached a '
            . 'gateway arm. The stored state IS the charge: every arm in PaymentService reads '
            . 'cartlist_final_price out of it for the amount it hands the provider.');
    }

    /**
     * AND THE TOLERANCE DOES NOT SWALLOW A REAL DISAGREEMENT.
     *
     * The comparison allows half a cent, so `40` and `40.00` agree. One cent short does not, and a
     * test that only drove a wildly wrong figure would not have shown that.
     */
    public function test_an_amount_one_cent_short_is_still_a_mismatch(): void
    {
        [$store, $customer] = $this->setUpStore();
        $rowId = $this->pendingRow($store, $customer->id);

        $state = $this->initiate($store, $customer, [
            'partial_payment_type' => 'pending_payment',
            'partial_payment_id' => $rowId,
            'payment_amount' => self::OUTSTANDING - 0.01,
        ]);

        $this->assertNull($state, 'a one-cent underpayment was accepted as agreeing with the record.');
    }

    /** An unparseable amount is a disagreement, not a zero. */
    public function test_a_non_numeric_amount_is_refused(): void
    {
        [$store, $customer] = $this->setUpStore();
        $rowId = $this->pendingRow($store, $customer->id);

        $state = $this->initiate($store, $customer, [
            'partial_payment_type' => 'pending_payment',
            'partial_payment_id' => $rowId,
            'payment_amount' => 'forty',
        ]);

        $this->assertNull($state, 'a non-numeric payment_amount was not treated as a disagreement.');
    }

    /** The honest body -- the exact figure the module's own view prints -- still goes through. */
    public function test_the_genuine_pending_payment_still_proceeds(): void
    {
        [$store, $customer] = $this->setUpStore();
        $rowId = $this->pendingRow($store, $customer->id);

        $state = $this->initiate($store, $customer, [
            'partial_payment_type' => 'pending_payment',
            'partial_payment_id' => $rowId,
            'payment_amount' => self::OUTSTANDING,
        ]);

        $this->assertNotNull($state,
            'PRESERVE FIRST: the genuine flow posts the row\'s own payment_amount and must be '
            . 'unaffected. A null here is a regression for every real shopper settling a balance.');
        $this->assertEqualsWithDelta(self::OUTSTANDING, (float) ($state['cartlist_final_price'] ?? 0), 0.005,
            'the genuine flow is charged the recorded balance.');
    }

    /**
     * ANOTHER SHOPPER'S BALANCE IS NOT THIS SHOPPER'S TO SETTLE.
     *
     * `OrderPartialPayments::find()` carries no customer predicate and the model is in no
     * `scoped_models` allowlist, so nothing downstream would have noticed.
     */
    public function test_a_pending_row_belonging_to_another_customer_is_refused(): void
    {
        [$store, $customer] = $this->setUpStore();
        $victim = $this->shopperWithBasket($store);
        $rowId = $this->pendingRow($store, $victim->id);

        $state = $this->initiate($store, $customer, [
            'partial_payment_type' => 'pending_payment',
            'partial_payment_id' => $rowId,
            'payment_amount' => self::OUTSTANDING,
        ]);

        $this->assertNull($state,
            'a shopper named another customer\'s pending balance and the checkout proceeded. The '
            . 'stored state is what the gateway return reads back to flip that row to paid.');
    }

    /** And neither is another STORE's. */
    public function test_a_pending_row_belonging_to_another_store_is_refused(): void
    {
        [$store, $customer] = $this->setUpStore();
        [, $otherStore] = $this->makeTenant();
        $rowId = $this->pendingRow($otherStore, $customer->id);

        $state = $this->initiate($store, $customer, [
            'partial_payment_type' => 'pending_payment',
            'partial_payment_id' => $rowId,
            'payment_amount' => self::OUTSTANDING,
        ]);

        $this->assertNull($state,
            'a pending balance recorded against another tenant was settled through this store.');
    }

    /** A balance already settled cannot be settled again. */
    public function test_a_row_that_is_not_awaiting_payment_is_refused(): void
    {
        [$store, $customer] = $this->setUpStore();
        $rowId = $this->pendingRow($store, $customer->id, ['payment_status' => 'On Hold']);

        $state = $this->initiate($store, $customer, [
            'partial_payment_type' => 'pending_payment',
            'partial_payment_id' => $rowId,
            'payment_amount' => self::OUTSTANDING,
        ]);

        $this->assertNull($state,
            'a row already marked paid was re-presented for payment.');
    }

    /** Naming no row at all is not an invitation to believe the body. */
    public function test_a_missing_partial_payment_id_is_refused(): void
    {
        [$store, $customer] = $this->setUpStore();

        $state = $this->initiate($store, $customer, [
            'partial_payment_type' => 'pending_payment',
            'payment_amount' => self::FORGED,
        ]);

        $this->assertNull($state,
            'with no partial_payment_id there is no server figure to charge, so the checkout must '
            . 'refuse rather than fall back to the posted one.');
    }

    /**
     * `'5abc'` IS NOT ROW 5, AND is_numeric WOULD NOT HAVE SAID SO.
     *
     * MySQL coerces `'5abc'` to 5 in `WHERE id = ?` while `is_numeric('5abc')` is false -- the
     * recorded identifier-parsing trap. FILTER_VALIDATE_INT is the predicate that answers both.
     */
    public function test_a_non_integer_partial_payment_id_is_refused(): void
    {
        [$store, $customer] = $this->setUpStore();
        $rowId = $this->pendingRow($store, $customer->id);

        $state = $this->initiate($store, $customer, [
            'partial_payment_type' => 'pending_payment',
            'partial_payment_id' => $rowId . 'abc',
            'payment_amount' => self::OUTSTANDING,
        ]);

        $this->assertNull($state,
            'a non-integer row identifier reached the lookup. On MySQL that is a silent coercion to '
            . 'the leading digits, which is a different row than the one the caller named.');
    }

    /**
     * A ZERO BALANCE IS NOT A PAYABLE.
     *
     * The module's own view already filters these out (`whereNot('payment_amount', 0)`), so a request
     * naming one did not come from the page.
     */
    public function test_a_zero_balance_row_is_refused(): void
    {
        [$store, $customer] = $this->setUpStore();
        $rowId = $this->pendingRow($store, $customer->id, ['payment_amount' => 0]);

        $state = $this->initiate($store, $customer, [
            'partial_payment_type' => 'pending_payment',
            'partial_payment_id' => $rowId,
            'payment_amount' => 0,
        ]);

        $this->assertNull($state, 'a zero-amount row was taken to a gateway.');
    }

    /**
     * THE `||` THAT SHOULD HAVE BEEN `&&`: an ordinary checkout cannot be diverted into the
     * settle-a-balance branch by announcing a partial_payment_type.
     *
     * The observable is the ORDER. Both return sites answer `redirect()->back()` from that branch
     * without writing one, so a charge taken on that journey leaves no record at all.
     */
    public function test_an_arbitrary_partial_payment_type_does_not_divert_the_gateway_return(): void
    {
        [$store, $customer] = $this->setUpStore();

        $state = $this->initiate($store, $customer, ['partial_payment_type' => 'not-pending']);

        $this->assertNotNull($state, 'Premise: the ordinary checkout still stored a state.');

        $before = (int) DB::table('orders')->count();

        $this->actingAs($customer, 'customers')
            ->withSession(['easebuzz_request_data' => $state])
            ->post('/' . $store->slug . '/get-payment-easebuzz',
                ['status' => 'success', 'txnid' => 'pp-' . uniqid()]);

        $this->assertSame($before + 1, (int) DB::table('orders')->count(),
            'the return was diverted into the settle-a-balance branch by a partial_payment_type the '
            . 'caller invented, so the payment produced no order row.');
    }

    /**
     * A GATEWAY WHOSE RETURN CANNOT SETTLE A BALANCE DOES NOT GET ONE TO CHARGE.
     *
     * FOUND BY THE READ-ONLY REVIEW OF THIS CHANGE. `KhaltiPaymnetController::getOrderPaymentStatus`
     * reads the stored checkout state and writes an order and carries NO partial-payment branch
     * (grepped: zero `partial_payment` matches in the file). So the fix as first written priced a
     * khalti pending-balance checkout at the outstanding amount -- correctly, from the server record
     * -- khalti charged that figure (`resources/views/payment/khalti.blade.php:16` reads
     * `cartlist_final_price`), and the return then wrote a FULL-PRICE order from the cart with the
     * balance still owing. The payable was a server fact and it priced the wrong thing.
     *
     * Refused at INITIATION, so nothing is charged. The observable is that no khalti checkout state
     * was stored: `PaymentService::processKhalti` puts `request_data` in the session at :3604,
     * before it renders the payment view, so a stored state means the shopper reached the gateway.
     */
    public function test_a_gateway_that_cannot_settle_a_balance_refuses_the_checkout(): void
    {
        [$store, $customer] = $this->setUpStore();
        $rowId = $this->pendingRow($store, $customer->id);

        $this->actingAs($customer, 'customers')->post('/' . $store->slug . '/process-order', [
            'slug' => $store->slug, 'store_id' => $store->id, 'customer_id' => $customer->id,
            'payment_type' => 'khalti', 'billing_info' => $this->billing(),
            'partial_payment_type' => 'pending_payment',
            'partial_payment_id' => $rowId,
            'payment_amount' => self::OUTSTANDING,
        ]);

        $this->assertNull(session('request_data'),
            'a pending-balance checkout reached khalti, whose return cannot settle a balance. The '
            . 'card is charged the balance and the return then writes a full-price order, leaving '
            . 'the balance owing.');

        $this->assertSame('Pending payment',
            (string) DB::table('order_partial_payments')->where('id', $rowId)->value('payment_status'),
            'and the balance is untouched, because nothing was charged.');
    }

    /**
     * AND THE SAME GATEWAY STILL TAKES AN ORDINARY CHECKOUT.
     *
     * The refusal is scoped to the pending-balance limb. A khalti checkout that is not settling a
     * balance must be unaffected -- otherwise the fix has removed a payment method.
     */
    public function test_an_ordinary_checkout_on_that_gateway_is_unaffected(): void
    {
        [$store, $customer] = $this->setUpStore();

        $this->actingAs($customer, 'customers')->post('/' . $store->slug . '/process-order', [
            'slug' => $store->slug, 'store_id' => $store->id, 'customer_id' => $customer->id,
            'payment_type' => 'khalti', 'billing_info' => $this->billing(),
        ]);

        $this->assertNotNull(session('request_data'),
            'PRESERVE FIRST: an ordinary khalti checkout no longer reaches the gateway. The refusal '
            . 'is meant to cover the pending-balance limb only.');
    }

    /**
     * THE BRANCH PREDICATE IS THE STORED STATE, NOT THE LIVE REQUEST.
     *
     * RAISED BY THE READ-ONLY REVIEW. `Request::merge()` only overwrites keys the stored state
     * HOLDS (GuardsGatewayReturn:174-176), so on an ordinary checkout -- whose state carries no
     * `partial_payment_type` -- a posted one survived the merge and diverted the return into the
     * settle-a-balance branch. The guard then refuses, but by that point `claimGatewayReturn()` has
     * consumed the claim and the state has been forgotten: the payment stands, no order is ever
     * written, and the shopper cannot retry.
     *
     * Reading the predicate from the state costs nothing for honest traffic -- a genuine pending
     * payment carries the key in its state, which is what
     * test_the_genuine_pending_payment_return_still_settles_the_balance proves.
     */
    public function test_a_partial_payment_type_posted_on_the_return_cannot_divert_it(): void
    {
        [$store, $customer] = $this->setUpStore();
        $rowId = $this->pendingRow($store, $customer->id);

        $state = $this->initiate($store, $customer, []);
        $this->assertNotNull($state, 'Premise: an ordinary initiation stored a checkout state.');
        $this->assertArrayNotHasKey('partial_payment_type', $state,
            'Premise: the ordinary state carries no partial_payment_type, which is what makes a '
            . 'posted one survive the merge.');

        $before = (int) DB::table('orders')->count();

        $this->actingAs($customer, 'customers')
            ->withSession(['easebuzz_request_data' => $state])
            ->post('/' . $store->slug . '/get-payment-easebuzz', [
                'status' => 'success', 'txnid' => 'pp-' . uniqid(),
                // The two keys the caller adds to the RETURN body, which the stored state does not hold.
                'partial_payment_type' => 'pending_payment',
                'partial_payment_id' => $rowId,
            ]);

        $this->assertSame($before + 1, (int) DB::table('orders')->count(),
            'a partial_payment_type posted on the RETURN diverted it into the settle branch. The '
            . 'claim is already consumed and the state forgotten by then, so the payment stands '
            . 'with no order and no way to retry.');

        $this->assertSame('Pending payment',
            (string) DB::table('order_partial_payments')->where('id', $rowId)->value('payment_status'),
            'and no balance was settled by a checkout that was never a pending payment.');
    }

    /**
     * THE SAME, ON THE STOREFRONT RETURN -- the handler every gateway without a controller of its
     * own comes back through.
     *
     * A REMOVAL CHECK IS WHY THIS EXISTS. Reverting the predicate to the live request in
     * `PaymentController::getProductStatus` SURVIVED the first removal run: the file's other tests
     * all drive the easebuzz controller, so the storefront return's copy of the guard had no
     * coverage at all. `POST {slug}/get-payment-status` (`store.payment.status`) is that handler,
     * and it is the return used by stripe, paypal, razorpay and the rest.
     */
    public function test_a_posted_partial_payment_type_cannot_divert_the_storefront_return(): void
    {
        [$store, $customer] = $this->setUpStore();
        $rowId = $this->pendingRow($store, $customer->id);

        $state = $this->initiate($store, $customer, []);
        $this->assertNotNull($state, 'Premise: an ordinary initiation stored a checkout state.');
        $this->assertArrayNotHasKey('partial_payment_type', $state,
            'Premise: the ordinary state carries no partial_payment_type.');

        $before = (int) DB::table('orders')->count();

        $response = $this->actingAs($customer, 'customers')
            ->withSession(['request_data' => $state])
            ->post('/' . $store->slug . '/get-payment-status', [
                'return_type' => 'success', 'payment_processor' => 'stripe',
                'partial_payment_type' => 'pending_payment',
                'partial_payment_id' => $rowId,
            ]);

        $this->assertNotSame(404, $response->getStatusCode(),
            'Premise: the storefront return route exists. A 404 would satisfy both assertions '
            . 'below for the wrong reason.');

        $this->assertSame($before + 1, (int) DB::table('orders')->count(),
            'a partial_payment_type posted on the STOREFRONT return diverted it into the settle '
            . 'branch, so the payment produced no order.');

        $this->assertSame('Pending payment',
            (string) DB::table('order_partial_payments')->where('id', $rowId)->value('payment_status'),
            'and no balance was settled by a checkout that was never a pending payment.');
    }

    /**
     * PRESERVE FIRST: the genuine journey still settles the balance, end to end.
     *
     * Initiate honestly, then drive the gateway return with the state the initiation stored. The row
     * must come back `On Hold` -- which is what the module calls settled -- and no order row may
     * appear, because settling a balance is not placing an order.
     */
    public function test_the_genuine_pending_payment_return_still_settles_the_balance(): void
    {
        [$store, $customer] = $this->setUpStore();
        $rowId = $this->pendingRow($store, $customer->id);

        $state = $this->initiate($store, $customer, [
            'partial_payment_type' => 'pending_payment',
            'partial_payment_id' => $rowId,
            'payment_amount' => self::OUTSTANDING,
        ]);

        $this->assertNotNull($state, 'Premise: the honest initiation stored a checkout state.');

        $before = (int) DB::table('orders')->count();

        $this->actingAs($customer, 'customers')
            ->withSession(['easebuzz_request_data' => $state])
            ->post('/' . $store->slug . '/get-payment-easebuzz',
                ['status' => 'success', 'txnid' => 'pp-' . uniqid()]);

        $this->assertSame('On Hold',
            (string) DB::table('order_partial_payments')->where('id', $rowId)->value('payment_status'),
            'the genuine return no longer settles the balance -- a regression for every real shopper '
            . 'paying off an order.');
        $this->assertSame($before, (int) DB::table('orders')->count(),
            'settling a balance wrote an order row. That branch returns before the order writer.');
    }

    /**
     * AND THE RETURN HOLDS ON ITS OWN, WHICH IS WHY THE GUARD IS IN BOTH PLACES.
     *
     * `enable_partial_payment` gates the INITIATION limb only. With it off, that limb never runs and
     * never validates anything -- yet the return still enters the settle-a-balance branch, because it
     * gates on the plan and the module, not on the setting. The stored state here is built directly
     * for that reason: it is the shape a store with the setting off produces.
     */
    public function test_another_customers_balance_cannot_be_settled_on_the_return(): void
    {
        [$store, $customer] = $this->setUpStore();
        $victim = $this->shopperWithBasket($store);
        $victimRow = $this->pendingRow($store, $victim->id);

        $state = $this->initiate($store, $customer, []);
        $this->assertNotNull($state, 'Premise: an ordinary initiation stored a checkout state.');

        $state['partial_payment_type'] = 'pending_payment';
        $state['partial_payment_id'] = $victimRow;

        $this->actingAs($customer, 'customers')
            ->withSession(['easebuzz_request_data' => $state])
            ->post('/' . $store->slug . '/get-payment-easebuzz',
                ['status' => 'success', 'txnid' => 'pp-' . uniqid()]);

        $this->assertSame('Pending payment',
            (string) DB::table('order_partial_payments')->where('id', $victimRow)->value('payment_status'),
            'one shopper settled another\'s outstanding balance. ManagePartialPayment() is a bare '
            . 'find() on a model no global scope narrows, so nothing else would have noticed.');
    }
}
