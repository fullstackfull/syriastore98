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
 * P4-F044 — PRESSING "CANCEL" AT THE GATEWAY WRITES AN ORDER AND TAKES THE STOCK.
 * =================================================================================================
 *
 * The Stripe, Sofort and Paymera initiations all send the shopper back to the same URL whether they
 * paid or cancelled — `route('store.payment.status', ...)` — and the cancel leg carries a marker,
 * `&return_type=cancel&payment_processor=<x>`. That marker is **written in eleven places and read in
 * zero**: `grep -rn 'return_type' app/` finds only assignments, and the single reader anywhere in
 * the repository is in the vendored `Campaigns` module, on a different surface.
 *
 * So a shopper who presses Back or Cancel on the hosted Stripe page lands on the generic storefront
 * order writer with their stored checkout state intact, and it writes the order.
 *
 * THE MONEY HALF IS ALREADY SAFE AND IS NOT WHAT THIS FIXES. `stripe`, `paypal`, `sofort` and
 * `paymera` are absent from `PaymentVerificationService::OFFLINE_PAYMENT_TYPES` and no verification
 * record exists on a cancel, so `resolveOrderPaymentStatus()` returns **Pending** and
 * `OrderObserver::created()` credits no wallet. The damage is everything else:
 *
 *   - a phantom Pending order in the merchant's list, for a payment that never happened;
 *   - an **uncompensated stock decrement** — the goods are off the shelf with nothing to claim them;
 *   - the cart emptied, so the shopper cannot simply try again;
 *   - and a consumed 48-hour gateway-return claim, which then refuses their genuine retry.
 *
 * NO TEST HAS EVER DRIVEN A CANCEL. `GatewayReturnTrustBoundaryTest`'s 38 cases drive forged,
 * replayed, overlapping and amount-manipulated **success** returns. The one `return_type` in all of
 * `tests/` is `'return_type' => 'success'`. The marker's whole point is a leg nobody tested.
 *
 * AND IT IS NOT A NEW DISCOVERY. The Phase 0 audit recorded it — *"The marker is written in 11
 * places and read in zero"*, rated P0 — with the remediation *"Read return_type"*. This is that
 * remediation.
 *
 * WHY "READ return_type" IS NOT THE WHOLE FIX, which is the correction an independent review made
 * to the finding: it closes Stripe, Sofort and Paymera only. PayPal's two URLs were **byte
 * identical** (`"return_url" => route(...)`, `"cancel_url" => route(...)`), so there was nothing to
 * read; its `cancel_url` had to carry the marker. Sofort's `cancel_url` had no marker either.
 *
 * AND THE REFUSAL MUST BE ON THE MARKER, NEVER ON ITS ABSENCE. A return carrying no marker at all
 * is PayPal's success leg and several other providers', and refusing it would contradict the
 * owner-recorded decision that an unverified return still writes a Pending order so a shopper who
 * may have been charged has a reconciliation path. `test_a_return_with_no_marker_still_writes_its
 * _pending_order()` is that control, and it is what forces the two URL fixes rather than a
 * stricter reader.
 */
class GatewayCancelReturnWritesNoOrderTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const OPENING_STOCK = 50;

    /** @return array{0: object, 1: Customer, 2: Product} */
    private function checkout(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'p4f044-plan-' . uniqid(), 'price' => 0, 'themes' => '[]',
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

        /* `getProductStatus` inserts a `cities` row from billing_info; without these FK parents it
           500s BEFORE the order write and every assertion below would be vacuous. */
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
            'first_name' => 'P4F044', 'last_name' => 'Shopper',
            'email' => 'p4f044-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);

        $product = Product::forceCreate([
            'name' => 'p4f044-' . uniqid(), 'slug' => 'p4f044-' . uniqid(),
            'product_stock' => self::OPENING_STOCK, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 100.00, 'sale_price' => 100.00,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);

        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
        ]);

        return [$store, $customer, $product];
    }

    private function state($store, Customer $customer, string $paymentType = 'stripe'): array
    {
        return [
            'slug' => $store->slug,
            'store_id' => $store->id,
            'customer_id' => $customer->id,
            'payment_type' => $paymentType,
            'shipping_final_price' => 0,
            'tax_price' => 0,
            'cartlist_final_price' => 100.00,
            'coupon_info' => null,
            'billing_info' => [
                'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.test',
                'billing_user_telephone' => '123', 'billing_address' => 'x',
                'billing_postecode' => '1', 'billing_country' => '101',
                'billing_state' => '1', 'billing_city' => '1', 'billing_company_name' => '',
                'delivery_address' => 'x', 'delivery_postcode' => '1',
                'delivery_country' => '101', 'delivery_state' => '1', 'delivery_city' => '1',
            ],
        ];
    }

    private function ordersIn($store): int
    {
        return (int) DB::table('orders')->where('store_id', $store->id)->count();
    }

    /** Read through the query builder: `Product` is Cachable and this runs under CACHE_STORE=array. */
    private function stockOf(Product $product): int
    {
        return (int) DB::table('products')->where('id', $product->id)->value('product_stock');
    }

    /**
     * SIGNED IN ON THE `customers` GUARD, because the handler reads the basket for the
     * authenticated shopper and answers "Cart is empty." without one — measured, and it made the
     * positive control fail while both cancel cases passed vacuously, which is exactly what a
     * positive control is for. A gateway return really does arrive with the storefront session
     * cookie, so this is the realistic shape and not a convenience.
     */
    private function returnTo($store, Customer $customer, string $query, string $paymentType = 'stripe')
    {
        return $this->actingAs($customer, 'customers')
            ->withSession(['request_data' => $this->state($store, $customer, $paymentType)])
            ->get('/' . $store->slug . '/get-payment-status' . $query);
    }

    /**
     * THE POSITIVE CONTROL, FIRST.
     *
     * Without it every refusal below is satisfied by a handler that writes no order for any reason
     * at all — a 500, a missing fixture, a guard firing early. It also catches the lazy fix: one
     * that "closes" this by refusing every return.
     */
    public function test_premise_a_success_return_writes_its_order_and_takes_the_stock(): void
    {
        [$store, $customer, $product] = $this->checkout();

        $r = $this->returnTo($store, $customer, '?return_type=success&payment_processor=stripe');

        $this->assertSame(1, $this->ordersIn($store),
            'the SUCCESS leg wrote no order, so this suite cannot tell a refusal from a broken '
            . 'fixture and every case below is vacuous. status=' . $r->getStatusCode()
            . ' error=' . var_export(session('error'), true));

        $this->assertSame(self::OPENING_STOCK - 1, $this->stockOf($product),
            'premise: a successful return moves the stock');
    }

    /**
     * THE DEFECT. The same request, one word different.
     */
    public function test_a_cancel_return_writes_no_order_and_takes_no_stock(): void
    {
        [$store, $customer, $product] = $this->checkout();

        $this->returnTo($store, $customer, '?return_type=cancel&payment_processor=stripe');

        $this->assertSame(0, $this->ordersIn($store),
            'a shopper who pressed Cancel on the hosted gateway page got an order. The marker '
            . '`return_type=cancel` is written in eleven places in this codebase and, before this '
            . 'change, read in none.');

        $this->assertSame(self::OPENING_STOCK, $this->stockOf($product),
            "the cancelled checkout took the shopper's goods off the shelf. There is no "
            . 'compensation on this path: the stock is simply gone, claimed by nothing.');
    }

    /**
     * AND THE BASKET SURVIVES, so the shopper can try again.
     *
     * Its own case rather than an extra assertion, because this is the half a shopper actually
     * notices: a cancelled payment that also empties the cart makes them rebuild it.
     */
    public function test_a_cancel_return_leaves_the_basket_alone(): void
    {
        [$store, $customer] = $this->checkout();

        $this->returnTo($store, $customer, '?return_type=cancel&payment_processor=stripe');

        $this->assertSame(
            1,
            (int) DB::table('carts')->where('customer_id', $customer->id)->count(),
            'the cancelled checkout emptied the basket, so the shopper has to rebuild it before '
            . 'they can retry'
        );
    }

    /**
     * THE NEGATIVE CONTROL THE FIX MUST NOT BREAK.
     *
     * A return carrying NO marker is PayPal's success leg and several other providers'. Refusing
     * it would contradict the owner-recorded decision that an unverified return still writes a
     * Pending order, so a shopper who may have been charged has a reconciliation path — and it is
     * precisely what forces PayPal and Sofort to be fixed at their `cancel_url` rather than here.
     */
    public function test_a_return_with_no_marker_still_writes_its_pending_order(): void
    {
        [$store, $customer] = $this->checkout();

        $this->returnTo($store, $customer, '', 'paypal');

        $this->assertSame(1, $this->ordersIn($store),
            'a return with no marker was refused. That is PayPal\'s success leg and the '
            . 'owner-recorded reconciliation path for every unverified gateway: refusing it takes '
            . 'those providers offline.');
    }

    /**
     * THE MARKER SURVIVES URL GENERATION — a premise for the reader, green before and after.
     *
     * `route('store.payment.status', [$slug, '&return_type=cancel&...'])` passes the marker as a
     * positional parameter, which is an unusual shape; this is what stops a future refactor
     * encoding it away where nothing would notice.
     */
    public function test_the_cancel_marker_survives_url_generation(): void
    {
        [$store] = $this->checkout();

        $url = route('store.payment.status', [
            $store->slug,
            '&return_type=cancel&payment_processor=stripe',
        ]);

        parse_str((string) parse_url(urldecode($url), PHP_URL_QUERY), $query);

        $this->assertSame('cancel', $query['return_type'] ?? null,
            'the cancel marker does not survive route() — the reader can never see it. Generated: '
            . $url);
    }

    /**
     * EVERY CANCEL URL THAT RETURNS TO THE STOREFRONT WRITER CARRIES THE MARKER.
     * =============================================================================================
     *
     * THIS CASE IS A REWRITE, AND THE REASON IS THE WHOLE POINT. Its first version asserted that
     * two literal spellings occurred ZERO times:
     *
     *     "cancel_url" => route('store.payment.status', $slug)      // double-quoted key, scalar
     *     'cancel_url' => route('store.payment.status', [$slug])    // array form, literal $slug
     *
     * An adversarial sweep measured what that actually covered, and the answer was: almost
     * nothing. It was blind to `'cancel_url' => route(..., $slug)` with a SINGLE-quoted key --
     * which is the exact shape PayFast had, one of the four providers this very suite was written
     * alongside, so the case could not have failed on it. It was blind to `[$store->slug]`, which
     * contains no `$slug` substring, and that is CoinGate -- a LIVE instance of the defect, whose
     * `cancel_url` was byte-identical to its `callback_url` and `success_url` and which does store
     * its checkout state, so a cancelling shopper got a phantom order, a stock decrement with
     * nothing to claim it, an emptied cart and a consumed 48-hour claim. And it was blind to
     * `$skill->cancel_url = ...`, a property assignment rather than an `=>` key.
     *
     * A NEGATIVE RATCHET CAN ONLY EVER FORBID THE SPELLINGS ITS AUTHOR IMAGINED. This version
     * asserts the property POSITIVELY: find every cancel URL that routes to the storefront return,
     * whatever its syntax, and require each one to carry the marker. A new provider written in a
     * shape nobody has thought of is caught by construction rather than missed by omission.
     *
     * AND IT HAS A POSITIVE CONTROL, which the first version also lacked: `file_get_contents()`
     * returns `false` for a moved file, `(string) false` is `''`, `token_get_all('')` is `[]`, and
     * both of the old counts were then 0 -- so the test passed having read nothing at all.
     */
    public function test_every_cancel_url_that_returns_to_the_storefront_writer_marks_itself(): void
    {
        $code = $this->codeOfPaymentService();

        /*
         * THE POSITIVE CONTROL. Without these three the case is satisfied by an empty string.
         */
        $this->assertNotSame('', $code,
            'PaymentService.php could not be read, so this case would pass having examined '
            . 'nothing. Re-read this test before trusting it.');

        $this->assertStringContainsString('function process(', $code,
            'the payment dispatcher has moved; this case is measuring the wrong file');

        /*
         * EVERY cancel URL, in any syntax: an array key (single or double quoted) or a property
         * assignment. The value is captured up to the closing parenthesis of the `route(` call,
         * across lines.
         */
        /*
         * THE ARGUMENT LIST IS EXTRACTED BY BALANCING PARENTHESES, not by a regex.
         *
         * A non-greedy `(.*?)\)` stops at the FIRST close paren, and two of these sites pass
         * `$return_url_parameters('cancel')` -- a nested call -- so a regex truncated them
         * mid-argument and reported them unmarked. Measured: it flagged Stripe and Sofort, both of
         * which are correctly marked. Balancing is the only way to read an argument list that can
         * contain calls.
         */
        $sites = $this->cancelUrlArgumentLists($code);

        $this->assertGreaterThanOrEqual(8, count($sites),
            'the sweep found only ' . count($sites) . ' cancel_url assignments. There were ten '
            . 'when this was written, so the scan has stopped matching shapes it used to -- '
            . 'which is exactly the failure mode this rewrite exists to prevent. Re-read it.');

        /*
         * THE CLOSURE FORM IS A MARKER, AND THE PREMISE IS ASSERTED RATHER THAN ASSUMED. Stripe
         * and Sofort build their marker through `$return_url_parameters($type)`, so the literal
         * `return_type=cancel` never appears at the call site. Accepting that spelling blindly
         * would be a hole; this proves the closure really emits it.
         */
        $this->assertMatchesRegularExpression(
            '/\$return_url_parameters\s*=\s*function\s*\(\$return_type\)\s*\{\s*return\s+\'&return_type=\'\s*\.\s*\$return_type/',
            $code,
            'the $return_url_parameters closure no longer builds `&return_type=<type>`, so the '
            . 'sites that call it can no longer be treated as marked. Re-read this test.'
        );

        $unmarked = [];

        foreach ($sites as $args) {

            /*
             * ONLY the ones that come back to the generic storefront writer. A cancel URL pointing
             * at `checkout`, at `nepalste.cancel` or anywhere else never reaches the reader in
             * `getProductStatus`, so the marker would mean nothing there.
             */
            if (! str_contains($args, 'store.payment.status')) {
                continue;
            }

            /*
             * An encrypted payload is its own marker: `Crypt::encrypt(['response' => 'cancel'])`
             * carries the intent in the route parameter instead of the query string. Accepted
             * explicitly rather than by accident, and named so a reader knows it was considered.
             */
            if (str_contains($args, "'response' => 'cancel'")) {
                continue;
            }

            /* The closure form, proven above to emit `&return_type=cancel`. */
            if (str_contains($args, "\$return_url_parameters('cancel')")) {
                continue;
            }

            if (! str_contains($args, 'return_type=cancel')) {
                $unmarked[] = trim(preg_replace('/\s+/', ' ', $args));
            }
        }

        $this->assertSame([], $unmarked,
            "A cancel URL returns to the storefront order writer carrying no `return_type=cancel`, "
            . "so a shopper who cancels there is indistinguishable from one who paid: the reader in "
            . "getProductStatus cannot fire, and the generic writer writes a phantom Pending order, "
            . "decrements stock with nothing to claim it, empties the cart and consumes the 48-hour "
            . "return claim.\n  " . implode("\n  ", $unmarked));
    }

    /**
     * Every `cancel_url` assignment's `route(...)` argument list, read by balancing parentheses so
     * a nested call cannot truncate it. Any syntax: a single- or double-quoted array key, or a
     * property assignment.
     *
     * @return array<int, string>
     */
    private function cancelUrlArgumentLists(string $code): array
    {
        $lists = [];
        $offset = 0;

        while (preg_match(
            '/(?:[\'"]cancel_url[\'"]\s*=>|->cancel_url\s*=)\s*route\(/',
            $code,
            $m,
            PREG_OFFSET_CAPTURE,
            $offset
        ) === 1) {
            $open = $m[0][1] + strlen($m[0][0]) - 1;
            $depth = 0;
            $end = $open;

            for ($i = $open; $i < strlen($code); $i++) {
                if ($code[$i] === '(') {
                    $depth++;
                } elseif ($code[$i] === ')') {
                    $depth--;

                    if ($depth === 0) {
                        $end = $i;
                        break;
                    }
                }
            }

            $lists[] = substr($code, $open + 1, $end - $open - 1);
            $offset = $end + 1;
        }

        return $lists;
    }

    /** PaymentService.php with comments stripped, so prose cannot satisfy or defeat a match. */
    private function codeOfPaymentService(): string
    {
        $absolute = base_path('app/Services/PaymentService.php');

        if (! is_file($absolute)) {
            return '';
        }

        $out = '';

        foreach (token_get_all((string) file_get_contents($absolute)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }

        return $out;
    }
}
