#!/usr/bin/env php
<?php

/**
 * P1-SEC-06 acceptance 3 — WHAT CURLOPT_FOLLOWLOCATION ACTUALLY DOES ON THIS LIBCURL.
 *
 * WHY THIS IS A SCRIPT AND NOT A TEST. The claim this criterion rests on is behavioural: that a
 * response from a valid `*.myshopify.com` host could walk this server to a private address, and that
 * the merchant's `X-Shopify-Access-Token` went with it. Proving that needs a host that answers with a
 * redirect, and the only such host available in a container is loopback — which
 * `SafeRemoteFetch::check()` refuses, correctly, as the EARLIER control. So the suite can assert the
 * option and the census (ImportStoreUrlSsrfTest) and this measures the option's effect directly,
 * below `SafeRemoteFetch`, with cURL and two throwaway servers of its own.
 *
 * It asserts nothing about Shopify. It asserts what libcurl does with these options, which is the
 * part the fix depends on and the part that was taken on trust.
 *
 *     php tests/Support/Security/bin/verify-sec06-shopify-redirect.php
 *
 * PRECONDITIONS, stated rather than assumed: the `curl` extension, the PHP CLI binary (it starts
 * `php -S` itself), and two free loopback ports. It binds only to 127.0.0.1, writes only inside its
 * own temporary directory, and removes both when it is done. It makes no outbound connection.
 */

final class Probe
{
    private array $procs = [];

    private string $dir;

    public int $failures = 0;

    public function __construct()
    {
        $this->dir = sys_get_temp_dir() . '/sec06-' . getmypid();
        mkdir($this->dir . '/redirector', 0700, true);
        mkdir($this->dir . '/target', 0700, true);
    }

    public function say(string $line): void
    {
        fwrite(STDOUT, $line . "\n");
    }

    public function check(string $what, bool $ok, string $detail = ''): void
    {
        $this->say(sprintf('  [%s] %s%s', $ok ? ' OK ' : 'FAIL', $what, $detail === '' ? '' : '  -- ' . $detail));

        if (! $ok) {
            $this->failures++;
        }
    }

    /** A server whose document root is one php file; returns its port. */
    private function serve(string $folder, string $php): int
    {
        file_put_contents($this->dir . '/' . $folder . '/index.php', $php);

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $port = (int) explode(':', (string) stream_socket_get_name($socket, false))[1];
        fclose($socket);

        $descriptors = [1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']];
        $this->procs[] = proc_open(
            sprintf('exec php -S 127.0.0.1:%d -t %s', $port, escapeshellarg($this->dir . '/' . $folder)),
            $descriptors, $pipes
        );

        for ($i = 0; $i < 100; $i++) {
            $probe = @stream_socket_client('tcp://127.0.0.1:' . $port, $e, $s, 0.2);

            if ($probe !== false) {
                fclose($probe);

                return $port;
            }

            usleep(50_000);
        }

        $this->say('  could not start a local server on port ' . $port);
        exit(2);
    }

    public function run(): int
    {
        $this->say('P1-SEC-06 acceptance 3 -- CURLOPT_FOLLOWLOCATION, measured. curl ' . (curl_version()['version'] ?? '?'));

        $log = $this->dir . '/target/received.txt';

        $targetPort = $this->serve('target', '<?php file_put_contents(' . var_export($log, true)
            . ', json_encode(array_change_key_case(getallheaders())) . PHP_EOL, FILE_APPEND); echo "REACHED-THE-TARGET";');

        $redirectPort = $this->serve('redirector', '<?php if (($_GET["slow"] ?? "") === "1") { sleep(3); echo "SLOW-BODY"; exit; }'
            . ' header("Location: http://127.0.0.1:' . $targetPort . '/", true, 302); echo "REDIRECT-BODY";');

        $this->say('');
        $this->say('1. the SHIPPED options, as all thirteen call sites had them (FOLLOWLOCATION => true, MAXREDIRS => 10, TIMEOUT => 0)');

        @unlink($log);
        [$body, $status, $effective] = $this->fetch('http://127.0.0.1:' . $redirectPort . '/', [
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 10, CURLOPT_TIMEOUT => 0,
        ]);

        $this->check('the redirect IS followed to the second host', $body === 'REACHED-THE-TARGET',
            'body=' . var_export($body, true) . ' status=' . $status . ' effective=' . $effective);
        $this->check('and the second host was really reached', is_file($log));

        $received = is_file($log) ? (array) json_decode((string) file_get_contents($log), true) : [];
        $this->check('AND THE MERCHANT TOKEN WAS SENT TO IT', ($received['x-shopify-access-token'] ?? null) === 'SENTINEL-MERCHANT-TOKEN',
            'this is the credential-leak half of the finding, and it is why refusing the redirect is worth more than re-validating each hop');

        $this->say('');
        $this->say('2. the FIXED options (FOLLOWLOCATION => false, MAXREDIRS => 0)');

        @unlink($log);
        [$body, $status] = $this->fetch('http://127.0.0.1:' . $redirectPort . '/', [
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
        ]);

        $this->check('the 302 is returned to the caller instead', $status === 302, 'status=' . $status);
        $this->check('the second host is NOT reached', ! is_file($log));
        $this->check('so no token can be forwarded anywhere', ! is_file($log));

        $this->say('');
        $this->say('3. the deadline. CURLOPT_TIMEOUT => 0 is NO deadline, which is what the call sites had.');

        $slow = 'http://127.0.0.1:' . $redirectPort . '/?slow=1';

        [$body, , , $errno] = $this->fetch($slow, [CURLOPT_TIMEOUT => 1]);
        $this->check('a 1-second deadline aborts a 3-second response', $errno === CURLE_OPERATION_TIMEDOUT,
            'curl errno=' . $errno);

        [$body, , , $errno] = $this->fetch($slow, [CURLOPT_TIMEOUT => 0]);
        $this->check('TIMEOUT => 0 waits for it instead', $errno === 0 && $body === 'SLOW-BODY',
            'curl errno=' . $errno . ' body=' . var_export($body, true));

        $this->say('');
        $this->say($this->failures === 0
            ? 'ALL ASSERTIONS HELD. The option is the control, the token did travel, and 0 is not a timeout.'
            : $this->failures . ' ASSERTION(S) FAILED.');

        return $this->failures === 0 ? 0 : 1;
    }

    /** @return array{0: string|false, 1: int, 2: string, 3: int} */
    private function fetch(string $url, array $options): array
    {
        $curl = curl_init();
        curl_setopt_array($curl, $options + [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => ['X-Shopify-Access-Token: SENTINEL-MERCHANT-TOKEN'],
        ]);

        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $effective = (string) curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
        $errno = curl_errno($curl);
        curl_close($curl);

        return [$body, $status, $effective, $errno];
    }

    public function __destruct()
    {
        foreach ($this->procs as $proc) {
            if (is_resource($proc)) {
                proc_terminate($proc, SIGKILL);
                proc_close($proc);
            }
        }

        foreach (['redirector', 'target'] as $folder) {
            foreach ((array) glob($this->dir . '/' . $folder . '/*') as $file) {
                @unlink((string) $file);
            }
            @rmdir($this->dir . '/' . $folder);
        }
        @rmdir($this->dir);
    }
}

exit((new Probe())->run());
