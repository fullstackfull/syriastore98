<?php

namespace App\Services;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Fetch a URL a CALLER supplied without letting the caller aim the server at its own network.  P1-SEC-06.
 *
 * The WooCommerce and Shopify importers take an image URL out of the response of the merchant's OWN remote store -- a URL the merchant
 * controls completely -- and fetched it (HEAD for the size, then `file_get_contents`), wrote the bytes under uploads/ and served them back
 * as the cover image: an exfiltrating server-side request forgery (`http://169.254.169.254/...`, any internal HTTP service).
 *
 * What this refuses, before a byte is requested and again on every redirect hop:
 *   - any scheme but http/https, a URL with user-info, a port outside 80/443/8080/8443;
 *   - a host that is not a plain DNS name or a canonical IP literal (`2130706433`, `0x7f.1`, `017700000001` are all 127.0.0.1 to cURL);
 *   - a name that resolves, on ANY of its A/AAAA records, to a loopback, private, link-local, metadata, CGNAT, documentation, multicast or
 *     reserved address, including IPv4 hidden in IPv6 (`::ffff:127.0.0.1`, 6to4, NAT64).
 * The connection is PINNED to the address that was checked (CURLOPT_RESOLVE, on the cURL handler for HEAD and GET alike -- see
 * requestOptions() for why not a streamed request), so a second DNS answer cannot redirect it, redirects are followed by hand and each hop
 * is checked, and the body is capped at MAX_BYTES (CURLOPT_MAXFILESIZE, then the length of what was kept).
 *
 * The platform's OWN origin (config app.url) is never fetched over the network: the importers use `asset(...)` of a bundled default image,
 * which is served from public/ -- that is read from disk, confined to public/ and storage/app/public.
 *
 * A merchant whose WooCommerce store is on a PRIVATE network can no longer have its images fetched; the refusal says why. Allowing a
 * private host would be an owner decision (an allowlist), not a default.
 */
final class SafeRemoteFetch
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    private const MAX_REDIRECTS = 3;
    private const PORTS = [80, 443, 8080, 8443];

    /** @var null|callable(string): list<string> test seam: the addresses a host resolves to */
    private static $resolver = null;

    /** test seam: a smaller body cap, so the cap can be exercised without a 20 MB string */
    private static ?int $maxBytes = null;

    public static function resolveUsing(?callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    public static function limitBytesTo(?int $bytes): void
    {
        self::$maxBytes = $bytes;
    }

    /** HEAD, validated; the returned response carries the final hop's headers (Content-Length is what the importers read). */
    public static function head(string $url): Response
    {
        $url = str_replace("\0", '', trim($url));

        if (($local = self::localFile($url)) !== null) {
            return new Response(new PsrResponse(200, ['Content-Length' => (string) filesize($local)]));
        }

        return self::send('HEAD', $url, false);
    }

    /** GET, validated, at most MAX_BYTES. */
    public static function contents(string $url): string
    {
        $url = str_replace("\0", '', trim($url));

        if (($local = self::localFile($url)) !== null) {
            return (string) file_get_contents($local);
        }

        $body = self::send('GET', $url, true)->body();

        if (strlen($body) > self::cap()) {
            throw new RuntimeException('The remote file is larger than ' . round(self::cap() / 1048576, 1) . ' MB.');
        }

        return $body;
    }

    /**
     * Validate a URL and return what the connection must be pinned to.
     *
     * @param  bool  $anyPort  a caller that talks to a PUBLIC address the user chose (a webhook receiver on :5678) may use any port; the private
     *                         ranges, the scheme and the host spelling are judged exactly as for the image fetch
     *
     * @return array{scheme: string, host: string, port: int, ip: string}
     *
     * @throws RuntimeException
     */
    public static function check(string $url, bool $anyPort = false): array
    {
        $parts = parse_url($url);

        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new RuntimeException('The file address is not a valid URL.');
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new RuntimeException('Only http and https file addresses are accepted.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('A file address with credentials in it is not accepted.');
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if (! $anyPort && ! in_array($port, self::PORTS, true)) {
            throw new RuntimeException('The file address uses a port that is not accepted.');
        }

        $host = strtolower(rtrim($parts['host'], '.'));

        if ($host === '') {
            throw new RuntimeException('The file address is not a valid URL.');
        }

        if (str_starts_with($host, '[')) {
            // an address that is not a valid IPv6 literal fails inet_pton() in isDeniedIp(), which counts it as denied
            $addresses = [trim($host, '[]')];
        } elseif (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $addresses = [$host];
        } elseif (preg_match('/^(0x[0-9a-f]*|[0-9]+)(\.(0x[0-9a-f]*|[0-9]+))*$/i', $host) === 1) {
            // a numeric spelling cURL would read as an address (decimal, octal, hex, short forms) but filter_var did not accept
            throw new RuntimeException('The file address is not a plain host name.');
        } elseif (preg_match('/^(?=.{1,253}$)([a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?\.)*[a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?$/', $host) === 1) {
            $addresses = self::resolve($host);

            if ($addresses === []) {
                throw new RuntimeException('The file address does not resolve.');
            }
        } else {
            throw new RuntimeException('The file address is not a plain host name.');
        }

        foreach ($addresses as $address) {
            if (self::isDeniedIp($address)) {
                throw new RuntimeException('The file address points at a private or reserved network address, which is not accepted.');
            }
        }

        return ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'ip' => $addresses[0]];
    }

    public static function isDeniedIp(string $ip): bool
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return true;
        }

        if (strlen($packed) === 16) {
            // IPv4 carried inside IPv6: mapped (::ffff:a.b.c.d), compatible (::a.b.c.d), NAT64 (64:ff9b::/96), 6to4 (2002:aabb:ccdd::)
            if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff") || str_starts_with($packed, str_repeat("\0", 12))) {
                return self::isDeniedIp(inet_ntop(substr($packed, 12)));
            }

            if (str_starts_with($packed, "\x00\x64\xff\x9b" . str_repeat("\0", 8))) {
                return self::isDeniedIp(inet_ntop(substr($packed, 12)));
            }

            if (str_starts_with($packed, "\x20\x02")) {
                return self::isDeniedIp(inet_ntop(substr($packed, 2, 4)));
            }

            foreach ([['::', 128], ['::1', 128], ['fc00::', 7], ['fe80::', 10], ['fec0::', 10], ['ff00::', 8], ['2001:db8::', 32], ['2001::', 32], ['100::', 64]] as [$net, $bits]) {
                if (self::inRange($packed, inet_pton($net), $bits)) {
                    return true;
                }
            }

            return false;
        }

        foreach ([['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16], ['172.16.0.0', 12], ['192.0.0.0', 24],
                  ['192.0.2.0', 24], ['192.88.99.0', 24], ['192.168.0.0', 16], ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24],
                  ['224.0.0.0', 4], ['240.0.0.0', 4]] as [$net, $bits]) {
            if (self::inRange($packed, inet_pton($net), $bits)) {
                return true;
            }
        }

        return false;
    }

    private static function inRange(string $packed, string $net, int $bits): bool
    {
        $bytes = intdiv($bits, 8);

        if (substr($packed, 0, $bytes) !== substr($net, 0, $bytes)) {
            return false;
        }

        $rest = $bits % 8;

        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        if (self::$resolver !== null) {
            return array_values((self::$resolver)($host));
        }

        return self::addressesFrom(@gethostbynamel($host), @dns_get_record($host, DNS_AAAA));
    }

    /**
     * The addresses a lookup produced, from the raw answers of gethostbynamel() and dns_get_record().
     *
     * Both answer `false` for a name with no such record, and `(array) false` is `[false]` -- which isDeniedIp() counts as denied, so a host
     * with no A record (an IPv6-only store) was refused as "private or reserved" and the "does not resolve" refusal could never fire.
     *
     * @param  mixed  $ipv4  list<string>|false
     * @param  mixed  $ipv6  list<array{ipv6?: string}>|false
     * @return list<string>
     */
    public static function addressesFrom(mixed $ipv4, mixed $ipv6): array
    {
        $addresses = [];

        foreach (is_array($ipv4) ? $ipv4 : [] as $ip) {
            if (is_string($ip) && $ip !== '') {
                $addresses[] = $ip;
            }
        }

        foreach (is_array($ipv6) ? $ipv6 : [] as $record) {
            if (is_array($record) && ! empty($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return $addresses;
    }

    /** @param bool $requireSuccess a GET must end on a 2xx; a HEAD answers whatever the server said, as Http::head() always did */
    private static function send(string $method, string $url, bool $requireSuccess): Response
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $target = self::check($url);
            $url = self::pinnedUrl($url, $target);

            try {
                $response = Http::withOptions(self::requestOptions($target, $method))->send($method, $url);
            } catch (ConnectionException $e) {
                throw new RuntimeException('The remote file could not be fetched.', 0, $e);
            } catch (GuzzleException $e) {
                // CURLOPT_MAXFILESIZE aborting a body that outgrew the cap surfaces here, as cURL error 63
                throw new RuntimeException(str_contains($e->getMessage(), 'cURL error 63')
                    ? 'The remote file is larger than ' . round(self::cap() / 1048576, 1) . ' MB.'
                    : 'The remote file could not be fetched.', 0, $e);
            }

            $status = $response->status();

            if ($status >= 300 && $status < 400 && $response->header('Location') !== '') {
                $url = self::absolute($url, $response->header('Location'));

                continue;
            }

            if ($requireSuccess && ($status < 200 || $status >= 300)) {
                throw new RuntimeException('The remote file could not be fetched (HTTP ' . $status . ').');
            }

            return $response;
        }

        throw new RuntimeException('The remote file redirects too many times.');
    }

    /**
     * The client options for ONE hop, for both methods.
     *
     * NOT `'stream' => true`: Guzzle sends a streamed request through its StreamHandler, which resolves the name itself and ignores the
     * `curl` array, so the pin below held for HEAD and was silently absent for the GET that stores the bytes -- a second DNS answer could
     * aim it anywhere (measured: with stream=true and a name that does not resolve the connection is refused; with stream=false the
     * CURLOPT_RESOLVE entry is honoured). The size cap is therefore enforced by cURL itself (CURLOPT_MAXFILESIZE aborts mid-transfer with
     * cURL error 63, also when the response carries no Content-Length), and again on the body that was kept.
     *
     * @param  array{scheme: string, host: string, port: int, ip: string}  $target
     * @return array<string, mixed>
     */
    public static function requestOptions(array $target, string $method): array
    {
        $curl = [CURLOPT_RESOLVE => [self::resolveEntry($target)]];

        if ($method === 'GET') {
            $curl[CURLOPT_MAXFILESIZE] = self::cap();
        }

        return [
            'allow_redirects' => false,
            'timeout' => 15,
            'connect_timeout' => 5,
            'curl' => $curl,
        ];
    }

    /**
     * The CURLOPT_RESOLVE entry that pins a connection to the address check() judged.
     *
     * @param  array{scheme: string, host: string, port: int, ip: string}  $target
     */
    public static function resolveEntry(array $target): string
    {
        return sprintf('%s:%d:%s', $target['host'], $target['port'], str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip']);
    }

    /**
     * The URL cURL is asked for. The pin names the host as check() normalised it (lower case, no trailing dot) and cURL matches the name
     * it was given, so `https://cdn.example.com./x` -- which check() judged as `cdn.example.com` -- would have bypassed the pin and been
     * resolved again. The request is built from the normalised parts so the two cannot differ.
     *
     * @param  array{scheme: string, host: string, port: int, ip: string}  $target
     */
    public static function pinnedUrl(string $url, array $target): string
    {
        $parts = parse_url($url);

        return $target['scheme'] . '://' . $target['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . ($parts['path'] ?? '/')
            . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    private static function cap(): int
    {
        return self::$maxBytes ?? self::MAX_BYTES;
    }

    private static function absolute(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location) === 1) {
            return $location;                                  // absolute: check() judges the scheme
        }

        $parts = parse_url($base);
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        if (str_starts_with($location, '//')) {
            return $parts['scheme'] . ':' . $location;
        }

        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        return $origin . rtrim(dirname($parts['path'] ?? '/'), '/') . '/' . $location;
    }

    /** A URL on THIS application's own origin, mapped to a file under public/ or storage/app/public; null for any other URL. */
    private static function localFile(string $url): ?string
    {
        $own = parse_url((string) config('app.url'));
        $parts = parse_url($url);

        if (! is_array($own) || ! is_array($parts) || empty($own['host']) || empty($parts['host']) || empty($parts['path'])) {
            return null;
        }

        if (strtolower($own['host']) !== strtolower($parts['host']) || strtolower($own['scheme'] ?? 'http') !== strtolower($parts['scheme'] ?? '')) {
            return null;
        }

        $relative = ltrim(rawurldecode($parts['path']), '/');

        if (str_contains($relative, "\0")) {
            // realpath() raises a ValueError (not an Exception) on a NUL byte, which the importers' catch (\Exception) would miss
            throw new RuntimeException('The file address is not available on this server.');
        }

        foreach ([public_path($relative), storage_path('app/public/' . preg_replace('#^storage/#', '', $relative))] as $candidate) {
            $real = realpath($candidate);

            foreach ([public_path(), storage_path('app/public')] as $root) {
                $rootReal = realpath($root);

                if ($real !== false && $rootReal !== false && is_file($real) && str_starts_with($real, $rootReal . DIRECTORY_SEPARATOR)) {
                    return $real;
                }
            }
        }

        throw new RuntimeException('The file address is not available on this server.');
    }
}
