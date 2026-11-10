<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\XSS;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * P0-PROFILE — the XSS sweep was rewriting credentials, and the password check was unthrottled.
 * =================================================================================================
 *
 * `App\Http\Middleware\XSS` ran
 * `str_replace(['&lt;', '&gt;', 'javascript', 'alert'], '', $value)` over EVERY string in EVERY
 * request, and `password` was not exempt. MEASURED before the fix: `my alert pass` reached the
 * handler as `my  pass`.
 *
 * Two consequences, and the second is the worse one:
 *
 *   VERIFYING  `current_password` on `DELETE /profile` compared the MANGLED string against the
 *              stored hash, so a merchant whose password contains `alert` could not confirm it,
 *              and two different passwords can mangle to the same value.
 *   SETTING    a registration or a reset stored the mangled value, so the account's real password
 *              silently differed from what was typed — a credential the user has never seen.
 *
 * The sweep exists to stop markup reaching a view. A password is never rendered, so exempting it
 * removes no protection. The cases below assert BOTH halves: the credential is untouched, and a
 * field that is not a credential is still sanitised — because a "fix" that disabled the sweep
 * would pass the first half alone.
 */
class CredentialSanitisationTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * A password that the sweep would have mangled. Contains both offending substrings.
     */
    private const HOSTILE_PASSWORD = 'my alert javascript pass';

    public function test_the_sweep_would_have_mangled_this_password(): void
    {
        // THE PREMISE, READ FROM THE MIDDLEWARE AND NOT FROM A COPY OF IT.
        //
        // The first version of this case re-declared `['&lt;', '&gt;', 'javascript', 'alert']`
        // here. Independent review caught what that costs: if the sweep's list ever changed, this
        // premise would stay green against its own literal while the two cases it guards silently
        // started passing because there was nothing left to mangle. `XSS::STRIPPED_SUBSTRINGS`
        // exists so that this reads the real one.
        $mangled = str_replace(XSS::STRIPPED_SUBSTRINGS, '', self::HOSTILE_PASSWORD);

        $this->assertNotSame(
            self::HOSTILE_PASSWORD,
            $mangled,
            'the chosen password is not affected by the sweep, so these tests prove nothing'
        );
    }

    public function test_a_password_containing_alert_still_verifies(): void
    {
        $user = $this->makeStaff($this->makeMerchant(['email' => 'owner-cred@example.test']));
        $user->forceFill(['password' => Hash::make(self::HOSTILE_PASSWORD)])->save();

        $this->actingAs($user)->delete('/profile', ['password' => self::HOSTILE_PASSWORD]);

        $this->assertFalse(
            User::withoutGlobalScopes()->whereKey($user->id)->exists(),
            'The correct password was refused because the XSS sweep rewrote it before '
            . '`current_password` read it. The merchant cannot confirm a password they typed '
            . 'correctly.'
        );
    }

    public function test_a_wrong_password_is_still_refused(): void
    {
        $user = $this->makeStaff($this->makeMerchant(['email' => 'owner-cred2@example.test']));
        $user->forceFill(['password' => Hash::make(self::HOSTILE_PASSWORD)])->save();

        $this->actingAs($user)->delete('/profile', ['password' => 'not the password']);

        $this->assertTrue(
            User::withoutGlobalScopes()->whereKey($user->id)->exists(),
            'a wrong password deleted the account'
        );
    }

    /**
     * THE OTHER HALF. Exempting credentials must not become exempting everything: a field that is
     * not a credential is still swept.
     */
    public function test_a_non_credential_field_is_still_sanitised(): void
    {
        $owner = $this->makeMerchant(['email' => 'owner-cred3@example.test']);
        $this->grantPermission($owner, 'Edit User');

        $staff = $this->makeStaff($owner);

        $this->actingAs($owner)->put("/users/{$staff->id}", [
            'name' => 'Amina alert Haddad',
            'email' => $staff->email,
        ]);

        $this->assertSame(
            'Amina  Haddad',
            $staff->fresh()->name,
            'A non-credential field reached the handler unsanitised, so the exemption is too '
            . 'wide — the sweep has been disabled rather than scoped.'
        );
    }

    public function test_the_exemption_list_is_exactly_these_eight_keys(): void
    {
        // PINNED EXACTLY, not matched by shape. This case used to assert
        // `str_contains($key, 'password') || str_contains($key, 'token')`, which independent
        // review pointed out cannot fail for the thing it is named after: a future `api_token`,
        // `access_token`, `remember_token` or `password_hint` would all pass it, and it pinned
        // neither the contents of the list nor its size.
        //
        // An addition here is meant to be a deliberate act. Adding a key means editing this line
        // too, which is the moment somebody has to ask whether the field is ever RENDERED -- the
        // one question that decides whether exempting it is safe. `token` is the closest call and
        // was checked: its only rendered consumer is plans/Braintreepayment.blade.php:68, inside
        // a `<script>` where Blade's `{{ }}` escaping prevents both quote-breakout and `</script>`
        // because HTML entities are not decoded in that context.
        $this->assertSame(
            [
                'password',
                'password_confirmation',
                'current_password',
                'new_password',
                'new_password_confirmation',
                'old_password',
                'token',
                '_token',
            ],
            XSS::CREDENTIAL_KEYS,
            'The XSS sanitiser exemption list changed. Every key on it is a value the sweep will '
            . 'NOT rewrite, so a field that is rendered back into a view must never be added. '
            . 'Check the new key is never echoed anywhere, then update this list.'
        );
    }

    // =============================================================================================
    // THROTTLING
    // =============================================================================================

    /**
     * `ProfileController::destroy` validates `current_password`, and the route carried no
     * ThrottleRequests — so it was an unlimited password-confirmation oracle for anyone holding a
     * session.
     */
    public function test_repeated_password_attempts_are_throttled(): void
    {
        RateLimiter::clear('');

        $user = $this->makeStaff($this->makeMerchant(['email' => 'owner-throttle@example.test']));
        $user->forceFill(['password' => Hash::make('the real password')])->save();

        $statuses = [];

        // The bound is 6 per minute, so the seventh must be refused.
        for ($i = 0; $i < 7; $i++) {
            $statuses[] = $this->actingAs($user)
                ->delete('/profile', ['password' => 'wrong guess ' . $i])
                ->getStatusCode();
        }

        $this->assertContains(
            429,
            $statuses,
            'Seven password attempts in a row were all accepted, so `current_password` on this '
            . 'route is still an unlimited oracle. Statuses: ' . implode(',', $statuses)
        );

        $this->assertTrue(
            User::withoutGlobalScopes()->whereKey($user->id)->exists(),
            'the account was deleted during the throttle test'
        );
    }
}
