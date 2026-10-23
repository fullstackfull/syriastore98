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

    // =============================================================================================
    //  ACCEPTANCE 3 -- THE SECOND HOP.  The label rule closed the first; these close the redirect.
    // =============================================================================================

    /**
     * WHAT WAS STILL OPEN AFTER THE LABEL RULE, and why it is not covered by it.
     *
     * `shopifyLabel()` makes the stored shop name a bare DNS label, so the FIRST hop is a
     * `*.myshopify.com` host. All thirteen call sites then set
     *
     *     CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 10, CURLOPT_TIMEOUT => 0
     *
     * with no CURLOPT_REDIR_PROTOCOLS, so a response from a perfectly valid Shopify host could walk the
     * platform's own server to 169.254.169.254 across up to ten hops with no deadline -- and libcurl
     * forwards CURLOPT_HTTPHEADER on a followed redirect, so every hop was sent
     * `X-Shopify-Access-Token: <the merchant's token>`. REDIR_PROTOCOLS does not help: https to a private
     * address is still https.
     *
     * This is the source half. The behavioural half -- that the option really is what decides, and that
     * the token really is forwarded -- cannot live in this suite, because proving it needs a host that
     * answers with a redirect and SafeRemoteFetch refuses the only such host available here (loopback).
     * It is measured in tests/Support/Security/bin/verify-sec06-shopify-redirect.php.
     */
    public function test_no_shopify_call_follows_a_redirect_and_none_is_without_a_deadline(): void
    {
        $service = $this->strippedSource(app_path('Services/ShopifyAdminApi.php'));

        $this->assertStringContainsString('CURLOPT_FOLLOWLOCATION => false', $service,
            'the Shopify client follows redirects again: a valid *.myshopify.com response can then walk this server to a private address');
        $this->assertStringContainsString('CURLOPT_MAXREDIRS => 0', $service);
        $this->assertStringNotContainsString('CURLOPT_TIMEOUT => 0', $service,
            'CURLOPT_TIMEOUT => 0 is NO deadline, which is what every one of the thirteen call sites had');
        $this->assertMatchesRegularExpression('/CURLOPT_TIMEOUT => self::TIMEOUT/', $service);
        $this->assertMatchesRegularExpression('/CURLOPT_RESOLVE => \[SafeRemoteFetch::resolveEntry/', $service,
            'the connection is no longer pinned to the address that was judged, so a second DNS answer can aim it elsewhere');

        // and the 20-second value is a real number, not another 0 spelled differently
        $this->assertGreaterThan(0, (int) (new \ReflectionClass(\App\Services\ShopifyAdminApi::class))->getConstant('TIMEOUT'));

        // A 3xx is reported as a failure rather than handed back as a body. With FOLLOWLOCATION off the
        // caller would otherwise receive the redirect's own body -- measured: `php -S` answers a 302 with
        // one -- and json_decode() of that is null, which every call site reads as "something went wrong".
        // So this is defence in depth and, more usefully, the thing that puts a line in the log.
        $this->assertMatchesRegularExpression('/\$status >= 300 && \$status < 400/', $service,
            'a redirect is no longer reported as a failure, so a stale shop name fails silently instead of being logged');

        // The address is judged BEFORE the handle is built -- the order is the control, not the presence.
        $this->assertLessThan(
            strpos($service, 'curl_init'),
            strpos($service, 'SafeRemoteFetch::check'),
            'the address is judged after the request is built; it must be judged before.'
        );
    }

    /**
     * THE CENSUS FOR THIS CRITERION, keyed on the construct that carried the defect: a raw cURL handle in
     * an importer controller. Thirteen of them had one; none may now.
     */
    public function test_no_shopify_controller_builds_its_own_curl_handle(): void
    {
        $files = glob(app_path('Http/Controllers/Shopify*.php')) ?: [];
        $this->assertGreaterThanOrEqual(4, count($files), 'the Shopify controllers are not where this expects them -- the census is vacuous');

        $calls = 0;

        foreach ($files as $file) {
            $stripped = $this->strippedSource($file);
            $name = basename($file);

            $this->assertSame(0, preg_match_all('/curl_init\s*\(/', $stripped),
                "{$name} builds its own cURL handle again. Every Shopify Admin API read goes through "
                . 'ShopifyAdminApi, which is where FOLLOWLOCATION, the deadline and the address pin live.');
            $this->assertSame(0, preg_match_all('/CURLOPT_FOLLOWLOCATION/', $stripped), $name);

            $calls += preg_match_all('/ShopifyAdminApi::(get|getWithHeaders)\s*\(/', $stripped);
        }

        $this->assertSame(13, $calls,
            'the thirteen Shopify Admin API reads measured when this was written. A different number means '
            . 'a site was added or removed -- look at it rather than changing this number.');
    }

    /**
     * THE ADDRESS IS JUDGED BEFORE THE REQUEST, for the Shopify client too.
     *
     * The label rule stops the stored value naming a private address directly; it cannot stop a
     * `*.myshopify.com` name RESOLVING to one (DNS is not the merchant's to control, but it is not this
     * server's either). SafeRemoteFetch::check() is therefore still asked, and a refusal is a refusal
     * before any byte leaves -- driven here with DNS stubbed, which is the only way to put a private
     * answer behind a public name without a network.
     */
    public function test_a_shopify_host_that_resolves_to_a_private_address_is_refused_before_the_request(): void
    {
        /*
         * ASSERTED ON THE REFUSAL, not on the return value, and the first draft of this test got that
         * wrong -- it asserted `get()` returns false, which is ALSO what a failed network call returns,
         * and nothing in this container can reach `mystore.myshopify.com`. So it passed whether or not
         * the address was ever judged (measured: a mutant that replaced check() with a hard-coded public
         * target survived it). The refusal's own log line is what separates the two, so the separation is
         * what is asserted: present for a private answer, ABSENT for a public one where the only failure
         * is the missing network.
         */
        \Illuminate\Support\Facades\Log::spy();

        foreach (['169.254.169.254', '127.0.0.1', '10.0.0.5', '::1'] as $answer) {
            SafeRemoteFetch::resolveUsing(static fn (string $host) => [$answer]);

            $this->assertFalse(
                \App\Services\ShopifyAdminApi::get('mystore', 'admin/api/2023-07/products.json', 'tok'),
                "a Shopify host resolving to {$answer} was dialled anyway"
            );

            \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
                ->withArgs(fn ($message) => str_contains((string) $message, 'address was refused before the request was made'))
                ->atLeast()->once();
        }
    }

    /** The other side of that separation: a PUBLIC answer is not refused by the address check. */
    public function test_a_public_shopify_answer_is_not_refused_by_the_address_check(): void
    {
        \Illuminate\Support\Facades\Log::spy();

        SafeRemoteFetch::resolveUsing(static fn (string $host) => ['93.184.216.34']);

        // The call itself cannot succeed here -- there is no network -- so the RETURN value says nothing.
        // What says something is that the refusal line is absent: the address passed, and the failure that
        // follows is the connection, not the guard.
        \App\Services\ShopifyAdminApi::get('mystore', 'admin/api/2023-07/products.json', 'tok');

        \Illuminate\Support\Facades\Log::shouldNotHaveReceived('warning', [
            'a Shopify Admin API address was refused before the request was made',
            \Mockery::any(),
        ]);
    }

    /**
     * THE ADDRESS USES THE NORMALISED LABEL, not the stored string.
     *
     * `shopifyLabel()` lower-cases and strips whitespace, and the pin SafeRemoteFetch installs names the
     * host as `check()` normalised it -- so if the URL carried the raw value, cURL would be given a host
     * the pin does not match and would resolve it again, which is the bypass `pinnedUrl()` exists to
     * prevent. Added because a mutant that interpolated `$shopName` instead of `$label` SURVIVED the
     * first cut of these tests: every name they used was already normalised.
     */
    public function test_the_address_carries_the_normalised_label_and_not_the_stored_string(): void
    {
        foreach (['MyStore', 'MYSTORE', " mystore\t", "mystore\n", 'mystore '] as $stored) {
            $url = \App\Services\ShopifyAdminApi::urlFor($stored, 'admin/api/x.json');

            $this->assertNotNull($url, var_export($stored, true) . ' is a usable label and must yield an address');
            $this->assertSame('mystore.myshopify.com', parse_url($url, PHP_URL_HOST),
                var_export($stored, true) . ' reached cURL unnormalised, so the address pin would not match the host.');
        }
    }

    /** A shop name that is not a usable label never becomes an address at all. */
    public function test_an_unusable_shop_name_yields_no_address(): void
    {
        foreach (['169.254.169.254#', '127.0.0.1:8080#', 'shop.evil.com', '', '  ', 'a_b', str_repeat('a', 64)] as $name) {
            $this->assertNull(\App\Services\ShopifyAdminApi::urlFor($name, 'admin/api/x.json'),
                "'{$name}' produced an address");
            $this->assertFalse(\App\Services\ShopifyAdminApi::get($name, 'admin/api/x.json', 'tok'));
        }
    }

    /**
     * NO PATH VALUE CAN MOVE THE HOST, which is what makes the pin meaningful: several call sites
     * interpolate request-derived values into the path (`?ids=$id`, `&page_info=$page_info`), and the
     * address SafeRemoteFetch judges and pins is only as good as the guarantee that its host is the host
     * that gets dialled.
     */
    public function test_no_path_value_can_move_the_host(): void
    {
        $hostile = [
            '/admin/api/x.json',
            '//169.254.169.254/latest/meta-data/',
            '///evil.example/x',
            '@169.254.169.254/x',
            '../../../etc/passwd',
            '..%2F..%2Fx',
            'admin/api/x.json?ids=1#@evil.example/',
            'admin/api/x.json?next=https://169.254.169.254/',
        ];

        foreach ($hostile as $path) {
            $url = \App\Services\ShopifyAdminApi::urlFor('mystore', $path);

            $this->assertNotNull($url, "'{$path}' was refused for the wrong reason -- the label is valid");
            $this->assertSame('mystore.myshopify.com', parse_url($url, PHP_URL_HOST),
                "'{$path}' moved the host to " . var_export(parse_url($url, PHP_URL_HOST), true));
            $this->assertSame('https', parse_url($url, PHP_URL_SCHEME), "'{$path}' changed the scheme");
        }
    }

    /** Positive control: an ordinary call still assembles the address the importers used before. */
    public function test_a_legitimate_call_assembles_the_address_the_importers_used(): void
    {
        $this->assertSame(
            'https://mystore.myshopify.com/admin/api/2023-07/products.json?ids=1,2',
            \App\Services\ShopifyAdminApi::urlFor('mystore', 'admin/api/2023-07/products.json?ids=1,2')
        );
    }

    private function strippedSource(string $file): string
    {
        $out = '';

        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
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
