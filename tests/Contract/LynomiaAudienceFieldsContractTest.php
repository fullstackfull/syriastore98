<?php

namespace Tests\Contract;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Lynomia\InteractsWithLynomia;
use Tests\TestCase;
use Workdo\LynomiaChat\app\Services\AccountProvisioner;

/**
 * THE CONSUMER HALF OF THE SECOND CROSS-REPOSITORY CONTRACT.
 *
 * WHY THERE IS A SECOND ONE. The first contract test closed the consent feed and the lesson
 * did not generalise on its own: this endpoint drifted in exactly the same way and nothing
 * caught it. Lynomia's `audience_fields` emitted three keys per store -- id, name, provider.
 * This repository's `ownStoreId()` matched on provider AND `external_store_id`, a fourth key
 * the producer never sent. The match was therefore always false, `commerce_store_id` never
 * reached Lynomia, and every "customers of this store" count silently became the
 * account-wide one. The whole P5B store scope was dead on the only path built to drive it.
 *
 * BOTH SIDES WERE GREEN. Lynomia's spec asserted the three keys it emitted. The fake in
 * tests/Feature/Lynomia/LynomiaAudienceTest.php injected `external_store_id` of its own
 * invention and asserted the behaviour that followed. A fake that invents the field it is
 * testing for proves only that the test author and the code author are the same person.
 *
 * SO THIS DRIVES THE REAL ARTIFACT. tests/Contract/lynomia/audience_fields.v1.json is the
 * real response of Lynomia's real endpoint, committed byte-identically in both repositories
 * and SHA-pinned in both (lynomia: spec/contracts/audience_fields_contract_spec.rb). It is
 * fed to the real controller, through the real resolution, and what is asserted is the
 * request that goes back out.
 *
 * WHAT THIS IS NOT. Contract verification, not deployed verification: it proves the two
 * codebases agree about the shape and that this side uses the field. Blocker A1 is about
 * DNS, TLS, real credentials and a real round trip, and stays open.
 */
class LynomiaAudienceFieldsContractTest extends TestCase
{
    use InteractsWithLynomia;
    use RefreshDatabase;

    /** Asserted identically in the producer repository. Changing the artifact means changing both. */
    private const CONTRACT_SHA256 = '46382538cef05d7125b2bf4cd58b734d3029791e2150f5b721cb537e4d92ed8f';

    private const ARTIFACT = __DIR__ . '/lynomia/audience_fields.v1.json';

    /** The Lynomia commerce-store row id the artifact's sample carries. */
    private const LYNOMIA_STORE_ID = 5101;

    private const AUDIENCES = '/lynomia/audiences/api';

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootLynomiaHarness();
    }

    public function test_the_artifact_is_the_one_the_producer_repository_holds(): void
    {
        $this->assertSame(
            self::CONTRACT_SHA256,
            hash_file('sha256', self::ARTIFACT),
            'the contract artifact changed; regenerate it in lynomiachat, copy it here, and update '
            . 'CONTRACT_SHA256 in both repositories'
        );
    }

    /**
     * THE ONE THAT WOULD HAVE CAUGHT IT. The producer's real row is handed to the real
     * resolution and the scope has to come out the other side.
     */
    public function test_the_real_producer_response_resolves_this_installations_store(): void
    {
        [$owner, $store] = $this->connectedTenant();
        $this->lynomiaScript['audience_fields'] = $this->sampleFor($store->id);
        $this->lynomiaScript['audience_preview'] = ['scoped' => 40, 'all' => 180];

        $body = $this->actingAs($owner)->postJson(self::AUDIENCES . '/preview', ['audience_id' => 12])
            ->assertOk()->json();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'audience_preview')
            && ($request['commerce_store_id'] ?? null) === self::LYNOMIA_STORE_ID);

        $this->assertSame(40, $body['count'], 'the scoped count is what a campaign from this store would reach');
        $this->assertSame(180, $body['count_all_stores']);
    }

    public function test_the_real_producer_response_names_this_installations_store_in_the_references(): void
    {
        [$owner, $store] = $this->connectedTenant();
        $this->lynomiaScript['audience_fields'] = $this->sampleFor($store->id);

        $body = $this->actingAs($owner)->getJson(self::AUDIENCES . '/references')->assertOk()->json();

        $this->assertSame(self::LYNOMIA_STORE_ID, $body['this_store']);
    }

    /**
     * THE FIELD WHOSE ABSENCE WAS THE DEFECT. Dropping it from the real payload has to stop
     * the scope being sent -- otherwise this test passes for a reason other than the one it
     * is named for, which is how the original went unnoticed.
     */
    public function test_without_the_external_store_id_the_scope_is_not_sent_at_all(): void
    {
        [$owner, $store] = $this->connectedTenant();
        $sample = $this->sampleFor($store->id);
        unset($sample['stores'][0]['external_store_id']);
        $this->lynomiaScript['audience_fields'] = $sample;
        $this->lynomiaScript['audience_preview'] = ['scoped' => 40, 'all' => 180];

        $this->actingAs($owner)->postJson(self::AUDIENCES . '/preview', ['audience_id' => 12])->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'audience_preview')
            && ! isset($request['commerce_store_id']));
    }

    /** A store of another provider is never this installation, whatever its external id says. */
    public function test_another_providers_store_is_not_mistaken_for_this_one(): void
    {
        [$owner, $store] = $this->connectedTenant();
        $sample = $this->sampleFor($store->id);
        $sample['stores'][0]['provider'] = 'shopify';
        $this->lynomiaScript['audience_fields'] = $sample;

        $body = $this->actingAs($owner)->getJson(self::AUDIENCES . '/references')->assertOk()->json();

        $this->assertNull($body['this_store']);
    }

    /**
     * The sample with its one deployment-specific value substituted: the producer normalises
     * the external id to "4242" because it is this installation's own id and differs between
     * any two of them. Everything else is the producer's own output, untouched.
     *
     * @return array<string, mixed>
     */
    private function sampleFor(int $storeId): array
    {
        $artifact = json_decode((string) file_get_contents(self::ARTIFACT), true);
        $sample = $artifact['sample'];
        $sample['stores'][0]['external_store_id'] = (string) $storeId;

        return $sample;
    }

    /** @return array{0: \App\Models\User, 1: \App\Models\Store} */
    private function connectedTenant(int $accountId = 7001): array
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia(['account_id' => $accountId]);
        app(AccountProvisioner::class)->provision($store, $owner);

        return [$owner->fresh(), $store];
    }
}
