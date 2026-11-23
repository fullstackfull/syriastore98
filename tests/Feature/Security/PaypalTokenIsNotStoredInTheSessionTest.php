<?php

namespace Tests\Feature\Security;

/**
 * SEC-SESS PART D -- A PAYPAL ACCESS TOKEN WAS BEING WRITTEN INTO THE SESSION, WITH NOTHING
 * READING IT BACK.
 * =================================================================================================
 *
 * `PaymentService::processPayPal` called `$provider->getAccessToken()` and put the resulting
 * `access_token` straight into the session. Measured before removing it:
 *
 *  - ONE first-party write site, ZERO readers. A repo-wide search for the key matched that write,
 *    the two vendored twins below, this file, and one unrelated Braintree search field. Nothing
 *    reads it. The one hole a literal grep leaves -- a dynamically composed key -- is closed too:
 *    the parameterised session read in `GuardsGatewayReturn` is only ever passed literals, and
 *    none of them is this key. So deleting the write removes no behaviour.
 *  - THE `getAccessToken()` CALL STAYS, and that is not an oversight. It is not a pure getter:
 *    it calls `setAccessToken()`, which sets the provider's `Authorization` request header, and
 *    `createOrder()` two lines later depends on that header. Only the assignment was dead, and a
 *    dead variable holding a live bearer token in scope through a long method is what invites the
 *    next person to "use" it.
 *  - WHERE IT LANDED. `config/session.php` defaults to the `database` driver with
 *    `'encrypt' => env('SESSION_ENCRYPT', false)`. Both are env-driven, so unless a deployment
 *    overrides them the value sat readable in `sessions.payload`. Stated as the config default,
 *    not as a fact about any deployment -- which is what an earlier draft of this docblock did.
 *  - AND IT WAS ONE FLAG FROM BEING EGRESS. `larabug` puts `Session::all()` into its outbound
 *    exception payload, filtered by key-name globs -- and `paypal_payment_id` matches none of the
 *    31 globs in `config/larabug.php`, because the key's name never says "token". That is the
 *    whole trouble with naming a bearer `*_payment_id`. It is LATENT, not live: `ScrubbedLaraBug`
 *    gates all egress behind `larabug.outbound_enabled`, false by owner decision P1-SEC-14. The
 *    condition under which it would have become egress is `LARABUG_OUTBOUND_ENABLED=true`.
 *
 * WHY THIS PINS THE CREDENTIAL AND NOT THE KEY NAME. An earlier draft flagged any line containing
 * `paypal_payment_id`, and review showed that is the wrong proposition in BOTH directions.
 * `<gateway>_payment_id` is this codebase's convention for a NON-secret gateway reference that IS
 * read back -- `MolliePaymentController` writes `mollie_payment_id` and reads it three times and
 * forgets it. The PayPal line was that pattern with a bearer substituted for an order id, which is
 * exactly why it had no reader. So a name-based pin would have FAILED a future correct change
 * (someone implementing PayPal return verification and storing the order id, mirroring Mollie) and
 * would have flagged a legitimate `Session::forget()` cleanup of legacy rows -- while still being
 * evadable by `Session::put('paypal_oauth', $token['access_token'])`. The rule asserted here is
 * the one that matters: no session WRITE in `app/` carries a credential.
 *
 * WHAT THE NAME SAYS IS WHAT IS MEASURED. The two vendored twins,
 * `packages/workdo/Donation/.../DonationPaymentService.php:418` and
 * `packages/workdo/LotteryPrizes/.../LotteryPaymentService.php:418`, still perform this write
 * whenever those modules are active. `packages/workdo/` must not be edited (CLAUDE.md §2), and
 * asserting over it would turn an owner item into a red build -- so the scope is `app/`, the
 * method name says `app/`, and the twins are a row in `docs/security/P01-SECURITY-WORK-QUEUE.md`
 * rather than a sentence in a test comment. A deferral recorded only in a comment is not a
 * disposition (CLAUDE.md §4.1), which an earlier draft of this file got wrong.
 *
 * COMMENTS ARE STRIPPED WITH `token_get_all()`, NOT WITH THE SHARED REGEX TRAIT, and that is
 * deliberate. `Tests\Support\StripsSourceComments` removes block comments with a lookbehind that
 * does not exclude `}`, so a PHP string containing `}` followed by `/` and `*` -- for instance
 * `File::glob("{$dir}/*.php")` in `app/Http/Middleware/XSS.php` -- opens a false comment that runs
 * to the next comment terminator. Measured: 45 lines of that file disappear, real code among
 * them. The trait is
 * fine for a single known file and unsound for a whole-tree scan, so this census uses the exact
 * tokeniser the sibling censuses use, which also gives TRUE line numbers -- a regex that deletes
 * comments also deletes their newlines, so offsets computed from stripped text point a reader at
 * the wrong place.
 */
class PaypalTokenIsNotStoredInTheSessionTest extends SecurityTestCase
{
    /** A write form paired with a credential on the same line is the thing being forbidden. */
    private const WRITE_FORMS = ['Session::put(', 'session()->put(', 'session([', '->session()->put('];

    private const CREDENTIAL_HINTS = ['access_token', 'accessToken', 'paypalToken', '_secret', 'client_secret'];

    public function test_nothing_under_app_writes_a_credential_into_the_session(): void
    {
        $base = dirname(__DIR__, 3);
        $scanned = 0;
        $offenders = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base . '/app', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $scanned++;
            $relative = ltrim(str_replace($base, '', $file->getPathname()), '/');

            foreach ($this->codeLines((string) file_get_contents($file->getPathname())) as $number => $line) {
                if ($this->isCredentialSessionWrite($line)) {
                    $offenders[$relative . ':' . $number] = trim($line);
                }
            }
        }

        /* THE PREMISE, which this directory's other censuses all assert and an earlier draft of
           this one omitted. With no floor, a wrong path or an unreadable tree yields an empty
           offender list and `assertSame([], [])` passes -- PHPUnit does not even mark it risky,
           because an assertion did run. */
        $this->assertGreaterThan(200, $scanned,
            "app/ was not scanned -- this census is vacuous. Files seen: {$scanned}.");

        $this->assertSame([], $offenders,
            "A credential is being written into the session. `config/session.php` leaves the "
            . "payload unencrypted by default and `larabug` would carry `Session::all()` outbound "
            . "if its egress flag were ever turned on:\n  "
            . implode("\n  ", array_map(
                fn ($k, $v) => $k . '  ' . $v,
                array_keys($offenders),
                $offenders
            )));
    }

    /**
     * THE POSITIVE CONTROL. Without it the detector could be broken in a way that reports nothing
     * and the census above would read as "clean" -- the shape this repository has been caught by
     * repeatedly. The fixture is the deleted line verbatim.
     */
    public function test_the_detector_recognises_the_line_that_was_removed(): void
    {
        $this->assertTrue(
            $this->isCredentialSessionWrite("        Session::put('paypal_payment_id', \$paypalToken['access_token']);"),
            'The detector does not flag the exact line this slice removed, so the census above '
            . 'proves nothing.'
        );

        // And it must NOT flag the house pattern for a non-secret, read-back gateway reference,
        // or it would fail a correct future change.
        $this->assertFalse(
            $this->isCredentialSessionWrite("                session()->put('mollie_payment_id', \$payment->id);"),
            'The detector flags a non-secret gateway reference. `MolliePaymentController` stores '
            . 'and reads one deliberately; forbidding it would break a legitimate pattern.'
        );
    }

    private function isCredentialSessionWrite(string $line): bool
    {
        $hasWrite = false;
        foreach (self::WRITE_FORMS as $form) {
            if (str_contains($line, $form)) {
                $hasWrite = true;
                break;
            }
        }

        if (! $hasWrite) {
            return false;
        }

        foreach (self::CREDENTIAL_HINTS as $hint) {
            if (str_contains($line, $hint)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Code lines keyed by their TRUE line number, comments blanked in place so the numbering
     * survives. `token_get_all()` is exact where a regex is not.
     *
     * @return array<int, string>
     */
    private function codeLines(string $source): array
    {
        $lines = explode("\n", $source);
        $blank = [];

        foreach (@token_get_all($source) as $token) {
            if (! is_array($token) || ! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $start = $token[2];
            for ($i = $start; $i < $start + substr_count($token[1], "\n") + 1; $i++) {
                $blank[$i] = true;
            }
        }

        $out = [];
        foreach ($lines as $index => $line) {
            $number = $index + 1;
            $out[$number] = isset($blank[$number]) ? '' : $line;
        }

        return $out;
    }
}
