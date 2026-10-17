<?php

namespace Tests\Feature\Lynomia;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Services\AccountProvisioner;
use Workdo\LynomiaChat\app\Services\CommerceCredential;
use Workdo\LynomiaChat\app\Support\CommerceState;
use Workdo\LynomiaChat\app\Support\LinkState;
use Workdo\LynomiaChat\app\Support\StoreIdentity;

/**
 * `lynomia:reconcile-commerce` -- and above all the two things it must NEVER do.
 *
 * WHAT IT IS FOR. An installation's existence is a fact on the other system, and four ordinary
 * things move it without telling us: an operator disables or disconnects the store in Lynomia, a
 * credential is rejected unattended, an HTTP response is lost after Lynomia has already
 * committed, or a merchant abandons the page mid-attempt. The merchant's page is DERIVED from
 * this row, so a stale belief is a wrong sentence with the wrong button under it.
 *
 * WHAT IT MUST NEVER DO, and what most of these examples pin: install, or mint. A sweep that
 * could create an installation is a sweep that can re-create one somebody deliberately
 * disconnected, at 3am, with nobody watching -- so "never silently attach a merchant's store to
 * a workspace" and "a background job that attaches stores" cannot both be true.
 */
class LynomiaCommerceReconciliationTest extends TestCase
{
    use InteractsWithLynomia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLynomiaHarness();
    }

    /**
     * An active, provisioned link whose store Lynomia will be scripted to know about, or not.
     *
     * WITH A CREDENTIAL, BECAUSE A STORE LYNOMIA HOLDS AN INSTALLATION FOR HAS ONE. The token is
     * the INPUT to connecting -- Lynomia cannot have attached this store without one having been
     * issued -- so a fixture with an upstream installation and version 0 is a state production
     * cannot reach, and CommerceState correctly reads it as disconnected (an installation that
     * cannot authenticate is not a connection). Minting here is what makes these examples about
     * the sweep rather than about that impossible combination.
     *
     * This is also the shape of the one store already connected by hand: a credential issued in
     * Super Admin, an installation Lynomia holds, and nothing on this side that has recorded it.
     */
    private function link(int $accountId = 7001): LynomiaStoreLink
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia(['account_id' => $accountId]);
        $link = app(AccountProvisioner::class)->provision($store, $owner->fresh())->fresh();
        CommerceCredential::mint($link);

        return $link->fresh();
    }

    /** Script Lynomia's health answer so it reports this store's installation in a given state. */
    private function healthHolding(LynomiaStoreLink $link, string $status, int $id = 41): void
    {
        $this->fakeLynomia([
            'account_id' => (int) $link->lynomia_account_id,
            'integration_health' => [
                'account' => ['id' => (int) $link->lynomia_account_id, 'name' => 'Merchant'],
                'status' => 'healthy',
                'installations' => [[
                    'id' => $id,
                    'external_store_id' => StoreIdentity::of((int) $link->store_id),
                    'provider' => 'storesyria',
                    'name' => 'Shop',
                    'status' => $status,
                ]],
                'signals' => ['open' => 0, 'by_source' => [], 'recent' => []],
            ],
        ]);
    }

    private function healthHoldingNothing(LynomiaStoreLink $link): void
    {
        $this->fakeLynomia([
            'account_id' => (int) $link->lynomia_account_id,
            'integration_health' => [
                'account' => ['id' => (int) $link->lynomia_account_id, 'name' => 'Merchant'],
                'status' => 'unknown',
                'installations' => [],
                'signals' => ['open' => 0, 'by_source' => [], 'recent' => []],
            ],
        ]);
    }

    // ============================================================ what it records

    /**
     * THE STORE THAT WAS CONNECTED BY HAND, BEFORE AUTOMATIC ACTIVATION EXISTED. Nothing on this
     * side ever recorded its installation, so the merchant's page would say "not connected" over
     * a working connection indefinitely. The sweep is what notices -- and it is the path the one
     * already-live production store takes.
     */
    public function test_it_records_an_installation_this_side_had_never_been_told_about(): void
    {
        $link = $this->link();
        $this->assertNull($link->lynomia_commerce_store_id);
        $this->healthHolding($link, 'active');

        $this->artisan('lynomia:reconcile-commerce')->assertExitCode(0);

        $fresh = $link->fresh();
        $this->assertSame(41, (int) $fresh->lynomia_commerce_store_id);
        $this->assertSame('active', $fresh->commerce_remote_status);
        $this->assertNotNull($fresh->commerce_installed_at);
        $this->assertNotNull($fresh->commerce_verified_at);
        $this->assertSame(CommerceState::CONNECTED, CommerceState::of($fresh));
    }

    /**
     * THE LOST RESPONSE. Lynomia committed and the answer never came back, so this side recorded
     * a failure over a connection that exists. Clearing the stale reason is most of the point of
     * the sweep: without it the merchant reads "not connected" forever and presses a button that
     * reports `applied: false` without changing what they are shown.
     */
    public function test_it_clears_a_stale_failure_over_an_installation_that_does_exist(): void
    {
        $link = $this->link();
        $link->forceFill([
            'commerce_last_error' => 'Store data could not be connected just now. You can try again.',
            'commerce_attempts' => 3,
        ])->save();
        $this->healthHolding($link, 'active');

        $this->artisan('lynomia:reconcile-commerce')->assertExitCode(0);

        $this->assertNull($link->fresh()->commerce_last_error);
        $this->assertSame(CommerceState::CONNECTED, CommerceState::of($link->fresh()));
    }

    /** An operator disabling the store in Lynomia reaches this side, and the page says so. */
    public function test_it_brings_back_the_far_sides_own_status(): void
    {
        $link = $this->link();
        $this->healthHolding($link, 'disabled');

        $this->artisan('lynomia:reconcile-commerce')->assertExitCode(0);

        $this->assertSame('disabled', $link->fresh()->commerce_remote_status);
        $this->assertSame(CommerceState::SUSPENDED, CommerceState::of($link->fresh()));
    }

    /**
     * AND THE BELIEF IS CLEARED RATHER THAN TURNED INTO AN ERROR when Lynomia holds nothing.
     * "There is no installation" is a state with a button under it; an error is a dead end.
     */
    public function test_it_forgets_an_installation_that_is_no_longer_there(): void
    {
        $link = $this->link();
        $link->forceFill([
            'lynomia_commerce_store_id' => 41,
            'commerce_remote_status' => 'active',
            'commerce_installed_at' => now()->subDay(),
        ])->save();
        $this->healthHoldingNothing($link);

        $this->artisan('lynomia:reconcile-commerce')->assertExitCode(0);

        $fresh = $link->fresh();
        $this->assertNull($fresh->lynomia_commerce_store_id);
        $this->assertNull($fresh->commerce_remote_status);
        $this->assertNull($fresh->commerce_last_error);
        $this->assertSame(CommerceState::NOT_CONNECTED, CommerceState::of($fresh));
        $this->assertTrue(CommerceState::actionable($fresh));
    }

    /**
     * MATCHED ON PROVIDER AS WELL AS ON THE ID. A merchant may legitimately have connected a
     * Salla or Shopify store to the same workspace, and matching on the external id alone would
     * read somebody else's installation as this one's.
     */
    public function test_it_does_not_mistake_another_providers_installation_for_this_one(): void
    {
        $link = $this->link();
        $this->fakeLynomia([
            'account_id' => (int) $link->lynomia_account_id,
            'integration_health' => [
                'account' => ['id' => (int) $link->lynomia_account_id, 'name' => 'Merchant'],
                'status' => 'healthy',
                'installations' => [[
                    'id' => 99,
                    'external_store_id' => StoreIdentity::of((int) $link->store_id),
                    'provider' => 'salla',
                    'name' => 'Other',
                    'status' => 'active',
                ]],
                'signals' => ['open' => 0, 'by_source' => [], 'recent' => []],
            ],
        ]);

        $this->artisan('lynomia:reconcile-commerce')->assertExitCode(0);

        $this->assertNull($link->fresh()->lynomia_commerce_store_id);
    }

    // ============================================================ what it never does

    /**
     * THE MOST IMPORTANT EXAMPLE IN THIS FILE. The sweep reads. It never calls the installation
     * endpoint, so there is no frequency at which it could attach a merchant's store to a
     * workspace unattended.
     */
    public function test_it_never_installs_anything(): void
    {
        $link = $this->link();
        $this->healthHoldingNothing($link);

        $this->artisan('lynomia:reconcile-commerce')->assertExitCode(0);

        $this->assertSame(0, $this->sentCount('PUT', '/commerce_installation'));
    }

    /** And it never mints: a credential is issued by a merchant's click or an operator's. */
    public function test_it_never_mints_a_credential(): void
    {
        $link = $this->link();
        $before = (int) $link->commerce_credential_version;
        $this->healthHolding($link, 'active');

        $this->artisan('lynomia:reconcile-commerce')->assertExitCode(0);

        // The version is the rotation epoch: a sweep that moved it would invalidate the token the
        // installation it just confirmed is authenticating with.
        $this->assertSame($before, (int) $link->fresh()->commerce_credential_version);
    }

    /**
     * A STORE SOMEBODY DELIBERATELY STOPPED IS NOT SWEPT BACK INTO SERVICE. Permission is this
     * side's to decide, so a link that cannot be opened is skipped entirely rather than having
     * its commerce belief refreshed from a workspace it may no longer use.
     */
    public function test_it_skips_a_link_that_is_not_allowed_to_be_opened(): void
    {
        foreach ([LinkState::SUSPENDED, LinkState::REVOKED, LinkState::DECOMMISSIONED, LinkState::PENDING_VERIFICATION] as $index => $state) {
            $link = $this->link(7200 + $index);
            $link->forceFill(['state' => $state])->save();
            $this->healthHolding($link->fresh(), 'active');

            $this->artisan('lynomia:reconcile-commerce')->assertExitCode(0);

            $this->assertNull($link->fresh()->lynomia_commerce_store_id, "state {$state} was reconciled");
            $this->assertSame(0, $this->sentCount('GET', '/integration_health'), "state {$state} was asked about");
        }
    }

    /**
     * AN UNREACHABLE LYNOMIA IS NOT DRIFT. Replacing the belief with "we could not ask" would
     * turn an outage into a wrong answer on every merchant's page at once.
     */
    public function test_it_leaves_the_belief_alone_when_lynomia_cannot_be_reached(): void
    {
        $link = $this->link();
        $link->forceFill(['lynomia_commerce_store_id' => 41, 'commerce_remote_status' => 'active'])->save();
        $this->fakeLynomia([
            'account_id' => (int) $link->lynomia_account_id,
            'fail' => ['GET /platform/api/v1/accounts/7001/integration_health' => Http::failedConnection()],
        ]);

        $this->artisan('lynomia:reconcile-commerce')->assertExitCode(0);

        $fresh = $link->fresh();
        $this->assertSame(41, (int) $fresh->lynomia_commerce_store_id);
        $this->assertSame('active', $fresh->commerce_remote_status);
    }

    public function test_dry_run_reports_the_drift_and_writes_nothing(): void
    {
        $link = $this->link();
        $this->healthHolding($link, 'active');

        $this->artisan('lynomia:reconcile-commerce', ['--dry-run' => true])->assertExitCode(0);

        $this->assertNull($link->fresh()->lynomia_commerce_store_id);
    }

    public function test_it_does_nothing_at_all_while_the_integration_is_disabled(): void
    {
        $link = $this->link();
        config()->set('lynomia.enabled', false);

        $this->artisan('lynomia:reconcile-commerce')->assertExitCode(0);

        $this->assertSame(0, $this->sentCount('GET', '/integration_health'));
        $this->assertNull($link->fresh()->lynomia_commerce_store_id);
    }
}
