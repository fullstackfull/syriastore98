<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;

/**
 * Every host GET route whose handler WRITES is declared here, or this test fails.
 * =============================================================================================
 *
 * WHY A RATCHET AND NOT A RULE. "No state-changing GET" cannot be enforced outright: dozens of
 * these are **payment gateway callbacks**, where the gateway chooses the verb by redirecting a
 * customer's browser back to a url. Changing those breaks taking money. So the rule here is the
 * only enforceable one: *the set does not grow*.
 *
 * THIS EXISTS BECAUSE THE SWEEPS KEPT MISSING ONE. F12 found `change-store`. The fix for it was
 * keyed on that one route, so F14 found `store/change-status` one screen up the same controller.
 * The P2-05 sweep then found `customer-status`, `product-brand-status`,
 * `product-brand-popular` and `product-label-status` -- and the last three turned out to have no
 * permission check and no tenant predicate either, while their Flutter siblings had been fixed
 * long before. Four rounds, each keyed on the surface somebody happened to be looking at.
 *
 * KEYED ON THE ACTION AND ON THE HANDLER'S SOURCE, never on the uri's words. A census keyed on
 * uri spelling has produced a defect in this project four separate times. The scan:
 *
 *   1. walks the real router, so a route declared anywhere is seen;
 *   2. keeps only `App\Http\Controllers\*` actions -- vendored module handlers are out of scope
 *      and out of bounds to edit;
 *   3. reads the handler METHOD'S OWN SOURCE, comment-stripped, for a write;
 *   4. compares the result to the declared set below in BOTH directions.
 *
 * BOTH DIRECTIONS MATTERS. A new state-changing GET fails because it is undeclared. A declared
 * one that has been fixed ALSO fails, because leaving a stale row in a ratchet is how a ratchet
 * stops meaning anything. Removing a row is the record that the finding was addressed.
 *
 * WHAT IT DOES NOT CLAIM. It does not say the declared routes are safe; several are, because
 * their handlers carry their own ownership checks. It says the list of places to look has not
 * grown. And `$writePatterns` is a source heuristic: a write reached through a helper, a job or
 * an event listener is invisible to it, so a route can be state-changing without appearing
 * here. It is a floor, not a proof.
 */
class StateChangingGetInventoryTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** Source patterns that mean "this method writes". */
    private const WRITE_PATTERNS = [
        '/->save\s*\(/',
        '/->update\s*\(/',
        '/->delete\s*\(/',
        '/->forceDelete\s*\(/',
        '/::create\s*\(/',
        '/->insert\s*\(/',
        '/->increment\s*\(/',
        '/->decrement\s*\(/',
        '/->updateOrCreate\s*\(/',
        '/->firstOrCreate\s*\(/',
        '/->truncate\s*\(/',
    ];

    /**
     * The declared set, as `uri => why it is still a GET`.
     *
     * MEASURED, not transcribed: produced by the scan below and then classified by reading each
     * handler. Four groups, and the group is the whole point of the comment on each row.
     *
     * @var array<string, string>
     */
    private const DECLARED = [
        // ---------------------------------------------------------------------------------
        // 1. PAYMENT GATEWAY CALLBACKS. The gateway redirects the customer's browser here, so
        //    the verb is the gateway's to choose and not ours. Cannot be changed without
        //    breaking payment capture, which is an owner decision and a money path.
        // ---------------------------------------------------------------------------------
        'aamarpay/success/{data}' => 'gateway callback',
        '{storeSlug?}/authorizenet-status' => 'gateway callback',
        'plan-Benefit-payment' => 'gateway callback',
        'plan-pay-with-Benefit' => 'gateway callback',
        'cashfree/payments/success' => 'gateway callback',
        '{storeSlug?}/get-payment-cinetPay' => 'gateway callback',
        'plan-cinetpay-payment-return' => 'gateway callback',
        'plan-coingate-payment' => 'gateway callback',
        'plan-get-cybersource-status' => 'gateway callback',
        'plan-get-DPO/{plan_id}' => 'gateway callback',
        'plan-pay-with-esewa' => 'gateway callback',
        '{storeSlug?}/get-payment-easebuzz' => 'gateway callback',
        'plan-easebuzz-payment-notify' => 'gateway callback',
        'plan-get-fedpay-status' => 'gateway callback',
        'plan-flaterwave-payment/{txref}/{plan_id}' => 'gateway callback',
        'plan-mercado-payment/{plan}' => 'gateway callback',
        'plan-midtrans-payment' => 'gateway callback',
        'plan-mollie-payment/{plan}' => 'gateway callback',
        'myfatoorah/call_back' => 'gateway callback',
        'myfatoorah/store_call_back' => 'gateway callback',
        '{storeSlug?}/get-payment-NMI' => 'gateway callback',
        'nepalste/status' => 'gateway callback',
        'plan-get-ozow-status/{plan_id}' => 'gateway callback',
        'plan-get-paddle-status' => 'gateway callback',
        '{storeSlug?}/get-payment-paiementpro' => 'gateway callback',
        'plan-get-paiementpro-status' => 'gateway callback',
        'plan-payfast-payment/{success}' => 'gateway callback',
        'plan-get-payhere-status/{plan_id}' => 'gateway callback',
        'plan-payu-payment/status/{plan_id}' => 'gateway callback',
        '{storeSlug?}/get-payment-status' => 'gateway callback',
        'plan-get-Paynow-status/{plan_id}' => 'gateway callback',
        '{id}/{amount}/plan-paypal-payment' => 'gateway callback',
        'plan-paystack-payment/{pay_id}/{plan_id}' => 'gateway callback',
        'plan-paytabs-payment' => 'gateway callback',
        'plan-paytr-payment/success' => 'gateway callback',
        'plan-get-phonepe-status' => 'gateway callback',
        'plan-get-Powertranz-status/{plan_id}' => 'gateway callback',
        'plan-razorpay-payment/{txref}/{plan_id}' => 'gateway callback',
        'plan/sslcommerz/status/{plan_id}' => 'gateway callback',
        'senang-pay/call_back' => 'gateway callback',
        'plan-skrill-payment/{plan}' => 'gateway callback',
        'plan-pay-with-sofort' => 'gateway callback',
        'plan-sspay-payment/{plan_id}/{amount}/{couponCode}' => 'gateway callback',
        'plan-get-tap-status' => 'gateway callback',
        'plan-toyyibpay-payment/{plan_id}/{amount}/{couponCode}' => 'gateway callback',
        'plan-pay-with-Xendit' => 'gateway callback',
        'plan-xendit-payment/status' => 'gateway callback',
        'plan-yookassa-payment/success' => 'gateway callback',

        // ---------------------------------------------------------------------------------
        // 2. API ROUTES THE SHIPPED FLUTTER APP CALLS. Changing a verb here breaks a released
        //    client. Public API compatibility is an owner decision.
        // ---------------------------------------------------------------------------------
        'api/admin/product-brand-popular' => 'Flutter API; verb change breaks a shipped client',
        'api/admin/product-brand-status' => 'Flutter API; verb change breaks a shipped client',
        'api/admin/product-label-status' => 'Flutter API; verb change breaks a shipped client',
        'api/admin/get-currency-rates' => 'Flutter API; caches fetched rates',
        'api/admin/notifications/mark-all-as-read' => 'Flutter API (delivery boy); verb change breaks a shipped client',
        'api/admin/wallet/transactions' => 'Flutter API; money path',

        // ---------------------------------------------------------------------------------
        // 3. MONEY AND SUBSCRIPTION PATHS. Converting these is a behaviour change on a money
        //    path, which this phase is explicitly not authorised to make. RECORDED SO THEY ARE
        //    NOT MISTAKEN FOR CLEARED: `refund` and `order_approve`/`order_reject` are the
        //    sharpest of them -- a refund and a bank-transfer approval, both by GET.
        // ---------------------------------------------------------------------------------
        'refund/{id}/{user_id}' => 'MONEY: issues a plan refund on a GET -- owner decision',
        'order_approve/{id}' => 'MONEY: approves a bank-transfer order on a GET -- owner decision',
        'order_reject/{id}' => 'MONEY: rejects a bank-transfer order on a GET -- owner decision',
        'store-plan-active/{id}/plan/{pid}' => 'subscription state on a GET -- owner decision',
        'plan-trial/{id}' => 'subscription state on a GET -- owner decision',
        'request-response/{id}/{response}' => 'plan request workflow on a GET -- owner decision',
        'request-cancel/{id}' => 'plan request workflow on a GET -- owner decision',
        'request-send/{id}' => 'plan request workflow on a GET -- owner decision',

        // ---------------------------------------------------------------------------------
        // 4. STOREFRONT AND MISCELLANEOUS, each needing its own caller sweep before conversion.
        // ---------------------------------------------------------------------------------
        '{storeSlug?}/delete-addressbook' => 'storefront; shopper-facing, own caller sweep needed',
        '{storeSlug?}/delete-wishlist' => 'storefront; shopper-facing, own caller sweep needed',
        '{storeSlug?}/destroy-ticket/{eid}' => 'storefront; shopper-facing, own caller sweep needed',
        'change-language/{lang}' => 'writes the caller\'s OWN language preference only',
        'change-languages/{lang}' => 'writes the caller\'s OWN language preference only',
        'update-whatsapp-notification' => 'settings toggle; own caller sweep needed',
        'user-enable-login/{id}' => 'staff login toggle; own caller sweep needed',
        'finance' => 'index page that lazily backfills rows it reads',
        'shopify_category/{shopify_category}/edit' => 'integration sync inside a show/edit',
        'shopify_category/{shopify_category}' => 'integration sync inside a show/edit',
        'shopify_coupon/{shopify_coupon}/edit' => 'integration sync inside a show/edit',
        'shopify_coupon/{shopify_coupon}' => 'integration sync inside a show/edit',
        'shopify_customer/{shopify_customer}/edit' => 'integration sync inside a show/edit',
        'shopify_customer/{shopify_customer}' => 'integration sync inside a show/edit',
        'shopify_product/{shopify_product}/edit' => 'integration sync inside a show/edit',
        'shopify_product/{shopify_product}' => 'integration sync inside a show/edit',
        'woocom_category/{woocom_category}/edit' => 'integration sync inside a show/edit',
        'woocom_category/{woocom_category}' => 'integration sync inside a show/edit',
        'woocom_coupon/{woocom_coupon}/edit' => 'integration sync inside a show/edit',
        'woocom_coupon/{woocom_coupon}' => 'integration sync inside a show/edit',
        'woocom_customer/{woocom_customer}/edit' => 'integration sync inside a show/edit',
        'woocom_customer/{woocom_customer}' => 'integration sync inside a show/edit',
        'woocom_product/{woocom_product}/edit' => 'integration sync inside a show/edit',
        'woocom_product/{woocom_product}' => 'integration sync inside a show/edit',
    ];

    /**
     * The actual set, read from the router and the handlers' own source.
     *
     * @return array<string, string> uri => action
     */
    private function scan(): array
    {
        $found = [];

        foreach (\Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $action = $route->getActionName();

            if (! str_contains($action, '@')) {
                continue;
            }

            [$class, $method] = explode('@', $action, 2);

            // HOST controllers only. A vendored module handler is out of this phase's scope and
            // `packages/workdo/` must not be edited, so flagging one would be a permanent red.
            if (! str_starts_with($class, 'App\\Http\\Controllers\\') || ! class_exists($class)) {
                continue;
            }

            try {
                $reflection = new ReflectionMethod($class, $method);
            } catch (\Throwable $e) {
                continue;
            }

            $file = $reflection->getFileName();

            if (! $file || ! is_readable($file)) {
                continue;
            }

            $lines = file($file);
            $body = implode('', array_slice(
                $lines,
                $reflection->getStartLine() - 1,
                $reflection->getEndLine() - $reflection->getStartLine() + 1
            ));

            // COMMENT-STRIPPED. Several of these handlers now carry long notes that quote
            // `->save()` while explaining why they are safe, and a ratchet a docblock can
            // satisfy is not a ratchet. That has happened in this project before.
            $body = (string) preg_replace('~/\*.*?\*/~s', '', $body);
            $body = (string) preg_replace('~//[^\n]*~', '', $body);

            foreach (self::WRITE_PATTERNS as $pattern) {
                if (preg_match($pattern, $body)) {
                    $found[$route->uri()] = str_replace('App\\Http\\Controllers\\', '', $action);
                    break;
                }
            }
        }

        return $found;
    }

    public function test_the_scan_is_reachable_and_finds_something(): void
    {
        $found = $this->scan();

        /*
         * PREMISE, and not a formality. If the router were empty, a controller namespace
         * renamed, or `WRITE_PATTERNS` broken, the scan would return [] and the two directional
         * assertions below would BOTH pass -- a green test measuring nothing. That is the
         * vacuous shape this project keeps catching, so the floor is asserted explicitly.
         */
        $this->assertGreaterThan(
            50,
            count($found),
            'the scan found only ' . count($found) . ' state-changing GET routes. It found 90 '
            . 'when written, and the payment callbacks alone are ~48, so a number this low '
            . 'means the scan itself is broken -- fix the scan, not the expectation'
        );
    }

    /**
     * NO NEW ONE. This is the assertion the whole file exists for.
     */
    public function test_no_undeclared_host_get_route_writes(): void
    {
        $found = $this->scan();
        $undeclared = array_diff_key($found, self::DECLARED);

        $message = "These host GET routes have a handler that WRITES and are not declared in\n"
            . "StateChangingGetInventoryTest::DECLARED:\n\n";

        foreach ($undeclared as $uri => $action) {
            $message .= "  GET {$uri}\n      {$action}\n";
        }

        $message .= "\nA GET that writes is reachable from a third-party <img src> with no token "
            . "and no click, and\nby link prefetching. Make it a POST and update EVERY caller "
            . "(census them by route NAME across\nall views -- the F14 finding on this phase's "
            . "ledger is a sweep that fixed one caller and missed\nanother in the same feature). "
            . "If it genuinely cannot change -- a gateway callback, an API the\nshipped app "
            . "calls, or a money path needing owner approval -- add it above WITH THE REASON.";

        $this->assertSame([], $undeclared, $message);
    }

    /**
     * AND NO STALE ONE. A declared route that no longer writes on a GET must be removed from the
     * list, because a ratchet carrying rows that are no longer true stops being read.
     */
    public function test_every_declared_route_is_still_a_state_changing_get(): void
    {
        $found = $this->scan();
        $stale = array_diff_key(self::DECLARED, $found);

        $this->assertSame([], $stale, sprintf(
            "These routes are declared as state-changing GETs but the scan no longer finds "
            . "them:\n\n  %s\n\nIf they were fixed, delete their rows -- removing a row is the "
            . "record that the finding was addressed. If a uri was merely RENAMED, the fix is to "
            . "rename it here too, and to check the new uri appears in the scan.",
            implode("\n  ", array_keys($stale))
        ));
    }

    /**
     * THE FOUR ROUTES P2-05-1 CONVERTED ARE GONE FROM THE SCAN, named individually.
     *
     * The directional assertions above would pass if these four were simply added to DECLARED
     * instead of fixed. This says they were fixed.
     */
    public function test_the_four_routes_this_slice_converted_no_longer_answer_a_get(): void
    {
        $found = $this->scan();

        foreach ([
            'customer-status' => 'update.customer.status',
            'product-brand-status' => 'product-brand.status',
            'product-brand-popular' => 'product-brand.popular',
            'product-label-status' => 'product-label.status',
        ] as $uri => $name) {
            $this->assertArrayNotHasKey($uri, $found,
                "GET {$uri} writes again -- P2-05-1 made it a POST");

            // PREMISE: the route still EXISTS under that name, so the absence above is the verb
            // having changed and not the route having been deleted or renamed.
            $route = \Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "the route {$name} has disappeared");
            $this->assertContains('POST', $route->methods(), "{$name} must accept POST");
            $this->assertNotContains('GET', $route->methods(), "{$name} still accepts GET");
        }
    }
}
