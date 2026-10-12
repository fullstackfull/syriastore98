<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Single source of truth for the tax component of a checkout.
 *
 * WHY THIS EXISTS
 * ---------------
 * The storefront checkout used to take the tax component of the charged total
 * straight from client input (`$requests_data['tax_price']`) at four separate
 * sites, with no Validator covering it. A customer could therefore set the tax
 * on their own order — including a NEGATIVE value, which both reduced the amount
 * actually charged and corrupted the ledger.
 *
 * This class is deliberately NOT a second tax engine. The platform already
 * computes tax server-side in two places and this resolver only ever reads
 * those existing results:
 *
 *   1. the cart total computation — `Cart::cart_list_cookie()` for guests and
 *      `ApiController::cart_list()` for authenticated customers, both of which
 *      return `total_tax_price`;
 *   2. the shipping-method computation — `CartController::get_shipping_method()`,
 *      which returns `final_tax_price` when the store's plan has
 *      `shipping_method = on` (the shipping charge is itself taxable, so that
 *      figure supersedes the cart one).
 *
 * The tax rules themselves (`TaxMethod` rows filtered by country/state/city,
 * `tax_rate * base / 100`, and the `round_tax` TaxOption) continue to live where
 * they always did, in `CartController::get_tax_data` and the cart list builders.
 * Nothing here duplicates them.
 */
class OrderTaxResolver
{
    /**
     * Resolve the server-authoritative tax for a checkout.
     *
     * @param  array       $cartlist           The server-computed cart payload
     *                                         (`total_tax_price` is the cart tax).
     * @param  array|null  $shippingMethodData The decoded body of
     *                                         `CartController::get_shipping_method()`,
     *                                         or null when the store has no
     *                                         shipping method engine enabled.
     * @return float The tax amount. Never negative, never client-supplied.
     */
    public function resolve(array $cartlist, ?array $shippingMethodData = null): float
    {
        // The shipping-method computation already includes the cart tax plus the
        // tax on the shipping charge, so when it is present it is the complete
        // figure and supersedes the cart-only one.
        if (is_array($shippingMethodData) && isset($shippingMethodData['final_tax_price'])) {
            return $this->sanitise($shippingMethodData['final_tax_price']);
        }

        // `total_tax_price` is what the cart builders return. Some call sites hand
        // in a cart payload that carries the same server-computed figure under
        // `tax_price` instead; both keys are SERVER-built cart output, neither is
        // request input.
        if (isset($cartlist['total_tax_price'])) {
            return $this->sanitise($cartlist['total_tax_price']);
        }

        return $this->sanitise($cartlist['tax_price'] ?? 0);
    }

    /**
     * Detect — and record — an attempt to supply tax from the client.
     *
     * The checkout form historically posted `tax_price`, and the active themes
     * may still do so, so a mismatch is not by itself proof of an attack: it is
     * also how a stale template shows up. Either way the client value is
     * discarded; this only makes the discrepancy visible in the log so the theme
     * enumeration (GATE H prerequisite) can be driven from real traffic.
     *
     * @param  mixed  $clientValue The value the client posted, if any.
     * @param  float  $serverValue The value {@see resolve()} returned.
     * @param  string $site        A `Class::method` marker for the log line.
     */
    public function recordClientSuppliedTax($clientValue, float $serverValue, string $site): void
    {
        if ($clientValue === null || $clientValue === '') {
            return;
        }

        if (!is_numeric($clientValue)) {
            Log::warning('Checkout: non-numeric client tax_price discarded', [
                'site' => $site,
                'server_tax_price' => $serverValue,
            ]);

            return;
        }

        // Compare at the money scale rather than with `==` on floats.
        if (OrderTotalReconciler::amountsMatch((string) $clientValue, (string) $serverValue)) {
            return;
        }

        Log::warning('Checkout: client-supplied tax_price discarded (server value used)', [
            'site' => $site,
            'client_tax_price' => (string) $clientValue,
            'server_tax_price' => (string) $serverValue,
        ]);
    }

    /**
     * Coerce a server-computed figure to a usable, non-negative amount.
     *
     * Tax is never negative. A negative figure here would mean a tax rule or a
     * cart builder has gone wrong, not that a discount applies — discounts are
     * `coupon_price`, a separate component.
     */
    private function sanitise($value): float
    {
        if (!is_numeric($value)) {
            return 0.0;
        }

        $amount = (float) $value;

        return $amount > 0 ? $amount : 0.0;
    }
}
