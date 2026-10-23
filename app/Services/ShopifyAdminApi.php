<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * ONE SHOPIFY ADMIN API READ, WITH THE REDIRECT CLOSED.  P1-SEC-06 acceptance 3.
 * =================================================================================================
 *
 * WHAT THIS ROW'S FIRST PASS CLOSED, AND WHAT IT LEFT OPEN. `ImportStoreUrl::shopifyLabel()` made the
 * stored shop name a bare DNS label, so `169.254.169.254#` can no longer be stored and reached -- a
 * `#`, `/` or `?` used to end the host and comment the `.myshopify.com` suffix out of it. That closed
 * the FIRST hop. It did not close the SECOND: all thirteen call sites set
 *
 *     CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 10, CURLOPT_TIMEOUT => 0
 *
 * with no CURLOPT_REDIR_PROTOCOLS, so a response from a perfectly valid `*.myshopify.com` host could
 * still walk the platform's own server to `169.254.169.254` across up to ten hops, with no deadline.
 * `CURLOPT_REDIR_PROTOCOLS` does not help -- https to a private address is still https.
 *
 * AND THE REDIRECT CARRIES THE TOKEN. libcurl forwards `CURLOPT_HTTPHEADER` on a followed redirect, so
 * every one of those hops was sent `X-Shopify-Access-Token: <the merchant's token>`. A redirect to an
 * attacker-controlled host was therefore a credential leak as well as an SSRF, which is why refusing
 * the redirect is worth more here than re-validating each hop would be.
 *
 * WHY REFUSE RATHER THAN FOLLOW-AND-RE-CHECK. `SafeRemoteFetch::send()` follows redirects itself,
 * validating every hop, and that is the right design for fetching a merchant's IMAGE from an address
 * the merchant chose. It is the wrong design here: this is a JSON API on a FIXED host under a fixed
 * suffix, and the Shopify Admin API does not answer an API call with a redirect. A 3xx from it means
 * the stored shop name is stale or something is in the path -- an error worth surfacing, not a hop
 * worth taking. So FOLLOWLOCATION is off, MAXREDIRS is 0, and a 3xx is reported as a failure.
 *
 * SEVERITY, which this row corrected twice and is worth keeping straight: the exposure is NOT
 * cross-tenant. `shopify_store_url` is already in `SettingsRestrictedWriteTest`'s restricted-name
 * list, so writing it needs `Manage Setting` in the caller's own store. The request is made by the
 * PLATFORM'S server, so a breakout reaches the PLATFORM's cloud IAM credentials -- merchant versus
 * platform, regardless of tenancy.
 *
 * THE RETURN SHAPE IS THE CALL SITES' EXISTING ONE. Every one of the thirteen handlers already reads
 *
 *     $response = curl_exec($curl);
 *     if ($response == false) { return redirect()->back()->with('error', 'Something went wrong.'); }
 *
 * so this returns the raw body or `false` and no handler needs new control flow -- the same reasoning
 * that made `ImportStoreUrl` a one-line change at each read site.
 */
final class ShopifyAdminApi
{
    /** The whole request, not one hop: these calls had CURLOPT_TIMEOUT => 0, i.e. no deadline at all. */
    private const TIMEOUT = 20;

    private const CONNECT_TIMEOUT = 5;

    /**
     * The address this service will dial, or null when the stored shop name is not a usable DNS label.
     *
     * PUBLIC AND SEPARATE so it can be asserted on directly. The host is assembled HERE, from the
     * validated label plus the fixed suffix, and the caller's `$path` is appended AFTER a `/` with its own
     * leading slashes stripped -- so no path value can move the host. That matters because the path at
     * several call sites interpolates request-derived values (`?ids=$id`, `&page_info=$page_info`), and
     * because the address is then judged and PINNED by SafeRemoteFetch: the pin is only as good as the
     * guarantee that the host it names is the host that will be dialled.
     */
    public static function urlFor(?string $shopName, string $path): ?string
    {
        $label = ImportStoreUrl::shopifyLabel($shopName);

        if ($label === '') {
            return null;
        }

        return 'https://' . $label . '.myshopify.com/' . ltrim($path, '/');
    }

    /**
     * @param  string|null  $shopName  the stored `shopify_store_url` value, unvalidated
     * @param  string  $path  the API path, e.g. 'admin/api/2023-07/products.json?ids=1,2'
     * @param  string|null  $accessToken  the merchant's `shopify_access_token`
     * @return string|false the response body, or false -- the two shapes every call site already handles
     */
    public static function get(?string $shopName, string $path, ?string $accessToken)
    {
        $result = self::request($shopName, $path, $accessToken, false);

        return $result === false ? false : $result['raw'];
    }

    /**
     * The same request, for the ONE call site that needs the response HEADERS.
     *
     * `ShopifyProductController::fetchShopifyProducts()` sets `CURLOPT_HEADER => true` and then reads
     * `CURLINFO_HEADER_SIZE` off the handle to split the two, because Shopify's cursor pagination lives in
     * the `Link` header. The handle is closed in here, so the size is returned with the bytes rather than
     * left to be asked for afterwards.
     *
     * @return array{raw: string, header_size: int}|false
     */
    public static function getWithHeaders(?string $shopName, string $path, ?string $accessToken)
    {
        return self::request($shopName, $path, $accessToken, true);
    }

    /**
     * @return array{raw: string, header_size: int}|false
     */
    private static function request(?string $shopName, string $path, ?string $accessToken, bool $withHeaders)
    {
        $url = self::urlFor($shopName, $path);

        if ($url === null) {
            // The settings WRITE validates the syntax and gives the merchant a readable message; this is
            // the read side failing closed for a value stored before that validation existed.
            return false;
        }

        $label = ImportStoreUrl::shopifyLabel($shopName);

        try {
            $target = SafeRemoteFetch::check($url);
        } catch (\Throwable $e) {
            Log::warning('a Shopify Admin API address was refused before the request was made', [
                'shop' => $label,
                'reason' => $e->getMessage(),
            ]);

            return false;
        }

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => SafeRemoteFetch::pinnedUrl($url, $target),
            // the connection is pinned to the address check() judged, so a second DNS answer cannot aim it
            CURLOPT_RESOLVE => [SafeRemoteFetch::resolveEntry($target)],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => ['X-Shopify-Access-Token: ' . (string) $accessToken],
            CURLOPT_HEADER => $withHeaders,
        ]);

        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        curl_close($curl);

        if ($status >= 300 && $status < 400) {
            Log::warning('a Shopify Admin API call answered with a redirect, which was not followed', [
                'shop' => $label,
                'status' => $status,
            ]);

            return false;
        }

        return is_string($raw) ? ['raw' => $raw, 'header_size' => $withHeaders ? $headerSize : 0] : false;
    }
}
