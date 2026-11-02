<?php

return [

    /*
     * TOKEN SECURITY STAGE 3 — the merchant token lifetime.
     *
     * `docs/security/P01-TOKEN-EXPIRY-PROPOSAL.md` §Stage 3 says: "the whole of Stage 3 is: pass a
     * third argument, read from a config key that DEFAULTS TO OFF". This is that key, and it is
     * the whole mechanism.
     *
     * **IT IS OFF, AND OFF IS THE SHIPPED STATE.** `null` means `createToken()` is called with no
     * `expiresAt`, which is byte-for-byte today's behaviour: the token row's `expires_at` stays
     * NULL and `CustomAuth:66` — whose refusal reads `$accessToken->expires_at && ->isPast()` —
     * never fires. Nothing changes for any client until an operator sets a value.
     *
     * **WHY IT IS A NUMBER OF DAYS RATHER THAN `config/sanctum.php`'s `expiration`.** That file
     * does not exist in this installation, and creating it would expire CUSTOMER and DRIVER tokens
     * too. The approved scope is merchant tokens only — the admin Flutter app — because:
     *
     *   - `ApiResponser::error()` returns HTTP 200 by default, so a new 401 is a deliberate act;
     *     and the shipped Flutter app FORCES A LOGOUT on any 401. An expiry is therefore a
     *     user-visible re-login wave, not a silent tightening.
     *   - There is NO REFRESH PATH for any client, so an expired token means a full re-login.
     *   - `Api\AuthController::login` is unrouted, so storefront customers have no login endpoint
     *     at all: expiring their tokens would lock them out with nothing to log back in with.
     *
     * **AND ONLY NEWLY MINTED TOKENS ARE AFFECTED, by construction rather than by a condition.**
     * `expiresAt` is written into the row at creation. Every token already in the table keeps
     * `expires_at = NULL` and keeps working, so switching this on cannot invalidate a session that
     * already exists — it only shortens the ones handed out from then on.
     *
     * **TURNING IT ON IS THE OWNER'S, AND IT IS NOT UNBLOCKED.** Their standing condition: "do not
     * apply an expiry policy to real production without a real usage report and a review of its
     * effect on active sessions". The instrument for that report exists —
     * `php artisan tokens:inventory` — and the figure that decides whether 30 days is a no-op or a
     * re-login wave is HOW MANY MERCHANT TOKENS ARE BOTH IN USE AND OLDER THAN 30 DAYS. That
     * cannot be measured from this container: there is no production data here. So this ships
     * merged and inert, which is what the proposal asked for, and no number is chosen on a guess.
     */

    /*
     * PARSED STRICTLY, because an independent review drove the loose form through the installed
     * `Illuminate\Support\Env` and found three ways to get a value nobody intended:
     *
     *   MERCHANT_TOKEN_LIFETIME_DAYS=true   -> Env returns boolean true -> (int) true = 1
     *                                       -> A ONE-DAY EXPIRY ON EVERY MERCHANT TOKEN.
     *                                          The most plausible wrong spelling of "switch this
     *                                          on" produced the most aggressive possible policy.
     *   MERCHANT_TOKEN_LIFETIME_DAYS=abc    -> (int) 'abc' = 0 -> silently OFF, with no complaint
     *   MERCHANT_TOKEN_LIFETIME_DAYS=0.9    -> (int) '0.9' = 0 -> silently OFF
     *
     * Now only a string of digits that is greater than zero sets a lifetime. Everything else --
     * unset, empty, 0, a boolean, a word, a decimal, a negative -- is null, which means NO EXPIRY
     * and is the shipped behaviour. A wrong value therefore changes nothing instead of changing
     * something surprising.
     */
    'merchant_token_lifetime_days' => (function () {
        $raw = env('MERCHANT_TOKEN_LIFETIME_DAYS');

        if (! is_string($raw) && ! is_int($raw)) {
            return null;   // unset, boolean, or null -- all mean "off"
        }

        $raw = trim((string) $raw);

        if (! preg_match('/^[0-9]+$/', $raw) || (int) $raw <= 0) {
            return null;
        }

        return (int) $raw;
    })(),

    /*
     * THE CEILING IS THE DATABASE'S, NOT A PREFERENCE. `personal_access_tokens.expires_at` is a
     * MySQL `TIMESTAMP` (`$table->timestamp('expires_at')->nullable()`), whose range ENDS AT
     * 2038-01-19 03:14:07 UTC. An independent review measured, on this repository's own MariaDB
     * 10.11.14 under its live `sql_mode` (STRICT_TRANS_TABLES), that inserting a later value is an
     * ERROR rather than a truncation -- so a lifetime past that boundary would make EVERY MERCHANT
     * LOGIN a 500, which the shipped Flutter app meets with a forced logout. A configuration
     * mistake would have become a total outage of the admin app.
     *
     * So the mint site refuses an expiry beyond this and mints WITHOUT one, loudly. Failing to "no
     * expiry" rather than "nobody can log in" is the deliberate choice: an unenforced expiry is a
     * security feature not yet in effect, while a failed login is the platform down.
     */
    'merchant_token_expiry_ceiling' => '2038-01-01 00:00:00',

];
