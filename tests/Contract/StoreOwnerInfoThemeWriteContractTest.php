<?php

namespace Tests\Contract;

use App\Models\Store;
use App\Models\ThemeCustomize;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * THE SHIPPED FLUTTER APP'S CONTRACT WITH `POST api/admin/updateStoreOwnerInfo`, across the P3-M3-C
 * change that routed its theme-setting write through a service.
 * =================================================================================================
 *
 * WHAT CHANGED AND WHY IT IS A CONTRACT QUESTION AT ALL. The endpoint saves a merchant's social
 * links into `theme_customizes` as `footer_repeater`. It did so with a RAW
 * `\DB::table()->updateOrInsert()`, which does not invalidate the `Cachable` model -- so the save
 * never reached the storefront. Measured on MariaDB with `CACHE_STORE=database` (production's
 * setting): the app read the new links back, because its own read is also raw, while the storefront
 * footer kept rendering the old ones.
 *
 * M3-C routes that write through `ThemeConfigurationService`, which authorises, validates and
 * flushes the cache. **Only the internal write path moved.** This file is the assertion that
 * nothing a client can observe did.
 *
 * THE SPECIFIC HAZARD THIS PROJECT HAS RECORDED. `ApiResponser::error()` returns **HTTP 200** with
 * `{status: 0, ...}` by default; 401 must be passed deliberately -- and **the shipped app forces a
 * logout on any 401** (`/home/user/StoreSyria_app98` `lib/network/network.dart:324-336`, where
 * `case 401:` calls `onUnauthorized()`; `case 403:` at `:352-358` maps to `action_not_permitted` and
 * deliberately does NOT log the merchant out -- its own comment says clearing the session there
 * "would turn one denied action into a logout loop"). So the dangerous failure mode
 * of adding a service with its own refusals is not a wrong value: it is a NEW status code on a path
 * a shipped client already calls. A refused social-links write must leave the response shape and
 * status exactly as they were.
 */
class StoreOwnerInfoThemeWriteContractTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = User::forceCreate([
            'name' => 'Contract Merchant', 'email' => 'contract-theme@example.test',
            'password' => bcrypt('x'), 'type' => 'admin',
            'created_by' => 1, 'email_verified_at' => now(),
        ]);

        $this->store = Store::create([
            'name' => 'Contract Store', 'email' => 'contract-store@example.test',
            'slug' => 'contract-store', 'theme_id' => 'stylique',
            'created_by' => $this->merchant->id, 'is_active' => 1, 'default_language' => 'en',
        ]);

        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();
    }

    /**
     * A REAL BEARER TOKEN, not `Sanctum::actingAs()`.
     *
     * `app/Http/Middleware/CustomAuth.php` (`custom.auth`) resolves a Sanctum TOKEN and logs its
     * owner into whichever guard matches the tokenable class. `actingAs()` sets the guard without
     * minting a token, so the middleware finds no bearer and answers 401 -- which is how the first
     * run of this file reported five failures that said nothing about the endpoint. The sibling
     * `AddCustomerPermissionRefusalContractTest:95` uses the same `createToken(...)->plainTextToken`
     * shape.
     */
    private function callEndpoint(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this
            ->withHeader('Authorization', 'Bearer ' . $this->merchant->createToken('auth_token')->plainTextToken)
            ->postJson('/api/admin/updateStoreOwnerInfo', $payload);
    }

    /* ======================================================= the happy path, unchanged */

    #[Test]
    public function test_social_media_still_appears_in_updated_fields_on_success(): void
    {
        $response = $this->callEndpoint([
            'store_id' => $this->store->id,
            'social_media' => [
                ['platform' => 'twitter', 'link' => 'https://x.com/contractshop'],
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['status', 'message', 'data' => ['message', 'updated_fields']]);

        $this->assertContains('social_media', $response->json('data.updated_fields') ?? [],
            'The response no longer reports social_media as updated. `$updatedFields` is part of '
            . 'what the client reads back, so dropping an entry is a contract change even when the '
            . 'write itself succeeded.');
    }

    #[Test]
    public function test_the_links_land_in_the_projection_and_reach_the_storefront_read(): void
    {
        /*
         * PRIME THE CACHE FIRST, OR THIS TEST PROVES NOTHING.
         *
         * MEASURED, by reverting the controller to its original raw `DB::table()->updateOrInsert()`:
         * without this line the test PASSES against the defect. `ThemeCustomize` is `Cachable`, but
         * a cache that was never populated is a MISS, and a miss reads the database -- which holds
         * the new row whichever way it was written. The suite's `CACHE_STORE=array` starts empty
         * every test, so the storefront's stale read is simply not reachable without first putting
         * something in the cache to go stale.
         *
         * This is the same false-reading shape recorded against the cache test in the service suite:
         * a green assertion that measures the absence of a cache rather than the presence of a fix.
         * With the priming call below, the raw write FAILS this test and the service write passes.
         */
        getThemeSetting($this->store->id, 'stylique');

        $this->callEndpoint([
            'store_id' => $this->store->id,
            'social_media' => [
                ['platform' => 'instagram', 'link' => 'https://instagram.com/contractshop'],
            ],
        ])->assertStatus(200);

        $stored = ThemeCustomize::withoutGlobalScopes()
            ->where('store_id', $this->store->id)
            ->where('theme_id', 'stylique')
            ->where('name', 'footer_repeater')
            ->value('value');

        $this->assertNotNull($stored, 'The social links were not written at all.');

        /*
         * DECODED, NOT MATCHED AS A SUBSTRING. `json_encode()` escapes forward slashes by default,
         * so the column holds `instagram.com\/contractshop` and a literal `instagram.com/...`
         * substring assertion fails against a value that is perfectly correct -- which is exactly
         * how this assertion first reported a passing write as a failure.
         *
         * Decoding is also what the STOREFRONT does with this key: `footer_repeater` is a `slider`
         * (repeater) field, and the theme views `json_decode()` it. So asserting on the decoded
         * structure tests the contract the storefront actually has, and the escaping stays an
         * encoder detail on both sides -- the same `json_encode($socialData)` the raw path used
         * before M3-C, byte for byte.
         */
        $decoded = json_decode((string) $stored, true);

        $this->assertIsArray($decoded, 'The repeater is no longer valid JSON.');
        $this->assertSame('https://instagram.com/contractshop', $decoded[0]['link'] ?? null);
        $this->assertSame('instagram', $decoded[0]['platform'] ?? null,
            'The endpoint also derives `platform` and `icon`; routing the write through the service '
            . 'must not drop the sibling keys it builds.');
        $this->assertArrayHasKey('icon', $decoded[0]);

        /* THE WHOLE POINT OF THE CHANGE: the storefront's own read sees it. */
        $merged = getThemeSetting($this->store->id, 'stylique');
        $fromStorefront = json_decode((string) ($merged['footer_repeater'] ?? ''), true);

        $this->assertSame('https://instagram.com/contractshop', $fromStorefront[0]['link'] ?? null,
            'The storefront read did not see the save. This is the defect M3-C fixed: a raw write '
            . 'leaves the Cachable read serving the pre-write row, so the app shows success while '
            . 'the footer keeps the old links.');
    }

    /* ============================================ a refusal must NOT become a new status code */

    /**
     * A HOSTILE LINK IS REFUSED BY THE ENDPOINT'S OWN, PRE-EXISTING RULE -- measured, not assumed.
     *
     * `social_media.*.link => 'required_with:social_media|url'` already rejects a `javascript:`
     * scheme with **422**, before `ThemeConfigurationService` is reached at all. So M3-C adds
     * defence in depth here rather than a new refusal, and the 422 below is the behaviour the
     * shipped app has always seen on this input.
     *
     * The assertion that matters is therefore the NEGATIVE one: the service must not have turned
     * this into a 401. The shipped app FORCES A LOGOUT on any 401
     * (`/home/user/StoreSyria_app98` `lib/network/network.dart:324-336`), so a validation failure
     * answering 401 would sign the merchant out -- a logout loop from a bad social link.
     */
    #[Test]
    public function test_a_hostile_link_is_refused_without_a_new_status_code(): void
    {
        $response = $this->callEndpoint([
            'store_id' => $this->store->id,
            'social_media' => [
                ['platform' => 'twitter', 'link' => 'javascript:fetch("/steal")'],
            ],
        ]);

        $this->assertNotSame(401, $response->status(),
            'A refused theme write answered 401, which forces a logout in the shipped app.');
        $this->assertSame(422, $response->status(),
            "Expected the endpoint's own `url` rule to refuse this at 422, as it did before M3-C. A "
            . 'different code here means the service changed the contract.');

        $this->assertNull(
            ThemeCustomize::withoutGlobalScopes()
                ->where('store_id', $this->store->id)->where('name', 'footer_repeater')->value('value'),
            'The hostile link was written anyway.');
    }

    /**
     * AND A REFUSAL THE SERVICE ITSELF OWNS: a store whose theme the installation does not ship.
     * The endpoint's validation has nothing to say about it, so this is the path where a new status
     * code could actually appear.
     */
    #[Test]
    public function test_a_service_refusal_does_not_change_the_status_code(): void
    {
        $this->store->theme_id = 'a-theme-that-does-not-exist';
        $this->store->save();

        $response = $this->callEndpoint([
            'store_id' => $this->store->id,
            'social_media' => [
                ['platform' => 'twitter', 'link' => 'https://x.com/contractshop'],
            ],
        ]);

        $this->assertNotSame(401, $response->status(),
            'A service refusal answered 401 and would log the merchant out.');
        $this->assertNotSame(403, $response->status(),
            'A service refusal answered 403, which this path never returned before.');
        $this->assertSame(200, $response->status(),
            'A rejected field is reported by OMISSION from updated_fields, not by an HTTP error. '
            . 'The endpoint answered 200 on this input before M3-C and must continue to.');

        $this->assertNotContains('social_media', $response->json('data.updated_fields') ?? [],
            'A refused write still reported social_media as updated, which tells the app a value '
            . 'was stored when it was not.');
    }

    #[Test]
    public function test_an_empty_social_media_array_is_still_a_no_op(): void
    {
        $response = $this->callEndpoint([
            'store_id' => $this->store->id,
            'social_media' => [],
        ]);

        $response->assertStatus(200);
        $this->assertNotContains('social_media', $response->json('data.updated_fields') ?? [],
            'An empty array previously produced no social_media entry; that is unchanged.');
    }

    /* ======================================================== the rest of the payload still works */

    #[Test]
    public function test_a_request_with_no_social_media_at_all_is_unaffected(): void
    {
        $response = $this->callEndpoint(['store_id' => $this->store->id]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['status', 'message', 'data' => ['message', 'updated_fields']]);
        $this->assertNotContains('social_media', $response->json('data.updated_fields') ?? []);
    }
}
