<?php

namespace Tests\Feature\Security;

use App\Khalti\Khalti;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * D-13 / D-18 -- STOCK TAKEN BEFORE THE PAYMENT IS KNOWN, ON THE KHALTI RETURN.
 *
 * WHAT THE REGISTER ALREADY ESTABLISHED, AND WHAT IT DID NOT. D-18 landed the ATOMICITY of these
 * decrements (every raw `stock - qty` now goes through ProductStockService) and the owner ruled
 * that this is "تحسين مهم للذرّية والمحاسبة، لكنه ليس إثباتاً لمنع استنزاف المخزون" -- an atomic
 * decrement is still a decrement. D-13's other half, the UNVERIFIED decrement, stayed open with
 * the reasoning that the decrement "cannot be made to wait" because no column records that an
 * order consumed stock.
 *
 * THAT REASONING IS ABOUT A DIFFERENT FIX. It holds for deferring a decrement to a LATER request
 * -- a webhook, a confirmation callback -- which would need durable evidence that this order has
 * not already taken its stock. It does not hold here, because on this handler the decrement and
 * the verification are in the SAME request, in this order:
 *
 *     claimGatewayReturn()            <- replay claim, already correct
 *     ... coupon, delivery, tax ...
 *     THE STOCK LOOP                  <- decrements every line
 *     $serverPayable = ...            <- refuses when the state has no server price
 *     $khalti->verifyPayment(...)     <- the first time this server asks whether money moved
 *     if (verified || unverifiable)  { write the order }
 *     else                           { refuse }
 *
 * So the gap is an ORDERING of two statements inside one request, and closing it needs no schema
 * and no marker: hoist the verification above the loop. Nothing in the verification block reads
 * anything the loop produces -- it uses `$request->payload`, `$store` and the stored checkout
 * state, all resolved long before the loop.
 *
 * WHAT THESE TESTS MEASURE RATHER THAN ARGUE.
 *
 * 1. REACHABILITY FIRST, which D-18 requires before any change and which the register records as
 *    NOT ESTABLISHED: `test_the_stock_loop_is_reached_at_all`. Every other case here is vacuous
 *    if the handler cannot reach the loop, so the premise is asserted on its own and the negative
 *    cases below each re-assert their own premise.
 * 2. THE DRAIN: a capture Khalti REJECTED, and a return whose stored state carries no server
 *    price, each take stock and never give it back.
 * 3. THE CONTROL that keeps the fix honest: a confirmed capture must still decrement, exactly
 *    once, and the deliberate UNVERIFIABLE branch must still decrement AND write its Pending
 *    order. A fix that stopped the drain by not decrementing would pass (2) and fail these.
 *
 * COD IS NOT IN SCOPE HERE and is not conflated with it (D-18 condition 3): this route is
 * Khalti's electronic return. A COD order never reaches it.
 */
class GatewayStockBeforeVerificationTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const SERVER_TOTAL = 100.00;
    private const SERVER_PAISA = 10000;
    private const OPENING_STOCK = 50;

    // ---------------------------------------------------------------- 1. the premise

    /**
     * D-18 STEP 1. "Verify actual reachability of each path first, including Plan conditions and
     * any dependency that might prevent reaching the decrement code."
     *
     * This asserts it by driving a capture Khalti confirms and watching the figure move. It is
     * the positive control as well: if the fix below stops the drain by never decrementing, this
     * is the case that catches it.
     */
    public function test_the_stock_loop_is_reached_at_all(): void
    {
        [, $store, $customer, $product] = $this->checkout();
        $this->fakeKhalti(['status_code' => 200, 'data' => ['amount' => self::SERVER_PAISA]]);

        $this->postReturn($store, $customer, self::SERVER_PAISA);

        $this->assertSame(
            self::OPENING_STOCK - 1,
            $this->stockOf($product),
            'the decrement code is not reached at all, so every other case in this file is vacuous'
        );
        $this->assertSame(
            1,
            DB::table('orders')->where('store_id', $store->id)->count(),
            'and the handler must still write the order for a confirmed capture'
        );
    }

    /** The plan condition D-13 flagged: with a resolvable plan the handler reaches the loop. */
    public function test_the_plan_the_handler_dereferences_really_resolves_in_this_fixture(): void
    {
        [$merchant, , , ] = $this->checkout();

        $admin = DB::table('users')->where('type', 'admin')->first();
        $this->assertNotNull($admin, 'the handler reads User::where(type,admin)->first()');
        $this->assertNotNull(
            DB::table('plans')->where('id', $admin->plan_id)->first(),
            'Plan::find($user->plan_id) must resolve or $plan->shipping_method is a null deref'
        );
    }

    /**
     * THE QUESTION THE REGISTER RECORDS AS "NOT ESTABLISHED", SETTLED BY DRIVING IT.
     *
     * D-13 observed that Khalti and PaiementPro dereference `$plan->shipping_method` before the
     * stock loop, having assigned `$plan` only inside `if ($user->type == 'admin')`, and asked
     * whether an unresolvable plan makes the whole path LATENT -- a 500 before any stock moves --
     * rather than live. It matters because a fix landed on a latent path can CREATE the reachable
     * drain it was meant to close, which is the outcome the owner forbade by name.
     *
     * MEASURED, 2026-10-28, with `users.plan_id` pointing at nothing: the handler throws
     *
     *     ErrorException: Attempt to read property "shipping_method" on null
     *
     * with `product_stock` still at 50 and no order row. So the path is LATENT on a platform
     * whose admin has no resolvable plan, and LIVE on one that has -- which is the ordinary
     * configuration, since a platform admin normally holds a plan. The register's hope that this
     * might make the drain latent in practice is therefore only true of a broken install.
     *
     * TWO BOUNDS ON THAT READING, both stated rather than glossed. The crash is a PHP warning
     * promoted to an exception by Laravel's handler, so it depends on `error_reporting()`
     * including E_WARNING -- a php.ini question, not a code one; where it does not, the deref
     * yields null, `null == 'on'` is false, and the path continues LIVE. And this says nothing
     * about which configuration production runs: that needs the one query the register asks for,
     * which no test here can answer.
     *
     * CONSEQUENCE FOR THE SUSPENDED `$plan` FIX. The owner's condition was that fixing the
     * exception must not make an illegitimate decrement reachable. On THIS handler it no longer
     * can: the verification now precedes the decrement, so a resolvable plan reaches a gate, not
     * a drain. The condition still binds on PaiementPro, which shares the dereference and has no
     * verification to gate on.
     */
    public function test_what_an_unresolvable_plan_does_to_the_path(): void
    {
        [$merchant, $store, $customer, $product] = $this->checkout();
        $merchant->plan_id = 999999;
        $merchant->save();
        Cache::forget('admin_details');
        $this->fakeKhalti(['status_code' => 200, 'data' => ['amount' => self::SERVER_PAISA]]);

        $this->withoutExceptionHandling();

        $crashed = null;
        try {
            $this->postReturn($store, $customer, self::SERVER_PAISA);
        } catch (\Throwable $e) {
            $crashed = $e;
        }

        $stock = $this->stockOf($product);
        $orders = DB::table('orders')->where('store_id', $store->id)->count();

        // The point of the case is the PAIRING, so it is asserted as one fact: either the path
        // crashes before touching stock (latent), or it completes normally (live). What must never
        // be true is a crash that has already taken stock.
        if ($crashed !== null) {
            $this->assertSame(
                self::OPENING_STOCK,
                $stock,
                'LATENT is only safe if the crash precedes the decrement: ' . $crashed->getMessage()
            );
            $this->assertSame(0, $orders, 'and a crashed return must leave no order');

            return;
        }

        $this->assertSame(
            self::OPENING_STOCK - 1,
            $stock,
            'LIVE: an unresolvable plan does not stop the path, so it is reached in this configuration too'
        );
        $this->assertSame(1, $orders);
    }

    // ---------------------------------------------------------------- 2. the drain

    /**
     * THE DEFECT. Khalti was reached and said the capture does not cover this basket. No order is
     * written -- KhaltiAmountBindingTest already pins that -- and the stock is gone anyway.
     *
     * The route is anonymous and the checkout can be re-initiated, so this is repeatable: denial
     * of inventory for any catalogue line the attacker can put in a cart.
     */
    public function test_a_rejected_capture_takes_no_stock(): void
    {
        [, $store, $customer, $product] = $this->checkout();
        $this->fakeKhalti(['status_code' => 200, 'data' => ['amount' => 100]]);

        $this->postReturn($store, $customer, 100);

        $this->assertSame(
            0,
            DB::table('orders')->where('store_id', $store->id)->count(),
            'premise: a rejected capture writes no order'
        );
        $this->assertSame(
            self::OPENING_STOCK,
            $this->stockOf($product),
            'a capture the gateway REJECTED must not consume the catalogue'
        );
    }

    /**
     * THE SAME DEFECT ON THE OTHER PRE-VERIFICATION EXIT. The stored state carries no
     * `cartlist_final_price`, so `serverPayableAmount()` returns null and the handler refuses
     * before it ever calls Khalti -- after the loop has already taken the stock.
     */
    public function test_a_return_with_no_server_price_takes_no_stock(): void
    {
        [, $store, $customer, $product] = $this->checkout(['cartlist_final_price' => null]);
        $this->fakeKhalti(['status_code' => 200, 'data' => ['amount' => self::SERVER_PAISA]]);

        $this->postReturn($store, $customer, self::SERVER_PAISA);

        $this->assertSame(
            0,
            DB::table('orders')->where('store_id', $store->id)->count(),
            'premise: an unpriceable state writes no order'
        );
        $this->assertSame(
            self::OPENING_STOCK,
            $this->stockOf($product),
            'a return this server cannot price must not consume the catalogue'
        );
    }

    /** The variant branch of the loop is a separate decrement site, so it is driven separately. */
    public function test_a_rejected_capture_takes_no_variant_stock(): void
    {
        [, $store, $customer, $product] = $this->checkout([], true);
        $variantId = DB::table('product_variants')->where('product_id', $product->id)->value('id');
        $this->fakeKhalti(['status_code' => 200, 'data' => ['amount' => 100]]);

        $this->postReturn($store, $customer, 100);

        $this->assertSame(
            self::OPENING_STOCK,
            (int) DB::table('product_variants')->where('id', $variantId)->value('stock'),
            'the variant decrement site has the same defect and needs the same proof'
        );
    }

    // ---------------------------------------------------------------- 3. what must not change

    /**
     * THE RECONCILIATION BRANCH IS DELIBERATE AND MUST SURVIVE. A failed curl leaves this server
     * unable to say whether money moved, so the order is written Pending rather than lost. That
     * decision predates this slice; a fix that refuses UNVERIFIABLE would strand a charged
     * customer, which is the opposite of the owner's instruction not to lose a paid order.
     */
    public function test_an_unreachable_gateway_still_decrements_and_still_writes_its_pending_order(): void
    {
        [, $store, $customer, $product] = $this->checkout();
        $this->fakeKhalti(['status_code' => 0, 'data' => null]);

        $this->postReturn($store, $customer, self::SERVER_PAISA);

        $order = DB::table('orders')->where('store_id', $store->id)->first();
        $this->assertNotNull($order, 'an unreachable gateway must still leave a reconcilable row');
        $this->assertSame('Pending', $order->payment_status);
        $this->assertSame(
            self::OPENING_STOCK - 1,
            $this->stockOf($product),
            'the Pending order is a real order and its stock must be held for it'
        );
    }

    /**
     * REPLAY (D-18 and the directive's Idempotency clause). Two returns for one checkout take one
     * decrement. claimGatewayReturn() already does this; it is asserted here because hoisting the
     * verification moves code across that claim and this is the property that would break.
     */
    public function test_a_replayed_return_takes_no_second_decrement(): void
    {
        [, $store, $customer, $product] = $this->checkout();
        $this->fakeKhalti(['status_code' => 200, 'data' => ['amount' => self::SERVER_PAISA]]);
        $state = $this->state;

        $token = 'tok-replay';
        for ($i = 0; $i < 2; $i++) {
            $this->actingAs($customer, 'customers')
                ->withSession(['request_data' => $state])
                ->post('/' . $store->slug . '/order-khalti', [
                    'payload' => ['token' => $token, 'amount' => self::SERVER_PAISA],
                ]);
        }

        $this->assertSame(
            self::OPENING_STOCK - 1,
            $this->stockOf($product),
            'the replay claim must still collapse two returns into one decrement'
        );
        $this->assertSame(1, DB::table('orders')->where('store_id', $store->id)->count());
    }

    /**
     * THE HOIST'S OWN FAILURE MODE. Moving the verification above the loop while leaving the old
     * call in place would ask Khalti twice for every single return -- two outbound requests per
     * payment, and two chances for the second answer to differ from the first. Nothing else in
     * this file would notice, so it is asserted directly off the spy's counter.
     */
    public function test_khalti_is_asked_exactly_once_per_return(): void
    {
        [, $store, $customer, ] = $this->checkout();
        $spy = $this->fakeKhalti(['status_code' => 200, 'data' => ['amount' => self::SERVER_PAISA]]);

        $this->postReturn($store, $customer, self::SERVER_PAISA);

        $this->assertSame(1, $spy->calls, 'the verification must be performed once, not once per position');
    }

    /**
     * AND THE REFUSAL MUST STILL LOOK THE SAME TO THE SHOPPER. The rejected capture's response is
     * the only thing a buyer sees change position, so it is pinned: the same redirect with the
     * same message the old post-loop `else` returned.
     */
    public function test_a_rejected_capture_still_tells_the_shopper_what_it_always_did(): void
    {
        [, $store, $customer, ] = $this->checkout();
        $this->fakeKhalti(['status_code' => 200, 'data' => ['amount' => 100]]);

        $response = $this->postReturn($store, $customer, 100);

        $response->assertRedirect();
        $this->assertSame(__('Transaction has been failed'), session('error'));
    }

    /**
     * THE GUEST ARM, AND IT EXISTS BECAUSE THE HOIST BROKE IT AND NOTHING NOTICED.
     *
     * Every Khalti test in this repository -- here, in `KhaltiAmountBindingTest`, in
     * `GatewayReturnMissingProductTest` -- drives `actingAs($customer, 'customers')`. The guest arm
     * is the one that reassigns `$response` from `Cart::cart_list_cookie()`, so when the hoist put
     * the verify result into that same `$response` ABOVE the cart read, the guest arm overwrote it
     * with a stdClass and the thank-you payload's `$response['status_code']` threw
     * `Error: Cannot use object of type stdClass as array` -- AFTER the order row had committed Paid
     * and the stock had been decremented. A confirmed payment, a charged shopper, an error page.
     * The project's reviewer found it by reading; this case is what would have found it by running.
     *
     * So it asserts the three things that defect broke, separately, because they fail separately:
     * the request completes, the payload carries Khalti's real status code, and the order is Paid.
     */
    public function test_a_verified_guest_capture_completes_and_reports_khaltis_status_code(): void
    {
        [, $store, , $product] = $this->checkout(['customer_id' => '']);
        $sid = $this->guestBasket($store, $product);
        $this->fakeKhalti(['status_code' => 200, 'data' => ['amount' => self::SERVER_PAISA]]);

        $this->withoutExceptionHandling();

        $thrown = null;
        $content = '';
        try {
            $response = $this->withCookie('cart', $sid)
                ->withSession(['request_data' => $this->state])
                ->post('/' . $store->slug . '/order-khalti', [
                    'payload' => ['token' => 'tok-' . uniqid(), 'amount' => self::SERVER_PAISA],
                ]);
            $content = (string) $response->getContent();
        } catch (\Throwable $e) {
            $thrown = get_class($e) . ': ' . $e->getMessage();
        }

        $this->assertNull($thrown, 'a confirmed guest capture must not throw');

        $order = DB::table('orders')->where('store_id', $store->id)->first();
        $this->assertNotNull($order, 'and it must write the order');
        $this->assertSame('Paid', $order->payment_status);

        $this->assertStringContainsString(
            '"status_code":200',
            $content,
            'the thank-you payload must carry the status code KHALTI returned -- the storefront '
            . 'navigates to the completion page only on 200 (payment/khalti.blade.php)'
        );
    }

    /**
     * D-13, 2026-10-29 — **AN EXCEPTION DURING THE ORDER WRITE LOSES THE STOCK.**
     *
     * The register's remedy for this slice was "move each stock loop below verification or adopt
     * the `AppliesCartStockChanges` trait". Both are done: all four handlers route every decrement
     * through `decrementStockOrFail` and give it back on every IN-LOOP exit, ratcheted by
     * `GatewayStockDecrementRoutingTest` and independently re-censused (0 unrestored returns
     * between the first decrement and the order write, in all five writers).
     *
     * WHAT NEITHER COVERS IS THE WINDOW AFTER THE LOOP. Between the last decrement and the end of
     * the order write the handler creates the order, its billing detail, its tax rows, its notes,
     * its coupon usage. **None of these four handlers wraps that in a transaction, and none has a
     * catch that gives the stock back** — measured across all seven first-party gateway writers;
     * the only `catch` blocks in that region are the narrow SMTP ones around mail sending. So an
     * exception there leaves the stock gone and, depending where it lands, no order at all.
     *
     * `OrderWriteTransactionTest` pins exactly this property — *"a rolled-back order write must
     * give back the stock it consumed"* — for `PaymentController::getProductStatus`,
     * `PaymentService::getProductStatus` and `PaymentController::whatsapp`. **The four gateway
     * RETURN handlers are not in its data provider.**
     *
     * IT IS NOT A COLLECTION RULE, which is what makes it closeable under the owner's D-13
     * condition: this changes nothing about whether a payment is accepted. It gives back inventory
     * that a failed write consumed.
     *
     * THE FAILURE IS INJECTED THROUGH A MODEL EVENT rather than by editing the handler, so the
     * test measures the PROPERTY ("any failure during the order write is compensated") instead of
     * one trigger. That is the honest subject: F89 proved this window throws in production — a
     * charged card ending in a 500 on `NMIPayController:1328` — and the next one will not be the
     * same line.
     */
    /**
     * D-13, 2026-10-29 — **AND THE COMPENSATION MUST NOT FIRE ONCE THE ORDER EXISTS.**
     *
     * THIS IS THE DEFECT THE INDEPENDENT REVIEW FOUND IN MY OWN FIX, which is the fourth time in
     * this project that the reviewer has found the defect inside the remedy. The first wrapper put
     * a `try` around the WHOLE method body, and there is no transaction — so a throw AFTER
     * `$order->save()` returned the basket's stock to the catalogue while the order row, its
     * billing detail, its coupon rows and its tax rows stayed durably written. **That is the
     * inverse of the defect D-13 set out to close:** the shopper has an order, the merchant has a
     * sale, and the goods are back on the shelf for someone else to buy.
     *
     * THE PROJECT'S OWN PATTERN ALREADY SAYS SO. `PaymentController::getProductStatus` opens its
     * `try` AT the order write, commits, and closes the `try` before the mail, the SMS, the
     * Firebase push and the cart clearing — so its `restoreStockDecrements()` is only ever reached
     * in company with `DB::rollBack()`. `OrderWriteTransactionTest::
     * test_the_stock_loop_is_outside_the_boundary_and_is_compensated_on_failure` encodes that as
     * `assertGreaterThan($rollback, $restore)`. The invariant is **restore only when the order
     * does not exist**, and the first wrapper broke it.
     *
     * THE PATHS ARE NOT HYPOTHETICAL. The review named six that throw after `$order->save()`:
     * `Utility::affiliateTransaction()` assigning a property on a null `Customer` when a referrer
     * row is gone; the first coupon branch's unguarded `$Coupon->id` where its own sibling branch
     * guards; `$owner->email` on a store whose owner row is deleted (the F121 shape);
     * `event(new GetProductStatus(...))`, whose module listeners send mail and SMS unwrapped; the
     * unguarded `$requests_data['tax_price']` read; and `OrderObserver::created()`, which catches
     * `\Exception` and therefore not `\Error`.
     *
     * The injection is at `OrderBillingDetail::creating` — the first child row, written straight
     * after the order — so the subject is "the order exists and something later failed", which is
     * the condition the flag has to recognise.
     */
    public function test_a_failure_after_the_order_is_written_keeps_the_stock_consumed(): void
    {
        [, $store, $customer, $product] = $this->checkout();
        $this->fakeKhalti(['status_code' => 200, 'data' => ['amount' => self::SERVER_PAISA]]);

        \App\Models\OrderBillingDetail::creating(static function (): void {
            throw new \RuntimeException('simulated failure AFTER the order row was written');
        });

        $this->withoutExceptionHandling();

        $threw = null;
        try {
            $this->postReturn($store, $customer, self::SERVER_PAISA);
        } catch (\Throwable $e) {
            $threw = $e;
        }

        $this->assertNotNull($threw, 'the injected failure did not reach the billing-detail write');

        /* The premise that makes the rest meaningful: the ORDER really is there. */
        $this->assertSame(
            1,
            (int) DB::table('orders')->where('store_id', $store->id)->count(),
            'the premise: the order row committed before the injected failure'
        );

        $this->assertSame(
            self::OPENING_STOCK - 1,
            $this->stockOf($product),
            'an order that EXISTS claims its goods. Giving the stock back here puts the shopper\'s'
            . ' items on the shelf for someone else while their order stands — the inverse of the'
            . ' defect this slice closes, and the reason the compensation is gated on whether the'
            . ' order was written rather than on whether anything threw.'
        );
    }

    public function test_a_failure_during_the_order_write_gives_the_stock_back(): void
    {
        [, $store, $customer, $product] = $this->checkout();
        $this->fakeKhalti(['status_code' => 200, 'data' => ['amount' => self::SERVER_PAISA]]);

        \App\Models\Order::creating(static function (): void {
            throw new \RuntimeException('simulated failure during the order write');
        });

        $this->withoutExceptionHandling();

        $threw = null;
        try {
            $this->postReturn($store, $customer, self::SERVER_PAISA);
        } catch (\Throwable $e) {
            $threw = $e;
        }

        /* The premise: the injection really fired, and it fired AFTER the loop. */
        $this->assertNotNull($threw, 'the injected failure did not reach the order write');
        $this->assertSame(0, (int) DB::table('orders')->where('store_id', $store->id)->count());

        $this->assertSame(
            self::OPENING_STOCK,
            $this->stockOf($product),
            'a failure during the order write must give back the stock the loop consumed.'
            . ' Without it the shopper has no order, the merchant has no sale, and the inventory'
            . ' is gone — the drain D-13 exists to close, reachable with no forged payment at all.'
        );
    }

    // ---------------------------------------------------------------- fixture

    private array $state = [];

    private function guestBasket($store, Product $product): string
    {
        DB::table('carts')->where('product_id', $product->id)->delete();

        $sid = 'd13-cookie-' . uniqid();

        DB::table('carts')->insert([
            'product_id' => $product->id, 'variant_id' => 0, 'qty' => 1,
            'customer_id' => 0, 'cookie_session_id' => $sid, 'price' => self::SERVER_TOTAL,
            'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $sid;
    }

    private function stockOf(Product $product): int
    {
        return (int) DB::table('products')->where('id', $product->id)->value('product_stock');
    }

    private function postReturn($store, $customer, $amount)
    {
        return $this->actingAs($customer, 'customers')
            ->withSession(['request_data' => $this->state])
            ->post('/' . $store->slug . '/order-khalti', [
                'payload' => ['token' => 'tok-' . uniqid(), 'amount' => $amount],
            ]);
    }

    /** @return array{0:\App\Models\User,1:\App\Models\Store,2:Customer,3:Product} */
    private function checkout(array $stateOverrides = [], bool $variant = false): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'd13-plan-' . uniqid(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'off',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->save();
        Cache::forget('admin_details');

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

        $customer = Customer::forceCreate([
            'first_name' => 'D13', 'last_name' => 'Buyer',
            'email' => 'd13-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);

        $product = Product::forceCreate([
            'name' => 'd13-' . uniqid(), 'slug' => 'd13-' . uniqid(),
            'product_stock' => self::OPENING_STOCK, 'track_stock' => 1,
            'variant_product' => $variant ? 1 : 0,
            'price' => self::SERVER_TOTAL, 'sale_price' => self::SERVER_TOTAL,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);

        $variantId = 0;
        if ($variant) {
            $variantId = DB::table('product_variants')->insertGetId([
                'product_id' => $product->id,
                'variant' => 'Red',
                'sku' => 'd13-sku-' . uniqid(),
                'variation_option' => 'manage_stock',
                'stock' => self::OPENING_STOCK,
                'price' => self::SERVER_TOTAL,
                'low_stock_threshold' => 0,
                'store_id' => $store->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('carts')->insert([
            'product_id' => $product->id, 'variant_id' => $variantId, 'qty' => 1,
            'customer_id' => $customer->id, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->state = array_merge([
            'slug' => $store->slug,
            'store_id' => $store->id,
            'customer_id' => $customer->id,
            'payment_type' => 'khalti',
            'shipping_final_price' => 0,
            'tax_price' => 0,
            'cartlist_final_price' => self::SERVER_TOTAL,
            'coupon_info' => null,
            'billing_info' => [
                'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.test',
                'billing_user_telephone' => '123', 'billing_address' => 'x',
                'billing_postecode' => '1', 'billing_country' => '101',
                'billing_state' => '1', 'billing_city' => '1', 'billing_company_name' => '',
                'delivery_address' => 'x', 'delivery_postcode' => '1',
                'delivery_country' => '101', 'delivery_state' => '1', 'delivery_city' => '1',
            ],
        ], $stateOverrides);

        return [$merchant, $store, $customer, $product];
    }

    private function fakeKhalti(array $response): Khalti
    {
        $spy = new class($response) extends Khalti {
            public int $calls = 0;

            public function __construct(private array $canned)
            {
            }

            public function verifyPayment($secret, $token, $amount)
            {
                $this->calls++;

                return $this->canned;
            }
        };

        $this->app->instance(Khalti::class, $spy);

        return $spy;
    }
}
