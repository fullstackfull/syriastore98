<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * A SHOPPER COULD WRITE THEIR OWN DISCOUNT INTO THE MERCHANT'S ORDER RECORDS.  P1-ORD-02 criterion 2.
 * =================================================================================================
 *
 * `PaymentController::processCoupon()` SHORT-CIRCUITS ON CALLER INPUT:
 *
 *     if (isset($request['coupon_info']) && $request['coupon_info']) {
 *         $coupon_price = $request['coupon_info']['coupon_discount_amount'] ?? 0;
 *         return [...];                                   // <- apply_coupon() is NEVER called
 *     }
 *
 * `coupon_info` is an ordinary request field. When the caller posts one, the server's own coupon
 * validation (`Api\ApiController::apply_coupon`) does not run at all, and -- because that branch
 * returns no `coupon_info` key -- the caller's array is never overwritten. It is therefore carried
 * verbatim into the stored checkout state, which `PaymentService` writes as
 * `$request->except('attachment','payment_receipt')`, and from there into the order's records.
 *
 * MEASURED on the real route, signed in as a real customer of the store, with a REAL 10% coupon on a
 * 100.00 basket (so the truth is 10.00) and a posted `coupon_discount_amount` of 99999:
 *
 *     stored coupon_info     {"coupon_discount_number":99999,"coupon_discount_amount":99999,
 *                             "coupon_final_amount":0.01, ...}
 *     orders.final_price     100.00      <- CORRECT, the charge is not affected
 *     orders.coupon_price      0.00
 *     order_coupon_details   coupon_discount_amount = 99999, coupon_final_amount = 0.01
 *
 * WHAT THIS IS, STATED PRECISELY, because the difference matters and an overclaim here would be the
 * second in this package. The SHOPPER IS STILL CHARGED THE FULL 100.00 -- `prepareFinalRequestData()`
 * computes the payable server-side, so this is NOT money leaving the merchant. What it is, is a
 * FALSIFIED FINANCIAL RECORD: the order's coupon detail row states a discount of the shopper's
 * choosing, which never happened. Anything built on those rows -- merchant reporting, coupon
 * analytics, reconciliation, `user_coupons.amount` on the arms that write it -- is then wrong by an
 * amount the shopper picked.
 *
 * REMOVING THE SHORT-CIRCUIT IS SAFE, and that was checked rather than assumed: `apply_coupon()` is
 * 870 lines and contains ZERO writes (no insert, update, save, increment, decrement or delete), so
 * always calling it cannot double-consume a coupon. Consumption is written by the gateway handlers
 * through `UserCoupon`, not here.
 *
 * Found by the posted-money census (22 agents over the eight host order writers); twelve claims
 * survived adversarial refutation and this is their common root cause.
 */
class CouponAmountSourceTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const BASKET = 100.00;
    private const TRUE_DISCOUNT = 10.0;        // a 10% coupon on a 100.00 basket
    private const FORGED_DISCOUNT = 99999;

    private function tenant(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'cp-' . uniqid(), 'price' => 0, 'themes' => '[]', 'storage_limit' => '100',
            'shipping_method' => 'off', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->save();
        Cache::forget('admin_details');

        foreach (['stock_management' => 'on', 'out_of_stock_threshold' => '0', 'notification' => '[]'] as $n => $v) {
            DB::table('settings')->insert(['name' => $n, 'value' => $v, 'created_by' => $merchant->id,
                'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now()]);
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
            'first_name' => 'A', 'last_name' => 'B', 'email' => 'cp-' . uniqid() . '@e.test',
            'mobile' => '555' . random_int(100000, 999999), 'password' => bcrypt('x'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
        $product = Product::forceCreate([
            'name' => 'p', 'slug' => 'p-' . uniqid(), 'product_stock' => 50, 'track_stock' => 1,
            'variant_product' => 0, 'price' => self::BASKET, 'sale_price' => self::BASKET,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
        Cart::forceCreate(['customer_id' => $customer->id, 'product_id' => $product->id,
            'variant_id' => 0, 'qty' => 1, 'price' => self::BASKET, 'store_id' => $store->id]);

        return $customer;
    }

    /** A genuine 10%-off coupon belonging to this store. */
    private function realCoupon($store): int
    {
        return DB::table('coupons')->insertGetId([
            'coupon_name' => 'SAVE10', 'coupon_code' => 'SAVE10', 'coupon_type' => 'percentage',
            'discount_amount' => 10, 'coupon_limit' => 100, 'coupon_limit_user' => 100,
            'coupon_expiry_date' => now()->addYear()->toDateString(), 'free_shipping_coupon' => 0,
            'status' => 1, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * A coupon the basket does not qualify for, so "declined" is a real state.
     *
     * UNTIL P1-ORD-01 WAS FIXED, ANY coupon was declined and the no-500 test below got its
     * declination for free. Now that ordinary coupons apply, a test about the declined path has to
     * arrange one -- a minimum spend above the basket is the cheapest honest way.
     */
    private function unqualifiedCoupon($store): int
    {
        return DB::table('coupons')->insertGetId([
            'coupon_name' => 'BIGSPEND', 'coupon_code' => 'BIGSPEND', 'coupon_type' => 'percentage',
            'discount_amount' => 10, 'coupon_limit' => 100, 'coupon_limit_user' => 100,
            'minimum_spend' => 100000,
            'coupon_expiry_date' => now()->addYear()->toDateString(), 'free_shipping_coupon' => 0,
            'status' => 1, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function billing(): array
    {
        return ['firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.test',
            'billing_user_telephone' => '123', 'billing_address' => 'x', 'billing_postecode' => '1',
            'billing_country' => '101', 'billing_state' => '1', 'billing_city' => '1',
            'billing_company_name' => '', 'delivery_address' => 'x', 'delivery_postcode' => '1',
            'delivery_country' => '101', 'delivery_state' => '1', 'delivery_city' => '1'];
    }

    /** Drive a real checkout initiation and hand back the stored state. */
    private function initiate($store, Customer $customer, array $extra): ?array
    {
        $this->actingAs($customer, 'customers')->post('/' . $store->slug . '/process-order', array_merge([
            'slug' => $store->slug, 'store_id' => $store->id, 'customer_id' => $customer->id,
            'payment_type' => 'easebuzz', 'billing_info' => $this->billing(),
        ], $extra));

        $state = session('easebuzz_request_data');

        return is_array($state) ? $state : null;
    }

    private function forgedCouponInfo(int $couponId): array
    {
        return [
            'coupon_id' => $couponId, 'coupon_name' => 'SAVE10', 'coupon_code' => 'SAVE10',
            'coupon_discount_type' => 'flat',
            'coupon_discount_number' => self::FORGED_DISCOUNT,
            'coupon_discount_amount' => self::FORGED_DISCOUNT,
            'coupon_final_amount' => 0.01,
        ];
    }

    /**
     * THE DEFECT, AT THE SOURCE: the stored checkout state must carry the SERVER's coupon figures.
     */
    public function test_a_posted_coupon_info_does_not_survive_into_the_checkout_state(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->shopperWithBasket($store);
        $couponId = $this->realCoupon($store);

        $state = $this->initiate($store, $customer, [
            'coupon_code' => 'SAVE10',
            'coupon_info' => $this->forgedCouponInfo($couponId),
        ]);

        $this->assertNotNull($state, 'Premise: the initiation stored a checkout state.');

        $stored = $state['coupon_info'] ?? null;

        $this->assertNotSame((string) self::FORGED_DISCOUNT, (string) ($stored['coupon_discount_amount'] ?? ''),
            'the checkout state carries the discount the CALLER posted. processCoupon() short-circuited '
            . 'on a posted coupon_info and never ran the server-side apply_coupon(), so the figure was '
            . 'never checked against the coupon it names.');

        /*
         * AND IT IS NOW A REAL FIGURE -- THE HANDOFF THIS DOCBLOCK PREDICTED.
         *
         * This assertion used to read `assertSame([], $stored)` with a note that "no discount is
         * reachable on this codebase today ... when P1-ORD-01 fixes the gates this becomes a real
         * figure and the assertion still holds". P1-ORD-01 IS FIXED (2026-10-24), so the server's
         * answer is 10.00 -- 10% of the 100.00 basket -- and the invariant is unchanged: the stored
         * figure is the SERVER'S, never the caller's 99999.
         *
         * THE OLD NOTE'S DIAGNOSIS WAS ALSO WRONG and is not carried forward: it blamed "a second,
         * non-null-tolerant spend gate pair". Both pairs are null-tolerant. The refusal came from
         * the `else` of `if ($coupon->coupon_type == 'fixed product discount')`, which rejected
         * every flat and percentage coupon and mislabelled it as a minimum-spend failure. See the
         * P1-ORD-01 ledger row.
         */
        $this->assertSame(self::TRUE_DISCOUNT, round((float) ($stored['coupon_discount_amount'] ?? -1), 2),
            "the checkout state does not carry the server's 10% of 100.00.");
    }

    /**
     * AND THE CONSEQUENCE IT WAS FOUND BY: the order's own records.
     *
     * Driven all the way to the database, because the state is an intermediate and a reader should
     * not have to take on trust that it reaches the records.
     */
    public function test_a_posted_discount_does_not_reach_the_orders_coupon_records(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->shopperWithBasket($store);
        $couponId = $this->realCoupon($store);

        $state = $this->initiate($store, $customer, [
            'coupon_code' => 'SAVE10',
            'coupon_info' => $this->forgedCouponInfo($couponId),
        ]);

        $this->assertNotNull($state);

        $this->actingAs($customer, 'customers')
            ->withSession(['easebuzz_request_data' => $state])
            ->post('/' . $store->slug . '/get-payment-easebuzz', ['status' => 'success', 'txnid' => 'cp-' . uniqid()]);

        $detail = DB::table('order_coupon_details')->orderByDesc('id')->first();

        $this->assertNotNull($detail,
            'Premise: the return wrote a coupon detail row, so the assertions below are not vacuous.');
        $this->assertNotSame((float) self::FORGED_DISCOUNT, round((float) $detail->coupon_discount_amount, 2),
            "the order's coupon record states the discount the SHOPPER chose. The charge is unaffected, "
            . 'but the merchant\'s financial record is then wrong by an amount the shopper picked.');
        $this->assertSame(self::TRUE_DISCOUNT, round((float) $detail->coupon_discount_amount, 2),
            "the recorded discount is not the server's 10% of 100.00.");
    }

    /**
     * THE CHARGE WAS ALWAYS CORRECT, and this says so rather than letting a later reader assume the
     * fix changed what shoppers pay. It is a preservation assertion.
     */
    public function test_the_amount_charged_was_and_remains_the_servers(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->shopperWithBasket($store);
        $couponId = $this->realCoupon($store);

        $state = $this->initiate($store, $customer, [
            'coupon_code' => 'SAVE10',
            'coupon_info' => $this->forgedCouponInfo($couponId),
        ]);

        $this->assertNotNull($state);

        /*
         * 90.00, NOT 100.00, AND THAT IS THE FIX RATHER THAN A REGRESSION. This asserted BASKET
         * while every coupon was refused; P1-ORD-01 made a 10% coupon on a 100.00 basket charge
         * 90.00, which is what it should always have charged.
         *
         * THE INVARIANT IS UNCHANGED AND IS THE POINT: the payable is the SERVER's arithmetic. The
         * caller posted a 99999 discount, so if the forged figure had won the payable would be 0 or
         * negative -- never 90.00.
         */
        $this->assertSame(
            round(self::BASKET - self::TRUE_DISCOUNT, 2),
            round((float) ($state['cartlist_final_price'] ?? -1), 2),
            'the payable is not the server figure: 100.00 less the server-computed 10% is 90.00, and '
            . 'the caller-posted 99999 must never reach it.');
    }

    /**
     * A DECLINED COUPON NO LONGER 500s THE CHECKOUT -- the second defect this package found, and the
     * one that was almost certainly keeping the short-circuit alive.
     *
     * The server path ended with `(array) $apply_coupon['coupon_info']`, dereferenced
     * unconditionally. `apply_coupon()` returns an ERROR SHAPE when it declines -- `data` is just
     * `['message' => ...]` -- so that line raised `Undefined array key "coupon_info"`, i.e. a 500 on
     * the checkout, for the ordinary case of a shopper typing a code they do not qualify for.
     * MEASURED, and it is how removing the short-circuit first showed up: every coupon attempt 500'd.
     *
     * WHEN THIS WAS WRITTEN apply_coupon() refused nearly every coupon, so this was the common path
     * and the test got its declination for free. P1-ORD-01 fixed that, so the test now arranges a
     * coupon the basket genuinely does not qualify for -- otherwise it would have been silently
     * measuring the SUCCESS path while claiming to measure the declined one.
     */
    public function test_a_declined_coupon_does_not_break_the_checkout(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->shopperWithBasket($store);
        // A coupon this basket does NOT qualify for. Until P1-ORD-01 was fixed every coupon was
        // declined and `SAVE10` served; now that ordinary coupons apply, the declined path has to
        // be arranged rather than assumed. See unqualifiedCoupon().
        $this->unqualifiedCoupon($store);

        $state = $this->initiate($store, $customer, ['coupon_code' => 'BIGSPEND']);

        $this->assertNotNull($state,
            'a declined coupon stopped the checkout storing its state at all -- the handler raised '
            . 'rather than proceeding without a discount.');
        $this->assertSame(self::BASKET, round((float) ($state['cartlist_final_price'] ?? -1), 2),
            'the payable is not the undiscounted server total after a declined coupon.');
        $this->assertSame([], (array) ($state['coupon_info'] ?? []),
            'a declined coupon left a coupon_info behind.');
    }

    /**
     * AND A COUPON CODE THE STORE DOES NOT HAVE CHANGES NOTHING -- the shape that would catch a "fix"
     * which simply stopped reading coupons at all while still trusting whatever was posted.
     */
    public function test_an_unknown_coupon_code_leaves_the_total_and_the_records_alone(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->shopperWithBasket($store);

        $state = $this->initiate($store, $customer, [
            'coupon_code' => 'NO-SUCH-CODE',
            'coupon_info' => $this->forgedCouponInfo(999999),
        ]);

        $this->assertNotNull($state);
        $this->assertSame(self::BASKET, round((float) ($state['cartlist_final_price'] ?? -1), 2));
        $this->assertSame([], (array) ($state['coupon_info'] ?? []),
            'a coupon code this store does not have still produced a coupon_info -- the caller\'s.');
    }

    /**
     * `final_sub_total` IS PINNED TO THE SERVER'S SUBTOTAL ON THE CHECKOUT PATH.
     *
     * A SECOND caller-supplied override, found by reading rather than by the census: `apply_coupon()`
     * prefers `$request->final_sub_total` over `sub_total` in FOUR places -- the maximum-spend gate,
     * the minimum-spend gate, and twice as `$price`, the base a percentage discount is computed FROM
     * (Api\ApiController.php:4015-4028, :4080, :4355-4359). A repo-wide search of app/ and
     * resources/views/ finds that key ONLY in those reads: nothing server-side sets it and no view
     * posts it, so on this path it can only ever be the caller's.
     *
     * ASSERTED OVER THE SOURCE, and the weakness is stated rather than hidden. A removal check
     * recorded dropping the pin as SURVIVING every behavioural test here, and that is honest: the
     * override only becomes observable once a coupon is actually APPLIED, and `apply_coupon()`
     * currently refuses every shape tried -- nulls, explicit 0/999999, min 1/max 999999, with and
     * without per-item limits -- all with the same message, which reports "$0,00" even when
     * `minimum_spend` is 1, so it is not even the gate it names. That is the deferred P1-ORD-01
     * defect (OrderTotalCouponTest pins it as known-bad).
     *
     * So this is defence in depth that cannot yet be driven. It is pinned so the pin survives until
     * P1-ORD-01 makes it testable, and recorded as a source ratchet rather than dressed up as
     * execution.
     */
    public function test_the_checkout_pins_final_sub_total_to_the_server_subtotal(): void
    {
        $code = $this->sourceWithoutComments(app_path('Http/Controllers/PaymentController.php'));

        $this->assertMatchesRegularExpression(
            "/'sub_total'\s*=>\s*\\\$cartlist_final_price\s*\?\?\s*0,\s*'final_sub_total'\s*=>\s*\\\$cartlist_final_price\s*\?\?\s*0,/s",
            $code,
            'processCoupon() no longer overwrites final_sub_total with the server subtotal before '
            . 'calling apply_coupon(). That key has no server-side producer, so leaving it unpinned '
            . 'lets the caller choose the base a percentage discount is taken from and walk past the '
            . 'spend gates.'
        );
    }

    /** A file's source with comments removed, by PHP's own tokenizer. See the note on the ratchet below. */
    private function sourceWithoutComments(string $path): string
    {
        $out = '';

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }

        return $out;
    }

    /**
     * AND THE SHORT-CIRCUIT IS GONE FROM THE SOURCE -- a ratchet, because the branch is one `if` that
     * a future edit could restore while every behavioural test above still passed on some other arm.
     */
    public function test_process_coupon_never_takes_its_discount_from_the_request(): void
    {
        /*
         * COMMENTS STRIPPED BY PHP'S OWN TOKENIZER, because the fix's docblock QUOTES the removed
         * line in order to explain it -- so a ratchet over the raw source is satisfied by the very
         * prose describing the defect. That trap has now fired three times in this project; a regex
         * is not enough either, since it cannot tell a comment from a string that looks like one.
         */
        $code = '';
        foreach (token_get_all((string) file_get_contents(app_path('Http/Controllers/PaymentController.php'))) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        $this->assertStringNotContainsString(
            "\$coupon_price = \$request['coupon_info']['coupon_discount_amount']",
            $code,
            'PaymentController::processCoupon() again takes the coupon discount straight from the '
            . 'request, bypassing apply_coupon(). That is the whole defect.'
        );
    }
}
