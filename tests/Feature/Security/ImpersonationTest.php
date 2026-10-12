<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

/**
 * SEC-F6 -- IMPERSONATION, platform takeover.
 *
 * The verdict here is CONFIRMED / CRITICAL / P0, superseding an earlier
 * REFUTED. The refutation rested on `grep -rn impersonate routes/*.php`
 * returning nothing -- which it does, because the routes are not named
 * "impersonate": they are `store/{id}/login-with-admin` and
 * `login-with-admin/exit`.
 *
 * The only gate was `auth()->check()` -- "is ANYONE logged in", not "may YOU
 * do this". Combined with open self-registration granting type=admin and a
 * committed APP_KEY making `decrypt($id)` forgeable, the chain was:
 * register -> forge an encrypted id -> become any account, including the
 * platform super admin.
 *
 * In every denial case the identity assertion is load-bearing: a 403 alone
 * would pass even if Impersonate::take() had already run.
 */
class ImpersonationTest extends SecurityTestCase
{
    use RefreshDatabase;


    /**
     * Disable App\Http\Middleware\XSS for platform-admin HTTP cases.
     *
     * XSS.php:27-49 redirects ANY user whose type is 'super admin' to the
     * LaravelUpdater welcome page whenever it finds pending migrations -- which
     * is always true on a from-zero test schema, because the 69 WorkDo module
     * migrations have not run. It is a migration-nag, not an authorization
     * control, so disabling it isolates the behaviour under test. The `auth`
     * and `verified` middleware stay on.
     */
    private function actingAsPlatformAdmin(User $admin): self
    {
        return $this->withoutMiddleware(\App\Http\Middleware\XSS::class)
            ->actingAs($admin);
    }

    private function impersonateUrl(User $target): string
    {
        return '/store/' . Crypt::encrypt($target->id) . '/login-with-admin';
    }

    public function test_ordinary_merchant_cannot_impersonate(): void
    {
        [$attacker] = $this->makeTenant();
        [$victim] = $this->makeTenant();

        $response = $this->actingAs($attacker)->get($this->impersonateUrl($victim));

        $response->assertForbidden();

        $this->assertSame(
            $attacker->id,
            auth()->id(),
            'The caller identity must be unchanged after a denied impersonation.'
        );
    }

    /**
     * THE TEST WHOSE ABSENCE LET SEC-F6 BE MIS-SCORED. It encrypts the target
     * id with the application key, exactly as an attacker holding the
     * committed APP_KEY would, and proves the control is the authorize() call
     * and NOT decrypt().
     */
    public function test_forged_encrypted_id_is_denied(): void
    {
        [$attacker] = $this->makeTenant();
        $platformAdmin = $this->makePlatformAdmin();

        // A perfectly valid ciphertext for the platform root's id.
        $forged = '/store/' . Crypt::encrypt($platformAdmin->id) . '/login-with-admin';

        $this->actingAs($attacker)->get($forged)->assertForbidden();

        $this->assertSame($attacker->id, auth()->id());
    }

    public function test_anonymous_caller_cannot_impersonate(): void
    {
        [$victim] = $this->makeTenant();

        $this->get($this->impersonateUrl($victim));

        $this->assertFalse(
            auth()->check(),
            'An anonymous caller must not end up authenticated as anyone.'
        );
    }

    /**
     * A platform admin can never be impersonated, not even by another platform
     * admin, so no one can laterally assume platform root.
     */
    public function test_platform_admin_cannot_be_impersonated(): void
    {
        $actor = $this->makePlatformAdmin();
        $target = $this->makePlatformAdmin();

        $this->actingAsPlatformAdmin($actor)
            ->get($this->impersonateUrl($target))
            ->assertForbidden();

        $this->assertSame($actor->id, auth()->id());
    }

    /**
     * THE POSITIVE CASE. Without it the fix could "pass" by denying everyone
     * and silently breaking a real support workflow.
     */
    public function test_platform_admin_can_still_impersonate_a_merchant(): void
    {
        $platformAdmin = $this->makePlatformAdmin();
        [$merchant] = $this->makeTenant();

        $response = $this->actingAsPlatformAdmin($platformAdmin)
            ->get($this->impersonateUrl($merchant));

        // The redirect target is the controller's own, proving authorize()
        // passed and Impersonate::take() ran.
        $response->assertRedirect('dashboard');
    }

    public function test_exit_admin_without_active_impersonation_is_rejected(): void
    {
        [$merchant] = $this->makeTenant();

        $response = $this->actingAs($merchant)->get('/login-with-admin/exit');

        // Must not be a 500 from calling leaveImpersonation() unconditionally.
        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertSame($merchant->id, auth()->id());
    }

    /**
     * The two model overrides are asserted directly, independently of the
     * controller, because they are the second half of the control: the Lab404
     * trait's own defaults are not relied upon.
     */
    public function test_model_level_impersonation_predicates(): void
    {
        $platformAdmin = $this->makePlatformAdmin();
        [$merchant] = $this->makeTenant();

        $this->assertTrue($platformAdmin->canImpersonate());
        $this->assertFalse($merchant->canImpersonate());

        $this->assertFalse($platformAdmin->canBeImpersonated());
        $this->assertTrue($merchant->canBeImpersonated());
    }
}
