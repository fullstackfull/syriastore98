<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * SINGLE-USE, EXPIRING, ACCOUNT- AND STORE-BOUND RESET AUTHORISATION for the storefront API's
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
 *                             value is fixed and public, anyone who knew a target completed the
 *                             flow could sign in as them WITHOUT the OTP. It also resolved the
 *                             customer with no store predicate, so a customer of store A was
 *                             resettable through store B's slug.
 *
 * WHAT AN INDEPENDENT REVIEW OF THE FIRST FIX THEN FOUND, and this revision closes. The first
 * version of this service (commit 58b8e287) stopped both of the above, and six further defects
 * survived it. Each is now covered by a test in
 * tests/Feature/Security/CustomerPasswordResetAdversarialTest that FAILED against that version:
 *
 *   1. THE COMPATIBILITY BEARER WAS ANY LOGIN TOKEN. forgot-password-save accepted any
 *      unexpired Sanctum token belonging to a Customer of the store. A customer's ordinary
 *      long-lived login token therefore set that account's password with no OTP and no knowledge
 *      of the current password, so one stolen token was a permanent takeover and the OTP step was
 *      optional. A Bearer is now an authorisation only if THIS flow registered it, and it is
 *      consumed like any other.
 *   2. THE OTP STEP BOUGHT UNLIMITED RESETS. The reset token was single-use, but the Bearer
 *      returned alongside it was not, so one completed OTP step authorised a password change over
 *      and over for the token's whole life -- and Sanctum has no expiry configured here, so that
 *      life is unbounded. Both authorisations now share one generation record and die together.
 *   3. A SUCCESSFUL CHANGE LEFT SIBLING AUTHORISATIONS ALIVE. Two recovery rounds minted two
 *      tokens; spending one left the other usable. The generation record makes the newest round
 *      the only live one and a successful consumption retires it.
 *   4. A WRONG GUESS EXTENDED THE OTP'S LIFE. The increment was re-put with a FRESH ten minutes
 *      while the comment beside it claimed the opposite, so a guesser held a hot OTP open
 *      indefinitely. The deadline is now stored in the record and honoured.
 *   5. THE ATTEMPT LIMIT BOUND ONE CODE, NOT THE ACCOUNT. Re-sending reset the counter for free,
 *      so five guesses cost one extra request and the limit bounded nothing. Sends are now capped
 *      per account per window.
 *   6. THE COUNTER'S READ-MODIFY-WRITE WAS NOT ATOMIC. Concurrent guesses each read the same
 *      count and each wrote the same increment, so parallelism bought guesses the limit had
 *      already spent. The whole verify step now runs under a cache lock and fails closed if it
 *      cannot serialise.
 *
 * WHY THE STATE LIVES IN THE CACHE AND NOT IN A COLUMN. `customers.password_otp` is declared
 * `integer()` (database/migrations/2023_12_15_112855_create_customers_table.php:39) and NO
 * migration alters it. The web flow in Customer\Auth\CustomerLoginController:154 nevertheless
 * writes `Hash::make($otp)` -- a 60-character bcrypt string -- into it. SQLite's dynamic typing
 * accepts that, which is why it passes in tests; MySQL stores 0 (or errors in strict mode), so
 * `Hash::check()` can never match and THE SECURE WEB RESET IS BROKEN ON THE PRODUCTION ENGINE.
 * That is P01-SEC-13 and is reported separately with a prepared, unapplied migration. This
 * service must work on both engines today and must not require a migration the running server
 * has not had applied, so it keeps its state in the cache, which also gives expiry, single-use
 * semantics, attempt counting and locking without new schema.
 *
 * THE LOCK IS REAL ON EVERY DRIVER THIS SERVER COULD USE. ArrayStore, FileStore and
 * DatabaseStore all implement LockProvider (measured, all three). `database` is what
 * .env.example configures. If a driver without locking is ever configured, the guard below
 * degrades to the unlocked path rather than throwing -- which is why the serialisation claim is
 * stated as driver-dependent rather than absolute.
 */
class CustomerPasswordResetService
{
    /** A short numeric OTP is only safe with an attempt limit; this is that limit. */
    public const MAX_ATTEMPTS = 5;

    /** Matches the 10 minutes the send-OTP response already promises the caller. */
    public const OTP_TTL_MINUTES = 10;

    /** The window to choose a new password after proving control of the mailbox. */
    public const TOKEN_TTL_MINUTES = 15;

    /**
     * HOW MANY CODES ONE ACCOUNT MAY BE SENT PER WINDOW. Without this the attempt limit above is
     * decorative: an attacker spends five guesses, re-sends, and starts again.
     *
     * THE ARITHMETIC, because it decides whether the current code length is defensible.
     * MAX_SENDS_PER_WINDOW * MAX_ATTEMPTS = 25 guesses per hour per account. Against OTP_DIGITS
     * = 4 (10,000 values) that is a 0.25% chance per hour, so a patient attacker sustaining the
     * maximum rate reaches roughly a one-in-three chance over a week. Against six digits it is
     * 0.0025% per hour -- about 0.4% over the same week.
     */
    public const MAX_SENDS_PER_WINDOW = 5;

    public const SEND_WINDOW_MINUTES = 60;

    /**
     * THE DEFAULT STAYS AT FOUR, and the reason is a client this session cannot see.
     *
     * The caps above are what make four survivable; six would make it comfortable
     * (25 guesses/hour against 10,000 values is ~34% over a sustained week; against 1,000,000 it
     * is ~0.4%). The SERVER is entirely length-agnostic -- nothing validates the digit count,
     * verification is a Hash::check against whatever string arrives, and the mail template renders
     * `{{ $otp }}` into a percentage-width box -- and a test drives the whole flow end to end at
     * six digits to prove it.
     *
     * WHAT BLOCKS THE CHANGE is the storefront client that calls
     * api/{slug}/forgot-password-send-otp. It is in neither repository in this session, and the
     * only contract artefact that exists for the endpoint --
     * packages/workdo/Api/src/documentation/theme.json -- documents the request as
     * `"otp": "1234"`. If that client's input field is four characters wide, a six-digit code is
     * un-enterable and EVERY storefront password recovery breaks. That is a worse outcome than the
     * brute-force margin four digits leaves, so the default does not move until someone can read
     * that client.
     *
     * SO IT IS A CONFIGURATION VALUE, NOT A CODE CHANGE. Set CUSTOMER_OTP_DIGITS=6 in the
     * environment and the next issued code is six digits; no deploy, no code review, and it
     * reverses by removing the line. otpDigits() clamps to [MIN_OTP_DIGITS, MAX_OTP_DIGITS] so a
     * typo cannot produce a one-digit code -- a misconfiguration must not be able to weaken this.
     */
    public const OTP_DIGITS = 4;

    /** A code shorter than this is not defensible at any rate limit, so it is not permitted. */
    public const MIN_OTP_DIGITS = 4;

    /** Above this the code stops being something a person will type correctly. */
    public const MAX_OTP_DIGITS = 8;

    /**
     * How many digits the next issued code will have: `auth.customer_password_reset.otp_digits`,
     * clamped. A non-numeric or absent value falls back to OTP_DIGITS, so a broken config is the
     * current behaviour rather than an outage.
     */
    public function otpDigits(): int
    {
        $configured = config('auth.customer_password_reset.otp_digits', self::OTP_DIGITS);

        if (! is_numeric($configured)) {
            return self::OTP_DIGITS;
        }

        return max(self::MIN_OTP_DIGITS, min(self::MAX_OTP_DIGITS, (int) $configured));
    }

    /** How long a held lock survives a crashed request, and how long a caller waits for it. */
    private const LOCK_SECONDS = 10;

    private const LOCK_WAIT_SECONDS = 3;

    private function otpKey(int $storeId, int $customerId): string
    {
        return "pwreset:otp:{$storeId}:{$customerId}";
    }

    /**
     * THE GENERATION RECORD, and the mechanism behind guarantees 2 and 3 above. Every
     * authorisation minted by a verify step carries the generation current at that moment.
     * Verifying again replaces it, so the newest round is the only live one; consuming any
     * authorisation deletes it, so every sibling of the one just spent -- the reset token's twin
     * Bearer, an older round's leftovers -- stops authorising anything.
     */
    private function generationKey(int $storeId, int $customerId): string
    {
        return "pwreset:gen:{$storeId}:{$customerId}";
    }

    private function sendCountKey(int $storeId, int $customerId): string
    {
        return "pwreset:sends:{$storeId}:{$customerId}";
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
     * Keyed by the personal_access_tokens row id, which is not a secret -- the secret half of a
     * Sanctum token is never stored anywhere in plaintext and is not needed here. Holding the id
     * is what lets this flow tell a Bearer it minted from a Bearer it did not.
     */
    private function bearerKey(int $tokenId): string
    {
        return "pwreset:bearer:{$tokenId}";
    }

    /** Null when the configured cache driver cannot lock; see the class docblock. */
    private function lock(int $storeId, int $customerId): ?Lock
    {
        if (! Cache::getStore() instanceof LockProvider) {
            return null;
        }

        return Cache::lock("pwreset:lock:{$storeId}:{$customerId}", self::LOCK_SECONDS);
    }

    /**
     * Take the customer's lock and run $critical, or return $onContention if the lock cannot be
     * had within LOCK_WAIT_SECONDS.
     *
     * block() THROWS LockTimeoutException rather than returning false -- the first version of
     * this file tested it as a boolean, so a contended request would have left this service as a
     * 500 instead of a clean refusal. The test that holds the lock and asserts a refusal is what
     * found that.
     *
     * @template T
     * @param  callable(): T  $critical
     * @param  T  $onContention
     * @return T
     */
    private function withLock(int $storeId, int $customerId, callable $critical, $onContention)
    {
        $lock = $this->lock($storeId, $customerId);

        if ($lock === null) {
            // A cache driver with no locking. The critical section still runs -- refusing every
            // reset would be worse -- but it is not serialised; see the class docblock.
            return $critical();
        }

        try {
            $lock->block(self::LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            return $onContention;
        }

        try {
            return $critical();
        } finally {
            $lock->release();
        }
    }

    /**
     * Issue an OTP for this customer in this store. Returns false when the account's send budget
     * for the window is spent, so the caller refuses rather than minting another code.
     *
     * The plaintext is never persisted anywhere -- only a bcrypt hash of it, and only in the cache.
     */
    public function issueOtp(Customer $customer, int $storeId, string $otp): bool
    {
        $customerId = (int) $customer->id;
        $sendKey = $this->sendCountKey($storeId, $customerId);

        // Refuses on contention rather than minting a code whose send would not be counted.
        return $this->withLock($storeId, $customerId, function () use ($customer, $storeId, $customerId, $sendKey, $otp) {
            $sends = (int) Cache::get($sendKey, 0);

            if ($sends >= self::MAX_SENDS_PER_WINDOW) {
                return false;
            }

            // The window is fixed from the FIRST send, not refreshed by each one -- a rolling
            // window would never let the budget reset for a victim under sustained attack, which
            // turns a rate limit into a permanent lockout of the real account owner.
            //
            // The deadline is written on the FIRST send and read by every later one. An earlier
            // version of this method skipped writing it on the first send, so the window was
            // anchored at the SECOND -- which stretched it by however long the caller waited
            // between the two and so held the budget closed longer than documented.
            Cache::put($sendKey, $sends + 1, $this->windowDeadline($sendKey));

            Cache::put(
                $this->otpKey($storeId, $customerId),
                [
                    'hash' => Hash::make($otp),
                    'attempts' => 0,
                    // The deadline, so a wrong guess re-puts the record without buying time.
                    'expires_at' => now()->addMinutes(self::OTP_TTL_MINUTES)->getTimestamp(),
                ],
                now()->addMinutes(self::OTP_TTL_MINUTES)
            );

            return true;
        }, false);
    }

    /**
     * The cache cannot be asked how long a key has left, so the window's own deadline is kept
     * beside the counter under a second key. Losing it degrades to a fresh window, never to an
     * unbounded one.
     */
    private function windowDeadline(string $sendKey): Carbon
    {
        $deadline = Cache::get($sendKey . ':until');

        if (is_int($deadline) && $deadline > now()->getTimestamp()) {
            return Carbon::createFromTimestamp($deadline);
        }

        $until = now()->addMinutes(self::SEND_WINDOW_MINUTES);
        Cache::put($sendKey . ':until', $until->getTimestamp(), $until);

        return $until;
    }

    /**
     * Verify an OTP. Returns a single-use reset token on success, or null on every failure --
     * absent, expired, wrong, too many attempts, or unserialisable. The caller must answer all of
     * them identically so the response cannot be used to distinguish them.
     *
     * RUNS UNDER A LOCK. The read, the attempt check, the increment and the write are one
     * critical section, so concurrent guesses cannot each spend the same attempt. A caller that
     * cannot take the lock within LOCK_WAIT_SECONDS is refused: failing closed costs a legitimate
     * user a retry, failing open costs the attempt limit its meaning.
     */
    public function verifyOtp(Customer $customer, int $storeId, ?string $otp): ?string
    {
        $customerId = (int) $customer->id;
        $key = $this->otpKey($storeId, $customerId);

        return $this->withLock($storeId, $customerId, function () use ($key, $otp, $customerId, $storeId) {
            $state = Cache::get($key);

            if (! is_array($state) || ! isset($state['hash']) || ! is_string($otp) || $otp === '') {
                return null;
            }

            $deadline = isset($state['expires_at']) ? (int) $state['expires_at'] : null;

            if ($deadline !== null && $deadline <= now()->getTimestamp()) {
                Cache::forget($key);

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

                // RE-PUT WITH THE ORIGINAL DEADLINE. A fresh TTL here -- which is what the first
                // version of this file did, while its comment claimed otherwise -- lets a guesser
                // hold a hot OTP alive for as long as they keep guessing.
                Cache::put(
                    $key,
                    $state,
                    $deadline !== null
                        ? Carbon::createFromTimestamp($deadline)
                        : now()->addMinutes(self::OTP_TTL_MINUTES)
                );

                return null;
            }

            // SINGLE USE: the OTP dies here, so a replay of the same code fails even within its
            // lifetime.
            Cache::forget($key);

            return $this->mintResetToken($customerId, $storeId);
        }, null);
    }

    /**
     * Open a new generation and mint the reset token that belongs to it. Any authorisation from an
     * earlier generation is dead from this moment.
     */
    private function mintResetToken(int $customerId, int $storeId): string
    {
        $generation = Str::random(32);

        Cache::put(
            $this->generationKey($storeId, $customerId),
            ['generation' => $generation, 'bearer_token_id' => null],
            now()->addMinutes(self::TOKEN_TTL_MINUTES)
        );

        $token = Str::random(64);

        Cache::put(
            $this->tokenKey($token),
            [
                'customer_id' => $customerId,
                'store_id' => $storeId,
                'generation' => $generation,
            ],
            now()->addMinutes(self::TOKEN_TTL_MINUTES)
        );

        return $token;
    }

    /**
     * Register the Sanctum token that verify-otp hands back as an authorisation for THIS recovery
     * round, so the compatibility path accepts it and accepts nothing else.
     *
     * This is the whole of finding 1. Without it the fallback accepted any customer token, which
     * made an ordinary login session a password-reset credential. The registration carries the
     * current generation, so it dies with its twin reset token rather than outliving it.
     */
    public function registerRecoveryBearer(int $tokenId, int $customerId, int $storeId): void
    {
        $generationKey = $this->generationKey($storeId, $customerId);
        $record = Cache::get($generationKey);

        if (! is_array($record) || ! isset($record['generation'])) {
            return;
        }

        // RECORDED ON THE GENERATION, NOT ONLY ON THE BEARER'S OWN KEY, so the session this round
        // minted is identified however the caller later authorises the save. An earlier version
        // kept it only under the Bearer's key, so a client that presented the RESET TOKEN had its
        // fresh session revoked along with the old ones -- correct for security and a needless
        // mid-flow logout for a legitimate client.
        $record['bearer_token_id'] = $tokenId;

        Cache::put($generationKey, $record, now()->addMinutes(self::TOKEN_TTL_MINUTES));

        Cache::put(
            $this->bearerKey($tokenId),
            [
                'customer_id' => $customerId,
                'store_id' => $storeId,
                'generation' => $record['generation'],
            ],
            now()->addMinutes(self::TOKEN_TTL_MINUTES)
        );
    }

    /**
     * Consume a reset token for this store.
     *
     * @return array{customer_id: int, keep_token_id: int|null}|null
     */
    public function consumeResetToken(?string $token, int $storeId): ?array
    {
        if (! is_string($token) || $token === '') {
            return null;
        }

        return $this->consume($this->tokenKey($token), $storeId);
    }

    /**
     * Consume the Bearer authorisation this flow registered for a Sanctum token id. A token this
     * flow did not mint has no record and authorises nothing.
     *
     * @return array{customer_id: int, keep_token_id: int|null}|null
     */
    public function consumeRecoveryBearer(?int $tokenId, int $storeId): ?array
    {
        if ($tokenId === null) {
            return null;
        }

        return $this->consume($this->bearerKey($tokenId), $storeId);
    }

    /**
     * The one path by which an authorisation is spent.
     *
     * BOUND TO THE STORE AS WELL AS THE ACCOUNT: a token minted through store A's slug cannot be
     * presented to store B's, even for the same customer id. Bound to its generation, so it stops
     * working the moment a newer round begins or any sibling is spent. Consumed on first use, so
     * a replay fails.
     *
     * @return array{customer_id: int, keep_token_id: int|null}|null
     */
    private function consume(string $key, int $storeId): ?array
    {
        // AN UNLOCKED PEEK, ONLY TO LEARN WHICH CUSTOMER'S LOCK TO TAKE. Nothing is decided here
        // and nothing is written; every check is repeated inside the critical section below.
        $peek = Cache::get($key);

        if (! is_array($peek) || ! isset($peek['customer_id'], $peek['store_id'], $peek['generation'])) {
            return null;
        }

        if ((int) $peek['store_id'] !== $storeId) {
            // Deliberately NOT forgotten: a token presented to the wrong store is not spent, so a
            // misrouted legitimate request does not destroy the customer's one chance.
            return null;
        }

        $customerId = (int) $peek['customer_id'];

        // SPENDING AN AUTHORISATION IS A READ-MODIFY-WRITE AND MUST BE SERIALISED.
        //
        // An independent review of the previous package found this method outside the lock while
        // issueOtp() and verifyOtp() were inside it. Two concurrent requests presenting the same
        // reset token both read the state, both saw the generation match, both forgot it and both
        // returned an authorisation -- so ONE completed OTP step authorised TWO password changes
        // and the single-use guarantee this service exists to provide did not hold under
        // concurrency. It is the same defect the attempt counter had, in the other direction.
        //
        // Refuses on contention rather than proceeding unserialised, exactly as verifyOtp does.
        return $this->withLock($storeId, $customerId, function () use ($key, $storeId, $customerId) {
            // Re-read under the lock: the peek above may be stale by now.
            $state = Cache::get($key);

            if (! is_array($state) || ! isset($state['customer_id'], $state['store_id'], $state['generation'])) {
                return null;
            }

            if ((int) $state['store_id'] !== $storeId || (int) $state['customer_id'] !== $customerId) {
                return null;
            }

            $generationKey = $this->generationKey($storeId, $customerId);
            $record = Cache::get($generationKey);

            if (! is_array($record) || ($record['generation'] ?? null) !== $state['generation']) {
                // A newer round has begun, or a sibling authorisation was already spent. Forget
                // this one too -- it is dead and keeping it serves no one.
                Cache::forget($key);

                return null;
            }

            // RETIRE THE WHOLE GENERATION, not just this key. That is what makes one completed OTP
            // step authorise exactly one password change.
            Cache::forget($generationKey);
            Cache::forget($key);

            return [
                'customer_id' => $customerId,
                // The session this recovery round minted, which the caller keeps when it revokes
                // the account's other sessions -- whichever authorisation was spent.
                'keep_token_id' => isset($record['bearer_token_id']) ? (int) $record['bearer_token_id'] : null,
            ];
        }, null);
    }
}
