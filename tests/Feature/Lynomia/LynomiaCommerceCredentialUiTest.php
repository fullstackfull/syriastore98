<?php

namespace Tests\Feature\Lynomia;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use Workdo\LynomiaChat\app\Http\Controllers\SuperAdmin\IntegrationController;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Services\AccountProvisioner;
use Workdo\LynomiaChat\app\Services\CommerceCredential;
use Workdo\LynomiaChat\app\Support\LinkState;

/**
 * The Super Admin commerce-token UI, and the controls behind it.
 *
 * WHAT WAS MISSING, AND WHAT WAS NOT. The issue/revoke actions, their routes and
 * CommerceCredential::issue() all existed already; what the Merchant Accounts tab lacked was any
 * way to SEE a store's token state or reach those actions. These tests cover the surface that was
 * added -- the status the cell derives, which buttons it shows, the one-time reveal -- and re-pin
 * the security properties of the issuing path that the UI now exposes to a click.
 *
 * NOTHING HERE ISSUES A REAL TOKEN. Every credential is minted against an in-memory fixture store,
 * never store 26 / account 135, and the master secret is the harness dummy.
 */
class LynomiaCommerceCredentialUiTest extends TestCase
{
    use InteractsWithLynomia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLynomiaHarness();
    }

    private function controller(): IntegrationController
    {
        return app(IntegrationController::class);
    }

    /** An active, provisioned link with no commerce token issued yet. */
    private function activeLink(): LynomiaStoreLink
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia(['account_id' => 7001]);

        return app(AccountProvisioner::class)->provision($store, $owner->fresh())->fresh();
    }

    private function renderCell(LynomiaStoreLink $link): string
    {
        return view('lynomia-chat::superadmin.partials.commerce-credential', ['row' => $link->fresh()])->render();
    }

    // ---------------------------------------------------------------- status derivation

    public function test_status_is_not_issued_active_then_revoked_across_the_lifecycle(): void
    {
        $link = $this->activeLink();
        $this->assertSame(LynomiaStoreLink::COMMERCE_CREDENTIAL_NOT_ISSUED, $link->commerceCredentialStatus());

        $link->forceFill(['commerce_credential_version' => 1, 'commerce_credential_issued_at' => now()])->save();
        $this->assertSame(LynomiaStoreLink::COMMERCE_CREDENTIAL_ACTIVE, $link->fresh()->commerceCredentialStatus());

        $link->forceFill(['commerce_credential_version' => 2, 'commerce_credential_issued_at' => null])->save();
        $this->assertSame(LynomiaStoreLink::COMMERCE_CREDENTIAL_REVOKED, $link->fresh()->commerceCredentialStatus());
    }

    // ---------------------------------------------------------------- which buttons show

    public function test_the_issue_button_shows_when_no_token_has_been_issued(): void
    {
        $html = $this->renderCell($this->activeLink());

        $this->assertStringContainsString('lyn-credential-issue', $html);
        $this->assertStringContainsString(__('Issue Commerce Token'), $html);
        $this->assertStringContainsString('data-credential-status="not_issued"', $html);
        // No rotate/revoke before there is anything to rotate or revoke.
        $this->assertStringNotContainsString('lyn-credential-rotate', $html);
        $this->assertStringNotContainsString('lyn-credential-revoke', $html);
    }

    public function test_rotate_and_revoke_show_when_a_token_is_active(): void
    {
        $link = $this->activeLink();
        $link->forceFill(['commerce_credential_version' => 1, 'commerce_credential_issued_at' => now()])->save();

        $html = $this->renderCell($link);

        $this->assertStringContainsString('data-credential-status="active"', $html);
        $this->assertStringContainsString('lyn-credential-rotate', $html);
        $this->assertStringContainsString('lyn-credential-revoke', $html);
        $this->assertStringContainsString(__('Rotate Token'), $html);
        $this->assertStringContainsString(__('Revoke Token'), $html);
        // Rotation and revocation both confirm first.
        $this->assertStringContainsString('onsubmit="return confirm(', $html);
        // The DELETE route is reached through a spoofed method, not a bare POST.
        $this->assertStringContainsString('name="_method" value="DELETE"', $html);
        $this->assertStringNotContainsString('lyn-credential-issue', $html);
    }

    public function test_the_issue_button_returns_after_a_revocation(): void
    {
        $link = $this->activeLink();
        $link->forceFill(['commerce_credential_version' => 2, 'commerce_credential_issued_at' => null])->save();

        $html = $this->renderCell($link);

        $this->assertStringContainsString('data-credential-status="revoked"', $html);
        $this->assertStringContainsString('lyn-credential-issue', $html);
        $this->assertStringNotContainsString('lyn-credential-rotate', $html);
    }

    public function test_no_issue_control_shows_when_the_link_is_not_active(): void
    {
        $link = $this->activeLink();
        $link->forceFill(['state' => LinkState::PENDING_VERIFICATION])->save();

        $html = $this->renderCell($link);

        $this->assertStringContainsString('lyn-credential-blocked', $html);
        $this->assertStringNotContainsString('lyn-credential-issue', $html);
        $this->assertStringNotContainsString('lyn-credential-rotate', $html);
    }

    // ---------------------------------------------------------------- the one-time reveal

    public function test_the_reveal_shows_the_token_once_when_flashed_and_not_otherwise(): void
    {
        $token = 'lsc_26_1_' . str_repeat('a', 64);

        session()->flash('lynomia_commerce_credential', $token);
        $shown = view('lynomia-chat::superadmin.partials.commerce-credential-reveal')->render();

        $this->assertStringContainsString($token, $shown);
        $this->assertStringContainsString('lyn-credential-copy', $shown);
        $this->assertStringContainsString(__('Copy Token'), $shown);
        // The "shown once, cannot be retrieved again" warning is present (asserted on an ASCII
        // fragment so an em-dash's byte form cannot make the test brittle).
        $this->assertStringContainsString('shown once and cannot be retrieved again', $shown);
        // Never parked where it could persist or be read back out of band.
        $this->assertStringNotContainsString('localStorage', $shown);
        $this->assertStringNotContainsString('console.log', $shown);

        // A later page load is a new request, where the flashed value has expired. Simulate that
        // by clearing it, and the banner -- and the token with it -- is gone.
        session()->forget('lynomia_commerce_credential');
        $absent = view('lynomia-chat::superadmin.partials.commerce-credential-reveal')->render();
        $this->assertStringNotContainsString($token, $absent);
        $this->assertStringNotContainsString('lyn-credential-reveal', $absent);
    }

    // ---------------------------------------------------------------- issuing

    public function test_an_operator_issues_a_token_that_resolves_to_the_store(): void
    {
        $link = $this->activeLink();
        $this->actingAs($this->operator());

        $redirect = $this->controller()->issueCommerceCredential($link->id);

        $fresh = $link->fresh();
        $this->assertSame(1, (int) $fresh->commerce_credential_version);
        $this->assertNotNull($fresh->commerce_credential_issued_at);
        $this->assertTrue($fresh->hasActiveCommerceCredential());

        // The token is flashed for the one-time reveal, and it is a WORKING token: it resolves
        // to this store and no other.
        $token = $redirect->getSession()->get('lynomia_commerce_credential');
        $this->assertIsString($token);
        $this->assertSame((int) $link->store_id, CommerceCredential::resolveStoreId($token));
    }

    public function test_rotation_invalidates_the_previous_token(): void
    {
        $link = $this->activeLink();
        $this->actingAs($this->operator());

        $first = $this->controller()->issueCommerceCredential($link->id)
            ->getSession()->get('lynomia_commerce_credential');

        $second = $this->controller()->issueCommerceCredential($link->id)
            ->getSession()->get('lynomia_commerce_credential');

        $this->assertNotSame($first, $second);
        $this->assertSame(2, (int) $link->fresh()->commerce_credential_version);
        // The old token no longer resolves; only the current version does.
        $this->assertNull(CommerceCredential::resolveStoreId($first));
        $this->assertSame((int) $link->store_id, CommerceCredential::resolveStoreId($second));
    }

    public function test_revoking_stops_the_current_token_and_leaves_a_revoked_status(): void
    {
        $link = $this->activeLink();
        $this->actingAs($this->operator());

        $token = $this->controller()->issueCommerceCredential($link->id)
            ->getSession()->get('lynomia_commerce_credential');

        $this->controller()->revokeCommerceCredential($link->id);

        $fresh = $link->fresh();
        $this->assertSame(LynomiaStoreLink::COMMERCE_CREDENTIAL_REVOKED, $fresh->commerceCredentialStatus());
        $this->assertNull($fresh->commerce_credential_issued_at);
        $this->assertNull(CommerceCredential::resolveStoreId($token), 'the revoked token still resolved');
    }

    public function test_issuing_is_refused_when_the_link_is_not_active(): void
    {
        $link = $this->activeLink();
        $link->forceFill(['state' => LinkState::SUSPENDED])->save();
        $this->actingAs($this->operator());

        $redirect = $this->controller()->issueCommerceCredential($link->id);

        $this->assertSame(0, (int) $link->fresh()->commerce_credential_version);
        $this->assertNull($redirect->getSession()->get('lynomia_commerce_credential'));
        $this->assertNotEmpty($redirect->getSession()->get('error'));
    }

    // ---------------------------------------------------------------- the token never leaks

    public function test_the_token_is_never_written_to_the_database_or_the_audit_trail(): void
    {
        $link = $this->activeLink();
        $this->actingAs($this->operator());

        $token = $this->controller()->issueCommerceCredential($link->id)
            ->getSession()->get('lynomia_commerce_credential');

        // Not on the link row.
        foreach ((array) $link->fresh()->getAttributes() as $value) {
            if (is_string($value)) {
                $this->assertStringNotContainsString($token, $value);
            }
        }

        // Not in any audit row -- the issue is recorded as a fact and a version, never the key.
        foreach (AuditLog::all() as $log) {
            $this->assertStringNotContainsString($token, json_encode($log->getAttributes()));
        }

        // Not anywhere else in the schema either: no column of any table holds it.
        $hmac = explode('_', $token)[3] ?? $token;
        foreach (DB::getSchemaBuilder()->getTableListing() as $table) {
            $hit = collect(DB::table($table)->get())->first(function ($row) use ($hmac) {
                return str_contains(json_encode($row), $hmac);
            });
            $this->assertNull($hit, "the commerce token leaked into `{$table}`");
        }
    }

    public function test_issuing_logs_nothing_containing_the_token(): void
    {
        $link = $this->activeLink();
        $this->actingAs($this->operator());

        $captured = [];
        Log::listen(function ($message) use (&$captured) {
            $captured[] = ($message->message ?? '') . ' ' . json_encode($message->context ?? []);
        });

        $token = $this->controller()->issueCommerceCredential($link->id)
            ->getSession()->get('lynomia_commerce_credential');

        $this->assertIsString($token);
        $this->assertNotEmpty($token);
        foreach ($captured as $line) {
            $this->assertStringNotContainsString($token, $line);
        }
    }

    // ---------------------------------------------------------------- the routes are operator-only

    public function test_the_commerce_routes_are_behind_the_operator_door(): void
    {
        foreach (['lynomia.admin.commerce.issue', 'lynomia.admin.commerce.revoke'] as $name) {
            $this->assertTrue(Route::has($name), "Route {$name} is missing.");

            $stack = app('router')->gatherRouteMiddleware(Route::getRoutes()->getByName($name));

            $this->assertContains(\App\Http\Middleware\PlatformOperatorOnly::class, $stack, $name);
            $this->assertContains(\Illuminate\Auth\Middleware\Authenticate::class, $stack, $name);
        }
    }

    public function test_a_merchant_cannot_reach_the_commerce_issue_route(): void
    {
        $link = $this->activeLink();
        [$merchant] = $this->entitledTenant();

        $response = $this->actingAs($merchant->fresh())
            ->post("/super-admin/lynomia-integration/links/{$link->id}/commerce-credential");

        // PlatformOperatorOnly turns a merchant away (redirect or forbidden), never a 200, and
        // the token stays unissued.
        $this->assertContains($response->getStatusCode(), [301, 302, 401, 403]);
        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertSame(0, (int) $link->fresh()->commerce_credential_version);
    }
}
