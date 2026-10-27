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
 * THE PAYMENT PANEL IS AN EXCEPTION AND HAS NO CLEARING PATH AT ALL. An earlier version of this
 * paragraph said "the other panels have the same shape"; that was wrong once the payment screen
 * was converted. `PaymentSettingController::processGateway()` skips every field write when a
 * gateway is switched off — deliberately, so switching a gateway off and on again keeps its
 * keys — so on that screen blank means keep and `off` means keep too. A merchant can ROTATE a
 * gateway credential but cannot REMOVE one. Making the toggle erase them would change behaviour
 * for 52 providers at once, so the gap is recorded for the owner rather than closed here. Found
 * by review, not by a test, which is itself the reason it is written down.
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
