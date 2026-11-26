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

    /*
    |--------------------------------------------------------------------------
    | P4-F061 / GAP-132 — A DEACTIVATED COUPON STILL DISCOUNTED
    |--------------------------------------------------------------------------
    |
    | THE FINDING, as measured in docs/development/phase-4/findings/pricing-discounts.md:
    | "`coupons.status` is assigned in SEVEN places across six files ... It is read by no query
    | predicate and no conditional anywhere in app/ or packages/workdo/: not by
    | Coupon::findByExactCode(), not by any of its eight call-site constraints, not by
    | Cart::getCouponTotalAmount(), not by the guest-checkout lookup (PaymentService) or the
    | order-recording lookup. Coupon declares no global scope and is in no scoped_models entry, so
    | nothing underneath can supply one. A coupon the merchant switched to Inactive therefore still
    | validates and still discounts, bounded only by its own expiry, coupon_limit,
    | coupon_limit_user and spend gates, which all still work."
    |
    | The column's own meaning is in its migration:
    | `$table->integer('status')->default(1)->comment('0 => Inactive, 1 => Active ')`.
    |
    | REACHABLE BY AN ANONYMOUS VISITOR who knows the code — `routes/customer.php:203` carries no
    | auth middleware — and it moves money on a cod order, where `orders.final_price` is the cash a
    | driver collects. The merchant believes they have withdrawn the offer.
    |
    | FOUR CASES, BECAUSE THERE ARE TWO ARITHMETICS AND TWO DOORS. `apply_coupon` and
    | `Cart::getCouponTotalAmount()` are separate code paths that have disagreed before — the
    | `coupon_limit` family was missed exactly that way, which is what
    | `test_the_cart_and_apply_coupon_agree_about_a_default_usage_limit` above exists for — and the
    | anonymous web route reaches the same handler through a different front door. The positive
    | control is not optional: without it every case below is satisfied by a fix that refuses
    | everything.
    */

    /**
     * The 100.00-basket fixture of `applyCoupon()`, but built so the caller can also drive
     * `cart-list` and the anonymous web route against the same rows.
     *
     * COUPON WRITTEN THROUGH ELOQUENT, DELIBERATELY. `Coupon` is `Cachable` and the suite runs
     * under `CACHE_STORE=array`, so a raw `DB::table('coupons')->update(['status' => 0])` would
     * leave every later lookup serving the pre-write row — a green test proving nothing (CLAUDE.md,
     * model caching). `status` is in `$fillable`, so `Coupon::create()` persists it.
     *
     * @return array{0:mixed,1:\App\Models\Customer,2:\App\Models\Coupon,3:string}
     */
    private function storeWithCoupon(int $status, array $overrides = []): array
    {
        [, $store] = $this->tenant();
        $shopper = $this->shopper($store);
        $product = $this->productIn($store, 100.00);

        $coupon = \App\Models\Coupon::create(array_merge([
            'coupon_name' => 'Save', 'coupon_code' => 'SAVE',
            'coupon_type' => 'flat', 'discount_amount' => 50.00,
            'coupon_limit' => 0,
            'coupon_expiry_date' => now()->addYear()->toDateString(),
            'status' => $status,
        ], $overrides));
        // `store_id` and `coupon_limit_user` are not fillable on this model.
        $coupon->forceFill(['store_id' => $store->id, 'coupon_limit_user' => 0])->save();

        Cart::forceCreate([
            'customer_id' => $shopper->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 2, 'price' => 100.00, 'store_id' => $store->id,
        ]);

        return [$store, $shopper, $coupon, $shopper->createToken('f061')->plainTextToken];
    }

    /** THE PREMISE: the column really does say Inactive, and the row really is the store's. */
    public function test_premise_the_fixture_stores_an_inactive_coupon_for_this_store(): void
    {
        [$store, , $coupon] = $this->storeWithCoupon(0);

        $row = DB::table('coupons')->where('id', $coupon->id)->first();

        $this->assertSame(0, (int) $row->status,
            'the fixture did not persist status = 0, so every case below measures nothing. '
            . '`status` must be in Coupon::$fillable and the write must flush the model cache.');
        $this->assertSame((int) $store->id, (int) $row->store_id,
            'premise: the coupon belongs to the store the requests name');
    }

    /** (1) THE DEFECT, on the signed-in storefront API. */
    public function test_a_deactivated_coupon_is_refused_by_apply_coupon(): void
    {
        [$store, $shopper, , $token] = $this->storeWithCoupon(0);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/' . $store->slug . '/apply-coupon', [
                'coupon_code' => 'SAVE', 'customer_id' => $shopper->id, 'sub_total' => 200.00,
            ]);

        $this->assertSame(200, $response->getStatusCode(),
            'premise: the route exists and the handler ran — a 404 or a 500 satisfies a row '
            . 'assertion for the wrong reason');

        $body = json_decode((string) $response->getContent(), true) ?: [];

        $this->assertSame(0, (int) ($body['status'] ?? 1),
            'A COUPON THE MERCHANT SWITCHED TO INACTIVE STILL APPLIED. `coupons.status` is '
            . 'written by four merchant-facing editors and read by no gate, so the toggle does '
            . 'nothing. Measured before the fix: status 1 with a 50.00 discount on a 200.00 '
            . 'basket. Body: ' . json_encode($body['data'] ?? $body));

        $this->assertNotSame('', $this->refusalMessage($body),
            'the refusal carries no message, so the shopper is told nothing');
    }

    /** (2) THE CONTROL. An active coupon must stay green across the fix. */
    public function test_an_active_coupon_still_applies(): void
    {
        [$store, $shopper, , $token] = $this->storeWithCoupon(1);

        $body = json_decode((string) $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/' . $store->slug . '/apply-coupon', [
                'coupon_code' => 'SAVE', 'customer_id' => $shopper->id, 'sub_total' => 200.00,
            ])->getContent(), true) ?: [];

        $data = $this->assertApplied($body, 'CONTROL: an ACTIVE coupon is refused, so the status '
            . 'gate refuses everything and the cases around it prove nothing');

        $this->assertSame('50.00', number_format((float) $data['amount'], 2, '.', ''),
            'the active coupon no longer discounts its configured 50.00');
    }

    /**
     * (3) THE SECOND ARITHMETIC, which a two-site fix would miss.
     *
     * `Cart::getCouponTotalAmount()` is handed an already-resolved model and makes its own refusal
     * decisions, so it needs its own check: the `coupon_limit` family went wrong exactly here.
     *
     * AND THE REFUSAL MUST RETURN THE LINE UNDISCOUNTED, NEVER 0. `Cart.php`'s own docblock records
     * why — a `return 0;` means "this line is free" to both callers, which is the zeroed-line
     * defect `RefusedCouponLinePriceTest` pins — so this case asserts the SUBTOTAL as well as the
     * absent discount.
     */
    public function test_a_deactivated_coupon_does_not_discount_the_cart_line(): void
    {
        [$store, $shopper, , $token] = $this->storeWithCoupon(0);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/' . $store->slug . '/cart-list', [
                'coupon_code' => 'SAVE', 'customer_id' => $shopper->id,
            ]);

        $this->assertSame(200, $response->getStatusCode(), 'premise: cart-list answered');

        $data = (json_decode((string) $response->getContent(), true) ?: [])['data'] ?? [];

        $this->assertArrayHasKey('total_coupon_price', $data,
            'premise: cart-list reports a coupon figure — if the key moved, this case is vacuous');

        $this->assertSame('0.00', number_format((float) $data['total_coupon_price'], 2, '.', ''),
            'THE CART STILL DISCOUNTS A DEACTIVATED COUPON. This is the second coupon arithmetic '
            . 'and a different code path from apply_coupon; the two have disagreed before. '
            . 'Measured before the fix: 50.00.');

        $this->assertSame('200.00', number_format((float) $data['sub_total'], 2, '.', ''),
            'the refusal must leave the line UNDISCOUNTED (getCouponTotalAmount returns $subTotal), '
            . 'not zero it — a 0 there is the free-line defect RefusedCouponLinePriceTest pins');
    }

    /**
     * (4) THE ANONYMOUS WEB DOOR. `routes/customer.php:203` is in the auth-free group, so this runs
     * with no session and no token at all.
     *
     * `OrderController::applycoupon` resolves the coupon itself and then delegates to
     * `ApiController::apply_coupon`, so a gate placed only in the API handler's own constraint
     * would leave this door's resolution untouched.
     *
     * TWO FIXTURE MECHANICS THIS CASE WAS WRONG ABOUT FIRST, both measured rather than reasoned,
     * and both of which made it pass for the wrong reason:
     *
     *   (a) `postJson()` SENDS NO COOKIES unless `withCredentials()` is called --
     *       `prepareCookiesForJsonRequest()` returns `[]` otherwise (Laravel 11,
     *       MakesHttpRequests:715). So `Cookie::get('cart')` answered null.
     *   (b) AN ANONYMOUS SHOPPER'S BASKET IS THE COOKIE CART, not the signed-in customer's rows.
     *       With an empty one, `apply_coupon`'s guest branch refuses with "This coupon has
     *       expired." -- `empty(array_diff([], []))` on both sides of its sale-items test is true
     *       for an empty product list, whatever the coupon is. The first version of this case
     *       therefore PASSED before any fix while its own control FAILED, which is what caught it.
     */
    public function test_a_deactivated_coupon_is_refused_on_the_anonymous_web_route(): void
    {
        [$store] = $this->storeWithCoupon(0);
        $cookie = $this->guestBasketIn($store);

        $response = $this->withCredentials()->withCookie('cart', $cookie)
            ->postJson('/' . $store->slug . '/applycoupon', [
                'coupon_code' => 'SAVE', 'sub_total' => 200.00,
            ]);

        $this->assertNotSame(404, $response->getStatusCode(),
            'premise: the anonymous web route exists — a 404 satisfies the assertion below for '
            . 'the wrong reason');

        $body = json_decode((string) $response->getContent(), true) ?: [];

        $this->assertSame(0, (int) ($body['status'] ?? 1),
            'A DEACTIVATED COUPON APPLIED TO AN ANONYMOUS VISITOR who knows the code. This route '
            . 'carries no auth middleware, so the merchant\'s withdrawn offer is public. Measured '
            . 'before the fix: "Coupon code applied successfully", 50.00 off a 200.00 basket, '
            . 'final_price 150.00, with no session at all. '
            . 'Body: ' . json_encode($body['data'] ?? $body));
    }

    /**
     * AND THE SAME DOOR STILL WORKS FOR AN ACTIVE COUPON — the control for case 4, and the one
     * that found both fixture faults above.
     */
    public function test_an_active_coupon_still_applies_on_the_anonymous_web_route(): void
    {
        [$store] = $this->storeWithCoupon(1);
        $cookie = $this->guestBasketIn($store);

        $body = json_decode((string) $this->withCredentials()->withCookie('cart', $cookie)
            ->postJson('/' . $store->slug . '/applycoupon', [
                'coupon_code' => 'SAVE', 'sub_total' => 200.00,
            ])->getContent(), true) ?: [];

        $this->assertSame(1, (int) ($body['status'] ?? 0),
            'CONTROL: the anonymous door refuses an ACTIVE coupon too, so case 4 proves nothing. '
            . 'Body: ' . json_encode($body['data'] ?? $body));

        $this->assertSame('150.00', number_format((float) ($body['data']['final_price'] ?? 0), 2, '.', ''),
            'CONTROL: the anonymous door no longer discounts an active coupon by its 50.00');
    }

    /**
     * A GUEST'S BASKET: cart rows keyed on a cookie value, and the cookie that names them.
     *
     * `Cart::scopeForGuestCookie()` matches on `cookie_session_id`, and `cart_list_cookie()` reads
     * the value from `Cookie::get('cart')` — so a signed-in customer's rows are invisible to this
     * path and vice versa.
     */
    private function guestBasketIn($store): string
    {
        $cookie = 'guest-' . uniqid();

        Cart::forceCreate([
            'customer_id' => 0,
            'cookie_session_id' => $cookie,
            'product_id' => DB::table('products')->where('store_id', $store->id)->orderByDesc('id')->value('id'),
            'variant_id' => 0, 'qty' => 2, 'price' => 100.00, 'store_id' => $store->id,
        ]);

        return $cookie;
    }

    /**
     * AND THE MERCHANT MUST STILL BE ABLE TO SEE WHAT THEY DEACTIVATED.
     *
     * The finding is explicit that this is the way to get the fix wrong: "Merchant-side reads
     * (`CouponDataTable::query()`, `CouponController::edit`, `DashboardController::CouponList`) must
     * NOT get the predicate or the merchant can no longer see or re-enable what they deactivated."
     * A gate pushed into a global scope, or into a plain `Coupon::where()`, would do exactly that.
     */
    public function test_the_merchant_can_still_list_and_read_a_deactivated_coupon(): void
    {
        [$store, , $coupon] = $this->storeWithCoupon(0);

        $this->assertNotNull(\App\Models\Coupon::find($coupon->id),
            'a deactivated coupon has become invisible to a plain model read, so the merchant '
            . 'cannot re-enable it and the edit screen 404s');

        $this->assertSame(1, \App\Models\Coupon::where('store_id', $store->id)->count(),
            'the merchant\'s own coupon list no longer shows a deactivated coupon');
    }

    /**
     * NO STOREFRONT COUPON RESOLUTION BYPASSES THE GATE.
     *
     * The shape of `test_no_raw_coupon_limit_gate_remains_outside_the_shared_helper` above, for the
     * same reason: the gate is placed in `Coupon::findByExactCode()` because that is the single
     * chokepoint for all eight storefront lookups, four of which live in files this change may not
     * edit. If a ninth lookup appears that resolves a coupon by CODE without going through it, this
     * fails and somebody reads it.
     *
     * KEYED ON THE CONSTRUCT WITH COMMENTS STRIPPED by PHP's own tokenizer — a docblock naming the
     * old shape would satisfy a grep, which CLAUDE.md records happening four times in one day.
     *
     * WHAT IT READS, AND WHAT IT DOES NOT — stated because a completeness claim here has failed
     * four times in this project by being keyed on spelling rather than on the thing it measures.
     *
     * IT READS every narrowing of a query by the `coupon_code` COLUMN anywhere under `app/`, with
     * comments stripped. Measured: TWO occurrences, of which one is the docblock sentence in
     * `Coupon::findByExactCode()` explaining the narrowing — so with comments stripped there is
     * exactly ONE, and it is inside the helper. (`packages/workdo/` has zero, measured
     * separately.) That is what makes the chokepoint a real chokepoint rather than a hope.
     *
     * IT DOES NOT READ lookups by a posted `coupon_id`. Those are a different family — P4-F135's —
     * and they are order-RECORDING sites rather than validation sites: `PaymentService` x3,
     * `PaymentController` x6, `AuthorizeNetPaymnetController` x3, `PayUPaymentController` x2,
     * `SspayController` x1 and about twenty more gateway controllers, measured. The status gate in
     * `findByExactCode()` does not reach any of them, deliberately: a redemption must be recorded
     * against whatever coupon the server actually granted, even one the merchant deactivated in
     * the meantime, or the caps under-count (which is P4-F134's harm). They are listed here rather
     * than ratcheted by count, because a count over twenty gateway controllers is the kind of
     * line-keyed inventory CLAUDE.md §6b records as the commonest way unrelated work turns CI red.
     */
    public function test_no_storefront_coupon_code_lookup_bypasses_the_status_gate(): void
    {
        $offenders = [];
        $total = 0;

        foreach ($this->phpFilesUnder(base_path('app')) as $path => $code) {
            if (! preg_match_all('/where\s*\(\s*[\'"]coupon_code[\'"]/', $code, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($m[0] as [, $offset]) {
                $total++;
                $relative = str_replace(base_path() . '/', '', $path);

                // The one permitted site: the shared helper itself.
                if ($relative === 'app/Models/Coupon.php') {
                    continue;
                }

                $offenders[] = $relative . ':' . (substr_count(substr($code, 0, $offset), "\n") + 1);
            }
        }

        $this->assertSame([], $offenders,
            'A COUPON IS NARROWED BY ITS CODE OUTSIDE Coupon::findByExactCode(), so the status '
            . 'gate does not cover it. Either route it through the helper or state here why it is '
            . 'exempt: ' . implode(', ', $offenders));

        // REACHABILITY: a scan that found nothing because it read nothing is not evidence.
        $this->assertSame(1, $total,
            'the scan expected exactly ONE coupon_code narrowing in app/ (the helper\'s own) and '
            . 'found ' . $total . '. If the helper was renamed or rewritten, this scan is no '
            . 'longer measuring the chokepoint it claims to.');
    }

    /*
    |--------------------------------------------------------------------------
    | THE TWO CONTRACTS NO HTTP CASE CAN REACH — and that is measured, not assumed
    |--------------------------------------------------------------------------
    |
    | FOUND BY MUTATION, not by reading. Three mutants of this fix SURVIVED every HTTP case above:
    | deleting the cart arithmetic's own status check, turning its refusal into `return 0`, and
    | making the redemption recorder stop opting out of the gate. The reason is the same for all
    | three and it is not a coverage gap in the HTTP cases — it is that the chokepoint gets there
    | first:
    |
    |   BOTH call sites of `CouponBasketAllocator::forBasket()` (`Cart.php:684`,
    |   `ApiController.php:934`) resolve their coupon through `Coupon::findByExactCode()` seven
    |   lines earlier, so `getCouponTotalAmount()` is never HANDED an inactive coupon over HTTP.
    |   Its own check is defence in depth, which the F061 finding asked for by name ("it is handed
    |   an already-resolved model, so it needs its own check") — and defence in depth whose only
    |   input comes from a layer that already refuses cannot be driven end to end.
    |
    | SO THEY ARE PINNED AT THEIR OWN CONTRACT instead, by direct call. Per this project's rule for
    | a surviving mutant: do not weaken the mutant, ask what input would make the two behaviours
    | disagree. The answer is a caller that resolves a coupon some other way — which is exactly what
    | the defence exists for, and exactly what these two cases supply.
    |
    | AND THE TWO LAYERS MAKE ONE HTTP CASE DOUBLE-COVERED, which is worth writing down because it
    | looks like a hole from either side. `test_a_deactivated_coupon_does_not_discount_the_cart_line`
    | survives the deletion of EITHER layer alone — the chokepoint refuses the lookup, or the cart
    | check refuses the model — and only fails when both are removed together. That is the shape the
    | F134/F135 work recorded too ("both together, because either alone still blocks the write"); it
    | is depth rather than redundancy, and the single-layer mutants are killed by the cases above and
    | the direct-call cases below respectively.
    |
    | A DIRECT CALL NEEDS THE DEFAULT GUARD PUT BACK. `getCouponTotalAmount()` reads
    | `auth('customers')->user()` for the per-user cap; over HTTP the kernel sets the guard, and in a
    | direct call `actingAs(..., 'customers')` would also call `shouldUse('customers')` and change
    | what `getCurrentStore()` answers (CLAUDE.md; skill lesson 5). No principal is signed in here,
    | which is the production shape for a guest basket, and the cap is left at 0 so it cannot be the
    | thing that refuses.
    */

    public function test_the_cart_arithmetic_refuses_an_inactive_coupon_handed_to_it_directly(): void
    {
        [$store, , $coupon] = $this->storeWithCoupon(0);

        $this->assertNull(auth()->user(),
            'premise: no principal is signed in, so the per-user cap cannot be what refuses');
        $this->assertSame(0, (int) $coupon->status, 'premise: the model carries status 0');

        $this->assertSame(
            200.00,
            (float) Cart::getCouponTotalAmount($coupon, 200.00, 0, 0),
            'getCouponTotalAmount() discounted a line for a coupon whose status is 0. It is handed '
            . 'an already-resolved model, so the chokepoint in findByExactCode() cannot protect a '
            . 'caller that resolves one some other way. AND THE REFUSAL MUST BE $subTotal, NOT 0: '
            . 'both readers compute the discount as `$final_price - $answer`, so a 0 here makes the '
            . 'whole line free — the defect RefusedCouponLinePriceTest pins.'
        );
    }

    /** THE CONTROL for the case above: an ACTIVE coupon still discounts when handed over directly. */
    public function test_the_cart_arithmetic_still_discounts_an_active_coupon_handed_to_it_directly(): void
    {
        [, , $coupon] = $this->storeWithCoupon(1);

        $this->assertSame(
            150.00,
            (float) Cart::getCouponTotalAmount($coupon, 200.00, 0, 0),
            'CONTROL: a 50.00 flat coupon on a 200.00 line must leave 150.00. If this fails the '
            . 'status check refuses everything and the case above proves nothing.'
        );
    }

    /**
     * AND THE RECORDER MUST STILL RECORD A COUPON THE MERCHANT SWITCHED OFF MID-FLIGHT.
     *
     * `CouponRedemptionRecorder` passes `activeOnly: false` deliberately. If it did not, a merchant
     * deactivating a coupon between `apply_coupon()`'s acceptance and the order write would leave
     * the order discounted with no `user_coupons` row — and every cap is a count on that table, so
     * the missing row is a limit that cannot fire. That is P4-F134's own harm arriving through the
     * F061 fix, and no HTTP case can stage the window, so it is pinned at the class's contract.
     */
    public function test_the_redemption_recorder_still_records_a_deactivated_coupon(): void
    {
        [$store, $shopper, $coupon] = $this->storeWithCoupon(0);

        $order = \App\Models\Order::withoutEvents(fn () => \App\Models\Order::forceCreate([
            'product_order_id' => 'F061-' . uniqid(),
            'customer_id' => $shopper->id, 'store_id' => $store->id,
            'product_json' => json_encode([]), 'product_price' => 200.00,
            'final_price' => 150.00, 'coupon_price' => 50.00,
            'payment_type' => 'cod', 'payment_status' => 'Unpaid',
        ]));

        $detail = app(\App\Services\CouponRedemptionRecorder::class)
            ->record($order, $store, 'SAVE', 50.00, 150.00, (int) $shopper->id);

        $this->assertNotNull($detail,
            'THE RECORDER REFUSED A DEACTIVATED COUPON, so a merchant switching a coupon off '
            . 'between acceptance and the order write leaves the discount granted and the '
            . 'redemption unrecorded. P4-F134 re-opened by the F061 fix.');

        $this->assertSame((int) $coupon->id, (int) $detail->coupon_id,
            'the row was recorded against a different coupon than the one granted');

        $this->assertSame(1,
            DB::table('user_coupons')->where('order_id', $order->id)->where('coupon_id', $coupon->id)->count(),
            'the detail row exists and the row the CAPS count does not, which is the half that '
            . 'matters: every coupon limit is a count on user_coupons');
    }

    /** AND THE GATE REALLY IS IN THE HELPER, not merely described in its docblock. */
    public function test_the_status_gate_is_in_the_shared_helper_and_not_only_in_its_prose(): void
    {
        $source = $this->phpFilesUnder(base_path('app/Models'))[base_path('app/Models/Coupon.php')] ?? '';

        $this->assertNotSame('', $source, 'premise: Coupon.php was read');

        $this->assertMatchesRegularExpression(
            '/where\s*\(\s*[\'"]status[\'"]\s*,\s*1\s*\)/',
            $source,
            'Coupon::findByExactCode() carries no status predicate in CODE. A docblock saying it '
            . 'does would satisfy a grep — CLAUDE.md records that happening four times in one '
            . 'day — so this reads the source with comments stripped by PHP\'s own tokenizer.'
        );
    }

    /**
     * Every PHP file under a root, with comments replaced by blank lines.
     *
     * @return array<string, string> path => source without comments
     */
    private function phpFilesUnder(string $root): array
    {
        $out = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $code = '';
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $code .= str_repeat("\n", substr_count($token[1], "\n"));

                    continue;
                }
                $code .= is_array($token) ? $token[1] : $token;
            }

            $out[$file->getPathname()] = $code;
        }

        return $out;
    }
}
