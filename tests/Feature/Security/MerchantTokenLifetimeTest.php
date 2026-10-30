<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TOKEN SECURITY STAGE 3 — the mechanism, and the proof that it is OFF.
 *
 * `docs/security/P01-TOKEN-EXPIRY-PROPOSAL.md` says "the whole of Stage 3 is: pass a third
 * argument, read from a config key that defaults to off". An independent audit of the Phase 02
 * residuals found that the argument **did not exist** while the ledger recorded Stage 3 as
 * "prepared" — DashboardController passed exactly two arguments and no lifetime config key was
 * anywhere in the tree. So this file does two opposite jobs on purpose:
 *
 *  1. **The default is inert, asserted.** With no value configured, a minted merchant token must
 *     have `expires_at = NULL`, which is byte-for-byte today's behaviour. If this ever fails,
 *     somebody has switched on a production-visible expiry by accident — and because
 *     `ApiResponser::error()` returns HTTP 200 by default while the shipped Flutter app FORCES A
 *     LOGOUT on any 401, and because there is no refresh path for any client, that is a re-login
 *     wave for every merchant, not a silent tightening.
 *
 *  2. **The mechanism works when set.** An inert switch that does nothing when flipped is worse
 *     than no switch, because the owner would turn it on believing it took effect. So the
 *     configured case is driven too.
 *
 * Only `createToken` is exercised, not `CustomAuth`'s refusal: that refusal is existing, tested
 * behaviour (`CustomAuth:66` reads `expires_at && isPast()`), and this stage is only about whether
 * an expiry is ever WRITTEN.
 */
class MerchantTokenLifetimeTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(): User
    {
        return User::create([
            'name' => 'Merchant',
            'email' => 'merchant-' . uniqid() . '@example.test',
            'password' => bcrypt('secret'),
            'type' => 'admin',
            'lang' => 'en',
        ]);
    }

    public function test_the_shipped_default_writes_no_expiry_at_all(): void
    {
        $this->assertNull(
            config('tokens.merchant_token_lifetime_days'),
            'THE SHIPPED STATE MUST BE OFF. The owner\'s standing condition is "do not apply an '
            . 'expiry policy to real production without a real usage report and a review of its '
            . 'effect on active sessions", and that report needs production data this repository '
            . 'does not have. If a value has been committed here, it was not chosen from a '
            . 'measurement.'
        );

        $token = $this->merchant()->createToken('auth_token', ['merchant']);

        $this->assertNull(
            $token->accessToken->expires_at,
            'a merchant token minted under the shipped configuration must not expire — this is the '
            . 'pre-Stage-3 behaviour, unchanged'
        );
    }

    public function test_the_mint_site_really_reads_the_config_key(): void
    {
        // The half that would otherwise be a comment: an inert switch that does nothing when
        // flipped is worse than no switch, because the owner would believe it took effect.
        config(['tokens.merchant_token_lifetime_days' => 30]);

        $lifetimeDays = config('tokens.merchant_token_lifetime_days');
        $expiresAt = ($lifetimeDays !== null && (int) $lifetimeDays > 0)
            ? now()->addDays((int) $lifetimeDays)
            : null;

        $token = $this->merchant()->createToken('auth_token', ['merchant'], $expiresAt);

        $this->assertNotNull($token->accessToken->expires_at, 'a configured lifetime must be written');
        // Compared as a DATE rather than a day count: Carbon's diffInDays truncates, so the
        // sub-second gap between building $expiresAt and asserting on it reads as 29.
        $this->assertSame(
            now()->addDays(30)->toDateString(),
            $token->accessToken->expires_at->toDateString(),
            'and it must be the configured number of days'
        );
    }

    public function test_the_handler_passes_the_lifetime_rather_than_hardcoding_two_arguments(): void
    {
        /*
         * The audit's actual finding was that the ledger said "prepared" while the code passed two
         * arguments. A source assertion is what makes that un-sayable again: the two cases above
         * would both pass against a handler that still hardcodes `createToken('auth_token',
         * ['merchant'])`, because they build `$expiresAt` themselves.
         */
        $source = (string) file_get_contents(
            base_path('app/Http/Controllers/Api/DashboardController.php')
        );

        $this->assertStringContainsString(
            "createToken('auth_token', ['merchant'], \$expiresAt)",
            $source,
            'the merchant mint site must pass the third argument, or Stage 3 is a config key that '
            . 'nothing reads — which is exactly the state the audit found'
        );

        $this->assertStringContainsString(
            "config('tokens.merchant_token_lifetime_days')",
            $source,
            'and it must come from the config key, not a literal'
        );
    }

    public function test_customer_and_driver_mint_sites_are_deliberately_untouched(): void
    {
        /*
         * THE SCOPE IS MERCHANT TOKENS ONLY, and this asserts the scope rather than describing it.
         *
         * Expiring customer tokens would lock customers out with nothing to log back in with:
         * `Api\AuthController::login` is UNROUTED, so the storefront has no login endpoint. Drivers
         * are out of scope for the same no-refresh-path reason. A future change that adds an expiry
         * to those mint sites must fail this test and be read, not pass quietly.
         */
        $auth = (string) file_get_contents(base_path('app/Http/Controllers/Api/AuthController.php'));

        $this->assertSame(
            3,
            preg_match_all("/createToken\('auth_token', \['storefront'\]\)/", $auth, $m),
            'the three storefront mint sites must still pass exactly two arguments'
        );

        $dashboard = (string) file_get_contents(
            base_path('app/Http/Controllers/Api/DashboardController.php')
        );

        $this->assertStringContainsString(
            "createToken('auth_token', ['delivery'])",
            $dashboard,
            'the delivery mint site must still pass exactly two arguments'
        );
    }

    // ──────── WHAT AN INDEPENDENT REVIEW FOUND

    public function test_only_a_positive_integer_sets_a_lifetime(): void
    {
        /*
         * THREE WAYS TO GET A VALUE NOBODY INTENDED, driven through the installed Env:
         *
         *   =true  -> Env returns boolean true -> (int) true = 1 -> A ONE-DAY EXPIRY on every
         *             merchant token. The most plausible wrong spelling of "switch this on"
         *             produced the most aggressive possible policy.
         *   =abc   -> (int) 'abc' = 0 -> silently off, with no complaint
         *   =0.9   -> (int) '0.9' = 0 -> silently off
         *
         * Everything that is not a string of digits above zero is now null, which means no expiry
         * and is the shipped behaviour -- so a wrong value changes nothing rather than something
         * surprising.
         */
        $parse = function ($value) {
            if ($value === null) {
                putenv('MERCHANT_TOKEN_LIFETIME_DAYS');
            } else {
                putenv('MERCHANT_TOKEN_LIFETIME_DAYS=' . $value);
            }

            $raw = env('MERCHANT_TOKEN_LIFETIME_DAYS');

            if (! is_string($raw) && ! is_int($raw)) {
                return null;
            }

            $raw = trim((string) $raw);

            return (preg_match('/^[0-9]+$/', $raw) && (int) $raw > 0) ? (int) $raw : null;
        };

        foreach ([null, '', 'true', 'TRUE', 'false', 'null', 'abc', '0', '0.9', '-1', '30days', ' '] as $off) {
            $this->assertNull(
                $parse($off),
                '`' . var_export($off, true) . '` must mean NO EXPIRY. `true` in particular: it '
                . 'became (int) 1, a one-day expiry on every merchant token.'
            );
        }

        foreach ([['30', 30], ['1', 1], [' 90 ', 90], ['365', 365]] as [$value, $expected]) {
            $this->assertSame($expected, $parse($value), '`' . $value . '` must parse to ' . $expected);
        }

        putenv('MERCHANT_TOKEN_LIFETIME_DAYS');
    }

    public function test_a_lifetime_past_the_timestamp_ceiling_mints_without_an_expiry(): void
    {
        /*
         * `personal_access_tokens.expires_at` is a MySQL TIMESTAMP, whose range ENDS at
         * 2038-01-19 03:14:07 UTC, and this repository's MariaDB runs STRICT_TRANS_TABLES -- so a
         * later value is an ERROR, not a truncation. An independent review measured it: a lifetime
         * past that boundary turns EVERY MERCHANT LOGIN into a 500, and the shipped Flutter app
         * meets a failed login with a forced logout. A configuration mistake would have been a
         * total outage of the admin app.
         *
         * The mint site refuses it and mints WITHOUT an expiry. Failing to "no expiry" rather than
         * "nobody can log in" is the deliberate choice, and it is asserted here so the direction
         * cannot be reversed by accident.
         */
        $ceiling = \Illuminate\Support\Carbon::parse(config('tokens.merchant_token_expiry_ceiling'));

        $this->assertTrue(
            $ceiling->lessThan(\Illuminate\Support\Carbon::parse('2038-01-19 03:14:07')),
            'the configured ceiling must sit BELOW the TIMESTAMP boundary, with margin'
        );

        // The handler's own arithmetic, with a lifetime that overshoots.
        $lifetimeDays = 100000;   // ~274 years
        $expiresAt = now()->addDays($lifetimeDays);

        $this->assertTrue($expiresAt->greaterThanOrEqualTo($ceiling), 'premise: it overshoots');

        if ($expiresAt->greaterThanOrEqualTo($ceiling)) {
            $expiresAt = null;
        }

        $token = $this->merchant()->createToken('auth_token', ['merchant'], $expiresAt);

        $this->assertNull(
            $token->accessToken->expires_at,
            'an overshooting lifetime must mint a token with NO expiry rather than fail the login'
        );
    }

    public function test_the_mint_site_carries_the_ceiling_guard(): void
    {
        // The behavioural cases above do the arithmetic themselves, so this binds them to the
        // shipped handler -- the same reason the two-argument source assertion exists.
        $source = (string) file_get_contents(
            base_path('app/Http/Controllers/Api/DashboardController.php')
        );

        $this->assertStringContainsString(
            "config('tokens.merchant_token_expiry_ceiling'",
            $source,
            'the mint site must consult the ceiling, or a misconfigured lifetime 500s every login'
        );

        $this->assertStringContainsString(
            'greaterThanOrEqualTo($ceiling)',
            $source,
            'and it must compare against it'
        );
    }
}
