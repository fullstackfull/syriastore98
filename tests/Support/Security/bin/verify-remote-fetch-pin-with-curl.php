<?php

/**
 * P1-SEC-06 (review) -- the SafeRemoteFetch pin and size cap, verified against a LIVE socket with the real cURL handler.
 *
 * Precondition (this script states its own, and does nothing without it): `php` can start the built-in server on 127.0.0.1 (a free port,
 * a throwaway docroot in the system temp directory, both removed on exit) and the cURL extension is loaded. No external network is used:
 * the host name is `pin-test.invalid` (a reserved TLD that never resolves), so a request that reaches the server got there ONLY through
 * the CURLOPT_RESOLVE pin.
 *
 *   php tests/Support/Security/bin/verify-remote-fetch-pin-with-curl.php
 *
 * THE FINDING (a read-only review of the first P1-SEC-06 fix, reproduced here). SafeRemoteFetch sent the GET with `'stream' => true`.
 * Guzzle serves a streamed request with its StreamHandler, which resolves the name itself and IGNORES the `curl` option array, so the
 * pin held for HEAD and was absent for the GET that stores the bytes: a second DNS answer (rebinding) could aim it at an internal
 * address after check() had judged the first. A trailing dot (`host.`) did the same for HEAD: check() judged `host`, cURL was asked for
 * `host.` and found no RESOLVE entry. The cap was a read loop over the stream, so removing the stream means cURL must cap it.
 *
 * WHAT IT ESTABLISHES, by execution (each line printed, exit code 1 on any failure):
 *   1. PREMISE: the old shape (`stream => true` + the RESOLVE entry) does NOT reach the pinned address; the same entry without `stream` does;
 *   2. SafeRemoteFetch::requestOptions() -- the options production sends -- reaches the pinned address for HEAD and for GET;
 *   3. a URL with a trailing dot and the RESOLVE entry for the bare host does NOT connect; the URL production rebuilds does;
 *   4. a GET whose body has NO Content-Length and outgrows the cap is aborted by cURL (error 63) and a HEAD of the same resource is not;
 *   5. the REAL resolver (every suite test uses the resolveUsing() seam): a name that does not resolve is refused as "does not resolve", not
 *      as a private address -- `(array) false` is `[false]`, which the first version counted as a denied address.
 */

require __DIR__ . '/../../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\SafeRemoteFetch;
use GuzzleHttp\Client;

if (! extension_loaded('curl')) {
    fwrite(STDERR, "PRECONDITION NOT MET: the curl extension is not loaded.\n");
    exit(2);
}

$docroot = sys_get_temp_dir() . '/verify-pin-' . bin2hex(random_bytes(4));
mkdir($docroot);
file_put_contents($docroot . '/index.html', 'pinned-ok');
file_put_contents($docroot . '/big.php', "<?php header('Content-Type: application/octet-stream'); for (\$i = 0; \$i < 80; \$i++) { echo str_repeat('A', 65536); flush(); }\n");

$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
$port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
fclose($socket);

$server = proc_open(['php', '-S', "127.0.0.1:{$port}", '-t', $docroot], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);

register_shutdown_function(function () use ($server, $docroot) {
    proc_terminate($server);
    foreach (glob($docroot . '/*') as $file) {
        unlink($file);
    }
    rmdir($docroot);
});

for ($i = 0; $i < 50; $i++) {
    if (@fsockopen('127.0.0.1', $port)) {
        break;
    }
    usleep(100000);
}

$failed = 0;
$check = function (string $label, bool $ok, string $detail = '') use (&$failed) {
    printf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail === '' ? '' : "  ({$detail})");
    $failed += $ok ? 0 : 1;
};

$host = 'pin-test.invalid';
$target = ['scheme' => 'http', 'host' => $host, 'port' => $port, 'ip' => '127.0.0.1'];
$client = new Client(['http_errors' => false]);
$try = function (string $method, string $url, array $options) use ($client): string {
    try {
        $response = $client->request($method, $url, $options + ['timeout' => 10]);

        return 'HTTP ' . $response->getStatusCode() . ' ' . strlen((string) $response->getBody()) . 'B';
    } catch (Throwable $e) {
        return get_class($e) . ': ' . substr($e->getMessage(), 0, 70);
    }
};

echo "1. premise: which shape reaches the pinned address\n";
$resolve = ["{$host}:{$port}:127.0.0.1"];
$old = $try('GET', "http://{$host}:{$port}/", ['stream' => true, 'curl' => [CURLOPT_RESOLVE => $resolve]]);
$new = $try('GET', "http://{$host}:{$port}/", ['curl' => [CURLOPT_RESOLVE => $resolve]]);
$check("stream=true + RESOLVE does NOT reach the pinned address (the reviewed defect)", ! str_starts_with($old, 'HTTP 200'), $old);
$check('RESOLVE without stream reaches it', $new === 'HTTP 200 9B', $new);

echo "2. SafeRemoteFetch::requestOptions() -- what production sends\n";
foreach (['HEAD', 'GET'] as $method) {
    $result = $try($method, "http://{$host}:{$port}/", SafeRemoteFetch::requestOptions($target, $method));
    $check("{$method} reaches the pinned address", str_starts_with($result, 'HTTP 200'), $result);
}

echo "3. trailing dot\n";
$dotted = $try('GET', "http://{$host}.:{$port}/", SafeRemoteFetch::requestOptions($target, 'GET'));
$check('a URL that keeps the dot is not matched by the pin (the reviewed defect)', ! str_starts_with($dotted, 'HTTP 200'), $dotted);

$pinnedUrl = new ReflectionMethod(SafeRemoteFetch::class, 'pinnedUrl');
$pinnedUrl->setAccessible(true);
$rebuilt = $pinnedUrl->invoke(null, "http://{$host}.:{$port}/index.html", $target);
$result = $try('GET', $rebuilt, SafeRemoteFetch::requestOptions($target, 'GET'));
$check("the URL production rebuilds ({$rebuilt}) is pinned", $result === 'HTTP 200 9B', $result);

echo "4. size cap on a body with no Content-Length\n";
SafeRemoteFetch::limitBytesTo(1000000);
$get = $try('GET', "http://{$host}:{$port}/big.php", SafeRemoteFetch::requestOptions($target, 'GET'));
$check('a 5 MB chunked body is aborted by cURL at the 1 MB cap', str_contains($get, 'cURL error 63'), $get);
$head = $try('HEAD', "http://{$host}:{$port}/big.php", SafeRemoteFetch::requestOptions($target, 'HEAD'));
$check('a HEAD of the same resource is not capped', str_starts_with($head, 'HTTP 200'), $head);
SafeRemoteFetch::limitBytesTo(null);
$under = $try('GET', "http://{$host}:{$port}/big.php", SafeRemoteFetch::requestOptions($target, 'GET'));
$check('under the default 20 MB cap the same body is delivered', $under === 'HTTP 200 5242880B', $under);

echo "5. the real resolver\n";
SafeRemoteFetch::resolveUsing(null);
try {
    SafeRemoteFetch::check('http://no-such-host.invalid/x.png');
    $check('an unresolvable name is refused', false, 'not refused');
} catch (RuntimeException $e) {
    $check('an unresolvable name is refused as not resolving', str_contains($e->getMessage(), 'does not resolve'), $e->getMessage());
}

echo $failed === 0 ? "\nALL CHECKS PASSED\n" : "\n{$failed} CHECK(S) FAILED\n";
exit($failed === 0 ? 0 : 1);
