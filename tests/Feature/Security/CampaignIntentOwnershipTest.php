<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\Security\EnablesWorkdoModules;

/**
 * P1-SEC-69 (c) — THE CAMPAIGN WRITER TOOK ITS STORE AND PRODUCT FROM A JSON BLOB IN THE URL.
 * =============================================================================================
 *
 * `CampaignsController::paymentSuccess` is the gateway-return handler, and it is where a campaign
 * is actually written (`store()` only starts a payment). It reads EVERY field of the campaign from
 * a blob the caller supplies:
 *
 *     $requestData = json_decode(urldecode($request->input('request_data')), true);
 *     if (empty($requestData)) { $requestData = Session::get('campaigns_request_data'); }
 *     ...
 *     $campaignsData->store   = $requestData['store'];
 *     $campaignsData->product = $requestData['product'];
 *     $campaignsData->created_by = \Auth::user()->creatorId();
 *
 * There is no ownership test on `store` or `product`, so an entitled principal could name ANOTHER
 * TENANT'S store and product and have a campaign created that advertises them — and
 * `settingmenuController::campaginSorting` renders approved campaigns on the platform's public
 * landing page. `Route::any` means the whole intent fits in a query string.
 *
 * WHAT THIS DOES NOT DECIDE, AND MUST NOT. `total_cost` and `total_days` come from the same blob,
 * and payment is verified for Khalti alone. Those are the platform's PRICE and its settlement rule
 * — a business decision and a per-gateway change in vendored code — so they are reported to the
 * owner and are deliberately outside this guard. This is a tenancy fix: it decides whose store and
 * whose product a campaign may name, nothing else.
 */
final class CampaignIntentOwnershipTest extends SecurityTestCase
{
    use EnablesWorkdoModules;
    use RefreshDatabase;

    private const URI = '/campaigns/campaigns-card-payment';

    private function enable(): void
    {
        $failed = $this->enableWorkdoModules(['Campaigns']);

        $this->assertNotEmpty(
            array_filter(Route::getRoutes()->getRoutes(), fn ($r) => str_starts_with(ltrim($r->getActionName(), '\\'), 'Workdo\\Campaigns\\')),
            'PREMISE: Campaigns is not loaded, so this test would reach nothing. Failed migrations: ' . implode('; ', $failed)
        );

        /*
         * THE GUARD NAMES THIS URI, SO THE URI MUST STILL BE THERE. The control is keyed on the
         * vendored route's own uri; if a module update renames it, the guard silently stops acting
         * and every refusal below would pass for the wrong reason.
         */
        $this->assertContains(
            'campaigns/campaigns-card-payment',
            array_map(fn ($r) => $r->uri(), Route::getRoutes()->getRoutes()),
            'PREMISE: the route the guard is keyed on no longer exists under that uri.'
        );
    }

    private function entitle(User $user): void
    {
        $planId = DB::table('plans')->insertGetId([
            'name' => 'campaigns-plan-' . $this->uniqueFixtureRef(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'modules' => 'Campaigns', 'shipping_method' => 'off',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $user->plan_id = $planId;
        $user->save();

        // The plan gate reads the EMPLOYER's activations for any type that is not literally `admin`.
        foreach (array_unique(array_filter([(int) $user->id, (int) $user->created_by])) as $id) {
            if (! DB::table('user_active_modules')->where('user_id', $id)->where('module', 'Campaigns')->exists()) {
                DB::table('user_active_modules')->insert([
                    'user_id' => $id, 'module' => 'Campaigns',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            app()->forgetInstance("lynomia.activated_modules.owner.{$id}");
        }
    }

    private function productFor(User $owner, $store, string $name): int
    {
        return (int) \App\Models\Product::forceCreate([
            'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name) . '-' . $this->uniqueFixtureRef(),
            'price' => 10, 'sale_price' => 10, 'product_stock' => 1, 'track_stock' => 1, 'variant_product' => 0,
            'store_id' => $store->id, 'created_by' => $owner->id,
        ])->id;
    }

    /**
     * The intent blob exactly as the module's own JS builds it, so the handler reaches its write.
     *
     * EVERY KEY THE HANDLER DEREFERENCES IS HERE, AND THE FIRST VERSION WAS MISSING TWO. It left
     * out `start_date` and `end_date`, which `paymentSuccess` assigns two lines before `save()`;
     * the undefined-key warning becomes an ErrorException, so the request answered 500 and NOTHING
     * was written -- for the attack cases as well as the legitimate one. Five refusals passed
     * against completely unguarded code. The positive controls are what caught it, which is the
     * whole reason they are written first.
     */
    private function intent(int $storeId, string $products, string $name): string
    {
        return urlencode(json_encode([
            'name' => $name,
            'store' => $storeId,
            'product' => $products,
            'total_days' => 7,
            'total_cost' => 1,
            'start_date' => '2026-01-01',
            'end_date' => '2099-01-01',
            'payment_type' => 'bank_transfer',
            'coupon_id' => '',
        ]));
    }

    private function campaignsNaming(int $storeId): int
    {
        return (int) DB::table('campaigns')->where('store', $storeId)->count();
    }

    public function test_a_merchant_cannot_create_a_campaign_for_another_tenants_store_and_product(): void
    {
        $this->enable();

        [$merchant, $store] = $this->makeTenant();
        [$rival, $rivalStore] = $this->makeTenant();

        $rivalProduct = $this->productFor($rival, $rivalStore, 'RIVAL SECRET PRODUCT');

        $this->grantPermission($merchant, 'Manage Campaigns');
        $this->entitle($merchant);

        $this->assertSame(0, $this->campaignsNaming((int) $rivalStore->id), 'PREMISE: the rival store already has a campaign.');

        $this->actingAs($merchant->fresh())->call('POST', self::URI, [
            'request_data' => $this->intent((int) $rivalStore->id, (string) $rivalProduct, 'HIJACKED PLACEMENT'),
        ]);

        $this->assertSame(0, $this->campaignsNaming((int) $rivalStore->id),
            'A merchant created a campaign naming ANOTHER TENANT\'s store. `paymentSuccess` takes '
            . '`store` and `product` from a caller-supplied JSON blob with no ownership test, and '
            . 'approved campaigns are rendered on the platform\'s public landing page.');

        $this->assertNull(DB::table('campaigns')->where('name', 'HIJACKED PLACEMENT')->first(),
            'The campaign row exists under some other store id, so the write was not refused.');
    }

    public function test_a_merchant_cannot_name_another_tenants_product_inside_their_own_store(): void
    {
        $this->enable();

        [$merchant, $store] = $this->makeTenant();
        [$rival, $rivalStore] = $this->makeTenant();

        $rivalProduct = $this->productFor($rival, $rivalStore, 'RIVAL SECRET PRODUCT');

        $this->grantPermission($merchant, 'Manage Campaigns');
        $this->entitle($merchant);

        $this->actingAs($merchant->fresh())->call('POST', self::URI, [
            'request_data' => $this->intent((int) $store->id, (string) $rivalProduct, 'FOREIGN PRODUCT PLACEMENT'),
        ]);

        $this->assertNull(DB::table('campaigns')->where('name', 'FOREIGN PRODUCT PLACEMENT')->first(),
            'A merchant advertised ANOTHER TENANT\'s product under their own store. The store is '
            . 'theirs, so a check on the store alone would pass this.');
    }

    /** THE PRODUCT COLUMN IS A LIST — `viewCampaigns` explodes it — so every id in it must be checked. */
    public function test_a_merchant_cannot_smuggle_a_foreign_product_into_a_list_of_their_own(): void
    {
        $this->enable();

        [$merchant, $store] = $this->makeTenant();
        [$rival, $rivalStore] = $this->makeTenant();

        $mine = $this->productFor($merchant, $store, 'MY OWN PRODUCT');
        $rivalProduct = $this->productFor($rival, $rivalStore, 'RIVAL SECRET PRODUCT');

        $this->grantPermission($merchant, 'Manage Campaigns');
        $this->entitle($merchant);

        $this->actingAs($merchant->fresh())->call('POST', self::URI, [
            'request_data' => $this->intent((int) $store->id, $mine . ',' . $rivalProduct, 'SMUGGLED PLACEMENT'),
        ]);

        $this->assertNull(DB::table('campaigns')->where('name', 'SMUGGLED PLACEMENT')->first(),
            'A campaign was created whose product list carries another tenant\'s product id '
            . 'alongside the caller\'s own. `campaigns.product` is a comma-separated list, so a '
            . 'check on the first id only would pass this.');
    }

    public function test_a_principal_rooted_at_the_operator_cannot_create_a_campaign_at_all(): void
    {
        $this->enable();

        $operator = $this->makePlatformAdmin();
        $operatorStore = $this->makeStore($operator, ['name' => 'OPERATOR PLATFORM STORE']);
        $operatorProduct = $this->productFor($operator, $operatorStore, 'OPERATOR SECRET PRODUCT');

        [$merchant] = $this->makeTenant();
        $merchant->type = 'manager';
        $merchant->save();

        $principal = $merchant->fresh();
        $this->grantPermission($principal, 'Manage Campaigns');
        $this->entitle($principal);

        $this->assertSame((int) $operator->id, (int) $principal->fresh()->creatorId(),
            'PREMISE: this principal is not rooted at the operator, so the case measures nothing.');

        $this->actingAs(User::find($principal->id))->call('POST', self::URI, [
            'request_data' => $this->intent((int) $operatorStore->id, (string) $operatorProduct, 'OPERATOR PLACEMENT'),
        ]);

        $this->assertNull(DB::table('campaigns')->where('name', 'OPERATOR PLACEMENT')->first(),
            'A principal whose tenant root is the operator created a campaign for the OPERATOR\'s '
            . 'store and product — and `created_by = creatorId()` would have filed it as the '
            . 'operator\'s own.');
    }

    public function test_a_request_with_no_session_creates_no_campaign(): void
    {
        $this->enable();

        [$merchant, $store] = $this->makeTenant();
        $product = $this->productFor($merchant, $store, 'MY OWN PRODUCT');

        $response = $this->call('POST', self::URI, [
            'request_data' => $this->intent((int) $store->id, (string) $product, 'ANONYMOUS PLACEMENT'),
        ]);

        $this->assertNull(DB::table('campaigns')->where('name', 'ANONYMOUS PLACEMENT')->first(),
            'A request with no session created a campaign.');

        /*
         * AND IT IS REFUSED RATHER THAN CRASHING -- WRITTEN BECAUSE A MUTATION SURVIVED. Admitting
         * the anonymous request leaves the table assertion above passing: the handler dereferences
         * `Auth::user()->id` two lines before `save()`, so it answers 500 and writes nothing. The
         * outcome is the same and the mechanism is not, and the mechanism is the part this guard
         * owns -- if that dereference ever moves below the write, only the refusal holds.
         */
        $this->assertContains($response->getStatusCode(), [302, 403],
            'The campaign writer ADMITTED a request with no session (it answered '
            . $response->getStatusCode() . '). Today the handler crashes on a null user before it '
            . 'writes; that is not a control.');
    }

    /**
     * AN ID THAT MATCHES NO ROW IS REFUSED TOO, which is the second limb of the predicate.
     *
     * ALSO WRITTEN BECAUSE A MUTATION SURVIVED: dropping the "every id asked for must be accounted
     * for" limb left every other case passing, because the first limb only looks for rows that ARE
     * outside the tenant and a non-existent id matches none. The campaign would then be written
     * with a product id that resolves to nothing -- and the module's own list cell does
     * `$campaigns->products->name` with no null guard, so one such row answers 500 for the WHOLE
     * campaigns list, the operator's included.
     */
    public function test_a_merchant_cannot_name_a_product_that_does_not_exist(): void
    {
        $this->enable();

        [$merchant, $store] = $this->makeTenant();
        $mine = $this->productFor($merchant, $store, 'MY OWN PRODUCT');

        $this->grantPermission($merchant, 'Manage Campaigns');
        $this->entitle($merchant);

        $absent = ((int) DB::table('products')->max('id')) + 9999;

        $this->assertNull(DB::table('products')->where('id', $absent)->first(),
            'PREMISE: the id this case calls absent exists, so it measures nothing.');

        $this->actingAs($merchant->fresh())->call('POST', self::URI, [
            'request_data' => $this->intent((int) $store->id, $mine . ',' . $absent, 'PHANTOM PLACEMENT'),
        ]);

        $this->assertNull(DB::table('campaigns')->where('name', 'PHANTOM PLACEMENT')->first(),
            'A campaign was created naming a product id that matches no row.');
    }

    // ---------------------------------------------------------------------------------------
    //  THE POSITIVE CONTROL: the legitimate flow still writes
    // ---------------------------------------------------------------------------------------

    public function test_a_merchant_can_still_create_a_campaign_for_their_own_store_and_product(): void
    {
        $this->enable();

        [$merchant, $store] = $this->makeTenant();
        $mine = $this->productFor($merchant, $store, 'MY OWN PRODUCT');
        $second = $this->productFor($merchant, $store, 'MY SECOND PRODUCT');

        $this->grantPermission($merchant, 'Manage Campaigns');
        $this->entitle($merchant);

        $this->actingAs($merchant->fresh())->call('POST', self::URI, [
            'request_data' => $this->intent((int) $store->id, $mine . ',' . $second, 'MY OWN PLACEMENT'),
        ]);

        $row = DB::table('campaigns')->where('name', 'MY OWN PLACEMENT')->first();

        $this->assertNotNull($row,
            'POSITIVE CONTROL: a merchant can no longer create a campaign for their own store and '
            . 'their own products, so every refusal above would pass on a guard that refused '
            . 'everybody.');
        $this->assertSame((int) $store->id, (int) $row->store);
        $this->assertSame((int) $merchant->id, (int) $row->created_by,
            'The campaign was filed against a different tenant than the caller\'s.');
    }

    /** And the operator, whose own campaigns are the platform's: unchanged. */
    public function test_the_operator_can_still_create_a_campaign(): void
    {
        $this->enable();

        $operator = $this->makePlatformAdmin();
        $operatorStore = $this->makeStore($operator, ['name' => 'OPERATOR PLATFORM STORE']);
        $operatorProduct = $this->productFor($operator, $operatorStore, 'OPERATOR SECRET PRODUCT');

        $this->actingAs(User::find($operator->id))->call('POST', self::URI, [
            'request_data' => $this->intent((int) $operatorStore->id, (string) $operatorProduct, 'PLATFORM PLACEMENT'),
        ]);

        $this->assertNotNull(DB::table('campaigns')->where('name', 'PLATFORM PLACEMENT')->first(),
            'POSITIVE CONTROL: the platform operator can no longer create a campaign.');
    }
}
