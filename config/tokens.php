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

    'merchant_token_lifetime_days' => env('MERCHANT_TOKEN_LIFETIME_DAYS') !== null
        ? (int) env('MERCHANT_TOKEN_LIFETIME_DAYS')
        : null,

];
