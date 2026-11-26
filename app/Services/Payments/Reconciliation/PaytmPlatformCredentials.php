<?php

namespace App\Services\Payments\Reconciliation;

/**
 * THE PLATFORM'S PAYTM CREDENTIALS, FOR A DOOR THAT HAS NO SIGNED-IN USER.
 *
 * MEASURED DEFECT THIS EXISTS FOR. `services.paytm-wallet.*` is not in `config/services.php` and is
 * set in exactly two places: `PaytmPaymentController::paymentConfig()`, which copies the operator's
 * stored settings into config **only `if (\Auth::check())`**, and `PaymentService` for a store's own
 * checkout. So on any path without a browser session the merchant key is the empty string, and
 * `PaytmTransactionVerifier` answers `UNVERIFIABLE: no merchant key is configured` -- correctly,
 * and for every purchase.
 *
 * Two doors have no session by construction:
 *
 *   - `PaymentCallbackController::paytmPlan`, the F241 provider callback, which is OUTSIDE the
 *     session group on purpose. It never loaded the settings at all, so the day its gate was opened
 *     every real callback would have been refused -- and `recordRefusal()` makes that terminal. Its
 *     tests could not see it: they set `config(['services.paytm-wallet.merchant_key' => ...])`
 *     directly, which is a state production never reaches without a session.
 *   - the reconciliation command, which runs from a shell.
 *
 * WHY THESE SETTINGS AND NOT A STORE'S. A plan purchase is paid to the PLATFORM, so the credentials
 * are the operator's -- `getSuperAdminAllSetting()`, the same reader `paymentConfig()` uses, with
 * the same four keys and the same two constants. Nothing here is a second definition of where the
 * key lives; it is that one definition without the session condition.
 *
 * A STORED VALUE WINS; AN ABSENT ONE CHANGES NOTHING. Each config key is written only when its
 * setting exists and is non-empty. An installation that configures Paytm some other way keeps what
 * it has, and one that has configured nothing keeps the empty key -- which the verifier then
 * refuses on, as it should. This never invents a credential and never blanks one.
 */
final class PaytmPlatformCredentials
{
    /** setting name => config key, the pairs `PaytmPaymentController::paymentConfig()` copies. */
    private const SETTINGS = [
        'paytm_mode' => 'services.paytm-wallet.env',
        'paytm_merchant_id' => 'services.paytm-wallet.merchant_id',
        'paytm_merchant_key' => 'services.paytm-wallet.merchant_key',
        'paytm_industry_type' => 'services.paytm-wallet.industry_type',
    ];

    public static function apply(): void
    {
        try {
            $settings = getSuperAdminAllSetting();
        } catch (\Throwable $theSettingsAreUnreadable) {
            /* Nothing is configured from a store that cannot be read. The verifier then refuses on
               the missing key, which is the right answer to "we do not know the key". */
            return;
        }

        if (! is_array($settings)) {
            return;
        }

        $loaded = [];

        foreach (self::SETTINGS as $setting => $configKey) {
            $value = $settings[$setting] ?? null;

            if (is_scalar($value) && trim((string) $value) !== '') {
                $loaded[$configKey] = (string) $value;
            }
        }

        if ($loaded === []) {
            return;
        }

        /* The two constants `paymentConfig()` sets beside the stored values, kept beside them. */
        $loaded['services.paytm-wallet.merchant_website'] = 'WEBSTAGING';
        $loaded['services.paytm-wallet.channel'] = 'WEB';

        config($loaded);
    }
}
