<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\CustomResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * CORRECTED SCAFFOLD TEST, with one assertion deliberately left RED.
 *
 * THE CORRECTION, and the evidence that the old assertions were wrong. Three tests in
 * this file asserted Illuminate\Auth\Notifications\ResetPassword -- Laravel's own
 * built-in notification, which is what stock Breeze sends. This product replaced it:
 *
 *   app/Http/Controllers/Auth/PasswordResetLinkController.php:87
 *       $user->notify(new CustomResetPasswordNotification($token));
 *
 * and that class exists at app/Notifications/CustomResetPasswordNotification.php,
 * building its link from route('password.reset', ['token' => ..., 'email' => ...])
 * (line 32). Illuminate's ResetPassword is never constructed anywhere in app/. So the
 * old assertions could not pass by any route: they named a notification this product
 * does not send. They are corrected to name the one it does.
 *
 * This is a CORRECTION, not a weakening. The property under test is unchanged and is
 * now actually verified end to end: requesting a reset sends a tokened notification to
 * that user, and the token it carries really does reset the password. The old version
 * asserted the wrong class and therefore verified none of that.
 *
 * WHAT IS STILL RED HERE, ON PURPOSE -- CAUSE RE-MEASURED 2026-10-12.
 *
 * An earlier revision of this docblock named `owner_menu_settings` as the cause. That is
 * NO LONGER THE PROXIMATE CAUSE and the claim is corrected here rather than left to
 * mislead. Re-measured on this worktree, the render tests fail EARLIER, before the guest
 * layout ever reaches its OwnerMenuSetting query:
 *
 *   ErrorException: Attempt to read property "current_store" on null
 *   in app/Helper/helper.php:145
 *   (View: resources/views/layouts/guest.blade.php)
 *
 * Command: vendor/bin/phpunit --filter test_reset_password_link_screen_can_be_rendered
 * `owner_menu_settings` appears ZERO times in that output (measured with grep -c).
 *
 * THE PROXIMATE DEFECT, app/Helper/helper.php:143-145:
 *     $admin    = User::where('type', 'super admin')->first();
 *     $settings = Setting::where('store_id', $admin->current_store)->...
 * get_file() dereferences $admin with no null guard, so in any context with no
 * super-admin row -- which is every fresh RefreshDatabase database -- every page that
 * calls get_file() 500s. SEVEN of the suite's thirteen failures are this single line.
 * app/ is owned by another agent this phase; recorded as a cross-agent dependency.
 *
 * THE SECOND DEFECT IS STILL REAL, JUST MASKED. Once helper.php:145 is guarded, the
 * guest layout proceeds to resources/views/layouts/guest.blade.php:96
 *     \Workdo\LandingPage\Entities\OwnerMenuSetting::where('created_by', 1)->first()
 * and `owner_menu_settings` is still absent. MEASURED mechanism, and it is NOT the one
 * previously recorded here ("no loadMigrationsFrom registers any addon module"): all 69
 * modules DO call loadMigrationsFrom (69 occurrences under packages/). Two separate
 * things keep their tables out of the test database:
 *
 *   1. LandingPage IS loaded statically -- app/Providers/PackageServiceProvider.php:16
 *      hardcodes $staticAddons = ['LandingPage'] -- but its provider registers the WRONG
 *      CASE: packages/workdo/LandingPage/src/Providers/LandingPageServiceProvider.php:28
 *      loads __DIR__.'/../database/Migrations' while the directory on disk is
 *      src/database/migrations (lowercase). On a case-sensitive filesystem that path does
 *      not exist, so the three owner_menu* migrations are registered from nowhere. It is
 *      the only one of the 69 that uses src/Providers/ and capital Migrations.
 *   2. The other 68 load only if listed in `add_on_managers`
 *      (PackageServiceProvider::loadDynamicAddons()), which is empty in a fresh test
 *      database, so their providers never register at all.
 *
 * MEASURED CONSEQUENCE: the test database holds 95 tables; of the 80 distinct tables
 * declared by addon-module migrations, 1 is present and 79 are ABSENT. No test in this
 * suite exercises addon-module schema. packages/workdo/ is protected by
 * .github/scripts/guard-no-workdo-changes.sh and must not be edited to fix this.
 *
 * Both are left failing rather than skipped because they are real, reportable gaps and a
 * skip would hide them. Neither fix is in this agent's owned files.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    /**
     * BLOCKED, NOT A SCAFFOLD MISMATCH. See the class docblock. MEASURED proximate cause
     * is app/Helper/helper.php:145 dereferencing a null super-admin inside get_file(),
     * which 500s every page render in a fresh test database. The owner_menu_settings gap
     * sits behind it and surfaces once that line is guarded. Kept red so both stay visible.
     */
    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertStatus(200);
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        // CORRECTED: this product sends its own notification, not Laravel's.
        // PasswordResetLinkController.php:87.
        Notification::assertSentTo($user, CustomResetPasswordNotification::class);
    }

    /**
     * BLOCKED on the page render only. The notification half of this test is corrected
     * and does hold; the assertStatus(200) fails on the get_file() null dereference
     * (helper.php:145) described above, with owner_menu_settings behind it.
     */
    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, CustomResetPasswordNotification::class, function ($notification) {
            $response = $this->get('/reset-password/'.$notification->token);

            $response->assertStatus(200);

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, CustomResetPasswordNotification::class, function ($notification) use ($user) {
            $response = $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));

            return true;
        });
    }

    /**
     * ADDED COVERAGE, and the reason correcting the class above was worth doing rather
     * than deleting the tests. The three originals never proved the token WORKS -- they
     * only looked at which notification class was queued. This drives the product's real
     * reset path end to end and asserts the stored hash actually changed, which is the
     * property a password-reset test exists to defend.
     *
     * MEASURED: POST /reset-password -> 302 Location /login, no session errors, and
     * Hash::check() against the new password returns true.
     */
    public function test_the_emailed_token_actually_changes_the_stored_password(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $originalHash = $user->password;

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, CustomResetPasswordNotification::class, function ($notification) use ($user) {
            $this->assertNotEmpty($notification->token, 'The reset notification carried no token.');

            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'a-brand-new-password-9',
                'password_confirmation' => 'a-brand-new-password-9',
            ])->assertSessionHasNoErrors();

            return true;
        });

        $fresh = $user->fresh();

        $this->assertTrue(
            Hash::check('a-brand-new-password-9', $fresh->password),
            'The reset flow reported success but the stored password hash does not match '
            . 'the new password.'
        );
        $this->assertNotSame(
            $originalHash,
            $fresh->password,
            'The stored password hash is unchanged after a reportedly successful reset.'
        );
    }

    /**
     * ADDED COVERAGE: a forged token must not reset a password. The originals had no
     * negative case at all, so a reset endpoint that accepted anything would have passed
     * every one of them.
     */
    public function test_a_forged_token_cannot_reset_a_password(): void
    {
        $user = User::factory()->create();
        $originalHash = $user->password;

        $response = $this->post('/reset-password', [
            'token' => str_repeat('0', 64),
            'email' => $user->email,
            'password' => 'attacker-chosen-password-9',
            'password_confirmation' => 'attacker-chosen-password-9',
        ]);

        $response->assertSessionHasErrors('email');

        $this->assertSame(
            $originalHash,
            $user->fresh()->password,
            'A forged reset token changed the stored password.'
        );
        $this->assertFalse(
            Hash::check('attacker-chosen-password-9', $user->fresh()->password),
            'A forged reset token set the password to the attacker-supplied value.'
        );
    }
}
