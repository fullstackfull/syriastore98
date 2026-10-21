<?php

namespace Tests\Feature\Security;

use App\Models\Utility;
use App\Services\SafeRemoteFetch;
use Codexshaper\WooCommerce\Facades\Category as WooCategory;
use Codexshaper\WooCommerce\Facades\Product as WooProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * P1-SEC-06 -- the WooCommerce / Shopify importers fetched an image URL the MERCHANT controls.
 * =============================================================================================
 *
 * The importers read `images[0].src` out of the response of the caller's own remote store, sent a HEAD to it for the size, then
 * `file_get_contents()`-ed it, wrote the bytes under uploads/{store}/ and served them as the cover image: an exfiltrating server-side
 * request forgery (`http://169.254.169.254/...`, any internal HTTP service). All of it now goes through App\Services\SafeRemoteFetch --
 * the size probe at fifteen controller sites and the download at the one sink, Utility::upload_woo_file, which the Shopify controllers
 * share.
 *
 * DNS is a seam (SafeRemoteFetch::resolveUsing) and the HTTP client is Http::fake, so nothing here touches a network. The fake's callback
 * receives the Guzzle OPTIONS, which is how the redirect and pinning controls are measured and not just assumed.
 */
class RemoteImageFetchTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const PUBLIC_IP = '93.184.216.34';

    protected function setUp(): void
    {
        parent::setUp();

        SafeRemoteFetch::resolveUsing(fn (string $host) => match ($host) {
            'img.example.test', 'other.example.test', 'hop1.example.test', 'hop2.example.test', 'hop3.example.test', 'hop4.example.test' => [self::PUBLIC_IP],
            'cdn.example.test' => ['93.184.216.35'],
            'evil.example.test' => ['169.254.169.254'],
            'loopback.example.test', 'localhost' => ['127.0.0.1'],
            'mixed.example.test' => [self::PUBLIC_IP, '10.0.0.5'],
            'v6mixed.example.test' => ['2606:4700:4700::1111', 'fd00::1'],
            'v6public.example.test' => ['2606:4700:4700::1111'],
            default => [],
        });
    }

    protected function tearDown(): void
    {
        SafeRemoteFetch::resolveUsing(null);
        SafeRemoteFetch::limitBytesTo(null);

        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------------------
    // the address rules
    // ---------------------------------------------------------------------------------------------

    public static function deniedAddresses(): array
    {
        return array_map(fn ($ip) => [$ip], [
            '127.0.0.1', '127.255.255.254', '10.0.0.1', '10.255.255.255', '172.16.0.1', '172.31.255.255', '192.168.0.1', '192.168.255.255',
            '169.254.169.254', '169.254.0.1', '100.64.0.1', '100.127.255.255', '0.0.0.0', '0.1.2.3', '192.0.0.1', '192.0.2.1', '198.18.0.1',
            '198.51.100.1', '203.0.113.1', '224.0.0.1', '239.255.255.255', '240.0.0.1', '255.255.255.255',
            '::', '::1', 'fe80::1', 'fec0::1', 'fc00::1', 'fd12:3456::1', 'ff02::1', '2001:db8::1', '::ffff:127.0.0.1', '::ffff:169.254.169.254',
            '::ffff:10.0.0.1', '::127.0.0.1', '64:ff9b::7f00:1', '64:ff9b::a9fe:a9fe', '2002:7f00:1::', '2002:a9fe:a9fe::1', 'not-an-ip',
        ]);
    }

    /** @dataProvider deniedAddresses */
    public function test_a_private_loopback_metadata_or_reserved_address_is_denied(string $ip): void
    {
        $this->assertTrue(SafeRemoteFetch::isDeniedIp($ip), $ip);
    }

    public static function publicAddresses(): array
    {
        return array_map(fn ($ip) => [$ip], ['8.8.8.8', '93.184.216.34', '1.1.1.1', '172.15.255.255', '172.32.0.1', '100.63.255.255', '100.128.0.1',
            '11.0.0.1', '126.255.255.255', '128.0.0.1', '169.253.255.255', '169.255.0.1', '223.255.255.255', '2606:4700:4700::1111', '2a00:1450:4001::1',
            '::ffff:8.8.8.8', '64:ff9b::808:808']);
    }

    /** the positive control for the table above: a list that denies everything proves nothing */
    #[\PHPUnit\Framework\Attributes\DataProvider('publicAddresses')]
    public function test_an_ordinary_public_address_is_not_denied(string $ip): void
    {
        $this->assertFalse(SafeRemoteFetch::isDeniedIp($ip), $ip);
    }

    public static function refusedUrls(): array
    {
        return array_map(fn ($u) => [$u], [
            'file:///etc/passwd', 'ftp://img.example.test/p.jpg', 'gopher://img.example.test/_x', 'data:text/plain;base64,AAAA', 'php://filter/resource=/etc/passwd',
            'phar:///tmp/x.phar', 'dict://img.example.test:11211/stats', '//img.example.test/p.jpg', 'http:///p.jpg', 'not a url', '',
            'http://user:pw@img.example.test/p.jpg', 'http://user@img.example.test/p.jpg',
            'http://img.example.test:6379/', 'http://img.example.test:22/', 'http://img.example.test:3306/', 'https://img.example.test:9200/',
            'http://169.254.169.254/latest/meta-data/', 'http://169.254.169.254:80/', 'http://10.1.2.3/', 'http://192.168.1.1/', 'http://172.16.0.9/',
            'http://100.64.0.1/', 'http://0.0.0.0/', 'http://127.0.0.1/', 'http://[::1]/', 'http://[::ffff:127.0.0.1]/', 'http://[fd00::1]/',
            'http://2130706433/', 'http://0x7f.0.0.1/', 'http://017700000001/', 'http://127.1/', 'http://0177.0.0.1/', 'http://0x7f000001/',
            'http://localhost/', 'http://loopback.example.test/', 'http://evil.example.test/', 'http://mixed.example.test/', 'http://v6mixed.example.test/',
            'http://nxdomain.example.test/', 'http://exa mple.test/', 'http://-bad.example.test/', "http://img.example.test\0.evil/",
        ]);
    }

    /** @dataProvider refusedUrls */
    public function test_a_url_that_points_inward_or_is_not_plain_http_is_refused_before_anything_is_sent(string $url): void
    {
        Http::fake();

        foreach (['check', 'head', 'contents'] as $call) {
            // NOT `$this->fail()` inside the try: PHPUnit's AssertionFailedError IS a RuntimeException, so the catch below would swallow it
            $refusal = null;
            try {
                SafeRemoteFetch::$call($url);
            } catch (RuntimeException $e) {
                $refusal = $e->getMessage();
            }
            $this->assertNotNull($refusal, "SafeRemoteFetch::{$call}() accepted " . json_encode($url));
            $this->assertNotSame('', $refusal);
        }

        Http::assertNothingSent();
    }

    public static function acceptedUrls(): array
    {
        return array_map(fn ($u) => [$u], ['http://img.example.test/p.jpg', 'https://img.example.test/p.jpg', 'https://IMG.Example.Test./p.jpg?x=1#f',
            'http://img.example.test:8080/p.jpg', 'https://img.example.test:8443/p.jpg', 'https://v6public.example.test/p.jpg', 'http://93.184.216.34/p.jpg',
            'http://[2606:4700:4700::1111]/p.jpg']);
    }

    /** @dataProvider acceptedUrls */
    public function test_a_public_http_or_https_url_is_accepted(string $url): void
    {
        $target = SafeRemoteFetch::check($url);

        $this->assertContains($target['scheme'], ['http', 'https']);
        $this->assertFalse(SafeRemoteFetch::isDeniedIp($target['ip']));
    }

    // ---------------------------------------------------------------------------------------------
    // the fetch itself
    // ---------------------------------------------------------------------------------------------

    public function test_the_connection_is_pinned_to_the_address_that_was_checked_and_redirects_are_not_followed_by_the_client(): void
    {
        $seen = [];
        Http::fake(function (Request $request, array $options) use (&$seen) {
            $seen[] = $options;

            return Http::response('bytes', 200);
        });

        $this->assertSame('bytes', SafeRemoteFetch::contents('https://img.example.test/p.jpg'));

        $this->assertCount(1, $seen);
        $this->assertFalse($seen[0]['allow_redirects'], 'The HTTP client was left to follow redirects by itself.');
        $this->assertSame(['img.example.test:443:' . self::PUBLIC_IP], $seen[0]['curl'][CURLOPT_RESOLVE],
            'The connection is not pinned to the address that was judged.');
    }

    /**
     * REVIEW FINDING (read-only review of 7524e844, reproduced by execution). The first version sent the GET with `'stream' => true`; Guzzle
     * routes a streamed request to its StreamHandler, which ignores the `curl` array -- so the pin held for HEAD and was absent for the GET
     * that stores the bytes. tests/Support/Security/bin/verify-remote-fetch-pin-with-curl.php measures the behaviour against a live socket;
     * this pins the OPTIONS that make it hold.
     */
    public function test_the_get_is_not_streamed_and_carries_the_pin_and_the_size_cap_to_curl(): void
    {
        SafeRemoteFetch::limitBytesTo(5000);
        $seen = [];
        Http::fake(function (Request $request, array $options) use (&$seen) {
            $seen[$request->method()] = $options;

            return Http::response('bytes', 200);
        });

        SafeRemoteFetch::contents('https://img.example.test/p.jpg');
        SafeRemoteFetch::head('https://img.example.test/p.jpg');

        foreach (['GET', 'HEAD'] as $method) {
            $this->assertArrayNotHasKey('stream', $seen[$method], "{$method}: a streamed request is served by a handler that ignores the curl options.");
            $this->assertSame(['img.example.test:443:' . self::PUBLIC_IP], $seen[$method]['curl'][CURLOPT_RESOLVE], "{$method} is not pinned");
        }

        $this->assertSame(5000, $seen['GET']['curl'][CURLOPT_MAXFILESIZE], 'the cap is enforced by cURL while the body arrives');
        $this->assertArrayNotHasKey(CURLOPT_MAXFILESIZE, $seen['HEAD']['curl'], 'HEAD stays free to report a large Content-Length, which the importers read');
    }

    /**
     * cURL matches the name it was GIVEN against the CURLOPT_RESOLVE entry. check() normalises (`cdn.example.com.` is judged as
     * `cdn.example.com`), so a URL that kept the dot would not match the pin and would be resolved again.
     */
    public function test_a_trailing_dot_or_capitals_in_the_host_do_not_escape_the_pin(): void
    {
        foreach (['https://img.example.test./p.jpg', 'https://IMG.Example.TEST/p.jpg', 'https://img.example.test../p.jpg'] as $url) {
            $seen = [];
            Http::fake(function (Request $request, array $options) use (&$seen) {
                $seen[] = [$request->url(), $options];

                return Http::response('bytes', 200);
            });

            SafeRemoteFetch::contents($url);

            $this->assertSame('https://img.example.test/p.jpg', $seen[0][0], "{$url}: the request must name the host the pin names");
            $this->assertSame(['img.example.test:443:' . self::PUBLIC_IP], $seen[0][1]['curl'][CURLOPT_RESOLVE]);
        }
    }

    public function test_the_path_query_and_port_survive_the_rebuilt_url(): void
    {
        $seen = [];
        Http::fake(function (Request $request) use (&$seen) {
            $seen[] = $request->url();

            return Http::response('bytes', 200);
        });

        SafeRemoteFetch::contents('http://img.example.test:8080/a%20b/p.jpg?x=1&y=%C3%A9#frag');
        SafeRemoteFetch::contents('http://img.example.test');

        $this->assertSame('http://img.example.test:8080/a%20b/p.jpg?x=1&y=%C3%A9', $seen[0]);
        $this->assertSame('http://img.example.test/', $seen[1]);
    }

    /** @return array<string, array{0: \Throwable, 1: string, 2: string}> */
    public static function transportFailures(): array
    {
        $request = new \GuzzleHttp\Psr7\Request('GET', 'https://img.example.test/x.jpg');

        return [
            'size abort' => [new \GuzzleHttp\Exception\RequestException('cURL error 63: Exceeded the maximum allowed file size (20971520) with 20971520 bytes', $request), 'larger than', 'cURL error'],
            'unresolvable' => [new \GuzzleHttp\Exception\ConnectException('cURL error 6: Could not resolve host', $request), 'could not be fetched', 'Could not resolve'],
            'timeout' => [new \GuzzleHttp\Exception\RequestException('cURL error 28: timed out', $request), 'could not be fetched', 'timed out'],
        ];
    }

    /**
     * A body cURL aborted for its size is reported as too large; any other transport error as unfetchable, without the transport detail.
     *
     * @dataProvider transportFailures
     */
    public function test_a_transport_failure_surfaces_as_a_runtime_exception_with_a_merchant_safe_message(\Throwable $failure, string $says, string $hides): void
    {
        Http::fake(function () use ($failure) {
            throw $failure;
        });

        try {
            SafeRemoteFetch::contents('https://img.example.test/x.jpg');
            $this->fail('no exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($says, $e->getMessage());
            $this->assertStringNotContainsString($hides, $e->getMessage());
        }
    }

    /** gethostbynamel() answers false for a name with no A record; `(array) false` made that `[false]`, which counted as a denied address. */
    public function test_a_failed_lookup_yields_no_addresses_and_an_aaaa_only_host_keeps_its_address(): void
    {
        $this->assertSame([], SafeRemoteFetch::addressesFrom(false, false));
        $this->assertSame([], SafeRemoteFetch::addressesFrom(false, []));
        $this->assertSame([self::PUBLIC_IP], SafeRemoteFetch::addressesFrom([self::PUBLIC_IP], false));
        $this->assertSame(['2606:4700:4700::1111'], SafeRemoteFetch::addressesFrom(false, [['host' => 'x', 'ipv6' => '2606:4700:4700::1111'], ['host' => 'x']]));
        $this->assertSame([self::PUBLIC_IP, '2606:4700:4700::1111'], SafeRemoteFetch::addressesFrom([self::PUBLIC_IP], [['ipv6' => '2606:4700:4700::1111']]));
    }

    public function test_a_host_that_does_not_resolve_is_refused_as_not_resolving(): void
    {
        SafeRemoteFetch::resolveUsing(fn () => []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not resolve');

        SafeRemoteFetch::check('https://nowhere.example.test/p.jpg');
    }

    public function test_a_nul_byte_in_an_own_origin_path_is_a_clean_refusal_not_a_value_error(): void
    {
        config(['app.url' => 'https://shop.example.test']);
        Http::fake();

        foreach (['https://shop.example.test/%00', 'https://shop.example.test/a%00.png', 'https://shop.example.test/storage/%00../.env'] as $url) {
            try {
                SafeRemoteFetch::contents($url);
                $this->fail("{$url} was read");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('not available on this server', $e->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    /** A server that rejects HEAD (405) but serves GET: the size probe is advisory and its answer is whatever the server said. */
    public function test_head_returns_a_non_success_answer_instead_of_raising(): void
    {
        Http::fake(['*' => Http::response('', 405)]);

        $response = SafeRemoteFetch::head('https://img.example.test/p.jpg');

        $this->assertSame(405, $response->status());
        $this->assertSame('', $response->header('Content-Length'));
    }

    public function test_head_still_judges_a_redirect_target(): void
    {
        Http::fake(['img.example.test/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/'])]);

        $this->expectException(RuntimeException::class);

        SafeRemoteFetch::head('https://img.example.test/p.jpg');
    }

    public function test_an_ipv6_address_is_pinned_in_brackets(): void
    {
        $seen = [];
        Http::fake(function (Request $request, array $options) use (&$seen) {
            $seen[] = $options;

            return Http::response('v6', 200);
        });

        SafeRemoteFetch::contents('http://v6public.example.test:8080/p.jpg');

        $this->assertSame(['v6public.example.test:8080:[2606:4700:4700::1111]'], $seen[0]['curl'][CURLOPT_RESOLVE]);
    }

    public function test_a_public_host_that_redirects_to_the_metadata_service_is_refused_and_the_second_request_is_never_made(): void
    {
        Http::fake([
            'img.example.test/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/iam/security-credentials/']),
            '*' => Http::response('SECRET', 200),
        ]);

        $this->expectException(RuntimeException::class);

        try {
            SafeRemoteFetch::contents('https://img.example.test/p.jpg');
        } finally {
            Http::assertSentCount(1);
            Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '169.254'));
        }
    }

    public static function dangerousRedirectTargets(): array
    {
        return array_map(fn ($u) => [$u], ['http://169.254.169.254/', 'http://127.0.0.1/', 'http://10.0.0.1/admin', 'http://[::1]/', 'http://evil.example.test/x',
            'http://loopback.example.test/', 'file:///etc/passwd', 'ftp://img.example.test/x', 'http://2130706433/', '//169.254.169.254/x', 'http://img.example.test:6379/']);
    }

    /** @dataProvider dangerousRedirectTargets */
    public function test_every_redirect_hop_is_judged_like_the_first_request(string $target): void
    {
        Http::fake(['img.example.test/*' => Http::response('', 301, ['Location' => $target]), '*' => Http::response('SECRET', 200)]);

        $refused = false;
        try {
            SafeRemoteFetch::head('https://img.example.test/p.jpg');
        } catch (RuntimeException $e) {
            $refused = true;
        }
        $this->assertTrue($refused, 'A redirect to ' . $target . ' was followed.');
        $this->assertCount(1, Http::recorded(), 'A second request was made.');
    }

    public function test_a_redirect_to_another_public_host_is_followed_and_pinned_again(): void
    {
        $seen = [];
        Http::fake(function (Request $request, array $options) use (&$seen) {
            $seen[] = [$request->url(), $options['curl'][CURLOPT_RESOLVE][0]];

            return str_contains($request->url(), 'img.example.test')
                ? Http::response('', 302, ['Location' => 'https://cdn.example.test/final.jpg'])
                : Http::response('FINAL', 200);
        });

        $this->assertSame('FINAL', SafeRemoteFetch::contents('https://img.example.test/p.jpg'));

        $this->assertSame([['https://img.example.test/p.jpg', 'img.example.test:443:93.184.216.34'], ['https://cdn.example.test/final.jpg', 'cdn.example.test:443:93.184.216.35']], $seen);
    }

    public function test_relative_redirects_are_resolved_against_the_current_hop(): void
    {
        $urls = [];
        Http::fake(function (Request $request) use (&$urls) {
            $urls[] = $request->url();

            return match (count($urls)) {
                1 => Http::response('', 302, ['Location' => '/img/final.jpg']),
                2 => Http::response('', 302, ['Location' => 'next.jpg']),
                default => Http::response('DONE', 200),
            };
        });

        $this->assertSame('DONE', SafeRemoteFetch::contents('http://img.example.test:8080/a/b.jpg'));
        $this->assertSame(['http://img.example.test:8080/a/b.jpg', 'http://img.example.test:8080/img/final.jpg', 'http://img.example.test:8080/img/next.jpg'], $urls);
    }

    public function test_a_chain_of_redirects_is_cut_off(): void
    {
        Http::fake(fn (Request $request) => Http::response('', 302, ['Location' => 'https://other.example.test/' . uniqid()]));

        try {
            SafeRemoteFetch::contents('https://img.example.test/p.jpg');
            $refused = null;
        } catch (RuntimeException $e) {
            $refused = $e->getMessage();
        }

        $this->assertStringContainsString('too many times', (string) $refused);
        Http::assertSentCount(4);   // the first request and three redirects, then it stops
    }

    public function test_a_non_success_status_is_refused_not_stored(): void
    {
        Http::fake(['*' => Http::response('<html>404</html>', 404)]);

        $this->expectException(RuntimeException::class);

        SafeRemoteFetch::contents('https://img.example.test/missing.jpg');
    }

    public function test_the_body_is_capped(): void
    {
        SafeRemoteFetch::limitBytesTo(1000);
        Http::fake(['*' => Http::response(str_repeat('a', 1001), 200)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('larger than');

        SafeRemoteFetch::contents('https://img.example.test/big.jpg');
    }

    public function test_a_body_at_the_cap_is_accepted(): void
    {
        SafeRemoteFetch::limitBytesTo(1000);
        Http::fake(['*' => Http::response(str_repeat('a', 1000), 200)]);

        $this->assertSame(1000, strlen(SafeRemoteFetch::contents('https://img.example.test/ok.jpg')));
    }

    public function test_head_returns_the_content_length_the_importers_read(): void
    {
        Http::fake(['*' => Http::response('', 200, ['Content-Length' => '4321'])]);

        $this->assertSame('4321', SafeRemoteFetch::head('https://img.example.test/p.jpg')->header('Content-Length'));
    }

    // ---------------------------------------------------------------------------------------------
    // the platform's own origin is read from disk, never over the network
    // ---------------------------------------------------------------------------------------------

    public function test_the_platforms_own_assets_are_read_from_disk_and_nothing_is_sent(): void
    {
        config(['app.url' => 'https://shop.example.test']);
        Http::fake();
        $dir = public_path('p1sec06-fixture');
        @mkdir($dir);
        file_put_contents($dir . '/default.png', 'PNGDATA');

        try {
            $this->assertSame('PNGDATA', SafeRemoteFetch::contents('https://shop.example.test/p1sec06-fixture/default.png'));
            $this->assertSame('7', SafeRemoteFetch::head('https://shop.example.test/p1sec06-fixture/default.png')->header('Content-Length'));
            Http::assertNothingSent();
        } finally {
            @unlink($dir . '/default.png');
            @rmdir($dir);
        }
    }

    public static function ownOriginEscapes(): array
    {
        return array_map(fn ($p) => [$p], ['/../.env', '/..%2F.env', '/%2e%2e/%2e%2e/etc/passwd', '/storage/../../.env', '/../composer.json', '/does-not-exist.png', '/index.php/../../.env']);
    }

    /** @dataProvider ownOriginEscapes */
    public function test_the_own_origin_shortcut_cannot_be_used_to_read_outside_the_public_directories(string $path): void
    {
        config(['app.url' => 'https://shop.example.test']);
        // the own host "resolves" to a PUBLIC address and the client would answer, so a fall-through to the network would succeed and be seen
        SafeRemoteFetch::resolveUsing(fn () => [self::PUBLIC_IP]);
        Http::fake(['*' => Http::response('FROM-THE-NETWORK', 200)]);

        $read = null;
        try {
            $read = SafeRemoteFetch::contents('https://shop.example.test' . $path);
        } catch (RuntimeException $e) {
            // expected
        }

        $this->assertNull($read, 'Read ' . $path . ' through the own-origin shortcut.');
        Http::assertNothingSent();
    }

    public function test_a_percent_encoded_file_name_on_the_own_origin_is_found(): void
    {
        config(['app.url' => 'https://shop.example.test']);
        Http::fake();
        $dir = public_path('p1sec06-fixture');
        @mkdir($dir);
        file_put_contents($dir . '/my image.png', 'SPACEDNAME');

        try {
            $this->assertSame('SPACEDNAME', SafeRemoteFetch::contents('https://shop.example.test/p1sec06-fixture/my%20image.png'));
        } finally {
            @unlink($dir . '/my image.png');
            @rmdir($dir);
        }
    }

    /**
     * A numeric host cURL would read as an address (decimal, octal, hex, short forms) must be refused even if the resolver "resolves" it to a
     * PUBLIC address: libcurl normalises such a host to dotted-quad itself and connects there, ignoring the pinned entry.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('numericHostSpellings')]
    public function test_a_numeric_host_spelling_is_refused_whatever_the_resolver_says(string $host): void
    {
        SafeRemoteFetch::resolveUsing(fn () => [self::PUBLIC_IP]);
        Http::fake();

        $refusal = null;
        try {
            SafeRemoteFetch::check('http://' . $host . '/p.jpg');
        } catch (RuntimeException $e) {
            $refusal = $e->getMessage();
        }

        $this->assertStringContainsString('plain host name', (string) $refusal, $host);
    }

    public static function numericHostSpellings(): array
    {
        return array_map(fn ($h) => [$h], ['2130706433', '0x7f.0.0.1', '017700000001', '127.1', '0177.0.0.1', '0x7f000001', '1.2.3', '4294967295', '0x0']);
    }

    // ---------------------------------------------------------------------------------------------
    // the sink every importer shares
    // ---------------------------------------------------------------------------------------------

    private function storageStore(): array
    {
        [$merchant, $store] = $this->makeTenant();
        DB::table('settings')->insert(['name' => 'storage_setting', 'value' => 'local', 'created_by' => $merchant->id, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now()]);
        Storage::fake('theme');
        $this->actingAs($merchant);

        return [$merchant, $store];
    }

    public static function inwardImageUrls(): array
    {
        return array_map(fn ($u) => [$u], ['http://169.254.169.254/latest/meta-data/', 'http://127.0.0.1:8080/', 'http://10.0.0.1/', 'http://192.168.0.1/x.png',
            'http://[::1]/', 'http://localhost/x.png', 'http://evil.example.test/x.png', 'file:///etc/passwd', '/etc/passwd', 'php://filter/resource=/etc/passwd']);
    }

    /** @dataProvider inwardImageUrls */
    public function test_the_upload_sink_writes_nothing_for_an_address_that_points_inward(string $url): void
    {
        $this->storageStore();
        Http::fake(['*' => Http::response('SECRET', 200)]);

        $result = Utility::upload_woo_file($url, 'img.jpg', 'uploads/1');

        $this->assertSame(0, $result['flag']);
        $this->assertArrayNotHasKey('url', $result);
        $this->assertSame([], Storage::disk('theme')->allFiles(), 'A file was written for ' . $url);
        Http::assertNothingSent();
    }

    public function test_the_upload_sink_still_stores_a_public_image(): void
    {
        $this->storageStore();
        Http::fake(['*' => Http::response(self::image('jpeg'), 200)]);

        $result = Utility::upload_woo_file('https://img.example.test/p.jpg', 'img.jpg', 'uploads/1');

        $this->assertSame(1, $result['flag'], $result['msg'] ?? '');
        $this->assertSame(self::image('jpeg'), Storage::disk('theme')->get('uploads/1/img.jpg'));
        $this->assertSame('uploads/1/img.jpg', $result['url']);
    }

    /** A real, minimal image of the given kind (GD), so the content check has something genuine to accept. */
    private static function image(string $kind): string
    {
        $im = imagecreatetruecolor(2, 2);
        ob_start();
        match ($kind) {
            'jpeg' => imagejpeg($im),
            'png' => imagepng($im),
            'gif' => imagegif($im),
            'webp' => imagewebp($im),
            'bmp' => imagebmp($im),
        };

        return (string) ob_get_clean();
    }

    // -- what is WRITTEN: the bytes must be an image and the name must say so ---------------------------------
    //
    // REVIEW FINDING (second read-only review of 7524e844). The sink kept the file name the REMOTE URL chose, extension included, and wrote
    // the bytes under it in the application root -- which is the web root (index.php, .htaccess and theme disk root are all base_path('')),
    // and Apache serves an existing file before the front controller. So a WooCommerce "store" the merchant runs could return
    // `images[0].src = https://attacker.example/x.php`, the guard let the fetch through (a public host), and the bytes landed in
    // uploads/<store>/NN_<time>_x.php -- a file the web server executes. The guard secured WHERE the fetch goes, not WHAT is stored.

    /** @return array<string, array{0: string, 1: string}> a name the remote chose, and the bytes it served */
    public static function hostileUploads(): array
    {
        return [
            'php source under .php' => ['x.php', "<?php system(\$_GET['c']);"],
            'php source under .phtml' => ['x.phtml', '<?php echo 1;'],
            'php source under .jpg' => ['x.jpg', '<?php echo 1;'],
            'html' => ['x.html', '<script>alert(1)</script>'],
            'svg with script' => ['x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'empty body' => ['x.png', ''],
            // recognised by getimagesize() and still refused: only the raster kinds a product page shows are accepted
            'photoshop document' => ['x.png', "8BPS\x00\x01" . str_repeat("\0", 6) . pack('n', 3) . pack('N', 2) . pack('N', 2) . pack('n', 8) . pack('n', 3)],
            'text under an image name' => ['x.png', 'IMAGEBYTES'],
            'htaccess' => ['.htaccess', "AddHandler application/x-httpd-php .png"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostileUploads')]
    public function test_the_upload_sink_stores_only_images(string $name, string $bytes): void
    {
        $this->storageStore();
        Http::fake(['*' => Http::response($bytes, 200)]);

        $result = Utility::upload_woo_file('https://img.example.test/' . $name, '55_1700000000_' . $name, 'uploads/1');

        $this->assertSame(0, $result['flag'], 'Bytes that are not an image were stored: ' . json_encode($result));
        $this->assertSame([], Storage::disk('theme')->allFiles(), 'A file was written for ' . $name);
        // every importer reads these next; an absent key is a 500 in the middle of an import
        $this->assertSame(['url' => '', 'image_path' => '', 'full_url' => ''], array_intersect_key($result, array_flip(['url', 'image_path', 'full_url'])));
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> the name the remote chose, the kind of image served, the extension that must result */
    public static function imageUnderAHostileName(): array
    {
        return [
            'png as .php' => ['55_1_x.php', 'png', 'png'],
            'png as .phtml' => ['55_1_x.phtml', 'png', 'png'],
            'jpeg as .php.jpg' => ['55_1_x.php.jpg', 'jpeg', 'jpg'],
            'png as .php.png' => ['55_1_x.php.png', 'png', 'png'],
            'gif as .php5' => ['55_1_x.php5', 'gif', 'gif'],
            'jpeg with a query string kept in the name' => ['55_1_x.php?v=1.jpg', 'jpeg', 'jpg'],
            'png as .jpg (mislabelled)' => ['55_1_photo.jpg', 'png', 'png'],
            'jpeg as .jpeg (equivalent spelling kept)' => ['55_1_photo.jpeg', 'jpeg', 'jpeg'],
            'png with a directory in the name' => ['55_1_../../x.php', 'png', 'png'],
            'png with no extension' => ['55_1_photo', 'png', 'png'],
            'png named only by an extension' => ['.php', 'png', 'png'],
            'png with a name of nothing but symbols' => ['???', 'png', 'png'],
            'png with a name of backslashes' => ['..\\..\\x.php', 'png', 'png'],
            'webp' => ['55_1_photo.webp', 'webp', 'webp'],
            'bmp' => ['55_1_photo.bmp', 'bmp', 'bmp'],
            'gif' => ['55_1_photo.gif', 'gif', 'gif'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('imageUnderAHostileName')]
    public function test_an_image_is_stored_under_a_name_that_cannot_be_executed(string $chosen, string $kind, string $extension): void
    {
        $this->storageStore();
        Http::fake(['*' => Http::response(self::image($kind), 200)]);

        $result = Utility::upload_woo_file('https://img.example.test/p', $chosen, 'uploads/1');

        $this->assertSame(1, $result['flag'], $result['msg'] ?? '');
        $files = Storage::disk('theme')->allFiles();
        $this->assertCount(1, $files);
        $file = basename($files[0]);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+\.' . $extension . '$/', $file, "'{$chosen}' was stored as '{$file}'.");
        $this->assertSame('uploads/1', dirname($files[0]), 'the file stays in the folder it was given');
        $this->assertSame('uploads/1/' . $file, $result['url'], 'the returned path is the stored one');
        $this->assertSame(self::image($kind), Storage::disk('theme')->get($files[0]));
        $this->assertSame(1, substr_count($file, '.'), 'a stored name has exactly one dot, so no handler mapping can see an inner extension');
    }

    /** The service answers "not an accepted image" with null for a kind PHP recognises but we do not accept -- it does not raise. */
    public function test_a_recognised_but_unaccepted_image_kind_is_answered_with_null(): void
    {
        $psd = "8BPS\x00\x01" . str_repeat("\0", 6) . pack('n', 3) . pack('N', 2) . pack('N', 2) . pack('n', 8) . pack('n', 3);

        $this->assertSame(IMAGETYPE_PSD, getimagesizefromstring($psd)[2], 'premise: PHP recognises it');
        $this->assertNull(\App\Services\ImportedImage::storedName($psd, 'x.png'));
    }

    /** A valid GIF header followed by PHP source is still a GIF to the content check; what protects it is that it is stored as .gif. */
    public function test_a_polyglot_is_stored_only_under_its_image_extension(): void
    {
        $this->storageStore();
        $polyglot = self::image('gif') . "\n<?php system('id'); ?>";
        Http::fake(['*' => Http::response($polyglot, 200)]);

        $result = Utility::upload_woo_file('https://img.example.test/x.php', '55_1_x.php', 'uploads/1');

        $this->assertSame(1, $result['flag'], $result['msg'] ?? '');
        $this->assertStringEndsWith('.gif', $result['url']);
        $this->assertSame([], array_filter(Storage::disk('theme')->allFiles(), fn ($f) => ! str_ends_with($f, '.gif')));
    }

    public function test_the_upload_sink_still_stores_the_bundled_default_image_from_this_servers_own_public_directory(): void
    {
        $this->storageStore();
        config(['app.url' => 'https://shop.example.test']);
        Http::fake();
        $dir = public_path('p1sec06-fixture');
        @mkdir($dir);
        file_put_contents($dir . '/woocommerce.png', self::image('png'));

        try {
            $result = Utility::upload_woo_file('https://shop.example.test/p1sec06-fixture/woocommerce.png', 'img.png', 'uploads/1');

            $this->assertSame(1, $result['flag'], $result['msg'] ?? '');
            $this->assertSame(self::image('png'), Storage::disk('theme')->get('uploads/1/img.png'));
            Http::assertNothingSent();
        } finally {
            @unlink($dir . '/woocommerce.png');
            @rmdir($dir);
        }
    }

    // ---------------------------------------------------------------------------------------------
    // the real route, with the WooCommerce SDK mocked: nothing is created, nothing is written, nothing is sent
    // ---------------------------------------------------------------------------------------------

    private function wooProductPayload(string $imageSrc): array
    {
        return ['id' => 77, 'name' => 'Imported', 'slug' => 'imported-' . uniqid(), 'description' => 'd', 'short_description' => 's', 'regular_price' => '10',
            'sale_price' => '8', 'price' => '8', 'stock_quantity' => 3, 'variations' => [], 'attributes' => [], 'default_attributes' => [],
            'images' => [(object) ['src' => $imageSrc]], 'categories' => [(object) ['id' => 900]]];
    }

    private function productImportFixture(): array
    {
        [$merchant, $store] = $this->storageStore();
        $this->grantPermission($merchant, 'Create Woocommerce Product');
        // the handler reads the creator's plan for the product limit; the two columns without a default are themes and storage_limit
        $plan = \App\Models\Plan::forceCreate(['name' => 'Import Plan', 'price' => 0, 'duration' => 'Unlimited', 'max_stores' => -1, 'max_products' => -1,
            'max_users' => -1, 'themes' => '[]', 'storage_limit' => '-1']);
        $merchant->plan_id = $plan->id;
        $merchant->save();
        $categoryId = DB::table('categories')->insertGetId(['name' => 'c', 'slug' => 'c-' . uniqid(), 'status' => 1, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('woocommerce_conections')->insert(['store_id' => $store->id, 'module' => 'category', 'woocomerce_id' => 900, 'original_id' => $categoryId,
            'created_at' => now(), 'updated_at' => now()]);
        WooCategory::shouldReceive('find')->andReturn(['id' => 900, 'parent' => 0]);

        return [$merchant, $store];
    }

    /** @dataProvider inwardImageUrls */
    public function test_the_product_import_route_refuses_an_inward_image_url_and_creates_nothing(string $url): void
    {
        [$merchant, $store] = $this->productImportFixture();
        WooProduct::shouldReceive('find')->andReturn($this->wooProductPayload($url));
        Http::fake(['*' => Http::response('SECRET', 200, ['Content-Length' => '6'])]);
        $before = DB::table('products')->count();

        $response = $this->actingAs($merchant->fresh())->get('/woocom_product/77');

        // pinned to the guard: the other refusals this handler can produce (permission, product limit, category) do not mention the address
        $response->assertSessionHas('error', fn ($message) => is_string($message) && str_contains($message, 'file address'));
        $this->assertSame($before, DB::table('products')->count(), 'A product was created from an inward image URL.');
        $this->assertSame([], Storage::disk('theme')->allFiles(), 'A file was written.');
        Http::assertNothingSent();
    }

    /**
     * The positive control. It measures what the fix controls -- that the public image WAS fetched and stored -- and not the product row:
     * on SQLite the handler's later `INSERT` fails on `products.track_stock` (it writes NULL into a NOT NULL column that MySQL, with
     * `strict => false`, coerces), which is a pre-existing difference between the test engine and production and not this change.
     */
    public function test_the_product_import_route_still_fetches_and_stores_a_public_image(): void
    {
        [$merchant, $store] = $this->productImportFixture();
        WooProduct::shouldReceive('find')->andReturn($this->wooProductPayload('https://img.example.test/p.jpg'));
        Http::fake(['*' => Http::response(self::image('jpeg'), 200, ['Content-Length' => (string) strlen(self::image('jpeg'))])]);

        $this->actingAs($merchant->fresh())->get('/woocom_product/77');

        $files = Storage::disk('theme')->allFiles();
        $this->assertCount(1, $files, 'Premise: a public image must still be fetched and stored.');
        $this->assertSame(self::image('jpeg'), Storage::disk('theme')->get($files[0]));
        $this->assertStringStartsWith('uploads/' . $store->id . '/', $files[0]);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://img.example.test/p.jpg' && $request->method() === 'GET');
    }

    /** The chain end to end: a "store" that names a .php image and serves PHP leaves nothing executable behind. */
    public function test_the_product_import_route_does_not_store_php_served_as_an_image(): void
    {
        [$merchant, $store] = $this->productImportFixture();
        WooProduct::shouldReceive('find')->andReturn($this->wooProductPayload('https://img.example.test/shell.php'));
        // Content-Length present, as a real server sends it: without one the handler's quota check refuses before the sink, and the
        // test would pass for a reason that is not the control.
        Http::fake(['*' => Http::response('<?php system($_GET["c"]);', 200, ['Content-Length' => '26'])]);
        $before = DB::table('products')->count();

        $this->actingAs($merchant->fresh())->get('/woocom_product/77');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://img.example.test/shell.php' && $request->method() === 'GET'); // premise: the sink was reached
        $this->assertSame([], Storage::disk('theme')->allFiles(), 'PHP source was written under uploads/.');
        $this->assertSame($before, DB::table('products')->count());
    }

    // ---------------------------------------------------------------------------------------------
    // the census: no importer reaches a caller-supplied URL except through SafeRemoteFetch
    // ---------------------------------------------------------------------------------------------

    public function test_no_importer_fetches_a_url_except_through_the_guarded_helper(): void
    {
        $offenders = [];
        $heads = 0;

        foreach (array_merge(glob(app_path('Http/Controllers/Woocom*.php')), glob(app_path('Http/Controllers/Shopify*.php'))) as $file) {
            $source = (string) file_get_contents($file);
            $heads += preg_match_all('/SafeRemoteFetch::head\(/', $source);

            if (preg_match('/Http::(head|get|post|withHeaders|withOptions)\(\s*\$(file_url|url|src)|file_get_contents\(\s*\$(file_url|url|src|request)|\bcopy\(\s*\$(file_url|url|src)/', $source)) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders, 'An importer fetches a caller-supplied URL without App\Services\SafeRemoteFetch.');
        $this->assertSame(15, $heads, 'The number of guarded size probes changed; every importer site must be accounted for.');

        $utility = (string) file_get_contents(app_path('Models/Utility.php'));
        $sink = substr($utility, strpos($utility, 'public static function upload_woo_file'), 4500);
        $this->assertStringContainsString('SafeRemoteFetch::contents($request)', $sink);
        $this->assertStringNotContainsString('file_get_contents($request)', $sink);
    }
}
