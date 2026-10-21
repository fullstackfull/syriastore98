<?php

namespace App\Services;

use GuzzleHttp\Psr7\Response as PsrResponse;
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
 * The connection is PINNED to the address that was checked (CURLOPT_RESOLVE), so a second DNS answer cannot redirect it, redirects are
 * followed by hand and each hop is checked, and the body is read up to MAX_BYTES.
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

        return self::send('HEAD', $url);
    }

    /** GET, validated, at most MAX_BYTES. */
    public static function contents(string $url): string
    {
        $url = str_replace("\0", '', trim($url));

        if (($local = self::localFile($url)) !== null) {
            return (string) file_get_contents($local);
        }

        $response = self::send('GET', $url);
        $stream = $response->toPsrResponse()->getBody();
        $body = '';

        while (! $stream->eof()) {
            $body .= $stream->read(65536);

            if (strlen($body) > (self::$maxBytes ?? self::MAX_BYTES)) {
                throw new RuntimeException('The remote file is larger than ' . round((self::$maxBytes ?? self::MAX_BYTES) / 1048576, 1) . ' MB.');
            }
        }

        return $body;
    }

    /**
     * Validate a URL and return what the connection must be pinned to.
     *
     * @return array{scheme: string, host: string, port: int, ip: string}
     *
     * @throws RuntimeException
     */
    public static function check(string $url): array
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

        if (! in_array($port, self::PORTS, true)) {
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

        $addresses = [];

        foreach ((array) @gethostbynamel($host) as $ip) {
            $addresses[] = $ip;
        }

        foreach ((array) @dns_get_record($host, DNS_AAAA) as $record) {
            if (! empty($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return $addresses;
    }

    private static function send(string $method, string $url): Response
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $target = self::check($url);

            $response = Http::withOptions([
                'allow_redirects' => false,
                'stream' => $method === 'GET',
                'timeout' => 15,
                'connect_timeout' => 5,
                'curl' => [CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $target['host'], $target['port'], str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'])]],
            ])->send($method, $url);

            $status = $response->status();

            if ($status >= 300 && $status < 400 && $response->header('Location') !== '') {
                $url = self::absolute($url, $response->header('Location'));

                continue;
            }

            if ($status < 200 || $status >= 300) {
                throw new RuntimeException('The remote file could not be fetched (HTTP ' . $status . ').');
            }

            return $response;
        }

        throw new RuntimeException('The remote file redirects too many times.');
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
