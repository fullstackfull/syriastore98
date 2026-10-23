<?php

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\RedactsApiLogFields;
use Closure;
use Illuminate\Http\Request;

/**
 * NO CREDENTIAL IS FLASHED INTO `sessions.payload`.  P1-SEC-16, acceptance 2.
 * =================================================================================================
 *
 * `RedirectResponse::withInput()` with no argument flashes the WHOLE request body into the session
 * under `_old_input`, so the form can be repopulated after a validation failure. Session encryption
 * is off (`config/session.php:50`) and the driver is the database, so whatever it flashes sits in
 * `sessions.payload` in plaintext for the session's lifetime -- readable by anything with a SELECT
 * on that table, and carried into every database backup.
 *
 * P1-SEC-16 named four host-app sites and they were changed to `withInput($request->except([...]))`
 * (CustomerController:95,106 and DeliveryBoyController:58,125). THAT WAS NOT THE WHOLE SET, and the
 * census that found the rest is the reason this is a middleware rather than a fifth edit:
 *
 *     packages/workdo/EMailBox/.../Company/SettingsController.php:77   `emailbox_mail_password`
 *     packages/workdo/Helpdesk/.../HelpdeskConversionController.php:40,107
 *     packages/workdo/SidebarCustomization/.../SubMenuController.php:60
 *     packages/workdo/SidebarCustomization/.../SideMenuController.php:98
 *
 * The EMailBox one is a credential sink in the same sense as the four: its form posts the mailbox's
 * SMTP/IMAP password, and `back()->withInput()` on the save's catch branch flashes it. All five are
 * in `packages/workdo/`, which is vendored commercial code -- an in-place edit is reverted by the
 * next upstream update and is refused by `guard-no-workdo-changes.sh`. So the fix has to live in the
 * host app, and the host app's vehicle for a module's response is the `web` group.
 *
 * REGISTERED ON THE GROUP, appended, so on the RESPONSE leg it is the first middleware to see the
 * response and therefore runs before `StartSession::terminate()` writes the payload to the database.
 * It also survives `route:cache`, and it cannot lose a route slot to a module's later declaration
 * the way a host re-declaration would (PackageServiceProvider requires module routes from
 * `app->booted()`).
 *
 * WHY THE PREDICATE IS SHARED AND NOT A SECOND LIST. `shouldRedact()` is `RedactsApiLogFields`'s,
 * unchanged. P1-SEC-16's own finding was that `APILog` had been fixed and its twin `AdminApiLog`
 * had not, so administrator and driver passwords kept reaching the same log file for months -- a
 * second list is exactly how that happens. One definition, two sinks.
 *
 * REMOVED, NOT MASKED. The log sink replaces a value with `[REDACTED]`; this one deletes the key.
 * `old('password')` is read back INTO the form field, so a mask would pre-fill the password box with
 * the literal string `[REDACTED]` and invite the user to submit it. Deleting the key renders the
 * field empty, which is what `$request->except(['password'])` already does at the four fixed sites.
 *
 * DISCLOSED BEHAVIOUR CHANGE. The shared predicate matches on whole key words, and two of its words
 * are not always credentials: `code` and `key`. So after a validation failure a field named
 * `coupon_code`, `country_code` or `meta_key` is no longer repopulated and the user retypes it.
 * That is the price of one predicate instead of two, and it is the cheaper side: the same word list
 * is what keeps `emailbox_mail_password`, `cvv`, `pin` and `cardNumber` out of the table.
 */
class FlashedCredentialScrubber
{
    use RedactsApiLogFields;

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if (! $request->hasSession()) {
            return $response;
        }

        $session = $request->session();
        $old = $session->get('_old_input');

        if (! is_array($old)) {
            return $response;
        }

        $clean = $this->withoutCredentials($old);

        if ($clean !== $old) {
            // put(), not flash(): `withInput()` already registered `_old_input` in `_flash.new`, and
            // re-flashing would be a second registration. The value is replaced; its lifetime is not.
            $session->put('_old_input', $clean);
        }

        return $response;
    }

    /**
     * The flashed input without any credential-named key, at every depth.
     *
     * Nested because a payment form posts `payment[card_number]` and a settings form posts
     * `settings[smtp_password]`; a top-level-only sweep would leave both in the table.
     */
    private function withoutCredentials(array $input): array
    {
        $clean = [];

        foreach ($input as $key => $value) {
            if (is_string($key) && $this->shouldRedact($key)) {
                continue;
            }

            $clean[$key] = is_array($value) ? $this->withoutCredentials($value) : $value;
        }

        return $clean;
    }
}
