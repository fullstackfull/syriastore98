<?php

namespace Tests\Feature\Commerce;

use Tests\Support\Commerce\OrderWriteCensus;
use Tests\TestCase;

/**
 * E14 (ledger row P2-04-17), THE PAYMENT HALF — which order writers take a replay claim.
 * =============================================================================================
 *
 * REWRITTEN 2026-10-31 BECAUSE EVERY NUMBER IT USED TO ASSERT WAS WRONG, AND ITS SIX TESTS PASSED.
 *
 * The previous version reported "31 first-party controllers write an Order; 8 carry the replay
 * guard; 19 gateway controllers write an Order on a gateway return with no replay guard; 4 write
 * an order outside a gateway return". It named the nineteen in prose — Stripe, Razorpay, Paytabs,
 * Mollie, Paystack, Midtrans, Flutterwave, Cashfree, Coingate, Benefit, Aamarpay, Mercado,
 * Yookassa, Skrill, SSLCommerz, Paytr, Paytm, CyberSource, PaymentWall — and called that list
 * "THE FINDING". Measured against the parsed source and `php artisan route:list`:
 *
 *   **NONE OF THE NINETEEN WRITES A CUSTOMER ORDER.** All nineteen write `App\Models\PlanOrder`,
 *   a SaaS *plan* sale against `plan_orders`. The census's regex `Order::create\s*\(` has no left
 *   anchor, so it matched the substring inside `PlanOrder::create(`. Every live route on those
 *   nineteen controllers is a plan route (`plan-pay-with-*`, `plan-*-payment`,
 *   `plan.get.*.status`), and their storefront returns land on `route('store.payment.status')` —
 *   `PaymentController::getProductStatus`, which DOES claim. Two of the nineteen matched inside a
 *   docblock rather than code (`PaymentWallPaymentController:184`, `CyberSourceController:213`).
 *
 * Plan purchases are not unprotected, either; they have a DIFFERENT control. `config/payments.php`
 * ships a 26-provider `plan_activation` map of `env('PLAN_ACTIVATION_<X>', false)` — disabled by
 * default — and the controllers on it fail closed via `assertPlanActivationEnabled()`.
 * `PlanPaymentFailClosedTest` proves the refusal for fifteen of them. Crediting a gateway-return
 * *replay* guard with that work, or reporting its absence as a replay finding, confuses two
 * controls that defend two different things.
 *
 * The error ran in the direction that INVENTS a finding, and it hid two that are real:
 *
 *   1. THE GLOB WAS NON-RECURSIVE, so `app/Http/Controllers/Api/` was structurally invisible.
 *      Three live customer-order writers were never censused at all: `Api\ApiController::place_order`
 *      (:3821), `Api\ApiController::ordersave` (:9043) and
 *      `Api\DashboardController::createOrderViaApi` (:6595). The last is the route the shipped
 *      merchant Flutter app posts orders to.
 *
 *   2. THE GUARD TEST WAS FILE-SCOPED — `str_contains($source, 'GuardsGatewayReturn')` — so it
 *      credited an import rather than a call. `PayUPaymentController` imports the trait at :23 and
 *      uses it at :28, and never calls `claimGatewayReturn()`; it was counted among the eight
 *      guarded. `PaymentController` holds TWO order writers and only `getProductStatus` claims:
 *      `whatsapp` (:4956) is a second, live, UNAUTHENTICATED storefront order writer at
 *      `POST {storeSlug?}/whatsapp` and `POST {storeSlug?}/telegram`, and the file-level test
 *      reported it as protected.
 *
 * So this file now derives everything from `OrderWriteCensus`, which parses the tree: recursive,
 * comments stripped, class names resolved through each file's own `use` map (including the grouped
 * form that hid `NMIPayController`'s writer), and the claim question asked of the ENCLOSING METHOD.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * IT IS STILL A CENSUS AND NOT A FIX FOR THE GATEWAY ROWS, deliberately: the seven guarded gateway
 * returns are already protected, and nothing here has gateway credentials or a sandbox to drive a
 * real return with. What it is no longer is WRONG — and the two surfaces it uncovered are
 * first-party, reachable from this container, and tracked as findings rather than described in
 * prose.
 */
class GatewayReplayGuardCensusTest extends TestCase
{
    /** It is a gateway return, and the writing method takes the `Cache::add()` replay claim. */
    private const GUARDED = 'gateway-return/guarded';

    /** It is a gateway return and the writing method does NOT claim. A finding. */
    private const UNGUARDED = 'gateway-return/unguarded';

    /** A client API posts the order. No gateway return, and no idempotency control either. */
    private const CLIENT_API = 'client-api/no-idempotency-control';

    /** An authenticated merchant or the operator typing an order. */
    private const MERCHANT = 'merchant-or-operator';

    /** A public storefront writer that is not a gateway return. */
    private const PUBLIC_NON_GATEWAY = 'public-storefront/not-a-gateway-return';

    /** The parser sees a constructor call that is never persisted. */
    private const NOT_A_WRITE = 'not-a-write';

    /**
     * EVERY customer-order write site in `app/Http/Controllers`, keyed `file::method`, with the
     * bucket and the reason read off the writing method rather than the file name.
     *
     * @var array<string,array{0:string,1:string}>
     */
    private const SITES = [
        'Api/ApiController.php::place_order' => [self::CLIENT_API,
            'POST api/{slug}/place-order and place-order-guest, middleware custom.auth. The '
            . 'storefront API order writer. No gateway return is involved — the order is written '
            . 'first and payment follows — so a gateway-return claim is not the control; '
            . 'double-submit idempotency is. There is none.'],

        'Api/ApiController.php::ordersave' => [self::CLIENT_API,
            'The second storefront API writer, same shape and same absence of a control.'],

        'Api/DashboardController.php::createOrderViaApi' => [self::CLIENT_API,
            'POST api/admin/createOrderViaApi, middleware custom.auth — the prefix the shipped '
            . 'merchant Flutter app calls. A merchant posting an order from the admin app. '
            . 'AdminApiOrderStockOwnershipTest already proves it refuses another merchant\'s '
            . 'product; nothing makes a repeated post idempotent.'],

        'AuthorizeNetPaymnetController.php::getOrderPaymentStatus' => [self::GUARDED, 'Authorize.Net return.'],
        'CinetPayController.php::getProductStatus' => [self::GUARDED, 'CinetPay return.'],
        'EasebuzzController.php::getProductStatus' => [self::GUARDED, 'Easebuzz storefront return.'],
        'KhaltiPaymnetController.php::getOrderPaymentStatus' => [self::GUARDED, 'Khalti return.'],
        'NMIPayController.php::getNMIProductStatus' => [self::GUARDED,
            'NMI return. Its writer is reachable only through the grouped import at '
            . 'NMIPayController:12 — `use App\\Models\\{Store,...,Order,...}` — which the previous '
            . 'census could not read, so this row was missing from all three of its lists.'],
        'PaiementProPaymentController.php::getProductStatus' => [self::GUARDED, 'PaiementPro return.'],
        'PaymentController.php::getProductStatus' => [self::GUARDED,
            'route("store.payment.status") — the SHARED storefront return that the plan-only '
            . 'gateways also redirect to. The highest-traffic guarded path in the codebase.'],

        'PayUPaymentController.php::getProductStatus' => [self::UNGUARDED,
            'THE TRAIT IS INERT HERE. PayUPaymentController imports GuardsGatewayReturn (:23) and '
            . 'uses it (:28) but never calls claimGatewayReturn(), so the import protects nothing. '
            . 'It is also UNREACHABLE on the current route table — no route resolves to '
            . 'getProductStatus; the PayU routes are pay/{pay}, plan-pay-with-payu, '
            . 'plan-payu-payment/status/{plan_id} and store-payu-payment/status, and the last '
            . 'redirects to route("store.payment.status") rather than calling this method. '
            . 'Recorded in-repo as F94. Unreachable is why it is not urgent; unguarded is why it '
            . 'is not listed as protected.'],

        'PaymentController.php::whatsapp' => [self::PUBLIC_NON_GATEWAY,
            'POST {storeSlug?}/whatsapp and POST {storeSlug?}/telegram, middleware web + '
            . 'ActiveTheme + ThemeLanguage — NO auth. The order is written and the shopper then '
            . 'messages the merchant, so there is no gateway arrival to replay and a '
            . 'gateway-return claim is the wrong control. A repeated post is still a second order '
            . 'and a second stock movement. The previous census reported this writer as guarded '
            . 'because its sibling method in the same file claims.'],

        'OrderController.php::store' => [self::MERCHANT,
            'The merchant typing an order by hand, on a CSRF-protected form. No third-party '
            . 'arrival to replay.'],

        'OrderController.php::order_kanban_view' => [self::NOT_A_WRITE,
            '`(new OrderDataTable())->query(new Order())` — a query-builder seed that is never '
            . 'saved. The parser sees a constructor call; the method persists nothing.'],

        'PosController.php::store' => [self::MERCHANT, 'The till. An authenticated merchant at a point of sale.'],
    ];

    /** @return array<string,string> site key => bucket */
    private function measured(): array
    {
        $buckets = [];

        foreach (OrderWriteCensus::customerOrderSites() as $site) {
            $buckets[OrderWriteCensus::key($site)] = $site;
        }

        return $buckets;
    }

    public function test_every_customer_order_write_site_is_classified(): void
    {
        $undeclared = array_values(array_diff(array_keys($this->measured()), array_keys(self::SITES)));

        $this->assertSame(
            [],
            $undeclared,
            "A METHOD WRITES A CUSTOMER ORDER AND THIS CENSUS DOES NOT KNOW ABOUT IT.\n\n"
            . "Read the method and declare it with its reason:\n"
            . "  " . self::GUARDED . "            — a gateway return whose method calls claimGatewayReturn()\n"
            . "  " . self::UNGUARDED . "          — a gateway return whose method does not (a finding)\n"
            . "  " . self::CLIENT_API . " — an API client posts it\n"
            . "  " . self::MERCHANT . "                    — an authenticated merchant or the operator\n"
            . "  " . self::PUBLIC_NON_GATEWAY . "  — public, but no gateway arrival\n"
            . "  " . self::NOT_A_WRITE . "                            — constructed and never persisted\n\n"
            . "Do not pick a bucket to make this pass. E14's rule is 'no duplication of payments "
            . "or inventory movements', and the shape it exists to stop is a control that holds on "
            . "the path somebody read and not on the one the caller chose.\n  "
            . implode("\n  ", $undeclared)
        );
    }

    public function test_no_classification_outlives_the_method_it_names(): void
    {
        $stale = array_values(array_diff(array_keys(self::SITES), array_keys($this->measured())));

        $this->assertSame(
            [],
            $stale,
            'These sites are classified here and no longer write a customer order. Remove them — a '
            . "stale row is how the next undeclared writer hides.\n  " . implode("\n  ", $stale)
        );
    }

    /**
     * THE ASSERTION THE PREVIOUS VERSION DID NOT MAKE: the claim is checked PER METHOD.
     *
     * A file-scoped `str_contains` is what credited PayU's inert import and PaymentController's
     * unguarded second writer. Both directions are asserted, so wiring one or losing one fails.
     */
    public function test_the_guarded_set_is_exactly_the_methods_that_claim(): void
    {
        foreach (self::SITES as $key => [$bucket, $reason]) {
            [$file, $method] = explode('::', $key, 2);
            $claims = OrderWriteCensus::methodClaimsGatewayReturn($file, $method);

            if ($bucket === self::GUARDED) {
                $this->assertTrue(
                    $claims,
                    $key . ' is declared guarded and its method does not call claimGatewayReturn(). '
                    . 'An import is not a control. If the claim was removed, say why on the record '
                    . 'and move the row to ' . self::UNGUARDED . '.'
                );

                continue;
            }

            $this->assertFalse(
                $claims,
                $key . ' now DOES call claimGatewayReturn(). If a replay guard has been wired in, '
                . 'move the row to ' . self::GUARDED . ' so the remaining gap stays honest.'
            );
        }
    }

    public function test_the_measured_population_is_what_the_census_reports(): void
    {
        $buckets = [];

        foreach (self::SITES as [$bucket]) {
            $buckets[$bucket] = ($buckets[$bucket] ?? 0) + 1;
        }

        ksort($buckets);

        $this->assertSame(
            [
                // ksort order, so the keys read in the order the comparison makes.
                self::CLIENT_API => 3,
                self::GUARDED => 7,
                self::UNGUARDED => 1,
                self::MERCHANT => 2,
                self::NOT_A_WRITE => 1,
                self::PUBLIC_NON_GATEWAY => 1,
            ],
            $buckets,
            'E14 PAYMENT HALF, MEASURED 2026-10-31 — 15 customer-order write sites in 11 files. '
            . 'Seven gateway returns claim; one (PayU) imports the trait without calling it and is '
            . 'unreachable anyway; three client-API writers and one unauthenticated storefront '
            . 'writer have no idempotency control of any kind; two are merchant-typed; one is not '
            . 'a write. If a count moved, say which way and why.'
        );

        $this->assertCount(
            15,
            OrderWriteCensus::customerOrderSites(),
            'the whole first-party customer-order write population'
        );
    }

    /**
     * THE PLAN POPULATION IS SEPARATE, AND KEEPING IT SEPARATE IS THE WHOLE CORRECTION.
     *
     * `App\Models\PlanOrder` is a SaaS plan sale. Conflating it with a customer order is what
     * produced nineteen phantom findings, and F147 was the same confusion one round earlier.
     */
    public function test_plan_purchases_are_counted_separately_and_are_not_order_writers(): void
    {
        $planSites = OrderWriteCensus::planOrderSites();
        $planFiles = array_unique(array_column($planSites, 'file'));

        $this->assertGreaterThan(
            40,
            count($planFiles),
            'the SaaS plan-purchase population — around fifty controllers, each writing plan_orders'
        );

        foreach (array_keys(self::SITES) as $key) {
            [$file] = explode('::', $key, 2);

            $this->assertNotContains(
                $file,
                array_diff($planFiles, [
                    // These write BOTH, and the customer-order site is the one censused above.
                    'AuthorizeNetPaymnetController.php', 'CinetPayController.php',
                    'EasebuzzController.php', 'KhaltiPaymnetController.php',
                    'NMIPayController.php', 'PaiementProPaymentController.php',
                    'PayUPaymentController.php',
                ]),
                $file . ' writes only plan orders and is censused here as an order writer. That is '
                . 'the exact error this rewrite corrects.'
            );
        }

        $this->assertSame(
            [],
            array_values(array_filter(
                $planSites,
                fn ($site) => isset(self::SITES[OrderWriteCensus::key($site)])
                    && self::SITES[OrderWriteCensus::key($site)][0] === self::GUARDED
            )),
            'A plan-order write is declared as a guarded gateway-return order write. The plan '
            . 'control is the fail-closed plan_activation map in config/payments.php, proven by '
            . 'PlanPaymentFailClosedTest — not the gateway-return replay claim.'
        );
    }

    /**
     * THE DERIVATION'S OWN REGRESSION GUARD.
     *
     * Four mistakes produced four wrong numbers. Each is pinned here against real source, so a
     * future rewrite of the scanner cannot quietly reintroduce one. No fixtures: a synthetic file
     * can be made to pass while the real tree still hides a writer.
     */
    public function test_the_scanner_is_not_fooled_by_the_four_mistakes_that_produced_the_old_numbers(): void
    {
        $keys = array_keys($this->measured());

        // 1. RECURSION — app/Http/Controllers/Api/ was invisible to a non-recursive glob.
        $this->assertContains('Api/DashboardController.php::createOrderViaApi', $keys,
            'the scanner must walk subdirectories; three live writers live in Api/');
        $this->assertContains('Api/ApiController.php::place_order', $keys);

        // 2. GROUPED IMPORTS — NMI's writer is reachable only through `use App\Models\{...,Order,...}`.
        $this->assertContains('NMIPayController.php::getNMIProductStatus', $keys,
            'the scanner must resolve the grouped use form, or NMI\'s order writer disappears');

        // 3. CLASS RESOLUTION — PlanOrder must not be mistaken for Order.
        foreach (['StripePaymentController.php', 'RazorpayPaymentController.php', 'PaytabsController.php'] as $planOnly) {
            $this->assertSame(
                [],
                array_values(array_filter($keys, fn ($key) => str_starts_with($key, $planOnly . '::'))),
                $planOnly . ' writes only PlanOrder. An unanchored `Order::create(` match is what '
                . 'put it, and sixteen others like it, on a list of order writers.'
            );
        }

        // 4. COMMENTS, and PER-METHOD scope.
        $this->assertSame([], array_values(array_filter($keys,
            fn ($key) => str_starts_with($key, 'PaymentWallPaymentController.php::')
                || str_starts_with($key, 'CyberSourceController.php::'))),
            'both of these matched the old census inside a docblock, not in code');

        $this->assertTrue(
            OrderWriteCensus::methodClaimsGatewayReturn('PaymentController.php', 'getProductStatus'),
            'PaymentController::getProductStatus claims'
        );
        $this->assertFalse(
            OrderWriteCensus::methodClaimsGatewayReturn('PaymentController.php', 'whatsapp'),
            'and its sibling writer in the SAME FILE does not — which a file-scoped str_contains '
            . 'cannot express, and which is why whatsapp was reported as protected'
        );
    }

    /**
     * The guard must still do what the census credits it with, AND something must still call it.
     *
     * The previous version asserted only that the trait contains `Cache::add(`. A trait nothing
     * calls satisfies that forever — which is precisely PayU's state.
     */
    public function test_the_guard_still_takes_a_claim_and_is_still_called(): void
    {
        $trait = (string) file_get_contents(
            base_path('app/Http/Controllers/Concerns/GuardsGatewayReturn.php')
        );

        $this->assertStringContainsString('Cache::add(', $trait,
            'GuardsGatewayReturn no longer takes a claim, so every guarded row credits a call with '
            . 'protection it does not provide.');

        $this->assertStringContainsString('already claimed', $trait,
            'and it must still refuse a second arrival');

        $callers = array_values(array_filter(
            self::SITES,
            fn ($row) => $row[0] === self::GUARDED
        ));

        $this->assertNotEmpty($callers, 'and at least one order writer must actually call it');
    }

    /**
     * F216's durable half exists, and recording that it is NOT wired into any order writer keeps
     * two facts from being confused: a UNIQUE index being ready is not a path being protected.
     */
    public function test_the_durable_reference_exists_and_is_wired_into_no_order_writer_yet(): void
    {
        $this->assertFileExists(base_path('app/Services/GatewayReference.php'));

        foreach (array_keys(self::SITES) as $key) {
            [$file, $method] = explode('::', $key, 2);

            $body = (string) file_get_contents(base_path('app/Http/Controllers/' . $file));

            if (! str_contains($body, 'GatewayReference')) {
                continue;
            }

            $this->fail(
                $file . ' now references GatewayReference. If the durable claim has been wired in, '
                . 'this census must say which method and on which transactional path — the owner\'s '
                . 'rule is that a UNIQUE reference table protects nothing unless the writer invokes '
                . 'the claim on the actual transactional path.'
            );
        }
    }
}
