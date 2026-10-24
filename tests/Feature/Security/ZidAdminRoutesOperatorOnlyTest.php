<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * P1-SEC-18 F7 -- `/zid/{storeId}/orders` and `/zid/{storeId}/webhooks` let any logged-in user read any connected store's Zid data.
 * =============================================================================================
 *
 * Both routes were `auth` only. `App\Services\Zid::store($storeId)` looks up `zid_stores` by the ZID store id the caller put in
 * the URL, decrypts that row's stored OAuth tokens and calls Zid's API with them: the response (orders, webhook subscriptions)
 * is returned to the caller. The credential itself is not returned, but the orders of whichever store is named are.
 *
 * WHY OPERATOR-ONLY AND NOT AN OWNERSHIP CHECK. `zid_stores.store_id` is ZID's own store id, written by ZidOAuthController from
 * Zid's account profile; the row links to a Delivery Syria merchant (`ds_merchant_id`), not to a StoreSyria tenant. There is no
 * column the host could compare to the caller's store or creatorId(), so there is no ownership predicate to write. The routes are
 * referenced by no view, no script and not by the admin app (whose base URL is /api/admin): they are operator tooling, and
 * the platform operator is who may use them. `PlatformOperatorOnly` answers 403 (never 401) and leaves the sign-in redirect to `auth`.
 *
 * `zid_stores` is declared by no migration (GATE F), so the test creates it -- the same way ChatgptKeyDisclosureTest creates
 * `currency_rates`. Without it every request would 500 and a "refused" assertion would pass for that reason, so the operator
 * control asserts the faked Zid body actually came back.
 */
class ZidAdminRoutesOperatorOnlyTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const ZID_STORE = 987654;
    private const MARKER = 'FIXTURE-ZID-ORDER-18f7';

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('zid_stores')) {
            Schema::create('zid_stores', function ($t) {
                $t->id();
                $t->string('store_id')->unique();
                $t->text('authorization')->nullable();
                $t->text('access_token')->nullable();
                $t->text('refresh_token')->nullable();
                $t->timestamp('expires_at')->nullable();
                $t->string('ds_merchant_id')->nullable();
                $t->string('ds_secret')->nullable();
                $t->timestamps();
            });
        }

        DB::table('zid_stores')->insert([
            'store_id' => (string) self::ZID_STORE,
            'authorization' => Crypt::encryptString('fixture-authorization'),
            'access_token' => Crypt::encryptString('fixture-manager-token'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Http::fake(['api.zid.sa/*' => Http::response(['orders' => [['id' => self::MARKER]]], 200)]);
    }

    public static function routes(): array
    {
        return ['orders' => ['orders'], 'webhooks' => ['webhooks']];
    }

    /** @dataProvider routes */
    public function test_premise_the_platform_operator_reaches_zid_through_the_real_route(string $leaf): void
    {
        $this->actingAs($this->makePlatformAdmin())
            ->get('/zid/' . self::ZID_STORE . '/' . $leaf)
            ->assertOk()
            ->assertJsonPath('orders.0.id', self::MARKER);
    }

    /** @dataProvider routes */
    public function test_a_merchant_cannot_read_a_connected_stores_zid_data(string $leaf): void
    {
        [$merchant] = $this->makeTenant();

        $response = $this->actingAs($merchant)->get('/zid/' . self::ZID_STORE . '/' . $leaf);

        $response->assertStatus(403);
        $this->assertStringNotContainsString(self::MARKER, $response->getContent());
    }

    /** @dataProvider routes */
    public function test_a_merchants_staff_member_cannot_either(string $leaf): void
    {
        [$merchant] = $this->makeTenant();

        $response = $this->actingAs($this->makeStaff($merchant))->get('/zid/' . self::ZID_STORE . '/' . $leaf);

        $response->assertStatus(403);
        $this->assertStringNotContainsString(self::MARKER, $response->getContent());
    }

    /** @dataProvider routes */
    public function test_an_anonymous_caller_is_still_sent_to_sign_in_not_given_a_403(string $leaf): void
    {
        $this->get('/zid/' . self::ZID_STORE . '/' . $leaf)->assertRedirect();
    }
}
