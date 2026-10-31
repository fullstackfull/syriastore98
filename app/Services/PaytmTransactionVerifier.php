<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * F229 / D-31 REQUIREMENT 2 — ask PAYTM what it says about an order, server to server, over a
 * channel this server authenticates itself.
 *
 * > *"Verify the actual payment with each provider before granting the subscription. Never trust
 * > callback-supplied plan IDs, amounts or success flags as payment evidence."*
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * WHY THIS EXISTS WHEN PAYTM'S CHECKSUM IS ALREADY CRYPTOGRAPHIC
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * `PaytmWalletProvider::response()` AES-verifies `CHECKSUMHASH` against the server-held merchant
 * key, and `isSuccessful()` tests `response['STATUS'] == 'TXN_SUCCESS'` — so the posted body is
 * authentic AND says success. That is genuinely stronger than the envelope gates the other
 * providers use, and it is why Paytm is the provider this requirement can be satisfied for first.
 *
 * **It is still a claim relayed through the buyer's browser.** The owner's instruction is explicit
 * that *"a valid checksum by itself is not sufficient proof of a successful payment"*, and the
 * reason is structural rather than cryptographic: the checksum proves the body was minted by
 * someone holding the merchant key, and it says nothing about whether that transaction is still
 * successful, was later reversed, or was ever settled. The authoritative answer lives at Paytm,
 * and `/merchant-status/getTxnStatus` is where it is kept.
 *
 * F232 is the other half of why the checksum cannot stand alone: with **no merchant key
 * configured**, `paymentConfig()` passes `''`, so the body is verified against the empty string —
 * a key the caller knows exactly as well as the server does. That is already fixed by a
 * fail-closed refusal, and it is a standing demonstration that "the checksum verified" can be true
 * while nothing was proved.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * WHY THE SDK'S OWN TRANSPORT IS NOT USED — AND THIS IS THE LOAD-BEARING PART
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * Found by independent review of 2f011efb, and it invalidated the first version of this class.
 *
 * `PaytmWallet::with('status')->check()` reaches the network through `callAPI()`
 * (`vendor/anandsiddharth/laravel-paytm-wallet/lib/encdec_paytm.php:136`), a **global function
 * using curl directly**, and it sets:
 *
 * ```php
 * curl_setopt ($ch, CURLOPT_SSL_VERIFYHOST, 0);   // :145
 * curl_setopt ($ch, CURLOPT_SSL_VERIFYPEER, 0);   // :146
 * ```
 *
 * **Both disabled.** So the "authoritative server-to-server answer" arrives over a channel with no
 * certificate validation of any kind: anyone able to answer for `securegw.paytm.in` — a DNS
 * answer, an ARP answer, a hostile resolver, a proxy — can return
 * `{"STATUS":"TXN_SUCCESS","TXNAMOUNT":"499.00"}` and this class would have called it VERIFIED.
 * A verification whose answer the attacker can write is not a verification, so going out through
 * the SDK would have replaced a browser-relayed claim with a network-relayed one and called that
 * progress.
 *
 * This class therefore owns its transport. The request is byte-for-byte what `callAPI()` sends
 * (`POST`, body `JsonData=<urlencoded json>`, `Content-Type: application/json` — the SDK's own
 * odd pairing, copied deliberately so the wire format is not a reimplementation) and the
 * parameters are checksummed with the SDK's own `getChecksumFromArray()`. The one difference is
 * `'verify' => true`, passed explicitly rather than relied on as a default so that it is visible
 * at the call site and assertable from the source.
 *
 * Replacing the transport also makes the class directly testable: `Http::fake()` intercepts it,
 * which `callAPI()` could never allow. The container seam below still earns its place — the
 * controller tests want a double without standing up HTTP at all — but it is no longer the only
 * way this code can be driven, and `PaytmTransactionVerifierTest` drives the real implementation.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * THE STATUS RESPONSE'S OWN CHECKSUM IS CHECKED WHEN IT CARRIES ONE
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * Also found by that review: nothing verified the status response, only the browser callback.
 * `StatusCheckProvider::response()` returns the decoded JSON untouched, so the SDK never looks at
 * a `CHECKSUMHASH` coming back.
 *
 * Here, the three cases are kept apart on purpose, and the middle one is **not** a guess about a
 * contract this project has no sandbox evidence for:
 *
 * | response carries        | basis of authenticity              | outcome                     |
 * |-------------------------|------------------------------------|-----------------------------|
 * | a checksum that matches | the server-held merchant key       | may be VERIFIED             |
 * | a checksum that differs | nothing is established             | **UNVERIFIABLE**            |
 * | no checksum at all      | the validated TLS certificate      | may be VERIFIED             |
 *
 * A **mismatching** checksum is deliberately UNVERIFIABLE rather than REFUSED. It is far more
 * likely to be tampering, which deserves the harsher label — but `verifychecksum_e()` hashes the
 * *whole* sorted array, so a legitimate response carrying any field Paytm excludes from its own
 * computation would also mismatch, and this project has no sandbox evidence either way.
 * `recordRefusal()` is terminal: it closes the intent and no retry can reopen it. UNVERIFIABLE
 * blocks the activation just as completely while leaving the intent `pending` and visible in
 * `PlanPurchaseIntent::unreconciled()`, so a wrongly-rejected real payment is recoverable by a
 * human instead of destroyed by my assumption. Fail closed, but fail recoverably.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * IT FAILS CLOSED, WHICH IS REQUIREMENT 8
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * Unreachable network, malformed response, missing fields, an exception — every one of them is
 * `UNVERIFIABLE`, and the caller must refuse on it. **`UNVERIFIABLE` is never a pass.** That is
 * the whole difference between this and the log-only approach D-31 rejected: an answer that cannot
 * be obtained stops the activation instead of being noted and ignored.
 *
 * The cost of that is real and requirement 8 asks for it to be stated: on an installation where
 * Paytm's status endpoint is unreachable, **legitimate Paytm plan purchases will not activate**.
 * They will leave a `pending` intent carrying the provider's transaction id, which
 * `PlanPurchaseIntent::unreconciled()` surfaces for a human. That is the intended trade — a
 * purchase a human must finish is recoverable, an entitlement granted without proof is not.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * THE AMOUNT'S UNITS ARE NOT GUESSED — REQUIREMENT 3
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * Paytm reports `TXNAMOUNT` as a **decimal string in major units** ("499.00"), and this is
 * corroborated by the write side rather than assumed: `PaytmPaymentController::planPayWithPaytm()`
 * sends `'amount' => $price` with **no multiplier**, where `$price` is `$plan->price` minus a
 * percentage discount. The same blade this server renders proves the conventions differ per
 * provider — `plans/stripe.blade.php` multiplies by 100 for some gateways and not for others —
 * which is exactly why the normalisation lives per provider and not in one shared helper.
 *
 * So Paytm's normaliser is the identity, and it is written out as such instead of being implied.
 * **No other provider may reuse it without its own evidence.**
 */
class PaytmTransactionVerifier
{
    /** The three outcomes. `UNVERIFIABLE` is a refusal, never a pass. */
    public const VERIFIED = 'verified';
    public const REFUSED = 'refused';
    public const UNVERIFIABLE = 'unverifiable';

    /** Paytm's own two hosts. `env` is the stored `paytm_mode` setting, as the SDK reads it. */
    private const HOST_PRODUCTION = 'securegw.paytm.in';
    private const HOST_SANDBOX = 'securegw-stage.paytm.in';

    /**
     * What does Paytm say about this order?
     *
     * @return array{state:string,amount:?string,currency:?string,txn_id:?string,reason:string}
     */
    public function verify(string $reference): array
    {
        if (trim($reference) === '') {
            return $this->unverifiable('no order reference to ask about');
        }

        $merchantKey = trim((string) config('services.paytm-wallet.merchant_key'));

        if ($merchantKey === '') {
            /* F232's condition. Without a key there is nothing to authenticate WITH, so there is
               no question that can be asked and no answer that would mean anything. */
            return $this->unverifiable('no merchant key is configured');
        }

        $merchantId = trim((string) config('services.paytm-wallet.merchant_id'));

        if ($merchantId === '') {
            /* getTxnStatus is keyed on (MID, ORDERID). Without the MID the question is malformed,
               and a malformed question must not be answered optimistically. */
            return $this->unverifiable('no merchant id is configured');
        }

        if (! $this->checksumHelpersAreLoadable()) {
            return $this->unverifiable('the Paytm checksum helpers could not be loaded');
        }

        $parameters = ['MID' => $merchantId, 'ORDERID' => $reference];
        $parameters['CHECKSUMHASH'] = getChecksumFromArray($parameters, $merchantKey);

        try {
            $answer = Http::withOptions([
                /* THE POINT OF OWNING THIS TRANSPORT. `callAPI()` sets both of curl's TLS checks
                   to 0, which makes its answer writable by anyone on the path. Stated explicitly
                   rather than left to the default so it is visible here and assertable from the
                   source. */
                'verify' => true,
            ])
                ->connectTimeout(10)
                ->timeout(20)
                /* `callAPI()`'s exact body and header pairing, copied rather than improved: the
                   wire format is evidence from the code that runs in production today, and
                   "correcting" the Content-Type would be an unverified change to a provider
                   contract. */
                ->withBody('JsonData=' . urlencode((string) json_encode($parameters)), 'application/json')
                ->post('https://' . $this->host() . '/merchant-status/getTxnStatus');
        } catch (\Throwable $theStatusCallFailed) {
            /* Unreachable, timed out, TLS refused to validate -- all the same answer, and the
               answer is not "yes". A TLS failure landing here is the control working. */
            Log::warning('Paytm transaction status could not be verified.', ['reason' => $theStatusCallFailed->getMessage(), 'reference' => $reference]);

            return $this->unverifiable('the status call failed: ' . $theStatusCallFailed->getMessage());
        }

        if (! $answer->successful()) {
            return $this->unverifiable('Paytm answered HTTP ' . $answer->status());
        }

        $response = $answer->json();

        if (! is_array($response)) {
            return $this->unverifiable('the status response was not an array');
        }

        $authenticity = $this->authenticityOf($response, $merchantKey);

        if ($authenticity === null) {
            /* Present and mismatching. Blocks the activation, but recoverably -- see the header. */
            Log::warning('Paytm transaction status carried a checksum that did not verify.', ['reference' => $reference]);

            return $this->unverifiable('the status response carried a checksum that did not verify');
        }

        $providerStatus = (string) ($response['STATUS'] ?? '');

        if ($providerStatus === '') {
            return $this->unverifiable('the status response carried no STATUS');
        }

        if ($providerStatus !== 'TXN_SUCCESS') {
            /* A definite "no" from the provider is a REFUSAL and not an absence of evidence --
               the distinction matters, because a refusal is final and an absence is retryable. */
            return [
                'state' => self::REFUSED,
                'amount' => isset($response['TXNAMOUNT']) && is_scalar($response['TXNAMOUNT']) ? (string) $response['TXNAMOUNT'] : null,
                'currency' => isset($response['CURRENCY']) && is_scalar($response['CURRENCY']) ? (string) $response['CURRENCY'] : null,
                'txn_id' => isset($response['TXNID']) && is_scalar($response['TXNID']) ? (string) $response['TXNID'] : null,
                'reason' => 'Paytm reports STATUS ' . $providerStatus,
            ];
        }

        if (! isset($response['TXNAMOUNT']) || ! is_scalar($response['TXNAMOUNT'])) {
            /* Success with no amount cannot be matched against the intent, so it cannot be
               accepted. Requirement 4 wants the amount verified, not assumed from the status. */
            return $this->unverifiable('Paytm reported success without a TXNAMOUNT');
        }

        return [
            'state' => self::VERIFIED,
            /* THE IDENTITY NORMALISER, written out rather than implied: Paytm reports major units
               as a decimal string, corroborated by this server sending the amount unmultiplied in
               `PaytmPaymentController::planPayWithPaytm()`. No other provider may reuse this
               without its own evidence -- the conventions genuinely differ per provider. */
            'amount' => (string) $response['TXNAMOUNT'],
            'currency' => isset($response['CURRENCY']) && is_scalar($response['CURRENCY'])
                ? (string) $response['CURRENCY'] : null,
            'txn_id' => isset($response['TXNID']) && is_scalar($response['TXNID'])
                ? (string) $response['TXNID'] : null,
            'reason' => 'authenticated by ' . $authenticity,
        ];
    }

    /**
     * Which host answers. `services.paytm-wallet.env` is set from the stored `paytm_mode`, and the
     * comparison is the SDK's own (`PaytmWalletProvider::__construct()`: `== 'production'`), so a
     * blank or unrecognised mode lands on the sandbox exactly as it does today.
     */
    private function host(): string
    {
        return trim((string) config('services.paytm-wallet.env')) === 'production'
            ? self::HOST_PRODUCTION
            : self::HOST_SANDBOX;
    }

    /**
     * What authenticates this response — or `null` if a checksum is present and does not verify.
     *
     * @param  array<array-key,mixed>  $response
     */
    private function authenticityOf(array $response, string $merchantKey): ?string
    {
        $checksum = $response['CHECKSUMHASH'] ?? null;

        if (! is_scalar($checksum) || trim((string) $checksum) === '') {
            /* No checksum offered. The channel still authenticates the answer: `'verify' => true`
               means Guzzle validated Paytm's certificate chain for the host above before any of
               these bytes were read. That is a real basis, and it is named so the distinction
               survives into the log and the return value. */
            return 'the validated TLS certificate of ' . $this->host();
        }

        $matched = false;

        try {
            $matched = verifychecksum_e($response, $merchantKey, (string) $checksum) === 'TRUE';
        } catch (\Throwable $theCheckItselfFailed) {
            /* A malformed checksum makes `decrypt_e_openssl()` throw or warn. Not a match. */
            $matched = false;
        }

        return $matched ? 'the response checksum' : null;
    }

    /**
     * The SDK's checksum functions are globals declared by a plain `require` at the top of
     * `PaytmWalletProvider.php`, so they exist only once that class has been autoloaded. Touching
     * the class is what loads them; this does not construct it or make any call.
     */
    private function checksumHelpersAreLoadable(): bool
    {
        if (! function_exists('getChecksumFromArray') || ! function_exists('verifychecksum_e')) {
            class_exists(\Anand\LaravelPaytmWallet\Providers\PaytmWalletProvider::class);
        }

        return function_exists('getChecksumFromArray') && function_exists('verifychecksum_e');
    }

    /** @return array{state:string,amount:?string,currency:?string,txn_id:?string,reason:string} */
    private function unverifiable(string $reason): array
    {
        return [
            'state' => self::UNVERIFIABLE,
            'amount' => null,
            'currency' => null,
            'txn_id' => null,
            'reason' => $reason,
        ];
    }
}
