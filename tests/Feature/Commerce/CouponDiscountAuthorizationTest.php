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
 * P1-ORD-01 (#20) -- CORRECT COUPON CALCULATION, AND NO UNAUTHORIZED DISCOUNT.
 * =================================================================================================
 *
 * THE OWNER'S WORDS: "Prevent incorrect coupon calculations and unauthorized discounts."
 *
 * THREE DEFECTS, ALL MEASURED BY DRIVING `api/{slug}/apply-coupon` BEFORE ANY FIX. Two of them made
 * coupons unusable; the third gave them away.
 *
 *   (A) EVERY COUPON WITH THE DEFAULT USAGE LIMIT READ AS EXPIRED. The gate is
 *       `->where('coupon_limit', '>', $uses)` and `coupons.coupon_limit` is
 *       `integer()->default('0')` (create_coupons_table.php:27). With no uses yet that is `0 > 0`
 *       -- false. Measured: {"status":0,"data":{"message":"This coupon has expired."}} on a coupon
 *       with a year left on its expiry date. A merchant who leaves the limit blank gets a coupon
 *       that can never be used, and an error that does not say why. Fixed by
 *       `Coupon::applyUsageLimit()`, which reads 0/NULL as "no limit" -- this codebase's own idiom
 *       for the sibling `coupon_limit_user`, which is gated on `!empty()`.
 *
 *   (B) EVERY `flat` AND `percentage` COUPON WAS REFUSED, AND THE REASON GIVEN WAS WRONG. The
 *       branch `if ($coupon->coupon_type == 'fixed product discount')` runs for ~230 lines and its
 *       `else` returned " The minimum spend for this coupon is $0,00." So the two types the
 *       migration's own comment documents (`percentage / flat`, and `percentage` is the column
 *       DEFAULT) were refused, blamed on a threshold nobody set.
 *
 *       THE LEDGER HAD THIS WRONG AND IT IS CORRECTED THERE: it recorded "a second,
 *       non-null-tolerant minimum/maximum-spend gate". Both spend gates ARE null-tolerant. Proven
 *       by dumping the operands at the gate -- `sub_total_min=200, minimum_spend=NULL,
 *       comparison=true` -- which is how the message was traced to the type branch instead. The
 *       discount for these types was already computed just above, and the response builder already
 *       had an `else` arm for them; the support was written and then made unreachable by one line.
 *
 *   (C) THE SPEND THRESHOLDS WERE NOT ENFORCED -- THE UNAUTHORIZED DISCOUNT, AND IT WAS LIVE.
 *       Two distinct holes, both measured:
 *         - the comparison was made against `$request->final_sub_total`, which the CLIENT POSTS.
 *           A real 200.00 basket plus `final_sub_total = 9999` satisfied `minimum_spend = 5000`.
 *         - worse, the free-shipping structure's `$shipping_Methods == null` branch assigned
 *           `$sub_total_min` AND THEN NEVER COMPARED IT. No posted value was even needed: a
 *           free-shipping coupon restricted to 5000 was granted on a 200.00 basket in any store
 *           with no shipping method configured (`plans.shipping_method = 'off'`).
 *       Both are closed by ONE predicate, `couponSpendRefusal()`, called once on the SERVER's
 *       pre-coupon subtotal before either structure runs.
 *
 * AND A FOURTH, FIXED IN PASSING BECAUSE THE PREDICATE MADE IT FREE: the two `else` arms of the
 * non-free-shipping gate emit EACH OTHER'S message, so a basket under the minimum was told about
 * the maximum. Measured: `minimum_spend = 5000` on 200.00 answered " The maximum spend for this
 * coupon is $0,00." -- the wrong limit and a value that was never set.
 *
 * AND THE DISCOUNT BASE WAS UNSAFE TOO -- A CORRECTION TO MY OWN FIRST READING, kept rather than
 * quietly removed because the way I got it wrong is the point. From a truncated probe payload I
 * read `coupon_discount_amount` -- which is the raw `$coupon->discount_amount`, i.e. the PERCENT --
 * as the computed discount, and concluded the base was already server-derived. It is not. The
 * regression test below, asserting the exact figure instead of eyeballing JSON, measured a
 * **100,000.00** discount for a 10% coupon on a real 200.00 basket when `final_sub_total = 1000000`
 * was posted: an unauthorized discount of unbounded size, priced from a number the shopper types.
 * `$price`, both percentage bases and the shipping-cost comparison now read the server subtotal.
 *
 * SO EVERY MONEY FIGURE IN THIS HANDLER IS NOW SERVER-DERIVED, including the `original_price` it
 * reports back -- echoing the client's own posted subtotal is how a tampered figure looks
 * confirmed. The posted keys are no longer read for any money purpose in `apply_coupon`.
 */
/*
 * MUTANTS, all executed against this file plus OrderTotalCouponTest. 7 applied, 6 bite:
 *
 *   M1  applyUsageLimit stops treating 0 as unlimited        -> 1 fails
 *   M2  applyUsageLimit drops the "> uses" limb              -> 1 fails (the limit still enforced)
 *   M3  the type branch refuses flat/percentage again        -> 5 fail
 *   M4  couponSpendRefusal always passes                     -> 3 fail
 *   M5  the percentage base reverts to the posted subtotal   -> 1 fails
 *   M6  the payable stops subtracting the coupon             -> 2 fail
 *   M7  couponSpendRefusal swaps its two messages            -> 1 fails
 *
 * M6 SURVIVED ON THE FIRST ATTEMPT AND THE REASON IS WORTH RECORDING, because it looks like a
 * coverage gap and is not one. The mutation tool replaced only the FIRST of the four `final_price`
 * assignments, which is the `!\Auth::guard('customers')->user()` arm -- and nothing drives that
 * arm. Probed directly by setting it to a sentinel 99999: all 17 tests still passed.
 *
 * IT IS NOT UNTESTED, IT IS UNREACHABLE. `place_order` resolves its shopper through
 * `resolveStoreCustomer($store)` ~450 lines earlier and returns 401 when that yields no `Customer`
 * of this store, so by the time the total is assigned `auth('customers')->user()` is always
 * truthy. The guest arm is dead code on this path -- which also means the four-way branch is
 * really a two-way one. Left in place rather than deleted: removing it is a separate change to a
 * handler this package is already editing for money reasons, and `place-order-guest` shares the
 * method. Mutating all four arms together fails 2 tests, which is the honest measurement.
 */
class CouponDiscountAuthorizationTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** @return array{0:mixed,1:mixed} */
    private function tenant(): array
    {
        [$merchant, $store] = $this->makeTenant();
        $planId = DB::table('plans')->insertGetId([
            'name' => 'ord01-plan-' . uniqid(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'off',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->save();
        Cache::forget('admin_details');

        foreach (['out_of_stock_threshold' => '0', 'notification' => '[]'] as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => $value,
                'created_by' => $merchant->id, 'store_id' => $store->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return [$merchant, $store];
    }

    private function productIn($store, float $price): Product
    {
        return Product::withoutEvents(fn () => Product::forceCreate([
            'name' => 'Widget', 'slug' => 'w-' . uniqid(),
            'price' => $price, 'sale_price' => $price,
            'product_stock' => 50, 'track_stock' => 0, 'variant_product' => 0,
            'status' => 1, 'trending' => 0, 'stock_status' => 'in_stock',
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]));
    }

    private function shopper($store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Api', 'last_name' => 'Shopper',
            'email' => 'ord01-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    /**
     * A 200.00 basket (2 x 100.00) and a coupon, driven through `apply-coupon`.
     *
     * @return array{0:int,1:array} the HTTP status and the decoded body
     */
    private function applyCoupon(array $coupon, array $posted = []): array
    {
        [, $store] = $this->tenant();
        $shopper = $this->shopper($store);
        $product = $this->productIn($store, 100.00);

        DB::table('coupons')->insert(array_merge([
            'coupon_code' => 'SAVE', 'coupon_name' => 'Save',
            'coupon_limit_user' => 0,
            'coupon_expiry_date' => now()->addYear()->toDateString(),
            'status' => 1, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ], $coupon));

        Cart::forceCreate([
            'customer_id' => $shopper->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 2, 'price' => 100.00, 'store_id' => $store->id,
        ]);

        $token = $shopper->createToken('coupon-test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/' . $store->slug . '/apply-coupon', array_merge([
                'coupon_code' => 'SAVE', 'customer_id' => $shopper->id,
            ], $posted));

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true) ?: []];
    }

    private function assertApplied(array $body, string $because): array
    {
        $this->assertSame(1, (int) ($body['status'] ?? 0),
            $because . ' -- refused with: ' . json_encode($body['data']['message'] ?? $body));

        return $body['data'] ?? [];
    }

    private function refusalMessage(array $body): string
    {
        $this->assertSame(0, (int) ($body['status'] ?? 1),
            'the coupon was APPLIED where a refusal was required: ' . json_encode($body['data'] ?? $body));

        return (string) ($body['data']['message'] ?? '');
    }

    // ------------------------------------------------------------------ (A) the usage limit

    /** THE MIGRATION'S OWN DEFAULT no longer reads as expired. */
    public function test_a_coupon_with_the_default_usage_limit_is_usable(): void
    {
        [$status, $body] = $this->applyCoupon([
            'coupon_type' => 'flat', 'discount_amount' => 50.00, 'coupon_limit' => 0,
        ], ['sub_total' => 200.00]);

        $this->assertSame(200, $status);
        $data = $this->assertApplied($body, 'a coupon whose limit was left at the schema default 0 '
            . 'is refused as expired, so every coupon a merchant creates without setting a limit is dead');
        $this->assertSame('50.00', number_format((float) $data['amount'], 2, '.', ''));
    }

    /** AND A POSITIVE LIMIT IS STILL ENFORCED: this fix must not remove the control. */
    public function test_a_coupon_whose_positive_limit_is_exhausted_is_still_refused(): void
    {
        [, $store] = $this->tenant();
        $shopper = $this->shopper($store);
        $product = $this->productIn($store, 100.00);

        $couponId = DB::table('coupons')->insertGetId([
            'coupon_code' => 'SAVE', 'coupon_name' => 'Save',
            'coupon_type' => 'flat', 'discount_amount' => 50.00,
            'coupon_limit' => 1, 'coupon_limit_user' => 0,
            'coupon_expiry_date' => now()->addYear()->toDateString(),
            'status' => 1, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // one prior use, which exhausts a limit of 1
        // `user_coupons` carries no store_id and `order_id` is an integer column
        // (2023_12_29_100550_create_user_coupons_table.php).
        DB::table('user_coupons')->insert([
            'coupon_id' => $couponId, 'user_id' => $shopper->id,
            'amount' => 50.00, 'order_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Cart::forceCreate([
            'customer_id' => $shopper->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 2, 'price' => 100.00, 'store_id' => $store->id,
        ]);

        $token = $shopper->createToken('coupon-test')->plainTextToken;
        $body = json_decode((string) $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/' . $store->slug . '/apply-coupon', [
                'coupon_code' => 'SAVE', 'customer_id' => $shopper->id, 'sub_total' => 200.00,
            ])->getContent(), true) ?: [];

        $this->assertStringContainsString('expired', $this->refusalMessage($body),
            'A COUPON LIMITED TO ONE USE WAS GRANTED A SECOND TIME. Reading 0 as "no limit" must '
            . 'not also loosen a limit the merchant did set.');
    }

    // ------------------------------------------------------------------ (B) the coupon types

    /** `flat` is one of the two types the schema documents, and it now applies. */
    public function test_a_flat_coupon_reduces_the_total_by_its_amount(): void
    {
        [, $body] = $this->applyCoupon([
            'coupon_type' => 'flat', 'discount_amount' => 50.00, 'coupon_limit' => 10,
        ], ['sub_total' => 200.00]);

        $data = $this->assertApplied($body, 'a flat coupon is still refused');

        $this->assertSame('50.00', number_format((float) $data['amount'], 2, '.', ''),
            'the discount is not the 50.00 the merchant configured');
        $this->assertSame('150.00', number_format((float) $data['final_price'], 2, '.', ''),
            'the discounted total is not 200.00 - 50.00');
    }

    /** `percentage` is the other, and it is the column DEFAULT. */
    public function test_a_percentage_coupon_discounts_a_percentage_of_the_server_subtotal(): void
    {
        [, $body] = $this->applyCoupon([
            'coupon_type' => 'percentage', 'discount_amount' => 10.00, 'coupon_limit' => 10,
        ], ['sub_total' => 200.00]);

        $data = $this->assertApplied($body, 'a percentage coupon is still refused');

        $this->assertSame('20.00', number_format((float) $data['amount'], 2, '.', ''),
            '10% of the 200.00 basket is 20.00');
        $this->assertSame('180.00', number_format((float) $data['final_price'], 2, '.', ''));
    }

    /** AND AN UNRECOGNISED TYPE IS STILL REFUSED, with a message that says so. */
    public function test_an_unsupported_coupon_type_is_refused_and_told_why(): void
    {
        [, $body] = $this->applyCoupon([
            'coupon_type' => 'buy one get one', 'discount_amount' => 50.00, 'coupon_limit' => 10,
        ], ['sub_total' => 200.00]);

        $message = $this->refusalMessage($body);

        $this->assertStringNotContainsString('minimum spend', $message,
            'an unsupported type is still blamed on a spend threshold');
        $this->assertStringContainsString('not supported', $message);
    }

    // ------------------------------------------------- (C) the thresholds, on the server's figure

    /**
     * THE UNAUTHORIZED DISCOUNT, IN ITS FIRST FORM: a posted subtotal satisfying a threshold the
     * real basket does not meet.
     */
    public function test_a_posted_subtotal_cannot_satisfy_a_minimum_spend(): void
    {
        [, $body] = $this->applyCoupon([
            'coupon_type' => 'flat', 'discount_amount' => 50.00, 'coupon_limit' => 10,
            'minimum_spend' => 5000,
        ], ['sub_total' => 200.00, 'final_sub_total' => 9999.00]);

        $message = $this->refusalMessage($body);

        $this->assertStringContainsString('minimum spend', $message,
            'the refusal does not name the threshold that failed');
        $this->assertStringContainsString('5,000', $message,
            'the message names the wrong value: the two else arms emit each other\'s message, so '
            . 'the shopper is told about a limit that was never set');
    }

    /**
     * ITS SECOND AND WORSE FORM: the free-shipping branch for a store with no shipping method
     * assigned `$sub_total_min` and never compared it. No posted value was needed at all.
     */
    public function test_a_free_shipping_coupon_still_honours_its_minimum_spend(): void
    {
        [, $body] = $this->applyCoupon([
            'coupon_type' => 'flat', 'discount_amount' => 50.00, 'coupon_limit' => 10,
            'free_shipping_coupon' => 1, 'minimum_spend' => 5000,
        ], ['sub_total' => 200.00, 'final_sub_total' => 9999.00]);

        $this->assertStringContainsString('minimum spend', $this->refusalMessage($body),
            'THE THRESHOLD IS NOT CHECKED ON THIS BRANCH. A free-shipping coupon restricted to '
            . 'baskets over 5000 was granted on a 200.00 one, in any store with no shipping '
            . 'method configured.');
    }

    /** The maximum behaves the same way, and names itself. */
    public function test_a_maximum_spend_is_enforced_on_the_server_figure(): void
    {
        [, $body] = $this->applyCoupon([
            'coupon_type' => 'flat', 'discount_amount' => 50.00, 'coupon_limit' => 10,
            'maximum_spend' => 100,
        ], ['sub_total' => 1.00, 'final_sub_total' => 1.00]);

        $message = $this->refusalMessage($body);

        $this->assertStringContainsString('maximum spend', $message);
        $this->assertStringContainsString('100', $message,
            'a posted 1.00 satisfied a maximum of 100 while the real basket is 200.00');
    }

    /**
     * AND THE DISCOUNT BASE IS STILL THE SERVER'S, which was already true and must stay true.
     *
     * Without this, "the thresholds use the server figure" could be satisfied by a change that
     * also started pricing the discount off the posted one -- the same defect moved.
     */
    public function test_an_inflated_posted_subtotal_does_not_inflate_the_discount(): void
    {
        [, $body] = $this->applyCoupon([
            'coupon_type' => 'percentage', 'discount_amount' => 10.00, 'coupon_limit' => 10,
        ], ['sub_total' => 200.00, 'final_sub_total' => 1000000.00]);

        $data = $this->assertApplied($body, 'the coupon was refused for an unrelated reason');

        $this->assertSame('20.00', number_format((float) $data['amount'], 2, '.', ''),
            'THE DISCOUNT IS PRICED FROM THE POSTED SUBTOTAL: 10% of a posted 1,000,000 is '
            . '100,000, and the real basket is 200.00.');
    }

    /**
     * THE FREE-SHIPPING ARM REPORTS A SERVER-DERIVED DISCOUNT -- AND THIS TEST DOES NOT MEASURE THE
     * LINE I FIRST CLAIMED IT DID. The correction is kept because the claim was wrong, not the test.
     *
     * WHAT I WROTE: that this covers "the second copy of the percentage arithmetic ~500 lines
     * further down". THE INDEPENDENT REVIEW SAID IT CANNOT, AND THE MUTANT AGREES: reverting that
     * copy's base to `$request->final_sub_total` fails NOTHING (19 tests still pass). The copy sits
     * inside `if ($shipping_Methods != null)`, and `$shipping_Methods` comes from
     * `\Session::get('shipping_method')`, which a `postJson` with no prior session write leaves
     * null -- so control takes the `== null` arm, where `amount` comes from the CART's own figure.
     *
     * SO WHAT THIS TEST ACTUALLY PINS, which is still worth having: on the free-shipping arm a
     * posted `final_sub_total` of 1,000,000 does not inflate the reported discount above the real
     * basket's 10%. It measures the OUTCOME on the arm a client reaches, not the line.
     *
     * UNMEASURED, AND NAMED RATHER THAN IMPLIED: the `$shipping_Methods != null` arm's own base and
     * its shipping-cost comparison. Reaching it needs a seeded `shipping_method` session key AND
     * real shipping-method rows, because the handler re-fetches through
     * `CartController::get_shipping_data()` and the session value is only a flag -- a fixture out of
     * proportion to a line whose change is identical to the one that IS measured. Recorded in the
     * P1-ORD-01 ledger row as not covered.
     */
    public function test_a_free_shipping_coupon_does_not_report_an_inflated_discount(): void
    {
        [, $body] = $this->applyCoupon([
            'coupon_type' => 'percentage', 'discount_amount' => 10.00, 'coupon_limit' => 10,
            'free_shipping_coupon' => 1,
        ], ['sub_total' => 200.00, 'final_sub_total' => 1000000.00]);

        $data = $this->assertApplied($body, 'the free-shipping percentage coupon was refused');

        $this->assertSame('20.00', number_format((float) $data['amount'], 2, '.', ''),
            'the free-shipping copy of the percentage arithmetic still prices off the posted '
            . 'subtotal: 10% of a posted 1,000,000 against a real 200.00 basket');
    }

    /**
     * THE TWO COUPON ARITHMETICS MUST AGREE ABOUT THE USAGE LIMIT.
     *
     * FOUND BY THE INDEPENDENT REVIEW OF THIS FIX, and it is a defect THE FIX INTRODUCED. The raw
     * `where('coupon_limit', '>', $uses)` gate exists in THREE places; I changed the two in
     * `ApiController` and missed `Cart::getCouponTotalAmount` (Cart.php:1070). Before the change
     * both arithmetics refused a `coupon_limit = 0` coupon, so they agreed on a refusal. After it,
     * `apply_coupon` accepts and the cart's per-item path still refuses -- and on the free-shipping
     * arm for a store with no shipping method the reported `amount` comes from the CART
     * (`$response->data->total_coupon_price`), so the answer is "Coupon code applied successfully"
     * with a discount of ZERO. The shopper is told the coupon worked and is charged full price,
     * which is criterion 4's exact shape arriving through a door the `elseif` does not cover.
     *
     * My own census for the fix was keyed on the two call sites I had already read rather than on
     * the construct across app/ -- census-failure mode 1 in this project's own skill, committed
     * while fixing a census-shaped defect.
     */
    public function test_the_cart_and_apply_coupon_agree_about_a_default_usage_limit(): void
    {
        [, $body] = $this->applyCoupon([
            'coupon_type' => 'flat', 'discount_amount' => 50.00,
            'coupon_limit' => 0,                 // the schema default
            'free_shipping_coupon' => 1,         // the arm that reads the cart's own figure
        ], ['sub_total' => 200.00, 'final_sub_total' => 200.00]);

        $data = $this->assertApplied($body, 'the free-shipping default-limit coupon was refused');

        $this->assertSame('50.00', number_format((float) $data['amount'], 2, '.', ''),
            'THE TWO ARITHMETICS DISAGREE: apply_coupon accepted this coupon and the cart still '
            . 'treats coupon_limit = 0 as exhausted, so the reported discount is 0.00 and the '
            . 'shopper is charged full price while being told the coupon applied.');
    }

    /**
     * AND NO FOURTH RAW USAGE-LIMIT GATE MAY APPEAR. This is the check that would have caught the
     * miss above, so it is the one worth keeping.
     *
     * Keyed on the CONSTRUCT across all of app/ with comments stripped by PHP's own tokenizer --
     * not on a list of files, which is how the third site was missed in the first place, and not on
     * a grep, which a docblock quoting the old gate would satisfy.
     */
    public function test_no_raw_coupon_limit_gate_remains_outside_the_shared_helper(): void
    {
        $offenders = [];
        $root = base_path('app');

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $code = '';
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= is_array($token) ? $token[1] : $token;
            }

            // The raw gate, in any spelling of the comparison.
            if (! preg_match('/[\'"]coupon_limit[\'"]\s*,\s*[\'"][<>]=?[\'"]/', $code)) {
                continue;
            }

            $relative = str_replace($root . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

            // Coupon.php is where the shared predicate legitimately expresses it.
            if ($relative === 'Models/Coupon.php') {
                continue;
            }

            $offenders[] = $relative;
        }

        $this->assertSame([], $offenders,
            "A RAW coupon_limit GATE OUTSIDE Coupon::applyUsageLimit():
  "
            . implode("
  ", $offenders)
            . "

`coupon_limit` defaults to 0, so `0 > \$uses` reads a brand-new coupon as "
            . 'exhausted. Three sites carried this gate and fixing two of them made the two coupon '
            . 'arithmetics disagree -- the shopper was told "applied successfully" with a 0.00 '
            . 'discount. Route it through the shared helper.');
    }

    /**
     * THE NON-CUSTOMER ARM OF couponSpendBaseline, DRIVEN WITH A MERCHANT TOKEN.
     *
     * RAISED BY THE INDEPENDENT REVIEW, and it is this project's own recorded rule: "a
     * customer-token probe cannot test the trap -- drive a merchant and a driver token too." Every
     * other test here uses a Customer token and so takes the `CustomerAuthCheck` arm. The OTHER arm
     * prices the cookie basket, and it is reachable: `apply-coupon` carries only `custom.auth`,
     * which logs a token's owner into whichever guard matches its tokenable class, and
     * `apply_coupon` assigns `auth('customers')->user()` without ever reading it -- so a merchant's
     * token reaches the handler with `CustomerAuthCheck` false.
     *
     * WHAT MUST HOLD: the baseline FAILS CLOSED. A caller with no customer basket must not be
     * handed a discount, and must not fall back to the posted subtotal -- which is exactly what the
     * old code did, because the figure came from `$request->final_sub_total` and no basket was
     * consulted at all.
     */
    public function test_a_merchant_token_cannot_obtain_a_discount_from_a_posted_subtotal(): void
    {
        [$merchant, $store] = $this->tenant();
        $product = $this->productIn($store, 100.00);

        DB::table('coupons')->insert([
            'coupon_code' => 'SAVE', 'coupon_name' => 'Save',
            'coupon_type' => 'flat', 'discount_amount' => 50.00,
            'coupon_limit' => 10, 'coupon_limit_user' => 0,
            'coupon_expiry_date' => now()->addYear()->toDateString(),
            'status' => 1, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // A MERCHANT token, not a customer one. custom.auth logs it into the `web` guard, so
        // auth('customers') stays null and CustomerAuthCheck answers false.
        $token = $merchant->createToken('merchant-probe')->plainTextToken;

        $raw = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/' . $store->slug . '/apply-coupon', [
                'coupon_code' => 'SAVE',
                'sub_total' => 5000.00,          // the posted lie
                'final_sub_total' => 5000.00,
            ]);

        // PREMISE: the request reached the HANDLER. Without this the assertions below are
        // satisfied by a 401 from custom.auth or a 404, and the arm is still unmeasured -- which
        // is the trap this project records for exactly this shape of test.
        $this->assertSame(200, $raw->getStatusCode(),
            'Premise: a merchant token reaches apply_coupon. ApiResponser::error() returns HTTP 200 '
            . 'with status 0, so a non-200 here means the middleware refused and this test measures '
            . 'nothing.');

        $body = json_decode((string) $raw->getContent(), true) ?: [];

        $this->assertArrayHasKey('status', $body,
            'Premise: the handler produced an ApiResponser envelope rather than a framework error.');

        // Either outcome is acceptable; what is NOT acceptable is a discount priced from the posted
        // 5000.00. A refusal is the fail-closed path; an acceptance must price the real (empty)
        // basket.
        if ((int) ($body['status'] ?? 0) === 1) {
            $amount = (float) ($body['data']['amount'] ?? 0);

            $this->assertSame('0.00', number_format($amount, 2, '.', ''),
                'A NON-CUSTOMER PRINCIPAL WAS PRICED FROM ITS OWN POSTED SUBTOTAL. The baseline '
                . 'must come from a real basket, and this caller has none.');

            return;
        }

        $this->assertSame(0, (int) ($body['status'] ?? 1),
            'Premise: the response is a refusal, which is the fail-closed outcome.');
    }

    /**
     * A COUPON LARGER THAN THE BASKET CANNOT PRODUCE A NEGATIVE PAYABLE -- the max(0, ...) floor.
     *
     * RAISED BY THE INDEPENDENT REVIEW as untested in either direction, and it was right: no test
     * drove a discount bigger than the goods. The review also notes the floor is LAXER than two
     * sibling handlers (`confirm_order` and `ordersave` refuse an implausible figure instead of
     * flooring), which is a genuine inconsistency recorded in the P1-ORD-01 row rather than changed
     * here -- aligning three handlers' coupon policy is a product decision, not a defect fix.
     *
     * What is asserted is the property that must hold either way: the stored payable is never
     * negative, and the merchant is never left owing the shopper money.
     */
    public function test_a_coupon_larger_than_the_basket_cannot_make_the_payable_negative(): void
    {
        [, $body] = $this->applyCoupon([
            'coupon_type' => 'flat', 'discount_amount' => 5000.00, 'coupon_limit' => 10,
        ], ['sub_total' => 200.00]);

        $data = $this->assertApplied($body, 'the oversized coupon was refused at apply time, which '
            . 'is also a safe outcome -- but then this test measures nothing, so it is reported');

        $this->assertGreaterThanOrEqual(0.0, (float) $data['final_price'],
            'apply_coupon reported a NEGATIVE total for a discount larger than the basket: '
            . var_export($data['final_price'], true));
    }

    /**
     * THE PER-USER CAP CANNOT BE EVADED BY POSTING A DIFFERENT BILLING E-MAIL.
     *
     * RAISED BY THE INDEPENDENT REVIEW of this package. `coupon_limit_user` was enforced by
     * comparing the signed-in customer's ACCOUNT e-mail against prior uses resolved through
     * `order_billing_details.email` -- a column written straight from `billing_info`. So the two
     * e-mails only match when the shopper chooses to make them match.
     *
     * THE FIXTURE IS THE EVASION BY DEFAULT, which is why this was worth a test rather than a
     * reading: the shopper's account e-mail is `ord01-...@example.test` and the billing e-mail this
     * suite posts is `api@example.test`. They have never matched, so `$i` was always 0 and the cap
     * never fired for any order this suite writes.
     *
     * Coupon::usesByCustomer() now takes the higher of the server-derived `user_coupons.user_id`
     * count and the legacy e-mail count.
     */
    public function test_the_per_user_cap_counts_the_principal_not_the_posted_email(): void
    {
        [, $store] = $this->tenant();
        $shopper = $this->shopper($store);
        $product = $this->productIn($store, 100.00);

        $couponId = DB::table('coupons')->insertGetId([
            'coupon_code' => 'SAVE', 'coupon_name' => 'Save',
            'coupon_type' => 'flat', 'discount_amount' => 50.00,
            'coupon_limit' => 0,                 // the schema default: no TOTAL cap
            'coupon_limit_user' => 1,            // ...so this is the only backstop
            'coupon_expiry_date' => now()->addYear()->toDateString(),
            'status' => 1, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // A PRIOR USE BY THIS CUSTOMER, attributed the way place_order attributes it
        // ($UserCoupon->user_id = $customerId, server-derived) ...
        $priorOrder = $this->makeOrder($store, ['payment_type' => 'cod']);
        DB::table('user_coupons')->insert([
            'coupon_id' => $couponId, 'user_id' => $shopper->id,
            'amount' => 50.00, 'order_id' => $priorOrder->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // ... whose BILLING e-mail is not the account e-mail, which is the evasion.
        DB::table('order_billing_details')->insert([
            'order_id' => $priorOrder->id, 'email' => 'someone-else@example.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Cart::forceCreate([
            'customer_id' => $shopper->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 2, 'price' => 100.00, 'store_id' => $store->id,
        ]);

        $token = $shopper->createToken('cap-test')->plainTextToken;
        $body = json_decode((string) $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/' . $store->slug . '/apply-coupon', [
                'coupon_code' => 'SAVE', 'customer_id' => $shopper->id, 'sub_total' => 200.00,
            ])->getContent(), true) ?: [];

        $this->assertStringContainsString('usage limit', $this->refusalMessage($body),
            'THE PER-USER CAP DID NOT FIRE. This customer has already used the coupon once and the '
            . 'cap is 1, but the prior use was attributed to a billing e-mail that is not their '
            . 'account e-mail -- so counting by e-mail alone sees no prior use. With coupon_limit = '
            . '0 meaning "no total cap", this is unlimited reuse by one shopper.');
    }

    /**
     * THE LIVENESS CONTROL FOR EVERY REFUSAL ABOVE.
     *
     * Four tests assert a refusal, and a handler that refuses everyone satisfies all four. This
     * drives the SAME free-shipping, no-shipping-method branch as the worst case above with a
     * threshold the basket DOES meet, so the refusals are known to be the gate and not the branch
     * being broken outright.
     */
    public function test_a_qualifying_free_shipping_coupon_on_the_same_branch_still_applies(): void
    {
        [, $body] = $this->applyCoupon([
            'coupon_type' => 'flat', 'discount_amount' => 50.00, 'coupon_limit' => 10,
            'free_shipping_coupon' => 1, 'minimum_spend' => 100,
        ], ['sub_total' => 200.00, 'final_sub_total' => 200.00]);

        $this->assertApplied($body, 'Control: a 200.00 basket DOES meet a minimum of 100, so this '
            . 'must apply. If it does not, the refusal tests above prove nothing.');
    }
}
