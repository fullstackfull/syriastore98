<?php

namespace Tests\Feature\Security;

use App\Services\ImportStoreUrl;
use App\Services\SafeRemoteFetch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P1-SEC-06 -- the importers' STORE-API address, which nothing judged.
 * =============================================================================================
 *
 * WHAT THE TASK ROW GETS WRONG, measured, and recorded because the scope follows from it. The row
 * reads "four import handlers fetch a merchant-controlled URL and serve the response".
 *
 *   1. The IMAGE fetch -- the surface that phrase describes -- is ALREADY GUARDED. Every importer runs
 *      each image URL through `SafeRemoteFetch::head()`: 14+ call sites across all eight controllers.
 *      Nothing in this file re-does that.
 *   2. "serve the response" does not hold for the one site the audit verified.
 *      `WoocomCategoryController::store()` assigns `$jsonData = $response->json()` and returns NOTHING
 *      -- it is a BLIND SSRF. The Shopify handlers do flash a fetched `errors` key back via
 *      `redirect()->back()->with('error', ...)`, but there the caller controls host and response both,
 *      so that is a weak channel. The sharp edge is different: every Shopify request carries
 *      `X-Shopify-Access-Token`, and the WooCommerce client carries the consumer key and secret, so a
 *      host breakout hands the merchant's API credentials to the host it reached.
 *   3. The real count is 13 Shopify URL sites + the WooCommerce base address, not four handlers.
 *
 * THE SHOPIFY BREAKOUT, which is the finding. Those 13 sites build
 * `"https://$shopify_store_url.myshopify.com/admin/api/..."`, so the merchant appears to control only a
 * label under a fixed suffix. `#`, `/` and `?` all end the host and comment the suffix out of it, and
 * the settings write validated `required|string`. `test_the_breakout_this_closes_was_real` pins the
 * pre-fix behaviour with parse_url so the claim is checkable rather than asserted.
 *
 * WHY A LABEL RULE AND NOT AN ADDRESS CHECK. `shopify_store_url` is a shop NAME; the only thing it may
 * ever be is one DNS label. Judging the assembled host instead would still let a merchant aim the
 * importer at any public host, which is not what that field means. The WooCommerce base address is
 * genuinely the merchant's, so there the address is judged -- by reusing `SafeRemoteFetch::check()`,
 * not by a second implementation, which is the acceptance criterion's "one shared helper".
 */
class ImportStoreUrlSsrfTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        SafeRemoteFetch::resolveUsing(null);
        parent::tearDown();
    }

    /**
     * THE PREMISE, and the reason the fix is shaped as it is: the breakout was real.
     *
     * This asserts the PRE-FIX construction, not the fix -- it is the measurement the docblock claims,
     * executed. If `parse_url` ever stopped reading these the way it does, the whole finding would need
     * restating, and this test is what would say so.
     */
    public function test_the_breakout_this_closes_was_real(): void
    {
        $host = static fn (string $label): ?string => parse_url(
            "https://{$label}.myshopify.com/admin/api/2023-07/products.json",
            PHP_URL_HOST
        );

        $this->assertSame('mystore.myshopify.com', $host('mystore'), 'the intended shape');

        // each of these ends the host early, so `.myshopify.com` becomes path or fragment
        $this->assertSame('169.254.169.254', $host('169.254.169.254#'), 'fragment breakout: cloud metadata');
        $this->assertSame('169.254.169.254', $host('169.254.169.254/'), 'path breakout');
        $this->assertSame('169.254.169.254', $host('169.254.169.254?'), 'query breakout');
        $this->assertSame('127.0.0.1', $host('127.0.0.1:8080#'), 'loopback with a port');
    }

    /** @dataProvider breakoutLabelProvider */
    public function test_a_label_that_breaks_out_of_the_host_is_refused(string $label, string $why): void
    {
        $this->assertSame('', ImportStoreUrl::shopifyLabel($label), $why);
        $this->assertFalse(ImportStoreUrl::isValidShopifyLabel($label), $why);

        // and the assembled URL is then an invalid host, not a reachable one -- the fail-closed path
        $assembled = 'https://' . ImportStoreUrl::shopifyLabel($label) . '.myshopify.com/admin/api/x.json';
        $this->assertSame('.myshopify.com', parse_url($assembled, PHP_URL_HOST), $why . ' -- assembled host');
    }

    public static function breakoutLabelProvider(): array
    {
        return [
            'fragment ends the host' => ['169.254.169.254#', 'cloud metadata via #'],
            'path ends the host' => ['169.254.169.254/', 'metadata via /'],
            'query ends the host' => ['169.254.169.254?', 'metadata via ?'],
            'loopback with a port' => ['127.0.0.1:8080#', 'internal service'],
            'a dotted host is not a label' => ['shop.evil.com', 'a label may not contain a dot'],
            'credentials in the value' => ['x@169.254.169.254', 'userinfo'],
            'a scheme in the value' => ['http://169.254.169.254#', 'scheme'],
            'a backslash' => ['evil.com\\', 'backslash'],
            'percent-encoded dot' => ['169%2e254%2e169%2e254%23', 'encoding'],
            'a leading hyphen' => ['-nope', 'RFC 1035 forbids it'],
            'a trailing hyphen' => ['nope-', 'RFC 1035 forbids it'],
            'empty' => ['', 'nothing stored'],
            'whitespace only' => ['   ', 'nothing meaningful stored'],
            'a NUL byte' => ["mystore\0.evil.com", 'NUL truncation'],
            'over 63 characters' => ['a-very-long-label-that-goes-past-the-sixty-three-character-limit-xx', 'label length'],
        ];
    }

    /** the positive control: a real shop name still works, and is still what gets dialled */
    public function test_a_legitimate_shop_name_still_resolves_to_the_shopify_host(): void
    {
        foreach (['mystore', 'my-store-2', 'a', 'syria-store-98'] as $label) {
            $this->assertSame($label, ImportStoreUrl::shopifyLabel($label), $label);
            $this->assertTrue(ImportStoreUrl::isValidShopifyLabel($label), $label);

            $assembled = 'https://' . ImportStoreUrl::shopifyLabel($label) . '.myshopify.com/admin/api/x.json';
            $this->assertSame($label . '.myshopify.com', parse_url($assembled, PHP_URL_HOST), $label);
        }

        // case is folded rather than refused: Shopify shop names are case-insensitive and a merchant
        // typing `MyStore` should not get a broken importer.
        $this->assertSame('mystore', ImportStoreUrl::shopifyLabel('MyStore'));
    }

    /**
     * The WooCommerce base address is judged by SafeRemoteFetch, so the private ranges, the scheme
     * allowlist and the refusal of in-URL credentials are the image fetch's and not a second set.
     *
     * DNS is stubbed: these must be decided by the RANGE the name resolves to, and a test that depended
     * on real DNS would be measuring the sandbox's resolver.
     *
     * @dataProvider deniedBaseProvider
     */
    public function test_a_woocommerce_base_this_server_may_not_dial_is_refused(string $url, string $resolvesTo, string $why): void
    {
        SafeRemoteFetch::resolveUsing(static fn (string $host) => [$resolvesTo]);

        $this->assertNull(ImportStoreUrl::woocommerceBase($url), $why);
        $this->assertFalse(ImportStoreUrl::isValidWoocommerceBase($url), $why);
    }

    public static function deniedBaseProvider(): array
    {
        return [
            'cloud metadata by address' => ['http://169.254.169.254', '169.254.169.254', 'link-local metadata'],
            'metadata behind a public name' => ['https://shop.example.com', '169.254.169.254', 'a public NAME resolving to metadata'],
            'loopback' => ['http://127.0.0.1', '127.0.0.1', 'loopback'],
            'private 10/8' => ['https://internal.example.com', '10.0.0.5', 'RFC1918'],
            'private 192.168/16' => ['https://router.example.com', '192.168.1.1', 'RFC1918'],
            'private 172.16/12' => ['https://svc.example.com', '172.16.0.9', 'RFC1918'],
            'credentials in the URL' => ['https://user:pw@shop.example.com', '93.184.216.34', 'userinfo is refused'],
            'a file scheme' => ['file:///etc/passwd', '93.184.216.34', 'scheme allowlist, not a denylist'],
            'a gopher scheme' => ['gopher://shop.example.com', '93.184.216.34', 'scheme allowlist'],
        ];
    }

    /** the positive control: a real public shop address still imports, and the trailing slash is normalised off */
    public function test_a_legitimate_public_woocommerce_base_is_accepted(): void
    {
        SafeRemoteFetch::resolveUsing(static fn (string $host) => ['93.184.216.34']);

        $this->assertSame('https://shop.example.com', ImportStoreUrl::woocommerceBase('https://shop.example.com'));
        $this->assertSame('https://shop.example.com', ImportStoreUrl::woocommerceBase('https://shop.example.com/'));
        $this->assertTrue(ImportStoreUrl::isValidWoocommerceBase('https://shop.example.com'));

        // a bare host is what merchants actually type; https is assumed, and http is NOT upgraded away
        $this->assertSame('https://shop.example.com', ImportStoreUrl::woocommerceBase('shop.example.com'));
        $this->assertSame('http://shop.example.com', ImportStoreUrl::woocommerceBase('http://shop.example.com'));
    }

    /**
     * THE WRITE AND THE FETCH ASK DIFFERENT QUESTIONS, and this pins the difference.
     *
     * The first version of this change validated the settings WRITE with `woocommerceBase()`, which
     * resolves DNS. That broke two legitimate saves (FixedNameCredentialWritersTest's woocommerce data
     * set posts `https://shop.example.test`, which does not resolve), and the breakage was the useful
     * part: a write-time resolution is a TOCTOU check -- DNS can be re-pointed at 169.254.169.254 at
     * any time AFTER the write -- so it could never have been the control, while it did stop honest
     * merchants behind split-horizon or unpropagated DNS from saving at all.
     *
     * So: the WRITE asks "is this well formed?" (no DNS), and the FETCH asks "may this server dial
     * it?" (DNS, microseconds before the request). This test asserts both halves of that split, because
     * a comment saying so would not stop someone reuniting them.
     */
    public function test_the_write_validates_syntax_only_and_does_not_resolve_dns(): void
    {
        // a host that cannot resolve is still WELL FORMED, so a merchant can save it
        $this->assertTrue(ImportStoreUrl::isWellFormedWoocommerceBase('https://shop.example.test'));
        $this->assertTrue(ImportStoreUrl::isWellFormedWoocommerceBase('shop.example.test'));
        $this->assertTrue(ImportStoreUrl::isWellFormedWoocommerceBase('http://localhost'), 'syntax is all this layer judges');

        // and it is still refused AT FETCH TIME, which is the layer that decides reachability
        SafeRemoteFetch::resolveUsing(static fn (string $host) => ['127.0.0.1']);
        $this->assertNull(ImportStoreUrl::woocommerceBase('http://localhost'), 'the fetch layer must still refuse loopback');

        // the write still refuses what is genuinely malformed, so it is not a no-op
        foreach ([
            '' => 'empty',
            '   ' => 'whitespace',
            'file:///etc/passwd' => 'scheme allowlist',
            'gopher://shop.example.com' => 'scheme allowlist',
            'https://user:pw@shop.example.com' => 'credentials would be sent on every import',
            'https://shop.example.com/a path' => 'a space in the host',
        ] as $value => $why) {
            $this->assertFalse(ImportStoreUrl::isWellFormedWoocommerceBase($value), $why);
        }
    }

    /**
     * THE CENSUS, keyed on the construction rather than on a spelling. No controller may interpolate the
     * raw setting into a URL any more; the value must come through the helper.
     *
     * Comments are stripped, because the helper's own docblock quotes the vulnerable construction to
     * explain it, and a ratchet a sentence of prose can fail is measuring the wrong file.
     */
    public function test_no_importer_interpolates_the_raw_store_setting_into_a_url(): void
    {
        $files = glob(app_path('Http/Controllers/{Shopify,Woocom}*.php'), GLOB_BRACE) ?: [];
        $this->assertGreaterThanOrEqual(8, count($files), 'the importer controllers are not where this expects them -- the census is vacuous');

        $checked = 0;

        foreach ($files as $file) {
            $stripped = '';
            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $stripped .= is_array($token) ? $token[1] : $token;
            }

            $name = basename($file);

            // KEYED ON THE ASSIGNMENT, not on the interpolation, and the first version of this test got
            // that wrong. It forbade the string `$shopify_store_url.myshopify.com` -- but the fix
            // sanitises the VARIABLE where it is read, and leaves the URL construction untouched, so the
            // ratchet failed against correct code and would have passed against a renamed-but-unguarded
            // variable. The control is "every read of a store-address setting passes through the helper",
            // so that is what this measures. Same lesson as the project's census-failure modes: key on the
            // write, not on the spelling.
            foreach ([
                'shopify_store_url' => 'shopifyLabel',
                'woocommerce_store_url' => 'woocommerceBase',
            ] as $setting => $method) {
                // `=(?!=)` so a COMPARISON is not read as an assignment: the explicit
                // `if ($woocommerce_store_url === null)` guard matched the first version of this pattern
                // and reported the handler unguarded, which is the opposite of the truth.
                if (preg_match_all('/\$' . $setting . '\s*=(?!=)\s*([^;]+);/', $stripped, $matches) === 0) {
                    continue;
                }

                foreach ($matches[1] as $rhs) {
                    $this->assertStringContainsString(
                        'ImportStoreUrl::' . $method,
                        $rhs,
                        "{$name} assigns \${$setting} without passing it through ImportStoreUrl::{$method}()"
                    );
                    $checked++;
                }
            }
        }

        // 25 read sites measured when this was written (12 Shopify, 13 WooCommerce). The floor is the
        // reachability assertion: if a rename made every pattern miss, $checked would be 0 and this fails.
        $this->assertGreaterThanOrEqual(20, $checked, 'the store-address assignments were not found -- the census is vacuous');
    }
}
