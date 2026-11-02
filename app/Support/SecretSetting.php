<?php

namespace App\Support;

/**
 * The two halves of "a stored credential is never shown, and never silently erased".
 * =================================================================================================
 *
 * F117 recorded that **16 credential fields across 11 settings panels echo the stored value into
 * the rendered HTML** — `value="sk_live_…"` in the DOM, readable by anyone who can open the page,
 * by any browser extension, and in any screenshot or screen-share of it.
 *
 * MASKING THE DISPLAY ALONE WOULD DESTROY DATA, which is why both halves live in one class. These
 * panels post EVERY field on EVERY save: `SettingController` writes
 * `$post['twilio_token'] = $request->twilio_token;` unconditionally. Render the box empty and the
 * next save of an unrelated field on the same panel writes an empty string over the credential.
 * The merchant's integration then stops working for a reason nothing on screen explains.
 *
 * So `display()` decides what the page may show and `resolve()` decides what a save may write,
 * and a caller that uses one without the other is the bug this class exists to prevent.
 *
 * HOW A SECRET IS CLEARED, since "blank means keep" removes the obvious way: on most panels,
 * through the panel's own enable toggle, which the handlers honour — `twilio_setting_enabled ==
 * 'off'` blanks the four Twilio values at `SettingController:1419-1423`. That is a deliberate,
 * named action rather than a side effect of leaving a box empty, and
 * `SecretSettingPersistenceTest::test_disabling_the_integration_still_clears_the_credentials`
 * holds it.
 *
 * THE PAYMENT PANEL CLEARS DIFFERENTLY, BY OWNER DECISION (F141). It has no toggle-clears
 * behaviour and must not grow one: `PaymentSettingController::processGateway()` deliberately
 * skips every field write when a gateway is switched off, so a merchant can disable and
 * re-enable a gateway without retyping its keys. On that screen blank means keep and `off`
 * means keep too.
 *
 * So removal there is its OWN action — `payment.secret.confirm` then `payment.secret.remove`,
 * one field of one gateway in one store, behind the same permission as the save, with the
 * confirmation asked by the SERVER because a JavaScript one fails open. A required credential
 * cannot be removed from an ENABLED gateway: that would leave it switched on and unable to
 * authenticate, and the merchant would learn about it from a shopper failing at checkout.
 *
 * AND REMOVING A CREDENTIAL HERE IS NOT REVOKING IT. The key keeps working for anyone holding a
 * copy until it is revoked in the provider's own dashboard. The confirmation page and the
 * success message both say so, because it is the thing a merchant removing a leaked key is most
 * likely to assume the opposite of.
 */
final class SecretSetting
{
    /**
     * What a save should store: the submitted value, or the stored one when nothing was typed.
     *
     * `null` and `''` both mean "not typed". A string of spaces does too — a credential is never
     * whitespace, and treating `'   '` as a real value would store garbage that the integration
     * would then fail on.
     */
    public static function resolve($submitted, $stored): string
    {
        $typed = is_string($submitted) ? trim($submitted) : '';

        return $typed === '' ? (string) ($stored ?? '') : $typed;
    }

    /**
     * What a page may put in the field: nothing, ever.
     *
     * It exists as a method rather than as an omitted attribute so the intent is greppable and so
     * a future edit that "restores" the value has to delete a call that says why it must not.
     */
    public static function display(): string
    {
        return '';
    }

    /**
     * Whether a credential is already stored, so the UI can say "leave blank to keep the current
     * value" only when there IS one — and say "not set" when there is not.
     *
     * This is the ONLY thing about a stored secret that may reach the page: its existence, never
     * its value, never its length, and never a partial mask (a prefix is a meaningful hint for
     * several providers' key formats).
     */
    public static function isSet($stored): bool
    {
        return is_string($stored) ? trim($stored) !== '' : ! empty($stored);
    }
}
