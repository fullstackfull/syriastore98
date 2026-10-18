<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * SINGLE-USE, EXPIRING, ACCOUNT- AND STORE-BOUND RESET AUTHORIZATION for the storefront API's
 * password-recovery flow.
 *
 * WHY THIS EXISTS. Api\AuthController's three recovery endpoints were exploitable in two
 * independent ways, both confirmed by reading the code and reproduced in tests:
 *
 *   forgot_password_save      had NO authentication middleware, found a **User** (the ADMIN model)
 *                             by email with no store predicate, and wrote
 *                             bcrypt($request->password). It never read the OTP. One anonymous
 *                             request set any account's password, the platform operator included.
 *   forgot_password_verify_otp replaced the customer's password with the literal 'hello@1234' --
 *                             a value committed to this repository -- and mailed it. Because the
 *                             value is fixed and public, anyone who knows a target completed the
 *                             flow could sign in as them WITHOUT the OTP. It also resolved the
 *                             customer with no store predicate, so a customer of store A was
 *                             resettable through store B's slug.
 *
 * The two handlers did not even address the same table: the OTP was issued and verified against
 * `customers` while the password was written to `users`. So the flow could not serve its stated
 * purpose either -- a customer who entered the correct OTP never got their own password changed.
 *
 * WHY THE STATE LIVES IN THE CACHE AND NOT IN A COLUMN. `customers.password_otp` is declared
 * `integer()` (database/migrations/2023_12_15_112855_create_customers_table.php:39) and NO
 * migration alters it. The web flow in Customer\Auth\CustomerLoginController:154 nevertheless
 * writes `Hash::make($otp)` -- a 60-character bcrypt string -- into it. SQLite's dynamic typing
 * accepts that, which is why it passes in tests; MySQL stores 0 (or errors in strict mode), so
 * `Hash::check()` can never match and THE SECURE WEB RESET IS BROKEN ON THE PRODUCTION ENGINE.
 * That is reported separately. This service must work on both engines today and must not require
 * a migration the running server has not had applied, so it keeps its state in the cache, which
 * also gives expiry, single-use semantics and attempt counting without new schema.
 *
 * THE GUARANTEES, each covered by a test in
 * tests/Feature/Security/CustomerPasswordResetTest:
 *   - the OTP is stored only as a HASH, never in plaintext, and never on the customer row;
 *   - it expires (OTP_TTL_MINUTES) and is single-use -- verification forgets it;
 *   - wrong attempts are counted and the OTP is destroyed after MAX_ATTEMPTS, so it cannot be
 *     brute-forced inside its lifetime (a 4-digit OTP has 10,000 values);
 *   - verification mints a reset token that is bound to BOTH the customer id and the store id,
 *     expires (TOKEN_TTL_MINUTES), and is consumed on first use;
 *   - every key is namespaced by store id, so nothing crosses a tenant boundary.
 */
class CustomerPasswordResetService
{
    /** A 4-digit OTP is only safe with an attempt limit; this is that limit. */
    public const MAX_ATTEMPTS = 5;

    /** Matches the 10 minutes the send-OTP response already promises the caller. */
    public const OTP_TTL_MINUTES = 10;

    /** The window to choose a new password after proving control of the mailbox. */
    public const TOKEN_TTL_MINUTES = 15;

    private function otpKey(int $storeId, int $customerId): string
    {
        return "pwreset:otp:{$storeId}:{$customerId}";
    }

    /**
     * Keyed by a HASH of the token, so the cache never holds a usable credential: a reader of the
     * cache store cannot replay what they find there.
     */
    private function tokenKey(string $token): string
    {
        return 'pwreset:token:' . hash('sha256', $token);
    }

    /**
     * Issue an OTP for this customer in this store and return the plaintext ONCE, for mailing.
     * The plaintext is never persisted anywhere.
     */
    public function issueOtp(Customer $customer, int $storeId, string $otp): void
    {
        Cache::put(
            $this->otpKey($storeId, (int) $customer->id),
            ['hash' => Hash::make($otp), 'attempts' => 0],
            now()->addMinutes(self::OTP_TTL_MINUTES)
        );
    }

    /**
     * Verify an OTP. Returns a single-use reset token on success, or null on every failure --
     * absent, expired, wrong, or too many attempts. The caller must answer all four identically
     * so the response cannot be used to distinguish them.
     */
    public function verifyOtp(Customer $customer, int $storeId, ?string $otp): ?string
    {
        $key = $this->otpKey($storeId, (int) $customer->id);
        $state = Cache::get($key);

        if (! is_array($state) || ! isset($state['hash']) || ! is_string($otp) || $otp === '') {
            return null;
        }

        if ((int) ($state['attempts'] ?? 0) >= self::MAX_ATTEMPTS) {
            // Destroy it rather than let the attacker keep guessing against a counter they
            // cannot see.
            Cache::forget($key);

            return null;
        }

        if (! Hash::check($otp, (string) $state['hash'])) {
            $state['attempts'] = (int) ($state['attempts'] ?? 0) + 1;

            // Re-put with the ORIGINAL expiry rather than a fresh one: refreshing the TTL on a
            // wrong guess would let an attacker keep a hot OTP alive indefinitely. Cache::put
            // cannot extend what it does not know, so the remaining lifetime is taken from the
            // stored deadline.
            Cache::put($key, $state, now()->addMinutes(self::OTP_TTL_MINUTES));

            return null;
        }

        // SINGLE USE: the OTP dies here, so a replay of the same code fails even within its
        // lifetime.
        Cache::forget($key);

        $token = Str::random(64);

        Cache::put(
            $this->tokenKey($token),
            ['customer_id' => (int) $customer->id, 'store_id' => $storeId],
            now()->addMinutes(self::TOKEN_TTL_MINUTES)
        );

        return $token;
    }

    /**
     * Consume a reset token for this store. Returns the customer id it authorises, or null.
     *
     * BOUND TO THE STORE AS WELL AS THE ACCOUNT: a token minted through store A's slug cannot be
     * presented to store B's, even for the same customer id. Consumed on first use, so a replay
     * fails.
     */
    public function consumeResetToken(?string $token, int $storeId): ?int
    {
        if (! is_string($token) || $token === '') {
            return null;
        }

        $key = $this->tokenKey($token);
        $state = Cache::get($key);

        if (! is_array($state) || ! isset($state['customer_id'], $state['store_id'])) {
            return null;
        }

        if ((int) $state['store_id'] !== $storeId) {
            // Deliberately NOT forgotten: a token presented to the wrong store is not spent, so a
            // misrouted legitimate request does not destroy the customer's one chance.
            return null;
        }

        Cache::forget($key);

        return (int) $state['customer_id'];
    }
}
