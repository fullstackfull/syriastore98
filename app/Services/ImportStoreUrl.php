<?php

namespace App\Services;

use RuntimeException;

/**
 * The remote-shop address an importer is allowed to talk to. P1-SEC-06.
 *
 * WHY THIS EXISTS, and why it is not just another call to SafeRemoteFetch. The importers have two
 * different outbound surfaces and only one of them was guarded:
 *
 *   the IMAGE fetch -- already safe. Every importer runs each product/category image URL through
 *   `SafeRemoteFetch::head()` (14+ call sites), which refuses private, loopback, link-local and
 *   metadata addresses, pins the connection to the address it judged, and bounds redirects.
 *
 *   the STORE-API fetch -- the hole. The base address of the merchant's own shop was interpolated
 *   raw, and nothing judged it.
 *
 * SHOPIFY: THE HOST LOOKED UNSPOOFABLE AND WAS NOT. Thirteen sites build
 * `"https://$shopify_store_url.myshopify.com/admin/api/..."`, so the merchant appears to control only
 * a subdomain label under a fixed suffix. MEASURED with parse_url:
 *
 *     'mystore'           -> host 'mystore.myshopify.com'     (intended)
 *     '169.254.169.254#'  -> host '169.254.169.254'           *** the cloud metadata endpoint ***
 *     '169.254.169.254/'  -> host '169.254.169.254'
 *     '169.254.169.254?'  -> host '169.254.169.254'
 *     '127.0.0.1:8080#'   -> host '127.0.0.1'                 *** internal services ***
 *
 * A `#`, `/` or `?` ends the host and comments the `.myshopify.com` suffix out of it. The settings
 * write validated only `required|string` (SettingController:992), and every one of those requests sets
 * `CURLOPT_FOLLOWLOCATION => true` and sends `X-Shopify-Access-Token`, so a breakout both reaches
 * internal addresses and hands the merchant's API token to the host it reached.
 *
 * So the Shopify fix is NOT an address check -- it is that the value must be a bare DNS label, because
 * that is the only thing it is ever allowed to be. Checking the assembled host instead would leave a
 * merchant able to point the importer at any public host of their choosing, which is not what a field
 * labelled "store name" means.
 *
 * WOOCOMMERCE: the whole base address is the merchant's, so there the address itself must be judged,
 * and `SafeRemoteFetch::check()` is reused rather than reimplemented -- same ranges, same scheme
 * allowlist, same refusal of credentials in the URL.
 *
 * BOTH FAIL CLOSED WITHOUT NEW CONTROL FLOW, which is why this is a one-line change at each read site
 * rather than a restructuring of twenty-five handlers. `shopifyLabel()` returns '' for anything it
 * does not accept, so the assembled URL becomes `https://.myshopify.com/...` -- an invalid host that
 * cURL refuses, landing in the `if ($response == false)` branch every one of those handlers already
 * has. `woocommerceBase()` returns null, which the WooCommerce client rejects the same way. The
 * merchant sees the handler's existing "something went wrong", and the readable message comes from the
 * validation added at the settings WRITE.
 */
final class ImportStoreUrl
{
    /**
     * A Shopify shop's subdomain label, or '' if the stored value is not one.
     *
     * One to sixty-three characters, letters digits and hyphens, not starting or ending with a hyphen:
     * RFC 1035's label rule, which is also what Shopify itself permits. Deliberately strict -- a dot is
     * refused too, so neither `evil.com#` nor a legitimate-looking `shop.evil.com` can be stored and
     * reached, and no percent-encoding, credential or port spelling survives.
     */
    public static function shopifyLabel(?string $raw): string
    {
        $label = strtolower(trim((string) $raw));

        // NUL and whitespace first: a trailing NUL would otherwise sit inside the host cURL dials.
        $label = str_replace(["\0", "\r", "\n", "\t", ' '], '', $label);

        if ($label === '' || strlen($label) > 63) {
            return '';
        }

        return preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label) === 1 ? $label : '';
    }

    /** Is this value usable as a Shopify shop name? The readable form of shopifyLabel(), for validation at the write. */
    public static function isValidShopifyLabel(?string $raw): bool
    {
        return self::shopifyLabel($raw) !== '';
    }

    /**
     * A WooCommerce shop's base address with no trailing slash, or null if it is not one this server may
     * dial. Address judgement is delegated to SafeRemoteFetch::check() -- http/https only, no credentials
     * in the URL, and private, loopback, link-local and metadata ranges refused.
     */
    public static function woocommerceBase(?string $raw): ?string
    {
        // Control characters are STRIPPED -- they must never reach a request line. A SPACE is
        // REFUSED, not stripped: silently removing it would turn `shop.example.com /x` into a host the
        // merchant did not type, and a URL with a space in it is a typo worth reporting.
        $url = str_replace(["\0", "\r", "\n", "\t"], '', trim((string) $raw));

        if ($url === '' || str_contains($url, ' ')) {
            return null;
        }

        // A bare host is what merchants type; make the scheme explicit so parse_url reads a host and not
        // a path. A value that already names a scheme keeps it, so `http://` is not silently upgraded.
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) !== 1) {
            $url = 'https://' . $url;
        }

        try {
            self::check($url);
        } catch (RuntimeException) {
            return null;
        }

        return rtrim($url, '/');
    }

    /**
     * Is this value WELL FORMED as a WooCommerce store URL? Syntax only -- NO DNS.
     *
     * WHY THE WRITE DOES NOT RESOLVE, corrected after this change broke two legitimate saves
     * (FixedNameCredentialWritersTest's woocommerce data set, which posts `https://shop.example.test`).
     * The first version validated the write with `woocommerceBase()`, which resolves the host, so
     * saving the setting required the host to resolve FROM THE PLATFORM'S SERVER at that moment.
     *
     * That is wrong twice over:
     *
     *   AVAILABILITY -- a merchant whose shop sits behind split-horizon DNS, a domain that has not
     *   propagated yet, or a host the platform cannot see, could not save their settings at all.
     *
     *   AND IT BUYS NOTHING, which is the deciding reason. A write-time resolution is a TOCTOU check:
     *   DNS can be re-pointed at 169.254.169.254 any time AFTER the write. So it could never have been
     *   the control, only a speed bump that also broke honest merchants.
     *
     * The sound place for the address judgement is immediately before the fetch, and that is where it
     * is -- `woocommerceBase()` at each read site, which runs microseconds before the request and
     * leaves no useful window. This layer's only job is to give the merchant a readable reason for a
     * malformed value instead of a silently failing import.
     */
    public static function isWellFormedWoocommerceBase(?string $raw): bool
    {
        $url = str_replace(["\0", "\r", "\n", "\t"], '', trim((string) $raw));

        if ($url === '' || str_contains($url, ' ')) {
            return false;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) !== 1) {
            $url = 'https://' . $url;
        }

        $parts = parse_url($url);

        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        // credentials in a store URL are never legitimate and would otherwise be sent on every import
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        // a host must look like a host: dotted name or an address, no path characters smuggled in
        return preg_match('/^[a-z0-9._:\[\]-]+$/i', $parts['host']) === 1;
    }

    /** Is this value usable as a WooCommerce store URL, address included? Resolves DNS -- for use at fetch time only. */
    public static function isValidWoocommerceBase(?string $raw): bool
    {
        return self::woocommerceBase($raw) !== null;
    }

    /** Seam: the address judgement, so a test can drive the refusal without DNS. */
    private static function check(string $url): void
    {
        SafeRemoteFetch::check($url);
    }
}
