<?php

namespace Tests\Feature\Lynomia;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Workdo\LynomiaChat\app\Services\AccountProvisioner;
use Workdo\LynomiaChat\app\Services\PlatformApiClient;

/**
 * P6A. THE FAR SIDE'S VIEW OF AN INSTALLATION, reachable from this side's console.
 *
 * Every incident in the Phase 5 register had the same shape: a token rejected unattended, a
 * store dropping out of the counted set, an outbound consent decision retired, a campaign
 * blocked for want of a capability. In each one the fact existed on one side while the
 * person who had to act was on the other.
 *
 * The design constraint that these tests exist to protect: the rest of the Super Admin page
 * is built from LOCAL tables so that it renders when Lynomia is unreachable -- which is
 * exactly when an operator opens it. This panel is the one that reaches across, so it must be
 * a separate request whose failure is one cell's message, never the page.
 */
class LynomiaIntegrationHealthTest extends TestCase
{
    use InteractsWithLynomia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLynomiaHarness();
    }

    /**
     * THE CONTROLLER, NOT THE ROUTE. Workdo's installer check redirects an HTTP request to
     * this page to /update in the test environment, which is why every other Super Admin test
     * in this module drives the controller directly. The route's guards are asserted
     * separately in LynomiaConnectionTest, from the booted router.
     */
    private function controller(): \Workdo\LynomiaChat\app\Http\Controllers\SuperAdmin\IntegrationController
    {
        return app(\Workdo\LynomiaChat\app\Http\Controllers\SuperAdmin\IntegrationController::class);
    }

    private function connectedTenant(): array
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia(['account_id' => 7001]);
        $link = app(AccountProvisioner::class)->provision($store, $owner);

        return [$owner->fresh(), $store, $link->fresh()];
    }

    public function test_an_operator_reads_what_lynomia_sees_of_one_installation(): void
    {
        [, , $link] = $this->connectedTenant();

        $this->actingAs($this->operator());

        $body = json_decode($this->controller()->remoteHealth($link->id)->getContent(), true);

        $this->assertSame('healthy', $body['status']);
        $this->assertSame('localhost:1', $body['installations'][0]['external_store_id']);
        $this->assertSame(4, $body['consent']['decisions_held']);
    }

    /** The account asked about is the one on the link, never one the client names. */
    public function test_the_workspace_asked_about_is_the_one_recorded_on_the_link(): void
    {
        [, , $link] = $this->connectedTenant();

        $this->actingAs($this->operator());

        $this->controller()->remoteHealth($link->id);

        Http::assertSent(fn ($request) => str_contains(
            $request->url(),
            "/platform/api/v1/accounts/{$link->lynomia_account_id}/integration_health"
        ));
    }

    /**
     * THE FAILURE IS A MESSAGE, NOT A BLANK PAGE. An operator opens this page because
     * something is wrong; a panel that takes the page down when the far side is down is worse
     * than no panel.
     */
    public function test_an_unreachable_lynomia_answers_with_a_message_rather_than_failing(): void
    {
        [, , $link] = $this->connectedTenant();
        $this->lynomiaScript['fail'] = [
            "GET /platform/api/v1/accounts/{$link->lynomia_account_id}/integration_health"
                => Http::response(['error' => 'upstream is down'], 500),
        ];

        $this->actingAs($this->operator());

        $response = $this->controller()->remoteHealth($link->id);
        $body = json_decode($response->getContent(), true);

        $this->assertSame(502, $response->getStatusCode());
        $this->assertNotEmpty($body['error']);
        // The typed sentence, never the far side's prose.
        $this->assertStringNotContainsString('upstream is down', (string) $body['error']);
    }

    public function test_a_link_with_no_workspace_yet_says_so_and_calls_nobody(): void
    {
        [, , $link] = $this->connectedTenant();
        $link->forceFill(['lynomia_account_id' => null])->save();
        Http::fake();

        $this->actingAs($this->operator());

        $this->assertSame(404, $this->controller()->remoteHealth($link->id)->getStatusCode());

        Http::assertNothingSent();
    }

    /** The client shapes a missing or moved key into a gap, not a fatal. */
    public function test_a_payload_missing_its_keys_is_read_as_unknown_rather_than_crashing(): void
    {
        [, , $link] = $this->connectedTenant();
        $this->lynomiaScript['integration_health'] = ['account' => ['id' => 7001]];

        $this->actingAs($this->operator());

        $body = json_decode($this->controller()->remoteHealth($link->id)->getContent(), true);

        $this->assertSame('unknown', $body['status']);
        $this->assertSame([], $body['installations']);
    }

    /**
     * THE PAGE IS BUILT WITHOUT REACHING ACROSS, which is the property the whole design rests
     * on: an operator opens it because something is wrong, so a page that needs the far side
     * to answer goes blank exactly when it is needed. Asserted on the view's DATA rather than
     * its HTML -- the full Workdo layout cannot render headless, and what matters here is
     * which queries ran, not which markup came out.
     */
    public function test_the_page_is_assembled_without_calling_lynomia(): void
    {
        $this->connectedTenant();
        config()->set('lynomia.base_url', 'http://127.0.0.1:1');
        Http::fake();

        $this->actingAs($this->operator());
        $data = $this->controller()->show()->getData();

        Http::assertNothingSent();
        // The local health panels are all present, computed from this side's own tables.
        $this->assertArrayHasKey('commerceHealth', $data);
        $this->assertArrayHasKey('consentHealth', $data);
        $this->assertArrayHasKey('eventHealth', $data);
        $this->assertArrayHasKey('links', $data);
    }

    /** B3.2's operator-visible half: a decision given up on is counted where an operator looks. */
    public function test_a_consent_decision_given_up_on_is_counted_for_the_operator(): void
    {
        [, , $link] = $this->connectedTenant();
        \Workdo\LynomiaChat\app\Models\LynomiaConsentDecision::create([
            'store_id' => $link->store_id, 'external_subject_id' => 'cust-1', 'purpose' => 'promotional',
            'channel' => 'whatsapp', 'state' => 'granted', 'source' => 'checkout_optin',
            'captured_at' => now()->subDays(40), 'decision_ref' => 'evt-expired', 'attempts' => 300,
            'delivered_at' => now(), 'last_error' => 'expired_after_30d_kind_subject_not_linked',
        ]);

        $this->actingAs($this->operator());
        $data = $this->controller()->show()->getData();

        $this->assertSame(1, $data['consentHealth']['expired']);
        $this->assertSame(0, $data['consentHealth']['pending']);
    }

    public function test_the_health_client_is_shaped_defensively(): void
    {
        $this->connectedTenant();
        $this->lynomiaScript['integration_health'] = ['status' => 42, 'installations' => 'not an array'];

        $health = app(PlatformApiClient::class)->integrationHealth(7001);

        $this->assertSame('unknown', $health['status']);
        $this->assertSame([], $health['installations']);
    }
}
