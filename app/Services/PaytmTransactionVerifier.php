<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use PaytmWallet;

/**
 * F229 / D-31 REQUIREMENT 2 — ask PAYTM what it says about an order, server to server.
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
 * WHY A FIRST-PARTY SEAM RATHER THAN CALLING THE SDK AT THE SITE
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * The SDK's transport is `callAPI($url, $params)` — a **global function using curl directly**
 * (`PaytmWalletProvider::api_call_new()`). `Http::fake()` cannot intercept it, so a verification
 * written inline at the call site would be **untestable**: every test would either reach the real
 * network or silently take the failure path, and a control that is only ever observed failing is
 * not a control. This class is the seam. Tests bind a double in its place via the container, and
 * the real implementation is exercised against a sandbox, never against a unit test pretending to
 * be one.
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
 * corroborated by the write side rather than assumed: `PaytmPaymentController:142` sends
 * `'amount' => $price` with **no multiplier**, where `$price` is `$plan->price` minus a percentage
 * discount. The same blade this server renders proves the conventions differ per provider —
 * `plans/stripe.blade.php:3228` and `:3275` multiply by 100 while `:3364` does not — which is
 * exactly why the normalisation lives per provider and not in one shared helper.
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

        try {
            $status = PaytmWallet::with('status');
            $status->prepare(['order' => $reference]);
            $status->check();

            $response = $status->response();

            if (! is_array($response)) {
                return $this->unverifiable('the status response was not an array');
            }
        } catch (\Throwable $theStatusCallFailed) {
            /* Unreachable, timed out, threw -- all the same answer, and the answer is not "yes". */
            Log::warning('Paytm transaction status could not be verified.', [
                'reason' => $theStatusCallFailed->getMessage(),
                'reference' => $reference,
            ]);

            return $this->unverifiable('the status call failed: ' . $theStatusCallFailed->getMessage());
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
                'amount' => isset($response['TXNAMOUNT']) ? (string) $response['TXNAMOUNT'] : null,
                'currency' => isset($response['CURRENCY']) ? (string) $response['CURRENCY'] : null,
                'txn_id' => isset($response['TXNID']) ? (string) $response['TXNID'] : null,
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
               as a decimal string, corroborated by this server sending the amount unmultiplied at
               PaytmPaymentController:142. No other provider may reuse this without its own
               evidence -- the conventions genuinely differ per provider. */
            'amount' => (string) $response['TXNAMOUNT'],
            'currency' => isset($response['CURRENCY']) && is_scalar($response['CURRENCY'])
                ? (string) $response['CURRENCY'] : null,
            'txn_id' => isset($response['TXNID']) && is_scalar($response['TXNID'])
                ? (string) $response['TXNID'] : null,
            'reason' => '',
        ];
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
