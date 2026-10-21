<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response as PlainResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * P1-SEC-17 F1 -- a Telegram bot URL is a credential, and no response may carry one.
 *
 * `https://api.telegram.org/bot<TOKEN>/sendMessage` IS the bot token. Three storefront handlers
 * built it and RETURNED it to an anonymous caller:
 *
 *   PaymentController::getWhatsappUrl                       host        -- fixed at the source
 *   CustomerDonationController::getWhatsappUrl              Donation    -- vendored
 *   LotteryPrizesController::getWhatsappUrl                 LotteryPrizes -- vendored
 *
 * `packages/workdo/` cannot be edited (guard-no-workdo-changes.sh refuses it), so the two module
 * copies are closed here, from the host, in the position BulkDeleteTenantGuard established: appended
 * to the `web` group in bootstrap/app.php, which reaches module routes whatever order they register
 * in and still runs under `route:cache`.
 *
 * It is a RESPONSE rule, not a route list. A list of route names would silently stop applying the
 * day an upstream release renames one; a rule about what a response may contain does not. It is
 * deliberately narrow so it can run on every web response for free: only a plain string response of
 * at most 2 KiB is examined (the module handlers return a bare URL; an HTML page, a JSON document,
 * a redirect, a file or a stream is never touched), and only one that BEGINS with the bot-URL prefix
 * is emptied. The pages that call these routes store the body in a hidden input nothing reads
 * (resources/views/payment/telegram.blade.php and the two module copies), so emptying it changes
 * nothing a shopper can see.
 *
 * Nothing is logged. Both module handlers return the URL on EVERY Telegram checkout, so a log line
 * per emptied response would be noise on the legitimate path and a flood an anonymous caller could
 * drive; and the body must never be logged in any case -- it is the secret.
 */
class WithholdTelegramBotUrl
{
    /** The module handlers return the bare URL, ~70 characters; anything larger is not that. */
    private const MAX_BODY_BYTES = 2048;

    private const BOT_URL_PREFIX = '#^\s*https?://api\.telegram\.org/bot#i';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof PlainResponse) {
            return $response;
        }

        $body = $response->getContent();

        if (is_string($body) && strlen($body) <= self::MAX_BODY_BYTES && preg_match(self::BOT_URL_PREFIX, $body) === 1) {
            $response->setContent('');
        }

        return $response;
    }
}
