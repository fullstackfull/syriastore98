<?php

/**
 * P1-SEC-75 -- the allowlist sanitizer behind the Helpdesk views, run through a REAL BROWSER.
 *
 * Precondition (this script states its own): `node` on the PATH and the `playwright` package with its Chromium installed (`require('playwright')` has to resolve; the pre-installed one
 * in the cloud container does). It starts a local headless Chromium and loads strings into about:blank; every network request the page makes is aborted inside the browser. It contacts
 * no host, and it must not be pointed at one.
 *
 *   php tests/Support/Security/bin/verify-rich-text-in-chromium.php [count=3000] [seed=7]
 *
 * WHY A BROWSER. A sanitizer is judged by what a browser does with its output, not by what the sanitizer believes it wrote. The unit test (tests/Unit/Support/Html/RichTextTest) shows the
 * contract of the output through PHP's own parser; this shows that Chromium, which parses HTML the way users' browsers do, builds exactly that and nothing else, that nothing it builds
 * opens a dialog, navigates or asks for a URL, and that the output survives being read back out of the DOM and put in again unchanged (the way mutation XSS gets in).
 *
 * THE CORPUS is Tests\Support\Security\RichTextCorpus: the hand-written evasions plus `count` seeded random tag soups. The same inputs are loaded RAW first -- the negative control: in the
 * run recorded in the work queue (P1-SEC-75, `3000 7`) 852 of 3,000 raw inputs opened a real dialog and 1,384 created an active element, so the corpus is not vacuous.
 *
 * THE DETECTORS CAN FAIL: before the corpus, the harness runs its own self-test (`--self-test`): known-bad outputs, one per detector, each of which must be flagged, and a clean control, which
 * must not be. If that fails the run stops: a harness whose detectors cannot fail proves nothing about a clean result. (The sanitizer re-writes almost every hostile input, so "the sanitizer
 * changed N inputs" is not evidence of anything and is not printed.)
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use App\Support\Html\RichText;
use Tests\Support\Security\RichTextCorpus;

$count = max(100, (int) ($argv[1] ?? 3000));
$seed = (int) ($argv[2] ?? 7);

$probe = shell_exec('node -e "require(\'playwright\'); console.log(\'ok\')" 2>&1');
if (trim((string) $probe) !== 'ok') {
    fwrite(STDERR, "REFUSING: `node` with the `playwright` package is not available here:\n{$probe}\n");
    exit(2);
}

$inputs = array_merge(RichTextCorpus::hostile(), RichTextCorpus::soup($count - count(RichTextCorpus::hostile()), $seed));
$rows = [];
foreach ($inputs as $in) {
    $rows[] = ['in' => $in, 'out' => RichText::clean($in)];
}

$file = tempnam(sys_get_temp_dir(), 'richtext-corpus-') . '.json';
file_put_contents($file, json_encode($rows, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE));

echo "P1-SEC-75 -- RichText::clean() through headless Chromium\n" . str_repeat('-', 78) . "\n";

$selfTest = 0;
passthru('node ' . escapeshellarg(__DIR__ . '/rich-text-browser-run.cjs') . ' --self-test', $selfTest);
if ($selfTest !== 0) {
    fwrite(STDERR, "\nREFUSING: the harness's detectors failed their own self-test, so a clean result from them would mean nothing.\n");
    @unlink($file);
    exit(3);
}

echo "\n  " . count($rows) . " inputs (" . count(RichTextCorpus::hostile()) . " hand-written, seed {$seed})\n\n";

$status = 0;
passthru('node ' . escapeshellarg(__DIR__ . '/rich-text-browser-run.cjs') . ' ' . escapeshellarg($file), $status);
@unlink($file);

echo "\n  " . ($status === 0 ? '[ OK ] the sanitized side did nothing: no dialog, no navigation, no popup, no request, nothing off the lists, stable under an innerHTML round trip' : '[FAIL] the sanitized output did something (see the lines above)') . "\n";
exit($status === 0 ? 0 : 1);
