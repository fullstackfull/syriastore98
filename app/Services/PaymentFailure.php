<?php

namespace App\Services;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

/**
 * P1-SEC-22 (b). The answer to a payment that could not be started.
 *
 * Seven gateway handlers ended by flashing the caught exception variable itself into the session error -- the exception OBJECT. The layouts print the session error unescaped,
 * so the shopper was shown the exception's string form (message plus stack trace, with call arguments when `zend.exception_ignore_args` is off:
 * the SEC-17 reviewer printed fifteen characters of a gateway key that way), and the object was serialised into the session row. Midtrans was the
 * worst: `Config::$isProduction = false` is hard-coded, so every production-key merchant took that path on every checkout.
 *
 * A shopper gets a fixed sentence; the operator gets the class and the message in the log (never the trace and never the arguments).
 */
final class PaymentFailure
{
    public static function redirectBack(\Throwable $e, string $gateway): RedirectResponse
    {
        Log::warning('Payment could not be started', [
            'gateway' => $gateway,
            'exception' => get_class($e),
            'message' => substr($e->getMessage(), 0, 300),
        ]);

        return redirect()->back()->with('error', __('The payment could not be started. Please try again or choose another payment method.'));
    }
}
