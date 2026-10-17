<?php

namespace Tests\Feature\Lynomia;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Services\AccountProvisioner;
use Workdo\LynomiaChat\app\Services\CommerceCredential;
use Workdo\LynomiaChat\app\Services\InstallationLifecycle;
use Workdo\LynomiaChat\app\Support\LinkState;

/**
 * L1. SUSPENSION WAS A ONE-WAY DOOR, AND THESE TESTS EXIST TO KEEP IT OPEN BOTH WAYS.
 *
 * THE DEFECT. Lynomia will not re-enable an installation it has not verified:
 * `Commerce::StoreConnection#enable` runs the provider health check, which is exactly one GET of
 * this side's commerce IDENTITY endpoint. Store Syria pushes a lifecycle transition to Lynomia
 * BEFORE writing it locally -- deliberately, so this side can never claim "active" while Lynomia
 * is still refusing to send. Each rule is right; together they deadlocked. A resume asked Lynomia
 * to health-check a store whose link was still `suspended`, `AuthenticateCommerceApi` refused the
 * identity read with 403, Lynomia answered `resume_failed` (409), and the unguarded push threw --
 * so the local transition never happened.
 *
 * Measured against a live pair before the fix: `resume()` raised a conflict and
 * `reauthenticate()` reported `back_online=false`, both leaving the link `suspended`. Recovery
 * required editing the row by hand. Neither repository's suite caught it, because each drives a
 * fake of the other side: this side's fake answers the lifecycle PUT without ever running
 * Lynomia's health check.
 *
 * THE FIX IS NOT A WIDER GATE. `LinkState::SSO_ALLOWED` is untouched and no data route gained
 * anything. What was added is a recovery window: opened only by an authorised lifecycle recovery,
 * good for ONE read of the identity route, expiring in seconds, closed again in a `finally`, and
 * audited at open, spend and close. It authorises nothing on its own -- a request still has to
 * carry that store's own valid commerce credential to reach the check at all.
 *
 * WHAT THESE TESTS ARE FOR. The first one fails if the window mechanism is removed. The rest fail
 * if it is ever made wider than it is: the data endpoints, the single use, the expiry, the tenant
 * boundary and the ineligible states are each asserted separately, because each is a different
 * way the exception could grow into a hole.
 */
class LynomiaRecoveryWindowTest extends TestCase
{
    use InteractsWithLynomia;
    use RefreshDatabase;

    private const IDENTITY = '/api/lynomia/commerce/v1/store';

    private const DATA = '/api/lynomia/commerce/v1/orders';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLynomiaHarness();
    }

    /** @return array{0: LynomiaStoreLink, 1: string, 2: \App\Models\User} */
    private function suspendedInstallation(int $accountId = 7001): array
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia(['account_id' => $accountId]);
        $link = app(AccountProvisioner::class)->provision($store, $owner)->fresh();
        $link->forceFill(['state' => LinkState::SUSPENDED])->save();

        return [$link->fresh(), CommerceCredential::issue($link->fresh()), $owner->fresh()];
    }

    private function identity(string $token): \Illuminate\Testing\TestResponse
    {
        return $this->getJson(self::IDENTITY, ['Authorization' => 'Bearer ' . $token]);
    }

    private function data(string $token): \Illuminate\Testing\TestResponse
    {
        return $this->getJson(self::DATA . '?number=SO-1', ['Authorization' => 'Bearer ' . $token]);
    }

    // =================================================== the regression itself

    /**
     * THE ONE THAT FAILS IF THE MECHANISM IS REMOVED.
     *
     * Lynomia's health check is simulated where it actually happens: inside the handler for the
     * lifecycle PUT, which is the only moment the real one runs. If the identity read is refused
     * there -- as it was before the fix -- the transition is refused and this example fails.
     */
    public function test_a_window_is_open_while_the_resume_push_is_in_flight(): void
    {
        [$link, , $owner] = $this->suspendedInstallation();
        $openDuringPush = null;
        $stateDuringPush = null;

        // The harness's `fail` map is matched on verb+path and returned AS IS, unwrapping a
        // Closure first -- so it is also the only place a test can observe the moment the push
        // is in flight. That is the moment Lynomia's health check runs, and the only moment the
        // window needs to be open.
        $this->lynomiaScript['fail'] = [
            'PUT /platform/api/v1/accounts/7001/installation_lifecycle'
                => function () use ($link, &$openDuringPush, &$stateDuringPush) {
                    $fresh = $link->fresh();
                    $openDuringPush = $fresh->recovery_window_expires_at !== null
                        && $fresh->recovery_window_consumed_at === null;
                    // AND the installation is still suspended at that instant, which is the whole
                    // reason the window has to exist.
                    $stateDuringPush = $fresh->state;

                    return Http::response(['status' => 'active', 'applied' => true], 200);
                },
        ];

        $after = app(InstallationLifecycle::class)->resume($link, $owner, 'recovery');

        $this->assertTrue($openDuringPush, 'a recovery window must be open while the push is in flight');
        $this->assertSame(LinkState::SUSPENDED, $stateDuringPush,
            'the link must still be suspended during the push -- that is why the window is needed');
        $this->assertSame(LinkState::ACTIVE, $after->state);
    }

    public function test_a_suspended_installation_is_refused_the_identity_endpoint_with_no_window(): void
    {
        [, $token] = $this->suspendedInstallation();

        $this->identity($token)->assertStatus(403);
    }

    // =================================================== the exception must stay narrow

    /** The property that matters most: a window is not a way into anyone's data. */
    public function test_an_open_window_does_not_unlock_the_data_endpoints(): void
    {
        [$link, $token, $owner] = $this->suspendedInstallation();
        $link->openRecoveryWindow('resume', (int) $owner->id);

        $this->data($token)->assertStatus(403);

        // and the window is still unspent, so a data attempt cannot even burn it
        $this->assertNull($link->fresh()->recovery_window_consumed_at);
    }

    public function test_a_window_is_good_for_exactly_one_identity_read(): void
    {
        [$link, $token, $owner] = $this->suspendedInstallation();
        $link->openRecoveryWindow('resume', (int) $owner->id);

        $this->identity($token)->assertStatus(200);
        $this->identity($token)->assertStatus(403);
    }

    public function test_an_expired_window_authorises_nothing(): void
    {
        [$link, $token, $owner] = $this->suspendedInstallation();
        $link->openRecoveryWindow('resume', (int) $owner->id);
        $link->fresh()->forceFill(['recovery_window_expires_at' => now()->subSecond()])->save();

        $this->identity($token)->assertStatus(403);
    }

    public function test_a_closed_window_authorises_nothing(): void
    {
        [$link, $token, $owner] = $this->suspendedInstallation();
        $link->openRecoveryWindow('resume', (int) $owner->id);
        $link->fresh()->closeRecoveryWindow();

        $this->identity($token)->assertStatus(403);
    }

    /** A window belongs to one store, and the credential that reaches it belongs to one store. */
    public function test_one_stores_window_cannot_be_spent_by_another_store(): void
    {
        [$a, , $ownerA] = $this->suspendedInstallation(7001);
        [$b] = $this->suspendedInstallation(7002);

        $a->openRecoveryWindow('resume', (int) $ownerA->id);

        $this->assertFalse(LynomiaStoreLink::spendRecoveryWindow((int) $b->store_id),
            "store B must not be able to spend store A's window");
        $this->assertNull($a->fresh()->recovery_window_consumed_at,
            "store A's window must still be unspent");
    }

    public function test_a_decommissioned_installation_cannot_open_a_window(): void
    {
        [$link, $token, $owner] = $this->suspendedInstallation();
        $link->fresh()->forceFill(['state' => LinkState::DECOMMISSIONED])->save();

        $this->assertFalse($link->fresh()->openRecoveryWindow('resume', (int) $owner->id));
        $this->identity($token)->assertStatus(403);
    }

    public function test_a_pending_installation_cannot_open_a_window(): void
    {
        [$link, , $owner] = $this->suspendedInstallation();
        $link->fresh()->forceFill(['state' => LinkState::PENDING_VERIFICATION])->save();

        $this->assertFalse($link->fresh()->openRecoveryWindow('resume', (int) $owner->id));
    }

    // =================================================== opened only where it is needed

    public function test_a_suspend_opens_no_window(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia(['account_id' => 7003]);
        $link = app(AccountProvisioner::class)->provision($store, $owner)->fresh();

        app(InstallationLifecycle::class)->suspend($link, $owner, 'hold');

        $this->assertNull($link->fresh()->recovery_window_expires_at);
        $this->assertSame(0, AuditLog::where('action', 'lynomia_recovery_window_opened')->count());
    }

    /** A refused push must not leave a usable window behind -- hence the `finally`. */
    public function test_a_refused_push_still_closes_the_window(): void
    {
        [$link, $token, $owner] = $this->suspendedInstallation();
        $this->lynomiaScript['fail'] = [
            'PUT /platform/api/v1/accounts/7001/installation_lifecycle' => Http::response(['error' => 'down'], 500),
        ];

        try {
            app(InstallationLifecycle::class)->resume($link, $owner, 'recovery');
        } catch (\Throwable) {
            // the push failed, which is this example's premise
        }

        $this->assertNull($link->fresh()->recovery_window_expires_at, 'the window must be closed');
        $this->identity($token)->assertStatus(403);
        $this->assertSame(LinkState::SUSPENDED, $link->fresh()->state);
    }

    public function test_the_window_lifecycle_is_audited(): void
    {
        [$link, $token, $owner] = $this->suspendedInstallation();
        $link->openRecoveryWindow('resume', (int) $owner->id);
        $this->identity($token)->assertStatus(200);

        $this->assertSame(1, AuditLog::where('action', 'lynomia_recovery_window_spent')->count());
    }

    public function test_resume_audits_the_window_being_opened_and_closed(): void
    {
        [$link, , $owner] = $this->suspendedInstallation();

        app(InstallationLifecycle::class)->resume($link, $owner, 'recovery');

        $this->assertSame(1, AuditLog::where('action', 'lynomia_recovery_window_opened')->count());
        $this->assertSame(1, AuditLog::where('action', 'lynomia_recovery_window_closed')->count());
    }

    // =================================================== concurrency

    /**
     * A DECOMMISSION LANDING INSIDE AN OPEN RECOVERY WINDOW -- the interleaving that actually
     * carries a risk, and the one InstallationLifecycle's in-lock guard was written for.
     *
     * WHY NOT A CONCURRENT SUSPEND. Measured: suspending an installation that is already
     * suspended is accepted as a no-op by design (`move` tolerates a transition to the state the
     * link is already in, so a retried request is not an error), so interleaving one proves
     * nothing -- the resume completes and the installation is legitimately ACTIVE. DECOMMISSION
     * is the transition that makes the race matter, because it is TERMINAL and deliberately NOT
     * in LinkState::RECOVERY_ELIGIBLE.
     *
     * HOW THE CONCURRENCY IS SIMULATED, STATED PLAINLY. The other operator's transition is
     * applied as a COMMITTED ROW WRITE, not by calling InstallationLifecycle again. Calling the
     * service recursively re-enters the very test double whose closure we are inside, so the
     * nested push hits the same hook and throws -- measured, and it made the first version of
     * this example pass for the wrong reason: the decommission never committed and the link
     * simply stayed SUSPENDED. A direct write is also the more faithful model, because the guard
     * under test re-reads the row under `lockForUpdate` and a real second process would reach it
     * exactly as a committed row.
     *
     * THE PROPERTY. A resume whose push is already in flight must not be able to flip a
     * decommissioned installation back to ACTIVE. The window is opened before the push, so
     * without the guard inside the row lock the resume would read a pre-push state, push, and
     * write ACTIVE over the decommission -- leaving this side active on an installation whose
     * credentials Lynomia has already dropped. The guard re-reads the state under
     * `lockForUpdate` and makes the loser a conflict instead of the final word.
     *
     * Three things are asserted, because the window adds a second way this could go wrong: the
     * state stays DECOMMISSIONED, no window survives the refused resume, and the identity route
     * is refused afterwards -- a decommissioned installation is not recovery-eligible, so the
     * window could not be reopened for it even if one were offered.
     */
    public function test_a_concurrent_decommission_cannot_be_resurrected_by_an_in_flight_resume(): void
    {
        [$link, $token, $owner] = $this->suspendedInstallation();
        $windowWasOpen = null;

        $this->lynomiaScript['fail'] = [
            'PUT /platform/api/v1/accounts/7001/installation_lifecycle'
                => function () use ($link, &$windowWasOpen) {
                    $windowWasOpen = $link->fresh()->recovery_window_expires_at !== null;

                    // the concurrent terminal transition, committed at the only moment it can
                    // interleave -- see HOW THE CONCURRENCY IS SIMULATED above
                    $link->fresh()->forceFill(['state' => LinkState::DECOMMISSIONED])->save();

                    return Http::response(['status' => 'active', 'applied' => true], 200);
                },
        ];

        $refused = false;

        try {
            app(InstallationLifecycle::class)->resume($link, $owner, 'recovery');
        } catch (\Throwable) {
            $refused = true;
        }

        $this->assertTrue($windowWasOpen, 'the premise: a window was open when the decommission landed');
        $this->assertTrue($refused, 'the resume must lose the race as a conflict, not silently win it');

        $fresh = $link->fresh();
        $this->assertSame(LinkState::DECOMMISSIONED, $fresh->state,
            'a decommissioned installation must not be resurrected by a resume already in flight');
        $this->assertNull($fresh->recovery_window_expires_at,
            'the refused resume must still shut its window -- that is what the `finally` is for');

        $this->identity($token)->assertStatus(403);
    }

    /**
     * THE SINGLE-USE PROPERTY IS A SINGLE ATOMIC STATEMENT, not a read-then-write. Two spends of
     * the same window must not both succeed, and `spendRecoveryWindow` is written as one
     * conditional UPDATE whose affected-row count is the answer precisely so that two callers
     * racing it cannot both see an unspent window.
     *
     * This asserts the property at the seam a race would exploit -- back-to-back calls with no
     * intervening read -- rather than spawning threads, which sqlite :memory: cannot do. The
     * HTTP-level single-use case is covered separately above.
     */
    public function test_two_spends_of_one_window_cannot_both_succeed(): void
    {
        [$link, , $owner] = $this->suspendedInstallation();
        $link->openRecoveryWindow('resume', (int) $owner->id);

        $first = LynomiaStoreLink::spendRecoveryWindow((int) $link->store_id);
        $second = LynomiaStoreLink::spendRecoveryWindow((int) $link->store_id);

        $this->assertTrue($first, 'the first spend takes the window');
        $this->assertFalse($second, 'the second must find nothing left to take');
    }

    /**
     * The credential still has to be the current one. A window does not rescue a revoked
     * credential, which is a different control and stays exactly as strict.
     */
    public function test_a_window_does_not_rescue_a_superseded_credential(): void
    {
        [$link, $token, $owner] = $this->suspendedInstallation();
        $link->openRecoveryWindow('resume', (int) $owner->id);
        // bump the version, which is what reauthenticate does -- the held token is now stale
        $link->fresh()->forceFill(['commerce_credential_version' => (int) $link->commerce_credential_version + 1])->save();

        $this->identity($token)->assertStatus(401);
    }
}
