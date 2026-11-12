<?php

namespace App\Services;

use App\Models\Utility;
use App\Models\WhatsappMessage;

/**
 * P2.5-PKG-K — why a "nudge the shopper" WhatsApp message cannot go out, in words a merchant can act on.
 * ==================================================================================================
 *
 * THIS EXISTS BECAUSE `Utility::SendMsgs()` ANSWERS NOTHING. It has no return statement on any
 * path that does not throw, so the two abandoned-basket handlers were asking `if ($resp == false)`
 * of a value that is always `null` — and `null == false`, so they reported failure 100% of the
 * time, with the hardcoded words "Invalid Auth access token - Cannot parse access token" whatever
 * the real cause was. Measured: the response was BYTE-IDENTICAL for a store with no WhatsApp
 * configuration at all and for one fully configured with an active template.
 *
 * SO THE OUTCOME IS DERIVED FROM THE SAME STATE `SendMsgs()` ITSELF READS, BEFORE CALLING IT,
 * rather than from its return value. `blockedReason()` asks the TWO questions `SendMsgs()` asks
 * silently — is WhatsApp configured for this store, is this template switched on — and names
 * the one that fails.
 *
 * THE MOBILE CHECK IS AN ADDITION, NOT A MIRROR, and saying so matters because the first draft
 * of this docblock called it a third question `SendMsgs()` asks. It does not: `SendMsgs()` never
 * validates `$mobile_no`, so an empty one produced `'to' => "+"` with nothing after it. The
 * check runs FIRST, so a store that is both unconfigured AND has a shopper with no number is
 * told about the number, and the configuration problem surfaces only on the next attempt. That
 * order is deliberate — the shopper-specific fact is the one the merchant can verify from the
 * row in front of them — but it is a choice, not an inevitability. Both points were raised by
 * the tenant-authz review of this change, with the `$storeId` contract on the method below.
 *
 * WHAT IT DELIBERATELY DOES NOT DO. It does not change `Utility::SendMsgs()`. That method is
 * called from PaymentService, PaymentController, ApiController, PayUPaymentController and
 * AuthorizeNetPaymnetController on the order and stock paths, and §3 of the owner's direction
 * reserves the shared contract. The consequence is honest and stated at the call sites: once the
 * preconditions hold, this class can report that the message was HANDED TO WhatsApp and cannot
 * report that it was delivered, because `SendMsgs()` discards the gateway's response.
 *
 * IT IS RESOLVED FROM THE CONTAINER, not called statically, so a test can substitute a recording
 * double and drive both workflows end to end WITHOUT SENDING A REAL MESSAGE. `SendMsgs()` uses
 * `curl_*` directly, so `Http::fake()` cannot see it and there was no other seam.
 */
class AbandonedMessageDispatch
{
    /**
     * The reason this message cannot be handed to WhatsApp, or null if it can.
     *
     * Translated, because it is shown to the merchant verbatim.
     *
     * `$storeId` MUST BE `getCurrentStore()`, and that is a contract rather than a convention.
     * `SendMsgs()` does not take a store: it re-resolves one itself from the route slug or
     * `getCurrentStore()` (Utility.php:2556-2569) and ignores whatever the caller had in mind. So
     * checking store A's configuration and then handing over on a request that resolves store B
     * would report on one store and send with another. Both callers today pass `getCurrentStore()`
     * directly — CartController::abandon_carts_messsend() and
     * WishlistController::abandonWishlistMsgSend(), neither reading any id from request input —
     * and a third caller (a queued nudge, an operator acting for a named store) must either do the
     * same or not use this class.
     *
     * A related trap, in the same place and for that same future caller: `GetValueByName()`'s
     * FALLBACK path (Utility.php:228) calls `Utility::Setting($store_id)`, which IGNORES its own
     * argument while a user is authenticated and substitutes `getCurrentStore()` (:141). Under the
     * contract above that is the same value; without it, a store with no row of its own would read
     * the CURRENT store's token.
     */
    public function blockedReason(string $template, ?string $mobile, int $storeId): ?string
    {
        if (trim((string) $mobile) === '') {
            return __('This shopper has no mobile number on file, so there is nothing to message.');
        }

        $phoneNumberId = Utility::GetValueByName('whatsapp_phone_number_id', $storeId);
        $accessToken = Utility::GetValueByName('whatsapp_access_token', $storeId);

        if (empty($phoneNumberId) || empty($accessToken)) {
            return __('WhatsApp messaging is not set up for this store. Add the WhatsApp phone number ID and access token in the store settings.');
        }

        /*
         * `SendMsgs()` reads this row as `$whatstemplate->is_active` with NO null guard, so a store
         * whose templates were never seeded reaches a fatal there. Answering it here means the
         * merchant gets a sentence instead of a 500, and it is the same `LIKE` lookup rather than a
         * stricter one so that this class cannot say "fine" where `SendMsgs()` would say nothing.
         */
        $templateRow = WhatsappMessage::where('name', 'LIKE', $template)
            ->where('store_id', $storeId)
            ->first();

        if ($templateRow === null) {
            return __('The ":template" WhatsApp template does not exist for this store yet.', ['template' => $template]);
        }

        if ((int) $templateRow->is_active !== 1) {
            return __('The ":template" WhatsApp template is switched off. Turn it on to message shoppers.', ['template' => $template]);
        }

        return null;
    }

    /**
     * Hand the message over. Returns nothing, deliberately: `SendMsgs()` discards the gateway's
     * answer, and inventing one here would replace a false failure with a false success.
     */
    public function handOver(string $template, string $mobile, string $message): void
    {
        Utility::SendMsgs($template, $mobile, $message);
    }
}
