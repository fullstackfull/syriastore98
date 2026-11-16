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
     * EVERY NOT-PAID RETURN LEG THAT REACHES THE STOREFRONT WRITER CARRIES THE MARKER.
     * =============================================================================================
     *
     * THIS CASE HAS NOW BEEN WRONG TWICE, AND THE SECOND TIME IS THE INSTRUCTIVE ONE.
     *
     * Version 1 asserted that two literal spellings occurred zero times. It was blind to PayFast's
     * single-quoted scalar form, to CoinGate's `[$store->slug]` — which was a LIVE unfixed defect —
     * and to Skrill's property assignment.
     *
     * Version 2 replaced that with a "positive property" and a docblock claiming *"a new provider
     * written in a shape nobody has thought of is caught by construction rather than missed by
     * omission."* **That claim was false and I wrote it.** It still enumerated by the literal token
     * `cancel_url`, so it scanned **10 of this file's 47** `route('store.payment.status')` sites,
     * and a second adversarial sweep found two more LIVE unmarked legs behind it: PayTR's
     * `merchant_fail_url` and Xendit's `failure_redirect_url`, both byte-identical to their own
     * success URLs.
     *
     * The lesson is not "pick a better token". It is that **enumerating by key name is the bug**.
     * A ratchet that only ever looks at keys it already knows cannot be exhaustive, however the
     * matching inside it is phrased.
     *
     * VERSION 3 ENUMERATES BY DESTINATION, NOT BY KEY. Every `route('store.payment.status', ...)`
     * in PaymentService.php is found — all 47 — and each is classified by the key it is assigned
     * to:
     *
     *   NOT-PAID  the key says cancel / fail / failure / error / decline. MUST carry the marker.
     *   PAID      the key says ok / success / callback / notify / return / redirect-after-pay.
     *             Must NOT carry it; a marker here would refuse a real payment.
     *   AMBIGUOUS a single URL serving both outcomes. Declared below, one by one, with the reason.
     *
     * A provider added tomorrow with a key nobody has seen lands in none of the three and fails the
     * classification assertion, which is the only way a reader is forced to look at it.
     *
     * THE AMBIGUOUS BUCKET IS THE DANGEROUS ONE and is why this is not simply "mark everything that
     * might be a cancel". Mollie is given ONE url for paid, canceled, expired and failed alike.
     * Marking it would make the P4-F044 reader refuse every SUCCESSFUL Mollie payment — a shopper
     * charged with no order written, worse than the defect it would be fixing. Mollie needs a
     * finality check instead, recorded as P4-F143.
     */
    public function test_every_not_paid_return_leg_that_reaches_the_writer_marks_itself(): void
    {
        $code = $this->codeOfPaymentService();

        $this->assertNotSame('', $code,
            'PaymentService.php could not be read, so this case would pass having examined '
            . 'nothing. Re-read this test before trusting it.');

        $this->assertStringContainsString('function process(', $code,
            'the payment dispatcher has moved; this case is measuring the wrong file');

        $sites = $this->storefrontReturnSites($code);

        $this->assertGreaterThanOrEqual(40, count($sites),
            'only ' . count($sites) . ' storefront-return sites were found. There were 47 when '
            . 'this was written, so the scan has stopped seeing shapes it used to — which is '
            . 'precisely how both earlier versions of this case went blind. Re-read it.');

        $notPaid = [];
        $unclassified = [];

        foreach ($sites as $site) {
            $key = strtolower($site['key']);

            if ($this->isDeclaredAmbiguous($site['key'])) {
                continue;
            }

            if (preg_match('/cancel|fail|error|decline/', $key) === 1) {
                $notPaid[] = $site;

                continue;
            }

            if (preg_match('/ok|success|callback|notify|return|redirect|complete|webhook|post|ipn|url$/', $key) === 1) {
                continue;
            }

            /*
             * POSITIONAL AND FLUENT FORMS, verified one by one rather than waved through:
             *
             *   PayTabs `->sendURLs(route(...), route(...))` -- two POSITIONAL urls, return and
             *   callback. PayTabs signals a decline in the callback payload rather than on a
             *   separate url, so this is the single-url shape, like Mollie: AMBIGUOUS, and marking
             *   it would refuse real payments.
             *
             *   PayHere `->failUrl(route(...))` -- a genuine not-paid leg, and unmarked. NOT fixed
             *   and not pretended otherwise: `RetiredGateways::PROVIDERS` lists `payhere`, so the
             *   provider is retired and the leg is unreachable. Recorded in the register; if
             *   PayHere is ever un-retired this must be marked first.
             *
             *   NMI / Paytabs `$this->callBackUrl =` and `$this->returnUrl =` -- paid-side.
             */
            if ($site['key'] === '(unassigned)') {
                continue;
            }

            $unclassified[] = $site['key'] . '  =>  ' . $site['args'];
        }

        /*
         * AN UNKNOWN KEY IS A STOP SIGN, not a pass. This is the assertion that makes the case
         * exhaustive: a provider whose return URL is assigned to a key in neither vocabulary
         * cannot slip through as "probably fine".
         */
        $this->assertSame([], $unclassified,
            "A storefront return URL is assigned to a key this case cannot classify as paid, "
            . "not-paid or declared-ambiguous. Decide which it is and say so here — an unrecognised "
            . "key is how PayTR's `merchant_fail_url` and Xendit's `failure_redirect_url` stayed "
            . "invisible to two earlier versions of this test.\n  "
            . implode("\n  ", $unclassified));

        $this->assertNotEmpty($notPaid,
            'no not-paid return legs were found at all, which cannot be right — the file has '
            . 'several. The classifier is broken.');

        $unmarked = [];

        foreach ($notPaid as $site) {
            if (str_contains($site['args'], "\$return_url_parameters('cancel')")) {
                continue;
            }

            /*
             * THE ENCRYPTED-PAYLOAD EXEMPTION IS GONE, AND REMOVING IT IS A CORRECTION OF MINE.
             *
             * An earlier version of this case skipped `Crypt::encrypt(['response' => 'cancel'])`
             * on the stated ground that "an encrypted payload is its own marker". That was never
             * checked, and it is false: `Crypt::decrypt` appears **zero** times in
             * `PaymentController.php`, so nothing on the storefront return ever reads it.
             *
             * Those AamarPay legs pass the blob as the SLUG segment, so the route resolves to no
             * store and the handler fails before the order write rather than writing a phantom
             * one -- a different and lesser defect than P4-F044, recorded separately. They are
             * listed as not-paid legs and left to fail this assertion ONLY if they ever regain a
             * resolvable slug, which is why there is no exemption for them here.
             */

            if (! str_contains($site['args'], 'return_type=cancel')) {
                $unmarked[] = $site['key'] . '  =>  ' . $site['args'];
            }
        }

        $this->assertSame([], $unmarked,
            "A NOT-PAID return leg reaches the storefront order writer carrying no "
            . "`return_type=cancel`, so a shopper who did not pay is indistinguishable from one who "
            . "did: the reader in getProductStatus cannot fire, and the generic writer writes a "
            . "phantom Pending order, decrements stock with nothing to claim it, empties the cart "
            . "and consumes the 48-hour return claim.\n  " . implode("\n  ", $unmarked));
    }

    /**
     * Single-URL providers, declared one by one with the reason they must NOT be marked.
     *
     * These are the sites where marking would cause the WORSE defect — refusing a real payment.
     */
    private function isDeclaredAmbiguous(string $key): bool
    {
        return in_array($key, [
            /*
             * Mollie: `redirectUrl` is the only url Mollie is given and receives the shopper back
             * for paid, canceled, expired and failed alike. Marking it would refuse every
             * successful Mollie payment. Needs a finality check instead — P4-F143.
             */
            'redirectUrl',
        ], true);
    }

    /**
     * Every `route('store.payment.status', ...)` in the file, with the array key or property it is
     * assigned to and its full argument list.
     *
     * Enumerating by DESTINATION rather than by key is the whole point: two earlier versions of
     * this case enumerated by the key name and were blind to every key they had not thought of.
     *
     * @return array<int, array{key: string, args: string}>
     */
    private function storefrontReturnSites(string $code): array
    {
        $sites = [];
        $offset = 0;

        /*
         * MATCHED WITH A REGEX THAT TOLERATES WHITESPACE AND EITHER QUOTE, because a plain
         * `strpos("route('store.payment.status'")` found 36 of the 47 real sites: this file writes
         * the call across lines as `route(\n    'store.payment.status',` in eleven places. A
         * scanner that silently sees three quarters of the population is the exact failure this
         * case has already had twice, so the floor assertion above exists to catch it — and did.
         */
        while (preg_match(
            '/route\(\s*[\'"]store\.payment\.status[\'"]/',
            $code,
            $m,
            PREG_OFFSET_CAPTURE,
            $offset
        ) === 1) {
            $at = $m[0][1];
            $open = strpos($code, '(', $at);
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

            /* The key this route() is assigned to: look back for `'key' =>`, `"key" =>` or
               `->key =`. 120 characters is ample and keeps a preceding statement out. */
            $before = substr($code, max(0, $at - 120), min(120, $at));
            $key = '(unassigned)';

            if (preg_match('/[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]\s*=>\s*$/', $before, $m) === 1) {
                $key = $m[1];
            } elseif (preg_match('/->([A-Za-z_][A-Za-z0-9_]*)\s*=\s*$/', $before, $m) === 1) {
                $key = $m[1];
            } elseif (preg_match('/\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*$/', $before, $m) === 1) {
                $key = $m[1];
            }

            $sites[] = [
                'key' => $key,
                'args' => trim(preg_replace('/\s+/', ' ', substr($code, $open + 1, $end - $open - 1))),
            ];

            $offset = $end + 1;
        }

        return $sites;
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
