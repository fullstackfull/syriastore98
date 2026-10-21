<?php

namespace Tests\Feature\Security;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * SEC login-throttle usability defect.
 *
 * RouteServiceProvider defined the `auth` limiter as
 * perMinute(3)->by($request->ip()), which is a shared-IP denial of service
 * dressed up as a security control:
 *
 *   * every merchant behind one office NAT, one mobile carrier CGNAT or one
 *     corporate proxy shares a single bucket, so three bad attempts by any one
 *     of them locks out all of them;
 *   * an attacker on a different address is entirely unaffected;
 *   * a real password-guessing attack targets one ACCOUNT from many addresses,
 *     which an ip()-only key cannot even see.
 *
 * It is also a support problem rather than just an annoyance: the shipped
 * Flutter app maps HTTP 429 to a generic error with no retry affordance, so a
 * throttled merchant sees an unexplained failure.
 *
 * These tests resolve the REAL limiter closure out of the container and invoke
 * it, rather than asserting on the source text.
 *
 * No database is touched, so this extends TestCase directly.
 */
class AuthRateLimiterTest extends TestCase
{
    private function limit(string $ip, ?string $email): Limit
    {
        $callback = RateLimiter::limiter('auth');

        $this->assertNotNull($callback, 'The `auth` rate limiter is not registered.');

        $request = Request::create('/login', 'POST', $email === null ? [] : ['email' => $email]);
        $request->server->set('REMOTE_ADDR', $ip);

        return $callback($request);
    }

    public function test_the_auth_limiter_is_registered_and_allows_a_usable_rate(): void
    {
        $limit = $this->limit('203.0.113.7', 'merchant@example.test');

        $this->assertSame(
            10,
            $limit->maxAttempts,
            'Three attempts per minute locks out a whole NAT on one typo.'
        );

        // The property is decaySeconds in this Laravel version, not decayMinutes.
        $this->assertSame(60, $limit->decaySeconds, 'The window must stay one minute.');
    }

    /**
     * THE DEFECT. Two different merchants behind one shared address must not
     * share a bucket.
     */
    public function test_two_accounts_behind_one_shared_address_get_separate_buckets(): void
    {
        $a = $this->limit('203.0.113.7', 'merchant-a@example.test');
        $b = $this->limit('203.0.113.7', 'merchant-b@example.test');

        $this->assertNotSame(
            $a->key,
            $b->key,
            'Two accounts on one NAT still share a throttle bucket, so either can '
            . 'lock out the other.'
        );
    }

    /**
     * The security half must survive: repeated attempts against the SAME account
     * from the same address must still collide, or the throttle does nothing.
     */
    public function test_repeated_attempts_on_one_account_share_a_bucket(): void
    {
        $first = $this->limit('203.0.113.7', 'merchant@example.test');
        $second = $this->limit('203.0.113.7', 'merchant@example.test');

        $this->assertSame(
            $first->key,
            $second->key,
            'Attempts against one account must accumulate, or brute force is unthrottled.'
        );
    }

    /**
     * The same account attacked from two addresses must not share a bucket
     * either -- the key is ip + email, so each address is tracked on its own.
     * This documents the limit of this control honestly: it bounds per-address
     * guessing, not a distributed attack on one account. Bounding that needs a
     * per-account limiter, which is an owner decision because it introduces a
     * remote account-lockout vector.
     */
    public function test_the_key_is_scoped_per_address(): void
    {
        $a = $this->limit('203.0.113.7', 'merchant@example.test');
        $b = $this->limit('198.51.100.9', 'merchant@example.test');

        $this->assertNotSame($a->key, $b->key);
    }

    /**
     * The raw email must not end up in the cache key.
     */
    public function test_the_submitted_email_is_not_stored_in_the_key(): void
    {
        $email = 'merchant@example.test';
        $limit = $this->limit('203.0.113.7', $email);

        $this->assertStringNotContainsString(
            $email,
            $limit->key,
            'A cache key is not a place to store a raw user identifier.'
        );
    }

    /**
     * Case must not create a second bucket, or `Merchant@x` and `merchant@x`
     * would each get a full allowance of attempts against one account.
     */
    public function test_the_email_is_case_normalised(): void
    {
        $lower = $this->limit('203.0.113.7', 'merchant@example.test');
        $mixed = $this->limit('203.0.113.7', 'Merchant@Example.TEST');

        $this->assertSame(
            $lower->key,
            $mixed->key,
            'Case variation must not double the allowance against one account.'
        );
    }

    /**
     * A request with no email at all must still be throttled, not crash and not
     * fall into an unbounded bucket.
     */
    public function test_a_request_with_no_email_is_still_throttled(): void
    {
        $limit = $this->limit('203.0.113.7', null);

        $this->assertSame(10, $limit->maxAttempts);
        $this->assertNotEmpty($limit->key);
    }

    /**
     * `email[]=x` is request input and `(string) $array` is an ErrorException. The closure runs
     * before the route's handler, so this was a 500 on every `throttle:auth` route -- the login forms
     * and, since P1-ORD-08, the guest order lookup. An array-shaped email is throttled in the same
     * bucket as an absent one.
     */
    public function test_an_array_shaped_email_is_throttled_not_a_crash(): void
    {
        $callback = RateLimiter::limiter('auth');

        $request = Request::create('/login', 'POST', ['email' => ['a@example.test', 'b@example.test']]);
        $request->server->set('REMOTE_ADDR', '203.0.113.9');

        $limit = $callback($request);

        $this->assertSame(10, $limit->maxAttempts);
        $this->assertSame(
            $this->limit('203.0.113.9', null)->key,
            $limit->key,
            'An array-shaped email must land in the same bucket as an absent one.'
        );
    }
}
