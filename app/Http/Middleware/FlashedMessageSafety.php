<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * NO EXCEPTION OBJECT REACHES A SHOPPER THROUGH A FLASH MESSAGE.  P1-SEC-22 (b).
 * =================================================================================================
 *
 * WHAT WAS HAPPENING. Six vendored payment and voucher services end a failed checkout with
 *
 *     return redirect()->back()->with('error', $e);        // the EXCEPTION OBJECT, not its message
 *
 * (Campaigns/CampaignsPaymentService:1080, Donation/DonationPaymentService:1103,
 * LotteryPrizes/LotteryPaymentService:1104, PDFVouchers/CouponRequestController:70 and two in
 * EMailBox/EmailBoxController). The three layouts that render a flashed message then interpolate it
 * -- so the shopper was shown the exception's string form: its message, its file, its line and its
 * STACK TRACE, with the arguments of every frame when `zend.exception_ignore_args` is off. That is
 * how the reviewer who found this printed fifteen characters of a key. It is also written into
 * `sessions.payload`, which is not encrypted (config/session.php:50).
 *
 * WHY A MIDDLEWARE. Every one of those six is under `packages/workdo/`: vendored commercial code, so
 * an in-place edit is reverted by the next upstream update and is refused by
 * guard-no-workdo-changes.sh. The host app's own count is ZERO -- `PaymentService::processMidtrans`,
 * which the task row named, no longer does it -- so there is nothing to fix at source and everything
 * to catch on the way out.
 *
 * KEYED ON THE TYPE, NOT ON THE KEY NAME. The obvious shape is "sanitise the `error` and `success`
 * keys", and it is the wrong one: this project's recorded census failures are all cases of keying on
 * the spelling somebody happened to look at. A Throwable is never a message, under ANY flash key, so
 * that is the test. `_old_input` and `errors` are arrays and a ViewErrorBag and are untouched --
 * FlashedCredentialScrubber owns the first and the framework owns the second.
 *
 * THE REAL REASON IS NOT LOST, it is moved: the exception goes to the log, where an operator can read
 * it, instead of to the shopper and the sessions table. P01-OBSERVABILITY-INVENTORY.md records the
 * line.
 *
 * REGISTERED on the `web` group, appended, for exactly the reasons set out in
 * App\Http\Middleware\FlashedCredentialScrubber -- on the response leg an appended group member
 * unwinds first, so this runs before StartSession writes the payload, and it cannot lose a route slot
 * to a module's later declaration.
 *
 * WHAT IT DOES NOT COVER, disclosed rather than implied: seven further sites do
 * `->with('error', __($e))`, and `__()` resolves to `Translator::get(string $key, ...)`, so passing a
 * Throwable raises a TypeError BEFORE the flash happens. Those handlers 500 instead of leaking --
 * a crash, not a disclosure, and not something a response-leg middleware can reach. One of the seven
 * was in the host app (CustomerLoginController's newsletter catch) and is fixed at source in the same
 * package; the other six are vendored.
 */
class FlashedMessageSafety
{
    /** What the shopper is told instead. Deliberately fixed: a failed checkout is not a diagnostic surface. */
    public const SAFE_MESSAGE = 'Something went wrong. Please try again.';

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if (! $request->hasSession()) {
            return $response;
        }

        $session = $request->session();

        foreach ($session->all() as $key => $value) {
            if (! $value instanceof Throwable) {
                continue;
            }

            Log::warning('an exception object was flashed to a session and was replaced before it could be rendered', [
                'flash_key' => $key,
                'exception' => get_class($value),
                'message' => $value->getMessage(),
                'file' => $value->getFile() . ':' . $value->getLine(),
            ]);

            $session->put($key, __(self::SAFE_MESSAGE));
        }

        return $response;
    }
}
